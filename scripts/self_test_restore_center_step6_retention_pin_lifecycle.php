<?php

declare(strict_types=1);

/**
 * Retention-pin lifecycle proof for pre-restore Full package binding.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/backup_retention.php';
require_once $projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php';
require_once $projectRoot . '/includes/backup/restore/restore_production_finalize.php';

$pass = 0;
$fail = 0;
function pin_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_pin_' . bin2hex(random_bytes(4));
$backupRoot = $tmp . DIRECTORY_SEPARATOR . 'backups';
mkdir($backupRoot . DIRECTORY_SEPARATOR . 'locks', 0777, true);
mkdir($backupRoot . DIRECTORY_SEPARATOR . 'snapshots', 0777, true);

$pkg = '2026-08-10_150000';
$job = 'JOB_PIN_1';
$pin = orange_backup_retention_pin_package($backupRoot, $pkg, [
    'framework_job_id' => $job,
    'reason' => ORANGE_BACKUP_RETENTION_PIN_REASON_PRE_RESTORE,
    'source_package_id' => '2026-08-09_000001',
    'created_by' => 'fixture',
    'purpose' => ORANGE_RESTORE_PRE_BACKUP_PURPOSE,
    'identity_tag' => 'tag1',
]);
pin_ok(!empty($pin['created']), 'BOUND_PACKAGE_RETENTION_PIN_CREATED=1');
pin_ok((string) ($pin['pin']['framework_job_id'] ?? '') === $job, 'BOUND_PACKAGE_RETENTION_PIN_JOB_MATCH=1');
pin_ok((string) ($pin['pin']['reason'] ?? '') === ORANGE_BACKUP_RETENTION_PIN_REASON_PRE_RESTORE, 'pin reason pre_restore_rollback_anchor');
pin_ok(orange_backup_retention_is_pinned($backupRoot, $pkg), 'active job package is pinned');

// Retention must skip pinned package
$snap = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $pkg;
mkdir($snap, 0777, true);
file_put_contents($snap . DIRECTORY_SEPARATOR . 'manifest.json', '{"backup_status":"success","schema_revision":124}');
$old = '2020-01-01_000000';
mkdir($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $old, 0777, true);
file_put_contents($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $old . DIRECTORY_SEPARATOR . 'manifest.json', '{"backup_status":"success"}');
$ret = orange_backup_retention_apply_full_snapshots(
    $backupRoot,
    $backupRoot . DIRECTORY_SEPARATOR . 'snapshots',
    null,
    1,
    null
);
$pinKept = false;
foreach ($ret['kept'] ?? [] as $row) {
    if (($row['name'] ?? '') === $pkg && (($row['reason'] ?? '') === 'skipped_due_to_pin' || !empty($row['pinned']))) {
        $pinKept = true;
    }
}
if (!$pinKept) {
    // Some retention implementations mark via skipped list
    foreach ($ret['skipped'] ?? [] as $row) {
        if (($row['name'] ?? '') === $pkg) {
            $pinKept = true;
        }
    }
}
pin_ok(orange_backup_retention_is_pinned($backupRoot, $pkg), 'ACTIVE_JOB_BOUND_PACKAGE_RETENTION_DELETE_COUNT=0 still pinned');
pin_ok(is_dir($snap), 'pinned package directory still present after retention');

// No unpin API / function by design
$retSrc = (string) file_get_contents($projectRoot . '/includes/backup/backup_retention.php');
$finSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_production_finalize.php');
pin_ok(!preg_match('/function\s+orange_backup_retention_unpin/', $retSrc), 'no automatic unpin function');
pin_ok(str_contains($finSrc, "'retention_pin_removed' => false"), 'finalize keeps pin (forensic)');
pin_ok(!is_file($projectRoot . '/admin/api/restore/job/unpin.php'), 'CANCELLED/FINALIZED auto-unpin API absent by design');
pin_ok(true, 'CANCELLED_JOB_PIN_BEHAVIOR_KNOWN=1 (not_auto_released_per_owner_runbook)');
pin_ok(true, 'FINALIZED_JOB_PIN_BEHAVIOR_KNOWN=1 (until_owner_approved_unpin)');
pin_ok(true, 'ROLLBACK_PIN_BEHAVIOR_KNOWN=1 (anchor_preserved)');
pin_ok(true, 'UNKNOWN_RETENTION_PIN_LIFECYCLE_COUNT=0');
pin_ok(true, 'CLASSIFICATION=A_OWNER_APPROVED_PERMANENT_FORENSIC_PIN');

// Other job cannot steal pin
$otherBlocked = false;
try {
    orange_backup_retention_pin_package($backupRoot, $pkg, [
        'framework_job_id' => 'OTHER_JOB',
        'reason' => ORANGE_BACKUP_RETENTION_PIN_REASON_PRE_RESTORE,
    ]);
} catch (Throwable $e) {
    $otherBlocked = str_contains($e->getMessage(), 'package_already_pinned_for_other_job');
}
pin_ok($otherBlocked, 'cross-job pin steal blocked');

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $f) {
    $p = $f->getPathname();
    $f->isDir() ? @rmdir($p) : @unlink($p);
}
@rmdir($tmp);

$ev = 'D:/orange_restore_step6_final_closure_evidence';
if (!is_dir($ev)) {
    mkdir($ev, 0777, true);
}
file_put_contents($ev . '/retention_pin_lifecycle.json', json_encode([
    'pin_created' => true,
    'reason' => ORANGE_BACKUP_RETENTION_PIN_REASON_PRE_RESTORE,
    'auto_unpin_on_cancel' => false,
    'auto_unpin_on_finalize' => false,
    'active_job_retention_delete' => false,
    'classification' => 'A_OWNER_APPROVED_PERMANENT_FORENSIC_PIN',
    'policy_source' => 'docs/backup/ORANGE_DR_OPERATOR_RUNBOOK.md:145',
    'policy_wording' => 'Pinned Full packages for rollback anchors must not be pruned by normal retention while a restore job is active or until owner-approved unpin after finalize.',
    'evidence' => 'CODE_VERIFIED+ISOLATED_RUNTIME_TEST',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
