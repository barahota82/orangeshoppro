<?php

declare(strict_types=1);

/**
 * Phase 3B.3B5 — dedicated shadow verification self-test (isolated fixtures + mocks).
 *
 * Never writes to production. Never cutover / maintenance / file restore.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_shadow_verify.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_dry_run.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_pre_restore_backup.php';

$failures = 0;
$passes = 0;

function sv_self_test(bool $ok, string $label): void
{
    global $failures, $passes;
    if ($ok) {
        echo "PASS: {$label}\n";
        $passes++;
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function sv_test_write_zip(string $path, array $files): void
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($files as $name => $body) {
                $zip->addFromString((string) $name, (string) $body);
            }
            $zip->close();

            return;
        }
    }
    file_put_contents($path, 'PK' . str_repeat("\0", 30));
}

function sv_test_seed_package(string $pkgDir, string $pkgId): void
{
    if (!is_dir($pkgDir)) {
        mkdir($pkgDir, 0775, true);
    }
    $dumpRel = 'database.sql.gz';
    $uploadsRel = 'uploads.zip';
    $gz = gzencode("SET NAMES utf8mb4;\nCREATE TABLE t(id INT);\n", 1);
    file_put_contents($pkgDir . DIRECTORY_SEPARATOR . $dumpRel, $gz !== false ? $gz : str_repeat('x', 32));
    sv_test_write_zip($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel, ['a.txt' => 'hello']);
    $dumpSha = hash_file('sha256', $pkgDir . DIRECTORY_SEPARATOR . $dumpRel) ?: '';
    $uploadsSha = hash_file('sha256', $pkgDir . DIRECTORY_SEPARATOR . $uploadsRel) ?: '';
    orange_backup_write_json($pkgDir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => 'full_disaster',
        'package_version' => '1.0.0',
        'generated_at' => gmdate('c'),
        'schema_revision' => ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION,
        'export_backend' => 'php_pdo',
        'backup_status' => 'success',
        'dump_file' => $dumpRel,
        'uploads_file' => $uploadsRel,
        'dump_sha256' => $dumpSha,
        'uploads_sha256' => $uploadsSha,
        'dump_size_bytes' => (int) filesize($pkgDir . DIRECTORY_SEPARATOR . $dumpRel),
        'uploads_size_bytes' => (int) filesize($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel),
        'health_report_file' => 'health.json',
        'checksums_file' => 'checksums.sha256',
        'table_count' => 2,
    ]);
    orange_backup_write_json($pkgDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
    file_put_contents(
        $pkgDir . DIRECTORY_SEPARATOR . 'checksums.sha256',
        $dumpSha . '  ' . $dumpRel . "\n" . $uploadsSha . '  ' . $uploadsRel . "\n"
    );
    orange_backup_write_json(
        orange_backup_admin_recovery_report_sibling_path($pkgDir, $pkgId),
        [
            'overall_result' => 'pass',
            'recovery_score' => 95,
            'validated_at' => gmdate('c'),
            'validation_engine_version' => ORANGE_RECOVERY_VALIDATION_ENGINE_VERSION,
            'manifest_valid' => true,
            'health_valid' => true,
            'checksums_valid' => true,
            'sql_valid' => true,
            'uploads_valid' => true,
        ]
    );
}

/**
 * @return array{job_id:string}
 */
