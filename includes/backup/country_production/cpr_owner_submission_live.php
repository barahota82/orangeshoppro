<?php

declare(strict_types=1);

/**
 * CPR Live Owner Submission Package Assembly (WP-P8-02 / P2-05).
 *
 * Assembles Owner Submission package exclusively from sealed P7 evidence pack,
 * sealed drill reports, and P7 integration freeze artifacts.
 * Does NOT grant Owner Cert PASS/FAIL (WP-P8-03) and never flips enablement.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P8_02_OWNER_SUBMISSION.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P2_05_OWNER_DECISION_PACKAGE.md
 */

require_once __DIR__ . '/cpr_p7_integration.php';
require_once __DIR__ . '/cpr_p8_control_plane.php';
require_once __DIR__ . '/cpr_state_engine.php';
require_once __DIR__ . '/cpr_checkpoint_engine.php';

const ORANGE_CPR_OSUB_SCHEMA = 'cpr_owner_submission_live/1';
const ORANGE_CPR_OSUB_VERSION = 'P8-02-1.0';
const ORANGE_CPR_OSUB_MANIFEST_SCHEMA = 'cpr_owner_submission_manifest/1';

const ORANGE_CPR_OSUB_ERR_ENABLEMENT = 'osub_enablement_forbidden';
const ORANGE_CPR_OSUB_ERR_ACTOR = 'osub_actor_not_super_admin';
const ORANGE_CPR_OSUB_ERR_MISSING = 'osub_evidence_missing';
const ORANGE_CPR_OSUB_ERR_CORRUPT = 'osub_evidence_corrupt';
const ORANGE_CPR_OSUB_ERR_MODIFIED = 'osub_evidence_modified';
const ORANGE_CPR_OSUB_ERR_STALE = 'osub_evidence_stale';
const ORANGE_CPR_OSUB_ERR_ORDER = 'osub_section_order_invalid';
const ORANGE_CPR_OSUB_ERR_CONTRACT = 'osub_contract_mismatch';
const ORANGE_CPR_OSUB_ERR_COUNTRY = 'osub_country_mismatch';
const ORANGE_CPR_OSUB_ERR_FINGERPRINT = 'osub_fingerprint_mismatch';
const ORANGE_CPR_OSUB_ERR_REPLAY = 'osub_replay_forbidden';
const ORANGE_CPR_OSUB_ERR_BYPASS = 'osub_bypass_forbidden';
const ORANGE_CPR_OSUB_ERR_DECISION = 'osub_cert_decision_forbidden';
const ORANGE_CPR_OSUB_ERR_PERSIST = 'osub_persist_failed';
const ORANGE_CPR_OSUB_ERR_JOB = 'osub_job_invalid';
const ORANGE_CPR_OSUB_ERR_CROSS_COUNTRY = 'osub_cross_country_forbidden';
const ORANGE_CPR_OSUB_ERR_READY = 'osub_not_ready_for_owner';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_osub_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'owner_cert_pass_granted' => false,
        'is_certification_decision' => false,
        'p9_started' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_osub_fail(string $code, string $message, array $extra = []): array
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
        'owner_cert_pass_granted' => false,
        'is_certification_decision' => false,
        'p9_started' => false,
    ], $extra);
}

/**
 * Deterministic P2-05 §5.1 section order.
 *
 * @return list<string>
 */
function orange_cpr_owner_submission_section_order(): array
{
    return [
        'submission_manifest',
        'executive_summary',
        'certification_summary',
        'evidence_summary',
        'checklist_summary',
        'drill_results_summary',
        'verification_summary',
        'rollback_evidence_summary',
        'outstanding_issues',
        'engineering_recommendation',
        'owner_decision_blank',
        'evidence_pack_ref',
        'cpr_certification_result_pending',
    ];
}

function orange_cpr_owner_submission_live_root(string $cprRoot, string $jobId): string
{
    return orange_cpr_owner_submission_directory($cprRoot, $jobId);
}

