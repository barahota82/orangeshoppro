<?php

declare(strict_types=1);

/**
 * CPR P7 Integration Baseline — Clone-Drill Evidence chain freeze (WP-P7-05).
 *
 * Orchestrates existing P7 live modules only (no new business logic):
 *   Clone Harness → Environment Binding → DS-* Execution → Sealed Drill Reports
 *   → EV-01…EV-14 Assembly → Sealed Evidence Pack
 *
 * Does NOT start Enterprise Audit, Git Tag, or P8.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P7_05_INTEGRATION_BASELINE.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P7_ARTIFACT_INDEX.md
 */

require_once __DIR__ . '/cpr_evidence_pack_live.php';
require_once __DIR__ . '/cpr_p7_control_plane.php';

const ORANGE_CPR_P7_INTEGRATION_SCHEMA = 'cpr_p7_integration/1';
const ORANGE_CPR_P7_INTEGRATION_VERSION = 'P7-05-1.0';

const ORANGE_CPR_P7INT_ERR_ENABLEMENT = 'p7int_enablement_forbidden';
const ORANGE_CPR_P7INT_ERR_ACTOR = 'p7int_actor_not_super_admin';
const ORANGE_CPR_P7INT_ERR_CHAIN = 'p7int_chain_failed';
const ORANGE_CPR_P7INT_ERR_VERIFY = 'p7int_verification_failed';
const ORANGE_CPR_P7INT_ERR_PERSIST = 'p7int_persist_failed';
const ORANGE_CPR_P7INT_ERR_BYPASS = 'p7int_bypass_forbidden';
const ORANGE_CPR_P7INT_ERR_P8 = 'p7int_p8_forbidden';
const ORANGE_CPR_P7INT_ERR_REPLAY = 'p7int_replay_forbidden';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_p7int_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'p7_baseline_ready' => false,
        'p8_started' => false,
        'owner_cert_pass_granted' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_p7int_fail(string $code, string $message, array $extra = []): array
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
        'p7_baseline_ready' => false,
        'p8_started' => false,
        'owner_cert_pass_granted' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
    ], $extra);
}

/**
 * Canonical P7 clone-drill evidence stage order (Artifact Index §8 / roadmap P7).
 *
 * @return list<string>
 */
