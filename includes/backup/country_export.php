<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_paths.php';
require_once __DIR__ . '/backup_manifest.php';
require_once __DIR__ . '/backup_pdo_export.php';
require_once __DIR__ . '/backup_table_registry_lib.php';
require_once __DIR__ . '/backup_validate.php';
require_once __DIR__ . '/uploads_collector.php';
require_once __DIR__ . '/country_boundary_matrix_lib.php';

const ORANGE_COUNTRY_EXPORT_PACKAGE_VERSION = '2.0';
const ORANGE_COUNTRY_EXPORT_PACKAGE_TYPE = 'country_recovery';
const ORANGE_COUNTRY_EXPORT_BACKEND = 'php_country_export_c3';
const ORANGE_COUNTRY_EXPORT_CHUNK_ROWS = 500;
const ORANGE_COUNTRY_EXPORT_DRV_VERSION = '1.1';
const ORANGE_COUNTRY_EXPORT_VERIFY_VERSION = '2.0';

/** @var list<string> */
const ORANGE_COUNTRY_EXPORT_UPLOAD_DISCOVERY_TABLES = [
    'customers',
    'suppliers',
    'inventory_reconciliation',
    'company_settings',
];

/**
 * @param array{country_id:int,project_root?:string,backup_root?:string,output_path?:string} $options
 * @return array{ok:bool,package_path:?string,message:string,manifest:?array<string,mixed>}
 */
