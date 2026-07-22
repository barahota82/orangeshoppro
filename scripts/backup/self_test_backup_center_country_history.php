<?php

declare(strict_types=1);

/**
 * Proves Country package listing counts every finalized package id (including same-day),
 * and that Backup Center list.php no longer applies a per-country cap of 5.
 */

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php';

$failed = 0;
$passed = 0;

function bc_country_hist_assert(bool $cond, string $msg): void
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

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_bc_country_hist_' . getmypid();
@mkdir($tmp . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw', 0775, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'snapshots', 0775, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'logs', 0775, true);

// Same calendar day, three distinct package ids (Full Backup pattern: date_time identity).
$sameDayIds = [
    '2026-07-22_100000',
    '2026-07-22_150000',
    '2026-07-22_230000',
];
// Plus older days to exceed the old per-country cap of 5.
$extraIds = [
    '2026-07-21_120000',
    '2026-07-20_120000',
    '2026-07-19_120000',
    '2026-07-18_120000',
];
$allIds = array_merge($sameDayIds, $extraIds);
foreach ($allIds as $id) {
    $dir = $tmp . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw' . DIRECTORY_SEPARATOR . $id;
    @mkdir($dir, 0775, true);
    $day = substr($id, 0, 10);
    $hh = substr($id, 11, 2);
    $mm = substr($id, 13, 2);
    $ss = substr($id, 15, 2);
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode([
        'generated_at' => "{$day}T{$hh}:{$mm}:{$ss}+00:00",
        'schema_revision' => 121,
        'country_id' => 1,
        'registry_version' => '1',
    ], JSON_UNESCAPED_SLASHES));
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'health.json', json_encode(['package_status' => 'healthy']));
}

// Two Full snapshots same day for comparison.
foreach (['2026-07-22_080000', '2026-07-22_210000'] as $fid) {
    $dir = $tmp . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $fid;
    @mkdir($dir, 0775, true);
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode([
        'generated_at' => '2026-07-22T12:00:00+00:00',
        'schema_revision' => 121,
        'export_backend' => 'mysqldump',
    ]));
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'health.json', json_encode(['package_status' => 'healthy']));
}

$pdo = new PDO('sqlite::memory:');

$inventory = orange_backup_admin_package_inventory_counts($tmp);
bc_country_hist_assert(
    (int) $inventory['stored_country_packages_total'] === count($allIds),
    'inventory total equals finalized country package directories on disk (' . count($allIds) . ')'
);
bc_country_hist_assert(
    (int) $inventory['full_snapshots_total'] === 2,
    'inventory full snapshot total counts both same-day Full packages'
);

$capped = orange_backup_admin_list_country_packages($pdo, $tmp, 5);
bc_country_hist_assert(count($capped) === 5, 'explicit per-country cap=5 still available for restore pickers');

$uncapped = orange_backup_admin_list_country_packages($pdo, $tmp, null);
bc_country_hist_assert(
    count($uncapped) === count($allIds),
    'null per-country limit returns every finalized country package'
);

$ids = array_map(static fn (array $p): string => (string) ($p['package_id'] ?? ''), $uncapped);
foreach ($sameDayIds as $sid) {
    bc_country_hist_assert(in_array($sid, $ids, true), 'same-day package retained: ' . $sid);
}
bc_country_hist_assert(count(array_unique($ids)) === count($ids), 'no duplicate package_id after list');

$fullSameDay = orange_backup_admin_list_full_snapshots($tmp, 20);
$fullIds = array_map(static fn (array $p): string => (string) ($p['package_id'] ?? ''), $fullSameDay);
bc_country_hist_assert(
    in_array('2026-07-22_080000', $fullIds, true) && in_array('2026-07-22_210000', $fullIds, true),
    'Full list preserves multiple same-day package ids'
);

$listSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'list.php');
bc_country_hist_assert(
    str_contains($listSource, 'list_country_packages($pdo, $backupRoot, null)'),
    'Backup Center list.php requests uncapped country packages'
);
bc_country_hist_assert(
    !str_contains($listSource, 'list_country_packages($pdo, $backupRoot, 5)'),
    'Backup Center list.php no longer hard-caps country packages at 5'
);

$pageSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'backup_center.php');
bc_country_hist_assert(str_contains($pageSource, 'id="bc_country_list"'), 'UI uses single country list container');
bc_country_hist_assert(!str_contains($pageSource, 'id="bc_country_recent"'), 'UI removed dual country recent container');
bc_country_hist_assert(!str_contains($pageSource, 'id="bc_country_history"'), 'UI removed dual country history container');
bc_country_hist_assert(str_contains($pageSource, 'bc-acc-list[hidden]'), 'UI forces hidden lists not to display');
bc_country_hist_assert(str_contains($pageSource, 'renderActiveBackupList'), 'UI swaps latest/history inside one card');

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
    ? "OK: backup center country history self-test ({$passed} assertions)\n"
    : "FAILED: {$failed} assertion(s), {$passed} passed\n";
exit($failed === 0 ? 0 : 1);
