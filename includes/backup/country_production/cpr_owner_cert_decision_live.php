<?php

declare(strict_types=1);

/**
 * CPR Live Owner Certification Decision (WP-P8-03 / OD-CERT / P1-13 / P2-05 §8).
 *
 * Records Owner Cert PASS or FAIL exclusively against a sealed Owner Submission
 * package. Performs the Owner certification ceremony (CG-H* + CG-F01).
 * Does NOT flip enablement. FAIL does NOT trigger automatic rollback.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P8_03_OWNER_CERT_DECISION.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_13_ENABLEMENT_CERT_HOOKS.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P2_05_OWNER_DECISION_PACKAGE.md
 */

require_once __DIR__ . '/cpr_owner_submission_live.php';
require_once __DIR__ . '/cpr_p8_control_plane.php';
require_once __DIR__ . '/cpr_state_engine.php';
require_once __DIR__ . '/cpr_checkpoint_engine.php';
require_once __DIR__ . '/cpr_enablement.php';

const ORANGE_CPR_OCERT_SCHEMA = 'cpr_owner_cert_decision_live/1';
const ORANGE_CPR_OCERT_VERSION = 'P8-03-1.0';
const ORANGE_CPR_OCERT_MANIFEST_SCHEMA = 'cpr_owner_cert_decision_manifest/1';
const ORANGE_CPR_OCERT_RESULT_SCHEMA = 'cpr_certification_result/1';
const ORANGE_CPR_OCERT_DECISION_SCHEMA = 'cpr_owner_cert_decision/1';

const ORANGE_CPR_OCERT_ERR_ENABLEMENT = 'ocert_enablement_forbidden';
const ORANGE_CPR_OCERT_ERR_ACTOR = 'ocert_actor_not_owner';
const ORANGE_CPR_OCERT_ERR_MISSING = 'ocert_submission_missing';
const ORANGE_CPR_OCERT_ERR_CORRUPT = 'ocert_submission_corrupt';
const ORANGE_CPR_OCERT_ERR_MODIFIED = 'ocert_submission_modified';
const ORANGE_CPR_OCERT_ERR_CONTRACT = 'ocert_contract_mismatch';
const ORANGE_CPR_OCERT_ERR_COUNTRY = 'ocert_country_mismatch';
const ORANGE_CPR_OCERT_ERR_FINGERPRINT = 'ocert_fingerprint_mismatch';
const ORANGE_CPR_OCERT_ERR_REPLAY = 'ocert_replay_forbidden';
const ORANGE_CPR_OCERT_ERR_DUPLICATE = 'ocert_duplicate_forbidden';
const ORANGE_CPR_OCERT_ERR_BYPASS = 'ocert_bypass_forbidden';
const ORANGE_CPR_OCERT_ERR_CEREMONY = 'ocert_ceremony_incomplete';
const ORANGE_CPR_OCERT_ERR_RESULT = 'ocert_result_invalid';
const ORANGE_CPR_OCERT_ERR_MUTUAL = 'ocert_pass_fail_mutual';
const ORANGE_CPR_OCERT_ERR_AUTO = 'ocert_automatic_decision_forbidden';
const ORANGE_CPR_OCERT_ERR_PERSIST = 'ocert_persist_failed';
const ORANGE_CPR_OCERT_ERR_JOB = 'ocert_job_invalid';
const ORANGE_CPR_OCERT_ERR_CROSS_COUNTRY = 'ocert_cross_country_forbidden';
const ORANGE_CPR_OCERT_ERR_ROLLBACK = 'ocert_auto_rollback_forbidden';
const ORANGE_CPR_OCERT_ERR_ENGINEERING = 'ocert_engineering_cannot_decide';

/**
 * @return list<string>
 */
