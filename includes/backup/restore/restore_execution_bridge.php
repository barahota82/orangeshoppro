<?php

declare(strict_types=1);

/**
 * Phase 3B.3B2 — Restore Bridge Layer (execution contract only).
 *
 * Converts an approved Admin Framework job into a versioned CLI execution request
 * contract. Never invokes CLI, imports SQL, creates shadow DB, restores files,
 * enables maintenance, or performs rollback.
 *
 * Public surface (only):
 *   - orange_restore_prepare_execution_contract()
 *   - orange_restore_validate_execution_contract()
 *   - orange_restore_load_execution_contract()
 *
 * @see docs/backup/RESTORE_PHASE2_CLI_ENTRYPOINTS.md
 */

require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_execution_orchestrator.php';
require_once __DIR__ . '/restore_dry_run.php';
require_once __DIR__ . '/restore_final_approval.php';
require_once __DIR__ . '/restore_version_lock.php';
require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../recovery_validation.php';

const ORANGE_RESTORE_EXEC_CONTRACT_VERSION = '3B.3B2-v1';
const ORANGE_RESTORE_EXEC_CONTRACT_FILE = 'restore_execution_contract.json';
const ORANGE_RESTORE_EXEC_CONTRACT_BACKEND = 'php_pdo';

/**
 * @return list<string>
 */
function orange_restore_bridge_phase2_cli_entrypoints(): array
{
    return [
        'scripts/backup/restore_run_full.php',
        'scripts/backup/restore_resume_full.php',
        'scripts/backup/restore_status_full.php',
        'scripts/backup/restore_full_to_staging.php',
        'scripts/backup/restore_country_to_staging.php',
        'scripts/backup/restore_approve_merge.php',
        'scripts/backup/restore_full_database_cutover.php',
        'scripts/backup/restore_full_uploads_cutover.php',
        'scripts/backup/restore_full_post_validate.php',
        'scripts/backup/restore_full_post_validate_finalize.php',
        'scripts/backup/restore_full_rollback.php',
        'scripts/backup/restore_job_status.php',
    ];
}

function orange_restore_exec_contract_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_EXEC_CONTRACT_FILE;
}

/**
 * Stable hash of final approval binding fields (metadata only).
 *
 * @param array<string, mixed> $approval
 */
function orange_restore_bridge_approval_hash(array $approval): string
{
    $material = implode('|', [
        (string) ($approval['approval_version'] ?? ''),
        (string) ($approval['job_id'] ?? ''),
        (string) ($approval['package_id'] ?? ''),
        (string) ($approval['package_type'] ?? ''),
        (string) ($approval['approved_at'] ?? ''),
        (string) ($approval['approved_by_admin_id'] ?? ''),
        (string) ($approval['plan_fingerprint'] ?? ''),
        (string) ($approval['package_fingerprint'] ?? ''),
        (string) ($approval['dry_run_fingerprint'] ?? ''),
        (string) ($approval['confirmation_phrase_hash'] ?? ''),
        (string) ($approval['nonce_id_hash'] ?? ''),
        (string) ($approval['execution_started'] ?? '0'),
    ]);

    return hash('sha256', $material);
}

/**
 * @param array<string, mixed> $job
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $approval
 * @param array<string, mixed> $fingerprint
 * @return array<string, mixed>
 */
function orange_restore_bridge_build_cli_request(
    array $job,
    array $plan,
    array $approval,
    array $fingerprint
): array {
    $packageType = (string) ($job['package_type'] ?? '');
    $packageId = (string) ($job['package_id'] ?? '');
    $isFull = $packageType === 'full_disaster';

    return [
        'invoked' => false,
        'execution_allowed' => false,
        'execution_started' => false,
        'primary_cli' => $isFull
            ? 'scripts/backup/restore_run_full.php'
            : 'scripts/backup/restore_country_to_staging.php',
        'staging_cli' => $isFull
            ? 'scripts/backup/restore_full_to_staging.php'
            : 'scripts/backup/restore_country_to_staging.php',
        'orchestration_module' => $isFull
            ? 'includes/backup/restore/restore_e2e_orchestrator.php'
            : 'includes/backup/restore/restore_country_staging.php',
        'orchestration_entry_function' => $isFull
            ? 'orange_restore_e2e_start_full'
            : 'orange_restore_country_staging_run',
        'phase2_cli_entrypoints' => orange_restore_bridge_phase2_cli_entrypoints(),
        'argv_template' => $isFull
            ? [
                'php',
                'scripts/backup/restore_run_full.php',
                '--package=<package_path>',
                '--admin-id=<admin_id>',
                '--password=<redacted>',
                '--confirm=RESTORE',
            ]
            : [
                'php',
                'scripts/backup/restore_country_to_staging.php',
                '--package=<package_path>',
            ],
        'package_id' => $packageId,
        'framework_job_id' => (string) ($job['job_id'] ?? ''),
        'plan_version' => (string) ($plan['plan_version'] ?? ''),
        'approval_bound' => (string) ($approval['approved_at'] ?? '') !== '',
        'export_backend' => (string) ($fingerprint['export_backend'] ?? ''),
        'schema_revision' => (int) ($fingerprint['schema_revision'] ?? 0),
        'note' => 'Metadata only. Bridge must not invoke CLI in 3B.3B2.',
    ];
}

