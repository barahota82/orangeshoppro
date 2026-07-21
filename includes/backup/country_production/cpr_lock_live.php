<?php

declare(strict_types=1);

/**
 * CPR Live Pre-PONR Lock Ownership & Heartbeat (WP-P4-05).
 *
 * Live orchestration over P3 lock engine: acquire / heartbeat / ownership revalidate /
 * stale detect / Super Admin manual clear — after CP4 + OD-PIN (CP1).
 * Sealed lifecycle + manual-clear artifacts. No DELETE/IMPORT/PONR/production mutation.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P4_05_LOCK_LIVE.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_05_LOCK_FORMATS.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P3_05_LOCK_SCAFFOLD.md
 */

require_once __DIR__ . '/cpr_lock_engine.php';
require_once __DIR__ . '/cpr_maintenance_live.php';
require_once __DIR__ . '/cpr_od_pin_live.php';
require_once __DIR__ . '/cpr_checkpoint_engine.php';
require_once __DIR__ . '/cpr_gate_evaluator.php';
require_once __DIR__ . '/cpr_authority_engine.php';
require_once __DIR__ . '/cpr_state_engine.php';

const ORANGE_CPR_LOCK_LIVE_SCHEMA = 'cpr_lock_live/1';
const ORANGE_CPR_LOCK_LIVE_VERSION = 'P4-05-1.0';

/** Deterministic fail codes (P4-05). */
const ORANGE_CPR_LOCKLIVE_ERR_ENABLEMENT = 'locklive_enablement_forbidden';
const ORANGE_CPR_LOCKLIVE_ERR_ACTOR = 'locklive_actor_not_super_admin';
const ORANGE_CPR_LOCKLIVE_ERR_STATE = 'locklive_state_invalid';
const ORANGE_CPR_LOCKLIVE_ERR_CONTRACT = 'locklive_contract_invalid';
const ORANGE_CPR_LOCKLIVE_ERR_IDENTITY = 'locklive_identity_drift';
const ORANGE_CPR_LOCKLIVE_ERR_MAINT = 'locklive_maint_required';
const ORANGE_CPR_LOCKLIVE_ERR_PIN = 'locklive_od_pin_required';
const ORANGE_CPR_LOCKLIVE_ERR_CHECKPOINT = 'locklive_checkpoint_required';
const ORANGE_CPR_LOCKLIVE_ERR_OWNERSHIP = 'locklive_ownership_drift';
const ORANGE_CPR_LOCKLIVE_ERR_MISSING = 'locklive_lock_missing';
const ORANGE_CPR_LOCKLIVE_ERR_CORRUPT = 'locklive_lock_corrupt';
const ORANGE_CPR_LOCKLIVE_ERR_CONFLICT = 'locklive_conflict';
const ORANGE_CPR_LOCKLIVE_ERR_FULL_DR = 'locklive_blocked_full_dr';
const ORANGE_CPR_LOCKLIVE_ERR_C6 = 'locklive_blocked_c6';
const ORANGE_CPR_LOCKLIVE_ERR_BACKUP = 'locklive_blocked_backup_runner';
const ORANGE_CPR_LOCKLIVE_ERR_HEARTBEAT = 'locklive_heartbeat_failed';
const ORANGE_CPR_LOCKLIVE_ERR_ACQUIRE = 'locklive_acquire_failed';
const ORANGE_CPR_LOCKLIVE_ERR_STALE = 'locklive_not_stale';
const ORANGE_CPR_LOCKLIVE_ERR_CLEAR = 'locklive_manual_clear_failed';
const ORANGE_CPR_LOCKLIVE_ERR_REASON = 'locklive_clear_reason_required';
const ORANGE_CPR_LOCKLIVE_ERR_POST_PONR = 'locklive_post_ponr_clear_forbidden';
const ORANGE_CPR_LOCKLIVE_ERR_BYPASS = 'locklive_bypass_forbidden';
const ORANGE_CPR_LOCKLIVE_ERR_GATE = 'locklive_gate_g26_failed';
const ORANGE_CPR_LOCKLIVE_ERR_DUPLICATE = 'locklive_persist_failed';
const ORANGE_CPR_LOCKLIVE_ERR_PONR = 'locklive_ponr_forbidden';
const ORANGE_CPR_LOCKLIVE_ERR_AUTO = 'locklive_auto_unlock_forbidden';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_locklive_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'auto_unlock' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_locklive_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'auto_unlock' => false,
        'fail_closed' => true,
    ], $extra);
}

