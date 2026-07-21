<?php

declare(strict_types=1);

/**
 * CPR P4 Integration Baseline — full Pre-PONR live chain (WP-P4-09).
 *
 * Orchestrates existing P4 live modules only (no new business mutation logic).
 * Validates the complete execution order through CP-A and seals a verification report.
 *
 * Order: CP4 → Session Full Backup → Verify → CP1 → Lock → Gates → Authority → Witnesses → CP5 → CP-A
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P4_09_INTEGRATION_BASELINE.md
 */

require_once __DIR__ . '/cpr_witnesses_live.php';
require_once __DIR__ . '/cpr_authority_live.php';
require_once __DIR__ . '/cpr_gates_live.php';
require_once __DIR__ . '/cpr_lock_live.php';
require_once __DIR__ . '/cpr_od_pin_live.php';
require_once __DIR__ . '/cpr_maintenance_live.php';
require_once __DIR__ . '/cpr_state_engine.php';

const ORANGE_CPR_P4_INTEGRATION_SCHEMA = 'cpr_p4_integration/1';
const ORANGE_CPR_P4_INTEGRATION_VERSION = 'P4-09-1.0';

const ORANGE_CPR_P4INT_ERR_ENABLEMENT = 'p4int_enablement_forbidden';
const ORANGE_CPR_P4INT_ERR_ACTOR = 'p4int_actor_not_super_admin';
const ORANGE_CPR_P4INT_ERR_CHAIN = 'p4int_chain_failed';
const ORANGE_CPR_P4INT_ERR_VERIFY = 'p4int_verification_failed';
const ORANGE_CPR_P4INT_ERR_PERSIST = 'p4int_persist_failed';
const ORANGE_CPR_P4INT_ERR_PONR = 'p4int_ponr_forbidden';
const ORANGE_CPR_P4INT_ERR_BYPASS = 'p4int_bypass_forbidden';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_p4int_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'p4_baseline_ready' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_p4int_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'p4_baseline_ready' => false,
        'fail_closed' => true,
    ], $extra);
}

/**
 * Canonical Pre-PONR live stage order (WP-P4-09).
 *
 * @return list<string>
 */
function orange_cpr_p4_integration_stage_order(): array
{
    return [
        'cp4_maint',
        'session_full_backup',
        'verify_backup',
        'cp1_pin',
        'lock_acquire',
        'gates_live',
        'authority_ceremony',
        'witnesses_capture',
        'cp5',
        'cpa',
    ];
}

/**
 * @return array<string, mixed>
 */
