<?php

declare(strict_types=1);

/**
 * Phase 3B.3B4 — dedicated shadow DB restore self-test (isolated fixtures + mocks).
 *
 * Never writes to production. Never enables maintenance. Never cutover / file restore.
 * Uses test overrides for ensure/connect/wipe/import/inventory only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_shadow_db.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_dry_run.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_pre_restore_backup.php';

$failures = 0;
$passes = 0;

function shadow_self_test(bool $ok, string $label): void
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

function shadow_test_write_zip(string $path, array $files): void
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

    $local = '';
    $central = '';
    $offset = 0;
    $count = 0;
    foreach ($files as $name => $body) {
        $name = (string) $name;
        $body = (string) $body;
        $nameLen = strlen($name);
        $size = strlen($body);
        $crc = crc32($body);
        if ($crc < 0) {
            $crc = $crc & 0xFFFFFFFF;
        }
        $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLen, 0)
            . $name . $body;
        $centralHeader = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLen, 0, 0, 0, 0, 0, $offset)
            . $name;
        $local .= $localHeader;
        $central .= $centralHeader;
        $offset += strlen($localHeader);
        $count++;
    }
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($local), 0);
    file_put_contents($path, $local . $central . $end);
}

function shadow_test_seed_package(string $pkgDir, string $pkgId): void
{
    if (!is_dir($pkgDir)) {
        mkdir($pkgDir, 0775, true);
    }
    $dumpRel = 'database.sql.gz';
    $uploadsRel = 'uploads.zip';
    $gz = gzencode(
        "SET NAMES utf8mb4;\nCREATE TABLE t(id INT);\nINSERT INTO t VALUES (1);\n",
        1
    );
    file_put_contents($pkgDir . DIRECTORY_SEPARATOR . $dumpRel, $gz !== false ? $gz : str_repeat('x', 32));
    shadow_test_write_zip($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel, ['a.txt' => 'hello']);
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
        'table_count' => 1,
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
function shadow_test_make_approved_job(string $workRoot, string $backupRoot, string $sourceId): array
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
        $overall = (string) (($dry['report']['overall_result'] ?? $afterDry['dry_run_overall_result'] ?? ''));
        throw new RuntimeException('dry_run_not_completed:' . (string) ($afterDry['status'] ?? '') . ':' . $overall);
    }
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

    return ['job_id' => $jobId];
}

function shadow_test_mark_pre_backup_ready(string $workRoot, string $jobId, string $sourceId): void
{
    orange_restore_pre_backup_write_record($workRoot, $jobId, [
        'record_version' => ORANGE_RESTORE_PRE_BACKUP_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => $sourceId,
        'rollback_package_id' => '2026-07-01_999999',
        'rollback_package_type' => 'full',
        'created_at' => gmdate('c'),
        'created_by' => 'superadmin',
        'ready_for_rollback' => true,
        'retention_pinned' => true,
        'retention_pin_id' => 'pin_test',
        'execution_started' => false,
        'status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
        'verify_result' => 'PASS',
        'drv_result' => 'pass',
        'purpose' => ORANGE_RESTORE_PRE_BACKUP_PURPOSE,
    ]);
    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
        ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_READY,
        100,
        'test pre-restore backup ready',
        'pre_restore_backup_ready'
    );
    $j = orange_restore_fw_read($workRoot, $jobId);
    $j['pre_restore_backup_file'] = ORANGE_RESTORE_PRE_BACKUP_FILE;
    $j['pre_restore_backup_status'] = ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY;
    $j['execution_started'] = false;
    orange_restore_fw_write($workRoot, $j);
}

function shadow_test_install_success_overrides(string $shadowDb): void
{
    $GLOBALS['orange_shadow_production_db_override'] = 'orange_db_prod_selftest_mock';
    $GLOBALS['orange_shadow_env_override'] = [
        ORANGE_RESTORE_ENV_SHADOW_DB => $shadowDb,
        'ORANGE_RESTORE_STAGING_DB' => $shadowDb,
        'ORANGE_RESTORE_STAGING_DB_USER' => 'shadow_selftest_user',
        'ORANGE_RESTORE_STAGING_DB_PASS' => 'shadow_selftest_pass',
    ];
    $GLOBALS['orange_shadow_ensure_override'] = static function (
        string $projectRoot,
        array $env,
        string $db
    ) use ($shadowDb): array {
        unset($projectRoot, $env);

        return [
            'ok' => true,
            'created' => true,
            'shadow_db' => $db !== '' ? $db : $shadowDb,
            'message' => 'mock_created',
        ];
    };
    $GLOBALS['orange_shadow_connect_override'] = static function () use ($shadowDb): PDO {
        // Isolated in-memory PDO — never touches MySQL production.
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Satisfy SELECT DATABASE() checks used by staging safety in non-override wipe path.
        // Wipe/import/inventory are also overridden below.
        return $pdo;
    };
    $GLOBALS['orange_shadow_wipe_override'] = static function (): void {
        // no-op
    };
    $GLOBALS['orange_shadow_import_override'] = static function (): array {
        return [
            'ok' => true,
            'statements_executed' => 2,
            'bytes_read' => 64,
        ];
    };
    $GLOBALS['orange_shadow_inventory_override'] = static function () use ($shadowDb): array {
        return [
            'database' => $shadowDb,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'tables' => ['t'],
            'table_count' => 1,
            'views' => [],
            'view_count' => 0,
            'routines' => [],
            'routine_count' => 0,
            'triggers' => [],
            'trigger_count' => 0,
            'events' => [],
            'event_count' => 0,
            'row_counts' => ['t' => 1],
            'total_rows' => 1,
            'schema_revision' => ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION,
        ];
    };
    $GLOBALS['orange_shadow_production_inventory_override'] = static function (): array {
        return [
            'database' => 'orange_db_prod_readonly_mock',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'tables' => ['t', 'other'],
            'table_count' => 2,
            'views' => [],
            'view_count' => 0,
            'routines' => [],
            'routine_count' => 0,
            'triggers' => [],
            'trigger_count' => 0,
            'events' => [],
            'event_count' => 0,
            'schema_revision' => ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION,
            'read_only' => true,
        ];
    };
}

function shadow_test_clear_overrides(): void
{
    unset(
        $GLOBALS['orange_shadow_production_db_override'],
        $GLOBALS['orange_shadow_env_override'],
        $GLOBALS['orange_shadow_ensure_override'],
        $GLOBALS['orange_shadow_connect_override'],
        $GLOBALS['orange_shadow_wipe_override'],
        $GLOBALS['orange_shadow_import_override'],
        $GLOBALS['orange_shadow_inventory_override'],
        $GLOBALS['orange_shadow_production_inventory_override']
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
    echo "SHADOW_RESTORE_TEST_RESULT: SKIP\n";
    exit(0);
}

$selfBase = rtrim($configured, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . '_self_tests';
if (!is_dir($selfBase)) {
    @mkdir($selfBase, 0775, true);
}
$tmpRoot = $selfBase . DIRECTORY_SEPARATOR . 'shadow_restore_' . bin2hex(random_bytes(4));
$backupRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'backups';
$workRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'restore_work';
foreach ([$tmpRoot, $backupRoot, $workRoot, $backupRoot . DIRECTORY_SEPARATOR . 'snapshots', $backupRoot . DIRECTORY_SEPARATOR . 'locks'] as $d) {
    if (!is_dir($d)) {
        mkdir($d, 0775, true);
    }
}

$cleanup = static function () use ($tmpRoot): void {
    shadow_test_clear_overrides();
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
    $sourceId = '2026-07-01_120000';
    shadow_test_seed_package($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $sourceId, $sourceId);

    $made = shadow_test_make_approved_job($workRoot, $backupRoot, $sourceId);
    $jobId = $made['job_id'];

    // Reject before pre-restore backup ready.
    $rejectedEarly = false;
    try {
        orange_restore_shadow_request($workRoot, $jobId, $backupRoot, ['username' => 'superadmin']);
    } catch (Throwable $e) {
        $rejectedEarly = in_array(trim($e->getMessage()), [
            'invalid_status',
            'pre_restore_backup_not_ready',
        ], true);
    }
    shadow_self_test($rejectedEarly, 'request rejected before pre_restore_backup_ready');

    shadow_test_mark_pre_backup_ready($workRoot, $jobId, $sourceId);

    $countryJob = orange_restore_fw_create($workRoot, [
        'package_id' => '2026-07-01_130000',
        'package_type' => 'country_recovery',
        'country_code' => 'KW',
        'created_by' => 'superadmin',
        'created_by_admin_id' => 1,
    ]);
    // Force status so revalidate package-type path is hit (country never reaches ready via Full gate).
    orange_restore_fw_write($workRoot, array_merge(orange_restore_fw_read($workRoot, (string) $countryJob['job_id']), [
        'status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
        'phase' => ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_READY,
    ]));
    $countryRejected = false;
    try {
        orange_restore_shadow_request($workRoot, (string) $countryJob['job_id'], $backupRoot, ['username' => 'superadmin']);
    } catch (Throwable $e) {
        $countryRejected = trim($e->getMessage()) === 'country_production_restore_not_enabled';
    }
    shadow_self_test($countryRejected, 'Country job rejected for shadow restore');
    orange_restore_fw_write($workRoot, array_merge(orange_restore_fw_read($workRoot, (string) $countryJob['job_id']), [
        'status' => ORANGE_RESTORE_FW_STATUS_CANCELLED,
        'phase' => ORANGE_RESTORE_FW_PHASE_CANCELLED,
    ]));
    orange_restore_fw_release_lock($workRoot, (string) $countryJob['job_id']);

    $req = orange_restore_shadow_request($workRoot, $jobId, $backupRoot, ['username' => 'superadmin', 'id' => 1]);
    shadow_self_test(($req['cli_needed'] ?? false) === true, 'ready Full job can request shadow restore');
    shadow_self_test(
        ($req['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        'request moves to shadow_restore_pending'
    );
    shadow_self_test(($req['execution_started'] ?? true) === false, 'request keeps execution_started false');
    shadow_self_test(($req['meta']['production_touched'] ?? true) === false, 'request meta production_touched false');

    $req2 = orange_restore_shadow_request($workRoot, $jobId, $backupRoot, ['username' => 'superadmin', 'id' => 1]);
    shadow_self_test(($req2['idempotent'] ?? false) === true, 'duplicate request is idempotent');

    $apiSrc = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'request-shadow-restore.php');
    shadow_self_test(
        !str_contains($apiSrc, 'orange_restore_shadow_run_cli')
        && !str_contains($apiSrc, 'orange_restore_sql_runner_import_gzip')
        && str_contains($apiSrc, 'restore_admin_api_require_csrf'),
        'HTTP request does not run shadow import'
    );
    $getApi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'shadow-restore.php');
    shadow_self_test(
        str_contains($getApi, 'restore_admin_api_require_get')
        && !str_contains($getApi, 'orange_restore_shadow_run_cli'),
        'HTTP status API is GET/read-only'
    );

    $cliSrc = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_shadow_db.php');
    shadow_self_test(
        str_contains($cliSrc, "PHP_SAPI !== 'cli'")
        && str_contains($cliSrc, '--job=')
        && !str_contains($cliSrc, 'orange_restore_orchestrator_database_cutover'),
        'CLI-only gate with job id only; no cutover'
    );

    $shadowDb = 'orange_shadow_selftest_' . bin2hex(random_bytes(3));
    shadow_test_install_success_overrides($shadowDb);

    $run = orange_restore_shadow_run_cli($projectRoot, $workRoot, $backupRoot, $jobId, 'tester');
    shadow_self_test(
        ($run['ok'] ?? false) === true,
        'successful shadow restore with mocks'
            . (empty($run['ok']) ? (' code=' . (string) ($run['code'] ?? '')) : '')
    );
    shadow_self_test(($run['production_touched'] ?? true) === false, 'CLI result production_touched false');
    shadow_self_test(($run['execution_started'] ?? true) === false, 'CLI result execution_started false');
    shadow_self_test(
        (orange_restore_fw_read($workRoot, $jobId)['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY,
        'stops at shadow_restore_ready'
    );
    shadow_self_test(is_file(orange_restore_shadow_report_path($workRoot, $jobId)), 'writes shadow_restore_report.json');

    $report = orange_restore_shadow_load_report($workRoot, $jobId) ?? [];
    shadow_self_test(($report['overall_result'] ?? '') === 'PASS', 'report overall PASS');
    shadow_self_test(($report['cutover_performed'] ?? true) === false, 'report cutover_performed false');
    shadow_self_test(($report['application_switched_to_shadow'] ?? true) === false, 'report no app switch');
    shadow_self_test(($report['files_restored'] ?? true) === false, 'report files_restored false');
    shadow_self_test(($report['maintenance_enabled'] ?? true) === false, 'report maintenance_enabled false');
    shadow_self_test(isset($report['package_compare']) && isset($report['production_compare']), 'report has package + production compare');
    shadow_self_test(
        (bool) (($report['production_compare']['read_only_production_scan'] ?? false)) === true,
        'production compare marked read-only'
    );

    $pub = orange_restore_shadow_public_meta(orange_restore_shadow_load_meta($workRoot, $jobId) ?? []);
    shadow_self_test(!isset($pub['package_path']) && !isset($pub['dump_path']), 'safe API redaction');

    $runDup = orange_restore_shadow_run_cli($projectRoot, $workRoot, $backupRoot, $jobId, 'tester');
    shadow_self_test(($runDup['idempotent'] ?? false) === true && ($runDup['ok'] ?? false) === true, 'duplicate CLI run is idempotent');

    // Verify failure path → shadow_restore_failed (retryable).
    $readyJob = orange_restore_fw_read($workRoot, $jobId);
    $readyJob['status'] = ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED;
    $readyJob['phase'] = ORANGE_RESTORE_FW_PHASE_EXECUTION_CANCELLED;
    orange_restore_fw_write($workRoot, $readyJob);
    orange_restore_shadow_release_lock($workRoot, $jobId);
    orange_restore_exec_release_lock($workRoot, $jobId);
    orange_restore_fw_release_lock($workRoot, $jobId);

    $made2 = shadow_test_make_approved_job($workRoot, $backupRoot, $sourceId);
    $job2Id = $made2['job_id'];
    shadow_test_mark_pre_backup_ready($workRoot, $job2Id, $sourceId);
    orange_restore_shadow_request($workRoot, $job2Id, $backupRoot, ['username' => 'superadmin']);

    $GLOBALS['orange_shadow_inventory_override'] = static function () use ($shadowDb): array {
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
        ];
    };
    $failRun = orange_restore_shadow_run_cli($projectRoot, $workRoot, $backupRoot, $job2Id, 'tester');
    shadow_self_test(($failRun['ok'] ?? true) === false && ($failRun['code'] ?? '') === 'shadow_verify_failed', 'verification failure blocks readiness');
    shadow_self_test(
        (orange_restore_fw_read($workRoot, $job2Id)['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'failed attempt preserves forensic status'
    );
    shadow_self_test(is_file(orange_restore_shadow_report_path($workRoot, $job2Id)), 'failed attempt preserves report');

    shadow_test_install_success_overrides($shadowDb);
    $retryReq = orange_restore_shadow_request($workRoot, $job2Id, $backupRoot, ['username' => 'superadmin']);
    shadow_self_test(
        ($retryReq['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        'retry policy from shadow_restore_failed'
    );
    $retryRun = orange_restore_shadow_run_cli($projectRoot, $workRoot, $backupRoot, $job2Id, 'tester');
    shadow_self_test(
        ($retryRun['ok'] ?? false) === true,
        'retry succeeds to shadow_restore_ready' . (empty($retryRun['ok']) ? (' code=' . (string) ($retryRun['code'] ?? '')) : '')
    );

    orange_restore_shadow_acquire_lock($workRoot, 'other_job', 'other');
    $lockStatus = orange_restore_shadow_acquire_lock($workRoot, $job2Id, 'tester');
    shadow_self_test(($lockStatus['ok'] ?? true) === false, 'lock contention');
    orange_restore_shadow_release_lock($workRoot, 'other_job');

    // Name fence: shadow must not equal production.
    $GLOBALS['orange_shadow_production_db_override'] = 'orange_db_prod_selftest_mock';
    $nameRejected = false;
    try {
        orange_restore_shadow_db_name(
            [ORANGE_RESTORE_ENV_SHADOW_DB => 'orange_db_prod_selftest_mock'],
            $projectRoot
        );
    } catch (Throwable $e) {
        $nameRejected = str_contains($e->getMessage(), 'must not equal production');
    }
    shadow_self_test($nameRejected, 'shadow DB name cannot equal production');

    shadow_self_test(!is_file($projectRoot . '/admin/api/restore/job/execute.php'), 'no execute endpoint');
    // Rollback API exists (3B.4E+) but must not be invoked from the shadow restore module.
    shadow_self_test(is_file($projectRoot . '/admin/api/restore/job/rollback.php'), 'rollback endpoint exists separately');
    shadow_self_test(!is_file($projectRoot . '/admin/api/restore/job/cutover.php'), 'no cutover endpoint');

    $mod = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_shadow_db.php');
    shadow_self_test(
        !str_contains($mod, 'orange_restore_prod_rollback_')
        && !str_contains($mod, 'restore_production_rollback.php'),
        'shadow module does not invoke rollback engine'
    );
    shadow_self_test(
        !str_contains($mod, 'orange_restore_orchestrator_database_cutover(')
        && !str_contains($mod, 'orange_restore_orchestrator_uploads_cutover(')
        && !str_contains($mod, 'orange_restore_full_staging_run(')
        && !str_contains($mod, 'orange_restore_e2e_start_full(')
        && !str_contains($mod, 'orange_restore_fw_maint_enable')
        && str_contains($mod, 'orange_restore_sql_runner_import_gzip'),
        'reuses SQL import; no cutover/staging-full/e2e/maint enable'
    );

    $ui = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
    shadow_self_test(
        str_contains($ui, 'rc-shadow-req')
        && str_contains($ui, 'rc-shadow-view')
        && !str_contains(strtolower($ui), 'execute restore'),
        'UI request/view present; no Execute Restore'
    );

    echo 'SHADOW_RESTORE_TEST_RESULT: ' . ($failures === 0 ? 'PASS' : 'FAIL') . "\n";
    echo 'TOTAL_PASS: ' . $passes . "\n";
    echo 'TOTAL_FAIL: ' . $failures . "\n";
    exit($failures > 0 ? 1 : 0);
} catch (Throwable $e) {
    echo 'THROWABLE: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    echo "SHADOW_RESTORE_TEST_RESULT: FAIL\n";
    echo 'TOTAL_PASS: ' . $passes . "\n";
    echo 'TOTAL_FAIL: ' . ($failures + 1) . "\n";
    exit(1);
}
