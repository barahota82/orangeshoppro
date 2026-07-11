<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_paths.php';

const ORANGE_BACKUP_LOCK_RELATIVE = 'locks/orange_full_backup.lock';
const ORANGE_BACKUP_LOCK_STALE_SECONDS = 21600;
const ORANGE_BACKUP_TEMP_STALE_SECONDS = 86400;

const ORANGE_BACKUP_ENV_ROOT = 'ORANGE_BACKUP_ROOT';
const ORANGE_BACKUP_ENV_MYSQLDUMP = 'ORANGE_MYSQLDUMP_PATH';
const ORANGE_BACKUP_ENV_POWERSHELL = 'ORANGE_BACKUP_POWERSHELL_PATH';

/**
 * @return array<string, mixed>
 */
function orange_backup_load_env_array(string $projectRoot): array
{
    $envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env.php';
    if (!is_file($envPath)) {
        return [];
    }
    $loaded = require $envPath;

    return is_array($loaded) ? $loaded : [];
}

function orange_backup_env_trimmed(array $env, string $key): string
{
    return trim((string) ($env[$key] ?? ''));
}

function orange_backup_root_configured(array $env): bool
{
    return orange_backup_env_trimmed($env, ORANGE_BACKUP_ENV_ROOT) !== '';
}

function orange_backup_default_root_candidate(string $projectRoot): string
{
    $drive = preg_match('/^([A-Za-z]:)/', $projectRoot, $m) ? $m[1] : '';
    if ($drive !== '') {
        return $drive . '\\orange_backups';
    }

    return dirname($projectRoot) . DIRECTORY_SEPARATOR . 'orange_backups';
}

function orange_backup_backup_root_candidate(array $env, string $projectRoot): string
{
    $configured = orange_backup_env_trimmed($env, ORANGE_BACKUP_ENV_ROOT);
    if ($configured !== '') {
        return $configured;
    }

    return orange_backup_default_root_candidate($projectRoot);
}

function orange_backup_open_basedir_report(): string
{
    $value = ini_get('open_basedir');

    return is_string($value) ? $value : '';
}

function orange_backup_function_usable(string $name): bool
{
    if (!function_exists($name)) {
        return false;
    }
    $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));

    return !in_array($name, $disabled, true);
}

function orange_backup_can_shell_exec(): bool
{
    return orange_backup_function_usable('shell_exec');
}

function orange_backup_can_proc_open(): bool
{
    return orange_backup_function_usable('proc_open');
}

function orange_backup_can_execute_commands(): bool
{
    return orange_backup_can_proc_open() || orange_backup_can_shell_exec();
}

function orange_backup_normalize_tool_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    $resolved = realpath($path);

    return $resolved !== false ? $resolved : $path;
}

/**
 * @return list<string>
 */
function orange_backup_powershell_known_paths(): array
{
    return [
        'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe',
        'C:\\Windows\\SysWOW64\\WindowsPowerShell\\v1.0\\powershell.exe',
    ];
}

/**
 * @return list<string>
 */
function orange_backup_mysqldump_known_paths(): array
{
    return [
        'C:\\Program Files\\MariaDB 11.4\\bin\\mysqldump.exe',
        'C:\\Program Files\\MariaDB 11.3\\bin\\mysqldump.exe',
        'C:\\Program Files\\MariaDB 10.11\\bin\\mysqldump.exe',
        'C:\\Program Files\\MariaDB 10.6\\bin\\mysqldump.exe',
        'C:\\Program Files\\MariaDB\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysqldump.exe',
        'C:\\Program Files (x86)\\Plesk\\MySQL\\bin\\mysqldump.exe',
        'C:\\Program Files (x86)\\Plesk\\Databases\\MySQL\\bin\\mysqldump.exe',
        'C:\\Program Files (x86)\\Parallels\\Plesk\\MySQL\\bin\\mysqldump.exe',
        'C:\\Program Files\\Parallels\\Plesk\\MySQL\\bin\\mysqldump.exe',
        'C:\\Program Files (x86)\\Parallels\\Plesk\\Databases\\MySQL\\bin\\mysqldump.exe',
    ];
}

function orange_backup_tool_path_exists(string $path): bool
{
    return $path !== '' && is_file($path);
}

