<?php

declare(strict_types=1);

/**
 * CPR Live Witnesses (CP5) & CP-A Pre-PONR Freeze (WP-P4-08).
 *
 * After sealed live gate PASS + authority/RESTORE ceremony:
 * - Capture and seal witness bundle (survivor / global / inventory)
 * - Persist CP5 via Checkpoint Engine
 * - Persist CP-A as last fully reversible idle point
 * - No PONR / DELETE / IMPORT / production mutation; no auto-continue beyond CP-A
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P4_08_WITNESSES_CPA.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_04_CHECKPOINT_SCHEMAS.md
 */

require_once __DIR__ . '/cpr_authority_live.php';
require_once __DIR__ . '/cpr_gates_live.php';
require_once __DIR__ . '/cpr_lock_live.php';
require_once __DIR__ . '/cpr_od_pin_live.php';
require_once __DIR__ . '/cpr_maintenance_live.php';
require_once __DIR__ . '/cpr_checkpoint_engine.php';
require_once __DIR__ . '/cpr_state_engine.php';

const ORANGE_CPR_WITNESSES_LIVE_SCHEMA = 'cpr_witnesses_live/1';
const ORANGE_CPR_WITNESSES_LIVE_VERSION = 'P4-08-1.0';
/** Engineering default: witness capture max age (seconds). */
const ORANGE_CPR_WITNESS_LIVE_MAX_AGE_SECONDS = 900;

/** Deterministic fail codes (P4-08). */
const ORANGE_CPR_WITNESSLIVE_ERR_ENABLEMENT = 'witnesslive_enablement_forbidden';
const ORANGE_CPR_WITNESSLIVE_ERR_ACTOR = 'witnesslive_actor_not_super_admin';
const ORANGE_CPR_WITNESSLIVE_ERR_STATE = 'witnesslive_state_invalid';
const ORANGE_CPR_WITNESSLIVE_ERR_CONTRACT = 'witnesslive_contract_invalid';
const ORANGE_CPR_WITNESSLIVE_ERR_LOCK = 'witnesslive_lock_invalid';
const ORANGE_CPR_WITNESSLIVE_ERR_PIN = 'witnesslive_session_pin_missing';
const ORANGE_CPR_WITNESSLIVE_ERR_MAINT = 'witnesslive_maint_required';
const ORANGE_CPR_WITNESSLIVE_ERR_GATE = 'witnesslive_gate_required';
const ORANGE_CPR_WITNESSLIVE_ERR_AUTH = 'witnesslive_authority_required';
const ORANGE_CPR_WITNESSLIVE_ERR_RUNBOOK = 'witnesslive_runbook_required';
const ORANGE_CPR_WITNESSLIVE_ERR_MISSING = 'witnesslive_witness_missing';
const ORANGE_CPR_WITNESSLIVE_ERR_STALE = 'witnesslive_witness_stale';
const ORANGE_CPR_WITNESSLIVE_ERR_CORRUPT = 'witnesslive_witness_corrupt';
const ORANGE_CPR_WITNESSLIVE_ERR_FINGERPRINT = 'witnesslive_fingerprint_mismatch';
const ORANGE_CPR_WITNESSLIVE_ERR_SURVIVOR = 'witnesslive_survivor_failure';
const ORANGE_CPR_WITNESSLIVE_ERR_GLOBAL = 'witnesslive_global_failure';
const ORANGE_CPR_WITNESSLIVE_ERR_INVENTORY = 'witnesslive_inventory_invalid';
const ORANGE_CPR_WITNESSLIVE_ERR_SCHEMA = 'witnesslive_schema_mismatch';
const ORANGE_CPR_WITNESSLIVE_ERR_REPLAY = 'witnesslive_replay_forbidden';
const ORANGE_CPR_WITNESSLIVE_ERR_DUPLICATE = 'witnesslive_duplicate_forbidden';
const ORANGE_CPR_WITNESSLIVE_ERR_CP5 = 'witnesslive_cp5_failed';
const ORANGE_CPR_WITNESSLIVE_ERR_CPA = 'witnesslive_cpa_failed';
const ORANGE_CPR_WITNESSLIVE_ERR_BYPASS = 'witnesslive_bypass_forbidden';
const ORANGE_CPR_WITNESSLIVE_ERR_PONR = 'witnesslive_ponr_forbidden';
const ORANGE_CPR_WITNESSLIVE_ERR_PERSIST = 'witnesslive_persist_failed';
const ORANGE_CPR_WITNESSLIVE_ERR_CONTINUE = 'witnesslive_auto_continue_forbidden';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_witnesslive_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'auto_continue_beyond_cpa' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_witnesslive_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'auto_continue_beyond_cpa' => false,
        'fail_closed' => true,
    ], $extra);
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_witnesses_live_persist_record(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_witnesses_live_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR witnesses_live directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Witnesses live record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Witnesses live record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_witnesses_live_bundle_latest_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_witnesses_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_witness_bundle_latest.json';
}

