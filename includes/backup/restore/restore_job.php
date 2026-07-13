<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_paths.php';

const ORANGE_RESTORE_JOB_TYPE_FULL = 'full_disaster';
const ORANGE_RESTORE_JOB_TYPE_COUNTRY = 'country_recovery';

const ORANGE_RESTORE_JOB_STATUS_CREATED = 'created';
const ORANGE_RESTORE_JOB_STATUS_VALIDATED = 'package_validated';
const ORANGE_RESTORE_JOB_STATUS_FRESH_BACKUP = 'fresh_backup_recorded';
const ORANGE_RESTORE_JOB_STATUS_STAGING = 'staging_restore';
const ORANGE_RESTORE_JOB_STATUS_STAGING_VALIDATED = 'staging_validated';
const ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL = 'awaiting_owner_approval';
const ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE = 'approved_for_merge';
const ORANGE_RESTORE_JOB_STATUS_MERGE_APPROVED = 'merge_approved';
const ORANGE_RESTORE_JOB_STATUS_MERGED = 'production_merged';
const ORANGE_RESTORE_JOB_STATUS_COMPLETED = 'completed';
const ORANGE_RESTORE_JOB_STATUS_FAILED = 'failed';
const ORANGE_RESTORE_JOB_STATUS_CANCELLED = 'cancelled';

/**
 * @return list<string>
 */
