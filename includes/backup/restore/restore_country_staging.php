<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_job.php';
require_once __DIR__ . '/restore_lock.php';
require_once __DIR__ . '/restore_audit.php';
require_once __DIR__ . '/restore_staging_target.php';
require_once __DIR__ . '/restore_sql_runner.php';
require_once __DIR__ . '/restore_uploads_applicator.php';
require_once __DIR__ . '/restore_validation_adapter.php';
require_once __DIR__ . '/restore_fresh_backup_gate.php';
require_once __DIR__ . '/restore_approval.php';
require_once __DIR__ . '/../backup_manifest.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_validate.php';
require_once __DIR__ . '/../backup_table_registry_lib.php';
require_once __DIR__ . '/../country_export.php';
require_once __DIR__ . '/../recovery_validation.php';

/**
 * Country Recovery Package restore into staging only (Phase 2B.2).
 *
 * Fresh backup anchor is mandatory — no bypass flags.
 *
 * @param array{
 *   project_root:string,
 *   package_path:string,
 *   env_override?:array<string,mixed>
 * } $options
 * @return array<string, mixed>
 */
function orange_restore_country_staging_run(array $options): array
{
    $startedAt = microtime(true);
    $projectRoot = (string) ($options['project_root'] ?? '');
    $packagePathInput = (string) ($options['package_path'] ?? '');

    if ($projectRoot === '' || $packagePathInput === '') {
        throw new InvalidArgumentException('project_root and package_path are required.');
    }

    $env = orange_backup_load_env_array($projectRoot);
    if (is_array($options['env_override'] ?? null)) {
        $env = array_merge($env, $options['env_override']);
    }
    $backupRoot = orange_backup_resolve_root($env);
    $workRoot = orange_restore_resolve_work_root($env);
    $packagePath = orange_restore_resolve_package_path($backupRoot, $packagePathInput);
    $productionDb = orange_restore_production_db_name($projectRoot);

    orange_restore_log('Restore country → staging START');
    orange_restore_log('Package=' . $packagePath);

    $lock = orange_restore_acquire_lock($workRoot, 'pending');
    if (!$lock['ok']) {
        throw new RuntimeException($lock['message']);
    }

    $jobId = '';
    $stagingDirty = false;
    $rollbackPreserved = false;
    $currentStage = 'package_precheck';

    try {
        $precheck = orange_restore_validation_adapter_country_package_precheck($packagePath);
        if (!$precheck['ok']) {
            throw new RuntimeException('Country package pre-validation failed: ' . implode('; ', $precheck['errors']));
        }

        /** @var array<string, mixed> $manifest */
        $manifest = is_array($precheck['verify']['manifest'] ?? null) ? $precheck['verify']['manifest'] : [];
        if (($manifest['package_type'] ?? '') !== ORANGE_RESTORE_JOB_TYPE_COUNTRY) {
            throw new RuntimeException('Package is not country_recovery.');
        }

        $countryId = (int) ($manifest['country_id'] ?? 0);
        $countryCode = (string) ($manifest['country_code'] ?? '');
        if ($countryId <= 0 || $countryCode === '') {
            throw new RuntimeException('Country package manifest missing country_id or country_code.');
        }

        $importPlan = orange_restore_country_staging_build_import_plan($projectRoot, $packagePath, $manifest);
        if (!$importPlan['ok']) {
            throw new RuntimeException((string) ($importPlan['error'] ?? 'Country import plan failed.'));
        }

        $packageChecksum = orange_restore_country_package_anchor_checksum($packagePath);
        $job = orange_restore_job_create($workRoot, [
            'job_type' => ORANGE_RESTORE_JOB_TYPE_COUNTRY,
            'operator_admin_id' => 0,
            'operator_username' => 'cli',
            'source_package_path' => $packagePath,
            'source_package_checksum' => $packageChecksum,
            'package_version' => (string) ($manifest['package_version'] ?? ''),
            'schema_revision' => (int) ($manifest['schema_revision'] ?? 0),
            'country_id' => $countryId,
            'country_code' => $countryCode,
            'approval_phrase_expected' => orange_restore_confirmation_phrase(ORANGE_RESTORE_JOB_TYPE_COUNTRY, $countryCode),
        ]);
        $jobId = (string) ($job['job_id'] ?? '');
        orange_restore_release_lock($workRoot);
        $lock = orange_restore_acquire_lock($workRoot, $jobId);
        if (!$lock['ok']) {
            throw new RuntimeException($lock['message']);
        }

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_from_job($job, 'package_precheck', 'pass'));
        $job = orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_VALIDATED, [
            'source_package_checksum' => $packageChecksum,
        ]);

        $stagingDb = orange_restore_staging_db_name($env, $projectRoot);

        $currentStage = 'fresh_backup_anchor';
        $fresh = orange_restore_fresh_backup_gate($projectRoot, $backupRoot);
        if (!$fresh['ok']) {
            throw new RuntimeException('Fresh backup gate failed: ' . implode('; ', $fresh['errors']));
        }
        $job = orange_restore_job_record_fresh_backup_anchor(
            $workRoot,
            $jobId,
            $fresh['snapshot_path'],
            $fresh['checksum']
        );
        $rollbackPreserved = true;
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_from_job($job, 'fresh_backup_anchor', 'pass', [
            'fresh_backup_path' => $fresh['snapshot_path'],
            'fresh_backup_checksum' => $fresh['checksum'],
        ]));

        $currentStage = 'staging_target_confirm';
        $stagingTarget = orange_restore_staging_confirm_target($projectRoot, $env);
        orange_restore_audit_append($workRoot, $jobId, [
            'stage' => 'staging_target_confirm',
            'result' => 'pass',
            'staging_db' => $stagingTarget['staging_db'],
            'staging_user' => $stagingTarget['staging_user'],
            'production_db' => $stagingTarget['production_db'],
            'session_database' => $stagingTarget['session_database'],
        ]);

        $stagingUploads = orange_restore_staging_uploads_directory($workRoot, $jobId);
        $currentStage = 'staging_restore';
        $job = orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_STAGING, [
            'staging_db' => $stagingDb,
            'staging_uploads_path' => $stagingUploads,
            'staging_dirty' => true,
            'staging_target_confirmed_at' => $stagingTarget['confirmed_at'],
        ]);
        $stagingDirty = true;

        $pdo = orange_restore_connect_staging_pdo($projectRoot, $env);
        orange_restore_country_staging_clear_tables($pdo, $stagingDb, $importPlan['delete_order_tables']);

        $sqlResult = orange_restore_sql_runner_import_country_chunks(
            $pdo,
            $importPlan['import_files'],
            $stagingDb,
            $productionDb
        );
        if (!$sqlResult['ok']) {
            throw new RuntimeException((string) ($sqlResult['error'] ?? 'Country SQL import failed'));
        }

        $uploadsPath = $packagePath . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip';
        $uploadsResult = orange_restore_uploads_applicator_extract($uploadsPath, $stagingUploads);
        if (!$uploadsResult['ok']) {
            throw new RuntimeException((string) ($uploadsResult['error'] ?? 'Country uploads extract failed'));
        }

        $currentStage = 'staging_post_validation';
        $stagingPost = orange_restore_validation_adapter_country_staging_postcheck(
            $pdo,
            $stagingDb,
            $packagePath,
            $manifest,
            $importPlan
        );
        $stagingDrv = orange_restore_validation_adapter_build_country_staging_drv_report(
            is_array($precheck['drv']) ? $precheck['drv'] : [],
            $stagingPost
        );
        if (!$stagingPost['ok']) {
            throw new RuntimeException('Country staging post-validation failed: ' . implode('; ', $stagingPost['errors']));
        }

        $stagingManifest = [
            'generated_at' => gmdate('c'),
            'job_id' => $jobId,
            'job_type' => ORANGE_RESTORE_JOB_TYPE_COUNTRY,
            'source_package_path' => $packagePath,
            'source_package_checksum' => $packageChecksum,
            'country_id' => $countryId,
            'country_code' => $countryCode,
            'export_backend' => (string) ($manifest['export_backend'] ?? ORANGE_COUNTRY_EXPORT_BACKEND),
            'staging_db' => $stagingDb,
            'staging_uploads_path' => $stagingUploads,
            'staging_target' => $stagingTarget,
            'import_plan' => $importPlan,
            'sql_import' => $sqlResult,
            'uploads_extract' => $uploadsResult,
            'country_staging_post_validation' => $stagingPost,
            'country_staging_drv_report' => $stagingDrv,
            'production_touched' => false,
        ];
        $stagingManifestPath = orange_restore_job_staging_manifest_path($workRoot, $jobId);
        orange_backup_write_json($stagingManifestPath, $stagingManifest);

        $duration = (int) round(microtime(true) - $startedAt);
        $job = orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_STAGING_VALIDATED, [
            'staging_dirty' => false,
            'staging_restore_manifest_path' => $stagingManifestPath,
            'duration_seconds' => $duration,
            'result' => 'staging_validated',
        ]);
        $stagingDirty = false;

        $report = [
            'generated_at' => gmdate('c'),
            'job_id' => $jobId,
            'job_type' => ORANGE_RESTORE_JOB_TYPE_COUNTRY,
            'overall_result' => 'pass',
            'duration_seconds' => $duration,
            'source_package_path' => $packagePath,
            'country_id' => $countryId,
            'country_code' => $countryCode,
            'rollback_anchor' => [
                'fresh_backup_path' => (string) ($job['fresh_backup_path'] ?? ''),
                'fresh_backup_checksum' => (string) ($job['fresh_backup_checksum'] ?? ''),
                'rollback_anchor_job_only' => (bool) ($job['rollback_anchor_job_only'] ?? true),
            ],
            'precheck_drv' => $precheck['drv'],
            'staging_manifest' => $stagingManifestPath,
            'country_staging_post_validation' => $stagingPost,
            'country_staging_drv_report' => $stagingDrv,
            'production_touched' => false,
        ];
        $reportPath = orange_restore_job_report_path($workRoot, $jobId);
        orange_backup_write_json($reportPath, $report);
        $job = orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL, [
            'restore_report_path' => $reportPath,
            'owner_approval_window_started_at' => gmdate('c'),
        ]);

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_from_job($job, 'staging_restore', 'pass', [
            'duration_seconds' => $duration,
            'country_id' => $countryId,
            'country_code' => $countryCode,
        ]));

        orange_restore_log('Restore country → staging END (job_id=' . $jobId . ')');

        return [
            'ok' => true,
            'job_id' => $jobId,
            'job' => $job,
            'report_path' => $reportPath,
            'staging_manifest_path' => $stagingManifestPath,
            'rollback_anchor_preserved' => $rollbackPreserved || (string) ($job['fresh_backup_path'] ?? '') !== '',
        ];
    } catch (Throwable $e) {
        if ($jobId !== '') {
            orange_restore_job_mark_failed($workRoot, $jobId, $currentStage, $e->getMessage(), $stagingDirty);
            orange_restore_audit_append($workRoot, $jobId, [
                'stage' => $currentStage,
                'result' => 'failed',
                'error' => $e->getMessage(),
                'staging_dirty' => $stagingDirty,
                'rollback_anchor_preserved' => $rollbackPreserved,
            ]);
        }
        orange_restore_log('Restore country → staging FAIL: ' . $e->getMessage());
        throw $e;
    } finally {
        orange_restore_release_lock($workRoot);
    }
}

