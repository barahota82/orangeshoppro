<?php

declare(strict_types=1);

/**
 * Self-test: CPR Approvals & Live Pre-PONR Contract (WP-P4-02).
 * Run: php scripts/backup/country_production/self_test_cpr_approvals_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_approvals_live.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_state_engine.php';

$pass = 0;
$fail = 0;

function cpr_ap(string $name, bool $ok, string $detail = ''): void
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
function cpr_ap_env(): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p402_' . bin2hex(random_bytes(4));
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

function cpr_ap_cleanup(string $base): void
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
function cpr_ap_fps(): array
{
    return [
        'schema_revision_expected' => 121,
        'boundary_policy_version' => 'C1.1',
        'dependency_graph_version' => '1',
        'registry_revision' => 121,
        'c4_report_hash' => '',
        'c5_report_hash' => '',
        'c6_report_hash' => '',
        'c7_report_hash' => '',
        'c8_report_hash' => '',
        'c8_overall_result' => 'SAFE',
        'inventory_snapshot_id' => 'inv-1',
        'inventory_snapshot_hash' => str_repeat('1', 32),
        'production_db_identity_hash' => str_repeat('2', 32),
    ];
}

/**
 * @return array<string, mixed>
 */
function cpr_ap_reports(): array
{
    return [
        'c4' => ['overall' => 'PASS', 'pkg' => 'x'],
        'c5' => ['overall_result' => 'pass', 'recovery_score' => 90],
        'c6' => ['status' => 'ready', 'production_touched' => false],
        'c7' => [
            'overall_result' => 'READY',
            'readiness_score' => 95,
            'survivor_country_integrity' => 'PASS',
            'global_state_integrity' => 'PASS',
            'pillars' => ['accounting' => 'PASS', 'stock_fifo' => 'PASS', 'composite' => 'PASS'],
        ],
        'c8' => [
            'overall_result' => 'SAFE',
            'survivor_country_impact' => 0,
            'global_impact' => 0,
            'je_full_only_impact' => 0,
            'simulation_only' => true,
            'execution_performed' => false,
        ],
    ];
}

/**
 * @param array<string, mixed> $reports
 * @return array<string, mixed>
 */
function cpr_ap_evidence(array $reports, string $packageId, string $fp): array
{
    return [
        'enablement' => true,
        'od_enable_preconditions_complete' => true,
        'pin_capability' => true,
        'certification' => ['result' => 'PASS'],
        'host_preflight' => ['pass' => true],
        'package' => ['status' => 'finalized', 'package_id' => $packageId, 'fingerprint' => $fp],
        'reports' => $reports,
        'inventory' => [
            'id' => 'inv-1',
            'hash' => str_repeat('1', 32),
            'certified_read_only' => true,
            'replaced_by_live' => false,
        ],
        'live_sot' => [
            'schema_revision' => 121,
            'boundary_policy_version' => 'C1.1',
            'dependency_graph_version' => '1',
            'registry_revision' => 121,
            'production_db_identity_hash' => str_repeat('2', 32),
        ],
        'maint' => [
            'scope' => 'GLOBAL',
            'global_maintenance_on' => true,
            'write_block_proven' => true,
        ],
        'session_full_backup' => [
            'id' => 'fb-new',
            'pinned' => true,
            'verified' => true,
            'reused' => false,
        ],
        'authority' => [
            'wfa_protections_ack' => true,
            'country_admin_is_executor' => false,
        ],
        'phrase' => [
            'phrase_ok' => true,
            'phrase' => 'RESTORE',
            'reauth_ok' => true,
            'one_time_authorization_id' => 'ota-scaffold',
            'ota_consumed' => false,
        ],
        'emergency_stop' => false,
        'fa_resolver' => ['proven' => true, 'shortcut' => false],
        'fa_stock' => ['armed' => true, 'soft_mode' => false],
        'fa_schema' => ['live_revision' => 121, 'soft_skip' => false, 'cert_invalidated' => false],
        'witness_expectations' => [
            'survivor_baseline_hash' => str_repeat('s', 32),
            'global_baseline_hash' => str_repeat('g', 32),
            'target_inventory_hash' => str_repeat('t', 32),
        ],
        'waiver_attempted' => false,
    ];
}

