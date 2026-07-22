<?php

declare(strict_types=1);

/**
 * CPR Live Session Full-Anchor Rollback Engine (WP-P6-04) — OD-ROLLBACK.
 *
 * Explicit Super Admin Rollback only from approved pause/failure states.
 * Restores to the session Full Backup (OD-PIN) recovery boundary under
 * enablement FALSE (sealed ledger evidence; no production SQL/uploads mutation).
 * Maintenance remains ON (CP12 / WP-P6-05). Never automatic. Never partial.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P6_04_ROLLBACK_INTEGRATION.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_09_FAIL_RESUME_ROLLBACK.md §6
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md OD-ROLLBACK
 */

require_once __DIR__ . '/cpr_post_verify_live.php';
require_once __DIR__ . '/cpr_maintenance_live.php';
require_once __DIR__ . '/cpr_od_pin_live.php';
require_once __DIR__ . '/cpr_state_engine.php';

const ORANGE_CPR_ROLLBACK_LIVE_SCHEMA = 'cpr_rollback_live/1';
const ORANGE_CPR_ROLLBACK_LIVE_VERSION = 'P6-04-1.0';
const ORANGE_CPR_ROLLBACK_MANIFEST_SCHEMA = 'cpr_rollback_manifest/1';
const ORANGE_CPR_ROLLBACK_AUTH_SCHEMA = 'cpr_rollback_authorization/1';
const ORANGE_CPR_ROLLBACK_EVIDENCE_SCHEMA = 'cpr_rollback_recovery_evidence/1';

const ORANGE_CPR_RBLIVE_ERR_ENABLEMENT = 'rblive_enablement_forbidden';
const ORANGE_CPR_RBLIVE_ERR_ACTOR = 'rblive_actor_not_super_admin';
const ORANGE_CPR_RBLIVE_ERR_STATE = 'rblive_state_invalid';
const ORANGE_CPR_RBLIVE_ERR_CONTRACT = 'rblive_contract_mismatch';
const ORANGE_CPR_RBLIVE_ERR_IDENTITY = 'rblive_identity_drift';
const ORANGE_CPR_RBLIVE_ERR_LOCK = 'rblive_lock_loss';
const ORANGE_CPR_RBLIVE_ERR_GATE = 'rblive_gate_failure';
const ORANGE_CPR_RBLIVE_ERR_AUTHORITY = 'rblive_authority_failure';
const ORANGE_CPR_RBLIVE_ERR_COUNTRY = 'rblive_country_mismatch';
const ORANGE_CPR_RBLIVE_ERR_SCHEMA = 'rblive_schema_mismatch';
const ORANGE_CPR_RBLIVE_ERR_FINGERPRINT = 'rblive_fingerprint_mismatch';
const ORANGE_CPR_RBLIVE_ERR_REPLAY = 'rblive_replay_forbidden';
const ORANGE_CPR_RBLIVE_ERR_BYPASS = 'rblive_bypass_forbidden';
const ORANGE_CPR_RBLIVE_ERR_TRANSITION = 'rblive_transition_failed';
const ORANGE_CPR_RBLIVE_ERR_CHECKPOINT = 'rblive_checkpoint_corrupt';
const ORANGE_CPR_RBLIVE_ERR_PERSIST = 'rblive_persist_failed';
const ORANGE_CPR_RBLIVE_ERR_MAINT = 'rblive_maint_not_active';
const ORANGE_CPR_RBLIVE_ERR_RELEASE = 'rblive_maint_release_forbidden';
const ORANGE_CPR_RBLIVE_ERR_PHRASE = 'rblive_phrase_required';
const ORANGE_CPR_RBLIVE_ERR_REAUTH = 'rblive_reauth_required';
const ORANGE_CPR_RBLIVE_ERR_AUTO = 'rblive_auto_rollback_forbidden';
const ORANGE_CPR_RBLIVE_ERR_ELIGIBLE = 'rblive_not_eligible';
const ORANGE_CPR_RBLIVE_ERR_PIN = 'rblive_od_pin_required';
const ORANGE_CPR_RBLIVE_ERR_BOUNDARY = 'rblive_recovery_boundary_mismatch';
const ORANGE_CPR_RBLIVE_ERR_RECOVERY = 'rblive_recovery_metadata_missing';
const ORANGE_CPR_RBLIVE_ERR_MANIFEST = 'rblive_manifest_integrity';
const ORANGE_CPR_RBLIVE_ERR_PARTIAL = 'rblive_partial_forbidden';
const ORANGE_CPR_RBLIVE_ERR_SCOPE = 'rblive_out_of_scope';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_rblive_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'partial_rollback' => false,
        'undefined_rollback' => false,
        'auto_rollback' => false,
        'maintenance_released' => false,
        'rollback_executed' => true,
        'enablement_flag_observed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_rblive_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'fail_closed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'partial_rollback' => false,
        'undefined_rollback' => false,
        'auto_rollback' => false,
        'maintenance_released' => false,
        'rollback_executed' => false,
        'partial_acceptance' => false,
        'best_effort' => false,
        'enablement_flag_observed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_rollback_live_persist(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_rollback_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR rollback directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Rollback record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Rollback record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_rollback_live_latest_path(string $cprRoot, string $jobId, string $kind): string
{
    return orange_cpr_rollback_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_rollback_' . $kind . '_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_rollback_live_load_latest(string $cprRoot, string $jobId, string $kind): ?array
{
    $path = orange_cpr_rollback_live_latest_path($cprRoot, $jobId, $kind);
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
function orange_cpr_rollback_live_refuse_unsafe(array $request): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_lock', 'skip_gate', 'skip_authority',
        'skip_pin', 'skip_phrase', 'skip_reauth', 'integrity_waiver', 'success_with_warnings',
        'release_maint', 'release_maintenance', 'maint_release', 'writers_restored',
        'begin_cp12', 'begin_p6_05', 'execute_production_sql', 'mutate_uploads',
        'partial_rollback', 'undefined_rollback', 'auto_rollback',
        'reuse_other_backup', 'cross_country',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            if (in_array($k, ['release_maint', 'release_maintenance', 'maint_release', 'writers_restored', 'begin_cp12', 'begin_p6_05'], true)) {
                return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_RELEASE, 'Maintenance release forbidden in WP-P6-04: ' . $k);
            }
            if ($k === 'auto_rollback') {
                return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_AUTO, 'Automatic rollback forbidden (OD-ROLLBACK).');
            }
            if (in_array($k, ['partial_rollback', 'undefined_rollback'], true)) {
                return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_PARTIAL, 'Partial/undefined rollback forbidden: ' . $k);
            }

            return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_BYPASS, 'Unsafe rollback knob forbidden: ' . $k);
        }
    }

    return orange_cpr_rblive_ok(['message' => 'unsafe knobs absent', 'rollback_executed' => false]);
}

