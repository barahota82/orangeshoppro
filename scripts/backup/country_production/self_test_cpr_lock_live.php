<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Pre-PONR Lock (WP-P4-05).
 * Run: php scripts/backup/country_production/self_test_cpr_lock_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_lock_live.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_state_engine.php';

$pass = 0;
$fail = 0;

function cpr_ll(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        ++$pass;
        echo "PASS  {$name}\n";
    } else {
        ++$fail;
        echo "FAIL  {$name}" . ($detail !== '' ? " â€” {$detail}" : '') . "\n";
    }
}

/**
 * @return array{env:array<string,mixed>,cpr:string,base:string,restore:string,shadow:string,backup_locks:string}
 */
function cpr_ll_env(): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p405_' . bin2hex(random_bytes(4));
    $restoreWork = $base . DIRECTORY_SEPARATOR . 'restore_work';
    $cpr = $restoreWork . DIRECTORY_SEPARATOR . 'country_production';
    $shadow = $restoreWork . DIRECTORY_SEPARATOR . 'country_shadow';
    $backupRoot = $base . DIRECTORY_SEPARATOR . 'backup_root';
    $backupLocks = $backupRoot . DIRECTORY_SEPARATOR . 'locks';
    @mkdir($cpr, 0775, true);
    @mkdir($shadow, 0775, true);
    @mkdir($backupLocks, 0775, true);

    return [
        'base' => $base,
        'cpr' => $cpr,
        'restore' => $restoreWork,
        'shadow' => $shadow,
        'backup_locks' => $backupLocks,
        'env' => [
            'ORANGE_CPR_WORK_DIR' => $cpr,
            'ORANGE_RESTORE_WORK_DIR' => $restoreWork,
            'ORANGE_BACKUP_ROOT' => $backupRoot,
            'ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false,
        ],
    ];
}

function cpr_ll_cleanup(string $base): void
{
    if (!is_dir($base)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($base);
}

/**
 * @return array<string, mixed>
 */
function cpr_ll_fps(string $pkgFp): array
{
    return [
        'schema_revision_expected' => 121,
        'boundary_policy_version' => 'C1.1',
        'dependency_graph_version' => '1',
        'registry_revision' => 121,
        'c4_report_hash' => str_repeat('b', 32),
        'c5_report_hash' => str_repeat('c', 32),
        'c6_report_hash' => str_repeat('d', 32),
        'c7_report_hash' => str_repeat('e', 32),
        'c8_report_hash' => str_repeat('f', 32),
        'c8_overall_result' => 'SAFE',
        'inventory_snapshot_id' => 'inv-1',
        'inventory_snapshot_hash' => str_repeat('1', 32),
        'production_db_identity_hash' => str_repeat('2', 32),
        'package_fingerprint' => $pkgFp,
    ];
}

/**
 * Job through CP4 + OD-PIN â†’ cpr_pre_ponr (ready for live lock).
 *
 * @param array<string, mixed> $env
 * @return array{job_id:string}
 */
function cpr_ll_setup_pinned(array $env, int $countryId = 1, string $code = 'KW'): array
{
    $fp = hash('sha256', 'fp-ll-' . $countryId . '-' . bin2hex(random_bytes(3)));
    $job = orange_cpr_job_create($env, [
        'package_id' => 'pkg-ll-' . $countryId . '-' . substr($fp, 0, 6),
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $code,
        'workflow' => 'A',
    ], 1);
    $jid = (string) $job['job_id'];

    orange_cpr_transition_apply($env, $jid, 'cpr_gates_validating', ['actor' => ORANGE_CPR_ACTOR_SYSTEM]);
    orange_cpr_checkpoint_create($env, $jid, 'CP0', [
        'c4_overall' => 'PASS',
        'c5_overall' => 'pass',
        'c5_recovery_score' => 90,
        'c6_status' => 'ready',
        'c7_overall' => 'READY',
        'c7_readiness_score' => 95,
        'c8_overall_result' => 'SAFE',
        'enablement_flag_observed' => false,
        'schema_revision_observed' => 121,
        'boundary_policy_version' => 'C1.1',
        'report_hashes' => [
            'c4' => str_repeat('b', 32),
            'c5' => str_repeat('c', 32),
            'c6' => str_repeat('d', 32),
            'c7' => str_repeat('e', 32),
            'c8' => str_repeat('f', 32),
        ],
    ], ['written_by' => 'system']);
    orange_cpr_transition_apply($env, $jid, 'cpr_contract_frozen', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'contract_fingerprints_ok' => true,
    ]);
    orange_cpr_checkpoint_create($env, $jid, 'CP2', [
        'workflow' => 'A',
        'wfa_protections_ack' => true,
        'approval_fingerprint' => str_repeat('9', 32),
    ], ['written_by' => 'system']);
    orange_cpr_contract_freeze_initial($env, $jid, cpr_ll_fps($fp), 1);
    orange_cpr_checkpoint_create($env, $jid, 'CP3', [
        'contract_revision' => 1,
        'contract_phase' => 'pre_pin',
        'package_fingerprint' => $fp,
        'fingerprint_digest' => hash('sha256', $fp),
    ], ['written_by' => 'system']);

    $maint = orange_cpr_maint_live_activate_cp4($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'maint_scope' => 'GLOBAL',
        'write_block_proof' => 'cpr_write_block_proof:orders',
    ]);
    if (empty($maint['ok'])) {
        throw new RuntimeException('maint setup failed: ' . (string) ($maint['code'] ?? ''));
    }
    $pin = orange_cpr_od_pin_live_run($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
    ]);
    if (empty($pin['ok'])) {
        throw new RuntimeException('od-pin setup failed: ' . (string) ($pin['code'] ?? ''));
    }

    return ['job_id' => $jid, 'package_fingerprint' => $fp];
}

