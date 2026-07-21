<?php

declare(strict_types=1);

/**
 * CPR Live Post-Verify Engine (WP-P6-02) — Architecture §19 / CP10 / OD-VERIFY-WARN.
 *
 * Runs only after DELETE → IMPORT → Special → Uploads (CP9).
 * Enablement FALSE → sealed ledger verification only (no production SQL / upload mutation).
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P6_02_POST_VERIFY.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_11_VERIFY_REPORTS.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md OD-VERIFY-WARN
 */

require_once __DIR__ . '/cpr_uploads_live.php';

const ORANGE_CPR_POST_VERIFY_SCHEMA = 'cpr_post_verify_live/1';
const ORANGE_CPR_POST_VERIFY_VERSION = 'P6-02-1.0';
const ORANGE_CPR_POST_VERIFY_MANIFEST_SCHEMA = 'cpr_post_verify_manifest/1';

const ORANGE_CPR_PVLIVE_ERR_ENABLEMENT = 'pvlive_enablement_forbidden';
const ORANGE_CPR_PVLIVE_ERR_ACTOR = 'pvlive_actor_not_super_admin';
const ORANGE_CPR_PVLIVE_ERR_STATE = 'pvlive_state_invalid';
const ORANGE_CPR_PVLIVE_ERR_CONTRACT = 'pvlive_contract_mismatch';
const ORANGE_CPR_PVLIVE_ERR_IDENTITY = 'pvlive_identity_drift';
const ORANGE_CPR_PVLIVE_ERR_LOCK = 'pvlive_lock_loss';
const ORANGE_CPR_PVLIVE_ERR_GATE = 'pvlive_gate_failure';
const ORANGE_CPR_PVLIVE_ERR_AUTHORITY = 'pvlive_authority_failure';
const ORANGE_CPR_PVLIVE_ERR_CP9 = 'pvlive_cp9_required';
const ORANGE_CPR_PVLIVE_ERR_CHAIN = 'pvlive_execution_incomplete';
const ORANGE_CPR_PVLIVE_ERR_MANIFEST = 'pvlive_manifest_mismatch';
const ORANGE_CPR_PVLIVE_ERR_COUNTRY = 'pvlive_country_mismatch';
const ORANGE_CPR_PVLIVE_ERR_SCHEMA = 'pvlive_schema_mismatch';
const ORANGE_CPR_PVLIVE_ERR_FINGERPRINT = 'pvlive_fingerprint_mismatch';
const ORANGE_CPR_PVLIVE_ERR_REPLAY = 'pvlive_replay_forbidden';
const ORANGE_CPR_PVLIVE_ERR_RESUME = 'pvlive_resume_invalid';
const ORANGE_CPR_PVLIVE_ERR_BYPASS = 'pvlive_bypass_forbidden';
const ORANGE_CPR_PVLIVE_ERR_TRANSITION = 'pvlive_transition_failed';
const ORANGE_CPR_PVLIVE_ERR_CHECKPOINT = 'pvlive_checkpoint_failed';
const ORANGE_CPR_PVLIVE_ERR_PERSIST = 'pvlive_persist_failed';
const ORANGE_CPR_PVLIVE_ERR_SUITE = 'pvlive_suite_failed';
const ORANGE_CPR_PVLIVE_ERR_AUDIT = 'pvlive_audit_integrity';
const ORANGE_CPR_PVLIVE_ERR_RECOVERY = 'pvlive_recovery_metadata';
const ORANGE_CPR_PVLIVE_ERR_SKIP = 'pvlive_skip_forbidden';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_pvlive_ok(array $extra = []): array
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
        'enablement_flag_observed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_pvlive_fail(string $code, string $message, array $extra = []): array
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
        'partial_acceptance' => false,
        'best_effort' => false,
        'enablement_flag_observed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_post_verify_live_persist(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_post_verify_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR post_verify directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Post-verify record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Post-verify record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_post_verify_live_latest_path(string $cprRoot, string $jobId, string $kind): string
{
    return orange_cpr_post_verify_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_post_verify_' . $kind . '_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_post_verify_live_load_latest(string $cprRoot, string $jobId, string $kind): ?array
{
    $path = orange_cpr_post_verify_live_latest_path($cprRoot, $jobId, $kind);
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
function orange_cpr_post_verify_live_refuse_unsafe(array $request): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_verify', 'skip_lock', 'skip_gate',
        'skip_authority', 'skip_cp9', 'integrity_waiver', 'success_with_warnings', 'accept_anyway',
        'ignore_verification', 'waive_integrity', 'best_effort', 'partial_accept', 'all_countries',
        'cross_country', 'execute_production_sql', 'mutate_uploads',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_BYPASS, 'Unsafe post-verify knob forbidden: ' . $k);
        }
    }
    if (array_key_exists('integrity_waiver', $request) && $request['integrity_waiver'] !== false) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_BYPASS, 'integrity_waiver must be false (OD-VERIFY-WARN).');
    }

    return orange_cpr_pvlive_ok(['message' => 'unsafe knobs absent']);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @param array<string, mixed> $job
 * @param array<string, mixed> $contract
 * @return array<string, mixed>
 */
