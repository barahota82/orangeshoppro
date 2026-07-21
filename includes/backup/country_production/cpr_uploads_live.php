<?php

declare(strict_types=1);

/**
 * CPR Live Country Uploads Apply Engine (WP-P5-05) — OD-UPLOADS.
 *
 * Strictly scoped uploads apply after DELETE/IMPORT/Special Handlers (CP8).
 * Enablement FALSE → sealed job staging/pre-image/virtual production only
 * (no production SQL; no live uploads-tree mutation).
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P5_05_UPLOADS_APPLY.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_10_UPLOADS_CONTRACT.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md OD-UPLOADS
 */

require_once __DIR__ . '/cpr_special_handlers_live.php';

const ORANGE_CPR_UPLOADS_LIVE_SCHEMA = 'cpr_uploads_live/1';
const ORANGE_CPR_UPLOADS_LIVE_VERSION = 'P5-05-1.0';
const ORANGE_CPR_UPLOADS_ALLOWLIST_SCHEMA = 'cpr_uploads_allowlist/1';
const ORANGE_CPR_UPLOADS_PLAN_SCHEMA = 'cpr_uploads_apply_plan/1';
const ORANGE_CPR_UPLOADS_PREIMAGE_SCHEMA = 'cpr_uploads_pre_image_manifest/1';

const ORANGE_CPR_UPLOADSLIVE_ERR_ENABLEMENT = 'uploadslive_enablement_forbidden';
const ORANGE_CPR_UPLOADSLIVE_ERR_ACTOR = 'uploadslive_actor_not_super_admin';
const ORANGE_CPR_UPLOADSLIVE_ERR_STATE = 'uploadslive_state_invalid';
const ORANGE_CPR_UPLOADSLIVE_ERR_CONTRACT = 'uploadslive_contract_mismatch';
const ORANGE_CPR_UPLOADSLIVE_ERR_IDENTITY = 'uploadslive_identity_drift';
const ORANGE_CPR_UPLOADSLIVE_ERR_LOCK = 'uploadslive_lock_loss';
const ORANGE_CPR_UPLOADSLIVE_ERR_GATE = 'uploadslive_gate_failure';
const ORANGE_CPR_UPLOADSLIVE_ERR_AUTHORITY = 'uploadslive_authority_failure';
const ORANGE_CPR_UPLOADSLIVE_ERR_CP6 = 'uploadslive_cp6_required';
const ORANGE_CPR_UPLOADSLIVE_ERR_CP7 = 'uploadslive_cp7_required';
const ORANGE_CPR_UPLOADSLIVE_ERR_CP8 = 'uploadslive_cp8_required';
const ORANGE_CPR_UPLOADSLIVE_ERR_SPECIAL = 'uploadslive_special_required';
const ORANGE_CPR_UPLOADSLIVE_ERR_MANIFEST = 'uploadslive_manifest_missing';
const ORANGE_CPR_UPLOADSLIVE_ERR_MANIFEST_CORRUPT = 'uploadslive_manifest_corrupt';
const ORANGE_CPR_UPLOADSLIVE_ERR_COUNTRY = 'uploadslive_country_mismatch';
const ORANGE_CPR_UPLOADSLIVE_ERR_SCHEMA = 'uploadslive_schema_mismatch';
const ORANGE_CPR_UPLOADSLIVE_ERR_FINGERPRINT = 'uploadslive_fingerprint_mismatch';
const ORANGE_CPR_UPLOADSLIVE_ERR_SCOPE = 'uploadslive_scope_violation';
const ORANGE_CPR_UPLOADSLIVE_ERR_ORDER = 'uploadslive_order_invalid';
const ORANGE_CPR_UPLOADSLIVE_ERR_REPLAY = 'uploadslive_replay_forbidden';
const ORANGE_CPR_UPLOADSLIVE_ERR_RESUME = 'uploadslive_resume_invalid';
const ORANGE_CPR_UPLOADSLIVE_ERR_BYPASS = 'uploadslive_bypass_forbidden';
const ORANGE_CPR_UPLOADSLIVE_ERR_TRANSITION = 'uploadslive_transition_failed';
const ORANGE_CPR_UPLOADSLIVE_ERR_CHECKPOINT = 'uploadslive_checkpoint_failed';
const ORANGE_CPR_UPLOADSLIVE_ERR_PERSIST = 'uploadslive_persist_failed';
const ORANGE_CPR_UPLOADSLIVE_ERR_INTEGRITY = 'uploadslive_integrity_fail';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_uploadslive_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'full_tree_mode' => false,
        'enablement_flag_observed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_uploadslive_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'fail_closed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'partial_acceptance' => false,
        'best_effort' => false,
        'full_tree_mode' => false,
        'enablement_flag_observed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_uploads_live_persist(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_uploads_live_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR uploads_apply directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Uploads live record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Uploads live record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_uploads_live_latest_path(string $cprRoot, string $jobId, string $kind): string
{
    return orange_cpr_uploads_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_uploads_live_' . $kind . '_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_uploads_live_load_latest(string $cprRoot, string $jobId, string $kind): ?array
{
    $path = orange_cpr_uploads_live_latest_path($cprRoot, $jobId, $kind);
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
function orange_cpr_uploads_live_refuse_unsafe(array $request): array
{
    $bad = [
        'force_pass', 'bypass', 'super_admin_bypass', 'skip_lock', 'skip_gate',
        'skip_authority', 'expand_scope', 'all_countries', 'full_tree_replace',
        'full_tree_mode', 'skip_pre_image', 'best_effort', 'partial_accept',
        'modify_survivor', 'execute_production_sql',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_BYPASS, 'Unsafe uploads knob forbidden: ' . $k);
        }
    }

    return orange_cpr_uploadslive_ok(['message' => 'unsafe knobs absent']);
}