function sv_test_make_shadow_ready_job(string $workRoot, string $backupRoot, string $sourceId): array
{
    $job = orange_restore_fw_create($workRoot, [
        'package_id' => $sourceId,
        'package_type' => 'full_disaster',
        'created_by' => 'superadmin',
        'created_by_admin_id' => 1,
    ]);
    $jobId = (string) $job['job_id'];
    $dry = orange_restore_dry_run_execute($workRoot, $jobId, [
        'backup_root' => $backupRoot,
        'operator_username' => 'superadmin',
    ]);
    $afterDry = orange_restore_fw_read($workRoot, $jobId);
    if ((string) ($afterDry['status'] ?? '') !== ORANGE_RESTORE_FW_STATUS_DRY_COMPLETED) {
        throw new RuntimeException('dry_run_not_completed:' . (string) ($afterDry['status'] ?? ''));
    }
    unset($dry);
    orange_restore_exec_prepare_plan($workRoot, $jobId, [
        'backup_root' => $backupRoot,
        'operator_username' => 'superadmin',
        'operator_admin_id' => 1,
    ]);
    $jobNow = orange_restore_fw_read($workRoot, $jobId);
    $planFp = orange_restore_final_approval_plan_fingerprint($workRoot, $jobId);
    file_put_contents(
        orange_restore_final_approval_record_path($workRoot, $jobId),
        json_encode([
            'approval_version' => ORANGE_RESTORE_FINAL_APPROVAL_VERSION,
            'job_id' => $jobId,
            'package_id' => $sourceId,
            'package_type' => 'full_disaster',
            'approved_by' => 'superadmin',
            'approved_by_admin_id' => 1,
            'approved_at' => gmdate('c'),
            'plan_fingerprint' => $planFp,
            'package_fingerprint' => (string) ($jobNow['package_fingerprint'] ?? ''),
            'dry_run_fingerprint' => (string) ($jobNow['dry_run_fingerprint'] ?? ''),
            'confirmation_phrase_hash' => hash('sha256', 'phrase'),
            'nonce_id_hash' => hash('sha256', 'nonce'),
            'execution_started' => false,
            'cli_invoked' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
    );
    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
        ORANGE_RESTORE_FW_PHASE_APPROVED_WAITING_EXECUTION,
        100,
        'test approved',
        'restore_final_approval_granted'
    );
    $j = orange_restore_fw_read($workRoot, $jobId);
    $j['package_fingerprint'] = (string) ($jobNow['package_fingerprint'] ?? '');
    $j['dry_run_fingerprint'] = (string) ($jobNow['dry_run_fingerprint'] ?? '');
    $j['execution_started'] = false;
    orange_restore_fw_write($workRoot, $j);
    orange_restore_prepare_execution_contract($workRoot, $jobId, $backupRoot);

    orange_restore_pre_backup_write_record($workRoot, $jobId, [
        'record_version' => ORANGE_RESTORE_PRE_BACKUP_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => $sourceId,
        'rollback_package_id' => '2026-07-01_999999',
        'ready_for_rollback' => true,
        'retention_pinned' => true,
        'retention_pin_id' => 'pin_test',
        'execution_started' => false,
        'status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
        'purpose' => ORANGE_RESTORE_PRE_BACKUP_PURPOSE,
    ]);

    $shadowDb = 'orange_shadow_verify_selftest';
    orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), [
        'record_version' => ORANGE_RESTORE_SHADOW_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => $sourceId,
        'shadow_db' => $shadowDb,
        'production_db' => 'orange_db_prod_selftest_mock',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY,
        'ready' => true,
        'verify_result' => 'PASS',
        'execution_started' => false,
        'production_touched' => false,
    ]);
    orange_restore_shadow_write_json(orange_restore_shadow_report_path($workRoot, $jobId), [
        'report_version' => ORANGE_RESTORE_SHADOW_RECORD_VERSION,
        'overall_result' => 'PASS',
        'production_touched' => false,
        'cutover_performed' => false,
    ]);

    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY,
        ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_READY,
        100,
        'test shadow restore ready',
        'shadow_restore_ready'
    );
    $ready = orange_restore_fw_read($workRoot, $jobId);
    $ready['shadow_restore_file'] = ORANGE_RESTORE_SHADOW_META_FILE;
    $ready['shadow_restore_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY;
    $ready['pre_restore_backup_file'] = ORANGE_RESTORE_PRE_BACKUP_FILE;
    $ready['execution_started'] = false;
    orange_restore_fw_write($workRoot, $ready);

    return ['job_id' => $jobId, 'shadow_db' => $shadowDb];
}

