<?php

declare(strict_types=1);

/**
 * Phase 2D.3 — Production uploads cutover self-tests (no DB writes, no rollback).
 *
 * Usage:
 *   php scripts/backup/self_test_restore_uploads_cutover.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_paths.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_job.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_lock.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_audit.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_approval.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_maintenance.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_staging_target.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_uploads_cutover.php';

$failures = 0;

function uploads_cutover_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function uploads_cutover_temp_root(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_2d3_' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

function uploads_cutover_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            uploads_cutover_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function uploads_cutover_try(callable $fn): ?Throwable
{
    try {
        $fn();

        return null;
    } catch (Throwable $e) {
        return $e;
    }
}

function uploads_cutover_write_file(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Cannot write test file: ' . $path);
    }
}

function uploads_cutover_write_uploads_tree(string $root, array $files): void
{
    foreach ($files as $relative => $contents) {
        uploads_cutover_write_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative), $contents);
    }
}

/**
 * @return array<string, mixed>
 */
function uploads_cutover_write_uploads_next_manifest(
    string $workRoot,
    string $jobId,
    string $projectRoot,
    string $stagingUploadsDir,
    string $uploadsNextDir
): array {
    $stagingInventory = orange_restore_uploads_tree_inventory($stagingUploadsDir);
    $nextInventory = orange_restore_uploads_tree_inventory($uploadsNextDir);
    $manifest = [
        'generated_at' => gmdate('c'),
        'job_id' => $jobId,
        'verified' => true,
        'verified_at' => gmdate('c'),
        'uploads_next_path' => $uploadsNextDir,
        'staging_uploads_path' => $stagingUploadsDir,
        'staging_uploads_tree_checksum' => $stagingInventory['tree_checksum_sha256'],
        'uploads_next_tree_checksum' => $nextInventory['tree_checksum_sha256'],
        'file_count' => $nextInventory['file_count'],
        'total_size' => $nextInventory['total_size'],
    ];
    orange_backup_write_json(orange_restore_uploads_next_manifest_path($workRoot, $jobId), $manifest);

    return $manifest;
}

/**
 * @return array{
 *   workRoot:string,
 *   backupRoot:string,
 *   projectRoot:string,
 *   jobId:string,
 *   job:array<string,mixed>,
 *   env:array<string,mixed>,
 *   uploadsDir:string,
 *   uploadsNextDir:string,
 *   stagingUploadsDir:string
 * }
 */
