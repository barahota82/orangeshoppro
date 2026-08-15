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
require_once __DIR__ . '/restore_private_sql_import_policy.php';
require_once __DIR__ . '/restore_private_engine_trace.php';

const ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION = '3B.4-rc-orchestrator-v10-attempt-contract';
const ORANGE_RESTORE_CENTER_WORKER_LOCK_STALE_SECONDS = 21600;
const ORANGE_RESTORE_CENTER_CLAIM_TRANSITION_GRACE_SECONDS = 120;
/** Active-attempt class F: own pending after request must never look like a foreign ACTIVE. */
const ORANGE_RESTORE_STEP7_ACTIVE_CLASS_OWN_PENDING_FALSE_POSITIVE = 'F';
/** Active-attempt class A: blocking claim with live/grace semantics. */
const ORANGE_RESTORE_STEP7_ACTIVE_CLASS_CLAIM_BLOCKS = 'A';
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
    // Step-7 diagnostics must never reach restore_lock.php:147 unbounded tasklist.
    if (!empty($GLOBALS['orange_restore_diagnostic_forbid_process_spawn'])) {
        $live = orange_restore_center_diagnostic_pid_liveness($pid);
        if ($live === 'alive') {
            return true;
        }
        if ($live === 'dead') {
            return false;
        }

        // unknown (Windows without spawn): conservative busy/alive — never invent READY.
        return true;
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
 * Step-7 diagnostic-only PID probe — never shell_exec/tasklist (restore_lock.php:147 hang).
 * posix_kill(0) only when available; otherwise unknown (fail closed upstream).
 *
 * @return 'alive'|'dead'|'unknown'
 */
function orange_restore_center_diagnostic_pid_liveness(int $pid): string
{
    if ($pid <= 0) {
        return 'unknown';
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0) ? 'alive' : 'dead';
    }

    // Windows / no posix: never spawn tasklist from diagnostics (Owner P0 restore_lock L147).
    return 'unknown';
}

/**
 * Read-only claim busy check for diagnostics — no process_alive / no claim clear.
 *
 * @param array<string, mixed>|null $claim
 */
function orange_restore_center_diagnostic_claim_busy_readonly(?array $claim): bool
{
    if ($claim === null) {
        return false;
    }
    if ((string) ($claim['state'] ?? 'running') === 'released') {
        return false;
    }
    $pid = (int) ($claim['pid'] ?? 0);
    if ($pid <= 0) {
        // Metadata present without PID: treat as busy (never invent READY).
        return true;
    }
    $live = orange_restore_center_diagnostic_pid_liveness($pid);
    if ($live === 'dead') {
        return false;
    }

    // alive OR unknown (Windows without spawn): busy / not READY.
    return true;
}

/**
 * Diagnostic-only attempt context — never calls orange_restore_lock_process_alive / tasklist
 * and never runs job-scoped PowerShell process enumeration.
 *
 * @return array<string, mixed>
 */