function orange_country_export_run(PDO $pdo, array $options): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Country export is CLI-only.');
    }

    $countryId = (int) ($options['country_id'] ?? 0);
    $projectRoot = (string) ($options['project_root'] ?? orange_backup_project_root());
    $envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env.php';
    $env = is_file($envPath) ? (require $envPath) : [];
    if (!is_array($env)) {
        $env = [];
    }
    $backupRoot = (string) ($options['backup_root'] ?? orange_backup_resolve_root($env));
    $outputOverride = trim((string) ($options['output_path'] ?? ''));

    $registry = orange_backup_registry_load($projectRoot);
    $registryErrors = orange_country_export_validate_registry_runtime($pdo, $registry, ORANGE_CATALOG_SCHEMA_PHP_REVISION);
    if ($registryErrors !== []) {
        throw new RuntimeException('Registry validation failed: ' . implode('; ', $registryErrors));
    }

    $country = orange_country_export_load_country($pdo, $countryId);
    $countryCode = $country['code'] !== '' ? $country['code'] : ('c' . $countryId);
    // Owner 2026-07-23: package id time segment is UTC only (canonical identity).
    // Do not use PHP default TZ, Country Context, or countries.timezone here.
    $timestamp = gmdate('Y-m-d_His');
    $finalDir = $outputOverride !== ''
        ? $outputOverride
        : $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . $countryCode . DIRECTORY_SEPARATOR . $timestamp;
    $tempDir = dirname($finalDir) . DIRECTORY_SEPARATOR . '.tmp_' . basename($finalDir) . '_' . bin2hex(random_bytes(4));

    if (is_dir($finalDir)) {
        throw new RuntimeException('Destination package already exists: ' . $finalDir);
    }
    if (!@mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
        throw new RuntimeException('Cannot create temp package directory.');
    }

    $committed = false;
    orange_country_export_set_pdo_ref($pdo);
    orange_backup_pdo_begin_snapshot($pdo);

    try {
        $sqlDir = $tempDir . DIRECTORY_SEPARATOR . 'sql';
        $filesDir = $tempDir . DIRECTORY_SEPARATOR . 'files';
        if (!@mkdir($sqlDir, 0775, true) || !@mkdir($filesDir, 0775, true)) {
            throw new RuntimeException('Cannot create package subdirectories.');
        }

        orange_country_export_write_session_file($sqlDir . DIRECTORY_SEPARATOR . '000_session_preamble.sql', true);

        /** @var array<string, list<int>> $idSnapshot */
        $idSnapshot = [];
        /** @var array<string, int> $rowCounts */
        $rowCounts = [];
        /** @var array<string, list<array<string,mixed>>> $exportedRows */
        $exportedRows = [];
        $validationErrors = [];
        $validationWarnings = [];
        $otherCountryMarkers = [];

        $boundaryMatrix = orange_country_boundary_matrix_load($projectRoot);
        $exportPlan = orange_country_boundary_matrix_export_plan($boundaryMatrix, $registry);
        $dbName = defined('DB_NAME') ? (string) DB_NAME : '';
        /** @var array<int, array{batch:int,tables:list<string>,row_counts:array<string,int>}> $batchMeta */
        $batchMeta = [];

        foreach ($exportPlan as $entry) {
            $tableName = $entry['table'];
            $meta = $entry['meta'];
            $matrixRow = $entry['matrix'];
            $batchNo = (int) ($matrixRow['restore_batch'] ?? 0);
            if (in_array($tableName, ORANGE_CRP_NEVER_EXPORT_TABLES, true)) {
                throw new RuntimeException('global_mutate_forbidden: attempted export of ' . $tableName);
            }

            $depErrors = orange_country_export_validate_parent_dependency($tableName, $meta, $idSnapshot);
            if ($depErrors !== []) {
                $validationErrors = array_merge($validationErrors, $depErrors);
                if ((bool) ($meta['integrity_critical'] ?? false)) {
                    throw new RuntimeException('Missing required parent for ' . $tableName . ': ' . implode('; ', $depErrors));
                }
            }

            $result = orange_country_export_table(
                $pdo,
                $dbName,
                $tableName,
                $meta,
                $countryId,
                $idSnapshot,
                $sqlDir
            );
            $rowCounts[$tableName] = $result['row_count'];
            $idSnapshot[$tableName] = $result['ids'];
            if ($result['rows'] !== []) {
                $exportedRows[$tableName] = $result['rows'];
            }
            $validationErrors = array_merge($validationErrors, $result['errors']);
            $validationWarnings = array_merge($validationWarnings, $result['warnings']);
            if ($result['errors'] !== []) {
                throw new RuntimeException(
                    'Export policy violation for ' . $tableName . ': ' . implode('; ', $result['errors'])
                );
            }
            if (!isset($batchMeta[$batchNo])) {
                $batchMeta[$batchNo] = ['batch' => $batchNo, 'tables' => [], 'row_counts' => []];
            }
            $batchMeta[$batchNo]['tables'][] = $tableName;
            $batchMeta[$batchNo]['row_counts'][$tableName] = $result['row_count'];
        }

        $compositeErrors = orange_crp_export_validate_composites($idSnapshot, $rowCounts);
        if ($compositeErrors !== []) {
            throw new RuntimeException('Composite inconsistency: ' . implode('; ', $compositeErrors));
        }

        orange_country_export_write_session_file($sqlDir . DIRECTORY_SEPARATOR . '999_session_postamble.sql', false);
        $countrySqlGz = $tempDir . DIRECTORY_SEPARATOR . 'country.sql.gz';
        orange_crp_export_write_country_sql_gz($sqlDir, $countrySqlGz);

        $trialBalance = orange_country_export_compute_trial_balance($pdo, $countryId, $idSnapshot);
        if (abs($trialBalance['difference']) > ORANGE_COUNTRY_EXPORT_TRIAL_BALANCE_TOLERANCE) {
            throw new RuntimeException('Trial balance mismatch: difference=' . (string) $trialBalance['difference']);
        }

        $inventorySummary = orange_country_export_compute_inventory_summary($pdo, $countryId, $idSnapshot);
        $uploads = orange_country_uploads_collect($projectRoot, $countryId, $exportedRows);
        foreach ($uploads['issues'] as $issue) {
            if (str_starts_with($issue, 'critical:')) {
                throw new RuntimeException('Critical upload missing: ' . $issue);
            }
        }

        $uploadZip = $filesDir . DIRECTORY_SEPARATOR . 'uploads_country.zip';
        if ($uploads['files'] === []) {
            orange_country_uploads_write_empty_zip($uploadZip);
        } else {
            orange_country_uploads_write_zip($uploadZip, $projectRoot, $uploads['files']);
        }

        $counts = orange_country_export_summary_counts($rowCounts);
        $restoreBatches = orange_country_boundary_matrix_restore_batches($boundaryMatrix);
        ksort($batchMeta);
        $dependencyGraph = orange_country_export_build_dependency_graph_c3($boundaryMatrix, $registry, $restoreBatches);
        $tableInventory = [
            'country_id' => $countryId,
            'country_code' => $country['code'],
            'schema_revision' => ORANGE_CATALOG_SCHEMA_PHP_REVISION,
            'boundary_policy_version' => ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
            'dependency_graph_version' => ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
            'registry_version' => (string) ($registry['registry_version'] ?? ''),
            'tables' => $rowCounts,
            'classification_summary' => orange_crp_export_classification_summary($rowCounts, $boundaryMatrix),
            'ownership_summary' => orange_country_export_row_counts_by_ownership($rowCounts, $registry),
            'restore_batches' => $restoreBatches,
            'other_country_markers' => $otherCountryMarkers,
            'never_export' => ORANGE_CRP_NEVER_EXPORT_TABLES,
        ];

        orange_backup_write_json($tempDir . DIRECTORY_SEPARATOR . 'dependency_graph.json', $dependencyGraph);
        orange_backup_write_json($tempDir . DIRECTORY_SEPARATOR . 'table_inventory.json', $tableInventory);
        orange_backup_write_json($tempDir . DIRECTORY_SEPARATOR . 'id_snapshot.json', [
            'country_id' => $countryId,
            'generated_at' => gmdate('c'),
            'tables' => $idSnapshot,
        ]);

        $health = orange_country_export_build_health([
            'country_id' => $countryId,
            'country_code' => $country['code'],
            'country_label' => $country['label'],
            'schema_revision' => ORANGE_CATALOG_SCHEMA_PHP_REVISION,
            'registry_version' => (string) ($registry['registry_version'] ?? ''),
            'counts' => $counts,
            'row_counts' => $rowCounts,
            'trial_balance' => $trialBalance,
            'inventory_summary' => $inventorySummary,
            'validation_errors' => $validationErrors,
            'validation_warnings' => $validationWarnings,
            'upload_issues' => $uploads['issues'],
            'upload_files_collected' => $uploads['collected'],
            'upload_files_missing' => $uploads['missing'],
            'maintenance_notes' => [],
        ]);
        if (($health['package_status'] ?? 'failed') === 'failed') {
            throw new RuntimeException('Package health failed: ' . implode('; ', $health['failure_reasons'] ?? []));
        }

        orange_backup_write_json($tempDir . DIRECTORY_SEPARATOR . 'health.json', $health);

        $exportTime = gmdate('c');
        $manifest = [
            'package_type' => ORANGE_COUNTRY_EXPORT_PACKAGE_TYPE,
            'package_version' => ORANGE_COUNTRY_EXPORT_PACKAGE_VERSION,
            'generated_at' => $exportTime,
            'export_time' => $exportTime,
            'country_id' => $countryId,
            'country_code' => $country['code'],
            'country_label' => $country['label'],
            'schema_revision' => ORANGE_CATALOG_SCHEMA_PHP_REVISION,
            'boundary_policy_version' => ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
            'dependency_graph_version' => ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
            'matrix_version' => (string) ($boundaryMatrix['matrix_version'] ?? ''),
            'registry_version' => (string) ($registry['registry_version'] ?? ''),
            'drv_version' => ORANGE_COUNTRY_EXPORT_DRV_VERSION,
            'verify_version' => ORANGE_COUNTRY_EXPORT_VERIFY_VERSION,
            'git_commit' => orange_backup_git_commit_hash($projectRoot),
            'source_database' => $dbName,
            'export_backend' => ORANGE_COUNTRY_EXPORT_BACKEND,
            'row_counts' => $rowCounts,
            'ownership_summary' => $tableInventory['ownership_summary'],
            'classification_summary' => $tableInventory['classification_summary'],
            'restore_batches' => $restoreBatches,
            'restore_batch_export' => array_values($batchMeta),
            'country_sql_archive' => 'country.sql.gz',
            'sql_directory' => 'sql/',
            'uploads_archive' => 'files/uploads_country.zip',
            'uploads_file_count' => (int) ($uploads['collected'] ?? 0),
            'checksums_file' => 'checksums.sha256',
            'health_report_file' => 'health.json',
            'dependency_graph_file' => 'dependency_graph.json',
            'table_inventory_file' => 'table_inventory.json',
            'id_snapshot_file' => 'id_snapshot.json',
            'package_status' => (string) ($health['package_status'] ?? 'healthy'),
            'maintenance_notes' => [],
            'never_export' => ORANGE_CRP_NEVER_EXPORT_TABLES,
        ];
        orange_backup_write_json($tempDir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);

        $checksumTargets = orange_backup_collect_package_files($tempDir);
        orange_backup_write_checksums($tempDir, $checksumTargets);
        $packageFingerprint = orange_crp_export_package_fingerprint($tempDir, $manifest);
        $manifest['package_fingerprint'] = $packageFingerprint;
        orange_backup_write_json($tempDir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
        // Refresh checksums after fingerprint written into manifest.
        $checksumTargets = orange_backup_collect_package_files($tempDir);
        orange_backup_write_checksums($tempDir, $checksumTargets);

        $verify = orange_country_export_verify_package($tempDir);
        if (!$verify['ok']) {
            throw new RuntimeException('Package verification failed: ' . implode('; ', $verify['errors']));
        }

        orange_backup_atomic_finalize($tempDir, $finalDir);
        orange_backup_pdo_end_snapshot($pdo, true);
        $committed = true;

        return [
            'ok' => true,
            'package_path' => $finalDir,
            'message' => 'Country recovery package exported.',
            'manifest' => $manifest,
        ];
    } catch (Throwable $e) {
        orange_backup_pdo_end_snapshot($pdo, $committed);
        orange_backup_remove_dir($tempDir);
        throw $e;
    }
}

