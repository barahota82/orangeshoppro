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
const ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED = 'merge_precheck_passed';
const ORANGE_RESTORE_JOB_STATUS_MERGE_STARTED = 'merge_started';
const ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE = 'database_cutover_complete';
const ORANGE_RESTORE_JOB_STATUS_UPLOADS_SNAPSHOT_COMPLETE = 'uploads_snapshot_complete';
const ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_PENDING = 'uploads_first_rename_pending';
const ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE = 'uploads_first_rename_complete';
const ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING = 'uploads_second_rename_pending';
const ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE = 'uploads_cutover_complete';
const ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE = 'failed_merge';
const ORANGE_RESTORE_JOB_STATUS_MERGE_APPROVED = 'merge_approved';
const ORANGE_RESTORE_JOB_STATUS_MERGED = 'production_merged';
const ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED = 'post_validation_passed';
const ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE = 'failed_post_merge';
const ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS = 'rollback_in_progress';
const ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK = 'rolled_back';
const ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED = 'rollback_failed';
const ORANGE_RESTORE_JOB_STATUS_COMPLETED = 'completed';
const ORANGE_RESTORE_JOB_STATUS_FAILED = 'failed';
const ORANGE_RESTORE_JOB_STATUS_CANCELLED = 'cancelled';
const ORANGE_RESTORE_ROLLBACK_CHECKPOINT_PRECHECK_PASSED = 'rollback_precheck_passed';
const ORANGE_RESTORE_ROLLBACK_CHECKPOINT_DATABASE_PENDING = 'rollback_database_pending';
const ORANGE_RESTORE_ROLLBACK_CHECKPOINT_DATABASE_COMPLETE = 'rollback_database_complete';
const ORANGE_RESTORE_ROLLBACK_CHECKPOINT_UPLOADS_PENDING = 'rollback_uploads_pending';
const ORANGE_RESTORE_ROLLBACK_CHECKPOINT_UPLOADS_COMPLETE = 'rollback_uploads_complete';
const ORANGE_RESTORE_ROLLBACK_CHECKPOINT_VALIDATION_PASSED = 'rollback_validation_passed';

/**
 * @return list<string>
 */
function orange_restore_job_rollback_entry_statuses(): array
{
    return [
        ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE,
        ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE,
        ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_MERGED,
        ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED,
        ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS,
        ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED,
    ];
}

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
        ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED,
        ORANGE_RESTORE_JOB_STATUS_MERGE_STARTED,
        ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_SNAPSHOT_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_PENDING,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE,
        ORANGE_RESTORE_JOB_STATUS_MERGE_APPROVED,
        ORANGE_RESTORE_JOB_STATUS_MERGED,
        ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED,
        ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE,
        ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS,
        ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK,
        ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED,
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
        'merge_precheck_passed_at' => '',
        'merge_precheck_production_db' => '',
        'merge_precheck_staging_db' => '',
        'merge_db_export_path' => '',
        'merge_db_export_checksum' => '',
        'merge_db_export_table_count' => 0,
        'merge_db_export_row_count' => 0,
        'merge_started_at' => '',
        'database_cutover_started_at' => '',
        'database_cutover_completed_at' => '',
        'database_cutover_statement_count' => 0,
        'uploads_next_path' => '',
        'uploads_next_manifest_path' => '',
        'uploads_next_verified_at' => '',
        'uploads_next_tree_checksum' => '',
        'pre_merge_uploads_snapshot_path' => '',
        'uploads_pre_merge_path' => '',
        'uploads_cutover_started_at' => '',
        'uploads_snapshot_completed_at' => '',
        'uploads_cutover_snapshot_complete' => false,
        'uploads_first_rename_pending_at' => '',
        'uploads_cutover_first_rename_pending' => false,
        'uploads_first_rename_completed_at' => '',
        'uploads_cutover_first_rename_complete' => false,
        'uploads_second_rename_pending_at' => '',
        'uploads_cutover_second_rename_pending' => false,
        'uploads_cutover_completed_at' => '',
        'production_merged_at' => '',
        'post_validation_passed_at' => '',
        'post_validation_report_path' => '',
        'final_restore_report_path' => '',
        'restore_completed_at' => '',
        'rollback_checkpoint' => '',
        'rollback_started_at' => '',
        'rollback_completed_at' => '',
        'rollback_uploads_source' => '',
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

/**
 * Phase 2D.1 merge foundation transitions (precheck only — no cutover states).
 *
 * @return array<string, list<string>>
 */
function orange_restore_job_merge_foundation_transition_map(): array
{
    return [
        ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE => [
            ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED,
        ],
    ];
}