function orange_backup_tool_probe_runnable(string $path, array $probeCommand): bool
{
    if (!orange_backup_can_proc_open()) {
        return false;
    }
    if (!orange_backup_tool_path_exists($path)) {
        return false;
    }

    try {
        $result = orange_backup_run_command_capture($probeCommand, 15);

        return $result['exit_code'] === 0;
    } catch (Throwable) {
        return false;
    }
}

function orange_backup_mysqldump_probe_runnable(string $path): bool
{
    return orange_backup_tool_probe_runnable($path, [$path, '--version']);
}

function orange_backup_powershell_probe_runnable(string $path): bool
{
    return orange_backup_tool_probe_runnable($path, [
        $path,
        '-NoProfile',
        '-NonInteractive',
        '-Command',
        'exit 0',
    ]);
}

/**
 * @return array{path:?string,source:string,error:?string}
 */
function orange_backup_detect_mysqldump(array $env): array
{
    $configured = orange_backup_env_trimmed($env, ORANGE_BACKUP_ENV_MYSQLDUMP);
    if ($configured !== '') {
        $path = orange_backup_normalize_tool_path($configured);
        if (!orange_backup_tool_path_exists($path)) {
            return [
                'path' => null,
                'source' => 'configured_invalid',
                'error' => ORANGE_BACKUP_ENV_MYSQLDUMP . ' is set but the file was not found.',
            ];
        }
        if (!orange_backup_can_proc_open()) {
            return [
                'path' => null,
                'source' => 'configured_not_executable',
                'error' => ORANGE_BACKUP_ENV_MYSQLDUMP . ' is set but proc_open is unavailable for mysqldump execution.',
            ];
        }
        if (!orange_backup_mysqldump_probe_runnable($path)) {
            return [
                'path' => null,
                'source' => 'configured_not_executable',
                'error' => ORANGE_BACKUP_ENV_MYSQLDUMP . ' is set but mysqldump could not be executed by PHP.',
            ];
        }

        return ['path' => $path, 'source' => 'configured', 'error' => null];
    }

    foreach (orange_backup_mysqldump_known_paths() as $candidate) {
        $path = orange_backup_normalize_tool_path($candidate);
        if (!orange_backup_tool_path_exists($path)) {
            continue;
        }
        if (!orange_backup_can_proc_open()) {
            return [
                'path' => null,
                'source' => 'known_path_requires_proc_open',
                'error' => 'mysqldump was found at a known path but proc_open is unavailable.',
            ];
        }
        if (orange_backup_mysqldump_probe_runnable($path)) {
            return ['path' => $path, 'source' => 'known_path', 'error' => null];
        }
    }

    if (orange_backup_can_execute_commands()) {
        $which = orange_backup_run_command_capture(['where.exe', 'mysqldump'], 15);
        if ($which['exit_code'] === 0) {
            $line = trim(explode("\n", str_replace("\r", '', $which['stdout'] ?? ''))[0] ?? '');
            if ($line !== '') {
                $path = orange_backup_normalize_tool_path($line);
                if (orange_backup_can_proc_open() && orange_backup_mysqldump_probe_runnable($path)) {
                    return ['path' => $path, 'source' => 'where', 'error' => null];
                }
            }
        }
    }

    if (!orange_backup_can_proc_open()) {
        return [
            'path' => null,
            'source' => 'none',
            'error' => 'mysqldump backend requires proc_open; it is disabled for this PHP profile.',
        ];
    }

    return [
        'path' => null,
        'source' => 'none',
        'error' => 'mysqldump was not found. Set ' . ORANGE_BACKUP_ENV_MYSQLDUMP . ' in .env.php.',
    ];
}

/**
 * @return array{path:?string,source:string,error:?string}
 */
