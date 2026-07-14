<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_job.php';
require_once __DIR__ . '/restore_lock.php';
require_once __DIR__ . '/restore_audit.php';
require_once __DIR__ . '/restore_reauth.php';
require_once __DIR__ . '/restore_validation_adapter.php';
require_once __DIR__ . '/restore_full_staging.php';
require_once __DIR__ . '/restore_merge_maintenance.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_manifest.php';

/**
 * Lazy-load approved phase orchestrator wrappers (avoids heavy merge chain for routing-only callers).
 */
function orange_restore_e2e_require_orchestrator(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    require_once __DIR__ . '/restore_orchestrator.php';
    $loaded = true;
}

const ORANGE_RESTORE_E2E_ACTION_TERMINAL = 'terminal';
const ORANGE_RESTORE_E2E_ACTION_STOP_STAGING_INCOMPLETE = 'stop_staging_incomplete';
const ORANGE_RESTORE_E2E_ACTION_FINALIZE_STAGING = 'finalize_staging_approval';
const ORANGE_RESTORE_E2E_ACTION_STOP_AWAITING_APPROVAL = 'stop_awaiting_approval';
const ORANGE_RESTORE_E2E_ACTION_STOP_MERGE_CONFIRMATION = 'stop_approved_for_merge';
const ORANGE_RESTORE_E2E_ACTION_STOP_CUTOVER_IN_PROGRESS = 'stop_cutover_in_progress';
const ORANGE_RESTORE_E2E_ACTION_STOP_FOR_ROLLBACK = 'stop_for_rollback';
const ORANGE_RESTORE_E2E_ACTION_STOP_POST_VALIDATION_INCOMPLETE = 'stop_post_validation_incomplete';
const ORANGE_RESTORE_E2E_ACTION_RUN_DATABASE_CUTOVER = 'run_database_cutover';
const ORANGE_RESTORE_E2E_ACTION_RUN_UPLOADS_CUTOVER = 'run_uploads_cutover';
const ORANGE_RESTORE_E2E_ACTION_RUN_POST_VALIDATION = 'run_post_validation';
const ORANGE_RESTORE_E2E_ACTION_RUN_ROLLBACK = 'run_rollback';
const ORANGE_RESTORE_E2E_ACTION_UNKNOWN_STATE = 'unknown_state';

/**
 * @return array<string, mixed>
 */
function orange_restore_e2e_action_spec(
    string $action,
    string $instruction,
    string $nextCli,
    bool $requiresReauth = false,
    string $confirmationPhrase = ''
): array {
    return [
        'action' => $action,
        'instruction' => $instruction,
        'next_cli' => $nextCli,
        'requires_reauth' => $requiresReauth,
        'confirmation_phrase' => $confirmationPhrase,
    ];
}