function orange_restore_job_assert_merge_foundation_transition(string $fromStatus, string $toStatus): void
{
    if ($toStatus === ORANGE_RESTORE_JOB_STATUS_FAILED) {
        return;
    }

    $allowed = orange_restore_job_merge_foundation_transition_map()[$fromStatus] ?? [];
    if (!in_array($toStatus, $allowed, true)) {
        throw new RuntimeException(
            'Invalid merge foundation job transition: ' . $fromStatus . ' -> ' . $toStatus
        );
    }
}

/**
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function orange_restore_job_merge_foundation_transition(
    string $workRoot,
    string $jobId,
    string $newStatus,
    array $patch = []
): array {
    if (!in_array($newStatus, orange_restore_job_allowed_statuses(), true)) {
        throw new RuntimeException('Invalid restore job status: ' . $newStatus);
    }

    $job = orange_restore_job_read($workRoot, $jobId);
    $currentStatus = (string) ($job['status'] ?? '');
    orange_restore_job_assert_merge_foundation_transition($currentStatus, $newStatus);

    $job['status'] = $newStatus;
    foreach ($patch as $key => $value) {
        $job[(string) $key] = $value;
    }
    orange_restore_job_write($workRoot, $job);

    return $job;
}

/**
 * Phase 2D.2 database cutover transitions (no uploads/post-validation states).
 *
 * @return array<string, list<string>>
 */
function orange_restore_job_db_cutover_transition_map(): array
{
    return [
        ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED => [
            ORANGE_RESTORE_JOB_STATUS_MERGE_STARTED,
        ],
        ORANGE_RESTORE_JOB_STATUS_MERGE_STARTED => [
            ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE,
            ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE,
        ],
    ];
}

function orange_restore_job_assert_db_cutover_transition(string $fromStatus, string $toStatus): void
{
    if ($toStatus === ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE) {
        if ($fromStatus === ORANGE_RESTORE_JOB_STATUS_MERGE_STARTED) {
            return;
        }
    }

    $allowed = orange_restore_job_db_cutover_transition_map()[$fromStatus] ?? [];
    if (!in_array($toStatus, $allowed, true)) {
        throw new RuntimeException(
            'Invalid database cutover job transition: ' . $fromStatus . ' -> ' . $toStatus
        );
    }
}

/**
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function orange_restore_job_db_cutover_transition(
    string $workRoot,
    string $jobId,
    string $newStatus,
    array $patch = []
): array {
    if (!in_array($newStatus, orange_restore_job_allowed_statuses(), true)) {
        throw new RuntimeException('Invalid restore job status: ' . $newStatus);
    }

    $job = orange_restore_job_read($workRoot, $jobId);
    $currentStatus = (string) ($job['status'] ?? '');
    orange_restore_job_assert_db_cutover_transition($currentStatus, $newStatus);

    $job['status'] = $newStatus;
    foreach ($patch as $key => $value) {
        $job[(string) $key] = $value;
    }
    orange_restore_job_write($workRoot, $job);

    return $job;
}

/**
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function orange_restore_job_mark_failed_merge(
    string $workRoot,
    string $jobId,
    string $stageFailed,
    string $errorSummary,
    array $patch = []
): array {
    $job = orange_restore_job_read($workRoot, $jobId);
    $currentStatus = (string) ($job['status'] ?? '');
    if ($currentStatus === ORANGE_RESTORE_JOB_STATUS_MERGE_STARTED) {
        return orange_restore_job_db_cutover_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE, array_merge([
            'stage_failed' => $stageFailed,
            'error_summary' => $errorSummary,
            'result' => 'failed_merge',
        ], $patch));
    }
    if ($currentStatus === ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE) {
        return orange_restore_job_uploads_cutover_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE, array_merge([
            'stage_failed' => $stageFailed,
            'error_summary' => $errorSummary,
            'result' => 'failed_merge',
        ], $patch));
    }
    if ($currentStatus === ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING) {
        return orange_restore_job_uploads_cutover_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE, array_merge([
            'stage_failed' => $stageFailed,
            'error_summary' => $errorSummary,
            'result' => 'failed_merge',
        ], $patch));
    }

    throw new RuntimeException(
        'failed_merge is only allowed from merge_started, uploads_first_rename_complete, or uploads_second_rename_pending (current=' . $currentStatus . ').'
    );
}

/**
 * Phase 2D.3 uploads cutover transitions (no post-validation states).
 *
 * @return array<string, list<string>>
 */
