<?php

declare(strict_types=1);

/**
 * CPR Live PONR Target-Slice DELETE Engine (WP-P5-02).
 *
 * Enters PONR via State Engine T09, executes deterministic target-slice DELETE
 * against the job-bound sealed slice ledger (enablement FALSE → no production SQL),
 * seals mutation manifest + DELETE report, writes CP6. Does not start IMPORT/uploads.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P5_02_TARGET_SLICE_DELETE.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md §6 §10.2 §10.3 §18
 */

require_once __DIR__ . '/cpr_witnesses_live.php';
require_once __DIR__ . '/cpr_authority_live.php';
require_once __DIR__ . '/cpr_gates_live.php';
require_once __DIR__ . '/cpr_lock_live.php';
require_once __DIR__ . '/cpr_od_pin_live.php';
require_once __DIR__ . '/cpr_maintenance_live.php';
require_once __DIR__ . '/cpr_state_engine.php';
require_once __DIR__ . '/cpr_checkpoint_engine.php';
require_once __DIR__ . '/cpr_mutation_engine.php';

const ORANGE_CPR_DELETE_LIVE_SCHEMA = 'cpr_delete_live/1';
const ORANGE_CPR_DELETE_LIVE_VERSION = 'P5-02-1.0';
const ORANGE_CPR_DELETE_ORDER_VERSION = 'c1.1-delete_order/1';

const ORANGE_CPR_DELETELIVE_ERR_ENABLEMENT = 'deletelive_enablement_forbidden';
const ORANGE_CPR_DELETELIVE_ERR_ACTOR = 'deletelive_actor_not_super_admin';
const ORANGE_CPR_DELETELIVE_ERR_STATE = 'deletelive_state_invalid';
const ORANGE_CPR_DELETELIVE_ERR_CONTRACT = 'deletelive_contract_mismatch';
const ORANGE_CPR_DELETELIVE_ERR_IDENTITY = 'deletelive_identity_drift';
const ORANGE_CPR_DELETELIVE_ERR_LOCK = 'deletelive_lock_loss';
const ORANGE_CPR_DELETELIVE_ERR_GATE = 'deletelive_gate_failure';
const ORANGE_CPR_DELETELIVE_ERR_AUTHORITY = 'deletelive_authority_failure';
const ORANGE_CPR_DELETELIVE_ERR_CPA = 'deletelive_cpa_required';
const ORANGE_CPR_DELETELIVE_ERR_SCOPE = 'deletelive_scope_expansion';
const ORANGE_CPR_DELETELIVE_ERR_SLICE = 'deletelive_slice_invalid';
const ORANGE_CPR_DELETELIVE_ERR_REPLAY = 'deletelive_replay_forbidden';
const ORANGE_CPR_DELETELIVE_ERR_BYPASS = 'deletelive_bypass_forbidden';
const ORANGE_CPR_DELETELIVE_ERR_IMPORT = 'deletelive_import_disabled';
const ORANGE_CPR_DELETELIVE_ERR_UPLOADS = 'deletelive_uploads_disabled';
const ORANGE_CPR_DELETELIVE_ERR_TRANSITION = 'deletelive_transition_failed';
const ORANGE_CPR_DELETELIVE_ERR_CHECKPOINT = 'deletelive_checkpoint_failed';
const ORANGE_CPR_DELETELIVE_ERR_PERSIST = 'deletelive_persist_failed';
const ORANGE_CPR_DELETELIVE_ERR_ORDER = 'deletelive_order_invalid';

/**
 * Canonical reverse-dependency delete order (Architecture §10.2 A — registry delete_order).
 *
 * @return list<string>
 */
function orange_cpr_delete_live_canonical_order(): array
{
    return [
        'order_items',
        'orders',
        'product_channels',
        'products',
        'storefront_accounts',
    ];
}

/**
 * @return list<string>
 */