function orange_cpr_owner_cert_cg_h_ids(): array
{
    return ['CG-H01', 'CG-H02', 'CG-H03', 'CG-H04', 'CG-H05', 'CG-H06'];
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_ocert_ok(array $extra = []): array
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
        'is_certification_decision' => true,
        'p9_started' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_ocert_fail(string $code, string $message, array $extra = []): array
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
        'is_certification_decision' => true,
        'p9_started' => false,
    ], $extra);
}

function orange_cpr_owner_cert_decision_live_root(string $cprRoot, string $jobId): string
{
    return orange_cpr_certification_directory($cprRoot, $jobId);
}

function orange_cpr_owner_cert_decision_live_latest_path(string $cprRoot, string $jobId, string $kind): string
{
    return orange_cpr_owner_cert_decision_live_root($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_owner_cert_' . $kind . '_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_owner_cert_decision_live_load_latest(string $cprRoot, string $jobId, string $kind): ?array
{
    $path = orange_cpr_owner_cert_decision_live_latest_path($cprRoot, $jobId, $kind);
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
 * @param array<string, mixed> $payload
 */
function orange_cpr_owner_cert_decision_live_write_latest(string $cprRoot, string $jobId, string $kind, array $payload): void
{
    $latest = orange_cpr_owner_cert_decision_live_latest_path($cprRoot, $jobId, $kind);
    $dir = dirname($latest);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR certification directory.');
    }
    $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
    $sealed = orange_cpr_auth_seal($payload);
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Owner cert latest write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $latest);
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_owner_cert_decision_live_refuse_unsafe(array $request): array
{
    $auto = [
        'auto_pass', 'auto_fail', 'auto_approve', 'auto_reject',
        'automatic_pass', 'automatic_fail', 'automatic_approval', 'automatic_rejection',
    ];
    foreach ($auto as $k) {
        if (!empty($request[$k])) {
            return orange_cpr_ocert_fail(
                ORANGE_CPR_OCERT_ERR_AUTO,
                'Automatic certification decision forbidden: ' . $k
            );
        }
    }

    $rollback = [
        'trigger_rollback', 'auto_rollback', 'perform_rollback', 'rollback_on_fail',
    ];
    foreach ($rollback as $k) {
        if (!empty($request[$k])) {
            return orange_cpr_ocert_fail(
                ORANGE_CPR_OCERT_ERR_ROLLBACK,
                'FAIL must not trigger automatic rollback: ' . $k
            );
        }
    }

    $bad = [
        'bypass', 'super_admin_bypass', 'owner_bypass', 'skip_ceremony',
        'skip_cg_h', 'skip_submission', 'enablement_true', 'flip_enablement',
        'use_production_db', 'mutate_production', 'execute_production_sql', 'mutate_uploads',
        'accept_corrupt', 'accept_missing', 'cross_country', 'force_replay',
        'decide_as_engineering', 'engineering_grant_pass', 'grant_cert_pass_as_engineering',
        'begin_p8_04', 'begin_p9',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            if (in_array($k, ['decide_as_engineering', 'engineering_grant_pass', 'grant_cert_pass_as_engineering'], true)) {
                return orange_cpr_ocert_fail(
                    ORANGE_CPR_OCERT_ERR_ENGINEERING,
                    'Engineering cannot grant Owner Cert decision: ' . $k
                );
            }

            return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_BYPASS, 'Unsafe owner cert knob forbidden: ' . $k);
        }
    }

    if (!empty($request['pass']) && !empty($request['fail'])) {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_MUTUAL,
            'PASS and FAIL are mutually exclusive.'
        );
    }

    return ['ok' => true];
}

/**
 * Normalize Owner result to PASS|FAIL only.
 *
 * @param array<string, mixed> $request
 */