function uploads_cutover_seed_database_cutover_complete_job(): array
{
    $backupRoot = uploads_cutover_temp_root();
    $workRoot = $backupRoot . DIRECTORY_SEPARATOR . 'restore_work';
    mkdir($workRoot);

    $projectRoot = $backupRoot . DIRECTORY_SEPARATOR . 'project';
    mkdir($projectRoot);

    $packageDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'pkg_' . bin2hex(random_bytes(2));
    mkdir($packageDir, 0775, true);
    orange_backup_write_json($packageDir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => 'full_disaster',
        'dump_sha256' => str_repeat('c', 64),
    ]);
    orange_backup_write_checksums($packageDir, ['manifest.json']);
    $packageChecksum = orange_backup_sha256_file($packageDir . DIRECTORY_SEPARATOR . 'checksums.sha256');

    $anchorDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'anchor_' . bin2hex(random_bytes(2));
    mkdir($anchorDir, 0775, true);
    orange_backup_write_json($anchorDir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => 'full_disaster',
        'dump_sha256' => str_repeat('b', 64),
    ]);
    orange_backup_write_checksums($anchorDir, ['manifest.json']);
    $anchorChecksum = orange_backup_sha256_file($anchorDir . DIRECTORY_SEPARATOR . 'checksums.sha256');

    $job = orange_restore_job_create($workRoot, [
        'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
        'source_package_path' => $packageDir,
        'source_package_checksum' => $packageChecksum,
        'schema_revision' => 121,
        'operator_admin_id' => 1,
        'operator_username' => 'superadmin',
    ]);
    $jobId = (string) $job['job_id'];

    orange_restore_job_record_fresh_backup_anchor($workRoot, $jobId, $anchorDir, $anchorChecksum);
    orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_STAGING);
    orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_STAGING_VALIDATED);

    $stagingUploadsDir = orange_restore_staging_uploads_directory($workRoot, $jobId);
    uploads_cutover_write_uploads_tree($stagingUploadsDir, [
        'products/a.webp' => 'live-a',
        'channels/b.webp' => 'live-b',
    ]);

    $manifestPath = orange_restore_job_staging_manifest_path($workRoot, $jobId);
    orange_backup_write_json($manifestPath, [
        'staging_uploads_path' => $stagingUploadsDir,
        'schema_revision' => 121,
    ]);
    $manifestChecksum = orange_backup_sha256_file($manifestPath);

    $reportPath = orange_restore_job_report_path($workRoot, $jobId);
    orange_backup_write_json($reportPath, [
        'overall_result' => 'pass',
        'production_touched' => false,
        'staging_post_validation' => ['ok' => true],
        'staging_drv_report' => ['overall_result' => 'pass'],
    ]);

    orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL, [
        'restore_report_path' => $reportPath,
        'staging_restore_manifest_path' => $manifestPath,
        'owner_approval_window_started_at' => gmdate('c'),
        'staging_db' => 'orange_restore_staging',
        'staging_uploads_path' => $stagingUploadsDir,
    ]);

    $binding = [
        'job_id' => $jobId,
        'operator_id' => 1,
        'scope' => ORANGE_RESTORE_JOB_TYPE_FULL,
        'source_package_checksum' => $packageChecksum,
        'staging_restore_manifest_checksum' => $manifestChecksum,
        'rollback_anchor_checksum' => $anchorChecksum,
    ];
    $issued = orange_restore_approval_issue_token($binding);

    $job = orange_restore_job_read($workRoot, $jobId);
    $job['status'] = ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE;
    $job['approval_token_hash'] = $issued['hash'];
    $job['approval_token_binding'] = $binding;
    $job['approval_token_consumed_at'] = gmdate('c');
    $job['owner_approval_at'] = gmdate('c');
    $job['production_merge_approved'] = true;
    $job['merge_precheck_passed_at'] = gmdate('c');
    $job['database_cutover_completed_at'] = gmdate('c');
    orange_restore_job_write($workRoot, $job);

    $uploadsDir = orange_restore_production_uploads_directory($projectRoot);
    uploads_cutover_write_uploads_tree($uploadsDir, [
        'products/old.webp' => 'old-content',
    ]);

    $uploadsNextDir = orange_restore_uploads_next_directory($projectRoot);
    uploads_cutover_write_uploads_tree($uploadsNextDir, [
        'products/a.webp' => 'live-a',
        'channels/b.webp' => 'live-b',
    ]);

    uploads_cutover_write_uploads_next_manifest($workRoot, $jobId, $projectRoot, $stagingUploadsDir, $uploadsNextDir);

    $env = [
        'ORANGE_BACKUP_ROOT' => $backupRoot,
        'ORANGE_RESTORE_STAGING_DB' => 'orange_restore_staging',
    ];

    return [
        'workRoot' => $workRoot,
        'backupRoot' => $backupRoot,
        'projectRoot' => $projectRoot,
        'jobId' => $jobId,
        'job' => orange_restore_job_read($workRoot, $jobId),
        'env' => $env,
        'uploadsDir' => $uploadsDir,
        'uploadsNextDir' => $uploadsNextDir,
        'stagingUploadsDir' => $stagingUploadsDir,
    ];
}

function uploads_cutover_prepare_runtime(array $seed): void
{
    orange_restore_acquire_lock($seed['workRoot'], $seed['jobId']);
    orange_restore_merge_maintenance_enable($seed['workRoot'], $seed['jobId']);
}

// --- same volume helper ---
$sameVolRoot = uploads_cutover_temp_root();
$dirA = $sameVolRoot . DIRECTORY_SEPARATOR . 'a';
$dirB = $sameVolRoot . DIRECTORY_SEPARATOR . 'b';
mkdir($dirA);
mkdir($dirB);
uploads_cutover_self_test(
    orange_restore_uploads_paths_same_volume($dirA, $dirB),
    'same volume: sibling directories on same temp root'
);
uploads_cutover_rmdir($sameVolRoot);

// --- wrong state ---
$wrongState = uploads_cutover_seed_database_cutover_complete_job();
uploads_cutover_prepare_runtime($wrongState);
$jobBad = orange_restore_job_read($wrongState['workRoot'], $wrongState['jobId']);
$jobBad['status'] = ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED;
orange_restore_job_write($wrongState['workRoot'], $jobBad);
$err = uploads_cutover_try(static function () use ($wrongState): void {
    orange_restore_merge_uploads_cutover_run([
        'project_root' => $wrongState['projectRoot'],
        'work_root' => $wrongState['workRoot'],
        'job_id' => $wrongState['jobId'],
        'admin_id' => 1,
        'env_override' => $wrongState['env'],
    ]);
});
uploads_cutover_self_test($err !== null, 'cutover: wrong job state rejected');
orange_restore_release_lock($wrongState['workRoot']);
orange_restore_merge_maintenance_disable($wrongState['workRoot'], $wrongState['jobId']);
uploads_cutover_rmdir($wrongState['backupRoot']);

