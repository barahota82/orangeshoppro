<?php

declare(strict_types=1);

/**
 * Phase C6 — Country Shadow Restore.
 *
 * Restores a verified Country Recovery Package into an isolated Country Shadow DB only.
 * Never modifies production. Never enables Country production restore.
 *
 * Gates: C4 Verify PASS + C5 Country DRV PASS + finalized + fingerprint + schema/env.
 */

require_once __DIR__ . '/../backup_paths.php';
require_once __DIR__ . '/../backup_manifest.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../country_boundary_matrix_lib.php';
require_once __DIR__ . '/../country_export.php';
require_once __DIR__ . '/../country_crp_verify.php';
require_once __DIR__ . '/../country_crp_drv.php';
require_once __DIR__ . '/restore_staging_target.php';
require_once __DIR__ . '/restore_sql_runner.php';
require_once __DIR__ . '/restore_country_staging.php';
require_once __DIR__ . '/restore_paths.php';
require_once __DIR__ . '/restore_country_shadow_final_hardening.php';

const ORANGE_COUNTRY_SHADOW_ENGINE_VERSION = '1.3';
const ORANGE_COUNTRY_SHADOW_ENV_DB = 'ORANGE_RESTORE_COUNTRY_SHADOW_DB';
const ORANGE_COUNTRY_SHADOW_DEFAULT_DB = 'orange_country_shadow';
const ORANGE_COUNTRY_SHADOW_REPORT_FILE = 'country_shadow_restore_report.json';
const ORANGE_COUNTRY_SHADOW_META_FILE = 'country_shadow_restore.json';
const ORANGE_COUNTRY_SHADOW_LOCK_FILE = '.country_shadow_restore.lock';

const ORANGE_COUNTRY_SHADOW_STATUS_PENDING = 'country_shadow_restore_pending';
const ORANGE_COUNTRY_SHADOW_STATUS_RUNNING = 'running';
const ORANGE_COUNTRY_SHADOW_STATUS_VERIFYING = 'verifying';
const ORANGE_COUNTRY_SHADOW_STATUS_READY = 'ready';
const ORANGE_COUNTRY_SHADOW_STATUS_FAILED = 'failed';

/**
 * @param array<string, mixed> $env
 */
function orange_country_shadow_db_name(array $env, string $projectRoot): string
{
    $name = trim((string) ($env[ORANGE_COUNTRY_SHADOW_ENV_DB] ?? ''));
    if ($name === '') {
        $name = ORANGE_COUNTRY_SHADOW_DEFAULT_DB;
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('country_shadow_db_name_invalid');
    }
    $productionDb = orange_country_shadow_production_db_name($projectRoot);
    if (strcasecmp($name, $productionDb) === 0) {
        throw new RuntimeException('country_shadow_db_equals_production');
    }

    return $name;
}

function orange_country_shadow_production_db_name(string $projectRoot): string
{
    $prodOverride = orange_country_shadow_override_value('orange_country_shadow_production_db_override');
    if (is_string($prodOverride) && trim($prodOverride) !== '') {
        return trim($prodOverride);
    }

    return orange_restore_production_db_name($projectRoot);
}

function orange_country_shadow_work_root(string $workRoot): string
{
    return rtrim($workRoot, "\\/") . DIRECTORY_SEPARATOR . 'country_shadow';
}

function orange_country_shadow_run_dir(string $workRoot, string $runId): string
{
    orange_country_shadow_assert_run_id($runId);

    return orange_country_shadow_work_root($workRoot) . DIRECTORY_SEPARATOR . $runId;
}

function orange_country_shadow_assert_run_id(string $runId): void
{
    if (!preg_match('/^[a-z0-9]{2}_\d{4}-\d{2}-\d{2}_\d{6}$/', $runId)) {
        throw new RuntimeException('invalid_country_shadow_run_id');
    }
}

function orange_country_shadow_make_run_id(string $countryCode, string $packageId): string
{
    orange_country_drv_assert_country_code($countryCode);
    orange_country_drv_assert_package_id($packageId);

    return strtolower($countryCode) . '_' . $packageId;
}

