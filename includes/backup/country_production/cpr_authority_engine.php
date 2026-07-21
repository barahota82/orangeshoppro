<?php

declare(strict_types=1);

/**
 * CPR Pre-PONR Authorization & Contract Freeze Engine (WP-P3-07).
 *
 * Consumes sealed PASS gate evaluations from WP-P3-06 only.
 * Freezes the execution contract at pre_ponr, re-reads fingerprints,
 * mints a one-time sealed PONR authorization — does NOT execute PONR mutation.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P3_07_AUTHORITY_SCAFFOLD.md
 * @see CPR-P1-WP06-AUTHORITY_RUNBOOK
 * @see CPR-P1-WP02-EXECUTION_CONTRACT
 */

require_once __DIR__ . '/cpr_job_framework.php';
require_once __DIR__ . '/cpr_checkpoint_engine.php';
require_once __DIR__ . '/cpr_lock_engine.php';
require_once __DIR__ . '/cpr_gate_evaluator.php';

const ORANGE_CPR_AUTH_SCHEMA = 'cpr_ponr_authorization/1';
const ORANGE_CPR_AUTH_CHALLENGE_SCHEMA = 'cpr_auth_challenge/1';
const ORANGE_CPR_AUTH_ENGINE_VERSION = 'P3-07-1.0';
const ORANGE_CPR_AUTH_PHRASE_LITERAL = 'RESTORE';
/** Engineering default: sealed pre_ponr_full gate report max age (seconds). */
const ORANGE_CPR_AUTH_GATE_MAX_AGE_SECONDS = 900;

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_auth_ok(array $extra = []): array
{
    return array_merge(['ok' => true, 'code' => 'ok'], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_auth_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'ponr_authorized' => false,
        'ponr_crossed' => false,
    ], $extra);
}

/**
 * Canonical JSON for sealing (sorted keys).
 *
 * @param array<string, mixed> $data
 */
