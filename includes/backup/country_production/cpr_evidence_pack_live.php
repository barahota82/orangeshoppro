<?php

declare(strict_types=1);

/**
 * CPR Live Evidence Pack Assembly & Seal (WP-P7-04 / P2-04 EV-01…EV-14).
 *
 * Assembles evidence only from sealed drill harness + scenario execution artifacts.
 * Does not grant Owner Cert PASS (P8) and never accesses production resources.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P7_04_EVIDENCE_PACK.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P2_04_EVIDENCE_PACK_SCHEMAS.md
 */

require_once __DIR__ . '/cpr_evidence_catalog.php';
require_once __DIR__ . '/cpr_drill_execution_live.php';
require_once __DIR__ . '/cpr_state_engine.php';
require_once __DIR__ . '/cpr_checkpoint_engine.php';
require_once __DIR__ . '/cpr_p7_control_plane.php';

const ORANGE_CPR_EVPACK_SCHEMA = 'cpr_evidence_pack_live/1';
const ORANGE_CPR_EVPACK_VERSION = 'P7-04-1.0';
const ORANGE_CPR_EVPACK_MANIFEST_SCHEMA = 'cpr_evidence_pack_manifest/1';
const ORANGE_CPR_EVPACK_SEAL_SCHEMA = 'cpr_evidence_pack_seal/1';

const ORANGE_CPR_EVLIVE_ERR_ENABLEMENT = 'evlive_enablement_forbidden';
const ORANGE_CPR_EVLIVE_ERR_ACTOR = 'evlive_actor_not_super_admin';
const ORANGE_CPR_EVLIVE_ERR_HARNESS = 'evlive_harness_required';
const ORANGE_CPR_EVLIVE_ERR_DRILL = 'evlive_drill_execution_required';
const ORANGE_CPR_EVLIVE_ERR_SEALED = 'evlive_source_not_sealed';
const ORANGE_CPR_EVLIVE_ERR_CORRUPT = 'evlive_source_corrupt';
const ORANGE_CPR_EVLIVE_ERR_MODIFIED = 'evlive_source_modified';
const ORANGE_CPR_EVLIVE_ERR_MISSING = 'evlive_evidence_missing';
const ORANGE_CPR_EVLIVE_ERR_ORDER = 'evlive_evidence_order_invalid';
const ORANGE_CPR_EVLIVE_ERR_CONTRACT = 'evlive_contract_mismatch';
const ORANGE_CPR_EVLIVE_ERR_COUNTRY = 'evlive_country_mismatch';
const ORANGE_CPR_EVLIVE_ERR_FINGERPRINT = 'evlive_fingerprint_mismatch';
const ORANGE_CPR_EVLIVE_ERR_REPLAY = 'evlive_replay_forbidden';
const ORANGE_CPR_EVLIVE_ERR_BYPASS = 'evlive_bypass_forbidden';
const ORANGE_CPR_EVLIVE_ERR_PERSIST = 'evlive_persist_failed';
const ORANGE_CPR_EVLIVE_ERR_JOB = 'evlive_job_invalid';
const ORANGE_CPR_EVLIVE_ERR_ENV = 'evlive_environment_invalid';
const ORANGE_CPR_EVLIVE_ERR_EV10 = 'evlive_ev10_minimum_fail';
const ORANGE_CPR_EVLIVE_ERR_CROSS_COUNTRY = 'evlive_cross_country_forbidden';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_evlive_ok(array $extra = []): array
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
        'p8_started' => false,
        'p7_integration_baseline_complete' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_evlive_fail(string $code, string $message, array $extra = []): array
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
        'p8_started' => false,
        'p7_integration_baseline_complete' => false,
    ], $extra);
}

function orange_cpr_evidence_pack_live_root(string $cprRoot, string $jobId): string
{
    return orange_cpr_evidence_pack_directory($cprRoot, $jobId);
}

function orange_cpr_evidence_pack_live_latest_path(string $cprRoot, string $jobId, string $kind): string
{
    return orange_cpr_evidence_pack_live_root($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_evidence_pack_' . $kind . '_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_evidence_pack_live_load_latest(string $cprRoot, string $jobId, string $kind): ?array
{
    $path = orange_cpr_evidence_pack_live_latest_path($cprRoot, $jobId, $kind);
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
function orange_cpr_evidence_pack_live_write_latest(string $cprRoot, string $jobId, string $kind, array $payload): void
{
    $latest = orange_cpr_evidence_pack_live_latest_path($cprRoot, $jobId, $kind);
    $dir = dirname($latest);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR evidence_pack directory.');
    }
    $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
    $sealed = orange_cpr_auth_seal($payload);
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Evidence pack latest write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $latest);
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_evidence_pack_live_refuse_unsafe(array $request): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_evidence', 'omit_evidence',
        'reorder_evidence', 'merge_evidence', 'invent_evidence',
        'owner_cert_pass', 'begin_p8', 'begin_p7_05', 'enablement_true',
        'use_production_db', 'mutate_production', 'execute_production_sql', 'mutate_uploads',
        'accept_stale', 'accept_corrupt', 'accept_modified', 'cross_country',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_BYPASS, 'Unsafe evidence pack knob forbidden: ' . $k);
        }
    }

    return orange_cpr_evlive_ok(['message' => 'unsafe knobs absent']);
}

