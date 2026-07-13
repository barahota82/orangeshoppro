<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_job.php';
require_once __DIR__ . '/restore_audit.php';
require_once __DIR__ . '/restore_approval.php';
require_once __DIR__ . '/restore_reauth.php';
require_once __DIR__ . '/restore_validation_adapter.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_manifest.php';

/**
 * Build a read-only job status report for CLI (no secrets).
 *
 * @return array<string, mixed>
 */
function orange_restore_orchestrator_job_status_report(string $workRoot, string $jobId): array
{
    $job = orange_restore_job_read($workRoot, $jobId);
    $stagingGate = orange_restore_validation_adapter_staging_gate($workRoot, $job);
    $auditEvents = orange_restore_audit_read_all($workRoot, $jobId);

    $windowStarted = (string) ($job['owner_approval_window_started_at'] ?? '');
    $windowExpires = '';
    if ($windowStarted !== '' && strtotime($windowStarted) !== false) {
        $windowExpires = gmdate('c', strtotime($windowStarted) + ORANGE_RESTORE_APPROVAL_WINDOW_SECONDS);
    }

    $approvalStatus = 'not_applicable';
    $status = (string) ($job['status'] ?? '');
    if ($status === ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL) {
        $approvalStatus = 'pending';
        if ($windowExpires !== '' && time() > strtotime($windowExpires)) {
            $approvalStatus = 'expired';
        }
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE) {
        $approvalStatus = 'approved';
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED) {
        $approvalStatus = 'merge_precheck_passed';
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_MERGE_STARTED) {
        $approvalStatus = 'merge_started';
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE) {
        $approvalStatus = 'database_cutover_complete';
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_UPLOADS_SNAPSHOT_COMPLETE) {
        $approvalStatus = 'uploads_snapshot_complete';
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_PENDING) {
        $approvalStatus = 'uploads_first_rename_pending';
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE) {
        $approvalStatus = 'uploads_first_rename_complete';
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING) {
        $approvalStatus = 'uploads_second_rename_pending';
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE) {
        $approvalStatus = 'uploads_cutover_complete';
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_MERGED) {
        $approvalStatus = 'production_merged';
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED) {
        $approvalStatus = 'post_validation_passed';
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE) {
        $approvalStatus = 'failed_post_merge';
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS) {
        $approvalStatus = 'rollback_in_progress';
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK) {
        $approvalStatus = 'rolled_back';
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED) {
        $approvalStatus = 'rollback_failed';
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_COMPLETED) {
        $approvalStatus = 'completed';
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE) {
        $approvalStatus = 'failed_merge';
    } elseif ($status === ORANGE_RESTORE_JOB_STATUS_CANCELLED) {
        $approvalStatus = (string) ($job['rejected_at'] ?? '') !== '' ? 'rejected' : 'cancelled';
    } elseif (in_array($status, [ORANGE_RESTORE_JOB_STATUS_FAILED], true)) {
        $approvalStatus = 'failed';
    }

    $sanitizedAudit = [];
    foreach ($auditEvents as $event) {
        unset($event['password'], $event['password_hash'], $event['approval_token'], $event['approval_token_plaintext']);
        $sanitizedAudit[] = $event;
    }

    $errors = [];
    $warnings = array_merge($stagingGate['warnings'], []);
    if (($job['error_summary'] ?? '') !== '') {
        $errors[] = (string) $job['error_summary'];
    }
    if ($approvalStatus === 'expired') {
        $errors[] = 'Owner approval window expired.';
    }

    return [
        'job_id' => $jobId,
        'job_type' => (string) ($job['job_type'] ?? ''),
        'scope' => (string) ($job['job_type'] ?? ''),
        'status' => $status,
        'engine_version' => (string) ($job['engine_version'] ?? ''),
        'created_at' => (string) ($job['created_at'] ?? ''),
        'updated_at' => (string) ($job['updated_at'] ?? ''),
        'package' => [
            'path' => (string) ($job['source_package_path'] ?? ''),
            'checksum' => (string) ($job['source_package_checksum'] ?? ''),
            'version' => (string) ($job['package_version'] ?? ''),
            'schema_revision' => (int) ($job['schema_revision'] ?? 0),
        ],
        'country' => [
            'country_id' => (int) ($job['country_id'] ?? 0),
            'country_code' => (string) ($job['country_code'] ?? ''),
        ],
        'staging' => [
            'database' => (string) ($job['staging_db'] ?? ''),
            'uploads_path' => (string) ($job['staging_uploads_path'] ?? ''),
            'manifest_path' => (string) ($stagingGate['manifest_path'] ?? ''),
            'manifest_checksum' => (string) ($stagingGate['manifest_checksum'] ?? ''),
            'validation_passed' => (bool) ($stagingGate['staging_validation_passed'] ?? false),
            'validation_gate_ok' => (bool) ($stagingGate['ok'] ?? false),
            'validation_errors' => $stagingGate['errors'],
        ],
        'rollback_anchor' => [
            'fresh_backup_path' => (string) ($job['fresh_backup_path'] ?? ''),
            'fresh_backup_checksum' => (string) ($job['fresh_backup_checksum'] ?? ''),
            'rollback_anchor_job_only' => (bool) ($job['rollback_anchor_job_only'] ?? true),
        ],
        'approval' => [
            'status' => $approvalStatus,
            'window_started_at' => $windowStarted,
            'window_expires_at' => $windowExpires,
            'phrase_expected' => (string) ($job['approval_phrase_expected'] ?? ''),
            'approved_at' => (string) ($job['owner_approval_at'] ?? ''),
            'approved_by' => (string) ($job['owner_approval_by'] ?? ''),
            'approved_admin_id' => (int) ($job['owner_approval_admin_id'] ?? 0),
            'token_issued_at' => (string) ($job['approval_token_issued_at'] ?? ''),
            'token_expires_at' => (string) ($job['approval_token_expires_at'] ?? ''),
            'token_consumed_at' => (string) ($job['approval_token_consumed_at'] ?? ''),
            'token_invalidated_at' => (string) ($job['approval_token_invalidated_at'] ?? ''),
            'token_active' => orange_restore_job_has_active_approval_token($job),
            'rejected_by' => (string) ($job['rejected_by'] ?? ''),
            'rejected_at' => (string) ($job['rejected_at'] ?? ''),
            'rejection_reason' => (string) ($job['rejection_reason'] ?? ''),
            'cancelled_by' => (string) ($job['cancelled_by'] ?? ''),
            'cancelled_at' => (string) ($job['cancelled_at'] ?? ''),
            'cancellation_reason' => (string) ($job['cancellation_reason'] ?? ''),
        ],
        'audit_events' => $sanitizedAudit,
        'errors' => $errors,
        'warnings' => $warnings,
        'production_writes' => false,
    ];
}

