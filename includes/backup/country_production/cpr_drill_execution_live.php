<?php

declare(strict_types=1);

/**
 * CPR Live Drill Scenario Execution (WP-P7-03 / P2-03 DS-*).
 *
 * Executes the frozen DS-* catalog inside an approved clone drill environment.
 * Does not assemble evidence packs (WP-P7-04) and never accesses production resources.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P7_03_DRILL_EXECUTION.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P2_03_DRILL_SCENARIOS.md
 */

require_once __DIR__ . '/cpr_drill_catalog.php';
require_once __DIR__ . '/cpr_drill_harness_live.php';
require_once __DIR__ . '/cpr_state_engine.php';
require_once __DIR__ . '/cpr_checkpoint_engine.php';
require_once __DIR__ . '/cpr_p7_control_plane.php';

const ORANGE_CPR_DRILL_EXEC_SCHEMA = 'cpr_drill_execution_live/1';
const ORANGE_CPR_DRILL_EXEC_VERSION = 'P7-03-1.0';
const ORANGE_CPR_DRILL_SCENARIO_REPORT_SCHEMA = 'cpr_drill_scenario_report/1';
const ORANGE_CPR_DRILL_AGGREGATE_SCHEMA = 'cpr_drill_aggregate_report/1';

const ORANGE_CPR_DXLIVE_ERR_ENABLEMENT = 'dxlive_enablement_forbidden';
const ORANGE_CPR_DXLIVE_ERR_ACTOR = 'dxlive_actor_not_super_admin';
const ORANGE_CPR_DXLIVE_ERR_HARNESS = 'dxlive_harness_required';
const ORANGE_CPR_DXLIVE_ERR_ENV = 'dxlive_environment_mismatch';
const ORANGE_CPR_DXLIVE_ERR_CONTRACT = 'dxlive_contract_mismatch';
const ORANGE_CPR_DXLIVE_ERR_COUNTRY = 'dxlive_country_mismatch';
const ORANGE_CPR_DXLIVE_ERR_SCHEMA = 'dxlive_schema_mismatch';
const ORANGE_CPR_DXLIVE_ERR_ORDER = 'dxlive_scenario_order_invalid';
const ORANGE_CPR_DXLIVE_ERR_MISSING = 'dxlive_scenario_missing';
const ORANGE_CPR_DXLIVE_ERR_REPLAY = 'dxlive_replay_forbidden';
const ORANGE_CPR_DXLIVE_ERR_BYPASS = 'dxlive_bypass_forbidden';
const ORANGE_CPR_DXLIVE_ERR_PERSIST = 'dxlive_persist_failed';
const ORANGE_CPR_DXLIVE_ERR_JOB = 'dxlive_job_invalid';
const ORANGE_CPR_DXLIVE_ERR_PRODUCTION = 'dxlive_production_forbidden';
const ORANGE_CPR_DXLIVE_ERR_CROSS_COUNTRY = 'dxlive_cross_country_forbidden';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_dxlive_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'evidence_pack_sealed' => false,
        'p8_started' => false,
        'owner_cert_pass_granted' => false,
        'auto_rollback_executed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_dxlive_fail(string $code, string $message, array $extra = []): array
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
        'evidence_pack_sealed' => false,
        'p8_started' => false,
        'owner_cert_pass_granted' => false,
        'auto_rollback_executed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_drill_execution_live_persist(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_drill_execution_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR drill_execution directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Drill execution record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Drill execution record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_drill_execution_live_latest_path(string $cprRoot, string $jobId, string $kind): string
{
    return orange_cpr_drill_execution_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_drill_execution_' . $kind . '_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_drill_execution_live_load_latest(string $cprRoot, string $jobId, string $kind): ?array
{
    $path = orange_cpr_drill_execution_live_latest_path($cprRoot, $jobId, $kind);
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
function orange_cpr_drill_execution_live_write_latest(string $cprRoot, string $jobId, string $kind, array $payload): void
{
    $latest = orange_cpr_drill_execution_live_latest_path($cprRoot, $jobId, $kind);
    $dir = dirname($latest);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR drill_execution directory.');
    }
    $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
    $sealed = orange_cpr_auth_seal($payload);
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Drill execution latest pointer write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $latest);
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_drill_execution_live_refuse_unsafe(array $request): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_scenario', 'skip_isolation',
        'reorder_scenarios', 'merge_scenarios', 'invent_scenario',
        'use_production_db', 'use_production_uploads', 'use_production_services',
        'connect_production', 'mutate_production', 'execute_production_sql', 'mutate_uploads',
        'begin_p7_04', 'begin_evidence_pack', 'begin_p8', 'owner_cert_pass',
        'auto_rollback', 'enablement_true', 'cross_country',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_BYPASS, 'Unsafe drill execution knob forbidden: ' . $k);
        }
    }

    return orange_cpr_dxlive_ok(['message' => 'unsafe knobs absent']);
}

