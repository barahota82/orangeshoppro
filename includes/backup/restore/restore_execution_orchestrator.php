<?php

declare(strict_types=1);

/**
 * Phase 3B.3A — Restore Execution Orchestrator (framework only).
 *
 * Prepares a metadata-only execution plan after successful Dry Run.
 * Never executes SQL, restores DB/uploads, extracts archives, or mutates production.
 */

require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_dry_run.php';
require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../recovery_validation.php';

const ORANGE_RESTORE_EXEC_ORCH_VERSION = '3B.3A-execution-orchestrator';
const ORANGE_RESTORE_EXEC_PLAN_FILE = 'execution_plan.json';
const ORANGE_RESTORE_EXEC_PLAN_VERSION = '1';
const ORANGE_RESTORE_EXEC_LOCK_FILE = '.restore_execution_orchestrator.lock';
const ORANGE_RESTORE_EXEC_LOCK_STALE_SECONDS = 21600;
const ORANGE_RESTORE_EXEC_CONFIRMATION_PHRASE = 'RESTORE';

/**
 * @return list<string>
 */
function orange_restore_exec_active_statuses(): array
{
    return [
        ORANGE_RESTORE_FW_STATUS_EXECUTION_PRECHECK,
        ORANGE_RESTORE_FW_STATUS_EXECUTION_PLAN_READY,
        ORANGE_RESTORE_FW_STATUS_AWAITING_FINAL_APPROVAL,
        ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_RUNNING,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED,
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_RUNNING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_PENDING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_RUNNING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_WARNING,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_READY,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_MANUAL_REVIEW,
        ORANGE_RESTORE_FW_STATUS_MAINTENANCE_REQUESTED,
        ORANGE_RESTORE_FW_STATUS_MAINTENANCE_VALIDATING,
        ORANGE_RESTORE_FW_STATUS_MAINTENANCE_ACTIVE,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_PENDING,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_RUNNING,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_PENDING,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_RUNNING,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_PENDING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_DATABASE_RUNNING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_DATABASE_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_FILES_RUNNING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_FILES_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_READY,
        ORANGE_RESTORE_FW_STATUS_RESTORE_FINALIZING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_FINALIZING,
    ];
}

/**
 * @return list<string>
 */
function orange_restore_exec_cancellable_statuses(): array
{
    return orange_restore_exec_active_statuses();
}

/**
 * @return list<string>
 */
function orange_restore_exec_terminal_statuses(): array
{
    return [
        ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED,
        ORANGE_RESTORE_FW_STATUS_EXECUTION_FAILED,
        ORANGE_RESTORE_FW_STATUS_EXECUTION_COMPLETED,
        ORANGE_RESTORE_FW_STATUS_CANCELLED,
        ORANGE_RESTORE_FW_STATUS_FAILED,
        ORANGE_RESTORE_FW_STATUS_COMPLETED,
    ];
}

function orange_restore_exec_lock_path(string $workRoot): string
{
    return orange_restore_fw_root($workRoot) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_EXEC_LOCK_FILE;
}

function orange_restore_exec_plan_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_EXEC_PLAN_FILE;
}

/**
 * @return array{held:bool,payload:?array<string,mixed>,stale:bool}
 */
function orange_restore_exec_lock_status(string $workRoot): array
{
    $path = orange_restore_exec_lock_path($workRoot);
    if (!is_file($path)) {
        return ['held' => false, 'payload' => null, 'stale' => false];
    }
    $raw = (string) file_get_contents($path);
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return ['held' => true, 'payload' => null, 'stale' => true];
    }
    $stale = orange_restore_exec_lock_is_stale($payload);

    return ['held' => true, 'payload' => $payload, 'stale' => $stale];
}

/**
 * @param array<string, mixed> $payload
 */
