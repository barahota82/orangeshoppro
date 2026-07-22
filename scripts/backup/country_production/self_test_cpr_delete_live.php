<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live PONR Target-Slice DELETE (WP-P5-02).
 * Run: php scripts/backup/country_production/self_test_cpr_delete_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_delete_live.php';

$pass = 0;
$fail = 0;

function cpr_dl(string $name, bool $ok, string $detail = ''): void
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
 * @return array{env:array<string,mixed>,base:string,cpr:string}
 */
function cpr_dl_env(): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p502_' . bin2hex(random_bytes(4));
    @mkdir($base, 0775, true);

    return array_merge(['base' => $base], cpr_dl_fresh_env($base));
}

/**
 * Isolated CPR work root per scenario (avoids cross-job lock conflicts).
 *
 * @return array{env:array<string,mixed>,cpr:string}
 */
function cpr_dl_fresh_env(string $base): array
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

function cpr_dl_cleanup(string $base): void
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
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function cpr_dl_setup_cpa(array $env): array
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
    $fp = hash('sha256', 'fp-dl-' . bin2hex(random_bytes(4)));
    $pkgId = 'pkg-dl-' . substr($fp, 0, 8);
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
    orange_cpr_contract_freeze_initial($env, $jid, $fps, 7);
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
        throw new RuntimeException('maint: ' . (string) ($maint['code'] ?? ''));
    }
    $pin = orange_cpr_od_pin_live_run($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
    ]);
    if (empty($pin['ok'])) {
        throw new RuntimeException('pin: ' . (string) ($pin['code'] ?? ''));
    }
    $sessionId = (string) ($pin['session_full_backup_id'] ?? '');
    $acq = orange_cpr_lock_live_acquire($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'owner_class' => 'super_admin_procedure',
        'worker_id' => 'dl-worker',
    ]);
    if (empty($acq['ok'])) {
        throw new RuntimeException('lock: ' . (string) ($acq['code'] ?? ''));
    }
    $lease = (string) ($acq['lease_token'] ?? '');
    $worker = (string) ($acq['worker_id'] ?? 'dl-worker');

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
        'audit_record_id' => 'aud-dl-1',
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
        throw new RuntimeException('gates: ' . (string) ($gate['code'] ?? ''));
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
        throw new RuntimeException('auth: ' . (string) ($auth['code'] ?? ''));
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    $cp5Path = orange_cpr_checkpoint_final_path($cprRoot, $jid, 'CP5');
    if (is_file($cp5Path)) {
        @unlink($cp5Path);
    }
    $wit = orange_cpr_witnesses_live_ceremony($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'witnesses' => [
            'survivor_baseline_hash' => str_repeat('s', 32),
            'global_baseline_hash' => str_repeat('g', 32),
            'target_inventory_hash' => str_repeat('t', 32),
            'inventory_snapshot_id' => 'inv-1',
            'inventory_snapshot_hash' => str_repeat('1', 32),
            'captured_at' => gmdate('c'),
            'schema_revision' => 121,
            'package_fingerprint' => $fp,
            'c8_overall_result' => 'SAFE',
        ],
    ]);
    if (empty($wit['ok'])) {
        throw new RuntimeException('witnesses: ' . (string) ($wit['code'] ?? ''));
    }

    return [
        'job_id' => $jid,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'package_fingerprint' => $fp,
        'cpr_root' => $cprRoot,
    ];
}

/**
 * @return array<string, mixed>
 */
function cpr_dl_slice(bool $empty = false): array
{
    return [
        'country_id' => 1,
        'country_code' => 'KW',
        'delete_order_version' => ORANGE_CPR_DELETE_ORDER_VERSION,
        'tables' => $empty ? [] : [
            ['table' => 'order_items', 'membership_key' => 'country_id', 'row_ids' => ['11', '12']],
            ['table' => 'orders', 'membership_key' => 'country_id', 'row_ids' => ['1']],
        ],
    ];
}

$bundle = cpr_dl_env();
$base = $bundle['base'];