function orange_cpr_owner_cert_decision_live_normalize_result(array $request): array
{
    $raw = strtoupper(trim((string) ($request['result'] ?? '')));
    if ($raw === '' && !empty($request['pass'])) {
        $raw = 'PASS';
    }
    if ($raw === '' && !empty($request['fail'])) {
        $raw = 'FAIL';
    }
    if ($raw !== 'PASS' && $raw !== 'FAIL') {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_RESULT,
            'Certification result must be strictly PASS or FAIL (no intermediate states).'
        );
    }
    if (!empty($request['pass']) && $raw === 'FAIL') {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_MUTUAL, 'Conflicting pass/fail knobs.');
    }
    if (!empty($request['fail']) && $raw === 'PASS') {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_MUTUAL, 'Conflicting pass/fail knobs.');
    }

    return ['ok' => true, 'result' => $raw];
}

/**
 * @param array<int, mixed> $reviews
 * @return array<string, mixed>
 */
function orange_cpr_owner_cert_decision_live_validate_ceremony(array $request, string $result): array
{
    if (empty($request['owner_certification_ceremony'])) {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_CEREMONY,
            'Approved Owner certification ceremony required (owner_certification_ceremony).'
        );
    }

    $decidedBy = strtolower(trim((string) ($request['decided_by'] ?? '')));
    if ($decidedBy !== 'owner') {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_ENGINEERING,
            'decided_by must be owner for PASS/FAIL (OD-CERT / P1-13).'
        );
    }

    $isOwner = !empty($request['actor_is_owner'])
        || strtolower(trim((string) ($request['actor_role'] ?? ''))) === 'owner';
    if (!$isOwner) {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_ACTOR,
            'Owner actor required for certification ceremony.'
        );
    }
    if (!empty($request['actor_is_engineering'])
        || strtolower(trim((string) ($request['actor_role'] ?? ''))) === 'engineering'
    ) {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_ENGINEERING,
            'Engineering actor cannot record Owner Cert PASS/FAIL.'
        );
    }

    $actorId = (int) ($request['actor_admin_id'] ?? ($request['decided_by_actor_id'] ?? 0));
    if ($actorId <= 0) {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_ACTOR, 'decided_by_actor_id / actor_admin_id required.');
    }

    $rationale = trim((string) ($request['rationale'] ?? ''));
    if ($rationale === '') {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_CEREMONY, 'Owner rationale required.');
    }

    $cgF01 = strtoupper(trim((string) ($request['cg_f01'] ?? $result)));
    if ($cgF01 !== $result) {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_CEREMONY,
            'CG-F01 must align with Owner result.'
        );
    }

    $reviewsIn = $request['cg_h_reviews'] ?? null;
    if (!is_array($reviewsIn)) {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_CEREMONY, 'cg_h_reviews required for Owner ceremony.');
    }

    $byId = [];
    foreach ($reviewsIn as $row) {
        if (!is_array($row)) {
            return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_CEREMONY, 'Invalid cg_h_reviews row.');
        }
        $gid = strtoupper(trim((string) ($row['gate_id'] ?? '')));
        if ($gid === '') {
            return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_CEREMONY, 'cg_h_reviews.gate_id required.');
        }
        $byId[$gid] = $row;
    }

    $normalized = [];
    foreach (orange_cpr_owner_cert_cg_h_ids() as $gid) {
        if (!isset($byId[$gid])) {
            return orange_cpr_ocert_fail(
                ORANGE_CPR_OCERT_ERR_CEREMONY,
                'Missing Owner CG-H review: ' . $gid
            );
        }
        $accepted = !empty($byId[$gid]['accepted']);
        $normalized[] = [
            'gate_id' => $gid,
            'accepted' => $accepted,
            'notes' => (string) ($byId[$gid]['notes'] ?? ''),
            'evidence_refs' => is_array($byId[$gid]['evidence_refs'] ?? null)
                ? array_values($byId[$gid]['evidence_refs'])
                : [],
        ];
    }

    if ($result === 'PASS') {
        foreach ($normalized as $row) {
            if (empty($row['accepted'])) {
                return orange_cpr_ocert_fail(
                    ORANGE_CPR_OCERT_ERR_CEREMONY,
                    'Owner PASS requires all CG-H01…H06 accepted; failed: ' . $row['gate_id']
                );
            }
        }
    }

    $refs = $request['supporting_evidence_refs'] ?? [];
    if (!is_array($refs) || $refs === []) {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_CEREMONY,
            'supporting_evidence_refs required (non-empty).'
        );
    }

    return [
        'ok' => true,
        'actor_id' => $actorId,
        'rationale' => $rationale,
        'cg_h_reviews' => $normalized,
        'cg_f01' => $cgF01,
        'supporting_evidence_refs' => array_values($refs),
    ];
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_owner_cert_decision_live_assert_preconditions(array $env, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during Owner Cert decision (OD-ENABLE / P9).'
        );
    }

    $unsafe = orange_cpr_owner_cert_decision_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $norm = orange_cpr_owner_cert_decision_live_normalize_result($request);
    if (empty($norm['ok'])) {
        return $norm;
    }
    $result = (string) $norm['result'];

    $ceremony = orange_cpr_owner_cert_decision_live_validate_ceremony($request, $result);
    if (empty($ceremony['ok'])) {
        return $ceremony;
    }

    if (!empty($request['cross_country_id']) || !empty($request['secondary_country_id'])) {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_CROSS_COUNTRY,
            'Cross-country certification forbidden.'
        );
    }

    $jobId = trim((string) ($request['job_id'] ?? ''));
    if ($jobId === '') {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_JOB, 'job_id required (must hold sealed Owner Submission).');
    }

    $cprRoot = orange_cpr_resolve_work_root($env);

    $existing = orange_cpr_owner_cert_decision_live_load_latest($cprRoot, $jobId, 'decision');
    if (is_array($existing) && !empty($existing['sealed']) && !empty($existing['decision_complete'])) {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_DUPLICATE,
            'Owner certification decision already recorded (exactly once; no replay).'
        );
    }
    $existingResult = orange_cpr_owner_cert_decision_live_load_latest($cprRoot, $jobId, 'result');
    if (is_array($existingResult) && !empty($existingResult['sealed'])
        && in_array((string) ($existingResult['result'] ?? ''), ['PASS', 'FAIL'], true)
    ) {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_DUPLICATE,
            'cpr_certification_result already sealed with PASS/FAIL.'
        );
    }

    try {
        orange_cpr_assert_job_id($jobId);
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_JOB, $e->getMessage());
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_CONTRACT, 'Frozen execution contract required.');
    }

    $reqCountry = (int) ($request['country_id'] ?? 0);
    if ($reqCountry > 0 && $reqCountry !== (int) ($job['country_id'] ?? 0)) {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_COUNTRY, 'Country mismatch vs job.');
    }
    $reqFp = trim((string) ($request['package_fingerprint'] ?? ''));
    if ($reqFp !== '' && !hash_equals((string) ($job['package_fingerprint'] ?? ''), $reqFp)) {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_FINGERPRINT, 'Package fingerprint mismatch vs job.');
    }
    if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_CONTRACT, 'Contract/job identity drift.');
    }

    $pkg = orange_cpr_owner_submission_live_load_latest($cprRoot, $jobId, 'package');
    $man = orange_cpr_owner_submission_live_load_latest($cprRoot, $jobId, 'manifest');
    if (!is_array($pkg) || !is_array($man)) {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_MISSING,
            'Sealed Owner Submission package/manifest missing.'
        );
    }
    if (empty($pkg['sealed']) || empty($pkg['submission_complete']) || empty($man['sealed'])) {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_CORRUPT,
            'Owner Submission package/manifest not sealed/complete.'
        );
    }
    if (($pkg['lifecycle_state'] ?? '') !== 'cert_submitted_for_owner'
        && ($man['lifecycle_state'] ?? '') !== 'cert_submitted_for_owner'
    ) {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_CORRUPT,
            'Owner Submission not in cert_submitted_for_owner lifecycle.'
        );
    }
    if (!empty($pkg['owner_decision_present']) || !empty($man['owner_decision_present'])) {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_DUPLICATE,
            'Owner Submission already marks owner_decision_present.'
        );
    }
    if (!empty($pkg['is_certification_decision']) || !empty($man['is_certification_decision'])) {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_CORRUPT,
            'Submission package must not already be a certification decision.'
        );
    }

    if ((string) ($pkg['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (string) ($man['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
    ) {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_FINGERPRINT, 'Submission fingerprint mismatch vs job.');
    }
    if ((int) ($pkg['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
        || (int) ($man['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_COUNTRY, 'Submission country mismatch vs job.');
    }
    $schema = (int) ($contract['schema_revision_expected'] ?? 0);
    if ((int) ($pkg['schema_revision_bound'] ?? 0) !== $schema
        || (int) ($man['schema_revision_bound'] ?? 0) !== $schema
    ) {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_CONTRACT, 'Schema binding mismatch vs submission.');
    }
    if ((string) ($pkg['submission_id'] ?? '') === ''
        || (string) ($pkg['certification_id'] ?? '') === ''
        || (string) ($pkg['package_cycle_id'] ?? '') === ''
    ) {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_CORRUPT, 'Submission identity fields incomplete.');
    }
    if ((string) ($pkg['submission_id'] ?? '') !== (string) ($man['submission_id'] ?? '')
        || (string) ($pkg['certification_id'] ?? '') !== (string) ($man['certification_id'] ?? '')
        || (string) ($pkg['pack_seal_hash'] ?? '') !== (string) ($man['pack_seal_hash'] ?? '')
    ) {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_MODIFIED, 'Submission package/manifest identity drift.');
    }

    $reqSchema = (int) ($request['schema_revision'] ?? 0);
    if ($reqSchema > 0 && $reqSchema !== $schema) {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_CONTRACT, 'Request schema_revision mismatch.');
    }

    return [
        'ok' => true,
        'result' => $result,
        'ceremony' => $ceremony,
        'job' => $job,
        'contract' => $contract,
        'job_id' => $jobId,
        'cpr_root' => $cprRoot,
        'package' => $pkg,
        'manifest' => $man,
    ];
}

