<?php

declare(strict_types=1);

/**
 * CPR Approvals & Live Pre-PONR Contract (WP-P4-02).
 *
 * Live approval flow + pre_ponr contract freeze + full re-validation.
 * Consumes P3 authority engine (`orange_cpr_ponr_authorize` / freeze helpers).
 * Does NOT execute PONR mutation, DELETE, or IMPORT.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P4_02_APPROVALS_CONTRACT_LIVE.md
 * @see CPR-P1-WP02-EXECUTION_CONTRACT
 * @see CPR-P1-WP06-AUTHORITY_RUNBOOK
 */

require_once __DIR__ . '/cpr_authority_engine.php';
require_once __DIR__ . '/cpr_state_engine.php';

const ORANGE_CPR_APPROVALS_LIVE_SCHEMA = 'cpr_approvals_live/1';
const ORANGE_CPR_APPROVALS_LIVE_VERSION = 'P4-02-1.0';

/** Deterministic fail codes (P4-02). */
const ORANGE_CPR_APPR_ERR_ENABLEMENT = 'approvals_enablement_forbidden';
const ORANGE_CPR_APPR_ERR_ACTOR = 'approvals_actor_not_super_admin';
const ORANGE_CPR_APPR_ERR_WORKFLOW = 'approvals_workflow_invalid';
const ORANGE_CPR_APPR_ERR_WF_B = 'approvals_wfb_approval_missing';
const ORANGE_CPR_APPR_ERR_STATE = 'approvals_state_invalid';
const ORANGE_CPR_APPR_ERR_CONTRACT = 'approvals_contract_invalid';
const ORANGE_CPR_APPR_ERR_FINGERPRINT = 'approvals_fingerprint_drift';
const ORANGE_CPR_APPR_ERR_CHECKPOINT = 'approvals_checkpoint_invalid';
const ORANGE_CPR_APPR_ERR_LOCK = 'approvals_lock_ownership_drift';
const ORANGE_CPR_APPR_ERR_GATE = 'approvals_gate_invalid';
const ORANGE_CPR_APPR_ERR_INVENTORY = 'approvals_inventory_drift';
const ORANGE_CPR_APPR_ERR_PIN = 'approvals_session_backup_invalid';
const ORANGE_CPR_APPR_ERR_AUTH = 'approvals_authorization_failed';
const ORANGE_CPR_APPR_ERR_DUPLICATE = 'approvals_duplicate_live_record';
const ORANGE_CPR_APPR_ERR_PONR = 'approvals_ponr_forbidden';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_appr_ok(array $extra = []): array
{
    return array_merge(['ok' => true, 'code' => 'ok'], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_appr_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'ponr_authorized' => false,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'fail_closed' => true,
    ], $extra);
}

/**
 * OD-DUAL live approval validation (WF-A / WF-B).
 *
 * @param array<string, mixed> $job
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_approvals_live_validate_dual(array $job, array $request): array
{
    if (empty($request['actor_is_super_admin'])) {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_ACTOR, 'Only Super Admin may run live pre-PONR approvals (OD-PERM).');
    }
    $actorId = (int) ($request['actor_admin_id'] ?? 0);
    if ($actorId <= 0) {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_ACTOR, 'Super Admin actor_admin_id required.');
    }
    if (!empty($request['country_admin_is_executor'])) {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_ACTOR, 'Country Admin must never execute Production Restore (OD-PERM).');
    }

    $workflow = strtoupper((string) ($job['workflow'] ?? ''));
    if ($workflow !== 'A' && $workflow !== 'B') {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_WORKFLOW, 'Job workflow must be A or B (OD-DUAL).');
    }

    if ($workflow === 'A') {
        if (empty($request['wfa_protections_ack'])) {
            return orange_cpr_appr_fail(
                ORANGE_CPR_APPR_ERR_WORKFLOW,
                'Workflow A requires wfa_protections_ack (OD-DUAL technical protections).'
            );
        }
    } else {
        // WF-B: Country Admin may prepare; Super Admin must have recorded approval before execute.
        if (empty($request['super_admin_approval_recorded'])) {
            return orange_cpr_appr_fail(
                ORANGE_CPR_APPR_ERR_WF_B,
                'Workflow B requires Super Admin approval recorded before live pre-PONR (OD-DUAL).'
            );
        }
        $approvalId = trim((string) ($request['super_admin_approval_id'] ?? ''));
        if ($approvalId === '') {
            return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_WF_B, 'Workflow B requires super_admin_approval_id.');
        }
    }

    return orange_cpr_appr_ok([
        'workflow' => $workflow,
        'actor_admin_id' => $actorId,
    ]);
}

/**
 * Re-validate execution contract + fingerprints vs re-read evidence.
 *
 * @param array<string, mixed> $contract
 * @param array<string, mixed> $job
 * @param array<string, mixed> $reread
 * @return array<string, mixed>
 */
