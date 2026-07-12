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
        $columns = is_array($rule['columns'] ?? null) ? $rule['columns'] : [];
        $matched = false;
        foreach ($columns as $column) {
            $column = (string) $column;
            if (array_key_exists($column, $row) && (int) $row[$column] === $countryId) {
                $matched = true;
                break;
            }
        }
        if (!$matched && $columns !== []) {
            return ['Cross-country scope mismatch in ' . $tableName . ' id=' . ($row['id'] ?? '?')];
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
    $sql = 'SELECT COALESCE(SUM(jl.debit), 0) AS debit_total, COALESCE(SUM(jl.credit), 0) AS credit_total
            FROM journal_lines jl
            INNER JOIN journal_vouchers jv ON jv.id = jl.journal_voucher_id
            WHERE jl.journal_voucher_id IN (' . $placeholders . ') AND jv.country_id = ?';
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
 * @return array{package_status:string,failure_reasons:list<string>,warnings:list<string>}
 */
function orange_country_export_classify_upload_issues(array $uploadIssues): array
{
    $failureReasons = [];
    $warnings = [];
    foreach ($uploadIssues as $issue) {
        if (str_starts_with($issue, 'critical:')) {
            $failureReasons[] = substr($issue, strlen('critical:'));
        } elseif (str_starts_with($issue, 'warning:')) {
            $warnings[] = substr($issue, strlen('warning:'));
        }
    }

    $status = 'healthy';
    if ($failureReasons !== []) {
        $status = 'failed';
    } elseif ($warnings !== []) {
        $status = 'warning';
    }

    return [
        'package_status' => $status,
        'failure_reasons' => $failureReasons,
        'warnings' => $warnings,
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

    $packageStatus = 'healthy';
    if ($failureReasons !== []) {
        $packageStatus = 'failed';
    } elseif ($warnings !== []) {
        $packageStatus = 'warning';
    }

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
        'maintenance_notes' => is_array($healthInput['maintenance_notes'] ?? null) ? $healthInput['maintenance_notes'] : [],
    ];
}

/**
 * @return array{ok:bool,errors:list<string>,warnings:list<string>,manifest:?array<string,mixed>}
 */
function orange_country_export_verify_package(string $packageRoot): array
{
    $errors = [];
    $warnings = [];
    $manifestPath = $packageRoot . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($manifestPath)) {
        return ['ok' => false, 'errors' => ['Missing manifest.json'], 'warnings' => [], 'manifest' => null];
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        return ['ok' => false, 'errors' => ['Invalid manifest.json'], 'warnings' => [], 'manifest' => null];
    }
    if (($manifest['package_type'] ?? '') !== 'country_recovery') {
        $errors[] = 'package_type must be country_recovery';
    }
    foreach ([
        'manifest.json',
        'health.json',
        'checksums.sha256',
        'dependency_graph.json',
        'table_inventory.json',
        'id_snapshot.json',
        'files/uploads_country.zip',
    ] as $required) {
        $abs = $packageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $required);
        if (!is_file($abs)) {
            $errors[] = 'Missing required file: ' . $required;
        }
    }
    $sqlDir = $packageRoot . DIRECTORY_SEPARATOR . 'sql';
    if (!is_dir($sqlDir)) {
        $errors[] = 'Missing sql/ directory';
    } else {
        $sqlFiles = glob($sqlDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        if ($sqlFiles === []) {
            $errors[] = 'sql/ directory has no .sql chunks';
        }
        foreach ($sqlFiles as $sqlFile) {
            $content = (string) file_get_contents($sqlFile);
            foreach (ORANGE_COUNTRY_EXPORT_FORBIDDEN_SQL_TABLES as $forbidden) {
                if (preg_match('/INSERT INTO `' . preg_quote($forbidden, '/') . '`/i', $content)) {
                    $errors[] = 'Forbidden global table data in SQL: ' . $forbidden;
                }
            }
        }
    }
    $checksum = orange_backup_verify_checksums($packageRoot);
    if (!$checksum['ok']) {
        $errors = array_merge($errors, $checksum['errors']);
    }
    $healthPath = $packageRoot . DIRECTORY_SEPARATOR . 'health.json';
    if (is_file($healthPath)) {
        $health = json_decode((string) file_get_contents($healthPath), true);
        if (is_array($health)) {
            if (($health['package_status'] ?? '') === 'failed') {
                $errors[] = 'health.json package_status=failed';
            }
            if ((int) ($health['country_id'] ?? 0) !== (int) ($manifest['country_id'] ?? -1)) {
                $errors[] = 'health country_id mismatch with manifest';
            }
        }
    }
    $inventoryPath = $packageRoot . DIRECTORY_SEPARATOR . 'table_inventory.json';
    if (is_file($inventoryPath)) {
        $inventory = json_decode((string) file_get_contents($inventoryPath), true);
        if (is_array($inventory) && !empty($inventory['other_country_markers'])) {
            $errors[] = 'table_inventory contains other-country markers';
        }
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'warnings' => $warnings,
        'manifest' => $manifest,
    ];
}