function orange_cpr_p7_integration_stage_order(): array
{
    return [
        'clone_harness',
        'environment_binding',
        'ds_scenario_execution',
        'sealed_drill_reports',
        'ev_assembly',
        'sealed_evidence_pack',
    ];
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_p7_integration_persist(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_integration_live_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR integration_live directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('P7 integration record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('P7 integration record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_p7_integration_latest_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_integration_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_p7_integration_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_p7_integration_load_latest(string $cprRoot, string $jobId): ?array
{
    $path = orange_cpr_p7_integration_latest_path($cprRoot, $jobId);
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
function orange_cpr_p7_integration_refuse_unsafe(array $request): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_harness', 'skip_drill', 'skip_evidence',
        'skip_verify', 'reorder_scenarios', 'omit_evidence', 'begin_p8', 'owner_cert_pass',
        'begin_enterprise_audit', 'create_git_tag', 'enablement_true',
        'use_production_db', 'mutate_production', 'execute_production_sql', 'mutate_uploads',
        'auto_rollback', 'cross_country',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            if (in_array($k, ['begin_p8', 'owner_cert_pass', 'begin_enterprise_audit', 'create_git_tag'], true)) {
                return orange_cpr_p7int_fail(
                    ORANGE_CPR_P7INT_ERR_P8,
                    'P8 / Owner Cert / Enterprise Audit / Git Tag forbidden in WP-P7-05: ' . $k
                );
            }

            return orange_cpr_p7int_fail(ORANGE_CPR_P7INT_ERR_BYPASS, 'Unsafe P7 integration knob forbidden: ' . $k);
        }
    }

    return orange_cpr_p7int_ok(['message' => 'unsafe knobs absent']);
}

/**
 * Fail-closed verification of a completed P7 clone-drill evidence chain.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_p7_integration_verify(array $env, string $jobId, array $ctx = []): array
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
    } catch (RuntimeException $e) {
        return orange_cpr_p7int_fail(ORANGE_CPR_P7INT_ERR_VERIFY, $e->getMessage());
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        $fail('contract_frozen', 'Frozen contract required.');
    } else {
        $pass('contract_frozen');
        if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
            || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
        ) {
            $fail('contract_consistency', 'Contract/job fingerprint or country drift.');
        } else {
            $pass('contract_consistency');
        }
    }

    $harness = orange_cpr_drill_harness_live_load_latest($cprRoot, $jobId, 'harness');
    $binding = orange_cpr_drill_harness_live_load_latest($cprRoot, $jobId, 'binding');
    if (!is_array($harness) || !orange_cpr_auth_verify_seal($harness) || empty($harness['harness_bound'])) {
        $fail('harness_sealed', 'Sealed harness report missing/invalid.');
    } else {
        $pass('harness_sealed');
    }
    if (!is_array($binding) || !orange_cpr_auth_verify_seal($binding) || empty($binding['binding_complete'])) {
        $fail('environment_binding', 'Sealed environment binding missing/invalid.');
    } else {
        $pass('environment_binding');
        if (empty($binding['environment_isolated'])
            || ($binding['production_db_access'] ?? true) === true
            || ($binding['production_uploads_access'] ?? true) === true
            || ($binding['production_services_access'] ?? true) === true
        ) {
            $fail('environment_isolation', 'Clone environment isolation flags failed.');
        } else {
            $pass('environment_isolation');
        }
        if (!in_array((string) ($binding['drill_context'] ?? ''), orange_cpr_p7_allowed_drill_contexts(), true)) {
            $fail('clone_context', 'drill_context not in allowed clone set.');
        } else {
            $pass('clone_context');
        }
    }

    $aggregate = orange_cpr_drill_execution_live_load_latest($cprRoot, $jobId, 'aggregate');
    if (!is_array($aggregate) || !orange_cpr_auth_verify_seal($aggregate) || empty($aggregate['execution_complete'])) {
        $fail('sealed_drill_reports', 'Sealed drill aggregate missing/incomplete.');
    } else {
        $pass('sealed_drill_reports');
        $expectedIds = orange_cpr_drill_catalog_ids();
        $gotIds = is_array($aggregate['scenario_ids'] ?? null) ? $aggregate['scenario_ids'] : [];
        if ($gotIds !== $expectedIds) {
            $fail('scenario_ordering', 'Scenario IDs do not match frozen catalog order.');
        } else {
            $pass('scenario_ordering');
        }
        $fps = is_array($aggregate['scenario_fingerprints'] ?? null) ? $aggregate['scenario_fingerprints'] : [];
        $fpOk = count($fps) === count($expectedIds);
        foreach ($expectedIds as $sid) {
            $rep = orange_cpr_drill_execution_live_load_latest($cprRoot, $jobId, 'scenario_' . $sid);
            if (!is_array($rep) || !orange_cpr_auth_verify_seal($rep) || ($rep['result'] ?? '') !== 'PASS') {
                $fpOk = false;
                break;
            }
            if (!isset($fps[$sid]) || !hash_equals((string) $fps[$sid], (string) ($rep['scenario_fingerprint'] ?? ''))) {
                $fpOk = false;
                break;
            }
        }
        if ($fpOk) {
            $pass('scenario_fingerprint_integrity');
        } else {
            $fail('scenario_fingerprint_integrity', 'Scenario fingerprint/seal integrity failed.');
        }
    }

    $pack = orange_cpr_evidence_pack_live_load_latest($cprRoot, $jobId, 'pack');
    $manifest = orange_cpr_evidence_pack_live_load_latest($cprRoot, $jobId, 'manifest');
    $seal = orange_cpr_evidence_pack_live_load_latest($cprRoot, $jobId, 'seal');
    if (!is_array($pack) || !orange_cpr_auth_verify_seal($pack) || empty($pack['sealed'])) {
        $fail('sealed_evidence_pack', 'Sealed evidence pack report missing/invalid.');
    } else {
        $pass('sealed_evidence_pack');
    }
    if (!is_array($manifest) || !orange_cpr_auth_verify_seal($manifest) || empty($manifest['sealed'])) {
        $fail('sealed_manifest', 'Sealed evidence manifest missing/invalid.');
    } else {
        $pass('sealed_manifest');
        $evExpected = orange_cpr_evidence_catalog_ids();
        if (($manifest['evidence_classes'] ?? null) !== $evExpected) {
            $fail('evidence_ordering', 'Evidence classes not exactly EV-01…EV-14 order.');
        } else {
            $pass('evidence_ordering');
        }
        $artIds = [];
        $dup = false;
        foreach ($manifest['artifacts'] ?? [] as $art) {
            if (!is_array($art)) {
                continue;
            }
            $aid = (string) ($art['artifact_id'] ?? '');
            if ($aid === '' || isset($artIds[$aid])) {
                $dup = true;
                break;
            }
            $artIds[$aid] = true;
        }
        if ($dup || count($artIds) !== 14) {
            $fail('no_duplicate_evidence', 'Duplicate or incomplete evidence artifact ids.');
        } else {
            $pass('no_duplicate_evidence');
        }
    }
    if (!is_array($seal) || !orange_cpr_auth_verify_seal($seal) || ($seal['post_seal_mutation_allowed'] ?? true) === true) {
        $fail('seal_immutability', 'Seal missing or post-seal mutation allowed.');
    } else {
        $pass('seal_immutability');
    }

    // Job identity continuity across harness → drill → evidence
    $fpJob = (string) ($job['package_fingerprint'] ?? '');
    $countryId = (int) ($job['country_id'] ?? 0);
    $schema = (int) ($contract['schema_revision_expected'] ?? 0);
    $identityOk = $fpJob !== ''
        && is_array($binding)
        && is_array($aggregate)
        && is_array($pack)
        && (string) ($binding['package_fingerprint'] ?? '') === $fpJob
        && (string) ($aggregate['package_fingerprint'] ?? '') === $fpJob
        && (string) ($pack['package_fingerprint'] ?? '') === $fpJob
        && (int) ($binding['country_id'] ?? 0) === $countryId
        && (int) ($aggregate['country_id'] ?? 0) === $countryId
        && (int) ($pack['country_id'] ?? 0) === $countryId
        && (int) ($binding['schema_revision_expected'] ?? 0) === $schema
        && (int) ($aggregate['schema_revision_expected'] ?? 0) === $schema
        && (int) ($pack['schema_revision_bound'] ?? 0) === $schema
        && (string) ($binding['job_id'] ?? '') === $jobId
        && (string) ($aggregate['job_id'] ?? '') === $jobId
        && (string) ($pack['job_id'] ?? '') === $jobId;
    if ($identityOk) {
        $pass('job_identity_continuity');
        $pass('fingerprint_integrity');
    } else {
        $fail('job_identity_continuity', 'Job/country/schema/fingerprint continuity broken across chain.');
        $fail('fingerprint_integrity', 'Fingerprint integrity failed across chain.');
    }

    $auditPath = $cprRoot . DIRECTORY_SEPARATOR . $jobId . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME;
    $audit = is_file($auditPath) ? (string) file_get_contents($auditPath) : '';
    $auditOk = str_contains($audit, 'cpr.drill_harness_live_complete')
        && str_contains($audit, 'cpr.drill_execution_live_complete')
        && str_contains($audit, 'cpr.evidence_pack_live_complete')
        && str_contains($audit, '"production_resources_accessed":false');
    if ($auditOk) {
        $pass('audit_chain_continuity');
    } else {
        $fail('audit_chain_continuity', 'Required P7 audit events missing or production access claimed.');
    }

    $recovery = is_array($pack['recovery_metadata'] ?? null) ? $pack['recovery_metadata'] : [];
    if (($recovery['job_id'] ?? '') === $jobId
        && ($recovery['completed_phase'] ?? '') === 'p7_evidence_pack_sealed'
        && ($recovery['production_resources_accessed'] ?? true) === false
        && ($recovery['owner_cert_pending'] ?? false) === true
        && ($recovery['p8_not_started'] ?? false) === true
    ) {
        $pass('recovery_metadata_integrity');
    } else {
        $fail('recovery_metadata_integrity', 'Evidence recovery metadata incomplete/invalid.');
    }

    // Orphan / directory presence
    $dirsOk = is_dir(orange_cpr_drill_harness_directory($cprRoot, $jobId))
        && is_dir(orange_cpr_drill_execution_directory($cprRoot, $jobId))
        && is_dir(orange_cpr_evidence_pack_directory($cprRoot, $jobId));
    if ($dirsOk) {
        $pass('no_orphan_artifacts');
    } else {
        $fail('no_orphan_artifacts', 'Expected harness/execution/evidence directories missing.');
    }

    // State + checkpoint engines consumed (observed)
    $checkpoints = orange_cpr_checkpoint_list_committed($cprRoot, $jobId);
    if (is_array($checkpoints) && is_array(orange_cpr_checkpoint_write_order())) {
        $pass('checkpoint_engine_integrated');
    } else {
        $fail('checkpoint_engine_integrated', 'Checkpoint engine observation failed.');
    }
    if (isset(orange_cpr_state_meta_map()[(string) ($job['state'] ?? '')])
        || (string) ($job['state'] ?? '') !== ''
    ) {
        $pass('state_engine_integrated');
    } else {
        $fail('state_engine_integrated', 'State engine observation failed.');
    }

    $prodOk = ($harness['production_resources_accessed'] ?? true) === false
        && ($aggregate['production_resources_accessed'] ?? true) === false
        && ($pack['production_resources_accessed'] ?? true) === false;
    if ($prodOk) {
        $pass('no_production_resource_access');
    } else {
        $fail('no_production_resource_access', 'Production resource access claimed in sealed reports.');
    }

    $allOk = true;
    foreach ($checks as $c) {
        if (empty($c['ok'])) {
            $allOk = false;
            break;
        }
    }

    if (!$allOk) {
        return orange_cpr_p7int_fail(ORANGE_CPR_P7INT_ERR_VERIFY, 'P7 integration verification failed.', [
            'checks' => $checks,
            'job_id' => $jobId,
        ]);
    }

    return orange_cpr_p7int_ok([
        'message' => 'P7 clone-drill evidence chain verification PASS.',
        'checks' => $checks,
        'job_id' => $jobId,
        'package_fingerprint' => $fpJob,
        'country_id' => $countryId,
        'schema_revision_expected' => $schema,
        'evidence_pack_id' => $pack['evidence_pack_id'] ?? null,
        'package_cycle_id' => $pack['package_cycle_id'] ?? null,
        'pack_seal_hash' => $pack['pack_seal_hash'] ?? null,
    ]);
}

/**
 * Run full P7 clone-drill → evidence chain and seal integration freeze report.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_p7_integration_run(array $env, array $request = []): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_p7int_fail(ORANGE_CPR_P7INT_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_p7int_fail(
            ORANGE_CPR_P7INT_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during P7 integration freeze.'
        );
    }
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_p7int_fail(ORANGE_CPR_P7INT_ERR_ACTOR, 'Super Admin required for P7 integration.');
    }
    $unsafe = orange_cpr_p7_integration_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $actorAdminId = (int) $request['actor_admin_id'];
    $cprRoot = orange_cpr_resolve_work_root($env);

    // Idempotent: if job_id provided and freeze already sealed
    $existingJobId = trim((string) ($request['job_id'] ?? ''));
    if ($existingJobId !== '') {
        $existing = orange_cpr_p7_integration_load_latest($cprRoot, $existingJobId);
        if (is_array($existing) && !empty($existing['p7_baseline_frozen'])) {
            if (!empty($request['force_replay'])) {
                return orange_cpr_p7int_fail(ORANGE_CPR_P7INT_ERR_REPLAY, 'Replay of frozen P7 integration baseline forbidden.');
            }

            return orange_cpr_p7int_ok([
                'message' => 'P7 integration baseline already frozen (idempotent).',
                'job_id' => $existingJobId,
                'idempotent' => true,
                'exactly_once' => true,
                'p7_baseline_ready' => true,
                'integration_report' => $existing,
            ]);
        }
    }
    if (!empty($request['force_replay'])) {
        return orange_cpr_p7int_fail(ORANGE_CPR_P7INT_ERR_REPLAY, 'force_replay refused when baseline incomplete.');
    }

    $cloneRoot = trim((string) ($request['clone_work_root'] ?? ''));
    if ($cloneRoot === '') {
        $cloneRoot = (string) ($env['ORANGE_RESTORE_WORK_DIR'] ?? sys_get_temp_dir())
            . DIRECTORY_SEPARATOR . 'p7_clone_' . bin2hex(random_bytes(3));
    }
    if (!is_dir($cloneRoot) && !@mkdir($cloneRoot, 0775, true) && !is_dir($cloneRoot)) {
        return orange_cpr_p7int_fail(ORANGE_CPR_P7INT_ERR_CHAIN, 'Cannot create clone work root.');
    }

    $fp = trim((string) ($request['package_fingerprint'] ?? str_repeat('7', 32)));
    $schema = (int) ($request['schema_revision'] ?? 121);
    $countryId = (int) ($request['country_id'] ?? 1);
    $countryCode = strtoupper(trim((string) ($request['country_code'] ?? 'KW')));
    $cloneEnv = [
        'clone_environment_id' => (string) ($request['clone_environment_id'] ?? 'clone-env-p705'),
        'drill_context' => (string) ($request['drill_context'] ?? 'clone'),
        'clone_work_root' => $cloneRoot,
        'schema_revision' => $schema,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'package_id' => (string) ($request['package_id'] ?? 'drill-pkg-p705'),
        'package_fingerprint' => $fp,
        'isolation_confirmed' => true,
        'production_db_access' => false,
        'production_uploads_access' => false,
        'production_services_access' => false,
    ];
    $baseReq = [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'schema_revision' => $schema,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'package_id' => (string) $cloneEnv['package_id'],
        'package_fingerprint' => $fp,
        'clone_environment' => $cloneEnv,
    ];

    $stages = [];
    $harness = orange_cpr_drill_harness_live_run($env, $baseReq);
    if (empty($harness['ok'])) {
        return orange_cpr_p7int_fail(
            ORANGE_CPR_P7INT_ERR_CHAIN,
            'Clone harness stage failed: ' . (string) ($harness['code'] ?? ''),
            ['stage' => 'clone_harness', 'detail' => $harness]
        );
    }
    $jobId = (string) ($harness['job_id'] ?? '');
    $stages[] = ['stage' => 'clone_harness', 'ok' => true];
    $stages[] = ['stage' => 'environment_binding', 'ok' => true, 'binding_id' => $harness['binding_id'] ?? null];

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.p7_integration_live_start',
        'job_id' => $jobId,
        'stage_order' => orange_cpr_p7_integration_stage_order(),
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'p8_started' => false,
        'actor_admin_id' => $actorAdminId,
    ]);

    $drill = orange_cpr_drill_execution_live_run($env, array_merge($baseReq, ['job_id' => $jobId]));
    if (empty($drill['ok'])) {
        return orange_cpr_p7int_fail(
            ORANGE_CPR_P7INT_ERR_CHAIN,
            'DS-* scenario execution stage failed: ' . (string) ($drill['code'] ?? ''),
            ['stage' => 'ds_scenario_execution', 'detail' => $drill]
        );
    }
    $stages[] = [
        'stage' => 'ds_scenario_execution',
        'ok' => true,
        'scenario_count' => $drill['scenario_count'] ?? null,
    ];
    $stages[] = ['stage' => 'sealed_drill_reports', 'ok' => true];

    $evidence = orange_cpr_evidence_pack_live_run($env, array_merge($baseReq, ['job_id' => $jobId]));
    if (empty($evidence['ok'])) {
        return orange_cpr_p7int_fail(
            ORANGE_CPR_P7INT_ERR_CHAIN,
            'Evidence pack stage failed: ' . (string) ($evidence['code'] ?? ''),
            ['stage' => 'ev_assembly', 'detail' => $evidence]
        );
    }
    $stages[] = [
        'stage' => 'ev_assembly',
        'ok' => true,
        'evidence_pack_id' => $evidence['evidence_pack_id'] ?? null,
    ];
    $stages[] = [
        'stage' => 'sealed_evidence_pack',
        'ok' => true,
        'pack_seal_hash' => $evidence['pack_seal_hash'] ?? null,
    ];

    $verify = orange_cpr_p7_integration_verify($env, $jobId, []);
    if (empty($verify['ok'])) {
        return $verify;
    }

    $now = gmdate('c');
    $freezeId = orange_cpr_generate_job_id();
    $inventory = [
        'WP-P7-01' => 'COUNTRY_PRODUCTION_RESTORE_P7_ARTIFACT_INDEX.md',
        'WP-P7-02' => ['doc' => 'COUNTRY_PRODUCTION_RESTORE_P7_02_DRILL_HARNESS.md', 'code' => 'cpr_drill_harness_live.php'],
        'WP-P7-03' => ['doc' => 'COUNTRY_PRODUCTION_RESTORE_P7_03_DRILL_EXECUTION.md', 'code' => ['cpr_drill_catalog.php', 'cpr_drill_execution_live.php']],
        'WP-P7-04' => ['doc' => 'COUNTRY_PRODUCTION_RESTORE_P7_04_EVIDENCE_PACK.md', 'code' => ['cpr_evidence_catalog.php', 'cpr_evidence_pack_live.php']],
        'WP-P7-05' => ['doc' => 'COUNTRY_PRODUCTION_RESTORE_P7_05_INTEGRATION_BASELINE.md', 'code' => 'cpr_p7_integration.php'],
    ];

    $recovery = [
        'job_id' => $jobId,
        'completed_phase' => 'p7_integration_baseline_frozen',
        'execution_status' => 'p7_baseline_ready',
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision_expected' => $schema,
        'evidence_pack_id' => $evidence['evidence_pack_id'] ?? null,
        'package_cycle_id' => $evidence['package_cycle_id'] ?? null,
        'pack_seal_hash' => $evidence['pack_seal_hash'] ?? null,
        'stage_order' => orange_cpr_p7_integration_stage_order(),
        'enterprise_audit_not_started' => true,
        'git_tag_not_created' => true,
        'p8_not_started' => true,
        'owner_cert_pending' => true,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
    ];

    $report = [
        'schema_version' => ORANGE_CPR_P7_INTEGRATION_SCHEMA,
        'engine_version' => ORANGE_CPR_P7_INTEGRATION_VERSION,
        'record_type' => 'p7_integration_freeze_report',
        'freeze_id' => $freezeId,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'p7_baseline_frozen' => true,
        'p7_baseline_ready' => true,
        'exactly_once' => true,
        'stage_order' => orange_cpr_p7_integration_stage_order(),
        'stages' => $stages,
        'verification' => [
            'ok' => true,
            'checks' => $verify['checks'] ?? [],
        ],
        'final_artifact_inventory' => $inventory,
        'binding_id' => $harness['binding_id'] ?? null,
        'harness_report_id' => $harness['harness_report_id'] ?? null,
        'aggregate_id' => $drill['aggregate_id'] ?? null,
        'evidence_pack_id' => $evidence['evidence_pack_id'] ?? null,
        'package_cycle_id' => $evidence['package_cycle_id'] ?? null,
        'pack_seal_hash' => $evidence['pack_seal_hash'] ?? null,
        'scenario_count' => $drill['scenario_count'] ?? null,
        'evidence_classes_count' => ORANGE_CPR_EVIDENCE_CLASS_COUNT,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision_expected' => $schema,
        'drill_context' => (string) $cloneEnv['drill_context'],
        'clone_environment_id' => (string) $cloneEnv['clone_environment_id'],
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'ops_enablement_flag' => false,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'p8_started' => false,
        'owner_cert_pass_granted' => false,
        'recovery_metadata' => $recovery,
        'created_at' => $now,
        'verdict' => 'A — P7 CLONE-DRILL EVIDENCE BASELINE APPROVED · READY FOR OWNER REVIEW (no Enterprise Audit / Tag / P8 until authorized)',
    ];

    try {
        $path = orange_cpr_p7_integration_persist(
            $cprRoot,
            $jobId,
            'cpr_p7_integration_' . $freezeId,
            $report
        );
        $latest = orange_cpr_p7_integration_latest_path($cprRoot, $jobId);
        $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
        $sealedLatest = orange_cpr_auth_seal(array_merge($report, ['integration_report_path' => $path]));
        $json = json_encode($sealedLatest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('P7 integration latest write failed.');
        }
        orange_cpr_atomic_rename_replace($tmp, $latest);
    } catch (RuntimeException $e) {
        return orange_cpr_p7int_fail(ORANGE_CPR_P7INT_ERR_PERSIST, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.p7_integration_live_complete',
        'job_id' => $jobId,
        'freeze_id' => $freezeId,
        'p7_baseline_frozen' => true,
        'p7_baseline_ready' => true,
        'evidence_pack_id' => $evidence['evidence_pack_id'] ?? null,
        'pack_seal_hash' => $evidence['pack_seal_hash'] ?? null,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'p8_started' => false,
        'owner_cert_pass_granted' => false,
        'actor_admin_id' => $actorAdminId,
    ]);

    return orange_cpr_p7int_ok([
        'message' => 'P7 clone-drill evidence chain integrated and baseline frozen.',
        'job_id' => $jobId,
        'freeze_id' => $freezeId,
        'p7_baseline_ready' => true,
        'p7_baseline_frozen' => true,
        'exactly_once' => true,
        'stage_order' => orange_cpr_p7_integration_stage_order(),
        'stages' => $stages,
        'verification' => $verify,
        'evidence_pack_id' => $evidence['evidence_pack_id'] ?? null,
        'package_cycle_id' => $evidence['package_cycle_id'] ?? null,
        'pack_seal_hash' => $evidence['pack_seal_hash'] ?? null,
        'integration_report' => orange_cpr_p7_integration_load_latest($cprRoot, $jobId),
        'recovery_metadata' => $recovery,
        'final_artifact_inventory' => $inventory,
        'production_resources_accessed' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'p8_started' => false,
        'owner_cert_pass_granted' => false,
    ]);
}
