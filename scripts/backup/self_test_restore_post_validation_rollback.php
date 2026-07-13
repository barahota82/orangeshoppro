<?php

declare(strict_types=1);

/**
 * Phase 2D.4 — Production post-validation + manual rollback self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_restore_post_validation_rollback.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_full.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_paths.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_job.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_lock.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_audit.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_approval.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_reauth.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_maintenance.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_staging_target.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_uploads_fs.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_validation_adapter_production.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_post_validation.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_rollback.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin_permissions.php';

$failures = 0;

function pvrb_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function pvrb_temp_root(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_2d4_' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

function pvrb_rmdir(string $dir): void
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
            pvrb_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function pvrb_try(callable $fn): ?Throwable
{
    try {
        $fn();

        return null;
    } catch (Throwable $e) {
        return $e;
    }
}

function pvrb_write_file(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Cannot write test file: ' . $path);
    }
}

function pvrb_write_uploads_tree(string $root, array $files): void
{
    foreach ($files as $relative => $contents) {
        pvrb_write_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative), $contents);
    }
}

function pvrb_test_pdo(string $permKey = 'backup_restore_full', bool $superuser = true): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $hash = password_hash('correct-pass', PASSWORD_DEFAULT);
    $pdo->exec(
        'CREATE TABLE admins (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            display_name TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            is_superuser INTEGER NOT NULL DEFAULT 0
        )'
    );
    $pdo->exec(
        'CREATE TABLE admin_permissions (
            admin_id INTEGER NOT NULL,
            resource_key TEXT NOT NULL,
            can_view INTEGER NOT NULL DEFAULT 0,
            can_edit INTEGER NOT NULL DEFAULT 0,
            can_delete INTEGER NOT NULL DEFAULT 0
        )'
    );
    $pdo->exec(
        'INSERT INTO admins (id, username, password_hash, display_name, is_active, is_superuser)
         VALUES (1, \'superadmin\', ' . $pdo->quote($hash) . ', \'Super Admin\', 1, ' . ($superuser ? '1' : '0') . ')'
    );
    if ($permKey !== '') {
        $pdo->exec(
            'INSERT INTO admin_permissions (admin_id, resource_key, can_view, can_edit, can_delete)
             VALUES (1, ' . $pdo->quote($permKey) . ', 1, 0, 0)'
        );
    }

    $GLOBALS['orange_schema_table_cache'] = [
        'admins' => true,
        'admin_permissions' => true,
    ];
    $GLOBALS['orange_schema_column_cache'] = [
        'admin_permissions.can_lock' => false,
        'admin_permissions.can_unlock' => false,
        'admin_permissions.can_print' => false,
        'admin_permissions.can_export' => false,
    ];

    return $pdo;
}

function pvrb_create_anchor(string $backupRoot, string $suffix = ''): array
{
    $anchorDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'anchor' . $suffix . '_' . bin2hex(random_bytes(2));
    mkdir($anchorDir, 0775, true);
    pvrb_write_file($anchorDir . DIRECTORY_SEPARATOR . 'dump.sql.gz', 'fake-dump');
    orange_backup_write_json($anchorDir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => 'full_disaster',
        'dump_file' => 'dump.sql.gz',
        'dump_sha256' => str_repeat('d', 64),
    ]);
    orange_backup_write_checksums($anchorDir, ['manifest.json', 'dump.sql.gz']);
    $checksum = orange_backup_sha256_file($anchorDir . DIRECTORY_SEPARATOR . 'checksums.sha256');

    return ['path' => $anchorDir, 'checksum' => $checksum];
}

/**
 * @return array<string, mixed>
 */