function orange_cpr_auth_canonical_json(array $data): string
{
    $normalized = orange_cpr_auth_ksort_recursive($data);
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('CPR auth canonical encode failed.');
    }

    return $json;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function orange_cpr_auth_ksort_recursive(array $data): array
{
    foreach ($data as $k => $v) {
        if (is_array($v)) {
            $data[$k] = orange_cpr_auth_ksort_recursive($v);
        }
    }
    ksort($data);

    return $data;
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_auth_seal(array $record): array
{
    $copy = $record;
    unset($copy['content_sha256']);
    $record['content_sha256'] = hash('sha256', orange_cpr_auth_canonical_json($copy));

    return $record;
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_auth_verify_seal(array $record): bool
{
    $expected = (string) ($record['content_sha256'] ?? '');
    if ($expected === '' || strlen($expected) !== 64) {
        return false;
    }
    $copy = $record;
    unset($copy['content_sha256']);

    return hash_equals($expected, hash('sha256', orange_cpr_auth_canonical_json($copy)));
}

/**
 * Verify WP-P3-06 gate evaluation seal (top-level ksort; matches evaluator).
 *
 * @param array<string, mixed> $report
 */
function orange_cpr_gate_evaluation_verify_seal(array $report): bool
{
    $expected = (string) ($report['content_sha256'] ?? '');
    if ($expected === '' || strlen($expected) !== 64) {
        return false;
    }
    $copy = $report;
    unset($copy['content_sha256'], $copy['_path']);
    ksort($copy);
    $got = hash(
        'sha256',
        (string) json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    return hash_equals($expected, $got);
}

/**
 * Load latest sealed gate evaluation for profile (WP-P3-06 artifact).
 *
 * @return array<string, mixed>|null
 */
function orange_cpr_gate_evaluation_load_latest(string $cprRoot, string $jobId, string $profile = 'pre_ponr_full'): ?array
{
    $profileSafe = preg_replace('/[^a-z0-9_]/i', '', $profile) ?: 'eval';
    $path = orange_cpr_gates_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_gate_evaluation_latest_' . $profileSafe . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    $decoded['_path'] = $path;

    return $decoded;
}

/**
 * Digest of frozen contract identity + fingerprint fields (non-transferable bind).
 *
 * @param array<string, mixed> $contract
 */
function orange_cpr_contract_fingerprint_digest(array $contract): string
{
    $slice = [
        'job_id' => (string) ($contract['job_id'] ?? ''),
        'package_id' => (string) ($contract['package_id'] ?? ''),
        'package_fingerprint' => (string) ($contract['package_fingerprint'] ?? ''),
        'country_id' => (int) ($contract['country_id'] ?? 0),
        'country_code' => (string) ($contract['country_code'] ?? ''),
        'schema_revision_expected' => $contract['schema_revision_expected'] ?? null,
        'c4_report_hash' => (string) ($contract['c4_report_hash'] ?? ''),
        'c5_report_hash' => (string) ($contract['c5_report_hash'] ?? ''),
        'c6_report_hash' => (string) ($contract['c6_report_hash'] ?? ''),
        'c7_report_hash' => (string) ($contract['c7_report_hash'] ?? ''),
        'c8_report_hash' => (string) ($contract['c8_report_hash'] ?? ''),
        'c8_overall_result' => (string) ($contract['c8_overall_result'] ?? ''),
        'inventory_snapshot_id' => (string) ($contract['inventory_snapshot_id'] ?? ''),
        'inventory_snapshot_hash' => (string) ($contract['inventory_snapshot_hash'] ?? ''),
        'production_db_identity_hash' => (string) ($contract['production_db_identity_hash'] ?? ''),
        'session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
        'session_full_backup_fingerprint' => (string) ($contract['session_full_backup_fingerprint'] ?? ''),
        'contract_revision' => (int) ($contract['contract_revision'] ?? 0),
        'contract_phase' => (string) ($contract['contract_phase'] ?? ''),
    ];

    return hash('sha256', orange_cpr_auth_canonical_json($slice));
}

/**
 * Freeze / amend execution contract to pre_ponr immediately before authorization (P1-02).
 * Attaches session Full Backup pin; refuses package/country/C4–C8 drift vs re-read.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $reread Bound fingerprint re-read evidence
 * @return array<string, mixed> Result with ok / contract
 */
function orange_cpr_contract_freeze_pre_ponr(
    array $env,
    string $jobId,
    array $reread,
    ?int $actorAdminId = null
): array {
    orange_cpr_assert_enablement_false_for_scaffold($env);

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_auth_fail('auth_job_missing', $e->getMessage());
    }

    if (!empty($job['ponr_crossed'])) {
        return orange_cpr_auth_fail('auth_ponr_already_crossed', 'Contract freeze forbidden after PONR.');
    }

    $state = (string) ($job['state'] ?? '');
    if ($state !== 'cpr_pre_ponr') {
        return orange_cpr_auth_fail('auth_state_invalid', 'pre_ponr freeze requires state cpr_pre_ponr; got ' . $state);
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_auth_fail('auth_contract_not_frozen', 'Initial contract freeze (pre_pin) required before pre_ponr freeze.');
    }

    if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_auth_fail('auth_fingerprint_drift', 'Frozen contract identity drifted vs job.');
    }

    $cp1 = orange_cpr_checkpoint_load($env, $jobId, 'CP1');
    if (empty($cp1['ok'])) {
        return orange_cpr_auth_fail('auth_pin_missing', 'CP1 session Full Backup pin checkpoint missing or invalid.');
    }
    $cp1Payload = is_array($cp1['checkpoint']['payload'] ?? null) ? $cp1['checkpoint']['payload'] : [];
    if (empty($cp1Payload['pinned']) || empty($cp1Payload['verified'])) {
        return orange_cpr_auth_fail('auth_pin_missing', 'Session Full Backup not pinned/verified on CP1.');
    }

    $sessionId = (string) ($cp1Payload['session_full_backup_id'] ?? '');
    $sessionFp = (string) ($cp1Payload['session_full_backup_fingerprint'] ?? '');
    if ($sessionId === '' || $sessionFp === '') {
        return orange_cpr_auth_fail('auth_pin_missing', 'Session Full Backup id/fingerprint missing on CP1.');
    }

    // Re-read must match frozen report hashes + identity + pin.
    $checks = [
        'package_fingerprint' => (string) ($contract['package_fingerprint'] ?? ''),
        'country_id' => (int) ($contract['country_id'] ?? 0),
        'schema_revision_expected' => $contract['schema_revision_expected'] ?? null,
        'c4_report_hash' => (string) ($contract['c4_report_hash'] ?? ''),
        'c5_report_hash' => (string) ($contract['c5_report_hash'] ?? ''),
        'c6_report_hash' => (string) ($contract['c6_report_hash'] ?? ''),
        'c7_report_hash' => (string) ($contract['c7_report_hash'] ?? ''),
        'c8_report_hash' => (string) ($contract['c8_report_hash'] ?? ''),
        'inventory_snapshot_id' => (string) ($contract['inventory_snapshot_id'] ?? ''),
        'inventory_snapshot_hash' => (string) ($contract['inventory_snapshot_hash'] ?? ''),
        'production_db_identity_hash' => (string) ($contract['production_db_identity_hash'] ?? ''),
    ];
    foreach ($checks as $key => $expected) {
        if (!array_key_exists($key, $reread)) {
            return orange_cpr_auth_fail('auth_fingerprint_drift', 'Re-read missing field: ' . $key);
        }
        $got = $reread[$key];
        if ($key === 'country_id' || $key === 'schema_revision_expected') {
            if ((string) $got !== (string) $expected) {
                return orange_cpr_auth_fail('auth_fingerprint_drift', 'Fingerprint drift: ' . $key);
            }
        } elseif ((string) $got !== (string) $expected) {
            return orange_cpr_auth_fail('auth_fingerprint_drift', 'Fingerprint drift: ' . $key);
        }
    }

    if ((string) ($reread['c8_overall_result'] ?? '') !== 'SAFE'
        || (string) ($contract['c8_overall_result'] ?? '') !== 'SAFE'
    ) {
        return orange_cpr_auth_fail('auth_c8_not_safe', 'C8 overall result must be SAFE for pre_ponr freeze.');
    }

    if ((string) ($reread['session_full_backup_id'] ?? '') !== $sessionId
        || (string) ($reread['session_full_backup_fingerprint'] ?? '') !== $sessionFp
    ) {
        return orange_cpr_auth_fail('auth_pin_missing', 'Re-read session Full Backup does not match CP1 pin.');
    }

    // Illegal package/country change after freeze
    if (isset($reread['package_id']) && (string) $reread['package_id'] !== (string) ($contract['package_id'] ?? '')) {
        return orange_cpr_auth_fail('auth_fingerprint_drift', 'Illegal package_id change after freeze.');
    }

    $now = gmdate('c');
    $phaseWas = (string) ($contract['contract_phase'] ?? '');
    $revision = (int) ($contract['contract_revision'] ?? 1);
    if ($phaseWas !== 'pre_ponr' || empty($contract['session_full_backup_pinned'])) {
        ++$revision; // pin amend per P1-02
    }

    $contract['session_full_backup_id'] = $sessionId;
    $contract['session_full_backup_fingerprint'] = $sessionFp;
    $contract['session_full_backup_pinned'] = true;
    $contract['contract_phase'] = 'pre_ponr';
    $contract['contract_revision'] = $revision;
    $contract['contract_frozen'] = true;
    $contract['pre_ponr_frozen_at'] = $now;
    $contract['pre_ponr_frozen_by_admin_id'] = $actorAdminId;
    $contract['enablement_flag_observed'] = false;
    $contract['http_mutation_forbidden'] = true;
    // Authorization id attached only by authorize(); keep null until then unless already set.
    if (!empty($contract['one_time_authorization_id']) && empty($contract['ponr_authorized'])) {
        // Incomplete prior attempt — refuse (fail-closed).
        return orange_cpr_auth_fail('auth_duplicate', 'Contract already carries an authorization id.');
    }
    if (!empty($contract['ponr_authorized']) && !empty($contract['one_time_authorization_id'])) {
        return orange_cpr_auth_fail('auth_duplicate', 'PONR authorization already bound on contract.');
    }

    $contract['contract_fingerprint'] = orange_cpr_contract_fingerprint_digest($contract);
    orange_cpr_contract_write($cprRoot, $jobId, $contract);

    $job['contract_revision'] = $revision;
    $job['updated_at'] = $now;
    $job['enablement_flag_observed'] = false;
    orange_cpr_job_write($cprRoot, $jobId, $job);

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.contract_freeze_pre_ponr',
        'job_id' => $jobId,
        'contract_phase' => 'pre_ponr',
        'contract_revision' => $revision,
        'contract_fingerprint' => $contract['contract_fingerprint'],
        'actor_admin_id' => $actorAdminId,
        'enablement_flag_observed' => false,
        'ponr_authorized' => false,
    ]);

    return orange_cpr_auth_ok([
        'contract' => $contract,
        'contract_fingerprint' => $contract['contract_fingerprint'],
    ]);
}

