<?php

declare(strict_types=1);

/**
 * Phase 3B.3B7 — shadow smoke + cutover readiness self-test (isolated temp dirs only).
 *
 * Never touches production DB/files/config. Never cutover/maintenance/rollback.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_shadow_smoke.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_dry_run.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_pre_restore_backup.php';

$failures = 0;
$passes = 0;

function ss_self_test(bool $ok, string $label): void
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

function ss_test_write_zip(string $path, array $files): void
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create test zip');
        }
        foreach ($files as $name => $body) {
            $zip->addFromString((string) $name, (string) $body);
        }
        $zip->close();

        return;
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

function ss_test_seed_package(string $pkgDir, string $pkgId): void
{
    if (!is_dir($pkgDir)) {
        mkdir($pkgDir, 0775, true);
    }
    $dumpRel = 'database.sql.gz';
    $uploadsRel = 'uploads.zip';
    $gz = gzencode("SET NAMES utf8mb4;\nCREATE TABLE t(id INT);\n", 1);
    file_put_contents($pkgDir . DIRECTORY_SEPARATOR . $dumpRel, $gz !== false ? $gz : str_repeat('x', 32));
    ss_test_write_zip($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel, [
        'a.txt' => 'hello-a',
        'nested/b.txt' => 'hello-b',
    ]);
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

/** @return array{job_id:string} */
function ss_test_make_shadow_files_ready_job(string $workRoot, string $backupRoot, string $sourceId): array
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
    unset($dry);
    $afterDry = orange_restore_fw_read($workRoot, $jobId);
    if ((string) ($afterDry['status'] ?? '') !== ORANGE_RESTORE_FW_STATUS_DRY_COMPLETED) {
        throw new RuntimeException('dry_run_not_completed:' . (string) ($afterDry['status'] ?? ''));
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

    orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), [
        'record_version' => ORANGE_RESTORE_SHADOW_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => $sourceId,
        'shadow_db' => 'orange_shadow_smoke_selftest',
        'ready' => true,
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY,
        'execution_started' => false,
        'production_touched' => false,
    ]);
    orange_restore_shadow_write_json(orange_restore_shadow_report_path($workRoot, $jobId), [
        'report_version' => ORANGE_RESTORE_SHADOW_RECORD_VERSION,
        'overall_result' => 'PASS',
        'production_touched' => false,
        'execution_started' => false,
    ]);
    orange_restore_shadow_verify_write_json(orange_restore_shadow_verify_meta_path($workRoot, $jobId), [
        'record_version' => ORANGE_RESTORE_SHADOW_VERIFY_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => $sourceId,
        'verified' => true,
        'overall_result' => 'READY',
        'readiness_score' => 95,
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED,
        'execution_started' => false,
        'production_touched' => false,
    ]);
    orange_restore_shadow_verify_write_json(orange_restore_shadow_verify_report_path($workRoot, $jobId), [
        'report_version' => ORANGE_RESTORE_SHADOW_VERIFY_RECORD_VERSION,
        'overall_result' => 'READY',
        'readiness_score' => 95,
        'verified' => true,
        'production_touched' => false,
    ]);

    $ws = orange_restore_shadow_files_workspace_path($workRoot, $jobId);
    if (!is_dir($ws)) {
        mkdir($ws, 0775, true);
    }
    file_put_contents($ws . DIRECTORY_SEPARATOR . 'a.txt', 'hello-a');
    mkdir($ws . DIRECTORY_SEPARATOR . 'nested', 0775, true);
    file_put_contents($ws . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'b.txt', 'hello-b');

    orange_restore_shadow_files_write_json(orange_restore_shadow_files_meta_path($workRoot, $jobId), [
        'record_version' => ORANGE_RESTORE_SHADOW_FILES_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => $sourceId,
        'ready' => true,
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY,
        'files_extracted' => 2,
        'execution_started' => false,
        'production_touched' => false,
        'cutover_performed' => false,
        'directories_renamed' => false,
    ]);
    orange_restore_shadow_files_write_json(orange_restore_shadow_files_report_path($workRoot, $jobId), [
        'report_version' => ORANGE_RESTORE_SHADOW_FILES_RECORD_VERSION,
        'overall_result' => 'PASS',
        'production_touched' => false,
        'cutover_performed' => false,
        'directories_renamed' => false,
        'files_restored_to_production' => false,
        'execution_started' => false,
    ]);

    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY,
        ORANGE_RESTORE_FW_PHASE_SHADOW_FILES_READY,
        100,
        'test shadow files ready',
        'shadow_files_ready'
    );
    $ready = orange_restore_fw_read($workRoot, $jobId);
    $ready['shadow_restore_file'] = ORANGE_RESTORE_SHADOW_META_FILE;
    $ready['shadow_verification_file'] = ORANGE_RESTORE_SHADOW_VERIFY_META_FILE;
    $ready['shadow_files_file'] = ORANGE_RESTORE_SHADOW_FILES_META_FILE;
    $ready['pre_restore_backup_file'] = ORANGE_RESTORE_PRE_BACKUP_FILE;
    $ready['execution_started'] = false;
    orange_restore_fw_write($workRoot, $ready);

    return ['job_id' => $jobId];
}

