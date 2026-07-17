<?php

declare(strict_types=1);

/**
 * Phase 3B.2A — Restore Job Framework (read-safe, no restore execution).
 *
 * Stores lightweight job metadata under {workRoot}/framework/{job_id}/.
 * Never extracts packages, never touches SQL/uploads/schema/production.
 */

require_once __DIR__ . '/restore_paths.php';
require_once __DIR__ . '/restore_lock.php';
require_once __DIR__ . '/restore_audit.php';

const ORANGE_RESTORE_FW_DIRNAME = 'framework';
const ORANGE_RESTORE_FW_JOB_FILE = 'job.json';
const ORANGE_RESTORE_FW_AUDIT_FILE = 'audit.jsonl';
const ORANGE_RESTORE_FW_LOCK_FILE = '.restore_framework.lock';
const ORANGE_RESTORE_FW_VERSION = '3B.2B-framework';

const ORANGE_RESTORE_FW_STATUS_QUEUED = 'queued';
const ORANGE_RESTORE_FW_STATUS_PREPARING = 'preparing';
const ORANGE_RESTORE_FW_STATUS_WAITING_CONFIRMATION = 'waiting_confirmation';
const ORANGE_RESTORE_FW_STATUS_DRY_RUNNING = 'dry_running';
const ORANGE_RESTORE_FW_STATUS_DRY_COMPLETED = 'dry_completed';
const ORANGE_RESTORE_FW_STATUS_DRY_FAILED = 'dry_failed';
const ORANGE_RESTORE_FW_STATUS_CANCELLED = 'cancelled';
const ORANGE_RESTORE_FW_STATUS_FAILED = 'failed';
const ORANGE_RESTORE_FW_STATUS_COMPLETED = 'completed';

const ORANGE_RESTORE_FW_PHASE_QUEUED = 'queued';
const ORANGE_RESTORE_FW_PHASE_PREPARING = 'preparing';
const ORANGE_RESTORE_FW_PHASE_WAITING_CONFIRMATION = 'waiting_confirmation';
const ORANGE_RESTORE_FW_PHASE_DRY_RUNNING = 'dry_running';
const ORANGE_RESTORE_FW_PHASE_DRY_COMPLETED = 'dry_completed';
const ORANGE_RESTORE_FW_PHASE_DRY_FAILED = 'dry_failed';
const ORANGE_RESTORE_FW_PHASE_CANCELLED = 'cancelled';
const ORANGE_RESTORE_FW_PHASE_FAILED = 'failed';
const ORANGE_RESTORE_FW_PHASE_COMPLETED = 'completed';

/**
 * @return list<string>
 */
function orange_restore_fw_active_statuses(): array
{
    return [
        ORANGE_RESTORE_FW_STATUS_QUEUED,
        ORANGE_RESTORE_FW_STATUS_PREPARING,
        ORANGE_RESTORE_FW_STATUS_WAITING_CONFIRMATION,
        ORANGE_RESTORE_FW_STATUS_DRY_RUNNING,
    ];
}

/**
 * @return list<string>
 */
function orange_restore_fw_cancellable_statuses(): array
{
    return [
        ORANGE_RESTORE_FW_STATUS_QUEUED,
        ORANGE_RESTORE_FW_STATUS_PREPARING,
        ORANGE_RESTORE_FW_STATUS_WAITING_CONFIRMATION,
    ];
}

/**
 * @return list<string>
 */
function orange_restore_fw_allowed_statuses(): array
{
    return [
        ORANGE_RESTORE_FW_STATUS_QUEUED,
        ORANGE_RESTORE_FW_STATUS_PREPARING,
        ORANGE_RESTORE_FW_STATUS_WAITING_CONFIRMATION,
        ORANGE_RESTORE_FW_STATUS_DRY_RUNNING,
        ORANGE_RESTORE_FW_STATUS_DRY_COMPLETED,
        ORANGE_RESTORE_FW_STATUS_DRY_FAILED,
        ORANGE_RESTORE_FW_STATUS_CANCELLED,
        ORANGE_RESTORE_FW_STATUS_FAILED,
        ORANGE_RESTORE_FW_STATUS_COMPLETED,
    ];
}

function orange_restore_fw_root(string $workRoot): string
{
    $root = rtrim($workRoot, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . ORANGE_RESTORE_FW_DIRNAME;
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        throw new RuntimeException('Cannot create restore framework directory.');
    }
    orange_restore_assert_inside_work_root($workRoot, $root);

    return realpath($root) ?: $root;
}