// --- maintenance inactive ---
$noMaint = uploads_cutover_seed_database_cutover_complete_job();
orange_restore_acquire_lock($noMaint['workRoot'], $noMaint['jobId']);
$err = uploads_cutover_try(static function () use ($noMaint): void {
    orange_restore_merge_uploads_cutover_run([
        'project_root' => $noMaint['projectRoot'],
        'work_root' => $noMaint['workRoot'],
        'job_id' => $noMaint['jobId'],
        'admin_id' => 1,
        'env_override' => $noMaint['env'],
    ]);
});
uploads_cutover_self_test($err !== null, 'cutover: maintenance not active rejected');
orange_restore_release_lock($noMaint['workRoot']);
uploads_cutover_rmdir($noMaint['backupRoot']);

// --- lock mismatch ---
$noLock = uploads_cutover_seed_database_cutover_complete_job();
orange_restore_merge_maintenance_enable($noLock['workRoot'], $noLock['jobId']);
$err = uploads_cutover_try(static function () use ($noLock): void {
    orange_restore_merge_uploads_cutover_run([
        'project_root' => $noLock['projectRoot'],
        'work_root' => $noLock['workRoot'],
        'job_id' => $noLock['jobId'],
        'admin_id' => 1,
        'env_override' => $noLock['env'],
    ]);
});
uploads_cutover_self_test($err !== null, 'cutover: missing lock rejected');
orange_restore_merge_maintenance_disable($noLock['workRoot'], $noLock['jobId']);
uploads_cutover_rmdir($noLock['backupRoot']);

// --- lock owned by other job ---
$wrongLock = uploads_cutover_seed_database_cutover_complete_job();
orange_restore_acquire_lock($wrongLock['workRoot'], 'other_job');
orange_restore_merge_maintenance_enable($wrongLock['workRoot'], $wrongLock['jobId']);
$err = uploads_cutover_try(static function () use ($wrongLock): void {
    orange_restore_merge_uploads_cutover_run([
        'project_root' => $wrongLock['projectRoot'],
        'work_root' => $wrongLock['workRoot'],
        'job_id' => $wrongLock['jobId'],
        'admin_id' => 1,
        'env_override' => $wrongLock['env'],
    ]);
});
uploads_cutover_self_test($err !== null, 'cutover: lock mismatch rejected');
orange_restore_release_lock($wrongLock['workRoot']);
orange_restore_merge_maintenance_disable($wrongLock['workRoot'], $wrongLock['jobId']);
uploads_cutover_rmdir($wrongLock['backupRoot']);

// --- uploads_next missing ---
$missingNext = uploads_cutover_seed_database_cutover_complete_job();
uploads_cutover_rmdir($missingNext['uploadsNextDir']);
uploads_cutover_prepare_runtime($missingNext);
$err = uploads_cutover_try(static function () use ($missingNext): void {
    orange_restore_merge_uploads_cutover_run([
        'project_root' => $missingNext['projectRoot'],
        'work_root' => $missingNext['workRoot'],
        'job_id' => $missingNext['jobId'],
        'admin_id' => 1,
        'env_override' => $missingNext['env'],
    ]);
});
uploads_cutover_self_test($err !== null, 'cutover: uploads_next missing rejected');
uploads_cutover_self_test(is_dir($missingNext['uploadsDir']), 'cutover: uploads untouched when uploads_next missing');
orange_restore_release_lock($missingNext['workRoot']);
orange_restore_merge_maintenance_disable($missingNext['workRoot'], $missingNext['jobId']);
uploads_cutover_rmdir($missingNext['backupRoot']);

// --- uploads_next checksum mismatch ---
$checksumBad = uploads_cutover_seed_database_cutover_complete_job();
uploads_cutover_write_file($checksumBad['uploadsNextDir'] . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'tampered.webp', 'x');
uploads_cutover_prepare_runtime($checksumBad);
$err = uploads_cutover_try(static function () use ($checksumBad): void {
    orange_restore_merge_uploads_cutover_run([
        'project_root' => $checksumBad['projectRoot'],
        'work_root' => $checksumBad['workRoot'],
        'job_id' => $checksumBad['jobId'],
        'admin_id' => 1,
        'env_override' => $checksumBad['env'],
    ]);
});
uploads_cutover_self_test($err !== null, 'cutover: uploads_next checksum mismatch rejected');
$beforeHash = orange_backup_sha256_file($checksumBad['uploadsDir'] . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'old.webp');
uploads_cutover_self_test($beforeHash !== '', 'cutover: uploads untouched before rename on checksum mismatch');
orange_restore_release_lock($checksumBad['workRoot']);
orange_restore_merge_maintenance_disable($checksumBad['workRoot'], $checksumBad['jobId']);
uploads_cutover_rmdir($checksumBad['backupRoot']);

