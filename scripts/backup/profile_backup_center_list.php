<?php

declare(strict_types=1);

/**
 * Load-time profiler for Backup Center data path (list.php equivalent).
 *
 * Usage:
 *   php scripts/backup/profile_backup_center_list.php
 *   php scripts/backup/profile_backup_center_list.php --root=D:\path\to\BackupRoot
 *   php scripts/backup/profile_backup_center_list.php --synthetic --packages=8 --countries=4 --per-country=3 --blob-kb=512
 *
 * Emits JSON timing + counters to stdout.
 */

$projectRoot = dirname(__DIR__, 2);

require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_batch_export.php';

/** @var array<string, float> */
$orangeBackupProfileTimings = [];
/** @var array<string, int> */
$orangeBackupProfileCounters = [
    'sql_queries' => 0,
    'filesystem_scans' => 0,
    'recursive_dir_traversals' => 0,
    'json_file_reads' => 0,
    'package_inspections' => 0,
    'finalized_dir_listings' => 0,
    'file_size_stats' => 0,
];

function orange_profile_mark(string $name): void
{
    $GLOBALS['orangeBackupProfileTimings'][$name . '__start'] = microtime(true);
}

function orange_profile_end(string $name): float
{
    $start = $GLOBALS['orangeBackupProfileTimings'][$name . '__start'] ?? microtime(true);
    $elapsed = (microtime(true) - $start) * 1000.0;
    $GLOBALS['orangeBackupProfileTimings'][$name] = ($GLOBALS['orangeBackupProfileTimings'][$name] ?? 0.0) + $elapsed;
    unset($GLOBALS['orangeBackupProfileTimings'][$name . '__start']);

    return $elapsed;
}

function orange_profile_inc(string $key, int $by = 1): void
{
    $GLOBALS['orangeBackupProfileCounters'][$key] = (int) ($GLOBALS['orangeBackupProfileCounters'][$key] ?? 0) + $by;
}

/**
 * Instrumented wrappers used only by this profiler (do not replace production APIs).
 */
function orange_profile_list_finalized_dirs(string $containerDir): array
{
    orange_profile_inc('filesystem_scans');
    orange_profile_inc('finalized_dir_listings');
    orange_profile_mark('finalized_dir_listings');
    $out = orange_backup_retention_list_finalized_dirs($containerDir);
    orange_profile_end('finalized_dir_listings');

    return $out;
}

function orange_profile_dir_size_bytes(string $dir): int
{
    orange_profile_inc('recursive_dir_traversals');
    orange_profile_mark('dir_size_bytes');
    $total = orange_backup_admin_dir_size_bytes($dir);
    orange_profile_end('dir_size_bytes');

    return $total;
}

function orange_profile_read_json(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    orange_profile_inc('json_file_reads');
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($decoded) ? $decoded : null;
}

function orange_profile_summarize_full(string $path, string $id): array
{
    orange_profile_inc('package_inspections');
    orange_profile_mark('package_inspections');
    $summary = orange_backup_admin_summarize_full_package($path, $id);
    // Approximate JSON reads inside summarize (manifest/health/recovery × up to 3)
    orange_profile_inc('json_file_reads', 2);
    if (isset($summary['recovery_score'])) {
        orange_profile_inc('json_file_reads');
    }
    orange_profile_end('package_inspections');

    return $summary;
}

function orange_profile_summarize_country(string $path, string $id, string $code, ?array $meta): array
{
    orange_profile_inc('package_inspections');
    orange_profile_mark('package_inspections');
    $summary = orange_backup_admin_summarize_country_package($path, $id, $code, $meta);
    orange_profile_inc('json_file_reads', 3);
    orange_profile_end('package_inspections');

    return $summary;
}

function orange_profile_list_full(string $backupRoot, int $limit): array
{
    orange_profile_mark('list_full_snapshots');
    $snapshotsDir = orange_backup_path_inside_root($backupRoot, 'snapshots');
    $dirs = orange_profile_list_finalized_dirs($snapshotsDir);
    $out = [];
    foreach (array_slice($dirs, 0, max(1, $limit)) as $dir) {
        $out[] = orange_profile_summarize_full($dir['path'], $dir['name']);
    }
    orange_profile_end('list_full_snapshots');

    return $out;
}