function orange_cpr_delete_live_allowed_tables(): array
{
    return orange_cpr_delete_live_canonical_order();
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_deletelive_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'import_executed' => false,
        'uploads_executed' => false,
        'enablement_flag_observed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_deletelive_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'fail_closed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'import_executed' => false,
        'uploads_executed' => false,
        'enablement_flag_observed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_delete_live_persist(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_delete_live_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR delete_live directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Delete live record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Delete live record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_delete_live_latest_path(string $cprRoot, string $jobId, string $kind): string
{
    return orange_cpr_delete_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_delete_live_' . $kind . '_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_delete_live_load_latest(string $cprRoot, string $jobId, string $kind): ?array
{
    $path = orange_cpr_delete_live_latest_path($cprRoot, $jobId, $kind);
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
function orange_cpr_delete_live_refuse_unsafe(array $request): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_lock', 'skip_gate',
        'skip_authority', 'expand_scope', 'all_countries', 'full_schema_wipe',
        'execute_import', 'continue_to_import', 'execute_uploads', 'continue_to_uploads',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_BYPASS, 'Unsafe DELETE knob forbidden: ' . $k);
        }
    }
    if (!empty($request['execute_import']) || !empty($request['continue_to_import'])) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_IMPORT, 'IMPORT engine disabled until WP-P5-03.');
    }
    if (!empty($request['execute_uploads']) || !empty($request['continue_to_uploads'])) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_UPLOADS, 'Country Uploads disabled until WP-P5-05.');
    }

    return orange_cpr_deletelive_ok(['message' => 'unsafe knobs absent']);
}

/**
 * Normalize and validate target slice against contract (fail-closed on scope expansion).
 *
 * @param array<string, mixed> $slice
 * @param array<string, mixed> $job
 * @param array<string, mixed> $contract
 * @return array<string, mixed>
 */
function orange_cpr_delete_live_validate_slice(array $slice, array $job, array $contract): array
{
    $countryId = (int) ($slice['country_id'] ?? 0);
    $countryCode = strtoupper(trim((string) ($slice['country_code'] ?? '')));
    if ($countryId <= 0 || $countryCode === '') {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_SLICE, 'Target slice country identity required.');
    }
    $jobCode = strtoupper((string) ($job['country_code'] ?? ''));
    $contractCode = strtoupper((string) ($contract['country_code'] ?? $jobCode));
    if ($countryId !== (int) ($job['country_id'] ?? 0)
        || $countryId !== (int) ($contract['country_id'] ?? 0)
        || $countryCode !== $jobCode
        || ($contractCode !== '' && $countryCode !== $contractCode)
    ) {
        return orange_cpr_deletelive_fail(
            ORANGE_CPR_DELETELIVE_ERR_SCOPE,
            'Target slice country does not match execution contract (scope expansion refused).'
        );
    }

    $orderVer = (string) ($slice['delete_order_version'] ?? '');
    if ($orderVer !== '' && $orderVer !== ORANGE_CPR_DELETE_ORDER_VERSION) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_ORDER, 'Unsupported delete_order_version.');
    }

    $tablesIn = is_array($slice['tables'] ?? null) ? $slice['tables'] : null;
    if ($tablesIn === null) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_SLICE, 'Target slice tables array required.');
    }

    $allowed = orange_cpr_delete_live_allowed_tables();
    $allowedMap = array_fill_keys($allowed, true);
    $canonical = orange_cpr_delete_live_canonical_order();
    $canonIndex = array_flip($canonical);
    $normalized = [];

    foreach ($tablesIn as $idx => $row) {
        if (!is_array($row)) {
            return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_SLICE, 'Invalid slice table entry at ' . (string) $idx);
        }
        $table = (string) ($row['table'] ?? '');
        $mKey = (string) ($row['membership_key'] ?? '');
        $rowIds = $row['row_ids'] ?? [];
        if ($table === '' || !isset($allowedMap[$table])) {
            return orange_cpr_deletelive_fail(
                ORANGE_CPR_DELETELIVE_ERR_SCOPE,
                'Table outside approved target-slice allowlist: ' . $table
            );
        }
        if ($mKey !== 'country_id') {
            return orange_cpr_deletelive_fail(
                ORANGE_CPR_DELETELIVE_ERR_SCOPE,
                'Membership key must be country_id for target-slice DELETE.'
            );
        }
        if (!is_array($rowIds)) {
            return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_SLICE, 'row_ids must be an array for ' . $table);
        }
        $cleanIds = [];
        foreach ($rowIds as $rid) {
            $s = trim((string) $rid);
            if ($s === '') {
                return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_SLICE, 'Empty row id in ' . $table);
            }
            $cleanIds[] = $s;
        }
        // Reject explicit foreign-country markers if supplied.
        if (isset($row['country_id']) && (int) $row['country_id'] !== $countryId) {
            return orange_cpr_deletelive_fail(
                ORANGE_CPR_DELETELIVE_ERR_SCOPE,
                'Per-table country_id expansion refused for ' . $table
            );
        }
        $normalized[] = [
            'table' => $table,
            'membership_key' => 'country_id',
            'country_id' => $countryId,
            'row_ids' => $cleanIds,
            'order_index' => (int) ($canonIndex[$table] ?? 999),
        ];
    }

    // Deterministic execution order: canonical reverse-dependency order.
    usort($normalized, static function (array $a, array $b): int {
        return $a['order_index'] <=> $b['order_index'];
    });

    return orange_cpr_deletelive_ok([
        'slice' => [
            'country_id' => $countryId,
            'country_code' => $countryCode,
            'delete_order_version' => ORANGE_CPR_DELETE_ORDER_VERSION,
            'tables' => $normalized,
            'empty' => $normalized === [] || array_sum(array_map(
                static fn (array $t): int => count($t['row_ids']),
                $normalized
            )) === 0,
        ],
    ]);
}