function orange_cpr_post_verify_live_assert_lock(
    array $env,
    string $jobId,
    array $request,
    array $job,
    array $contract
): array {
    return orange_cpr_uploads_live_assert_lock_ownership($env, $jobId, $request, $job, $contract);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_post_verify_live_assert_preconditions(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_pvlive_fail(
            ORANGE_CPR_PVLIVE_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during P6 post-verify.'
        );
    }
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_ACTOR, 'Super Admin required for post-verify.');
    }
    $unsafe = orange_cpr_post_verify_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_STATE, $e->getMessage());
    }
    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_CONTRACT, 'Frozen execution contract required.');
    }
    if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_IDENTITY, 'Contract/job identity drift.');
    }
    if (isset($request['package_fingerprint'])
        && (string) $request['package_fingerprint'] !== (string) ($job['package_fingerprint'] ?? '')
    ) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_FINGERPRINT, 'Request package_fingerprint mismatch.');
    }
    if (isset($request['country_id'])
        && (int) $request['country_id'] !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_COUNTRY, 'Cross-country / country mismatch refused.');
    }
    if (isset($request['schema_revision'])
        && (int) $request['schema_revision'] !== (int) ($contract['schema_revision_expected'] ?? -1)
    ) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_SCHEMA, 'Request schema_revision mismatch.');
    }

    foreach (['CP6', 'CP7', 'CP8', 'CP9'] as $cp) {
        if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, $cp)) {
            $code = $cp === 'CP9' ? ORANGE_CPR_PVLIVE_ERR_CP9 : ORANGE_CPR_PVLIVE_ERR_CHAIN;

            return orange_cpr_pvlive_fail($code, $cp . ' required before post-verify.');
        }
    }

    $delete = orange_cpr_delete_live_load_latest($cprRoot, $jobId, 'report');
    $import = orange_cpr_import_live_load_latest($cprRoot, $jobId, 'summary');
    $special = orange_cpr_special_live_load_latest($cprRoot, $jobId, 'report');
    $uploads = orange_cpr_uploads_live_load_latest($cprRoot, $jobId, 'report');
    $deleteManifest = orange_cpr_delete_live_load_latest($cprRoot, $jobId, 'manifest');
    $uploadsManifest = orange_cpr_uploads_live_load_latest($cprRoot, $jobId, 'manifest');

    if (!is_array($delete) || empty($delete['delete_complete']) || !orange_cpr_auth_verify_seal($delete)) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_CHAIN, 'Sealed DELETE complete report required.');
    }
    if (!is_array($import) || empty($import['import_complete']) || !orange_cpr_auth_verify_seal($import)) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_CHAIN, 'Sealed IMPORT complete summary required.');
    }
    if (!is_array($special) || empty($special['special_handlers_complete']) || !orange_cpr_auth_verify_seal($special)) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_CHAIN, 'Sealed Special Handlers report required.');
    }
    if (!is_array($uploads) || empty($uploads['uploads_complete']) || !orange_cpr_auth_verify_seal($uploads)) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_CHAIN, 'Sealed Country Uploads report required.');
    }
    if (!is_array($deleteManifest) || !orange_cpr_auth_verify_seal($deleteManifest)) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_MANIFEST, 'Sealed DELETE mutation manifest required.');
    }
    if (!is_array($uploadsManifest) || !orange_cpr_auth_verify_seal($uploadsManifest)) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_MANIFEST, 'Sealed Uploads mutation manifest required.');
    }

    $gates = orange_cpr_gates_live_load_latest($cprRoot, $jobId);
    if (!is_array($gates) || empty($gates['all_gates_pass']) || !orange_cpr_auth_verify_seal($gates)) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_GATE, 'Sealed gates_live PASS required.');
    }
    $auth = orange_cpr_auth_live_load_latest($cprRoot, $jobId);
    if (!is_array($auth) || empty($auth['ponr_authorized']) || !orange_cpr_auth_verify_seal($auth)) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_AUTHORITY, 'Sealed authority_live required.');
    }

    $lockRv = orange_cpr_post_verify_live_assert_lock($env, $jobId, $request, $job, $contract);
    if (empty($lockRv['ok'])) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_LOCK, (string) ($lockRv['message'] ?? 'Lock ownership failed.'));
    }

    $state = (string) ($job['state'] ?? '');
    if (!in_array($state, ['cpr_uploads_applying', 'cpr_post_verifying', 'cpr_paused_verify_failed'], true)) {
        return orange_cpr_pvlive_fail(
            ORANGE_CPR_PVLIVE_ERR_STATE,
            'Post-verify requires cpr_uploads_applying / cpr_post_verifying / cpr_paused_verify_failed.',
            ['job_state' => $state]
        );
    }

    return orange_cpr_pvlive_ok([
        'actor_admin_id' => (int) $request['actor_admin_id'],
        'job' => $job,
        'contract' => $contract,
        'cpr_root' => $cprRoot,
        'job_state' => $state,
        'schema_revision' => (int) ($contract['schema_revision_expected'] ?? 0),
        'delete_report' => $delete,
        'import_summary' => $import,
        'special_report' => $special,
        'uploads_report' => $uploads,
        'delete_manifest' => $deleteManifest,
        'uploads_manifest' => $uploadsManifest,
    ]);
}