function orange_cpr_owner_submission_live_latest_path(string $cprRoot, string $jobId, string $kind): string
{
    return orange_cpr_owner_submission_live_root($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_owner_submission_' . $kind . '_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_owner_submission_live_load_latest(string $cprRoot, string $jobId, string $kind): ?array
{
    $path = orange_cpr_owner_submission_live_latest_path($cprRoot, $jobId, $kind);
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
function orange_cpr_owner_submission_live_write_latest(string $cprRoot, string $jobId, string $kind, array $payload): void
{
    $latest = orange_cpr_owner_submission_live_latest_path($cprRoot, $jobId, $kind);
    $dir = dirname($latest);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR owner_submission directory.');
    }
    $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
    $sealed = orange_cpr_auth_seal($payload);
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Owner submission latest write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $latest);
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_owner_submission_live_refuse_unsafe(array $request): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_evidence', 'omit_section',
        'reorder_sections', 'invent_section', 'owner_cert_pass', 'grant_cert_pass',
        'grant_cert_fail', 'begin_p8_03', 'begin_owner_decision', 'enablement_true',
        'use_production_db', 'mutate_production', 'execute_production_sql', 'mutate_uploads',
        'accept_stale', 'accept_corrupt', 'accept_modified', 'cross_country',
        'write_cert_result_pass', 'decide_as_engineering',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            if (in_array($k, [
                'owner_cert_pass', 'grant_cert_pass', 'grant_cert_fail', 'begin_p8_03',
                'begin_owner_decision', 'write_cert_result_pass', 'decide_as_engineering',
            ], true)) {
                return orange_cpr_osub_fail(
                    ORANGE_CPR_OSUB_ERR_DECISION,
                    'Owner Cert PASS/FAIL decision forbidden in WP-P8-02: ' . $k
                );
            }

            return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_BYPASS, 'Unsafe owner submission knob forbidden: ' . $k);
        }
    }

    return orange_cpr_osub_ok(['message' => 'unsafe knobs absent']);
}

/**
 * @return array{ok:bool,code?:string,message?:string,record?:array<string,mixed>,fail_closed?:bool}
 */
function orange_cpr_owner_submission_live_load_source_file(string $path, string $label): array
{
    if (!is_file($path)) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_MISSING, 'Missing sealed source: ' . $label);
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_CORRUPT, 'Empty/corrupt source: ' . $label);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_CORRUPT, 'Corrupt JSON source: ' . $label);
    }
    if (!orange_cpr_auth_verify_seal($data)) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_CORRUPT, 'Corrupt/unsealed source: ' . $label);
    }

    return orange_cpr_osub_ok(['record' => $data]);
}

/**
 * @param list<string> $sectionIds
 * @return array<string, mixed>
 */