/**
 * @return array<string, mixed>
 */
function orange_restore_bridge_execution_profile(string $packageType): array
{
    if ($packageType === 'full_disaster') {
        return [
            'profile_id' => 'phase2_full_staging_then_cutover',
            'restore_mode' => 'full_disaster',
            'production_enabled' => false,
            'stages_planned' => [
                'fresh_backup_gate',
                'full_staging',
                'merge_approval',
                'database_cutover',
                'uploads_cutover',
                'post_validation',
            ],
        ];
    }

    return [
        'profile_id' => 'phase2_country_staging_only',
        'restore_mode' => 'country_recovery',
        'production_enabled' => false,
        'stages_planned' => [
            'country_staging',
        ],
        'blocked_reason' => 'country_production_restore_not_enabled',
    ];
}

/**
 * Prepare (or refresh) restore_execution_contract.json for an approved framework job.
 * Never starts restore or invokes CLI.
 *
 * @return array<string, mixed>
 */
function orange_restore_prepare_execution_contract(
    string $workRoot,
    string $jobId,
    string $backupRoot
): array {
    if ($backupRoot === '') {
        throw new RuntimeException('Backup root required.');
    }

    $job = orange_restore_fw_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');
    if ($status !== ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION) {
        throw new RuntimeException('invalid_status');
    }
    if (!empty($job['execution_started'])) {
        throw new RuntimeException('execution_started_forbidden');
    }

    $packageType = (string) ($job['package_type'] ?? '');
    $packageId = (string) ($job['package_id'] ?? '');
    $countryCode = isset($job['country_code']) && (string) $job['country_code'] !== ''
        ? (string) $job['country_code']
        : null;

    if ($packageType === 'country_recovery') {
        throw new RuntimeException('country_production_restore_not_enabled');
    }
    if ($packageType !== 'full_disaster') {
        throw new RuntimeException('package_type_mismatch');
    }

    $approvalPath = orange_restore_final_approval_record_path($workRoot, $jobId);
    if (!is_file($approvalPath)) {
        throw new RuntimeException('final_approval_missing');
    }
    $approvalRaw = file_get_contents($approvalPath);
    if ($approvalRaw === false) {
        throw new RuntimeException('final_approval_missing');
    }
    $approval = json_decode($approvalRaw, true);
    if (!is_array($approval)) {
        throw new RuntimeException('final_approval_invalid');
    }
    if (!empty($approval['execution_started']) || !empty($approval['cli_invoked'])) {
        throw new RuntimeException('execution_started_forbidden');
    }

    $plan = orange_restore_exec_read_plan($workRoot, $jobId);
    $planPath = orange_restore_exec_plan_path($workRoot, $jobId);
    $executionPlanHash = orange_restore_exec_file_sha256($planPath);
    if ($executionPlanHash === '') {
        throw new RuntimeException('execution_plan_missing');
    }

    $livePlanFp = orange_restore_final_approval_plan_fingerprint($workRoot, $jobId);
    if (!hash_equals((string) ($approval['plan_fingerprint'] ?? ''), $livePlanFp)) {
        throw new RuntimeException('plan_changed');
    }

    $fingerprint = orange_restore_exec_build_package_fingerprint(
        $backupRoot,
        $packageType,
        $packageId,
        $countryCode
    );
    $packageFingerprint = (string) ($fingerprint['fingerprint'] ?? '');
    $storedPackageFp = (string) ($job['package_fingerprint'] ?? '');
    $approvalPackageFp = (string) ($approval['package_fingerprint'] ?? '');
    if ($packageFingerprint === '' || $storedPackageFp === ''
        || !hash_equals($storedPackageFp, $packageFingerprint)
        || !hash_equals($approvalPackageFp, $packageFingerprint)) {
        throw new RuntimeException('package_changed');
    }

    $dryPath = orange_restore_dry_run_report_path($workRoot, $jobId);
    $dryRunFingerprint = orange_restore_exec_file_sha256($dryPath);
    $storedDry = (string) ($job['dry_run_fingerprint'] ?? '');
    $approvalDry = (string) ($approval['dry_run_fingerprint'] ?? '');
    if ($dryRunFingerprint === '' || $storedDry === ''
        || !hash_equals($storedDry, $dryRunFingerprint)
        || !hash_equals($approvalDry, $dryRunFingerprint)) {
        throw new RuntimeException('package_changed');
    }

    $schemaRevision = (int) ($fingerprint['schema_revision'] ?? 0);
    if ($schemaRevision !== ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION) {
        throw new RuntimeException('schema_mismatch');
    }

    $backend = strtolower(trim((string) ($fingerprint['export_backend'] ?? '')));
    if ($backend === '') {
        $backend = ORANGE_RESTORE_EXEC_CONTRACT_BACKEND;
    }
    if ($backend !== ORANGE_RESTORE_EXEC_CONTRACT_BACKEND) {
        throw new RuntimeException('backend_mismatch');
    }

    $versionLock = orange_restore_version_lock_evaluate($workRoot, $jobId, $backupRoot);
    if (!($versionLock['ok'] ?? false)) {
        throw new RuntimeException('version_mismatch');
    }

    $approvalHash = orange_restore_bridge_approval_hash($approval);
    $profile = orange_restore_bridge_execution_profile($packageType);
    $cliRequest = orange_restore_bridge_build_cli_request($job, $plan, $approval, $fingerprint);

    $contract = [
        'contract_version' => ORANGE_RESTORE_EXEC_CONTRACT_VERSION,
        'framework_job_id' => $jobId,
        'package_id' => $packageId,
        'package_type' => $packageType,
        'country_code' => $countryCode,
        'execution_plan_hash' => $executionPlanHash,
        'package_fingerprint' => $packageFingerprint,
        'dry_run_fingerprint' => $dryRunFingerprint,
        'approval_hash' => $approvalHash,
        'restore_mode' => (string) ($profile['restore_mode'] ?? $packageType),
        'backend' => ORANGE_RESTORE_EXEC_CONTRACT_BACKEND,
        'schema_revision' => ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION,
        'execution_profile' => $profile,
        'created_at' => gmdate('c'),
        'execution_started' => false,
        'cli_invoked' => false,
        'cli_request' => $cliRequest,
        'version_lock' => [
            'ok' => true,
            'overall' => (string) ($versionLock['overall'] ?? 'pass'),
            'reasons' => [],
        ],
        'bridge_version' => '3B.3B2-bridge',
        'warning' => 'Execution contract prepared. No CLI invoke, restore, or maintenance enablement performed.',
    ];

    $path = orange_restore_exec_contract_path($workRoot, $jobId);
    $json = json_encode($contract, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write execution contract.');
    }

    $job = orange_restore_fw_read($workRoot, $jobId);
    $job['execution_contract_file'] = ORANGE_RESTORE_EXEC_CONTRACT_FILE;
    $job['execution_started'] = false;
    orange_restore_fw_write($workRoot, $job);

    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_execution_contract_prepared',
        'result' => 'ok',
        'contract_version' => ORANGE_RESTORE_EXEC_CONTRACT_VERSION,
        'execution_started' => false,
        'cli_invoked' => false,
    ]);

    return $contract;
}