/**
 * @return array<string, mixed>
 */
function orange_cpr_lock_live_refuse_auto_unlock(): array
{
    return orange_cpr_locklive_fail(
        ORANGE_CPR_LOCKLIVE_ERR_AUTO,
        'Automatic lock unlock is forbidden (OD-LOCK-TTL); Super Admin manual clear only pre-PONR.'
    );
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_lock_live_assert_actor(array $request, bool $requireSuperAdmin = true): array
{
    if (!empty($request['country_admin_is_executor']) || ($request['actor'] ?? '') === ORANGE_CPR_ACTOR_COUNTRY_ADMIN) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_ACTOR, 'Country Admin cannot control CPR locks (OD-PERM).');
    }
    if ($requireSuperAdmin) {
        if (empty($request['actor_is_super_admin']) && ($request['actor'] ?? '') !== ORANGE_CPR_ACTOR_SUPER_ADMIN) {
            return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_ACTOR, 'Super Admin required for this lock live action.');
        }
        $actorId = (int) ($request['actor_admin_id'] ?? 0);
        if ($actorId <= 0) {
            return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_ACTOR, 'actor_admin_id required.');
        }

        return orange_cpr_locklive_ok(['actor_admin_id' => $actorId]);
    }

    return orange_cpr_locklive_ok(['actor_admin_id' => (int) ($request['actor_admin_id'] ?? 0)]);
}

/**
 * Persist sealed lock_live artifact.
 *
 * @param array<string, mixed> $record
 */
function orange_cpr_lock_live_persist_record(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_lock_live_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR lock_live directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Lock live record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Lock live record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

/**
 * Fail-closed lock file read distinguishing missing vs corrupt.
 *
 * @return array<string, mixed>
 */
function orange_cpr_lock_live_read_strict(string $cprRoot): array
{
    $path = orange_cpr_lock_file_path($cprRoot);
    if (!is_file($path)) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_MISSING, 'CPR lock file not held.');
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_CORRUPT, 'CPR lock file empty/unreadable (fail-closed).');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_CORRUPT, 'CPR lock JSON corrupt (fail-closed).');
    }
    $schema = orange_cpr_lock_validate_payload($data);
    if (empty($schema['ok'])) {
        return orange_cpr_locklive_fail(
            ORANGE_CPR_LOCKLIVE_ERR_CORRUPT,
            'CPR lock schema invalid (fail-closed).',
            ['upstream_code' => (string) ($schema['code'] ?? '')]
        );
    }

    return orange_cpr_locklive_ok(['lock' => $data, 'path' => $path, 'raw_sha256' => hash('sha256', $raw)]);
}

/**
 * Map P3 concurrency / lock codes into live codes where useful.
 */
