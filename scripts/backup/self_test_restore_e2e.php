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
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_paths.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_job.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_lock.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_audit.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_approval.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_reauth.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_maintenance.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_post_validation.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_uploads_cutover.php';
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

/**
 * @return array<string, mixed>
 */
function e2e_finalize_install_bindings(array $seed): array
{
    $backupRoot = $seed['backupRoot'];
    $workRoot = $seed['workRoot'];
    $jobId = $seed['jobId'];

    $packageDir = $backupRoot . DIRECTORY_SEPARATOR . 'pkg_' . bin2hex(random_bytes(2));
    mkdir($packageDir);
    orange_backup_write_json($packageDir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
        'dump_sha256' => str_repeat('c', 64),
    ]);
    orange_backup_write_checksums($packageDir, ['manifest.json']);
    $packageChecksum = orange_backup_sha256_file($packageDir . DIRECTORY_SEPARATOR . 'checksums.sha256');

    $anchorDir = $backupRoot . DIRECTORY_SEPARATOR . 'anchor_' . bin2hex(random_bytes(2));
    mkdir($anchorDir);
    file_put_contents($anchorDir . DIRECTORY_SEPARATOR . 'dump.sql.gz', 'fake-dump');
    orange_backup_write_json($anchorDir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
        'dump_sha256' => str_repeat('d', 64),
    ]);
    orange_backup_write_checksums($anchorDir, ['manifest.json', 'dump.sql.gz']);
    $anchorChecksum = orange_backup_sha256_file($anchorDir . DIRECTORY_SEPARATOR . 'checksums.sha256');

    $stagingManifestPath = orange_restore_job_staging_manifest_path($workRoot, $jobId);
    orange_backup_write_json($stagingManifestPath, [
        'generated_at' => gmdate('c'),
        'job_id' => $jobId,
        'overall_result' => 'pass',
    ]);
    $manifestChecksum = orange_backup_sha256_file($stagingManifestPath);

    $restoreReportPath = orange_restore_job_report_path($workRoot, $jobId);
    orange_backup_write_json($restoreReportPath, [
        'generated_at' => gmdate('c'),
        'job_id' => $jobId,
        'overall_result' => 'pass',
        'production_touched' => false,
        'staging_post_validation' => ['ok' => true],
        'staging_drv_report' => ['overall_result' => 'pass'],
    ]);

    $job = orange_restore_job_read($workRoot, $jobId);
    $job['source_package_path'] = $packageDir;
    $job['source_package_checksum'] = $packageChecksum;
    $job['fresh_backup_path'] = $anchorDir;
    $job['fresh_backup_checksum'] = $anchorChecksum;
    $job['rollback_anchor_job_only'] = true;
    $job['schema_revision'] = 121;
    $job['merge_precheck_production_db'] = 'orange_db';
    $job['staging_restore_manifest_path'] = $stagingManifestPath;
    $job['restore_report_path'] = $restoreReportPath;
    $job['approval_token_binding'] = [
        'source_package_checksum' => $packageChecksum,
        'staging_restore_manifest_checksum' => $manifestChecksum,
        'rollback_anchor_checksum' => $anchorChecksum,
    ];
    orange_restore_job_write($workRoot, $job);

    $reportPath = orange_restore_production_post_validation_report_path($workRoot, $jobId);
    orange_backup_write_json($reportPath, [
        'generated_at' => gmdate('c'),
        'job_id' => $jobId,
        'overall_result' => 'pass',
        'hard_failures' => [],
        'warnings' => [],
        'schema_revision' => 121,
        'production_db_identity' => 'orange_db',
        'package_identity' => [
            'source_package_path' => $packageDir,
            'source_package_checksum' => $packageChecksum,
            'package_version' => '',
        ],
        'rollback_anchor_identity' => [
            'fresh_backup_path' => $anchorDir,
            'fresh_backup_checksum' => $anchorChecksum,
            'rollback_anchor_job_only' => true,
        ],
    ]);
    $job = orange_restore_job_read($workRoot, $jobId);
    $job['post_validation_report_path'] = $reportPath;
    orange_restore_job_write($workRoot, $job);
    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_post_validation_event($job, 'production_post_validation_passed', 'pass', [
        'operator_admin_id' => 1,
        'database_writes' => false,
        'production_writes' => false,
    ]));

    $seed['job'] = orange_restore_job_read($workRoot, $jobId);
    $seed['reportPath'] = $reportPath;
    $seed['packageChecksum'] = $packageChecksum;
    $seed['manifestChecksum'] = $manifestChecksum;
    $seed['anchorChecksum'] = $anchorChecksum;

    return $seed;
}

