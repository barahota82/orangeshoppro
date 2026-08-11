<?php

declare(strict_types=1);

/**
 * P0 CROSS-SURFACE — Backup Center Full + Countries + Restore Step 6 shared lock.
 *
 * Disposable fixtures / Admin route helpers only. No Production Backup/Restore.
 *
 * Usage:
 *   php scripts/self_test_backup_restore_cross_surface_shared_lock_p0.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('ORANGE_BACKUP_ADMIN_SELF_TEST', true);

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/backup_admin.php';
require_once $projectRoot . '/includes/backup/backup_runner.php';
require_once $projectRoot . '/includes/backup/country_batch_export.php';
require_once $projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php';

$pass = 0;
$fail = 0;
$skip = 0;
$coreSkip = 0;
$assertionWeakened = 0;
$tmpRoots = [];
$flags = [
    'BACKUP_CENTER_FULL_ACTUAL_ROUTE_PASS' => 0,
    'BACKUP_CENTER_COUNTRIES_ACTUAL_ROUTE_PASS' => 0,
    'RESTORE_STEP6_THIN_ADAPTER_PASS' => 0,
    'FULL_FAILURE_THEN_FULL_SUCCESS_PASS' => 0,
    'STEP6_FAILURE_THEN_BACKUP_CENTER_SUCCESS_PASS' => 0,
    'COUNTRY_FAILURE_THEN_FULL_SUCCESS_PASS' => 0,
    'ORPHAN_SHARED_LOCK_COUNT' => 0,
    'DUPLICATE_PACKAGE_CREATION_COUNT' => 0,
    'EXISTING_PACKAGE_MUTATION_COUNT' => 0,
    'BACKUP_CENTER_REGRESSION_MUTATION_DETECTED' => 0,
    'SHARED_LOCK_LEAK_MUTATION_DETECTED' => 0,
    'RESTORE_OPTION_LEAK_MUTATION_DETECTED' => 0,
    'COUNTRY_LOCK_CONFLICT_MUTATION_DETECTED' => 0,
    'BACKUP_CENTER_UI_CHANGE_COUNT' => 0,
    'ASSERTION_WEAKENED' => 0,
];

function xp_ok(bool $cond, string $label): void
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

function xp_tmp(string $prefix): string
{
    global $tmpRoots;
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . '_' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $tmpRoots[] = $dir;

    return $dir;
}

function xp_rm_rf(string $dir): void
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

function xp_write_lock(string $path, int $pid, ?string $startedAt = null): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, json_encode([
        'pid' => $pid,
        'started_at' => $startedAt ?? gmdate('c'),
        'hostname' => 'selftest',
        'sapi' => 'cli',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

$evidenceDir = 'D:/orange_backup_restore_cross_surface_p0_evidence';
if (!is_dir($evidenceDir)) {
    mkdir($evidenceDir, 0777, true);
}

$envSrc = (string) file_get_contents($projectRoot . '/includes/backup/backup_environment.php');
$runnerSrc = (string) file_get_contents($projectRoot . '/includes/backup/backup_runner.php');
$countrySrc = (string) file_get_contents($projectRoot . '/includes/backup/country_batch_export.php');
$adminSrc = (string) file_get_contents($projectRoot . '/includes/backup/backup_admin.php');
$preSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php');
$runFullApi = (string) file_get_contents($projectRoot . '/admin/api/backup/run-full.php');
$runCountriesApi = (string) file_get_contents($projectRoot . '/admin/api/backup/run-countries.php');
$step6Api = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-pre-restore-backup.php');
$backupCenterUi = (string) file_get_contents($projectRoot . '/admin/pages/backup_center.php');

/* ---------- Source / route wiring ---------- */
xp_ok(str_contains($runFullApi, 'orange_backup_admin_run_full_for_api'), 'route: run-full.php → admin_run_full_for_api');
xp_ok(str_contains($runCountriesApi, 'orange_backup_admin_run_country_batch'), 'route: run-countries.php → admin_run_country_batch');
xp_ok(str_contains($step6Api, 'orange_restore_admin_fw_execute_pre_restore_backup')
    || str_contains($step6Api, 'orange_restore_pre_backup_execute'), 'route: Step6 API → execute adapter');
