<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_paths.php';
require_once __DIR__ . '/../backup_manifest.php';

/**
 * Validate a decoded maintenance payload (shared by status, verify, disable).
 *
 * @param array<string, mixed> $payload
 * @return list<string>
 */
function orange_restore_merge_maintenance_payload_errors(array $payload): array
{
    $errors = [];

    $jobId = (string) ($payload['job_id'] ?? '');
    if ($jobId === '') {
        $errors[] = 'job_id is missing or empty.';
    }

    $reason = trim((string) ($payload['reason'] ?? ''));
    if ($reason === '') {
        $errors[] = 'reason is missing or empty.';
    }

    $enabledAt = trim((string) ($payload['enabled_at'] ?? ''));
    if ($enabledAt === '' || strtotime($enabledAt) === false) {
        $errors[] = 'enabled_at is missing or invalid.';
    }

    if (array_key_exists('pid', $payload)) {
        $pid = $payload['pid'];
        if (!is_int($pid) && !(is_string($pid) && ctype_digit($pid))) {
            $errors[] = 'pid must be a positive integer when present.';
        } elseif ((int) $pid <= 0) {
            $errors[] = 'pid must be a positive integer when present.';
        }
    }

    if (array_key_exists('hostname', $payload)) {
        if (trim((string) $payload['hostname']) === '') {
            $errors[] = 'hostname must be non-empty when present.';
        }
    }

    return $errors;
}

/**
 * Read maintenance file state without mutating it.
 *
 * @return array{
 *   active:bool,
 *   corrupt:bool,
 *   payload:array<string,mixed>,
 *   path:string,
 *   errors:list<string>
 * }
 */
function orange_restore_merge_maintenance_read_state(string $workRoot): array
{
    $path = orange_restore_merge_maintenance_file_path($workRoot);
    $inactive = [
        'active' => false,
        'corrupt' => false,
        'payload' => [],
        'path' => $path,
        'errors' => [],
    ];

    if (!is_file($path)) {
        return $inactive;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [
            'active' => true,
            'corrupt' => true,
            'payload' => [],
            'path' => $path,
            'errors' => ['Maintenance file is empty or unreadable.'],
        ];
    }

    $decoded = json_decode(trim($raw), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return [
            'active' => true,
            'corrupt' => true,
            'payload' => [],
            'path' => $path,
            'errors' => ['Maintenance file contains invalid JSON.'],
        ];
    }

    $errors = orange_restore_merge_maintenance_payload_errors($decoded);

    return [
        'active' => true,
        'corrupt' => $errors !== [],
        'payload' => $decoded,
        'path' => $path,
        'errors' => $errors,
    ];
}

/**
 * Fail closed unless maintenance is active, structurally valid, and owned by job_id.
 *
 * @return array{
 *   active:bool,
 *   corrupt:bool,
 *   payload:array<string,mixed>,
 *   path:string,
 *   errors:list<string>
 * }
 */
function orange_restore_merge_maintenance_assert_valid_owned(
    string $workRoot,
    string $jobId,
    string $operation
): array {
    $state = orange_restore_merge_maintenance_read_state($workRoot);
    if (!$state['active']) {
        throw new RuntimeException('Restore maintenance mode is not active.');
    }

    if ($state['corrupt']) {
        throw new RuntimeException(
            'Restore maintenance file is corrupt or incomplete ('
            . $operation
            . ' aborted): '
            . implode('; ', $state['errors'])
        );
    }

    $activeJob = (string) ($state['payload']['job_id'] ?? '');
    if ($activeJob !== $jobId) {
        throw new RuntimeException(
            'Restore maintenance mode is owned by a different job (active=' . $activeJob . ').'
        );
    }

    return $state;
}

/**
 * @return array{
 *   active:bool,
 *   corrupt:bool,
 *   payload:array<string,mixed>,
 *   path:string,
 *   errors:list<string>
 * }
 */
function orange_restore_merge_maintenance_status(string $workRoot): array
{
    return orange_restore_merge_maintenance_read_state($workRoot);
}

/**
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function orange_restore_merge_maintenance_enable(string $workRoot, string $jobId, array $context = []): array
{
    if ($jobId === '') {
        throw new RuntimeException('Maintenance enable requires a non-empty job_id.');
    }

    $state = orange_restore_merge_maintenance_read_state($workRoot);
    if ($state['active']) {
        if ($state['corrupt']) {
            throw new RuntimeException(
                'Restore maintenance mode is active but corrupt; manual operator intervention required.'
            );
        }

        $existingJob = (string) ($state['payload']['job_id'] ?? '');
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

    $payloadErrors = orange_restore_merge_maintenance_payload_errors($payload);
    if ($payloadErrors !== []) {
        throw new RuntimeException(
            'Maintenance enable payload is invalid: ' . implode('; ', $payloadErrors)
        );
    }

    $path = orange_restore_merge_maintenance_file_path($workRoot);
    orange_backup_write_json($path, $payload);

    return [
        'ok' => true,
        'active' => true,
        'corrupt' => false,
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
    if ($jobId === '') {
        throw new RuntimeException('Maintenance disable requires a non-empty job_id.');
    }

    $state = orange_restore_merge_maintenance_assert_valid_owned($workRoot, $jobId, 'disable');

    if (!is_file($state['path'])) {
        throw new RuntimeException('Restore maintenance mode is not active.');
    }

    if (!@unlink($state['path'])) {
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
 * Verify maintenance flag exists, is structurally valid, and matches the expected job.
 *
 * @return array<string, mixed>
 */
function orange_restore_merge_maintenance_verify(string $workRoot, string $jobId): array
{
    if ($jobId === '') {
        throw new RuntimeException('Maintenance verify requires a non-empty job_id.');
    }

    $state = orange_restore_merge_maintenance_assert_valid_owned($workRoot, $jobId, 'verify');

    return [
        'ok' => true,
        'job_id' => $jobId,
        'payload' => $state['payload'],
        'path' => $state['path'],
        'verified_at' => gmdate('c'),
        'production_writes' => false,
    ];
}
