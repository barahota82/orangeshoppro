<?php

declare(strict_types=1);

/**
 * CPR Checkpoint Engine & Persistence (WP-P3-04).
 *
 * Atomic write/rename, load, validate, recover, integrity, lifecycle.
 * Fail-closed. No silent repair. No DELETE/IMPORT/production mutation.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P3_04_CHECKPOINT_SCAFFOLD.md
 * @see CPR-P1-WP04-CHECKPOINT_SCHEMAS
 */

require_once __DIR__ . '/cpr_job_framework.php';
require_once __DIR__ . '/cpr_checkpoint_catalog.php';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_cp_ok(array $extra = []): array
{
    return array_merge(['ok' => true, 'code' => 'ok', 'message' => 'OK'], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_cp_fail(string $code, string $message, array $extra = []): array
{
    return array_merge(['ok' => false, 'code' => $code, 'message' => $message], $extra);
}

/**
 * Canonical JSON for integrity hashing (stable key order).
 *
 * @param array<string, mixed> $data
 */
function orange_cpr_checkpoint_canonical_json(array $data): string
{
    orange_cpr_ksort_recursive($data);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Checkpoint canonical encode failed.');
    }

    return $json;
}

/**
 * @param array<string, mixed> $data
 */
function orange_cpr_ksort_recursive(array &$data): void
{
    ksort($data);
    foreach ($data as &$value) {
        if (is_array($value)) {
            orange_cpr_ksort_recursive($value);
        }
    }
}

/**
 * @param array<string, mixed> $envelopeWithoutHash
 */
function orange_cpr_checkpoint_integrity_hash(array $envelopeWithoutHash): string
{
    unset($envelopeWithoutHash['content_sha256']);

    return hash('sha256', orange_cpr_checkpoint_canonical_json($envelopeWithoutHash));
}

function orange_cpr_checkpoint_final_path(string $cprRoot, string $jobId, string $checkpointId): string
{
    $def = orange_cpr_checkpoint_definition($checkpointId);
    if ($def === null) {
        throw new RuntimeException('Unknown checkpoint id: ' . $checkpointId);
    }

    return orange_cpr_checkpoints_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . $def['filename'];
}

function orange_cpr_checkpoint_exists(string $cprRoot, string $jobId, string $checkpointId): bool
{
    return is_file(orange_cpr_checkpoint_final_path($cprRoot, $jobId, $checkpointId));
}

/**
 * @return list<string>
 */
function orange_cpr_checkpoint_list_committed(string $cprRoot, string $jobId): array
{
    $committed = [];
    foreach (orange_cpr_checkpoint_write_order() as $id) {
        if (orange_cpr_checkpoint_exists($cprRoot, $jobId, $id)) {
            $committed[] = $id;
        }
    }

    return $committed;
}

/**
 * Load checkpoint. Missing / corrupt / version / integrity → fail-closed result.
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_checkpoint_load(array $env, string $jobId, string $checkpointId): array
{
    $def = orange_cpr_checkpoint_definition($checkpointId);
    if ($def === null) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_UNKNOWN, 'Unknown checkpoint id.', [
            'checkpoint_id' => $checkpointId,
        ]);
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    $path = orange_cpr_checkpoint_final_path($cprRoot, $jobId, $checkpointId);
    if (!is_file($path)) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_MISSING, 'Checkpoint not achieved (missing file).', [
            'checkpoint_id' => $checkpointId,
            'path' => $path,
        ]);
    }

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_CORRUPT, 'Checkpoint file unreadable or empty.', [
            'checkpoint_id' => $checkpointId,
        ]);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_CORRUPT, 'Checkpoint JSON corrupt.', [
            'checkpoint_id' => $checkpointId,
        ]);
    }

    $versionCheck = orange_cpr_checkpoint_validate_version($data);
    if (empty($versionCheck['ok'])) {
        return $versionCheck;
    }

    $integrity = orange_cpr_checkpoint_verify_integrity($data);
    if (empty($integrity['ok'])) {
        return $integrity;
    }

    return orange_cpr_cp_ok([
        'checkpoint_id' => $checkpointId,
        'checkpoint' => $data,
        'path' => $path,
    ]);
}

/**
 * @param array<string, mixed> $checkpoint
 * @return array<string, mixed>
 */
function orange_cpr_checkpoint_validate_version(array $checkpoint): array
{
    $version = (string) ($checkpoint['schema_version'] ?? '');
    if ($version !== ORANGE_CPR_CHECKPOINT_SCHEMA_VERSION) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_VERSION, 'Checkpoint schema_version mismatch.', [
            'expected' => ORANGE_CPR_CHECKPOINT_SCHEMA_VERSION,
            'observed' => $version,
            'checkpoint_id' => $checkpoint['checkpoint_id'] ?? null,
        ]);
    }

    return orange_cpr_cp_ok(['schema_version' => $version]);
}

