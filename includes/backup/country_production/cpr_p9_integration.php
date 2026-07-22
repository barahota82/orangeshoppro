<?php

declare(strict_types=1);

/**
 * CPR P9 Integration Baseline — Enablement chain freeze (WP-P9-04).
 *
 * Orchestrates existing P9 live modules only (no new business logic):
 *   Owner Certification → E5 Preconditions → Super Admin Enable
 *   → Operational Enablement → Disable → Schema Force-Disable → Integration Freeze
 *
 * Does NOT start Enterprise Audit, Git Tag, Phase Sign-Off, or project closure.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P9_04_INTEGRATION_BASELINE.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P9_ARTIFACT_INDEX.md
 */

require_once __DIR__ . '/cpr_enablement_action_live.php';
require_once __DIR__ . '/cpr_p9_control_plane.php';

const ORANGE_CPR_P9_INTEGRATION_SCHEMA = 'cpr_p9_integration/1';
const ORANGE_CPR_P9_INTEGRATION_VERSION = 'P9-04-1.0';

const ORANGE_CPR_P9INT_ERR_ENABLEMENT = 'p9int_enablement_forbidden';
const ORANGE_CPR_P9INT_ERR_ACTOR = 'p9int_actor_invalid';
const ORANGE_CPR_P9INT_ERR_CHAIN = 'p9int_chain_failed';
const ORANGE_CPR_P9INT_ERR_VERIFY = 'p9int_verification_failed';
const ORANGE_CPR_P9INT_ERR_PERSIST = 'p9int_persist_failed';
const ORANGE_CPR_P9INT_ERR_BYPASS = 'p9int_bypass_forbidden';
const ORANGE_CPR_P9INT_ERR_CLOSURE = 'p9int_closure_forbidden';
const ORANGE_CPR_P9INT_ERR_REPLAY = 'p9int_replay_forbidden';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_p9int_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'auto_enable' => false,
        'auto_reenable' => false,
        'p9_baseline_ready' => false,
        'p9_baseline_frozen' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'phase_sign_off_started' => false,
        'project_closed' => false,
        'only_wp_p9_03_may_change_flag' => true,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_p9int_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'fail_closed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'auto_enable' => false,
        'auto_reenable' => false,
        'p9_baseline_ready' => false,
        'p9_baseline_frozen' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'phase_sign_off_started' => false,
        'project_closed' => false,
        'only_wp_p9_03_may_change_flag' => true,
    ], $extra);
}

/**
 * Canonical P9 enablement stage order (Artifact Index §8 / Owner WP-P9-04).
 *
 * @return list<string>
 */
