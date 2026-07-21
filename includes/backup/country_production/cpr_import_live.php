<?php

declare(strict_types=1);

/**
 * CPR Live Target-Slice IMPORT Engine (WP-P5-03) — Batches 1→6.
 *
 * Requires completed DELETE (CP6). Enters cpr_importing via T10, applies each
 * restore batch against the job-bound sealed import ledger (enablement FALSE →
 * no production SQL), seals per-batch artifacts, then CP7 + final summary.
 * Special handlers / uploads remain disabled.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P5_03_TARGET_SLICE_IMPORT.md
 * @see docs/backup/COUNTRY_DEPENDENCY_GRAPH.md §4
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md §6 §10.2 §13 §18
 */

require_once __DIR__ . '/cpr_delete_live.php';
require_once __DIR__ . '/cpr_import_batches.php';

const ORANGE_CPR_IMPORT_LIVE_SCHEMA = 'cpr_import_live/1';
const ORANGE_CPR_IMPORT_LIVE_VERSION = 'P5-03-1.0';

const ORANGE_CPR_IMPORTLIVE_ERR_ENABLEMENT = 'importlive_enablement_forbidden';
const ORANGE_CPR_IMPORTLIVE_ERR_ACTOR = 'importlive_actor_not_super_admin';
const ORANGE_CPR_IMPORTLIVE_ERR_STATE = 'importlive_state_invalid';
const ORANGE_CPR_IMPORTLIVE_ERR_CONTRACT = 'importlive_contract_mismatch';
const ORANGE_CPR_IMPORTLIVE_ERR_IDENTITY = 'importlive_identity_drift';
const ORANGE_CPR_IMPORTLIVE_ERR_LOCK = 'importlive_lock_loss';
const ORANGE_CPR_IMPORTLIVE_ERR_GATE = 'importlive_gate_failure';
const ORANGE_CPR_IMPORTLIVE_ERR_AUTHORITY = 'importlive_authority_failure';
const ORANGE_CPR_IMPORTLIVE_ERR_DELETE = 'importlive_delete_required';
const ORANGE_CPR_IMPORTLIVE_ERR_CP6 = 'importlive_cp6_required';
const ORANGE_CPR_IMPORTLIVE_ERR_ODPIN = 'importlive_odpin_mismatch';
const ORANGE_CPR_IMPORTLIVE_ERR_SCHEMA = 'importlive_schema_mismatch';
const ORANGE_CPR_IMPORTLIVE_ERR_SCOPE = 'importlive_scope_expansion';
const ORANGE_CPR_IMPORTLIVE_ERR_SLICE = 'importlive_slice_invalid';
const ORANGE_CPR_IMPORTLIVE_ERR_SOURCE = 'importlive_source_invalid';
const ORANGE_CPR_IMPORTLIVE_ERR_BATCH_SKIP = 'importlive_batch_skip_forbidden';
const ORANGE_CPR_IMPORTLIVE_ERR_BATCH_REORDER = 'importlive_batch_reorder_forbidden';
const ORANGE_CPR_IMPORTLIVE_ERR_REPLAY = 'importlive_replay_forbidden';
const ORANGE_CPR_IMPORTLIVE_ERR_RESUME = 'importlive_resume_invalid';
const ORANGE_CPR_IMPORTLIVE_ERR_RI = 'importlive_referential_integrity';
const ORANGE_CPR_IMPORTLIVE_ERR_FINGERPRINT = 'importlive_fingerprint_mismatch';
const ORANGE_CPR_IMPORTLIVE_ERR_COUNT = 'importlive_count_mismatch';
const ORANGE_CPR_IMPORTLIVE_ERR_BYPASS = 'importlive_bypass_forbidden';
const ORANGE_CPR_IMPORTLIVE_ERR_SPECIAL = 'importlive_special_handlers_disabled';
const ORANGE_CPR_IMPORTLIVE_ERR_UPLOADS = 'importlive_uploads_disabled';
const ORANGE_CPR_IMPORTLIVE_ERR_TRANSITION = 'importlive_transition_failed';
const ORANGE_CPR_IMPORTLIVE_ERR_CHECKPOINT = 'importlive_checkpoint_failed';
const ORANGE_CPR_IMPORTLIVE_ERR_PERSIST = 'importlive_persist_failed';
const ORANGE_CPR_IMPORTLIVE_ERR_BATCH_FAIL = 'importlive_batch_failed';
const ORANGE_CPR_IMPORTLIVE_ERR_ORDER = 'importlive_order_invalid';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_importlive_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'special_handlers_executed' => false,
        'uploads_executed' => false,
        'enablement_flag_observed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_importlive_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'fail_closed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'special_handlers_executed' => false,
        'uploads_executed' => false,
        'enablement_flag_observed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_import_live_persist(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_import_live_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR import_live directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Import live record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Import live record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_import_live_latest_path(string $cprRoot, string $jobId, string $kind): string
{
    return orange_cpr_import_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_import_live_' . $kind . '_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_import_live_load_latest(string $cprRoot, string $jobId, string $kind): ?array
{
    $path = orange_cpr_import_live_latest_path($cprRoot, $jobId, $kind);
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

function orange_cpr_import_live_batch_report_path(string $cprRoot, string $jobId, int $batch): string
{
    return orange_cpr_import_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_import_batch_' . $batch . '_report_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_import_live_load_batch_report(string $cprRoot, string $jobId, int $batch): ?array
{
    $path = orange_cpr_import_live_batch_report_path($cprRoot, $jobId, $batch);
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
function orange_cpr_import_live_refuse_unsafe(array $request): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_lock', 'skip_gate',
        'skip_authority', 'expand_scope', 'all_countries', 'full_schema_wipe',
        'skip_batch', 'reorder_batches', 'execute_special_handlers', 'execute_uploads',
        'continue_to_uploads', 'statement_offset_resume',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            if ($k === 'execute_special_handlers') {
                return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_SPECIAL, 'Special handlers disabled until WP-P5-04.');
            }
            if ($k === 'execute_uploads' || $k === 'continue_to_uploads') {
                return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_UPLOADS, 'Country Uploads disabled until WP-P5-05.');
            }
            if ($k === 'skip_batch') {
                return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_BATCH_SKIP, 'Batch skip forbidden.');
            }
            if ($k === 'reorder_batches') {
                return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_BATCH_REORDER, 'Batch reorder forbidden.');
            }

            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_BYPASS, 'Unsafe IMPORT knob forbidden: ' . $k);
        }
    }

    return orange_cpr_importlive_ok(['message' => 'unsafe knobs absent']);
}

/**
 * Post-PONR lock ownership assert (does not use pre-PONR revalidate that refuses ponr_crossed).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @param array<string, mixed> $job
 * @param array<string, mixed> $contract
 * @return array<string, mixed>
 */