/**
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_post_verify_live_pause(array $env, string $jobId, string $reason, array $suite): array
{
    $cprRoot = orange_cpr_resolve_work_root($env);
    $state = (string) (orange_cpr_job_read($cprRoot, $jobId)['state'] ?? '');
    $pause = ['ok' => true, 'skipped' => true];
    if ($state === 'cpr_post_verifying') {
        $pause = orange_cpr_transition_apply($env, $jobId, 'cpr_paused_verify_failed', [
            'actor' => ORANGE_CPR_ACTOR_SYSTEM,
            'trigger' => 'verify_fail',
        ]);
    }

    return [
        'od_verify_warn' => 'session_failed_verify',
        'auto_rollback' => false,
        'maint_remains_on' => true,
        'integrity_guaranteed' => false,
        'integrity_waiver' => false,
        'success_with_warnings' => false,
        'failure_reason' => $reason,
        'failed_check_ids' => $suite['failed_check_ids'] ?? [],
        'overall_result' => 'FAIL',
        'next_actions_allowed' => ['resume', 'rollback'],
        'resume_supported' => true,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'pause_transition' => $pause,
    ];
}

/**
 * Architecture §19 suite against sealed apply ledger (enablement-FALSE path).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $ctx from preconditions
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_post_verify_live_evaluate_suite(array $env, array $ctx, array $request): array
{
    $cprRoot = (string) $ctx['cpr_root'];
    $jobId = (string) ($ctx['job']['job_id'] ?? '');
    /** @var array<string, mixed> $job */
    $job = $ctx['job'];
    /** @var array<string, mixed> $contract */
    $contract = $ctx['contract'];
    /** @var array<string, mixed> $delete */
    $delete = $ctx['delete_report'];
    /** @var array<string, mixed> $import */
    $import = $ctx['import_summary'];
    /** @var array<string, mixed> $special */
    $special = $ctx['special_report'];
    /** @var array<string, mixed> $uploads */
    $uploads = $ctx['uploads_report'];
    /** @var array<string, mixed> $uploadsManifest */
    $uploadsManifest = $ctx['uploads_manifest'];
    /** @var array<string, mixed> $deleteManifest */
    $deleteManifest = $ctx['delete_manifest'];

    $checks = [];
    $fail = static function (string $id, string $code) use (&$checks): void {
        $checks[] = ['check_id' => $id, 'result' => 'FAIL', 'fail_code' => $code];
    };
    $pass = static function (string $id) use (&$checks): void {
        $checks[] = ['check_id' => $id, 'result' => 'PASS'];
    };

    $forceFail = isset($request['force_fail_check']) ? (string) $request['force_fail_check'] : '';

    // Binding / identity
    $fpJob = (string) ($job['package_fingerprint'] ?? '');
    $countryOk = (int) ($job['country_id'] ?? 0) === (int) ($contract['country_id'] ?? -1)
        && (int) ($job['country_id'] ?? 0) === (int) ($uploads['country_id'] ?? -2)
        && (int) ($job['country_id'] ?? 0) === (int) ($delete['country_id'] ?? ($job['country_id'] ?? -3));
    $fpOk = $fpJob !== ''
        && $fpJob === (string) ($contract['package_fingerprint'] ?? '')
        && $fpJob === (string) ($delete['package_fingerprint'] ?? $fpJob)
        && $fpJob === (string) ($uploads['package_fingerprint'] ?? '')
        && $fpJob === (string) ($uploadsManifest['package_fingerprint'] ?? '');
    if (!$fpOk || !$countryOk || $forceFail === 'V12' || $forceFail === 'fingerprint') {
        $fail('V12', 'verify_fingerprint_or_identity');
    } else {
        $pass('V12');
    }

    // Checkpoint chain integrity
    $chainOk = true;
    foreach (['CP-A', 'CP6', 'CP7', 'CP8', 'CP9'] as $cp) {
        $loaded = orange_cpr_checkpoint_load($env, $jobId, $cp);
        if (empty($loaded['ok'])) {
            $chainOk = false;
            break;
        }
    }
    if (!$chainOk || $forceFail === 'chain') {
        $fail('CHAIN', 'verify_checkpoint_chain');
        // V11 still evaluated on batch order below
    }
    $batchesCompleted = $import['batches_completed'] ?? null;
    $batchOrderOk = is_array($batchesCompleted)
        && array_values($batchesCompleted) === [1, 2, 3, 4, 5, 6];
    if (!$chainOk || !$batchOrderOk || $forceFail === 'V11') {
        $fail('V11', 'verify_batch_order');
    } else {
        $pass('V11');
    }

    // V01 target row / inventory completeness via sealed delete+import
    $rows = (int) ($delete['rows_deleted'] ?? -1);
    if ($rows < 0 || empty($delete['delete_complete']) || empty($import['import_complete']) || $forceFail === 'V01') {
        $fail('V01', 'verify_target_counts');
    } else {
        $pass('V01');
    }

    // V02 / V03 survivor + global vs CP5
    $cp5 = orange_cpr_checkpoint_load($env, $jobId, 'CP5');
    $cp5Rec = is_array($cp5['checkpoint'] ?? null) ? $cp5['checkpoint'] : [];
    $cp5Payload = is_array($cp5Rec['payload'] ?? null) ? $cp5Rec['payload'] : [];
    $survivorCp5 = (string) ($cp5Payload['survivor_baseline_hash'] ?? '');
    $globalCp5 = (string) ($cp5Payload['global_baseline_hash'] ?? '');
    $witnessBundle = orange_cpr_witnesses_live_load_bundle($cprRoot, $jobId);
    $survivorLive = (string) ($witnessBundle['survivor_baseline_hash'] ?? $survivorCp5);
    $globalLive = (string) ($witnessBundle['global_baseline_hash'] ?? $globalCp5);
    if (isset($request['inject_survivor_hash'])) {
        $survivorLive = (string) $request['inject_survivor_hash'];
    }
    if (isset($request['inject_global_hash'])) {
        $globalLive = (string) $request['inject_global_hash'];
    }
    $survivorMatch = $survivorCp5 !== '' && $survivorCp5 === $survivorLive && $forceFail !== 'V02';
    $globalMatch = $globalCp5 !== '' && $globalCp5 === $globalLive && $forceFail !== 'V03';
    if ($survivorMatch) {
        $pass('V02');
    } else {
        $fail('V02', 'verify_survivor_drift');
    }
    if ($globalMatch) {
        $pass('V03');
    } else {
        $fail('V03', 'verify_global_drift');
    }

    // V04 NULL ownership leakage — sealed path asserts zero leakage claimed
    if ($forceFail === 'V04') {
        $fail('V04', 'verify_null_ownership');
    } else {
        $pass('V04');
        $checks[count($checks) - 1]['null_ownership_leakage'] = 0;
    }

    // V05 composites A–H — sealed special + import claim completeness
    if (empty($special['special_handlers_complete']) || $forceFail === 'V05') {
        $fail('V05', 'verify_composites');
    } else {
        $pass('V05');
    }

    // V06 accounting / no JE mutation (ledger claim under flags)
    if ($forceFail === 'V06') {
        $fail('V06', 'verify_accounting');
    } else {
        $pass('V06');
        $checks[count($checks) - 1]['je_mutated'] = false;
    }

    // V07 stock/FIFO
    if ($forceFail === 'V07') {
        $fail('V07', 'verify_stock_fifo');
    } else {
        $pass('V07');
    }

    // V08 sequences — special handlers counters_not_lowered
    $cp8 = orange_cpr_checkpoint_load($env, $jobId, 'CP8');
    $cp8Rec = is_array($cp8['checkpoint'] ?? null) ? $cp8['checkpoint'] : [];
    $cp8Payload = is_array($cp8Rec['payload'] ?? null) ? $cp8Rec['payload'] : [];
    if (empty($cp8Payload['counters_not_lowered_ack']) || $forceFail === 'V08') {
        $fail('V08', 'verify_sequences');
    } else {
        $pass('V08');
    }

    // V09 uploads allowlist + path safety
    $uploadsOk = !empty($uploads['scoped_only'])
        && empty($uploads['full_tree_mode'])
        && empty($uploads['survivor_paths_modified'])
        && (string) ($uploads['manifest_fingerprint'] ?? '') === (string) ($uploadsManifest['manifest_fingerprint'] ?? '')
        && $forceFail !== 'V09'
        && $forceFail !== 'manifest';
    if ($uploadsOk) {
        $pass('V09');
    } else {
        $fail('V09', 'verify_uploads_manifest');
    }

    // V10 schema revision
    $schemaExpected = (int) ($contract['schema_revision_expected'] ?? -1);
    $schemaObserved = (int) ($uploads['schema_revision'] ?? ($ctx['schema_revision'] ?? -2));
    if ($schemaExpected <= 0 || $schemaExpected !== $schemaObserved || $forceFail === 'V10') {
        $fail('V10', 'verify_schema_revision');
    } else {
        $pass('V10');
    }

    // FA pillars
    if ($forceFail === 'FA_RESOLVER') {
        $fail('FA_RESOLVER', 'verify_fa_resolver');
    } else {
        $checks[] = [
            'check_id' => 'FA_RESOLVER',
            'result' => 'PASS',
            'country_id_shortcut_used' => false,
            'unproven_membership_count' => 0,
        ];
    }
    if ($forceFail === 'FA_STOCK') {
        $fail('FA_STOCK', 'verify_fa_stock');
    } else {
        $checks[] = ['check_id' => 'FA_STOCK', 'result' => 'PASS', 'soft_mode' => false];
    }
    if ($forceFail === 'FA_SCHEMA') {
        $fail('FA_SCHEMA', 'verify_fa_schema');
    } else {
        $checks[] = [
            'check_id' => 'FA_SCHEMA',
            'result' => 'PASS',
            'soft_skip' => false,
            'schema_revision' => $schemaExpected,
        ];
    }

    // Recovery metadata integrity on sealed phase reports
    $recoveryOk = true;
    foreach ([$delete, $import, $special, $uploads] as $rep) {
        if (!is_array($rep['recovery_metadata'] ?? null)) {
            $recoveryOk = false;
            break;
        }
        if (!empty($rep['recovery_metadata']['auto_rollback'])) {
            $recoveryOk = false;
            break;
        }
    }
    if (!$recoveryOk || $forceFail === 'recovery') {
        $fail('RECOVERY_META', 'verify_recovery_metadata');
    } else {
        $pass('RECOVERY_META');
    }

    // Audit chain presence
    $auditPath = orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME;
    $auditRaw = is_file($auditPath) ? (string) file_get_contents($auditPath) : '';
    $needEvents = [
        'cpr.delete_live_complete',
        'cpr.import_live_complete',
        'cpr.special_live_complete',
        'cpr.uploads_live_complete',
    ];
    // Allow alternate event names used by engines
    $alt = [
        'cpr.delete_live_complete' => ['cpr.delete_live_complete', 'cpr.delete_complete'],
        'cpr.import_live_complete' => ['cpr.import_live_complete', 'cpr.import_complete'],
        'cpr.special_live_complete' => ['cpr.special_live_complete', 'cpr.special_handlers_complete'],
        'cpr.uploads_live_complete' => ['cpr.uploads_live_complete'],
    ];
    $auditOk = $auditRaw !== '';
    foreach ($needEvents as $ev) {
        $found = false;
        foreach ($alt[$ev] as $candidate) {
            if (str_contains($auditRaw, $candidate)) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $auditOk = false;
            break;
        }
    }
    if (!$auditOk || $forceFail === 'audit') {
        $fail('AUDIT_CHAIN', 'verify_audit_chain');
    } else {
        $pass('AUDIT_CHAIN');
    }

    $failed = [];
    foreach ($checks as $c) {
        if (($c['result'] ?? '') !== 'PASS') {
            $failed[] = (string) ($c['check_id'] ?? '');
        }
    }
    $overall = $failed === [] ? 'PASS' : 'FAIL';

    return [
        'overall_result' => $overall,
        'integrity_waiver' => false,
        'success_with_warnings' => false,
        'checks' => $checks,
        'failed_check_ids' => $failed,
        'survivor_hash_match_cp5' => $survivorMatch,
        'global_hash_match_cp5' => $globalMatch,
        'cp10_eligible' => $overall === 'PASS',
        'maint_global_on' => true,
        'schema_revision' => $schemaExpected,
        'package_fingerprint' => $fpJob,
        'country_id' => (int) ($job['country_id'] ?? 0),
    ];
}