function orange_restore_exec_lock_is_stale(array $payload): bool
{
    // Prefer heartbeat_at so long-running imports remain non-stale while alive.
    $hb = strtotime((string) ($payload['heartbeat_at'] ?? ''));
    if ($hb === false) {
        $hb = strtotime((string) ($payload['started_at'] ?? ''));
    }
    if ($hb !== false && (time() - $hb) > ORANGE_RESTORE_EXEC_LOCK_STALE_SECONDS) {
        return true;
    }
    $pid = (int) ($payload['pid'] ?? 0);
    if ($pid <= 0) {
        return true;
    }
    if (function_exists('posix_kill')) {
        return !@posix_kill($pid, 0);
    }
    if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!in_array('shell_exec', $disabled, true)) {
            $output = shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');

            return !(is_string($output) && preg_match('/\b' . preg_quote((string) $pid, '/') . '\b/', $output) === 1);
        }
    }

    return false;
}

/**
 * Refresh execution lock heartbeat for a held job (call during long CLI work).
 *
 * @return array{ok:bool,message:string}
 */
function orange_restore_exec_lock_heartbeat(string $workRoot, string $jobId): array
{
    $path = orange_restore_exec_lock_path($workRoot);
    if (!is_file($path)) {
        return ['ok' => false, 'message' => 'execution_lock_missing'];
    }
    $raw = (string) file_get_contents($path);
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'message' => 'execution_lock_corrupt'];
    }
    $heldJob = (string) ($payload['job_id'] ?? '');
    if ($heldJob !== '' && $heldJob !== $jobId) {
        return ['ok' => false, 'message' => 'execution_lock_job_mismatch'];
    }
    $payload['heartbeat_at'] = gmdate('c');
    $payload['pid'] = getmypid();
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return ['ok' => false, 'message' => 'execution_lock_encode_failed'];
    }
    $tmp = $path . '.hb.tmp';
    if (@file_put_contents($tmp, $encoded . "\n") === false) {
        return ['ok' => false, 'message' => 'execution_lock_heartbeat_write_failed'];
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);

        return ['ok' => false, 'message' => 'execution_lock_heartbeat_rename_failed'];
    }

    return ['ok' => true, 'message' => 'execution_lock_heartbeat_ok'];
}

/**
 * @return array{ok:bool,message:string,stale_cleared:bool}
 */
function orange_restore_exec_acquire_lock(string $workRoot, string $jobId): array
{
    $path = orange_restore_exec_lock_path($workRoot);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'message' => 'Cannot create execution orchestrator lock directory.', 'stale_cleared' => false];
    }

    $status = orange_restore_exec_lock_status($workRoot);
    if ($status['held'] && $status['stale']) {
        @unlink($path);
        $status = orange_restore_exec_lock_status($workRoot);
        $staleCleared = true;
    } else {
        $staleCleared = false;
    }
    if ($status['held'] && !$status['stale']) {
        $heldJob = (string) (($status['payload'] ?? [])['job_id'] ?? '');
        if ($heldJob !== '' && $heldJob !== $jobId) {
            return ['ok' => false, 'message' => 'execution_orchestration_already_active', 'stale_cleared' => false];
        }
        if ($heldJob === $jobId) {
            return ['ok' => true, 'message' => 'Execution orchestration lock already held by this job.', 'stale_cleared' => $staleCleared];
        }

        return ['ok' => false, 'message' => 'execution_orchestration_already_active', 'stale_cleared' => false];
    }

    $now = gmdate('c');
    $payload = json_encode([
        'job_id' => $jobId,
        'pid' => getmypid(),
        'started_at' => $now,
        'heartbeat_at' => $now,
        'orchestrator_version' => ORANGE_RESTORE_EXEC_ORCH_VERSION,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return ['ok' => false, 'message' => 'Lock payload encode failed.', 'stale_cleared' => $staleCleared];
    }
    $handle = @fopen($path, 'xb');
    if ($handle === false) {
        return ['ok' => false, 'message' => 'execution_orchestration_already_active', 'stale_cleared' => $staleCleared];
    }
    fwrite($handle, $payload . "\n");
    fclose($handle);

    return ['ok' => true, 'message' => 'Execution orchestration lock acquired.', 'stale_cleared' => $staleCleared];
}

