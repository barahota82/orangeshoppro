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

define('ORANGE_BACKUP_ADMIN_SELF_TEST', true);

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

function backup_admin_test_set_readonly(string $dir): bool
{
    if (!is_dir($dir)) {
        return false;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $user = getenv('USERNAME') ?: getenv('USER');
        if (!is_string($user) || $user === '') {
            return false;
        }
        exec('icacls ' . escapeshellarg($dir) . ' /inheritance:r', $out, $code1);
        exec('icacls ' . escapeshellarg($dir) . ' /grant:r ' . escapeshellarg($user . ':(R)'), $out, $code2);

        return $code1 === 0 && $code2 === 0 && !is_writable($dir);
    }

    if (!@chmod($dir, 0555)) {
        return false;
    }

    return !is_writable($dir);
}

function backup_admin_test_restore_writable(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $user = getenv('USERNAME') ?: getenv('USER');
        exec('icacls ' . escapeshellarg($dir) . ' /reset', $out, $code);
        if (is_string($user) && $user !== '') {
            exec('icacls ' . escapeshellarg($dir) . ' /grant:r ' . escapeshellarg($user . ':(OI)(CI)F'), $out, $code);
        }
    } else {
        @chmod($dir, 0775);
    }
}

function backup_admin_test_remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            backup_admin_test_remove_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
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

$tempRunnerLog = $backupRoot . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'selftest_runner.log';
@mkdir(dirname($tempRunnerLog), 0775, true);
$outerStdout = '';
ob_start();
try {
    $apiFullResult = orange_backup_admin_run_full_for_api($projectRoot, [
        'run_full_override' => static function () use ($tempRunnerLog): array {
            orange_backup_runner_log($tempRunnerLog, 'Self-test progress line one');
            orange_backup_runner_log($tempRunnerLog, 'Self-test progress line two');

            return [
                'ok' => true,
                'backend' => 'test',
                'snapshot' => '2026-07-15_120000',
                'message' => 'ok',
                'exit_code' => 0,
                'log_file' => $tempRunnerLog,
            ];
        },
    ]);
} finally {
    $outerStdout = ob_get_clean();
}
backup_admin_self_test($outerStdout === '', 'api: run_full_for_api suppresses runner stdout');
backup_admin_self_test(is_file($tempRunnerLog) && str_contains((string) file_get_contents($tempRunnerLog), 'Self-test progress line one'), 'api: run_full_for_api preserves runner log file output');
$apiJsonBody = json_encode([
    'success' => true,
    'message' => 'ok',
    'result' => orange_backup_admin_redact_secrets($apiFullResult),
], JSON_THROW_ON_ERROR);
$decodedApiBody = json_decode($apiJsonBody, true);
backup_admin_self_test(is_array($decodedApiBody) && ($decodedApiBody['success'] ?? false) === true, 'api: run_full_for_api payload is valid JSON');
$cliStdoutSample = "[2026-07-16 00:00:00] [INFO] Orange full backup started.\n"
    . '{"ok":true,"backend":"php_pdo","snapshot":"2026-07-16_120000","log_file":"x.log","message":"ok"}' . "\n";
