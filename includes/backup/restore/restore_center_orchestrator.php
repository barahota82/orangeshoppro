<?php

declare(strict_types=1);

/**
 * Restore Center orchestration layer (Owner 2026-07-24).
 *
 * Schedules already-approved restore CLI workers from Admin HTTP.
 * HTTP must return immediately — workers run as detached OS processes and
 * must not depend on browser / HTTP connection lifetime.
 *
 * Production rules (Owner rejection → v3):
 * - Atomic one job + one stage = one running worker (orchestration lock; not CLI).
 * - Never report scheduled unless spawn + PID verification succeeded.
 * - Full stdin/stdout/stderr detachment from the HTTP process.
 *
 * Does not implement restore logic, alter gates, or rewrite workers.
 */

require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_production_cli_policy.php';

const ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION = '3B.4-rc-orchestrator-v3-production';
const ORANGE_RESTORE_CENTER_WORKER_LOCK_STALE_SECONDS = 21600;

/**
 * Allowlisted Restore Center worker keys → approved repo-relative CLI scripts.
 *
 * @return array<string, string>
 */
function orange_restore_center_worker_catalog(): array
{
    return [
        'pre_restore_backup' => 'scripts/backup/restore_prepare_backup.php',
        'shadow_db' => 'scripts/backup/restore_shadow_db.php',
        'shadow_verify' => 'scripts/backup/restore_shadow_verify.php',
        'shadow_files' => 'scripts/backup/restore_shadow_files.php',
        'shadow_smoke' => 'scripts/backup/restore_shadow_smoke.php',
        'production_import' => 'scripts/backup/restore_import_production.php',
        'uploads_cutover' => 'scripts/backup/restore_uploads_cutover.php',
        'rollback' => 'scripts/backup/restore_rollback.php',
        'finalize' => 'scripts/backup/restore_finalize.php',
    ];
}

/**
 * @return list<string>
 */
function orange_restore_center_approved_script_paths(): array
{
    return array_values(array_unique(array_merge(
        orange_restore_approved_production_mutation_cli_workers(),
        orange_restore_approved_non_mutation_restore_clis()
    )));
}

function orange_restore_center_assert_worker_key(string $workerKey): string
{
    $catalog = orange_restore_center_worker_catalog();
    if (!isset($catalog[$workerKey])) {
        throw new RuntimeException('restore_center_unknown_worker');
    }
    $rel = $catalog[$workerKey];
    $approved = orange_restore_center_approved_script_paths();
    if (!in_array($rel, $approved, true)) {
        throw new RuntimeException('restore_center_worker_not_allowlisted');
    }

    return $rel;
}

/**
 * Resolve absolute path to an allowlisted restore CLI script under project root.
 */
function orange_restore_center_resolve_worker_script(string $projectRoot, string $relativeScript): string
{
    $projectRoot = realpath($projectRoot) ?: $projectRoot;
    $script = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeScript);
    if (!is_file($script)) {
        throw new RuntimeException('restore_center_worker_script_missing');
    }
    $realScript = realpath($script);
    if ($realScript === false || !is_file($realScript)) {
        throw new RuntimeException('restore_center_worker_script_missing');
    }
    $normalized = str_replace('\\', '/', $realScript);
    $suffix = str_replace('\\', '/', $relativeScript);
    if (!str_ends_with($normalized, $suffix)) {
        throw new RuntimeException('restore_center_worker_script_path_rejected');
    }

    return $realScript;
}

function orange_restore_center_safe_worker_token(string $workerKey): string
{
    return preg_replace('/[^a-z0-9_]+/i', '_', $workerKey) ?: 'worker';
}

function orange_restore_center_worker_log_path(string $workRoot, string $jobId, string $workerKey): string
{
    $dir = orange_restore_fw_job_directory($workRoot, $jobId);

    return $dir . DIRECTORY_SEPARATOR . 'orchestrator_' . orange_restore_center_safe_worker_token($workerKey) . '.log';
}

