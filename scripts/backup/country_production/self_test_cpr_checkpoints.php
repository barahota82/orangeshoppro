<?php

declare(strict_types=1);

/**
 * Self-test: CPR checkpoint engine (WP-P3-04).
 * Run: php scripts/backup/country_production/self_test_cpr_checkpoints.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_checkpoint_engine.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_state_engine.php';

$pass = 0;
$fail = 0;

function cpr_cp_t(string $name, bool $ok, string $detail = ''): void
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
function cpr_cp_fps(): array
{
    return [
        'schema_revision_expected' => 121,
        'boundary_policy_version' => 'C1.1',
        'dependency_graph_version' => '1',
        'registry_revision' => 121,
        'c4_report_hash' => str_repeat('b', 32),
        'c5_report_hash' => str_repeat('c', 32),
        'c6_report_hash' => str_repeat('d', 32),
        'c7_report_hash' => str_repeat('e', 32),
        'c8_report_hash' => str_repeat('f', 32),
        'c8_overall_result' => 'SAFE',
        'inventory_snapshot_id' => 'inv-1',
        'inventory_snapshot_hash' => str_repeat('1', 32),
        'production_db_identity_hash' => str_repeat('2', 32),
    ];
}

/**
 * @param array<string, mixed> $env
 * @return array{job_id:string,cpr_root:string}
 */
function cpr_cp_setup_job(array $env): array
{
    $job = orange_cpr_job_create($env, [
        'package_id' => 'pkg-cp',
        'package_fingerprint' => str_repeat('a', 32),
        'country_id' => 1,
        'country_code' => 'KW',
        'workflow' => 'A',
    ], 1);
    $jid = (string) $job['job_id'];
    orange_cpr_transition_apply($env, $jid, 'cpr_gates_validating', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
    ]);

    return ['job_id' => $jid, 'cpr_root' => orange_cpr_resolve_work_root($env)];
}

/**
 * @return array<string, mixed>
 */
function cpr_cp0_payload(): array
{
    return [
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
            'c4' => str_repeat('b', 32),
            'c5' => str_repeat('c', 32),
            'c6' => str_repeat('d', 32),
            'c7' => str_repeat('e', 32),
            'c8' => str_repeat('f', 32),
        ],
    ];
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p304_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0775, true);
$env = [
    'ORANGE_CPR_WORK_DIR' => $tmp,
    'ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false,
];