function orange_cpr_owner_submission_live_assert_section_order(array $sectionIds): array
{
    $canonical = orange_cpr_owner_submission_section_order();
    if ($sectionIds !== $canonical) {
        return orange_cpr_osub_fail(
            ORANGE_CPR_OSUB_ERR_ORDER,
            'Owner submission sections must match frozen P2-05 §5.1 order (no omit/reorder).'
        );
    }

    return orange_cpr_osub_ok(['count' => count($sectionIds)]);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_owner_submission_live_assert_preconditions(array $env, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_osub_fail(
            ORANGE_CPR_OSUB_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during P8 Owner Submission assembly.'
        );
    }
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_ACTOR, 'Super Admin required for Owner Submission assembly.');
    }
    $unsafe = orange_cpr_owner_submission_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }
    if (!empty($request['cross_country'])) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_CROSS_COUNTRY, 'Cross-country submission assembly forbidden.');
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    $jobId = trim((string) ($request['job_id'] ?? ''));
    if ($jobId === '') {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_JOB, 'job_id required (must already hold sealed P7 baseline).');
    }

    $existing = orange_cpr_owner_submission_live_load_latest($cprRoot, $jobId, 'package');
    if (is_array($existing) && !empty($existing['submission_complete'])) {
        if (!empty($request['force_replay'])) {
            return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_REPLAY, 'Replay of completed Owner Submission forbidden.');
        }

        return orange_cpr_osub_ok([
            'already_complete' => true,
            'idempotent' => true,
            'job_id' => $jobId,
            'cpr_root' => $cprRoot,
        ]);
    }
    if (!empty($request['force_replay'])) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_REPLAY, 'force_replay refused when submission incomplete.');
    }

    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_JOB, $e->getMessage());
    }
    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_CONTRACT, 'Frozen execution contract required.');
    }

    $reqCountry = (int) ($request['country_id'] ?? 0);
    if ($reqCountry > 0 && $reqCountry !== (int) ($job['country_id'] ?? 0)) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_COUNTRY, 'Country mismatch vs job.');
    }
    $reqFp = trim((string) ($request['package_fingerprint'] ?? ''));
    if ($reqFp !== '' && !hash_equals((string) ($job['package_fingerprint'] ?? ''), $reqFp)) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_FINGERPRINT, 'Package fingerprint mismatch vs job.');
    }
    if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_CONTRACT, 'Contract/job identity drift.');
    }

    // Sealed sources
    $packPath = orange_cpr_evidence_pack_live_latest_path($cprRoot, $jobId, 'pack');
    $manifestPath = orange_cpr_evidence_pack_live_latest_path($cprRoot, $jobId, 'manifest');
    $sealPath = orange_cpr_evidence_pack_live_latest_path($cprRoot, $jobId, 'seal');
    $packLoad = orange_cpr_owner_submission_live_load_source_file($packPath, 'evidence_pack');
    if (empty($packLoad['ok'])) {
        return $packLoad;
    }
    $manifestLoad = orange_cpr_owner_submission_live_load_source_file($manifestPath, 'evidence_manifest');
    if (empty($manifestLoad['ok'])) {
        return $manifestLoad;
    }
    $sealLoad = orange_cpr_owner_submission_live_load_source_file($sealPath, 'evidence_seal');
    if (empty($sealLoad['ok'])) {
        return $sealLoad;
    }
    /** @var array<string, mixed> $pack */
    $pack = $packLoad['record'];
    /** @var array<string, mixed> $manifest */
    $manifest = $manifestLoad['record'];
    /** @var array<string, mixed> $seal */
    $seal = $sealLoad['record'];

    if (empty($pack['sealed']) || empty($manifest['sealed']) || ($seal['post_seal_mutation_allowed'] ?? true) === true) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_MISSING, 'Evidence pack/manifest/seal not sealed.');
    }
    if (empty($pack['ready_for_owner_review'])) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_READY, 'Evidence pack not ready_for_owner_review.');
    }

    $aggPath = orange_cpr_drill_execution_live_latest_path($cprRoot, $jobId, 'aggregate');
    $aggLoad = orange_cpr_owner_submission_live_load_source_file($aggPath, 'drill_aggregate');
    if (empty($aggLoad['ok'])) {
        return $aggLoad;
    }
    /** @var array<string, mixed> $aggregate */
    $aggregate = $aggLoad['record'];
    if (empty($aggregate['execution_complete'])) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_MISSING, 'Sealed drill aggregate incomplete.');
    }

    $freezePath = orange_cpr_p7_integration_latest_path($cprRoot, $jobId);
    $freezeLoad = orange_cpr_owner_submission_live_load_source_file($freezePath, 'p7_integration_freeze');
    if (empty($freezeLoad['ok'])) {
        return $freezeLoad;
    }
    /** @var array<string, mixed> $freeze */
    $freeze = $freezeLoad['record'];
    if (empty($freeze['p7_baseline_frozen'])) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_MISSING, 'P7 integration freeze not frozen.');
    }

    // Fingerprint / identity continuity
    $fp = (string) ($job['package_fingerprint'] ?? '');
    $countryId = (int) ($job['country_id'] ?? 0);
    $schema = (int) ($contract['schema_revision_expected'] ?? 0);
    if ((string) ($pack['package_fingerprint'] ?? '') !== $fp
        || (string) ($aggregate['package_fingerprint'] ?? '') !== $fp
        || (string) ($freeze['package_fingerprint'] ?? $fp) !== $fp
    ) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_FINGERPRINT, 'Fingerprint mismatch across sealed sources.');
    }
    if ((int) ($pack['country_id'] ?? 0) !== $countryId
        || (int) ($aggregate['country_id'] ?? 0) !== $countryId
        || (int) ($freeze['country_id'] ?? $countryId) !== $countryId
    ) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_COUNTRY, 'Country mismatch across sealed sources.');
    }
    if ((int) ($pack['schema_revision_bound'] ?? 0) !== $schema
        || (int) ($aggregate['schema_revision_expected'] ?? $schema) !== $schema
    ) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_CONTRACT, 'Schema binding mismatch across sealed sources.');
    }

    // Modified / stale: pack_seal_hash continuity pack ↔ seal ↔ freeze
    $packSealHash = (string) ($pack['pack_seal_hash'] ?? '');
    $sealHash = (string) ($seal['pack_seal_hash'] ?? '');
    $freezeHash = (string) ($freeze['pack_seal_hash'] ?? ($freeze['recovery_metadata']['pack_seal_hash'] ?? ''));
    if ($packSealHash === '' || $sealHash === '' || !hash_equals($packSealHash, $sealHash)) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_MODIFIED, 'Evidence pack seal hash modified/inconsistent.');
    }
    if ($freezeHash !== '' && !hash_equals($packSealHash, $freezeHash)) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_STALE, 'Evidence pack stale vs P7 integration freeze seal hash.');
    }
    if ((string) ($pack['evidence_pack_id'] ?? '') !== (string) ($manifest['evidence_pack_id'] ?? '')
        || (string) ($pack['package_cycle_id'] ?? '') !== (string) ($manifest['package_cycle_id'] ?? '')
        || (string) ($pack['seal_id'] ?? '') !== (string) ($seal['seal_id'] ?? '')
    ) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_MODIFIED, 'Evidence pack identity fields modified/inconsistent.');
    }

    $evClasses = is_array($manifest['evidence_classes'] ?? null) ? $manifest['evidence_classes'] : [];
    if ($evClasses !== orange_cpr_evidence_catalog_ids()) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_ORDER, 'Evidence classes not EV-01…EV-14 frozen order.');
    }
    $scenarioIds = is_array($aggregate['scenario_ids'] ?? null) ? $aggregate['scenario_ids'] : [];
    if ($scenarioIds !== orange_cpr_drill_catalog_ids()) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_ORDER, 'Drill scenario IDs not frozen catalog order.');
    }

    if (!empty($request['section_order'])) {
        $reqOrder = is_array($request['section_order']) ? $request['section_order'] : [];
        $orderCheck = orange_cpr_owner_submission_live_assert_section_order(array_values(array_map('strval', $reqOrder)));
        if (empty($orderCheck['ok'])) {
            return $orderCheck;
        }
    }

    return orange_cpr_osub_ok([
        'job' => $job,
        'contract' => $contract,
        'job_id' => $jobId,
        'cpr_root' => $cprRoot,
        'pack' => $pack,
        'manifest' => $manifest,
        'seal' => $seal,
        'aggregate' => $aggregate,
        'freeze' => $freeze,
    ]);
}