/**
 * @return array<string, mixed>
 */
function cpr_ll_sa(array $extra = []): array
{
    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'owner_class' => 'super_admin_procedure',
        'worker_id' => 'll-worker-1',
    ], $extra);
}

$bundle = cpr_ll_env();
$env = $bundle['env'];
$base = $bundle['base'];
$cprRoot = $bundle['cpr'];
$restore = $bundle['restore'];
$shadow = $bundle['shadow'];
$backupLocks = $bundle['backup_locks'];

try {
    cpr_ll('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P6-05-maint-release');

    // Prepare both jobs before any CPR lock is held (maint/OD-PIN refuse peer lock conflict).
    $setup = cpr_ll_setup_pinned($env);
    $jid = $setup['job_id'];
    $setup2 = cpr_ll_setup_pinned($env, 2, 'SA');
    $jid2 = $setup2['job_id'];

    // --- Acquire + heartbeat ---
    $acq = orange_cpr_lock_live_acquire($env, $jid, cpr_ll_sa());
    cpr_ll('acquire_ok', !empty($acq['ok']), (string) ($acq['message'] ?? $acq['code'] ?? ''));
    $lease = (string) ($acq['lease_token'] ?? '');
    $worker = (string) ($acq['worker_id'] ?? 'll-worker-1');
    cpr_ll('acquire_sealed', is_file((string) ($acq['lifecycle_path'] ?? '')));
    cpr_ll('g26_after_acquire', ($acq['g26']['result'] ?? '') === ORANGE_CPR_GATE_PASS);

    $hb = orange_cpr_lock_live_heartbeat($env, $jid, [
        'lease_token' => $lease,
        'worker_id' => $worker,
    ]);
    cpr_ll('heartbeat_ok', !empty($hb['ok']), (string) ($hb['code'] ?? ''));
    cpr_ll('heartbeat_sealed', is_file((string) ($hb['lifecycle_path'] ?? '')));

    // Same-job ownership validation
    $rv = orange_cpr_lock_live_revalidate_ownership($env, $jid, [
        'lease_token' => $lease,
        'worker_id' => $worker,
    ]);
    cpr_ll('same_job_ownership_ok', !empty($rv['ok']) && !empty($rv['ownership_ok']), (string) ($rv['code'] ?? ''));

    // Ownership drift
    $drift = orange_cpr_lock_live_revalidate_ownership($env, $jid, [
        'lease_token' => 'wrong-lease-token-value',
        'worker_id' => $worker,
    ]);
    cpr_ll(
        'ownership_drift',
        empty($drift['ok']) && ($drift['code'] ?? '') === ORANGE_CPR_LOCKLIVE_ERR_OWNERSHIP,
        (string) ($drift['code'] ?? '')
    );

    // CPR conflict (second prepared job)
    $conflict = orange_cpr_lock_live_acquire($env, $jid2, cpr_ll_sa(['worker_id' => 'll-worker-2']));
    cpr_ll(
        'cpr_conflict',
        empty($conflict['ok']) && ($conflict['code'] ?? '') === ORANGE_CPR_LOCKLIVE_ERR_CONFLICT,
        (string) ($conflict['code'] ?? '')
    );

    // Release via P3 for peer tests (pre-PONR)
    orange_cpr_lock_release($env, $jid, ['lease_token' => $lease]);

    // Full DR conflict
    file_put_contents($restore . DIRECTORY_SEPARATOR . '.restore.lock', json_encode(['pid' => 1]));
    $fd = orange_cpr_lock_live_acquire($env, $jid, cpr_ll_sa(['worker_id' => 'w-fd']));
    cpr_ll(
        'full_dr_conflict',
        empty($fd['ok']) && ($fd['code'] ?? '') === ORANGE_CPR_LOCKLIVE_ERR_FULL_DR,
        (string) ($fd['code'] ?? '')
    );
    @unlink($restore . DIRECTORY_SEPARATOR . '.restore.lock');

    // C6 conflict
    file_put_contents($shadow . DIRECTORY_SEPARATOR . '.country_shadow_restore.lock', json_encode(['run_id' => 'c6']));
    $c6 = orange_cpr_lock_live_acquire($env, $jid, cpr_ll_sa(['worker_id' => 'w-c6']));
    cpr_ll(
        'c6_conflict',
        empty($c6['ok']) && ($c6['code'] ?? '') === ORANGE_CPR_LOCKLIVE_ERR_C6,
        (string) ($c6['code'] ?? '')
    );
    @unlink($shadow . DIRECTORY_SEPARATOR . '.country_shadow_restore.lock');

    // Backup Runner conflict
    file_put_contents($backupLocks . DIRECTORY_SEPARATOR . 'orange_full_backup.lock', json_encode(['pid' => 1]));
    $bk = orange_cpr_lock_live_acquire($env, $jid, cpr_ll_sa(['worker_id' => 'w-bk']));
    cpr_ll(
        'backup_runner_conflict',
        empty($bk['ok']) && ($bk['code'] ?? '') === ORANGE_CPR_LOCKLIVE_ERR_BACKUP,
        (string) ($bk['code'] ?? '')
    );
    @unlink($backupLocks . DIRECTORY_SEPARATOR . 'orange_full_backup.lock');

    // Re-acquire for stale / clear / corrupt / post-PONR
    $acq2 = orange_cpr_lock_live_acquire($env, $jid, cpr_ll_sa(['worker_id' => 'll-worker-3']));
    cpr_ll('reacquire_ok', !empty($acq2['ok']), (string) ($acq2['code'] ?? ''));
    $lease2 = (string) ($acq2['lease_token'] ?? '');
    $worker2 = (string) ($acq2['worker_id'] ?? 'll-worker-3');

    // Stale pre-PONR detection
    $lockPath = orange_cpr_lock_file_path($cprRoot);
    $lockDoc = json_decode((string) file_get_contents($lockPath), true);
    $lockDoc['heartbeat_at'] = gmdate('c', time() - 300);
    $lockDoc['pid'] = 999999001;
    file_put_contents($lockPath, json_encode($lockDoc, JSON_PRETTY_PRINT));
    $stale = orange_cpr_lock_live_detect_stale($env, $jid);
    cpr_ll('stale_pre_ponr_detect', !empty($stale['ok']) && !empty($stale['is_stale']));
    $auto = orange_cpr_lock_auto_unlock_attempt($env);
    cpr_ll('stale_no_auto_unlock', empty($auto['ok']));
    $refuseAuto = orange_cpr_lock_live_refuse_auto_unlock();
    cpr_ll('refuse_auto_helper', empty($refuseAuto['ok']) && ($refuseAuto['code'] ?? '') === ORANGE_CPR_LOCKLIVE_ERR_AUTO);

    // Country Admin denial
    $caClear = orange_cpr_lock_live_manual_clear($env, $jid, [
        'actor_admin_id' => 99,
        'actor_is_super_admin' => false,
        'country_admin_is_executor' => true,
        'reason' => 'country-admin-should-fail',
    ]);
    cpr_ll(
        'country_admin_clear_denied',
        empty($caClear['ok']) && ($caClear['code'] ?? '') === ORANGE_CPR_LOCKLIVE_ERR_ACTOR,
        (string) ($caClear['code'] ?? '')
    );

    // Missing audit reason
    $noReason = orange_cpr_lock_live_manual_clear($env, $jid, cpr_ll_sa([
        'reason' => 'short',
    ]));
    cpr_ll(
        'missing_audit_reason',
        empty($noReason['ok']) && ($noReason['code'] ?? '') === ORANGE_CPR_LOCKLIVE_ERR_REASON,
        (string) ($noReason['code'] ?? '')
    );

    // Super Admin manual clear
    $clear = orange_cpr_lock_live_manual_clear($env, $jid, cpr_ll_sa([
        'reason' => 'stale-pre-ponr-manual-clear-ok',
        'worker_id' => $worker2,
    ]));
    cpr_ll('super_admin_manual_clear', !empty($clear['ok']) && !empty($clear['cleared']), (string) ($clear['code'] ?? ''));
    cpr_ll('manual_clear_sealed', is_file((string) ($clear['sealed_audit_path'] ?? '')));

    // Corrupt lock
    $acq3 = orange_cpr_lock_live_acquire($env, $jid, cpr_ll_sa(['worker_id' => 'll-worker-4']));
    cpr_ll('acquire_for_corrupt', !empty($acq3['ok']), (string) ($acq3['code'] ?? ''));
    $lease3 = (string) ($acq3['lease_token'] ?? '');
    file_put_contents($lockPath, '{not-valid-json');
    $corrupt = orange_cpr_lock_live_revalidate_ownership($env, $jid, ['lease_token' => $lease3]);
    cpr_ll(
        'corrupt_lock',
        empty($corrupt['ok']) && ($corrupt['code'] ?? '') === ORANGE_CPR_LOCKLIVE_ERR_CORRUPT,
        (string) ($corrupt['code'] ?? '')
    );
    @unlink($lockPath);

    // Post-PONR clear denial
    $acq4 = orange_cpr_lock_live_acquire($env, $jid, cpr_ll_sa(['worker_id' => 'll-worker-5']));
    cpr_ll('acquire_for_post_ponr', !empty($acq4['ok']), (string) ($acq4['code'] ?? ''));
    $lease4 = (string) ($acq4['lease_token'] ?? '');
    $worker4 = (string) ($acq4['worker_id'] ?? 'll-worker-5');
    $marked = orange_cpr_lock_mark_ponr($env, $jid, [
        'lease_token' => $lease4,
        'worker_id' => $worker4,
    ]);
    cpr_ll('mark_ponr_scaffold', !empty($marked['ok']));
    // Age heartbeat so clear would otherwise be eligible if not post-PONR
    $lockDoc2 = json_decode((string) file_get_contents($lockPath), true);
    $lockDoc2['heartbeat_at'] = gmdate('c', time() - 300);
    $lockDoc2['pid'] = 999999002;
    $lockDoc2['ponr_crossed'] = true;
    file_put_contents($lockPath, json_encode($lockDoc2, JSON_PRETTY_PRINT));
    $postClear = orange_cpr_lock_live_manual_clear($env, $jid, cpr_ll_sa([
        'reason' => 'must-not-clear-post-ponr',
    ]));
    cpr_ll(
        'post_ponr_clear_denied',
        empty($postClear['ok']) && ($postClear['code'] ?? '') === ORANGE_CPR_LOCKLIVE_ERR_POST_PONR,
        (string) ($postClear['code'] ?? '')
    );

    // Cleanup post-PONR lock with authorized closeout (P3) so suite ends clean
    orange_cpr_lock_release($env, $jid, [
        'lease_token' => $lease4,
        'authorized_closeout' => true,
    ]);

    // Bypass forbidden
    $bypass = orange_cpr_lock_live_acquire($env, $jid, cpr_ll_sa([
        'force' => true,
        'worker_id' => 'w-bypass',
    ]));
    cpr_ll(
        'bypass_forbidden',
        empty($bypass['ok']) && ($bypass['code'] ?? '') === ORANGE_CPR_LOCKLIVE_ERR_BYPASS,
        (string) ($bypass['code'] ?? '')
    );

    // Audit events
    $audit = (string) file_get_contents(orange_cpr_audit_file_path($cprRoot, $jid));
    cpr_ll('audit_acquire', str_contains($audit, 'cpr.lock_live_acquire'));
    cpr_ll('audit_heartbeat', str_contains($audit, 'cpr.lock_live_heartbeat'));
    cpr_ll('audit_manual_clear', str_contains($audit, 'cpr.lock_live_manual_clear'));

    cpr_ll('enablement_false', ($acq['enablement_flag_observed'] ?? true) === false);
    cpr_ll('no_production_mutation', ($acq['production_mutation'] ?? true) === false);
} catch (Throwable $e) {
    cpr_ll('suite_exception', false, $e->getMessage());
} finally {
    cpr_ll_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
