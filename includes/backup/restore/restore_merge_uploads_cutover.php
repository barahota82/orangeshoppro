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
    ?string $uploadsInventoryDir = null
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
    if ($uploadsInventoryDir === null) {
        $uploadsInventoryDir = $uploadsDir;
    }
    if (!is_dir($uploadsInventoryDir)) {
        throw new RuntimeException('Uploads inventory directory missing: ' . $uploadsInventoryDir);
    }

    $uploadsNextDir = orange_restore_uploads_next_directory($projectRoot);
    if (!is_dir($uploadsNextDir)) {
        throw new RuntimeException('uploads_next directory missing: ' . $uploadsNextDir);
    }

    orange_restore_uploads_fs_assert_atomic_rename_volume([
        $uploadsInventoryDir,
        $uploadsNextDir,
        dirname($uploadsDir),
        dirname($uploadsNextDir),
    ]);

    $stagingInventory = orange_restore_uploads_tree_inventory($stagingUploadsDir);
    $uploadsNextInventory = orange_restore_uploads_tree_inventory($uploadsNextDir);
    $uploadsInventory = orange_restore_uploads_tree_inventory($uploadsInventoryDir);

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

    $gate = orange_restore_merge_uploads_cutover_collect_trust_gates($projectRoot, $workRoot, $backupRoot, $job);

    $uploadsPreMergeDir = (string) $gate['uploads_pre_merge_dir'];
    if (is_dir($uploadsPreMergeDir) || is_file($uploadsPreMergeDir)) {
        throw new RuntimeException('uploads_pre_merge target already exists: ' . $uploadsPreMergeDir);
    }

    return $gate;
}

/**
 * @return array<string, mixed>
 */