function orange_restore_exec_release_lock(string $workRoot, ?string $expectedJobId = null): void
{
    $path = orange_restore_exec_lock_path($workRoot);
    if (!is_file($path)) {
        return;
    }
    if ($expectedJobId !== null) {
        $raw = (string) file_get_contents($path);
        $decoded = json_decode($raw, true);
        $heldJob = is_array($decoded) ? (string) ($decoded['job_id'] ?? '') : '';
        if ($heldJob !== '' && $heldJob !== $expectedJobId) {
            return;
        }
    }
    @unlink($path);
}

function orange_restore_exec_file_sha256(string $path): string
{
    if (!is_file($path)) {
        return '';
    }
    $hash = hash_file('sha256', $path);

    return is_string($hash) ? $hash : '';
}

/**
 * @return array<string, mixed>
 */
function orange_restore_exec_build_package_fingerprint(
    string $backupRoot,
    string $packageType,
    string $packageId,
    ?string $countryCode
): array {
    if ($packageType === 'full_disaster') {
        $packagePath = orange_backup_admin_resolve_full_package_path($backupRoot, $packageId);
    } elseif ($packageType === 'country_recovery') {
        $packagePath = orange_backup_admin_resolve_country_package_path($backupRoot, (string) $countryCode, $packageId);
    } else {
        throw new RuntimeException('Invalid package_type.');
    }

    $manifestPath = $packagePath . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifest = orange_backup_admin_read_json_if_exists($manifestPath);
    if ($manifest === null) {
        throw new RuntimeException('Package manifest missing for fingerprint.');
    }
    $manifestSha = orange_restore_exec_file_sha256($manifestPath);
    $checksumsSha = orange_restore_exec_file_sha256($packagePath . DIRECTORY_SEPARATOR . 'checksums.sha256');
    if ($checksumsSha === '') {
        $checksumsSha = hash(
            'sha256',
            (string) ($manifest['dump_sha256'] ?? '') . '|' . (string) ($manifest['uploads_sha256'] ?? '')
        );
    }
    $drvPath = orange_backup_admin_recovery_report_sibling_path($packagePath, $packageId);
    if (!is_file($drvPath)) {
        $drvPath = $packagePath . DIRECTORY_SEPARATOR . ORANGE_RECOVERY_VALIDATION_REPORT_FILE;
    }
    $drvSha = orange_restore_exec_file_sha256($drvPath);

    $material = implode('|', [
        $packageId,
        $packageType,
        strtoupper((string) ($countryCode ?? '')),
        (string) ((int) ($manifest['schema_revision'] ?? 0)),
        $manifestSha,
        $checksumsSha,
        $drvSha,
    ]);

    return [
        'fingerprint' => hash('sha256', $material),
        'package_id' => $packageId,
        'package_type' => $packageType,
        'country_code' => ($countryCode !== null && $countryCode !== '') ? strtoupper($countryCode) : null,
        'schema_revision' => (int) ($manifest['schema_revision'] ?? 0),
        'manifest_checksum' => $manifestSha,
        'package_checksum' => $checksumsSha,
        'drv_report_checksum' => $drvSha,
        'export_backend' => (string) ($manifest['export_backend'] ?? ''),
        'registry_version' => (string) ($manifest['registry_version'] ?? ''),
    ];
}

/**
 * WARNING policy: Full packages may proceed on Dry Run WARNING (adapter-aligned).
 * Country packages require Dry Run PASS.
 */