/**
 * Load a JSON record from disk without swallowing seal failures (distinguish missing vs corrupt).
 *
 * @return array{ok:bool,code?:string,message?:string,record?:array<string,mixed>,fail_closed?:bool}
 */
function orange_cpr_evidence_pack_live_load_source_file(string $path, string $label): array
{
    if (!is_file($path)) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_SEALED, 'Missing sealed source: ' . $label);
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_CORRUPT, 'Empty/corrupt source: ' . $label);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_CORRUPT, 'Corrupt JSON source: ' . $label);
    }
    if (!orange_cpr_auth_verify_seal($data)) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_CORRUPT, 'Corrupt/unsealed source: ' . $label);
    }

    return orange_cpr_evlive_ok(['record' => $data]);
}

/**
 * @param array<string, mixed>|null $record
 * @return array<string, mixed>
 */
function orange_cpr_evidence_pack_live_require_sealed_source(?array $record, string $label): array
{
    if (!is_array($record)) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_SEALED, 'Missing sealed source: ' . $label);
    }
    if (!orange_cpr_auth_verify_seal($record)) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_CORRUPT, 'Corrupt/unsealed source: ' . $label);
    }

    return orange_cpr_evlive_ok(['record' => $record]);
}

/**
 * @param array<string, mixed> $parts
 */
function orange_cpr_evidence_pack_live_content_hash_hex(array $parts): string
{
    return hash('sha256', orange_cpr_auth_canonical_json($parts));
}

function orange_cpr_evidence_pack_live_sha256_field(string $hex): string
{
    return 'sha256:' . $hex;
}

/**
 * @param list<array{artifact_id:string,content_hash:string}> $ordered
 */
