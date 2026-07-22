<?php

declare(strict_types=1);

/**
 * CPR Live Clone Drill Harness & Environment Binding (WP-P7-02).
 *
 * Binds a CPR job to an approved clone / non-production drill environment.
 * Does NOT execute DS-* scenarios (WP-P7-03) and never accesses production resources.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P7_02_DRILL_HARNESS.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P2_03_DRILL_SCENARIOS.md §3
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P7_ARTIFACT_INDEX.md
 */

require_once __DIR__ . '/cpr_job_framework.php';
require_once __DIR__ . '/cpr_authority_engine.php';
require_once __DIR__ . '/cpr_p7_control_plane.php';

const ORANGE_CPR_DRILL_HARNESS_SCHEMA = 'cpr_drill_harness_live/1';
const ORANGE_CPR_DRILL_HARNESS_VERSION = 'P7-02-1.0';
const ORANGE_CPR_DRILL_ENV_BIND_SCHEMA = 'cpr_drill_environment_binding/1';

const ORANGE_CPR_DHLIVE_ERR_ENABLEMENT = 'dhlive_enablement_forbidden';
const ORANGE_CPR_DHLIVE_ERR_ACTOR = 'dhlive_actor_not_super_admin';
const ORANGE_CPR_DHLIVE_ERR_ENV = 'dhlive_environment_invalid';
const ORANGE_CPR_DHLIVE_ERR_PRODUCTION = 'dhlive_production_endpoint_forbidden';
const ORANGE_CPR_DHLIVE_ERR_CONTEXT = 'dhlive_drill_context_invalid';
const ORANGE_CPR_DHLIVE_ERR_CONTRACT = 'dhlive_contract_mismatch';
const ORANGE_CPR_DHLIVE_ERR_COUNTRY = 'dhlive_country_mismatch';
const ORANGE_CPR_DHLIVE_ERR_SCHEMA = 'dhlive_schema_mismatch';
const ORANGE_CPR_DHLIVE_ERR_IDENTITY = 'dhlive_identity_drift';
const ORANGE_CPR_DHLIVE_ERR_REPLAY = 'dhlive_replay_forbidden';
const ORANGE_CPR_DHLIVE_ERR_BYPASS = 'dhlive_bypass_forbidden';
const ORANGE_CPR_DHLIVE_ERR_PERSIST = 'dhlive_persist_failed';
const ORANGE_CPR_DHLIVE_ERR_JOB = 'dhlive_job_invalid';
const ORANGE_CPR_DHLIVE_ERR_ISOLATION = 'dhlive_isolation_failed';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_dhlive_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'scenario_executed' => false,
        'p8_started' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_dhlive_fail(string $code, string $message, array $extra = []): array
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
        'scenario_executed' => false,
        'p8_started' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_drill_harness_live_persist(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_drill_harness_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR drill_harness directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Drill harness record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Drill harness record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_drill_harness_live_latest_path(string $cprRoot, string $jobId, string $kind): string
{
    return orange_cpr_drill_harness_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_drill_harness_' . $kind . '_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_drill_harness_live_load_latest(string $cprRoot, string $jobId, string $kind): ?array
{
    $path = orange_cpr_drill_harness_live_latest_path($cprRoot, $jobId, $kind);
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
function orange_cpr_drill_harness_live_refuse_unsafe(array $request): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_isolation', 'skip_validation',
        'use_production_db', 'use_production_uploads', 'use_production_services',
        'connect_production', 'mutate_production', 'execute_production_sql', 'mutate_uploads',
        'begin_p7_03', 'begin_scenario_execution', 'begin_p8', 'owner_cert_pass',
        'auto_rollback', 'enablement_true',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_BYPASS, 'Unsafe drill harness knob forbidden: ' . $k);
        }
    }

    return orange_cpr_dhlive_ok(['message' => 'unsafe knobs absent']);
}

/**
 * Detect production endpoint / resource markers in a clone environment definition.
 *
 * @param array<string, mixed> $cloneEnv
 * @return list<string>
 */
