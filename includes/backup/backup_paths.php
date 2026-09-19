<?php

declare(strict_types=1);

/**
 * BackupRoot resolution and path safety (Phase 1A — full disaster backup).
 */

function orange_backup_project_root(): string
{
    static $root = null;
    if ($root !== null) {
        return $root;
    }
    $root = realpath(__DIR__ . '/../..');
    if ($root === false) {
        throw new RuntimeException('Cannot resolve Orange project root.');
    }

    return $root;
}

function orange_backup_normalize_directory_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

    return rtrim($normalized, DIRECTORY_SEPARATOR);
}

function orange_backup_directory_path_is_absolute(string $path): bool
{
    $path = trim($path);
    if ($path === '') {
        return false;
    }
    if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
        return true;
    }
    if (str_starts_with($path, '\\\\') || str_starts_with($path, '//')) {
        return true;
    }

    return str_starts_with($path, '/') || str_starts_with($path, '\\');
}

function orange_backup_absolute_directory_path(string $path): string
{
    $normalized = orange_backup_normalize_directory_path($path);
    if ($normalized === '' || orange_backup_directory_path_is_absolute($normalized)) {
        return $normalized;
    }

    $cwd = getcwd();
    $base = $cwd !== false ? $cwd : orange_backup_project_root();

    return orange_backup_normalize_directory_path(rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalized);
}

/**
 * @return array<string, mixed>
 */
function orange_backup_probe_directory_path(string $path): array
{
    $configured = trim($path);
    $normalized = orange_backup_normalize_directory_path($configured);
    $realpath = @realpath($configured);
    if ($realpath === false && $normalized !== '' && $normalized !== $configured) {
        $realpath = @realpath($normalized);
    }

    return [
        'configured_path' => $configured,
        'normalized_path' => $normalized,
        'realpath' => $realpath !== false ? $realpath : '',
        'realpath_ok' => $realpath !== false,
        'is_dir_configured' => $configured !== '' && is_dir($configured),
        'is_dir_normalized' => $normalized !== '' && is_dir($normalized),
        'file_exists_configured' => $configured !== '' && file_exists($configured),
        'dirname_configured' => $configured !== '' ? dirname($configured) : '',
        'getcwd' => getcwd() !== false ? getcwd() : '',
        'open_basedir' => ini_get('open_basedir') ?: '',
    ];
}

/**
 * @param array<string, mixed> $env
 */
function orange_backup_resolve_root(array $env, ?string $cliOverride = null): string
{
    if ($cliOverride !== null) {
        $candidate = trim((string) $cliOverride);
        if ($candidate === '') {
            throw new RuntimeException('ORANGE_BACKUP_ROOT must not be empty.');
        }
    } else {
        $candidate = trim((string) ($env['ORANGE_BACKUP_ROOT'] ?? ''));
        if ($candidate === '' && array_key_exists('ORANGE_BACKUP_ROOT', $env)) {
            throw new RuntimeException('ORANGE_BACKUP_ROOT must not be empty.');
        }
    }
    if ($candidate === '') {
        $projectRoot = orange_backup_project_root();
        $drive = preg_match('/^([A-Za-z]:)/', $projectRoot, $m) ? $m[1] : '';
        $candidate = $drive !== '' ? $drive . '\\orange_backups' : dirname($projectRoot) . DIRECTORY_SEPARATOR . 'orange_backups';
    }

    $candidateNorm = str_replace('\\', '/', $candidate);
    if (preg_match('#(^|/)(httpdocs|public_html|wwwroot)(/|$)#i', $candidateNorm)) {
        throw new RuntimeException('ORANGE_BACKUP_ROOT must not be inside a public web root (httpdocs/public_html/wwwroot).');
    }

    if (str_contains($candidate, '..')) {
        throw new RuntimeException('ORANGE_BACKUP_ROOT must not contain path traversal (..).');
    }

    $resolved = realpath($candidate);
    if ($resolved === false) {
        if (!is_dir($candidate)) {
            if (!@mkdir($candidate, 0775, true) && !is_dir($candidate)) {
                throw new RuntimeException('ORANGE_BACKUP_ROOT is not writable or cannot be created: ' . $candidate);
            }
        }
        $resolved = realpath($candidate);
    }
    if ($resolved === false) {
        if (is_dir($candidate)) {
            $resolved = orange_backup_absolute_directory_path($candidate);
        } else {
            throw new RuntimeException('ORANGE_BACKUP_ROOT is not a directory: ' . $candidate);
        }
    }
    if (!is_dir($resolved)) {
        throw new RuntimeException('ORANGE_BACKUP_ROOT is not a directory: ' . $candidate);
    }
    if (!is_writable($resolved)) {
        throw new RuntimeException('ORANGE_BACKUP_ROOT is not writable: ' . $resolved);
    }

    orange_backup_assert_outside_web_root($resolved);

    return $resolved;
}

function orange_backup_assert_outside_web_root(string $path): void
{
    $projectRoot = orange_backup_project_root();
    $uploadsRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads');
    $normPath = str_replace('\\', '/', strtolower(rtrim($path, '\\/')));
    $normProject = str_replace('\\', '/', strtolower(rtrim($projectRoot, '\\/')));
    $normUploads = $uploadsRoot !== false
        ? str_replace('\\', '/', strtolower(rtrim($uploadsRoot, '\\/')))
        : $normProject . '/uploads';

    if ($normPath === $normProject || str_starts_with($normPath, $normProject . '/')) {
        if ($normPath === $normUploads || str_starts_with($normPath, $normUploads . '/')) {
            throw new RuntimeException('BackupRoot must not be inside uploads/: ' . $path);
        }
        throw new RuntimeException('BackupRoot must not be inside the web project root: ' . $path);
    }
}

function orange_backup_path_inside_root(string $backupRoot, string $relativePath): string
{
    $backupRoot = rtrim(str_replace('\\', '/', realpath($backupRoot) ?: $backupRoot), '/');
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        throw new RuntimeException('Invalid backup relative path.');
    }
    $target = str_replace('\\', '/', realpath($backupRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath))
        ?: $backupRoot . '/' . $relativePath);
    if (!str_starts_with(strtolower($target), strtolower($backupRoot . '/'))) {
        throw new RuntimeException('Refusing path outside BackupRoot: ' . $target);
    }

    return $target;
}

function orange_backup_git_commit_hash(?string $projectRoot = null): ?string
{
    $projectRoot = $projectRoot ?? orange_backup_project_root();
    $gitDir = $projectRoot . DIRECTORY_SEPARATOR . '.git';
    if (!is_dir($gitDir)) {
        return null;
    }
    $cmd = 'git -C ' . escapeshellarg($projectRoot) . ' rev-parse --short HEAD 2>/dev/null';
    if (DIRECTORY_SEPARATOR === '\\') {
        $cmd = 'git -C ' . escapeshellarg($projectRoot) . ' rev-parse --short HEAD 2>nul';
    }
    $out = shell_exec($cmd);
    if (!is_string($out)) {
        return null;
    }
    $hash = trim($out);

    return $hash !== '' ? $hash : null;
}