function orange_cpr_evidence_pack_live_pack_seal_hash(string $packageCycleId, string $manifestHash, array $ordered): string
{
    $parts = ['v1', $packageCycleId, $manifestHash];
    foreach ($ordered as $row) {
        $parts[] = (string) $row['artifact_id'] . ':' . (string) $row['content_hash'];
    }
    $canonical = implode('|', $parts);

    return orange_cpr_evidence_pack_live_sha256_field(hash('sha256', $canonical));
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_evidence_pack_live_assert_preconditions(array $env, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_evlive_fail(
            ORANGE_CPR_EVLIVE_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during P7 evidence pack assembly.'
        );
    }
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_ACTOR, 'Super Admin required for evidence pack assembly.');
    }
    $unsafe = orange_cpr_evidence_pack_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $jobId = trim((string) ($request['job_id'] ?? ''));
    if ($jobId === '') {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_JOB, 'job_id required.');
    }
    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_JOB, $e->getMessage());
    }
    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_CONTRACT, 'Frozen execution contract required.');
    }

    $harness = orange_cpr_drill_harness_live_load_latest($cprRoot, $jobId, 'harness');
    $binding = orange_cpr_drill_harness_live_load_latest($cprRoot, $jobId, 'binding');
    $hCheck = orange_cpr_evidence_pack_live_require_sealed_source($harness, 'harness');
    if (empty($hCheck['ok'])) {
        return $hCheck;
    }
    $bCheck = orange_cpr_evidence_pack_live_require_sealed_source($binding, 'binding');
    if (empty($bCheck['ok'])) {
        return $bCheck;
    }
    if (empty($harness['harness_bound']) || empty($binding['binding_complete'])) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_HARNESS, 'Clone drill harness binding incomplete.');
    }
    if (!in_array((string) ($binding['drill_context'] ?? ''), orange_cpr_p7_allowed_drill_contexts(), true)) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_ENV, 'Harness drill_context invalid.');
    }

    $aggregate = orange_cpr_drill_execution_live_load_latest($cprRoot, $jobId, 'aggregate');
    $aCheck = orange_cpr_evidence_pack_live_require_sealed_source($aggregate, 'drill_aggregate');
    if (empty($aCheck['ok'])) {
        return empty($aggregate)
            ? orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_DRILL, 'Sealed drill aggregate required before evidence pack.')
            : $aCheck;
    }
    if (empty($aggregate['execution_complete']) || ($aggregate['suite_result'] ?? '') !== 'PASS') {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_DRILL, 'Drill suite must be complete PASS before evidence pack.');
    }

    $scenarioReports = [];
    foreach (orange_cpr_drill_catalog_ids() as $sid) {
        $spath = orange_cpr_drill_execution_live_latest_path($cprRoot, $jobId, 'scenario_' . $sid);
        $rCheck = orange_cpr_evidence_pack_live_load_source_file($spath, 'scenario_' . $sid);
        if (empty($rCheck['ok'])) {
            return $rCheck;
        }
        /** @var array<string, mixed> $rep */
        $rep = $rCheck['record'];
        if (($rep['result'] ?? '') !== 'PASS') {
            return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_DRILL, 'Scenario not PASS: ' . $sid);
        }
        $fp = (string) ($rep['scenario_fingerprint'] ?? '');
        $aggFp = (string) (($aggregate['scenario_fingerprints'][$sid] ?? ''));
        if ($fp === '' || $aggFp === '' || !hash_equals($fp, $aggFp)) {
            return orange_cpr_evlive_fail(
                ORANGE_CPR_EVLIVE_ERR_FINGERPRINT,
                'Scenario fingerprint mismatch vs aggregate: ' . $sid
            );
        }
        // Detect modified payload (seal still present but content drift vs fingerprint inputs)
        $recomputed = orange_cpr_drill_execution_live_scenario_fingerprint([
            'scenario_id' => $sid,
            'catalog_version' => ORANGE_CPR_DRILL_CATALOG_VERSION,
            'catalog_order' => (int) ($rep['catalog_order'] ?? 0),
            'job_id' => $jobId,
            'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
            'country_id' => (int) ($job['country_id'] ?? 0),
            'country_code' => (string) ($job['country_code'] ?? ''),
            'schema_revision_expected' => (int) ($contract['schema_revision_expected'] ?? 0),
            'binding_id' => (string) ($binding['binding_id'] ?? ''),
            'clone_environment_id' => (string) ($binding['clone_environment_id'] ?? ''),
            'drill_context' => (string) ($binding['drill_context'] ?? ''),
            'expected_outcome' => (string) ($rep['expected_outcome'] ?? ''),
            'sequence' => (int) ($rep['execution_sequence'] ?? 0),
        ]);
        if (!hash_equals($fp, $recomputed)) {
            return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_MODIFIED, 'Modified scenario evidence detected: ' . $sid);
        }
        $scenarioReports[$sid] = $rep;
    }

    $reqCountryId = (int) ($request['country_id'] ?? $job['country_id'] ?? 0);
    $reqCountryCode = strtoupper(trim((string) ($request['country_code'] ?? $job['country_code'] ?? '')));
    if ($reqCountryId !== (int) ($job['country_id'] ?? 0)
        || $reqCountryCode !== strtoupper(trim((string) ($job['country_code'] ?? '')))
        || $reqCountryId !== (int) ($binding['country_id'] ?? 0)
        || $reqCountryId !== (int) ($aggregate['country_id'] ?? 0)
    ) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_COUNTRY, 'Country binding mismatch (cross-country evidence forbidden).');
    }
    if (!empty($request['cross_country_country_id'])
        && (int) $request['cross_country_country_id'] !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_CROSS_COUNTRY, 'Cross-country evidence assembly forbidden.');
    }

    $fpJob = (string) ($job['package_fingerprint'] ?? '');
    $fpReq = trim((string) ($request['package_fingerprint'] ?? $fpJob));
    if ($fpJob === ''
        || $fpJob !== (string) ($contract['package_fingerprint'] ?? '')
        || $fpJob !== (string) ($binding['package_fingerprint'] ?? '')
        || $fpJob !== (string) ($aggregate['package_fingerprint'] ?? '')
        || $fpReq !== $fpJob
    ) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_FINGERPRINT, 'Package fingerprint mismatch across job/contract/binding/aggregate/request.');
    }

    $schemaJob = (int) ($contract['schema_revision_expected'] ?? 0);
    $schemaReq = (int) ($request['schema_revision'] ?? $schemaJob);
    if ($schemaJob < 1
        || $schemaReq !== $schemaJob
        || $schemaJob !== (int) ($binding['schema_revision_expected'] ?? 0)
        || $schemaJob !== (int) ($aggregate['schema_revision_expected'] ?? 0)
    ) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_CONTRACT, 'Schema revision / contract identity mismatch.');
    }
    if (empty($contract['contract_frozen']) || ($contract['c8_overall_result'] ?? '') !== 'SAFE') {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_CONTRACT, 'Contract must be frozen with C8 SAFE for EV-03.');
    }

    $classes = $request['evidence_classes'] ?? null;
    if ($classes === null) {
        $classes = orange_cpr_evidence_catalog_ids();
    }
    if (!is_array($classes)) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_ORDER, 'evidence_classes must be a list or omitted.');
    }
    /** @var list<string> $classes */
    $classes = array_values(array_map(static fn ($v) => (string) $v, $classes));
    $orderCheck = orange_cpr_evidence_catalog_assert_order($classes);
    if (empty($orderCheck['ok'])) {
        $catalogCode = (string) ($orderCheck['code'] ?? '');
        $detail = $orderCheck;
        unset($detail['ok'], $detail['code'], $detail['message'], $detail['fail_closed']);
        if ($catalogCode === 'evidence_item_missing') {
            return orange_cpr_evlive_fail(
                ORANGE_CPR_EVLIVE_ERR_MISSING,
                (string) ($orderCheck['message'] ?? 'missing'),
                ['catalog_code' => $catalogCode, 'detail' => $detail]
            );
        }

        return orange_cpr_evlive_fail(
            ORANGE_CPR_EVLIVE_ERR_ORDER,
            (string) ($orderCheck['message'] ?? 'order invalid'),
            ['catalog_code' => $catalogCode, 'detail' => $detail]
        );
    }

    $ev10 = orange_cpr_evidence_ev10_minimum_satisfied(array_keys($scenarioReports));
    if (empty($ev10['ok'])) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_EV10, 'EV-10 rollback-minimum scenario set not satisfied.', $ev10);
    }

    $existing = orange_cpr_evidence_pack_live_load_latest($cprRoot, $jobId, 'pack');
    if (is_array($existing) && !empty($existing['sealed'])) {
        if (!empty($request['force_replay'])) {
            return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_REPLAY, 'Replay of sealed evidence pack forbidden.');
        }

        return orange_cpr_evlive_ok([
            'message' => 'Evidence pack already sealed (idempotent).',
            'idempotent' => true,
            'already_complete' => true,
            'job' => $job,
            'contract' => $contract,
            'job_id' => $jobId,
            'cpr_root' => $cprRoot,
            'harness' => $harness,
            'binding' => $binding,
            'aggregate' => $aggregate,
            'scenario_reports' => $scenarioReports,
            'evidence_classes' => $classes,
        ]);
    }
    if (!empty($request['force_replay'])) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_REPLAY, 'force_replay refused when pack incomplete.');
    }

    // Optional stale injection for tests
    if (!empty($request['inject_stale_source'])) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_SEALED, 'Stale evidence source rejected.');
    }

    return orange_cpr_evlive_ok([
        'job' => $job,
        'contract' => $contract,
        'job_id' => $jobId,
        'cpr_root' => $cprRoot,
        'harness' => $harness,
        'binding' => $binding,
        'aggregate' => $aggregate,
        'scenario_reports' => $scenarioReports,
        'evidence_classes' => $classes,
        'ev10' => $ev10,
        'already_complete' => false,
    ]);
}

