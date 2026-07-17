<?php

declare(strict_types=1);

/**
 * Phase 3B.4F — Restore Finalization & Maintenance Release.
 *
 * Finalizes a successful restore or rollback. Never: database import, uploads rename,
 * rollback execution, shadow execution, modify/delete rollback anchors, remove retention
 * pins, or re-run verification. All execution work must already be complete.
 */

require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_production_maintenance.php';
require_once __DIR__ . '/restore_production_import.php';
require_once __DIR__ . '/restore_production_uploads_cutover.php';
require_once __DIR__ . '/restore_production_rollback.php';
require_once __DIR__ . '/restore_merge_maintenance.php';
require_once __DIR__ . '/restore_maintenance_framework.php';
require_once __DIR__ . '/restore_execution_orchestrator.php';
require_once __DIR__ . '/restore_execution_bridge.php';
require_once __DIR__ . '/restore_pre_restore_backup.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_manifest.php';
require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../backup_retention.php';

const ORANGE_RESTORE_PROD_FINALIZE_VERSION = '3B.4F-v1';
const ORANGE_RESTORE_PROD_FINALIZE_REPORT_FILE = 'restore_final_report.json';
const ORANGE_RESTORE_PROD_FINALIZE_META_FILE = 'finalize.json';

const ORANGE_RESTORE_PROD_FINALIZE_PATH_RESTORE = 'restore_success';
const ORANGE_RESTORE_PROD_FINALIZE_PATH_ROLLBACK = 'rollback_success';

function orange_restore_prod_finalize_report_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PROD_FINALIZE_REPORT_FILE;
}

function orange_restore_prod_finalize_meta_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PROD_FINALIZE_META_FILE;
}

/**
 * Detect whether any rollback was requested/started for this job.
 */
function orange_restore_prod_finalize_rollback_was_requested(string $workRoot, string $jobId): bool
{
    $status = (string) (orange_restore_fw_read($workRoot, $jobId)['status'] ?? '');
    if (in_array($status, [
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_PENDING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_DATABASE_RUNNING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_DATABASE_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_FILES_RUNNING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_FILES_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_READY,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_FAILED,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_FINALIZING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_COMPLETED,
    ], true)) {
        return true;
    }

    $metaPath = orange_restore_prod_rollback_meta_path($workRoot, $jobId);
    if (is_file($metaPath)) {
        return true;
    }

    foreach (orange_restore_prod_rollback_checkpoint_ids() as $id) {
        if (orange_restore_prod_rollback_load_checkpoint($workRoot, $jobId, $id) !== null) {
            return true;
        }
    }

    return false;
}

/**
 * Read existing verification artifacts only — never re-run verification engines.
 *
 * @return array{ok:bool,code:string,summary:array<string,mixed>}
 */
function orange_restore_prod_finalize_read_success_verification(string $workRoot, string $jobId): array
{
    $summary = [
        'production_import' => false,
        'uploads_cutover' => false,
        'c6' => false,
        'c8' => false,
    ];

    $c6 = orange_restore_prod_import_load_checkpoint($workRoot, $jobId, ORANGE_RESTORE_PROD_IMPORT_C6);
    $c5 = orange_restore_prod_import_load_checkpoint($workRoot, $jobId, ORANGE_RESTORE_PROD_IMPORT_C5);
    $summary['c6'] = $c6 !== null && $c5 !== null;

    $importReport = null;
    $importPath = orange_restore_prod_import_report_path($workRoot, $jobId);
    if (is_file($importPath)) {
        $importReport = json_decode((string) file_get_contents($importPath), true);
    }
    $summary['production_import'] = is_array($importReport)
        && strtoupper((string) ($importReport['overall'] ?? '')) === 'PASS'
        && strtoupper((string) (($importReport['verification']['overall'] ?? '') ?: 'PASS')) === 'PASS';

    $c8 = orange_restore_uploads_cutover_load_checkpoint($workRoot, $jobId, ORANGE_RESTORE_UPLOADS_CUTOVER_C8);
    $summary['c8'] = $c8 !== null;

    $uploadsReport = null;
    $uploadsPath = orange_restore_uploads_cutover_report_path($workRoot, $jobId);
    if (is_file($uploadsPath)) {
        $uploadsReport = json_decode((string) file_get_contents($uploadsPath), true);
    }
    $summary['uploads_cutover'] = is_array($uploadsReport)
        && strtoupper((string) ($uploadsReport['overall'] ?? '')) === 'PASS';

    if (!$summary['c6'] || !$summary['production_import']) {
        return ['ok' => false, 'code' => 'production_import_not_ready', 'summary' => $summary];
    }
    if (!$summary['c8'] || !$summary['uploads_cutover']) {
        return ['ok' => false, 'code' => 'uploads_cutover_not_verified', 'summary' => $summary];
    }

    return ['ok' => true, 'code' => 'ok', 'summary' => $summary];
}