/**
 * Apply DELETE to sealed slice ledger (no production SQL under enablement FALSE).
 *
 * @param array<string, mixed> $slice
 * @return array<string, mixed>
 */
function orange_cpr_delete_live_apply_ledger(string $cprRoot, string $jobId, array $slice): array
{
    $dir = orange_cpr_delete_live_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create delete_live directory for ledger.');
    }
    $ledgerPath = $dir . DIRECTORY_SEPARATOR . 'target_slice_ledger.json';
    $before = [];
    $deleted = [];
    $tablesCompleted = [];
    $rowsDeleted = 0;

    foreach ($slice['tables'] as $t) {
        if (!is_array($t)) {
            continue;
        }
        $table = (string) ($t['table'] ?? '');
        $ids = is_array($t['row_ids'] ?? null) ? $t['row_ids'] : [];
        $before[$table] = $ids;
        $deleted[$table] = $ids;
        $tablesCompleted[] = $table;
        $rowsDeleted += count($ids);
    }

    $ledger = [
        'schema_version' => 'cpr_target_slice_ledger/1',
        'job_id' => $jobId,
        'country_id' => (int) ($slice['country_id'] ?? 0),
        'country_code' => (string) ($slice['country_code'] ?? ''),
        'delete_order_version' => ORANGE_CPR_DELETE_ORDER_VERSION,
        'before' => $before,
        'deleted' => $deleted,
        'remaining' => array_fill_keys(array_keys($before), []),
        'production_sql_executed' => false,
        'updated_at' => gmdate('c'),
    ];
    $json = json_encode($ledger, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($ledgerPath, $json) === false) {
        throw new RuntimeException('Slice ledger write failed.');
    }

    return [
        'ledger_path' => $ledgerPath,
        'tables_completed' => $tablesCompleted,
        'rows_deleted' => $rowsDeleted,
        'deleted' => $deleted,
        'production_sql_executed' => false,
    ];
}

/**
 * Lock ownership for DELETE (allows pre-PONR entry and post-T09 hold; never auto-unlock).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @param array<string, mixed> $job
 * @param array<string, mixed> $contract
 * @return array<string, mixed>
 */
