<?php

declare(strict_types=1);

/**
 * CPR Live Special Handlers Engine (WP-P5-04).
 *
 * Framework for exceptional restore behavior after IMPORT (CP7). Keeps the
 * IMPORT engine generic — all special-case logic lives here. Enablement FALSE
 * → sealed job ledger only (no production SQL). Uploads remain disabled.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P5_04_SPECIAL_HANDLERS.md
 * @see docs/backup/COUNTRY_RESTORE_BOUNDARY_POLICY.md §4
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md §6 §18 CP8
 */

require_once __DIR__ . '/cpr_import_live.php';
require_once __DIR__ . '/cpr_special_handlers_catalog.php';

const ORANGE_CPR_SPECIAL_LIVE_SCHEMA = 'cpr_special_handlers_live/1';
const ORANGE_CPR_SPECIAL_LIVE_VERSION = 'P5-04-1.0';

const ORANGE_CPR_SPECIALLIVE_ERR_ENABLEMENT = 'speciallive_enablement_forbidden';
const ORANGE_CPR_SPECIALLIVE_ERR_ACTOR = 'speciallive_actor_not_super_admin';
const ORANGE_CPR_SPECIALLIVE_ERR_STATE = 'speciallive_state_invalid';
const ORANGE_CPR_SPECIALLIVE_ERR_CONTRACT = 'speciallive_contract_mismatch';
const ORANGE_CPR_SPECIALLIVE_ERR_IDENTITY = 'speciallive_identity_drift';
const ORANGE_CPR_SPECIALLIVE_ERR_LOCK = 'speciallive_lock_loss';
const ORANGE_CPR_SPECIALLIVE_ERR_GATE = 'speciallive_gate_failure';
const ORANGE_CPR_SPECIALLIVE_ERR_AUTHORITY = 'speciallive_authority_failure';
const ORANGE_CPR_SPECIALLIVE_ERR_IMPORT = 'speciallive_import_required';
const ORANGE_CPR_SPECIALLIVE_ERR_CP7 = 'speciallive_cp7_required';
const ORANGE_CPR_SPECIALLIVE_ERR_DELETE = 'speciallive_delete_required';
const ORANGE_CPR_SPECIALLIVE_ERR_UNKNOWN = 'speciallive_unknown_handler';
const ORANGE_CPR_SPECIALLIVE_ERR_EXCLUDED = 'speciallive_handler_excluded';
const ORANGE_CPR_SPECIALLIVE_ERR_ORDER = 'speciallive_out_of_order';
const ORANGE_CPR_SPECIALLIVE_ERR_DEPENDENCY = 'speciallive_dependency_violation';
const ORANGE_CPR_SPECIALLIVE_ERR_SCOPE = 'speciallive_scope_expansion';
const ORANGE_CPR_SPECIALLIVE_ERR_REPLAY = 'speciallive_replay_forbidden';
const ORANGE_CPR_SPECIALLIVE_ERR_RESUME = 'speciallive_resume_invalid';
const ORANGE_CPR_SPECIALLIVE_ERR_FAIL = 'speciallive_handler_failed';
const ORANGE_CPR_SPECIALLIVE_ERR_BYPASS = 'speciallive_bypass_forbidden';
const ORANGE_CPR_SPECIALLIVE_ERR_UPLOADS = 'speciallive_uploads_disabled';
const ORANGE_CPR_SPECIALLIVE_ERR_FINGERPRINT = 'speciallive_fingerprint_mismatch';
const ORANGE_CPR_SPECIALLIVE_ERR_CHECKPOINT = 'speciallive_checkpoint_failed';
const ORANGE_CPR_SPECIALLIVE_ERR_PERSIST = 'speciallive_persist_failed';
const ORANGE_CPR_SPECIALLIVE_ERR_COUNTER = 'speciallive_counter_lower_forbidden';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_speciallive_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'uploads_executed' => false,
        'import_engine_modified' => false,
        'enablement_flag_observed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_speciallive_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'fail_closed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'uploads_executed' => false,
        'import_engine_modified' => false,
        'enablement_flag_observed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_special_live_persist(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_special_live_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR special_handlers directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Special handler record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Special handler record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_special_live_latest_path(string $cprRoot, string $jobId, string $kind): string
{
    return orange_cpr_special_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_special_live_' . $kind . '_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_special_live_load_latest(string $cprRoot, string $jobId, string $kind): ?array
{
    $path = orange_cpr_special_live_latest_path($cprRoot, $jobId, $kind);
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

function orange_cpr_special_live_handler_report_path(string $cprRoot, string $jobId, string $handlerId): string
{
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $handlerId) ?? 'handler';

    return orange_cpr_special_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_special_handler_' . $safe . '_report_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_special_live_load_handler_report(string $cprRoot, string $jobId, string $handlerId): ?array
{
    $path = orange_cpr_special_live_handler_report_path($cprRoot, $jobId, $handlerId);
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
function orange_cpr_special_live_refuse_unsafe(array $request): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_lock', 'skip_gate',
        'skip_authority', 'expand_scope', 'all_countries', 'full_schema_wipe',
        'execute_uploads', 'continue_to_uploads', 'lower_sequence_counters',
        'full_table_replace_sequences', 'modify_import_engine',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            if ($k === 'execute_uploads' || $k === 'continue_to_uploads') {
                return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_UPLOADS, 'Country Uploads disabled until WP-P5-05.');
            }

            return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_BYPASS, 'Unsafe Special Handler knob forbidden: ' . $k);
        }
    }

    return orange_cpr_speciallive_ok(['message' => 'unsafe knobs absent']);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @param array<string, mixed> $job
 * @param array<string, mixed> $contract
 * @return array<string, mixed>
 */