function orange_cpr_drill_harness_live_detect_production_markers(array $cloneEnv): array
{
    $hits = [];
    if (!empty($cloneEnv['is_production']) || !empty($cloneEnv['production']) || !empty($cloneEnv['live_production'])) {
        $hits[] = 'is_production_flag';
    }
    foreach (['production_db_dsn', 'production_db_host', 'production_uploads_root', 'production_service_url'] as $k) {
        if (!empty($cloneEnv[$k])) {
            $hits[] = $k;
        }
    }
    $endpoints = $cloneEnv['production_endpoints'] ?? null;
    if (is_array($endpoints) && $endpoints !== []) {
        $hits[] = 'production_endpoints_nonempty';
    }
    $role = strtolower(trim((string) ($cloneEnv['db_role'] ?? $cloneEnv['clone_db_role'] ?? '')));
    if (in_array($role, ['production', 'prod', 'live', 'primary_production'], true)) {
        $hits[] = 'db_role_production';
    }
    $label = strtolower(trim((string) ($cloneEnv['clone_db_label'] ?? $cloneEnv['db_label'] ?? '')));
    if ($label !== '' && preg_match('/\b(production|prod[_-]?live|live[_-]?prod)\b/', $label)) {
        $hits[] = 'db_label_production';
    }
    $host = strtolower(trim((string) ($cloneEnv['db_host'] ?? $cloneEnv['clone_db_host'] ?? '')));
    if ($host !== '' && !empty($cloneEnv['treat_host_as_production'])) {
        $hits[] = 'host_marked_production';
    }
    if (!empty($cloneEnv['allow_production_access'])) {
        $hits[] = 'allow_production_access';
    }

    return $hits;
}

/**
 * @param array<string, mixed> $cloneEnv
 * @return array<string, mixed>
 */