function orange_cpr_delete_live_assert_lock_ownership(
    array $env,
    string $jobId,
    array $request,
    array $job,
    array $contract
): array {
    $cprRoot = orange_cpr_resolve_work_root($env);
    $lock = orange_cpr_lock_read($cprRoot);
    if (!is_array($lock)) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_LOCK, 'CPR lock missing.');
    }
    if ((string) ($lock['job_id'] ?? '') !== $jobId) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_LOCK, 'Lock job_id ownership drift.');
    }
    if ((int) ($lock['country_id'] ?? -1) !== (int) ($job['country_id'] ?? -2)) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_LOCK, 'Lock country ownership drift.');
    }
    if ((string) ($lock['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_LOCK, 'Lock package fingerprint drift.');
    }
    $lease = (string) ($request['lease_token'] ?? '');
    $heldLease = (string) ($lock['ownership']['lease_token'] ?? '');
    if ($lease === '' || $heldLease === '' || !hash_equals($heldLease, $lease)) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_LOCK, 'Lease token ownership drift.');
    }
    $workerId = (string) ($request['worker_id'] ?? '');
    $heldWorker = (string) ($lock['ownership']['worker_id'] ?? '');
    if ($workerId === '' || $heldWorker === '' || $heldWorker !== $workerId) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_LOCK, 'Worker id ownership drift.');
    }
    // Contract revision may advance after lock acquire (pre_ponr amend); bind identity not stale revision.
    if ((int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_LOCK, 'Contract/job country drift under lock.');
    }

    return orange_cpr_deletelive_ok(['lock' => $lock]);
}

