<?php

declare(strict_types=1);

/**
 * Restore Center worker PHP CLI execution contract (absolute-only).
 *
 * Separate from Backup Center's b47cbe86 execution contract in backup_admin.php.
 * Used by restore_center_orchestrator for non-Step-6 detached workers only.
 * Step 6 remains Phase-1 frozen and must not call this resolver.
 *
 * @see includes/backup/backup_admin.php orange_backup_admin_resolve_cli_php_binary (Backup Center)
 */

require_once __DIR__ . '/../backup_environment.php';

/**
 * True when $path looks like an absolute filesystem executable path (not bare "php").
 */
function orange_restore_worker_php_cli_path_is_absolute(string $path): bool
{
    $path = trim($path);
    if ($path === '') {
        return false;
    }
    if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
        return true;
    }
    if (str_starts_with($path, '\\\\') || str_starts_with($path, '/')) {
        return true;
    }

    return false;
}

/**
 * Runtime PHP_BINARY for Restore worker resolution (test override via $GLOBALS['orange_backup_test_php_binary']).
 */
function orange_restore_worker_runtime_php_binary(): string
{
    if (isset($GLOBALS['orange_backup_test_php_binary'])
        && is_string($GLOBALS['orange_backup_test_php_binary'])) {
        return trim($GLOBALS['orange_backup_test_php_binary']);
    }
    if (defined('PHP_BINARY') && is_string(PHP_BINARY)) {
        return trim(PHP_BINARY);
    }

    return '';
}

/**
 * Runtime PHP_BINDIR for Restore worker resolution (test override via $GLOBALS['orange_backup_test_php_bindir']).
 */
function orange_restore_worker_runtime_php_bindir(): string
{
    if (isset($GLOBALS['orange_backup_test_php_bindir'])
        && is_string($GLOBALS['orange_backup_test_php_bindir'])) {
        return trim($GLOBALS['orange_backup_test_php_bindir']);
    }
    if (defined('PHP_BINDIR') && is_string(PHP_BINDIR)) {
        return trim(PHP_BINDIR);
    }

    return '';
}

/**
 * Build deterministic absolute CLI candidates for Restore workers.
 * Allowed: ORANGE_PHP_CLI (absolute CLI), PHP_BINARY if CLI, Windows sibling php.exe beside php-cgi,
 * PHP_BINDIR/php(.exe). Forbidden: bare "php", PATH/where/which, hardcoded hosting trees, CGI-as-CLI.
 *
 * @return list<string>
 */
function orange_restore_worker_cli_php_candidate_paths(string $projectRoot): array
{
    $candidates = [];
    $env = orange_backup_load_env_array($projectRoot);
    if (isset($GLOBALS['orange_backup_test_env_override'])
        && is_array($GLOBALS['orange_backup_test_env_override'])) {
        $env = array_merge($env, $GLOBALS['orange_backup_test_env_override']);
    }
    $configured = trim((string) ($env['ORANGE_PHP_CLI'] ?? ''));
    if ($configured !== '') {
        $candidates[] = $configured;
    }

    $phpBinary = orange_restore_worker_runtime_php_binary();
    if ($phpBinary !== '') {
        $candidates[] = $phpBinary;
        $binDir = dirname($phpBinary);
        if (preg_match('/php-cgi(\.exe)?$/i', $phpBinary) === 1) {
            $sibling = preg_replace('/php-cgi(\.exe)?$/i', 'php$1', $phpBinary);
            if (is_string($sibling) && $sibling !== '') {
                $candidates[] = $sibling;
            }
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates[] = $binDir . DIRECTORY_SEPARATOR . 'php.exe';
        } else {
            $candidates[] = $binDir . DIRECTORY_SEPARATOR . 'php';
        }
    }

    $binDirConst = orange_restore_worker_runtime_php_bindir();
    if ($binDirConst !== '') {
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates[] = rtrim($binDirConst, "\\/") . DIRECTORY_SEPARATOR . 'php.exe';
        } else {
            $candidates[] = rtrim($binDirConst, '/') . '/php';
        }
    }

    return $candidates;
}

/**
 * True when $candidate is the Windows sibling php.exe of the runtime php-cgi binary.
 */
function orange_restore_worker_php_cli_is_windows_cgi_sibling(string $candidate, string $phpBinary): bool
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return false;
    }
    $phpBinary = trim($phpBinary);
    $candidate = trim($candidate);
    if ($phpBinary === '' || $candidate === '') {
        return false;
    }
    if (!orange_restore_worker_php_cli_path_is_absolute($phpBinary)
        || preg_match('/php-cgi\.exe$/i', $phpBinary) !== 1
        || !is_file($phpBinary)) {
        return false;
    }
    if (!orange_restore_worker_php_cli_path_is_absolute($candidate)
        || strcasecmp(basename($candidate), 'php.exe') !== 0
        || !is_file($candidate)) {
        return false;
    }
    $binDir = strtolower(rtrim(str_replace('\\', '/', dirname($phpBinary)), '/'));
    $candDir = strtolower(rtrim(str_replace('\\', '/', dirname($candidate)), '/'));

    return $binDir !== '' && $binDir === $candDir;
}

/**
 * True when $candidate is an allowlisted absolute Windows php.exe (never php-cgi).
 */
