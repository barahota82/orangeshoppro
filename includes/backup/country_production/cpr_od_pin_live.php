<?php

declare(strict_types=1);

/**
 * CPR Live Session Full Backup & OD-PIN (WP-P4-04 / CP1).
 *
 * Live path: NEW session Full Backup metadata → verify → immutable pin (CP1).
 * Requires CP4 / GLOBAL Maint proven first (OD-PIN / OD-MAINT).
 * Integrates State (T07/T08), Lock observation, Gate (G23), Authority seals / contract pin amend.
 * Does NOT execute PONR, DELETE, IMPORT, or production backup mutation.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P4_04_OD_PIN_LIVE.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_04_CHECKPOINT_SCHEMAS.md
 */

require_once __DIR__ . '/cpr_maintenance_live.php';
require_once __DIR__ . '/cpr_state_engine.php';
require_once __DIR__ . '/cpr_checkpoint_engine.php';
require_once __DIR__ . '/cpr_lock_engine.php';
require_once __DIR__ . '/cpr_gate_evaluator.php';
require_once __DIR__ . '/cpr_authority_engine.php';

const ORANGE_CPR_OD_PIN_LIVE_SCHEMA = 'cpr_od_pin_live/1';
const ORANGE_CPR_OD_PIN_BACKUP_SCHEMA = 'cpr_session_full_backup/1';
const ORANGE_CPR_OD_PIN_VERIFY_SCHEMA = 'cpr_session_full_backup_verify/1';
const ORANGE_CPR_OD_PIN_PIN_SCHEMA = 'cpr_session_full_backup_pin/1';
const ORANGE_CPR_OD_PIN_LIVE_VERSION = 'P4-04-1.0';

/** Deterministic fail codes (P4-04). */
const ORANGE_CPR_ODPIN_ERR_ENABLEMENT = 'odpin_enablement_forbidden';
const ORANGE_CPR_ODPIN_ERR_ACTOR = 'odpin_actor_not_super_admin';
const ORANGE_CPR_ODPIN_ERR_STATE = 'odpin_state_invalid';
const ORANGE_CPR_ODPIN_ERR_CONTRACT = 'odpin_contract_invalid';
const ORANGE_CPR_ODPIN_ERR_IDENTITY = 'odpin_identity_drift';
const ORANGE_CPR_ODPIN_ERR_MAINT = 'odpin_maint_cp4_required';
const ORANGE_CPR_ODPIN_ERR_REUSE = 'odpin_reuse_forbidden';
const ORANGE_CPR_ODPIN_ERR_VERIFY = 'odpin_verify_failed';
const ORANGE_CPR_ODPIN_ERR_PIN = 'odpin_pin_failed';
const ORANGE_CPR_ODPIN_ERR_IMMUTABLE = 'odpin_pin_immutable';
const ORANGE_CPR_ODPIN_ERR_LOCK = 'odpin_lock_conflict';
const ORANGE_CPR_ODPIN_ERR_GATE = 'odpin_gate_g23_failed';
const ORANGE_CPR_ODPIN_ERR_TRANSITION = 'odpin_state_transition_failed';
const ORANGE_CPR_ODPIN_ERR_CP1 = 'odpin_cp1_commit_failed';
const ORANGE_CPR_ODPIN_ERR_BACKUP = 'odpin_backup_invalid';
const ORANGE_CPR_ODPIN_ERR_DUPLICATE = 'odpin_duplicate_or_persist_failed';
const ORANGE_CPR_ODPIN_ERR_PONR = 'odpin_ponr_forbidden';
const ORANGE_CPR_ODPIN_ERR_CONTINUATION = 'odpin_continuation_without_pin';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_odpin_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'reused_existing_backup' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_odpin_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'reused_existing_backup' => false,
        'fail_closed' => true,
        'session_full_backup_pinned' => false,
    ], $extra);
}