function orange_cpr_special_live_assert_lock_ownership(
    array $env,
    string $jobId,
    array $request,
    array $job,
    array $contract
): array {
    $cprRoot = orange_cpr_resolve_work_root($env);
    $lock = orange_cpr_lock_read($cprRoot);
    if (!is_array($lock)) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_LOCK, 'CPR lock missing during Special Handlers.');
    }
    if ((string) ($lock['job_id'] ?? '') !== $jobId) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_LOCK, 'Lock job_id ownership drift.');
    }
    if ((int) ($lock['country_id'] ?? -1) !== (int) ($job['country_id'] ?? -2)) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_LOCK, 'Lock country ownership drift.');
    }
    if ((string) ($lock['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_LOCK, 'Lock package fingerprint drift.');
    }
    $lease = (string) ($request['lease_token'] ?? '');
    $heldLease = (string) ($lock['ownership']['lease_token'] ?? '');
    if ($lease === '' || $heldLease === '' || !hash_equals($heldLease, $lease)) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_LOCK, 'Lease token ownership drift.');
    }
    $workerId = (string) ($request['worker_id'] ?? '');
    $heldWorker = (string) ($lock['ownership']['worker_id'] ?? '');
    if ($workerId === '' || $heldWorker === '' || $heldWorker !== $workerId) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_LOCK, 'Worker id ownership drift.');
    }
    if ((int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_LOCK, 'Contract/job country drift under lock.');
    }

    return orange_cpr_speciallive_ok(['lock' => $lock]);
}

/**
 * @return list<string> completed handler ids in catalog order
 */
function orange_cpr_special_live_completed_handlers(string $cprRoot, string $jobId): array
{
    $done = [];
    foreach (orange_cpr_special_handlers_executable_order() as $hid) {
        $rep = orange_cpr_special_live_load_handler_report($cprRoot, $jobId, $hid);
        if (is_array($rep) && !empty($rep['handler_complete']) && (string) ($rep['handler_id'] ?? '') === $hid) {
            $done[] = $hid;
            continue;
        }
        break;
    }

    return $done;
}

/**
 * Pause on special-handler failure (reuse import pause state — still pre-uploads).
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_special_live_pause(
    array $env,
    string $jobId,
    string $reason,
    array $completed,
    string $failedHandler
): array {
    $cprRoot = orange_cpr_resolve_work_root($env);
    $state = (string) (orange_cpr_job_read($cprRoot, $jobId)['state'] ?? '');
    $pause = ['ok' => true, 'skipped' => true];
    if ($state === 'cpr_importing') {
        $pause = orange_cpr_transition_apply($env, $jobId, 'cpr_paused_import_failed', [
            'actor' => ORANGE_CPR_ACTOR_SYSTEM,
            'trigger' => 'import_fail',
        ]);
    }
    $n = count(orange_cpr_special_handlers_executable_order());
    $done = count($completed);

    return [
        'od_fail_special' => 'pause_for_super_admin',
        'auto_rollback' => false,
        'maint_remains_on' => true,
        'completed_handlers' => $completed,
        'failed_handler' => $failedHandler,
        'failure_reason' => $reason,
        'current_stage' => 'special_handlers',
        'progress_percent' => $n > 0 ? (int) floor(($done / $n) * 100) : 0,
        'resume_supported' => true,
        'resume_from_handler' => $failedHandler,
        'statement_offset_resume' => false,
        'uploads_not_started' => true,
        'production_sql_executed' => false,
        'counters_not_lowered_ack' => true,
        'pause_transition' => $pause,
    ];
}

/**
 * Apply one handler to sealed special ledger (no production SQL).
 *
 * @param array<string, mixed> $def
 * @param array<string, mixed> $request
 * @param array<string, mixed> $job
 * @param array<string, mixed> $importSummary
 * @return array<string, mixed>
 */