function sv_test_install_ready_overrides(string $shadowDb): void
{
    $GLOBALS['orange_shadow_production_db_override'] = 'orange_db_prod_selftest_mock';
    $GLOBALS['orange_shadow_env_override'] = [
        ORANGE_RESTORE_ENV_SHADOW_DB => $shadowDb,
        'ORANGE_RESTORE_STAGING_DB' => $shadowDb,
        'ORANGE_RESTORE_STAGING_DB_USER' => 'shadow_selftest_user',
        'ORANGE_RESTORE_STAGING_DB_PASS' => 'x',
    ];
    $GLOBALS['orange_shadow_verify_connect_override'] = static function (): PDO {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    };
    $GLOBALS['orange_shadow_verify_deep_inventory_override'] = static function () use ($shadowDb): array {
        return [
            'database' => $shadowDb,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'tables' => ['t', 'u'],
            'table_count' => 2,
            'views' => [],
            'view_count' => 0,
            'routines' => [],
            'routine_count' => 0,
            'triggers' => [],
            'trigger_count' => 0,
            'events' => [],
            'event_count' => 0,
            'row_counts' => ['t' => 1, 'u' => 2],
            'total_rows' => 3,
            'schema_revision' => ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION,
            'foreign_keys' => [
                ['constraint' => 'fk1', 'table' => 'u', 'column' => 't_id', 'ref_table' => 't', 'ref_column' => 'id'],
            ],
            'foreign_key_count' => 1,
            'indexes' => [
                ['table' => 't', 'name' => 'PRIMARY', 'unique' => true, 'columns' => 'id'],
                ['table' => 'u', 'name' => 'PRIMARY', 'unique' => true, 'columns' => 'id'],
            ],
            'index_count' => 2,
            'auto_increment' => ['t' => 2, 'u' => 3],
            'table_collations' => ['t' => 'utf8mb4_unicode_ci', 'u' => 'utf8mb4_unicode_ci'],
            'checksums' => ['t' => 111, 'u' => 222],
            'checksum_supported' => true,
            'checksum_errors' => [],
            'orphan_errors' => [],
        ];
    };
    $GLOBALS['orange_shadow_verify_production_schema_override'] = static function (): array {
        return [
            'database' => 'orange_db_prod_selftest_mock',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'tables' => ['t', 'u'],
            'table_count' => 2,
            'views' => [],
            'view_count' => 0,
            'routine_count' => 0,
            'trigger_count' => 0,
            'event_count' => 0,
            'schema_revision' => ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION,
            'foreign_keys' => [
                ['constraint' => 'fk1', 'table' => 'u', 'column' => 't_id', 'ref_table' => 't', 'ref_column' => 'id'],
            ],
            'foreign_key_count' => 1,
            'indexes' => [
                ['table' => 't', 'name' => 'PRIMARY'],
                ['table' => 'u', 'name' => 'PRIMARY'],
            ],
            'index_count' => 2,
            'read_only' => true,
        ];
    };
}

function sv_test_clear_overrides(): void
{
    unset(
        $GLOBALS['orange_shadow_production_db_override'],
        $GLOBALS['orange_shadow_env_override'],
        $GLOBALS['orange_shadow_verify_connect_override'],
        $GLOBALS['orange_shadow_verify_deep_inventory_override'],
        $GLOBALS['orange_shadow_verify_production_schema_override'],
        $GLOBALS['orange_shadow_verify_orphan_override']
    );
}

$configured = '';
try {
    $env = orange_backup_load_env_array($projectRoot);
    $configured = orange_backup_backup_root_candidate($env, $projectRoot);
} catch (Throwable) {
    $configured = '';
}
if ($configured === '' || !is_dir($configured)) {
    echo "SKIP: configured backup root unavailable\n";
    echo "SHADOW_VERIFY_TEST_RESULT: SKIP\n";
    exit(0);
}

