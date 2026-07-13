<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_job.php';
require_once __DIR__ . '/restore_audit.php';
require_once __DIR__ . '/restore_lock.php';
require_once __DIR__ . '/restore_merge_maintenance.php';
require_once __DIR__ . '/restore_merge_precheck.php';
require_once __DIR__ . '/restore_validation_adapter.php';
require_once __DIR__ . '/restore_uploads_fs.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_manifest.php';

/**
 * @return array<string, mixed>
 */
function orange_restore_uploads_next_manifest_read(string $manifestPath): array
{
    if (!is_file($manifestPath)) {
        throw new RuntimeException('uploads_next manifest missing: ' . $manifestPath);
    }
    $decoded = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('uploads_next manifest is invalid JSON.');
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $manifest
 * @param array<string, mixed> $job
 * @param array<string, mixed> $binding
 * @param array{file_count:int,total_size:int,tree_checksum_sha256:string} $liveNextInventory
 * @param array{file_count:int,total_size:int,tree_checksum_sha256:string} $liveStagingInventory
 */
function orange_restore_uploads_next_manifest_validate(
    array $manifest,
    array $job,
    array $binding,
    array $liveNextInventory,
    array $liveStagingInventory,
    string $liveStagingManifestChecksum,
    string $livePackageChecksum
): void {
    $requiredStrings = [
        'job_id',
        'source_package_checksum',
        'staging_restore_manifest_checksum',
        'aggregate_tree_checksum',
        'generated_at',
    ];
    foreach ($requiredStrings as $key) {
        if (trim((string) ($manifest[$key] ?? '')) === '') {
            throw new RuntimeException('uploads_next manifest missing required field: ' . $key);
        }
    }

    if (($manifest['verified'] ?? false) !== true) {
        throw new RuntimeException('uploads_next is not verified (manifest verified flag is false).');
    }

    if (!is_int($manifest['file_count'] ?? null) && !ctype_digit((string) ($manifest['file_count'] ?? ''))) {
        throw new RuntimeException('uploads_next manifest file_count missing or invalid.');
    }
    $manifestFileCount = (int) ($manifest['file_count'] ?? -1);

    $manifestTotalSize = null;
    if (isset($manifest['total_size_bytes'])) {
        $manifestTotalSize = (int) $manifest['total_size_bytes'];
    } elseif (isset($manifest['total_size'])) {
        $manifestTotalSize = (int) $manifest['total_size'];
    }
    if ($manifestTotalSize === null) {
        throw new RuntimeException('uploads_next manifest total_size_bytes missing or invalid.');
    }

    $jobId = (string) ($job['job_id'] ?? '');
    if ((string) ($manifest['job_id'] ?? '') !== $jobId) {
        throw new RuntimeException('uploads_next manifest job_id does not match current job.');
    }

    if (!hash_equals((string) ($manifest['source_package_checksum'] ?? ''), (string) ($job['source_package_checksum'] ?? ''))) {
        throw new RuntimeException('uploads_next manifest source_package_checksum mismatch with job.');
    }
    if (!hash_equals((string) ($manifest['source_package_checksum'] ?? ''), (string) ($binding['source_package_checksum'] ?? ''))) {
        throw new RuntimeException('uploads_next manifest source_package_checksum mismatch with approval binding.');
    }
    if (!hash_equals((string) ($manifest['source_package_checksum'] ?? ''), $livePackageChecksum)) {
        throw new RuntimeException('uploads_next manifest source_package_checksum mismatch with live package.');
    }

    if (!hash_equals((string) ($manifest['staging_restore_manifest_checksum'] ?? ''), $liveStagingManifestChecksum)) {
        throw new RuntimeException('uploads_next manifest staging_restore_manifest_checksum mismatch with live staging manifest.');
    }
    if (!hash_equals((string) ($manifest['staging_restore_manifest_checksum'] ?? ''), (string) ($binding['staging_restore_manifest_checksum'] ?? ''))) {
        throw new RuntimeException('uploads_next manifest staging_restore_manifest_checksum mismatch with approval binding.');
    }

    if ($manifestFileCount !== $liveNextInventory['file_count']) {
        throw new RuntimeException('uploads_next manifest file_count mismatch with live uploads_next inventory.');
    }
    if ($manifestTotalSize !== $liveNextInventory['total_size']) {
        throw new RuntimeException('uploads_next manifest total_size_bytes mismatch with live uploads_next inventory.');
    }
    if (!hash_equals((string) ($manifest['aggregate_tree_checksum'] ?? ''), $liveNextInventory['tree_checksum_sha256'])) {
        throw new RuntimeException('uploads_next manifest aggregate_tree_checksum mismatch with live uploads_next inventory.');
    }
    if (!hash_equals($liveStagingInventory['tree_checksum_sha256'], $liveNextInventory['tree_checksum_sha256'])) {
        throw new RuntimeException('uploads_next tree checksum does not match staging uploads tree checksum.');
    }
}

/**
 * @return array{path:string,checksum:string,manifest:array<string,mixed>}
 */
function orange_restore_merge_uploads_cutover_assert_staging_manifest_binding(
    string $workRoot,
    array $job
): array {
    /** @var array<string, mixed> $binding */
    $binding = is_array($job['approval_token_binding'] ?? null) ? $job['approval_token_binding'] : [];
    if ($binding === []) {
        throw new RuntimeException('Approval token binding is missing on job.');
    }
    orange_restore_merge_precheck_assert_binding_checksums($job, $binding);

    $stagingManifestPath = trim((string) ($job['staging_restore_manifest_path'] ?? ''));
    if ($stagingManifestPath === '' || !is_file($stagingManifestPath)) {
        $stagingManifestPath = orange_restore_job_staging_manifest_path($workRoot, (string) ($job['job_id'] ?? ''));
    }
    if (!is_file($stagingManifestPath)) {
        throw new RuntimeException('Staging restore manifest missing.');
    }

    $liveManifestChecksum = orange_restore_validation_adapter_file_checksum($stagingManifestPath);
    if (!hash_equals((string) ($binding['staging_restore_manifest_checksum'] ?? ''), $liveManifestChecksum)) {
        throw new RuntimeException('Staging manifest checksum mismatch (binding vs live manifest).');
    }

    $stagingManifest = json_decode((string) file_get_contents($stagingManifestPath), true);
    if (!is_array($stagingManifest)) {
        throw new RuntimeException('Staging restore manifest is invalid JSON.');
    }

    return [
        'path' => $stagingManifestPath,
        'checksum' => $liveManifestChecksum,
        'manifest' => $stagingManifest,
    ];
}

/**
 * @return array<string, mixed>
 */
function orange_restore_merge_uploads_cutover_collect_trust_gates(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    array $job,
    string $phase = 'before_first_rename'
): array {
    $jobId = (string) ($job['job_id'] ?? '');

    $rollbackPath = trim((string) ($job['fresh_backup_path'] ?? ''));
    $rollbackChecksum = trim((string) ($job['fresh_backup_checksum'] ?? ''));
    if ($rollbackPath === '' || $rollbackChecksum === '') {
        throw new RuntimeException('Rollback anchor (fresh backup) is missing on job.');
    }
    if (!(bool) ($job['rollback_anchor_job_only'] ?? false)) {
        throw new RuntimeException('Rollback anchor must be job-only (rollback_anchor_job_only=true).');
    }

    $liveAnchorChecksum = orange_restore_merge_precheck_live_rollback_checksum($rollbackPath);
    if (!hash_equals($rollbackChecksum, $liveAnchorChecksum)) {
        throw new RuntimeException('Rollback anchor checksum mismatch (job vs live anchor package).');
    }

    /** @var array<string, mixed> $binding */
    $binding = is_array($job['approval_token_binding'] ?? null) ? $job['approval_token_binding'] : [];
    if ($binding === []) {
        throw new RuntimeException('Approval token binding is missing on job.');
    }
    orange_restore_merge_precheck_assert_binding_checksums($job, $binding);

    $expectedPackageChecksum = (string) ($job['source_package_checksum'] ?? '');
    $packagePath = orange_restore_resolve_package_path($backupRoot, (string) ($job['source_package_path'] ?? ''));
    $packageManifestPath = $packagePath . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($packageManifestPath)) {
        throw new RuntimeException('Live package manifest.json missing.');
    }
    $packageManifest = json_decode((string) file_get_contents($packageManifestPath), true);
    if (!is_array($packageManifest)) {
        throw new RuntimeException('Invalid live package manifest.json.');
    }
    $livePackageChecksum = orange_restore_validation_adapter_live_package_checksum($packagePath, $packageManifest);
    if (!hash_equals($expectedPackageChecksum, $livePackageChecksum)) {
        throw new RuntimeException('Package checksum mismatch (job vs live package).');
    }
    if (!hash_equals((string) ($binding['source_package_checksum'] ?? ''), $livePackageChecksum)) {
        throw new RuntimeException('Package checksum mismatch (binding vs live package).');
    }

    $stagingGate = orange_restore_merge_uploads_cutover_assert_staging_manifest_binding($workRoot, $job);
    /** @var array<string, mixed> $stagingManifest */
    $stagingManifest = $stagingGate['manifest'];

    $stagingUploadsDir = trim((string) ($stagingManifest['staging_uploads_path'] ?? ''));
    if ($stagingUploadsDir === '') {
        $stagingUploadsDir = trim((string) ($job['staging_uploads_path'] ?? ''));
    }
    if ($stagingUploadsDir === '' || !is_dir($stagingUploadsDir)) {
        throw new RuntimeException('Staging uploads directory missing from staging manifest.');
    }

    $uploadsDir = orange_restore_production_uploads_directory($projectRoot);
    $uploadsNextDir = orange_restore_uploads_next_directory($projectRoot);
    $uploadsPreMergeDir = orange_restore_uploads_pre_merge_directory($projectRoot, $jobId);

    if ($phase === 'before_second_rename') {
        if (is_dir($uploadsDir) || is_file($uploadsDir)) {
            throw new RuntimeException('Production uploads directory still exists before second rename: ' . $uploadsDir);
        }
        if (!is_dir($uploadsPreMergeDir)) {
            throw new RuntimeException('uploads_pre_merge directory missing before second rename: ' . $uploadsPreMergeDir);
        }
        if (!is_dir($uploadsNextDir)) {
            throw new RuntimeException('uploads_next directory missing before second rename: ' . $uploadsNextDir);
        }

        orange_restore_uploads_fs_assert_atomic_rename_volume([
            $uploadsPreMergeDir,
            $uploadsNextDir,
            dirname($uploadsDir),
            dirname($uploadsNextDir),
        ]);

        $stagingInventory = orange_restore_uploads_tree_inventory($stagingUploadsDir);
        $uploadsNextInventory = orange_restore_uploads_tree_inventory($uploadsNextDir);

        $uploadsNextManifestPath = orange_restore_uploads_next_manifest_path($workRoot, $jobId);
        $uploadsNextManifest = orange_restore_uploads_next_manifest_read($uploadsNextManifestPath);
        orange_restore_uploads_next_manifest_validate(
            $uploadsNextManifest,
            $job,
            $binding,
            $uploadsNextInventory,
            $stagingInventory,
            (string) $stagingGate['checksum'],
            $livePackageChecksum
        );

        $manifestUploadsNextPath = (string) ($uploadsNextManifest['uploads_next_path'] ?? '');
        $uploadsNextReal = orange_restore_uploads_fs_require_realpath($uploadsNextDir);
        if ($manifestUploadsNextPath !== '') {
            $manifestReal = realpath($manifestUploadsNextPath) ?: $manifestUploadsNextPath;
            if (strcasecmp(
                rtrim(str_replace('\\', '/', $manifestReal), '/'),
                rtrim(str_replace('\\', '/', $uploadsNextReal), '/')
            ) !== 0) {
                throw new RuntimeException('uploads_next manifest path does not match live uploads_next directory.');
            }
        }

        return [
            'uploads_dir' => $uploadsDir,
            'uploads_next_dir' => $uploadsNextDir,
            'uploads_pre_merge_dir' => $uploadsPreMergeDir,
            'staging_uploads_dir' => $stagingUploadsDir,
            'uploads_next_inventory' => $uploadsNextInventory,
            'staging_inventory' => $stagingInventory,
            'live_package_checksum' => $livePackageChecksum,
            'live_staging_manifest_checksum' => (string) $stagingGate['checksum'],
            'live_rollback_checksum' => $liveAnchorChecksum,
            'uploads_next_tree_checksum' => $uploadsNextInventory['tree_checksum_sha256'],
            'uploads_tree_checksum' => '',
        ];
    }

    if ($phase === 'verify_live_uploads') {
        if (!is_dir($uploadsDir) && !is_file($uploadsDir)) {
            throw new RuntimeException('Production uploads directory missing during live uploads verification: ' . $uploadsDir);
        }
        if (is_dir($uploadsNextDir) || is_file($uploadsNextDir)) {
            throw new RuntimeException('uploads_next still exists during live uploads verification: ' . $uploadsNextDir);
        }
        if (!is_dir($uploadsPreMergeDir)) {
            throw new RuntimeException('uploads_pre_merge directory missing during live uploads verification: ' . $uploadsPreMergeDir);
        }

        orange_restore_uploads_fs_assert_atomic_rename_volume([
            $uploadsDir,
            $uploadsPreMergeDir,
            dirname($uploadsDir),
            dirname($uploadsNextDir),
        ]);

        $stagingInventory = orange_restore_uploads_tree_inventory($stagingUploadsDir);
        $liveUploadsInventory = orange_restore_uploads_tree_inventory($uploadsDir);

        $uploadsNextManifestPath = orange_restore_uploads_next_manifest_path($workRoot, $jobId);
        $uploadsNextManifest = orange_restore_uploads_next_manifest_read($uploadsNextManifestPath);
        orange_restore_uploads_next_manifest_validate(
            $uploadsNextManifest,
            $job,
            $binding,
            $liveUploadsInventory,
            $stagingInventory,
            (string) $stagingGate['checksum'],
            $livePackageChecksum
        );

        return [
            'uploads_dir' => $uploadsDir,
            'uploads_next_dir' => $uploadsNextDir,
            'uploads_pre_merge_dir' => $uploadsPreMergeDir,
            'staging_uploads_dir' => $stagingUploadsDir,
            'uploads_inventory' => $liveUploadsInventory,
            'uploads_next_inventory' => $liveUploadsInventory,
            'staging_inventory' => $stagingInventory,
            'live_package_checksum' => $livePackageChecksum,
            'live_staging_manifest_checksum' => (string) $stagingGate['checksum'],
            'live_rollback_checksum' => $liveAnchorChecksum,
            'uploads_next_tree_checksum' => $liveUploadsInventory['tree_checksum_sha256'],
            'uploads_tree_checksum' => $liveUploadsInventory['tree_checksum_sha256'],
        ];
    }

    if (!is_dir($uploadsDir)) {
        throw new RuntimeException('Production uploads directory missing: ' . $uploadsDir);
    }

    if (!is_dir($uploadsNextDir)) {
        throw new RuntimeException('uploads_next directory missing: ' . $uploadsNextDir);
    }

    orange_restore_uploads_fs_assert_atomic_rename_volume([
        $uploadsDir,
        $uploadsNextDir,
        dirname($uploadsDir),
        dirname($uploadsNextDir),
    ]);

    $stagingInventory = orange_restore_uploads_tree_inventory($stagingUploadsDir);
    $uploadsNextInventory = orange_restore_uploads_tree_inventory($uploadsNextDir);
    $uploadsInventory = orange_restore_uploads_tree_inventory($uploadsDir);

    $uploadsNextManifestPath = orange_restore_uploads_next_manifest_path($workRoot, $jobId);
    $uploadsNextManifest = orange_restore_uploads_next_manifest_read($uploadsNextManifestPath);
    orange_restore_uploads_next_manifest_validate(
        $uploadsNextManifest,
        $job,
        $binding,
        $uploadsNextInventory,
        $stagingInventory,
        (string) $stagingGate['checksum'],
        $livePackageChecksum
    );

    $manifestUploadsNextPath = (string) ($uploadsNextManifest['uploads_next_path'] ?? '');
    $uploadsNextReal = orange_restore_uploads_fs_require_realpath($uploadsNextDir);
    if ($manifestUploadsNextPath !== '') {
        $manifestReal = realpath($manifestUploadsNextPath) ?: $manifestUploadsNextPath;
        if (strcasecmp(
            rtrim(str_replace('\\', '/', $manifestReal), '/'),
            rtrim(str_replace('\\', '/', $uploadsNextReal), '/')
        ) !== 0) {
            throw new RuntimeException('uploads_next manifest path does not match live uploads_next directory.');
        }
    }

    return [
        'uploads_dir' => $uploadsDir,
        'uploads_next_dir' => $uploadsNextDir,
        'uploads_pre_merge_dir' => orange_restore_uploads_pre_merge_directory($projectRoot, $jobId),
        'staging_uploads_dir' => $stagingUploadsDir,
        'uploads_inventory' => $uploadsInventory,
        'uploads_next_inventory' => $uploadsNextInventory,
        'staging_inventory' => $stagingInventory,
        'live_package_checksum' => $livePackageChecksum,
        'live_staging_manifest_checksum' => (string) $stagingGate['checksum'],
        'live_rollback_checksum' => $liveAnchorChecksum,
        'uploads_next_tree_checksum' => $uploadsNextInventory['tree_checksum_sha256'],
        'uploads_tree_checksum' => $uploadsInventory['tree_checksum_sha256'],
    ];
}

