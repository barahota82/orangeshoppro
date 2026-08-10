<?php

declare(strict_types=1);

/**
 * Phase 3B.3B3 — Mandatory Pre-Restore Backup Gate (rollback anchor only).
 *
 * Authoritative Full Backup execution (Owner 2026-08-10):
 *   - orange_backup_execute_full_authoritative()  (same path as Backup Center)
 *     → CLI scripts/backup/run_full_backup.php → orange_backup_run_full()
 *
 * Restore-only adapter responsibilities after shared success:
 *   - orange_backup_verify_full_package() / DRV / retention pin
 *   - bind exact package identity to the Restore job
 *   - transition to pre_restore_backup_ready
 *
 * Never restores DB/files, never cutover, never enables production maintenance,
 * never uses a Step-6-only launcher / run-worker / launch.cmd path.
 */

require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_execution_orchestrator.php';
require_once __DIR__ . '/restore_execution_bridge.php';
require_once __DIR__ . '/restore_final_approval.php';
require_once __DIR__ . '/restore_version_lock.php';
require_once __DIR__ . '/restore_dry_run.php';
require_once __DIR__ . '/../backup_runner.php';
require_once __DIR__ . '/../backup_full.php';
require_once __DIR__ . '/../backup_retention.php';
require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../recovery_validation.php';

const ORANGE_RESTORE_PRE_BACKUP_RECORD_VERSION = '3B.3B3-v1';
const ORANGE_RESTORE_PRE_BACKUP_FILE = 'pre_restore_backup.json';
const ORANGE_RESTORE_PRE_BACKUP_LOCK_FILE = '.pre_restore_backup.lock';
const ORANGE_RESTORE_PRE_BACKUP_LOCK_STALE_SECONDS = 21600;
const ORANGE_RESTORE_PRE_BACKUP_PURPOSE = 'pre_restore_rollback_anchor';
const ORANGE_RESTORE_PRE_BACKUP_DRV_MIN_SCORE = 70;
const ORANGE_RESTORE_PRE_BACKUP_ENGINE_VERSION = 'orange_backup_execute_full_authoritative';

function orange_restore_pre_backup_record_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRE_BACKUP_FILE;
}

function orange_restore_pre_backup_lock_path(string $workRoot): string
{
    return orange_restore_fw_root($workRoot) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRE_BACKUP_LOCK_FILE;
}

/**
 * @return array{held:bool,payload:?array<string,mixed>,stale:bool,pid_alive:?bool}
 */
function orange_restore_pre_backup_lock_status(string $workRoot): array
{
    $path = orange_restore_pre_backup_lock_path($workRoot);
    if (!is_file($path)) {
        return ['held' => false, 'payload' => null, 'stale' => false, 'pid_alive' => null];
    }
    $raw = (string) file_get_contents($path);
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return ['held' => true, 'payload' => null, 'stale' => true, 'pid_alive' => null];
    }

    $acquiredAt = strtotime((string) ($payload['acquired_at'] ?? $payload['heartbeat_at'] ?? ''));
    $age = $acquiredAt !== false ? (time() - $acquiredAt) : PHP_INT_MAX;
    $pid = (int) ($payload['pid'] ?? 0);
    $pidAlive = null;
    if ($pid > 0) {
        if (function_exists('posix_kill')) {
            $pidAlive = @posix_kill($pid, 0);
        } elseif (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
            $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
            if (!in_array('shell_exec', $disabled, true)) {
                $output = shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');
                $pidAlive = is_string($output) && preg_match('/\b' . preg_quote((string) $pid, '/') . '\b/', $output) === 1;
            }
        }
    }

    // Conservative: only stale when age exceeded AND process is proven dead (or pid unknown).
    $stale = $age > ORANGE_RESTORE_PRE_BACKUP_LOCK_STALE_SECONDS && $pidAlive !== true;

    return ['held' => true, 'payload' => $payload, 'stale' => $stale, 'pid_alive' => $pidAlive];
}

/**
 * @return array{ok:bool,message:string,stale_cleared:bool}
 */
