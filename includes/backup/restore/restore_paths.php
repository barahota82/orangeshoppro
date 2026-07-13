<?php

declare(strict_types=1);

require_once __DIR__ . '/../backup_paths.php';

const ORANGE_RESTORE_WORK_DIRNAME = 'restore_work';
const ORANGE_RESTORE_LOCK_FILENAME = '.restore.lock';
const ORANGE_RESTORE_JOB_FILENAME = 'job.json';
const ORANGE_RESTORE_AUDIT_FILENAME = 'audit.jsonl';
const ORANGE_RESTORE_ENGINE_VERSION = '2D.3-uploads-cutover-crash-recovery';
const ORANGE_RESTORE_UPLOADS_NEXT_DIRNAME = 'uploads_next';
const ORANGE_RESTORE_UPLOADS_NEXT_MANIFEST_FILENAME = 'uploads_next_manifest.json';
const ORANGE_RESTORE_PRE_MERGE_UPLOADS_SNAPSHOT_DIRNAME = 'pre_merge_uploads_snapshot';
const ORANGE_RESTORE_PRE_MERGE_UPLOADS_SNAPSHOT_MANIFEST_FILENAME = 'snapshot_manifest.json';
const ORANGE_RESTORE_PRE_MERGE_UPLOADS_SNAPSHOT_CHECKSUMS_FILENAME = 'checksums.sha256';
const ORANGE_RESTORE_ENV_STAGING_DB = 'ORANGE_RESTORE_STAGING_DB';
const ORANGE_RESTORE_ENV_MERGE_DB_USER = 'ORANGE_RESTORE_MERGE_DB_USER';
const ORANGE_RESTORE_ENV_MERGE_DB_PASS = 'ORANGE_RESTORE_MERGE_DB_PASS';
const ORANGE_RESTORE_STAGING_MANIFEST_FILE = 'staging_restore_manifest.json';
const ORANGE_RESTORE_REPORT_FILE = 'restore_report.json';
const ORANGE_RESTORE_MAINTENANCE_FILENAME = '.maintenance.json';
const ORANGE_RESTORE_MERGE_DB_EXPORT_FILENAME = 'merge_db_export.sql.gz';
const ORANGE_RESTORE_MERGE_DB_EXPORT_MANIFEST_FILENAME = 'merge_db_export_manifest.json';
const ORANGE_RESTORE_APPROVAL_WINDOW_SECONDS = 604800;
const ORANGE_RESTORE_APPROVAL_TOKEN_TTL_SECONDS = 3600;
const ORANGE_RESTORE_APPROVAL_TOKEN_FILENAME = 'approval_token.json';

/**
 * @param array<string, mixed> $env
 */
function orange_restore_resolve_work_root(array $env, ?string $cliOverride = null): string
{
    $backupRoot = orange_backup_resolve_root($env, $cliOverride);
    $configured = trim((string) ($env['ORANGE_RESTORE_WORK_DIR'] ?? ''));
    if ($configured !== '') {
        $workRoot = orange_backup_normalize_directory_path($configured);
        orange_backup_assert_outside_web_root($workRoot);
        if (!is_dir($workRoot) && !@mkdir($workRoot, 0775, true) && !is_dir($workRoot)) {
            throw new RuntimeException('ORANGE_RESTORE_WORK_DIR is not writable or cannot be created: ' . $workRoot);
        }

        return realpath($workRoot) ?: $workRoot;
    }

    $workRoot = $backupRoot . DIRECTORY_SEPARATOR . ORANGE_RESTORE_WORK_DIRNAME;
    if (!is_dir($workRoot) && !@mkdir($workRoot, 0775, true) && !is_dir($workRoot)) {
        throw new RuntimeException('Restore work directory cannot be created: ' . $workRoot);
    }

    return realpath($workRoot) ?: $workRoot;
}

function orange_restore_assert_inside_work_root(string $workRoot, string $absolutePath): string
{
    $workNorm = strtolower(rtrim(str_replace('\\', '/', realpath($workRoot) ?: $workRoot), '/'));
    $targetNorm = strtolower(rtrim(str_replace('\\', '/', realpath($absolutePath) ?: $absolutePath), '/'));
    if ($targetNorm === $workNorm || str_starts_with($targetNorm, $workNorm . '/')) {
        return realpath($absolutePath) ?: $absolutePath;
    }

    throw new RuntimeException('Restore path escapes work root: ' . $absolutePath);
}

