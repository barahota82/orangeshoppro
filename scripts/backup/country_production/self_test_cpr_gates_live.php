<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Gate Evaluation & Pre-PONR Readiness (WP-P4-06).
 * Run: php scripts/backup/country_production/self_test_cpr_gates_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_gates_live.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_state_engine.php';

$pass = 0;
$fail = 0;

function cpr_gl(string $name, bool $ok, string $detail = ''): void
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
function cpr_gl_env(): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p406_' . bin2hex(random_bytes(4));
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

function cpr_gl_cleanup(string $base): void
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
function cpr_gl_reports(): array
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
function cpr_gl_fps(array $reports, string $pkgFp): array
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
 * @param array<string, mixed> $reports
 * @param array<string, mixed> $jobMeta
 * @return array<string, mixed>
 */
function cpr_gl_evidence(array $reports, array $jobMeta = []): array
{
    $pkgId = (string) ($jobMeta['package_id'] ?? 'pkg-gl');
    $fp = (string) ($jobMeta['package_fingerprint'] ?? str_repeat('a', 32));
    $sessionId = (string) ($jobMeta['session_full_backup_id'] ?? 'fb-live');

    return [
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
        'authority' => [
            'wfa_protections_ack' => true,
            'country_admin_is_executor' => false,
        ],
        'phrase' => [
            'phrase_ok' => true,
            'phrase' => 'RESTORE',
            'reauth_ok' => true,
            'one_time_authorization_id' => 'ota-gl-1',
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
        'session_hint' => $sessionId,
    ];
}

/**
 * Job through maint + OD-PIN + lock + runbook/CP5 scaffolding for full gate PASS.
 *
 * @param array<string, mixed> $env
 * @return array{job_id:string,lease:string,worker_id:string,reports:array,package_id:string,package_fingerprint:string,session_full_backup_id:string}
 */
function cpr_gl_setup_ready(array $env): array
{
    $reports = cpr_gl_reports();
    $fp = hash('sha256', 'fp-gl-' . bin2hex(random_bytes(4)));
    $pkgId = 'pkg-gl-' . substr($fp, 0, 8);
    $job = orange_cpr_job_create($env, [
        'package_id' => $pkgId,
        'package_fingerprint' => $fp,
        'country_id' => 1,
        'country_code' => 'KW',
        'workflow' => 'A',
    ], 1);
    $jid = (string) $job['job_id'];
    $fps = cpr_gl_fps($reports, $fp);

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
        throw new RuntimeException('maint setup failed: ' . (string) ($maint['code'] ?? ''));
    }
    $pin = orange_cpr_od_pin_live_run($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
    ]);
    if (empty($pin['ok'])) {
        throw new RuntimeException('od-pin setup failed: ' . (string) ($pin['code'] ?? ''));
    }
    $sessionId = (string) ($pin['session_full_backup_id'] ?? '');

    $acq = orange_cpr_lock_live_acquire($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'owner_class' => 'super_admin_procedure',
        'worker_id' => 'gl-worker-1',
    ]);
    if (empty($acq['ok'])) {
        throw new RuntimeException('lock setup failed: ' . (string) ($acq['code'] ?? ''));
    }

    // Scaffold runbook + CP5 (G27/G28) â€” witnesses live in WP-P4-08; predicates require checkpoints.
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
        'audit_record_id' => 'aud-gl-1',
    ], ['written_by' => 'super_admin']);
    orange_cpr_checkpoint_create($env, $jid, 'CP5', [
        'survivor_baseline_hash' => str_repeat('s', 32),
        'global_baseline_hash' => str_repeat('g', 32),
        'target_inventory_hash' => str_repeat('t', 32),
        'inventory_snapshot_id' => 'inv-1',
        'captured_at' => gmdate('c'),
    ], ['written_by' => 'system']);

    return [
        'job_id' => $jid,
        'lease' => (string) ($acq['lease_token'] ?? ''),
        'worker_id' => (string) ($acq['worker_id'] ?? 'gl-worker-1'),
        'reports' => $reports,
        'package_id' => $pkgId,
        'package_fingerprint' => $fp,
        'session_full_backup_id' => $sessionId,
    ];
}

