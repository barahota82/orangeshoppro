<?php

declare(strict_types=1);

/**
 * Self-test: CPR Pre-PONR gate evaluator (WP-P3-06).
 * Run: php scripts/backup/country_production/self_test_cpr_gates.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_gate_evaluator.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_state_engine.php';

$pass = 0;
$fail = 0;

function cpr_gt(string $name, bool $ok, string $detail = ''): void
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

/**
 * @return array<string, mixed>
 */
function cpr_gt_fps(): array
{
    return [
        'schema_revision_expected' => 121,
        'boundary_policy_version' => 'C1.1',
        'dependency_graph_version' => '1',
        'registry_revision' => 121,
        'c4_report_hash' => '', // filled after reports built
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
function cpr_gt_reports(): array
{
    $c4 = ['overall' => 'PASS', 'pkg' => 'x'];
    $c5 = ['overall_result' => 'pass', 'recovery_score' => 90];
    $c6 = ['status' => 'ready', 'production_touched' => false];
    $c7 = [
        'overall_result' => 'READY',
        'readiness_score' => 95,
        'survivor_country_integrity' => 'PASS',
        'global_state_integrity' => 'PASS',
        'pillars' => ['accounting' => 'PASS', 'stock_fifo' => 'PASS', 'composite' => 'PASS'],
    ];
    $c8 = [
        'overall_result' => 'SAFE',
        'survivor_country_impact' => 0,
        'global_impact' => 0,
        'je_full_only_impact' => 0,
        'simulation_only' => true,
        'execution_performed' => false,
    ];

    return compact('c4', 'c5', 'c6', 'c7', 'c8');
}

/**
 * @param array<string, mixed> $reports
 * @return array<string, mixed>
 */
function cpr_gt_base_evidence(array $reports, string $packageId, string $fp): array
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
            'one_time_authorization_id' => 'ota-1',
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
 * @return array{job_id:string,evidence:array<string,mixed>,lease:string}
 */
function cpr_gt_setup_full(array $env): array
{
    $fp = str_repeat('a', 32);
    $job = orange_cpr_job_create($env, [
        'package_id' => 'pkg-gate',
        'package_fingerprint' => $fp,
        'country_id' => 1,
        'country_code' => 'KW',
        'workflow' => 'A',
    ], 1);
    $jid = (string) $job['job_id'];

    $reports = cpr_gt_reports();
    $fps = cpr_gt_fps();
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
        'workflow' => 'A',
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
        'restore_package_id' => 'pkg-gate',
        'target_country_id' => 1,
        'target_country_code' => 'KW',
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

    $acq = orange_cpr_lock_acquire($env, $jid, ['worker_id' => 'gw']);
    $lease = (string) ($acq['lease_token'] ?? '');

    $evidence = cpr_gt_base_evidence($reports, 'pkg-gate', $fp);

    return ['job_id' => $jid, 'evidence' => $evidence, 'lease' => $lease];
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p306_' . bin2hex(random_bytes(4));
$restoreWork = $base . DIRECTORY_SEPARATOR . 'restore_work';
$cpr = $restoreWork . DIRECTORY_SEPARATOR . 'country_production';
$backupRoot = $base . DIRECTORY_SEPARATOR . 'backup_root';
@mkdir($cpr, 0775, true);
@mkdir($backupRoot . DIRECTORY_SEPARATOR . 'locks', 0775, true);

$env = [
    'ORANGE_CPR_WORK_DIR' => $cpr,
    'ORANGE_RESTORE_WORK_DIR' => $restoreWork,
    'ORANGE_BACKUP_ROOT' => $backupRoot,
    'ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false,
];

try {
    $setup = cpr_gt_setup_full($env);
    $jid = $setup['job_id'];
    $evidence = $setup['evidence'];

    $all = orange_cpr_gate_evaluate($env, $jid, 'pre_ponr_full', $evidence);
    cpr_gt('all_pass_path', !empty($all['all_gates_pass']) && !empty($all['ponr_authorized']),
        (string) ($all['message'] ?? ''));
    cpr_gt('sealed_report', is_file((string) ($all['path'] ?? ''))
        && strlen((string) ($all['evaluation']['content_sha256'] ?? '')) === 64);

    // Bypass attempt
    $bypass = orange_cpr_gate_evaluate($env, $jid, 'pre_ponr_full', $evidence, ['force_pass' => true]);
    cpr_gt('bypass_attempt', empty($bypass['all_gates_pass'])
        && ($bypass['code'] ?? '') === 'gate_bypass_forbidden');

    // Helper to find fail code for a gate
    $failCode = static function (array $eval, string $gid): ?string {
        foreach ($eval['evaluation']['gates'] ?? [] as $g) {
            if (($g['gate_id'] ?? '') === $gid && ($g['result'] ?? '') === 'FAIL') {
                return (string) ($g['fail_code'] ?? '');
            }
        }

        return null;
    };

    // C8 WARNING
    $e = $evidence;
    $e['reports']['c8']['overall_result'] = 'WARNING';
    $r = orange_cpr_gate_evaluate($env, $jid, 'pre_ponr_full', $e);
    cpr_gt('c8_warning', empty($r['all_gates_pass'])
        && $failCode($r, 'G16') === 'gate_c8_warning_rejected');

    // C8 FAIL
    $e = $evidence;
    $e['reports']['c8']['overall_result'] = 'FAIL';
    $r = orange_cpr_gate_evaluate($env, $jid, 'pre_ponr_full', $e);
    cpr_gt('c8_fail', empty($r['all_gates_pass']) && $failCode($r, 'G16') === 'gate_c8_not_safe');

    // Missing evidence (no c4 report)
    $e = $evidence;
    unset($e['reports']['c4']);
    $r = orange_cpr_gate_evaluate($env, $jid, 'pre_ponr_full', $e);
    cpr_gt('missing_evidence', empty($r['all_gates_pass']) && $failCode($r, 'G08') === 'gate_evidence_missing');

    // Fingerprint mismatch
    $e = $evidence;
    $e['package']['fingerprint'] = str_repeat('z', 32);
    $r = orange_cpr_gate_evaluate($env, $jid, 'pre_ponr_full', $e);
    cpr_gt('stale_fingerprint', empty($r['all_gates_pass'])
        && $failCode($r, 'G10') === 'gate_package_fingerprint_drift');

    // Inventory missing
    $e = $evidence;
    unset($e['inventory']);
    $r = orange_cpr_gate_evaluate($env, $jid, 'pre_ponr_full', $e);
    cpr_gt('inventory_missing', empty($r['all_gates_pass']) && $failCode($r, 'G21') === 'gate_inv_missing');

    // Schema mismatch
    $e = $evidence;
    $e['fa_schema']['live_revision'] = 999;
    $e['live_sot']['schema_revision'] = 999;
    $r = orange_cpr_gate_evaluate($env, $jid, 'pre_ponr_full', $e);
    cpr_gt('schema_mismatch', empty($r['all_gates_pass'])
        && ($failCode($r, 'G-FA-SCHEMA') === 'gate_fa_schema_mismatch'
            || $failCode($r, 'G19') === 'gate_schema_revision_mismatch'));

    // Lock conflict (Full DR)
    file_put_contents($restoreWork . DIRECTORY_SEPARATOR . '.restore.lock', '{"pid":1}');
    $r = orange_cpr_gate_evaluate($env, $jid, 'pre_ponr_full', $evidence);
    cpr_gt('lock_conflict_full_dr', empty($r['all_gates_pass'])
        && $failCode($r, 'G05') === 'gate_full_dr_active');
    @unlink($restoreWork . DIRECTORY_SEPARATOR . '.restore.lock');

    // Invalid OD-PIN: reused backup on evidence + break CP1 payload by evaluating without CP1
    // Simulate by marking CP1 reused via evidence session only — G23 checks checkpoint payload.
    // Create a second job without CP1 for pin-order fail.
    $jobB = orange_cpr_job_create($env, [
        'package_id' => 'pkg-b',
        'package_fingerprint' => str_repeat('b', 32),
        'country_id' => 2,
        'country_code' => 'SA',
        'workflow' => 'A',
    ], 2);
    $jidB = (string) $jobB['job_id'];
    $reportsB = cpr_gt_reports();
    $fpsB = cpr_gt_fps();
    $fpsB['c4_report_hash'] = orange_cpr_gate_report_hash($reportsB['c4']);
    $fpsB['c5_report_hash'] = orange_cpr_gate_report_hash($reportsB['c5']);
    $fpsB['c6_report_hash'] = orange_cpr_gate_report_hash($reportsB['c6']);
    $fpsB['c7_report_hash'] = orange_cpr_gate_report_hash($reportsB['c7']);
    $fpsB['c8_report_hash'] = orange_cpr_gate_report_hash($reportsB['c8']);
    orange_cpr_transition_apply($env, $jidB, 'cpr_gates_validating', ['actor' => ORANGE_CPR_ACTOR_SYSTEM]);
    orange_cpr_transition_apply($env, $jidB, 'cpr_contract_frozen', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'contract_fingerprints_ok' => true,
    ]);
    orange_cpr_contract_freeze_initial($env, $jidB, $fpsB, 1);
    $evB = cpr_gt_base_evidence($reportsB, 'pkg-b', str_repeat('b', 32));
    $r = orange_cpr_gate_evaluate($env, $jidB, 'pre_ponr_full', $evB);
    cpr_gt('invalid_od_pin_order', empty($r['all_gates_pass'])
        && $failCode($r, 'G23') === 'gate_pin_order_violated');

    // FA resolver failure
    $e = $evidence;
    $e['fa_resolver']['proven'] = false;
    $r = orange_cpr_gate_evaluate($env, $jid, 'pre_ponr_full', $e);
    cpr_gt('fa_resolver_fail', empty($r['all_gates_pass'])
        && $failCode($r, 'G-FA-RESOLVER') === 'gate_fa_resolver_unproven');

    // FA stock soft mode
    $e = $evidence;
    $e['fa_stock']['soft_mode'] = true;
    $r = orange_cpr_gate_evaluate($env, $jid, 'pre_ponr_full', $e);
    cpr_gt('fa_stock_soft', empty($r['all_gates_pass'])
        && $failCode($r, 'G-FA-STOCK') === 'gate_fa_stock_soft_mode');

    // Enablement false (real P3 default path) — G01 fail; PONR not authorized
    $e = $evidence;
    $e['enablement'] = false;
    $r = orange_cpr_gate_evaluate($env, $jid, 'pre_ponr_full', $e);
    cpr_gt('enablement_false_blocks_ponr', empty($r['ponr_authorized'])
        && $failCode($r, 'G01') === 'gate_enablement_false');

    // Inventory replaced by live
    $e = $evidence;
    $e['inventory']['replaced_by_live'] = true;
    $r = orange_cpr_gate_evaluate($env, $jid, 'pre_ponr_full', $e);
    cpr_gt('inv_live_replace_forbidden', empty($r['all_gates_pass'])
        && $failCode($r, 'G21') === 'gate_inv_replaced_by_live');

    // package_chain can pass without enablement true
    $e = $evidence;
    $e['enablement'] = false;
    $r = orange_cpr_gate_evaluate($env, $jid, 'package_chain', $e);
    cpr_gt('package_chain_pass', !empty($r['all_gates_pass']));
    cpr_gt('package_chain_no_ponr_auth', empty($r['ponr_authorized']));

    // CPR lock missing for G26
    orange_cpr_lock_release($env, $jid, [
        'lease_token' => $setup['lease'],
        'authorized_closeout' => false,
    ]);
    // pre-PONR release without closeout should work when ponr false
    $lock = orange_cpr_lock_read($cpr);
    if ($lock !== null) {
        orange_cpr_lock_release($env, $jid, [
            'lease_token' => (string) ($lock['ownership']['lease_token'] ?? ''),
        ]);
    }
    $r = orange_cpr_gate_evaluate($env, $jid, 'pre_ponr_full', $evidence);
    cpr_gt('lock_missing_g26', empty($r['all_gates_pass'])
        && $failCode($r, 'G26') === 'gate_cpr_lock_not_held');
} catch (Throwable $e) {
    cpr_gt('suite_exception', false, $e->getMessage());
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
