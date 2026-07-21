<?php

declare(strict_types=1);

/**
 * CPR Live Success Finalize Engine (WP-P6-03) — Architecture §18 CP11.
 *
 * Runs only after Post-Verify PASS (CP10). Seals success reports and writes CP11
 * without releasing GLOBAL maintenance (CP12 / WP-P6-05) and without rollback.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P6_03_SUCCESS_FINALIZE.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_11_VERIFY_REPORTS.md §7.8
 */

require_once __DIR__ . '/cpr_post_verify_live.php';
require_once __DIR__ . '/cpr_maintenance_live.php';

const ORANGE_CPR_SUCCESS_FINALIZE_SCHEMA = 'cpr_success_finalize_live/1';
const ORANGE_CPR_SUCCESS_FINALIZE_VERSION = 'P6-03-1.0';
const ORANGE_CPR_SUCCESS_FINALIZE_MANIFEST_SCHEMA = 'cpr_success_finalize_manifest/1';

const ORANGE_CPR_SFLIVE_ERR_ENABLEMENT = 'sflive_enablement_forbidden';
const ORANGE_CPR_SFLIVE_ERR_ACTOR = 'sflive_actor_not_super_admin';
const ORANGE_CPR_SFLIVE_ERR_STATE = 'sflive_state_invalid';
const ORANGE_CPR_SFLIVE_ERR_CONTRACT = 'sflive_contract_mismatch';
const ORANGE_CPR_SFLIVE_ERR_IDENTITY = 'sflive_identity_drift';
const ORANGE_CPR_SFLIVE_ERR_LOCK = 'sflive_lock_loss';
const ORANGE_CPR_SFLIVE_ERR_GATE = 'sflive_gate_failure';
const ORANGE_CPR_SFLIVE_ERR_AUTHORITY = 'sflive_authority_failure';
const ORANGE_CPR_SFLIVE_ERR_CP10 = 'sflive_cp10_required';
const ORANGE_CPR_SFLIVE_ERR_VERIFY = 'sflive_verify_required';
const ORANGE_CPR_SFLIVE_ERR_COUNTRY = 'sflive_country_mismatch';
const ORANGE_CPR_SFLIVE_ERR_SCHEMA = 'sflive_schema_mismatch';
const ORANGE_CPR_SFLIVE_ERR_FINGERPRINT = 'sflive_fingerprint_mismatch';
const ORANGE_CPR_SFLIVE_ERR_REPLAY = 'sflive_replay_forbidden';
const ORANGE_CPR_SFLIVE_ERR_BYPASS = 'sflive_bypass_forbidden';
const ORANGE_CPR_SFLIVE_ERR_TRANSITION = 'sflive_transition_failed';
const ORANGE_CPR_SFLIVE_ERR_CHECKPOINT = 'sflive_checkpoint_failed';
const ORANGE_CPR_SFLIVE_ERR_PERSIST = 'sflive_persist_failed';
const ORANGE_CPR_SFLIVE_ERR_MAINT = 'sflive_maint_not_active';
const ORANGE_CPR_SFLIVE_ERR_ROLLBACK = 'sflive_rollback_forbidden';
const ORANGE_CPR_SFLIVE_ERR_RELEASE = 'sflive_maint_release_forbidden';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_sflive_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'integrity_waiver' => false,
        'success_with_warnings' => false,
        'maintenance_released' => false,
        'rollback_executed' => false,
        'enablement_flag_observed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_sflive_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'fail_closed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'integrity_waiver' => false,
        'success_with_warnings' => false,
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
function orange_cpr_success_finalize_live_persist(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_success_finalize_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR success_finalize directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Success finalize record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Success finalize record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_success_finalize_live_latest_path(string $cprRoot, string $jobId, string $kind): string
{
    return orange_cpr_success_finalize_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_success_finalize_' . $kind . '_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_success_finalize_live_load_latest(string $cprRoot, string $jobId, string $kind): ?array
{
    $path = orange_cpr_success_finalize_live_latest_path($cprRoot, $jobId, $kind);
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
function orange_cpr_success_finalize_live_refuse_unsafe(array $request): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_cp10', 'skip_verify', 'skip_lock',
        'skip_gate', 'skip_authority', 'integrity_waiver', 'success_with_warnings',
        'release_maint', 'release_maintenance', 'maint_release', 'writers_restored',
        'rollback', 'rollback_action', 'auto_rollback', 'execute_production_sql',
        'mutate_uploads', 'begin_p6_04', 'begin_cp12',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            if (in_array($k, ['release_maint', 'release_maintenance', 'maint_release', 'writers_restored', 'begin_cp12'], true)) {
                return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_RELEASE, 'Maintenance release forbidden in WP-P6-03: ' . $k);
            }
            if (in_array($k, ['rollback', 'rollback_action', 'auto_rollback'], true)) {
                return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_ROLLBACK, 'Rollback forbidden in WP-P6-03: ' . $k);
            }

            return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_BYPASS, 'Unsafe success-finalize knob forbidden: ' . $k);
        }
    }

    return orange_cpr_sflive_ok(['message' => 'unsafe knobs absent']);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_success_finalize_live_assert_preconditions(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_sflive_fail(
            ORANGE_CPR_SFLIVE_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during P6 success finalize.'
        );
    }
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_ACTOR, 'Super Admin required for success finalize.');
    }
    $unsafe = orange_cpr_success_finalize_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_STATE, $e->getMessage());
    }
    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_CONTRACT, 'Frozen execution contract required.');
    }
    if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_IDENTITY, 'Contract/job identity drift.');
    }
    if (isset($request['package_fingerprint'])
        && (string) $request['package_fingerprint'] !== (string) ($job['package_fingerprint'] ?? '')
    ) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_FINGERPRINT, 'Request package_fingerprint mismatch.');
    }
    if (isset($request['country_id'])
        && (int) $request['country_id'] !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_COUNTRY, 'Country mismatch refused.');
    }
    if (isset($request['schema_revision'])
        && (int) $request['schema_revision'] !== (int) ($contract['schema_revision_expected'] ?? -1)
    ) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_SCHEMA, 'Request schema_revision mismatch.');
    }

    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP10')) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_CP10, 'CP10 required before success finalize.');
    }
    $cp10 = orange_cpr_checkpoint_load($env, $jobId, 'CP10');
    if (empty($cp10['ok'])) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_CP10, 'CP10 unreadable.');
    }
    $cp10Payload = is_array($cp10['checkpoint']['payload'] ?? null) ? $cp10['checkpoint']['payload'] : [];
    if ((string) ($cp10Payload['verify_suite_result'] ?? '') !== 'PASS'
        || ($cp10Payload['integrity_waiver'] ?? true) !== false
    ) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_VERIFY, 'CP10 must be PASS with integrity_waiver=false.');
    }

    $verify = orange_cpr_post_verify_live_load_latest($cprRoot, $jobId, 'report');
    if (!is_array($verify)
        || (string) ($verify['overall_result'] ?? '') !== 'PASS'
        || !orange_cpr_auth_verify_seal($verify)
    ) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_VERIFY, 'Sealed Post-Verify PASS report required.');
    }

    $gates = orange_cpr_gates_live_load_latest($cprRoot, $jobId);
    if (!is_array($gates) || empty($gates['all_gates_pass']) || !orange_cpr_auth_verify_seal($gates)) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_GATE, 'Sealed gates_live PASS required.');
    }
    $auth = orange_cpr_auth_live_load_latest($cprRoot, $jobId);
    if (!is_array($auth) || empty($auth['ponr_authorized']) || !orange_cpr_auth_verify_seal($auth)) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_AUTHORITY, 'Sealed authority_live required.');
    }

    $lockRv = orange_cpr_uploads_live_assert_lock_ownership($env, $jobId, $request, $job, $contract);
    if (empty($lockRv['ok'])) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_LOCK, (string) ($lockRv['message'] ?? 'Lock ownership failed.'));
    }

    $maint = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if (!is_array($maint) || empty($maint['global_maintenance_on']) || !orange_cpr_auth_verify_seal($maint)) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_MAINT, 'GLOBAL maintenance must remain active (not released).');
    }
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP12')) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_RELEASE, 'CP12 present — maint release must not precede success finalize.');
    }

    $state = (string) ($job['state'] ?? '');
    if (!in_array($state, ['cpr_post_verifying', 'cpr_succeeded'], true)) {
        return orange_cpr_sflive_fail(
            ORANGE_CPR_SFLIVE_ERR_STATE,
            'Success finalize requires cpr_post_verifying or cpr_succeeded.',
            ['job_state' => $state]
        );
    }

    return orange_cpr_sflive_ok([
        'actor_admin_id' => (int) $request['actor_admin_id'],
        'job' => $job,
        'contract' => $contract,
        'cpr_root' => $cprRoot,
        'job_state' => $state,
        'schema_revision' => (int) ($contract['schema_revision_expected'] ?? 0),
        'verify_report' => $verify,
        'maint_state' => $maint,
        'cp10_payload' => $cp10Payload,
    ]);
}

