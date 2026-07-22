<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Session Full Backup & OD-PIN (WP-P4-04 / CP1).
 * Run: php scripts/backup/country_production/self_test_cpr_od_pin_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_od_pin_live.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_state_engine.php';

$pass = 0;
$fail = 0;

function cpr_op(string $name, bool $ok, string $detail = ''): void
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
 * @return array{env:array<string,mixed>,cpr:string,base:string}
 */
function cpr_op_env(): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p404_' . bin2hex(random_bytes(4));
    $restoreWork = $base . DIRECTORY_SEPARATOR . 'restore_work';
    $cpr = $restoreWork . DIRECTORY_SEPARATOR . 'country_production';
    $backupRoot = $base . DIRECTORY_SEPARATOR . 'backup_root';
    @mkdir($cpr, 0775, true);
    @mkdir($backupRoot . DIRECTORY_SEPARATOR . 'locks', 0775, true);

    return [
        'base' => $base,
        'cpr' => $cpr,
        'env' => [
            'ORANGE_CPR_WORK_DIR' => $cpr,
            'ORANGE_RESTORE_WORK_DIR' => $restoreWork,
            'ORANGE_BACKUP_ROOT' => $backupRoot,
            'ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false,
        ],
    ];
}

function cpr_op_cleanup(string $base): void
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
function cpr_op_fps(string $pkgFp): array
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
 * Job with CP0â€“CP4 via live maint activate (ready for OD-PIN).
 *
 * @param array<string, mixed> $env
 * @return array{job_id:string}
 */
function cpr_op_setup_cp4(array $env): array
{
    $fp = hash('sha256', 'fp-op-' . bin2hex(random_bytes(4)));
    $job = orange_cpr_job_create($env, [
        'package_id' => 'pkg-op-' . substr($fp, 0, 8),
        'package_fingerprint' => $fp,
        'country_id' => 1,
        'country_code' => 'KW',
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
    orange_cpr_contract_freeze_initial($env, $jid, cpr_op_fps($fp), 1);
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
        'write_block_proof' => 'cpr_write_block_proof:storefront_order_create',
    ]);
    if (empty($maint['ok'])) {
        throw new RuntimeException('Maint/CP4 setup failed: ' . (string) ($maint['code'] ?? ''));
    }

    return ['job_id' => $jid, 'package_fingerprint' => $fp];
}

/**
 * @return array<string, mixed>
 */
function cpr_op_sa(array $extra = []): array
{
    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
    ], $extra);
}

$bundle = cpr_op_env();
$env = $bundle['env'];
$base = $bundle['base'];