function orange_restore_pre_backup_acquire_lock(string $workRoot, string $jobId, string $owner): array
{
    $path = orange_restore_pre_backup_lock_path($workRoot);
    $status = orange_restore_pre_backup_lock_status($workRoot);
    $staleCleared = false;
    if ($status['held'] && $status['stale']) {
        @unlink($path);
        $staleCleared = true;
        $status = orange_restore_pre_backup_lock_status($workRoot);
    }
    if ($status['held'] && !$status['stale']) {
        return ['ok' => false, 'message' => 'pre_restore_backup_lock_active', 'stale_cleared' => $staleCleared];
    }

    $payload = json_encode([
        'job_id' => $jobId,
        'owner' => $owner,
        'pid' => getmypid(),
        'acquired_at' => gmdate('c'),
        'heartbeat_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $handle = @fopen($path, 'xb');
    if ($handle === false || $payload === false) {
        return ['ok' => false, 'message' => 'pre_restore_backup_lock_active', 'stale_cleared' => $staleCleared];
    }
    fwrite($handle, $payload . "\n");
    fclose($handle);

    return ['ok' => true, 'message' => 'ok', 'stale_cleared' => $staleCleared];
}

function orange_restore_pre_backup_heartbeat(string $workRoot, string $jobId): void
{
    $path = orange_restore_pre_backup_lock_path($workRoot);
    if (!is_file($path)) {
        return;
    }
    $raw = (string) file_get_contents($path);
    $payload = json_decode($raw, true);
    if (!is_array($payload) || (string) ($payload['job_id'] ?? '') !== $jobId) {
        return;
    }
    $payload['heartbeat_at'] = gmdate('c');
    file_put_contents(
        $path,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        LOCK_EX
    );
}

function orange_restore_pre_backup_release_lock(string $workRoot, ?string $expectedJobId = null): void
{
    $path = orange_restore_pre_backup_lock_path($workRoot);
    if (!is_file($path)) {
        return;
    }
    if ($expectedJobId !== null) {
        $raw = (string) file_get_contents($path);
        $decoded = json_decode($raw, true);
        $held = is_array($decoded) ? (string) ($decoded['job_id'] ?? '') : '';
        if ($held !== '' && $held !== $expectedJobId) {
            return;
        }
    }
    @unlink($path);
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_pre_backup_load_record(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_pre_backup_record_path($workRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $record
 */
function orange_restore_pre_backup_write_record(string $workRoot, string $jobId, array $record): void
{
    $path = orange_restore_pre_backup_record_path($workRoot, $jobId);
    $record['execution_started'] = false;
    unset($record['absolute_paths'], $record['package_path'], $record['snapshot_path'], $record['password'], $record['secrets']);
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write pre_restore_backup.json');
    }
}

/**
 * @param array<string, mixed> $record
 * @return array<string, mixed>
 */
function orange_restore_pre_backup_public_record(array $record): array
{
    unset($record['absolute_paths'], $record['package_path'], $record['snapshot_path'], $record['password'], $record['secrets']);

    return [
        'record_version' => (string) ($record['record_version'] ?? ''),
        'framework_job_id' => (string) ($record['framework_job_id'] ?? ''),
        'source_package_id' => (string) ($record['source_package_id'] ?? ''),
        'rollback_package_id' => (string) ($record['rollback_package_id'] ?? ''),
        'rollback_package_type' => (string) ($record['rollback_package_type'] ?? 'full'),
        'created_at' => (string) ($record['created_at'] ?? ''),
        'created_by' => (string) ($record['created_by'] ?? ''),
        'backup_engine_version' => (string) ($record['backup_engine_version'] ?? ''),
        'schema_revision' => (int) ($record['schema_revision'] ?? 0),
        'backend' => (string) ($record['backend'] ?? ''),
        'verify_result' => (string) ($record['verify_result'] ?? ''),
        'drv_result' => (string) ($record['drv_result'] ?? ''),
        'package_fingerprint' => (string) ($record['package_fingerprint'] ?? ''),
        'rollback_package_fingerprint' => (string) ($record['rollback_package_fingerprint'] ?? ''),
        'retention_pin_id' => (string) ($record['retention_pin_id'] ?? ''),
        'retention_pinned' => (bool) ($record['retention_pinned'] ?? false),
        'ready_for_rollback' => (bool) ($record['ready_for_rollback'] ?? false),
        'execution_started' => false,
        'identity_tag' => (string) ($record['identity_tag'] ?? ''),
        'purpose' => (string) ($record['purpose'] ?? ORANGE_RESTORE_PRE_BACKUP_PURPOSE),
        'status' => (string) ($record['status'] ?? ''),
        'cli_needed' => (bool) ($record['cli_needed'] ?? false),
        'failure_code' => (string) ($record['failure_code'] ?? ''),
        'warning' => (string) ($record['warning'] ?? 'لن يبدأ الاسترداد قبل إنشاء نسخة Full احتياطية موثقة ومثبتة ضد الحذف.'),
    ];
}

function orange_restore_pre_backup_identity_tag(
    string $jobId,
    string $sourcePackageId,
    string $operator,
    string $timestamp
): string {
    return implode('|', [
        'prera',
        $jobId,
        $sourcePackageId,
        $operator !== '' ? $operator : 'operator',
        $timestamp,
        ORANGE_RESTORE_PRE_BACKUP_PURPOSE,
    ]);
}

/**
 * Revalidate approval + contract before request/run.
 *
 * @return array{ok:bool,code:string,job:array<string,mixed>,contract:array<string,mixed>}
 */
function orange_restore_pre_backup_revalidate(
    string $workRoot,
    string $jobId,
    string $backupRoot
): array {
    if (isset($GLOBALS['orange_pre_restore_backup_revalidate_override'])
        && is_callable($GLOBALS['orange_pre_restore_backup_revalidate_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_pre_restore_backup_revalidate_override'];
        $over = $fn($workRoot, $jobId, $backupRoot);
        if (is_array($over)) {
            return $over;
        }
    }

    $job = orange_restore_fw_read($workRoot, $jobId);
    $packageType = (string) ($job['package_type'] ?? '');
    if ($packageType === 'country_recovery') {
        return ['ok' => false, 'code' => 'country_production_restore_not_enabled', 'job' => $job, 'contract' => []];
    }
    if ($packageType !== 'full_disaster') {
        return ['ok' => false, 'code' => 'package_type_mismatch', 'job' => $job, 'contract' => []];
    }

    $status = (string) ($job['status'] ?? '');
    $allowed = [
        ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_RUNNING,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED,
    ];
    if (!in_array($status, $allowed, true)) {
        return ['ok' => false, 'code' => 'invalid_status', 'job' => $job, 'contract' => []];
    }

    if (!is_file(orange_restore_final_approval_record_path($workRoot, $jobId))) {
        return ['ok' => false, 'code' => 'final_approval_missing', 'job' => $job, 'contract' => []];
    }

    try {
        $contract = orange_restore_load_execution_contract($workRoot, $jobId);
    } catch (Throwable) {
        return ['ok' => false, 'code' => 'contract_missing', 'job' => $job, 'contract' => []];
    }

    $validation = orange_restore_validate_execution_contract($workRoot, $jobId, $backupRoot, $contract);
    if (!($validation['ok'] ?? false)) {
        $code = (string) ($validation['code'] ?? 'version_mismatch');
        if (in_array($code, ['package_changed', 'plan_changed', 'approval_changed'], true)) {
            return ['ok' => false, 'code' => $code, 'job' => $job, 'contract' => $contract];
        }

        return ['ok' => false, 'code' => $code !== '' ? $code : 'version_mismatch', 'job' => $job, 'contract' => $contract];
    }

    $versionLock = orange_restore_version_lock_evaluate($workRoot, $jobId, $backupRoot);
    if (!($versionLock['ok'] ?? false)) {
        return ['ok' => false, 'code' => 'version_mismatch', 'job' => $job, 'contract' => $contract];
    }

    return ['ok' => true, 'code' => 'ok', 'job' => $job, 'contract' => $contract];
}

/**
 * HTTP: mark Step-6 request metadata (no operator CLI; execution is via shared Full Backup service).
 *
 * @return array<string, mixed>
 */
function orange_restore_pre_backup_request(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    array $admin
): array {
    $operator = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin'));
    if ($operator === '') {
        $operator = 'admin';
    }

    $check = orange_restore_pre_backup_revalidate($workRoot, $jobId, $backupRoot);
    if (!$check['ok']) {
        throw new RuntimeException((string) $check['code']);
    }
    $job = $check['job'];
    $status = (string) ($job['status'] ?? '');

    $existing = orange_restore_pre_backup_load_record($workRoot, $jobId);
    if ($status === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY
        && is_array($existing)
        && !empty($existing['ready_for_rollback'])
        && !empty($existing['retention_pinned'])) {
        return [
            'job' => orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId)),
            'record' => orange_restore_pre_backup_public_record($existing),
            'cli_needed' => false,
            'idempotent' => true,
            'execution_started' => false,
            'message' => 'Pre-restore backup already ready.',
        ];
    }

    if (in_array($status, [
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_RUNNING,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_VERIFYING,
    ], true)) {
        $record = $existing ?? [
            'record_version' => ORANGE_RESTORE_PRE_BACKUP_RECORD_VERSION,
            'framework_job_id' => $jobId,
            'source_package_id' => (string) ($job['package_id'] ?? ''),
            'status' => $status,
            'cli_needed' => false,
            'execution_started' => false,
            'ready_for_rollback' => false,
            'retention_pinned' => false,
        ];

        return [
            'job' => orange_restore_fw_public_row($job),
            'record' => orange_restore_pre_backup_public_record($record),
            'cli_needed' => false,
            'idempotent' => true,
            'execution_started' => false,
            'message' => 'Pre-restore backup already in progress or pending execution.',
        ];
    }

    if ($status === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED) {
        if (is_array($existing) && !empty($existing['ready_for_rollback'])) {
            throw new RuntimeException('rollback_anchor_already_exists');
        }
        $lock = orange_restore_pre_backup_lock_status($workRoot);
        if ($lock['held'] && !$lock['stale']) {
            throw new RuntimeException('pre_restore_backup_lock_active');
        }
    } elseif ($status !== ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION) {
        throw new RuntimeException('invalid_status');
    }

    $ts = gmdate('Y-m-d\TH:i:s\Z');
    $identity = orange_restore_pre_backup_identity_tag(
        $jobId,
        (string) ($job['package_id'] ?? ''),
        $operator,
        $ts
    );

    $record = [
        'record_version' => ORANGE_RESTORE_PRE_BACKUP_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => (string) ($job['package_id'] ?? ''),
        'rollback_package_id' => '',
        'rollback_package_type' => 'full',
        'created_at' => $ts,
        'created_by' => $operator,
        'backup_engine_version' => ORANGE_RESTORE_PRE_BACKUP_ENGINE_VERSION,
        'schema_revision' => 0,
        'backend' => '',
        'verify_result' => '',
        'drv_result' => '',
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'rollback_package_fingerprint' => '',
        'retention_pin_id' => '',
        'retention_pinned' => false,
        'ready_for_rollback' => false,
        'execution_started' => false,
        'identity_tag' => $identity,
        'purpose' => ORANGE_RESTORE_PRE_BACKUP_PURPOSE,
        'status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING,
        'cli_needed' => false,
        'cli_command' => '',
        'origin' => 'pre_restore_backup',
        'warning' => 'لن يبدأ الاسترداد قبل إنشاء نسخة Full احتياطية موثقة ومثبتة ضد الحذف.',
    ];
    orange_restore_pre_backup_write_record($workRoot, $jobId, $record);

    $job = orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING,
        ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_PENDING,
        10,
        'Pre-restore backup pending — shared Full Backup service',
        'pre_restore_backup_requested'
    );
    $job['pre_restore_backup_file'] = ORANGE_RESTORE_PRE_BACKUP_FILE;
    $job['pre_restore_backup_status'] = ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING;
    $job['execution_started'] = false;
    orange_restore_fw_write($workRoot, $job);

    return [
        'job' => orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId)),
        'record' => orange_restore_pre_backup_public_record($record),
        'cli_needed' => false,
        'idempotent' => false,
        'execution_started' => false,
        'message' => 'Pre-restore backup requested; shared Full Backup service will execute.',
    ];
}

