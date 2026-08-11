<?php

declare(strict_types=1);

/**
 * Restore Center Step 6 — Restore-only adapter Phase 2 suite.
 *
 * Usage:
 *   php scripts/self_test_restore_center_step6_restore_only_adapter_phase2.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/backup_admin.php';
require_once $projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
require_once $projectRoot . '/includes/backup/restore_admin.php';

$ev = 'D:/orange_restore_step6_restore_only_phase2_evidence';
if (!is_dir($ev)) {
    mkdir($ev, 0777, true);
}

$pass = 0;
$fail = 0;
function p2_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

$req = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-pre-restore-backup.php');
$pre = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php');
$admin = (string) file_get_contents($projectRoot . '/includes/backup/restore_admin.php');
$page = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
$runFull = (string) file_get_contents($projectRoot . '/admin/api/backup/run-full.php');
$backupAdmin = (string) file_get_contents($projectRoot . '/includes/backup/backup_admin.php');
$orch = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');

/* Freeze removed; adapter re-enabled */
p2_ok(!str_contains($req, 'step6_temporarily_frozen'), 'request API freeze removed');
p2_ok(!str_contains($req, 'phase1_freeze'), 'request API no phase1_freeze payload');
p2_ok(str_contains($req, 'orange_restore_admin_fw_execute_pre_restore_backup'), 'request calls Restore adapter');
p2_ok(str_contains($req, 'orange_backup_admin_run_full_for_api'), 'request names immutable Backup callable');
p2_ok(defined('ORANGE_RESTORE_STEP6_PHASE1_FROZEN') && ORANGE_RESTORE_STEP6_PHASE1_FROZEN === false, 'PHASE1_FROZEN=false');
p2_ok(!str_contains($pre, "ORANGE_RESTORE_STEP6_PHASE1_FROZEN = true"), 'source freeze constant false');
p2_ok(str_contains($pre, 'orange_backup_admin_run_full_for_api'), 'invoke_engine uses Backup Center Full callable');
p2_ok(!str_contains($pre, 'orange_backup_execute_full_authoritative'), 'no removed authoritative wrapper in Restore');
p2_ok(!preg_match('/\$raw\s*=\s*orange_backup_run_full\s*\(/', $pre), 'no direct orange_backup_run_full in Restore');
p2_ok(str_contains($pre, 'backup_package_id_missing') || str_contains($pre, 'Exact package'), 'exact package bind guard present');
p2_ok(!preg_match('/\borange_backup_latest_snapshot_name\s*\(/', $pre), 'Restore does not call latest-directory guess');
p2_ok(str_contains($admin, "'shared_full_backup_service' => 'orange_backup_admin_run_full_for_api'"), 'restore_admin service id updated');

/* Backup Center immutable path intact */
p2_ok(str_contains($runFull, 'orange_backup_admin_run_full_for_api'), 'Backup run-full unchanged caller');
p2_ok(str_contains($backupAdmin, 'function orange_backup_admin_run_full_for_api'), 'Backup callable present');
p2_ok(!str_contains($backupAdmin, 'function orange_backup_execute_full_authoritative'), 'Backup authoritative wrapper stays absent');

/* No orchestrator / detached Step6 */
$catalog = orange_restore_center_worker_catalog();
p2_ok(!isset($catalog['pre_restore_backup']), 'Step6 not in orchestrator catalog');
p2_ok(!str_contains($orch, "'pre_restore_backup' => 'scripts/backup/restore_prepare_backup.php'"), 'no prepare_backup catalog line');
p2_ok(!str_contains($req, 'attach_verified_schedule'), 'no schedule path');
p2_ok(!str_contains($page, "data-worker': 'pre_restore_backup'"), 'UI no Step6 worker key');
p2_ok(str_contains($page, 'job/request-pre-restore-backup.php'), 'UI posts Step6 endpoint');
p2_ok(str_contains($page, 'disabled: true') && str_contains($page, 'rc-pre-backup-req'), 'grey busy lock retained');

/* Sync HTTP contract markers */
p2_ok(!str_contains($req, 'ignore_user_abort'), 'no ignore_user_abort (sync like Backup Center)');
p2_ok(!str_contains($req, 'set_time_limit'), 'no set_time_limit detach');

/* Functional: failed→pending→running + exact bind + pin via overrides */
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s6p2_' . bin2hex(random_bytes(4));
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
$backupRoot = $tmp . DIRECTORY_SEPARATOR . 'backup';
$snapRoot = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots';
mkdir($workRoot, 0777, true);
mkdir($snapRoot, 0777, true);

$srcPkg = '2026-08-10_030008';
$jobId = '2026-08-10_035058_0bd13c6d_test';
$pkgId = '2026-08-11_120000';
$pkgPath = $snapRoot . DIRECTORY_SEPARATOR . $pkgId;
mkdir($pkgPath, 0777, true);
$schema = defined('ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION')
    ? ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION
    : 124;
file_put_contents($pkgPath . DIRECTORY_SEPARATOR . 'manifest.json', json_encode([
    'schema_revision' => $schema,
    'backup_status' => 'healthy',
    'export_backend' => 'php_pdo',
    'package_type' => 'full_disaster',
], JSON_UNESCAPED_UNICODE));
file_put_contents($pkgPath . DIRECTORY_SEPARATOR . 'health.json', json_encode([
    'package_status' => 'healthy',
], JSON_UNESCAPED_UNICODE));
file_put_contents($pkgPath . DIRECTORY_SEPARATOR . 'checksums.sha256', "abc  database.sql.gz\ndef  uploads.zip\n");
file_put_contents($pkgPath . DIRECTORY_SEPARATOR . 'database.sql.gz', str_repeat('x', 32));
file_put_contents($pkgPath . DIRECTORY_SEPARATOR . 'uploads.zip', str_repeat('y', 32));