function orange_cpr_import_live_assert_lock_ownership(
    array $env,
    string $jobId,
    array $request,
    array $job,
    array $contract
): array {
    $cprRoot = orange_cpr_resolve_work_root($env);
    $lock = orange_cpr_lock_read($cprRoot);
    if (!is_array($lock)) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_LOCK, 'CPR lock missing during IMPORT.');
    }
    if ((string) ($lock['job_id'] ?? '') !== $jobId) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_LOCK, 'Lock job_id ownership drift.');
    }
    if ((int) ($lock['country_id'] ?? -1) !== (int) ($job['country_id'] ?? -2)) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_LOCK, 'Lock country ownership drift.');
    }
    if ((string) ($lock['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_LOCK, 'Lock package fingerprint drift.');
    }
    $lease = (string) ($request['lease_token'] ?? '');
    $heldLease = (string) ($lock['ownership']['lease_token'] ?? '');
    if ($lease === '' || $heldLease === '' || !hash_equals($heldLease, $lease)) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_LOCK, 'Lease token ownership drift.');
    }
    $workerId = (string) ($request['worker_id'] ?? '');
    $heldWorker = (string) ($lock['ownership']['worker_id'] ?? '');
    if ($workerId === '' || $heldWorker === '' || $heldWorker !== $workerId) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_LOCK, 'Worker id ownership drift.');
    }
    if ((int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_LOCK, 'Contract/job country drift under lock.');
    }

    return orange_cpr_importlive_ok(['lock' => $lock]);
}

/**
 * Derive import target slice from sealed DELETE ledger (contract-bound).
 *
 * @param array<string, mixed> $job
 * @param array<string, mixed> $contract
 * @param array<string, mixed> $deleteReport
 * @return array<string, mixed>
 */
function orange_cpr_import_live_build_slice_from_delete(
    string $cprRoot,
    string $jobId,
    array $job,
    array $contract,
    array $deleteReport
): array {
    $ledgerPath = orange_cpr_delete_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'target_slice_ledger.json';
    if (!is_file($ledgerPath)) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_DELETE, 'DELETE target_slice_ledger.json missing.');
    }
    $raw = file_get_contents($ledgerPath);
    $ledger = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($ledger)) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_DELETE, 'DELETE ledger corrupt.');
    }
    $countryId = (int) ($ledger['country_id'] ?? 0);
    $countryCode = strtoupper((string) ($ledger['country_code'] ?? ''));
    if ($countryId !== (int) ($job['country_id'] ?? 0)
        || $countryId !== (int) ($contract['country_id'] ?? 0)
        || $countryId !== (int) ($deleteReport['country_id'] ?? 0)
    ) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_SCOPE, 'DELETE ledger country mismatch.');
    }

    $deleted = is_array($ledger['deleted'] ?? null) ? $ledger['deleted'] : [];
    $tables = [];
    foreach ($deleted as $table => $ids) {
        if (!is_string($table) || $table === '' || !is_array($ids)) {
            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_SLICE, 'Invalid DELETE ledger table entry.');
        }
        $clean = [];
        foreach ($ids as $id) {
            $s = trim((string) $id);
            if ($s === '') {
                return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_SLICE, 'Empty row id in DELETE ledger.');
            }
            $clean[] = $s;
        }
        $tables[$table] = $clean;
    }

    return orange_cpr_importlive_ok([
        'slice' => [
            'country_id' => $countryId,
            'country_code' => $countryCode,
            'import_order_version' => ORANGE_CPR_IMPORT_ORDER_VERSION,
            'tables' => $tables,
            'empty' => array_sum(array_map('count', $tables)) === 0,
        ],
        'delete_ledger' => $ledger,
    ]);
}

/**
 * @param array<string, mixed> $slice tables map
 * @param array<string, mixed>|null $sourceOverride
 * @return array<string, mixed>
 */
function orange_cpr_import_live_resolve_batch_input(
    int $batch,
    array $slice,
    ?array $sourceOverride,
    int $countryId,
    string $packageFingerprint,
    int $schemaRevision
): array {
    $batchTables = orange_cpr_import_batch_tables($batch);
    $sliceTables = is_array($slice['tables'] ?? null) ? $slice['tables'] : [];
    $rows = [];
    $expectedCount = 0;

    foreach ($batchTables as $table) {
        $ids = is_array($sliceTables[$table] ?? null) ? $sliceTables[$table] : [];
        $objects = [];
        foreach ($ids as $id) {
            $objects[] = [
                'id' => (string) $id,
                'country_id' => $countryId,
                'table' => $table,
            ];
        }
        $rows[$table] = $objects;
        $expectedCount += count($objects);
    }

    if (is_array($sourceOverride)) {
        if ((int) ($sourceOverride['batch'] ?? 0) !== $batch) {
            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_SOURCE, 'Source artifact batch mismatch.');
        }
        if ((int) ($sourceOverride['country_id'] ?? 0) !== $countryId) {
            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_SCOPE, 'Source artifact cross-country refused.');
        }
        if ((string) ($sourceOverride['package_fingerprint'] ?? '') !== $packageFingerprint) {
            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_SOURCE, 'Source package_fingerprint mismatch.');
        }
        if ((int) ($sourceOverride['schema_revision'] ?? -1) !== $schemaRevision) {
            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_SCHEMA, 'Source schema_revision mismatch.');
        }
        if (empty($sourceOverride['sealed']) && empty($sourceOverride['_seal'])) {
            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_SOURCE, 'Unsealed source artifact refused.');
        }
        $overrideRows = is_array($sourceOverride['rows'] ?? null) ? $sourceOverride['rows'] : null;
        if ($overrideRows === null) {
            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_SOURCE, 'Source rows missing.');
        }
        // Reject out-of-batch / out-of-slice tables.
        foreach ($overrideRows as $table => $objs) {
            if (!is_string($table) || !in_array($table, $batchTables, true)) {
                return orange_cpr_importlive_fail(
                    ORANGE_CPR_IMPORTLIVE_ERR_SCOPE,
                    'Source table outside batch ' . (string) $batch . ': ' . (string) $table
                );
            }
            if (!isset($sliceTables[$table]) && is_array($objs) && count($objs) > 0) {
                return orange_cpr_importlive_fail(
                    ORANGE_CPR_IMPORTLIVE_ERR_SCOPE,
                    'Source table outside target slice: ' . $table
                );
            }
            if (!is_array($objs)) {
                return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_SOURCE, 'Corrupt source rows for ' . $table);
            }
            foreach ($objs as $obj) {
                if (!is_array($obj)) {
                    return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_SOURCE, 'Corrupt source object in ' . $table);
                }
                if (isset($obj['country_id']) && (int) $obj['country_id'] !== $countryId) {
                    return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_SCOPE, 'Cross-country write attempt in ' . $table);
                }
            }
        }
        $rows = [];
        $expectedCount = 0;
        foreach ($batchTables as $table) {
            $objs = is_array($overrideRows[$table] ?? null) ? $overrideRows[$table] : [];
            $rows[$table] = $objs;
            $expectedCount += count($objs);
        }
        if (isset($sourceOverride['expected_row_count'])
            && (int) $sourceOverride['expected_row_count'] !== $expectedCount
        ) {
            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_COUNT, 'Source expected_row_count mismatch.');
        }
    }

    $canonical = [
        'batch' => $batch,
        'country_id' => $countryId,
        'package_fingerprint' => $packageFingerprint,
        'schema_revision' => $schemaRevision,
        'import_order_version' => ORANGE_CPR_IMPORT_ORDER_VERSION,
        'tables' => $batchTables,
        'rows' => $rows,
    ];
    $fp = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return orange_cpr_importlive_ok([
        'input' => [
            'batch' => $batch,
            'country_id' => $countryId,
            'package_fingerprint' => $packageFingerprint,
            'schema_revision' => $schemaRevision,
            'import_order_version' => ORANGE_CPR_IMPORT_ORDER_VERSION,
            'dependency_graph_version' => ORANGE_CPR_IMPORT_DEPENDENCY_GRAPH_VERSION,
            'tables' => $batchTables,
            'rows' => $rows,
            'row_count' => $expectedCount,
            'input_fingerprint' => $fp,
            'sealed' => true,
            'production_sql_executed' => false,
        ],
    ]);
}

