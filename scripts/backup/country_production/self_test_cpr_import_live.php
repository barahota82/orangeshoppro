<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Target-Slice IMPORT Batches 1→6 (WP-P5-03).
 * Run: php scripts/backup/country_production/self_test_cpr_import_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_import_live.php';

$pass = 0;
$fail = 0;

function cpr_il(string $name, bool $ok, string $detail = ''): void
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
function cpr_il_env(): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p503_' . bin2hex(random_bytes(4));
    @mkdir($base, 0775, true);

    return array_merge(['base' => $base], cpr_il_fresh_env($base));
}

/**
 * @return array{env:array<string,mixed>,cpr:string}
 */
function cpr_il_fresh_env(string $base): array
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

function cpr_il_cleanup(string $base): void
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
function cpr_il_slice(): array
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
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function cpr_il_setup_cpa(array $env): array
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
    $fp = hash('sha256', 'fp-il-' . bin2hex(random_bytes(4)));
    $pkgId = 'pkg-il-' . substr($fp, 0, 8);
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
    $acq = orange_cpr_lock_live_acquire($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'owner_class' => 'super_admin_procedure',
        'worker_id' => 'il-worker',
    ]);
    if (empty($acq['ok'])) {
        throw new RuntimeException('lock: ' . (string) ($acq['code'] ?? ''));
    }
    $lease = (string) ($acq['lease_token'] ?? '');
    $worker = (string) ($acq['worker_id'] ?? 'il-worker');

    $sessionId = (string) ($pin['session_full_backup_id'] ?? '');
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
        'audit_record_id' => 'aud-il-1',
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
        'session_full_backup_id' => $sessionId,
    ];
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $setup
 * @return array<string, mixed>
 */
function cpr_il_setup_post_delete(array $env, array $setup): array
{
    $del = orange_cpr_delete_live_run($env, (string) $setup['job_id'], [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setup['lease_token'],
        'worker_id' => (string) $setup['worker_id'],
        'target_slice' => cpr_il_slice(),
    ]);
    if (empty($del['ok'])) {
        throw new RuntimeException('delete setup: ' . (string) ($del['code'] ?? $del['message'] ?? ''));
    }

    return $del;
}

