<?php

declare(strict_types=1);

/**
 * Phase 3B.4K / P0-3 — Explicit Production Cutover Authorization.
 *
 * Final owner authorization record (not a substitute for final_approval).
 * Metadata / gate only: never imports DB, renames uploads, or starts CLI workers.
 */

require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_execution_bridge.php';
require_once __DIR__ . '/restore_final_approval.php';
require_once __DIR__ . '/restore_pre_restore_backup.php';
require_once __DIR__ . '/restore_reauth.php';
require_once __DIR__ . '/restore_production_maintenance.php';
require_once __DIR__ . '/../backup_admin.php';

const ORANGE_RESTORE_PCA_VERSION = '3B.4K-pca-v1';
const ORANGE_RESTORE_PCA_FILE = 'production_cutover_authorization.json';
const ORANGE_RESTORE_PCA_CHALLENGE_FILE = 'production_cutover_authorization_challenge.json';
const ORANGE_RESTORE_PCA_TTL_SECONDS = 300;
const ORANGE_RESTORE_PCA_LOCK = '.production_cutover_authorization.lock';

function orange_restore_pca_record_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PCA_FILE;
}

function orange_restore_pca_challenge_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PCA_CHALLENGE_FILE;
}

function orange_restore_pca_phrase(string $packageId, string $jobId): string
{
    return 'AUTHORIZE PRODUCTION CUTOVER ' . $packageId . ' JOB ' . $jobId;
}

/**
 * @return array{ok:bool,handle:?resource,message:string}
 */
function orange_restore_pca_acquire_lock(string $workRoot, string $jobId): array
{
    $path = orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PCA_LOCK;
    $handle = @fopen($path, 'c+b');
    if ($handle === false) {
        return ['ok' => false, 'handle' => null, 'message' => 'Cannot open cutover authorization lock.'];
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);

        return ['ok' => false, 'handle' => null, 'message' => 'Cannot acquire cutover authorization lock.'];
    }

    return ['ok' => true, 'handle' => $handle, 'message' => 'ok'];
}

/**
 * @param resource|null $handle
 */