function orange_restore_exec_dry_run_result_allowed(string $packageType, string $overallResult): bool
{
    $overall = strtoupper(trim($overallResult));
    if ($overall === 'PASS') {
        return true;
    }
    if ($overall === 'WARNING' && $packageType === 'full_disaster') {
        return true;
    }

    return false;
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_exec_find_active_job(string $workRoot): ?array
{
    foreach (orange_restore_fw_list_ids($workRoot) as $jobId) {
        try {
            $job = orange_restore_fw_read($workRoot, $jobId);
        } catch (Throwable) {
            continue;
        }
        if (in_array((string) ($job['status'] ?? ''), orange_restore_exec_active_statuses(), true)) {
            return $job;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $plan
 */
function orange_restore_exec_write_plan(string $workRoot, string $jobId, array $plan): void
{
    $path = orange_restore_exec_plan_path($workRoot, $jobId);
    $json = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Cannot encode execution plan.');
    }
    if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write execution plan.');
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_exec_read_plan(string $workRoot, string $jobId): array
{
    $path = orange_restore_exec_plan_path($workRoot, $jobId);
    if (!is_file($path)) {
        throw new RuntimeException('Execution plan not found.');
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Cannot read execution plan.');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid execution plan payload.');
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $plan
 * @return array<string, mixed>
 */
function orange_restore_exec_public_plan(array $plan): array
{
    unset($plan['absolute_paths'], $plan['package_path'], $plan['secrets']);

    return [
        'plan_version' => (string) ($plan['plan_version'] ?? ''),
        'job_id' => (string) ($plan['job_id'] ?? ''),
        'package_id' => (string) ($plan['package_id'] ?? ''),
        'package_type' => (string) ($plan['package_type'] ?? ''),
        'country_code' => $plan['country_code'] ?? null,
        'created_at' => (string) ($plan['created_at'] ?? ''),
        'created_by' => (string) ($plan['created_by'] ?? ''),
        'package_fingerprint' => (string) (($plan['package_fingerprint'] ?? [])['fingerprint'] ?? $plan['package_fingerprint'] ?? ''),
        'package_fingerprint_details' => [
            'schema_revision' => (int) (($plan['package_fingerprint'] ?? [])['schema_revision'] ?? 0),
            'manifest_checksum' => (string) (($plan['package_fingerprint'] ?? [])['manifest_checksum'] ?? ''),
            'package_checksum' => (string) (($plan['package_fingerprint'] ?? [])['package_checksum'] ?? ''),
            'drv_report_checksum' => (string) (($plan['package_fingerprint'] ?? [])['drv_report_checksum'] ?? ''),
        ],
        'dry_run_fingerprint' => (string) ($plan['dry_run_fingerprint'] ?? ''),
        'preconditions' => is_array($plan['preconditions'] ?? null) ? $plan['preconditions'] : [],
        'planned_stages' => is_array($plan['planned_stages'] ?? null) ? $plan['planned_stages'] : [],
        'safety_gates' => is_array($plan['safety_gates'] ?? null) ? $plan['safety_gates'] : [],
        'rollback_strategy' => is_array($plan['rollback_strategy'] ?? null) ? $plan['rollback_strategy'] : [],
        'estimated_duration' => is_array($plan['estimated_duration'] ?? null) ? $plan['estimated_duration'] : [],
        'requires_final_approval' => (bool) ($plan['requires_final_approval'] ?? true),
        'execution_started' => (bool) ($plan['execution_started'] ?? false),
        'orchestrator_version' => (string) ($plan['orchestrator_version'] ?? ''),
        'version_lock' => is_array($plan['version_lock'] ?? null) ? $plan['version_lock'] : null,
        'warning' => 'No restore has been executed. This plan only describes the future operation.',
    ];
}

/**
 * Prepare metadata-only execution plan. Stops at awaiting_final_approval.
 *
 * @param array{backup_root:string,operator_username:string,operator_admin_id?:int} $context
 * @return array{job:array<string,mixed>,plan:array<string,mixed>}
 */
function orange_restore_exec_prepare_plan(string $workRoot, string $jobId, array $context): array
{
    $backupRoot = (string) ($context['backup_root'] ?? '');
    if ($backupRoot === '') {
        throw new RuntimeException('Backup root required.');
    }
    $operator = (string) ($context['operator_username'] ?? '');
    $operatorId = (int) ($context['operator_admin_id'] ?? 0);

    $job = orange_restore_fw_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');
    if ($status === ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED) {
        throw new RuntimeException('execution_plan_cancelled_reset_required');
    }
    if ($status === ORANGE_RESTORE_FW_STATUS_EXECUTION_FAILED) {
        throw new RuntimeException('execution_plan_failed_reset_required');
    }
    if (in_array($status, [
        ORANGE_RESTORE_FW_STATUS_EXECUTION_COMPLETED,
        ORANGE_RESTORE_FW_STATUS_CANCELLED,
        ORANGE_RESTORE_FW_STATUS_FAILED,
        ORANGE_RESTORE_FW_STATUS_COMPLETED,
    ], true)) {
        throw new RuntimeException('Job is terminal and cannot prepare execution plan.');
    }
    if (in_array($status, orange_restore_exec_active_statuses(), true)) {
        throw new RuntimeException('execution_orchestration_already_active');
    }
    if ($status !== ORANGE_RESTORE_FW_STATUS_DRY_COMPLETED) {
        throw new RuntimeException('Execution plan requires dry_completed status.');
    }

    $active = orange_restore_exec_find_active_job($workRoot);
    if ($active !== null && (string) ($active['job_id'] ?? '') !== $jobId) {
        throw new RuntimeException('execution_orchestration_already_active');
    }

    $lock = orange_restore_exec_acquire_lock($workRoot, $jobId);
    if (!$lock['ok']) {
        throw new RuntimeException((string) $lock['message']);
    }

    try {
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_execution_lock_acquired',
            'result' => 'ok',
            'stale_cleared' => !empty($lock['stale_cleared']),
            'operator_username' => $operator,
        ]);

        orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_EXECUTION_PRECHECK,
            ORANGE_RESTORE_FW_PHASE_EXECUTION_PRECHECK,
            10,
            'Execution precheck',
            'restore_execution_precheck_started'
        );

        $packageType = (string) ($job['package_type'] ?? '');
        $packageId = (string) ($job['package_id'] ?? '');
        $countryCode = (string) ($job['country_code'] ?? '');

        $dryReport = orange_restore_dry_run_read_report($workRoot, $jobId);
        if ($dryReport === null) {
            throw new RuntimeException('dry_run_report_missing');
        }
        if (!empty($dryReport['execution_performed'])) {
            throw new RuntimeException('execution_already_performed');
        }
        $dryOverall = strtoupper((string) ($dryReport['overall_result'] ?? ''));
        if ($dryOverall === 'FAIL') {
            throw new RuntimeException('dry_run_failed');
        }
        if (!orange_restore_exec_dry_run_result_allowed($packageType, $dryOverall)) {
            throw new RuntimeException('dry_run_warning_not_approved_for_package_type');
        }

        $reportPackageId = (string) ($dryReport['package_id'] ?? '');
        $reportPackageType = (string) ($dryReport['package_type'] ?? '');
        $reportCountry = strtoupper((string) ($dryReport['country_code'] ?? ''));
        if ($reportPackageId !== $packageId) {
            throw new RuntimeException('package_id_mismatch');
        }
        if ($reportPackageType !== $packageType) {
            throw new RuntimeException('package_type_mismatch');
        }
        if ($packageType === 'country_recovery' && $reportCountry !== strtoupper($countryCode)) {
            throw new RuntimeException('country_code_mismatch');
        }

        $fingerprint = orange_restore_exec_build_package_fingerprint(
            $backupRoot,
            $packageType,
            $packageId,
            $countryCode !== '' ? $countryCode : null
        );

        if (!function_exists('orange_restore_admin_package_eligibility')) {
            require_once __DIR__ . '/../restore_admin.php';
        }
        $eligibilitySummary = $packageType === 'full_disaster'
            ? orange_backup_admin_summarize_full_package(
                orange_backup_admin_resolve_full_package_path($backupRoot, $packageId),
                $packageId
            )
            : orange_backup_admin_summarize_country_package(
                orange_backup_admin_resolve_country_package_path($backupRoot, $countryCode, $packageId),
                $packageId,
                $countryCode
            );
        $eligibility = orange_restore_admin_package_eligibility($eligibilitySummary, $packageType);
        if (($eligibility['eligibility_status'] ?? '') !== 'eligible') {
            throw new RuntimeException('package_not_eligible');
        }

        if ((int) ($fingerprint['schema_revision'] ?? 0) !== ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION) {
            throw new RuntimeException('schema_incompatible');
        }
        if ($packageType === 'full_disaster') {
            $backend = strtolower(trim((string) ($fingerprint['export_backend'] ?? '')));
            if ($backend !== '' && $backend !== 'php_pdo') {
                throw new RuntimeException('backend_incompatible');
            }
        }

        $storedFp = (string) ($job['package_fingerprint'] ?? '');
        $dryFpStored = (string) ($job['dry_run_fingerprint'] ?? '');
        $dryReportPath = orange_restore_dry_run_report_path($workRoot, $jobId);
        $dryRunFingerprint = orange_restore_exec_file_sha256($dryReportPath);
        if ($storedFp !== '' && !hash_equals($storedFp, (string) $fingerprint['fingerprint'])) {
            throw new RuntimeException('package_changed_after_dry_run');
        }
        if ($dryFpStored !== '' && !hash_equals($dryFpStored, $dryRunFingerprint)) {
            throw new RuntimeException('package_changed_after_dry_run');
        }

        // First prepare: bind fingerprints from current package + dry report.
        if ($storedFp === '') {
            $job = orange_restore_fw_read($workRoot, $jobId);
            $job['package_fingerprint'] = (string) $fingerprint['fingerprint'];
            $job['dry_run_fingerprint'] = $dryRunFingerprint;
            orange_restore_fw_write($workRoot, $job);
        } else {
            // Re-validate live fingerprint still matches bound fingerprint.
            if (!hash_equals($storedFp, (string) $fingerprint['fingerprint'])) {
                throw new RuntimeException('package_changed_after_dry_run');
            }
        }

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_execution_precheck_passed',
            'result' => 'ok',
            'operator_username' => $operator,
            'dry_run_overall_result' => $dryOverall,
        ]);

        orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_EXECUTION_PLAN_READY,
            ORANGE_RESTORE_FW_PHASE_EXECUTION_PLAN_READY,
            60,
            'Execution plan ready',
            'restore_execution_plan_created'
        );

        $estimated = is_array($dryReport['estimated_duration'] ?? null) ? $dryReport['estimated_duration'] : ['seconds' => 0, 'human' => 'unknown'];
        $plan = [
            'plan_version' => ORANGE_RESTORE_EXEC_PLAN_VERSION,
            'job_id' => $jobId,
            'package_id' => $packageId,
            'package_type' => $packageType,
            'country_code' => $countryCode !== '' ? strtoupper($countryCode) : null,
            'created_at' => gmdate('c'),
            'created_by' => $operator,
            'created_by_admin_id' => $operatorId,
            'package_fingerprint' => $fingerprint,
            'dry_run_fingerprint' => $dryRunFingerprint,
            'preconditions' => [
                'dry_run_overall_result' => $dryOverall,
                'package_eligible' => true,
                'execution_performed' => false,
                'schema_compatible' => true,
                'backend_compatible' => true,
            ],
            'planned_stages' => [
                ['id' => 'acquire_maintenance_lock', 'label' => 'Acquire maintenance lock', 'executed' => false],
                ['id' => 'create_pre_restore_backup', 'label' => 'Create mandatory pre-restore backup', 'executed' => false],
                ['id' => 'validate_backup', 'label' => 'Validate backup', 'executed' => false],
                ['id' => 'restore_database', 'label' => 'Restore database', 'executed' => false],
                ['id' => 'verify_database', 'label' => 'Verify database', 'executed' => false],
                ['id' => 'restore_files', 'label' => 'Restore files', 'executed' => false],
                ['id' => 'verify_files', 'label' => 'Verify files', 'executed' => false],
                ['id' => 'smoke_tests', 'label' => 'Smoke tests', 'executed' => false],
                ['id' => 'finalize', 'label' => 'Finalize', 'executed' => false],
                ['id' => 'rollback_when_required', 'label' => 'Rollback when required', 'executed' => false],
            ],
            'safety_gates' => [
                'require_package_id_confirmation' => true,
                'require_job_id_confirmation' => true,
                'require_operator_identity' => true,
                'require_typed_confirmation_phrase' => ORANGE_RESTORE_EXEC_CONFIRMATION_PHRASE,
                'require_fresh_csrf' => true,
                'require_recent_authentication' => true,
                'require_mandatory_pre_restore_backup' => true,
                'require_rollback_readiness' => true,
            ],
            'rollback_strategy' => [
                'mode' => 'pre_restore_backup_anchor',
                'automatic' => false,
                'requires_approval' => true,
                'note' => 'Rollback is planned only; not implemented in this phase.',
            ],
            'estimated_duration' => $estimated,
            'requires_final_approval' => true,
            'execution_started' => false,
            'orchestrator_version' => ORANGE_RESTORE_EXEC_ORCH_VERSION,
        ];
        orange_restore_exec_write_plan($workRoot, $jobId, $plan);

        $job = orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_AWAITING_FINAL_APPROVAL,
            ORANGE_RESTORE_FW_PHASE_AWAITING_FINAL_APPROVAL,
            100,
            'Awaiting final approval',
            'restore_execution_waiting_final_approval'
        );
        $job['execution_plan_file'] = ORANGE_RESTORE_EXEC_PLAN_FILE;
        $job['requires_final_approval'] = true;
        $job['execution_started'] = false;
        $job['package_fingerprint'] = (string) $fingerprint['fingerprint'];
        $job['dry_run_fingerprint'] = $dryRunFingerprint;
        orange_restore_fw_write($workRoot, $job);

        return [
            'job' => orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId)),
            'plan' => orange_restore_exec_public_plan($plan),
        ];
    } catch (Throwable $e) {
        try {
            orange_restore_fw_audit_append($workRoot, $jobId, [
                'event' => 'restore_execution_precheck_failed',
                'result' => 'fail',
                'message' => $e->getMessage(),
                'operator_username' => $operator,
            ]);
            $current = orange_restore_fw_read($workRoot, $jobId);
            if (in_array((string) ($current['status'] ?? ''), orange_restore_exec_active_statuses(), true)
                || (string) ($current['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_EXECUTION_PRECHECK) {
                orange_restore_fw_transition(
                    $workRoot,
                    $jobId,
                    ORANGE_RESTORE_FW_STATUS_EXECUTION_FAILED,
                    ORANGE_RESTORE_FW_PHASE_EXECUTION_FAILED,
                    100,
                    'Execution precheck failed',
                    'restore_execution_precheck_failed'
                );
            }
        } catch (Throwable) {
            // ignore secondary
        }
        orange_restore_exec_release_lock($workRoot, $jobId);
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_execution_lock_released',
            'result' => 'ok',
            'operator_username' => $operator,
            'reason' => 'precheck_failed',
        ]);
        throw $e;
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_exec_cancel_plan(
    string $workRoot,
    string $jobId,
    string $cancelledBy,
    string $reason = ''
): array {
    $job = orange_restore_fw_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');
    if (!in_array($status, orange_restore_exec_cancellable_statuses(), true)) {
        throw new RuntimeException('Execution plan cannot be cancelled in status: ' . $status);
    }

    $job['status'] = ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED;
    $job['phase'] = ORANGE_RESTORE_FW_PHASE_EXECUTION_CANCELLED;
    $job['progress'] = 0;
    $job['message'] = 'Execution plan cancelled';
    $job['cancelled_by'] = $cancelledBy;
    $job['cancelled_at'] = gmdate('c');
    $job['cancellation_reason'] = $reason;
    $job['execution_started'] = false;
    orange_restore_fw_write($workRoot, $job);

    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_execution_cancelled',
        'result' => 'ok',
        'status' => $job['status'],
        'operator_username' => $cancelledBy,
        'cancellation_reason' => $reason,
    ]);

    orange_restore_exec_release_lock($workRoot, $jobId);
    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_execution_lock_released',
        'result' => 'ok',
        'operator_username' => $cancelledBy,
        'reason' => 'cancelled',
    ]);

    // Preserve execution_plan.json (forensic).
    return orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId));
}