/**
 * @param array<string, mixed> $setup
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function cpr_il_req(array $setup, array $extra = []): array
{
    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setup['lease_token'],
        'worker_id' => (string) $setup['worker_id'],
        'session_full_backup_id' => (string) ($setup['session_full_backup_id'] ?? ''),
        'schema_revision' => 121,
    ], $extra);
}

$bundle = cpr_il_env();
$base = $bundle['base'];

try {
    cpr_il('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P5-06-integration-baseline');
    cpr_il('batch_catalog_six', orange_cpr_import_batch_numbers() === [1, 2, 3, 4, 5, 6]);
    cpr_il('batch1_has_products', in_array('products', orange_cpr_import_batch_tables(1), true));
    cpr_il('batch6_document_sequences', orange_cpr_import_batch_tables(6) === ['document_sequences']);

    // Valid complete 1→6
    $slot = cpr_il_fresh_env($base);
    $setup = cpr_il_setup_cpa($slot['env']);
    cpr_il_setup_post_delete($slot['env'], $setup);
    $run = orange_cpr_import_live_run($slot['env'], (string) $setup['job_id'], cpr_il_req($setup));
    cpr_il('valid_batches_1_to_6', !empty($run['ok']) && ($run['batches_completed'] ?? null) === [1, 2, 3, 4, 5, 6], (string) ($run['code'] ?? $run['message'] ?? ''));
    cpr_il('cp7_exists', orange_cpr_checkpoint_exists((string) $setup['cpr_root'], (string) $setup['job_id'], 'CP7'));
    cpr_il('sealed_summary', is_array($run['import_summary'] ?? null) && orange_cpr_auth_verify_seal($run['import_summary']));
    cpr_il('summary_integrity', !empty($run['import_summary']['summary_fingerprint']) && !empty($run['import_summary']['import_complete']));
    cpr_il('state_remains_importing', ($run['job_state'] ?? '') === 'cpr_importing');
    cpr_il('no_production_sql', ($run['production_sql_executed'] ?? true) === false);
    cpr_il('special_not_executed', empty($run['special_handlers_executed']));
    cpr_il('uploads_not_executed', empty($run['uploads_executed']));
    cpr_il('recovery_metadata', is_array($run['recovery_metadata'] ?? null) && ($run['recovery_metadata']['last_sealed_batch'] ?? null) === 6);

    $idem = orange_cpr_import_live_run($slot['env'], (string) $setup['job_id'], cpr_il_req($setup));
    cpr_il('idempotent_complete', !empty($idem['ok']) && !empty($idem['idempotent']));

    $auditPath = orange_cpr_job_directory((string) $setup['cpr_root'], (string) $setup['job_id'])
        . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME;
    $auditRaw = is_file($auditPath) ? (string) file_get_contents($auditPath) : '';
    cpr_il('audit_start', str_contains($auditRaw, 'cpr.import_live_start'));
    cpr_il('audit_batch_complete', str_contains($auditRaw, 'cpr.import_live_batch_complete'));
    cpr_il('audit_complete', str_contains($auditRaw, 'cpr.import_live_complete'));

    // Missing DELETE / CP6
    $slotM = cpr_il_fresh_env($base);
    $setupM = cpr_il_setup_cpa($slotM['env']);
    $miss = orange_cpr_import_live_run($slotM['env'], (string) $setupM['job_id'], cpr_il_req($setupM));
    cpr_il(
        'missing_delete_or_cp6',
        empty($miss['ok']) && in_array(($miss['code'] ?? ''), [
            ORANGE_CPR_IMPORTLIVE_ERR_CP6,
            ORANGE_CPR_IMPORTLIVE_ERR_DELETE,
            ORANGE_CPR_IMPORTLIVE_ERR_STATE,
        ], true),
        (string) ($miss['code'] ?? '')
    );

    // Invalid starting state
    $slotB = cpr_il_fresh_env($base);
    $badJob = orange_cpr_job_create($slotB['env'], [
        'package_id' => 'pkg-bad',
        'package_fingerprint' => str_repeat('b', 64),
        'country_id' => 1,
        'country_code' => 'KW',
        'workflow' => 'A',
    ], 7);
    $badState = orange_cpr_import_live_run($slotB['env'], (string) $badJob['job_id'], [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => 'x',
        'worker_id' => 'y',
    ]);
    cpr_il('invalid_starting_state', empty($badState['ok']), (string) ($badState['code'] ?? ''));

    // Contract / OD-PIN / schema mismatch
    $slotC = cpr_il_fresh_env($base);
    $setupC = cpr_il_setup_cpa($slotC['env']);
    cpr_il_setup_post_delete($slotC['env'], $setupC);
    $odpin = orange_cpr_import_live_run($slotC['env'], (string) $setupC['job_id'], cpr_il_req($setupC, [
        'session_full_backup_id' => 'wrong-session',
    ]));
    cpr_il('odpin_mismatch', empty($odpin['ok']) && ($odpin['code'] ?? '') === ORANGE_CPR_IMPORTLIVE_ERR_ODPIN, (string) ($odpin['code'] ?? ''));

    $schema = orange_cpr_import_live_run($slotC['env'], (string) $setupC['job_id'], cpr_il_req($setupC, [
        'schema_revision' => 999,
    ]));
    cpr_il('schema_mismatch', empty($schema['ok']) && ($schema['code'] ?? '') === ORANGE_CPR_IMPORTLIVE_ERR_SCHEMA, (string) ($schema['code'] ?? ''));

    // Lock loss
    $slotL = cpr_il_fresh_env($base);
    $setupL = cpr_il_setup_cpa($slotL['env']);
    cpr_il_setup_post_delete($slotL['env'], $setupL);
    $lockLoss = orange_cpr_import_live_run($slotL['env'], (string) $setupL['job_id'], cpr_il_req($setupL, [
        'lease_token' => 'wrong',
        'worker_id' => 'wrong',
    ]));
    cpr_il('lock_loss', empty($lockLoss['ok']) && ($lockLoss['code'] ?? '') === ORANGE_CPR_IMPORTLIVE_ERR_LOCK, (string) ($lockLoss['code'] ?? ''));

    // Corrupt / missing batch input
    $slotS = cpr_il_fresh_env($base);
    $setupS = cpr_il_setup_cpa($slotS['env']);
    cpr_il_setup_post_delete($slotS['env'], $setupS);
    $corrupt = orange_cpr_import_live_run($slotS['env'], (string) $setupS['job_id'], cpr_il_req($setupS, [
        'source_batches' => ['1' => ['corrupt' => true, 'batch' => 1]],
    ]));
    cpr_il('corrupt_batch_input', empty($corrupt['ok']) && ($corrupt['code'] ?? '') === ORANGE_CPR_IMPORTLIVE_ERR_SOURCE, (string) ($corrupt['code'] ?? ''));

    // Out-of-slice / cross-country
    $slotO = cpr_il_fresh_env($base);
    $setupO = cpr_il_setup_cpa($slotO['env']);
    cpr_il_setup_post_delete($slotO['env'], $setupO);
    $oos = orange_cpr_import_live_run($slotO['env'], (string) $setupO['job_id'], cpr_il_req($setupO, [
        'target_slice' => [
            'country_id' => 1,
            'tables' => ['admins' => ['1']],
        ],
    ]));
    cpr_il('out_of_slice_write', empty($oos['ok']) && ($oos['code'] ?? '') === ORANGE_CPR_IMPORTLIVE_ERR_SCOPE, (string) ($oos['code'] ?? ''));

    $xcountry = orange_cpr_import_live_run($slotO['env'], (string) $setupO['job_id'], cpr_il_req($setupO, [
        'source_batches' => [
            '1' => [
                'batch' => 1,
                'country_id' => 2,
                'package_fingerprint' => (string) $setupO['package_fingerprint'],
                'schema_revision' => 121,
                'sealed' => true,
                'rows' => ['products' => [['id' => 'p1', 'country_id' => 2]]],
            ],
        ],
    ]));
    cpr_il('cross_country_write', empty($xcountry['ok']) && ($xcountry['code'] ?? '') === ORANGE_CPR_IMPORTLIVE_ERR_SCOPE, (string) ($xcountry['code'] ?? ''));

    // Batch skip / reorder
    $slotK = cpr_il_fresh_env($base);
    $setupK = cpr_il_setup_cpa($slotK['env']);
    cpr_il_setup_post_delete($slotK['env'], $setupK);
    $skip = orange_cpr_import_live_run($slotK['env'], (string) $setupK['job_id'], cpr_il_req($setupK, [
        'skip_batch' => true,
    ]));
    cpr_il('batch_skip', empty($skip['ok']) && ($skip['code'] ?? '') === ORANGE_CPR_IMPORTLIVE_ERR_BATCH_SKIP, (string) ($skip['code'] ?? ''));
    $reorder = orange_cpr_import_live_run($slotK['env'], (string) $setupK['job_id'], cpr_il_req($setupK, [
        'force_batch_order' => [2, 1, 3, 4, 5, 6],
    ]));
    cpr_il('batch_reorder', empty($reorder['ok']) && ($reorder['code'] ?? '') === ORANGE_CPR_IMPORTLIVE_ERR_BATCH_REORDER, (string) ($reorder['code'] ?? ''));

    // Failure during each batch + recovery metadata
    for ($b = 1; $b <= 6; ++$b) {
        $slotF = cpr_il_fresh_env($base);
        $setupF = cpr_il_setup_cpa($slotF['env']);
        cpr_il_setup_post_delete($slotF['env'], $setupF);
        $failB = orange_cpr_import_live_run($slotF['env'], (string) $setupF['job_id'], cpr_il_req($setupF, [
            'fail_at_batch' => $b,
        ]));
        cpr_il(
            'failure_batch_' . $b,
            empty($failB['ok'])
            && ($failB['code'] ?? '') === ORANGE_CPR_IMPORTLIVE_ERR_BATCH_FAIL
            && (int) (($failB['recovery_metadata']['last_sealed_batch'] ?? -1)) === ($b - 1),
            (string) ($failB['code'] ?? '')
        );
    }

    // Resume from every valid batch boundary
    for ($boundary = 1; $boundary <= 5; ++$boundary) {
        $slotR = cpr_il_fresh_env($base);
        $setupR = cpr_il_setup_cpa($slotR['env']);
        cpr_il_setup_post_delete($slotR['env'], $setupR);
        $partial = orange_cpr_import_live_run($slotR['env'], (string) $setupR['job_id'], cpr_il_req($setupR, [
            'stop_after_batch' => $boundary,
        ]));
        $okPartial = !empty($partial['ok']) && (int) ($partial['last_sealed_batch'] ?? 0) === $boundary;
        $resume = orange_cpr_import_live_run($slotR['env'], (string) $setupR['job_id'], cpr_il_req($setupR, [
            'resume_from_batch' => $boundary + 1,
        ]));
        cpr_il(
            'resume_from_batch_' . ($boundary + 1),
            $okPartial && !empty($resume['ok']) && ($resume['batches_completed'] ?? null) === [1, 2, 3, 4, 5, 6],
            (string) (($resume['code'] ?? '') . '/' . ($partial['code'] ?? ''))
        );
    }

    // Invalid resume point
    $slotIR = cpr_il_fresh_env($base);
    $setupIR = cpr_il_setup_cpa($slotIR['env']);
    cpr_il_setup_post_delete($slotIR['env'], $setupIR);
    orange_cpr_import_live_run($slotIR['env'], (string) $setupIR['job_id'], cpr_il_req($setupIR, [
        'stop_after_batch' => 2,
    ]));
    $badResume = orange_cpr_import_live_run($slotIR['env'], (string) $setupIR['job_id'], cpr_il_req($setupIR, [
        'resume_from_batch' => 5,
    ]));
    cpr_il('invalid_resume_point', empty($badResume['ok']) && ($badResume['code'] ?? '') === ORANGE_CPR_IMPORTLIVE_ERR_RESUME, (string) ($badResume['code'] ?? ''));

    // Replay completed batch
    $slotRP = cpr_il_fresh_env($base);
    $setupRP = cpr_il_setup_cpa($slotRP['env']);
    cpr_il_setup_post_delete($slotRP['env'], $setupRP);
    orange_cpr_import_live_run($slotRP['env'], (string) $setupRP['job_id'], cpr_il_req($setupRP, [
        'stop_after_batch' => 2,
    ]));
    $replay = orange_cpr_import_live_run($slotRP['env'], (string) $setupRP['job_id'], cpr_il_req($setupRP, [
        'resume_from_batch' => 1,
        'force_replay_batch' => 1,
    ]));
    cpr_il(
        'duplicate_replay_attempt',
        empty($replay['ok']) && in_array(($replay['code'] ?? ''), [
            ORANGE_CPR_IMPORTLIVE_ERR_REPLAY,
            ORANGE_CPR_IMPORTLIVE_ERR_RESUME,
            ORANGE_CPR_IMPORTLIVE_ERR_BATCH_SKIP,
        ], true),
        (string) ($replay['code'] ?? '')
    );

    // Referential integrity failure (order_items parent missing)
    $slotRI = cpr_il_fresh_env($base);
    $setupRI = cpr_il_setup_cpa($slotRI['env']);
    cpr_il_setup_post_delete($slotRI['env'], $setupRI);
    $ri = orange_cpr_import_live_run($slotRI['env'], (string) $setupRI['job_id'], cpr_il_req($setupRI, [
        'source_batches' => [
            '4' => [
                'batch' => 4,
                'country_id' => 1,
                'package_fingerprint' => (string) $setupRI['package_fingerprint'],
                'schema_revision' => 121,
                'sealed' => true,
                'rows' => [
                    'order_items' => [
                        ['id' => '11', 'country_id' => 1, 'parent_id' => 'missing-order'],
                        ['id' => '12', 'country_id' => 1, 'parent_id' => 'missing-order'],
                    ],
                    'inventory_cost_layers' => [],
                    'purchase_items' => [],
                    'purchase_return_items' => [],
                    'sales_return_items' => [],
                    'stock_movements' => [],
                    'warehouse_variant_stock' => [],
                ],
            ],
        ],
    ]));
    cpr_il('referential_integrity_failure', empty($ri['ok']) && ($ri['code'] ?? '') === ORANGE_CPR_IMPORTLIVE_ERR_RI, (string) ($ri['code'] ?? ''));

    // Count mismatch
    $slotCnt = cpr_il_fresh_env($base);
    $setupCnt = cpr_il_setup_cpa($slotCnt['env']);
    cpr_il_setup_post_delete($slotCnt['env'], $setupCnt);
    $cnt = orange_cpr_import_live_run($slotCnt['env'], (string) $setupCnt['job_id'], cpr_il_req($setupCnt, [
        'force_count_mismatch' => 2,
    ]));
    cpr_il('count_fingerprint_mismatch', empty($cnt['ok']) && ($cnt['code'] ?? '') === ORANGE_CPR_IMPORTLIVE_ERR_COUNT, (string) ($cnt['code'] ?? ''));

    // Privilege / special / uploads knobs
    $slotP = cpr_il_fresh_env($base);
    $setupP = cpr_il_setup_cpa($slotP['env']);
    cpr_il_setup_post_delete($slotP['env'], $setupP);
    $nonSa = orange_cpr_import_live_run($slotP['env'], (string) $setupP['job_id'], cpr_il_req($setupP, [
        'actor_is_super_admin' => false,
    ]));
    cpr_il('privilege_bypass_denied', empty($nonSa['ok']) && ($nonSa['code'] ?? '') === ORANGE_CPR_IMPORTLIVE_ERR_ACTOR);
    $special = orange_cpr_import_live_run($slotP['env'], (string) $setupP['job_id'], cpr_il_req($setupP, [
        'execute_special_handlers' => true,
    ]));
    cpr_il('special_handlers_disabled', empty($special['ok']) && ($special['code'] ?? '') === ORANGE_CPR_IMPORTLIVE_ERR_SPECIAL);
    $uploads = orange_cpr_import_live_run($slotP['env'], (string) $setupP['job_id'], cpr_il_req($setupP, [
        'execute_uploads' => true,
    ]));
    cpr_il('uploads_disabled', empty($uploads['ok']) && ($uploads['code'] ?? '') === ORANGE_CPR_IMPORTLIVE_ERR_UPLOADS);

    cpr_il('ops_enablement_still_false', orange_cpr_enablement_flag_read($slot['env']) === false);
} catch (Throwable $e) {
    cpr_il('exception', false, $e->getMessage());
} finally {
    cpr_il_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
