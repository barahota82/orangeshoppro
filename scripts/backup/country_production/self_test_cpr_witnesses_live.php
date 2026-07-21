<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Witnesses (CP5) & CP-A Pre-PONR Freeze (WP-P4-08).
 * Run: php scripts/backup/country_production/self_test_cpr_witnesses_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_witnesses_live.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_state_engine.php';

$pass = 0;
$fail = 0;

function cpr_wl(string $name, bool $ok, string $detail = ''): void
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
function cpr_wl_env(): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p408_' . bin2hex(random_bytes(4));
    $restoreWork = $base . DIRECTORY_SEPARATOR . 'restore_work';
    $cpr = $restoreWork . DIRECTORY_SEPARATOR . 'country_production';
    $shadow = $restoreWork . DIRECTORY_SEPARATOR . 'country_shadow';
    $backupRoot = $base . DIRECTORY_SEPARATOR . 'backup_root';
    @mkdir($cpr, 0775, true);
    @mkdir($shadow, 0775, true);
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

function cpr_wl_cleanup(string $base): void
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
function cpr_wl_reports(): array
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
function cpr_wl_fps(array $reports, string $pkgFp): array
{
    return [
        'schema_revision_expected' => 121,
        'boundary_policy_version' => 'C1.1',
        'dependency_graph_version' => '1',
        'registry_revision' => 121,
        'c4_report_hash' => orange_cpr_gate_report_hash($reports['c4']),
        'c5_report_hash' => orange_cpr_gate_report_hash($reports['c5']),
        'c6_report_hash' => orange_cpr_gate_report_hash($reports['c6']),
        'c7_report_hash' => orange_cpr_gate_report_hash($reports['c7']),
        'c8_report_hash' => orange_cpr_gate_report_hash($reports['c8']),
        'c8_overall_result' => 'SAFE',
        'inventory_snapshot_id' => 'inv-1',
        'inventory_snapshot_hash' => str_repeat('1', 32),
        'production_db_identity_hash' => str_repeat('2', 32),
        'package_fingerprint' => $pkgFp,
    ];
}

/**
 * @return array<string, mixed>
 */
function cpr_wl_witnesses(array $extra = []): array
{
    return array_merge([
        'survivor_baseline_hash' => str_repeat('s', 32),
        'global_baseline_hash' => str_repeat('g', 32),
        'target_inventory_hash' => str_repeat('t', 32),
        'inventory_snapshot_id' => 'inv-1',
        'inventory_snapshot_hash' => str_repeat('1', 32),
        'captured_at' => gmdate('c'),
        'schema_revision' => 121,
        'package_fingerprint' => '',
        'c8_overall_result' => 'SAFE',
    ], $extra);
}