try {
    cpr_dl('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P6-06-integration-baseline');

    $slot = cpr_dl_fresh_env($base);
    $env = $slot['env'];
    $setup = cpr_dl_setup_cpa($env);
    $jid = (string) $setup['job_id'];
    $cprRoot = (string) $setup['cpr_root'];

    $run = orange_cpr_delete_live_run($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setup['lease_token'],
        'worker_id' => (string) $setup['worker_id'],
        'target_slice' => cpr_dl_slice(false),
    ]);
    cpr_dl('valid_target_slice_delete', !empty($run['ok']) && !empty($run['ponr_crossed']), (string) ($run['code'] ?? $run['message'] ?? ''));
    cpr_dl('cp6_exists', orange_cpr_checkpoint_exists($cprRoot, $jid, 'CP6'));
    cpr_dl('sealed_report', is_array($run['delete_report'] ?? null) && orange_cpr_auth_verify_seal($run['delete_report']));
    cpr_dl('sealed_manifest', is_array($run['mutation_manifest'] ?? null) && orange_cpr_auth_verify_seal($run['mutation_manifest']));
    cpr_dl('state_remains_deleting', ($run['job_state'] ?? '') === 'cpr_deleting');
    cpr_dl('no_production_sql', ($run['production_sql_executed'] ?? true) === false);
    cpr_dl('import_not_executed', empty($run['import_executed']));
    cpr_dl('recovery_metadata', is_array($run['recovery_metadata'] ?? null) && !empty($run['recovery_metadata']['session_full_backup_id']));

    $idem = orange_cpr_delete_live_run($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setup['lease_token'],
        'worker_id' => (string) $setup['worker_id'],
        'target_slice' => cpr_dl_slice(false),
    ]);
    cpr_dl('idempotent_complete', !empty($idem['ok']) && !empty($idem['idempotent']));

    // Audit integrity
    $auditPath = orange_cpr_job_directory($cprRoot, $jid) . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME;
    $auditRaw = is_file($auditPath) ? (string) file_get_contents($auditPath) : '';
    cpr_dl('audit_ponr_enter', str_contains($auditRaw, 'cpr.delete_live_ponr_enter'));
    cpr_dl('audit_complete', str_contains($auditRaw, 'cpr.delete_live_complete'));

    // Empty target slice
    $slotE = cpr_dl_fresh_env($base);
    $setupE = cpr_dl_setup_cpa($slotE['env']);
    $empty = orange_cpr_delete_live_run($slotE['env'], (string) $setupE['job_id'], [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setupE['lease_token'],
        'worker_id' => (string) $setupE['worker_id'],
        'target_slice' => cpr_dl_slice(true),
    ]);
    cpr_dl('empty_target_slice', !empty($empty['ok']) && !empty($empty['empty_target_slice']), (string) ($empty['code'] ?? ''));

    // Invalid execution state (pending job)
    $slotB = cpr_dl_fresh_env($base);
    $badJob = orange_cpr_job_create($slotB['env'], [
        'package_id' => 'pkg-bad',
        'package_fingerprint' => str_repeat('a', 64),
        'country_id' => 1,
        'country_code' => 'KW',
        'workflow' => 'A',
    ], 7);
    $badState = orange_cpr_delete_live_run($slotB['env'], (string) $badJob['job_id'], [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => 'x',
        'worker_id' => 'y',
        'target_slice' => cpr_dl_slice(false),
    ]);
    cpr_dl(
        'invalid_execution_state',
        empty($badState['ok']) && in_array(($badState['code'] ?? ''), [
            ORANGE_CPR_DELETELIVE_ERR_STATE,
            ORANGE_CPR_DELETELIVE_ERR_CPA,
            ORANGE_CPR_DELETELIVE_ERR_CONTRACT,
            ORANGE_CPR_DELETELIVE_ERR_GATE,
            ORANGE_CPR_DELETELIVE_ERR_AUTHORITY,
            ORANGE_CPR_DELETELIVE_ERR_LOCK,
        ], true),
        (string) ($badState['code'] ?? '')
    );

    // Contract mismatch / scope expansion
    $slotS = cpr_dl_fresh_env($base);
    $setupS = cpr_dl_setup_cpa($slotS['env']);
    $scope = orange_cpr_delete_live_run($slotS['env'], (string) $setupS['job_id'], [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setupS['lease_token'],
        'worker_id' => (string) $setupS['worker_id'],
        'target_slice' => [
            'country_id' => 2,
            'country_code' => 'SA',
            'tables' => [
                ['table' => 'orders', 'membership_key' => 'country_id', 'row_ids' => ['9']],
            ],
        ],
    ]);
    cpr_dl('contract_mismatch_scope', empty($scope['ok']) && ($scope['code'] ?? '') === ORANGE_CPR_DELETELIVE_ERR_SCOPE, (string) ($scope['code'] ?? ''));

    $expand = orange_cpr_delete_live_run($slotS['env'], (string) $setupS['job_id'], [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setupS['lease_token'],
        'worker_id' => (string) $setupS['worker_id'],
        'target_slice' => [
            'country_id' => 1,
            'country_code' => 'KW',
            'tables' => [
                ['table' => 'admins', 'membership_key' => 'country_id', 'row_ids' => ['1']],
            ],
        ],
    ]);
    cpr_dl('attempted_scope_expansion', empty($expand['ok']) && ($expand['code'] ?? '') === ORANGE_CPR_DELETELIVE_ERR_SCOPE, (string) ($expand['code'] ?? ''));

    // Lock loss
    $slotL = cpr_dl_fresh_env($base);
    $setupL = cpr_dl_setup_cpa($slotL['env']);
    $lockLoss = orange_cpr_delete_live_run($slotL['env'], (string) $setupL['job_id'], [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => 'wrong-lease',
        'worker_id' => 'wrong-worker',
        'target_slice' => cpr_dl_slice(false),
    ]);
    cpr_dl('lock_loss', empty($lockLoss['ok']) && ($lockLoss['code'] ?? '') === ORANGE_CPR_DELETELIVE_ERR_LOCK, (string) ($lockLoss['code'] ?? ''));

    // Gate failure — remove gates_live artifacts after CP-A setup
    $slotG = cpr_dl_fresh_env($base);
    $setupG = cpr_dl_setup_cpa($slotG['env']);
    $gDir = orange_cpr_gates_live_directory((string) $setupG['cpr_root'], (string) $setupG['job_id']);
    foreach (glob($gDir . DIRECTORY_SEPARATOR . '*') ?: [] as $gf) {
        @unlink($gf);
    }
    $gateFail = orange_cpr_delete_live_run($slotG['env'], (string) $setupG['job_id'], [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setupG['lease_token'],
        'worker_id' => (string) $setupG['worker_id'],
        'target_slice' => cpr_dl_slice(false),
    ]);
    cpr_dl('gate_failure', empty($gateFail['ok']) && ($gateFail['code'] ?? '') === ORANGE_CPR_DELETELIVE_ERR_GATE, (string) ($gateFail['code'] ?? ''));

    // Authority failure
    $slotA = cpr_dl_fresh_env($base);
    $setupA = cpr_dl_setup_cpa($slotA['env']);
    $aDir = orange_cpr_auth_live_directory((string) $setupA['cpr_root'], (string) $setupA['job_id']);
    foreach (glob($aDir . DIRECTORY_SEPARATOR . '*') ?: [] as $af) {
        @unlink($af);
    }
    $authFail = orange_cpr_delete_live_run($slotA['env'], (string) $setupA['job_id'], [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setupA['lease_token'],
        'worker_id' => (string) $setupA['worker_id'],
        'target_slice' => cpr_dl_slice(false),
    ]);
    cpr_dl('authority_failure', empty($authFail['ok']) && ($authFail['code'] ?? '') === ORANGE_CPR_DELETELIVE_ERR_AUTHORITY, (string) ($authFail['code'] ?? ''));

    // Bypass / import continuation forbidden
    $slotR = cpr_dl_fresh_env($base);
    $setupR = cpr_dl_setup_cpa($slotR['env']);
    $bypass = orange_cpr_delete_live_run($slotR['env'], (string) $setupR['job_id'], [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setupR['lease_token'],
        'worker_id' => (string) $setupR['worker_id'],
        'target_slice' => cpr_dl_slice(false),
        'continue_to_import' => true,
    ]);
    cpr_dl(
        'replay_or_bypass_import',
        empty($bypass['ok']) && in_array(($bypass['code'] ?? ''), [
            ORANGE_CPR_DELETELIVE_ERR_BYPASS,
            ORANGE_CPR_DELETELIVE_ERR_IMPORT,
        ], true),
        (string) ($bypass['code'] ?? '')
    );

    // Non-SA
    $slotN = cpr_dl_fresh_env($base);
    $setupN = cpr_dl_setup_cpa($slotN['env']);
    $nonSa = orange_cpr_delete_live_run($slotN['env'], (string) $setupN['job_id'], [
        'actor_admin_id' => 99,
        'actor_is_super_admin' => false,
        'lease_token' => (string) $setupN['lease_token'],
        'worker_id' => (string) $setupN['worker_id'],
        'target_slice' => cpr_dl_slice(false),
    ]);
    cpr_dl('privilege_bypass_denied', empty($nonSa['ok']) && ($nonSa['code'] ?? '') === ORANGE_CPR_DELETELIVE_ERR_ACTOR);

    cpr_dl('ops_enablement_still_false', orange_cpr_enablement_flag_read($slot['env']) === false);
} catch (Throwable $e) {
    cpr_dl('exception', false, $e->getMessage());
} finally {
    cpr_dl_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