/**
 * @param array<string, mixed> $checkpoint
 * @return array<string, mixed>
 */
function orange_cpr_checkpoint_verify_integrity(array $checkpoint): array
{
    $stored = (string) ($checkpoint['content_sha256'] ?? '');
    if ($stored === '' || strlen($stored) !== 64) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_INTEGRITY, 'Checkpoint missing content_sha256.', [
            'checkpoint_id' => $checkpoint['checkpoint_id'] ?? null,
        ]);
    }
    $copy = $checkpoint;
    unset($copy['content_sha256']);
    $computed = orange_cpr_checkpoint_integrity_hash($copy);
    if (!hash_equals($stored, $computed)) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_INTEGRITY, 'Checkpoint integrity verification failed.', [
            'checkpoint_id' => $checkpoint['checkpoint_id'] ?? null,
        ]);
    }

    return orange_cpr_cp_ok([
        'content_sha256' => $stored,
        'checkpoint_id' => $checkpoint['checkpoint_id'] ?? null,
    ]);
}

/**
 * Validate payload + envelope fields for a checkpoint id (schema rules).
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function orange_cpr_checkpoint_validate_payload(string $checkpointId, array $payload): array
{
    $def = orange_cpr_checkpoint_definition($checkpointId);
    if ($def === null) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_UNKNOWN, 'Unknown checkpoint id.');
    }
    foreach ($def['payload_required'] as $field) {
        if (!array_key_exists($field, $payload) || $payload[$field] === '' || $payload[$field] === null) {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_SCHEMA, 'Missing payload field: ' . $field, [
                'checkpoint_id' => $checkpointId,
                'field' => $field,
            ]);
        }
    }

    if ($checkpointId === 'CP0') {
        if ((string) ($payload['c8_overall_result'] ?? '') !== 'SAFE') {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_SCHEMA, 'CP0 requires c8_overall_result=SAFE.', [
                'checkpoint_id' => 'CP0',
            ]);
        }
    }
    if ($checkpointId === 'CP4') {
        if (($payload['global_maintenance_on'] ?? false) !== true) {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_SCHEMA, 'CP4 requires global_maintenance_on=true.');
        }
    }
    if ($checkpointId === 'CP1') {
        if (($payload['verified'] ?? false) !== true
            || ($payload['pinned'] ?? false) !== true
            || ($payload['created_under_maintenance'] ?? false) !== true
            || ($payload['reused_existing_backup'] ?? true) !== false
        ) {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_OD_PIN, 'CP1 OD-PIN payload rejects reuse/unpinned/unverified.', [
                'checkpoint_id' => 'CP1',
            ]);
        }
    }
    if ($checkpointId === 'runbook_pre_ponr') {
        if ((string) ($payload['c8_overall_result'] ?? '') !== 'SAFE') {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_SCHEMA, 'Runbook requires C8 SAFE.');
        }
        if (($payload['global_maintenance_active'] ?? false) !== true) {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_SCHEMA, 'Runbook requires global_maintenance_active=true.');
        }
    }
    if ($checkpointId === 'CP-A') {
        if ((string) ($payload['contract_phase'] ?? '') !== 'pre_ponr'
            || ($payload['reversible'] ?? false) !== true
            || ($payload['ponr_not_entered'] ?? false) !== true
        ) {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_SCHEMA, 'CP-A payload invalid for last_reversible.');
        }
    }
    if ($checkpointId === 'CP10') {
        if ((string) ($payload['verify_suite_result'] ?? '') !== 'PASS'
            || ($payload['integrity_waiver'] ?? true) !== false
        ) {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_SCHEMA, 'CP10 requires PASS and integrity_waiver=false.');
        }
    }
    if ($checkpointId === 'CP12') {
        if (($payload['runbook_completed'] ?? false) !== true
            || ($payload['writers_restored'] ?? false) !== true
        ) {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_SCHEMA, 'CP12 requires runbook_completed and writers_restored.');
        }
    }

    // Secrets forbidden
    $blocked = ['password', 'passwd', 'token', 'secret', 'credential', 'api_key'];
    $flat = json_encode($payload);
    if (is_string($flat)) {
        $lower = strtolower($flat);
        foreach ($blocked as $fragment) {
            if (str_contains($lower, '"' . $fragment)) {
                return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_SCHEMA, 'Secrets forbidden in checkpoint payload.');
            }
        }
    }

    return orange_cpr_cp_ok(['checkpoint_id' => $checkpointId]);
}

/**
 * Full write-time validation (prereq DAG, OD-PIN, state, identity, PONR discipline).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function orange_cpr_checkpoint_validate_write(
    array $env,
    string $jobId,
    string $checkpointId,
    array $payload,
    array $context = []
): array {
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_ENABLEMENT, $e->getMessage());
    }

    $def = orange_cpr_checkpoint_definition($checkpointId);
    if ($def === null) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_UNKNOWN, 'Unknown checkpoint id.', [
            'checkpoint_id' => $checkpointId,
        ]);
    }

    $payloadCheck = orange_cpr_checkpoint_validate_payload($checkpointId, $payload);
    if (empty($payloadCheck['ok'])) {
        return $payloadCheck;
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_IDENTITY, $e->getMessage());
    }

    $state = (string) ($job['state'] ?? '');
    if (!in_array($state, $def['allowed_states'], true)) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_STATE, 'Checkpoint state binding failed.', [
            'checkpoint_id' => $checkpointId,
            'job_state' => $state,
            'allowed_states' => $def['allowed_states'],
        ]);
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if ($checkpointId !== 'CP0' && $checkpointId !== 'CP2' && !is_array($contract)) {
        // CP3+ generally need contract; CP0/CP2 may precede freeze file in WF paths.
        if (!in_array($checkpointId, ['CP0', 'CP2'], true)) {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_CONTRACT, 'Execution contract required for checkpoint.', [
                'checkpoint_id' => $checkpointId,
            ]);
        }
    }
    if (is_array($contract)) {
        if ((string) ($contract['job_id'] ?? '') !== $jobId
            || (string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
            || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
        ) {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_IDENTITY, 'Checkpoint/contract/job identity mismatch.');
        }
    }

    // Prerequisites
    foreach ($def['requires'] as $req) {
        if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, $req)) {
            $code = ($checkpointId === 'CP1' && $req === 'CP4')
                ? ORANGE_CPR_CP_ERR_OD_PIN
                : ORANGE_CPR_CP_ERR_PREREQ;

            return orange_cpr_cp_fail($code, 'Prerequisite checkpoint missing: ' . $req, [
                'checkpoint_id' => $checkpointId,
                'missing' => $req,
            ]);
        }
    }

    // OD-PIN explicit: never CP1 before CP4 (also covered by requires)
    if ($checkpointId === 'CP1' && !orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP4')) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_OD_PIN, 'CP1 forbidden before CP4 (OD-PIN).');
    }

    // CP12 special prerequisites
    if ($checkpointId === 'CP12') {
        $hasSuccess = orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP11');
        $prior = (string) ($payload['prior_terminal'] ?? '');
        $rollbackPath = in_array($prior, ['cpr_rollback_completed', 'cpr_failed_pre_ponr', 'cpr_cancelled_pre_ponr'], true);
        if (!$hasSuccess && !$rollbackPath) {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_PREREQ, 'CP12 requires CP11 or authorized rollback/pre-PONR closeout prior_terminal.', [
                'checkpoint_id' => 'CP12',
            ]);
        }
        if (($context['actor'] ?? '') !== 'super_admin' && empty($context['actor_is_super_admin'])) {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_STATE, 'CP12 requires Super Admin actor.');
        }
    }

    $ponr = !empty($job['ponr_crossed']);
    if (!empty($def['post_ponr'])) {
        if (!$ponr) {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_PONR, 'Post-PONR checkpoint requires ponr_crossed=true.', [
                'checkpoint_id' => $checkpointId,
            ]);
        }
        if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP-A')) {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_PONR, 'CP6+ forbidden without CP-A.', [
                'checkpoint_id' => $checkpointId,
            ]);
        }
        // Fail-pause: do not write success CPs
        if (str_starts_with($state, 'cpr_paused_')) {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_PONR, 'Success checkpoints forbidden while paused.', [
                'checkpoint_id' => $checkpointId,
                'job_state' => $state,
            ]);
        }
    }
    if (!empty($def['pre_ponr']) && $ponr && in_array($checkpointId, ['CP0', 'CP2', 'CP3', 'CP4', 'CP1', 'runbook_pre_ponr', 'CP5', 'CP-A'], true)) {
        // Allow reading historical; writing pre-PONR CPs after PONR is forbidden.
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_PONR, 'Pre-PONR checkpoint write forbidden after PONR.', [
            'checkpoint_id' => $checkpointId,
        ]);
    }

    // Duplicate: identical only
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, $checkpointId)) {
        $existing = orange_cpr_checkpoint_load($env, $jobId, $checkpointId);
        if (empty($existing['ok'])) {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_DUPLICATE, 'Existing checkpoint unreadable; refuse overwrite (no silent repair).', [
                'checkpoint_id' => $checkpointId,
                'load_code' => $existing['code'] ?? null,
            ]);
        }
        /** @var array<string, mixed> $prev */
        $prev = $existing['checkpoint'];
        $prevPayload = $prev['payload'] ?? null;
        if (!is_array($prevPayload)
            || orange_cpr_checkpoint_canonical_json($prevPayload) !== orange_cpr_checkpoint_canonical_json($payload)
        ) {
            return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_DUPLICATE, 'Duplicate checkpoint with different payload rejected.', [
                'checkpoint_id' => $checkpointId,
            ]);
        }

        return orange_cpr_cp_ok([
            'checkpoint_id' => $checkpointId,
            'idempotent' => true,
            'job' => $job,
            'contract' => $contract,
        ]);
    }

    return orange_cpr_cp_ok([
        'checkpoint_id' => $checkpointId,
        'idempotent' => false,
        'job' => $job,
        'contract' => $contract,
        'job_state' => $state,
        'ponr_crossed' => $ponr,
    ]);
}