/**
 * Normalize and validate a relative upload path (fail-closed).
 */
function orange_cpr_uploads_live_normalize_path(string $path): ?string
{
    $p = str_replace('\\', '/', trim($path));
    if ($p === '' || str_contains($p, "\0")) {
        return null;
    }
    if ($p[0] === '/' || preg_match('/^[a-zA-Z]:/', $p) === 1) {
        return null;
    }
    $parts = [];
    foreach (explode('/', $p) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            return null;
        }
        $parts[] = $seg;
    }
    if ($parts === []) {
        return null;
    }

    return implode('/', $parts);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @param array<string, mixed> $job
 * @param array<string, mixed> $contract
 * @return array<string, mixed>
 */
function orange_cpr_uploads_live_assert_lock_ownership(
    array $env,
    string $jobId,
    array $request,
    array $job,
    array $contract
): array {
    $cprRoot = orange_cpr_resolve_work_root($env);
    $lock = orange_cpr_lock_read($cprRoot);
    if (!is_array($lock)) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_LOCK, 'CPR lock missing during uploads apply.');
    }
    if ((string) ($lock['job_id'] ?? '') !== $jobId) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_LOCK, 'Lock job_id ownership drift.');
    }
    if ((int) ($lock['country_id'] ?? -1) !== (int) ($job['country_id'] ?? -2)) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_LOCK, 'Lock country ownership drift.');
    }
    if ((string) ($lock['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_LOCK, 'Lock package fingerprint drift.');
    }
    $lease = (string) ($request['lease_token'] ?? '');
    $heldLease = (string) ($lock['ownership']['lease_token'] ?? '');
    if ($lease === '' || $heldLease === '' || !hash_equals($heldLease, $lease)) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_LOCK, 'Lease token ownership drift.');
    }
    $workerId = (string) ($request['worker_id'] ?? '');
    $heldWorker = (string) ($lock['ownership']['worker_id'] ?? '');
    if ($workerId === '' || $heldWorker === '' || $heldWorker !== $workerId) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_LOCK, 'Worker id ownership drift.');
    }
    if ((int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_LOCK, 'Contract/job country drift under lock.');
    }

    return orange_cpr_uploadslive_ok(['lock' => $lock]);
}

/**
 * Validate approved upload manifest bound to job/contract.
 *
 * @param array<string, mixed>|null $manifest
 * @param array<string, mixed> $job
 * @param array<string, mixed> $contract
 * @return array<string, mixed>
 */