function orange_backup_detect_powershell(array $env): array
{
    $configured = orange_backup_env_trimmed($env, ORANGE_BACKUP_ENV_POWERSHELL);
    if ($configured !== '') {
        $path = orange_backup_normalize_tool_path($configured);
        if (!orange_backup_tool_path_exists($path)) {
            return [
                'path' => null,
                'source' => 'configured_invalid',
                'error' => ORANGE_BACKUP_ENV_POWERSHELL . ' is set but the file was not found.',
            ];
        }
        if (!orange_backup_can_execute_commands()) {
            return [
                'path' => null,
                'source' => 'configured_not_executable',
                'error' => ORANGE_BACKUP_ENV_POWERSHELL . ' is set but process execution functions are unavailable.',
            ];
        }
        if (!orange_backup_powershell_probe_runnable($path)) {
            return [
                'path' => null,
                'source' => 'configured_not_executable',
                'error' => ORANGE_BACKUP_ENV_POWERSHELL . ' is set but PowerShell could not be executed by PHP.',
            ];
        }

        return ['path' => $path, 'source' => 'configured', 'error' => null];
    }

    foreach (orange_backup_powershell_known_paths() as $candidate) {
        $path = orange_backup_normalize_tool_path($candidate);
        if (!orange_backup_tool_path_exists($path)) {
            continue;
        }
        if (!orange_backup_can_execute_commands()) {
            continue;
        }
        if (orange_backup_powershell_probe_runnable($path)) {
            return ['path' => $path, 'source' => 'known_path', 'error' => null];
        }
    }

    if (orange_backup_can_execute_commands()) {
        foreach (['powershell.exe', 'pwsh.exe'] as $binary) {
            $which = orange_backup_run_command_capture(['where.exe', $binary], 15);
            if ($which['exit_code'] !== 0) {
                continue;
            }
            $line = trim(explode("\n", str_replace("\r", '', $which['stdout'] ?? ''))[0] ?? '');
            if ($line === '') {
                continue;
            }
            $path = orange_backup_normalize_tool_path($line);
            if (orange_backup_powershell_probe_runnable($path)) {
                return ['path' => $path, 'source' => 'where', 'error' => null];
            }
        }
    }

    return ['path' => null, 'source' => 'none', 'error' => null];
}

function orange_backup_find_mysqldump(?array $env = null): ?string
{
    $env ??= [];
    $detected = orange_backup_detect_mysqldump($env);

    return $detected['path'];
}

function orange_backup_find_powershell(?array $env = null): ?string
{
    $env ??= [];
    $detected = orange_backup_detect_powershell($env);

    return $detected['path'];
}

function orange_backup_has_gzip_support(): bool
{
    return function_exists('gzopen') && function_exists('gzwrite');
}

function orange_backup_has_ziparchive_support(): bool
{
    return class_exists('ZipArchive');
}

function orange_backup_debug_enabled(array $env): bool
{
    if (!array_key_exists('ORANGE_DEBUG', $env)) {
        return false;
    }
    $value = $env['ORANGE_DEBUG'];

    return $value === true
        || $value === 1
        || $value === '1'
        || (is_string($value) && strtolower($value) === 'true');
}

function orange_backup_cli_error_log_path(string $projectRoot, array $env): string
{
    try {
        if (orange_backup_root_configured($env)) {
            $root = orange_backup_resolve_root($env);
            $logsDir = orange_backup_path_inside_root($root, 'logs');
            if (!is_dir($logsDir)) {
                @mkdir($logsDir, 0775, true);
            }

            return $logsDir . DIRECTORY_SEPARATOR . 'backup_cli_errors.log';
        }
    } catch (Throwable) {
    }

    $fallbackDir = $projectRoot . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($fallbackDir)) {
        @mkdir($fallbackDir, 0775, true);
    }

    return $fallbackDir . DIRECTORY_SEPARATOR . 'backup_cli_errors.log';
}

function orange_backup_cli_render_error(Throwable $e, string $projectRoot, array $env, string $scriptLabel): void
{
    $detail = sprintf(
        "exception=%s\nmessage=%s\nfile=%s\nline=%d\nstack_trace=%s\n",
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    $logPath = orange_backup_cli_error_log_path($projectRoot, $env);
    @file_put_contents(
        $logPath,
        '[' . gmdate('c') . "] {$scriptLabel}\n{$detail}\n",
        FILE_APPEND | LOCK_EX
    );

    $stream = defined('STDERR') ? STDERR : fopen('php://stderr', 'wb');
    if ($stream === false) {
        return;
    }

    if (orange_backup_debug_enabled($env)) {
        fwrite($stream, $detail);
    } else {
        fwrite($stream, "Backup CLI error in {$scriptLabel}. See log: {$logPath}\n");
    }

    if (!defined('STDERR')) {
        fclose($stream);
    }
}

/**
 * @return array{host:string,name:string,user:string,pass:string}
 */
function orange_backup_load_db_settings(string $projectRoot): array
{
    $configPath = $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
    $envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('Missing config.php.');
    }
    if (!is_file($envPath)) {
        throw new RuntimeException('Missing .env.php.');
    }

    require_once $configPath;
    $env = orange_backup_load_env_array($projectRoot);

    $user = trim((string) ($env['DB_USER'] ?? ''));
    if ($user === '') {
        throw new RuntimeException('DB_USER is not configured.');
    }

    return [
        'host' => defined('DB_HOST') ? (string) DB_HOST : 'localhost',
        'name' => defined('DB_NAME') ? (string) DB_NAME : 'orange_db',
        'user' => $user,
        'pass' => (string) ($env['DB_PASS'] ?? ''),
    ];
}