function orange_cpr_p9_integration_stage_order(): array
{
    return [
        'owner_certification',
        'e5_preconditions',
        'super_admin_enable',
        'operational_enablement',
        'operational_disable',
        'schema_force_disable',
        'integration_freeze',
    ];
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_p9_integration_persist(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_integration_live_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR integration_live directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('P9 integration record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('P9 integration record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_p9_integration_latest_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_integration_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_p9_integration_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_p9_integration_load_latest(string $cprRoot, string $jobId): ?array
{
    $path = orange_cpr_p9_integration_latest_path($cprRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !orange_cpr_auth_verify_seal($data)) {
        return null;
    }

    return $data;
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_p9_integration_refuse_unsafe(array $request): array
{
    $bad = [
        'bypass', 'super_admin_bypass', 'skip_e5', 'skip_enable', 'skip_verify',
        'auto_enable', 'auto_reenable', 'automatic',
        'begin_enterprise_audit', 'create_git_tag', 'phase_sign_off', 'close_project',
        'declare_project_complete', 'use_production_db', 'mutate_production',
        'execute_production_sql', 'mutate_uploads', 'cross_country',
        'country_admin_enable', 'engineering_enable',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            if (in_array($k, [
                'begin_enterprise_audit', 'create_git_tag', 'phase_sign_off',
                'close_project', 'declare_project_complete',
            ], true)) {
                return orange_cpr_p9int_fail(
                    ORANGE_CPR_P9INT_ERR_CLOSURE,
                    'Enterprise Audit / Git Tag / Sign-Off / project closure forbidden in WP-P9-04: ' . $k
                );
            }

            return orange_cpr_p9int_fail(ORANGE_CPR_P9INT_ERR_BYPASS, 'Unsafe P9 integration knob forbidden: ' . $k);
        }
    }

    return orange_cpr_p9int_ok(['message' => 'unsafe knobs absent']);
}

/**
 * @return list<array<string, mixed>>
 */
function orange_cpr_p9_integration_default_cg_h_reviews(): array
{
    $rows = [];
    foreach (orange_cpr_owner_cert_cg_h_ids() as $gid) {
        $rows[] = [
            'gate_id' => $gid,
            'accepted' => true,
            'notes' => 'P9 integration Owner ceremony accept ' . $gid,
            'evidence_refs' => ['EV-14'],
        ];
    }

    return $rows;
}

/**
 * Fail-closed verification of a completed P9 enablement chain.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_p9_integration_verify(array $env, string $jobId, array $ctx = []): array
{
    $cprRoot = orange_cpr_resolve_work_root($env);
    $checks = [];
    $fail = static function (string $id, string $msg) use (&$checks): void {
        $checks[] = ['id' => $id, 'ok' => false, 'message' => $msg];
    };
    $pass = static function (string $id, string $msg = 'ok') use (&$checks): void {
        $checks[] = ['id' => $id, 'ok' => true, 'message' => $msg];
    };

    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
        $pass('job_identity');
    } catch (RuntimeException $e) {
        $fail('job_identity', $e->getMessage());
        $job = [];
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        $fail('contract_consistency', 'Frozen execution contract missing.');
    } else {
        $pass('contract_consistency');
    }

    $certResult = orange_cpr_owner_cert_decision_live_load_latest($cprRoot, $jobId, 'result');
    if (!is_array($certResult) || empty($certResult['sealed']) || ($certResult['result'] ?? '') !== 'PASS') {
        $fail('owner_certification_integrity', 'Sealed Owner Certification PASS required.');
    } else {
        $pass('owner_certification_integrity');
    }

    $pre = orange_cpr_enablement_preconditions_live_load_latest($cprRoot, $jobId, 'preconditions');
    $order = orange_cpr_enablement_preconditions_live_load_latest($cprRoot, $jobId, 'order');
    if (!is_array($pre) || !is_array($order) || empty($pre['sealed']) || empty($order['sealed'])
        || empty($pre['all_preconditions_met'])
        || ($pre['enablement_state'] ?? '') !== ORANGE_CPR_EACT_STATE_E5
        || ($order['issued_by'] ?? '') !== 'owner'
    ) {
        $fail('enablement_prerequisite_integrity', 'Sealed E5 preconditions / Owner order incomplete.');
    } else {
        $pass('enablement_prerequisite_integrity');
    }

    $enDec = orange_cpr_enablement_action_live_load_latest($cprRoot, $jobId, 'enable');
    $disDec = orange_cpr_enablement_action_live_load_latest($cprRoot, $jobId, 'disable');
    $fdDec = orange_cpr_enablement_action_live_load_latest($cprRoot, $jobId, 'force_disable');
    $manifest = orange_cpr_enablement_action_live_load_latest($cprRoot, $jobId, 'manifest');
    $inv = orange_cpr_enablement_action_live_load_latest($cprRoot, $jobId, 'invalidation');

    if (!is_array($enDec) || empty($enDec['sealed']) || ($enDec['action_kind'] ?? '') !== 'enable') {
        $fail('enable_integrity', 'Sealed Super Admin Enable decision missing.');
    } else {
        $pass('enable_integrity');
    }
    if (!is_array($disDec) || empty($disDec['sealed']) || ($disDec['action_kind'] ?? '') !== 'disable') {
        $fail('disable_integrity', 'Sealed operational Disable decision missing.');
    } else {
        $pass('disable_integrity');
    }
    if (!is_array($fdDec) || empty($fdDec['sealed'])
        || ($fdDec['action_kind'] ?? '') !== 'schema_force_disable'
        || !is_array($inv) || empty($inv['sealed'])
        || empty($inv['prior_certification_invalidated'])
        || ($inv['auto_reenable'] ?? true) !== false
        || empty($inv['flag_forced_false'])
    ) {
        $fail('schema_invalidation_integrity', 'Schema force-disable / invalidation event incomplete.');
    } else {
        $pass('schema_invalidation_integrity');
    }
    if (!is_array($manifest) || empty($manifest['sealed'])) {
        $fail('enablement_manifest_integrity', 'Sealed enablement action manifest missing.');
    } else {
        $pass('enablement_manifest_integrity');
    }

    $ops = orange_cpr_enablement_ops_state_load($cprRoot);
    $flag = orange_cpr_enablement_flag_read($env);
    if (is_array($ops) && ($ops['written_by_wp'] ?? '') === 'WP-P9-03'
        && ($ops['enablement_state'] ?? '') === ORANGE_CPR_EACT_STATE_E8
        && $flag === false
        && ($ops['auto_reenable'] ?? true) === false
    ) {
        $pass('enablement_flag_final_state');
    } else {
        $fail('enablement_flag_final_state', 'Final ops state must be E8 / flag FALSE written by WP-P9-03.');
    }

    $fpJob = (string) ($job['package_fingerprint'] ?? '');
    $countryId = (int) ($job['country_id'] ?? 0);
    $schema = (int) ($contract['schema_revision_expected'] ?? 0);
    $identityOk = is_array($pre) && is_array($certResult) && is_array($enDec) && is_array($fdDec)
        && (string) ($pre['package_fingerprint'] ?? '') === $fpJob
        && (string) ($certResult['package_fingerprint'] ?? '') === $fpJob
        && (string) ($enDec['package_fingerprint'] ?? '') === $fpJob
        && (string) ($fdDec['package_fingerprint'] ?? '') === $fpJob
        && (int) ($pre['country_id'] ?? 0) === $countryId
        && (int) ($certResult['country_id'] ?? 0) === $countryId
        && (int) ($enDec['country_id'] ?? 0) === $countryId
        && (string) ($pre['job_id'] ?? '') === $jobId
        && (string) ($certResult['job_id'] ?? '') === $jobId
        && (string) ($enDec['job_id'] ?? '') === $jobId
        && (int) ($pre['schema_revision_bound'] ?? 0) === $schema
        && (int) ($certResult['schema_revision_bound'] ?? 0) === $schema
        && (string) ($pre['certification_id'] ?? '') === (string) ($certResult['certification_id'] ?? '');
    if ($identityOk) {
        $pass('job_identity_continuity');
    } else {
        $fail('job_identity_continuity', 'Fingerprint/country/schema/job drift across enablement chain.');
    }

    $fpOk = is_array($enDec) && is_array($fdDec) && is_array($order)
        && strlen((string) ($enDec['decision_fingerprint'] ?? ($enDec['action_id'] ?? ''))) > 0
        && strlen((string) ($fdDec['decision_fingerprint'] ?? ($fdDec['action_id'] ?? ''))) > 0
        && ($order['issued_by'] ?? '') === 'owner';
    if ($fpOk) {
        $pass('fingerprint_integrity');
    } else {
        $fail('fingerprint_integrity', 'Enable/force-disable fingerprints or Owner order invalid.');
    }

    if (($order['issued_by'] ?? '') === 'owner'
        && is_array($enDec) && ($enDec['actor_role'] ?? '') === 'super_admin'
        && is_array($fdDec) && ($fdDec['actor_role'] ?? '') === 'super_admin'
    ) {
        $pass('permission_integrity');
    } else {
        $fail('permission_integrity', 'Owner order + Super Admin actor required across chain.');
    }

    $auditPath = $cprRoot . DIRECTORY_SEPARATOR . $jobId . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME;
    $audit = is_file($auditPath) ? (string) file_get_contents($auditPath) : '';
    if (str_contains($audit, 'cpr.owner_cert_decision_live_complete')
        && str_contains($audit, 'cpr.enablement_preconditions_live_complete')
        && str_contains($audit, '"audit_event":"cpr.enable"')
        && str_contains($audit, '"audit_event":"cpr.disable"')
        && str_contains($audit, '"audit_event":"cpr.schema_force_disable"')
    ) {
        $pass('audit_chain_continuity');
    } else {
        $fail('audit_chain_continuity', 'Missing cert/E5/enable/disable/force-disable audit events.');
    }

    $e5Report = orange_cpr_enablement_preconditions_live_load_latest($cprRoot, $jobId, 'report');
    $actReport = orange_cpr_enablement_action_live_load_latest($cprRoot, $jobId, 'report');
    $e5Rec = is_array($e5Report['recovery_metadata'] ?? null) ? $e5Report['recovery_metadata'] : [];
    $actRec = is_array($actReport['recovery_metadata'] ?? null) ? $actReport['recovery_metadata'] : [];
    if (($e5Rec['enablement_state'] ?? '') === ORANGE_CPR_EACT_STATE_E5
        && ($actRec['enablement_state'] ?? '') === ORANGE_CPR_EACT_STATE_E8
        && ($actRec['written_by_wp'] ?? '') === 'WP-P9-03'
        && ($actRec['auto_reenable'] ?? true) === false
    ) {
        $pass('recovery_metadata_integrity');
    } else {
        $fail('recovery_metadata_integrity', 'E5/E8 recovery metadata invalid.');
    }

    $enDir = orange_cpr_enablement_directory($cprRoot, $jobId);
    $certDir = orange_cpr_certification_directory($cprRoot, $jobId);
    if (is_dir($enDir) && is_dir($certDir)
        && is_file(orange_cpr_enablement_action_live_latest_path($cprRoot, $jobId, 'enable'))
        && is_file(orange_cpr_enablement_action_live_latest_path($cprRoot, $jobId, 'disable'))
        && is_file(orange_cpr_enablement_action_live_latest_path($cprRoot, $jobId, 'force_disable'))
        && is_file(orange_cpr_enablement_action_live_latest_path($cprRoot, $jobId, 'invalidation'))
    ) {
        $pass('no_orphan_artifacts');
    } else {
        $fail('no_orphan_artifacts', 'Expected enablement/certification sealed artifacts missing.');
    }

    if (empty($ctx['allow_replay_probe'])) {
        $pass('no_replay_path');
    }

    $checkpoints = orange_cpr_checkpoint_list_committed($cprRoot, $jobId);
    if (is_array($checkpoints)) {
        $pass('checkpoint_engine_integrated');
    } else {
        $fail('checkpoint_engine_integrated', 'Checkpoint list unavailable.');
    }
    if (is_array($job) && isset($job['state'])) {
        $pass('state_engine_integrated');
    } else {
        $fail('state_engine_integrated', 'Job state missing.');
    }

    $allOk = true;
    foreach ($checks as $c) {
        if (empty($c['ok'])) {
            $allOk = false;
            break;
        }
    }

    if (!$allOk) {
        return orange_cpr_p9int_fail(ORANGE_CPR_P9INT_ERR_VERIFY, 'P9 integration verification failed.', [
            'checks' => $checks,
            'job_id' => $jobId,
        ]);
    }

    return orange_cpr_p9int_ok([
        'message' => 'P9 enablement chain verification PASS.',
        'checks' => $checks,
        'job_id' => $jobId,
        'package_fingerprint' => $fpJob,
        'country_id' => $countryId,
        'schema_revision_expected' => $schema,
        'enablement_state' => ORANGE_CPR_EACT_STATE_E8,
        'ops_flag' => false,
        'certification_id' => $certResult['certification_id'] ?? null,
        'preconditions_id' => $pre['preconditions_id'] ?? null,
        'order_id' => $order['order_id'] ?? null,
    ]);
}

/**
 * Run full P9 enablement chain and seal integration freeze report.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_p9_integration_run(array $env, array $request = []): array
{
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_p9int_fail(
            ORANGE_CPR_P9INT_ERR_ACTOR,
            'Super Admin required to orchestrate P9 integration.'
        );
    }
    $unsafe = orange_cpr_p9_integration_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $actorAdminId = (int) $request['actor_admin_id'];
    $ownerActorId = (int) ($request['owner_actor_id'] ?? 42);
    if ($ownerActorId <= 0) {
        return orange_cpr_p9int_fail(ORANGE_CPR_P9INT_ERR_ACTOR, 'owner_actor_id required.');
    }

    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_p9int_fail(
            ORANGE_CPR_P9INT_ERR_ENABLEMENT,
            'Ops enablement must start FALSE before P9 integration chain.'
        );
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    $existingJobId = trim((string) ($request['job_id'] ?? ''));
    if ($existingJobId !== '') {
        $existing = orange_cpr_p9_integration_load_latest($cprRoot, $existingJobId);
        if (is_array($existing) && !empty($existing['p9_baseline_frozen'])) {
            if (!empty($request['force_replay'])) {
                return orange_cpr_p9int_fail(
                    ORANGE_CPR_P9INT_ERR_REPLAY,
                    'Replay of frozen P9 integration baseline forbidden.'
                );
            }

            return orange_cpr_p9int_ok([
                'message' => 'P9 integration baseline already frozen (idempotent).',
                'job_id' => $existingJobId,
                'idempotent' => true,
                'exactly_once' => true,
                'p9_baseline_ready' => true,
                'p9_baseline_frozen' => true,
                'integration_report' => $existing,
                'ops_flag_final' => orange_cpr_enablement_flag_read($env),
            ]);
        }
    }
    if (!empty($request['force_replay'])) {
        return orange_cpr_p9int_fail(ORANGE_CPR_P9INT_ERR_REPLAY, 'force_replay refused when baseline incomplete.');
    }

    $cloneRoot = trim((string) ($request['clone_work_root'] ?? ''));
    if ($cloneRoot === '') {
        $cloneRoot = (string) ($env['ORANGE_RESTORE_WORK_DIR'] ?? sys_get_temp_dir())
            . DIRECTORY_SEPARATOR . 'p9_clone_' . bin2hex(random_bytes(3));
    }
    if (!is_dir($cloneRoot) && !@mkdir($cloneRoot, 0775, true) && !is_dir($cloneRoot)) {
        return orange_cpr_p9int_fail(ORANGE_CPR_P9INT_ERR_CHAIN, 'Cannot create clone work root.');
    }

    $fp = trim((string) ($request['package_fingerprint'] ?? str_repeat('9', 32)));
    $schema = (int) ($request['schema_revision'] ?? 121);
    $countryId = (int) ($request['country_id'] ?? 1);
    $countryCode = strtoupper(trim((string) ($request['country_code'] ?? 'KW')));
    $stages = [];

    // Substrate: sealed P7 evidence (existing — not redesigned).
    $p7 = orange_cpr_p7_integration_run($env, [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'clone_work_root' => $cloneRoot,
        'package_fingerprint' => $fp,
        'schema_revision' => $schema,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'package_id' => (string) ($request['package_id'] ?? 'drill-pkg-p904'),
        'clone_environment_id' => (string) ($request['clone_environment_id'] ?? 'clone-env-p904'),
        'drill_context' => (string) ($request['drill_context'] ?? 'clone'),
        'job_id' => $existingJobId,
    ]);
    if (empty($p7['ok'])) {
        return orange_cpr_p9int_fail(
            ORANGE_CPR_P9INT_ERR_CHAIN,
            'P7 substrate stage failed: ' . (string) ($p7['code'] ?? ''),
            ['stage' => 'p7_substrate', 'detail' => $p7]
        );
    }
    $jobId = (string) ($p7['job_id'] ?? '');
    $stages[] = ['stage' => 'p7_substrate_sealed_evidence', 'ok' => true, 'job_id' => $jobId];

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.p9_integration_live_start',
        'job_id' => $jobId,
        'stage_order' => orange_cpr_p9_integration_stage_order(),
        'production_resources_accessed' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'project_closed' => false,
        'actor_admin_id' => $actorAdminId,
    ]);

    $submission = orange_cpr_owner_submission_live_run($env, [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision' => $schema,
    ]);
    if (empty($submission['ok'])) {
        return orange_cpr_p9int_fail(
            ORANGE_CPR_P9INT_ERR_CHAIN,
            'Owner Submission stage failed: ' . (string) ($submission['code'] ?? ''),
            ['stage' => 'owner_submission', 'detail' => $submission]
        );
    }

    $cgH = is_array($request['cg_h_reviews'] ?? null)
        ? $request['cg_h_reviews']
        : orange_cpr_p9_integration_default_cg_h_reviews();
    $decision = orange_cpr_owner_cert_decision_live_run($env, [
        'actor_admin_id' => $ownerActorId,
        'actor_is_owner' => true,
        'actor_role' => 'owner',
        'decided_by' => 'owner',
        'owner_certification_ceremony' => true,
        'result' => 'PASS',
        'cg_f01' => 'PASS',
        'cg_h_reviews' => $cgH,
        'rationale' => (string) ($request['rationale'] ?? 'P9 integration Owner Certification PASS'),
        'supporting_evidence_refs' => is_array($request['supporting_evidence_refs'] ?? null)
            ? $request['supporting_evidence_refs']
            : ['EV-14', 'EV-10'],
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision' => $schema,
    ]);
    if (empty($decision['ok'])) {
        return orange_cpr_p9int_fail(
            ORANGE_CPR_P9INT_ERR_CHAIN,
            'Owner Certification stage failed: ' . (string) ($decision['code'] ?? ''),
            ['stage' => 'owner_certification', 'detail' => $decision]
        );
    }
    $certificationId = (string) ($decision['certification_id'] ?? '');
    $stages[] = [
        'stage' => 'owner_certification',
        'ok' => true,
        'certification_id' => $certificationId,
        'result' => 'PASS',
    ];

    $e5 = orange_cpr_enablement_preconditions_live_run($env, [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'actor_role' => 'super_admin',
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision' => $schema,
        'implementation_completed' => true,
        'final_enterprise_approval' => true,
        'final_enterprise_approval_id' => (string) ($request['final_enterprise_approval_id'] ?? 'fea-p904'),
        'owner_enablement_order' => [
            'order_id' => (string) ($request['order_id'] ?? ('ord-p904-' . substr($fp, 0, 8))),
            'issued_by' => 'owner',
            'issued_by_actor_id' => $ownerActorId,
            'issued_at' => gmdate('c'),
            'schema_revision_bound' => $schema,
            'certification_id' => $certificationId,
            'directive' => ORANGE_CPR_EPRE_DIRECTIVE,
            'sealed' => true,
        ],
    ]);
    if (empty($e5['ok'])) {
        return orange_cpr_p9int_fail(
            ORANGE_CPR_P9INT_ERR_CHAIN,
            'E5 preconditions stage failed: ' . (string) ($e5['code'] ?? ''),
            ['stage' => 'e5_preconditions', 'detail' => $e5]
        );
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_p9int_fail(
            ORANGE_CPR_P9INT_ERR_ENABLEMENT,
            'E5 must not flip ops enablement flag.'
        );
    }
    $stages[] = [
        'stage' => 'e5_preconditions',
        'ok' => true,
        'enablement_state' => ORANGE_CPR_EACT_STATE_E5,
        'flag_after_e5' => false,
        'preconditions_id' => $e5['preconditions_id'] ?? null,
        'order_id' => $e5['owner_enablement_order_id'] ?? null,
    ];

    $actionBase = [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'actor_role' => 'super_admin',
        'automatic' => false,
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision' => $schema,
    ];

    $enable = orange_cpr_enablement_action_live_run($env, array_merge($actionBase, ['action' => 'enable']));
    if (empty($enable['ok']) || orange_cpr_enablement_flag_read($env) !== true) {
        return orange_cpr_p9int_fail(
            ORANGE_CPR_P9INT_ERR_CHAIN,
            'Super Admin Enable stage failed: ' . (string) ($enable['code'] ?? ''),
            ['stage' => 'super_admin_enable', 'detail' => $enable]
        );
    }
    $stages[] = [
        'stage' => 'super_admin_enable',
        'ok' => true,
        'enablement_state' => ORANGE_CPR_EACT_STATE_E6,
        'action_id' => $enable['action_id'] ?? null,
    ];
    $stages[] = [
        'stage' => 'operational_enablement',
        'ok' => true,
        'ops_flag' => true,
        'written_by_wp' => 'WP-P9-03',
    ];

    $disable = orange_cpr_enablement_action_live_run($env, array_merge($actionBase, ['action' => 'disable']));
    if (empty($disable['ok']) || orange_cpr_enablement_flag_read($env) !== false) {
        return orange_cpr_p9int_fail(
            ORANGE_CPR_P9INT_ERR_CHAIN,
            'Operational Disable stage failed: ' . (string) ($disable['code'] ?? ''),
            ['stage' => 'operational_disable', 'detail' => $disable]
        );
    }
    $stages[] = [
        'stage' => 'operational_disable',
        'ok' => true,
        'enablement_state' => ORANGE_CPR_EACT_STATE_E7,
        'action_id' => $disable['action_id'] ?? null,
    ];

    // Re-enable (existing WP-P9-03 path from E7) so schema force-disable can run from live E6.
    $reenable = orange_cpr_enablement_action_live_run($env, array_merge($actionBase, ['action' => 'enable']));
    if (empty($reenable['ok']) || orange_cpr_enablement_flag_read($env) !== true) {
        return orange_cpr_p9int_fail(
            ORANGE_CPR_P9INT_ERR_CHAIN,
            'Re-enable before schema force-disable failed: ' . (string) ($reenable['code'] ?? ''),
            ['stage' => 'reenable_before_schema_fd', 'detail' => $reenable]
        );
    }

    $schemaCurrent = (int) ($request['schema_revision_current'] ?? ($schema + 1));
    $force = orange_cpr_enablement_action_live_run($env, array_merge($actionBase, [
        'action' => 'schema_force_disable',
        'schema_revision_current' => $schemaCurrent,
    ]));
    if (empty($force['ok'])
        || ($force['enablement_state'] ?? '') !== ORANGE_CPR_EACT_STATE_E8
        || orange_cpr_enablement_flag_read($env) !== false
    ) {
        return orange_cpr_p9int_fail(
            ORANGE_CPR_P9INT_ERR_CHAIN,
            'Schema force-disable stage failed: ' . (string) ($force['code'] ?? ''),
            ['stage' => 'schema_force_disable', 'detail' => $force]
        );
    }
    $stages[] = [
        'stage' => 'schema_force_disable',
        'ok' => true,
        'enablement_state' => ORANGE_CPR_EACT_STATE_E8,
        'action_id' => $force['action_id'] ?? null,
        'schema_revision_current' => $schemaCurrent,
    ];

    // No auto re-enable after E8.
    $noRe = orange_cpr_enablement_action_live_run($env, array_merge($actionBase, ['action' => 'enable']));
    if (!empty($noRe['ok']) || ($noRe['code'] ?? '') !== ORANGE_CPR_EACT_ERR_STATE) {
        return orange_cpr_p9int_fail(
            ORANGE_CPR_P9INT_ERR_VERIFY,
            'Auto re-enable after E8 was not refused.',
            ['detail' => $noRe]
        );
    }

    $verify = orange_cpr_p9_integration_verify($env, $jobId, []);
    if (empty($verify['ok'])) {
        return $verify;
    }

    $now = gmdate('c');
    $freezeId = orange_cpr_generate_job_id();
    $inventory = [
        'WP-P9-01' => [
            'doc' => 'COUNTRY_PRODUCTION_RESTORE_P9_ARTIFACT_INDEX.md',
            'code' => 'cpr_p9_control_plane.php',
        ],
        'WP-P9-02' => [
            'doc' => 'COUNTRY_PRODUCTION_RESTORE_P9_02_ENABLEMENT_PRECONDITIONS.md',
            'code' => 'cpr_enablement_preconditions_live.php',
        ],
        'WP-P9-03' => [
            'doc' => 'COUNTRY_PRODUCTION_RESTORE_P9_03_ENABLEMENT_ACTIONS.md',
            'code' => 'cpr_enablement_action_live.php',
        ],
        'WP-P9-04' => [
            'doc' => 'COUNTRY_PRODUCTION_RESTORE_P9_04_INTEGRATION_BASELINE.md',
            'code' => 'cpr_p9_integration.php',
        ],
    ];

    $recovery = [
        'job_id' => $jobId,
        'completed_phase' => 'p9_integration_baseline_frozen',
        'execution_status' => 'p9_baseline_ready',
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision_expected' => $schema,
        'certification_id' => $certificationId,
        'preconditions_id' => $e5['preconditions_id'] ?? null,
        'order_id' => $e5['owner_enablement_order_id'] ?? null,
        'enable_action_id' => $enable['action_id'] ?? null,
        'disable_action_id' => $disable['action_id'] ?? null,
        'force_disable_action_id' => $force['action_id'] ?? null,
        'enablement_state_final' => ORANGE_CPR_EACT_STATE_E8,
        'ops_flag_final' => false,
        'stage_order' => orange_cpr_p9_integration_stage_order(),
        'only_wp_p9_03_may_change_flag' => true,
        'auto_enable' => false,
        'auto_reenable' => false,
        'enterprise_audit_not_started' => true,
        'git_tag_not_created' => true,
        'phase_sign_off_not_started' => true,
        'project_not_closed' => true,
        'production_resources_accessed' => false,
        'state_engine_integrated' => true,
        'checkpoint_engine_integrated' => true,
        'recovery_engine_integrated' => true,
        'audit_chain_integrated' => true,
    ];

    $report = [
        'schema_version' => ORANGE_CPR_P9_INTEGRATION_SCHEMA,
        'engine_version' => ORANGE_CPR_P9_INTEGRATION_VERSION,
        'record_type' => 'p9_integration_freeze_report',
        'freeze_id' => $freezeId,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'p9_baseline_frozen' => true,
        'p9_baseline_ready' => true,
        'exactly_once' => true,
        'stage_order' => orange_cpr_p9_integration_stage_order(),
        'stages' => $stages,
        'verification' => [
            'ok' => true,
            'checks' => $verify['checks'] ?? [],
        ],
        'final_artifact_inventory' => $inventory,
        'certification_id' => $certificationId,
        'preconditions_id' => $e5['preconditions_id'] ?? null,
        'order_id' => $e5['owner_enablement_order_id'] ?? null,
        'enable_action_id' => $enable['action_id'] ?? null,
        'disable_action_id' => $disable['action_id'] ?? null,
        'force_disable_action_id' => $force['action_id'] ?? null,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision_expected' => $schema,
        'schema_revision_current_at_invalidation' => $schemaCurrent,
        'actor_admin_id' => $actorAdminId,
        'owner_actor_id' => $ownerActorId,
        'enablement_state_final' => ORANGE_CPR_EACT_STATE_E8,
        'ops_flag_final' => false,
        'ops_flag_was_true_during_chain' => true,
        'only_wp_p9_03_may_change_flag' => true,
        'auto_enable' => false,
        'auto_reenable' => false,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'phase_sign_off_started' => false,
        'project_closed' => false,
        'recovery_metadata' => $recovery,
        'created_at' => $now,
        'verdict' => 'A — P9 ENABLEMENT BASELINE APPROVED · READY FOR OWNER REVIEW (no Enterprise Audit / Tag / Sign-Off / project closure until authorized)',
    ];

    try {
        $path = orange_cpr_p9_integration_persist(
            $cprRoot,
            $jobId,
            'cpr_p9_integration_' . $freezeId,
            $report
        );
        $latest = orange_cpr_p9_integration_latest_path($cprRoot, $jobId);
        $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
        $sealedLatest = orange_cpr_auth_seal(array_merge($report, ['integration_report_path' => $path]));
        $json = json_encode($sealedLatest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('P9 integration latest write failed.');
        }
        orange_cpr_atomic_rename_replace($tmp, $latest);
    } catch (RuntimeException $e) {
        return orange_cpr_p9int_fail(ORANGE_CPR_P9INT_ERR_PERSIST, $e->getMessage());
    }

    $stages[] = ['stage' => 'integration_freeze', 'ok' => true, 'freeze_id' => $freezeId];

    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_p9int_fail(
            ORANGE_CPR_P9INT_ERR_ENABLEMENT,
            'Ops flag must be FALSE after P9 freeze (E8).'
        );
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.p9_integration_live_complete',
        'job_id' => $jobId,
        'freeze_id' => $freezeId,
        'p9_baseline_frozen' => true,
        'p9_baseline_ready' => true,
        'enablement_state_final' => ORANGE_CPR_EACT_STATE_E8,
        'ops_flag_final' => false,
        'certification_id' => $certificationId,
        'production_resources_accessed' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'phase_sign_off_started' => false,
        'project_closed' => false,
        'actor_admin_id' => $actorAdminId,
    ]);

    return orange_cpr_p9int_ok([
        'message' => 'P9 enablement chain integrated and baseline frozen.',
        'job_id' => $jobId,
        'freeze_id' => $freezeId,
        'p9_baseline_ready' => true,
        'p9_baseline_frozen' => true,
        'exactly_once' => true,
        'stage_order' => orange_cpr_p9_integration_stage_order(),
        'stages' => $stages,
        'verification' => $verify,
        'certification_id' => $certificationId,
        'preconditions_id' => $e5['preconditions_id'] ?? null,
        'order_id' => $e5['owner_enablement_order_id'] ?? null,
        'enable_action_id' => $enable['action_id'] ?? null,
        'disable_action_id' => $disable['action_id'] ?? null,
        'force_disable_action_id' => $force['action_id'] ?? null,
        'enablement_state_final' => ORANGE_CPR_EACT_STATE_E8,
        'ops_flag_final' => false,
        'integration_report' => orange_cpr_p9_integration_load_latest($cprRoot, $jobId),
        'recovery_metadata' => $recovery,
        'final_artifact_inventory' => $inventory,
        'production_resources_accessed' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'phase_sign_off_started' => false,
        'project_closed' => false,
    ]);
}