/**
 * Collect sealed P5 apply manifests required for recovery evidence binding.
 *
 * @return array<string, mixed>
 */
function orange_cpr_rollback_live_collect_apply_manifests(string $cprRoot, string $jobId): array
{
    // Import seals a summary (not a separate manifest latest); others use manifest latest.
    $specs = [
        'delete' => ['fn' => 'orange_cpr_delete_live_load_latest', 'kind' => 'manifest', 'id' => 'manifest_id', 'fp' => 'manifest_fingerprint'],
        'import' => ['fn' => 'orange_cpr_import_live_load_latest', 'kind' => 'summary', 'id' => 'import_summary_id', 'fp' => 'summary_fingerprint'],
        'special' => ['fn' => 'orange_cpr_special_live_load_latest', 'kind' => 'manifest', 'id' => 'manifest_id', 'fp' => 'manifest_fingerprint'],
        'uploads' => ['fn' => 'orange_cpr_uploads_live_load_latest', 'kind' => 'manifest', 'id' => 'manifest_id', 'fp' => 'manifest_fingerprint'],
    ];
    $out = [];
    foreach ($specs as $label => $spec) {
        $fn = (string) $spec['fn'];
        if (!function_exists($fn)) {
            return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_MANIFEST, 'Apply loader missing: ' . $fn);
        }
        /** @var callable $fn */
        $manifest = $fn($cprRoot, $jobId, (string) $spec['kind']);
        if (!is_array($manifest) || !orange_cpr_auth_verify_seal($manifest)) {
            return orange_cpr_rblive_fail(
                ORANGE_CPR_RBLIVE_ERR_MANIFEST,
                'Sealed ' . $label . ' ' . $spec['kind'] . ' required for rollback evidence.'
            );
        }
        $out[$label] = [
            'record_kind' => (string) $spec['kind'],
            'manifest_id' => (string) ($manifest[$spec['id']] ?? ''),
            'manifest_fingerprint' => (string) ($manifest[$spec['fp']] ?? ''),
            'sealed' => true,
        ];
    }

    return orange_cpr_rblive_ok(['apply_manifests' => $out, 'rollback_executed' => false]);
}

