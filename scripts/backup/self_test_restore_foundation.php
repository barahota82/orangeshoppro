<?php

declare(strict_types=1);

/**
 * Phase 2A Restore foundation self-tests (no restore execution, no DB audit table).
 *
 * Usage:
 *   php scripts/backup/self_test_restore_foundation.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_paths.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_job.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_lock.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_audit.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_approval.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_reauth.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin_permissions.php';

$failures = 0;

function restore_foundation_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function restore_foundation_temp_root(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_2a_' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

function restore_foundation_rmdir(string $dir): void
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
            restore_foundation_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$backupRoot = restore_foundation_temp_root();
$workRoot = $backupRoot . DIRECTORY_SEPARATOR . 'restore_work';
mkdir($workRoot);

restore_foundation_test(
    orange_restore_global_lock_path($workRoot) === $workRoot . DIRECTORY_SEPARATOR . '.restore.lock',
    'paths: global lock path'
);

$job = orange_restore_job_create($workRoot, [
    'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
    'operator_admin_id' => 1,
    'operator_username' => 'superadmin',
    'source_package_path' => $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'demo',
    'source_package_checksum' => str_repeat('a', 64),
    'package_version' => '1.2',
    'schema_revision' => 121,
    'approval_phrase_expected' => orange_restore_confirmation_phrase(ORANGE_RESTORE_JOB_TYPE_FULL),
    'reauth_verified_at' => orange_restore_reauth_timestamp(),
]);
restore_foundation_test(($job['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_CREATED, 'job: created');
restore_foundation_test(($job['rollback_anchor_job_only'] ?? false) === true, 'job: rollback anchor flag defaults true');

$jobId = (string) ($job['job_id'] ?? '');
$lock = orange_restore_acquire_lock($workRoot, $jobId);
restore_foundation_test($lock['ok'] === true, 'lock: acquire');
$lockAgain = orange_restore_acquire_lock($workRoot, 'other_job');
restore_foundation_test($lockAgain['ok'] === false, 'lock: second acquire blocked');
orange_restore_release_lock($workRoot);
restore_foundation_test(orange_restore_lock_status($workRoot)['held'] === false, 'lock: release');

orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_from_job($job, 'foundation_test', 'pass'));
$auditRows = orange_restore_audit_read_all($workRoot, $jobId);
restore_foundation_test(count($auditRows) === 1, 'audit: append one record');

$updated = orange_restore_job_record_fresh_backup_anchor(
    $workRoot,
    $jobId,
    $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'fresh_anchor',
    str_repeat('b', 64)
);
restore_foundation_test(($updated['fresh_backup_checksum'] ?? '') === str_repeat('b', 64), 'job: fresh backup anchor recorded');
restore_foundation_test(($updated['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_FRESH_BACKUP, 'job: fresh backup status');

restore_foundation_test(
    orange_restore_validate_confirmation_phrase(ORANGE_RESTORE_JOB_TYPE_FULL, 'RESTORE') === true,
    'approval: full phrase accepted'
);
restore_foundation_test(
    orange_restore_validate_confirmation_phrase(ORANGE_RESTORE_JOB_TYPE_FULL, 'restore') === false,
    'approval: full phrase case-sensitive'
);
restore_foundation_test(
    orange_restore_validate_confirmation_phrase(ORANGE_RESTORE_JOB_TYPE_COUNTRY, 'RESTORE KW', 'kw') === true,
    'approval: country phrase accepted'
);
restore_foundation_test(
    orange_restore_validate_confirmation_phrase(ORANGE_RESTORE_JOB_TYPE_COUNTRY, 'RESTORE', 'kw') === false,
    'approval: country phrase rejects full-only'
);

$token = orange_restore_generate_approval_token($jobId, str_repeat('a', 64));
restore_foundation_test(orange_restore_validate_approval_token($token, $token) === true, 'approval: token match');
restore_foundation_test(orange_restore_validate_approval_token('wrong', $token) === false, 'approval: token mismatch');

restore_foundation_test(
    in_array('backup_restore_full', orange_admin_restore_permission_keys(), true),
    'permissions: backup_restore_full defined'
);
restore_foundation_test(
    in_array('backup_restore_country', orange_admin_restore_permission_keys(), true),
    'permissions: backup_restore_country defined'
);
$legacyGroupKeys = array_values(array_diff(array_keys(orange_admin_resource_labels()), orange_admin_restore_permission_keys()));
restore_foundation_test(
    !in_array('backup_restore_full', $legacyGroupKeys, true)
        && !in_array('backup_restore_country', $legacyGroupKeys, true),
    'permissions: restore keys excluded from legacy group migration'
);

$fakeSuper = ['id' => 1, 'is_superuser' => 1, 'username' => 'super'];
restore_foundation_test(
    orange_admin_is_superuser($fakeSuper) === true,
    'permissions: superuser detect'
);
try {
    orange_restore_assert_superuser_operator(['id' => 2, 'is_superuser' => 0]);
    restore_foundation_test(false, 'reauth: non-superuser rejected');
} catch (Throwable $e) {
    restore_foundation_test(true, 'reauth: non-superuser rejected');
}

restore_foundation_rmdir($backupRoot);

exit($failures > 0 ? 1 : 0);
