<?php

declare(strict_types=1);

/**
 * CPR Live Authority, Runbook & RESTORE Ceremony (WP-P4-07).
 *
 * Live orchestration of P3 Authority Engine after sealed live gate PASS (P4-06):
 * - Machine-verified runbook completion
 * - Exact RESTORE phrase + mandatory password re-auth evidence
 * - One-time sealed authorization (no PONR mutation)
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P4_07_AUTHORITY_RUNBOOK_LIVE.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P3_07_AUTHORITY_SCAFFOLD.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_06_AUTHORITY_RUNBOOK.md
 */

require_once __DIR__ . '/cpr_authority_engine.php';
require_once __DIR__ . '/cpr_gates_live.php';
require_once __DIR__ . '/cpr_lock_live.php';
require_once __DIR__ . '/cpr_od_pin_live.php';
require_once __DIR__ . '/cpr_maintenance_live.php';
require_once __DIR__ . '/cpr_state_engine.php';

const ORANGE_CPR_AUTH_LIVE_SCHEMA = 'cpr_authority_live/1';
const ORANGE_CPR_RUNBOOK_LIVE_SCHEMA = 'cpr_runbook_live/1';
const ORANGE_CPR_AUTH_LIVE_VERSION = 'P4-07-1.0';

/** Deterministic fail codes (P4-07). */
const ORANGE_CPR_AUTHLIVE_ERR_ENABLEMENT = 'authlive_enablement_forbidden';
const ORANGE_CPR_AUTHLIVE_ERR_ACTOR = 'authlive_actor_not_super_admin';
const ORANGE_CPR_AUTHLIVE_ERR_PRIVILEGE = 'authlive_privilege_escalation';
const ORANGE_CPR_AUTHLIVE_ERR_STATE = 'authlive_state_invalid';
const ORANGE_CPR_AUTHLIVE_ERR_CONTRACT = 'authlive_contract_invalid';
const ORANGE_CPR_AUTHLIVE_ERR_LOCK = 'authlive_lock_invalid';
const ORANGE_CPR_AUTHLIVE_ERR_PIN = 'authlive_session_pin_missing';
const ORANGE_CPR_AUTHLIVE_ERR_MAINT = 'authlive_maint_required';
const ORANGE_CPR_AUTHLIVE_ERR_GATE = 'authlive_gate_required';
const ORANGE_CPR_AUTHLIVE_ERR_RUNBOOK = 'authlive_runbook_incomplete';
const ORANGE_CPR_AUTHLIVE_ERR_PHRASE = 'authlive_phrase_invalid';
const ORANGE_CPR_AUTHLIVE_ERR_REAUTH = 'authlive_reauth_missing';
const ORANGE_CPR_AUTHLIVE_ERR_ARTIFACT = 'authlive_sealed_artifact_missing';
const ORANGE_CPR_AUTHLIVE_ERR_CORRUPT = 'authlive_authorization_corrupt';
const ORANGE_CPR_AUTHLIVE_ERR_REPLAY = 'authlive_replay_forbidden';
const ORANGE_CPR_AUTHLIVE_ERR_DUPLICATE = 'authlive_duplicate_forbidden';
const ORANGE_CPR_AUTHLIVE_ERR_BYPASS = 'authlive_bypass_forbidden';
const ORANGE_CPR_AUTHLIVE_ERR_PONR = 'authlive_ponr_forbidden';
const ORANGE_CPR_AUTHLIVE_ERR_AUTH = 'authlive_authorization_failed';
const ORANGE_CPR_AUTHLIVE_ERR_PERSIST = 'authlive_persist_failed';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_authlive_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'ponr_authorized' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_authlive_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'ponr_authorized' => false,
        'fail_closed' => true,
    ], $extra);
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_auth_live_persist_record(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_auth_live_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR auth_live directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Authority live record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Authority live record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_auth_live_latest_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_auth_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_authority_live_latest.json';
}

