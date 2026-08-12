<?php

declare(strict_types=1);

/**
 * Restore Center orchestration layer (Owner 2026-07-24).
 *
 * Schedules already-approved restore CLI workers from Admin HTTP.
 * HTTP must return immediately — workers run as detached OS processes and
 * must not depend on browser / HTTP connection lifetime.
 *
 * Production rules (Owner final directive):
 * - Server-side stage validation before any schedule (atomic with claim).
 * - One job + one stage = one running worker (orchestration lock; not CLI).
 * - Never report scheduled unless spawn + PID verification succeeded.
 * - Full stdin/stdout/stderr detachment from the HTTP process.
 * - Claim lifecycle reconciled with job status (not PID-only).
 * - Safe diagnostics for operators (no SSH).
 *
 * Does not implement restore logic, alter gates, or rewrite workers.
 */

require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/restore_worker_runtime.php';
require_once __DIR__ . '/restore_worker_php_cli.php';
require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_production_cli_policy.php';
require_once __DIR__ . '/restore_private_shadow_engine.php';

const ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION = '3B.4-rc-orchestrator-v9-private-shadow-engine';
const ORANGE_RESTORE_CENTER_WORKER_LOCK_STALE_SECONDS = 21600;
const ORANGE_RESTORE_CENTER_CLAIM_TRANSITION_GRACE_SECONDS = 120;
const ORANGE_RESTORE_CENTER_DIAG_LOG_TAIL_BYTES = 8192;
const ORANGE_RESTORE_CENTER_SHADOW_BOOTSTRAP_WAIT_MS = 5000;
const ORANGE_RESTORE_CENTER_SHADOW_BOOTSTRAP_POLL_MS = 100;

/** Owner-safe Step-7 start failure codes (no paths/secrets). */
const ORANGE_RESTORE_STEP7_WRONG_STATE = 'STEP7_WRONG_STATE';
const ORANGE_RESTORE_STEP7_REQUEST_TRANSITION_FAILED = 'STEP7_REQUEST_TRANSITION_FAILED';
const ORANGE_RESTORE_STEP7_ACTIVE_ATTEMPT_EXISTS = 'STEP7_ACTIVE_ATTEMPT_EXISTS';
const ORANGE_RESTORE_STEP7_CLAIM_CONFLICT = 'STEP7_CLAIM_CONFLICT';
const ORANGE_RESTORE_STEP7_MUTEX_CONFLICT = 'STEP7_MUTEX_CONFLICT';
const ORANGE_RESTORE_STEP7_WORK_ROOT_NOT_READY = 'STEP7_WORK_ROOT_NOT_READY';
const ORANGE_RESTORE_STEP7_WORK_ROOT_NOT_WRITABLE = 'STEP7_WORK_ROOT_NOT_WRITABLE';
const ORANGE_RESTORE_STEP7_WORKER_KEY_UNAVAILABLE = 'STEP7_WORKER_KEY_UNAVAILABLE';
const ORANGE_RESTORE_STEP7_WORKER_ENTRYPOINT_UNAVAILABLE = 'STEP7_WORKER_ENTRYPOINT_UNAVAILABLE';
const ORANGE_RESTORE_STEP7_WORKER_ENTRYPOINT_NOT_ALLOWED = 'STEP7_WORKER_ENTRYPOINT_NOT_ALLOWED';
const ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE = 'STEP7_PHP_CLI_UNAVAILABLE';
const ORANGE_RESTORE_STEP7_PROCESS_EXECUTION_UNAVAILABLE = 'STEP7_PROCESS_EXECUTION_UNAVAILABLE';
const ORANGE_RESTORE_STEP7_LAUNCH_ARTIFACT_FAILED = 'STEP7_LAUNCH_ARTIFACT_FAILED';
const ORANGE_RESTORE_STEP7_PROCESS_SPAWN_FAILED = 'STEP7_PROCESS_SPAWN_FAILED';
const ORANGE_RESTORE_STEP7_WORKER_BOOTSTRAP_FAILED = 'STEP7_WORKER_BOOTSTRAP_FAILED';
if (!defined('ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE')) {
    define('ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE', 'STEP7_SHADOW_DB_TARGET_UNAVAILABLE');
}
if (!defined('ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE')) {
    define('ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE', 'STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE');
}
const ORANGE_RESTORE_STEP7_UNKNOWN_START_FAILURE = 'STEP7_UNKNOWN_START_FAILURE';
/** Owner readiness token — only when all Step-7 gates are proven green. */
const ORANGE_RESTORE_STEP7_READY_FOR_CONTROLLED_ATTEMPT = 'READY_FOR_CONTROLLED_STEP7_ATTEMPT';
/** Owner readiness token — private engine binary discoverable; provision not yet done (zero-mutation). */
// Alias: ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING (restore_private_shadow_engine.php)

/**
 * Pending statuses that must never remain without a verified worker consumer.
 *
 * @return array<string, string> workerKey => pending status
 */
function orange_restore_center_worker_pending_status_map(): array
{
    // Step 6 (pre_restore_backup) is NOT an orchestrator worker — it uses the shared
    // Full Backup service via request-pre-restore-backup.php (Owner 2026-08-10).
    return [
        'shadow_db' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        'shadow_smoke' => ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_PENDING,
        'production_import' => ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_PENDING,
        'uploads_cutover' => ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_PENDING,
        'rollback' => ORANGE_RESTORE_FW_STATUS_ROLLBACK_PENDING,
    ];
}

/**
 * Existing retryable/failed statuses used to compensate dispatch failure after pending.
 *
 * @return array<string, string> workerKey => failed status
 */
function orange_restore_center_worker_dispatch_failure_status_map(): array
{
    return [
        'shadow_db' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'shadow_smoke' => ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_FAILED,
        'production_import' => ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_FAILED,
        'uploads_cutover' => ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_FAILED,
        'rollback' => ORANGE_RESTORE_FW_STATUS_ROLLBACK_FAILED,
    ];
}

/**
 * @return array<string, string> workerKey => failed phase
 */
function orange_restore_center_worker_dispatch_failure_phase_map(): array
{
    return [
        'shadow_db' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
        'shadow_smoke' => ORANGE_RESTORE_FW_PHASE_SHADOW_SMOKE_FAILED,
        'production_import' => ORANGE_RESTORE_FW_PHASE_PRODUCTION_IMPORT_FAILED,
        'uploads_cutover' => ORANGE_RESTORE_FW_PHASE_UPLOADS_CUTOVER_FAILED,
        'rollback' => ORANGE_RESTORE_FW_PHASE_ROLLBACK_FAILED,
    ];
}

/**
 * After pre-exec dispatch compensation, keep nested shadow_restore_status aligned with top-level.
 * Does not delete historical attempts. Does not invent a new Step-7 request.
 *
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function orange_restore_center_sync_shadow_restore_nested_status(
    string $workRoot,
    string $jobId,
    array $job
): array {
    $status = (string) ($job['status'] ?? '');
    $nested = (string) ($job['shadow_restore_status'] ?? '');
    if ($status !== ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED) {
        return $job;
    }
    if ($nested !== '' && $nested !== ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING) {
        return $job;
    }
    // Only reconcile stale pending (or empty) nested status after top-level failed with no execution.
    if (!empty($job['execution_started'])) {
        return $job;
    }
    $job['shadow_restore_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED;
    orange_restore_fw_write($workRoot, $job);

    // Nested shadow meta (same relative name as restore_shadow_db) — no Backup Center coupling.
    $metaPath = orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . 'shadow_restore.json';
    if (is_file($metaPath)) {
        $raw = (string) @file_get_contents($metaPath);
        $meta = json_decode($raw, true);
        if (is_array($meta)) {
            $metaStatus = (string) ($meta['status'] ?? '');
            if ($metaStatus === '' || $metaStatus === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING) {
                $meta['status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED;
                $meta['execution_started'] = false;
                $meta['cli_needed'] = false;
                $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json !== false) {
                    @file_put_contents($metaPath, $json . "\n", LOCK_EX);
                }
            }
        }
    }

    return $job;
}

/**
 * Refresh-time reconciliation: top-level shadow_restore_failed vs stale nested pending.
 * Safe for Owner Refresh — does not create a new attempt / request / spawn.
 *
 * @return array<string, mixed>|null Updated job or null when unchanged
 */
function orange_restore_center_reconcile_stale_shadow_restore_public_state(
    string $workRoot,
    string $jobId
): ?array {
    try {
        $job = orange_restore_fw_read($workRoot, $jobId);
    } catch (Throwable $e) {
        return null;
    }
    $status = (string) ($job['status'] ?? '');
    $nested = (string) ($job['shadow_restore_status'] ?? '');
    if ($status !== ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED) {
        return null;
    }
    if ($nested !== ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING && $nested !== '') {
        return null;
    }
    if (!empty($job['execution_started'])) {
        return null;
    }
    $before = $nested;
    $updated = orange_restore_center_sync_shadow_restore_nested_status($workRoot, $jobId, $job);
    $after = (string) ($updated['shadow_restore_status'] ?? '');
    if ($after === $before) {
        return null;
    }
    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_center_stale_shadow_restore_status_reconciled',
        'result' => 'ok',
        'worker' => 'shadow_db',
        'from_shadow_restore_status' => $before !== '' ? $before : 'empty',
        'to_shadow_restore_status' => $after,
        'job_status' => $status,
        'execution_started' => false,
        'scheduling_attempted' => false,
        'refresh_only' => true,
        'orchestrator_version' => ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION,
    ]);

    return $updated;
}

/**
 * If job is stuck in an unconsumed pending state after schedule failure, move to existing failed/retryable status.
 *
 * @return array<string, mixed>|null Updated job or null when no compensation applied
 */
function orange_restore_center_compensate_unconsumed_pending(
    string $workRoot,
    string $jobId,
    string $workerKey,
    string $reason = 'dispatch_failed'
): ?array {
    $pendingMap = orange_restore_center_worker_pending_status_map();
    $failedMap = orange_restore_center_worker_dispatch_failure_status_map();
    $phaseMap = orange_restore_center_worker_dispatch_failure_phase_map();
    if (!isset($pendingMap[$workerKey], $failedMap[$workerKey], $phaseMap[$workerKey])) {
        return null;
    }
    try {
        $job = orange_restore_fw_read($workRoot, $jobId);
    } catch (Throwable $e) {
        return null;
    }
    $status = (string) ($job['status'] ?? '');
    if ($status !== $pendingMap[$workerKey]) {
        // Already failed at top-level but nested pending may still be stale (Owner live shape).
        if ($workerKey === 'shadow_db' && $status === ($failedMap[$workerKey] ?? '')) {
            return orange_restore_center_reconcile_stale_shadow_restore_public_state($workRoot, $jobId);
        }

        return null;
    }
    $failed = $failedMap[$workerKey];
    if (!orange_restore_fw_transition_is_allowed($status, $failed)) {
        return null;
    }
    $updated = orange_restore_fw_transition(
        $workRoot,
        $jobId,
        $failed,
        $phaseMap[$workerKey],
        0,
        'Worker dispatch failed — retry from Restore Center',
        'restore_center_dispatch_compensated'
    );
    if ($workerKey === 'shadow_db') {
        $updated = orange_restore_center_sync_shadow_restore_nested_status($workRoot, $jobId, $updated);
    }
    $compensateAudit = [
        'event' => 'restore_center_pending_without_worker_compensated',
        'result' => 'fail',
        'worker' => $workerKey,
        'from_status' => $status,
        'to_status' => $failed,
        'reason' => $reason,
        'orchestrator_version' => ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION,
        'execution_started' => false,
        'spawn_succeeded' => false,
    ];
    if ($workerKey === 'shadow_db') {
        $compensateAudit['safe_failure_code'] = orange_restore_center_step7_classify_start_failure($reason);
        $compensateAudit['stage'] = 'shadow_restore';
        $compensateAudit['shadow_restore_status'] = (string) ($updated['shadow_restore_status'] ?? '');
    }
    orange_restore_fw_audit_append($workRoot, $jobId, $compensateAudit);

    return $updated;
}

