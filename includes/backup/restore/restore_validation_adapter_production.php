<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_validation_adapter.php';
require_once __DIR__ . '/restore_staging_target.php';
require_once __DIR__ . '/restore_merge_precheck.php';
require_once __DIR__ . '/restore_merge_maintenance.php';
require_once __DIR__ . '/restore_lock.php';
require_once __DIR__ . '/restore_uploads_fs.php';
require_once __DIR__ . '/../backup_validate.php';
require_once __DIR__ . '/../backup_table_registry_lib.php';
require_once __DIR__ . '/../uploads_collector.php';

const ORANGE_RESTORE_PRODUCTION_GATE_HARD = 'hard';
const ORANGE_RESTORE_PRODUCTION_GATE_WARNING = 'warning';
const ORANGE_RESTORE_PRODUCTION_GATE_INFO = 'informational';

const ORANGE_RESTORE_PRODUCTION_GL_TOLERANCE = 0.01;

/** @var list<string> */
const ORANGE_RESTORE_PRODUCTION_CRITICAL_TABLES = [
    'admins',
    'countries',
    'products',
    'warehouses',
    'warehouse_variant_stock',
    'stock_movements',
    'inventory_cost_layers',
];

/**
 * @return array<string, mixed>
 */
function orange_restore_validation_adapter_production_gate(
    string $gateId,
    string $severity,
    bool $passed,
    string $message,
    array $details = []
): array {
    return [
        'gate_id' => $gateId,
        'severity' => $severity,
        'passed' => $passed,
        'message' => $message,
        'details' => $details,
    ];
}

/**
 * @param list<array<string, mixed>> $gates
 * @return array{hard_failures:list<string>,warnings:list<string>,informational:list<string>,passed:bool}
 */
function orange_restore_validation_adapter_summarize_gates(array $gates): array
{
    $hardFailures = [];
    $warnings = [];
    $informational = [];

    foreach ($gates as $gate) {
        $gateId = (string) ($gate['gate_id'] ?? 'unknown');
        $severity = (string) ($gate['severity'] ?? ORANGE_RESTORE_PRODUCTION_GATE_HARD);
        $passed = (bool) ($gate['passed'] ?? false);
        $message = (string) ($gate['message'] ?? '');

        if ($passed) {
            if ($severity === ORANGE_RESTORE_PRODUCTION_GATE_INFO) {
                $informational[] = $gateId . ': ' . $message;
            }
            continue;
        }

        if ($severity === ORANGE_RESTORE_PRODUCTION_GATE_HARD) {
            $hardFailures[] = $gateId . ': ' . $message;
        } elseif ($severity === ORANGE_RESTORE_PRODUCTION_GATE_WARNING) {
            $warnings[] = $gateId . ': ' . $message;
        } else {
            $informational[] = $gateId . ': ' . $message;
        }
    }

    return [
        'hard_failures' => $hardFailures,
        'warnings' => $warnings,
        'informational' => $informational,
        'passed' => $hardFailures === [],
    ];
}

/**
 * Hard gate: maintenance active, owned by job, restore lock still held.
 *
 * @return array<string, mixed>
 */
function orange_restore_validation_adapter_production_maintenance_hard_gate(
    string $workRoot,
    string $jobId,
    string $validationGroup
): array {
    try {
        orange_restore_lock_assert_held_by_job($workRoot, $jobId);
        orange_restore_merge_maintenance_verify($workRoot, $jobId);

        return orange_restore_validation_adapter_production_gate(
            'maintenance_active_during_validation',
            ORANGE_RESTORE_PRODUCTION_GATE_HARD,
            true,
            'Maintenance active and owned by job during validation group: ' . $validationGroup . '.',
            ['validation_group' => $validationGroup]
        );
    } catch (Throwable $e) {
        return orange_restore_validation_adapter_production_gate(
            'maintenance_active_during_validation',
            ORANGE_RESTORE_PRODUCTION_GATE_HARD,
            false,
            $e->getMessage(),
            ['validation_group' => $validationGroup]
        );
    }
}

/**
 * @param list<array<string, mixed>> $gates
 * @return bool true when validation may continue
 */
function orange_restore_validation_adapter_production_append_gate(array &$gates, array $gate): bool
{
    $gates[] = $gate;
    $severity = (string) ($gate['severity'] ?? ORANGE_RESTORE_PRODUCTION_GATE_HARD);
    $passed = (bool) ($gate['passed'] ?? false);

    return !($severity === ORANGE_RESTORE_PRODUCTION_GATE_HARD && !$passed);
}

/**
 * @return array{ok:bool,registry:array<string,mixed>,error:string}
 */