/**
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function orange_restore_merge_uploads_cutover_verify_preconditions(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    array $job
): array {
    $jobId = (string) ($job['job_id'] ?? '');
    if ($jobId === '') {
        throw new RuntimeException('Restore job missing job_id.');
    }

    $status = (string) ($job['status'] ?? '');
    if ($status !== ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE) {
        throw new RuntimeException('Restore job is not database_cutover_complete (status=' . $status . ').');
    }

    if (($job['job_type'] ?? '') !== ORANGE_RESTORE_JOB_TYPE_FULL) {
        throw new RuntimeException('Uploads cutover applies to full_disaster jobs only.');
    }

    orange_restore_lock_assert_held_by_job($workRoot, $jobId);
    orange_restore_merge_maintenance_verify($workRoot, $jobId);

    $gate = orange_restore_merge_uploads_cutover_collect_trust_gates(
        $projectRoot,
        $workRoot,
        $backupRoot,
        $job,
        'before_first_rename'
    );

    $uploadsPreMergeDir = (string) $gate['uploads_pre_merge_dir'];
    if (is_dir($uploadsPreMergeDir) || is_file($uploadsPreMergeDir)) {
        throw new RuntimeException('uploads_pre_merge target already exists: ' . $uploadsPreMergeDir);
    }

    return $gate;
}

/**
 * Reconcile filesystem vs job when status is uploads_first_rename_pending.
 *
 * @return array{action:string,job:array<string,mixed>,gate:array<string,mixed>}
 */
