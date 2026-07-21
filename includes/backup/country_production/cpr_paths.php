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
const ORANGE_CPR_SCAFFOLD_VERSION = 'P3-04-checkpoint-engine';

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
    return rtrim($cprRoot, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . '.country_production_restore.lock';
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