/**
 * @param array<string, mixed> $input
 * @param array<string, array<string, bool>> $importedIndex table => id => true
 * @return array<string, mixed>
 */
function orange_cpr_import_live_check_referential_integrity(array $input, array $importedIndex): array
{
    $parents = orange_cpr_import_referential_parents();
    $rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
    foreach ($rows as $table => $objs) {
        if (!is_string($table) || !is_array($objs) || $objs === []) {
            continue;
        }
        $need = $parents[$table] ?? [];
        foreach ($need as $parentTable) {
            // Only enforce when parent is in target-slice import path (already imported or same job).
            $parentSeen = $importedIndex[$parentTable] ?? null;
            if ($parentSeen === null) {
                // Parent table not part of this import slice — skip RI for non-slice parents.
                continue;
            }
            foreach ($objs as $obj) {
                if (!is_array($obj)) {
                    return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_RI, 'Corrupt object in ' . $table);
                }
                $parentId = trim((string) ($obj['parent_id'] ?? $obj['id'] ?? ''));
                // For order_items etc., prefer explicit parent_id; fallback requires parent id set present.
                if (isset($obj['parent_id'])) {
                    $pid = trim((string) $obj['parent_id']);
                    if ($pid === '' || empty($parentSeen[$pid])) {
                        return orange_cpr_importlive_fail(
                            ORANGE_CPR_IMPORTLIVE_ERR_RI,
                            'Referential integrity failure: ' . $table . ' → ' . $parentTable
                        );
                    }
                } elseif ($parentSeen === []) {
                    return orange_cpr_importlive_fail(
                        ORANGE_CPR_IMPORTLIVE_ERR_RI,
                        'Referential integrity failure: empty parent set for ' . $table
                    );
                }
                unset($parentId);
            }
        }
    }

    return orange_cpr_importlive_ok(['message' => 'ri_ok']);
}

/**
 * @param array<string, mixed> $input
 * @param array<string, array<string, bool>> $importedIndex
 * @return array<string, mixed>
 */
