<?php

declare(strict_types=1);

/**
 * Phase 3B.4B — Production Maintenance Activation Framework.
 *
 * Activates framework maintenance metadata only.
 * NEVER: production DB import/wipe, file restore, config switch, cutover, rollback,
 * or automatic restore-worker invocation.
 */

require_once __DIR__ . '/restore_maintenance_framework.php';
require_once __DIR__ . '/restore_execution_orchestrator.php';
require_once __DIR__ . '/restore_execution_bridge.php';
require_once __DIR__ . '/restore_final_approval.php';
require_once __DIR__ . '/restore_version_lock.php';
require_once __DIR__ . '/restore_pre_restore_backup.php';
require_once __DIR__ . '/restore_shadow_verify.php';
require_once __DIR__ . '/restore_shadow_smoke.php';
require_once __DIR__ . '/restore_reauth.php';
require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../backup_environment.php';

const ORANGE_RESTORE_PROD_MAINT_VERSION = '3B.4B-v1';
const ORANGE_RESTORE_PROD_MAINT_CHALLENGE_FILE = 'maintenance_activation_challenge.json';
const ORANGE_RESTORE_PROD_MAINT_CHALLENGE_TTL_SECONDS = 600;
const ORANGE_RESTORE_PROD_MAINT_RECORD_FILE = 'production_maintenance.json';

function orange_restore_prod_maint_challenge_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PROD_MAINT_CHALLENGE_FILE;
}

function orange_restore_prod_maint_record_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PROD_MAINT_RECORD_FILE;
}

/**
 * @return list<string>
 */
function orange_restore_prod_maint_entry_statuses(): array
{
    return [
        ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_READY,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_MANUAL_REVIEW,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_WARNING,
        ORANGE_RESTORE_FW_STATUS_MAINTENANCE_REQUESTED,
    ];
}

/**
 * @return array{ok:bool,code:string,details:array<string,mixed>}
 */