// --- different filesystem ---
$diffVol = uploads_cutover_seed_database_cutover_complete_job();
uploads_cutover_prepare_runtime($diffVol);
$err = uploads_cutover_try(static function () use ($diffVol): void {
    orange_restore_merge_uploads_cutover_run([
        'project_root' => $diffVol['projectRoot'],
        'work_root' => $diffVol['workRoot'],
        'job_id' => $diffVol['jobId'],
        'admin_id' => 1,
        'env_override' => $diffVol['env'],
        'same_volume_override' => static fn (string $a, string $b): bool => false,
    ]);
});
uploads_cutover_self_test($err !== null, 'cutover: different filesystem rejected');
orange_restore_release_lock($diffVol['workRoot']);
orange_restore_merge_maintenance_disable($diffVol['workRoot'], $diffVol['jobId']);
uploads_cutover_rmdir($diffVol['backupRoot']);

// --- snapshot failure ---
$snapFail = uploads_cutover_seed_database_cutover_complete_job();
uploads_cutover_prepare_runtime($snapFail);
$err = uploads_cutover_try(static function () use ($snapFail): void {
    orange_restore_merge_uploads_cutover_run([
        'project_root' => $snapFail['projectRoot'],
        'work_root' => $snapFail['workRoot'],
        'job_id' => $snapFail['jobId'],
        'admin_id' => 1,
        'env_override' => $snapFail['env'],
        'snapshot_override' => static fn (): array => ['ok' => false],
    ]);
});
uploads_cutover_self_test($err !== null, 'cutover: snapshot failure rejected');
uploads_cutover_self_test(is_dir($snapFail['uploadsDir']), 'cutover: uploads untouched after snapshot failure');
orange_restore_release_lock($snapFail['workRoot']);
orange_restore_merge_maintenance_disable($snapFail['workRoot'], $snapFail['jobId']);
uploads_cutover_rmdir($snapFail['backupRoot']);