function orange_restore_validation_adapter_production_registry_load_safe(string $projectRoot): array
{
    try {
        return [
            'ok' => true,
            'registry' => orange_backup_registry_load($projectRoot),
            'error' => '',
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'registry' => [],
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * @return list<int>
 */
function orange_restore_validation_adapter_production_load_country_ids(PDO $pdo): array
{
    $ids = [];
    $st = $pdo->query('SELECT id FROM countries ORDER BY id');
    if ($st === false) {
        throw new RuntimeException('Cannot read countries.id for cross-country validation.');
    }
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (is_array($row)) {
            $ids[] = (int) ($row['id'] ?? 0);
        }
    }

    return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
}

/**
 * @param list<int> $countryIds
 * @return list<string>
 */
function orange_restore_validation_adapter_production_count_invalid_country_refs(
    PDO $pdo,
    string $tableName,
    string $column,
    array $countryIds,
    bool $integrityCritical
): array {
    if ($countryIds === []) {
        return ['Cross-country validation cannot run: countries table is empty.'];
    }

    $table = '`' . str_replace('`', '``', $tableName) . '`';
    $col = '`' . str_replace('`', '``', $column) . '`';
    $placeholders = implode(',', array_fill(0, count($countryIds), '?'));
    $errors = [];

    try {
        $invalidSql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $col . ' IS NOT NULL AND ' . $col . ' NOT IN (' . $placeholders . ')';
        $st = $pdo->prepare($invalidSql);
        $st->execute($countryIds);
        $invalidCount = (int) ($st->fetchColumn() ?: 0);
        if ($invalidCount > 0) {
            $errors[] = 'Cross-country foreign country rows in ' . $tableName . '.' . $column . ' (' . (string) $invalidCount . ' rows).';
        }

        if ($integrityCritical) {
            $nullSql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $col . ' IS NULL';
            $nullCount = (int) ($pdo->query($nullSql)->fetchColumn() ?: 0);
            if ($nullCount > 0) {
                $errors[] = 'Cross-country NULL ' . $column . ' in integrity-critical table ' . $tableName . ' (' . (string) $nullCount . ' rows).';
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Cross-country validation failed for ' . $tableName . '.' . $column . ': ' . $e->getMessage();
    }

    return $errors;
}

/**
 * @param list<string> $columns
 * @param list<int> $countryIds
 * @return list<string>
 */
function orange_restore_validation_adapter_production_count_country_scope_or_violations(
    PDO $pdo,
    string $tableName,
    array $columns,
    array $countryIds
): array {
    if ($columns === []) {
        return ['Cross-country country_scope_or rule missing columns for ' . $tableName . '.'];
    }
    if ($countryIds === []) {
        return ['Cross-country validation cannot run: countries table is empty.'];
    }

    $table = '`' . str_replace('`', '``', $tableName) . '`';
    $matchParts = [];
    foreach ($columns as $column) {
        $col = '`' . str_replace('`', '``', (string) $column) . '`';
        $matchParts[] = $col . ' IN (' . implode(',', array_map('intval', $countryIds)) . ')';
    }
    $invalidParts = [];
    foreach ($columns as $column) {
        $col = '`' . str_replace('`', '``', (string) $column) . '`';
        $invalidParts[] = '(' . $col . ' IS NOT NULL AND ' . $col . ' NOT IN (' . implode(',', array_map('intval', $countryIds)) . '))';
    }

    $sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ('
        . implode(' OR ', $invalidParts)
        . ') OR ((' . implode(' OR ', array_map(static fn (string $c): string => '`' . str_replace('`', '``', $c) . '` IS NOT NULL', $columns)) . ') AND NOT (' . implode(' OR ', $matchParts) . '))';

    try {
        $count = (int) ($pdo->query($sql)->fetchColumn() ?: 0);
        if ($count > 0) {
            return ['Cross-country scope mismatch in ' . $tableName . ' (' . (string) $count . ' rows).'];
        }
    } catch (Throwable $e) {
        return ['Cross-country country_scope_or validation failed for ' . $tableName . ': ' . $e->getMessage()];
    }

    return [];
}

/**
 * Registry-aligned cross-FK ownership checks (integrity-critical relationships).
 *
 * @param list<int> $countryIds
 * @return list<string>
 */
function orange_restore_validation_adapter_production_cross_country_fk_checks(
    PDO $pdo,
    array $productionTables,
    array $countryIds
): array {
    if ($countryIds === []) {
        return ['Cross-country FK validation cannot run: countries table is empty.'];
    }

    $errors = [];
    $checks = [
        [
            'label' => 'journal_lines.account vs journal_vouchers.country_id',
            'tables' => ['journal_lines', 'journal_vouchers', 'accounts'],
            'sql' => 'SELECT COUNT(*) FROM journal_lines jl INNER JOIN journal_vouchers jv ON jv.id = jl.journal_voucher_id INNER JOIN accounts a ON a.id = jl.account_id WHERE jv.country_id <> a.country_id',
        ],
        [
            'label' => 'order_items.product vs orders.country_id',
            'tables' => ['order_items', 'orders', 'products'],
            'sql' => 'SELECT COUNT(*) FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id INNER JOIN products p ON p.id = oi.product_id WHERE o.country_id <> p.country_id',
        ],
        [
            'label' => 'warehouse_variant_stock.product vs warehouses.country_id',
            'tables' => ['warehouse_variant_stock', 'warehouses', 'product_variants', 'products'],
            'sql' => 'SELECT COUNT(*) FROM warehouse_variant_stock wvs INNER JOIN warehouses w ON w.id = wvs.warehouse_id INNER JOIN product_variants pv ON pv.id = wvs.variant_id INNER JOIN products p ON p.id = pv.product_id WHERE w.country_id <> p.country_id',
        ],
        [
            'label' => 'stock_movements.warehouse vs stock_movements.country_id',
            'tables' => ['stock_movements', 'warehouses'],
            'sql' => 'SELECT COUNT(*) FROM stock_movements sm INNER JOIN warehouses w ON w.id = sm.warehouse_id WHERE sm.country_id IS NOT NULL AND sm.country_id <> w.country_id',
        ],
        [
            'label' => 'party_subledger.voucher vs journal_vouchers.country_id',
            'tables' => ['party_subledger', 'journal_vouchers'],
            'sql' => 'SELECT COUNT(*) FROM party_subledger ps INNER JOIN journal_vouchers jv ON jv.id = ps.voucher_id WHERE ps.voucher_id IS NOT NULL AND jv.id IS NOT NULL AND ps.country_id IS NOT NULL AND ps.country_id <> jv.country_id',
            'requires_column' => ['party_subledger' => 'country_id'],
        ],
        [
            'label' => 'purchase_returns.purchase vs supplier country ownership',
            'tables' => ['purchase_returns', 'purchases', 'suppliers'],
            'sql' => 'SELECT COUNT(*) FROM purchase_returns pr INNER JOIN purchases p ON p.id = pr.purchase_id INNER JOIN suppliers s ON s.id = pr.supplier_id WHERE p.country_id <> s.country_id',
        ],
    ];

    foreach ($checks as $check) {
        $checkFailed = false;
        foreach ($check['tables'] as $tableName) {
            if (!in_array($tableName, $productionTables, true)) {
                $errors[] = 'Cross-country FK validation cannot run: missing table '
                    . $tableName
                    . ' for '
                    . (string) $check['label']
                    . '.';
                $checkFailed = true;
                break;
            }
        }
        if ($checkFailed) {
            continue;
        }
        $requiresColumn = is_array($check['requires_column'] ?? null) ? $check['requires_column'] : [];
        foreach ($requiresColumn as $tableName => $columnName) {
            if (!orange_restore_validation_adapter_table_has_column($pdo, (string) $tableName, (string) $columnName)) {
                $errors[] = 'Cross-country FK validation cannot run: missing column '
                    . (string) $tableName
                    . '.'
                    . (string) $columnName
                    . ' for '
                    . (string) $check['label']
                    . '.';
                $checkFailed = true;
                break;
            }
        }
        if ($checkFailed) {
            continue;
        }
        try {
            $count = (int) ($pdo->query((string) $check['sql'])->fetchColumn() ?: 0);
            if ($count > 0) {
                $errors[] = 'Cross-country contamination in ' . (string) $check['label'] . ' (' . (string) $count . ' rows).';
            }
        } catch (Throwable $e) {
            $errors[] = 'Cross-country FK check failed for ' . (string) $check['label'] . ': ' . $e->getMessage();
        }
    }

    return $errors;
}

/**
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function orange_restore_validation_adapter_read_uploads_next_manifest(string $workRoot, array $job): array
{
    $jobId = (string) ($job['job_id'] ?? '');
    $manifestPath = trim((string) ($job['uploads_next_manifest_path'] ?? ''));
    if ($manifestPath === '' || !is_file($manifestPath)) {
        $manifestPath = orange_restore_uploads_next_manifest_path($workRoot, $jobId);
    }
    if (!is_file($manifestPath)) {
        throw new RuntimeException('uploads_next manifest missing for production validation.');
    }
    $decoded = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('uploads_next manifest is invalid JSON.');
    }

    return $decoded;
}

/**
 * Run production post-validation gates after DB + uploads cutover.
 *
 * @param array<string, mixed> $job
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_restore_validation_adapter_production_postcheck(
    string $projectRoot,
    string $workRoot,
    array $job,
    array $env,
    ?PDO $productionPdo = null,
    ?PDO $stagingPdo = null
): array {
    $gates = [];
    $jobId = (string) ($job['job_id'] ?? '');
    $stagingDb = orange_restore_staging_db_name($env, $projectRoot);
    $productionDb = orange_restore_production_db_name($projectRoot);

    $productionPdo = $productionPdo ?? orange_restore_connect_merge_pdo($projectRoot, $env);
    $stagingPdo = $stagingPdo ?? orange_restore_connect_staging_pdo($projectRoot, $env);

    orange_restore_log('Production post-validation...');

    if (!orange_restore_validation_adapter_production_append_gate(
        $gates,
        orange_restore_validation_adapter_production_maintenance_hard_gate($workRoot, $jobId, 'identity_connectivity')
    )) {
        return orange_restore_validation_adapter_production_postcheck_finalize($gates, $job, $productionDb, $stagingDb);
    }

    try {
        orange_restore_production_assert_identity($productionPdo, $productionDb);
        $gates[] = orange_restore_validation_adapter_production_gate(
            'production_db_connectivity',
            ORANGE_RESTORE_PRODUCTION_GATE_HARD,
            true,
            'Production database connectivity OK.',
            ['database' => $productionDb]
        );
    } catch (Throwable $e) {
        $gates[] = orange_restore_validation_adapter_production_gate(
            'production_db_connectivity',
            ORANGE_RESTORE_PRODUCTION_GATE_HARD,
            false,
            $e->getMessage()
        );

        return orange_restore_validation_adapter_production_postcheck_finalize($gates, $job, $productionDb, $stagingDb);
    }

    $sessionDb = (string) ($productionPdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
    $gates[] = orange_restore_validation_adapter_production_gate(
        'production_db_identity',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        $sessionDb !== '' && strcasecmp($sessionDb, $productionDb) === 0,
        $sessionDb !== '' && strcasecmp($sessionDb, $productionDb) === 0
            ? 'SELECT DATABASE() matches production DB.'
            : 'SELECT DATABASE() mismatch (expected ' . $productionDb . ', got ' . ($sessionDb === '' ? '(empty)' : $sessionDb) . ').',
        ['session_database' => $sessionDb, 'expected_database' => $productionDb]
    );

    $gates[] = orange_restore_validation_adapter_production_gate(
        'production_not_staging_db',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        strcasecmp($sessionDb, $stagingDb) !== 0,
        strcasecmp($sessionDb, $stagingDb) !== 0
            ? 'Production DB differs from staging DB.'
            : 'Production DB equals staging DB (forbidden).',
        ['production_db' => $productionDb, 'staging_db' => $stagingDb]
    );

    if (!orange_restore_validation_adapter_production_append_gate(
        $gates,
        orange_restore_validation_adapter_production_maintenance_hard_gate($workRoot, $jobId, 'schema_and_counts')
    )) {
        return orange_restore_validation_adapter_production_postcheck_finalize($gates, $job, $productionDb, $stagingDb);
    }

    $expectedSchemaRevision = (int) ($job['schema_revision'] ?? 0);
    $liveSchemaRevision = function_exists('orange_backup_schema_revision_live')
        ? orange_backup_schema_revision_live($productionPdo)
        : 0;
    $gates[] = orange_restore_validation_adapter_production_gate(
        'schema_revision_exact_match',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        $expectedSchemaRevision > 0 && $liveSchemaRevision === $expectedSchemaRevision,
        $expectedSchemaRevision > 0 && $liveSchemaRevision === $expectedSchemaRevision
            ? 'Schema revision matches job (' . (string) $expectedSchemaRevision . ').'
            : 'Schema revision mismatch (expected ' . (string) $expectedSchemaRevision . ', live ' . (string) $liveSchemaRevision . ').',
        ['expected' => $expectedSchemaRevision, 'live' => $liveSchemaRevision]
    );

    $stagingManifestPath = trim((string) ($job['staging_restore_manifest_path'] ?? ''));
    if ($stagingManifestPath === '' || !is_file($stagingManifestPath)) {
        $stagingManifestPath = orange_restore_job_staging_manifest_path($workRoot, $jobId);
    }
    $stagingManifest = is_file($stagingManifestPath)
        ? (json_decode((string) file_get_contents($stagingManifestPath), true) ?: [])
        : [];
    $expectedTableCount = (int) ($stagingManifest['staging_post_validation']['table_count'] ?? ($stagingManifest['table_count'] ?? 0));
    if ($expectedTableCount <= 0) {
        $expectedTableCount = (int) ($job['merge_db_export_table_count'] ?? 0);
    }

    $productionTables = [];
    $st = $productionPdo->query('SHOW TABLES');
    if ($st !== false) {
        while ($row = $st->fetch(PDO::FETCH_NUM)) {
            if (is_array($row) && isset($row[0])) {
                $productionTables[] = (string) $row[0];
            }
        }
    }
    $liveTableCount = count($productionTables);
    $gates[] = orange_restore_validation_adapter_production_gate(
        'table_count_exact_match',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        $expectedTableCount > 0 && $liveTableCount === $expectedTableCount,
        $expectedTableCount > 0 && $liveTableCount === $expectedTableCount
            ? 'Table count matches staging manifest (' . (string) $liveTableCount . ').'
            : 'Table count mismatch (expected ' . (string) $expectedTableCount . ', live ' . (string) $liveTableCount . ').',
        ['expected' => $expectedTableCount, 'live' => $liveTableCount]
    );

    $criticalRowChecks = [];
    $criticalMismatch = false;
    foreach (ORANGE_RESTORE_PRODUCTION_CRITICAL_TABLES as $tableName) {
        if (!in_array($tableName, $productionTables, true)) {
            $criticalMismatch = true;
            $criticalRowChecks[] = [
                'table' => $tableName,
                'error' => 'Critical table missing from production schema.',
                'ok' => false,
            ];
            continue;
        }
        $quoted = '`' . str_replace('`', '``', $tableName) . '`';
        try {
            orange_restore_staging_assert_safe_target($stagingPdo, $stagingDb);
            $expectedCount = (int) ($stagingPdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn() ?: 0);
            $liveCount = (int) ($productionPdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn() ?: 0);
            $ok = $liveCount === $expectedCount;
            $criticalRowChecks[] = [
                'table' => $tableName,
                'expected_rows' => $expectedCount,
                'live_rows' => $liveCount,
                'ok' => $ok,
            ];
            if (!$ok) {
                $criticalMismatch = true;
            }
        } catch (Throwable $e) {
            $criticalMismatch = true;
            $criticalRowChecks[] = [
                'table' => $tableName,
                'error' => 'Critical table unreadable or staging compare failed: ' . $e->getMessage(),
                'ok' => false,
            ];
        }
    }
    $gates[] = orange_restore_validation_adapter_production_gate(
        'critical_row_counts',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        !$criticalMismatch && count($criticalRowChecks) === count(ORANGE_RESTORE_PRODUCTION_CRITICAL_TABLES),
        !$criticalMismatch
            ? 'Critical row counts match validated staging.'
            : 'Critical row count validation failed (missing/unreadable/mismatch).',
        ['checks' => $criticalRowChecks]
    );

    $adminReadable = false;
    $superAdminCount = 0;
    try {
        $adminSt = $productionPdo->query(
            'SELECT COUNT(*) FROM admins WHERE is_active = 1 AND is_superuser = 1'
        );
        $superAdminCount = (int) ($adminSt ? $adminSt->fetchColumn() : 0);
        $adminReadable = true;
    } catch (Throwable $e) {
        $gates[] = orange_restore_validation_adapter_production_gate(
            'admin_table_readable',
            ORANGE_RESTORE_PRODUCTION_GATE_HARD,
            false,
            'Cannot read admins table: ' . $e->getMessage()
        );
    }
    if ($adminReadable) {
        $gates[] = orange_restore_validation_adapter_production_gate(
            'admin_table_readable',
            ORANGE_RESTORE_PRODUCTION_GATE_HARD,
            true,
            'admins table readable.',
            ['super_admin_count' => $superAdminCount]
        );
        $gates[] = orange_restore_validation_adapter_production_gate(
            'super_admin_present',
            ORANGE_RESTORE_PRODUCTION_GATE_HARD,
            $superAdminCount >= 1,
            $superAdminCount >= 1
                ? 'At least one active Super Admin remains.'
                : 'No active Super Admin account found.',
            ['super_admin_count' => $superAdminCount]
        );
    }

    if (!orange_restore_validation_adapter_production_append_gate(
        $gates,
        orange_restore_validation_adapter_production_maintenance_hard_gate($workRoot, $jobId, 'accounting_inventory_integrity')
    )) {
        return orange_restore_validation_adapter_production_postcheck_finalize($gates, $job, $productionDb, $stagingDb);
    }

    $glResult = ['debit' => 0.0, 'credit' => 0.0, 'difference' => 0.0];
    $glGateRecorded = false;
    try {
        $glSt = $productionPdo->query(
            'SELECT COALESCE(SUM(debit), 0) AS debit_total, COALESCE(SUM(credit), 0) AS credit_total FROM journal_lines'
        );
        $glRow = $glSt ? $glSt->fetch(PDO::FETCH_ASSOC) : false;
        if (is_array($glRow)) {
            $glResult['debit'] = (float) ($glRow['debit_total'] ?? 0);
            $glResult['credit'] = (float) ($glRow['credit_total'] ?? 0);
            $glResult['difference'] = round($glResult['debit'] - $glResult['credit'], 4);
        }
    } catch (Throwable $e) {
        $gates[] = orange_restore_validation_adapter_production_gate(
            'gl_debit_credit_balance',
            ORANGE_RESTORE_PRODUCTION_GATE_HARD,
            false,
            'GL balance check failed: ' . $e->getMessage()
        );
        $glGateRecorded = true;
    }
    if (!$glGateRecorded) {
        $glBalanced = abs($glResult['difference']) <= ORANGE_RESTORE_PRODUCTION_GL_TOLERANCE;
        $gates[] = orange_restore_validation_adapter_production_gate(
            'gl_debit_credit_balance',
            ORANGE_RESTORE_PRODUCTION_GATE_HARD,
            $glBalanced,
            $glBalanced
                ? 'GL debit/credit balanced within tolerance.'
                : 'GL imbalance exceeds tolerance (difference=' . (string) $glResult['difference'] . ').',
            $glResult
        );
    }

    $orphanErrors = orange_restore_validation_adapter_production_orphan_checks($productionPdo, $productionTables);
    $gates[] = orange_restore_validation_adapter_production_gate(
        'orphan_validation',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        $orphanErrors === [],
        $orphanErrors === [] ? 'No orphan FK violations detected.' : 'Orphan FK violations detected.',
        ['errors' => $orphanErrors]
    );

    $crossCountryErrors = orange_restore_validation_adapter_production_cross_country_checks(
        $productionPdo,
        $projectRoot,
        $productionTables
    );
    $gates[] = orange_restore_validation_adapter_production_gate(
        'cross_country_integrity',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        $crossCountryErrors === [],
        $crossCountryErrors === [] ? 'Cross-country integrity OK.' : 'Cross-country integrity violations detected.',
        ['errors' => $crossCountryErrors]
    );

    $stockErrors = orange_restore_validation_adapter_production_stock_checks($productionPdo, $productionTables);
    $gates[] = orange_restore_validation_adapter_production_gate(
        'stock_movement_integrity',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        $stockErrors['movement_errors'] === [],
        $stockErrors['movement_errors'] === [] ? 'Stock movement integrity OK.' : 'Stock movement integrity failed.',
        ['errors' => $stockErrors['movement_errors']]
    );
    $gates[] = orange_restore_validation_adapter_production_gate(
        'warehouse_variant_stock_consistency',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        $stockErrors['wvs_errors'] === [],
        $stockErrors['wvs_errors'] === [] ? 'warehouse_variant_stock consistency OK.' : 'warehouse_variant_stock consistency failed.',
        ['errors' => $stockErrors['wvs_errors']]
    );
    $gates[] = orange_restore_validation_adapter_production_gate(
        'fifo_layer_integrity',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        $stockErrors['fifo_errors'] === [],
        $stockErrors['fifo_errors'] === [] ? 'FIFO layer integrity OK.' : 'FIFO layer integrity failed.',
        ['errors' => $stockErrors['fifo_errors']]
    );
    $gates[] = orange_restore_validation_adapter_production_gate(
        'negative_quantity_policy',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        $stockErrors['negative_errors'] === [],
        $stockErrors['negative_errors'] === [] ? 'No forbidden negative quantities.' : 'Forbidden negative quantities detected.',
        ['errors' => $stockErrors['negative_errors']]
    );

    if (!orange_restore_validation_adapter_production_append_gate(
        $gates,
        orange_restore_validation_adapter_production_maintenance_hard_gate($workRoot, $jobId, 'uploads_validation')
    )) {
        return orange_restore_validation_adapter_production_postcheck_finalize($gates, $job, $productionDb, $stagingDb);
    }

    $uploadsDir = orange_restore_production_uploads_directory($projectRoot);
    $uploadsExists = is_dir($uploadsDir);
    $gates[] = orange_restore_validation_adapter_production_gate(
        'uploads_tree_exists',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        $uploadsExists,
        $uploadsExists ? 'Production uploads tree exists.' : 'Production uploads tree missing.',
        ['uploads_path' => $uploadsDir]
    );

    $requiredUploadsCheck = orange_restore_validation_adapter_production_required_uploads_check(
        $productionPdo,
        $projectRoot,
        $productionTables
    );
    $gates[] = orange_restore_validation_adapter_production_gate(
        'required_referenced_uploads',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        $requiredUploadsCheck['ok'],
        ($requiredUploadsCheck['ok'] ?? false)
            ? (($requiredUploadsCheck['no_uploads_required'] ?? false)
                ? 'No DB-referenced uploads required.'
                : 'Required referenced uploads available on disk.')
            : (($requiredUploadsCheck['verifiable'] ?? true)
                ? 'Missing required referenced uploads.'
                : 'Required uploads validation could not be performed.'),
        $requiredUploadsCheck
    );

    $uploadsChecksumMatch = false;
    $uploadsDetails = [];
    if ($uploadsExists) {
        try {
            $nextManifest = orange_restore_validation_adapter_read_uploads_next_manifest($workRoot, $job);
            $liveInventory = orange_restore_uploads_tree_inventory($uploadsDir);
            $uploadsChecksumMatch = hash_equals(
                (string) ($nextManifest['aggregate_tree_checksum'] ?? ''),
                $liveInventory['tree_checksum_sha256']
            ) && (int) ($nextManifest['file_count'] ?? -1) === $liveInventory['file_count'];
            $uploadsDetails = [
                'expected_tree_checksum' => (string) ($nextManifest['aggregate_tree_checksum'] ?? ''),
                'live_tree_checksum' => $liveInventory['tree_checksum_sha256'],
                'expected_file_count' => (int) ($nextManifest['file_count'] ?? 0),
                'live_file_count' => $liveInventory['file_count'],
            ];
        } catch (Throwable $e) {
            $uploadsDetails = ['error' => $e->getMessage()];
        }
    }
    $gates[] = orange_restore_validation_adapter_production_gate(
        'uploads_checksum_match',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        $uploadsChecksumMatch,
        $uploadsChecksumMatch
            ? 'Live uploads tree matches uploads_next manifest.'
            : 'Live uploads tree does not match uploads_next manifest.',
        $uploadsDetails
    );

    $stagingPathLeak = str_contains(strtolower($stagingDb), 'staging')
        || (is_dir($uploadsDir) && is_dir(orange_restore_staging_uploads_directory($workRoot, $jobId))
            && realpath($uploadsDir) === realpath(orange_restore_staging_uploads_directory($workRoot, $jobId)));
    $gates[] = orange_restore_validation_adapter_production_gate(
        'no_staging_identity_leak',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        !$stagingPathLeak && strcasecmp($sessionDb, $stagingDb) !== 0,
        !$stagingPathLeak
            ? 'No staging DB/uploads identity leaked into production state.'
            : 'Staging identity leak detected in production state.',
        ['production_db' => $productionDb, 'staging_db' => $stagingDb]
    );

    if (!orange_restore_validation_adapter_production_append_gate(
        $gates,
        orange_restore_validation_adapter_production_maintenance_hard_gate($workRoot, $jobId, 'package_bindings')
    )) {
        return orange_restore_validation_adapter_production_postcheck_finalize($gates, $job, $productionDb, $stagingDb);
    }

    try {
        /** @var array<string, mixed> $binding */
        $binding = is_array($job['approval_token_binding'] ?? null) ? $job['approval_token_binding'] : [];
        orange_restore_merge_precheck_assert_binding_checksums($job, $binding);
        $liveAnchor = orange_restore_merge_precheck_live_rollback_checksum((string) ($job['fresh_backup_path'] ?? ''));
        $bindingsOk = hash_equals((string) ($job['fresh_backup_checksum'] ?? ''), $liveAnchor);
        $gates[] = orange_restore_validation_adapter_production_gate(
            'package_staging_rollback_bindings',
            ORANGE_RESTORE_PRODUCTION_GATE_HARD,
            $bindingsOk,
            $bindingsOk
                ? 'Package/staging/rollback-anchor bindings unchanged.'
                : 'Rollback anchor checksum drift detected.',
            [
                'source_package_checksum' => (string) ($job['source_package_checksum'] ?? ''),
                'rollback_anchor_checksum' => (string) ($job['fresh_backup_checksum'] ?? ''),
            ]
        );
    } catch (Throwable $e) {
        $gates[] = orange_restore_validation_adapter_production_gate(
            'package_staging_rollback_bindings',
            ORANGE_RESTORE_PRODUCTION_GATE_HARD,
            false,
            $e->getMessage()
        );
    }

    return orange_restore_validation_adapter_production_postcheck_finalize($gates, $job, $productionDb, $stagingDb);
}

/**
 * @param list<array<string, mixed>> $gates
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function orange_restore_validation_adapter_production_postcheck_finalize(
    array $gates,
    array $job,
    string $productionDb,
    string $stagingDb
): array {
    $summary = orange_restore_validation_adapter_summarize_gates($gates);
    orange_restore_log('Production post-validation... ' . ($summary['passed'] ? 'OK' : 'FAIL'));

    return [
        'ok' => $summary['passed'],
        'gates' => $gates,
        'hard_failures' => $summary['hard_failures'],
        'warnings' => $summary['warnings'],
        'informational' => $summary['informational'],
        'overall_result' => $summary['passed'] ? 'pass' : 'fail',
        'production_db' => $productionDb,
        'staging_db' => $stagingDb,
        'schema_revision' => (int) ($job['schema_revision'] ?? 0),
        'validated_at' => gmdate('c'),
    ];
}

/**
 * Verify DB-referenced upload files exist under the production uploads tree.
 *
 * Uses registry uploads_linked tables plus country upload discovery tables.
 *
 * @param list<string> $productionTables
 * @return array{
 *   ok:bool,
 *   verifiable:bool,
 *   no_uploads_required:bool,
 *   checked:int,
 *   missing:list<string>,
 *   warnings:list<string>,
 *   scan_errors:list<string>
 * }
 */
function orange_restore_validation_adapter_production_required_uploads_check(
    PDO $pdo,
    string $projectRoot,
    array $productionTables
): array {
    $missing = [];
    $warnings = [];
    $scanErrors = [];
    $checked = 0;
    $referencedPaths = [];

    $registryLoad = orange_restore_validation_adapter_production_registry_load_safe($projectRoot);
    if (!$registryLoad['ok']) {
        return [
            'ok' => false,
            'verifiable' => false,
            'no_uploads_required' => false,
            'checked' => 0,
            'missing' => [],
            'warnings' => $warnings,
            'scan_errors' => ['Registry load failed: ' . $registryLoad['error']],
        ];
    }
    $registry = $registryLoad['registry'];
    $registryTables = is_array($registry['tables'] ?? null) ? $registry['tables'] : [];
    $uploadLinkedTables = [];
    foreach ($registryTables as $tableName => $meta) {
        if (!is_string($tableName) || !is_array($meta)) {
            continue;
        }
        if (($meta['uploads_linked'] ?? false) === true) {
            $uploadLinkedTables[] = $tableName;
        }
    }
    foreach (ORANGE_COUNTRY_EXPORT_UPLOAD_DISCOVERY_TABLES as $tableName) {
        if (!in_array($tableName, $uploadLinkedTables, true)) {
            $uploadLinkedTables[] = $tableName;
        }
    }

    if ($uploadLinkedTables === []) {
        return [
            'ok' => false,
            'verifiable' => false,
            'no_uploads_required' => false,
            'checked' => 0,
            'missing' => [],
            'warnings' => [],
            'scan_errors' => ['No uploads-linked registry tables configured.'],
        ];
    }

    foreach ($uploadLinkedTables as $tableName) {
        if (!in_array($tableName, $productionTables, true)) {
            $scanErrors[] = 'uploads-linked table missing from production: ' . $tableName;
            continue;
        }

        try {
            $rows = orange_restore_validation_adapter_production_collect_upload_references($pdo, $tableName);
            foreach ($rows as $relativePath) {
                $referencedPaths[$relativePath] = true;
            }
        } catch (Throwable $e) {
            $scanErrors[] = 'Cannot scan uploads-linked table ' . $tableName . ': ' . $e->getMessage();
        }
    }

    if ($scanErrors !== []) {
        return [
            'ok' => false,
            'verifiable' => false,
            'no_uploads_required' => false,
            'checked' => 0,
            'missing' => [],
            'warnings' => $warnings,
            'scan_errors' => $scanErrors,
        ];
    }

    foreach (array_keys($referencedPaths) as $relativePath) {
        $checked++;
        $validated = orange_restore_validation_adapter_production_validate_upload_reference($projectRoot, $relativePath);
        if (!$validated['ok']) {
            $scanErrors[] = 'Invalid uploads reference '
                . ($validated['relative_path'] !== '' ? $validated['relative_path'] : $relativePath)
                . ': '
                . $validated['error'];
            continue;
        }
        if (!$validated['exists']) {
            $missing[] = $validated['relative_path'];
        }
    }

    if ($scanErrors !== []) {
        return [
            'ok' => false,
            'verifiable' => false,
            'no_uploads_required' => false,
            'checked' => $checked,
            'missing' => $missing,
            'warnings' => $warnings,
            'scan_errors' => $scanErrors,
        ];
    }

    if ($checked === 0) {
        return [
            'ok' => true,
            'verifiable' => true,
            'no_uploads_required' => true,
            'checked' => 0,
            'missing' => [],
            'warnings' => $warnings,
            'scan_errors' => [],
        ];
    }

    return [
        'ok' => $missing === [],
        'verifiable' => true,
        'no_uploads_required' => false,
        'checked' => $checked,
        'missing' => $missing,
        'warnings' => $warnings,
        'scan_errors' => [],
    ];
}

/**
 * @return list<string> normalized uploads-relative paths
 */
function orange_restore_validation_adapter_production_collect_upload_references(PDO $pdo, string $tableName): array
{
    $paths = [];
    $add = static function (string $path) use (&$paths): void {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return;
        }
        if (!str_starts_with($path, 'uploads/')) {
            if (str_starts_with($path, '/uploads/')) {
                $path = ltrim($path, '/');
            } else {
                $path = 'uploads/products/' . ltrim($path, '/');
            }
        }
        $paths[$path] = true;
    };

    switch ($tableName) {
        case 'products':
            $st = $pdo->query("SELECT main_image FROM products WHERE main_image IS NOT NULL AND TRIM(main_image) <> ''");
            if ($st !== false) {
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    if (is_array($row)) {
                        $add((string) ($row['main_image'] ?? ''));
                    }
                }
            }
            break;
        case 'product_images':
            $st = $pdo->query("SELECT image_path FROM product_images WHERE image_path IS NOT NULL AND TRIM(image_path) <> ''");
            if ($st !== false) {
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    if (is_array($row)) {
                        $add((string) ($row['image_path'] ?? ''));
                    }
                }
            }
            break;
        case 'product_colorway_images':
            $st = $pdo->query("SELECT image_path FROM product_colorway_images WHERE image_path IS NOT NULL AND TRIM(image_path) <> ''");
            if ($st !== false) {
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    if (is_array($row)) {
                        $add((string) ($row['image_path'] ?? ''));
                    }
                }
            }
            break;
        case 'payment_transactions':
            $st = $pdo->query("SELECT proof_file FROM payment_transactions WHERE proof_file IS NOT NULL AND TRIM(proof_file) <> ''");
            if ($st !== false) {
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    if (is_array($row)) {
                        $proof = trim((string) ($row['proof_file'] ?? ''));
                        if ($proof !== '') {
                            $add('uploads/payment_proofs/' . ltrim($proof, '/'));
                        }
                    }
                }
            }
            break;
        case 'orange_company_documents':
            $st = $pdo->query("SELECT storage_path FROM orange_company_documents WHERE storage_path IS NOT NULL AND TRIM(storage_path) <> ''");
            if ($st !== false) {
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    if (is_array($row)) {
                        $add((string) ($row['storage_path'] ?? ''));
                    }
                }
            }
            break;
        case 'company_settings':
            $st = $pdo->query("SELECT company_logo FROM company_settings WHERE company_logo IS NOT NULL AND TRIM(company_logo) <> ''");
            if ($st !== false) {
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    if (is_array($row)) {
                        $logo = trim((string) ($row['company_logo'] ?? ''));
                        if ($logo !== '') {
                            $add('uploads/company/' . ltrim($logo, '/'));
                        }
                    }
                }
            }
            break;
        case 'customers':
        case 'suppliers':
        case 'inventory_reconciliation':
            // Discovery tables may not expose direct file paths in production validation.
            break;
        default:
            if (in_array($tableName, ORANGE_COUNTRY_EXPORT_UPLOAD_DISCOVERY_TABLES, true)) {
                break;
            }
            throw new RuntimeException('Unsupported uploads-linked table scan: ' . $tableName);
    }

    return array_keys($paths);
}

