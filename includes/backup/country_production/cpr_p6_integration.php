<?php

declare(strict_types=1);

/**
 * CPR P6 Integration Baseline — post-execution verify/rollback/closeout chain (WP-P6-06).
 *
 * Orchestrates existing P5 CP9 + P6 live modules only (no new business logic).
 * Success path: CP9 → Post-Verify → CP10 → Success Finalize → CP11 → Maint Release → CP12
 * Rollback path: CP9 → Post-Verify FAIL → OD-ROLLBACK → rollback_completed → Maint Release → CP12
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P6_06_INTEGRATION_BASELINE.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P6_ARTIFACT_INDEX.md §8
 */

require_once __DIR__ . '/cpr_p5_integration.php';
require_once __DIR__ . '/cpr_maint_release_live.php';
require_once __DIR__ . '/cpr_p6_control_plane.php';

const ORANGE_CPR_P6_INTEGRATION_SCHEMA = 'cpr_p6_integration/1';
const ORANGE_CPR_P6_INTEGRATION_VERSION = 'P6-06-1.0';

const ORANGE_CPR_P6INT_ERR_ENABLEMENT = 'p6int_enablement_forbidden';
const ORANGE_CPR_P6INT_ERR_ACTOR = 'p6int_actor_not_super_admin';
const ORANGE_CPR_P6INT_ERR_CHAIN = 'p6int_chain_failed';
const ORANGE_CPR_P6INT_ERR_VERIFY = 'p6int_verification_failed';
const ORANGE_CPR_P6INT_ERR_PERSIST = 'p6int_persist_failed';
const ORANGE_CPR_P6INT_ERR_BYPASS = 'p6int_bypass_forbidden';
const ORANGE_CPR_P6INT_ERR_P7 = 'p6int_p7_forbidden';
const ORANGE_CPR_P6INT_ERR_PATH = 'p6int_path_invalid';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_p6int_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
        'p6_baseline_ready' => false,
        'p7_started' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_p6int_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'fail_closed' => true,
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
        'p6_baseline_ready' => false,
        'p7_started' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
    ], $extra);
}

/**
 * Canonical P6 success-path stage order (Architecture §18 / Artifact Index §8).
 *
 * @return list<string>
 */
function orange_cpr_p6_integration_stage_order_success(): array
{
    return [
        'p5_through_cp9',
        'post_verify',
        'cp10',
        'success_finalize',
        'cp11',
        'maintenance_release',
        'cp12',
    ];
}

/**
 * Canonical P6 rollback-path stage order.
 *
 * @return list<string>
 */
function orange_cpr_p6_integration_stage_order_rollback(): array
{
    return [
        'p5_through_cp9',
        'post_verify_fail_pause',
        'od_rollback',
        'rollback_completed',
        'maintenance_release',
        'cp12',
    ];
}

/**
 * @return list<string>
 */