function orange_cpr_lock_live_map_upstream(string $code): string
{
    return match ($code) {
        ORANGE_CPR_LOCK_ERR_FULL_DR => ORANGE_CPR_LOCKLIVE_ERR_FULL_DR,
        ORANGE_CPR_LOCK_ERR_C6 => ORANGE_CPR_LOCKLIVE_ERR_C6,
        ORANGE_CPR_LOCK_ERR_BACKUP => ORANGE_CPR_LOCKLIVE_ERR_BACKUP,
        ORANGE_CPR_LOCK_ERR_HELD, ORANGE_CPR_LOCK_ERR_CPR_ACTIVE => ORANGE_CPR_LOCKLIVE_ERR_CONFLICT,
        ORANGE_CPR_LOCK_ERR_BYPASS => ORANGE_CPR_LOCKLIVE_ERR_BYPASS,
        ORANGE_CPR_LOCK_ERR_ENABLEMENT => ORANGE_CPR_LOCKLIVE_ERR_ENABLEMENT,
        ORANGE_CPR_LOCK_ERR_IDENTITY, ORANGE_CPR_LOCK_ERR_LEASE => ORANGE_CPR_LOCKLIVE_ERR_OWNERSHIP,
        ORANGE_CPR_LOCK_ERR_POST_PONR, ORANGE_CPR_LOCK_ERR_POST_PONR_CLEAR => ORANGE_CPR_LOCKLIVE_ERR_POST_PONR,
        ORANGE_CPR_LOCK_ERR_AUDIT => ORANGE_CPR_LOCKLIVE_ERR_REASON,
        ORANGE_CPR_LOCK_ERR_NOT_STALE => ORANGE_CPR_LOCKLIVE_ERR_STALE,
        ORANGE_CPR_LOCK_ERR_ACTOR => ORANGE_CPR_LOCKLIVE_ERR_ACTOR,
        ORANGE_CPR_LOCK_ERR_MISSING => ORANGE_CPR_LOCKLIVE_ERR_MISSING,
        ORANGE_CPR_LOCK_ERR_SCHEMA => ORANGE_CPR_LOCKLIVE_ERR_CORRUPT,
        default => ORANGE_CPR_LOCKLIVE_ERR_ACQUIRE,
    };
}