function orange_restore_orchestrator_assert_awaiting_approval(array $job): void
{
    if (($job['status'] ?? '') !== ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL) {
        throw new RuntimeException('Restore job is not awaiting owner approval.');
    }
}

/**
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function orange_restore_orchestrator_approval_transition(
    string $workRoot,
    string $jobId,
    string $newStatus,
    array $patch = [],
    bool $preserveApprovalToken = false
): array {
    if (!in_array($newStatus, orange_restore_job_allowed_statuses(), true)) {
        throw new RuntimeException('Invalid restore job status: ' . $newStatus);
    }

    $job = orange_restore_job_read($workRoot, $jobId);
    $currentStatus = (string) ($job['status'] ?? '');
    orange_restore_job_assert_approval_transition($currentStatus, $newStatus);

    if (!$preserveApprovalToken && orange_restore_job_has_active_approval_token($job)) {
        $job = orange_restore_approval_invalidate_token($job, 'job_mutated_before_approval_transition');
    }

    $job['status'] = $newStatus;
    foreach ($patch as $key => $value) {
        $job[(string) $key] = $value;
    }
    orange_restore_job_write($workRoot, $job);

    return $job;
}

function orange_restore_orchestrator_assert_not_terminal(array $job): void
{
    $status = (string) ($job['status'] ?? '');
    if (in_array($status, [
        ORANGE_RESTORE_JOB_STATUS_FAILED,
        ORANGE_RESTORE_JOB_STATUS_CANCELLED,
        ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE,
        ORANGE_RESTORE_JOB_STATUS_MERGED,
        ORANGE_RESTORE_JOB_STATUS_COMPLETED,
    ], true)) {
        throw new RuntimeException('Restore job is in terminal or post-approval state: ' . $status);
    }
}

function orange_restore_orchestrator_assert_approval_window_open(array $job): void
{
    $started = (string) ($job['owner_approval_window_started_at'] ?? '');
    if ($started === '') {
        throw new RuntimeException('Owner approval window has not started.');
    }
    $startTs = strtotime($started);
    if ($startTs === false) {
        throw new RuntimeException('Invalid owner approval window timestamp.');
    }
    if (time() > $startTs + ORANGE_RESTORE_APPROVAL_WINDOW_SECONDS) {
        throw new RuntimeException('Owner approval window expired.');
    }
}

function orange_restore_orchestrator_assert_rollback_anchor(array $job): void
{
    $path = trim((string) ($job['fresh_backup_path'] ?? ''));
    $checksum = trim((string) ($job['fresh_backup_checksum'] ?? ''));
    if ($path === '' || $checksum === '') {
        throw new RuntimeException('Rollback anchor (fresh backup) is missing on job.');
    }
    if (!(bool) ($job['rollback_anchor_job_only'] ?? false)) {
        throw new RuntimeException('Rollback anchor must be job-only (rollback_anchor_job_only=true).');
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_orchestrator_verify_live_package_checksum(string $backupRoot, array $job): array
{
    $expected = (string) ($job['source_package_checksum'] ?? '');
    if ($expected === '') {
        throw new RuntimeException('Job source_package_checksum is missing.');
    }

    $packagePath = orange_restore_resolve_package_path($backupRoot, (string) ($job['source_package_path'] ?? ''));
    $jobType = (string) ($job['job_type'] ?? '');

    $manifestPath = $packagePath . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($manifestPath)) {
        throw new RuntimeException('Live package manifest.json missing.');
    }
    $manifestRaw = file_get_contents($manifestPath);
    if ($manifestRaw === false) {
        throw new RuntimeException('Cannot read live package manifest.json.');
    }
    /** @var array<string, mixed> $manifest */
    $manifest = json_decode($manifestRaw, true);
    if (!is_array($manifest)) {
        throw new RuntimeException('Invalid live package manifest.json.');
    }

    if ($jobType === ORANGE_RESTORE_JOB_TYPE_COUNTRY) {
        $checksumFile = $packagePath . DIRECTORY_SEPARATOR . 'checksums.sha256';
        if (is_file($checksumFile)) {
            $liveChecksum = orange_backup_sha256_file($checksumFile);
        } else {
            $liveChecksum = trim((string) ($manifest['package_checksum'] ?? ''));
            if ($liveChecksum === '') {
                throw new RuntimeException('Cannot determine live country package checksum.');
            }
        }
    } else {
        $liveChecksum = orange_restore_validation_adapter_live_package_checksum($packagePath, $manifest);
    }

    if (!hash_equals($expected, $liveChecksum)) {
        throw new RuntimeException('Package checksum mismatch (job vs live package).');
    }

    return ['package_path' => $packagePath, 'checksum' => $liveChecksum, 'manifest' => $manifest];
}