function orange_country_export_write_session_file(string $path, bool $preamble): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Cannot write session SQL file.');
    }
    if ($preamble) {
        orange_backup_pdo_write_preamble($handle);
    } else {
        orange_backup_pdo_write_postamble($handle);
    }
    fclose($handle);
}

/**
 * @param array<string, list<int>> $idSnapshot
 * @return array{row_count:int,ids:list<int>,rows:list<array<string,mixed>>,errors:list<string>,warnings:list<string>}
 */
function orange_country_export_table(
    PDO $pdo,
    string $dbName,
    string $tableName,
    array $meta,
    int $countryId,
    array $idSnapshot,
    string $sqlDir
): array {
    $query = orange_country_export_build_query($tableName, $meta, $countryId, $idSnapshot);
    $st = $pdo->prepare($query['sql']);
    $st->execute($query['params']);

    $order = (int) ($meta['export_order'] ?? 0);
    $sqlFile = $sqlDir . DIRECTORY_SEPARATOR . sprintf('%03d_%s.sql', $order, $tableName);
    $handle = fopen($sqlFile, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Cannot open SQL chunk for ' . $tableName);
    }

    fwrite($handle, '-- Orange CRP export table=' . $tableName . ' country_id=' . $countryId . "\n");

    $columnMeta = orange_backup_pdo_table_columns($pdo, $dbName, $tableName);
    $insertColumns = orange_backup_pdo_insertable_column_names($columnMeta);
    $hasId = in_array('id', $insertColumns, true);

    $ids = [];
    $rows = [];
    $errors = [];
    $warnings = [];
    $batch = [];
    $rowCount = 0;

    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }
        $rowCount++;
        $errors = array_merge($errors, orange_country_export_validate_row_country_scope($tableName, $meta, $row, $countryId));
        $errors = array_merge($errors, orange_country_export_validate_orphan_fk($tableName, $meta, $row, $idSnapshot));
        if ($hasId && isset($row['id'])) {
            $ids[] = (int) $row['id'];
        }
        if ((bool) ($meta['uploads_linked'] ?? false) || in_array($tableName, ORANGE_COUNTRY_EXPORT_UPLOAD_DISCOVERY_TABLES, true)) {
            $rows[] = $row;
        }
        $batch[] = $row;
        if (count($batch) >= ORANGE_COUNTRY_EXPORT_CHUNK_ROWS) {
            orange_backup_pdo_write_insert_chunk($pdo, $handle, $tableName, $insertColumns, $columnMeta, $batch);
            $batch = [];
        }
    }
    if ($batch !== []) {
        orange_backup_pdo_write_insert_chunk($pdo, $handle, $tableName, $insertColumns, $columnMeta, $batch);
    }
    fwrite($handle, '-- rows=' . $rowCount . "\n");
    fclose($handle);

    return [
        'row_count' => $rowCount,
        'ids' => array_values(array_unique($ids)),
        'rows' => $rows,
        'errors' => $errors,
        'warnings' => $warnings,
    ];
}