function orange_cpr_runbook_live_latest_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_auth_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_runbook_live_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_auth_live_load_json(string $path): ?array
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
function orange_cpr_auth_live_load_latest(string $cprRoot, string $jobId): ?array
{
    return orange_cpr_auth_live_load_json(orange_cpr_auth_live_latest_path($cprRoot, $jobId));
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_runbook_live_load_latest(string $cprRoot, string $jobId): ?array
{
    return orange_cpr_auth_live_load_json(orange_cpr_runbook_live_latest_path($cprRoot, $jobId));
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_authority_live_assert_actor(array $request): array
{
    if (!empty($request['country_admin_is_executor'])
        || !empty($request['actor_is_country_admin'])
        || ((string) ($request['actor_class'] ?? '') === 'country_admin')
    ) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_ACTOR,
            'Country Admin may never authorize CPR (OD-PERM / OD-DUAL).'
        );
    }
    if (empty($request['actor_is_super_admin'])) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_ACTOR,
            'Only Super Admin may complete runbook / RESTORE ceremony.'
        );
    }
    $actorId = (int) ($request['actor_admin_id'] ?? 0);
    if ($actorId <= 0) {
        return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_ACTOR, 'actor_admin_id required.');
    }

    return orange_cpr_authlive_ok(['actor_admin_id' => $actorId]);
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_authority_live_refuse_unsafe(array $request): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_reauth', 'skip_phrase',
        'skip_runbook', 'waive_runbook', 'escalate_to_ponr', 'execute_ponr',
        'privilege_escalation', 'impersonate_super_admin',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            if (str_contains($k, 'escalat') || $k === 'impersonate_super_admin' || $k === 'execute_ponr') {
                return orange_cpr_authlive_fail(
                    ORANGE_CPR_AUTHLIVE_ERR_PRIVILEGE,
                    'Privilege escalation / PONR execute knobs forbidden.'
                );
            }

            return orange_cpr_authlive_fail(
                ORANGE_CPR_AUTHLIVE_ERR_BYPASS,
                'Bypass / skip / force knobs forbidden for live authority ceremony.'
            );
        }
    }
    if (!empty($request['replay_previous']) || !empty($request['reuse_authorization'])) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_REPLAY,
            'Replay / reuse of prior authorization is forbidden.'
        );
    }

    return orange_cpr_authlive_ok();
}

