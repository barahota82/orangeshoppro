<?php

declare(strict_types=1);

/**
 * Phase 3B.3B1 — Final approval gate (metadata only).
 *
 * Stops at approved_waiting_execution. Never starts restore, maintenance, SQL, or CLI.
 */

require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_execution_orchestrator.php';
require_once __DIR__ . '/restore_dry_run.php';
require_once __DIR__ . '/restore_version_lock.php';
require_once __DIR__ . '/restore_execution_bridge.php';
require_once __DIR__ . '/restore_reauth.php';
require_once __DIR__ . '/../backup_admin.php';

const ORANGE_RESTORE_FINAL_APPROVAL_VERSION = '3B.3B1-final-approval';
const ORANGE_RESTORE_FINAL_APPROVAL_FILE = 'final_approval.json';
const ORANGE_RESTORE_APPROVAL_CHALLENGE_FILE = 'approval_challenge.json';
const ORANGE_RESTORE_APPROVAL_CHALLENGE_TTL_SECONDS = 300;
const ORANGE_RESTORE_APPROVAL_TRANSITION_LOCK = '.approval_transition.lock';

function orange_restore_final_approval_phrase(string $packageId, string $jobId): string
{
    return 'RESTORE ' . $packageId . ' JOB ' . $jobId;
}

function orange_restore_final_approval_challenge_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_APPROVAL_CHALLENGE_FILE;
}

function orange_restore_final_approval_record_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_FINAL_APPROVAL_FILE;
}

function orange_restore_final_approval_session_id(): string
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sid = session_id();
        if (is_string($sid) && $sid !== '') {
            return hash('sha256', $sid);
        }
    }
    // CLI / headers-already-sent: stable process-bound binder (not a security weakening of password re-auth).
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return hash('sha256', 'cli-restore-approval-session|' . (string) getmypid());
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $sid = session_id();
    if (!is_string($sid) || $sid === '') {
        return hash('sha256', 'no_session');
    }

    return hash('sha256', $sid);
}

/**
 * @return array{ok:bool,handle:?resource,message:string}
 */
function orange_restore_final_approval_acquire_transition_lock(string $workRoot, string $jobId): array
{
    $path = orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_APPROVAL_TRANSITION_LOCK;
    $handle = @fopen($path, 'c+b');
    if ($handle === false) {
        return ['ok' => false, 'handle' => null, 'message' => 'Cannot open approval transition lock.'];
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);

        return ['ok' => false, 'handle' => null, 'message' => 'Cannot acquire approval transition lock.'];
    }

    return ['ok' => true, 'handle' => $handle, 'message' => 'ok'];
}

/**
 * Plan fingerprint for challenge binding (hashes of plan + package + dry-run).
 */
