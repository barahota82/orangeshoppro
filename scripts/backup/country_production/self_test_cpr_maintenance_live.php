<?php

declare(strict_types=1);

/**
 * Self-test: CPR GLOBAL Maintenance Live (WP-P4-03 / CP4).
 * Run: php scripts/backup/country_production/self_test_cpr_maintenance_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_maintenance_live.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_state_engine.php';

$pass = 0;
$fail = 0;

function cpr_ml(string $name, bool $ok, string $detail = ''): void
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
function cpr_ml_env(): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p403_' . bin2hex(random_bytes(4));
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

function cpr_ml_cleanup(string $base): void
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
function cpr_ml_fps(string $pkgFp): array
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
 * Job frozen with CP0/CP2/CP3 â€” ready for live maint/CP4 (no CP1).
 *
 * @param array<string, mixed> $env
 * @return array{job_id:string,package_fingerprint:string}
 */
function cpr_ml_setup_frozen(array $env): array
{
    $fp = hash('sha256', 'fp-ml-' . bin2hex(random_bytes(4)));
    $job = orange_cpr_job_create($env, [
        'package_id' => 'pkg-ml-' . substr($fp, 0, 8),
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
    orange_cpr_contract_freeze_initial($env, $jid, cpr_ml_fps($fp), 1);
    orange_cpr_checkpoint_create($env, $jid, 'CP3', [
        'contract_revision' => 1,
        'contract_phase' => 'pre_pin',
        'package_fingerprint' => $fp,
        'fingerprint_digest' => hash('sha256', $fp),
    ], ['written_by' => 'system']);

    return ['job_id' => $jid, 'package_fingerprint' => $fp];
}

/**
 * @return array<string, mixed>
 */
function cpr_ml_sa_req(array $extra = []): array
{
    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'maint_scope' => 'GLOBAL',
        'write_block_proof' => 'cpr_write_block_proof:storefront_order_create',
        'duration_inputs' => [
            'package_bytes' => 10 * 1024 * 1024,
            'sql_bytes' => 2 * 1024 * 1024,
            'uploads_bytes' => 1024 * 1024,
            'row_count_estimate' => 5000,
            'batch_count_estimate' => 10,
            'infra_factor' => 1.0,
        ],
    ], $extra);
}

$bundle = cpr_ml_env();
$env = $bundle['env'];
$base = $bundle['base'];

