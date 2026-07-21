<?php

declare(strict_types=1);

/**
 * CPR Pre-PONR Gate Evaluation Engine (WP-P3-06).
 *
 * Fail-closed G01–G30 + FA gates per CPR-P1-WP08-PRE_PONR_GATES.
 * Consumes C4–C8 / inventory / locks / checkpoints as evidence — never mutates C3–C8.
 * No DELETE/IMPORT/PONR mutation. No Super Admin bypass. No SKIP.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P3_06_GATE_EVALUATOR.md
 */

require_once __DIR__ . '/cpr_job_framework.php';
require_once __DIR__ . '/cpr_checkpoint_engine.php';
require_once __DIR__ . '/cpr_lock_engine.php';
require_once __DIR__ . '/cpr_gate_catalog.php';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_gate_result(string $gateId, string $result, ?string $failCode = null, array $refs = [], array $extra = []): array
{
    $row = [
        'gate_id' => $gateId,
        'result' => $result,
        'evidence_refs' => $refs,
    ];
    if ($failCode !== null) {
        $row['fail_code'] = $failCode;
    }

    return array_merge($row, $extra);
}

function orange_cpr_gate_pass(string $gateId, array $refs = []): array
{
    return orange_cpr_gate_result($gateId, ORANGE_CPR_GATE_PASS, null, $refs);
}

function orange_cpr_gate_fail(string $gateId, string $failCode, array $refs = []): array
{
    return orange_cpr_gate_result($gateId, ORANGE_CPR_GATE_FAIL, $failCode, $refs);
}

/**
 * Hash report payload for drift checks.
 *
 * @param array<string, mixed> $report
 */