function orange_restore_prod_maint_validate_gates(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    bool $requireLockHeld = true
): array {
    $details = [
        'approved_waiting_execution' => false,
        'rollback_anchor' => false,
        'execution_contract' => false,
        'version_lock' => false,
        'shadow_readiness' => false,
        'smoke_report' => false,
        'package_unchanged' => false,
        'approval_valid' => false,
        'execution_lock' => false,
        'no_conflicting_backup' => false,
        'no_conflicting_restore' => false,
    ];

    $job = orange_restore_fw_read($workRoot, $jobId);
    if ((string) ($job['package_type'] ?? '') === 'country_recovery') {
        return ['ok' => false, 'code' => 'country_production_restore_not_enabled', 'details' => $details];
    }
    if ((string) ($job['package_type'] ?? '') !== 'full_disaster') {
        return ['ok' => false, 'code' => 'package_type_mismatch', 'details' => $details];
    }
    if (!empty($job['execution_started'])) {
        return ['ok' => false, 'code' => 'execution_already_started', 'details' => $details];
    }

    $status = (string) ($job['status'] ?? '');
    $approvedAt = (string) ($job['approved_at'] ?? '');
    $details['approved_waiting_execution'] = $status === ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION
        || $approvedAt !== ''
        || is_file(orange_restore_final_approval_record_path($workRoot, $jobId));
    if (!$details['approved_waiting_execution']) {
        return ['ok' => false, 'code' => 'not_approved_waiting_execution', 'details' => $details];
    }

    $anchor = orange_restore_pre_backup_load_record($workRoot, $jobId);
    $details['rollback_anchor'] = is_array($anchor)
        && !empty($anchor['ready_for_rollback'])
        && !empty($anchor['retention_pinned']);
    if (!$details['rollback_anchor']) {
        return ['ok' => false, 'code' => 'missing_rollback_anchor', 'details' => $details];
    }

    $approvalPath = orange_restore_final_approval_record_path($workRoot, $jobId);
    if (!is_file($approvalPath)) {
        return ['ok' => false, 'code' => 'invalid_approval', 'details' => $details];
    }
    $approval = json_decode((string) file_get_contents($approvalPath), true);
    if (!is_array($approval) || empty($approval['approval_consumed'])) {
        return ['ok' => false, 'code' => 'invalid_approval', 'details' => $details];
    }
    if (!empty($approval['execution_started']) || !empty($approval['cli_invoked'])) {
        return ['ok' => false, 'code' => 'invalid_approval', 'details' => $details];
    }
    $details['approval_valid'] = true;

    try {
        $contract = orange_restore_load_execution_contract($workRoot, $jobId);
        $validation = orange_restore_validate_execution_contract($workRoot, $jobId, $backupRoot, $contract);
        $details['execution_contract'] = (bool) ($validation['ok'] ?? false);
        if (!$details['execution_contract']) {
            return [
                'ok' => false,
                'code' => (string) ($validation['code'] ?? 'invalid_execution_contract'),
                'details' => $details,
            ];
        }
    } catch (Throwable) {
        return ['ok' => false, 'code' => 'invalid_execution_contract', 'details' => $details];
    }

    $versionLock = orange_restore_version_lock_evaluate($workRoot, $jobId, $backupRoot);
    $details['version_lock'] = (bool) ($versionLock['ok'] ?? false);
    if (!$details['version_lock']) {
        return [
            'ok' => false,
            'code' => (string) (($versionLock['reasons'][0] ?? 'invalid_version_lock')),
            'details' => $details + ['version_lock_reasons' => $versionLock['reasons'] ?? []],
        ];
    }

    $verifyReport = orange_restore_shadow_verify_load_report($workRoot, $jobId);
    $details['shadow_readiness'] = is_array($verifyReport)
        && (
            strtoupper((string) ($verifyReport['overall_result'] ?? '')) === 'PASS'
            || strtoupper((string) ($verifyReport['overall_result'] ?? '')) === 'READY'
            || (int) ($verifyReport['readiness_score'] ?? 0) >= 85
        );
    if (!$details['shadow_readiness']) {
        $shadowMeta = function_exists('orange_restore_shadow_load_meta')
            ? orange_restore_shadow_load_meta($workRoot, $jobId)
            : null;
        $details['shadow_readiness'] = is_array($shadowMeta) && !empty($shadowMeta['ready']);
    }
    if (!$details['shadow_readiness']) {
        return ['ok' => false, 'code' => 'invalid_shadow_readiness', 'details' => $details];
    }

    $smoke = orange_restore_shadow_smoke_load_report($workRoot, $jobId);
    $smokeResult = strtoupper((string) (($smoke['overall_result'] ?? '') ?: ''));
    $details['smoke_report'] = is_array($smoke)
        && in_array($smokeResult, ['READY', 'WARNING', 'PASS'], true);
    if (!$details['smoke_report']) {
        return ['ok' => false, 'code' => 'invalid_smoke_report', 'details' => $details];
    }

    $packageId = (string) ($job['package_id'] ?? '');
    $fp = orange_restore_exec_build_package_fingerprint($backupRoot, 'full_disaster', $packageId, null);
    $storedFp = (string) ($job['package_fingerprint'] ?? '');
    $approvalFp = (string) ($approval['package_fingerprint'] ?? '');
    $details['package_unchanged'] = $storedFp !== ''
        && hash_equals($storedFp, (string) ($fp['fingerprint'] ?? ''))
        && ($approvalFp === '' || hash_equals($approvalFp, $storedFp));
    if (!$details['package_unchanged']) {
        return ['ok' => false, 'code' => 'package_changed', 'details' => $details];
    }

    if ($requireLockHeld) {
        $lock = orange_restore_exec_lock_status($workRoot);
        $heldJob = (string) (($lock['payload'] ?? [])['job_id'] ?? '');
        $details['execution_lock'] = $lock['held'] && !$lock['stale'] && $heldJob === $jobId;
        if (!$details['execution_lock']) {
            return ['ok' => false, 'code' => 'execution_lock_not_held', 'details' => $details];
        }
    }

    $fullLock = orange_backup_admin_full_lock_status($backupRoot);
    $countryLock = orange_backup_admin_country_lock_status($backupRoot);
    $details['no_conflicting_backup'] = empty($fullLock['held']) && empty($countryLock['held']);
    if (!$details['no_conflicting_backup']) {
        return ['ok' => false, 'code' => 'conflicting_backup_job', 'details' => $details];
    }

    foreach (orange_restore_fw_list_ids($workRoot) as $otherId) {
        if ($otherId === $jobId) {
            continue;
        }
        try {
            $other = orange_restore_fw_read($workRoot, $otherId);
        } catch (Throwable) {
            continue;
        }
        $otherStatus = (string) ($other['status'] ?? '');
        if (in_array($otherStatus, [
            ORANGE_RESTORE_FW_STATUS_MAINTENANCE_REQUESTED,
            ORANGE_RESTORE_FW_STATUS_MAINTENANCE_VALIDATING,
            ORANGE_RESTORE_FW_STATUS_MAINTENANCE_ACTIVE,
            ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_RUNNING,
            ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
            ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_RUNNING,
            ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_RUNNING,
        ], true)) {
            return ['ok' => false, 'code' => 'conflicting_restore_job', 'details' => $details];
        }
    }
    $details['no_conflicting_restore'] = true;

    $maint = orange_restore_maint_fw_read($workRoot);
    $maintState = (string) ($maint['state'] ?? '');
    $related = (string) ($maint['related_job_id'] ?? '');
    if (in_array($maintState, [
        ORANGE_RESTORE_MAINT_STATE_ACTIVE,
        ORANGE_RESTORE_MAINT_STATE_VALIDATING,
        ORANGE_RESTORE_MAINT_STATE_RELEASING,
    ], true) && $related !== '' && $related !== $jobId) {
        return ['ok' => false, 'code' => 'duplicate_maintenance', 'details' => $details];
    }

    return ['ok' => false, 'code' => 'ok', 'details' => $details];
}

