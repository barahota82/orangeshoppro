<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_environment.php';
require_once __DIR__ . '/backup_full.php';
require_once __DIR__ . '/backup_manifest.php';
require_once __DIR__ . '/backup_pdo_export.php';
require_once __DIR__ . '/backup_retention.php';

/**
 * @var resource|null
 */
$orangeBackupLockHandle = null;

function orange_backup_runner_log(string $logFile, string $message, string $level = 'INFO'): void
{
    $line = sprintf('[%s] [%s] %s', gmdate('Y-m-d H:i:s'), $level, $message);
    echo $line . PHP_EOL;
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function orange_backup_lock_path(string $backupRoot): string
{
    $locksDir = orange_backup_path_inside_root($backupRoot, 'locks');
    if (!is_dir($locksDir) && !@mkdir($locksDir, 0775, true) && !is_dir($locksDir)) {
        throw new RuntimeException('Cannot create locks directory under BackupRoot.');
    }

    return $locksDir . DIRECTORY_SEPARATOR . basename(ORANGE_BACKUP_LOCK_RELATIVE);
}

/**
 * @return array{acquired:bool,reason:string,path:string}
 */
function orange_backup_acquire_lock(string $backupRoot): array
{
    global $orangeBackupLockHandle;

    $path = orange_backup_lock_path($backupRoot);
    if (is_file($path)) {
        $raw = file_get_contents($path);
        $meta = is_string($raw) ? json_decode($raw, true) : null;
        $pid = is_array($meta) ? (int) ($meta['pid'] ?? 0) : 0;
        $startedAt = is_array($meta) ? (string) ($meta['started_at'] ?? '') : '';
        $ageSeconds = 0;
        if ($startedAt !== '') {
            $startedTs = strtotime($startedAt);
            if ($startedTs !== false) {
                $ageSeconds = time() - $startedTs;
            }
        } elseif (is_file($path)) {
            $mtime = filemtime($path);
            if ($mtime !== false) {
                $ageSeconds = time() - $mtime;
            }
        }

        $stale = $ageSeconds >= ORANGE_BACKUP_LOCK_STALE_SECONDS;
        $pidAlive = $pid > 0 && orange_backup_process_alive($pid);
        if (!$pidAlive || $stale) {
            @unlink($path);
        } else {
            return [
                'acquired' => false,
                'reason' => 'Another backup is already running (pid ' . $pid . ').',
                'path' => $path,
            ];
        }
    }

    $handle = @fopen($path, 'c+b');
    if ($handle === false) {
        throw new RuntimeException('Cannot open lock file: ' . $path);
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);

        return [
            'acquired' => false,
            'reason' => 'Another backup holds the lock file.',
            'path' => $path,
        ];
    }

    $payload = json_encode([
        'pid' => getmypid(),
        'started_at' => gmdate('c'),
        'hostname' => php_uname('n'),
        'sapi' => PHP_SAPI,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, $payload !== false ? $payload : '{}');
    fflush($handle);

    $orangeBackupLockHandle = $handle;

    return [
        'acquired' => true,
        'reason' => '',
        'path' => $path,
    ];
}

function orange_backup_release_lock(): void
{
    global $orangeBackupLockHandle;

    if (!is_resource($orangeBackupLockHandle)) {
        return;
    }

    $meta = stream_get_meta_data($orangeBackupLockHandle);
    $path = (string) ($meta['uri'] ?? '');
    flock($orangeBackupLockHandle, LOCK_UN);
    fclose($orangeBackupLockHandle);
    $orangeBackupLockHandle = null;
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
}

function orange_backup_clean_stale_workspaces(string $backupRoot, callable $logger): void
{
    $snapshotsDir = orange_backup_path_inside_root($backupRoot, 'snapshots');
    if (!is_dir($snapshotsDir)) {
        return;
    }

    $now = time();
    foreach (scandir($snapshotsDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!str_starts_with($entry, '._work_')) {
            continue;
        }
        $full = $snapshotsDir . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($full)) {
            continue;
        }
        $mtime = filemtime($full);
        if ($mtime !== false && ($now - $mtime) >= ORANGE_BACKUP_TEMP_STALE_SECONDS) {
            orange_backup_remove_dir($full);
            $logger('Removed stale temporary workspace: ' . $entry, 'WARN');
        }
    }
}

