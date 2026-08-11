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
require_once __DIR__ . '/restore_worker_php_cli.php';
require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_production_cli_policy.php';

const ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION = '3B.4-rc-orchestrator-v5-internal-atomic';
const ORANGE_RESTORE_CENTER_WORKER_LOCK_STALE_SECONDS = 21600;
const ORANGE_RESTORE_CENTER_CLAIM_TRANSITION_GRACE_SECONDS = 120;
const ORANGE_RESTORE_CENTER_DIAG_LOG_TAIL_BYTES = 8192;

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
    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_center_pending_without_worker_compensated',
        'result' => 'fail',
        'worker' => $workerKey,
        'from_status' => $status,
        'to_status' => $failed,
        'reason' => $reason,
        'orchestrator_version' => ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION,
    ]);

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

    // Schedulable again (failed / pending / entry): brief grace against double-schedule right after spawn.
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
 * Operator-safe Arabic reason for orchestration codes (no paths/secrets).
 */
function orange_restore_center_operator_reason_ar(string $code, string $jobStatus = '', string $worker = ''): string
{
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
            $requestResult['scheduled'] = true;
            $requestResult['detached'] = true;
            $requestResult['job'] = orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId));
            $requestResult['message'] = 'التنفيذ يعمل بالفعل على الخادم.';

            return $requestResult;
        }
        throw $e;
    }

    $requestResult['scheduled'] = !empty($scheduled['scheduled']);
    $requestResult['detached'] = !empty($scheduled['detached']);
    $requestResult['pid'] = (int) ($scheduled['pid'] ?? 0);
    $requestResult['worker'] = $workerKey;
    $requestResult['job'] = orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId));
    $requestResult['message'] = (string) ($scheduled['diagnostics']['reason_ar']
        ?? 'تم بدء التنفيذ على الخادم. يمكنك مغادرة الصفحة، وسيستمر التنفيذ.');
    $requestResult['diagnostics'] = is_array($scheduled['diagnostics'] ?? null)
        ? $scheduled['diagnostics']
        : ['code' => 'ok', 'reason_ar' => $requestResult['message']];

    return $requestResult;
}

/**
 * Safe diagnostics for Restore Center (no absolute paths, no secrets).
 *
 * @return array<string, mixed>
 */