/**
 * Full path through authority; removes scaffold CP5 so live capture can write real CP5.
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function cpr_wl_setup_authorized(array $env): array
{
    $reports = cpr_wl_reports();
    $fp = hash('sha256', 'fp-wl-' . bin2hex(random_bytes(4)));
    $pkgId = 'pkg-wl-' . substr($fp, 0, 8);
    $job = orange_cpr_job_create($env, [
        'package_id' => $pkgId,
        'package_fingerprint' => $fp,
        'country_id' => 1,
        'country_code' => 'KW',
        'workflow' => 'A',
    ], 1);
    $jid = (string) $job['job_id'];
    $fps = cpr_wl_fps($reports, $fp);

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
            'c4' => $fps['c4_report_hash'],
            'c5' => $fps['c5_report_hash'],
            'c6' => $fps['c6_report_hash'],
            'c7' => $fps['c7_report_hash'],
            'c8' => $fps['c8_report_hash'],
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
    orange_cpr_contract_freeze_initial($env, $jid, $fps, 1);
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
        throw new RuntimeException('maint failed: ' . (string) ($maint['code'] ?? ''));
    }
    $pin = orange_cpr_od_pin_live_run($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
    ]);
    if (empty($pin['ok'])) {
        throw new RuntimeException('od-pin failed: ' . (string) ($pin['code'] ?? ''));
    }
    $sessionId = (string) ($pin['session_full_backup_id'] ?? '');

    $acq = orange_cpr_lock_live_acquire($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'owner_class' => 'super_admin_procedure',
        'worker_id' => 'wl-worker-1',
    ]);
    if (empty($acq['ok'])) {
        throw new RuntimeException('lock failed: ' . (string) ($acq['code'] ?? ''));
    }
    $lease = (string) ($acq['lease_token'] ?? '');
    $worker = (string) ($acq['worker_id'] ?? 'wl-worker-1');

    // Scaffold runbook + CP5 for G28 during gates (provisional; removed after authority).
    orange_cpr_checkpoint_create($env, $jid, 'runbook_pre_ponr', [
        'restore_package_id' => $pkgId,
        'target_country_id' => 1,
        'target_country_code' => 'KW',
        'c8_overall_result' => 'SAFE',
        'certified_inventory_snapshot_id' => 'inv-1',
        'session_full_backup_id' => $sessionId,
        'global_maintenance_active' => true,
        'completed_by_admin_id' => 7,
        'completed_at' => gmdate('c'),
        'audit_record_id' => 'aud-wl-1',
    ], ['written_by' => 'super_admin']);
    orange_cpr_checkpoint_create($env, $jid, 'CP5', [
        'survivor_baseline_hash' => str_repeat('s', 32),
        'global_baseline_hash' => str_repeat('g', 32),
        'target_inventory_hash' => str_repeat('t', 32),
        'inventory_snapshot_id' => 'inv-1',
        'captured_at' => gmdate('c'),
    ], ['written_by' => 'system']);

    $evidence = [
        'enablement' => true,
        'od_enable_preconditions_complete' => true,
        'pin_capability' => true,
        'certification' => ['result' => 'PASS'],
        'host_preflight' => ['pass' => true],
        'package' => ['status' => 'finalized', 'package_id' => $pkgId, 'fingerprint' => $fp],
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
        'authority' => ['wfa_protections_ack' => true, 'country_admin_is_executor' => false],
        'phrase' => [
            'phrase_ok' => true,
            'phrase' => 'RESTORE',
            'reauth_ok' => true,
            'one_time_authorization_id' => 'ota-pending',
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
    ];

    $gate = orange_cpr_gates_live_evaluate($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'evidence' => $evidence,
    ]);
    if (empty($gate['ok'])) {
        throw new RuntimeException('gates_live failed: ' . (string) ($gate['code'] ?? ''));
    }

    $auth = orange_cpr_authority_live_ceremony($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'all_minimum_items_confirmed' => true,
        'phrase' => 'RESTORE',
        'password_reauth_ok' => true,
    ]);
    if (empty($auth['ok'])) {
        throw new RuntimeException('authority_live failed: ' . (string) ($auth['code'] ?? ''));
    }

    // Remove provisional CP5 so live capture writes the production CP5.
    $cprRoot = orange_cpr_resolve_work_root($env);
    $cp5Path = orange_cpr_checkpoint_final_path($cprRoot, $jid, 'CP5');
    if (is_file($cp5Path)) {
        @unlink($cp5Path);
    }

    return [
        'job_id' => $jid,
        'lease' => $lease,
        'worker_id' => $worker,
        'package_id' => $pkgId,
        'package_fingerprint' => $fp,
        'session_full_backup_id' => $sessionId,
        'reports' => $reports,
    ];
}

/**
 * @param array<string, mixed> $setup
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function cpr_wl_req(array $setup, array $extra = []): array
{
    $wit = cpr_wl_witnesses([
        'package_fingerprint' => (string) $setup['package_fingerprint'],
    ]);
    if (isset($extra['witnesses']) && is_array($extra['witnesses'])) {
        $wit = array_merge($wit, $extra['witnesses']);
        unset($extra['witnesses']);
    }

    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setup['lease'],
        'worker_id' => (string) $setup['worker_id'],
        'witnesses' => $wit,
    ], $extra);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $setup
 */
function cpr_wl_release(array $env, array $setup): void
{
    orange_cpr_lock_release($env, (string) $setup['job_id'], [
        'lease_token' => (string) $setup['lease'],
        'worker_id' => (string) $setup['worker_id'],
    ]);
}

$bundle = cpr_wl_env();
$env = $bundle['env'];
$base = $bundle['base'];
$cprRoot = $bundle['cpr'];