// --- rename failure before first rename ---
$renameFail = uploads_cutover_seed_database_cutover_complete_job();
uploads_cutover_prepare_runtime($renameFail);
$err = uploads_cutover_try(static function () use ($renameFail): void {
    orange_restore_merge_uploads_cutover_run([
        'project_root' => $renameFail['projectRoot'],
        'work_root' => $renameFail['workRoot'],
        'job_id' => $renameFail['jobId'],
        'admin_id' => 1,
        'env_override' => $renameFail['env'],
        'rename_override' => static function (): void {
            throw new RuntimeException('Simulated rename failure.');
        },
    ]);
});
uploads_cutover_self_test($err !== null, 'cutover: rename failure before first rename');
uploads_cutover_self_test(is_dir($renameFail['uploadsDir']), 'cutover: uploads directory preserved on pre-rename failure');
$failedJobRename = orange_restore_job_read($renameFail['workRoot'], $renameFail['jobId']);
uploads_cutover_self_test(
    ($failedJobRename['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE,
    'cutover: pre-rename failure keeps database_cutover_complete'
);
orange_restore_release_lock($renameFail['workRoot']);
orange_restore_merge_maintenance_disable($renameFail['workRoot'], $renameFail['jobId']);
uploads_cutover_rmdir($renameFail['backupRoot']);

// --- first rename succeeds second fails ---
$partialFail = uploads_cutover_seed_database_cutover_complete_job();
uploads_cutover_prepare_runtime($partialFail);
$renameCount = 0;
$err = uploads_cutover_try(static function () use ($partialFail, &$renameCount): void {
    orange_restore_merge_uploads_cutover_run([
        'project_root' => $partialFail['projectRoot'],
        'work_root' => $partialFail['workRoot'],
        'job_id' => $partialFail['jobId'],
        'admin_id' => 1,
        'env_override' => $partialFail['env'],
        'rename_override' => static function (string $from, string $to) use (&$renameCount): void {
            $renameCount++;
            if ($renameCount === 1) {
                if (!@rename($from, $to)) {
                    throw new RuntimeException('First rename failed unexpectedly.');
                }

                return;
            }
            throw new RuntimeException('Simulated second rename failure.');
        },
    ]);
});
uploads_cutover_self_test($err !== null, 'cutover: first rename success second fails surfaces error');
$partialJob = orange_restore_job_read($partialFail['workRoot'], $partialFail['jobId']);
uploads_cutover_self_test(
    ($partialJob['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE,
    'cutover: partial rename sets failed_merge'
);
uploads_cutover_self_test(
    orange_restore_merge_maintenance_status($partialFail['workRoot'])['active'] === true,
    'cutover: maintenance remains active after partial rename failure'
);
$preMergeDir = orange_restore_uploads_pre_merge_directory($partialFail['projectRoot'], $partialFail['jobId']);
uploads_cutover_self_test(is_dir($preMergeDir), 'cutover: uploads_pre_merge preserved after partial failure');
uploads_cutover_self_test(
    is_dir(orange_restore_pre_merge_uploads_snapshot_directory($partialFail['workRoot'], $partialFail['jobId'])),
    'cutover: snapshot preserved after partial failure'
);
uploads_cutover_self_test(!is_dir($partialFail['uploadsDir']), 'cutover: live uploads path moved after first rename');
orange_restore_release_lock($partialFail['workRoot']);
orange_restore_merge_maintenance_disable($partialFail['workRoot'], $partialFail['jobId']);
uploads_cutover_rmdir($partialFail['backupRoot']);

// --- successful uploads cutover ---
$success = uploads_cutover_seed_database_cutover_complete_job();
uploads_cutover_prepare_runtime($success);
$oldUploadsHash = orange_backup_sha256_file($success['uploadsDir'] . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'old.webp');
$result = orange_restore_merge_uploads_cutover_run([
    'project_root' => $success['projectRoot'],
    'work_root' => $success['workRoot'],
    'job_id' => $success['jobId'],
    'admin_id' => 1,
    'env_override' => $success['env'],
]);
uploads_cutover_self_test(
    ($result['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE,
    'cutover: successful transition to uploads_cutover_complete'
);
uploads_cutover_self_test(
    is_file($success['uploadsDir'] . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'a.webp'),
    'cutover: new uploads tree active after swap'
);
uploads_cutover_self_test(
    is_dir(orange_restore_uploads_pre_merge_directory($success['projectRoot'], $success['jobId'])),
    'cutover: uploads_pre_merge directory exists after success'
);
$preMergeOld = orange_restore_uploads_pre_merge_directory($success['projectRoot'], $success['jobId'])
    . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'old.webp';
uploads_cutover_self_test(
    is_file($preMergeOld) && hash_equals($oldUploadsHash, orange_backup_sha256_file($preMergeOld)),
    'cutover: pre-merge uploads preserved in uploads_pre_merge'
);
uploads_cutover_self_test(($result['rollback_executed'] ?? true) === false, 'cutover: no automatic rollback');
uploads_cutover_self_test(($result['database_writes'] ?? true) === false, 'cutover: database untouched');
$auditRows = orange_restore_audit_read_all($success['workRoot'], $success['jobId']);
$events = array_map(static fn (array $row): string => (string) ($row['uploads_cutover_event'] ?? ''), $auditRows);
uploads_cutover_self_test(in_array('uploads_snapshot_started', $events, true), 'audit: uploads_snapshot_started');
uploads_cutover_self_test(in_array('uploads_snapshot_completed', $events, true), 'audit: uploads_snapshot_completed');
uploads_cutover_self_test(in_array('uploads_cutover_started', $events, true), 'audit: uploads_cutover_started');
uploads_cutover_self_test(in_array('uploads_cutover_complete', $events, true), 'audit: uploads_cutover_complete');
orange_restore_release_lock($success['workRoot']);
orange_restore_merge_maintenance_disable($success['workRoot'], $success['jobId']);
uploads_cutover_rmdir($success['backupRoot']);

// --- state machine ---
try {
    orange_restore_job_assert_uploads_cutover_transition(
        ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE
    );
    uploads_cutover_self_test(true, 'state: database_cutover_complete -> uploads_cutover_complete');
} catch (Throwable) {
    uploads_cutover_self_test(false, 'state: database_cutover_complete -> uploads_cutover_complete');
}
try {
    orange_restore_job_assert_uploads_cutover_transition(
        ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED,
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE
    );
    uploads_cutover_self_test(false, 'state: direct uploads_cutover_complete blocked');
} catch (Throwable) {
    uploads_cutover_self_test(true, 'state: direct uploads_cutover_complete blocked');
}

exit($failures > 0 ? 1 : 0);