function orange_restore_merge_uploads_cutover_reconcile_pending_first_rename(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    array $job
): array {
    $jobId = (string) ($job['job_id'] ?? '');
    if ($jobId === '') {
        throw new RuntimeException('Restore job missing job_id.');
    }

    if (($job['status'] ?? '') !== ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_PENDING) {
        throw new RuntimeException('Pending reconciliation requires uploads_first_rename_pending status.');
    }

    if (($job['job_type'] ?? '') !== ORANGE_RESTORE_JOB_TYPE_FULL) {
        throw new RuntimeException('Uploads cutover applies to full_disaster jobs only.');
    }

    orange_restore_lock_assert_held_by_job($workRoot, $jobId);
    orange_restore_merge_maintenance_verify($workRoot, $jobId);

    $uploadsDir = orange_restore_production_uploads_directory($projectRoot);
    $uploadsPreMergeDir = orange_restore_uploads_pre_merge_directory($projectRoot, $jobId);
    $uploadsExists = is_dir($uploadsDir) || is_file($uploadsDir);
    $preMergeExists = is_dir($uploadsPreMergeDir) || is_file($uploadsPreMergeDir);

    if ($uploadsExists && $preMergeExists) {
        throw new RuntimeException(
            'Cannot reconcile uploads_first_rename_pending: both uploads and uploads_pre_merge exist.'
        );
    }
    if (!$uploadsExists && !$preMergeExists) {
        throw new RuntimeException(
            'Cannot reconcile uploads_first_rename_pending: neither uploads nor uploads_pre_merge exists.'
        );
    }

    if (!$uploadsExists && $preMergeExists) {
        $firstRenameAt = trim((string) ($job['uploads_first_rename_completed_at'] ?? ''));
        if ($firstRenameAt === '') {
            $firstRenameAt = gmdate('c');
        }

        $job = orange_restore_job_uploads_cutover_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE,
            [
                'uploads_first_rename_completed_at' => $firstRenameAt,
                'uploads_cutover_first_rename_complete' => true,
                'uploads_cutover_first_rename_pending' => false,
                'uploads_pre_merge_path' => $uploadsPreMergeDir,
                'pre_merge_uploads_snapshot_path' => orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId),
            ]
        );

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_first_rename_reconciled', 'pass', [
            'reconciliation' => 'pending_to_first_rename_complete',
            'uploads_path' => $uploadsDir,
            'uploads_pre_merge_path' => $uploadsPreMergeDir,
            'uploads_exists' => false,
            'uploads_pre_merge_exists' => true,
            'database_writes' => false,
            'production_writes' => false,
        ]));

        $gate = orange_restore_merge_uploads_cutover_collect_trust_gates(
            $projectRoot,
            $workRoot,
            $backupRoot,
            $job,
            'before_second_rename'
        );

        return [
            'action' => 'reconciled_to_first_rename_complete',
            'job' => $job,
            'gate' => $gate,
        ];
    }

    $gate = orange_restore_merge_uploads_cutover_collect_trust_gates(
        $projectRoot,
        $workRoot,
        $backupRoot,
        $job,
        'before_first_rename'
    );

    return [
        'action' => 'proceed_first_rename',
        'job' => $job,
        'gate' => $gate,
    ];
}

/**
 * @param array<string, mixed> $baseline
 */