/**
 * @return array{ok:bool,code:string,summary:array<string,mixed>}
 */
function orange_restore_prod_finalize_read_rollback_verification(string $workRoot, string $jobId): array
{
    $summary = [
        'c12' => false,
        'rollback_report' => false,
    ];
    $c12 = orange_restore_prod_rollback_load_checkpoint($workRoot, $jobId, ORANGE_RESTORE_PROD_ROLLBACK_C12);
    $summary['c12'] = $c12 !== null;

    $report = null;
    $path = orange_restore_prod_rollback_report_path($workRoot, $jobId);
    if (is_file($path)) {
        $report = json_decode((string) file_get_contents($path), true);
    }
    $summary['rollback_report'] = is_array($report)
        && strtoupper((string) ($report['overall'] ?? '')) === 'PASS';

    if (!$summary['c12'] || !$summary['rollback_report']) {
        return ['ok' => false, 'code' => 'rollback_verification_not_passed', 'summary' => $summary];
    }

    return ['ok' => true, 'code' => 'ok', 'summary' => $summary];
}

/**
 * @return array{ok:bool,code:string,path:string,details:array<string,mixed>}
 */
function orange_restore_prod_finalize_validate_entry(
    string $workRoot,
    string $jobId,
    string $backupRoot
): array {
    $details = [
        'path' => '',
        'production_import_ready' => false,
        'uploads_cutover_ready' => false,
        'no_rollback_requested' => false,
        'final_verification_passed' => false,
        'rollback_ready' => false,
        'rollback_verification_passed' => false,
        'rollback_anchor' => false,
        'maintenance_active' => false,
        'execution_started_false' => false,
    ];

    $job = orange_restore_fw_read($workRoot, $jobId);
    if ((string) ($job['package_type'] ?? '') !== 'full_disaster') {
        return [
            'ok' => false,
            'code' => 'country_production_restore_not_enabled',
            'path' => '',
            'details' => $details,
        ];
    }
    if (!empty($job['execution_started'])) {
        return [
            'ok' => false,
            'code' => 'execution_already_started',
            'path' => '',
            'details' => $details,
        ];
    }
    $details['execution_started_false'] = true;

    $status = (string) ($job['status'] ?? '');
    $maint = orange_restore_maint_fw_read($workRoot);
    $details['maintenance_active'] = (string) ($maint['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_ACTIVE
        && (string) ($maint['related_job_id'] ?? '') === $jobId;

    // Already finalized — allow idempotent status reads via dedicated path detection.
    if ($status === ORANGE_RESTORE_FW_STATUS_RESTORE_COMPLETED) {
        return [
            'ok' => true,
            'code' => 'already_restore_completed',
            'path' => ORANGE_RESTORE_PROD_FINALIZE_PATH_RESTORE,
            'details' => $details + [
                'path' => ORANGE_RESTORE_PROD_FINALIZE_PATH_RESTORE,
                'production_import_ready' => true,
                'uploads_cutover_ready' => true,
                'no_rollback_requested' => true,
                'final_verification_passed' => true,
            ],
        ];
    }
    if ($status === ORANGE_RESTORE_FW_STATUS_ROLLBACK_COMPLETED) {
        return [
            'ok' => true,
            'code' => 'already_rollback_completed',
            'path' => ORANGE_RESTORE_PROD_FINALIZE_PATH_ROLLBACK,
            'details' => $details + [
                'path' => ORANGE_RESTORE_PROD_FINALIZE_PATH_ROLLBACK,
                'rollback_ready' => true,
                'rollback_verification_passed' => true,
            ],
        ];
    }

    $anchor = orange_restore_pre_backup_load_record($workRoot, $jobId);
    $details['rollback_anchor'] = is_array($anchor)
        && !empty($anchor['ready_for_rollback'])
        && !empty($anchor['retention_pinned'])
        && trim((string) ($anchor['rollback_package_id'] ?? '')) !== '';
    if (!$details['rollback_anchor']) {
        return ['ok' => false, 'code' => 'missing_rollback_anchor', 'path' => '', 'details' => $details];
    }

    $pkgId = trim((string) ($anchor['rollback_package_id'] ?? ''));
    if ($pkgId !== '' && orange_backup_retention_pin_public($backupRoot, $pkgId) === null) {
        return ['ok' => false, 'code' => 'retention_pin_missing', 'path' => '', 'details' => $details];
    }

    // Rollback finalize path
    if (in_array($status, [
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_READY,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_FINALIZING,
    ], true)) {
        if (!$details['maintenance_active'] && $status !== ORANGE_RESTORE_FW_STATUS_ROLLBACK_FINALIZING) {
            // Allow resume of finalizing if maint already released mid-finalize (crash after release).
            return ['ok' => false, 'code' => 'maintenance_not_active', 'path' => '', 'details' => $details];
        }
        $details['rollback_ready'] = true;
        $rv = orange_restore_prod_finalize_read_rollback_verification($workRoot, $jobId);
        $details['rollback_verification_passed'] = $rv['ok'];
        $details['verification_summary'] = $rv['summary'];
        if (!$rv['ok']) {
            return [
                'ok' => false,
                'code' => (string) $rv['code'],
                'path' => ORANGE_RESTORE_PROD_FINALIZE_PATH_ROLLBACK,
                'details' => $details,
            ];
        }
        if ($status === ORANGE_RESTORE_FW_STATUS_ROLLBACK_READY && !$details['maintenance_active']) {
            return ['ok' => false, 'code' => 'maintenance_not_active', 'path' => '', 'details' => $details];
        }

        return [
            'ok' => true,
            'code' => 'ok',
            'path' => ORANGE_RESTORE_PROD_FINALIZE_PATH_ROLLBACK,
            'details' => $details + ['path' => ORANGE_RESTORE_PROD_FINALIZE_PATH_ROLLBACK],
        ];
    }

    // Success finalize path
    if (in_array($status, [
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY,
        ORANGE_RESTORE_FW_STATUS_RESTORE_FINALIZING,
    ], true)) {
        if ($status === ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY && !$details['maintenance_active']) {
            return ['ok' => false, 'code' => 'maintenance_not_active', 'path' => '', 'details' => $details];
        }

        $details['uploads_cutover_ready'] = $status === ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY
            || $status === ORANGE_RESTORE_FW_STATUS_RESTORE_FINALIZING
            || orange_restore_uploads_cutover_load_checkpoint($workRoot, $jobId, ORANGE_RESTORE_UPLOADS_CUTOVER_C8) !== null;

        $details['no_rollback_requested'] = !orange_restore_prod_finalize_rollback_was_requested($workRoot, $jobId);
        if (!$details['no_rollback_requested'] && $status === ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY) {
            return [
                'ok' => false,
                'code' => 'rollback_was_requested',
                'path' => '',
                'details' => $details,
            ];
        }

        $sv = orange_restore_prod_finalize_read_success_verification($workRoot, $jobId);
        $details['production_import_ready'] = !empty($sv['summary']['production_import']) && !empty($sv['summary']['c6']);
        $details['final_verification_passed'] = $sv['ok'];
        $details['verification_summary'] = $sv['summary'];
        if (!$sv['ok']) {
            return [
                'ok' => false,
                'code' => (string) $sv['code'],
                'path' => ORANGE_RESTORE_PROD_FINALIZE_PATH_RESTORE,
                'details' => $details,
            ];
        }

        return [
            'ok' => true,
            'code' => 'ok',
            'path' => ORANGE_RESTORE_PROD_FINALIZE_PATH_RESTORE,
            'details' => $details + ['path' => ORANGE_RESTORE_PROD_FINALIZE_PATH_RESTORE],
        ];
    }

    return ['ok' => false, 'code' => 'finalize_entry_not_allowed', 'path' => '', 'details' => $details];
}

/**
 * @return list<array<string,mixed>>
 */
function orange_restore_prod_finalize_collect_timeline(string $workRoot, string $jobId): array
{
    $timeline = [];
    foreach (array_merge(
        orange_restore_prod_import_checkpoint_history($workRoot, $jobId),
        orange_restore_uploads_cutover_checkpoint_history($workRoot, $jobId),
        orange_restore_prod_rollback_checkpoint_history($workRoot, $jobId)
    ) as $row) {
        $timeline[] = $row;
    }

    return $timeline;
}

/**
 * @return array<string, bool|string>
 */
function orange_restore_prod_finalize_artifact_presence(string $workRoot, string $jobId): array
{
    return [
        'production_import_report' => is_file(orange_restore_prod_import_report_path($workRoot, $jobId)),
        'uploads_cutover_report' => is_file(orange_restore_uploads_cutover_report_path($workRoot, $jobId)),
        'rollback_report' => is_file(orange_restore_prod_rollback_report_path($workRoot, $jobId)),
        'pre_restore_backup_record' => is_file(orange_restore_pre_backup_record_path($workRoot, $jobId)),
        'checkpoints_dir' => is_dir(orange_restore_prod_import_checkpoint_dir($workRoot, $jobId)),
        'finalize_report' => is_file(orange_restore_prod_finalize_report_path($workRoot, $jobId)),
    ];
}

/**
 * HTTP: request finalize (metadata only).
 *
 * @param array<string, mixed> $admin
 * @return array<string, mixed>
 */
function orange_restore_prod_finalize_request(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    array $admin
): array {
    $operator = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin')) ?: 'admin';
    $gates = orange_restore_prod_finalize_validate_entry($workRoot, $jobId, $backupRoot);
    if (!$gates['ok'] && !in_array((string) $gates['code'], [
        'already_restore_completed',
        'already_rollback_completed',
    ], true)) {
        throw new RuntimeException((string) $gates['code']);
    }

    $job = orange_restore_fw_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');
    $path = (string) ($gates['path'] ?? '');

    if ($status === ORANGE_RESTORE_FW_STATUS_RESTORE_COMPLETED
        || $status === ORANGE_RESTORE_FW_STATUS_ROLLBACK_COMPLETED) {
        return [
            'job' => orange_restore_fw_public_row($job),
            'cli_needed' => false,
            'idempotent' => true,
            'path' => $path,
            'execution_started' => false,
            'maintenance_released' => true,
            'restore_completed' => $status === ORANGE_RESTORE_FW_STATUS_RESTORE_COMPLETED,
            'rollback_completed' => $status === ORANGE_RESTORE_FW_STATUS_ROLLBACK_COMPLETED,
            'message' => $status === ORANGE_RESTORE_FW_STATUS_RESTORE_COMPLETED
                ? 'Restore already completed.'
                : 'Rollback already completed.',
            'warning' => 'Finalization already finished. Forensic artifacts retained.',
        ];
    }

    if (in_array($status, [
        ORANGE_RESTORE_FW_STATUS_RESTORE_FINALIZING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_FINALIZING,
    ], true)) {
        return [
            'job' => orange_restore_fw_public_row($job),
            'cli_needed' => true,
            'idempotent' => true,
            'path' => $path,
            'cli_command' => 'php scripts/backup/restore_finalize.php --job=' . $jobId,
            'execution_started' => false,
            'message' => 'Finalize already requested. Run CLI worker.',
            'warning' => 'CLI will release maintenance and mark completion. Artifacts retained.',
        ];
    }

    $pendingStatus = $path === ORANGE_RESTORE_PROD_FINALIZE_PATH_ROLLBACK
        ? ORANGE_RESTORE_FW_STATUS_ROLLBACK_FINALIZING
        : ORANGE_RESTORE_FW_STATUS_RESTORE_FINALIZING;
    $pendingPhase = $path === ORANGE_RESTORE_PROD_FINALIZE_PATH_ROLLBACK
        ? ORANGE_RESTORE_FW_PHASE_ROLLBACK_FINALIZING
        : ORANGE_RESTORE_FW_PHASE_RESTORE_FINALIZING;

    $meta = [
        'record_version' => ORANGE_RESTORE_PROD_FINALIZE_VERSION,
        'framework_job_id' => $jobId,
        'path' => $path,
        'status' => $pendingStatus,
        'requested_at' => gmdate('c'),
        'requested_by' => $operator,
        'cli_needed' => true,
        'cli_command' => 'php scripts/backup/restore_finalize.php --job=' . $jobId,
        'execution_started' => false,
        'database_import_performed' => false,
        'uploads_rename_performed' => false,
        'rollback_executed' => false,
        'shadow_executed' => false,
        'rollback_anchor_deleted' => false,
        'retention_pin_removed' => false,
        'verification_rerun' => false,
        'warning' => 'CLI will release maintenance. Rollback anchor and retention pin are preserved.',
    ];
    orange_backup_write_json(orange_restore_prod_finalize_meta_path($workRoot, $jobId), $meta);

    $job = orange_restore_fw_transition(
        $workRoot,
        $jobId,
        $pendingStatus,
        $pendingPhase,
        90,
        'Finalize Pending — CLI required',
        'restore_finalize_pending'
    );
    $job['execution_started'] = false;
    $job['finalize_file'] = ORANGE_RESTORE_PROD_FINALIZE_META_FILE;
    orange_restore_fw_write($workRoot, $job);

    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_finalize_requested',
        'result' => 'ok',
        'path' => $path,
        'operator_username' => $operator,
        'execution_started' => false,
        'rollback_anchor_deleted' => false,
        'retention_pin_removed' => false,
    ]);

    return [
        'job' => orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId)),
        'meta' => $meta,
        'gates' => $gates['details'],
        'path' => $path,
        'cli_needed' => true,
        'cli_command' => $meta['cli_command'],
        'execution_started' => false,
        'message' => 'Finalize Pending. Run CLI worker.',
        'warning' => 'CLI will release maintenance. Rollback anchor and retention pin are preserved.',
    ];
}