function orange_restore_center_worker_run_claim_path(string $workRoot, string $jobId, string $workerKey): string
{
    $dir = orange_restore_fw_job_directory($workRoot, $jobId);

    return $dir . DIRECTORY_SEPARATOR . 'orchestrator_' . orange_restore_center_safe_worker_token($workerKey) . '.run.json';
}

function orange_restore_center_worker_mutex_path(string $workRoot, string $jobId, string $workerKey): string
{
    $dir = orange_restore_fw_job_directory($workRoot, $jobId);

    return $dir . DIRECTORY_SEPARATOR . 'orchestrator_' . orange_restore_center_safe_worker_token($workerKey) . '.mutex';
}

function orange_restore_center_worker_launch_cmd_path(string $workRoot, string $jobId, string $workerKey): string
{
    $dir = orange_restore_fw_job_directory($workRoot, $jobId);

    return $dir . DIRECTORY_SEPARATOR . 'orchestrator_' . orange_restore_center_safe_worker_token($workerKey) . '_launch.cmd';
}

function orange_restore_center_process_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('orange_restore_lock_process_alive')) {
        return orange_restore_lock_process_alive($pid);
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }

    return false;
}

function orange_restore_center_can_probe_process_liveness(): bool
{
    if (function_exists('posix_kill')) {
        return true;
    }
    if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('shell_exec', $disabled, true);
    }

    return false;
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_center_read_run_claim(string $claimPath): ?array
{
    if (!is_file($claimPath)) {
        return null;
    }
    $raw = (string) @file_get_contents($claimPath);
    if ($raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $payload
 */
function orange_restore_center_write_run_claim(string $claimPath, array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('restore_center_run_claim_encode_failed');
    }
    if (@file_put_contents($claimPath, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('restore_center_run_claim_write_failed');
    }
}

/**
 * True when an orchestration claim still represents a live worker for this job+stage.
 *
 * @param array<string, mixed>|null $claim
 */
function orange_restore_center_run_claim_is_active(?array $claim): bool
{
    if ($claim === null) {
        return false;
    }
    $pid = (int) ($claim['pid'] ?? 0);
    $startedAt = strtotime((string) ($claim['started_at'] ?? ''));
    $age = $startedAt === false ? 0 : (time() - $startedAt);

    if ($pid > 0 && orange_restore_center_process_alive($pid)) {
        return true;
    }

    // If PID liveness cannot be probed, keep the claim until stale timeout (fail-closed).
    if ($pid > 0 && !orange_restore_center_can_probe_process_liveness()) {
        return $age < ORANGE_RESTORE_CENTER_WORKER_LOCK_STALE_SECONDS;
    }

    // Probeable PID that is no longer alive → worker finished; allow reschedule.
    if ($pid > 0) {
        return false;
    }

    return $age < 30;
}

/**
 * @return array{handle:resource,path:string}
 */
function orange_restore_center_acquire_schedule_mutex(string $workRoot, string $jobId, string $workerKey): array
{
    $path = orange_restore_center_worker_mutex_path($workRoot, $jobId, $workerKey);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('restore_center_spawn_log_dir_failed');
    }
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('restore_center_mutex_open_failed');
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new RuntimeException('restore_center_worker_already_running');
    }

    return ['handle' => $handle, 'path' => $path];
}

/**
 * @param resource $handle
 */
