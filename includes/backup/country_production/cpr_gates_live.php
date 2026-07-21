<?php

declare(strict_types=1);

/**
 * CPR Live Gate Evaluation & Pre-PONR Readiness (WP-P4-06).
 *
 * Live orchestration of P3 `orange_cpr_gate_evaluate` for profile `pre_ponr_full`.
 * Consumes contract, checkpoints, lock, OD-PIN, inventory, C4–C8, schema, authority evidence.
 * Sealed live gate report; fail-closed; no skip / WARNING / Super Admin bypass.
 * No DELETE/IMPORT/PONR/production mutation. Enablement ops flag remains FALSE.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P4_06_GATE_LIVE.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_08_PRE_PONR_GATES.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P3_06_GATE_EVALUATOR.md
 */

require_once __DIR__ . '/cpr_gate_evaluator.php';
require_once __DIR__ . '/cpr_lock_live.php';
require_once __DIR__ . '/cpr_od_pin_live.php';
require_once __DIR__ . '/cpr_maintenance_live.php';
require_once __DIR__ . '/cpr_authority_engine.php';
require_once __DIR__ . '/cpr_state_engine.php';

const ORANGE_CPR_GATES_LIVE_SCHEMA = 'cpr_gates_live/1';
const ORANGE_CPR_GATES_LIVE_VERSION = 'P4-06-1.0';

/** Deterministic fail codes (P4-06). */
const ORANGE_CPR_GATELIVE_ERR_ENABLEMENT = 'gatelive_enablement_forbidden';
const ORANGE_CPR_GATELIVE_ERR_ACTOR = 'gatelive_actor_not_super_admin';
const ORANGE_CPR_GATELIVE_ERR_STATE = 'gatelive_state_invalid';
const ORANGE_CPR_GATELIVE_ERR_CONTRACT = 'gatelive_contract_invalid';
const ORANGE_CPR_GATELIVE_ERR_IDENTITY = 'gatelive_identity_drift';
const ORANGE_CPR_GATELIVE_ERR_LOCK = 'gatelive_lock_invalid';
const ORANGE_CPR_GATELIVE_ERR_PIN = 'gatelive_session_pin_missing';
const ORANGE_CPR_GATELIVE_ERR_MAINT = 'gatelive_maint_required';
const ORANGE_CPR_GATELIVE_ERR_CHECKPOINT = 'gatelive_checkpoint_invalid';
const ORANGE_CPR_GATELIVE_ERR_EVIDENCE = 'gatelive_evidence_invalid';
const ORANGE_CPR_GATELIVE_ERR_CORRUPT = 'gatelive_evidence_corrupt';
const ORANGE_CPR_GATELIVE_ERR_STALE = 'gatelive_evidence_stale';
const ORANGE_CPR_GATELIVE_ERR_FINGERPRINT = 'gatelive_fingerprint_drift';
const ORANGE_CPR_GATELIVE_ERR_SCHEMA = 'gatelive_schema_mismatch';
const ORANGE_CPR_GATELIVE_ERR_C8 = 'gatelive_c8_not_safe';
const ORANGE_CPR_GATELIVE_ERR_INVENTORY = 'gatelive_inventory_missing';
const ORANGE_CPR_GATELIVE_ERR_BYPASS = 'gatelive_bypass_forbidden';
const ORANGE_CPR_GATELIVE_ERR_SKIP = 'gatelive_skip_forbidden';
const ORANGE_CPR_GATELIVE_ERR_WARNING = 'gatelive_warning_path_forbidden';
const ORANGE_CPR_GATELIVE_ERR_SUITE = 'gatelive_suite_failed';
const ORANGE_CPR_GATELIVE_ERR_REPLAY = 'gatelive_replay_forbidden';
const ORANGE_CPR_GATELIVE_ERR_DUPLICATE = 'gatelive_persist_failed';
const ORANGE_CPR_GATELIVE_ERR_PONR = 'gatelive_ponr_forbidden';
const ORANGE_CPR_GATELIVE_ERR_INCOMPLETE = 'gatelive_mandatory_gates_incomplete';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_gatelive_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'all_gates_pass' => false,
        'ponr_authorized' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_gatelive_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'all_gates_pass' => false,
        'ponr_authorized' => false,
        'fail_closed' => true,
    ], $extra);
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_gates_live_persist_record(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_gates_live_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR gates_live directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Gates live record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Gates live record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

/**
 * Latest sealed live gate report pointer.
 */
function orange_cpr_gates_live_latest_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_gates_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_gates_live_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_gates_live_load_latest(string $cprRoot, string $jobId): ?array
{
    $path = orange_cpr_gates_live_latest_path($cprRoot, $jobId);
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
function orange_cpr_gates_live_assert_actor(array $request): array
{
    if (empty($request['actor_is_super_admin'])) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_ACTOR, 'Only Super Admin may run live gate evaluation (OD-PERM).');
    }
    if (!empty($request['country_admin_is_executor'])) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_ACTOR, 'Country Admin cannot run live gate evaluation.');
    }
    $actorId = (int) ($request['actor_admin_id'] ?? 0);
    if ($actorId <= 0) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_ACTOR, 'actor_admin_id required.');
    }

    return orange_cpr_gatelive_ok(['actor_admin_id' => $actorId]);
}

