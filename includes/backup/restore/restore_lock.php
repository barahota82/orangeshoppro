<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_paths.php';

const ORANGE_RESTORE_LOCK_STALE_SECONDS = 21600;

/**
 * @return array{ok:bool,path:string,pid:int,message:string,stale_cleared:bool}
 */
function orange_restore_acquire_lock(string $workRoot, string $jobId): array
{
    $path = orange_restore_global_lock_path($workRoot);
    $locksDir = dirname($path);
    if (!is_dir($locksDir) && !@mkdir($locksDir, 0775, true) && !is_dir($locksDir)) {
        return [
            'ok' => false,
            'path' => $path,
            'pid' => 0,
            'message' => 'Cannot create restore work directory.',
            'stale_cleared' => false,
        ];
    }

    $attempt = orange_restore_acquire_lock_once($workRoot, $jobId, $path);
    if ($attempt['ok']) {
        return $attempt;
    }

    $status = orange_restore_lock_status($workRoot);
    if (!$status['held']) {
        return orange_restore_acquire_lock_once($workRoot, $jobId, $path);
    }

    if (!orange_restore_lock_is_stale($status['payload'])) {
        $existing = is_file($path) ? trim((string) file_get_contents($path)) : '';

        return [
            'ok' => false,
            'path' => $path,
            'pid' => (int) ($status['payload']['pid'] ?? 0),
            'message' => 'Restore lock already held by active job: ' . $existing,
            'stale_cleared' => false,
        ];
    }

    if (!@unlink($path)) {
        return [
            'ok' => false,
            'path' => $path,
            'pid' => (int) ($status['payload']['pid'] ?? 0),
            'message' => 'Restore lock is stale but could not be cleared.',
            'stale_cleared' => false,
        ];
    }

    $retry = orange_restore_acquire_lock_once($workRoot, $jobId, $path);
    $retry['stale_cleared'] = true;
    if ($retry['ok']) {
        $retry['message'] = 'Restore lock acquired after clearing stale lock.';
    }

    return $retry;
}

/**
 * @return array{ok:bool,path:string,pid:int,message:string,stale_cleared:bool}
 */
function orange_restore_acquire_lock_once(string $workRoot, string $jobId, ?string $path = null): array
{
    $path ??= orange_restore_global_lock_path($workRoot);

    $payload = json_encode([
        'pid' => getmypid(),
        'hostname' => php_uname('n'),
        'job_id' => $jobId,
        'started_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return [
            'ok' => false,
            'path' => $path,
            'pid' => 0,
            'message' => 'Lock payload encode failed.',
            'stale_cleared' => false,
        ];
    }

    $handle = @fopen($path, 'xb');
    if ($handle === false) {
        return [
            'ok' => false,
            'path' => $path,
            'pid' => 0,
            'message' => 'Restore lock already held.',
            'stale_cleared' => false,
        ];
    }
    fwrite($handle, $payload . "\n");
    fclose($handle);

    return [
        'ok' => true,
        'path' => $path,
        'pid' => (int) getmypid(),
        'message' => 'Restore lock acquired.',
        'stale_cleared' => false,
    ];
}

/**
 * @param array<string, mixed>|null $payload
 */
function orange_restore_lock_is_stale(?array $payload): bool
{
    if ($payload === null) {
        return true;
    }

    $startedAt = strtotime((string) ($payload['started_at'] ?? ''));
    if ($startedAt !== false && (time() - $startedAt) > ORANGE_RESTORE_LOCK_STALE_SECONDS) {
        return true;
    }

    $pid = (int) ($payload['pid'] ?? 0);
    if ($pid <= 0) {
        return true;
    }

    return !orange_restore_lock_process_alive($pid);
}

function orange_restore_lock_process_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }

    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }

    if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!in_array('shell_exec', $disabled, true)) {
            $output = shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');

            return is_string($output) && preg_match('/\b' . preg_quote((string) $pid, '/') . '\b/', $output) === 1;
        }
    }

    return false;
}

function orange_restore_release_lock(string $workRoot): void
{
    $path = orange_restore_global_lock_path($workRoot);
    if (!is_file($path)) {
        return;
    }

    $status = orange_restore_lock_status($workRoot);
    $payload = $status['payload'];
    if (is_array($payload) && (int) ($payload['pid'] ?? 0) === getmypid()) {
        @unlink($path);
    }
}

/**
 * @return array{held:bool,payload:?array<string,mixed>,path:string}
 */
function orange_restore_lock_status(string $workRoot): array
{
    $path = orange_restore_global_lock_path($workRoot);
    if (!is_file($path)) {
        return ['held' => false, 'payload' => null, 'path' => $path];
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return ['held' => true, 'payload' => null, 'path' => $path];
    }
    $decoded = json_decode(trim($raw), true);

    return [
        'held' => true,
        'payload' => is_array($decoded) ? $decoded : null,
        'path' => $path,
    ];
}