/**
 * Approve restore for merge (Phase 2C — state only; no production writes).
 *
 * @param array{
 *   project_root:string,
 *   work_root?:string,
 *   job_id:string,
 *   admin_id:int,
 *   password:string,
 *   confirmation_phrase:string,
 *   env_override?:array<string,mixed>
 * } $options
 * @return array<string, mixed>
 */
function orange_restore_orchestrator_approve_for_merge(PDO $pdo, array $options): array
{
    $projectRoot = (string) ($options['project_root'] ?? '');
    $jobId = trim((string) ($options['job_id'] ?? ''));
    $adminId = (int) ($options['admin_id'] ?? 0);
    $password = (string) ($options['password'] ?? '');
    $confirmationPhrase = (string) ($options['confirmation_phrase'] ?? '');

    if ($projectRoot === '' || $jobId === '' || $adminId <= 0) {
        throw new InvalidArgumentException('project_root, job_id, and admin_id are required.');
    }

    $env = orange_backup_load_env_array($projectRoot);
    if (is_array($options['env_override'] ?? null)) {
        $env = array_merge($env, $options['env_override']);
    }
    $backupRoot = orange_backup_resolve_root($env);
    $workRoot = (string) ($options['work_root'] ?? '');
    if ($workRoot === '') {
        $workRoot = orange_restore_resolve_work_root($env);
    }

    $job = orange_restore_job_read($workRoot, $jobId);
    orange_restore_orchestrator_assert_awaiting_approval($job);
    orange_restore_orchestrator_assert_not_terminal($job);
    orange_restore_orchestrator_assert_approval_window_open($job);

    if ((string) ($job['owner_approval_at'] ?? '') !== '') {
        throw new RuntimeException('Restore job already approved.');
    }

    $admin = orange_restore_reauth_load_admin($pdo, $adminId);
    $jobType = (string) ($job['job_type'] ?? '');

    try {
        orange_restore_reauth_assert_restore_permission($admin, $pdo, $jobType);
    } catch (Throwable $e) {
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_approval_event($job, 'permission_check', 'failed', [
            'operator_admin_id' => $adminId,
            'operator_username' => (string) ($admin['username'] ?? ''),
            'error' => $e->getMessage(),
        ]));
        throw $e;
    }

    $passwordOk = orange_restore_verify_operator_password($pdo, $adminId, $password);
    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_approval_event($job, 'reauth', $passwordOk ? 'pass' : 'failed', [
        'operator_admin_id' => $adminId,
        'operator_username' => (string) ($admin['username'] ?? ''),
    ]));
    if (!$passwordOk) {
        throw new RuntimeException('Operator password re-authentication failed.');
    }

    $countryCode = (string) ($job['country_code'] ?? '');
    $phraseOk = orange_restore_validate_confirmation_phrase($jobType, $confirmationPhrase, $countryCode);
    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_approval_event($job, 'confirmation_phrase', $phraseOk ? 'pass' : 'failed', [
        'operator_admin_id' => $adminId,
        'operator_username' => (string) ($admin['username'] ?? ''),
        'phrase_expected' => orange_restore_confirmation_phrase($jobType, $countryCode),
    ]));
    if (!$phraseOk) {
        throw new RuntimeException('Confirmation phrase mismatch.');
    }

    orange_restore_orchestrator_assert_rollback_anchor($job);
    orange_restore_orchestrator_verify_live_package_checksum($backupRoot, $job);

    $stagingGate = orange_restore_validation_adapter_staging_gate($workRoot, $job);
    if (!$stagingGate['ok']) {
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_approval_event($job, 'staging_validation_gate', 'failed', [
            'errors' => $stagingGate['errors'],
        ]));
        throw new RuntimeException('Staging validation gate failed: ' . implode('; ', $stagingGate['errors']));
    }

    $rollbackChecksum = (string) ($job['fresh_backup_checksum'] ?? '');
    $binding = [
        'job_id' => $jobId,
        'operator_id' => $adminId,
        'scope' => $jobType,
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => $countryCode,
        'source_package_checksum' => (string) ($job['source_package_checksum'] ?? ''),
        'staging_restore_manifest_checksum' => (string) ($stagingGate['manifest_checksum'] ?? ''),
        'rollback_anchor_checksum' => $rollbackChecksum,
    ];

    $issued = orange_restore_approval_issue_token($binding);
    $job = orange_restore_approval_store_token_on_job($job, $issued['hash'], $issued['binding']);
    orange_restore_approval_write_token_sidecar($workRoot, $jobId, [
        'token_hash' => $issued['hash'],
        'binding' => $issued['binding'],
        'issued_at' => $issued['issued_at'],
        'expires_at' => $issued['expires_at'],
    ]);

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_approval_event($job, 'approval_token_issued', 'pass', [
        'operator_admin_id' => $adminId,
        'operator_username' => (string) ($admin['username'] ?? ''),
        'token_expires_at' => $issued['expires_at'],
        'staging_restore_manifest_checksum' => $binding['staging_restore_manifest_checksum'],
        'rollback_anchor_checksum' => $rollbackChecksum,
    ]));

    $verify = orange_restore_approval_verify_token($job, $issued['plaintext'], true);
    if (!$verify['ok']) {
        throw new RuntimeException('Approval token self-verify failed: ' . (string) ($verify['error'] ?? ''));
    }
    $job['approval_token_consumed_at'] = gmdate('c');

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_approval_event($job, 'approval_token_consumed', 'pass', [
        'operator_admin_id' => $adminId,
        'operator_username' => (string) ($admin['username'] ?? ''),
    ]));

    $now = gmdate('c');
    $job = orange_restore_orchestrator_approval_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE, [
        'approval_token_hash' => $issued['hash'],
        'approval_token_binding' => $issued['binding'],
        'approval_token_issued_at' => $issued['issued_at'],
        'approval_token_expires_at' => $issued['expires_at'],
        'approval_token_consumed_at' => $job['approval_token_consumed_at'],
        'owner_approval_at' => $now,
        'owner_approval_by' => (string) ($admin['username'] ?? ''),
        'owner_approval_admin_id' => $adminId,
        'production_merge_approved' => true,
        'result' => 'approved_for_merge',
    ], true);

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_approval_event($job, 'state_transition', 'pass', [
        'from_status' => ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL,
        'to_status' => ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE,
        'operator_admin_id' => $adminId,
        'operator_username' => (string) ($admin['username'] ?? ''),
        'production_writes' => false,
    ]));

    return [
        'ok' => true,
        'job_id' => $jobId,
        'status' => ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE,
        'job' => $job,
        'production_writes' => false,
        'merge_executed' => false,
    ];
}

