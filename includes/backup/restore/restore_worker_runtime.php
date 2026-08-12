<?php

declare(strict_types=1);

/**
 * Restore Center — internal-worker PHP CLI runtime resolver (Restore-only).
 *
 * Absolute-path contract for detached Restore workers (Step 7+). Never bare "php".
 * Never PATH/where/which. Never hardcoded Plesk install trees.
 * Separate from Backup Center (backup_admin.php) execution contract.
 *
 * Candidate order:
 *   1) ORANGE_PHP_CLI (absolute CLI)
 *   2) PHP_BINARY when already CLI
 *   3) trusted sibling (php-cgi → php, same directory)
 *   4) PHP_BINDIR/php(.exe)
 *   5) fail closed → php_cli_binary_unavailable / STEP7_PHP_CLI_UNAVAILABLE
 *
 * @see includes/backup/restore/restore_worker_php_cli.php (compat wrappers)
 */

require_once __DIR__ . '/../backup_environment.php';

/**
 * True when $path looks like an absolute filesystem executable path (not bare "php").
 */
function orange_restore_worker_runtime_path_is_absolute(string $path): bool
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
 * Runtime PHP_BINARY (test override via $GLOBALS['orange_backup_test_php_binary']).
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
 * Runtime PHP_BINDIR (test override via $GLOBALS['orange_backup_test_php_bindir']).
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
 * Join a non-absolute basename with an absolute bindir when possible.
 */
function orange_restore_worker_runtime_join_bindir(string $bindir, string $name): string
{
    $bindir = rtrim(str_replace('/', DIRECTORY_SEPARATOR, trim($bindir)), "\\/");
    $name = trim($name);
    if ($bindir === '' || $name === '' || !orange_restore_worker_runtime_path_is_absolute($bindir)) {
        return '';
    }

    return $bindir . DIRECTORY_SEPARATOR . basename(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $name));
}

/**
 * Normalize runtime PHP_BINARY to an absolute path when possible (BINDIR join).
 */
function orange_restore_worker_runtime_normalized_php_binary(): string
{
    $phpBinary = orange_restore_worker_runtime_php_binary();
    if ($phpBinary === '') {
        return '';
    }
    if (orange_restore_worker_runtime_path_is_absolute($phpBinary)) {
        return orange_backup_normalize_tool_path($phpBinary);
    }
    $joined = orange_restore_worker_runtime_join_bindir(orange_restore_worker_runtime_php_bindir(), $phpBinary);

    return $joined !== '' ? orange_backup_normalize_tool_path($joined) : $phpBinary;
}

/**
 * @return list<array{path:string,source:string}>
 */
function orange_restore_worker_runtime_cli_candidates(string $projectRoot): array
{
    $out = [];
    $push = static function (string $path, string $source) use (&$out): void {
        $path = trim($path);
        if ($path === '') {
            return;
        }
        $path = orange_backup_normalize_tool_path($path);
        if ($path === '') {
            return;
        }
        foreach ($out as $row) {
            if (strcasecmp($row['path'], $path) === 0) {
                return;
            }
        }
        $out[] = ['path' => $path, 'source' => $source];
    };

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
    if ($configured !== '') {
        $push($configured, 'orange_php_cli');
    }

    $phpBinary = orange_restore_worker_runtime_normalized_php_binary();
    if ($phpBinary !== '') {
        $push($phpBinary, 'php_binary');
        if (preg_match('/php-cgi(\.exe)?$/i', $phpBinary) === 1) {
            $sibling = preg_replace('/php-cgi(\.exe)?$/i', 'php$1', $phpBinary);
            if (is_string($sibling) && $sibling !== '') {
                $push($sibling, 'cgi_sibling');
            }
        }
        $binDir = dirname($phpBinary);
        if (orange_restore_worker_runtime_path_is_absolute($binDir)) {
            if (PHP_OS_FAMILY === 'Windows') {
                $push($binDir . DIRECTORY_SEPARATOR . 'php.exe', 'php_binary_dir');
            } else {
                $push($binDir . DIRECTORY_SEPARATOR . 'php', 'php_binary_dir');
            }
        }
    }

    $bindir = orange_restore_worker_runtime_php_bindir();
    if ($bindir !== '') {
        if (PHP_OS_FAMILY === 'Windows') {
            $push(rtrim($bindir, "\\/") . DIRECTORY_SEPARATOR . 'php.exe', 'php_bindir');
        } else {
            $push(rtrim($bindir, '/') . '/php', 'php_bindir');
        }
    }

    return $out;
}