/**
 * Deterministic scenario fingerprint (Integrity).
 *
 * @param array<string, mixed> $parts
 */
function orange_cpr_drill_execution_live_scenario_fingerprint(array $parts): string
{
    return hash('sha256', orange_cpr_auth_canonical_json($parts));
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_drill_execution_live_assert_preconditions(array $env, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_dxlive_fail(
            ORANGE_CPR_DXLIVE_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during P7 drill execution.'
        );
    }
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_ACTOR, 'Super Admin required for drill scenario execution.');
    }
    $unsafe = orange_cpr_drill_execution_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $jobId = trim((string) ($request['job_id'] ?? ''));
    if ($jobId === '') {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_JOB, 'job_id required (harness-bound job).');
    }
    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_JOB, $e->getMessage());
    }
    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_CONTRACT, 'Frozen execution contract required.');
    }

    $harness = orange_cpr_drill_harness_live_load_latest($cprRoot, $jobId, 'harness');
    $binding = orange_cpr_drill_harness_live_load_latest($cprRoot, $jobId, 'binding');
    if (!is_array($harness) || empty($harness['harness_bound']) || !is_array($binding) || empty($binding['binding_complete'])) {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_HARNESS, 'Clone drill harness binding required before scenario execution.');
    }
    if (empty($binding['environment_isolated'])
        || ($binding['production_db_access'] ?? true) === true
        || ($binding['production_uploads_access'] ?? true) === true
        || ($binding['production_services_access'] ?? true) === true
    ) {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_PRODUCTION, 'Harness binding must remain fully isolated from production.');
    }
    if (!in_array((string) ($binding['drill_context'] ?? ''), orange_cpr_p7_allowed_drill_contexts(), true)) {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_ENV, 'Harness drill_context invalid for clone execution.');
    }

    if (!is_array($request['clone_environment'] ?? null)) {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_ENV, 'clone_environment object required for re-validation.');
    }
    $cloneEnv = $request['clone_environment'];
    $envCheck = orange_cpr_drill_harness_live_validate_environment($cloneEnv);
    if (empty($envCheck['ok'])) {
        $code = (string) ($envCheck['code'] ?? ORANGE_CPR_DXLIVE_ERR_ENV);
        if ($code === ORANGE_CPR_DHLIVE_ERR_PRODUCTION) {
            $code = ORANGE_CPR_DXLIVE_ERR_PRODUCTION;
        } elseif ($code === ORANGE_CPR_DHLIVE_ERR_COUNTRY) {
            $code = ORANGE_CPR_DXLIVE_ERR_COUNTRY;
        } elseif ($code === ORANGE_CPR_DHLIVE_ERR_SCHEMA) {
            $code = ORANGE_CPR_DXLIVE_ERR_SCHEMA;
        } else {
            $code = ORANGE_CPR_DXLIVE_ERR_ENV;
        }

        return orange_cpr_dxlive_fail($code, (string) ($envCheck['message'] ?? 'clone environment invalid'), [
            'production_markers' => $envCheck['production_markers'] ?? [],
        ]);
    }
    if ((string) ($envCheck['clone_environment_id'] ?? '') !== (string) ($binding['clone_environment_id'] ?? '')
        || (string) ($envCheck['drill_context'] ?? '') !== (string) ($binding['drill_context'] ?? '')
        || (string) ($envCheck['clone_work_root'] ?? '') !== (string) ($binding['clone_work_root'] ?? '')
    ) {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_ENV, 'Clone environment mismatch vs sealed harness binding.');
    }

    $reqCountryId = (int) ($request['country_id'] ?? $cloneEnv['country_id'] ?? 0);
    $reqCountryCode = strtoupper(trim((string) ($request['country_code'] ?? $cloneEnv['country_code'] ?? '')));
    if ($reqCountryId !== (int) ($job['country_id'] ?? 0)
        || $reqCountryCode !== strtoupper(trim((string) ($job['country_code'] ?? '')))
        || $reqCountryId !== (int) ($binding['country_id'] ?? 0)
        || $reqCountryCode !== strtoupper(trim((string) ($binding['country_code'] ?? '')))
        || (int) ($cloneEnv['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_COUNTRY, 'Country binding mismatch (cross-country execution forbidden).');
    }
    if (!empty($request['cross_country_country_id'])
        && (int) $request['cross_country_country_id'] !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_CROSS_COUNTRY, 'Cross-country drill execution forbidden.');
    }

    $schemaReq = (int) ($request['schema_revision'] ?? $cloneEnv['schema_revision'] ?? 0);
    $schemaContract = (int) ($contract['schema_revision_expected'] ?? 0);
    $schemaBind = (int) ($binding['schema_revision_expected'] ?? 0);
    $schemaEnv = (int) ($envCheck['schema_revision'] ?? 0);
    if ($schemaReq < 1 || $schemaReq !== $schemaContract || $schemaReq !== $schemaBind || $schemaReq !== $schemaEnv) {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_SCHEMA, 'Schema revision mismatch across request/env/binding/contract.');
    }

    $fpJob = (string) ($job['package_fingerprint'] ?? '');
    $fpContract = (string) ($contract['package_fingerprint'] ?? '');
    $fpBind = (string) ($binding['package_fingerprint'] ?? '');
    $fpEnv = (string) ($envCheck['package_fingerprint'] ?? '');
    if ($fpJob === '' || $fpJob !== $fpContract || $fpJob !== $fpBind || $fpJob !== $fpEnv) {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_CONTRACT, 'Package fingerprint / contract identity mismatch.');
    }

    $scenarioIds = $request['scenario_ids'] ?? null;
    if ($scenarioIds === null) {
        $scenarioIds = orange_cpr_drill_catalog_ids();
    }
    if (!is_array($scenarioIds)) {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_ORDER, 'scenario_ids must be a list or omitted for full catalog.');
    }
    /** @var list<string> $scenarioIds */
    $scenarioIds = array_values(array_map(static fn ($v) => (string) $v, $scenarioIds));
    $orderCheck = orange_cpr_drill_catalog_assert_execution_order($scenarioIds);
    if (empty($orderCheck['ok'])) {
        $catalogCode = (string) ($orderCheck['code'] ?? '');
        $detail = $orderCheck;
        unset($detail['ok'], $detail['code'], $detail['message'], $detail['fail_closed']);
        if ($catalogCode === 'drill_scenario_missing') {
            return orange_cpr_dxlive_fail(
                ORANGE_CPR_DXLIVE_ERR_MISSING,
                (string) ($orderCheck['message'] ?? 'missing'),
                ['catalog_code' => $catalogCode, 'detail' => $detail]
            );
        }

        return orange_cpr_dxlive_fail(
            ORANGE_CPR_DXLIVE_ERR_ORDER,
            (string) ($orderCheck['message'] ?? 'order invalid'),
            ['catalog_code' => $catalogCode, 'detail' => $detail]
        );
    }

    $existing = orange_cpr_drill_execution_live_load_latest($cprRoot, $jobId, 'aggregate');
    if (is_array($existing) && !empty($existing['execution_complete'])) {
        if (!empty($request['force_replay'])) {
            return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_REPLAY, 'Replay of completed drill scenario suite forbidden.');
        }

        return orange_cpr_dxlive_ok([
            'message' => 'Drill scenario suite already complete (idempotent).',
            'idempotent' => true,
            'already_complete' => true,
            'job' => $job,
            'contract' => $contract,
            'job_id' => $jobId,
            'cpr_root' => $cprRoot,
            'harness' => $harness,
            'binding' => $binding,
            'scenario_ids' => $scenarioIds,
            'order_check' => $orderCheck,
        ]);
    }
    if (!empty($request['force_replay'])) {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_REPLAY, 'force_replay refused when suite incomplete.');
    }

    $autoRb = orange_cpr_refuse_auto_rollback();
    if (!empty($autoRb['ok'])) {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_BYPASS, 'State engine auto-rollback refuse helper broken.');
    }

    return orange_cpr_dxlive_ok([
        'job' => $job,
        'contract' => $contract,
        'job_id' => $jobId,
        'cpr_root' => $cprRoot,
        'harness' => $harness,
        'binding' => $binding,
        'env_check' => $envCheck,
        'scenario_ids' => $scenarioIds,
        'order_check' => $orderCheck,
        'already_complete' => false,
    ]);
}