/**
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $def
 * @return array{payload:array<string,mixed>,descriptor:array<string,mixed>,relative_path:string,bytes:string}
 */
function orange_cpr_evidence_pack_live_build_artifact(
    array $ctx,
    array $def,
    string $packageCycleId,
    string $evidencePackId,
    int $actorAdminId
): array {
    $class = (string) $def['evidence_class'];
    $job = $ctx['job'];
    $contract = $ctx['contract'];
    $binding = $ctx['binding'];
    $aggregate = $ctx['aggregate'];
    /** @var array<string, array<string, mixed>> $scenarioReports */
    $scenarioReports = $ctx['scenario_reports'];
    $jobId = (string) $ctx['job_id'];
    $now = gmdate('c');
    $artifactId = orange_cpr_generate_job_id();
    $seq = 1;
    $seqPad = str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    $relativePath = 'artifacts/' . $class . '/' . $seqPad . '_' . strtolower(str_replace('-', '_', $class)) . '.json';

    $scenarioRefs = [];
    foreach ($def['scenario_refs'] as $sid) {
        if (isset($scenarioReports[$sid])) {
            $scenarioRefs[] = $sid;
        }
    }
    if ($class === 'EV-10') {
        $scenarioRefs = array_values(array_unique(array_merge(
            $scenarioRefs,
            ['DS-F01', 'DS-F02', 'DS-R01', 'DS-R05', 'DS-B01', 'DS-B03', 'DS-M03', 'DS-P02', 'DS-P03']
        )));
    }
    if ($class === 'EV-11') {
        $scenarioRefs = orange_cpr_drill_catalog_ids();
    }

    $sourceFingerprints = [
        'binding_id' => (string) ($binding['binding_id'] ?? ''),
        'harness_report_id' => (string) ($ctx['harness']['harness_report_id'] ?? ''),
        'aggregate_id' => (string) ($aggregate['aggregate_id'] ?? ''),
        'scenario_fingerprints' => $aggregate['scenario_fingerprints'] ?? [],
    ];

    $payload = [
        'schema_version' => 'cpr_evidence_artifact_payload/1',
        'evidence_class' => $class,
        'title' => (string) $def['title'],
        'artifact_id' => $artifactId,
        'package_cycle_id' => $packageCycleId,
        'evidence_pack_id' => $evidencePackId,
        'job_id' => $jobId,
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'schema_revision_bound' => (int) ($contract['schema_revision_expected'] ?? 0),
        'drill_context' => (string) ($binding['drill_context'] ?? ''),
        'clone_environment_id' => (string) ($binding['clone_environment_id'] ?? ''),
        'source_sealed_only' => true,
        'source_fingerprints' => $sourceFingerprints,
        'scenario_refs' => $scenarioRefs,
        'od_refs' => $def['od_refs'],
        'p1_artifact_refs' => $def['p1_artifact_refs'],
        'enablement_flag_observed' => false,
        'auto_rollback_executed' => false,
        'owner_cert_pass_granted' => false,
        'c8_overall_result' => (string) ($contract['c8_overall_result'] ?? ''),
        'c8_safe_evidence' => ($contract['c8_overall_result'] ?? '') === 'SAFE',
        'inventory_snapshot_hash' => (string) ($contract['inventory_snapshot_hash'] ?? ''),
        'job_state_observed' => (string) ($job['state'] ?? ''),
        'checkpoints_committed_observed' => orange_cpr_checkpoint_list_committed((string) $ctx['cpr_root'], $jobId),
        'cert_result' => $class === 'EV-14' ? 'PENDING' : null,
        'produced_at' => $now,
        'secrets_present' => false,
        'production_resources_accessed' => false,
    ];

    $bytes = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $hex = hash('sha256', $bytes);
    $descriptor = [
        'schema_version' => 'cpr_evidence_artifact_descriptor/1',
        'artifact_id' => $artifactId,
        'evidence_class' => $class,
        'artifact_seq' => $seq,
        'logical_key' => $packageCycleId . ':' . $class . ':' . $seqPad,
        'relative_path' => $relativePath,
        'media_type' => 'application/json',
        'byte_length' => strlen($bytes),
        'content_hash' => orange_cpr_evidence_pack_live_sha256_field($hex),
        'evidence_fingerprint' => $hex,
        'produced_by' => 'engineering',
        'producer_actor_id' => $actorAdminId,
        'produced_at' => $now,
        'drill_context' => !empty($def['drill_derived']) ? (string) ($binding['drill_context'] ?? '') : null,
        'enablement_flag_observed' => false,
        'od_refs' => $def['od_refs'],
        'p1_artifact_refs' => $def['p1_artifact_refs'],
        'scenario_refs' => $scenarioRefs,
        'checklist_refs' => [],
        'execution_contract_refs' => [
            [
                'job_id' => $jobId,
                'contract_revision' => $contract['contract_revision'] ?? null,
            ],
        ],
        'c8_safe_evidence' => $class === 'EV-03' ? true : null,
        'immutable_after_pack_seal' => true,
        'secrets_present' => false,
    ];

    return [
        'payload' => $payload,
        'descriptor' => $descriptor,
        'relative_path' => $relativePath,
        'bytes' => $bytes,
    ];
}

