<?php

declare(strict_types=1);

/**
 * Self-test: CPR state engine (WP-P3-03).
 * Run: php scripts/backup/country_production/self_test_cpr_state_engine.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_state_engine.php';

$pass = 0;
$fail = 0;

function cpr_st(string $name, bool $ok, string $detail = ''): void
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
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function cpr_st_new_job(array $env, string $workflow = 'A', int $countryId = 1): array
{
    return orange_cpr_job_create($env, [
        'package_id' => 'pkg-st-' . $countryId,
        'package_fingerprint' => str_repeat((string) $countryId, 32),
        'country_id' => $countryId,
        'country_code' => $countryId === 1 ? 'KW' : 'SA',
        'workflow' => $workflow,
    ], 7);
}

/**
 * @return array<string, mixed>
 */
function cpr_st_fps(): array
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

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p303_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0775, true);
$env = [
    'ORANGE_CPR_WORK_DIR' => $tmp,
    'ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false,
];

try {
    // --- Legal pre-PONR ---
    $job = cpr_st_new_job($env, 'A', 1);
    $jid = (string) $job['job_id'];
    $r = orange_cpr_transition_apply($env, $jid, 'cpr_gates_validating', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'trigger' => 'start_validation',
    ]);
    cpr_st('legal_T01', !empty($r['ok']) && ($r['transition_id'] ?? '') === 'T01');

    $r = orange_cpr_transition_apply($env, $jid, 'cpr_contract_frozen', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'trigger' => 'gates_ok_wfa_freeze',
        'contract_fingerprints_ok' => true,
    ]);
    cpr_st('legal_T03', !empty($r['ok']) && ($r['transition_id'] ?? '') === 'T03');
    orange_cpr_contract_freeze_initial($env, $jid, cpr_st_fps(), 7);

    $r = orange_cpr_transition_apply($env, $jid, 'cpr_maintenance_on', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'trigger' => 'enter_global_maint',
    ]);
    cpr_st('legal_T06', !empty($r['ok']));

    $r = orange_cpr_transition_apply($env, $jid, 'cpr_anchor_pinning', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
    ]);
    cpr_st('legal_T07', !empty($r['ok']));

    $r = orange_cpr_transition_apply($env, $jid, 'cpr_pre_ponr', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'session_full_backup_pinned' => true,
    ]);
    cpr_st('legal_T08', !empty($r['ok']));

    // T09 scaffold record only — no mutation engines
    $r = orange_cpr_transition_apply($env, $jid, 'cpr_deleting', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'trigger' => 'authorize_ponr_start_delete',
        'phrase_ok' => true,
        'reauth_ok' => true,
        'runbook_completed' => true,
        'c8_overall_result' => 'SAFE',
    ]);
    cpr_st('legal_T09_scaffold', !empty($r['ok']) && ($r['ponr_mutation_executed'] ?? true) === false);
    $job = orange_cpr_job_read(orange_cpr_resolve_work_root($env), $jid);
    cpr_st('ponr_crossed_after_T09', !empty($job['ponr_crossed']));
    cpr_st('mutation_engines_still_off', ($job['mutation_engines']['delete'] ?? true) === false);

    // Fail-pause
    $r = orange_cpr_transition_apply($env, $jid, 'cpr_paused_delete_failed', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'trigger' => 'delete_fail',
    ]);
    cpr_st('fail_pause_T30', !empty($r['ok']) && ($r['to_state'] ?? '') === 'cpr_paused_delete_failed');

    // Resume eligibility
    $elig = orange_cpr_resume_eligibility($job = orange_cpr_job_read(orange_cpr_resolve_work_root($env), $jid), [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'safe_resume' => true,
    ]);
    cpr_st('resume_eligible_sa', !empty($elig['eligible']) && ($elig['transition_id'] ?? '') === 'T40');

    $eligCa = orange_cpr_resume_eligibility($job, [
        'actor' => ORANGE_CPR_ACTOR_COUNTRY_ADMIN,
        'safe_resume' => true,
    ]);
    cpr_st('resume_blocked_country_admin', empty($eligCa['eligible'])
        && ($eligCa['code'] ?? '') === ORANGE_CPR_ERR_COUNTRY_ADMIN_FORBIDDEN);

    $eligUnsafe = orange_cpr_resume_eligibility($job, [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'safe_resume' => false,
    ]);
    cpr_st('resume_blocked_unsafe', empty($eligUnsafe['eligible']));

    // Rollback eligibility
    $rb = orange_cpr_rollback_eligibility($job, ['actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN]);
    cpr_st('rollback_eligible_sa', !empty($rb['eligible']) && ($rb['transition_id'] ?? '') === 'T50');

    $rbCa = orange_cpr_rollback_eligibility($job, ['actor' => ORANGE_CPR_ACTOR_COUNTRY_ADMIN]);
    cpr_st('rollback_blocked_country_admin', empty($rbCa['eligible']));

    $rbAuto = orange_cpr_rollback_eligibility($job, [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'auto_rollback' => true,
    ]);
    cpr_st('rollback_auto_forbidden', empty($rbAuto['eligible'])
        && ($rbAuto['code'] ?? '') === ORANGE_CPR_ERR_AUTO_ROLLBACK);

    // Legal resume apply
    $r = orange_cpr_transition_apply($env, $jid, 'cpr_deleting', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'trigger' => 'resume',
        'safe_resume' => true,
        'phrase_ok' => true,
        'reauth_ok' => true,
    ]);
    cpr_st('legal_resume_T40', !empty($r['ok']));

    // Illegal: active post-PONR → rollback without pause (auto-rollback pattern)
    $bad = orange_cpr_transition_validate($env, $jid, 'cpr_rolling_back', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'trigger' => 'rollback_action',
    ]);
    cpr_st('illegal_active_to_rollback', empty($bad['ok'])
        && ($bad['code'] ?? '') === ORANGE_CPR_ERR_ILLEGAL_TRANSITION);

    // Illegal: skip delete
    $jobB = cpr_st_new_job($env, 'A', 2);
    $jidB = (string) $jobB['job_id'];
    orange_cpr_transition_apply($env, $jidB, 'cpr_gates_validating', ['actor' => ORANGE_CPR_ACTOR_SYSTEM]);
    orange_cpr_transition_apply($env, $jidB, 'cpr_contract_frozen', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'contract_fingerprints_ok' => true,
    ]);
    orange_cpr_contract_freeze_initial($env, $jidB, cpr_st_fps(), 1);
    orange_cpr_transition_apply($env, $jidB, 'cpr_maintenance_on', ['actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN]);
    orange_cpr_transition_apply($env, $jidB, 'cpr_anchor_pinning', ['actor' => ORANGE_CPR_ACTOR_SYSTEM]);
    orange_cpr_transition_apply($env, $jidB, 'cpr_pre_ponr', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'session_full_backup_pinned' => true,
    ]);
    $skip = orange_cpr_transition_validate($env, $jidB, 'cpr_importing', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
    ]);
    cpr_st('illegal_skip_delete', empty($skip['ok'])
        && ($skip['code'] ?? '') === ORANGE_CPR_ERR_ILLEGAL_TRANSITION);

    // Pre-PONR cancellation
    $jobC = cpr_st_new_job($env, 'A', 3);
    $jidC = (string) $jobC['job_id'];
    $cancelled = orange_cpr_job_cancel($env, $jidC, 7, 'test-cancel');
    cpr_st('pre_ponr_cancel', ($cancelled['state'] ?? '') === 'cpr_cancelled_pre_ponr');

    // Terminal: cannot revive
    $revive = orange_cpr_transition_validate($env, $jidC, 'cpr_pending', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
    ]);
    cpr_st('terminal_no_revive', empty($revive['ok']));

    // Maint release requires Super Admin + runbook
    $jobD = cpr_st_new_job($env, 'A', 4);
    $jidD = (string) $jobD['job_id'];
    orange_cpr_job_cancel($env, $jidD, 1, 'x');
    $maintNoRunbook = orange_cpr_transition_validate($env, $jidD, 'cpr_maintenance_released', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'trigger' => 'release_maint',
    ]);
    cpr_st('maint_release_needs_runbook', empty($maintNoRunbook['ok'])
        && ($maintNoRunbook['code'] ?? '') === ORANGE_CPR_ERR_RUNBOOK);

    $maintCa = orange_cpr_transition_validate($env, $jidD, 'cpr_maintenance_released', [
        'actor' => ORANGE_CPR_ACTOR_COUNTRY_ADMIN,
        'trigger' => 'release_maint',
        'runbook_completed' => true,
    ]);
    cpr_st('maint_release_blocked_country_admin', empty($maintCa['ok']));

    $maintOk = orange_cpr_transition_apply($env, $jidD, 'cpr_maintenance_released', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'trigger' => 'release_maint',
        'runbook_completed' => true,
    ]);
    cpr_st('maint_release_sa_runbook', !empty($maintOk['ok']));
    cpr_st('terminal_maint_released', in_array('cpr_maintenance_released', orange_cpr_terminal_states(), true));

    // Timeout alone forbidden
    $jobE = cpr_st_new_job($env, 'A', 5);
    $to = orange_cpr_transition_validate($env, (string) $jobE['job_id'], 'cpr_failed_pre_ponr', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'timeout_alone' => true,
    ]);
    cpr_st('timeout_alone_forbidden', empty($to['ok'])
        && ($to['code'] ?? '') === ORANGE_CPR_ERR_TIMEOUT_ALONE);

    // Auto unlock hard refuse
    $unlock = orange_cpr_refuse_post_ponr_auto_unlock();
    cpr_st('auto_unlock_refuse', empty($unlock['ok'])
        && ($unlock['code'] ?? '') === ORANGE_CPR_ERR_AUTO_UNLOCK);

    // Mutation engine invoke blocked
    $jobF = cpr_st_new_job($env, 'A', 6);
    $jidF = (string) $jobF['job_id'];
    orange_cpr_transition_apply($env, $jidF, 'cpr_gates_validating', ['actor' => ORANGE_CPR_ACTOR_SYSTEM]);
    orange_cpr_transition_apply($env, $jidF, 'cpr_contract_frozen', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'contract_fingerprints_ok' => true,
    ]);
    orange_cpr_contract_freeze_initial($env, $jidF, cpr_st_fps(), 1);
    orange_cpr_transition_apply($env, $jidF, 'cpr_maintenance_on', ['actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN]);
    orange_cpr_transition_apply($env, $jidF, 'cpr_anchor_pinning', ['actor' => ORANGE_CPR_ACTOR_SYSTEM]);
    orange_cpr_transition_apply($env, $jidF, 'cpr_pre_ponr', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'session_full_backup_pinned' => true,
    ]);
    $mut = orange_cpr_transition_validate($env, $jidF, 'cpr_deleting', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'phrase_ok' => true,
        'reauth_ok' => true,
        'runbook_completed' => true,
        'c8_overall_result' => 'SAFE',
        'invoke_delete_engine' => true,
    ]);
    cpr_st('delete_engine_blocked', empty($mut['ok'])
        && ($mut['code'] ?? '') === ORANGE_CPR_ERR_MUTATION);

    // Identity binding
    $idBad = orange_cpr_transition_validate($env, $jidF, 'cpr_deleting', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'phrase_ok' => true,
        'reauth_ok' => true,
        'runbook_completed' => true,
        'c8_overall_result' => 'SAFE',
        'country_id' => 999,
    ]);
    cpr_st('identity_country_bind', empty($idBad['ok'])
        && ($idBad['code'] ?? '') === ORANGE_CPR_ERR_IDENTITY);

    // Checkpoint binding
    $jobG = orange_cpr_job_read(orange_cpr_resolve_work_root($env), $jidF);
    $jobG['last_checkpoint_id'] = 'CP0';
    orange_cpr_job_write(orange_cpr_resolve_work_root($env), $jidF, $jobG);
    $cpBad = orange_cpr_transition_validate($env, $jidF, 'cpr_deleting', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'phrase_ok' => true,
        'reauth_ok' => true,
        'runbook_completed' => true,
        'c8_overall_result' => 'SAFE',
        'expected_checkpoint_id' => 'CP1',
    ]);
    cpr_st('checkpoint_bind_fail', empty($cpBad['ok'])
        && ($cpBad['code'] ?? '') === ORANGE_CPR_ERR_CHECKPOINT);

    // Enablement true blocks
    $envTrue = $env;
    $envTrue['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED'] = true;
    $en = orange_cpr_transition_validate($envTrue, $jidF, 'cpr_deleting', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'phrase_ok' => true,
        'reauth_ok' => true,
        'runbook_completed' => true,
        'c8_overall_result' => 'SAFE',
    ]);
    cpr_st('enablement_blocks', empty($en['ok'])
        && ($en['code'] ?? '') === ORANGE_CPR_ERR_ENABLEMENT);

    // Audit event present after transition
    $auditPath = orange_cpr_audit_file_path(orange_cpr_resolve_work_root($env), $jid);
    $auditRaw = is_file($auditPath) ? (string) file_get_contents($auditPath) : '';
    cpr_st('audit_state_transition', str_contains($auditRaw, 'cpr.state_transition'));

    // WF-B path fragment
    $jobH = cpr_st_new_job($env, 'B', 8);
    $jidH = (string) $jobH['job_id'];
    orange_cpr_transition_apply($env, $jidH, 'cpr_gates_validating', ['actor' => ORANGE_CPR_ACTOR_SYSTEM]);
    $wfaOnB = orange_cpr_transition_validate($env, $jidH, 'cpr_contract_frozen', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'trigger' => 'gates_ok_wfa_freeze',
        'contract_fingerprints_ok' => true,
    ]);
    cpr_st('wfb_rejects_T03', empty($wfaOnB['ok']) && ($wfaOnB['code'] ?? '') === ORANGE_CPR_ERR_WORKFLOW);
    $t02 = orange_cpr_transition_apply($env, $jidH, 'cpr_awaiting_approvals', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'trigger' => 'gates_ok_wfb',
    ]);
    cpr_st('legal_T02_wfb', !empty($t02['ok']));
} catch (Throwable $e) {
    cpr_st('suite_exception', false, $e->getMessage());
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