/**
 * @param array<string, list<int>> $idSnapshot
 * @return array{sql:string,params:list<mixed>}
 */
function orange_country_export_build_query(string $tableName, array $meta, int $countryId, array $idSnapshot): array
{
    $rule = $meta['extraction_rule'] ?? [];
    if (!is_array($rule)) {
        throw new RuntimeException('Missing extraction_rule for ' . $tableName);
    }
    $type = (string) ($rule['type'] ?? '');
    $tableSql = orange_backup_pdo_quote_identifier($tableName);

    return match ($type) {
        'country_id' => [
            // D2: exact equality only — never COALESCE / IS NULL.
            'sql' => 'SELECT * FROM ' . $tableSql . ' WHERE ' . orange_backup_pdo_quote_identifier((string) ($rule['column'] ?? 'country_id')) . ' = ?',
            'params' => [$countryId],
        ],
        'special_sequence_namespace' => orange_crp_export_build_sequence_namespace_query($countryId),
        'parent_rows' => orange_country_export_build_parent_rows_query($tableName, $rule, $idSnapshot),
        'custom_sql' => orange_country_export_build_custom_sql_query($tableName, $rule, $countryId),
        'country_scope_or' => throw new RuntimeException(
            'country_scope_or forbidden under frozen boundary policy (table=' . $tableName . ')'
        ),
        default => throw new RuntimeException('Unsupported extraction rule type for ' . $tableName . ': ' . $type),
    };
}