function orange_cpr_approvals_live_revalidate_contract(array $contract, array $job, array $reread): array
{
    if (empty($contract['contract_frozen'])) {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_CONTRACT, 'Execution contract is not frozen.');
    }
    if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_FINGERPRINT, 'Contract identity drifted vs job.');
    }
    if ((string) ($contract['c8_overall_result'] ?? '') !== 'SAFE') {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_FINGERPRINT, 'Contract C8 is not SAFE.');
    }

    $keys = [
        'package_fingerprint',
        'country_id',
        'schema_revision_expected',
        'c4_report_hash',
        'c5_report_hash',
        'c6_report_hash',
        'c7_report_hash',
        'c8_report_hash',
        'inventory_snapshot_id',
        'inventory_snapshot_hash',
        'production_db_identity_hash',
    ];
    foreach ($keys as $key) {
        if (!array_key_exists($key, $reread)) {
            return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_FINGERPRINT, 'Re-read missing field: ' . $key);
        }
        $expected = $contract[$key] ?? null;
        if ((string) $reread[$key] !== (string) $expected) {
            return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_FINGERPRINT, 'Fingerprint drift: ' . $key);
        }
    }
    if ((string) ($reread['c8_overall_result'] ?? '') !== 'SAFE') {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_FINGERPRINT, 'Re-read C8 is not SAFE.');
    }

    return orange_cpr_appr_ok();
}

/**
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_approvals_live_revalidate_checkpoints(array $env, string $jobId): array
{
    $required = ['CP0', 'CP2', 'CP3', 'CP4', 'CP1', 'runbook_pre_ponr', 'CP5'];
    $missing = [];
    foreach ($required as $cpId) {
        $loaded = orange_cpr_checkpoint_load($env, $jobId, $cpId);
        if (empty($loaded['ok'])) {
            $missing[] = $cpId;
        }
    }
    if ($missing !== []) {
        return orange_cpr_appr_fail(
            ORANGE_CPR_APPR_ERR_CHECKPOINT,
            'Required checkpoints missing/invalid: ' . implode(',', $missing)
        );
    }

    return orange_cpr_appr_ok(['checkpoints_ok' => $required]);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $job
 * @param array<string, mixed> $contract
 * @param array<string, mixed> $reread
 * @return array<string, mixed>
 */
function orange_cpr_approvals_live_revalidate_inventory_and_pin(
    array $env,
    string $jobId,
    array $job,
    array $contract,
    array $reread
): array {
    $invId = (string) ($contract['inventory_snapshot_id'] ?? '');
    $invHash = (string) ($contract['inventory_snapshot_hash'] ?? '');
    if ($invId === '' || $invHash === '') {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_INVENTORY, 'Contract inventory snapshot missing.');
    }
    if ((string) ($reread['inventory_snapshot_id'] ?? '') !== $invId
        || (string) ($reread['inventory_snapshot_hash'] ?? '') !== $invHash
    ) {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_INVENTORY, 'Inventory snapshot drift vs contract.');
    }
    if (!empty($reread['inventory_replaced_by_live'])) {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_INVENTORY, 'OD-INV: live replace of certified inventory forbidden.');
    }

    $cp1 = orange_cpr_checkpoint_load($env, $jobId, 'CP1');
    if (empty($cp1['ok'])) {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_PIN, 'Session Full Backup CP1 missing.');
    }
    $payload = is_array($cp1['checkpoint']['payload'] ?? null) ? $cp1['checkpoint']['payload'] : [];
    if (empty($payload['pinned']) || empty($payload['verified'])) {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_PIN, 'Session Full Backup not pinned/verified.');
    }
    if (!empty($payload['reused_existing_backup'])) {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_PIN, 'OD-PIN: reused existing backup forbidden.');
    }
    $sid = (string) ($payload['session_full_backup_id'] ?? '');
    $sfp = (string) ($payload['session_full_backup_fingerprint'] ?? '');
    if ($sid === '' || $sfp === '') {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_PIN, 'Session Full Backup id/fingerprint missing on CP1.');
    }
    if ((string) ($reread['session_full_backup_id'] ?? '') !== $sid
        || (string) ($reread['session_full_backup_fingerprint'] ?? '') !== $sfp
    ) {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_PIN, 'Session Full Backup drift vs CP1.');
    }

    return orange_cpr_appr_ok([
        'session_full_backup_id' => $sid,
        'inventory_snapshot_id' => $invId,
        'job_country_id' => (int) ($job['country_id'] ?? 0),
    ]);
}

/**
 * Persist sealed live approvals outcome (immutable primary file).
 *
 * @param array<string, mixed> $record
 */