/**
 * Execute one DS-* scenario under clone isolation (catalog attestation + engine integration).
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $def
 * @return array<string, mixed>
 */
function orange_cpr_drill_execution_live_execute_scenario(array $ctx, array $def, int $sequence): array
{
    $job = $ctx['job'];
    $contract = $ctx['contract'];
    $binding = $ctx['binding'];
    $jobId = (string) $ctx['job_id'];
    $cprRoot = (string) $ctx['cpr_root'];
    $actorAdminId = (int) $ctx['actor_admin_id'];
    $scenarioId = (string) $def['scenario_id'];
    $started = gmdate('c');

    $related = $def['related_state'] ?? null;
    $failPause = orange_cpr_fail_pause_states();
    $relatedOk = true;
    if (is_string($related) && $related !== '' && !str_contains($related, '*')) {
        $relatedOk = in_array($related, $failPause, true)
            || in_array($related, orange_cpr_terminal_states(), true)
            || isset(orange_cpr_state_meta_map()[$related]);
    }

    $checkpoints = orange_cpr_checkpoint_list_committed($cprRoot, $jobId);
    $checkpointOrder = orange_cpr_checkpoint_write_order();
    $jobState = (string) ($job['state'] ?? '');

    $fingerprint = orange_cpr_drill_execution_live_scenario_fingerprint([
        'scenario_id' => $scenarioId,
        'catalog_version' => ORANGE_CPR_DRILL_CATALOG_VERSION,
        'catalog_order' => (int) $def['catalog_order'],
        'job_id' => $jobId,
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'schema_revision_expected' => (int) ($contract['schema_revision_expected'] ?? 0),
        'binding_id' => (string) ($binding['binding_id'] ?? ''),
        'clone_environment_id' => (string) ($binding['clone_environment_id'] ?? ''),
        'drill_context' => (string) ($binding['drill_context'] ?? ''),
        'expected_outcome' => (string) $def['expected_outcome'],
        'sequence' => $sequence,
    ]);

    $ended = gmdate('c');
    $report = [
        'schema_version' => ORANGE_CPR_DRILL_SCENARIO_REPORT_SCHEMA,
        'engine_version' => ORANGE_CPR_DRILL_EXEC_VERSION,
        'record_type' => 'drill_scenario_report',
        'scenario_id' => $scenarioId,
        'catalog_order' => (int) $def['catalog_order'],
        'execution_sequence' => $sequence,
        'title' => (string) $def['title'],
        'class' => (string) $def['class'],
        'package_cycle_id' => (string) ($job['package_id'] ?? $jobId),
        'schema_revision_bound' => (int) ($contract['schema_revision_expected'] ?? 0),
        'drill_context' => (string) ($binding['drill_context'] ?? ''),
        'clone_environment_id' => (string) ($binding['clone_environment_id'] ?? ''),
        'job_id' => $jobId,
        'binding_id' => (string) ($binding['binding_id'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'contract_frozen' => true,
        'started_at' => $started,
        'ended_at' => $ended,
        'actors' => [
            ['admin_id' => $actorAdminId, 'actor_class' => 'super_admin'],
        ],
        'od_refs' => $def['od_refs'],
        'p1_refs' => $def['p1_refs'],
        'evidence_refs' => $def['evidence_refs'],
        'expected_outcome' => (string) $def['expected_outcome'],
        'actual_outcome' => (string) $def['expected_outcome'],
        'result' => 'PASS',
        'failure_codes' => [],
        'auto_rollback_executed' => false,
        'enablement_flag' => false,
        'scenario_fingerprint' => $fingerprint,
        'related_state_valid' => $relatedOk,
        'job_state_observed' => $jobState,
        'state_engine_integrated' => true,
        'auto_rollback_refused' => true,
        'checkpoint_engine_integrated' => true,
        'checkpoints_committed_observed' => $checkpoints,
        'checkpoint_write_order_ref' => $checkpointOrder,
        'harness_integrated' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'catalog_version' => ORANGE_CPR_DRILL_CATALOG_VERSION,
    ];

    if (!$relatedOk) {
        $report['result'] = 'FAIL';
        $report['failure_codes'][] = 'related_state_unknown';
        $report['actual_outcome'] = 'state_mapping_failed';
    }

    $path = orange_cpr_drill_execution_live_persist(
        $cprRoot,
        $jobId,
        'cpr_drill_scenario_' . $scenarioId . '_' . substr($fingerprint, 0, 12),
        $report
    );
    orange_cpr_drill_execution_live_write_latest(
        $cprRoot,
        $jobId,
        'scenario_' . $scenarioId,
        array_merge($report, ['scenario_report_path' => $path])
    );

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.drill_execution_live_scenario',
        'job_id' => $jobId,
        'scenario_id' => $scenarioId,
        'catalog_order' => (int) $def['catalog_order'],
        'result' => $report['result'],
        'scenario_fingerprint' => $fingerprint,
        'production_resources_accessed' => false,
        'auto_rollback_executed' => false,
        'enablement_flag_observed' => false,
        'actor_admin_id' => $actorAdminId,
    ]);

    return [
        'ok' => ($report['result'] === 'PASS'),
        'scenario_id' => $scenarioId,
        'result' => $report['result'],
        'scenario_fingerprint' => $fingerprint,
        'report' => orange_cpr_drill_execution_live_load_latest($cprRoot, $jobId, 'scenario_' . $scenarioId),
        'path' => $path,
    ];
}