function orange_profile_list_country(PDO $pdo, string $backupRoot, int $perCountryLimit): array
{
    orange_profile_mark('list_country_packages');
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'countries.php';
    $countryRoot = orange_backup_path_inside_root($backupRoot, 'country_packages');
    $codes = orange_backup_retention_list_country_codes($backupRoot);
    orange_profile_inc('filesystem_scans');
    $out = [];
    foreach ($codes as $countryCode) {
        $countryMeta = null;
        if (function_exists('orange_country_row_by_code') && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            orange_profile_inc('sql_queries');
            $countryMeta = orange_country_row_by_code($pdo, strtoupper($countryCode), false);
        }
        $container = $countryRoot . DIRECTORY_SEPARATOR . $countryCode;
        $dirs = orange_profile_list_finalized_dirs($container);
        foreach (array_slice($dirs, 0, max(1, $perCountryLimit)) as $dir) {
            $out[] = orange_profile_summarize_country(
                $dir['path'],
                $dir['name'],
                $countryCode,
                is_array($countryMeta) ? $countryMeta : null
            );
        }
    }
    usort($out, static fn (array $a, array $b): int => strcmp((string) ($b['generated_at'] ?? ''), (string) ($a['generated_at'] ?? '')));
    orange_profile_end('list_country_packages');

    return $out;
}

/**
 * Mirror of collect_overview with instrumentation (same logic as production at profile time).
 *
 * @return array<string, mixed>
 */
function orange_profile_collect_overview(PDO $pdo, string $projectRoot, array $viewCtx): array
{
    orange_profile_mark('collect_overview');
    $backupRoot = $viewCtx['backup_root'];
    $env = $viewCtx['env'];
    $retentionDays = orange_backup_retention_days($env);
    $snapshotsDir = orange_backup_path_inside_root($backupRoot, 'snapshots');
    $countryRoot = orange_backup_path_inside_root($backupRoot, 'country_packages');
    $logsDir = orange_backup_path_inside_root($backupRoot, 'logs');

    $fullSnapshots = orange_profile_list_full($backupRoot, 50);
    $latestFull = $fullSnapshots[0] ?? null;
    $lastSuccessfulFull = null;
    foreach ($fullSnapshots as $snap) {
        if (!empty($snap['healthy'])) {
            $lastSuccessfulFull = $snap;
            break;
        }
    }
    $latestRecoveryScore = isset($latestFull['recovery_score']) && is_numeric($latestFull['recovery_score'])
        ? (int) $latestFull['recovery_score']
        : 0;

    orange_profile_mark('discover_countries');
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        // Historical probes are MySQL-specific; measure SQL SELECT only.
        orange_profile_inc('sql_queries');
        $st = $pdo->query('SELECT id, code, name_ar, name_en, is_active FROM countries ORDER BY sort_order ASC, id ASC');
        $recoverableCount = 0;
        if ($st !== false) {
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if ((int) ($row['is_active'] ?? 0) === 1) {
                    $recoverableCount++;
                }
            }
        }
    } else {
        $discovery = orange_crp_batch_discover_countries($pdo);
        orange_profile_inc('sql_queries', 1 + count($discovery['discovered'] ?? []));
        $recoverableCount = count($discovery['selected'] ?? []);
    }
    orange_profile_end('discover_countries');

    orange_profile_mark('environment_report');
    $backend = 'mysqldump';
    if (is_file($projectRoot . DIRECTORY_SEPARATOR . '.env.php')) {
        $report = orange_backup_collect_environment_report($projectRoot);
        $backend = (string) ($report['selected_backend'] ?? '');
    }
    orange_profile_end('environment_report');

    $countryPackages = orange_profile_list_country($pdo, $backupRoot, 1);
    $latestCountry = $countryPackages[0] ?? null;

    orange_profile_mark('inventory_counts');
    $countryCodesOnDisk = orange_backup_retention_list_country_codes($backupRoot);
    orange_profile_inc('filesystem_scans');
    $storedCountryPackagesTotal = 0;
    foreach ($countryCodesOnDisk as $countryCode) {
        $container = $countryRoot . DIRECTORY_SEPARATOR . $countryCode;
        $storedCountryPackagesTotal += count(orange_profile_list_finalized_dirs($container));
    }
    $fullSnapshotsTotal = count(orange_profile_list_finalized_dirs($snapshotsDir));
    orange_profile_end('inventory_counts');

    orange_profile_mark('storage_sizes');
    $storage = [
        'snapshots_bytes' => orange_profile_dir_size_bytes($snapshotsDir),
        'country_packages_bytes' => orange_profile_dir_size_bytes($countryRoot),
        'logs_bytes' => orange_profile_dir_size_bytes($logsDir),
        'total_bytes' => 0,
    ];
    $storage['total_bytes'] = $storage['snapshots_bytes'] + $storage['country_packages_bytes'] + $storage['logs_bytes'];
    $storage['snapshots_human'] = orange_backup_admin_format_bytes($storage['snapshots_bytes']);
    $storage['country_packages_human'] = orange_backup_admin_format_bytes($storage['country_packages_bytes']);
    $storage['logs_human'] = orange_backup_admin_format_bytes($storage['logs_bytes']);
    $storage['total_human'] = orange_backup_admin_format_bytes($storage['total_bytes']);
    orange_profile_end('storage_sizes');

    orange_profile_end('collect_overview');

    return [
        'recoverable_countries' => $recoverableCount,
        'stored_country_packages_total' => $storedCountryPackagesTotal,
        'full_snapshots_total' => $fullSnapshotsTotal,
        'latest_recovery_score' => $latestRecoveryScore,
        'last_successful_full' => $lastSuccessfulFull,
        'latest_full' => $latestFull,
        'latest_country_batch' => $latestCountry,
        'selected_backend' => $backend,
        'retention_days' => $retentionDays,
        'storage' => $storage,
    ];
}