/**
 * Invoke the single authoritative Full Backup service (Backup Center path).
 * Does not implement a second dump/package engine.
 *
 * @return array{ok:bool,snapshot:?string,backend:?string,message:string,exit_code:int}
 */
function orange_restore_pre_backup_invoke_engine(string $projectRoot, ?string $backupRootOverride): array
{
    if (isset($GLOBALS['orange_pre_restore_backup_engine_override'])
        && is_callable($GLOBALS['orange_pre_restore_backup_engine_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_pre_restore_backup_engine_override'];
        $result = $fn($projectRoot, $backupRootOverride);
        if (!is_array($result)) {
            return ['ok' => false, 'snapshot' => null, 'backend' => null, 'message' => 'engine_override_invalid', 'exit_code' => 1];
        }

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'snapshot' => isset($result['snapshot']) ? (string) $result['snapshot'] : null,
            'backend' => isset($result['backend']) ? (string) $result['backend'] : null,
            'message' => (string) ($result['message'] ?? ''),
            'exit_code' => (int) ($result['exit_code'] ?? (($result['ok'] ?? false) ? 0 : 1)),
        ];
    }

    // Disposable/self-test harness may inject Backup Center options through the shared service.
    $options = [
        // Exact package binding: never invent identity by scanning newest snapshot dir.
        'forbid_latest_snapshot_refresh' => true,
    ];
    if (isset($GLOBALS['orange_backup_execute_full_authoritative_options'])
        && is_array($GLOBALS['orange_backup_execute_full_authoritative_options'])) {
        $options = array_merge($options, $GLOBALS['orange_backup_execute_full_authoritative_options']);
        $options['forbid_latest_snapshot_refresh'] = true;
    }

    // $backupRootOverride is intentionally unused here: the shared CLI path uses BackupRoot from env
    // (same as Backup Center). Fixtures must use engine override, not a second dump path.
    if ($backupRootOverride !== null && $backupRootOverride !== '') {
        // no-op: keep signature for callers/tests; never open a parallel engine.
    }

    $raw = orange_backup_execute_full_authoritative($projectRoot, $options);

    return [
        'ok' => (bool) ($raw['ok'] ?? false),
        'snapshot' => isset($raw['snapshot']) && is_string($raw['snapshot']) ? $raw['snapshot'] : null,
        'backend' => isset($raw['backend']) ? (is_string($raw['backend']) ? $raw['backend'] : null) : null,
        'message' => (string) ($raw['message'] ?? ''),
        'exit_code' => (int) ($raw['exit_code'] ?? 1),
    ];
}