function orange_restore_job_uploads_cutover_transition_map(): array
{
    return [
        ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE => [
            ORANGE_RESTORE_JOB_STATUS_UPLOADS_SNAPSHOT_COMPLETE,
        ],
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_SNAPSHOT_COMPLETE => [
            ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_PENDING,
        ],
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_PENDING => [
            ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE,
            ORANGE_RESTORE_JOB_STATUS_UPLOADS_SNAPSHOT_COMPLETE,
        ],
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE => [
            ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING,
        ],
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING => [
            ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE,
            ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE,
        ],
    ];
}

function orange_restore_job_assert_uploads_cutover_transition(string $fromStatus, string $toStatus): void
{
    if ($toStatus === ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE) {
        if (in_array($fromStatus, [
            ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE,
            ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING,
        ], true)) {
            return;
        }
    }

    if ($toStatus === ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE) {
        if ($fromStatus === ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE) {
            return;
        }
    }

    $allowed = orange_restore_job_uploads_cutover_transition_map()[$fromStatus] ?? [];
    if (!in_array($toStatus, $allowed, true)) {
        throw new RuntimeException(
            'Invalid uploads cutover job transition: ' . $fromStatus . ' -> ' . $toStatus
        );
    }
}

/**
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function orange_restore_job_uploads_cutover_transition(
    string $workRoot,
    string $jobId,
    string $newStatus,
    array $patch = []
): array {
    if (!in_array($newStatus, orange_restore_job_allowed_statuses(), true)) {
        throw new RuntimeException('Invalid restore job status: ' . $newStatus);
    }

    $job = orange_restore_job_read($workRoot, $jobId);
    $currentStatus = (string) ($job['status'] ?? '');
    orange_restore_job_assert_uploads_cutover_transition($currentStatus, $newStatus);

    $job['status'] = $newStatus;
    foreach ($patch as $key => $value) {
        $job[(string) $key] = $value;
    }
    orange_restore_job_write($workRoot, $job);

    return $job;
}

function orange_restore_job_mark_failed_merge_db_only(
    string $workRoot,
    string $jobId,
    string $stageFailed,
    string $errorSummary,
    array $patch = []
): array {
    $job = orange_restore_job_read($workRoot, $jobId);
    $currentStatus = (string) ($job['status'] ?? '');
    if ($currentStatus !== ORANGE_RESTORE_JOB_STATUS_MERGE_STARTED) {
        throw new RuntimeException(
            'failed_merge (db cutover) is only allowed from merge_started (current=' . $currentStatus . ').'
        );
    }

    return orange_restore_job_db_cutover_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE, array_merge([
        'stage_failed' => $stageFailed,
        'error_summary' => $errorSummary,
        'result' => 'failed_merge',
    ], $patch));
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

/**
 * Phase 2D.4 post-validation transitions.
 *
 * @return array<string, list<string>>
 */
function orange_restore_job_post_validation_transition_map(): array
{
    return [
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE => [
            ORANGE_RESTORE_JOB_STATUS_MERGED,
            ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE,
        ],
        ORANGE_RESTORE_JOB_STATUS_MERGED => [
            ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED,
            ORANGE_RESTORE_JOB_STATUS_COMPLETED,
            ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE,
        ],
        ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED => [
            ORANGE_RESTORE_JOB_STATUS_COMPLETED,
        ],
    ];
}

function orange_restore_job_assert_post_validation_transition(string $fromStatus, string $toStatus): void
{
    if ($toStatus === ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE) {
        if (in_array($fromStatus, [
            ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE,
            ORANGE_RESTORE_JOB_STATUS_MERGED,
            ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED,
        ], true)) {
            return;
        }
    }

    $allowed = orange_restore_job_post_validation_transition_map()[$fromStatus] ?? [];
    if (!in_array($toStatus, $allowed, true)) {
        throw new RuntimeException(
            'Invalid post-validation job transition: ' . $fromStatus . ' -> ' . $toStatus
        );
    }
}

/**
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function orange_restore_job_post_validation_transition(
    string $workRoot,
    string $jobId,
    string $newStatus,
    array $patch = []
): array {
    if (!in_array($newStatus, orange_restore_job_allowed_statuses(), true)) {
        throw new RuntimeException('Invalid restore job status: ' . $newStatus);
    }

    $job = orange_restore_job_read($workRoot, $jobId);
    $currentStatus = (string) ($job['status'] ?? '');
    orange_restore_job_assert_post_validation_transition($currentStatus, $newStatus);

    $job['status'] = $newStatus;
    foreach ($patch as $key => $value) {
        $job[(string) $key] = $value;
    }
    orange_restore_job_write($workRoot, $job);

    return $job;
}

/**
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function orange_restore_job_mark_failed_post_merge(
    string $workRoot,
    string $jobId,
    string $stageFailed,
    string $errorSummary,
    array $patch = []
): array {
    $job = orange_restore_job_read($workRoot, $jobId);
    $currentStatus = (string) ($job['status'] ?? '');
    if (!in_array($currentStatus, [
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_MERGED,
    ], true)) {
        throw new RuntimeException(
            'failed_post_merge is only allowed from uploads_cutover_complete or production_merged (current=' . $currentStatus . ').'
        );
    }

    return orange_restore_job_post_validation_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE, array_merge([
        'stage_failed' => $stageFailed,
        'error_summary' => $errorSummary,
        'result' => 'failed_post_merge',
    ], $patch));
}

/**
 * Phase 2D.4 rollback transitions.
 *
 * @return array<string, list<string>>
 */