function orange_restore_center_release_schedule_mutex($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Build Windows launch .cmd that fully redirects stdin/stdout/stderr, then start it detached.
 * The tracked PID is the cmd.exe host that stays alive until the PHP worker exits.
 *
 * @param list<string> $command
 * @return array{pid:int,launch_cmd:string}
 */
function orange_restore_center_spawn_detached_windows(
    array $command,
    string $logPath,
    string $launchCmdPath
): array {
    $phpBinary = (string) $command[0];
    $script = (string) $command[1];
    $jobArg = (string) $command[2];

    $cmdBody = '@echo off' . "\r\n"
        . 'setlocal' . "\r\n"
        . escapeshellarg($phpBinary) . ' '
        . escapeshellarg($script) . ' '
        . escapeshellarg($jobArg)
        . ' <NUL >>' . escapeshellarg($logPath) . ' 2>&1' . "\r\n"
        . 'exit /B %ERRORLEVEL%' . "\r\n";
    if (@file_put_contents($launchCmdPath, $cmdBody) === false) {
        throw new RuntimeException('restore_center_spawn_launch_cmd_failed');
    }

    $ps = '$ErrorActionPreference = \'Stop\'; '
        . '$p = Start-Process -FilePath ' . orange_restore_center_powershell_quote($launchCmdPath)
        . ' -WindowStyle Hidden -PassThru; '
        . 'if ($null -eq $p -or [int]$p.Id -le 0) { exit 1 }; '
        . 'Write-Output ([int]$p.Id); '
        . 'exit 0';

    $output = [];
    $exitCode = 1;
    $psBinary = orange_restore_center_resolve_powershell_binary();
    exec(
        escapeshellarg($psBinary) . ' -NoProfile -NonInteractive -Command ' . escapeshellarg($ps),
        $output,
        $exitCode
    );
    $pid = isset($output[0]) ? (int) trim((string) $output[0]) : 0;
    if ($exitCode !== 0 || $pid <= 0) {
        throw new RuntimeException('restore_center_spawn_failed');
    }
    usleep(100000);
    if (!orange_restore_center_process_alive($pid)) {
        throw new RuntimeException('restore_center_spawn_failed');
    }

    return ['pid' => $pid, 'launch_cmd' => $launchCmdPath];
}

function orange_restore_center_powershell_quote(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function orange_restore_center_resolve_powershell_binary(): string
{
    foreach (orange_backup_powershell_known_paths() as $candidate) {
        $path = orange_backup_normalize_tool_path((string) $candidate);
        if ($path !== '' && is_file($path)) {
            return $path;
        }
    }

    return 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
}

/**
 * @param list<string> $command
 * @return array{pid:int}
 */
function orange_restore_center_spawn_detached_unix(array $command, string $logPath): array
{
    $phpBinary = (string) $command[0];
    $script = (string) $command[1];
    $jobArg = (string) $command[2];

    $cmdline = escapeshellarg($phpBinary) . ' '
        . escapeshellarg($script) . ' '
        . escapeshellarg($jobArg)
        . ' </dev/null >>' . escapeshellarg($logPath) . ' 2>&1 & echo $!';

    $output = [];
    $exitCode = 1;
    exec('nohup ' . $cmdline, $output, $exitCode);
    $pid = isset($output[0]) ? (int) trim((string) $output[0]) : 0;
    if ($exitCode !== 0 || $pid <= 0) {
        throw new RuntimeException('restore_center_spawn_failed');
    }
    usleep(100000);
    if (!orange_restore_center_process_alive($pid)) {
        throw new RuntimeException('restore_center_spawn_failed');
    }

    return ['pid' => $pid];
}

/**
 * Launch CLI worker detached from the PHP HTTP request (Windows + Unix).
 * Does not wait for worker completion. Returns verified OS pid.
 *
 * @param list<string> $command Absolute php binary + script + --job=…
 * @return array{pid:int,launch_cmd?:string}
 */
function orange_restore_center_spawn_detached(
    array $command,
    string $logPath,
    string $workRoot = '',
    string $jobId = '',
    string $workerKey = ''
): array {
    if (count($command) < 3) {
        throw new RuntimeException('restore_center_spawn_invalid_command');
    }

    $logDir = dirname($logPath);
    if (!is_dir($logDir) && !@mkdir($logDir, 0775, true) && !is_dir($logDir)) {
        throw new RuntimeException('restore_center_spawn_log_dir_failed');
    }

    $jobArg = (string) $command[2];
    if (!str_starts_with($jobArg, '--job=')) {
        throw new RuntimeException('restore_center_spawn_job_arg_rejected');
    }

    if (PHP_OS_FAMILY === 'Windows') {
        if ($workRoot === '' || $jobId === '' || $workerKey === '') {
            throw new RuntimeException('restore_center_spawn_invalid_command');
        }
        $launchCmd = orange_restore_center_worker_launch_cmd_path($workRoot, $jobId, $workerKey);

        return orange_restore_center_spawn_detached_windows($command, $logPath, $launchCmd);
    }

    return orange_restore_center_spawn_detached_unix($command, $logPath);
}

/**
 * Schedule an approved restore CLI worker (--job= only). Returns immediately after verified launch.
 *
 * @return array{
 *   ok:bool,
 *   detached:bool,
 *   scheduled:bool,
 *   worker:string,
 *   script:string,
 *   log_path:string,
 *   pid:int,
 *   message:string
 * }
 */
function orange_restore_center_run_worker(
    string $projectRoot,
    string $workRoot,
    string $jobId,
    string $workerKey,
    string $operatorUsername = ''
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    if ($jobId === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $jobId)) {
        throw new RuntimeException('invalid_job_id');
    }
    if (!function_exists('orange_restore_admin_assert_fw_job_allowlisted')) {
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'restore_admin.php';
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);

    $relative = orange_restore_center_assert_worker_key($workerKey);
    $script = orange_restore_center_resolve_worker_script($projectRoot, $relative);
    $phpBinary = orange_backup_admin_resolve_cli_php_binary($projectRoot);
    $logPath = orange_restore_center_worker_log_path($workRoot, $jobId, $workerKey);
    $claimPath = orange_restore_center_worker_run_claim_path($workRoot, $jobId, $workerKey);

    $mutex = orange_restore_center_acquire_schedule_mutex($workRoot, $jobId, $workerKey);
    $mutexHandle = $mutex['handle'];
    $pid = 0;

    try {
        $existing = orange_restore_center_read_run_claim($claimPath);
        if (orange_restore_center_run_claim_is_active($existing)) {
            throw new RuntimeException('restore_center_worker_already_running');
        }
        if (is_array($existing)) {
            @unlink($claimPath);
        }

        $spawned = orange_restore_center_spawn_detached(
            [$phpBinary, $script, '--job=' . $jobId],
            $logPath,
            $workRoot,
            $jobId,
            $workerKey
        );
        $pid = (int) ($spawned['pid'] ?? 0);
        if ($pid <= 0 || !orange_restore_center_process_alive($pid)) {
            throw new RuntimeException('restore_center_spawn_failed');
        }

        $claim = [
            'job_id' => $jobId,
            'worker' => $workerKey,
            'script' => $relative,
            'pid' => $pid,
            'started_at' => gmdate('c'),
            'operator_username' => $operatorUsername,
            'log_path' => $logPath,
            'orchestrator_version' => ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION,
            'detached' => true,
        ];
        if (!empty($spawned['launch_cmd'])) {
            $claim['launch_cmd'] = (string) $spawned['launch_cmd'];
        }
        orange_restore_center_write_run_claim($claimPath, $claim);

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_center_worker_scheduled',
            'result' => 'ok',
            'worker' => $workerKey,
            'script' => $relative,
            'detached' => true,
            'pid' => $pid,
            'operator_username' => $operatorUsername,
            'orchestrator_version' => ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION,
        ]);
    } catch (Throwable $e) {
        $code = trim($e->getMessage());
        if ($code !== 'restore_center_worker_already_running') {
            orange_restore_fw_audit_append($workRoot, $jobId, [
                'event' => 'restore_center_worker_schedule_failed',
                'result' => 'fail',
                'worker' => $workerKey,
                'script' => $relative,
                'detached' => true,
                'code' => $code !== '' ? $code : 'restore_center_spawn_failed',
                'operator_username' => $operatorUsername,
                'orchestrator_version' => ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION,
            ]);
        }
        throw $e;
    } finally {
        orange_restore_center_release_schedule_mutex($mutexHandle);
    }

    return [
        'ok' => true,
        'detached' => true,
        'scheduled' => true,
        'worker' => $workerKey,
        'script' => $relative,
        'log_path' => $logPath,
        'pid' => $pid,
        'message' => 'Worker scheduled on server. Continues independently of the browser.',
    ];
}