/**
 * Allowlisted Restore Center worker keys → approved repo-relative CLI scripts.
 *
 * @return array<string, string>
 */
function orange_restore_center_worker_catalog(): array
{
    // pre_restore_backup intentionally absent — Restore Step 6 uses shared Full Backup service.
    $catalog = [
        'shadow_db' => 'scripts/backup/restore_shadow_db.php',
        'shadow_verify' => 'scripts/backup/restore_shadow_verify.php',
        'shadow_files' => 'scripts/backup/restore_shadow_files.php',
        'shadow_smoke' => 'scripts/backup/restore_shadow_smoke.php',
        'production_import' => 'scripts/backup/restore_import_production.php',
        'uploads_cutover' => 'scripts/backup/restore_uploads_cutover.php',
        'rollback' => 'scripts/backup/restore_rollback.php',
        'finalize' => 'scripts/backup/restore_finalize.php',
    ];
    // Disposable harness only (self-tests). Never used by Admin UI.
    if (isset($GLOBALS['orange_restore_center_test_worker_catalog'])
        && is_array($GLOBALS['orange_restore_center_test_worker_catalog'])) {
        foreach ($GLOBALS['orange_restore_center_test_worker_catalog'] as $key => $rel) {
            if (is_string($key) && is_string($rel) && $key !== '' && $rel !== '') {
                $catalog[$key] = $rel;
            }
        }
    }

    return $catalog;
}

/**
 * Job statuses from which this worker may be scheduled (mirrors framework requestable + pending + failed retry).
 * Orchestration-only allowlist — does not change framework transitions.
 *
 * @return array<string, list<string>>
 */
function orange_restore_center_worker_schedulable_statuses_map(): array
{
    $map = [
        'shadow_db' => [
            ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
            ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
            ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        ],
        'shadow_verify' => [
            ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY,
            ORANGE_RESTORE_FW_STATUS_SHADOW_NOT_READY,
        ],
        'shadow_files' => [
            ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED,
            ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_FAILED,
        ],
        'shadow_smoke' => [
            ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY,
            ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_FAILED,
            ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_WARNING,
            ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_BLOCKED,
            ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_MANUAL_REVIEW,
            ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_PENDING,
        ],
        'production_import' => [
            ORANGE_RESTORE_FW_STATUS_MAINTENANCE_ACTIVE,
            ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_FAILED,
            ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_PENDING,
        ],
        'uploads_cutover' => [
            ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY,
            ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_FAILED,
            ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_PENDING,
        ],
        'rollback' => [
            ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY,
            ORANGE_RESTORE_FW_STATUS_ROLLBACK_FAILED,
            ORANGE_RESTORE_FW_STATUS_ROLLBACK_PENDING,
        ],
        'finalize' => [
            ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY,
            ORANGE_RESTORE_FW_STATUS_ROLLBACK_READY,
            ORANGE_RESTORE_FW_STATUS_RESTORE_FINALIZING,
            ORANGE_RESTORE_FW_STATUS_ROLLBACK_FINALIZING,
        ],
    ];
    if (isset($GLOBALS['orange_restore_center_test_worker_schedulable'])
        && is_array($GLOBALS['orange_restore_center_test_worker_schedulable'])) {
        foreach ($GLOBALS['orange_restore_center_test_worker_schedulable'] as $key => $statuses) {
            if (is_string($key) && is_array($statuses)) {
                $map[$key] = array_values(array_map('strval', $statuses));
            }
        }
    }

    return $map;
}

/**
 * Statuses that mean this worker stage is currently in flight (claim may still be live).
 *
 * @return array<string, list<string>>
 */
function orange_restore_center_worker_inflight_statuses_map(): array
{
    return [
        'shadow_db' => [
            ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
            ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
            ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_VERIFYING,
        ],
        'shadow_verify' => [
            ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFYING,
        ],
        'shadow_files' => [
            ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_RUNNING,
            ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_VERIFYING,
        ],
        'shadow_smoke' => [
            ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_PENDING,
            ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_RUNNING,
        ],
        'production_import' => [
            ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_PENDING,
            ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_RUNNING,
            ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_VERIFYING,
        ],
        'uploads_cutover' => [
            ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_PENDING,
            ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_RUNNING,
            ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_VERIFYING,
        ],
        'rollback' => [
            ORANGE_RESTORE_FW_STATUS_ROLLBACK_PENDING,
            ORANGE_RESTORE_FW_STATUS_ROLLBACK_DATABASE_RUNNING,
            ORANGE_RESTORE_FW_STATUS_ROLLBACK_DATABASE_VERIFYING,
            ORANGE_RESTORE_FW_STATUS_ROLLBACK_FILES_RUNNING,
            ORANGE_RESTORE_FW_STATUS_ROLLBACK_FILES_VERIFYING,
        ],
        'finalize' => [
            ORANGE_RESTORE_FW_STATUS_RESTORE_FINALIZING,
            ORANGE_RESTORE_FW_STATUS_ROLLBACK_FINALIZING,
        ],
    ];
}

/**
 * @return list<string>
 */
function orange_restore_center_terminal_job_statuses(): array
{
    return [
        ORANGE_RESTORE_FW_STATUS_CANCELLED,
        ORANGE_RESTORE_FW_STATUS_FAILED,
        ORANGE_RESTORE_FW_STATUS_COMPLETED,
        ORANGE_RESTORE_FW_STATUS_RESTORE_COMPLETED,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_COMPLETED,
        ORANGE_RESTORE_FW_STATUS_EXECUTION_COMPLETED,
        ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED,
        ORANGE_RESTORE_FW_STATUS_EXECUTION_FAILED,
    ];
}

/**
 * @return list<string>
 */
function orange_restore_center_approved_script_paths(): array
{
    return array_values(array_unique(array_merge(
        orange_restore_approved_production_mutation_cli_workers(),
        orange_restore_approved_non_mutation_restore_clis()
    )));
}

function orange_restore_center_assert_worker_key(string $workerKey): string
{
    $catalog = orange_restore_center_worker_catalog();
    if (!isset($catalog[$workerKey])) {
        throw new RuntimeException('restore_center_unknown_worker');
    }
    $rel = $catalog[$workerKey];
    $approved = orange_restore_center_approved_script_paths();
    $testCatalog = isset($GLOBALS['orange_restore_center_test_worker_catalog'])
        && is_array($GLOBALS['orange_restore_center_test_worker_catalog'])
        ? $GLOBALS['orange_restore_center_test_worker_catalog']
        : [];
    $isDisposableTestWorker = isset($testCatalog[$workerKey]) && $testCatalog[$workerKey] === $rel;
    if (!$isDisposableTestWorker && !in_array($rel, $approved, true)) {
        throw new RuntimeException('restore_center_worker_not_allowlisted');
    }

    return $rel;
}

/**
 * Resolve absolute path to an allowlisted restore CLI script under project root.
 * Test-only absolute override via $GLOBALS['orange_restore_center_test_worker_absolute']
 * (disposable harness workers outside Production catalog paths).
 */
function orange_restore_center_resolve_worker_script(string $projectRoot, string $relativeScript): string
{
    $absMap = isset($GLOBALS['orange_restore_center_test_worker_absolute'])
        && is_array($GLOBALS['orange_restore_center_test_worker_absolute'])
        ? $GLOBALS['orange_restore_center_test_worker_absolute']
        : [];
    if (isset($absMap[$relativeScript]) && is_string($absMap[$relativeScript]) && $absMap[$relativeScript] !== '') {
        $abs = $absMap[$relativeScript];
        $realAbs = realpath($abs);
        if ($realAbs === false || !is_file($realAbs)) {
            throw new RuntimeException('restore_center_worker_script_missing');
        }

        return $realAbs;
    }

    $projectRoot = realpath($projectRoot) ?: $projectRoot;
    $script = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeScript);
    if (!is_file($script)) {
        throw new RuntimeException('restore_center_worker_script_missing');
    }
    $realScript = realpath($script);
    if ($realScript === false || !is_file($realScript)) {
        throw new RuntimeException('restore_center_worker_script_missing');
    }
    $normalized = str_replace('\\', '/', $realScript);
    $suffix = str_replace('\\', '/', $relativeScript);
    if (!str_ends_with($normalized, $suffix)) {
        throw new RuntimeException('restore_center_worker_script_path_rejected');
    }

    return $realScript;
}

function orange_restore_center_safe_worker_token(string $workerKey): string
{
    return preg_replace('/[^a-z0-9_]+/i', '_', $workerKey) ?: 'worker';
}

function orange_restore_center_worker_log_path(string $workRoot, string $jobId, string $workerKey): string
{
    $dir = orange_restore_fw_job_directory($workRoot, $jobId);

    return $dir . DIRECTORY_SEPARATOR . 'orchestrator_' . orange_restore_center_safe_worker_token($workerKey) . '.log';
}

function orange_restore_center_worker_run_claim_path(string $workRoot, string $jobId, string $workerKey): string
{
    $dir = orange_restore_fw_job_directory($workRoot, $jobId);

    return $dir . DIRECTORY_SEPARATOR . 'orchestrator_' . orange_restore_center_safe_worker_token($workerKey) . '.run.json';
}

function orange_restore_center_worker_mutex_path(string $workRoot, string $jobId, string $workerKey): string
{
    $dir = orange_restore_fw_job_directory($workRoot, $jobId);

    return $dir . DIRECTORY_SEPARATOR . 'orchestrator_' . orange_restore_center_safe_worker_token($workerKey) . '.mutex';
}

function orange_restore_center_worker_launch_cmd_path(string $workRoot, string $jobId, string $workerKey): string
{
    $dir = orange_restore_fw_job_directory($workRoot, $jobId);

    return $dir . DIRECTORY_SEPARATOR . 'orchestrator_' . orange_restore_center_safe_worker_token($workerKey) . '_launch.cmd';
}

/**
 * True when launch.cmd is a prior bare-php / pre-fix artifact that must not be reused.
 * Fresh absolute launches (attempt marker + php.exe, no bare "php") are kept for forensics.
 */
function orange_restore_center_launch_artifact_is_stale_bare(string $launchPath): bool
{
    if (!is_file($launchPath)) {
        return false;
    }
    $body = (string) @file_get_contents($launchPath);
    if ($body === '') {
        return true;
    }
    if (preg_match('/\\"php\\"\\s+/', $body) === 1 || preg_match('/^"php"\s+/m', $body) === 1) {
        return true;
    }
    if (!str_contains($body, 'orange_restore_launch_attempt=')) {
        return true;
    }
    if (preg_match('/"[A-Za-z]:\\\\[^"]*php\\.exe"/i', $body) !== 1
        && preg_match('/php\\.exe/i', $body) !== 1) {
        return true;
    }

    return false;
}

/**
 * Remove a non-running bare/stale launch.cmd left by a prior failed attempt so retry cannot reuse it.
 * Never deletes claim/PID files. Never deletes a fresh absolute regenerated launch.
 */
function orange_restore_center_discard_stale_launch_artifact(
    string $workRoot,
    string $jobId,
    string $workerKey
): bool {
    $launchPath = orange_restore_center_worker_launch_cmd_path($workRoot, $jobId, $workerKey);
    if (!is_file($launchPath)) {
        return false;
    }
    if (!orange_restore_center_launch_artifact_is_stale_bare($launchPath)) {
        return false;
    }
    $job = null;
    try {
        $job = orange_restore_fw_read($workRoot, $jobId);
    } catch (Throwable $e) {
        $job = null;
    }
    $claim = orange_restore_center_reconcile_run_claim($workRoot, $jobId, $workerKey, is_array($job) ? $job : []);
    if ($claim !== null && orange_restore_center_claim_blocks_schedule($claim, is_array($job) ? $job : [], $workerKey)) {
        return false;
    }
    $removed = @unlink($launchPath);
    if ($removed) {
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_center_stale_launch_artifact_discarded',
            'result' => 'ok',
            'worker' => $workerKey,
            'orchestrator_version' => ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION,
        ]);
    }

    return $removed;
}

function orange_restore_center_process_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('orange_restore_lock_process_alive')) {
        return orange_restore_lock_process_alive($pid);
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }

    return false;
}

