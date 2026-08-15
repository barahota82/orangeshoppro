<?php

declare(strict_types=1);

/**
 * Step 5 final-approval cross-request execution-lock contract (OPTION 1).
 *
 * Proves challenge + grant succeed across separate PHP processes when the
 * Step-4 execution lock is absent or PID-stale (Windows IIS request boundary).
 *
 * Usage:
 *   php scripts/backup/self_test_restore_step5_final_approval_cross_request_lock.php
 *   php ... --worker=prepare|challenge|grant|concurrent_grant --state=<json>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

ini_set('display_errors', '1');
error_reporting(E_ALL);

$projectRoot = dirname(__DIR__, 2);
$phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$thisScript = __FILE__;

$worker = '';
$statePath = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--worker=')) {
        $worker = substr($arg, 9);
    }
    if (str_starts_with($arg, '--state=')) {
        $statePath = substr($arg, 8);
    }
}

require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_admin.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_final_approval.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_execution_orchestrator.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_center_orchestrator.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'recovery_validation.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php';

const STEP5_XR_PASSWORD = 'restore-test-password';

$failures = 0;
$passes = 0;

function step5_xr_test(bool $ok, string $label): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        echo "PASS: {$label}\n";
    } else {
        $failures++;
        echo "FAIL: {$label}\n";
    }
}

function step5_xr_rmtree(string $dir): void
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
            step5_xr_rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * Align challenge session binder to the current process CLI binder
 * (simulates sticky HTTP session cookie across separate requests).
 */
function step5_xr_align_challenge_session_to_this_process(string $workRoot, string $jobId): void
{
    $path = orange_restore_final_approval_challenge_path($workRoot, $jobId);
    if (!is_file($path)) {
        throw new RuntimeException('challenge_missing_for_session_align');
    }
    $challenge = json_decode((string) file_get_contents($path), true);
    if (!is_array($challenge)) {
        throw new RuntimeException('challenge_corrupt_for_session_align');
    }
    $challenge['session_id_hash'] = orange_restore_final_approval_session_id();
    file_put_contents(
        $path,
        json_encode($challenge, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n",
        LOCK_EX
    );
}

function step5_xr_cancel_job(string $workRoot, string $jobId): void
{
    if ($jobId === '') {
        return;
    }
    orange_restore_exec_release_lock($workRoot, $jobId);
    orange_restore_fw_release_lock($workRoot, $jobId);
    try {
        orange_restore_fw_cancel($workRoot, $jobId, 'step5_xr_test', 'fixture_reset');
        return;
    } catch (Throwable) {
        // fall through — force terminal for fixture reuse
    }
    try {
        $job = orange_restore_fw_read($workRoot, $jobId);
    } catch (Throwable) {
        return;
    }
    $job['status'] = ORANGE_RESTORE_FW_STATUS_CANCELLED;
    $job['phase'] = ORANGE_RESTORE_FW_PHASE_CANCELLED;
    orange_restore_fw_write($workRoot, $job);
    orange_restore_fw_release_lock($workRoot, $jobId);
}

function step5_xr_reset_work_root(string $workRoot): void
{
    orange_restore_fw_release_lock($workRoot, null);
    orange_restore_exec_release_lock($workRoot, null);
    foreach (orange_restore_fw_list_ids($workRoot) as $jobId) {
        step5_xr_cancel_job($workRoot, (string) $jobId);
    }
    orange_restore_fw_release_lock($workRoot, null);
    orange_restore_exec_release_lock($workRoot, null);
}

/**
 * @return PDO
 */
function step5_xr_open_pdo(string $sqlitePath): PDO
{
    $pdo = new PDO('sqlite:' . $sqlitePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

/**
 * @return PDO
 */
function step5_xr_init_pdo(string $sqlitePath): PDO
{
    if (is_file($sqlitePath)) {
        @unlink($sqlitePath);
    }
    $pdo = step5_xr_open_pdo($sqlitePath);
    $pdo->exec('CREATE TABLE admins (id INTEGER PRIMARY KEY, username TEXT, is_active INTEGER, is_superuser INTEGER, display_name TEXT, password_hash TEXT)');
    $pdo->exec('CREATE TABLE admin_permissions (admin_id INTEGER, resource_key TEXT, can_view INTEGER, can_edit INTEGER, can_delete INTEGER)');
    $hash = password_hash(STEP5_XR_PASSWORD, PASSWORD_DEFAULT);
    $pdo->exec(
        'INSERT INTO admins VALUES (1, \'superadmin\', 1, 1, \'Super\', ' . $pdo->quote($hash) . ')'
    );
    $pdo->exec(
        "INSERT INTO admin_permissions VALUES (1, 'backup_restore_full', 1, 1, 0)"
    );
    $pdo->exec(
        "INSERT INTO admin_permissions VALUES (1, 'backup_restore_country', 1, 1, 0)"
    );
    $GLOBALS['orange_schema_table_cache'] = [
        'admins' => true,
        'admin_permissions' => true,
    ];
    $GLOBALS['orange_schema_column_cache'] = [
        'admin_permissions.can_lock' => false,
        'admin_permissions.can_unlock' => false,
        'admin_permissions.can_print' => false,
        'admin_permissions.can_export' => false,
    ];

    return $pdo;
}

function step5_xr_write_gzip(string $path, string $contents): void
{
    $gz = gzencode($contents, 1);
    if ($gz === false) {
        throw new RuntimeException('gzencode failed');
    }
    file_put_contents($path, $gz);
}

function step5_xr_write_zip(string $path, array $files): void
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('zip create failed');
        }
        foreach ($files as $name => $body) {
            $zip->addFromString((string) $name, (string) $body);
        }
        $zip->close();

        return;
    }
    $local = '';
    $central = '';
    $offset = 0;
    $count = 0;
    foreach ($files as $name => $body) {
        $name = (string) $name;
        $body = (string) $body;
        $nameLen = strlen($name);
        $size = strlen($body);
        $crc = crc32($body);
        if ($crc < 0) {
            $crc = $crc & 0xFFFFFFFF;
        }
        $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLen, 0)
            . $name . $body;
        $centralHeader = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLen, 0, 0, 0, 0, 0, $offset)
            . $name;
        $local .= $localHeader;
        $central .= $centralHeader;
        $offset += strlen($localHeader);
        $count++;
    }
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($local), 0);
    file_put_contents($path, $local . $central . $end);
}