function orange_cpr_approvals_live_persist(string $cprRoot, string $jobId, array $record): string
{
    $dir = orange_cpr_approvals_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create approvals directory.');
    }
    $rid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($record['approvals_live_id'] ?? '')) ?: 'appr';
    $final = $dir . DIRECTORY_SEPARATOR . 'cpr_approvals_live_' . $rid . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Approvals live record immutable; file exists.');
    }
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Approvals live encode failed.');
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Approvals live tmp write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    $latest = $dir . DIRECTORY_SEPARATOR . 'cpr_approvals_live_latest.json';
    $tmp2 = $latest . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp2, $json) !== false) {
        try {
            orange_cpr_atomic_rename_replace($tmp2, $latest);
        } catch (RuntimeException) {
            @unlink($tmp2);
        }
    }

    return $final;
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_approvals_live_load_latest(string $cprRoot, string $jobId): ?array
{
    $path = orange_cpr_approvals_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_approvals_live_latest.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Live pre-PONR approvals + contract freeze + P3 authorization consume.
 *
 * Deterministic outcomes. Fail-closed. No PONR mutation.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_approvals_live_pre_ponr(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_appr_fail(
            ORANGE_CPR_APPR_ERR_ENABLEMENT,
            'Enablement must remain FALSE (OD-ENABLE / P9).'
        );
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_STATE, $e->getMessage());
    }

    $dual = orange_cpr_approvals_live_validate_dual($job, $request);
    if (empty($dual['ok'])) {
        return $dual;
    }
    $actorAdminId = (int) $dual['actor_admin_id'];

    if (!empty($job['ponr_crossed'])) {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_PONR, 'PONR already crossed; live approvals forbidden.');
    }

    $state = (string) ($job['state'] ?? '');
    if ($state !== 'cpr_pre_ponr') {
        return orange_cpr_appr_fail(
            ORANGE_CPR_APPR_ERR_STATE,
            'Live pre-PONR approvals require state cpr_pre_ponr; got ' . $state
        );
    }

    $existingLive = orange_cpr_approvals_live_load_latest($cprRoot, $jobId);
    if (is_array($existingLive) && orange_cpr_auth_verify_seal($existingLive)
        && !empty($existingLive['authorization_ok'])
    ) {
        return orange_cpr_appr_fail(
            ORANGE_CPR_APPR_ERR_DUPLICATE,
            'Active sealed live approvals record already exists for this job.'
        );
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract)) {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_CONTRACT, 'Execution contract missing.');
    }

    $reread = is_array($request['reread'] ?? null) ? $request['reread'] : [];
    $rvContract = orange_cpr_approvals_live_revalidate_contract($contract, $job, $reread);
    if (empty($rvContract['ok'])) {
        return $rvContract;
    }

    $rvCp = orange_cpr_approvals_live_revalidate_checkpoints($env, $jobId);
    if (empty($rvCp['ok'])) {
        return $rvCp;
    }

    $rvInvPin = orange_cpr_approvals_live_revalidate_inventory_and_pin($env, $jobId, $job, $contract, $reread);
    if (empty($rvInvPin['ok'])) {
        return $rvInvPin;
    }

    $lock = orange_cpr_lock_read($cprRoot);
    $lockCheck = orange_cpr_auth_validate_lock_ownership($lock, $job, $request);
    if (empty($lockCheck['ok'])) {
        return orange_cpr_appr_fail(
            ORANGE_CPR_APPR_ERR_LOCK,
            (string) ($lockCheck['message'] ?? 'Lock ownership validation failed.'),
            ['upstream_code' => (string) ($lockCheck['code'] ?? '')]
        );
    }

    $maxAge = (int) ($request['gate_max_age_seconds'] ?? ORANGE_CPR_AUTH_GATE_MAX_AGE_SECONDS);
    if ($maxAge <= 0) {
        $maxAge = ORANGE_CPR_AUTH_GATE_MAX_AGE_SECONDS;
    }
    $gate = orange_cpr_gate_evaluation_load_latest($cprRoot, $jobId, 'pre_ponr_full');
    $gateCheck = orange_cpr_auth_validate_gate_report($gate, $job, $maxAge);
    if (empty($gateCheck['ok'])) {
        return orange_cpr_appr_fail(
            ORANGE_CPR_APPR_ERR_GATE,
            (string) ($gateCheck['message'] ?? 'Gate validation failed.'),
            ['upstream_code' => (string) ($gateCheck['code'] ?? '')]
        );
    }

    // Live contract freeze immediately before authorization (P3 helper).
    $freeze = orange_cpr_contract_freeze_pre_ponr($env, $jobId, $reread, $actorAdminId);
    if (empty($freeze['ok'])) {
        $code = (string) ($freeze['code'] ?? ORANGE_CPR_APPR_ERR_CONTRACT);
        $mapped = match ($code) {
            'auth_fingerprint_drift' => ORANGE_CPR_APPR_ERR_FINGERPRINT,
            'auth_pin_missing' => ORANGE_CPR_APPR_ERR_PIN,
            'auth_state_invalid' => ORANGE_CPR_APPR_ERR_STATE,
            'auth_c8_not_safe' => ORANGE_CPR_APPR_ERR_FINGERPRINT,
            'auth_duplicate' => ORANGE_CPR_APPR_ERR_DUPLICATE,
            default => ORANGE_CPR_APPR_ERR_CONTRACT,
        };

        return orange_cpr_appr_fail(
            $mapped,
            (string) ($freeze['message'] ?? 'Live contract freeze failed.'),
            ['upstream_code' => $code]
        );
    }

    // Consume P3 authorization engine (phrase + reauth + runbook + bindings).
    $authReq = [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'phrase' => (string) ($request['phrase'] ?? ''),
        'password_reauth_ok' => !empty($request['password_reauth_ok']),
        'lease_token' => (string) ($request['lease_token'] ?? ''),
        'worker_id' => (string) ($request['worker_id'] ?? ''),
        'reread' => $reread,
        'gate_max_age_seconds' => $maxAge,
    ];
    $auth = orange_cpr_ponr_authorize($env, $jobId, $authReq);
    if (empty($auth['ok'])) {
        return orange_cpr_appr_fail(
            ORANGE_CPR_APPR_ERR_AUTH,
            (string) ($auth['message'] ?? 'P3 authorization engine refused.'),
            [
                'upstream_code' => (string) ($auth['code'] ?? ''),
                'contract_fingerprint' => (string) ($freeze['contract_fingerprint'] ?? ''),
            ]
        );
    }

    $now = gmdate('c');
    $approvalsLiveId = orange_cpr_generate_job_id();
    $record = orange_cpr_auth_seal([
        'schema_version' => ORANGE_CPR_APPROVALS_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_APPROVALS_LIVE_VERSION,
        'approvals_live_id' => $approvalsLiveId,
        'job_id' => $jobId,
        'workflow' => (string) ($job['workflow'] ?? ''),
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'wfa_protections_ack' => !empty($request['wfa_protections_ack']),
        'super_admin_approval_recorded' => !empty($request['super_admin_approval_recorded']),
        'super_admin_approval_id' => (string) ($request['super_admin_approval_id'] ?? ''),
        'contract_phase' => 'pre_ponr',
        'contract_fingerprint' => (string) ($freeze['contract_fingerprint'] ?? ''),
        'gate_content_sha256' => (string) ($gate['content_sha256'] ?? ''),
        'one_time_authorization_id' => (string) ($auth['one_time_authorization_id'] ?? ''),
        'authorization_id' => (string) ($auth['authorization']['authorization_id'] ?? ''),
        'authorization_ok' => true,
        'inventory_snapshot_id' => (string) ($rvInvPin['inventory_snapshot_id'] ?? ''),
        'session_full_backup_id' => (string) ($rvInvPin['session_full_backup_id'] ?? ''),
        'enablement_flag_observed' => false,
        'ponr_authorized' => true,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'http_mutation_forbidden' => true,
        'created_at' => $now,
        'scaffold_record_only' => false,
        'live_path' => true,
    ]);

    try {
        $path = orange_cpr_approvals_live_persist($cprRoot, $jobId, $record);
    } catch (RuntimeException $e) {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_DUPLICATE, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.approvals_live_pre_ponr',
        'job_id' => $jobId,
        'approvals_live_id' => $approvalsLiveId,
        'actor_admin_id' => $actorAdminId,
        'workflow' => (string) ($job['workflow'] ?? ''),
        'contract_fingerprint' => (string) ($freeze['contract_fingerprint'] ?? ''),
        'one_time_authorization_id' => (string) ($auth['one_time_authorization_id'] ?? ''),
        'authorization_ok' => true,
        'enablement_flag_observed' => false,
        'ponr_authorized' => true,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
    ]);

    // Hard refuse helpers still available; ensure job not crossed.
    $jobAfter = orange_cpr_job_read($cprRoot, $jobId);
    if (!empty($jobAfter['ponr_crossed'])) {
        return orange_cpr_appr_fail(ORANGE_CPR_APPR_ERR_PONR, 'Invariant broken: ponr_crossed became true.');
    }

    return orange_cpr_appr_ok([
        'code' => 'ok',
        'message' => 'Live pre-PONR approvals complete; contract frozen; P3 authorization sealed; no PONR mutation.',
        'approvals_live' => $record,
        'approvals_live_path' => $path,
        'authorization' => $auth['authorization'] ?? null,
        'one_time_authorization_id' => (string) ($auth['one_time_authorization_id'] ?? ''),
        'contract_fingerprint' => (string) ($freeze['contract_fingerprint'] ?? ''),
        'ponr_authorized' => true,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
    ]);
}