/**
 * State-driven routing for Full Restore resume (Phase 2E).
 *
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function orange_restore_e2e_resolve_action(array $job): array
{
    $status = (string) ($job['status'] ?? '');
    $jobType = (string) ($job['job_type'] ?? '');

    if ($jobType !== ORANGE_RESTORE_JOB_TYPE_FULL) {
        return orange_restore_e2e_action_spec(
            ORANGE_RESTORE_E2E_ACTION_UNKNOWN_STATE,
            'End-to-end orchestrator applies to full_disaster jobs only.',
            '',
            false
        );
    }

    if (in_array($status, orange_restore_job_e2e_terminal_statuses(), true)) {
        return orange_restore_e2e_action_spec(
            ORANGE_RESTORE_E2E_ACTION_TERMINAL,
            'Job is terminal (status=' . $status . '). No further action.',
            'php scripts/backup/restore_status_full.php --job=' . (string) ($job['job_id'] ?? ''),
            false
        );
    }

    if (in_array($status, orange_restore_job_e2e_staging_incomplete_statuses(), true)) {
        return orange_restore_e2e_action_spec(
            ORANGE_RESTORE_E2E_ACTION_STOP_STAGING_INCOMPLETE,
            'Staging workflow incomplete at status=' . $status . '. Cannot resume mid-staging without duplicating fresh backup or staging import. Cancel the job or complete staging manually before resuming.',
            '',
            false
        );
    }

    if ($status === ORANGE_RESTORE_JOB_STATUS_STAGING_VALIDATED) {
        return orange_restore_e2e_action_spec(
            ORANGE_RESTORE_E2E_ACTION_FINALIZE_STAGING,
            'Finalize validated staging and open owner approval window.',
            'php scripts/backup/restore_resume_full.php --job=' . (string) ($job['job_id'] ?? ''),
            false
        );
    }

    if ($status === ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL) {
        return orange_restore_e2e_action_spec(
            ORANGE_RESTORE_E2E_ACTION_STOP_AWAITING_APPROVAL,
            'Owner approval required. Run approval CLI with RESTORE confirmation — no automatic approval.',
            'php scripts/backup/restore_approve_merge.php --job=' . (string) ($job['job_id'] ?? '') . ' --admin-id=N --password=SECRET --confirm=RESTORE --action=approve',
            false
        );
    }

    if ($status === ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE) {
        return orange_restore_e2e_action_spec(
            ORANGE_RESTORE_E2E_ACTION_STOP_MERGE_CONFIRMATION,
            'Job approved for merge. Run merge foundation precheck manually — no automatic merge.',
            'Invoke orange_restore_orchestrator_merge_foundation_precheck() for job, then resume with restore_resume_full.php',
            false
        );
    }

    if ($status === ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED) {
        return orange_restore_e2e_action_spec(
            ORANGE_RESTORE_E2E_ACTION_RUN_DATABASE_CUTOVER,
            'Merge precheck passed. Database cutover requires fresh Super Admin re-auth and RESTORE confirmation.',
            'php scripts/backup/restore_resume_full.php --job=' . (string) ($job['job_id'] ?? '') . ' --admin-id=N --password=SECRET --confirm=RESTORE',
            true,
            'RESTORE'
        );
    }

    if ($status === ORANGE_RESTORE_JOB_STATUS_MERGE_STARTED) {
        return orange_restore_e2e_action_spec(
            ORANGE_RESTORE_E2E_ACTION_STOP_CUTOVER_IN_PROGRESS,
            'Database cutover in progress (merge_started). Resume is blocked to prevent duplicate cutover. Use manual rollback if production is inconsistent.',
            'php scripts/backup/restore_full_rollback.php --job=' . (string) ($job['job_id'] ?? '') . ' --admin-id=N --password=SECRET --confirm=ROLLBACK',
            false
        );
    }

    if (in_array($status, [
        ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_SNAPSHOT_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_PENDING,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING,
    ], true)) {
        return orange_restore_e2e_action_spec(
            ORANGE_RESTORE_E2E_ACTION_RUN_UPLOADS_CUTOVER,
            'Resume uploads cutover from checkpoint status=' . $status . '.',
            'php scripts/backup/restore_resume_full.php --job=' . (string) ($job['job_id'] ?? '') . ' --admin-id=N',
            false
        );
    }

    if (in_array($status, [
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_MERGED,
    ], true)) {
        return orange_restore_e2e_action_spec(
            ORANGE_RESTORE_E2E_ACTION_RUN_POST_VALIDATION,
            'Run production post-validation (no automatic rollback).',
            'php scripts/backup/restore_resume_full.php --job=' . (string) ($job['job_id'] ?? '') . ' --admin-id=N',
            false
        );
    }

    if ($status === ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED) {
        return orange_restore_e2e_action_spec(
            ORANGE_RESTORE_E2E_ACTION_STOP_POST_VALIDATION_INCOMPLETE,
            'Post-validation passed but job not completed. Re-run post-validation CLI to finalize completion steps.',
            'php scripts/backup/restore_full_post_validate.php --job=' . (string) ($job['job_id'] ?? '') . ' --admin-id=N',
            false
        );
    }

    if (in_array($status, [
        ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE,
        ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE,
        ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED,
    ], true)) {
        return orange_restore_e2e_action_spec(
            ORANGE_RESTORE_E2E_ACTION_STOP_FOR_ROLLBACK,
            'Manual rollback required (status=' . $status . '). No automatic rollback.',
            'php scripts/backup/restore_full_rollback.php --job=' . (string) ($job['job_id'] ?? '') . ' --admin-id=N --password=SECRET --confirm=ROLLBACK',
            true,
            'ROLLBACK'
        );
    }

    if ($status === ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS) {
        return orange_restore_e2e_action_spec(
            ORANGE_RESTORE_E2E_ACTION_RUN_ROLLBACK,
            'Resume manual rollback from checkpoint.',
            'php scripts/backup/restore_resume_full.php --job=' . (string) ($job['job_id'] ?? '') . ' --admin-id=N --password=SECRET --confirm=ROLLBACK',
            true,
            'ROLLBACK'
        );
    }

    return orange_restore_e2e_action_spec(
        ORANGE_RESTORE_E2E_ACTION_UNKNOWN_STATE,
        'Unknown or unsupported job state for end-to-end resume (status=' . $status . ').',
        '',
        false
    );
}

/**
 * @param array<string, mixed> $job
 */