function orange_cpr_drill_harness_live_validate_environment(array $cloneEnv): array
{
    $context = trim((string) ($cloneEnv['drill_context'] ?? ''));
    if (!in_array($context, orange_cpr_p7_allowed_drill_contexts(), true)) {
        return orange_cpr_dhlive_fail(
            ORANGE_CPR_DHLIVE_ERR_CONTEXT,
            'drill_context must be clone|shadow_lab|non_production_fixture (P2-03 H1).'
        );
    }
    $envId = trim((string) ($cloneEnv['clone_environment_id'] ?? ''));
    if ($envId === '' || strlen($envId) < 4) {
        return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_ENV, 'clone_environment_id required.');
    }
    $cloneRoot = trim((string) ($cloneEnv['clone_work_root'] ?? $cloneEnv['clone_root'] ?? ''));
    if ($cloneRoot === '') {
        return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_ENV, 'clone_work_root required for isolation binding.');
    }
    $schema = (int) ($cloneEnv['schema_revision'] ?? $cloneEnv['schema_revision_expected'] ?? 0);
    if ($schema < 1) {
        return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_SCHEMA, 'clone environment schema_revision required.');
    }
    $countryId = (int) ($cloneEnv['country_id'] ?? 0);
    $countryCode = strtoupper(trim((string) ($cloneEnv['country_code'] ?? '')));
    if ($countryId < 1 || strlen($countryCode) < 2) {
        return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_COUNTRY, 'clone environment country_id/country_code required.');
    }
    $fp = trim((string) ($cloneEnv['package_fingerprint'] ?? ''));
    if (strlen($fp) < 16) {
        return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_IDENTITY, 'clone environment package_fingerprint required.');
    }

    $prodHits = orange_cpr_drill_harness_live_detect_production_markers($cloneEnv);
    if ($prodHits !== []) {
        return orange_cpr_dhlive_fail(
            ORANGE_CPR_DHLIVE_ERR_PRODUCTION,
            'Production endpoint/resource markers forbidden: ' . implode(',', $prodHits),
            ['production_markers' => $prodHits]
        );
    }

    $isolation = ($cloneEnv['isolation_confirmed'] ?? false) === true
        || ($cloneEnv['environment_isolated'] ?? false) === true;
    if (!$isolation) {
        return orange_cpr_dhlive_fail(
            ORANGE_CPR_DHLIVE_ERR_ISOLATION,
            'isolation_confirmed|environment_isolated must be true.'
        );
    }
    if (($cloneEnv['production_db_access'] ?? false) === true
        || ($cloneEnv['production_uploads_access'] ?? false) === true
        || ($cloneEnv['production_services_access'] ?? false) === true
    ) {
        return orange_cpr_dhlive_fail(
            ORANGE_CPR_DHLIVE_ERR_PRODUCTION,
            'production_*_access flags must remain false.'
        );
    }

    return orange_cpr_dhlive_ok([
        'message' => 'clone environment definition valid',
        'drill_context' => $context,
        'clone_environment_id' => $envId,
        'clone_work_root' => $cloneRoot,
        'schema_revision' => $schema,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'package_fingerprint' => $fp,
    ]);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_drill_harness_live_ensure_job(array $env, array $request): array
{
    $cprRoot = orange_cpr_resolve_work_root($env);
    $jobId = trim((string) ($request['job_id'] ?? ''));
    $actor = (int) ($request['actor_admin_id'] ?? 0);

    if ($jobId !== '') {
        try {
            $job = orange_cpr_job_read($cprRoot, $jobId);
        } catch (RuntimeException $e) {
            return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_JOB, $e->getMessage());
        }
        $contract = orange_cpr_contract_read($cprRoot, $jobId);
        if (!is_array($contract) || empty($contract['contract_frozen'])) {
            return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_CONTRACT, 'Frozen execution contract required for drill harness.');
        }

        return orange_cpr_dhlive_ok([
            'job' => $job,
            'contract' => $contract,
            'job_id' => $jobId,
            'cpr_root' => $cprRoot,
            'created_job' => false,
        ]);
    }

    $cloneEnv = is_array($request['clone_environment'] ?? null) ? $request['clone_environment'] : [];
    $packageId = trim((string) ($request['package_id'] ?? $cloneEnv['package_id'] ?? 'drill-pkg'));
    $packageFingerprint = trim((string) ($request['package_fingerprint'] ?? $cloneEnv['package_fingerprint'] ?? ''));
    $countryId = (int) ($request['country_id'] ?? $cloneEnv['country_id'] ?? 0);
    $countryCode = strtoupper(trim((string) ($request['country_code'] ?? $cloneEnv['country_code'] ?? '')));
    $workflow = strtoupper(trim((string) ($request['workflow'] ?? 'B')));
    $schema = (int) ($request['schema_revision'] ?? $cloneEnv['schema_revision'] ?? $cloneEnv['schema_revision_expected'] ?? 121);

    try {
        $job = orange_cpr_job_create($env, [
            'package_id' => $packageId,
            'package_fingerprint' => $packageFingerprint,
            'country_id' => $countryId,
            'country_code' => $countryCode,
            'workflow' => $workflow,
        ], $actor);
        $jobId = (string) $job['job_id'];
        $contract = orange_cpr_contract_freeze_initial($env, $jobId, [
            'schema_revision_expected' => $schema,
            'boundary_policy_version' => 'C1.1',
            'dependency_graph_version' => '1',
            'registry_revision' => $schema,
            'c4_report_hash' => str_repeat('b', 32),
            'c5_report_hash' => str_repeat('c', 32),
            'c6_report_hash' => str_repeat('d', 32),
            'c7_report_hash' => str_repeat('e', 32),
            'c8_report_hash' => str_repeat('f', 32),
            'c8_overall_result' => 'SAFE',
            'inventory_snapshot_id' => 'drill-inv-1',
            'inventory_snapshot_hash' => str_repeat('1', 32),
            'production_db_identity_hash' => str_repeat('2', 32),
            'package_fingerprint' => $packageFingerprint,
            'country_id' => $countryId,
        ], $actor);
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_JOB, $e->getMessage());
    }

    return orange_cpr_dhlive_ok([
        'job' => $job,
        'contract' => $contract,
        'job_id' => $jobId,
        'cpr_root' => $cprRoot,
        'created_job' => true,
    ]);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_drill_harness_live_assert_preconditions(array $env, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_dhlive_fail(
            ORANGE_CPR_DHLIVE_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during P7 drill harness.'
        );
    }
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_ACTOR, 'Super Admin required for drill harness.');
    }
    $unsafe = orange_cpr_drill_harness_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }
    if (!is_array($request['clone_environment'] ?? null)) {
        return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_ENV, 'clone_environment object required.');
    }
    $envCheck = orange_cpr_drill_harness_live_validate_environment($request['clone_environment']);
    if (empty($envCheck['ok'])) {
        return $envCheck;
    }
    $jobBundle = orange_cpr_drill_harness_live_ensure_job($env, $request);
    if (empty($jobBundle['ok'])) {
        return $jobBundle;
    }

    /** @var array<string, mixed> $job */
    $job = $jobBundle['job'];
    /** @var array<string, mixed> $contract */
    $contract = $jobBundle['contract'];
    $jobId = (string) $jobBundle['job_id'];
    $cprRoot = (string) $jobBundle['cpr_root'];

    $existing = orange_cpr_drill_harness_live_load_latest($cprRoot, $jobId, 'harness');
    if (is_array($existing) && !empty($existing['harness_bound'])) {
        if (!empty($request['force_replay'])) {
            return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_REPLAY, 'Replay of completed drill harness forbidden.');
        }

        return orange_cpr_dhlive_ok([
            'message' => 'Drill harness already bound (idempotent).',
            'idempotent' => true,
            'job' => $job,
            'contract' => $contract,
            'job_id' => $jobId,
            'cpr_root' => $cprRoot,
            'env_check' => $envCheck,
            'created_job' => !empty($jobBundle['created_job']),
            'already_complete' => true,
        ]);
    }
    if (!empty($request['force_replay'])) {
        return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_REPLAY, 'force_replay refused when harness incomplete.');
    }

    $cloneEnv = $request['clone_environment'];
    if ((int) ($cloneEnv['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
        || strtoupper(trim((string) ($cloneEnv['country_code'] ?? ''))) !== strtoupper(trim((string) ($job['country_code'] ?? '')))
    ) {
        return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_COUNTRY, 'Clone environment country bind mismatch vs job.');
    }
    if ((string) ($cloneEnv['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
    ) {
        return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_IDENTITY, 'Package fingerprint mismatch across env/job/contract.');
    }
    $schemaEnv = (int) ($cloneEnv['schema_revision'] ?? $cloneEnv['schema_revision_expected'] ?? 0);
    $schemaContract = (int) ($contract['schema_revision_expected'] ?? 0);
    $schemaReq = (int) ($request['schema_revision'] ?? $schemaEnv);
    if ($schemaEnv !== $schemaContract || $schemaReq !== $schemaContract) {
        return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_SCHEMA, 'Schema revision mismatch across env/request/contract.');
    }
    if (empty($contract['contract_frozen'])) {
        return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_CONTRACT, 'Execution contract must be frozen.');
    }

    return orange_cpr_dhlive_ok([
        'job' => $job,
        'contract' => $contract,
        'job_id' => $jobId,
        'cpr_root' => $cprRoot,
        'env_check' => $envCheck,
        'created_job' => !empty($jobBundle['created_job']),
        'already_complete' => false,
    ]);
}