$cliParsed = orange_backup_admin_parse_run_full_cli_stdout($cliStdoutSample, 0);
backup_admin_self_test(($cliParsed['ok'] ?? false) === true && ($cliParsed['snapshot'] ?? '') === '2026-07-16_120000', 'api: parse run_full CLI stdout after runner logs');
$runFullCmd = orange_backup_admin_run_full_cli_command($projectRoot);
backup_admin_self_test(count($runFullCmd) === 2, 'api: run_full admin action launches fixed CLI argv array');
backup_admin_self_test(str_ends_with(str_replace('\\', '/', $runFullCmd[1]), 'scripts/backup/run_full_backup.php'), 'api: run_full uses approved run_full_backup.php entry');
backup_admin_self_test(!str_contains($runFullCmd[0], '&') && !str_contains($runFullCmd[0], '|'), 'api: cli php binary has no shell metacharacters');
$failExit = orange_backup_admin_parse_run_full_cli_stdout('{"ok":false,"message":"Backup already running."}', 2, '');
backup_admin_self_test(($failExit['ok'] ?? true) === false && (int) ($failExit['exit_code'] ?? 0) === 2, 'api: non-zero exit returns failure');
$lockFail = orange_backup_admin_parse_run_full_cli_stdout('{"ok":false,"message":"Backup already running."}', 2, 'lock busy');
backup_admin_self_test(($lockFail['ok'] ?? true) === false, 'api: engine lock failure remains failure');
$timeoutFail = orange_backup_admin_parse_run_full_cli_stdout('', 124, 'Command timed out.');
backup_admin_self_test(($timeoutFail['ok'] ?? true) === false && ($timeoutFail['error'] ?? '') !== '', 'api: timeout returns sanitized failure');
$stderrSan = orange_backup_admin_parse_run_full_cli_stdout('', 1, 'DB_PASS=secret token=abc');
backup_admin_self_test(($stderrSan['ok'] ?? true) === false && !str_contains((string) ($stderrSan['error'] ?? ''), 'secret'), 'api: stderr sanitized before api error field');
$successExit = orange_backup_admin_parse_run_full_cli_stdout($cliStdoutSample, 0);
backup_admin_self_test(($successExit['ok'] ?? false) === true && (int) ($successExit['exit_code'] ?? 1) === 0, 'api: successful exit returns success');
$adminSrc = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php');
backup_admin_self_test(str_contains($adminSrc, 'orange_backup_admin_run_full_cli_command') && str_contains($adminSrc, 'orange_backup_run_command_capture'), 'api: run_full_for_api delegates via CLI capture');
backup_admin_self_test(str_contains($adminSrc, 'orange_backup_admin_self_test_enabled'), 'api: self-test override gated from production');
backup_admin_self_test(str_contains($adminSrc, 'orange_backup_admin_resolve_cli_php_binary'), 'api: resolves approved cli php binary');
$pdoExportSrc = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_pdo_export.php');
backup_admin_self_test(str_contains($pdoExportSrc, "PHP_SAPI !== 'cli'") && str_contains($pdoExportSrc, 'PDO export is CLI-only.'), 'security: PDO export cli-only guard unchanged');
$runFullApi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'run-full.php');
backup_admin_self_test(str_contains($runFullApi, 'orange_backup_admin_run_full_for_api') && !str_contains($runFullApi, 'orange_backup_run_full('), 'api: run-full.php does not call engine in-process');
$bootstrapSrc = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . '_bootstrap.php');
backup_admin_self_test(str_contains($bootstrapSrc, 'backup_admin_api_begin_json_only') && str_contains($bootstrapSrc, 'backup_admin_api_json_shutdown_guard'), 'api: backup bootstrap enforces json-only guard');
backup_admin_self_test(str_contains($bootstrapSrc, 'application/json'), 'api: backup bootstrap sets json content type early');
$configSrc = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'config.php');
backup_admin_self_test(str_contains($configSrc, 'ORANGE_JSON_RESPONSE_EMITTED'), 'api: json_response marks emitted json and clears buffers');
backup_admin_self_test(str_contains($configSrc, "json_response(['success' => false, 'message' => 'غير مصرح'], 401)"), 'api: unauthenticated admin api returns json 401');
$runnerOnly = orange_backup_admin_classify_captured_stdout("[2026-07-16 00:00:00] [INFO] Orange full backup started.\n");
backup_admin_self_test(($runnerOnly['type'] ?? '') === 'runner_log', 'api: runner log stdout classified as runner_log');
$phpOnly = orange_backup_admin_classify_captured_stdout("Warning: something bad in /path/file.php on line 1\n");
backup_admin_self_test(($phpOnly['type'] ?? '') === 'php_error', 'api: php warning stdout classified as php_error');
@unlink($tempRunnerLog);

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
$restorePageSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'restore_center.php');
$headerSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php');
backup_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'restore_center.php'), 'phase3b: restore_center page exists');
backup_admin_self_test(str_contains($restorePageSource, 'Phase 3B.1') && str_contains($restorePageSource, 'للعرض والمتابعة فقط'), 'phase3b.1: restore_center read-only dashboard');
backup_admin_self_test(strpos($headerSource, 'backup_center') !== false && strpos($headerSource, 'restore_center') !== false && strpos($headerSource, 'backup_center') < strpos($headerSource, 'restore_center'), 'phase3b: menu order backup_center then restore_center');
backup_admin_self_test(!str_contains($restorePageSource, 'restore_admin.php'), 'phase3b.1: restore_center page does not load restore_admin at render');
backup_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'self_test_restore_admin.php'), 'phase3b.1: dedicated restore admin self-test exists');
backup_admin_self_test(str_contains($pageSource, 'parseApiJsonResponse') && str_contains($pageSource, 'text/html'), 'ui: api client detects html responses before json parse');
backup_admin_self_test(str_contains($pageSource, '\\u0627\\u0633\\u062a\\u062c\\u0627\\u0628'), 'ui: sanitized arabic message for non-json responses');
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

$strictResolveSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_paths.php');
backup_admin_self_test(str_contains($strictResolveSource, 'is_writable($resolved)') || str_contains($strictResolveSource, 'is_writable($candidate)'), 'engine: strict orange_backup_resolve_root still checks writable');

$viewCtxWritable = orange_backup_admin_context_for_view($projectRoot);
backup_admin_self_test(($viewCtxWritable['root_health']['readable'] ?? false) === true, 'view: context succeeds when BackupRoot is readable');

$fakeProjectRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'fake_project';
if (!is_dir($fakeProjectRoot)) {
    mkdir($fakeProjectRoot, 0775, true);
}
$readonlyBackupRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'readonly_backups';
if (is_dir($readonlyBackupRoot)) {
    backup_admin_test_remove_tree($readonlyBackupRoot);
}
mkdir($readonlyBackupRoot, 0775, true);
$readonlySnap = $readonlyBackupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . '2026-07-15_130000';
mkdir($readonlySnap, 0775, true);
orange_backup_write_json($readonlySnap . DIRECTORY_SEPARATOR . 'manifest.json', [
    'package_type' => 'full_disaster',
    'generated_at' => gmdate('c'),
    'schema_revision' => 121,
    'backup_status' => 'success',
]);
file_put_contents(
    $fakeProjectRoot . DIRECTORY_SEPARATOR . '.env.php',
    "<?php\nreturn ['ORANGE_BACKUP_ROOT' => " . var_export($readonlyBackupRoot, true) . "];\n"
);

$viewBeforeReadonly = orange_backup_admin_resolve_root_for_view($fakeProjectRoot);
backup_admin_self_test($viewBeforeReadonly['readable'] === true && $viewBeforeReadonly['writable'] === true, 'view: writable root reports manual_actions_available');

$readonlyApplied = backup_admin_test_set_readonly($readonlyBackupRoot);
if ($readonlyApplied) {
    $viewReadonly = orange_backup_admin_resolve_root_for_view($fakeProjectRoot);
    backup_admin_self_test($viewReadonly['readable'] === true, 'view: readable=true when root is read-only for PHP');
    backup_admin_self_test($viewReadonly['writable'] === false, 'view: writable=false does not throw');
    backup_admin_self_test($viewReadonly['manual_actions_available'] === false, 'view: manual_actions_available=false when non-writable');
    backup_admin_self_test($viewReadonly['warning'] === ORANGE_BACKUP_ADMIN_ROOT_READONLY_WARNING, 'view: readonly warning constant returned');

    $listed = orange_backup_admin_list_full_snapshots($readonlyBackupRoot, 10);
    backup_admin_self_test(count($listed) >= 1, 'view: list discovers packages on readable non-writable root');

    $healthPayload = orange_backup_admin_root_health_payload($viewReadonly);
    backup_admin_self_test($healthPayload['manual_actions_available'] === false, 'view: health payload exposes manual_actions_available=false');

    $blockMsg = orange_backup_admin_manual_actions_block_message($fakeProjectRoot);
    backup_admin_self_test(is_string($blockMsg) && $blockMsg !== '', 'mutation: manual Full/Country blocked before engine when non-writable');

    try {
        orange_backup_admin_assert_manual_actions_available($fakeProjectRoot);
        backup_admin_self_test(false, 'mutation: assert manual actions throws when non-writable');
    } catch (Throwable) {
        backup_admin_self_test(true, 'mutation: assert manual actions throws when non-writable');
    }

    try {
        orange_backup_resolve_root(orange_backup_load_env_array($fakeProjectRoot));
        backup_admin_self_test(false, 'engine: strict resolve rejects non-writable root');
    } catch (Throwable) {
        backup_admin_self_test(true, 'engine: strict resolve rejects non-writable root');
    }

    backup_admin_test_restore_writable($readonlyBackupRoot);
} else {
    echo "SKIP: readonly ACL simulation unavailable on this host\n";
}

try {
    orange_backup_admin_validate_configured_root_candidate('C:/inetpub/vhosts/example.com/httpdocs/orange_backups');
    backup_admin_self_test(false, 'view: public web root candidate rejected');
} catch (Throwable) {
    backup_admin_self_test(true, 'view: public web root candidate rejected');
}