function ss_test_clear_overrides(): void
{
    unset($GLOBALS['orange_shadow_smoke_pipeline_override']);
    orange_restore_shadow_context_end();
}

function ss_pipeline_ready(string $projectRoot, string $workRoot, string $backupRoot, string $jobId): array
{
    unset($projectRoot, $backupRoot);
    $ws = orange_restore_shadow_files_workspace_path($workRoot, $jobId);
    orange_restore_shadow_context_begin($jobId, 'orange_shadow_smoke_selftest', $ws, 'orange_db_prod_fake');
    try {
        orange_restore_shadow_context_assert_read_only('probe');
    } catch (Throwable) {
    }
    try {
        orange_restore_shadow_context_assert_integrations_disabled('email');
    } catch (Throwable) {
    }
    $ctx = orange_restore_shadow_context_snapshot();

    return [
        'overall_result' => 'READY',
        'readiness_score' => 96,
        'blocking_errors' => [],
        'warnings' => [],
        'checks' => [
            orange_restore_shadow_smoke_check('bootstrap', 'environment', 'PASS', 'info', 'ok', 'ok'),
            orange_restore_shadow_smoke_check('mutation_guard', 'isolation', 'PASS', 'info', 'blocked', 'blocked'),
        ],
        'context' => $ctx,
        'shadow_db_identity_hash' => hash('sha256', 'orange_shadow_smoke_selftest'),
        'shadow_files_identity_hash' => hash('sha256', 'restore_shadow_workspace/' . $jobId),
        'gates' => [
            'shadow_db_ready' => true,
            'shadow_files_ready' => true,
            'rollback_anchor_ready' => true,
            'approval_valid' => true,
            'contract_valid' => true,
            'version_lock_valid' => true,
            'package_fingerprint_valid' => true,
        ],
    ];
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
    echo "SHADOW_SMOKE_TEST_RESULT: SKIP\n";
    exit(0);
}