/**
 * Resolve recovery metadata from pause evidence (fail-closed if absent).
 *
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function orange_cpr_rollback_live_resolve_recovery_metadata(string $cprRoot, string $jobId, array $job): array
{
    $state = (string) ($job['state'] ?? '');
    $meta = null;
    $failureEventId = '';

    // Pause-state-specific recovery evidence only (do not invent from unrelated stages).
    if ($state === 'cpr_paused_verify_failed' || $state === 'cpr_failed_post_ponr') {
        $verify = orange_cpr_post_verify_live_load_latest($cprRoot, $jobId, 'report');
        if (!is_array($verify) || !orange_cpr_auth_verify_seal($verify)) {
            return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_RECOVERY, 'Sealed post-verify failure report required for verify-pause rollback.');
        }
        $failureEventId = (string) ($verify['verify_report_id'] ?? '');
        $meta = is_array($verify['recovery_metadata'] ?? null) ? $verify['recovery_metadata'] : null;
    } elseif ($state === 'cpr_paused_delete_failed') {
        $rep = orange_cpr_delete_live_load_latest($cprRoot, $jobId, 'report');
        if (is_array($rep) && orange_cpr_auth_verify_seal($rep) && is_array($rep['recovery_metadata'] ?? null)) {
            $meta = $rep['recovery_metadata'];
            $failureEventId = (string) ($rep['delete_report_id'] ?? $rep['report_id'] ?? '');
        }
    } elseif ($state === 'cpr_paused_import_failed') {
        $rep = orange_cpr_import_live_load_latest($cprRoot, $jobId, 'summary');
        if (is_array($rep) && orange_cpr_auth_verify_seal($rep) && is_array($rep['recovery_metadata'] ?? null)) {
            $meta = $rep['recovery_metadata'];
            $failureEventId = (string) ($rep['import_summary_id'] ?? '');
        }
    } elseif ($state === 'cpr_paused_uploads_failed') {
        $rep = orange_cpr_uploads_live_load_latest($cprRoot, $jobId, 'report');
        if (is_array($rep) && orange_cpr_auth_verify_seal($rep) && is_array($rep['recovery_metadata'] ?? null)) {
            $meta = $rep['recovery_metadata'];
            $failureEventId = (string) ($rep['uploads_report_id'] ?? $rep['report_id'] ?? '');
        }
    } elseif ($state === 'cpr_paused_rollback_failed') {
        $prior = orange_cpr_rollback_live_load_latest($cprRoot, $jobId, 'report');
        if (is_array($prior) && is_array($prior['recovery_metadata'] ?? null)) {
            $meta = $prior['recovery_metadata'];
            $failureEventId = (string) ($prior['rollback_id'] ?? '');
        } elseif (is_array($job['recovery_metadata'] ?? null)) {
            $meta = $job['recovery_metadata'];
        }
    } elseif (is_array($job['recovery_metadata'] ?? null)) {
        $meta = $job['recovery_metadata'];
    }

    if (!is_array($meta) || $meta === []) {
        return orange_cpr_rblive_fail(
            ORANGE_CPR_RBLIVE_ERR_RECOVERY,
            'Recovery metadata missing — fail-closed (OD-ROLLBACK / Recovery Contract).'
        );
    }
    if (!empty($meta['auto_rollback'])) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_AUTO, 'Recovery metadata marks auto_rollback — refused.');
    }
    // Verify-pause path always binds OD-PIN primary rollback.
    if ($state === 'cpr_paused_verify_failed'
        && (string) ($meta['primary_rollback'] ?? '') !== 'session_full_backup_od_pin'
    ) {
        return orange_cpr_rblive_fail(
            ORANGE_CPR_RBLIVE_ERR_BOUNDARY,
            'Recovery metadata primary_rollback must be session_full_backup_od_pin.'
        );
    }

    return orange_cpr_rblive_ok([
        'recovery_metadata' => $meta,
        'failure_event_id' => $failureEventId !== '' ? $failureEventId : ('fail_' . $jobId),
        'rollback_executed' => false,
    ]);
}

/**
 * Assert last sealed recovery checkpoint is readable and sealed (not corrupt).
 *
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function orange_cpr_rollback_live_assert_recovery_checkpoint(array $env, string $jobId, array $job): array
{
    $cpId = (string) ($job['last_checkpoint_id'] ?? '');
    if ($cpId === '') {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_CHECKPOINT, 'Last recovery checkpoint id missing.');
    }
    // Resume/rollback from sealed recovery checkpoints only — never invent success CPs.
    if (in_array($cpId, ['CP10', 'CP11', 'CP12'], true)) {
        return orange_cpr_rblive_fail(
            ORANGE_CPR_RBLIVE_ERR_CHECKPOINT,
            'Rollback must not resume from post-apply success checkpoints (' . $cpId . ').'
        );
    }
    $loaded = orange_cpr_checkpoint_load($env, $jobId, $cpId);
    if (empty($loaded['ok']) || !is_array($loaded['checkpoint'] ?? null)) {
        return orange_cpr_rblive_fail(
            ORANGE_CPR_RBLIVE_ERR_CHECKPOINT,
            'Corrupt or unreadable recovery checkpoint: ' . $cpId,
            ['checkpoint_id' => $cpId, 'upstream' => $loaded]
        );
    }

    return orange_cpr_rblive_ok([
        'recovery_checkpoint_id' => $cpId,
        'recovery_checkpoint' => $loaded['checkpoint'],
        'rollback_executed' => false,
    ]);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_rollback_live_assert_preconditions(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_rblive_fail(
            ORANGE_CPR_RBLIVE_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during P6 rollback.'
        );
    }
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_ACTOR, 'Super Admin required for OD-ROLLBACK.');
    }
    if (!empty($request['actor_is_country_admin'])) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_ACTOR, 'Country Admin cannot Rollback (OD-PERM; OD-ROLLBACK).');
    }

    $unsafe = orange_cpr_rollback_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $phrase = (string) ($request['confirmation_phrase'] ?? $request['phrase'] ?? '');
    if ($phrase !== 'RESTORE') {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_PHRASE, 'Confirmation phrase RESTORE required (OD-PHRASE).');
    }
    if (empty($request['reauth_ok']) && empty($request['password_reauth_ok'])) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_REAUTH, 'Password re-authentication required (OD-ROLLBACK).');
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_STATE, $e->getMessage());
    }
    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_CONTRACT, 'Frozen execution contract required.');
    }
    if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_IDENTITY, 'Contract/job identity drift.');
    }
    if (isset($request['package_fingerprint'])
        && (string) $request['package_fingerprint'] !== (string) ($job['package_fingerprint'] ?? '')
    ) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_FINGERPRINT, 'Request package_fingerprint mismatch.');
    }
    if (isset($request['country_id'])
        && (int) $request['country_id'] !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_COUNTRY, 'Cross-country rollback refused.');
    }
    if (isset($request['schema_revision'])
        && (int) $request['schema_revision'] !== (int) ($contract['schema_revision_expected'] ?? -1)
    ) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_SCHEMA, 'Request schema_revision mismatch.');
    }

    $elig = orange_cpr_rollback_eligibility($job, [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'auto_rollback' => !empty($request['auto_rollback']),
    ]);
    if (empty($elig['eligible'])) {
        return orange_cpr_rblive_fail(
            ORANGE_CPR_RBLIVE_ERR_ELIGIBLE,
            (string) ($elig['message'] ?? 'Rollback not eligible (OD-ROLLBACK).'),
            [
                'from_state' => (string) ($job['state'] ?? ''),
                'upstream' => $elig,
            ]
        );
    }

    // OD-PIN session Full Backup — sole recovery boundary.
    $pin = orange_cpr_od_pin_live_load_pin($cprRoot, $jobId);
    $backupId = (string) ($contract['session_full_backup_id'] ?? '');
    $backupFp = (string) ($contract['session_full_backup_fingerprint'] ?? '');
    $pinned = !empty($contract['session_full_backup_pinned'])
        || (is_array($pin) && !empty($pin['pinned']));
    if (!$pinned || $backupId === '' || $backupFp === '') {
        return orange_cpr_rblive_fail(
            ORANGE_CPR_RBLIVE_ERR_PIN,
            'Session Full Backup must be verified and pinned (OD-PIN) before Rollback.'
        );
    }
    if (is_array($pin)) {
        $pinId = (string) ($pin['session_full_backup_id'] ?? '');
        $pinFp = (string) ($pin['session_full_backup_fingerprint'] ?? '');
        if ($pinId !== '' && ($pinId !== $backupId || $pinFp !== $backupFp)) {
            return orange_cpr_rblive_fail(
                ORANGE_CPR_RBLIVE_ERR_BOUNDARY,
                'OD-PIN pin record does not match contract recovery boundary.'
            );
        }
    }
    if (isset($request['session_full_backup_id'])
        && (string) $request['session_full_backup_id'] !== $backupId
    ) {
        return orange_cpr_rblive_fail(
            ORANGE_CPR_RBLIVE_ERR_BOUNDARY,
            'Requested session_full_backup_id does not match OD-PIN recovery boundary.'
        );
    }
    if (isset($request['session_full_backup_fingerprint'])
        && (string) $request['session_full_backup_fingerprint'] !== $backupFp
    ) {
        return orange_cpr_rblive_fail(
            ORANGE_CPR_RBLIVE_ERR_BOUNDARY,
            'Requested session_full_backup_fingerprint does not match OD-PIN recovery boundary.'
        );
    }

    $recoveryRv = orange_cpr_rollback_live_resolve_recovery_metadata($cprRoot, $jobId, $job);
    if (empty($recoveryRv['ok'])) {
        return $recoveryRv;
    }

    $cpRv = orange_cpr_rollback_live_assert_recovery_checkpoint($env, $jobId, $job);
    if (empty($cpRv['ok'])) {
        return $cpRv;
    }

    $manifestsRv = orange_cpr_rollback_live_collect_apply_manifests($cprRoot, $jobId);
    if (empty($manifestsRv['ok'])) {
        return $manifestsRv;
    }

    $gates = orange_cpr_gates_live_load_latest($cprRoot, $jobId);
    if (!is_array($gates) || empty($gates['all_gates_pass']) || !orange_cpr_auth_verify_seal($gates)) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_GATE, 'Sealed gates_live PASS required.');
    }
    $auth = orange_cpr_auth_live_load_latest($cprRoot, $jobId);
    if (!is_array($auth) || empty($auth['ponr_authorized']) || !orange_cpr_auth_verify_seal($auth)) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_AUTHORITY, 'Sealed authority_live required.');
    }

    $lockRv = orange_cpr_uploads_live_assert_lock_ownership($env, $jobId, $request, $job, $contract);
    if (empty($lockRv['ok'])) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_LOCK, (string) ($lockRv['message'] ?? 'Lock ownership failed.'));
    }

    $maint = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if (!is_array($maint) || empty($maint['global_maintenance_on']) || !orange_cpr_auth_verify_seal($maint)) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_MAINT, 'GLOBAL maintenance must remain active during Rollback.');
    }
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP12')) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_RELEASE, 'CP12 present — maint already released; out of rollback scope.');
    }
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP11')) {
        return orange_cpr_rblive_fail(
            ORANGE_CPR_RBLIVE_ERR_SCOPE,
            'CP11 success finalize present — Rollback from success is out of scope (OD-ROLLBACK pause-only).'
        );
    }

    return orange_cpr_rblive_ok([
        'actor_admin_id' => (int) $request['actor_admin_id'],
        'job' => $job,
        'contract' => $contract,
        'cpr_root' => $cprRoot,
        'job_state' => (string) ($job['state'] ?? ''),
        'schema_revision' => (int) ($contract['schema_revision_expected'] ?? 0),
        'transition_id' => (string) ($elig['transition_id'] ?? ''),
        'session_full_backup_id' => $backupId,
        'session_full_backup_fingerprint' => $backupFp,
        'recovery_metadata' => $recoveryRv['recovery_metadata'],
        'failure_event_id' => (string) $recoveryRv['failure_event_id'],
        'recovery_checkpoint_id' => (string) $cpRv['recovery_checkpoint_id'],
        'apply_manifests' => $manifestsRv['apply_manifests'],
        'maint_state' => $maint,
        'rollback_executed' => false,
    ]);
}

/**
 * Execute OD-ROLLBACK to session Full Backup boundary (enablement-FALSE sealed path).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_rollback_live_run(array $env, string $jobId, array $request = []): array
{
    $cprRootEarly = orange_cpr_resolve_work_root($env);
    $existingEarly = orange_cpr_rollback_live_load_latest($cprRootEarly, $jobId, 'report');
    if (is_array($existingEarly) && !empty($existingEarly['rollback_completed'])) {
        try {
            $jobEarly = orange_cpr_job_read($cprRootEarly, $jobId);
        } catch (RuntimeException $e) {
            return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_STATE, $e->getMessage());
        }
        if ((string) ($jobEarly['state'] ?? '') === 'cpr_rollback_completed') {
            if (!empty($request['force_replay'])) {
                return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_REPLAY, 'Replay of completed rollback forbidden.');
            }
            $maint = orange_cpr_maint_live_load_state($cprRootEarly, $jobId);

            return orange_cpr_rblive_ok([
                'message' => 'Rollback already complete (idempotent).',
                'job_id' => $jobId,
                'idempotent' => true,
                'rollback_report' => $existingEarly,
                'rollback_manifest' => orange_cpr_rollback_live_load_latest($cprRootEarly, $jobId, 'manifest'),
                'recovery_evidence' => orange_cpr_rollback_live_load_latest($cprRootEarly, $jobId, 'evidence'),
                'job_state' => 'cpr_rollback_completed',
                'maintenance_remains_active' => !empty($maint['global_maintenance_on']),
                'maintenance_released' => false,
                'production_sql_executed' => false,
                'production_uploads_mutated' => false,
            ]);
        }
    }

    $pre = orange_cpr_rollback_live_assert_preconditions($env, $jobId, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }

    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    /** @var array<string, mixed> $recoveryMeta */
    $recoveryMeta = $pre['recovery_metadata'];
    /** @var array<string, mixed> $applyManifests */
    $applyManifests = $pre['apply_manifests'];
    $cprRoot = (string) $pre['cpr_root'];
    $actorAdminId = (int) $pre['actor_admin_id'];
    $state = (string) $pre['job_state'];
    $backupId = (string) $pre['session_full_backup_id'];
    $backupFp = (string) $pre['session_full_backup_fingerprint'];
    $failureEventId = (string) $pre['failure_event_id'];
    $recoveryCpId = (string) $pre['recovery_checkpoint_id'];
    $enterTid = (string) $pre['transition_id'];

    $now = gmdate('c');
    $rollbackId = orange_cpr_generate_job_id();
    $manifestId = orange_cpr_generate_job_id();
    $authId = orange_cpr_generate_job_id();
    $evidenceId = orange_cpr_generate_job_id();
    $auditRecordId = orange_cpr_generate_job_id();
    $challengeId = orange_cpr_generate_job_id();

    $authorization = [
        'schema_version' => ORANGE_CPR_ROLLBACK_AUTH_SCHEMA,
        'record_type' => 'cpr_rollback_authorization',
        'rollback_id' => $rollbackId,
        'job_id' => $jobId,
        'authorized_by_admin_id' => $actorAdminId,
        'authorized_at' => $now,
        'from_pause_state' => $state,
        'session_full_backup_id' => $backupId,
        'session_full_backup_fingerprint' => $backupFp,
        'password_reauth_ok' => true,
        'phrase_accepted' => true,
        'confirmation_phrase' => 'RESTORE',
        'auth_challenge_id' => $challengeId,
        'permission_check_ok' => true,
        'automatic' => false,
        'country_admin_actor' => false,
        'failure_event_id' => $failureEventId,
        'audit_record_id' => $auditRecordId,
        'execution_log_ref' => 'rollback/' . $rollbackId,
        'transition_id' => $enterTid,
        'recovery_checkpoint_id' => $recoveryCpId,
    ];

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.rollback_live_authorization',
        'job_id' => $jobId,
        'rollback_id' => $rollbackId,
        'audit_record_id' => $auditRecordId,
        'from_pause_state' => $state,
        'actor_admin_id' => $actorAdminId,
        'automatic' => false,
        'session_full_backup_id' => $backupId,
        'production_sql_executed' => false,
        'enablement_flag_observed' => false,
    ]);

    $trEnter = orange_cpr_transition_apply($env, $jobId, 'cpr_rolling_back', [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'trigger' => $state === 'cpr_paused_rollback_failed' ? 'retry_rollback' : 'rollback_action',
        'phrase_ok' => true,
        'reauth_ok' => true,
    ]);
    if (empty($trEnter['ok'])) {
        return orange_cpr_rblive_fail(
            ORANGE_CPR_RBLIVE_ERR_TRANSITION,
            (string) ($trEnter['message'] ?? 'Enter cpr_rolling_back failed.'),
            ['upstream' => $trEnter]
        );
    }
    $job = orange_cpr_job_read($cprRoot, $jobId);
    if ((string) ($job['state'] ?? '') !== 'cpr_rolling_back') {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_STATE, 'Expected cpr_rolling_back after authorization.');
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.rollback_live_start',
        'job_id' => $jobId,
        'rollback_id' => $rollbackId,
        'actor_admin_id' => $actorAdminId,
        'transition_id' => (string) ($trEnter['transition_id'] ?? $enterTid),
        'recovery_checkpoint_id' => $recoveryCpId,
        'session_full_backup_id' => $backupId,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'partial_rollback' => false,
        'enablement_flag_observed' => false,
    ]);

    // Full-anchor restore under enablement FALSE: sealed ledger evidence only (no production SQL).
    $restoreEvidence = [
        'schema_version' => ORANGE_CPR_ROLLBACK_EVIDENCE_SCHEMA,
        'record_type' => 'cpr_rollback_recovery_evidence',
        'evidence_id' => $evidenceId,
        'rollback_id' => $rollbackId,
        'job_id' => $jobId,
        'mode' => 'enablement_false_sealed_full_anchor_ledger',
        'session_full_backup_id' => $backupId,
        'session_full_backup_fingerprint' => $backupFp,
        'recovery_checkpoint_id' => $recoveryCpId,
        'apply_manifests' => $applyManifests,
        'restore_complete' => true,
        'partial_rollback' => false,
        'undefined_rollback' => false,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'production_mutation' => false,
        'maint_global_on' => true,
        'created_at' => $now,
    ];

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
        'session_full_backup_id' => $backupId,
        'session_full_backup_fingerprint' => $backupFp,
        'production_db_identity_hash' => (string) ($contract['production_db_identity_hash'] ?? ''),
        'c4_report_hash' => (string) ($contract['c4_report_hash'] ?? ''),
        'c8_report_hash' => (string) ($contract['c8_report_hash'] ?? ''),
    ];

    $sealedManifest = array_merge($binding, [
        'schema_version' => ORANGE_CPR_ROLLBACK_MANIFEST_SCHEMA,
        'engine_version' => ORANGE_CPR_ROLLBACK_LIVE_VERSION,
        'record_type' => 'cpr_rollback_manifest',
        'manifest_id' => $manifestId,
        'rollback_id' => $rollbackId,
        'evidence_id' => $evidenceId,
        'authorization_id' => $authId,
        'from_pause_state' => $state,
        'enter_transition_id' => (string) ($trEnter['transition_id'] ?? $enterTid),
        'complete_transition_id' => 'T54',
        'recovery_checkpoint_id' => $recoveryCpId,
        'apply_manifests' => $applyManifests,
        'restore_complete' => true,
        'partial_rollback' => false,
        'undefined_rollback' => false,
        'automatic' => false,
        'maintenance_released' => false,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'created_at' => $now,
    ]);
    $sealedManifest['manifest_fingerprint'] = hash(
        'sha256',
        (string) json_encode([
            'job_id' => $jobId,
            'rollback_id' => $rollbackId,
            'session_full_backup_id' => $backupId,
            'session_full_backup_fingerprint' => $backupFp,
            'recovery_checkpoint_id' => $recoveryCpId,
            'restore_complete' => true,
            'partial_rollback' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $report = array_merge($binding, [
        'schema_version' => ORANGE_CPR_ROLLBACK_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_ROLLBACK_LIVE_VERSION,
        'record_type' => 'cpr_rollback_report',
        'rollback_id' => $rollbackId,
        'manifest_id' => $manifestId,
        'evidence_id' => $evidenceId,
        'authorization_id' => $authId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'rollback_completed' => true,
        'overall_result' => 'ROLLBACK_OK',
        'from_pause_state' => $state,
        'od_rollback' => true,
        'automatic' => false,
        'country_admin_actor' => false,
        'phrase_accepted' => true,
        'password_reauth_ok' => true,
        'recovery_checkpoint_id' => $recoveryCpId,
        'manifest_fingerprint' => $sealedManifest['manifest_fingerprint'],
        'apply_manifests' => $applyManifests,
        'restore_complete' => true,
        'partial_rollback' => false,
        'undefined_rollback' => false,
        'maint_global_on' => true,
        'maintenance_released' => false,
        'next_actions_allowed' => ['maintenance_release'],
        'forbidden_actions' => ['auto_rollback', 'partial_rollback', 'country_admin_rollback', 'replay'],
        'ops_enablement_flag' => false,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
        'sealed' => true,
        'actor_admin_id' => $actorAdminId,
        'failure_event_id' => $failureEventId,
        'evaluated_at' => $now,
        'created_at' => $now,
        'recovery_metadata' => array_merge($recoveryMeta, [
            'od_rollback' => 'complete',
            'auto_rollback' => false,
            'rollback_executed' => true,
            'rollback_id' => $rollbackId,
            'maint_remains_on' => true,
            'maintenance_released' => false,
            'primary_rollback' => 'session_full_backup_od_pin',
            'session_full_backup_id' => $backupId,
            'session_full_backup_fingerprint' => $backupFp,
            'recovery_checkpoint_id' => $recoveryCpId,
            'restore_complete' => true,
            'partial_rollback' => false,
            'undefined_rollback' => false,
            'completed_phase' => 'rollback',
            'execution_status' => 'rollback_completed',
            'cp12_pending' => true,
            'production_sql_executed' => false,
            'production_uploads_mutated' => false,
        ]),
    ]);

    try {
        orange_cpr_rollback_live_persist(
            $cprRoot,
            $jobId,
            'cpr_rollback_authorization_' . $authId,
            array_merge($authorization, ['authorization_id' => $authId])
        );
        $ePath = orange_cpr_rollback_live_persist(
            $cprRoot,
            $jobId,
            'cpr_rollback_evidence_' . $evidenceId,
            $restoreEvidence
        );
        $mPath = orange_cpr_rollback_live_persist(
            $cprRoot,
            $jobId,
            'cpr_rollback_manifest_' . $manifestId,
            $sealedManifest
        );
        $rPath = orange_cpr_rollback_live_persist(
            $cprRoot,
            $jobId,
            'cpr_rollback_report_' . $rollbackId,
            $report
        );
        foreach ([
            'authorization' => array_merge($authorization, ['authorization_id' => $authId]),
            'evidence' => array_merge($restoreEvidence, ['evidence_path' => $ePath]),
            'manifest' => array_merge($sealedManifest, ['manifest_path' => $mPath]),
            'report' => array_merge($report, ['rollback_report_path' => $rPath]),
        ] as $kind => $rec) {
            $latest = orange_cpr_rollback_live_latest_path($cprRoot, $jobId, $kind);
            $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
            $sealed = orange_cpr_auth_seal($rec);
            $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false || @file_put_contents($tmp, $json) === false) {
                throw new RuntimeException('Rollback latest write failed: ' . $kind);
            }
            orange_cpr_atomic_rename_replace($tmp, $latest);
        }
    } catch (RuntimeException $e) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_PERSIST, $e->getMessage());
    }

    // Re-assert sealed outputs before completing transition (never partial).
    $reportCheck = orange_cpr_rollback_live_load_latest($cprRoot, $jobId, 'report');
    $manifestCheck = orange_cpr_rollback_live_load_latest($cprRoot, $jobId, 'manifest');
    $evidenceCheck = orange_cpr_rollback_live_load_latest($cprRoot, $jobId, 'evidence');
    if (!is_array($reportCheck) || !is_array($manifestCheck) || !is_array($evidenceCheck)
        || empty($reportCheck['rollback_completed'])
        || empty($manifestCheck['restore_complete'])
        || empty($evidenceCheck['restore_complete'])
        || !empty($manifestCheck['partial_rollback'])
        || !empty($evidenceCheck['partial_rollback'])
    ) {
        return orange_cpr_rblive_fail(
            ORANGE_CPR_RBLIVE_ERR_PARTIAL,
            'Rollback evidence incomplete — refusing partial/undefined completion.'
        );
    }

    $trOk = orange_cpr_transition_apply($env, $jobId, 'cpr_rollback_completed', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'trigger' => 'rollback_ok',
    ]);
    if (empty($trOk['ok'])) {
        return orange_cpr_rblive_fail(
            ORANGE_CPR_RBLIVE_ERR_TRANSITION,
            (string) ($trOk['message'] ?? 'T54 rollback_ok failed.'),
            ['upstream' => $trOk]
        );
    }

    $jobAfter = orange_cpr_job_read($cprRoot, $jobId);
    $jobAfter['rollback_completed'] = true;
    $jobAfter['rollback_id'] = $rollbackId;
    $jobAfter['updated_at'] = $now;
    orange_cpr_job_write($cprRoot, $jobId, $jobAfter);

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.rollback_live_complete',
        'job_id' => $jobId,
        'rollback_id' => $rollbackId,
        'manifest_id' => $manifestId,
        'evidence_id' => $evidenceId,
        'transition_id' => 'T54',
        'job_state' => 'cpr_rollback_completed',
        'session_full_backup_id' => $backupId,
        'recovery_checkpoint_id' => $recoveryCpId,
        'restore_complete' => true,
        'partial_rollback' => false,
        'automatic' => false,
        'maintenance_released' => false,
        'actor_admin_id' => $actorAdminId,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
    ]);

    $jobFinal = orange_cpr_job_read($cprRoot, $jobId);
    $maintFinal = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if ((string) ($jobFinal['state'] ?? '') !== 'cpr_rollback_completed') {
        return orange_cpr_rblive_fail(
            ORANGE_CPR_RBLIVE_ERR_STATE,
            'Expected cpr_rollback_completed after T54.',
            ['job_state' => (string) ($jobFinal['state'] ?? '')]
        );
    }
    if (!is_array($maintFinal) || empty($maintFinal['global_maintenance_on'])) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_MAINT, 'Maintenance must remain active after rollback.');
    }
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP12')) {
        return orange_cpr_rblive_fail(ORANGE_CPR_RBLIVE_ERR_RELEASE, 'CP12 must not be written by rollback.');
    }

    return orange_cpr_rblive_ok([
        'message' => 'OD-ROLLBACK complete to session Full Backup boundary; maintenance remains active.',
        'job_id' => $jobId,
        'rollback_id' => $rollbackId,
        'manifest_id' => $manifestId,
        'evidence_id' => $evidenceId,
        'rollback_report' => orange_cpr_rollback_live_load_latest($cprRoot, $jobId, 'report'),
        'rollback_manifest' => orange_cpr_rollback_live_load_latest($cprRoot, $jobId, 'manifest'),
        'recovery_evidence' => orange_cpr_rollback_live_load_latest($cprRoot, $jobId, 'evidence'),
        'authorization' => orange_cpr_rollback_live_load_latest($cprRoot, $jobId, 'authorization'),
        'overall_result' => 'ROLLBACK_OK',
        'job_state' => 'cpr_rollback_completed',
        'from_pause_state' => $state,
        'enter_transition_id' => (string) ($trEnter['transition_id'] ?? $enterTid),
        'complete_transition_id' => 'T54',
        'recovery_checkpoint_id' => $recoveryCpId,
        'session_full_backup_id' => $backupId,
        'session_full_backup_fingerprint' => $backupFp,
        'maintenance_remains_active' => true,
        'maintenance_released' => false,
        'partial_rollback' => false,
        'undefined_rollback' => false,
        'auto_rollback' => false,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
        'recovery_metadata' => $report['recovery_metadata'],
    ]);
}