/**
 * @return array<string, mixed>
 */
function e2e_seed_finalize_checkpoint(string $status, array $extra = []): array
{
    $seed = e2e_seed_job($status, array_merge([
        'post_validation_passed_at' => gmdate('c'),
        'fresh_backup_path' => '/tmp/fresh-backup',
        'fresh_backup_checksum' => str_repeat('9', 64),
        'rollback_anchor_job_only' => true,
    ], $extra));
    $seed = e2e_finalize_install_bindings($seed);
    $job = orange_restore_job_read($seed['workRoot'], $seed['jobId']);
    foreach ($extra as $key => $value) {
        $job[(string) $key] = $value;
    }
    $job['status'] = $status;
    orange_restore_job_write($seed['workRoot'], $job);
    if ($status === ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLE_PENDING) {
        orange_restore_audit_append($seed['workRoot'], $seed['jobId'], orange_restore_audit_post_validation_event($job, 'maintenance_disable_pending', 'checkpoint', [
            'operator_admin_id' => 1,
            'database_writes' => false,
            'production_writes' => false,
        ]));
    }
    if ($status === ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLED) {
        orange_restore_audit_append($seed['workRoot'], $seed['jobId'], orange_restore_audit_post_validation_event($job, 'maintenance_disable_pending', 'checkpoint', [
            'operator_admin_id' => 1,
            'database_writes' => false,
            'production_writes' => false,
        ]));
        orange_restore_audit_append($seed['workRoot'], $seed['jobId'], orange_restore_audit_post_validation_event($job, 'maintenance_disabled_checkpoint', 'checkpoint', [
            'operator_admin_id' => 1,
            'database_writes' => false,
            'production_writes' => false,
        ]));
    }
    $seed['job'] = orange_restore_job_read($seed['workRoot'], $seed['jobId']);

    return $seed;
}

/**
 * @param array<string, mixed> $extraOptions
 * @return array<string, mixed>
 */
