<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Special Handlers (WP-P5-04).
 * Run: php scripts/backup/country_production/self_test_cpr_special_handlers_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_special_handlers_live.php';

$pass = 0;
$fail = 0;

function cpr_sh(string $name, bool $ok, string $detail = ''): void
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
 * @return array{env:array<string,mixed>,cpr:string}
 */
function cpr_sh_fresh_env(string $base): array
{
    $id = bin2hex(random_bytes(3));
    $restoreWork = $base . DIRECTORY_SEPARATOR . 'rw_' . $id;
    $cpr = $restoreWork . DIRECTORY_SEPARATOR . 'country_production';
    $shadow = $restoreWork . DIRECTORY_SEPARATOR . 'country_shadow';
    $backupRoot = $base . DIRECTORY_SEPARATOR . 'br_' . $id;
    @mkdir($cpr, 0775, true);
    @mkdir($shadow, 0775, true);
    @mkdir($backupRoot . DIRECTORY_SEPARATOR . 'locks', 0775, true);

    return [
        'cpr' => $cpr,
        'env' => [
            'ORANGE_CPR_WORK_DIR' => $cpr,
            'ORANGE_RESTORE_WORK_DIR' => $restoreWork,
            'ORANGE_BACKUP_ROOT' => $backupRoot,
            'ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false,
        ],
    ];
}

function cpr_sh_cleanup(string $base): void
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
function cpr_sh_slice(): array
{
    return [
        'country_id' => 1,
        'country_code' => 'KW',
        'delete_order_version' => ORANGE_CPR_DELETE_ORDER_VERSION,
        'tables' => [
            ['table' => 'order_items', 'membership_key' => 'country_id', 'row_ids' => ['11', '12']],
            ['table' => 'orders', 'membership_key' => 'country_id', 'row_ids' => ['1']],
            ['table' => 'product_channels', 'membership_key' => 'country_id', 'row_ids' => ['pc1']],
            ['table' => 'products', 'membership_key' => 'country_id', 'row_ids' => ['p1']],
            ['table' => 'storefront_accounts', 'membership_key' => 'country_id', 'row_ids' => ['sa1']],
        ],
    ];
}

