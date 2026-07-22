<?php

declare(strict_types=1);

/**
 * CPR Live GLOBAL Maintenance Release Engine (WP-P6-05) — Architecture §18 CP12.
 *
 * Releases GLOBAL maintenance exactly once after an approved terminal path:
 *   - Success finalize through CP11 (cpr_succeeded), or
 *   - OD-ROLLBACK completion (cpr_rollback_completed).
 * Produces sealed release report/manifest + CP12. Never early, never partial,
 * never automatic. Enablement remains FALSE (no production SQL/uploads mutation).
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P6_05_MAINT_RELEASE.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_04_CHECKPOINT_SCHEMAS.md CP12
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_06_AUTHORITY_RUNBOOK.md §8.4
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md OD-MAINT · OD-RUNBOOK
 */

require_once __DIR__ . '/cpr_success_finalize_live.php';
require_once __DIR__ . '/cpr_rollback_live.php';
require_once __DIR__ . '/cpr_maintenance_live.php';
require_once __DIR__ . '/cpr_lock_engine.php';
require_once __DIR__ . '/cpr_state_engine.php';

const ORANGE_CPR_MAINT_RELEASE_LIVE_SCHEMA = 'cpr_maint_release_live/1';
const ORANGE_CPR_MAINT_RELEASE_LIVE_VERSION = 'P6-05-1.0';
const ORANGE_CPR_MAINT_RELEASE_MANIFEST_SCHEMA = 'cpr_maint_release_manifest/1';

const ORANGE_CPR_MRLIVE_ERR_ENABLEMENT = 'mrlive_enablement_forbidden';
const ORANGE_CPR_MRLIVE_ERR_ACTOR = 'mrlive_actor_not_super_admin';
const ORANGE_CPR_MRLIVE_ERR_STATE = 'mrlive_state_invalid';
const ORANGE_CPR_MRLIVE_ERR_CONTRACT = 'mrlive_contract_mismatch';
const ORANGE_CPR_MRLIVE_ERR_IDENTITY = 'mrlive_identity_drift';
const ORANGE_CPR_MRLIVE_ERR_LOCK = 'mrlive_lock_mismatch';
const ORANGE_CPR_MRLIVE_ERR_AUTHORITY = 'mrlive_authority_mismatch';
const ORANGE_CPR_MRLIVE_ERR_COUNTRY = 'mrlive_country_mismatch';
const ORANGE_CPR_MRLIVE_ERR_SCHEMA = 'mrlive_schema_mismatch';
const ORANGE_CPR_MRLIVE_ERR_FINGERPRINT = 'mrlive_fingerprint_mismatch';
const ORANGE_CPR_MRLIVE_ERR_REPLAY = 'mrlive_replay_forbidden';
const ORANGE_CPR_MRLIVE_ERR_BYPASS = 'mrlive_bypass_forbidden';
const ORANGE_CPR_MRLIVE_ERR_TRANSITION = 'mrlive_transition_failed';
const ORANGE_CPR_MRLIVE_ERR_CHECKPOINT = 'mrlive_checkpoint_failed';
const ORANGE_CPR_MRLIVE_ERR_PERSIST = 'mrlive_persist_failed';
const ORANGE_CPR_MRLIVE_ERR_MAINT = 'mrlive_maint_not_active';
const ORANGE_CPR_MRLIVE_ERR_RUNBOOK = 'mrlive_runbook_required';
const ORANGE_CPR_MRLIVE_ERR_TERMINAL = 'mrlive_terminal_required';
const ORANGE_CPR_MRLIVE_ERR_CP11 = 'mrlive_cp11_required';
const ORANGE_CPR_MRLIVE_ERR_ROLLBACK = 'mrlive_rollback_required';
const ORANGE_CPR_MRLIVE_ERR_PARTIAL = 'mrlive_partial_release_forbidden';
const ORANGE_CPR_MRLIVE_ERR_EARLY = 'mrlive_early_release_forbidden';
const ORANGE_CPR_MRLIVE_ERR_AUTO = 'mrlive_auto_release_forbidden';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_mrlive_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'maintenance_released' => true,
        'partial_release' => false,
        'auto_release' => false,
        'enablement_flag_observed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_mrlive_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'fail_closed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'maintenance_released' => false,
        'partial_release' => false,
        'auto_release' => false,
        'partial_acceptance' => false,
        'best_effort' => false,
        'enablement_flag_observed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_maint_release_live_persist(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_maint_release_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR maint_release directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Maint release record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Maint release record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_maint_release_live_latest_path(string $cprRoot, string $jobId, string $kind): string
{
    return orange_cpr_maint_release_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_maint_release_' . $kind . '_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_maint_release_live_load_latest(string $cprRoot, string $jobId, string $kind): ?array
{
    $path = orange_cpr_maint_release_live_latest_path($cprRoot, $jobId, $kind);
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
function orange_cpr_maint_release_live_refuse_unsafe(array $request): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_lock', 'skip_authority',
        'skip_runbook', 'skip_cp11', 'skip_rollback', 'skip_cp12',
        'auto_release', 'timeout_release', 'wall_clock_release',
        'partial_release', 'early_release', 'begin_p7', 'begin_p6_06',
        'execute_production_sql', 'mutate_uploads',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            if (in_array($k, ['auto_release', 'timeout_release', 'wall_clock_release'], true)) {
                return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_AUTO, 'Automatic maint release forbidden: ' . $k);
            }
            if (in_array($k, ['partial_release', 'early_release'], true)) {
                return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_PARTIAL, 'Partial/early release forbidden: ' . $k);
            }

            return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_BYPASS, 'Unsafe maint-release knob forbidden: ' . $k);
        }
    }

    return orange_cpr_mrlive_ok(['message' => 'unsafe knobs absent', 'maintenance_released' => false]);
}