function orange_backup_gzip_file(string $sourceFile, string $destinationFile): void
{
    if (!orange_backup_has_gzip_support()) {
        throw new RuntimeException('gzip support is unavailable.');
    }
    $in = fopen($sourceFile, 'rb');
    if ($in === false) {
        throw new RuntimeException('Cannot read source file for gzip.');
    }
    $out = gzopen($destinationFile, 'wb9');
    if ($out === false) {
        fclose($in);
        throw new RuntimeException('Cannot open gzip destination file.');
    }
    while (!feof($in)) {
        $chunk = fread($in, 1024 * 512);
        if ($chunk === false) {
            break;
        }
        gzwrite($out, $chunk);
    }
    fclose($in);
    gzclose($out);
}

function orange_backup_zip_directory(string $sourceDir, string $zipPath): void
{
    if (!orange_backup_has_ziparchive_support()) {
        throw new RuntimeException('ZipArchive is unavailable.');
    }
    $resolved = realpath($sourceDir);
    if ($resolved === false || !is_dir($resolved)) {
        throw new RuntimeException('Uploads directory not found.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create uploads zip archive.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo) {
            continue;
        }
        $path = $fileInfo->getPathname();
        $rel = ltrim(str_replace('\\', '/', substr($path, strlen($resolved))), '/');
        if ($fileInfo->isDir()) {
            $zip->addEmptyDir($rel);
        } else {
            $zip->addFile($path, $rel);
        }
    }
    $zip->close();
}

function orange_backup_create_mysqldump_defaults_file(string $directory, string $host, string $user, string $password): string
{
    $file = $directory . DIRECTORY_SEPARATOR . 'mysqldump-client-' . bin2hex(random_bytes(8)) . '.cnf';
    $escapedPassword = str_replace(['\\', '"'], ['\\\\', '\\"'], $password);
    $content = "[client]\nhost={$host}\nuser={$user}\npassword=\"{$escapedPassword}\"\n";
    if (file_put_contents($file, $content) === false) {
        throw new RuntimeException('Cannot write temporary mysqldump defaults file.');
    }

    return $file;
}

function orange_backup_run_mysqldump(string $mysqldumpPath, string $defaultsFile, string $databaseName, string $outputSqlFile): void
{
    if (!orange_backup_can_proc_open()) {
        throw new RuntimeException('proc_open is required for mysqldump execution.');
    }

    $command = [
        $mysqldumpPath,
        '--defaults-extra-file=' . $defaultsFile,
        '--single-transaction',
        '--routines',
        '--triggers',
        '--events',
        '--hex-blob',
        '--default-character-set=utf8mb4',
        '--result-file=' . $outputSqlFile,
        $databaseName,
    ];
    $result = orange_backup_run_command_capture($command, null);
    if ($result['exit_code'] !== 0) {
        throw new RuntimeException('mysqldump failed: ' . trim($result['stderr'] . ' ' . $result['stdout']));
    }
    if (!is_file($outputSqlFile) || filesize($outputSqlFile) < 64) {
        throw new RuntimeException('mysqldump output is missing or too small.');
    }
}

/**
 * Canonical Full Backup package folder id (NEW packages only).
 * Owner 2026-07-23: UTC only — never PHP default TZ / OS / Country Context.
 * Format unchanged: Y-m-d_His. Historical local-named packages remain valid.
 */
function orange_backup_snapshot_name(): string
{
    return gmdate('Y-m-d_His');
}