/**
 * @return array{sql:string,params:list<mixed>}
 */
function orange_country_export_build_country_scope_or_query(string $tableName, array $rule, int $countryId): array
{
    $columns = is_array($rule['columns'] ?? null) ? $rule['columns'] : [];
    if ($columns === []) {
        throw new RuntimeException('country_scope_or requires columns for ' . $tableName);
    }
    $parts = [];
    foreach ($columns as $column) {
        $parts[] = orange_backup_pdo_quote_identifier((string) $column) . ' = ?';
    }
    $params = array_fill(0, count($columns), $countryId);

    return [
        'sql' => 'SELECT * FROM ' . orange_backup_pdo_quote_identifier($tableName) . ' WHERE (' . implode(' OR ', $parts) . ')',
        'params' => $params,
    ];
}

/**
 * @param array<string, list<int>> $idSnapshot
 * @return array{sql:string,params:list<mixed>}
 */
function orange_country_export_build_parent_rows_query(string $tableName, array $rule, array $idSnapshot): array
{
    $parentTable = (string) ($rule['parent_table'] ?? '');
    $foreignKey = (string) ($rule['foreign_key'] ?? '');
    if ($parentTable === '' || $foreignKey === '') {
        throw new RuntimeException('Invalid parent_rows rule for ' . $tableName);
    }
    $parentIds = $idSnapshot[$parentTable] ?? [];
    if ($parentIds === []) {
        return [
            'sql' => 'SELECT * FROM ' . orange_backup_pdo_quote_identifier($tableName) . ' WHERE 1=0',
            'params' => [],
        ];
    }
    $placeholders = implode(',', array_fill(0, count($parentIds), '?'));

    return [
        'sql' => 'SELECT * FROM ' . orange_backup_pdo_quote_identifier($tableName) . ' WHERE ' . orange_backup_pdo_quote_identifier($foreignKey) . ' IN (' . $placeholders . ')',
        'params' => $parentIds,
    ];
}

