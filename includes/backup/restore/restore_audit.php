<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_paths.php';

/**
 * Filesystem append-only audit log (Phase 2A — no DB table).
 * Permanent DB audit arrives with the first phase that performs restore operations.
 *
 * @param array<string, mixed> $event
 */
function orange_restore_audit_append(string $workRoot, string $jobId, array $event): void
{
    $path = orange_restore_audit_file_path($workRoot, $jobId);
    $jobDir = dirname($path);
    if (!is_dir($jobDir) && !@mkdir($jobDir, 0775, true) && !is_dir($jobDir)) {
        throw new RuntimeException('Cannot create restore audit directory.');
    }

    $record = array_merge([
        'recorded_at' => gmdate('c'),
        'job_id' => $jobId,
        'engine_version' => ORANGE_RESTORE_ENGINE_VERSION,
    ], $event);

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        throw new RuntimeException('Restore audit JSON encode failed.');
    }

    if (file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Cannot append restore audit record.');
    }
}

/**
 * @return list<array<string, mixed>>
 */
function orange_restore_audit_read_all(string $workRoot, string $jobId): array
{
    $path = orange_restore_audit_file_path($workRoot, $jobId);
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('Cannot read restore audit log.');
    }
    $out = [];
    foreach ($lines as $line) {
        $decoded = json_decode((string) $line, true);
        if (is_array($decoded)) {
            $out[] = $decoded;
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $job
 * @param array<string, mixed> $extra
 */
function orange_restore_audit_from_job(array $job, string $stage, string $result, array $extra = []): array
{
    return array_merge([
        'stage' => $stage,
        'result' => $result,
        'operator_admin_id' => (int) ($job['operator_admin_id'] ?? 0),
        'operator_username' => (string) ($job['operator_username'] ?? ''),
        'source_package_path' => (string) ($job['source_package_path'] ?? ''),
        'source_package_checksum' => (string) ($job['source_package_checksum'] ?? ''),
        'package_version' => (string) ($job['package_version'] ?? ''),
        'schema_revision' => (int) ($job['schema_revision'] ?? 0),
        'scope' => (string) ($job['job_type'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'fresh_backup_path' => (string) ($job['fresh_backup_path'] ?? ''),
        'fresh_backup_checksum' => (string) ($job['fresh_backup_checksum'] ?? ''),
        'production_merge_approved' => (bool) ($job['production_merge_approved'] ?? false),
        'job_status' => (string) ($job['status'] ?? ''),
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 */
function orange_restore_audit_approval_event(array $job, string $event, string $result, array $extra = []): array
{
    return orange_restore_audit_from_job($job, 'approval_gate', $result, array_merge([
        'approval_event' => $event,
    ], $extra));
}

/**
 * @param array<string, mixed> $extra
 */
function orange_restore_audit_merge_event(array $job, string $event, string $result, array $extra = []): array
{
    return orange_restore_audit_from_job($job, 'merge_foundation', $result, array_merge([
        'merge_event' => $event,
    ], $extra));
}

/**
 * @param array<string, mixed> $extra
 */
function orange_restore_audit_db_cutover_event(array $job, string $event, string $result, array $extra = []): array
{
    return orange_restore_audit_from_job($job, 'merge_db_cutover', $result, array_merge([
        'db_cutover_event' => $event,
    ], $extra));
}

/**
 * @param array<string, mixed> $extra
 */
function orange_restore_audit_uploads_cutover_event(array $job, string $event, string $result, array $extra = []): array
{
    return orange_restore_audit_from_job($job, 'merge_uploads_cutover', $result, array_merge([
        'uploads_cutover_event' => $event,
    ], $extra));
}

/**
 * @param array<string, mixed> $extra
 */
function orange_restore_audit_post_validation_event(array $job, string $event, string $result, array $extra = []): array
{
    return orange_restore_audit_from_job($job, 'merge_post_validation', $result, array_merge([
        'post_validation_event' => $event,
    ], $extra));
}

/**
 * @param array<string, mixed> $extra
 */
function orange_restore_audit_rollback_event(array $job, string $event, string $result, array $extra = []): array
{
    return orange_restore_audit_from_job($job, 'merge_rollback', $result, array_merge([
        'rollback_event' => $event,
    ], $extra));
}

/**
 * @param array<string, mixed> $extra
 */
function orange_restore_audit_e2e_event(array $job, string $event, string $result, array $extra = []): array
{
    return orange_restore_audit_from_job($job, 'e2e_orchestrator', $result, array_merge([
        'e2e_event' => $event,
    ], $extra));
}

function orange_restore_audit_e2e_has_event(string $workRoot, string $jobId, string $eventName): bool
{
    foreach (orange_restore_audit_read_all($workRoot, $jobId) as $event) {
        if ((string) ($event['e2e_event'] ?? '') === $eventName) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $extra
 */
function orange_restore_audit_e2e_append_once(
    string $workRoot,
    string $jobId,
    array $job,
    string $event,
    string $result,
    array $extra = []
): void {
    if (orange_restore_audit_e2e_has_event($workRoot, $jobId, $event)) {
        return;
    }

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_e2e_event($job, $event, $result, $extra));
}

function orange_restore_audit_post_validation_has_event(string $workRoot, string $jobId, string $eventName): bool
{
    foreach (orange_restore_audit_read_all($workRoot, $jobId) as $event) {
        if ((string) ($event['post_validation_event'] ?? '') === $eventName) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $extra
 */
function orange_restore_audit_post_validation_append_once(
    string $workRoot,
    string $jobId,
    array $job,
    string $event,
    string $result,
    array $extra = []
): void {
    if (orange_restore_audit_post_validation_has_event($workRoot, $jobId, $event)) {
        return;
    }

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_post_validation_event($job, $event, $result, $extra));
}