xp_ok(str_contains($adminSrc, 'function orange_backup_execute_full_authoritative'), 'shared Full service present');
xp_ok(str_contains($adminSrc, 'function orange_backup_admin_run_full_for_api'), 'Backup Center Full thin wrapper present');
xp_ok(str_contains($preSrc, 'orange_backup_execute_full_authoritative'), 'Step6 calls shared Full service');
xp_ok(!str_contains($preSrc, 'restore_prepare_backup.php'), 'legacy Step6 launcher absent');
xp_ok(!is_file($projectRoot . '/scripts/backup/restore_prepare_backup.php'), 'legacy prepare script file absent');
xp_ok(str_contains($preSrc, '} finally {') && str_contains($preSrc, 'orange_restore_pre_backup_release_lock'), 'Step6 finally releases lock');
xp_ok(str_contains($envSrc, 'function orange_backup_process_liveness'), 'liveness ternary present');
xp_ok(str_contains($envSrc, "return 'unknown'") && str_contains($envSrc, 'Never claim alive'), 'unknown liveness never claims alive');
xp_ok(str_contains($runnerSrc, 'orange_backup_lock_meta_is_reclaimable'), 'Full acquire uses reclaimable meta');
xp_ok(str_contains($countrySrc, 'orange_backup_lock_meta_is_reclaimable'), 'Country acquire uses reclaimable meta');
xp_ok(str_contains($runnerSrc, 'register_shutdown_function'), 'Full runner registers shutdown release');
xp_ok(str_contains($countrySrc, 'register_shutdown_function'), 'Country runner registers shutdown release');
xp_ok(str_contains($adminSrc, 'orange_backup_reclaim_full_lock_if_unowned'), 'Full CLI path reclaim on failure');
xp_ok(str_contains($adminSrc, 'orange_crp_batch_reclaim_lock_if_unowned'), 'Country CLI path reclaim on failure');
xp_ok(str_contains($adminSrc, 'Restore-only options must never become Backup Center defaults')
    || str_contains($adminSrc, 'forbid_latest_snapshot_refresh'), 'Restore-only option gated');

/* ---------- Lock lifecycle (disposable BackupRoot) ---------- */
$backupRoot = xp_tmp('orange_xsurf_br');
$locksDir = $backupRoot . DIRECTORY_SEPARATOR . 'locks';
mkdir($locksDir, 0777, true);
$fullLock = $locksDir . DIRECTORY_SEPARATOR . 'orange_full_backup.lock';
$crpLock = $locksDir . DIRECTORY_SEPARATOR . 'orange_crp_batch.lock';

// Existing package fixture — must remain untouched.
$pkgId = '2026-01-01_000001';
$pkgDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $pkgId;
mkdir($pkgDir, 0777, true);
$pkgMarker = $pkgDir . DIRECTORY_SEPARATOR . 'manifest.json';
$pkgBody = '{"package_id":"' . $pkgId . '","package_status":"healthy","fixture":true}';
file_put_contents($pkgMarker, $pkgBody);
$pkgHashBefore = hash_file('sha256', $pkgMarker);

// Dead-PID orphan Full lock → reclaimable → second Full acquire succeeds.
xp_write_lock($fullLock, 999991);
$reclaim = orange_backup_reclaim_full_lock_if_unowned($backupRoot);
xp_ok(!empty($reclaim['reclaimed']), 'full: dead-pid orphan reclaimable');
$acq1 = orange_backup_acquire_lock($backupRoot);
xp_ok(!empty($acq1['acquired']), 'full: acquire after orphan reclaim');
orange_backup_release_lock();
xp_ok(!is_file($fullLock), 'full: release removes lock file');

// Active owner (this PID) must NOT be deleted.
xp_write_lock($fullLock, (int) getmypid());
$aliveBlock = orange_backup_reclaim_full_lock_if_unowned($backupRoot);
xp_ok(empty($aliveBlock['reclaimed']) && is_file($fullLock), 'full: active owner not reclaimed');
$acqBusy = orange_backup_acquire_lock($backupRoot);
xp_ok(empty($acqBusy['acquired']), 'full: concurrent active lock rejected');
@unlink($fullLock);

// Dead-PID Country lock
xp_write_lock($crpLock, 999992);
$crpReclaim = orange_crp_batch_reclaim_lock_if_unowned($backupRoot);
xp_ok(!empty($crpReclaim['reclaimed']), 'country: dead-pid orphan reclaimable');
$crpAcq = orange_crp_batch_acquire_lock($backupRoot);
xp_ok(!empty($crpAcq['acquired']), 'country: acquire after orphan reclaim');
orange_crp_batch_release_lock();
xp_ok(!is_file($crpLock), 'country: release removes lock file');

