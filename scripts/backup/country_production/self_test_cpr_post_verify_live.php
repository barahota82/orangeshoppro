<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Post-Verify (WP-P6-02 / CP10).
 * Run: php scripts/backup/country_production/self_test_cpr_post_verify_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_post_verify_live.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p6_control_plane.php';

$pass = 0;
$fail = 0;

function cpr_pv(string $name, bool $ok, string $detail = ''): void
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
function cpr_pv_fresh_env(string $base): array
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

function cpr_pv_cleanup(string $base): void
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
function cpr_pv_slice(): array
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
 * Full path through Country Uploads / CP9.
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function cpr_pv_setup_through_cp9(array $env): array
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
    $fp = hash('sha256', 'fp-pv-' . bin2hex(random_bytes(4)));
    $pkgId = 'pkg-pv-' . substr($fp, 0, 8);
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
        'owner_class' => 'super_admin_procedure', 'worker_id' => 'pv-worker',
    ]);
    if (empty($acq['ok'])) {
        throw new RuntimeException('lock');
    }
    $lease = (string) ($acq['lease_token'] ?? '');
    $worker = (string) ($acq['worker_id'] ?? 'pv-worker');
    $sessionId = (string) ($pin['session_full_backup_id'] ?? '');

    orange_cpr_checkpoint_create($env, $jid, 'runbook_pre_ponr', [
        'restore_package_id' => $pkgId, 'target_country_id' => 1, 'target_country_code' => 'KW',
        'c8_overall_result' => 'SAFE', 'certified_inventory_snapshot_id' => 'inv-1',
        'session_full_backup_id' => $sessionId, 'global_maintenance_active' => true,
        'completed_by_admin_id' => 7, 'completed_at' => gmdate('c'), 'audit_record_id' => 'aud-pv-1',
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
        'lease_token' => $lease, 'worker_id' => $worker, 'target_slice' => cpr_pv_slice(),
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
    $sh = orange_cpr_special_live_run($env, $jid, [
        'actor_admin_id' => 7, 'actor_is_super_admin' => true,
        'lease_token' => $lease, 'worker_id' => $worker,
        'run_all_handlers' => true,
        'sequence_package' => ['inv_c1' => 10, 'ord_c1' => 5, 'other_c2' => 99],
        'sequence_surviving' => ['inv_c1' => 12, 'ord_c1' => 3],
        'sequence_observed_max' => ['inv_c1' => 11, 'ord_c1' => 4],
    ]);
    if (empty($sh['ok'])) {
        throw new RuntimeException('special: ' . (string) ($sh['code'] ?? ''));
    }

    $paths = [
        'countries/kw/products/a.jpg',
        'countries/kw/products/b.jpg',
        'c1/docs/note.txt',
    ];
    $normalized = [];
    foreach ($paths as $p) {
        $n = orange_cpr_uploads_live_normalize_path($p);
        if ($n === null) {
            throw new RuntimeException('bad path');
        }
        $normalized[] = $n;
    }
    sort($normalized, SORT_STRING);
    $canonical = [
        'job_id' => $jid,
        'country_id' => 1,
        'package_fingerprint' => $fp,
        'schema_revision' => 121,
        'paths' => $normalized,
    ];
    $sha = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $manifest = [
        'manifest_id' => 'um-' . substr(hash('sha256', $jid . $fp), 0, 12),
        'job_id' => $jid,
        'country_id' => 1,
        'package_fingerprint' => $fp,
        'schema_revision' => 121,
        'paths' => $normalized,
        'manifest_sha256' => $sha,
        'manifest_sealed' => true,
        'require_non_empty' => true,
    ];
    $artifacts = [];
    foreach ($normalized as $i => $p) {
        $content = 'blob-' . $p . '-' . (string) $i;
        $artifacts[] = [
            'relative_path' => $p,
            'country_id' => 1,
            'content' => $content,
            'sha256' => hash('sha256', $content),
        ];
    }
    $up = orange_cpr_uploads_live_run($env, $jid, [
        'actor_admin_id' => 7, 'actor_is_super_admin' => true,
        'lease_token' => $lease, 'worker_id' => $worker,
        'schema_revision' => 121,
        'upload_manifest' => $manifest,
        'upload_artifacts' => $artifacts,
    ]);
    if (empty($up['ok'])) {
        throw new RuntimeException('uploads: ' . (string) ($up['code'] ?? ''));
    }

    return [
        'job_id' => $jid,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'package_fingerprint' => $fp,
        'cpr_root' => $cprRoot,
        'session_full_backup_id' => $sessionId,
        'package_id' => $pkgId,
    ];
}

/**
 * @param array<string, mixed> $setup
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function cpr_pv_req(array $setup, array $extra = []): array
{
    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setup['lease_token'],
        'worker_id' => (string) $setup['worker_id'],
        'schema_revision' => 121,
    ], $extra);
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p602_' . bin2hex(random_bytes(4));
@mkdir($base, 0775, true);

try {
    cpr_pv('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P7-04-evidence-pack');
    cpr_pv('dirname_constant', ORANGE_CPR_POST_VERIFY_DIRNAME === 'post_verify');
    cpr_pv('control_plane_flag', !empty(orange_cpr_p6_control_plane_snapshot()['post_verify_engine_implemented']));

    // --- Valid verification ---
    $ctx = cpr_pv_fresh_env($base);
    $env = $ctx['env'];
    $setup = cpr_pv_setup_through_cp9($env);
    $jid = (string) $setup['job_id'];
    $cprRoot = (string) $setup['cpr_root'];
    $run = orange_cpr_post_verify_live_run($env, $jid, cpr_pv_req($setup));
    cpr_pv('valid_verification', !empty($run['ok']), (string) ($run['code'] ?? ''));
    cpr_pv('cp10_exists', orange_cpr_checkpoint_exists($cprRoot, $jid, 'CP10'));
    $report = orange_cpr_post_verify_live_load_latest($cprRoot, $jid, 'report');
    $manifest = orange_cpr_post_verify_live_load_latest($cprRoot, $jid, 'manifest');
    cpr_pv('sealed_report', is_array($report) && ($report['overall_result'] ?? '') === 'PASS' && orange_cpr_auth_verify_seal($report));
    cpr_pv('sealed_manifest', is_array($manifest) && orange_cpr_auth_verify_seal($manifest));
    cpr_pv('state_post_verifying', (string) (orange_cpr_job_read($cprRoot, $jid)['state'] ?? '') === 'cpr_post_verifying');
    cpr_pv('no_production_sql', ($run['production_sql_executed'] ?? true) === false);
    cpr_pv('no_upload_mutation', ($run['production_uploads_mutated'] ?? true) === false);
    cpr_pv('recovery_metadata', is_array($run['recovery_metadata'] ?? null) && empty($run['recovery_metadata']['auto_rollback']));
    $cp10 = orange_cpr_checkpoint_load($env, $jid, 'CP10');
    $cp10Payload = is_array($cp10['checkpoint']['payload'] ?? null) ? $cp10['checkpoint']['payload'] : [];
    cpr_pv(
        'cp10_integrity',
        !empty($cp10['ok'])
        && ($cp10Payload['verify_suite_result'] ?? '') === 'PASS'
        && ($cp10Payload['integrity_waiver'] ?? true) === false
    );
    $audit = (string) file_get_contents($cprRoot . DIRECTORY_SEPARATOR . $jid . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME);
    cpr_pv('audit_integrity', str_contains($audit, 'cpr.post_verify_live_complete'));

    // Idempotent + replay refuse
    $again = orange_cpr_post_verify_live_run($env, $jid, cpr_pv_req($setup));
    cpr_pv('idempotent_complete', !empty($again['ok']) && !empty($again['idempotent']));
    $replay = orange_cpr_post_verify_live_run($env, $jid, cpr_pv_req($setup, ['force_replay' => true]));
    cpr_pv('replay_attempt', empty($replay['ok']) && ($replay['code'] ?? '') === ORANGE_CPR_PVLIVE_ERR_REPLAY);

    // --- Missing CP9 ---
    $ctx2 = cpr_pv_fresh_env($base);
    $setup2 = cpr_pv_setup_through_cp9($ctx2['env']);
    $cp9path = orange_cpr_checkpoint_final_path($setup2['cpr_root'], $setup2['job_id'], 'CP9');
    @unlink($cp9path);
    $miss = orange_cpr_post_verify_live_run($ctx2['env'], (string) $setup2['job_id'], cpr_pv_req($setup2));
    cpr_pv('missing_cp9', empty($miss['ok']) && ($miss['code'] ?? '') === ORANGE_CPR_PVLIVE_ERR_CP9, (string) ($miss['code'] ?? ''));

    // --- Manifest mismatch ---
    $ctx3 = cpr_pv_fresh_env($base);
    $setup3 = cpr_pv_setup_through_cp9($ctx3['env']);
    $um = orange_cpr_uploads_live_load_latest($setup3['cpr_root'], $setup3['job_id'], 'manifest');
    if (is_array($um)) {
        $um['manifest_fingerprint'] = str_repeat('f', 64);
        unset($um['seal'], $um['content_sha256'], $um['sealed_sha256']);
        $path = orange_cpr_uploads_live_latest_path($setup3['cpr_root'], $setup3['job_id'], 'manifest');
        $tmp = $path . '.tmp';
        file_put_contents($tmp, (string) json_encode(orange_cpr_auth_seal($um), JSON_UNESCAPED_UNICODE));
        orange_cpr_atomic_rename_replace($tmp, $path);
    }
    $mm = orange_cpr_post_verify_live_run($ctx3['env'], (string) $setup3['job_id'], cpr_pv_req($setup3, [
        'force_fail_check' => 'manifest',
    ]));
    cpr_pv(
        'manifest_mismatch',
        empty($mm['ok']) && in_array($mm['code'] ?? '', [ORANGE_CPR_PVLIVE_ERR_SUITE, ORANGE_CPR_PVLIVE_ERR_MANIFEST], true),
        (string) ($mm['code'] ?? '')
    );

    // --- Fingerprint mismatch ---
    $ctx4 = cpr_pv_fresh_env($base);
    $setup4 = cpr_pv_setup_through_cp9($ctx4['env']);
    $fpMiss = orange_cpr_post_verify_live_run($ctx4['env'], (string) $setup4['job_id'], cpr_pv_req($setup4, [
        'package_fingerprint' => str_repeat('a', 64),
    ]));
    cpr_pv('fingerprint_mismatch', empty($fpMiss['ok']) && ($fpMiss['code'] ?? '') === ORANGE_CPR_PVLIVE_ERR_FINGERPRINT);

    // --- State mismatch ---
    $ctx5 = cpr_pv_fresh_env($base);
    $setup5 = cpr_pv_setup_through_cp9($ctx5['env']);
    $job5 = orange_cpr_job_read($setup5['cpr_root'], $setup5['job_id']);
    $job5['state'] = 'cpr_deleting';
    orange_cpr_job_write($setup5['cpr_root'], $setup5['job_id'], $job5);
    $st = orange_cpr_post_verify_live_run($ctx5['env'], (string) $setup5['job_id'], cpr_pv_req($setup5));
    cpr_pv('state_mismatch', empty($st['ok']) && ($st['code'] ?? '') === ORANGE_CPR_PVLIVE_ERR_STATE);

    // --- Contract / schema mismatch ---
    $ctx6 = cpr_pv_fresh_env($base);
    $setup6 = cpr_pv_setup_through_cp9($ctx6['env']);
    $sch = orange_cpr_post_verify_live_run($ctx6['env'], (string) $setup6['job_id'], cpr_pv_req($setup6, [
        'schema_revision' => 999,
    ]));
    cpr_pv('contract_mismatch', empty($sch['ok']) && ($sch['code'] ?? '') === ORANGE_CPR_PVLIVE_ERR_SCHEMA);

    // --- Country mismatch ---
    $ctx7 = cpr_pv_fresh_env($base);
    $setup7 = cpr_pv_setup_through_cp9($ctx7['env']);
    $cty = orange_cpr_post_verify_live_run($ctx7['env'], (string) $setup7['job_id'], cpr_pv_req($setup7, [
        'country_id' => 99,
    ]));
    cpr_pv('country_mismatch', empty($cty['ok']) && ($cty['code'] ?? '') === ORANGE_CPR_PVLIVE_ERR_COUNTRY);

    // --- Suite fail path (recovery + no CP10) ---
    $ctx8 = cpr_pv_fresh_env($base);
    $setup8 = cpr_pv_setup_through_cp9($ctx8['env']);
    $suiteFail = orange_cpr_post_verify_live_run($ctx8['env'], (string) $setup8['job_id'], cpr_pv_req($setup8, [
        'force_fail_check' => 'V02',
        'inject_survivor_hash' => str_repeat('x', 32),
    ]));
    cpr_pv('suite_fail_closed', empty($suiteFail['ok']) && ($suiteFail['code'] ?? '') === ORANGE_CPR_PVLIVE_ERR_SUITE);
    cpr_pv('suite_fail_no_cp10', !orange_cpr_checkpoint_exists($setup8['cpr_root'], $setup8['job_id'], 'CP10'));
    cpr_pv(
        'suite_fail_paused',
        (string) (orange_cpr_job_read($setup8['cpr_root'], $setup8['job_id'])['state'] ?? '') === 'cpr_paused_verify_failed'
    );
    cpr_pv('recovery_metadata_integrity', is_array($suiteFail['recovery_metadata'] ?? null)
        && ($suiteFail['recovery_metadata']['maint_remains_on'] ?? false) === true
        && ($suiteFail['recovery_metadata']['auto_rollback'] ?? true) === false);

    // Bypass knobs
    $ctx9 = cpr_pv_fresh_env($base);
    $setup9 = cpr_pv_setup_through_cp9($ctx9['env']);
    $bypass = orange_cpr_post_verify_live_run($ctx9['env'], (string) $setup9['job_id'], cpr_pv_req($setup9, [
        'force_pass' => true,
    ]));
    cpr_pv('privilege_bypass_denied', empty($bypass['ok']) && ($bypass['code'] ?? '') === ORANGE_CPR_PVLIVE_ERR_BYPASS);

    $docs = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup';
    $design = $docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P6_02_POST_VERIFY.md';
    $index = (string) file_get_contents($docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P6_ARTIFACT_INDEX.md');
    cpr_pv('design_doc_exists', is_file($design));
    cpr_pv(
        'index_wp_p6_02_complete',
        str_contains($index, 'COUNTRY_PRODUCTION_RESTORE_P6_02_POST_VERIFY.md')
        && str_contains($index, '**WP-P6-02 COMPLETE**')
    );
    cpr_pv('design_stop_rule_present', str_contains(
        (string) file_get_contents($design),
        'Do **not** begin **WP-P6-03**'
    ));
    cpr_pv('ops_enablement_still_false', orange_cpr_enablement_flag_read($env) === false);
} catch (Throwable $e) {
    cpr_pv('exception', false, $e->getMessage());
} finally {
    cpr_pv_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