try {
    cpr_op('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P6-06-integration-baseline');

    // --- Happy path ---
    $setup = cpr_op_setup_cp4($env);
    $jid = $setup['job_id'];
    $cprRoot = orange_cpr_resolve_work_root($env);

    $run = orange_cpr_od_pin_live_run($env, $jid, cpr_op_sa());
    cpr_op('od_pin_run_ok', !empty($run['ok']), (string) ($run['message'] ?? $run['code'] ?? ''));
    cpr_op('pinned_true', !empty($run['session_full_backup_pinned']) && !empty($run['pinned']));
    cpr_op('immutable_flag', !empty($run['immutable']));
    cpr_op('no_reuse', ($run['reused_existing_backup'] ?? true) === false);
    cpr_op('no_production_mutation', ($run['production_mutation'] ?? true) === false);
    cpr_op('no_ponr', ($run['ponr_crossed'] ?? true) === false);

    $job = orange_cpr_job_read($cprRoot, $jid);
    cpr_op('job_pre_ponr', ($job['state'] ?? '') === 'cpr_pre_ponr');
    cpr_op('cp4_before_cp1', orange_cpr_checkpoint_exists($cprRoot, $jid, 'CP4')
        && orange_cpr_checkpoint_exists($cprRoot, $jid, 'CP1'));

    $contract = orange_cpr_contract_read($cprRoot, $jid);
    cpr_op('contract_pin_bound', is_array($contract)
        && !empty($contract['session_full_backup_pinned'])
        && (string) ($contract['session_full_backup_id'] ?? '') === (string) ($run['session_full_backup_id'] ?? '')
        && (string) ($contract['contract_phase'] ?? '') === 'pre_ponr');

    $pin = orange_cpr_od_pin_live_load_pin($cprRoot, $jid);
    cpr_op('pin_sealed', is_array($pin) && orange_cpr_auth_verify_seal($pin) && !empty($pin['immutable']));

    $sessionEv = [
        'id' => (string) ($run['session_full_backup_id'] ?? ''),
        'fingerprint' => (string) ($run['session_full_backup_fingerprint'] ?? ''),
        'pinned' => true,
        'verified' => true,
        'reused_existing_backup' => false,
        'created_under_maintenance' => true,
    ];
    $g23 = orange_cpr_gate_evaluate_one('G23', orange_cpr_gate_build_context($env, $jid, [
        'session_full_backup' => $sessionEv,
    ]));
    cpr_op('gate_g23_pass', ($g23['result'] ?? '') === ORANGE_CPR_GATE_PASS, (string) ($g23['fail_code'] ?? ''));

    // Immutable re-pin
    $again = orange_cpr_od_pin_live_run($env, $jid, cpr_op_sa());
    cpr_op(
        'deny_second_pin',
        empty($again['ok']) && ($again['code'] ?? '') === ORANGE_CPR_ODPIN_ERR_IMMUTABLE,
        (string) ($again['code'] ?? '')
    );

    // --- Reuse forbidden ---
    $setup2 = cpr_op_setup_cp4($env);
    $reuse = orange_cpr_od_pin_live_run($env, $setup2['job_id'], cpr_op_sa([
        'reuse_existing_backup' => true,
    ]));
    cpr_op(
        'deny_reuse',
        empty($reuse['ok']) && ($reuse['code'] ?? '') === ORANGE_CPR_ODPIN_ERR_REUSE,
        (string) ($reuse['code'] ?? '')
    );
    $refuseReuse = orange_cpr_od_pin_live_refuse_reuse();
    cpr_op('refuse_reuse_helper', empty($refuseReuse['ok']) && ($refuseReuse['code'] ?? '') === ORANGE_CPR_ODPIN_ERR_REUSE);

    // --- Verify failure fail-closed (no pin) ---
    $setup3 = cpr_op_setup_cp4($env);
    $jid3 = $setup3['job_id'];
    $create3 = orange_cpr_od_pin_live_create_session_backup($env, $jid3, cpr_op_sa());
    cpr_op('create_before_verify_fail', !empty($create3['ok']), (string) ($create3['code'] ?? ''));
    $vfail = orange_cpr_od_pin_live_verify($env, $jid3, cpr_op_sa(['force_verify_fail' => true]));
    cpr_op(
        'verify_fail_closed',
        empty($vfail['ok']) && ($vfail['code'] ?? '') === ORANGE_CPR_ODPIN_ERR_VERIFY,
        (string) ($vfail['code'] ?? '')
    );
    $pinAfterFail = orange_cpr_od_pin_live_pin($env, $jid3, cpr_op_sa());
    cpr_op(
        'pin_blocked_after_verify_fail',
        empty($pinAfterFail['ok']) && ($pinAfterFail['code'] ?? '') === ORANGE_CPR_ODPIN_ERR_VERIFY,
        (string) ($pinAfterFail['code'] ?? '')
    );
    $cpr3 = orange_cpr_resolve_work_root($env);
    cpr_op('cp1_absent_after_verify_fail', !orange_cpr_checkpoint_exists($cpr3, $jid3, 'CP1'));

    // --- Without CP4 ---
    $fp4 = hash('sha256', 'fp-nop4-' . bin2hex(random_bytes(4)));
    $job4 = orange_cpr_job_create($env, [
        'package_id' => 'pkg-nop4',
        'package_fingerprint' => $fp4,
        'country_id' => 1,
        'country_code' => 'KW',
        'workflow' => 'A',
    ], 1);
    $jid4 = (string) $job4['job_id'];
    orange_cpr_transition_apply($env, $jid4, 'cpr_gates_validating', ['actor' => ORANGE_CPR_ACTOR_SYSTEM]);
    orange_cpr_checkpoint_create($env, $jid4, 'CP0', [
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
        'report_hashes' => ['c4' => str_repeat('b', 32)],
    ], ['written_by' => 'system']);
    orange_cpr_transition_apply($env, $jid4, 'cpr_contract_frozen', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'contract_fingerprints_ok' => true,
    ]);
    orange_cpr_contract_freeze_initial($env, $jid4, cpr_op_fps($fp4), 1);
    orange_cpr_transition_apply($env, $jid4, 'cpr_maintenance_on', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
    ]);
    $noCp4 = orange_cpr_od_pin_live_run($env, $jid4, cpr_op_sa());
    cpr_op(
        'deny_without_cp4',
        empty($noCp4['ok']) && ($noCp4['code'] ?? '') === ORANGE_CPR_ODPIN_ERR_MAINT,
        (string) ($noCp4['code'] ?? '')
    );

    // --- Country Admin forbidden ---
    $setup5 = cpr_op_setup_cp4($env);
    $ca = orange_cpr_od_pin_live_run($env, $setup5['job_id'], [
        'actor_admin_id' => 99,
        'actor_is_super_admin' => false,
        'country_admin_is_executor' => true,
    ]);
    cpr_op(
        'deny_country_admin',
        empty($ca['ok']) && ($ca['code'] ?? '') === ORANGE_CPR_ODPIN_ERR_ACTOR,
        (string) ($ca['code'] ?? '')
    );

    // Continuation without pin helper
    $cont = orange_cpr_od_pin_live_refuse_continuation_without_pin();
    cpr_op(
        'refuse_continuation_without_pin',
        empty($cont['ok']) && ($cont['code'] ?? '') === ORANGE_CPR_ODPIN_ERR_CONTINUATION
    );

    // Audit
    $auditRaw = (string) file_get_contents(orange_cpr_audit_file_path($cprRoot, $jid));
    cpr_op('audit_create', str_contains($auditRaw, 'cpr.od_pin_session_backup_create'));
    cpr_op('audit_verify', str_contains($auditRaw, 'cpr.od_pin_session_backup_verify'));
    cpr_op('audit_pin', str_contains($auditRaw, 'cpr.od_pin_session_backup_pin'));

    cpr_op('enablement_false', ($run['enablement_flag_observed'] ?? true) === false);
} finally {
    cpr_op_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