/**
 * Hard refuse reuse of a previous backup as CPR session anchor (OD-PIN).
 *
 * @return array<string, mixed>
 */
function orange_cpr_od_pin_live_refuse_reuse(): array
{
    return orange_cpr_odpin_fail(
        ORANGE_CPR_ODPIN_ERR_REUSE,
        'Reuse of a previous Full Backup as CPR session anchor is forbidden (OD-PIN).'
    );
}

/**
 * Hard refuse continuation without a valid session pin.
 *
 * @return array<string, mixed>
 */
function orange_cpr_od_pin_live_refuse_continuation_without_pin(): array
{
    return orange_cpr_odpin_fail(
        ORANGE_CPR_ODPIN_ERR_CONTINUATION,
        'Continuation without a valid Session Full Backup pin is forbidden (OD-PIN).'
    );
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_od_pin_live_assert_actor(array $request): array
{
    if (empty($request['actor_is_super_admin'])) {
        return orange_cpr_odpin_fail(ORANGE_CPR_ODPIN_ERR_ACTOR, 'Only Super Admin may run OD-PIN live path (OD-PERM).');
    }
    if (!empty($request['country_admin_is_executor'])) {
        return orange_cpr_odpin_fail(ORANGE_CPR_ODPIN_ERR_ACTOR, 'Country Admin must never execute OD-PIN (OD-PERM).');
    }
    $actorId = (int) ($request['actor_admin_id'] ?? 0);
    if ($actorId <= 0) {
        return orange_cpr_odpin_fail(ORANGE_CPR_ODPIN_ERR_ACTOR, 'Super Admin actor_admin_id required.');
    }

    return orange_cpr_odpin_ok(['actor_admin_id' => $actorId]);
}

/**
 * Persist sealed artifact under od_pin/.
 *
 * @param array<string, mixed> $record
 */
function orange_cpr_od_pin_live_persist_record(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_od_pin_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR od_pin directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('OD-PIN record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('OD-PIN record encode failed.');
    }
    if (@file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('OD-PIN record tmp write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_od_pin_live_load_json(string $path): ?array
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
 * Latest pin artifact path pointer (immutable once written).
 */
function orange_cpr_od_pin_live_pin_latest_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_od_pin_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . 'cpr_session_pin_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_od_pin_live_load_pin(string $cprRoot, string $jobId): ?array
{
    return orange_cpr_od_pin_live_load_json(orange_cpr_od_pin_live_pin_latest_path($cprRoot, $jobId));
}

/**
 * Lock Engine integration — refuse conflicting peer lock.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function orange_cpr_od_pin_live_integrate_lock(array $env, array $job): array
{
    $cprRoot = orange_cpr_resolve_work_root($env);
    $jobId = (string) ($job['job_id'] ?? '');
    $lock = orange_cpr_lock_read($cprRoot);
    if (is_array($lock)) {
        $lockJob = (string) ($lock['job_id'] ?? '');
        if ($lockJob !== '' && $lockJob !== $jobId) {
            return orange_cpr_odpin_fail(
                ORANGE_CPR_ODPIN_ERR_LOCK,
                'Conflicting CPR lock held by another job; OD-PIN refused (fail-closed).',
                ['lock_job_id' => $lockJob]
            );
        }
    }

    return orange_cpr_odpin_ok([
        'lock_observed' => $lock,
        'maint_global_required' => true,
    ]);
}

/**
 * Gate Engine integration — G23 PASS after CP1 pin.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $sessionEvidence
 * @return array<string, mixed>
 */
function orange_cpr_od_pin_live_integrate_gate_g23(array $env, string $jobId, array $sessionEvidence): array
{
    $ctx = orange_cpr_gate_build_context($env, $jobId, [
        'session_full_backup' => $sessionEvidence,
    ]);
    $g23 = orange_cpr_gate_evaluate_one('G23', $ctx);
    if (($g23['result'] ?? '') !== ORANGE_CPR_GATE_PASS) {
        return orange_cpr_odpin_fail(
            ORANGE_CPR_ODPIN_ERR_GATE,
            'G23 failed after CP1 pin (Gate Engine integration).',
            [
                'gate' => $g23,
                'fail_code' => (string) ($g23['fail_code'] ?? ''),
            ]
        );
    }

    return orange_cpr_odpin_ok(['g23' => $g23]);
}

/**
 * Preconditions: CP4 + GLOBAL maint proven; contract frozen; identity intact.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_od_pin_live_assert_preconditions(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_odpin_fail(ORANGE_CPR_ODPIN_ERR_ENABLEMENT, $e->getMessage());
    }

    $actor = orange_cpr_od_pin_live_assert_actor($request);
    if (empty($actor['ok'])) {
        return $actor;
    }

    if (!empty($request['reuse_existing_backup']) || !empty($request['reused_existing_backup'])) {
        return orange_cpr_od_pin_live_refuse_reuse();
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_odpin_fail(ORANGE_CPR_ODPIN_ERR_STATE, $e->getMessage());
    }

    if (!empty($job['ponr_crossed'])) {
        return orange_cpr_odpin_fail(ORANGE_CPR_ODPIN_ERR_PONR, 'PONR already crossed; OD-PIN live path refused.');
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_odpin_fail(ORANGE_CPR_ODPIN_ERR_CONTRACT, 'Frozen execution contract required before OD-PIN.');
    }
    if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_odpin_fail(ORANGE_CPR_ODPIN_ERR_IDENTITY, 'Contract/job identity drift (fail-closed).');
    }

    // Immutable pin wins over state checks (re-entry after T08 must not look like a soft state error).
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP1')
        || orange_cpr_od_pin_live_load_pin($cprRoot, $jobId) !== null
        || !empty($contract['session_full_backup_pinned'])
    ) {
        return orange_cpr_odpin_fail(
            ORANGE_CPR_ODPIN_ERR_IMMUTABLE,
            'Session pin already exists and is immutable (OD-PIN).'
        );
    }

    $state = (string) ($job['state'] ?? '');
    if (!in_array($state, ['cpr_maintenance_on', 'cpr_anchor_pinning'], true)) {
        return orange_cpr_odpin_fail(
            ORANGE_CPR_ODPIN_ERR_STATE,
            'OD-PIN requires cpr_maintenance_on or cpr_anchor_pinning.',
            ['job_state' => $state]
        );
    }

    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP4')) {
        return orange_cpr_odpin_fail(ORANGE_CPR_ODPIN_ERR_MAINT, 'CP4 required before Session Full Backup pin (OD-PIN).');
    }

    $maint = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if (!is_array($maint)
        || empty($maint['global_maintenance_on'])
        || (string) ($maint['maint_scope'] ?? '') !== 'GLOBAL'
        || empty($maint['write_block_proven'])
        || empty($maint['cp4_committed'])
    ) {
        return orange_cpr_odpin_fail(
            ORANGE_CPR_ODPIN_ERR_MAINT,
            'GLOBAL Maintenance must be ON and write-block proven (CP4) before OD-PIN.'
        );
    }

    $lockInt = orange_cpr_od_pin_live_integrate_lock($env, $job);
    if (empty($lockInt['ok'])) {
        return $lockInt;
    }

    return orange_cpr_odpin_ok([
        'actor_admin_id' => (int) $actor['actor_admin_id'],
        'job' => $job,
        'contract' => $contract,
        'maint_state' => $maint,
        'cpr_root' => $cprRoot,
        'lock' => $lockInt,
    ]);
}

/**
 * Create sealed NEW Session Full Backup metadata (no production mutation).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_od_pin_live_create_session_backup(array $env, string $jobId, array $request): array
{
    $pre = orange_cpr_od_pin_live_assert_preconditions($env, $jobId, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }

    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    $cprRoot = (string) $pre['cpr_root'];
    $actorAdminId = (int) $pre['actor_admin_id'];

    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP1')
        || orange_cpr_od_pin_live_load_pin($cprRoot, $jobId) !== null
    ) {
        return orange_cpr_odpin_fail(
            ORANGE_CPR_ODPIN_ERR_IMMUTABLE,
            'Session pin already exists; pin is immutable (OD-PIN).'
        );
    }

    // T07: enter anchor_pinning for OD-PIN backup stage.
    if ((string) ($job['state'] ?? '') === 'cpr_maintenance_on') {
        $tr = orange_cpr_transition_apply($env, $jobId, 'cpr_anchor_pinning', [
            'actor' => ORANGE_CPR_ACTOR_SYSTEM,
            'actor_admin_id' => $actorAdminId,
            'trigger' => 'start_od_pin_backup',
        ]);
        if (empty($tr['ok'])) {
            return orange_cpr_odpin_fail(
                ORANGE_CPR_ODPIN_ERR_TRANSITION,
                (string) ($tr['message'] ?? 'T07 start_od_pin_backup failed.'),
                ['upstream_code' => (string) ($tr['code'] ?? '')]
            );
        }
        $job = orange_cpr_job_read($cprRoot, $jobId);
    }

    $now = gmdate('c');
    $backupId = 'sfb-' . orange_cpr_generate_job_id();
    $fingerprintMaterial = [
        'job_id' => $jobId,
        'package_id' => (string) ($job['package_id'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'contract_revision' => (int) ($contract['contract_revision'] ?? 0),
        'backup_id' => $backupId,
        'created_at' => $now,
        'nonce' => bin2hex(random_bytes(16)),
    ];
    ksort($fingerprintMaterial);
    $fingerprint = hash('sha256', (string) json_encode($fingerprintMaterial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $backup = [
        'schema_version' => ORANGE_CPR_OD_PIN_BACKUP_SCHEMA,
        'engine_version' => ORANGE_CPR_OD_PIN_LIVE_VERSION,
        'record_type' => 'session_full_backup_create',
        'job_id' => $jobId,
        'session_full_backup_id' => $backupId,
        'session_full_backup_fingerprint' => $fingerprint,
        'package_id' => (string) ($job['package_id'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'contract_revision' => (int) ($contract['contract_revision'] ?? 0),
        'created_under_maintenance' => true,
        'reused_existing_backup' => false,
        'global_maintenance_on' => true,
        'cp4_reference' => 'CP4_maintenance_verified.json',
        'job_state_observed' => (string) ($job['state'] ?? ''),
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'verified' => false,
        'pinned' => false,
        'production_backup_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'created_at' => $now,
    ];

    try {
        $path = orange_cpr_od_pin_live_persist_record(
            $cprRoot,
            $jobId,
            'cpr_session_backup_' . $backupId,
            $backup
        );
        // Latest pointer for verify step (overwrite allowed only for pointer file via atomic replace).
        $latest = orange_cpr_od_pin_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . 'cpr_session_backup_latest.json';
        $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
        $sealed = orange_cpr_auth_seal(array_merge($backup, ['backup_record_path' => $path]));
        $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Session backup latest pointer write failed.');
        }
        orange_cpr_atomic_rename_replace($tmp, $latest);
    } catch (RuntimeException $e) {
        return orange_cpr_odpin_fail(ORANGE_CPR_ODPIN_ERR_DUPLICATE, $e->getMessage());
    }

    // Maint lifecycle → executing (OD-PIN in progress).
    $maint = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if (is_array($maint)) {
        $maint['lifecycle'] = ORANGE_CPR_MAINT_LIFECYCLE_EXECUTING;
        $maint['updated_at'] = $now;
        unset($maint['content_sha256']);
        try {
            orange_cpr_maint_live_persist_state($cprRoot, $jobId, $maint);
        } catch (RuntimeException) {
            // Non-fatal for OD-PIN create; sealed backup already persisted.
        }
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.od_pin_session_backup_create',
        'job_id' => $jobId,
        'session_full_backup_id' => $backupId,
        'session_full_backup_fingerprint' => $fingerprint,
        'actor_admin_id' => $actorAdminId,
        'created_under_maintenance' => true,
        'reused_existing_backup' => false,
        'job_state' => (string) ($job['state'] ?? ''),
        'enablement_flag_observed' => false,
        'production_mutation' => false,
    ]);

    return orange_cpr_odpin_ok([
        'message' => 'NEW Session Full Backup metadata sealed under GLOBAL Maint (OD-PIN).',
        'session_full_backup' => $backup,
        'session_full_backup_path' => $path,
        'session_full_backup_id' => $backupId,
        'session_full_backup_fingerprint' => $fingerprint,
        'verified' => false,
        'pinned' => false,
        'job_state' => (string) ($job['state'] ?? ''),
    ]);
}

/**
 * Live verify Session Full Backup — mandatory before pin; fail-closed.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_od_pin_live_verify(array $env, string $jobId, array $request): array
{
    $pre = orange_cpr_od_pin_live_assert_preconditions($env, $jobId, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }

    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    $cprRoot = (string) $pre['cpr_root'];
    $actorAdminId = (int) $pre['actor_admin_id'];

    $latestPath = orange_cpr_od_pin_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . 'cpr_session_backup_latest.json';
    $backup = orange_cpr_od_pin_live_load_json($latestPath);
    if (!is_array($backup)) {
        return orange_cpr_odpin_fail(ORANGE_CPR_ODPIN_ERR_BACKUP, 'Session Full Backup metadata missing or seal invalid.');
    }

    $backupId = (string) ($backup['session_full_backup_id'] ?? '');
    $fingerprint = (string) ($backup['session_full_backup_fingerprint'] ?? '');
    if ($backupId === '' || $fingerprint === '' || strlen($fingerprint) !== 64) {
        return orange_cpr_odpin_fail(ORANGE_CPR_ODPIN_ERR_VERIFY, 'Session Full Backup id/fingerprint invalid.');
    }

    $failures = [];
    if (!empty($backup['reused_existing_backup'])) {
        $failures[] = 'reused_existing_backup';
    }
    if (($backup['created_under_maintenance'] ?? false) !== true) {
        $failures[] = 'not_created_under_maintenance';
    }
    if ((string) ($backup['job_id'] ?? '') !== $jobId) {
        $failures[] = 'job_id_mismatch';
    }
    if ((string) ($backup['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')) {
        $failures[] = 'package_fingerprint_mismatch';
    }
    if ((int) ($backup['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)) {
        $failures[] = 'country_id_mismatch';
    }
    if ((string) ($backup['cp4_reference'] ?? '') === '' || !orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP4')) {
        $failures[] = 'cp4_missing';
    }
    if (!empty($request['force_verify_fail'])) {
        $failures[] = 'forced_verify_fail';
    }

    $now = gmdate('c');
    $verifyId = orange_cpr_generate_job_id();
    $passed = $failures === [];

    $verify = [
        'schema_version' => ORANGE_CPR_OD_PIN_VERIFY_SCHEMA,
        'engine_version' => ORANGE_CPR_OD_PIN_LIVE_VERSION,
        'record_type' => 'session_full_backup_verify',
        'verify_id' => $verifyId,
        'job_id' => $jobId,
        'session_full_backup_id' => $backupId,
        'session_full_backup_fingerprint' => $fingerprint,
        'result' => $passed ? 'PASS' : 'FAIL',
        'verified' => $passed,
        'failures' => $failures,
        'created_under_maintenance' => !empty($backup['created_under_maintenance']),
        'reused_existing_backup' => !empty($backup['reused_existing_backup']),
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'verified_at' => $now,
    ];

    try {
        $path = orange_cpr_od_pin_live_persist_record(
            $cprRoot,
            $jobId,
            'cpr_session_verify_' . $verifyId,
            $verify
        );
        $latest = orange_cpr_od_pin_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . 'cpr_session_verify_latest.json';
        $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
        $sealed = orange_cpr_auth_seal(array_merge($verify, ['verify_record_path' => $path]));
        $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Verify latest pointer write failed.');
        }
        orange_cpr_atomic_rename_replace($tmp, $latest);
    } catch (RuntimeException $e) {
        return orange_cpr_odpin_fail(ORANGE_CPR_ODPIN_ERR_DUPLICATE, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.od_pin_session_backup_verify',
        'job_id' => $jobId,
        'verify_id' => $verifyId,
        'session_full_backup_id' => $backupId,
        'result' => $passed ? 'PASS' : 'FAIL',
        'failures' => $failures,
        'actor_admin_id' => $actorAdminId,
        'enablement_flag_observed' => false,
        'production_mutation' => false,
    ]);

    if (!$passed) {
        return orange_cpr_odpin_fail(
            ORANGE_CPR_ODPIN_ERR_VERIFY,
            'Session Full Backup verification failed (fail-closed; pin forbidden).',
            [
                'verify' => $verify,
                'verify_path' => $path,
                'failures' => $failures,
            ]
        );
    }

    return orange_cpr_odpin_ok([
        'message' => 'Session Full Backup verification PASS; pin may proceed.',
        'verify' => $verify,
        'verify_path' => $path,
        'session_full_backup_id' => $backupId,
        'session_full_backup_fingerprint' => $fingerprint,
        'verified' => true,
        'pinned' => false,
    ]);
}

/**
 * Create immutable Session Full Backup pin + CP1; transition to cpr_pre_ponr (T08).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_od_pin_live_pin(array $env, string $jobId, array $request): array
{
    $pre = orange_cpr_od_pin_live_assert_preconditions($env, $jobId, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }

    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    $cprRoot = (string) $pre['cpr_root'];
    $actorAdminId = (int) $pre['actor_admin_id'];

    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP1')
        || orange_cpr_od_pin_live_load_pin($cprRoot, $jobId) !== null
        || !empty($contract['session_full_backup_pinned'])
    ) {
        return orange_cpr_odpin_fail(
            ORANGE_CPR_ODPIN_ERR_IMMUTABLE,
            'Session pin already exists and is immutable (OD-PIN).'
        );
    }

    $verifyLatest = orange_cpr_od_pin_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . 'cpr_session_verify_latest.json';
    $verify = orange_cpr_od_pin_live_load_json($verifyLatest);
    if (!is_array($verify) || ($verify['result'] ?? '') !== 'PASS' || empty($verify['verified'])) {
        return orange_cpr_odpin_fail(
            ORANGE_CPR_ODPIN_ERR_VERIFY,
            'Verification PASS required before pin (OD-PIN).'
        );
    }

    $backupLatest = orange_cpr_od_pin_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . 'cpr_session_backup_latest.json';
    $backup = orange_cpr_od_pin_live_load_json($backupLatest);
    if (!is_array($backup)) {
        return orange_cpr_odpin_fail(ORANGE_CPR_ODPIN_ERR_BACKUP, 'Session Full Backup metadata missing before pin.');
    }

    $backupId = (string) ($backup['session_full_backup_id'] ?? '');
    $fingerprint = (string) ($backup['session_full_backup_fingerprint'] ?? '');
    if ($backupId === '' || $fingerprint === ''
        || $backupId !== (string) ($verify['session_full_backup_id'] ?? '')
        || $fingerprint !== (string) ($verify['session_full_backup_fingerprint'] ?? '')
    ) {
        return orange_cpr_odpin_fail(ORANGE_CPR_ODPIN_ERR_VERIFY, 'Verify/backup fingerprint mismatch (fail-closed).');
    }
    if (!empty($backup['reused_existing_backup'])) {
        return orange_cpr_od_pin_live_refuse_reuse();
    }

    if ((string) ($job['state'] ?? '') !== 'cpr_anchor_pinning') {
        return orange_cpr_odpin_fail(
            ORANGE_CPR_ODPIN_ERR_STATE,
            'Pin requires job state cpr_anchor_pinning.',
            ['job_state' => (string) ($job['state'] ?? '')]
        );
    }

    $now = gmdate('c');
    $cp4Ref = 'CP4_maintenance_verified.json';
    $cp1Payload = [
        'session_full_backup_id' => $backupId,
        'session_full_backup_fingerprint' => $fingerprint,
        'verified' => true,
        'pinned' => true,
        'created_under_maintenance' => true,
        'reused_existing_backup' => false,
        'cp4_reference' => $cp4Ref,
    ];

    $cp1 = orange_cpr_checkpoint_create($env, $jobId, 'CP1', $cp1Payload, [
        'written_by' => 'super_admin',
        'actor_admin_id' => $actorAdminId,
    ]);
    if (empty($cp1['ok'])) {
        return orange_cpr_odpin_fail(
            ORANGE_CPR_ODPIN_ERR_CP1,
            (string) ($cp1['message'] ?? 'CP1 commit failed.'),
            ['upstream_code' => (string) ($cp1['code'] ?? '')]
        );
    }

    // Authority / contract pin amend (session fields) — before T08 so state engine sees pinned contract.
    $revision = (int) ($contract['contract_revision'] ?? 1) + 1;
    $contract['session_full_backup_id'] = $backupId;
    $contract['session_full_backup_fingerprint'] = $fingerprint;
    $contract['session_full_backup_pinned'] = true;
    $contract['contract_revision'] = $revision;
    $contract['contract_phase'] = 'pre_ponr';
    $contract['pin_amended_at'] = $now;
    $contract['pin_amended_by_admin_id'] = $actorAdminId;
    $contract['enablement_flag_observed'] = false;
    $contract['http_mutation_forbidden'] = true;
    orange_cpr_contract_write($cprRoot, $jobId, $contract);

    $job['contract_revision'] = $revision;
    $job['updated_at'] = $now;
    orange_cpr_job_write($cprRoot, $jobId, $job);

    $tr = orange_cpr_transition_apply($env, $jobId, 'cpr_pre_ponr', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'actor_admin_id' => $actorAdminId,
        'trigger' => 'pin_verified',
        'session_full_backup_pinned' => true,
    ]);
    if (empty($tr['ok'])) {
        return orange_cpr_odpin_fail(
            ORANGE_CPR_ODPIN_ERR_TRANSITION,
            (string) ($tr['message'] ?? 'T08 pin_verified failed.'),
            ['upstream_code' => (string) ($tr['code'] ?? '')]
        );
    }

    $pinId = orange_cpr_generate_job_id();
    $pinRecord = [
        'schema_version' => ORANGE_CPR_OD_PIN_PIN_SCHEMA,
        'engine_version' => ORANGE_CPR_OD_PIN_LIVE_VERSION,
        'record_type' => 'session_full_backup_pin',
        'pin_id' => $pinId,
        'job_id' => $jobId,
        'session_full_backup_id' => $backupId,
        'session_full_backup_fingerprint' => $fingerprint,
        'verified' => true,
        'pinned' => true,
        'immutable' => true,
        'created_under_maintenance' => true,
        'reused_existing_backup' => false,
        'cp4_reference' => $cp4Ref,
        'cp1_committed' => true,
        'contract_revision' => $revision,
        'contract_phase' => 'pre_ponr',
        'verify_id' => (string) ($verify['verify_id'] ?? ''),
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'delete_engine' => false,
        'import_engine' => false,
        'ponr_crossed' => false,
        'pinned_at' => $now,
    ];

    try {
        $pinPath = orange_cpr_od_pin_live_persist_record(
            $cprRoot,
            $jobId,
            'cpr_session_pin_' . $pinId,
            $pinRecord
        );
        $latest = orange_cpr_od_pin_live_pin_latest_path($cprRoot, $jobId);
        if (is_file($latest)) {
            return orange_cpr_odpin_fail(ORANGE_CPR_ODPIN_ERR_IMMUTABLE, 'Pin latest pointer already exists (immutable).');
        }
        $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
        $sealed = orange_cpr_auth_seal(array_merge($pinRecord, ['pin_record_path' => $pinPath]));
        $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Pin latest pointer write failed.');
        }
        orange_cpr_atomic_rename_replace($tmp, $latest);
    } catch (RuntimeException $e) {
        return orange_cpr_odpin_fail(ORANGE_CPR_ODPIN_ERR_DUPLICATE, $e->getMessage());
    }

    $sessionEvidence = [
        'id' => $backupId,
        'fingerprint' => $fingerprint,
        'pinned' => true,
        'verified' => true,
        'reused_existing_backup' => false,
        'created_under_maintenance' => true,
    ];
    $g23 = orange_cpr_od_pin_live_integrate_gate_g23($env, $jobId, $sessionEvidence);
    if (empty($g23['ok'])) {
        return $g23;
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.od_pin_session_backup_pin',
        'job_id' => $jobId,
        'pin_id' => $pinId,
        'session_full_backup_id' => $backupId,
        'session_full_backup_fingerprint' => $fingerprint,
        'cp1_committed' => true,
        'immutable' => true,
        'contract_revision' => $revision,
        'actor_admin_id' => $actorAdminId,
        'g23_result' => 'PASS',
        'job_state' => 'cpr_pre_ponr',
        'enablement_flag_observed' => false,
        'production_mutation' => false,
        'ponr_crossed' => false,
    ]);

    return orange_cpr_odpin_ok([
        'message' => 'Session Full Backup pinned (CP1); contract pin-amended; state cpr_pre_ponr.',
        'pin' => $pinRecord,
        'pin_path' => $pinPath,
        'cp1' => $cp1,
        'g23' => $g23['g23'] ?? null,
        'session_full_backup_id' => $backupId,
        'session_full_backup_fingerprint' => $fingerprint,
        'session_full_backup_pinned' => true,
        'verified' => true,
        'pinned' => true,
        'immutable' => true,
        'job_state' => 'cpr_pre_ponr',
        'contract_revision' => $revision,
    ]);
}

/**
 * Combined live OD-PIN path: create → verify → pin (CP1).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_od_pin_live_run(array $env, string $jobId, array $request): array
{
    $create = orange_cpr_od_pin_live_create_session_backup($env, $jobId, $request);
    if (empty($create['ok'])) {
        return $create;
    }

    $verify = orange_cpr_od_pin_live_verify($env, $jobId, $request);
    if (empty($verify['ok'])) {
        return $verify;
    }

    $pin = orange_cpr_od_pin_live_pin($env, $jobId, $request);
    if (empty($pin['ok'])) {
        return $pin;
    }

    return orange_cpr_odpin_ok([
        'message' => 'OD-PIN live path complete: NEW Session Full Backup → verify → immutable CP1 pin.',
        'create' => $create,
        'verify' => $verify,
        'pin' => $pin,
        'session_full_backup_id' => (string) ($pin['session_full_backup_id'] ?? ''),
        'session_full_backup_fingerprint' => (string) ($pin['session_full_backup_fingerprint'] ?? ''),
        'session_full_backup_pinned' => true,
        'verified' => true,
        'pinned' => true,
        'immutable' => true,
        'job_state' => 'cpr_pre_ponr',
        'production_mutation' => false,
        'delete_engine' => false,
        'import_engine' => false,
        'ponr_crossed' => false,
    ]);
}