function orange_restore_center_diagnostics(string $workRoot, string $jobId): array
{
    $job = orange_restore_fw_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');
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

    $failures = [];
    $step6Events = [];
    $latestStep6 = null;
    $step6EventNames = [
        'pre_restore_backup_requested',
        'pre_restore_backup_running',
        'pre_restore_backup_started',
        'pre_restore_backup_completed',
        'pre_restore_backup_failed',
        'pre_restore_backup_ready',
    ];
    $auditPath = orange_restore_fw_audit_file_path($workRoot, $jobId);
    if (is_file($auditPath)) {
        $lines = @file($auditPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            $slice = array_slice($lines, -120);
            foreach (array_reverse($slice) as $line) {
                $row = json_decode((string) $line, true);
                if (!is_array($row)) {
                    continue;
                }
                $event = (string) ($row['event'] ?? '');
                $at = (string) ($row['recorded_at'] ?? $row['at'] ?? $row['timestamp'] ?? '');

                if (in_array($event, $step6EventNames, true)) {
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
                    $item = [
                        'event' => $event,
                        'result' => (string) ($row['result'] ?? ''),
                        'worker' => 'pre_backup',
                        'code' => $category !== '' ? $category : $event,
                        'reason_ar' => $reasonAr,
                        'at' => $at,
                        'is_step6_attempt' => true,
                    ];
                    if ($latestStep6 === null) {
                        $latestStep6 = $item;
                    }
                    if (count($step6Events) < 12) {
                        $step6Events[] = $item;
                    }
                    continue;
                }

                if ($event !== 'restore_center_worker_schedule_failed' && $event !== 'restore_center_worker_scheduled') {
                    continue;
                }
                $code = (string) ($row['code'] ?? ($event === 'restore_center_worker_scheduled' ? 'ok' : 'restore_center_spawn_failed'));
                $failures[] = [
                    'event' => $event,
                    'result' => (string) ($row['result'] ?? ''),
                    'worker' => (string) ($row['worker'] ?? ''),
                    'code' => $code,
                    'reason_ar' => $code === 'ok'
                        ? 'تم جدولة العامل بنجاح.'
                        : orange_restore_center_operator_reason_ar($code, $status, (string) ($row['worker'] ?? '')),
                    'at' => $at,
                    'is_step6_attempt' => false,
                    'historical_only' => true,
                ];
                if (count($failures) >= 12) {
                    break;
                }
            }
        }
    }

    // Latest Step-6 attempt authority: never prefer stale worker-schedule failures when newer Step-6 events exist.
    $isStep6Status = str_starts_with($status, 'pre_restore_backup_');
    $recent = $isStep6Status || $latestStep6 !== null
        ? array_slice(array_merge($step6Events, $failures), 0, 12)
        : array_slice(array_merge($failures, $step6Events), 0, 12);

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
        $logSnippets[] = [
            'worker' => $workerKey,
            'log_name' => 'orchestrator_' . orange_restore_center_safe_worker_token($workerKey) . '.log',
            'tail' => trim($text) !== '' ? trim($text) : '(empty)',
        ];
    }

    return [
        'job_id' => $jobId,
        'job_status' => $status,
        'package_type' => (string) ($job['package_type'] ?? ''),
        'orchestrator_version' => ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION,
        'workers' => $workers,
        'recent_orchestration_events' => $recent,
        'latest_attempt_diagnostic' => $latestStep6,
        'log_tails' => $logSnippets,
        'notes_ar' => [
            'تشخيص تشغيل مراحل الاسترداد — عرض تشغيلي آمن من مركز الاسترداد فقط.',
            'لا تُعرض مسارات الخادم المطلقة ولا الأسرار ولا رموز الانتقال الداخلية.',
            'عند وجود محاولات حديثة للخطوة 6 تُعرض أحدث محاولة حالية؛ أحداث الجدولة القديمة تبقى تاريخية.',
            'يُرفض التنفيذ إذا كانت حالة المهمة لا تسمح بالمرحلة أو إذا كانت المرحلة تعمل.',
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

    try {
        $relative = orange_restore_center_assert_worker_key($workerKey);
        $script = orange_restore_center_resolve_worker_script($projectRoot, $relative);
        try {
            $phpBinary = orange_restore_worker_resolve_cli_php_binary($projectRoot);
        } catch (Throwable $resolveEx) {
            throw new RuntimeException(
                orange_restore_center_normalize_failure_code(trim($resolveEx->getMessage()))
            );
        }
        if (!orange_restore_worker_php_cli_path_is_absolute($phpBinary) || !is_file($phpBinary)) {
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

        $claim = [
            'job_id' => $jobId,
            'worker' => $workerKey,
            'script' => $relative,
            'pid' => $pid,
            'state' => 'running',
            'started_at' => gmdate('c'),
            'job_status_at_schedule' => $jobStatus,
            'operator_username' => $operatorUsername,
            'log_name' => 'orchestrator_' . orange_restore_center_safe_worker_token($workerKey) . '.log',
            'orchestrator_version' => ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION,
            'detached' => true,
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
            if ($code === 'restore_center_worker_executable_unavailable'
                && function_exists('orange_backup_admin_cli_php_safe_resolve_diag')) {
                // Categories only — no raw paths (Owner UI must stay path-free).
                $failAudit['resolve_diag'] = orange_backup_admin_cli_php_safe_resolve_diag($projectRoot);
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
                || $code === 'restore_center_worker_bootstrap_failed') {
                try {
                    orange_restore_center_discard_stale_launch_artifact($workRoot, $jobId, $workerKey);
                } catch (Throwable $ignoredDiscard) {
                    // non-fatal cleanup
                }
            }
        }
        if (trim($e->getMessage()) !== $code) {
            throw new RuntimeException($code, 0, $e);
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
            'reason_ar' => 'تم بدء التنفيذ على الخادم. يمكنك مغادرة الصفحة، وسيستمر التنفيذ.',
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
