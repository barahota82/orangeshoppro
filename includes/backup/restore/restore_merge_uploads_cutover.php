<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_job.php';
require_once __DIR__ . '/restore_audit.php';
require_once __DIR__ . '/restore_lock.php';
require_once __DIR__ . '/restore_reauth.php';
require_once __DIR__ . '/restore_approval.php';
require_once __DIR__ . '/restore_merge_maintenance.php';
require_once __DIR__ . '/restore_merge_precheck.php';
require_once __DIR__ . '/restore_validation_adapter.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_manifest.php';

function orange_restore_uploads_assert_not_symlink(string $path, string $label): void
{
    if (is_link($path)) {
        throw new RuntimeException($label . ' must not be a symlink/junction: ' . $path);
    }
}

/**
 * @return array{file_count:int,total_size:int,tree_checksum_sha256:string,checksum_lines:list<string>}
 */
function orange_restore_uploads_tree_inventory(string $rootDir): array
{
    orange_restore_uploads_assert_not_symlink($rootDir, 'Uploads root');
    if (!is_dir($rootDir)) {
        throw new RuntimeException('Uploads directory does not exist: ' . $rootDir);
    }

    $rootReal = realpath($rootDir);
    if ($rootReal === false) {
        throw new RuntimeException('Cannot resolve uploads directory: ' . $rootDir);
    }

    $rootNorm = strtolower(rtrim(str_replace('\\', '/', $rootReal), '/'));
    $fileCount = 0;
    $totalSize = 0;
    $checksumLines = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo) {
            continue;
        }
        if ($fileInfo->isLink()) {
            throw new RuntimeException('Symlink/junction blocked in uploads tree: ' . $fileInfo->getPathname());
        }
        if (!$fileInfo->isFile()) {
            continue;
        }

        $fullPath = $fileInfo->getPathname();
        $fullNorm = strtolower(rtrim(str_replace('\\', '/', $fullPath), '/'));
        if ($fullNorm !== $rootNorm && !str_starts_with($fullNorm, $rootNorm . '/')) {
            throw new RuntimeException('Uploads traversal escaped root: ' . $fullPath);
        }

        $relative = ltrim(str_replace('\\', '/', substr($fullPath, strlen($rootReal))), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            throw new RuntimeException('Invalid uploads relative path: ' . $relative);
        }

        $hash = orange_backup_sha256_file($fullPath);
        $checksumLines[] = $hash . '  ' . $relative;
        $fileCount++;
        $totalSize += (int) $fileInfo->getSize();
    }

    sort($checksumLines, SORT_STRING);
    $manifestBody = implode("\n", $checksumLines) . ($checksumLines !== [] ? "\n" : '');
    $treeChecksum = hash('sha256', $manifestBody);

    return [
        'file_count' => $fileCount,
        'total_size' => $totalSize,
        'tree_checksum_sha256' => $treeChecksum,
        'checksum_lines' => $checksumLines,
    ];
}

function orange_restore_uploads_paths_same_volume(string $pathA, string $pathB): bool
{
    $realA = realpath($pathA);
    $realB = realpath($pathB);
    if ($realA === false || $realB === false) {
        return false;
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        if (strlen($realA) < 2 || strlen($realB) < 2) {
            return false;
        }
        if ($realA[1] !== ':' || $realB[1] !== ':') {
            return false;
        }

        return strcasecmp($realA[0], $realB[0]) === 0;
    }

    $statA = stat($realA);
    $statB = stat($realB);
    if ($statA === false || $statB === false) {
        return false;
    }

    return ($statA['dev'] ?? null) === ($statB['dev'] ?? null);
}

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
 * Assert uploads-cutover operator re-authentication (Super Admin + permission + password + phrase).
 *
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function orange_restore_merge_uploads_cutover_assert_operator_reauth(
    PDO $adminPdo,
    array $job,
    int $adminId,
    string $password,
    string $confirmationPhrase
): array {
    $admin = orange_restore_reauth_load_admin($adminPdo, $adminId);
    orange_restore_reauth_assert_restore_permission($admin, $adminPdo, (string) ($job['job_type'] ?? ''));
    if (!orange_restore_verify_operator_password($adminPdo, $adminId, $password)) {
        throw new RuntimeException('Operator password re-authentication failed.');
    }

    $countryCode = (string) ($job['country_code'] ?? '');
    if (!orange_restore_validate_confirmation_phrase(
        (string) ($job['job_type'] ?? ''),
        $confirmationPhrase,
        $countryCode
    )) {
        throw new RuntimeException('Confirmation phrase mismatch.');
    }

    return $admin;
}