/**
 * @return array{sql:string,params:list<mixed>}
 */
function orange_country_export_build_custom_sql_query(string $tableName, array $rule, int $countryId): array
{
    $idSql = trim((string) ($rule['sql'] ?? ''));
    if ($idSql === '') {
        throw new RuntimeException('custom_sql missing sql for ' . $tableName);
    }
    if (!preg_match('/^\s*SELECT\s+/i', $idSql)) {
        throw new RuntimeException('custom_sql must be SELECT for ' . $tableName);
    }
    /** @var PDO $pdo */
    $pdo = $GLOBALS['orange_country_export_pdo_ref'] ?? null;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Internal export PDO reference missing.');
    }

    $paramCount = substr_count($idSql, ':country_id');
    $positionalSql = str_replace(':country_id', '?', $idSql);
    $params = array_fill(0, max(1, $paramCount), $countryId);

    $idStmt = $pdo->prepare($positionalSql);
    $idStmt->execute($params);
    $ids = [];
    while ($row = $idStmt->fetch(PDO::FETCH_NUM)) {
        if (isset($row[0])) {
            $ids[] = (int) $row[0];
        }
    }
    $ids = array_values(array_unique(array_filter($ids, static fn (int $v): bool => $v > 0)));
    if ($ids === []) {
        return [
            'sql' => 'SELECT * FROM ' . orange_backup_pdo_quote_identifier($tableName) . ' WHERE 1=0',
            'params' => [],
        ];
    }

    $columnMeta = orange_backup_pdo_table_columns($pdo, defined('DB_NAME') ? (string) DB_NAME : '', $tableName);
    $insertColumns = orange_backup_pdo_insertable_column_names($columnMeta);
    if (in_array('id', $insertColumns, true)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return [
            'sql' => 'SELECT * FROM ' . orange_backup_pdo_quote_identifier($tableName) . ' WHERE id IN (' . $placeholders . ')',
            'params' => $ids,
        ];
    }

    // Fallback: re-run custom SELECT as full-row query when no single id column exists.
    return [
        'sql' => $positionalSql,
        'params' => $params,
    ];
}

/**
 * @param array<string, int> $rowCounts
 * @return array<string, int>
 */
function orange_country_export_row_counts_by_ownership(array $rowCounts, array $registry): array
{
    $summary = [
        'country_owned' => 0,
        'dependent' => 0,
    ];
    /** @var array<string, array<string, mixed>> $tables */
    $tables = $registry['tables'];
    foreach ($rowCounts as $table => $count) {
        $type = (string) ($tables[$table]['ownership_type'] ?? '');
        if (isset($summary[$type])) {
            $summary[$type] += $count;
        }
    }

    return $summary;
}

/**
 * @param array<string, int> $rowCounts
 * @return array<string, int>
 */
function orange_country_export_summary_counts(array $rowCounts): array
{
    return [
        'customers' => (int) ($rowCounts['customers'] ?? 0),
        'suppliers' => (int) ($rowCounts['suppliers'] ?? 0),
        'products' => (int) ($rowCounts['products'] ?? 0),
        'warehouses' => (int) ($rowCounts['warehouses'] ?? 0),
        'purchases' => (int) ($rowCounts['purchases'] ?? 0),
        'purchase_returns' => (int) ($rowCounts['purchase_returns'] ?? 0),
        'orders' => (int) ($rowCounts['orders'] ?? 0),
        'sales_returns' => (int) ($rowCounts['sales_returns'] ?? 0),
        'journal_vouchers' => (int) ($rowCounts['journal_vouchers'] ?? 0),
        'journal_lines' => (int) ($rowCounts['journal_lines'] ?? 0),
        'party_subledger' => (int) ($rowCounts['party_subledger'] ?? 0),
        'loyalty_ledger' => (int) ($rowCounts['loyalty_ledger'] ?? 0),
        'stock_movements' => (int) ($rowCounts['stock_movements'] ?? 0),
        'inventory_cost_layers' => (int) ($rowCounts['inventory_cost_layers'] ?? 0),
        'inventory_cost_consumptions' => (int) ($rowCounts['inventory_cost_consumptions'] ?? 0),
        'orange_gl_voucher_slots' => (int) ($rowCounts['orange_gl_voucher_slots'] ?? 0),
    ];
}

