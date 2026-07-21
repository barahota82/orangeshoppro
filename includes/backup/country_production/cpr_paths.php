<?php

declare(strict_types=1);

/**
 * CPR path helpers (WP-P3-02 scaffolding).
 *
 * Runtime root: {restoreWorkRoot}/country_production/
 * Does not modify Full DR or C3–C8 paths.
 */

require_once __DIR__ . '/../restore/restore_paths.php';

const ORANGE_CPR_DIRNAME = 'country_production';
const ORANGE_CPR_JOB_FILENAME = 'job.json';
const ORANGE_CPR_CONTRACT_FILENAME = 'execution_contract.json';
const ORANGE_CPR_AUDIT_FILENAME = 'audit.jsonl';
const ORANGE_CPR_CHECKPOINTS_DIRNAME = 'checkpoints';
const ORANGE_CPR_CHECKPOINTS_TMP_DIRNAME = '.tmp';
const ORANGE_CPR_CHECKPOINTS_MANIFEST = 'MANIFEST.json';
const ORANGE_CPR_SCAFFOLD_VERSION = 'P5-03-import-live';
const ORANGE_CPR_GATES_DIRNAME = 'gates';
const ORANGE_CPR_AUTH_DIRNAME = 'auth';
const ORANGE_CPR_AUTH_LIVE_DIRNAME = 'auth_live';
const ORANGE_CPR_WITNESSES_LIVE_DIRNAME = 'witnesses_live';
const ORANGE_CPR_INTEGRATION_LIVE_DIRNAME = 'integration_live';
const ORANGE_CPR_DELETE_LIVE_DIRNAME = 'delete_live';
const ORANGE_CPR_IMPORT_LIVE_DIRNAME = 'import_live';
const ORANGE_CPR_APPROVALS_DIRNAME = 'approvals';
const ORANGE_CPR_MAINT_DIRNAME = 'maintenance';
const ORANGE_CPR_MAINT_STATE_FILENAME = 'maint_state.json';
const ORANGE_CPR_OD_PIN_DIRNAME = 'od_pin';
const ORANGE_CPR_LOCK_LIVE_DIRNAME = 'lock_live';
const ORANGE_CPR_GATES_LIVE_DIRNAME = 'gates_live';
const ORANGE_CPR_PIPELINE_DIRNAME = 'pipeline';
const ORANGE_CPR_LOCK_FILENAME = '.country_production_restore.lock';
const ORANGE_CPR_SHADOW_DIRNAME = 'country_shadow';
const ORANGE_CPR_SHADOW_LOCK_FILENAME = '.country_shadow_restore.lock';
const ORANGE_CPR_FW_LOCK_FILENAME = '.restore_framework.lock';
const ORANGE_CPR_ORCH_LOCK_FILENAME = '.restore_execution_orchestrator.lock';

/**
 * Resolve CPR runtime root.
 *
 * Prefer ORANGE_CPR_WORK_DIR when set (tests / explicit config).
 * Else: {restoreWorkRoot}/country_production/ (Architecture).
 *
 * @param array<string, mixed> $env
 */
function orange_cpr_resolve_work_root(array $env, ?string $cliOverride = null): string
{
    $configured = trim((string) ($cliOverride ?? ($env['ORANGE_CPR_WORK_DIR'] ?? '')));
    if ($configured !== '') {
        require_once __DIR__ . '/../backup_paths.php';
        $root = orange_backup_normalize_directory_path($configured);
        orange_backup_assert_outside_web_root($root);
        if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException('ORANGE_CPR_WORK_DIR is not writable or cannot be created: ' . $root);
        }

        return realpath($root) ?: $root;
    }

    $restoreWork = orange_restore_resolve_work_root($env, null);
    $root = rtrim($restoreWork, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . ORANGE_CPR_DIRNAME;
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        throw new RuntimeException('Cannot create CPR work root: ' . $root);
    }
    orange_restore_assert_inside_work_root($restoreWork, $root);

    return realpath($root) ?: $root;
}

function orange_cpr_assert_job_id(string $jobId): void
{
    if ($jobId === '' || !preg_match('/^[a-f0-9-]{36}$/i', $jobId)) {
        throw new RuntimeException('Invalid CPR job_id (UUID required).');
    }
}

function orange_cpr_generate_job_id(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function orange_cpr_job_directory(string $cprRoot, string $jobId): string
{
    orange_cpr_assert_job_id($jobId);
    $dir = rtrim($cprRoot, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . $jobId;
    $parent = dirname($dir);
    $parentReal = realpath($parent) ?: $parent;
    $cprReal = realpath($cprRoot) ?: $cprRoot;
    $parentNorm = strtolower(rtrim(str_replace('\\', '/', $parentReal), '/'));
    $cprNorm = strtolower(rtrim(str_replace('\\', '/', $cprReal), '/'));
    if ($parentNorm !== $cprNorm && !str_starts_with($parentNorm, $cprNorm . '/')) {
        throw new RuntimeException('CPR job path escapes CPR root.');
    }

    return $dir;
}

function orange_cpr_job_file_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_JOB_FILENAME;
}

function orange_cpr_contract_file_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_CONTRACT_FILENAME;
}

function orange_cpr_audit_file_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME;
}