function orange_cpr_p4_integration_default_reports(): array
{
    return [
        'c4' => ['overall' => 'PASS', 'pkg' => 'x'],
        'c5' => ['overall_result' => 'pass', 'recovery_score' => 90],
        'c6' => ['status' => 'ready', 'production_touched' => false],
        'c7' => [
            'overall_result' => 'READY',
            'readiness_score' => 95,
            'survivor_country_integrity' => 'PASS',
            'global_state_integrity' => 'PASS',
            'pillars' => ['accounting' => 'PASS', 'stock_fifo' => 'PASS', 'composite' => 'PASS'],
        ],
        'c8' => [
            'overall_result' => 'SAFE',
            'survivor_country_impact' => 0,
            'global_impact' => 0,
            'je_full_only_impact' => 0,
            'simulation_only' => true,
            'execution_performed' => false,
        ],
    ];
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_p4_integration_persist(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_integration_live_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR integration_live directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Integration record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Integration record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_p4_integration_latest_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_integration_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_p4_integration_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_p4_integration_load_latest(string $cprRoot, string $jobId): ?array
{
    $path = orange_cpr_p4_integration_latest_path($cprRoot, $jobId);
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
 * @param array<string, mixed> $env
 * @return list<array<string, mixed>>
 */
function orange_cpr_p4_integration_read_audit(array $env, string $jobId): array
{
    $cprRoot = orange_cpr_resolve_work_root($env);
    $path = orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME;
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }
    $out = [];
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $out[] = $row;
        }
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $audit
 * @param list<string> $required
 * @return array{ok:bool,missing:list<string>}
 */
function orange_cpr_p4_integration_audit_has_events(array $audit, array $required): array
{
    $seen = [];
    foreach ($audit as $row) {
        $t = (string) ($row['event_type'] ?? '');
        if ($t !== '') {
            $seen[$t] = true;
        }
    }
    $missing = [];
    foreach ($required as $ev) {
        if (empty($seen[$ev])) {
            $missing[] = $ev;
        }
    }

    return ['ok' => $missing === [], 'missing' => $missing];
}

/**
 * Build C4–C8 evidence for live gates (consumable hashes only; no C3–C8 engine changes).
 *
 * @param array<string, mixed> $reports
 * @param array<string, mixed> $meta
 * @return array<string, mixed>
 */
function orange_cpr_p4_integration_gate_evidence(array $reports, array $meta): array
{
    return [
        'enablement' => true,
        'od_enable_preconditions_complete' => true,
        'pin_capability' => true,
        'certification' => ['result' => 'PASS'],
        'host_preflight' => ['pass' => true],
        'package' => [
            'status' => 'finalized',
            'package_id' => (string) ($meta['package_id'] ?? ''),
            'fingerprint' => (string) ($meta['package_fingerprint'] ?? ''),
        ],
        'reports' => $reports,
        'inventory' => [
            'id' => 'inv-1',
            'hash' => str_repeat('1', 32),
            'certified_read_only' => true,
            'replaced_by_live' => false,
        ],
        'live_sot' => [
            'schema_revision' => 121,
            'boundary_policy_version' => 'C1.1',
            'dependency_graph_version' => '1',
            'registry_revision' => 121,
            'production_db_identity_hash' => str_repeat('2', 32),
        ],
        'authority' => [
            'wfa_protections_ack' => true,
            'country_admin_is_executor' => false,
        ],
        'phrase' => [
            'phrase_ok' => true,
            'phrase' => 'RESTORE',
            'reauth_ok' => true,
            'one_time_authorization_id' => 'ota-pending',
            'ota_consumed' => false,
        ],
        'emergency_stop' => false,
        'fa_resolver' => ['proven' => true, 'shortcut' => false],
        'fa_stock' => ['armed' => true, 'soft_mode' => false],
        'fa_schema' => ['live_revision' => 121, 'soft_skip' => false, 'cert_invalidated' => false],
        'witness_expectations' => [
            'survivor_baseline_hash' => str_repeat('s', 32),
            'global_baseline_hash' => str_repeat('g', 32),
            'target_inventory_hash' => str_repeat('t', 32),
        ],
    ];
}

/**
 * Post-chain verification (fail-closed).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_p4_integration_verify(array $env, string $jobId, array $ctx): array
{
    $cprRoot = orange_cpr_resolve_work_root($env);
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
        return orange_cpr_p4int_fail(ORANGE_CPR_P4INT_ERR_VERIFY, $e->getMessage());
    }
    if ((string) ($job['state'] ?? '') !== 'cpr_pre_ponr') {
        $fail('state_cpr_pre_ponr', 'Expected state cpr_pre_ponr; got ' . (string) ($job['state'] ?? ''));
    } else {
        $pass('state_cpr_pre_ponr');
    }
    if (!empty($job['ponr_crossed'])) {
        $fail('ponr_not_crossed', 'ponr_crossed must be false.');
    } else {
        $pass('ponr_not_crossed');
    }
    if (empty($job['ponr_authorized'])) {
        $fail('ponr_authorized', 'Job must be ponr_authorized after authority.');
    } else {
        $pass('ponr_authorized');
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        $fail('contract_frozen', 'Frozen contract required.');
    } else {
        $pass('contract_frozen');
        if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
            || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
        ) {
            $fail('contract_identity', 'Contract/job identity drift.');
        } else {
            $pass('contract_identity');
        }
        if ((string) ($contract['contract_phase'] ?? '') !== 'pre_ponr') {
            $fail('contract_phase', 'Contract phase must be pre_ponr.');
        } else {
            $pass('contract_phase');
        }
        if (empty($contract['session_full_backup_pinned'])
            || (string) ($contract['session_full_backup_id'] ?? '') === ''
        ) {
            $fail('session_pin_bound', 'Session Full Backup pin missing on contract.');
        } else {
            $pass('session_pin_bound');
        }
        if (empty($contract['one_time_authorization_id'])) {
            $fail('ota_bound', 'OTA missing on contract.');
        } else {
            $pass('ota_bound');
        }
    }

    $requiredCp = ['CP0', 'CP2', 'CP3', 'CP4', 'CP1', 'runbook_pre_ponr', 'CP5', 'CP-A'];
    foreach ($requiredCp as $cp) {
        if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, $cp)) {
            $fail('checkpoint_' . $cp, 'Missing checkpoint ' . $cp);
        } else {
            $pass('checkpoint_' . $cp);
        }
    }
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP6')) {
        $fail('no_cp6', 'CP6 must not exist (PONR not entered).');
    } else {
        $pass('no_cp6');
    }

    // Checkpoint ordering: write-order indices non-decreasing for required set.
    $order = orange_cpr_checkpoint_write_order();
    $lastIdx = -1;
    $orderOk = true;
    foreach ($requiredCp as $cp) {
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
        $fail('checkpoint_ordering', 'Checkpoint DAG order violated.');
    }

    $gates = orange_cpr_gates_live_load_latest($cprRoot, $jobId);
    if (!is_array($gates) || empty($gates['all_gates_pass']) || !orange_cpr_auth_verify_seal($gates)) {
        $fail('gates_live_pass', 'Sealed gates_live PASS missing.');
    } else {
        $pass('gates_live_pass');
    }
    $auth = orange_cpr_auth_live_load_latest($cprRoot, $jobId);
    if (!is_array($auth) || empty($auth['ponr_authorized']) || !orange_cpr_auth_verify_seal($auth)) {
        $fail('authority_live', 'Sealed authority_live missing.');
    } else {
        $pass('authority_live');
    }
    $rb = orange_cpr_runbook_live_load_latest($cprRoot, $jobId);
    if (!is_array($rb) || empty($rb['runbook_completed']) || !orange_cpr_auth_verify_seal($rb)) {
        $fail('runbook_live', 'Sealed runbook_live missing.');
    } else {
        $pass('runbook_live');
    }
    $wit = orange_cpr_witnesses_live_load_bundle($cprRoot, $jobId);
    if (!is_array($wit) || empty($wit['witness_bundle_sealed']) || !orange_cpr_auth_verify_seal($wit)) {
        $fail('witness_bundle', 'Sealed witness bundle missing.');
    } else {
        $pass('witness_bundle');
    }
    $cpaLive = orange_cpr_witnesses_live_load_cpa($cprRoot, $jobId);
    if (!is_array($cpaLive) || empty($cpaLive['cpa_committed']) || !orange_cpr_auth_verify_seal($cpaLive)) {
        $fail('cpa_live', 'Sealed CP-A live record missing.');
    } else {
        $pass('cpa_live');
    }

    $pin = orange_cpr_od_pin_live_load_pin($cprRoot, $jobId);
    if (!is_array($pin) || empty($pin['pinned']) || !orange_cpr_auth_verify_seal($pin)) {
        $fail('od_pin_sealed', 'OD-PIN sealed pin missing.');
    } else {
        $pass('od_pin_sealed');
    }
    $maint = orange_cpr_maint_live_load_state($cprRoot, $jobId);
    if (!is_array($maint) || empty($maint['global_maintenance_on']) || empty($maint['write_block_proven'])) {
        $fail('maint_global', 'GLOBAL Maint proven missing.');
    } else {
        $pass('maint_global');
    }

    $lockRv = orange_cpr_lock_live_revalidate_ownership($env, $jobId, [
        'lease_token' => (string) ($ctx['lease_token'] ?? ''),
        'worker_id' => (string) ($ctx['worker_id'] ?? ''),
    ]);
    if (empty($lockRv['ok'])) {
        $fail('lock_ownership', (string) ($lockRv['message'] ?? 'Lock ownership failed.'));
    } else {
        $pass('lock_ownership');
    }

    // CP5 vs witness bundle fingerprint bind.
    $cp5 = orange_cpr_checkpoint_load($env, $jobId, 'CP5');
    if (!empty($cp5['ok']) && is_array($wit)) {
        $payload = is_array($cp5['checkpoint']['payload'] ?? null) ? $cp5['checkpoint']['payload'] : [];
        $bindOk = true;
        foreach (['survivor_baseline_hash', 'global_baseline_hash', 'target_inventory_hash', 'inventory_snapshot_id'] as $k) {
            if ((string) ($payload[$k] ?? '') !== (string) ($wit[$k] ?? '')) {
                $bindOk = false;
                break;
            }
        }
        if ($bindOk) {
            $pass('witness_cp5_bind');
        } else {
            $fail('witness_cp5_bind', 'CP5 payload drift vs witness bundle.');
        }
    } else {
        $fail('witness_cp5_bind', 'CP5 or witness bundle unreadable.');
    }

    $audit = orange_cpr_p4_integration_read_audit($env, $jobId);
    $auditNeed = [
        'cpr.gates_live_evaluate',
        'cpr.runbook_live_complete',
        'cpr.authority_live_authorize',
        'cpr.witnesses_live_capture',
        'cpr.witnesses_live_commit_cp5',
        'cpr.witnesses_live_commit_cpa',
    ];
    $auditCheck = orange_cpr_p4_integration_audit_has_events($audit, $auditNeed);
    if ($auditCheck['ok']) {
        $pass('audit_chain');
    } else {
        $fail('audit_chain', 'Missing audit events: ' . implode(',', $auditCheck['missing']));
    }

    // No orphan post-PONR mutation artifacts.
    $pipelineDir = orange_cpr_pipeline_directory($cprRoot, $jobId);
    if (is_dir($pipelineDir)) {
        $fail('no_orphan_pipeline', 'Unexpected pipeline directory (mutation path).');
    } else {
        $pass('no_orphan_pipeline');
    }

    $refuse = orange_cpr_ponr_mutation_refuse();
    if (empty($refuse['ok']) && ($refuse['code'] ?? '') === 'ponr_mutation_forbidden') {
        $pass('no_ponr_mutation');
    } else {
        $fail('no_ponr_mutation', 'PONR mutation refuse helper not fail-closed.');
    }

    // Privilege / replay guards still refuse.
    $bypass = orange_cpr_gates_live_refuse_unsafe_options(['force_pass' => true], []);
    if (empty($bypass['ok'])) {
        $pass('no_privilege_bypass');
    } else {
        $fail('no_privilege_bypass', 'Bypass not refused.');
    }

    $allOk = true;
    foreach ($checks as $c) {
        if (empty($c['ok'])) {
            $allOk = false;
            break;
        }
    }

    if (!$allOk) {
        return orange_cpr_p4int_fail(
            ORANGE_CPR_P4INT_ERR_VERIFY,
            'P4 integration verification failed (fail-closed).',
            ['checks' => $checks]
        );
    }

    return orange_cpr_p4int_ok([
        'checks' => $checks,
        'check_count' => count($checks),
        'audit_event_count' => count($audit),
        'job_state' => (string) ($job['state'] ?? ''),
        'contract_phase' => (string) ($contract['contract_phase'] ?? ''),
        'session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
        'one_time_authorization_id' => (string) ($contract['one_time_authorization_id'] ?? ''),
    ]);
}

/**
 * Execute the full P4 Pre-PONR live chain and seal integration verification report.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_p4_integration_run(array $env, array $request = []): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_p4int_fail(ORANGE_CPR_P4INT_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_p4int_fail(
            ORANGE_CPR_P4INT_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during P4 integration.'
        );
    }
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_p4int_fail(ORANGE_CPR_P4INT_ERR_ACTOR, 'Super Admin actor required for P4 integration run.');
    }
    if (!empty($request['force_pass']) || !empty($request['bypass']) || !empty($request['execute_ponr'])) {
        return orange_cpr_p4int_fail(ORANGE_CPR_P4INT_ERR_BYPASS, 'Bypass / PONR knobs forbidden.');
    }

    $actorAdminId = (int) $request['actor_admin_id'];
    $workerId = (string) ($request['worker_id'] ?? 'p4-int-worker');
    $stages = [];
    $cprRoot = orange_cpr_resolve_work_root($env);

    $reports = orange_cpr_p4_integration_default_reports();
    $fp = hash('sha256', 'fp-p4int-' . bin2hex(random_bytes(4)));
    $pkgId = 'pkg-p4int-' . substr($fp, 0, 8);

    $job = orange_cpr_job_create($env, [
        'package_id' => $pkgId,
        'package_fingerprint' => $fp,
        'country_id' => 1,
        'country_code' => 'KW',
        'workflow' => 'A',
    ], $actorAdminId);
    $jobId = (string) $job['job_id'];

    $fps = [
        'schema_revision_expected' => 121,
        'boundary_policy_version' => 'C1.1',
        'dependency_graph_version' => '1',
        'registry_revision' => 121,
        'c4_report_hash' => orange_cpr_gate_report_hash($reports['c4']),
        'c5_report_hash' => orange_cpr_gate_report_hash($reports['c5']),
        'c6_report_hash' => orange_cpr_gate_report_hash($reports['c6']),
        'c7_report_hash' => orange_cpr_gate_report_hash($reports['c7']),
        'c8_report_hash' => orange_cpr_gate_report_hash($reports['c8']),
        'c8_overall_result' => 'SAFE',
        'inventory_snapshot_id' => 'inv-1',
        'inventory_snapshot_hash' => str_repeat('1', 32),
        'production_db_identity_hash' => str_repeat('2', 32),
        'package_fingerprint' => $fp,
    ];

    // Contract freeze path (P4-02 substrate) before live CP4.
    orange_cpr_transition_apply($env, $jobId, 'cpr_gates_validating', ['actor' => ORANGE_CPR_ACTOR_SYSTEM]);
    orange_cpr_checkpoint_create($env, $jobId, 'CP0', [
        'c4_overall' => 'PASS',
        'c5_overall' => 'pass',
        'c5_recovery_score' => 90,
        'c6_status' => 'ready',
        'c7_overall' => 'READY',
        'c7_readiness_score' => 95,
        'c8_overall_result' => 'SAFE',
        'enablement_flag_observed' => false,
        'schema_revision_observed' => 121,
        'boundary_policy_version' => 'C1.1',
        'report_hashes' => [
            'c4' => $fps['c4_report_hash'],
            'c5' => $fps['c5_report_hash'],
            'c6' => $fps['c6_report_hash'],
            'c7' => $fps['c7_report_hash'],
            'c8' => $fps['c8_report_hash'],
        ],
    ], ['written_by' => 'system']);
    orange_cpr_transition_apply($env, $jobId, 'cpr_contract_frozen', [
        'actor' => ORANGE_CPR_ACTOR_SYSTEM,
        'contract_fingerprints_ok' => true,
    ]);
    orange_cpr_checkpoint_create($env, $jobId, 'CP2', [
        'workflow' => 'A',
        'wfa_protections_ack' => true,
        'approval_fingerprint' => str_repeat('9', 32),
    ], ['written_by' => 'system']);
    orange_cpr_contract_freeze_initial($env, $jobId, $fps, $actorAdminId);
    orange_cpr_checkpoint_create($env, $jobId, 'CP3', [
        'contract_revision' => 1,
        'contract_phase' => 'pre_pin',
        'package_fingerprint' => $fp,
        'fingerprint_digest' => hash('sha256', $fp),
    ], ['written_by' => 'system']);
    $stages[] = ['stage' => 'contract_freeze_pre_pin', 'ok' => true];

    // CP4 — GLOBAL Maint live
    $maint = orange_cpr_maint_live_activate_cp4($env, $jobId, [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'maint_scope' => 'GLOBAL',
        'write_block_proof' => 'cpr_write_block_proof:orders',
    ]);
    if (empty($maint['ok'])) {
        return orange_cpr_p4int_fail(ORANGE_CPR_P4INT_ERR_CHAIN, 'CP4 maint failed: ' . (string) ($maint['code'] ?? ''), [
            'stage' => 'cp4_maint',
            'upstream' => $maint,
        ]);
    }
    $stages[] = ['stage' => 'cp4_maint', 'ok' => true, 'code' => (string) ($maint['code'] ?? 'ok')];

    // Session Full Backup → Verify → CP1 (OD-PIN live)
    $pin = orange_cpr_od_pin_live_run($env, $jobId, [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
    ]);
    if (empty($pin['ok'])) {
        return orange_cpr_p4int_fail(ORANGE_CPR_P4INT_ERR_CHAIN, 'OD-PIN failed: ' . (string) ($pin['code'] ?? ''), [
            'stage' => 'cp1_pin',
            'upstream' => $pin,
        ]);
    }
    $sessionId = (string) ($pin['session_full_backup_id'] ?? '');
    $stages[] = ['stage' => 'session_full_backup', 'ok' => true];
    $stages[] = ['stage' => 'verify_backup', 'ok' => true];
    $stages[] = ['stage' => 'cp1_pin', 'ok' => true, 'session_full_backup_id' => $sessionId];

    // Lock
    $acq = orange_cpr_lock_live_acquire($env, $jobId, [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'owner_class' => 'super_admin_procedure',
        'worker_id' => $workerId,
    ]);
    if (empty($acq['ok'])) {
        return orange_cpr_p4int_fail(ORANGE_CPR_P4INT_ERR_CHAIN, 'Lock acquire failed: ' . (string) ($acq['code'] ?? ''), [
            'stage' => 'lock_acquire',
            'upstream' => $acq,
        ]);
    }
    $lease = (string) ($acq['lease_token'] ?? '');
    $worker = (string) ($acq['worker_id'] ?? $workerId);
    $stages[] = ['stage' => 'lock_acquire', 'ok' => true];

    // Provisional runbook + CP5 for G28 during gates (replaced by live CP5 after authority).
    orange_cpr_checkpoint_create($env, $jobId, 'runbook_pre_ponr', [
        'restore_package_id' => $pkgId,
        'target_country_id' => 1,
        'target_country_code' => 'KW',
        'c8_overall_result' => 'SAFE',
        'certified_inventory_snapshot_id' => 'inv-1',
        'session_full_backup_id' => $sessionId,
        'global_maintenance_active' => true,
        'completed_by_admin_id' => $actorAdminId,
        'completed_at' => gmdate('c'),
        'audit_record_id' => 'aud-p4int-1',
    ], ['written_by' => 'super_admin']);
    orange_cpr_checkpoint_create($env, $jobId, 'CP5', [
        'survivor_baseline_hash' => str_repeat('s', 32),
        'global_baseline_hash' => str_repeat('g', 32),
        'target_inventory_hash' => str_repeat('t', 32),
        'inventory_snapshot_id' => 'inv-1',
        'captured_at' => gmdate('c'),
    ], ['written_by' => 'system']);

    // Gates live
    $gate = orange_cpr_gates_live_evaluate($env, $jobId, [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'evidence' => orange_cpr_p4_integration_gate_evidence($reports, [
            'package_id' => $pkgId,
            'package_fingerprint' => $fp,
        ]),
    ]);
    if (empty($gate['ok']) || empty($gate['all_gates_pass'])) {
        return orange_cpr_p4int_fail(ORANGE_CPR_P4INT_ERR_CHAIN, 'Gates live failed: ' . (string) ($gate['code'] ?? ''), [
            'stage' => 'gates_live',
            'upstream' => $gate,
        ]);
    }
    $stages[] = ['stage' => 'gates_live', 'ok' => true, 'gates_live_id' => (string) ($gate['gates_live_id'] ?? '')];

    // Authority / Runbook / RESTORE
    $auth = orange_cpr_authority_live_ceremony($env, $jobId, [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'all_minimum_items_confirmed' => true,
        'phrase' => 'RESTORE',
        'password_reauth_ok' => true,
    ]);
    if (empty($auth['ok']) || empty($auth['ponr_authorized'])) {
        return orange_cpr_p4int_fail(ORANGE_CPR_P4INT_ERR_CHAIN, 'Authority ceremony failed: ' . (string) ($auth['code'] ?? ''), [
            'stage' => 'authority_ceremony',
            'upstream' => $auth,
        ]);
    }
    $stages[] = ['stage' => 'authority_ceremony', 'ok' => true, 'ota' => (string) ($auth['one_time_authorization_id'] ?? '')];

    // Replace provisional CP5 with live witnesses → CP5 → CP-A
    $cp5Path = orange_cpr_checkpoint_final_path($cprRoot, $jobId, 'CP5');
    if (is_file($cp5Path)) {
        @unlink($cp5Path);
    }

    $wit = orange_cpr_witnesses_live_ceremony($env, $jobId, [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'witnesses' => [
            'survivor_baseline_hash' => str_repeat('s', 32),
            'global_baseline_hash' => str_repeat('g', 32),
            'target_inventory_hash' => str_repeat('t', 32),
            'inventory_snapshot_id' => 'inv-1',
            'inventory_snapshot_hash' => str_repeat('1', 32),
            'captured_at' => gmdate('c'),
            'schema_revision' => 121,
            'package_fingerprint' => $fp,
            'c8_overall_result' => 'SAFE',
        ],
    ]);
    if (empty($wit['ok']) || empty($wit['last_fully_reversible_idle_point'])) {
        return orange_cpr_p4int_fail(ORANGE_CPR_P4INT_ERR_CHAIN, 'Witnesses/CP-A failed: ' . (string) ($wit['code'] ?? ''), [
            'stage' => 'cpa',
            'upstream' => $wit,
        ]);
    }
    $stages[] = ['stage' => 'witnesses_capture', 'ok' => true];
    $stages[] = ['stage' => 'cp5', 'ok' => true];
    $stages[] = ['stage' => 'cpa', 'ok' => true, 'cpa_live_id' => (string) ($wit['cpa_live_id'] ?? '')];

    $verify = orange_cpr_p4_integration_verify($env, $jobId, [
        'lease_token' => $lease,
        'worker_id' => $worker,
    ]);
    if (empty($verify['ok'])) {
        return $verify;
    }

    $now = gmdate('c');
    $reportId = orange_cpr_generate_job_id();
    $record = [
        'schema_version' => ORANGE_CPR_P4_INTEGRATION_SCHEMA,
        'engine_version' => ORANGE_CPR_P4_INTEGRATION_VERSION,
        'record_type' => 'p4_integration_verification',
        'integration_report_id' => $reportId,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'stage_order' => orange_cpr_p4_integration_stage_order(),
        'stages' => $stages,
        'verification' => [
            'checks' => $verify['checks'] ?? [],
            'check_count' => (int) ($verify['check_count'] ?? 0),
            'audit_event_count' => (int) ($verify['audit_event_count'] ?? 0),
        ],
        'recovery_metadata' => [
            'job_state' => (string) ($verify['job_state'] ?? ''),
            'contract_phase' => (string) ($verify['contract_phase'] ?? ''),
            'session_full_backup_id' => (string) ($verify['session_full_backup_id'] ?? ''),
            'one_time_authorization_id' => (string) ($verify['one_time_authorization_id'] ?? ''),
            'last_fully_reversible_idle_point' => true,
            'ponr_not_entered' => true,
        ],
        'package_id' => $pkgId,
        'package_fingerprint' => $fp,
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'ops_enablement_flag' => false,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
        'p4_baseline_ready' => true,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'p5_started' => false,
        'scaffold_record_only' => false,
        'live_path' => true,
        'created_at' => $now,
    ];

    try {
        $path = orange_cpr_p4_integration_persist($cprRoot, $jobId, 'cpr_p4_integration_' . $reportId, $record);
        $latest = orange_cpr_p4_integration_latest_path($cprRoot, $jobId);
        $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
        $sealedLatest = orange_cpr_auth_seal(array_merge($record, ['integration_report_path' => $path]));
        $json = json_encode($sealedLatest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Integration latest pointer write failed.');
        }
        orange_cpr_atomic_rename_replace($tmp, $latest);
    } catch (RuntimeException $e) {
        return orange_cpr_p4int_fail(ORANGE_CPR_P4INT_ERR_PERSIST, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.p4_integration_verify',
        'job_id' => $jobId,
        'integration_report_id' => $reportId,
        'p4_baseline_ready' => true,
        'actor_admin_id' => $actorAdminId,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'enablement_flag_observed' => false,
        'production_mutation' => false,
    ]);

    $jobAfter = orange_cpr_job_read($cprRoot, $jobId);
    if (!empty($jobAfter['ponr_crossed'])) {
        return orange_cpr_p4int_fail(ORANGE_CPR_P4INT_ERR_PONR, 'Invariant broken: ponr_crossed became true.');
    }

    return orange_cpr_p4int_ok([
        'message' => 'P4 Pre-PONR live chain verified through CP-A; baseline ready; no PONR.',
        'job_id' => $jobId,
        'integration_report_id' => $reportId,
        'integration_report_path' => $path,
        'integration_report' => orange_cpr_p4_integration_load_latest($cprRoot, $jobId),
        'stages' => $stages,
        'verification' => $verify,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'p4_baseline_ready' => true,
        'enablement_flag_observed' => false,
    ]);
}