function e2e_run_finalize(array $seed, array $extraOptions = []): array
{
    orange_restore_acquire_lock($seed['workRoot'], $seed['jobId']);

    return orange_restore_merge_post_validation_finalize_run(array_merge([
        'project_root' => $seed['projectRoot'],
        'work_root' => $seed['workRoot'],
        'job_id' => $seed['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'RESTORE',
        'admin_pdo_override' => $seed['adminPdo'],
        'env_override' => $seed['env'],
    ], $extraOptions));
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

$pendingRoute = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLE_PENDING);
$pendingRouteAction = orange_restore_e2e_resolve_action($pendingRoute['job']);
e2e_self_test(
    ($pendingRouteAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_RUN_POST_VALIDATION_FINALIZE,
    'routing: maintenance_disable_pending resumes finalize'
);
e2e_rmdir($pendingRoute['backupRoot']);

$disabledRoute = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLED);
$disabledRouteAction = orange_restore_e2e_resolve_action($disabledRoute['job']);
e2e_self_test(
    ($disabledRouteAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_RUN_POST_VALIDATION_FINALIZE,
    'routing: maintenance_disabled resumes finalize'
);
e2e_rmdir($disabledRoute['backupRoot']);

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

$completed = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_COMPLETED, [
    'restore_completed_at' => gmdate('c'),
]);
$completedAction = orange_restore_e2e_resolve_action($completed['job'], $completed['workRoot']);
e2e_self_test(
    ($completedAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_RECONCILE_COMPLETED
    && ($completedAction['requires_reauth'] ?? false) === true,
    'routing: completed with missing artifacts requires reconcile_completed'
);
e2e_rmdir($completed['backupRoot']);

$completedReconciled = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_COMPLETED, [
    'restore_completed_at' => gmdate('c'),
]);
e2e_run_finalize($completedReconciled);
$completedReconciledJob = orange_restore_job_read($completedReconciled['workRoot'], $completedReconciled['jobId']);
$completedReconciledAction = orange_restore_e2e_resolve_action($completedReconciledJob, $completedReconciled['workRoot']);
e2e_self_test(
    ($completedReconciledAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_TERMINAL,
    'routing: completed with complete artifacts is terminal'
);
e2e_rmdir($completedReconciled['backupRoot']);

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

$legacyMerge = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL);
$legacyPath = orange_restore_job_file_path($legacyMerge['workRoot'], $legacyMerge['jobId']);
$legacyRaw = json_decode((string) file_get_contents($legacyPath), true);
if (is_array($legacyRaw)) {
    $legacyRaw['status'] = ORANGE_RESTORE_JOB_STATUS_MERGE_APPROVED;
    file_put_contents($legacyPath, json_encode($legacyRaw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
}
$legacyJob = orange_restore_job_read($legacyMerge['workRoot'], $legacyMerge['jobId']);
$legacyAction = orange_restore_e2e_resolve_action($legacyJob, $legacyMerge['workRoot']);
e2e_self_test(
    ($legacyJob['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE
    && ($legacyAction['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_STOP_MERGE_CONFIRMATION,
    'routing: legacy merge_approved normalizes to approved_for_merge stop'
);
$legacyAudit = array_column(orange_restore_audit_read_all($legacyMerge['workRoot'], $legacyMerge['jobId']), 'job_event');
e2e_self_test(
    in_array('legacy_merge_approved_normalized', $legacyAudit, true),
    'routing: merge_approved normalization audited once'
);
e2e_rmdir($legacyMerge['backupRoot']);

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
            ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLE_PENDING,
            ['maintenance_disable_pending_at' => gmdate('c')]
        );
        orange_restore_job_post_validation_transition(
            $postSeed['workRoot'],
            $postSeed['jobId'],
            ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLED,
            ['maintenance_disabled_at' => gmdate('c')]
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
            ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLE_PENDING,
            ['maintenance_disable_pending_at' => gmdate('c')]
        );
        orange_restore_job_post_validation_transition(
            $finalizeSeed['workRoot'],
            $finalizeSeed['jobId'],
            ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLED,
            ['maintenance_disabled_at' => gmdate('c')]
        );
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

// --- finalize checkpoint crash recovery ---
$crashPending = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLE_PENDING);
orange_restore_merge_maintenance_enable($crashPending['workRoot'], $crashPending['jobId']);
$crashPendingResult = e2e_run_finalize($crashPending);
e2e_self_test(
    ($crashPendingResult['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_COMPLETED,
    'checkpoint: crash after pending before disable resumes to completed'
);
e2e_rmdir($crashPending['backupRoot']);

$crashDisabled = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLE_PENDING, [
    'maintenance_disable_pending_at' => gmdate('c'),
]);
$crashDisabledJob = orange_restore_job_read($crashDisabled['workRoot'], $crashDisabled['jobId']);
orange_restore_job_write($crashDisabled['workRoot'], $crashDisabledJob);
orange_restore_merge_maintenance_enable($crashDisabled['workRoot'], $crashDisabled['jobId']);
orange_restore_merge_maintenance_disable($crashDisabled['workRoot'], $crashDisabled['jobId'], ['reason' => 'test_crash']);
$crashDisabledResult = e2e_run_finalize($crashDisabled);
e2e_self_test(
    ($crashDisabledResult['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_COMPLETED,
    'checkpoint: crash after disable before completed resumes to completed'
);
e2e_rmdir($crashDisabled['backupRoot']);

$resumeDisabled = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLED, [
    'maintenance_disable_pending_at' => gmdate('c'),
    'maintenance_disabled_at' => gmdate('c'),
]);
$resumeDisabledResult = e2e_run_finalize($resumeDisabled);
e2e_self_test(
    ($resumeDisabledResult['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_COMPLETED,
    'checkpoint: resume from maintenance_disabled completes without re-disable'
);
e2e_rmdir($resumeDisabled['backupRoot']);

$repeatFinalize = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED);
orange_restore_merge_maintenance_enable($repeatFinalize['workRoot'], $repeatFinalize['jobId']);
$repeatFirst = e2e_run_finalize($repeatFinalize);
$repeatSecond = e2e_run_finalize($repeatFinalize);
e2e_self_test(
    ($repeatFirst['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_COMPLETED
    && ($repeatSecond['idempotent'] ?? false) === true,
    'checkpoint: repeated finalize is idempotent'
);
e2e_rmdir($repeatFinalize['backupRoot']);

$maintenanceOff = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED);
$maintenanceOffResult = e2e_run_finalize($maintenanceOff);
e2e_self_test(
    ($maintenanceOffResult['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_COMPLETED,
    'checkpoint: maintenance already off still completes'
);
e2e_rmdir($maintenanceOff['backupRoot']);

$auditOnce = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED);
orange_restore_merge_maintenance_enable($auditOnce['workRoot'], $auditOnce['jobId']);
e2e_run_finalize($auditOnce);
e2e_run_finalize($auditOnce);
$auditEvents = array_column(orange_restore_audit_read_all($auditOnce['workRoot'], $auditOnce['jobId']), 'post_validation_event');
$restoreCompletedCount = count(array_filter($auditEvents, static fn ($event): bool => $event === 'restore_completed'));
$maintenanceDisabledCount = count(array_filter($auditEvents, static fn ($event): bool => $event === 'maintenance_disabled'));
e2e_self_test(
    $restoreCompletedCount === 1 && $maintenanceDisabledCount === 1,
    'checkpoint: restore_completed and maintenance_disabled emitted once'
);
e2e_rmdir($auditOnce['backupRoot']);

// --- completed reconciliation + binding verification ---
$crashCompleted = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_COMPLETED, [
    'restore_completed_at' => gmdate('c'),
]);
$crashCompletedResult = e2e_run_finalize($crashCompleted);
$crashCompletedEvents = array_column(
    orange_restore_audit_read_all($crashCompleted['workRoot'], $crashCompleted['jobId']),
    'post_validation_event'
);
e2e_self_test(
    ($crashCompletedResult['reconciled'] ?? false) === true
    && in_array('restore_completed', $crashCompletedEvents, true),
    'reconcile: crash after completed state backfills restore_completed audit'
);
e2e_rmdir($crashCompleted['backupRoot']);

$missingFinalFields = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_COMPLETED, [
    'restore_completed_at' => gmdate('c'),
    'maintenance_disable_pending_at' => gmdate('c'),
    'maintenance_disabled_at' => gmdate('c'),
]);
orange_backup_write_json(orange_restore_final_restore_report_path($missingFinalFields['workRoot'], $missingFinalFields['jobId']), [
    'job_id' => $missingFinalFields['jobId'],
    'overall_result' => 'pass',
]);
$missingFinalResult = e2e_run_finalize($missingFinalFields);
$missingFinalRaw = file_get_contents(orange_restore_final_restore_report_path($missingFinalFields['workRoot'], $missingFinalFields['jobId']));
$missingFinalDecoded = is_string($missingFinalRaw) ? json_decode($missingFinalRaw, true) : null;
e2e_self_test(
    ($missingFinalResult['reconciled'] ?? false) === true
    && is_array($missingFinalDecoded)
    && (string) ($missingFinalDecoded['job_status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_COMPLETED,
    'reconcile: missing final_restore_report completion fields are backfilled'
);
e2e_rmdir($missingFinalFields['backupRoot']);

$reconcileBackfill = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_COMPLETED, [
    'restore_completed_at' => gmdate('c'),
]);
$reconcileBackfillResult = e2e_run_finalize($reconcileBackfill);
e2e_self_test(
    ($reconcileBackfillResult['reconciled'] ?? false) === true
    && is_file(orange_restore_final_restore_report_path($reconcileBackfill['workRoot'], $reconcileBackfill['jobId'])),
    'reconcile: completed job backfills missing report and audit'
);
e2e_rmdir($reconcileBackfill['backupRoot']);

$noValidationRerun = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_COMPLETED, [
    'restore_completed_at' => gmdate('c'),
]);
$noValidationRerunResult = e2e_run_finalize($noValidationRerun);
e2e_self_test(
    ($noValidationRerunResult['production_writes'] ?? true) === false
    && ($noValidationRerunResult['database_writes'] ?? true) === false,
    'reconcile: completed reconciliation does not rerun validation'
);
e2e_rmdir($noValidationRerun['backupRoot']);

$noDisableOnReconcile = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_COMPLETED, [
    'restore_completed_at' => gmdate('c'),
]);
$noDisableResult = e2e_run_finalize($noDisableOnReconcile);
$maintAfterReconcile = orange_restore_merge_maintenance_status($noDisableOnReconcile['workRoot']);
e2e_self_test(
    ($noDisableResult['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_COMPLETED
    && ($maintAfterReconcile['active'] ?? true) === false,
    'reconcile: completed reconciliation does not disable maintenance'
);
e2e_rmdir($noDisableOnReconcile['backupRoot']);

$reconcileNoDup = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_COMPLETED, [
    'restore_completed_at' => gmdate('c'),
]);
e2e_run_finalize($reconcileNoDup);
e2e_run_finalize($reconcileNoDup);
$reconcileEvents = array_column(
    orange_restore_audit_read_all($reconcileNoDup['workRoot'], $reconcileNoDup['jobId']),
    'post_validation_event'
);
$reconcileCompletedCount = count(array_filter($reconcileEvents, static fn ($event): bool => $event === 'restore_completed'));
$reconcileReconciledCount = count(array_filter($reconcileEvents, static fn ($event): bool => $event === 'restore_completion_reconciled'));
e2e_self_test(
    $reconcileCompletedCount === 1 && $reconcileReconciledCount === 1,
    'reconcile: completed reconciliation emits no duplicate completion events'
);
e2e_rmdir($reconcileNoDup['backupRoot']);

$reconcilePersistFail = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_COMPLETED, [
    'restore_completed_at' => gmdate('c'),
]);
$reconcilePersistErr = e2e_try(static function () use ($reconcilePersistFail): void {
    e2e_run_finalize($reconcilePersistFail, [
        'final_report_write_override' => static function (): void {
            throw new RuntimeException('Simulated final report write failure.');
        },
    ]);
});
$emergencyPath = orange_restore_post_validation_emergency_failure_log_path($reconcilePersistFail['workRoot'], $reconcilePersistFail['jobId']);
e2e_self_test(
    $reconcilePersistErr !== null
    && str_contains($reconcilePersistErr->getMessage(), 'reconciliation')
    && is_file($emergencyPath),
    'reconcile: persistence failure is visible and emergency-logged'
);
e2e_rmdir($reconcilePersistFail['backupRoot']);

$pkgMismatch = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED);
$pkgMismatchJob = orange_restore_job_read($pkgMismatch['workRoot'], $pkgMismatch['jobId']);
$pkgMismatchJob['source_package_checksum'] = str_repeat('0', 64);
orange_restore_job_write($pkgMismatch['workRoot'], $pkgMismatchJob);
$pkgMismatchErr = e2e_try(static fn (): array => e2e_run_finalize($pkgMismatch));
e2e_self_test(
    $pkgMismatchErr !== null && str_contains($pkgMismatchErr->getMessage(), 'checksum'),
    'binding: package checksum mismatch fails closed'
);
e2e_rmdir($pkgMismatch['backupRoot']);

$stagingMismatch = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED);
$stagingMismatchJob = orange_restore_job_read($stagingMismatch['workRoot'], $stagingMismatch['jobId']);
$binding = is_array($stagingMismatchJob['approval_token_binding'] ?? null) ? $stagingMismatchJob['approval_token_binding'] : [];
$binding['staging_restore_manifest_checksum'] = str_repeat('0', 64);
$stagingMismatchJob['approval_token_binding'] = $binding;
orange_restore_job_write($stagingMismatch['workRoot'], $stagingMismatchJob);
$stagingMismatchErr = e2e_try(static fn (): array => e2e_run_finalize($stagingMismatch));
e2e_self_test(
    $stagingMismatchErr !== null && str_contains($stagingMismatchErr->getMessage(), 'Staging manifest checksum mismatch'),
    'binding: staging manifest checksum mismatch fails closed'
);
e2e_rmdir($stagingMismatch['backupRoot']);

$approvalMismatch = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED);
$approvalMismatchJob = orange_restore_job_read($approvalMismatch['workRoot'], $approvalMismatch['jobId']);
$approvalMismatchJob['approval_token_binding'] = [
    'source_package_checksum' => str_repeat('0', 64),
    'staging_restore_manifest_checksum' => (string) ($approvalMismatch['manifestChecksum'] ?? ''),
    'rollback_anchor_checksum' => (string) ($approvalMismatch['anchorChecksum'] ?? ''),
];
orange_restore_job_write($approvalMismatch['workRoot'], $approvalMismatchJob);
$approvalMismatchErr = e2e_try(static fn (): array => e2e_run_finalize($approvalMismatch));
e2e_self_test(
    $approvalMismatchErr !== null && str_contains($approvalMismatchErr->getMessage(), 'differs from approval binding'),
    'binding: approval binding mismatch fails closed'
);
e2e_rmdir($approvalMismatch['backupRoot']);

$anchorDrift = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED);
$anchorPath = (string) ($anchorDrift['job']['fresh_backup_path'] ?? '');
file_put_contents($anchorPath . DIRECTORY_SEPARATOR . 'checksums.sha256', str_repeat('f', 64) . "  manifest.json\n");
$anchorDriftErr = e2e_try(static fn (): array => e2e_run_finalize($anchorDrift));
e2e_self_test(
    $anchorDriftErr !== null && str_contains($anchorDriftErr->getMessage(), 'Rollback anchor checksum mismatch'),
    'binding: live rollback anchor checksum drift fails closed'
);
e2e_rmdir($anchorDrift['backupRoot']);

$wrongJobReport = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED);
$wrongJobReportPath = (string) ($wrongJobReport['reportPath'] ?? '');
$wrongJobReportRaw = file_get_contents($wrongJobReportPath);
if (is_string($wrongJobReportRaw)) {
    orange_backup_write_json($wrongJobReportPath, array_merge(
        json_decode($wrongJobReportRaw, true) ?: [],
        ['job_id' => 'other-job-id']
    ));
}
$wrongJobReportErr = e2e_try(static fn (): array => e2e_run_finalize($wrongJobReport));
e2e_self_test(
    $wrongJobReportErr !== null && str_contains($wrongJobReportErr->getMessage(), 'job_id'),
    'binding: pass report for another job fails closed'
);
e2e_rmdir($wrongJobReport['backupRoot']);

$dbMismatch = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED);
$dbMismatchPath = (string) ($dbMismatch['reportPath'] ?? '');
$dbMismatchRaw = file_get_contents($dbMismatchPath);
if (is_string($dbMismatchRaw)) {
    orange_backup_write_json($dbMismatchPath, array_merge(
        json_decode($dbMismatchRaw, true) ?: [],
        ['production_db_identity' => 'other_db']
    ));
}
$dbMismatchErr = e2e_try(static fn (): array => e2e_run_finalize($dbMismatch));
e2e_self_test(
    $dbMismatchErr !== null && str_contains($dbMismatchErr->getMessage(), 'production_db_identity'),
    'binding: production DB identity mismatch fails closed'
);
e2e_rmdir($dbMismatch['backupRoot']);

$disabledMaintOn = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLED, [
    'maintenance_disable_pending_at' => gmdate('c'),
    'maintenance_disabled_at' => gmdate('c'),
]);
orange_restore_merge_maintenance_enable($disabledMaintOn['workRoot'], $disabledMaintOn['jobId']);
$disabledMaintOnErr = e2e_try(static fn (): array => e2e_run_finalize($disabledMaintOn));
e2e_self_test(
    $disabledMaintOnErr !== null
    && str_contains($disabledMaintOnErr->getMessage(), 'requires maintenance OFF'),
    'checkpoint: maintenance_disabled with maintenance ON fails closed without disable'
);
e2e_rmdir($disabledMaintOn['backupRoot']);