function pvrb_seed_uploads_cutover_complete_job(string $entryStatus = ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE): array
{
    $backupRoot = pvrb_temp_root();
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

    $anchor = pvrb_create_anchor($backupRoot);

    $job = orange_restore_job_create($workRoot, [
        'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
        'source_package_path' => $packageDir,
        'source_package_checksum' => $packageChecksum,
        'schema_revision' => 121,
        'operator_admin_id' => 1,
        'operator_username' => 'superadmin',
    ]);
    $jobId = (string) $job['job_id'];

    orange_restore_job_record_fresh_backup_anchor($workRoot, $jobId, $anchor['path'], $anchor['checksum']);

    $stagingUploadsDir = orange_restore_staging_uploads_directory($workRoot, $jobId);
    pvrb_write_uploads_tree($stagingUploadsDir, ['products/a.webp' => 'live-a']);

    $manifestPath = orange_restore_job_staging_manifest_path($workRoot, $jobId);
    orange_backup_write_json($manifestPath, [
        'staging_uploads_path' => $stagingUploadsDir,
        'schema_revision' => 121,
        'staging_post_validation' => ['table_count' => 10],
        'table_count' => 10,
    ]);
    $manifestChecksum = orange_backup_sha256_file($manifestPath);

    $uploadsDir = orange_restore_production_uploads_directory($projectRoot);
    pvrb_write_uploads_tree($uploadsDir, ['products/a.webp' => 'live-a']);
    $liveInventory = orange_restore_uploads_tree_inventory($uploadsDir);

    orange_backup_write_json(orange_restore_uploads_next_manifest_path($workRoot, $jobId), [
        'generated_at' => gmdate('c'),
        'job_id' => $jobId,
        'verified' => true,
        'file_count' => $liveInventory['file_count'],
        'total_size_bytes' => $liveInventory['total_size'],
        'aggregate_tree_checksum' => $liveInventory['tree_checksum_sha256'],
        'source_package_checksum' => $packageChecksum,
        'staging_restore_manifest_checksum' => $manifestChecksum,
    ]);

    $binding = [
        'job_id' => $jobId,
        'operator_id' => 1,
        'scope' => ORANGE_RESTORE_JOB_TYPE_FULL,
        'source_package_checksum' => $packageChecksum,
        'staging_restore_manifest_checksum' => $manifestChecksum,
        'rollback_anchor_checksum' => $anchor['checksum'],
    ];
    $issued = orange_restore_approval_issue_token($binding);

    $job = orange_restore_job_read($workRoot, $jobId);
    $job['status'] = $entryStatus;
    $job['approval_token_hash'] = $issued['hash'];
    $job['approval_token_binding'] = $binding;
    $job['approval_token_consumed_at'] = gmdate('c');
    $job['production_merge_approved'] = true;
    $job['staging_restore_manifest_path'] = $manifestPath;
    $job['uploads_cutover_completed_at'] = gmdate('c');
    orange_restore_job_write($workRoot, $job);

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
        'anchor' => $anchor,
        'packageChecksum' => $packageChecksum,
        'manifestChecksum' => $manifestChecksum,
        'adminPdo' => pvrb_test_pdo(),
    ];
}

function pvrb_prepare_runtime(array $seed): void
{
    orange_restore_acquire_lock($seed['workRoot'], $seed['jobId']);
    orange_restore_merge_maintenance_enable($seed['workRoot'], $seed['jobId']);
}

// --- gate summarizer: warnings do not bypass hard failures ---
$summary = orange_restore_validation_adapter_summarize_gates([
    orange_restore_validation_adapter_production_gate('hard_gate', ORANGE_RESTORE_PRODUCTION_GATE_HARD, false, 'fail'),
    orange_restore_validation_adapter_production_gate('warn_gate', ORANGE_RESTORE_PRODUCTION_GATE_WARNING, false, 'warn only'),
]);
pvrb_self_test($summary['passed'] === false && count($summary['hard_failures']) === 1, 'gates: warnings do not bypass hard failures');

// --- ROLLBACK phrase ---
pvrb_self_test(orange_restore_validate_rollback_phrase('ROLLBACK'), 'rollback phrase: exact ROLLBACK accepted');
pvrb_self_test(!orange_restore_validate_rollback_phrase('rollback'), 'rollback phrase: lowercase rejected');
pvrb_self_test(!orange_restore_validate_rollback_phrase('RESTORE'), 'rollback phrase: RESTORE rejected');