function orange_restore_center_can_probe_process_liveness(): bool
{
    if (function_exists('posix_kill')) {
        return true;
    }
    if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('shell_exec', $disabled, true);
    }

    return false;
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_center_read_run_claim(string $claimPath): ?array
{
    if (!is_file($claimPath)) {
        return null;
    }
    $raw = (string) @file_get_contents($claimPath);
    if ($raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $payload
 */
function orange_restore_center_write_run_claim(string $claimPath, array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('restore_center_run_claim_encode_failed');
    }
    if (@file_put_contents($claimPath, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('restore_center_run_claim_write_failed');
    }
}

function orange_restore_center_clear_run_claim(string $claimPath, string $reason = 'released'): void
{
    if (!is_file($claimPath)) {
        return;
    }
    $existing = orange_restore_center_read_run_claim($claimPath);
    if (is_array($existing)) {
        $existing['state'] = 'released';
        $existing['released_at'] = gmdate('c');
        $existing['release_reason'] = $reason;
        try {
            orange_restore_center_write_run_claim($claimPath, $existing);
        } catch (Throwable $e) {
            // fall through to unlink
        }
    }
    @unlink($claimPath);
}

/**
 * Server-side stage gate (orchestration only). Throws restore_center_invalid_stage.
 *
 * @param array<string, mixed> $job
 */
function orange_restore_center_assert_worker_stage_allowed(array $job, string $workerKey): void
{
    $status = (string) ($job['status'] ?? '');
    if ($status === '' || in_array($status, orange_restore_center_terminal_job_statuses(), true)) {
        throw new RuntimeException('restore_center_invalid_stage');
    }
    $map = orange_restore_center_worker_schedulable_statuses_map();
    $allowed = $map[$workerKey] ?? [];
    if ($allowed === [] || !in_array($status, $allowed, true)) {
        throw new RuntimeException('restore_center_invalid_stage');
    }
}

/**
 * Whether an existing claim must block a new schedule (job status + PID, not PID alone).
 *
 * @param array<string, mixed>|null $claim
 * @param array<string, mixed> $job
 */
function orange_restore_center_claim_blocks_schedule(?array $claim, array $job, string $workerKey): bool
{
    if ($claim === null) {
        return false;
    }
    if ((string) ($claim['state'] ?? 'running') === 'released') {
        return false;
    }

    $status = (string) ($job['status'] ?? '');
    $inflightMap = orange_restore_center_worker_inflight_statuses_map();
    $schedMap = orange_restore_center_worker_schedulable_statuses_map();
    $inflight = $inflightMap[$workerKey] ?? [];
    $schedulable = $schedMap[$workerKey] ?? [];
    $pid = (int) ($claim['pid'] ?? 0);
    $alive = orange_restore_center_process_alive($pid);
    $startedAt = strtotime((string) ($claim['started_at'] ?? ''));
    $age = $startedAt === false ? 0 : (time() - $startedAt);
    $inInflight = in_array($status, $inflight, true);
    $inSchedulable = in_array($status, $schedulable, true);

    // Stage finished / moved on — claim must not block indefinitely.
    if (!$inInflight && !$inSchedulable) {
        return false;
    }

    $failedMap = orange_restore_center_worker_dispatch_failure_status_map();
    $failedStatus = (string) ($failedMap[$workerKey] ?? '');
    // FAILED_BUT_ACTIVE_PUBLIC_STATE_01: terminal failed must never stay blocked by a dead claim.
    if ($failedStatus !== '' && $status === $failedStatus && !$alive) {
        return false;
    }

    if ($alive) {
        return true;
    }

    if ($inInflight) {
        if ($age >= ORANGE_RESTORE_CENTER_WORKER_LOCK_STALE_SECONDS) {
            return false;
        }
        if (!orange_restore_center_can_probe_process_liveness()) {
            return true;
        }

        // PID dead while status still in-flight: short grace, then allow recovery if status still permits.
        return $age < ORANGE_RESTORE_CENTER_CLAIM_TRANSITION_GRACE_SECONDS;
    }

    // Pending / entry only: brief grace against double-schedule right after spawn.
    // Do not apply grace for failed (retryable) — Owner must see requestable immediately.
    if ($failedStatus !== '' && $status === $failedStatus) {
        return false;
    }

    return $age < ORANGE_RESTORE_CENTER_CLAIM_TRANSITION_GRACE_SECONDS;
}

/**
 * Reconcile claim file with current job status. Clears completed/stale claims.
 *
 * @param array<string, mixed> $job
 * @return array<string, mixed>|null Active claim or null
 */
function orange_restore_center_reconcile_run_claim(
    string $workRoot,
    string $jobId,
    string $workerKey,
    array $job
): ?array {
    $claimPath = orange_restore_center_worker_run_claim_path($workRoot, $jobId, $workerKey);
    $claim = orange_restore_center_read_run_claim($claimPath);
    if ($claim === null) {
        return null;
    }
    if (orange_restore_center_claim_blocks_schedule($claim, $job, $workerKey)) {
        return $claim;
    }
    orange_restore_center_clear_run_claim($claimPath, 'reconciled_inactive');

    return null;
}

/**
 * @return array{handle:resource,path:string}
 */
function orange_restore_center_acquire_schedule_mutex(string $workRoot, string $jobId, string $workerKey): array
{
    $path = orange_restore_center_worker_mutex_path($workRoot, $jobId, $workerKey);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('restore_center_spawn_log_dir_failed');
    }
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('restore_center_mutex_open_failed');
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new RuntimeException('restore_center_worker_already_running');
    }

    return ['handle' => $handle, 'path' => $path];
}

/**
 * @param resource $handle
 */
function orange_restore_center_release_schedule_mutex($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * @param list<string> $command
 * @return array{pid:int,launch_cmd:string}
 */
function orange_restore_center_spawn_detached_windows(
    array $command,
    string $logPath,
    string $launchCmdPath
): array {
    $phpBinary = (string) $command[0];
    $script = (string) $command[1];
    $jobArg = (string) $command[2];

    // Atomic replacement: write temp then rename so retries never reuse a prior bare-php launch.cmd.
    $cmdBody = '@echo off' . "\r\n"
        . 'setlocal' . "\r\n"
        . 'rem orange_restore_launch_attempt=' . gmdate('YmdHis') . "\r\n"
        . escapeshellarg($phpBinary) . ' '
        . escapeshellarg($script) . ' '
        . escapeshellarg($jobArg)
        . ' <NUL >>' . escapeshellarg($logPath) . ' 2>&1' . "\r\n"
        . 'exit /B %ERRORLEVEL%' . "\r\n";
    $tmpLaunch = $launchCmdPath . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmpLaunch, $cmdBody) === false) {
        throw new RuntimeException('restore_center_spawn_launch_cmd_failed');
    }
    if (!@rename($tmpLaunch, $launchCmdPath)) {
        @unlink($launchCmdPath);
        if (!@rename($tmpLaunch, $launchCmdPath)) {
            @unlink($tmpLaunch);
            throw new RuntimeException('restore_center_spawn_launch_cmd_failed');
        }
    }

    $ps = '$ErrorActionPreference = \'Stop\'; '
        . '$p = Start-Process -FilePath ' . orange_restore_center_powershell_quote($launchCmdPath)
        . ' -WindowStyle Hidden -PassThru; '
        . 'if ($null -eq $p -or [int]$p.Id -le 0) { exit 1 }; '
        . 'Write-Output ([int]$p.Id); '
        . 'exit 0';

    $output = [];
    $exitCode = 1;
    $psBinary = orange_restore_center_resolve_powershell_binary();
    exec(
        escapeshellarg($psBinary) . ' -NoProfile -NonInteractive -Command ' . escapeshellarg($ps),
        $output,
        $exitCode
    );
    $pid = isset($output[0]) ? (int) trim((string) $output[0]) : 0;
    if ($exitCode !== 0 || $pid <= 0) {
        throw new RuntimeException('restore_center_spawn_failed');
    }
    usleep(150000);
    // On Windows, Start-Process may return a short-lived launcher PID while the worker
    // cmd/php continues. Accept launch if the worker log was created/written.
    if (!orange_restore_center_process_alive($pid)) {
        $logStarted = is_file($logPath) && filesize($logPath) > 0;
        if (!$logStarted) {
            // Brief grace for log flush.
            usleep(200000);
            $logStarted = is_file($logPath) && filesize($logPath) > 0;
        }
        if (!$logStarted) {
            throw new RuntimeException('restore_center_spawn_failed');
        }
    }

    return ['pid' => $pid, 'launch_cmd' => $launchCmdPath];
}