require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';

$fwDir = orange_restore_fw_root($workRoot);
if (!is_dir($fwDir)) {
    mkdir($fwDir, 0777, true);
}
$jobDir = orange_restore_fw_job_directory($workRoot, $jobId);
mkdir($jobDir, 0777, true);
$job = [
    'job_id' => $jobId,
    'package_id' => $srcPkg,
    'package_type' => 'full_disaster',
    'package_fingerprint' => 'fp_test',
    'status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED,
    'phase' => ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_FAILED,
    'progress_percent' => 100,
    'execution_started' => false,
];
orange_restore_fw_write($workRoot, $job);

$engineCalls = 0;
$GLOBALS['orange_pre_restore_backup_engine_override'] = static function () use (&$engineCalls, $pkgId): array {
    $engineCalls++;

    return [
        'ok' => true,
        'snapshot' => $pkgId,
        'backend' => 'php_pdo',
        'message' => 'ok',
        'exit_code' => 0,
    ];
};
$GLOBALS['orange_pre_restore_backup_verify_override'] = static function (string $packagePath) use ($schema): array {
    return [
        'ok' => true,
        'errors' => [],
        'manifest' => [
            'schema_revision' => $schema,
            'backup_status' => 'healthy',
            'export_backend' => 'php_pdo',
        ],
        'health' => ['package_status' => 'healthy'],
    ];
};
$GLOBALS['orange_pre_restore_backup_drv_override'] = static function (): array {
    return ['recovery_score' => 95, 'overall_result' => 'pass'];
};
$GLOBALS['orange_pre_restore_backup_revalidate_override'] = static function (
    string $workRoot,
    string $jobId,
    string $backupRoot
): array {
    return [
        'ok' => true,
        'code' => 'ok',
        'job' => orange_restore_fw_read($workRoot, $jobId),
        'contract' => [],
    ];
};

// Pin helper may require retention module; stub via existing pin if available.
if (!function_exists('orange_backup_retention_pin_package')) {
    require_once $projectRoot . '/includes/backup/backup_retention.php';
}

$result = orange_restore_pre_backup_execute($projectRoot, $workRoot, $backupRoot, $jobId, 'phase2_tester');
p2_ok(!empty($result['ok']), 'execute success with Restore-only adapter');
p2_ok($engineCalls === 1, 'engine invoked exactly once');
p2_ok((string) ($result['rollback_package_id'] ?? '') === $pkgId, 'exact package bound');
$fresh = orange_restore_fw_read($workRoot, $jobId);
p2_ok((string) ($fresh['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY, 'status ready');
p2_ok(!empty($result['retention_pinned']) || !empty($fresh['rollback_package_id']), 'pin/bind present');

/* Empty snapshot from Backup result → Restore refuses (no latest guess) */
$job2 = 'job_missing_pkg_' . bin2hex(random_bytes(2));
$jobDir2 = orange_restore_fw_job_directory($workRoot, $job2);
mkdir($jobDir2, 0777, true);
orange_restore_fw_write($workRoot, [
    'job_id' => $job2,
    'package_id' => $srcPkg,
    'package_type' => 'full_disaster',
    'package_fingerprint' => 'fp_test',
    'status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING,
    'phase' => ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_PENDING,
    'progress_percent' => 10,
    'execution_started' => false,
]);
$GLOBALS['orange_pre_restore_backup_engine_override'] = static function (): array {
    return [
        'ok' => true,
        'snapshot' => null,
        'backend' => 'php_pdo',
        'message' => 'ok-without-id',
        'exit_code' => 0,
    ];
};
$missing = orange_restore_pre_backup_execute($projectRoot, $workRoot, $backupRoot, $job2, 'phase2_tester');
p2_ok(empty($missing['ok']), 'empty package id fails Restore bind');
$fresh2 = orange_restore_fw_read($workRoot, $job2);
p2_ok((string) ($fresh2['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED, 'missing id → failed');

/* Cleanup overrides + temp */
unset(
    $GLOBALS['orange_pre_restore_backup_engine_override'],
    $GLOBALS['orange_pre_restore_backup_verify_override'],
    $GLOBALS['orange_pre_restore_backup_drv_override'],
    $GLOBALS['orange_pre_restore_backup_revalidate_override']
);
if (is_dir($tmp)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $p = $f->getPathname();
        $f->isDir() ? @rmdir($p) : @unlink($p);
    }
    @rmdir($tmp);
}

$summary = [
    'registers' => [
        'RESTORE_CENTER_STEP6_RESTORE_ONLY_ADAPTER_01',
        'BACKUP_CENTER_IMMUTABLE_PRODUCTION_BOUNDARY_01',
        'RESTORE_STEP6_EXACT_BACKUP_RESULT_BINDING_01',
        'RESTORE_STEP6_NO_BACKUP_BACKEND_MODIFICATION_01',
        'RESTORE_STEP6_SAME_LIVE_JOB_RETRY_REQUIRED_01',
    ],
    'immutable_callable' => 'orange_backup_admin_run_full_for_api',
    'pass' => $pass,
    'fail' => $fail,
];
file_put_contents(
    $ev . '/restore_only_adapter_phase2_suite.json',
    json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
);

echo 'RESTORE_CENTER_STEP6_RESTORE_ONLY_ADAPTER_PHASE2_PASS=' . ($fail === 0 ? '1' : '0') . "\n";
echo "PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
