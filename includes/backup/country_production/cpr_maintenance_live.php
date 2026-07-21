<?php

declare(strict_types=1);

/**
 * CPR GLOBAL Maintenance Live Path (WP-P4-03 / CP4).
 *
 * Live GLOBAL maint lifecycle + write-block proof + CP4 activation.
 * Integrates State / Lock observation / Gate (G22) / Authority (OD-PERM / OD-RUNBOOK).
 * Does NOT execute PONR, DELETE, IMPORT, or production write mutation.
 * Does NOT auto-release maintenance.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P4_03_MAINTENANCE_LIVE.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_07_MAINTENANCE_TIMEOUT.md
 * @see CPR-P1-WP06-AUTHORITY_RUNBOOK §8.3–§8.4
 */

require_once __DIR__ . '/cpr_state_engine.php';
require_once __DIR__ . '/cpr_checkpoint_engine.php';
require_once __DIR__ . '/cpr_lock_engine.php';
require_once __DIR__ . '/cpr_gate_evaluator.php';
require_once __DIR__ . '/cpr_authority_engine.php';

const ORANGE_CPR_MAINT_STATE_SCHEMA = 'cpr_maint_state/1';
const ORANGE_CPR_MAINT_LIVE_SCHEMA = 'cpr_maint_live/1';
const ORANGE_CPR_MAINT_RELEASE_SCHEMA = 'cpr_maint_release_authorization/1';
const ORANGE_CPR_MAINT_DURATION_SCHEMA = 'cpr_duration_estimate/1';
const ORANGE_CPR_MAINT_LIVE_VERSION = 'P4-03-1.0';

const ORANGE_CPR_MAINT_LIFECYCLE_OFF = 'maint_off';
const ORANGE_CPR_MAINT_LIFECYCLE_ENTERING = 'maint_entering';
const ORANGE_CPR_MAINT_LIFECYCLE_UNPROVEN = 'maint_on_unproven';
const ORANGE_CPR_MAINT_LIFECYCLE_PROVEN = 'maint_on_proven';
const ORANGE_CPR_MAINT_LIFECYCLE_EXECUTING = 'maint_on_executing';
const ORANGE_CPR_MAINT_LIFECYCLE_PAUSED = 'maint_on_paused_failure';
const ORANGE_CPR_MAINT_LIFECYCLE_RELEASE_AUTH = 'maint_release_authorized';
const ORANGE_CPR_MAINT_LIFECYCLE_RELEASED = 'maint_released';

/** Deterministic fail codes (P4-03). */
const ORANGE_CPR_MAINT_ERR_ENABLEMENT = 'maint_enablement_forbidden';
const ORANGE_CPR_MAINT_ERR_ACTOR = 'maint_actor_not_super_admin';
const ORANGE_CPR_MAINT_ERR_SCOPE = 'maint_country_only_forbidden';
const ORANGE_CPR_MAINT_ERR_STATE = 'maint_state_invalid';
const ORANGE_CPR_MAINT_ERR_CONTRACT = 'maint_contract_invalid';
const ORANGE_CPR_MAINT_ERR_CHECKPOINT = 'maint_checkpoint_prereq_invalid';
const ORANGE_CPR_MAINT_ERR_OD_PIN = 'maint_od_pin_order_violation';
const ORANGE_CPR_MAINT_ERR_PROOF = 'maint_write_block_proof_invalid';
const ORANGE_CPR_MAINT_ERR_LOCK = 'maint_lock_conflict';
const ORANGE_CPR_MAINT_ERR_GATE = 'maint_gate_g22_failed';
const ORANGE_CPR_MAINT_ERR_TRANSITION = 'maint_state_transition_failed';
const ORANGE_CPR_MAINT_ERR_CP4 = 'maint_cp4_commit_failed';
const ORANGE_CPR_MAINT_ERR_AUTO_RELEASE = 'maint_auto_release_forbidden';
const ORANGE_CPR_MAINT_ERR_RUNBOOK = 'maint_runbook_incomplete';
const ORANGE_CPR_MAINT_ERR_RELEASE = 'maint_release_forbidden';
const ORANGE_CPR_MAINT_ERR_DUPLICATE = 'maint_duplicate_or_persist_failed';
const ORANGE_CPR_MAINT_ERR_LIFECYCLE = 'maint_lifecycle_invalid';
const ORANGE_CPR_MAINT_ERR_PONR = 'maint_ponr_forbidden';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_maint_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'auto_release' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_maint_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'auto_release' => false,
        'fail_closed' => true,
    ], $extra);
}