/**
 * Preconditions for live pre-PONR lock acquire (after CP4 + OD-PIN).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_lock_live_assert_preconditions(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_ENABLEMENT, $e->getMessage());
    }

    if (!empty($request['force']) || !empty($request['bypass_exclusion'])) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_BYPASS, 'Exclusion bypass forbidden (OD-LOCK-CROSS / OD-LOCK-SHADOW).');
    }

    $actor = orange_cpr_lock_live_assert_actor($request, true);
    if (empty($actor['ok'])) {
        return $actor;
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_STATE, $e->getMessage());
    }

    if (!empty($job['ponr_crossed'])) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_PONR, 'PONR already crossed; P4-05 live lock path refused.');
    }

    $state = (string) ($job['state'] ?? '');
    if ($state !== 'cpr_pre_ponr') {
        return orange_cpr_locklive_fail(
            ORANGE_CPR_LOCKLIVE_ERR_STATE,
            'Live pre-PONR lock acquire requires state cpr_pre_ponr (after OD-PIN).',
            ['job_state' => $state]
        );
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_CONTRACT, 'Frozen execution contract required.');
    }
    if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_IDENTITY, 'Job/contract identity drift (fail-closed).');
    }
    if (empty($contract['session_full_backup_pinned'])) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_PIN, 'Session Full Backup pin required on contract before lock.');
    }

    foreach (['CP4', 'CP1'] as $cp) {
        if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, $cp)) {
            return orange_cpr_locklive_fail(
                ORANGE_CPR_LOCKLIVE_ERR_CHECKPOINT,
                'Checkpoint ' . $cp . ' required before live lock acquire.',
                ['missing_checkpoint' => $cp]
            );
        }
    }

    $maint = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if (!is_array($maint)
        || empty($maint['global_maintenance_on'])
        || (string) ($maint['maint_scope'] ?? '') !== 'GLOBAL'
        || empty($maint['write_block_proven'])
    ) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_MAINT, 'GLOBAL Maint proven (CP4) required before live lock.');
    }

    $pin = orange_cpr_od_pin_live_load_pin($cprRoot, $jobId);
    if (!is_array($pin) || empty($pin['pinned']) || empty($pin['immutable'])) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_PIN, 'Immutable OD-PIN pin artifact required before live lock.');
    }

    return orange_cpr_locklive_ok([
        'actor_admin_id' => (int) $actor['actor_admin_id'],
        'job' => $job,
        'contract' => $contract,
        'maint_state' => $maint,
        'pin' => $pin,
        'cpr_root' => $cprRoot,
    ]);
}

/**
 * Live acquire — after CP4 + CP1; enforces peer exclusion via P3 engine.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_lock_live_acquire(array $env, string $jobId, array $request): array
{
    $pre = orange_cpr_lock_live_assert_preconditions($env, $jobId, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }

    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    $cprRoot = (string) $pre['cpr_root'];
    $actorAdminId = (int) $pre['actor_admin_id'];

    $workerId = trim((string) ($request['worker_id'] ?? ''));
    if ($workerId === '') {
        $workerId = 'locklive-' . orange_cpr_generate_job_id();
    }

    $acq = orange_cpr_lock_acquire($env, $jobId, [
        'worker_id' => $workerId,
        'owner_class' => (string) ($request['owner_class'] ?? 'super_admin_procedure'),
        'actor_admin_id' => $actorAdminId,
        'actor_username' => $request['actor_username'] ?? null,
        'lease_token' => (string) ($request['lease_token'] ?? ''),
        'deployment_id' => $request['deployment_id'] ?? null,
        'force' => !empty($request['force']),
        'bypass_exclusion' => !empty($request['bypass_exclusion']),
    ]);
    if (empty($acq['ok'])) {
        $up = (string) ($acq['code'] ?? '');

        return orange_cpr_locklive_fail(
            orange_cpr_lock_live_map_upstream($up),
            (string) ($acq['message'] ?? 'Live lock acquire failed.'),
            ['upstream_code' => $up]
        );
    }

    /** @var array<string, mixed> $lock */
    $lock = $acq['lock'] ?? [];
    $lease = (string) ($acq['lease_token'] ?? ($lock['ownership']['lease_token'] ?? ''));

    // Bind contract revision observed on lock vs current contract.
    if ((int) ($lock['contract_revision'] ?? -1) !== (int) ($contract['contract_revision'] ?? -2)) {
        return orange_cpr_locklive_fail(
            ORANGE_CPR_LOCKLIVE_ERR_IDENTITY,
            'Lock contract_revision drifted vs execution contract (fail-closed).'
        );
    }

    $now = gmdate('c');
    $liveId = orange_cpr_generate_job_id();
    $record = [
        'schema_version' => ORANGE_CPR_LOCK_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_LOCK_LIVE_VERSION,
        'record_type' => 'lock_live_acquire',
        'lock_live_id' => $liveId,
        'job_id' => $jobId,
        'package_id' => (string) ($job['package_id'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'contract_revision' => (int) ($contract['contract_revision'] ?? 0),
        'session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
        'phase' => (string) ($lock['phase'] ?? ''),
        'ponr_crossed' => !empty($lock['ponr_crossed']),
        'maint_global_required' => !empty($lock['maint_global_required']),
        'lease_token' => $lease,
        'worker_id' => (string) ($lock['ownership']['worker_id'] ?? $workerId),
        'owner_class' => (string) ($lock['ownership']['owner_class'] ?? ''),
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'cp4_present' => true,
        'cp1_present' => true,
        'heartbeat_at' => (string) ($lock['heartbeat_at'] ?? $now),
        'acquired_at' => (string) ($lock['acquired_at'] ?? $now),
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'created_at' => $now,
    ];

    try {
        $path = orange_cpr_lock_live_persist_record($cprRoot, $jobId, 'cpr_lock_live_acquire_' . $liveId, $record);
        $latest = orange_cpr_lock_live_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . 'cpr_lock_live_latest.json';
        $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
        $sealed = orange_cpr_auth_seal(array_merge($record, ['lifecycle_record_path' => $path]));
        $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Lock live latest pointer write failed.');
        }
        orange_cpr_atomic_rename_replace($tmp, $latest);
    } catch (RuntimeException $e) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_DUPLICATE, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.lock_live_acquire',
        'job_id' => $jobId,
        'lock_live_id' => $liveId,
        'actor_admin_id' => $actorAdminId,
        'phase' => (string) ($lock['phase'] ?? ''),
        'contract_revision' => (int) ($contract['contract_revision'] ?? 0),
        'ponr_crossed' => false,
        'enablement_flag_observed' => false,
        'production_mutation' => false,
    ]);

    // Gate Engine surface: G26 should PASS with held lock.
    $g26 = orange_cpr_gate_evaluate_one('G26', orange_cpr_gate_build_context($env, $jobId, []));
    if (($g26['result'] ?? '') !== ORANGE_CPR_GATE_PASS) {
        return orange_cpr_locklive_fail(
            ORANGE_CPR_LOCKLIVE_ERR_GATE,
            'G26 failed after live lock acquire.',
            ['gate' => $g26]
        );
    }

    return orange_cpr_locklive_ok([
        'message' => 'Live pre-PONR CPR lock acquired; ownership bound to job/contract.',
        'lock' => $lock,
        'lease_token' => $lease,
        'worker_id' => (string) ($lock['ownership']['worker_id'] ?? $workerId),
        'lifecycle_path' => $path,
        'lock_live_id' => $liveId,
        'g26' => $g26,
        'revalidated_for_gates' => true,
    ]);
}