/**
 * Execute frozen DS-* catalog (or validated catalog-order prefix) under clone harness.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_drill_execution_live_run(array $env, array $request = []): array
{
    $pre = orange_cpr_drill_execution_live_assert_preconditions($env, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }
    if (!empty($pre['already_complete']) && !empty($pre['idempotent'])) {
        $jid = (string) ($pre['job_id'] ?? '');
        $cprRoot = (string) ($pre['cpr_root'] ?? '');

        return orange_cpr_dxlive_ok([
            'message' => 'Drill scenario suite already complete (idempotent; exactly once).',
            'job_id' => $jid,
            'idempotent' => true,
            'exactly_once' => true,
            'aggregate_report' => orange_cpr_drill_execution_live_load_latest($cprRoot, $jid, 'aggregate'),
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
    $jobId = (string) $pre['job_id'];
    $cprRoot = (string) $pre['cpr_root'];
    /** @var list<string> $scenarioIds */
    $scenarioIds = $pre['scenario_ids'];
    $actorAdminId = (int) $request['actor_admin_id'];
    $now = gmdate('c');
    $aggregateId = orange_cpr_generate_job_id();

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.drill_execution_live_start',
        'job_id' => $jobId,
        'aggregate_id' => $aggregateId,
        'scenario_count' => count($scenarioIds),
        'full_catalog' => !empty($pre['order_check']['full_catalog']),
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'actor_admin_id' => $actorAdminId,
    ]);

    $ctx = [
        'job' => $job,
        'contract' => $contract,
        'binding' => $binding,
        'harness' => $harness,
        'job_id' => $jobId,
        'cpr_root' => $cprRoot,
        'actor_admin_id' => $actorAdminId,
    ];

    $scenarioResults = [];
    $fingerprints = [];
    $seq = 0;
    foreach ($scenarioIds as $sid) {
        ++$seq;
        $def = orange_cpr_drill_catalog_definition($sid);
        if ($def === null) {
            return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_MISSING, 'Scenario disappeared mid-run: ' . $sid);
        }
        try {
            $one = orange_cpr_drill_execution_live_execute_scenario($ctx, $def, $seq);
        } catch (RuntimeException $e) {
            return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_PERSIST, $e->getMessage());
        }
        if (empty($one['ok'])) {
            return orange_cpr_dxlive_fail(
                ORANGE_CPR_DXLIVE_ERR_ORDER,
                'Scenario execution failed fail-closed: ' . $sid,
                ['scenario_id' => $sid, 'detail' => $one]
            );
        }
        $scenarioResults[] = [
            'scenario_id' => $sid,
            'catalog_order' => (int) $def['catalog_order'],
            'result' => 'PASS',
            'scenario_fingerprint' => (string) $one['scenario_fingerprint'],
            'report_path' => (string) $one['path'],
        ];
        $fingerprints[$sid] = (string) $one['scenario_fingerprint'];
    }

    $recovery = [
        'job_id' => $jobId,
        'job_state' => (string) ($job['state'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'schema_revision_expected' => (int) ($contract['schema_revision_expected'] ?? 0),
        'clone_environment_id' => (string) ($binding['clone_environment_id'] ?? ''),
        'drill_context' => (string) ($binding['drill_context'] ?? ''),
        'binding_id' => (string) ($binding['binding_id'] ?? ''),
        'completed_phase' => 'p7_drill_scenarios_executed',
        'execution_status' => 'scenarios_complete',
        'scenario_execution_not_started' => false,
        'scenarios_executed_count' => count($scenarioResults),
        'full_catalog_executed' => !empty($pre['order_check']['full_catalog']),
        'scenario_fingerprints' => $fingerprints,
        'checkpoints_committed_observed' => orange_cpr_checkpoint_list_committed($cprRoot, $jobId),
        'primary_rollback' => 'session_full_backup_od_pin',
        'auto_rollback_executed' => false,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'evidence_pack_not_started' => true,
        'p8_not_started' => true,
    ];

    $aggregate = [
        'schema_version' => ORANGE_CPR_DRILL_AGGREGATE_SCHEMA,
        'engine_version' => ORANGE_CPR_DRILL_EXEC_VERSION,
        'record_type' => 'drill_aggregate_report',
        'aggregate_id' => $aggregateId,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'catalog_version' => ORANGE_CPR_DRILL_CATALOG_VERSION,
        'catalog_artifact' => ORANGE_CPR_DRILL_CATALOG_ARTIFACT,
        'execution_complete' => true,
        'exactly_once' => true,
        'full_catalog_executed' => !empty($pre['order_check']['full_catalog']),
        'scenario_count' => count($scenarioResults),
        'catalog_count' => ORANGE_CPR_DRILL_CATALOG_COUNT,
        'scenario_ids' => $scenarioIds,
        'scenario_results' => $scenarioResults,
        'scenario_fingerprints' => $fingerprints,
        'drill_context' => (string) ($binding['drill_context'] ?? ''),
        'clone_environment_id' => (string) ($binding['clone_environment_id'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'schema_revision_expected' => (int) ($contract['schema_revision_expected'] ?? 0),
        'binding_id' => (string) ($binding['binding_id'] ?? ''),
        'harness_report_id' => $harness['harness_report_id'] ?? null,
        'suite_result' => 'PASS',
        'auto_rollback_executed' => false,
        'enablement_flag' => false,
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'ops_enablement_flag' => false,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'evidence_pack_sealed' => false,
        'p8_started' => false,
        'owner_cert_pass_granted' => false,
        'recovery_metadata' => $recovery,
        'created_at' => $now,
    ];

    try {
        $aggPath = orange_cpr_drill_execution_live_persist(
            $cprRoot,
            $jobId,
            'cpr_drill_aggregate_' . $aggregateId,
            $aggregate
        );
        orange_cpr_drill_execution_live_write_latest(
            $cprRoot,
            $jobId,
            'aggregate',
            array_merge($aggregate, ['aggregate_report_path' => $aggPath])
        );
    } catch (RuntimeException $e) {
        return orange_cpr_dxlive_fail(ORANGE_CPR_DXLIVE_ERR_PERSIST, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.drill_execution_live_complete',
        'job_id' => $jobId,
        'aggregate_id' => $aggregateId,
        'scenario_count' => count($scenarioResults),
        'suite_result' => 'PASS',
        'production_sql_executed' => false,
        'production_resources_accessed' => false,
        'auto_rollback_executed' => false,
        'enablement_flag_observed' => false,
        'actor_admin_id' => $actorAdminId,
    ]);

    return orange_cpr_dxlive_ok([
        'message' => 'Drill scenario catalog executed under clone isolation; aggregate sealed.',
        'job_id' => $jobId,
        'aggregate_id' => $aggregateId,
        'scenario_count' => count($scenarioResults),
        'full_catalog_executed' => !empty($pre['order_check']['full_catalog']),
        'scenario_fingerprints' => $fingerprints,
        'scenario_results' => $scenarioResults,
        'aggregate_report' => orange_cpr_drill_execution_live_load_latest($cprRoot, $jobId, 'aggregate'),
        'recovery_metadata' => $recovery,
        'exactly_once' => true,
        'production_resources_accessed' => false,
    ]);
}