function orange_restore_pca_release_lock(mixed $handle): void
{
    if (!is_resource($handle)) {
        return;
    }
    flock($handle, LOCK_UN);
    fclose($handle);
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_pca_load_record(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_pca_record_path($workRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_pca_load_challenge(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_pca_challenge_path($workRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $record
 */
function orange_restore_pca_write_record(string $workRoot, string $jobId, array $record): void
{
    $path = orange_restore_pca_record_path($workRoot, $jobId);
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write production_cutover_authorization.json');
    }
}

function orange_restore_pca_execution_contract_hash(string $workRoot, string $jobId): string
{
    $path = orange_restore_exec_contract_path($workRoot, $jobId);
    if (!is_file($path)) {
        return '';
    }
    $hash = hash_file('sha256', $path);

    return is_string($hash) ? $hash : '';
}

/**
 * @return array{ok:bool,hash:string,code:string}
 */
function orange_restore_pca_rollback_anchor_hash(string $workRoot, string $jobId): array
{
    $anchor = orange_restore_pre_backup_load_record($workRoot, $jobId);
    if (!is_array($anchor) || empty($anchor['ready_for_rollback']) || empty($anchor['retention_pinned'])) {
        return ['ok' => false, 'hash' => '', 'code' => 'missing_rollback_anchor'];
    }
    $material = implode('|', [
        (string) ($anchor['framework_job_id'] ?? $jobId),
        (string) ($anchor['rollback_package_id'] ?? ''),
        (string) ($anchor['rollback_package_fingerprint'] ?? ''),
        (string) ($anchor['package_fingerprint'] ?? ''),
        (string) ($anchor['retention_pin_id'] ?? ''),
        !empty($anchor['ready_for_rollback']) ? '1' : '0',
        !empty($anchor['retention_pinned']) ? '1' : '0',
    ]);

    return ['ok' => true, 'hash' => hash('sha256', $material), 'code' => 'ok'];
}

/**
 * Live binding hashes for challenge / authorization / import gate.
 *
 * @return array{ok:bool,code:string,bindings?:array<string,mixed>}
 */
function orange_restore_pca_live_bindings(string $workRoot, string $jobId, string $backupRoot): array
{
    $job = orange_restore_fw_read($workRoot, $jobId);
    if ((string) ($job['package_type'] ?? '') !== 'full_disaster') {
        return ['ok' => false, 'code' => 'country_production_restore_not_enabled'];
    }

    $packageId = (string) ($job['package_id'] ?? '');
    $packageFingerprint = (string) ($job['package_fingerprint'] ?? '');
    if ($packageId === '' || $packageFingerprint === '') {
        return ['ok' => false, 'code' => 'package_fingerprint_missing'];
    }

    $fp = orange_restore_exec_build_package_fingerprint($backupRoot, 'full_disaster', $packageId, null);
    $liveFp = (string) ($fp['fingerprint'] ?? '');
    if ($liveFp === '' || !hash_equals($packageFingerprint, $liveFp)) {
        return ['ok' => false, 'code' => 'package_fingerprint_mismatch'];
    }

    $approvalPath = orange_restore_final_approval_record_path($workRoot, $jobId);
    if (!is_file($approvalPath)) {
        return ['ok' => false, 'code' => 'invalid_approval'];
    }
    $approval = json_decode((string) file_get_contents($approvalPath), true);
    if (!is_array($approval) || empty($approval['approval_consumed'])) {
        return ['ok' => false, 'code' => 'invalid_approval'];
    }
    $approvalHash = orange_restore_bridge_approval_hash($approval);

    $contractHash = orange_restore_pca_execution_contract_hash($workRoot, $jobId);
    if ($contractHash === '') {
        return ['ok' => false, 'code' => 'execution_contract_missing'];
    }
    try {
        $contract = orange_restore_load_execution_contract($workRoot, $jobId);
        $validation = orange_restore_validate_execution_contract($workRoot, $jobId, $backupRoot, $contract);
        if (empty($validation['ok'])) {
            return ['ok' => false, 'code' => (string) ($validation['code'] ?? 'invalid_execution_contract')];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'code' => trim($e->getMessage()) !== '' ? trim($e->getMessage()) : 'invalid_execution_contract'];
    }

    $anchor = orange_restore_pca_rollback_anchor_hash($workRoot, $jobId);
    if (!$anchor['ok']) {
        return ['ok' => false, 'code' => $anchor['code']];
    }

    return [
        'ok' => true,
        'code' => 'ok',
        'bindings' => [
            'job_id' => $jobId,
            'package_id' => $packageId,
            'package_fingerprint' => $packageFingerprint,
            'execution_contract_hash' => $contractHash,
            'rollback_anchor_hash' => $anchor['hash'],
            'approval_hash' => $approvalHash,
        ],
    ];
}

/**
 * Preconditions for creating a cutover-authorization challenge (after maintenance lineage).
 *
 * @param array<string, mixed> $admin
 * @return array{ok:bool,code:string,message:string}
 */
function orange_restore_pca_precheck(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    array $admin,
    PDO $pdo
): array {
    if ((int) ($admin['id'] ?? 0) <= 0) {
        return ['ok' => false, 'code' => 'invalid_operator', 'message' => 'Operator required.'];
    }
    if (!function_exists('orange_restore_admin_assert_package_type_permission')) {
        require_once __DIR__ . '/../restore_admin.php';
    }
    orange_restore_admin_assert_package_type_permission($admin, $pdo, 'full_disaster');

    $job = orange_restore_fw_read($workRoot, $jobId);
    if (!empty($job['execution_started'])) {
        return ['ok' => false, 'code' => 'execution_already_started', 'message' => 'execution_started must be false.'];
    }

    $existing = orange_restore_pca_load_record($workRoot, $jobId);
    if (is_array($existing)) {
        if (!empty($existing['authorization_consumed']) || !empty($existing['cutover_started'])) {
            return ['ok' => false, 'code' => 'authorization_already_consumed', 'message' => 'Authorization already consumed.'];
        }
        $expiresAt = strtotime((string) ($existing['authorization_expires_at'] ?? ''));
        if ($expiresAt !== false && $expiresAt > time()) {
            return ['ok' => false, 'code' => 'authorization_already_active', 'message' => 'Active authorization already exists.'];
        }
    }

    $maint = orange_restore_maint_fw_read($workRoot);
    if ((string) ($maint['state'] ?? '') !== ORANGE_RESTORE_MAINT_STATE_ACTIVE
        || (string) ($maint['related_job_id'] ?? '') !== $jobId) {
        return ['ok' => false, 'code' => 'maintenance_not_active', 'message' => 'Framework maintenance must be active for this job.'];
    }

    $base = orange_restore_prod_maint_validate($workRoot, $jobId, $backupRoot, true);
    if (!($base['ok'] ?? false)) {
        return [
            'ok' => false,
            'code' => (string) ($base['code'] ?? 'lineage_invalid'),
            'message' => 'Production lineage gates failed.',
        ];
    }

    $bindings = orange_restore_pca_live_bindings($workRoot, $jobId, $backupRoot);
    if (!($bindings['ok'] ?? false)) {
        return ['ok' => false, 'code' => (string) $bindings['code'], 'message' => 'Binding revalidation failed.'];
    }

    return ['ok' => true, 'code' => 'ok', 'message' => 'ok'];
}

/**
 * @param array<string, mixed> $admin
 * @return array<string, mixed>
 */
function orange_restore_pca_create_challenge(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    array $admin,
    PDO $pdo
): array {
    $pre = orange_restore_pca_precheck($workRoot, $jobId, $backupRoot, $admin, $pdo);
    if (!($pre['ok'] ?? false)) {
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_cutover_authorization_challenge_rejected',
            'result' => 'fail',
            'code' => $pre['code'],
            'operator_username' => (string) ($admin['username'] ?? ''),
        ]);
        throw new RuntimeException((string) $pre['code']);
    }

    $bindings = orange_restore_pca_live_bindings($workRoot, $jobId, $backupRoot);
    /** @var array<string, mixed> $b */
    $b = $bindings['bindings'] ?? [];
    $packageId = (string) ($b['package_id'] ?? '');
    $operatorId = (int) ($admin['id'] ?? 0);
    $operator = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin'));
    $phrase = orange_restore_pca_phrase($packageId, $jobId);
    $nonce = bin2hex(random_bytes(32));
    $now = time();
    $sessionHash = orange_restore_final_approval_session_id();

    $challenge = [
        'challenge_version' => ORANGE_RESTORE_PCA_VERSION,
        'job_id' => $jobId,
        'package_id' => $packageId,
        'package_fingerprint' => (string) ($b['package_fingerprint'] ?? ''),
        'execution_contract_hash' => (string) ($b['execution_contract_hash'] ?? ''),
        'rollback_anchor_hash' => (string) ($b['rollback_anchor_hash'] ?? ''),
        'approval_hash' => (string) ($b['approval_hash'] ?? ''),
        'operator_admin_id' => $operatorId,
        'operator_username' => $operator,
        'session_id_hash' => $sessionHash,
        'nonce_hash' => hash('sha256', $nonce),
        'owner_confirmation_phrase_hash' => hash('sha256', $phrase),
        'created_at' => gmdate('c', $now),
        'expires_at' => gmdate('c', $now + ORANGE_RESTORE_PCA_TTL_SECONDS),
        'consumed_at' => '',
        'execution_started' => false,
        'cutover_started' => false,
    ];

    $path = orange_restore_pca_challenge_path($workRoot, $jobId);
    $json = json_encode($challenge, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write cutover authorization challenge.');
    }

    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_cutover_authorization_challenge_created',
        'result' => 'ok',
        'operator_username' => $operator,
        'expires_at' => $challenge['expires_at'],
        'execution_started' => false,
        'cutover_started' => false,
    ]);

    return [
        'nonce' => $nonce,
        'expires_at' => $challenge['expires_at'],
        'required_confirmation_phrase' => $phrase,
        'job_id' => $jobId,
        'package_id' => $packageId,
        'bindings' => [
            'package_fingerprint' => $challenge['package_fingerprint'],
            'execution_contract_hash' => $challenge['execution_contract_hash'],
            'rollback_anchor_hash' => $challenge['rollback_anchor_hash'],
            'approval_hash' => $challenge['approval_hash'],
        ],
        'warning' => 'Cutover authorization challenge only. No production import, wipe, or uploads cutover.',
        'execution_started' => false,
        'cutover_started' => false,
    ];
}