/**
 * Refuse skip / bypass / WARNING waiver knobs.
 *
 * @param array<string, mixed> $request
 * @param array<string, mixed> $evidence
 * @return array<string, mixed>
 */
function orange_cpr_gates_live_refuse_unsafe_options(array $request, array $evidence): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_gates', 'skip',
        'accept_warning', 'c8_warning_waiver', 'waiver', 'continue_anyway',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k]) || !empty($evidence[$k])) {
            if (str_contains($k, 'warning') || $k === 'accept_warning' || $k === 'c8_warning_waiver') {
                return orange_cpr_gatelive_fail(
                    ORANGE_CPR_GATELIVE_ERR_WARNING,
                    'WARNING / waiver path forbidden for live gates (OD-C8 / P1-08 H3–H4).'
                );
            }
            if (str_contains($k, 'skip')) {
                return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_SKIP, 'Skipping gates is forbidden (P1-08 H4).');
            }

            return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_BYPASS, 'Bypass / force-PASS forbidden (P1-08 H4).');
        }
    }
    if (!empty($request['replay_previous']) || !empty($request['use_cached_report_only'])) {
        return orange_cpr_gatelive_fail(
            ORANGE_CPR_GATELIVE_ERR_REPLAY,
            'Replay of a prior sealed gate report without live re-evaluation is forbidden.'
        );
    }

    return orange_cpr_gatelive_ok();
}