function orange_restore_merge_uploads_cutover_final_pre_rename_revalidation(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    array $job,
    array $baseline
): void {
    orange_restore_lock_assert_held_by_job($workRoot, (string) ($job['job_id'] ?? ''));
    orange_restore_merge_maintenance_verify($workRoot, (string) ($job['job_id'] ?? ''));

    $live = orange_restore_merge_uploads_cutover_collect_trust_gates(
        $projectRoot,
        $workRoot,
        $backupRoot,
        $job,
        'before_first_rename'
    );

    if ((string) ($baseline['uploads_tree_checksum'] ?? '') !== (string) ($live['uploads_tree_checksum'] ?? '')) {
        throw new RuntimeException('Final pre-rename revalidation detected uploads tree drift.');
    }
    if ((string) ($baseline['uploads_next_tree_checksum'] ?? '') !== (string) ($live['uploads_next_tree_checksum'] ?? '')) {
        throw new RuntimeException('Final pre-rename revalidation detected uploads_next tree drift.');
    }
    if ((int) ($baseline['uploads_inventory']['file_count'] ?? -1) !== (int) ($live['uploads_inventory']['file_count'] ?? -2)) {
        throw new RuntimeException('Final pre-rename revalidation detected uploads file_count drift.');
    }
    if ((int) ($baseline['uploads_inventory']['total_size'] ?? -1) !== (int) ($live['uploads_inventory']['total_size'] ?? -2)) {
        throw new RuntimeException('Final pre-rename revalidation detected uploads total_size drift.');
    }
    if ((string) ($baseline['live_package_checksum'] ?? '') !== (string) ($live['live_package_checksum'] ?? '')) {
        throw new RuntimeException('Final pre-rename revalidation detected live package checksum drift.');
    }
    if ((string) ($baseline['live_staging_manifest_checksum'] ?? '') !== (string) ($live['live_staging_manifest_checksum'] ?? '')) {
        throw new RuntimeException('Final pre-rename revalidation detected staging manifest checksum drift.');
    }
    if ((string) ($baseline['live_rollback_checksum'] ?? '') !== (string) ($live['live_rollback_checksum'] ?? '')) {
        throw new RuntimeException('Final pre-rename revalidation detected rollback anchor checksum drift.');
    }
}

/**
 * @param array<string, mixed> $baseline
 */
function orange_restore_merge_uploads_cutover_final_pre_second_rename_revalidation(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    array $job,
    array $baseline
): void {
    orange_restore_lock_assert_held_by_job($workRoot, (string) ($job['job_id'] ?? ''));
    orange_restore_merge_maintenance_verify($workRoot, (string) ($job['job_id'] ?? ''));

    $live = orange_restore_merge_uploads_cutover_collect_trust_gates(
        $projectRoot,
        $workRoot,
        $backupRoot,
        $job,
        'before_second_rename'
    );

    if ((string) ($baseline['uploads_next_tree_checksum'] ?? '') !== (string) ($live['uploads_next_tree_checksum'] ?? '')) {
        throw new RuntimeException('Final pre-second-rename revalidation detected uploads_next tree drift.');
    }
    if ((int) ($baseline['uploads_next_inventory']['file_count'] ?? -1) !== (int) ($live['uploads_next_inventory']['file_count'] ?? -2)) {
        throw new RuntimeException('Final pre-second-rename revalidation detected uploads_next file_count drift.');
    }
    if ((int) ($baseline['uploads_next_inventory']['total_size'] ?? -1) !== (int) ($live['uploads_next_inventory']['total_size'] ?? -2)) {
        throw new RuntimeException('Final pre-second-rename revalidation detected uploads_next total_size drift.');
    }
    if ((string) ($baseline['live_package_checksum'] ?? '') !== (string) ($live['live_package_checksum'] ?? '')) {
        throw new RuntimeException('Final pre-second-rename revalidation detected live package checksum drift.');
    }
    if ((string) ($baseline['live_staging_manifest_checksum'] ?? '') !== (string) ($live['live_staging_manifest_checksum'] ?? '')) {
        throw new RuntimeException('Final pre-second-rename revalidation detected staging manifest checksum drift.');
    }
    if ((string) ($baseline['live_rollback_checksum'] ?? '') !== (string) ($live['live_rollback_checksum'] ?? '')) {
        throw new RuntimeException('Final pre-second-rename revalidation detected rollback anchor checksum drift.');
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_merge_uploads_cutover_filesystem_state(string $projectRoot, array $verify): array
{
    $uploadsDir = (string) ($verify['uploads_dir'] ?? '');
    $uploadsNextDir = (string) ($verify['uploads_next_dir'] ?? '');
    $uploadsPreMergeDir = (string) ($verify['uploads_pre_merge_dir'] ?? '');

    return [
        'uploads_path' => $uploadsDir,
        'uploads_exists' => is_dir($uploadsDir) || is_file($uploadsDir),
        'uploads_pre_merge_path' => $uploadsPreMergeDir,
        'uploads_pre_merge_exists' => is_dir($uploadsPreMergeDir) || is_file($uploadsPreMergeDir),
        'uploads_next_path' => $uploadsNextDir,
        'uploads_next_exists' => is_dir($uploadsNextDir) || is_file($uploadsNextDir),
        'recorded_at' => gmdate('c'),
    ];
}

/**
 * @return array<string, mixed>
 */
function orange_restore_merge_uploads_cutover_create_snapshot(
    string $workRoot,
    string $jobId,
    string $uploadsDir
): array {
    $snapshotDir = orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId);
    if (is_dir($snapshotDir)) {
        throw new RuntimeException('Pre-merge uploads snapshot directory already exists.');
    }
    if (!@mkdir($snapshotDir, 0775, true) && !is_dir($snapshotDir)) {
        throw new RuntimeException('Cannot create pre-merge uploads snapshot directory.');
    }

    $inventory = orange_restore_uploads_tree_inventory($uploadsDir);
    $checksumsPath = orange_restore_pre_merge_uploads_snapshot_checksums_path($workRoot, $jobId);
    $checksumContent = implode("\n", $inventory['checksum_lines']) . ($inventory['checksum_lines'] !== [] ? "\n" : '');
    if (file_put_contents($checksumsPath, $checksumContent) === false) {
        throw new RuntimeException('Cannot write pre-merge uploads snapshot checksums.');
    }

    $manifest = [
        'generated_at' => gmdate('c'),
        'job_id' => $jobId,
        'path' => $uploadsDir,
        'file_count' => $inventory['file_count'],
        'total_size' => $inventory['total_size'],
        'tree_checksum_sha256' => $inventory['tree_checksum_sha256'],
        'checksums_path' => $checksumsPath,
    ];
    orange_backup_write_json(orange_restore_pre_merge_uploads_snapshot_manifest_path($workRoot, $jobId), $manifest);

    return $manifest;
}

/**
 * @param array<string, mixed> $snapshotManifest
 */
function orange_restore_merge_uploads_cutover_verify_snapshot(
    string $uploadsDir,
    array $snapshotManifest
): void {
    $inventory = orange_restore_uploads_tree_inventory($uploadsDir);
    if ((int) ($snapshotManifest['file_count'] ?? -1) !== $inventory['file_count']) {
        throw new RuntimeException('Pre-merge snapshot file_count mismatch before cutover rename.');
    }
    if ((int) ($snapshotManifest['total_size'] ?? -1) !== $inventory['total_size']) {
        throw new RuntimeException('Pre-merge snapshot total_size mismatch before cutover rename.');
    }
    if (!hash_equals(
        (string) ($snapshotManifest['tree_checksum_sha256'] ?? ''),
        $inventory['tree_checksum_sha256']
    )) {
        throw new RuntimeException('Pre-merge snapshot tree checksum mismatch before cutover rename.');
    }

    $checksumsPath = (string) ($snapshotManifest['checksums_path'] ?? '');
    if ($checksumsPath === '' || !is_file($checksumsPath)) {
        throw new RuntimeException('Pre-merge snapshot checksums file missing.');
    }
    $expectedBody = implode("\n", $inventory['checksum_lines']) . ($inventory['checksum_lines'] !== [] ? "\n" : '');
    $actualBody = (string) file_get_contents($checksumsPath);
    if (!hash_equals($expectedBody, $actualBody)) {
        throw new RuntimeException('Pre-merge snapshot checksums file mismatch before cutover rename.');
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_merge_uploads_cutover_load_existing_snapshot(
    string $workRoot,
    string $jobId,
    string $uploadsDir,
    array $job
): array {
    $snapshotDir = orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId);
    if (!is_dir($snapshotDir)) {
        throw new RuntimeException('Pre-merge uploads snapshot directory missing.');
    }

    $manifestPath = orange_restore_pre_merge_uploads_snapshot_manifest_path($workRoot, $jobId);
    if (!is_file($manifestPath)) {
        throw new RuntimeException('Pre-merge uploads snapshot manifest missing.');
    }

    $decoded = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Pre-merge uploads snapshot manifest is invalid JSON.');
    }

    if ((string) ($decoded['job_id'] ?? '') !== $jobId) {
        throw new RuntimeException('Pre-merge uploads snapshot belongs to another job.');
    }

    $manifestUploadsPath = trim((string) ($decoded['path'] ?? ''));
    if ($manifestUploadsPath === '') {
        throw new RuntimeException('Pre-merge uploads snapshot manifest missing path.');
    }

    $uploadsReal = orange_restore_uploads_fs_require_realpath($uploadsDir);
    $manifestReal = realpath($manifestUploadsPath) ?: $manifestUploadsPath;
    if (strcasecmp(
        rtrim(str_replace('\\', '/', $manifestReal), '/'),
        rtrim(str_replace('\\', '/', $uploadsReal), '/')
    ) !== 0) {
        throw new RuntimeException('Pre-merge uploads snapshot path does not match live uploads directory.');
    }

    $checksumsPath = (string) ($decoded['checksums_path'] ?? '');
    if ($checksumsPath === '' || !is_file($checksumsPath)) {
        throw new RuntimeException('Pre-merge uploads snapshot checksums file missing.');
    }

    orange_restore_merge_uploads_cutover_verify_snapshot($uploadsDir, $decoded);

    return $decoded;
}

/**
 * @return array{manifest:array<string,mixed>,reused:bool}
 */
function orange_restore_merge_uploads_cutover_ensure_snapshot(
    string $workRoot,
    string $jobId,
    string $uploadsDir,
    ?callable $snapshotOverride = null
): array {
    $snapshotDir = orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId);
    if (is_dir($snapshotDir)) {
        return [
            'manifest' => orange_restore_merge_uploads_cutover_load_existing_snapshot($workRoot, $jobId, $uploadsDir, []),
            'reused' => true,
        ];
    }

    if ($snapshotOverride !== null) {
        $manifest = $snapshotOverride($workRoot, $jobId, $uploadsDir);
        if (!is_array($manifest) || ($manifest['ok'] ?? true) === false) {
            throw new RuntimeException('Pre-merge uploads snapshot override failed.');
        }
    } else {
        $manifest = orange_restore_merge_uploads_cutover_create_snapshot($workRoot, $jobId, $uploadsDir);
    }

    orange_restore_merge_uploads_cutover_verify_snapshot($uploadsDir, $manifest);

    return [
        'manifest' => $manifest,
        'reused' => false,
    ];
}