/**
 * Load restore_execution_contract.json (read-only).
 *
 * @return array<string, mixed>
 */
function orange_restore_load_execution_contract(string $workRoot, string $jobId): array
{
    $path = orange_restore_exec_contract_path($workRoot, $jobId);
    if (!is_file($path)) {
        throw new RuntimeException('contract_missing');
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('contract_missing');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('contract_invalid');
    }

    // Hard safety: never advertise execution as started from this layer.
    $decoded['execution_started'] = false;
    if (isset($decoded['cli_request']) && is_array($decoded['cli_request'])) {
        $decoded['cli_request']['invoked'] = false;
        $decoded['cli_request']['execution_started'] = false;
        $decoded['cli_request']['execution_allowed'] = false;
    }
    $decoded['cli_invoked'] = false;

    return $decoded;
}

/**
 * Validate a contract against live plan / package / approval / version gates.
 *
 * @param array<string, mixed>|null $contract
 * @return array{ok:bool,code:string,reasons:list<string>,contract:array<string,mixed>}
 */
function orange_restore_validate_execution_contract(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    ?array $contract = null
): array {
    $reasons = [];
    try {
        $contract = $contract ?? orange_restore_load_execution_contract($workRoot, $jobId);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'code' => trim($e->getMessage()) !== '' ? trim($e->getMessage()) : 'contract_missing',
            'reasons' => ['contract_missing'],
            'contract' => [],
        ];
    }

    if ((string) ($contract['contract_version'] ?? '') !== ORANGE_RESTORE_EXEC_CONTRACT_VERSION) {
        $reasons[] = 'version_mismatch';
    }
    if (!empty($contract['execution_started'])) {
        $reasons[] = 'execution_started_forbidden';
    }

    $job = orange_restore_fw_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');
    $contractValidStatuses = [
        ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_RUNNING,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED,
        ORANGE_RESTORE_FW_STATUS_SHADOW_NOT_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_RUNNING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_FAILED,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_PENDING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_RUNNING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_WARNING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_FAILED,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_READY,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_MANUAL_REVIEW,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_BLOCKED,
        ORANGE_RESTORE_FW_STATUS_MAINTENANCE_REQUESTED,
        ORANGE_RESTORE_FW_STATUS_MAINTENANCE_VALIDATING,
        ORANGE_RESTORE_FW_STATUS_MAINTENANCE_ACTIVE,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_PENDING,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_RUNNING,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_FAILED,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_PENDING,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_RUNNING,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_FAILED,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_PENDING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_DATABASE_RUNNING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_DATABASE_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_FILES_RUNNING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_FILES_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_READY,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_FAILED,
        ORANGE_RESTORE_FW_STATUS_RESTORE_FINALIZING,
        ORANGE_RESTORE_FW_STATUS_RESTORE_COMPLETED,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_FINALIZING,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_COMPLETED,
    ];
    if (!in_array($status, $contractValidStatuses, true)) {
        $reasons[] = 'invalid_status';
    }
    if ((string) ($contract['framework_job_id'] ?? '') !== $jobId) {
        $reasons[] = 'version_mismatch';
    }

    $packageType = (string) ($job['package_type'] ?? '');
    $packageId = (string) ($job['package_id'] ?? '');
    $countryCode = isset($job['country_code']) && (string) $job['country_code'] !== ''
        ? (string) $job['country_code']
        : null;

    if ((string) ($contract['package_id'] ?? '') !== $packageId
        || (string) ($contract['package_type'] ?? '') !== $packageType) {
        $reasons[] = 'package_changed';
    }

    try {
        $planPath = orange_restore_exec_plan_path($workRoot, $jobId);
        $livePlanHash = orange_restore_exec_file_sha256($planPath);
        if ($livePlanHash === '' || !hash_equals((string) ($contract['execution_plan_hash'] ?? ''), $livePlanHash)) {
            $reasons[] = 'plan_changed';
        }
    } catch (Throwable) {
        $reasons[] = 'plan_changed';
    }

    try {
        $fingerprint = orange_restore_exec_build_package_fingerprint(
            $backupRoot,
            $packageType,
            $packageId,
            $countryCode
        );
        $livePkg = (string) ($fingerprint['fingerprint'] ?? '');
        if ($livePkg === '' || !hash_equals((string) ($contract['package_fingerprint'] ?? ''), $livePkg)) {
            $reasons[] = 'package_changed';
        }

        $schemaRevision = (int) ($fingerprint['schema_revision'] ?? 0);
        if ($schemaRevision !== (int) ($contract['schema_revision'] ?? -1)
            || $schemaRevision !== ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION) {
            $reasons[] = 'schema_mismatch';
        }

        $backend = strtolower(trim((string) ($fingerprint['export_backend'] ?? '')));
        if ($backend === '') {
            $backend = ORANGE_RESTORE_EXEC_CONTRACT_BACKEND;
        }
        $contractBackend = strtolower(trim((string) ($contract['backend'] ?? '')));
        if ($contractBackend !== ORANGE_RESTORE_EXEC_CONTRACT_BACKEND
            || $backend !== ORANGE_RESTORE_EXEC_CONTRACT_BACKEND) {
            $reasons[] = 'backend_mismatch';
        }
    } catch (Throwable) {
        $reasons[] = 'package_changed';
    }

    $dryPath = orange_restore_dry_run_report_path($workRoot, $jobId);
    $liveDry = orange_restore_exec_file_sha256($dryPath);
    if ($liveDry === '' || !hash_equals((string) ($contract['dry_run_fingerprint'] ?? ''), $liveDry)) {
        $reasons[] = 'package_changed';
    }

    $approvalPath = orange_restore_final_approval_record_path($workRoot, $jobId);
    if (!is_file($approvalPath)) {
        $reasons[] = 'approval_changed';
    } else {
        $approvalRaw = file_get_contents($approvalPath);
        $approval = is_string($approvalRaw) ? json_decode($approvalRaw, true) : null;
        if (!is_array($approval)) {
            $reasons[] = 'approval_changed';
        } else {
            $liveApprovalHash = orange_restore_bridge_approval_hash($approval);
            if (!hash_equals((string) ($contract['approval_hash'] ?? ''), $liveApprovalHash)) {
                $reasons[] = 'approval_changed';
            }
            $livePlanFp = orange_restore_final_approval_plan_fingerprint($workRoot, $jobId);
            if (!hash_equals((string) ($approval['plan_fingerprint'] ?? ''), $livePlanFp)) {
                $reasons[] = 'plan_changed';
            }
        }
    }

    $versionLock = orange_restore_version_lock_evaluate($workRoot, $jobId, $backupRoot);
    if (!($versionLock['ok'] ?? false)) {
        $reasons[] = 'version_mismatch';
    }

    $reasons = array_values(array_unique($reasons));
    $ok = $reasons === [];
    $code = $ok ? 'ok' : (string) $reasons[0];

    $contract['execution_started'] = false;
    $contract['cli_invoked'] = false;

    return [
        'ok' => $ok,
        'code' => $code,
        'reasons' => $reasons,
        'contract' => $contract,
    ];
}