function orange_profile_build_synthetic(string $root, int $fullCount, int $countries, int $perCountry, int $blobKb): void
{
    $blob = str_repeat('X', max(1, $blobKb) * 1024);
    $snapRoot = $root . DIRECTORY_SEPARATOR . 'snapshots';
    $countryRoot = $root . DIRECTORY_SEPARATOR . 'country_packages';
    $logsRoot = $root . DIRECTORY_SEPARATOR . 'logs';
    @mkdir($snapRoot, 0775, true);
    @mkdir($countryRoot, 0775, true);
    @mkdir($logsRoot, 0775, true);

    for ($i = 0; $i < $fullCount; $i++) {
        $id = sprintf('2026-07-%02d_%02d0000', max(1, 20 - $i), $i);
        $dir = $snapRoot . DIRECTORY_SEPARATOR . $id;
        @mkdir($dir, 0775, true);
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode([
            'generated_at' => sprintf('2026-07-%02dT12:00:00+00:00', max(1, 20 - $i)),
            'schema_revision' => 121,
            'export_backend' => 'mysqldump',
            'dump_size_bytes' => strlen($blob),
            'uploads_size_bytes' => 0,
            'backup_status' => 'success',
        ], JSON_UNESCAPED_SLASHES));
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'health.json', json_encode([
            'package_status' => $i === 0 ? 'healthy' : 'healthy',
        ]));
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'recovery_validation.json', json_encode([
            'recovery_score' => 90 - $i,
            'overall_result' => 'pass',
        ]));
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'dump.sql', $blob);
    }

    $codes = ['kw', 'sa', 'ae', 'bh', 'om', 'qa', 'eg', 'jo'];
    for ($c = 0; $c < $countries; $c++) {
        $code = $codes[$c % count($codes)];
        $cdir = $countryRoot . DIRECTORY_SEPARATOR . $code;
        @mkdir($cdir, 0775, true);
        for ($p = 0; $p < $perCountry; $p++) {
            $id = sprintf('2026-07-%02d_%02d%02d00', max(1, 15 - $p), $c, $p);
            $dir = $cdir . DIRECTORY_SEPARATOR . $id;
            @mkdir($dir, 0775, true);
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode([
                'generated_at' => sprintf('2026-07-%02dT13:00:00+00:00', max(1, 15 - $p)),
                'schema_revision' => 121,
                'country_id' => $c + 1,
                'registry_version' => '1',
                'backup_status' => 'success',
            ], JSON_UNESCAPED_SLASHES));
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'health.json', json_encode(['package_status' => 'healthy']));
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'country_recovery_validation.json', json_encode([
                'package_type' => 'country',
                'recovery_score' => 88,
                'overall_result' => 'pass',
            ]));
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'data.bin', $blob);
        }
    }
    file_put_contents($logsRoot . DIRECTORY_SEPARATOR . 'run_full_backup_demo.log', str_repeat("log line\n", 200));
}

// --- CLI args ---
$opts = [
    'root' => null,
    'synthetic' => false,
    'packages' => 8,
    'countries' => 4,
    'per-country' => 3,
    'blob-kb' => 512,
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--synthetic') {
        $opts['synthetic'] = true;
        continue;
    }
    if (str_starts_with($arg, '--root=')) {
        $opts['root'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--packages=')) {
        $opts['packages'] = (int) substr($arg, 11);
    } elseif (str_starts_with($arg, '--countries=')) {
        $opts['countries'] = (int) substr($arg, 12);
    } elseif (str_starts_with($arg, '--per-country=')) {
        $opts['per-country'] = (int) substr($arg, 14);
    } elseif (str_starts_with($arg, '--blob-kb=')) {
        $opts['blob-kb'] = (int) substr($arg, 10);
    }
}