/**
 * Live heartbeat — validated lease + auditable sealed event.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_lock_live_heartbeat(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_ENABLEMENT, $e->getMessage());
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    $strict = orange_cpr_lock_live_read_strict($cprRoot);
    if (empty($strict['ok'])) {
        return $strict;
    }

    $lease = (string) ($request['lease_token'] ?? '');
    $workerId = (string) ($request['worker_id'] ?? '');
    $hb = orange_cpr_lock_heartbeat($env, $jobId, [
        'lease_token' => $lease,
        'worker_id' => $workerId,
    ]);
    if (empty($hb['ok'])) {
        return orange_cpr_locklive_fail(
            orange_cpr_lock_live_map_upstream((string) ($hb['code'] ?? '')),
            (string) ($hb['message'] ?? 'Heartbeat failed.'),
            ['upstream_code' => (string) ($hb['code'] ?? '')]
        );
    }

    /** @var array<string, mixed> $lock */
    $lock = $hb['lock'];
    $now = gmdate('c');
    $liveId = orange_cpr_generate_job_id();
    $record = [
        'schema_version' => ORANGE_CPR_LOCK_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_LOCK_LIVE_VERSION,
        'record_type' => 'lock_live_heartbeat',
        'lock_live_id' => $liveId,
        'job_id' => $jobId,
        'phase' => (string) ($lock['phase'] ?? ''),
        'heartbeat_at' => (string) ($lock['heartbeat_at'] ?? $now),
        'lease_token' => (string) ($lock['ownership']['lease_token'] ?? ''),
        'worker_id' => (string) ($lock['ownership']['worker_id'] ?? ''),
        'ponr_crossed' => !empty($lock['ponr_crossed']),
        'contract_revision' => (int) ($lock['contract_revision'] ?? 0),
        'last_checkpoint_id' => $lock['last_checkpoint_id'] ?? null,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'created_at' => $now,
    ];

    try {
        $path = orange_cpr_lock_live_persist_record($cprRoot, $jobId, 'cpr_lock_live_heartbeat_' . $liveId, $record);
    } catch (RuntimeException $e) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_DUPLICATE, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.lock_live_heartbeat',
        'job_id' => $jobId,
        'lock_live_id' => $liveId,
        'heartbeat_at' => $record['heartbeat_at'],
        'phase' => $record['phase'],
        'enablement_flag_observed' => false,
    ]);

    return orange_cpr_locklive_ok([
        'message' => 'Live heartbeat accepted and sealed.',
        'lock' => $lock,
        'lifecycle_path' => $path,
        'lock_live_id' => $liveId,
    ]);
}

