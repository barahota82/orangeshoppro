<?php

declare(strict_types=1);

/**
 * Phase 2C — Restore approval + staging validation gate self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_restore_approval.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_full.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_paths.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_job.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_audit.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_approval.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_reauth.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_validation_adapter.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_orchestrator.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin_permissions.php';

$failures = 0;

function restore_approval_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function restore_approval_temp_root(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_2c_' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

function restore_approval_rmdir(string $dir): void
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
            restore_approval_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * SQLite in-memory PDO for approval gate tests (re-auth + permission matrix).
 */
function restore_approval_test_pdo(string $permKey = 'backup_restore_full', bool $superuser = true, int $adminId = 1): PDO
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
         VALUES (' . $adminId . ', \'superadmin\', ' . $pdo->quote($hash) . ', \'Super Admin\', 1, ' . ($superuser ? '1' : '0') . ')'
    );
    if ($permKey !== '') {
        $pdo->exec(
            'INSERT INTO admin_permissions (admin_id, resource_key, can_view, can_edit, can_delete)
             VALUES (' . $adminId . ', ' . $pdo->quote($permKey) . ', 1, 0, 0)'
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

/**
 * @return array{workRoot:string,backupRoot:string,packagePath:string,jobId:string,job:array<string,mixed>,pdo:RestoreApprovalMockPdo}
 */
function restore_approval_seed_job(
    string $jobType,
    string $countryCode = '',
    bool $stagingPass = true,
    bool $withRollback = true
): array {
    $backupRoot = restore_approval_temp_root();
    $workRoot = $backupRoot . DIRECTORY_SEPARATOR . 'restore_work';
    mkdir($workRoot);

    $packageDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'pkg_' . bin2hex(random_bytes(2));
    mkdir($packageDir, 0775, true);
    $manifest = [
        'package_type' => $jobType === ORANGE_RESTORE_JOB_TYPE_COUNTRY ? 'country_recovery' : 'full_disaster',
        'package_version' => '1.2',
        'schema_revision' => 121,
        'export_backend' => 'php_pdo',
        'dump_sha256' => str_repeat('c', 64),
    ];
    if ($jobType === ORANGE_RESTORE_JOB_TYPE_COUNTRY) {
        $manifest['country_code'] = strtoupper($countryCode);
        $manifest['country_id'] = 1;
        $manifest['package_checksum'] = str_repeat('c', 64);
    }
    orange_backup_write_json($packageDir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
    orange_backup_write_checksums($packageDir, ['manifest.json']);

    $packageChecksum = orange_backup_sha256_file($packageDir . DIRECTORY_SEPARATOR . 'checksums.sha256');

    $job = orange_restore_job_create($workRoot, [
        'job_type' => $jobType,
        'operator_admin_id' => 0,
        'operator_username' => 'cli',
        'source_package_path' => $packageDir,
        'source_package_checksum' => $packageChecksum,
        'package_version' => '1.2',
        'schema_revision' => 121,
        'country_id' => $jobType === ORANGE_RESTORE_JOB_TYPE_COUNTRY ? 1 : 0,
        'country_code' => strtoupper($countryCode),
        'approval_phrase_expected' => orange_restore_confirmation_phrase($jobType, $countryCode),
        'staging_db' => 'orange_restore_staging',
        'staging_uploads_path' => $workRoot . DIRECTORY_SEPARATOR . 'staging_uploads',
    ]);
    $jobId = (string) $job['job_id'];

    orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_VALIDATED);
    if ($withRollback) {
        orange_restore_job_record_fresh_backup_anchor(
            $workRoot,
            $jobId,
            $backupRoot . DIRECTORY_SEPARATOR . 'fresh_anchor',
            str_repeat('b', 64)
        );
    } else {
        orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_FRESH_BACKUP);
    }
    orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_STAGING);
    orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_STAGING_VALIDATED);

    $stagingManifestPath = orange_restore_job_staging_manifest_path($workRoot, $jobId);
    orange_backup_write_json($stagingManifestPath, ['table_count' => 10, 'schema_revision' => 121]);

    $report = [
        'overall_result' => $stagingPass ? 'pass' : 'fail',
        'production_touched' => false,
        'staging_post_validation' => ['ok' => $stagingPass],
        'staging_drv_report' => ['overall_result' => $stagingPass ? 'pass' : 'fail'],
        'country_staging_post_validation' => ['ok' => $stagingPass],
        'country_staging_drv_report' => ['overall_result' => $stagingPass ? 'pass' : 'fail'],
    ];
    $reportPath = orange_restore_job_report_path($workRoot, $jobId);
    orange_backup_write_json($reportPath, $report);

    $job = orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL, [
        'restore_report_path' => $reportPath,
        'staging_restore_manifest_path' => $stagingManifestPath,
        'owner_approval_window_started_at' => gmdate('c'),
    ]);

    $permKey = $jobType === ORANGE_RESTORE_JOB_TYPE_COUNTRY ? 'backup_restore_country' : 'backup_restore_full';
    $adminId = $jobType === ORANGE_RESTORE_JOB_TYPE_COUNTRY ? 2 : 1;
    $pdo = restore_approval_test_pdo($permKey, true, $adminId);

    return [
        'workRoot' => $workRoot,
        'backupRoot' => $backupRoot,
        'packagePath' => $packageDir,
        'jobId' => $jobId,
        'job' => $job,
        'pdo' => $pdo,
    ];
}

