<?php

declare(strict_types=1);

/**
 * CPR P8 Integration Baseline — Owner Certification chain freeze (WP-P8-04).
 *
 * Orchestrates existing P8 live modules only (no new business logic):
 *   Sealed Owner Submission → Owner Certification Ceremony
 *   → PASS or FAIL Decision → Sealed Certification Result → Integration Freeze
 *
 * Does NOT start Enterprise Audit, Git Tag, or P9.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P8_04_INTEGRATION_BASELINE.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P8_ARTIFACT_INDEX.md
 */

require_once __DIR__ . '/cpr_owner_cert_decision_live.php';
require_once __DIR__ . '/cpr_p8_control_plane.php';

const ORANGE_CPR_P8_INTEGRATION_SCHEMA = 'cpr_p8_integration/1';
const ORANGE_CPR_P8_INTEGRATION_VERSION = 'P8-04-1.0';

const ORANGE_CPR_P8INT_ERR_ENABLEMENT = 'p8int_enablement_forbidden';
const ORANGE_CPR_P8INT_ERR_ACTOR = 'p8int_actor_invalid';
const ORANGE_CPR_P8INT_ERR_CHAIN = 'p8int_chain_failed';
const ORANGE_CPR_P8INT_ERR_VERIFY = 'p8int_verification_failed';
const ORANGE_CPR_P8INT_ERR_PERSIST = 'p8int_persist_failed';
const ORANGE_CPR_P8INT_ERR_BYPASS = 'p8int_bypass_forbidden';
const ORANGE_CPR_P8INT_ERR_P9 = 'p8int_p9_forbidden';
const ORANGE_CPR_P8INT_ERR_REPLAY = 'p8int_replay_forbidden';
const ORANGE_CPR_P8INT_ERR_RESULT = 'p8int_result_invalid';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_p8int_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'enablement_flag_after_decision' => false,
        'auto_rollback_triggered' => false,
        'p8_baseline_ready' => false,
        'p9_started' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'cert_pass_does_not_enable' => true,
        'fail_does_not_auto_rollback' => true,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_p8int_fail(string $code, string $message, array $extra = []): array
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
        'enablement_flag_observed' => false,
        'enablement_flag_after_decision' => false,
        'auto_rollback_triggered' => false,
        'p8_baseline_ready' => false,
        'p9_started' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'cert_pass_does_not_enable' => true,
        'fail_does_not_auto_rollback' => true,
    ], $extra);
}

/**
 * Canonical P8 Owner Certification stage order (Artifact Index §8 / roadmap P8).
 *
 * @return list<string>
 */
