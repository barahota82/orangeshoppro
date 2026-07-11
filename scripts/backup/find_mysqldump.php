<?php

declare(strict_types=1);

/**
 * Read-only Windows diagnostic: locate mysqldump.exe on the local server.
 *
 * Usage:
 *   php scripts/backup/find_mysqldump.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if (DIRECTORY_SEPARATOR !== '\\') {
    fwrite(STDERR, "find_mysqldump.php is intended for Windows hosts only.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';

/**
 * @return list<string>
 */
function find_mysqldump_search_roots(): array
{
    $roots = [
        'C:\\Program Files (x86)\\Plesk',
        'C:\\Program Files\\Plesk',
        'C:\\Program Files (x86)\\Parallels\\Plesk',
        'C:\\Program Files\\Parallels\\Plesk',
        'C:\\Program Files',
        'C:\\Program Files (x86)',
    ];

    foreach (orange_backup_plesk_base_directories() as $base) {
        $roots[] = $base;
        $parent = dirname($base);
        if ($parent !== '' && $parent !== '.' && $parent !== $base) {
            $roots[] = $parent;
        }
    }

    foreach (['ProgramFiles', 'ProgramFiles(x86)'] as $envKey) {
        $value = getenv($envKey);
        if (is_string($value) && trim($value) !== '') {
            $roots[] = rtrim(str_replace('/', '\\', trim($value)), '\\');
        }
    }

    $normalized = [];
    foreach ($roots as $root) {
        $root = rtrim(str_replace('/', '\\', trim($root)), '\\');
        if ($root === '') {
            continue;
        }
        $key = strtolower($root);
        if (!isset($normalized[$key])) {
            $normalized[$key] = $root;
        }
    }

    return array_values($normalized);
}

function find_mysqldump_path_accessible(string $path): bool
{
    return orange_backup_tool_path_accessible($path);
}

function find_mysqldump_normalize_path(string $path): string
{
    $path = trim(str_replace('/', '\\', $path));
    $resolved = realpath($path);

    return $resolved !== false ? $resolved : $path;
}

/**
 * @param list<string> $paths
 * @return list<string>
 */
function find_mysqldump_unique_paths(array $paths): array
{
    $unique = [];
    foreach ($paths as $path) {
        $path = find_mysqldump_normalize_path($path);
        if ($path === '') {
            continue;
        }
        $key = strtolower($path);
        if (!isset($unique[$key])) {
            $unique[$key] = $path;
        }
    }

    return array_values($unique);
}

/**
 * @return list<string>
 */
function find_mysqldump_from_path_env(): array
{
    if (!orange_backup_can_execute_commands()) {
        return [];
    }

    $found = [];
    foreach (['mysqldump', 'mysqldump.exe'] as $binary) {
        $result = orange_backup_run_command_capture(['where.exe', $binary], 20);
        if ($result['exit_code'] !== 0) {
            continue;
        }
        $lines = preg_split('/\R/', str_replace("\r", '', (string) ($result['stdout'] ?? ''))) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && find_mysqldump_path_accessible($line)) {
                $found[] = $line;
            }
        }
    }

    return find_mysqldump_unique_paths($found);
}

/**
 * @return list<string>
 */
function find_mysqldump_from_known_paths(): array
{
    $found = [];
    foreach (orange_backup_mysqldump_known_paths() as $candidate) {
        if (find_mysqldump_path_accessible($candidate)) {
            $found[] = $candidate;
        }
    }

    return find_mysqldump_unique_paths($found);
}

function find_mysqldump_directory_exists(string $root): bool
{
    return is_dir($root) || orange_backup_windows_path_visible_via_shell($root);
}

/**
 * @return list<string>
 */
function find_mysqldump_where_recursive(string $root, int $timeoutSeconds = 30): array
{
    if (!orange_backup_can_execute_commands()) {
        return [];
    }

    $root = rtrim(str_replace('/', '\\', $root), '\\');
    if ($root === '' || !find_mysqldump_directory_exists($root)) {
        return [];
    }

    $line = 'where /r ' . escapeshellarg($root) . ' mysqldump.exe';
    $result = orange_backup_run_command_capture(['cmd.exe', '/c', $line], $timeoutSeconds);
    if ($result['exit_code'] !== 0) {
        return [];
    }

    $found = [];
    $lines = preg_split('/\R/', str_replace("\r", '', (string) ($result['stdout'] ?? ''))) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '' && find_mysqldump_path_accessible($line)) {
            $found[] = $line;
        }
    }

    return find_mysqldump_unique_paths($found);
}