function orange_cpr_witnesses_live_cpa_latest_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_witnesses_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_cpa_live_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_witnesses_live_load_json(string $path): ?array
{
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
 * @return array<string, mixed>|null
 */
function orange_cpr_witnesses_live_load_bundle(string $cprRoot, string $jobId): ?array
{
    return orange_cpr_witnesses_live_load_json(orange_cpr_witnesses_live_bundle_latest_path($cprRoot, $jobId));
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_witnesses_live_load_cpa(string $cprRoot, string $jobId): ?array
{
    return orange_cpr_witnesses_live_load_json(orange_cpr_witnesses_live_cpa_latest_path($cprRoot, $jobId));
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_witnesses_live_assert_actor(array $request): array
{
    if (!empty($request['country_admin_is_executor']) || !empty($request['actor_is_country_admin'])) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_ACTOR,
            'Country Admin may not capture witnesses / CP-A (OD-PERM).'
        );
    }
    if (empty($request['actor_is_super_admin'])) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_ACTOR,
            'Only Super Admin may run live witnesses / CP-A.'
        );
    }
    $actorId = (int) ($request['actor_admin_id'] ?? 0);
    if ($actorId <= 0) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_ACTOR, 'actor_admin_id required.');
    }

    return orange_cpr_witnesslive_ok(['actor_admin_id' => $actorId]);
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_witnesses_live_refuse_unsafe(array $request): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_witnesses', 'waive_witnesses',
        'execute_ponr', 'auto_continue_beyond_cpa', 'continue_to_delete', 'continue_to_import',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            if (str_contains($k, 'continue') || str_contains($k, 'delete') || str_contains($k, 'import') || $k === 'execute_ponr') {
                return orange_cpr_witnesslive_fail(
                    ORANGE_CPR_WITNESSLIVE_ERR_CONTINUE,
                    'Automatic continuation beyond CP-A / PONR knobs forbidden.'
                );
            }

            return orange_cpr_witnesslive_fail(
                ORANGE_CPR_WITNESSLIVE_ERR_BYPASS,
                'Bypass / skip / force knobs forbidden for live witnesses.'
            );
        }
    }
    if (!empty($request['replay_previous']) || !empty($request['reuse_witness_bundle'])) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_REPLAY,
            'Replay / reuse of prior witness bundle without live re-capture is forbidden.'
        );
    }

    return orange_cpr_witnesslive_ok();
}