/**
 * @param array<string, mixed> $job
 * @param callable(string,string):bool|null $sameVolumeOverride
 * @return array{
 *   uploads_dir:string,
 *   uploads_next_dir:string,
 *   uploads_pre_merge_dir:string,
 *   uploads_next_manifest:array<string,mixed>,
 *   staging_uploads_dir:string,
 *   staging_uploads_tree_checksum:string,
 *   uploads_next_tree_checksum:string
 * }
 */
function orange_restore_merge_uploads_cutover_verify_preconditions(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    array $job,
    ?callable $sameVolumeOverride = null
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

    orange_restore_orchestrator_assert_approval_window_open($job);
    if (!(bool) ($job['production_merge_approved'] ?? false)) {
        throw new RuntimeException('Job production_merge_approved flag is false.');
    }
    if ((string) ($job['owner_approval_at'] ?? '') === '') {
        throw new RuntimeException('Owner approval timestamp is missing.');
    }
    if ((string) ($job['approval_token_consumed_at'] ?? '') === '') {
        throw new RuntimeException('Approval token has not been consumed.');
    }
    if ((string) ($job['approval_token_hash'] ?? '') === '') {
        throw new RuntimeException('Approval token hash is missing on job.');
    }
    /** @var array<string, mixed> $approvalBinding */
    $approvalBinding = is_array($job['approval_token_binding'] ?? null) ? $job['approval_token_binding'] : [];
    orange_restore_merge_precheck_assert_binding_checksums($job, $approvalBinding);

    orange_restore_lock_assert_held_by_job($workRoot, $jobId);
    orange_restore_merge_maintenance_verify($workRoot, $jobId);

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

    $expectedPackageChecksum = (string) ($job['source_package_checksum'] ?? '');
    if ($expectedPackageChecksum === '') {
        throw new RuntimeException('Job source_package_checksum is missing.');
    }
    $packagePath = orange_restore_resolve_package_path($backupRoot, (string) ($job['source_package_path'] ?? ''));
    $manifestPath = $packagePath . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($manifestPath)) {
        throw new RuntimeException('Live package manifest.json missing.');
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        throw new RuntimeException('Invalid live package manifest.json.');
    }
    $livePackageChecksum = orange_restore_validation_adapter_live_package_checksum($packagePath, $manifest);
    if (!hash_equals($expectedPackageChecksum, $livePackageChecksum)) {
        throw new RuntimeException('Package checksum mismatch (job vs live package).');
    }

    $uploadsDir = orange_restore_production_uploads_directory($projectRoot);
    if (!is_dir($uploadsDir)) {
        throw new RuntimeException('Production uploads directory missing: ' . $uploadsDir);
    }

    $uploadsNextDir = orange_restore_uploads_next_directory($projectRoot);
    if (!is_dir($uploadsNextDir)) {
        throw new RuntimeException('uploads_next directory missing: ' . $uploadsNextDir);
    }

    $manifestPath = orange_restore_uploads_next_manifest_path($workRoot, $jobId);
    $uploadsNextManifest = orange_restore_uploads_next_manifest_read($manifestPath);
    if (($uploadsNextManifest['verified'] ?? false) !== true) {
        throw new RuntimeException('uploads_next is not verified (manifest verified flag is false).');
    }
    if ((string) ($uploadsNextManifest['job_id'] ?? '') !== $jobId) {
        throw new RuntimeException('uploads_next manifest job_id does not match current job.');
    }

    $manifestUploadsNextPath = (string) ($uploadsNextManifest['uploads_next_path'] ?? '');
    $uploadsNextReal = realpath($uploadsNextDir) ?: $uploadsNextDir;
    if ($manifestUploadsNextPath !== '') {
        $manifestReal = realpath($manifestUploadsNextPath) ?: $manifestUploadsNextPath;
        if (strcasecmp(
            rtrim(str_replace('\\', '/', $manifestReal), '/'),
            rtrim(str_replace('\\', '/', $uploadsNextReal), '/')
        ) !== 0) {
            throw new RuntimeException('uploads_next manifest path does not match live uploads_next directory.');
        }
    }

    $stagingManifestPath = trim((string) ($job['staging_restore_manifest_path'] ?? ''));
    if ($stagingManifestPath === '' || !is_file($stagingManifestPath)) {
        $stagingManifestPath = orange_restore_job_staging_manifest_path($workRoot, $jobId);
    }
    if (!is_file($stagingManifestPath)) {
        throw new RuntimeException('Staging restore manifest missing.');
    }
    $liveManifestChecksum = orange_backup_sha256_file($stagingManifestPath);
    if (!hash_equals((string) ($approvalBinding['staging_restore_manifest_checksum'] ?? ''), $liveManifestChecksum)) {
        throw new RuntimeException('Staging manifest checksum mismatch (binding vs live manifest).');
    }
    $stagingManifest = json_decode((string) file_get_contents($stagingManifestPath), true);
    if (!is_array($stagingManifest)) {
        throw new RuntimeException('Staging restore manifest is invalid JSON.');
    }

    $stagingUploadsDir = trim((string) ($stagingManifest['staging_uploads_path'] ?? ''));
    if ($stagingUploadsDir === '') {
        $stagingUploadsDir = trim((string) ($job['staging_uploads_path'] ?? ''));
    }
    if ($stagingUploadsDir === '' || !is_dir($stagingUploadsDir)) {
        throw new RuntimeException('Staging uploads directory missing from staging manifest.');
    }

    $stagingInventory = orange_restore_uploads_tree_inventory($stagingUploadsDir);
    $uploadsNextInventory = orange_restore_uploads_tree_inventory($uploadsNextDir);

    $manifestStagingChecksum = trim((string) ($uploadsNextManifest['staging_uploads_tree_checksum'] ?? ''));
    $manifestUploadsNextChecksum = trim((string) ($uploadsNextManifest['uploads_next_tree_checksum'] ?? ''));
    if ($manifestStagingChecksum === '' || $manifestUploadsNextChecksum === '') {
        throw new RuntimeException('uploads_next manifest missing tree checksum fields.');
    }
    if (!hash_equals($manifestStagingChecksum, $stagingInventory['tree_checksum_sha256'])) {
        throw new RuntimeException('uploads_next manifest staging_uploads_tree_checksum mismatch with live staging uploads.');
    }
    if (!hash_equals($manifestUploadsNextChecksum, $uploadsNextInventory['tree_checksum_sha256'])) {
        throw new RuntimeException('uploads_next manifest uploads_next_tree_checksum mismatch with live uploads_next.');
    }
    if (!hash_equals($stagingInventory['tree_checksum_sha256'], $uploadsNextInventory['tree_checksum_sha256'])) {
        throw new RuntimeException('uploads_next tree checksum does not match staging uploads tree checksum.');
    }

    $sameVolume = $sameVolumeOverride !== null
        ? (bool) $sameVolumeOverride($uploadsDir, $uploadsNextDir)
        : orange_restore_uploads_paths_same_volume($uploadsDir, $uploadsNextDir);
    if (!$sameVolume) {
        throw new RuntimeException('uploads/ and uploads_next/ are not on the same filesystem/volume (atomic rename cannot be guaranteed).');
    }

    $uploadsPreMergeDir = orange_restore_uploads_pre_merge_directory($projectRoot, $jobId);
    if (is_dir($uploadsPreMergeDir) || is_file($uploadsPreMergeDir)) {
        throw new RuntimeException('uploads_pre_merge target already exists: ' . $uploadsPreMergeDir);
    }

    return [
        'uploads_dir' => $uploadsDir,
        'uploads_next_dir' => $uploadsNextDir,
        'uploads_pre_merge_dir' => $uploadsPreMergeDir,
        'uploads_next_manifest' => $uploadsNextManifest,
        'staging_uploads_dir' => $stagingUploadsDir,
        'staging_uploads_tree_checksum' => $stagingInventory['tree_checksum_sha256'],
        'uploads_next_tree_checksum' => $uploadsNextInventory['tree_checksum_sha256'],
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
 * @param array{
 *   project_root:string,
 *   work_root?:string,
 *   job_id:string,
 *   admin_id:int,
 *   password:string,
 *   confirmation_phrase:string,
 *   env_override?:array<string,mixed>,
 *   admin_pdo_override?:?PDO,
 *   same_volume_override?:?callable(string,string):bool,
 *   snapshot_override?:?callable(string,string,string):array<string,mixed>,
 *   rename_override?:?callable(string,string):void
 * } $options
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
    $password = (string) ($options['password'] ?? '');
    $confirmationPhrase = (string) ($options['confirmation_phrase'] ?? '');

    if ($projectRoot === '' || $jobId === '' || $adminId <= 0) {
        throw new InvalidArgumentException('project_root, job_id, and admin_id are required.');
    }
    if ($password === '') {
        throw new InvalidArgumentException('password is required for uploads cutover re-authentication.');
    }
    if (trim($confirmationPhrase) === '') {
        throw new InvalidArgumentException('confirmation_phrase is required for uploads cutover.');
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

    $adminPdo = $options['admin_pdo_override'] ?? null;
    if (!$adminPdo instanceof PDO) {
        require_once rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.php';
        $adminPdo = db();
    }

    $operator = orange_restore_merge_uploads_cutover_assert_operator_reauth(
        $adminPdo,
        $job,
        $adminId,
        $password,
        $confirmationPhrase
    );
    $operatorUsername = (string) ($operator['username'] ?? ($job['operator_username'] ?? ''));

    /** @var callable(string,string):bool|null $sameVolumeOverride */
    $sameVolumeOverride = isset($options['same_volume_override']) && is_callable($options['same_volume_override'])
        ? $options['same_volume_override']
        : null;
    /** @var callable(string,string,string):array<string,mixed>|null $snapshotOverride */
    $snapshotOverride = isset($options['snapshot_override']) && is_callable($options['snapshot_override'])
        ? $options['snapshot_override']
        : null;
    /** @var callable(string,string):void|null $renameOverride */
    $renameOverride = isset($options['rename_override']) && is_callable($options['rename_override'])
        ? $options['rename_override']
        : null;

    $verify = orange_restore_merge_uploads_cutover_verify_preconditions(
        $projectRoot,
        $workRoot,
        $backupRoot,
        $job,
        $sameVolumeOverride
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
            $snapshotManifest = $snapshotOverride($workRoot, $jobId, $verify['uploads_dir']);
            if (!is_array($snapshotManifest) || ($snapshotManifest['ok'] ?? true) === false) {
                throw new RuntimeException('Pre-merge uploads snapshot override failed.');
            }
        } else {
            $snapshotManifest = orange_restore_merge_uploads_cutover_create_snapshot(
                $workRoot,
                $jobId,
                $verify['uploads_dir']
            );
        }

        orange_restore_merge_uploads_cutover_verify_snapshot($verify['uploads_dir'], $snapshotManifest);

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

    $firstRenameDone = false;
    try {
        orange_restore_merge_uploads_cutover_atomic_rename(
            $verify['uploads_dir'],
            $verify['uploads_pre_merge_dir'],
            $renameOverride
        );
        $firstRenameDone = true;

        orange_restore_merge_uploads_cutover_atomic_rename(
            $verify['uploads_next_dir'],
            $verify['uploads_dir'],
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
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_uploads_cutover_event($job, 'uploads_cutover_failed', 'failed', [
            'operator_admin_id' => $adminId,
            'operator_username' => $operatorUsername,
            'uploads_path' => $verify['uploads_dir'],
            'uploads_pre_merge_path' => $verify['uploads_pre_merge_dir'],
            'uploads_next_path' => $verify['uploads_next_dir'],
            'first_rename_done' => $firstRenameDone,
            'error' => $e->getMessage(),
            'database_writes' => false,
            'production_writes' => $firstRenameDone,
            'rollback_executed' => false,
        ]));

        if ($firstRenameDone) {
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
