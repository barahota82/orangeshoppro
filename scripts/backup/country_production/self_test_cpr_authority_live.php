<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Authority, Runbook & RESTORE Ceremony (WP-P4-07).
 * Run: php scripts/backup/country_production/self_test_cpr_authority_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_authority_live.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_state_engine.php';

$pass = 0;
$fail = 0;

function cpr_al(string $name, bool $ok, string $detail = ''): void
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
function cpr_al_env(): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p407_' . bin2hex(random_bytes(4));
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

function cpr_al_cleanup(string $base): void
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
function cpr_al_reports(): array
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
function cpr_al_fps(array $reports, string $pkgFp): array
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
 * @param array<string, mixed> $meta
 * @return array<string, mixed>
 */
function cpr_al_evidence(array $reports, array $meta): array
{
    return [
        'enablement' => true,
        'od_enable_preconditions_complete' => true,
        'pin_capability' => true,
        'certification' => ['result' => 'PASS'],
        'host_preflight' => ['pass' => true],
        'package' => [
            'status' => 'finalized',
            'package_id' => (string) $meta['package_id'],
            'fingerprint' => (string) $meta['package_fingerprint'],
        ],
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
}

/**
 * Through lock + CP5 (optional runbook) ready for gates or runbook create.
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function cpr_al_setup_base(array $env, bool $withRunbookScaffold = true): array
{
    $reports = cpr_al_reports();
    $fp = hash('sha256', 'fp-al-' . bin2hex(random_bytes(4)));
    $pkgId = 'pkg-al-' . substr($fp, 0, 8);
    $job = orange_cpr_job_create($env, [
        'package_id' => $pkgId,
        'package_fingerprint' => $fp,
        'country_id' => 1,
        'country_code' => 'KW',
        'workflow' => 'A',
    ], 1);
    $jid = (string) $job['job_id'];
    $fps = cpr_al_fps($reports, $fp);

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
        'worker_id' => 'al-worker-1',
    ]);
    if (empty($acq['ok'])) {
        throw new RuntimeException('lock setup failed: ' . (string) ($acq['code'] ?? ''));
    }

    if ($withRunbookScaffold) {
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
            'audit_record_id' => 'aud-al-1',
        ], ['written_by' => 'super_admin']);
    }
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
        'worker_id' => (string) ($acq['worker_id'] ?? 'al-worker-1'),
        'reports' => $reports,
        'package_id' => $pkgId,
        'package_fingerprint' => $fp,
        'session_full_backup_id' => $sessionId,
    ];
}

/**
 * Base + live gates PASS.
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function cpr_al_setup_gated(array $env): array
{
    $setup = cpr_al_setup_base($env, true);
    $ev = cpr_al_evidence($setup['reports'], $setup);
    $gate = orange_cpr_gates_live_evaluate($env, $setup['job_id'], [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setup['lease'],
        'worker_id' => (string) $setup['worker_id'],
        'evidence' => $ev,
    ]);
    if (empty($gate['ok']) || empty($gate['all_gates_pass'])) {
        throw new RuntimeException('gates_live setup failed: ' . (string) ($gate['code'] ?? ''));
    }
    $setup['gates_live_id'] = (string) ($gate['gates_live_id'] ?? '');

    return $setup;
}

/**
 * @param array<string, mixed> $setup
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function cpr_al_req(array $setup, array $extra = []): array
{
    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setup['lease'],
        'worker_id' => (string) $setup['worker_id'],
        'all_minimum_items_confirmed' => true,
        'phrase' => 'RESTORE',
        'password_reauth_ok' => true,
    ], $extra);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $setup
 */
function cpr_al_release(array $env, array $setup): void
{
    orange_cpr_lock_release($env, (string) $setup['job_id'], [
        'lease_token' => (string) $setup['lease'],
        'worker_id' => (string) $setup['worker_id'],
    ]);
}

$bundle = cpr_al_env();
$env = $bundle['env'];
$base = $bundle['base'];
$cprRoot = $bundle['cpr'];

try {
    cpr_al('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P8-03-owner-cert-decision');

    // --- Valid authorization (full ceremony) ---
    $setup = cpr_al_setup_gated($env);
    $ok = orange_cpr_authority_live_ceremony($env, $setup['job_id'], cpr_al_req($setup));
    cpr_al('valid_authorization', !empty($ok['ok']) && !empty($ok['ponr_authorized']), (string) ($ok['code'] ?? $ok['message'] ?? ''));
    cpr_al('sealed_runbook', is_array($ok['runbook_live'] ?? null) && orange_cpr_auth_verify_seal($ok['runbook_live']));
    cpr_al('sealed_authority_live', is_array($ok['authority_live'] ?? null) && orange_cpr_auth_verify_seal($ok['authority_live']));
    cpr_al('sealed_p3_authorization', is_array($ok['authorization'] ?? null) && orange_cpr_auth_verify_seal($ok['authorization']));
    cpr_al('no_ponr_mutation', empty($ok['ponr_crossed']) && empty($ok['ponr_mutation_executed']));
    cpr_al('enablement_false', ($ok['enablement_flag_observed'] ?? null) === false);
    cpr_al_release($env, $setup);

    // --- Invalid actor (Country Admin) ---
    $setupA = cpr_al_setup_gated($env);
    $badActor = orange_cpr_authority_live_ceremony($env, $setupA['job_id'], cpr_al_req($setupA, [
        'actor_is_super_admin' => false,
        'country_admin_is_executor' => true,
        'actor_admin_id' => 99,
    ]));
    cpr_al(
        'invalid_actor',
        empty($badActor['ok']) && ($badActor['code'] ?? '') === ORANGE_CPR_AUTHLIVE_ERR_ACTOR,
        (string) ($badActor['code'] ?? '')
    );
    cpr_al_release($env, $setupA);

    // --- Missing RESTORE phrase ---
    $setupP0 = cpr_al_setup_gated($env);
    orange_cpr_authority_live_complete_runbook($env, $setupP0['job_id'], cpr_al_req($setupP0));
    $missPhrase = orange_cpr_authority_live_authorize($env, $setupP0['job_id'], cpr_al_req($setupP0, [
        'phrase' => '',
    ]));
    cpr_al(
        'missing_restore_phrase',
        empty($missPhrase['ok']) && ($missPhrase['code'] ?? '') === ORANGE_CPR_AUTHLIVE_ERR_PHRASE,
        (string) ($missPhrase['code'] ?? '')
    );
    cpr_al_release($env, $setupP0);

    // --- Wrong RESTORE phrase ---
    $setupP1 = cpr_al_setup_gated($env);
    orange_cpr_authority_live_complete_runbook($env, $setupP1['job_id'], cpr_al_req($setupP1));
    $wrongPhrase = orange_cpr_authority_live_authorize($env, $setupP1['job_id'], cpr_al_req($setupP1, [
        'phrase' => 'restore',
    ]));
    cpr_al(
        'wrong_restore_phrase',
        empty($wrongPhrase['ok']) && ($wrongPhrase['code'] ?? '') === ORANGE_CPR_AUTHLIVE_ERR_PHRASE,
        (string) ($wrongPhrase['code'] ?? '')
    );
    cpr_al_release($env, $setupP1);

    // --- Missing password re-auth ---
    $setupR = cpr_al_setup_gated($env);
    orange_cpr_authority_live_complete_runbook($env, $setupR['job_id'], cpr_al_req($setupR));
    $missReauth = orange_cpr_authority_live_authorize($env, $setupR['job_id'], cpr_al_req($setupR, [
        'password_reauth_ok' => false,
    ]));
    cpr_al(
        'missing_password_reauth',
        empty($missReauth['ok']) && ($missReauth['code'] ?? '') === ORANGE_CPR_AUTHLIVE_ERR_REAUTH,
        (string) ($missReauth['code'] ?? '')
    );
    cpr_al_release($env, $setupR);

    // --- Incomplete Runbook ---
    $setupRb = cpr_al_setup_base($env, false); // no runbook scaffold
    $incRb = orange_cpr_authority_live_complete_runbook($env, $setupRb['job_id'], cpr_al_req($setupRb, [
        'all_minimum_items_confirmed' => false,
    ]));
    cpr_al(
        'incomplete_runbook_flag',
        empty($incRb['ok']) && ($incRb['code'] ?? '') === ORANGE_CPR_AUTHLIVE_ERR_RUNBOOK,
        (string) ($incRb['code'] ?? '')
    );
    $badRb = orange_cpr_authority_live_complete_runbook($env, $setupRb['job_id'], cpr_al_req($setupRb, [
        'runbook_overrides' => ['restore_package_id' => 'wrong-package'],
    ]));
    cpr_al(
        'incomplete_runbook_mismatch',
        empty($badRb['ok']) && ($badRb['code'] ?? '') === ORANGE_CPR_AUTHLIVE_ERR_RUNBOOK,
        (string) ($badRb['code'] ?? '')
    );
    cpr_al_release($env, $setupRb);

    // --- Replay attempt ---
    $setupRp = cpr_al_setup_gated($env);
    $first = orange_cpr_authority_live_ceremony($env, $setupRp['job_id'], cpr_al_req($setupRp));
    cpr_al('replay_setup_ok', !empty($first['ok']));
    $replayKnob = orange_cpr_authority_live_authorize($env, $setupRp['job_id'], cpr_al_req($setupRp, [
        'replay_previous' => true,
    ]));
    cpr_al(
        'replay_attempt_knob',
        empty($replayKnob['ok']) && ($replayKnob['code'] ?? '') === ORANGE_CPR_AUTHLIVE_ERR_REPLAY,
        (string) ($replayKnob['code'] ?? '')
    );
    $replayDup = orange_cpr_authority_live_authorize($env, $setupRp['job_id'], cpr_al_req($setupRp));
    cpr_al(
        'replay_attempt_duplicate',
        empty($replayDup['ok']) && in_array(($replayDup['code'] ?? ''), [
            ORANGE_CPR_AUTHLIVE_ERR_DUPLICATE,
            ORANGE_CPR_AUTHLIVE_ERR_REPLAY,
        ], true),
        (string) ($replayDup['code'] ?? '')
    );
    cpr_al_release($env, $setupRp);

    // --- Privilege escalation attempt ---
    $setupEsc = cpr_al_setup_gated($env);
    $esc = orange_cpr_authority_live_ceremony($env, $setupEsc['job_id'], cpr_al_req($setupEsc, [
        'escalate_to_ponr' => true,
    ]));
    cpr_al(
        'privilege_escalation_attempt',
        empty($esc['ok']) && ($esc['code'] ?? '') === ORANGE_CPR_AUTHLIVE_ERR_PRIVILEGE,
        (string) ($esc['code'] ?? '')
    );
    cpr_al_release($env, $setupEsc);

    // --- Missing sealed artifacts (gates_live) ---
    $setupMiss = cpr_al_setup_gated($env);
    orange_cpr_authority_live_complete_runbook($env, $setupMiss['job_id'], cpr_al_req($setupMiss));
    $glPath = orange_cpr_gates_live_latest_path($cprRoot, $setupMiss['job_id']);
    if (is_file($glPath)) {
        @unlink($glPath);
    }
    $missArt = orange_cpr_authority_live_authorize($env, $setupMiss['job_id'], cpr_al_req($setupMiss));
    cpr_al(
        'missing_sealed_artifacts',
        empty($missArt['ok']) && in_array(($missArt['code'] ?? ''), [
            ORANGE_CPR_AUTHLIVE_ERR_GATE,
            ORANGE_CPR_AUTHLIVE_ERR_ARTIFACT,
        ], true),
        (string) ($missArt['code'] ?? '')
    );
    cpr_al_release($env, $setupMiss);

    // --- Corrupted authorization record ---
    $setupCorr = cpr_al_setup_gated($env);
    $corrOk = orange_cpr_authority_live_ceremony($env, $setupCorr['job_id'], cpr_al_req($setupCorr));
    cpr_al('corrupt_setup_ok', !empty($corrOk['ok']));
    $authLatest = orange_cpr_auth_live_latest_path($cprRoot, $setupCorr['job_id']);
    $raw = (string) file_get_contents($authLatest);
    $doc = json_decode($raw, true);
    if (is_array($doc)) {
        $doc['phrase_accepted'] = false; // break seal
        file_put_contents($authLatest, json_encode($doc, JSON_PRETTY_PRINT));
    }
    $loaded = orange_cpr_auth_live_load_latest($cprRoot, $setupCorr['job_id']);
    cpr_al('corrupted_authorization_record', $loaded === null);

    // Also break P3 authorization seal and assert_usable fails
    $p3Latest = orange_cpr_auth_latest_path($cprRoot, $setupCorr['job_id']);
    $p3raw = (string) file_get_contents($p3Latest);
    $p3doc = json_decode($p3raw, true);
    if (is_array($p3doc)) {
        $p3doc['actor_admin_id'] = 999;
        file_put_contents($p3Latest, json_encode($p3doc, JSON_PRETTY_PRINT));
    }
    $usable = orange_cpr_ponr_authorization_assert_usable(
        $env,
        $setupCorr['job_id'],
        (string) ($corrOk['one_time_authorization_id'] ?? '')
    );
    cpr_al(
        'corrupted_p3_authorization_unusable',
        empty($usable['ok']) && in_array(($usable['code'] ?? ''), ['auth_unsealed', 'auth_missing', 'auth_fingerprint_drift', 'auth_replay'], true),
        (string) ($usable['code'] ?? '')
    );
    cpr_al_release($env, $setupCorr);

    // Ops enablement remains false
    cpr_al('ops_enablement_still_false', orange_cpr_enablement_flag_read($env) === false);
} catch (Throwable $e) {
    cpr_al('exception', false, $e->getMessage());
} finally {
    cpr_al_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
