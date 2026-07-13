<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_paths.php';

/**
 * @return array{ok:bool,path:string,pid:int,message:string}
 */
function orange_restore_acquire_lock(string $workRoot, string $jobId): array
{
    $path = orange_restore_global_lock_path($workRoot);
    $locksDir = dirname($path);
    if (!is_dir($locksDir) && !@mkdir($locksDir, 0775, true) && !is_dir($locksDir)) {
        return ['ok' => false, 'path' => $path, 'pid' => 0, 'message' => 'Cannot create restore work directory.'];
    }

    $payload = json_encode([
        'pid' => getmypid(),
        'job_id' => $jobId,
        'started_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return ['ok' => false, 'path' => $path, 'pid' => 0, 'message' => 'Lock payload encode failed.'];
    }

    $handle = @fopen($path, 'xb');
    if ($handle === false) {
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        return ['ok' => false, 'path' => $path, 'pid' => 0, 'message' => 'Restore lock already held: ' . $existing];
    }
    fwrite($handle, $payload . "\n");
    fclose($handle);

    return ['ok' => true, 'path' => $path, 'pid' => (int) getmypid(), 'message' => 'Restore lock acquired.'];
}

function orange_restore_release_lock(string $workRoot): void
{
    $path = orange_restore_global_lock_path($workRoot);
    if (is_file($path)) {
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