function orange_restore_center_diagnostic_attempt_context_readonly(string $workRoot, string $jobId): array
{
    $job = [];
    try {
        $job = orange_restore_fw_read($workRoot, $jobId);
    } catch (Throwable) {
        $job = [];
    }
    $status = (string) ($job['status'] ?? '');
    $inflight = in_array($status, [
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_VERIFYING,
    ], true);
    $terminalFailed = $status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED;
    $terminalReady = $status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY;

    $claimBlocks = false;
    $phpClass = ORANGE_RESTORE_STEP7_PROC_METADATA_ABSENT_LEGACY;
    $claimPresent = false;
    if (function_exists('orange_restore_center_worker_run_claim_path')
        && function_exists('orange_restore_center_read_run_claim')) {
        $claimPath = orange_restore_center_worker_run_claim_path($workRoot, $jobId, 'shadow_db');
        $claim = is_file($claimPath) ? orange_restore_center_read_run_claim($claimPath) : null;
        if (is_array($claim)) {
            $claimPresent = true;
            $pid = (int) ($claim['pid'] ?? 0);
            $state = (string) ($claim['state'] ?? '');
            if ($state === 'released' || $pid <= 0) {
                $phpClass = $pid <= 0 && $state !== 'released'
                    ? ORANGE_RESTORE_STEP7_PROC_INSPECTION_UNAVAILABLE
                    : ORANGE_RESTORE_STEP7_PROC_METADATA_ABSENT_LEGACY;
                $claimBlocks = orange_restore_center_diagnostic_claim_busy_readonly($claim);
            } elseif (function_exists('orange_restore_private_engine_bounded_pid_inspect')
                && function_exists('orange_restore_private_engine_liveness_class_from_pid_inspect')) {
                $phpInspect = orange_restore_private_engine_bounded_pid_inspect($pid, [
                    'expect_name_regex' => '^(php)(\\.exe)?$',
                    'expect_cmdline_substrings' => [strtolower($jobId)],
                ]);
                $phpClass = orange_restore_private_engine_liveness_class_from_pid_inspect(
                    (string) ($phpInspect['status'] ?? ORANGE_RESTORE_PE_PID_UNKNOWN),
                    false
                );
                if ($phpClass === ORANGE_RESTORE_STEP7_PROC_MATCHED_ACTIVE) {
                    $claimBlocks = true;
                } elseif ($phpClass === ORANGE_RESTORE_STEP7_PROC_MATCHED_TERMINAL_OR_DEAD
                    || $phpClass === ORANGE_RESTORE_STEP7_PROC_PID_IDENTITY_MISMATCH) {
                    $claimBlocks = false;
                } else {
                    // UNKNOWN / INSPECTION_UNAVAILABLE: fail closed (busy / not READY).
                    $claimBlocks = true;
                    if ($phpClass !== ORANGE_RESTORE_STEP7_PROC_INSPECTION_UNAVAILABLE) {
                        $phpClass = ORANGE_RESTORE_STEP7_PROC_INSPECTION_UNAVAILABLE;
                    }
                }
            } else {
                $live = orange_restore_center_diagnostic_pid_liveness($pid);
                if ($live === 'alive') {
                    $phpClass = ORANGE_RESTORE_STEP7_PROC_MATCHED_ACTIVE;
                    $claimBlocks = true;
                } elseif ($live === 'dead') {
                    $phpClass = ORANGE_RESTORE_STEP7_PROC_MATCHED_TERMINAL_OR_DEAD;
                    $claimBlocks = false;
                } else {
                    // Windows: unknown without tasklist — lock-busy / not READY (never spawn).
                    $phpClass = ORANGE_RESTORE_STEP7_PROC_INSPECTION_UNAVAILABLE;
                    $claimBlocks = true;
                }
            }
        }
    }

    $dbClass = ORANGE_RESTORE_STEP7_PROC_METADATA_ABSENT_LEGACY;
    $state = function_exists('orange_restore_private_engine_load_state')
        ? orange_restore_private_engine_load_state($workRoot, $jobId)
        : null;
    $enginePid = is_array($state) ? (int) ($state['engine_pid'] ?? 0) : 0;
    $healthy = function_exists('orange_restore_private_engine_runtime_healthy')
        && orange_restore_private_engine_runtime_healthy($workRoot, $jobId);
    if ($enginePid > 0) {
        if (function_exists('orange_restore_private_engine_bounded_pid_inspect')
            && function_exists('orange_restore_private_engine_liveness_class_from_pid_inspect')) {
            $dbInspect = orange_restore_private_engine_bounded_pid_inspect($enginePid, [
                'expect_name_regex' => '^(mysqld|mariadbd)(\\.exe)?$',
                'expect_cmdline_substrings' => [
                    strtolower($jobId),
                    defined('ORANGE_RESTORE_PRIVATE_ENGINE_DIRNAME')
                        ? strtolower((string) ORANGE_RESTORE_PRIVATE_ENGINE_DIRNAME)
                        : 'private_shadow_engine',
                ],
            ]);
            $dbClass = orange_restore_private_engine_liveness_class_from_pid_inspect(
                (string) ($dbInspect['status'] ?? ORANGE_RESTORE_PE_PID_UNKNOWN),
                $healthy
            );
            if ($dbClass === ORANGE_RESTORE_STEP7_PROC_INSPECTION_UNAVAILABLE && $healthy) {
                // Port/PDO proves engine service without unbounded tasklist.
                $dbClass = ORANGE_RESTORE_STEP7_PROC_MATCHED_ACTIVE;
            }
        } else {
            $live = orange_restore_center_diagnostic_pid_liveness($enginePid);
            if ($live === 'alive') {
                $dbClass = ORANGE_RESTORE_STEP7_PROC_MATCHED_ACTIVE;
            } elseif ($live === 'dead') {
                $dbClass = ORANGE_RESTORE_STEP7_PROC_MATCHED_TERMINAL_OR_DEAD;
            } elseif ($healthy) {
                // Port/PDO proves engine service without tasklist.
                $dbClass = ORANGE_RESTORE_STEP7_PROC_MATCHED_ACTIVE;
            } else {
                $dbClass = ORANGE_RESTORE_STEP7_PROC_INSPECTION_UNAVAILABLE;
            }
        }
    } elseif (is_array($state) && !empty($state['ready']) && $healthy) {
        $dbClass = ORANGE_RESTORE_STEP7_PROC_MATCHED_ACTIVE;
    }

    // Claim absent + terminal/non-inflight: PHP worker absence is job-scoped (no spawn required).
    if (!$claimPresent && !$inflight
        && $phpClass === ORANGE_RESTORE_STEP7_PROC_METADATA_ABSENT_LEGACY) {
        $phpClass = ORANGE_RESTORE_STEP7_PROC_NO_JOB_SCOPED_FOUND;
    }
    if (!$claimPresent && !$inflight
        && $enginePid <= 0
        && $dbClass === ORANGE_RESTORE_STEP7_PROC_METADATA_ABSENT_LEGACY) {
        $dbClass = ORANGE_RESTORE_STEP7_PROC_NO_JOB_SCOPED_FOUND;
    }

    $nonActiveProven = [
        ORANGE_RESTORE_STEP7_PROC_NO_JOB_SCOPED_FOUND,
        ORANGE_RESTORE_STEP7_PROC_MATCHED_TERMINAL_OR_DEAD,
        ORANGE_RESTORE_STEP7_PROC_PID_IDENTITY_MISMATCH,
        ORANGE_RESTORE_STEP7_PROC_PID_REUSED,
        ORANGE_RESTORE_STEP7_PROC_EXISTS_OTHER_JOB,
    ];
    $phpActive = $phpClass === ORANGE_RESTORE_STEP7_PROC_MATCHED_ACTIVE;
    $dbActive = $dbClass === ORANGE_RESTORE_STEP7_PROC_MATCHED_ACTIVE;
    $activeAttempt = $claimBlocks || ($inflight && $phpActive) || $phpActive;

    $phpAbsenceProven = !$phpActive && !$activeAttempt
        && in_array($phpClass, $nonActiveProven, true)
        && ($terminalFailed || $terminalReady || !$claimPresent || !$claimBlocks);
    $processAbsenceProven = $phpAbsenceProven
        && !$dbActive
        && in_array($dbClass, $nonActiveProven, true);

    $engineHealthyOwned = $dbActive
        && ($terminalFailed || $terminalReady || !$inflight)
        && !$phpActive
        && !$claimBlocks
        && (is_array($state) && (!empty($state['ready']) || !empty($state['datadir_job_owned'])));
    $engineServiceState = ORANGE_RESTORE_ENGINE_ABSENT;
    if ($activeAttempt && $dbActive) {
        $engineServiceState = ORANGE_RESTORE_ENGINE_IN_USE_BY_ACTIVE_ATTEMPT;
    } elseif ($engineHealthyOwned) {
        $engineServiceState = ORANGE_RESTORE_ENGINE_READY_IDLE;
    } elseif ($dbClass === ORANGE_RESTORE_STEP7_PROC_MATCHED_TERMINAL_OR_DEAD
        && is_array($state) && !empty($state['datadir_job_owned'])) {
        $engineServiceState = ORANGE_RESTORE_ENGINE_STOPPED_OWNED;
    } elseif ($dbClass === ORANGE_RESTORE_STEP7_PROC_INSPECTION_UNAVAILABLE
        || $dbClass === ORANGE_RESTORE_STEP7_PROC_EVIDENCE_CONTRADICTORY
        || $dbClass === ORANGE_RESTORE_STEP7_PROC_METADATA_ABSENT_LEGACY) {
        $engineServiceState = ORANGE_RESTORE_ENGINE_OWNERSHIP_UNKNOWN;
    } elseif ($dbActive && $inflight) {
        $engineServiceState = ORANGE_RESTORE_ENGINE_STARTING;
    } elseif (is_array($state) && !empty($state['terminal_failure'])) {
        $engineServiceState = ORANGE_RESTORE_ENGINE_FAILED;
    }

    if ($phpActive || $activeAttempt) {
        $absenceConclusion = ORANGE_RESTORE_STEP7_ABSENCE_ACTIVE;
    } elseif ($engineServiceState === ORANGE_RESTORE_ENGINE_READY_IDLE && $phpAbsenceProven) {
        $absenceConclusion = ORANGE_RESTORE_STEP7_ABSENCE_PROVEN;
    } elseif ($processAbsenceProven) {
        $absenceConclusion = ORANGE_RESTORE_STEP7_ABSENCE_PROVEN;
    } else {
        $absenceConclusion = ORANGE_RESTORE_STEP7_ABSENCE_NOT_PROVABLE;
    }

    $phpCompat = $phpActive ? 'alive'
        : (in_array($phpClass, $nonActiveProven, true) ? 'inactive' : 'unknown');
    $dbCompat = $dbActive ? 'alive'
        : (in_array($dbClass, $nonActiveProven, true) ? 'inactive' : 'unknown');

    return [
        'active_attempt' => $activeAttempt,
        'latest_attempt_terminal' => $terminalFailed || $terminalReady,
        'php_worker_liveness' => $phpCompat,
        'private_db_liveness' => $dbCompat,
        'php_worker_liveness_class' => $phpClass,
        'private_db_liveness_class' => $dbClass,
        'php_worker_absence_proven' => $phpAbsenceProven,
        'process_absence_proven' => $processAbsenceProven
            || ($engineServiceState === ORANGE_RESTORE_ENGINE_READY_IDLE && $phpAbsenceProven),
        'process_absence_conclusion' => $absenceConclusion,
        'engine_service_state' => $engineServiceState,
        'engine_ready_idle' => $engineServiceState === ORANGE_RESTORE_ENGINE_READY_IDLE,
        'process_inspection_available' => function_exists('posix_kill')
            || function_exists('orange_restore_private_engine_bounded_pid_inspect')
            || (PHP_OS_FAMILY === 'Windows' && (function_exists('exec') || function_exists('proc_open'))),
        'claim_present' => $claimPresent,
        'claim_blocks' => $claimBlocks,
        'diagnostic_skip_process_spawn' => true,
    ];
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
    // Prefer exact CODE: when present — never collapse every FAIL to target-unavailable.
    if (preg_match('/CODE:\s*(STEP7_[A-Z0-9_]+)/i', $raw, $mCode) === 1) {
        return strtoupper((string) $mCode[1]);
    }
    if (preg_match('/forbidden pattern\(s\) for Phase 2B\.1 staging\s*import:\s*USE database switch/i', $raw) === 1
        || preg_match('/USE database switch/i', $raw) === 1) {
        if (!function_exists('orange_restore_private_sql_map_import_error')) {
            require_once __DIR__ . '/restore_private_sql_import_policy.php';
        }

        return ORANGE_RESTORE_STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED;
    }
    if (preg_match('/CODE:\s*STEP7_SHADOW_DB_TARGET_UNAVAILABLE/i', $raw) === 1
        || preg_match('/ORANGE_RESTORE_STAGING_DB/i', $raw) === 1
        || preg_match('/ORANGE_RESTORE_SHADOW_DB/i', $raw) === 1
        || preg_match('/is not configured/i', $raw) === 1) {
        return ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE;
    }
    if (preg_match('/SHADOW_RESTORE_RESULT:\s*FAIL/i', $raw) === 1) {
        return ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_READY_IMPORT_NOT_STARTED;
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
        str_starts_with($code, 'STEP7_SQL_') => $code,
        str_starts_with($code, 'STEP7_PRIVATE_IMPORT_') => $code,
        str_starts_with($code, 'STEP7_PRIVATE_TARGET_') => $code,
        str_contains($code, 'USE database switch')
            || str_contains($code, 'forbidden pattern') => 'STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED',
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
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_MKDIR_FAILED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_MKDIR_FAILED),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_PARTIAL => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_PARTIAL),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_UNOWNED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_UNOWNED),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_LOG_UNAVAILABLE => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_LOG_UNAVAILABLE),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INITIALIZE_FAILED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INITIALIZE_FAILED),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_UNRESOLVED_INIT_FAILURE => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_UNRESOLVED_INIT_FAILURE),
        ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_SOURCE_NOT_PERSISTABLE => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_SOURCE_NOT_PERSISTABLE),
        ORANGE_RESTORE_STEP7_TERMINAL_PARTIAL_RECOVERY_NOT_SAFE => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_TERMINAL_PARTIAL_RECOVERY_NOT_SAFE),
        ORANGE_RESTORE_STEP7_PRIVATE_TERMINAL_PARTIAL_RECOVERY_FAILED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_TERMINAL_PARTIAL_RECOVERY_FAILED),
        ORANGE_RESTORE_STEP7_DATADIR_OWNERSHIP_UNKNOWN => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_DATADIR_OWNERSHIP_UNKNOWN),
        ORANGE_RESTORE_STEP7_PRIVATE_PROCESS_STATE_UNKNOWN => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_PROCESS_STATE_UNKNOWN),
        ORANGE_RESTORE_STEP7_PHP_WORKER_LIVENESS_UNKNOWN => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PHP_WORKER_LIVENESS_UNKNOWN),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_LIVENESS_UNKNOWN => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_LIVENESS_UNKNOWN),
        ORANGE_RESTORE_STEP7_PROCESS_INSPECTION_UNAVAILABLE => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PROCESS_INSPECTION_UNAVAILABLE),
        ORANGE_RESTORE_STEP7_PROCESS_EVIDENCE_CONTRADICTORY => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PROCESS_EVIDENCE_CONTRADICTORY),
        ORANGE_RESTORE_STEP7_STAGE_MUTEX_UNRESOLVED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_STAGE_MUTEX_UNRESOLVED),
        ORANGE_RESTORE_STEP7_RUNTIME_INSTALL_IN_PROGRESS => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_RUNTIME_INSTALL_IN_PROGRESS),
        ORANGE_RESTORE_STEP7_CURRENT_CAPTURE_CAPABILITY_NOT_READY => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_CURRENT_CAPTURE_CAPABILITY_NOT_READY),
        ORANGE_RESTORE_STEP7_PARENT_WORKER_RUNTIME_MISMATCH => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PARENT_WORKER_RUNTIME_MISMATCH),
        ORANGE_RESTORE_STEP7_SOURCE_PACKAGE_NOT_READY => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_SOURCE_PACKAGE_NOT_READY),
        ORANGE_RESTORE_STEP7_GENUINE_ACTIVE_ATTEMPT => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_GENUINE_ACTIVE_ATTEMPT),
        ORANGE_RESTORE_STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED => function_exists('orange_restore_private_sql_operator_reason_ar')
            ? orange_restore_private_sql_operator_reason_ar(ORANGE_RESTORE_STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED)
            : 'تعذر قبول مقدمة ملف SQL للحزمة على مسار الاستيراد الخاص. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_DUMP_MULTIPLE_DATABASE_SWITCHES => function_exists('orange_restore_private_sql_operator_reason_ar')
            ? orange_restore_private_sql_operator_reason_ar(ORANGE_RESTORE_STEP7_SQL_DUMP_MULTIPLE_DATABASE_SWITCHES)
            : 'ملف SQL يحتوي أكثر من تبديل قاعدة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_DUMP_DATABASE_IDENTITY_MISMATCH => function_exists('orange_restore_private_sql_operator_reason_ar')
            ? orange_restore_private_sql_operator_reason_ar(ORANGE_RESTORE_STEP7_SQL_DUMP_DATABASE_IDENTITY_MISMATCH)
            : 'هوية قاعدة البيانات في مقدمة SQL غير متطابقة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE => function_exists('orange_restore_private_sql_operator_reason_ar')
            ? orange_restore_private_sql_operator_reason_ar(ORANGE_RESTORE_STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE)
            : 'ملف SQL يحتوي مراجع عبر قواعد بيانات غير مسموحة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_DUMP_DATABASE_LEVEL_DDL_FORBIDDEN => function_exists('orange_restore_private_sql_operator_reason_ar')
            ? orange_restore_private_sql_operator_reason_ar(ORANGE_RESTORE_STEP7_SQL_DUMP_DATABASE_LEVEL_DDL_FORBIDDEN)
            : 'ملف SQL يحتوي أوامر مستوى قاعدة غير مسموحة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED => function_exists('orange_restore_private_sql_operator_reason_ar')
            ? orange_restore_private_sql_operator_reason_ar(ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED)
            : 'تعذر تجهيز تيار الاستيراد المطبّع. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_TARGET_PREPARE_FAILED => function_exists('orange_restore_private_sql_operator_reason_ar')
            ? orange_restore_private_sql_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_TARGET_PREPARE_FAILED)
            : 'تعذر تجهيز هدف قاعدة الظل داخل المحرك الخاص. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_READY_IMPORT_NOT_STARTED => function_exists('orange_restore_private_sql_operator_reason_ar')
            ? orange_restore_private_sql_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_READY_IMPORT_NOT_STARTED)
            : 'محرك قاعدة الظل الخاص جاهز لكن الاستيراد لم يبدأ.',
        ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_START_FAILED => function_exists('orange_restore_private_sql_operator_reason_ar')
            ? orange_restore_private_sql_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_START_FAILED)
            : 'تعذر بدء استيراد SQL إلى قاعدة الظل الخاصة.',
        ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_FAILED => function_exists('orange_restore_private_sql_operator_reason_ar')
            ? orange_restore_private_sql_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_FAILED)
            : 'فشل استيراد SQL إلى قاعدة الظل الخاصة.',
        ORANGE_RESTORE_STEP7_ENGINE_STATE_CAPTURE_NOT_READY => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_ENGINE_STATE_CAPTURE_NOT_READY),
        ORANGE_RESTORE_STEP7_INIT_ERROR_CAPTURE_NOT_READY => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_INIT_ERROR_CAPTURE_NOT_READY),
        ORANGE_RESTORE_STEP7_RETRY_PREFLIGHT_UNKNOWN => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_RETRY_PREFLIGHT_UNKNOWN),
        ORANGE_RESTORE_STEP7_ACTIVE_ATTEMPT => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_ACTIVE_ATTEMPT),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_START_FAILED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_START_FAILED),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_SECRET_BOUNDARY_FAILED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_SECRET_BOUNDARY_FAILED),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NETWORK_POLICY_FAILED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NETWORK_POLICY_FAILED),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_USER_FAILED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_USER_FAILED),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHECKSUM_FAILED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHECKSUM_FAILED),
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHANNEL_UNAVAILABLE => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHANNEL_UNAVAILABLE),
        ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_SOURCE_UNAVAILABLE => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_SOURCE_UNAVAILABLE),
        ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_ARTIFACT_UNREACHABLE => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_ARTIFACT_UNREACHABLE),
        ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_CHECKSUM_FAILED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_CHECKSUM_FAILED),
        ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_INCOMPATIBLE => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_INCOMPATIBLE),
        ORANGE_RESTORE_STEP7_PRIVATE_TOOLS_ROOT_NOT_READY => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_TOOLS_ROOT_NOT_READY),
        ORANGE_RESTORE_STEP7_PRIVATE_PROCESS_EXECUTION_UNAVAILABLE => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_PROCESS_EXECUTION_UNAVAILABLE),
        ORANGE_RESTORE_STEP7_PRIVATE_OWNERSHIP_CONFLICT => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_OWNERSHIP_CONFLICT),
        ORANGE_RESTORE_STEP7_PARENT_WORKER_IDENTITY_MISMATCH => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PARENT_WORKER_IDENTITY_MISMATCH),
        ORANGE_RESTORE_STEP7_PRIVATE_READINESS_UNKNOWN => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_PRIVATE_READINESS_UNKNOWN),
        ORANGE_RESTORE_STEP7_NOT_READY_MUTATION_REJECTED => orange_restore_private_engine_operator_reason_ar(ORANGE_RESTORE_STEP7_NOT_READY_MUTATION_REJECTED),
        ORANGE_RESTORE_STEP7_DIAGNOSTIC_SQL_SCAN_RESOURCE_LIMIT => 'تعذر إكمال شهادة توافق ملف SQL ضمن حدود الموارد الآمنة. الخطوة غير جاهزة.',
        ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_FAILED => 'تعذر فحص توافق ملف SQL للحزمة. الخطوة غير جاهزة.',
        ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_DEFERRED_FROM_STATUS_REFRESH => 'شهادة توافق ملف SQL مؤجّلة لتشخيص التشغيل. تحديث الحالة لا يفحص الحزمة؛ الخطوة غير جاهزة حتى يكتمل التشخيص.',
        ORANGE_RESTORE_STEP7_SQL_STRUCTURE_AMBIGUOUS => 'بنية ملف SQL غير حاسمة للفحص الآمن. الخطوة غير جاهزة.',
        ORANGE_RESTORE_STEP7_SQL_SOURCE_IDENTITY_MISMATCH => 'هوية قاعدة المصدر في ملف SQL غير متطابقة. الخطوة غير جاهزة.',
        ORANGE_RESTORE_STEP7_SQL_EXTERNAL_DATABASE_FORBIDDEN => 'ملف SQL يشير إلى قاعدة تطبيق خارجية غير مسموحة. الخطوة غير جاهزة.',
        ORANGE_RESTORE_STEP7_SQL_SYSTEM_SCHEMA_FORBIDDEN => 'ملف SQL يشير إلى مخطط نظام غير مسموح. الخطوة غير جاهزة.',
        ORANGE_RESTORE_STEP7_SQL_MULTIPLE_DATABASES_FORBIDDEN => 'ملف SQL يحتوي أكثر من قاعدة/تبديل غير مسموح. الخطوة غير جاهزة.',
        ORANGE_RESTORE_STEP7_UNKNOWN_START_FAILURE => 'تعذر بدء استعادة قاعدة الظل. أعد المحاولة من شاشة الاسترداد.',
    ];

    if (isset($messages[$safeCode])) {
        return $messages[$safeCode];
    }

    if (str_starts_with($safeCode, 'STEP7_PRIVATE_ENGINE_')
        || str_starts_with($safeCode, 'STEP7_PRIVATE_')
        || str_starts_with($safeCode, 'STEP7_PHP_')
        || str_starts_with($safeCode, 'STEP7_PROCESS_')
        || str_starts_with($safeCode, 'STEP7_STAGE_')
        || str_starts_with($safeCode, 'STEP7_RUNTIME_')
        || str_starts_with($safeCode, 'STEP7_CURRENT_')
        || str_starts_with($safeCode, 'STEP7_SOURCE_')
        || str_starts_with($safeCode, 'STEP7_GENUINE_')
        || str_starts_with($safeCode, 'STEP7_TERMINAL_')
        || str_starts_with($safeCode, 'STEP7_DATADIR_')
        || str_starts_with($safeCode, 'STEP7_ENGINE_')
        || str_starts_with($safeCode, 'STEP7_INIT_')
        || str_starts_with($safeCode, 'STEP7_RETRY_')
        || $safeCode === ORANGE_RESTORE_STEP7_NOT_READY_MUTATION_REJECTED
        || $safeCode === ORANGE_RESTORE_STEP7_PARENT_WORKER_IDENTITY_MISMATCH
        || $safeCode === ORANGE_RESTORE_STEP7_PARENT_WORKER_RUNTIME_MISMATCH
        || $safeCode === ORANGE_RESTORE_STEP7_ACTIVE_ATTEMPT) {
        return orange_restore_private_engine_operator_reason_ar($safeCode);
    }

    return $messages[ORANGE_RESTORE_STEP7_UNKNOWN_START_FAILURE];
}

