<?php

declare(strict_types=1);

/**
 * Phase 3B.4D — Production Uploads Cutover.
 *
 * Uploads/files rename only. Never: database import, rollback, maintenance release,
 * finalize/complete restore, or application PHP/.env cutover.
 *
 * Model (approved): uploads → uploads_pre_merge ; uploads_next → uploads
 * Checkpoints: C7 uploads rename completed | C8 uploads verification completed
 */

require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_production_maintenance.php';
require_once __DIR__ . '/restore_production_import.php';
require_once __DIR__ . '/restore_merge_maintenance.php';
require_once __DIR__ . '/restore_merge_uploads_cutover.php';
require_once __DIR__ . '/restore_uploads_fs.php';
require_once __DIR__ . '/restore_paths.php';
require_once __DIR__ . '/restore_shadow_files.php';
require_once __DIR__ . '/restore_shadow_smoke.php';
require_once __DIR__ . '/restore_execution_bridge.php';
require_once __DIR__ . '/restore_pre_restore_backup.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_manifest.php';
require_once __DIR__ . '/../backup_admin.php';

const ORANGE_RESTORE_UPLOADS_CUTOVER_VERSION = '3B.4D-v1';
const ORANGE_RESTORE_UPLOADS_CUTOVER_REPORT_FILE = 'uploads_cutover_report.json';
const ORANGE_RESTORE_UPLOADS_CUTOVER_META_FILE = 'uploads_cutover.json';
const ORANGE_RESTORE_UPLOADS_CUTOVER_C7 = 'C7';
const ORANGE_RESTORE_UPLOADS_CUTOVER_C8 = 'C8';

/**
 * @return list<string>
 */
function orange_restore_uploads_cutover_checkpoint_ids(): array
{
    return [ORANGE_RESTORE_UPLOADS_CUTOVER_C7, ORANGE_RESTORE_UPLOADS_CUTOVER_C8];
}

/**
 * @return array<string, string>
 */
function orange_restore_uploads_cutover_checkpoint_names(): array
{
    return [
        ORANGE_RESTORE_UPLOADS_CUTOVER_C7 => 'uploads_rename_completed',
        ORANGE_RESTORE_UPLOADS_CUTOVER_C8 => 'uploads_verification_completed',
    ];
}

function orange_restore_uploads_cutover_report_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_UPLOADS_CUTOVER_REPORT_FILE;
}

function orange_restore_uploads_cutover_meta_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_UPLOADS_CUTOVER_META_FILE;
}

/**
 * @param array<string, mixed> $payload
 */
function orange_restore_uploads_cutover_write_checkpoint(
    string $workRoot,
    string $jobId,
    string $id,
    array $payload = []
): void {
    $names = orange_restore_uploads_cutover_checkpoint_names();
    if (!isset($names[$id])) {
        throw new RuntimeException('invalid_uploads_cutover_checkpoint');
    }
    $record = array_merge([
        'checkpoint_id' => $id,
        'checkpoint_name' => $names[$id],
        'written_at' => gmdate('c'),
        'framework_job_id' => $jobId,
        'record_version' => ORANGE_RESTORE_UPLOADS_CUTOVER_VERSION,
    ], $payload);
    $path = orange_restore_prod_import_checkpoint_path($workRoot, $jobId, $id);
    $tmp = $path . '.tmp';
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write uploads cutover checkpoint ' . $id);
    }
    if (!@rename($tmp, $path)) {
        @unlink($path);
        if (!@rename($tmp, $path)) {
            throw new RuntimeException('Cannot finalize uploads cutover checkpoint ' . $id);
        }
    }
}