/**
 * Shared preconditions for witness capture and CP-A.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_witnesses_live_preconditions(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during live witnesses / CP-A.'
        );
    }

    $actor = orange_cpr_witnesses_live_assert_actor($request);
    if (empty($actor['ok'])) {
        return $actor;
    }
    $unsafe = orange_cpr_witnesses_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_STATE, $e->getMessage());
    }
    if (!empty($job['ponr_crossed'])) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_PONR, 'PONR already crossed; witnesses/CP-A refused.');
    }
    if ((string) ($job['state'] ?? '') !== 'cpr_pre_ponr') {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_STATE,
            'Live witnesses / CP-A require state cpr_pre_ponr.',
            ['job_state' => (string) ($job['state'] ?? '')]
        );
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_CONTRACT, 'Frozen execution contract required.');
    }
    if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_FINGERPRINT, 'Job/contract identity drift.');
    }
    if (empty($contract['session_full_backup_pinned'])
        || (string) ($contract['session_full_backup_id'] ?? '') === ''
    ) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_PIN, 'Session Full Backup pin missing on contract.');
    }

    $pin = orange_cpr_od_pin_live_load_pin($cprRoot, $jobId);
    if (!is_array($pin) || empty($pin['pinned']) || !orange_cpr_auth_verify_seal($pin)) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_PIN, 'OD-PIN sealed pin artifact missing or corrupt.');
    }

    $maint = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if (!is_array($maint) || empty($maint['global_maintenance_on']) || empty($maint['write_block_proven'])) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_MAINT, 'GLOBAL Maint proven required.');
    }

    $lockRv = orange_cpr_lock_live_revalidate_ownership($env, $jobId, [
        'lease_token' => (string) ($request['lease_token'] ?? ''),
        'worker_id' => (string) ($request['worker_id'] ?? ''),
    ]);
    if (empty($lockRv['ok'])) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_LOCK,
            (string) ($lockRv['message'] ?? 'Lock ownership revalidation failed.'),
            ['upstream_code' => (string) ($lockRv['code'] ?? '')]
        );
    }

    $gatesLive = orange_cpr_gates_live_load_latest($cprRoot, $jobId);
    if (!is_array($gatesLive) || empty($gatesLive['all_gates_pass']) || !orange_cpr_auth_verify_seal($gatesLive)) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_GATE,
            'Sealed live gate PASS report required (WP-P4-06).'
        );
    }

    $authLive = orange_cpr_auth_live_load_latest($cprRoot, $jobId);
    if (!is_array($authLive) || empty($authLive['ponr_authorized']) || !orange_cpr_auth_verify_seal($authLive)) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_AUTH,
            'Sealed live authority / RESTORE ceremony required (WP-P4-07).'
        );
    }
    $runbookLive = orange_cpr_runbook_live_load_latest($cprRoot, $jobId);
    if (!is_array($runbookLive) || empty($runbookLive['runbook_completed']) || !orange_cpr_auth_verify_seal($runbookLive)) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_RUNBOOK,
            'Sealed live runbook validation required.'
        );
    }
    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'runbook_pre_ponr')) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_RUNBOOK, 'runbook_pre_ponr checkpoint missing.');
    }

    $otaId = (string) ($authLive['one_time_authorization_id'] ?? '');
    if ($otaId === '') {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_AUTH, 'one_time_authorization_id missing on authority live.');
    }
    $usable = orange_cpr_ponr_authorization_assert_usable($env, $jobId, $otaId);
    if (empty($usable['ok'])) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_AUTH,
            (string) ($usable['message'] ?? 'P3 authorization not usable.'),
            ['upstream_code' => (string) ($usable['code'] ?? '')]
        );
    }

    return orange_cpr_witnesslive_ok([
        'actor_admin_id' => (int) $actor['actor_admin_id'],
        'job' => $job,
        'contract' => $contract,
        'pin' => $pin,
        'maint' => $maint,
        'gates_live' => $gatesLive,
        'auth_live' => $authLive,
        'runbook_live' => $runbookLive,
        'authorization' => $usable['authorization'] ?? null,
        'cpr_root' => $cprRoot,
    ]);
}

/**
 * Validate witness capture payload (fail-closed).
 *
 * @param array<string, mixed> $contract
 * @param array<string, mixed> $witnesses
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_witnesses_live_validate_capture(array $contract, array $witnesses, array $request): array
{
    if (!empty($witnesses['stale_witness']) || !empty($witnesses['modified_after_freeze'])
        || !empty($request['stale_witness']) || !empty($request['modified_after_freeze'])
    ) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_STALE, 'Stale or modified witness data rejected.');
    }
    if (!empty($witnesses['corrupt']) || !empty($request['witnesses_corrupt'])) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_CORRUPT, 'Corrupt witness data rejected.');
    }

    $survivor = (string) ($witnesses['survivor_baseline_hash'] ?? '');
    $global = (string) ($witnesses['global_baseline_hash'] ?? '');
    $target = (string) ($witnesses['target_inventory_hash'] ?? '');
    $invId = (string) ($witnesses['inventory_snapshot_id'] ?? '');
    $capturedAt = (string) ($witnesses['captured_at'] ?? '');

    if ($survivor === '' || $global === '' || $target === '' || $invId === '' || $capturedAt === '') {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_MISSING,
            'All mandatory witnesses required (survivor, global, target inventory, inventory id, captured_at).'
        );
    }
    if (strlen($survivor) < 32 || !empty($witnesses['survivor_failed']) || !empty($witnesses['survivor_country_impact'])) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_SURVIVOR,
            'Survivor-country witness invalid or failed (fail-closed).'
        );
    }
    if (strlen($global) < 32 || !empty($witnesses['global_failed']) || !empty($witnesses['global_impact'])) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_GLOBAL,
            'Global witness invalid or failed (fail-closed).'
        );
    }
    if (strlen($target) < 32) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_MISSING, 'target_inventory_hash invalid.');
    }

    if ($invId !== (string) ($contract['inventory_snapshot_id'] ?? '')) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_INVENTORY,
            'Inventory snapshot id mismatch vs contract (OD-INV).'
        );
    }
    $invHash = (string) ($witnesses['inventory_snapshot_hash'] ?? ($contract['inventory_snapshot_hash'] ?? ''));
    if ($invHash !== '' && $invHash !== (string) ($contract['inventory_snapshot_hash'] ?? '')) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_FINGERPRINT,
            'Inventory snapshot hash mismatch vs contract.'
        );
    }
    if (!empty($witnesses['replaced_by_live'])) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_INVENTORY,
            'Live inventory replacement forbidden (OD-INV).'
        );
    }

    $schemaObs = array_key_exists('schema_revision', $witnesses)
        ? (int) $witnesses['schema_revision']
        : (int) ($contract['schema_revision_expected'] ?? -1);
    if ($schemaObs !== (int) ($contract['schema_revision_expected'] ?? -2)) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_SCHEMA, 'Schema revision mismatch vs contract.');
    }

    // C4–C8 evidence bind (hashes when supplied must match contract).
    foreach (['c4', 'c5', 'c6', 'c7', 'c8'] as $rk) {
        $key = $rk . '_report_hash';
        if (!array_key_exists($key, $witnesses)) {
            continue;
        }
        $expected = (string) ($contract[$key] ?? '');
        if ($expected !== '' && !hash_equals($expected, (string) $witnesses[$key])) {
            return orange_cpr_witnesslive_fail(
                ORANGE_CPR_WITNESSLIVE_ERR_FINGERPRINT,
                'C4–C8 report fingerprint mismatch: ' . $rk
            );
        }
    }
    if (isset($witnesses['c8_overall_result']) && (string) $witnesses['c8_overall_result'] !== 'SAFE') {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_FINGERPRINT, 'C8 overall_result must be SAFE.');
    }

    $ts = strtotime($capturedAt);
    if ($ts === false) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_CORRUPT, 'captured_at not parseable.');
    }
    $maxAge = (int) ($request['witness_max_age_seconds'] ?? ORANGE_CPR_WITNESS_LIVE_MAX_AGE_SECONDS);
    if ($maxAge <= 0) {
        $maxAge = ORANGE_CPR_WITNESS_LIVE_MAX_AGE_SECONDS;
    }
    if ((time() - $ts) > $maxAge) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_STALE, 'Witness capture is stale.');
    }

    $pkgFp = (string) ($witnesses['package_fingerprint'] ?? '');
    if ($pkgFp !== '' && !hash_equals($pkgFp, (string) ($contract['package_fingerprint'] ?? ''))) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_FINGERPRINT, 'Package fingerprint mismatch.');
    }

    return orange_cpr_witnesslive_ok([
        'survivor_baseline_hash' => $survivor,
        'global_baseline_hash' => $global,
        'target_inventory_hash' => $target,
        'inventory_snapshot_id' => $invId,
        'inventory_snapshot_hash' => $invHash !== '' ? $invHash : (string) ($contract['inventory_snapshot_hash'] ?? ''),
        'captured_at' => gmdate('c', $ts),
        'schema_revision' => $schemaObs,
    ]);
}

/**
 * Capture and seal witness bundle (does not write CP5/CP-A).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_witnesses_live_capture(array $env, string $jobId, array $request): array
{
    $pre = orange_cpr_witnesses_live_preconditions($env, $jobId, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }

    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    $cprRoot = (string) $pre['cpr_root'];
    $actorAdminId = (int) $pre['actor_admin_id'];

    $existing = orange_cpr_witnesses_live_load_bundle($cprRoot, $jobId);
    if (is_array($existing) && !empty($existing['witness_bundle_sealed'])) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_DUPLICATE,
            'Sealed witness bundle already exists for this job.'
        );
    }

    $witnesses = is_array($request['witnesses'] ?? null) ? $request['witnesses'] : [];
    $validated = orange_cpr_witnesses_live_validate_capture($contract, $witnesses, $request);
    if (empty($validated['ok'])) {
        return $validated;
    }

    $now = gmdate('c');
    $bundleId = orange_cpr_generate_job_id();
    /** @var array<string, mixed> $authLive */
    $authLive = $pre['auth_live'];
    /** @var array<string, mixed> $gatesLive */
    $gatesLive = $pre['gates_live'];

    $record = [
        'schema_version' => ORANGE_CPR_WITNESSES_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_WITNESSES_LIVE_VERSION,
        'record_type' => 'witness_bundle',
        'witness_bundle_id' => $bundleId,
        'job_id' => $jobId,
        'package_id' => (string) ($job['package_id'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'contract_revision' => (int) ($contract['contract_revision'] ?? 0),
        'contract_phase' => (string) ($contract['contract_phase'] ?? 'pre_ponr'),
        'session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
        'session_full_backup_fingerprint' => (string) ($contract['session_full_backup_fingerprint'] ?? ''),
        'survivor_baseline_hash' => (string) $validated['survivor_baseline_hash'],
        'global_baseline_hash' => (string) $validated['global_baseline_hash'],
        'target_inventory_hash' => (string) $validated['target_inventory_hash'],
        'inventory_snapshot_id' => (string) $validated['inventory_snapshot_id'],
        'inventory_snapshot_hash' => (string) $validated['inventory_snapshot_hash'],
        'schema_revision' => (int) $validated['schema_revision'],
        'c4_report_hash' => (string) ($contract['c4_report_hash'] ?? ''),
        'c5_report_hash' => (string) ($contract['c5_report_hash'] ?? ''),
        'c6_report_hash' => (string) ($contract['c6_report_hash'] ?? ''),
        'c7_report_hash' => (string) ($contract['c7_report_hash'] ?? ''),
        'c8_report_hash' => (string) ($contract['c8_report_hash'] ?? ''),
        'c8_overall_result' => 'SAFE',
        'captured_at' => (string) $validated['captured_at'],
        'gates_live_id' => (string) ($gatesLive['gates_live_id'] ?? ''),
        'authority_live_id' => (string) ($authLive['authority_live_id'] ?? ''),
        'one_time_authorization_id' => (string) ($authLive['one_time_authorization_id'] ?? ''),
        'witness_bundle_sealed' => true,
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'ops_enablement_flag' => false,
        'production_mutation' => false,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'enablement_flag_observed' => false,
        'scaffold_record_only' => false,
        'live_path' => true,
        'created_at' => $now,
    ];

    try {
        $path = orange_cpr_witnesses_live_persist_record(
            $cprRoot,
            $jobId,
            'cpr_witness_bundle_' . $bundleId,
            $record
        );
        $latest = orange_cpr_witnesses_live_bundle_latest_path($cprRoot, $jobId);
        $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
        $sealedLatest = orange_cpr_auth_seal(array_merge($record, ['witness_bundle_path' => $path]));
        $json = json_encode($sealedLatest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Witness bundle latest pointer write failed.');
        }
        orange_cpr_atomic_rename_replace($tmp, $latest);
    } catch (RuntimeException $e) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_PERSIST, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.witnesses_live_capture',
        'job_id' => $jobId,
        'witness_bundle_id' => $bundleId,
        'inventory_snapshot_id' => (string) $validated['inventory_snapshot_id'],
        'actor_admin_id' => $actorAdminId,
        'enablement_flag_observed' => false,
        'production_mutation' => false,
        'ponr_crossed' => false,
    ]);

    return orange_cpr_witnesslive_ok([
        'message' => 'Witness bundle sealed; CP5/CP-A not yet written.',
        'witness_bundle_id' => $bundleId,
        'witness_bundle_path' => $path,
        'witness_bundle' => orange_cpr_witnesses_live_load_bundle($cprRoot, $jobId),
        'enablement_flag_observed' => false,
    ]);
}