/**
 * Genuine Step-7 active attempt (claim/mutex contract) — never status-only.
 *
 * Class A: blocking run-claim (live PID or grace).
 * Class F (rejected): treating own PENDING after request as ACTIVE — must not happen.
 * Install/materialize mutex is a separate channel and must not surface as ACTIVE.
 *
 * @param array<string, mixed> $job
 * @return array{active:bool,class:string,attempt_id:string,claim:array<string,mixed>|null}
 */
function orange_restore_center_step7_genuine_active_attempt(
    string $workRoot,
    string $jobId,
    array $job
): array {
    $claim = orange_restore_center_reconcile_run_claim($workRoot, $jobId, 'shadow_db', $job);
    if ($claim === null || !orange_restore_center_claim_blocks_schedule($claim, $job, 'shadow_db')) {
        return [
            'active' => false,
            'class' => '',
            'attempt_id' => '',
            'claim' => null,
        ];
    }

    return [
        'active' => true,
        'class' => ORANGE_RESTORE_STEP7_ACTIVE_CLASS_CLAIM_BLOCKS,
        'attempt_id' => trim((string) ($claim['attempt_id'] ?? '')),
        'claim' => $claim,
    ];
}

/**
 * Read-only stage mutex probe (acquire+release immediately; no job writes).
 */
function orange_restore_step7_stage_mutex_status_readonly(
    string $workRoot,
    string $jobId
): string {
    if (!function_exists('orange_restore_center_worker_mutex_path')) {
        return 'absent_or_released';
    }
    $path = orange_restore_center_worker_mutex_path($workRoot, $jobId, 'shadow_db');
    if (!is_file($path)) {
        return 'absent_or_released';
    }
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return 'unresolved';
    }
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);

        return 'held';
    }
    @flock($handle, LOCK_UN);
    fclose($handle);

    return 'absent_or_released';
}

/**
 * Read-only runtime-install mutex classification (separate from Step-7 attempt).
 *
 * @return array{status:string,separate_from_step7_attempt:bool,in_progress:bool}
 */