function orange_cpr_lock_file_path(string $cprRoot): string
{
    return rtrim($cprRoot, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . ORANGE_CPR_LOCK_FILENAME;
}

/**
 * Restore work root used for Full DR / C6 peer lock observation (P1-05 §4).
 *
 * @param array<string, mixed> $env
 */
function orange_cpr_resolve_peer_restore_work_root(array $env, ?string $cprRoot = null): string
{
    $configured = trim((string) ($env['ORANGE_RESTORE_WORK_DIR'] ?? ''));
    if ($configured !== '') {
        require_once __DIR__ . '/../backup_paths.php';
        $root = orange_backup_normalize_directory_path($configured);
        if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException('ORANGE_RESTORE_WORK_DIR cannot be created.');
        }

        return realpath($root) ?: $root;
    }

    $cprRoot ??= orange_cpr_resolve_work_root($env);
    $base = basename(rtrim(str_replace('\\', '/', $cprRoot), '/'));
    if (strcasecmp($base, ORANGE_CPR_DIRNAME) === 0) {
        $parent = dirname($cprRoot);

        return realpath($parent) ?: $parent;
    }

    // Standalone CPR work dir (tests): peers live beside it under parent.
    $parent = dirname($cprRoot);

    return realpath($parent) ?: $parent;
}

/**
 * @param array<string, mixed> $env
 * @return list<string>
 */
function orange_cpr_full_dr_lock_paths(array $env, ?string $cprRoot = null): array
{
    $work = orange_cpr_resolve_peer_restore_work_root($env, $cprRoot);

    return [
        $work . DIRECTORY_SEPARATOR . ORANGE_RESTORE_LOCK_FILENAME,
        $work . DIRECTORY_SEPARATOR . ORANGE_CPR_FW_LOCK_FILENAME,
        $work . DIRECTORY_SEPARATOR . ORANGE_CPR_ORCH_LOCK_FILENAME,
    ];
}

/**
 * @param array<string, mixed> $env
 */
function orange_cpr_c6_lock_path(array $env, ?string $cprRoot = null): string
{
    $work = orange_cpr_resolve_peer_restore_work_root($env, $cprRoot);

    return $work . DIRECTORY_SEPARATOR . ORANGE_CPR_SHADOW_DIRNAME
        . DIRECTORY_SEPARATOR . ORANGE_CPR_SHADOW_LOCK_FILENAME;
}

/**
 * @param array<string, mixed> $env
 */
function orange_cpr_backup_runner_lock_path(array $env): string
{
    require_once __DIR__ . '/../backup_environment.php';
    $backupRoot = orange_backup_resolve_root($env, null);

    return rtrim($backupRoot, DIRECTORY_SEPARATOR . '/\\')
        . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ORANGE_BACKUP_LOCK_RELATIVE);
}

function orange_cpr_lock_manual_clear_audit_directory(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . 'audit';
}

function orange_cpr_gates_directory(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_GATES_DIRNAME;
}

function orange_cpr_auth_directory(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_AUTH_DIRNAME;
}

function orange_cpr_approvals_directory(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_APPROVALS_DIRNAME;
}

function orange_cpr_maint_directory(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_MAINT_DIRNAME;
}

function orange_cpr_maint_state_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_maint_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_MAINT_STATE_FILENAME;
}

function orange_cpr_od_pin_directory(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_OD_PIN_DIRNAME;
}

function orange_cpr_lock_live_directory(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_LOCK_LIVE_DIRNAME;
}

function orange_cpr_gates_live_directory(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_GATES_LIVE_DIRNAME;
}

function orange_cpr_auth_live_directory(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_AUTH_LIVE_DIRNAME;
}

function orange_cpr_witnesses_live_directory(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_WITNESSES_LIVE_DIRNAME;
}

function orange_cpr_integration_live_directory(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_INTEGRATION_LIVE_DIRNAME;
}

function orange_cpr_delete_live_directory(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_DELETE_LIVE_DIRNAME;
}

function orange_cpr_import_live_directory(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_IMPORT_LIVE_DIRNAME;
}

function orange_cpr_pipeline_directory(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_PIPELINE_DIRNAME;
}

function orange_cpr_checkpoints_directory(string $cprRoot, string $jobId): string
{
    return orange_cpr_job_directory($cprRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_CPR_CHECKPOINTS_DIRNAME;
}

function orange_cpr_checkpoints_tmp_directory(string $cprRoot, string $jobId): string
{
    return orange_cpr_checkpoints_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_CPR_CHECKPOINTS_TMP_DIRNAME;
}

function orange_cpr_checkpoints_manifest_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_checkpoints_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_CPR_CHECKPOINTS_MANIFEST;
}

/**
 * Atomic-ish replace within the same directory (Windows-safe unlink+rename pattern).
 */
function orange_cpr_atomic_rename_replace(string $tmpPath, string $finalPath): void
{
    if (!is_file($tmpPath)) {
        throw new RuntimeException('Atomic rename source missing: ' . $tmpPath);
    }
    $finalDir = dirname($finalPath);
    if (!is_dir($finalDir) && !@mkdir($finalDir, 0775, true) && !is_dir($finalDir)) {
        throw new RuntimeException('Cannot create checkpoint directory.');
    }
    if (is_file($finalPath)) {
        if (!@unlink($finalPath)) {
            throw new RuntimeException('Cannot replace existing checkpoint file.');
        }
    }
    if (!@rename($tmpPath, $finalPath)) {
        @unlink($tmpPath);
        throw new RuntimeException('Atomic rename to checkpoint final path failed.');
    }
}