try {
    cpr_wl('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P4-08-witnesses-live');

    // --- Complete valid witness path ---
    $setup = cpr_wl_setup_authorized($env);
    $ok = orange_cpr_witnesses_live_ceremony($env, $setup['job_id'], cpr_wl_req($setup));
    cpr_wl('complete_valid_witness_path', !empty($ok['ok']) && !empty($ok['last_fully_reversible_idle_point']), (string) ($ok['code'] ?? $ok['message'] ?? ''));
    cpr_wl('sealed_witness_bundle', is_array($ok['witness_bundle'] ?? null) && orange_cpr_auth_verify_seal($ok['witness_bundle']));
    cpr_wl('sealed_cp5', orange_cpr_checkpoint_exists($cprRoot, $setup['job_id'], 'CP5'));
    cpr_wl('sealed_cpa_checkpoint', orange_cpr_checkpoint_exists($cprRoot, $setup['job_id'], 'CP-A'));
    cpr_wl('sealed_cpa_live', is_array($ok['cpa_live'] ?? null) && orange_cpr_auth_verify_seal($ok['cpa_live']));
    cpr_wl('cpa_no_ponr_mutation', empty($ok['ponr_crossed']) && empty($ok['ponr_mutation_executed']));
    cpr_wl('cpa_no_auto_continue', ($ok['auto_continue_beyond_cpa'] ?? null) === false);
    cpr_wl('enablement_false', ($ok['enablement_flag_observed'] ?? null) === false);
    $refuse = orange_cpr_ponr_mutation_refuse();
    cpr_wl('proof_cpa_no_ponr_mutation', empty($refuse['ok']) && ($refuse['code'] ?? '') === 'ponr_mutation_forbidden');
    cpr_wl_release($env, $setup);

    // --- Missing witness ---
    $setupM = cpr_wl_setup_authorized($env);
    $miss = orange_cpr_witnesses_live_ceremony($env, $setupM['job_id'], cpr_wl_req($setupM, [
        'witnesses' => ['survivor_baseline_hash' => '', 'global_baseline_hash' => str_repeat('g', 32)],
    ]));
    cpr_wl(
        'missing_witness',
        empty($miss['ok']) && ($miss['code'] ?? '') === ORANGE_CPR_WITNESSLIVE_ERR_MISSING,
        (string) ($miss['code'] ?? '')
    );
    cpr_wl_release($env, $setupM);

    // --- Stale witness ---
    $setupSt = cpr_wl_setup_authorized($env);
    $stale = orange_cpr_witnesses_live_ceremony($env, $setupSt['job_id'], cpr_wl_req($setupSt, [
        'stale_witness' => true,
    ]));
    cpr_wl(
        'stale_witness',
        empty($stale['ok']) && ($stale['code'] ?? '') === ORANGE_CPR_WITNESSLIVE_ERR_STALE,
        (string) ($stale['code'] ?? '')
    );
    cpr_wl_release($env, $setupSt);

    // --- Corrupt witness ---
    $setupR = cpr_wl_setup_authorized($env);
    $corr = orange_cpr_witnesses_live_ceremony($env, $setupR['job_id'], cpr_wl_req($setupR, [
        'witnesses_corrupt' => true,
    ]));
    cpr_wl(
        'corrupt_witness',
        empty($corr['ok']) && ($corr['code'] ?? '') === ORANGE_CPR_WITNESSLIVE_ERR_CORRUPT,
        (string) ($corr['code'] ?? '')
    );
    cpr_wl_release($env, $setupR);

    // --- Fingerprint mismatch ---
    $setupF = cpr_wl_setup_authorized($env);
    $fpMiss = orange_cpr_witnesses_live_ceremony($env, $setupF['job_id'], cpr_wl_req($setupF, [
        'witnesses' => ['inventory_snapshot_id' => 'inv-WRONG'],
    ]));
    cpr_wl(
        'fingerprint_mismatch',
        empty($fpMiss['ok']) && in_array(($fpMiss['code'] ?? ''), [
            ORANGE_CPR_WITNESSLIVE_ERR_INVENTORY,
            ORANGE_CPR_WITNESSLIVE_ERR_FINGERPRINT,
        ], true),
        (string) ($fpMiss['code'] ?? '')
    );
    cpr_wl_release($env, $setupF);

    // --- Survivor witness failure ---
    $setupS = cpr_wl_setup_authorized($env);
    $surv = orange_cpr_witnesses_live_ceremony($env, $setupS['job_id'], cpr_wl_req($setupS, [
        'witnesses' => ['survivor_failed' => true],
    ]));
    cpr_wl(
        'survivor_witness_failure',
        empty($surv['ok']) && ($surv['code'] ?? '') === ORANGE_CPR_WITNESSLIVE_ERR_SURVIVOR,
        (string) ($surv['code'] ?? '')
    );
    cpr_wl_release($env, $setupS);

    // --- Global witness failure ---
    $setupG = cpr_wl_setup_authorized($env);
    $glob = orange_cpr_witnesses_live_ceremony($env, $setupG['job_id'], cpr_wl_req($setupG, [
        'witnesses' => ['global_failed' => true],
    ]));
    cpr_wl(
        'global_witness_failure',
        empty($glob['ok']) && ($glob['code'] ?? '') === ORANGE_CPR_WITNESSLIVE_ERR_GLOBAL,
        (string) ($glob['code'] ?? '')
    );
    cpr_wl_release($env, $setupG);

    // --- Replay attempt ---
    $setupRp = cpr_wl_setup_authorized($env);
    $first = orange_cpr_witnesses_live_ceremony($env, $setupRp['job_id'], cpr_wl_req($setupRp));
    cpr_wl('replay_setup_ok', !empty($first['ok']));
    $replay = orange_cpr_witnesses_live_capture($env, $setupRp['job_id'], cpr_wl_req($setupRp, [
        'replay_previous' => true,
    ]));
    cpr_wl(
        'replay_attempt',
        empty($replay['ok']) && ($replay['code'] ?? '') === ORANGE_CPR_WITNESSLIVE_ERR_REPLAY,
        (string) ($replay['code'] ?? '')
    );
    cpr_wl_release($env, $setupRp);

    // --- Invalid state ---
    $setupI = cpr_wl_setup_authorized($env);
    $cprI = orange_cpr_resolve_work_root($env);
    $jobI = orange_cpr_job_read($cprI, $setupI['job_id']);
    $jobI['state'] = 'cpr_maintenance_on';
    orange_cpr_job_write($cprI, $setupI['job_id'], $jobI);
    $badState = orange_cpr_witnesses_live_ceremony($env, $setupI['job_id'], cpr_wl_req($setupI));
    cpr_wl(
        'invalid_state',
        empty($badState['ok']) && ($badState['code'] ?? '') === ORANGE_CPR_WITNESSLIVE_ERR_STATE,
        (string) ($badState['code'] ?? '')
    );
    cpr_wl_release($env, $setupI);

    // --- Missing gate PASS ---
    $setupGate = cpr_wl_setup_authorized($env);
    $glPath = orange_cpr_gates_live_latest_path($cprRoot, $setupGate['job_id']);
    if (is_file($glPath)) {
        @unlink($glPath);
    }
    $noGate = orange_cpr_witnesses_live_ceremony($env, $setupGate['job_id'], cpr_wl_req($setupGate));
    cpr_wl(
        'missing_gate_pass',
        empty($noGate['ok']) && ($noGate['code'] ?? '') === ORANGE_CPR_WITNESSLIVE_ERR_GATE,
        (string) ($noGate['code'] ?? '')
    );
    cpr_wl_release($env, $setupGate);

    // --- Missing authority ceremony ---
    $setupAuth = cpr_wl_setup_authorized($env);
    $alPath = orange_cpr_auth_live_latest_path($cprRoot, $setupAuth['job_id']);
    if (is_file($alPath)) {
        @unlink($alPath);
    }
    $noAuth = orange_cpr_witnesses_live_ceremony($env, $setupAuth['job_id'], cpr_wl_req($setupAuth));
    cpr_wl(
        'missing_authority_ceremony',
        empty($noAuth['ok']) && ($noAuth['code'] ?? '') === ORANGE_CPR_WITNESSLIVE_ERR_AUTH,
        (string) ($noAuth['code'] ?? '')
    );
    cpr_wl_release($env, $setupAuth);

    // --- Missing / invalid session pin ---
    $setupPin = cpr_wl_setup_authorized($env);
    $pinPath = orange_cpr_od_pin_live_pin_latest_path($cprRoot, $setupPin['job_id']);
    if (is_file($pinPath)) {
        @unlink($pinPath);
    }
    $noPin = orange_cpr_witnesses_live_ceremony($env, $setupPin['job_id'], cpr_wl_req($setupPin));
    cpr_wl(
        'missing_or_invalid_session_pin',
        empty($noPin['ok']) && ($noPin['code'] ?? '') === ORANGE_CPR_WITNESSLIVE_ERR_PIN,
        (string) ($noPin['code'] ?? '')
    );
    cpr_wl_release($env, $setupPin);

    // --- CP-A duplicate attempt ---
    $setupDup = cpr_wl_setup_authorized($env);
    $dup1 = orange_cpr_witnesses_live_ceremony($env, $setupDup['job_id'], cpr_wl_req($setupDup));
    cpr_wl('cpa_dup_setup_ok', !empty($dup1['ok']));
    $dup2 = orange_cpr_witnesses_live_commit_cpa($env, $setupDup['job_id'], cpr_wl_req($setupDup));
    cpr_wl(
        'cpa_duplicate_attempt',
        empty($dup2['ok']) && ($dup2['code'] ?? '') === ORANGE_CPR_WITNESSLIVE_ERR_DUPLICATE,
        (string) ($dup2['code'] ?? '')
    );
    cpr_wl_release($env, $setupDup);

    cpr_wl('ops_enablement_still_false', orange_cpr_enablement_flag_read($env) === false);
} catch (Throwable $e) {
    cpr_wl('exception', false, $e->getMessage());
} finally {
    cpr_wl_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