function orange_restore_country_package_anchor_checksum(string $packagePath): string
{
    $checksumFile = $packagePath . DIRECTORY_SEPARATOR . 'checksums.sha256';
    if (is_file($checksumFile)) {
        return orange_backup_sha256_file($checksumFile);
    }

    throw new RuntimeException('Cannot determine country package checksum.');
}

/**
 * Parse a Country Recovery Package SQL chunk basename.
 *
 * Exporter contract (country_export.php): sprintf('%03d_%s.sql', export_order, table)
 * — minimum width 3 digits; values ≥ 1000 naturally produce 4+ digits.
 * Historical packages with exactly three digits remain valid (FSR-D5-CRP-CHUNK-01).
 *
 * @return array{kind:string,table:string,prefix:string,prefix_int:int}|null
 */
function orange_restore_country_staging_parse_sql_chunk(string $filename): ?array
{
    if ($filename === '' || str_contains($filename, "\0") || preg_match('/[[:cntrl:]]/', $filename) === 1) {
        return null;
    }

    $normalized = str_replace('\\', '/', $filename);
    // Reject traversal path segments (e.g. ../001_table.sql) while allowing absolute package paths.
    if (preg_match('#(?:^|/)\.\.(?:/|$)#', $normalized) === 1) {
        return null;
    }

    // Package callers pass absolute glob paths — validate the basename only.
    $base = basename($normalized);
    if ($base === ''
        || str_contains($base, '/')
        || str_contains($base, '\\')
        || str_contains($base, '..')
        || str_contains($base, '://')
    ) {
        return null;
    }
    // Minimum 3 digits (historical + sprintf %03d); allow 4+ for export_order ≥ 1000.
    // Table/token: safe MySQL-ish identifier only (rejects ../, spaces, metacharacters).
    if (!preg_match('/^(\d{3,})_([A-Za-z_][A-Za-z0-9_]*)\.sql$/', $base, $matches)) {
        return null;
    }

    $table = (string) $matches[2];
    $kind = 'table';
    if ($table === 'session_preamble') {
        $kind = 'preamble';
    } elseif ($table === 'session_postamble') {
        $kind = 'postamble';
    }

    return [
        'kind' => $kind,
        'table' => $table,
        'prefix' => (string) $matches[1],
        'prefix_int' => (int) $matches[1],
    ];
}