/**
 * Reject a restore job before merge (awaiting_owner_approval only).
 *
 * @param array{
 *   project_root:string,
 *   work_root?:string,
 *   job_id:string,
 *   admin_id:int,
 *   password:string,
 *   reason:string,
 *   env_override?:array<string,mixed>
 * } $options
 * @return array<string, mixed>
 */
function orange_restore_orchestrator_reject(PDO $pdo, array $options): array
{
    return orange_restore_orchestrator_cancel_or_reject($pdo, $options, 'reject');
}

/**
 * Cancel a restore job before merge (awaiting_owner_approval only).
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_orchestrator_cancel(PDO $pdo, array $options): array
{
    return orange_restore_orchestrator_cancel_or_reject($pdo, $options, 'cancel');
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_orchestrator_cancel_or_reject(PDO $pdo, array $options, string $mode): array
{
    $projectRoot = (string) ($options['project_root'] ?? '');
    $jobId = trim((string) ($options['job_id'] ?? ''));
    $adminId = (int) ($options['admin_id'] ?? 0);
    $password = (string) ($options['password'] ?? '');
    $reason = trim((string) ($options['reason'] ?? ''));

    if ($projectRoot === '' || $jobId === '' || $adminId <= 0) {
        throw new InvalidArgumentException('project_root, job_id, and admin_id are required.');
    }
    if ($reason === '') {
        throw new InvalidArgumentException('reason is required for rejection/cancellation.');
    }

    $env = orange_backup_load_env_array($projectRoot);
    if (is_array($options['env_override'] ?? null)) {
        $env = array_merge($env, $options['env_override']);
    }
    $workRoot = (string) ($options['work_root'] ?? '');
    if ($workRoot === '') {
        $workRoot = orange_restore_resolve_work_root($env);
    }

    $job = orange_restore_job_read($workRoot, $jobId);
    orange_restore_orchestrator_assert_awaiting_approval($job);

    $admin = orange_restore_reauth_load_admin($pdo, $adminId);
    $jobType = (string) ($job['job_type'] ?? '');
    orange_restore_reauth_assert_restore_permission($admin, $pdo, $jobType);

    if (!orange_restore_verify_operator_password($pdo, $adminId, $password)) {
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_approval_event($job, 'reauth', 'failed', [
            'operator_admin_id' => $adminId,
            'context' => $mode,
        ]));
        throw new RuntimeException('Operator password re-authentication failed.');
    }

    if (orange_restore_job_has_active_approval_token($job)) {
        $job = orange_restore_approval_invalidate_token($job, $mode . '_before_merge');
    }

    $now = gmdate('c');
    $username = (string) ($admin['username'] ?? '');
    $patch = [
        'result' => $mode === 'reject' ? 'rejected' : 'cancelled',
    ];
    if ($mode === 'reject') {
        $patch['rejected_by'] = $username;
        $patch['rejected_at'] = $now;
        $patch['rejection_reason'] = $reason;
    } else {
        $patch['cancelled_by'] = $username;
        $patch['cancelled_at'] = $now;
        $patch['cancellation_reason'] = $reason;
    }

    $job = orange_restore_orchestrator_approval_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_CANCELLED, $patch, true);

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_approval_event($job, $mode === 'reject' ? 'approval_rejected' : 'approval_cancelled', 'pass', [
        'operator_admin_id' => $adminId,
        'operator_username' => $username,
        'reason' => $reason,
        'from_status' => ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL,
        'to_status' => ORANGE_RESTORE_JOB_STATUS_CANCELLED,
        'production_writes' => false,
    ]));

    return [
        'ok' => true,
        'job_id' => $jobId,
        'status' => ORANGE_RESTORE_JOB_STATUS_CANCELLED,
        'mode' => $mode,
        'job' => $job,
        'production_writes' => false,
    ];
}

require_once __DIR__ . '/restore_merge_maintenance.php';
require_once __DIR__ . '/restore_merge_precheck.php';

/**
 * Phase 2D.1 foundation — run merge precheck gates only (no production writes).
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_orchestrator_merge_foundation_precheck(array $options): array
{
    return orange_restore_merge_precheck_run($options);
}

/**
 * @param array<string, mixed> $job
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function orange_restore_orchestrator_merge_maintenance_enable(
    string $workRoot,
    string $jobId,
    array $job,
    array $context = []
): array {
    $result = orange_restore_merge_maintenance_enable($workRoot, $jobId, $context);
    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_merge_event($job, 'maintenance_enabled', 'pass', [
        'maintenance_path' => (string) ($result['path'] ?? ''),
        'production_writes' => false,
    ]));

    return $result;
}

/**
 * @param array<string, mixed> $job
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function orange_restore_orchestrator_merge_maintenance_disable(
    string $workRoot,
    string $jobId,
    array $job,
    array $context = []
): array {
    $result = orange_restore_merge_maintenance_disable($workRoot, $jobId, $context);
    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_merge_event($job, 'maintenance_disabled', 'pass', [
        'production_writes' => false,
    ]));

    return $result;
}

/**
 * @return array<string, mixed>
 */