/**
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function orange_restore_merge_uploads_cutover_verify_snapshot_complete_entry(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    array $job
): array {
    $jobId = (string) ($job['job_id'] ?? '');
    if (($job['status'] ?? '') !== ORANGE_RESTORE_JOB_STATUS_UPLOADS_SNAPSHOT_COMPLETE) {
        throw new RuntimeException('Snapshot resume requires uploads_snapshot_complete status.');
    }

    orange_restore_lock_assert_held_by_job($workRoot, $jobId);
    orange_restore_merge_maintenance_verify($workRoot, $jobId);

    $gate = orange_restore_merge_uploads_cutover_collect_trust_gates(
        $projectRoot,
        $workRoot,
        $backupRoot,
        $job,
        'before_first_rename'
    );

    $uploadsPreMergeDir = (string) $gate['uploads_pre_merge_dir'];
    if (is_dir($uploadsPreMergeDir) || is_file($uploadsPreMergeDir)) {
        throw new RuntimeException('uploads_pre_merge target already exists: ' . $uploadsPreMergeDir);
    }

    orange_restore_merge_uploads_cutover_load_existing_snapshot(
        $workRoot,
        $jobId,
        (string) $gate['uploads_dir'],
        $job
    );

    return $gate;
}

/**
 * @return array{
 *   action:string,
 *   job:array<string,mixed>,
 *   gate:array<string,mixed>,
 *   snapshot_manifest:array<string,mixed>|null
 * }
 */
function orange_restore_merge_uploads_cutover_resolve_second_rename_entry(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    array $job
): array {
    $jobId = (string) ($job['job_id'] ?? '');
    $status = (string) ($job['status'] ?? '');
    if (!in_array($status, [
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING,
    ], true)) {
        throw new RuntimeException('Second rename resolution requires uploads_first_rename_complete or uploads_second_rename_pending.');
    }

    orange_restore_lock_assert_held_by_job($workRoot, $jobId);
    orange_restore_merge_maintenance_verify($workRoot, $jobId);

    $uploadsDir = orange_restore_production_uploads_directory($projectRoot);
    $uploadsNextDir = orange_restore_uploads_next_directory($projectRoot);
    $uploadsPreMergeDir = orange_restore_uploads_pre_merge_directory($projectRoot, $jobId);
    $uploadsExists = is_dir($uploadsDir) || is_file($uploadsDir);
    $uploadsNextExists = is_dir($uploadsNextDir) || is_file($uploadsNextDir);
    $preMergeExists = is_dir($uploadsPreMergeDir) || is_file($uploadsPreMergeDir);

    if ($uploadsExists && $uploadsNextExists) {
        throw new RuntimeException('Cannot reconcile second rename: both uploads and uploads_next exist.');
    }
    if (!$uploadsExists && !$uploadsNextExists) {
        throw new RuntimeException('Cannot reconcile second rename: neither uploads nor uploads_next exists.');
    }
    if (!$preMergeExists) {
        throw new RuntimeException('Cannot reconcile second rename: uploads_pre_merge is missing.');
    }

    if ($uploadsExists && !$uploadsNextExists) {
        $gate = orange_restore_merge_uploads_cutover_collect_trust_gates(
            $projectRoot,
            $workRoot,
            $backupRoot,
            $job,
            'verify_live_uploads'
        );

        return [
            'action' => 'reconcile_cutover_complete',
            'job' => $job,
            'gate' => $gate,
            'snapshot_manifest' => null,
        ];
    }

    $gate = orange_restore_merge_uploads_cutover_collect_trust_gates(
        $projectRoot,
        $workRoot,
        $backupRoot,
        $job,
        'before_second_rename'
    );

    if ($status === ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE) {
        return [
            'action' => 'execute_second_rename',
            'job' => $job,
            'gate' => $gate,
            'snapshot_manifest' => null,
            'emit_pending_audit' => true,
        ];
    }

    return [
        'action' => 'execute_second_rename',
        'job' => $job,
        'gate' => $gate,
        'snapshot_manifest' => null,
        'emit_pending_audit' => false,
    ];
}

/**
 * @param array<string, mixed> $gate
 * @return array<string, mixed>
 */
function orange_restore_merge_uploads_cutover_reconcile_cutover_complete(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    string $jobId,
    array $job,
    array $gate,
    int $adminId,
    string $operatorUsername,
    float $startedAt
): array {
    $fsState = orange_restore_merge_uploads_cutover_filesystem_state($projectRoot, $gate);
    $durationSeconds = (int) round(microtime(true) - $startedAt);
    $priorStatus = (string) ($job['status'] ?? '');

    if ($priorStatus === ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING) {
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_second_rename_reconciled', 'pass', array_merge($fsState, [
            'operator_admin_id' => $adminId,
            'operator_username' => $operatorUsername,
            'reconciliation' => 'second_rename_already_applied',
            'uploads_tree_checksum' => $gate['uploads_tree_checksum'],
            'database_writes' => false,
            'production_writes' => false,
        ])));
    }

    $job = orange_restore_job_uploads_cutover_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE,
        [
            'uploads_cutover_completed_at' => gmdate('c'),
            'uploads_cutover_second_rename_pending' => false,
            'uploads_second_rename_pending_at' => '',
            'uploads_pre_merge_path' => $gate['uploads_pre_merge_dir'],
            'pre_merge_uploads_snapshot_path' => orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId),
            'duration_seconds' => $durationSeconds,
            'result' => 'uploads_cutover_complete',
        ]
    );

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_cutover_reconciled_complete', 'pass', array_merge([
        'operator_admin_id' => $adminId,
        'operator_username' => $operatorUsername,
        'reconciliation' => 'live_uploads_matches_expected',
        'uploads_tree_checksum' => $gate['uploads_tree_checksum'],
        'uploads_next_tree_checksum' => $gate['uploads_next_tree_checksum'],
        'duration_seconds' => $durationSeconds,
        'database_writes' => false,
        'production_writes' => true,
        'rollback_executed' => false,
    ], $fsState)));

    return [
        'ok' => true,
        'job_id' => $jobId,
        'status' => ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE,
        'job' => $job,
        'reconciled' => true,
        'database_writes' => false,
        'production_writes' => true,
        'uploads_touched' => true,
        'rollback_executed' => false,
    ];
}

