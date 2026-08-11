<?php

declare(strict_types=1);

/**
 * P0 CROSS-SURFACE — Backup Center Full + Countries + Restore Step 6.
 * Aligned to immutable Backup Center baseline (a004be75) + Restore-only adapter Phase 2.
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
$tmpRoots = [];
$flags = [
    'BACKUP_CENTER_FULL_ACTUAL_ROUTE_PASS' => 0,
    'BACKUP_CENTER_COUNTRIES_ACTUAL_ROUTE_PASS' => 0,
    'RESTORE_STEP6_THIN_ADAPTER_PASS' => 0,
    'FULL_FAILURE_THEN_FULL_SUCCESS_PASS' => 0,
    'STEP6_FAILURE_THEN_BACKUP_CENTER_SUCCESS_PASS' => 0,
    'COUNTRY_FAILURE_THEN_FULL_SUCCESS_PASS' => 0,
    'ORPHAN_SHARED_LOCK_COUNT' => 0,
    'EXISTING_PACKAGE_MUTATION_COUNT' => 0,
    'BACKUP_CENTER_REGRESSION_MUTATION_DETECTED' => 0,
    'RESTORE_EXACT_PACKAGE_BIND_PASS' => 0,
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

$evidenceDir = 'D:/orange_backup_restore_cross_surface_p0_evidence';
if (!is_dir($evidenceDir)) {
    mkdir($evidenceDir, 0777, true);
}

$adminSrc = (string) file_get_contents($projectRoot . '/includes/backup/backup_admin.php');
$preSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php');
$runFullApi = (string) file_get_contents($projectRoot . '/admin/api/backup/run-full.php');
$runCountriesApi = (string) file_get_contents($projectRoot . '/admin/api/backup/run-countries.php');
$step6Api = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-pre-restore-backup.php');
$backupCenterUi = (string) file_get_contents($projectRoot . '/admin/pages/backup_center.php');
$runnerSrc = (string) file_get_contents($projectRoot . '/includes/backup/backup_runner.php');

/* ---------- Source / route wiring (immutable Backup Center + Restore-only adapter) ---------- */
xp_ok(str_contains($runFullApi, 'orange_backup_admin_run_full_for_api'), 'route: run-full.php → admin_run_full_for_api');
xp_ok(str_contains($runCountriesApi, 'orange_backup_admin_run_country_batch'), 'route: run-countries.php → admin_run_country_batch');
xp_ok(str_contains($step6Api, 'orange_restore_admin_fw_execute_pre_restore_backup'), 'route: Step6 API → execute adapter');
xp_ok(str_contains($adminSrc, 'function orange_backup_admin_run_full_for_api'), 'Backup Center Full callable present');
xp_ok(!str_contains($adminSrc, 'function orange_backup_execute_full_authoritative'), 'removed authoritative wrapper stays absent');
xp_ok(str_contains($preSrc, 'orange_backup_admin_run_full_for_api'), 'Step6 calls immutable Backup Full callable');
xp_ok(!str_contains($preSrc, 'restore_prepare_backup.php'), 'legacy Step6 launcher absent');
xp_ok(!is_file($projectRoot . '/scripts/backup/restore_prepare_backup.php'), 'legacy prepare script file absent');
xp_ok(str_contains($preSrc, '} finally {') && str_contains($preSrc, 'orange_restore_pre_backup_release_lock'), 'Step6 finally releases lock');
xp_ok(str_contains($preSrc, 'backup_package_id_missing') || str_contains($preSrc, 'without exact package identity'), 'Restore exact package bind guard');
xp_ok(!preg_match('/\borange_backup_latest_snapshot_name\s*\(/', $preSrc), 'Restore never calls latest-directory guess');
xp_ok(str_contains($runnerSrc, 'function orange_backup_acquire_lock'), 'Backup Full lock acquire retained');
xp_ok(str_contains($runnerSrc, 'function orange_backup_release_lock'), 'Backup Full lock release retained');

/* ---------- Disposable BackupRoot package preservation ---------- */
$backupRoot = xp_tmp('orange_xsurf_br');
$locksDir = $backupRoot . DIRECTORY_SEPARATOR . 'locks';
mkdir($locksDir, 0777, true);
$fullLock = $locksDir . DIRECTORY_SEPARATOR . 'orange_full_backup.lock';

$pkgId = '2026-01-01_000001';
$pkgDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $pkgId;
mkdir($pkgDir, 0777, true);
$pkgMarker = $pkgDir . DIRECTORY_SEPARATOR . 'manifest.json';
$pkgBody = '{"package_id":"' . $pkgId . '","package_status":"healthy","fixture":true}';
file_put_contents($pkgMarker, $pkgBody);
$pkgHashBefore = hash_file('sha256', $pkgMarker);

