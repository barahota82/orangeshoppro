<?php

declare(strict_types=1);

/**
 * Proves Country Backup listing/inventory follow Country Context scope,
 * while Full Backup listing stays global across the same BackupRoot.
 */

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'countries.php';

$failed = 0;
$passed = 0;

function bc_ctx_assert(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) {
        echo "PASS: {$msg}\n";
        $passed++;
    } else {
        echo "FAIL: {$msg}\n";
        $failed++;
    }
}

function bc_ctx_mk_country_pkg(string $root, string $code, string $id, string $generatedAt): void
{
    $dir = $root . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . $code . DIRECTORY_SEPARATOR . $id;
    @mkdir($dir, 0775, true);
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode([
        'generated_at' => $generatedAt,
        'schema_revision' => 122,
        'country_id' => $code === 'kw' ? 1 : 2,
        'registry_version' => '1',
    ], JSON_UNESCAPED_SLASHES));
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'health.json', json_encode(['package_status' => 'healthy']));
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_bc_ctx_scope_' . getmypid();
@mkdir($tmp . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw', 0775, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'ksa', 0775, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'snapshots', 0775, true);

bc_ctx_mk_country_pkg($tmp, 'kw', '2026-07-22_100000', '2026-07-22T10:00:00+00:00');
bc_ctx_mk_country_pkg($tmp, 'kw', '2026-07-22_110000', '2026-07-22T11:00:00+00:00');
bc_ctx_mk_country_pkg($tmp, 'ksa', '2026-07-22_120000', '2026-07-22T12:00:00+00:00');
bc_ctx_mk_country_pkg($tmp, 'ksa', '2026-07-21_090000', '2026-07-21T09:00:00+00:00');

foreach (['2026-07-22_080000', '2026-07-22_210000'] as $fid) {
    $dir = $tmp . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $fid;
    @mkdir($dir, 0775, true);
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode([
        'generated_at' => '2026-07-22T12:00:00+00:00',
        'schema_revision' => 122,
        'export_backend' => 'mysqldump',
    ]));
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'health.json', json_encode(['package_status' => 'healthy']));
}

$pdo = new PDO('sqlite::memory:');

$globalInv = orange_backup_admin_package_inventory_counts($tmp, null);
bc_ctx_assert((int) $globalInv['stored_country_packages_total'] === 4, 'global inventory counts all country packages');
bc_ctx_assert((int) $globalInv['countries_with_packages'] === 2, 'global inventory sees KW + KSA folders');
bc_ctx_assert((int) $globalInv['full_snapshots_total'] === 2, 'Full snapshot inventory stays global');

$kwInv = orange_backup_admin_package_inventory_counts($tmp, 'kw');
bc_ctx_assert((int) $kwInv['stored_country_packages_total'] === 2, 'KW-scoped inventory = 2');
bc_ctx_assert((int) $kwInv['countries_with_packages'] === 1, 'KW-scoped countries_with_packages = 1');
bc_ctx_assert((int) $kwInv['full_snapshots_total'] === 2, 'Full total unchanged under country scope arg');

$ksaInv = orange_backup_admin_package_inventory_counts($tmp, 'ksa');
bc_ctx_assert((int) $ksaInv['stored_country_packages_total'] === 2, 'KSA-scoped inventory = 2');

$kwList = orange_backup_admin_list_country_packages($pdo, $tmp, null, 'kw');
$ksaList = orange_backup_admin_list_country_packages($pdo, $tmp, null, 'ksa');
$allList = orange_backup_admin_list_country_packages($pdo, $tmp, null, null);

bc_ctx_assert(count($kwList) === 2, 'KW context list length = 2');
bc_ctx_assert(count($ksaList) === 2, 'KSA context list length = 2');
bc_ctx_assert(count($allList) === 4, 'null scope still lists all countries (restore/global)');