/**
 * @param array<string, mixed> $contract
 * @return array<string, mixed>
 */
function orange_restore_bridge_public_contract(array $contract): array
{
    unset($contract['absolute_paths'], $contract['package_path'], $contract['secrets'], $contract['password']);

    return [
        'contract_version' => (string) ($contract['contract_version'] ?? ''),
        'framework_job_id' => (string) ($contract['framework_job_id'] ?? ''),
        'package_id' => (string) ($contract['package_id'] ?? ''),
        'package_type' => (string) ($contract['package_type'] ?? ''),
        'country_code' => $contract['country_code'] ?? null,
        'execution_plan_hash' => (string) ($contract['execution_plan_hash'] ?? ''),
        'package_fingerprint' => (string) ($contract['package_fingerprint'] ?? ''),
        'dry_run_fingerprint' => (string) ($contract['dry_run_fingerprint'] ?? ''),
        'approval_hash' => (string) ($contract['approval_hash'] ?? ''),
        'restore_mode' => (string) ($contract['restore_mode'] ?? ''),
        'backend' => (string) ($contract['backend'] ?? ''),
        'schema_revision' => (int) ($contract['schema_revision'] ?? 0),
        'execution_profile' => is_array($contract['execution_profile'] ?? null)
            ? $contract['execution_profile']
            : [],
        'created_at' => (string) ($contract['created_at'] ?? ''),
        'execution_started' => false,
        'cli_invoked' => false,
        'cli_request' => is_array($contract['cli_request'] ?? null) ? [
            'invoked' => false,
            'execution_allowed' => false,
            'execution_started' => false,
            'primary_cli' => (string) ($contract['cli_request']['primary_cli'] ?? ''),
            'staging_cli' => (string) ($contract['cli_request']['staging_cli'] ?? ''),
            'orchestration_module' => (string) ($contract['cli_request']['orchestration_module'] ?? ''),
            'orchestration_entry_function' => (string) ($contract['cli_request']['orchestration_entry_function'] ?? ''),
            'phase2_cli_entrypoints' => is_array($contract['cli_request']['phase2_cli_entrypoints'] ?? null)
                ? array_values($contract['cli_request']['phase2_cli_entrypoints'])
                : [],
            'argv_template' => is_array($contract['cli_request']['argv_template'] ?? null)
                ? array_values($contract['cli_request']['argv_template'])
                : [],
            'note' => (string) ($contract['cli_request']['note'] ?? 'Metadata only.'),
        ] : [
            'invoked' => false,
            'execution_allowed' => false,
            'execution_started' => false,
        ],
        'version_lock' => is_array($contract['version_lock'] ?? null) ? $contract['version_lock'] : null,
        'bridge_version' => (string) ($contract['bridge_version'] ?? ''),
        'warning' => (string) ($contract['warning'] ?? 'Execution contract is metadata only. No restore executed.'),
    ];
}