/**
 * @param array<string, mixed> $job
 * @param array<string, mixed> $contract
 * @return array<string, mixed>
 */
function orange_cpr_maint_release_live_assert_terminal_path(
    array $env,
    string $cprRoot,
    string $jobId,
    array $job,
    array $contract
): array {
    $state = (string) ($job['state'] ?? '');

    if ($state === 'cpr_succeeded') {
        if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP11')) {
            return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_CP11, 'CP11 required before maint release on success path.');
        }
        $cp11 = orange_cpr_checkpoint_load($env, $jobId, 'CP11');
        if (empty($cp11['ok'])) {
            return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_CP11, 'CP11 unreadable — fail-closed.');
        }
        $sf = orange_cpr_success_finalize_live_load_latest($cprRoot, $jobId, 'report');
        if (!is_array($sf) || empty($sf['success_finalized']) || !orange_cpr_auth_verify_seal($sf)) {
            return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_CP11, 'Sealed success finalize report required with CP11.');
        }

        return orange_cpr_mrlive_ok([
            'terminal_path' => 'success_cp11',
            'prior_terminal' => 'cpr_succeeded',
            'transition_trigger' => 'super_admin_release_maint',
            'transition_id' => 'T14',
            'maintenance_released' => false,
        ]);
    }

    if ($state === 'cpr_rollback_completed') {
        $rb = orange_cpr_rollback_live_load_latest($cprRoot, $jobId, 'report');
        if (!is_array($rb) || empty($rb['rollback_completed']) || !orange_cpr_auth_verify_seal($rb)) {
            return orange_cpr_mrlive_fail(
                ORANGE_CPR_MRLIVE_ERR_ROLLBACK,
                'Sealed rollback completion report required before maint release.'
            );
        }
        if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP11')) {
            return orange_cpr_mrlive_fail(
                ORANGE_CPR_MRLIVE_ERR_TERMINAL,
                'CP11 present with rollback terminal — inconsistent closeout path.'
            );
        }

        return orange_cpr_mrlive_ok([
            'terminal_path' => 'rollback_completed',
            'prior_terminal' => 'cpr_rollback_completed',
            'transition_trigger' => 'release_maint',
            'transition_id' => 'T57',
            'maintenance_released' => false,
        ]);
    }

    return orange_cpr_mrlive_fail(
        ORANGE_CPR_MRLIVE_ERR_TERMINAL,
        'Maint release only after CP11 success or approved rollback completion (WP-P6-05).',
        ['job_state' => $state]
    );
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_maint_release_live_assert_preconditions(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_mrlive_fail(
            ORANGE_CPR_MRLIVE_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during P6 maint release.'
        );
    }
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_ACTOR, 'Super Admin required for GLOBAL Maint release.');
    }
    if (!empty($request['actor_is_country_admin'])) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_ACTOR, 'Country Admin cannot release GLOBAL Maintenance (OD-PERM).');
    }

    $unsafe = orange_cpr_maint_release_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    if (empty($request['runbook_completed'])) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_RUNBOOK, 'Runbook completion required (OD-RUNBOOK).');
    }
    $runbookRef = trim((string) ($request['runbook_evidence_ref'] ?? ''));
    if ($runbookRef === '') {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_RUNBOOK, 'runbook_evidence_ref required (OD-RUNBOOK).');
    }
    $writeBlockCleared = trim((string) ($request['write_block_cleared_proof'] ?? ''));
    if ($writeBlockCleared === '') {
        return orange_cpr_mrlive_fail(
            ORANGE_CPR_MRLIVE_ERR_PARTIAL,
            'write_block_cleared_proof required — no partial release (P1-06 §8.4).'
        );
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_STATE, $e->getMessage());
    }
    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_CONTRACT, 'Frozen execution contract required.');
    }
    if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_IDENTITY, 'Contract/job identity drift.');
    }
    if (isset($request['package_fingerprint'])
        && (string) $request['package_fingerprint'] !== (string) ($job['package_fingerprint'] ?? '')
    ) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_FINGERPRINT, 'Request package_fingerprint mismatch.');
    }
    if (isset($request['country_id'])
        && (int) $request['country_id'] !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_COUNTRY, 'Country mismatch refused.');
    }
    if (isset($request['schema_revision'])
        && (int) $request['schema_revision'] !== (int) ($contract['schema_revision_expected'] ?? -1)
    ) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_SCHEMA, 'Request schema_revision mismatch.');
    }

    $terminal = orange_cpr_maint_release_live_assert_terminal_path($env, $cprRoot, $jobId, $job, $contract);
    if (empty($terminal['ok'])) {
        return $terminal;
    }

    $auth = orange_cpr_auth_live_load_latest($cprRoot, $jobId);
    if (!is_array($auth) || empty($auth['ponr_authorized']) || !orange_cpr_auth_verify_seal($auth)) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_AUTHORITY, 'Sealed authority_live required for maint release.');
    }

    $lockRv = orange_cpr_uploads_live_assert_lock_ownership($env, $jobId, $request, $job, $contract);
    if (empty($lockRv['ok'])) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_LOCK, (string) ($lockRv['message'] ?? 'Lock ownership mismatch.'));
    }

    $maint = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if (!is_array($maint) || empty($maint['global_maintenance_on']) || !orange_cpr_auth_verify_seal($maint)) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_MAINT, 'GLOBAL maintenance must be active before release.');
    }
    if (!empty($maint['paused_failure'])) {
        return orange_cpr_mrlive_fail(
            ORANGE_CPR_MRLIVE_ERR_EARLY,
            'Cannot release while paused_failure without approved terminal closeout.'
        );
    }
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP12')) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_REPLAY, 'CP12 already present — release exactly once.');
    }

    return orange_cpr_mrlive_ok([
        'actor_admin_id' => (int) $request['actor_admin_id'],
        'job' => $job,
        'contract' => $contract,
        'cpr_root' => $cprRoot,
        'job_state' => (string) ($job['state'] ?? ''),
        'schema_revision' => (int) ($contract['schema_revision_expected'] ?? 0),
        'terminal_path' => (string) $terminal['terminal_path'],
        'prior_terminal' => (string) $terminal['prior_terminal'],
        'transition_trigger' => (string) $terminal['transition_trigger'],
        'transition_id' => (string) $terminal['transition_id'],
        'runbook_evidence_ref' => $runbookRef,
        'write_block_cleared_proof' => $writeBlockCleared,
        'maint_state' => $maint,
        'maintenance_released' => false,
    ]);
}

