<?php

declare(strict_types=1);

/**
 * Phase 2E — End-to-end restore orchestrator self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_restore_e2e.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_paths.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_job.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_lock.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_audit.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_approval.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_reauth.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_maintenance.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_validation_adapter.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_e2e_orchestrator.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin_permissions.php';

$failures = 0;

function e2e_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function e2e_temp_root(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_2e_' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

function e2e_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            e2e_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function e2e_try(callable $fn): ?Throwable
{
    try {
        $fn();

        return null;
    } catch (Throwable $e) {
        return $e;
    }
}

function e2e_test_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $hash = password_hash('correct-pass', PASSWORD_DEFAULT);
    $pdo->exec(
        'CREATE TABLE admins (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            display_name TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            is_superuser INTEGER NOT NULL DEFAULT 0
        )'
    );
    $pdo->exec(
        'CREATE TABLE admin_permissions (
            admin_id INTEGER NOT NULL,
            resource_key TEXT NOT NULL,
            can_view INTEGER NOT NULL DEFAULT 0,
            can_edit INTEGER NOT NULL DEFAULT 0,
            can_delete INTEGER NOT NULL DEFAULT 0
        )'
    );
    $pdo->exec(
        'INSERT INTO admins (id, username, password_hash, display_name, is_active, is_superuser)
         VALUES (1, \'superadmin\', ' . $pdo->quote($hash) . ', \'Super Admin\', 1, 1)'
    );
    $pdo->exec(
        'INSERT INTO admin_permissions (admin_id, resource_key, can_view, can_edit, can_delete)
         VALUES (1, \'backup_restore_full\', 1, 0, 0)'
    );

    $GLOBALS['orange_schema_table_cache'] = [
        'admins' => true,
        'admin_permissions' => true,
    ];
    $GLOBALS['orange_schema_column_cache'] = [
        'admin_permissions.can_lock' => false,
        'admin_permissions.can_unlock' => false,
        'admin_permissions.can_print' => false,
        'admin_permissions.can_export' => false,
    ];

    return $pdo;
}

/**
 * @return array<string, mixed>
 */
function e2e_seed_job(string $status, array $extra = []): array
{
    $backupRoot = e2e_temp_root();
    $workRoot = $backupRoot . DIRECTORY_SEPARATOR . 'restore_work';
    mkdir($workRoot);
    $projectRoot = $backupRoot . DIRECTORY_SEPARATOR . 'project';
    mkdir($projectRoot);

    $job = orange_restore_job_create($workRoot, [
        'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
        'source_package_path' => $backupRoot . DIRECTORY_SEPARATOR . 'pkg',
        'source_package_checksum' => str_repeat('a', 64),
        'schema_revision' => 121,
        'operator_admin_id' => 1,
        'operator_username' => 'superadmin',
    ]);
    $jobId = (string) $job['job_id'];
    $job['status'] = $status;
    foreach ($extra as $key => $value) {
        $job[(string) $key] = $value;
    }
    orange_restore_job_write($workRoot, $job);

    return [
        'backupRoot' => $backupRoot,
        'workRoot' => $workRoot,
        'projectRoot' => $projectRoot,
        'jobId' => $jobId,
        'job' => orange_restore_job_read($workRoot, $jobId),
        'env' => ['ORANGE_BACKUP_ROOT' => $backupRoot],
        'adminPdo' => e2e_test_pdo(),
    ];
}