function orange_restore_orchestrator_merge_maintenance_status(string $workRoot): array
{
    return orange_restore_merge_maintenance_status($workRoot);
}

/**
 * @return array<string, mixed>
 */
function orange_restore_orchestrator_merge_maintenance_verify(string $workRoot, string $jobId): array
{
    return orange_restore_merge_maintenance_verify($workRoot, $jobId);
}

require_once __DIR__ . '/restore_merge_db_cutover.php';
require_once __DIR__ . '/restore_merge_uploads_cutover.php';
require_once __DIR__ . '/restore_merge_post_validation.php';
require_once __DIR__ . '/restore_merge_rollback.php';

/**
 * Phase 2D.2 — production database cutover (no uploads cutover).
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_orchestrator_database_cutover(array $options): array
{
    return orange_restore_merge_db_cutover_run($options);
}

/**
 * Phase 2D.3 — production uploads cutover (no DB writes, no rollback, no post-validation).
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_orchestrator_uploads_cutover(array $options): array
{
    return orange_restore_merge_uploads_cutover_run($options);
}

/**
 * Phase 2D.4 — production post-validation (no rollback).
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_orchestrator_post_validation(array $options): array
{
    return orange_restore_merge_post_validation_run($options);
}

/**
 * Phase 2D.4 — manual production rollback (job-scoped anchor only).
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_orchestrator_rollback(array $options): array
{
    return orange_restore_merge_rollback_run($options);
}