function orange_restore_step7_runtime_install_mutex_status_readonly(string $projectRoot): array
{
    $toolsRoot = '';
    if (function_exists('orange_restore_private_engine_tools_root')) {
        $toolsRoot = (string) orange_restore_private_engine_tools_root($projectRoot);
    }
    $lockFile = $toolsRoot !== ''
        ? ($toolsRoot . DIRECTORY_SEPARATOR . '.locks' . DIRECTORY_SEPARATOR . 'runtime_install.lock')
        : '';
    if ($lockFile === '' || !is_file($lockFile)) {
        return [
            'status' => 'absent_or_released',
            'separate_from_step7_attempt' => true,
            'in_progress' => false,
        ];
    }
    $handle = @fopen($lockFile, 'c+');
    if ($handle === false) {
        return [
            'status' => 'unresolved_separate',
            'separate_from_step7_attempt' => true,
            'in_progress' => false,
        ];
    }
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);

        return [
            'status' => 'held_separate_from_step7_attempt',
            'separate_from_step7_attempt' => true,
            'in_progress' => true,
        ];
    }
    @flock($handle, LOCK_UN);
    fclose($handle);

    return [
        'status' => 'absent_or_released',
        'separate_from_step7_attempt' => true,
        'in_progress' => false,
    ];
}

/**
 * Authoritative read-only Step-7 retry preflight (zero filesystem/job writes).
 * Used by diagnostic readiness, private-engine trace, button authority, and request mutation gate.
 *
 * @return array<string, mixed>
 */
/**
 * Authoritative Step 7 retry preflight (read-only).
 *
 * @param array{
 *   include_sql_package_scan?:bool
 * } $options Status Refresh / list hydration MUST pass include_sql_package_scan=false
 *            so a Full-package dump scan cannot OOM/timeout the status route.
 *            Diagnostic + mutation readiness keep the default (scan enabled).
 * @return array<string, mixed>
 */