function orange_restore_job_allowed_statuses(): array
{
    return [
        ORANGE_RESTORE_JOB_STATUS_CREATED,
        ORANGE_RESTORE_JOB_STATUS_VALIDATED,
        ORANGE_RESTORE_JOB_STATUS_FRESH_BACKUP,
        ORANGE_RESTORE_JOB_STATUS_STAGING,
        ORANGE_RESTORE_JOB_STATUS_STAGING_VALIDATED,
        ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL,
        ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE,
        ORANGE_RESTORE_JOB_STATUS_MERGE_APPROVED,
        ORANGE_RESTORE_JOB_STATUS_MERGED,
        ORANGE_RESTORE_JOB_STATUS_COMPLETED,
        ORANGE_RESTORE_JOB_STATUS_FAILED,
        ORANGE_RESTORE_JOB_STATUS_CANCELLED,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function orange_restore_job_create(string $workRoot, array $input): array
{
    $jobType = (string) ($input['job_type'] ?? '');
    if (!in_array($jobType, [ORANGE_RESTORE_JOB_TYPE_FULL, ORANGE_RESTORE_JOB_TYPE_COUNTRY], true)) {
        throw new RuntimeException('Invalid restore job_type.');
    }

    $jobId = orange_restore_generate_job_id();
    $jobDir = orange_restore_job_directory($workRoot, $jobId);
    if (is_dir($jobDir)) {
        throw new RuntimeException('Restore job directory already exists: ' . $jobId);
    }
    if (!@mkdir($jobDir, 0775, true) && !is_dir($jobDir)) {
        throw new RuntimeException('Cannot create restore job directory.');
    }

    $now = gmdate('c');
    $job = [
        'job_id' => $jobId,
        'job_type' => $jobType,
        'status' => ORANGE_RESTORE_JOB_STATUS_CREATED,
        'engine_version' => ORANGE_RESTORE_ENGINE_VERSION,
        'created_at' => $now,
        'updated_at' => $now,
        'operator_admin_id' => (int) ($input['operator_admin_id'] ?? 0),
        'operator_username' => (string) ($input['operator_username'] ?? ''),
        'source_package_path' => (string) ($input['source_package_path'] ?? ''),
        'source_package_checksum' => (string) ($input['source_package_checksum'] ?? ''),
        'package_version' => (string) ($input['package_version'] ?? ''),
        'schema_revision' => (int) ($input['schema_revision'] ?? 0),
        'country_id' => (int) ($input['country_id'] ?? 0),
        'country_code' => (string) ($input['country_code'] ?? ''),
        'fresh_backup_path' => '',
        'fresh_backup_checksum' => '',
        'rollback_anchor_job_only' => true,
        'reauth_verified_at' => (string) ($input['reauth_verified_at'] ?? ''),
        'approval_token' => '',
        'approval_token_hash' => '',
        'approval_token_binding' => [],
        'approval_token_issued_at' => '',
        'approval_token_expires_at' => '',
        'approval_token_consumed_at' => '',
        'approval_token_invalidated_at' => '',
        'approval_token_invalidation_reason' => '',
        'approval_phrase_expected' => (string) ($input['approval_phrase_expected'] ?? ''),
        'owner_approval_window_started_at' => '',
        'owner_approval_at' => '',
        'owner_approval_by' => '',
        'owner_approval_admin_id' => 0,
        'rejected_by' => '',
        'rejected_at' => '',
        'rejection_reason' => '',
        'cancelled_by' => '',
        'cancelled_at' => '',
        'cancellation_reason' => '',
        'production_merge_approved' => false,
        'result' => '',
        'stage_failed' => '',
        'error_summary' => '',
        'duration_seconds' => 0,
        'staging_db' => '',
        'staging_uploads_path' => '',
        'staging_dirty' => false,
        'staging_restore_manifest_path' => '',
        'restore_report_path' => '',
    ];

    orange_restore_job_write($workRoot, $job);

    return $job;
}

/**
 * @return array<string, mixed>
 */
function orange_restore_job_read(string $workRoot, string $jobId): array
{
    $path = orange_restore_job_file_path($workRoot, $jobId);
    if (!is_file($path)) {
        throw new RuntimeException('Restore job not found: ' . $jobId);
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Cannot read restore job: ' . $jobId);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid restore job JSON: ' . $jobId);
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $job
 */
function orange_restore_job_write(string $workRoot, array $job): void
{
    $jobId = (string) ($job['job_id'] ?? '');
    if ($jobId === '') {
        throw new RuntimeException('Restore job missing job_id.');
    }
    $job['updated_at'] = gmdate('c');
    $path = orange_restore_job_file_path($workRoot, $jobId);
    $json = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Restore job JSON encode failed.');
    }
    if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write restore job file.');
    }
}

/**
 * @return array<string, list<string>>
 */
function orange_restore_job_full_staging_transition_map(): array
{
    return [
        ORANGE_RESTORE_JOB_STATUS_CREATED => [ORANGE_RESTORE_JOB_STATUS_VALIDATED],
        ORANGE_RESTORE_JOB_STATUS_VALIDATED => [ORANGE_RESTORE_JOB_STATUS_FRESH_BACKUP],
        ORANGE_RESTORE_JOB_STATUS_FRESH_BACKUP => [ORANGE_RESTORE_JOB_STATUS_STAGING],
        ORANGE_RESTORE_JOB_STATUS_STAGING => [ORANGE_RESTORE_JOB_STATUS_STAGING_VALIDATED],
        ORANGE_RESTORE_JOB_STATUS_STAGING_VALIDATED => [ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL],
    ];
}

/**
 * Phase 2C approval gate transitions (no merge/completed).
 *
 * @return array<string, list<string>>
 */
function orange_restore_job_approval_transition_map(): array
{
    return [
        ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL => [
            ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE,
            ORANGE_RESTORE_JOB_STATUS_CANCELLED,
        ],
    ];
}

function orange_restore_job_assert_approval_transition(string $fromStatus, string $toStatus): void
{
    if ($toStatus === ORANGE_RESTORE_JOB_STATUS_FAILED) {
        return;
    }

    $allowed = orange_restore_job_approval_transition_map()[$fromStatus] ?? [];
    if (!in_array($toStatus, $allowed, true)) {
        throw new RuntimeException(
            'Invalid restore approval transition: ' . $fromStatus . ' -> ' . $toStatus
        );
    }
}

function orange_restore_job_has_active_approval_token(array $job): bool
{
    return (string) ($job['approval_token_hash'] ?? '') !== ''
        && (string) ($job['approval_token_consumed_at'] ?? '') === ''
        && (string) ($job['approval_token_invalidated_at'] ?? '') === '';
}

function orange_restore_job_assert_full_staging_transition(string $fromStatus, string $toStatus): void
{
    if ($toStatus === ORANGE_RESTORE_JOB_STATUS_FAILED || $toStatus === ORANGE_RESTORE_JOB_STATUS_CANCELLED) {
        return;
    }

    $allowed = orange_restore_job_full_staging_transition_map()[$fromStatus] ?? [];
    if (!in_array($toStatus, $allowed, true)) {
        throw new RuntimeException(
            'Invalid full-disaster restore job transition: ' . $fromStatus . ' -> ' . $toStatus
        );
    }
}

/**
 * Country restore → staging uses the same lifecycle gates as full disaster staging.
 *
 * @return array<string, list<string>>
 */
function orange_restore_job_country_staging_transition_map(): array
{
    return orange_restore_job_full_staging_transition_map();
}

function orange_restore_job_assert_country_staging_transition(string $fromStatus, string $toStatus): void
{
    if ($toStatus === ORANGE_RESTORE_JOB_STATUS_FAILED || $toStatus === ORANGE_RESTORE_JOB_STATUS_CANCELLED) {
        return;
    }

    $allowed = orange_restore_job_country_staging_transition_map()[$fromStatus] ?? [];
    if (!in_array($toStatus, $allowed, true)) {
        throw new RuntimeException(
            'Invalid country-restore staging job transition: ' . $fromStatus . ' -> ' . $toStatus
        );
    }
}

/**
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function orange_restore_job_transition(string $workRoot, string $jobId, string $newStatus, array $patch = []): array
{
    if (!in_array($newStatus, orange_restore_job_allowed_statuses(), true)) {
        throw new RuntimeException('Invalid restore job status: ' . $newStatus);
    }
    $job = orange_restore_job_read($workRoot, $jobId);
    $currentStatus = (string) ($job['status'] ?? '');
    if (($job['job_type'] ?? '') === ORANGE_RESTORE_JOB_TYPE_FULL) {
        orange_restore_job_assert_full_staging_transition($currentStatus, $newStatus);
    } elseif (($job['job_type'] ?? '') === ORANGE_RESTORE_JOB_TYPE_COUNTRY) {
        orange_restore_job_assert_country_staging_transition($currentStatus, $newStatus);
    }
    $job['status'] = $newStatus;
    foreach ($patch as $key => $value) {
        $job[(string) $key] = $value;
    }
    orange_restore_job_write($workRoot, $job);

    return $job;
}

/**
 * Record the Stage-3 fresh backup anchor for THIS job only (owner rollback policy).
 *
 * @return array<string, mixed>
 */
function orange_restore_job_record_fresh_backup_anchor(
    string $workRoot,
    string $jobId,
    string $freshBackupPath,
    string $freshBackupChecksum
): array {
    if ($freshBackupPath === '' || $freshBackupChecksum === '') {
        throw new RuntimeException('Fresh backup anchor requires path and checksum.');
    }

    return orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_FRESH_BACKUP, [
        'fresh_backup_path' => $freshBackupPath,
        'fresh_backup_checksum' => $freshBackupChecksum,
        'rollback_anchor_job_only' => true,
    ]);
}

/**
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function orange_restore_job_mark_failed(
    string $workRoot,
    string $jobId,
    string $stageFailed,
    string $errorSummary,
    bool $stagingDirty = false,
    array $patch = []
): array {
    return orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_FAILED, array_merge([
        'stage_failed' => $stageFailed,
        'error_summary' => $errorSummary,
        'result' => 'failed',
        'staging_dirty' => $stagingDirty,
    ], $patch));
}

function orange_restore_job_staging_manifest_path(string $workRoot, string $jobId): string
{
    return orange_restore_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_STAGING_MANIFEST_FILE;
}

function orange_restore_job_report_path(string $workRoot, string $jobId): string
{
    return orange_restore_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_REPORT_FILE;
}