function orange_backup_apply_retention(
    string $backupRoot,
    string $snapshotsDir,
    string $currentSnapshotName,
    int $retentionDaily = 7,
    int $retentionWeekly = 4,
    int $retentionMonthly = 6,
    ?callable $logger = null
): void {
    unset($retentionDaily, $retentionWeekly, $retentionMonthly);
    $env = orange_backup_load_env_array(orange_backup_project_root());
    $retentionResult = orange_backup_retention_apply_full_snapshots(
        $backupRoot,
        $snapshotsDir,
        $currentSnapshotName,
        orange_backup_retention_days($env),
        $logger
    );
    if (($retentionResult['errors'] ?? []) !== [] && $logger !== null) {
        foreach ($retentionResult['errors'] as $error) {
            $logger((string) $error, 'ERROR');
        }
    }
}

/**
 * @return array{ok:bool,backend:string,message:string,snapshot:?string}
 */
function orange_backup_run_via_powershell(string $projectRoot, string $backupRoot, string $logFile, ?array $env = null): array
{
    $env ??= orange_backup_load_env_array($projectRoot);
    $detected = orange_backup_detect_powershell($env);
    $psPath = $detected['path'];
    $psScript = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'orange_backup.ps1';
    if ($psPath === null || !is_file($psScript)) {
        return ['ok' => false, 'backend' => 'powershell', 'message' => 'PowerShell backend unavailable.', 'snapshot' => null];
    }

    orange_backup_runner_log($logFile, 'Delegating backup to PowerShell script.');
    $command = [
        $psPath,
        '-NoProfile',
        '-NonInteractive',
        '-ExecutionPolicy',
        'Bypass',
        '-File',
        $psScript,
        '-ProjectRoot',
        $projectRoot,
        '-BackupRoot',
        $backupRoot,
    ];
    $result = orange_backup_run_command_capture($command, null);
    $combined = trim($result['stdout'] . "\n" . $result['stderr']);
    if ($combined !== '') {
        orange_backup_runner_log($logFile, 'PowerShell output captured (credentials redacted if present).');
    }
    if ($result['exit_code'] !== 0) {
        return [
            'ok' => false,
            'backend' => 'powershell',
            'message' => 'PowerShell backup failed with exit code ' . $result['exit_code'] . '.',
            'snapshot' => null,
        ];
    }

    $snapshot = orange_backup_latest_snapshot_name($backupRoot);

    return [
        'ok' => true,
        'backend' => 'powershell',
        'message' => 'PowerShell backup completed successfully.',
        'snapshot' => $snapshot,
    ];
}

function orange_backup_latest_snapshot_name(string $backupRoot): ?string
{
    $snapshotsDir = orange_backup_path_inside_root($backupRoot, 'snapshots');
    if (!is_dir($snapshotsDir)) {
        return null;
    }
    $latest = null;
    $latestMtime = 0;
    foreach (scandir($snapshotsDir) ?: [] as $entry) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $entry)) {
            continue;
        }
        $full = $snapshotsDir . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($full)) {
            continue;
        }
        $mtime = filemtime($full) ?: 0;
        if ($mtime >= $latestMtime) {
            $latestMtime = $mtime;
            $latest = $entry;
        }
    }

    return $latest;
}

/**
 * @param callable(string):void $createRawSqlDump
 * @param list<string> $exporterWarnings
 * @param list<string> $exporterMaintenanceNotes
 * @return array{ok:bool,backend:string,message:string,snapshot:?string}
 */