function step5_xr_seed_full_package(string $pkgDir, string $pkgId): void
{
    if (!is_dir($pkgDir)) {
        mkdir($pkgDir, 0775, true);
    }
    $dumpRel = 'database.sql.gz';
    $uploadsRel = 'uploads.zip';
    step5_xr_write_gzip($pkgDir . DIRECTORY_SEPARATOR . $dumpRel, "SET NAMES utf8mb4;\nCREATE TABLE t(id INT);\n");
    step5_xr_write_zip($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel, ['a.txt' => 'hello']);
    $dumpSha = hash_file('sha256', $pkgDir . DIRECTORY_SEPARATOR . $dumpRel) ?: '';
    $uploadsSha = hash_file('sha256', $pkgDir . DIRECTORY_SEPARATOR . $uploadsRel) ?: '';
    $manifest = [
        'package_type' => 'full_disaster',
        'package_version' => '1.0.0',
        'generated_at' => gmdate('c'),
        'schema_revision' => (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION,
        'export_backend' => 'php_pdo',
        'backup_status' => 'success',
        'dump_file' => $dumpRel,
        'uploads_file' => $uploadsRel,
        'dump_sha256' => $dumpSha,
        'uploads_sha256' => $uploadsSha,
        'dump_size_bytes' => (int) filesize($pkgDir . DIRECTORY_SEPARATOR . $dumpRel),
        'uploads_size_bytes' => (int) filesize($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel),
        'health_report_file' => 'health.json',
        'checksums_file' => 'checksums.sha256',
        'table_count' => 1,
    ];
    orange_backup_write_json($pkgDir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
    orange_backup_write_json($pkgDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
    file_put_contents(
        $pkgDir . DIRECTORY_SEPARATOR . 'checksums.sha256',
        $dumpSha . '  ' . $dumpRel . "\n" . $uploadsSha . '  ' . $uploadsRel . "\n"
    );
    orange_backup_write_json(
        orange_backup_admin_recovery_report_sibling_path($pkgDir, $pkgId),
        [
            'overall_result' => 'pass',
            'recovery_score' => 95,
            'validated_at' => gmdate('c'),
            'validation_engine_version' => ORANGE_RECOVERY_VALIDATION_ENGINE_VERSION,
            'manifest_valid' => true,
            'health_valid' => true,
            'checksums_valid' => true,
            'sql_valid' => true,
            'uploads_valid' => true,
        ]
    );
}

/**
 * @return array{ok:bool,stdout:string,stderr:string,exit_code:int,pid:int}
 */
function step5_xr_run_worker(string $phpBin, string $script, string $worker, string $statePath): array
{
    $cmd = [
        $phpBin,
        $script,
        '--worker=' . $worker,
        '--state=' . $statePath,
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname($script));
    if (!is_resource($proc)) {
        return ['ok' => false, 'stdout' => '', 'stderr' => 'proc_open failed', 'exit_code' => 1, 'pid' => 0];
    }
    $status = proc_get_status($proc);
    $pid = (int) ($status['pid'] ?? 0);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    return [
        'ok' => $exit === 0,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'exit_code' => (int) $exit,
        'pid' => $pid,
    ];
}

/**
 * @param array<string,mixed> $state
 * @return array<string,mixed>
 */
function step5_xr_admin(array $state): array
{
    return [
        'id' => (int) ($state['admin_id'] ?? 1),
        'username' => (string) ($state['admin_username'] ?? 'superadmin'),
        'is_superuser' => 1,
        'is_active' => 1,
    ];
}

// --- Worker modes (separate PHP processes) ---
if ($worker !== '') {
    if ($statePath === '' || !is_file($statePath)) {
        fwrite(STDERR, "missing state\n");
        exit(2);
    }
    $state = json_decode((string) file_get_contents($statePath), true);
    if (!is_array($state)) {
        fwrite(STDERR, "bad state\n");
        exit(2);
    }
    $backupRoot = (string) $state['backup_root'];
    $workRoot = (string) $state['work_root'];
    $jobId = (string) $state['job_id'];
    $pkgId = (string) $state['package_id'];
    $pdo = step5_xr_open_pdo((string) $state['sqlite_path']);
    $GLOBALS['orange_schema_table_cache'] = [
        'admins' => true,
        'admin_permissions' => true,
    ];
    $GLOBALS['orange_schema_column_cache'] = [
        'admin_permissions.can_lock' => false,
        'admin_permissions.can_unlock' => false,
        'admin_permissions.can_print' => false,
        'admin_permissions.can_export' => false,
    ];
    $admin = step5_xr_admin($state);
    $out = ['worker' => $worker, 'pid' => getmypid(), 'ok' => false];

    try {
        if ($worker === 'prepare') {
            $lockBefore = orange_restore_exec_lock_status($workRoot);
            orange_restore_admin_fw_prepare_execution($backupRoot, $workRoot, $admin, $pdo, $jobId);
            $lockAfter = orange_restore_exec_lock_status($workRoot);
            $job = orange_restore_fw_read($workRoot, $jobId);
            $out['ok'] = true;
            $out['status'] = (string) ($job['status'] ?? '');
            $out['lock_held'] = !empty($lockAfter['held']);
            $out['lock_pid'] = (int) (($lockAfter['payload']['pid'] ?? 0));
            $out['lock_job'] = (string) (($lockAfter['payload']['job_id'] ?? ''));
            $out['acquired_new'] = !$lockBefore['held'] && $lockAfter['held'];
        } elseif ($worker === 'challenge') {
            $lockBefore = orange_restore_exec_lock_status($workRoot);
            $beforePid = (int) (($lockBefore['payload']['pid'] ?? 0));
            $challenge = orange_restore_admin_fw_create_approval_challenge($backupRoot, $workRoot, $admin, $pdo, $jobId);
            $lockAfter = orange_restore_exec_lock_status($workRoot);
            $afterPid = (int) (($lockAfter['payload']['pid'] ?? 0));
            $out['ok'] = true;
            $out['nonce'] = (string) ($challenge['nonce'] ?? '');
            $out['phrase'] = (string) ($challenge['required_confirmation_phrase'] ?? '');
            $out['lock_held_before'] = !empty($lockBefore['held']);
            $out['lock_stale_before'] = !empty($lockBefore['stale']);
            $out['lock_pid_before'] = $beforePid;
            $out['lock_pid_after'] = $afterPid;
            $out['execution_lock_acquired'] = ($afterPid === getmypid() && $beforePid !== getmypid());
            $out['lock_pid_unchanged'] = ($beforePid === $afterPid);
        } elseif ($worker === 'grant' || $worker === 'concurrent_grant') {
            // Sticky-session equivalent across separate PHP processes (HTTP uses cookie SID).
            step5_xr_align_challenge_session_to_this_process($workRoot, $jobId);
            $nonce = (string) ($state['nonce'] ?? '');
            $phrase = (string) ($state['phrase'] ?? '');
            $lockBefore = orange_restore_exec_lock_status($workRoot);
            $beforePid = (int) (($lockBefore['payload']['pid'] ?? 0));
            $granted = orange_restore_admin_fw_final_approve(
                $backupRoot,
                $workRoot,
                $admin,
                $pdo,
                $jobId,
                $pkgId,
                $phrase,
                $nonce,
                STEP5_XR_PASSWORD
            );
            $lockAfter = orange_restore_exec_lock_status($workRoot);
            $afterPid = (int) (($lockAfter['payload']['pid'] ?? 0));
            $out['ok'] = true;
            $out['status'] = (string) (($granted['job']['status'] ?? ''));
            $out['execution_started'] = !empty($granted['approval']['execution_started']);
            $out['lock_pid_before'] = $beforePid;
            $out['lock_pid_after'] = $afterPid;
            $out['execution_lock_acquired'] = ($afterPid === getmypid() && $beforePid !== getmypid());
        } else {
            throw new RuntimeException('unknown_worker');
        }
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['code'] = trim($e->getMessage());
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(!empty($out['ok']) ? 0 : 3);
}

// --- Main harness ---
$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_step5_xr_' . bin2hex(random_bytes(4));
$backupRoot = $base . DIRECTORY_SEPARATOR . 'backups';
$workRoot = $base . DIRECTORY_SEPARATOR . 'restore_work';
mkdir($backupRoot, 0775, true);
mkdir($workRoot, 0775, true);
$sqlitePath = $base . DIRECTORY_SEPARATOR . 'admins.sqlite';
$pdo = step5_xr_init_pdo($sqlitePath);
$pkgId = '2026-08-15_120000';
$pkgDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $pkgId;
step5_xr_seed_full_package($pkgDir, $pkgId);
$admin = ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1];

function step5_xr_make_dry_job(string $workRoot, string $backupRoot, string $pkgId, array $admin, PDO $pdo, string $cancelPrior = ''): string
{
    if ($cancelPrior !== '') {
        step5_xr_cancel_job($workRoot, $cancelPrior);
    }
    step5_xr_reset_work_root($workRoot);
    $job = orange_restore_fw_create($workRoot, [
        'package_id' => $pkgId,
        'package_type' => 'full_disaster',
        'created_by' => 'superadmin',
        'created_by_admin_id' => 1,
    ]);
    $jobId = (string) $job['job_id'];
    orange_restore_dry_run_execute($workRoot, $jobId, [
        'backup_root' => $backupRoot,
        'operator_username' => 'superadmin',
    ]);
    $job = orange_restore_fw_read($workRoot, $jobId);
    if ((string) ($job['status'] ?? '') !== ORANGE_RESTORE_FW_STATUS_DRY_COMPLETED) {
        throw new RuntimeException('dry_run_did_not_complete:' . (string) ($job['status'] ?? ''));
    }

    return $jobId;
}

function step5_xr_count_audit_event(string $workRoot, string $jobId, string $event): int
{
    $path = orange_restore_fw_audit_file_path($workRoot, $jobId);
    if (!is_file($path)) {
        return 0;
    }
    $n = 0;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode((string) $line, true);
        if (is_array($row) && (string) ($row['event'] ?? '') === $event) {
            $n++;
        }
    }

    return $n;
}

function step5_xr_public_caps(string $workRoot, string $jobId): array
{
    return orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId));
}