function orange_restore_merge_uploads_cutover_atomic_rename(
    string $from,
    string $to,
    ?callable $renameOverride = null
): void {
    if ($renameOverride !== null) {
        $renameOverride($from, $to);

        return;
    }

    if (!@rename($from, $to)) {
        throw new RuntimeException('Atomic directory rename failed: ' . $from . ' -> ' . $to);
    }
}

/**
 * @param array<string, mixed> $verify
 * @param array<string, mixed>|null $snapshotManifest
 * @return array<string, mixed>
 */
function orange_restore_merge_uploads_cutover_execute_second_rename_phase(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    string $jobId,
    array $job,
    array $verify,
    array $secondRenameBaseline,
    int $adminId,
    string $operatorUsername,
    float $startedAt,
    ?array $snapshotManifest,
    ?callable $renameOverride
): array {
    $job = orange_restore_job_read($workRoot, $jobId);
    if (($job['status'] ?? '') !== ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING) {
        throw new RuntimeException(
            'Second rename requires uploads_second_rename_pending (status=' . (string) ($job['status'] ?? '') . ').'
        );
    }

    orange_restore_merge_uploads_cutover_final_pre_second_rename_revalidation(
        $projectRoot,
        $workRoot,
        $backupRoot,
        $job,
        $secondRenameBaseline
    );

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_second_rename_started', 'started', array_merge(
        orange_restore_merge_uploads_cutover_filesystem_state($projectRoot, $verify),
        [
            'operator_admin_id' => $adminId,
            'operator_username' => $operatorUsername,
            'uploads_path' => $verify['uploads_dir'],
            'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
            'uploads_next_path' => $verify['uploads_next_dir'],
            'uploads_next_tree_checksum' => $verify['uploads_next_tree_checksum'],
            'database_writes' => false,
            'production_writes' => true,
        ]
    )));

    orange_restore_merge_uploads_cutover_atomic_rename(
        (string) $verify['uploads_next_dir'],
        (string) $verify['uploads_dir'],
        $renameOverride
    );

    $durationSeconds = (int) round(microtime(true) - $startedAt);
    $job = orange_restore_job_uploads_cutover_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE,
        [
            'uploads_cutover_completed_at' => gmdate('c'),
            'uploads_cutover_second_rename_pending' => false,
            'uploads_second_rename_pending_at' => '',
            'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
            'pre_merge_uploads_snapshot_path' => orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId),
            'duration_seconds' => $durationSeconds,
            'result' => 'uploads_cutover_complete',
        ]
    );

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_cutover_complete', 'pass', array_merge(
        orange_restore_merge_uploads_cutover_filesystem_state($projectRoot, $verify),
        [
            'operator_admin_id' => $adminId,
            'operator_username' => $operatorUsername,
            'uploads_path' => $verify['uploads_dir'],
            'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
            'uploads_next_path' => $verify['uploads_next_dir'],
            'file_count' => (int) ($snapshotManifest['file_count'] ?? ($verify['uploads_next_inventory']['file_count'] ?? 0)),
            'total_size' => (int) ($snapshotManifest['total_size'] ?? ($verify['uploads_next_inventory']['total_size'] ?? 0)),
            'tree_checksum_sha256' => (string) ($snapshotManifest['tree_checksum_sha256'] ?? ($verify['uploads_next_tree_checksum'] ?? '')),
            'uploads_next_tree_checksum' => $verify['uploads_next_tree_checksum'],
            'duration_seconds' => $durationSeconds,
            'database_writes' => false,
            'production_writes' => true,
            'rollback_executed' => false,
        ]
    )));

    return [
        'ok' => true,
        'job_id' => $jobId,
        'status' => ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE,
        'job' => $job,
        'snapshot' => $snapshotManifest,
        'database_writes' => false,
        'production_writes' => true,
        'uploads_touched' => true,
        'rollback_executed' => false,
    ];
}

/**
 * @param array<string, mixed> $verify
 */
function orange_restore_merge_uploads_cutover_handle_failure(
    string $projectRoot,
    string $workRoot,
    string $jobId,
    array $job,
    array $verify,
    int $adminId,
    string $operatorUsername,
    Throwable $e
): void {
    $job = orange_restore_job_read($workRoot, $jobId);
    $fsState = orange_restore_merge_uploads_cutover_filesystem_state($projectRoot, $verify);
    $currentStatus = (string) ($job['status'] ?? '');

    if ($currentStatus === ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE) {
        $failureEvent = 'uploads_second_rename_failed';
    } elseif ($currentStatus === ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING) {
        $failureEvent = 'uploads_second_rename_failed';
    } elseif ($currentStatus === ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_PENDING) {
        $failureEvent = 'uploads_first_rename_failed';
    } else {
        $failureEvent = 'uploads_cutover_failed';
    }

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, $failureEvent, 'failed', array_merge([
        'operator_admin_id' => $adminId,
        'operator_username' => $operatorUsername,
        'uploads_path' => $verify['uploads_dir'],
        'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
        'uploads_next_path' => $verify['uploads_next_dir'],
        'job_status' => $currentStatus,
        'error' => $e->getMessage(),
        'database_writes' => false,
        'production_writes' => in_array($currentStatus, [
            ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE,
            ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING,
            ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_PENDING,
        ], true),
        'rollback_executed' => false,
    ], $fsState)));

    if (in_array($currentStatus, [
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING,
    ], true)) {
        orange_restore_job_mark_failed_merge(
            $workRoot,
            $jobId,
            'uploads_cutover',
            $e->getMessage(),
            [
                'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
                'pre_merge_uploads_snapshot_path' => orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId),
            ]
        );

        throw new RuntimeException(
            'Uploads cutover failed after first rename (job=' . $jobId . ', status=failed_merge): ' . $e->getMessage(),
            0,
            $e
        );
    }

    if ($currentStatus === ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_PENDING) {
        $uploadsDir = (string) $verify['uploads_dir'];
        $uploadsExists = is_dir($uploadsDir) || is_file($uploadsDir);
        if ($uploadsExists) {
            orange_restore_job_uploads_cutover_transition(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_JOB_STATUS_UPLOADS_SNAPSHOT_COMPLETE,
                [
                    'uploads_cutover_first_rename_pending' => false,
                    'uploads_first_rename_pending_at' => '',
                ]
            );
        }
    }

    throw $e;
}

/**
 * @return array<string, mixed>
 */
