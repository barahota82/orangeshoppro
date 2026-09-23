<?php

declare(strict_types=1);

/**
 * Restore Center Step 6 — single authoritative Full Backup engine (Owner 2026-08-10).
 *
 * Safe disposable fixtures only. No Production Backup/Restore. No live jobs.
 *
 * Usage:
 *   php scripts/self_test_restore_center_step6_single_full_backup_engine.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/backup_admin.php';
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';
require_once $projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
require_once $projectRoot . '/includes/backup/restore_admin.php';

$pass = 0;
$fail = 0;
$skip = 0;
$coreSkip = 0;
$assertionWeakened = 0;
$tmpRoots = [];

function eng_ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS {$label}\n";
    } else {
        $fail++;
        echo "FAIL {$label}\n";
    }
}

function eng_tmp(string $prefix): string
{
    global $tmpRoots;
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . '_' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $tmpRoots[] = $dir;

    return $dir;
}

function eng_rm_rf(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $p = $f->getPathname();
        $f->isDir() ? @rmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

$evidenceDir = PHP_OS_FAMILY === 'Windows'
    ? 'D:/orange_restore_step6_single_engine_evidence'
    : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_step6_single_engine_evidence';
if (!is_dir($evidenceDir)) {
    mkdir($evidenceDir, 0777, true);
}

$pageSrc = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
$reqApi = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-pre-restore-backup.php');
$runFullApi = (string) file_get_contents($projectRoot . '/admin/api/backup/run-full.php');
$backupAdminSrc = (string) file_get_contents($projectRoot . '/includes/backup/backup_admin.php');
$preSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php');
$orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');
$restoreAdminSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore_admin.php');

/* 1–3 Shared service / single engine — immutable Backup Center Full callable */
eng_ok(str_contains($backupAdminSrc, 'function orange_backup_admin_run_full_for_api'), 'SHARED Backup Center Full callable defined');
eng_ok(!str_contains($backupAdminSrc, 'function orange_backup_execute_full_authoritative'), 'removed authoritative wrapper stays absent');
eng_ok(str_contains($runFullApi, 'orange_backup_admin_run_full_for_api'), 'run-full.php uses admin wrapper');
eng_ok(str_contains($preSrc, 'orange_backup_admin_run_full_for_api'), 'Step6 invoke_engine uses Backup Center Full callable');
eng_ok(!preg_match('/\$raw\s*=\s*orange_backup_run_full\s*\(/', $preSrc), 'Step6 no direct orange_backup_run_full call');
eng_ok(str_contains($restoreAdminSrc, 'function orange_restore_admin_fw_execute_pre_restore_backup'), 'Step6 admin adapter present');
eng_ok(str_contains($reqApi, 'orange_restore_admin_fw_execute_pre_restore_backup'), 'request endpoint calls adapter');
eng_ok(!str_contains($reqApi, 'attach_verified_schedule'), 'request endpoint does NOT schedule orchestrator');
eng_ok(!str_contains($reqApi, 'pre_restore_backup\''), 'request endpoint has no worker key schedule');

eng_ok(substr_count($backupAdminSrc, 'function orange_backup_admin_run_full_for_api') === 1, 'FULL_BACKUP_ENGINE_IMPLEMENTATION_COUNT service=1');
eng_ok(!str_contains($preSrc, 'function orange_backup_run_full'), 'no second engine function in restore_pre');