function orange_restore_e2e_assert_start_credentials(
    PDO $pdo,
    array $job,
    int $adminId,
    string $password,
    string $confirmationPhrase
): array {
    $admin = orange_restore_reauth_load_admin($pdo, $adminId);
    orange_restore_reauth_assert_restore_permission($admin, $pdo, ORANGE_RESTORE_JOB_TYPE_FULL);
    if (!orange_restore_verify_operator_password($pdo, $adminId, $password)) {
        throw new RuntimeException('Operator password re-authentication failed.');
    }
    if (!orange_restore_validate_confirmation_phrase(ORANGE_RESTORE_JOB_TYPE_FULL, $confirmationPhrase, '')) {
        throw new RuntimeException('Confirmation phrase mismatch (expected RESTORE).');
    }

    return $admin;
}

/**
 * @param array<string, mixed> $job
 */
function orange_restore_e2e_assert_mutating_credentials(
    PDO $pdo,
    array $job,
    int $adminId,
    string $password,
    string $confirmationPhrase,
    string $expectedPhrase
): array {
    $admin = orange_restore_reauth_load_admin($pdo, $adminId);
    orange_restore_reauth_assert_restore_permission($admin, $pdo, (string) ($job['job_type'] ?? ''));
    if (!orange_restore_verify_operator_password($pdo, $adminId, $password)) {
        throw new RuntimeException('Operator password re-authentication failed.');
    }
    if (trim($confirmationPhrase) !== $expectedPhrase) {
        throw new RuntimeException('Confirmation phrase mismatch (expected ' . $expectedPhrase . ').');
    }

    return $admin;
}

/**
 * Finalize staging_validated → awaiting_owner_approval without re-importing staging.
 *
 * @return array<string, mixed>
 */