function orange_restore_step7_retry_preflight(
    string $projectRoot,
    string $workRoot,
    string $jobId,
    array $options = []
): array {
    $includeSqlPackageScan = array_key_exists('include_sql_package_scan', $options)
        ? (bool) $options['include_sql_package_scan']
        : true;
    $diagnosticSkipProcessSpawn = !empty($options['diagnostic_skip_process_spawn']);
    if (!function_exists('orange_restore_private_engine_public_readiness')) {
        require_once __DIR__ . '/restore_private_shadow_engine.php';
    }
    if (!function_exists('orange_restore_shadow_resolve_target')) {
        require_once __DIR__ . '/restore_shadow_db.php';
    }
    $job = orange_restore_fw_read($workRoot, $jobId) ?: [];
    $pub = orange_restore_fw_public_row($job);
    // Read-only active check — do NOT call reconcile/clear (write-nothing contract).
    $active = ['active' => false, 'class' => '', 'attempt_id' => '', 'claim' => null];
    if (function_exists('orange_restore_center_worker_run_claim_path')
        && function_exists('orange_restore_center_read_run_claim')) {
        $claimPath = orange_restore_center_worker_run_claim_path($workRoot, $jobId, 'shadow_db');
        $claim = is_file($claimPath) ? orange_restore_center_read_run_claim($claimPath) : null;
        if ($diagnosticSkipProcessSpawn) {
            if (is_array($claim) && orange_restore_center_diagnostic_claim_busy_readonly($claim)) {
                $active = [
                    'active' => true,
                    'class' => defined('ORANGE_RESTORE_STEP7_ACTIVE_CLASS_CLAIM_BLOCKS')
                        ? ORANGE_RESTORE_STEP7_ACTIVE_CLASS_CLAIM_BLOCKS
                        : 'CLAIM_BLOCKS',
                    'attempt_id' => trim((string) ($claim['attempt_id'] ?? '')),
                    'claim' => $claim,
                ];
            }
        } elseif (function_exists('orange_restore_center_claim_blocks_schedule')
            && is_array($claim)
            && orange_restore_center_claim_blocks_schedule($claim, $job, 'shadow_db')) {
            $active = [
                'active' => true,
                'class' => defined('ORANGE_RESTORE_STEP7_ACTIVE_CLASS_CLAIM_BLOCKS')
                    ? ORANGE_RESTORE_STEP7_ACTIVE_CLASS_CLAIM_BLOCKS
                    : 'CLAIM_BLOCKS',
                'attempt_id' => trim((string) ($claim['attempt_id'] ?? '')),
                'claim' => $claim,
            ];
        }
    }
    $meta = orange_restore_shadow_load_meta($workRoot, $jobId) ?? [];
    $env = orange_backup_load_env_array($projectRoot);
    $resolved = orange_restore_shadow_resolve_target($env, $projectRoot, $jobId, $meta);
    $engine = orange_restore_private_engine_public_readiness($projectRoot, $workRoot, $jobId);
    if ($diagnosticSkipProcessSpawn) {
        $attemptCtx = orange_restore_center_diagnostic_attempt_context_readonly($workRoot, $jobId);
    } else {
        $attemptCtx = function_exists('orange_restore_private_engine_attempt_context')
            ? orange_restore_private_engine_attempt_context($workRoot, $jobId)
            : [];
    }
    $identity = (string) ($resolved['identity_hash'] ?? '');
    $boundHash = trim((string) ($meta['shadow_db_identity_hash'] ?? ''));
    $match = $identity !== '' && $boundHash !== '' && hash_equals($identity, $boundHash);
    if (!$match
        && ($resolved['ok'] ?? false)
        && trim((string) ($meta['shadow_db'] ?? '')) !== ''
        && hash_equals(
            strtolower((string) ($resolved['shadow_db'] ?? '')),
            strtolower(trim((string) ($meta['shadow_db'] ?? '')))
        )) {
        $match = true;
    }
    $status = (string) ($job['status'] ?? '');
    $inflight = in_array($status, [
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_VERIFYING,
    ], true);
    $terminalFailed = $status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED;
    $terminalReady = $status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY;
    $latestTerminal = $terminalFailed || $terminalReady || !empty($attemptCtx['latest_attempt_terminal']);
    $requestable = !empty($pub['shadow_restore_requestable']);
    $step8Locked = empty($pub['is_shadow_restore_ready']) && !$terminalReady;
    $token = (string) ($engine['ready_token'] ?? '');
    $datadirState = (string) ($engine['datadir_state'] ?? 'ABSENT');
    $recoveryRequired = !empty($engine['datadir_recovery_required']);
    $recoverySafe = !empty($engine['datadir_recovery_safe']);
    $engineStateCap = array_key_exists('engine_state_capture_ready', $engine)
        ? !empty($engine['engine_state_capture_ready'])
        : true;
    $initCap = array_key_exists('init_error_capture_ready', $engine)
        ? !empty($engine['init_error_capture_ready'])
        : true;
    $initResultCap = $initCap && function_exists('orange_restore_private_engine_init_ledger_write');
    $runtimePersistable = (string) ($engine['runtime_source'] ?? '') !== 'unavailable'
        && (string) ($engine['runtime_source'] ?? '') !== '';
    $runtimeVerified = !empty($engine['binary_available']) || !empty($engine['materializable']);
    $runtimeCompatible = !empty($engine['runtime_compatible']);
    $sourceReady = trim((string) ($job['source_package_id'] ?? $job['package_id'] ?? '')) !== '';
    // Package-specific SQL compatibility certificate (read-only; no stream/target/worker writes).
    // Status Refresh must NOT invoke the dump scan (OOM/timeout collapses list.php → generic Arabic).
    if (!function_exists('orange_restore_sql_compat_scan_package')) {
        require_once __DIR__ . '/restore_sql_compat_engine.php';
    }
    $sqlCertificate = [
        'ok' => false,
        'compatible' => false,
        'final_compatibility_classification' => ORANGE_RESTORE_SQL_PKG_SCAN_FAILED,
        'exact_not_ready_reason' => ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_FAILED,
        'parser_policy_version' => defined('ORANGE_RESTORE_SQL_COMPAT_ENGINE_VERSION')
            ? ORANGE_RESTORE_SQL_COMPAT_ENGINE_VERSION
            : '',
        'package_scan_complete' => false,
        'deferred_from_status_refresh' => false,
        'scan_invoked' => false,
    ];
    if (!$includeSqlPackageScan) {
        $sqlCertificate['final_compatibility_classification'] = ORANGE_RESTORE_SQL_PKG_SCAN_DEFERRED;
        $sqlCertificate['exact_not_ready_reason'] = ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_DEFERRED_FROM_STATUS_REFRESH;
        $sqlCertificate['deferred_from_status_refresh'] = true;
        $sqlCertificate['scan_invoked'] = false;
        $sqlCertificate['package_scan_complete'] = false;
        $sqlCertificate['compatible'] = false;
        $sqlCertificate['ok'] = false;
    } else {
        if (!function_exists('orange_restore_shadow_resolve_source_package')) {
            require_once __DIR__ . '/restore_shadow_db.php';
        }
        try {
            $backupRoot = '';
            if (function_exists('orange_backup_admin_resolve_backup_root')) {
                require_once dirname(__DIR__) . '/backup_admin.php';
                $backupRoot = orange_backup_admin_resolve_backup_root($projectRoot);
            }
            if ($backupRoot !== '' && $sourceReady) {
                $src = orange_restore_shadow_resolve_source_package($workRoot, $jobId, $backupRoot, $job);
                if (!empty($src['ok'])) {
                    $manifest = is_array($src['manifest'] ?? null) ? $src['manifest'] : [];
                    $dumpFile = trim((string) ($manifest['dump_file'] ?? ''));
                    $pkgPath = (string) ($src['package_path'] ?? '');
                    $dumpPath = ($pkgPath !== '' && $dumpFile !== '')
                        ? ($pkgPath . DIRECTORY_SEPARATOR . $dumpFile)
                        : '';
                    $trusted = orange_restore_sql_compat_trusted_source_from_manifest($manifest);
                    if ($trusted === '') {
                        $trusted = orange_restore_sql_compat_normalize_ident(
                            orange_restore_shadow_production_db_name($projectRoot)
                        );
                    }
                    $fpOk = trim((string) ($src['fingerprint'] ?? '')) !== '' ? 'yes' : 'unknown';
                    $sumOk = 'unknown';
                    if ($dumpPath !== '' && is_file($dumpPath)) {
                        $sqlCertificate = orange_restore_sql_compat_scan_package(
                            $dumpPath,
                            $manifest,
                            $trusted,
                            $fpOk,
                            $sumOk
                        );
                        $sqlCertificate['scan_invoked'] = true;
                        $sqlCertificate['deferred_from_status_refresh'] = false;
                        $reason = (string) ($sqlCertificate['exact_not_ready_reason'] ?? '');
                        $class = (string) ($sqlCertificate['final_compatibility_classification'] ?? '');
                        $resourceHit = !empty($sqlCertificate['resource_limit_hit'])
                            || $reason === ORANGE_RESTORE_STEP7_DIAGNOSTIC_SQL_SCAN_RESOURCE_LIMIT;
                        $hardScanFail = $class === ORANGE_RESTORE_SQL_PKG_SCAN_FAILED
                            && ($reason === ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_FAILED || $reason === '');
                        $sqlCertificate['package_scan_complete'] = !$resourceHit && !$hardScanFail;
                        $sqlCertificate['compatible'] = !empty($sqlCertificate['ok'])
                            && !empty($sqlCertificate['package_scan_complete'])
                            && in_array($class, [
                                ORANGE_RESTORE_SQL_PKG_COMPATIBLE_UNCHANGED,
                                ORANGE_RESTORE_SQL_PKG_COMPATIBLE_PRELUDE,
                                ORANGE_RESTORE_SQL_PKG_COMPATIBLE_SAME_SOURCE,
                            ], true);
                        // Never expose raw names in public preflight.
                        unset($sqlCertificate['internal_classification']);
                    }
                }
            }
        } catch (Throwable) {
            $sqlCertificate['package_scan_complete'] = false;
            $sqlCertificate['scan_invoked'] = true;
            $sqlCertificate['deferred_from_status_refresh'] = false;
            $sqlCertificate['exact_not_ready_reason'] = ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_FAILED;
        }
    }
    $sqlCompatOk = !empty($sqlCertificate['compatible']) && !empty($sqlCertificate['package_scan_complete']);
    $phpClass = (string) ($attemptCtx['php_worker_liveness_class'] ?? ORANGE_RESTORE_STEP7_PROC_METADATA_ABSENT_LEGACY);
    $dbClass = (string) ($attemptCtx['private_db_liveness_class'] ?? ORANGE_RESTORE_STEP7_PROC_METADATA_ABSENT_LEGACY);
    $phpLive = (string) ($attemptCtx['php_worker_liveness'] ?? 'unknown');
    $dbLive = (string) ($attemptCtx['private_db_liveness'] ?? 'unknown');
    $processAbsenceProven = !empty($attemptCtx['process_absence_proven']);
    $absenceConclusion = (string) ($attemptCtx['process_absence_conclusion']
        ?? ($processAbsenceProven
            ? ORANGE_RESTORE_STEP7_ABSENCE_PROVEN
            : ORANGE_RESTORE_STEP7_ABSENCE_NOT_PROVABLE));
    $stageMutex = orange_restore_step7_stage_mutex_status_readonly($workRoot, $jobId);
    $installMutex = orange_restore_step7_runtime_install_mutex_status_readonly($projectRoot);
    $parentTargetMatch = $match && ($resolved['ok'] ?? false);
    $parentRuntimeMatch = $match && $runtimeVerified;
    $claimStatus = !empty($active['active']) ? 'active' : 'ABSENT_TERMINAL_OR_RELEASED';

    $engineServiceState = (string) ($attemptCtx['engine_service_state'] ?? '');
    if ($engineServiceState === '' && function_exists('orange_restore_private_engine_attempt_context')) {
        $engineServiceState = (string) ($attemptCtx['engine_service_state'] ?? ORANGE_RESTORE_ENGINE_ABSENT);
    }
    $engineReadyIdle = $engineServiceState === ORANGE_RESTORE_ENGINE_READY_IDLE
        || !empty($attemptCtx['engine_ready_idle']);
    $phpAbsenceOk = !empty($attemptCtx['php_worker_absence_proven'])
        || ($processAbsenceProven && $phpClass !== ORANGE_RESTORE_STEP7_PROC_MATCHED_ACTIVE);

    $code = 'ok';
    $green = false;
    if (!empty($active['active']) || $phpClass === ORANGE_RESTORE_STEP7_PROC_MATCHED_ACTIVE) {
        $code = ORANGE_RESTORE_STEP7_GENUINE_ACTIVE_ATTEMPT;
    } elseif ($datadirState === 'PARTIAL_OWNED_ACTIVE_ATTEMPT' && !$engineReadyIdle) {
        $code = ORANGE_RESTORE_STEP7_GENUINE_ACTIVE_ATTEMPT;
    } elseif ($dbClass === ORANGE_RESTORE_STEP7_PROC_MATCHED_ACTIVE && !$engineReadyIdle) {
        $code = ORANGE_RESTORE_STEP7_GENUINE_ACTIVE_ATTEMPT;
    } elseif ($stageMutex === 'held' || $stageMutex === 'unresolved') {
        $code = ORANGE_RESTORE_STEP7_STAGE_MUTEX_UNRESOLVED;
    } elseif (!empty($installMutex['in_progress'])) {
        $code = ORANGE_RESTORE_STEP7_RUNTIME_INSTALL_IN_PROGRESS;
    } elseif ($phpClass === ORANGE_RESTORE_STEP7_PROC_INSPECTION_UNAVAILABLE
        || $dbClass === ORANGE_RESTORE_STEP7_PROC_INSPECTION_UNAVAILABLE) {
        $code = ORANGE_RESTORE_STEP7_PROCESS_INSPECTION_UNAVAILABLE;
    } elseif ($phpClass === ORANGE_RESTORE_STEP7_PROC_EVIDENCE_CONTRADICTORY
        || $dbClass === ORANGE_RESTORE_STEP7_PROC_EVIDENCE_CONTRADICTORY) {
        $code = ORANGE_RESTORE_STEP7_PROCESS_EVIDENCE_CONTRADICTORY;
    } elseif ($phpLive === 'unknown'
        || $phpClass === ORANGE_RESTORE_STEP7_PROC_METADATA_ABSENT_LEGACY) {
        $code = ORANGE_RESTORE_STEP7_PHP_WORKER_LIVENESS_UNKNOWN;
    } elseif ($dbLive === 'unknown'
        || $dbClass === ORANGE_RESTORE_STEP7_PROC_METADATA_ABSENT_LEGACY) {
        $code = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_LIVENESS_UNKNOWN;
    } elseif (!$processAbsenceProven && in_array($datadirState, [
        'PARTIAL_OWNED_TERMINAL_ATTEMPT',
        'PARTIAL_OWNED_OLDER_TERMINAL_ATTEMPT',
        'PARTIAL_OWNED_CURRENT_ATTEMPT',
    ], true)) {
        $code = ORANGE_RESTORE_STEP7_TERMINAL_PARTIAL_RECOVERY_NOT_SAFE;
    } elseif (!($resolved['ok'] ?? false)) {
        $code = ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE;
    } elseif (!$parentTargetMatch && $boundHash !== '') {
        $code = ORANGE_RESTORE_STEP7_PARENT_WORKER_IDENTITY_MISMATCH;
    } elseif (!$parentRuntimeMatch) {
        $code = ORANGE_RESTORE_STEP7_PARENT_WORKER_RUNTIME_MISMATCH;
    } elseif (!$sourceReady) {
        $code = ORANGE_RESTORE_STEP7_SOURCE_PACKAGE_NOT_READY;
    } elseif (!$engineStateCap || !$initCap || !$initResultCap) {
        $code = ORANGE_RESTORE_STEP7_CURRENT_CAPTURE_CAPABILITY_NOT_READY;
    } elseif ($datadirState === 'PARTIAL_OWNED_TERMINAL_ATTEMPT' && !$recoverySafe) {
        $code = ORANGE_RESTORE_STEP7_TERMINAL_PARTIAL_RECOVERY_NOT_SAFE;
    } elseif (in_array($datadirState, ['UNOWNED', 'MALFORMED_OR_UNKNOWN', 'UNKNOWN'], true)) {
        $code = ORANGE_RESTORE_STEP7_DATADIR_OWNERSHIP_UNKNOWN;
    } elseif (!$sqlCompatOk && $sourceReady) {
        $code = (string) ($sqlCertificate['exact_not_ready_reason'] ?? ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_FAILED);
        if ($code === '' || $code === 'ok') {
            $code = ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_FAILED;
        }
        // Keep Owner Arabic parity for external/system via legacy CROSS_DB code.
        if (in_array($code, [
            ORANGE_RESTORE_STEP7_SQL_EXTERNAL_DATABASE_FORBIDDEN,
            ORANGE_RESTORE_STEP7_SQL_SYSTEM_SCHEMA_FORBIDDEN,
        ], true)) {
            $code = ORANGE_RESTORE_STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE;
        }
    } elseif ($engineReadyIdle
        && $requestable && !$inflight && $phpAbsenceOk && empty($active['active'])
        && $latestTerminal && $step8Locked && $parentTargetMatch && $parentRuntimeMatch
        && $runtimeVerified && $runtimeCompatible && $runtimePersistable
        && $sourceReady && $sqlCompatOk && $engineStateCap && $initCap
        && in_array($datadirState, ['ACTIVE_OWNED', 'READY_OWNED', 'EMPTY_OWNED'], true)) {
        // Owned healthy private engine service → controlled retry (not provisioning).
        $green = true;
        $code = 'ok';
        $token = ORANGE_RESTORE_STEP7_READY_FOR_CONTROLLED_ATTEMPT;
    } elseif ($token === ORANGE_RESTORE_STEP7_READY_FOR_CONTROLLED_ATTEMPT
        && $requestable && !$inflight && ($processAbsenceProven || $phpAbsenceOk) && empty($active['active'])
        && $sqlCompatOk) {
        $green = true;
        $code = 'ok';
    } elseif ($token === ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING
        && $requestable && !$inflight && $processAbsenceProven && empty($active['active'])
        && $latestTerminal && $step8Locked && $parentTargetMatch && $parentRuntimeMatch
        && $runtimeVerified && $runtimeCompatible && $runtimePersistable
        && $sourceReady && $sqlCompatOk && $engineStateCap && $initCap
        && in_array($datadirState, ['ABSENT', 'EMPTY_OWNED', 'PARTIAL_OWNED_TERMINAL_ATTEMPT'], true)
        && (!str_contains($datadirState, 'PARTIAL') || ($recoveryRequired && $recoverySafe))) {
        $green = true;
        $code = 'ok';
    } elseif ((string) ($engine['code'] ?? '') !== '' && (string) ($engine['code'] ?? '') !== 'ok') {
        $code = (string) $engine['code'];
        $token = '';
    } else {
        $code = ORANGE_RESTORE_STEP7_NOT_READY_MUTATION_REJECTED;
        $token = '';
    }
    if (!$green) {
        $token = '';
    }
    // Prefer structured deferred-scan reason when status-refresh skipped the dump scan
    // and no stronger process/mutex blocker already owns $code.
    if (!$green
        && !$sqlCompatOk
        && !empty($sqlCertificate['deferred_from_status_refresh'])
        && (
            $code === ORANGE_RESTORE_STEP7_NOT_READY_MUTATION_REJECTED
            || $code === 'ok'
            || $code === ''
        )) {
        $code = ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_DEFERRED_FROM_STATUS_REFRESH;
    }
    $exactReason = $green ? '' : $code;
    $finalReadiness = $green
        ? ($token !== '' ? $token : 'READY')
        : 'NOT_READY';

    return [
        'ok' => $green,
        'code' => $code,
        'ready_token' => $token,
        'final_readiness' => $finalReadiness,
        'exact_not_ready_reason' => $exactReason,
        'read_only' => true,
        'job_write_count' => 0,
        'filesystem_mutation_count' => 0,
        'process_mutation_count' => 0,
        'state_requestable' => $requestable,
        'latest_attempt_state' => $status,
        'latest_attempt_terminal' => $latestTerminal,
        'active_attempt' => !empty($active['active']),
        'claim_state' => $claimStatus,
        'claim_status' => $claimStatus,
        'stage_mutex_state' => $stageMutex,
        'stage_mutex_status' => $stageMutex,
        'runtime_install_mutex_state' => (string) ($installMutex['status'] ?? 'absent_or_released'),
        'runtime_install_mutex_status' => (string) ($installMutex['status'] ?? 'absent_or_released'),
        'runtime_install_mutex_separate_from_step7_attempt' => !empty($installMutex['separate_from_step7_attempt']),
        'PHP_worker_liveness' => $phpLive,
        'php_worker_liveness' => $phpLive,
        'php_worker_liveness_class' => $phpClass,
        'private_DB_process_liveness' => $dbLive,
        'private_db_liveness' => $dbLive,
        'private_db_liveness_class' => $dbClass,
        'process_absence_proven' => $processAbsenceProven || ($engineReadyIdle && $phpAbsenceOk),
        'process_absence_conclusion' => $absenceConclusion,
        'engine_service_state' => $engineServiceState !== ''
            ? $engineServiceState
            : ($engineReadyIdle ? ORANGE_RESTORE_ENGINE_READY_IDLE : ORANGE_RESTORE_ENGINE_ABSENT),
        'engine_ready_idle' => $engineReadyIdle,
        'php_worker_absence_proven' => $phpAbsenceOk,
        'current_runtime_source' => (string) ($engine['runtime_source'] ?? 'unavailable'),
        'current_runtime_verified' => $runtimeVerified,
        'current_runtime_compatible' => $runtimeCompatible,
        'current_runtime_identity_persistable' => $runtimePersistable,
        'datadir_category' => $datadirState,
        'datadir_ownership_proven' => !empty($engine['ownership_proven']),
        'recovery_required' => $recoveryRequired,
        'partial_recovery_required' => $recoveryRequired,
        'recovery_safe' => $recoverySafe,
        'partial_recovery_safe' => $recoverySafe,
        'recovery_mode' => $recoverySafe ? 'AUTOMATIC_ON_NEXT_EXPLICIT_ATTEMPT' : 'none',
        'engine_state_capture_capability' => $engineStateCap ? 'ready' : 'not_ready',
        'initialization_result_capture_capability' => $initResultCap ? 'ready' : 'not_ready',
        'initialization_error_capture_capability' => $initCap ? 'ready' : 'not_ready',
        'initialization_result_error_capture_capability' => $initCap ? 'ready' : 'not_ready',
        'source_package_ready' => $sourceReady,
        'sql_package_compatibility' => [
            'package_scan_complete' => !empty($sqlCertificate['package_scan_complete']),
            'compatible' => !empty($sqlCertificate['compatible']),
            'final_compatibility_classification' => (string) ($sqlCertificate['final_compatibility_classification'] ?? ''),
            'exact_not_ready_reason' => (string) ($sqlCertificate['exact_not_ready_reason'] ?? ''),
            'parser_policy_version' => (string) ($sqlCertificate['parser_policy_version'] ?? ''),
            'package_fingerprint_verified' => (string) ($sqlCertificate['package_fingerprint_verified'] ?? 'unknown'),
            'sql_dump_checksum_verified' => (string) ($sqlCertificate['sql_dump_checksum_verified'] ?? 'unknown'),
            'normalization_required' => !empty($sqlCertificate['normalization_required']),
            'normalization_supported' => !empty($sqlCertificate['normalization_supported']),
            'original_dump_unchanged' => !empty($sqlCertificate['original_dump_unchanged']),
            'real_qualified_reference_count' => (int) ($sqlCertificate['real_qualified_reference_count'] ?? 0),
            'same_source_qualified_reference_count' => (int) ($sqlCertificate['same_source_qualified_reference_count'] ?? 0),
            'external_application_database_count' => (int) ($sqlCertificate['external_application_database_count'] ?? 0),
            'system_schema_reference_count' => (int) ($sqlCertificate['system_schema_reference_count'] ?? 0),
            'distinct_database_identity_count' => (int) ($sqlCertificate['distinct_database_identity_count'] ?? 0),
            'ambiguous_token_count' => (int) ($sqlCertificate['ambiguous_token_count'] ?? 0),
            'false_positive_comment_string_count' => (int) ($sqlCertificate['false_positive_comment_string_count'] ?? 0),
            'canonical_use_count' => (int) ($sqlCertificate['canonical_use_count'] ?? 0),
            'canonical_database_ddl_count' => (int) ($sqlCertificate['canonical_database_ddl_count'] ?? 0),
            'statement_count' => (int) ($sqlCertificate['statement_count'] ?? 0),
            'stored_object_reference_count_by_type' => is_array($sqlCertificate['stored_object_reference_count_by_type'] ?? null)
                ? $sqlCertificate['stored_object_reference_count_by_type']
                : [],
            'deferred_from_status_refresh' => !empty($sqlCertificate['deferred_from_status_refresh']),
            'scan_invoked' => !empty($sqlCertificate['scan_invoked']),
        ],
        'Step_8_locked' => $step8Locked,
        'step8_locked' => $step8Locked,
        'parent_worker_target_match' => $parentTargetMatch,
        'parent_worker_runtime_match' => $parentRuntimeMatch,
        'step7_action_enabled' => $green && $requestable && !$inflight && empty($active['active']) && $sqlCompatOk,
        'engine' => $engine,
        'active_attempt_detail' => $active,
        'attempt_context' => $attemptCtx,
    ];
}