/**
 * Preconditions for PONR DELETE entry.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_delete_live_assert_preconditions(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_deletelive_fail(
            ORANGE_CPR_DELETELIVE_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during P5 DELETE (Architecture roadmap).'
        );
    }
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_ACTOR, 'Super Admin required for PONR DELETE.');
    }
    $unsafe = orange_cpr_delete_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_STATE, $e->getMessage());
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_CONTRACT, 'Frozen execution contract required.');
    }
    if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_IDENTITY, 'Contract/job identity drift.');
    }
    if ((string) ($contract['contract_phase'] ?? '') !== 'pre_ponr'
        && empty($job['ponr_crossed'])
    ) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_CONTRACT, 'Contract phase must be pre_ponr before PONR DELETE.');
    }

    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP-A')) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_CPA, 'CP-A last reversible checkpoint required (P4 baseline).');
    }
    $cpaLive = orange_cpr_witnesses_live_load_cpa($cprRoot, $jobId);
    if (!is_array($cpaLive) || empty($cpaLive['cpa_committed']) || !orange_cpr_auth_verify_seal($cpaLive)) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_CPA, 'Sealed CP-A live record required.');
    }

    $gates = orange_cpr_gates_live_load_latest($cprRoot, $jobId);
    if (!is_array($gates) || empty($gates['all_gates_pass']) || !orange_cpr_auth_verify_seal($gates)) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_GATE, 'Sealed gates_live PASS required.');
    }
    $auth = orange_cpr_auth_live_load_latest($cprRoot, $jobId);
    if (!is_array($auth) || empty($auth['ponr_authorized']) || !orange_cpr_auth_verify_seal($auth)) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_AUTHORITY, 'Sealed authority_live required.');
    }
    $rb = orange_cpr_runbook_live_load_latest($cprRoot, $jobId);
    if (!is_array($rb) || empty($rb['runbook_completed']) || !orange_cpr_auth_verify_seal($rb)) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_AUTHORITY, 'Sealed runbook_live required.');
    }

    $lockRv = orange_cpr_delete_live_assert_lock_ownership($env, $jobId, $request, $job, $contract);
    if (empty($lockRv['ok'])) {
        return $lockRv;
    }

    $state = (string) ($job['state'] ?? '');
    if (!in_array($state, ['cpr_pre_ponr', 'cpr_deleting'], true)) {
        return orange_cpr_deletelive_fail(
            ORANGE_CPR_DELETELIVE_ERR_STATE,
            'PONR DELETE requires cpr_pre_ponr (entry) or cpr_deleting (idempotent).',
            ['job_state' => $state]
        );
    }

    $sliceIn = is_array($request['target_slice'] ?? null) ? $request['target_slice'] : [];
    $sliceCheck = orange_cpr_delete_live_validate_slice($sliceIn, $job, $contract);
    if (empty($sliceCheck['ok'])) {
        return $sliceCheck;
    }

    return orange_cpr_deletelive_ok([
        'actor_admin_id' => (int) $request['actor_admin_id'],
        'job' => $job,
        'contract' => $contract,
        'cpr_root' => $cprRoot,
        'slice' => $sliceCheck['slice'],
        'gates' => $gates,
        'authority' => $auth,
        'runbook' => $rb,
        'cpa_live' => $cpaLive,
        'job_state' => $state,
    ]);
}

/**
 * Execute live PONR target-slice DELETE through CP6 (IMPORT/uploads remain disabled).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_delete_live_run(array $env, string $jobId, array $request = []): array
{
    $pre = orange_cpr_delete_live_assert_preconditions($env, $jobId, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }

    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    /** @var array<string, mixed> $slice */
    $slice = $pre['slice'];
    $cprRoot = (string) $pre['cpr_root'];
    $actorAdminId = (int) $pre['actor_admin_id'];
    $state = (string) $pre['job_state'];

    // Idempotent success: sealed report + CP6 already present.
    $existingReport = orange_cpr_delete_live_load_latest($cprRoot, $jobId, 'report');
    if (is_array($existingReport)
        && !empty($existingReport['delete_complete'])
        && orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP6')
    ) {
        return orange_cpr_deletelive_ok([
            'message' => 'Target-slice DELETE already complete (idempotent).',
            'job_id' => $jobId,
            'idempotent' => true,
            'delete_report' => $existingReport,
            'mutation_manifest' => orange_cpr_delete_live_load_latest($cprRoot, $jobId, 'manifest'),
            'ponr_crossed' => true,
            'job_state' => (string) (orange_cpr_job_read($cprRoot, $jobId)['state'] ?? ''),
        ]);
    }
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP6') && !is_array($existingReport)) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_REPLAY, 'CP6 exists without sealed delete report (replay refused).');
    }
    if (is_array($existingReport) && empty($existingReport['delete_complete'])) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_REPLAY, 'Incomplete delete report present; replay refused.');
    }

    $ponrEnteredAt = gmdate('c');
    if ($state === 'cpr_pre_ponr') {
        $tr = orange_cpr_transition_apply($env, $jobId, 'cpr_deleting', [
            'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
            'actor_admin_id' => $actorAdminId,
            'phrase_ok' => true,
            'reauth_ok' => true,
            'runbook_completed' => true,
            'c8_overall_result' => 'SAFE',
            'trigger' => 'authorize_ponr_start_delete',
        ]);
        if (empty($tr['ok'])) {
            return orange_cpr_deletelive_fail(
                ORANGE_CPR_DELETELIVE_ERR_TRANSITION,
                (string) ($tr['message'] ?? 'T09 authorize_ponr_start_delete failed.'),
                ['upstream' => $tr]
            );
        }
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } elseif ($state === 'cpr_deleting' && empty($job['ponr_crossed'])) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_STATE, 'cpr_deleting without ponr_crossed is invalid.');
    }

    // Re-validate lock immediately before mutation apply (post-T09 safe).
    $jobNow = orange_cpr_job_read($cprRoot, $jobId);
    $contractNow = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contractNow)) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_CONTRACT, 'Contract missing before DELETE apply.');
    }
    $lockRv = orange_cpr_delete_live_assert_lock_ownership($env, $jobId, $request, $jobNow, $contractNow);
    if (empty($lockRv['ok'])) {
        $pause = orange_cpr_transition_apply($env, $jobId, 'cpr_paused_delete_failed', [
            'actor' => ORANGE_CPR_ACTOR_SYSTEM,
            'trigger' => 'delete_fail',
        ]);

        return orange_cpr_deletelive_fail(
            ORANGE_CPR_DELETELIVE_ERR_LOCK,
            'Lock loss during DELETE (OD-FAIL-DELETE pause).',
            ['upstream' => $lockRv, 'pause' => $pause]
        );
    }

    try {
        $apply = orange_cpr_delete_live_apply_ledger($cprRoot, $jobId, $slice);
    } catch (Throwable $e) {
        orange_cpr_transition_apply($env, $jobId, 'cpr_paused_delete_failed', [
            'actor' => ORANGE_CPR_ACTOR_SYSTEM,
            'trigger' => 'delete_fail',
        ]);

        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_PERSIST, $e->getMessage());
    }

    $now = gmdate('c');
    $reportId = orange_cpr_generate_job_id();
    $manifestId = orange_cpr_generate_job_id();

    $manifest = [
        'schema_version' => ORANGE_CPR_DELETE_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_DELETE_LIVE_VERSION,
        'record_type' => 'cpr_delete_mutation_manifest',
        'manifest_id' => $manifestId,
        'job_id' => $jobId,
        'package_id' => (string) ($job['package_id'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'contract_revision' => (int) ($contract['contract_revision'] ?? 0),
        'delete_order_version' => ORANGE_CPR_DELETE_ORDER_VERSION,
        'canonical_order' => orange_cpr_delete_live_canonical_order(),
        'tables_completed' => $apply['tables_completed'],
        'deleted' => $apply['deleted'],
        'rows_deleted' => (int) $apply['rows_deleted'],
        'empty_target_slice' => !empty($slice['empty']),
        'production_sql_executed' => false,
        'production_mutation' => false,
        'import_disabled' => true,
        'uploads_disabled' => true,
        'created_at' => $now,
    ];

    $report = [
        'schema_version' => ORANGE_CPR_DELETE_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_DELETE_LIVE_VERSION,
        'record_type' => 'cpr_delete_execution_report',
        'delete_report_id' => $reportId,
        'manifest_id' => $manifestId,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'delete_complete' => true,
        'ponr_entered_at' => $ponrEnteredAt,
        'ponr_crossed' => true,
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'contract_phase_observed' => (string) ($contract['contract_phase'] ?? ''),
        'session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
        'one_time_authorization_id' => (string) ($contract['one_time_authorization_id'] ?? ($pre['authority']['one_time_authorization_id'] ?? '')),
        'tables_completed' => $apply['tables_completed'],
        'delete_order_version' => ORANGE_CPR_DELETE_ORDER_VERSION,
        'rows_deleted' => (int) $apply['rows_deleted'],
        'empty_target_slice' => !empty($slice['empty']),
        'ledger_path' => (string) $apply['ledger_path'],
        'recovery_metadata' => [
            'od_fail_delete' => 'pause_for_super_admin',
            'auto_rollback' => false,
            'session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
            'completed_phase' => 'target_slice_delete',
            'execution_status' => 'delete_complete',
            'maint_remains_on' => true,
            'import_not_started' => true,
            'uploads_not_started' => true,
            'ponr_crossed' => true,
            'production_sql_executed' => false,
        ],
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'ops_enablement_flag' => false,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'import_executed' => false,
        'uploads_executed' => false,
        'enablement_flag_observed' => false,
        'created_at' => $now,
    ];

    try {
        $manifestPath = orange_cpr_delete_live_persist($cprRoot, $jobId, 'cpr_delete_manifest_' . $manifestId, $manifest);
        $reportPath = orange_cpr_delete_live_persist($cprRoot, $jobId, 'cpr_delete_report_' . $reportId, $report);
        foreach (['manifest' => array_merge($manifest, ['manifest_path' => $manifestPath]),
            'report' => array_merge($report, ['delete_report_path' => $reportPath])] as $kind => $rec) {
            $latest = orange_cpr_delete_live_latest_path($cprRoot, $jobId, $kind);
            $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
            $sealed = orange_cpr_auth_seal($rec);
            $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false || @file_put_contents($tmp, $json) === false) {
                throw new RuntimeException('Delete live latest pointer write failed: ' . $kind);
            }
            orange_cpr_atomic_rename_replace($tmp, $latest);
        }
    } catch (RuntimeException $e) {
        return orange_cpr_deletelive_fail(ORANGE_CPR_DELETELIVE_ERR_PERSIST, $e->getMessage());
    }

    $cp6 = orange_cpr_checkpoint_create($env, $jobId, 'CP6', [
        'tables_completed' => $apply['tables_completed'],
        'delete_order_version' => ORANGE_CPR_DELETE_ORDER_VERSION,
        'ponr_entered_at' => $ponrEnteredAt,
    ], ['written_by' => 'system']);
    if (empty($cp6['ok'])) {
        return orange_cpr_deletelive_fail(
            ORANGE_CPR_DELETELIVE_ERR_CHECKPOINT,
            (string) ($cp6['message'] ?? 'CP6 write failed.'),
            ['upstream' => $cp6]
        );
    }

    // Mark delete engine executed on job; production SQL remains false (OD-ENABLE).
    $jobAfter = orange_cpr_job_read($cprRoot, $jobId);
    $engines = is_array($jobAfter['mutation_engines'] ?? null) ? $jobAfter['mutation_engines'] : [];
    $engines['delete'] = true;
    $engines['import'] = false;
    $engines['uploads'] = false;
    $engines['ponr'] = true;
    $jobAfter['mutation_engines'] = $engines;
    $jobAfter['ponr_mutation_executed'] = false; // no production SQL
    $jobAfter['delete_live_complete'] = true;
    $jobAfter['delete_report_id'] = $reportId;
    $jobAfter['updated_at'] = $now;
    orange_cpr_job_write($cprRoot, $jobId, $jobAfter);

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.delete_live_ponr_enter',
        'job_id' => $jobId,
        'ponr_entered_at' => $ponrEnteredAt,
        'actor_admin_id' => $actorAdminId,
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'enablement_flag_observed' => false,
    ]);
    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.delete_live_apply',
        'job_id' => $jobId,
        'manifest_id' => $manifestId,
        'rows_deleted' => (int) $apply['rows_deleted'],
        'tables_completed' => $apply['tables_completed'],
        'production_sql_executed' => false,
        'actor_admin_id' => $actorAdminId,
    ]);
    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.delete_live_complete',
        'job_id' => $jobId,
        'delete_report_id' => $reportId,
        'checkpoint_id' => 'CP6',
        'import_executed' => false,
        'uploads_executed' => false,
        'actor_admin_id' => $actorAdminId,
        'ponr_crossed' => true,
        'production_mutation' => false,
        'enablement_flag_observed' => false,
    ]);

    // Hard stop: do not auto-continue to IMPORT.
    $jobFinal = orange_cpr_job_read($cprRoot, $jobId);
    if ((string) ($jobFinal['state'] ?? '') !== 'cpr_deleting') {
        return orange_cpr_deletelive_fail(
            ORANGE_CPR_DELETELIVE_ERR_STATE,
            'Expected job to remain cpr_deleting after DELETE (IMPORT disabled).',
            ['job_state' => (string) ($jobFinal['state'] ?? '')]
        );
    }

    return orange_cpr_deletelive_ok([
        'message' => 'PONR target-slice DELETE complete through CP6; IMPORT disabled.',
        'job_id' => $jobId,
        'delete_report_id' => $reportId,
        'manifest_id' => $manifestId,
        'delete_report' => orange_cpr_delete_live_load_latest($cprRoot, $jobId, 'report'),
        'mutation_manifest' => orange_cpr_delete_live_load_latest($cprRoot, $jobId, 'manifest'),
        'tables_completed' => $apply['tables_completed'],
        'rows_deleted' => (int) $apply['rows_deleted'],
        'empty_target_slice' => !empty($slice['empty']),
        'checkpoint_id' => 'CP6',
        'job_state' => 'cpr_deleting',
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'import_executed' => false,
        'uploads_executed' => false,
        'enablement_flag_observed' => false,
        'recovery_metadata' => $report['recovery_metadata'],
    ]);
}