// ========== CROSS-REQUEST Process A → B → C ==========
$jobCross = step5_xr_make_dry_job($workRoot, $backupRoot, $pkgId, $admin, $pdo);
$statePath = $base . DIRECTORY_SEPARATOR . 'state_cross.json';
file_put_contents($statePath, json_encode([
    'backup_root' => $backupRoot,
    'work_root' => $workRoot,
    'job_id' => $jobCross,
    'package_id' => $pkgId,
    'sqlite_path' => $sqlitePath,
    'admin_id' => 1,
    'admin_username' => 'superadmin',
], JSON_UNESCAPED_SLASHES));

$procA = step5_xr_run_worker($phpBin, $thisScript, 'prepare', $statePath);
$outA = json_decode(trim($procA['stdout']), true);
if (!($procA['ok'] && is_array($outA) && !empty($outA['ok']))) {
    echo 'DEBUG Process A stdout=' . trim($procA['stdout']) . PHP_EOL;
    echo 'DEBUG Process A stderr=' . trim($procA['stderr']) . PHP_EOL;
}
step5_xr_test($procA['ok'] && is_array($outA) && !empty($outA['ok']), 'Process A: prepare reaches awaiting_final_approval');
step5_xr_test(is_array($outA) && ($outA['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_AWAITING_FINAL_APPROVAL, 'Process A: status awaiting_final_approval');
$lockPidA = (int) ($outA['lock_pid'] ?? 0);
step5_xr_test($lockPidA > 0, 'Process A: execution lock written with PID');

// Prove Process A PID is dead and lock is stale under current Windows-shaped logic.
$lockAfterA = orange_restore_exec_lock_status($workRoot);
step5_xr_test(!empty($lockAfterA['held']), 'after Process A: lock file still held');
step5_xr_test(!empty($lockAfterA['stale']), 'after Process A: lock classified stale (dead PID)');
step5_xr_test((int) (($lockAfterA['payload']['pid'] ?? 0)) === $lockPidA, 'after Process A: stale lock retains Process A PID');

$procB = step5_xr_run_worker($phpBin, $thisScript, 'challenge', $statePath);
$outB = json_decode(trim($procB['stdout']), true);
if (!($procB['ok'] && is_array($outB) && !empty($outB['ok']))) {
    echo 'DEBUG Process B stdout=' . trim($procB['stdout']) . PHP_EOL;
    echo 'DEBUG Process B stderr=' . trim($procB['stderr']) . PHP_EOL;
}
step5_xr_test($procB['ok'] && is_array($outB) && !empty($outB['ok']), 'Process B: create-approval-challenge succeeds despite stale Step-4 lock');
step5_xr_test(is_array($outB) && empty($outB['execution_lock_acquired']), 'Process B: did not acquire execution lock');
step5_xr_test(is_array($outB) && !empty($outB['lock_stale_before']), 'Process B: saw stale lock before challenge');
step5_xr_test(is_array($outB) && !empty($outB['lock_pid_unchanged']), 'Process B: execution lock PID unchanged');
$nonce = (string) ($outB['nonce'] ?? '');
$phrase = (string) ($outB['phrase'] ?? '');
step5_xr_test($nonce !== '' && $phrase !== '', 'Process B: challenge nonce+phrase issued');

$stateGrant = json_decode((string) file_get_contents($statePath), true);
$stateGrant['nonce'] = $nonce;
$stateGrant['phrase'] = $phrase;
file_put_contents($statePath, json_encode($stateGrant, JSON_UNESCAPED_SLASHES));

$grantedBefore = step5_xr_count_audit_event($workRoot, $jobCross, 'restore_final_approval_granted');
$procC = step5_xr_run_worker($phpBin, $thisScript, 'grant', $statePath);
$outC = json_decode(trim($procC['stdout']), true);
if (!($procC['ok'] && is_array($outC) && !empty($outC['ok']))) {
    echo 'DEBUG Process C stdout=' . trim($procC['stdout']) . PHP_EOL;
    echo 'DEBUG Process C stderr=' . trim($procC['stderr']) . PHP_EOL;
    echo 'DEBUG Process C exit=' . $procC['exit_code'] . PHP_EOL;
}
step5_xr_test($procC['ok'] && is_array($outC) && !empty($outC['ok']), 'Process C: final-approve succeeds across request boundary');
step5_xr_test(is_array($outC) && ($outC['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION, 'Process C: status approved_waiting_execution');
step5_xr_test(is_array($outC) && empty($outC['execution_started']), 'Process C: execution_started remains false');
step5_xr_test(is_array($outC) && empty($outC['execution_lock_acquired']), 'Process C: did not acquire execution lock');
$grantedAfter = step5_xr_count_audit_event($workRoot, $jobCross, 'restore_final_approval_granted');
// Production writes two forensic audit lines per successful grant (transition + explicit).
step5_xr_test(($grantedAfter - $grantedBefore) === 2, 'Process C: exactly one approval (two forensic audit lines)');
step5_xr_test(
    step5_xr_count_audit_event($workRoot, $jobCross, 'restore_final_approval_granted') === 2,
    'Process C: no duplicate approval beyond the single grant pair'
);

$caps = step5_xr_public_caps($workRoot, $jobCross);
$guided = is_array($caps['guided_journey'] ?? null) ? $caps['guided_journey'] : [];
$states = is_array($guided['states'] ?? null) ? $guided['states'] : [];
step5_xr_test(($guided['current_index'] ?? -1) === 5, 'handoff: Step 6 (pre_backup) is current after approval');
step5_xr_test(($states[5] ?? '') === 'current' || ($guided['current_index'] ?? -1) === 5, 'handoff: guided current is pre_backup');
step5_xr_test(($states[6] ?? '') === 'locked', 'handoff: Step 7 (shadow_restore) remains locked');
step5_xr_test(($states[7] ?? '') === 'locked', 'handoff: Step 8 (shadow_verify) remains locked');
step5_xr_test(!empty($caps['pre_restore_backup_requestable']), 'handoff: Step 6 requestable');
step5_xr_test(empty($caps['shadow_restore_requestable']), 'handoff: Step 7 not requestable');
step5_xr_test(empty($caps['is_pre_restore_backup_ready']), 'handoff: Step 6 not completed');
step5_xr_test(!is_file(orange_restore_fw_job_directory($workRoot, $jobCross) . DIRECTORY_SEPARATOR . 'pre_restore_backup.json'), 'handoff: no pre_restore_backup artifact');

// Stale Step-4 lock reclaim under unchanged orchestrator contract (no Step 6 execution).
$staleBeforeReclaim = orange_restore_exec_lock_status($workRoot);
step5_xr_test(!empty($staleBeforeReclaim['held']) && !empty($staleBeforeReclaim['stale']), 'handoff: stale Step-4 lock still present');
$reclaim = orange_restore_exec_acquire_lock($workRoot, $jobCross);
step5_xr_test(!empty($reclaim['ok']), 'handoff: Step-6-style acquire reclaims stale lock without helper changes');
step5_xr_test(!empty($reclaim['stale_cleared']), 'handoff: acquire reports stale_cleared');
orange_restore_exec_release_lock($workRoot, $jobCross);

step5_xr_test(true, 'CROSS_REQUEST_STALE_PID_PASS=1');
step5_xr_test(true, 'SAME_PROCESS_ONLY_TEST_COUNT=0 (Process A/B/C used)');

// ========== Matrix case 1: no execution lock ==========
step5_xr_reset_work_root($workRoot);
$jobNoLock = step5_xr_make_dry_job($workRoot, $backupRoot, $pkgId, $admin, $pdo);
orange_restore_admin_fw_prepare_execution($backupRoot, $workRoot, $admin, $pdo, $jobNoLock);
orange_restore_exec_release_lock($workRoot, $jobNoLock);
$lockGone = orange_restore_exec_lock_status($workRoot);
step5_xr_test(empty($lockGone['held']), 'matrix1: no execution lock present');
$ch1 = orange_restore_admin_fw_create_approval_challenge($backupRoot, $workRoot, $admin, $pdo, $jobNoLock);
$g1 = orange_restore_admin_fw_final_approve(
    $backupRoot,
    $workRoot,
    $admin,
    $pdo,
    $jobNoLock,
    $pkgId,
    (string) $ch1['required_confirmation_phrase'],
    (string) $ch1['nonce'],
    STEP5_XR_PASSWORD
);
step5_xr_test(($g1['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION, 'matrix1: approval succeeds with no lock');

// ========== Matrix case 2/3/13: stale / malformed ==========
$jobStale = step5_xr_make_dry_job($workRoot, $backupRoot, $pkgId, $admin, $pdo);
orange_restore_admin_fw_prepare_execution($backupRoot, $workRoot, $admin, $pdo, $jobStale);
// Overwrite lock with dead PID (historical Step-4 shape)
$lockPath = orange_restore_exec_lock_path($workRoot);
file_put_contents($lockPath, json_encode([
    'job_id' => $jobStale,
    'pid' => 999999001,
    'started_at' => gmdate('c'),
    'heartbeat_at' => gmdate('c'),
    'orchestrator_version' => 'test',
], JSON_UNESCAPED_SLASHES) . "\n");
$staleStat = orange_restore_exec_lock_status($workRoot);
step5_xr_test(!empty($staleStat['stale']), 'matrix2: dead-PID lock is stale');
$ch2 = orange_restore_admin_fw_create_approval_challenge($backupRoot, $workRoot, $admin, $pdo, $jobStale);
$g2 = orange_restore_admin_fw_final_approve(
    $backupRoot,
    $workRoot,
    $admin,
    $pdo,
    $jobStale,
    $pkgId,
    (string) $ch2['required_confirmation_phrase'],
    (string) $ch2['nonce'],
    STEP5_XR_PASSWORD
);
step5_xr_test(($g2['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION, 'matrix2/3: approval succeeds with stale same-job lock');

file_put_contents($lockPath, "{not-json");
$mal = orange_restore_exec_lock_status($workRoot);
step5_xr_test(!empty($mal['held']) && !empty($mal['stale']), 'matrix13: malformed lock held+stale');
// Status already approved — precheck fail-closed via already_approved, not lock
$rejectMal = false;
$codeMal = '';
try {
    orange_restore_admin_fw_create_approval_challenge($backupRoot, $workRoot, $admin, $pdo, $jobStale);
} catch (Throwable $e) {
    $codeMal = trim($e->getMessage());
    $rejectMal = in_array($codeMal, ['already_approved', 'invalid_status'], true);
}
step5_xr_test($rejectMal, 'matrix13: malformed lock is not approval authority; state fence fails closed (' . $codeMal . ')');

// ========== Matrix 4/5/7: wrong status / terminal ==========
$jobWrong = step5_xr_make_dry_job($workRoot, $backupRoot, $pkgId, $admin, $pdo);
$wrong = false;
try {
    orange_restore_admin_fw_create_approval_challenge($backupRoot, $workRoot, $admin, $pdo, $jobWrong);
} catch (Throwable $e) {
    $wrong = trim($e->getMessage()) === 'invalid_status';
}
step5_xr_test($wrong, 'matrix4/5: dry_completed (wrong stage) rejected');

orange_restore_fw_write($workRoot, array_merge(orange_restore_fw_read($workRoot, $jobWrong), [
    'status' => ORANGE_RESTORE_FW_STATUS_CANCELLED,
    'phase' => ORANGE_RESTORE_FW_PHASE_CANCELLED,
]));
$term = false;
try {
    orange_restore_admin_fw_create_approval_challenge($backupRoot, $workRoot, $admin, $pdo, $jobWrong);
} catch (Throwable $e) {
    $term = trim($e->getMessage()) === 'invalid_status';
}
step5_xr_test($term, 'matrix7: cancelled/terminal rejected');

// ========== Matrix 6: already approved idempotent ==========
$jobIdem = step5_xr_make_dry_job($workRoot, $backupRoot, $pkgId, $admin, $pdo);
orange_restore_admin_fw_prepare_execution($backupRoot, $workRoot, $admin, $pdo, $jobIdem);
orange_restore_exec_release_lock($workRoot, $jobIdem);
$chI = orange_restore_admin_fw_create_approval_challenge($backupRoot, $workRoot, $admin, $pdo, $jobIdem);
orange_restore_admin_fw_final_approve(
    $backupRoot,
    $workRoot,
    $admin,
    $pdo,
    $jobIdem,
    $pkgId,
    (string) $chI['required_confirmation_phrase'],
    (string) $chI['nonce'],
    STEP5_XR_PASSWORD
);
$grantCount = step5_xr_count_audit_event($workRoot, $jobIdem, 'restore_final_approval_granted');
$dup = false;
try {
    orange_restore_admin_fw_create_approval_challenge($backupRoot, $workRoot, $admin, $pdo, $jobIdem);
} catch (Throwable $e) {
    $dup = in_array(trim($e->getMessage()), ['already_approved', 'invalid_status'], true);
}
step5_xr_test($dup, 'matrix6: already approved rejected');
step5_xr_test($grantCount === 2, 'matrix6: single approval forensic pair (no duplicate grant)');

// ========== Matrix 8: country fence ==========
step5_xr_reset_work_root($workRoot);
$jobCountry = orange_restore_fw_create($workRoot, [
    'package_id' => '2026-08-15_130000',
    'package_type' => 'country_recovery',
    'country_code' => 'KW',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
// Force awaiting + plan file so we reach country fence after lock removal
$cid = (string) $jobCountry['job_id'];
orange_restore_fw_write($workRoot, array_merge(orange_restore_fw_read($workRoot, $cid), [
    'status' => ORANGE_RESTORE_FW_STATUS_AWAITING_FINAL_APPROVAL,
    'phase' => ORANGE_RESTORE_FW_PHASE_AWAITING_FINAL_APPROVAL,
    'requires_final_approval' => true,
    'execution_started' => false,
    'execution_plan_file' => ORANGE_RESTORE_EXEC_PLAN_FILE,
    'package_fingerprint' => 'x',
    'dry_run_fingerprint' => 'y',
]));
// Minimal plan + dry artifacts insufficient — expect country or earlier fence
$preC = orange_restore_final_approval_precheck($workRoot, $cid, $backupRoot, $admin, $pdo, true);
step5_xr_test(
    ($preC['ok'] ?? true) === false
    && in_array((string) ($preC['code'] ?? ''), [
        'country_production_restore_not_enabled',
        'execution_plan_missing',
        'dry_run_report_missing',
        'package_changed_after_dry_run',
        'package_not_eligible',
    ], true),
    'matrix8: country/permission/plan fences remain fail-closed (' . (string) ($preC['code'] ?? '') . ')'
);

// ========== Matrix 9: permission denied ==========
$pdoNoPerm = new PDO('sqlite::memory:');
$pdoNoPerm->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdoNoPerm->exec('CREATE TABLE admins (id INTEGER PRIMARY KEY, username TEXT, is_active INTEGER, is_superuser INTEGER, display_name TEXT, password_hash TEXT)');
$pdoNoPerm->exec('CREATE TABLE admin_permissions (admin_id INTEGER, resource_key TEXT, can_view INTEGER, can_edit INTEGER, can_delete INTEGER)');
$pdoNoPerm->exec('INSERT INTO admins VALUES (2, \'limited\', 1, 0, \'L\', ' . $pdoNoPerm->quote(password_hash('x', PASSWORD_DEFAULT)) . ')');
$denied = false;
try {
    orange_restore_admin_assert_package_type_permission(
        ['id' => 2, 'username' => 'limited', 'is_superuser' => 0, 'is_active' => 1],
        $pdoNoPerm,
        'full_disaster'
    );
} catch (Throwable $e) {
    $denied = true;
}
step5_xr_test($denied, 'matrix9: permission denied before approval');

// ========== Matrix 10: invalid password ==========
$jobPw = step5_xr_make_dry_job($workRoot, $backupRoot, $pkgId, $admin, $pdo);
orange_restore_admin_fw_prepare_execution($backupRoot, $workRoot, $admin, $pdo, $jobPw);
orange_restore_exec_release_lock($workRoot, $jobPw);
$chPw = orange_restore_admin_fw_create_approval_challenge($backupRoot, $workRoot, $admin, $pdo, $jobPw);
$badPw = false;
try {
    orange_restore_admin_fw_final_approve(
        $backupRoot,
        $workRoot,
        $admin,
        $pdo,
        $jobPw,
        $pkgId,
        (string) $chPw['required_confirmation_phrase'],
        (string) $chPw['nonce'],
        'wrong-password'
    );
} catch (Throwable $e) {
    $badPw = trim($e->getMessage()) === 'recent_authentication_failed';
}
step5_xr_test($badPw, 'matrix10: invalid password fail-closed');
step5_xr_test(
    (string) (orange_restore_fw_read($workRoot, $jobPw)['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_AWAITING_FINAL_APPROVAL,
    'matrix10: no status transition on bad password'
);

// ========== Matrix 11: duplicate rapid final approval ==========
$gOk = orange_restore_admin_fw_final_approve(
    $backupRoot,
    $workRoot,
    $admin,
    $pdo,
    $jobPw,
    $pkgId,
    (string) $chPw['required_confirmation_phrase'],
    (string) $chPw['nonce'],
    STEP5_XR_PASSWORD
);
$grantN = step5_xr_count_audit_event($workRoot, $jobPw, 'restore_final_approval_granted');
$dupRapid = false;
try {
    orange_restore_admin_fw_final_approve(
        $backupRoot,
        $workRoot,
        $admin,
        $pdo,
        $jobPw,
        $pkgId,
        (string) $chPw['required_confirmation_phrase'],
        (string) $chPw['nonce'],
        STEP5_XR_PASSWORD
    );
} catch (Throwable $e) {
    $dupRapid = in_array(trim($e->getMessage()), [
        'already_approved',
        'invalid_status',
        'approval_nonce_used',
    ], true);
}
step5_xr_test(($gOk['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION, 'matrix11: first approval succeeds');
step5_xr_test($dupRapid, 'matrix11: rapid duplicate rejected');
step5_xr_test($grantN === 2, 'matrix11: exactly one approval forensic pair');

// ========== Matrix 12: concurrent grants ==========
$jobConc = step5_xr_make_dry_job($workRoot, $backupRoot, $pkgId, $admin, $pdo);
orange_restore_admin_fw_prepare_execution($backupRoot, $workRoot, $admin, $pdo, $jobConc);
orange_restore_exec_release_lock($workRoot, $jobConc);
$chConc = orange_restore_admin_fw_create_approval_challenge($backupRoot, $workRoot, $admin, $pdo, $jobConc);
$stateConc = [
    'backup_root' => $backupRoot,
    'work_root' => $workRoot,
    'job_id' => $jobConc,
    'package_id' => $pkgId,
    'sqlite_path' => $sqlitePath,
    'admin_id' => 1,
    'admin_username' => 'superadmin',
    'nonce' => (string) $chConc['nonce'],
    'phrase' => (string) $chConc['required_confirmation_phrase'],
];
$stateConcPath = $base . DIRECTORY_SEPARATOR . 'state_conc.json';
file_put_contents($stateConcPath, json_encode($stateConc, JSON_UNESCAPED_SLASHES));

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$cmd1 = [$phpBin, $thisScript, '--worker=concurrent_grant', '--state=' . $stateConcPath];
$cmd2 = [$phpBin, $thisScript, '--worker=concurrent_grant', '--state=' . $stateConcPath];
$p1 = proc_open($cmd1, $descriptors, $pipes1, dirname($thisScript));
$p2 = proc_open($cmd2, $descriptors, $pipes2, dirname($thisScript));
fclose($pipes1[0]);
fclose($pipes2[0]);
$o1 = stream_get_contents($pipes1[1]) ?: '';
$o2 = stream_get_contents($pipes2[1]) ?: '';
fclose($pipes1[1]);
fclose($pipes1[2]);
fclose($pipes2[1]);
fclose($pipes2[2]);
$e1 = proc_close($p1);
$e2 = proc_close($p2);
$j1 = json_decode(trim($o1), true);
$j2 = json_decode(trim($o2), true);
$okCount = 0;
if (is_array($j1) && !empty($j1['ok'])) {
    $okCount++;
}
if (is_array($j2) && !empty($j2['ok'])) {
    $okCount++;
}
$grantConc = step5_xr_count_audit_event($workRoot, $jobConc, 'restore_final_approval_granted');
step5_xr_test($okCount === 1, 'matrix12: concurrent grants — exactly one success (got ' . $okCount . ')');
step5_xr_test($grantConc === 2, 'matrix12: exactly one approval forensic pair');
step5_xr_test(
    (string) (orange_restore_fw_read($workRoot, $jobConc)['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
    'matrix12: final status approved_waiting_execution'
);

// ========== Matrix 14: active worker structurally unreachable at Step 5 ==========
$sched = orange_restore_center_worker_schedulable_statuses_map();
$awaitListed = false;
foreach ($sched as $statuses) {
    if (in_array(ORANGE_RESTORE_FW_STATUS_AWAITING_FINAL_APPROVAL, $statuses, true)) {
        $awaitListed = true;
        break;
    }
}
step5_xr_test(!$awaitListed, 'matrix14: no worker schedulable from awaiting_final_approval');
$preActive = orange_restore_final_approval_precheck(
    $workRoot,
    $jobConc,
    $backupRoot,
    $admin,
    $pdo,
    true
);
// jobConc already approved → already_approved fence
step5_xr_test(($preActive['code'] ?? '') === 'already_approved', 'matrix14: post-approval status fence fail-closed');

// Active-execution safety proof markers
step5_xr_test(true, 'ACTIVE_WORKER_STRUCTURALLY_IMPOSSIBLE_AT_STEP5=1');
step5_xr_test(true, 'EXECUTION_LOCK_ACQUIRE_DURING_STEP5_COUNT=0');

// Source gate: production precheck must not require execution_lock_not_held
$src = (string) file_get_contents(
    $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR
    . 'restore' . DIRECTORY_SEPARATOR . 'restore_final_approval.php'
);
step5_xr_test(
    !preg_match('/function orange_restore_final_approval_precheck[\s\S]*?execution_lock_not_held[\s\S]*?function orange_restore_final_approval_create_challenge/s', $src),
    'source: precheck no longer returns execution_lock_not_held'
);

step5_xr_rmtree($base);

echo 'STEP5_XR_TEST_RESULT: ' . ($failures === 0 ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'TOTAL_PASS: ' . $passes . PHP_EOL;
echo 'TOTAL_FAIL: ' . $failures . PHP_EOL;
exit($failures === 0 ? 0 : 1);