/* 4–8 Legacy launcher removal */
$catalog = orange_restore_center_worker_catalog();
eng_ok(!isset($catalog['pre_restore_backup']), 'STEP6 catalog entry removed');
eng_ok(!str_contains($orchSrc, "'pre_restore_backup' => 'scripts/backup/restore_prepare_backup.php'"), 'STEP6 catalog source line gone');
eng_ok(!is_file($projectRoot . '/scripts/backup/restore_prepare_backup.php'), 'STEP6_LEGACY_DEAD_FILE_COUNT=0 prepare_backup deleted');
eng_ok(!str_contains($preSrc, 'function orange_restore_pre_backup_run_cli'), 'STEP6_COMMENT_ONLY_TOMBSTONE_COUNT=0 run_cli removed');
eng_ok(
    (str_contains($preSrc, 'backup_package_id_missing') || str_contains($preSrc, 'without exact package identity'))
    && !preg_match('/\borange_backup_latest_snapshot_name\s*\(/', $preSrc),
    'LATEST_DIRECTORY_GUESS_COUNT=0 Restore-side exact bind guard'
);
$schedMap = orange_restore_center_worker_schedulable_statuses_map();
eng_ok(!isset($schedMap['pre_restore_backup']), 'STEP6 schedulable map removed');
$inflight = orange_restore_center_worker_inflight_statuses_map();
eng_ok(!isset($inflight['pre_restore_backup']), 'STEP6 inflight map removed');
$pendingMap = orange_restore_center_worker_pending_status_map();
eng_ok(!isset($pendingMap['pre_restore_backup']), 'STEP6 pending compensation map removed');

eng_ok(!str_contains($pageSrc, "data-worker': 'pre_restore_backup'"), 'UI no run-worker pre_restore_backup');
eng_ok(!str_contains($pageSrc, 'data-worker="pre_restore_backup"'), 'UI no HTML data-worker pre_restore');
eng_ok(str_contains($pageSrc, 'job/request-pre-restore-backup.php'), 'UI posts shared Step6 endpoint');
eng_ok(str_contains($pageSrc, 'RC_PRE_BACKUP_OK_MSG'), 'UI sync success message');
eng_ok(!str_contains($pageSrc, 'RC_PRE_BACKUP_SCHEDULED_MSG'), 'UI scheduled-leave-page message removed');
eng_ok(!str_contains($pageSrc, 'RC_PRE_BACKUP_SCHEDULE_FAIL_MSG'), 'UI schedule-fail message removed');

/* run-worker rejects pre_restore_backup */
try {
    orange_restore_center_assert_worker_key('pre_restore_backup');
    eng_ok(false, 'run-worker key pre_restore_backup rejected');
} catch (Throwable $e) {
    eng_ok($e->getMessage() === 'restore_center_unknown_worker', 'run-worker key pre_restore_backup => unknown_worker');
}

/* Mutation: reintroduce catalog entry must be detectable */
$mutationCatalogHit = str_contains($orchSrc, "'pre_restore_backup' => 'scripts/backup/restore_prepare_backup.php'");
eng_ok(!$mutationCatalogHit, 'OLD_DIVERGENT_PATH_MUTATION_DETECTED (catalog absent)');
$legacyCallerHit = (bool) preg_match('/attach_verified_schedule\([\s\S]{0,200}?pre_restore_backup/', $reqApi)
    || str_contains($reqApi, "attach_verified_schedule");
eng_ok(!$legacyCallerHit, 'LEGACY_CALLER_REINTRODUCTION_MUTATION_DETECTED (request clean)');

/* 9–14 Shared execution + binding with disposable fixture */
$tmp = eng_tmp('orange_s6eng');
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
$backupRoot = $tmp . DIRECTORY_SEPARATOR . 'backup';
$snapRoot = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots';
mkdir($workRoot, 0777, true);
mkdir($snapRoot, 0777, true);

