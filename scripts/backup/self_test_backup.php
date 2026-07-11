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

require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_pdo_export.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_runner.php';

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

// Environment diagnostic structure
$envReport = orange_backup_collect_environment_report($projectRoot);
$requiredFields = [
    'orange_backup_root_configured',
    'backup_root_candidate',
    'backup_root_error',
    'open_basedir',
    'proc_open_available',
    'configured_mysqldump_path_present',
    'mysqldump_detection_source',
    'powershell_detection_source',
    'pdo_fallback_ready',
    'selected_backend',
    'can_run_full_backup',
    'blockers',
];
foreach ($requiredFields as $field) {
    self_test(array_key_exists($field, $envReport), "environment report includes {$field}");
}
if (empty($envReport['backup_root'])) {
    self_test(!empty($envReport['backup_root_error']), 'backup_root_error surfaced when backup_root unresolved');
}

// Invalid configured mysqldump path rejected
$invalidDump = orange_backup_detect_mysqldump(['ORANGE_MYSQLDUMP_PATH' => 'Z:\\missing\\mysqldump.exe']);
self_test($invalidDump['source'] === 'configured_invalid', 'invalid configured mysqldump path rejected');

// Configured path takes precedence over discovery order (invalid configured => configured_invalid, not none)
self_test($invalidDump['source'] !== 'none', 'configured mysqldump path preferred over auto-discovery');

// proc_open unavailable blocks execution
if (!orange_backup_can_proc_open()) {
    $blockerText = implode(' ', $envReport['blockers'] ?? []);
    if (($envReport['selected_backend'] ?? '') === 'php_pdo') {
        self_test(!empty($envReport['can_run_full_backup']), 'php_pdo backend can run without proc_open');
    } else {
        self_test(empty($envReport['can_run_full_backup']), 'proc_open unavailable marks can_run_full_backup=false');
        self_test(str_contains($blockerText, 'proc_open'), 'proc_open unavailable listed in blockers');
    }
}

// PDO fallback detection exists
self_test(function_exists('orange_backup_pdo_export_database'), 'PDO export fallback exists');

// Backend priority: PDO only when mysqldump absent
$pdoOnlyEnv = orange_backup_detect_mysqldump(['ORANGE_MYSQLDUMP_PATH' => 'Z:\\missing\\mysqldump.exe']);
self_test(($pdoOnlyEnv['path'] ?? null) === null, 'PDO fallback scenario keeps mysqldump unavailable');

// PDO literal escaping
$pdoLiteral = new PDO('sqlite::memory:');
self_test(orange_backup_pdo_sql_literal($pdoLiteral, null, 'varchar') === 'NULL', 'PDO literal NULL');
self_test(orange_backup_pdo_sql_literal($pdoLiteral, "line\n\tquote'", 'varchar') !== '', 'PDO literal escaped text');
self_test(orange_backup_pdo_sql_literal($pdoLiteral, "\x01\x02", 'blob') === '0x0102', 'PDO literal binary hex');

// PDO export format validation
$pdoFormatFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_pdo_format_' . bin2hex(random_bytes(4)) . '.sql';
file_put_contents($pdoFormatFile, implode("\n", [
    'SET NAMES utf8mb4;',
    'SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;',
    'SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\';',
    'CREATE TABLE `demo` (`id` int NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`));',
    'SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;',
]));
try {
    orange_backup_pdo_validate_export_format($pdoFormatFile, 1);
    self_test(true, 'PDO export format self-check');
} catch (Throwable) {
    self_test(false, 'PDO export format self-check');
}
@unlink($pdoFormatFile);

// Live DB PDO export self-test when database is reachable
if (!empty($envReport['database_connected']) && is_file($projectRoot . DIRECTORY_SEPARATOR . '.env.php')) {
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
    $pdoLive = db();
    $dbName = defined('DB_NAME') ? (string) DB_NAME : '';
    if ($dbName !== '') {
        $pdoSelf = orange_backup_pdo_export_self_test($pdoLive, $dbName);
        self_test(($pdoSelf['failed'] ?? 1) === 0, 'PDO export live self-test');
    }
}

$backend = orange_backup_select_backend($projectRoot);

// Backend selection includes PDO when available
if ($backend === null && !empty($envReport['pdo_fallback_ready'])) {
    self_test(false, 'pdo_fallback_ready true but backend null');
} elseif ($backend === 'php_pdo') {
    self_test(!empty($envReport['can_run_full_backup']), 'php_pdo backend enables can_run_full_backup');
}

// Lock prevents concurrent backup (same process pid marked active)
$lockRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_bak_lock_' . bin2hex(random_bytes(4));
mkdir($lockRoot);
$firstLock = orange_backup_acquire_lock($lockRoot);
self_test($firstLock['acquired'], 'lock acquire first caller');
$secondLock = orange_backup_acquire_lock($lockRoot);
self_test(!$secondLock['acquired'], 'lock prevents concurrent backup');
orange_backup_release_lock();
orange_backup_remove_dir($lockRoot);

// Backend unavailable => clear failure signal
if ($backend === null) {
    self_test(empty($envReport['can_run_full_backup']), 'backend unavailable marks can_run_full_backup=false');
} else {
    self_test(in_array($backend, ['powershell', 'php_mysqldump', 'php_pdo'], true), 'backend selection returns supported backend');
    echo "INFO: backend available on this machine ({$backend})\n";
}

// PowerShell delegation path exists when powershell_ready
if (!empty($envReport['powershell_ready'])) {
    $psScript = $projectRoot . '/scripts/backup/orange_backup.ps1';
    self_test(is_file($psScript), 'powershell backend script present');
}

exit($failures > 0 ? 1 : 0);