function restore_approval_try(callable $fn): ?Throwable
{
    try {
        $fn();

        return null;
    } catch (Throwable $e) {
        return $e;
    }
}

// --- State machine ---
restore_approval_self_test(
    in_array(ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE, orange_restore_job_allowed_statuses(), true),
    'state: approved_for_merge allowed'
);
try {
    orange_restore_job_assert_approval_transition(
        ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL,
        ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE
    );
    restore_approval_self_test(true, 'state: awaiting -> approved_for_merge');
} catch (Throwable $e) {
    restore_approval_self_test(false, 'state: awaiting -> approved_for_merge');
}
try {
    orange_restore_job_assert_approval_transition(
        ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL,
        ORANGE_RESTORE_JOB_STATUS_MERGED
    );
    restore_approval_self_test(false, 'state: direct merge blocked');
} catch (Throwable $e) {
    restore_approval_self_test(true, 'state: direct merge blocked');
}

// --- Token binding ---
$tokenBinding = orange_restore_approval_issue_token([
    'job_id' => 'demo_job',
    'operator_id' => 1,
    'scope' => ORANGE_RESTORE_JOB_TYPE_FULL,
    'source_package_checksum' => str_repeat('a', 64),
    'staging_restore_manifest_checksum' => str_repeat('d', 64),
    'rollback_anchor_checksum' => str_repeat('b', 64),
]);
restore_approval_self_test(strlen($tokenBinding['plaintext']) === 64, 'token: random length');
restore_approval_self_test($tokenBinding['hash'] === hash('sha256', $tokenBinding['plaintext']), 'token: hash at rest');

$tokenJob = [
    'job_id' => 'demo_job',
    'approval_token_hash' => $tokenBinding['hash'],
    'approval_token_binding' => $tokenBinding['binding'],
    'approval_token_expires_at' => gmdate('c', time() + 3600),
];
$verifyOk = orange_restore_approval_verify_token($tokenJob, $tokenBinding['plaintext'], false);
restore_approval_self_test($verifyOk['ok'] === true, 'token: verify match');
$verifyBad = orange_restore_approval_verify_token($tokenJob, 'bad-token', false);
restore_approval_self_test($verifyBad['ok'] === false, 'token: verify mismatch');

$tokenJob['approval_token_consumed_at'] = gmdate('c');
$replay = orange_restore_approval_verify_token($tokenJob, $tokenBinding['plaintext'], true);
restore_approval_self_test($replay['ok'] === false, 'token: replay after consume');

$expiredJob = $tokenJob;
$expiredJob['approval_token_consumed_at'] = '';
$expiredJob['approval_token_expires_at'] = gmdate('c', time() - 10);
$expiredVerify = orange_restore_approval_verify_token($expiredJob, $tokenBinding['plaintext'], false);
restore_approval_self_test($expiredVerify['ok'] === false, 'token: expired');