/**
 * CLI worker — finalize only. Releases maintenance + execution lock; writes final report.
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_prod_finalize_run_cli(array $options): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('cli_only');
    }

    $projectRoot = (string) ($options['project_root'] ?? '');
    $workRoot = (string) ($options['work_root'] ?? '');
    $backupRoot = (string) ($options['backup_root'] ?? '');
    $jobId = trim((string) ($options['job_id'] ?? ''));
    $owner = (string) ($options['owner'] ?? 'cli');

    if ($projectRoot === '' || $workRoot === '' || $backupRoot === '' || $jobId === '') {
        throw new InvalidArgumentException('project_root, work_root, backup_root, job_id required.');
    }

    $startedAt = microtime(true);
    $gates = orange_restore_prod_finalize_validate_entry($workRoot, $jobId, $backupRoot);
    if (!$gates['ok'] && !in_array((string) $gates['code'], [
        'already_restore_completed',
        'already_rollback_completed',
    ], true)) {
        throw new RuntimeException((string) $gates['code']);
    }

    $path = (string) ($gates['path'] ?? '');
    $job = orange_restore_fw_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');

    if (in_array($status, [
        ORANGE_RESTORE_FW_STATUS_RESTORE_COMPLETED,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_COMPLETED,
    ], true)) {
        return [
            'ok' => true,
            'job_id' => $jobId,
            'status' => $status,
            'path' => $path,
            'resume' => 'already_completed',
            'execution_started' => false,
            'maintenance_released' => true,
            'restore_completed' => $status === ORANGE_RESTORE_FW_STATUS_RESTORE_COMPLETED,
            'rollback_completed' => $status === ORANGE_RESTORE_FW_STATUS_ROLLBACK_COMPLETED,
            'rollback_anchor_deleted' => false,
            'retention_pin_removed' => false,
        ];
    }

    $isRollback = $path === ORANGE_RESTORE_PROD_FINALIZE_PATH_ROLLBACK;
    $finalizingStatus = $isRollback
        ? ORANGE_RESTORE_FW_STATUS_ROLLBACK_FINALIZING
        : ORANGE_RESTORE_FW_STATUS_RESTORE_FINALIZING;
    $finalizingPhase = $isRollback
        ? ORANGE_RESTORE_FW_PHASE_ROLLBACK_FINALIZING
        : ORANGE_RESTORE_FW_PHASE_RESTORE_FINALIZING;
    $completedStatus = $isRollback
        ? ORANGE_RESTORE_FW_STATUS_ROLLBACK_COMPLETED
        : ORANGE_RESTORE_FW_STATUS_RESTORE_COMPLETED;
    $completedPhase = $isRollback
        ? ORANGE_RESTORE_FW_PHASE_ROLLBACK_COMPLETED
        : ORANGE_RESTORE_FW_PHASE_RESTORE_COMPLETED;
    $overallResult = $isRollback ? 'ROLLBACK_COMPLETED' : 'RESTORE_COMPLETED';

    try {
        if ($status !== $finalizingStatus) {
            orange_restore_fw_transition(
                $workRoot,
                $jobId,
                $finalizingStatus,
                $finalizingPhase,
                92,
                $isRollback ? 'Rollback finalizing' : 'Restore finalizing',
                'restore_finalize_running'
            );
        }

        $anchor = orange_restore_pre_backup_load_record($workRoot, $jobId);
        $pkgId = trim((string) (($anchor['rollback_package_id'] ?? '') ?: ''));
        $pinBefore = $pkgId !== '' ? orange_backup_retention_pin_public($backupRoot, $pkgId) : null;
        if ($anchor === null || $pinBefore === null) {
            throw new RuntimeException('finalize_anchor_or_pin_missing');
        }

        $verificationSummary = $gates['details']['verification_summary']
            ?? ($isRollback
                ? orange_restore_prod_finalize_read_rollback_verification($workRoot, $jobId)['summary']
                : orange_restore_prod_finalize_read_success_verification($workRoot, $jobId)['summary']);

        $timeline = orange_restore_prod_finalize_collect_timeline($workRoot, $jobId);
        $artifactsBefore = orange_restore_prod_finalize_artifact_presence($workRoot, $jobId);

        // Release merge maintenance file (Phase-2 flag) if present.
        $mergeMaint = orange_restore_merge_maintenance_status($workRoot);
        if (!empty($mergeMaint['active'])) {
            try {
                orange_restore_merge_maintenance_disable($workRoot, $jobId, [
                    'reason' => $isRollback ? 'rollback_finalize_3b4f' : 'restore_finalize_3b4f',
                ]);
            } catch (Throwable $e) {
                // If owned by this job but disable failed hard, fail closed.
                throw new RuntimeException('merge_maintenance_release_failed:' . $e->getMessage());
            }
        }

        // Release framework maintenance.
        $maint = orange_restore_maint_fw_read($workRoot);
        if ((string) ($maint['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_ACTIVE
            && (string) ($maint['related_job_id'] ?? '') === $jobId) {
            orange_restore_maint_fw_release($workRoot, $owner);
        }

        // Release execution orchestration lock.
        orange_restore_exec_release_lock($workRoot, $jobId);

        // Confirm forensic artifacts still present — never delete.
        $anchorAfter = orange_restore_pre_backup_load_record($workRoot, $jobId);
        $pinAfter = orange_backup_retention_pin_public($backupRoot, $pkgId);
        if ($anchorAfter === null || $pinAfter === null) {
            throw new RuntimeException('finalize_destroyed_forensic_artifacts');
        }
        $artifactsAfter = orange_restore_prod_finalize_artifact_presence($workRoot, $jobId);

        $duration = (int) round(microtime(true) - $startedAt);
        $jobCreated = (string) ($job['created_at'] ?? '');
        $totalDuration = $duration;
        if ($jobCreated !== '') {
            $createdTs = strtotime($jobCreated);
            if ($createdTs !== false) {
                $totalDuration = max($duration, (int) (time() - $createdTs));
            }
        }

        $report = [
            'report_version' => ORANGE_RESTORE_PROD_FINALIZE_VERSION,
            'job_id' => $jobId,
            'overall_result' => $overallResult,
            'RESTORE_COMPLETED' => !$isRollback,
            'ROLLBACK_COMPLETED' => $isRollback,
            'path' => $path,
            'duration_seconds' => $duration,
            'job_duration_seconds' => $totalDuration,
            'timeline' => $timeline,
            'artifacts' => [
                'before' => $artifactsBefore,
                'after' => $artifactsAfter,
                'preserved' => true,
            ],
            'verification_summary' => $verificationSummary,
            'rollback_package_id' => $pkgId,
            'retention_pin_id' => (string) ($pinAfter['pin_id'] ?? ''),
            'execution_started' => false,
            'maintenance_released' => true,
            'execution_lock_released' => true,
            'restore_completed' => !$isRollback,
            'rollback_completed' => $isRollback,
            'rollback_anchor_deleted' => false,
            'retention_pin_removed' => false,
            'database_import_performed' => false,
            'uploads_rename_performed' => false,
            'rollback_executed' => false,
            'shadow_executed' => false,
            'verification_rerun' => false,
            'completed_at' => gmdate('c'),
            'completed_by' => $owner,
            'warning' => 'Maintenance released. Execution finished. Forensic artifacts retained (anchor + pin + reports + checkpoints).',
        ];
        orange_backup_write_json(orange_restore_prod_finalize_report_path($workRoot, $jobId), $report);

        $meta = [
            'record_version' => ORANGE_RESTORE_PROD_FINALIZE_VERSION,
            'framework_job_id' => $jobId,
            'path' => $path,
            'status' => $completedStatus,
            'completed_at' => gmdate('c'),
            'completed_by' => $owner,
            'cli_needed' => false,
            'execution_started' => false,
            'maintenance_released' => true,
            'restore_completed' => !$isRollback,
            'rollback_completed' => $isRollback,
            'rollback_anchor_deleted' => false,
            'retention_pin_removed' => false,
            'warning' => 'Maintenance released. Execution finished. Forensic artifacts retained.',
        ];
        orange_backup_write_json(orange_restore_prod_finalize_meta_path($workRoot, $jobId), $meta);

        $job = orange_restore_fw_transition(
            $workRoot,
            $jobId,
            $completedStatus,
            $completedPhase,
            100,
            $isRollback ? 'Rollback Completed — Maintenance Released' : 'Restore Completed — Maintenance Released',
            $isRollback ? 'restore_rollback_completed' : 'restore_restore_completed'
        );
        $job['execution_started'] = false;
        $job['restore_completed'] = !$isRollback;
        $job['rollback_completed'] = $isRollback;
        $job['maintenance_released'] = true;
        $job['execution_finished'] = true;
        orange_restore_fw_write($workRoot, $job);

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => $isRollback ? 'restore_rollback_finalize_completed' : 'restore_finalize_completed',
            'result' => 'ok',
            'overall_result' => $overallResult,
            'operator_username' => $owner,
            'execution_started' => false,
            'maintenance_released' => true,
            'restore_completed' => !$isRollback,
            'rollback_completed' => $isRollback,
            'rollback_anchor_deleted' => false,
            'retention_pin_removed' => false,
            'duration_seconds' => $duration,
        ]);

        return [
            'ok' => true,
            'job_id' => $jobId,
            'status' => $completedStatus,
            'path' => $path,
            'overall_result' => $overallResult,
            'report' => $report,
            'execution_started' => false,
            'maintenance_released' => true,
            'execution_lock_released' => true,
            'restore_completed' => !$isRollback,
            'rollback_completed' => $isRollback,
            'rollback_anchor_deleted' => false,
            'retention_pin_removed' => false,
            'warning' => 'Maintenance released. Execution finished. Forensic artifacts retained.',
        ];
    } catch (Throwable $e) {
        $code = trim($e->getMessage()) ?: 'finalize_failed';
        try {
            orange_restore_fw_audit_append($workRoot, $jobId, [
                'event' => 'restore_finalize_failed',
                'result' => 'fail',
                'code' => $code,
                'operator_username' => $owner,
                'execution_started' => false,
            ]);
        } catch (Throwable) {
            // ignore
        }

        return [
            'ok' => false,
            'job_id' => $jobId,
            'status' => (string) (orange_restore_fw_read($workRoot, $jobId)['status'] ?? $finalizingStatus),
            'code' => $code,
            'path' => $path,
            'execution_started' => false,
            'maintenance_released' => false,
            'restore_completed' => false,
            'rollback_completed' => false,
            'rollback_anchor_deleted' => false,
            'retention_pin_removed' => false,
            'warning' => 'Finalize failed. Check maintenance/lock state before retry.',
        ];
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_prod_finalize_status(string $workRoot, string $jobId): array
{
    $job = orange_restore_fw_read($workRoot, $jobId);
    $metaPath = orange_restore_prod_finalize_meta_path($workRoot, $jobId);
    $reportPath = orange_restore_prod_finalize_report_path($workRoot, $jobId);
    $meta = is_file($metaPath) ? json_decode((string) file_get_contents($metaPath), true) : null;
    $report = is_file($reportPath) ? json_decode((string) file_get_contents($reportPath), true) : null;

    $labels = [
        ORANGE_RESTORE_FW_STATUS_RESTORE_FINALIZING => 'Restore Finalizing',
        ORANGE_RESTORE_FW_STATUS_RESTORE_COMPLETED => 'Restore Completed',
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_FINALIZING => 'Rollback Finalizing',
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_COMPLETED => 'Rollback Completed',
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY => 'Uploads Cutover Ready (finalize not requested)',
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_READY => 'Rollback Ready (finalize not requested)',
    ];
    $status = (string) ($job['status'] ?? '');
    $completed = in_array($status, [
        ORANGE_RESTORE_FW_STATUS_RESTORE_COMPLETED,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_COMPLETED,
    ], true);

    return [
        'job' => orange_restore_fw_public_row($job),
        'meta' => is_array($meta) ? $meta : null,
        'report' => is_array($report) ? $report : null,
        'status_label' => $labels[$status] ?? $status,
        'execution_started' => false,
        'maintenance_released' => $completed || !empty($job['maintenance_released']),
        'restore_completed' => $status === ORANGE_RESTORE_FW_STATUS_RESTORE_COMPLETED,
        'rollback_completed' => $status === ORANGE_RESTORE_FW_STATUS_ROLLBACK_COMPLETED,
        'execution_finished' => $completed,
        'rollback_anchor_deleted' => false,
        'retention_pin_removed' => false,
        'artifacts' => orange_restore_prod_finalize_artifact_presence($workRoot, $jobId),
        'read_only' => true,
        'warning' => $completed
            ? 'Maintenance Released. Execution Finished. Forensic artifacts retained.'
            : 'Finalize not completed yet.',
    ];
}