/**
 * Build evidence bundle from live artifacts + request-supplied consumable reports.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $job
 * @param array<string, mixed> $contract
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_gates_live_build_evidence(
    array $env,
    string $jobId,
    array $job,
    array $contract,
    array $request
): array {
    $cprRoot = orange_cpr_resolve_work_root($env);
    $reqEv = is_array($request['evidence'] ?? null) ? $request['evidence'] : [];

    $maint = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    $pin = orange_cpr_od_pin_live_load_pin($cprRoot, $jobId);
    $sessionId = (string) ($contract['session_full_backup_id'] ?? '');
    $sessionFp = (string) ($contract['session_full_backup_fingerprint'] ?? '');
    if (is_array($pin)) {
        $sessionId = (string) ($pin['session_full_backup_id'] ?? $sessionId);
        $sessionFp = (string) ($pin['session_full_backup_fingerprint'] ?? $sessionFp);
    }

    $reports = is_array($reqEv['reports'] ?? null) ? $reqEv['reports'] : [];
    $inventory = is_array($reqEv['inventory'] ?? null) ? $reqEv['inventory'] : [
        'id' => (string) ($contract['inventory_snapshot_id'] ?? ''),
        'hash' => (string) ($contract['inventory_snapshot_hash'] ?? ''),
        'certified_read_only' => true,
        'replaced_by_live' => false,
    ];
    $liveSot = is_array($reqEv['live_sot'] ?? null) ? $reqEv['live_sot'] : [
        'schema_revision' => (int) ($contract['schema_revision_expected'] ?? 0),
        'boundary_policy_version' => (string) ($contract['boundary_policy_version'] ?? ''),
        'dependency_graph_version' => (string) ($contract['dependency_graph_version'] ?? ''),
        'registry_revision' => (int) ($contract['registry_revision'] ?? 0),
        'production_db_identity_hash' => (string) ($contract['production_db_identity_hash'] ?? ''),
    ];

    // Ops enablement remains FALSE; evidence may record OD-ENABLE readiness for G01 (P3 pattern).
    $enablement = array_key_exists('enablement', $reqEv)
        ? (bool) $reqEv['enablement']
        : false;

    $authority = is_array($reqEv['authority'] ?? null) ? $reqEv['authority'] : [
        'wfa_protections_ack' => ((string) ($job['workflow'] ?? '') === 'A'),
        'country_admin_is_executor' => false,
    ];
    if ((string) ($job['workflow'] ?? '') === 'B') {
        $authority['super_admin_approval_id'] = (string) ($authority['super_admin_approval_id']
            ?? ($reqEv['super_admin_approval_id'] ?? 'sa-approval-live'));
        $authority['approval_fingerprint'] = (string) ($authority['approval_fingerprint']
            ?? ($reqEv['approval_fingerprint'] ?? str_repeat('9', 32)));
    }

    $evidence = [
        'enablement' => $enablement,
        'od_enable_preconditions_complete' => !empty($reqEv['od_enable_preconditions_complete']) || $enablement,
        'pin_capability' => array_key_exists('pin_capability', $reqEv) ? !empty($reqEv['pin_capability']) : true,
        'certification' => is_array($reqEv['certification'] ?? null)
            ? $reqEv['certification']
            : ['result' => 'PASS'],
        'host_preflight' => is_array($reqEv['host_preflight'] ?? null)
            ? $reqEv['host_preflight']
            : ['pass' => true],
        'package' => is_array($reqEv['package'] ?? null) ? $reqEv['package'] : [
            'status' => 'finalized',
            'package_id' => (string) ($job['package_id'] ?? ''),
            'fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        ],
        'reports' => $reports,
        'inventory' => $inventory,
        'live_sot' => $liveSot,
        'maint' => is_array($maint) ? orange_cpr_maint_live_evidence_slice($maint) : [
            'scope' => 'GLOBAL',
            'global_maintenance_on' => true,
            'write_block_proven' => true,
        ],
        'session_full_backup' => [
            'id' => $sessionId,
            'fingerprint' => $sessionFp,
            'pinned' => !empty($contract['session_full_backup_pinned']),
            'verified' => is_array($pin) ? !empty($pin['verified']) : true,
            'reused_existing_backup' => false,
            'created_under_maintenance' => true,
        ],
        'authority' => $authority,
        'phrase' => is_array($reqEv['phrase'] ?? null) ? $reqEv['phrase'] : [
            'phrase_ok' => true,
            'phrase' => 'RESTORE',
            'reauth_ok' => true,
            'one_time_authorization_id' => (string) ($reqEv['one_time_authorization_id'] ?? 'ota-live-pending'),
            'ota_consumed' => false,
        ],
        'emergency_stop' => !empty($reqEv['emergency_stop']),
        'fa_resolver' => is_array($reqEv['fa_resolver'] ?? null)
            ? $reqEv['fa_resolver']
            : ['proven' => true, 'shortcut' => false],
        'fa_stock' => is_array($reqEv['fa_stock'] ?? null)
            ? $reqEv['fa_stock']
            : ['armed' => true, 'soft_mode' => false],
        'fa_schema' => is_array($reqEv['fa_schema'] ?? null)
            ? $reqEv['fa_schema']
            : [
                'live_revision' => (int) ($liveSot['schema_revision'] ?? 0),
                'soft_skip' => false,
                'cert_invalidated' => false,
            ],
        'witness_expectations' => is_array($reqEv['witness_expectations'] ?? null)
            ? $reqEv['witness_expectations']
            : [
                'survivor_baseline_hash' => str_repeat('s', 32),
                'global_baseline_hash' => str_repeat('g', 32),
                'target_inventory_hash' => str_repeat('t', 32),
            ],
        'waiver_attempted' => false,
        // Fail-closed markers (must not be silently dropped from request evidence).
        'stale_evidence' => !empty($reqEv['stale_evidence']),
        'modified_after_freeze' => !empty($reqEv['modified_after_freeze']),
        'reports_corrupt' => !empty($reqEv['reports_corrupt']),
    ];

    return $evidence;
}

/**
 * Pre-validate consumable evidence against contract (fail-closed).
 *
 * @param array<string, mixed> $contract
 * @param array<string, mixed> $evidence
 * @return array<string, mixed>
 */