function orange_backup_run_php_native_snapshot(
    string $projectRoot,
    string $backupRoot,
    string $logFile,
    ?array $env,
    string $backendName,
    string $backupEngineVersion,
    array $exporterWarnings,
    callable $createRawSqlDump,
    array $exporterMaintenanceNotes = []
): array {
    $env ??= orange_backup_load_env_array($projectRoot);

    if (!orange_backup_has_gzip_support() || !orange_backup_has_ziparchive_support()) {
        return ['ok' => false, 'backend' => $backendName, 'message' => 'gzip or ZipArchive support is unavailable.', 'snapshot' => null];
    }

    require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php';

    $db = orange_backup_load_db_settings($projectRoot);
    $uploadsDir = $projectRoot . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadsDir) || !is_readable($uploadsDir)) {
        throw new RuntimeException('uploads directory not found or not readable.');
    }

    $snapshotsDir = orange_backup_path_inside_root($backupRoot, 'snapshots');
    if (!is_dir($snapshotsDir) && !@mkdir($snapshotsDir, 0775, true) && !is_dir($snapshotsDir)) {
        throw new RuntimeException('Cannot create snapshots directory.');
    }

    $snapshotName = orange_backup_snapshot_name();
    $finalSnapshotDir = $snapshotsDir . DIRECTORY_SEPARATOR . $snapshotName;
    if (is_dir($finalSnapshotDir)) {
        throw new RuntimeException('Snapshot directory already exists: ' . $snapshotName);
    }

    $tempWorkDir = $snapshotsDir . DIRECTORY_SEPARATOR . '._work_' . $snapshotName;
    if (is_dir($tempWorkDir)) {
        orange_backup_remove_dir($tempWorkDir);
    }
    if (!@mkdir($tempWorkDir, 0775, true) && !is_dir($tempWorkDir)) {
        throw new RuntimeException('Cannot create temporary backup workspace.');
    }

    try {
        orange_backup_runner_log($logFile, 'Running PHP native backup backend: ' . $backendName);
        orange_backup_runner_log($logFile, 'Database target: host=' . $db['host'] . ' name=' . $db['name'] . ' user=' . $db['user']);

        $rawSqlFile = $tempWorkDir . DIRECTORY_SEPARATOR . $db['name'] . '.sql';
        $dumpFileName = $db['name'] . '.sql.gz';
        $compressedDumpFile = $tempWorkDir . DIRECTORY_SEPARATOR . $dumpFileName;
        $uploadsArchiveName = 'uploads.zip';
        $uploadsArchiveFile = $tempWorkDir . DIRECTORY_SEPARATOR . $uploadsArchiveName;

        $createRawSqlDump($rawSqlFile);

        if (!is_file($rawSqlFile) || filesize($rawSqlFile) < ORANGE_BACKUP_PDO_MIN_DUMP_BYTES) {
            throw new RuntimeException('Database SQL export is missing or too small.');
        }

        orange_backup_gzip_file($rawSqlFile, $compressedDumpFile);
        @unlink($rawSqlFile);
        orange_backup_runner_log($logFile, 'Compressed database dump -> ' . $dumpFileName);

        orange_backup_runner_log($logFile, 'Archiving uploads directory...');
        orange_backup_zip_directory($uploadsDir, $uploadsArchiveFile);
        if (!is_file($uploadsArchiveFile)) {
            throw new RuntimeException('Failed to create uploads archive.');
        }

        $pdo = db();
        orange_catalog_ensure_schema($pdo);
        $metadata = orange_backup_collect_safe_metadata($pdo, $projectRoot, $env);
        $result = orange_backup_full_finalize_workspace([
            'workspace' => $tempWorkDir,
            'backup_root' => $backupRoot,
            'dump_file' => $dumpFileName,
            'uploads_file' => $uploadsArchiveName,
            'metadata' => $metadata,
            'metadata_ok' => true,
            'env' => $env,
            'export_backend' => $backendName,
            'backup_engine_version' => $backupEngineVersion,
            'exporter_warnings' => $exporterWarnings,
            'exporter_maintenance_notes' => $exporterMaintenanceNotes,
        ]);
        if (($result['package_status'] ?? 'failed') === 'failed') {
            throw new RuntimeException('Package finalization failed.');
        }

        if (!@rename($tempWorkDir, $finalSnapshotDir)) {
            throw new RuntimeException('Cannot finalize snapshot directory rename.');
        }
        $tempWorkDir = '';

        $verifyFull = orange_backup_verify_full_package($finalSnapshotDir);
        if (!$verifyFull['ok']) {
            throw new RuntimeException('Post-finalize full backup verification failed: ' . implode('; ', $verifyFull['errors']));
        }

        orange_backup_apply_retention(
            $backupRoot,
            $snapshotsDir,
            $snapshotName,
            7,
            4,
            6,
            static function (string $message, string $level = 'INFO') use ($logFile): void {
                orange_backup_runner_log($logFile, $message, $level);
            }
        );
        orange_backup_runner_log($logFile, 'Backup snapshot ready: ' . $finalSnapshotDir);

        return [
            'ok' => true,
            'backend' => $backendName,
            'message' => 'PHP ' . $backendName . ' backup completed successfully.',
            'snapshot' => $snapshotName,
        ];
    } catch (Throwable $e) {
        if ($tempWorkDir !== '' && is_dir($tempWorkDir)) {
            orange_backup_remove_dir($tempWorkDir);
            orange_backup_runner_log($logFile, 'Removed incomplete temporary backup workspace.', 'WARN');
        }

        return [
            'ok' => false,
            'backend' => $backendName,
            'message' => $e->getMessage(),
            'snapshot' => null,
        ];
    }
}