function orange_cpr_gate_report_hash(array $report): string
{
    ksort($report);

    return hash('sha256', (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Build evaluation context from job + contract + checkpoints + locks + evidence bundle.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $evidence Optional durable/synthetic evidence (reports, FA proofs, …)
 * @return array<string, mixed>
 */
function orange_cpr_gate_build_context(array $env, string $jobId, array $evidence = []): array
{
    $cprRoot = orange_cpr_resolve_work_root($env);
    $job = orange_cpr_job_read($cprRoot, $jobId);
    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    $lock = orange_cpr_lock_read($cprRoot);
    $committed = orange_cpr_checkpoint_list_committed($cprRoot, $jobId);

    $checkpoints = [];
    foreach ($committed as $cpId) {
        $loaded = orange_cpr_checkpoint_load($env, $jobId, $cpId);
        if (!empty($loaded['ok'])) {
            $checkpoints[$cpId] = $loaded['checkpoint'];
        }
    }

    return [
        'env' => $env,
        'cpr_root' => $cprRoot,
        'job' => $job,
        'contract' => $contract,
        'lock' => $lock,
        'checkpoints' => $checkpoints,
        'committed_checkpoints' => $committed,
        'evidence' => $evidence,
        'full_dr_active' => orange_cpr_peer_full_dr_active($env, $cprRoot),
        'c6_active' => orange_cpr_peer_c6_active($env, $cprRoot),
        'backup_runner_active' => orange_cpr_peer_backup_runner_active($env),
    ];
}

/**
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_gate_ev(array $ctx): array
{
    $e = $ctx['evidence'] ?? [];

    return is_array($e) ? $e : [];
}

/**
 * Evaluate one gate — PASS or FAIL only.
 *
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_gate_evaluate_one(string $gateId, array $ctx): array
{
    try {
        return match ($gateId) {
            'G01' => orange_cpr_gate_eval_g01($ctx),
            'G02' => orange_cpr_gate_eval_g02($ctx),
            'G03' => orange_cpr_gate_eval_g03($ctx),
            'G04' => orange_cpr_gate_eval_g04($ctx),
            'G05' => orange_cpr_gate_eval_g05($ctx),
            'G06' => orange_cpr_gate_eval_g06($ctx),
            'G07' => orange_cpr_gate_eval_g07($ctx),
            'G08' => orange_cpr_gate_eval_g08($ctx),
            'G09' => orange_cpr_gate_eval_g09($ctx),
            'G10' => orange_cpr_gate_eval_g10($ctx),
            'G11' => orange_cpr_gate_eval_g11($ctx),
            'G12' => orange_cpr_gate_eval_g12($ctx),
            'G13' => orange_cpr_gate_eval_g13($ctx),
            'G14' => orange_cpr_gate_eval_g14($ctx),
            'G15' => orange_cpr_gate_eval_g15($ctx),
            'G16' => orange_cpr_gate_eval_g16($ctx),
            'G17' => orange_cpr_gate_eval_g17($ctx),
            'G18' => orange_cpr_gate_eval_g18($ctx),
            'G19' => orange_cpr_gate_eval_g19($ctx),
            'G20' => orange_cpr_gate_eval_g20($ctx),
            'G21' => orange_cpr_gate_eval_g21($ctx),
            'G22' => orange_cpr_gate_eval_g22($ctx),
            'G23' => orange_cpr_gate_eval_g23($ctx),
            'G24' => orange_cpr_gate_eval_g24($ctx),
            'G25' => orange_cpr_gate_eval_g25($ctx),
            'G26' => orange_cpr_gate_eval_g26($ctx),
            'G27' => orange_cpr_gate_eval_g27($ctx),
            'G28' => orange_cpr_gate_eval_g28($ctx),
            'G29' => orange_cpr_gate_eval_g29($ctx),
            'G30' => orange_cpr_gate_eval_g30($ctx),
            'G-FA-RESOLVER' => orange_cpr_gate_eval_fa_resolver($ctx),
            'G-FA-STOCK' => orange_cpr_gate_eval_fa_stock($ctx),
            'G-FA-SCHEMA' => orange_cpr_gate_eval_fa_schema($ctx),
            default => orange_cpr_gate_fail($gateId, 'gate_eval_error'),
        };
    } catch (Throwable) {
        return orange_cpr_gate_fail($gateId, 'gate_eval_error');
    }
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g01(array $ctx): array
{
    $ev = orange_cpr_gate_ev($ctx);
    $enabled = array_key_exists('enablement', $ev)
        ? (bool) $ev['enablement']
        : orange_cpr_enablement_flag_read($ctx['env']);
    $pre = !empty($ev['od_enable_preconditions_complete']);
    if (!$enabled) {
        return orange_cpr_gate_fail('G01', 'gate_enablement_false', ['enablement']);
    }
    if (!$pre) {
        return orange_cpr_gate_fail('G01', 'gate_enablement_preconditions_incomplete', ['od_enable']);
    }

    return orange_cpr_gate_pass('G01', ['enablement', 'od_enable']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g02(array $ctx): array
{
    $wf = (string) ($ctx['job']['workflow'] ?? '');
    $ev = orange_cpr_gate_ev($ctx);
    if (($wf !== 'A' && $wf !== 'B') || !empty($ev['dual_waiver']) || !empty($ev['dual_super_admin_marker'])) {
        return orange_cpr_gate_fail('G02', 'gate_dual_model_invalid');
    }

    return orange_cpr_gate_pass('G02', ['job.workflow']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g03(array $ctx): array
{
    $ev = orange_cpr_gate_ev($ctx);
    if (empty($ev['pin_capability'])) {
        return orange_cpr_gate_fail('G03', 'gate_pin_mechanism_unavailable');
    }

    return orange_cpr_gate_pass('G03', ['pin_capability']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g04(array $ctx): array
{
    $cert = orange_cpr_gate_ev($ctx)['certification'] ?? null;
    if (!is_array($cert) || (string) ($cert['result'] ?? '') !== 'PASS') {
        return orange_cpr_gate_fail('G04', 'gate_cert_not_pass');
    }

    return orange_cpr_gate_pass('G04', ['certification']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g05(array $ctx): array
{
    if (!empty($ctx['full_dr_active'])) {
        return orange_cpr_gate_fail('G05', 'gate_full_dr_active');
    }
    if (!empty($ctx['c6_active'])) {
        return orange_cpr_gate_fail('G05', 'gate_c6_active');
    }
    if (!empty($ctx['backup_runner_active'])) {
        return orange_cpr_gate_fail('G05', 'gate_backup_runner_active');
    }

    return orange_cpr_gate_pass('G05', ['locks.peers']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g06(array $ctx): array
{
    $pf = orange_cpr_gate_ev($ctx)['host_preflight'] ?? null;
    if (!is_array($pf) || ($pf['pass'] ?? false) !== true) {
        return orange_cpr_gate_fail('G06', 'gate_host_preflight_fail');
    }

    return orange_cpr_gate_pass('G06', ['host_preflight']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g07(array $ctx): array
{
    $pkg = orange_cpr_gate_ev($ctx)['package'] ?? null;
    $contract = $ctx['contract'];
    $job = $ctx['job'];
    if (!is_array($pkg) || (string) ($pkg['status'] ?? '') !== 'finalized') {
        return orange_cpr_gate_fail('G07', 'gate_package_not_final');
    }
    $pid = (string) ($pkg['package_id'] ?? '');
    if ($pid === '' || $pid !== (string) ($job['package_id'] ?? '')) {
        return orange_cpr_gate_fail('G07', 'gate_package_not_final');
    }
    if (is_array($contract) && $pid !== (string) ($contract['package_id'] ?? '')) {
        return orange_cpr_gate_fail('G07', 'gate_package_not_final');
    }

    return orange_cpr_gate_pass('G07', ['package']);
}

/**
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>|null
 */
function orange_cpr_gate_report(array $ctx, string $key): ?array
{
    $r = orange_cpr_gate_ev($ctx)['reports'][$key] ?? null;

    return is_array($r) ? $r : null;
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g08(array $ctx): array
{
    $report = orange_cpr_gate_report($ctx, 'c4');
    $contract = $ctx['contract'];
    if ($report === null || !is_array($contract)) {
        return orange_cpr_gate_fail('G08', 'gate_evidence_missing');
    }
    if ((string) ($report['overall'] ?? '') !== 'PASS') {
        return orange_cpr_gate_fail('G08', 'gate_c4_not_pass');
    }
    $hash = orange_cpr_gate_report_hash($report);
    if ($hash !== (string) ($contract['c4_report_hash'] ?? '')) {
        return orange_cpr_gate_fail('G08', 'gate_c4_hash_drift');
    }

    return orange_cpr_gate_pass('G08', ['reports.c4', 'contract.c4_report_hash']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g09(array $ctx): array
{
    $report = orange_cpr_gate_report($ctx, 'c5');
    $contract = $ctx['contract'];
    if ($report === null || !is_array($contract)) {
        return orange_cpr_gate_fail('G09', 'gate_evidence_missing');
    }
    if (strtolower((string) ($report['overall_result'] ?? '')) !== 'pass') {
        return orange_cpr_gate_fail('G09', 'gate_c5_not_pass');
    }
    if ((float) ($report['recovery_score'] ?? 0) < 85) {
        return orange_cpr_gate_fail('G09', 'gate_c5_score_low');
    }
    if (orange_cpr_gate_report_hash($report) !== (string) ($contract['c5_report_hash'] ?? '')) {
        return orange_cpr_gate_fail('G09', 'gate_c5_hash_drift');
    }

    return orange_cpr_gate_pass('G09', ['reports.c5']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g10(array $ctx): array
{
    $ev = orange_cpr_gate_ev($ctx);
    $liveFp = (string) ($ev['package']['fingerprint'] ?? ($ev['live_package_fingerprint'] ?? ''));
    $jobFp = (string) ($ctx['job']['package_fingerprint'] ?? '');
    $contractFp = is_array($ctx['contract']) ? (string) ($ctx['contract']['package_fingerprint'] ?? '') : '';
    if ($liveFp === '' || $jobFp === '' || $liveFp !== $jobFp || ($contractFp !== '' && $liveFp !== $contractFp)) {
        return orange_cpr_gate_fail('G10', 'gate_package_fingerprint_drift');
    }

    return orange_cpr_gate_pass('G10', ['package.fingerprint']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g11(array $ctx): array
{
    $report = orange_cpr_gate_report($ctx, 'c6');
    $contract = $ctx['contract'];
    if ($report === null || !is_array($contract)) {
        return orange_cpr_gate_fail('G11', 'gate_evidence_missing');
    }
    $status = strtolower((string) ($report['status'] ?? ''));
    if (!in_array($status, ['ready', 'success', 'pass'], true)) {
        return orange_cpr_gate_fail('G11', 'gate_c6_not_ready');
    }
    if (($report['production_touched'] ?? true) !== false) {
        return orange_cpr_gate_fail('G11', 'gate_c6_production_touched');
    }
    if (orange_cpr_gate_report_hash($report) !== (string) ($contract['c6_report_hash'] ?? '')) {
        return orange_cpr_gate_fail('G11', 'gate_c6_hash_drift');
    }

    return orange_cpr_gate_pass('G11', ['reports.c6']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g12(array $ctx): array
{
    $report = orange_cpr_gate_report($ctx, 'c7');
    $contract = $ctx['contract'];
    if ($report === null || !is_array($contract)) {
        return orange_cpr_gate_fail('G12', 'gate_evidence_missing');
    }
    if ((string) ($report['overall_result'] ?? '') !== 'READY') {
        return orange_cpr_gate_fail('G12', 'gate_c7_not_ready');
    }
    if ((float) ($report['readiness_score'] ?? 0) < 90) {
        return orange_cpr_gate_fail('G12', 'gate_c7_score_low');
    }
    if (orange_cpr_gate_report_hash($report) !== (string) ($contract['c7_report_hash'] ?? '')) {
        return orange_cpr_gate_fail('G12', 'gate_c7_hash_drift');
    }

    return orange_cpr_gate_pass('G12', ['reports.c7']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g13(array $ctx): array
{
    $report = orange_cpr_gate_report($ctx, 'c7');
    if ($report === null || (string) ($report['survivor_country_integrity'] ?? '') !== 'PASS') {
        return orange_cpr_gate_fail('G13', 'gate_c7_survivor_fail');
    }

    return orange_cpr_gate_pass('G13', ['reports.c7.survivor']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g14(array $ctx): array
{
    $report = orange_cpr_gate_report($ctx, 'c7');
    if ($report === null || (string) ($report['global_state_integrity'] ?? '') !== 'PASS') {
        return orange_cpr_gate_fail('G14', 'gate_c7_global_fail');
    }

    return orange_cpr_gate_pass('G14', ['reports.c7.global']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g15(array $ctx): array
{
    $report = orange_cpr_gate_report($ctx, 'c7');
    if ($report === null) {
        return orange_cpr_gate_fail('G15', 'gate_evidence_missing');
    }
    $pillars = $report['pillars'] ?? null;
    if (!is_array($pillars)) {
        return orange_cpr_gate_fail('G15', 'gate_c7_pillar_unproven');
    }
    foreach (['accounting', 'stock_fifo', 'composite'] as $p) {
        if ((string) ($pillars[$p] ?? '') !== 'PASS') {
            return orange_cpr_gate_fail('G15', 'gate_c7_pillar_fail');
        }
    }

    return orange_cpr_gate_pass('G15', ['reports.c7.pillars']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g16(array $ctx): array
{
    $report = orange_cpr_gate_report($ctx, 'c8');
    $ev = orange_cpr_gate_ev($ctx);
    if ($report === null) {
        return orange_cpr_gate_fail('G16', 'gate_evidence_missing');
    }
    if (!empty($ev['c8_waiver']) || !empty($ev['waiver_attempted'])) {
        return orange_cpr_gate_fail('G16', 'gate_c8_waiver_forbidden');
    }
    $overall = (string) ($report['overall_result'] ?? '');
    if ($overall === 'WARNING') {
        return orange_cpr_gate_fail('G16', 'gate_c8_warning_rejected');
    }
    if ($overall !== 'SAFE') {
        return orange_cpr_gate_fail('G16', 'gate_c8_not_safe');
    }
    $contract = $ctx['contract'];
    if (is_array($contract) && orange_cpr_gate_report_hash($report) !== (string) ($contract['c8_report_hash'] ?? '')) {
        return orange_cpr_gate_fail('G16', 'gate_c8_not_safe'); // hash drift treated fail-closed under C8
    }

    return orange_cpr_gate_pass('G16', ['reports.c8']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g17(array $ctx): array
{
    $report = orange_cpr_gate_report($ctx, 'c8');
    if ($report === null) {
        return orange_cpr_gate_fail('G17', 'gate_evidence_missing');
    }
    if ((int) ($report['survivor_country_impact'] ?? -1) !== 0) {
        return orange_cpr_gate_fail('G17', 'gate_c8_survivor_impact');
    }
    if ((int) ($report['global_impact'] ?? -1) !== 0) {
        return orange_cpr_gate_fail('G17', 'gate_c8_global_impact');
    }
    if ((int) ($report['je_full_only_impact'] ?? ($report['je_impact'] ?? -1)) !== 0) {
        return orange_cpr_gate_fail('G17', 'gate_c8_je_impact');
    }

    return orange_cpr_gate_pass('G17', ['reports.c8.impacts']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g18(array $ctx): array
{
    $report = orange_cpr_gate_report($ctx, 'c8');
    if ($report === null) {
        return orange_cpr_gate_fail('G18', 'gate_evidence_missing');
    }
    if (($report['simulation_only'] ?? false) !== true) {
        return orange_cpr_gate_fail('G18', 'gate_c8_not_simulation');
    }
    if (($report['execution_performed'] ?? true) !== false) {
        return orange_cpr_gate_fail('G18', 'gate_c8_execution_performed');
    }

    return orange_cpr_gate_pass('G18', ['reports.c8.simulation']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g19(array $ctx): array
{
    $contract = $ctx['contract'];
    $live = orange_cpr_gate_ev($ctx)['live_sot'] ?? null;
    if (!is_array($contract) || !is_array($live)) {
        return orange_cpr_gate_fail('G19', 'gate_evidence_missing');
    }
    if ((int) ($live['schema_revision'] ?? -1) !== (int) ($contract['schema_revision_expected'] ?? -2)) {
        return orange_cpr_gate_fail('G19', 'gate_schema_revision_mismatch');
    }
    foreach (['boundary_policy_version', 'dependency_graph_version'] as $k) {
        if ((string) ($live[$k] ?? '') !== (string) ($contract[$k] ?? '')) {
            return orange_cpr_gate_fail('G19', 'gate_version_mismatch');
        }
    }
    if ((int) ($live['registry_revision'] ?? -1) !== (int) ($contract['registry_revision'] ?? -2)) {
        return orange_cpr_gate_fail('G19', 'gate_version_mismatch');
    }

    return orange_cpr_gate_pass('G19', ['live_sot', 'contract.versions']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g20(array $ctx): array
{
    $job = $ctx['job'];
    $contract = $ctx['contract'];
    if (!is_array($contract)) {
        return orange_cpr_gate_fail('G20', 'gate_job_identity_mismatch');
    }
    if ((string) ($job['package_id'] ?? '') !== (string) ($contract['package_id'] ?? '')
        || (int) ($job['country_id'] ?? 0) !== (int) ($contract['country_id'] ?? -1)
        || (string) ($job['country_code'] ?? '') !== (string) ($contract['country_code'] ?? '')
        || (string) ($contract['job_id'] ?? '') !== (string) ($job['job_id'] ?? '')
    ) {
        return orange_cpr_gate_fail('G20', 'gate_job_identity_mismatch');
    }

    return orange_cpr_gate_pass('G20', ['job', 'contract']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g21(array $ctx): array
{
    $inv = orange_cpr_gate_ev($ctx)['inventory'] ?? null;
    $contract = $ctx['contract'];
    if (!is_array($inv) || !is_array($contract)) {
        return orange_cpr_gate_fail('G21', 'gate_inv_missing');
    }
    if (empty($inv['id']) || empty($inv['hash'])) {
        return orange_cpr_gate_fail('G21', 'gate_inv_missing');
    }
    if (($inv['certified_read_only'] ?? false) !== true) {
        return orange_cpr_gate_fail('G21', 'gate_inv_not_certified');
    }
    if (!empty($inv['replaced_by_live'])) {
        return orange_cpr_gate_fail('G21', 'gate_inv_replaced_by_live');
    }
    if ((string) $inv['id'] !== (string) ($contract['inventory_snapshot_id'] ?? '')
        || (string) $inv['hash'] !== (string) ($contract['inventory_snapshot_hash'] ?? '')
    ) {
        return orange_cpr_gate_fail('G21', 'gate_inv_hash_drift');
    }

    return orange_cpr_gate_pass('G21', ['inventory', 'contract.inventory']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g22(array $ctx): array
{
    $maint = orange_cpr_gate_ev($ctx)['maint'] ?? null;
    if (!is_array($maint)) {
        return orange_cpr_gate_fail('G22', 'gate_maint_off');
    }
    if (($maint['global_maintenance_on'] ?? false) !== true) {
        return orange_cpr_gate_fail('G22', 'gate_maint_off');
    }
    if ((string) ($maint['scope'] ?? '') !== 'GLOBAL') {
        return orange_cpr_gate_fail('G22', 'gate_maint_not_global');
    }
    if (($maint['write_block_proven'] ?? false) !== true) {
        return orange_cpr_gate_fail('G22', 'gate_write_block_unproven');
    }
    if (!in_array('CP4', $ctx['committed_checkpoints'], true)) {
        return orange_cpr_gate_fail('G22', 'gate_evidence_missing');
    }

    return orange_cpr_gate_pass('G22', ['maint', 'CP4']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g23(array $ctx): array
{
    $committed = $ctx['committed_checkpoints'];
    if (!in_array('CP4', $committed, true) || !in_array('CP1', $committed, true)) {
        return orange_cpr_gate_fail('G23', 'gate_pin_order_violated');
    }
    // OD-PIN order: CP4 before CP1 in write order list
    $order = orange_cpr_checkpoint_write_order();
    $i4 = array_search('CP4', $order, true);
    $i1 = array_search('CP1', $order, true);
    if ($i4 === false || $i1 === false || $i4 > $i1) {
        return orange_cpr_gate_fail('G23', 'gate_pin_order_violated');
    }
    $cp1 = $ctx['checkpoints']['CP1']['payload'] ?? null;
    if (!is_array($cp1)) {
        return orange_cpr_gate_fail('G23', 'gate_evidence_missing');
    }
    if (($cp1['reused_existing_backup'] ?? true) !== false) {
        return orange_cpr_gate_fail('G23', 'gate_pin_reused_backup');
    }
    if (($cp1['verified'] ?? false) !== true) {
        return orange_cpr_gate_fail('G23', 'gate_pin_not_verified');
    }
    if (($cp1['pinned'] ?? false) !== true || ($cp1['created_under_maintenance'] ?? false) !== true) {
        return orange_cpr_gate_fail('G23', 'gate_pin_not_pinned');
    }
    $session = orange_cpr_gate_ev($ctx)['session_full_backup'] ?? null;
    if (!is_array($session) || empty($session['id']) || ($session['pinned'] ?? false) !== true) {
        return orange_cpr_gate_fail('G23', 'gate_pin_not_pinned');
    }

    return orange_cpr_gate_pass('G23', ['CP1', 'CP4', 'session_full_backup']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g24(array $ctx): array
{
    $auth = orange_cpr_gate_ev($ctx)['authority'] ?? null;
    $wf = (string) ($ctx['job']['workflow'] ?? '');
    if (!is_array($auth)) {
        return orange_cpr_gate_fail('G24', 'gate_authority_unsatisfied');
    }
    if (!empty($auth['country_admin_is_executor'])) {
        return orange_cpr_gate_fail('G24', 'gate_authority_unsatisfied');
    }
    if ($wf === 'B') {
        if (empty($auth['super_admin_approval_id']) || empty($auth['approval_fingerprint'])) {
            return orange_cpr_gate_fail('G24', 'gate_wfb_approval_missing');
        }
    } elseif ($wf === 'A') {
        if (empty($auth['wfa_protections_ack'])) {
            return orange_cpr_gate_fail('G24', 'gate_authority_unsatisfied');
        }
    } else {
        return orange_cpr_gate_fail('G24', 'gate_authority_unsatisfied');
    }

    return orange_cpr_gate_pass('G24', ['authority']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g25(array $ctx): array
{
    $contract = $ctx['contract'];
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_gate_fail('G25', 'gate_contract_not_frozen');
    }
    $ev = orange_cpr_gate_ev($ctx);
    $c8 = orange_cpr_gate_report($ctx, 'c8');
    if ($c8 === null || (string) ($c8['overall_result'] ?? '') !== 'SAFE') {
        return orange_cpr_gate_fail('G25', 'gate_contract_fingerprint_drift');
    }
    $liveFp = (string) ($ev['package']['fingerprint'] ?? '');
    if ($liveFp !== '' && $liveFp !== (string) ($contract['package_fingerprint'] ?? '')) {
        return orange_cpr_gate_fail('G25', 'gate_contract_fingerprint_drift');
    }
    $dbId = (string) ($ev['live_sot']['production_db_identity_hash'] ?? '');
    if ($dbId !== '' && $dbId !== (string) ($contract['production_db_identity_hash'] ?? '')) {
        return orange_cpr_gate_fail('G25', 'gate_contract_fingerprint_drift');
    }

    return orange_cpr_gate_pass('G25', ['contract', 'reread']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g26(array $ctx): array
{
    $lock = $ctx['lock'];
    $jobId = (string) ($ctx['job']['job_id'] ?? '');
    if (!is_array($lock)) {
        return orange_cpr_gate_fail('G26', 'gate_cpr_lock_not_held');
    }
    if ((string) ($lock['job_id'] ?? '') !== $jobId) {
        return orange_cpr_gate_fail('G26', 'gate_cpr_lock_wrong_job');
    }
    if (empty($lock['heartbeat_at'])) {
        return orange_cpr_gate_fail('G26', 'gate_cpr_lock_not_held');
    }
    if (!empty($lock['ponr_crossed'])) {
        return orange_cpr_gate_fail('G26', 'gate_cpr_lock_wrong_job');
    }

    return orange_cpr_gate_pass('G26', ['cpr_lock']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g27(array $ctx): array
{
    if (!in_array('runbook_pre_ponr', $ctx['committed_checkpoints'], true)) {
        return orange_cpr_gate_fail('G27', 'gate_runbook_incomplete');
    }
    $rb = $ctx['checkpoints']['runbook_pre_ponr']['payload'] ?? null;
    if (!is_array($rb) || (string) ($rb['c8_overall_result'] ?? '') !== 'SAFE'
        || ($rb['global_maintenance_active'] ?? false) !== true
    ) {
        return orange_cpr_gate_fail('G27', 'gate_runbook_incomplete');
    }

    return orange_cpr_gate_pass('G27', ['runbook_pre_ponr']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g28(array $ctx): array
{
    if (!in_array('CP5', $ctx['committed_checkpoints'], true)) {
        return orange_cpr_gate_fail('G28', 'gate_witness_missing');
    }
    $cp5 = $ctx['checkpoints']['CP5']['payload'] ?? null;
    $exp = orange_cpr_gate_ev($ctx)['witness_expectations'] ?? null;
    if (!is_array($cp5)) {
        return orange_cpr_gate_fail('G28', 'gate_witness_missing');
    }
    if (is_array($exp)) {
        foreach (['survivor_baseline_hash', 'global_baseline_hash', 'target_inventory_hash'] as $k) {
            if (isset($exp[$k]) && (string) ($cp5[$k] ?? '') !== (string) $exp[$k]) {
                return orange_cpr_gate_fail('G28', 'gate_witness_drift');
            }
        }
    }

    return orange_cpr_gate_pass('G28', ['CP5']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g29(array $ctx): array
{
    $ph = orange_cpr_gate_ev($ctx)['phrase'] ?? null;
    if (!is_array($ph)) {
        return orange_cpr_gate_fail('G29', 'gate_phrase_failed');
    }
    if (empty($ph['reauth_ok'])) {
        return orange_cpr_gate_fail('G29', 'gate_reauth_failed');
    }
    if (($ph['phrase'] ?? '') !== 'RESTORE' && empty($ph['phrase_ok'])) {
        return orange_cpr_gate_fail('G29', 'gate_phrase_failed');
    }
    if (empty($ph['one_time_authorization_id']) || !empty($ph['ota_consumed'])) {
        return orange_cpr_gate_fail('G29', 'gate_ota_missing');
    }

    return orange_cpr_gate_pass('G29', ['phrase']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_g30(array $ctx): array
{
    $ev = orange_cpr_gate_ev($ctx);
    if (!empty($ev['emergency_stop']) || !empty($ev['cpr_emergency_stop'])) {
        return orange_cpr_gate_fail('G30', 'gate_emergency_stop_set');
    }

    return orange_cpr_gate_pass('G30', ['emergency_stop']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_fa_resolver(array $ctx): array
{
    $fa = orange_cpr_gate_ev($ctx)['fa_resolver'] ?? null;
    if (!is_array($fa) || empty($fa['proven'])) {
        return orange_cpr_gate_fail('G-FA-RESOLVER', 'gate_fa_resolver_unproven');
    }
    if (!empty($fa['shortcut'])) {
        return orange_cpr_gate_fail('G-FA-RESOLVER', 'gate_fa_resolver_shortcut');
    }

    return orange_cpr_gate_pass('G-FA-RESOLVER', ['fa_resolver']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_fa_stock(array $ctx): array
{
    $fa = orange_cpr_gate_ev($ctx)['fa_stock'] ?? null;
    if (!is_array($fa) || empty($fa['armed'])) {
        return orange_cpr_gate_fail('G-FA-STOCK', 'gate_fa_stock_unarmed');
    }
    if (!empty($fa['soft_mode'])) {
        return orange_cpr_gate_fail('G-FA-STOCK', 'gate_fa_stock_soft_mode');
    }

    return orange_cpr_gate_pass('G-FA-STOCK', ['fa_stock']);
}

/** @param array<string, mixed> $ctx */
function orange_cpr_gate_eval_fa_schema(array $ctx): array
{
    $fa = orange_cpr_gate_ev($ctx)['fa_schema'] ?? null;
    $contract = $ctx['contract'];
    if (!is_array($fa) || !is_array($contract)) {
        return orange_cpr_gate_fail('G-FA-SCHEMA', 'gate_evidence_missing');
    }
    if (!empty($fa['soft_skip'])) {
        return orange_cpr_gate_fail('G-FA-SCHEMA', 'gate_fa_schema_soft_skip');
    }
    if (!empty($fa['cert_invalidated'])) {
        return orange_cpr_gate_fail('G-FA-SCHEMA', 'gate_schema_cert_invalidated');
    }
    if ((int) ($fa['live_revision'] ?? -1) !== (int) ($contract['schema_revision_expected'] ?? -2)) {
        return orange_cpr_gate_fail('G-FA-SCHEMA', 'gate_fa_schema_mismatch');
    }

    return orange_cpr_gate_pass('G-FA-SCHEMA', ['fa_schema']);
}

/**
 * Run suite, persist sealed report, return evaluation.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $evidence
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_cpr_gate_evaluate(
    array $env,
    string $jobId,
    string $profile = 'pre_ponr_full',
    array $evidence = [],
    array $options = []
): array {
    if (!empty($options['force_pass']) || !empty($options['bypass']) || !empty($options['super_admin_bypass'])
        || !empty($evidence['force_pass']) || !empty($evidence['bypass']) || !empty($evidence['skip_gates'])
    ) {
        return [
            'ok' => false,
            'code' => 'gate_bypass_forbidden',
            'message' => 'Super Admin bypass / skip / force-PASS is forbidden (P1-08 H4).',
            'all_gates_pass' => false,
            'waiver_attempted' => true,
            'profile' => $profile,
            'job_id' => $jobId,
        ];
    }

    $ctx = orange_cpr_gate_build_context($env, $jobId, $evidence);
    $gateIds = orange_cpr_gate_ids_for_profile($profile);
    $gates = [];
    foreach ($gateIds as $gid) {
        $gates[] = orange_cpr_gate_evaluate_one($gid, $ctx);
    }

    $allPass = true;
    foreach ($gates as $g) {
        if (($g['result'] ?? '') !== ORANGE_CPR_GATE_PASS) {
            $allPass = false;
            break;
        }
    }

    $c8 = orange_cpr_gate_report($ctx, 'c8');
    $inv = $evidence['inventory'] ?? [];
    $session = $evidence['session_full_backup'] ?? [];
    $now = gmdate('c');

    $report = [
        'schema_version' => ORANGE_CPR_GATE_EVAL_SCHEMA,
        'job_id' => $jobId,
        'profile' => $profile,
        'evaluated_at' => $now,
        'all_gates_pass' => $allPass,
        'gates' => $gates,
        'c8_overall_result_observed' => is_array($c8) ? (string) ($c8['overall_result'] ?? '') : '',
        'inventory_snapshot_id' => (string) ($inv['id'] ?? (is_array($ctx['contract']) ? ($ctx['contract']['inventory_snapshot_id'] ?? '') : '')),
        'session_full_backup_pinned' => !empty($session['pinned']),
        'full_dr_active' => !empty($ctx['full_dr_active']),
        'c6_active' => !empty($ctx['c6_active']),
        'waiver_attempted' => !empty($evidence['waiver_attempted']),
        'evaluator_version' => ORANGE_CPR_GATE_EVALUATOR_VERSION,
        'package_fingerprint' => (string) ($ctx['job']['package_fingerprint'] ?? ''),
        'job_state' => (string) ($ctx['job']['state'] ?? ''),
        'last_checkpoint_id' => $ctx['job']['last_checkpoint_id'] ?? null,
        'ponr_crossed_observed' => !empty($ctx['job']['ponr_crossed']),
        'enablement_flag_observed' => array_key_exists('enablement', $evidence)
            ? (bool) $evidence['enablement']
            : orange_cpr_enablement_flag_read($env),
        'scaffold_record_only' => true,
    ];
    $report['ponr_authorized'] = orange_cpr_ponr_authorization_allowed($report);

    // Seal after final fields
    $copy = $report;
    unset($copy['content_sha256']);
    ksort($copy);
    $report['content_sha256'] = hash(
        'sha256',
        (string) json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $path = orange_cpr_gate_persist_report($ctx['cpr_root'], $jobId, $report);

    // Update job last_gate_eval_ref
    $job = $ctx['job'];
    $job['last_gate_eval_ref'] = basename($path);
    $job['updated_at'] = $now;
    orange_cpr_job_write($ctx['cpr_root'], $jobId, $job);

    orange_cpr_audit_append($ctx['cpr_root'], $jobId, [
        'event_type' => 'cpr.gate_evaluation',
        'job_id' => $jobId,
        'profile' => $profile,
        'all_gates_pass' => $allPass,
        'ponr_authorized' => $report['ponr_authorized'],
        'report_path' => $path,
        'content_sha256' => $report['content_sha256'],
        'enablement_flag_observed' => $report['enablement_flag_observed'],
    ]);

    return [
        'ok' => true,
        'code' => $allPass ? 'ok' : 'gate_suite_failed',
        'message' => $allPass ? 'All gates PASS.' : 'Gate suite FAIL (fail-closed).',
        'evaluation' => $report,
        'path' => $path,
        'all_gates_pass' => $allPass,
        'ponr_authorized' => $report['ponr_authorized'],
    ];
}

/**
 * PONR authorization forbidden unless pre_ponr_full all PASS (P1-08 H10).
 *
 * @param array<string, mixed> $evaluation
 */
function orange_cpr_ponr_authorization_allowed(array $evaluation): bool
{
    if (($evaluation['profile'] ?? '') !== 'pre_ponr_full') {
        return false;
    }
    if (empty($evaluation['all_gates_pass'])) {
        return false;
    }
    if (!empty($evaluation['waiver_attempted'])) {
        return false;
    }
    if ((string) ($evaluation['c8_overall_result_observed'] ?? '') !== 'SAFE') {
        return false;
    }
    if (!empty($evaluation['full_dr_active']) || !empty($evaluation['c6_active'])) {
        return false;
    }

    return true;
}

/**
 * @param array<string, mixed> $report
 */
function orange_cpr_gate_persist_report(string $cprRoot, string $jobId, array $report): string
{
    $dir = orange_cpr_gates_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create gates directory.');
    }
    $profile = preg_replace('/[^a-z0-9_]/i', '', (string) ($report['profile'] ?? 'eval')) ?: 'eval';
    $stamp = gmdate('Ymd\THis');
    $final = $dir . DIRECTORY_SEPARATOR . 'cpr_gate_evaluation_' . $profile . '_' . $stamp . '.json';
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Gate evaluation tmp write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    // Also write latest pointer via atomic replace
    $latest = $dir . DIRECTORY_SEPARATOR . 'cpr_gate_evaluation_latest_' . $profile . '.json';
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