/**
 * Hard refuse automatic maintenance release (OD-MAINT / OD-RUNBOOK).
 *
 * @return array<string, mixed>
 */
function orange_cpr_maint_live_refuse_auto_release(): array
{
    return orange_cpr_maint_fail(
        ORANGE_CPR_MAINT_ERR_AUTO_RELEASE,
        'Automatic GLOBAL Maintenance release is forbidden (OD-MAINT / OD-RUNBOOK).'
    );
}

/**
 * Gate / lock evidence slice from durable maint state.
 *
 * @param array<string, mixed> $maintState
 * @return array<string, mixed>
 */
function orange_cpr_maint_live_evidence_slice(array $maintState): array
{
    return [
        'scope' => (string) ($maintState['maint_scope'] ?? ''),
        'global_maintenance_on' => !empty($maintState['global_maintenance_on']),
        'write_block_proven' => !empty($maintState['write_block_proven']),
        'write_block_proof' => (string) ($maintState['write_block_proof'] ?? ''),
        'lifecycle' => (string) ($maintState['lifecycle'] ?? ''),
        'entered_at' => (string) ($maintState['entered_at'] ?? ''),
        'proven_at' => (string) ($maintState['proven_at'] ?? ''),
    ];
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_maint_live_load_state(string $cprRoot, string $jobId): ?array
{
    $path = orange_cpr_maint_state_path($cprRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    if (!orange_cpr_auth_verify_seal($data)) {
        return null;
    }

    return $data;
}

/**
 * Atomic persist of sealed maint_state.json.
 *
 * @param array<string, mixed> $state
 */
function orange_cpr_maint_live_persist_state(string $cprRoot, string $jobId, array $state): string
{
    $dir = orange_cpr_maint_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR maintenance directory.');
    }
    $sealed = orange_cpr_auth_seal($state);
    $final = orange_cpr_maint_state_path($cprRoot, $jobId);
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Maint state encode failed.');
    }
    if (@file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Maint state tmp write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

/**
 * Persist sealed event artifact under maintenance/.
 *
 * @param array<string, mixed> $record
 */
function orange_cpr_maint_live_persist_record(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_maint_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR maintenance directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Maintenance record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Maint record encode failed.');
    }
    if (@file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Maint record tmp write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_maint_live_assert_actor(array $request): array
{
    if (empty($request['actor_is_super_admin'])) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_ACTOR, 'Only Super Admin may control GLOBAL Maintenance (OD-PERM).');
    }
    if (!empty($request['country_admin_is_executor'])) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_ACTOR, 'Country Admin must never control CPR Maintenance (OD-PERM).');
    }
    $actorId = (int) ($request['actor_admin_id'] ?? 0);
    if ($actorId <= 0) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_ACTOR, 'Super Admin actor_admin_id required.');
    }

    return orange_cpr_maint_ok(['actor_admin_id' => $actorId]);
}

/**
 * OD-MAINT-SCOPE: GLOBAL only.
 *
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_maint_live_assert_global_scope(array $request): array
{
    $scope = strtoupper(trim((string) ($request['maint_scope'] ?? 'GLOBAL')));
    if ($scope === '' || $scope === 'GLOBAL') {
        return orange_cpr_maint_ok(['maint_scope' => 'GLOBAL']);
    }

    return orange_cpr_maint_fail(
        ORANGE_CPR_MAINT_ERR_SCOPE,
        'Country-only / non-GLOBAL maintenance is forbidden (OD-MAINT-SCOPE).',
        ['requested_scope' => $scope]
    );
}

/**
 * Lock Engine integration: refuse conflicting peer CPR lock; record maint_global_required expectation.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function orange_cpr_maint_live_integrate_lock(array $env, array $job): array
{
    $cprRoot = orange_cpr_resolve_work_root($env);
    $jobId = (string) ($job['job_id'] ?? '');
    $lock = orange_cpr_lock_read($cprRoot);
    if (is_array($lock)) {
        $lockJob = (string) ($lock['job_id'] ?? '');
        if ($lockJob !== '' && $lockJob !== $jobId) {
            return orange_cpr_maint_fail(
                ORANGE_CPR_MAINT_ERR_LOCK,
                'Conflicting CPR lock held by another job; GLOBAL Maint entry refused (fail-closed).',
                ['lock_job_id' => $lockJob]
            );
        }
    }

    $state = (string) ($job['state'] ?? '');
    $maintRequired = in_array($state, [
        'cpr_maintenance_on', 'cpr_anchor_pinning', 'cpr_pre_ponr',
    ], true) || $state === 'cpr_contract_frozen' || !empty($job['ponr_crossed']);

    return orange_cpr_maint_ok([
        'lock_observed' => $lock,
        'maint_global_required' => $maintRequired,
        'lock_conflict' => false,
    ]);
}

/**
 * Gate Engine integration: G22 must PASS with live maint evidence + committed CP4.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $maintState
 * @return array<string, mixed>
 */