/**
 * Layout trust: candidate is php(.exe) beside a proven absolute php-cgi(.exe) runtime binary.
 * Does not require is_file(candidate) — open_basedir on IIS/Plesk may hide sibling checks
 * while the app-pool can still execute the sibling via proc_open/Start-Process.
 */
function orange_restore_worker_runtime_is_cgi_sibling_layout(string $candidate, string $phpBinary): bool
{
    $phpBinary = trim($phpBinary);
    $candidate = trim($candidate);
    if ($phpBinary === '' || $candidate === '') {
        return false;
    }
    if (!orange_restore_worker_runtime_path_is_absolute($phpBinary)
        || preg_match('/php-cgi(\.exe)?$/i', $phpBinary) !== 1) {
        return false;
    }
    // Parent cgi must be observable as the running FastCGI binary when possible.
    if (!is_file($phpBinary) && !isset($GLOBALS['orange_backup_test_php_binary'])) {
        return false;
    }
    if (!orange_restore_worker_runtime_path_is_absolute($candidate)) {
        return false;
    }
    $base = basename(str_replace('\\', '/', $candidate));
    if (PHP_OS_FAMILY === 'Windows') {
        if (strcasecmp($base, 'php.exe') !== 0) {
            return false;
        }
    } elseif ($base !== 'php') {
        return false;
    }
    $binDir = strtolower(rtrim(str_replace('\\', '/', dirname($phpBinary)), '/'));
    $candDir = strtolower(rtrim(str_replace('\\', '/', dirname($candidate)), '/'));

    return $binDir !== '' && $binDir === $candDir;
}

/**
 * True when candidate is absolute php.exe from an allowlisted source directory.
 */
function orange_restore_worker_runtime_is_windows_php_exe_name(string $candidate): bool
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return false;
    }
    $candidate = trim($candidate);
    if ($candidate === '' || !orange_restore_worker_runtime_path_is_absolute($candidate)) {
        return false;
    }

    return strcasecmp(basename($candidate), 'php.exe') === 0;
}

/**
 * Safe SAPI probe — must return exactly "cli". Never throws.
 */
function orange_restore_worker_runtime_sapi_is_cli(string $phpBinary): bool
{
    $phpBinary = trim($phpBinary);
    if ($phpBinary === '' || preg_match('/php-cgi(\.exe)?$/i', $phpBinary) === 1) {
        return false;
    }
    if (!orange_restore_worker_runtime_path_is_absolute($phpBinary)) {
        return false;
    }

    $capture = orange_backup_run_command_capture([$phpBinary, '-r', 'echo PHP_SAPI;'], 20);
    if ((int) ($capture['exit_code'] ?? 1) !== 0) {
        return false;
    }

    return trim((string) ($capture['stdout'] ?? '')) === 'cli';
}

/**
 * Operator-safe resolve diagnostics (no raw filesystem paths).
 *
 * @return array<string, int|string>
 */
function orange_restore_worker_runtime_safe_diag(string $projectRoot): array
{
    $phpBinary = orange_restore_worker_runtime_normalized_php_binary();
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
    $layoutHits = 0;
    foreach (orange_restore_worker_runtime_cli_candidates($projectRoot) as $row) {
        $candidate = (string) ($row['path'] ?? '');
        if ($candidate === '') {
            continue;
        }
        $candidates++;
        if (!orange_restore_worker_runtime_path_is_absolute($candidate)) {
            continue;
        }
        $absoluteHits++;
        if (is_file($candidate)) {
            $fileHits++;
        }
        if (orange_restore_worker_runtime_is_windows_php_exe_name($candidate) && is_file($candidate)) {
            $trustedHits++;
        }
        if (orange_restore_worker_runtime_is_cgi_sibling_layout($candidate, $phpBinary)) {
            $layoutHits++;
        }
    }

    return [
        'php_binary_kind' => $kind,
        'php_binary_absolute' => orange_restore_worker_runtime_path_is_absolute($phpBinary) ? 1 : 0,
        'php_bindir_present' => $bindir !== '' ? 1 : 0,
        'orange_php_cli_configured' => $configured !== '' ? 1 : 0,
        'candidate_count' => $candidates,
        'candidate_absolute_count' => $absoluteHits,
        'candidate_file_count' => $fileHits,
        'candidate_trusted_php_exe_count' => $trustedHits,
        'candidate_cgi_sibling_layout_count' => $layoutHits,
        'execution_contract' => 'RESTORE_WORKER_RUNTIME_ABSOLUTE_CLI',
    ];
}