function orange_country_shadow_assert_not_production(PDO $pdo, string $shadowDb, string $productionDb): void
{
    if (strcasecmp($shadowDb, $productionDb) === 0) {
        throw new RuntimeException('country_shadow_db_equals_production');
    }
    // Fixture/self-test mode: name fence only (no MySQL SESSION DATABASE()).
    if (!empty(orange_country_shadow_override_value('orange_country_shadow_skip_session_assert'))) {
        return;
    }
    $current = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
    if ($current === '' || strcasecmp($current, $shadowDb) !== 0) {
        throw new RuntimeException('country_shadow_session_db_mismatch');
    }
    if (strcasecmp($current, $productionDb) === 0) {
        throw new RuntimeException('country_shadow_connected_to_production');
    }
}

/**
 * @param array<string, mixed> $env
 */
function orange_country_shadow_connect_pdo(string $projectRoot, array $env, string $shadowDb): PDO
{
    $connectOverride = orange_country_shadow_override_callable('orange_country_shadow_connect_override');
    if ($connectOverride !== null) {
        $pdo = $connectOverride($projectRoot, $env, $shadowDb);
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('country_shadow_connect_override_invalid');
        }

        return $pdo;
    }

    $creds = orange_restore_staging_credentials($env, $projectRoot);
    $dsn = 'mysql:host=' . $creds['host'] . ';dbname=' . $shadowDb . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $creds['user'], $creds['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('SET NAMES utf8mb4');
    orange_country_shadow_assert_not_production($pdo, $shadowDb, orange_country_shadow_production_db_name($projectRoot));

    return $pdo;
}

/**
 * @return array{ok:bool,codes:list<string>,manifest:?array<string,mixed>,verify:?array<string,mixed>,drv:?array<string,mixed>}
 */