/**
 * @param array<string, mixed> $env
 * @return array{job_id:string,evidence:array<string,mixed>,lease:string,worker:string,reread:array<string,mixed>}
 */
function cpr_ap_setup(array $env, int $countryId = 1, string $code = 'KW', string $workflow = 'A'): array
{
    $fp = hash('sha256', 'fp-ap-' . $countryId . '-' . $code . '-' . $workflow);
    $pkg = 'pkg-appr-' . $countryId . '-' . strtolower($workflow);
    $job = orange_cpr_job_create($env, [
        'package_id' => $pkg,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $code,
        'workflow' => $workflow,
    ], 1);
    $jid = (string) $job['job_id'];

    $reports = cpr_ap_reports();
    $fps = cpr_ap_fps();
    $fps['c4_report_hash'] = orange_cpr_gate_report_hash($reports['c4']);
    $fps['c5_report_hash'] = orange_cpr_gate_report_hash($reports['c5']);
    $fps['c6_report_hash'] = orange_cpr_gate_report_hash($reports['c6']);
    $fps['c7_report_hash'] = orange_cpr_gate_report_hash($reports['c7']);
    $fps['c8_report_hash'] = orange_cpr_gate_report_hash($reports['c8']);

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
        'report_hashes' => ['c4' => $fps['c4_report_hash']],
    ], ['written_by' => 'system']);

    orange_cpr_transition_apply($env, $jid, 'cpr_contract_frozen', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'contract_fingerprints_ok' => true,
    ]);
    orange_cpr_checkpoint_create($env, $jid, 'CP2', [
        'workflow' => $workflow,
        'wfa_protections_ack' => true,
        'approval_fingerprint' => str_repeat('9', 32),
    ], ['written_by' => 'system']);
    orange_cpr_contract_freeze_initial($env, $jid, $fps, 1);
    orange_cpr_checkpoint_create($env, $jid, 'CP3', [
        'contract_revision' => 1,
        'contract_phase' => 'pre_pin',
        'package_fingerprint' => $fp,
        'fingerprint_digest' => hash('sha256', $fp),
    ], ['written_by' => 'system']);

    orange_cpr_transition_apply($env, $jid, 'cpr_maintenance_on', ['actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN]);
    orange_cpr_checkpoint_create($env, $jid, 'CP4', [
        'global_maintenance_on' => true,
        'write_block_proof' => 'proof',
        'maint_entered_at' => gmdate('c'),
    ], ['written_by' => 'system']);
    orange_cpr_transition_apply($env, $jid, 'cpr_anchor_pinning', ['actor' => ORANGE_CPR_ACTOR_SYSTEM]);
    orange_cpr_checkpoint_create($env, $jid, 'CP1', [
        'session_full_backup_id' => 'fb-new',
        'session_full_backup_fingerprint' => str_repeat('4', 32),
        'verified' => true,
        'pinned' => true,
        'created_under_maintenance' => true,
        'reused_existing_backup' => false,
        'cp4_reference' => 'CP4_maintenance_verified.json',
    ], ['written_by' => 'system']);
    orange_cpr_transition_apply($env, $jid, 'cpr_pre_ponr', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'session_full_backup_pinned' => true,
    ]);
    orange_cpr_checkpoint_create($env, $jid, 'runbook_pre_ponr', [
        'restore_package_id' => $pkg,
        'target_country_id' => $countryId,
        'target_country_code' => $code,
        'c8_overall_result' => 'SAFE',
        'certified_inventory_snapshot_id' => 'inv-1',
        'session_full_backup_id' => 'fb-new',
        'global_maintenance_active' => true,
        'completed_by_admin_id' => 1,
        'completed_at' => gmdate('c'),
        'audit_record_id' => 'aud-1',
    ], ['written_by' => 'super_admin']);
    orange_cpr_checkpoint_create($env, $jid, 'CP5', [
        'survivor_baseline_hash' => str_repeat('s', 32),
        'global_baseline_hash' => str_repeat('g', 32),
        'target_inventory_hash' => str_repeat('t', 32),
        'inventory_snapshot_id' => 'inv-1',
        'captured_at' => gmdate('c'),
    ], ['written_by' => 'system']);

    $worker = 'apw-' . $countryId . '-' . strtolower($workflow);
    $acq = orange_cpr_lock_acquire($env, $jid, ['worker_id' => $worker]);
    $lease = (string) ($acq['lease_token'] ?? '');

    return [
        'job_id' => $jid,
        'evidence' => cpr_ap_evidence($reports, $pkg, $fp),
        'lease' => $lease,
        'worker' => $worker,
        'reread' => [
            'package_fingerprint' => $fp,
            'country_id' => $countryId,
            'schema_revision_expected' => 121,
            'c4_report_hash' => $fps['c4_report_hash'],
            'c5_report_hash' => $fps['c5_report_hash'],
            'c6_report_hash' => $fps['c6_report_hash'],
            'c7_report_hash' => $fps['c7_report_hash'],
            'c8_report_hash' => $fps['c8_report_hash'],
            'c8_overall_result' => 'SAFE',
            'inventory_snapshot_id' => 'inv-1',
            'inventory_snapshot_hash' => str_repeat('1', 32),
            'production_db_identity_hash' => str_repeat('2', 32),
            'session_full_backup_id' => 'fb-new',
            'session_full_backup_fingerprint' => str_repeat('4', 32),
        ],
    ];
}