function orange_restore_merge_uploads_cutover_transition_second_rename_pending(
    string $workRoot,
    string $jobId,
    array $job,
    array $verify,
    int $adminId,
    string $operatorUsername,
    string $projectRoot,
    bool $emitPendingAudit
): array {
    $job = orange_restore_job_read($workRoot, $jobId);
    if (($job['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING) {
        return $job;
    }

    $pendingAt = gmdate('c');
    $job = orange_restore_job_uploads_cutover_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING,
        [
            'uploads_second_rename_pending_at' => $pendingAt,
            'uploads_cutover_second_rename_pending' => true,
            'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
            'pre_merge_uploads_snapshot_path' => orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId),
        ]
    );

    if ($emitPendingAudit) {
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_second_rename_pending', 'pass', array_merge(
            orange_restore_merge_uploads_cutover_filesystem_state($projectRoot, $verify),
            [
                'operator_admin_id' => $adminId,
                'operator_username' => $operatorUsername,
                'uploads_second_rename_pending_at' => $pendingAt,
                'uploads_next_tree_checksum' => $verify['uploads_next_tree_checksum'],
                'database_writes' => false,
                'production_writes' => false,
            ]
        )));
    }

    return $job;
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_merge_uploads_cutover_run_second_rename_from_gate(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    string $jobId,
    array $job,
    array $verify,
    int $adminId,
    string $operatorUsername,
    float $startedAt,
    ?array $snapshotManifest,
    ?callable $renameOverride,
    bool $emitPendingAudit
): array {
    $job = orange_restore_merge_uploads_cutover_transition_second_rename_pending(
        $workRoot,
        $jobId,
        $job,
        $verify,
        $adminId,
        $operatorUsername,
        $projectRoot,
        $emitPendingAudit
    );

    return orange_restore_merge_uploads_cutover_execute_second_rename_phase(
        $projectRoot,
        $workRoot,
        $backupRoot,
        $jobId,
        $job,
        $verify,
        $verify,
        $adminId,
        $operatorUsername,
        $startedAt,
        $snapshotManifest,
        $renameOverride
    );
}