function orange_restore_fw_assert_job_id(string $jobId): void
{
    if ($jobId === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $jobId)) {
        throw new RuntimeException('Invalid restore job id.');
    }
}

function orange_restore_fw_job_directory(string $workRoot, string $jobId): string
{
    orange_restore_fw_assert_job_id($jobId);
    $dir = orange_restore_fw_root($workRoot) . DIRECTORY_SEPARATOR . $jobId;
    orange_restore_assert_inside_work_root($workRoot, $dir);

    return $dir;
}

function orange_restore_fw_job_file_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_FW_JOB_FILE;
}

function orange_restore_fw_audit_file_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_FW_AUDIT_FILE;
}

function orange_restore_fw_lock_path(string $workRoot): string
{
    return orange_restore_fw_root($workRoot) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_FW_LOCK_FILE;
}

/**
 * @param array<string, mixed> $event
 */
function orange_restore_fw_audit_append(string $workRoot, string $jobId, array $event): void
{
    $path = orange_restore_fw_audit_file_path($workRoot, $jobId);
    $jobDir = dirname($path);
    if (!is_dir($jobDir) && !@mkdir($jobDir, 0775, true) && !is_dir($jobDir)) {
        throw new RuntimeException('Cannot create restore framework audit directory.');
    }

    $blocked = ['password', 'passwd', 'token', 'secret', 'credential', 'api_key', 'approval_token'];
    $safe = [];
    foreach ($event as $key => $value) {
        $keyLower = strtolower((string) $key);
        $skip = false;
        foreach ($blocked as $fragment) {
            if (str_contains($keyLower, $fragment)) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }
        $safe[$key] = $value;
    }

    $record = array_merge([
        'recorded_at' => gmdate('c'),
        'job_id' => $jobId,
        'framework_version' => ORANGE_RESTORE_FW_VERSION,
    ], $safe);

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        throw new RuntimeException('Restore framework audit JSON encode failed.');
    }
    if (file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Cannot append restore framework audit record.');
    }
}

/**
 * @return list<array<string, mixed>>
 */
function orange_restore_fw_audit_read(string $workRoot, string $jobId): array
{
    $path = orange_restore_fw_audit_file_path($workRoot, $jobId);
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }
    $out = [];
    foreach ($lines as $line) {
        $decoded = json_decode((string) $line, true);
        if (is_array($decoded)) {
            $out[] = $decoded;
        }
    }

    return $out;
}

/**
 * @return array{ok:bool,message:string}
 */
function orange_restore_fw_acquire_lock(string $workRoot, string $jobId): array
{
    $path = orange_restore_fw_lock_path($workRoot);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'message' => 'Cannot create restore framework lock directory.'];
    }

    $payload = json_encode([
        'job_id' => $jobId,
        'pid' => getmypid(),
        'started_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return ['ok' => false, 'message' => 'Lock payload encode failed.'];
    }

    $handle = @fopen($path, 'xb');
    if ($handle === false) {
        return ['ok' => false, 'message' => 'restore_job_already_active'];
    }
    fwrite($handle, $payload . "\n");
    fclose($handle);

    return ['ok' => true, 'message' => 'Restore framework lock acquired.'];
}

function orange_restore_fw_release_lock(string $workRoot, ?string $expectedJobId = null): void
{
    $path = orange_restore_fw_lock_path($workRoot);
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

/**
 * @return array<string, mixed>
 */
function orange_restore_fw_read(string $workRoot, string $jobId): array
{
    $path = orange_restore_fw_job_file_path($workRoot, $jobId);
    if (!is_file($path)) {
        throw new RuntimeException('Restore job not found.');
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Cannot read restore job.');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid restore job payload.');
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $job
 */
function orange_restore_fw_write(string $workRoot, array $job): void
{
    $jobId = (string) ($job['job_id'] ?? '');
    orange_restore_fw_assert_job_id($jobId);
    $dir = orange_restore_fw_job_directory($workRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create restore framework job directory.');
    }
    $job['updated_at'] = gmdate('c');
    $path = orange_restore_fw_job_file_path($workRoot, $jobId);
    $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Cannot encode restore framework job.');
    }
    if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write restore framework job.');
    }
}

/**
 * @return list<string>
 */
