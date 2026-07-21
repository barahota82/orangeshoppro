<?php

declare(strict_types=1);

/**
 * Self-test: CPR lock engine (WP-P3-05).
 * Run: php scripts/backup/country_production/self_test_cpr_locks.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_lock_engine.php';

$pass = 0;
$fail = 0;

function cpr_lk_t(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        ++$pass;
        echo "PASS  {$name}\n";
    } else {
        ++$fail;
        echo "FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p305_' . bin2hex(random_bytes(4));
$restoreWork = $base . DIRECTORY_SEPARATOR . 'restore_work';
$cpr = $restoreWork . DIRECTORY_SEPARATOR . 'country_production';
$shadow = $restoreWork . DIRECTORY_SEPARATOR . 'country_shadow';
$backupRoot = $base . DIRECTORY_SEPARATOR . 'backup_root';
$backupLocks = $backupRoot . DIRECTORY_SEPARATOR . 'locks';
@mkdir($cpr, 0775, true);
@mkdir($shadow, 0775, true);
@mkdir($backupLocks, 0775, true);

$env = [
    'ORANGE_CPR_WORK_DIR' => $cpr,
    'ORANGE_RESTORE_WORK_DIR' => $restoreWork,
    'ORANGE_BACKUP_ROOT' => $backupRoot,
    'ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false,
];

try {
    $job = orange_cpr_job_create($env, [
        'package_id' => 'pkg-lock',
        'package_fingerprint' => str_repeat('a', 32),
        'country_id' => 1,
        'country_code' => 'KW',
        'workflow' => 'A',
    ], 1);
    $jid = (string) $job['job_id'];

    $acq = orange_cpr_lock_acquire($env, $jid, [
        'worker_id' => 'worker-1',
        'owner_class' => 'system_worker',
    ]);
    cpr_lk_t('lock_acquire', !empty($acq['ok']), (string) ($acq['message'] ?? ''));
    $lease = (string) ($acq['lease_token'] ?? '');

    $hb = orange_cpr_lock_heartbeat($env, $jid, ['lease_token' => $lease, 'worker_id' => 'worker-1']);
    cpr_lk_t('heartbeat_update', !empty($hb['ok']));

    $job2 = orange_cpr_job_create($env, [
        'package_id' => 'pkg-lock-2',
        'package_fingerprint' => str_repeat('b', 32),
        'country_id' => 2,
        'country_code' => 'SA',
        'workflow' => 'A',
    ], 2);
    $jid2 = (string) $job2['job_id'];
    $conflict = orange_cpr_lock_acquire($env, $jid2, ['worker_id' => 'worker-2']);
    cpr_lk_t('concurrent_cpr_denied', empty($conflict['ok'])
        && ($conflict['code'] ?? '') === ORANGE_CPR_LOCK_ERR_HELD);

    // Peer exclusion while CPR held
    $peerFd = orange_cpr_exclusion_check_for_peer($env, 'full_dr');
    cpr_lk_t('cpr_blocks_full_dr_peer', empty($peerFd['ok'])
        && ($peerFd['code'] ?? '') === ORANGE_CPR_LOCK_ERR_CPR_ACTIVE);
    $peerC6 = orange_cpr_exclusion_check_for_peer($env, 'c6');
    cpr_lk_t('cpr_blocks_c6_peer', empty($peerC6['ok']));
    $peerBk = orange_cpr_exclusion_check_for_peer($env, 'backup_runner');
    cpr_lk_t('cpr_blocks_backup_peer', empty($peerBk['ok']));

    // Release then test CPR vs Full DR
    $rel = orange_cpr_lock_release($env, $jid, ['lease_token' => $lease]);
    cpr_lk_t('lock_release', !empty($rel['ok']));

    file_put_contents($restoreWork . DIRECTORY_SEPARATOR . '.restore.lock', json_encode([
        'pid' => 1,
        'hostname' => 't',
        'job_id' => 'fdr-1',
        'started_at' => gmdate('c'),
    ]));
    $vsFdr = orange_cpr_lock_acquire($env, $jid, ['worker_id' => 'w']);
    cpr_lk_t('cpr_vs_full_dr', empty($vsFdr['ok'])
        && ($vsFdr['code'] ?? '') === ORANGE_CPR_LOCK_ERR_FULL_DR);
    @unlink($restoreWork . DIRECTORY_SEPARATOR . '.restore.lock');

    file_put_contents($shadow . DIRECTORY_SEPARATOR . '.country_shadow_restore.lock', json_encode([
        'run_id' => 'c6-1',
        'pid' => 1,
        'acquired_at' => gmdate('c'),
    ]));
    $vsC6 = orange_cpr_lock_acquire($env, $jid, ['worker_id' => 'w']);
    cpr_lk_t('cpr_vs_c6', empty($vsC6['ok'])
        && ($vsC6['code'] ?? '') === ORANGE_CPR_LOCK_ERR_C6);
    @unlink($shadow . DIRECTORY_SEPARATOR . '.country_shadow_restore.lock');

    file_put_contents($backupLocks . DIRECTORY_SEPARATOR . 'orange_full_backup.lock', json_encode([
        'pid' => 1,
        'started_at' => gmdate('c'),
        'hostname' => 't',
        'sapi' => 'cli',
    ]));
    $vsBk = orange_cpr_lock_acquire($env, $jid, ['worker_id' => 'w']);
    cpr_lk_t('cpr_vs_backup_runner', empty($vsBk['ok'])
        && ($vsBk['code'] ?? '') === ORANGE_CPR_LOCK_ERR_BACKUP);
    @unlink($backupLocks . DIRECTORY_SEPARATOR . 'orange_full_backup.lock');

    // Re-acquire for stale / post-PONR tests
    $acq2 = orange_cpr_lock_acquire($env, $jid, ['worker_id' => 'worker-1']);
    $lease2 = (string) ($acq2['lease_token'] ?? '');
    cpr_lk_t('reacquire', !empty($acq2['ok']));

    // Force stale heartbeat in lock file
    $lockPath = orange_cpr_lock_file_path($cpr);
    $lockDoc = json_decode((string) file_get_contents($lockPath), true);
    $lockDoc['heartbeat_at'] = gmdate('c', time() - 300);
    $lockDoc['pid'] = 999999001; // likely dead
    file_put_contents($lockPath, json_encode($lockDoc, JSON_PRETTY_PRINT));
    $stale = orange_cpr_lock_stale_classify($lockDoc);
    cpr_lk_t('stale_pre_ponr_detect', !empty($stale['is_heartbeat_stale']));

    $auto = orange_cpr_lock_auto_unlock_attempt($env);
    cpr_lk_t('stale_no_auto_unlock', empty($auto['ok']));

    $clearCa = orange_cpr_lock_manual_clear_pre_ponr($env, $jid, [
        'actor' => ORANGE_CPR_ACTOR_COUNTRY_ADMIN,
        'actor_admin_id' => 9,
        'reason' => 'country-admin-try',
    ]);
    cpr_lk_t('clear_blocked_country_admin', empty($clearCa['ok']));

    $clear = orange_cpr_lock_manual_clear_pre_ponr($env, $jid, [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'actor_admin_id' => 1,
        'reason' => 'stale-pre-ponr-clear',
    ]);
    cpr_lk_t('stale_pre_ponr_manual_clear', !empty($clear['ok']) && is_file((string) ($clear['audit_path'] ?? '')),
        (string) ($clear['message'] ?? ''));

    // Post-PONR protection
    $acq3 = orange_cpr_lock_acquire($env, $jid, ['worker_id' => 'worker-1']);
    $lease3 = (string) ($acq3['lease_token'] ?? '');
    $marked = orange_cpr_lock_mark_ponr($env, $jid, ['lease_token' => $lease3, 'worker_id' => 'worker-1']);
    cpr_lk_t('mark_ponr', !empty($marked['ok']) && !empty($marked['lock']['ponr_crossed']));

    $postAuto = orange_cpr_lock_auto_unlock_attempt($env);
    cpr_lk_t('post_ponr_auto_forbidden', empty($postAuto['ok'])
        && ($postAuto['code'] ?? '') === ORANGE_CPR_LOCK_ERR_POST_PONR);

    $postClear = orange_cpr_lock_manual_clear_pre_ponr($env, $jid, [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'actor_admin_id' => 1,
        'reason' => 'should-not-clear-post',
    ]);
    cpr_lk_t('post_ponr_manual_clear_forbidden', empty($postClear['ok'])
        && ($postClear['code'] ?? '') === ORANGE_CPR_LOCK_ERR_POST_PONR_CLEAR);

    $relNoClose = orange_cpr_lock_release($env, $jid, ['lease_token' => $lease3]);
    cpr_lk_t('post_ponr_release_needs_closeout', empty($relNoClose['ok'])
        && ($relNoClose['code'] ?? '') === ORANGE_CPR_LOCK_ERR_CLOSEOUT);

    $relOk = orange_cpr_lock_release($env, $jid, [
        'lease_token' => $lease3,
        'authorized_closeout' => true,
    ]);
    cpr_lk_t('post_ponr_authorized_closeout_release', !empty($relOk['ok']));

    $bypass = orange_cpr_concurrency_validate($env, $jid, ['force' => true]);
    cpr_lk_t('bypass_forbidden', empty($bypass['ok'])
        && ($bypass['code'] ?? '') === ORANGE_CPR_LOCK_ERR_BYPASS);

    // Conflict: Full DR + framework lock family
    file_put_contents($restoreWork . DIRECTORY_SEPARATOR . '.restore_framework.lock', '{}');
    $fw = orange_cpr_concurrency_validate($env, $jid);
    cpr_lk_t('full_dr_family_framework', empty($fw['ok'])
        && ($fw['code'] ?? '') === ORANGE_CPR_LOCK_ERR_FULL_DR);
    @unlink($restoreWork . DIRECTORY_SEPARATOR . '.restore_framework.lock');

    // Lock conflict message shape
    $acq4 = orange_cpr_lock_acquire($env, $jid, ['worker_id' => 'w4']);
    $lease4 = (string) ($acq4['lease_token'] ?? '');
    $lockConflict = orange_cpr_concurrency_validate($env, $jid2);
    cpr_lk_t('lock_conflict_validate', empty($lockConflict['ok']));
    orange_cpr_lock_release($env, $jid, ['lease_token' => $lease4]);

    // Audit acquire events
    $audit = (string) file_get_contents(orange_cpr_audit_file_path($cpr, $jid));
    cpr_lk_t('audit_lock_events', str_contains($audit, 'cpr.lock_acquire')
        && str_contains($audit, 'cpr.lock_heartbeat'));
} catch (Throwable $e) {
    cpr_lk_t('suite_exception', false, $e->getMessage());
}

if (is_dir($base)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($base);
}

echo "\n{$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