// --- Full approval success ---
$fullSeed = restore_approval_seed_job(ORANGE_RESTORE_JOB_TYPE_FULL);
$fullResult = orange_restore_orchestrator_approve_for_merge($fullSeed['pdo'], [
    'project_root' => $projectRoot,
    'work_root' => $fullSeed['workRoot'],
    'job_id' => $fullSeed['jobId'],
    'admin_id' => 1,
    'password' => 'correct-pass',
    'confirmation_phrase' => 'RESTORE',
    'env_override' => ['ORANGE_BACKUP_ROOT' => $fullSeed['backupRoot']],
]);
restore_approval_self_test(
    ($fullResult['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE,
    'approve: full success'
);
restore_approval_self_test(($fullResult['production_writes'] ?? true) === false, 'approve: no production writes (full)');
$fullStatus = orange_restore_orchestrator_job_status_report($fullSeed['workRoot'], $fullSeed['jobId']);
restore_approval_self_test(($fullStatus['approval']['status'] ?? '') === 'approved', 'status report: full approved');
restore_approval_rmdir($fullSeed['backupRoot']);

// --- Country approval success ---
$countrySeed = restore_approval_seed_job(ORANGE_RESTORE_JOB_TYPE_COUNTRY, 'kw');
$countryResult = orange_restore_orchestrator_approve_for_merge($countrySeed['pdo'], [
    'project_root' => $projectRoot,
    'work_root' => $countrySeed['workRoot'],
    'job_id' => $countrySeed['jobId'],
    'admin_id' => 2,
    'password' => 'correct-pass',
    'confirmation_phrase' => 'RESTORE KW',
    'env_override' => ['ORANGE_BACKUP_ROOT' => $countrySeed['backupRoot']],
]);
restore_approval_self_test(
    ($countryResult['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE,
    'approve: country success'
);
restore_approval_rmdir($countrySeed['backupRoot']);

// --- Wrong state ---
$wrongState = restore_approval_seed_job(ORANGE_RESTORE_JOB_TYPE_FULL);
$wrongJob = orange_restore_job_read($wrongState['workRoot'], $wrongState['jobId']);
$wrongJob['status'] = ORANGE_RESTORE_JOB_STATUS_CREATED;
orange_restore_job_write($wrongState['workRoot'], $wrongJob);
$err = restore_approval_try(static function () use ($wrongState, $projectRoot): void {
    orange_restore_orchestrator_approve_for_merge($wrongState['pdo'], [
        'project_root' => $projectRoot,
        'work_root' => $wrongState['workRoot'],
        'job_id' => $wrongState['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'RESTORE',
        'env_override' => ['ORANGE_BACKUP_ROOT' => $wrongState['backupRoot']],
    ]);
});
restore_approval_self_test($err !== null, 'gate: wrong state rejected');
restore_approval_rmdir($wrongState['backupRoot']);

// --- Wrong password ---
$badPass = restore_approval_seed_job(ORANGE_RESTORE_JOB_TYPE_FULL);
$err = restore_approval_try(static function () use ($badPass, $projectRoot): void {
    orange_restore_orchestrator_approve_for_merge($badPass['pdo'], [
        'project_root' => $projectRoot,
        'work_root' => $badPass['workRoot'],
        'job_id' => $badPass['jobId'],
        'admin_id' => 1,
        'password' => 'wrong-pass',
        'confirmation_phrase' => 'RESTORE',
        'env_override' => ['ORANGE_BACKUP_ROOT' => $badPass['backupRoot']],
    ]);
});
restore_approval_self_test($err !== null, 'gate: wrong password rejected');
restore_approval_rmdir($badPass['backupRoot']);

// --- Wrong confirmation phrase ---
$badPhrase = restore_approval_seed_job(ORANGE_RESTORE_JOB_TYPE_FULL);
$err = restore_approval_try(static function () use ($badPhrase, $projectRoot): void {
    orange_restore_orchestrator_approve_for_merge($badPhrase['pdo'], [
        'project_root' => $projectRoot,
        'work_root' => $badPhrase['workRoot'],
        'job_id' => $badPhrase['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'RESTORE NOW',
        'env_override' => ['ORANGE_BACKUP_ROOT' => $badPhrase['backupRoot']],
    ]);
});
restore_approval_self_test($err !== null, 'gate: wrong confirmation phrase rejected');
restore_approval_rmdir($badPhrase['backupRoot']);

// --- Non-super-admin ---
$nonSuper = restore_approval_seed_job(ORANGE_RESTORE_JOB_TYPE_FULL);
$nonSuperPdo = restore_approval_test_pdo('backup_restore_full', false);
$err = restore_approval_try(static function () use ($nonSuper, $nonSuperPdo, $projectRoot): void {
    orange_restore_orchestrator_approve_for_merge($nonSuperPdo, [
        'project_root' => $projectRoot,
        'work_root' => $nonSuper['workRoot'],
        'job_id' => $nonSuper['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'RESTORE',
        'env_override' => ['ORANGE_BACKUP_ROOT' => $nonSuper['backupRoot']],
    ]);
});
restore_approval_self_test($err !== null, 'gate: non-super-admin rejected');
restore_approval_rmdir($nonSuper['backupRoot']);

// --- Wrong permission ---
$noPerm = restore_approval_seed_job(ORANGE_RESTORE_JOB_TYPE_FULL);
$noPermPdo = restore_approval_test_pdo('', true, 3);
$err = restore_approval_try(static function () use ($noPerm, $noPermPdo, $projectRoot): void {
    orange_restore_orchestrator_approve_for_merge($noPermPdo, [
        'project_root' => $projectRoot,
        'work_root' => $noPerm['workRoot'],
        'job_id' => $noPerm['jobId'],
        'admin_id' => 3,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'RESTORE',
        'env_override' => ['ORANGE_BACKUP_ROOT' => $noPerm['backupRoot']],
    ]);
});
restore_approval_self_test($err !== null, 'gate: wrong permission rejected');
restore_approval_rmdir($noPerm['backupRoot']);

// --- Checksum mismatch ---
$checksumBad = restore_approval_seed_job(ORANGE_RESTORE_JOB_TYPE_FULL);
$jobBad = orange_restore_job_read($checksumBad['workRoot'], $checksumBad['jobId']);
$jobBad['source_package_checksum'] = str_repeat('f', 64);
orange_restore_job_write($checksumBad['workRoot'], $jobBad);
$err = restore_approval_try(static function () use ($checksumBad, $projectRoot): void {
    orange_restore_orchestrator_approve_for_merge($checksumBad['pdo'], [
        'project_root' => $projectRoot,
        'work_root' => $checksumBad['workRoot'],
        'job_id' => $checksumBad['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'RESTORE',
        'env_override' => ['ORANGE_BACKUP_ROOT' => $checksumBad['backupRoot']],
    ]);
});
restore_approval_self_test($err !== null, 'gate: checksum mismatch rejected');
restore_approval_rmdir($checksumBad['backupRoot']);

// --- Missing rollback anchor ---
$noAnchor = restore_approval_seed_job(ORANGE_RESTORE_JOB_TYPE_FULL, '', true, false);
$err = restore_approval_try(static function () use ($noAnchor, $projectRoot): void {
    orange_restore_orchestrator_approve_for_merge($noAnchor['pdo'], [
        'project_root' => $projectRoot,
        'work_root' => $noAnchor['workRoot'],
        'job_id' => $noAnchor['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'RESTORE',
        'env_override' => ['ORANGE_BACKUP_ROOT' => $noAnchor['backupRoot']],
    ]);
});
restore_approval_self_test($err !== null, 'gate: missing rollback anchor rejected');
restore_approval_rmdir($noAnchor['backupRoot']);

// --- Failed staging validation ---
$stagingFail = restore_approval_seed_job(ORANGE_RESTORE_JOB_TYPE_FULL, '', false);
$err = restore_approval_try(static function () use ($stagingFail, $projectRoot): void {
    orange_restore_orchestrator_approve_for_merge($stagingFail['pdo'], [
        'project_root' => $projectRoot,
        'work_root' => $stagingFail['workRoot'],
        'job_id' => $stagingFail['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'RESTORE',
        'env_override' => ['ORANGE_BACKUP_ROOT' => $stagingFail['backupRoot']],
    ]);
});
restore_approval_self_test($err !== null, 'gate: failed staging validation rejected');
restore_approval_rmdir($stagingFail['backupRoot']);

// --- Expired approval window ---
$expiredWindow = restore_approval_seed_job(ORANGE_RESTORE_JOB_TYPE_FULL);
$expJob = orange_restore_job_read($expiredWindow['workRoot'], $expiredWindow['jobId']);
$expJob['owner_approval_window_started_at'] = gmdate('c', time() - ORANGE_RESTORE_APPROVAL_WINDOW_SECONDS - 60);
orange_restore_job_write($expiredWindow['workRoot'], $expJob);
$err = restore_approval_try(static function () use ($expiredWindow, $projectRoot): void {
    orange_restore_orchestrator_approve_for_merge($expiredWindow['pdo'], [
        'project_root' => $projectRoot,
        'work_root' => $expiredWindow['workRoot'],
        'job_id' => $expiredWindow['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'RESTORE',
        'env_override' => ['ORANGE_BACKUP_ROOT' => $expiredWindow['backupRoot']],
    ]);
});
restore_approval_self_test($err !== null, 'gate: expired approval window rejected');
restore_approval_rmdir($expiredWindow['backupRoot']);

// --- Token invalidation after job mutation ---
$mutated = restore_approval_seed_job(ORANGE_RESTORE_JOB_TYPE_FULL);
$issued = orange_restore_approval_issue_token(['job_id' => $mutated['jobId'], 'operator_id' => 1]);
$mutJob = orange_restore_job_read($mutated['workRoot'], $mutated['jobId']);
$mutJob = orange_restore_approval_store_token_on_job($mutJob, $issued['hash'], $issued['binding']);
orange_restore_job_write($mutated['workRoot'], $mutJob);
$mutJob['source_package_checksum'] = str_repeat('1', 64);
orange_restore_job_write($mutated['workRoot'], $mutJob);
$mutJob = orange_restore_approval_invalidate_token($mutJob, 'job_mutated');
$invalidated = orange_restore_approval_verify_token($mutJob, $issued['plaintext'], false);
restore_approval_self_test($invalidated['ok'] === false, 'token: invalidated after job mutation');
restore_approval_rmdir($mutated['backupRoot']);

// --- Rejection ---
$rejectSeed = restore_approval_seed_job(ORANGE_RESTORE_JOB_TYPE_FULL);
$rejectResult = orange_restore_orchestrator_reject($rejectSeed['pdo'], [
    'project_root' => $projectRoot,
    'work_root' => $rejectSeed['workRoot'],
    'job_id' => $rejectSeed['jobId'],
    'admin_id' => 1,
    'password' => 'correct-pass',
    'reason' => 'Owner declined merge',
]);
restore_approval_self_test(
    ($rejectResult['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_CANCELLED,
    'reject: transitions to cancelled'
);
$rejectJob = orange_restore_job_read($rejectSeed['workRoot'], $rejectSeed['jobId']);
restore_approval_self_test(($rejectJob['rejected_by'] ?? '') === 'superadmin', 'reject: rejected_by recorded');
restore_approval_rmdir($rejectSeed['backupRoot']);

// --- Cancellation ---
$cancelSeed = restore_approval_seed_job(ORANGE_RESTORE_JOB_TYPE_FULL);
$cancelResult = orange_restore_orchestrator_cancel($cancelSeed['pdo'], [
    'project_root' => $projectRoot,
    'work_root' => $cancelSeed['workRoot'],
    'job_id' => $cancelSeed['jobId'],
    'admin_id' => 1,
    'password' => 'correct-pass',
    'reason' => 'Operator cancelled before merge',
]);
restore_approval_self_test(
    ($cancelResult['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_CANCELLED,
    'cancel: transitions to cancelled'
);
restore_approval_rmdir($cancelSeed['backupRoot']);

// --- Audit append-only ---
$auditSeed = restore_approval_seed_job(ORANGE_RESTORE_JOB_TYPE_FULL);
orange_restore_orchestrator_approve_for_merge($auditSeed['pdo'], [
    'project_root' => $projectRoot,
    'work_root' => $auditSeed['workRoot'],
    'job_id' => $auditSeed['jobId'],
    'admin_id' => 1,
    'password' => 'correct-pass',
    'confirmation_phrase' => 'RESTORE',
    'env_override' => ['ORANGE_BACKUP_ROOT' => $auditSeed['backupRoot']],
]);
$auditRows = orange_restore_audit_read_all($auditSeed['workRoot'], $auditSeed['jobId']);
$hasReauth = false;
$hasPhrase = false;
$hasIssued = false;
$hasConsumed = false;
foreach ($auditRows as $row) {
    if (($row['approval_event'] ?? '') === 'reauth') {
        $hasReauth = true;
    }
    if (($row['approval_event'] ?? '') === 'confirmation_phrase') {
        $hasPhrase = true;
    }
    if (($row['approval_event'] ?? '') === 'approval_token_issued') {
        $hasIssued = true;
    }
    if (($row['approval_event'] ?? '') === 'approval_token_consumed') {
        $hasConsumed = true;
    }
}
restore_approval_self_test($hasReauth && $hasPhrase && $hasIssued && $hasConsumed, 'audit: approval events recorded');
restore_approval_rmdir($auditSeed['backupRoot']);

exit($failures > 0 ? 1 : 0);