$tmpRoot = null;
$backupRoot = $opts['root'];
if ($backupRoot === null || $opts['synthetic']) {
    $tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_bc_profile_' . getmypid();
    if (is_dir($tmpRoot)) {
        // best-effort cleanup of previous run leftovers is skipped; unique pid path
    }
    @mkdir($tmpRoot, 0775, true);
    orange_profile_build_synthetic(
        $tmpRoot,
        max(1, (int) $opts['packages']),
        max(1, (int) $opts['countries']),
        max(1, (int) $opts['per-country']),
        max(1, (int) $opts['blob-kb'])
    );
    $backupRoot = $tmpRoot;
    $opts['synthetic'] = true;
}

if (!is_dir($backupRoot)) {
    fwrite(STDERR, "Backup root not found: {$backupRoot}\n");
    exit(1);
}

// Minimal PDO stub for discover/countries — use real DB if config exists, else in-memory SQLite countries table.
$pdo = null;
if (is_file($projectRoot . DIRECTORY_SEPARATOR . '.env.php')) {
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
    $pdo = db();
} else {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY, code TEXT, name_ar TEXT, name_en TEXT, is_active INTEGER, sort_order INTEGER)');
    $codes = ['kw', 'sa', 'ae', 'bh'];
    foreach ($codes as $i => $code) {
        $st = $pdo->prepare('INSERT INTO countries (id, code, name_ar, name_en, is_active, sort_order) VALUES (?,?,?,?,1,?)');
        $st->execute([$i + 1, $code, $code, strtoupper($code), $i]);
    }
    // Stub orange_table_exists if catalog_schema not loaded
    if (!function_exists('orange_table_exists')) {
        function orange_table_exists(PDO $pdo, string $table): bool
        {
            return $table === 'countries';
        }
    }
}

$viewCtx = [
    'project_root' => $projectRoot,
    'backup_root' => $backupRoot,
    'env' => [
        'ORANGE_BACKUP_RETENTION_DAYS' => '30',
        'ORANGE_BACKUP_ROOT' => $backupRoot,
    ],
    'root_health' => [
        'exists' => true,
        'readable' => true,
        'writable' => is_writable($backupRoot),
        'manual_actions_available' => is_writable($backupRoot),
        'warning' => null,
    ],
];

$totalStart = microtime(true);

orange_profile_mark('context_for_view_sim');
// context already built
orange_profile_end('context_for_view_sim');

$overview = orange_profile_collect_overview($pdo, $projectRoot, $viewCtx);

orange_profile_mark('list_php_full_20');
$full20 = orange_profile_list_full($backupRoot, 20);
orange_profile_end('list_php_full_20');

orange_profile_mark('list_php_country_5');
$country5 = orange_profile_list_country($pdo, $backupRoot, 5);
orange_profile_end('list_php_country_5');

orange_profile_mark('list_logs');
$logs = orange_backup_admin_list_logs($backupRoot, 40);
orange_profile_inc('filesystem_scans');
orange_profile_end('list_logs');

$totalMs = (microtime(true) - $totalStart) * 1000.0;

$timings = [];
foreach ($GLOBALS['orangeBackupProfileTimings'] as $k => $v) {
    if (!str_ends_with($k, '__start')) {
        $timings[$k] = round((float) $v, 2);
    }
}
arsort($timings);

$report = [
    'mode' => $opts['synthetic'] ? 'synthetic' : 'live_root',
    'backup_root' => $backupRoot,
    'total_ms' => round($totalMs, 2),
    'timings_ms' => $timings,
    'counters' => $GLOBALS['orangeBackupProfileCounters'],
    'result_sizes' => [
        'full_snapshots_listed_20' => count($full20),
        'country_packages_listed_5' => count($country5),
        'logs' => count($logs),
        'overview_full_snapshots_total' => $overview['full_snapshots_total'] ?? null,
        'overview_stored_country_packages_total' => $overview['stored_country_packages_total'] ?? null,
    ],
    'bottlenecks_ranked' => array_slice(array_keys($timings), 0, 12),
    'notes' => [
        'This script models the LEGACY duplicated path (overview list + list.php list again) for before/after comparison.',
        'Optimized path: scripts/backup/profile_backup_center_list_optimized.php and admin/api/backup/list.php.',
        'storage sizes use RecursiveDirectoryIterator over entire trees including dump blobs (cached after first compute).',
        'environment_report may open DB and probe mysqldump/powershell (request-scoped cache applied).',
    ],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

// cleanup synthetic
if ($tmpRoot !== null && is_dir($tmpRoot)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $fileInfo) {
        $p = $fileInfo->getPathname();
        $fileInfo->isDir() ? @rmdir($p) : @unlink($p);
    }
    @rmdir($tmpRoot);
}