function orange_restore_center_powershell_quote(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

/**
 * Classify detached worker log bootstrap failures (safe codes only — no path leakage).
 */
function orange_restore_center_classify_worker_log_bootstrap(string $logPath): string
{
    if ($logPath === '' || !is_file($logPath)) {
        return '';
    }
    $size = (int) @filesize($logPath);
    if ($size <= 0) {
        return '';
    }
    $raw = (string) @file_get_contents($logPath);
    if ($raw === '') {
        return '';
    }
    // Windows cmd when launch binary is bare "php" / missing from PATH.
    if (preg_match('/is not recognized as an internal or external command/i', $raw) === 1
        || preg_match('/not recognized as an internal or external command/i', $raw) === 1
        || preg_match('/command not found/i', $raw) === 1
        || preg_match('/No such file or directory/i', $raw) === 1) {
        return 'restore_center_worker_executable_unavailable';
    }
    if (preg_match('/CODE:\s*STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE/i', $raw) === 1
        || preg_match('/access denied/i', $raw) === 1) {
        return ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE;
    }
    if (preg_match('/SHADOW_RESTORE_RESULT:\s*FAIL/i', $raw) === 1
        || preg_match('/CODE:\s*STEP7_SHADOW_DB_TARGET_UNAVAILABLE/i', $raw) === 1
        || preg_match('/ORANGE_RESTORE_STAGING_DB/i', $raw) === 1
        || preg_match('/ORANGE_RESTORE_SHADOW_DB/i', $raw) === 1
        || preg_match('/is not configured/i', $raw) === 1) {
        return ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE;
    }

    return '';
}

/**
 * Normalize orchestration exception codes for audit + operator surface.
 */
function orange_restore_center_normalize_failure_code(string $code): string
{
    $code = trim($code);
    if ($code === 'php_cli_binary_unavailable') {
        return 'restore_center_worker_executable_unavailable';
    }

    return $code !== '' ? $code : 'restore_center_spawn_failed';
}

function orange_restore_center_resolve_powershell_binary(): string
{
    foreach (orange_backup_powershell_known_paths() as $candidate) {
        $path = orange_backup_normalize_tool_path((string) $candidate);
        if ($path !== '' && is_file($path)) {
            return $path;
        }
    }

    return 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
}

/**
 * @param list<string> $command
 * @return array{pid:int}
 */
function orange_restore_center_spawn_detached_unix(array $command, string $logPath): array
{
    $phpBinary = (string) $command[0];
    $script = (string) $command[1];
    $jobArg = (string) $command[2];

    $cmdline = escapeshellarg($phpBinary) . ' '
        . escapeshellarg($script) . ' '
        . escapeshellarg($jobArg)
        . ' </dev/null >>' . escapeshellarg($logPath) . ' 2>&1 & echo $!';

    $output = [];
    $exitCode = 1;
    exec('nohup ' . $cmdline, $output, $exitCode);
    $pid = isset($output[0]) ? (int) trim((string) $output[0]) : 0;
    if ($exitCode !== 0 || $pid <= 0) {
        throw new RuntimeException('restore_center_spawn_failed');
    }
    usleep(100000);
    if (!orange_restore_center_process_alive($pid)) {
        throw new RuntimeException('restore_center_spawn_failed');
    }

    return ['pid' => $pid];
}

/**
 * @param list<string> $command
 * @return array{pid:int,launch_cmd?:string}
 */
function orange_restore_center_spawn_detached(
    array $command,
    string $logPath,
    string $workRoot = '',
    string $jobId = '',
    string $workerKey = ''
): array {
    if (count($command) < 3) {
        throw new RuntimeException('restore_center_spawn_invalid_command');
    }

    $logDir = dirname($logPath);
    if (!is_dir($logDir) && !@mkdir($logDir, 0775, true) && !is_dir($logDir)) {
        throw new RuntimeException('restore_center_spawn_log_dir_failed');
    }

    $jobArg = (string) $command[2];
    if (!str_starts_with($jobArg, '--job=')) {
        throw new RuntimeException('restore_center_spawn_job_arg_rejected');
    }

    if (PHP_OS_FAMILY === 'Windows') {
        if ($workRoot === '' || $jobId === '' || $workerKey === '') {
            throw new RuntimeException('restore_center_spawn_invalid_command');
        }
        $launchCmd = orange_restore_center_worker_launch_cmd_path($workRoot, $jobId, $workerKey);

        return orange_restore_center_spawn_detached_windows($command, $logPath, $launchCmd);
    }

    return orange_restore_center_spawn_detached_unix($command, $logPath);
}

/**
 * Guided Restore Center worker key from authoritative job status.
 * Used by current-stage diagnostic authority (never invent a richer older stage).
 */
function orange_restore_center_guided_worker_key_from_status(string $status): string
{
    $status = trim($status);
    if ($status === '') {
        return '';
    }
    if (str_starts_with($status, 'pre_restore_backup_')) {
        return 'pre_backup';
    }
    if (str_starts_with($status, 'shadow_restore_')) {
        return 'shadow_db';
    }
    if ($status === ORANGE_RESTORE_FW_STATUS_SHADOW_NOT_READY
        || $status === ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED
        || str_starts_with($status, 'shadow_verify')) {
        return 'shadow_verify';
    }
    if (str_starts_with($status, 'shadow_files_')) {
        return 'shadow_files';
    }
    if (str_starts_with($status, 'shadow_smoke_')
        || str_starts_with($status, 'cutover_readiness_')) {
        return 'shadow_smoke';
    }
    if (str_starts_with($status, 'production_import_')
        || $status === ORANGE_RESTORE_FW_STATUS_MAINTENANCE_ACTIVE) {
        return 'production_import';
    }
    if (str_starts_with($status, 'uploads_cutover_')) {
        return 'uploads_cutover';
    }
    if (str_starts_with($status, 'rollback_')) {
        return 'rollback';
    }
    if (str_starts_with($status, 'restore_final')
        || $status === ORANGE_RESTORE_FW_STATUS_RESTORE_FINALIZING) {
        return 'finalize';
    }

    return '';
}

/**
 * Approved Audit event names per guided worker (canonical names only).
 *
 * @return array<string, list<string>>
 */
function orange_restore_center_stage_audit_event_family_map(): array
{
    return [
        'pre_backup' => [
            'pre_restore_backup_requested',
            'pre_restore_backup_running',
            'pre_restore_backup_started',
            'pre_restore_backup_completed',
            'pre_restore_backup_failed',
            'pre_restore_backup_ready',
        ],
        'shadow_db' => [
            'shadow_restore_requested',
            'shadow_restore_started',
            'shadow_restore_failed',
            'shadow_restore_ready',
            'shadow_restore_verification_started',
            'shadow_restore_verification_failed',
            'shadow_restore_verification_passed',
            'restore_center_worker_schedule_failed',
            'restore_center_worker_scheduled',
            'restore_center_pending_without_worker_compensated',
            'restore_center_dispatch_compensated',
        ],
        'shadow_verify' => [
            'restore_center_worker_schedule_failed',
            'restore_center_worker_scheduled',
        ],
        'shadow_files' => [
            'restore_center_worker_schedule_failed',
            'restore_center_worker_scheduled',
        ],
        'shadow_smoke' => [
            'restore_center_worker_schedule_failed',
            'restore_center_worker_scheduled',
        ],
        'production_import' => [
            'restore_center_worker_schedule_failed',
            'restore_center_worker_scheduled',
        ],
        'uploads_cutover' => [
            'restore_center_worker_schedule_failed',
            'restore_center_worker_scheduled',
        ],
        'rollback' => [
            'restore_center_worker_schedule_failed',
            'restore_center_worker_scheduled',
        ],
        'finalize' => [
            'restore_center_worker_schedule_failed',
            'restore_center_worker_scheduled',
        ],
    ];
}

/**
 * Map orchestration / request failures to Step-7 owner-safe codes.
 */
function orange_restore_center_step7_classify_start_failure(string $code): string
{
    $code = trim($code);
    return match (true) {
        $code === 'invalid_status'
            || $code === 'restore_center_invalid_stage'
            || str_starts_with($code, 'illegal_framework_status_transition') => ORANGE_RESTORE_STEP7_WRONG_STATE,
        $code === 'restore_center_worker_already_running' => ORANGE_RESTORE_STEP7_ACTIVE_ATTEMPT_EXISTS,
        $code === 'restore_center_mutex_open_failed' => ORANGE_RESTORE_STEP7_MUTEX_CONFLICT,
        $code === 'Restore work root unavailable.'
            || $code === 'restore_work_root_unavailable' => ORANGE_RESTORE_STEP7_WORK_ROOT_NOT_READY,
        $code === 'restore_center_spawn_log_dir_failed' => ORANGE_RESTORE_STEP7_WORK_ROOT_NOT_WRITABLE,
        $code === 'restore_center_unknown_worker' => ORANGE_RESTORE_STEP7_WORKER_KEY_UNAVAILABLE,
        $code === 'restore_center_worker_script_missing'
            || $code === 'restore_center_worker_script_path_rejected' => ORANGE_RESTORE_STEP7_WORKER_ENTRYPOINT_UNAVAILABLE,
        $code === 'restore_center_worker_not_allowlisted' => ORANGE_RESTORE_STEP7_WORKER_ENTRYPOINT_NOT_ALLOWED,
        $code === 'php_cli_binary_unavailable'
            || $code === ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE => ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE,
        $code === 'restore_center_worker_executable_unavailable' => ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE,
        $code === 'restore_center_spawn_launch_cmd_failed' => ORANGE_RESTORE_STEP7_LAUNCH_ARTIFACT_FAILED,
        $code === 'restore_center_spawn_failed'
            || $code === 'restore_center_spawn_invalid_command'
            || $code === 'restore_center_spawn_job_arg_rejected' => ORANGE_RESTORE_STEP7_PROCESS_SPAWN_FAILED,
        $code === 'restore_center_worker_bootstrap_failed' => ORANGE_RESTORE_STEP7_WORKER_BOOTSTRAP_FAILED,
        $code === 'shadow_restore_lock_active' => ORANGE_RESTORE_STEP7_CLAIM_CONFLICT,
        $code === ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE
            || $code === 'shadow_db_capability_unavailable'
            || $code === 'shadow_db_create_failed'
            || preg_match('/\b(1044|1045|1142|1227)\b/', $code) === 1
            || preg_match('/access denied/i', $code) === 1 => ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE,
        $code === ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE
            || $code === 'shadow_db_target_unavailable'
            || str_contains($code, 'ORANGE_RESTORE_STAGING_DB')
            || str_contains($code, 'ORANGE_RESTORE_SHADOW_DB')
            || str_contains($code, 'is not configured') => ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE,
        str_starts_with($code, 'STEP7_PRIVATE_ENGINE_') => $code,
        str_starts_with($code, 'STEP7_') => $code,
        default => ORANGE_RESTORE_STEP7_UNKNOWN_START_FAILURE,
    };
}

/**
 * Step-7 owner Arabic for safe failure codes (no paths/commands/raw tokens).
 */
function orange_restore_center_step7_operator_reason_ar(string $safeCode): string
{
    $messages = [
        ORANGE_RESTORE_STEP7_WRONG_STATE => 'لا يمكن بدء استعادة قاعدة الظل من الحالة الحالية. حدّث الحالة ثم أعد المحاولة من نفس الخطوة.',
        ORANGE_RESTORE_STEP7_REQUEST_TRANSITION_FAILED => 'تعذر قبول طلب استعادة قاعدة الظل. أعد المحاولة من شاشة الاسترداد.',
        ORANGE_RESTORE_STEP7_ACTIVE_ATTEMPT_EXISTS => 'توجد محاولة أخرى نشطة لاستعادة قاعدة الظل لهذه المهمة. لن يُبدأ تنفيذ مكرر.',
        ORANGE_RESTORE_STEP7_CLAIM_CONFLICT => 'تعذر بدء استعادة قاعدة الظل بسبب تعارض قفل التنفيذ. انتظر ثم أعد المحاولة.',
        ORANGE_RESTORE_STEP7_MUTEX_CONFLICT => 'تعذر قفل بدء استعادة قاعدة الظل على الخادم. أعد المحاولة من مركز الاسترداد.',
        ORANGE_RESTORE_STEP7_WORK_ROOT_NOT_READY => 'تعذر تجهيز مجلد عمل المرحلة على الخادم.',
        ORANGE_RESTORE_STEP7_WORK_ROOT_NOT_WRITABLE => 'مجلد عمل المرحلة غير قابل للكتابة على الخادم.',
        ORANGE_RESTORE_STEP7_WORKER_KEY_UNAVAILABLE => 'مفتاح تشغيل مرحلة استعادة قاعدة الظل غير متاح.',
        ORANGE_RESTORE_STEP7_WORKER_ENTRYPOINT_UNAVAILABLE => 'تعذر العثور على ملف تشغيل المرحلة الداخلي.',
        ORANGE_RESTORE_STEP7_WORKER_ENTRYPOINT_NOT_ALLOWED => 'ملف تشغيل المرحلة الداخلي غير مسموح به في سياسة التشغيل.',
        ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE => 'منفذ PHP المطلوب لتشغيل المرحلة غير متاح. لم يبدأ أي تنفيذ، ويمكن إعادة المحاولة بعد إصلاح بيئة التشغيل.',
        ORANGE_RESTORE_STEP7_PROCESS_EXECUTION_UNAVAILABLE => 'تعذر إنشاء عملية العامل الداخلي على الخادم.',
        ORANGE_RESTORE_STEP7_LAUNCH_ARTIFACT_FAILED => 'تعذر تجهيز تشغيل المرحلة الداخلي على الخادم.',
        ORANGE_RESTORE_STEP7_PROCESS_SPAWN_FAILED => 'تعذر إنشاء عملية العامل الداخلي. لم يبدأ أي تنفيذ.',
        ORANGE_RESTORE_STEP7_WORKER_BOOTSTRAP_FAILED => 'تعذر إقلاع عامل استعادة قاعدة الظل بعد الجدولة. لم يُعتمد التنفيذ.',
        ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE => 'تعذر تجهيز هدف قاعدة الظل لهذه المهمة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE => 'هدف قاعدة الظل معروف، لكن صلاحية إنشاء/استخدام قاعدة الظل غير متاحة لحساب التطبيق. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_START_FAILED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_START_FAILED),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_SECRET_BOUNDARY_FAILED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_SECRET_BOUNDARY_FAILED),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NETWORK_POLICY_FAILED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NETWORK_POLICY_FAILED),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_USER_FAILED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_USER_FAILED),
        ORANGE_RESTORE_STEP7_UNKNOWN_START_FAILURE => 'تعذر بدء استعادة قاعدة الظل. أعد المحاولة من شاشة الاسترداد.',
    ];

    if (str_starts_with($safeCode, 'STEP7_PRIVATE_ENGINE_')) {
        return orange_restore_private_engine_operator_reason_ar($safeCode);
    }

    return $messages[$safeCode] ?? $messages[ORANGE_RESTORE_STEP7_UNKNOWN_START_FAILURE];
}

/**
 * Pre-spawn shadow DB target readiness (fail closed before process creation).
 *
 * @return array{ok:bool,code:string,attempt_id:string,readiness:array<string,mixed>}
 */