function orange_cpr_p6_integration_stage_order(string $path = 'success'): array
{
    return $path === 'rollback'
        ? orange_cpr_p6_integration_stage_order_rollback()
        : orange_cpr_p6_integration_stage_order_success();
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_p6_integration_persist(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_integration_live_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR integration_live directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('P6 integration record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('P6 integration record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_p6_integration_latest_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_integration_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_p6_integration_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_p6_integration_load_latest(string $cprRoot, string $jobId): ?array
{
    $path = orange_cpr_p6_integration_latest_path($cprRoot, $jobId);
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
function orange_cpr_p6_integration_refuse_unsafe(array $request): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_verify', 'skip_cp10', 'skip_cp11',
        'skip_cp12', 'skip_rollback', 'skip_maint_release', 'begin_p7', 'begin_enterprise_audit',
        'create_git_tag', 'auto_release', 'auto_rollback', 'execute_production_sql', 'mutate_uploads',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            if (in_array($k, ['begin_p7', 'begin_enterprise_audit', 'create_git_tag'], true)) {
                return orange_cpr_p6int_fail(ORANGE_CPR_P6INT_ERR_P7, 'P7 / Enterprise Audit / Git Tag forbidden in WP-P6-06: ' . $k);
            }

            return orange_cpr_p6int_fail(ORANGE_CPR_P6INT_ERR_BYPASS, 'Unsafe P6 integration knob forbidden: ' . $k);
        }
    }

    return orange_cpr_p6int_ok(['message' => 'unsafe knobs absent']);
}

/**
 * Fail-closed post-chain verification for a completed P6 path.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_p6_integration_verify(array $env, string $jobId, array $ctx): array
{
    $cprRoot = orange_cpr_resolve_work_root($env);
    $path = (string) ($ctx['path'] ?? 'success');
    if (!in_array($path, ['success', 'rollback'], true)) {
        return orange_cpr_p6int_fail(ORANGE_CPR_P6INT_ERR_PATH, 'path must be success or rollback.');
    }

    $checks = [];
    $fail = static function (string $id, string $msg) use (&$checks): void {
        $checks[] = ['id' => $id, 'ok' => false, 'message' => $msg];
    };
    $pass = static function (string $id, string $msg = 'ok') use (&$checks): void {
        $checks[] = ['id' => $id, 'ok' => true, 'message' => $msg];
    };

    if (orange_cpr_enablement_flag_read($env)) {
        $fail('enablement_false', 'Ops enablement must be FALSE.');
    } else {
        $pass('enablement_false');
    }

    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_p6int_fail(ORANGE_CPR_P6INT_ERR_VERIFY, $e->getMessage());
    }

    if ((string) ($job['state'] ?? '') !== 'cpr_maintenance_released') {
        $fail('state_maintenance_released', 'Expected cpr_maintenance_released; got ' . (string) ($job['state'] ?? ''));
    } else {
        $pass('state_maintenance_released');
    }
    if (empty($job['ponr_crossed'])) {
        $fail('ponr_crossed', 'ponr_crossed must remain true.');
    } else {
        $pass('ponr_crossed');
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        $fail('contract_frozen', 'Frozen contract required.');
    } else {
        $pass('contract_frozen');
        if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
            || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
            || (string) ($job['job_id'] ?? $jobId) !== $jobId
        ) {
            $fail('job_identity_continuity', 'Contract/job identity drift.');
        } else {
            $pass('job_identity_continuity');
        }
        if ((string) ($contract['session_full_backup_id'] ?? '') === ''
            || empty($contract['session_full_backup_pinned'])
        ) {
            $fail('recovery_integrity', 'OD-PIN session Full Backup missing/unpinned.');
        } else {
            $pass('recovery_integrity');
        }
    }

    $requiredCp = [
        'CP0', 'CP2', 'CP3', 'CP4', 'CP1', 'runbook_pre_ponr', 'CP5', 'CP-A',
        'CP6', 'CP7', 'CP8', 'CP9', 'CP12',
    ];
    if ($path === 'success') {
        $requiredCp[] = 'CP10';
        $requiredCp[] = 'CP11';
    }
    foreach ($requiredCp as $cp) {
        if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, $cp)) {
            $fail('checkpoint_' . $cp, 'Missing checkpoint ' . $cp);
        } else {
            $pass('checkpoint_' . $cp);
        }
    }

    if ($path === 'rollback') {
        if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP11')) {
            $fail('no_cp11_on_rollback', 'CP11 must not exist on rollback closeout path.');
        } else {
            $pass('no_cp11_on_rollback');
        }
        if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP10')) {
            $fail('no_cp10_on_rollback_fail', 'CP10 must not exist after verify FAIL → rollback.');
        } else {
            $pass('no_cp10_on_rollback_fail');
        }
    }

    $order = orange_cpr_checkpoint_write_order();
    $lastIdx = -1;
    $orderOk = true;
    $orderCheck = ['CP6', 'CP7', 'CP8', 'CP9'];
    if ($path === 'success') {
        $orderCheck = array_merge($orderCheck, ['CP10', 'CP11', 'CP12']);
    } else {
        $orderCheck[] = 'CP12';
    }
    foreach ($orderCheck as $cp) {
        $idx = array_search($cp, $order, true);
        if ($idx === false || $idx < $lastIdx) {
            $orderOk = false;
            break;
        }
        $lastIdx = (int) $idx;
    }
    if ($orderOk) {
        $pass('checkpoint_ordering');
    } else {
        $fail('checkpoint_ordering', 'Checkpoint DAG order violated for P6 path.');
    }

    $cpDir = orange_cpr_checkpoints_directory($cprRoot, $jobId);
    $dupTargets = $path === 'success' ? ['CP10', 'CP11', 'CP12'] : ['CP12'];
    $dupOk = true;
    foreach ($dupTargets as $cp) {
        $matches = glob($cpDir . DIRECTORY_SEPARATOR . $cp . '*.json') ?: [];
        $finals = array_values(array_filter($matches, static function (string $p): bool {
            return !str_contains(basename($p), '.tmp.');
        }));
        if (count($finals) !== 1) {
            $dupOk = false;
            $fail('no_duplicate_' . $cp, 'Expected exactly one sealed ' . $cp . ' file.');
        }
    }
    if ($dupOk) {
        $pass('no_duplicate_checkpoints');
    }

    if ($path === 'success') {
        $pv = orange_cpr_post_verify_live_load_latest($cprRoot, $jobId, 'report');
        if (!is_array($pv) || (string) ($pv['overall_result'] ?? '') !== 'PASS' || !orange_cpr_auth_verify_seal($pv)) {
            $fail('post_verify_pass', 'Sealed Post-Verify PASS required.');
        } else {
            $pass('post_verify_pass');
        }
        $sf = orange_cpr_success_finalize_live_load_latest($cprRoot, $jobId, 'report');
        if (!is_array($sf) || empty($sf['success_finalized']) || !orange_cpr_auth_verify_seal($sf)) {
            $fail('success_finalize', 'Sealed success finalize report required.');
        } else {
            $pass('success_finalize');
        }
        $rb = orange_cpr_rollback_live_load_latest($cprRoot, $jobId, 'report');
        if (is_array($rb) && !empty($rb['rollback_completed'])) {
            $fail('no_orphan_rollback', 'Rollback completion must not coexist with success path.');
        } else {
            $pass('no_orphan_rollback');
        }
    } else {
        $rb = orange_cpr_rollback_live_load_latest($cprRoot, $jobId, 'report');
        if (!is_array($rb) || empty($rb['rollback_completed']) || !orange_cpr_auth_verify_seal($rb)) {
            $fail('rollback_integrity', 'Sealed rollback completion report required.');
        } else {
            $pass('rollback_integrity');
            if (($rb['partial_rollback'] ?? true) !== false || empty($rb['restore_complete'])) {
                $fail('rollback_complete_boundary', 'Rollback must be complete/non-partial.');
            } else {
                $pass('rollback_complete_boundary');
            }
        }
        $sf = orange_cpr_success_finalize_live_load_latest($cprRoot, $jobId, 'report');
        if (is_array($sf) && !empty($sf['success_finalized'])) {
            $fail('no_orphan_success', 'Success finalize must not coexist with rollback path.');
        } else {
            $pass('no_orphan_success');
        }
    }

    $mr = orange_cpr_maint_release_live_load_latest($cprRoot, $jobId, 'report');
    if (!is_array($mr) || empty($mr['maintenance_released']) || !orange_cpr_auth_verify_seal($mr)) {
        $fail('maint_release_integrity', 'Sealed maint release report required.');
    } else {
        $pass('maint_release_integrity');
        if (($mr['partial_release'] ?? true) !== false || ($mr['auto_release'] ?? true) !== false) {
            $fail('maint_release_flags', 'Maint release must be non-partial and non-automatic.');
        } else {
            $pass('maint_release_flags');
        }
    }

    $cp12 = orange_cpr_checkpoint_load($env, $jobId, 'CP12');
    $cp12Payload = is_array($cp12['checkpoint']['payload'] ?? null) ? $cp12['checkpoint']['payload'] : [];
    $expectedPrior = $path === 'success' ? 'cpr_succeeded' : 'cpr_rollback_completed';
    if (!empty($cp12['ok'])
        && ($cp12Payload['runbook_completed'] ?? false) === true
        && ($cp12Payload['writers_restored'] ?? false) === true
        && (string) ($cp12Payload['prior_terminal'] ?? '') === $expectedPrior
    ) {
        $pass('cp12_integrity');
    } else {
        $fail('cp12_integrity', 'CP12 payload invalid for path ' . $path);
    }

    $maint = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if (is_array($maint) && empty($maint['global_maintenance_on']) && orange_cpr_auth_verify_seal($maint)) {
        $pass('maint_off');
    } else {
        $fail('maint_off', 'GLOBAL maintenance must be OFF after CP12.');
    }

    $lock = orange_cpr_lock_read($cprRoot);
    if ($lock === null || (string) ($lock['job_id'] ?? '') !== $jobId) {
        $pass('lock_released_closeout');
    } else {
        $fail('lock_released_closeout', 'CPR lock must be released after authorized closeout.');
    }

    $auth = orange_cpr_auth_live_load_latest($cprRoot, $jobId);
    if (is_array($auth) && !empty($auth['ponr_authorized']) && orange_cpr_auth_verify_seal($auth)) {
        $pass('authority_integrity');
    } else {
        $fail('authority_integrity', 'Sealed authority_live required.');
    }

    $audit = orange_cpr_p4_integration_read_audit($env, $jobId);
    $seen = [];
    foreach ($audit as $row) {
        $t = (string) ($row['event_type'] ?? '');
        if ($t !== '') {
            $seen[$t] = true;
        }
    }
    $need = [
        'cpr.uploads_live_complete',
        'cpr.maint_release_live_complete',
    ];
    if ($path === 'success') {
        $need[] = 'cpr.post_verify_live_complete';
        $need[] = 'cpr.success_finalize_live_complete';
    } else {
        $need[] = 'cpr.post_verify_live_fail';
        $need[] = 'cpr.rollback_live_complete';
    }
    $missing = [];
    foreach ($need as $ev) {
        if (empty($seen[$ev])) {
            $missing[] = $ev;
        }
    }
    if ($missing === []) {
        $pass('audit_chain');
    } else {
        $fail('audit_chain', 'Missing audit events: ' . implode(',', $missing));
    }

    // Replay / privilege refuse still hold on maint-release engine.
    $replay = orange_cpr_maint_release_live_run($env, $jobId, [
        'actor_admin_id' => (int) ($ctx['actor_admin_id'] ?? 7),
        'actor_is_super_admin' => true,
        'lease_token' => (string) ($ctx['lease_token'] ?? ''),
        'worker_id' => (string) ($ctx['worker_id'] ?? ''),
        'schema_revision' => 121,
        'runbook_completed' => true,
        'runbook_evidence_ref' => 'rb',
        'write_block_cleared_proof' => 'cleared',
        'force_replay' => true,
    ]);
    if (empty($replay['ok']) && ($replay['code'] ?? '') === ORANGE_CPR_MRLIVE_ERR_REPLAY) {
        $pass('no_replay_path');
    } elseif (!empty($replay['ok']) && !empty($replay['idempotent'])) {
        // Idempotent without force_replay is OK; force_replay must refuse — if ok+idempotent with force, fail.
        $fail('no_replay_path', 'force_replay must be refused; got idempotent ok');
    } else {
        $fail('no_replay_path', 'force_replay must be refused; got ' . (string) ($replay['code'] ?? 'ok'));
    }

    $bypass = orange_cpr_maint_release_live_refuse_unsafe(['force_pass' => true]);
    if (empty($bypass['ok'])) {
        $pass('no_privilege_bypass');
    } else {
        $fail('no_privilege_bypass', 'Unsafe knob not refused.');
    }

    $failed = array_values(array_filter($checks, static fn (array $c): bool => empty($c['ok'])));
    if ($failed !== []) {
        return orange_cpr_p6int_fail(
            ORANGE_CPR_P6INT_ERR_VERIFY,
            'P6 integration verification failed: ' . (string) ($failed[0]['id'] ?? 'check'),
            [
                'checks' => $checks,
                'failed' => $failed,
                'path' => $path,
                'job_state' => (string) ($job['state'] ?? ''),
                'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
                'country_id' => (int) ($job['country_id'] ?? 0),
                'session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
                'audit_event_count' => count($seen),
                'check_count' => count($checks),
            ]
        );
    }

    return orange_cpr_p6int_ok([
        'message' => 'P6 integration verification PASS.',
        'checks' => $checks,
        'check_count' => count($checks),
        'path' => $path,
        'job_state' => 'cpr_maintenance_released',
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
        'audit_event_count' => count($seen),
        'p6_baseline_ready' => true,
    ]);
}

/**
 * Orchestrate full P6 post-execution chain for success or rollback path.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_p6_integration_run(array $env, array $request = []): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_p6int_fail(ORANGE_CPR_P6INT_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_p6int_fail(ORANGE_CPR_P6INT_ERR_ENABLEMENT, 'Ops enablement must remain FALSE.');
    }
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_p6int_fail(ORANGE_CPR_P6INT_ERR_ACTOR, 'Super Admin required for P6 integration.');
    }
    $unsafe = orange_cpr_p6_integration_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $path = (string) ($request['path'] ?? 'success');
    if (!in_array($path, ['success', 'rollback'], true)) {
        return orange_cpr_p6int_fail(ORANGE_CPR_P6INT_ERR_PATH, 'path must be success or rollback.');
    }

    $actorAdminId = (int) $request['actor_admin_id'];
    $workerId = (string) ($request['worker_id'] ?? 'p6-int-worker');
    $stages = [];
    $cprRoot = orange_cpr_resolve_work_root($env);

    $p5 = orange_cpr_p5_integration_run($env, [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'worker_id' => $workerId,
    ]);
    if (empty($p5['ok'])) {
        return orange_cpr_p6int_fail(
            ORANGE_CPR_P6INT_ERR_CHAIN,
            'P5 through CP9 failed: ' . (string) ($p5['code'] ?? ''),
            ['stage' => 'p5_through_cp9', 'upstream' => $p5]
        );
    }
    $jobId = (string) ($p5['job_id'] ?? '');
    $lease = (string) ($p5['lease_token'] ?? '');
    $worker = (string) ($p5['worker_id'] ?? $workerId);
    $fp = (string) ($p5['package_fingerprint'] ?? '');
    $stages[] = ['stage' => 'p5_through_cp9', 'ok' => true, 'job_id' => $jobId];

    $baseReq = [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'schema_revision' => 121,
    ];

    if ($path === 'success') {
        $pv = orange_cpr_post_verify_live_run($env, $jobId, $baseReq);
        if (empty($pv['ok'])) {
            return orange_cpr_p6int_fail(
                ORANGE_CPR_P6INT_ERR_CHAIN,
                'Post-Verify failed: ' . (string) ($pv['code'] ?? ''),
                ['stage' => 'post_verify', 'upstream' => $pv, 'stages' => $stages]
            );
        }
        $stages[] = ['stage' => 'post_verify', 'ok' => true];
        if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP10')) {
            return orange_cpr_p6int_fail(ORANGE_CPR_P6INT_ERR_CHAIN, 'CP10 missing after Post-Verify.', ['stages' => $stages]);
        }
        $stages[] = ['stage' => 'cp10', 'ok' => true];

        $sf = orange_cpr_success_finalize_live_run($env, $jobId, $baseReq);
        if (empty($sf['ok'])) {
            return orange_cpr_p6int_fail(
                ORANGE_CPR_P6INT_ERR_CHAIN,
                'Success Finalize failed: ' . (string) ($sf['code'] ?? ''),
                ['stage' => 'success_finalize', 'upstream' => $sf, 'stages' => $stages]
            );
        }
        $stages[] = ['stage' => 'success_finalize', 'ok' => true];
        if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP11')) {
            return orange_cpr_p6int_fail(ORANGE_CPR_P6INT_ERR_CHAIN, 'CP11 missing after Success Finalize.', ['stages' => $stages]);
        }
        $stages[] = ['stage' => 'cp11', 'ok' => true];
    } else {
        $pv = orange_cpr_post_verify_live_run($env, $jobId, array_merge($baseReq, [
            'force_fail_check' => 'V02',
            'inject_survivor_hash' => str_repeat('x', 32),
        ]));
        if (!empty($pv['ok']) || ($pv['code'] ?? '') !== ORANGE_CPR_PVLIVE_ERR_SUITE) {
            return orange_cpr_p6int_fail(
                ORANGE_CPR_P6INT_ERR_CHAIN,
                'Expected Post-Verify FAIL pause for rollback path.',
                ['stage' => 'post_verify_fail_pause', 'upstream' => $pv, 'stages' => $stages]
            );
        }
        $jobPause = orange_cpr_job_read($cprRoot, $jobId);
        if ((string) ($jobPause['state'] ?? '') !== 'cpr_paused_verify_failed') {
            return orange_cpr_p6int_fail(
                ORANGE_CPR_P6INT_ERR_CHAIN,
                'Expected cpr_paused_verify_failed.',
                ['stages' => $stages, 'job_state' => (string) ($jobPause['state'] ?? '')]
            );
        }
        $stages[] = ['stage' => 'post_verify_fail_pause', 'ok' => true];

        $rb = orange_cpr_rollback_live_run($env, $jobId, array_merge($baseReq, [
            'confirmation_phrase' => 'RESTORE',
            'reauth_ok' => true,
            'password_reauth_ok' => true,
        ]));
        if (empty($rb['ok'])) {
            return orange_cpr_p6int_fail(
                ORANGE_CPR_P6INT_ERR_CHAIN,
                'OD-ROLLBACK failed: ' . (string) ($rb['code'] ?? ''),
                ['stage' => 'od_rollback', 'upstream' => $rb, 'stages' => $stages]
            );
        }
        $stages[] = ['stage' => 'od_rollback', 'ok' => true];
        $jobRb = orange_cpr_job_read($cprRoot, $jobId);
        if ((string) ($jobRb['state'] ?? '') !== 'cpr_rollback_completed') {
            return orange_cpr_p6int_fail(
                ORANGE_CPR_P6INT_ERR_CHAIN,
                'Expected cpr_rollback_completed.',
                ['stages' => $stages, 'job_state' => (string) ($jobRb['state'] ?? '')]
            );
        }
        $stages[] = ['stage' => 'rollback_completed', 'ok' => true];
    }

    $mr = orange_cpr_maint_release_live_run($env, $jobId, array_merge($baseReq, [
        'runbook_completed' => true,
        'runbook_evidence_ref' => 'runbook/evidence/p606-integration',
        'write_block_cleared_proof' => 'write_block_cleared:sa-p606-1',
    ]));
    if (empty($mr['ok'])) {
        return orange_cpr_p6int_fail(
            ORANGE_CPR_P6INT_ERR_CHAIN,
            'Maint Release failed: ' . (string) ($mr['code'] ?? ''),
            ['stage' => 'maintenance_release', 'upstream' => $mr, 'stages' => $stages]
        );
    }
    $stages[] = ['stage' => 'maintenance_release', 'ok' => true];
    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP12')) {
        return orange_cpr_p6int_fail(ORANGE_CPR_P6INT_ERR_CHAIN, 'CP12 missing after Maint Release.', ['stages' => $stages]);
    }
    $stages[] = ['stage' => 'cp12', 'ok' => true];

    $verify = orange_cpr_p6_integration_verify($env, $jobId, [
        'path' => $path,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'actor_admin_id' => $actorAdminId,
        'package_fingerprint' => $fp,
    ]);
    if (empty($verify['ok'])) {
        return array_merge($verify, [
            'stages' => $stages,
            'job_id' => $jobId,
            'lease_token' => $lease,
            'worker_id' => $worker,
            'path' => $path,
        ]);
    }

    $now = gmdate('c');
    $reportId = orange_cpr_generate_job_id();
    $record = [
        'schema_version' => ORANGE_CPR_P6_INTEGRATION_SCHEMA,
        'engine_version' => ORANGE_CPR_P6_INTEGRATION_VERSION,
        'record_type' => 'p6_integration_verification',
        'integration_report_id' => $reportId,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'path' => $path,
        'stage_order' => orange_cpr_p6_integration_stage_order($path),
        'stages' => $stages,
        'verification' => [
            'checks' => $verify['checks'] ?? [],
            'check_count' => (int) ($verify['check_count'] ?? 0),
            'audit_event_count' => (int) ($verify['audit_event_count'] ?? 0),
        ],
        'recovery_metadata' => [
            'job_state' => (string) ($verify['job_state'] ?? ''),
            'package_fingerprint' => (string) ($verify['package_fingerprint'] ?? ''),
            'country_id' => (int) ($verify['country_id'] ?? 0),
            'session_full_backup_id' => (string) ($verify['session_full_backup_id'] ?? ''),
            'completed_phase' => 'p6_verify_rollback_integration',
            'execution_status' => 'p6_baseline_frozen',
            'path' => $path,
            'ponr_crossed' => true,
            'production_sql_executed' => false,
            'production_uploads_mutated' => false,
            'primary_rollback' => 'session_full_backup_od_pin',
            'p7_not_started' => true,
            'enterprise_audit_not_started' => true,
            'git_tag_not_created' => true,
        ],
        'package_fingerprint' => $fp,
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'ops_enablement_flag' => false,
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
        'p6_baseline_ready' => true,
        'p6_phase_complete' => true,
        'p6_integration_baseline_complete' => true,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'p7_started' => false,
        'live_path' => true,
        'created_at' => $now,
    ];

    try {
        $pathOut = orange_cpr_p6_integration_persist($cprRoot, $jobId, 'cpr_p6_integration_' . $reportId, $record);
        $latest = orange_cpr_p6_integration_latest_path($cprRoot, $jobId);
        $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
        $sealedLatest = orange_cpr_auth_seal(array_merge($record, ['integration_report_path' => $pathOut]));
        $json = json_encode($sealedLatest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('P6 integration latest pointer write failed.');
        }
        orange_cpr_atomic_rename_replace($tmp, $latest);
    } catch (RuntimeException $e) {
        return orange_cpr_p6int_fail(ORANGE_CPR_P6INT_ERR_PERSIST, $e->getMessage(), ['stages' => $stages]);
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.p6_integration_verify',
        'job_id' => $jobId,
        'integration_report_id' => $reportId,
        'path' => $path,
        'p6_baseline_ready' => true,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'p7_started' => false,
        'production_sql_executed' => false,
        'enablement_flag_observed' => false,
        'actor_admin_id' => $actorAdminId,
    ]);

    return orange_cpr_p6int_ok([
        'message' => 'P6 integration baseline chain verified and sealed.',
        'job_id' => $jobId,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'package_fingerprint' => $fp,
        'path' => $path,
        'stages' => $stages,
        'integration_report_id' => $reportId,
        'integration_report' => orange_cpr_p6_integration_load_latest($cprRoot, $jobId),
        'p6_baseline_ready' => true,
        'p6_phase_complete' => true,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'p7_started' => false,
        'job_state' => 'cpr_maintenance_released',
    ]);
}
