<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_paths.php';

const ORANGE_BACKUP_LOCK_RELATIVE = 'locks/orange_full_backup.lock';
const ORANGE_BACKUP_LOCK_STALE_SECONDS = 21600;
const ORANGE_BACKUP_TEMP_STALE_SECONDS = 86400;

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

function orange_backup_can_exec(): bool
{
    return orange_backup_function_usable('exec');
}

function orange_backup_can_proc_open(): bool
{
    return orange_backup_function_usable('proc_open');
}

function orange_backup_can_execute_commands(): bool
{
    return orange_backup_can_proc_open() || orange_backup_can_exec();
}

/**
 * @return list<string>
 */
function orange_backup_powershell_candidates(): array
{
    return [
        'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe',
        'C:\\Windows\\SysWOW64\\WindowsPowerShell\\v1.0\\powershell.exe',
        'powershell.exe',
        'pwsh.exe',
    ];
}

function orange_backup_find_powershell(): ?string
{
    foreach (orange_backup_powershell_candidates() as $candidate) {
        if ($candidate === 'powershell.exe' || $candidate === 'pwsh.exe') {
            if (!orange_backup_can_execute_commands()) {
                continue;
            }
            $which = orange_backup_run_command_capture(['where.exe', $candidate], 10);
            if ($which['exit_code'] === 0) {
                $line = trim(explode("\n", str_replace("\r", '', $which['stdout'] ?? ''))[0] ?? '');
                if ($line !== '' && is_file($line)) {
                    return $line;
                }
            }
            continue;
        }
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @return list<string>
 */
function orange_backup_mysqldump_candidates(): array
{
    return [
        'mysqldump',
        'C:\\Program Files\\MariaDB 11.4\\bin\\mysqldump.exe',
        'C:\\Program Files\\MariaDB 11.3\\bin\\mysqldump.exe',
        'C:\\Program Files\\MariaDB 10.11\\bin\\mysqldump.exe',
        'C:\\Program Files\\MariaDB 10.6\\bin\\mysqldump.exe',
        'C:\\Program Files\\MariaDB\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysqldump.exe',
        'C:\\Program Files (x86)\\Plesk\\MySQL\\bin\\mysqldump.exe',
        'C:\\Program Files (x86)\\Plesk\\Databases\\MySQL\\bin\\mysqldump.exe',
    ];
}

function orange_backup_find_mysqldump(): ?string
{
    foreach (orange_backup_mysqldump_candidates() as $candidate) {
        if ($candidate === 'mysqldump') {
            if (!orange_backup_can_execute_commands()) {
                continue;
            }
            $which = orange_backup_run_command_capture(['where.exe', 'mysqldump'], 10);
            if ($which['exit_code'] === 0) {
                $line = trim(explode("\n", str_replace("\r", '', $which['stdout'] ?? ''))[0] ?? '');
                if ($line !== '' && is_file($line)) {
                    return $line;
                }
            }
            continue;
        }
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function orange_backup_has_gzip_support(): bool
{
    return function_exists('gzopen') && function_exists('gzwrite');
}

function orange_backup_has_ziparchive_support(): bool
{
    return class_exists(ZipArchive::class);
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
    if (!orange_backup_can_proc_open()) {
        if (!orange_backup_can_exec()) {
            return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'No command execution functions available.'];
        }
        $escaped = array_map(static fn (string $part): string => escapeshellarg($part), $command);
        $lines = [];
        $exitCode = 127;
        exec(implode(' ', $escaped) . ' 2>&1', $lines, $exitCode);

        return [
            'exit_code' => $exitCode,
            'stdout' => $lines !== [] ? implode("\n", $lines) . "\n" : '',
            'stderr' => '',
        ];
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($command, $descriptors, $pipes);
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
    $env = orange_backup_load_env_array($projectRoot);
    $uploadsPath = $projectRoot . DIRECTORY_SEPARATOR . 'uploads';
    $powershellPath = orange_backup_find_powershell();
    $mysqldumpPath = orange_backup_find_mysqldump();
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

    $databaseConnected = false;
    $schemaRevision = null;
    $databaseError = null;
    if (is_file($projectRoot . DIRECTORY_SEPARATOR . '.env.php')) {
        try {
            require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'backup_full.php';
            $catalogSchemaPath = $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php';
            if (is_file($catalogSchemaPath)) {
                require_once $catalogSchemaPath;
            }
            $pdo = db();
            $databaseConnected = true;
            $schemaRevision = orange_backup_schema_revision_live($pdo);
            if ($schemaRevision <= 0 && defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION')) {
                $schemaRevision = ORANGE_CATALOG_SCHEMA_PHP_REVISION;
            }
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
        $selectedBackend = 'php';
    }

    $canRun = $backupRoot !== null
        && $backupRootWritable
        && is_dir($uploadsPath)
        && is_readable($uploadsPath)
        && $databaseConnected
        && $selectedBackend !== null;

    $blockers = [];
    if ($backupRoot === null || !$backupRootWritable) {
        $blockers[] = 'BackupRoot unavailable or not writable' . ($backupRootError ? (': ' . $backupRootError) : '');
    }
    if (!is_dir($uploadsPath) || !is_readable($uploadsPath)) {
        $blockers[] = 'uploads/ is missing or not readable';
    }
    if (!$databaseConnected) {
        $blockers[] = 'Database connectivity failed' . ($databaseError ? (': ' . $databaseError) : '');
    }
    if ($selectedBackend === null) {
        $blockers[] = 'No backup backend available (PowerShell or PHP+mysqldump+gzip+ZipArchive+proc_open required)';
    }

    return [
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'project_root' => $projectRoot,
        'backup_root' => $backupRoot,
        'backup_root_writable' => $backupRootWritable,
        'backup_root_error' => $backupRootError,
        'powershell_available' => $powershellPath !== null,
        'powershell_path' => $powershellPath,
        'powershell_script_present' => is_file($psScript),
        'powershell_ready' => $powershellReady,
        'mysqldump_available' => $mysqldumpPath !== null,
        'mysqldump_path' => $mysqldumpPath,
        'gzip_supported' => orange_backup_has_gzip_support(),
        'ziparchive_supported' => orange_backup_has_ziparchive_support(),
        'proc_open_available' => orange_backup_can_proc_open(),
        'exec_available' => orange_backup_can_exec(),
        'shell_exec_available' => orange_backup_can_shell_exec(),
        'uploads_path' => $uploadsPath,
        'uploads_readable' => is_dir($uploadsPath) && is_readable($uploadsPath),
        'database_connected' => $databaseConnected,
        'schema_revision' => $schemaRevision,
        'database_error' => $databaseError,
        'selected_backend' => $selectedBackend,
        'can_run_full_backup' => $canRun,
        'blockers' => $blockers,
    ];
}

function orange_backup_select_backend(string $projectRoot): ?string
{
    $report = orange_backup_collect_environment_report($projectRoot);

    return $report['selected_backend'] ?? null;
}