/**
 * @param array<string, mixed> $admin
 * @return array{authorization:array<string,mixed>,public:array<string,mixed>}
 */
function orange_restore_pca_finalize(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    array $admin,
    PDO $pdo,
    string $packageIdInput,
    string $confirmationPhrase,
    string $nonce,
    string $password,
    string $authorizationReason
): array {
    $operator = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin'));
    $operatorId = (int) ($admin['id'] ?? 0);
    $reason = trim($authorizationReason);
    if ($reason === '' || mb_strlen($reason) < 8) {
        throw new RuntimeException('authorization_reason_required');
    }
    if (mb_strlen($reason) > 500) {
        throw new RuntimeException('authorization_reason_too_long');
    }

    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_cutover_authorization_attempted',
        'result' => 'ok',
        'operator_username' => $operator,
    ]);

    if (trim($password) === '') {
        throw new RuntimeException('recent_authentication_not_available');
    }
    if (!orange_restore_verify_operator_password($pdo, $operatorId, $password)) {
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_cutover_authorization_rejected',
            'result' => 'fail',
            'code' => 'recent_authentication_failed',
            'operator_username' => $operator,
        ]);
        throw new RuntimeException('recent_authentication_failed');
    }

    $lock = orange_restore_pca_acquire_lock($workRoot, $jobId);
    if (!$lock['ok']) {
        throw new RuntimeException((string) $lock['message']);
    }

    try {
        $pre = orange_restore_pca_precheck($workRoot, $jobId, $backupRoot, $admin, $pdo);
        if (!($pre['ok'] ?? false)) {
            throw new RuntimeException((string) $pre['code']);
        }

        $challenge = orange_restore_pca_load_challenge($workRoot, $jobId);
        if ($challenge === null) {
            throw new RuntimeException('authorization_challenge_missing');
        }
        if ((string) ($challenge['consumed_at'] ?? '') !== '') {
            throw new RuntimeException('authorization_challenge_replay');
        }
        $expiresAt = strtotime((string) ($challenge['expires_at'] ?? ''));
        if ($expiresAt === false || $expiresAt < time()) {
            throw new RuntimeException('authorization_challenge_expired');
        }
        if ((int) ($challenge['operator_admin_id'] ?? 0) !== $operatorId) {
            throw new RuntimeException('authorization_wrong_operator');
        }
        $sessionHash = orange_restore_final_approval_session_id();
        if (!hash_equals((string) ($challenge['session_id_hash'] ?? ''), $sessionHash)) {
            throw new RuntimeException('authorization_wrong_session');
        }
        if (!hash_equals((string) ($challenge['nonce_hash'] ?? ''), hash('sha256', $nonce))) {
            throw new RuntimeException('authorization_nonce_invalid');
        }
        if (!hash_equals((string) ($challenge['job_id'] ?? ''), $jobId)) {
            throw new RuntimeException('authorization_wrong_job');
        }
        if (!hash_equals((string) ($challenge['package_id'] ?? ''), trim($packageIdInput))) {
            throw new RuntimeException('authorization_wrong_package');
        }
        $expectedPhrase = orange_restore_pca_phrase((string) $challenge['package_id'], $jobId);
        if (!hash_equals((string) ($challenge['owner_confirmation_phrase_hash'] ?? ''), hash('sha256', $confirmationPhrase))
            || !hash_equals($expectedPhrase, $confirmationPhrase)) {
            throw new RuntimeException('authorization_phrase_invalid');
        }

        $live = orange_restore_pca_live_bindings($workRoot, $jobId, $backupRoot);
        if (!($live['ok'] ?? false)) {
            throw new RuntimeException((string) $live['code']);
        }
        /** @var array<string, mixed> $b */
        $b = $live['bindings'] ?? [];
        foreach ([
            'package_fingerprint',
            'execution_contract_hash',
            'rollback_anchor_hash',
            'approval_hash',
        ] as $key) {
            if (!hash_equals((string) ($challenge[$key] ?? ''), (string) ($b[$key] ?? ''))) {
                $code = match ($key) {
                    'package_fingerprint' => 'authorization_package_fingerprint_changed',
                    'execution_contract_hash' => 'authorization_execution_contract_changed',
                    'rollback_anchor_hash' => 'authorization_rollback_anchor_changed',
                    'approval_hash' => 'authorization_approval_changed',
                    default => 'authorization_binding_changed',
                };
                throw new RuntimeException($code);
            }
        }

        $now = time();
        $authorization = [
            'authorization_version' => ORANGE_RESTORE_PCA_VERSION,
            'job_id' => $jobId,
            'package_id' => (string) ($b['package_id'] ?? ''),
            'package_fingerprint' => (string) ($b['package_fingerprint'] ?? ''),
            'execution_contract_hash' => (string) ($b['execution_contract_hash'] ?? ''),
            'rollback_anchor_hash' => (string) ($b['rollback_anchor_hash'] ?? ''),
            'approval_hash' => (string) ($b['approval_hash'] ?? ''),
            'authorized_by' => $operator,
            'authorized_by_admin_id' => $operatorId,
            'authorized_at' => gmdate('c', $now),
            'authorization_reason' => $reason,
            'owner_confirmation_phrase_hash' => hash('sha256', $confirmationPhrase),
            'authorization_nonce_hash' => hash('sha256', $nonce),
            'session_id_hash' => $sessionHash,
            'authorization_expires_at' => gmdate('c', $now + ORANGE_RESTORE_PCA_TTL_SECONDS),
            'authorization_consumed' => false,
            'cutover_started' => false,
            'execution_started' => false,
        ];
        orange_restore_pca_write_record($workRoot, $jobId, $authorization);

        $challenge['consumed_at'] = gmdate('c', $now);
        $chPath = orange_restore_pca_challenge_path($workRoot, $jobId);
        $chJson = json_encode($challenge, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($chJson === false || file_put_contents($chPath, $chJson . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Cannot update cutover authorization challenge.');
        }

        $job = orange_restore_fw_read($workRoot, $jobId);
        $job['production_cutover_authorization_file'] = ORANGE_RESTORE_PCA_FILE;
        $job['production_cutover_authorized'] = true;
        $job['execution_started'] = false;
        orange_restore_fw_write($workRoot, $job);

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_cutover_authorization_granted',
            'result' => 'ok',
            'operator_username' => $operator,
            'authorization_expires_at' => $authorization['authorization_expires_at'],
            'authorization_consumed' => false,
            'cutover_started' => false,
            'execution_started' => false,
        ]);

        return [
            'authorization' => $authorization,
            'public' => orange_restore_pca_public_record($authorization),
        ];
    } catch (Throwable $e) {
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_cutover_authorization_rejected',
            'result' => 'fail',
            'code' => trim($e->getMessage()) !== '' ? trim($e->getMessage()) : 'authorization_failed',
            'operator_username' => $operator,
        ]);
        throw $e;
    } finally {
        orange_restore_pca_release_lock($lock['handle'] ?? null);
    }
}