try {
    file_put_contents(
        $fakeProjectRoot . DIRECTORY_SEPARATOR . '.env.php',
        "<?php\nreturn ['ORANGE_BACKUP_ROOT' => " . var_export($tmpRoot . DIRECTORY_SEPARATOR . 'missing_backup_root_' . bin2hex(random_bytes(3)), true) . "];\n"
    );
    orange_backup_admin_resolve_root_for_view($fakeProjectRoot);
    backup_admin_self_test(false, 'view: missing BackupRoot directory fails closed');
} catch (Throwable) {
    backup_admin_self_test(true, 'view: missing BackupRoot directory fails closed');
}

$listApi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'list.php');
backup_admin_self_test(str_contains($listApi, 'orange_backup_admin_context_for_view'), 'api: list.php uses view context');
backup_admin_self_test(str_contains($listApi, 'backup_root_health'), 'api: list.php returns backup_root_health');

$statusApi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'status.php');
backup_admin_self_test(str_contains($statusApi, 'orange_backup_admin_context_for_view'), 'api: status.php uses view context');

$verifyApi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'verify.php');
backup_admin_self_test(str_contains($verifyApi, 'orange_backup_admin_context_for_view'), 'api: verify.php uses view context (read-only verify)');

$recoveryApi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'recovery-check.php');
backup_admin_self_test(str_contains($recoveryApi, 'manual_actions_block_message'), 'api: recovery-check.php blocks before write when non-writable');
backup_admin_self_test(str_contains($recoveryApi, 'context_for_mutation'), 'api: recovery-check.php uses mutation context after writable check');

$runFullApi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'run-full.php');
backup_admin_self_test(str_contains($runFullApi, 'manual_actions_block_message') && str_contains($runFullApi, 'orange_backup_admin_run_full_for_api'), 'api: run-full.php blocks then delegates via run_full_for_api when writable');
backup_admin_self_test(!str_contains($runFullApi, 'partials/header') && !str_contains($runFullApi, 'admin/index.php'), 'api: run-full.php does not include admin layout');

$runCountriesApi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'run-countries.php');
backup_admin_self_test(str_contains($runCountriesApi, 'manual_actions_block_message') && str_contains($runCountriesApi, 'orange_backup_admin_run_country_batch'), 'api: run-countries.php blocks then delegates only when writable');

backup_admin_self_test(str_contains($pageSource, 'bc_root_warning'), 'ui: writable warning banner element present');
backup_admin_self_test(str_contains($pageSource, 'renderRootHealth'), 'ui: renderRootHealth without generic alert for readonly health');
backup_admin_self_test(str_contains($pageSource, 'applyActionAvailability'), 'ui: run buttons disabled via applyActionAvailability');
backup_admin_self_test(str_contains($pageSource, ORANGE_BACKUP_ADMIN_ROOT_READONLY_WARNING), 'ui: Arabic readonly warning text embedded for banner');

$listApiUsesMutation = str_contains($listApi, 'orange_backup_admin_context_for_mutation') || str_contains($listApi, 'orange_backup_admin_run_full');
backup_admin_self_test(!$listApiUsesMutation, 'view: list.php does not invoke mutation engine paths');

$statusApiUsesMutation = str_contains($statusApi, 'orange_backup_admin_run_full') || str_contains($statusApi, 'orange_backup_admin_run_country_batch');
backup_admin_self_test(!$statusApiUsesMutation, 'view: status.php does not invoke backup run engines');

@unlink($fakeProjectRoot . DIRECTORY_SEPARATOR . '.env.php');
@unlink($readonlySnap . DIRECTORY_SEPARATOR . 'manifest.json');
@rmdir($readonlySnap);
@rmdir($readonlyBackupRoot . DIRECTORY_SEPARATOR . 'snapshots');
@rmdir($readonlyBackupRoot);
@rmdir($fakeProjectRoot);

// Cleanup
@rmdir($locksDir);
@unlink($snapDir . DIRECTORY_SEPARATOR . 'manifest.json');
@unlink($snapDir . DIRECTORY_SEPARATOR . 'health.json');
@rmdir($snapDir);
@rmdir($backupRoot . DIRECTORY_SEPARATOR . 'snapshots');
@rmdir($backupRoot);
@rmdir($tmpRoot);

exit($failures > 0 ? 1 : 0);