function orange_restore_center_shadow_pre_spawn_readiness(
    string $projectRoot,
    string $workRoot,
    string $jobId
): array {
    if (!function_exists('orange_restore_shadow_probe_target_readiness')) {
        require_once __DIR__ . '/restore_shadow_db.php';
    }
    $meta = orange_restore_shadow_load_meta($workRoot, $jobId) ?? [];
    $attemptId = trim((string) ($meta['attempt_id'] ?? ''));
    if ($attemptId === '') {
        $attemptId = 's7_' . bin2hex(random_bytes(8));
        $meta['attempt_id'] = $attemptId;
    }
    $env = orange_backup_load_env_array($projectRoot);
    if (isset($GLOBALS['orange_shadow_env_override']) && is_array($GLOBALS['orange_shadow_env_override'])) {
        $env = array_merge($env, $GLOBALS['orange_shadow_env_override']);
    }

    // Bind private-engine context for parent preflight/provision (NO Production CREATE DATABASE).
    $GLOBALS['orange_restore_private_engine_context'] = [
        'work_root' => $workRoot,
        'job_id' => $jobId,
    ];

    $resolved = orange_restore_shadow_resolve_target($env, $projectRoot, $jobId, $meta);
    if (!($resolved['ok'] ?? false) || trim((string) ($resolved['shadow_db'] ?? '')) === '') {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE,
            'attempt_id' => $attemptId,
            'readiness' => [
                'ok' => false,
                'code' => ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE,
                'source' => (string) ($resolved['source'] ?? 'unavailable'),
                'credential_mode' => '',
                'can_create' => false,
                'can_use' => false,
                'database_capability' => 'unavailable',
                'shadow_db_identity_hash' => '',
                'parent_worker_target_identity_match' => false,
                'private_engine' => true,
            ],
        ];
    }
    // Persist job-bound target BEFORE provision/spawn so worker consumes the same identity.
    try {
        $meta = orange_restore_shadow_bind_resolved_target($meta, $resolved, $jobId);
        orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);
    } catch (Throwable) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE,
            'attempt_id' => $attemptId,
            'readiness' => [
                'ok' => false,
                'code' => ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE,
                'source' => (string) ($resolved['source'] ?? 'unavailable'),
                'credential_mode' => '',
                'can_create' => false,
                'can_use' => false,
                'database_capability' => 'unavailable',
                'shadow_db_identity_hash' => (string) ($resolved['identity_hash'] ?? ''),
                'parent_worker_target_identity_match' => false,
                'private_engine' => true,
            ],
        ];
    }

    // Private-engine preflight/provision BEFORE claim/spawn (NO_PRODUCTION_MYSQL_PROVISIONING_01).
    $enginePid = 0;
    try {
        $provision = orange_restore_private_engine_provision(
            $projectRoot,
            $workRoot,
            $jobId,
            (string) $resolved['shadow_db']
        );
        if (empty($provision['ok']) || empty($provision['ready'])) {
            $pCode = (string) ($provision['code'] ?? ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED);
            if (!str_starts_with($pCode, 'STEP7_PRIVATE_ENGINE_')) {
                $pCode = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED;
            }

            return [
                'ok' => false,
                'code' => $pCode,
                'attempt_id' => $attemptId,
                'readiness' => [
                    'ok' => false,
                    'code' => $pCode,
                    'source' => (string) ($resolved['source'] ?? 'unavailable'),
                    'credential_mode' => 'private_shadow_engine',
                    'can_create' => false,
                    'can_use' => false,
                    'database_capability' => 'unavailable',
                    'shadow_db_identity_hash' => (string) ($resolved['identity_hash'] ?? ''),
                    'parent_worker_target_identity_match' => false,
                    'private_engine' => true,
                    'engine_pid' => (int) ($provision['engine_pid'] ?? 0),
                ],
            ];
        }
        $enginePid = (int) ($provision['engine_pid'] ?? 0);
        $meta['private_engine_ready'] = true;
        $meta['private_engine_pid'] = $enginePid;
        orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);
    } catch (Throwable $e) {
        $pCode = orange_restore_shadow_normalize_failure_code(trim($e->getMessage()));
        if (!str_starts_with($pCode, 'STEP7_PRIVATE_ENGINE_')) {
            $pCode = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED;
        }

        return [
            'ok' => false,
            'code' => $pCode,
            'attempt_id' => $attemptId,
            'readiness' => [
                'ok' => false,
                'code' => $pCode,
                'source' => (string) ($resolved['source'] ?? 'unavailable'),
                'credential_mode' => 'private_shadow_engine',
                'can_create' => false,
                'can_use' => false,
                'database_capability' => 'unavailable',
                'shadow_db_identity_hash' => (string) ($resolved['identity_hash'] ?? ''),
                'parent_worker_target_identity_match' => false,
                'private_engine' => true,
            ],
        ];
    }

    $probe = orange_restore_shadow_probe_target_readiness($projectRoot, $env, $jobId, $meta);
    $identity = (string) ($probe['shadow_db_identity_hash'] ?? ($meta['shadow_db_identity_hash'] ?? ''));
    $parentWorkerMatch = $identity !== ''
        && hash_equals($identity, (string) ($meta['shadow_db_identity_hash'] ?? ''));
    if (!($probe['ok'] ?? false)) {
        $failCode = (string) ($probe['code'] ?? ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY);
        if (!str_starts_with($failCode, 'STEP7_PRIVATE_ENGINE_')
            && $failCode !== ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE
            && $failCode !== ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE) {
            $failCode = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY;
        }

        return [
            'ok' => false,
            'code' => $failCode,
            'attempt_id' => $attemptId,
            'readiness' => [
                'ok' => false,
                'code' => $failCode,
                'source' => (string) ($probe['source'] ?? 'unavailable'),
                'credential_mode' => (string) ($probe['credential_mode'] ?? 'private_shadow_engine'),
                'can_create' => !empty($probe['can_create']),
                'can_use' => !empty($probe['can_use']),
                'database_capability' => (string) ($probe['database_capability'] ?? 'unavailable'),
                'shadow_db_identity_hash' => $identity,
                'parent_worker_target_identity_match' => $parentWorkerMatch,
                'private_engine' => true,
                'engine_pid' => $enginePid,
            ],
        ];
    }

    return [
        'ok' => true,
        'code' => 'ok',
        'attempt_id' => $attemptId,
        'engine_pid' => $enginePid,
        'readiness' => [
            'ok' => true,
            'code' => 'ok',
            'source' => (string) ($probe['source'] ?? ''),
            'credential_mode' => (string) ($probe['credential_mode'] ?? 'private_shadow_engine'),
            'can_create' => !empty($probe['can_create']),
            'can_use' => !empty($probe['can_use']),
            'database_capability' => (string) ($probe['database_capability'] ?? 'available'),
            'shadow_db_identity_hash' => $identity,
            'parent_worker_target_identity_match' => $parentWorkerMatch,
            'private_engine' => true,
            'engine_pid' => $enginePid,
            'ready_token' => ORANGE_RESTORE_STEP7_READY_FOR_CONTROLLED_ATTEMPT,
        ],
    ];
}

/**
 * Bounded wait for shadow worker bootstrap ack or terminal failure (Owner E/F).
 *
 * @return array{acked:bool,failed:bool,code:string,attempt_id:string}
 */
function orange_restore_center_await_shadow_bootstrap_ack(
    string $workRoot,
    string $jobId,
    string $attemptId,
    string $logPath,
    int $waitMs = ORANGE_RESTORE_CENTER_SHADOW_BOOTSTRAP_WAIT_MS
): array {
    if (!function_exists('orange_restore_shadow_load_bootstrap_ack')) {
        require_once __DIR__ . '/restore_shadow_db.php';
    }
    $deadline = microtime(true) + (max(200, $waitMs) / 1000.0);
    do {
        $ack = orange_restore_shadow_load_bootstrap_ack($workRoot, $jobId);
        if (is_array($ack) && !empty($ack['ready'])) {
            $ackAttempt = trim((string) ($ack['attempt_id'] ?? ''));
            if ($attemptId === '' || $ackAttempt === '' || hash_equals($attemptId, $ackAttempt)) {
                return [
                    'acked' => true,
                    'failed' => false,
                    'code' => 'ok',
                    'attempt_id' => $ackAttempt !== '' ? $ackAttempt : $attemptId,
                ];
            }
        }
        $bootFail = orange_restore_center_classify_worker_log_bootstrap($logPath);
        if ($bootFail !== '') {
            return [
                'acked' => false,
                'failed' => true,
                'code' => $bootFail,
                'attempt_id' => $attemptId,
            ];
        }
        usleep(ORANGE_RESTORE_CENTER_SHADOW_BOOTSTRAP_POLL_MS * 1000);
    } while (microtime(true) < $deadline);

    $bootFail = orange_restore_center_classify_worker_log_bootstrap($logPath);
    if ($bootFail !== '') {
        return [
            'acked' => false,
            'failed' => true,
            'code' => $bootFail,
            'attempt_id' => $attemptId,
        ];
    }

    return [
        'acked' => false,
        'failed' => true,
        'code' => ORANGE_RESTORE_STEP7_WORKER_BOOTSTRAP_FAILED,
        'attempt_id' => $attemptId,
    ];
}

/**
 * Operator-safe Arabic reason for orchestration codes (no paths/secrets).
 */
function orange_restore_center_operator_reason_ar(string $code, string $jobStatus = '', string $worker = ''): string
{
    if ($worker === 'shadow_db' || str_starts_with(trim($code), 'STEP7_')) {
        $safe = orange_restore_center_step7_classify_start_failure($code);

        return orange_restore_center_step7_operator_reason_ar($safe);
    }
    $messages = [
        'restore_center_invalid_stage' => 'لا يمكن بدء هذه المرحلة الآن من مركز الاسترداد. أكمل المتطلبات السابقة أو حدّث الحالة.',
        'restore_center_worker_already_running' => 'تنفيذ هذه المرحلة يعمل بالفعل لهذه المهمة. لن يُشغَّل مجدداً.',
        'restore_center_spawn_failed' => 'تعذر بدء عامل التنفيذ على الخادم. لم يبدأ أي تنفيذ، ويمكن إعادة المحاولة من شاشة الاسترداد.',
        'restore_center_worker_executable_unavailable' => 'تعذر بدء عامل التنفيذ لأن بيئة التشغيل على الخادم غير جاهزة لتشغيل العامل الداخلي. لم يبدأ أي تنفيذ، ويمكن إعادة المحاولة من شاشة الاسترداد بعد إصلاح البيئة.',
        'php_cli_binary_unavailable' => 'تعذر بدء عامل التنفيذ لأن بيئة التشغيل على الخادم غير جاهزة لتشغيل العامل الداخلي. لم يبدأ أي تنفيذ، ويمكن إعادة المحاولة من شاشة الاسترداد بعد إصلاح البيئة.',
        'restore_center_mutex_open_failed' => 'تعذر قفل بدء المرحلة على الخادم. أعد المحاولة من مركز الاسترداد.',
        'restore_center_spawn_launch_cmd_failed' => 'تعذر تجهيز التشغيل الداخلي على الخادم. أعد المحاولة من مركز الاسترداد.',
        'restore_center_unknown_worker' => 'مرحلة تنفيذ غير معروفة في قائمة التنسيق.',
        'restore_center_worker_not_allowlisted' => 'مرحلة التنفيذ غير مسموح بها في قائمة التنسيق.',
        'restore_center_worker_script_missing' => 'ملف عامل التنفيذ الداخلي غير متاح على الخادم.',
        'restore_center_spawn_log_dir_failed' => 'تعذر تجهيز سجلات التنسيق للمهمة على الخادم.',
    ];
    // Operator-safe: never append raw status tokens or CLI/path instructions.
    return $messages[$code] ?? 'تعذر بدء التنفيذ من مركز الاسترداد. أعد المحاولة من الشاشة.';
}

/**
 * After a metadata request that may have written pending: verify-schedule the worker or compensate.
 * Strips operator CLI handoff fields from the merged result.
 *
 * @param array<string, mixed> $requestResult
 * @return array<string, mixed>
 */