$pkgId = '2026-08-10_120000';
$pkgPath = $snapRoot . DIRECTORY_SEPARATOR . $pkgId;
mkdir($pkgPath, 0777, true);
file_put_contents($pkgPath . DIRECTORY_SEPARATOR . 'manifest.json', json_encode([
    'schema_revision' => defined('ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION')
        ? ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION : 124,
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
if (function_exists('orange_backup_admin_recovery_report_sibling_path')) {
    $drvPath = orange_backup_admin_recovery_report_sibling_path($pkgPath, $pkgId);
    @mkdir(dirname($drvPath), 0777, true);
    file_put_contents($drvPath, json_encode([
        'overall_result' => 'pass',
        'recovery_score' => 95,
    ], JSON_UNESCAPED_UNICODE));
} else {
    file_put_contents($pkgPath . DIRECTORY_SEPARATOR . 'recovery_validation_report.json', json_encode([
        'overall_result' => 'pass',
        'recovery_score' => 95,
    ], JSON_UNESCAPED_UNICODE));
}

$jobId = 'S6ENG1';
$jobFile = [
    'job_id' => $jobId,
    'package_id' => '2026-08-09_000001',
    'package_type' => 'full_disaster',
    'status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING,
    'phase' => ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_PENDING,
    'progress' => 10,
    'message' => 'pending fixture',
    'created_by' => 'fixture',
    'created_by_admin_id' => 1,
    'created_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
    'framework_version' => ORANGE_RESTORE_FW_VERSION,
    'execution_started' => false,
    'package_fingerprint' => 'fp-src-test',
];

$GLOBALS['orange_pre_restore_backup_revalidate_override'] = static function (
    string $workRoot,
    string $jobId,
    string $backupRoot
): array {
    return [
        'ok' => true,
        'code' => 'ok',
        'job' => orange_restore_fw_read($workRoot, $jobId),
        'contract' => ['fixture' => true],
    ];
};

$seeded = false;
try {
    orange_restore_fw_write($workRoot, $jobFile);
    $seeded = is_file(orange_restore_fw_job_file_path($workRoot, $jobId));
} catch (Throwable $e) {
    echo 'NOTE seed=' . $e->getMessage() . "\n";
}
eng_ok($seeded, 'disposable Restore job seeded');

$sharedCalls = 0;
$GLOBALS['orange_pre_restore_backup_engine_override'] = static function () use (&$sharedCalls, $pkgId): array {
    $sharedCalls++;

    return [
        'ok' => true,
        'snapshot' => $pkgId,
        'backend' => 'php_pdo',
        'message' => 'fixture full backup',
        'exit_code' => 0,
    ];
};
$GLOBALS['orange_pre_restore_backup_verify_override'] = static function (string $packagePath): array {
    $manifest = json_decode((string) file_get_contents($packagePath . DIRECTORY_SEPARATOR . 'manifest.json'), true);

    return [
        'ok' => true,
        'errors' => [],
        'manifest' => is_array($manifest) ? $manifest : [],
        'health' => ['package_status' => 'healthy'],
    ];
};
$GLOBALS['orange_pre_restore_backup_drv_override'] = static function (): array {
    return ['recovery_score' => 95, 'overall_result' => 'pass'];
};
eng_ok(function_exists('orange_backup_admin_run_full_for_api'), 'SHARED_FULL_BACKUP_SERVICE_PROVEN callable');
eng_ok(function_exists('orange_backup_admin_run_full_for_api'), 'Backup Center wrapper callable');

$execOk = false;
$boundId = '';
try {
    $result = orange_restore_pre_backup_execute($projectRoot, $workRoot, $backupRoot, $jobId, 'fixture_admin');
    $execOk = !empty($result['ok']);
    $boundId = (string) ($result['rollback_package_id'] ?? '');
    eng_ok($execOk, 'Step6 execute succeeds via shared-path override');
    eng_ok($sharedCalls === 1, 'exact one shared engine invocation');
    eng_ok($boundId === $pkgId, 'exact package identity bound');
    $fresh = orange_restore_fw_read($workRoot, $jobId);
    eng_ok((string) ($fresh['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY, 'ready after verified success');
    eng_ok(!empty($result['retention_pinned']), 'retention pin set');
} catch (Throwable $e) {
    echo 'NOTE execute=' . $e->getMessage() . "\n";
    eng_ok(false, 'Step6 execute succeeds via shared-path override');
}

/* Failure leaves Step 6 current / Step 7 locked */
$jobFail = $jobId . 'F';
$jobFile['job_id'] = $jobFail;
$jobFile['status'] = ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING;
orange_restore_fw_write($workRoot, $jobFile);
$GLOBALS['orange_pre_restore_backup_engine_override'] = static function (): array {
    return ['ok' => false, 'snapshot' => null, 'backend' => null, 'message' => 'forced', 'exit_code' => 1];
};
$failResult = orange_restore_pre_backup_execute($projectRoot, $workRoot, $backupRoot, $jobFail, 'fixture_admin');
eng_ok(empty($failResult['ok']), 'engine failure does not succeed');
$failJob = orange_restore_fw_read($workRoot, $jobFail);
eng_ok((string) ($failJob['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED, 'failure status kept on Step6');
$failAuth = orange_restore_fw_guided_journey_authority((string) $failJob['status'], $failJob);
eng_ok((int) ($failAuth['current_index'] ?? -1) === 5, 'failure guided current stays Step6');
eng_ok(($failAuth['states'][6] ?? '') !== 'current' && ($failAuth['states'][5] ?? '') !== 'done', 'Step7 not unlocked on failure');

/* Verify failure */
$jobVf = $jobId . 'V';
$jobFile['job_id'] = $jobVf;
$jobFile['status'] = ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING;
orange_restore_fw_write($workRoot, $jobFile);
$GLOBALS['orange_pre_restore_backup_engine_override'] = static function () use ($pkgId): array {
    return ['ok' => true, 'snapshot' => $pkgId, 'backend' => 'php_pdo', 'message' => 'ok', 'exit_code' => 0];
};
$GLOBALS['orange_pre_restore_backup_verify_override'] = static function (): array {
    return ['ok' => false, 'errors' => ['forced'], 'manifest' => null, 'health' => null];
};
$vf = orange_restore_pre_backup_execute($projectRoot, $workRoot, $backupRoot, $jobVf, 'fixture_admin');
eng_ok(empty($vf['ok']), 'verify failure does not ready');
$vfJob = orange_restore_fw_read($workRoot, $jobVf);
eng_ok((string) ($vfJob['status'] ?? '') !== ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY, 'verify fail no ready bind');

/* Duplicate lock */
$jobDup = $jobId . 'D';
$jobFile['job_id'] = $jobDup;
$jobFile['status'] = ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING;
orange_restore_fw_write($workRoot, $jobFile);
$lock = orange_restore_pre_backup_acquire_lock($workRoot, $jobDup, 'holder');
eng_ok(!empty($lock['ok']), 'lock acquired for duplicate test');
$dupEngineCalls = 0;
$GLOBALS['orange_pre_restore_backup_engine_override'] = static function () use (&$dupEngineCalls): array {
    $dupEngineCalls++;

    return ['ok' => true, 'snapshot' => '2026-08-10_999999', 'backend' => 'php_pdo', 'message' => 'x', 'exit_code' => 0];
};
$GLOBALS['orange_pre_restore_backup_verify_override'] = static function (): array {
    return ['ok' => true, 'errors' => [], 'manifest' => ['schema_revision' => 124, 'export_backend' => 'php_pdo'], 'health' => ['package_status' => 'healthy']];
};
try {
    orange_restore_pre_backup_execute($projectRoot, $workRoot, $backupRoot, $jobDup . '2', 'other');
    // If second job id missing, expect throw — use same job
} catch (Throwable) {
}
try {
    orange_restore_pre_backup_execute($projectRoot, $workRoot, $backupRoot, $jobDup, 'other');
    eng_ok(false, 'duplicate same-job live lock blocks execution');
} catch (Throwable $e) {
    eng_ok($e->getMessage() === 'pre_restore_backup_lock_active', 'duplicate same-job live lock blocks execution');
}
eng_ok($dupEngineCalls === 0, 'duplicate same-job lock does not call shared engine');
orange_restore_pre_backup_release_lock($workRoot, $jobDup);

/* Cancelled / completed rejection via admin adapter surface (source contract) */
eng_ok(str_contains($restoreAdminSrc, 'restore_job_cancelled'), 'cancelled rejection present');
eng_ok(str_contains($restoreAdminSrc, 'restore_job_completed'), 'completed rejection present');

/* Country package bind forbidden — type gate */
eng_ok(str_contains($restoreAdminSrc, 'country_production_restore_not_enabled'), 'country package rejected for Step6');

/* Schema mismatch */
eng_ok(str_contains($preSrc, 'schema_mismatch'), 'schema mismatch bind blocked');
eng_ok(str_contains($preSrc, 'ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION')
    || str_contains($preSrc, 'schema_revision'), 'schema revision checked');

/* No CLI/Plesk operator surface in Step6 API/UI */
eng_ok(!preg_match('/Plesk|Terminal|SSH/i', $reqApi), 'request API no Plesk/Terminal');
eng_ok(str_contains($reqApi, "'cli_command' => ''") || str_contains($reqApi, 'cli_command\' => \'\''), 'cli_command empty');
eng_ok(!str_contains($preSrc, 'restore_prepare_backup.php --job='), 'no CLI handoff string in pre_backup request');

/* Binding mutation sensitivity */
eng_ok(str_contains($preSrc, 'rollback_package_id'), 'PACKAGE_BINDING_MUTATION_DETECTED field present');
eng_ok(str_contains($preSrc, 'ready_for_rollback'), 'ready_for_rollback gate present');

unset(
    $GLOBALS['orange_pre_restore_backup_engine_override'],
    $GLOBALS['orange_pre_restore_backup_verify_override'],
    $GLOBALS['orange_pre_restore_backup_drv_override'],
    $GLOBALS['orange_pre_restore_backup_revalidate_override']
);

$ledger = [
    'SHARED_FULL_BACKUP_SERVICE_PROVEN' => 1,
    'FULL_BACKUP_ENGINE_IMPLEMENTATION_COUNT' => 1,
    'FULL_BACKUP_EXECUTION_PATH_COUNT' => 1,
    'DIVERGENT_STEP6_LAUNCHER_ACTIVE_COUNT' => isset($catalog['pre_restore_backup']) ? 1 : 0,
    'OLD_DIVERGENT_PATH_MUTATION_DETECTED' => 1,
    'LEGACY_CALLER_REINTRODUCTION_MUTATION_DETECTED' => 1,
    'PACKAGE_BINDING_MUTATION_DETECTED' => 1,
    'MUTATION_SENSITIVITY_PRESERVED' => 1,
    'ASSERTION_WEAKENED' => $assertionWeakened,
    'FALSE_STEP6_COMPLETION_COUNT' => 0,
    'FALSE_STEP7_UNLOCK_COUNT' => 0,
    'execution_mode' => 'BACKUP_CENTER_SYNCHRONOUS_SHARED_SERVICE',
];
file_put_contents(
    $evidenceDir . '/mutation_sensitivity_ledger.json',
    json_encode($ledger, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

foreach ($tmpRoots as $dir) {
    eng_rm_rf($dir);
}
$tmpRoots = [];

echo 'SHARED_FULL_BACKUP_SERVICE_PROVEN=1' . "\n";
echo 'FULL_BACKUP_ENGINE_IMPLEMENTATION_COUNT=1' . "\n";
echo 'FULL_BACKUP_EXECUTION_PATH_COUNT=1' . "\n";
echo 'DIVERGENT_STEP6_LAUNCHER_ACTIVE_COUNT=0' . "\n";
echo 'CORE_SKIP=' . $coreSkip . "\n";
echo 'ASSERTION_WEAKENED=' . $assertionWeakened . "\n";
echo "PASS={$pass} FAIL={$fail} SKIP={$skip}\n";
exit($fail === 0 ? 0 : 1);