/**
 * Create/persist checkpoint via atomic tmp → rename; update MANIFEST + job + audit.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $context written_by, actor_admin_id, actor_is_super_admin
 * @return array<string, mixed>
 */
function orange_cpr_checkpoint_create(
    array $env,
    string $jobId,
    string $checkpointId,
    array $payload,
    array $context = []
): array {
    $validation = orange_cpr_checkpoint_validate_write($env, $jobId, $checkpointId, $payload, $context);
    if (empty($validation['ok'])) {
        return $validation;
    }
    if (!empty($validation['idempotent'])) {
        return orange_cpr_cp_ok([
            'checkpoint_id' => $checkpointId,
            'idempotent' => true,
            'message' => 'Checkpoint already committed with identical payload.',
        ]);
    }

    $def = orange_cpr_checkpoint_definition($checkpointId);
    if ($def === null) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_UNKNOWN, 'Unknown checkpoint id.');
    }

    /** @var array<string, mixed> $job */
    $job = $validation['job'];
    /** @var array<string, mixed>|null $contract */
    $contract = $validation['contract'];
    $cprRoot = orange_cpr_resolve_work_root($env);

    $now = gmdate('c');
    $envelope = [
        'checkpoint_id' => $checkpointId,
        'checkpoint_name' => $def['name'],
        'job_id' => $jobId,
        'package_id' => (string) ($job['package_id'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'contract_revision' => is_array($contract)
            ? (int) ($contract['contract_revision'] ?? ($job['contract_revision'] ?? 0))
            : (int) ($job['contract_revision'] ?? 0),
        'written_at' => $now,
        'written_by' => (string) ($context['written_by'] ?? 'system'),
        'schema_version' => ORANGE_CPR_CHECKPOINT_SCHEMA_VERSION,
        'ponr_crossed_observed' => !empty($job['ponr_crossed']),
        'job_state_observed' => (string) ($job['state'] ?? ''),
        'payload' => $payload,
    ];
    $envelope['content_sha256'] = orange_cpr_checkpoint_integrity_hash($envelope);

    $cpDir = orange_cpr_checkpoints_directory($cprRoot, $jobId);
    $tmpDir = orange_cpr_checkpoints_tmp_directory($cprRoot, $jobId);
    if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_CORRUPT, 'Cannot create checkpoints/.tmp directory.');
    }

    $tmpName = $checkpointId . '_' . bin2hex(random_bytes(8)) . '.json';
    $tmpName = str_replace(['/', '\\', ':'], '_', $tmpName);
    $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . $tmpName;
    $finalPath = orange_cpr_checkpoint_final_path($cprRoot, $jobId, $checkpointId);

    $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_CORRUPT, 'Checkpoint encode failed.');
    }
    if (@file_put_contents($tmpPath, $json) === false) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_CORRUPT, 'Checkpoint tmp write failed.');
    }
    // Durable close best-effort
    if (function_exists('fflush')) {
        // file_put_contents already closed the handle; no-op marker for §3.1 step 5.
    }

    try {
        orange_cpr_atomic_rename_replace($tmpPath, $finalPath);
    } catch (RuntimeException $e) {
        @unlink($tmpPath);

        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_CORRUPT, $e->getMessage());
    }

    orange_cpr_checkpoint_manifest_append($cprRoot, $jobId, $checkpointId, $now);

    $job['last_checkpoint_id'] = $checkpointId;
    $job['updated_at'] = $now;
    orange_cpr_job_write($cprRoot, $jobId, $job);

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.checkpoint_commit',
        'job_id' => $jobId,
        'checkpoint_id' => $checkpointId,
        'checkpoint_name' => $def['name'],
        'job_state' => $job['state'] ?? null,
        'ponr_crossed' => !empty($job['ponr_crossed']),
        'content_sha256' => $envelope['content_sha256'],
        'actor_admin_id' => $context['actor_admin_id'] ?? null,
        'enablement_flag_observed' => false,
        'scaffold_record_only' => true,
    ]);

    return orange_cpr_cp_ok([
        'checkpoint_id' => $checkpointId,
        'checkpoint' => $envelope,
        'path' => $finalPath,
        'atomic_rename' => true,
        'job' => $job,
    ]);
}

