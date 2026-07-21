<?php

declare(strict_types=1);

/**
 * Self-test: CPR Pre-PONR Authorization & Contract Freeze (WP-P3-07).
 * Run: php scripts/backup/country_production/self_test_cpr_authority.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_authority_engine.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_state_engine.php';

$pass = 0;
$fail = 0;

function cpr_at(string $name, bool $ok, string $detail = ''): void
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
 * @return array{env:array<string,mixed>,cpr:string,base:string}
 */
function cpr_at_env(): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p307_' . bin2hex(random_bytes(4));
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

function cpr_at_cleanup(string $base): void
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
function cpr_at_fps(): array
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
function cpr_at_reports(): array
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
function cpr_at_evidence(array $reports, string $packageId, string $fp): array
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
 * @return array{job_id:string,evidence:array<string,mixed>,lease:string,worker:string,reread:array<string,mixed>,fps:array<string,mixed>}
 */
function cpr_at_setup(array $env, int $countryId = 1, string $code = 'KW'): array
{
    $fp = hash('sha256', 'fp-' . $countryId . '-' . $code);
    $pkg = 'pkg-auth-' . $countryId;
    $job = orange_cpr_job_create($env, [
        'package_id' => $pkg,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $code,
        'workflow' => 'A',
    ], 1);
    $jid = (string) $job['job_id'];

    $reports = cpr_at_reports();
    $fps = cpr_at_fps();
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

    $worker = 'aw-' . $countryId;
    $acq = orange_cpr_lock_acquire($env, $jid, ['worker_id' => $worker]);
    $lease = (string) ($acq['lease_token'] ?? '');

    return [
        'job_id' => $jid,
        'evidence' => cpr_at_evidence($reports, $pkg, $fp),
        'lease' => $lease,
        'worker' => $worker,
        'fps' => $fps,
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
function cpr_at_req(array $setup, array $overrides = []): array
{
    return array_merge([
        'actor_admin_id' => 1,
        'actor_is_super_admin' => true,
        'phrase' => 'RESTORE',
        'password_reauth_ok' => true,
        'lease_token' => $setup['lease'],
        'worker_id' => $setup['worker'],
        'reread' => $setup['reread'],
    ], $overrides);
}

$bases = [];

try {
    // ===== Happy path + duplicate + replay =====
    $e1 = cpr_at_env();
    $bases[] = $e1['base'];
    $env = $e1['env'];
    $setup = cpr_at_setup($env, 1, 'KW');
    $gate = orange_cpr_gate_evaluate($env, $setup['job_id'], 'pre_ponr_full', $setup['evidence']);
    cpr_at('gate_pass_precondition', !empty($gate['all_gates_pass']) && !empty($gate['ponr_authorized']));

    $auth = orange_cpr_ponr_authorize($env, $setup['job_id'], cpr_at_req($setup));
    cpr_at(
        'successful_authorization_scaffold',
        !empty($auth['ok']) && !empty($auth['ponr_authorized'])
        && empty($auth['ponr_crossed']) && empty($auth['ponr_mutation_executed']),
        (string) ($auth['code'] ?? '') . ' ' . (string) ($auth['message'] ?? '')
    );
    cpr_at(
        'sealed_authorization_record',
        is_file((string) ($auth['authorization_path'] ?? ''))
        && orange_cpr_auth_verify_seal($auth['authorization'] ?? [])
    );
    cpr_at('enablement_remains_false', ($auth['enablement_flag_observed'] ?? null) === false);
    $refuse = orange_cpr_ponr_mutation_refuse();
    cpr_at('no_ponr_mutation', empty($refuse['ok']) && ($refuse['code'] ?? '') === 'ponr_mutation_forbidden');

    $dup = orange_cpr_ponr_authorize($env, $setup['job_id'], cpr_at_req($setup));
    cpr_at('duplicate_authorization_attempt', empty($dup['ok']) && ($dup['code'] ?? '') === 'auth_duplicate');

    $ota = (string) ($auth['one_time_authorization_id'] ?? '');
    $consumed = orange_cpr_ponr_authorization_mark_consumed($env, $setup['job_id'], $ota);
    cpr_at('consume_without_mutation', !empty($consumed['ok']));
    $replay = orange_cpr_ponr_authorization_assert_usable($env, $setup['job_id'], $ota);
    cpr_at('replay_attempt', empty($replay['ok']) && ($replay['code'] ?? '') === 'auth_replay');

    // ===== Missing gate =====
    $e2 = cpr_at_env();
    $bases[] = $e2['base'];
    $s = cpr_at_setup($e2['env'], 1, 'KW');
    $r = orange_cpr_ponr_authorize($e2['env'], $s['job_id'], cpr_at_req($s));
    cpr_at('missing_gate_report', empty($r['ok']) && ($r['code'] ?? '') === 'auth_gate_missing');

    // ===== Failed gate =====
    $e3 = cpr_at_env();
    $bases[] = $e3['base'];
    $s = cpr_at_setup($e3['env'], 1, 'KW');
    orange_cpr_gate_evaluate($e3['env'], $s['job_id'], 'pre_ponr_full', array_merge($s['evidence'], [
        'enablement' => false,
    ]));
    $r = orange_cpr_ponr_authorize($e3['env'], $s['job_id'], cpr_at_req($s));
    cpr_at('failed_gate_report', empty($r['ok']) && ($r['code'] ?? '') === 'auth_gate_not_pass');

    // ===== Stale gate =====
    $e4 = cpr_at_env();
    $bases[] = $e4['base'];
    $s = cpr_at_setup($e4['env'], 1, 'KW');
    orange_cpr_gate_evaluate($e4['env'], $s['job_id'], 'pre_ponr_full', $s['evidence']);
    $latestPath = orange_cpr_gates_directory($e4['cpr'], $s['job_id'])
        . DIRECTORY_SEPARATOR . 'cpr_gate_evaluation_latest_pre_ponr_full.json';
    $rep = json_decode((string) file_get_contents($latestPath), true);
    $rep['evaluated_at'] = gmdate('c', time() - 3600);
    unset($rep['content_sha256']);
    ksort($rep);
    $rep['content_sha256'] = hash(
        'sha256',
        (string) json_encode($rep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    file_put_contents(
        $latestPath,
        json_encode($rep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
    $r = orange_cpr_ponr_authorize($e4['env'], $s['job_id'], cpr_at_req($s));
    cpr_at('stale_gate_report', empty($r['ok']) && ($r['code'] ?? '') === 'auth_gate_stale');

    // ===== Fingerprint drift =====
    $e5 = cpr_at_env();
    $bases[] = $e5['base'];
    $s = cpr_at_setup($e5['env'], 1, 'KW');
    orange_cpr_gate_evaluate($e5['env'], $s['job_id'], 'pre_ponr_full', $s['evidence']);
    $bad = $s['reread'];
    $bad['c4_report_hash'] = str_repeat('f', 64);
    $r = orange_cpr_ponr_authorize($e5['env'], $s['job_id'], cpr_at_req($s, ['reread' => $bad]));
    cpr_at('fingerprint_drift', empty($r['ok']) && ($r['code'] ?? '') === 'auth_fingerprint_drift');

    // ===== Invalid actor / phrase / reauth =====
    $e6 = cpr_at_env();
    $bases[] = $e6['base'];
    $s = cpr_at_setup($e6['env'], 1, 'KW');
    orange_cpr_gate_evaluate($e6['env'], $s['job_id'], 'pre_ponr_full', $s['evidence']);
    $r = orange_cpr_ponr_authorize($e6['env'], $s['job_id'], cpr_at_req($s, [
        'actor_is_super_admin' => false,
    ]));
    cpr_at('invalid_actor', empty($r['ok']) && ($r['code'] ?? '') === 'auth_actor_not_super_admin');
    $r = orange_cpr_ponr_authorize($e6['env'], $s['job_id'], cpr_at_req($s, ['phrase' => 'restore']));
    cpr_at('invalid_restore_phrase', empty($r['ok']) && ($r['code'] ?? '') === 'auth_phrase_invalid');
    $r = orange_cpr_ponr_authorize($e6['env'], $s['job_id'], cpr_at_req($s, [
        'password_reauth_ok' => false,
    ]));
    cpr_at('missing_reauth', empty($r['ok']) && ($r['code'] ?? '') === 'auth_reauth_missing');

    // ===== Incomplete runbook (gate PASS then delete runbook) =====
    $e7 = cpr_at_env();
    $bases[] = $e7['base'];
    $s = cpr_at_setup($e7['env'], 1, 'KW');
    orange_cpr_gate_evaluate($e7['env'], $s['job_id'], 'pre_ponr_full', $s['evidence']);
    @unlink(orange_cpr_checkpoint_final_path($e7['cpr'], $s['job_id'], 'runbook_pre_ponr'));
    $r = orange_cpr_ponr_authorize($e7['env'], $s['job_id'], cpr_at_req($s));
    cpr_at(
        'incomplete_runbook',
        empty($r['ok']) && ($r['code'] ?? '') === 'auth_runbook_incomplete',
        (string) ($r['code'] ?? '')
    );

    // ===== Missing pin =====
    $e8 = cpr_at_env();
    $bases[] = $e8['base'];
    $env = $e8['env'];
    $cpr = $e8['cpr'];
    $fp = hash('sha256', 'nopin');
    $job = orange_cpr_job_create($env, [
        'package_id' => 'pkg-nopin',
        'package_fingerprint' => $fp,
        'country_id' => 9,
        'country_code' => 'QA',
        'workflow' => 'A',
    ], 1);
    $jid = (string) $job['job_id'];
    $reports = cpr_at_reports();
    $fps = cpr_at_fps();
    foreach (['c4', 'c5', 'c6', 'c7', 'c8'] as $k) {
        $fps[$k . '_report_hash'] = orange_cpr_gate_report_hash($reports[$k]);
    }
    orange_cpr_transition_apply($env, $jid, 'cpr_gates_validating', ['actor' => ORANGE_CPR_ACTOR_SYSTEM]);
    orange_cpr_transition_apply($env, $jid, 'cpr_contract_frozen', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'contract_fingerprints_ok' => true,
    ]);
    orange_cpr_contract_freeze_initial($env, $jid, $fps, 1);
    $jobRec = orange_cpr_job_read($cpr, $jid);
    $jobRec['state'] = 'cpr_pre_ponr';
    orange_cpr_job_write($cpr, $jid, $jobRec);
    $fakeGate = [
        'schema_version' => ORANGE_CPR_GATE_EVAL_SCHEMA,
        'job_id' => $jid,
        'profile' => 'pre_ponr_full',
        'evaluated_at' => gmdate('c'),
        'all_gates_pass' => true,
        'gates' => [],
        'c8_overall_result_observed' => 'SAFE',
        'inventory_snapshot_id' => 'inv-1',
        'session_full_backup_pinned' => true,
        'full_dr_active' => false,
        'c6_active' => false,
        'waiver_attempted' => false,
        'evaluator_version' => 'test',
        'package_fingerprint' => $fp,
        'job_state' => 'cpr_pre_ponr',
        'last_checkpoint_id' => null,
        'ponr_crossed_observed' => false,
        'enablement_flag_observed' => true,
        'scaffold_record_only' => true,
        'ponr_authorized' => true,
    ];
    ksort($fakeGate);
    $fakeGate['content_sha256'] = hash(
        'sha256',
        (string) json_encode($fakeGate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    $gdir = orange_cpr_gates_directory($cpr, $jid);
    @mkdir($gdir, 0775, true);
    file_put_contents(
        $gdir . DIRECTORY_SEPARATOR . 'cpr_gate_evaluation_latest_pre_ponr_full.json',
        json_encode($fakeGate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
    $acq = orange_cpr_lock_acquire($env, $jid, ['worker_id' => 'nopin']);
    $r = orange_cpr_ponr_authorize($env, $jid, [
        'actor_admin_id' => 1,
        'actor_is_super_admin' => true,
        'phrase' => 'RESTORE',
        'password_reauth_ok' => true,
        'lease_token' => (string) ($acq['lease_token'] ?? ''),
        'worker_id' => 'nopin',
        'reread' => [
            'package_fingerprint' => $fp,
            'country_id' => 9,
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
    ]);
    cpr_at('missing_pin', empty($r['ok']) && ($r['code'] ?? '') === 'auth_pin_missing', (string) ($r['code'] ?? ''));

    // ===== Lock ownership drift =====
    $e9 = cpr_at_env();
    $bases[] = $e9['base'];
    $s = cpr_at_setup($e9['env'], 1, 'KW');
    orange_cpr_gate_evaluate($e9['env'], $s['job_id'], 'pre_ponr_full', $s['evidence']);
    $r = orange_cpr_ponr_authorize($e9['env'], $s['job_id'], cpr_at_req($s, [
        'lease_token' => 'wrong-lease-token-value-xxxxxxxx',
    ]));
    cpr_at('lock_ownership_drift', empty($r['ok']) && ($r['code'] ?? '') === 'auth_lock_ownership_drift');
} catch (Throwable $e) {
    cpr_at('suite_exception', false, $e->getMessage());
}

foreach ($bases as $base) {
    cpr_at_cleanup($base);
}

echo "\n{$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