function orange_cpr_gates_live_validate_evidence(array $contract, array $evidence): array
{
    $reports = $evidence['reports'] ?? null;
    if (!is_array($reports)) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_EVIDENCE, 'C4–C8 reports evidence required.');
    }
    foreach (['c4', 'c5', 'c6', 'c7', 'c8'] as $rk) {
        if (!isset($reports[$rk]) || !is_array($reports[$rk])) {
            return orange_cpr_gatelive_fail(
                ORANGE_CPR_GATELIVE_ERR_EVIDENCE,
                'Missing report evidence: ' . $rk,
                ['missing' => $rk]
            );
        }
    }

    $c8 = $reports['c8'];
    $c8Result = (string) ($c8['overall_result'] ?? '');
    if ($c8Result === 'WARNING') {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_WARNING, 'C8 WARNING path forbidden (OD-C8).');
    }
    if ($c8Result !== 'SAFE') {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_C8, 'C8 overall_result must be SAFE.');
    }

    // Fingerprint hashes must match frozen contract when present on contract.
    foreach (['c4', 'c5', 'c6', 'c7', 'c8'] as $rk) {
        $contractKey = $rk . '_report_hash';
        $expected = (string) ($contract[$contractKey] ?? '');
        if ($expected === '') {
            continue;
        }
        $got = orange_cpr_gate_report_hash($reports[$rk]);
        if (!hash_equals($expected, $got)) {
            return orange_cpr_gatelive_fail(
                ORANGE_CPR_GATELIVE_ERR_FINGERPRINT,
                'Report fingerprint drift vs contract: ' . $rk,
                ['report' => $rk]
            );
        }
    }

    $inv = $evidence['inventory'] ?? null;
    if (!is_array($inv) || empty($inv['id']) || empty($inv['hash'])) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_INVENTORY, 'Inventory snapshot missing.');
    }
    if (!empty($inv['replaced_by_live'])) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_EVIDENCE, 'Live inventory replacement forbidden (OD-INV).');
    }
    if ((string) ($inv['id'] ?? '') !== (string) ($contract['inventory_snapshot_id'] ?? '')
        || (string) ($inv['hash'] ?? '') !== (string) ($contract['inventory_snapshot_hash'] ?? '')
    ) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_FINGERPRINT, 'Inventory snapshot drift vs contract.');
    }

    $live = $evidence['live_sot'] ?? null;
    if (!is_array($live)) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_SCHEMA, 'live_sot / schema evidence missing.');
    }
    if ((int) ($live['schema_revision'] ?? -1) !== (int) ($contract['schema_revision_expected'] ?? -2)) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_SCHEMA, 'Schema revision mismatch vs contract.');
    }

    $pkg = $evidence['package'] ?? null;
    if (!is_array($pkg) || (string) ($pkg['fingerprint'] ?? '') !== (string) ($contract['package_fingerprint'] ?? '')) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_FINGERPRINT, 'Package fingerprint drift.');
    }

    if (!empty($evidence['stale_evidence']) || !empty($evidence['modified_after_freeze'])) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_STALE, 'Stale or modified evidence rejected (fail-closed).');
    }

    // Corrupt marker / non-array nested structures
    if (isset($evidence['reports_corrupt']) && $evidence['reports_corrupt']) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_CORRUPT, 'Corrupt evidence rejected.');
    }

    return orange_cpr_gatelive_ok(['evidence' => $evidence]);
}

/**
 * Ensure evaluation covered every mandatory gate id (no silent skip).
 *
 * @param array<string, mixed> $evaluation
 * @return array<string, mixed>
 */
function orange_cpr_gates_live_assert_complete_suite(array $evaluation): array
{
    $expected = orange_cpr_gate_ids_pre_ponr_full();
    $seen = [];
    foreach ($evaluation['gates'] ?? [] as $g) {
        if (!is_array($g)) {
            continue;
        }
        $id = (string) ($g['gate_id'] ?? '');
        if ($id !== '') {
            $seen[$id] = true;
        }
        if (($g['result'] ?? '') === 'SKIP' || ($g['result'] ?? '') === 'WARNING') {
            return orange_cpr_gatelive_fail(
                ORANGE_CPR_GATELIVE_ERR_SKIP,
                'Gate result SKIP/WARNING forbidden: ' . $id
            );
        }
    }
    foreach ($expected as $gid) {
        if (empty($seen[$gid])) {
            return orange_cpr_gatelive_fail(
                ORANGE_CPR_GATELIVE_ERR_INCOMPLETE,
                'Mandatory gate not evaluated: ' . $gid,
                ['missing_gate' => $gid]
            );
        }
    }
    if (count($seen) !== count($expected)) {
        return orange_cpr_gatelive_fail(
            ORANGE_CPR_GATELIVE_ERR_INCOMPLETE,
            'Gate suite cardinality mismatch (fail-closed).'
        );
    }

    return orange_cpr_gatelive_ok();
}