/**
 * @param array<string, mixed>|null $manifest
 */
function orange_cpr_checkpoint_manifest_read(string $cprRoot, string $jobId): ?array
{
    $path = orange_cpr_checkpoints_manifest_path($cprRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

function orange_cpr_checkpoint_manifest_append(
    string $cprRoot,
    string $jobId,
    string $checkpointId,
    string $writtenAt
): void {
    $manifest = orange_cpr_checkpoint_manifest_read($cprRoot, $jobId);
    if (!is_array($manifest)) {
        $manifest = [
            'schema_version' => 'cpr_checkpoint_manifest/1',
            'job_id' => $jobId,
            'committed' => [],
        ];
    }
    if (!isset($manifest['committed']) || !is_array($manifest['committed'])) {
        $manifest['committed'] = [];
    }
    $manifest['committed'][] = [
        'checkpoint_id' => $checkpointId,
        'written_at' => $writtenAt,
    ];
    $manifest['updated_at'] = $writtenAt;

    $tmpDir = orange_cpr_checkpoints_tmp_directory($cprRoot, $jobId);
    if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
        throw new RuntimeException('Cannot create checkpoint tmp for MANIFEST.');
    }
    $tmp = $tmpDir . DIRECTORY_SEPARATOR . 'MANIFEST_' . bin2hex(random_bytes(6)) . '.json';
    $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('MANIFEST tmp write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, orange_cpr_checkpoints_manifest_path($cprRoot, $jobId));
}

/**
 * Recovery: ignore torn .tmp files; never silently repair corrupt finals.
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_checkpoint_recover(array $env, string $jobId): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_ENABLEMENT, $e->getMessage());
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_IDENTITY, $e->getMessage());
    }

    $tmpDir = orange_cpr_checkpoints_tmp_directory($cprRoot, $jobId);
    $tornIgnored = [];
    if (is_dir($tmpDir)) {
        foreach (scandir($tmpDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $tornIgnored[] = $entry;
            // Policy: ignore tmp — do not promote to final (no torn recovery outside rules).
        }
    }

    $committed = [];
    $corrupt = [];
    $latest = null;
    foreach (orange_cpr_checkpoint_write_order() as $id) {
        if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, $id)) {
            continue;
        }
        $loaded = orange_cpr_checkpoint_load($env, $jobId, $id);
        if (empty($loaded['ok'])) {
            $corrupt[] = [
                'checkpoint_id' => $id,
                'code' => $loaded['code'] ?? ORANGE_CPR_CP_ERR_CORRUPT,
                'message' => $loaded['message'] ?? 'corrupt',
            ];
            // Fail-closed: stop considering later CPs as recoverable chain head if corrupt mid-chain.
            break;
        }
        $committed[] = $id;
        $latest = $id;
    }

    if ($corrupt !== []) {
        return orange_cpr_cp_fail(ORANGE_CPR_CP_ERR_CORRUPT, 'Recovery found corrupt checkpoint; no silent repair.', [
            'job_id' => $jobId,
            'job_state' => $job['state'] ?? null,
            'ponr_crossed' => !empty($job['ponr_crossed']),
            'committed_before_corrupt' => $committed,
            'corrupt' => $corrupt,
            'torn_tmp_ignored' => $tornIgnored,
            'latest_good_checkpoint_id' => $latest,
            'code_detail' => ORANGE_CPR_CP_ERR_REPAIR,
        ]);
    }

    if ($latest !== null) {
        $job['last_checkpoint_id'] = $latest;
        $job['updated_at'] = gmdate('c');
        orange_cpr_job_write($cprRoot, $jobId, $job);
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.checkpoint_recover',
        'job_id' => $jobId,
        'latest_good_checkpoint_id' => $latest,
        'committed' => $committed,
        'torn_tmp_ignored' => $tornIgnored,
        'job_state' => $job['state'] ?? null,
        'ponr_crossed' => !empty($job['ponr_crossed']),
    ]);

    return orange_cpr_cp_ok([
        'job_id' => $jobId,
        'job_state' => $job['state'] ?? null,
        'ponr_crossed' => !empty($job['ponr_crossed']),
        'committed' => $committed,
        'latest_good_checkpoint_id' => $latest,
        'torn_tmp_ignored' => $tornIgnored,
        'torn_code' => $tornIgnored !== [] ? ORANGE_CPR_CP_ERR_TORN : null,
        'message' => 'Recovery complete from final checkpoints only; tmp ignored.',
    ]);
}

/**
 * Lifecycle: purge tmp staging files only (approved cleanup — not silent repair of finals).
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_checkpoint_lifecycle_purge_tmp(array $env, string $jobId): array
{
    $cprRoot = orange_cpr_resolve_work_root($env);
    $tmpDir = orange_cpr_checkpoints_tmp_directory($cprRoot, $jobId);
    $removed = [];
    if (is_dir($tmpDir)) {
        foreach (scandir($tmpDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $tmpDir . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path) && @unlink($path)) {
                $removed[] = $entry;
            }
        }
    }
    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.checkpoint_tmp_purged',
        'job_id' => $jobId,
        'removed' => $removed,
    ]);

    return orange_cpr_cp_ok(['removed' => $removed]);
}

/**
 * Refuse silent repair explicitly.
 *
 * @return array<string, mixed>
 */
function orange_cpr_checkpoint_refuse_silent_repair(): array
{
    return orange_cpr_cp_fail(
        ORANGE_CPR_CP_ERR_REPAIR,
        'Silent checkpoint repair is forbidden; rewrite only via validated create with identical payload or new job.'
    );
}