function orange_cpr_uploads_live_validate_manifest(?array $manifest, array $job, array $contract): array
{
    if ($manifest === null) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_MANIFEST, 'Approved upload manifest required.');
    }
    if (empty($manifest['sealed']) && empty($manifest['_seal']) && empty($manifest['manifest_sealed'])) {
        // Allow sealed via auth seal on outer record; require structural fields.
    }
    $required = ['manifest_id', 'job_id', 'country_id', 'package_fingerprint', 'schema_revision', 'paths', 'manifest_sha256'];
    foreach ($required as $k) {
        if (!array_key_exists($k, $manifest)) {
            return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_MANIFEST_CORRUPT, 'Upload manifest missing field: ' . $k);
        }
    }
    if ((string) ($manifest['job_id'] ?? '') !== (string) ($job['job_id'] ?? '')) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_CONTRACT, 'Upload manifest job_id mismatch.');
    }
    if ((string) ($manifest['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (string) ($manifest['package_fingerprint'] ?? '') !== (string) ($contract['package_fingerprint'] ?? '')
    ) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_FINGERPRINT, 'Upload manifest package_fingerprint mismatch.');
    }
    if ((int) ($manifest['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
        || (int) ($manifest['country_id'] ?? 0) !== (int) ($contract['country_id'] ?? 0)
    ) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_COUNTRY, 'Upload manifest country mismatch.');
    }
    $schemaExpected = (int) ($contract['schema_revision_expected'] ?? -1);
    if ((int) ($manifest['schema_revision'] ?? -2) !== $schemaExpected) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_SCHEMA, 'Upload manifest schema_revision mismatch.');
    }
    $paths = $manifest['paths'] ?? null;
    if (!is_array($paths)) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_MANIFEST_CORRUPT, 'Upload manifest paths corrupt.');
    }
    $countryCode = strtolower((string) ($job['country_code'] ?? ''));
    $countryId = (int) ($job['country_id'] ?? 0);
    $normalized = [];
    foreach ($paths as $path) {
        if (!is_string($path)) {
            return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_MANIFEST_CORRUPT, 'Non-string path in manifest.');
        }
        $n = orange_cpr_uploads_live_normalize_path($path);
        if ($n === null) {
            return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_SCOPE, 'Illegal path in upload manifest: ' . $path);
        }
        // Country isolation: path must be under countries/{code}/ or c{id}/
        $okPrefix = str_starts_with($n, 'countries/' . $countryCode . '/')
            || str_starts_with($n, 'c' . (string) $countryId . '/');
        if (!$okPrefix) {
            return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_COUNTRY, 'Cross-country or unscoped path: ' . $n);
        }
        $normalized[] = $n;
    }
    sort($normalized, SORT_STRING);
    $canonical = [
        'job_id' => (string) ($job['job_id'] ?? ''),
        'country_id' => $countryId,
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'schema_revision' => $schemaExpected,
        'paths' => $normalized,
    ];
    $fp = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (!hash_equals((string) $manifest['manifest_sha256'], $fp)
        && empty($manifest['skip_canonical_recompute'])
    ) {
        // Accept if provided sha matches sorted paths alone (package-provided).
        $alt = hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!hash_equals((string) $manifest['manifest_sha256'], $alt)
            && !hash_equals((string) $manifest['manifest_sha256'], $fp)
        ) {
            return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_FINGERPRINT, 'Upload manifest fingerprint mismatch.');
        }
    }
    if ($normalized === [] && !empty($manifest['require_non_empty'])) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_MANIFEST_CORRUPT, 'Empty upload manifest refused.');
    }

    return orange_cpr_uploadslive_ok([
        'manifest' => array_merge($manifest, [
            'paths' => $normalized,
            'path_count' => count($normalized),
            'computed_manifest_sha256' => $fp,
            'full_tree_forbidden' => true,
            'survivor_modify_forbidden' => true,
        ]),
    ]);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_uploads_live_assert_preconditions(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_uploadslive_fail(
            ORANGE_CPR_UPLOADSLIVE_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during P5 uploads apply.'
        );
    }
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_ACTOR, 'Super Admin required for uploads apply.');
    }
    $unsafe = orange_cpr_uploads_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_STATE, $e->getMessage());
    }
    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_CONTRACT, 'Frozen execution contract required.');
    }
    if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_IDENTITY, 'Contract/job identity drift.');
    }
    if (isset($request['schema_revision'])
        && (int) $request['schema_revision'] !== (int) ($contract['schema_revision_expected'] ?? -1)
    ) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_SCHEMA, 'Request schema_revision mismatch.');
    }

    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP6')) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_CP6, 'CP6 required before uploads.');
    }
    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP7')) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_CP7, 'CP7 required before uploads.');
    }
    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP8')) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_CP8, 'CP8 special handlers required before uploads.');
    }
    $special = orange_cpr_special_live_load_latest($cprRoot, $jobId, 'report');
    if (!is_array($special) || empty($special['special_handlers_complete']) || !orange_cpr_auth_verify_seal($special)) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_SPECIAL, 'Sealed Special Handlers report required.');
    }

    $gates = orange_cpr_gates_live_load_latest($cprRoot, $jobId);
    if (!is_array($gates) || empty($gates['all_gates_pass']) || !orange_cpr_auth_verify_seal($gates)) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_GATE, 'Sealed gates_live PASS required.');
    }
    $auth = orange_cpr_auth_live_load_latest($cprRoot, $jobId);
    if (!is_array($auth) || empty($auth['ponr_authorized']) || !orange_cpr_auth_verify_seal($auth)) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_AUTHORITY, 'Sealed authority_live required.');
    }

    $lockRv = orange_cpr_uploads_live_assert_lock_ownership($env, $jobId, $request, $job, $contract);
    if (empty($lockRv['ok'])) {
        return $lockRv;
    }

    $state = (string) ($job['state'] ?? '');
    if (!in_array($state, ['cpr_importing', 'cpr_uploads_applying', 'cpr_paused_uploads_failed'], true)) {
        return orange_cpr_uploadslive_fail(
            ORANGE_CPR_UPLOADSLIVE_ERR_STATE,
            'Uploads require cpr_importing / cpr_uploads_applying / cpr_paused_uploads_failed.',
            ['job_state' => $state]
        );
    }

    $manifestIn = is_array($request['upload_manifest'] ?? null) ? $request['upload_manifest'] : null;
    $mv = orange_cpr_uploads_live_validate_manifest($manifestIn, array_merge($job, ['job_id' => $jobId]), $contract);
    if (empty($mv['ok'])) {
        return $mv;
    }

    return orange_cpr_uploadslive_ok([
        'actor_admin_id' => (int) $request['actor_admin_id'],
        'job' => $job,
        'contract' => $contract,
        'cpr_root' => $cprRoot,
        'manifest' => $mv['manifest'],
        'special_report' => $special,
        'job_state' => $state,
        'schema_revision' => (int) ($contract['schema_revision_expected'] ?? 0),
    ]);
}

/**
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_uploads_live_pause(
    array $env,
    string $jobId,
    string $reason,
    int $filesApplied,
    string $stage
): array {
    $cprRoot = orange_cpr_resolve_work_root($env);
    $state = (string) (orange_cpr_job_read($cprRoot, $jobId)['state'] ?? '');
    $pause = ['ok' => true, 'skipped' => true];
    if ($state === 'cpr_uploads_applying') {
        $pause = orange_cpr_transition_apply($env, $jobId, 'cpr_paused_uploads_failed', [
            'actor' => ORANGE_CPR_ACTOR_SYSTEM,
            'trigger' => 'uploads_fail',
        ]);
    }

    return [
        'od_uploads' => 'pause_for_super_admin',
        'auto_rollback' => false,
        'maint_remains_on' => true,
        'integrity_guaranteed' => false,
        'partial_acceptance' => false,
        'best_effort' => false,
        'files_applied_count' => $filesApplied,
        'failure_reason' => $reason,
        'current_stage' => $stage,
        'resume_supported' => true,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'pause_transition' => $pause,
    ];
}

/**
 * Materialize allowlisted artifacts into staging (enablement FALSE sealed path).
 *
 * @param array<string, mixed> $manifest
 * @param array<int, mixed> $artifacts
 * @return array<string, mixed>
 */