function orange_restore_final_approval_plan_fingerprint(string $workRoot, string $jobId): string
{
    $planPath = orange_restore_exec_plan_path($workRoot, $jobId);
    $dryPath = orange_restore_dry_run_report_path($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $material = implode('|', [
        orange_restore_exec_file_sha256($planPath),
        (string) ($job['package_fingerprint'] ?? ''),
        (string) ($job['dry_run_fingerprint'] ?? ''),
        orange_restore_exec_file_sha256($dryPath),
    ]);

    return hash('sha256', $material);
}

/**
 * @return array{ok:bool,code:string,message:string,version_lock?:array<string,mixed>}
 */
function orange_restore_final_approval_precheck(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    array $admin,
    PDO $pdo,
    bool $forCountryBlock = true
): array {
    $job = orange_restore_fw_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');
    if ($status === ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION) {
        return ['ok' => false, 'code' => 'already_approved', 'message' => 'Job already approved.'];
    }
    if ($status !== ORANGE_RESTORE_FW_STATUS_AWAITING_FINAL_APPROVAL) {
        return ['ok' => false, 'code' => 'invalid_status', 'message' => 'Job is not awaiting_final_approval.'];
    }
    if (!empty($job['execution_started'])) {
        return ['ok' => false, 'code' => 'execution_already_started', 'message' => 'execution_started must be false.'];
    }
    if (empty($job['requires_final_approval'])) {
        return ['ok' => false, 'code' => 'final_approval_not_required', 'message' => 'requires_final_approval is false.'];
    }
    if (!is_file(orange_restore_exec_plan_path($workRoot, $jobId))) {
        return ['ok' => false, 'code' => 'execution_plan_missing', 'message' => 'execution_plan.json missing.'];
    }

    // Final approval is a pre-execution metadata transition. It must not require a live
    // non-stale execution orchestrator lock left by Step-4 prepare across HTTP requests
    // (Windows PID-stale after the prepare request ends). Serialization uses
    // .approval_transition.lock; worker/execution locks remain for later stages.
    // Active-worker safety is enforced by status === awaiting_final_approval plus
    // execution_started === false (workers are not schedulable in this status).

    $packageType = (string) ($job['package_type'] ?? '');
    $packageId = (string) ($job['package_id'] ?? '');
    $countryCode = (string) ($job['country_code'] ?? '');
    if (!function_exists('orange_restore_admin_assert_package_type_permission')) {
        require_once __DIR__ . '/../restore_admin.php';
    }
    orange_restore_admin_assert_package_type_permission($admin, $pdo, $packageType);

    if ($forCountryBlock && $packageType === 'country_recovery') {
        return [
            'ok' => false,
            'code' => 'country_production_restore_not_enabled',
            'message' => 'country_production_restore_not_enabled',
        ];
    }

    $plan = orange_restore_exec_read_plan($workRoot, $jobId);
    if (!empty($plan['execution_started'])) {
        return ['ok' => false, 'code' => 'execution_already_started', 'message' => 'Plan execution_started must be false.'];
    }
    if (empty($plan['requires_final_approval'])) {
        return ['ok' => false, 'code' => 'final_approval_not_required', 'message' => 'Plan requires_final_approval is false.'];
    }

    $dry = orange_restore_dry_run_read_report($workRoot, $jobId);
    if ($dry === null) {
        return ['ok' => false, 'code' => 'dry_run_report_missing', 'message' => 'Dry run report missing.'];
    }
    if (!empty($dry['execution_performed'])) {
        return ['ok' => false, 'code' => 'execution_already_performed', 'message' => 'execution_performed is true.'];
    }

    $fp = orange_restore_exec_build_package_fingerprint(
        $backupRoot,
        $packageType,
        $packageId,
        $countryCode !== '' ? $countryCode : null
    );
    $storedFp = (string) ($job['package_fingerprint'] ?? '');
    $dryFpStored = (string) ($job['dry_run_fingerprint'] ?? '');
    $dryFpLive = orange_restore_exec_file_sha256(orange_restore_dry_run_report_path($workRoot, $jobId));
    if ($storedFp === '' || !hash_equals($storedFp, (string) $fp['fingerprint'])) {
        return ['ok' => false, 'code' => 'package_changed_after_dry_run', 'message' => 'package_changed_after_dry_run'];
    }
    if ($dryFpStored === '' || !hash_equals($dryFpStored, $dryFpLive)) {
        return ['ok' => false, 'code' => 'package_changed_after_dry_run', 'message' => 'dry_run fingerprint mismatch'];
    }

    $eligibilitySummary = $packageType === 'full_disaster'
        ? orange_backup_admin_summarize_full_package(
            orange_backup_admin_resolve_full_package_path($backupRoot, $packageId),
            $packageId
        )
        : orange_backup_admin_summarize_country_package(
            orange_backup_admin_resolve_country_package_path($backupRoot, $countryCode, $packageId),
            $packageId,
            $countryCode
        );
    $eligibility = orange_restore_admin_package_eligibility($eligibilitySummary, $packageType);
    if (($eligibility['eligibility_status'] ?? '') !== 'eligible') {
        return ['ok' => false, 'code' => 'package_not_eligible', 'message' => 'Package not eligible.'];
    }

    $versionLock = orange_restore_version_lock_evaluate($workRoot, $jobId, $backupRoot);
    if (!$versionLock['ok']) {
        return [
            'ok' => false,
            'code' => (string) (($versionLock['reasons'][0] ?? 'version_incompatible')),
            'message' => 'Version lock failed: ' . implode(',', $versionLock['reasons']),
            'version_lock' => $versionLock,
        ];
    }

    return ['ok' => true, 'code' => 'ok', 'message' => 'ok', 'version_lock' => $versionLock];
}

/**
 * @return array<string, mixed>
 */
function orange_restore_final_approval_create_challenge(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    array $admin,
    PDO $pdo
): array {
    $pre = orange_restore_final_approval_precheck($workRoot, $jobId, $backupRoot, $admin, $pdo, true);
    if (!$pre['ok']) {
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_final_approval_rejected',
            'result' => 'fail',
            'code' => $pre['code'],
            'operator_username' => (string) ($admin['username'] ?? ''),
        ]);
        throw new RuntimeException((string) $pre['code']);
    }

    $job = orange_restore_fw_read($workRoot, $jobId);
    $packageId = (string) ($job['package_id'] ?? '');
    $operatorId = (int) ($admin['id'] ?? 0);
    $operator = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin'));
    $phrase = orange_restore_final_approval_phrase($packageId, $jobId);
    $nonce = bin2hex(random_bytes(32));
    $now = time();
    $createdAt = gmdate('c', $now);
    $expiresAt = gmdate('c', $now + ORANGE_RESTORE_APPROVAL_CHALLENGE_TTL_SECONDS);
    $planFp = orange_restore_final_approval_plan_fingerprint($workRoot, $jobId);
    $sessionHash = orange_restore_final_approval_session_id();

    $challenge = [
        'challenge_version' => ORANGE_RESTORE_FINAL_APPROVAL_VERSION,
        'job_id' => $jobId,
        'package_id' => $packageId,
        'package_type' => (string) ($job['package_type'] ?? ''),
        'country_code' => $job['country_code'] ?? null,
        'operator_admin_id' => $operatorId,
        'operator_username' => $operator,
        'session_id_hash' => $sessionHash,
        'nonce_hash' => hash('sha256', $nonce),
        'confirmation_phrase_hash' => hash('sha256', $phrase),
        'plan_fingerprint' => $planFp,
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'dry_run_fingerprint' => (string) ($job['dry_run_fingerprint'] ?? ''),
        'created_at' => $createdAt,
        'expires_at' => $expiresAt,
        'consumed_at' => '',
        'version_lock' => $pre['version_lock'] ?? [],
    ];

    $path = orange_restore_final_approval_challenge_path($workRoot, $jobId);
    $json = json_encode($challenge, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write approval challenge.');
    }

    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_approval_challenge_created',
        'result' => 'ok',
        'operator_username' => $operator,
        'expires_at' => $expiresAt,
    ]);

    return [
        'nonce' => $nonce,
        'expires_at' => $expiresAt,
        'required_confirmation_phrase' => $phrase,
        'job_id' => $jobId,
        'package_id' => $packageId,
        'package_type' => (string) ($job['package_type'] ?? ''),
        'country_code' => $job['country_code'] ?? null,
        'version_lock' => [
            'ok' => true,
            'overall' => (string) (($pre['version_lock']['overall'] ?? 'compatible')),
            'reasons' => [],
        ],
        'warning' => 'تم إنشاء تحدي الموافقة فقط. لم يبدأ الاسترداد ولم يتم تفعيل وضع الصيانة.',
        'two_person_approval' => [
            'implemented' => false,
            'recommended' => true,
            'required_before_production_execution' => true,
            'deferred' => true,
            'note' => 'Single-person approval is not equivalent to dual control.',
        ],
    ];
}