// --- post-validation: wrong entry state ---
$wrongState = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE);
pvrb_prepare_runtime($wrongState);
$err = pvrb_try(static function () use ($wrongState): void {
    orange_restore_merge_post_validation_run([
        'project_root' => $wrongState['projectRoot'],
        'work_root' => $wrongState['workRoot'],
        'job_id' => $wrongState['jobId'],
        'admin_id' => 1,
        'env_override' => $wrongState['env'],
    ]);
});
pvrb_self_test($err !== null && str_contains($err->getMessage(), 'uploads_cutover_complete'), 'post-validation: wrong entry state rejected');
orange_restore_release_lock($wrongState['workRoot']);
pvrb_rmdir($wrongState['backupRoot']);

// --- post-validation: lock missing ---
$noLock = pvrb_seed_uploads_cutover_complete_job();
$err = pvrb_try(static function () use ($noLock): void {
    orange_restore_merge_maintenance_enable($noLock['workRoot'], $noLock['jobId']);
    orange_restore_merge_post_validation_run([
        'project_root' => $noLock['projectRoot'],
        'work_root' => $noLock['workRoot'],
        'job_id' => $noLock['jobId'],
        'admin_id' => 1,
        'env_override' => $noLock['env'],
    ]);
});
pvrb_self_test($err !== null, 'post-validation: lock missing rejected');
pvrb_rmdir($noLock['backupRoot']);

// --- post-validation: maintenance missing ---
$noMaint = pvrb_seed_uploads_cutover_complete_job();
orange_restore_acquire_lock($noMaint['workRoot'], $noMaint['jobId']);
$err = pvrb_try(static function () use ($noMaint): void {
    orange_restore_merge_post_validation_run([
        'project_root' => $noMaint['projectRoot'],
        'work_root' => $noMaint['workRoot'],
        'job_id' => $noMaint['jobId'],
        'admin_id' => 1,
        'env_override' => $noMaint['env'],
    ]);
});
pvrb_self_test($err !== null, 'post-validation: maintenance missing rejected');
orange_restore_release_lock($noMaint['workRoot']);
pvrb_rmdir($noMaint['backupRoot']);