/**
 * Fail closed before pending/attempt/worker when Step7 readiness is not green.
 *
 * ACTIVE is claim-based only. Pending/running status alone is never ACTIVE
 * (OWN_PENDING_POST_REQUEST_FALSE_POSITIVE / class F). Duplicate clicks while
 * genuinely active are handled as idempotent same-attempt by shadow_request + schedule.
 *
 * @return array{ok:bool,code:string,ready_token:string,engine:array<string,mixed>,active_attempt?:array<string,mixed>}
 */
function orange_restore_center_step7_mutation_readiness(
    string $projectRoot,
    string $workRoot,
    string $jobId
): array {
    $pre = orange_restore_step7_retry_preflight($projectRoot, $workRoot, $jobId);
    $active = is_array($pre['active_attempt_detail'] ?? null) ? $pre['active_attempt_detail'] : ['active' => false];
    $engine = is_array($pre['engine'] ?? null) ? $pre['engine'] : [];
    $job = orange_restore_fw_read($workRoot, $jobId) ?: [];
    $status = (string) ($job['status'] ?? '');
    $inflight = in_array($status, [
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_VERIFYING,
    ], true);
    $token = (string) ($pre['ready_token'] ?? '');
    $green = !empty($pre['ok']);

    if (!$green && !$inflight && empty($active['active'])) {
        $code = (string) ($pre['code'] ?? ORANGE_RESTORE_STEP7_NOT_READY_MUTATION_REJECTED);
        if ($code === '' || $code === 'ok') {
            $code = ORANGE_RESTORE_STEP7_NOT_READY_MUTATION_REJECTED;
        }

        return [
            'ok' => false,
            'code' => $code,
            'ready_token' => '',
            'engine' => $engine,
            'active_attempt' => $active,
            'retry_preflight' => $pre,
        ];
    }
    if (!$green && $inflight && empty($active['active'])) {
        return [
            'ok' => true,
            'code' => 'ok',
            'ready_token' => $token,
            'engine' => $engine,
            'active_attempt' => $active,
            'orphan_inflight_attach' => true,
            'retry_preflight' => $pre,
        ];
    }
    if (!$green && !empty($active['active'])) {
        return [
            'ok' => true,
            'code' => 'ok',
            'ready_token' => $token,
            'engine' => $engine,
            'active_attempt' => $active,
            'idempotent_active' => true,
            'retry_preflight' => $pre,
        ];
    }

    return [
        'ok' => true,
        'code' => 'ok',
        'ready_token' => $token,
        'engine' => $engine,
        'active_attempt' => $active,
        'retry_preflight' => $pre,
    ];
}

/**
 * @throws RuntimeException when Step7 mutation must be rejected (no pending/attempt).
 */