function orange_cpr_import_live_apply_batch_ledger(
    string $cprRoot,
    string $jobId,
    int $batch,
    array $input,
    array &$importedIndex
): array {
    $dir = orange_cpr_import_live_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create import_live directory.');
    }
    $ledgerPath = $dir . DIRECTORY_SEPARATOR . 'target_slice_import_ledger.json';
    $ledger = ['schema_version' => 'cpr_target_slice_import_ledger/1', 'job_id' => $jobId, 'batches' => [], 'imported' => []];
    if (is_file($ledgerPath)) {
        $raw = file_get_contents($ledgerPath);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $ledger = $decoded;
        }
    }

    $rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
    $imported = [];
    $count = 0;
    foreach ($rows as $table => $objs) {
        if (!is_string($table) || !is_array($objs)) {
            throw new RuntimeException('Invalid batch rows for ledger.');
        }
        $ids = [];
        if (!isset($importedIndex[$table])) {
            $importedIndex[$table] = [];
        }
        foreach ($objs as $obj) {
            if (!is_array($obj)) {
                throw new RuntimeException('Invalid row object.');
            }
            if (isset($obj['country_id']) && (int) $obj['country_id'] !== (int) ($input['country_id'] ?? 0)) {
                throw new RuntimeException('Cross-country write blocked at ledger apply.');
            }
            $id = trim((string) ($obj['id'] ?? ''));
            if ($id === '') {
                throw new RuntimeException('Missing row id in ' . $table);
            }
            $ids[] = $id;
            $importedIndex[$table][$id] = true;
            ++$count;
        }
        $imported[$table] = $ids;
        $ledger['imported'][$table] = array_values(array_unique(array_merge(
            is_array($ledger['imported'][$table] ?? null) ? $ledger['imported'][$table] : [],
            $ids
        )));
    }

    $ledger['batches'][(string) $batch] = [
        'batch' => $batch,
        'row_count' => $count,
        'tables' => array_keys($imported),
        'imported' => $imported,
        'input_fingerprint' => (string) ($input['input_fingerprint'] ?? ''),
        'production_sql_executed' => false,
        'updated_at' => gmdate('c'),
    ];
    $ledger['country_id'] = (int) ($input['country_id'] ?? 0);
    $ledger['package_fingerprint'] = (string) ($input['package_fingerprint'] ?? '');
    $ledger['schema_revision'] = (int) ($input['schema_revision'] ?? 0);
    $ledger['production_sql_executed'] = false;
    $ledger['updated_at'] = gmdate('c');

    $json = json_encode($ledger, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($ledgerPath, $json) === false) {
        throw new RuntimeException('Import ledger write failed.');
    }

    $mutationFp = hash(
        'sha256',
        (string) json_encode([
            'batch' => $batch,
            'imported' => $imported,
            'row_count' => $count,
            'input_fingerprint' => (string) ($input['input_fingerprint'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    return [
        'ledger_path' => $ledgerPath,
        'imported' => $imported,
        'rows_imported' => $count,
        'mutation_fingerprint' => $mutationFp,
        'production_sql_executed' => false,
    ];
}

/**
 * Highest sealed completed batch (0 if none).
 */
function orange_cpr_import_live_last_sealed_batch(string $cprRoot, string $jobId): int
{
    $last = 0;
    foreach (orange_cpr_import_batch_numbers() as $b) {
        $rep = orange_cpr_import_live_load_batch_report($cprRoot, $jobId, $b);
        if (is_array($rep) && !empty($rep['batch_complete']) && (int) ($rep['batch'] ?? 0) === $b) {
            $last = $b;
            continue;
        }
        break;
    }

    return $last;
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_import_live_assert_preconditions(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_importlive_fail(
            ORANGE_CPR_IMPORTLIVE_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during P5 IMPORT (Architecture roadmap).'
        );
    }
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_ACTOR, 'Super Admin required for IMPORT.');
    }
    $unsafe = orange_cpr_import_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_STATE, $e->getMessage());
    }
    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_CONTRACT, 'Frozen execution contract required.');
    }
    if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_IDENTITY, 'Contract/job identity drift.');
    }

    $schemaExpected = (int) ($contract['schema_revision_expected'] ?? -1);
    if (isset($request['schema_revision']) && (int) $request['schema_revision'] !== $schemaExpected) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_SCHEMA, 'Request schema_revision mismatch vs contract.');
    }
    $dep = (string) ($contract['dependency_graph_version'] ?? '');
    if ($dep !== '' && $dep !== ORANGE_CPR_IMPORT_DEPENDENCY_GRAPH_VERSION) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_ORDER, 'dependency_graph_version mismatch.');
    }

    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP6')) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_CP6, 'CP6 delete_complete required before IMPORT.');
    }
    $deleteReport = orange_cpr_delete_live_load_latest($cprRoot, $jobId, 'report');
    if (!is_array($deleteReport) || empty($deleteReport['delete_complete']) || !orange_cpr_auth_verify_seal($deleteReport)) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_DELETE, 'Sealed DELETE completion report required.');
    }
    if ((string) ($deleteReport['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($deleteReport['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_IDENTITY, 'DELETE report identity drift.');
    }

    $sessionId = (string) ($contract['session_full_backup_id'] ?? '');
    if ($sessionId === '' || (string) ($deleteReport['session_full_backup_id'] ?? '') !== $sessionId) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_ODPIN, 'Session Full Backup / OD-PIN mismatch.');
    }
    if (isset($request['session_full_backup_id'])
        && (string) $request['session_full_backup_id'] !== $sessionId
    ) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_ODPIN, 'Request session_full_backup_id mismatch.');
    }

    $gates = orange_cpr_gates_live_load_latest($cprRoot, $jobId);
    if (!is_array($gates) || empty($gates['all_gates_pass']) || !orange_cpr_auth_verify_seal($gates)) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_GATE, 'Sealed gates_live PASS required.');
    }
    $auth = orange_cpr_auth_live_load_latest($cprRoot, $jobId);
    if (!is_array($auth) || empty($auth['ponr_authorized']) || !orange_cpr_auth_verify_seal($auth)) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_AUTHORITY, 'Sealed authority_live required.');
    }

    $lockRv = orange_cpr_import_live_assert_lock_ownership($env, $jobId, $request, $job, $contract);
    if (empty($lockRv['ok'])) {
        return $lockRv;
    }

    $state = (string) ($job['state'] ?? '');
    $allowedStates = ['cpr_deleting', 'cpr_importing', 'cpr_paused_import_failed'];
    if (!in_array($state, $allowedStates, true)) {
        return orange_cpr_importlive_fail(
            ORANGE_CPR_IMPORTLIVE_ERR_STATE,
            'IMPORT requires cpr_deleting / cpr_importing / cpr_paused_import_failed.',
            ['job_state' => $state]
        );
    }
    if ($state === 'cpr_deleting' && empty($job['ponr_crossed']) && empty($deleteReport['ponr_crossed'])) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_STATE, 'PONR not crossed before IMPORT.');
    }

    $sliceBuild = orange_cpr_import_live_build_slice_from_delete($cprRoot, $jobId, $job, $contract, $deleteReport);
    if (empty($sliceBuild['ok'])) {
        return $sliceBuild;
    }

    // Optional request slice must not expand beyond DELETE ledger.
    if (isset($request['target_slice']) && is_array($request['target_slice'])) {
        $reqTables = is_array($request['target_slice']['tables'] ?? null) ? $request['target_slice']['tables'] : [];
        $sliceTables = $sliceBuild['slice']['tables'];
        foreach ($reqTables as $t => $ids) {
            $table = is_array($t) ? (string) ($t['table'] ?? '') : (string) $t;
            if (is_int($t) && is_array($ids)) {
                $table = (string) ($ids['table'] ?? '');
                $ids = $ids['row_ids'] ?? [];
            }
            if ($table === '' || !isset($sliceTables[$table])) {
                return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_SCOPE, 'Out-of-slice write attempt: ' . $table);
            }
            if ((int) ($request['target_slice']['country_id'] ?? $sliceBuild['slice']['country_id'])
                !== (int) $sliceBuild['slice']['country_id']
            ) {
                return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_SCOPE, 'Cross-country write attempt.');
            }
        }
    }

    return orange_cpr_importlive_ok([
        'actor_admin_id' => (int) $request['actor_admin_id'],
        'job' => $job,
        'contract' => $contract,
        'cpr_root' => $cprRoot,
        'slice' => $sliceBuild['slice'],
        'delete_report' => $deleteReport,
        'gates' => $gates,
        'authority' => $auth,
        'job_state' => $state,
        'schema_revision' => $schemaExpected,
        'session_full_backup_id' => $sessionId,
    ]);
}