/**
 * Full CPA → DELETE → IMPORT fixture (reuses proven P5 path).
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function cpr_sh_setup_through_import(array $env): array
{
    $reports = [
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
    $fp = hash('sha256', 'fp-sh-' . bin2hex(random_bytes(4)));
    $pkgId = 'pkg-sh-' . substr($fp, 0, 8);
    $job = orange_cpr_job_create($env, [
        'package_id' => $pkgId,
        'package_fingerprint' => $fp,
        'country_id' => 1,
        'country_code' => 'KW',
        'workflow' => 'A',
    ], 7);
    $jid = (string) $job['job_id'];
    $fps = [
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
        'package_fingerprint' => $fp,
    ];

    orange_cpr_transition_apply($env, $jid, 'cpr_gates_validating', ['actor' => ORANGE_CPR_ACTOR_SYSTEM]);
    orange_cpr_checkpoint_create($env, $jid, 'CP0', [
        'c4_overall' => 'PASS', 'c5_overall' => 'pass', 'c5_recovery_score' => 90,
        'c6_status' => 'ready', 'c7_overall' => 'READY', 'c7_readiness_score' => 95,
        'c8_overall_result' => 'SAFE', 'enablement_flag_observed' => false,
        'schema_revision_observed' => 121, 'boundary_policy_version' => 'C1.1',
        'report_hashes' => [
            'c4' => $fps['c4_report_hash'], 'c5' => $fps['c5_report_hash'],
            'c6' => $fps['c6_report_hash'], 'c7' => $fps['c7_report_hash'],
            'c8' => $fps['c8_report_hash'],
        ],
    ], ['written_by' => 'system']);
    orange_cpr_transition_apply($env, $jid, 'cpr_contract_frozen', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM, 'contract_fingerprints_ok' => true,
    ]);
    orange_cpr_checkpoint_create($env, $jid, 'CP2', [
        'workflow' => 'A', 'wfa_protections_ack' => true, 'approval_fingerprint' => str_repeat('9', 32),
    ], ['written_by' => 'system']);
    orange_cpr_contract_freeze_initial($env, $jid, $fps, 7);
    orange_cpr_checkpoint_create($env, $jid, 'CP3', [
        'contract_revision' => 1, 'contract_phase' => 'pre_pin',
        'package_fingerprint' => $fp, 'fingerprint_digest' => hash('sha256', $fp),
    ], ['written_by' => 'system']);

    $maint = orange_cpr_maint_live_activate_cp4($env, $jid, [
        'actor_admin_id' => 7, 'actor_is_super_admin' => true,
        'maint_scope' => 'GLOBAL', 'write_block_proof' => 'cpr_write_block_proof:orders',
    ]);
    if (empty($maint['ok'])) {
        throw new RuntimeException('maint');
    }
    $pin = orange_cpr_od_pin_live_run($env, $jid, [
        'actor_admin_id' => 7, 'actor_is_super_admin' => true,
    ]);
    if (empty($pin['ok'])) {
        throw new RuntimeException('pin');
    }
    $acq = orange_cpr_lock_live_acquire($env, $jid, [
        'actor_admin_id' => 7, 'actor_is_super_admin' => true,
        'owner_class' => 'super_admin_procedure', 'worker_id' => 'sh-worker',
    ]);
    if (empty($acq['ok'])) {
        throw new RuntimeException('lock');
    }
    $lease = (string) ($acq['lease_token'] ?? '');
    $worker = (string) ($acq['worker_id'] ?? 'sh-worker');
    $sessionId = (string) ($pin['session_full_backup_id'] ?? '');

    orange_cpr_checkpoint_create($env, $jid, 'runbook_pre_ponr', [
        'restore_package_id' => $pkgId, 'target_country_id' => 1, 'target_country_code' => 'KW',
        'c8_overall_result' => 'SAFE', 'certified_inventory_snapshot_id' => 'inv-1',
        'session_full_backup_id' => $sessionId, 'global_maintenance_active' => true,
        'completed_by_admin_id' => 7, 'completed_at' => gmdate('c'), 'audit_record_id' => 'aud-sh-1',
    ], ['written_by' => 'super_admin']);
    orange_cpr_checkpoint_create($env, $jid, 'CP5', [
        'survivor_baseline_hash' => str_repeat('s', 32),
        'global_baseline_hash' => str_repeat('g', 32),
        'target_inventory_hash' => str_repeat('t', 32),
        'inventory_snapshot_id' => 'inv-1', 'captured_at' => gmdate('c'),
    ], ['written_by' => 'system']);

    $evidence = [
        'enablement' => true, 'od_enable_preconditions_complete' => true, 'pin_capability' => true,
        'certification' => ['result' => 'PASS'], 'host_preflight' => ['pass' => true],
        'package' => ['status' => 'finalized', 'package_id' => $pkgId, 'fingerprint' => $fp],
        'reports' => $reports,
        'inventory' => [
            'id' => 'inv-1', 'hash' => str_repeat('1', 32),
            'certified_read_only' => true, 'replaced_by_live' => false,
        ],
        'live_sot' => [
            'schema_revision' => 121, 'boundary_policy_version' => 'C1.1',
            'dependency_graph_version' => '1', 'registry_revision' => 121,
            'production_db_identity_hash' => str_repeat('2', 32),
        ],
        'authority' => ['wfa_protections_ack' => true, 'country_admin_is_executor' => false],
        'phrase' => [
            'phrase_ok' => true, 'phrase' => 'RESTORE', 'reauth_ok' => true,
            'one_time_authorization_id' => 'ota-pending', 'ota_consumed' => false,
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
        'actor_admin_id' => 7, 'actor_is_super_admin' => true,
        'lease_token' => $lease, 'worker_id' => $worker, 'evidence' => $evidence,
    ]);
    if (empty($gate['ok'])) {
        throw new RuntimeException('gates');
    }
    $auth = orange_cpr_authority_live_ceremony($env, $jid, [
        'actor_admin_id' => 7, 'actor_is_super_admin' => true,
        'lease_token' => $lease, 'worker_id' => $worker,
        'all_minimum_items_confirmed' => true, 'phrase' => 'RESTORE', 'password_reauth_ok' => true,
    ]);
    if (empty($auth['ok'])) {
        throw new RuntimeException('auth');
    }
    $cprRoot = orange_cpr_resolve_work_root($env);
    $cp5Path = orange_cpr_checkpoint_final_path($cprRoot, $jid, 'CP5');
    if (is_file($cp5Path)) {
        @unlink($cp5Path);
    }
    $wit = orange_cpr_witnesses_live_ceremony($env, $jid, [
        'actor_admin_id' => 7, 'actor_is_super_admin' => true,
        'lease_token' => $lease, 'worker_id' => $worker,
        'witnesses' => [
            'survivor_baseline_hash' => str_repeat('s', 32),
            'global_baseline_hash' => str_repeat('g', 32),
            'target_inventory_hash' => str_repeat('t', 32),
            'inventory_snapshot_id' => 'inv-1',
            'inventory_snapshot_hash' => str_repeat('1', 32),
            'captured_at' => gmdate('c'), 'schema_revision' => 121,
            'package_fingerprint' => $fp, 'c8_overall_result' => 'SAFE',
        ],
    ]);
    if (empty($wit['ok'])) {
        throw new RuntimeException('witnesses');
    }

    $del = orange_cpr_delete_live_run($env, $jid, [
        'actor_admin_id' => 7, 'actor_is_super_admin' => true,
        'lease_token' => $lease, 'worker_id' => $worker, 'target_slice' => cpr_sh_slice(),
    ]);
    if (empty($del['ok'])) {
        throw new RuntimeException('delete: ' . (string) ($del['code'] ?? ''));
    }
    $imp = orange_cpr_import_live_run($env, $jid, [
        'actor_admin_id' => 7, 'actor_is_super_admin' => true,
        'lease_token' => $lease, 'worker_id' => $worker,
        'session_full_backup_id' => $sessionId, 'schema_revision' => 121,
    ]);
    if (empty($imp['ok'])) {
        throw new RuntimeException('import: ' . (string) ($imp['code'] ?? ''));
    }

    return [
        'job_id' => $jid,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'package_fingerprint' => $fp,
        'cpr_root' => $cprRoot,
        'session_full_backup_id' => $sessionId,
    ];
}

/**
 * @param array<string, mixed> $setup
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function cpr_sh_req(array $setup, array $extra = []): array
{
    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setup['lease_token'],
        'worker_id' => (string) $setup['worker_id'],
        'run_all_handlers' => true,
        'sequence_package' => [
            'inv_c1' => 10,
            'ord_c1' => 5,
            'other_c2' => 99,
        ],
        'sequence_surviving' => [
            'inv_c1' => 12,
            'ord_c1' => 3,
        ],
        'sequence_observed_max' => [
            'inv_c1' => 11,
            'ord_c1' => 4,
        ],
    ], $extra);
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p504_' . bin2hex(random_bytes(4));
@mkdir($base, 0775, true);

try {
    cpr_sh('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P6-01-control-plane');
    cpr_sh(
        'catalog_order',
        orange_cpr_special_handlers_executable_order() === [
            'admins_permissions_composite',
            'expenses_via_accounts',
            'polymorphic_company_documents',
            'gl_voucher_slots_country',
            'seq_country_namespace',
        ]
    );

    $importSrc = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_import_live.php');
    cpr_sh(
        'import_engine_remains_generic',
        !str_contains($importSrc, 'seq_country_namespace')
        && !str_contains($importSrc, 'admins_permissions_composite')
        && !str_contains($importSrc, 'orange_cpr_special_live_run')
    );

    // Valid handler execution
    $slot = cpr_sh_fresh_env($base);
    $setup = cpr_sh_setup_through_import($slot['env']);
    $run = orange_cpr_special_live_run($slot['env'], (string) $setup['job_id'], cpr_sh_req($setup));
    cpr_sh('valid_handler_execution', !empty($run['ok']) && !empty($run['counters_not_lowered_ack']), (string) ($run['code'] ?? $run['message'] ?? ''));
    cpr_sh('cp8_exists', orange_cpr_checkpoint_exists((string) $setup['cpr_root'], (string) $setup['job_id'], 'CP8'));
    cpr_sh('sealed_report', is_array($run['special_report'] ?? null) && orange_cpr_auth_verify_seal($run['special_report']));
    cpr_sh('sealed_manifest', is_array($run['mutation_manifest'] ?? null) && orange_cpr_auth_verify_seal($run['mutation_manifest']));
    cpr_sh('fingerprint_integrity', !empty($run['mutation_manifest']['manifest_fingerprint']));
    cpr_sh('state_remains_importing', ($run['job_state'] ?? '') === 'cpr_importing');
    cpr_sh('no_production_sql', ($run['production_sql_executed'] ?? true) === false);
    cpr_sh('uploads_not_executed', empty($run['uploads_executed']));
    cpr_sh('import_engine_not_modified_flag', empty($run['import_engine_modified']));

    $idem = orange_cpr_special_live_run($slot['env'], (string) $setup['job_id'], cpr_sh_req($setup));
    cpr_sh('idempotent_complete', !empty($idem['ok']) && !empty($idem['idempotent']));

    $auditPath = orange_cpr_job_directory((string) $setup['cpr_root'], (string) $setup['job_id'])
        . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME;
    $auditRaw = is_file($auditPath) ? (string) file_get_contents($auditPath) : '';
    cpr_sh('audit_start', str_contains($auditRaw, 'cpr.special_live_start'));
    cpr_sh('audit_handler_complete', str_contains($auditRaw, 'cpr.special_live_handler_complete'));
    cpr_sh('audit_complete', str_contains($auditRaw, 'cpr.special_live_complete'));

    // Unknown handler
    $slotU = cpr_sh_fresh_env($base);
    $setupU = cpr_sh_setup_through_import($slotU['env']);
    $unk = orange_cpr_special_live_run($slotU['env'], (string) $setupU['job_id'], cpr_sh_req($setupU, [
        'handler_id' => 'not_a_real_handler',
        'run_all_handlers' => false,
    ]));
    cpr_sh('unknown_handler', empty($unk['ok']) && ($unk['code'] ?? '') === ORANGE_CPR_SPECIALLIVE_ERR_UNKNOWN, (string) ($unk['code'] ?? ''));

    // Excluded handler
    $excl = orange_cpr_special_live_run($slotU['env'], (string) $setupU['job_id'], cpr_sh_req($setupU, [
        'handler_id' => 'full_only_journal_entries',
        'run_all_handlers' => false,
    ]));
    cpr_sh('excluded_handler', empty($excl['ok']) && ($excl['code'] ?? '') === ORANGE_CPR_SPECIALLIVE_ERR_EXCLUDED, (string) ($excl['code'] ?? ''));

    // Handler failure + recovery metadata
    $slotF = cpr_sh_fresh_env($base);
    $setupF = cpr_sh_setup_through_import($slotF['env']);
    $failH = orange_cpr_special_live_run($slotF['env'], (string) $setupF['job_id'], cpr_sh_req($setupF, [
        'fail_handler' => 'expenses_via_accounts',
    ]));
    cpr_sh(
        'handler_failure',
        empty($failH['ok'])
        && ($failH['code'] ?? '') === ORANGE_CPR_SPECIALLIVE_ERR_FAIL
        && is_array($failH['recovery_metadata'] ?? null)
        && ($failH['recovery_metadata']['failed_handler'] ?? '') === 'expenses_via_accounts',
        (string) ($failH['code'] ?? '')
    );

    // Recovery / resume after failure
    $resume = orange_cpr_special_live_run($slotF['env'], (string) $setupF['job_id'], cpr_sh_req($setupF, [
        'resume_authorized' => true,
        'resume_from_handler' => 'expenses_via_accounts',
    ]));
    cpr_sh(
        'recovery_after_handler_failure',
        !empty($resume['ok']) && orange_cpr_checkpoint_exists((string) $setupF['cpr_root'], (string) $setupF['job_id'], 'CP8'),
        (string) ($resume['code'] ?? $resume['message'] ?? '')
    );

    // Replay attempt
    $replay = orange_cpr_special_live_run($slotF['env'], (string) $setupF['job_id'], cpr_sh_req($setupF, [
        'force_replay' => true,
    ]));
    cpr_sh('replay_attempt', empty($replay['ok']) && ($replay['code'] ?? '') === ORANGE_CPR_SPECIALLIVE_ERR_REPLAY, (string) ($replay['code'] ?? ''));

    // Out-of-order
    $slotO = cpr_sh_fresh_env($base);
    $setupO = cpr_sh_setup_through_import($slotO['env']);
    $ooo = orange_cpr_special_live_run($slotO['env'], (string) $setupO['job_id'], cpr_sh_req($setupO, [
        'force_handler_order' => [
            'seq_country_namespace',
            'admins_permissions_composite',
            'expenses_via_accounts',
            'polymorphic_company_documents',
            'gl_voucher_slots_country',
        ],
    ]));
    cpr_sh('out_of_order_execution', empty($ooo['ok']) && ($ooo['code'] ?? '') === ORANGE_CPR_SPECIALLIVE_ERR_ORDER, (string) ($ooo['code'] ?? ''));

    // Dependency violation (skip to seq without priors — probe single handler)
    $dep = orange_cpr_special_live_run($slotO['env'], (string) $setupO['job_id'], cpr_sh_req($setupO, [
        'handler_id' => 'seq_country_namespace',
        'run_all_handlers' => false,
    ]));
    cpr_sh(
        'dependency_violation',
        empty($dep['ok']) && in_array(($dep['code'] ?? ''), [
            ORANGE_CPR_SPECIALLIVE_ERR_DEPENDENCY,
            ORANGE_CPR_SPECIALLIVE_ERR_ORDER,
        ], true),
        (string) ($dep['code'] ?? '')
    );

    // Resume from sealed checkpoint (stop after first handler)
    $slotR = cpr_sh_fresh_env($base);
    $setupR = cpr_sh_setup_through_import($slotR['env']);
    $partial = orange_cpr_special_live_run($slotR['env'], (string) $setupR['job_id'], cpr_sh_req($setupR, [
        'stop_after_handler' => 'admins_permissions_composite',
    ]));
    $resume2 = orange_cpr_special_live_run($slotR['env'], (string) $setupR['job_id'], cpr_sh_req($setupR, [
        'resume_from_handler' => 'expenses_via_accounts',
    ]));
    cpr_sh(
        'resume_from_sealed_checkpoint',
        !empty($partial['ok'])
        && (int) count($partial['completed_handlers'] ?? []) === 1
        && !empty($resume2['ok'])
        && orange_cpr_checkpoint_exists((string) $setupR['cpr_root'], (string) $setupR['job_id'], 'CP8'),
        (string) (($resume2['code'] ?? '') . '/' . ($partial['code'] ?? ''))
    );

    // Cross-country / out-of-slice
    $slotS = cpr_sh_fresh_env($base);
    $setupS = cpr_sh_setup_through_import($slotS['env']);
    $xc = orange_cpr_special_live_run($slotS['env'], (string) $setupS['job_id'], cpr_sh_req($setupS, [
        'force_country_id' => 9,
    ]));
    cpr_sh('cross_country_refused', empty($xc['ok']) && ($xc['code'] ?? '') === ORANGE_CPR_SPECIALLIVE_ERR_SCOPE, (string) ($xc['code'] ?? ''));

    // Counter lower forbidden
    $slotC = cpr_sh_fresh_env($base);
    $setupC = cpr_sh_setup_through_import($slotC['env']);
    $cnt = orange_cpr_special_live_run($slotC['env'], (string) $setupC['job_id'], cpr_sh_req($setupC, [
        'force_lower_counter' => 'inv_c1',
    ]));
    cpr_sh('counter_not_lowered', empty($cnt['ok']) && ($cnt['code'] ?? '') === ORANGE_CPR_SPECIALLIVE_ERR_COUNTER, (string) ($cnt['code'] ?? ''));

    // Privilege bypass
    $slotP = cpr_sh_fresh_env($base);
    $setupP = cpr_sh_setup_through_import($slotP['env']);
    $nonSa = orange_cpr_special_live_run($slotP['env'], (string) $setupP['job_id'], cpr_sh_req($setupP, [
        'actor_is_super_admin' => false,
    ]));
    cpr_sh('privilege_bypass_denied', empty($nonSa['ok']) && ($nonSa['code'] ?? '') === ORANGE_CPR_SPECIALLIVE_ERR_ACTOR);

    $uploads = orange_cpr_special_live_run($slotP['env'], (string) $setupP['job_id'], cpr_sh_req($setupP, [
        'execute_uploads' => true,
    ]));
    cpr_sh('uploads_disabled', empty($uploads['ok']) && ($uploads['code'] ?? '') === ORANGE_CPR_SPECIALLIVE_ERR_UPLOADS);

    cpr_sh('ops_enablement_still_false', orange_cpr_enablement_flag_read($slot['env']) === false);
} catch (Throwable $e) {
    cpr_sh('exception', false, $e->getMessage());
} finally {
    cpr_sh_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