/**
 * Execute GLOBAL Maintenance Release through CP12 (exactly once).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_maint_release_live_run(array $env, string $jobId, array $request = []): array
{
    $cprRootEarly = orange_cpr_resolve_work_root($env);
    $existing = orange_cpr_maint_release_live_load_latest($cprRootEarly, $jobId, 'report');
    if (is_array($existing) && !empty($existing['maintenance_released'])
        && orange_cpr_checkpoint_exists($cprRootEarly, $jobId, 'CP12')
    ) {
        if (!empty($request['force_replay'])) {
            return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_REPLAY, 'Replay of completed maint release forbidden.');
        }
        try {
            $jobEarly = orange_cpr_job_read($cprRootEarly, $jobId);
        } catch (RuntimeException $e) {
            return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_STATE, $e->getMessage());
        }
        if ((string) ($jobEarly['state'] ?? '') !== 'cpr_maintenance_released') {
            return orange_cpr_mrlive_fail(
                ORANGE_CPR_MRLIVE_ERR_STATE,
                'CP12/report present but job state is not cpr_maintenance_released.'
            );
        }

        return orange_cpr_mrlive_ok([
            'message' => 'Maint release already complete (idempotent; exactly once).',
            'job_id' => $jobId,
            'idempotent' => true,
            'exactly_once' => true,
            'release_report' => $existing,
            'release_manifest' => orange_cpr_maint_release_live_load_latest($cprRootEarly, $jobId, 'manifest'),
            'checkpoint_id' => 'CP12',
            'job_state' => 'cpr_maintenance_released',
            'maintenance_released' => true,
            'production_sql_executed' => false,
            'production_uploads_mutated' => false,
        ]);
    }
    if (orange_cpr_checkpoint_exists($cprRootEarly, $jobId, 'CP12') && !is_array($existing)) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_REPLAY, 'CP12 exists without sealed maint release report.');
    }

    $pre = orange_cpr_maint_release_live_assert_preconditions($env, $jobId, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }

    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    /** @var array<string, mixed> $maint */
    $maint = $pre['maint_state'];
    $cprRoot = (string) $pre['cpr_root'];
    $actorAdminId = (int) $pre['actor_admin_id'];
    $priorTerminal = (string) $pre['prior_terminal'];
    $trigger = (string) $pre['transition_trigger'];
    $runbookRef = (string) $pre['runbook_evidence_ref'];
    $writeBlockCleared = (string) $pre['write_block_cleared_proof'];

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.maint_release_live_start',
        'job_id' => $jobId,
        'actor_admin_id' => $actorAdminId,
        'prior_terminal' => $priorTerminal,
        'terminal_path' => (string) $pre['terminal_path'],
        'runbook_completed' => true,
        'production_sql_executed' => false,
        'enablement_flag_observed' => false,
    ]);

    // Authorized post-PONR lock closeout (never TTL/auto).
    $lockRel = orange_cpr_lock_release($env, $jobId, [
        'lease_token' => (string) ($request['lease_token'] ?? ''),
        'authorized_closeout' => true,
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'actor_admin_id' => $actorAdminId,
    ]);
    if (empty($lockRel['ok'])) {
        return orange_cpr_mrlive_fail(
            ORANGE_CPR_MRLIVE_ERR_LOCK,
            (string) ($lockRel['message'] ?? 'Authorized lock closeout failed.'),
            ['upstream' => $lockRel]
        );
    }

    $tr = orange_cpr_transition_apply($env, $jobId, 'cpr_maintenance_released', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'actor_admin_id' => $actorAdminId,
        'trigger' => $trigger,
        'runbook_completed' => true,
    ]);
    if (empty($tr['ok'])) {
        return orange_cpr_mrlive_fail(
            ORANGE_CPR_MRLIVE_ERR_TRANSITION,
            (string) ($tr['message'] ?? 'Maint release transition failed.'),
            ['upstream' => $tr]
        );
    }

    $now = gmdate('c');
    $releaseId = orange_cpr_generate_job_id();
    $manifestId = orange_cpr_generate_job_id();
    $auditRecordId = orange_cpr_generate_job_id();

    $maint['lifecycle'] = ORANGE_CPR_MAINT_LIFECYCLE_RELEASED;
    $maint['global_maintenance_on'] = false;
    $maint['runbook_completed'] = true;
    $maint['released_at'] = $now;
    $maint['released_by_admin_id'] = $actorAdminId;
    $maint['session_full_backup_pin_allowed'] = false;
    $maint['paused_failure'] = false;
    $maint['updated_at'] = $now;
    unset($maint['content_sha256'], $maint['seal'], $maint['sealed_sha256']);

    try {
        orange_cpr_maint_live_persist_state($cprRoot, $jobId, $maint);
    } catch (RuntimeException $e) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_PERSIST, $e->getMessage());
    }

    $binding = [
        'job_id' => $jobId,
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'package_id' => (string) ($job['package_id'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'contract_revision' => (int) ($contract['contract_revision'] ?? 1),
        'schema_revision' => (int) $pre['schema_revision'],
        'inventory_snapshot_id' => (string) ($contract['inventory_snapshot_id'] ?? ''),
        'inventory_snapshot_hash' => (string) ($contract['inventory_snapshot_hash'] ?? ''),
        'session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
        'session_full_backup_fingerprint' => (string) ($contract['session_full_backup_fingerprint'] ?? ''),
        'production_db_identity_hash' => (string) ($contract['production_db_identity_hash'] ?? ''),
        'c4_report_hash' => (string) ($contract['c4_report_hash'] ?? ''),
        'c8_report_hash' => (string) ($contract['c8_report_hash'] ?? ''),
    ];

    $sealedManifest = array_merge($binding, [
        'schema_version' => ORANGE_CPR_MAINT_RELEASE_MANIFEST_SCHEMA,
        'engine_version' => ORANGE_CPR_MAINT_RELEASE_LIVE_VERSION,
        'record_type' => 'cpr_maint_release_manifest',
        'manifest_id' => $manifestId,
        'release_id' => $releaseId,
        'prior_terminal' => $priorTerminal,
        'terminal_path' => (string) $pre['terminal_path'],
        'transition_id' => (string) ($tr['transition_id'] ?? $pre['transition_id']),
        'cp12_ref' => 'CP12',
        'runbook_completed' => true,
        'runbook_evidence_ref' => $runbookRef,
        'writers_restored' => true,
        'write_block_cleared_proof' => $writeBlockCleared,
        'partial_release' => false,
        'auto_release' => false,
        'maintenance_released' => true,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'created_at' => $now,
    ]);
    $sealedManifest['manifest_fingerprint'] = hash(
        'sha256',
        (string) json_encode([
            'job_id' => $jobId,
            'release_id' => $releaseId,
            'prior_terminal' => $priorTerminal,
            'runbook_completed' => true,
            'writers_restored' => true,
            'maintenance_released' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $report = array_merge($binding, [
        'schema_version' => ORANGE_CPR_MAINT_RELEASE_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_MAINT_RELEASE_LIVE_VERSION,
        'record_type' => 'cpr_maint_release_report',
        'release_id' => $releaseId,
        'manifest_id' => $manifestId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'maintenance_released' => true,
        'overall_result' => 'MAINT_RELEASED',
        'prior_terminal' => $priorTerminal,
        'terminal_path' => (string) $pre['terminal_path'],
        'released_by_admin_id' => $actorAdminId,
        'runbook_completed' => true,
        'runbook_evidence_ref' => $runbookRef,
        'writers_restored' => true,
        'write_block_cleared_proof' => $writeBlockCleared,
        'audit_record_id' => $auditRecordId,
        'cp12_ref' => 'CP12',
        'manifest_fingerprint' => $sealedManifest['manifest_fingerprint'],
        'partial_release' => false,
        'auto_release' => false,
        'exactly_once' => true,
        'global_maintenance_on' => false,
        'ops_enablement_flag' => false,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
        'sealed' => true,
        'actor_admin_id' => $actorAdminId,
        'evaluated_at' => $now,
        'created_at' => $now,
        'recovery_metadata' => [
            'od_maint_release' => 'complete',
            'od_runbook' => 'complete',
            'auto_release' => false,
            'partial_release' => false,
            'maint_released' => true,
            'global_maintenance_on' => false,
            'writers_restored' => true,
            'prior_terminal' => $priorTerminal,
            'completed_phase' => 'maint_release',
            'execution_status' => 'maintenance_released',
            'cp12' => true,
            'production_sql_executed' => false,
            'production_uploads_mutated' => false,
        ],
    ]);

    try {
        $mPath = orange_cpr_maint_release_live_persist(
            $cprRoot,
            $jobId,
            'cpr_maint_release_manifest_' . $manifestId,
            $sealedManifest
        );
        $rPath = orange_cpr_maint_release_live_persist(
            $cprRoot,
            $jobId,
            'cpr_maint_release_report_' . $releaseId,
            $report
        );
        foreach ([
            'manifest' => array_merge($sealedManifest, ['manifest_path' => $mPath]),
            'report' => array_merge($report, ['release_report_path' => $rPath]),
        ] as $kind => $rec) {
            $latest = orange_cpr_maint_release_live_latest_path($cprRoot, $jobId, $kind);
            $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
            $sealed = orange_cpr_auth_seal($rec);
            $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false || @file_put_contents($tmp, $json) === false) {
                throw new RuntimeException('Maint release latest write failed: ' . $kind);
            }
            orange_cpr_atomic_rename_replace($tmp, $latest);
        }
    } catch (RuntimeException $e) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_PERSIST, $e->getMessage());
    }

    $reportCheck = orange_cpr_maint_release_live_load_latest($cprRoot, $jobId, 'report');
    $manifestCheck = orange_cpr_maint_release_live_load_latest($cprRoot, $jobId, 'manifest');
    if (!is_array($reportCheck) || !is_array($manifestCheck)
        || empty($reportCheck['maintenance_released'])
        || empty($manifestCheck['writers_restored'])
        || !empty($manifestCheck['partial_release'])
    ) {
        return orange_cpr_mrlive_fail(
            ORANGE_CPR_MRLIVE_ERR_PARTIAL,
            'Maint release evidence incomplete — refusing partial release.'
        );
    }

    $cp12 = orange_cpr_checkpoint_create($env, $jobId, 'CP12', [
        'released_by_admin_id' => $actorAdminId,
        'runbook_completed' => true,
        'prior_terminal' => $priorTerminal,
        'writers_restored' => true,
    ], [
        'written_by' => 'system',
        'actor' => 'super_admin',
        'actor_is_super_admin' => true,
        'actor_admin_id' => $actorAdminId,
    ]);
    if (empty($cp12['ok'])) {
        return orange_cpr_mrlive_fail(
            ORANGE_CPR_MRLIVE_ERR_CHECKPOINT,
            (string) ($cp12['message'] ?? 'CP12 write failed.'),
            ['upstream' => $cp12]
        );
    }

    $jobAfter = orange_cpr_job_read($cprRoot, $jobId);
    $jobAfter['maintenance_released'] = true;
    $jobAfter['maint_release_id'] = $releaseId;
    $jobAfter['updated_at'] = $now;
    orange_cpr_job_write($cprRoot, $jobId, $jobAfter);

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.maint_release_live_complete',
        'job_id' => $jobId,
        'release_id' => $releaseId,
        'manifest_id' => $manifestId,
        'audit_record_id' => $auditRecordId,
        'checkpoint_id' => 'CP12',
        'prior_terminal' => $priorTerminal,
        'runbook_completed' => true,
        'writers_restored' => true,
        'maintenance_released' => true,
        'exactly_once' => true,
        'partial_release' => false,
        'auto_release' => false,
        'actor_admin_id' => $actorAdminId,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
    ]);

    $jobFinal = orange_cpr_job_read($cprRoot, $jobId);
    $maintFinal = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if ((string) ($jobFinal['state'] ?? '') !== 'cpr_maintenance_released') {
        return orange_cpr_mrlive_fail(
            ORANGE_CPR_MRLIVE_ERR_STATE,
            'Expected cpr_maintenance_released after CP12.',
            ['job_state' => (string) ($jobFinal['state'] ?? '')]
        );
    }
    if (!is_array($maintFinal) || !empty($maintFinal['global_maintenance_on'])) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_MAINT, 'GLOBAL maintenance must be OFF after CP12.');
    }
    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP12')) {
        return orange_cpr_mrlive_fail(ORANGE_CPR_MRLIVE_ERR_CHECKPOINT, 'CP12 missing after release.');
    }

    return orange_cpr_mrlive_ok([
        'message' => 'GLOBAL Maintenance released through CP12 (exactly once).',
        'job_id' => $jobId,
        'release_id' => $releaseId,
        'manifest_id' => $manifestId,
        'release_report' => orange_cpr_maint_release_live_load_latest($cprRoot, $jobId, 'report'),
        'release_manifest' => orange_cpr_maint_release_live_load_latest($cprRoot, $jobId, 'manifest'),
        'overall_result' => 'MAINT_RELEASED',
        'checkpoint_id' => 'CP12',
        'job_state' => 'cpr_maintenance_released',
        'prior_terminal' => $priorTerminal,
        'terminal_path' => (string) $pre['terminal_path'],
        'exactly_once' => true,
        'maintenance_released' => true,
        'writers_restored' => true,
        'partial_release' => false,
        'auto_release' => false,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
        'recovery_metadata' => $report['recovery_metadata'],
    ]);
}