/**
 * Persist CP5 from sealed witness bundle (create or identical idempotent).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_witnesses_live_commit_cp5(array $env, string $jobId, array $request): array
{
    $pre = orange_cpr_witnesses_live_preconditions($env, $jobId, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }
    $cprRoot = (string) $pre['cpr_root'];
    $actorAdminId = (int) $pre['actor_admin_id'];

    $bundle = orange_cpr_witnesses_live_load_bundle($cprRoot, $jobId);
    if (!is_array($bundle) || empty($bundle['witness_bundle_sealed'])) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_MISSING,
            'Sealed witness bundle required before CP5 commit.'
        );
    }

    $payload = [
        'survivor_baseline_hash' => (string) ($bundle['survivor_baseline_hash'] ?? ''),
        'global_baseline_hash' => (string) ($bundle['global_baseline_hash'] ?? ''),
        'target_inventory_hash' => (string) ($bundle['target_inventory_hash'] ?? ''),
        'inventory_snapshot_id' => (string) ($bundle['inventory_snapshot_id'] ?? ''),
        'captured_at' => (string) ($bundle['captured_at'] ?? ''),
    ];

    $cp5 = orange_cpr_checkpoint_create($env, $jobId, 'CP5', $payload, [
        'written_by' => 'super_admin',
        'actor_admin_id' => $actorAdminId,
    ]);
    if (empty($cp5['ok'])) {
        $code = (string) ($cp5['code'] ?? '');
        $mapped = $code === ORANGE_CPR_CP_ERR_DUPLICATE
            ? ORANGE_CPR_WITNESSLIVE_ERR_DUPLICATE
            : ORANGE_CPR_WITNESSLIVE_ERR_CP5;

        return orange_cpr_witnesslive_fail(
            $mapped,
            (string) ($cp5['message'] ?? 'CP5 commit failed.'),
            ['upstream_code' => $code]
        );
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.witnesses_live_commit_cp5',
        'job_id' => $jobId,
        'witness_bundle_id' => (string) ($bundle['witness_bundle_id'] ?? ''),
        'checkpoint_id' => 'CP5',
        'idempotent' => !empty($cp5['idempotent']),
        'actor_admin_id' => $actorAdminId,
        'enablement_flag_observed' => false,
        'production_mutation' => false,
        'ponr_crossed' => false,
    ]);

    return orange_cpr_witnesslive_ok([
        'message' => 'CP5 pre_ponr_witnesses committed (sealed checkpoint).',
        'cp5' => $cp5,
        'witness_bundle_id' => (string) ($bundle['witness_bundle_id'] ?? ''),
        'idempotent' => !empty($cp5['idempotent']),
        'enablement_flag_observed' => false,
    ]);
}

/**
 * Persist CP-A last reversible checkpoint (no PONR).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_witnesses_live_commit_cpa(array $env, string $jobId, array $request): array
{
    $pre = orange_cpr_witnesses_live_preconditions($env, $jobId, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }
    $cprRoot = (string) $pre['cpr_root'];
    $actorAdminId = (int) $pre['actor_admin_id'];
    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    /** @var array<string, mixed> $authLive */
    $authLive = $pre['auth_live'];
    /** @var array<string, mixed>|null $authorization */
    $authorization = is_array($pre['authorization'] ?? null) ? $pre['authorization'] : null;

    $existingCpaLive = orange_cpr_witnesses_live_load_cpa($cprRoot, $jobId);
    if (is_array($existingCpaLive) && !empty($existingCpaLive['cpa_committed'])) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_DUPLICATE,
            'Sealed CP-A live record already exists for this job.'
        );
    }
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP-A')) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_DUPLICATE,
            'CP-A checkpoint already exists; duplicate CP-A forbidden.'
        );
    }

    $bundle = orange_cpr_witnesses_live_load_bundle($cprRoot, $jobId);
    if (!is_array($bundle) || empty($bundle['witness_bundle_sealed'])) {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_MISSING,
            'Sealed witness bundle required before CP-A.'
        );
    }
    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP5')) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_CP5, 'CP5 checkpoint required before CP-A.');
    }

    // Ensure CP5 payload matches sealed bundle (no stale/mismatched witnesses).
    $cp5Load = orange_cpr_checkpoint_load($env, $jobId, 'CP5');
    if (empty($cp5Load['ok'])) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_CP5, 'CP5 checkpoint unreadable.');
    }
    $cp5Payload = is_array($cp5Load['checkpoint']['payload'] ?? null) ? $cp5Load['checkpoint']['payload'] : [];
    foreach (['survivor_baseline_hash', 'global_baseline_hash', 'target_inventory_hash', 'inventory_snapshot_id'] as $wk) {
        if ((string) ($cp5Payload[$wk] ?? '') !== (string) ($bundle[$wk] ?? '')) {
            return orange_cpr_witnesslive_fail(
                ORANGE_CPR_WITNESSLIVE_ERR_FINGERPRINT,
                'CP5 payload does not match sealed witness bundle: ' . $wk
            );
        }
    }

    $otaId = (string) ($authLive['one_time_authorization_id'] ?? '');
    $challengeId = (string) ($authLive['challenge_id'] ?? '');
    if ($challengeId === '' && is_array($authorization)) {
        $challengeId = (string) ($authorization['challenge_id'] ?? '');
    }
    if ($otaId === '' || $challengeId === '') {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_AUTH,
            'OTA / phrase_challenge_id missing for CP-A.'
        );
    }
    if ((string) ($contract['contract_phase'] ?? '') !== 'pre_ponr') {
        return orange_cpr_witnesslive_fail(
            ORANGE_CPR_WITNESSLIVE_ERR_CONTRACT,
            'Contract phase must be pre_ponr for CP-A.'
        );
    }

    $payload = [
        'runbook_evidence_ref' => 'runbook_pre_ponr.json',
        'one_time_authorization_id' => $otaId,
        'phrase_challenge_id' => $challengeId,
        'contract_phase' => 'pre_ponr',
        'cp1_session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
        'reversible' => true,
        'ponr_not_entered' => true,
        'witness_bundle_id' => (string) ($bundle['witness_bundle_id'] ?? ''),
        'gates_live_id' => (string) (($pre['gates_live']['gates_live_id'] ?? '')),
        'authority_live_id' => (string) ($authLive['authority_live_id'] ?? ''),
    ];

    $cpa = orange_cpr_checkpoint_create($env, $jobId, 'CP-A', $payload, [
        'written_by' => 'super_admin',
        'actor_admin_id' => $actorAdminId,
    ]);
    if (empty($cpa['ok'])) {
        $code = (string) ($cpa['code'] ?? '');
        $mapped = $code === ORANGE_CPR_CP_ERR_DUPLICATE
            ? ORANGE_CPR_WITNESSLIVE_ERR_DUPLICATE
            : ORANGE_CPR_WITNESSLIVE_ERR_CPA;

        return orange_cpr_witnesslive_fail(
            $mapped,
            (string) ($cpa['message'] ?? 'CP-A commit failed.'),
            ['upstream_code' => $code]
        );
    }

    $now = gmdate('c');
    $cpaLiveId = orange_cpr_generate_job_id();
    $liveRecord = [
        'schema_version' => ORANGE_CPR_WITNESSES_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_WITNESSES_LIVE_VERSION,
        'record_type' => 'cpa_live',
        'cpa_live_id' => $cpaLiveId,
        'job_id' => $jobId,
        'cpa_committed' => true,
        'checkpoint_id' => 'CP-A',
        'last_fully_reversible_idle_point' => true,
        'reversible' => true,
        'ponr_not_entered' => true,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'auto_continue_beyond_cpa' => false,
        'one_time_authorization_id' => $otaId,
        'phrase_challenge_id' => $challengeId,
        'witness_bundle_id' => (string) ($bundle['witness_bundle_id'] ?? ''),
        'runbook_evidence_ref' => 'runbook_pre_ponr.json',
        'cp1_session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
        'contract_phase' => 'pre_ponr',
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'ops_enablement_flag' => false,
        'enablement_flag_observed' => false,
        'scaffold_record_only' => false,
        'live_path' => true,
        'created_at' => $now,
    ];

    try {
        $path = orange_cpr_witnesses_live_persist_record(
            $cprRoot,
            $jobId,
            'cpr_cpa_live_' . $cpaLiveId,
            $liveRecord
        );
        $latest = orange_cpr_witnesses_live_cpa_latest_path($cprRoot, $jobId);
        $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
        $sealedLatest = orange_cpr_auth_seal(array_merge($liveRecord, ['cpa_live_path' => $path]));
        $json = json_encode($sealedLatest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('CP-A live latest pointer write failed.');
        }
        orange_cpr_atomic_rename_replace($tmp, $latest);
    } catch (RuntimeException $e) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_PERSIST, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.witnesses_live_commit_cpa',
        'job_id' => $jobId,
        'cpa_live_id' => $cpaLiveId,
        'checkpoint_id' => 'CP-A',
        'one_time_authorization_id' => $otaId,
        'witness_bundle_id' => (string) ($bundle['witness_bundle_id'] ?? ''),
        'actor_admin_id' => $actorAdminId,
        'reversible' => true,
        'ponr_not_entered' => true,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'auto_continue_beyond_cpa' => false,
        'enablement_flag_observed' => false,
    ]);

    // Hard refuse PONR mutation helper still available.
    $refuse = orange_cpr_ponr_mutation_refuse();
    $jobAfter = orange_cpr_job_read($cprRoot, $jobId);
    if (!empty($jobAfter['ponr_crossed'])) {
        return orange_cpr_witnesslive_fail(ORANGE_CPR_WITNESSLIVE_ERR_PONR, 'Invariant broken: ponr_crossed became true.');
    }

    return orange_cpr_witnesslive_ok([
        'message' => 'CP-A last reversible idle point sealed; no PONR mutation; no auto-continue.',
        'cpa' => $cpa,
        'cpa_live_id' => $cpaLiveId,
        'cpa_live_path' => $path,
        'cpa_live' => orange_cpr_witnesses_live_load_cpa($cprRoot, $jobId),
        'witness_bundle_id' => (string) ($bundle['witness_bundle_id'] ?? ''),
        'last_fully_reversible_idle_point' => true,
        'ponr_not_entered' => true,
        'ponr_mutation_refuse' => $refuse,
        'enablement_flag_observed' => false,
    ]);
}