/**
 * Fix ok flag (validate helper returns code ok with ok:false typo prevention).
 *
 * @return array{ok:bool,code:string,details:array<string,mixed>}
 */
function orange_restore_prod_maint_validate(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    bool $requireLockHeld = true
): array {
    $result = orange_restore_prod_maint_validate_gates($workRoot, $jobId, $backupRoot, $requireLockHeld);
    if (($result['code'] ?? '') === 'ok') {
        $result['ok'] = true;
    }

    return $result;
}

/**
 * @param array<string, mixed> $admin
 * @return array<string, mixed>
 */
function orange_restore_prod_maint_write_challenge(
    string $workRoot,
    string $jobId,
    array $admin
): array {
    $operatorId = (int) ($admin['id'] ?? 0);
    $nonce = bin2hex(random_bytes(16));
    $payload = [
        'job_id' => $jobId,
        'operator_admin_id' => $operatorId,
        'session_id_hash' => orange_restore_final_approval_session_id(),
        'nonce_hash' => hash('sha256', $nonce),
        'created_at' => gmdate('c'),
        'expires_at' => gmdate('c', time() + ORANGE_RESTORE_PROD_MAINT_CHALLENGE_TTL_SECONDS),
        'consumed_at' => '',
    ];
    file_put_contents(
        orange_restore_prod_maint_challenge_path($workRoot, $jobId),
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n",
        LOCK_EX
    );

    return [
        'nonce' => $nonce,
        'expires_at' => (string) $payload['expires_at'],
        'ttl_seconds' => ORANGE_RESTORE_PROD_MAINT_CHALLENGE_TTL_SECONDS,
    ];
}

/**
 * @param array<string, mixed> $admin
 */