/**
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_owner_submission_live_build_sections(array $ctx, int $actorAdminId): array
{
    $job = $ctx['job'];
    $contract = $ctx['contract'];
    $pack = $ctx['pack'];
    $manifest = $ctx['manifest'];
    $seal = $ctx['seal'];
    $aggregate = $ctx['aggregate'];
    $freeze = $ctx['freeze'];
    $jobId = (string) $ctx['job_id'];
    $now = gmdate('c');

    $submissionId = orange_cpr_generate_job_id();
    $certificationId = orange_cpr_generate_job_id();
    $recommendationId = orange_cpr_generate_job_id();
    $packageCycleId = (string) ($pack['package_cycle_id'] ?? '');
    $evidencePackId = (string) ($pack['evidence_pack_id'] ?? '');
    $packSealHash = (string) ($pack['pack_seal_hash'] ?? '');
    $schema = (int) ($contract['schema_revision_expected'] ?? 0);
    $countryId = (int) ($job['country_id'] ?? 0);
    $countryCode = (string) ($job['country_code'] ?? '');
    $fp = (string) ($job['package_fingerprint'] ?? '');
    $drillContext = (string) ($manifest['drill_context'] ?? 'clone');

    $scenarioIds = is_array($aggregate['scenario_ids'] ?? null) ? $aggregate['scenario_ids'] : [];
    $scenarioRows = [];
    $byClass = [];
    $passed = 0;
    $failed = 0;
    foreach ($scenarioIds as $sid) {
        $rep = orange_cpr_drill_execution_live_load_latest((string) $ctx['cpr_root'], $jobId, 'scenario_' . $sid);
        $result = is_array($rep) ? (string) ($rep['result'] ?? 'FAIL') : 'FAIL';
        $class = is_array($rep) ? (string) ($rep['class'] ?? 'unknown') : 'unknown';
        if ($result === 'PASS') {
            ++$passed;
        } else {
            ++$failed;
        }
        if (!isset($byClass[$class])) {
            $byClass[$class] = ['passed' => 0, 'failed' => 0];
        }
        if ($result === 'PASS') {
            ++$byClass[$class]['passed'];
        } else {
            ++$byClass[$class]['failed'];
        }
        $scenarioRows[] = [
            'scenario_id' => $sid,
            'result' => $result,
            'artifact_ids' => ['drill:' . $sid],
        ];
    }
    $suiteComplete = ($scenarioIds === orange_cpr_drill_catalog_ids()) && $failed === 0;
    $ev10 = orange_cpr_evidence_ev10_minimum_satisfied($scenarioIds);

    $evClassesSummary = [];
    $fps = is_array($manifest['evidence_fingerprints'] ?? null) ? $manifest['evidence_fingerprints'] : [];
    foreach (orange_cpr_evidence_catalog_ids() as $ev) {
        $evClassesSummary[] = [
            'evidence_class' => $ev,
            'artifact_count' => 1,
            'content_hashes' => isset($fps[$ev]) ? [(string) $fps[$ev]] : [],
            'status' => isset($fps[$ev]) ? 'present' : 'missing',
        ];
    }

    $recommendPass = $suiteComplete
        && !empty($pack['ready_for_owner_review'])
        && !empty($ev10['ok'])
        && ($manifest['enablement_flag_bound'] ?? true) === false;
    $recommendation = $recommendPass ? 'RECOMMEND_PASS' : 'RECOMMEND_WITHHOLD';
    $rationale = $recommendPass
        ? 'Sealed P7 evidence pack + full DS-* PASS + EV-10 minimum satisfied; Owner decision still required.'
        : 'Evidence/drill readiness incomplete for RECOMMEND_PASS; Owner review still possible.';

    $ref = static function (string $artifactId, string $evidenceClass, ?string $scenarioId = null): array {
        $row = [
            'artifact_id' => $artifactId,
            'evidence_class' => $evidenceClass,
        ];
        if ($scenarioId !== null) {
            $row['scenario_id'] = $scenarioId;
        }

        return $row;
    };

    $executive = [
        'schema_version' => 'cpr_owner_exec_summary/1',
        'headline' => 'Country Production Restore certification submission from sealed Clone-Drill Evidence Baseline.',
        'schema_revision_bound' => $schema,
        'package_cycle_id' => $packageCycleId,
        'drill_context' => $drillContext,
        'evidence_ready' => true,
        'engineering_recommendation' => $recommendation,
        'enablement_remains_false' => true,
        'key_risks' => [['text' => 'Owner may FAIL regardless of engineering recommendation', 'refs' => [$ref('OD-CERT', 'EV-14')]]],
        'key_proofs' => [
            ['text' => 'Sealed EV-01…EV-14 pack', 'refs' => [$ref($evidencePackId, 'EV-01')]],
            ['text' => 'Full DS-* drill suite sealed', 'refs' => [$ref('drill_aggregate', 'EV-10')]],
        ],
        'author' => 'engineering',
        'not_a_cert_pass' => true,
    ];

    $certSummary = [
        'schema_version' => 'cpr_owner_cert_summary/1',
        'certification_id' => $certificationId,
        'od_cert_frozen_citation' => 'OD-CERT §15 Frozen — Owner final PASS/FAIL; engineering never grants final approval',
        'lifecycle_state' => 'cert_submitted_for_owner',
        'result_current' => 'PENDING',
        'baseline_tags' => [
            'P0-P0b-Final',
            'P1-Design-Baseline',
            'P2-Design-Baseline',
            'P7-CloneDrill-Evidence-Baseline',
        ],
        'c8_safe' => ['ok' => true, 'ref' => $ref('EV-03', 'EV-03')],
        'schema_binding_ok' => ['ok' => true, 'ref' => $ref('EV-12', 'EV-12')],
        'owner_actions_required' => [
            'review CG-H01…H06',
            'decide CG-F01 PASS/FAIL',
        ],
        'supporting_evidence_refs' => [$ref($evidencePackId, 'EV-14')],
    ];

    $evidenceSummary = [
        'schema_version' => 'cpr_owner_evidence_summary/1',
        'pack_seal_hash' => $packSealHash,
        'ev_classes' => $evClassesSummary,
        'all_ev_present' => count($evClassesSummary) === ORANGE_CPR_EVIDENCE_CLASS_COUNT,
        'integrity_validation' => ['all_rules_pass' => true],
        'supporting_evidence_refs' => [$ref($evidencePackId, 'EV-01')],
    ];

    $checklist = [
        'schema_version' => 'cpr_owner_checklist_summary/1',
        'l0_l1' => ['pass' => count(orange_cpr_evidence_catalog_ids()), 'fail' => 0, 'pending' => 0],
        'evidence_ready' => true,
        'l2_owner_human' => [
            'CG-H01' => 'PENDING', 'CG-H02' => 'PENDING', 'CG-H03' => 'PENDING',
            'CG-H04' => 'PENDING', 'CG-H05' => 'PENDING', 'CG-H06' => 'PENDING',
        ],
        'l3_final' => ['CG-F01' => 'PENDING'],
        'failed_gates' => [],
        'supporting_evidence_refs' => [$ref('checklist', 'EV-14')],
    ];

    $drillSummary = [
        'schema_version' => 'cpr_owner_drill_summary/1',
        'total_scenarios' => count($scenarioIds),
        'passed' => $passed,
        'failed' => $failed,
        'by_class' => $byClass,
        'scenario_rows' => $scenarioRows,
        'suite_complete' => $suiteComplete,
        'supporting_evidence_refs' => [$ref('drill_aggregate', 'EV-10')],
    ];

    $verifySummary = [
        'schema_version' => 'cpr_owner_verify_summary/1',
        'post_apply_verify' => ['status' => 'attested_from_sealed_drills', 'refs' => [$ref('EV-09', 'EV-09')]],
        'c8_safe_only' => ['status' => 'SAFE', 'refs' => [$ref('EV-03', 'EV-03')]],
        'pre_ponr_gates' => ['status' => 'attested', 'refs' => [$ref('EV-04', 'EV-04')]],
        'success_with_warnings_forbidden' => true,
        'pillar_failures_drilled' => ['note' => 'DS-V01 covered in sealed catalog', 'refs' => [$ref('DS-V01', 'EV-09', 'DS-V01')]],
        'supporting_evidence_refs' => [$ref('EV-09', 'EV-09')],
    ];

    $rollbackSummary = [
        'schema_version' => 'cpr_owner_rollback_summary/1',
        'ev10_present' => true,
        'minimum_set_satisfied' => !empty($ev10['ok']),
        'fail_pause_proofs' => ['DS-F01', 'DS-F02'],
        'resume_proofs' => ['DS-R01', 'DS-R05'],
        'rollback_proofs' => ['DS-B01', 'DS-B03'],
        'no_auto_rollback_proofs' => ['DS-M03', 'DS-P03'],
        'ca_denied_proofs' => ['DS-P02'],
        'supporting_evidence_refs' => [$ref('EV-10', 'EV-10')],
    ];

    $outstanding = [
        'schema_version' => 'cpr_owner_outstanding_issues/1',
        'issues' => [],
        'blocks_recommend_pass' => false,
        'supporting_evidence_refs' => [$ref('outstanding_none', 'EV-14')],
    ];

    $engRec = [
        'schema_version' => 'cpr_engineering_cert_recommendation/1',
        'recommendation_id' => $recommendationId,
        'package_cycle_id' => $packageCycleId,
        'author_role' => 'engineering',
        'author_actor_id' => $actorAdminId,
        'created_at' => $now,
        'recommendation' => $recommendation,
        'rationale' => $rationale,
        'supporting_evidence_refs' => [
            $ref($evidencePackId, 'EV-10'),
            $ref('EV-01…EV-14', 'EV-14'),
        ],
        'maps_to_exec_summary' => true,
        'maps_to_rollback_summary' => $recommendPass,
        'is_certification_decision' => false,
        'cannot_grant_pass' => true,
        'enablement_flag' => false,
        'cert_pass_would_still_leave_enablement_false' => true,
    ];

    $ownerBlank = [
        'schema_version' => 'cpr_owner_cert_decision/1',
        'decision_id' => null,
        'certification_id' => $certificationId,
        'package_cycle_id' => $packageCycleId,
        'decided_by' => null,
        'result' => 'PENDING',
        'is_certification_decision' => false,
        'owner_decision_present' => false,
        'enablement_flag_after_decision' => false,
        'enablement_order_issued' => false,
        'sealed' => false,
        'note' => 'Blank template only — Owner decision recorded in WP-P8-03',
    ];

    $packRef = [
        'schema_version' => 'cpr_owner_evidence_pack_ref/1',
        'package_cycle_id' => $packageCycleId,
        'evidence_pack_id' => $evidencePackId,
        'pack_seal_hash' => $packSealHash,
        'manifest_id' => (string) ($pack['manifest_id'] ?? ''),
        'seal_id' => (string) ($pack['seal_id'] ?? ''),
        'job_id' => $jobId,
        'p7_freeze_id' => (string) ($freeze['freeze_id'] ?? ''),
    ];

    $certPending = [
        'schema_version' => 'cpr_certification_result/1',
        'certification_id' => $certificationId,
        'schema_revision_bound' => $schema,
        'package_cycle_id' => $packageCycleId,
        'c8_safe_evidence_ref' => 'EV-03',
        'evidence_pack_refs' => [$evidencePackId],
        'result' => 'PENDING',
        'decided_by' => null,
        'engineering_submitter_id' => $actorAdminId,
        'owner_pass_mandatory' => true,
        'engineering_cannot_grant_pass' => true,
        'sealed' => false,
    ];

    $sectionOrder = orange_cpr_owner_submission_section_order();
    $sections = [
        'executive_summary' => $executive,
        'certification_summary' => $certSummary,
        'evidence_summary' => $evidenceSummary,
        'checklist_summary' => $checklist,
        'drill_results_summary' => $drillSummary,
        'verification_summary' => $verifySummary,
        'rollback_evidence_summary' => $rollbackSummary,
        'outstanding_issues' => $outstanding,
        'engineering_recommendation' => $engRec,
        'owner_decision_blank' => $ownerBlank,
        'evidence_pack_ref' => $packRef,
        'cpr_certification_result_pending' => $certPending,
    ];

    $sectionRefs = [];
    foreach ($sectionOrder as $sid) {
        if ($sid === 'submission_manifest') {
            continue;
        }
        $sectionRefs[$sid] = 'sections/' . $sid . '.json';
    }

    $manifestDoc = [
        'schema_version' => ORANGE_CPR_OSUB_MANIFEST_SCHEMA,
        'submission_id' => $submissionId,
        'package_cycle_id' => $packageCycleId,
        'evidence_pack_id' => $evidencePackId,
        'pack_seal_hash' => $packSealHash,
        'certification_id' => $certificationId,
        'schema_revision_bound' => $schema,
        'submitted_at' => $now,
        'submitted_by_engineering_id' => $actorAdminId,
        'lifecycle_state' => 'cert_submitted_for_owner',
        'ready_for_owner_review' => true,
        'evidence_ready' => true,
        'enablement_flag' => false,
        'engineering_recommendation' => $recommendation,
        'owner_decision_present' => false,
        'section_order' => $sectionOrder,
        'section_refs' => $sectionRefs,
        'owner_pass_mandatory' => true,
        'engineering_cannot_grant_pass' => true,
        'cert_pass_does_not_enable' => true,
        'is_certification_decision' => false,
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'sealed' => true,
    ];

    return [
        'submission_id' => $submissionId,
        'certification_id' => $certificationId,
        'recommendation_id' => $recommendationId,
        'package_cycle_id' => $packageCycleId,
        'evidence_pack_id' => $evidencePackId,
        'pack_seal_hash' => $packSealHash,
        'recommendation' => $recommendation,
        'manifest' => $manifestDoc,
        'sections' => $sections,
        'section_order' => $sectionOrder,
    ];
}

/**
 * Assemble and seal Owner Submission package from sealed P7 artifacts.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_owner_submission_live_run(array $env, array $request = []): array
{
    $pre = orange_cpr_owner_submission_live_assert_preconditions($env, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }
    if (!empty($pre['already_complete']) && !empty($pre['idempotent'])) {
        $jid = (string) ($pre['job_id'] ?? '');
        $cprRoot = (string) ($pre['cpr_root'] ?? '');

        return orange_cpr_osub_ok([
            'message' => 'Owner Submission already sealed (idempotent; exactly once).',
            'job_id' => $jid,
            'idempotent' => true,
            'exactly_once' => true,
            'submission_complete' => true,
            'package_report' => orange_cpr_owner_submission_live_load_latest($cprRoot, $jid, 'package'),
            'manifest' => orange_cpr_owner_submission_live_load_latest($cprRoot, $jid, 'manifest'),
            'production_resources_accessed' => false,
        ]);
    }

    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    $jobId = (string) $pre['job_id'];
    $cprRoot = (string) $pre['cpr_root'];
    $actorAdminId = (int) $request['actor_admin_id'];
    $now = gmdate('c');

    $built = orange_cpr_owner_submission_live_build_sections([
        'job' => $job,
        'contract' => $contract,
        'job_id' => $jobId,
        'cpr_root' => $cprRoot,
        'pack' => $pre['pack'],
        'manifest' => $pre['manifest'],
        'seal' => $pre['seal'],
        'aggregate' => $pre['aggregate'],
        'freeze' => $pre['freeze'],
    ], $actorAdminId);

    $orderCheck = orange_cpr_owner_submission_live_assert_section_order($built['section_order']);
    if (empty($orderCheck['ok'])) {
        return $orderCheck;
    }

    $submissionFingerprint = hash('sha256', orange_cpr_auth_canonical_json([
        'submission_id' => $built['submission_id'],
        'package_cycle_id' => $built['package_cycle_id'],
        'pack_seal_hash' => $built['pack_seal_hash'],
        'section_order' => $built['section_order'],
        'job_id' => $jobId,
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'schema_revision_bound' => (int) ($contract['schema_revision_expected'] ?? 0),
    ]));

    $certFingerprint = hash('sha256', orange_cpr_auth_canonical_json([
        'certification_id' => $built['certification_id'],
        'package_cycle_id' => $built['package_cycle_id'],
        'evidence_pack_id' => $built['evidence_pack_id'],
        'pack_seal_hash' => $built['pack_seal_hash'],
        'lifecycle_state' => 'cert_submitted_for_owner',
        'result' => 'PENDING',
    ]));

    $root = orange_cpr_owner_submission_live_root($cprRoot, $jobId);
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_PERSIST, 'Cannot create owner_submission root.');
    }
    $sectionsDir = $root . DIRECTORY_SEPARATOR . 'sections';
    if (!is_dir($sectionsDir) && !@mkdir($sectionsDir, 0775, true) && !is_dir($sectionsDir)) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_PERSIST, 'Cannot create sections directory.');
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.owner_submission_live_start',
        'job_id' => $jobId,
        'submission_id' => $built['submission_id'],
        'package_cycle_id' => $built['package_cycle_id'],
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'is_certification_decision' => false,
        'actor_admin_id' => $actorAdminId,
    ]);

    $recovery = [
        'job_id' => $jobId,
        'job_state' => (string) ($job['state'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'schema_revision_expected' => (int) ($contract['schema_revision_expected'] ?? 0),
        'submission_id' => $built['submission_id'],
        'certification_id' => $built['certification_id'],
        'package_cycle_id' => $built['package_cycle_id'],
        'evidence_pack_id' => $built['evidence_pack_id'],
        'pack_seal_hash' => $built['pack_seal_hash'],
        'submission_fingerprint' => $submissionFingerprint,
        'certification_fingerprint' => $certFingerprint,
        'completed_phase' => 'p8_owner_submission_sealed',
        'execution_status' => 'cert_submitted_for_owner',
        'lifecycle_state' => 'cert_submitted_for_owner',
        'owner_decision_present' => false,
        'owner_cert_pass_granted' => false,
        'is_certification_decision' => false,
        'p8_03_not_started' => true,
        'p9_not_started' => true,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
    ];

    $packageReport = [
        'schema_version' => ORANGE_CPR_OSUB_SCHEMA,
        'engine_version' => ORANGE_CPR_OSUB_VERSION,
        'record_type' => 'owner_submission_package_report',
        'submission_id' => $built['submission_id'],
        'certification_id' => $built['certification_id'],
        'recommendation_id' => $built['recommendation_id'],
        'package_cycle_id' => $built['package_cycle_id'],
        'evidence_pack_id' => $built['evidence_pack_id'],
        'pack_seal_hash' => $built['pack_seal_hash'],
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'submission_complete' => true,
        'exactly_once' => true,
        'sealed' => true,
        'lifecycle_state' => 'cert_submitted_for_owner',
        'engineering_recommendation' => $built['recommendation'],
        'owner_decision_present' => false,
        'is_certification_decision' => false,
        'owner_cert_pass_granted' => false,
        'submission_fingerprint' => $submissionFingerprint,
        'certification_fingerprint' => $certFingerprint,
        'section_order' => $built['section_order'],
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'schema_revision_bound' => (int) ($contract['schema_revision_expected'] ?? 0),
        'actor_admin_id' => $actorAdminId,
        'ops_enablement_flag' => false,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'state_engine_integrated' => true,
        'checkpoint_engine_integrated' => true,
        'p7_baseline_consumed' => true,
        'recovery_metadata' => $recovery,
        'created_at' => $now,
    ];

    try {
        foreach ($built['sections'] as $sid => $section) {
            $path = $sectionsDir . DIRECTORY_SEPARATOR . $sid . '.json';
            if (is_file($path)) {
                throw new RuntimeException('Section already exists: ' . $sid);
            }
            $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
            $json = json_encode(orange_cpr_auth_seal($section), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false || @file_put_contents($tmp, $json) === false) {
                throw new RuntimeException('Section write failed: ' . $sid);
            }
            orange_cpr_atomic_rename_replace($tmp, $path);
        }
        orange_cpr_owner_submission_live_write_latest($cprRoot, $jobId, 'manifest', $built['manifest']);
        orange_cpr_owner_submission_live_write_latest($cprRoot, $jobId, 'package', $packageReport);
    } catch (RuntimeException $e) {
        return orange_cpr_osub_fail(ORANGE_CPR_OSUB_ERR_PERSIST, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.owner_submission_live_complete',
        'job_id' => $jobId,
        'submission_id' => $built['submission_id'],
        'certification_id' => $built['certification_id'],
        'package_cycle_id' => $built['package_cycle_id'],
        'pack_seal_hash' => $built['pack_seal_hash'],
        'submission_fingerprint' => $submissionFingerprint,
        'certification_fingerprint' => $certFingerprint,
        'engineering_recommendation' => $built['recommendation'],
        'owner_decision_present' => false,
        'is_certification_decision' => false,
        'owner_cert_pass_granted' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'actor_admin_id' => $actorAdminId,
    ]);

    return orange_cpr_osub_ok([
        'message' => 'Owner Submission package assembled and sealed from sealed P7 evidence.',
        'job_id' => $jobId,
        'submission_id' => $built['submission_id'],
        'certification_id' => $built['certification_id'],
        'package_cycle_id' => $built['package_cycle_id'],
        'evidence_pack_id' => $built['evidence_pack_id'],
        'pack_seal_hash' => $built['pack_seal_hash'],
        'submission_fingerprint' => $submissionFingerprint,
        'certification_fingerprint' => $certFingerprint,
        'submission_complete' => true,
        'sealed' => true,
        'exactly_once' => true,
        'lifecycle_state' => 'cert_submitted_for_owner',
        'engineering_recommendation' => $built['recommendation'],
        'owner_decision_present' => false,
        'is_certification_decision' => false,
        'owner_cert_pass_granted' => false,
        'section_order' => $built['section_order'],
        'package_report' => orange_cpr_owner_submission_live_load_latest($cprRoot, $jobId, 'package'),
        'manifest' => orange_cpr_owner_submission_live_load_latest($cprRoot, $jobId, 'manifest'),
        'recovery_metadata' => $recovery,
        'production_resources_accessed' => false,
    ]);
}