/**
 * @param array<string, mixed> $record
 * @return array<string, mixed>
 */
function orange_restore_pca_public_record(array $record): array
{
    return [
        'authorization_version' => (string) ($record['authorization_version'] ?? ''),
        'job_id' => (string) ($record['job_id'] ?? ''),
        'package_id' => (string) ($record['package_id'] ?? ''),
        'package_fingerprint' => (string) ($record['package_fingerprint'] ?? ''),
        'execution_contract_hash' => (string) ($record['execution_contract_hash'] ?? ''),
        'rollback_anchor_hash' => (string) ($record['rollback_anchor_hash'] ?? ''),
        'approval_hash' => (string) ($record['approval_hash'] ?? ''),
        'authorized_by' => (string) ($record['authorized_by'] ?? ''),
        'authorized_by_admin_id' => (int) ($record['authorized_by_admin_id'] ?? 0),
        'authorized_at' => (string) ($record['authorized_at'] ?? ''),
        'authorization_reason' => (string) ($record['authorization_reason'] ?? ''),
        'authorization_expires_at' => (string) ($record['authorization_expires_at'] ?? ''),
        'authorization_consumed' => (bool) ($record['authorization_consumed'] ?? false),
        'cutover_started' => (bool) ($record['cutover_started'] ?? false),
        'execution_started' => false,
        'owner_confirmation_phrase_hash' => (string) ($record['owner_confirmation_phrase_hash'] ?? ''),
        'authorization_nonce_hash' => (string) ($record['authorization_nonce_hash'] ?? ''),
    ];
}