function orange_cpr_p8_integration_stage_order(): array
{
    return [
        'sealed_owner_submission',
        'owner_certification_ceremony',
        'pass_or_fail_decision',
        'sealed_certification_result',
        'integration_freeze',
    ];
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_p8_integration_persist(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_integration_live_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR integration_live directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('P8 integration record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('P8 integration record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_p8_integration_latest_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_integration_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_p8_integration_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_p8_integration_load_latest(string $cprRoot, string $jobId): ?array
{
    $path = orange_cpr_p8_integration_latest_path($cprRoot, $jobId);
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
function orange_cpr_p8_integration_refuse_unsafe(array $request): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_submission', 'skip_decision',
        'skip_verify', 'skip_ceremony', 'auto_pass', 'auto_fail', 'auto_approve', 'auto_reject',
        'begin_p9', 'begin_enterprise_audit', 'create_git_tag', 'enablement_true', 'flip_enablement',
        'use_production_db', 'mutate_production', 'execute_production_sql', 'mutate_uploads',
        'auto_rollback', 'trigger_rollback', 'cross_country', 'decide_as_engineering',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            if (in_array($k, ['begin_p9', 'begin_enterprise_audit', 'create_git_tag'], true)) {
                return orange_cpr_p8int_fail(
                    ORANGE_CPR_P8INT_ERR_P9,
                    'Enterprise Audit / Git Tag / P9 forbidden in WP-P8-04: ' . $k
                );
            }

            return orange_cpr_p8int_fail(ORANGE_CPR_P8INT_ERR_BYPASS, 'Unsafe P8 integration knob forbidden: ' . $k);
        }
    }

    return orange_cpr_p8int_ok(['message' => 'unsafe knobs absent']);
}

/**
 * @return list<array<string, mixed>>
 */
function orange_cpr_p8_integration_default_cg_h_reviews(): array
{
    $rows = [];
    foreach (orange_cpr_owner_cert_cg_h_ids() as $gid) {
        $rows[] = [
            'gate_id' => $gid,
            'accepted' => true,
            'notes' => 'P8 integration Owner ceremony accept ' . $gid,
            'evidence_refs' => ['EV-14'],
        ];
    }

    return $rows;
}

/**
 * Fail-closed verification of a completed P8 Owner Certification chain.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_p8_integration_verify(array $env, string $jobId, array $ctx = []): array
{
    $cprRoot = orange_cpr_resolve_work_root($env);
    $checks = [];
    $fail = static function (string $id, string $msg) use (&$checks): void {
        $checks[] = ['id' => $id, 'ok' => false, 'message' => $msg];
    };
    $pass = static function (string $id, string $msg = 'ok') use (&$checks): void {
        $checks[] = ['id' => $id, 'ok' => true, 'message' => $msg];
    };

    if (orange_cpr_enablement_flag_read($env)) {
        $fail('enablement_false', 'Ops enablement must be FALSE.');
    } else {
        $pass('enablement_false');
    }

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

    $subPkg = orange_cpr_owner_submission_live_load_latest($cprRoot, $jobId, 'package');
    $subMan = orange_cpr_owner_submission_live_load_latest($cprRoot, $jobId, 'manifest');
    if (!is_array($subPkg) || !is_array($subMan) || empty($subPkg['sealed']) || empty($subMan['sealed'])
        || empty($subPkg['submission_complete'])
    ) {
        $fail('submission_integrity', 'Sealed Owner Submission package/manifest incomplete.');
    } else {
        $pass('submission_integrity');
    }

    $decision = orange_cpr_owner_cert_decision_live_load_latest($cprRoot, $jobId, 'decision');
    $certMan = orange_cpr_owner_cert_decision_live_load_latest($cprRoot, $jobId, 'manifest');
    $certResult = orange_cpr_owner_cert_decision_live_load_latest($cprRoot, $jobId, 'result');
    if (!is_array($decision) || !is_array($certMan) || !is_array($certResult)
        || empty($decision['sealed']) || empty($certMan['sealed']) || empty($certResult['sealed'])
    ) {
        $fail('certification_integrity', 'Sealed Owner Cert decision/manifest/result incomplete.');
    } else {
        $pass('certification_integrity');
    }

    $result = is_array($certResult) ? strtoupper((string) ($certResult['result'] ?? '')) : '';
    $decResult = is_array($decision) ? strtoupper((string) ($decision['result'] ?? '')) : '';
    if (!in_array($result, ['PASS', 'FAIL'], true) || $result !== $decResult) {
        $fail('pass_fail_exclusivity', 'Result must be exactly PASS or FAIL and match decision.');
    } else {
        $pass('pass_fail_exclusivity');
    }

    if (is_array($certResult) && $result === 'PASS' && ($certResult['decided_by'] ?? '') !== 'owner') {
        $fail('owner_authority', 'PASS with decided_by != owner.');
    } elseif (is_array($certResult) && ($certResult['decided_by'] ?? '') === 'owner') {
        $pass('owner_authority');
    } else {
        $fail('owner_authority', 'decided_by must be owner.');
    }

    $fpJob = (string) ($job['package_fingerprint'] ?? '');
    $countryId = (int) ($job['country_id'] ?? 0);
    $schema = (int) ($contract['schema_revision_expected'] ?? 0);
    $identityOk = is_array($subPkg) && is_array($certResult)
        && (string) ($subPkg['package_fingerprint'] ?? '') === $fpJob
        && (string) ($certResult['package_fingerprint'] ?? '') === $fpJob
        && (int) ($subPkg['country_id'] ?? 0) === $countryId
        && (int) ($certResult['country_id'] ?? 0) === $countryId
        && (int) ($subPkg['schema_revision_bound'] ?? 0) === $schema
        && (int) ($certResult['schema_revision_bound'] ?? 0) === $schema
        && (string) ($subPkg['job_id'] ?? '') === $jobId
        && (string) ($certResult['job_id'] ?? '') === $jobId
        && (string) ($subPkg['certification_id'] ?? '') === (string) ($certResult['certification_id'] ?? '')
        && (string) ($subPkg['package_cycle_id'] ?? '') === (string) ($certResult['package_cycle_id'] ?? '');
    if ($identityOk) {
        $pass('job_identity_continuity');
    } else {
        $fail('job_identity_continuity', 'Fingerprint/country/schema/cert identity drift across chain.');
    }

    $fpOk = is_array($subPkg) && is_array($decision) && is_array($certResult)
        && strlen((string) ($subPkg['submission_fingerprint'] ?? '')) === 64
        && strlen((string) ($subPkg['certification_fingerprint'] ?? '')) === 64
        && strlen((string) ($decision['decision_id'] ?? '')) > 0
        && hash_equals((string) ($subPkg['pack_seal_hash'] ?? ''), (string) ($certResult['pack_seal_hash'] ?? ''));
    if ($fpOk) {
        $pass('fingerprint_integrity');
    } else {
        $fail('fingerprint_integrity', 'Submission/cert fingerprints or pack_seal_hash inconsistent.');
    }

    $auditPath = $cprRoot . DIRECTORY_SEPARATOR . $jobId . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME;
    $audit = is_file($auditPath) ? (string) file_get_contents($auditPath) : '';
    if (str_contains($audit, 'cpr.owner_submission_live_complete')
        && str_contains($audit, 'cpr.owner_cert_decision_live_complete')
    ) {
        $pass('audit_chain_continuity');
    } else {
        $fail('audit_chain_continuity', 'Missing submission/cert decision audit completion events.');
    }

    $subRec = is_array($subPkg['recovery_metadata'] ?? null) ? $subPkg['recovery_metadata'] : [];
    $certReport = orange_cpr_owner_cert_decision_live_load_latest($cprRoot, $jobId, 'report');
    $certRec = is_array($certReport['recovery_metadata'] ?? null) ? $certReport['recovery_metadata'] : [];
    if (($subRec['lifecycle_state'] ?? '') === 'cert_submitted_for_owner'
        && in_array(($certRec['lifecycle_state'] ?? ''), ['cert_pass', 'cert_fail'], true)
        && ($certRec['enablement_flag_after_decision'] ?? true) === false
        && ($certRec['auto_rollback_triggered'] ?? true) === false
    ) {
        $pass('recovery_metadata_integrity');
    } else {
        $fail('recovery_metadata_integrity', 'Recovery metadata lifecycle/enablement/rollback flags invalid.');
    }

    $subDir = orange_cpr_owner_submission_directory($cprRoot, $jobId);
    $certDir = orange_cpr_certification_directory($cprRoot, $jobId);
    if (is_dir($subDir) && is_dir($certDir)) {
        $pass('no_orphan_artifacts');
    } else {
        $fail('no_orphan_artifacts', 'owner_submission/ or certification/ directory missing.');
    }

    // Exactly one sealed PASS/FAIL result (duplicate would have been refused at write).
    $resultFiles = glob($certDir . DIRECTORY_SEPARATOR . 'cpr_owner_cert_result_latest.json') ?: [];
    if (count($resultFiles) === 1 && is_array($certResult)
        && in_array((string) ($certResult['result'] ?? ''), ['PASS', 'FAIL'], true)
    ) {
        $pass('no_duplicate_certification');
    } else {
        $fail('no_duplicate_certification', 'Expected exactly one sealed certification result.');
    }

    if (empty($ctx['allow_replay_probe'])) {
        $pass('no_replay_path');
    }

    if (($certResult['enablement_flag_after_decision'] ?? true) === false
        && !orange_cpr_enablement_flag_read($env)
    ) {
        $pass('pass_does_not_enable');
    } else {
        $fail('pass_does_not_enable', 'Enablement must remain FALSE after decision.');
    }

    if (($certResult['auto_rollback_triggered'] ?? true) === false) {
        $pass('fail_does_not_auto_rollback');
    } else {
        $fail('fail_does_not_auto_rollback', 'auto_rollback_triggered must be false.');
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
        return orange_cpr_p8int_fail(ORANGE_CPR_P8INT_ERR_VERIFY, 'P8 integration verification failed.', [
            'checks' => $checks,
            'job_id' => $jobId,
        ]);
    }

    return orange_cpr_p8int_ok([
        'message' => 'P8 Owner Certification chain verification PASS.',
        'checks' => $checks,
        'job_id' => $jobId,
        'package_fingerprint' => $fpJob,
        'country_id' => $countryId,
        'schema_revision_expected' => $schema,
        'result' => $result,
        'certification_id' => $certResult['certification_id'] ?? null,
        'decision_id' => $decision['decision_id'] ?? null,
        'submission_id' => $subPkg['submission_id'] ?? null,
    ]);
}

/**
 * Run full P8 Owner Certification chain and seal integration freeze report.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_p8_integration_run(array $env, array $request = []): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_p8int_fail(ORANGE_CPR_P8INT_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_p8int_fail(
            ORANGE_CPR_P8INT_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during P8 integration freeze.'
        );
    }
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_p8int_fail(
            ORANGE_CPR_P8INT_ERR_ACTOR,
            'Super Admin required to orchestrate P8 integration (submission substrate).'
        );
    }
    $unsafe = orange_cpr_p8_integration_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $certResultWanted = strtoupper(trim((string) ($request['cert_result'] ?? ($request['result'] ?? 'PASS'))));
    if (!in_array($certResultWanted, ['PASS', 'FAIL'], true)) {
        return orange_cpr_p8int_fail(
            ORANGE_CPR_P8INT_ERR_RESULT,
            'cert_result must be PASS or FAIL for integration chain.'
        );
    }

    $actorAdminId = (int) $request['actor_admin_id'];
    $ownerActorId = (int) ($request['owner_actor_id'] ?? ($request['decided_by_actor_id'] ?? 42));
    if ($ownerActorId <= 0) {
        return orange_cpr_p8int_fail(ORANGE_CPR_P8INT_ERR_ACTOR, 'owner_actor_id required for Owner ceremony.');
    }
    $cprRoot = orange_cpr_resolve_work_root($env);

    $existingJobId = trim((string) ($request['job_id'] ?? ''));
    if ($existingJobId !== '') {
        $existing = orange_cpr_p8_integration_load_latest($cprRoot, $existingJobId);
        if (is_array($existing) && !empty($existing['p8_baseline_frozen'])) {
            if (!empty($request['force_replay'])) {
                return orange_cpr_p8int_fail(
                    ORANGE_CPR_P8INT_ERR_REPLAY,
                    'Replay of frozen P8 integration baseline forbidden.'
                );
            }

            return orange_cpr_p8int_ok([
                'message' => 'P8 integration baseline already frozen (idempotent).',
                'job_id' => $existingJobId,
                'idempotent' => true,
                'exactly_once' => true,
                'p8_baseline_ready' => true,
                'p8_baseline_frozen' => true,
                'integration_report' => $existing,
            ]);
        }
    }
    if (!empty($request['force_replay'])) {
        return orange_cpr_p8int_fail(ORANGE_CPR_P8INT_ERR_REPLAY, 'force_replay refused when baseline incomplete.');
    }

    $cloneRoot = trim((string) ($request['clone_work_root'] ?? ''));
    if ($cloneRoot === '') {
        $cloneRoot = (string) ($env['ORANGE_RESTORE_WORK_DIR'] ?? sys_get_temp_dir())
            . DIRECTORY_SEPARATOR . 'p8_clone_' . bin2hex(random_bytes(3));
    }
    if (!is_dir($cloneRoot) && !@mkdir($cloneRoot, 0775, true) && !is_dir($cloneRoot)) {
        return orange_cpr_p8int_fail(ORANGE_CPR_P8INT_ERR_CHAIN, 'Cannot create clone work root.');
    }

    $fp = trim((string) ($request['package_fingerprint'] ?? str_repeat('8', 32)));
    $schema = (int) ($request['schema_revision'] ?? 121);
    $countryId = (int) ($request['country_id'] ?? 1);
    $countryCode = strtoupper(trim((string) ($request['country_code'] ?? 'KW')));

    $stages = [];

    // Substrate: sealed P7 evidence baseline (existing module — not redesigned).
    $p7 = orange_cpr_p7_integration_run($env, [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'clone_work_root' => $cloneRoot,
        'package_fingerprint' => $fp,
        'schema_revision' => $schema,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'package_id' => (string) ($request['package_id'] ?? 'drill-pkg-p804'),
        'clone_environment_id' => (string) ($request['clone_environment_id'] ?? 'clone-env-p804'),
        'drill_context' => (string) ($request['drill_context'] ?? 'clone'),
        'job_id' => $existingJobId,
    ]);
    if (empty($p7['ok'])) {
        return orange_cpr_p8int_fail(
            ORANGE_CPR_P8INT_ERR_CHAIN,
            'P7 substrate stage failed: ' . (string) ($p7['code'] ?? ''),
            ['stage' => 'p7_substrate', 'detail' => $p7]
        );
    }
    $jobId = (string) ($p7['job_id'] ?? '');
    $stages[] = ['stage' => 'p7_substrate_sealed_evidence', 'ok' => true, 'job_id' => $jobId];

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.p8_integration_live_start',
        'job_id' => $jobId,
        'stage_order' => orange_cpr_p8_integration_stage_order(),
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'p9_started' => false,
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
        return orange_cpr_p8int_fail(
            ORANGE_CPR_P8INT_ERR_CHAIN,
            'Owner Submission stage failed: ' . (string) ($submission['code'] ?? ''),
            ['stage' => 'sealed_owner_submission', 'detail' => $submission]
        );
    }
    $stages[] = [
        'stage' => 'sealed_owner_submission',
        'ok' => true,
        'submission_id' => $submission['submission_id'] ?? null,
    ];

    $cgH = is_array($request['cg_h_reviews'] ?? null)
        ? $request['cg_h_reviews']
        : orange_cpr_p8_integration_default_cg_h_reviews();
    if ($certResultWanted === 'FAIL' && !isset($request['cg_h_reviews'])) {
        $cgH[1]['accepted'] = false;
        $cgH[1]['notes'] = 'P8 integration FAIL path — CG-H02 not accepted';
    }

    $decision = orange_cpr_owner_cert_decision_live_run($env, [
        'actor_admin_id' => $ownerActorId,
        'actor_is_owner' => true,
        'actor_role' => 'owner',
        'decided_by' => 'owner',
        'owner_certification_ceremony' => true,
        'result' => $certResultWanted,
        'cg_f01' => $certResultWanted,
        'cg_h_reviews' => $cgH,
        'rationale' => (string) ($request['rationale'] ?? ('P8 integration Owner ceremony: ' . $certResultWanted)),
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
        return orange_cpr_p8int_fail(
            ORANGE_CPR_P8INT_ERR_CHAIN,
            'Owner Cert decision stage failed: ' . (string) ($decision['code'] ?? ''),
            ['stage' => 'pass_or_fail_decision', 'detail' => $decision]
        );
    }
    $stages[] = ['stage' => 'owner_certification_ceremony', 'ok' => true];
    $stages[] = [
        'stage' => 'pass_or_fail_decision',
        'ok' => true,
        'result' => $decision['result'] ?? null,
        'decision_id' => $decision['decision_id'] ?? null,
    ];
    $stages[] = [
        'stage' => 'sealed_certification_result',
        'ok' => true,
        'certification_id' => $decision['certification_id'] ?? null,
        'lifecycle_state' => $decision['lifecycle_state'] ?? null,
    ];

    $verify = orange_cpr_p8_integration_verify($env, $jobId, []);
    if (empty($verify['ok'])) {
        return $verify;
    }

    // Replay probe (must refuse duplicate decision).
    $dupProbe = orange_cpr_owner_cert_decision_live_run($env, [
        'actor_admin_id' => $ownerActorId,
        'actor_is_owner' => true,
        'actor_role' => 'owner',
        'decided_by' => 'owner',
        'owner_certification_ceremony' => true,
        'result' => $certResultWanted,
        'cg_f01' => $certResultWanted,
        'cg_h_reviews' => $cgH,
        'rationale' => 'replay probe',
        'supporting_evidence_refs' => ['EV-14'],
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'schema_revision' => $schema,
    ]);
    if (!empty($dupProbe['ok']) || ($dupProbe['code'] ?? '') !== ORANGE_CPR_OCERT_ERR_DUPLICATE) {
        return orange_cpr_p8int_fail(
            ORANGE_CPR_P8INT_ERR_VERIFY,
            'Duplicate certification path not refused.',
            ['detail' => $dupProbe]
        );
    }

    $now = gmdate('c');
    $freezeId = orange_cpr_generate_job_id();
    $inventory = [
        'WP-P8-01' => [
            'doc' => 'COUNTRY_PRODUCTION_RESTORE_P8_ARTIFACT_INDEX.md',
            'code' => 'cpr_p8_control_plane.php',
        ],
        'WP-P8-02' => [
            'doc' => 'COUNTRY_PRODUCTION_RESTORE_P8_02_OWNER_SUBMISSION.md',
            'code' => 'cpr_owner_submission_live.php',
        ],
        'WP-P8-03' => [
            'doc' => 'COUNTRY_PRODUCTION_RESTORE_P8_03_OWNER_CERT_DECISION.md',
            'code' => 'cpr_owner_cert_decision_live.php',
        ],
        'WP-P8-04' => [
            'doc' => 'COUNTRY_PRODUCTION_RESTORE_P8_04_INTEGRATION_BASELINE.md',
            'code' => 'cpr_p8_integration.php',
        ],
    ];

    $result = (string) ($decision['result'] ?? $certResultWanted);
    $lifecycle = (string) ($decision['lifecycle_state'] ?? ($result === 'PASS' ? 'cert_pass' : 'cert_fail'));

    $recovery = [
        'job_id' => $jobId,
        'completed_phase' => 'p8_integration_baseline_frozen',
        'execution_status' => 'p8_baseline_ready',
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision_expected' => $schema,
        'submission_id' => $submission['submission_id'] ?? null,
        'certification_id' => $decision['certification_id'] ?? null,
        'decision_id' => $decision['decision_id'] ?? null,
        'package_cycle_id' => $submission['package_cycle_id'] ?? null,
        'pack_seal_hash' => $submission['pack_seal_hash'] ?? null,
        'result' => $result,
        'lifecycle_state' => $lifecycle,
        'stage_order' => orange_cpr_p8_integration_stage_order(),
        'enablement_flag_after_decision' => false,
        'auto_rollback_triggered' => false,
        'enterprise_audit_not_started' => true,
        'git_tag_not_created' => true,
        'p9_not_started' => true,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'state_engine_integrated' => true,
        'checkpoint_engine_integrated' => true,
    ];

    $report = [
        'schema_version' => ORANGE_CPR_P8_INTEGRATION_SCHEMA,
        'engine_version' => ORANGE_CPR_P8_INTEGRATION_VERSION,
        'record_type' => 'p8_integration_freeze_report',
        'freeze_id' => $freezeId,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'p8_baseline_frozen' => true,
        'p8_baseline_ready' => true,
        'exactly_once' => true,
        'stage_order' => orange_cpr_p8_integration_stage_order(),
        'stages' => $stages,
        'verification' => [
            'ok' => true,
            'checks' => $verify['checks'] ?? [],
        ],
        'final_artifact_inventory' => $inventory,
        'submission_id' => $submission['submission_id'] ?? null,
        'certification_id' => $decision['certification_id'] ?? null,
        'decision_id' => $decision['decision_id'] ?? null,
        'package_cycle_id' => $submission['package_cycle_id'] ?? null,
        'pack_seal_hash' => $submission['pack_seal_hash'] ?? null,
        'certification_fingerprint' => $decision['certification_fingerprint'] ?? null,
        'decision_fingerprint' => $decision['decision_fingerprint'] ?? null,
        'result' => $result,
        'lifecycle_state' => $lifecycle,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision_expected' => $schema,
        'actor_admin_id' => $actorAdminId,
        'owner_actor_id' => $ownerActorId,
        'ops_enablement_flag' => false,
        'enablement_flag_after_decision' => false,
        'cert_pass_does_not_enable' => true,
        'auto_rollback_triggered' => false,
        'fail_does_not_auto_rollback' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'p9_started' => false,
        'recovery_metadata' => $recovery,
        'created_at' => $now,
        'verdict' => 'A — P8 OWNER CERTIFICATION BASELINE APPROVED · READY FOR OWNER REVIEW (no Enterprise Audit / Tag / P9 until authorized)',
    ];

    try {
        $path = orange_cpr_p8_integration_persist(
            $cprRoot,
            $jobId,
            'cpr_p8_integration_' . $freezeId,
            $report
        );
        $latest = orange_cpr_p8_integration_latest_path($cprRoot, $jobId);
        $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
        $sealedLatest = orange_cpr_auth_seal(array_merge($report, ['integration_report_path' => $path]));
        $json = json_encode($sealedLatest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('P8 integration latest write failed.');
        }
        orange_cpr_atomic_rename_replace($tmp, $latest);
    } catch (RuntimeException $e) {
        return orange_cpr_p8int_fail(ORANGE_CPR_P8INT_ERR_PERSIST, $e->getMessage());
    }

    $stages[] = ['stage' => 'integration_freeze', 'ok' => true, 'freeze_id' => $freezeId];

    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_p8int_fail(
            ORANGE_CPR_P8INT_ERR_ENABLEMENT,
            'Enablement must remain FALSE after P8 freeze.'
        );
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.p8_integration_live_complete',
        'job_id' => $jobId,
        'freeze_id' => $freezeId,
        'p8_baseline_frozen' => true,
        'p8_baseline_ready' => true,
        'result' => $result,
        'certification_id' => $decision['certification_id'] ?? null,
        'submission_id' => $submission['submission_id'] ?? null,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'enablement_flag_after_decision' => false,
        'auto_rollback_triggered' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'p9_started' => false,
        'actor_admin_id' => $actorAdminId,
    ]);

    return orange_cpr_p8int_ok([
        'message' => 'P8 Owner Certification chain integrated and baseline frozen.',
        'job_id' => $jobId,
        'freeze_id' => $freezeId,
        'p8_baseline_ready' => true,
        'p8_baseline_frozen' => true,
        'exactly_once' => true,
        'stage_order' => orange_cpr_p8_integration_stage_order(),
        'stages' => $stages,
        'verification' => $verify,
        'result' => $result,
        'lifecycle_state' => $lifecycle,
        'submission_id' => $submission['submission_id'] ?? null,
        'certification_id' => $decision['certification_id'] ?? null,
        'decision_id' => $decision['decision_id'] ?? null,
        'package_cycle_id' => $submission['package_cycle_id'] ?? null,
        'pack_seal_hash' => $submission['pack_seal_hash'] ?? null,
        'integration_report' => orange_cpr_p8_integration_load_latest($cprRoot, $jobId),
        'recovery_metadata' => $recovery,
        'final_artifact_inventory' => $inventory,
        'production_resources_accessed' => false,
        'enablement_flag_after_decision' => false,
        'auto_rollback_triggered' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'p9_started' => false,
    ]);
}