function orange_cpr_maint_live_integrate_gate_g22(array $env, string $jobId, array $maintState): array
{
    $slice = orange_cpr_maint_live_evidence_slice($maintState);
    $ctx = orange_cpr_gate_build_context($env, $jobId, [
        'maint' => $slice,
    ]);
    $g22 = orange_cpr_gate_evaluate_one('G22', $ctx);
    if (($g22['result'] ?? '') !== ORANGE_CPR_GATE_PASS) {
        return orange_cpr_maint_fail(
            ORANGE_CPR_MAINT_ERR_GATE,
            'G22 failed after CP4 activation (Gate Engine integration).',
            [
                'gate' => $g22,
                'fail_code' => (string) ($g22['fail_code'] ?? ''),
            ]
        );
    }

    return orange_cpr_maint_ok(['g22' => $g22]);
}

/**
 * Monitoring-only duration estimate (OD-MAINT-MAX / OD-RTO). Never a fail/release deadline.
 *
 * @param array<string, mixed> $job
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_maint_live_duration_estimate(array $job, array $request): array
{
    $inputs = is_array($request['duration_inputs'] ?? null) ? $request['duration_inputs'] : [];
    $packageBytes = max(0, (int) ($inputs['package_bytes'] ?? 0));
    $sqlBytes = max(0, (int) ($inputs['sql_bytes'] ?? 0));
    $uploadsBytes = max(0, (int) ($inputs['uploads_bytes'] ?? 0));
    $rowCount = max(0, (int) ($inputs['row_count_estimate'] ?? 0));
    $batchCount = max(1, (int) ($inputs['batch_count_estimate'] ?? 1));
    $infra = (float) ($inputs['infra_factor'] ?? 1.0);
    if ($infra <= 0) {
        $infra = 1.0;
    }

    // Engineering estimator (monitoring only) — not Owner RTO.
    $bytes = $packageBytes + $sqlBytes + $uploadsBytes;
    $seconds = (int) max(
        300,
        (int) ceil(($bytes / (1024 * 1024) * 2.0 + ($rowCount / 1000.0) + ($batchCount * 5.0)) / $infra)
    );

    return [
        'schema_version' => ORANGE_CPR_MAINT_DURATION_SCHEMA,
        'job_id' => (string) ($job['job_id'] ?? ''),
        'estimated_duration_seconds' => $seconds,
        'estimate_computed_at' => gmdate('c'),
        'inputs' => [
            'package_bytes' => $packageBytes,
            'sql_bytes' => $sqlBytes,
            'uploads_bytes' => $uploadsBytes,
            'row_count_estimate' => $rowCount,
            'batch_count_estimate' => $batchCount,
            'infra_factor' => $infra,
        ],
        'formula_id' => 'cpr_duration_estimate/p4-03-v1',
        'monitoring_only' => true,
        'hard_fail_deadline' => false,
        'rto_hardcoded' => false,
        'auto_fail' => false,
        'auto_rollback' => false,
        'auto_release' => false,
    ];
}

/**
 * Enter GLOBAL Maintenance (State T06) — before Session Full Backup pin (OD-PIN).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_maint_live_enter(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_ENABLEMENT, $e->getMessage());
    }

    $actor = orange_cpr_maint_live_assert_actor($request);
    if (empty($actor['ok'])) {
        return $actor;
    }
    $scope = orange_cpr_maint_live_assert_global_scope($request);
    if (empty($scope['ok'])) {
        return $scope;
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_STATE, $e->getMessage());
    }

    if (!empty($job['ponr_crossed'])) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_PONR, 'PONR already crossed; P4-03 enter path refused.');
    }

    $existing = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if (is_array($existing) && !empty($existing['global_maintenance_on'])
        && (string) ($existing['lifecycle'] ?? '') !== ORANGE_CPR_MAINT_LIFECYCLE_OFF
        && (string) ($existing['lifecycle'] ?? '') !== ORANGE_CPR_MAINT_LIFECYCLE_RELEASED
    ) {
        if ((string) ($job['state'] ?? '') === 'cpr_maintenance_on') {
            return orange_cpr_maint_ok([
                'message' => 'GLOBAL Maintenance already entered (idempotent).',
                'idempotent' => true,
                'maint_state' => $existing,
                'job_state' => $job['state'],
            ]);
        }
    }

    if ((string) ($job['state'] ?? '') !== 'cpr_contract_frozen'
        && (string) ($job['state'] ?? '') !== 'cpr_maintenance_on'
    ) {
        return orange_cpr_maint_fail(
            ORANGE_CPR_MAINT_ERR_STATE,
            'GLOBAL Maint enter requires cpr_contract_frozen (or already cpr_maintenance_on).',
            ['job_state' => (string) ($job['state'] ?? '')]
        );
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_CONTRACT, 'Frozen execution contract required before GLOBAL Maint (fail-closed).');
    }

    // OD-PIN: Session Full Backup pin (CP1) must not precede maint/CP4.
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP1')) {
        return orange_cpr_maint_fail(
            ORANGE_CPR_MAINT_ERR_OD_PIN,
            'Session Full Backup pin (CP1) exists before GLOBAL Maint/CP4 — OD-PIN violation.'
        );
    }

    $lockInt = orange_cpr_maint_live_integrate_lock($env, $job);
    if (empty($lockInt['ok'])) {
        return $lockInt;
    }

    $now = gmdate('c');
    $actorAdminId = (int) $actor['actor_admin_id'];

    if ((string) ($job['state'] ?? '') !== 'cpr_maintenance_on') {
        $tr = orange_cpr_transition_apply($env, $jobId, 'cpr_maintenance_on', [
            'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
            'actor_admin_id' => $actorAdminId,
            'trigger' => 'enter_global_maint',
        ]);
        if (empty($tr['ok'])) {
            return orange_cpr_maint_fail(
                ORANGE_CPR_MAINT_ERR_TRANSITION,
                (string) ($tr['message'] ?? 'State transition T06 failed.'),
                ['upstream_code' => (string) ($tr['code'] ?? '')]
            );
        }
        $job = orange_cpr_job_read($cprRoot, $jobId);
    }

    $estimate = orange_cpr_maint_live_duration_estimate($job, $request);
    $estimateId = 'duration_' . orange_cpr_generate_job_id();
    try {
        $estimatePath = orange_cpr_maint_live_persist_record(
            $cprRoot,
            $jobId,
            'cpr_duration_estimate_' . $estimateId,
            array_merge($estimate, [
                'engine_version' => ORANGE_CPR_MAINT_LIVE_VERSION,
                'duration_estimate_id' => $estimateId,
            ])
        );
    } catch (RuntimeException $e) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_DUPLICATE, $e->getMessage());
    }

    $state = [
        'schema_version' => ORANGE_CPR_MAINT_STATE_SCHEMA,
        'engine_version' => ORANGE_CPR_MAINT_LIVE_VERSION,
        'job_id' => $jobId,
        'maint_scope' => 'GLOBAL',
        'lifecycle' => ORANGE_CPR_MAINT_LIFECYCLE_UNPROVEN,
        'global_maintenance_on' => true,
        'write_block_proven' => false,
        'write_block_proof' => '',
        'entered_at' => $now,
        'entered_by_admin_id' => $actorAdminId,
        'proven_at' => null,
        'proven_by' => null,
        'paused_failure' => false,
        'duration_estimate_ref' => $estimatePath,
        'latest_timeout_signal_ref' => null,
        'runbook_completed' => false,
        'release_authorization_ref' => null,
        'released_at' => null,
        'released_by_admin_id' => null,
        'maint_global_required' => true,
        'lock_conflict' => false,
        'cp4_committed' => false,
        'session_full_backup_pin_allowed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'updated_at' => $now,
    ];

    try {
        $statePath = orange_cpr_maint_live_persist_state($cprRoot, $jobId, $state);
    } catch (RuntimeException $e) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_DUPLICATE, $e->getMessage());
    }

    $enterId = orange_cpr_generate_job_id();
    $enterRecord = [
        'schema_version' => ORANGE_CPR_MAINT_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_MAINT_LIVE_VERSION,
        'record_type' => 'maint_enter',
        'maint_live_id' => $enterId,
        'job_id' => $jobId,
        'maint_scope' => 'GLOBAL',
        'lifecycle' => ORANGE_CPR_MAINT_LIFECYCLE_UNPROVEN,
        'global_maintenance_on' => true,
        'write_block_proven' => false,
        'job_state' => (string) ($job['state'] ?? ''),
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'duration_estimate_ref' => $estimatePath,
        'maint_global_required' => true,
        'od_pin_session_backup_before_maint' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'created_at' => $now,
    ];
    try {
        $enterPath = orange_cpr_maint_live_persist_record(
            $cprRoot,
            $jobId,
            'cpr_maint_enter_' . $enterId,
            $enterRecord
        );
    } catch (RuntimeException $e) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_DUPLICATE, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.maint_live_enter',
        'job_id' => $jobId,
        'maint_live_id' => $enterId,
        'actor_admin_id' => $actorAdminId,
        'maint_scope' => 'GLOBAL',
        'lifecycle' => ORANGE_CPR_MAINT_LIFECYCLE_UNPROVEN,
        'global_maintenance_on' => true,
        'write_block_proven' => false,
        'job_state' => (string) ($job['state'] ?? ''),
        'enablement_flag_observed' => false,
        'production_mutation' => false,
        'auto_release' => false,
    ]);

    return orange_cpr_maint_ok([
        'message' => 'GLOBAL Maintenance entered (unproven); Session Full Backup pin still forbidden until CP4.',
        'maint_state' => orange_cpr_maint_live_load_state($cprRoot, $jobId),
        'maint_state_path' => $statePath,
        'enter_record_path' => $enterPath,
        'duration_estimate_path' => $estimatePath,
        'job_state' => (string) ($job['state'] ?? ''),
        'lifecycle' => ORANGE_CPR_MAINT_LIFECYCLE_UNPROVEN,
        'cp4_committed' => false,
        'session_full_backup_pin_allowed' => false,
    ]);
}

/**
 * Prove write-block + commit CP4 (OD-MAINT / OD-PIN before Session Full Backup).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_maint_live_prove_and_activate_cp4(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_ENABLEMENT, $e->getMessage());
    }

    $actor = orange_cpr_maint_live_assert_actor($request);
    if (empty($actor['ok'])) {
        return $actor;
    }
    $scope = orange_cpr_maint_live_assert_global_scope($request);
    if (empty($scope['ok'])) {
        return $scope;
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_STATE, $e->getMessage());
    }

    if (!empty($job['ponr_crossed'])) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_PONR, 'PONR already crossed; CP4 live path refused.');
    }
    if ((string) ($job['state'] ?? '') !== 'cpr_maintenance_on') {
        return orange_cpr_maint_fail(
            ORANGE_CPR_MAINT_ERR_STATE,
            'CP4 requires job state cpr_maintenance_on.',
            ['job_state' => (string) ($job['state'] ?? '')]
        );
    }

    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP1')) {
        return orange_cpr_maint_fail(
            ORANGE_CPR_MAINT_ERR_OD_PIN,
            'CP1 already present before CP4 — OD-PIN order violated.'
        );
    }

    foreach (['CP0', 'CP2', 'CP3'] as $req) {
        if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, $req)) {
            return orange_cpr_maint_fail(
                ORANGE_CPR_MAINT_ERR_CHECKPOINT,
                'CP4 requires committed checkpoint ' . $req . '.',
                ['missing_checkpoint' => $req]
            );
        }
    }

    $maintState = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if (!is_array($maintState) || empty($maintState['global_maintenance_on'])) {
        return orange_cpr_maint_fail(
            ORANGE_CPR_MAINT_ERR_LIFECYCLE,
            'Durable GLOBAL maint_state missing or OFF; enter maint first.'
        );
    }
    if ((string) ($maintState['maint_scope'] ?? '') !== 'GLOBAL') {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_SCOPE, 'Durable maint_scope is not GLOBAL.');
    }

    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP4')
        && !empty($maintState['write_block_proven'])
        && !empty($maintState['cp4_committed'])
    ) {
        return orange_cpr_maint_ok([
            'message' => 'CP4 already committed with write-block proven (idempotent).',
            'idempotent' => true,
            'maint_state' => $maintState,
            'cp4_committed' => true,
            'session_full_backup_pin_allowed' => true,
            'lifecycle' => (string) ($maintState['lifecycle'] ?? ORANGE_CPR_MAINT_LIFECYCLE_PROVEN),
        ]);
    }

    $proof = trim((string) ($request['write_block_proof'] ?? ''));
    if ($proof === '') {
        return orange_cpr_maint_fail(
            ORANGE_CPR_MAINT_ERR_PROOF,
            'Non-empty write_block_proof is required (OD-MAINT).'
        );
    }

    $lockInt = orange_cpr_maint_live_integrate_lock($env, $job);
    if (empty($lockInt['ok'])) {
        return $lockInt;
    }

    $now = gmdate('c');
    $actorAdminId = (int) $actor['actor_admin_id'];
    $enteredAt = (string) ($maintState['entered_at'] ?? $now);

    $cp4Payload = [
        'global_maintenance_on' => true,
        'write_block_proof' => $proof,
        'maint_entered_at' => $enteredAt,
        'maint_scope' => 'GLOBAL',
        'proven_at' => $now,
        'proven_by' => 'super_admin:' . $actorAdminId,
        'production_mutation' => false,
        'scaffold_write_block_evidence' => true,
    ];

    $cp4 = orange_cpr_checkpoint_create($env, $jobId, 'CP4', $cp4Payload, [
        'written_by' => 'super_admin',
        'actor_admin_id' => $actorAdminId,
    ]);
    if (empty($cp4['ok'])) {
        return orange_cpr_maint_fail(
            ORANGE_CPR_MAINT_ERR_CP4,
            (string) ($cp4['message'] ?? 'CP4 commit failed.'),
            ['upstream_code' => (string) ($cp4['code'] ?? '')]
        );
    }

    // Authority Engine surface: Super Admin + phrase/reauth optional for prove (not PONR).
    // Hard binding: Country Admin already refused; enablement FALSE asserted.

    $maintState['lifecycle'] = ORANGE_CPR_MAINT_LIFECYCLE_PROVEN;
    $maintState['write_block_proven'] = true;
    $maintState['write_block_proof'] = $proof;
    $maintState['proven_at'] = $now;
    $maintState['proven_by'] = 'super_admin:' . $actorAdminId;
    $maintState['cp4_committed'] = true;
    $maintState['session_full_backup_pin_allowed'] = true;
    $maintState['updated_at'] = $now;
    unset($maintState['content_sha256']);

    try {
        $statePath = orange_cpr_maint_live_persist_state($cprRoot, $jobId, $maintState);
    } catch (RuntimeException $e) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_DUPLICATE, $e->getMessage());
    }

    $reloaded = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if (!is_array($reloaded)) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_DUPLICATE, 'Maint state unreadable after CP4 persist.');
    }

    $g22 = orange_cpr_maint_live_integrate_gate_g22($env, $jobId, $reloaded);
    if (empty($g22['ok'])) {
        return $g22;
    }

    // OD-PIN enforcement check: CP1 still forbidden in maintenance_on (wrong state + order).
    $cp1Probe = orange_cpr_checkpoint_validate_write($env, $jobId, 'CP1', [
        'session_full_backup_id' => 'probe',
        'session_full_backup_fingerprint' => str_repeat('0', 32),
        'verified' => true,
        'pinned' => true,
        'created_under_maintenance' => true,
        'reused_existing_backup' => false,
        'cp4_reference' => 'CP4_maintenance_verified.json',
    ], ['written_by' => 'system']);
    if (!empty($cp1Probe['ok'])) {
        return orange_cpr_maint_fail(
            ORANGE_CPR_MAINT_ERR_OD_PIN,
            'Invariant broken: CP1 validate unexpectedly allowed while still in cpr_maintenance_on.'
        );
    }

    $activateId = orange_cpr_generate_job_id();
    $activateRecord = [
        'schema_version' => ORANGE_CPR_MAINT_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_MAINT_LIVE_VERSION,
        'record_type' => 'maint_cp4_activate',
        'maint_live_id' => $activateId,
        'job_id' => $jobId,
        'maint_scope' => 'GLOBAL',
        'lifecycle' => ORANGE_CPR_MAINT_LIFECYCLE_PROVEN,
        'global_maintenance_on' => true,
        'write_block_proven' => true,
        'write_block_proof' => $proof,
        'maint_entered_at' => $enteredAt,
        'proven_at' => $now,
        'cp4_committed' => true,
        'cp4_content_sha256' => (string) ($cp4['content_sha256'] ?? ($cp4['checkpoint']['content_sha256'] ?? '')),
        'g22_result' => 'PASS',
        'session_full_backup_pin_allowed' => true,
        'session_full_backup_pin_executed' => false,
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'maint_global_required' => true,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'delete_engine' => false,
        'import_engine' => false,
        'created_at' => $now,
    ];
    try {
        $activatePath = orange_cpr_maint_live_persist_record(
            $cprRoot,
            $jobId,
            'cpr_maint_cp4_' . $activateId,
            $activateRecord
        );
    } catch (RuntimeException $e) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_DUPLICATE, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.maint_live_cp4_activate',
        'job_id' => $jobId,
        'maint_live_id' => $activateId,
        'actor_admin_id' => $actorAdminId,
        'maint_scope' => 'GLOBAL',
        'lifecycle' => ORANGE_CPR_MAINT_LIFECYCLE_PROVEN,
        'global_maintenance_on' => true,
        'write_block_proven' => true,
        'write_block_proof' => $proof,
        'cp4_committed' => true,
        'g22_result' => 'PASS',
        'session_full_backup_pin_allowed' => true,
        'enablement_flag_observed' => false,
        'production_mutation' => false,
        'auto_release' => false,
    ]);

    return orange_cpr_maint_ok([
        'message' => 'Write-block proven; CP4 committed; Session Full Backup pin now allowed (OD-PIN).',
        'maint_state' => $reloaded,
        'maint_state_path' => $statePath,
        'activate_record_path' => $activatePath,
        'cp4' => $cp4,
        'g22' => $g22['g22'] ?? null,
        'lifecycle' => ORANGE_CPR_MAINT_LIFECYCLE_PROVEN,
        'cp4_committed' => true,
        'session_full_backup_pin_allowed' => true,
        'session_full_backup_pin_executed' => false,
    ]);
}

/**
 * Combined live path: enter GLOBAL Maint → prove write-block → CP4.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_maint_live_activate_cp4(array $env, string $jobId, array $request): array
{
    $enter = orange_cpr_maint_live_enter($env, $jobId, $request);
    if (empty($enter['ok'])) {
        return $enter;
    }

    $prove = orange_cpr_maint_live_prove_and_activate_cp4($env, $jobId, $request);
    if (empty($prove['ok'])) {
        return $prove;
    }

    return orange_cpr_maint_ok([
        'message' => 'GLOBAL Maint live path complete through CP4 (OD-PIN ordering preserved).',
        'enter' => $enter,
        'prove' => $prove,
        'maint_state' => $prove['maint_state'] ?? null,
        'lifecycle' => ORANGE_CPR_MAINT_LIFECYCLE_PROVEN,
        'cp4_committed' => true,
        'session_full_backup_pin_allowed' => true,
        'session_full_backup_pin_executed' => false,
        'production_mutation' => false,
        'delete_engine' => false,
        'import_engine' => false,
    ]);
}

/**
 * Super Admin Maint release after Runbook completion (never automatic).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_maint_live_release(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_ENABLEMENT, $e->getMessage());
    }

    if (!empty($request['auto_release']) || !empty($request['timeout_release']) || !empty($request['wall_clock_release'])) {
        return orange_cpr_maint_live_refuse_auto_release();
    }

    $actor = orange_cpr_maint_live_assert_actor($request);
    if (empty($actor['ok'])) {
        return $actor;
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_STATE, $e->getMessage());
    }

    $maintState = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if (!is_array($maintState) || empty($maintState['global_maintenance_on'])) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_LIFECYCLE, 'No active GLOBAL Maintenance to release.');
    }
    if (!empty($maintState['paused_failure'])) {
        return orange_cpr_maint_fail(
            ORANGE_CPR_MAINT_ERR_RELEASE,
            'Cannot release while maint_on_paused_failure without Resume/Rollback closeout.'
        );
    }

    if (empty($request['runbook_completed'])) {
        return orange_cpr_maint_fail(
            ORANGE_CPR_MAINT_ERR_RUNBOOK,
            'Runbook completion required before GLOBAL Maint release (OD-RUNBOOK).'
        );
    }
    $runbookRef = trim((string) ($request['runbook_evidence_ref'] ?? ''));
    if ($runbookRef === '') {
        return orange_cpr_maint_fail(
            ORANGE_CPR_MAINT_ERR_RUNBOOK,
            'runbook_evidence_ref required before GLOBAL Maint release (OD-RUNBOOK).'
        );
    }

    $writeBlockCleared = trim((string) ($request['write_block_cleared_proof'] ?? ''));
    if ($writeBlockCleared === '') {
        return orange_cpr_maint_fail(
            ORANGE_CPR_MAINT_ERR_RELEASE,
            'write_block_cleared_proof required for maint release authorization (P1-06 §8.4).'
        );
    }

    $fromState = (string) ($job['state'] ?? '');
    $allowedFrom = [
        'cpr_succeeded',
        'cpr_rollback_completed',
        'cpr_failed_pre_ponr',
        'cpr_cancelled_pre_ponr',
        'cpr_failed_post_ponr',
    ];
    if (!in_array($fromState, $allowedFrom, true)) {
        return orange_cpr_maint_fail(
            ORANGE_CPR_MAINT_ERR_RELEASE,
            'Maint release only from authorized terminal/closeout states (P1-03 / P1-06 §8.3).',
            ['job_state' => $fromState]
        );
    }

    $lock = orange_cpr_lock_read($cprRoot);
    if (is_array($lock) && (string) ($lock['job_id'] ?? '') === $jobId && !empty($lock['ponr_crossed'])) {
        return orange_cpr_maint_fail(
            ORANGE_CPR_MAINT_ERR_LOCK,
            'Post-PONR lock still held; clear/close lock path before Maint release (fail-closed).'
        );
    }

    $actorAdminId = (int) $actor['actor_admin_id'];
    $trigger = match ($fromState) {
        'cpr_succeeded' => 'super_admin_release_maint',
        'cpr_failed_post_ponr' => 'release_maint_exceptional',
        default => 'release_maint',
    };

    $tr = orange_cpr_transition_apply($env, $jobId, 'cpr_maintenance_released', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'actor_admin_id' => $actorAdminId,
        'trigger' => $trigger,
        'runbook_completed' => true,
    ]);
    if (empty($tr['ok'])) {
        return orange_cpr_maint_fail(
            ORANGE_CPR_MAINT_ERR_TRANSITION,
            (string) ($tr['message'] ?? 'Maint release state transition failed.'),
            ['upstream_code' => (string) ($tr['code'] ?? ''), 'from_state' => $fromState]
        );
    }

    $now = gmdate('c');
    $releaseId = orange_cpr_generate_job_id();
    $auditRecordId = 'audit_maint_release_' . $releaseId;
    $authz = [
        'schema_version' => ORANGE_CPR_MAINT_RELEASE_SCHEMA,
        'engine_version' => ORANGE_CPR_MAINT_LIVE_VERSION,
        'release_id' => $releaseId,
        'job_id' => $jobId,
        'released_by_admin_id' => $actorAdminId,
        'released_at' => $now,
        'runbook_completed' => true,
        'runbook_evidence_ref' => $runbookRef,
        'prior_terminal_state' => $fromState,
        'write_block_cleared_proof' => $writeBlockCleared,
        'audit_record_id' => $auditRecordId,
        'auto_release' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
    ];

    try {
        $authzPath = orange_cpr_maint_live_persist_record(
            $cprRoot,
            $jobId,
            'cpr_maint_release_' . $releaseId,
            $authz
        );
    } catch (RuntimeException $e) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_DUPLICATE, $e->getMessage());
    }

    $maintState['lifecycle'] = ORANGE_CPR_MAINT_LIFECYCLE_RELEASED;
    $maintState['global_maintenance_on'] = false;
    $maintState['runbook_completed'] = true;
    $maintState['release_authorization_ref'] = $authzPath;
    $maintState['released_at'] = $now;
    $maintState['released_by_admin_id'] = $actorAdminId;
    $maintState['session_full_backup_pin_allowed'] = false;
    $maintState['updated_at'] = $now;
    unset($maintState['content_sha256']);

    try {
        $statePath = orange_cpr_maint_live_persist_state($cprRoot, $jobId, $maintState);
    } catch (RuntimeException $e) {
        return orange_cpr_maint_fail(ORANGE_CPR_MAINT_ERR_DUPLICATE, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.maint_live_release',
        'job_id' => $jobId,
        'release_id' => $releaseId,
        'audit_record_id' => $auditRecordId,
        'actor_admin_id' => $actorAdminId,
        'prior_terminal_state' => $fromState,
        'runbook_completed' => true,
        'runbook_evidence_ref' => $runbookRef,
        'lifecycle' => ORANGE_CPR_MAINT_LIFECYCLE_RELEASED,
        'global_maintenance_on' => false,
        'auto_release' => false,
        'enablement_flag_observed' => false,
        'production_mutation' => false,
    ]);

    return orange_cpr_maint_ok([
        'message' => 'GLOBAL Maintenance released by Super Admin after Runbook completion.',
        'release_authorization' => $authz,
        'release_authorization_path' => $authzPath,
        'maint_state_path' => $statePath,
        'maint_state' => orange_cpr_maint_live_load_state($cprRoot, $jobId),
        'lifecycle' => ORANGE_CPR_MAINT_LIFECYCLE_RELEASED,
        'job_state' => 'cpr_maintenance_released',
        'auto_release' => false,
    ]);
}