function orange_restore_generate_job_id(): string
{
    return gmdate('Y-m-d_His') . '_' . bin2hex(random_bytes(4));
}

function orange_restore_job_directory(string $workRoot, string $jobId): string
{
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $jobId)) {
        throw new RuntimeException('Invalid restore job id.');
    }
    $dir = $workRoot . DIRECTORY_SEPARATOR . $jobId;
    orange_restore_assert_inside_work_root($workRoot, $dir);

    return $dir;
}

function orange_restore_job_file_path(string $workRoot, string $jobId): string
{
    return orange_restore_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_JOB_FILENAME;
}

function orange_restore_audit_file_path(string $workRoot, string $jobId): string
{
    return orange_restore_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_AUDIT_FILENAME;
}

function orange_restore_global_lock_path(string $workRoot): string
{
    return $workRoot . DIRECTORY_SEPARATOR . ORANGE_RESTORE_LOCK_FILENAME;
}

function orange_restore_merge_maintenance_file_path(string $workRoot): string
{
    return $workRoot . DIRECTORY_SEPARATOR . ORANGE_RESTORE_MAINTENANCE_FILENAME;
}

function orange_restore_merge_db_export_gzip_path(string $workRoot, string $jobId): string
{
    return orange_restore_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_MERGE_DB_EXPORT_FILENAME;
}

function orange_restore_merge_db_export_manifest_path(string $workRoot, string $jobId): string
{
    return orange_restore_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_MERGE_DB_EXPORT_MANIFEST_FILENAME;
}

function orange_restore_production_uploads_directory(string $projectRoot): string
{
    $root = realpath($projectRoot) ?: $projectRoot;

    return $root . DIRECTORY_SEPARATOR . 'uploads';
}

function orange_restore_uploads_next_directory(string $projectRoot): string
{
    $root = realpath($projectRoot) ?: $projectRoot;

    return $root . DIRECTORY_SEPARATOR . ORANGE_RESTORE_UPLOADS_NEXT_DIRNAME;
}

function orange_restore_uploads_pre_merge_directory(string $projectRoot, string $jobId): string
{
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $jobId)) {
        throw new RuntimeException('Invalid restore job id.');
    }
    $root = realpath($projectRoot) ?: $projectRoot;

    return $root . DIRECTORY_SEPARATOR . 'uploads_pre_merge_' . $jobId;
}

function orange_restore_uploads_next_manifest_path(string $workRoot, string $jobId): string
{
    return orange_restore_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_UPLOADS_NEXT_MANIFEST_FILENAME;
}

function orange_restore_pre_merge_uploads_snapshot_directory(string $workRoot, string $jobId): string
{
    return orange_restore_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRE_MERGE_UPLOADS_SNAPSHOT_DIRNAME;
}

function orange_restore_pre_merge_uploads_snapshot_manifest_path(string $workRoot, string $jobId): string
{
    return orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRE_MERGE_UPLOADS_SNAPSHOT_MANIFEST_FILENAME;
}

function orange_restore_pre_merge_uploads_snapshot_checksums_path(string $workRoot, string $jobId): string
{
    return orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRE_MERGE_UPLOADS_SNAPSHOT_CHECKSUMS_FILENAME;
}

/**
 * Resolve an existing backup package directory under BackupRoot.
 */
function orange_restore_resolve_package_path(string $backupRoot, string $packagePath): string
{
    $candidate = orange_backup_normalize_directory_path($packagePath);
    $resolved = realpath($candidate);
    if ($resolved === false || !is_dir($resolved)) {
        throw new RuntimeException('Restore package path does not exist: ' . $packagePath);
    }
    $backupNorm = strtolower(rtrim(str_replace('\\', '/', realpath($backupRoot) ?: $backupRoot), '/'));
    $resolvedNorm = strtolower(rtrim(str_replace('\\', '/', $resolved), '/'));
    if ($resolvedNorm !== $backupNorm && !str_starts_with($resolvedNorm, $backupNorm . '/')) {
        throw new RuntimeException('Restore package must be inside ORANGE_BACKUP_ROOT.');
    }

    return $resolved;
}