function orange_restore_center_assert_step7_mutation_ready(
    string $projectRoot,
    string $workRoot,
    string $jobId
): void {
    $gate = orange_restore_center_step7_mutation_readiness($projectRoot, $workRoot, $jobId);
    if (!empty($gate['ok'])) {
        return;
    }
    $code = (string) ($gate['code'] ?? ORANGE_RESTORE_STEP7_NOT_READY_MUTATION_REJECTED);
    throw new RuntimeException($code !== '' ? $code : ORANGE_RESTORE_STEP7_NOT_READY_MUTATION_REJECTED);
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
        if ($code === 'restore_center_worker_already_running'
            || $code === ORANGE_RESTORE_STEP7_ACTIVE_ATTEMPT_EXISTS) {
            $liveJob = orange_restore_fw_read($workRoot, $jobId);
            $liveStatus = (string) ($liveJob['status'] ?? '');
            $failedMap = orange_restore_center_worker_dispatch_failure_status_map();
            // Never promote a terminal failed/compensated stage into a false "scheduled" success.
            if ($workerKey === 'shadow_db'
                && isset($failedMap[$workerKey])
                && $liveStatus === $failedMap[$workerKey]
            ) {
                // Stale claim on failed status — reconcile and surface retryable failure, not ACTIVE.
                orange_restore_center_reconcile_run_claim($workRoot, $jobId, $workerKey, $liveJob);
                throw new RuntimeException(ORANGE_RESTORE_STEP7_UNKNOWN_START_FAILURE, 0, $e);
            }
            $activeInfo = $workerKey === 'shadow_db'
                ? orange_restore_center_step7_genuine_active_attempt($workRoot, $jobId, $liveJob)
                : ['active' => true, 'attempt_id' => '', 'class' => ''];
            // Duplicate click / same attempt: return identical attempt — never a new spawn.
            $requestResult['scheduled'] = !empty($activeInfo['active']);
            $requestResult['detached'] = true;
            $requestResult['bootstrap_acked'] = !empty($activeInfo['active']);
            $requestResult['idempotent'] = true;
            $requestResult['attempt_id'] = (string) ($activeInfo['attempt_id']
                ?? ($requestResult['attempt_id'] ?? ''));
            if ($requestResult['attempt_id'] === '' && is_array($activeInfo['claim'] ?? null)) {
                $requestResult['attempt_id'] = trim((string) (($activeInfo['claim']['attempt_id'] ?? '')));
            }
            $metaLive = function_exists('orange_restore_shadow_load_meta')
                ? (orange_restore_shadow_load_meta($workRoot, $jobId) ?? [])
                : [];
            if ($requestResult['attempt_id'] === '') {
                $requestResult['attempt_id'] = trim((string) ($metaLive['attempt_id'] ?? ''));
            }
            $requestResult['job'] = orange_restore_fw_public_row($liveJob);
            $requestResult['message'] = 'استعادة قاعدة الظل قيد التنفيذ أو مكتملة مسبقاً.';
            $requestResult['active_attempt_class'] = (string) ($activeInfo['class']
                ?? ORANGE_RESTORE_STEP7_ACTIVE_CLASS_CLAIM_BLOCKS);

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
    // Diagnostic is read-only: never reconcile/clear claims or mutate public state.
    // Forbid unbounded tasklist (restore_lock.php:147) for this request — including nested
    // live_trace / any accidental process_alive callers.
    $prevForbid = $GLOBALS['orange_restore_diagnostic_forbid_process_spawn'] ?? null;
    $GLOBALS['orange_restore_diagnostic_forbid_process_spawn'] = true;
    try {
        return orange_restore_center_diagnostics_body($workRoot, $jobId);
    } finally {
        if ($prevForbid === null) {
            unset($GLOBALS['orange_restore_diagnostic_forbid_process_spawn']);
        } else {
            $GLOBALS['orange_restore_diagnostic_forbid_process_spawn'] = $prevForbid;
        }
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_center_diagnostics_body(string $workRoot, string $jobId): array
{
    $job = orange_restore_fw_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');
    $guidedWorker = orange_restore_center_guided_worker_key_from_status($status);
    $familyMap = orange_restore_center_stage_audit_event_family_map();
    $guidedFamily = $guidedWorker !== '' && isset($familyMap[$guidedWorker])
        ? $familyMap[$guidedWorker]
        : [];

    $workers = [];
    foreach (array_keys(orange_restore_center_worker_catalog()) as $workerKey) {
        $claimPath = orange_restore_center_worker_run_claim_path($workRoot, $jobId, $workerKey);
        $claim = is_file($claimPath) ? orange_restore_center_read_run_claim($claimPath) : null;
        $schedulable = false;
        try {
            orange_restore_center_assert_worker_stage_allowed($job, $workerKey);
            $schedulable = true;
        } catch (Throwable $e) {
            $schedulable = false;
        }
        $blocking = orange_restore_center_diagnostic_claim_busy_readonly($claim);
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
        $latestSafe = is_array($latestCurrent)
            ? (string) ($latestCurrent['safe_failure_code'] ?? $latestCurrent['code'] ?? '')
            : '';
        $looksLikeStaleEnv = preg_match('/\[env\].*is not configured|is not configured/i', $text) === 1;
        $historicalLog = false;
        if ($workerKey === 'shadow_db'
            && $looksLikeStaleEnv
            && (
                str_starts_with($latestSafe, 'STEP7_PRIVATE_ENGINE_')
                || $latestSafe === ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE
            )) {
            // HISTORICAL_LOG_NOT_CURRENT_CAUSE_01 — do not present stale env as current cause.
            $historicalLog = true;
            $text = "[historical] مقتطف سجل أقدم لا يمثّل سبب المحاولة الحالية.\n"
                . 'سبب المحاولة الحالية: ' . $latestSafe . "\n"
                . $text;
        }
        $logSnippets[] = [
            'worker' => $workerKey,
            'log_name' => 'orchestrator_' . orange_restore_center_safe_worker_token($workerKey) . '.log',
            'tail' => trim($text) !== '' ? trim($text) : '(empty)',
            'historical_only' => $historicalLog,
            'not_current_cause' => $historicalLog,
        ];
    }

    $shadowReadiness = null;
    $readyToken = '';
    $pubForReady = orange_restore_fw_public_row($job);
    $step7RequestableNow = !empty($pubForReady['shadow_restore_requestable'])
        || $guidedWorker === 'shadow_db';
    if ($step7RequestableNow) {
        try {
            if (!function_exists('orange_restore_shadow_probe_target_readiness')) {
                require_once __DIR__ . '/restore_shadow_db.php';
            }
            $projectRootDiag = dirname(__DIR__, 3);
            $meta = orange_restore_shadow_load_meta($workRoot, $jobId) ?? [];
            $env = orange_backup_load_env_array($projectRootDiag);
            // Zero-mutation: private-engine preflight (no provision) + target resolve only.
            $resolved = orange_restore_shadow_resolve_target($env, $projectRootDiag, $jobId, $meta);
            // Read-only diagnostic: never soft-bind / write meta during diagnostics.
            $enginePub = orange_restore_private_engine_public_readiness($projectRootDiag, $workRoot, $jobId);
            $ack = orange_restore_shadow_load_bootstrap_ack($workRoot, $jobId);
            $pub = orange_restore_fw_public_row($job);
            $requestable = !empty($pub['shadow_restore_requestable']);
            $running = in_array($status, [
                ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
                ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
                ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_VERIFYING,
            ], true);
            $identity = (string) ($resolved['identity_hash'] ?? '');
            if ($identity === '') {
                $identity = (string) ($enginePub['shadow_db_identity_hash'] ?? '');
            }
            $boundHash = trim((string) ($meta['shadow_db_identity_hash'] ?? ''));
            // Strict parity: both sides must exist and match (no empty-hash waiver for READY).
            $parentWorkerMatch = $identity !== ''
                && $boundHash !== ''
                && hash_equals($identity, $boundHash);
            // If meta already job-bound to same DB name, treat as match even when hash was stale.
            if (!$parentWorkerMatch
                && ($resolved['ok'] ?? false)
                && trim((string) ($meta['shadow_db'] ?? '')) !== ''
                && hash_equals(
                    strtolower((string) ($resolved['shadow_db'] ?? '')),
                    strtolower(trim((string) ($meta['shadow_db'] ?? '')))
                )) {
                $parentWorkerMatch = true;
                // Read-only: do not persist identity hash during diagnostics.
            }
            $targetOk = (bool) ($resolved['ok'] ?? false);
            $binaryOk = !empty($enginePub['binary_available'])
                || !empty($enginePub['materializable'])
                || in_array((string) ($enginePub['runtime_source'] ?? ''), [
                    'verified_portable_artifact',
                    'verified_local_service_binary',
                    'materializable_portable',
                ], true);
            $engineReady = !empty($enginePub['engine_ready']);
            $runtimeCompatible = !empty($enginePub['runtime_compatible']);
            $toolsReady = !empty($enginePub['tools_root_ready']) || $binaryOk;
            $procOk = !empty($enginePub['process_execution_available']);
            $privateCapability = (string) ($enginePub['private_capability'] ?? 'unavailable');
            if ($privateCapability === '' || $privateCapability === 'unavailable') {
                if ($engineReady) {
                    $privateCapability = 'available';
                } elseif (!empty($enginePub['materializable'])) {
                    $privateCapability = 'materializable';
                } elseif ($binaryOk) {
                    $privateCapability = 'runtime_present';
                } else {
                    $privateCapability = 'unavailable';
                }
            }
            // Private-engine authority — never Production CREATE/SHOW GRANTS as unexplained gate.
            $legacyProductionDbCapabilityGate = 0;

            $engineToken = (string) ($enginePub['ready_token'] ?? '');
            if (!$runtimeCompatible) {
                $readyToken = '';
                $failCode = ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_INCOMPATIBLE;
            } elseif (!$procOk) {
                $readyToken = '';
                $failCode = ORANGE_RESTORE_STEP7_PRIVATE_PROCESS_EXECUTION_UNAVAILABLE;
            } elseif (!$toolsReady && !$binaryOk) {
                $readyToken = '';
                $failCode = ORANGE_RESTORE_STEP7_PRIVATE_TOOLS_ROOT_NOT_READY;
            } elseif (!$binaryOk) {
                $readyToken = '';
                $failCode = (string) (($enginePub['code'] ?? '') !== '' && ($enginePub['code'] ?? '') !== 'ok'
                    ? $enginePub['code']
                    : ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_SOURCE_UNAVAILABLE);
            } elseif (!$parentWorkerMatch || !$targetOk) {
                $readyToken = '';
                $failCode = !$targetOk
                    ? ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE
                    : ORANGE_RESTORE_STEP7_PARENT_WORKER_IDENTITY_MISMATCH;
            } elseif ($engineReady && $requestable && !$running) {
                $readyToken = ORANGE_RESTORE_STEP7_READY_FOR_CONTROLLED_ATTEMPT;
                $failCode = 'ok';
            } elseif ($binaryOk && $requestable && !$running) {
                $readyToken = ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING;
                $failCode = 'ok';
            } else {
                $readyToken = $engineToken;
                if ($readyToken !== '' && (!$parentWorkerMatch || !$targetOk || $running)) {
                    $readyToken = '';
                }
                $failCode = $readyToken !== ''
                    ? 'ok'
                    : (string) (($enginePub['code'] ?? '') !== '' && ($enginePub['code'] ?? '') !== 'ok'
                        ? $enginePub['code']
                        : ORANGE_RESTORE_STEP7_PRIVATE_READINESS_UNKNOWN);
            }

            // Authoritative retry preflight overrides ad-hoc green/button (parity with request endpoint).
            // diagnostic_skip_process_spawn: never hit restore_lock.php:147 tasklist from Step 7 diagnostics.
            $retryPre = orange_restore_step7_retry_preflight($projectRootDiag, $workRoot, $jobId, [
                'diagnostic_skip_process_spawn' => true,
            ]);
            $readyToken = (string) ($retryPre['ready_token'] ?? '');
            $failCode = (string) ($retryPre['code'] ?? $failCode);
            $provisionGreen = $readyToken === ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING;
            $controlledGreen = $readyToken === ORANGE_RESTORE_STEP7_READY_FOR_CONTROLLED_ATTEMPT;
            $actionEnabled = !empty($retryPre['step7_action_enabled']);
            $shadowReadiness = [
                'ok' => $controlledGreen,
                'code' => $failCode,
                'source' => (string) ($resolved['source'] ?? 'unavailable'),
                'shadow_db_identity_hash' => $identity !== ''
                    ? $identity
                    : (string) ($resolved['identity_hash'] ?? ''),
                'attempt_id' => (string) ($meta['attempt_id'] ?? ''),
                'bootstrap_acked' => is_array($ack) && !empty($ack['ready']),
                'requestable' => $requestable,
                'execution_running' => $running,
                // Deprecated display alias — private_capability is authoritative in private mode.
                'database_capability' => $privateCapability === 'available' ? 'available' : 'private_engine',
                'private_capability' => $privateCapability,
                'can_create' => $engineReady || $provisionGreen,
                'can_use' => $engineReady,
                'credential_mode' => $engineReady ? 'private_shadow_engine' : ($provisionGreen ? 'private_shadow_engine_pending' : ''),
                'parent_worker_target_identity_match' => !empty($retryPre['parent_worker_target_match']),
                'parent_worker_runtime_identity_match' => !empty($retryPre['parent_worker_runtime_match']),
                'ready_for_controlled_step7_attempt' => $controlledGreen,
                'ready_for_private_shadow_provisioning' => $provisionGreen,
                'ready_token' => $readyToken,
                'step7_action_enabled' => $actionEnabled,
                'legacy_production_db_capability_gate' => $legacyProductionDbCapabilityGate,
                'private_engine' => $enginePub,
                'runtime_source' => (string) ($enginePub['runtime_source'] ?? 'unavailable'),
                'runtime_verified' => !empty($enginePub['runtime_verified']) || !empty($enginePub['materializable']),
                'runtime_compatible' => $runtimeCompatible,
                'tools_root_ready' => $toolsReady,
                'process_execution_available' => $procOk,
                'db_host_category' => (string) ($enginePub['db_host_category'] ?? 'UNKNOWN'),
                'datadir_category' => (string) ($retryPre['datadir_category'] ?? ''),
                'datadir_ownership_proven' => !empty($retryPre['datadir_ownership_proven']),
                'partial_recovery_required' => !empty($retryPre['partial_recovery_required']),
                'partial_recovery_safe' => !empty($retryPre['partial_recovery_safe']),
                'recovery_required' => !empty($retryPre['recovery_required']),
                'recovery_safe' => !empty($retryPre['recovery_safe']),
                'recovery_mode' => (string) ($retryPre['recovery_mode'] ?? 'none'),
                'engine_state_capture_capability' => (string) ($retryPre['engine_state_capture_capability'] ?? 'not_ready'),
                'initialization_result_capture_capability' => (string) ($retryPre['initialization_result_capture_capability'] ?? 'not_ready'),
                'initialization_error_capture_capability' => (string) ($retryPre['initialization_error_capture_capability'] ?? 'not_ready'),
                'initialization_result_error_capture_capability' => (string) ($retryPre['initialization_result_error_capture_capability'] ?? 'not_ready'),
                'exact_not_ready_reason' => (string) ($retryPre['exact_not_ready_reason'] ?? ''),
                'final_readiness' => (string) ($retryPre['final_readiness'] ?? 'NOT_READY'),
                'php_worker_liveness' => (string) ($retryPre['php_worker_liveness'] ?? 'unknown'),
                'php_worker_liveness_class' => (string) ($retryPre['php_worker_liveness_class'] ?? ''),
                'private_db_liveness' => (string) ($retryPre['private_db_liveness'] ?? 'unknown'),
                'private_db_liveness_class' => (string) ($retryPre['private_db_liveness_class'] ?? ''),
                'process_absence_proven' => !empty($retryPre['process_absence_proven']),
                'process_absence_conclusion' => (string) ($retryPre['process_absence_conclusion'] ?? ''),
                'claim_status' => (string) ($retryPre['claim_status'] ?? ''),
                'stage_mutex_status' => (string) ($retryPre['stage_mutex_status'] ?? ''),
                'runtime_install_mutex_status' => (string) ($retryPre['runtime_install_mutex_status'] ?? ''),
                'source_package_ready' => !empty($retryPre['source_package_ready']),
                'step8_locked' => !empty($retryPre['step8_locked']),
                'latest_attempt_terminal' => !empty($retryPre['latest_attempt_terminal']),
                'retry_preflight' => $retryPre,
                'read_only' => true,
            ];
        } catch (Throwable) {
            $shadowReadiness = [
                'ok' => false,
                'code' => ORANGE_RESTORE_STEP7_PRIVATE_READINESS_UNKNOWN,
                'source' => 'unavailable',
                'shadow_db_identity_hash' => '',
                'attempt_id' => '',
                'bootstrap_acked' => false,
                'requestable' => !empty(orange_restore_fw_public_row($job)['shadow_restore_requestable']),
                'execution_running' => false,
                'database_capability' => 'private_engine',
                'private_capability' => 'unavailable',
                'can_create' => false,
                'can_use' => false,
                'credential_mode' => '',
                'parent_worker_target_identity_match' => false,
                'parent_worker_runtime_identity_match' => false,
                'ready_for_controlled_step7_attempt' => false,
                'ready_for_private_shadow_provisioning' => false,
                'ready_token' => '',
                'step7_action_enabled' => false,
                'legacy_production_db_capability_gate' => 0,
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
        'step7_action_enabled' => is_array($shadowReadiness)
            && !empty($shadowReadiness['step7_action_enabled']),
        'log_tails' => $logSnippets,
        'private_engine_live_trace' => (static function () use ($workRoot, $jobId, $shadowReadiness): array {
            try {
                $projectRootDiag = dirname(__DIR__, 3);
                $precomputed = is_array($shadowReadiness['retry_preflight'] ?? null)
                    ? $shadowReadiness['retry_preflight']
                    : [];

                return orange_restore_private_engine_trace_snapshot(
                    $projectRootDiag,
                    $workRoot,
                    $jobId,
                    ['retry_preflight' => $precomputed]
                );
            } catch (Throwable $e) {
                return [
                    'trace_version' => defined('ORANGE_RESTORE_PRIVATE_ENGINE_TRACE_VERSION')
                        ? ORANGE_RESTORE_PRIVATE_ENGINE_TRACE_VERSION
                        : 'step7-private-engine-trace-v1',
                    'read_only' => true,
                    'immutable_snapshot' => true,
                    'classification' => 'TRACE_INCOMPLETE_MISSING_REQUIRED_ARTIFACTS',
                    'missing_artifact_categories' => ['trace_snapshot_exception'],
                    'mutation_counters' => [
                        'LIVE_JOB_MUTATION_COUNT' => 0,
                    ],
                    'arabic_report' => "تقرير آثار محرك قاعدة الظل الخاص (قراءة فقط)\n"
                        . "تعذر إكمال لقطة الآثار بأمان.\n"
                        . 'التصنيف: TRACE_INCOMPLETE_MISSING_REQUIRED_ARTIFACTS',
                    'notes_ar' => [
                        'لقطة الآثار قراءة فقط — فُحصت بأمان دون تغيير حالة المهمة.',
                    ],
                ];
            }
        })(),
        'notes_ar' => [
            'تشخيص تشغيل مراحل الاسترداد — عرض تشغيلي آمن من مركز الاسترداد فقط.',
            'لا تُعرض مسارات الخادم المطلقة ولا الأسرار ولا أسماء قواعد البيانات ولا مفاتيح البيئة.',
            'أحدث محاولة تخص المرحلة الحالية حسب حالة المهمة؛ أحداث المراحل السابقة تُعرض كتاريخية فقط.',
            'يُرفض التنفيذ إذا كانت حالة المهمة لا تسمح بالمرحلة أو إذا كانت المرحلة تعمل.',
            'تحديث الحالة (Refresh) لا يُحسب محاولة جديدة لخطوة استعادة قاعدة الظل.',
            'قسم جاهزية هدف قاعدة الظل للقراءة فقط ولا ينشئ محاولة جديدة ولا يشغّل المحرك الخاص.',
            'READY_FOR_PRIVATE_SHADOW_PROVISIONING يظهر عندما يكون محرك الظل الخاص قابلاً للتوريد/التجهيز.',
            'READY_FOR_CONTROLLED_STEP7_ATTEMPT يظهر فقط بعد جاهزية المحرك الخاص وهدف قاعدة الظل.',
            'قدرة قاعدة الإنتاج (CREATE/SHOW GRANTS) ليست بوابة في وضع المحرك الخاص — تُعرض قدرة المحرك الخاص فقط.',
            'زر خطوة استعادة قاعدة الظل يُعطَّل ويرفض من الخادم إذا كانت الجاهزية NOT_READY حتى لو كانت الحالة قابلة للطلب.',
            'قسم آثار محرك قاعدة الظل الخاص قراءة فقط ويعرض تصنيفاً آمناً حتى عند نقص الآثار.',
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
            // Do NOT re-call assert_step7_mutation_ready here: HTTP already gated readiness,
            // and shadow_request may have moved status to PENDING. Status-only ACTIVE was
            // class F false-positive (OWN_PENDING_POST_REQUEST). Claim/mutex above is authority.
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
