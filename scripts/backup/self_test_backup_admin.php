<?php

declare(strict_types=1);

/**
 * Phase 3A — Admin Backup Center self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_backup_admin.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_paths.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_full.php';

$failures = 0;

function backup_admin_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function backup_admin_test_pdo(string $permKey, bool $canEdit, int $adminId = 2): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE admins (id INTEGER PRIMARY KEY, username TEXT, is_active INTEGER, is_superuser INTEGER, display_name TEXT, password_hash TEXT)');
    $pdo->exec('CREATE TABLE admin_permissions (admin_id INTEGER, resource_key TEXT, can_view INTEGER, can_edit INTEGER, can_delete INTEGER)');
    $pdo->exec('INSERT INTO admins VALUES (' . $adminId . ', \'op\', 1, 0, \'Op\', \'\')');
    if ($permKey !== '') {
        $pdo->exec('INSERT INTO admin_permissions VALUES (' . $adminId . ', ' . $pdo->quote($permKey) . ', 1, ' . ($canEdit ? '1' : '0') . ', 0)');
    }
    $GLOBALS['orange_schema_table_cache'] = ['admins' => true, 'admin_permissions' => true];
    $GLOBALS['orange_schema_column_cache'] = [
        'admin_permissions.can_lock' => false,
        'admin_permissions.can_unlock' => false,
        'admin_permissions.can_print' => false,
        'admin_permissions.can_export' => false,
    ];

    return $pdo;
}

$superAdmin = ['id' => 1, 'is_superuser' => 1, 'is_active' => 1];
$regularAdmin = ['id' => 2, 'is_superuser' => 0, 'is_active' => 1];
$noPermPdo = backup_admin_test_pdo('', false, 2);
$viewPdo = backup_admin_test_pdo('backup_view', false, 3);
$runPdo = backup_admin_test_pdo('backup_run', true, 4);
$verifyPdo = backup_admin_test_pdo('backup_verify', true, 5);

backup_admin_self_test(!orange_backup_admin_may_view($regularAdmin, $noPermPdo), 'auth: missing permission rejected for view');
backup_admin_self_test(orange_backup_admin_may_view($superAdmin, $noPermPdo), 'auth: superuser may view');
backup_admin_self_test(orange_backup_admin_may_view(['id' => 3, 'is_superuser' => 0, 'is_active' => 1], $viewPdo), 'auth: backup_view permission works');
backup_admin_self_test(!orange_backup_admin_may_run($regularAdmin, $viewPdo), 'auth: run permission required');
backup_admin_self_test(orange_backup_admin_may_run(['id' => 4, 'is_superuser' => 0, 'is_active' => 1], $runPdo), 'auth: backup_run permission works');
backup_admin_self_test(!orange_backup_admin_may_verify($regularAdmin, $viewPdo), 'auth: verify permission required');
backup_admin_self_test(orange_backup_admin_may_verify(['id' => 5, 'is_superuser' => 0, 'is_active' => 1], $verifyPdo), 'auth: backup_verify permission works');

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$_SESSION['orange_backup_admin_csrf'] = 'test-csrf-token';
try {
    orange_backup_admin_verify_csrf('test-csrf-token');
    backup_admin_self_test(true, 'csrf: valid token accepted');
} catch (Throwable) {
    backup_admin_self_test(false, 'csrf: valid token accepted');
}
try {
    orange_backup_admin_verify_csrf('bad-token');
    backup_admin_self_test(false, 'csrf: invalid token rejected');
} catch (Throwable) {
    backup_admin_self_test(true, 'csrf: invalid token rejected');
}

$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_bc_admin_' . bin2hex(random_bytes(3));
mkdir($tmpRoot);
$backupRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'backups';
mkdir($backupRoot);
$snapDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . '2026-07-15_120000';
mkdir($snapDir, 0775, true);
orange_backup_write_json($snapDir . DIRECTORY_SEPARATOR . 'manifest.json', [
    'package_type' => 'full_disaster',
    'generated_at' => gmdate('c'),
    'schema_revision' => 121,
    'export_backend' => 'php_pdo',
    'backup_status' => 'success',
    'dump_size_bytes' => 100,
    'uploads_size_bytes' => 50,
    'table_count' => 3,
]);
orange_backup_write_json($snapDir . DIRECTORY_SEPARATOR . 'health.json', [
    'package_status' => 'healthy',
]);

try {
    orange_backup_admin_resolve_full_package_path($backupRoot, '../evil');
    backup_admin_self_test(false, 'path: traversal package id rejected');
} catch (Throwable) {
    backup_admin_self_test(true, 'path: traversal package id rejected');
}
try {
    orange_backup_admin_resolve_full_package_path($backupRoot, 'not-a-valid-id');
    backup_admin_self_test(false, 'path: arbitrary package id rejected');
} catch (Throwable) {
    backup_admin_self_test(true, 'path: arbitrary package id rejected');
}
$resolved = orange_backup_admin_resolve_full_package_path($backupRoot, '2026-07-15_120000');
backup_admin_self_test(is_dir($resolved), 'path: allowlisted full package resolves');

$delegatedFull = false;
$resultFull = orange_backup_admin_run_full($projectRoot, [
    'run_full_override' => static function () use (&$delegatedFull): array {
        $delegatedFull = true;

        return ['ok' => true, 'backend' => 'test', 'snapshot' => '2026-07-15_120000', 'message' => 'ok', 'exit_code' => 0, 'log_file' => ''];
    },
]);
backup_admin_self_test($delegatedFull && ($resultFull['ok'] ?? false) === true, 'engine: full backup delegates via override');

$delegatedBatch = false;
$resultBatch = orange_backup_admin_run_country_batch($projectRoot, [
    'batch_override' => static function () use (&$delegatedBatch): array {
        $delegatedBatch = true;

        return ['ok' => true, 'exit_code' => 0, 'stdout' => '', 'stderr' => '', 'message' => 'ok'];
    },
]);
backup_admin_self_test($delegatedBatch && ($resultBatch['ok'] ?? false) === true, 'engine: country batch delegates via override');

$verifyResult = orange_backup_admin_verify_package('full_disaster', $resolved);
backup_admin_self_test(is_array($verifyResult) && array_key_exists('ok', $verifyResult), 'engine: verify delegates to orange_backup_verify_full_package');

$drvResult = orange_backup_admin_recovery_validate($resolved);
backup_admin_self_test(is_array($drvResult) && array_key_exists('recovery_score', $drvResult), 'engine: recovery validation delegates');

$locksDir = $backupRoot . DIRECTORY_SEPARATOR . 'locks';
mkdir($locksDir, 0775, true);
$lockPath = $locksDir . DIRECTORY_SEPARATOR . 'orange_full_backup.lock';
file_put_contents($lockPath, json_encode(['pid' => getmypid(), 'started_at' => gmdate('c')], JSON_THROW_ON_ERROR));
$lockTry = orange_backup_acquire_lock($backupRoot);
backup_admin_self_test(($lockTry['acquired'] ?? true) === false, 'engine: concurrent backup lock rejected');
if ($lockTry['acquired'] ?? false) {
    orange_backup_release_lock();
}
@unlink($lockPath);

$redacted = orange_backup_admin_redact_secrets([
    'db_pass' => 'secret',
    'manifest' => ['token' => 'abc', 'table_count' => 1],
]);
backup_admin_self_test(!isset($redacted['db_pass']) && !isset($redacted['manifest']['token']), 'security: secrets redacted from admin payloads');

$redactedText = orange_backup_admin_redact_text('DB_PASS=supersecret token=abc123');
backup_admin_self_test(!str_contains($redactedText, 'supersecret') && !str_contains($redactedText, 'abc123'), 'security: secret values redacted from text payloads');
backup_admin_self_test(!in_array('checksums.sha256', ORANGE_BACKUP_ADMIN_VIEWABLE_FILES, true), 'security: checksums file not in view allowlist');

$pageSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'backup_center.php');
backup_admin_self_test(!str_contains($pageSource, 'restore_run_full') && !str_contains($pageSource, 'rollback'), 'scope: no restore UI actions in backup_center page');
backup_admin_self_test(!str_contains($pageSource, 'delete'), 'scope: no delete action in backup_center page');

$apiFiles = [
    'run-full.php',
    'run-countries.php',
    'verify.php',
    'recovery-check.php',
    'list.php',
    'status.php',
];
foreach ($apiFiles as $file) {
    $src = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . $file);
    backup_admin_self_test(!str_contains(strtolower($src), 'restore_') && !str_contains(strtolower($src), 'rollback'), 'scope: API ' . $file . ' has no restore/rollback');
    backup_admin_self_test(!str_contains(strtolower($src), 'delete'), 'scope: API ' . $file . ' has no delete');
}

backup_admin_self_test(defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION') && ORANGE_CATALOG_SCHEMA_PHP_REVISION === 121, 'schema: revision remains 121');

// Cleanup
@rmdir($locksDir);
@unlink($snapDir . DIRECTORY_SEPARATOR . 'manifest.json');
@unlink($snapDir . DIRECTORY_SEPARATOR . 'health.json');
@rmdir($snapDir);
@rmdir($backupRoot . DIRECTORY_SEPARATOR . 'snapshots');
@rmdir($backupRoot);
@rmdir($tmpRoot);

exit($failures > 0 ? 1 : 0);