/**
 * Validate sealed PASS gate report from WP-P3-06 (fail-closed).
 *
 * @param array<string, mixed> $job
 * @param array<string, mixed>|null $gate
 * @return array<string, mixed>
 */
function orange_cpr_auth_validate_gate_report(?array $gate, array $job, int $maxAgeSeconds): array
{
    if ($gate === null) {
        return orange_cpr_auth_fail('auth_gate_missing', 'Sealed pre_ponr_full gate evaluation missing.');
    }
    if (($gate['profile'] ?? '') !== 'pre_ponr_full') {
        return orange_cpr_auth_fail('auth_gate_not_pass', 'Only sealed pre_ponr_full PASS may authorize PONR.');
    }
    if (!orange_cpr_gate_evaluation_verify_seal($gate)) {
        return orange_cpr_auth_fail('auth_gate_unsealed', 'Gate evaluation seal verification failed.');
    }
    if (empty($gate['all_gates_pass']) || empty($gate['ponr_authorized'])) {
        return orange_cpr_auth_fail('auth_gate_not_pass', 'Gate evaluation is not PASS / PONR not allowed.');
    }
    if (!empty($gate['waiver_attempted'])) {
        return orange_cpr_auth_fail('auth_gate_not_pass', 'Gate evaluation waiver attempted — reject.');
    }
    if ((string) ($gate['c8_overall_result_observed'] ?? '') !== 'SAFE') {
        return orange_cpr_auth_fail('auth_c8_not_safe', 'Gate report C8 is not SAFE.');
    }
    if ((string) ($gate['job_id'] ?? '') !== (string) ($job['job_id'] ?? '')) {
        return orange_cpr_auth_fail('auth_gate_not_pass', 'Gate report job_id mismatch.');
    }
    if ((string) ($gate['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')) {
        return orange_cpr_auth_fail('auth_fingerprint_drift', 'Gate report package fingerprint drifted vs job.');
    }

    $evaluatedAt = (string) ($gate['evaluated_at'] ?? '');
    $ts = $evaluatedAt !== '' ? strtotime($evaluatedAt) : false;
    if ($ts === false) {
        return orange_cpr_auth_fail('auth_gate_stale', 'Gate evaluation timestamp missing/invalid.');
    }
    if ((time() - $ts) > $maxAgeSeconds) {
        return orange_cpr_auth_fail('auth_gate_stale', 'Gate evaluation is stale.');
    }

    return orange_cpr_auth_ok(['gate' => $gate]);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $job
 * @param array<string, mixed> $contract
 * @return array<string, mixed>
 */
function orange_cpr_auth_validate_runbook(array $env, string $jobId, array $job, array $contract): array
{
    $rb = orange_cpr_checkpoint_load($env, $jobId, 'runbook_pre_ponr');
    if (empty($rb['ok'])) {
        return orange_cpr_auth_fail('auth_runbook_incomplete', 'runbook_pre_ponr checkpoint missing or invalid.');
    }
    $payload = is_array($rb['checkpoint']['payload'] ?? null) ? $rb['checkpoint']['payload'] : [];

    $required = [
        'restore_package_id' => (string) ($contract['package_id'] ?? $job['package_id'] ?? ''),
        'target_country_id' => (int) ($contract['country_id'] ?? $job['country_id'] ?? 0),
        'target_country_code' => (string) ($contract['country_code'] ?? $job['country_code'] ?? ''),
        'c8_overall_result' => 'SAFE',
        'certified_inventory_snapshot_id' => (string) ($contract['inventory_snapshot_id'] ?? ''),
        'session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
        'global_maintenance_active' => true,
    ];

    if ((string) ($payload['restore_package_id'] ?? '') !== $required['restore_package_id']) {
        return orange_cpr_auth_fail('auth_runbook_incomplete', 'Runbook package id mismatch.');
    }
    if ((int) ($payload['target_country_id'] ?? 0) !== $required['target_country_id']) {
        return orange_cpr_auth_fail('auth_runbook_incomplete', 'Runbook country id mismatch.');
    }
    if ((string) ($payload['target_country_code'] ?? '') !== $required['target_country_code']) {
        return orange_cpr_auth_fail('auth_runbook_incomplete', 'Runbook country code mismatch.');
    }
    if ((string) ($payload['c8_overall_result'] ?? '') !== 'SAFE') {
        return orange_cpr_auth_fail('auth_c8_not_safe', 'Runbook C8 is not SAFE.');
    }
    if ((string) ($payload['certified_inventory_snapshot_id'] ?? '') !== $required['certified_inventory_snapshot_id']) {
        return orange_cpr_auth_fail('auth_runbook_incomplete', 'Runbook inventory snapshot mismatch.');
    }
    if ((string) ($payload['session_full_backup_id'] ?? '') !== $required['session_full_backup_id']) {
        return orange_cpr_auth_fail('auth_runbook_incomplete', 'Runbook session Full Backup mismatch.');
    }
    if (empty($payload['global_maintenance_active'])) {
        return orange_cpr_auth_fail('auth_runbook_incomplete', 'Runbook global maintenance not confirmed.');
    }
    if ((int) ($payload['completed_by_admin_id'] ?? 0) <= 0) {
        return orange_cpr_auth_fail('auth_runbook_incomplete', 'Runbook completed_by_admin_id missing.');
    }

    $cp5 = orange_cpr_checkpoint_load($env, $jobId, 'CP5');
    if (empty($cp5['ok'])) {
        return orange_cpr_auth_fail('auth_checkpoint_invalid', 'CP5 pre-PONR witnesses missing or invalid.');
    }

    return orange_cpr_auth_ok([
        'runbook' => $rb['checkpoint'],
        'runbook_evidence_ref' => basename((string) ($rb['path'] ?? 'runbook_pre_ponr.json')),
    ]);
}

/**
 * @param array<string, mixed>|null $lock
 * @param array<string, mixed> $job
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_auth_validate_lock_ownership(?array $lock, array $job, array $request): array
{
    if ($lock === null || (string) ($lock['job_id'] ?? '') !== (string) ($job['job_id'] ?? '')) {
        return orange_cpr_auth_fail('auth_lock_ownership_drift', 'CPR lock not held by this job.');
    }
    $lease = (string) ($request['lease_token'] ?? '');
    $worker = (string) ($request['worker_id'] ?? '');
    $ownLease = (string) ($lock['ownership']['lease_token'] ?? '');
    $ownWorker = (string) ($lock['ownership']['worker_id'] ?? '');
    if ($lease === '' || $ownLease === '' || !hash_equals($ownLease, $lease)) {
        return orange_cpr_auth_fail('auth_lock_ownership_drift', 'Lock lease_token ownership mismatch.');
    }
    if ($worker !== '' && $ownWorker !== '' && !hash_equals($ownWorker, $worker)) {
        return orange_cpr_auth_fail('auth_lock_ownership_drift', 'Lock worker_id ownership mismatch.');
    }
    if ((string) ($lock['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')) {
        return orange_cpr_auth_fail('auth_lock_ownership_drift', 'Lock package fingerprint drift.');
    }

    return orange_cpr_auth_ok(['lock' => $lock]);
}

function orange_cpr_auth_directory_ensure(string $cprRoot, string $jobId): string
{
    $dir = orange_cpr_auth_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR auth directory.');
    }

    return $dir;
}

function orange_cpr_auth_latest_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_auth_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_ponr_authorization_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_ponr_authorization_load_latest(string $cprRoot, string $jobId): ?array
{
    $path = orange_cpr_auth_latest_path($cprRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Persist sealed authorization (immutable: refuse overwrite of same authorization_id file).
 *
 * @param array<string, mixed> $record
 */
function orange_cpr_ponr_authorization_persist(string $cprRoot, string $jobId, array $record): string
{
    $dir = orange_cpr_auth_directory_ensure($cprRoot, $jobId);
    $authId = (string) ($record['authorization_id'] ?? '');
    if ($authId === '') {
        throw new RuntimeException('authorization_id required.');
    }
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $authId) ?: 'auth';
    $final = $dir . DIRECTORY_SEPARATOR . 'cpr_ponr_authorization_' . $safeId . '.json';
    if (is_file($final)) {
        throw new RuntimeException('Authorization record immutable; file exists.');
    }

    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Authorization encode failed.');
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Authorization tmp write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    $latest = orange_cpr_auth_latest_path($cprRoot, $jobId);
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

/**
 * @param array<string, mixed> $challenge
 */
function orange_cpr_auth_challenge_persist(string $cprRoot, string $jobId, array $challenge): string
{
    $dir = orange_cpr_auth_directory_ensure($cprRoot, $jobId);
    $cid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($challenge['challenge_id'] ?? '')) ?: 'challenge';
    $final = $dir . DIRECTORY_SEPARATOR . 'cpr_auth_challenge_' . $cid . '.json';
    $json = json_encode($challenge, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Challenge encode failed.');
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Challenge tmp write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

/**
 * Assert sealed authorization is usable (not consumed / transferable). Does not execute PONR.
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_ponr_authorization_assert_usable(
    array $env,
    string $jobId,
    string $authorizationId
): array {
    $cprRoot = orange_cpr_resolve_work_root($env);
    $latest = orange_cpr_ponr_authorization_load_latest($cprRoot, $jobId);
    if ($latest === null) {
        return orange_cpr_auth_fail('auth_missing', 'PONR authorization record missing.');
    }
    if (!orange_cpr_auth_verify_seal($latest)) {
        return orange_cpr_auth_fail('auth_unsealed', 'PONR authorization seal verification failed.');
    }
    if ((string) ($latest['authorization_id'] ?? '') !== $authorizationId
        && (string) ($latest['one_time_authorization_id'] ?? '') !== $authorizationId
    ) {
        return orange_cpr_auth_fail('auth_replay', 'Authorization id does not match sealed record (non-transferable).');
    }
    if ((string) ($latest['job_id'] ?? '') !== $jobId) {
        return orange_cpr_auth_fail('auth_replay', 'Authorization job binding mismatch (non-transferable).');
    }
    if (!empty($latest['consumed_at'])) {
        return orange_cpr_auth_fail('auth_replay', 'Authorization already consumed; replay forbidden.');
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract)) {
        return orange_cpr_auth_fail('auth_contract_not_frozen', 'Contract missing for authorization bind check.');
    }
    $digest = orange_cpr_contract_fingerprint_digest($contract);
    if (!hash_equals((string) ($latest['contract_fingerprint'] ?? ''), $digest)) {
        return orange_cpr_auth_fail('auth_fingerprint_drift', 'Authorization contract fingerprint no longer matches.');
    }

    return orange_cpr_auth_ok(['authorization' => $latest]);
}

/**
 * Mark authorization consumed (one-time). Does NOT run PONR mutation engines.
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_ponr_authorization_mark_consumed(
    array $env,
    string $jobId,
    string $authorizationId
): array {
    $check = orange_cpr_ponr_authorization_assert_usable($env, $jobId, $authorizationId);
    if (empty($check['ok'])) {
        return $check;
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    $auth = $check['authorization'];
    $auth['consumed_at'] = gmdate('c');
    $auth['consumption_note'] = 'marked_consumed_without_ponr_mutation';
    $auth = orange_cpr_auth_seal($auth);

    // Immutable primary file: write consumed sidecar + update latest only.
    $dir = orange_cpr_auth_directory_ensure($cprRoot, $jobId);
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $authorizationId) ?: 'auth';
    $consumedPath = $dir . DIRECTORY_SEPARATOR . 'cpr_ponr_authorization_' . $safeId . '_consumed.json';
    $json = json_encode($auth, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return orange_cpr_auth_fail('auth_persist_failed', 'Consumed authorization encode failed.');
    }
    $tmp = $consumedPath . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json) === false) {
        return orange_cpr_auth_fail('auth_persist_failed', 'Consumed authorization write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $consumedPath);

    $latest = orange_cpr_auth_latest_path($cprRoot, $jobId);
    $tmp2 = $latest . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp2, $json) !== false) {
        try {
            orange_cpr_atomic_rename_replace($tmp2, $latest);
        } catch (RuntimeException) {
            @unlink($tmp2);
        }
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.ponr_authorization_consumed',
        'job_id' => $jobId,
        'authorization_id' => $authorizationId,
        'ponr_mutation' => false,
    ]);

    return orange_cpr_auth_ok([
        'authorization' => $auth,
        'ponr_mutation' => false,
        'message' => 'Authorization marked consumed; PONR mutation not executed.',
    ]);
}

/**
 * Explicit refuse helper — PONR mutation engines are out of P3 scope.
 */
function orange_cpr_ponr_mutation_refuse(): array
{
    return orange_cpr_auth_fail(
        'ponr_mutation_forbidden',
        'PONR mutation (DELETE/IMPORT/apply) is not implemented in P3; authorization only.'
    );
}

/**
 * Pre-PONR authorization ceremony (OD-PHRASE / OD-DUAL / OD-RUNBOOK / OD-PERM).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_ponr_authorize(array $env, string $jobId, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_auth_fail('auth_enablement_true_forbidden', $e->getMessage());
    }

    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_auth_fail(
            'auth_enablement_true_forbidden',
            'Enablement must remain FALSE; P3 does not authorize production enablement.'
        );
    }

    if (empty($request['actor_is_super_admin'])) {
        return orange_cpr_auth_fail('auth_actor_not_super_admin', 'Only Super Admin may authorize PONR.');
    }
    $actorAdminId = (int) ($request['actor_admin_id'] ?? 0);
    if ($actorAdminId <= 0) {
        return orange_cpr_auth_fail('auth_actor_not_super_admin', 'Super Admin actor_admin_id required.');
    }

    if (empty($request['password_reauth_ok'])) {
        return orange_cpr_auth_fail('auth_reauth_missing', 'Password re-auth evidence required (never store password).');
    }

    $phrase = (string) ($request['phrase'] ?? '');
    if ($phrase !== ORANGE_CPR_AUTH_PHRASE_LITERAL) {
        return orange_cpr_auth_fail('auth_phrase_invalid', 'Phrase must be exactly RESTORE.');
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_auth_fail('auth_job_missing', $e->getMessage());
    }

    if (!empty($job['ponr_crossed'])) {
        return orange_cpr_auth_fail('auth_ponr_already_crossed', 'PONR already crossed; authorization forbidden.');
    }

    $existing = orange_cpr_ponr_authorization_load_latest($cprRoot, $jobId);
    if (is_array($existing) && orange_cpr_auth_verify_seal($existing) && empty($existing['consumed_at'])) {
        return orange_cpr_auth_fail('auth_duplicate', 'Active PONR authorization already exists for this job.');
    }
    if (is_array($existing) && !empty($existing['consumed_at'])) {
        return orange_cpr_auth_fail('auth_replay', 'Prior authorization was consumed; minting a replay is forbidden.');
    }

    $maxAge = (int) ($request['gate_max_age_seconds'] ?? ORANGE_CPR_AUTH_GATE_MAX_AGE_SECONDS);
    if ($maxAge <= 0) {
        $maxAge = ORANGE_CPR_AUTH_GATE_MAX_AGE_SECONDS;
    }
    $gate = orange_cpr_gate_evaluation_load_latest($cprRoot, $jobId, 'pre_ponr_full');
    $gateCheck = orange_cpr_auth_validate_gate_report($gate, $job, $maxAge);
    if (empty($gateCheck['ok'])) {
        return $gateCheck;
    }

    $lock = orange_cpr_lock_read($cprRoot);
    $lockCheck = orange_cpr_auth_validate_lock_ownership($lock, $job, $request);
    if (empty($lockCheck['ok'])) {
        return $lockCheck;
    }

    $reread = is_array($request['reread'] ?? null) ? $request['reread'] : [];
    $freeze = orange_cpr_contract_freeze_pre_ponr($env, $jobId, $reread, $actorAdminId);
    if (empty($freeze['ok'])) {
        return $freeze;
    }
    $contract = $freeze['contract'];

    $rbCheck = orange_cpr_auth_validate_runbook($env, $jobId, $job, $contract);
    if (empty($rbCheck['ok'])) {
        return $rbCheck;
    }

    $now = gmdate('c');
    $challengeId = orange_cpr_generate_job_id();
    $authorizationId = orange_cpr_generate_job_id();
    $otaId = $authorizationId; // one_time_authorization_id bound to sealed auth
    $phraseHash = hash('sha256', $phrase);
    $contractFp = (string) ($contract['contract_fingerprint'] ?? orange_cpr_contract_fingerprint_digest($contract));
    $auditId = orange_cpr_generate_job_id();

    $challenge = orange_cpr_auth_seal([
        'schema_version' => ORANGE_CPR_AUTH_CHALLENGE_SCHEMA,
        'challenge_id' => $challengeId,
        'job_id' => $jobId,
        'workflow' => (string) ($job['workflow'] ?? ''),
        'admin_id' => $actorAdminId,
        'reauth_ok' => true,
        'reauth_at' => $now,
        'phrase_submitted_hash' => $phraseHash,
        'phrase_accepted' => true,
        'one_time_authorization_id' => $otaId,
        'contract_fingerprint' => $contractFp,
        'runbook_evidence_ref' => (string) ($rbCheck['runbook_evidence_ref'] ?? 'runbook_pre_ponr.json'),
        'created_at' => $now,
        'consumed_at' => null,
        'audit_record_id' => $auditId,
        'engine_version' => ORANGE_CPR_AUTH_ENGINE_VERSION,
    ]);

    $authorization = orange_cpr_auth_seal([
        'schema_version' => ORANGE_CPR_AUTH_SCHEMA,
        'authorization_id' => $authorizationId,
        'one_time_authorization_id' => $otaId,
        'challenge_id' => $challengeId,
        'job_id' => $jobId,
        'idempotency_key' => (string) ($job['idempotency_key'] ?? ''),
        'package_id' => (string) ($job['package_id'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'inventory_snapshot_id' => (string) ($contract['inventory_snapshot_id'] ?? ''),
        'inventory_snapshot_hash' => (string) ($contract['inventory_snapshot_hash'] ?? ''),
        'schema_revision_expected' => $contract['schema_revision_expected'] ?? null,
        'c4_report_hash' => (string) ($contract['c4_report_hash'] ?? ''),
        'c5_report_hash' => (string) ($contract['c5_report_hash'] ?? ''),
        'c6_report_hash' => (string) ($contract['c6_report_hash'] ?? ''),
        'c7_report_hash' => (string) ($contract['c7_report_hash'] ?? ''),
        'c8_report_hash' => (string) ($contract['c8_report_hash'] ?? ''),
        'c8_overall_result' => 'SAFE',
        'session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
        'session_full_backup_fingerprint' => (string) ($contract['session_full_backup_fingerprint'] ?? ''),
        'session_full_backup_pinned' => true,
        'contract_revision' => (int) ($contract['contract_revision'] ?? 0),
        'contract_phase' => 'pre_ponr',
        'contract_fingerprint' => $contractFp,
        'gate_evaluation_ref' => basename((string) ($gate['_path'] ?? ($job['last_gate_eval_ref'] ?? ''))),
        'gate_content_sha256' => (string) ($gate['content_sha256'] ?? ''),
        'lock_lease_fingerprint' => hash('sha256', (string) ($lock['ownership']['lease_token'] ?? '')),
        'lock_worker_id' => (string) ($lock['ownership']['worker_id'] ?? ''),
        'checkpoint_state' => [
            'last_checkpoint_id' => $job['last_checkpoint_id'] ?? null,
            'runbook_evidence_ref' => (string) ($rbCheck['runbook_evidence_ref'] ?? ''),
            'cp1_present' => true,
            'cp5_present' => true,
        ],
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'phrase_accepted' => true,
        'reauth_ok' => true,
        'runbook_completed' => true,
        'enablement_flag_observed' => false,
        'ponr_authorized' => true,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'one_time' => true,
        'immutable' => true,
        'non_transferable' => true,
        'created_at' => $now,
        'consumed_at' => null,
        'audit_record_id' => $auditId,
        'engine_version' => ORANGE_CPR_AUTH_ENGINE_VERSION,
        'scaffold_record_only' => true,
    ]);

    try {
        $challengePath = orange_cpr_auth_challenge_persist($cprRoot, $jobId, $challenge);
        $authPath = orange_cpr_ponr_authorization_persist($cprRoot, $jobId, $authorization);
    } catch (RuntimeException $e) {
        return orange_cpr_auth_fail('auth_persist_failed', $e->getMessage());
    }

    $contract['one_time_authorization_id'] = $otaId;
    $contract['ponr_authorized'] = true;
    $contract['authorization_id'] = $authorizationId;
    $contract['authorization_at'] = $now;
    $contract['contract_fingerprint'] = orange_cpr_contract_fingerprint_digest($contract);
    // Re-seal binding: authorization already sealed with prior fingerprint; keep auth bind
    // on pre-authorization digest (authorization.contract_fingerprint). Contract may gain OTA fields.
    orange_cpr_contract_write($cprRoot, $jobId, $contract);

    $job = orange_cpr_job_read($cprRoot, $jobId);
    $job['one_time_authorization_id'] = $otaId;
    $job['ponr_authorized'] = true;
    $job['updated_at'] = $now;
    $job['enablement_flag_observed'] = false;
    // Critical: do not set ponr_crossed
    $job['ponr_crossed'] = false;
    orange_cpr_job_write($cprRoot, $jobId, $job);

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.ponr_authorization',
        'job_id' => $jobId,
        'authorization_id' => $authorizationId,
        'one_time_authorization_id' => $otaId,
        'challenge_id' => $challengeId,
        'actor_admin_id' => $actorAdminId,
        'contract_fingerprint' => $contractFp,
        'gate_content_sha256' => (string) ($gate['content_sha256'] ?? ''),
        'runbook_evidence_ref' => (string) ($rbCheck['runbook_evidence_ref'] ?? ''),
        'phrase_accepted' => true,
        'reauth_ok' => true,
        'enablement_flag_observed' => false,
        'ponr_authorized' => true,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'audit_record_id' => $auditId,
    ]);

    return orange_cpr_auth_ok([
        'code' => 'ok',
        'message' => 'PONR authorization sealed; mutation not executed.',
        'authorization' => $authorization,
        'challenge' => $challenge,
        'authorization_path' => $authPath,
        'challenge_path' => $challengePath,
        'one_time_authorization_id' => $otaId,
        'ponr_authorized' => true,
        'ponr_crossed' => false,
        'ponr_mutation_executed' => false,
        'enablement_flag_observed' => false,
        'contract_fingerprint' => $contractFp,
    ]);
}