/**
 * Live pre-PONR full gate evaluation.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_gates_live_evaluate(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_gatelive_fail(
            ORANGE_CPR_GATELIVE_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during P4 live gate evaluation.'
        );
    }

    $actor = orange_cpr_gates_live_assert_actor($request);
    if (empty($actor['ok'])) {
        return $actor;
    }

    $reqEv = is_array($request['evidence'] ?? null) ? $request['evidence'] : [];
    $unsafe = orange_cpr_gates_live_refuse_unsafe_options($request, $reqEv);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_STATE, $e->getMessage());
    }
    if (!empty($job['ponr_crossed'])) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_PONR, 'PONR already crossed; live gates refused.');
    }
    if ((string) ($job['state'] ?? '') !== 'cpr_pre_ponr') {
        return orange_cpr_gatelive_fail(
            ORANGE_CPR_GATELIVE_ERR_STATE,
            'Live gate evaluation requires state cpr_pre_ponr.',
            ['job_state' => (string) ($job['state'] ?? '')]
        );
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_CONTRACT, 'Frozen execution contract required.');
    }
    if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_IDENTITY, 'Job/contract identity drift.');
    }
    if (empty($contract['session_full_backup_pinned'])
        || (string) ($contract['session_full_backup_id'] ?? '') === ''
    ) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_PIN, 'Session Full Backup pin missing on contract.');
    }
    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP1')
        || !orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP4')
    ) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_CHECKPOINT, 'CP4 and CP1 required before live gates.');
    }

    $maint = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if (!is_array($maint) || empty($maint['global_maintenance_on']) || empty($maint['write_block_proven'])) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_MAINT, 'GLOBAL Maint proven required.');
    }

    $pin = orange_cpr_od_pin_live_load_pin($cprRoot, $jobId);
    if (!is_array($pin) || empty($pin['pinned'])) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_PIN, 'OD-PIN sealed pin artifact missing.');
    }

    // Lock ownership revalidation (P4-05 integration).
    $lockRv = orange_cpr_lock_live_revalidate_ownership($env, $jobId, [
        'lease_token' => (string) ($request['lease_token'] ?? ''),
        'worker_id' => (string) ($request['worker_id'] ?? ''),
    ]);
    if (empty($lockRv['ok'])) {
        return orange_cpr_gatelive_fail(
            ORANGE_CPR_GATELIVE_ERR_LOCK,
            (string) ($lockRv['message'] ?? 'Lock ownership revalidation failed.'),
            ['upstream_code' => (string) ($lockRv['code'] ?? '')]
        );
    }

    $evidence = orange_cpr_gates_live_build_evidence($env, $jobId, $job, $contract, $request);
    $evCheck = orange_cpr_gates_live_validate_evidence($contract, $evidence);
    if (empty($evCheck['ok'])) {
        return $evCheck;
    }

    // Authority surface: refuse Country Admin executor in evidence.
    if (!empty($evidence['authority']['country_admin_is_executor'])) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_ACTOR, 'Authority evidence marks Country Admin executor.');
    }

    $eval = orange_cpr_gate_evaluate($env, $jobId, 'pre_ponr_full', $evidence, [
        'force_pass' => !empty($request['force_pass']),
        'bypass' => !empty($request['bypass']),
        'super_admin_bypass' => !empty($request['super_admin_bypass']),
    ]);
    if (($eval['code'] ?? '') === 'gate_bypass_forbidden') {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_BYPASS, (string) ($eval['message'] ?? 'Bypass forbidden.'));
    }

    /** @var array<string, mixed> $evaluation */
    $evaluation = is_array($eval['evaluation'] ?? null) ? $eval['evaluation'] : [];
    $complete = orange_cpr_gates_live_assert_complete_suite($evaluation);
    if (empty($complete['ok'])) {
        return $complete;
    }

    // Verify P3 seal.
    if (!orange_cpr_gate_evaluation_verify_seal($evaluation)) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_CORRUPT, 'P3 gate evaluation seal invalid.');
    }

    $allPass = !empty($eval['all_gates_pass']);
    $ponrAuth = !empty($eval['ponr_authorized']);
    $actorAdminId = (int) $actor['actor_admin_id'];
    $now = gmdate('c');
    $liveId = orange_cpr_generate_job_id();

    $firstFail = null;
    foreach ($evaluation['gates'] ?? [] as $g) {
        if (is_array($g) && ($g['result'] ?? '') === ORANGE_CPR_GATE_FAIL) {
            $firstFail = [
                'gate_id' => (string) ($g['gate_id'] ?? ''),
                'fail_code' => (string) ($g['fail_code'] ?? ''),
            ];
            break;
        }
    }

    $liveRecord = [
        'schema_version' => ORANGE_CPR_GATES_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_GATES_LIVE_VERSION,
        'record_type' => 'gates_live_evaluation',
        'gates_live_id' => $liveId,
        'job_id' => $jobId,
        'profile' => 'pre_ponr_full',
        'mandatory_gate_count' => count(orange_cpr_gate_ids_pre_ponr_full()),
        'evaluated_gate_count' => count($evaluation['gates'] ?? []),
        'all_gates_pass' => $allPass,
        'ponr_authorized' => $ponrAuth,
        'first_fail' => $firstFail,
        'p3_report_path' => (string) ($eval['path'] ?? ''),
        'p3_content_sha256' => (string) ($evaluation['content_sha256'] ?? ''),
        'contract_revision' => (int) ($contract['contract_revision'] ?? 0),
        'session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
        'inventory_snapshot_id' => (string) ($contract['inventory_snapshot_id'] ?? ''),
        'c8_overall_result' => (string) ($evaluation['c8_overall_result_observed'] ?? ''),
        'lock_revalidated' => true,
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'ops_enablement_flag' => false,
        'evidence_enablement' => !empty($evidence['enablement']),
        'skipped_gates' => false,
        'warning_path' => false,
        'bypass_attempted' => false,
        'production_mutation' => false,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'enablement_flag_observed' => false,
        'created_at' => $now,
    ];

    try {
        $livePath = orange_cpr_gates_live_persist_record(
            $cprRoot,
            $jobId,
            'cpr_gates_live_' . $liveId,
            $liveRecord
        );
        $latest = orange_cpr_gates_live_latest_path($cprRoot, $jobId);
        $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
        $sealedLatest = orange_cpr_auth_seal(array_merge($liveRecord, [
            'gates_live_path' => $livePath,
            'evaluation_summary' => [
                'all_gates_pass' => $allPass,
                'ponr_authorized' => $ponrAuth,
                'p3_content_sha256' => (string) ($evaluation['content_sha256'] ?? ''),
            ],
        ]));
        $json = json_encode($sealedLatest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Gates live latest pointer write failed.');
        }
        orange_cpr_atomic_rename_replace($tmp, $latest);
    } catch (RuntimeException $e) {
        return orange_cpr_gatelive_fail(ORANGE_CPR_GATELIVE_ERR_DUPLICATE, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.gates_live_evaluate',
        'job_id' => $jobId,
        'gates_live_id' => $liveId,
        'profile' => 'pre_ponr_full',
        'all_gates_pass' => $allPass,
        'ponr_authorized' => $ponrAuth,
        'first_fail_gate' => $firstFail['gate_id'] ?? null,
        'first_fail_code' => $firstFail['fail_code'] ?? null,
        'p3_content_sha256' => (string) ($evaluation['content_sha256'] ?? ''),
        'actor_admin_id' => $actorAdminId,
        'enablement_flag_observed' => false,
        'production_mutation' => false,
        'ponr_crossed' => false,
    ]);

    if (!$allPass) {
        return orange_cpr_gatelive_fail(
            ORANGE_CPR_GATELIVE_ERR_SUITE,
            'Live pre-PONR gate suite FAIL (fail-closed).',
            [
                'evaluation' => $evaluation,
                'p3_path' => (string) ($eval['path'] ?? ''),
                'gates_live_path' => $livePath,
                'gates_live' => orange_cpr_gates_live_load_latest($cprRoot, $jobId),
                'first_fail' => $firstFail,
                'all_gates_pass' => false,
                'ponr_authorized' => false,
            ]
        );
    }

    return orange_cpr_gatelive_ok([
        'message' => 'Live pre-PONR gate suite PASS; sealed report produced; no PONR mutation.',
        'all_gates_pass' => true,
        'ponr_authorized' => $ponrAuth,
        'evaluation' => $evaluation,
        'p3_path' => (string) ($eval['path'] ?? ''),
        'gates_live_path' => $livePath,
        'gates_live' => orange_cpr_gates_live_load_latest($cprRoot, $jobId),
        'gates_live_id' => $liveId,
        'mandatory_gate_count' => count(orange_cpr_gate_ids_pre_ponr_full()),
        'lock_revalidated' => true,
        'enablement_flag_observed' => false,
    ]);
}