/**
 * @param array<string, mixed> $setup
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function cpr_ap_req(array $setup, array $overrides = []): array
{
    return array_merge([
        'actor_admin_id' => 1,
        'actor_is_super_admin' => true,
        'wfa_protections_ack' => true,
        'phrase' => 'RESTORE',
        'password_reauth_ok' => true,
        'lease_token' => $setup['lease'],
        'worker_id' => $setup['worker'],
        'reread' => $setup['reread'],
    ], $overrides);
}

$bases = [];

try {
    // ===== Happy path =====
    $e1 = cpr_ap_env();
    $bases[] = $e1['base'];
    $setup = cpr_ap_setup($e1['env'], 1, 'KW', 'A');
    $gate = orange_cpr_gate_evaluate($e1['env'], $setup['job_id'], 'pre_ponr_full', $setup['evidence']);
    cpr_ap('gate_pass_precondition', !empty($gate['all_gates_pass']));

    $live = orange_cpr_approvals_live_pre_ponr($e1['env'], $setup['job_id'], cpr_ap_req($setup));
    cpr_ap(
        'live_pre_ponr_approvals_ok',
        !empty($live['ok'])
        && !empty($live['ponr_authorized'])
        && empty($live['ponr_crossed'])
        && empty($live['production_mutation'])
        && empty($live['ponr_mutation_executed']),
        (string) ($live['code'] ?? '') . ' ' . (string) ($live['message'] ?? '')
    );
    cpr_ap(
        'sealed_approvals_live_record',
        is_file((string) ($live['approvals_live_path'] ?? ''))
        && orange_cpr_auth_verify_seal($live['approvals_live'] ?? [])
    );
    cpr_ap('contract_fingerprint_bound', (string) ($live['contract_fingerprint'] ?? '') !== '');
    cpr_ap('enablement_remains_false', ($live['enablement_flag_observed'] ?? null) === false);
    cpr_ap('scaffold_version_current', ORANGE_CPR_SCAFFOLD_VERSION === 'P8-04-integration-baseline');

    $contract = orange_cpr_contract_read($e1['cpr'], $setup['job_id']);
    cpr_ap(
        'contract_phase_pre_ponr',
        is_array($contract) && ($contract['contract_phase'] ?? '') === 'pre_ponr'
        && !empty($contract['session_full_backup_pinned'])
    );

    $dup = orange_cpr_approvals_live_pre_ponr($e1['env'], $setup['job_id'], cpr_ap_req($setup));
    cpr_ap(
        'duplicate_live_approvals',
        empty($dup['ok']) && ($dup['code'] ?? '') === ORANGE_CPR_APPR_ERR_DUPLICATE,
        (string) ($dup['code'] ?? '')
    );

    // ===== Dual deny â€” actor =====
    $e2 = cpr_ap_env();
    $bases[] = $e2['base'];
    $s = cpr_ap_setup($e2['env'], 2, 'KW', 'A');
    orange_cpr_gate_evaluate($e2['env'], $s['job_id'], 'pre_ponr_full', $s['evidence']);
    $r = orange_cpr_approvals_live_pre_ponr($e2['env'], $s['job_id'], cpr_ap_req($s, [
        'actor_is_super_admin' => false,
    ]));
    cpr_ap(
        'dual_deny_actor',
        empty($r['ok']) && ($r['code'] ?? '') === ORANGE_CPR_APPR_ERR_ACTOR,
        (string) ($r['code'] ?? '')
    );

    // ===== Dual deny â€” WF-B missing approval =====
    $e3 = cpr_ap_env();
    $bases[] = $e3['base'];
    $s = cpr_ap_setup($e3['env'], 3, 'BH', 'B');
    orange_cpr_gate_evaluate($e3['env'], $s['job_id'], 'pre_ponr_full', $s['evidence']);
    $r = orange_cpr_approvals_live_pre_ponr($e3['env'], $s['job_id'], cpr_ap_req($s, [
        'wfa_protections_ack' => false,
        'super_admin_approval_recorded' => false,
    ]));
    cpr_ap(
        'dual_deny_wfb',
        empty($r['ok']) && ($r['code'] ?? '') === ORANGE_CPR_APPR_ERR_WF_B,
        (string) ($r['code'] ?? '')
    );

    // ===== State deny =====
    $e4 = cpr_ap_env();
    $bases[] = $e4['base'];
    $s = cpr_ap_setup($e4['env'], 4, 'QA', 'A');
    orange_cpr_gate_evaluate($e4['env'], $s['job_id'], 'pre_ponr_full', $s['evidence']);
    $jobRec = orange_cpr_job_read($e4['cpr'], $s['job_id']);
    $jobRec['state'] = 'cpr_maintenance_on';
    orange_cpr_job_write($e4['cpr'], $s['job_id'], $jobRec);
    $r = orange_cpr_approvals_live_pre_ponr($e4['env'], $s['job_id'], cpr_ap_req($s));
    cpr_ap(
        'state_deny',
        empty($r['ok']) && ($r['code'] ?? '') === ORANGE_CPR_APPR_ERR_STATE,
        (string) ($r['code'] ?? '')
    );

    // ===== Gate deny =====
    $e5 = cpr_ap_env();
    $bases[] = $e5['base'];
    $s = cpr_ap_setup($e5['env'], 5, 'OM', 'A');
    orange_cpr_gate_evaluate($e5['env'], $s['job_id'], 'pre_ponr_full', array_merge($s['evidence'], [
        'enablement' => false,
    ]));
    $r = orange_cpr_approvals_live_pre_ponr($e5['env'], $s['job_id'], cpr_ap_req($s));
    cpr_ap(
        'gate_deny',
        empty($r['ok']) && ($r['code'] ?? '') === ORANGE_CPR_APPR_ERR_GATE,
        (string) ($r['code'] ?? '') . ' ' . (string) ($r['upstream_code'] ?? '')
    );

    // ===== Lock deny =====
    $e6 = cpr_ap_env();
    $bases[] = $e6['base'];
    $s = cpr_ap_setup($e6['env'], 6, 'SA', 'A');
    orange_cpr_gate_evaluate($e6['env'], $s['job_id'], 'pre_ponr_full', $s['evidence']);
    $r = orange_cpr_approvals_live_pre_ponr($e6['env'], $s['job_id'], cpr_ap_req($s, [
        'lease_token' => 'wrong-lease-token-value-xxxxxxxx',
    ]));
    cpr_ap(
        'lock_deny',
        empty($r['ok']) && ($r['code'] ?? '') === ORANGE_CPR_APPR_ERR_LOCK,
        (string) ($r['code'] ?? '')
    );

    // ===== Fingerprint drift =====
    $e7 = cpr_ap_env();
    $bases[] = $e7['base'];
    $s = cpr_ap_setup($e7['env'], 7, 'AE', 'A');
    orange_cpr_gate_evaluate($e7['env'], $s['job_id'], 'pre_ponr_full', $s['evidence']);
    $bad = $s['reread'];
    $bad['c4_report_hash'] = str_repeat('f', 64);
    $r = orange_cpr_approvals_live_pre_ponr($e7['env'], $s['job_id'], cpr_ap_req($s, ['reread' => $bad]));
    cpr_ap(
        'fingerprint_drift',
        empty($r['ok']) && ($r['code'] ?? '') === ORANGE_CPR_APPR_ERR_FINGERPRINT,
        (string) ($r['code'] ?? '')
    );

    // ===== Authorize deny (bad phrase) after dual/revalidate =====
    $e8 = cpr_ap_env();
    $bases[] = $e8['base'];
    $s = cpr_ap_setup($e8['env'], 8, 'KW', 'A');
    orange_cpr_gate_evaluate($e8['env'], $s['job_id'], 'pre_ponr_full', $s['evidence']);
    $r = orange_cpr_approvals_live_pre_ponr($e8['env'], $s['job_id'], cpr_ap_req($s, [
        'phrase' => 'restore',
    ]));
    cpr_ap(
        'authorize_deny_phrase',
        empty($r['ok']) && ($r['code'] ?? '') === ORANGE_CPR_APPR_ERR_AUTH,
        (string) ($r['code'] ?? '') . ' ' . (string) ($r['upstream_code'] ?? '')
    );
    cpr_ap('authorize_deny_no_ponr', empty($r['ponr_crossed']) && empty($r['production_mutation']));

    // ===== Checkpoint deny (remove CP5) =====
    $e9 = cpr_ap_env();
    $bases[] = $e9['base'];
    $s = cpr_ap_setup($e9['env'], 9, 'KW', 'A');
    orange_cpr_gate_evaluate($e9['env'], $s['job_id'], 'pre_ponr_full', $s['evidence']);
    @unlink(orange_cpr_checkpoint_final_path($e9['cpr'], $s['job_id'], 'CP5'));
    $r = orange_cpr_approvals_live_pre_ponr($e9['env'], $s['job_id'], cpr_ap_req($s));
    cpr_ap(
        'checkpoint_deny',
        empty($r['ok']) && ($r['code'] ?? '') === ORANGE_CPR_APPR_ERR_CHECKPOINT,
        (string) ($r['code'] ?? '')
    );

    // ===== Hard refuse mutation helper still closed =====
    $refuse = orange_cpr_ponr_mutation_refuse();
    cpr_ap('no_ponr_mutation_helper', empty($refuse['ok']) && ($refuse['code'] ?? '') === 'ponr_mutation_forbidden');
} catch (Throwable $e) {
    cpr_ap('suite_exception', false, $e->getMessage());
}

foreach ($bases as $base) {
    cpr_ap_cleanup($base);
}

echo "\n{$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