/**
 * Legacy graph builder (registry-only). Prefer orange_country_export_build_dependency_graph_c3().
 *
 * @return array{nodes:list<array<string,mixed>>,edges:list<array<string,mixed>>}
 */
function orange_country_export_build_dependency_graph(array $registry): array
{
    $nodes = [];
    $edges = [];
    foreach (orange_backup_registry_exportable_tables($registry) as $entry) {
        if (in_array($entry['table'], ORANGE_CRP_NEVER_EXPORT_TABLES, true)) {
            continue;
        }
        $nodes[] = [
            'table' => $entry['table'],
            'ownership_type' => $entry['meta']['ownership_type'] ?? '',
            'export_order' => (int) ($entry['meta']['export_order'] ?? 0),
            'integrity_critical' => (bool) ($entry['meta']['integrity_critical'] ?? false),
        ];
        $parent = $entry['meta']['parent_dependency'] ?? null;
        if (is_array($parent) && ($parent['table'] ?? '') !== '') {
            $edges[] = [
                'from' => $entry['table'],
                'to' => (string) $parent['table'],
                'foreign_key' => (string) ($parent['foreign_key'] ?? ''),
            ];
        }
    }

    return ['nodes' => $nodes, 'edges' => $edges];
}

/**
 * @param array<string, mixed> $matrix
 * @param array<string, mixed> $registry
 * @param array<int, list<string>> $restoreBatches
 * @return array<string, mixed>
 */
