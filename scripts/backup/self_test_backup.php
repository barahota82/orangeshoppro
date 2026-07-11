<?php

declare(strict_types=1);

/**
 * Phase 1A full-disaster backup self-tests (no DB backup, no CRP).
 *
 * Usage:
 *   php scripts/backup/self_test_backup.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_paths.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_full.php';

$failures = 0;

function self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

// Path traversal rejection
try {
    orange_backup_path_inside_root($projectRoot, '../outside');
    self_test(false, 'path traversal should fail');
} catch (Throwable) {
    self_test(true, 'path traversal rejected');
}

// BackupRoot inside web root rejection
try {
    orange_backup_assert_outside_web_root($projectRoot);
    self_test(false, 'project root should be rejected as BackupRoot');
} catch (Throwable) {
    self_test(true, 'web root BackupRoot rejected');
}

// Public httpdocs path rejection
try {
    orange_backup_resolve_root([], 'D:\\httpdocs\\orange_backups');
    self_test(false, 'httpdocs BackupRoot should fail');
} catch (Throwable) {
    self_test(true, 'httpdocs BackupRoot rejected');
}

// Empty explicit override rejection
try {
    orange_backup_resolve_root([], '');
    self_test(false, 'empty explicit BackupRoot should fail');
} catch (Throwable) {
    self_test(true, 'empty BackupRoot rejected');
}

// Manifest JSON write/read
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_bak_selftest_' . bin2hex(random_bytes(4));
mkdir($tmp);
$manifestPath = $tmp . DIRECTORY_SEPARATOR . 'manifest.json';
$manifestData = ['package_type' => 'full_disaster', 'generated_at' => gmdate('c')];
orange_backup_write_json($manifestPath, $manifestData);
$readBack = json_decode((string) file_get_contents($manifestPath), true);
self_test(is_array($readBack) && ($readBack['package_type'] ?? '') === 'full_disaster', 'manifest JSON write/read');

// Checksum generation/verification
$sample = $tmp . DIRECTORY_SEPARATOR . 'sample.txt';
file_put_contents($sample, 'orange-backup-self-test');
$hash = orange_backup_sha256_file($sample);
orange_backup_write_checksums($tmp, ['sample.txt']);
$verify = orange_backup_verify_checksums($tmp);
self_test($verify['ok'], 'checksum generation/verification');

// Incomplete package rejection (manifest only, no dump/uploads)
$incompleteDir = $tmp . DIRECTORY_SEPARATOR . 'incomplete_pkg';
mkdir($incompleteDir);
orange_backup_write_json($incompleteDir . DIRECTORY_SEPARATOR . 'manifest.json', [
    'package_type' => 'full_disaster',
    'schema_revision' => 121,
    'dump_file' => 'missing.sql.gz',
    'uploads_file' => 'missing.zip',
    'dump_sha256' => str_repeat('a', 64),
    'uploads_sha256' => str_repeat('b', 64),
    'dump_size_bytes' => 1,
    'uploads_size_bytes' => 1,
    'backup_status' => 'success',
    'health_report_file' => 'health.json',
    'checksums_file' => 'checksums.sha256',
    'package_version' => '1.2',
    'generated_at' => gmdate('c'),
]);
$incompleteResult = orange_backup_verify_full_package($incompleteDir);
self_test(!$incompleteResult['ok'], 'incomplete package rejected');

// Corrupted file rejection
$corruptDir = $tmp . DIRECTORY_SEPARATOR . 'corrupt_pkg';
mkdir($corruptDir);
file_put_contents($corruptDir . DIRECTORY_SEPARATOR . 'dump.sql.gz', 'data');
file_put_contents($corruptDir . DIRECTORY_SEPARATOR . 'uploads.zip', 'data');
orange_backup_write_json($corruptDir . DIRECTORY_SEPARATOR . 'manifest.json', [
    'package_type' => 'full_disaster',
    'schema_revision' => 121,
    'dump_file' => 'dump.sql.gz',
    'uploads_file' => 'uploads.zip',
    'dump_sha256' => str_repeat('0', 64),
    'uploads_sha256' => str_repeat('0', 64),
    'dump_size_bytes' => 4,
    'uploads_size_bytes' => 4,
    'backup_status' => 'success',
    'health_report_file' => 'health.json',
    'checksums_file' => 'checksums.sha256',
    'package_version' => '1.2',
    'generated_at' => gmdate('c'),
]);
orange_backup_write_json($corruptDir . DIRECTORY_SEPARATOR . 'health.json', [
    'package_status' => 'healthy',
    'schema_revision' => 121,
]);
orange_backup_write_checksums($corruptDir, ['dump.sql.gz', 'uploads.zip', 'manifest.json', 'health.json']);
$corruptManifestPath = $corruptDir . DIRECTORY_SEPARATOR . 'manifest.json';
$corruptManifest = json_decode((string) file_get_contents($corruptManifestPath), true);
if (is_array($corruptManifest)) {
    $corruptManifest['dump_sha256'] = orange_backup_sha256_file($corruptDir . DIRECTORY_SEPARATOR . 'dump.sql.gz');
    orange_backup_write_json($corruptManifestPath, $corruptManifest);
}
file_put_contents($corruptDir . DIRECTORY_SEPARATOR . 'checksums.sha256', str_repeat('f', 64) . "  dump.sql.gz\n");
$corruptResult = orange_backup_verify_full_package($corruptDir);
self_test(!$corruptResult['ok'], 'corrupted checksum rejected');

// Temporary-folder cleanup behavior
orange_backup_remove_dir($tmp);
self_test(!is_dir($tmp), 'temporary-folder cleanup');

exit($failures > 0 ? 1 : 0);
