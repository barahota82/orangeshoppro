<?php

declare(strict_types=1);

/**
 * Profile the optimized list.php data path (shared lists + storage cache).
 *
 *   php scripts/backup/profile_backup_center_list_optimized.php --synthetic --packages=12 --countries=4 --per-country=5 --blob-kb=8192
 */

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php';

$opts = [
    'packages' => 12,
    'countries' => 4,
    'per-country' => 5,
    'blob-kb' => 8192,
];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--packages=')) {
        $opts['packages'] = (int) substr($arg, 11);
    } elseif (str_starts_with($arg, '--countries=')) {
        $opts['countries'] = (int) substr($arg, 12);
    } elseif (str_starts_with($arg, '--per-country=')) {
        $opts['per-country'] = (int) substr($arg, 14);
    } elseif (str_starts_with($arg, '--blob-kb=')) {
        $opts['blob-kb'] = (int) substr($arg, 10);
    }
}

$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_bc_opt_' . getmypid();
@mkdir($tmpRoot, 0775, true);
$blob = str_repeat('Y', max(1, (int) $opts['blob-kb']) * 1024);
$snapRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'snapshots';
$countryRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'country_packages';
$logsRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'logs';
@mkdir($snapRoot, 0775, true);
@mkdir($countryRoot, 0775, true);
@mkdir($logsRoot, 0775, true);
for ($i = 0; $i < (int) $opts['packages']; $i++) {
    $id = sprintf('2026-07-%02d_%02d0000', max(1, 20 - $i), $i);
    $dir = $snapRoot . DIRECTORY_SEPARATOR . $id;
    @mkdir($dir, 0775, true);
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode([
        'generated_at' => sprintf('2026-07-%02dT12:00:00+00:00', max(1, 20 - $i)),
        'schema_revision' => 121,
        'export_backend' => 'mysqldump',
        'dump_size_bytes' => strlen($blob),
        'uploads_size_bytes' => 0,
    ]));
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'health.json', json_encode(['package_status' => 'healthy']));
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'recovery_validation.json', json_encode([
        'recovery_score' => 90,
        'overall_result' => 'pass',
    ]));
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'dump.sql', $blob);
}
$codes = ['kw', 'sa', 'ae', 'bh'];
for ($c = 0; $c < (int) $opts['countries']; $c++) {
    $code = $codes[$c % count($codes)];
    $cdir = $countryRoot . DIRECTORY_SEPARATOR . $code;
    @mkdir($cdir, 0775, true);
    for ($p = 0; $p < (int) $opts['per-country']; $p++) {
        $id = sprintf('2026-07-%02d_%02d%02d00', max(1, 15 - $p), $c, $p);
        $dir = $cdir . DIRECTORY_SEPARATOR . $id;
        @mkdir($dir, 0775, true);
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode([
            'generated_at' => sprintf('2026-07-%02dT13:00:00+00:00', max(1, 15 - $p)),
            'schema_revision' => 121,
            'country_id' => $c + 1,
        ]));
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'health.json', json_encode(['package_status' => 'healthy']));
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'country_recovery_validation.json', json_encode([
            'package_type' => 'country',
            'recovery_score' => 88,
            'overall_result' => 'pass',
        ]));
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'data.bin', $blob);
    }
}
file_put_contents($logsRoot . DIRECTORY_SEPARATOR . 'demo.log', "x\n");

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY, code TEXT, name_ar TEXT, name_en TEXT, is_active INTEGER, sort_order INTEGER)');
foreach ($codes as $i => $code) {
    $st = $pdo->prepare('INSERT INTO countries (id, code, name_ar, name_en, is_active, sort_order) VALUES (?,?,?,?,1,?)');
    $st->execute([$i + 1, $code, $code, strtoupper($code), $i]);
}

$viewCtx = [
    'project_root' => $projectRoot,
    'backup_root' => $tmpRoot,
    'env' => ['ORANGE_BACKUP_RETENTION_DAYS' => '30', 'ORANGE_BACKUP_ROOT' => $tmpRoot],
    'root_health' => [
        'exists' => true,
        'readable' => true,
        'writable' => true,
        'manual_actions_available' => true,
        'warning' => null,
    ],
];

function orange_profile_ms(callable $fn): array
{
    $t0 = microtime(true);
    $result = $fn();
    return [$result, round((microtime(true) - $t0) * 1000, 2)];
}

// Cold path (first request — storage cache miss)
$timings = [];
$total0 = microtime(true);
[, $timings['list_full_20']] = orange_profile_ms(
    static fn () => orange_backup_admin_list_full_snapshots($tmpRoot, 20)
);
[, $timings['list_country_5']] = orange_profile_ms(
    static fn () => orange_backup_admin_list_country_packages($pdo, $tmpRoot, 5)
);
[$inventory, $timings['inventory']] = orange_profile_ms(
    static fn () => orange_backup_admin_package_inventory_counts($tmpRoot)
);
[$storageCold, $timings['storage_cold']] = orange_profile_ms(
    static fn () => orange_backup_admin_collect_storage_totals($tmpRoot, $inventory)
);
$full = orange_backup_admin_list_full_snapshots($tmpRoot, 20);
$country = orange_backup_admin_list_country_packages($pdo, $tmpRoot, 5);
[, $timings['overview_with_preload']] = orange_profile_ms(
    static function () use ($tmpRoot, $full, $country, $inventory, $storageCold) {
        return [
            'latest_full' => $full[0] ?? null,
            'latest_country' => $country[0] ?? null,
            'inventory' => $inventory,
            'storage' => $storageCold,
            'last_successful' => orange_backup_admin_find_last_successful_full($tmpRoot, $full, 50),
        ];
    }
);
[, $timings['list_logs']] = orange_profile_ms(
    static fn () => orange_backup_admin_list_logs($tmpRoot, 40)
);
$timings['total_cold_ms'] = round((microtime(true) - $total0) * 1000, 2);

// Warm path (storage cache hit + finalized-dir memo already warm — new request memo is cold but cache file warm)
// Simulate second HTTP request: reset is not possible for static memo without new process.
// Measure storage cache hit alone in a subprocess-like re-call after clearing would need separate process.
[$storageWarm, $timings['storage_warm_same_process']] = orange_profile_ms(
    static fn () => orange_backup_admin_collect_storage_totals($tmpRoot, $inventory)
);

echo json_encode([
    'mode' => 'optimized_synthetic',
    'timings_ms' => $timings,
    'storage_total_human' => $storageWarm['total_human'] ?? null,
    'inventory' => $inventory,
    'notes' => [
        'storage_warm_same_process should be near-zero (signature cache hit).',
        'Compared to legacy profiler: no second full/country inspection pass.',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

// cleanup
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmpRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $fileInfo) {
    $p = $fileInfo->getPathname();
    $fileInfo->isDir() ? @rmdir($p) : @unlink($p);
}
@rmdir($tmpRoot);