function orange_restore_job_rollback_transition_map(): array
{
    return [
        ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE => [ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS],
        ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE => [ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS],
        ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE => [ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS],
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE => [ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS],
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING => [ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS],
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE => [ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS],
        ORANGE_RESTORE_JOB_STATUS_MERGED => [ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS],
        ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED => [ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS],
        ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED => [ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS],
        ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS => [
            ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK,
            ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED,
        ],
    ];
}

function orange_restore_job_assert_rollback_transition(string $fromStatus, string $toStatus): void
{
    if ($toStatus === ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS) {
        if (in_array($fromStatus, orange_restore_job_rollback_entry_statuses(), true)) {
            return;
        }
    }

    if ($toStatus === ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED) {
        if ($fromStatus === ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS) {
            return;
        }
    }

    if ($toStatus === ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK) {
        if ($fromStatus === ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS) {
            return;
        }
    }

    $allowed = orange_restore_job_rollback_transition_map()[$fromStatus] ?? [];
    if (!in_array($toStatus, $allowed, true)) {
        throw new RuntimeException(
            'Invalid rollback job transition: ' . $fromStatus . ' -> ' . $toStatus
        );
    }
}

/**
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function orange_restore_job_rollback_transition(
    string $workRoot,
    string $jobId,
    string $newStatus,
    array $patch = []
): array {
    if (!in_array($newStatus, orange_restore_job_allowed_statuses(), true)) {
        throw new RuntimeException('Invalid restore job status: ' . $newStatus);
    }

    $job = orange_restore_job_read($workRoot, $jobId);
    $currentStatus = (string) ($job['status'] ?? '');
    orange_restore_job_assert_rollback_transition($currentStatus, $newStatus);

    $job['status'] = $newStatus;
    foreach ($patch as $key => $value) {
        $job[(string) $key] = $value;
    }
    orange_restore_job_write($workRoot, $job);

    return $job;
}

/**
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function orange_restore_job_mark_rollback_failed(
    string $workRoot,
    string $jobId,
    string $stageFailed,
    string $errorSummary,
    array $patch = []
): array {
    $job = orange_restore_job_read($workRoot, $jobId);
    $currentStatus = (string) ($job['status'] ?? '');
    if ($currentStatus !== ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS) {
        throw new RuntimeException(
            'rollback_failed is only allowed from rollback_in_progress (current=' . $currentStatus . ').'
        );
    }

    return orange_restore_job_rollback_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED, array_merge([
        'stage_failed' => $stageFailed,
        'error_summary' => $errorSummary,
        'result' => 'rollback_failed',
    ], $patch));
}

/**
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function orange_restore_job_write_rollback_checkpoint(
    string $workRoot,
    string $jobId,
    string $checkpoint,
    array $patch = []
): array {
    $allowedCheckpoints = [
        ORANGE_RESTORE_ROLLBACK_CHECKPOINT_PRECHECK_PASSED,
        ORANGE_RESTORE_ROLLBACK_CHECKPOINT_DATABASE_PENDING,
        ORANGE_RESTORE_ROLLBACK_CHECKPOINT_DATABASE_COMPLETE,
        ORANGE_RESTORE_ROLLBACK_CHECKPOINT_UPLOADS_PENDING,
        ORANGE_RESTORE_ROLLBACK_CHECKPOINT_UPLOADS_COMPLETE,
        ORANGE_RESTORE_ROLLBACK_CHECKPOINT_VALIDATION_PASSED,
    ];
    if (!in_array($checkpoint, $allowedCheckpoints, true)) {
        throw new RuntimeException('Invalid rollback checkpoint: ' . $checkpoint);
    }

    $job = orange_restore_job_read($workRoot, $jobId);
    if (($job['status'] ?? '') !== ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS) {
        throw new RuntimeException('Rollback checkpoint requires rollback_in_progress status.');
    }

    $job['rollback_checkpoint'] = $checkpoint;
    foreach ($patch as $key => $value) {
        $job[(string) $key] = $value;
    }
    orange_restore_job_write($workRoot, $job);

    return $job;
}