/**
 * Execute Success Finalize through CP11 (no maint release, no rollback).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_success_finalize_live_run(array $env, string $jobId, array $request = []): array
{
    $pre = orange_cpr_success_finalize_live_assert_preconditions($env, $jobId, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }

    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    /** @var array<string, mixed> $verify */
    $verify = $pre['verify_report'];
    $cprRoot = (string) $pre['cpr_root'];
    $actorAdminId = (int) $pre['actor_admin_id'];
    $state = (string) $pre['job_state'];

    $existing = orange_cpr_success_finalize_live_load_latest($cprRoot, $jobId, 'report');
    if (is_array($existing)
        && !empty($existing['success_finalized'])
        && orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP11')
    ) {
        if (!empty($request['force_replay'])) {
            return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_REPLAY, 'Replay of completed success finalize forbidden.');
        }

        $maint = orange_cpr_maint_live_load_state($cprRoot, $jobId);

        return orange_cpr_sflive_ok([
            'message' => 'Success finalize already complete (idempotent).',
            'job_id' => $jobId,
            'idempotent' => true,
            'success_report' => $existing,
            'completion_manifest' => orange_cpr_success_finalize_live_load_latest($cprRoot, $jobId, 'manifest'),
            'checkpoint_id' => 'CP11',
            'job_state' => (string) (orange_cpr_job_read($cprRoot, $jobId)['state'] ?? ''),
            'maintenance_remains_active' => !empty($maint['global_maintenance_on']),
            'maintenance_released' => false,
            'rollback_executed' => false,
        ]);
    }
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP11') && !is_array($existing)) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_REPLAY, 'CP11 exists without sealed success finalize report.');
    }

    if ($state === 'cpr_post_verifying') {
        $tr = orange_cpr_transition_apply($env, $jobId, 'cpr_succeeded', [
            'actor' => ORANGE_CPR_ACTOR_SYSTEM,
            'trigger' => 'verify_pass',
        ]);
        if (empty($tr['ok'])) {
            return orange_cpr_sflive_fail(
                ORANGE_CPR_SFLIVE_ERR_TRANSITION,
                (string) ($tr['message'] ?? 'T13 verify_pass failed.'),
                ['upstream' => $tr]
            );
        }
        $job = orange_cpr_job_read($cprRoot, $jobId);
        $state = (string) ($job['state'] ?? '');
    }
    if ($state !== 'cpr_succeeded') {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_STATE, 'Expected cpr_succeeded.', ['job_state' => $state]);
    }

    // Re-assert maint ON after transition (must not release).
    $maint = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if (!is_array($maint) || empty($maint['global_maintenance_on'])) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_MAINT, 'Maintenance must remain active after success finalize transition.');
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.success_finalize_live_start',
        'job_id' => $jobId,
        'actor_admin_id' => $actorAdminId,
        'cp10_ref' => 'CP10',
        'production_sql_executed' => false,
        'maintenance_released' => false,
        'rollback_executed' => false,
        'enablement_flag_observed' => false,
    ]);

    $now = gmdate('c');
    $reportId = orange_cpr_generate_job_id();
    $manifestId = orange_cpr_generate_job_id();
    $verifyReportId = (string) ($verify['verify_report_id'] ?? '');
    $verifySha = (string) ($verify['content_sha256'] ?? '');
    if ($verifySha === '') {
        $copy = $verify;
        unset($copy['content_sha256'], $copy['verify_report_path']);
        $verifySha = hash('sha256', orange_cpr_auth_canonical_json($copy));
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

    $reportIds = [
        'verify_report_id' => $verifyReportId,
        'success_report_id' => $reportId,
        'completion_manifest_id' => $manifestId,
    ];

    $sealedManifest = array_merge($binding, [
        'schema_version' => ORANGE_CPR_SUCCESS_FINALIZE_MANIFEST_SCHEMA,
        'engine_version' => ORANGE_CPR_SUCCESS_FINALIZE_VERSION,
        'record_type' => 'cpr_success_finalize_manifest',
        'manifest_id' => $manifestId,
        'reports_sealed' => true,
        'report_ids' => $reportIds,
        'cp10_ref' => 'CP10',
        'cp11_ref' => 'CP11',
        'verify_report_sha256' => $verifySha,
        'integrity_waiver' => false,
        'success_with_warnings' => false,
        'maintenance_released' => false,
        'rollback_executed' => false,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'created_at' => $now,
    ]);
    $sealedManifest['manifest_fingerprint'] = hash(
        'sha256',
        (string) json_encode([
            'job_id' => $jobId,
            'package_fingerprint' => $binding['package_fingerprint'],
            'verify_report_id' => $verifyReportId,
            'verify_report_sha256' => $verifySha,
            'reports_sealed' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $report = array_merge($binding, [
        'schema_version' => ORANGE_CPR_SUCCESS_FINALIZE_SCHEMA,
        'engine_version' => ORANGE_CPR_SUCCESS_FINALIZE_VERSION,
        'record_type' => 'cpr_success_report',
        'success_report_id' => $reportId,
        'manifest_id' => $manifestId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'success_finalized' => true,
        'overall_result' => 'PASS',
        'verify_report_id' => $verifyReportId,
        'verify_report_sha256' => $verifySha,
        'integrity_waiver' => false,
        'success_with_warnings' => false,
        'reports_sealed' => true,
        'report_ids' => $reportIds,
        'cp10_ref' => 'CP10',
        'cp11_ref' => 'CP11',
        'manifest_fingerprint' => $sealedManifest['manifest_fingerprint'],
        'maint_global_on' => true,
        'maintenance_released' => false,
        'rollback_executed' => false,
        'next_actions_allowed' => ['maintenance_release'],
        'forbidden_actions' => ['rollback_from_success', 'auto_rollback', 'waive_integrity'],
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
            'od_success_finalize' => 'complete',
            'auto_rollback' => false,
            'rollback_executed' => false,
            'maint_remains_on' => true,
            'maintenance_released' => false,
            'integrity_guaranteed' => true,
            'integrity_waiver' => false,
            'completed_phase' => 'success_finalize',
            'execution_status' => 'success_finalized',
            'primary_rollback' => 'session_full_backup_od_pin',
            'cp12_pending' => true,
            'production_sql_executed' => false,
            'production_uploads_mutated' => false,
        ],
    ]);

    try {
        $mPath = orange_cpr_success_finalize_live_persist(
            $cprRoot,
            $jobId,
            'cpr_success_finalize_manifest_' . $manifestId,
            $sealedManifest
        );
        $rPath = orange_cpr_success_finalize_live_persist(
            $cprRoot,
            $jobId,
            'cpr_success_report_' . $reportId,
            $report
        );
        foreach ([
            'manifest' => array_merge($sealedManifest, ['manifest_path' => $mPath]),
            'report' => array_merge($report, ['success_report_path' => $rPath]),
        ] as $kind => $rec) {
            $latest = orange_cpr_success_finalize_live_latest_path($cprRoot, $jobId, $kind);
            $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
            $sealed = orange_cpr_auth_seal($rec);
            $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false || @file_put_contents($tmp, $json) === false) {
                throw new RuntimeException('Success finalize latest write failed: ' . $kind);
            }
            orange_cpr_atomic_rename_replace($tmp, $latest);
        }
    } catch (RuntimeException $e) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_PERSIST, $e->getMessage());
    }

    $cp11 = orange_cpr_checkpoint_create($env, $jobId, 'CP11', [
        'reports_sealed' => true,
        'report_ids' => $reportIds,
    ], ['written_by' => 'system']);
    if (empty($cp11['ok'])) {
        return orange_cpr_sflive_fail(
            ORANGE_CPR_SFLIVE_ERR_CHECKPOINT,
            (string) ($cp11['message'] ?? 'CP11 write failed.'),
            ['upstream' => $cp11]
        );
    }

    $jobAfter = orange_cpr_job_read($cprRoot, $jobId);
    $jobAfter['success_finalized'] = true;
    $jobAfter['success_report_id'] = $reportId;
    $jobAfter['updated_at'] = $now;
    orange_cpr_job_write($cprRoot, $jobId, $jobAfter);

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.success_finalize_live_complete',
        'job_id' => $jobId,
        'success_report_id' => $reportId,
        'checkpoint_id' => 'CP11',
        'reports_sealed' => true,
        'report_ids' => $reportIds,
        'maintenance_released' => false,
        'rollback_executed' => false,
        'actor_admin_id' => $actorAdminId,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
    ]);

    $jobFinal = orange_cpr_job_read($cprRoot, $jobId);
    $maintFinal = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if ((string) ($jobFinal['state'] ?? '') !== 'cpr_succeeded') {
        return orange_cpr_sflive_fail(
            ORANGE_CPR_SFLIVE_ERR_STATE,
            'Expected job to remain cpr_succeeded after CP11 (maint release is WP-P6-05).',
            ['job_state' => (string) ($jobFinal['state'] ?? '')]
        );
    }
    if (!is_array($maintFinal) || empty($maintFinal['global_maintenance_on'])) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_MAINT, 'Maintenance must remain active after CP11.');
    }
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP12')) {
        return orange_cpr_sflive_fail(ORANGE_CPR_SFLIVE_ERR_RELEASE, 'CP12 must not be written by success finalize.');
    }

    return orange_cpr_sflive_ok([
        'message' => 'Success finalize complete through CP11; maintenance remains active; rollback not executed.',
        'job_id' => $jobId,
        'success_report_id' => $reportId,
        'manifest_id' => $manifestId,
        'success_report' => orange_cpr_success_finalize_live_load_latest($cprRoot, $jobId, 'report'),
        'completion_manifest' => orange_cpr_success_finalize_live_load_latest($cprRoot, $jobId, 'manifest'),
        'overall_result' => 'PASS',
        'checkpoint_id' => 'CP11',
        'job_state' => 'cpr_succeeded',
        'reports_sealed' => true,
        'maintenance_remains_active' => true,
        'maintenance_released' => false,
        'rollback_executed' => false,
        'integrity_waiver' => false,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
        'recovery_metadata' => $report['recovery_metadata'],
    ]);
}