/**
 * @return array{job:array<string,mixed>,approval:array<string,mixed>}
 */
function orange_restore_final_approval_grant(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    array $admin,
    PDO $pdo,
    string $packageIdInput,
    string $confirmationPhrase,
    string $nonce,
    string $password
): array {
    $operator = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin'));
    $operatorId = (int) ($admin['id'] ?? 0);

    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_final_approval_attempted',
        'result' => 'ok',
        'operator_username' => $operator,
    ]);

    if (trim($password) === '') {
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_final_approval_rejected',
            'result' => 'fail',
            'code' => 'recent_authentication_not_available',
            'operator_username' => $operator,
        ]);
        throw new RuntimeException('recent_authentication_not_available');
    }
    if (!orange_restore_verify_operator_password($pdo, $operatorId, $password)) {
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_final_approval_rejected',
            'result' => 'fail',
            'code' => 'recent_authentication_failed',
            'operator_username' => $operator,
        ]);
        throw new RuntimeException('recent_authentication_failed');
    }

    $lock = orange_restore_final_approval_acquire_transition_lock($workRoot, $jobId);
    if (!$lock['ok']) {
        throw new RuntimeException((string) $lock['message']);
    }

    try {
        $pre = orange_restore_final_approval_precheck($workRoot, $jobId, $backupRoot, $admin, $pdo, true);
        if (!$pre['ok']) {
            orange_restore_fw_audit_append($workRoot, $jobId, [
                'event' => 'restore_final_approval_rejected',
                'result' => 'fail',
                'code' => $pre['code'],
                'operator_username' => $operator,
            ]);
            throw new RuntimeException((string) $pre['code']);
        }

        $job = orange_restore_fw_read($workRoot, $jobId);
        $packageId = (string) ($job['package_id'] ?? '');
        if (!hash_equals($packageId, trim($packageIdInput))) {
            throw new RuntimeException('package_id_mismatch');
        }

        $challengePath = orange_restore_final_approval_challenge_path($workRoot, $jobId);
        if (!is_file($challengePath)) {
            throw new RuntimeException('approval_nonce_invalid');
        }
        $challenge = json_decode((string) file_get_contents($challengePath), true);
        if (!is_array($challenge)) {
            throw new RuntimeException('approval_nonce_invalid');
        }

        if ((string) ($challenge['consumed_at'] ?? '') !== '') {
            throw new RuntimeException('approval_nonce_used');
        }
        $expires = strtotime((string) ($challenge['expires_at'] ?? ''));
        if ($expires === false || time() > $expires) {
            orange_restore_fw_audit_append($workRoot, $jobId, [
                'event' => 'restore_approval_challenge_expired',
                'result' => 'fail',
                'operator_username' => $operator,
            ]);
            throw new RuntimeException('approval_nonce_expired');
        }
        if ((int) ($challenge['operator_admin_id'] ?? 0) !== $operatorId) {
            throw new RuntimeException('approval_nonce_wrong_operator');
        }
        $sessionHash = orange_restore_final_approval_session_id();
        if (!hash_equals((string) ($challenge['session_id_hash'] ?? ''), $sessionHash)) {
            throw new RuntimeException('approval_nonce_wrong_session');
        }
        if (!hash_equals((string) ($challenge['nonce_hash'] ?? ''), hash('sha256', $nonce))) {
            throw new RuntimeException('approval_nonce_invalid');
        }

        $expectedPhrase = orange_restore_final_approval_phrase($packageId, $jobId);
        $typed = trim($confirmationPhrase);
        if (!hash_equals($expectedPhrase, $typed)) {
            throw new RuntimeException('confirmation_phrase_mismatch');
        }
        if (!hash_equals((string) ($challenge['confirmation_phrase_hash'] ?? ''), hash('sha256', $expectedPhrase))) {
            throw new RuntimeException('confirmation_phrase_mismatch');
        }

        $livePlanFp = orange_restore_final_approval_plan_fingerprint($workRoot, $jobId);
        if (!hash_equals((string) ($challenge['plan_fingerprint'] ?? ''), $livePlanFp)) {
            throw new RuntimeException('plan_changed_after_challenge');
        }
        if (!hash_equals((string) ($challenge['package_fingerprint'] ?? ''), (string) ($job['package_fingerprint'] ?? ''))) {
            throw new RuntimeException('package_changed_after_dry_run');
        }

        // Atomically consume nonce (clear plaintext-capable fields; keep hashes).
        $challenge['consumed_at'] = gmdate('c');
        $challenge['nonce_hash'] = (string) ($challenge['nonce_hash'] ?? '');
        file_put_contents(
            $challengePath,
            json_encode($challenge, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n",
            LOCK_EX
        );
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_approval_nonce_consumed',
            'result' => 'ok',
            'operator_username' => $operator,
        ]);

        $approval = [
            'approval_version' => ORANGE_RESTORE_FINAL_APPROVAL_VERSION,
            'job_id' => $jobId,
            'package_id' => $packageId,
            'package_type' => (string) ($job['package_type'] ?? ''),
            'country_code' => $job['country_code'] ?? null,
            'approved_by' => $operator,
            'approved_by_admin_id' => $operatorId,
            'approved_at' => gmdate('c'),
            'challenge_created_at' => (string) ($challenge['created_at'] ?? ''),
            'challenge_expires_at' => (string) ($challenge['expires_at'] ?? ''),
            'nonce_id_hash' => (string) ($challenge['nonce_hash'] ?? ''),
            'confirmation_phrase_hash' => (string) ($challenge['confirmation_phrase_hash'] ?? ''),
            'plan_fingerprint' => $livePlanFp,
            'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
            'dry_run_fingerprint' => (string) ($job['dry_run_fingerprint'] ?? ''),
            'recent_auth_verified' => true,
            'recent_auth_method' => 'password_reverify',
            'approval_consumed' => true,
            'execution_started' => false,
            'maintenance_enabled' => false,
            'cli_invoked' => false,
            'version_lock' => $pre['version_lock'] ?? [],
            'two_person_approval' => [
                'implemented' => false,
                'recommended' => true,
                'required_before_production_execution' => true,
                'deferred' => true,
            ],
            'warning' => 'تم اعتماد الخطة، لكن لم يبدأ الاسترداد ولم يتم تفعيل وضع الصيانة.',
        ];

        $approvalPath = orange_restore_final_approval_record_path($workRoot, $jobId);
        file_put_contents(
            $approvalPath,
            json_encode($approval, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n",
            LOCK_EX
        );

        $job = orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
            ORANGE_RESTORE_FW_PHASE_APPROVED_WAITING_EXECUTION,
            100,
            'Approved — waiting execution (not started)',
            'restore_final_approval_granted'
        );
        $job['final_approval_file'] = ORANGE_RESTORE_FINAL_APPROVAL_FILE;
        $job['execution_started'] = false;
        $job['requires_final_approval'] = true;
        $job['approved_at'] = (string) $approval['approved_at'];
        $job['approved_by'] = $operator;
        orange_restore_fw_write($workRoot, $job);

        // Explicit non-actions for forensic clarity.
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_final_approval_granted',
            'result' => 'ok',
            'operator_username' => $operator,
            'execution_started' => false,
            'maintenance_enabled' => false,
            'cli_invoked' => false,
        ]);

        // 3B.3B2 — metadata-only CLI execution contract (never invokes restore).
        $contract = orange_restore_prepare_execution_contract($workRoot, $jobId, $backupRoot);

        return [
            'job' => orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId)),
            'approval' => orange_restore_final_approval_public($approval),
            'execution_contract' => orange_restore_bridge_public_contract($contract),
        ];
    } catch (Throwable $e) {
        $code = trim($e->getMessage());
        if (!in_array($code, [
            'recent_authentication_not_available',
            'recent_authentication_failed',
            'approval_nonce_used',
            'approval_nonce_expired',
            'approval_nonce_invalid',
            'approval_nonce_wrong_operator',
            'approval_nonce_wrong_session',
            'confirmation_phrase_mismatch',
            'plan_changed_after_challenge',
            'package_changed_after_dry_run',
            'package_id_mismatch',
            'country_production_restore_not_enabled',
            'already_approved',
            'invalid_status',
        ], true)) {
            orange_restore_fw_audit_append($workRoot, $jobId, [
                'event' => 'restore_final_approval_rejected',
                'result' => 'fail',
                'code' => $code !== '' ? $code : 'approval_rejected',
                'operator_username' => $operator,
            ]);
        } elseif (!in_array($code, ['recent_authentication_not_available', 'recent_authentication_failed'], true)) {
            orange_restore_fw_audit_append($workRoot, $jobId, [
                'event' => 'restore_final_approval_rejected',
                'result' => 'fail',
                'code' => $code,
                'operator_username' => $operator,
            ]);
        }
        throw $e;
    } finally {
        if (is_resource($lock['handle'])) {
            flock($lock['handle'], LOCK_UN);
            fclose($lock['handle']);
        }
    }
}