/**
 * Full live ceremony: capture → CP5 → CP-A.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_witnesses_live_ceremony(array $env, string $jobId, array $request): array
{
    $cap = orange_cpr_witnesses_live_capture($env, $jobId, $request);
    if (empty($cap['ok'])) {
        return $cap;
    }
    $cp5 = orange_cpr_witnesses_live_commit_cp5($env, $jobId, $request);
    if (empty($cp5['ok'])) {
        return $cp5;
    }
    $cpa = orange_cpr_witnesses_live_commit_cpa($env, $jobId, $request);
    if (empty($cpa['ok'])) {
        return $cpa;
    }

    return orange_cpr_witnesslive_ok([
        'message' => 'Live witnesses + CP5 + CP-A complete; last reversible idle point; no PONR.',
        'witness_bundle_id' => (string) ($cap['witness_bundle_id'] ?? ''),
        'witness_bundle' => $cap['witness_bundle'] ?? null,
        'cp5' => $cp5['cp5'] ?? null,
        'cpa_live_id' => (string) ($cpa['cpa_live_id'] ?? ''),
        'cpa_live' => $cpa['cpa_live'] ?? null,
        'last_fully_reversible_idle_point' => true,
        'ponr_not_entered' => true,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'auto_continue_beyond_cpa' => false,
        'enablement_flag_observed' => false,
    ]);
}