function orange_restore_center_attach_verified_schedule(
    string $projectRoot,
    string $workRoot,
    string $jobId,
    string $workerKey,
    string $operatorUsername,
    array $requestResult
): array {
    $statusBefore = (string) (($requestResult['job']['status'] ?? '') ?: '');
    $legacyCliNeeded = !empty($requestResult['cli_needed']);
    $pendingMap = orange_restore_center_worker_pending_status_map();
    $pendingStatus = $pendingMap[$workerKey] ?? '';
    $isPending = $pendingStatus !== '' && $statusBefore === $pendingStatus;
    $needsSchedule = $legacyCliNeeded || $isPending || str_ends_with($statusBefore, '_pending')
        || str_ends_with($statusBefore, '_finalizing');

    $requestResult['cli_needed'] = false;
    $requestResult['cli_command'] = '';
    $requestResult['operator_action_required'] = false;
    unset($requestResult['cli_command']);

    if (!$needsSchedule) {
        return $requestResult;
    }

    try {
        $scheduled = orange_restore_center_request_and_schedule(
            $projectRoot,
            $workRoot,
            $jobId,
            $workerKey,
            $operatorUsername
        );
    } catch (Throwable $e) {
        $code = trim($e->getMessage());
        if ($code === 'restore_center_worker_already_running') {
            $liveJob = orange_restore_fw_read($workRoot, $jobId);
            $liveStatus = (string) ($liveJob['status'] ?? '');
            $failedMap = orange_restore_center_worker_dispatch_failure_status_map();
            // Never promote a terminal failed/compensated stage into a false "scheduled" success.
            if ($workerKey === 'shadow_db'
                && isset($failedMap[$workerKey])
                && $liveStatus === $failedMap[$workerKey]
            ) {
                throw new RuntimeException(ORANGE_RESTORE_STEP7_UNKNOWN_START_FAILURE, 0, $e);
            }
            $requestResult['scheduled'] = true;
            $requestResult['detached'] = true;
            $requestResult['bootstrap_acked'] = true;
            $requestResult['job'] = orange_restore_fw_public_row($liveJob);
            $requestResult['message'] = 'التنفيذ يعمل بالفعل على الخادم.';

            return $requestResult;
        }
        throw $e;
    }

    $requestResult['scheduled'] = !empty($scheduled['scheduled']);
    $requestResult['detached'] = !empty($scheduled['detached']);
    $requestResult['pid'] = (int) ($scheduled['pid'] ?? 0);
    $requestResult['worker'] = $workerKey;
    $requestResult['bootstrap_acked'] = !empty($scheduled['bootstrap_acked'])
        || ($workerKey !== 'shadow_db' && !empty($scheduled['scheduled']));
    $requestResult['attempt_id'] = (string) ($scheduled['attempt_id'] ?? '');
    $requestResult['job'] = orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId));
    $requestResult['message'] = (string) ($scheduled['diagnostics']['reason_ar']
        ?? ($workerKey === 'shadow_db'
            ? 'تم بدء استعادة قاعدة الظل بعد تأكيد الإقلاع. يمكنك مغادرة الصفحة، وسيستمر التنفيذ.'
            : 'تم بدء التنفيذ على الخادم. يمكنك مغادرة الصفحة، وسيستمر التنفيذ.'));
    $requestResult['diagnostics'] = is_array($scheduled['diagnostics'] ?? null)
        ? $scheduled['diagnostics']
        : ['code' => 'ok', 'reason_ar' => $requestResult['message']];

    return $requestResult;
}

/**
 * Safe diagnostics for Restore Center (no absolute paths, no secrets).
 * Current-stage authority: latest attempt of the guided stage only.
 *
 * @return array<string, mixed>
 */