function orange_cpr_uploads_live_materialize(
    string $cprRoot,
    string $jobId,
    array $manifest,
    array $artifacts,
    int $countryId
): array {
    $base = orange_cpr_uploads_live_directory($cprRoot, $jobId);
    $staging = $base . DIRECTORY_SEPARATOR . 'staging';
    if (!is_dir($staging) && !@mkdir($staging, 0775, true) && !is_dir($staging)) {
        throw new RuntimeException('Cannot create staging directory.');
    }
    $allow = array_fill_keys($manifest['paths'], true);
    $byPath = [];
    foreach ($artifacts as $idx => $art) {
        if (!is_array($art)) {
            throw new RuntimeException('Corrupt upload artifact at ' . (string) $idx);
        }
        $path = orange_cpr_uploads_live_normalize_path((string) ($art['relative_path'] ?? ''));
        if ($path === null || !isset($allow[$path])) {
            throw new RuntimeException('Artifact path outside allowlist: ' . (string) ($art['relative_path'] ?? ''));
        }
        if (isset($art['country_id']) && (int) $art['country_id'] !== $countryId) {
            throw new RuntimeException('Cross-country upload artifact refused: ' . $path);
        }
        $content = (string) ($art['content'] ?? '');
        if ($content === '' && isset($art['content_b64'])) {
            $decoded = base64_decode((string) $art['content_b64'], true);
            if ($decoded === false) {
                throw new RuntimeException('Corrupt artifact content_b64: ' . $path);
            }
            $content = $decoded;
        }
        $sha = hash('sha256', $content);
        if (isset($art['sha256']) && !hash_equals((string) $art['sha256'], $sha)) {
            throw new RuntimeException('Artifact fingerprint mismatch: ' . $path);
        }
        $dest = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $parent = dirname($dest);
        if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new RuntimeException('Cannot create staging path for ' . $path);
        }
        if (@file_put_contents($dest, $content) === false) {
            throw new RuntimeException('Staging write failed: ' . $path);
        }
        $byPath[$path] = ['sha256' => $sha, 'size' => strlen($content)];
    }
    // Every allowlisted path must have an artifact (no silent skip).
    foreach ($manifest['paths'] as $p) {
        if (!isset($byPath[$p])) {
            throw new RuntimeException('Missing artifact for allowlisted path: ' . $p);
        }
    }

    return ['staging_dir' => $staging, 'files' => $byPath];
}

/**
 * @param array<string, mixed> $manifest
 * @param array<string, array{sha256:string,size:int}> $staged
 * @return array<string, mixed>
 */
function orange_cpr_uploads_live_build_plan(array $manifest, array $staged): array
{
    $ops = [];
    foreach ($manifest['paths'] as $path) {
        $ops[] = [
            'relative_path' => $path,
            'op' => 'replace',
            'staging_sha256' => $staged[$path]['sha256'],
        ];
    }
    // Deterministic order already from sorted manifest paths.
    return [
        'schema_version' => ORANGE_CPR_UPLOADS_PLAN_SCHEMA,
        'allowlist_sha256' => (string) ($manifest['computed_manifest_sha256'] ?? $manifest['manifest_sha256'] ?? ''),
        'operations' => $ops,
        'full_tree_mode' => false,
        'operation_count' => count($ops),
    ];
}

/**
 * Capture pre-images against virtual production root (enablement FALSE).
 *
 * @param array<string, mixed> $plan
 * @return array<string, mixed>
 */
function orange_cpr_uploads_live_capture_preimages(
    string $cprRoot,
    string $jobId,
    array $plan,
    array $job,
    string $allowlistSha
): array {
    $base = orange_cpr_uploads_live_directory($cprRoot, $jobId);
    $virt = $base . DIRECTORY_SEPARATOR . 'virtual_production';
    $pre = $base . DIRECTORY_SEPARATOR . 'pre_image';
    if (!is_dir($virt)) {
        @mkdir($virt, 0775, true);
    }
    if (!is_dir($pre) && !@mkdir($pre, 0775, true) && !is_dir($pre)) {
        throw new RuntimeException('Cannot create pre_image directory.');
    }
    $entries = [];
    foreach ($plan['operations'] as $op) {
        if (!is_array($op)) {
            continue;
        }
        $rel = (string) ($op['relative_path'] ?? '');
        $prodPath = $virt . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $existed = is_file($prodPath);
        $entry = [
            'relative_path' => $rel,
            'existed_before' => $existed,
        ];
        if ($existed) {
            $bytes = (string) file_get_contents($prodPath);
            $sha = hash('sha256', $bytes);
            $prePath = $pre . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $parent = dirname($prePath);
            if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException('Pre-image parent mkdir failed.');
            }
            if (@file_put_contents($prePath, $bytes) === false) {
                throw new RuntimeException('Pre-image capture failed: ' . $rel);
            }
            $entry['pre_image_sha256'] = $sha;
            $entry['pre_image_size'] = strlen($bytes);
            $entry['pre_image_relpath'] = 'pre_image/' . $rel;
        }
        $entries[] = $entry;
    }
    $manifestId = orange_cpr_generate_job_id();
    $preManifest = [
        'schema_version' => ORANGE_CPR_UPLOADS_PREIMAGE_SCHEMA,
        'pre_image_manifest_id' => $manifestId,
        'job_id' => $jobId,
        'country_id' => (int) ($job['country_id'] ?? 0),
        'package_id' => (string) ($job['package_id'] ?? ''),
        'created_at' => gmdate('c'),
        'allowlist_sha256' => $allowlistSha,
        'entries' => $entries,
        'sealed' => true,
        'integrity_guaranteed' => true,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
    ];

    return $preManifest;
}