/**
 * @param array<string, mixed> $setup
 * @param array<string, mixed> $evidenceExtra
 * @return array<string, mixed>
 */
function cpr_gl_req(array $setup, array $evidenceExtra = []): array
{
    $ev = cpr_gl_evidence($setup['reports'], $setup);
    foreach ($evidenceExtra as $k => $v) {
        $ev[$k] = $v;
    }

    return [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setup['lease'],
        'worker_id' => (string) $setup['worker_id'],
        'evidence' => $ev,
    ];
}

$bundle = cpr_gl_env();
$env = $bundle['env'];
$base = $bundle['base'];
$cprRoot = $bundle['cpr'];

try {
    cpr_gl('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P6-01-control-plane');

    // --- Every mandatory gate FAIL (P3 predicates via evaluate_one; fail-inducing ctx) ---
    $gateFailCount = 0;
    foreach (orange_cpr_gate_ids_pre_ponr_full() as $gid) {
        $failCtx = [
            'env' => $env,
            'job' => [],
            'contract' => [],
            'committed_checkpoints' => [],
            'evidence' => [],
            'lock' => null,
            'full_dr_active' => false,
            'c6_active' => false,
            'backup_runner_active' => false,
        ];
        // G05 / G30 are negative-condition gates (PASS when peers/stop clear).
        if ($gid === 'G05') {
            $failCtx['full_dr_active'] = true;
        }
        if ($gid === 'G30') {
            $failCtx['evidence']['emergency_stop'] = true;
        }
        $one = orange_cpr_gate_evaluate_one($gid, $failCtx);
        if (($one['result'] ?? '') === ORANGE_CPR_GATE_FAIL) {
            ++$gateFailCount;
        } else {
            cpr_gl('mandatory_gate_fail_' . $gid, false, (string) ($one['result'] ?? '?'));
        }
    }
    cpr_gl(
        'every_mandatory_gate_fail',
        $gateFailCount === count(orange_cpr_gate_ids_pre_ponr_full()),
        (string) $gateFailCount
    );

    // --- Complete PASS ---
    $setup = cpr_gl_setup_ready($env);
    $jid = $setup['job_id'];
    $ok = orange_cpr_gates_live_evaluate($env, $jid, cpr_gl_req($setup));
    cpr_gl('complete_pass', !empty($ok['ok']) && !empty($ok['all_gates_pass']), (string) ($ok['code'] ?? $ok['message'] ?? ''));
    cpr_gl('complete_pass_ponr_auth', !empty($ok['ponr_authorized']));
    cpr_gl('complete_pass_no_ponr_mutation', empty($ok['ponr_mutation_executed']) && empty($ok['ponr_crossed']));
    cpr_gl('complete_pass_enablement_false', ($ok['enablement_flag_observed'] ?? null) === false);
    cpr_gl('mandatory_gate_count', (int) ($ok['mandatory_gate_count'] ?? 0) === count(orange_cpr_gate_ids_pre_ponr_full()));
    $latest = orange_cpr_gates_live_load_latest($cprRoot, $jid);
    cpr_gl('sealed_live_report', is_array($latest) && orange_cpr_auth_verify_seal($latest));
    cpr_gl('p3_eval_sealed', !empty($ok['evaluation']) && orange_cpr_gate_evaluation_verify_seal($ok['evaluation']));
    cpr_gl('lock_revalidated', !empty($ok['lock_revalidated']));

    // Release lock so subsequent jobs can acquire.
    orange_cpr_lock_release($env, $jid, [
        'lease_token' => (string) $setup['lease'],
        'worker_id' => (string) $setup['worker_id'],
    ]);

    // --- Bypass / skip / WARNING / replay ---
    $setupB = cpr_gl_setup_ready($env);
    $bypass = orange_cpr_gates_live_evaluate($env, $setupB['job_id'], array_merge(cpr_gl_req($setupB), [
        'force_pass' => true,
    ]));
    cpr_gl(
        'bypass_forbidden',
        empty($bypass['ok']) && ($bypass['code'] ?? '') === ORANGE_CPR_GATELIVE_ERR_BYPASS,
        (string) ($bypass['code'] ?? '')
    );
    orange_cpr_lock_release($env, $setupB['job_id'], [
        'lease_token' => (string) $setupB['lease'],
        'worker_id' => (string) $setupB['worker_id'],
    ]);

    $setupS = cpr_gl_setup_ready($env);
    $skip = orange_cpr_gates_live_evaluate($env, $setupS['job_id'], array_merge(cpr_gl_req($setupS), [
        'skip_gates' => true,
    ]));
    cpr_gl(
        'skip_forbidden',
        empty($skip['ok']) && ($skip['code'] ?? '') === ORANGE_CPR_GATELIVE_ERR_SKIP,
        (string) ($skip['code'] ?? '')
    );
    orange_cpr_lock_release($env, $setupS['job_id'], [
        'lease_token' => (string) $setupS['lease'],
        'worker_id' => (string) $setupS['worker_id'],
    ]);

    $setupW = cpr_gl_setup_ready($env);
    $warn = orange_cpr_gates_live_evaluate($env, $setupW['job_id'], array_merge(cpr_gl_req($setupW), [
        'accept_warning' => true,
    ]));
    cpr_gl(
        'warning_path_forbidden',
        empty($warn['ok']) && ($warn['code'] ?? '') === ORANGE_CPR_GATELIVE_ERR_WARNING,
        (string) ($warn['code'] ?? '')
    );
    orange_cpr_lock_release($env, $setupW['job_id'], [
        'lease_token' => (string) $setupW['lease'],
        'worker_id' => (string) $setupW['worker_id'],
    ]);

    $setupR = cpr_gl_setup_ready($env);
    $replay = orange_cpr_gates_live_evaluate($env, $setupR['job_id'], array_merge(cpr_gl_req($setupR), [
        'replay_previous' => true,
    ]));
    cpr_gl(
        'replay_forbidden',
        empty($replay['ok']) && ($replay['code'] ?? '') === ORANGE_CPR_GATELIVE_ERR_REPLAY,
        (string) ($replay['code'] ?? '')
    );
    $cached = orange_cpr_gates_live_evaluate($env, $setupR['job_id'], array_merge(cpr_gl_req($setupR), [
        'use_cached_report_only' => true,
    ]));
    cpr_gl(
        'replay_cached_forbidden',
        empty($cached['ok']) && ($cached['code'] ?? '') === ORANGE_CPR_GATELIVE_ERR_REPLAY,
        (string) ($cached['code'] ?? '')
    );
    orange_cpr_lock_release($env, $setupR['job_id'], [
        'lease_token' => (string) $setupR['lease'],
        'worker_id' => (string) $setupR['worker_id'],
    ]);

    // --- Missing evidence (reports) ---
    $setupM = cpr_gl_setup_ready($env);
    $reqM = cpr_gl_req($setupM);
    unset($reqM['evidence']['reports']);
    $miss = orange_cpr_gates_live_evaluate($env, $setupM['job_id'], $reqM);
    cpr_gl(
        'missing_evidence',
        empty($miss['ok']) && ($miss['code'] ?? '') === ORANGE_CPR_GATELIVE_ERR_EVIDENCE,
        (string) ($miss['code'] ?? '')
    );
    orange_cpr_lock_release($env, $setupM['job_id'], [
        'lease_token' => (string) $setupM['lease'],
        'worker_id' => (string) $setupM['worker_id'],
    ]);

    // --- Corrupt evidence ---
    $setupC = cpr_gl_setup_ready($env);
    $corr = orange_cpr_gates_live_evaluate($env, $setupC['job_id'], cpr_gl_req($setupC, [
        'reports_corrupt' => true,
    ]));
    cpr_gl(
        'corrupt_evidence',
        empty($corr['ok']) && ($corr['code'] ?? '') === ORANGE_CPR_GATELIVE_ERR_CORRUPT,
        (string) ($corr['code'] ?? '')
    );
    orange_cpr_lock_release($env, $setupC['job_id'], [
        'lease_token' => (string) $setupC['lease'],
        'worker_id' => (string) $setupC['worker_id'],
    ]);

    // --- Stale evidence ---
    $setupSt = cpr_gl_setup_ready($env);
    $stale = orange_cpr_gates_live_evaluate($env, $setupSt['job_id'], cpr_gl_req($setupSt, [
        'stale_evidence' => true,
    ]));
    cpr_gl(
        'stale_evidence',
        empty($stale['ok']) && ($stale['code'] ?? '') === ORANGE_CPR_GATELIVE_ERR_STALE,
        (string) ($stale['code'] ?? '')
    );
    orange_cpr_lock_release($env, $setupSt['job_id'], [
        'lease_token' => (string) $setupSt['lease'],
        'worker_id' => (string) $setupSt['worker_id'],
    ]);

    // --- Fingerprint drift ---
    $setupF = cpr_gl_setup_ready($env);
    $driftReports = $setupF['reports'];
    $driftReports['c4'] = array_merge($driftReports['c4'], ['tampered' => true]);
    $fpDrift = orange_cpr_gates_live_evaluate($env, $setupF['job_id'], cpr_gl_req($setupF, [
        'reports' => $driftReports,
    ]));
    cpr_gl(
        'fingerprint_drift',
        empty($fpDrift['ok']) && ($fpDrift['code'] ?? '') === ORANGE_CPR_GATELIVE_ERR_FINGERPRINT,
        (string) ($fpDrift['code'] ?? '')
    );
    orange_cpr_lock_release($env, $setupF['job_id'], [
        'lease_token' => (string) $setupF['lease'],
        'worker_id' => (string) $setupF['worker_id'],
    ]);

    // --- Lock drift ---
    $setupL = cpr_gl_setup_ready($env);
    $lockDrift = orange_cpr_gates_live_evaluate($env, $setupL['job_id'], array_merge(cpr_gl_req($setupL), [
        'lease_token' => 'wrong-lease-token-value',
    ]));
    cpr_gl(
        'lock_drift',
        empty($lockDrift['ok']) && ($lockDrift['code'] ?? '') === ORANGE_CPR_GATELIVE_ERR_LOCK,
        (string) ($lockDrift['code'] ?? '')
    );
    orange_cpr_lock_release($env, $setupL['job_id'], [
        'lease_token' => (string) $setupL['lease'],
        'worker_id' => (string) $setupL['worker_id'],
    ]);

    // --- Session pin missing (OD-PIN artifact removed) ---
    $setupP = cpr_gl_setup_ready($env);
    $pinPath = orange_cpr_od_pin_live_pin_latest_path($cprRoot, $setupP['job_id']);
    if (is_file($pinPath)) {
        @unlink($pinPath);
    }
    $pinMiss = orange_cpr_gates_live_evaluate($env, $setupP['job_id'], cpr_gl_req($setupP));
    cpr_gl(
        'session_pin_missing',
        empty($pinMiss['ok']) && ($pinMiss['code'] ?? '') === ORANGE_CPR_GATELIVE_ERR_PIN,
        (string) ($pinMiss['code'] ?? '')
    );
    orange_cpr_lock_release($env, $setupP['job_id'], [
        'lease_token' => (string) $setupP['lease'],
        'worker_id' => (string) $setupP['worker_id'],
    ]);

    // --- Schema mismatch ---
    $setupSch = cpr_gl_setup_ready($env);
    $sch = orange_cpr_gates_live_evaluate($env, $setupSch['job_id'], cpr_gl_req($setupSch, [
        'live_sot' => [
            'schema_revision' => 999,
            'boundary_policy_version' => 'C1.1',
            'dependency_graph_version' => '1',
            'registry_revision' => 121,
            'production_db_identity_hash' => str_repeat('2', 32),
        ],
    ]));
    cpr_gl(
        'schema_mismatch',
        empty($sch['ok']) && ($sch['code'] ?? '') === ORANGE_CPR_GATELIVE_ERR_SCHEMA,
        (string) ($sch['code'] ?? '')
    );
    orange_cpr_lock_release($env, $setupSch['job_id'], [
        'lease_token' => (string) $setupSch['lease'],
        'worker_id' => (string) $setupSch['worker_id'],
    ]);

    // --- C8 not SAFE ---
    $setupC8 = cpr_gl_setup_ready($env);
    $badC8 = $setupC8['reports'];
    $badC8['c8'] = array_merge($badC8['c8'], ['overall_result' => 'UNSAFE']);
    // C8 change also breaks fingerprint â€” validate_evidence checks C8 SAFE before hash loop for c8...
    // Actually order: C8 SAFE check first, then hash loop. UNSAFE â†’ gatelive_c8_not_safe.
    $c8fail = orange_cpr_gates_live_evaluate($env, $setupC8['job_id'], cpr_gl_req($setupC8, [
        'reports' => $badC8,
    ]));
    cpr_gl(
        'c8_not_safe',
        empty($c8fail['ok']) && ($c8fail['code'] ?? '') === ORANGE_CPR_GATELIVE_ERR_C8,
        (string) ($c8fail['code'] ?? '')
    );
    orange_cpr_lock_release($env, $setupC8['job_id'], [
        'lease_token' => (string) $setupC8['lease'],
        'worker_id' => (string) $setupC8['worker_id'],
    ]);

    // --- Inventory snapshot missing ---
    $setupInv = cpr_gl_setup_ready($env);
    $inv = orange_cpr_gates_live_evaluate($env, $setupInv['job_id'], cpr_gl_req($setupInv, [
        'inventory' => ['id' => '', 'hash' => '', 'certified_read_only' => true],
    ]));
    cpr_gl(
        'inventory_snapshot_missing',
        empty($inv['ok']) && ($inv['code'] ?? '') === ORANGE_CPR_GATELIVE_ERR_INVENTORY,
        (string) ($inv['code'] ?? '')
    );
    orange_cpr_lock_release($env, $setupInv['job_id'], [
        'lease_token' => (string) $setupInv['lease'],
        'worker_id' => (string) $setupInv['worker_id'],
    ]);

    // --- Suite FAIL path (evidence enablement false â†’ G01 FAIL, still seals live report) ---
    $setupFail = cpr_gl_setup_ready($env);
    $suiteFail = orange_cpr_gates_live_evaluate($env, $setupFail['job_id'], cpr_gl_req($setupFail, [
        'enablement' => false,
        'od_enable_preconditions_complete' => false,
    ]));
    cpr_gl(
        'suite_fail_closed',
        empty($suiteFail['ok']) && ($suiteFail['code'] ?? '') === ORANGE_CPR_GATELIVE_ERR_SUITE,
        (string) ($suiteFail['code'] ?? '')
    );
    cpr_gl('suite_fail_sealed', is_file((string) ($suiteFail['gates_live_path'] ?? '')));
    cpr_gl('suite_fail_no_ponr_auth', empty($suiteFail['ponr_authorized']));
    orange_cpr_lock_release($env, $setupFail['job_id'], [
        'lease_token' => (string) $setupFail['lease'],
        'worker_id' => (string) $setupFail['worker_id'],
    ]);

    // Ops enablement remains false on env
    cpr_gl('ops_enablement_still_false', orange_cpr_enablement_flag_read($env) === false);
} catch (Throwable $e) {
    cpr_gl('exception', false, $e->getMessage());
} finally {
    cpr_gl_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