function orange_restore_prod_maint_assert_fresh_auth(
    string $workRoot,
    string $jobId,
    array $admin,
    PDO $pdo,
    string $password,
    string $nonce
): void {
    if (trim($password) === '') {
        throw new RuntimeException('recent_authentication_not_available');
    }
    $operatorId = (int) ($admin['id'] ?? 0);
    if (!orange_restore_verify_operator_password($pdo, $operatorId, $password)) {
        throw new RuntimeException('recent_authentication_failed');
    }

    $path = orange_restore_prod_maint_challenge_path($workRoot, $jobId);
    if (!is_file($path)) {
        throw new RuntimeException('maintenance_auth_stale');
    }
    $challenge = json_decode((string) file_get_contents($path), true);
    if (!is_array($challenge)) {
        throw new RuntimeException('maintenance_auth_stale');
    }
    if ((string) ($challenge['consumed_at'] ?? '') !== '') {
        throw new RuntimeException('maintenance_auth_stale');
    }
    $expires = strtotime((string) ($challenge['expires_at'] ?? ''));
    if ($expires === false || time() > $expires) {
        throw new RuntimeException('maintenance_auth_stale');
    }
    if ((int) ($challenge['operator_admin_id'] ?? 0) !== $operatorId) {
        throw new RuntimeException('maintenance_auth_stale');
    }
    if (!hash_equals((string) ($challenge['session_id_hash'] ?? ''), orange_restore_final_approval_session_id())) {
        throw new RuntimeException('maintenance_auth_stale');
    }
    if (!hash_equals((string) ($challenge['nonce_hash'] ?? ''), hash('sha256', $nonce))) {
        throw new RuntimeException('maintenance_auth_stale');
    }

    $challenge['consumed_at'] = gmdate('c');
    file_put_contents(
        $path,
        json_encode($challenge, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n",
        LOCK_EX
    );
}

/**
 * Ensure execution orchestrator lock is held by this job (re-acquire if stale/missing).
 */
function orange_restore_prod_maint_ensure_execution_lock(string $workRoot, string $jobId): void
{
    $acq = orange_restore_exec_acquire_lock($workRoot, $jobId);
    if (!($acq['ok'] ?? false)) {
        throw new RuntimeException((string) ($acq['message'] ?? 'execution_lock_not_held'));
    }
}

/**
 * HTTP: request maintenance activation (metadata only).
 *
 * @param array<string, mixed> $admin
 * @return array<string, mixed>
 */
function orange_restore_prod_maint_request(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    array $admin
): array {
    $operator = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin')) ?: 'admin';
    $job = orange_restore_fw_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');

    if ($status === ORANGE_RESTORE_FW_STATUS_MAINTENANCE_ACTIVE) {
        throw new RuntimeException('duplicate_maintenance');
    }
    if ($status === ORANGE_RESTORE_FW_STATUS_MAINTENANCE_REQUESTED
        || $status === ORANGE_RESTORE_FW_STATUS_MAINTENANCE_VALIDATING) {
        $challenge = orange_restore_prod_maint_write_challenge($workRoot, $jobId, $admin);

        return [
            'job' => orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId)),
            'maintenance' => orange_restore_maint_fw_public(orange_restore_maint_fw_read($workRoot)),
            'challenge' => [
                'nonce' => $challenge['nonce'],
                'expires_at' => $challenge['expires_at'],
                'ttl_seconds' => $challenge['ttl_seconds'],
            ],
            'idempotent' => true,
            'execution_started' => false,
            'restore_started' => false,
            'message' => 'Maintenance already requested.',
            'warning' => 'Production restore has NOT started.',
        ];
    }

    if (!in_array($status, orange_restore_prod_maint_entry_statuses(), true)) {
        throw new RuntimeException('invalid_status');
    }

    orange_restore_prod_maint_ensure_execution_lock($workRoot, $jobId);
    $gates = orange_restore_prod_maint_validate($workRoot, $jobId, $backupRoot, true);
    if (!$gates['ok']) {
        throw new RuntimeException((string) $gates['code']);
    }

    $maintState = orange_restore_maint_fw_read($workRoot);
    if ((string) ($maintState['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_ACTIVE) {
        throw new RuntimeException('duplicate_maintenance');
    }

    orange_restore_maint_fw_request($workRoot, $operator, $jobId, 'production_maintenance_pending');
    $job = orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_MAINTENANCE_REQUESTED,
        ORANGE_RESTORE_FW_PHASE_MAINTENANCE_REQUESTED,
        10,
        'Maintenance requested — restore not started',
        'restore_maintenance_requested'
    );
    $job['execution_started'] = false;
    $job['restore_started'] = false;
    orange_restore_fw_write($workRoot, $job);

    $challenge = orange_restore_prod_maint_write_challenge($workRoot, $jobId, $admin);
    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_production_maintenance_requested',
        'result' => 'ok',
        'operator_username' => $operator,
        'execution_started' => false,
        'restore_started' => false,
    ]);

    return [
        'job' => orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId)),
        'maintenance' => orange_restore_maint_fw_public(orange_restore_maint_fw_read($workRoot)),
        'gates' => $gates['details'],
        'challenge' => [
            'nonce' => $challenge['nonce'],
            'expires_at' => $challenge['expires_at'],
            'ttl_seconds' => $challenge['ttl_seconds'],
        ],
        'idempotent' => false,
        'execution_started' => false,
        'restore_started' => false,
        'message' => 'Maintenance Ready — activation required. Restore has not started.',
        'warning' => 'Production restore has NOT started.',
    ];
}

/**
 * HTTP: activate maintenance framework only.
 *
 * @param array<string, mixed> $admin
 * @return array<string, mixed>
 */