/**
 * @return array{ok:bool,errors:list<string>,manifest:?array<string,mixed>,health:?array<string,mixed>}
 */
function orange_restore_pre_backup_invoke_verify(string $packagePath): array
{
    if (isset($GLOBALS['orange_pre_restore_backup_verify_override'])
        && is_callable($GLOBALS['orange_pre_restore_backup_verify_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_pre_restore_backup_verify_override'];
        $result = $fn($packagePath);

        return is_array($result) ? $result : ['ok' => false, 'errors' => ['verify_override_invalid'], 'manifest' => null, 'health' => null];
    }

    return orange_backup_verify_full_package($packagePath);
}

/**
 * @return array<string, mixed>
 */
function orange_restore_pre_backup_invoke_drv(string $packagePath): array
{
    if (isset($GLOBALS['orange_pre_restore_backup_drv_override'])
        && is_callable($GLOBALS['orange_pre_restore_backup_drv_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_pre_restore_backup_drv_override'];
        $result = $fn($packagePath);

        return is_array($result) ? $result : ['recovery_score' => 0, 'overall_result' => 'fail'];
    }

    return orange_recovery_validate_package($packagePath);
}

/**
 * Step-6 adapter: run shared Full Backup + verify + bind + ready.
 * Callable from Admin HTTP and from the optional thin CLI entry (not orchestrator-launched).
 *
 * @return array<string, mixed>
 */
function orange_restore_pre_backup_execute(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    string $jobId,
    string $owner = 'admin'
): array {
    $check = orange_restore_pre_backup_revalidate($workRoot, $jobId, $backupRoot);
    if (!$check['ok']) {
        throw new RuntimeException((string) $check['code']);
    }
    $job = $check['job'];
    $status = (string) ($job['status'] ?? '');

    $existing = orange_restore_pre_backup_load_record($workRoot, $jobId);
    if ($status === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY
        && is_array($existing)
        && !empty($existing['ready_for_rollback'])
        && !empty($existing['retention_pinned'])
        && (string) ($existing['rollback_package_id'] ?? '') !== '') {
        return [
            'ok' => true,
            'idempotent' => true,
            'result' => 'PASS',
            'job_id' => $jobId,
            'rollback_package_id' => (string) $existing['rollback_package_id'],
            'verify' => (string) ($existing['verify_result'] ?? 'PASS'),
            'drv' => (string) ($existing['drv_result'] ?? 'PASS'),
            'retention_pinned' => true,
            'execution_started' => false,
            'record' => orange_restore_pre_backup_public_record($existing),
        ];
    }

    if (!in_array($status, [
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED,
    ], true)) {
        if ($status === ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION) {
            // Convenience: allow CLI when request step was skipped.
            orange_restore_pre_backup_request($workRoot, $jobId, $backupRoot, [
                'username' => $owner,
                'id' => 0,
            ]);
            $job = orange_restore_fw_read($workRoot, $jobId);
            $status = (string) ($job['status'] ?? '');
        } else {
            throw new RuntimeException('invalid_status');
        }
    }

    if ($status === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED
        && is_array($existing)
        && !empty($existing['ready_for_rollback'])) {
        throw new RuntimeException('rollback_anchor_already_exists');
    }

    $lock = orange_restore_pre_backup_acquire_lock($workRoot, $jobId, $owner);
    if (!$lock['ok']) {
        throw new RuntimeException((string) $lock['message']);
    }

    $partial = orange_restore_pre_backup_load_record($workRoot, $jobId) ?? [
        'record_version' => ORANGE_RESTORE_PRE_BACKUP_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => (string) ($job['package_id'] ?? ''),
        'rollback_package_type' => 'full',
        'purpose' => ORANGE_RESTORE_PRE_BACKUP_PURPOSE,
        'execution_started' => false,
    ];

    try {
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'pre_restore_backup_started',
            'result' => 'ok',
            'owner' => $owner,
        ]);

        orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_RUNNING,
            ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_RUNNING,
            35,
            'Creating mandatory Full pre-restore backup',
            'pre_restore_backup_started'
        );
        $partial['status'] = ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_RUNNING;
        $partial['cli_needed'] = false;
        $partial['origin'] = ORANGE_RESTORE_PRE_BACKUP_PURPOSE;
        $partial['cli_command'] = '';
        orange_restore_pre_backup_write_record($workRoot, $jobId, $partial);
        orange_restore_pre_backup_heartbeat($workRoot, $jobId);

        $engine = orange_restore_pre_backup_invoke_engine($projectRoot, $backupRoot);
        if (!$engine['ok'] || $engine['snapshot'] === null || $engine['snapshot'] === '') {
            throw new RuntimeException('backup_engine_failed');
        }

        $rollbackPackageId = basename(str_replace('\\', '/', (string) $engine['snapshot']));
        // Engine may return absolute path or bare name.
        if (str_contains((string) $engine['snapshot'], DIRECTORY_SEPARATOR)
            || str_contains((string) $engine['snapshot'], '/')) {
            $rollbackPackageId = basename(str_replace('\\', '/', (string) $engine['snapshot']));
        } else {
            $rollbackPackageId = (string) $engine['snapshot'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $rollbackPackageId)) {
            throw new RuntimeException('backup_package_id_invalid');
        }

        $packagePath = orange_backup_admin_resolve_full_package_path($backupRoot, $rollbackPackageId);
        $partial['rollback_package_id'] = $rollbackPackageId;
        $partial['backend'] = (string) ($engine['backend'] ?? 'php_pdo');
        $partial['backup_engine_version'] = ORANGE_RESTORE_PRE_BACKUP_ENGINE_VERSION;
        orange_restore_pre_backup_write_record($workRoot, $jobId, $partial);

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'pre_restore_backup_completed',
            'result' => 'ok',
            'rollback_package_id' => $rollbackPackageId,
        ]);

        orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_VERIFYING,
            ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_VERIFYING,
            65,
            'Verifying pre-restore Full backup',
            'pre_restore_backup_verification_started'
        );
        $partial['status'] = ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_VERIFYING;
        orange_restore_pre_backup_write_record($workRoot, $jobId, $partial);
        orange_restore_pre_backup_heartbeat($workRoot, $jobId);

        $verify = orange_restore_pre_backup_invoke_verify($packagePath);
        if (!($verify['ok'] ?? false)) {
            orange_restore_fw_audit_append($workRoot, $jobId, [
                'event' => 'pre_restore_backup_verification_failed',
                'result' => 'fail',
                'stage' => 'verify',
            ]);
            throw new RuntimeException('verify_failed');
        }
        $manifest = is_array($verify['manifest'] ?? null) ? $verify['manifest'] : [];
        $health = is_array($verify['health'] ?? null) ? $verify['health'] : [];
        $healthStatus = strtolower((string) ($health['package_status'] ?? $manifest['backup_status'] ?? ''));
        if ($healthStatus !== '' && !in_array($healthStatus, ['healthy', 'success', 'pass'], true)) {
            throw new RuntimeException('health_unacceptable');
        }
        $schemaRevision = (int) ($manifest['schema_revision'] ?? 0);
        if ($schemaRevision !== ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION) {
            throw new RuntimeException('schema_mismatch');
        }
        $backend = strtolower(trim((string) ($manifest['export_backend'] ?? $engine['backend'] ?? '')));
        if ($backend !== '' && $backend !== 'php_pdo') {
            throw new RuntimeException('backend_mismatch');
        }

        $partial['verify_result'] = 'PASS';
        $partial['schema_revision'] = $schemaRevision;
        $partial['backend'] = $backend !== '' ? $backend : 'php_pdo';

        // DRV required by approved Full rollback policy (same gate as fresh_backup_gate).
        $drv = orange_restore_pre_backup_invoke_drv($packagePath);
        $drvScore = (int) ($drv['recovery_score'] ?? 0);
        $drvOverall = strtolower((string) ($drv['overall_result'] ?? ''));
        if ($drvScore < ORANGE_RESTORE_PRE_BACKUP_DRV_MIN_SCORE || $drvOverall === 'fail') {
            orange_restore_fw_audit_append($workRoot, $jobId, [
                'event' => 'pre_restore_backup_verification_failed',
                'result' => 'fail',
                'stage' => 'drv',
                'recovery_score' => $drvScore,
            ]);
            throw new RuntimeException('drv_failed');
        }
        $partial['drv_result'] = 'PASS';

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'pre_restore_backup_verification_passed',
            'result' => 'ok',
            'verify_result' => 'PASS',
            'drv_result' => 'PASS',
        ]);

        $fp = orange_restore_exec_build_package_fingerprint(
            $backupRoot,
            'full_disaster',
            $rollbackPackageId,
            null
        );
        $rollbackFp = (string) ($fp['fingerprint'] ?? '');
        if ($rollbackFp === '') {
            throw new RuntimeException('package_fingerprint_unstable');
        }
        $partial['rollback_package_fingerprint'] = $rollbackFp;
        $partial['package_fingerprint'] = (string) ($job['package_fingerprint'] ?? '');

        // Package must remain readable.
        if (!is_dir($packagePath) || !is_file($packagePath . DIRECTORY_SEPARATOR . 'manifest.json')) {
            throw new RuntimeException('package_unreadable');
        }

        $identity = (string) ($partial['identity_tag'] ?? '');
        if ($identity === '') {
            $identity = orange_restore_pre_backup_identity_tag(
                $jobId,
                (string) ($job['package_id'] ?? ''),
                (string) ($partial['created_by'] ?? $owner),
                gmdate('c')
            );
            $partial['identity_tag'] = $identity;
        }

        // Sidecar identity inside package (no rename — retention name pattern is fixed).
        $metaPath = $packagePath . DIRECTORY_SEPARATOR . 'pre_restore_anchor_meta.json';
        file_put_contents(
            $metaPath,
            json_encode([
                'purpose' => ORANGE_RESTORE_PRE_BACKUP_PURPOSE,
                'framework_job_id' => $jobId,
                'source_package_id' => (string) ($job['package_id'] ?? ''),
                'identity_tag' => $identity,
                'created_by' => (string) ($partial['created_by'] ?? $owner),
                'created_at' => gmdate('c'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n",
            LOCK_EX
        );

        $pin = orange_backup_retention_pin_package($backupRoot, $rollbackPackageId, [
            'framework_job_id' => $jobId,
            'reason' => ORANGE_BACKUP_RETENTION_PIN_REASON_PRE_RESTORE,
            'source_package_id' => (string) ($job['package_id'] ?? ''),
            'created_by' => (string) ($partial['created_by'] ?? $owner),
            'purpose' => ORANGE_RESTORE_PRE_BACKUP_PURPOSE,
            'identity_tag' => $identity,
        ]);
        if (!orange_backup_retention_is_pinned($backupRoot, $rollbackPackageId)) {
            throw new RuntimeException('retention_pin_failed');
        }

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'pre_restore_backup_pinned',
            'result' => 'ok',
            'retention_pin_id' => (string) ($pin['pin_id'] ?? ''),
            'rollback_package_id' => $rollbackPackageId,
        ]);

        $partial['retention_pin_id'] = (string) ($pin['pin_id'] ?? '');
        $partial['retention_pinned'] = true;
        $partial['ready_for_rollback'] = true;
        $partial['execution_started'] = false;
        $partial['status'] = ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY;
        $partial['cli_needed'] = false;
        $partial['failure_code'] = '';
        $partial['created_at'] = (string) ($partial['created_at'] ?? gmdate('c'));
        $partial['created_by'] = (string) ($partial['created_by'] ?? $owner);
        $partial['warning'] = 'لن يبدأ الاسترداد قبل إنشاء نسخة Full احتياطية موثقة ومثبتة ضد الحذف.';
        orange_restore_pre_backup_write_record($workRoot, $jobId, $partial);

        $job = orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
            ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_READY,
            100,
            'Pre-restore Full backup ready and retention-pinned',
            'pre_restore_backup_ready'
        );
        $job['pre_restore_backup_file'] = ORANGE_RESTORE_PRE_BACKUP_FILE;
        $job['pre_restore_backup_status'] = ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY;
        $job['rollback_package_id'] = $rollbackPackageId;
        $job['execution_started'] = false;
        orange_restore_fw_write($workRoot, $job);

        orange_restore_pre_backup_release_lock($workRoot, $jobId);

        return [
            'ok' => true,
            'idempotent' => false,
            'result' => 'PASS',
            'job_id' => $jobId,
            'rollback_package_id' => $rollbackPackageId,
            'verify' => 'PASS',
            'drv' => 'PASS',
            'retention_pinned' => true,
            'execution_started' => false,
            'record' => orange_restore_pre_backup_public_record($partial),
        ];
    } catch (Throwable $e) {
        $code = trim($e->getMessage());
        if ($code === '') {
            $code = 'pre_restore_backup_failed';
        }
        $partial['status'] = ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED;
        $partial['ready_for_rollback'] = false;
        $partial['retention_pinned'] = !empty($partial['retention_pinned']);
        $partial['failure_code'] = $code;
        $partial['execution_started'] = false;
        $partial['cli_needed'] = false;
        $partial['cli_command'] = '';
        try {
            orange_restore_pre_backup_write_record($workRoot, $jobId, $partial);
        } catch (Throwable) {
            // preserve best-effort
        }
        try {
            orange_restore_fw_transition(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED,
                ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_FAILED,
                100,
                'Pre-restore backup failed: ' . $code,
                'pre_restore_backup_failed'
            );
            $failedJob = orange_restore_fw_read($workRoot, $jobId);
            $failedJob['pre_restore_backup_status'] = ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED;
            $failedJob['execution_started'] = false;
            orange_restore_fw_write($workRoot, $failedJob);
            orange_restore_fw_audit_append($workRoot, $jobId, [
                'event' => 'pre_restore_backup_failed',
                'result' => 'fail',
                'code' => $code,
            ]);
        } catch (Throwable) {
            // ignore secondary failures
        }
        orange_restore_pre_backup_release_lock($workRoot, $jobId);

        return [
            'ok' => false,
            'idempotent' => false,
            'result' => 'FAIL',
            'job_id' => $jobId,
            'code' => $code,
            'rollback_package_id' => (string) ($partial['rollback_package_id'] ?? ''),
            'verify' => (string) ($partial['verify_result'] ?? 'FAIL'),
            'drv' => (string) ($partial['drv_result'] ?? 'FAIL'),
            'retention_pinned' => (bool) ($partial['retention_pinned'] ?? false),
            'execution_started' => false,
            'record' => orange_restore_pre_backup_public_record($partial),
        ];
    }
}