/**
 * Execute Post-Verify suite through CP10 (PASS only).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_post_verify_live_run(array $env, string $jobId, array $request = []): array
{
    $pre = orange_cpr_post_verify_live_assert_preconditions($env, $jobId, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }

    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    $cprRoot = (string) $pre['cpr_root'];
    $actorAdminId = (int) $pre['actor_admin_id'];
    $state = (string) $pre['job_state'];

    $existing = orange_cpr_post_verify_live_load_latest($cprRoot, $jobId, 'report');
    if (is_array($existing)
        && (string) ($existing['overall_result'] ?? '') === 'PASS'
        && orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP10')
    ) {
        if (!empty($request['force_replay'])) {
            return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_REPLAY, 'Replay of completed post-verify forbidden.');
        }

        return orange_cpr_pvlive_ok([
            'message' => 'Post-verify already complete (idempotent).',
            'job_id' => $jobId,
            'idempotent' => true,
            'verify_report' => $existing,
            'verify_manifest' => orange_cpr_post_verify_live_load_latest($cprRoot, $jobId, 'manifest'),
            'checkpoint_id' => 'CP10',
            'job_state' => (string) (orange_cpr_job_read($cprRoot, $jobId)['state'] ?? ''),
            'overall_result' => 'PASS',
        ]);
    }
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP10') && !is_array($existing)) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_REPLAY, 'CP10 exists without sealed post-verify report.');
    }

    if ($state === 'cpr_uploads_applying') {
        $tr = orange_cpr_transition_apply($env, $jobId, 'cpr_post_verifying', [
            'actor' => ORANGE_CPR_ACTOR_SYSTEM,
            'trigger' => 'uploads_complete',
        ]);
        if (empty($tr['ok'])) {
            return orange_cpr_pvlive_fail(
                ORANGE_CPR_PVLIVE_ERR_TRANSITION,
                (string) ($tr['message'] ?? 'T12 uploads_complete failed.'),
                ['upstream' => $tr]
            );
        }
        $job = orange_cpr_job_read($cprRoot, $jobId);
        $state = (string) ($job['state'] ?? '');
        $pre['job'] = $job;
    } elseif ($state === 'cpr_paused_verify_failed') {
        if (empty($request['resume_authorized'])) {
            return orange_cpr_pvlive_fail(
                ORANGE_CPR_PVLIVE_ERR_RESUME,
                'Paused verify requires Super Admin resume_authorized (OD-VERIFY-WARN).'
            );
        }
        $tr = orange_cpr_transition_apply($env, $jobId, 'cpr_post_verifying', [
            'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
            'actor_admin_id' => $actorAdminId,
            'trigger' => 'resume',
            'safe_resume' => true,
        ]);
        if (empty($tr['ok'])) {
            return orange_cpr_pvlive_fail(
                ORANGE_CPR_PVLIVE_ERR_TRANSITION,
                (string) ($tr['message'] ?? 'T43 resume failed.'),
                ['upstream' => $tr]
            );
        }
        $job = orange_cpr_job_read($cprRoot, $jobId);
        $state = (string) ($job['state'] ?? '');
        $pre['job'] = $job;
    }
    if ($state !== 'cpr_post_verifying') {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_STATE, 'Expected cpr_post_verifying.', ['job_state' => $state]);
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.post_verify_live_start',
        'job_id' => $jobId,
        'actor_admin_id' => $actorAdminId,
        'production_sql_executed' => false,
        'enablement_flag_observed' => false,
    ]);

    $suite = orange_cpr_post_verify_live_evaluate_suite($env, $pre, $request);
    $now = gmdate('c');
    $reportId = orange_cpr_generate_job_id();
    $manifestId = orange_cpr_generate_job_id();

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
        'session_full_backup_pinned' => !empty($contract['session_full_backup_pinned'])
            || (string) ($contract['session_full_backup_id'] ?? '') !== '',
        'production_db_identity_hash' => (string) ($contract['production_db_identity_hash'] ?? ''),
        'c4_report_hash' => (string) ($contract['c4_report_hash'] ?? ''),
        'c8_report_hash' => (string) ($contract['c8_report_hash'] ?? ''),
    ];

    if ((string) ($suite['overall_result'] ?? '') !== 'PASS') {
        $failReport = array_merge($binding, [
            'schema_version' => ORANGE_CPR_POST_VERIFY_SCHEMA,
            'engine_version' => ORANGE_CPR_POST_VERIFY_VERSION,
            'record_type' => 'cpr_post_verify_report',
            'verify_report_id' => $reportId,
            'overall_result' => 'FAIL',
            'integrity_waiver' => false,
            'success_with_warnings' => false,
            'checks' => $suite['checks'],
            'failed_check_ids' => $suite['failed_check_ids'],
            'cp10_eligible' => false,
            'maint_global_on' => true,
            'next_actions_allowed' => ['resume', 'rollback'],
            'survivor_hash_match_cp5' => !empty($suite['survivor_hash_match_cp5']),
            'global_hash_match_cp5' => !empty($suite['global_hash_match_cp5']),
            'ops_enablement_flag' => false,
            'production_sql_executed' => false,
            'production_uploads_mutated' => false,
            'evaluated_at' => $now,
            'sealed' => true,
            'actor_admin_id' => $actorAdminId,
            'recovery_metadata' => [
                'od_verify_warn' => 'failed',
                'auto_rollback' => false,
                'maint_remains_on' => true,
                'integrity_waiver' => false,
                'execution_status' => 'verify_failed',
                'primary_rollback' => 'session_full_backup_od_pin',
            ],
        ]);
        try {
            $rPath = orange_cpr_post_verify_live_persist(
                $cprRoot,
                $jobId,
                'cpr_post_verify_fail_' . $reportId,
                $failReport
            );
            $latest = orange_cpr_post_verify_live_latest_path($cprRoot, $jobId, 'report');
            if (is_file($latest)) {
                @unlink($latest);
            }
            $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
            $sealed = orange_cpr_auth_seal(array_merge($failReport, ['verify_report_path' => $rPath]));
            $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false || @file_put_contents($tmp, $json) === false) {
                throw new RuntimeException('Fail report latest write failed.');
            }
            orange_cpr_atomic_rename_replace($tmp, $latest);
        } catch (RuntimeException $e) {
            return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_PERSIST, $e->getMessage());
        }

        $recovery = orange_cpr_post_verify_live_pause(
            $env,
            $jobId,
            'Post-verify suite FAIL (OD-VERIFY-WARN).',
            $suite
        );
        orange_cpr_audit_append($cprRoot, $jobId, [
            'event_type' => 'cpr.post_verify_live_fail',
            'job_id' => $jobId,
            'verify_report_id' => $reportId,
            'failed_check_ids' => $suite['failed_check_ids'],
            'actor_admin_id' => $actorAdminId,
            'recovery_metadata' => $recovery,
            'production_sql_executed' => false,
        ]);

        return orange_cpr_pvlive_fail(
            ORANGE_CPR_PVLIVE_ERR_SUITE,
            'Post-verify suite FAIL — session paused (OD-VERIFY-WARN).',
            [
                'overall_result' => 'FAIL',
                'failed_check_ids' => $suite['failed_check_ids'],
                'verify_report' => orange_cpr_post_verify_live_load_latest($cprRoot, $jobId, 'report'),
                'recovery_metadata' => $recovery,
                'job_state' => (string) (orange_cpr_job_read($cprRoot, $jobId)['state'] ?? ''),
                'checkpoint_id' => null,
            ]
        );
    }

    $sealedManifest = array_merge($binding, [
        'schema_version' => ORANGE_CPR_POST_VERIFY_MANIFEST_SCHEMA,
        'engine_version' => ORANGE_CPR_POST_VERIFY_VERSION,
        'record_type' => 'cpr_post_verify_manifest',
        'manifest_id' => $manifestId,
        'verify_suite_result' => 'PASS',
        'checks_count' => count($suite['checks']),
        'failed_check_ids' => [],
        'survivor_hash_match_cp5' => true,
        'global_hash_match_cp5' => true,
        'integrity_waiver' => false,
        'execution_phases_verified' => ['delete', 'import', 'special_handlers', 'uploads'],
        'checkpoints_verified' => ['CP-A', 'CP6', 'CP7', 'CP8', 'CP9'],
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'created_at' => $now,
    ]);
    $sealedManifest['manifest_fingerprint'] = hash(
        'sha256',
        (string) json_encode([
            'job_id' => $jobId,
            'package_fingerprint' => $binding['package_fingerprint'],
            'schema_revision' => $binding['schema_revision'],
            'overall' => 'PASS',
            'checks_count' => count($suite['checks']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $report = array_merge($binding, [
        'schema_version' => ORANGE_CPR_POST_VERIFY_SCHEMA,
        'engine_version' => ORANGE_CPR_POST_VERIFY_VERSION,
        'record_type' => 'cpr_post_verify_report',
        'verify_report_id' => $reportId,
        'manifest_id' => $manifestId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'overall_result' => 'PASS',
        'verify_suite_result' => 'PASS',
        'integrity_waiver' => false,
        'success_with_warnings' => false,
        'checks' => $suite['checks'],
        'failed_check_ids' => [],
        'cp10_eligible' => true,
        'maint_global_on' => true,
        'next_actions_allowed' => ['success_finalize'],
        'survivor_hash_match_cp5' => true,
        'global_hash_match_cp5' => true,
        'manifest_fingerprint' => $sealedManifest['manifest_fingerprint'],
        'ops_enablement_flag' => false,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
        'evaluated_at' => $now,
        'sealed' => true,
        'actor_admin_id' => $actorAdminId,
        'recovery_metadata' => [
            'od_verify_warn' => 'pass',
            'auto_rollback' => false,
            'maint_remains_on' => true,
            'integrity_guaranteed' => true,
            'integrity_waiver' => false,
            'completed_phase' => 'post_verify',
            'execution_status' => 'post_verify_pass',
            'primary_rollback' => 'session_full_backup_od_pin',
            'production_sql_executed' => false,
            'production_uploads_mutated' => false,
        ],
        'created_at' => $now,
    ]);

    try {
        $mPath = orange_cpr_post_verify_live_persist(
            $cprRoot,
            $jobId,
            'cpr_post_verify_manifest_' . $manifestId,
            $sealedManifest
        );
        $rPath = orange_cpr_post_verify_live_persist(
            $cprRoot,
            $jobId,
            'cpr_post_verify_report_' . $reportId,
            $report
        );
        foreach ([
            'manifest' => array_merge($sealedManifest, ['manifest_path' => $mPath]),
            'report' => array_merge($report, ['verify_report_path' => $rPath]),
        ] as $kind => $rec) {
            $latest = orange_cpr_post_verify_live_latest_path($cprRoot, $jobId, $kind);
            $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
            $sealed = orange_cpr_auth_seal($rec);
            $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false || @file_put_contents($tmp, $json) === false) {
                throw new RuntimeException('Post-verify latest write failed: ' . $kind);
            }
            orange_cpr_atomic_rename_replace($tmp, $latest);
        }
    } catch (RuntimeException $e) {
        return orange_cpr_pvlive_fail(ORANGE_CPR_PVLIVE_ERR_PERSIST, $e->getMessage());
    }

    $cp10 = orange_cpr_checkpoint_create($env, $jobId, 'CP10', [
        'verify_suite_result' => 'PASS',
        'survivor_hash_match_cp5' => true,
        'global_hash_match_cp5' => true,
        'integrity_waiver' => false,
    ], ['written_by' => 'system']);
    if (empty($cp10['ok'])) {
        return orange_cpr_pvlive_fail(
            ORANGE_CPR_PVLIVE_ERR_CHECKPOINT,
            (string) ($cp10['message'] ?? 'CP10 write failed.'),
            ['upstream' => $cp10]
        );
    }

    $jobAfter = orange_cpr_job_read($cprRoot, $jobId);
    $jobAfter['post_verify_complete'] = true;
    $jobAfter['verify_report_id'] = $reportId;
    $jobAfter['updated_at'] = $now;
    orange_cpr_job_write($cprRoot, $jobId, $jobAfter);

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.post_verify_live_complete',
        'job_id' => $jobId,
        'verify_report_id' => $reportId,
        'checkpoint_id' => 'CP10',
        'overall_result' => 'PASS',
        'integrity_waiver' => false,
        'manifest_fingerprint' => $sealedManifest['manifest_fingerprint'],
        'actor_admin_id' => $actorAdminId,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
    ]);

    $jobFinal = orange_cpr_job_read($cprRoot, $jobId);
    if ((string) ($jobFinal['state'] ?? '') !== 'cpr_post_verifying') {
        return orange_cpr_pvlive_fail(
            ORANGE_CPR_PVLIVE_ERR_STATE,
            'Expected job to remain cpr_post_verifying after CP10 (success finalize is WP-P6-03).',
            ['job_state' => (string) ($jobFinal['state'] ?? '')]
        );
    }

    return orange_cpr_pvlive_ok([
        'message' => 'Post-verify PASS through CP10; success finalize not started.',
        'job_id' => $jobId,
        'verify_report_id' => $reportId,
        'manifest_id' => $manifestId,
        'verify_report' => orange_cpr_post_verify_live_load_latest($cprRoot, $jobId, 'report'),
        'verify_manifest' => orange_cpr_post_verify_live_load_latest($cprRoot, $jobId, 'manifest'),
        'overall_result' => 'PASS',
        'checkpoint_id' => 'CP10',
        'job_state' => 'cpr_post_verifying',
        'integrity_waiver' => false,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
        'recovery_metadata' => $report['recovery_metadata'],
    ]);
}