/**
 * @param list<string> $command
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function orange_backup_run_command_capture(array $command, ?int $timeoutSeconds = null): array
{
    try {
        if (!orange_backup_can_proc_open()) {
            if (!orange_backup_can_shell_exec()) {
                return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'No command execution functions available.'];
            }
            $escaped = array_map(static fn (string $part): string => escapeshellarg($part), $command);
            $output = shell_exec(implode(' ', $escaped) . ' 2>&1');

            return [
                'exit_code' => 0,
                'stdout' => is_string($output) ? $output : '',
                'stderr' => '',
            ];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'proc_open failed.'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = time();
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ($timeoutSeconds !== null && (time() - $started) > $timeoutSeconds) {
                proc_terminate($process);
                $stderr .= 'Command timed out.';
                break;
            }
            usleep(100000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    } catch (Throwable $e) {
        return [
            'exit_code' => 127,
            'stdout' => '',
            'stderr' => $e->getMessage(),
        ];
    }
}

function orange_backup_process_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        if (!orange_backup_can_execute_commands()) {
            return true;
        }
        $result = orange_backup_run_command_capture(['tasklist', '/FI', 'PID eq ' . $pid, '/NH'], 15);
        $combined = strtolower($result['stdout'] . $result['stderr']);

        return str_contains($combined, (string) $pid) && !str_contains($combined, 'no tasks');
    }

    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }

    return true;
}

/**
 * @return array<string, mixed>
 */