/**
 * @return array{ok:bool,code:string,details:array<string,mixed>}
 */
function orange_restore_pca_validate_for_import(
    string $workRoot,
    string $jobId,
    string $backupRoot
): array {
    $details = [
        'authorization_present' => false,
        'authorization_valid' => false,
        'bindings_match' => false,
        'not_expired_or_in_progress' => false,
        'one_time_ok' => false,
    ];

    $record = orange_restore_pca_load_record($workRoot, $jobId);
    if ($record === null) {
        return ['ok' => false, 'code' => 'production_cutover_authorization_required', 'details' => $details];
    }
    $details['authorization_present'] = true;

    if ((string) ($record['job_id'] ?? '') !== $jobId) {
        return ['ok' => false, 'code' => 'authorization_wrong_job', 'details' => $details];
    }

    $live = orange_restore_pca_live_bindings($workRoot, $jobId, $backupRoot);
    if (!($live['ok'] ?? false)) {
        return ['ok' => false, 'code' => (string) $live['code'], 'details' => $details];
    }
    /** @var array<string, mixed> $b */
    $b = $live['bindings'] ?? [];
    foreach ([
        'package_id',
        'package_fingerprint',
        'execution_contract_hash',
        'rollback_anchor_hash',
        'approval_hash',
    ] as $key) {
        if (!hash_equals((string) ($record[$key] ?? ''), (string) ($b[$key] ?? ''))) {
            $code = match ($key) {
                'package_id' => 'authorization_wrong_package',
                'package_fingerprint' => 'authorization_package_fingerprint_changed',
                'execution_contract_hash' => 'authorization_execution_contract_changed',
                'rollback_anchor_hash' => 'authorization_rollback_anchor_changed',
                'approval_hash' => 'authorization_approval_changed',
                default => 'authorization_binding_changed',
            };

            return ['ok' => false, 'code' => $code, 'details' => $details];
        }
    }
    $details['bindings_match'] = true;

    $consumed = !empty($record['authorization_consumed']);
    $started = !empty($record['cutover_started']);

    if ($started) {
        if (!$consumed) {
            return ['ok' => false, 'code' => 'authorization_state_corrupt', 'details' => $details];
        }
        $details['not_expired_or_in_progress'] = true;
        $details['one_time_ok'] = true;
        $details['authorization_valid'] = true;

        return ['ok' => true, 'code' => 'ok', 'details' => $details];
    }

    if ($consumed) {
        return ['ok' => false, 'code' => 'authorization_already_consumed', 'details' => $details];
    }
    $details['one_time_ok'] = true;

    $expiresAt = strtotime((string) ($record['authorization_expires_at'] ?? ''));
    if ($expiresAt === false || $expiresAt < time()) {
        return ['ok' => false, 'code' => 'authorization_expired', 'details' => $details];
    }
    $details['not_expired_or_in_progress'] = true;
    $details['authorization_valid'] = true;

    return ['ok' => true, 'code' => 'ok', 'details' => $details];
}