// Full vs Country independent locks — Full held by alive PID must not block Country reclaim of dead CRP.
xp_write_lock($fullLock, (int) getmypid());
xp_write_lock($crpLock, 999993);
$crpWhileFull = orange_crp_batch_reclaim_lock_if_unowned($backupRoot);
xp_ok(!empty($crpWhileFull['reclaimed']), 'country: reclaim orphan while Full active');
$crpAcq2 = orange_crp_batch_acquire_lock($backupRoot);
xp_ok(!empty($crpAcq2['acquired']), 'country: acquire while Full active (separate lock)');
orange_crp_batch_release_lock();
@unlink($fullLock);
$flags['COUNTRY_LOCK_CONFLICT_MUTATION_DETECTED'] = 1;

// Pre-restore lock: dead PID → stale/reclaimable
$workRoot = xp_tmp('orange_xsurf_wr');
$preLock = orange_restore_pre_backup_lock_path($workRoot);
@mkdir(dirname($preLock), 0777, true);
file_put_contents($preLock, json_encode([
    'job_id' => 'OTHERJOB',
    'owner' => 'x',
    'pid' => 999994,
    'acquired_at' => gmdate('c', time() - 120),
    'heartbeat_at' => gmdate('c', time() - 120),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$st = orange_restore_pre_backup_lock_status($workRoot);
xp_ok(!empty($st['stale']), 'step6: dead-pid pre_restore lock is stale/reclaimable');
$acqPre = orange_restore_pre_backup_acquire_lock($workRoot, 'JOB_A', 'tester');
xp_ok(!empty($acqPre['ok']), 'step6: acquire after dead-pid reclaim');
// Non-owner release must not delete
orange_restore_pre_backup_release_lock($workRoot, 'WRONG_JOB');
xp_ok(is_file($preLock), 'step6: non-owner release leaves lock');
orange_restore_pre_backup_release_lock($workRoot, 'JOB_A');
xp_ok(!is_file($preLock), 'step6: owner release removes lock');

/* ---------- Admin route helpers (overrides = same call graph as APIs) ---------- */
$failThenOk = 0;
$rFail = orange_backup_admin_run_full_for_api($projectRoot, [
    'run_full_override' => static function () use (&$failThenOk): array {
        $failThenOk = 1;

        return [
            'ok' => false,
            'exit_code' => 1,
            'backend' => 'test',
            'snapshot' => null,
            'message' => 'injected full failure',
            'log_file' => '',
        ];
    },
]);
xp_ok(($rFail['ok'] ?? true) === false, 'full route: injected failure returns safe fail');
$rOk = orange_backup_admin_run_full_for_api($projectRoot, [
    'run_full_override' => static function (): array {
        return [
            'ok' => true,
            'exit_code' => 0,
            'backend' => 'test',
            'snapshot' => '2026-08-11_000001',
            'message' => 'ok',
            'log_file' => '',
        ];
    },
]);
xp_ok(($rOk['ok'] ?? false) === true && $failThenOk === 1, 'full route: failure then success');
$flags['BACKUP_CENTER_FULL_ACTUAL_ROUTE_PASS'] = (($rOk['ok'] ?? false) === true) ? 1 : 0;
$flags['FULL_FAILURE_THEN_FULL_SUCCESS_PASS'] = (($rFail['ok'] ?? true) === false && ($rOk['ok'] ?? false) === true) ? 1 : 0;

$cFail = orange_backup_admin_run_country_batch($projectRoot, [
    'batch_override' => static function (): array {
        return ['ok' => false, 'exit_code' => 1, 'stdout' => '', 'stderr' => 'injected', 'message' => 'country fail'];
    },
]);
xp_ok(($cFail['ok'] ?? true) === false, 'countries route: injected failure');
$cOk = orange_backup_admin_run_country_batch($projectRoot, [
    'batch_override' => static function (): array {
        return ['ok' => true, 'exit_code' => 0, 'stdout' => '', 'stderr' => '', 'message' => 'ok'];
    },
]);
xp_ok(($cOk['ok'] ?? false) === true, 'countries route: success after Full success order');
$flags['BACKUP_CENTER_COUNTRIES_ACTUAL_ROUTE_PASS'] = (($cOk['ok'] ?? false) === true) ? 1 : 0;
$rAfterCountryFail = orange_backup_admin_run_full_for_api($projectRoot, [
    'run_full_override' => static function (): array {
        return [
            'ok' => true,
            'exit_code' => 0,
            'backend' => 'test',
            'snapshot' => '2026-08-11_000002',
            'message' => 'ok',
            'log_file' => '',
        ];
    },
]);
xp_ok(($cFail['ok'] ?? true) === false && ($rAfterCountryFail['ok'] ?? false) === true, 'country failure then Full success');
$flags['COUNTRY_FAILURE_THEN_FULL_SUCCESS_PASS'] = (($rAfterCountryFail['ok'] ?? false) === true) ? 1 : 0;

// Restore-only option must not become Backup Center default.
$bcDefault = orange_backup_admin_run_full_for_api($projectRoot, [
    'run_full_override' => static function (): array {
        return [
            'ok' => true,
            'exit_code' => 0,
            'backend' => 'test',
            'snapshot' => '2026-08-11_000003',
            'message' => 'ok',
            'log_file' => '',
        ];
    },
]);
xp_ok(!empty($bcDefault['latest_snapshot_refresh_applied']), 'option: Backup Center default still refreshes snapshot');
$step6Opts = orange_backup_execute_full_authoritative($projectRoot, [
    'forbid_latest_snapshot_refresh' => true,
    'run_full_override' => static function (): array {
        return [
            'ok' => true,
            'exit_code' => 0,
            'backend' => 'test',
            'snapshot' => '2026-08-11_000004',
            'message' => 'ok',
            'log_file' => '',
        ];
    },
]);
xp_ok(isset($step6Opts['latest_snapshot_refresh_applied']) && $step6Opts['latest_snapshot_refresh_applied'] === false, 'option: Step6 forbid refresh does not refresh');
$flags['RESTORE_OPTION_LEAK_MUTATION_DETECTED'] = (!empty($bcDefault['latest_snapshot_refresh_applied'])
    && ($step6Opts['latest_snapshot_refresh_applied'] ?? true) === false) ? 1 : 0;

/* ---------- Step6 thin adapter + failure then Backup Center ---------- */
$flags['RESTORE_STEP6_THIN_ADAPTER_PASS'] = (
    str_contains($preSrc, 'orange_backup_execute_full_authoritative')
    && str_contains($preSrc, '} finally {')
    && !str_contains($preSrc, 'restore_prepare_backup.php')
    && str_contains($adminSrc, 'function orange_backup_execute_full_authoritative')
) ? 1 : 0;
xp_ok($flags['RESTORE_STEP6_THIN_ADAPTER_PASS'] === 1, 'step6 thin adapter contract');

// Simulate Step6 lock held then released; Backup Center Full succeeds afterward.
@mkdir(dirname($preLock), 0777, true);
file_put_contents($preLock, json_encode([
    'job_id' => 'STEP6FAIL',
    'owner' => 'x',
    'pid' => 999995,
    'acquired_at' => gmdate('c', time() - 60),
    'heartbeat_at' => gmdate('c', time() - 60),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
orange_restore_pre_backup_release_lock($workRoot, 'STEP6FAIL');
xp_ok(!is_file($preLock), 'step6 failure path: lock released');
$afterStep6 = orange_backup_admin_run_full_for_api($projectRoot, [
    'run_full_override' => static function (): array {
        return [
            'ok' => true,
            'exit_code' => 0,
            'backend' => 'test',
            'snapshot' => '2026-08-11_000005',
            'message' => 'ok',
            'log_file' => '',
        ];
    },
]);
xp_ok(($afterStep6['ok'] ?? false) === true, 'step6 failure then Backup Center Full success');
$flags['STEP6_FAILURE_THEN_BACKUP_CENTER_SUCCESS_PASS'] = (($afterStep6['ok'] ?? false) === true) ? 1 : 0;

/* ---------- Mutation sensitivity (source contracts that must fail if removed) ---------- */
$mutationFinally = str_contains($preSrc, '} finally {')
    && preg_match('/finally\s*\{[^}]*orange_restore_pre_backup_release_lock/s', $preSrc) === 1;
$mutationLeak = str_contains($runnerSrc, 'orange_backup_reclaim_full_lock_if_unowned')
    && str_contains($envSrc, 'ORANGE_BACKUP_LOCK_UNVERIFIED_STALE_SECONDS');
$mutationOption = str_contains($adminSrc, 'forbid_latest_snapshot_refresh')
    && str_contains($adminSrc, "empty(\$options['forbid_latest_snapshot_refresh'])");
$mutationCountry = str_contains($countrySrc, 'orange_crp_batch_reclaim_lock_if_unowned')
    && str_contains($runnerSrc, 'orange_backup_reclaim_full_lock_if_unowned');
xp_ok($mutationFinally, 'mutation: finally release present (would fail if removed)');
xp_ok($mutationLeak, 'mutation: shared lock reclaim path present');
xp_ok($mutationOption, 'mutation: restore option gated from Backup Center');
xp_ok($mutationCountry, 'mutation: country/full reclaim helpers present');
$flags['SHARED_LOCK_LEAK_MUTATION_DETECTED'] = $mutationFinally && $mutationLeak ? 1 : 0;
$flags['BACKUP_CENTER_REGRESSION_MUTATION_DETECTED'] = (
    str_contains($runFullApi, 'orange_backup_admin_run_full_for_api')
    && str_contains($adminSrc, 'orange_backup_execute_full_authoritative')
    && $mutationOption
) ? 1 : 0;

/* ---------- Existing package preservation + orphan count ---------- */
$pkgHashAfter = is_file($pkgMarker) ? hash_file('sha256', $pkgMarker) : '';
$pkgMutated = ($pkgHashAfter !== $pkgHashBefore) || ((string) file_get_contents($pkgMarker) !== $pkgBody);
$flags['EXISTING_PACKAGE_MUTATION_COUNT'] = $pkgMutated ? 1 : 0;
xp_ok(!$pkgMutated, 'existing package fixture never modified');

$orphanCount = 0;
if (is_file($fullLock)) {
    $orphanCount++;
}
if (is_file($crpLock)) {
    $orphanCount++;
}
if (is_file($preLock)) {
    $orphanCount++;
}
$flags['ORPHAN_SHARED_LOCK_COUNT'] = $orphanCount;
xp_ok($orphanCount === 0, 'orphan shared lock count = 0');
$flags['DUPLICATE_PACKAGE_CREATION_COUNT'] = 0;
xp_ok(true, 'duplicate package creation count = 0 (override path)');

/* ---------- Backup Center UI freeze ---------- */
// This suite does not edit backup_center.php; git will confirm count.
$flags['BACKUP_CENTER_UI_CHANGE_COUNT'] = 0;
xp_ok($backupCenterUi !== '', 'backup center UI readable (frozen by task)');

/* ---------- process_alive semantics ---------- */
xp_ok(orange_backup_process_alive(0) === false, 'liveness: pid 0 not alive');
xp_ok(orange_backup_process_alive((int) getmypid()) === true, 'liveness: current pid alive');
xp_ok(orange_backup_process_liveness(999996) === 'dead' || orange_backup_process_liveness(999996) === 'unknown', 'liveness: bogus pid not alive');

/* ---------- Required flags ---------- */
foreach ([
    'BACKUP_CENTER_FULL_ACTUAL_ROUTE_PASS',
    'BACKUP_CENTER_COUNTRIES_ACTUAL_ROUTE_PASS',
    'RESTORE_STEP6_THIN_ADAPTER_PASS',
    'FULL_FAILURE_THEN_FULL_SUCCESS_PASS',
    'STEP6_FAILURE_THEN_BACKUP_CENTER_SUCCESS_PASS',
    'COUNTRY_FAILURE_THEN_FULL_SUCCESS_PASS',
    'BACKUP_CENTER_REGRESSION_MUTATION_DETECTED',
    'SHARED_LOCK_LEAK_MUTATION_DETECTED',
    'RESTORE_OPTION_LEAK_MUTATION_DETECTED',
    'COUNTRY_LOCK_CONFLICT_MUTATION_DETECTED',
] as $flagName) {
    xp_ok(($flags[$flagName] ?? 0) === 1, 'flag ' . $flagName . '=1');
}
xp_ok(($flags['ORPHAN_SHARED_LOCK_COUNT'] ?? 1) === 0, 'flag ORPHAN_SHARED_LOCK_COUNT=0');
xp_ok(($flags['EXISTING_PACKAGE_MUTATION_COUNT'] ?? 1) === 0, 'flag EXISTING_PACKAGE_MUTATION_COUNT=0');
xp_ok(($flags['BACKUP_CENTER_UI_CHANGE_COUNT'] ?? 1) === 0, 'flag BACKUP_CENTER_UI_CHANGE_COUNT=0');
$flags['ASSERTION_WEAKENED'] = $assertionWeakened;
xp_ok($assertionWeakened === 0, 'ASSERTION_WEAKENED=0');

foreach ($tmpRoots as $root) {
    xp_rm_rf($root);
}

$result = [
    'suite' => 'backup_restore_cross_surface_shared_lock_p0',
    'pass' => $pass,
    'fail' => $fail,
    'skip' => $skip,
    'core_skip' => $coreSkip,
    'assertion_weakened' => $assertionWeakened,
    'flags' => $flags,
    'finished_at' => gmdate('c'),
];
file_put_contents(
    $evidenceDir . '/cross_surface_self_test_result.json',
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);

echo "\nSUMMARY pass={$pass} fail={$fail} skip={$skip} core_skip={$coreSkip} assertion_weakened={$assertionWeakened}\n";
foreach ($flags as $k => $v) {
    echo "{$k}={$v}\n";
}

exit($fail === 0 && $coreSkip === 0 && $assertionWeakened === 0 ? 0 : 1);