/**
 * Normalize a DB-stored uploads reference to uploads/... relative form.
 *
 * @throws RuntimeException when the reference violates hardened uploads policy.
 */
function orange_restore_validation_adapter_production_normalize_upload_reference(string $rawPath): string
{
    $path = trim(str_replace('\\', '/', $rawPath));
    if ($path === '') {
        throw new RuntimeException('Empty uploads reference.');
    }
    if (str_contains($path, '..')) {
        throw new RuntimeException('Uploads reference traversal blocked.');
    }
    if (preg_match('/^[A-Za-z]:[\\/]/', $path) === 1) {
        throw new RuntimeException('Absolute uploads reference blocked.');
    }
    if (str_starts_with($path, '//') || str_starts_with($path, '\\\\')) {
        throw new RuntimeException('Absolute uploads reference blocked.');
    }
    if (str_starts_with($path, '/') && !str_starts_with($path, '/uploads/')) {
        throw new RuntimeException('Absolute uploads reference blocked.');
    }
    if (str_starts_with($path, '/uploads/')) {
        $path = ltrim($path, '/');
    } elseif (!str_starts_with($path, 'uploads/')) {
        $path = 'uploads/products/' . ltrim($path, '/');
    }
    if (!orange_country_uploads_is_allowlisted($path)) {
        throw new RuntimeException('Uploads reference outside allowlisted prefixes.');
    }

    return $path;
}