$selfBase = rtrim($configured, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . '_self_tests';
if (!is_dir($selfBase)) {
    @mkdir($selfBase, 0775, true);
}
$tmpRoot = $selfBase . DIRECTORY_SEPARATOR . 'shadow_smoke_' . bin2hex(random_bytes(4));
$backupRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'backups';
$workRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'restore_work';
foreach ([$tmpRoot, $backupRoot, $workRoot, $backupRoot . DIRECTORY_SEPARATOR . 'snapshots', $backupRoot . DIRECTORY_SEPARATOR . 'locks'] as $d) {
    if (!is_dir($d)) {
        mkdir($d, 0775, true);
    }
}

$cleanup = static function () use ($tmpRoot): void {
    ss_test_clear_overrides();
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
    // Isolated mutation / integration guards.
    orange_restore_shadow_context_begin('job_x', 'shadow_db_x', $tmpRoot . '/ws', 'prod_db_x');
    $blocked = false;
    try {
        orange_restore_shadow_context_assert_read_only('test_write');
    } catch (Throwable $e) {
        $blocked = trim($e->getMessage()) === 'shadow_context_write_blocked';
    }
    ss_self_test($blocked, 'write attempt blocked');
    $intBlocked = false;
    try {
        orange_restore_shadow_context_assert_integrations_disabled('payment');
    } catch (Throwable $e) {
        $intBlocked = trim($e->getMessage()) === 'shadow_context_integration_blocked';
    }
    ss_self_test($intBlocked, 'external integration blocked');
    orange_restore_shadow_context_end();

    $scoreReady = orange_restore_shadow_smoke_score([
        orange_restore_shadow_smoke_check('a', 'environment', 'PASS', 'info', 'ok'),
    ]);
    ss_self_test(($scoreReady['overall_result'] ?? '') === 'READY' && ($scoreReady['readiness_score'] ?? 0) >= 85, 'READY score path');
    $scoreWarn = orange_restore_shadow_smoke_score([
        orange_restore_shadow_smoke_check('a', 'environment', 'WARNING', 'warning', 'soft'),
    ]);
    ss_self_test(($scoreWarn['overall_result'] ?? '') === 'WARNING', 'WARNING score path');
    $scoreFail = orange_restore_shadow_smoke_score([
        orange_restore_shadow_smoke_check('a', 'database', 'FAIL', 'blocking', 'missing table'),
    ]);
    ss_self_test(($scoreFail['overall_result'] ?? '') === 'FAIL', 'FAIL score path');
    ss_self_test(
        ($scoreReady['readiness_score'] ?? -1) >= 0 && ($scoreReady['readiness_score'] ?? 101) <= 100,
        'readiness score bounds'
    );

    $sourceId = '2026-07-01_180000';
    ss_test_seed_package($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $sourceId, $sourceId);

    $country = orange_restore_fw_create($workRoot, [
        'package_id' => '2026-07-01_180100',
        'package_type' => 'country_recovery',
        'country_code' => 'KW',
        'created_by' => 'superadmin',
        'created_by_admin_id' => 1,
    ]);
    $countryRejected = false;
    try {
        orange_restore_shadow_smoke_request($workRoot, (string) $country['job_id'], $backupRoot, ['username' => 'tester']);
    } catch (Throwable $e) {
        $countryRejected = trim($e->getMessage()) === 'country_production_restore_not_enabled';
    }
    ss_self_test($countryRejected, 'Country job rejected');
    orange_restore_fw_write($workRoot, array_merge(orange_restore_fw_read($workRoot, (string) $country['job_id']), [
        'status' => ORANGE_RESTORE_FW_STATUS_CANCELLED,
        'phase' => ORANGE_RESTORE_FW_PHASE_CANCELLED,
    ]));
    orange_restore_fw_release_lock($workRoot, (string) $country['job_id']);

    $made = ss_test_make_shadow_files_ready_job($workRoot, $backupRoot, $sourceId);
    $jobId = $made['job_id'];

    $reqApi = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-shadow-smoke.php');
    ss_self_test(
        str_contains($reqApi, 'restore_admin_api_require_post')
        && str_contains($reqApi, 'cli_needed')
        && !str_contains($reqApi, 'orange_restore_shadow_smoke_run_cli'),
        'HTTP request does not execute CLI'
    );
    $getApi = (string) file_get_contents($projectRoot . '/admin/api/restore/job/shadow-smoke.php');
    ss_self_test(
        str_contains($getApi, 'restore_admin_api_require_get')
        && !str_contains($getApi, 'orange_restore_shadow_smoke_run_cli'),
        'HTTP smoke status is GET/read-only'
    );
    $cutApi = (string) file_get_contents($projectRoot . '/admin/api/restore/job/cutover-readiness.php');
    ss_self_test(
        str_contains($cutApi, 'restore_admin_api_require_get')
        && str_contains($cutApi, 'production_cutover_allowed')
        && !str_contains($cutApi, 'orange_restore_shadow_smoke_run_cli'),
        'HTTP cutover-readiness is GET/read-only'
    );
    ss_self_test(!is_file($projectRoot . '/admin/api/restore/job/execute-cutover.php'), 'no execute cutover endpoint');
    ss_self_test(!is_file($projectRoot . '/admin/api/restore/job/approve-cutover.php'), 'no approve cutover endpoint');
    ss_self_test(!is_file($projectRoot . '/admin/api/restore/job/switch-database.php'), 'no switch database endpoint');
    ss_self_test(!is_file($projectRoot . '/admin/api/restore/job/enable-maintenance.php'), 'no enable maintenance endpoint');

    $cliSrc = (string) file_get_contents($projectRoot . '/scripts/backup/restore_shadow_smoke.php');
    ss_self_test(
        str_contains($cliSrc, "PHP_SAPI !== 'cli'")
        && str_contains($cliSrc, '--job=')
        && str_contains($cliSrc, 'PRODUCTION_CUTOVER_ALLOWED: NO'),
        'CLI-only gate; cutover disallowed banner'
    );

    // Missing rollback anchor blocks.
    $anchorPath = orange_restore_pre_backup_record_path($workRoot, $jobId);
    $anchorBackup = (string) file_get_contents($anchorPath);
    @unlink($anchorPath);
    $anchorBlocked = false;
    try {
        orange_restore_shadow_smoke_request($workRoot, $jobId, $backupRoot, ['username' => 'tester']);
    } catch (Throwable $e) {
        $anchorBlocked = trim($e->getMessage()) === 'pre_restore_backup_not_ready';
    }
    ss_self_test($anchorBlocked, 'rollback anchor missing blocks readiness');
    file_put_contents($anchorPath, $anchorBackup);

    $req = orange_restore_shadow_smoke_request($workRoot, $jobId, $backupRoot, ['username' => 'tester']);
    ss_self_test(($req['cli_needed'] ?? false) === true, 'request returns cli_needed');
    ss_self_test(($req['production_cutover_allowed'] ?? true) === false, 'request production_cutover_allowed false');
    ss_self_test(
        (orange_restore_fw_read($workRoot, $jobId)['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_PENDING,
        'request transitions to shadow_smoke_pending'
    );

    $GLOBALS['orange_shadow_smoke_pipeline_override'] = 'ss_pipeline_ready';
    $run = orange_restore_shadow_smoke_run_cli($projectRoot, $workRoot, $backupRoot, $jobId, 'tester');
    ss_self_test(($run['ok'] ?? false) === true, 'successful isolated bootstrap/smoke');
    ss_self_test(($run['result'] ?? '') === 'READY', 'successful READY report');
    ss_self_test(($run['production_cutover_allowed'] ?? true) === false, 'production_cutover_allowed always false');
    ss_self_test(($run['execution_started'] ?? true) === false, 'execution_started false');
    ss_self_test(($run['production_touched'] ?? true) === false, 'production_touched false');
    ss_self_test(is_file(orange_restore_shadow_smoke_report_path($workRoot, $jobId)), 'writes shadow_smoke_report.json');
    ss_self_test(is_file(orange_restore_cutover_readiness_path($workRoot, $jobId)), 'writes cutover_readiness.json');
    $decision = orange_restore_cutover_readiness_load($workRoot, $jobId) ?? [];
    ss_self_test(($decision['status'] ?? '') === 'READY', 'cutover readiness READY');
    ss_self_test(($decision['production_cutover_allowed'] ?? true) === false, 'decision cutover disallowed');
    ss_self_test(
        (orange_restore_fw_read($workRoot, $jobId)['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_READY,
        'stops at cutover_readiness_ready'
    );
    $report = orange_restore_shadow_smoke_load_report($workRoot, $jobId) ?? [];
    ss_self_test(($report['production_db_writes'] ?? 1) === 0, 'no production DB writes');
    ss_self_test(($report['production_file_writes'] ?? 1) === 0, 'no production file writes');
    ss_self_test(($report['external_integrations_invoked'] ?? true) === false, 'no external integrations invoked');
    ss_self_test(($report['smoke_tests_completed'] ?? false) === true, 'smoke_tests_completed true');

    $dup = orange_restore_shadow_smoke_run_cli($projectRoot, $workRoot, $backupRoot, $jobId, 'tester');
    ss_self_test(($dup['idempotent'] ?? false) === true, 'duplicate CLI idempotent');

    // WARNING path
    $readyJob = orange_restore_fw_read($workRoot, $jobId);
    $readyJob['status'] = ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED;
    $readyJob['phase'] = ORANGE_RESTORE_FW_PHASE_EXECUTION_CANCELLED;
    orange_restore_fw_write($workRoot, $readyJob);
    orange_restore_shadow_smoke_release_lock($workRoot, $jobId);
    orange_restore_exec_release_lock($workRoot, $jobId);
    orange_restore_fw_release_lock($workRoot, $jobId);

    $made2 = ss_test_make_shadow_files_ready_job($workRoot, $backupRoot, $sourceId);
    $job2 = $made2['job_id'];
    orange_restore_shadow_smoke_request($workRoot, $job2, $backupRoot, ['username' => 'tester']);
    $GLOBALS['orange_shadow_smoke_pipeline_override'] = static function () use ($workRoot, $job2): array {
        $ws = orange_restore_shadow_files_workspace_path($workRoot, $job2);
        orange_restore_shadow_context_begin($job2, 'shadow_warn', $ws, 'prod_fake');
        return [
            'overall_result' => 'WARNING',
            'readiness_score' => 72,
            'blocking_errors' => [],
            'warnings' => ['soft inconsistency'],
            'checks' => [
                orange_restore_shadow_smoke_check('soft', 'referential', 'WARNING', 'warning', 'soft inconsistency'),
            ],
            'context' => orange_restore_shadow_context_snapshot(),
            'shadow_db_identity_hash' => hash('sha256', 'shadow_warn'),
            'shadow_files_identity_hash' => hash('sha256', 'ws'),
            'gates' => [
                'shadow_db_ready' => true,
                'shadow_files_ready' => true,
                'rollback_anchor_ready' => true,
                'approval_valid' => true,
                'contract_valid' => true,
                'version_lock_valid' => true,
                'package_fingerprint_valid' => true,
            ],
        ];
    };
    $warn = orange_restore_shadow_smoke_run_cli($projectRoot, $workRoot, $backupRoot, $job2, 'tester');
    ss_self_test(($warn['result'] ?? '') === 'WARNING', 'WARNING report');
    ss_self_test(
        (orange_restore_fw_read($workRoot, $job2)['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_MANUAL_REVIEW,
        'WARNING maps to cutover_readiness_manual_review'
    );
    ss_self_test(((orange_restore_cutover_readiness_load($workRoot, $job2) ?? [])['production_cutover_allowed'] ?? true) === false, 'WARNING still disallows cutover');

    // FAIL path
    $j2 = orange_restore_fw_read($workRoot, $job2);
    $j2['status'] = ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED;
    $j2['phase'] = ORANGE_RESTORE_FW_PHASE_EXECUTION_CANCELLED;
    orange_restore_fw_write($workRoot, $j2);
    orange_restore_shadow_smoke_release_lock($workRoot, $job2);
    orange_restore_exec_release_lock($workRoot, $job2);
    orange_restore_fw_release_lock($workRoot, $job2);
    $made3 = ss_test_make_shadow_files_ready_job($workRoot, $backupRoot, $sourceId);
    $job3 = $made3['job_id'];
    orange_restore_shadow_smoke_request($workRoot, $job3, $backupRoot, ['username' => 'tester']);
    $GLOBALS['orange_shadow_smoke_pipeline_override'] = static function () use ($workRoot, $job3): array {
        $ws = orange_restore_shadow_files_workspace_path($workRoot, $job3);
        orange_restore_shadow_context_begin($job3, 'shadow_fail', $ws, 'prod_fake');
        return [
            'overall_result' => 'FAIL',
            'readiness_score' => 20,
            'blocking_errors' => ['required table missing', 'GL imbalance'],
            'warnings' => [],
            'checks' => [
                orange_restore_shadow_smoke_check('required_tables', 'database', 'FAIL', 'blocking', 'required table missing'),
                orange_restore_shadow_smoke_check('gl_journal_balanced', 'accounting', 'FAIL', 'blocking', 'GL imbalance'),
            ],
            'context' => orange_restore_shadow_context_snapshot(),
            'shadow_db_identity_hash' => hash('sha256', 'shadow_fail'),
            'shadow_files_identity_hash' => hash('sha256', 'ws'),
            'gates' => [
                'shadow_db_ready' => true,
                'shadow_files_ready' => true,
                'rollback_anchor_ready' => true,
                'approval_valid' => true,
                'contract_valid' => true,
                'version_lock_valid' => true,
                'package_fingerprint_valid' => true,
            ],
        ];
    };
    $fail = orange_restore_shadow_smoke_run_cli($projectRoot, $workRoot, $backupRoot, $job3, 'tester');
    ss_self_test(($fail['ok'] ?? true) === false && ($fail['result'] ?? '') === 'FAIL', 'FAIL report');
    ss_self_test(
        (orange_restore_fw_read($workRoot, $job3)['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_BLOCKED,
        'FAIL maps to cutover_readiness_blocked'
    );

    // Invalid approval blocks
    $j3 = orange_restore_fw_read($workRoot, $job3);
    $j3['status'] = ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED;
    $j3['phase'] = ORANGE_RESTORE_FW_PHASE_EXECUTION_CANCELLED;
    orange_restore_fw_write($workRoot, $j3);
    orange_restore_shadow_smoke_release_lock($workRoot, $job3);
    orange_restore_exec_release_lock($workRoot, $job3);
    orange_restore_fw_release_lock($workRoot, $job3);
    $made4 = ss_test_make_shadow_files_ready_job($workRoot, $backupRoot, $sourceId);
    $job4 = $made4['job_id'];
    @unlink(orange_restore_final_approval_record_path($workRoot, $job4));
    $approvalBlocked = false;
    try {
        orange_restore_shadow_smoke_request($workRoot, $job4, $backupRoot, ['username' => 'tester']);
    } catch (Throwable $e) {
        $approvalBlocked = in_array(trim($e->getMessage()), ['final_approval_missing', 'final_approval_invalid'], true);
    }
    ss_self_test($approvalBlocked, 'invalid/missing approval blocks');

    $mod = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_shadow_smoke.php');
    ss_self_test(
        str_contains($mod, 'orange_restore_shadow_context_assert_read_only')
        && str_contains($mod, 'shadow_smoke_report.json')
        && str_contains($mod, 'cutover_readiness.json')
        && str_contains($mod, "'production_cutover_allowed' => false")
        && !str_contains($mod, 'orange_restore_orchestrator_uploads_cutover(')
        && !str_contains($mod, 'orange_restore_merge_db_cutover('),
        'guards + reports; no cutover helpers'
    );

    $ui = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
    ss_self_test(
        str_contains($ui, 'rc-shadow-smoke-req')
        && str_contains($ui, 'rc-shadow-smoke-view')
        && str_contains($ui, 'تشغيل اختبارات الجاهزية المعزولة')
        && str_contains($ui, 'لم يتم تعديل قاعدة الإنتاج أو ملفات الإنتاج')
        && !str_contains($ui, 'Execute Cutover')
        && !str_contains($ui, 'Switch Database')
        && !str_contains(strtolower($ui), 'execute restore'),
        'UI wires smoke; no cutover/execute controls'
    );

    $pub = orange_restore_shadow_smoke_public_meta(orange_restore_shadow_smoke_load_meta($workRoot, $jobId) ?? []);
    ss_self_test(!isset($pub['workspace_path']) && !isset($pub['dsn']), 'safe API redaction');

    ss_test_clear_overrides();
    echo 'SHADOW_SMOKE_TEST_RESULT: ' . ($failures === 0 ? 'PASS' : 'FAIL') . "\n";
    echo 'TOTAL_PASS: ' . $passes . "\n";
    echo 'TOTAL_FAIL: ' . $failures . "\n";
    exit($failures > 0 ? 1 : 0);
} catch (Throwable $e) {
    echo 'THROWABLE: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    echo "SHADOW_SMOKE_TEST_RESULT: FAIL\n";
    echo 'TOTAL_PASS: ' . $passes . "\n";
    echo 'TOTAL_FAIL: ' . ($failures + 1) . "\n";
    exit(1);
}