function orange_restore_center_diagnostics(string $workRoot, string $jobId): array
{
    // Refresh-only public-state reconcile (no new Step-7 attempt).
    $reconciled = orange_restore_center_reconcile_stale_shadow_restore_public_state($workRoot, $jobId);
    $job = is_array($reconciled) ? $reconciled : orange_restore_fw_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');
    $guidedWorker = orange_restore_center_guided_worker_key_from_status($status);
    $familyMap = orange_restore_center_stage_audit_event_family_map();
    $guidedFamily = $guidedWorker !== '' && isset($familyMap[$guidedWorker])
        ? $familyMap[$guidedWorker]
        : [];

    $workers = [];
    foreach (array_keys(orange_restore_center_worker_catalog()) as $workerKey) {
        $claim = orange_restore_center_reconcile_run_claim($workRoot, $jobId, $workerKey, $job);
        $schedulable = false;
        try {
            orange_restore_center_assert_worker_stage_allowed($job, $workerKey);
            $schedulable = true;
        } catch (Throwable $e) {
            $schedulable = false;
        }
        $blocking = $claim !== null && orange_restore_center_claim_blocks_schedule($claim, $job, $workerKey);
        $workers[] = [
            'worker' => $workerKey,
            'schedulable_now' => $schedulable && !$blocking,
            'claim_active' => $blocking,
            'claim_state' => is_array($claim) ? (string) ($claim['state'] ?? 'running') : 'none',
            'started_at' => is_array($claim) ? (string) ($claim['started_at'] ?? '') : '',
            'log_name' => 'orchestrator_' . orange_restore_center_safe_worker_token($workerKey) . '.log',
        ];
    }

    $currentStageEvents = [];
    $historicalEvents = [];
    $latestCurrent = null;
    $step7AttemptCount = 0;
    $attemptClusters = [];
    $auditPath = orange_restore_fw_audit_file_path($workRoot, $jobId);
    if (is_file($auditPath)) {
        $lines = @file($auditPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            $slice = array_slice($lines, -160);
            foreach (array_reverse($slice) as $line) {
                $row = json_decode((string) $line, true);
                if (!is_array($row)) {
                    continue;
                }
                $event = (string) ($row['event'] ?? '');
                if ($event === '') {
                    continue;
                }
                $at = (string) ($row['recorded_at'] ?? $row['at'] ?? $row['timestamp'] ?? '');
                $rowWorker = trim((string) ($row['worker'] ?? ''));

                $itemWorker = '';
                $isCurrentFamily = false;
                $reasonAr = '';
                $code = '';

                if (in_array($event, $familyMap['pre_backup'] ?? [], true)) {
                    $itemWorker = 'pre_backup';
                    $rawCode = (string) ($row['code'] ?? '');
                    $category = (string) ($row['failure_category'] ?? '');
                    if ($category === '' && str_starts_with($rawCode, 'illegal_framework_status_transition:')) {
                        $category = 'retry_state_conflict';
                    }
                    if ($category === '' && $rawCode !== '') {
                        $category = function_exists('orange_restore_pre_backup_public_failure_code')
                            ? orange_restore_pre_backup_public_failure_code($rawCode)
                            : $rawCode;
                    }
                    $code = $category !== '' ? $category : $event;
                    $reasonAr = match ($event) {
                        'pre_restore_backup_requested' => 'تم قبول طلب النسخة الاحتياطية الإلزامية ودخلت حالة الانتظار.',
                        'pre_restore_backup_running' => 'تم اعتماد انتقال التنفيذ إلى حالة التشغيل.',
                        'pre_restore_backup_started' => 'بدأ تنفيذ النسخة الاحتياطية عبر المحرك المعتمد.',
                        'pre_restore_backup_completed' => 'اكتمل إنشاء الحزمة ويجري/جرى التحقق والربط.',
                        'pre_restore_backup_ready' => 'أصبحت النسخة الاحتياطية الإلزامية جاهزة وآمنة للرجوع.',
                        'pre_restore_backup_failed' => ($category === 'retry_state_conflict'
                            ? 'تعذر بدء إعادة المحاولة لأن حالة المهمة الحالية تتعارض مع بدء تنفيذ جديد. حدّث الحالة ثم أعد المحاولة من نفس الخطوة.'
                            : 'فشلت محاولة النسخة الاحتياطية الإلزامية. يمكن إعادة المحاولة من مركز الاسترداد.'),
                        default => 'تحديث مرحلة النسخة الاحتياطية الإلزامية.',
                    };
                    $isCurrentFamily = ($guidedWorker === 'pre_backup');
                } elseif (str_starts_with($event, 'shadow_restore_')
                    || (
                        ($event === 'restore_center_worker_schedule_failed'
                            || $event === 'restore_center_worker_scheduled'
                            || $event === 'restore_center_pending_without_worker_compensated'
                            || $event === 'restore_center_dispatch_compensated')
                        && $rowWorker === 'shadow_db'
                    )
                ) {
                    $itemWorker = 'shadow_db';
                    $rawCode = (string) ($row['safe_failure_code'] ?? $row['code'] ?? $row['reason'] ?? '');
                    if ($event === 'restore_center_worker_scheduled') {
                        $code = 'ok';
                        $reasonAr = 'تم جدولة استعادة قاعدة الظل بنجاح.';
                    } elseif ($event === 'shadow_restore_requested') {
                        $code = 'shadow_restore_requested';
                        $reasonAr = 'تم قبول طلب استعادة قاعدة الظل ودخلت حالة الانتظار.';
                    } elseif ($event === 'shadow_restore_started') {
                        $code = 'shadow_restore_started';
                        $reasonAr = 'بدأ تنفيذ استعادة قاعدة الظل بعد تأكيد الإقلاع.';
                    } elseif ($event === 'shadow_restore_ready') {
                        $code = 'shadow_restore_ready';
                        $reasonAr = 'اكتملت استعادة قاعدة الظل وأصبحت جاهزة للتحقق.';
                    } elseif ($event === 'shadow_restore_failed'
                        || $event === 'restore_center_worker_schedule_failed'
                        || $event === 'restore_center_pending_without_worker_compensated'
                        || $event === 'restore_center_dispatch_compensated') {
                        $internal = $rawCode !== '' ? $rawCode : 'restore_center_spawn_failed';
                        $safe = orange_restore_center_step7_classify_start_failure($internal);
                        $code = $safe;
                        $reasonAr = orange_restore_center_step7_operator_reason_ar($safe);
                    } else {
                        $code = $rawCode !== '' ? $rawCode : $event;
                        $reasonAr = 'تحديث مرحلة استعادة قاعدة الظل.';
                    }
                    $isCurrentFamily = ($guidedWorker === 'shadow_db');
                } elseif ($event === 'restore_center_worker_schedule_failed'
                    || $event === 'restore_center_worker_scheduled'
                    || $event === 'restore_center_pending_without_worker_compensated'
                    || $event === 'restore_center_dispatch_compensated') {
                    $itemWorker = $rowWorker !== '' ? $rowWorker : 'unknown';
                    $code = (string) ($row['safe_failure_code'] ?? $row['code']
                        ?? ($event === 'restore_center_worker_scheduled' ? 'ok' : 'restore_center_spawn_failed'));
                    $reasonAr = $code === 'ok'
                        ? 'تم جدولة المرحلة بنجاح.'
                        : orange_restore_center_operator_reason_ar($code, $status, $itemWorker);
                    $isCurrentFamily = ($guidedWorker !== '' && $itemWorker === $guidedWorker
                        && in_array($event, $guidedFamily, true));
                }

                if ($itemWorker === '') {
                    continue;
                }

                $item = [
                    'event' => $event,
                    'result' => (string) ($row['result'] ?? ''),
                    'worker' => $itemWorker,
                    'code' => $code,
                    'reason_ar' => $reasonAr,
                    'at' => $at,
                    'is_step6_attempt' => $itemWorker === 'pre_backup',
                    'is_step7_attempt' => $itemWorker === 'shadow_db',
                    'historical_only' => !$isCurrentFamily,
                ];
                if (!empty($row['safe_failure_code'])) {
                    $item['safe_failure_code'] = (string) $row['safe_failure_code'];
                } elseif ($itemWorker === 'shadow_db' && str_starts_with((string) $code, 'STEP7_')) {
                    $item['safe_failure_code'] = (string) $code;
                }

                // Refresh / nested-status reconcile is never an Owner attempt.
                if ($event === 'restore_center_stale_shadow_restore_status_reconciled'
                    || !empty($row['refresh_only'])) {
                    $item['is_step7_attempt'] = false;
                    $item['refresh_only'] = true;
                }

                if ($isCurrentFamily) {
                    if ($latestCurrent === null
                        && $event !== 'restore_center_stale_shadow_restore_status_reconciled'
                        && empty($row['refresh_only'])) {
                        $latestCurrent = $item;
                    }
                    if (count($currentStageEvents) < 12) {
                        $currentStageEvents[] = $item;
                    }
                } else {
                    if (count($historicalEvents) < 12) {
                        $historicalEvents[] = $item;
                    }
                }
            }
        }
    }

    // Dedup Step-7 attempt clusters: requested + schedule_failed + compensated = one attempt.
    // Refresh / nested-status reconcile events never open a cluster.
    $attemptClusters = [];
    $clusterSeq = 0;
    $openCluster = null;
    foreach (array_reverse($currentStageEvents) as $evRow) {
        if (($evRow['worker'] ?? '') !== 'shadow_db' || !empty($evRow['refresh_only'])) {
            continue;
        }
        $evName = (string) ($evRow['event'] ?? '');
        if ($evName === 'restore_center_stale_shadow_restore_status_reconciled') {
            continue;
        }
        if ($evName === 'shadow_restore_requested') {
            $clusterSeq++;
            $openCluster = $clusterSeq;
            $attemptClusters[$openCluster] = [
                'attempt_index' => $openCluster,
                'events' => [],
                'safe_failure_code' => '',
                'result' => '',
            ];
        } elseif ($openCluster === null) {
            // Trailing compensate/fail without a request opener — attach to prior attempt if any.
            if ($clusterSeq === 0) {
                $clusterSeq++;
                $attemptClusters[$clusterSeq] = [
                    'attempt_index' => $clusterSeq,
                    'events' => [],
                    'safe_failure_code' => '',
                    'result' => '',
                ];
            }
            $openCluster = $clusterSeq;
        }
        if ($openCluster === null) {
            continue;
        }
        $attemptClusters[$openCluster]['events'][] = $evName;
        if (!empty($evRow['safe_failure_code'])) {
            $attemptClusters[$openCluster]['safe_failure_code'] = (string) $evRow['safe_failure_code'];
        }
        if ((string) ($evRow['result'] ?? '') !== '') {
            $attemptClusters[$openCluster]['result'] = (string) $evRow['result'];
        }
        // Close after terminal outcome; subsequent compensate in same cluster still appends above
        // until the next shadow_restore_requested opens a new cluster.
        if (in_array($evName, [
            'shadow_restore_failed',
            'restore_center_worker_scheduled',
            'shadow_restore_ready',
            'restore_center_pending_without_worker_compensated',
        ], true)) {
            $openCluster = null;
        }
    }
    $step7AttemptCount = count($attemptClusters);

    // Current stage first; prior stages remain labeled historical. Never delete history.
    $recent = array_slice(array_merge($currentStageEvents, $historicalEvents), 0, 16);

    if ($latestCurrent === null && $guidedWorker !== '') {
        $latestCurrent = [
            'event' => '',
            'result' => '',
            'worker' => $guidedWorker,
            'code' => 'missing_current_attempt',
            'reason_ar' => 'تعذر العثور على تفاصيل المحاولة الحالية في سجل التشغيل.',
            'at' => '',
            'missing_current_attempt' => true,
            'is_step6_attempt' => $guidedWorker === 'pre_backup',
            'is_step7_attempt' => $guidedWorker === 'shadow_db',
            'historical_only' => false,
        ];
    }

    $logSnippets = [];
    foreach (array_keys(orange_restore_center_worker_catalog()) as $workerKey) {
        $logPath = orange_restore_center_worker_log_path($workRoot, $jobId, $workerKey);
        if (!is_file($logPath)) {
            continue;
        }
        $size = (int) @filesize($logPath);
        $raw = '';
        if ($size > 0) {
            $fh = @fopen($logPath, 'rb');
            if (is_resource($fh)) {
                $start = max(0, $size - ORANGE_RESTORE_CENTER_DIAG_LOG_TAIL_BYTES);
                if ($start > 0) {
                    fseek($fh, $start);
                }
                $raw = (string) stream_get_contents($fh);
                fclose($fh);
            }
        }
        $text = function_exists('orange_backup_admin_redact_text')
            ? orange_backup_admin_redact_text($raw)
            : $raw;
        // Strip absolute path-like segments for operator safety.
        $text = (string) preg_replace('#[A-Za-z]:\\\\[^\s\'"]+#', '[path]', $text);
        $text = (string) preg_replace('#/(?:var|home|usr|opt|httpdocs|inetpub)[^\s\'"]+#i', '[path]', $text);
        // RAW_ENVIRONMENT_NAME_EXPOSURE_01 — never surface env key names to Owner diagnostics.
        $text = (string) preg_replace('/ORANGE_RESTORE_[A-Z0-9_]+/', '[env]', $text);
        $text = (string) preg_replace('/\bDB_(?:NAME|USER|PASS|HOST)\b/', '[env]', $text);
        $text = (string) preg_replace('/\.env\.php/', '[config]', $text);
        $logSnippets[] = [
            'worker' => $workerKey,
            'log_name' => 'orchestrator_' . orange_restore_center_safe_worker_token($workerKey) . '.log',
            'tail' => trim($text) !== '' ? trim($text) : '(empty)',
        ];
    }

    $shadowReadiness = null;
    $readyToken = '';
    if ($guidedWorker === 'shadow_db') {
        try {
            if (!function_exists('orange_restore_shadow_probe_target_readiness')) {
                require_once __DIR__ . '/restore_shadow_db.php';
            }
            $projectRootDiag = dirname(__DIR__, 3);
            $meta = orange_restore_shadow_load_meta($workRoot, $jobId) ?? [];
            $env = orange_backup_load_env_array($projectRootDiag);
            // Zero-mutation: private-engine preflight (no provision) + target resolve only.
            $resolved = orange_restore_shadow_resolve_target($env, $projectRootDiag, $jobId, $meta);
            $enginePub = orange_restore_private_engine_public_readiness($projectRootDiag, $workRoot, $jobId);
            $ack = orange_restore_shadow_load_bootstrap_ack($workRoot, $jobId);
            $pub = orange_restore_fw_public_row($job);
            $requestable = !empty($pub['shadow_restore_requestable']);
            $running = in_array($status, [
                ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
                ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
                ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_VERIFYING,
            ], true);
            $identity = (string) ($enginePub['shadow_db_identity_hash']
                ?? ($resolved['identity_hash'] ?? ''));
            $boundHash = trim((string) ($meta['shadow_db_identity_hash'] ?? ''));
            $parentWorkerMatch = $identity !== '' && ($boundHash === '' || hash_equals($identity, $boundHash));
            $targetOk = (bool) ($resolved['ok'] ?? false);
            $binaryOk = !empty($enginePub['binary_available']);
            $engineReady = !empty($enginePub['engine_ready']);
            $capability = $engineReady ? 'available' : 'unavailable';

            if (!$binaryOk) {
                $readyToken = '';
            } elseif ($engineReady && $targetOk && $requestable && !$running) {
                $readyToken = ORANGE_RESTORE_STEP7_READY_FOR_CONTROLLED_ATTEMPT;
            } elseif ($binaryOk && $targetOk && $requestable && !$running) {
                $readyToken = ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING;
            } else {
                $readyToken = (string) ($enginePub['ready_token'] ?? '');
            }

            $allGreen = $readyToken === ORANGE_RESTORE_STEP7_READY_FOR_CONTROLLED_ATTEMPT;
            $shadowReadiness = [
                'ok' => $allGreen,
                'code' => $allGreen
                    ? 'ok'
                    : (string) (!$binaryOk
                        ? ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE
                        : (!$targetOk
                            ? ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE
                            : ($engineReady
                                ? 'ok'
                                : ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING))),
                'source' => (string) ($resolved['source'] ?? 'unavailable'),
                'shadow_db_identity_hash' => $identity !== ''
                    ? $identity
                    : (string) ($resolved['identity_hash'] ?? ''),
                'attempt_id' => (string) ($meta['attempt_id'] ?? ''),
                'bootstrap_acked' => is_array($ack) && !empty($ack['ready']),
                'requestable' => $requestable,
                'execution_running' => $running,
                'database_capability' => $capability,
                'can_create' => $engineReady,
                'can_use' => $engineReady,
                'credential_mode' => $engineReady ? 'private_shadow_engine' : '',
                'parent_worker_target_identity_match' => $parentWorkerMatch || $boundHash === '',
                'ready_for_controlled_step7_attempt' => $allGreen,
                'ready_for_private_shadow_provisioning' => $readyToken
                    === ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING,
                'ready_token' => $readyToken,
                'private_engine' => $enginePub,
                'read_only' => true,
            ];
        } catch (Throwable) {
            $shadowReadiness = [
                'ok' => false,
                'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE,
                'source' => 'unavailable',
                'shadow_db_identity_hash' => '',
                'attempt_id' => '',
                'bootstrap_acked' => false,
                'requestable' => !empty(orange_restore_fw_public_row($job)['shadow_restore_requestable']),
                'execution_running' => false,
                'database_capability' => 'unavailable',
                'can_create' => false,
                'can_use' => false,
                'credential_mode' => '',
                'parent_worker_target_identity_match' => false,
                'ready_for_controlled_step7_attempt' => false,
                'ready_for_private_shadow_provisioning' => false,
                'ready_token' => '',
                'read_only' => true,
            ];
        }
    }

    // Owner diagnostic dedup: one row per attempt_id + stage + safe category.
    $dedupedRecent = [];
    $seenDiagKeys = [];
    foreach ($recent as $evRow) {
        if (!is_array($evRow)) {
            continue;
        }
        $dedupKey = strtolower(trim((string) ($evRow['attempt_id'] ?? '')))
            . '|' . strtolower(trim((string) ($evRow['worker'] ?? '')))
            . '|' . strtolower(trim((string) ($evRow['safe_failure_code'] ?? ($evRow['code'] ?? ''))))
            . '|' . strtolower(trim((string) ($evRow['event'] ?? '')));
        if ($dedupKey !== '|||' && isset($seenDiagKeys[$dedupKey])) {
            continue;
        }
        if ($dedupKey !== '|||') {
            $seenDiagKeys[$dedupKey] = 1;
        }
        $dedupedRecent[] = $evRow;
    }

    return [
        'job_id' => $jobId,
        'job_status' => $status,
        'shadow_restore_status' => (string) ($job['shadow_restore_status'] ?? ''),
        'guided_stage_worker' => $guidedWorker,
        'package_type' => (string) ($job['package_type'] ?? ''),
        'orchestrator_version' => ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION,
        'workers' => $workers,
        'recent_orchestration_events' => $dedupedRecent,
        'latest_attempt_diagnostic' => $latestCurrent,
        'step7_attempt_count' => $guidedWorker === 'shadow_db' ? $step7AttemptCount : 0,
        'step7_attempt_clusters' => $guidedWorker === 'shadow_db' ? array_values($attemptClusters) : [],
        'step7_shadow_target_readiness' => $shadowReadiness,
        'ready_for_controlled_step7_attempt' => $readyToken === ORANGE_RESTORE_STEP7_READY_FOR_CONTROLLED_ATTEMPT,
        'ready_for_private_shadow_provisioning' => $readyToken
            === ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING,
        'ready_token' => $readyToken,
        'log_tails' => $logSnippets,
        'notes_ar' => [
            'تشخيص تشغيل مراحل الاسترداد — عرض تشغيلي آمن من مركز الاسترداد فقط.',
            'لا تُعرض مسارات الخادم المطلقة ولا الأسرار ولا أسماء قواعد البيانات ولا مفاتيح البيئة.',
            'أحدث محاولة تخص المرحلة الحالية حسب حالة المهمة؛ أحداث المراحل السابقة تُعرض كتاريخية فقط.',
            'يُرفض التنفيذ إذا كانت حالة المهمة لا تسمح بالمرحلة أو إذا كانت المرحلة تعمل.',
            'تحديث الحالة (Refresh) لا يُحسب محاولة جديدة لخطوة استعادة قاعدة الظل.',
            'قسم جاهزية هدف قاعدة الظل للقراءة فقط ولا ينشئ محاولة جديدة ولا يشغّل المحرك الخاص.',
            'READY_FOR_PRIVATE_SHADOW_PROVISIONING يظهر عندما يكون محرك الظل الخاص قابلاً للاكتشاف وقبل التجهيز.',
            'READY_FOR_CONTROLLED_STEP7_ATTEMPT يظهر فقط بعد جاهزية المحرك الخاص وهدف قاعدة الظل.',
        ],
    ];
}