/**
 * Build ordered CRP SQL import plan from registry restore_order + package dependency graph.
 *
 * @param array<string, mixed> $manifest
 * @return array{
 *   ok:bool,
 *   error:?string,
 *   import_files:list<string>,
 *   tables:list<string>,
 *   delete_order_tables:list<string>,
 *   registry_version:string,
 *   dependency_graph_valid:bool
 * }
 */
function orange_restore_country_staging_build_import_plan(
    string $projectRoot,
    string $packagePath,
    array $manifest
): array {
    $registry = orange_backup_registry_load($projectRoot);
    $manifestRegistryVersion = trim((string) ($manifest['registry_version'] ?? ''));
    $liveRegistryVersion = trim((string) ($registry['registry_version'] ?? ''));
    if ($manifestRegistryVersion === '') {
        return ['ok' => false, 'error' => 'manifest.registry_version missing', 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'registry_version' => '', 'dependency_graph_valid' => false];
    }
    if ($manifestRegistryVersion !== ORANGE_RECOVERY_VALIDATION_EXPECTED_REGISTRY_VERSION) {
        return ['ok' => false, 'error' => 'Registry version mismatch (expected ' . ORANGE_RECOVERY_VALIDATION_EXPECTED_REGISTRY_VERSION . ', got ' . $manifestRegistryVersion . ')', 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'registry_version' => $manifestRegistryVersion, 'dependency_graph_valid' => false];
    }
    if ($liveRegistryVersion !== '' && $liveRegistryVersion !== $manifestRegistryVersion) {
        return ['ok' => false, 'error' => 'Live registry version mismatch with package (live=' . $liveRegistryVersion . ', package=' . $manifestRegistryVersion . ')', 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'registry_version' => $manifestRegistryVersion, 'dependency_graph_valid' => false];
    }

    $graphPath = $packagePath . DIRECTORY_SEPARATOR . 'dependency_graph.json';
    if (!is_file($graphPath)) {
        return ['ok' => false, 'error' => 'dependency_graph.json missing', 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'registry_version' => $manifestRegistryVersion, 'dependency_graph_valid' => false];
    }
    $packageGraph = json_decode((string) file_get_contents($graphPath), true);
    if (!is_array($packageGraph)) {
        return ['ok' => false, 'error' => 'dependency_graph.json invalid', 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'registry_version' => $manifestRegistryVersion, 'dependency_graph_valid' => false];
    }

    $expectedGraph = orange_country_export_build_dependency_graph($registry);
    $graphError = orange_restore_country_staging_validate_dependency_graph($packageGraph, $expectedGraph);
    if ($graphError !== null) {
        return ['ok' => false, 'error' => $graphError, 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'registry_version' => $manifestRegistryVersion, 'dependency_graph_valid' => false];
    }

    $sqlDir = $packagePath . DIRECTORY_SEPARATOR . 'sql';
    if (!is_dir($sqlDir)) {
        return ['ok' => false, 'error' => 'sql/ directory missing', 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'registry_version' => $manifestRegistryVersion, 'dependency_graph_valid' => true];
    }

    $sqlFiles = glob($sqlDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    if ($sqlFiles === []) {
        return ['ok' => false, 'error' => 'No SQL chunks in package', 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'registry_version' => $manifestRegistryVersion, 'dependency_graph_valid' => true];
    }

    $exportableTables = [];
    foreach (orange_backup_registry_exportable_tables($registry) as $entry) {
        $exportableTables[$entry['table']] = $entry['meta'];
    }

    $preamble = null;
    $postamble = null;
    /** @var array<string, string> $tableFiles */
    $tableFiles = [];
    /** @var array<int, string> $prefixOwners */
    $prefixOwners = [];

    foreach ($sqlFiles as $sqlFile) {
        $parsed = orange_restore_country_staging_parse_sql_chunk($sqlFile);
        if ($parsed === null) {
            return ['ok' => false, 'error' => 'Invalid SQL chunk filename: ' . basename($sqlFile), 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'registry_version' => $manifestRegistryVersion, 'dependency_graph_valid' => true];
        }

        if ($parsed['kind'] === 'preamble') {
            $preamble = $sqlFile;
            continue;
        }
        if ($parsed['kind'] === 'postamble') {
            $postamble = $sqlFile;
            continue;
        }

        $tableName = $parsed['table'];
        $prefixInt = (int) ($parsed['prefix_int'] ?? (int) $parsed['prefix']);
        if (isset($prefixOwners[$prefixInt]) && $prefixOwners[$prefixInt] !== $tableName) {
            return ['ok' => false, 'error' => 'Duplicate SQL chunk numeric prefix: ' . $prefixInt, 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'registry_version' => $manifestRegistryVersion, 'dependency_graph_valid' => true];
        }
        $prefixOwners[$prefixInt] = $tableName;

        if (!isset($exportableTables[$tableName])) {
            return ['ok' => false, 'error' => 'SQL chunk references non-exportable or global table: ' . $tableName, 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'registry_version' => $manifestRegistryVersion, 'dependency_graph_valid' => true];
        }

        $forbidden = orange_restore_country_staging_scan_sql_file_forbidden($sqlFile);
        if ($forbidden !== null) {
            return ['ok' => false, 'error' => $forbidden, 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'registry_version' => $manifestRegistryVersion, 'dependency_graph_valid' => true];
        }

        if (isset($tableFiles[$tableName])) {
            return ['ok' => false, 'error' => 'Duplicate SQL chunk for table: ' . $tableName, 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'registry_version' => $manifestRegistryVersion, 'dependency_graph_valid' => true];
        }
        $tableFiles[$tableName] = $sqlFile;
    }

    $restoreOrderError = orange_restore_country_staging_validate_restore_order($tableFiles, $exportableTables, $packageGraph);
    if ($restoreOrderError !== null) {
        return ['ok' => false, 'error' => $restoreOrderError, 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'registry_version' => $manifestRegistryVersion, 'dependency_graph_valid' => true];
    }

    $sortedTables = array_keys($tableFiles);
    usort($sortedTables, static function (string $a, string $b) use ($exportableTables): int {
        $ao = (int) ($exportableTables[$a]['restore_order'] ?? 0);
        $bo = (int) ($exportableTables[$b]['restore_order'] ?? 0);
        if ($ao === $bo) {
            return strcmp($a, $b);
        }

        return $ao <=> $bo;
    });

    $importFiles = [];
    if ($preamble !== null) {
        $importFiles[] = $preamble;
    }
    foreach ($sortedTables as $tableName) {
        $importFiles[] = $tableFiles[$tableName];
    }
    if ($postamble !== null) {
        $importFiles[] = $postamble;
    }

    $deleteOrderTables = $sortedTables;
    usort($deleteOrderTables, static function (string $a, string $b) use ($exportableTables): int {
        $ao = (int) ($exportableTables[$a]['delete_order'] ?? 0);
        $bo = (int) ($exportableTables[$b]['delete_order'] ?? 0);
        if ($ao === $bo) {
            return strcmp($b, $a);
        }

        return $bo <=> $ao;
    });

    return [
        'ok' => true,
        'error' => null,
        'import_files' => $importFiles,
        'tables' => $sortedTables,
        'delete_order_tables' => $deleteOrderTables,
        'registry_version' => $manifestRegistryVersion,
        'dependency_graph_valid' => true,
    ];
}

/**
 * @param array<string, string> $tableFiles
 * @param array<string, array<string, mixed>> $exportableTables
 * @param array<string, mixed> $packageGraph
 */
function orange_restore_country_staging_validate_restore_order(
    array $tableFiles,
    array $exportableTables,
    array $packageGraph
): ?string {
    $restoreOrders = [];
    foreach ($tableFiles as $tableName => $_path) {
        $restoreOrders[$tableName] = (int) ($exportableTables[$tableName]['restore_order'] ?? 0);
    }

    $edges = is_array($packageGraph['edges'] ?? null) ? $packageGraph['edges'] : [];
    foreach ($edges as $edge) {
        if (!is_array($edge)) {
            continue;
        }
        $child = (string) ($edge['from'] ?? '');
        $parent = (string) ($edge['to'] ?? '');
        if ($child === '' || $parent === '') {
            continue;
        }
        if (!isset($restoreOrders[$child], $restoreOrders[$parent])) {
            continue;
        }
        if ($restoreOrders[$parent] > $restoreOrders[$child]) {
            return 'dependency_graph restore_order violation: parent ' . $parent . ' must restore before child ' . $child;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $packageGraph
 * @param array<string, mixed> $expectedGraph
 */
function orange_restore_country_staging_validate_dependency_graph(array $packageGraph, array $expectedGraph): ?string
{
    $packageNodes = [];
    foreach (is_array($packageGraph['nodes'] ?? null) ? $packageGraph['nodes'] : [] as $node) {
        if (is_array($node) && ($node['table'] ?? '') !== '') {
            $packageNodes[(string) $node['table']] = true;
        }
    }
    $expectedNodes = [];
    foreach (is_array($expectedGraph['nodes'] ?? null) ? $expectedGraph['nodes'] : [] as $node) {
        if (is_array($node) && ($node['table'] ?? '') !== '') {
            $expectedNodes[(string) $node['table']] = true;
        }
    }

    $missingInPackage = array_diff(array_keys($expectedNodes), array_keys($packageNodes));
    if ($missingInPackage !== []) {
        return 'dependency_graph missing registry nodes: ' . implode(', ', array_slice($missingInPackage, 0, 5));
    }

    $extraInPackage = array_diff(array_keys($packageNodes), array_keys($expectedNodes));
    if ($extraInPackage !== []) {
        return 'dependency_graph contains unknown nodes: ' . implode(', ', array_slice($extraInPackage, 0, 5));
    }

    $normalizeEdges = static function (array $graph): array {
        $out = [];
        foreach (is_array($graph['edges'] ?? null) ? $graph['edges'] : [] as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            $fk = (string) ($edge['foreign_key'] ?? '');
            if ($from === '' || $to === '') {
                continue;
            }
            $out[] = $from . '|' . $to . '|' . $fk;
        }
        sort($out);

        return $out;
    };

    if ($normalizeEdges($packageGraph) !== $normalizeEdges($expectedGraph)) {
        return 'dependency_graph edges mismatch with live registry';
    }

    return null;
}

function orange_restore_country_staging_scan_sql_file_forbidden(string $sqlPath): ?string
{
    $content = file_get_contents($sqlPath);
    if ($content === false) {
        return 'Cannot read SQL chunk: ' . basename($sqlPath);
    }
    foreach (ORANGE_COUNTRY_EXPORT_FORBIDDEN_SQL_TABLES as $forbidden) {
        if (preg_match('/INSERT INTO `' . preg_quote($forbidden, '/') . '`/i', $content) === 1) {
            return 'Forbidden global table data in SQL chunk ' . basename($sqlPath) . ': ' . $forbidden;
        }
    }

    return null;
}

/**
 * Clear only CRP tables in registry delete_order before import (staging-only).
 *
 * @param list<string> $tables
 */
function orange_restore_country_staging_clear_tables(PDO $pdo, string $stagingDb, array $tables): void
{
    if ($tables === []) {
        return;
    }

    orange_restore_staging_assert_safe_target($pdo, $stagingDb);
    orange_restore_log('Country staging table clear... START');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $tableName) {
        orange_restore_staging_assert_safe_target($pdo, $stagingDb);
        $quoted = '`' . str_replace('`', '``', $tableName) . '`';
        $pdo->exec('DELETE FROM ' . $quoted);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    orange_restore_staging_assert_safe_target($pdo, $stagingDb);
    orange_restore_log('Country staging table clear... OK (tables=' . (string) count($tables) . ')');
}
