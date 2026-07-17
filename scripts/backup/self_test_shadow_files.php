<?php

declare(strict_types=1);

/**
 * Phase 3B.3B6 — dedicated shadow file restore self-test (isolated temp dirs only).
 *
 * Never touches production filesystem paths. Never rename/cutover.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_shadow_files.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_dry_run.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_pre_restore_backup.php';

$failures = 0;
$passes = 0;

function sf_self_test(bool $ok, string $label): void
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

function sf_test_write_zip(string $path, array $files): void
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

    // Minimal stored (no compression) ZIP writer for environments without ZipArchive.
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
    if (file_put_contents($path, $local . $central . $end) === false) {
        throw new RuntimeException('Cannot create test zip');
    }
}

function sf_test_seed_package(string $pkgDir, string $pkgId): void
{
    if (!is_dir($pkgDir)) {
        mkdir($pkgDir, 0775, true);
    }
    $dumpRel = 'database.sql.gz';
    $uploadsRel = 'uploads.zip';
    $gz = gzencode("SET NAMES utf8mb4;\nCREATE TABLE t(id INT);\n", 1);
    file_put_contents($pkgDir . DIRECTORY_SEPARATOR . $dumpRel, $gz !== false ? $gz : str_repeat('x', 32));
    sf_test_write_zip($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel, [
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

/**
 * @return array{job_id:string}
 */
function sf_test_make_shadow_verified_job(string $workRoot, string $backupRoot, string $sourceId): array
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

    orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), [
        'record_version' => ORANGE_RESTORE_SHADOW_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => $sourceId,
        'shadow_db' => 'orange_shadow_files_selftest',
        'ready' => true,
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY,
        'execution_started' => false,
        'production_touched' => false,
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

    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED,
        ORANGE_RESTORE_FW_PHASE_SHADOW_VERIFIED,
        100,
        'test shadow verified',
        'shadow_verified'
    );
    $ready = orange_restore_fw_read($workRoot, $jobId);
    $ready['shadow_restore_file'] = ORANGE_RESTORE_SHADOW_META_FILE;
    $ready['shadow_verification_file'] = ORANGE_RESTORE_SHADOW_VERIFY_META_FILE;
    $ready['pre_restore_backup_file'] = ORANGE_RESTORE_PRE_BACKUP_FILE;
    $ready['shadow_verification_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED;
    $ready['execution_started'] = false;
    orange_restore_fw_write($workRoot, $ready);

    return ['job_id' => $jobId];
}