try {
    $setup = cpr_cp_setup_job($env);
    $jid = $setup['job_id'];
    $root = $setup['cpr_root'];

    // Create CP0
    $created = orange_cpr_checkpoint_create($env, $jid, 'CP0', cpr_cp0_payload(), [
        'written_by' => 'system',
    ]);
    cpr_cp_t('create_cp0', !empty($created['ok']) && !empty($created['atomic_rename']));

    // Load
    $loaded = orange_cpr_checkpoint_load($env, $jid, 'CP0');
    cpr_cp_t('load_cp0', !empty($loaded['ok']) && ($loaded['checkpoint']['checkpoint_id'] ?? '') === 'CP0');

    // Integrity
    $integ = orange_cpr_checkpoint_verify_integrity($loaded['checkpoint']);
    cpr_cp_t('integrity_ok', !empty($integ['ok']));

    // Version
    $ver = orange_cpr_checkpoint_validate_version($loaded['checkpoint']);
    cpr_cp_t('version_ok', !empty($ver['ok']));

    // Missing
    $missing = orange_cpr_checkpoint_load($env, $jid, 'CP4');
    cpr_cp_t('missing_checkpoint', empty($missing['ok']) && ($missing['code'] ?? '') === ORANGE_CPR_CP_ERR_MISSING);

    // OD-PIN: CP1 before CP4
    orange_cpr_transition_apply($env, $jid, 'cpr_contract_frozen', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'contract_fingerprints_ok' => true,
    ]);
    // Need CP2 first for chain — write CP2 in allowed state (contract_frozen allowed)
    $cp2 = orange_cpr_checkpoint_create($env, $jid, 'CP2', [
        'workflow' => 'A',
        'wfa_protections_ack' => true,
        'approval_fingerprint' => str_repeat('9', 32),
    ], ['written_by' => 'system']);
    cpr_cp_t('create_cp2', !empty($cp2['ok']), (string) ($cp2['message'] ?? ''));

    orange_cpr_contract_freeze_initial($env, $jid, cpr_cp_fps(), 1);
    $cp3 = orange_cpr_checkpoint_create($env, $jid, 'CP3', [
        'contract_revision' => 1,
        'contract_phase' => 'pre_pin',
        'package_fingerprint' => str_repeat('a', 32),
        'fingerprint_digest' => hash('sha256', 'fp'),
    ], ['written_by' => 'system']);
    cpr_cp_t('create_cp3', !empty($cp3['ok']), (string) ($cp3['message'] ?? ''));

    orange_cpr_transition_apply($env, $jid, 'cpr_maintenance_on', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
    ]);
    // Attempt CP1 before CP4 while in wrong state too — move to anchor_pinning first
    orange_cpr_transition_apply($env, $jid, 'cpr_anchor_pinning', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
    ]);
    $earlyCp1 = orange_cpr_checkpoint_create($env, $jid, 'CP1', [
        'session_full_backup_id' => 'fb-1',
        'session_full_backup_fingerprint' => str_repeat('3', 32),
        'verified' => true,
        'pinned' => true,
        'created_under_maintenance' => true,
        'reused_existing_backup' => false,
        'cp4_reference' => 'CP4_maintenance_verified.json',
    ], ['written_by' => 'system']);
    cpr_cp_t('od_pin_reject_cp1_before_cp4', empty($earlyCp1['ok'])
        && in_array($earlyCp1['code'] ?? '', [ORANGE_CPR_CP_ERR_OD_PIN, ORANGE_CPR_CP_ERR_PREREQ], true),
        (string) ($earlyCp1['code'] ?? '') . ' ' . (string) ($earlyCp1['message'] ?? ''));

    // Back to maint for CP4
    // Job is in anchor_pinning — need CP4 in maintenance_on. Re-read and force state for scaffold test of CP4 write.
    // Legal: only from maintenance_on. Transition back is illegal — create fresh job for CP4 path.
    $setup2 = cpr_cp_setup_job($env);
    $jid2 = $setup2['job_id'];
    orange_cpr_checkpoint_create($env, $jid2, 'CP0', cpr_cp0_payload(), ['written_by' => 'system']);
    orange_cpr_transition_apply($env, $jid2, 'cpr_contract_frozen', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'contract_fingerprints_ok' => true,
    ]);
    orange_cpr_checkpoint_create($env, $jid2, 'CP2', [
        'workflow' => 'A',
        'wfa_protections_ack' => true,
        'approval_fingerprint' => str_repeat('8', 32),
    ], ['written_by' => 'system']);
    orange_cpr_contract_freeze_initial($env, $jid2, cpr_cp_fps(), 1);
    orange_cpr_checkpoint_create($env, $jid2, 'CP3', [
        'contract_revision' => 1,
        'contract_phase' => 'pre_pin',
        'package_fingerprint' => str_repeat('a', 32),
        'fingerprint_digest' => hash('sha256', 'fp2'),
    ], ['written_by' => 'system']);
    orange_cpr_transition_apply($env, $jid2, 'cpr_maintenance_on', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
    ]);
    $cp4 = orange_cpr_checkpoint_create($env, $jid2, 'CP4', [
        'global_maintenance_on' => true,
        'write_block_proof' => 'proof-1',
        'maint_entered_at' => gmdate('c'),
    ], ['written_by' => 'system']);
    cpr_cp_t('create_cp4', !empty($cp4['ok']), (string) ($cp4['message'] ?? ''));

    orange_cpr_transition_apply($env, $jid2, 'cpr_anchor_pinning', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
    ]);
    $cp1 = orange_cpr_checkpoint_create($env, $jid2, 'CP1', [
        'session_full_backup_id' => 'fb-new',
        'session_full_backup_fingerprint' => str_repeat('4', 32),
        'verified' => true,
        'pinned' => true,
        'created_under_maintenance' => true,
        'reused_existing_backup' => false,
        'cp4_reference' => 'CP4_maintenance_verified.json',
    ], ['written_by' => 'system']);
    cpr_cp_t('create_cp1_after_cp4', !empty($cp1['ok']), (string) ($cp1['message'] ?? ''));

    $reuse = orange_cpr_checkpoint_validate_payload('CP1', [
        'session_full_backup_id' => 'old',
        'session_full_backup_fingerprint' => str_repeat('4', 32),
        'verified' => true,
        'pinned' => true,
        'created_under_maintenance' => true,
        'reused_existing_backup' => true,
        'cp4_reference' => 'x',
    ]);
    cpr_cp_t('reject_reused_backup', empty($reuse['ok']) && ($reuse['code'] ?? '') === ORANGE_CPR_CP_ERR_OD_PIN);

    // Atomic rename evidence + MANIFEST
    $manifest = orange_cpr_checkpoint_manifest_read($setup2['cpr_root'], $jid2);
    cpr_cp_t('manifest_present', is_array($manifest) && isset($manifest['committed']));

    // Torn tmp recovery
    $tmpDir = orange_cpr_checkpoints_tmp_directory($setup2['cpr_root'], $jid2);
    @mkdir($tmpDir, 0775, true);
    file_put_contents($tmpDir . DIRECTORY_SEPARATOR . 'CP5_torn.json', '{"partial":true}');
    $rec = orange_cpr_checkpoint_recover($env, $jid2);
    cpr_cp_t('recover_ignores_tmp', !empty($rec['ok']) && ($rec['torn_tmp_ignored'] ?? []) !== []);
    cpr_cp_t('recover_latest', ($rec['latest_good_checkpoint_id'] ?? '') === 'CP1');

    // Corrupted checkpoint — no silent repair
    $cp0Path = orange_cpr_checkpoint_final_path($root, $jid, 'CP0');
    file_put_contents($cp0Path, '{not-json');
    $corruptLoad = orange_cpr_checkpoint_load($env, $jid, 'CP0');
    cpr_cp_t('corrupt_load', empty($corruptLoad['ok']) && ($corruptLoad['code'] ?? '') === ORANGE_CPR_CP_ERR_CORRUPT);
    $corruptRec = orange_cpr_checkpoint_recover($env, $jid);
    cpr_cp_t('corrupt_recover_no_repair', empty($corruptRec['ok'])
        && ($corruptRec['code'] ?? '') === ORANGE_CPR_CP_ERR_CORRUPT);
    $refuse = orange_cpr_checkpoint_refuse_silent_repair();
    cpr_cp_t('silent_repair_forbidden', empty($refuse['ok'])
        && ($refuse['code'] ?? '') === ORANGE_CPR_CP_ERR_REPAIR);

    // Version mismatch
    $setup3 = cpr_cp_setup_job($env);
    $jid3 = $setup3['job_id'];
    orange_cpr_checkpoint_create($env, $jid3, 'CP0', cpr_cp0_payload(), ['written_by' => 'system']);
    $p = orange_cpr_checkpoint_final_path($setup3['cpr_root'], $jid3, 'CP0');
    $doc = json_decode((string) file_get_contents($p), true);
    $doc['schema_version'] = '9.9';
    // Keep old hash so integrity may fail first — set hash after version change for version test path
    unset($doc['content_sha256']);
    $doc['content_sha256'] = orange_cpr_checkpoint_integrity_hash($doc);
    file_put_contents($p, json_encode($doc));
    $verBad = orange_cpr_checkpoint_load($env, $jid3, 'CP0');
    cpr_cp_t('version_mismatch', empty($verBad['ok']) && ($verBad['code'] ?? '') === ORANGE_CPR_CP_ERR_VERSION);

    // Integrity tamper
    $setup4 = cpr_cp_setup_job($env);
    $jid4 = $setup4['job_id'];
    orange_cpr_checkpoint_create($env, $jid4, 'CP0', cpr_cp0_payload(), ['written_by' => 'system']);
    $p4 = orange_cpr_checkpoint_final_path($setup4['cpr_root'], $jid4, 'CP0');
    $doc4 = json_decode((string) file_get_contents($p4), true);
    $doc4['payload']['c5_recovery_score'] = 1;
    file_put_contents($p4, json_encode($doc4));
    $tamper = orange_cpr_checkpoint_load($env, $jid4, 'CP0');
    cpr_cp_t('integrity_fail_on_tamper', empty($tamper['ok'])
        && ($tamper['code'] ?? '') === ORANGE_CPR_CP_ERR_INTEGRITY);

    // Lifecycle purge tmp
    $purge = orange_cpr_checkpoint_lifecycle_purge_tmp($env, $jid2);
    cpr_cp_t('lifecycle_purge_tmp', !empty($purge['ok']));

    // Post-PONR discipline: CP6 without PONR / CP-A
    orange_cpr_transition_apply($env, $jid2, 'cpr_pre_ponr', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'session_full_backup_pinned' => true,
    ]);
    // Still need runbook/CP5/CPA before PONR — try CP6 in deleting without CPA
    orange_cpr_transition_apply($env, $jid2, 'cpr_deleting', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'phrase_ok' => true,
        'reauth_ok' => true,
        'runbook_completed' => true,
        'c8_overall_result' => 'SAFE',
    ]);
    $cp6bad = orange_cpr_checkpoint_create($env, $jid2, 'CP6', [
        'tables_completed' => 1,
        'delete_order_version' => '1',
        'ponr_entered_at' => gmdate('c'),
    ], ['written_by' => 'system']);
    cpr_cp_t('post_ponr_requires_cpa', empty($cp6bad['ok'])
        && in_array($cp6bad['code'] ?? '', [ORANGE_CPR_CP_ERR_PONR, ORANGE_CPR_CP_ERR_PREREQ], true),
        (string) ($cp6bad['code'] ?? ''));

    // Audit binding
    $audit = (string) file_get_contents(orange_cpr_audit_file_path($setup2['cpr_root'], $jid2));
    cpr_cp_t('audit_checkpoint_commit', str_contains($audit, 'cpr.checkpoint_commit'));

    // Job last_checkpoint_id binding
    $job2 = orange_cpr_job_read($setup2['cpr_root'], $jid2);
    cpr_cp_t('job_last_checkpoint_bound', ($job2['last_checkpoint_id'] ?? null) !== null);

    // Enablement blocks
    $envTrue = $env;
    $envTrue['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED'] = true;
    $blocked = orange_cpr_checkpoint_validate_write($envTrue, $jid4, 'CP0', cpr_cp0_payload());
    cpr_cp_t('enablement_blocks', empty($blocked['ok'])
        && ($blocked['code'] ?? '') === ORANGE_CPR_CP_ERR_ENABLEMENT);
} catch (Throwable $e) {
    cpr_cp_t('suite_exception', false, $e->getMessage());
}

if (is_dir($tmp)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($tmp);
}

echo "\n{$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