/**
 * Bind clone drill environment to a CPR job (exactly once; fail-closed).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_drill_harness_live_run(array $env, array $request = []): array
{
    $pre = orange_cpr_drill_harness_live_assert_preconditions($env, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }
    if (!empty($pre['already_complete']) && !empty($pre['idempotent'])) {
        $jid = (string) ($pre['job_id'] ?? '');
        $cprRoot = (string) ($pre['cpr_root'] ?? '');

        return orange_cpr_dhlive_ok([
            'message' => 'Drill harness already bound (idempotent; exactly once).',
            'job_id' => $jid,
            'idempotent' => true,
            'exactly_once' => true,
            'harness_report' => orange_cpr_drill_harness_live_load_latest($cprRoot, $jid, 'harness'),
            'environment_binding' => orange_cpr_drill_harness_live_load_latest($cprRoot, $jid, 'binding'),
            'production_resources_accessed' => false,
            'scenario_executed' => false,
        ]);
    }

    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    $jobId = (string) $pre['job_id'];
    $cprRoot = (string) $pre['cpr_root'];
    /** @var array<string, mixed> $envCheck */
    $envCheck = $pre['env_check'];
    /** @var array<string, mixed> $cloneEnv */
    $cloneEnv = $request['clone_environment'];
    $actorAdminId = (int) $request['actor_admin_id'];
    $now = gmdate('c');
    $bindingId = orange_cpr_generate_job_id();
    $harnessId = orange_cpr_generate_job_id();

    $recovery = [
        'job_id' => $jobId,
        'job_state' => (string) ($job['state'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'schema_revision_expected' => (int) ($contract['schema_revision_expected'] ?? 0),
        'clone_environment_id' => (string) ($envCheck['clone_environment_id'] ?? ''),
        'drill_context' => (string) ($envCheck['drill_context'] ?? ''),
        'clone_work_root' => (string) ($envCheck['clone_work_root'] ?? ''),
        'completed_phase' => 'p7_drill_harness_bound',
        'execution_status' => 'environment_bound',
        'scenario_execution_not_started' => true,
        'primary_rollback' => 'session_full_backup_od_pin',
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'p8_not_started' => true,
    ];

    $binding = [
        'schema_version' => ORANGE_CPR_DRILL_ENV_BIND_SCHEMA,
        'engine_version' => ORANGE_CPR_DRILL_HARNESS_VERSION,
        'record_type' => 'drill_environment_binding',
        'binding_id' => $bindingId,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'clone_environment_id' => (string) ($envCheck['clone_environment_id'] ?? ''),
        'drill_context' => (string) ($envCheck['drill_context'] ?? ''),
        'clone_work_root' => (string) ($envCheck['clone_work_root'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'schema_revision_expected' => (int) ($contract['schema_revision_expected'] ?? 0),
        'contract_frozen' => true,
        'contract_revision' => $contract['contract_revision'] ?? null,
        'isolation_confirmed' => true,
        'production_db_access' => false,
        'production_uploads_access' => false,
        'production_services_access' => false,
        'production_endpoints' => [],
        'production_markers_detected' => [],
        'environment_isolated' => true,
        'binding_complete' => true,
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'ops_enablement_flag' => false,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'recovery_metadata' => $recovery,
        'created_at' => $now,
    ];

    $harness = [
        'schema_version' => ORANGE_CPR_DRILL_HARNESS_SCHEMA,
        'engine_version' => ORANGE_CPR_DRILL_HARNESS_VERSION,
        'record_type' => 'drill_harness_report',
        'harness_report_id' => $harnessId,
        'binding_id' => $bindingId,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'harness_bound' => true,
        'exactly_once' => true,
        'drill_context' => (string) ($envCheck['drill_context'] ?? ''),
        'clone_environment_id' => (string) ($envCheck['clone_environment_id'] ?? ''),
        'scenario_executed' => false,
        'scenarios_started' => [],
        'allowed_drill_contexts' => orange_cpr_p7_allowed_drill_contexts(),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'schema_revision_expected' => (int) ($contract['schema_revision_expected'] ?? 0),
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'ops_enablement_flag' => false,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'p8_started' => false,
        'owner_cert_pass_granted' => false,
        'recovery_metadata' => $recovery,
        'created_at' => $now,
        'created_job' => !empty($pre['created_job']),
    ];

    try {
        $bindingPath = orange_cpr_drill_harness_live_persist(
            $cprRoot,
            $jobId,
            'cpr_drill_env_binding_' . $bindingId,
            $binding
        );
        $harnessPath = orange_cpr_drill_harness_live_persist(
            $cprRoot,
            $jobId,
            'cpr_drill_harness_' . $harnessId,
            $harness
        );
        foreach (
            [
                'binding' => array_merge($binding, ['binding_path' => $bindingPath]),
                'harness' => array_merge($harness, ['harness_report_path' => $harnessPath]),
            ] as $kind => $payload
        ) {
            $latest = orange_cpr_drill_harness_live_latest_path($cprRoot, $jobId, $kind);
            $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
            $sealedLatest = orange_cpr_auth_seal($payload);
            $json = json_encode($sealedLatest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false || @file_put_contents($tmp, $json) === false) {
                throw new RuntimeException('Drill harness latest pointer write failed.');
            }
            orange_cpr_atomic_rename_replace($tmp, $latest);
        }
    } catch (RuntimeException $e) {
        return orange_cpr_dhlive_fail(ORANGE_CPR_DHLIVE_ERR_PERSIST, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.drill_harness_live_bind',
        'job_id' => $jobId,
        'binding_id' => $bindingId,
        'harness_report_id' => $harnessId,
        'drill_context' => (string) ($envCheck['drill_context'] ?? ''),
        'clone_environment_id' => (string) ($envCheck['clone_environment_id'] ?? ''),
        'production_resources_accessed' => false,
        'scenario_executed' => false,
        'enablement_flag_observed' => false,
        'actor_admin_id' => $actorAdminId,
    ]);
    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.drill_harness_live_complete',
        'job_id' => $jobId,
        'binding_id' => $bindingId,
        'harness_report_id' => $harnessId,
        'harness_bound' => true,
        'production_sql_executed' => false,
        'production_resources_accessed' => false,
        'actor_admin_id' => $actorAdminId,
    ]);

    return orange_cpr_dhlive_ok([
        'message' => 'Clone drill harness bound; environment isolation sealed.',
        'job_id' => $jobId,
        'binding_id' => $bindingId,
        'harness_report_id' => $harnessId,
        'drill_context' => (string) ($envCheck['drill_context'] ?? ''),
        'clone_environment_id' => (string) ($envCheck['clone_environment_id'] ?? ''),
        'harness_report' => orange_cpr_drill_harness_live_load_latest($cprRoot, $jobId, 'harness'),
        'environment_binding' => orange_cpr_drill_harness_live_load_latest($cprRoot, $jobId, 'binding'),
        'recovery_metadata' => $recovery,
        'exactly_once' => true,
        'scenario_executed' => false,
        'production_resources_accessed' => false,
        'created_job' => !empty($pre['created_job']),
    ]);
}