// --- post-validation: hard failure -> failed_post_merge ---
$hardFail = pvrb_seed_uploads_cutover_complete_job();
pvrb_prepare_runtime($hardFail);
$err = pvrb_try(static function () use ($hardFail): void {
    orange_restore_merge_post_validation_run([
        'project_root' => $hardFail['projectRoot'],
        'work_root' => $hardFail['workRoot'],
        'job_id' => $hardFail['jobId'],
        'admin_id' => 1,
        'env_override' => $hardFail['env'],
        'postcheck_override' => static fn (): array => [
            'ok' => false,
            'overall_result' => 'fail',
            'hard_failures' => ['schema_revision_exact_match: mismatch'],
            'warnings' => ['sample_warning'],
            'informational' => [],
            'gates' => [],
            'production_db' => 'orange_db',
            'schema_revision' => 121,
        ],
    ]);
});
$jobAfterFail = orange_restore_job_read($hardFail['workRoot'], $hardFail['jobId']);
$maintAfterFail = orange_restore_merge_maintenance_status($hardFail['workRoot']);
pvrb_self_test($err !== null, 'post-validation: hard failure throws');
pvrb_self_test(
    ($jobAfterFail['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE,
    'post-validation: hard failure -> failed_post_merge'
);
pvrb_self_test(($maintAfterFail['active'] ?? false) === true, 'post-validation: maintenance stays active on hard failure');
pvrb_self_test(is_file(orange_restore_production_post_validation_report_path($hardFail['workRoot'], $hardFail['jobId'])), 'post-validation: report written on failure');
orange_restore_release_lock($hardFail['workRoot']);
pvrb_rmdir($hardFail['backupRoot']);

// --- post-validation: success -> completed only after maintenance disable ---
$success = pvrb_seed_uploads_cutover_complete_job();
pvrb_prepare_runtime($success);
$result = orange_restore_merge_post_validation_run([
    'project_root' => $success['projectRoot'],
    'work_root' => $success['workRoot'],
    'job_id' => $success['jobId'],
    'admin_id' => 1,
    'env_override' => $success['env'],
    'postcheck_override' => static fn (): array => [
        'ok' => true,
        'overall_result' => 'pass',
        'hard_failures' => [],
        'warnings' => ['non_blocking_warning'],
        'informational' => ['maintenance_active_during_validation: ok'],
        'gates' => [
            ['gate_id' => 'table_count_exact_match', 'details' => ['expected' => 10, 'live' => 10]],
            ['gate_id' => 'critical_row_counts', 'details' => ['checks' => []]],
            ['gate_id' => 'gl_debit_credit_balance', 'details' => ['difference' => 0.0]],
            ['gate_id' => 'uploads_checksum_match', 'details' => ['match' => true]],
        ],
        'production_db' => 'orange_db',
        'schema_revision' => 121,
    ],
]);
$jobDone = orange_restore_job_read($success['workRoot'], $success['jobId']);
$maintDone = orange_restore_merge_maintenance_status($success['workRoot']);
pvrb_self_test(($result['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_COMPLETED, 'post-validation: success -> completed');
pvrb_self_test(($maintDone['active'] ?? true) === false, 'post-validation: maintenance disabled after pass');
pvrb_self_test(is_file(orange_restore_final_restore_report_path($success['workRoot'], $success['jobId'])), 'post-validation: final_restore_report.json written');
$auditSuccess = orange_restore_audit_read_all($success['workRoot'], $success['jobId']);
$auditEvents = array_column($auditSuccess, 'post_validation_event');
pvrb_self_test(in_array('restore_completed', $auditEvents, true), 'post-validation: restore_completed audit event');
orange_restore_release_lock($success['workRoot']);
pvrb_rmdir($success['backupRoot']);

// --- rollback: wrong state (completed) ---
$completed = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_COMPLETED);
pvrb_prepare_runtime($completed);
$err = pvrb_try(static function () use ($completed): void {
    orange_restore_merge_rollback_run([
        'project_root' => $completed['projectRoot'],
        'work_root' => $completed['workRoot'],
        'job_id' => $completed['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $completed['env'],
        'admin_pdo_override' => $completed['adminPdo'],
    ]);
});
pvrb_self_test($err !== null, 'rollback: completed state rejected');
orange_restore_release_lock($completed['workRoot']);
pvrb_rmdir($completed['backupRoot']);

// --- rollback: wrong password ---
$badPass = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
pvrb_prepare_runtime($badPass);
$err = pvrb_try(static function () use ($badPass): void {
    orange_restore_merge_rollback_run([
        'project_root' => $badPass['projectRoot'],
        'work_root' => $badPass['workRoot'],
        'job_id' => $badPass['jobId'],
        'admin_id' => 1,
        'password' => 'wrong-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $badPass['env'],
        'admin_pdo_override' => $badPass['adminPdo'],
    ]);
});
pvrb_self_test($err !== null && str_contains($err->getMessage(), 'password'), 'rollback: wrong password rejected');
orange_restore_release_lock($badPass['workRoot']);
pvrb_rmdir($badPass['backupRoot']);

// --- rollback: wrong ROLLBACK phrase ---
$badPhrase = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
pvrb_prepare_runtime($badPhrase);
$err = pvrb_try(static function () use ($badPhrase): void {
    orange_restore_merge_rollback_run([
        'project_root' => $badPhrase['projectRoot'],
        'work_root' => $badPhrase['workRoot'],
        'job_id' => $badPhrase['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'RESTORE',
        'env_override' => $badPhrase['env'],
        'admin_pdo_override' => $badPhrase['adminPdo'],
    ]);
});
pvrb_self_test($err !== null && str_contains($err->getMessage(), 'ROLLBACK'), 'rollback: wrong confirmation phrase rejected');
orange_restore_release_lock($badPhrase['workRoot']);
pvrb_rmdir($badPhrase['backupRoot']);

// --- rollback: wrong permission (non-superuser) ---
$badPerm = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
$badPerm['adminPdo'] = pvrb_test_pdo('backup_restore_full', false);
pvrb_prepare_runtime($badPerm);
$err = pvrb_try(static function () use ($badPerm): void {
    orange_restore_merge_rollback_run([
        'project_root' => $badPerm['projectRoot'],
        'work_root' => $badPerm['workRoot'],
        'job_id' => $badPerm['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $badPerm['env'],
        'admin_pdo_override' => $badPerm['adminPdo'],
    ]);
});
pvrb_self_test($err !== null, 'rollback: non-superuser rejected');
orange_restore_release_lock($badPerm['workRoot']);
pvrb_rmdir($badPerm['backupRoot']);

// --- rollback: missing anchor ---
$noAnchor = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
$jobNoAnchor = orange_restore_job_read($noAnchor['workRoot'], $noAnchor['jobId']);
$jobNoAnchor['fresh_backup_path'] = '';
$jobNoAnchor['fresh_backup_checksum'] = '';
orange_restore_job_write($noAnchor['workRoot'], $jobNoAnchor);
pvrb_prepare_runtime($noAnchor);
$err = pvrb_try(static function () use ($noAnchor): void {
    orange_restore_merge_rollback_run([
        'project_root' => $noAnchor['projectRoot'],
        'work_root' => $noAnchor['workRoot'],
        'job_id' => $noAnchor['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $noAnchor['env'],
        'admin_pdo_override' => $noAnchor['adminPdo'],
    ]);
});
pvrb_self_test($err !== null && str_contains($err->getMessage(), 'anchor'), 'rollback: missing anchor rejected');
orange_restore_release_lock($noAnchor['workRoot']);
pvrb_rmdir($noAnchor['backupRoot']);

// --- rollback: anchor checksum mismatch ---
$badChecksum = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
$jobBadCs = orange_restore_job_read($badChecksum['workRoot'], $badChecksum['jobId']);
$jobBadCs['fresh_backup_checksum'] = str_repeat('0', 64);
orange_restore_job_write($badChecksum['workRoot'], $jobBadCs);
pvrb_prepare_runtime($badChecksum);
$err = pvrb_try(static function () use ($badChecksum): void {
    orange_restore_merge_rollback_run([
        'project_root' => $badChecksum['projectRoot'],
        'work_root' => $badChecksum['workRoot'],
        'job_id' => $badChecksum['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $badChecksum['env'],
        'admin_pdo_override' => $badChecksum['adminPdo'],
    ]);
});
pvrb_self_test($err !== null && str_contains($err->getMessage(), 'checksum'), 'rollback: anchor checksum mismatch rejected');
orange_restore_release_lock($badChecksum['workRoot']);
pvrb_rmdir($badChecksum['backupRoot']);

// --- rollback: another job anchor rejected (job-only flag false) ---
$otherAnchor = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
$jobOther = orange_restore_job_read($otherAnchor['workRoot'], $otherAnchor['jobId']);
$jobOther['rollback_anchor_job_only'] = false;
orange_restore_job_write($otherAnchor['workRoot'], $jobOther);
pvrb_prepare_runtime($otherAnchor);
$err = pvrb_try(static function () use ($otherAnchor): void {
    orange_restore_merge_rollback_run([
        'project_root' => $otherAnchor['projectRoot'],
        'work_root' => $otherAnchor['workRoot'],
        'job_id' => $otherAnchor['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $otherAnchor['env'],
        'admin_pdo_override' => $otherAnchor['adminPdo'],
    ]);
});
pvrb_self_test($err !== null && str_contains($err->getMessage(), 'job-only'), 'rollback: non job-only anchor rejected');
orange_restore_release_lock($otherAnchor['workRoot']);
pvrb_rmdir($otherAnchor['backupRoot']);

// --- rollback: DB failure keeps maintenance active ---
$dbFail = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
pvrb_prepare_runtime($dbFail);
$err = pvrb_try(static function () use ($dbFail): void {
    orange_restore_merge_rollback_run([
        'project_root' => $dbFail['projectRoot'],
        'work_root' => $dbFail['workRoot'],
        'job_id' => $dbFail['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $dbFail['env'],
        'admin_pdo_override' => $dbFail['adminPdo'],
        'db_import_override' => static function (): void {
            throw new RuntimeException('Simulated DB rollback failure.');
        },
    ]);
});
$jobDbFail = orange_restore_job_read($dbFail['workRoot'], $dbFail['jobId']);
$maintDbFail = orange_restore_merge_maintenance_status($dbFail['workRoot']);
pvrb_self_test($err !== null, 'rollback: DB failure throws');
pvrb_self_test(($jobDbFail['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED, 'rollback: DB failure -> rollback_failed');
pvrb_self_test(($maintDbFail['active'] ?? false) === true, 'rollback: DB failure keeps maintenance active');
orange_restore_release_lock($dbFail['workRoot']);
pvrb_rmdir($dbFail['backupRoot']);

// --- rollback: crash after DB before uploads (resume checkpoint) ---
$crashUploads = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS);
$jobCrash = orange_restore_job_read($crashUploads['workRoot'], $crashUploads['jobId']);
$jobCrash['rollback_checkpoint'] = ORANGE_RESTORE_ROLLBACK_CHECKPOINT_DATABASE_COMPLETE;
orange_restore_job_write($crashUploads['workRoot'], $jobCrash);
pvrb_prepare_runtime($crashUploads);
$uploadsCalled = false;
$resultCrash = orange_restore_merge_rollback_run([
    'project_root' => $crashUploads['projectRoot'],
    'work_root' => $crashUploads['workRoot'],
    'job_id' => $crashUploads['jobId'],
    'admin_id' => 1,
    'password' => 'correct-pass',
    'confirmation_phrase' => 'ROLLBACK',
    'env_override' => $crashUploads['env'],
    'admin_pdo_override' => $crashUploads['adminPdo'],
    'db_import_override' => static function (): void {
        throw new RuntimeException('DB must not re-run when checkpoint is database_complete.');
    },
    'uploads_rollback_override' => static function () use (&$uploadsCalled): void {
        $uploadsCalled = true;
    },
    'rollback_postcheck_override' => static fn (): array => [
        'ok' => true,
        'hard_failures' => [],
        'warnings' => [],
        'overall_result' => 'pass',
    ],
]);
pvrb_self_test($uploadsCalled, 'rollback: resumes uploads after database_complete checkpoint');
pvrb_self_test(($resultCrash['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK, 'rollback: crash resume -> rolled_back');
orange_restore_release_lock($crashUploads['workRoot']);
pvrb_rmdir($crashUploads['backupRoot']);

// --- rollback: uploads failure ---
$uploadsFail = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
pvrb_prepare_runtime($uploadsFail);
$err = pvrb_try(static function () use ($uploadsFail): void {
    orange_restore_merge_rollback_run([
        'project_root' => $uploadsFail['projectRoot'],
        'work_root' => $uploadsFail['workRoot'],
        'job_id' => $uploadsFail['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $uploadsFail['env'],
        'admin_pdo_override' => $uploadsFail['adminPdo'],
        'db_import_override' => static function (): void {
            // ok
        },
        'uploads_rollback_override' => static function (): void {
            throw new RuntimeException('Simulated uploads rollback failure.');
        },
    ]);
});
pvrb_self_test($err !== null, 'rollback: uploads failure throws');
orange_restore_release_lock($uploadsFail['workRoot']);
pvrb_rmdir($uploadsFail['backupRoot']);

// --- rollback: validation failure after uploads ---
$valFail = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
pvrb_prepare_runtime($valFail);
$err = pvrb_try(static function () use ($valFail): void {
    orange_restore_merge_rollback_run([
        'project_root' => $valFail['projectRoot'],
        'work_root' => $valFail['workRoot'],
        'job_id' => $valFail['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $valFail['env'],
        'admin_pdo_override' => $valFail['adminPdo'],
        'db_import_override' => static function (): void {},
        'uploads_rollback_override' => static function (): void {},
        'rollback_postcheck_override' => static fn (): array => [
            'ok' => false,
            'hard_failures' => ['rollback_super_admin_present: missing'],
            'warnings' => [],
            'overall_result' => 'fail',
        ],
    ]);
});
$jobValFail = orange_restore_job_read($valFail['workRoot'], $valFail['jobId']);
$maintValFail = orange_restore_merge_maintenance_status($valFail['workRoot']);
pvrb_self_test($err !== null, 'rollback: validation failure throws');
pvrb_self_test(($jobValFail['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED, 'rollback: validation failure -> rollback_failed');
pvrb_self_test(($maintValFail['active'] ?? false) === true, 'rollback: validation failure keeps maintenance active');
orange_restore_release_lock($valFail['workRoot']);
pvrb_rmdir($valFail['backupRoot']);

// --- rollback: successful full path -> rolled_back ---
$rbOk = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
$preMergeDir = orange_restore_uploads_pre_merge_directory($rbOk['projectRoot'], $rbOk['jobId']);
pvrb_write_uploads_tree($preMergeDir, ['products/old.webp' => 'pre-merge']);
pvrb_prepare_runtime($rbOk);
$resultRb = orange_restore_merge_rollback_run([
    'project_root' => $rbOk['projectRoot'],
    'work_root' => $rbOk['workRoot'],
    'job_id' => $rbOk['jobId'],
    'admin_id' => 1,
    'password' => 'correct-pass',
    'confirmation_phrase' => 'ROLLBACK',
    'env_override' => $rbOk['env'],
    'admin_pdo_override' => $rbOk['adminPdo'],
    'db_import_override' => static function (): void {},
    'rename_override' => static function (string $from, string $to): void {
        if (!@rename($from, $to)) {
            throw new RuntimeException('Test rename failed.');
        }
    },
    'rollback_postcheck_override' => static fn (): array => [
        'ok' => true,
        'hard_failures' => [],
        'warnings' => [],
        'overall_result' => 'pass',
    ],
]);
$jobRb = orange_restore_job_read($rbOk['workRoot'], $rbOk['jobId']);
$maintRb = orange_restore_merge_maintenance_status($rbOk['workRoot']);
pvrb_self_test(($resultRb['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK, 'rollback: success -> rolled_back');
pvrb_self_test(($resultRb['automatic_rollback'] ?? true) === false, 'rollback: no automatic rollback flag');
pvrb_self_test(($maintRb['active'] ?? true) === false, 'rollback: maintenance disabled after success');
pvrb_self_test(
    ($jobRb['rollback_checkpoint'] ?? '') === ORANGE_RESTORE_ROLLBACK_CHECKPOINT_VALIDATION_PASSED,
    'rollback: validation_passed checkpoint persisted'
);
$auditRb = orange_restore_audit_read_all($rbOk['workRoot'], $rbOk['jobId']);
$rbEvents = array_column($auditRb, 'rollback_event');
pvrb_self_test(in_array('rollback_completed', $rbEvents, true), 'rollback: rollback_completed audit event');
orange_restore_release_lock($rbOk['workRoot']);
pvrb_rmdir($rbOk['backupRoot']);

// --- production gate unit: GL imbalance ---
$glGates = orange_restore_validation_adapter_summarize_gates([
    orange_restore_validation_adapter_production_gate(
        'gl_debit_credit_balance',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        false,
        'GL imbalance',
        ['difference' => 5.0]
    ),
]);
pvrb_self_test($glGates['passed'] === false, 'gates: GL imbalance is hard failure');

// --- production gate unit: orphan / FIFO labels ---
pvrb_self_test(
    ORANGE_RESTORE_PRODUCTION_GL_TOLERANCE === 0.01,
    'gates: GL tolerance is 0.01'
);

echo PHP_EOL;
if ($failures === 0) {
    echo "ALL Phase 2D.4 post-validation + rollback self-tests PASSED.\n";
    exit(0);
}

echo "FAILED: {$failures} test(s).\n";
exit(1);