function orange_restore_merge_uploads_cutover_verify_resume_preconditions(
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
    if ($status !== ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE) {
        throw new RuntimeException('Restore job is not uploads_first_rename_complete (status=' . $status . ').');
    }

    if (($job['job_type'] ?? '') !== ORANGE_RESTORE_JOB_TYPE_FULL) {
        throw new RuntimeException('Uploads cutover applies to full_disaster jobs only.');
    }
    if (($job['uploads_cutover_first_rename_complete'] ?? false) !== true) {
        throw new RuntimeException('Cannot resume uploads cutover without first-rename checkpoint flag.');
    }

    orange_restore_lock_assert_held_by_job($workRoot, $jobId);
    orange_restore_merge_maintenance_verify($workRoot, $jobId);

    $uploadsDir = orange_restore_production_uploads_directory($projectRoot);
    if (is_dir($uploadsDir) || is_file($uploadsDir)) {
        throw new RuntimeException('Cannot resume uploads cutover: live uploads target already exists.');
    }

    $uploadsPreMergeDir = trim((string) ($job['uploads_pre_merge_path'] ?? ''));
    if ($uploadsPreMergeDir === '') {
        $uploadsPreMergeDir = orange_restore_uploads_pre_merge_directory($projectRoot, $jobId);
    }
    if (!is_dir($uploadsPreMergeDir)) {
        throw new RuntimeException('Cannot resume uploads cutover: uploads_pre_merge directory missing.');
    }

    $gate = orange_restore_merge_uploads_cutover_collect_trust_gates(
        $projectRoot,
        $workRoot,
        $backupRoot,
        $job,
        $uploadsPreMergeDir
    );

    $snapshotManifestPath = orange_restore_pre_merge_uploads_snapshot_manifest_path($workRoot, $jobId);
    if (!is_file($snapshotManifestPath)) {
        throw new RuntimeException('Cannot resume uploads cutover: pre-merge snapshot manifest missing.');
    }
    $snapshotManifest = json_decode((string) file_get_contents($snapshotManifestPath), true);
    if (!is_array($snapshotManifest)) {
        throw new RuntimeException('Cannot resume uploads cutover: pre-merge snapshot manifest is invalid JSON.');
    }
    orange_restore_merge_uploads_cutover_verify_snapshot($uploadsPreMergeDir, $snapshotManifest);

    $gate['uploads_pre_merge_dir'] = $uploadsPreMergeDir;
    $gate['snapshot'] = $snapshotManifest;
    $gate['resume_after_first_rename'] = true;

    return $gate;
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

    $live = orange_restore_merge_uploads_cutover_collect_trust_gates($projectRoot, $workRoot, $backupRoot, $job);

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
 * Phase 2D.3 — production uploads cutover only (no DB writes, no rollback, no post-validation).
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

    if ((string) ($job['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE) {
        $verify = orange_restore_merge_uploads_cutover_verify_resume_preconditions(
            $projectRoot,
            $workRoot,
            $backupRoot,
            $job
        );

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_second_rename_started', 'started', [
            'operator_admin_id' => $adminId,
            'operator_username' => $operatorUsername,
            'uploads_path' => $verify['uploads_dir'],
            'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
            'uploads_next_path' => $verify['uploads_next_dir'],
            'resumed_after_first_rename' => true,
            'database_writes' => false,
            'production_writes' => true,
        ]));

        try {
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
                    'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
                    'pre_merge_uploads_snapshot_path' => orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId),
                    'duration_seconds' => $durationSeconds,
                    'result' => 'uploads_cutover_complete',
                ]
            );

            orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_cutover_complete', 'pass', [
                'operator_admin_id' => $adminId,
                'operator_username' => $operatorUsername,
                'uploads_path' => $verify['uploads_dir'],
                'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
                'uploads_next_path' => $verify['uploads_next_dir'],
                'file_count' => (int) ($verify['uploads_next_inventory']['file_count'] ?? 0),
                'total_size' => (int) ($verify['uploads_next_inventory']['total_size'] ?? 0),
                'tree_checksum_sha256' => (string) ($verify['uploads_next_inventory']['tree_checksum_sha256'] ?? ''),
                'uploads_next_tree_checksum' => $verify['uploads_next_tree_checksum'],
                'duration_seconds' => $durationSeconds,
                'resumed_after_first_rename' => true,
                'database_writes' => false,
                'production_writes' => true,
                'rollback_executed' => false,
            ]));

            return [
                'ok' => true,
                'job_id' => $jobId,
                'status' => ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE,
                'job' => $job,
                'snapshot' => $verify['snapshot'] ?? [],
                'resumed_after_first_rename' => true,
                'database_writes' => false,
                'production_writes' => true,
                'uploads_touched' => true,
                'rollback_executed' => false,
            ];
        } catch (Throwable $e) {
            $job = orange_restore_job_read($workRoot, $jobId);
            $fsState = orange_restore_merge_uploads_cutover_filesystem_state($projectRoot, $verify);
            orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_second_rename_failed', 'failed', array_merge([
                'operator_admin_id' => $adminId,
                'operator_username' => $operatorUsername,
                'uploads_path' => $verify['uploads_dir'],
                'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
                'uploads_next_path' => $verify['uploads_next_dir'],
                'job_status' => (string) ($job['status'] ?? ''),
                'error' => $e->getMessage(),
                'resumed_after_first_rename' => true,
                'database_writes' => false,
                'production_writes' => true,
                'rollback_executed' => false,
            ], $fsState)));

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
                'Uploads cutover resume failed after first rename (job=' . $jobId . ', status=failed_merge): ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

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

    $snapshotManifest = null;
    try {
        if ($snapshotOverride !== null) {
            $snapshotManifest = $snapshotOverride($workRoot, $jobId, (string) $verify['uploads_dir']);
            if (!is_array($snapshotManifest) || ($snapshotManifest['ok'] ?? true) === false) {
                throw new RuntimeException('Pre-merge uploads snapshot override failed.');
            }
        } else {
            $snapshotManifest = orange_restore_merge_uploads_cutover_create_snapshot(
                $workRoot,
                $jobId,
                (string) $verify['uploads_dir']
            );
        }

        orange_restore_merge_uploads_cutover_verify_snapshot((string) $verify['uploads_dir'], $snapshotManifest);

        $snapshotPath = orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId);
        $job = orange_restore_job_read($workRoot, $jobId);
        $job['pre_merge_uploads_snapshot_path'] = $snapshotPath;
        orange_restore_job_write($workRoot, $job);
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

    orange_restore_merge_uploads_cutover_final_pre_rename_revalidation(
        $projectRoot,
        $workRoot,
        $backupRoot,
        orange_restore_job_read($workRoot, $jobId),
        $verify
    );

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

    try {
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

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_second_rename_started', 'started', [
            'operator_admin_id' => $adminId,
            'operator_username' => $operatorUsername,
            'uploads_path' => $verify['uploads_dir'],
            'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
            'uploads_next_path' => $verify['uploads_next_dir'],
            'database_writes' => false,
            'production_writes' => true,
        ]));

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
                'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
                'pre_merge_uploads_snapshot_path' => orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId),
                'duration_seconds' => $durationSeconds,
                'result' => 'uploads_cutover_complete',
            ]
        );

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_cutover_complete', 'pass', [
            'operator_admin_id' => $adminId,
            'operator_username' => $operatorUsername,
            'uploads_path' => $verify['uploads_dir'],
            'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
            'uploads_next_path' => $verify['uploads_next_dir'],
            'file_count' => (int) ($snapshotManifest['file_count'] ?? 0),
            'total_size' => (int) ($snapshotManifest['total_size'] ?? 0),
            'tree_checksum_sha256' => (string) ($snapshotManifest['tree_checksum_sha256'] ?? ''),
            'uploads_next_tree_checksum' => $verify['uploads_next_tree_checksum'],
            'duration_seconds' => $durationSeconds,
            'database_writes' => false,
            'production_writes' => true,
            'rollback_executed' => false,
        ]));

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
    } catch (Throwable $e) {
        $job = orange_restore_job_read($workRoot, $jobId);
        $fsState = orange_restore_merge_uploads_cutover_filesystem_state($projectRoot, $verify);
        $failureEvent = ($job['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE
            ? 'uploads_second_rename_failed'
            : 'uploads_cutover_failed';

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, $failureEvent, 'failed', array_merge([
            'operator_admin_id' => $adminId,
            'operator_username' => $operatorUsername,
            'uploads_path' => $verify['uploads_dir'],
            'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
            'uploads_next_path' => $verify['uploads_next_dir'],
            'job_status' => (string) ($job['status'] ?? ''),
            'error' => $e->getMessage(),
            'database_writes' => false,
            'production_writes' => ($job['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE,
            'rollback_executed' => false,
        ], $fsState)));

        if (($job['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE) {
            $job = orange_restore_job_mark_failed_merge(
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

        throw $e;
    }
}