/**
 * Structured internal resolve result (never leaks absolute paths to Owner UI).
 *
 * @return array{
 *   ok:bool,
 *   php_binary:string,
 *   source:string,
 *   code:string,
 *   accepted_via:string,
 *   diag:array<string,int|string>
 * }
 */
function orange_restore_worker_runtime_resolve(string $projectRoot): array
{
    $diag = orange_restore_worker_runtime_safe_diag($projectRoot);
    $phpBinary = orange_restore_worker_runtime_normalized_php_binary();
    $forceFail = !empty($GLOBALS['orange_restore_worker_runtime_force_unavailable']);

    if ($forceFail) {
        return [
            'ok' => false,
            'php_binary' => '',
            'source' => '',
            'code' => 'php_cli_binary_unavailable',
            'accepted_via' => '',
            'diag' => $diag,
        ];
    }

    foreach (orange_restore_worker_runtime_cli_candidates($projectRoot) as $row) {
        $candidate = trim((string) ($row['path'] ?? ''));
        $source = (string) ($row['source'] ?? '');
        if ($candidate === '' || !orange_restore_worker_runtime_path_is_absolute($candidate)) {
            continue;
        }

        $fileOk = is_file($candidate);
        $cgiLayout = orange_restore_worker_runtime_is_cgi_sibling_layout($candidate, $phpBinary);
        $winPhpExe = orange_restore_worker_runtime_is_windows_php_exe_name($candidate);

        // Skip unknowns that are neither visible files nor trusted layout/BINDIR php.exe.
        if (!$fileOk && !$cgiLayout && !($winPhpExe && in_array($source, ['php_bindir', 'php_binary_dir', 'cgi_sibling', 'orange_php_cli'], true))) {
            continue;
        }

        $acceptedVia = '';
        if ($fileOk && orange_restore_worker_runtime_sapi_is_cli($candidate)) {
            $acceptedVia = 'sapi_probe';
        } elseif ($cgiLayout) {
            // Deterministic Plesk/IIS: sibling php beside proven php-cgi (probe often blocked).
            $acceptedVia = 'cgi_sibling_trust';
        } elseif ($fileOk && $winPhpExe) {
            $acceptedVia = 'windows_php_exe_trust';
        } elseif (!$fileOk && ($cgiLayout || ($winPhpExe && in_array($source, ['php_bindir', 'php_binary_dir', 'cgi_sibling'], true)))) {
            // open_basedir may hide is_file; still attempt a safe probe.
            if (orange_restore_worker_runtime_sapi_is_cli($candidate)) {
                $acceptedVia = 'sapi_probe_without_is_file';
            } elseif ($cgiLayout) {
                $acceptedVia = 'cgi_sibling_layout_trust';
            }
        }

        if ($acceptedVia === '') {
            continue;
        }

        $real = $fileOk ? realpath($candidate) : false;
        $resolved = is_string($real) && $real !== '' ? $real : $candidate;

        return [
            'ok' => true,
            'php_binary' => $resolved,
            'source' => $source,
            'code' => 'ok',
            'accepted_via' => $acceptedVia,
            'diag' => $diag,
        ];
    }

    return [
        'ok' => false,
        'php_binary' => '',
        'source' => '',
        'code' => 'php_cli_binary_unavailable',
        'accepted_via' => '',
        'diag' => $diag,
    ];
}

/**
 * Resolve absolute PHP CLI for Restore Center detached workers, or throw.
 */
function orange_restore_worker_runtime_resolve_cli_php_binary(string $projectRoot): string
{
    $result = orange_restore_worker_runtime_resolve($projectRoot);
    if (!empty($result['ok']) && is_string($result['php_binary'] ?? null) && $result['php_binary'] !== '') {
        return (string) $result['php_binary'];
    }

    throw new RuntimeException('php_cli_binary_unavailable');
}