$completedMaintOn = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_COMPLETED, [
    'restore_completed_at' => gmdate('c'),
]);
orange_restore_merge_maintenance_enable($completedMaintOn['workRoot'], $completedMaintOn['jobId']);
$completedMaintOnErr = e2e_try(static fn (): array => e2e_run_finalize($completedMaintOn));
e2e_self_test(
    $completedMaintOnErr !== null
    && str_contains($completedMaintOnErr->getMessage(), 'requires maintenance OFF'),
    'reconcile: completed with maintenance ON fails closed'
);
e2e_rmdir($completedMaintOn['backupRoot']);

// --- E2E completed reconciliation via primary resume CLI ---
$e2eReconcileSeed = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_COMPLETED, [
    'restore_completed_at' => gmdate('c'),
]);
$e2eReconcileErr = e2e_try(static function () use ($e2eReconcileSeed): void {
    orange_restore_e2e_resume_full($e2eReconcileSeed['adminPdo'], [
        'project_root' => $e2eReconcileSeed['projectRoot'],
        'work_root' => $e2eReconcileSeed['workRoot'],
        'job_id' => $e2eReconcileSeed['jobId'],
        'admin_id' => 1,
        'env_override' => $e2eReconcileSeed['env'],
    ]);
});
e2e_self_test(
    $e2eReconcileErr !== null && str_contains($e2eReconcileErr->getMessage(), 'password'),
    'e2e reconcile: completed reconciliation requires fresh re-auth'
);
$e2eReconcileOk = orange_restore_e2e_resume_full($e2eReconcileSeed['adminPdo'], [
    'project_root' => $e2eReconcileSeed['projectRoot'],
    'work_root' => $e2eReconcileSeed['workRoot'],
    'job_id' => $e2eReconcileSeed['jobId'],
    'admin_id' => 1,
    'password' => 'correct-pass',
    'confirmation_phrase' => 'RESTORE',
    'env_override' => $e2eReconcileSeed['env'],
]);
e2e_self_test(
    ($e2eReconcileOk['terminal'] ?? false) === true
    && ($e2eReconcileOk['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_COMPLETED,
    'e2e reconcile: completed job reconciles through primary resume CLI'
);
$e2eReconcileAgain = orange_restore_e2e_resume_full($e2eReconcileSeed['adminPdo'], [
    'project_root' => $e2eReconcileSeed['projectRoot'],
    'work_root' => $e2eReconcileSeed['workRoot'],
    'job_id' => $e2eReconcileSeed['jobId'],
    'admin_id' => 1,
    'env_override' => $e2eReconcileSeed['env'],
]);
e2e_self_test(
    ($e2eReconcileAgain['terminal'] ?? false) === true
    && ($e2eReconcileAgain['action']['action'] ?? '') === ORANGE_RESTORE_E2E_ACTION_TERMINAL,
    'e2e reconcile: repeated completed resume is idempotent terminal'
);
e2e_rmdir($e2eReconcileSeed['backupRoot']);

// --- direct production-mutating CLIs enforce re-auth ---
$directUploads = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE);
orange_restore_acquire_lock($directUploads['workRoot'], $directUploads['jobId']);
orange_restore_merge_maintenance_enable($directUploads['workRoot'], $directUploads['jobId']);
$directUploadsErr = e2e_try(static function () use ($directUploads): void {
    orange_restore_merge_uploads_cutover_run([
        'project_root' => $directUploads['projectRoot'],
        'work_root' => $directUploads['workRoot'],
        'job_id' => $directUploads['jobId'],
        'admin_id' => 1,
        'env_override' => $directUploads['env'],
    ]);
});
e2e_self_test(
    $directUploadsErr !== null && str_contains($directUploadsErr->getMessage(), 'password'),
    'security: direct uploads cutover rejects missing password before mutation'
);
orange_restore_release_lock($directUploads['workRoot']);
e2e_rmdir($directUploads['backupRoot']);

$directPost = e2e_seed_job(ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE);
orange_restore_acquire_lock($directPost['workRoot'], $directPost['jobId']);
orange_restore_merge_maintenance_enable($directPost['workRoot'], $directPost['jobId']);
$directPostErr = e2e_try(static function () use ($directPost): void {
    orange_restore_merge_post_validation_run([
        'project_root' => $directPost['projectRoot'],
        'work_root' => $directPost['workRoot'],
        'job_id' => $directPost['jobId'],
        'admin_id' => 1,
        'env_override' => $directPost['env'],
    ]);
});
e2e_self_test(
    $directPostErr !== null && str_contains($directPostErr->getMessage(), 'password'),
    'security: direct post-validation rejects missing password before mutation'
);
orange_restore_release_lock($directPost['workRoot']);
e2e_rmdir($directPost['backupRoot']);

$directFinalize = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED);
$directFinalizeErr = e2e_try(static function () use ($directFinalize): void {
    orange_restore_merge_post_validation_finalize_run([
        'project_root' => $directFinalize['projectRoot'],
        'work_root' => $directFinalize['workRoot'],
        'job_id' => $directFinalize['jobId'],
        'admin_id' => 1,
        'env_override' => $directFinalize['env'],
    ]);
});
e2e_self_test(
    $directFinalizeErr !== null && str_contains($directFinalizeErr->getMessage(), 'password'),
    'security: direct finalize rejects missing password before mutation'
);
e2e_rmdir($directFinalize['backupRoot']);

