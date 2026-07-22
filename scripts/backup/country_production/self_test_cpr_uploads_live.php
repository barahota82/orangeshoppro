<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Country Uploads Apply (WP-P5-05).
 * Run: php scripts/backup/country_production/self_test_cpr_uploads_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_uploads_live.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p5_control_plane.php';

$pass = 0;
$fail = 0;

function cpr_ul(string $name, bool $ok, string $detail = ''): void
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
function cpr_ul_fresh_env(string $base): array
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

function cpr_ul_cleanup(string $base): void
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
function cpr_ul_slice(): array
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
 * Full path through Special Handlers / CP8.
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function cpr_ul_setup_through_special(array $env): array
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
    $fp = hash('sha256', 'fp-ul-' . bin2hex(random_bytes(4)));
    $pkgId = 'pkg-ul-' . substr($fp, 0, 8);
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
        'owner_class' => 'super_admin_procedure', 'worker_id' => 'ul-worker',
    ]);
    if (empty($acq['ok'])) {
        throw new RuntimeException('lock');
    }
    $lease = (string) ($acq['lease_token'] ?? '');
    $worker = (string) ($acq['worker_id'] ?? 'ul-worker');
    $sessionId = (string) ($pin['session_full_backup_id'] ?? '');

    orange_cpr_checkpoint_create($env, $jid, 'runbook_pre_ponr', [
        'restore_package_id' => $pkgId, 'target_country_id' => 1, 'target_country_code' => 'KW',
        'c8_overall_result' => 'SAFE', 'certified_inventory_snapshot_id' => 'inv-1',
        'session_full_backup_id' => $sessionId, 'global_maintenance_active' => true,
        'completed_by_admin_id' => 7, 'completed_at' => gmdate('c'), 'audit_record_id' => 'aud-ul-1',
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
        'lease_token' => $lease, 'worker_id' => $worker, 'target_slice' => cpr_ul_slice(),
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
 * @param list<string> $paths
 * @return array<string, mixed>
 */
function cpr_ul_build_manifest(string $jobId, string $fp, array $paths, array $overrides = []): array
{
    $normalized = [];
    foreach ($paths as $p) {
        $n = orange_cpr_uploads_live_normalize_path($p);
        if ($n === null) {
            throw new RuntimeException('bad path in test: ' . $p);
        }
        $normalized[] = $n;
    }
    sort($normalized, SORT_STRING);
    $canonical = [
        'job_id' => $jobId,
        'country_id' => 1,
        'package_fingerprint' => $fp,
        'schema_revision' => 121,
        'paths' => $normalized,
    ];
    $sha = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $base = [
        'manifest_id' => 'um-' . substr(hash('sha256', $jobId . $fp), 0, 12),
        'job_id' => $jobId,
        'country_id' => 1,
        'package_fingerprint' => $fp,
        'schema_revision' => 121,
        'paths' => $normalized,
        'manifest_sha256' => $sha,
        'manifest_sealed' => true,
        'require_non_empty' => true,
    ];

    return array_merge($base, $overrides);
}

/**
 * @param list<string> $paths
 * @return list<array<string, mixed>>
 */
function cpr_ul_artifacts(array $paths, int $countryId = 1): array
{
    $out = [];
    foreach ($paths as $i => $p) {
        $content = 'blob-' . $p . '-' . (string) $i;
        $out[] = [
            'relative_path' => $p,
            'country_id' => $countryId,
            'content' => $content,
            'sha256' => hash('sha256', $content),
        ];
    }

    return $out;
}

/**
 * @param array<string, mixed> $setup
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function cpr_ul_req(array $setup, array $extra = []): array
{
    $paths = [
        'countries/kw/products/a.jpg',
        'countries/kw/products/b.jpg',
        'c1/docs/note.txt',
    ];
    $manifest = cpr_ul_build_manifest(
        (string) $setup['job_id'],
        (string) $setup['package_fingerprint'],
        $paths
    );

    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setup['lease_token'],
        'worker_id' => (string) $setup['worker_id'],
        'schema_revision' => 121,
        'upload_manifest' => $manifest,
        'upload_artifacts' => cpr_ul_artifacts($manifest['paths']),
    ], $extra);
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p505_' . bin2hex(random_bytes(4));
@mkdir($base, 0775, true);

try {
    cpr_ul('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P6-04-rollback');
    cpr_ul('dirname_constant', ORANGE_CPR_UPLOADS_LIVE_DIRNAME === 'uploads_apply');

    $src = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_uploads_live.php');
    cpr_ul(
        'no_production_sql_in_engine',
        !preg_match('/\bdb\s*\(|PDO::|mysqli_|DELETE FROM|INSERT INTO|UPDATE\s+\w+/i', $src)
    );

    // Valid upload path
    $slot = cpr_ul_fresh_env($base);
    $setup = cpr_ul_setup_through_special($slot['env']);
    $run = orange_cpr_uploads_live_run($slot['env'], (string) $setup['job_id'], cpr_ul_req($setup));
    cpr_ul('valid_upload_path', !empty($run['ok']) && !empty($run['files_applied_count']), (string) ($run['code'] ?? $run['message'] ?? ''));
    cpr_ul('cp9_exists', orange_cpr_checkpoint_exists((string) $setup['cpr_root'], (string) $setup['job_id'], 'CP9'));
    cpr_ul('sealed_upload_report', is_array($run['upload_report'] ?? null) && orange_cpr_auth_verify_seal($run['upload_report']));
    cpr_ul('sealed_upload_manifest', is_array($run['upload_manifest'] ?? null) && orange_cpr_auth_verify_seal($run['upload_manifest']));
    cpr_ul('state_uploads_applying', ($run['job_state'] ?? '') === 'cpr_uploads_applying');
    cpr_ul('production_sql_disabled', ($run['production_sql_executed'] ?? true) === false);
    cpr_ul('production_uploads_not_mutated', ($run['production_uploads_mutated'] ?? true) === false);
    cpr_ul('scoped_only', !empty($run['scoped_only']));
    cpr_ul('recovery_metadata', is_array($run['recovery_metadata'] ?? null)
        && ($run['recovery_metadata']['execution_status'] ?? '') === 'uploads_complete');

    $idem = orange_cpr_uploads_live_run($slot['env'], (string) $setup['job_id'], cpr_ul_req($setup));
    cpr_ul('idempotent_complete', !empty($idem['ok']) && !empty($idem['idempotent']));

    $auditPath = orange_cpr_job_directory((string) $setup['cpr_root'], (string) $setup['job_id'])
        . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME;
    $auditRaw = is_file($auditPath) ? (string) file_get_contents($auditPath) : '';
    cpr_ul('audit_start', str_contains($auditRaw, 'cpr.uploads_live_start'));
    cpr_ul('audit_complete', str_contains($auditRaw, 'cpr.uploads_live_complete'));
    cpr_ul('audit_integrity_no_sql', !str_contains($auditRaw, 'production_sql_executed":true'));

    // Missing manifest
    $slotM = cpr_ul_fresh_env($base);
    $setupM = cpr_ul_setup_through_special($slotM['env']);
    $reqM = cpr_ul_req($setupM);
    unset($reqM['upload_manifest']);
    $miss = orange_cpr_uploads_live_run($slotM['env'], (string) $setupM['job_id'], $reqM);
    cpr_ul('missing_manifest', empty($miss['ok']) && ($miss['code'] ?? '') === ORANGE_CPR_UPLOADSLIVE_ERR_MANIFEST, (string) ($miss['code'] ?? ''));

    // Corrupt manifest
    $reqC = cpr_ul_req($setupM);
    unset($reqC['upload_manifest']['paths']);
    $corr = orange_cpr_uploads_live_run($slotM['env'], (string) $setupM['job_id'], $reqC);
    cpr_ul('corrupt_manifest', empty($corr['ok']) && ($corr['code'] ?? '') === ORANGE_CPR_UPLOADSLIVE_ERR_MANIFEST_CORRUPT, (string) ($corr['code'] ?? ''));

    // Country mismatch (path for another country)
    $slotX = cpr_ul_fresh_env($base);
    $setupX = cpr_ul_setup_through_special($slotX['env']);
    $pathsX = ['countries/sa/products/x.jpg'];
    $manX = cpr_ul_build_manifest((string) $setupX['job_id'], (string) $setupX['package_fingerprint'], $pathsX);
    // Force country_id match so fingerprint path uses wrong country prefix check
    $xc = orange_cpr_uploads_live_run($slotX['env'], (string) $setupX['job_id'], cpr_ul_req($setupX, [
        'upload_manifest' => $manX,
        'upload_artifacts' => cpr_ul_artifacts($pathsX),
    ]));
    cpr_ul('country_mismatch', empty($xc['ok']) && ($xc['code'] ?? '') === ORANGE_CPR_UPLOADSLIVE_ERR_COUNTRY, (string) ($xc['code'] ?? ''));

    // Contract mismatch (wrong job_id on manifest)
    $manJ = cpr_ul_build_manifest('wrong-job', (string) $setupX['package_fingerprint'], [
        'countries/kw/products/a.jpg',
    ]);
    $cm = orange_cpr_uploads_live_run($slotX['env'], (string) $setupX['job_id'], cpr_ul_req($setupX, [
        'upload_manifest' => $manJ,
        'upload_artifacts' => cpr_ul_artifacts($manJ['paths']),
    ]));
    cpr_ul('contract_mismatch', empty($cm['ok']) && ($cm['code'] ?? '') === ORANGE_CPR_UPLOADSLIVE_ERR_CONTRACT, (string) ($cm['code'] ?? ''));

    // Fingerprint mismatch
    $manF = cpr_ul_build_manifest((string) $setupX['job_id'], (string) $setupX['package_fingerprint'], [
        'countries/kw/products/a.jpg',
    ]);
    $manF['manifest_sha256'] = str_repeat('0', 64);
    $fpFail = orange_cpr_uploads_live_run($slotX['env'], (string) $setupX['job_id'], cpr_ul_req($setupX, [
        'upload_manifest' => $manF,
        'upload_artifacts' => cpr_ul_artifacts($manF['paths']),
    ]));
    cpr_ul('fingerprint_mismatch', empty($fpFail['ok']) && ($fpFail['code'] ?? '') === ORANGE_CPR_UPLOADSLIVE_ERR_FINGERPRINT, (string) ($fpFail['code'] ?? ''));

    // Replay attempt
    $replay = orange_cpr_uploads_live_run($slot['env'], (string) $setup['job_id'], cpr_ul_req($setup, [
        'force_replay' => true,
    ]));
    cpr_ul('replay_attempt', empty($replay['ok']) && ($replay['code'] ?? '') === ORANGE_CPR_UPLOADSLIVE_ERR_REPLAY, (string) ($replay['code'] ?? ''));

    // Resume from sealed checkpoint
    $slotR = cpr_ul_fresh_env($base);
    $setupR = cpr_ul_setup_through_special($slotR['env']);
    $partial = orange_cpr_uploads_live_run($slotR['env'], (string) $setupR['job_id'], cpr_ul_req($setupR, [
        'stop_after_index' => 0,
    ]));
    $resume = orange_cpr_uploads_live_run($slotR['env'], (string) $setupR['job_id'], cpr_ul_req($setupR, [
        'resume_from_index' => 1,
    ]));
    cpr_ul(
        'resume_from_sealed_checkpoint',
        !empty($partial['ok'])
        && empty($partial['uploads_complete'])
        && !empty($resume['ok'])
        && orange_cpr_checkpoint_exists((string) $setupR['cpr_root'], (string) $setupR['job_id'], 'CP9'),
        (string) (($resume['code'] ?? '') . '/' . ($partial['code'] ?? ''))
    );

    // Upload ordering (forced non-deterministic order refused)
    $slotO = cpr_ul_fresh_env($base);
    $setupO = cpr_ul_setup_through_special($slotO['env']);
    $reqO = cpr_ul_req($setupO);
    $sorted = $reqO['upload_manifest']['paths'];
    $wrongOrder = array_reverse($sorted);
    $ooo = orange_cpr_uploads_live_run($slotO['env'], (string) $setupO['job_id'], cpr_ul_req($setupO, [
        'force_path_order' => $wrongOrder,
    ]));
    cpr_ul('upload_ordering', empty($ooo['ok']) && ($ooo['code'] ?? '') === ORANGE_CPR_UPLOADSLIVE_ERR_ORDER, (string) ($ooo['code'] ?? ''));

    // Privilege bypass
    $slotP = cpr_ul_fresh_env($base);
    $setupP = cpr_ul_setup_through_special($slotP['env']);
    $nonSa = orange_cpr_uploads_live_run($slotP['env'], (string) $setupP['job_id'], cpr_ul_req($setupP, [
        'actor_is_super_admin' => false,
    ]));
    cpr_ul('privilege_bypass_denied', empty($nonSa['ok']) && ($nonSa['code'] ?? '') === ORANGE_CPR_UPLOADSLIVE_ERR_ACTOR);

    // Fail-closed inject + recovery metadata
    $slotF = cpr_ul_fresh_env($base);
    $setupF = cpr_ul_setup_through_special($slotF['env']);
    $failA = orange_cpr_uploads_live_run($slotF['env'], (string) $setupF['job_id'], cpr_ul_req($setupF, [
        'fail_at_index' => 1,
    ]));
    cpr_ul(
        'fail_closed_recovery_metadata',
        empty($failA['ok'])
        && is_array($failA['recovery_metadata'] ?? null)
        && !empty($failA['fail_closed'])
        && ($failA['production_sql_executed'] ?? true) === false,
        (string) ($failA['code'] ?? '')
    );

    cpr_ul('ops_enablement_still_false', orange_cpr_enablement_flag_read($slot['env']) === false);
    cpr_ul(
        'control_plane_uploads_flag',
        !empty(orange_cpr_p5_control_plane_snapshot()['uploads_apply_engine_implemented'])
    );
} catch (Throwable $e) {
    cpr_ul('exception', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . (string) $e->getLine());
} finally {
    cpr_ul_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