try {
    cpr_ml('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P6-05-maint-release');

    // --- Happy path: activate CP4 ---
    $setup = cpr_ml_setup_frozen($env);
    $jid = $setup['job_id'];
    $cprRoot = orange_cpr_resolve_work_root($env);

    $act = orange_cpr_maint_live_activate_cp4($env, $jid, cpr_ml_sa_req());
    cpr_ml('activate_cp4_ok', !empty($act['ok']), (string) ($act['message'] ?? $act['code'] ?? ''));
    cpr_ml('lifecycle_proven', ($act['lifecycle'] ?? '') === ORANGE_CPR_MAINT_LIFECYCLE_PROVEN);
    cpr_ml('cp4_committed_flag', !empty($act['cp4_committed']));
    cpr_ml('pin_allowed_not_executed', !empty($act['session_full_backup_pin_allowed'])
        && empty($act['session_full_backup_pin_executed']));
    cpr_ml('no_production_mutation', ($act['production_mutation'] ?? true) === false);

    $job = orange_cpr_job_read($cprRoot, $jid);
    cpr_ml('job_state_maintenance_on', ($job['state'] ?? '') === 'cpr_maintenance_on');
    cpr_ml('cp4_exists', orange_cpr_checkpoint_exists($cprRoot, $jid, 'CP4'));
    cpr_ml('cp1_absent', !orange_cpr_checkpoint_exists($cprRoot, $jid, 'CP1'));

    $state = orange_cpr_maint_live_load_state($cprRoot, $jid);
    cpr_ml('maint_state_sealed', is_array($state) && orange_cpr_auth_verify_seal($state));
    cpr_ml('maint_scope_global', is_array($state) && ($state['maint_scope'] ?? '') === 'GLOBAL');
    cpr_ml('write_block_proven', is_array($state) && !empty($state['write_block_proven']));

    $slice = orange_cpr_maint_live_evidence_slice($state ?? []);
    $g22ctx = orange_cpr_gate_build_context($env, $jid, ['maint' => $slice]);
    $g22 = orange_cpr_gate_evaluate_one('G22', $g22ctx);
    cpr_ml('gate_g22_pass', ($g22['result'] ?? '') === ORANGE_CPR_GATE_PASS, (string) ($g22['fail_code'] ?? ''));

    $lockInt = orange_cpr_maint_live_integrate_lock($env, $job);
    cpr_ml('lock_integration_ok', !empty($lockInt['ok']) && !empty($lockInt['maint_global_required']));

    // Idempotent re-activate
    $again = orange_cpr_maint_live_activate_cp4($env, $jid, cpr_ml_sa_req());
    cpr_ml('activate_idempotent', !empty($again['ok']));

    // --- Country-only forbidden ---
    $setup2 = cpr_ml_setup_frozen($env);
    $denyScope = orange_cpr_maint_live_activate_cp4($env, $setup2['job_id'], cpr_ml_sa_req([
        'maint_scope' => 'COUNTRY',
    ]));
    cpr_ml(
        'deny_country_scope',
        empty($denyScope['ok']) && ($denyScope['code'] ?? '') === ORANGE_CPR_MAINT_ERR_SCOPE,
        (string) ($denyScope['code'] ?? '')
    );

    // --- Country Admin forbidden ---
    $setup3 = cpr_ml_setup_frozen($env);
    $denyActor = orange_cpr_maint_live_activate_cp4($env, $setup3['job_id'], cpr_ml_sa_req([
        'actor_is_super_admin' => false,
        'country_admin_is_executor' => true,
    ]));
    cpr_ml(
        'deny_country_admin',
        empty($denyActor['ok']) && ($denyActor['code'] ?? '') === ORANGE_CPR_MAINT_ERR_ACTOR,
        (string) ($denyActor['code'] ?? '')
    );

    // --- Empty write-block proof ---
    $setup4 = cpr_ml_setup_frozen($env);
    $enter4 = orange_cpr_maint_live_enter($env, $setup4['job_id'], cpr_ml_sa_req());
    cpr_ml('enter_ok_before_empty_proof', !empty($enter4['ok']), (string) ($enter4['code'] ?? ''));
    $denyProof = orange_cpr_maint_live_prove_and_activate_cp4($env, $setup4['job_id'], cpr_ml_sa_req([
        'write_block_proof' => '',
    ]));
    cpr_ml(
        'deny_empty_proof',
        empty($denyProof['ok']) && ($denyProof['code'] ?? '') === ORANGE_CPR_MAINT_ERR_PROOF,
        (string) ($denyProof['code'] ?? '')
    );

    // --- Auto release forbidden ---
    $auto = orange_cpr_maint_live_refuse_auto_release();
    cpr_ml('refuse_auto_helper', empty($auto['ok']) && ($auto['code'] ?? '') === ORANGE_CPR_MAINT_ERR_AUTO_RELEASE);

    orange_cpr_transition_apply($env, $jid, 'cpr_cancelled_pre_ponr', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'trigger' => 'cancel_or_estop',
    ]);
    $autoRel = orange_cpr_maint_live_release($env, $jid, cpr_ml_sa_req([
        'auto_release' => true,
        'runbook_completed' => true,
        'runbook_evidence_ref' => 'rb-1',
        'write_block_cleared_proof' => 'cleared-1',
    ]));
    cpr_ml(
        'deny_auto_release',
        empty($autoRel['ok']) && ($autoRel['code'] ?? '') === ORANGE_CPR_MAINT_ERR_AUTO_RELEASE,
        (string) ($autoRel['code'] ?? '')
    );

    // --- Release without runbook ---
    $noRb = orange_cpr_maint_live_release($env, $jid, cpr_ml_sa_req([
        'runbook_completed' => false,
        'write_block_cleared_proof' => 'cleared-1',
    ]));
    cpr_ml(
        'deny_release_without_runbook',
        empty($noRb['ok']) && ($noRb['code'] ?? '') === ORANGE_CPR_MAINT_ERR_RUNBOOK,
        (string) ($noRb['code'] ?? '')
    );

    // --- Release by Country Admin ---
    $caRel = orange_cpr_maint_live_release($env, $jid, [
        'actor_admin_id' => 99,
        'actor_is_super_admin' => false,
        'country_admin_is_executor' => true,
        'runbook_completed' => true,
        'runbook_evidence_ref' => 'rb-1',
        'write_block_cleared_proof' => 'cleared-1',
    ]);
    cpr_ml(
        'deny_release_country_admin',
        empty($caRel['ok']) && ($caRel['code'] ?? '') === ORANGE_CPR_MAINT_ERR_ACTOR,
        (string) ($caRel['code'] ?? '')
    );

    // --- Legal Super Admin release after cancel closeout ---
    $rel = orange_cpr_maint_live_release($env, $jid, cpr_ml_sa_req([
        'runbook_completed' => true,
        'runbook_evidence_ref' => 'runbook/evidence/rb-ml-1',
        'write_block_cleared_proof' => 'write_block_cleared:sa-procedure-1',
    ]));
    cpr_ml('release_ok', !empty($rel['ok']), (string) ($rel['message'] ?? $rel['code'] ?? ''));
    $jobRel = orange_cpr_job_read($cprRoot, $jid);
    cpr_ml('job_maintenance_released', ($jobRel['state'] ?? '') === 'cpr_maintenance_released');
    $stRel = orange_cpr_maint_live_load_state($cprRoot, $jid);
    cpr_ml('lifecycle_released', is_array($stRel) && ($stRel['lifecycle'] ?? '') === ORANGE_CPR_MAINT_LIFECYCLE_RELEASED);
    cpr_ml('maint_off_after_release', is_array($stRel) && empty($stRel['global_maintenance_on']));

    // Audit events present
    $auditPath = orange_cpr_audit_file_path($cprRoot, $jid);
    $auditRaw = is_file($auditPath) ? (string) file_get_contents($auditPath) : '';
    cpr_ml('audit_enter', str_contains($auditRaw, 'cpr.maint_live_enter'));
    cpr_ml('audit_cp4', str_contains($auditRaw, 'cpr.maint_live_cp4_activate'));
    cpr_ml('audit_release', str_contains($auditRaw, 'cpr.maint_live_release'));

    // Duration estimate monitoring-only
    $est = orange_cpr_maint_live_duration_estimate(['job_id' => $jid], cpr_ml_sa_req());
    cpr_ml('duration_monitoring_only', !empty($est['monitoring_only'])
        && ($est['hard_fail_deadline'] ?? true) === false
        && ($est['auto_release'] ?? true) === false);

    // Enablement hard false still observed
    cpr_ml('enablement_false_on_success', ($act['enablement_flag_observed'] ?? true) === false);
} finally {
    cpr_ml_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