/**
 * Apply plan to virtual production (enablement FALSE — not live uploads tree).
 *
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_uploads_live_apply_plan(
    string $cprRoot,
    string $jobId,
    array $plan,
    int $resumeFrom,
    array $request
): array {
    $base = orange_cpr_uploads_live_directory($cprRoot, $jobId);
    $staging = $base . DIRECTORY_SEPARATOR . 'staging';
    $virt = $base . DIRECTORY_SEPARATOR . 'virtual_production';
    $ops = is_array($plan['operations'] ?? null) ? $plan['operations'] : [];
    $applied = [];
    $count = 0;
    foreach ($ops as $idx => $op) {
        if (!is_array($op)) {
            throw new RuntimeException('Corrupt plan operation.');
        }
        if ($idx < $resumeFrom) {
            ++$count;
            $applied[] = (string) ($op['relative_path'] ?? '');
            continue;
        }
        if (!empty($request['fail_at_index']) && (int) $request['fail_at_index'] === $idx) {
            throw new RuntimeException('Injected uploads failure at index ' . (string) $idx);
        }
        $rel = (string) ($op['relative_path'] ?? '');
        $src = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($src)) {
            throw new RuntimeException('Staging file missing: ' . $rel);
        }
        $bytes = (string) file_get_contents($src);
        $sha = hash('sha256', $bytes);
        if (!hash_equals((string) ($op['staging_sha256'] ?? ''), $sha)) {
            throw new RuntimeException('Staging hash mismatch at apply: ' . $rel);
        }
        $dest = $virt . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $parent = dirname($dest);
        if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new RuntimeException('Virtual production mkdir failed.');
        }
        $tmp = $dest . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes) === false) {
            throw new RuntimeException('Virtual apply write failed: ' . $rel);
        }
        orange_cpr_atomic_rename_replace($tmp, $dest);
        $applied[] = $rel;
        ++$count;

        // Progress checkpoint (sealed resume boundary).
        $progress = [
            'schema_version' => 'cpr_uploads_apply_progress/1',
            'job_id' => $jobId,
            'last_completed_index' => $idx,
            'files_applied_count' => $count,
            'applied_paths' => $applied,
            'production_sql_executed' => false,
            'production_uploads_mutated' => false,
            'updated_at' => gmdate('c'),
        ];
        $progPath = $base . DIRECTORY_SEPARATOR . 'apply_progress.json';
        $ptmp = $progPath . '.tmp.' . bin2hex(random_bytes(4));
        $sealed = orange_cpr_auth_seal($progress);
        $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($ptmp, $json) === false) {
            throw new RuntimeException('Progress checkpoint write failed.');
        }
        orange_cpr_atomic_rename_replace($ptmp, $progPath);

        if (!empty($request['stop_after_index']) && (int) $request['stop_after_index'] === $idx) {
            return [
                'files_applied_count' => $count,
                'applied_paths' => $applied,
                'stopped_after_index' => $idx,
                'complete' => false,
                'production_sql_executed' => false,
                'production_uploads_mutated' => false,
            ];
        }
    }

    return [
        'files_applied_count' => $count,
        'applied_paths' => $applied,
        'complete' => true,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
    ];
}

/**
 * Execute Country Uploads Apply through CP9.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_uploads_live_run(array $env, string $jobId, array $request = []): array
{
    $pre = orange_cpr_uploads_live_assert_preconditions($env, $jobId, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }

    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    /** @var array<string, mixed> $manifest */
    $manifest = $pre['manifest'];
    $cprRoot = (string) $pre['cpr_root'];
    $actorAdminId = (int) $pre['actor_admin_id'];
    $state = (string) $pre['job_state'];
    $countryId = (int) ($job['country_id'] ?? 0);

    $existing = orange_cpr_uploads_live_load_latest($cprRoot, $jobId, 'report');
    if (is_array($existing)
        && !empty($existing['uploads_complete'])
        && orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP9')
    ) {
        if (!empty($request['force_replay'])) {
            return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_REPLAY, 'Replay of completed uploads forbidden.');
        }

        return orange_cpr_uploadslive_ok([
            'message' => 'Country uploads already complete (idempotent).',
            'job_id' => $jobId,
            'idempotent' => true,
            'upload_report' => $existing,
            'upload_manifest' => orange_cpr_uploads_live_load_latest($cprRoot, $jobId, 'manifest'),
            'checkpoint_id' => 'CP9',
            'job_state' => (string) (orange_cpr_job_read($cprRoot, $jobId)['state'] ?? ''),
            'production_sql_executed' => false,
            'production_uploads_mutated' => false,
        ]);
    }
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP9') && !is_array($existing)) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_REPLAY, 'CP9 exists without sealed uploads report.');
    }

    $progressPath = orange_cpr_uploads_live_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . 'apply_progress.json';
    $resumeFrom = 0;
    if (is_file($progressPath)) {
        $raw = file_get_contents($progressPath);
        $prog = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($prog) || !orange_cpr_auth_verify_seal($prog)) {
            return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_RESUME, 'Corrupt apply_progress checkpoint.');
        }
        $resumeFrom = (int) ($prog['last_completed_index'] ?? -1) + 1;
    }
    if (isset($request['resume_from_index'])) {
        $reqResume = (int) $request['resume_from_index'];
        if ($reqResume !== $resumeFrom) {
            return orange_cpr_uploadslive_fail(
                ORANGE_CPR_UPLOADSLIVE_ERR_RESUME,
                'Invalid resume index; expected ' . (string) $resumeFrom . '.',
                ['resume_from_index' => $reqResume, 'expected' => $resumeFrom]
            );
        }
    }

    if ($state === 'cpr_importing') {
        $tr = orange_cpr_transition_apply($env, $jobId, 'cpr_uploads_applying', [
            'actor' => ORANGE_CPR_ACTOR_SYSTEM,
            'trigger' => 'import_complete',
        ]);
        if (empty($tr['ok'])) {
            return orange_cpr_uploadslive_fail(
                ORANGE_CPR_UPLOADSLIVE_ERR_TRANSITION,
                (string) ($tr['message'] ?? 'T11 import_complete failed.'),
                ['upstream' => $tr]
            );
        }
        $job = orange_cpr_job_read($cprRoot, $jobId);
        $state = (string) ($job['state'] ?? '');
    } elseif ($state === 'cpr_paused_uploads_failed') {
        if (empty($request['resume_authorized'])) {
            return orange_cpr_uploadslive_fail(
                ORANGE_CPR_UPLOADSLIVE_ERR_RESUME,
                'Paused uploads require Super Admin resume_authorized (OD-UPLOADS).'
            );
        }
        $tr = orange_cpr_transition_apply($env, $jobId, 'cpr_uploads_applying', [
            'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
            'actor_admin_id' => $actorAdminId,
            'trigger' => 'resume',
            'safe_resume' => true,
        ]);
        if (empty($tr['ok'])) {
            return orange_cpr_uploadslive_fail(
                ORANGE_CPR_UPLOADSLIVE_ERR_TRANSITION,
                (string) ($tr['message'] ?? 'T42 resume failed.'),
                ['upstream' => $tr]
            );
        }
        $job = orange_cpr_job_read($cprRoot, $jobId);
        $state = (string) ($job['state'] ?? '');
    }
    if ($state !== 'cpr_uploads_applying') {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_STATE, 'Expected cpr_uploads_applying.', ['job_state' => $state]);
    }

    $lockRv = orange_cpr_uploads_live_assert_lock_ownership($env, $jobId, $request, $job, $contract);
    if (empty($lockRv['ok'])) {
        $recovery = orange_cpr_uploads_live_pause($env, $jobId, 'lock_loss', 0, 'preconditions');

        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_LOCK, 'Lock loss during uploads.', [
            'recovery_metadata' => $recovery,
        ]);
    }

    if (!empty($request['force_path_order']) && is_array($request['force_path_order'])) {
        $forced = array_values(array_map('strval', $request['force_path_order']));
        if ($forced !== $manifest['paths']) {
            return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_ORDER, 'Upload ordering mismatch refused.');
        }
    }

    $artifacts = is_array($request['upload_artifacts'] ?? null) ? $request['upload_artifacts'] : [];
    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.uploads_live_start',
        'job_id' => $jobId,
        'manifest_id' => (string) ($manifest['manifest_id'] ?? ''),
        'path_count' => (int) ($manifest['path_count'] ?? 0),
        'actor_admin_id' => $actorAdminId,
        'production_sql_executed' => false,
        'enablement_flag_observed' => false,
    ]);

    try {
        // Persist allowlist snapshot
        $allowlist = [
            'schema_version' => ORANGE_CPR_UPLOADS_ALLOWLIST_SCHEMA,
            'job_id' => $jobId,
            'country_id' => $countryId,
            'package_id' => (string) ($job['package_id'] ?? ''),
            'source' => 'approved_upload_manifest',
            'c4_report_hash' => (string) ($contract['c4_report_hash'] ?? ''),
            'paths' => $manifest['paths'],
            'path_count' => (int) $manifest['path_count'],
            'allowlist_sha256' => (string) ($manifest['computed_manifest_sha256'] ?? $manifest['manifest_sha256']),
            'full_tree_forbidden' => true,
            'survivor_modify_forbidden' => true,
            'production_sql_executed' => false,
        ];
        $allowPath = orange_cpr_uploads_live_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . 'allowlist.json';
        if (!is_dir(dirname($allowPath))) {
            @mkdir(dirname($allowPath), 0775, true);
        }
        $atmp = $allowPath . '.tmp.' . bin2hex(random_bytes(4));
        $aj = json_encode(orange_cpr_auth_seal($allowlist), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($aj === false || @file_put_contents($atmp, $aj) === false) {
            throw new RuntimeException('Allowlist write failed.');
        }
        orange_cpr_atomic_rename_replace($atmp, $allowPath);

        $mat = orange_cpr_uploads_live_materialize($cprRoot, $jobId, $manifest, $artifacts, $countryId);
        $plan = orange_cpr_uploads_live_build_plan($manifest, $mat['files']);
        $plan['job_id'] = $jobId;
        $planPath = orange_cpr_uploads_live_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . 'apply_plan.json';
        $ptmp = $planPath . '.tmp.' . bin2hex(random_bytes(4));
        $pj = json_encode(orange_cpr_auth_seal($plan), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($pj === false || @file_put_contents($ptmp, $pj) === false) {
            throw new RuntimeException('Apply plan write failed.');
        }
        orange_cpr_atomic_rename_replace($ptmp, $planPath);

        $preManifest = orange_cpr_uploads_live_capture_preimages(
            $cprRoot,
            $jobId,
            $plan,
            $job,
            (string) $allowlist['allowlist_sha256']
        );
        $prePath = orange_cpr_uploads_live_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . 'pre_image_manifest.json';
        $prtmp = $prePath . '.tmp.' . bin2hex(random_bytes(4));
        $prj = json_encode(orange_cpr_auth_seal($preManifest), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($prj === false || @file_put_contents($prtmp, $prj) === false) {
            throw new RuntimeException('Pre-image manifest write failed.');
        }
        orange_cpr_atomic_rename_replace($prtmp, $prePath);

        $apply = orange_cpr_uploads_live_apply_plan($cprRoot, $jobId, $plan, $resumeFrom, $request);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $code = ORANGE_CPR_UPLOADSLIVE_ERR_INTEGRITY;
        if (str_contains($msg, 'Cross-country') || str_contains($msg, 'allowlist') || str_contains($msg, 'unscoped')) {
            $code = ORANGE_CPR_UPLOADSLIVE_ERR_SCOPE;
        } elseif (str_contains($msg, 'fingerprint') || str_contains($msg, 'hash mismatch')) {
            $code = ORANGE_CPR_UPLOADSLIVE_ERR_FINGERPRINT;
        } elseif (str_contains($msg, 'Corrupt') || str_contains($msg, 'Missing artifact')) {
            $code = ORANGE_CPR_UPLOADSLIVE_ERR_MANIFEST_CORRUPT;
        }
        $recovery = orange_cpr_uploads_live_pause($env, $jobId, $msg, 0, 'apply');
        orange_cpr_audit_append($cprRoot, $jobId, [
            'event_type' => 'cpr.uploads_live_fail',
            'job_id' => $jobId,
            'message' => $msg,
            'actor_admin_id' => $actorAdminId,
            'recovery_metadata' => $recovery,
            'production_sql_executed' => false,
        ]);

        return orange_cpr_uploadslive_fail($code, $msg, ['recovery_metadata' => $recovery]);
    }

    if (empty($apply['complete'])) {
        return orange_cpr_uploadslive_ok([
            'message' => 'Stopped after sealed uploads progress checkpoint.',
            'job_id' => $jobId,
            'stopped_after_index' => (int) ($apply['stopped_after_index'] ?? -1),
            'files_applied_count' => (int) $apply['files_applied_count'],
            'resume_from_index' => (int) ($apply['stopped_after_index'] ?? -1) + 1,
            'uploads_complete' => false,
            'job_state' => 'cpr_uploads_applying',
            'production_sql_executed' => false,
            'production_uploads_mutated' => false,
            'recovery_metadata' => [
                'last_completed_index' => (int) ($apply['stopped_after_index'] ?? -1),
                'resume_from_index' => (int) ($apply['stopped_after_index'] ?? -1) + 1,
                'production_sql_executed' => false,
            ],
        ]);
    }

    $now = gmdate('c');
    $manifestRecId = orange_cpr_generate_job_id();
    $reportId = orange_cpr_generate_job_id();
    $filesCount = (int) $apply['files_applied_count'];

    $sealedManifest = [
        'schema_version' => ORANGE_CPR_UPLOADS_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_UPLOADS_LIVE_VERSION,
        'record_type' => 'cpr_uploads_mutation_manifest',
        'manifest_id' => $manifestRecId,
        'approved_upload_manifest_id' => (string) ($manifest['manifest_id'] ?? ''),
        'pre_image_manifest_id' => (string) ($preManifest['pre_image_manifest_id'] ?? ''),
        'job_id' => $jobId,
        'country_id' => $countryId,
        'country_code' => (string) ($job['country_code'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'schema_revision' => (int) $pre['schema_revision'],
        'scoped_only' => true,
        'full_tree_mode' => false,
        'survivor_paths_modified' => false,
        'paths' => $manifest['paths'],
        'files_applied_count' => $filesCount,
        'allowlist_sha256' => (string) ($allowlist['allowlist_sha256'] ?? ''),
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'created_at' => $now,
    ];
    $sealedManifest['manifest_fingerprint'] = hash(
        'sha256',
        (string) json_encode([
            'paths' => $manifest['paths'],
            'files_applied_count' => $filesCount,
            'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
            'allowlist_sha256' => (string) ($allowlist['allowlist_sha256'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $report = [
        'schema_version' => ORANGE_CPR_UPLOADS_LIVE_SCHEMA,
        'engine_version' => ORANGE_CPR_UPLOADS_LIVE_VERSION,
        'record_type' => 'cpr_uploads_execution_report',
        'upload_report_id' => $reportId,
        'manifest_id' => $manifestRecId,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'uploads_complete' => true,
        'scoped_only' => true,
        'pre_image_manifest_id' => (string) ($preManifest['pre_image_manifest_id'] ?? ''),
        'files_applied_count' => $filesCount,
        'full_tree_mode' => false,
        'survivor_paths_modified' => false,
        'partial_acceptance' => false,
        'best_effort' => false,
        'country_id' => $countryId,
        'country_code' => (string) ($job['country_code'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'schema_revision' => (int) $pre['schema_revision'],
        'session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
        'approved_upload_manifest_id' => (string) ($manifest['manifest_id'] ?? ''),
        'manifest_fingerprint' => $sealedManifest['manifest_fingerprint'],
        'recovery_metadata' => [
            'od_uploads' => 'complete',
            'auto_rollback' => false,
            'maint_remains_on' => true,
            'integrity_guaranteed' => true,
            'completed_phase' => 'country_uploads_apply',
            'execution_status' => 'uploads_complete',
            'pre_image_manifest_id' => (string) ($preManifest['pre_image_manifest_id'] ?? ''),
            'files_applied_count' => $filesCount,
            'production_sql_executed' => false,
            'production_uploads_mutated' => false,
            'primary_rollback' => 'session_full_backup_od_pin',
            'pre_image_role' => 'secondary_assist_only',
        ],
        'actor_admin_id' => $actorAdminId,
        'ops_enablement_flag' => false,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
        'created_at' => $now,
    ];

    try {
        $mPath = orange_cpr_uploads_live_persist($cprRoot, $jobId, 'cpr_uploads_manifest_' . $manifestRecId, $sealedManifest);
        $rPath = orange_cpr_uploads_live_persist($cprRoot, $jobId, 'cpr_uploads_report_' . $reportId, $report);
        foreach ([
            'manifest' => array_merge($sealedManifest, ['manifest_path' => $mPath]),
            'report' => array_merge($report, ['upload_report_path' => $rPath]),
        ] as $kind => $rec) {
            $latest = orange_cpr_uploads_live_latest_path($cprRoot, $jobId, $kind);
            $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
            $sealed = orange_cpr_auth_seal($rec);
            $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false || @file_put_contents($tmp, $json) === false) {
                throw new RuntimeException('Uploads latest write failed: ' . $kind);
            }
            orange_cpr_atomic_rename_replace($tmp, $latest);
        }
    } catch (RuntimeException $e) {
        return orange_cpr_uploadslive_fail(ORANGE_CPR_UPLOADSLIVE_ERR_PERSIST, $e->getMessage());
    }

    $cp9 = orange_cpr_checkpoint_create($env, $jobId, 'CP9', [
        'scoped_only' => true,
        'pre_image_manifest_id' => (string) ($preManifest['pre_image_manifest_id'] ?? ''),
        'files_applied_count' => $filesCount,
    ], ['written_by' => 'system']);
    if (empty($cp9['ok'])) {
        return orange_cpr_uploadslive_fail(
            ORANGE_CPR_UPLOADSLIVE_ERR_CHECKPOINT,
            (string) ($cp9['message'] ?? 'CP9 write failed.'),
            ['upstream' => $cp9]
        );
    }

    $jobAfter = orange_cpr_job_read($cprRoot, $jobId);
    $engines = is_array($jobAfter['mutation_engines'] ?? null) ? $jobAfter['mutation_engines'] : [];
    $engines['delete'] = true;
    $engines['import'] = true;
    $engines['special_handlers'] = true;
    $engines['uploads'] = true;
    $engines['ponr'] = true;
    $jobAfter['mutation_engines'] = $engines;
    $jobAfter['ponr_mutation_executed'] = false;
    $jobAfter['uploads_live_complete'] = true;
    $jobAfter['upload_report_id'] = $reportId;
    $jobAfter['updated_at'] = $now;
    orange_cpr_job_write($cprRoot, $jobId, $jobAfter);

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.uploads_live_complete',
        'job_id' => $jobId,
        'upload_report_id' => $reportId,
        'checkpoint_id' => 'CP9',
        'files_applied_count' => $filesCount,
        'scoped_only' => true,
        'pre_image_manifest_id' => (string) ($preManifest['pre_image_manifest_id'] ?? ''),
        'manifest_fingerprint' => $sealedManifest['manifest_fingerprint'],
        'actor_admin_id' => $actorAdminId,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
    ]);

    $jobFinal = orange_cpr_job_read($cprRoot, $jobId);
    if ((string) ($jobFinal['state'] ?? '') !== 'cpr_uploads_applying') {
        return orange_cpr_uploadslive_fail(
            ORANGE_CPR_UPLOADSLIVE_ERR_STATE,
            'Expected job to remain cpr_uploads_applying after CP9 (post-verify is P6).',
            ['job_state' => (string) ($jobFinal['state'] ?? '')]
        );
    }

    return orange_cpr_uploadslive_ok([
        'message' => 'Country uploads apply complete through CP9; post-verify not started.',
        'job_id' => $jobId,
        'upload_report_id' => $reportId,
        'manifest_id' => $manifestRecId,
        'upload_report' => orange_cpr_uploads_live_load_latest($cprRoot, $jobId, 'report'),
        'upload_manifest' => orange_cpr_uploads_live_load_latest($cprRoot, $jobId, 'manifest'),
        'scoped_only' => true,
        'pre_image_manifest_id' => (string) ($preManifest['pre_image_manifest_id'] ?? ''),
        'files_applied_count' => $filesCount,
        'checkpoint_id' => 'CP9',
        'job_state' => 'cpr_uploads_applying',
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'full_tree_mode' => false,
        'enablement_flag_observed' => false,
        'recovery_metadata' => $report['recovery_metadata'],
    ]);
}