$missingReport = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLED, [
    'maintenance_disable_pending_at' => gmdate('c'),
    'maintenance_disabled_at' => gmdate('c'),
]);
@unlink((string) ($missingReport['reportPath'] ?? ''));
$missingReportErr = e2e_try(static fn (): array => e2e_run_finalize($missingReport));
e2e_self_test(
    $missingReportErr !== null && str_contains($missingReportErr->getMessage(), 'Post-validation report missing'),
    'binding: missing pass report fails closed'
);
e2e_rmdir($missingReport['backupRoot']);

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
$terminalAudit = e2e_seed_finalize_checkpoint(ORANGE_RESTORE_JOB_STATUS_COMPLETED, [
    'restore_completed_at' => gmdate('c'),
]);
e2e_run_finalize($terminalAudit);
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
            ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLE_PENDING,
            ['maintenance_disable_pending_at' => gmdate('c')]
        );
        orange_restore_job_post_validation_transition(
            $completedAudit['workRoot'],
            $completedAudit['jobId'],
            ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLED,
            ['maintenance_disabled_at' => gmdate('c')]
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
    && str_contains($e2eSource, 'orange_restore_full_staging_finalize_approval')
    && str_contains($e2eSource, 'ORANGE_RESTORE_E2E_ACTION_RECONCILE_COMPLETED'),
    'design: e2e orchestrator delegates staging finalization without duplicated gate logic'
);

echo PHP_EOL;
if ($failures === 0) {
    echo "ALL Phase 2E end-to-end orchestrator self-tests PASSED.\n";
    exit(0);
}

echo "FAILED: {$failures} test(s).\n";
exit(1);