function orange_restore_uploads_cutover_load_checkpoint(string $workRoot, string $jobId, string $id): ?array
{
    $path = orange_restore_prod_import_checkpoint_path($workRoot, $jobId, $id);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @return list<array<string, mixed>>
 */
function orange_restore_uploads_cutover_checkpoint_history(string $workRoot, string $jobId): array
{
    $out = [];
    foreach (orange_restore_uploads_cutover_checkpoint_ids() as $id) {
        $cp = orange_restore_uploads_cutover_load_checkpoint($workRoot, $jobId, $id);
        if ($cp !== null) {
            $out[] = [
                'checkpoint_id' => $id,
                'checkpoint_name' => (string) ($cp['checkpoint_name'] ?? ''),
                'written_at' => (string) ($cp['written_at'] ?? ''),
            ];
        }
    }

    return $out;
}

function orange_restore_uploads_cutover_highest_checkpoint(string $workRoot, string $jobId): string
{
    $highest = '';
    foreach (orange_restore_uploads_cutover_checkpoint_ids() as $id) {
        if (orange_restore_uploads_cutover_load_checkpoint($workRoot, $jobId, $id) !== null) {
            $highest = $id;
        }
    }

    return $highest;
}

/**
 * Recursive copy without following symlinks. Destination must not exist.
 */
function orange_restore_uploads_cutover_copy_tree(string $sourceDir, string $destDir): void
{
    if (!is_dir($sourceDir)) {
        throw new RuntimeException('shadow_workspace_missing');
    }
    if (is_dir($destDir) || is_file($destDir) || is_link($destDir)) {
        throw new RuntimeException('uploads_next_already_exists');
    }
    $sourceReal = orange_restore_uploads_fs_require_realpath($sourceDir);
    orange_restore_uploads_fs_assert_not_reparse_point($sourceReal);

    if (!@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
        throw new RuntimeException('uploads_next_create_failed');
    }
    $destReal = orange_restore_uploads_fs_require_realpath($destDir);

    $walk = static function (string $from, string $to) use (&$walk, $sourceReal, $destReal): void {
        orange_restore_uploads_fs_assert_not_reparse_point($from);
        orange_restore_uploads_fs_assert_path_inside_root($from, $sourceReal);
        orange_restore_uploads_fs_assert_path_inside_root($to, $destReal);
        $entries = scandir($from);
        if ($entries === false) {
            throw new RuntimeException('Cannot scan source tree: ' . $from);
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fromPath = $from . DIRECTORY_SEPARATOR . $entry;
            $toPath = $to . DIRECTORY_SEPARATOR . $entry;
            if (is_link($fromPath)) {
                throw new RuntimeException('Symlink blocked in shadow→uploads_next copy: ' . $fromPath);
            }
            orange_restore_uploads_fs_assert_not_reparse_point($fromPath);
            if (is_dir($fromPath)) {
                if (!@mkdir($toPath, 0775, true) && !is_dir($toPath)) {
                    throw new RuntimeException('Cannot create directory during uploads_next materialize.');
                }
                $walk($fromPath, $toPath);
                continue;
            }
            if (!is_file($fromPath)) {
                throw new RuntimeException('Unsupported entry during uploads_next materialize: ' . $fromPath);
            }
            if (!@copy($fromPath, $toPath)) {
                throw new RuntimeException('Cannot copy file into uploads_next: ' . $entry);
            }
        }
    };

    $walk($sourceReal, $destReal);
}

/**
 * @return array{ok:bool,code:string,details:array<string,mixed>}
 */
function orange_restore_uploads_cutover_validate_entry(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    string $projectRoot
): array {
    $details = [
        'production_import_ready' => false,
        'import_verification_passed' => false,
        'maintenance_active' => false,
        'rollback_anchor' => false,
        'execution_contract' => false,
        'fingerprints_unchanged' => false,
        'shadow_files_ready' => false,
        'shadow_smoke_ready' => false,
        'cutover_readiness_ready' => false,
        'execution_started_false' => false,
    ];

    $job = orange_restore_fw_read($workRoot, $jobId);
    if ((string) ($job['package_type'] ?? '') !== 'full_disaster') {
        return ['ok' => false, 'code' => 'country_production_restore_not_enabled', 'details' => $details];
    }
    if (!empty($job['execution_started'])) {
        return ['ok' => false, 'code' => 'execution_already_started', 'details' => $details];
    }
    $details['execution_started_false'] = true;

    $status = (string) ($job['status'] ?? '');
    $allowed = [
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_PENDING,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_RUNNING,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_FAILED,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY,
    ];
    if (!in_array($status, $allowed, true)) {
        return ['ok' => false, 'code' => 'production_import_not_ready', 'details' => $details];
    }
    $details['production_import_ready'] = in_array($status, [
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_PENDING,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_RUNNING,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_FAILED,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY,
    ], true);

    $c6 = orange_restore_prod_import_load_checkpoint($workRoot, $jobId, ORANGE_RESTORE_PROD_IMPORT_C6);
    $c5 = orange_restore_prod_import_load_checkpoint($workRoot, $jobId, ORANGE_RESTORE_PROD_IMPORT_C5);
    $importReport = null;
    $reportPath = orange_restore_prod_import_report_path($workRoot, $jobId);
    if (is_file($reportPath)) {
        $importReport = json_decode((string) file_get_contents($reportPath), true);
    }
    $details['import_verification_passed'] = $c6 !== null
        && $c5 !== null
        && is_array($importReport)
        && strtoupper((string) ($importReport['overall'] ?? '')) === 'PASS'
        && strtoupper((string) (($importReport['verification']['overall'] ?? '') ?: '')) === 'PASS';
    if (!$details['import_verification_passed']) {
        return ['ok' => false, 'code' => 'import_verification_not_passed', 'details' => $details];
    }

    $maint = orange_restore_maint_fw_read($workRoot);
    $details['maintenance_active'] = (string) ($maint['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_ACTIVE
        && (string) ($maint['related_job_id'] ?? '') === $jobId;
    if (!$details['maintenance_active']) {
        return ['ok' => false, 'code' => 'maintenance_not_active', 'details' => $details];
    }

    $base = orange_restore_prod_maint_validate($workRoot, $jobId, $backupRoot, true);
    if (!$base['ok']) {
        return ['ok' => false, 'code' => (string) $base['code'], 'details' => $details + ($base['details'] ?? [])];
    }
    $details['rollback_anchor'] = true;
    $details['execution_contract'] = true;
    $details['fingerprints_unchanged'] = true;

    $shadowFiles = orange_restore_shadow_files_load_meta($workRoot, $jobId);
    $details['shadow_files_ready'] = is_array($shadowFiles) && !empty($shadowFiles['ready']);
    if (!$details['shadow_files_ready']) {
        return ['ok' => false, 'code' => 'shadow_files_not_ready', 'details' => $details];
    }

    $smoke = orange_restore_shadow_smoke_load_report($workRoot, $jobId);
    $sResult = strtoupper((string) (($smoke['overall_result'] ?? '') ?: ''));
    $details['shadow_smoke_ready'] = is_array($smoke) && in_array($sResult, ['READY', 'PASS'], true);
    if (!$details['shadow_smoke_ready']) {
        return ['ok' => false, 'code' => 'shadow_smoke_not_ready', 'details' => $details];
    }

    $cutover = orange_restore_cutover_readiness_load($workRoot, $jobId);
    $details['cutover_readiness_ready'] = is_array($cutover)
        && strtoupper((string) ($cutover['status'] ?? '')) === 'READY'
        && empty($cutover['production_cutover_allowed']);
    if (!$details['cutover_readiness_ready']) {
        return ['ok' => false, 'code' => 'cutover_readiness_not_ready', 'details' => $details];
    }

    return ['ok' => true, 'code' => 'ok', 'details' => $details];
}

/**
 * @return array{ok:bool,code:string,details:array<string,mixed>}
 */
function orange_restore_uploads_cutover_preflight(
    string $projectRoot,
    string $workRoot,
    string $jobId,
    ?int $diskFreeOverride = null
): array {
    $uploadsDir = orange_restore_production_uploads_directory($projectRoot);
    $uploadsNextDir = orange_restore_uploads_next_directory($projectRoot);
    $uploadsPreMergeDir = orange_restore_uploads_pre_merge_directory($projectRoot, $jobId);
    $details = [
        'uploads_exists' => is_dir($uploadsDir),
        'uploads_next_exists' => is_dir($uploadsNextDir),
        'uploads_pre_merge_absent' => !is_dir($uploadsPreMergeDir) && !is_file($uploadsPreMergeDir),
        'permissions_valid' => false,
        'enough_disk' => false,
        'checksum_report_valid' => false,
        'uploads_dir' => $uploadsDir,
        'uploads_next_dir' => $uploadsNextDir,
        'uploads_pre_merge_dir' => $uploadsPreMergeDir,
    ];

    if (!$details['uploads_exists']) {
        return ['ok' => false, 'code' => 'uploads_missing', 'details' => $details];
    }
    if (!$details['uploads_pre_merge_absent']) {
        return ['ok' => false, 'code' => 'uploads_pre_merge_already_exists', 'details' => $details];
    }

    $shadowReport = orange_restore_shadow_files_load_report($workRoot, $jobId);
    $expectedChecksum = (string) (($shadowReport['verification']['tree_checksum_sha256'] ?? '') ?: '');
    $expectedCount = (int) (($shadowReport['verification']['actual_file_count'] ?? 0) ?: 0);
    $details['checksum_report_valid'] = $expectedChecksum !== '' && $expectedCount > 0
        && strtoupper((string) ($shadowReport['overall_result'] ?? '')) === 'PASS';
    if (!$details['checksum_report_valid']) {
        return ['ok' => false, 'code' => 'shadow_checksum_report_invalid', 'details' => $details];
    }

    $parent = dirname($uploadsDir);
    $details['permissions_valid'] = is_writable($parent) && is_readable($uploadsDir) && is_writable($uploadsDir);
    if (!$details['permissions_valid']) {
        return ['ok' => false, 'code' => 'uploads_permissions_invalid', 'details' => $details];
    }

    $shadowWs = orange_restore_shadow_files_workspace_path($workRoot, $jobId);
    $shadowInv = orange_restore_uploads_tree_inventory($shadowWs);
    $needed = (int) ($shadowInv['total_size'] ?? 0) + 1048576;
    $free = $diskFreeOverride !== null ? $diskFreeOverride : (int) (@disk_free_space($parent) ?: 0);
    $details['enough_disk'] = $free >= $needed;
    $details['disk_free_bytes'] = $free;
    $details['disk_needed_bytes'] = $needed;
    $details['shadow_tree_checksum'] = $shadowInv['tree_checksum_sha256'];
    $details['shadow_file_count'] = $shadowInv['file_count'];
    if (!$details['enough_disk']) {
        return ['ok' => false, 'code' => 'insufficient_disk', 'details' => $details];
    }

    if (!hash_equals($expectedChecksum, $shadowInv['tree_checksum_sha256'])) {
        return ['ok' => false, 'code' => 'shadow_tree_checksum_drift', 'details' => $details];
    }

    return ['ok' => true, 'code' => 'ok', 'details' => $details];
}

/**
 * @return array{ok:bool,overall:string,blocking_errors:list<string>,details:array<string,mixed>}
 */
function orange_restore_uploads_cutover_verify_live(
    string $projectRoot,
    string $workRoot,
    string $jobId,
    string $expectedTreeChecksum,
    int $expectedFileCount
): array {
    $errors = [];
    $uploadsDir = orange_restore_production_uploads_directory($projectRoot);
    $uploadsNextDir = orange_restore_uploads_next_directory($projectRoot);
    $uploadsPreMergeDir = orange_restore_uploads_pre_merge_directory($projectRoot, $jobId);
    $details = [
        'directory_tree' => false,
        'critical_files' => false,
        'checksums' => false,
        'permissions' => false,
        'expected_counts' => false,
        'file_count' => 0,
        'tree_checksum_sha256' => '',
        'uploads_pre_merge_retained' => is_dir($uploadsPreMergeDir),
        'uploads_next_absent' => !is_dir($uploadsNextDir) && !is_file($uploadsNextDir),
    ];

    if (!is_dir($uploadsDir)) {
        $errors[] = 'live_uploads_missing';
    }
    if (!$details['uploads_next_absent']) {
        $errors[] = 'uploads_next_still_present';
    }
    if (!$details['uploads_pre_merge_retained']) {
        $errors[] = 'uploads_pre_merge_missing_after_cutover';
    }

    if ($errors === []) {
        try {
            $inv = orange_restore_uploads_tree_inventory($uploadsDir);
            $details['file_count'] = $inv['file_count'];
            $details['tree_checksum_sha256'] = $inv['tree_checksum_sha256'];
            $details['directory_tree'] = $inv['file_count'] > 0;
            $details['critical_files'] = $inv['file_count'] > 0;
            $details['expected_counts'] = $expectedFileCount <= 0 || $inv['file_count'] === $expectedFileCount;
            $details['checksums'] = $expectedTreeChecksum !== ''
                && hash_equals($expectedTreeChecksum, $inv['tree_checksum_sha256']);
            $details['permissions'] = is_readable($uploadsDir) && is_writable($uploadsDir);
            if (!$details['directory_tree']) {
                $errors[] = 'live_tree_empty';
            }
            if (!$details['expected_counts']) {
                $errors[] = 'file_count_mismatch';
            }
            if (!$details['checksums']) {
                $errors[] = 'tree_checksum_mismatch';
            }
            if (!$details['permissions']) {
                $errors[] = 'live_uploads_permissions_invalid';
            }
        } catch (Throwable $e) {
            $errors[] = 'live_inventory_failed';
        }
    }

    $ok = $errors === [];

    return [
        'ok' => $ok,
        'overall' => $ok ? 'PASS' : 'FAIL',
        'blocking_errors' => $errors,
        'details' => $details,
    ];
}

/**
 * @param array<string, mixed> $admin
 * @return array<string, mixed>
 */
function orange_restore_uploads_cutover_request(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    string $projectRoot,
    array $admin
): array {
    $operator = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin')) ?: 'admin';
    $gates = orange_restore_uploads_cutover_validate_entry($workRoot, $jobId, $backupRoot, $projectRoot);
    if (!$gates['ok']) {
        throw new RuntimeException((string) $gates['code']);
    }

    $job = orange_restore_fw_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');
    if ($status === ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY) {
        return [
            'job' => orange_restore_fw_public_row($job),
            'cli_needed' => false,
            'idempotent' => true,
            'execution_started' => false,
            'message' => 'Uploads cutover already ready.',
            'warning' => 'Maintenance remains active. Restore is NOT completed. Rollback was NOT executed.',
        ];
    }
    if (in_array($status, [
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_PENDING,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_RUNNING,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_VERIFYING,
    ], true)) {
        return [
            'job' => orange_restore_fw_public_row($job),
            'cli_needed' => true,
            'idempotent' => true,
            'execution_started' => false,
            'cli_command' => 'php scripts/backup/restore_uploads_cutover.php --job=' . $jobId,
            'message' => 'Uploads cutover already requested. Run CLI worker.',
            'warning' => 'Maintenance remains active. Restore is NOT completed. Rollback was NOT executed.',
        ];
    }

    $meta = [
        'record_version' => ORANGE_RESTORE_UPLOADS_CUTOVER_VERSION,
        'framework_job_id' => $jobId,
        'status' => ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_PENDING,
        'requested_at' => gmdate('c'),
        'requested_by' => $operator,
        'cli_needed' => true,
        'cli_command' => 'php scripts/backup/restore_uploads_cutover.php --job=' . $jobId,
        'execution_started' => false,
        'database_import_performed' => false,
        'rollback_executed' => false,
        'maintenance_released' => false,
        'restore_completed' => false,
        'warning' => 'Maintenance remains active. Restore is NOT completed. Rollback was NOT executed.',
    ];
    orange_backup_write_json(orange_restore_uploads_cutover_meta_path($workRoot, $jobId), $meta);

    $job = orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_PENDING,
        ORANGE_RESTORE_FW_PHASE_UPLOADS_CUTOVER_PENDING,
        5,
        'Uploads Cutover Pending — CLI required',
        'restore_uploads_cutover_pending'
    );
    $job['execution_started'] = false;
    $job['uploads_cutover_file'] = ORANGE_RESTORE_UPLOADS_CUTOVER_META_FILE;
    orange_restore_fw_write($workRoot, $job);

    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_uploads_cutover_requested',
        'result' => 'ok',
        'operator_username' => $operator,
        'execution_started' => false,
        'rollback_executed' => false,
        'maintenance_released' => false,
    ]);

    return [
        'job' => orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId)),
        'meta' => $meta,
        'gates' => $gates['details'],
        'cli_needed' => true,
        'cli_command' => $meta['cli_command'],
        'execution_started' => false,
        'message' => 'Uploads Cutover Pending. Run CLI worker.',
        'warning' => 'Maintenance remains active. Restore is NOT completed. Rollback was NOT executed.',
    ];
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_uploads_cutover_run_cli(array $options): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('cli_only');
    }

    $projectRoot = (string) ($options['project_root'] ?? '');
    $workRoot = (string) ($options['work_root'] ?? '');
    $backupRoot = (string) ($options['backup_root'] ?? '');
    $jobId = trim((string) ($options['job_id'] ?? ''));
    $owner = (string) ($options['owner'] ?? 'cli');
    $diskFreeOverride = array_key_exists('disk_free_bytes_override', $options)
        ? (int) $options['disk_free_bytes_override']
        : null;
    /** @var callable(string,string):void|null $renameOverride */
    $renameOverride = isset($options['rename_override']) && is_callable($options['rename_override'])
        ? $options['rename_override']
        : null;

    if ($projectRoot === '' || $workRoot === '' || $backupRoot === '' || $jobId === '') {
        throw new InvalidArgumentException('project_root, work_root, backup_root, job_id required.');
    }

    $startedAt = microtime(true);
    $gates = orange_restore_uploads_cutover_validate_entry($workRoot, $jobId, $backupRoot, $projectRoot);
    if (!$gates['ok']) {
        throw new RuntimeException((string) $gates['code']);
    }

    orange_restore_prod_maint_ensure_execution_lock($workRoot, $jobId);
    $mergeMaint = orange_restore_merge_maintenance_status($workRoot);
    if (!$mergeMaint['active']) {
        orange_restore_merge_maintenance_enable($workRoot, $jobId, [
            'reason' => 'production_uploads_cutover_3b4d',
        ]);
    } else {
        orange_restore_merge_maintenance_verify($workRoot, $jobId);
    }

    $highest = orange_restore_uploads_cutover_highest_checkpoint($workRoot, $jobId);
    if ($highest === ORANGE_RESTORE_UPLOADS_CUTOVER_C8) {
        $job = orange_restore_fw_read($workRoot, $jobId);

        return [
            'ok' => true,
            'job_id' => $jobId,
            'status' => (string) ($job['status'] ?? ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY),
            'resume' => 'already_verified',
            'execution_started' => false,
            'rollback_executed' => false,
            'maintenance_released' => false,
            'restore_completed' => false,
            'database_import_performed' => false,
        ];
    }

    $resumeMode = $highest === ORANGE_RESTORE_UPLOADS_CUTOVER_C7 ? 'verify_only' : 'full';
    $expectedChecksum = '';
    $expectedCount = 0;
    $verify = ['ok' => false, 'overall' => 'FAIL', 'blocking_errors' => [], 'details' => []];

    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_RUNNING,
        ORANGE_RESTORE_FW_PHASE_UPLOADS_CUTOVER_RUNNING,
        20,
        'Uploads cutover running',
        'restore_uploads_cutover_running'
    );
    orange_restore_maint_fw_heartbeat($workRoot);

    try {
        $shadowReport = orange_restore_shadow_files_load_report($workRoot, $jobId);
        $expectedChecksum = (string) (($shadowReport['verification']['tree_checksum_sha256'] ?? '') ?: '');
        $expectedCount = (int) (($shadowReport['verification']['actual_file_count'] ?? 0) ?: 0);
        $uploadsDir = orange_restore_production_uploads_directory($projectRoot);
        $uploadsNextDir = orange_restore_uploads_next_directory($projectRoot);
        $uploadsPreMergeDir = orange_restore_uploads_pre_merge_directory($projectRoot, $jobId);
        $shadowWs = orange_restore_shadow_files_workspace_path($workRoot, $jobId);

        if ($resumeMode !== 'verify_only') {
            // Mid-rename reconcile: first rename done, second pending.
            $uploadsExists = is_dir($uploadsDir);
            $preExists = is_dir($uploadsPreMergeDir);
            $nextExists = is_dir($uploadsNextDir);

            if (!$uploadsExists && $preExists && $nextExists) {
                orange_restore_uploads_fs_assert_atomic_rename_volume([
                    $uploadsPreMergeDir,
                    $uploadsNextDir,
                    dirname($uploadsDir),
                ]);
                orange_restore_merge_uploads_cutover_atomic_rename($uploadsNextDir, $uploadsDir, $renameOverride);
            } else {
                $preflight = orange_restore_uploads_cutover_preflight(
                    $projectRoot,
                    $workRoot,
                    $jobId,
                    $diskFreeOverride
                );
                if (!$preflight['ok']) {
                    throw new RuntimeException((string) $preflight['code']);
                }
                $expectedChecksum = (string) ($preflight['details']['shadow_tree_checksum'] ?? $expectedChecksum);
                $expectedCount = (int) ($preflight['details']['shadow_file_count'] ?? $expectedCount);

                if (is_dir($uploadsNextDir)) {
                    $nextInv = orange_restore_uploads_tree_inventory($uploadsNextDir);
                    if (!hash_equals($expectedChecksum, $nextInv['tree_checksum_sha256'])) {
                        throw new RuntimeException('uploads_next_checksum_mismatch_before_rename');
                    }
                } else {
                    orange_restore_uploads_cutover_copy_tree($shadowWs, $uploadsNextDir);
                    $nextInv = orange_restore_uploads_tree_inventory($uploadsNextDir);
                    if (!hash_equals($expectedChecksum, $nextInv['tree_checksum_sha256'])) {
                        throw new RuntimeException('uploads_next_materialize_checksum_mismatch');
                    }
                }

                if (!is_dir($uploadsDir)) {
                    throw new RuntimeException('uploads_missing');
                }
                if (is_dir($uploadsPreMergeDir) || is_file($uploadsPreMergeDir)) {
                    throw new RuntimeException('uploads_pre_merge_already_exists');
                }

                orange_restore_uploads_fs_assert_atomic_rename_volume([
                    $uploadsDir,
                    $uploadsNextDir,
                    dirname($uploadsDir),
                    dirname($uploadsNextDir),
                ]);
                orange_restore_merge_uploads_cutover_create_snapshot($workRoot, $jobId, $uploadsDir);

                // Rename only — never overwrite / copy over production.
                orange_restore_merge_uploads_cutover_atomic_rename($uploadsDir, $uploadsPreMergeDir, $renameOverride);
                orange_restore_merge_uploads_cutover_atomic_rename($uploadsNextDir, $uploadsDir, $renameOverride);
            }

            orange_restore_uploads_cutover_write_checkpoint($workRoot, $jobId, ORANGE_RESTORE_UPLOADS_CUTOVER_C7, [
                'uploads_dir' => $uploadsDir,
                'uploads_pre_merge_dir' => $uploadsPreMergeDir,
                'expected_tree_checksum' => $expectedChecksum,
                'expected_file_count' => $expectedCount,
                'renamed_at' => gmdate('c'),
            ]);
        } else {
            $c7 = orange_restore_uploads_cutover_load_checkpoint($workRoot, $jobId, ORANGE_RESTORE_UPLOADS_CUTOVER_C7);
            $expectedChecksum = (string) ($c7['expected_tree_checksum'] ?? $expectedChecksum);
            $expectedCount = (int) ($c7['expected_file_count'] ?? $expectedCount);
        }

        orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_VERIFYING,
            ORANGE_RESTORE_FW_PHASE_UPLOADS_CUTOVER_VERIFYING,
            80,
            'Uploads cutover verifying',
            'restore_uploads_cutover_verifying'
        );
        orange_restore_maint_fw_heartbeat($workRoot);

        $verify = orange_restore_uploads_cutover_verify_live(
            $projectRoot,
            $workRoot,
            $jobId,
            $expectedChecksum,
            $expectedCount
        );
        if (!$verify['ok']) {
            throw new RuntimeException('uploads_post_cutover_verification_failed:' . implode(',', $verify['blocking_errors']));
        }

        orange_restore_uploads_cutover_write_checkpoint($workRoot, $jobId, ORANGE_RESTORE_UPLOADS_CUTOVER_C8, [
            'verification' => $verify,
            'verified_at' => gmdate('c'),
        ]);

        $duration = (int) round(microtime(true) - $startedAt);
        $report = [
            'report_version' => ORANGE_RESTORE_UPLOADS_CUTOVER_VERSION,
            'job_id' => $jobId,
            'overall' => 'PASS',
            'duration_seconds' => $duration,
            'verification' => $verify,
            'checkpoint_history' => array_merge(
                orange_restore_prod_import_checkpoint_history($workRoot, $jobId),
                orange_restore_uploads_cutover_checkpoint_history($workRoot, $jobId)
            ),
            'blocking_errors' => [],
            'resume_mode' => $resumeMode,
            'execution_started' => false,
            'database_import_performed' => false,
            'rollback_executed' => false,
            'maintenance_released' => false,
            'restore_completed' => false,
            'production_cutover_allowed' => false,
            'warning' => 'Maintenance remains active. Restore is NOT completed. Rollback was NOT executed.',
            'completed_at' => gmdate('c'),
        ];
        orange_backup_write_json(orange_restore_uploads_cutover_report_path($workRoot, $jobId), $report);

        $meta = [
            'record_version' => ORANGE_RESTORE_UPLOADS_CUTOVER_VERSION,
            'framework_job_id' => $jobId,
            'status' => ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY,
            'completed_at' => gmdate('c'),
            'completed_by' => $owner,
            'execution_started' => false,
            'cli_needed' => false,
            'rollback_executed' => false,
            'maintenance_released' => false,
            'restore_completed' => false,
            'warning' => 'Maintenance remains active. Restore is NOT completed. Rollback was NOT executed.',
        ];
        orange_backup_write_json(orange_restore_uploads_cutover_meta_path($workRoot, $jobId), $meta);

        $job = orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY,
            ORANGE_RESTORE_FW_PHASE_UPLOADS_CUTOVER_READY,
            100,
            'Uploads Cutover Ready — restore NOT completed',
            'restore_uploads_cutover_ready'
        );
        $job['execution_started'] = false;
        $job['uploads_cutover_ready'] = true;
        orange_restore_fw_write($workRoot, $job);

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_uploads_cutover_ready',
            'result' => 'ok',
            'operator_username' => $owner,
            'execution_started' => false,
            'rollback_executed' => false,
            'maintenance_released' => false,
            'restore_completed' => false,
            'duration_seconds' => $duration,
        ]);
        orange_restore_maint_fw_heartbeat($workRoot);

        return [
            'ok' => true,
            'job_id' => $jobId,
            'status' => ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY,
            'report' => $report,
            'resume_mode' => $resumeMode,
            'execution_started' => false,
            'database_import_performed' => false,
            'rollback_executed' => false,
            'maintenance_released' => false,
            'restore_completed' => false,
            'warning' => 'Maintenance remains active. Restore is NOT completed. Rollback was NOT executed.',
        ];
    } catch (Throwable $e) {
        $code = trim($e->getMessage()) ?: 'uploads_cutover_failed';
        $report = [
            'report_version' => ORANGE_RESTORE_UPLOADS_CUTOVER_VERSION,
            'job_id' => $jobId,
            'overall' => 'FAIL',
            'duration_seconds' => (int) round(microtime(true) - $startedAt),
            'verification' => $verify,
            'checkpoint_history' => array_merge(
                orange_restore_prod_import_checkpoint_history($workRoot, $jobId),
                orange_restore_uploads_cutover_checkpoint_history($workRoot, $jobId)
            ),
            'blocking_errors' => [$code],
            'resume_mode' => $resumeMode,
            'execution_started' => false,
            'rollback_executed' => false,
            'maintenance_released' => false,
            'restore_completed' => false,
            'warning' => 'Maintenance remains active. Restore is NOT completed. Rollback was NOT executed.',
            'failed_at' => gmdate('c'),
        ];
        try {
            orange_backup_write_json(orange_restore_uploads_cutover_report_path($workRoot, $jobId), $report);
        } catch (Throwable) {
            // ignore
        }
        try {
            orange_restore_fw_transition(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_FAILED,
                ORANGE_RESTORE_FW_PHASE_UPLOADS_CUTOVER_FAILED,
                0,
                'Uploads cutover failed: ' . substr($code, 0, 120),
                'restore_uploads_cutover_failed'
            );
            $job = orange_restore_fw_read($workRoot, $jobId);
            $job['execution_started'] = false;
            orange_restore_fw_write($workRoot, $job);
        } catch (Throwable) {
            // ignore
        }
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_uploads_cutover_failed',
            'result' => 'fail',
            'code' => $code,
            'operator_username' => $owner,
            'execution_started' => false,
            'rollback_executed' => false,
            'maintenance_released' => false,
        ]);

        return [
            'ok' => false,
            'job_id' => $jobId,
            'status' => ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_FAILED,
            'code' => $code,
            'report' => $report,
            'execution_started' => false,
            'rollback_executed' => false,
            'maintenance_released' => false,
            'restore_completed' => false,
            'warning' => 'Maintenance remains active. Restore is NOT completed. Rollback was NOT executed.',
        ];
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_uploads_cutover_status(string $workRoot, string $jobId): array
{
    $job = orange_restore_fw_read($workRoot, $jobId);
    $metaPath = orange_restore_uploads_cutover_meta_path($workRoot, $jobId);
    $reportPath = orange_restore_uploads_cutover_report_path($workRoot, $jobId);
    $meta = is_file($metaPath) ? json_decode((string) file_get_contents($metaPath), true) : null;
    $report = is_file($reportPath) ? json_decode((string) file_get_contents($reportPath), true) : null;

    $labels = [
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_PENDING => 'Uploads Cutover Pending',
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_RUNNING => 'Running',
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_VERIFYING => 'Verifying',
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY => 'Ready',
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_FAILED => 'Failed',
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY => 'Production Import Ready (uploads not requested)',
    ];
    $status = (string) ($job['status'] ?? '');

    return [
        'job' => orange_restore_fw_public_row($job),
        'meta' => is_array($meta) ? $meta : null,
        'report' => is_array($report) ? $report : null,
        'checkpoint_history' => orange_restore_uploads_cutover_checkpoint_history($workRoot, $jobId),
        'highest_checkpoint' => orange_restore_uploads_cutover_highest_checkpoint($workRoot, $jobId),
        'status_label' => $labels[$status] ?? $status,
        'execution_started' => false,
        'database_import_performed' => false,
        'rollback_executed' => false,
        'maintenance_released' => false,
        'restore_completed' => false,
        'production_cutover_allowed' => false,
        'read_only' => true,
        'warning' => 'Maintenance remains active. Restore is NOT completed. Rollback was NOT executed.',
    ];
}