function orange_country_shadow_entry_check(string $packagePath, string $packageId, string $projectRoot): array
{
    $codes = [];
    if (!orange_backup_retention_is_finalized_dir_name($packageId)
        || str_starts_with(basename($packagePath), '._work_')
        || str_starts_with(basename($packagePath), '.tmp')
    ) {
        $codes[] = 'package_not_finalized';
    }

    $verifyPath = $packagePath . DIRECTORY_SEPARATOR . ORANGE_CRP_VERIFY_REPORT_FILENAME;
    $verify = is_file($verifyPath) ? json_decode((string) file_get_contents($verifyPath), true) : null;
    if (!is_array($verify) || ($verify['report_type'] ?? '') !== 'country_recovery_verify') {
        $codes[] = 'verify_report_missing';
    } elseif (strtoupper((string) ($verify['overall'] ?? '')) !== 'PASS' || !(bool) ($verify['ok'] ?? false)) {
        $codes[] = 'verify_not_pass';
    }

    $drv = orange_country_drv_read_report($packagePath, $packageId);
    if (!is_array($drv) || ($drv['package_type'] ?? '') !== 'country') {
        $codes[] = 'country_drv_report_missing';
    } elseif (($drv['overall_result'] ?? '') !== 'pass'
        || (int) ($drv['recovery_score'] ?? 0) < ORANGE_COUNTRY_DRV_PASS_SCORE_THRESHOLD
    ) {
        $codes[] = 'country_drv_not_pass';
    }

    $manifestPath = $packagePath . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
    if (!is_array($manifest) || ($manifest['package_type'] ?? '') !== 'country_recovery') {
        $codes[] = 'manifest_invalid';

        return ['ok' => false, 'codes' => array_values(array_unique($codes)), 'manifest' => null, 'verify' => is_array($verify) ? $verify : null, 'drv' => $drv];
    }

    $storedFp = trim((string) ($manifest['package_fingerprint'] ?? ''));
    $verifyFp = is_array($verify) ? trim((string) ($verify['package_fingerprint'] ?? '')) : '';
    $computedFp = orange_crp_export_package_fingerprint($packagePath, $manifest);
    if ($storedFp === '' || $verifyFp === '' || !hash_equals($storedFp, $verifyFp) || !hash_equals($storedFp, $computedFp)) {
        $codes[] = 'fingerprint_changed';
    }

    if (($manifest['boundary_policy_version'] ?? '') !== ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION
        || ($manifest['dependency_graph_version'] ?? '') !== ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION
    ) {
        $codes[] = 'boundary_or_dependency_version_incompatible';
    }

    try {
        $matrix = orange_country_boundary_matrix_load($projectRoot);
        $expectedSchema = (int) ($matrix['schema_revision'] ?? (defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION') ? ORANGE_CATALOG_SCHEMA_PHP_REVISION : 122));
    } catch (Throwable) {
        $expectedSchema = defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION') ? (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION : 122;
        $codes[] = 'boundary_matrix_unreadable';
    }
    if ((int) ($manifest['schema_revision'] ?? 0) !== $expectedSchema) {
        $codes[] = 'schema_incompatible';
    }

    $backend = (string) ($manifest['export_backend'] ?? '');
    if ($backend !== '' && $backend !== ORANGE_COUNTRY_EXPORT_BACKEND) {
        $codes[] = 'environment_incompatible';
    }
    if (!function_exists('gzopen') || !class_exists('ZipArchive')) {
        $codes[] = 'environment_incompatible';
    }

    if (ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED) {
        $codes[] = 'country_restore_unexpectedly_enabled';
    }

    return [
        'ok' => $codes === [],
        'codes' => array_values(array_unique($codes)),
        'manifest' => $manifest,
        'verify' => is_array($verify) ? $verify : null,
        'drv' => $drv,
    ];
}

/**
 * Build import plan ordered by Country Dependency Graph restore batches 1→6.
 *
 * @param array<string, mixed> $manifest
 * @return array{
 *   ok:bool,
 *   error:?string,
 *   import_files:list<string>,
 *   tables:list<string>,
 *   delete_order_tables:list<string>,
 *   restore_batches:array<int, list<string>>
 * }
 */
function orange_country_shadow_build_import_plan(
    string $projectRoot,
    string $packagePath,
    array $manifest
): array {
    try {
        $matrix = orange_country_boundary_matrix_load($projectRoot);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'boundary_matrix_unreadable', 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'restore_batches' => []];
    }
    $batches = orange_country_boundary_matrix_restore_batches($matrix);
    /** @var array<string, array<string, mixed>> $matrixTables */
    $matrixTables = is_array($matrix['tables'] ?? null) ? $matrix['tables'] : [];

    $sqlDir = $packagePath . DIRECTORY_SEPARATOR . 'sql';
    if (!is_dir($sqlDir)) {
        return ['ok' => false, 'error' => 'sql_directory_missing', 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'restore_batches' => $batches];
    }
    $sqlFiles = glob($sqlDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    if ($sqlFiles === []) {
        return ['ok' => false, 'error' => 'sql_chunks_missing', 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'restore_batches' => $batches];
    }

    /** @var array<string, string> $tableFiles */
    $tableFiles = [];
    foreach ($sqlFiles as $sqlFile) {
        $parsed = orange_restore_country_staging_parse_sql_chunk($sqlFile);
        if ($parsed === null) {
            return ['ok' => false, 'error' => 'invalid_sql_chunk_name', 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'restore_batches' => $batches];
        }
        if (in_array($parsed['kind'], ['preamble', 'postamble'], true)) {
            continue;
        }
        $table = $parsed['table'];
        if (in_array($table, ORANGE_CRP_NEVER_EXPORT_TABLES, true)) {
            return ['ok' => false, 'error' => 'never_export_table_in_sql', 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'restore_batches' => $batches];
        }
        if (!isset($matrixTables[$table]) || !(bool) ($matrixTables[$table]['exportable'] ?? false)) {
            return ['ok' => false, 'error' => 'non_mutate_table_in_sql', 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'restore_batches' => $batches];
        }
        $forbidden = orange_restore_country_staging_scan_sql_file_forbidden($sqlFile);
        if ($forbidden !== null) {
            return ['ok' => false, 'error' => 'forbidden_sql_present', 'import_files' => [], 'tables' => [], 'delete_order_tables' => [], 'restore_batches' => $batches];
        }
        $tableFiles[$table] = $sqlFile;
    }

    $ordered = [];
    for ($b = 1; $b <= 6; $b++) {
        $batchTables = $batches[$b] ?? [];
        sort($batchTables);
        foreach ($batchTables as $table) {
            if (isset($tableFiles[$table])) {
                $ordered[] = $table;
            }
        }
    }
    // Any remaining exportable chunks (should be none)
    foreach (array_keys($tableFiles) as $table) {
        if (!in_array($table, $ordered, true)) {
            $ordered[] = $table;
        }
    }

    $importFiles = [];
    foreach ($ordered as $table) {
        $importFiles[] = $tableFiles[$table];
    }

    $deleteOrder = [];
    for ($b = 6; $b >= 1; $b--) {
        $batchTables = $batches[$b] ?? [];
        rsort($batchTables);
        foreach ($batchTables as $table) {
            if (isset($tableFiles[$table])) {
                $deleteOrder[] = $table;
            }
        }
    }

    return [
        'ok' => true,
        'error' => null,
        'import_files' => $importFiles,
        'tables' => $ordered,
        'delete_order_tables' => $deleteOrder,
        'restore_batches' => $batches,
    ];
}

/**
 * Capture survivor-country + Global baselines before target-slice clear (F-01).
 * Shadow model (F-02): seeded multi-country; never synthetic empty seed as "proof".
 *
 * @return array{survivor:array<string,mixed>,global:array<string,mixed>,capture_mode:string}
 */
function orange_country_shadow_write_pre_restore_baselines(
    PDO $pdo,
    string $runDir,
    int $countryId,
    string $projectRoot = ''
): array {
    if ($projectRoot === '') {
        $projectRoot = dirname(__DIR__, 3);
    }

    return orange_country_shadow_write_live_baselines($pdo, $runDir, $countryId, $projectRoot);
}

/**
 * Target-slice clear only (F-02). Full-table DELETE of mutate tables is forbidden.
 *
 * @param list<string> $tables
 * @param array<string, mixed>|null $matrix
 */
function orange_country_shadow_clear_tables(
    PDO $pdo,
    string $shadowDb,
    string $productionDb,
    array $tables,
    int $countryId = 0,
    ?array $matrix = null,
    string $projectRoot = ''
): void {
    if ($projectRoot === '') {
        $projectRoot = dirname(__DIR__, 3);
    }
    if ($matrix === null) {
        $matrix = orange_country_boundary_matrix_load($projectRoot);
    }
    if ($countryId <= 0) {
        throw new RuntimeException('country_shadow_clear_requires_country_id');
    }
    orange_country_shadow_clear_target_slice(
        $pdo,
        $shadowDb,
        $productionDb,
        $tables,
        $countryId,
        $matrix,
        $projectRoot
    );
}

/**
 * @param list<string> $importFiles
 * @return array{ok:bool,files_imported:int,statements_executed:int,error:?string}
 */
function orange_country_shadow_import_sql(
    PDO $pdo,
    array $importFiles,
    string $shadowDb,
    string $productionDb
): array {
    $importOverride = orange_country_shadow_override_callable('orange_country_shadow_import_override');
    if ($importOverride !== null) {
        $result = $importOverride($pdo, $importFiles, $shadowDb, $productionDb);

        return is_array($result) ? $result : ['ok' => false, 'files_imported' => 0, 'statements_executed' => 0, 'error' => 'import_override_invalid'];
    }
    orange_country_shadow_assert_not_production($pdo, $shadowDb, $productionDb);
    $result = orange_restore_sql_runner_import_country_chunks($pdo, $importFiles, $shadowDb, $productionDb);
    orange_country_shadow_assert_not_production($pdo, $shadowDb, $productionDb);

    return [
        'ok' => (bool) ($result['ok'] ?? false),
        'files_imported' => (int) ($result['files_imported'] ?? 0),
        'statements_executed' => (int) ($result['statements_executed'] ?? 0),
        'error' => $result['error'] ?? null,
    ];
}

/**
 * Post-restore verification in shadow DB.
 *
 * @param array<string, mixed> $manifest
 * @param array<string, mixed> $importPlan
 * @return array{ok:bool,codes:list<string>,checks:list<array<string,mixed>>,row_counts:array<string,int>}
 */
function orange_country_shadow_verify(
    PDO $pdo,
    string $shadowDb,
    string $productionDb,
    int $countryId,
    array $manifest,
    array $importPlan,
    string $packagePath,
    string $projectRoot = '',
    string $runDir = ''
): array {
    $verifyOverride = orange_country_shadow_override_callable('orange_country_shadow_verify_override');
    if ($verifyOverride !== null) {
        $result = $verifyOverride($pdo, $shadowDb, $countryId, $manifest, $importPlan, $packagePath);

        return is_array($result) ? $result : ['ok' => false, 'codes' => ['verify_override_invalid'], 'checks' => [], 'row_counts' => []];
    }
    if ($projectRoot === '') {
        $projectRoot = dirname(__DIR__, 3);
    }

    // EA-01: target-slice verify for seeded_multicountry_target_slice
    return orange_country_shadow_verify_target_slice(
        $pdo,
        $shadowDb,
        $productionDb,
        $countryId,
        $manifest,
        $importPlan,
        $packagePath,
        $projectRoot,
        $runDir
    );
}

/**
 * @param array{
 *   project_root:string,
 *   backup_root:string,
 *   work_root:string,
 *   package_id:string,
 *   country_code?:string,
 *   env?:array<string,mixed>
 * } $options
 * @return array<string, mixed>
 */
function orange_country_shadow_run(array $options): array
{
    $projectRoot = (string) ($options['project_root'] ?? '');
    $backupRoot = (string) ($options['backup_root'] ?? '');
    $workRoot = (string) ($options['work_root'] ?? '');
    $packageId = (string) ($options['package_id'] ?? '');
    $countryCode = trim((string) ($options['country_code'] ?? ''));

    if ($projectRoot === '' || $backupRoot === '' || $workRoot === '' || $packageId === '') {
        throw new InvalidArgumentException('project_root, backup_root, work_root, package_id required');
    }

    $env = orange_backup_load_env_array($projectRoot);
    if (is_array($options['env'] ?? null)) {
        $env = array_merge($env, $options['env']);
    }
    $envOverride = orange_country_shadow_override_value('orange_country_shadow_env_override');
    if (is_array($envOverride)) {
        $env = array_merge($env, $envOverride);
    }

    $resolved = orange_country_drv_resolve_package_id(
        $backupRoot,
        $packageId,
        $countryCode !== '' ? $countryCode : null
    );
    $packagePath = $resolved['package_path'];
    $countryCode = $resolved['country_code'];
    $runId = orange_country_shadow_make_run_id($countryCode, $packageId);
    $runDir = orange_country_shadow_run_dir($workRoot, $runId);
    if (!is_dir($runDir) && !@mkdir($runDir, 0775, true) && !is_dir($runDir)) {
        throw new RuntimeException('cannot_create_country_shadow_run_dir');
    }

    $productionDb = orange_country_shadow_production_db_name($projectRoot);
    $shadowDb = orange_country_shadow_db_name($env, $projectRoot);
    $startedAt = gmdate('c');

    $meta = [
        'engine_version' => ORANGE_COUNTRY_SHADOW_ENGINE_VERSION,
        'run_id' => $runId,
        'package_id' => $packageId,
        'country_code' => $countryCode,
        'status' => ORANGE_COUNTRY_SHADOW_STATUS_PENDING,
        'shadow_db' => $shadowDb,
        'production_db' => $productionDb,
        'created_at' => $startedAt,
        'production_touched' => false,
        'country_production_restore_enabled' => false,
        'execution_performed' => false,
    ];
    orange_backup_write_json($runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_META_FILE, $meta);

    $fail = static function (string $code, array $extra = []) use (&$meta, $runDir, $startedAt, $runId, $packageId, $countryCode, $shadowDb, $productionDb): array {
        $meta['status'] = ORANGE_COUNTRY_SHADOW_STATUS_FAILED;
        $meta['failure_code'] = $code;
        $meta['failed_at'] = gmdate('c');
        orange_backup_write_json($runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_META_FILE, $meta);
        $report = array_merge([
            'report_type' => 'country_shadow_restore',
            'engine_version' => ORANGE_COUNTRY_SHADOW_ENGINE_VERSION,
            'validated_at' => gmdate('c'),
            'started_at' => $startedAt,
            'finished_at' => gmdate('c'),
            'run_id' => $runId,
            'package_id' => $packageId,
            'country_code' => $countryCode,
            'country_id' => (int) ($extra['country_id'] ?? 0),
            'shadow_db' => $shadowDb,
            'status' => ORANGE_COUNTRY_SHADOW_STATUS_FAILED,
            'overall_result' => 'fail',
            'failure_code' => $code,
            'blocking_reason_codes' => $extra['codes'] ?? [$code],
            'checks' => $extra['checks'] ?? [],
            'production_touched' => false,
            'execution_performed' => false,
            'country_production_restore_enabled' => false,
        ], $extra['report'] ?? []);
        unset($report['package_path'], $report['absolute_paths']);
        $reportPath = $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_REPORT_FILE;
        orange_backup_write_json($reportPath, $report);

        return [
            'ok' => false,
            'status' => ORANGE_COUNTRY_SHADOW_STATUS_FAILED,
            'run_id' => $runId,
            'code' => $code,
            'report_path' => $reportPath,
            'report' => $report,
            'shadow_db' => $shadowDb,
            'production_touched' => false,
        ];
    };

    $entry = orange_country_shadow_entry_check($packagePath, $packageId, $projectRoot);
    if (!$entry['ok']) {
        return $fail('entry_rejected', ['codes' => $entry['codes']]);
    }
    /** @var array<string, mixed> $manifest */
    $manifest = $entry['manifest'];
    $countryId = (int) ($manifest['country_id'] ?? 0);

    $importPlan = orange_country_shadow_build_import_plan($projectRoot, $packagePath, $manifest);
    if (!$importPlan['ok']) {
        return $fail((string) ($importPlan['error'] ?? 'import_plan_failed'), ['country_id' => $countryId]);
    }

    $meta['status'] = ORANGE_COUNTRY_SHADOW_STATUS_RUNNING;
    $meta['country_id'] = $countryId;
    $meta['shadow_model'] = ORANGE_COUNTRY_SHADOW_MODEL;
    orange_backup_write_json($runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_META_FILE, $meta);

    $lock = orange_country_shadow_acquire_lock($workRoot, $runId, $shadowDb);
    if (!$lock['ok']) {
        return $fail((string) ($lock['code'] ?? 'country_shadow_lock_held'), [
            'country_id' => $countryId,
            'codes' => [(string) ($lock['code'] ?? 'country_shadow_lock_held')],
        ]);
    }

    try {
        $pdo = orange_country_shadow_connect_pdo($projectRoot, $env, $shadowDb);
        orange_country_shadow_assert_not_production($pdo, $shadowDb, $productionDb);
        // F-01: live survivor/global baselines before target-slice clear.
        orange_country_shadow_write_pre_restore_baselines($pdo, $runDir, $countryId, $projectRoot);
        orange_country_shadow_assert_not_production($pdo, $shadowDb, $productionDb);
        // F-02: country-scoped target-slice clear only.
        $matrix = orange_country_boundary_matrix_load($projectRoot);
        orange_country_shadow_clear_tables(
            $pdo,
            $shadowDb,
            $productionDb,
            $importPlan['delete_order_tables'],
            $countryId,
            $matrix,
            $projectRoot
        );
        orange_country_shadow_assert_not_production($pdo, $shadowDb, $productionDb);
        $importResult = orange_country_shadow_import_sql($pdo, $importPlan['import_files'], $shadowDb, $productionDb);
        if (!$importResult['ok']) {
            return $fail('sql_import_failed', [
                'country_id' => $countryId,
                'codes' => ['sql_import_failed'],
                'report' => ['import' => $importResult],
            ]);
        }

        $meta['status'] = ORANGE_COUNTRY_SHADOW_STATUS_VERIFYING;
        orange_backup_write_json($runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_META_FILE, $meta);

        orange_country_shadow_assert_not_production($pdo, $shadowDb, $productionDb);
        $verify = orange_country_shadow_verify(
            $pdo,
            $shadowDb,
            $productionDb,
            $countryId,
            $manifest,
            $importPlan,
            $packagePath,
            $projectRoot,
            $runDir
        );
        if (!$verify['ok']) {
            return $fail('shadow_verify_failed', [
                'country_id' => $countryId,
                'codes' => $verify['codes'],
                'checks' => $verify['checks'],
                'report' => ['row_counts' => $verify['row_counts']],
            ]);
        }

        $meta['status'] = ORANGE_COUNTRY_SHADOW_STATUS_READY;
        $meta['ready'] = true;
        $meta['finished_at'] = gmdate('c');
        $meta['statements_executed'] = (int) ($importResult['statements_executed'] ?? 0);
        orange_backup_write_json($runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_META_FILE, $meta);

        $report = [
            'report_type' => 'country_shadow_restore',
            'engine_version' => ORANGE_COUNTRY_SHADOW_ENGINE_VERSION,
            'validated_at' => gmdate('c'),
            'started_at' => $startedAt,
            'finished_at' => gmdate('c'),
            'run_id' => $runId,
            'package_id' => $packageId,
            'country_code' => $countryCode,
            'country_id' => $countryId,
            'schema_revision' => (int) ($manifest['schema_revision'] ?? 0),
            'boundary_policy_version' => ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
            'dependency_graph_version' => ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
            'verify_engine_version' => ORANGE_CRP_VERIFY_ENGINE_VERSION,
            'drv_engine_version' => ORANGE_COUNTRY_DRV_ENGINE_VERSION,
            'shadow_db' => $shadowDb,
            'shadow_model' => ORANGE_COUNTRY_SHADOW_MODEL,
            'status' => ORANGE_COUNTRY_SHADOW_STATUS_READY,
            'overall_result' => 'pass',
            'tables_restored' => count($importPlan['tables']),
            'files_imported' => (int) ($importResult['files_imported'] ?? 0),
            'statements_executed' => (int) ($importResult['statements_executed'] ?? 0),
            'row_counts' => $verify['row_counts'],
            'checks' => $verify['checks'],
            'blocking_reason_codes' => [],
            'production_touched' => false,
            'execution_performed' => false,
            'country_production_restore_enabled' => false,
            'restore_batches_applied' => array_keys($importPlan['restore_batches']),
        ];
        $reportPath = $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_REPORT_FILE;
        orange_backup_write_json($reportPath, $report);

        return [
            'ok' => true,
            'status' => ORANGE_COUNTRY_SHADOW_STATUS_READY,
            'run_id' => $runId,
            'report_path' => $reportPath,
            'report' => $report,
            'shadow_db' => $shadowDb,
            'production_touched' => false,
        ];
    } catch (Throwable $e) {
        $code = trim($e->getMessage());
        if ($code === '' || strlen($code) > 80 || str_contains($code, ' ')) {
            $code = 'country_shadow_restore_failed';
        }

        return $fail($code, ['country_id' => $countryId, 'codes' => [$code]]);
    } finally {
        orange_country_shadow_release_lock($workRoot, $runId);
    }
}

/**
 * Read-only status for HTTP GET.
 *
 * @return array<string, mixed>
 */
function orange_country_shadow_status(string $workRoot, string $runId): array
{
    orange_country_shadow_assert_run_id($runId);
    $runDir = orange_country_shadow_run_dir($workRoot, $runId);
    $metaPath = $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_META_FILE;
    $reportPath = $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_REPORT_FILE;
    $meta = is_file($metaPath) ? json_decode((string) file_get_contents($metaPath), true) : null;
    $report = is_file($reportPath) ? json_decode((string) file_get_contents($reportPath), true) : null;
    if (!is_array($meta)) {
        throw new RuntimeException('country_shadow_run_not_found');
    }
    unset($meta['package_path'], $meta['absolute_paths']);
    if (is_array($report)) {
        unset($report['package_path'], $report['absolute_paths']);
    }

    return [
        'run_id' => $runId,
        'status' => (string) ($meta['status'] ?? ''),
        'meta' => $meta,
        'report' => is_array($report) ? $report : null,
        'read_only' => true,
        'execution_started' => false,
        'production_touched' => false,
        'country_production_restore_enabled' => false,
    ];
}

require_once __DIR__ . '/restore_country_shadow_integrity.php';