function orange_restore_e2e_finalize_staging_approval(string $workRoot, string $jobId): array
{
    $job = orange_restore_job_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');
    if ($status !== ORANGE_RESTORE_JOB_STATUS_STAGING_VALIDATED) {
        throw new RuntimeException('Finalize staging requires staging_validated (status=' . $status . ').');
    }

    $stagingGate = orange_restore_validation_adapter_staging_gate($workRoot, $job);
    if (!$stagingGate['ok']) {
        throw new RuntimeException('Staging validation gate failed: ' . implode('; ', $stagingGate['errors']));
    }

    $reportPath = (string) ($job['restore_report_path'] ?? '');
    if ($reportPath === '' || !is_file($reportPath)) {
        $reportPath = orange_restore_job_report_path($workRoot, $jobId);
        $report = [
            'generated_at' => gmdate('c'),
            'job_id' => $jobId,
            'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
            'overall_result' => 'pass',
            'source_package_path' => (string) ($job['source_package_path'] ?? ''),
            'rollback_anchor' => [
                'fresh_backup_path' => (string) ($job['fresh_backup_path'] ?? ''),
                'fresh_backup_checksum' => (string) ($job['fresh_backup_checksum'] ?? ''),
                'rollback_anchor_job_only' => (bool) ($job['rollback_anchor_job_only'] ?? true),
            ],
            'staging_manifest' => (string) ($stagingGate['manifest_path'] ?? ''),
            'production_touched' => false,
        ];
        orange_backup_write_json($reportPath, $report);
    }

    if ($status === ORANGE_RESTORE_JOB_STATUS_STAGING_VALIDATED) {
        $job = orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL, [
            'restore_report_path' => $reportPath,
            'owner_approval_window_started_at' => (string) ($job['owner_approval_window_started_at'] ?? gmdate('c')),
            'result' => 'awaiting_owner_approval',
        ]);
    }

    return $job;
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_e2e_start_full(PDO $pdo, array $options): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('End-to-end restore start is CLI-only.');
    }

    $projectRoot = (string) ($options['project_root'] ?? '');
    $packagePath = (string) ($options['package_path'] ?? '');
    $adminId = (int) ($options['admin_id'] ?? 0);
    $password = (string) ($options['password'] ?? '');
    $confirmationPhrase = (string) ($options['confirmation_phrase'] ?? '');

    if ($projectRoot === '' || $packagePath === '' || $adminId <= 0 || $password === '') {
        throw new InvalidArgumentException('project_root, package_path, admin_id, and password are required.');
    }

    $admin = orange_restore_e2e_assert_start_credentials($pdo, [], $adminId, $password, $confirmationPhrase);

    $env = orange_backup_load_env_array($projectRoot);
    if (is_array($options['env_override'] ?? null)) {
        $env = array_merge($env, $options['env_override']);
    }
    $workRoot = orange_restore_resolve_work_root($env);

    $stagingRunner = isset($options['full_staging_override']) && is_callable($options['full_staging_override'])
        ? $options['full_staging_override']
        : static function (array $stagingOptions): array {
            return orange_restore_full_staging_run($stagingOptions);
        };

    $stagingResult = $stagingRunner([
        'project_root' => $projectRoot,
        'package_path' => $packagePath,
        'env_override' => $options['env_override'] ?? null,
    ]);

    $jobId = (string) ($stagingResult['job_id'] ?? '');
    if ($jobId === '') {
        throw new RuntimeException('Staging workflow did not return a job_id.');
    }

    $job = orange_restore_job_read($workRoot, $jobId);
    $job['operator_admin_id'] = $adminId;
    $job['operator_username'] = (string) ($admin['username'] ?? '');
    orange_restore_job_write($workRoot, $job);

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_e2e_event($job, 'e2e_started', 'pass', [
        'operator_admin_id' => $adminId,
        'operator_username' => (string) ($admin['username'] ?? ''),
    ]));

    $status = (string) ($job['status'] ?? '');
    if ($status === ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL) {
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_e2e_event($job, 'e2e_stopped_for_approval', 'stopped', [
            'operator_admin_id' => $adminId,
        ]));
    }

    $action = orange_restore_e2e_resolve_action($job);

    return [
        'ok' => true,
        'mode' => 'start',
        'job_id' => $jobId,
        'status' => $status,
        'stopped' => true,
        'action' => $action,
        'job' => $job,
        'production_writes' => false,
        'automatic_approval' => false,
        'automatic_merge' => false,
    ];
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_e2e_resume_full(PDO $pdo, array $options): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('End-to-end restore resume is CLI-only.');
    }

    $projectRoot = (string) ($options['project_root'] ?? '');
    $jobId = trim((string) ($options['job_id'] ?? ''));
    $adminId = (int) ($options['admin_id'] ?? 0);
    $password = (string) ($options['password'] ?? '');
    $confirmationPhrase = (string) ($options['confirmation_phrase'] ?? '');

    if ($projectRoot === '' || $jobId === '') {
        throw new InvalidArgumentException('project_root and job_id are required.');
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
    $actionSpec = orange_restore_e2e_resolve_action($job);
    $action = (string) ($actionSpec['action'] ?? '');

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_e2e_event($job, 'e2e_resumed', 'started', [
        'operator_admin_id' => $adminId,
        'resolved_action' => $action,
        'job_status' => (string) ($job['status'] ?? ''),
    ]));

    if ($action === ORANGE_RESTORE_E2E_ACTION_UNKNOWN_STATE) {
        throw new RuntimeException((string) ($actionSpec['instruction'] ?? 'Unknown job state.'));
    }

    if ($action === ORANGE_RESTORE_E2E_ACTION_TERMINAL) {
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_e2e_event($job, 'e2e_terminal', 'pass', [
            'terminal_status' => (string) ($job['status'] ?? ''),
        ]));

        return [
            'ok' => true,
            'mode' => 'resume',
            'job_id' => $jobId,
            'status' => (string) ($job['status'] ?? ''),
            'stopped' => true,
            'terminal' => true,
            'action' => $actionSpec,
            'production_writes' => false,
        ];
    }

    if ($action === ORANGE_RESTORE_E2E_ACTION_STOP_AWAITING_APPROVAL) {
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_e2e_event($job, 'e2e_stopped_for_approval', 'stopped', [
            'operator_admin_id' => $adminId,
        ]));

        return orange_restore_e2e_stop_result($jobId, $job, $actionSpec);
    }

    if ($action === ORANGE_RESTORE_E2E_ACTION_STOP_MERGE_CONFIRMATION) {
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_e2e_event($job, 'e2e_stopped_for_merge_confirmation', 'stopped', [
            'operator_admin_id' => $adminId,
        ]));

        return orange_restore_e2e_stop_result($jobId, $job, $actionSpec);
    }

    if (in_array($action, [
        ORANGE_RESTORE_E2E_ACTION_STOP_FOR_ROLLBACK,
        ORANGE_RESTORE_E2E_ACTION_STOP_CUTOVER_IN_PROGRESS,
        ORANGE_RESTORE_E2E_ACTION_STOP_STAGING_INCOMPLETE,
        ORANGE_RESTORE_E2E_ACTION_STOP_POST_VALIDATION_INCOMPLETE,
    ], true)) {
        if ($action === ORANGE_RESTORE_E2E_ACTION_STOP_FOR_ROLLBACK) {
            orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_e2e_event($job, 'e2e_stopped_for_rollback', 'stopped', [
                'operator_admin_id' => $adminId,
            ]));
        }

        return orange_restore_e2e_stop_result($jobId, $job, $actionSpec);
    }

    if ($adminId <= 0) {
        throw new InvalidArgumentException('admin_id is required for this resume action.');
    }

    if ($action === ORANGE_RESTORE_E2E_ACTION_FINALIZE_STAGING) {
        $job = orange_restore_e2e_finalize_staging_approval($workRoot, $jobId);
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_e2e_event($job, 'e2e_stopped_for_approval', 'stopped', [
            'operator_admin_id' => $adminId,
        ]));
        $actionSpec = orange_restore_e2e_resolve_action($job);

        return orange_restore_e2e_stop_result($jobId, $job, $actionSpec);
    }

    $lockHeld = false;
    try {
        if (in_array($action, [
            ORANGE_RESTORE_E2E_ACTION_RUN_DATABASE_CUTOVER,
            ORANGE_RESTORE_E2E_ACTION_RUN_UPLOADS_CUTOVER,
            ORANGE_RESTORE_E2E_ACTION_RUN_POST_VALIDATION,
            ORANGE_RESTORE_E2E_ACTION_RUN_ROLLBACK,
        ], true)) {
            $lock = orange_restore_acquire_lock($workRoot, $jobId);
            if (!$lock['ok']) {
                throw new RuntimeException($lock['message']);
            }
            $lockHeld = true;
        }

        if ($action === ORANGE_RESTORE_E2E_ACTION_RUN_DATABASE_CUTOVER) {
            if ($password === '' || trim($confirmationPhrase) === '') {
                throw new InvalidArgumentException('password and confirm=RESTORE are required for database cutover.');
            }
            orange_restore_e2e_assert_mutating_credentials($pdo, $job, $adminId, $password, $confirmationPhrase, 'RESTORE');

            $cutoverRunner = isset($options['database_cutover_override']) && is_callable($options['database_cutover_override'])
                ? $options['database_cutover_override']
                : static function (array $cutoverOptions): array {
                    orange_restore_e2e_require_orchestrator();

                    return orange_restore_orchestrator_database_cutover($cutoverOptions);
                };

            $phaseResult = $cutoverRunner(array_merge($options, [
                'project_root' => $projectRoot,
                'work_root' => $workRoot,
                'job_id' => $jobId,
                'admin_id' => $adminId,
                'password' => $password,
                'confirmation_phrase' => $confirmationPhrase,
                'admin_pdo_override' => $options['admin_pdo_override'] ?? null,
            ]));
        } elseif ($action === ORANGE_RESTORE_E2E_ACTION_RUN_UPLOADS_CUTOVER) {
            $uploadsRunner = isset($options['uploads_cutover_override']) && is_callable($options['uploads_cutover_override'])
                ? $options['uploads_cutover_override']
                : static function (array $uploadsOptions): array {
                    orange_restore_e2e_require_orchestrator();

                    return orange_restore_orchestrator_uploads_cutover($uploadsOptions);
                };

            $phaseResult = $uploadsRunner(array_merge($options, [
                'project_root' => $projectRoot,
                'work_root' => $workRoot,
                'job_id' => $jobId,
                'admin_id' => $adminId,
                'admin_pdo_override' => $options['admin_pdo_override'] ?? null,
            ]));
        } elseif ($action === ORANGE_RESTORE_E2E_ACTION_RUN_POST_VALIDATION) {
            $postRunner = isset($options['post_validation_override']) && is_callable($options['post_validation_override'])
                ? $options['post_validation_override']
                : static function (array $postOptions): array {
                    orange_restore_e2e_require_orchestrator();

                    return orange_restore_orchestrator_post_validation($postOptions);
                };

            $phaseResult = $postRunner(array_merge($options, [
                'project_root' => $projectRoot,
                'work_root' => $workRoot,
                'job_id' => $jobId,
                'admin_id' => $adminId,
                'admin_pdo_override' => $options['admin_pdo_override'] ?? null,
            ]));
        } elseif ($action === ORANGE_RESTORE_E2E_ACTION_RUN_ROLLBACK) {
            if ($password === '' || trim($confirmationPhrase) === '') {
                throw new InvalidArgumentException('password and confirm=ROLLBACK are required for rollback resume.');
            }
            orange_restore_e2e_assert_mutating_credentials($pdo, $job, $adminId, $password, $confirmationPhrase, 'ROLLBACK');

            $rollbackRunner = isset($options['rollback_override']) && is_callable($options['rollback_override'])
                ? $options['rollback_override']
                : static function (array $rollbackOptions): array {
                    orange_restore_e2e_require_orchestrator();

                    return orange_restore_orchestrator_rollback($rollbackOptions);
                };

            $phaseResult = $rollbackRunner(array_merge($options, [
                'project_root' => $projectRoot,
                'work_root' => $workRoot,
                'job_id' => $jobId,
                'admin_id' => $adminId,
                'password' => $password,
                'confirmation_phrase' => $confirmationPhrase,
                'admin_pdo_override' => $options['admin_pdo_override'] ?? null,
            ]));
        } else {
            throw new RuntimeException('Unsupported resume action: ' . $action);
        }

        $job = orange_restore_job_read($workRoot, $jobId);
        $nextAction = orange_restore_e2e_resolve_action($job);
        $finalStatus = (string) ($job['status'] ?? '');

        if ($finalStatus === ORANGE_RESTORE_JOB_STATUS_COMPLETED) {
            orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_e2e_event($job, 'e2e_completed', 'pass', [
                'operator_admin_id' => $adminId,
            ]));
        } elseif (in_array($finalStatus, [
            ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL,
            ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE,
            ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE,
            ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE,
        ], true)) {
            // phase-specific stop audits handled by underlying phases or below
        }

        if ($finalStatus === ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL) {
            orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_e2e_event($job, 'e2e_stopped_for_approval', 'stopped', [
                'operator_admin_id' => $adminId,
            ]));
        }

        return [
            'ok' => true,
            'mode' => 'resume',
            'job_id' => $jobId,
            'status' => $finalStatus,
            'stopped' => in_array((string) ($nextAction['action'] ?? ''), [
                ORANGE_RESTORE_E2E_ACTION_STOP_AWAITING_APPROVAL,
                ORANGE_RESTORE_E2E_ACTION_STOP_MERGE_CONFIRMATION,
                ORANGE_RESTORE_E2E_ACTION_STOP_FOR_ROLLBACK,
                ORANGE_RESTORE_E2E_ACTION_TERMINAL,
                ORANGE_RESTORE_E2E_ACTION_STOP_CUTOVER_IN_PROGRESS,
                ORANGE_RESTORE_E2E_ACTION_STOP_STAGING_INCOMPLETE,
                ORANGE_RESTORE_E2E_ACTION_STOP_POST_VALIDATION_INCOMPLETE,
            ], true),
            'terminal' => ($nextAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_TERMINAL,
            'action' => $nextAction,
            'phase_result' => $phaseResult,
            'production_writes' => (bool) ($phaseResult['production_writes'] ?? false),
            'automatic_approval' => false,
            'automatic_merge' => false,
            'automatic_rollback' => false,
        ];
    } finally {
        if ($lockHeld) {
            orange_restore_release_lock($workRoot);
        }
    }
}