function orange_restore_fw_list_ids(string $workRoot): array
{
    $root = orange_restore_fw_root($workRoot);
    $entries = @scandir($root);
    if ($entries === false) {
        return [];
    }
    $ids = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
            continue;
        }
        $jobFile = $root . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . ORANGE_RESTORE_FW_JOB_FILE;
        if (is_file($jobFile)) {
            $ids[] = $entry;
        }
    }
    rsort($ids, SORT_STRING);

    return $ids;
}

/**
 * @return list<array<string, mixed>>
 */
function orange_restore_fw_list_jobs(string $workRoot): array
{
    $rows = [];
    foreach (orange_restore_fw_list_ids($workRoot) as $jobId) {
        try {
            $rows[] = orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId));
        } catch (Throwable) {
            continue;
        }
    }

    usort(
        $rows,
        static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''))
    );

    return $rows;
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_fw_find_active_job(string $workRoot): ?array
{
    foreach (orange_restore_fw_list_ids($workRoot) as $jobId) {
        try {
            $job = orange_restore_fw_read($workRoot, $jobId);
        } catch (Throwable) {
            continue;
        }
        if (in_array((string) ($job['status'] ?? ''), orange_restore_fw_active_statuses(), true)) {
            return $job;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function orange_restore_fw_public_row(array $job): array
{
    $status = (string) ($job['status'] ?? '');

    return [
        'job_id' => (string) ($job['job_id'] ?? ''),
        'package_id' => (string) ($job['package_id'] ?? ''),
        'package_type' => (string) ($job['package_type'] ?? ''),
        'country_code' => ($job['country_code'] ?? null) !== null && (string) $job['country_code'] !== ''
            ? (string) $job['country_code']
            : null,
        'created_by' => (string) ($job['created_by'] ?? ''),
        'created_by_admin_id' => (int) ($job['created_by_admin_id'] ?? 0),
        'created_at' => (string) ($job['created_at'] ?? ''),
        'updated_at' => (string) ($job['updated_at'] ?? ''),
        'status' => $status,
        'phase' => (string) ($job['phase'] ?? ''),
        'progress' => (int) ($job['progress'] ?? 0),
        'message' => (string) ($job['message'] ?? ''),
        'cancellable' => in_array($status, orange_restore_fw_cancellable_statuses(), true),
        'dry_run_available' => in_array($status, [
            ORANGE_RESTORE_FW_STATUS_WAITING_CONFIRMATION,
            ORANGE_RESTORE_FW_STATUS_DRY_COMPLETED,
            ORANGE_RESTORE_FW_STATUS_DRY_FAILED,
        ], true),
        'has_dry_run_report' => in_array($status, [
            ORANGE_RESTORE_FW_STATUS_DRY_COMPLETED,
            ORANGE_RESTORE_FW_STATUS_DRY_FAILED,
        ], true) || !empty($job['dry_run_report_file']),
        'dry_run_overall_result' => (string) ($job['dry_run_overall_result'] ?? ''),
        'framework_version' => (string) ($job['framework_version'] ?? ORANGE_RESTORE_FW_VERSION),
        'execution_enabled' => false,
    ];
}

/**
 * @return array<string, mixed>
 */
function orange_restore_fw_set_progress(string $workRoot, string $jobId, int $progress, string $message): array
{
    $job = orange_restore_fw_read($workRoot, $jobId);
    $job['progress'] = max(0, min(100, $progress));
    $job['message'] = $message;
    orange_restore_fw_write($workRoot, $job);

    return $job;
}

/**
 * Create a framework job and advance it dry-run to waiting_confirmation.
 * Never starts restore execution.
 *
 * @param array{
 *   package_id:string,
 *   package_type:string,
 *   country_code?:string|null,
 *   created_by:string,
 *   created_by_admin_id:int
 * } $input
 * @return array<string, mixed>
 */
function orange_restore_fw_create(string $workRoot, array $input): array
{
    $packageType = (string) ($input['package_type'] ?? '');
    if (!in_array($packageType, ['full_disaster', 'country_recovery'], true)) {
        throw new RuntimeException('Invalid package_type.');
    }
    $packageId = trim((string) ($input['package_id'] ?? ''));
    if ($packageId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $packageId)) {
        throw new RuntimeException('Invalid package_id.');
    }
    $countryCode = strtoupper(trim((string) ($input['country_code'] ?? '')));
    if ($packageType === 'country_recovery') {
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            throw new RuntimeException('Invalid country_code.');
        }
    } else {
        $countryCode = '';
    }

    $active = orange_restore_fw_find_active_job($workRoot);
    if ($active !== null) {
        throw new RuntimeException('restore_job_already_active');
    }

    $jobId = orange_restore_generate_job_id();
    $lock = orange_restore_fw_acquire_lock($workRoot, $jobId);
    if (!$lock['ok']) {
        throw new RuntimeException('restore_job_already_active');
    }

    try {
        $activeAgain = orange_restore_fw_find_active_job($workRoot);
        if ($activeAgain !== null) {
            throw new RuntimeException('restore_job_already_active');
        }

        $now = gmdate('c');
        $job = [
            'job_id' => $jobId,
            'package_id' => $packageId,
            'package_type' => $packageType,
            'country_code' => $countryCode !== '' ? $countryCode : null,
            'created_by' => (string) ($input['created_by'] ?? ''),
            'created_by_admin_id' => (int) ($input['created_by_admin_id'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
            'status' => ORANGE_RESTORE_FW_STATUS_QUEUED,
            'phase' => ORANGE_RESTORE_FW_PHASE_QUEUED,
            'progress' => 0,
            'message' => 'Queued',
            'framework_version' => ORANGE_RESTORE_FW_VERSION,
            'execution_enabled' => false,
            'cancelled_by' => '',
            'cancelled_at' => '',
            'cancellation_reason' => '',
        ];
        orange_restore_fw_write($workRoot, $job);
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'Restore Job Created',
            'result' => 'ok',
            'status' => $job['status'],
            'phase' => $job['phase'],
            'package_id' => $packageId,
            'package_type' => $packageType,
            'country_code' => $countryCode !== '' ? $countryCode : null,
            'operator_username' => $job['created_by'],
        ]);
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'Restore Job Locked',
            'result' => 'ok',
            'status' => $job['status'],
            'phase' => $job['phase'],
            'operator_username' => $job['created_by'],
        ]);

        $job = orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_PREPARING,
            ORANGE_RESTORE_FW_PHASE_PREPARING,
            0,
            'Preparing',
            'Restore Job Preparing'
        );

        $job = orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_WAITING_CONFIRMATION,
            ORANGE_RESTORE_FW_PHASE_WAITING_CONFIRMATION,
            0,
            'Waiting confirmation',
            'Restore Job Waiting Confirmation'
        );

        return $job;
    } catch (Throwable $e) {
        orange_restore_fw_release_lock($workRoot, $jobId);
        throw $e;
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_fw_transition(
    string $workRoot,
    string $jobId,
    string $status,
    string $phase,
    int $progress,
    string $message,
    string $auditEvent
): array {
    if (!in_array($status, orange_restore_fw_allowed_statuses(), true)) {
        throw new RuntimeException('Invalid restore framework status.');
    }
    $job = orange_restore_fw_read($workRoot, $jobId);
    $job['status'] = $status;
    $job['phase'] = $phase;
    $job['progress'] = max(0, min(100, $progress));
    $job['message'] = $message;
    orange_restore_fw_write($workRoot, $job);
    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => $auditEvent,
        'result' => 'ok',
        'status' => $status,
        'phase' => $phase,
        'progress' => $job['progress'],
        'message' => $message,
        'operator_username' => (string) ($job['created_by'] ?? ''),
    ]);

    return $job;
}

/**
 * @return array<string, mixed>
 */
function orange_restore_fw_cancel(
    string $workRoot,
    string $jobId,
    string $cancelledBy,
    string $reason = ''
): array {
    $job = orange_restore_fw_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');
    if (!in_array($status, orange_restore_fw_cancellable_statuses(), true)) {
        throw new RuntimeException('Restore job cannot be cancelled in status: ' . $status);
    }

    $job['status'] = ORANGE_RESTORE_FW_STATUS_CANCELLED;
    $job['phase'] = ORANGE_RESTORE_FW_PHASE_CANCELLED;
    $job['progress'] = 0;
    $job['message'] = 'Cancelled';
    $job['cancelled_by'] = $cancelledBy;
    $job['cancelled_at'] = gmdate('c');
    $job['cancellation_reason'] = $reason;
    orange_restore_fw_write($workRoot, $job);
    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'Restore Job Cancelled',
        'result' => 'ok',
        'status' => $job['status'],
        'phase' => $job['phase'],
        'operator_username' => $cancelledBy,
        'cancellation_reason' => $reason,
    ]);
    orange_restore_fw_release_lock($workRoot, $jobId);

    return $job;
}