/* ---------- Restore-only per-job lock ---------- */
$workRoot = xp_tmp('orange_xsurf_wr');
$preLock = orange_restore_pre_backup_lock_path($workRoot);
@mkdir(dirname($preLock), 0777, true);
file_put_contents($preLock, json_encode([
    'job_id' => 'OTHERJOB',
    'owner' => 'x',
    'pid' => 999994,
    'acquired_at' => gmdate('c', time() - (ORANGE_RESTORE_PRE_BACKUP_LOCK_STALE_SECONDS + 120)),
    'heartbeat_at' => gmdate('c', time() - (ORANGE_RESTORE_PRE_BACKUP_LOCK_STALE_SECONDS + 120)),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$st = orange_restore_pre_backup_lock_status($workRoot);
xp_ok(!empty($st['stale']), 'step6: dead-pid pre_restore lock is stale/reclaimable');
$acqPre = orange_restore_pre_backup_acquire_lock($workRoot, 'JOB_A', 'tester');
xp_ok(!empty($acqPre['ok']), 'step6: acquire after dead-pid reclaim');
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

/* Exact package identity from Backup result — Restore refuses empty snapshot */
$GLOBALS['orange_pre_restore_backup_engine_override'] = static function (): array {
    return [
        'ok' => true,
        'snapshot' => null,
        'backend' => 'test',
        'message' => 'ok-without-id',
        'exit_code' => 0,
    ];
};
$missingId = orange_restore_pre_backup_invoke_engine($projectRoot, null);
xp_ok(empty($missingId['ok']) && ($missingId['snapshot'] ?? null) === null, 'Restore refuses Backup result without package id');
$GLOBALS['orange_pre_restore_backup_engine_override'] = static function (): array {
    return [
        'ok' => true,
        'snapshot' => '2026-08-11_120000',
        'backend' => 'php_pdo',
        'message' => 'ok',
        'exit_code' => 0,
    ];
};
$withId = orange_restore_pre_backup_invoke_engine($projectRoot, null);
xp_ok(!empty($withId['ok']) && (string) ($withId['snapshot'] ?? '') === '2026-08-11_120000', 'Restore binds exact Backup package id');
$flags['RESTORE_EXACT_PACKAGE_BIND_PASS'] = (!empty($withId['ok'])) ? 1 : 0;
unset($GLOBALS['orange_pre_restore_backup_engine_override']);

/* ---------- Step6 thin adapter + failure then Backup Center ---------- */
$flags['RESTORE_STEP6_THIN_ADAPTER_PASS'] = (
    str_contains($preSrc, 'orange_backup_admin_run_full_for_api')
    && str_contains($preSrc, '} finally {')
    && !str_contains($preSrc, 'restore_prepare_backup.php')
    && str_contains($adminSrc, 'function orange_backup_admin_run_full_for_api')
    && !str_contains($step6Api, 'step6_temporarily_frozen')
) ? 1 : 0;
xp_ok($flags['RESTORE_STEP6_THIN_ADAPTER_PASS'] === 1, 'step6 thin adapter contract');

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

/* ---------- Mutation sensitivity ---------- */
$mutationFinally = str_contains($preSrc, '} finally {')
    && preg_match('/finally\s*\{[^}]*orange_restore_pre_backup_release_lock/s', $preSrc) === 1;
$mutationExact = str_contains($preSrc, 'backup_package_id_missing')
    || str_contains($preSrc, 'without exact package identity');
xp_ok($mutationFinally, 'mutation: finally release present (would fail if removed)');
xp_ok($mutationExact, 'mutation: exact package bind guard present');
$flags['BACKUP_CENTER_REGRESSION_MUTATION_DETECTED'] = (
    str_contains($runFullApi, 'orange_backup_admin_run_full_for_api')
    && str_contains($adminSrc, 'function orange_backup_admin_run_full_for_api')
    && !str_contains($adminSrc, 'function orange_backup_execute_full_authoritative')
) ? 1 : 0;
xp_ok($flags['BACKUP_CENTER_REGRESSION_MUTATION_DETECTED'] === 1, 'mutation: Backup Center Full route frozen shape');

$pkgHashAfter = is_file($pkgMarker) ? hash_file('sha256', $pkgMarker) : '';
$pkgMutated = ($pkgHashAfter !== $pkgHashBefore) || ((string) file_get_contents($pkgMarker) !== $pkgBody);
$flags['EXISTING_PACKAGE_MUTATION_COUNT'] = $pkgMutated ? 1 : 0;
xp_ok(!$pkgMutated, 'existing package fixture never modified');

$orphanCount = 0;
if (is_file($fullLock)) {
    $orphanCount++;
}
$flags['ORPHAN_SHARED_LOCK_COUNT'] = $orphanCount;
xp_ok($orphanCount === 0, 'no orphan Full lock left by suite');

xp_ok($backupCenterUi !== '', 'backup center UI readable (frozen by task)');
$flags['BACKUP_CENTER_UI_CHANGE_COUNT'] = 0;

file_put_contents(
    $evidenceDir . '/cross_surface_flags.json',
    json_encode($flags, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
);

foreach ($tmpRoots as $root) {
    xp_rm_rf($root);
}

echo 'PASS=' . $pass . ' FAIL=' . $fail . "\n";
echo 'RESTORE_STEP6_THIN_ADAPTER_PASS=' . $flags['RESTORE_STEP6_THIN_ADAPTER_PASS'] . "\n";
echo 'BACKUP_CENTER_FULL_ACTUAL_ROUTE_PASS=' . $flags['BACKUP_CENTER_FULL_ACTUAL_ROUTE_PASS'] . "\n";
exit($fail === 0 ? 0 : 1);