/**
 * Mark authorization consumed at the point production cutover mutation begins (import wipe).
 */
function orange_restore_pca_consume_for_cutover_start(string $workRoot, string $jobId): void
{
    $lock = orange_restore_pca_acquire_lock($workRoot, $jobId);
    if (!$lock['ok']) {
        throw new RuntimeException((string) $lock['message']);
    }
    try {
        $record = orange_restore_pca_load_record($workRoot, $jobId);
        if ($record === null) {
            throw new RuntimeException('production_cutover_authorization_required');
        }
        if (!empty($record['cutover_started']) && !empty($record['authorization_consumed'])) {
            return;
        }
        if (!empty($record['authorization_consumed']) && empty($record['cutover_started'])) {
            throw new RuntimeException('authorization_already_consumed');
        }
        $expiresAt = strtotime((string) ($record['authorization_expires_at'] ?? ''));
        if ($expiresAt === false || $expiresAt < time()) {
            throw new RuntimeException('authorization_expired');
        }
        $record['authorization_consumed'] = true;
        $record['cutover_started'] = true;
        $record['consumed_at'] = gmdate('c');
        orange_restore_pca_write_record($workRoot, $jobId, $record);
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_cutover_authorization_consumed',
            'result' => 'ok',
            'cutover_started' => true,
            'authorization_consumed' => true,
        ]);
    } finally {
        orange_restore_pca_release_lock($lock['handle'] ?? null);
    }
}

/**
 * Read-only status for Restore Center / API.
 *
 * @return array<string, mixed>
 */
function orange_restore_pca_status(string $workRoot, string $jobId, string $backupRoot): array
{
    $record = orange_restore_pca_load_record($workRoot, $jobId);
    $challenge = orange_restore_pca_load_challenge($workRoot, $jobId);
    $gate = $record !== null
        ? orange_restore_pca_validate_for_import($workRoot, $jobId, $backupRoot)
        : ['ok' => false, 'code' => 'production_cutover_authorization_required', 'details' => []];

    return [
        'present' => $record !== null,
        'challenge_present' => $challenge !== null && (string) ($challenge['consumed_at'] ?? '') === '',
        'challenge_expires_at' => (string) ($challenge['expires_at'] ?? ''),
        'authorization' => $record !== null ? orange_restore_pca_public_record($record) : null,
        'import_gate_ok' => (bool) ($gate['ok'] ?? false),
        'import_gate_code' => (string) ($gate['code'] ?? ''),
        'execution_started' => false,
        'cutover_started' => (bool) ($record['cutover_started'] ?? false),
        'warning' => 'Production cutover authorization is metadata only until CLI import consumes it.',
    ];
}