// --- routing ---
$awaiting = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL);
$awaitAction = orange_restore_e2e_resolve_action($awaiting['job']);
e2e_self_test(
    ($awaitAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_STOP_AWAITING_APPROVAL,
    'routing: awaiting_owner_approval stops for approval'
);
e2e_rmdir($awaiting['backupRoot']);

$approved = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE, ['production_merge_approved' => true]);
$approvedAction = orange_restore_e2e_resolve_action($approved['job']);
e2e_self_test(
    ($approvedAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_STOP_MERGE_CONFIRMATION,
    'routing: approved_for_merge stops for merge precheck'
);
e2e_rmdir($approved['backupRoot']);

$precheck = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED);
$precheckAction = orange_restore_e2e_resolve_action($precheck['job']);
e2e_self_test(
    ($precheckAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_RUN_DATABASE_CUTOVER
    && ($precheckAction['requires_reauth'] ?? false) === true,
    'routing: merge_precheck_passed requires database cutover with re-auth'
);
e2e_rmdir($precheck['backupRoot']);

$uploads = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE);
$uploadsAction = orange_restore_e2e_resolve_action($uploads['job']);
e2e_self_test(
    ($uploadsAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_RUN_UPLOADS_CUTOVER,
    'routing: uploads checkpoint resumes uploads cutover'
);
e2e_rmdir($uploads['backupRoot']);

$postVal = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE);
$postAction = orange_restore_e2e_resolve_action($postVal['job']);
e2e_self_test(
    ($postAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_RUN_POST_VALIDATION
    && ($postAction['requires_reauth'] ?? false) === true,
    'routing: uploads_cutover_complete resumes post-validation with re-auth'
);
e2e_rmdir($postVal['backupRoot']);

$merged = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_MERGED);
$mergedAction = orange_restore_e2e_resolve_action($merged['job']);
e2e_self_test(
    ($mergedAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_RUN_POST_VALIDATION,
    'routing: production_merged resumes post-validation'
);
e2e_rmdir($merged['backupRoot']);

$postPassed = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED, [
    'post_validation_report_path' => '',
    'post_validation_passed_at' => gmdate('c'),
]);
$postPassedAction = orange_restore_e2e_resolve_action($postPassed['job']);
e2e_self_test(
    ($postPassedAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_RUN_POST_VALIDATION_FINALIZE,
    'routing: post_validation_passed finalizes completion'
);
e2e_rmdir($postPassed['backupRoot']);

$failedMerge = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE);
$failedMergeAction = orange_restore_e2e_resolve_action($failedMerge['job']);
e2e_self_test(
    ($failedMergeAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_STOP_FOR_ROLLBACK,
    'routing: failed_merge stops for manual rollback'
);
e2e_rmdir($failedMerge['backupRoot']);

$failedPost = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
$failedPostAction = orange_restore_e2e_resolve_action($failedPost['job']);
e2e_self_test(
    ($failedPostAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_STOP_FOR_ROLLBACK,
    'routing: failed_post_merge stops for manual rollback'
);
e2e_rmdir($failedPost['backupRoot']);

$rollbackFailed = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED);
$rollbackFailedAction = orange_restore_e2e_resolve_action($rollbackFailed['job']);
e2e_self_test(
    ($rollbackFailedAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_STOP_FOR_ROLLBACK,
    'routing: rollback_failed stops for manual rollback'
);
e2e_rmdir($rollbackFailed['backupRoot']);

$rollback = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS, [
    'rollback_checkpoint' => ORANGE_RESTORE_ROLLBACK_CHECKPOINT_DATABASE_COMPLETE,
]);
$rollbackAction = orange_restore_e2e_resolve_action($rollback['job']);
e2e_self_test(
    ($rollbackAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_RUN_ROLLBACK,
    'routing: rollback_in_progress resumes rollback'
);
e2e_rmdir($rollback['backupRoot']);

$completed = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_COMPLETED);
$completedAction = orange_restore_e2e_resolve_action($completed['job']);
e2e_self_test(
    ($completedAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_TERMINAL,
    'routing: completed is terminal'
);
e2e_rmdir($completed['backupRoot']);

$rolledBack = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK);
$rolledBackAction = orange_restore_e2e_resolve_action($rolledBack['job']);
e2e_self_test(
    ($rolledBackAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_TERMINAL,
    'routing: rolled_back is terminal'
);
e2e_rmdir($rolledBack['backupRoot']);

$cancelled = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_CANCELLED);
$cancelledAction = orange_restore_e2e_resolve_action($cancelled['job']);
e2e_self_test(
    ($cancelledAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_TERMINAL,
    'routing: cancelled is terminal'
);
e2e_rmdir($cancelled['backupRoot']);

$unknown = e2e_seed_job('merge_approved');
$unknownAction = orange_restore_e2e_resolve_action($unknown['job']);
e2e_self_test(
    ($unknownAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_UNKNOWN_STATE,
    'routing: unknown state fails closed'
);
e2e_rmdir($unknown['backupRoot']);

// --- start stops at awaiting_owner_approval ---
$startRoot = e2e_temp_root();
$startProject = $startRoot . DIRECTORY_SEPARATOR . 'project';
mkdir($startProject);
mkdir($startRoot . DIRECTORY_SEPARATOR . 'pkg');
$startWorkRoot = orange_restore_resolve_work_root(['ORANGE_BACKUP_ROOT' => $startRoot]);
$created = orange_restore_job_create($startWorkRoot, [
    'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
    'source_package_path' => $startRoot . DIRECTORY_SEPARATOR . 'pkg',
    'source_package_checksum' => str_repeat('b', 64),
    'schema_revision' => 121,
    'operator_admin_id' => 0,
    'operator_username' => 'cli',
]);
$startJobId = (string) $created['job_id'];
$created['status'] = ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL;
orange_restore_job_write($startWorkRoot, $created);

$startResult = orange_restore_e2e_start_full(e2e_test_pdo(), [
    'project_root' => $startProject,
    'package_path' => $startRoot . DIRECTORY_SEPARATOR . 'pkg',
    'admin_id' => 1,
    'password' => 'correct-pass',
    'confirmation_phrase' => 'RESTORE',
    'env_override' => ['ORANGE_BACKUP_ROOT' => $startRoot],
    'full_staging_override' => static function () use ($startJobId): array {
        return [
            'ok' => true,
            'job_id' => $startJobId,
        ];
    },
]);
e2e_self_test(
    ($startResult['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL
    && ($startResult['automatic_approval'] ?? true) === false,
    'start: new full restore stops at awaiting_owner_approval'
);
e2e_rmdir($startRoot);

// --- resume stop points ---
$awaitResume = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL);
$awaitResumeResult = orange_restore_e2e_resume_full($awaitResume['adminPdo'], [
    'project_root' => $awaitResume['projectRoot'],
    'work_root' => $awaitResume['workRoot'],
    'job_id' => $awaitResume['jobId'],
    'admin_id' => 1,
    'env_override' => $awaitResume['env'],
]);
e2e_self_test(
    ($awaitResumeResult['stopped'] ?? false) === true
    && ($awaitResumeResult['action']['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_STOP_AWAITING_APPROVAL,
    'resume: awaiting approval never auto-approves'
);
$awaitResumeAgain = orange_restore_e2e_resume_full($awaitResume['adminPdo'], [
    'project_root' => $awaitResume['projectRoot'],
    'work_root' => $awaitResume['workRoot'],
    'job_id' => $awaitResume['jobId'],
    'admin_id' => 1,
    'env_override' => $awaitResume['env'],
]);
e2e_self_test(
    ($awaitResumeAgain['action']['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_STOP_AWAITING_APPROVAL,
    'resume: repeated awaiting approval is idempotent'
);
e2e_rmdir($awaitResume['backupRoot']);

$approvedResume = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE, ['production_merge_approved' => true]);
$approvedResumeResult = orange_restore_e2e_resume_full($approvedResume['adminPdo'], [
    'project_root' => $approvedResume['projectRoot'],
    'work_root' => $approvedResume['workRoot'],
    'job_id' => $approvedResume['jobId'],
    'admin_id' => 1,
    'env_override' => $approvedResume['env'],
]);
e2e_self_test(
    ($approvedResumeResult['stopped'] ?? false) === true
    && ($approvedResumeResult['automatic_merge'] ?? true) === false,
    'resume: approved_for_merge never auto-merges'
);
e2e_rmdir($approvedResume['backupRoot']);

// --- merge_precheck_passed requires re-auth ---
$cutoverSeed = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED);
$cutoverErr = e2e_try(static function () use ($cutoverSeed): void {
    orange_restore_e2e_resume_full($cutoverSeed['adminPdo'], [
        'project_root' => $cutoverSeed['projectRoot'],
        'work_root' => $cutoverSeed['workRoot'],
        'job_id' => $cutoverSeed['jobId'],
        'admin_id' => 1,
        'env_override' => $cutoverSeed['env'],
    ]);
});
e2e_self_test(
    $cutoverErr !== null && str_contains($cutoverErr->getMessage(), 'password'),
    'resume: merge_precheck_passed requires fresh re-auth'
);
$cutoverOk = orange_restore_e2e_resume_full($cutoverSeed['adminPdo'], [
    'project_root' => $cutoverSeed['projectRoot'],
    'work_root' => $cutoverSeed['workRoot'],
    'job_id' => $cutoverSeed['jobId'],
    'admin_id' => 1,
    'password' => 'correct-pass',
    'confirmation_phrase' => 'RESTORE',
    'env_override' => $cutoverSeed['env'],
    'database_cutover_override' => static fn (): array => [
        'ok' => true,
        'job_id' => $cutoverSeed['jobId'],
        'status' => ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE,
        'production_writes' => true,
    ],
]);
e2e_self_test(
    ($cutoverOk['phase_result']['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE,
    'resume: database cutover delegates to approved phase function'
);
e2e_rmdir($cutoverSeed['backupRoot']);

// --- uploads + post-validation resume delegates ---
$uploadsSeed = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE);
$uploadsErr = e2e_try(static function () use ($uploadsSeed): void {
    orange_restore_e2e_resume_full($uploadsSeed['adminPdo'], [
        'project_root' => $uploadsSeed['projectRoot'],
        'work_root' => $uploadsSeed['workRoot'],
        'job_id' => $uploadsSeed['jobId'],
        'admin_id' => 1,
        'env_override' => $uploadsSeed['env'],
    ]);
});
e2e_self_test(
    $uploadsErr !== null && str_contains($uploadsErr->getMessage(), 'password'),
    'resume: uploads cutover requires fresh re-auth'
);
$uploadsOk = orange_restore_e2e_resume_full($uploadsSeed['adminPdo'], [
    'project_root' => $uploadsSeed['projectRoot'],
    'work_root' => $uploadsSeed['workRoot'],
    'job_id' => $uploadsSeed['jobId'],
    'admin_id' => 1,
    'password' => 'correct-pass',
    'confirmation_phrase' => 'RESTORE',
    'env_override' => $uploadsSeed['env'],
    'uploads_cutover_override' => static fn (): array => [
        'ok' => true,
        'status' => ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE,
        'production_writes' => true,
    ],
]);
e2e_self_test(
    ($uploadsOk['phase_result']['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE,
    'resume: uploads checkpoint delegates to approved uploads cutover'
);
e2e_rmdir($uploadsSeed['backupRoot']);

$postSeed = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE);
$postErr = e2e_try(static function () use ($postSeed): void {
    orange_restore_e2e_resume_full($postSeed['adminPdo'], [
        'project_root' => $postSeed['projectRoot'],
        'work_root' => $postSeed['workRoot'],
        'job_id' => $postSeed['jobId'],
        'admin_id' => 1,
        'env_override' => $postSeed['env'],
    ]);
});
e2e_self_test(
    $postErr !== null && str_contains($postErr->getMessage(), 'password'),
    'resume: post-validation requires fresh re-auth'
);
$postOk = orange_restore_e2e_resume_full($postSeed['adminPdo'], [
    'project_root' => $postSeed['projectRoot'],
    'work_root' => $postSeed['workRoot'],
    'job_id' => $postSeed['jobId'],
    'admin_id' => 1,
    'password' => 'correct-pass',
    'confirmation_phrase' => 'RESTORE',
    'env_override' => $postSeed['env'],
    'post_validation_override' => static function () use ($postSeed): array {
        orange_restore_job_post_validation_transition(
            $postSeed['workRoot'],
            $postSeed['jobId'],
            ORANGE_RESTORE_JOB_STATUS_MERGED,
            ['production_merged_at' => gmdate('c')]
        );
        orange_restore_job_post_validation_transition(
            $postSeed['workRoot'],
            $postSeed['jobId'],
            ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED,
            ['post_validation_passed_at' => gmdate('c')]
        );
        orange_restore_job_post_validation_transition(
            $postSeed['workRoot'],
            $postSeed['jobId'],
            ORANGE_RESTORE_JOB_STATUS_COMPLETED,
            ['restore_completed_at' => gmdate('c'), 'result' => 'completed']
        );

        return [
            'ok' => true,
            'status' => ORANGE_RESTORE_JOB_STATUS_COMPLETED,
            'production_writes' => false,
        ];
    },
]);
e2e_self_test(
    ($postOk['phase_result']['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_COMPLETED,
    'resume: post-validation delegates to approved post-validation function'
);
$auditPost = orange_restore_audit_read_all($postSeed['workRoot'], $postSeed['jobId']);
$postEvents = array_column($auditPost, 'e2e_event');
e2e_self_test(in_array('e2e_completed', $postEvents, true), 'audit: e2e_completed recorded');
e2e_rmdir($postSeed['backupRoot']);

// --- production_merged resume post-validation ---
$mergedSeed = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_MERGED);
orange_restore_merge_maintenance_enable($mergedSeed['workRoot'], $mergedSeed['jobId']);
$mergedOk = orange_restore_e2e_resume_full($mergedSeed['adminPdo'], [
    'project_root' => $mergedSeed['projectRoot'],
    'work_root' => $mergedSeed['workRoot'],
    'job_id' => $mergedSeed['jobId'],
    'admin_id' => 1,
    'password' => 'correct-pass',
    'confirmation_phrase' => 'RESTORE',
    'env_override' => $mergedSeed['env'],
    'post_validation_override' => static fn (): array => [
        'ok' => true,
        'status' => ORANGE_RESTORE_JOB_STATUS_COMPLETED,
        'production_writes' => false,
    ],
]);
e2e_self_test(
    ($mergedOk['phase_result']['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_COMPLETED,
    'resume: production_merged resumes post-validation successfully'
);
e2e_rmdir($mergedSeed['backupRoot']);

// --- post_validation_passed finalize-only ---
$finalizeSeed = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED, [
    'post_validation_passed_at' => gmdate('c'),
]);
$reportPath = orange_restore_production_post_validation_report_path($finalizeSeed['workRoot'], $finalizeSeed['jobId']);
orange_backup_write_json($reportPath, [
    'generated_at' => gmdate('c'),
    'job_id' => $finalizeSeed['jobId'],
    'overall_result' => 'pass',
    'hard_failures' => [],
    'warnings' => [],
]);
$finalizeJob = orange_restore_job_read($finalizeSeed['workRoot'], $finalizeSeed['jobId']);
$finalizeJob['post_validation_report_path'] = $reportPath;
orange_restore_job_write($finalizeSeed['workRoot'], $finalizeJob);
orange_restore_merge_maintenance_enable($finalizeSeed['workRoot'], $finalizeSeed['jobId']);
$finalizeOk = orange_restore_e2e_resume_full($finalizeSeed['adminPdo'], [
    'project_root' => $finalizeSeed['projectRoot'],
    'work_root' => $finalizeSeed['workRoot'],
    'job_id' => $finalizeSeed['jobId'],
    'admin_id' => 1,
    'password' => 'correct-pass',
    'confirmation_phrase' => 'RESTORE',
    'env_override' => $finalizeSeed['env'],
    'post_validation_finalize_override' => static function () use ($finalizeSeed, $reportPath): array {
        orange_restore_merge_maintenance_verify($finalizeSeed['workRoot'], $finalizeSeed['jobId']);
        orange_restore_job_post_validation_transition(
            $finalizeSeed['workRoot'],
            $finalizeSeed['jobId'],
            ORANGE_RESTORE_JOB_STATUS_COMPLETED,
            ['restore_completed_at' => gmdate('c'), 'result' => 'completed']
        );

        return [
            'ok' => true,
            'status' => ORANGE_RESTORE_JOB_STATUS_COMPLETED,
            'production_writes' => false,
        ];
    },
]);
e2e_self_test(
    ($finalizeOk['phase_result']['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_COMPLETED,
    'resume: post_validation_passed finalizes to completed'
);
e2e_rmdir($finalizeSeed['backupRoot']);

// --- start idempotency ---
$pkgPath = e2e_temp_root() . DIRECTORY_SEPARATOR . 'pkg';
mkdir($pkgPath);
$pkgChecksum = str_repeat('d', 64);
$idempotentRoot = dirname($pkgPath);
$idempotentWork = $idempotentRoot . DIRECTORY_SEPARATOR . 'restore_work';
mkdir($idempotentWork);
$existingJob = orange_restore_job_create($idempotentWork, [
    'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
    'source_package_path' => $pkgPath,
    'source_package_checksum' => $pkgChecksum,
    'schema_revision' => 121,
]);
$existingJob['status'] = ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL;
orange_restore_job_write($idempotentWork, $existingJob);
$idempotentProject = $idempotentRoot . DIRECTORY_SEPARATOR . 'project';
mkdir($idempotentProject);
$existingStart = orange_restore_e2e_start_full(e2e_test_pdo(), [
    'project_root' => $idempotentProject,
    'package_path' => $pkgPath,
    'admin_id' => 1,
    'password' => 'correct-pass',
    'confirmation_phrase' => 'RESTORE',
    'env_override' => ['ORANGE_BACKUP_ROOT' => $idempotentRoot],
    'start_precheck_override' => static fn (): array => [
        'ok' => true,
        'verify' => ['manifest' => ['package_type' => ORANGE_RESTORE_JOB_TYPE_FULL]],
    ],
    'package_checksum_override' => $pkgChecksum,
]);
e2e_self_test(
    ($existingStart['resumed_existing'] ?? false) === true
    && ($existingStart['job_id'] ?? '') === (string) ($existingJob['job_id'] ?? ''),
    'start: repeated restore_run_full returns existing active job'
);
e2e_rmdir($idempotentRoot);

$dupRoot = e2e_temp_root();
$dupWork = $dupRoot . DIRECTORY_SEPARATOR . 'restore_work';
mkdir($dupWork);
$dupPkg = $dupRoot . DIRECTORY_SEPARATOR . 'pkg';
mkdir($dupPkg);
$dupChecksum = str_repeat('e', 64);
$dupOne = orange_restore_job_create($dupWork, [
    'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
    'source_package_path' => $dupPkg,
    'source_package_checksum' => $dupChecksum,
]);
$dupOne['status'] = ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL;
orange_restore_job_write($dupWork, $dupOne);
$dupTwo = orange_restore_job_create($dupWork, [
    'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
    'source_package_path' => $dupPkg,
    'source_package_checksum' => $dupChecksum,
]);
$dupTwo['status'] = ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED;
orange_restore_job_write($dupWork, $dupTwo);
$dupMatches = orange_restore_job_find_active_full_by_package($dupWork, $dupPkg, $dupChecksum);
$dupErr = e2e_try(static function () use ($dupMatches): void {
    if (count($dupMatches) > 1) {
        throw new RuntimeException('Multiple active full restore jobs match this package. Resolve manually before starting a new restore.');
    }
});
e2e_self_test(
    count($dupMatches) === 2 && $dupErr !== null,
    'start: two matching active jobs fail closed'
);
$terminalRoot = e2e_temp_root();
$terminalWork = $terminalRoot . DIRECTORY_SEPARATOR . 'restore_work';
mkdir($terminalWork);
$terminalPkg = $terminalRoot . DIRECTORY_SEPARATOR . 'pkg';
mkdir($terminalPkg);
$terminalChecksum = str_repeat('f', 64);
$terminalJob = orange_restore_job_create($terminalWork, [
    'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
    'source_package_path' => $terminalPkg,
    'source_package_checksum' => $terminalChecksum,
]);
$terminalJob['status'] = ORANGE_RESTORE_JOB_STATUS_COMPLETED;
orange_restore_job_write($terminalWork, $terminalJob);
$terminalMatches = orange_restore_job_find_active_full_by_package($terminalWork, $terminalPkg, $terminalChecksum);
e2e_self_test(count($terminalMatches) === 0, 'start: terminal previous job allows a new restore job');
e2e_rmdir($dupRoot);
e2e_rmdir($terminalRoot);

// --- staging_validated delegates to 2B.1 ---
$stagingSeed = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_STAGING_VALIDATED, [
    'staging_restore_manifest_path' => '',
    'fresh_backup_path' => '/tmp/fresh',
    'fresh_backup_checksum' => str_repeat('1', 64),
]);
$stagingManifestPath = orange_restore_job_staging_manifest_path($stagingSeed['workRoot'], $stagingSeed['jobId']);
orange_backup_write_json($stagingManifestPath, [
    'generated_at' => gmdate('c'),
    'job_id' => $stagingSeed['jobId'],
    'overall_result' => 'pass',
]);
$stagingReportPath = orange_restore_job_report_path($stagingSeed['workRoot'], $stagingSeed['jobId']);
orange_backup_write_json($stagingReportPath, [
    'generated_at' => gmdate('c'),
    'job_id' => $stagingSeed['jobId'],
    'overall_result' => 'pass',
    'production_touched' => false,
    'staging_post_validation' => ['ok' => true],
    'staging_drv_report' => ['overall_result' => 'pass'],
]);
$stagingJob = orange_restore_job_read($stagingSeed['workRoot'], $stagingSeed['jobId']);
$stagingJob['staging_restore_manifest_path'] = $stagingManifestPath;
$stagingJob['restore_report_path'] = $stagingReportPath;
orange_restore_job_write($stagingSeed['workRoot'], $stagingJob);
$stagingResume = orange_restore_e2e_resume_full($stagingSeed['adminPdo'], [
    'project_root' => $stagingSeed['projectRoot'],
    'work_root' => $stagingSeed['workRoot'],
    'job_id' => $stagingSeed['jobId'],
    'admin_id' => 1,
    'env_override' => $stagingSeed['env'],
]);
e2e_self_test(
    ($stagingResume['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL,
    'resume: staging_validated uses Phase 2B.1 finalizer delegation'
);
e2e_rmdir($stagingSeed['backupRoot']);

// --- audit idempotency ---
$terminalAudit = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_COMPLETED);
orange_restore_e2e_resume_full($terminalAudit['adminPdo'], [
    'project_root' => $terminalAudit['projectRoot'],
    'work_root' => $terminalAudit['workRoot'],
    'job_id' => $terminalAudit['jobId'],
    'admin_id' => 1,
    'env_override' => $terminalAudit['env'],
]);
orange_restore_e2e_resume_full($terminalAudit['adminPdo'], [
    'project_root' => $terminalAudit['projectRoot'],
    'work_root' => $terminalAudit['workRoot'],
    'job_id' => $terminalAudit['jobId'],
    'admin_id' => 1,
    'env_override' => $terminalAudit['env'],
]);
$terminalEvents = array_filter(
    array_column(orange_restore_audit_read_all($terminalAudit['workRoot'], $terminalAudit['jobId']), 'e2e_event'),
    static fn ($event): bool => $event === 'e2e_terminal'
);
e2e_self_test(count($terminalEvents) === 1, 'audit: terminal resume does not duplicate terminal audit');
$completedAudit = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE);
orange_restore_merge_maintenance_enable($completedAudit['workRoot'], $completedAudit['jobId']);
orange_restore_e2e_resume_full($completedAudit['adminPdo'], [
    'project_root' => $completedAudit['projectRoot'],
    'work_root' => $completedAudit['workRoot'],
    'job_id' => $completedAudit['jobId'],
    'admin_id' => 1,
    'password' => 'correct-pass',
    'confirmation_phrase' => 'RESTORE',
    'env_override' => $completedAudit['env'],
    'post_validation_override' => static function () use ($completedAudit): array {
        orange_restore_job_post_validation_transition(
            $completedAudit['workRoot'],
            $completedAudit['jobId'],
            ORANGE_RESTORE_JOB_STATUS_MERGED,
            ['production_merged_at' => gmdate('c')]
        );
        orange_restore_job_post_validation_transition(
            $completedAudit['workRoot'],
            $completedAudit['jobId'],
            ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED,
            ['post_validation_passed_at' => gmdate('c')]
        );
        orange_restore_job_post_validation_transition(
            $completedAudit['workRoot'],
            $completedAudit['jobId'],
            ORANGE_RESTORE_JOB_STATUS_COMPLETED,
            ['restore_completed_at' => gmdate('c')]
        );

        return ['ok' => true, 'status' => ORANGE_RESTORE_JOB_STATUS_COMPLETED];
    },
]);
$completedEvents = array_filter(
    array_column(orange_restore_audit_read_all($completedAudit['workRoot'], $completedAudit['jobId']), 'e2e_event'),
    static fn ($event): bool => $event === 'e2e_completed'
);
e2e_self_test(count($completedEvents) === 1, 'audit: e2e_completed emitted once');
e2e_rmdir($completedAudit['backupRoot']);
e2e_rmdir($terminalAudit['backupRoot']);

// --- rollback resume ---
$rbSeed = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS, [
    'rollback_checkpoint' => ORANGE_RESTORE_ROLLBACK_CHECKPOINT_DATABASE_COMPLETE,
]);
$rbOk = orange_restore_e2e_resume_full($rbSeed['adminPdo'], [
    'project_root' => $rbSeed['projectRoot'],
    'work_root' => $rbSeed['workRoot'],
    'job_id' => $rbSeed['jobId'],
    'admin_id' => 1,
    'password' => 'correct-pass',
    'confirmation_phrase' => 'ROLLBACK',
    'env_override' => $rbSeed['env'],
    'rollback_override' => static fn (): array => [
        'ok' => true,
        'status' => ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK,
        'automatic_rollback' => false,
    ],
]);
e2e_self_test(
    ($rbOk['phase_result']['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK
    && ($rbOk['automatic_rollback'] ?? true) === false,
    'resume: rollback checkpoint delegates without automatic rollback'
);
e2e_rmdir($rbSeed['backupRoot']);

// --- status redaction ---
$statusSeed = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL, [
    'approval_token_hash' => str_repeat('f', 64),
]);
$statusReport = orange_restore_e2e_status_full($statusSeed['projectRoot'], $statusSeed['jobId'], [
    'work_root' => $statusSeed['workRoot'],
    'env_override' => $statusSeed['env'],
]);
$encoded = json_encode($statusReport, JSON_UNESCAPED_UNICODE);
e2e_self_test(
    !str_contains($encoded, 'password_hash')
    && !str_contains($encoded, 'approval_token_plaintext')
    && !preg_match('/--password=(?!\\[REDACTED\\])\\S+/', $encoded)
    && isset($statusReport['e2e']['next_action']),
    'status: output redaction and next action present'
);
e2e_rmdir($statusSeed['backupRoot']);

// --- orchestrator file must not duplicate phase SQL/import logic ---
$e2eSource = (string) file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_e2e_orchestrator.php');
e2e_self_test(
    !str_contains($e2eSource, 'orange_restore_sql_runner_import_gzip')
    && !str_contains($e2eSource, 'orange_restore_staging_wipe')
    && !str_contains($e2eSource, 'orange_restore_orchestrator_approve_for_merge')
    && !str_contains($e2eSource, 'orange_restore_validation_adapter_staging_gate')
    && !str_contains($e2eSource, 'function orange_restore_e2e_finalize_staging_approval')
    && str_contains($e2eSource, 'orange_restore_full_staging_finalize_approval'),
    'design: e2e orchestrator delegates staging finalization without duplicated gate logic'
);

echo PHP_EOL;
if ($failures === 0) {
    echo "ALL Phase 2E end-to-end orchestrator self-tests PASSED.\n";
    exit(0);
}

echo "FAILED: {$failures} test(s).\n";
exit(1);