/**
 * Hardened uploads reference validation (same filesystem policy as uploads cutover).
 *
 * @return array{
 *   ok:bool,
 *   relative_path:string,
 *   absolute_path:string,
 *   exists:bool,
 *   error:string
 * }
 */
function orange_restore_validation_adapter_production_validate_upload_reference(
    string $projectRoot,
    string $rawDbPath
): array {
    try {
        $relative = orange_restore_validation_adapter_production_normalize_upload_reference($rawDbPath);
        $uploadsDir = orange_restore_production_uploads_directory($projectRoot);
        if (!is_dir($uploadsDir) && !@mkdir($uploadsDir, 0775, true) && !is_dir($uploadsDir)) {
            throw new RuntimeException('Production uploads directory missing.');
        }

        $uploadsRootReal = orange_restore_uploads_fs_require_realpath($uploadsDir);
        orange_restore_uploads_fs_assert_not_reparse_point($uploadsRootReal);

        $suffix = substr($relative, strlen('uploads/'));
        if ($suffix === '' || str_contains($suffix, '..')) {
            throw new RuntimeException('Invalid uploads relative suffix.');
        }

        $parts = explode('/', $suffix);
        $current = $uploadsRootReal;
        $lastIndex = count($parts) - 1;
        foreach ($parts as $index => $part) {
            if ($part === '' || $part === '.') {
                throw new RuntimeException('Invalid uploads path segment.');
            }
            if ($part === '..') {
                throw new RuntimeException('Uploads reference traversal blocked.');
            }

            $current = $current . DIRECTORY_SEPARATOR . $part;
            if ($index < $lastIndex) {
                if (file_exists($current)) {
                    if (is_link($current)) {
                        throw new RuntimeException('Symlink blocked in uploads tree: ' . $current);
                    }
                    orange_restore_uploads_fs_assert_not_reparse_point($current);
                    orange_restore_uploads_fs_assert_path_inside_root($current, $uploadsRootReal);
                    $current = orange_restore_uploads_fs_require_realpath($current);
                }
            }
        }

        orange_restore_uploads_fs_assert_lexical_path_inside_root($current, $uploadsRootReal);

        if (file_exists($current)) {
            if (is_link($current)) {
                throw new RuntimeException('Symlink blocked for uploads reference: ' . $relative);
            }
            orange_restore_uploads_fs_assert_not_reparse_point($current);
            orange_restore_uploads_fs_assert_path_inside_root($current, $uploadsRootReal);
            $absolute = orange_restore_uploads_fs_require_realpath($current);
            $exists = is_file($absolute);
        } else {
            $absolute = $current;
            $exists = false;
        }

        return [
            'ok' => true,
            'relative_path' => $relative,
            'absolute_path' => $absolute,
            'exists' => $exists,
            'error' => '',
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'relative_path' => trim(str_replace('\\', '/', $rawDbPath)),
            'absolute_path' => '',
            'exists' => false,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * @param list<string> $productionTables
 * @return list<string>
 */
function orange_restore_validation_adapter_production_orphan_checks(PDO $pdo, array $productionTables): array
{
    $errors = [];
    $checks = [
        ['child' => 'warehouse_variant_stock', 'fk' => 'warehouse_id', 'parent' => 'warehouses'],
        ['child' => 'stock_movements', 'fk' => 'warehouse_id', 'parent' => 'warehouses'],
        ['child' => 'journal_lines', 'fk' => 'journal_voucher_id', 'parent' => 'journal_vouchers'],
    ];
    foreach ($checks as $check) {
        if (!in_array($check['child'], $productionTables, true) || !in_array($check['parent'], $productionTables, true)) {
            continue;
        }
        $child = '`' . str_replace('`', '``', $check['child']) . '`';
        $parent = '`' . str_replace('`', '``', $check['parent']) . '`';
        $fk = str_replace('`', '``', $check['fk']);
        $sql = 'SELECT COUNT(*) FROM ' . $child . ' c LEFT JOIN ' . $parent . ' p ON p.id = c.`' . $fk
            . '` WHERE c.`' . $fk . '` IS NOT NULL AND p.id IS NULL';
        try {
            $count = (int) ($pdo->query($sql)->fetchColumn() ?: 0);
            if ($count > 0) {
                $errors[] = 'Orphan FK in ' . $check['child'] . '.' . $check['fk'] . ' (' . (string) $count . ' rows).';
            }
        } catch (Throwable $e) {
            $errors[] = 'Orphan check failed for ' . $check['child'] . ': ' . $e->getMessage();
        }
    }

    return $errors;
}

/**
 * Validate registry country_owned extraction rules before cross-country SQL execution.
 *
 * Approved rule types: country_id, country_scope_or, custom_sql.
 *
 * @param array<string, mixed> $meta
 * @return list<string>
 */
function orange_restore_validation_adapter_production_validate_country_owned_rule(
    PDO $pdo,
    string $tableName,
    array $meta
): array {
    $rule = $meta['extraction_rule'] ?? null;
    if (!is_array($rule)) {
        return ['Cross-country country_owned rule missing extraction_rule for ' . $tableName . '.'];
    }

    $ruleType = trim((string) ($rule['type'] ?? ''));
    if ($ruleType === '') {
        return ['Cross-country country_owned rule missing extraction_rule.type for ' . $tableName . '.'];
    }

    if ($ruleType === 'country_id') {
        $column = trim((string) ($rule['column'] ?? 'country_id'));
        if ($column === '') {
            return ['Cross-country country_owned country_id rule missing column for ' . $tableName . '.'];
        }
        if (!orange_restore_validation_adapter_table_has_column($pdo, $tableName, $column)) {
            return ['Cross-country country_owned table ' . $tableName . ' missing ownership column ' . $column . '.'];
        }

        return [];
    }

    if ($ruleType === 'country_scope_or') {
        $columns = $rule['columns'] ?? null;
        if (!is_array($columns) || $columns === []) {
            return ['Cross-country country_owned country_scope_or rule missing columns for ' . $tableName . '.'];
        }
        foreach ($columns as $column) {
            $columnName = trim((string) $column);
            if ($columnName === '') {
                return ['Cross-country country_owned country_scope_or rule has empty column for ' . $tableName . '.'];
            }
            if (!orange_restore_validation_adapter_table_has_column($pdo, $tableName, $columnName)) {
                return [
                    'Cross-country country_owned table '
                    . $tableName
                    . ' missing country_scope_or column '
                    . $columnName
                    . '.',
                ];
            }
        }

        return [];
    }

    if ($ruleType === 'custom_sql') {
        $sql = trim((string) ($rule['sql'] ?? ''));
        if ($sql === '') {
            return ['Cross-country country_owned custom_sql rule missing sql for ' . $tableName . '.'];
        }
        if (!preg_match('/^\s*SELECT\s+/i', $sql)) {
            return ['Cross-country country_owned custom_sql rule must be SELECT for ' . $tableName . '.'];
        }
        if (!str_contains($sql, ':country_id')) {
            return ['Cross-country country_owned custom_sql rule must bind :country_id for ' . $tableName . '.'];
        }
        if (!orange_restore_validation_adapter_table_has_column($pdo, $tableName, 'id')) {
            return ['Cross-country country_owned custom_sql table ' . $tableName . ' missing id column for coverage validation.'];
        }

        return [];
    }

    return [
        'Cross-country country_owned rule unsupported extraction_rule.type '
        . $ruleType
        . ' for '
        . $tableName
        . '.',
    ];
}

/**
 * Verify custom_sql country ownership covers each table row exactly once.
 *
 * @param list<int> $countryIds
 * @return list<string>
 */
function orange_restore_validation_adapter_production_count_custom_sql_coverage_violations(
    PDO $pdo,
    string $tableName,
    array $rule,
    array $countryIds
): array {
    if ($countryIds === []) {
        return ['Cross-country validation cannot run: countries table is empty.'];
    }

    $idSql = trim((string) ($rule['sql'] ?? ''));
    $paramCount = substr_count($idSql, ':country_id');
    $positionalSql = str_replace(':country_id', '?', $idSql);
    $seen = [];
    $duplicateMatches = 0;
    $invalidIds = 0;

    try {
        $st = $pdo->prepare($positionalSql);
        foreach ($countryIds as $countryId) {
            $st->execute(array_fill(0, $paramCount, $countryId));
            while ($row = $st->fetch(PDO::FETCH_NUM)) {
                $id = (int) ($row[0] ?? 0);
                if ($id <= 0) {
                    $invalidIds++;
                    continue;
                }
                if (isset($seen[$id])) {
                    $duplicateMatches++;
                }
                $seen[$id] = true;
            }
        }

        $table = '`' . str_replace('`', '``', $tableName) . '`';
        $tableIds = [];
        $rows = $pdo->query('SELECT id FROM ' . $table);
        if ($rows === false) {
            throw new RuntimeException('Cannot read ids from ' . $tableName . '.');
        }
        while ($row = $rows->fetch(PDO::FETCH_NUM)) {
            $id = (int) ($row[0] ?? 0);
            if ($id > 0) {
                $tableIds[$id] = true;
            }
        }
    } catch (Throwable $e) {
        return ['Cross-country custom_sql validation failed for ' . $tableName . ': ' . $e->getMessage()];
    }

    $uncovered = 0;
    foreach ($tableIds as $id => $_) {
        if (!isset($seen[$id])) {
            $uncovered++;
        }
    }

    $phantom = 0;
    foreach ($seen as $id => $_) {
        if (!isset($tableIds[$id])) {
            $phantom++;
        }
    }

    $errors = [];
    if ($uncovered > 0) {
        $errors[] = 'Cross-country custom_sql ownership leaves unowned rows in ' . $tableName . ' (' . (string) $uncovered . ' rows).';
    }
    if ($duplicateMatches > 0) {
        $errors[] = 'Cross-country custom_sql ownership matches rows in multiple countries for ' . $tableName . ' (' . (string) $duplicateMatches . ' duplicate matches).';
    }
    if ($phantom > 0 || $invalidIds > 0) {
        $errors[] = 'Cross-country custom_sql ownership returned invalid ids for ' . $tableName . ' (phantom=' . (string) $phantom . ', invalid=' . (string) $invalidIds . ').';
    }

    return $errors;
}

/**
 * Registry-driven cross-country integrity validation for full production DB.
 *
 * @param list<string> $productionTables
 * @return list<string>
 */
function orange_restore_validation_adapter_production_cross_country_checks(
    PDO $pdo,
    string $projectRoot,
    array $productionTables
): array {
    if (!in_array('countries', $productionTables, true)) {
        return ['Cross-country validation unverifiable: countries table missing from production.'];
    }

    try {
        $countryIds = orange_restore_validation_adapter_production_load_country_ids($pdo);
    } catch (Throwable $e) {
        return ['Cross-country validation failed: ' . $e->getMessage()];
    }
    if ($countryIds === []) {
        return ['Cross-country validation failed: countries table is empty.'];
    }

    $errors = [];
    $registryLoad = orange_restore_validation_adapter_production_registry_load_safe($projectRoot);
    if (!$registryLoad['ok']) {
        return ['Registry load failed for cross-country validation: ' . $registryLoad['error']];
    }
    $registry = $registryLoad['registry'];
    $registryTables = is_array($registry['tables'] ?? null) ? $registry['tables'] : [];

    foreach ($registryTables as $tableName => $meta) {
        if (!is_string($tableName)) {
            continue;
        }
        if (!is_array($meta)) {
            if (in_array($tableName, $productionTables, true)) {
                $errors[] = 'Cross-country registry metadata invalid for table ' . $tableName . '.';
            }
            continue;
        }
        $ownership = (string) ($meta['ownership_type'] ?? '');
        if (!in_array($tableName, $productionTables, true)) {
            if ($ownership === 'country_owned') {
                $errors[] = 'Cross-country country_owned registry table ' . $tableName . ' missing from production schema.';
            }
            continue;
        }
        $rule = is_array($meta['extraction_rule'] ?? null) ? $meta['extraction_rule'] : [];
        $ruleType = (string) ($rule['type'] ?? '');
        $integrityCritical = (bool) ($meta['integrity_critical'] ?? false);

        if ($ownership === 'country_owned') {
            $ownedRuleErrors = orange_restore_validation_adapter_production_validate_country_owned_rule(
                $pdo,
                $tableName,
                $meta
            );
            if ($ownedRuleErrors !== []) {
                $errors = array_merge($errors, $ownedRuleErrors);
                continue;
            }

            if ($ruleType === 'country_id') {
                $errors = array_merge(
                    $errors,
                    orange_restore_validation_adapter_production_count_invalid_country_refs(
                        $pdo,
                        $tableName,
                        (string) ($rule['column'] ?? 'country_id'),
                        $countryIds,
                        $integrityCritical
                    )
                );
            } elseif ($ruleType === 'country_scope_or') {
                $columns = is_array($rule['columns'] ?? null) ? $rule['columns'] : [];
                $errors = array_merge(
                    $errors,
                    orange_restore_validation_adapter_production_count_country_scope_or_violations(
                        $pdo,
                        $tableName,
                        $columns,
                        $countryIds
                    )
                );
            } elseif ($ruleType === 'custom_sql') {
                $errors = array_merge(
                    $errors,
                    orange_restore_validation_adapter_production_count_custom_sql_coverage_violations(
                        $pdo,
                        $tableName,
                        $rule,
                        $countryIds
                    )
                );
            }
            continue;
        }

        if ($ruleType === 'country_scope_or') {
            $columns = is_array($rule['columns'] ?? null) ? $rule['columns'] : [];
            $errors = array_merge(
                $errors,
                orange_restore_validation_adapter_production_count_country_scope_or_violations(
                    $pdo,
                    $tableName,
                    $columns,
                    $countryIds
                )
            );
            continue;
        }

        if ($ownership === 'dependent') {
            $parent = is_array($meta['parent_dependency'] ?? null) ? $meta['parent_dependency'] : null;
            if ($parent === null) {
                $errors[] = 'Cross-country dependent validation cannot run: parent_dependency missing for ' . $tableName . '.';
                continue;
            }
            $parentTable = (string) ($parent['table'] ?? '');
            $foreignKey = (string) ($parent['foreign_key'] ?? '');
            if ($parentTable === '' || $foreignKey === '') {
                $errors[] = 'Cross-country dependent validation cannot run: invalid parent_dependency for ' . $tableName . '.';
                continue;
            }
            if (!in_array($parentTable, $productionTables, true)) {
                $errors[] = 'Cross-country dependent validation cannot run: parent table missing '
                    . $parentTable
                    . ' for '
                    . $tableName
                    . '.';
                continue;
            }
            if (!orange_restore_validation_adapter_table_has_column($pdo, $parentTable, 'country_id')) {
                $errors[] = 'Cross-country dependent validation cannot run: parent table '
                    . $parentTable
                    . ' missing country_id for '
                    . $tableName
                    . '.';
                continue;
            }
            if (!orange_restore_validation_adapter_table_has_column($pdo, $tableName, 'country_id')) {
                $errors[] = 'Cross-country dependent validation cannot run: '
                    . $tableName
                    . ' missing country_id for dependent ownership validation.';
                continue;
            }

            $child = '`' . str_replace('`', '``', $tableName) . '`';
            $parentSql = '`' . str_replace('`', '``', $parentTable) . '`';
            $fk = '`' . str_replace('`', '``', $foreignKey) . '`';
            $sql = 'SELECT COUNT(*) FROM ' . $child . ' c INNER JOIN ' . $parentSql . ' p ON p.id = c.' . $fk
                . ' WHERE c.' . $fk . ' IS NOT NULL AND c.country_id IS NOT NULL AND c.country_id <> p.country_id';
            try {
                $count = (int) ($pdo->query($sql)->fetchColumn() ?: 0);
                if ($count > 0) {
                    $errors[] = 'Cross-country ownership mismatch in ' . $tableName . ' vs ' . $parentTable . ' (' . (string) $count . ' rows).';
                }
            } catch (Throwable $e) {
                $errors[] = 'Cross-country dependent validation failed for ' . $tableName . ': ' . $e->getMessage();
            }
        }
    }

    return array_values(array_unique(array_merge(
        $errors,
        orange_restore_validation_adapter_production_cross_country_fk_checks($pdo, $productionTables, $countryIds)
    )));
}

function orange_restore_validation_adapter_table_has_column(PDO $pdo, string $table, string $column): bool
{
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $quotedTable = str_replace("'", "''", $table);
        $st = $pdo->query("PRAGMA table_info('" . $quotedTable . "')");
        if ($st === false) {
            return false;
        }
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row) && (string) ($row['name'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }

    $quotedTable = $pdo->quote($table);
    $quotedColumn = $pdo->quote($column);

    return (int) ($pdo->query(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '
        . $quotedTable . ' AND column_name = ' . $quotedColumn
    )->fetchColumn() ?: 0) > 0;
}

/**
 * @param list<string> $productionTables
 * @return array{movement_errors:list<string>,wvs_errors:list<string>,fifo_errors:list<string>,negative_errors:list<string>}
 */
function orange_restore_validation_adapter_production_stock_checks(PDO $pdo, array $productionTables): array
{
    $movementErrors = [];
    $wvsErrors = [];
    $fifoErrors = [];
    $negativeErrors = [];

    if (in_array('warehouse_variant_stock', $productionTables, true)) {
        try {
            $neg = (int) ($pdo->query(
                'SELECT COUNT(*) FROM warehouse_variant_stock WHERE quantity < 0'
            )->fetchColumn() ?: 0);
            if ($neg > 0) {
                $negativeErrors[] = 'warehouse_variant_stock has ' . (string) $neg . ' negative quantity rows.';
            }
        } catch (Throwable $e) {
            $wvsErrors[] = $e->getMessage();
        }
    }

    if (in_array('inventory_cost_layers', $productionTables, true)) {
        try {
            $negLayers = (int) ($pdo->query(
                'SELECT COUNT(*) FROM inventory_cost_layers WHERE qty_remaining < 0'
            )->fetchColumn() ?: 0);
            if ($negLayers > 0) {
                $fifoErrors[] = 'inventory_cost_layers has ' . (string) $negLayers . ' negative qty_remaining rows.';
                $negativeErrors[] = 'inventory_cost_layers negative qty_remaining forbidden.';
            }
        } catch (Throwable $e) {
            $fifoErrors[] = $e->getMessage();
        }
    }

    if (in_array('stock_movements', $productionTables, true)) {
        try {
            $nullWh = (int) ($pdo->query(
                'SELECT COUNT(*) FROM stock_movements WHERE warehouse_id IS NULL'
            )->fetchColumn() ?: 0);
            if ($nullWh > 0) {
                $movementErrors[] = 'stock_movements has ' . (string) $nullWh . ' rows with NULL warehouse_id.';
            }
        } catch (Throwable $e) {
            $movementErrors[] = $e->getMessage();
        }
    }

    return [
        'movement_errors' => $movementErrors,
        'wvs_errors' => $wvsErrors,
        'fifo_errors' => $fifoErrors,
        'negative_errors' => $negativeErrors,
    ];
}

function orange_restore_validation_adapter_table_exists(PDO $pdo, string $table): bool
{
    $quoted = $pdo->quote($table);

    return (int) ($pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ' . $quoted
    )->fetchColumn() ?: 0) > 0;
}

/**
 * Minimal rollback post-validation (production restored from anchor).
 *
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function orange_restore_validation_adapter_rollback_postcheck(
    string $projectRoot,
    array $job,
    array $env,
    ?PDO $productionPdo = null
): array {
    $productionDb = orange_restore_production_db_name($projectRoot);
    $pdo = $productionPdo ?? orange_restore_connect_merge_pdo($projectRoot, $env);
    orange_restore_production_assert_identity($pdo, $productionDb);

    $gates = [];
    $gates[] = orange_restore_validation_adapter_production_gate(
        'rollback_production_db_connectivity',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        true,
        'Production DB reachable after rollback.',
        ['database' => $productionDb]
    );

    $superAdminCount = 0;
    try {
        $superAdminCount = (int) ($pdo->query(
            'SELECT COUNT(*) FROM admins WHERE is_active = 1 AND is_superuser = 1'
        )->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $gates[] = orange_restore_validation_adapter_production_gate(
            'rollback_super_admin_present',
            ORANGE_RESTORE_PRODUCTION_GATE_HARD,
            false,
            $e->getMessage()
        );
    }
    if ($superAdminCount > 0 || ($gates[count($gates) - 1]['gate_id'] ?? '') !== 'rollback_super_admin_present') {
        $gates[] = orange_restore_validation_adapter_production_gate(
            'rollback_super_admin_present',
            ORANGE_RESTORE_PRODUCTION_GATE_HARD,
            $superAdminCount >= 1,
            $superAdminCount >= 1 ? 'Super Admin present after rollback.' : 'No Super Admin after rollback.',
            ['super_admin_count' => $superAdminCount]
        );
    }

    $uploadsDir = orange_restore_production_uploads_directory($projectRoot);
    $gates[] = orange_restore_validation_adapter_production_gate(
        'rollback_uploads_tree_exists',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        is_dir($uploadsDir),
        is_dir($uploadsDir) ? 'Uploads tree exists after rollback.' : 'Uploads tree missing after rollback.',
        ['uploads_path' => $uploadsDir]
    );

    $summary = orange_restore_validation_adapter_summarize_gates($gates);

    return [
        'ok' => $summary['passed'],
        'gates' => $gates,
        'hard_failures' => $summary['hard_failures'],
        'warnings' => $summary['warnings'],
        'overall_result' => $summary['passed'] ? 'pass' : 'fail',
        'validated_at' => gmdate('c'),
    ];
}