/**
 * Schedule an approved restore CLI worker (--job= only). Returns immediately after verified launch.
 *
 * @return array{
 *   ok:bool,
 *   detached:bool,
 *   scheduled:bool,
 *   worker:string,
 *   script:string,
 *   pid:int,
 *   job_status:string,
 *   message:string,
 *   diagnostics:array<string,mixed>
 * }
 */
function orange_restore_center_run_worker(
    string $projectRoot,
    string $workRoot,
    string $jobId,
    string $workerKey,
    string $operatorUsername = ''
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    if ($jobId === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $jobId)) {
        throw new RuntimeException('invalid_job_id');
    }
    if (!function_exists('orange_restore_admin_assert_fw_job_allowlisted')) {
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'restore_admin.php';
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);

    $relative = '';
    $mutexHandle = null;
    $pid = 0;
    $jobStatus = '';
    $attemptId = '';

    try {
        $relative = orange_restore_center_assert_worker_key($workerKey);
        $script = orange_restore_center_resolve_worker_script($projectRoot, $relative);
        $resolve = orange_restore_worker_runtime_resolve($projectRoot);
        if (empty($resolve['ok']) || trim((string) ($resolve['php_binary'] ?? '')) === '') {
            throw new RuntimeException('php_cli_binary_unavailable');
        }
        $phpBinary = trim((string) $resolve['php_binary']);
        // Absolute contract required. is_file may be false under open_basedir for trusted sibling layout;
        // spawn layer distinguishes PROCESS_SPAWN_FAILED / WORKER_BOOTSTRAP_FAILED thereafter.
        if (!orange_restore_worker_runtime_path_is_absolute($phpBinary)) {
            throw new RuntimeException('restore_center_worker_executable_unavailable');
        }
        if (!is_file($phpBinary)
            && ($resolve['accepted_via'] ?? '') !== 'cgi_sibling_trust'
            && ($resolve['accepted_via'] ?? '') !== 'cgi_sibling_layout_trust'
            && ($resolve['accepted_via'] ?? '') !== 'sapi_probe_without_is_file') {
            throw new RuntimeException('restore_center_worker_executable_unavailable');
        }
        $logPath = orange_restore_center_worker_log_path($workRoot, $jobId, $workerKey);
        $claimPath = orange_restore_center_worker_run_claim_path($workRoot, $jobId, $workerKey);

        $mutex = orange_restore_center_acquire_schedule_mutex($workRoot, $jobId, $workerKey);
        $mutexHandle = $mutex['handle'];

        // Atomic critical section: read job → stage validate → claim reconcile → spawn → claim write.
        $job = orange_restore_fw_read($workRoot, $jobId);
        $jobStatus = (string) ($job['status'] ?? '');
        orange_restore_center_assert_worker_stage_allowed($job, $workerKey);

        $active = orange_restore_center_reconcile_run_claim($workRoot, $jobId, $workerKey, $job);
        if ($active !== null && orange_restore_center_claim_blocks_schedule($active, $job, $workerKey)) {
            throw new RuntimeException('restore_center_worker_already_running');
        }

        // Retry must never reuse a prior failed attempt's launch.cmd (bare "php" leftover).
        orange_restore_center_discard_stale_launch_artifact($workRoot, $jobId, $workerKey);

        $attemptId = '';
        $privateEnginePid = 0;
        if ($workerKey === 'shadow_db') {
            // Fail closed BEFORE spawn: private-engine provision + readiness proven.
            $pre = orange_restore_center_shadow_pre_spawn_readiness($projectRoot, $workRoot, $jobId);
            $attemptId = (string) ($pre['attempt_id'] ?? '');
            $privateEnginePid = (int) ($pre['engine_pid'] ?? ($pre['readiness']['engine_pid'] ?? 0));
            if (empty($pre['ok'])) {
                $preCode = (string) ($pre['code'] ?? ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED);
                if (!str_starts_with($preCode, 'STEP7_PRIVATE_ENGINE_')
                    && $preCode !== ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE
                    && $preCode !== ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE) {
                    $preCode = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED;
                }
                throw new RuntimeException($preCode);
            }
            // Clear stale bootstrap ack from a previous attempt.
            $ackPath = function_exists('orange_restore_shadow_bootstrap_ack_path')
                ? orange_restore_shadow_bootstrap_ack_path($workRoot, $jobId)
                : '';
            if ($ackPath !== '' && is_file($ackPath)) {
                @unlink($ackPath);
            }
        }

        $spawned = orange_restore_center_spawn_detached(
            [$phpBinary, $script, '--job=' . $jobId],
            $logPath,
            $workRoot,
            $jobId,
            $workerKey
        );
        $pid = (int) ($spawned['pid'] ?? 0);
        $alive = $pid > 0 && orange_restore_center_process_alive($pid);
        if (!$alive) {
            // Windows launcher PID can exit while worker continues; spawn_detached already
            // accepted a non-empty log. Re-classify bootstrap failures before generic spawn_failed.
            $bootCode = orange_restore_center_classify_worker_log_bootstrap($logPath);
            if ($bootCode !== '') {
                throw new RuntimeException($bootCode);
            }
            $logOk = is_file($logPath) && (int) @filesize($logPath) > 0;
            if (!$logOk) {
                throw new RuntimeException('restore_center_spawn_failed');
            }
            // Log present without executable-missing markers: accept short-lived launcher PID.
        }

        $bootstrapAcked = false;
        if ($workerKey === 'shadow_db') {
            // Owner E/F: never record scheduled/success after child terminal failure;
            // "started" only after bootstrap readiness ack.
            $await = orange_restore_center_await_shadow_bootstrap_ack(
                $workRoot,
                $jobId,
                $attemptId,
                $logPath
            );
            if (!empty($await['failed']) || empty($await['acked'])) {
                throw new RuntimeException(
                    (string) ($await['code'] ?? ORANGE_RESTORE_STEP7_WORKER_BOOTSTRAP_FAILED)
                );
            }
            $bootstrapAcked = true;
            $attemptId = (string) ($await['attempt_id'] ?? $attemptId);
        }

        $claim = [
            'job_id' => $jobId,
            'worker' => $workerKey,
            'script' => $relative,
            'pid' => $pid,
            'php_worker_pid' => $pid,
            'private_engine_pid' => $workerKey === 'shadow_db' ? $privateEnginePid : 0,
            'state' => 'running',
            'started_at' => gmdate('c'),
            'job_status_at_schedule' => $jobStatus,
            'operator_username' => $operatorUsername,
            'log_name' => 'orchestrator_' . orange_restore_center_safe_worker_token($workerKey) . '.log',
            'orchestrator_version' => ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION,
            'detached' => true,
            'attempt_id' => $attemptId,
            'bootstrap_acked' => $bootstrapAcked,
        ];
        orange_restore_center_write_run_claim($claimPath, $claim);

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_center_worker_scheduled',
            'result' => 'ok',
            'worker' => $workerKey,
            'script' => $relative,
            'detached' => true,
            'pid' => $pid,
            'job_status' => $jobStatus,
            'operator_username' => $operatorUsername,
            'orchestrator_version' => ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION,
            'attempt_id' => $attemptId,
            'bootstrap_acked' => $bootstrapAcked,
        ]);
    } catch (Throwable $e) {
        $code = orange_restore_center_normalize_failure_code(trim($e->getMessage()));
        if ($code !== 'restore_center_worker_already_running') {
            try {
                if ($jobStatus === '') {
                    $peek = orange_restore_fw_read($workRoot, $jobId);
                    $jobStatus = (string) ($peek['status'] ?? '');
                }
            } catch (Throwable $ignored) {
                // keep prior jobStatus
            }
            // Prefer log-derived executable failure over generic spawn_failed when present.
            try {
                $logPathCatch = orange_restore_center_worker_log_path($workRoot, $jobId, $workerKey);
                $bootCode = orange_restore_center_classify_worker_log_bootstrap($logPathCatch);
                if ($bootCode !== '' && ($code === 'restore_center_spawn_failed' || $code === '')) {
                    $code = $bootCode;
                }
            } catch (Throwable $ignoredBoot) {
                // keep prior code
            }
            $failAudit = [
                'event' => 'restore_center_worker_schedule_failed',
                'result' => 'fail',
                'worker' => $workerKey,
                'script' => $relative,
                'detached' => true,
                'code' => $code,
                'job_status' => $jobStatus,
                'operator_username' => $operatorUsername,
                'orchestrator_version' => ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION,
            ];
            if ($workerKey === 'shadow_db') {
                $safeStep7 = orange_restore_center_step7_classify_start_failure($code);
                $failAudit['safe_failure_code'] = $safeStep7;
                $failAudit['stage'] = 'shadow_restore';
                $failAudit['scheduling_attempted'] = true;
                $failAudit['spawn_succeeded'] = false;
                $failAudit['execution_started'] = false;
            }
            if ($code === 'restore_center_worker_executable_unavailable'
                    || $code === 'php_cli_binary_unavailable'
                    || ($failAudit['safe_failure_code'] ?? '') === ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE) {
                // Restore-only categories — no raw paths (Owner UI must stay path-free).
                $failAudit['resolve_diag'] = orange_restore_worker_runtime_safe_diag($projectRoot);
            }
            orange_restore_fw_audit_append($workRoot, $jobId, $failAudit);
            // Never leave unconsumed pending after a failed schedule attempt.
            orange_restore_center_compensate_unconsumed_pending(
                $workRoot,
                $jobId,
                $workerKey,
                $code
            );
            // Remove only bare/stale launch leftovers — never a fresh absolute regenerated launch.
            if ($code === 'restore_center_worker_executable_unavailable'
                || $code === 'restore_center_spawn_failed'
                || $code === 'restore_center_worker_bootstrap_failed'
                || $code === ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE
                || $code === ORANGE_RESTORE_STEP7_WORKER_BOOTSTRAP_FAILED) {
                try {
                    orange_restore_center_discard_stale_launch_artifact($workRoot, $jobId, $workerKey);
                } catch (Throwable $ignoredDiscard) {
                    // non-fatal cleanup
                }
            }
        }
        $safeThrow = $workerKey === 'shadow_db'
            ? orange_restore_center_step7_classify_start_failure($code)
            : $code;
        if (trim($e->getMessage()) !== $safeThrow) {
            throw new RuntimeException($safeThrow, 0, $e);
        }
        throw $e;
    } finally {
        if ($mutexHandle !== null) {
            orange_restore_center_release_schedule_mutex($mutexHandle);
        }
    }

    return [
        'ok' => true,
        'detached' => true,
        'scheduled' => true,
        'bootstrap_acked' => $workerKey !== 'shadow_db' ? true : true,
        'attempt_id' => isset($attemptId) ? (string) $attemptId : '',
        'worker' => $workerKey,
        'script' => $relative,
        'pid' => $pid,
        'job_status' => $jobStatus,
        'cli_needed' => false,
        'cli_command' => '',
        'operator_action_required' => false,
        'message' => 'Worker scheduled on server. Continues independently of the browser.',
        'diagnostics' => [
            'code' => 'ok',
            'reason_ar' => $workerKey === 'shadow_db'
                ? 'تم بدء استعادة قاعدة الظل بعد تأكيد الإقلاع. يمكنك مغادرة الصفحة، وسيستمر التنفيذ.'
                : 'تم بدء التنفيذ على الخادم. يمكنك مغادرة الصفحة، وسيستمر التنفيذ.',
            'job_status' => $jobStatus,
            'worker' => $workerKey,
        ],
    ];
}

/**
 * Shared Restore Center contract: schedule allowlisted worker from a valid stage status.
 * Workers self-request from entry statuses — HTTP must not leave pending without a consumer.
 * Prefer calling this from a single Admin click (no operator CLI handoff).
 *
 * @return array<string, mixed>
 */
function orange_restore_center_request_and_schedule(
    string $projectRoot,
    string $workRoot,
    string $jobId,
    string $workerKey,
    string $operatorUsername = ''
): array {
    return orange_restore_center_run_worker(
        $projectRoot,
        $workRoot,
        $jobId,
        $workerKey,
        $operatorUsername
    );
}