function sf_test_clear_overrides(): void
{
    unset(
        $GLOBALS['orange_shadow_files_scan_override'],
        $GLOBALS['orange_shadow_files_extract_override'],
        $GLOBALS['orange_shadow_files_inventory_override']
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
    echo "SHADOW_FILES_TEST_RESULT: SKIP\n";
    exit(0);
}

$selfBase = rtrim($configured, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . '_self_tests';
if (!is_dir($selfBase)) {
    @mkdir($selfBase, 0775, true);
}
$tmpRoot = $selfBase . DIRECTORY_SEPARATOR . 'shadow_files_' . bin2hex(random_bytes(4));
$backupRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'backups';
$workRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'restore_work';
foreach ([$tmpRoot, $backupRoot, $workRoot, $backupRoot . DIRECTORY_SEPARATOR . 'snapshots', $backupRoot . DIRECTORY_SEPARATOR . 'locks'] as $d) {
    if (!is_dir($d)) {
        mkdir($d, 0775, true);
    }
}

$cleanup = static function () use ($tmpRoot): void {
    sf_test_clear_overrides();
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
    $sourceId = '2026-07-01_160000';
    sf_test_seed_package($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $sourceId, $sourceId);
    $made = sf_test_make_shadow_verified_job($workRoot, $backupRoot, $sourceId);
    $jobId = $made['job_id'];

    $early = orange_restore_fw_create($workRoot, [
        'package_id' => '2026-07-01_170000',
        'package_type' => 'full_disaster',
        'created_by' => 'superadmin',
        'created_by_admin_id' => 1,
    ]);
    $earlyRejected = false;
    try {
        orange_restore_shadow_files_run_cli($projectRoot, $workRoot, $backupRoot, (string) $early['job_id'], 'tester');
    } catch (Throwable $e) {
        $earlyRejected = in_array(trim($e->getMessage()), ['invalid_status', 'shadow_not_verified'], true);
    }
    sf_self_test($earlyRejected, 'CLI rejected before shadow_verified');
    orange_restore_fw_write($workRoot, array_merge(orange_restore_fw_read($workRoot, (string) $early['job_id']), [
        'status' => ORANGE_RESTORE_FW_STATUS_CANCELLED,
        'phase' => ORANGE_RESTORE_FW_PHASE_CANCELLED,
    ]));
    orange_restore_fw_release_lock($workRoot, (string) $early['job_id']);

    $getApi = (string) file_get_contents($projectRoot . '/admin/api/restore/job/shadow-files.php');
    sf_self_test(
        str_contains($getApi, 'restore_admin_api_require_get')
        && !str_contains($getApi, 'orange_restore_shadow_files_run_cli'),
        'HTTP status API is GET/read-only'
    );
    sf_self_test(!is_file($projectRoot . '/admin/api/restore/job/request-shadow-files.php'), 'no HTTP request endpoint');

    $cliSrc = (string) file_get_contents($projectRoot . '/scripts/backup/restore_shadow_files.php');
    sf_self_test(
        str_contains($cliSrc, "PHP_SAPI !== 'cli'")
        && str_contains($cliSrc, '--job=')
        && !str_contains($cliSrc, 'orange_restore_orchestrator_uploads_cutover'),
        'CLI-only gate; no uploads cutover'
    );

    // Real extract into isolated workspace under _self_tests work root.
    $run = orange_restore_shadow_files_run_cli($projectRoot, $workRoot, $backupRoot, $jobId, 'tester');
    sf_self_test(
        ($run['ok'] ?? false) === true,
        'successful shadow file extract'
            . (empty($run['ok']) ? (' code=' . (string) ($run['code'] ?? '')) : '')
    );
    sf_self_test(($run['production_touched'] ?? true) === false, 'production_touched false');
    sf_self_test(($run['execution_started'] ?? true) === false, 'execution_started false');
    sf_self_test(
        (orange_restore_fw_read($workRoot, $jobId)['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY,
        'stops at shadow_files_ready'
    );
    sf_self_test(is_file(orange_restore_shadow_files_report_path($workRoot, $jobId)), 'writes shadow_files_report.json');

    $workspace = orange_restore_shadow_files_workspace_path($workRoot, $jobId);
    sf_self_test(is_file($workspace . DIRECTORY_SEPARATOR . 'a.txt'), 'extracted a.txt in workspace');
    sf_self_test(is_file($workspace . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'b.txt'), 'extracted nested/b.txt');
    $wsNorm = str_replace('\\', '/', $workspace);
    sf_self_test(str_contains($wsNorm, '/restore_shadow_workspace'), 'workspace dirname is restore_shadow_workspace');
    sf_self_test(str_contains($wsNorm, '/_self_tests/'), 'workspace stays under self_tests');

    $report = orange_restore_shadow_files_load_report($workRoot, $jobId) ?? [];
    sf_self_test(($report['overall_result'] ?? '') === 'PASS', 'report PASS');
    sf_self_test(($report['cutover_performed'] ?? true) === false, 'report cutover false');
    sf_self_test(($report['directories_renamed'] ?? true) === false, 'report rename false');
    sf_self_test(($report['files_restored_to_production'] ?? true) === false, 'report no prod restore');

    $dup = orange_restore_shadow_files_run_cli($projectRoot, $workRoot, $backupRoot, $jobId, 'tester');
    sf_self_test(($dup['idempotent'] ?? false) === true && ($dup['ok'] ?? false) === true, 'duplicate CLI idempotent');

    // Zip-slip / safety failure path.
    $readyJob = orange_restore_fw_read($workRoot, $jobId);
    $readyJob['status'] = ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED;
    $readyJob['phase'] = ORANGE_RESTORE_FW_PHASE_EXECUTION_CANCELLED;
    orange_restore_fw_write($workRoot, $readyJob);
    orange_restore_shadow_files_release_lock($workRoot, $jobId);
    orange_restore_exec_release_lock($workRoot, $jobId);
    orange_restore_fw_release_lock($workRoot, $jobId);

    $made2 = sf_test_make_shadow_verified_job($workRoot, $backupRoot, $sourceId);
    $job2Id = $made2['job_id'];
    $GLOBALS['orange_shadow_files_scan_override'] = static function (): array {
        return [
            'ok' => false,
            'files' => [],
            'dirs' => [],
            'errors' => ['zip_slip:../evil.txt'],
            'file_count' => 0,
        ];
    };
    $fail = orange_restore_shadow_files_run_cli($projectRoot, $workRoot, $backupRoot, $job2Id, 'tester');
    sf_self_test(($fail['ok'] ?? true) === false, 'zip safety failure blocks readiness');
    sf_self_test(
        (orange_restore_fw_read($workRoot, $job2Id)['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_FAILED,
        'failed attempt preserves shadow_files_failed'
    );
    sf_self_test(is_file(orange_restore_shadow_files_report_path($workRoot, $job2Id)), 'failed attempt preserves report');
    sf_test_clear_overrides();

    $retry = orange_restore_shadow_files_run_cli($projectRoot, $workRoot, $backupRoot, $job2Id, 'tester');
    sf_self_test(($retry['ok'] ?? false) === true, 'retry from shadow_files_failed succeeds');
    sf_self_test(
        (orange_restore_fw_read($workRoot, $job2Id)['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY,
        'retry reaches shadow_files_ready'
    );

    // Pure zip-slip scanner unit check with a crafted zip if ZipArchive available.
    $evilZip = $tmpRoot . DIRECTORY_SEPARATOR . 'evil.zip';
    sf_test_write_zip($evilZip, ['../escape.txt' => 'nope']);
    $scanEvil = orange_restore_shadow_files_scan_zip($evilZip);
    sf_self_test(($scanEvil['ok'] ?? true) === false, 'scanner rejects zip-slip entry');

    orange_restore_shadow_files_acquire_lock($workRoot, 'other_job', 'other');
    $lockStatus = orange_restore_shadow_files_acquire_lock($workRoot, $job2Id, 'tester');
    sf_self_test(($lockStatus['ok'] ?? true) === false, 'lock contention');
    orange_restore_shadow_files_release_lock($workRoot, 'other_job');

    $mod = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_shadow_files.php');
    sf_self_test(
        str_contains($mod, 'restore_shadow_workspace')
        && str_contains($mod, 'orange_restore_uploads_applicator_extract')
        && str_contains($mod, 'orange_restore_uploads_tree_inventory')
        && !str_contains($mod, 'orange_restore_orchestrator_uploads_cutover(')
        && !str_contains($mod, 'orange_restore_merge_uploads_cutover(')
        && !str_contains($mod, 'rename('),
        'reuses safe extract/inventory; no cutover/rename'
    );

    $ui = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
    sf_self_test(
        str_contains($ui, 'rc-shadow-files-view')
        && str_contains($ui, 'shadow_files_ready')
        && !str_contains(strtolower($ui), 'execute restore'),
        'UI view present; no Execute Restore'
    );

    $pub = orange_restore_shadow_files_public_meta(orange_restore_shadow_files_load_meta($workRoot, $jobId) ?? []);
    sf_self_test(!isset($pub['workspace_path']) && !isset($pub['zip_path']), 'safe API redaction');

    echo 'SHADOW_FILES_TEST_RESULT: ' . ($failures === 0 ? 'PASS' : 'FAIL') . "\n";
    echo 'TOTAL_PASS: ' . $passes . "\n";
    echo 'TOTAL_FAIL: ' . $failures . "\n";
    exit($failures > 0 ? 1 : 0);
} catch (Throwable $e) {
    echo 'THROWABLE: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    echo "SHADOW_FILES_TEST_RESULT: FAIL\n";
    echo 'TOTAL_PASS: ' . $passes . "\n";
    echo 'TOTAL_FAIL: ' . ($failures + 1) . "\n";
    exit(1);
}