/**
 * @return array{ok:bool,backend:string,message:string,snapshot:?string}
 */
function orange_backup_run_via_php_mysqldump(string $projectRoot, string $backupRoot, string $logFile, ?array $env = null): array
{
    $env ??= orange_backup_load_env_array($projectRoot);
    $detected = orange_backup_detect_mysqldump($env);
    $mysqldumpPath = $detected['path'];
    if ($mysqldumpPath === null || !orange_backup_can_proc_open()) {
        $reason = $detected['error'] ?? 'PHP mysqldump backend unavailable.';

        return ['ok' => false, 'backend' => 'php_mysqldump', 'message' => $reason, 'snapshot' => null];
    }

    $db = orange_backup_load_db_settings($projectRoot);

    return orange_backup_run_php_native_snapshot(
        $projectRoot,
        $backupRoot,
        $logFile,
        $env,
        'php_mysqldump',
        'mysqldump',
        [],
        static function (string $rawSqlFile) use ($projectRoot, $logFile, $mysqldumpPath, $db): void {
            $defaultsFile = orange_backup_create_mysqldump_defaults_file(dirname($rawSqlFile), $db['host'], $db['user'], $db['pass']);
            try {
                orange_backup_runner_log($logFile, 'Running mysqldump...');
                orange_backup_run_mysqldump($mysqldumpPath, $defaultsFile, $db['name'], $rawSqlFile);
            } finally {
                if (is_file($defaultsFile)) {
                    @unlink($defaultsFile);
                }
            }
        }
    );
}

/**
 * @return array{ok:bool,backend:string,message:string,snapshot:?string}
 */
function orange_backup_run_via_pdo(string $projectRoot, string $backupRoot, string $logFile, ?array $env = null): array
{
    $env ??= orange_backup_load_env_array($projectRoot);
    $detected = orange_backup_detect_mysqldump($env);
    if ($detected['path'] !== null) {
        return ['ok' => false, 'backend' => 'php_pdo', 'message' => 'PDO fallback skipped because mysqldump is available.', 'snapshot' => null];
    }

    require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php';
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $dbName = defined('DB_NAME') ? (string) DB_NAME : orange_backup_load_db_settings($projectRoot)['name'];
    $preflight = orange_backup_pdo_export_preflight($pdo, $dbName);
    if (!$preflight['ready']) {
        return ['ok' => false, 'backend' => 'php_pdo', 'message' => (string) ($preflight['error'] ?? 'PDO fallback unavailable.'), 'snapshot' => null];
    }

    return orange_backup_run_php_native_snapshot(
        $projectRoot,
        $backupRoot,
        $logFile,
        $env,
        'php_pdo',
        ORANGE_BACKUP_PDO_EXPORTER_VERSION,
        $preflight['warnings'],
        static function (string $rawSqlFile) use ($pdo, $dbName, $logFile): void {
            orange_backup_runner_log($logFile, 'Running PDO SQL export fallback...');
            orange_backup_pdo_export_database($pdo, $dbName, $rawSqlFile);
        },
        is_array($preflight['maintenance_notes'] ?? null) ? $preflight['maintenance_notes'] : []
    );
}
/**
 * @return array{ok:bool,exit_code:int,backend:?string,message:string,snapshot:?string,log_file:string}
 */