function orange_restore_prod_maint_activate(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    array $admin,
    PDO $pdo,
    string $password,
    string $nonce
): array {
    $operator = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin')) ?: 'admin';
    $job = orange_restore_fw_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');

    if ($status === ORANGE_RESTORE_FW_STATUS_MAINTENANCE_ACTIVE) {
        throw new RuntimeException('duplicate_maintenance');
    }
    if ($status !== ORANGE_RESTORE_FW_STATUS_MAINTENANCE_REQUESTED
        && $status !== ORANGE_RESTORE_FW_STATUS_MAINTENANCE_VALIDATING) {
        throw new RuntimeException('invalid_status');
    }

    orange_restore_prod_maint_assert_fresh_auth($workRoot, $jobId, $admin, $pdo, $password, $nonce);

    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_MAINTENANCE_VALIDATING,
        ORANGE_RESTORE_FW_PHASE_MAINTENANCE_VALIDATING,
        40,
        'Validating maintenance activation gates',
        'restore_maintenance_validating'
    );
    orange_restore_maint_fw_mark_validating($workRoot, $operator, $jobId);

    orange_restore_prod_maint_ensure_execution_lock($workRoot, $jobId);
    $gates = orange_restore_prod_maint_validate($workRoot, $jobId, $backupRoot, true);
    if (!$gates['ok']) {
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_production_maintenance_validation_failed',
            'result' => 'fail',
            'code' => (string) $gates['code'],
            'operator_username' => $operator,
        ]);
        throw new RuntimeException((string) $gates['code']);
    }

    $maintenance = orange_restore_maint_fw_activate($workRoot, $operator, $jobId);
    $job = orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_MAINTENANCE_ACTIVE,
        ORANGE_RESTORE_FW_PHASE_MAINTENANCE_ACTIVE,
        100,
        'Maintenance Active — production restore has NOT started',
        'restore_maintenance_active'
    );
    $job['execution_started'] = false;
    $job['restore_started'] = false;
    $job['maintenance_active'] = true;
    orange_restore_fw_write($workRoot, $job);

    // Heartbeat immediately after activation.
    $maintenance = orange_restore_maint_fw_heartbeat($workRoot);

    $record = [
        'record_version' => ORANGE_RESTORE_PROD_MAINT_VERSION,
        'job_id' => $jobId,
        'activated_at' => gmdate('c'),
        'activated_by' => $operator,
        'gates' => $gates['details'],
        'execution_started' => false,
        'restore_started' => false,
        'cutover_started' => false,
        'rollback_started' => false,
        'worker_invoked' => false,
        'warning' => 'Production restore has NOT started.',
    ];
    file_put_contents(
        orange_restore_prod_maint_record_path($workRoot, $jobId),
        json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n",
        LOCK_EX
    );

    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_production_maintenance_active',
        'result' => 'ok',
        'operator_username' => $operator,
        'execution_started' => false,
        'restore_started' => false,
        'worker_invoked' => false,
    ]);

    return [
        'job' => orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId)),
        'maintenance' => $maintenance,
        'record' => $record,
        'gates' => $gates['details'],
        'execution_started' => false,
        'restore_started' => false,
        'production_cutover_allowed' => false,
        'message' => 'Maintenance Active.',
        'warning' => 'Production restore has NOT started.',
    ];
}

/**
 * Read-only maintenance state for a job / framework.
 *
 * @return array<string, mixed>
 */
function orange_restore_prod_maint_state(string $workRoot, string $jobId = ''): array
{
    $maintenance = orange_restore_maint_fw_public(orange_restore_maint_fw_read($workRoot));
    $job = null;
    $record = null;
    if ($jobId !== '') {
        try {
            $job = orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId));
            $path = orange_restore_prod_maint_record_path($workRoot, $jobId);
            if (is_file($path)) {
                $decoded = json_decode((string) file_get_contents($path), true);
                $record = is_array($decoded) ? $decoded : null;
            }
        } catch (Throwable) {
            $job = null;
        }
    }

    return [
        'maintenance' => $maintenance,
        'job' => $job,
        'record' => $record,
        'stale' => (bool) ($maintenance['stale'] ?? false),
        'auto_release_forbidden' => true,
        'execution_started' => false,
        'restore_started' => false,
        'warning' => 'Production restore has NOT started.',
        'read_only' => true,
    ];
}