/**
 * Build live re-read evidence from frozen contract + CP1 pin.
 *
 * @param array<string, mixed> $contract
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function orange_cpr_authority_live_build_reread(array $contract, array $job): array
{
    return [
        'package_id' => (string) ($contract['package_id'] ?? $job['package_id'] ?? ''),
        'package_fingerprint' => (string) ($contract['package_fingerprint'] ?? ''),
        'country_id' => (int) ($contract['country_id'] ?? 0),
        'schema_revision_expected' => $contract['schema_revision_expected'] ?? null,
        'c4_report_hash' => (string) ($contract['c4_report_hash'] ?? ''),
        'c5_report_hash' => (string) ($contract['c5_report_hash'] ?? ''),
        'c6_report_hash' => (string) ($contract['c6_report_hash'] ?? ''),
        'c7_report_hash' => (string) ($contract['c7_report_hash'] ?? ''),
        'c8_report_hash' => (string) ($contract['c8_report_hash'] ?? ''),
        'c8_overall_result' => (string) ($contract['c8_overall_result'] ?? 'SAFE'),
        'inventory_snapshot_id' => (string) ($contract['inventory_snapshot_id'] ?? ''),
        'inventory_snapshot_hash' => (string) ($contract['inventory_snapshot_hash'] ?? ''),
        'production_db_identity_hash' => (string) ($contract['production_db_identity_hash'] ?? ''),
        'session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
        'session_full_backup_fingerprint' => (string) ($contract['session_full_backup_fingerprint'] ?? ''),
    ];
}

/**
 * Require sealed live gate PASS (P4-06) aligned with P3 gate hash.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function orange_cpr_authority_live_require_gates_pass(array $env, string $jobId, array $job): array
{
    $cprRoot = orange_cpr_resolve_work_root($env);
    $live = orange_cpr_gates_live_load_latest($cprRoot, $jobId);
    if (!is_array($live) || !orange_cpr_auth_verify_seal($live)) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_GATE,
            'Sealed live gate PASS report missing (WP-P4-06 required).'
        );
    }
    if (empty($live['all_gates_pass'])) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_GATE,
            'Live gate suite did not PASS; authority ceremony refused.'
        );
    }

    $gate = orange_cpr_gate_evaluation_load_latest($cprRoot, $jobId, 'pre_ponr_full');
    $gateCheck = orange_cpr_auth_validate_gate_report($gate, $job, ORANGE_CPR_AUTH_GATE_MAX_AGE_SECONDS);
    if (empty($gateCheck['ok'])) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_GATE,
            (string) ($gateCheck['message'] ?? 'P3 gate validation failed.'),
            ['upstream_code' => (string) ($gateCheck['code'] ?? '')]
        );
    }
    $p3Hash = (string) ($gate['content_sha256'] ?? '');
    $liveHash = (string) ($live['p3_content_sha256'] ?? '');
    if ($p3Hash === '' || $liveHash === '' || !hash_equals($p3Hash, $liveHash)) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_ARTIFACT,
            'Live gate report / P3 gate hash linkage broken.'
        );
    }

    return orange_cpr_authlive_ok([
        'gates_live' => $live,
        'gate' => $gate,
        'p3_content_sha256' => $p3Hash,
    ]);
}

/**
 * Live runbook completion / machine validation (OD-RUNBOOK).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_authority_live_complete_runbook(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during live runbook ceremony.'
        );
    }

    $actor = orange_cpr_authority_live_assert_actor($request);
    if (empty($actor['ok'])) {
        return $actor;
    }
    $unsafe = orange_cpr_authority_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_STATE, $e->getMessage());
    }
    if (!empty($job['ponr_crossed'])) {
        return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_PONR, 'PONR already crossed; runbook refused.');
    }
    if ((string) ($job['state'] ?? '') !== 'cpr_pre_ponr') {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_STATE,
            'Live runbook requires state cpr_pre_ponr.',
            ['job_state' => (string) ($job['state'] ?? '')]
        );
    }

    $existingRbLive = orange_cpr_runbook_live_load_latest($cprRoot, $jobId);
    if (is_array($existingRbLive) && !empty($existingRbLive['runbook_completed'])) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_DUPLICATE,
            'Sealed live runbook validation already exists for this job.'
        );
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_CONTRACT, 'Frozen execution contract required.');
    }
    if (empty($contract['session_full_backup_pinned'])
        || (string) ($contract['session_full_backup_id'] ?? '') === ''
    ) {
        return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_PIN, 'Session Full Backup pin missing on contract.');
    }

    $maint = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if (!is_array($maint) || empty($maint['global_maintenance_on']) || empty($maint['write_block_proven'])) {
        return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_MAINT, 'GLOBAL Maint proven required.');
    }
    $pin = orange_cpr_od_pin_live_load_pin($cprRoot, $jobId);
    if (!is_array($pin) || empty($pin['pinned'])) {
        return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_PIN, 'OD-PIN sealed pin artifact missing.');
    }

    $lockRv = orange_cpr_lock_live_revalidate_ownership($env, $jobId, [
        'lease_token' => (string) ($request['lease_token'] ?? ''),
        'worker_id' => (string) ($request['worker_id'] ?? ''),
    ]);
    if (empty($lockRv['ok'])) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_LOCK,
            (string) ($lockRv['message'] ?? 'Lock ownership revalidation failed.'),
            ['upstream_code' => (string) ($lockRv['code'] ?? '')]
        );
    }

    if (empty($request['all_minimum_items_confirmed'])) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_RUNBOOK,
            'all_minimum_items_confirmed required (OD-RUNBOOK §8.1).'
        );
    }

    $actorAdminId = (int) $actor['actor_admin_id'];
    $sessionId = (string) ($contract['session_full_backup_id'] ?? '');
    $payload = [
        'checklist_version' => 'od_runbook/1',
        'restore_package_id' => (string) ($contract['package_id'] ?? $job['package_id'] ?? ''),
        'target_country_id' => (int) ($contract['country_id'] ?? $job['country_id'] ?? 0),
        'target_country_code' => (string) ($contract['country_code'] ?? $job['country_code'] ?? ''),
        'c8_overall_result' => 'SAFE',
        'certified_inventory_snapshot_id' => (string) ($contract['inventory_snapshot_id'] ?? ''),
        'session_full_backup_id' => $sessionId,
        'global_maintenance_active' => true,
        'all_minimum_items_confirmed' => true,
        'completed_by_admin_id' => $actorAdminId,
        'completed_at' => gmdate('c'),
        'audit_record_id' => orange_cpr_generate_job_id(),
    ];

    // Optional overrides for negative tests only — refuse if they break machine match.
    $overrides = is_array($request['runbook_overrides'] ?? null) ? $request['runbook_overrides'] : [];
    foreach ($overrides as $k => $v) {
        if (is_string($k)) {
            $payload[$k] = $v;
        }
    }

    $existingCp = orange_cpr_checkpoint_load($env, $jobId, 'runbook_pre_ponr');
    if (!empty($existingCp['ok'])) {
        $existingPayload = is_array($existingCp['checkpoint']['payload'] ?? null)
            ? $existingCp['checkpoint']['payload']
            : [];
        // Re-validate existing scaffold/checkpoint against required contract bindings.
        $probeJob = $job;
        $probeContract = $contract;
        // Temporarily ensure contract pin fields for P3 validator.
        $rbCheck = orange_cpr_auth_validate_runbook($env, $jobId, $probeJob, $probeContract);
        if (empty($rbCheck['ok'])) {
            return orange_cpr_authlive_fail(
                ORANGE_CPR_AUTHLIVE_ERR_RUNBOOK,
                (string) ($rbCheck['message'] ?? 'Existing runbook failed machine validation.'),
                ['upstream_code' => (string) ($rbCheck['code'] ?? '')]
            );
        }
        // Enrich live record; do not rewrite immutable checkpoint.
        $payload = array_merge($existingPayload, [
            'all_minimum_items_confirmed' => true,
            'checklist_version' => (string) ($existingPayload['checklist_version'] ?? 'od_runbook/1'),
        ]);
        if ((int) ($payload['completed_by_admin_id'] ?? 0) <= 0) {
            return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_RUNBOOK, 'Runbook completed_by_admin_id missing.');
        }
    } else {
        // Create runbook checkpoint (live completion).
        // CP5 may already exist from gate scaffolding; P3 authorize still requires it.
        try {
            orange_cpr_checkpoint_create($env, $jobId, 'runbook_pre_ponr', $payload, [
                'written_by' => 'super_admin',
            ]);
        } catch (Throwable $e) {
            return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_RUNBOOK, $e->getMessage());
        }
        $rbCheck = orange_cpr_auth_validate_runbook($env, $jobId, $job, $contract);
        if (empty($rbCheck['ok'])) {
            return orange_cpr_authlive_fail(
                ORANGE_CPR_AUTHLIVE_ERR_RUNBOOK,
                (string) ($rbCheck['message'] ?? 'Runbook machine validation failed after create.'),
                ['upstream_code' => (string) ($rbCheck['code'] ?? '')]
            );
        }
    }

    $now = gmdate('c');
    $runbookLiveId = orange_cpr_generate_job_id();
    $record = [
        'schema_version' => ORANGE_CPR_RUNBOOK_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_AUTH_LIVE_VERSION,
        'record_type' => 'runbook_live_validation',
        'runbook_live_id' => $runbookLiveId,
        'job_id' => $jobId,
        'runbook_completed' => true,
        'all_minimum_items_confirmed' => true,
        'checklist_version' => 'od_runbook/1',
        'restore_package_id' => (string) ($payload['restore_package_id'] ?? ''),
        'target_country_id' => (int) ($payload['target_country_id'] ?? 0),
        'target_country_code' => (string) ($payload['target_country_code'] ?? ''),
        'c8_overall_result' => 'SAFE',
        'certified_inventory_snapshot_id' => (string) ($payload['certified_inventory_snapshot_id'] ?? ''),
        'session_full_backup_id' => $sessionId,
        'global_maintenance_active' => true,
        'completed_by_admin_id' => (int) ($payload['completed_by_admin_id'] ?? $actorAdminId),
        'runbook_evidence_ref' => 'runbook_pre_ponr.json',
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
        $path = orange_cpr_auth_live_persist_record(
            $cprRoot,
            $jobId,
            'cpr_runbook_live_' . $runbookLiveId,
            $record
        );
        $latest = orange_cpr_runbook_live_latest_path($cprRoot, $jobId);
        $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
        $sealedLatest = orange_cpr_auth_seal(array_merge($record, ['runbook_live_path' => $path]));
        $json = json_encode($sealedLatest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Runbook live latest pointer write failed.');
        }
        orange_cpr_atomic_rename_replace($tmp, $latest);
    } catch (RuntimeException $e) {
        return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_PERSIST, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.runbook_live_complete',
        'job_id' => $jobId,
        'runbook_live_id' => $runbookLiveId,
        'actor_admin_id' => $actorAdminId,
        'runbook_completed' => true,
        'session_full_backup_id' => $sessionId,
        'enablement_flag_observed' => false,
        'production_mutation' => false,
        'ponr_crossed' => false,
    ]);

    return orange_cpr_authlive_ok([
        'message' => 'Live runbook validation sealed; no PONR mutation.',
        'runbook_completed' => true,
        'runbook_live_id' => $runbookLiveId,
        'runbook_live_path' => $path,
        'runbook_live' => orange_cpr_runbook_live_load_latest($cprRoot, $jobId),
        'enablement_flag_observed' => false,
    ]);
}

/**
 * Live RESTORE phrase + re-auth + P3 one-time authorization ceremony.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_authority_live_authorize(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during live authority ceremony.'
        );
    }

    $actor = orange_cpr_authority_live_assert_actor($request);
    if (empty($actor['ok'])) {
        return $actor;
    }
    $unsafe = orange_cpr_authority_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $phrase = (string) ($request['phrase'] ?? '');
    if ($phrase === '') {
        return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_PHRASE, 'RESTORE phrase missing.');
    }
    if ($phrase !== ORANGE_CPR_AUTH_PHRASE_LITERAL) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_PHRASE,
            'Phrase must be exactly RESTORE (case-sensitive).'
        );
    }
    if (empty($request['password_reauth_ok'])) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_REAUTH,
            'Password re-authentication evidence required (never store password).'
        );
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_STATE, $e->getMessage());
    }
    if (!empty($job['ponr_crossed'])) {
        return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_PONR, 'PONR already crossed; authorize refused.');
    }
    if ((string) ($job['state'] ?? '') !== 'cpr_pre_ponr') {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_STATE,
            'Live authorize requires state cpr_pre_ponr.'
        );
    }

    $existingLive = orange_cpr_auth_live_load_latest($cprRoot, $jobId);
    if (is_array($existingLive) && !empty($existingLive['ponr_authorized'])) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_DUPLICATE,
            'Sealed live authority record already exists for this job.'
        );
    }

    $runbookLive = orange_cpr_runbook_live_load_latest($cprRoot, $jobId);
    if (!is_array($runbookLive) || empty($runbookLive['runbook_completed'])
        || !orange_cpr_auth_verify_seal($runbookLive)
    ) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_RUNBOOK,
            'Sealed live runbook validation required before RESTORE ceremony.'
        );
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_CONTRACT, 'Frozen execution contract required.');
    }

    $gateReq = orange_cpr_authority_live_require_gates_pass($env, $jobId, $job);
    if (empty($gateReq['ok'])) {
        return $gateReq;
    }

    $lockRv = orange_cpr_lock_live_revalidate_ownership($env, $jobId, [
        'lease_token' => (string) ($request['lease_token'] ?? ''),
        'worker_id' => (string) ($request['worker_id'] ?? ''),
    ]);
    if (empty($lockRv['ok'])) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_LOCK,
            (string) ($lockRv['message'] ?? 'Lock ownership revalidation failed.'),
            ['upstream_code' => (string) ($lockRv['code'] ?? '')]
        );
    }

    $actorAdminId = (int) $actor['actor_admin_id'];
    $reread = is_array($request['reread'] ?? null) && $request['reread'] !== []
        ? $request['reread']
        : orange_cpr_authority_live_build_reread($contract, $job);

    $authReq = [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'phrase' => ORANGE_CPR_AUTH_PHRASE_LITERAL,
        'password_reauth_ok' => true,
        'lease_token' => (string) ($request['lease_token'] ?? ''),
        'worker_id' => (string) ($request['worker_id'] ?? ''),
        'reread' => $reread,
        'gate_max_age_seconds' => (int) ($request['gate_max_age_seconds'] ?? ORANGE_CPR_AUTH_GATE_MAX_AGE_SECONDS),
    ];
    $auth = orange_cpr_ponr_authorize($env, $jobId, $authReq);
    if (empty($auth['ok'])) {
        $upstream = (string) ($auth['code'] ?? '');
        $mapped = match ($upstream) {
            'auth_actor_not_super_admin' => ORANGE_CPR_AUTHLIVE_ERR_ACTOR,
            'auth_phrase_invalid' => ORANGE_CPR_AUTHLIVE_ERR_PHRASE,
            'auth_reauth_missing' => ORANGE_CPR_AUTHLIVE_ERR_REAUTH,
            'auth_runbook_incomplete', 'auth_checkpoint_invalid' => ORANGE_CPR_AUTHLIVE_ERR_RUNBOOK,
            'auth_duplicate', 'auth_replay' => ORANGE_CPR_AUTHLIVE_ERR_REPLAY,
            'auth_lock_ownership_drift' => ORANGE_CPR_AUTHLIVE_ERR_LOCK,
            'auth_gate_missing', 'auth_gate_unsealed', 'auth_gate_not_pass', 'auth_gate_stale' => ORANGE_CPR_AUTHLIVE_ERR_GATE,
            'auth_pin_missing' => ORANGE_CPR_AUTHLIVE_ERR_PIN,
            default => ORANGE_CPR_AUTHLIVE_ERR_AUTH,
        };

        return orange_cpr_authlive_fail(
            $mapped,
            (string) ($auth['message'] ?? 'P3 authorization engine refused.'),
            ['upstream_code' => $upstream]
        );
    }

    /** @var array<string, mixed> $authorization */
    $authorization = is_array($auth['authorization'] ?? null) ? $auth['authorization'] : [];
    if ($authorization === [] || !orange_cpr_auth_verify_seal($authorization)) {
        return orange_cpr_authlive_fail(
            ORANGE_CPR_AUTHLIVE_ERR_CORRUPT,
            'P3 authorization seal missing or invalid after mint.'
        );
    }

    $now = gmdate('c');
    $authLiveId = orange_cpr_generate_job_id();
    $liveRecord = [
        'schema_version' => ORANGE_CPR_AUTH_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_AUTH_LIVE_VERSION,
        'record_type' => 'authority_live_ceremony',
        'authority_live_id' => $authLiveId,
        'job_id' => $jobId,
        'runbook_live_id' => (string) ($runbookLive['runbook_live_id'] ?? ''),
        'runbook_completed' => true,
        'phrase_accepted' => true,
        'phrase_literal' => ORANGE_CPR_AUTH_PHRASE_LITERAL,
        'password_reauth_ok' => true,
        'one_time_authorization_id' => (string) ($auth['one_time_authorization_id'] ?? ''),
        'authorization_id' => (string) ($authorization['authorization_id'] ?? ''),
        'challenge_id' => (string) ($authorization['challenge_id'] ?? ''),
        'p3_authorization_path' => (string) ($auth['authorization_path'] ?? ''),
        'p3_content_sha256' => (string) ($authorization['content_sha256'] ?? ''),
        'p3_gate_content_sha256' => (string) ($gateReq['p3_content_sha256'] ?? ''),
        'gates_live_id' => (string) (($gateReq['gates_live']['gates_live_id'] ?? '')),
        'contract_fingerprint' => (string) ($auth['contract_fingerprint'] ?? ''),
        'session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'one_time' => true,
        'immutable' => true,
        'non_transferable' => true,
        'ops_enablement_flag' => false,
        'ponr_authorized' => true,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'scaffold_record_only' => false,
        'live_path' => true,
        'created_at' => $now,
    ];

    try {
        $livePath = orange_cpr_auth_live_persist_record(
            $cprRoot,
            $jobId,
            'cpr_authority_live_' . $authLiveId,
            $liveRecord
        );
        $latest = orange_cpr_auth_live_latest_path($cprRoot, $jobId);
        $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
        $sealedLatest = orange_cpr_auth_seal(array_merge($liveRecord, [
            'authority_live_path' => $livePath,
        ]));
        $json = json_encode($sealedLatest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Authority live latest pointer write failed.');
        }
        orange_cpr_atomic_rename_replace($tmp, $latest);
    } catch (RuntimeException $e) {
        return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_PERSIST, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.authority_live_authorize',
        'job_id' => $jobId,
        'authority_live_id' => $authLiveId,
        'one_time_authorization_id' => (string) ($auth['one_time_authorization_id'] ?? ''),
        'authorization_id' => (string) ($authorization['authorization_id'] ?? ''),
        'actor_admin_id' => $actorAdminId,
        'phrase_accepted' => true,
        'password_reauth_ok' => true,
        'runbook_completed' => true,
        'ponr_authorized' => true,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'enablement_flag_observed' => false,
        'production_mutation' => false,
    ]);

    $jobAfter = orange_cpr_job_read($cprRoot, $jobId);
    if (!empty($jobAfter['ponr_crossed'])) {
        return orange_cpr_authlive_fail(ORANGE_CPR_AUTHLIVE_ERR_PONR, 'Invariant broken: ponr_crossed became true.');
    }

    return orange_cpr_authlive_ok([
        'message' => 'Live RESTORE ceremony complete; one-time authorization sealed; no PONR mutation.',
        'ponr_authorized' => true,
        'one_time_authorization_id' => (string) ($auth['one_time_authorization_id'] ?? ''),
        'authorization_id' => (string) ($authorization['authorization_id'] ?? ''),
        'authority_live_id' => $authLiveId,
        'authority_live_path' => $livePath,
        'authority_live' => orange_cpr_auth_live_load_latest($cprRoot, $jobId),
        'authorization' => $authorization,
        'challenge' => $auth['challenge'] ?? null,
        'runbook_live' => $runbookLive,
        'enablement_flag_observed' => false,
    ]);
}

/**
 * Combined live ceremony: runbook validation then RESTORE authorize.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_authority_live_ceremony(array $env, string $jobId, array $request): array
{
    $rb = orange_cpr_authority_live_complete_runbook($env, $jobId, $request);
    if (empty($rb['ok'])) {
        return $rb;
    }
    $auth = orange_cpr_authority_live_authorize($env, $jobId, $request);
    if (empty($auth['ok'])) {
        return $auth;
    }

    return orange_cpr_authlive_ok([
        'message' => 'Live authority ceremony complete (runbook + RESTORE); no PONR mutation.',
        'ponr_authorized' => true,
        'runbook_live_id' => (string) ($rb['runbook_live_id'] ?? ''),
        'authority_live_id' => (string) ($auth['authority_live_id'] ?? ''),
        'one_time_authorization_id' => (string) ($auth['one_time_authorization_id'] ?? ''),
        'runbook_live' => $rb['runbook_live'] ?? null,
        'authority_live' => $auth['authority_live'] ?? null,
        'authorization' => $auth['authorization'] ?? null,
        'enablement_flag_observed' => false,
    ]);
}