function orange_cpr_special_live_apply_handler(
    string $cprRoot,
    string $jobId,
    string $handlerId,
    array $def,
    array $request,
    array $job,
    array $importSummary
): array {
    $countryId = (int) ($job['country_id'] ?? 0);
    $dir = orange_cpr_special_live_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create special_handlers directory.');
    }
    $ledgerPath = $dir . DIRECTORY_SEPARATOR . 'special_handlers_ledger.json';
    $ledger = [
        'schema_version' => 'cpr_special_handlers_ledger/1',
        'job_id' => $jobId,
        'country_id' => $countryId,
        'handlers' => [],
        'production_sql_executed' => false,
    ];
    if (is_file($ledgerPath)) {
        $raw = file_get_contents($ledgerPath);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $ledger = $decoded;
        }
    }

    $kind = (string) ($def['kind'] ?? '');
    $tables = is_array($def['tables'] ?? null) ? $def['tables'] : [];
    $result = [
        'handler_id' => $handlerId,
        'kind' => $kind,
        'tables' => $tables,
        'country_id' => $countryId,
        'applied' => true,
        'noop' => false,
        'objects_touched' => 0,
        'counters_not_lowered' => true,
        'production_sql_executed' => false,
    ];

    // Out-of-slice / cross-country refusal knobs
    if (isset($request['force_country_id']) && (int) $request['force_country_id'] !== $countryId) {
        throw new RuntimeException('Cross-country special handler execution refused.');
    }
    if (!empty($request['force_out_of_slice_table'])) {
        $t = (string) $request['force_out_of_slice_table'];
        if (!in_array($t, $tables, true)) {
            throw new RuntimeException('Out-of-slice special handler table refused: ' . $t);
        }
    }

    if ($handlerId === 'seq_country_namespace') {
        $suffix = '_c' . (string) $countryId;
        $packageScopes = is_array($request['sequence_package'] ?? null) ? $request['sequence_package'] : [];
        $surviving = is_array($request['sequence_surviving'] ?? null) ? $request['sequence_surviving'] : [];
        $observedMax = is_array($request['sequence_observed_max'] ?? null) ? $request['sequence_observed_max'] : [];
        $appliedScopes = [];
        foreach ($packageScopes as $scope => $pkgVal) {
            if (!is_string($scope) || $scope === '') {
                throw new RuntimeException('Invalid sequence scope.');
            }
            if (!str_ends_with($scope, $suffix)) {
                // Non-target scopes must remain untouched — skip, never write.
                continue;
            }
            $pkg = (int) $pkgVal;
            $surv = (int) ($surviving[$scope] ?? 0);
            $obs = (int) ($observedMax[$scope] ?? 0);
            $next = max($surv, $pkg, $obs + 1);
            if ($next < $surv) {
                throw new RuntimeException('Sequence counter lower forbidden for ' . $scope);
            }
            if (!empty($request['force_lower_counter']) && (string) $request['force_lower_counter'] === $scope) {
                throw new RuntimeException('Sequence counter lower forbidden for ' . $scope);
            }
            // Collision: another country suffix must never be written.
            if (preg_match('/_c(\d+)$/', $scope, $m) && (int) $m[1] !== $countryId) {
                throw new RuntimeException('Sequence namespace collision for ' . $scope);
            }
            $appliedScopes[$scope] = [
                'next_value' => $next,
                'package_value' => $pkg,
                'surviving_value' => $surv,
                'observed_max' => $obs,
                'counters_not_lowered' => true,
            ];
        }
        $result['objects_touched'] = count($appliedScopes);
        $result['sequence_scopes'] = $appliedScopes;
        $result['noop'] = $appliedScopes === [];
        $result['counters_not_lowered'] = true;
    } else {
        // Composite / resolver / polymorphic: sealed acknowledgment against import binding.
        $importBatches = is_array($importSummary['batches_completed'] ?? null)
            ? $importSummary['batches_completed']
            : [];
        foreach ($def['requires_import_batches'] as $b) {
            if (!in_array($b, $importBatches, true) && !in_array((string) $b, $importBatches, true)) {
                throw new RuntimeException('Dependency violation: import batch ' . (string) $b . ' required for ' . $handlerId);
            }
        }
        // Slice-aware: if handler tables absent from import ledger, record noop success.
        $importLedgerPath = orange_cpr_import_live_directory($cprRoot, $jobId)
            . DIRECTORY_SEPARATOR . 'target_slice_import_ledger.json';
        $importedTables = [];
        if (is_file($importLedgerPath)) {
            $ilRaw = file_get_contents($importLedgerPath);
            $il = is_string($ilRaw) && $ilRaw !== '' ? json_decode($ilRaw, true) : null;
            if (is_array($il) && is_array($il['imported'] ?? null)) {
                $importedTables = $il['imported'];
            }
        }
        $touched = 0;
        $memberIds = [];
        foreach ($tables as $table) {
            $ids = is_array($importedTables[$table] ?? null) ? $importedTables[$table] : [];
            $memberIds[$table] = $ids;
            $touched += count($ids);
        }
        if ($handlerId === 'admins_permissions_composite') {
            $admins = $memberIds['admins'] ?? [];
            $perms = $memberIds['admin_permissions'] ?? [];
            if ($admins !== [] && $perms === [] && !empty($request['require_composite_complete'])) {
                throw new RuntimeException('admin_permissions_composite_incomplete');
            }
        }
        $result['objects_touched'] = $touched;
        $result['member_ids'] = $memberIds;
        $result['noop'] = $touched === 0;
        $result['counters_not_lowered'] = true;
    }

    if (!empty($request['fail_handler']) && (string) $request['fail_handler'] === $handlerId) {
        throw new RuntimeException('Injected special handler failure: ' . $handlerId);
    }

    $canonical = [
        'handler_id' => $handlerId,
        'country_id' => $countryId,
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'result' => $result,
    ];
    $fp = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $result['mutation_fingerprint'] = $fp;

    $ledger['handlers'][$handlerId] = [
        'handler_id' => $handlerId,
        'result' => $result,
        'production_sql_executed' => false,
        'updated_at' => gmdate('c'),
    ];
    $ledger['package_fingerprint'] = (string) ($job['package_fingerprint'] ?? '');
    $ledger['updated_at'] = gmdate('c');
    $json = json_encode($ledger, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($ledgerPath, $json) === false) {
        throw new RuntimeException('Special handlers ledger write failed.');
    }

    return [
        'ledger_path' => $ledgerPath,
        'result' => $result,
        'mutation_fingerprint' => $fp,
        'production_sql_executed' => false,
    ];
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_special_live_assert_preconditions(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_speciallive_fail(
            ORANGE_CPR_SPECIALLIVE_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during P5 Special Handlers.'
        );
    }
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_ACTOR, 'Super Admin required for Special Handlers.');
    }
    $unsafe = orange_cpr_special_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_STATE, $e->getMessage());
    }
    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_CONTRACT, 'Frozen execution contract required.');
    }
    if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_IDENTITY, 'Contract/job identity drift.');
    }

    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP6')) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_DELETE, 'CP6 DELETE complete required.');
    }
    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP7')) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_CP7, 'CP7 import_complete required before Special Handlers.');
    }
    $importSummary = orange_cpr_import_live_load_latest($cprRoot, $jobId, 'summary');
    if (!is_array($importSummary) || empty($importSummary['import_complete']) || !orange_cpr_auth_verify_seal($importSummary)) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_IMPORT, 'Sealed IMPORT summary required.');
    }
    if ((string) ($importSummary['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($importSummary['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_IDENTITY, 'IMPORT summary identity drift.');
    }

    $gates = orange_cpr_gates_live_load_latest($cprRoot, $jobId);
    if (!is_array($gates) || empty($gates['all_gates_pass']) || !orange_cpr_auth_verify_seal($gates)) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_GATE, 'Sealed gates_live PASS required.');
    }
    $auth = orange_cpr_auth_live_load_latest($cprRoot, $jobId);
    if (!is_array($auth) || empty($auth['ponr_authorized']) || !orange_cpr_auth_verify_seal($auth)) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_AUTHORITY, 'Sealed authority_live required.');
    }

    $lockRv = orange_cpr_special_live_assert_lock_ownership($env, $jobId, $request, $job, $contract);
    if (empty($lockRv['ok'])) {
        return $lockRv;
    }

    $state = (string) ($job['state'] ?? '');
    if (!in_array($state, ['cpr_importing', 'cpr_paused_import_failed'], true)) {
        return orange_cpr_speciallive_fail(
            ORANGE_CPR_SPECIALLIVE_ERR_STATE,
            'Special Handlers require cpr_importing or cpr_paused_import_failed.',
            ['job_state' => $state]
        );
    }

    // Unknown / excluded handler probes
    if (isset($request['handler_id']) && is_string($request['handler_id']) && $request['handler_id'] !== '') {
        $hid = (string) $request['handler_id'];
        if (orange_cpr_special_handler_definition($hid) === null) {
            return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_UNKNOWN, 'Unknown special handler: ' . $hid);
        }
        if (orange_cpr_special_handler_is_excluded($hid)) {
            return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_EXCLUDED, 'Excluded special handler refused: ' . $hid);
        }
        if (!orange_cpr_special_handler_is_executable($hid)) {
            return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_UNKNOWN, 'Handler not executable: ' . $hid);
        }
    }

    return orange_cpr_speciallive_ok([
        'actor_admin_id' => (int) $request['actor_admin_id'],
        'job' => $job,
        'contract' => $contract,
        'cpr_root' => $cprRoot,
        'import_summary' => $importSummary,
        'job_state' => $state,
    ]);
}