/**
 * Revalidate ownership before gate/authority steps (fail-closed).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_lock_live_revalidate_ownership(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_ENABLEMENT, $e->getMessage());
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    $strict = orange_cpr_lock_live_read_strict($cprRoot);
    if (empty($strict['ok'])) {
        return $strict;
    }

    /** @var array<string, mixed> $lock */
    $lock = $strict['lock'];
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
        $contract = orange_cpr_contract_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_STATE, $e->getMessage());
    }

    if ((string) ($lock['job_id'] ?? '') !== $jobId) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_OWNERSHIP, 'Lock job_id ownership drift.');
    }
    if ((int) ($lock['country_id'] ?? -1) !== (int) ($job['country_id'] ?? -2)) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_OWNERSHIP, 'Lock country_id ownership drift.');
    }
    if ((string) ($lock['package_id'] ?? '') !== (string) ($job['package_id'] ?? '')
        || (string) ($lock['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
    ) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_OWNERSHIP, 'Lock package identity ownership drift.');
    }
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_CONTRACT, 'Contract missing during lock revalidate.');
    }
    if ((int) ($lock['contract_revision'] ?? -1) !== (int) ($contract['contract_revision'] ?? -2)) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_OWNERSHIP, 'Lock/contract revision ownership drift.');
    }

    $lease = (string) ($request['lease_token'] ?? '');
    $heldLease = (string) ($lock['ownership']['lease_token'] ?? '');
    if ($lease !== '' && ($heldLease === '' || !hash_equals($heldLease, $lease))) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_OWNERSHIP, 'Lease token ownership drift.');
    }
    $workerId = (string) ($request['worker_id'] ?? '');
    $heldWorker = (string) ($lock['ownership']['worker_id'] ?? '');
    if ($workerId !== '' && ($heldWorker === '' || $heldWorker !== $workerId)) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_OWNERSHIP, 'Worker id ownership drift.');
    }

    if (!empty($lock['ponr_crossed']) || !empty($job['ponr_crossed'])) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_PONR, 'PONR crossed observed during pre-PONR revalidate.');
    }

    // Authority-facing evidence slice (no authorize here).
    $authorityEvidence = [
        'lock_held' => true,
        'job_id' => $jobId,
        'lease_token_bound' => $heldLease !== '',
        'contract_revision' => (int) ($lock['contract_revision'] ?? 0),
        'phase' => (string) ($lock['phase'] ?? ''),
        'session_full_backup_pinned' => !empty($contract['session_full_backup_pinned']),
        'country_admin_is_executor' => false,
    ];

    $g26 = orange_cpr_gate_evaluate_one('G26', orange_cpr_gate_build_context($env, $jobId, []));
    if (($g26['result'] ?? '') !== ORANGE_CPR_GATE_PASS) {
        return orange_cpr_locklive_fail(
            ORANGE_CPR_LOCKLIVE_ERR_GATE,
            'G26 failed during ownership revalidation.',
            ['gate' => $g26]
        );
    }

    return orange_cpr_locklive_ok([
        'message' => 'Lock ownership revalidated for gate/authority steps.',
        'lock' => $lock,
        'authority_evidence' => $authorityEvidence,
        'g26' => $g26,
        'ownership_ok' => true,
        'revalidated_for_gates' => true,
        'revalidated_for_authority' => true,
    ]);
}

/**
 * Stale observation (never auto-unlock).
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_lock_live_detect_stale(array $env, string $jobId): array
{
    $cprRoot = orange_cpr_resolve_work_root($env);
    $strict = orange_cpr_lock_live_read_strict($cprRoot);
    if (empty($strict['ok'])) {
        return $strict;
    }
    /** @var array<string, mixed> $lock */
    $lock = $strict['lock'];
    if ((string) ($lock['job_id'] ?? '') !== $jobId) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_OWNERSHIP, 'Stale detect job_id mismatch.');
    }
    $stale = orange_cpr_lock_stale_classify($lock);

    return orange_cpr_locklive_ok([
        'stale' => $stale,
        'is_stale' => !empty($stale['is_heartbeat_stale']),
        'auto_unlock_authorized' => false,
        'ponr_crossed' => !empty($lock['ponr_crossed']),
        'lock' => $lock,
    ]);
}

