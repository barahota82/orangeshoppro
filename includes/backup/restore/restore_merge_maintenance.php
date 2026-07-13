<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_paths.php';
require_once __DIR__ . '/../backup_manifest.php';

/**
 * @return array{active:bool,payload:array<string,mixed>,path:string}
 */
function orange_restore_merge_maintenance_status(string $workRoot): array
{
    $path = orange_restore_merge_maintenance_file_path($workRoot);
    if (!is_file($path)) {
        return ['active' => false, 'payload' => [], 'path' => $path];
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return ['active' => true, 'payload' => [], 'path' => $path];
    }

    $decoded = json_decode(trim($raw), true);

    return [
        'active' => true,
        'payload' => is_array($decoded) ? $decoded : [],
        'path' => $path,
    ];
}

/**
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function orange_restore_merge_maintenance_enable(string $workRoot, string $jobId, array $context = []): array
{
    $status = orange_restore_merge_maintenance_status($workRoot);
    if ($status['active']) {
        $existingJob = (string) ($status['payload']['job_id'] ?? '');
        throw new RuntimeException(
            'Restore maintenance mode is already active'
            . ($existingJob !== '' ? ' (job_id=' . $existingJob . ').' : '.')
        );
    }

    $payload = array_merge([
        'job_id' => $jobId,
        'reason' => 'full_merge_foundation',
        'enabled_at' => gmdate('c'),
        'pid' => getmypid(),
        'hostname' => php_uname('n'),
    ], $context);

    $path = orange_restore_merge_maintenance_file_path($workRoot);
    orange_backup_write_json($path, $payload);

    return [
        'ok' => true,
        'active' => true,
        'path' => $path,
        'payload' => $payload,
        'production_writes' => false,
    ];
}

/**
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function orange_restore_merge_maintenance_disable(string $workRoot, string $jobId, array $context = []): array
{
    $status = orange_restore_merge_maintenance_status($workRoot);
    if (!$status['active']) {
        throw new RuntimeException('Restore maintenance mode is not active.');
    }

    $activeJob = (string) ($status['payload']['job_id'] ?? '');
    if ($activeJob !== '' && $activeJob !== $jobId) {
        throw new RuntimeException(
            'Restore maintenance mode is owned by a different job (active=' . $activeJob . ').'
        );
    }

    $path = $status['path'];
    if (!@unlink($path)) {
        throw new RuntimeException('Cannot disable restore maintenance mode (unlink failed).');
    }

    return [
        'ok' => true,
        'active' => false,
        'disabled_at' => gmdate('c'),
        'job_id' => $jobId,
        'context' => $context,
        'production_writes' => false,
    ];
}

/**
 * Verify maintenance flag exists and matches the expected job.
 *
 * @return array<string, mixed>
 */
function orange_restore_merge_maintenance_verify(string $workRoot, string $jobId): array
{
    $status = orange_restore_merge_maintenance_status($workRoot);
    if (!$status['active']) {
        throw new RuntimeException('Restore maintenance mode is not active.');
    }

    $activeJob = (string) ($status['payload']['job_id'] ?? '');
    if ($activeJob !== $jobId) {
        throw new RuntimeException(
            'Restore maintenance job_id mismatch (expected ' . $jobId . ', got ' . $activeJob . ').'
        );
    }

    return [
        'ok' => true,
        'job_id' => $jobId,
        'payload' => $status['payload'],
        'path' => $status['path'],
        'verified_at' => gmdate('c'),
        'production_writes' => false,
    ];
}