/**
 * Record Owner Cert PASS or FAIL against sealed Owner Submission.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_owner_cert_decision_live_run(array $env, array $request = []): array
{
    $pre = orange_cpr_owner_cert_decision_live_assert_preconditions($env, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }

    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    /** @var array<string, mixed> $pkg */
    $pkg = $pre['package'];
    /** @var array<string, mixed> $man */
    $man = $pre['manifest'];
    /** @var array<string, mixed> $ceremony */
    $ceremony = $pre['ceremony'];
    $jobId = (string) $pre['job_id'];
    $cprRoot = (string) $pre['cpr_root'];
    $result = (string) $pre['result'];
    $actorId = (int) $ceremony['actor_id'];
    $now = gmdate('c');

    $decisionId = orange_cpr_generate_job_id();
    $certificationId = (string) ($pkg['certification_id'] ?? '');
    $packageCycleId = (string) ($pkg['package_cycle_id'] ?? '');
    $submissionId = (string) ($pkg['submission_id'] ?? '');
    $evidencePackId = (string) ($pkg['evidence_pack_id'] ?? '');
    $packSealHash = (string) ($pkg['pack_seal_hash'] ?? '');
    $schema = (int) ($contract['schema_revision_expected'] ?? 0);
    $fp = (string) ($job['package_fingerprint'] ?? '');
    $countryId = (int) ($job['country_id'] ?? 0);
    $countryCode = (string) ($job['country_code'] ?? '');
    $lifecycle = $result === 'PASS' ? 'cert_pass' : 'cert_fail';
    $ownerPassGranted = $result === 'PASS';

    // Explicit non-effects
    $enablementAfter = false;
    $autoRollback = false;
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_ENABLEMENT, 'Enablement flipped unexpectedly.');
    }

    $checkpointsObserved = orange_cpr_checkpoint_list_committed($cprRoot, $jobId);

    $decision = [
        'schema_version' => ORANGE_CPR_OCERT_DECISION_SCHEMA,
        'decision_id' => $decisionId,
        'certification_id' => $certificationId,
        'package_cycle_id' => $packageCycleId,
        'submission_id' => $submissionId,
        'decided_by' => 'owner',
        'decided_by_actor_id' => $actorId,
        'decided_at' => $now,
        'result' => $result,
        'cg_h_reviews' => $ceremony['cg_h_reviews'],
        'cg_f01' => $ceremony['cg_f01'],
        'engineering_recommendation_id' => (string) ($pkg['recommendation_id'] ?? ''),
        'engineering_recommendation_followed' => null,
        'rationale' => $ceremony['rationale'],
        'supporting_evidence_refs' => $ceremony['supporting_evidence_refs'],
        'is_certification_decision' => true,
        'enablement_flag_after_decision' => $enablementAfter,
        'enablement_order_issued' => false,
        'auto_rollback_triggered' => $autoRollback,
        'owner_certification_ceremony' => true,
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision_bound' => $schema,
        'sealed' => true,
    ];

    $certResult = [
        'schema_version' => ORANGE_CPR_OCERT_RESULT_SCHEMA,
        'certification_id' => $certificationId,
        'schema_revision_bound' => $schema,
        'package_cycle_id' => $packageCycleId,
        'c8_safe_evidence_ref' => 'EV-03',
        'evidence_pack_refs' => [$evidencePackId !== '' ? $evidencePackId : 'EV-14'],
        'result' => $result,
        'decided_by' => 'owner',
        'decided_by_actor_id' => $actorId,
        'decided_at' => $now,
        'engineering_submitter_id' => (int) ($man['submitted_by_engineering_id'] ?? ($pkg['actor_admin_id'] ?? 0)),
        'owner_pass_mandatory' => true,
        'engineering_cannot_grant_pass' => true,
        'invalidation_ref' => null,
        'enablement_flag_after_decision' => false,
        'cert_pass_does_not_enable' => true,
        'auto_rollback_triggered' => false,
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'submission_id' => $submissionId,
        'decision_id' => $decisionId,
        'pack_seal_hash' => $packSealHash,
        'lifecycle_state' => $lifecycle,
        'sealed' => true,
    ];

    if (($certResult['result'] ?? '') === 'PASS' && ($certResult['decided_by'] ?? '') !== 'owner') {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_ENGINEERING,
            'Reject PASS with decided_by != owner (P1-13).'
        );
    }

    $certFingerprint = hash('sha256', orange_cpr_auth_canonical_json([
        'certification_id' => $certificationId,
        'decision_id' => $decisionId,
        'package_cycle_id' => $packageCycleId,
        'submission_id' => $submissionId,
        'result' => $result,
        'decided_by' => 'owner',
        'decided_by_actor_id' => $actorId,
        'pack_seal_hash' => $packSealHash,
        'schema_revision_bound' => $schema,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'lifecycle_state' => $lifecycle,
    ]));

    $decisionFingerprint = hash('sha256', orange_cpr_auth_canonical_json([
        'decision_id' => $decisionId,
        'result' => $result,
        'cg_h_reviews' => $ceremony['cg_h_reviews'],
        'cg_f01' => $ceremony['cg_f01'],
        'rationale' => $ceremony['rationale'],
        'certification_id' => $certificationId,
    ]));

    $root = orange_cpr_owner_cert_decision_live_root($cprRoot, $jobId);
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_PERSIST, 'Cannot create certification root.');
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.owner_cert_decision_live_start',
        'job_id' => $jobId,
        'decision_id' => $decisionId,
        'certification_id' => $certificationId,
        'submission_id' => $submissionId,
        'result' => $result,
        'decided_by' => 'owner',
        'owner_certification_ceremony' => true,
        'enablement_flag_observed' => false,
        'auto_rollback_triggered' => false,
        'actor_admin_id' => $actorId,
        'production_resources_accessed' => false,
    ]);

    $recovery = [
        'job_id' => $jobId,
        'job_state' => (string) ($job['state'] ?? ''),
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision_expected' => $schema,
        'submission_id' => $submissionId,
        'certification_id' => $certificationId,
        'decision_id' => $decisionId,
        'package_cycle_id' => $packageCycleId,
        'evidence_pack_id' => $evidencePackId,
        'pack_seal_hash' => $packSealHash,
        'certification_fingerprint' => $certFingerprint,
        'decision_fingerprint' => $decisionFingerprint,
        'result' => $result,
        'lifecycle_state' => $lifecycle,
        'completed_phase' => 'p8_owner_cert_decision_sealed',
        'execution_status' => $lifecycle,
        'owner_decision_present' => true,
        'owner_cert_pass_granted' => $ownerPassGranted,
        'is_certification_decision' => true,
        'enablement_flag_after_decision' => false,
        'enablement_order_issued' => false,
        'auto_rollback_triggered' => false,
        'p8_04_not_started' => true,
        'p9_not_started' => true,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'state_engine_integrated' => true,
        'checkpoint_engine_integrated' => true,
        'checkpoints_committed_observed' => $checkpointsObserved,
    ];

    $manifest = [
        'schema_version' => ORANGE_CPR_OCERT_MANIFEST_SCHEMA,
        'decision_id' => $decisionId,
        'certification_id' => $certificationId,
        'submission_id' => $submissionId,
        'package_cycle_id' => $packageCycleId,
        'result' => $result,
        'lifecycle_state' => $lifecycle,
        'decided_by' => 'owner',
        'decided_by_actor_id' => $actorId,
        'decided_at' => $now,
        'owner_certification_ceremony' => true,
        'is_certification_decision' => true,
        'owner_cert_pass_granted' => $ownerPassGranted,
        'enablement_flag_after_decision' => false,
        'enablement_order_issued' => false,
        'cert_pass_does_not_enable' => true,
        'auto_rollback_triggered' => false,
        'fail_does_not_auto_rollback' => true,
        'certification_fingerprint' => $certFingerprint,
        'decision_fingerprint' => $decisionFingerprint,
        'pack_seal_hash' => $packSealHash,
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision_bound' => $schema,
        'artifact_refs' => [
            'decision' => 'cpr_owner_cert_decision_latest.json',
            'manifest' => 'cpr_owner_cert_manifest_latest.json',
            'result' => 'cpr_owner_cert_result_latest.json',
            'report' => 'cpr_owner_cert_report_latest.json',
        ],
        'sealed' => true,
    ];

    $report = [
        'schema_version' => ORANGE_CPR_OCERT_SCHEMA,
        'engine_version' => ORANGE_CPR_OCERT_VERSION,
        'record_type' => 'owner_cert_decision_report',
        'decision_id' => $decisionId,
        'certification_id' => $certificationId,
        'submission_id' => $submissionId,
        'package_cycle_id' => $packageCycleId,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'decision_complete' => true,
        'exactly_once' => true,
        'sealed' => true,
        'result' => $result,
        'lifecycle_state' => $lifecycle,
        'decided_by' => 'owner',
        'decided_by_actor_id' => $actorId,
        'owner_certification_ceremony' => true,
        'is_certification_decision' => true,
        'owner_cert_pass_granted' => $ownerPassGranted,
        'owner_decision_present' => true,
        'certification_fingerprint' => $certFingerprint,
        'decision_fingerprint' => $decisionFingerprint,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision_bound' => $schema,
        'ops_enablement_flag' => false,
        'enablement_flag_after_decision' => false,
        'enablement_order_issued' => false,
        'cert_pass_does_not_enable' => true,
        'auto_rollback_triggered' => false,
        'fail_does_not_auto_rollback' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'state_engine_integrated' => true,
        'checkpoint_engine_integrated' => true,
        'submission_consumed' => true,
        'recovery_metadata' => $recovery,
        'created_at' => $now,
    ];

    try {
        // Write under distinct kinds; result file is the P1-13 cpr_certification_result.
        orange_cpr_owner_cert_decision_live_write_latest($cprRoot, $jobId, 'decision', $decision);
        orange_cpr_owner_cert_decision_live_write_latest($cprRoot, $jobId, 'manifest', $manifest);
        orange_cpr_owner_cert_decision_live_write_latest($cprRoot, $jobId, 'result', $certResult);
        orange_cpr_owner_cert_decision_live_write_latest($cprRoot, $jobId, 'report', $report);
    } catch (RuntimeException $e) {
        return orange_cpr_ocert_fail(ORANGE_CPR_OCERT_ERR_PERSIST, $e->getMessage());
    }

    // Re-verify enablement still false after seal (PASS must not enable).
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_ocert_fail(
            ORANGE_CPR_OCERT_ERR_ENABLEMENT,
            'PASS must not enable production; enablement observed true after decision.'
        );
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.owner_cert_decision_live_complete',
        'job_id' => $jobId,
        'decision_id' => $decisionId,
        'certification_id' => $certificationId,
        'submission_id' => $submissionId,
        'result' => $result,
        'lifecycle_state' => $lifecycle,
        'decided_by' => 'owner',
        'decided_by_actor_id' => $actorId,
        'certification_fingerprint' => $certFingerprint,
        'decision_fingerprint' => $decisionFingerprint,
        'owner_cert_pass_granted' => $ownerPassGranted,
        'is_certification_decision' => true,
        'enablement_flag_after_decision' => false,
        'enablement_flag_observed' => false,
        'auto_rollback_triggered' => false,
        'fail_does_not_auto_rollback' => true,
        'production_resources_accessed' => false,
        'actor_admin_id' => $actorId,
    ]);

    return orange_cpr_ocert_ok([
        'message' => 'Owner certification decision sealed (' . $result . ').',
        'job_id' => $jobId,
        'decision_id' => $decisionId,
        'certification_id' => $certificationId,
        'submission_id' => $submissionId,
        'package_cycle_id' => $packageCycleId,
        'result' => $result,
        'lifecycle_state' => $lifecycle,
        'decided_by' => 'owner',
        'decided_by_actor_id' => $actorId,
        'decision_complete' => true,
        'sealed' => true,
        'exactly_once' => true,
        'owner_decision_present' => true,
        'owner_cert_pass_granted' => $ownerPassGranted,
        'is_certification_decision' => true,
        'owner_certification_ceremony' => true,
        'certification_fingerprint' => $certFingerprint,
        'decision_fingerprint' => $decisionFingerprint,
        'enablement_flag_after_decision' => false,
        'enablement_order_issued' => false,
        'cert_pass_does_not_enable' => true,
        'auto_rollback_triggered' => false,
        'fail_does_not_auto_rollback' => true,
        'decision' => orange_cpr_owner_cert_decision_live_load_latest($cprRoot, $jobId, 'decision'),
        'manifest' => orange_cpr_owner_cert_decision_live_load_latest($cprRoot, $jobId, 'manifest'),
        'cpr_certification_result' => orange_cpr_owner_cert_decision_live_load_latest($cprRoot, $jobId, 'result'),
        'report' => orange_cpr_owner_cert_decision_live_load_latest($cprRoot, $jobId, 'report'),
        'recovery_metadata' => $recovery,
        'production_resources_accessed' => false,
    ]);
}