/**
 * Execute Special Handlers framework through CP8.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_special_live_run(array $env, string $jobId, array $request = []): array
{
    $pre = orange_cpr_special_live_assert_preconditions($env, $jobId, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }

    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    /** @var array<string, mixed> $importSummary */
    $importSummary = $pre['import_summary'];
    $cprRoot = (string) $pre['cpr_root'];
    $actorAdminId = (int) $pre['actor_admin_id'];
    $state = (string) $pre['job_state'];

    $existingReport = orange_cpr_special_live_load_latest($cprRoot, $jobId, 'report');
    if (is_array($existingReport)
        && !empty($existingReport['special_handlers_complete'])
        && orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP8')
    ) {
        if (!empty($request['force_replay'])) {
            return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_REPLAY, 'Replay of completed Special Handlers forbidden.');
        }

        return orange_cpr_speciallive_ok([
            'message' => 'Special Handlers already complete (idempotent).',
            'job_id' => $jobId,
            'idempotent' => true,
            'special_report' => $existingReport,
            'mutation_manifest' => orange_cpr_special_live_load_latest($cprRoot, $jobId, 'manifest'),
            'checkpoint_id' => 'CP8',
            'job_state' => (string) (orange_cpr_job_read($cprRoot, $jobId)['state'] ?? ''),
            'production_sql_executed' => false,
            'uploads_executed' => false,
        ]);
    }
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP8') && !is_array($existingReport)) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_REPLAY, 'CP8 exists without sealed special report.');
    }

    $completed = orange_cpr_special_live_completed_handlers($cprRoot, $jobId);
    $order = orange_cpr_special_handlers_executable_order();

    if (!empty($request['force_handler_order']) && is_array($request['force_handler_order'])) {
        $forced = array_values(array_map('strval', $request['force_handler_order']));
        if ($forced !== $order) {
            return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_ORDER, 'Out-of-order Special Handler execution refused.');
        }
    }

    $resumeFrom = isset($request['resume_from_handler']) ? (string) $request['resume_from_handler'] : null;
    $nextExpected = $completed === [] ? $order[0] : (
        count($completed) < count($order) ? $order[count($completed)] : null
    );
    if ($resumeFrom !== null) {
        if ($nextExpected === null || $resumeFrom !== $nextExpected) {
            return orange_cpr_speciallive_fail(
                ORANGE_CPR_SPECIALLIVE_ERR_RESUME,
                'Invalid resume handler; expected ' . (string) $nextExpected . '.',
                ['completed_handlers' => $completed, 'resume_from_handler' => $resumeFrom]
            );
        }
    }

    if ($state === 'cpr_paused_import_failed') {
        if (empty($request['resume_authorized'])) {
            return orange_cpr_speciallive_fail(
                ORANGE_CPR_SPECIALLIVE_ERR_RESUME,
                'Paused Special Handlers require Super Admin resume_authorized.'
            );
        }
        $tr = orange_cpr_transition_apply($env, $jobId, 'cpr_importing', [
            'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
            'actor_admin_id' => $actorAdminId,
            'trigger' => 'resume',
            'safe_resume' => true,
        ]);
        if (empty($tr['ok'])) {
            return orange_cpr_speciallive_fail(
                ORANGE_CPR_SPECIALLIVE_ERR_STATE,
                (string) ($tr['message'] ?? 'Resume to cpr_importing failed.'),
                ['upstream' => $tr]
            );
        }
        $job = orange_cpr_job_read($cprRoot, $jobId);
        $state = (string) ($job['state'] ?? '');
    }
    if ($state !== 'cpr_importing') {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_STATE, 'Expected cpr_importing.', ['job_state' => $state]);
    }

    $lockRv = orange_cpr_special_live_assert_lock_ownership($env, $jobId, $request, $job, $contract);
    if (empty($lockRv['ok'])) {
        $recovery = orange_cpr_special_live_pause($env, $jobId, 'lock_loss', $completed, (string) ($nextExpected ?? ''));

        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_LOCK, 'Lock loss during Special Handlers.', [
            'recovery_metadata' => $recovery,
        ]);
    }

    // Single-handler probe path (unknown/excluded already handled in preconditions).
    if (isset($request['handler_id']) && is_string($request['handler_id']) && $request['handler_id'] !== ''
        && empty($request['run_all_handlers'])
    ) {
        $hid = (string) $request['handler_id'];
        if ($nextExpected !== null && $hid !== $nextExpected && !in_array($hid, $completed, true)) {
            return orange_cpr_speciallive_fail(
                ORANGE_CPR_SPECIALLIVE_ERR_ORDER,
                'Cannot execute handler out of order: ' . $hid . ' (expected ' . $nextExpected . ').'
            );
        }
        $probeDef = orange_cpr_special_handler_definition($hid);
        $probeDeps = is_array($probeDef['requires_handlers'] ?? null) ? $probeDef['requires_handlers'] : [];
        foreach ($probeDeps as $dep) {
            if (!in_array($dep, $completed, true) && $dep !== $hid) {
                $depRep = orange_cpr_special_live_load_handler_report($cprRoot, $jobId, $dep);
                if (!is_array($depRep) || empty($depRep['handler_complete'])) {
                    return orange_cpr_speciallive_fail(
                        ORANGE_CPR_SPECIALLIVE_ERR_DEPENDENCY,
                        'Dependency violation: ' . $dep . ' required before ' . $hid
                    );
                }
            }
        }
    }

    $stopAfter = isset($request['stop_after_handler']) ? (string) $request['stop_after_handler'] : '';
    $handlerReports = [];
    $allFingerprints = [];

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.special_live_start',
        'job_id' => $jobId,
        'completed_handlers' => $completed,
        'order_version' => ORANGE_CPR_SPECIAL_HANDLERS_ORDER_VERSION,
        'actor_admin_id' => $actorAdminId,
        'production_sql_executed' => false,
        'enablement_flag_observed' => false,
    ]);

    foreach ($order as $handlerId) {
        if (in_array($handlerId, $completed, true)) {
            $prev = orange_cpr_special_live_load_handler_report($cprRoot, $jobId, $handlerId);
            if (is_array($prev)) {
                $handlerReports[$handlerId] = $prev;
                $allFingerprints[$handlerId] = (string) ($prev['mutation_fingerprint'] ?? '');
            }
            continue;
        }

        // Prior handlers must be sealed.
        $idx = array_search($handlerId, $order, true);
        if ($idx === false) {
            return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_UNKNOWN, 'Handler missing from order.');
        }
        if ($idx > 0) {
            $prior = $order[$idx - 1];
            $priorRep = orange_cpr_special_live_load_handler_report($cprRoot, $jobId, $prior);
            if (!is_array($priorRep) || empty($priorRep['handler_complete'])) {
                $recovery = orange_cpr_special_live_pause($env, $jobId, 'prior_unsealed', $completed, $handlerId);

                return orange_cpr_speciallive_fail(
                    ORANGE_CPR_SPECIALLIVE_ERR_DEPENDENCY,
                    'Cannot start ' . $handlerId . ' before ' . $prior . ' sealed.',
                    ['recovery_metadata' => $recovery]
                );
            }
        }

        $def = orange_cpr_special_handler_definition($handlerId);
        if ($def === null) {
            return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_UNKNOWN, 'Unknown handler: ' . $handlerId);
        }
        foreach ($def['requires_handlers'] as $dep) {
            $depRep = orange_cpr_special_live_load_handler_report($cprRoot, $jobId, $dep);
            if (!is_array($depRep) || empty($depRep['handler_complete'])) {
                $recovery = orange_cpr_special_live_pause($env, $jobId, 'dependency', $completed, $handlerId);

                return orange_cpr_speciallive_fail(
                    ORANGE_CPR_SPECIALLIVE_ERR_DEPENDENCY,
                    'Dependency violation: ' . $dep . ' before ' . $handlerId,
                    ['recovery_metadata' => $recovery]
                );
            }
        }

        $existingH = orange_cpr_special_live_load_handler_report($cprRoot, $jobId, $handlerId);
        if (is_array($existingH) && !empty($existingH['handler_complete'])) {
            if (!empty($request['force_replay_handler']) && (string) $request['force_replay_handler'] === $handlerId) {
                return orange_cpr_speciallive_fail(
                    ORANGE_CPR_SPECIALLIVE_ERR_REPLAY,
                    'Replay of completed handler forbidden: ' . $handlerId
                );
            }
            $handlerReports[$handlerId] = $existingH;
            $completed[] = $handlerId;
            continue;
        }

        $lockRv = orange_cpr_special_live_assert_lock_ownership($env, $jobId, $request, $job, $contract);
        if (empty($lockRv['ok'])) {
            $recovery = orange_cpr_special_live_pause($env, $jobId, 'lock_loss', $completed, $handlerId);

            return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_LOCK, 'Lock loss before ' . $handlerId, [
                'recovery_metadata' => $recovery,
            ]);
        }

        try {
            $apply = orange_cpr_special_live_apply_handler(
                $cprRoot,
                $jobId,
                $handlerId,
                $def,
                $request,
                $job,
                $importSummary
            );
        } catch (Throwable $e) {
            $code = ORANGE_CPR_SPECIALLIVE_ERR_FAIL;
            $msg = $e->getMessage();
            if (str_contains($msg, 'Cross-country')) {
                $code = ORANGE_CPR_SPECIALLIVE_ERR_SCOPE;
            } elseif (str_contains($msg, 'Out-of-slice')) {
                $code = ORANGE_CPR_SPECIALLIVE_ERR_SCOPE;
            } elseif (str_contains($msg, 'counter lower') || str_contains($msg, 'Sequence counter')) {
                $code = ORANGE_CPR_SPECIALLIVE_ERR_COUNTER;
            } elseif (str_contains($msg, 'Dependency violation')) {
                $code = ORANGE_CPR_SPECIALLIVE_ERR_DEPENDENCY;
            }
            $recovery = orange_cpr_special_live_pause($env, $jobId, $msg, $completed, $handlerId);
            orange_cpr_audit_append($cprRoot, $jobId, [
                'event_type' => 'cpr.special_live_handler_fail',
                'job_id' => $jobId,
                'handler_id' => $handlerId,
                'message' => $msg,
                'actor_admin_id' => $actorAdminId,
                'recovery_metadata' => $recovery,
                'production_sql_executed' => false,
            ]);

            return orange_cpr_speciallive_fail($code, $msg, [
                'failed_handler' => $handlerId,
                'completed_handlers' => $completed,
                'recovery_metadata' => $recovery,
            ]);
        }

        if (!empty($request['expect_fingerprint'])
            && is_array($request['expect_fingerprint'])
            && isset($request['expect_fingerprint'][$handlerId])
            && (string) $request['expect_fingerprint'][$handlerId] !== (string) $apply['mutation_fingerprint']
        ) {
            $recovery = orange_cpr_special_live_pause($env, $jobId, 'fingerprint_mismatch', $completed, $handlerId);

            return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_FINGERPRINT, 'Handler fingerprint mismatch.', [
                'failed_handler' => $handlerId,
                'recovery_metadata' => $recovery,
            ]);
        }

        $now = gmdate('c');
        $manifestId = orange_cpr_generate_job_id();
        $reportId = orange_cpr_generate_job_id();
        /** @var array<string, mixed> $hResult */
        $hResult = $apply['result'];

        $mutationManifest = [
            'schema_version' => ORANGE_CPR_SPECIAL_LIVE_SCHEMA,
            'engine_version' => ORANGE_CPR_SPECIAL_LIVE_VERSION,
            'record_type' => 'cpr_special_handler_mutation_manifest',
            'manifest_id' => $manifestId,
            'job_id' => $jobId,
            'handler_id' => $handlerId,
            'country_id' => (int) ($job['country_id'] ?? 0),
            'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
            'order_version' => ORANGE_CPR_SPECIAL_HANDLERS_ORDER_VERSION,
            'tables' => $def['tables'],
            'kind' => $def['kind'],
            'result' => $hResult,
            'mutation_fingerprint' => (string) $apply['mutation_fingerprint'],
            'counters_not_lowered_ack' => !empty($hResult['counters_not_lowered']),
            'production_sql_executed' => false,
            'uploads_executed' => false,
            'created_at' => $now,
        ];

        $handlerReport = [
            'schema_version' => ORANGE_CPR_SPECIAL_LIVE_SCHEMA,
            'engine_version' => ORANGE_CPR_SPECIAL_LIVE_VERSION,
            'record_type' => 'cpr_special_handler_execution_report',
            'handler_report_id' => $reportId,
            'manifest_id' => $manifestId,
            'job_id' => $jobId,
            'handler_id' => $handlerId,
            'handler_complete' => true,
            'country_id' => (int) ($job['country_id'] ?? 0),
            'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
            'order_version' => ORANGE_CPR_SPECIAL_HANDLERS_ORDER_VERSION,
            'tables' => $def['tables'],
            'kind' => $def['kind'],
            'result' => $hResult,
            'mutation_fingerprint' => (string) $apply['mutation_fingerprint'],
            'counters_not_lowered_ack' => true,
            'recovery_metadata' => [
                'completed_handlers' => array_merge($completed, [$handlerId]),
                'resume_from_handler' => null,
                'production_sql_executed' => false,
            ],
            'actor_admin_id' => $actorAdminId,
            'production_sql_executed' => false,
            'uploads_executed' => false,
            'enablement_flag_observed' => false,
            'created_at' => $now,
        ];
        $nextIdx = $idx + 1;
        if ($nextIdx < count($order)) {
            $handlerReport['recovery_metadata']['resume_from_handler'] = $order[$nextIdx];
        }

        try {
            orange_cpr_special_live_persist(
                $cprRoot,
                $jobId,
                'cpr_special_handler_' . $handlerId . '_mutation_' . $manifestId,
                $mutationManifest
            );
            $reportPath = orange_cpr_special_live_persist(
                $cprRoot,
                $jobId,
                'cpr_special_handler_' . $handlerId . '_report_' . $reportId,
                $handlerReport
            );
            $latest = orange_cpr_special_live_handler_report_path($cprRoot, $jobId, $handlerId);
            $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
            $sealed = orange_cpr_auth_seal(array_merge($handlerReport, ['handler_report_path' => $reportPath]));
            $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false || @file_put_contents($tmp, $json) === false) {
                throw new RuntimeException('Handler latest pointer write failed.');
            }
            orange_cpr_atomic_rename_replace($tmp, $latest);
        } catch (RuntimeException $e) {
            $recovery = orange_cpr_special_live_pause($env, $jobId, $e->getMessage(), $completed, $handlerId);

            return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_PERSIST, $e->getMessage(), [
                'failed_handler' => $handlerId,
                'recovery_metadata' => $recovery,
            ]);
        }

        $completed[] = $handlerId;
        $handlerReports[$handlerId] = orange_cpr_special_live_load_handler_report($cprRoot, $jobId, $handlerId);
        $allFingerprints[$handlerId] = (string) $apply['mutation_fingerprint'];

        orange_cpr_audit_append($cprRoot, $jobId, [
            'event_type' => 'cpr.special_live_handler_complete',
            'job_id' => $jobId,
            'handler_id' => $handlerId,
            'handler_report_id' => $reportId,
            'mutation_fingerprint' => (string) $apply['mutation_fingerprint'],
            'counters_not_lowered_ack' => true,
            'actor_admin_id' => $actorAdminId,
            'production_sql_executed' => false,
        ]);

        if ($stopAfter !== '' && $stopAfter === $handlerId && count($completed) < count($order)) {
            return orange_cpr_speciallive_ok([
                'message' => 'Stopped after sealed handler ' . $handlerId . ' (resume boundary).',
                'job_id' => $jobId,
                'stopped_after_handler' => $handlerId,
                'completed_handlers' => $completed,
                'resume_from_handler' => $order[count($completed)],
                'special_handlers_complete' => false,
                'job_state' => 'cpr_importing',
                'production_sql_executed' => false,
                'uploads_executed' => false,
                'recovery_metadata' => [
                    'completed_handlers' => $completed,
                    'resume_from_handler' => $order[count($completed)],
                    'production_sql_executed' => false,
                ],
            ]);
        }
    }

    if (count($completed) !== count($order)) {
        return orange_cpr_speciallive_fail(
            ORANGE_CPR_SPECIALLIVE_ERR_STATE,
            'Special Handlers incomplete; completed=' . (string) count($completed)
        );
    }

    $now = gmdate('c');
    $summaryManifestId = orange_cpr_generate_job_id();
    $summaryReportId = orange_cpr_generate_job_id();
    $handlersList = $order;

    $mutationManifest = [
        'schema_version' => ORANGE_CPR_SPECIAL_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_SPECIAL_LIVE_VERSION,
        'record_type' => 'cpr_special_handlers_mutation_manifest',
        'manifest_id' => $summaryManifestId,
        'job_id' => $jobId,
        'handlers' => $handlersList,
        'handler_fingerprints' => $allFingerprints,
        'order_version' => ORANGE_CPR_SPECIAL_HANDLERS_ORDER_VERSION,
        'country_id' => (int) ($job['country_id'] ?? 0),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'counters_not_lowered_ack' => true,
        'production_sql_executed' => false,
        'uploads_executed' => false,
        'created_at' => $now,
    ];
    $mutationManifest['manifest_fingerprint'] = hash(
        'sha256',
        (string) json_encode([
            'handlers' => $handlersList,
            'handler_fingerprints' => $allFingerprints,
            'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $report = [
        'schema_version' => ORANGE_CPR_SPECIAL_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_SPECIAL_LIVE_VERSION,
        'record_type' => 'cpr_special_handlers_execution_report',
        'special_report_id' => $summaryReportId,
        'manifest_id' => $summaryManifestId,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'special_handlers_complete' => true,
        'handlers' => $handlersList,
        'handler_fingerprints' => $allFingerprints,
        'counters_not_lowered_ack' => true,
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
        'order_version' => ORANGE_CPR_SPECIAL_HANDLERS_ORDER_VERSION,
        'import_summary_id' => (string) ($importSummary['import_summary_id'] ?? ''),
        'manifest_fingerprint' => $mutationManifest['manifest_fingerprint'],
        'recovery_metadata' => [
            'completed_phase' => 'special_handlers',
            'execution_status' => 'special_handlers_complete',
            'maint_remains_on' => true,
            'uploads_not_started' => true,
            'auto_rollback' => false,
            'ponr_crossed' => true,
            'production_sql_executed' => false,
            'counters_not_lowered_ack' => true,
            'completed_handlers' => $handlersList,
        ],
        'actor_admin_id' => $actorAdminId,
        'ops_enablement_flag' => false,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'uploads_executed' => false,
        'import_engine_modified' => false,
        'enablement_flag_observed' => false,
        'created_at' => $now,
    ];

    try {
        $mPath = orange_cpr_special_live_persist($cprRoot, $jobId, 'cpr_special_manifest_' . $summaryManifestId, $mutationManifest);
        $rPath = orange_cpr_special_live_persist($cprRoot, $jobId, 'cpr_special_report_' . $summaryReportId, $report);
        foreach ([
            'manifest' => array_merge($mutationManifest, ['manifest_path' => $mPath]),
            'report' => array_merge($report, ['special_report_path' => $rPath]),
        ] as $kind => $rec) {
            $latest = orange_cpr_special_live_latest_path($cprRoot, $jobId, $kind);
            $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
            $sealed = orange_cpr_auth_seal($rec);
            $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false || @file_put_contents($tmp, $json) === false) {
                throw new RuntimeException('Special live latest write failed: ' . $kind);
            }
            orange_cpr_atomic_rename_replace($tmp, $latest);
        }
    } catch (RuntimeException $e) {
        return orange_cpr_speciallive_fail(ORANGE_CPR_SPECIALLIVE_ERR_PERSIST, $e->getMessage());
    }

    $cp8 = orange_cpr_checkpoint_create($env, $jobId, 'CP8', [
        'handlers' => $handlersList,
        'counters_not_lowered_ack' => true,
    ], ['written_by' => 'system']);
    if (empty($cp8['ok'])) {
        return orange_cpr_speciallive_fail(
            ORANGE_CPR_SPECIALLIVE_ERR_CHECKPOINT,
            (string) ($cp8['message'] ?? 'CP8 write failed.'),
            ['upstream' => $cp8]
        );
    }

    $jobAfter = orange_cpr_job_read($cprRoot, $jobId);
    $engines = is_array($jobAfter['mutation_engines'] ?? null) ? $jobAfter['mutation_engines'] : [];
    $engines['delete'] = true;
    $engines['import'] = true;
    $engines['special_handlers'] = true;
    $engines['uploads'] = false;
    $engines['ponr'] = true;
    $jobAfter['mutation_engines'] = $engines;
    $jobAfter['ponr_mutation_executed'] = false;
    $jobAfter['special_handlers_live_complete'] = true;
    $jobAfter['special_report_id'] = $summaryReportId;
    $jobAfter['updated_at'] = $now;
    orange_cpr_job_write($cprRoot, $jobId, $jobAfter);

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.special_live_complete',
        'job_id' => $jobId,
        'special_report_id' => $summaryReportId,
        'checkpoint_id' => 'CP8',
        'handlers' => $handlersList,
        'manifest_fingerprint' => $mutationManifest['manifest_fingerprint'],
        'counters_not_lowered_ack' => true,
        'uploads_executed' => false,
        'actor_admin_id' => $actorAdminId,
        'production_sql_executed' => false,
        'enablement_flag_observed' => false,
    ]);

    $jobFinal = orange_cpr_job_read($cprRoot, $jobId);
    if ((string) ($jobFinal['state'] ?? '') !== 'cpr_importing') {
        return orange_cpr_speciallive_fail(
            ORANGE_CPR_SPECIALLIVE_ERR_STATE,
            'Expected job to remain cpr_importing after Special Handlers (uploads disabled).',
            ['job_state' => (string) ($jobFinal['state'] ?? '')]
        );
    }

    return orange_cpr_speciallive_ok([
        'message' => 'Special Handlers complete through CP8; uploads disabled.',
        'job_id' => $jobId,
        'special_report_id' => $summaryReportId,
        'manifest_id' => $summaryManifestId,
        'special_report' => orange_cpr_special_live_load_latest($cprRoot, $jobId, 'report'),
        'mutation_manifest' => orange_cpr_special_live_load_latest($cprRoot, $jobId, 'manifest'),
        'handlers' => $handlersList,
        'counters_not_lowered_ack' => true,
        'checkpoint_id' => 'CP8',
        'job_state' => 'cpr_importing',
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'uploads_executed' => false,
        'import_engine_modified' => false,
        'enablement_flag_observed' => false,
        'recovery_metadata' => $report['recovery_metadata'],
    ]);
}