function orange_backup_collect_environment_report(string $projectRoot): array
{
    $projectRoot = realpath($projectRoot) ?: $projectRoot;
    $env = orange_backup_load_env_array($projectRoot);
    $uploadsPath = $projectRoot . DIRECTORY_SEPARATOR . 'uploads';
    $rootConfigured = orange_backup_root_configured($env);
    $rootCandidate = orange_backup_backup_root_candidate($env, $projectRoot);

    $mysqldumpDetected = orange_backup_detect_mysqldump($env);
    $powershellDetected = orange_backup_detect_powershell($env);
    $mysqldumpPath = $mysqldumpDetected['path'];
    $powershellPath = $powershellDetected['path'];

    $psScript = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'orange_backup.ps1';

    $backupRoot = null;
    $backupRootWritable = false;
    $backupRootError = null;
    try {
        $backupRoot = orange_backup_resolve_root($env);
        $backupRootWritable = is_writable($backupRoot);
    } catch (Throwable $e) {
        $backupRootError = $e->getMessage();
    }

    if ($backupRoot === null && $backupRootError === null) {
        $backupRootError = 'BackupRoot could not be resolved.';
    }

    $databaseConnected = false;
    $schemaRevision = null;
    $databaseError = null;
    if (is_file($projectRoot . DIRECTORY_SEPARATOR . '.env.php')) {
        try {
            require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
            require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php';
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'backup_full.php';
            $pdo = db();
            orange_catalog_ensure_schema($pdo);
            $databaseConnected = true;
            $schemaRevision = orange_backup_schema_revision_live($pdo) ?: ORANGE_CATALOG_SCHEMA_PHP_REVISION;
        } catch (Throwable $e) {
            $databaseError = $e->getMessage();
        }
    }

    $powershellReady = $powershellPath !== null
        && is_file($psScript)
        && orange_backup_can_execute_commands();
    $phpReady = $mysqldumpPath !== null
        && orange_backup_has_gzip_support()
        && orange_backup_has_ziparchive_support()
        && orange_backup_can_proc_open();

    $selectedBackend = null;
    if ($powershellReady) {
        $selectedBackend = 'powershell';
    } elseif ($phpReady) {
        $selectedBackend = 'php_mysqldump';
    }

    $warnings = [];
    if (!$rootConfigured) {
        $warnings[] = ORANGE_BACKUP_ENV_ROOT . ' is not configured in .env.php; using candidate path (mandatory on Plesk production).';
    }

    $blockers = [];
    if (!$rootConfigured) {
        $blockers[] = ORANGE_BACKUP_ENV_ROOT . ' must be set explicitly in .env.php on Plesk production.';
    }
    if ($backupRoot === null || !$backupRootWritable) {
        $blockers[] = 'BackupRoot unavailable or not writable'
            . ($backupRootError ? (': ' . $backupRootError) : '');
    }
    if (!is_dir($uploadsPath) || !is_readable($uploadsPath)) {
        $blockers[] = 'uploads/ is missing or not readable';
    }
    if (!$databaseConnected) {
        $blockers[] = 'Database connectivity failed' . ($databaseError ? (': ' . $databaseError) : '');
    }
    if (!orange_backup_can_proc_open() && $selectedBackend !== 'powershell') {
        $blockers[] = 'proc_open is unavailable; PHP mysqldump backend cannot run.';
    }
    if ($mysqldumpDetected['error']) {
        $blockers[] = $mysqldumpDetected['error'];
    }
    if ($powershellDetected['error']) {
        $warnings[] = $powershellDetected['error'];
    }
    if ($selectedBackend === null) {
        $blockers[] = 'No executable backup backend is available (PowerShell optional; PHP mysqldump requires proc_open).';
    }
    if (!$orange_backup_has_gzip_support() || !orange_backup_has_ziparchive_support()) {
        $blockers[] = 'PHP gzip and ZipArchive extensions are required for full backup packaging.';
    }

    $blockers = array_values(array_unique($blockers));
    $warnings = array_values(array_unique($warnings));

    $canRun = $backupRoot !== null
        && $backupRootWritable
        && $rootConfigured
        && is_dir($uploadsPath)
        && is_readable($uploadsPath)
        && $databaseConnected
        && $selectedBackend !== null;

    return [
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'project_root' => $projectRoot,
        'orange_backup_root_configured' => $rootConfigured,
        'backup_root_candidate' => $rootCandidate,
        'backup_root' => $backupRoot,
        'backup_root_writable' => $backupRootWritable,
        'backup_root_error' => $backupRootError,
        'open_basedir' => orange_backup_open_basedir_report(),
        'proc_open_available' => orange_backup_can_proc_open(),
        'shell_exec_available' => orange_backup_can_shell_exec(),
        'configured_mysqldump_path_present' => orange_backup_env_trimmed($env, ORANGE_BACKUP_ENV_MYSQLDUMP) !== '',
        'configured_powershell_path_present' => orange_backup_env_trimmed($env, ORANGE_BACKUP_ENV_POWERSHELL) !== '',
        'mysqldump_detection_source' => $mysqldumpDetected['source'],
        'powershell_detection_source' => $powershellDetected['source'],
        'powershell_available' => $powershellPath !== null,
        'powershell_path' => $powershellPath,
        'powershell_script_present' => is_file($psScript),
        'powershell_ready' => $powershellReady,
        'mysqldump_available' => $mysqldumpPath !== null,
        'mysqldump_path' => $mysqldumpPath,
        'gzip_supported' => orange_backup_has_gzip_support(),
        'ziparchive_supported' => orange_backup_has_ziparchive_support(),
        'uploads_path' => $uploadsPath,
        'uploads_readable' => is_dir($uploadsPath) && is_readable($uploadsPath),
        'database_connected' => $databaseConnected,
        'schema_revision' => $schemaRevision,
        'database_error' => $databaseError,
        'selected_backend' => $selectedBackend,
        'can_run_full_backup' => $canRun,
        'warnings' => $warnings,
        'blockers' => $blockers,
    ];
}

function orange_backup_select_backend(string $projectRoot): ?string
{
    $report = orange_backup_collect_environment_report($projectRoot);

    return $report['selected_backend'] ?? null;
}