/**
 * Super Admin pre-PONR manual clear — reason + sealed audit required.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_lock_live_manual_clear(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_ENABLEMENT, $e->getMessage());
    }

    if (!empty($request['auto_unlock']) || !empty($request['ttl_unlock'])) {
        return orange_cpr_lock_live_refuse_auto_unlock();
    }

    $actor = orange_cpr_lock_live_assert_actor($request, true);
    if (empty($actor['ok'])) {
        return $actor;
    }

    $reason = trim((string) ($request['reason'] ?? ''));
    if (strlen($reason) < 8) {
        return orange_cpr_locklive_fail(
            ORANGE_CPR_LOCKLIVE_ERR_REASON,
            'Manual clear requires explicit reason (min 8 chars) and audit record.'
        );
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    $strict = orange_cpr_lock_live_read_strict($cprRoot);
    if (empty($strict['ok'])) {
        return $strict;
    }
    /** @var array<string, mixed> $lockBefore */
    $lockBefore = $strict['lock'];
    if (!empty($lockBefore['ponr_crossed'])) {
        return orange_cpr_locklive_fail(
            ORANGE_CPR_LOCKLIVE_ERR_POST_PONR,
            'Post-PONR manual clear forbidden (OD-LOCK-TTL).'
        );
    }

    $clear = orange_cpr_lock_manual_clear_pre_ponr($env, $jobId, [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'actor_admin_id' => (int) $actor['actor_admin_id'],
        'actor_username' => $request['actor_username'] ?? null,
        'reason' => $reason,
        'operator_confirms_stale' => !empty($request['operator_confirms_stale']),
    ]);
    if (empty($clear['ok'])) {
        return orange_cpr_locklive_fail(
            orange_cpr_lock_live_map_upstream((string) ($clear['code'] ?? '')),
            (string) ($clear['message'] ?? 'Manual clear failed.'),
            [
                'upstream_code' => (string) ($clear['code'] ?? ''),
                'p3_audit_path' => $clear['audit_path'] ?? null,
            ]
        );
    }

    $now = gmdate('c');
    $liveId = orange_cpr_generate_job_id();
    $sealedClear = [
        'schema_version' => ORANGE_CPR_LOCK_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_LOCK_LIVE_VERSION,
        'record_type' => 'lock_live_manual_clear',
        'lock_live_id' => $liveId,
        'job_id' => $jobId,
        'cleared_by_admin_id' => (int) $actor['actor_admin_id'],
        'actor_class' => 'super_admin',
        'reason' => $reason,
        'cleared_at' => $now,
        'ponr_crossed_observed' => false,
        'phase_observed' => (string) ($lockBefore['phase'] ?? ''),
        'prior_lock_sha256' => (string) ($strict['raw_sha256'] ?? ''),
        'p3_audit_path' => (string) ($clear['audit_path'] ?? ''),
        'stale_evidence' => $clear['audit']['stale_evidence'] ?? null,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'auto_unlock' => false,
    ];

    try {
        $path = orange_cpr_lock_live_persist_record(
            $cprRoot,
            $jobId,
            'cpr_lock_live_manual_clear_' . $liveId,
            $sealedClear
        );
    } catch (RuntimeException $e) {
        return orange_cpr_locklive_fail(ORANGE_CPR_LOCKLIVE_ERR_DUPLICATE, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.lock_live_manual_clear',
        'job_id' => $jobId,
        'lock_live_id' => $liveId,
        'actor_admin_id' => (int) $actor['actor_admin_id'],
        'reason' => $reason,
        'sealed_audit_path' => $path,
        'p3_audit_path' => (string) ($clear['audit_path'] ?? ''),
        'ponr_crossed_observed' => false,
        'enablement_flag_observed' => false,
    ]);

    return orange_cpr_locklive_ok([
        'message' => 'Pre-PONR stale lock manually cleared by Super Admin; sealed audit written.',
        'cleared' => true,
        'sealed_audit_path' => $path,
        'p3_audit_path' => (string) ($clear['audit_path'] ?? ''),
        'lock_live_id' => $liveId,
        'auto_unlock' => false,
    ]);
}