function orange_restore_worker_php_cli_windows_trust_existing_php_exe(string $candidate): bool
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return false;
    }
    $candidate = trim($candidate);
    if ($candidate === '') {
        return false;
    }
    if (strcasecmp(basename($candidate), 'php.exe') !== 0) {
        return false;
    }
    if (!orange_restore_worker_php_cli_path_is_absolute($candidate) || !is_file($candidate)) {
        return false;
    }

    return true;
}

/**
 * Operator/UI-safe resolve diagnostics for Restore workers (no raw filesystem paths).
 *
 * @return array<string, int|string>
 */
function orange_restore_worker_cli_php_safe_resolve_diag(string $projectRoot): array
{
    $phpBinary = orange_restore_worker_runtime_php_binary();
    $bindir = orange_restore_worker_runtime_php_bindir();
    $kind = 'empty';
    if ($phpBinary !== '') {
        if (preg_match('/php-cgi(?:\.exe)?$/i', $phpBinary) === 1) {
            $kind = 'cgi';
        } elseif (preg_match('/php(?:\.exe)?$/i', $phpBinary) === 1) {
            $kind = 'cli_named';
        } else {
            $kind = 'other';
        }
    }
    $env = [];
    try {
        $env = orange_backup_load_env_array($projectRoot);
        if (isset($GLOBALS['orange_backup_test_env_override']) && is_array($GLOBALS['orange_backup_test_env_override'])) {
            $env = array_merge($env, $GLOBALS['orange_backup_test_env_override']);
        }
    } catch (Throwable $e) {
        $env = [];
    }
    $configured = trim((string) ($env['ORANGE_PHP_CLI'] ?? ''));
    $candidates = 0;
    $absoluteHits = 0;
    $fileHits = 0;
    $trustedHits = 0;
    foreach (orange_restore_worker_cli_php_candidate_paths($projectRoot) as $candidate) {
        $candidate = orange_backup_normalize_tool_path(trim((string) $candidate));
        if ($candidate === '') {
            continue;
        }
        $candidates++;
        if (!orange_restore_worker_php_cli_path_is_absolute($candidate)) {
            continue;
        }
        $absoluteHits++;
        if (!is_file($candidate)) {
            continue;
        }
        $fileHits++;
        if (orange_restore_worker_php_cli_windows_trust_existing_php_exe($candidate)
            || orange_restore_worker_php_cli_is_windows_cgi_sibling($candidate, $phpBinary)) {
            $trustedHits++;
        }
    }

    return [
        'php_binary_kind' => $kind,
        'php_binary_absolute' => orange_restore_worker_php_cli_path_is_absolute($phpBinary) ? 1 : 0,
        'php_bindir_present' => $bindir !== '' ? 1 : 0,
        'orange_php_cli_configured' => $configured !== '' ? 1 : 0,
        'candidate_count' => $candidates,
        'candidate_absolute_count' => $absoluteHits,
        'candidate_file_count' => $fileHits,
        'candidate_trusted_php_exe_count' => $trustedHits,
        'execution_contract' => 'RESTORE_WORKER_ABSOLUTE_CLI',
    ];
}

function orange_restore_worker_cli_php_binary_is_cli(string $phpBinary): bool
{
    $phpBinary = trim($phpBinary);
    if ($phpBinary === '' || preg_match('/php-cgi(?:\.exe)?$/i', $phpBinary) === 1) {
        return false;
    }
    if (!orange_restore_worker_php_cli_path_is_absolute($phpBinary) || !is_file($phpBinary)) {
        return false;
    }

    $capture = orange_backup_run_command_capture([$phpBinary, '-r', 'echo PHP_SAPI;'], 20);
    if ((int) ($capture['exit_code'] ?? 1) !== 0) {
        return false;
    }

    return trim((string) ($capture['stdout'] ?? '')) === 'cli';
}

/**
 * Resolve absolute PHP CLI for Restore Center detached workers.
 * Never returns bare "php". Throws php_cli_binary_unavailable when unresolved.
 */
function orange_restore_worker_resolve_cli_php_binary(string $projectRoot): string
{
    $phpBinary = orange_restore_worker_runtime_php_binary();
    $seen = [];
    foreach (orange_restore_worker_cli_php_candidate_paths($projectRoot) as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            continue;
        }
        $candidate = orange_backup_normalize_tool_path($candidate);
        if ($candidate === '' || isset($seen[$candidate])) {
            continue;
        }
        $seen[$candidate] = true;
        if (!orange_restore_worker_php_cli_path_is_absolute($candidate)) {
            continue;
        }
        if (!is_file($candidate)) {
            continue;
        }
        $accepted = orange_restore_worker_cli_php_binary_is_cli($candidate);
        if (!$accepted && orange_restore_worker_php_cli_is_windows_cgi_sibling($candidate, $phpBinary)) {
            $accepted = true;
        }
        if (!$accepted && orange_restore_worker_php_cli_windows_trust_existing_php_exe($candidate)) {
            $accepted = true;
        }
        if ($accepted) {
            $real = realpath($candidate);

            return is_string($real) && $real !== '' ? $real : $candidate;
        }
    }

    throw new RuntimeException('php_cli_binary_unavailable');
}