/**
 * @return list<string>
 */
function find_mysqldump_from_program_files(): array
{
    $found = [];

    foreach (orange_backup_plesk_base_directories() as $base) {
        $found = array_merge($found, find_mysqldump_where_recursive($base, 30));
    }

    foreach (find_mysqldump_search_roots() as $root) {
        $basename = strtolower(basename($root));
        if (in_array($basename, ['program files', 'program files (x86)'], true)) {
            continue;
        }
        $found = array_merge($found, find_mysqldump_where_recursive($root, 30));
    }

    foreach (['C:\\Program Files', 'C:\\Program Files (x86)'] as $programFilesRoot) {
        if (!find_mysqldump_directory_exists($programFilesRoot)) {
            continue;
        }
        $entries = @scandir($programFilesRoot);
        if (!is_array($entries)) {
            continue;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!preg_match('/^(MariaDB|MySQL|Plesk|Parallels)/i', $entry)) {
                continue;
            }
            $found = array_merge(
                $found,
                find_mysqldump_where_recursive($programFilesRoot . '\\' . $entry, 25)
            );
        }
    }

    return find_mysqldump_unique_paths($found);
}

function find_mysqldump_version_string(string $path): string
{
    if (orange_backup_can_proc_open()) {
        $result = orange_backup_run_command_capture([$path, '--version'], 15);
        $combined = trim(preg_replace('/\s+/', ' ', $result['stdout'] . ' ' . $result['stderr']) ?? '');
        if ($combined !== '') {
            return $combined;
        }
    }

    $ps = 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    if (orange_backup_can_proc_open() && is_file($ps)) {
        $command = '(Get-Item -LiteralPath ' . escapeshellarg($path) . ').VersionInfo | ForEach-Object { $_.ProductVersion ?? $_.FileVersion }';
        $result = orange_backup_run_command_capture([
            $ps,
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            $command,
        ], 15);
        $version = trim($result['stdout']);
        if ($version !== '') {
            return $version;
        }
    }

    return '';
}

/**
 * @param list<string> $paths
 * @return list<array{path:string,source:string,version:string,runnable:bool}>
 */
function find_mysqldump_build_entries(array $paths, string $source): array
{
    $entries = [];
    foreach ($paths as $path) {
        $normalized = find_mysqldump_normalize_path($path);
        $entries[] = [
            'path' => $normalized,
            'source' => $source,
            'version' => find_mysqldump_version_string($normalized),
            'runnable' => orange_backup_mysqldump_probe_runnable($normalized),
        ];
    }

    return $entries;
}

/**
 * @param list<array{path:string,source:string,version:string,runnable:bool}> $entries
 */
function find_mysqldump_pick_recommended(array $entries): ?string
{
    $pleskNeedle = '\\plesk\\';
    foreach ($entries as $entry) {
        if (!empty($entry['runnable']) && str_contains(strtolower($entry['path']), $pleskNeedle)) {
            return $entry['path'];
        }
    }
    foreach ($entries as $entry) {
        if (!empty($entry['runnable'])) {
            return $entry['path'];
        }
    }

    return $entries[0]['path'] ?? null;
}

try {
    $allPaths = find_mysqldump_unique_paths(array_merge(
        find_mysqldump_from_path_env(),
        find_mysqldump_from_known_paths(),
        find_mysqldump_from_program_files()
    ));

    if ($allPaths === []) {
        echo 'NO_MYSQLDUMP_FOUND_ON_SERVER' . PHP_EOL;
        exit(1);
    }

    $entries = find_mysqldump_build_entries($allPaths, 'filesystem_search');
    usort($entries, static fn (array $a, array $b): int => strcasecmp($a['path'], $b['path']));

    echo 'discovered_count=' . count($entries) . PHP_EOL;
    foreach ($entries as $index => $entry) {
        $n = $index + 1;
        echo 'discovered_' . $n . '_path=' . $entry['path'] . PHP_EOL;
        echo 'discovered_' . $n . '_version=' . ($entry['version'] !== '' ? $entry['version'] : 'unknown') . PHP_EOL;
        echo 'discovered_' . $n . '_runnable=' . ($entry['runnable'] ? 'yes' : 'no') . PHP_EOL;
    }

    $recommended = find_mysqldump_pick_recommended($entries);
    if ($recommended !== null) {
        echo 'recommended_orange_mysqldump_path=' . $recommended . PHP_EOL;
        echo 'recommended_env_php_value=' . str_replace('\\', '\\\\', $recommended) . PHP_EOL;
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'find_mysqldump failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
