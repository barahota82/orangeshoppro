<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_table_registry_lib.php';
require_once __DIR__ . '/backup_manifest.php';

const ORANGE_COUNTRY_EXPORT_TRIAL_BALANCE_TOLERANCE = 0.01;

/** @var list<string> */
const ORANGE_COUNTRY_EXPORT_FORBIDDEN_SQL_TABLES = [
    'countries',
    'report_line_master',
    'orange_schema_meta',
    'orange_schema_migrations',
    'orange_schema_migration_failures',
    'orange_catalog_schema_checkpoint',
    'admin_sessions',
    'logs',
    // C3 frozen boundary — never in CRP SQL
    'journal_entries',
    'orange_country_screen_copy_log',
];

/**
 * @return array{id:int,code:string,label:string}
 */
function orange_country_export_load_country(PDO $pdo, int $countryId): array
{
    if ($countryId <= 0) {
        throw new InvalidArgumentException('country-id must be positive.');
    }
    $st = $pdo->prepare('SELECT id, code, name_ar, name_en FROM countries WHERE id = ? LIMIT 1');
    $st->execute([$countryId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Unknown country_id: ' . $countryId);
    }

    $label = trim((string) ($row['name_en'] ?? ''));
    if ($label === '') {
        $label = trim((string) ($row['name_ar'] ?? ''));
    }

    return [
        'id' => (int) $row['id'],
        'code' => strtolower(trim((string) ($row['code'] ?? ''))),
        'label' => $label !== '' ? $label : ('country_' . $countryId),
    ];
}

/**
 * @param array<string, mixed> $registry
 * @return list<string>
 */
function orange_country_export_validate_registry_runtime(PDO $pdo, array $registry, int $expectedRevision): array
{
    $errors = orange_backup_registry_validate_structure($registry, $expectedRevision);
    if ($errors !== []) {
        return $errors;
    }
    $dbName = defined('DB_NAME') ? (string) DB_NAME : '';
    if ($dbName === '') {
        return ['DB_NAME is not defined'];
    }
    $liveTables = orange_backup_registry_live_tables($pdo, $dbName);
    /** @var array<string, array<string, mixed>> $registryTables */
    $registryTables = $registry['tables'];

    return orange_backup_registry_validate_coverage($registryTables, $liveTables);
}

/**
 * @param array<string, list<int>> $idSnapshot exported parent tables (empty list = zero rows, still valid)
 * @return list<string>
 */
function orange_country_export_validate_parent_dependency(
    string $tableName,
    array $meta,
    array $idSnapshot
): array {
    $parent = $meta['parent_dependency'] ?? null;
    if (!is_array($parent)) {
        return [];
    }
    $parentTable = (string) ($parent['table'] ?? '');
    if ($parentTable === '') {
        return ['Invalid parent_dependency on ' . $tableName];
    }
    // Parent must be materialized in idSnapshot before child export (export_order).
    // An empty ID list means zero parent rows — valid; child exports zero rows.
    if (!array_key_exists($parentTable, $idSnapshot)) {
        return ['Parent table not materialized before child export: ' . $tableName . ' -> ' . $parentTable];
    }

    return [];
}

/**
 * @param array<string, mixed> $row
 * @return list<string>
 */
function orange_country_export_validate_row_country_scope(
    string $tableName,
    array $meta,
    array $row,
    int $countryId
): array {
    $rule = $meta['extraction_rule'] ?? [];
    if (!is_array($rule)) {
        return [];
    }
    $type = (string) ($rule['type'] ?? '');
    if ($type === 'country_id') {
        $column = (string) ($rule['column'] ?? 'country_id');
        if (!array_key_exists($column, $row)) {
            return [];
        }
        $value = $row[$column];
        if ($value === null) {
            return ['Cross-country NULL ' . $column . ' in ' . $tableName . ' id=' . ($row['id'] ?? '?')];
        }
        if ((int) $value !== $countryId) {
            return ['Cross-country reference in ' . $tableName . ' id=' . ($row['id'] ?? '?') . ' (' . $column . '=' . (string) $value . ')'];
        }
    }
    if ($type === 'country_scope_or') {
        return ['country_scope_or forbidden under frozen boundary policy for ' . $tableName];
    }
    if ($type === 'special_sequence_namespace') {
        $scope = (string) ($row['scope'] ?? '');
        $suffix = '_c' . $countryId;
        if ($scope === '' || !str_ends_with($scope, $suffix)) {
            return ['sequence_namespace_violation: scope=' . $scope . ' for country_id=' . $countryId];
        }
    }
    // D2 NULL leakage: any country_id column present must be exact target (never NULL).
    if (array_key_exists('country_id', $row)) {
        if ($row['country_id'] === null) {
            return ['null_country_id_leakage in ' . $tableName . ' id=' . ($row['id'] ?? '?')];
        }
        if ((int) $row['country_id'] !== $countryId) {
            return ['wrong_country in ' . $tableName . ' id=' . ($row['id'] ?? '?')];
        }
    }

    return [];
}

/**
 * @param array<string, list<int>> $idSnapshot
 * @return list<string>
 */
function orange_country_export_validate_orphan_fk(
    string $tableName,
    array $meta,
    array $row,
    array $idSnapshot
): array {
    $parent = $meta['parent_dependency'] ?? null;
    if (!is_array($parent)) {
        return [];
    }
    $parentTable = (string) ($parent['table'] ?? '');
    $foreignKey = (string) ($parent['foreign_key'] ?? '');
    if ($parentTable === '' || $foreignKey === '' || !array_key_exists($foreignKey, $row)) {
        return [];
    }
    $fkValue = $row[$foreignKey];
    if ($fkValue === null) {
        if ((bool) ($parent['nullable'] ?? false)) {
            return [];
        }

        return ['Orphan NULL FK ' . $tableName . '.' . $foreignKey];
    }
    $parentIds = $idSnapshot[$parentTable] ?? [];
    if (!in_array((int) $fkValue, $parentIds, true)) {
        return ['Orphan FK ' . $tableName . '.' . $foreignKey . '=' . (string) $fkValue . ' -> ' . $parentTable];
    }

    return [];
}

/**
 * @param array<string, list<int>> $idSnapshot
 * @return array{debit:float,credit:float,difference:float}
 */
function orange_country_export_compute_trial_balance(PDO $pdo, int $countryId, array $idSnapshot): array
{
    $voucherIds = $idSnapshot['journal_vouchers'] ?? [];
    if ($voucherIds === []) {
        return ['debit' => 0.0, 'credit' => 0.0, 'difference' => 0.0];
    }
    $placeholders = implode(',', array_fill(0, count($voucherIds), '?'));
    // Schema truth: journal_lines.voucher_id (not journal_voucher_id — C1.1 drift).
    $sql = 'SELECT COALESCE(SUM(jl.debit), 0) AS debit_total, COALESCE(SUM(jl.credit), 0) AS credit_total
            FROM journal_lines jl
            INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
            WHERE jl.voucher_id IN (' . $placeholders . ') AND jv.country_id = ?';
    $params = array_merge($voucherIds, [$countryId]);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $debit = (float) ($row['debit_total'] ?? 0);
    $credit = (float) ($row['credit_total'] ?? 0);

    return [
        'debit' => $debit,
        'credit' => $credit,
        'difference' => round($debit - $credit, 4),
    ];
}

/**
 * @param array<string, list<int>> $idSnapshot
 * @return array{quantity_total:float,valuation_total:float}
 */
function orange_country_export_compute_inventory_summary(PDO $pdo, int $countryId, array $idSnapshot): array
{
    $warehouseIds = $idSnapshot['warehouses'] ?? [];
    if ($warehouseIds === []) {
        return ['quantity_total' => 0.0, 'valuation_total' => 0.0];
    }
    $placeholders = implode(',', array_fill(0, count($warehouseIds), '?'));
    $qtySql = 'SELECT COALESCE(SUM(wvs.quantity), 0) FROM warehouse_variant_stock wvs WHERE wvs.warehouse_id IN (' . $placeholders . ')';
    $stQty = $pdo->prepare($qtySql);
    $stQty->execute($warehouseIds);
    $quantityTotal = (float) ($stQty->fetchColumn() ?: 0);

    $valuationTotal = 0.0;
    if (isset($idSnapshot['inventory_cost_layers']) && $idSnapshot['inventory_cost_layers'] !== []) {
        $layerIds = $idSnapshot['inventory_cost_layers'];
        $layerPh = implode(',', array_fill(0, count($layerIds), '?'));
        $valSql = 'SELECT COALESCE(SUM(qty_remaining * unit_cost), 0) FROM inventory_cost_layers WHERE id IN (' . $layerPh . ') AND country_id = ?';
        $stVal = $pdo->prepare($valSql);
        $stVal->execute(array_merge($layerIds, [$countryId]));
        $valuationTotal = (float) ($stVal->fetchColumn() ?: 0);
    }

    return [
        'quantity_total' => $quantityTotal,
        'valuation_total' => round($valuationTotal, 4),
    ];
}

/**
 * @param list<string> $uploadIssues
 * @return array{package_status:string,failure_reasons:list<string>,warnings:list<string>,maintenance_notes:list<string>}
 */
function orange_country_export_classify_upload_issues(array $uploadIssues): array
{
    $failureReasons = [];
    $warnings = [];
    $maintenanceNotes = [];
    foreach ($uploadIssues as $issue) {
        if (str_starts_with($issue, 'critical:')) {
            $failureReasons[] = substr($issue, strlen('critical:'));
        } elseif (str_starts_with($issue, 'warning:')) {
            $warnings[] = substr($issue, strlen('warning:'));
        } elseif (str_starts_with($issue, 'informational:')) {
            $maintenanceNotes[] = substr($issue, strlen('informational:'));
        }
    }

    return [
        'package_status' => $failureReasons !== [] ? 'failed' : 'healthy',
        'failure_reasons' => $failureReasons,
        'warnings' => $warnings,
        'maintenance_notes' => $maintenanceNotes,
    ];
}

/**
 * @param array<string, mixed> $healthInput
 * @return array<string, mixed>
 */
function orange_country_export_build_health(array $healthInput): array
{
    $uploadClass = orange_country_export_classify_upload_issues(is_array($healthInput['upload_issues'] ?? null) ? $healthInput['upload_issues'] : []);
    $validationErrors = is_array($healthInput['validation_errors'] ?? null) ? $healthInput['validation_errors'] : [];
    $validationWarnings = is_array($healthInput['validation_warnings'] ?? null) ? $healthInput['validation_warnings'] : [];

    $failureReasons = array_values(array_unique(array_merge(
        $validationErrors,
        $uploadClass['failure_reasons']
    )));
    $warnings = array_values(array_unique(array_merge(
        $validationWarnings,
        $uploadClass['warnings']
    )));
    $maintenanceNotes = array_values(array_unique(array_merge(
        is_array($healthInput['maintenance_notes'] ?? null) ? $healthInput['maintenance_notes'] : [],
        $uploadClass['maintenance_notes']
    )));

    $packageStatus = $failureReasons !== [] ? 'failed' : 'healthy';

    return [
        'package_type' => 'country_recovery',
        'package_status' => $packageStatus,
        'generated_at' => gmdate('c'),
        'country_id' => (int) ($healthInput['country_id'] ?? 0),
        'country_code' => (string) ($healthInput['country_code'] ?? ''),
        'country_label' => (string) ($healthInput['country_label'] ?? ''),
        'schema_revision' => (int) ($healthInput['schema_revision'] ?? 0),
        'registry_version' => (string) ($healthInput['registry_version'] ?? ''),
        'counts' => is_array($healthInput['counts'] ?? null) ? $healthInput['counts'] : [],
        'row_counts' => is_array($healthInput['row_counts'] ?? null) ? $healthInput['row_counts'] : [],
        'trial_balance' => is_array($healthInput['trial_balance'] ?? null) ? $healthInput['trial_balance'] : [],
        'inventory_summary' => is_array($healthInput['inventory_summary'] ?? null) ? $healthInput['inventory_summary'] : [],
        'orphan_validation' => [
            'errors' => array_values(array_filter($validationErrors, static fn (string $e): bool => str_contains($e, 'Orphan'))),
        ],
        'cross_country_validation' => [
            'errors' => array_values(array_filter($validationErrors, static fn (string $e): bool => str_contains($e, 'Cross-country'))),
        ],
        'upload_validation' => [
            'issues' => is_array($healthInput['upload_issues'] ?? null) ? $healthInput['upload_issues'] : [],
            'files_collected' => (int) ($healthInput['upload_files_collected'] ?? 0),
            'files_missing' => (int) ($healthInput['upload_files_missing'] ?? 0),
        ],
        'failure_reasons' => $failureReasons,
        'warnings' => $warnings,
        'maintenance_notes' => $maintenanceNotes,
    ];
}

/**
 * Compatibility wrapper — delegates to Phase C4 CRP verify engine.
 *
 * @return array{
 *   ok:bool,
 *   errors:list<string>,
 *   warnings:list<string>,
 *   manifest:?array<string,mixed>,
 *   overall?:string,
 *   report_path?:?string,
 *   report?:array<string,mixed>
 * }
 */
function orange_country_export_verify_package(string $packageRoot): array
{
    require_once __DIR__ . '/country_crp_verify.php';

    $result = orange_crp_verify_run($packageRoot, [
        'write_report' => true,
        'project_root' => orange_backup_project_root(),
    ]);

    $errors = $result['codes'];
    if ($result['overall'] === 'FAIL' && $errors === []) {
        $errors = ['verify_failed'];
    }

    return [
        'ok' => (bool) $result['ok'] && $result['overall'] !== 'FAIL',
        'errors' => $errors,
        'warnings' => $result['warnings'],
        'manifest' => $result['manifest'],
        'overall' => $result['overall'],
        'report_path' => $result['report_path'],
        'report' => $result['report'],
    ];
}