/**
 * @param array<string, mixed> $job
 * @param array<string, mixed> $actionSpec
 * @return array<string, mixed>
 */
function orange_restore_e2e_stop_result(string $jobId, array $job, array $actionSpec): array
{
    return [
        'ok' => true,
        'mode' => 'resume',
        'job_id' => $jobId,
        'status' => (string) ($job['status'] ?? ''),
        'stopped' => true,
        'terminal' => ($actionSpec['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_TERMINAL,
        'action' => $actionSpec,
        'production_writes' => false,
        'automatic_approval' => false,
        'automatic_merge' => false,
        'automatic_rollback' => false,
    ];
}

/**
 * Read-only Full Restore status for Phase 2E (no secrets).
 *
 * @return array<string, mixed>
 */
function orange_restore_e2e_status_full(string $projectRoot, string $jobId, array $options = []): array
{
    $env = orange_backup_load_env_array($projectRoot);
    if (is_array($options['env_override'] ?? null)) {
        $env = array_merge($env, $options['env_override']);
    }
    $workRoot = (string) ($options['work_root'] ?? '');
    if ($workRoot === '') {
        $workRoot = orange_restore_resolve_work_root($env);
    }

    orange_restore_e2e_require_orchestrator();
    $base = orange_restore_orchestrator_job_status_report($workRoot, $jobId);
    $job = orange_restore_job_read($workRoot, $jobId);
    $action = orange_restore_e2e_resolve_action($job);
    $lock = orange_restore_lock_status($workRoot);
    $maintenance = orange_restore_merge_maintenance_status($workRoot);

    $e2eAudit = [];
    foreach ($base['audit_events'] ?? [] as $event) {
        if (!is_array($event)) {
            continue;
        }
        if (isset($event['e2e_event'])) {
            $e2eAudit[] = [
                'recorded_at' => (string) ($event['recorded_at'] ?? ''),
                'e2e_event' => (string) ($event['e2e_event'] ?? ''),
                'result' => (string) ($event['result'] ?? ''),
            ];
        }
    }

    unset(
        $base['approval']['token_hash'],
        $base['approval']['approval_token_hash']
    );

    $nextCli = (string) ($action['next_cli'] ?? '');
    $instruction = (string) ($action['instruction'] ?? '');
    $nextCli = preg_replace('/--password(?:=|\s)\S+/', '--password=[REDACTED]', $nextCli) ?? $nextCli;
    $instruction = preg_replace('/--password(?:=|\s)\S+/', '--password=[REDACTED]', $instruction) ?? $instruction;

    return array_merge($base, [
        'e2e' => [
            'next_action' => $action['action'] ?? '',
            'instruction' => $instruction,
            'next_cli' => $nextCli,
            'requires_reauth' => (bool) ($action['requires_reauth'] ?? false),
            'confirmation_phrase' => (string) ($action['confirmation_phrase'] ?? ''),
        ],
        'lock' => [
            'held' => (bool) ($lock['held'] ?? false),
            'job_id' => (string) ($lock['payload']['job_id'] ?? ''),
            'pid' => (int) ($lock['payload']['pid'] ?? 0),
            'acquired_at' => (string) ($lock['payload']['acquired_at'] ?? ''),
            'stale' => (bool) ($lock['stale'] ?? false),
        ],
        'maintenance' => [
            'active' => (bool) ($maintenance['active'] ?? false),
            'job_id' => (string) ($maintenance['job_id'] ?? ''),
            'enabled_at' => (string) ($maintenance['enabled_at'] ?? ''),
            'reason' => (string) ($maintenance['reason'] ?? ''),
        ],
        'merge_checkpoints' => [
            'rollback_checkpoint' => (string) ($job['rollback_checkpoint'] ?? ''),
            'uploads_cutover_completed_at' => (string) ($job['uploads_cutover_completed_at'] ?? ''),
            'production_merged_at' => (string) ($job['production_merged_at'] ?? ''),
            'post_validation_passed_at' => (string) ($job['post_validation_passed_at'] ?? ''),
            'restore_completed_at' => (string) ($job['restore_completed_at'] ?? ''),
        ],
        'e2e_audit_summary' => $e2eAudit,
    ]);
}
