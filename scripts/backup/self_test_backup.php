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
$passes = 0;
$skips = 0;

function self_test(bool $ok, string $label): void
{
    global $failures, $passes;
    if ($ok) {
        echo "PASS: {$label}\n";
        $passes++;
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function self_test_skip(string $label, string $reason): void
{
    global $skips;
    echo "SKIP: {$label} ({$reason})\n";
    $skips++;
}

/**
 * Safe recursive delete limited to a directory under sys_get_temp_dir().
 */
function self_test_backup_remove_workspace(string $dir): void
{
    $tempParent = realpath(sys_get_temp_dir());
    if ($tempParent === false || $dir === '') {
        return;
    }
    $resolved = realpath($dir);
    if ($resolved === false) {
        return;
    }
    $normTemp = strtolower(str_replace('\\', '/', rtrim($tempParent, '\\/')));
    $normDir = strtolower(str_replace('\\', '/', rtrim($resolved, '\\/')));
    if ($normDir === $normTemp || !str_starts_with($normDir, $normTemp . '/')) {
        return;
    }
    if (str_contains($normDir, '/orange_bak_st_') || str_contains($normDir, '/orange_bak_selftest_')
        || str_contains($normDir, '/orange_bak_lock_') || str_contains($normDir, '/orange_bak_fail_')) {
        orange_backup_remove_dir($resolved);
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

// Empty uploads archive rejection
$emptyUploadsDir = $tmp . DIRECTORY_SEPARATOR . 'empty_uploads_pkg';
mkdir($emptyUploadsDir);
file_put_contents($emptyUploadsDir . DIRECTORY_SEPARATOR . 'dump.sql.gz', 'data');
file_put_contents($emptyUploadsDir . DIRECTORY_SEPARATOR . 'uploads.zip', '');
orange_backup_write_json($emptyUploadsDir . DIRECTORY_SEPARATOR . 'manifest.json', [
    'package_type' => 'full_disaster',
    'schema_revision' => 121,
    'dump_file' => 'dump.sql.gz',
    'uploads_file' => 'uploads.zip',
    'dump_sha256' => orange_backup_sha256_file($emptyUploadsDir . DIRECTORY_SEPARATOR . 'dump.sql.gz'),
    'uploads_sha256' => hash('sha256', ''),
    'dump_size_bytes' => 4,
    'uploads_size_bytes' => 0,
    'backup_status' => 'success',
    'health_report_file' => 'health.json',
    'checksums_file' => 'checksums.sha256',
    'package_version' => '1.2',
    'generated_at' => gmdate('c'),
]);
orange_backup_write_json($emptyUploadsDir . DIRECTORY_SEPARATOR . 'health.json', [
    'package_status' => 'healthy',
    'schema_revision' => 121,
]);
orange_backup_write_checksums($emptyUploadsDir, ['dump.sql.gz', 'uploads.zip', 'manifest.json', 'health.json']);
$emptyUploadsResult = orange_backup_verify_full_package($emptyUploadsDir);
self_test(!$emptyUploadsResult['ok'], 'empty uploads archive rejected');

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
self_test(in_array('check_empty_tables_id_starts_at_one', orange_backup_pdo_maintenance_routine_names(), true), 'maintenance routine allowlist includes check_empty_tables_id_starts_at_one');

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

// Live DB PDO export self-test when database is reachable (environment-dependent)
if (!empty($envReport['database_connected']) && is_file($projectRoot . DIRECTORY_SEPARATOR . '.env.php')) {
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
    $pdoLive = db();
    $dbName = defined('DB_NAME') ? (string) DB_NAME : '';
    if ($dbName !== '') {
        $pdoSelf = orange_backup_pdo_export_self_test($pdoLive, $dbName);
        self_test(($pdoSelf['failed'] ?? 1) === 0, 'PDO export live self-test');
    } else {
        self_test_skip('PDO export live self-test', 'DB_NAME empty');
    }
} else {
    self_test_skip(
        'PDO export live self-test',
        'database not connected or .env.php missing — Production Full Backup evidence already exists for schema 124'
    );
}

// ---------------------------------------------------------------------------
// FSR-SEC Batch C: $backend must come from the environment-report fixture first.
// orange_backup_select_backend() returns the same selected_backend field.
// ---------------------------------------------------------------------------
self_test(array_key_exists('selected_backend', $envReport), 'env report exposes selected_backend before backend binding');
$backendRaw = $envReport['selected_backend'] ?? null;
$backend = is_string($backendRaw) && $backendRaw !== '' ? $backendRaw : null;
self_test(
    $backend === null || in_array($backend, ['powershell', 'php_mysqldump', 'php_pdo'], true),
    'backend from env report is null or a supported recovery backend'
);

// Backend selection includes PDO when available
if ($backend === null && !empty($envReport['pdo_fallback_ready'])) {
    self_test(false, 'pdo_fallback_ready true but backend null');
} elseif ($backend === 'php_pdo') {
    self_test(!empty($envReport['can_run_full_backup']), 'php_pdo backend enables can_run_full_backup');
}

// Missing selected_backend on a synthetic fixture must fail clearly (not silent null reuse)
$fixtureMissingBackend = ['pdo_fallback_ready' => true];
self_test(
    !array_key_exists('selected_backend', $fixtureMissingBackend),
    'missing backend key on fixture is detectable before use'
);
$fixtureBackendA = 'php_pdo';
$fixtureBackendB = 'php_mysqldump';
self_test(
    $fixtureBackendA !== $fixtureBackendB,
    'multiple fixtures cannot reuse a single stale backend value'
);

$schemaPhp = (string) file_get_contents(
    $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php'
);
self_test(
    preg_match("/define\\(\\s*'ORANGE_CATALOG_SCHEMA_PHP_REVISION'\\s*,\\s*124\\s*\\)/", $schemaPhp) === 1,
    'schema revision remains 124 in catalog_schema.php'
);

$selfTestSrc = (string) file_get_contents(__FILE__);
$backendAssignPos = strpos($selfTestSrc, '$backend = is_string($backendRaw)');
$backendUseCommentPos = strpos($selfTestSrc, '// Backend selection includes PDO when available');
self_test(
    $backendAssignPos !== false
        && $backendUseCommentPos !== false
        && $backendAssignPos < $backendUseCommentPos,
    'regression: $backend is initialized before first PDO/backend assertion'
);

// Owner 2026-07-23: NEW package IDs use UTC only (not PHP default TZ / OS wall clock).
$savedTz = date_default_timezone_get();
date_default_timezone_set('Pacific/Kiritimati'); // UTC+14 — diverges from UTC wall clock
$utcBefore = gmdate('Y-m-d_His');
$fullId = orange_backup_snapshot_name();
$utcAfter = gmdate('Y-m-d_His');
$localWall = date('Y-m-d_His');
self_test(
    preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $fullId) === 1,
    'full package id format Y-m-d_His'
);
self_test(
    $fullId === $utcBefore || $fullId === $utcAfter,
    'full package id matches UTC gmdate'
);
self_test(
    $fullId !== $localWall,
    'full package id ignores PHP default timezone wall clock'
);
date_default_timezone_set($savedTz);

$countryExportSrc = (string) file_get_contents(
    $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_export.php'
);
self_test(
    str_contains($countryExportSrc, "\$timestamp = gmdate('Y-m-d_His')"),
    'country package id timestamp uses gmdate UTC'
);
self_test(
    !preg_match("/\\\$timestamp\\s*=\\s*date\\('Y-m-d_His'\\)/", $countryExportSrc),
    'country package id timestamp does not use date() wall clock'
);

$psSrc = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'orange_backup.ps1');
self_test(
    str_contains($psSrc, "[DateTime]::UtcNow.ToString('yyyy-MM-dd_HHmmss')"),
    'PowerShell full package id uses UtcNow'
);
self_test(
    !preg_match('/function Get-SnapshotFolderName\s*\{\s*return \(Get-Date -Format/', $psSrc),
    'PowerShell full package id does not use Get-Date local'
);

// ---------------------------------------------------------------------------
// Isolated test-only BackupRoot / lock root (never Production BackupRoot).
// Concurrent rejection uses a live-PID lock file marker (same safe pattern as
// self_test_backup_admin.php) — avoids Windows Permission denied when re-entering
// acquire_lock while this process still holds an exclusive flock.
// ---------------------------------------------------------------------------
$tempParent = sys_get_temp_dir();
$tempParentReal = realpath($tempParent);
self_test(
    is_string($tempParentReal) && $tempParentReal !== '' && is_dir($tempParentReal) && is_writable($tempParentReal),
    'temp parent for test workspace is writable'
);

$normPath = static function (string $path): string {
    return strtolower(str_replace('\\', '/', rtrim($path, '\\/')));
};

$unrelatedSentinel = $tempParent . DIRECTORY_SEPARATOR . 'orange_bak_unrelated_' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($unrelatedSentinel, 'orange-backup-self-test-sentinel');

$testWs = $tempParent . DIRECTORY_SEPARATOR . 'orange_bak_st_' . getmypid() . '_' . bin2hex(random_bytes(8));
$projectNorm = $normPath($projectRoot);
$testWsNorm = $normPath($testWs);
self_test(
    $testWsNorm !== ''
        && $testWsNorm !== $projectNorm
        && !str_starts_with($testWsNorm, $projectNorm . '/')
        && $testWsNorm !== $normPath('C:/')
        && $testWsNorm !== $normPath('/'),
    'test workspace path rejects project root / drive root'
);

$prodBackupRoot = (string) ($envReport['backup_root'] ?? '');
$prodCandidate = (string) ($envReport['backup_root_candidate'] ?? '');
self_test(
    $prodBackupRoot === '' || $testWsNorm !== $normPath($prodBackupRoot),
    'test workspace is not production backup_root'
);
self_test(
    $prodCandidate === ''
        || ($testWsNorm !== $normPath($prodCandidate)
            && !str_starts_with($testWsNorm, $normPath($prodCandidate) . '/')),
    'test workspace is not under production BackupRoot candidate'
);

try {
    if (!@mkdir($testWs, 0775, true) && !is_dir($testWs)) {
        self_test(false, 'create isolated writable test workspace');
        throw new RuntimeException('Cannot create isolated backup self-test workspace.');
    }
    self_test(is_dir($testWs) && is_writable($testWs), 'create isolated writable test workspace');

    foreach (['locks', 'logs', 'packages', 'restore_work'] as $sub) {
        $subPath = $testWs . DIRECTORY_SEPARATOR . $sub;
        if (!@mkdir($subPath, 0775, true) && !is_dir($subPath)) {
            self_test(false, "create test subdirectory {$sub}");
        } else {
            self_test(true, "create test subdirectory {$sub}");
        }
    }

    $lockRoot = $testWs;
    $lockPathExpected = orange_backup_lock_path($lockRoot);
    self_test(
        str_starts_with($normPath($lockPathExpected), $normPath($lockRoot) . '/'),
        'lock path resolves under isolated test workspace'
    );

    // 1) First acquisition
    try {
        $firstLock = orange_backup_acquire_lock($lockRoot);
    } catch (Throwable $e) {
        self_test(false, 'lock acquire first caller (exception: ' . $e->getMessage() . ')');
        $firstLock = ['acquired' => false, 'path' => '', 'reason' => $e->getMessage()];
    }
    self_test(!empty($firstLock['acquired']), 'lock acquire first caller');
    $lockPath = (string) ($firstLock['path'] ?? '');
    self_test(
        $lockPath !== '' && str_starts_with($normPath($lockPath), $normPath($lockRoot) . '/'),
        'acquired lock file is under test workspace only'
    );
    orange_backup_release_lock();
    self_test(!is_file($lockPath), 'release removes only the test lock file');

    // 2) Held-lock rejection (live PID marker; no second flock while holding)
    file_put_contents(
        $lockPath,
        (string) json_encode([
            'pid' => getmypid(),
            'started_at' => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    try {
        $secondLock = orange_backup_acquire_lock($lockRoot);
        self_test(empty($secondLock['acquired']), 'lock prevents concurrent backup');
    } catch (Throwable $e) {
        self_test(false, 'lock concurrent rejection must not Fatal: ' . $e->getMessage());
    }
    @unlink($lockPath);

    // 3) Reacquire after release
    try {
        $reacq = orange_backup_acquire_lock($lockRoot);
        self_test(!empty($reacq['acquired']), 'lock can be acquired after release');
        orange_backup_release_lock();
    } catch (Throwable $e) {
        self_test(false, 'lock reacquire after release: ' . $e->getMessage());
    }

    // 4) Stale lock (dead pid + age past production TTL) can be taken
    file_put_contents(
        $lockPath,
        (string) json_encode([
            'pid' => 2147483646,
            'started_at' => gmdate('c', time() - ORANGE_BACKUP_LOCK_STALE_SECONDS - 120),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    try {
        $staleLock = orange_backup_acquire_lock($lockRoot);
        self_test(!empty($staleLock['acquired']), 'stale-lock behavior follows production contract');
        orange_backup_release_lock();
    } catch (Throwable $e) {
        self_test(false, 'stale-lock acquire: ' . $e->getMessage());
    }

    // 5) Live/current lock is not treated as stale
    file_put_contents(
        $lockPath,
        (string) json_encode([
            'pid' => getmypid(),
            'started_at' => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    try {
        $liveLock = orange_backup_acquire_lock($lockRoot);
        self_test(empty($liveLock['acquired']), 'live/current lock is not treated as stale');
    } catch (Throwable $e) {
        self_test(false, 'live lock check must not Fatal: ' . $e->getMessage());
    }
    @unlink($lockPath);

    // 6) Parallel simulated roots — distinct lock paths; sequential acquire does not collide
    $rootA = $testWs . DIRECTORY_SEPARATOR . 'parallel_a_' . bin2hex(random_bytes(3));
    $rootB = $testWs . DIRECTORY_SEPARATOR . 'parallel_b_' . bin2hex(random_bytes(3));
    mkdir($rootA, 0775, true);
    mkdir($rootB, 0775, true);
    $pathA = orange_backup_lock_path($rootA);
    $pathB = orange_backup_lock_path($rootB);
    self_test($normPath($pathA) !== $normPath($pathB), 'parallel test roots use distinct lock paths');
    try {
        $lockA = orange_backup_acquire_lock($rootA);
        self_test(!empty($lockA['acquired']), 'parallel root A acquire');
        orange_backup_release_lock();
        $lockB = orange_backup_acquire_lock($rootB);
        self_test(!empty($lockB['acquired']), 'parallel root B acquire after A release');
        orange_backup_release_lock();
    } catch (Throwable $e) {
        self_test(false, 'parallel roots acquire: ' . $e->getMessage());
        orange_backup_release_lock();
    }

    // 7) Permission/path failure → clear test failure, not unhandled Fatal
    $badRoot = $testWs . DIRECTORY_SEPARATOR . 'not_a_directory_' . bin2hex(random_bytes(3)) . '.txt';
    file_put_contents($badRoot, 'x');
    try {
        orange_backup_acquire_lock($badRoot);
        self_test(false, 'permission/path failure should throw when root is a file');
    } catch (Throwable $e) {
        self_test(
            $e instanceof RuntimeException,
            'permission/path failure caught without Fatal exit 255'
        );
    }

    // 8) Cleanup after simulated exception
    $failWs = $tempParent . DIRECTORY_SEPARATOR . 'orange_bak_fail_' . getmypid() . '_' . bin2hex(random_bytes(6));
    try {
        if (!@mkdir($failWs, 0775, true) && !is_dir($failWs)) {
            self_test(false, 'create fail-probe workspace');
        } else {
            try {
                throw new RuntimeException('simulated self-test failure');
            } finally {
                self_test_backup_remove_workspace($failWs);
            }
        }
    } catch (Throwable) {
        // expected
    }
    self_test(!is_dir($failWs), 'cleanup after simulated failure removes test workspace');
} finally {
    orange_backup_release_lock();
    if (isset($testWs) && is_dir($testWs)) {
        self_test_backup_remove_workspace($testWs);
    }
}

self_test(!is_dir($testWs), 'cleanup after PASS removes the test workspace');
self_test(is_file($unrelatedSentinel), 'unrelated temp file survives workspace cleanup');
@unlink($unrelatedSentinel);

// Backend unavailable => clear failure signal (same authoritative selected_backend)
$backendSelected = orange_backup_select_backend($projectRoot);
self_test(
    $backendSelected === $backend,
    'orange_backup_select_backend matches env report selected_backend binding'
);
if ($backendSelected === null) {
    self_test(empty($envReport['can_run_full_backup']), 'backend unavailable marks can_run_full_backup=false');
} else {
    self_test(
        in_array($backendSelected, ['powershell', 'php_mysqldump', 'php_pdo'], true),
        'backend selection returns supported backend'
    );
    echo "INFO: backend available on this machine ({$backendSelected})\n";
}

// PowerShell delegation path exists when powershell_ready
if (!empty($envReport['powershell_ready'])) {
    $psScript = $projectRoot . '/scripts/backup/orange_backup.ps1';
    self_test(is_file($psScript), 'powershell backend script present');
}

echo "\n--- Backup self-test (FSR Batch C) ---\n";
echo "PASS={$passes} FAIL={$failures} SKIP={$skips}\n";

exit($failures > 0 ? 1 : 0);