foreach ($kwList as $pkg) {
    bc_ctx_assert(
        orange_backup_admin_normalize_country_scope_code((string) ($pkg['country_code'] ?? '')) === 'kw',
        'KW list row country_code is KW only: ' . ($pkg['package_id'] ?? '')
    );
}
foreach ($ksaList as $pkg) {
    bc_ctx_assert(
        orange_backup_admin_normalize_country_scope_code((string) ($pkg['country_code'] ?? '')) === 'ksa',
        'KSA list row country_code is KSA only: ' . ($pkg['package_id'] ?? '')
    );
}

$kwIds = array_map(static fn (array $p): string => (string) ($p['package_id'] ?? ''), $kwList);
$ksaIds = array_map(static fn (array $p): string => (string) ($p['package_id'] ?? ''), $ksaList);
bc_ctx_assert(!in_array('2026-07-22_120000', $kwIds, true), 'no KSA package leaks into KW list');
bc_ctx_assert(!in_array('2026-07-22_100000', $ksaIds, true), 'no KW package leaks into KSA list');

$fullA = orange_backup_admin_list_full_snapshots($tmp, 20);
$fullB = orange_backup_admin_list_full_snapshots($tmp, 20);
bc_ctx_assert(count($fullA) === 2 && count($fullB) === 2, 'Full list is global and identical across calls');

$GLOBALS['orange_admin_ctx_country_code'] = 'kw';
$GLOBALS['orange_admin_ctx_country_id'] = 1;
try {
    orange_backup_admin_assert_country_package_in_context($pdo, 'kw');
    bc_ctx_assert(true, 'assert allows KW package under KW context');
} catch (Throwable $e) {
    bc_ctx_assert(false, 'assert allows KW package under KW context');
}
try {
    orange_backup_admin_assert_country_package_in_context($pdo, 'ksa');
    bc_ctx_assert(false, 'assert blocks KSA package under KW context');
} catch (Throwable $e) {
    bc_ctx_assert(true, 'assert blocks KSA package under KW context');
}

$listPhp = (string) file_get_contents(
    $projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'list.php'
);
$runCountries = (string) file_get_contents(
    $projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'run-countries.php'
);
$runFull = (string) file_get_contents(
    $projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'run-full.php'
);
bc_ctx_assert(str_contains($listPhp, '$countryContextCode'), 'list.php reads Admin Country Context for Country Backup');
bc_ctx_assert(str_contains($listPhp, 'list_full_snapshots($backupRoot, 20)'), 'list.php Full path has no country scope');
bc_ctx_assert(
    str_contains($runCountries, 'orange_backup_admin_run_country_batch')
    && !str_contains($runCountries, 'orange_admin_context_country'),
    'Run All Recoverable Countries remains global (no Country Context filter)'
);
bc_ctx_assert(
    str_contains($runFull, 'orange_backup_admin_run_full_for_api')
    && !str_contains($runFull, 'orange_admin_context_country'),
    'Run Full Backup remains global'
);

$verifyPhp = (string) file_get_contents(
    $projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'verify.php'
);
$statusPhp = (string) file_get_contents(
    $projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'status.php'
);
$drvPhp = (string) file_get_contents(
    $projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'recovery-check.php'
);
bc_ctx_assert(str_contains($verifyPhp, 'assert_country_package_in_context'), 'verify guards country packages by context');
bc_ctx_assert(str_contains($statusPhp, 'assert_country_package_in_context'), 'status view_file guards country packages by context');
bc_ctx_assert(str_contains($drvPhp, 'assert_country_package_in_context'), 'DRV guards country packages by context');

// cleanup
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $fileInfo) {
    $p = $fileInfo->getPathname();
    $fileInfo->isDir() ? @rmdir($p) : @unlink($p);
}
@rmdir($tmp);

echo $failed === 0
    ? "OK: backup center country context scope self-test ({$passed} assertions)\n"
    : "FAILED: {$failed} assertion(s), {$passed} passed\n";
exit($failed === 0 ? 0 : 1);