/**
 * Assemble and seal EV-01…EV-14 evidence pack from sealed drill artifacts.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_evidence_pack_live_run(array $env, array $request = []): array
{
    $pre = orange_cpr_evidence_pack_live_assert_preconditions($env, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }
    if (!empty($pre['already_complete']) && !empty($pre['idempotent'])) {
        $jid = (string) ($pre['job_id'] ?? '');
        $cprRoot = (string) ($pre['cpr_root'] ?? '');

        return orange_cpr_evlive_ok([
            'message' => 'Evidence pack already sealed (idempotent; exactly once).',
            'job_id' => $jid,
            'idempotent' => true,
            'exactly_once' => true,
            'pack_report' => orange_cpr_evidence_pack_live_load_latest($cprRoot, $jid, 'pack'),
            'manifest' => orange_cpr_evidence_pack_live_load_latest($cprRoot, $jid, 'manifest'),
            'seal' => orange_cpr_evidence_pack_live_load_latest($cprRoot, $jid, 'seal'),
            'production_resources_accessed' => false,
        ]);
    }

    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    /** @var array<string, mixed> $binding */
    $binding = $pre['binding'];
    /** @var array<string, mixed> $harness */
    $harness = $pre['harness'];
    /** @var array<string, mixed> $aggregate */
    $aggregate = $pre['aggregate'];
    $jobId = (string) $pre['job_id'];
    $cprRoot = (string) $pre['cpr_root'];
    /** @var list<string> $classes */
    $classes = $pre['evidence_classes'];
    $actorAdminId = (int) $request['actor_admin_id'];
    $now = gmdate('c');
    $packageCycleId = orange_cpr_generate_job_id();
    $evidencePackId = orange_cpr_generate_job_id();
    $manifestId = orange_cpr_generate_job_id();
    $sealId = orange_cpr_generate_job_id();

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.evidence_pack_live_start',
        'job_id' => $jobId,
        'evidence_pack_id' => $evidencePackId,
        'package_cycle_id' => $packageCycleId,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'actor_admin_id' => $actorAdminId,
    ]);

    $packRoot = orange_cpr_evidence_pack_live_root($cprRoot, $jobId);
    if (!is_dir($packRoot) && !@mkdir($packRoot, 0775, true) && !is_dir($packRoot)) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_PERSIST, 'Cannot create evidence_pack root.');
    }

    $ctx = [
        'job' => $job,
        'contract' => $contract,
        'binding' => $binding,
        'harness' => $harness,
        'aggregate' => $aggregate,
        'scenario_reports' => $pre['scenario_reports'],
        'job_id' => $jobId,
        'cpr_root' => $cprRoot,
    ];

    $descriptors = [];
    $orderedHashes = [];
    $evidenceFingerprints = [];
    $built = [];

    try {
        foreach ($classes as $class) {
            $def = orange_cpr_evidence_catalog_definition($class);
            if ($def === null) {
                return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_MISSING, 'Evidence class missing mid-run: ' . $class);
            }
            $art = orange_cpr_evidence_pack_live_build_artifact($ctx, $def, $packageCycleId, $evidencePackId, $actorAdminId);
            $abs = $packRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $art['relative_path']);
            $dir = dirname($abs);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create artifact directory for ' . $class);
            }
            if (is_file($abs)) {
                throw new RuntimeException('Artifact already exists: ' . $art['relative_path']);
            }
            $tmp = $abs . '.tmp.' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $art['bytes']) === false) {
                throw new RuntimeException('Artifact write failed: ' . $class);
            }
            orange_cpr_atomic_rename_replace($tmp, $abs);
            // Recompute hash from disk (reject corruption)
            $onDisk = file_get_contents($abs);
            if ($onDisk === false || hash('sha256', $onDisk) !== (string) $art['descriptor']['evidence_fingerprint']) {
                throw new RuntimeException('Artifact hash mismatch after write: ' . $class);
            }
            $descriptors[] = $art['descriptor'];
            $orderedHashes[] = [
                'artifact_id' => (string) $art['descriptor']['artifact_id'],
                'content_hash' => (string) $art['descriptor']['content_hash'],
            ];
            $evidenceFingerprints[$class] = (string) $art['descriptor']['evidence_fingerprint'];
            $built[$class] = $art;
        }
    } catch (RuntimeException $e) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_PERSIST, $e->getMessage());
    }

    $packagingOrder = array_map(static fn (array $d) => (string) $d['artifact_id'], $descriptors);

    $drillsIndex = [
        'schema_version' => 'cpr_evidence_drills_index/1',
        'package_cycle_id' => $packageCycleId,
        'scenario_count' => count($pre['scenario_reports']),
        'scenarios' => [],
    ];
    foreach ($pre['scenario_reports'] as $sid => $rep) {
        $drillsIndex['scenarios'][] = [
            'scenario_id' => $sid,
            'result' => (string) ($rep['result'] ?? ''),
            'scenario_fingerprint' => (string) ($rep['scenario_fingerprint'] ?? ''),
            'catalog_order' => (int) ($rep['catalog_order'] ?? 0),
        ];
    }

    $traceability = [
        'schema_version' => 'cpr_evidence_traceability/1',
        'package_cycle_id' => $packageCycleId,
        'evidence_pack_id' => $evidencePackId,
        'job_id' => $jobId,
        'binding_id' => (string) ($binding['binding_id'] ?? ''),
        'aggregate_id' => (string) ($aggregate['aggregate_id'] ?? ''),
        'edges' => [
            'harness_to_pack' => true,
            'drill_to_pack' => true,
            'contract_to_pack' => true,
            'country_to_pack' => true,
            'schema_to_pack' => true,
        ],
    ];

    $checklist = [
        'schema_version' => 'cpr_evidence_checklist_eval/1',
        'package_cycle_id' => $packageCycleId,
        'evidence_ready' => true,
        'owner_gates_pending' => true,
        'cg_s_m_engineering_pass' => true,
        'cg_h_f_not_pass_by_engineering' => true,
    ];

    $validation = [
        'schema_version' => 'cpr_evidence_validation_report/1',
        'package_cycle_id' => $packageCycleId,
        'all_rules_pass' => true,
        'ready_for_owner_review' => true,
        'ev10_minimum_set_satisfied' => !empty($pre['ev10']['ev10_minimum_set_satisfied']),
        'enablement_flag_bound' => false,
        'owner_pass_granted_by_engineering' => false,
        'failed_rules' => [],
    ];

    $manifest = [
        'schema_version' => ORANGE_CPR_EVPACK_MANIFEST_SCHEMA,
        'manifest_id' => $manifestId,
        'evidence_pack_id' => $evidencePackId,
        'package_cycle_id' => $packageCycleId,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'catalog_version' => ORANGE_CPR_EVIDENCE_CATALOG_VERSION,
        'pack_state' => 'sealed',
        'created_by_engineering_id' => $actorAdminId,
        'created_at' => $now,
        'schema_revision_bound' => (int) ($contract['schema_revision_expected'] ?? 0),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'drill_context' => (string) ($binding['drill_context'] ?? ''),
        'clone_environment_id' => (string) ($binding['clone_environment_id'] ?? ''),
        'enablement_flag_bound' => false,
        'waiver_present' => false,
        'owner_pass_granted_by_engineering' => false,
        'artifacts' => $descriptors,
        'packaging_order' => $packagingOrder,
        'evidence_classes' => $classes,
        'evidence_fingerprints' => $evidenceFingerprints,
        'execution_contract_binding' => [
            'schema_version' => 'cpr_evidence_exec_contract_binding/1',
            'bound_jobs' => [[
                'job_id' => $jobId,
                'contract_revision' => $contract['contract_revision'] ?? null,
                'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
                'country_id' => (int) ($job['country_id'] ?? 0),
            ]],
            'fingerprint_fields_cited' => [
                'package_fingerprint',
                'c4_report_hash',
                'c5_report_hash',
                'c6_report_hash',
                'c7_report_hash',
                'c8_report_hash',
                'inventory_snapshot_hash',
            ],
            'drift_detected' => false,
            'p1_ref' => 'CPR-P1-WP02-EXECUTION_CONTRACT',
        ],
        'sealed' => true,
        'production_sql_executed' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
    ];

    $manifestForHash = $manifest;
    unset($manifestForHash['seal_ref']);
    $manifestHash = orange_cpr_evidence_pack_live_sha256_field(
        hash('sha256', orange_cpr_auth_canonical_json($manifestForHash))
    );
    $packSealHash = orange_cpr_evidence_pack_live_pack_seal_hash($packageCycleId, $manifestHash, $orderedHashes);

    $seal = [
        'schema_version' => ORANGE_CPR_EVPACK_SEAL_SCHEMA,
        'seal_id' => $sealId,
        'evidence_pack_id' => $evidencePackId,
        'package_cycle_id' => $packageCycleId,
        'sealed_at' => $now,
        'sealed_by_engineering_id' => $actorAdminId,
        'manifest_id' => $manifestId,
        'manifest_hash' => $manifestHash,
        'ordered_artifact_hashes' => $orderedHashes,
        'pack_seal_hash' => $packSealHash,
        'post_seal_mutation_allowed' => false,
        'enablement_flag_bound' => false,
        'owner_cert_pass_granted' => false,
        'production_resources_accessed' => false,
    ];
    $manifest['seal_ref'] = $sealId;

    $recovery = [
        'job_id' => $jobId,
        'job_state' => (string) ($job['state'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'schema_revision_expected' => (int) ($contract['schema_revision_expected'] ?? 0),
        'clone_environment_id' => (string) ($binding['clone_environment_id'] ?? ''),
        'drill_context' => (string) ($binding['drill_context'] ?? ''),
        'evidence_pack_id' => $evidencePackId,
        'package_cycle_id' => $packageCycleId,
        'manifest_id' => $manifestId,
        'seal_id' => $sealId,
        'pack_seal_hash' => $packSealHash,
        'completed_phase' => 'p7_evidence_pack_sealed',
        'execution_status' => 'evidence_sealed',
        'evidence_classes_count' => count($classes),
        'evidence_fingerprints' => $evidenceFingerprints,
        'ev10_minimum_set_satisfied' => true,
        'owner_cert_pending' => true,
        'p8_not_started' => true,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
    ];

    $packReport = [
        'schema_version' => ORANGE_CPR_EVPACK_SCHEMA,
        'engine_version' => ORANGE_CPR_EVPACK_VERSION,
        'record_type' => 'evidence_pack_report',
        'evidence_pack_id' => $evidencePackId,
        'package_cycle_id' => $packageCycleId,
        'manifest_id' => $manifestId,
        'seal_id' => $sealId,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'sealed' => true,
        'exactly_once' => true,
        'pack_seal_hash' => $packSealHash,
        'manifest_hash' => $manifestHash,
        'evidence_classes' => $classes,
        'evidence_fingerprints' => $evidenceFingerprints,
        'artifact_count' => count($descriptors),
        'packaging_order' => $packagingOrder,
        'drill_context' => (string) ($binding['drill_context'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'schema_revision_bound' => (int) ($contract['schema_revision_expected'] ?? 0),
        'ready_for_owner_review' => true,
        'owner_cert_pass_granted' => false,
        'p8_started' => false,
        'actor_admin_id' => $actorAdminId,
        'ops_enablement_flag' => false,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'recovery_metadata' => $recovery,
        'created_at' => $now,
        'state_engine_integrated' => true,
        'checkpoint_engine_integrated' => true,
        'harness_integrated' => true,
        'drill_execution_integrated' => true,
    ];

    try {
        $writeJson = static function (string $path, array $data): void {
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create path: ' . $dir);
            }
            $sealed = orange_cpr_auth_seal($data);
            $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
            $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false || @file_put_contents($tmp, $json) === false) {
                throw new RuntimeException('Write failed: ' . $path);
            }
            orange_cpr_atomic_rename_replace($tmp, $path);
        };

        $writeJson($packRoot . DIRECTORY_SEPARATOR . 'traceability.json', $traceability);
        $writeJson($packRoot . DIRECTORY_SEPARATOR . 'drills' . DIRECTORY_SEPARATOR . 'index.json', $drillsIndex);
        $writeJson($packRoot . DIRECTORY_SEPARATOR . 'checklist' . DIRECTORY_SEPARATOR . 'evaluation.json', $checklist);
        $writeJson($packRoot . DIRECTORY_SEPARATOR . 'validation_report.json', $validation);
        $writeJson($packRoot . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
        $writeJson($packRoot . DIRECTORY_SEPARATOR . 'seal.json', $seal);

        orange_cpr_evidence_pack_live_write_latest($cprRoot, $jobId, 'manifest', $manifest);
        orange_cpr_evidence_pack_live_write_latest($cprRoot, $jobId, 'seal', $seal);
        orange_cpr_evidence_pack_live_write_latest($cprRoot, $jobId, 'pack', $packReport);
    } catch (RuntimeException $e) {
        return orange_cpr_evlive_fail(ORANGE_CPR_EVLIVE_ERR_PERSIST, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.evidence_pack_live_complete',
        'job_id' => $jobId,
        'evidence_pack_id' => $evidencePackId,
        'package_cycle_id' => $packageCycleId,
        'manifest_id' => $manifestId,
        'seal_id' => $sealId,
        'pack_seal_hash' => $packSealHash,
        'artifact_count' => count($descriptors),
        'sealed' => true,
        'production_sql_executed' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'owner_cert_pass_granted' => false,
        'actor_admin_id' => $actorAdminId,
    ]);

    return orange_cpr_evlive_ok([
        'message' => 'Evidence pack EV-01…EV-14 assembled and sealed from sealed drill artifacts.',
        'job_id' => $jobId,
        'evidence_pack_id' => $evidencePackId,
        'package_cycle_id' => $packageCycleId,
        'manifest_id' => $manifestId,
        'seal_id' => $sealId,
        'pack_seal_hash' => $packSealHash,
        'manifest_hash' => $manifestHash,
        'evidence_fingerprints' => $evidenceFingerprints,
        'artifact_count' => count($descriptors),
        'pack_report' => orange_cpr_evidence_pack_live_load_latest($cprRoot, $jobId, 'pack'),
        'manifest' => orange_cpr_evidence_pack_live_load_latest($cprRoot, $jobId, 'manifest'),
        'seal' => orange_cpr_evidence_pack_live_load_latest($cprRoot, $jobId, 'seal'),
        'recovery_metadata' => $recovery,
        'exactly_once' => true,
        'sealed' => true,
        'ready_for_owner_review' => true,
        'owner_cert_pass_granted' => false,
        'production_resources_accessed' => false,
    ]);
}
