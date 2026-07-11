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
        if (!@mkdir($candidate, 0775, true) && !is_dir($candidate)) {
            throw new RuntimeException('ORANGE_BACKUP_ROOT is not writable or cannot be created: ' . $candidate);
        }
        $resolved = realpath($candidate);
    }
    if ($resolved === false || !is_dir($resolved)) {
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
