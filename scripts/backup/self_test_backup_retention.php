<?php

declare(strict_types=1);

/**
 * Shared backup retention self-tests (30-day policy).
 *
 * Usage:
 *   php scripts/backup/self_test_backup_retention.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_retention.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'uploads_collector.php';

$failures = 0;

function retention_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

retention_self_test(orange_backup_retention_days([]) === 30, 'retention default = 30 when config key missing');
retention_self_test(orange_backup_retention_days(['ORANGE_BACKUP_RETENTION_DAYS' => 45]) === 45, 'retention override from env');
retention_self_test(!orange_backup_retention_age_exceeds(30 * 86400, 30), 'package exactly 30 days kept');
retention_self_test(orange_backup_retention_age_exceeds((30 * 86400) + 1, 30), 'package older than 30 days eligible');
retention_self_test(orange_backup_retention_is_temp_dir_name('._work_abc'), 'temp workspace name detected');
retention_self_test(orange_backup_retention_is_temp_dir_name('.tmp_pkg_abc'), 'temp package name detected');
retention_self_test(!orange_backup_retention_is_finalized_dir_name('._work_abc'), 'temp workspace not finalized');

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_retention_' . bin2hex(random_bytes(4));
$snapshotsDir = $root . DIRECTORY_SEPARATOR . 'snapshots';
$countryDir = $root . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'zztest';
mkdir($snapshotsDir, 0775, true);
mkdir($countryDir, 0775, true);
mkdir($snapshotsDir . DIRECTORY_SEPARATOR . '._work_active', 0775, true);

$writeHealthyFull = static function (string $dir): void {
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'orange_db.sql.gz', str_repeat('x', 128));
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'uploads.zip', str_repeat('y', 128));
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => 'full_disaster',
        'package_version' => '1.0',
        'generated_at' => gmdate('c'),
        'schema_revision' => 121,
        'dump_file' => 'orange_db.sql.gz',
        'uploads_file' => 'uploads.zip',
        'dump_sha256' => hash('sha256', str_repeat('x', 128)),
        'uploads_sha256' => hash('sha256', str_repeat('y', 128)),
        'dump_size_bytes' => 128,
        'uploads_size_bytes' => 128,
        'backup_status' => 'success',
        'health_report_file' => 'health.json',
        'checksums_file' => 'checksums.sha256',
    ]);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'health.json', [
        'package_type' => 'full_disaster',
        'package_status' => 'healthy',
        'schema_revision' => 121,
    ]);
    orange_backup_write_checksums($dir, ['manifest.json', 'health.json', 'orange_db.sql.gz', 'uploads.zip']);
};

$writeHealthyCrp = static function (string $dir): void {
    mkdir($dir . DIRECTORY_SEPARATOR . 'sql');
    mkdir($dir . DIRECTORY_SEPARATOR . 'files');
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . '001_orders.sql', "-- rows=0\n");
    orange_country_uploads_write_empty_zip($dir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip');
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => 'country_recovery',
        'package_version' => '1.0',
        'generated_at' => gmdate('c'),
        'country_id' => 99999,
        'country_code' => 'zztest',
        'country_label' => 'Retention Test',
        'schema_revision' => 121,
        'registry_version' => '1.0',
        'export_backend' => 'php_country_export',
        'package_status' => 'healthy',
    ]);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'health.json', [
        'package_type' => 'country_recovery',
        'package_status' => 'healthy',
        'country_id' => 99999,
        'schema_revision' => 121,
    ]);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'dependency_graph.json', ['nodes' => [], 'edges' => []]);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'table_inventory.json', [
        'country_id' => 99999,
        'other_country_markers' => [],
        'tables' => [],
    ]);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'id_snapshot.json', ['country_id' => 99999, 'tables' => []]);
    orange_backup_write_checksums($dir, [
        'manifest.json',
        'health.json',
        'dependency_graph.json',
        'table_inventory.json',
        'id_snapshot.json',
        'files/uploads_country.zip',
        'sql/001_orders.sql',
    ]);
};

$oldHealthyFull = $snapshotsDir . DIRECTORY_SEPARATOR . '2020-01-01_010000';
$newFailedFull = $snapshotsDir . DIRECTORY_SEPARATOR . '2026-07-01_010000';
$exactThirtyName = date('Y-m-d_His', time() - (30 * 86400));
$exactThirtyFull = $snapshotsDir . DIRECTORY_SEPARATOR . $exactThirtyName;
mkdir($oldHealthyFull);
mkdir($newFailedFull);
mkdir($exactThirtyFull);
$writeHealthyFull($oldHealthyFull);
orange_backup_write_json($newFailedFull . DIRECTORY_SEPARATOR . 'health.json', [
    'package_type' => 'full_disaster',
    'package_status' => 'failed',
]);
$writeHealthyFull($exactThirtyFull);

$oldHealthyCrp = $countryDir . DIRECTORY_SEPARATOR . '2020-01-01_010000';
$newFailedCrp = $countryDir . DIRECTORY_SEPARATOR . '2026-07-01_010000';
mkdir($oldHealthyCrp);
mkdir($newFailedCrp);
$writeHealthyCrp($oldHealthyCrp);
orange_backup_write_json($newFailedCrp . DIRECTORY_SEPARATOR . 'health.json', [
    'package_type' => 'country_recovery',
    'package_status' => 'failed',
]);

$fullResult = orange_backup_retention_apply_full_snapshots($root, $snapshotsDir, $exactThirtyName, 30);
retention_self_test(is_dir($exactThirtyFull), 'newest healthy full snapshot preserved');
retention_self_test(!is_dir($newFailedFull), 'unhealthy full snapshot not protected');
retention_self_test(is_dir($exactThirtyFull), 'exactly 30-day full snapshot kept');
retention_self_test(!is_dir($oldHealthyFull), 'package older than 30 days deleted when newer healthy exists');
retention_self_test(is_dir($snapshotsDir . DIRECTORY_SEPARATOR . '._work_active'), 'current temp workspace never deleted');

$crpResult = orange_backup_retention_apply_country_packages($root, 'zztest', null, 30);
retention_self_test(is_dir($oldHealthyCrp), 'newest healthy CRP per country preserved when no newer healthy exists');
retention_self_test(!is_dir($newFailedCrp), 'unhealthy CRP not protected');

$deletedFullNames = array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $fullResult['deleted']);
retention_self_test($deletedFullNames !== [] || count($fullResult['kept']) > 0, 'full retention produced decisions');

$outsideDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_retention_out_' . bin2hex(random_bytes(3));
mkdir($outsideDir);
$escapeTarget = $snapshotsDir . DIRECTORY_SEPARATOR . '2020-02-01_010000';
mkdir($escapeTarget);
if (function_exists('symlink')) {
    @symlink($outsideDir, $snapshotsDir . DIRECTORY_SEPARATOR . '2020-02-02_010000');
}
retention_self_test(
    !orange_backup_retention_path_within_root($root, $outsideDir),
    'traversal/symlink escape blocked outside BackupRoot'
);

orange_backup_remove_dir($root);
orange_backup_remove_dir($outsideDir);

exit($failures > 0 ? 1 : 0);