function orange_backup_run_full(string $projectRoot, ?string $backupRootOverride = null): array
{
    $projectRoot = realpath($projectRoot) ?: $projectRoot;
    // P0-1: block conflicting full backups while restore maintenance is active
    // (pre-restore backup runs before maintenance_active).
    require_once __DIR__ . '/restore/restore_maintenance_enforcement.php';
    orange_restore_maint_enforcement_library_mutation_guard('conflicting_backup_job');

    $env = orange_backup_load_env_array($projectRoot);
    $backupRoot = orange_backup_resolve_root($env, $backupRootOverride);

    $logsDir = orange_backup_path_inside_root($backupRoot, 'logs');
    if (!is_dir($logsDir) && !@mkdir($logsDir, 0775, true) && !is_dir($logsDir)) {
        throw new RuntimeException('Cannot create logs directory under BackupRoot.');
    }
    $logFile = $logsDir . DIRECTORY_SEPARATOR . 'run_full_backup_' . gmdate('Ymd_His') . '.log';

    orange_backup_runner_log($logFile, 'Orange full backup started.');
    orange_backup_runner_log($logFile, 'ProjectRoot=' . $projectRoot);
    orange_backup_runner_log($logFile, 'BackupRoot=' . $backupRoot);

    $lock = orange_backup_acquire_lock($backupRoot);
    if (!$lock['acquired']) {
        orange_backup_runner_log($logFile, $lock['reason'], 'ERROR');

        return [
            'ok' => false,
            'exit_code' => 2,
            'backend' => null,
            'message' => $lock['reason'],
            'snapshot' => null,
            'log_file' => $logFile,
        ];
    }

    try {
        orange_backup_clean_stale_workspaces($backupRoot, static function (string $message, string $level = 'INFO') use ($logFile): void {
            orange_backup_runner_log($logFile, $message, $level);
        });

        $report = orange_backup_collect_environment_report($projectRoot);
        $backend = $report['selected_backend'] ?? null;
        if ($backend === null) {
            $blockers = implode(' | ', $report['blockers'] ?? ['No backup backend available on this server.']);
            throw new RuntimeException($blockers);
        }

        orange_backup_runner_log($logFile, 'Selected backend=' . $backend);
        $env = orange_backup_load_env_array($projectRoot);
        $result = match ($backend) {
            'powershell' => orange_backup_run_via_powershell($projectRoot, $backupRoot, $logFile, $env),
            'php_mysqldump' => orange_backup_run_via_php_mysqldump($projectRoot, $backupRoot, $logFile, $env),
            'php_pdo' => orange_backup_run_via_pdo($projectRoot, $backupRoot, $logFile, $env),
            default => ['ok' => false, 'backend' => (string) $backend, 'message' => 'Unsupported backup backend.', 'snapshot' => null],
        };

        if (!$result['ok']) {
            orange_backup_runner_log($logFile, $result['message'], 'ERROR');

            return [
                'ok' => false,
                'exit_code' => 1,
                'backend' => $result['backend'],
                'message' => $result['message'],
                'snapshot' => null,
                'log_file' => $logFile,
            ];
        }

        orange_backup_runner_log($logFile, $result['message']);
        if ($result['snapshot'] !== null) {
            orange_backup_runner_log($logFile, 'Snapshot=' . $result['snapshot']);
        }
        orange_backup_runner_log($logFile, 'Orange full backup completed successfully.');

        return [
            'ok' => true,
            'exit_code' => 0,
            'backend' => $result['backend'],
            'message' => $result['message'],
            'snapshot' => $result['snapshot'],
            'log_file' => $logFile,
        ];
    } finally {
        orange_backup_release_lock();
    }
}