function orange_country_export_build_dependency_graph_c3(
    array $matrix,
    array $registry,
    array $restoreBatches
): array {
    $plan = orange_country_boundary_matrix_export_plan($matrix, $registry);
    $nodes = [];
    $edges = [];
    foreach ($plan as $entry) {
        $table = $entry['table'];
        $meta = $entry['meta'];
        $m = $entry['matrix'];
        $nodes[] = [
            'table' => $table,
            'classification' => (string) ($m['classification'] ?? ''),
            'restore_mode' => (string) ($m['restore_mode'] ?? ''),
            'restore_batch' => (int) ($m['restore_batch'] ?? 0),
            'delete_batch' => (int) ($m['delete_batch'] ?? 0),
            'ownership_resolver' => (string) ($m['ownership_resolver'] ?? ''),
            'special_handler' => $m['special_handler'] ?? null,
            'ownership_type' => $meta['ownership_type'] ?? '',
            'export_order' => (int) ($meta['export_order'] ?? 0),
            'integrity_critical' => (bool) ($meta['integrity_critical'] ?? false),
        ];
        $parent = $meta['parent_dependency'] ?? null;
        if (is_array($parent) && ($parent['table'] ?? '') !== '') {
            $edges[] = [
                'from' => $table,
                'to' => (string) $parent['table'],
                'foreign_key' => (string) ($parent['foreign_key'] ?? ''),
            ];
        }
    }

    return [
        'boundary_policy_version' => ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
        'dependency_graph_version' => ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
        'schema_revision' => (int) ($matrix['schema_revision'] ?? (defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION') ? ORANGE_CATALOG_SCHEMA_PHP_REVISION : 123)),
        'restore_batches' => $restoreBatches,
        'nodes' => $nodes,
        'edges' => $edges,
    ];
}

/**
 * @return array{sql:string,params:list<mixed>}
 */
function orange_crp_export_build_sequence_namespace_query(int $countryId): array
{
    // D3: only scopes ending with _c{country_id}. LIKE '_' is wildcard — escape it.
    return [
        'sql' => 'SELECT * FROM `document_sequences` WHERE `scope` LIKE ? ESCAPE \'\\\\\'',
        'params' => ['%\\_c' . $countryId],
    ];
}

/**
 * Concatenate sql/*.sql chunks into country.sql.gz (C3 package contract).
 */
function orange_crp_export_write_country_sql_gz(string $sqlDir, string $destinationGz): void
{
    if (!function_exists('gzopen')) {
        throw new RuntimeException('gzopen unavailable for country.sql.gz');
    }
    $files = glob($sqlDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    sort($files, SORT_STRING);
    if ($files === []) {
        throw new RuntimeException('No SQL chunks to pack into country.sql.gz');
    }
    $out = gzopen($destinationGz, 'wb9');
    if ($out === false) {
        throw new RuntimeException('Cannot open country.sql.gz for writing');
    }
    foreach ($files as $file) {
        $raw = file_get_contents($file);
        if ($raw === false) {
            gzclose($out);
            throw new RuntimeException('Cannot read SQL chunk: ' . basename($file));
        }
        gzwrite($out, $raw);
        if (!str_ends_with($raw, "\n")) {
            gzwrite($out, "\n");
        }
    }
    gzclose($out);
}

/**
 * @param array<string, list<int>> $idSnapshot
 * @param array<string, int> $rowCounts
 * @return list<string>
 */
function orange_crp_export_validate_composites(array $idSnapshot, array $rowCounts): array
{
    $errors = [];
    $adminIds = $idSnapshot['admins'] ?? [];
    $permCount = (int) ($rowCounts['admin_permissions'] ?? 0);
    if ($adminIds === [] && $permCount > 0) {
        $errors[] = 'admin_permissions_composite_incomplete: permissions without target admins';
    }
    $accountIds = $idSnapshot['accounts'] ?? [];
    $expenseCount = (int) ($rowCounts['expenses'] ?? 0);
    if ($expenseCount > 0 && $accountIds === []) {
        $errors[] = 'composite_unit_incomplete: expenses without accounts';
    }
    $voucherIds = $idSnapshot['journal_vouchers'] ?? [];
    $slotCount = (int) ($rowCounts['orange_gl_voucher_slots'] ?? 0);
    if ($slotCount > 0 && $voucherIds === []) {
        $errors[] = 'composite_unit_incomplete: voucher slots without journal_vouchers';
    }
    $seqCount = (int) ($rowCounts['document_sequences'] ?? 0);
    if ($seqCount < 0) {
        $errors[] = 'sequence_namespace_violation';
    }

    return $errors;
}

/**
 * @param array<string, int> $rowCounts
 * @param array<string, mixed> $matrix
 * @return array<string, int>
 */
function orange_crp_export_classification_summary(array $rowCounts, array $matrix): array
{
    $summary = ['Country Scoped' => 0, 'Mixed' => 0];
    /** @var array<string, array<string, mixed>> $tables */
    $tables = is_array($matrix['tables'] ?? null) ? $matrix['tables'] : [];
    foreach ($rowCounts as $table => $count) {
        $class = (string) ($tables[$table]['classification'] ?? '');
        if (isset($summary[$class])) {
            $summary[$class] += (int) $count;
        }
    }

    return $summary;
}

/**
 * @param array<string, mixed> $manifest
 */
function orange_crp_export_package_fingerprint(string $packageRoot, array $manifest): string
{
    $parts = [
        'v=' . (string) ($manifest['package_version'] ?? ''),
        'c=' . (string) ($manifest['country_id'] ?? ''),
        's=' . (string) ($manifest['schema_revision'] ?? ''),
        'bp=' . (string) ($manifest['boundary_policy_version'] ?? ''),
        'dg=' . (string) ($manifest['dependency_graph_version'] ?? ''),
    ];
    foreach (['country.sql.gz', 'files/uploads_country.zip', 'table_inventory.json', 'dependency_graph.json'] as $rel) {
        $abs = $packageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $parts[] = $rel . '=' . (is_file($abs) ? (hash_file('sha256', $abs) ?: '') : 'missing');
    }

    return hash('sha256', implode('|', $parts));
}

/**
 * Internal PDO reference for custom_sql id resolution.
 */
function orange_country_export_set_pdo_ref(PDO $pdo): void
{
    $GLOBALS['orange_country_export_pdo_ref'] = $pdo;
}