$selfBase = rtrim($configured, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . '_self_tests';
if (!is_dir($selfBase)) {
    @mkdir($selfBase, 0775, true);
}
$tmpRoot = $selfBase . DIRECTORY_SEPARATOR . 'shadow_verify_' . bin2hex(random_bytes(4));
$backupRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'backups';
$workRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'restore_work';
foreach ([$tmpRoot, $backupRoot, $workRoot, $backupRoot . DIRECTORY_SEPARATOR . 'snapshots', $backupRoot . DIRECTORY_SEPARATOR . 'locks'] as $d) {
    if (!is_dir($d)) {
        mkdir($d, 0775, true);
    }
}

$cleanup = static function () use ($tmpRoot): void {
    sv_test_clear_overrides();
    $norm = str_replace('\\', '/', $tmpRoot);
    if ($tmpRoot === '' || !str_contains($norm, '/_self_tests/')) {
        return;
    }
    if (!is_dir($tmpRoot)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $path = $file->getPathname();
        $file->isDir() ? @rmdir($path) : @unlink($path);
    }
    @rmdir($tmpRoot);
};
register_shutdown_function($cleanup);

try {
    $sourceId = '2026-07-01_140000';
    sv_test_seed_package($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $sourceId, $sourceId);
    $made = sv_test_make_shadow_ready_job($workRoot, $backupRoot, $sourceId);
    $jobId = $made['job_id'];
    $shadowDb = $made['shadow_db'];

    $early = orange_restore_fw_create($workRoot, [
        'package_id' => '2026-07-01_150000',
        'package_type' => 'full_disaster',
        'created_by' => 'superadmin',
        'created_by_admin_id' => 1,
    ]);
    $earlyRejected = false;
    try {
        orange_restore_shadow_verify_run_cli($projectRoot, $workRoot, $backupRoot, (string) $early['job_id'], 'tester');
    } catch (Throwable $e) {
        $earlyRejected = in_array(trim($e->getMessage()), ['invalid_status', 'shadow_restore_not_ready'], true);
    }
    sv_self_test($earlyRejected, 'CLI rejected before shadow_restore_ready');
    orange_restore_fw_write($workRoot, array_merge(orange_restore_fw_read($workRoot, (string) $early['job_id']), [
        'status' => ORANGE_RESTORE_FW_STATUS_CANCELLED,
        'phase' => ORANGE_RESTORE_FW_PHASE_CANCELLED,
    ]));
    orange_restore_fw_release_lock($workRoot, (string) $early['job_id']);

    $getApi = (string) file_get_contents($projectRoot . '/admin/api/restore/job/shadow-verification.php');
    sv_self_test(
        str_contains($getApi, 'restore_admin_api_require_get')
        && !str_contains($getApi, 'orange_restore_shadow_verify_run_cli'),
        'HTTP status API is GET/read-only'
    );
    sv_self_test(!is_file($projectRoot . '/admin/api/restore/job/request-shadow-verification.php'), 'no HTTP request endpoint for verification');

    $cliSrc = (string) file_get_contents($projectRoot . '/scripts/backup/restore_shadow_verify.php');
    sv_self_test(
        str_contains($cliSrc, "PHP_SAPI !== 'cli'")
        && str_contains($cliSrc, '--job=')
        && !str_contains($cliSrc, 'orange_restore_orchestrator_database_cutover'),
        'CLI-only gate with job id; no cutover'
    );

    sv_test_install_ready_overrides($shadowDb);
    $run = orange_restore_shadow_verify_run_cli($projectRoot, $workRoot, $backupRoot, $jobId, 'tester');
    sv_self_test(($run['ok'] ?? false) === true, 'successful verification with mocks');
    sv_self_test(in_array((string) ($run['result'] ?? ''), ['READY', 'WARNING'], true), 'overall READY or WARNING');
    sv_self_test((int) ($run['readiness_score'] ?? 0) >= ORANGE_RESTORE_SHADOW_VERIFY_SCORE_WARNING, 'readiness score present');
    sv_self_test(($run['production_touched'] ?? true) === false, 'production_touched false');
    sv_self_test(($run['execution_started'] ?? true) === false, 'execution_started false');
    sv_self_test(
        (orange_restore_fw_read($workRoot, $jobId)['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED,
        'stops at shadow_verified'
    );
    sv_self_test(is_file(orange_restore_shadow_verify_report_path($workRoot, $jobId)), 'writes shadow_verification_report.json');

    $report = orange_restore_shadow_verify_load_report($workRoot, $jobId) ?? [];
    sv_self_test(isset($report['package_compare']) && isset($report['production_compare']), 'report has package + production compare');
    sv_self_test(($report['cutover_performed'] ?? true) === false, 'report cutover false');
    sv_self_test(($report['application_switched_to_shadow'] ?? true) === false, 'report no app switch');
    sv_self_test((bool) (($report['production_compare']['read_only_production_scan'] ?? false)) === true, 'prod scan read-only');

    $dup = orange_restore_shadow_verify_run_cli($projectRoot, $workRoot, $backupRoot, $jobId, 'tester');
    sv_self_test(($dup['idempotent'] ?? false) === true && ($dup['ok'] ?? false) === true, 'duplicate CLI idempotent');

    // Force not-ready path via missing production tables.
    $readyJob = orange_restore_fw_read($workRoot, $jobId);
    $readyJob['status'] = ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED;
    $readyJob['phase'] = ORANGE_RESTORE_FW_PHASE_EXECUTION_CANCELLED;
    orange_restore_fw_write($workRoot, $readyJob);
    orange_restore_shadow_verify_release_lock($workRoot, $jobId);
    orange_restore_exec_release_lock($workRoot, $jobId);
    orange_restore_fw_release_lock($workRoot, $jobId);

    $made2 = sv_test_make_shadow_ready_job($workRoot, $backupRoot, $sourceId);
    $job2Id = $made2['job_id'];
    sv_test_install_ready_overrides($shadowDb);
    $GLOBALS['orange_shadow_verify_deep_inventory_override'] = static function () use ($shadowDb): array {
        return [
            'database' => $shadowDb,
            'charset' => 'latin1',
            'collation' => 'latin1_swedish_ci',
            'tables' => [],
            'table_count' => 0,
            'views' => [],
            'view_count' => 0,
            'routines' => [],
            'routine_count' => 0,
            'triggers' => [],
            'trigger_count' => 0,
            'events' => [],
            'event_count' => 0,
            'row_counts' => [],
            'total_rows' => 0,
            'schema_revision' => 0,
            'foreign_keys' => [],
            'foreign_key_count' => 0,
            'indexes' => [],
            'index_count' => 0,
            'auto_increment' => [],
            'table_collations' => [],
            'checksums' => [],
            'checksum_supported' => false,
            'checksum_errors' => ['unsupported'],
            'orphan_errors' => ['Orphan FK in order_items.order_id (3 rows).'],
        ];
    };
    $fail = orange_restore_shadow_verify_run_cli($projectRoot, $workRoot, $backupRoot, $job2Id, 'tester');
    sv_self_test(($fail['ok'] ?? true) === false, 'verification FAIL blocks readiness');
    sv_self_test(
        (orange_restore_fw_read($workRoot, $job2Id)['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_NOT_READY,
        'failed attempt preserves shadow_not_ready'
    );
    sv_self_test(is_file(orange_restore_shadow_verify_report_path($workRoot, $job2Id)), 'failed attempt preserves report');

    sv_test_install_ready_overrides($shadowDb);
    $retry = orange_restore_shadow_verify_run_cli($projectRoot, $workRoot, $backupRoot, $job2Id, 'tester');
    sv_self_test(($retry['ok'] ?? false) === true, 'retry from shadow_not_ready succeeds');
    sv_self_test(
        (orange_restore_fw_read($workRoot, $job2Id)['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED,
        'retry reaches shadow_verified'
    );

    orange_restore_shadow_verify_acquire_lock($workRoot, 'other_job', 'other');
    $lockStatus = orange_restore_shadow_verify_acquire_lock($workRoot, $job2Id, 'tester');
    sv_self_test(($lockStatus['ok'] ?? true) === false, 'lock contention');
    orange_restore_shadow_verify_release_lock($workRoot, 'other_job');

    // Pure evaluate unit checks.
    $evalReady = orange_restore_shadow_verify_evaluate(
        [
            'table_count' => 2,
            'tables' => ['t', 'u'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'schema_revision' => ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION,
            'view_count' => 0,
            'views' => [],
            'routine_count' => 0,
            'trigger_count' => 0,
            'event_count' => 0,
            'foreign_keys' => [],
            'foreign_key_count' => 0,
            'indexes' => [['table' => 't', 'name' => 'PRIMARY']],
            'index_count' => 1,
            'total_rows' => 1,
            'table_collations' => ['t' => 'utf8mb4_unicode_ci'],
            'checksum_supported' => true,
            'checksum_errors' => [],
            'orphan_errors' => [],
        ],
        ['table_count' => 2, 'schema_revision' => ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION],
        [
            'database' => 'prod',
            'tables' => ['t', 'u'],
            'views' => [],
            'foreign_keys' => [],
            'indexes' => [['table' => 't', 'name' => 'PRIMARY']],
            'schema_revision' => ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'routine_count' => 0,
            'trigger_count' => 0,
            'event_count' => 0,
        ],
        ['overall_result' => 'PASS']
    );
    sv_self_test(($evalReady['overall_result'] ?? '') === 'READY', 'evaluate READY when aligned');
    sv_self_test((int) ($evalReady['readiness_score'] ?? 0) >= ORANGE_RESTORE_SHADOW_VERIFY_SCORE_READY, 'evaluate high score');

    $mod = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_shadow_verify.php');
    sv_self_test(
        !str_contains($mod, 'orange_restore_orchestrator_database_cutover(')
        && !str_contains($mod, 'orange_restore_full_staging_run(')
        && !str_contains($mod, 'orange_restore_fw_maint_enable')
        && str_contains($mod, 'shadow_verification_report.json')
        && str_contains($mod, 'readiness_score'),
        'module: report + score; no cutover/staging/maint'
    );

    $ui = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
    sv_self_test(
        str_contains($ui, 'rc-shadow-verify-view')
        && str_contains($ui, 'shadow_verified')
        && !str_contains(strtolower($ui), 'execute restore'),
        'UI view present; no Execute Restore'
    );

    echo 'SHADOW_VERIFY_TEST_RESULT: ' . ($failures === 0 ? 'PASS' : 'FAIL') . "\n";
    echo 'TOTAL_PASS: ' . $passes . "\n";
    echo 'TOTAL_FAIL: ' . $failures . "\n";
    exit($failures > 0 ? 1 : 0);
} catch (Throwable $e) {
    echo 'THROWABLE: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    echo "SHADOW_VERIFY_TEST_RESULT: FAIL\n";
    echo 'TOTAL_PASS: ' . $passes . "\n";
    echo 'TOTAL_FAIL: ' . ($failures + 1) . "\n";
    exit(1);
}