/**
 * @param array<string, mixed> $approval
 * @return array<string, mixed>
 */
function orange_restore_final_approval_public(array $approval): array
{
    unset($approval['absolute_paths'], $approval['password'], $approval['nonce'], $approval['secrets']);

    return [
        'approval_version' => (string) ($approval['approval_version'] ?? ''),
        'job_id' => (string) ($approval['job_id'] ?? ''),
        'package_id' => (string) ($approval['package_id'] ?? ''),
        'package_type' => (string) ($approval['package_type'] ?? ''),
        'country_code' => $approval['country_code'] ?? null,
        'approved_by' => (string) ($approval['approved_by'] ?? ''),
        'approved_at' => (string) ($approval['approved_at'] ?? ''),
        'challenge_created_at' => (string) ($approval['challenge_created_at'] ?? ''),
        'challenge_expires_at' => (string) ($approval['challenge_expires_at'] ?? ''),
        'nonce_id_hash' => (string) ($approval['nonce_id_hash'] ?? ''),
        'confirmation_phrase_hash' => (string) ($approval['confirmation_phrase_hash'] ?? ''),
        'plan_fingerprint' => (string) ($approval['plan_fingerprint'] ?? ''),
        'package_fingerprint' => (string) ($approval['package_fingerprint'] ?? ''),
        'dry_run_fingerprint' => (string) ($approval['dry_run_fingerprint'] ?? ''),
        'recent_auth_verified' => (bool) ($approval['recent_auth_verified'] ?? false),
        'recent_auth_method' => (string) ($approval['recent_auth_method'] ?? ''),
        'approval_consumed' => (bool) ($approval['approval_consumed'] ?? true),
        'execution_started' => false,
        'maintenance_enabled' => false,
        'cli_invoked' => false,
        'version_lock' => [
            'ok' => (bool) (($approval['version_lock']['ok'] ?? false)),
            'overall' => (string) (($approval['version_lock']['overall'] ?? '')),
            'reasons' => is_array($approval['version_lock']['reasons'] ?? null)
                ? array_values($approval['version_lock']['reasons'])
                : [],
        ],
        'two_person_approval' => is_array($approval['two_person_approval'] ?? null)
            ? $approval['two_person_approval']
            : ['implemented' => false, 'deferred' => true, 'recommended' => true],
        'warning' => (string) ($approval['warning'] ?? 'تم اعتماد الخطة، لكن لم يبدأ الاسترداد ولم يتم تفعيل وضع الصيانة.'),
    ];
}