/**
 * Pause IMPORT (OD-FAIL-IMPORT) — no auto-rollback.
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_import_live_pause(array $env, string $jobId, string $reason, int $lastSealed, int $failedBatch): array
{
    $state = (string) (orange_cpr_job_read(orange_cpr_resolve_work_root($env), $jobId)['state'] ?? '');
    $pause = ['ok' => true, 'skipped' => true];
    if ($state === 'cpr_importing') {
        $pause = orange_cpr_transition_apply($env, $jobId, 'cpr_paused_import_failed', [
            'actor' => ORANGE_CPR_ACTOR_SYSTEM,
            'trigger' => 'import_fail',
        ]);
    }

    return [
        'od_fail_import' => 'pause_for_super_admin',
        'auto_rollback' => false,
        'maint_remains_on' => true,
        'last_sealed_batch' => $lastSealed,
        'failed_batch' => $failedBatch,
        'failure_reason' => $reason,
        'current_stage' => 'target_slice_import',
        'progress_percent' => (int) floor(($lastSealed / 6) * 100),
        'completed_batches' => $lastSealed,
        'resume_supported' => $lastSealed >= 0 && $lastSealed < 6,
        'resume_from_batch' => $lastSealed + 1,
        'statement_offset_resume' => false,
        'special_handlers_not_started' => true,
        'uploads_not_started' => true,
        'production_sql_executed' => false,
        'pause_transition' => $pause,
    ];
}

/**
 * Execute live Target-Slice IMPORT batches 1→6 through CP7.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_import_live_run(array $env, string $jobId, array $request = []): array
{
    $pre = orange_cpr_import_live_assert_preconditions($env, $jobId, $request);
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
    $schemaRevision = (int) $pre['schema_revision'];
    $packageFp = (string) ($job['package_fingerprint'] ?? '');
    $countryId = (int) ($job['country_id'] ?? 0);

    $existingSummary = orange_cpr_import_live_load_latest($cprRoot, $jobId, 'summary');
    if (is_array($existingSummary)
        && !empty($existingSummary['import_complete'])
        && orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP7')
    ) {
        return orange_cpr_importlive_ok([
            'message' => 'Target-slice IMPORT already complete (idempotent).',
            'job_id' => $jobId,
            'idempotent' => true,
            'import_summary' => $existingSummary,
            'batches_completed' => 6,
            'checkpoint_id' => 'CP7',
            'job_state' => (string) (orange_cpr_job_read($cprRoot, $jobId)['state'] ?? ''),
            'production_sql_executed' => false,
            'special_handlers_executed' => false,
            'uploads_executed' => false,
        ]);
    }
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP7') && !is_array($existingSummary)) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_REPLAY, 'CP7 exists without sealed IMPORT summary.');
    }

    // Resume / start batch selection.
    $lastSealed = orange_cpr_import_live_last_sealed_batch($cprRoot, $jobId);
    $resumeRequested = array_key_exists('resume_from_batch', $request)
        ? (int) $request['resume_from_batch']
        : null;
    if ($resumeRequested !== null) {
        $expected = $lastSealed + 1;
        if ($resumeRequested !== $expected || $resumeRequested < 1 || $resumeRequested > 6) {
            return orange_cpr_importlive_fail(
                ORANGE_CPR_IMPORTLIVE_ERR_RESUME,
                'Invalid resume point; expected batch ' . (string) $expected . '.',
                ['last_sealed_batch' => $lastSealed, 'resume_from_batch' => $resumeRequested]
            );
        }
    }
    if (!empty($request['force_batch_order']) && is_array($request['force_batch_order'])) {
        $forced = array_map('intval', $request['force_batch_order']);
        if ($forced !== orange_cpr_import_batch_numbers()) {
            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_BATCH_REORDER, 'Batch reorder refused.');
        }
    }
    if (isset($request['start_batch']) && (int) $request['start_batch'] !== ($lastSealed + 1)
        && (int) $request['start_batch'] !== 1
    ) {
        // Explicit non-sequential start without matching resume boundary.
        if ((int) $request['start_batch'] !== $lastSealed + 1) {
            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_BATCH_SKIP, 'Batch skip / non-sequential start refused.');
        }
    }

    // Enter importing state.
    if ($state === 'cpr_deleting') {
        $tr = orange_cpr_transition_apply($env, $jobId, 'cpr_importing', [
            'actor' => ORANGE_CPR_ACTOR_SYSTEM,
            'trigger' => 'delete_complete',
        ]);
        if (empty($tr['ok'])) {
            return orange_cpr_importlive_fail(
                ORANGE_CPR_IMPORTLIVE_ERR_TRANSITION,
                (string) ($tr['message'] ?? 'T10 delete_complete failed.'),
                ['upstream' => $tr]
            );
        }
        $job = orange_cpr_job_read($cprRoot, $jobId);
        $state = (string) ($job['state'] ?? '');
    } elseif ($state === 'cpr_paused_import_failed') {
        if (empty($request['resume_authorized']) || empty($request['actor_is_super_admin'])) {
            return orange_cpr_importlive_fail(
                ORANGE_CPR_IMPORTLIVE_ERR_RESUME,
                'Paused IMPORT requires Super Admin resume_authorized (OD-FAIL-IMPORT).'
            );
        }
        $tr = orange_cpr_transition_apply($env, $jobId, 'cpr_importing', [
            'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
            'actor_admin_id' => $actorAdminId,
            'trigger' => 'resume',
            'safe_resume' => true,
        ]);
        if (empty($tr['ok'])) {
            return orange_cpr_importlive_fail(
                ORANGE_CPR_IMPORTLIVE_ERR_TRANSITION,
                (string) ($tr['message'] ?? 'T41 resume failed.'),
                ['upstream' => $tr]
            );
        }
        $job = orange_cpr_job_read($cprRoot, $jobId);
        $state = (string) ($job['state'] ?? '');
    }
    if ($state !== 'cpr_importing') {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_STATE, 'Expected cpr_importing.', ['job_state' => $state]);
    }

    $lockRv = orange_cpr_import_live_assert_lock_ownership($env, $jobId, $request, $job, $contract);
    if (empty($lockRv['ok'])) {
        $recovery = orange_cpr_import_live_pause($env, $jobId, 'lock_loss', $lastSealed, $lastSealed + 1);

        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_LOCK, 'Lock loss during IMPORT.', [
            'recovery_metadata' => $recovery,
            'upstream' => $lockRv,
        ]);
    }

    // Rebuild imported index from sealed batches for RI.
    /** @var array<string, array<string, bool>> $importedIndex */
    $importedIndex = [];
    foreach (orange_cpr_import_batch_numbers() as $b) {
        if ($b > $lastSealed) {
            break;
        }
        $rep = orange_cpr_import_live_load_batch_report($cprRoot, $jobId, $b);
        $imp = is_array($rep['imported'] ?? null) ? $rep['imported'] : [];
        foreach ($imp as $table => $ids) {
            if (!is_string($table) || !is_array($ids)) {
                continue;
            }
            if (!isset($importedIndex[$table])) {
                $importedIndex[$table] = [];
            }
            foreach ($ids as $id) {
                $importedIndex[$table][(string) $id] = true;
            }
        }
        // Mark empty slice tables that appear in completed DELETE slice for RI parent presence.
        foreach (array_keys($slice['tables']) as $sliceTable) {
            if (!isset($importedIndex[$sliceTable])) {
                $importedIndex[$sliceTable] = [];
            }
        }
    }
    foreach (array_keys($slice['tables']) as $sliceTable) {
        if (!isset($importedIndex[$sliceTable])) {
            $importedIndex[$sliceTable] = [];
        }
    }

    $sourceOverrides = is_array($request['source_batches'] ?? null) ? $request['source_batches'] : [];
    $failAt = isset($request['fail_at_batch']) ? (int) $request['fail_at_batch'] : 0;
    $stopAfter = isset($request['stop_after_batch']) ? (int) $request['stop_after_batch'] : 0;
    $batchReports = [];
    $totalRows = 0;
    $startBatch = $lastSealed + 1;

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.import_live_start',
        'job_id' => $jobId,
        'start_batch' => $startBatch,
        'last_sealed_batch' => $lastSealed,
        'actor_admin_id' => $actorAdminId,
        'production_sql_executed' => false,
        'enablement_flag_observed' => false,
    ]);

    foreach (orange_cpr_import_batch_numbers() as $batch) {
        if ($batch < $startBatch) {
            $prev = orange_cpr_import_live_load_batch_report($cprRoot, $jobId, $batch);
            if (is_array($prev)) {
                $batchReports[$batch] = $prev;
                $totalRows += (int) ($prev['rows_imported'] ?? 0);
            }
            continue;
        }

        // Prior batch must be sealed (except batch 1).
        if ($batch > 1) {
            $prev = orange_cpr_import_live_load_batch_report($cprRoot, $jobId, $batch - 1);
            if (!is_array($prev) || empty($prev['batch_complete'])) {
                $recovery = orange_cpr_import_live_pause($env, $jobId, 'prior_batch_unsealed', $batch - 2, $batch);

                return orange_cpr_importlive_fail(
                    ORANGE_CPR_IMPORTLIVE_ERR_BATCH_SKIP,
                    'Cannot start batch ' . (string) $batch . ' before prior batch sealed.',
                    ['recovery_metadata' => $recovery]
                );
            }
        }

        // Replay of already-complete batch refused when forcing re-run.
        $existingBatch = orange_cpr_import_live_load_batch_report($cprRoot, $jobId, $batch);
        if (is_array($existingBatch) && !empty($existingBatch['batch_complete'])) {
            if (!empty($request['force_replay_batch']) && (int) $request['force_replay_batch'] === $batch) {
                return orange_cpr_importlive_fail(
                    ORANGE_CPR_IMPORTLIVE_ERR_REPLAY,
                    'Replay of completed batch ' . (string) $batch . ' forbidden.'
                );
            }
            $batchReports[$batch] = $existingBatch;
            $totalRows += (int) ($existingBatch['rows_imported'] ?? 0);
            continue;
        }

        if ($failAt === $batch) {
            $recovery = orange_cpr_import_live_pause(
                $env,
                $jobId,
                'injected_failure_batch_' . (string) $batch,
                $batch - 1,
                $batch
            );
            orange_cpr_audit_append($cprRoot, $jobId, [
                'event_type' => 'cpr.import_live_batch_fail',
                'job_id' => $jobId,
                'batch' => $batch,
                'actor_admin_id' => $actorAdminId,
                'recovery_metadata' => $recovery,
                'production_sql_executed' => false,
            ]);

            return orange_cpr_importlive_fail(
                ORANGE_CPR_IMPORTLIVE_ERR_BATCH_FAIL,
                'IMPORT failed during batch ' . (string) $batch . '.',
                [
                    'failed_batch' => $batch,
                    'last_sealed_batch' => $batch - 1,
                    'recovery_metadata' => $recovery,
                ]
            );
        }

        $lockRv = orange_cpr_import_live_assert_lock_ownership($env, $jobId, $request, $job, $contract);
        if (empty($lockRv['ok'])) {
            $recovery = orange_cpr_import_live_pause($env, $jobId, 'lock_loss', $batch - 1, $batch);

            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_LOCK, 'Lock loss before batch ' . (string) $batch, [
                'recovery_metadata' => $recovery,
            ]);
        }

        $override = null;
        if (isset($sourceOverrides[(string) $batch]) && is_array($sourceOverrides[(string) $batch])) {
            $override = $sourceOverrides[(string) $batch];
        } elseif (isset($sourceOverrides[$batch]) && is_array($sourceOverrides[$batch])) {
            $override = $sourceOverrides[$batch];
        }
        if (is_array($override) && !empty($override['corrupt'])) {
            $recovery = orange_cpr_import_live_pause($env, $jobId, 'corrupt_source', $batch - 1, $batch);

            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_SOURCE, 'Corrupt batch input refused.', [
                'failed_batch' => $batch,
                'recovery_metadata' => $recovery,
            ]);
        }

        $resolved = orange_cpr_import_live_resolve_batch_input(
            $batch,
            $slice,
            $override,
            $countryId,
            $packageFp,
            $schemaRevision
        );
        if (empty($resolved['ok'])) {
            $recovery = orange_cpr_import_live_pause(
                $env,
                $jobId,
                (string) ($resolved['code'] ?? 'source'),
                $batch - 1,
                $batch
            );

            return array_merge($resolved, ['recovery_metadata' => $recovery, 'failed_batch' => $batch]);
        }
        /** @var array<string, mixed> $input */
        $input = $resolved['input'];

        if (!empty($request['force_fingerprint_mismatch']) && (int) $request['force_fingerprint_mismatch'] === $batch) {
            $input['input_fingerprint'] = str_repeat('0', 64);
        }

        $ri = orange_cpr_import_live_check_referential_integrity($input, $importedIndex);
        if (empty($ri['ok'])) {
            $recovery = orange_cpr_import_live_pause($env, $jobId, 'referential_integrity', $batch - 1, $batch);

            return array_merge($ri, ['recovery_metadata' => $recovery, 'failed_batch' => $batch]);
        }

        $now = gmdate('c');
        $inputManifestId = orange_cpr_generate_job_id();
        $mutationManifestId = orange_cpr_generate_job_id();
        $batchReportId = orange_cpr_generate_job_id();

        $inputManifest = [
            'schema_version' => ORANGE_CPR_IMPORT_LIVE_SCHEMA,
            'engine_version' => ORANGE_CPR_IMPORT_LIVE_VERSION,
            'record_type' => 'cpr_import_input_manifest',
            'manifest_id' => $inputManifestId,
            'job_id' => $jobId,
            'batch' => $batch,
            'country_id' => $countryId,
            'package_fingerprint' => $packageFp,
            'schema_revision' => $schemaRevision,
            'session_full_backup_id' => (string) $pre['session_full_backup_id'],
            'import_order_version' => ORANGE_CPR_IMPORT_ORDER_VERSION,
            'tables' => $input['tables'],
            'row_count' => (int) $input['row_count'],
            'input_fingerprint' => (string) $input['input_fingerprint'],
            'production_sql_executed' => false,
            'created_at' => $now,
        ];

        try {
            $apply = orange_cpr_import_live_apply_batch_ledger($cprRoot, $jobId, $batch, $input, $importedIndex);
        } catch (Throwable $e) {
            $recovery = orange_cpr_import_live_pause($env, $jobId, $e->getMessage(), $batch - 1, $batch);

            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_PERSIST, $e->getMessage(), [
                'failed_batch' => $batch,
                'recovery_metadata' => $recovery,
            ]);
        }

        if (!empty($request['force_count_mismatch']) && (int) $request['force_count_mismatch'] === $batch) {
            $recovery = orange_cpr_import_live_pause($env, $jobId, 'count_mismatch', $batch - 1, $batch);

            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_COUNT, 'Row count mismatch on batch ' . (string) $batch, [
                'failed_batch' => $batch,
                'recovery_metadata' => $recovery,
            ]);
        }
        if ((int) $apply['rows_imported'] !== (int) $input['row_count']) {
            $recovery = orange_cpr_import_live_pause($env, $jobId, 'count_mismatch', $batch - 1, $batch);

            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_COUNT, 'Applied row count mismatch.', [
                'failed_batch' => $batch,
                'recovery_metadata' => $recovery,
            ]);
        }
        if (!empty($request['expect_mutation_fingerprint'])
            && is_array($request['expect_mutation_fingerprint'])
            && isset($request['expect_mutation_fingerprint'][$batch])
            && (string) $request['expect_mutation_fingerprint'][$batch] !== (string) $apply['mutation_fingerprint']
        ) {
            $recovery = orange_cpr_import_live_pause($env, $jobId, 'fingerprint_mismatch', $batch - 1, $batch);

            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_FINGERPRINT, 'Mutation fingerprint mismatch.', [
                'failed_batch' => $batch,
                'recovery_metadata' => $recovery,
            ]);
        }

        $mutationManifest = [
            'schema_version' => ORANGE_CPR_IMPORT_LIVE_SCHEMA,
            'engine_version' => ORANGE_CPR_IMPORT_LIVE_VERSION,
            'record_type' => 'cpr_import_mutation_manifest',
            'manifest_id' => $mutationManifestId,
            'input_manifest_id' => $inputManifestId,
            'job_id' => $jobId,
            'batch' => $batch,
            'country_id' => $countryId,
            'package_fingerprint' => $packageFp,
            'schema_revision' => $schemaRevision,
            'import_order_version' => ORANGE_CPR_IMPORT_ORDER_VERSION,
            'tables' => array_keys($apply['imported']),
            'imported' => $apply['imported'],
            'rows_imported' => (int) $apply['rows_imported'],
            'input_fingerprint' => (string) $input['input_fingerprint'],
            'mutation_fingerprint' => (string) $apply['mutation_fingerprint'],
            'production_sql_executed' => false,
            'special_handlers_executed' => false,
            'uploads_executed' => false,
            'created_at' => $now,
        ];

        $batchReport = [
            'schema_version' => ORANGE_CPR_IMPORT_LIVE_SCHEMA,
            'engine_version' => ORANGE_CPR_IMPORT_LIVE_VERSION,
            'record_type' => 'cpr_import_batch_execution_report',
            'batch_report_id' => $batchReportId,
            'input_manifest_id' => $inputManifestId,
            'mutation_manifest_id' => $mutationManifestId,
            'job_id' => $jobId,
            'batch' => $batch,
            'batch_complete' => true,
            'country_id' => $countryId,
            'package_fingerprint' => $packageFp,
            'schema_revision' => $schemaRevision,
            'session_full_backup_id' => (string) $pre['session_full_backup_id'],
            'import_order_version' => ORANGE_CPR_IMPORT_ORDER_VERSION,
            'tables' => orange_cpr_import_batch_tables($batch),
            'imported' => $apply['imported'],
            'rows_imported' => (int) $apply['rows_imported'],
            'input_fingerprint' => (string) $input['input_fingerprint'],
            'mutation_fingerprint' => (string) $apply['mutation_fingerprint'],
            'recovery_metadata' => [
                'last_sealed_batch' => $batch,
                'resume_from_batch' => $batch < 6 ? $batch + 1 : null,
                'statement_offset_resume' => false,
                'od_fail_import' => 'pause_for_super_admin',
                'production_sql_executed' => false,
            ],
            'actor_admin_id' => $actorAdminId,
            'production_sql_executed' => false,
            'special_handlers_executed' => false,
            'uploads_executed' => false,
            'enablement_flag_observed' => false,
            'created_at' => $now,
        ];

        try {
            orange_cpr_import_live_persist($cprRoot, $jobId, 'cpr_import_batch_' . $batch . '_input_' . $inputManifestId, $inputManifest);
            orange_cpr_import_live_persist($cprRoot, $jobId, 'cpr_import_batch_' . $batch . '_mutation_' . $mutationManifestId, $mutationManifest);
            $reportPath = orange_cpr_import_live_persist(
                $cprRoot,
                $jobId,
                'cpr_import_batch_' . $batch . '_report_' . $batchReportId,
                $batchReport
            );
            $latestBatch = orange_cpr_import_live_batch_report_path($cprRoot, $jobId, $batch);
            $tmp = $latestBatch . '.tmp.' . bin2hex(random_bytes(4));
            $sealedBatch = orange_cpr_auth_seal(array_merge($batchReport, ['batch_report_path' => $reportPath]));
            $json = json_encode($sealedBatch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false || @file_put_contents($tmp, $json) === false) {
                throw new RuntimeException('Batch latest pointer write failed.');
            }
            orange_cpr_atomic_rename_replace($tmp, $latestBatch);
        } catch (RuntimeException $e) {
            $recovery = orange_cpr_import_live_pause($env, $jobId, $e->getMessage(), $batch - 1, $batch);

            return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_PERSIST, $e->getMessage(), [
                'failed_batch' => $batch,
                'recovery_metadata' => $recovery,
            ]);
        }

        $lastSealed = $batch;
        $batchReports[$batch] = orange_cpr_import_live_load_batch_report($cprRoot, $jobId, $batch);
        $totalRows += (int) $apply['rows_imported'];

        orange_cpr_audit_append($cprRoot, $jobId, [
            'event_type' => 'cpr.import_live_batch_complete',
            'job_id' => $jobId,
            'batch' => $batch,
            'batch_report_id' => $batchReportId,
            'rows_imported' => (int) $apply['rows_imported'],
            'input_fingerprint' => (string) $input['input_fingerprint'],
            'mutation_fingerprint' => (string) $apply['mutation_fingerprint'],
            'actor_admin_id' => $actorAdminId,
            'production_sql_executed' => false,
        ]);

        if ($stopAfter === $batch && $batch < 6) {
            return orange_cpr_importlive_ok([
                'message' => 'Stopped after sealed batch ' . (string) $batch . ' (resume boundary).',
                'job_id' => $jobId,
                'stopped_after_batch' => $batch,
                'last_sealed_batch' => $batch,
                'resume_from_batch' => $batch + 1,
                'batches_completed' => $batch,
                'rows_imported' => $totalRows,
                'job_state' => 'cpr_importing',
                'import_complete' => false,
                'production_sql_executed' => false,
                'special_handlers_executed' => false,
                'uploads_executed' => false,
                'recovery_metadata' => [
                    'last_sealed_batch' => $batch,
                    'resume_from_batch' => $batch + 1,
                    'statement_offset_resume' => false,
                    'production_sql_executed' => false,
                ],
            ]);
        }
    }

    if ($lastSealed !== 6) {
        return orange_cpr_importlive_fail(
            ORANGE_CPR_IMPORTLIVE_ERR_STATE,
            'IMPORT incomplete; last sealed batch=' . (string) $lastSealed
        );
    }

    $now = gmdate('c');
    $summaryId = orange_cpr_generate_job_id();
    $batchesCompleted = [1, 2, 3, 4, 5, 6];
    $summary = [
        'schema_version' => ORANGE_CPR_IMPORT_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_IMPORT_LIVE_VERSION,
        'record_type' => 'cpr_import_execution_summary',
        'import_summary_id' => $summaryId,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'import_complete' => true,
        'batches_completed' => $batchesCompleted,
        'rows_imported' => $totalRows,
        'country_id' => $countryId,
        'country_code' => (string) ($job['country_code'] ?? ''),
        'package_fingerprint' => $packageFp,
        'schema_revision' => $schemaRevision,
        'session_full_backup_id' => (string) $pre['session_full_backup_id'],
        'import_order_version' => ORANGE_CPR_IMPORT_ORDER_VERSION,
        'dependency_graph_version' => ORANGE_CPR_IMPORT_DEPENDENCY_GRAPH_VERSION,
        'batch_fingerprints' => [],
        'recovery_metadata' => [
            'od_fail_import' => 'pause_for_super_admin',
            'auto_rollback' => false,
            'completed_phase' => 'target_slice_import',
            'execution_status' => 'import_complete',
            'maint_remains_on' => true,
            'special_handlers_not_started' => true,
            'uploads_not_started' => true,
            'ponr_crossed' => true,
            'production_sql_executed' => false,
            'last_sealed_batch' => 6,
            'statement_offset_resume' => false,
        ],
        'actor_admin_id' => $actorAdminId,
        'ops_enablement_flag' => false,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'special_handlers_executed' => false,
        'uploads_executed' => false,
        'enablement_flag_observed' => false,
        'created_at' => $now,
    ];
    foreach ($batchesCompleted as $b) {
        $br = $batchReports[$b] ?? orange_cpr_import_live_load_batch_report($cprRoot, $jobId, $b);
        $summary['batch_fingerprints'][$b] = [
            'input_fingerprint' => (string) ($br['input_fingerprint'] ?? ''),
            'mutation_fingerprint' => (string) ($br['mutation_fingerprint'] ?? ''),
            'rows_imported' => (int) ($br['rows_imported'] ?? 0),
        ];
    }
    $summary['summary_fingerprint'] = hash(
        'sha256',
        (string) json_encode([
            'batches_completed' => $batchesCompleted,
            'rows_imported' => $totalRows,
            'batch_fingerprints' => $summary['batch_fingerprints'],
            'package_fingerprint' => $packageFp,
            'schema_revision' => $schemaRevision,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    try {
        $summaryPath = orange_cpr_import_live_persist($cprRoot, $jobId, 'cpr_import_summary_' . $summaryId, $summary);
        $latest = orange_cpr_import_live_latest_path($cprRoot, $jobId, 'summary');
        $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
        $sealed = orange_cpr_auth_seal(array_merge($summary, ['import_summary_path' => $summaryPath]));
        $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('IMPORT summary latest write failed.');
        }
        orange_cpr_atomic_rename_replace($tmp, $latest);
    } catch (RuntimeException $e) {
        return orange_cpr_importlive_fail(ORANGE_CPR_IMPORTLIVE_ERR_PERSIST, $e->getMessage());
    }

    $cp7 = orange_cpr_checkpoint_create($env, $jobId, 'CP7', [
        'batches_completed' => $batchesCompleted,
        'rows_imported' => $totalRows,
    ], ['written_by' => 'system']);
    if (empty($cp7['ok'])) {
        return orange_cpr_importlive_fail(
            ORANGE_CPR_IMPORTLIVE_ERR_CHECKPOINT,
            (string) ($cp7['message'] ?? 'CP7 write failed.'),
            ['upstream' => $cp7]
        );
    }

    $jobAfter = orange_cpr_job_read($cprRoot, $jobId);
    $engines = is_array($jobAfter['mutation_engines'] ?? null) ? $jobAfter['mutation_engines'] : [];
    $engines['delete'] = true;
    $engines['import'] = true;
    $engines['uploads'] = false;
    $engines['special_handlers'] = false;
    $engines['ponr'] = true;
    $jobAfter['mutation_engines'] = $engines;
    $jobAfter['ponr_mutation_executed'] = false;
    $jobAfter['import_live_complete'] = true;
    $jobAfter['import_summary_id'] = $summaryId;
    $jobAfter['updated_at'] = $now;
    orange_cpr_job_write($cprRoot, $jobId, $jobAfter);

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.import_live_complete',
        'job_id' => $jobId,
        'import_summary_id' => $summaryId,
        'checkpoint_id' => 'CP7',
        'batches_completed' => $batchesCompleted,
        'rows_imported' => $totalRows,
        'summary_fingerprint' => $summary['summary_fingerprint'],
        'special_handlers_executed' => false,
        'uploads_executed' => false,
        'actor_admin_id' => $actorAdminId,
        'production_sql_executed' => false,
        'enablement_flag_observed' => false,
    ]);

    $jobFinal = orange_cpr_job_read($cprRoot, $jobId);
    if ((string) ($jobFinal['state'] ?? '') !== 'cpr_importing') {
        return orange_cpr_importlive_fail(
            ORANGE_CPR_IMPORTLIVE_ERR_STATE,
            'Expected job to remain cpr_importing after IMPORT (uploads/special disabled).',
            ['job_state' => (string) ($jobFinal['state'] ?? '')]
        );
    }

    return orange_cpr_importlive_ok([
        'message' => 'Target-slice IMPORT batches 1→6 complete through CP7; special/uploads disabled.',
        'job_id' => $jobId,
        'import_summary_id' => $summaryId,
        'import_summary' => orange_cpr_import_live_load_latest($cprRoot, $jobId, 'summary'),
        'batches_completed' => $batchesCompleted,
        'rows_imported' => $totalRows,
        'checkpoint_id' => 'CP7',
        'job_state' => 'cpr_importing',
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'special_handlers_executed' => false,
        'uploads_executed' => false,
        'enablement_flag_observed' => false,
        'recovery_metadata' => $summary['recovery_metadata'],
    ]);
}