/**
 * Phase 2D.3 — production uploads cutover only (no DB writes, no rollback, no post-validation).
 *
 * Legal entry states: database_cutover_complete, uploads_snapshot_complete,
 * uploads_first_rename_pending, uploads_first_rename_complete, uploads_second_rename_pending.
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_merge_uploads_cutover_run(array $options): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Uploads cutover is CLI-only.');
    }

    $projectRoot = (string) ($options['project_root'] ?? '');
    $jobId = trim((string) ($options['job_id'] ?? ''));
    $adminId = (int) ($options['admin_id'] ?? 0);

    if ($projectRoot === '' || $jobId === '' || $adminId <= 0) {
        throw new InvalidArgumentException('project_root, job_id, and admin_id are required.');
    }

    $env = orange_backup_load_env_array($projectRoot);
    if (is_array($options['env_override'] ?? null)) {
        $env = array_merge($env, $options['env_override']);
    }

    $backupRoot = orange_backup_resolve_root($env);
    $workRoot = (string) ($options['work_root'] ?? '');
    if ($workRoot === '') {
        $workRoot = orange_restore_resolve_work_root($env);
    }

    $job = orange_restore_job_read($workRoot, $jobId);
    $startedAt = microtime(true);
    $operatorUsername = (string) ($job['operator_username'] ?? '');

    /** @var callable(string,string,string):array<string,mixed>|null $snapshotOverride */
    $snapshotOverride = isset($options['snapshot_override']) && is_callable($options['snapshot_override'])
        ? $options['snapshot_override']
        : null;
    /** @var callable(string,string):void|null $renameOverride */
    $renameOverride = isset($options['rename_override']) && is_callable($options['rename_override'])
        ? $options['rename_override']
        : null;
    $haltAfterFirstRename = (bool) ($options['halt_after_first_rename'] ?? false);

    $entryStatus = (string) ($job['status'] ?? '');
    $snapshotManifest = null;
    $verify = [];

    if (in_array($entryStatus, [
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING,
    ], true)) {
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_cutover_resume_started', 'started', [
            'operator_admin_id' => $adminId,
            'operator_username' => $operatorUsername,
            'resume_from' => $entryStatus,
            'database_writes' => false,
            'production_writes' => true,
        ]));

        try {
            $resolved = orange_restore_merge_uploads_cutover_resolve_second_rename_entry(
                $projectRoot,
                $workRoot,
                $backupRoot,
                orange_restore_job_read($workRoot, $jobId)
            );
            $job = $resolved['job'];
            $verify = $resolved['gate'];

            if ($resolved['action'] === 'reconcile_cutover_complete') {
                return orange_restore_merge_uploads_cutover_reconcile_cutover_complete(
                    $projectRoot,
                    $workRoot,
                    $backupRoot,
                    $jobId,
                    $job,
                    $verify,
                    $adminId,
                    $operatorUsername,
                    $startedAt
                );
            }

            return orange_restore_merge_uploads_cutover_run_second_rename_from_gate(
                $projectRoot,
                $workRoot,
                $backupRoot,
                $jobId,
                $job,
                $verify,
                $adminId,
                $operatorUsername,
                $startedAt,
                $resolved['snapshot_manifest'],
                $renameOverride,
                (bool) ($resolved['emit_pending_audit'] ?? false)
            );
        } catch (Throwable $e) {
            if ($verify === []) {
                $verify = [
                    'uploads_dir' => orange_restore_production_uploads_directory($projectRoot),
                    'uploads_next_dir' => orange_restore_uploads_next_directory($projectRoot),
                    'uploads_pre_merge_dir' => orange_restore_uploads_pre_merge_directory($projectRoot, $jobId),
                ];
            }
            orange_restore_merge_uploads_cutover_handle_failure(
                $projectRoot,
                $workRoot,
                $jobId,
                $job,
                $verify,
                $adminId,
                $operatorUsername,
                $e
            );
        }
    }

    if ($entryStatus === ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_PENDING) {
        $reconciled = orange_restore_merge_uploads_cutover_reconcile_pending_first_rename(
            $projectRoot,
            $workRoot,
            $backupRoot,
            $job
        );
        $job = $reconciled['job'];
        $verify = $reconciled['gate'];

        if ($reconciled['action'] === 'reconciled_to_first_rename_complete') {
            if ($haltAfterFirstRename) {
                return [
                    'ok' => true,
                    'job_id' => $jobId,
                    'status' => ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE,
                    'job' => $job,
                    'reconciled_from_pending' => true,
                    'halted_after_first_rename' => true,
                    'database_writes' => false,
                    'production_writes' => false,
                    'rollback_executed' => false,
                ];
            }

            try {
                return orange_restore_merge_uploads_cutover_run_second_rename_from_gate(
                    $projectRoot,
                    $workRoot,
                    $backupRoot,
                    $jobId,
                    $job,
                    $verify,
                    $adminId,
                    $operatorUsername,
                    $startedAt,
                    null,
                    $renameOverride,
                    true
                );
            } catch (Throwable $e) {
                orange_restore_merge_uploads_cutover_handle_failure(
                    $projectRoot,
                    $workRoot,
                    $jobId,
                    $job,
                    $verify,
                    $adminId,
                    $operatorUsername,
                    $e
                );
            }
        }

        $snapshotManifest = orange_restore_merge_uploads_cutover_load_existing_snapshot(
            $workRoot,
            $jobId,
            (string) $verify['uploads_dir'],
            $job
        );
    } elseif ($entryStatus === ORANGE_RESTORE_JOB_STATUS_UPLOADS_SNAPSHOT_COMPLETE) {
        $verify = orange_restore_merge_uploads_cutover_verify_snapshot_complete_entry(
            $projectRoot,
            $workRoot,
            $backupRoot,
            $job
        );
        $snapshotManifest = orange_restore_merge_uploads_cutover_load_existing_snapshot(
            $workRoot,
            $jobId,
            (string) $verify['uploads_dir'],
            $job
        );
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_snapshot_reused', 'pass', [
            'operator_admin_id' => $adminId,
            'operator_username' => $operatorUsername,
            'snapshot_path' => orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId),
            'file_count' => (int) ($snapshotManifest['file_count'] ?? 0),
            'total_size' => (int) ($snapshotManifest['total_size'] ?? 0),
            'tree_checksum_sha256' => (string) ($snapshotManifest['tree_checksum_sha256'] ?? ''),
            'database_writes' => false,
            'production_writes' => false,
        ]));
    } elseif ($entryStatus === ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE) {
        $verify = orange_restore_merge_uploads_cutover_verify_preconditions(
            $projectRoot,
            $workRoot,
            $backupRoot,
            $job
        );

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_snapshot_started', 'started', [
            'operator_admin_id' => $adminId,
            'operator_username' => $operatorUsername,
            'uploads_path' => $verify['uploads_dir'],
            'uploads_next_path' => $verify['uploads_next_dir'],
            'database_writes' => false,
            'production_writes' => true,
        ]));

        try {
            $snapshotResult = orange_restore_merge_uploads_cutover_ensure_snapshot(
                $workRoot,
                $jobId,
                (string) $verify['uploads_dir'],
                $snapshotOverride
            );
            $snapshotManifest = $snapshotResult['manifest'];
            if ($snapshotResult['reused']) {
                orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_snapshot_reused', 'pass', [
                    'operator_admin_id' => $adminId,
                    'operator_username' => $operatorUsername,
                    'snapshot_path' => orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId),
                    'file_count' => (int) ($snapshotManifest['file_count'] ?? 0),
                    'total_size' => (int) ($snapshotManifest['total_size'] ?? 0),
                    'tree_checksum_sha256' => (string) ($snapshotManifest['tree_checksum_sha256'] ?? ''),
                    'database_writes' => false,
                    'production_writes' => false,
                ]));
            } else {
                orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_snapshot_completed', 'pass', [
                    'operator_admin_id' => $adminId,
                    'operator_username' => $operatorUsername,
                    'snapshot_path' => (string) ($snapshotManifest['path'] ?? $verify['uploads_dir']),
                    'file_count' => (int) ($snapshotManifest['file_count'] ?? 0),
                    'total_size' => (int) ($snapshotManifest['total_size'] ?? 0),
                    'tree_checksum_sha256' => (string) ($snapshotManifest['tree_checksum_sha256'] ?? ''),
                    'duration_seconds' => (int) round(microtime(true) - $startedAt),
                    'database_writes' => false,
                    'production_writes' => false,
                ]));
            }

            $snapshotAt = gmdate('c');
            $job = orange_restore_job_uploads_cutover_transition(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_JOB_STATUS_UPLOADS_SNAPSHOT_COMPLETE,
                [
                    'uploads_snapshot_completed_at' => $snapshotAt,
                    'uploads_cutover_snapshot_complete' => true,
                    'pre_merge_uploads_snapshot_path' => orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId),
                ]
            );

            orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_snapshot_checkpointed', 'pass', [
                'operator_admin_id' => $adminId,
                'operator_username' => $operatorUsername,
                'snapshot_reused' => $snapshotResult['reused'],
                'uploads_snapshot_completed_at' => $snapshotAt,
                'database_writes' => false,
                'production_writes' => false,
            ]));
        } catch (Throwable $e) {
            orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_cutover_failed', 'failed', [
                'operator_admin_id' => $adminId,
                'operator_username' => $operatorUsername,
                'stage' => 'uploads_snapshot',
                'error' => $e->getMessage(),
                'database_writes' => false,
                'production_writes' => false,
            ]));
            throw $e;
        }
    } else {
        throw new RuntimeException(
            'Uploads cutover illegal entry state (status=' . $entryStatus . '). '
            . 'Allowed: database_cutover_complete, uploads_snapshot_complete, uploads_first_rename_pending, '
            . 'uploads_first_rename_complete, uploads_second_rename_pending.'
        );
    }

    orange_restore_merge_uploads_cutover_final_pre_rename_revalidation(
        $projectRoot,
        $workRoot,
        $backupRoot,
        orange_restore_job_read($workRoot, $jobId),
        $verify
    );

    if (in_array($entryStatus, [
        ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_SNAPSHOT_COMPLETE,
    ], true)) {
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_cutover_started', 'started', [
            'operator_admin_id' => $adminId,
            'operator_username' => $operatorUsername,
            'uploads_path' => $verify['uploads_dir'],
            'uploads_next_path' => $verify['uploads_next_dir'],
            'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
            'uploads_next_tree_checksum' => $verify['uploads_next_tree_checksum'],
            'database_writes' => false,
            'production_writes' => true,
        ]));

        $cutoverStartedAt = gmdate('c');
        $job = orange_restore_job_read($workRoot, $jobId);
        $job['uploads_cutover_started_at'] = $cutoverStartedAt;
        $job['uploads_next_path'] = $verify['uploads_next_dir'];
        $job['uploads_next_manifest_path'] = orange_restore_uploads_next_manifest_path($workRoot, $jobId);
        orange_restore_job_write($workRoot, $job);
    }

    try {
        if (($job['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_UPLOADS_SNAPSHOT_COMPLETE) {
            $pendingAt = gmdate('c');
            $job = orange_restore_job_uploads_cutover_transition(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_PENDING,
                [
                    'uploads_first_rename_pending_at' => $pendingAt,
                    'uploads_cutover_first_rename_pending' => true,
                    'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
                    'pre_merge_uploads_snapshot_path' => orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId),
                ]
            );

            orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_first_rename_pending', 'pass', [
                'operator_admin_id' => $adminId,
                'operator_username' => $operatorUsername,
                'uploads_path' => $verify['uploads_dir'],
                'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
                'uploads_next_path' => $verify['uploads_next_dir'],
                'uploads_first_rename_pending_at' => $pendingAt,
                'database_writes' => false,
                'production_writes' => false,
            ]));
        }

        orange_restore_merge_uploads_cutover_atomic_rename(
            (string) $verify['uploads_dir'],
            (string) $verify['uploads_pre_merge_dir'],
            $renameOverride
        );

        $firstRenameAt = gmdate('c');
        $job = orange_restore_job_uploads_cutover_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE,
            [
                'uploads_first_rename_completed_at' => $firstRenameAt,
                'uploads_cutover_first_rename_complete' => true,
                'uploads_cutover_first_rename_pending' => false,
                'uploads_first_rename_pending_at' => '',
                'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
                'pre_merge_uploads_snapshot_path' => orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId),
            ]
        );

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_first_rename_complete', 'pass', [
            'operator_admin_id' => $adminId,
            'operator_username' => $operatorUsername,
            'first_rename_complete' => true,
            'uploads_path' => $verify['uploads_dir'],
            'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
            'uploads_next_path' => $verify['uploads_next_dir'],
            'first_rename_completed_at' => $firstRenameAt,
            'database_writes' => false,
            'production_writes' => true,
        ]));

        if ($haltAfterFirstRename) {
            return [
                'ok' => true,
                'job_id' => $jobId,
                'status' => ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE,
                'job' => $job,
                'halted_after_first_rename' => true,
                'database_writes' => false,
                'production_writes' => true,
                'rollback_executed' => false,
            ];
        }

        $secondGate = orange_restore_merge_uploads_cutover_collect_trust_gates(
            $projectRoot,
            $workRoot,
            $backupRoot,
            $job,
            'before_second_rename'
        );

        return orange_restore_merge_uploads_cutover_run_second_rename_from_gate(
            $projectRoot,
            $workRoot,
            $backupRoot,
            $jobId,
            $job,
            $secondGate,
            $adminId,
            $operatorUsername,
            $startedAt,
            $snapshotManifest,
            $renameOverride,
            true
        );
    } catch (Throwable $e) {
        orange_restore_merge_uploads_cutover_handle_failure(
            $projectRoot,
            $workRoot,
            $jobId,
            $job,
            $verify,
            $adminId,
            $operatorUsername,
            $e
        );
    }
}
