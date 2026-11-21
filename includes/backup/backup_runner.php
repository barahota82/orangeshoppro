<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_environment.php';
require_once __DIR__ . '/backup_full.php';
require_once __DIR__ . '/backup_manifest.php';
require_once __DIR__ . '/backup_pdo_export.php';
require_once __DIR__ . '/backup_retention.php';
require_once __DIR__ . '/backup_provenance.php';

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
/**
 * @param array{skip_retention?:bool} $runOptions
 */
/**
 * Claim a process-owned ._work_<snapshot> directory. Refuses if a peer already owns the name (BL-04).
 * Does not delete an existing directory. Does not load config/.env.
 *
 * @return array{snapshot_name:string,temp_work_dir:string,final_snapshot_dir:string}
 */
function orange_backup_claim_owned_work_dir(string $snapshotsDir, ?string $forcedSnapshotName = null): array
{
    if (!is_dir($snapshotsDir) && !@mkdir($snapshotsDir, 0775, true) && !is_dir($snapshotsDir)) {
        throw new RuntimeException('Cannot create snapshots directory.');
    }

    $snapshotName = $forcedSnapshotName !== null && $forcedSnapshotName !== ''
        ? $forcedSnapshotName
        : orange_backup_snapshot_name();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $snapshotName)) {
        throw new RuntimeException('Invalid snapshot name for work dir claim.');
    }

    $finalSnapshotDir = $snapshotsDir . DIRECTORY_SEPARATOR . $snapshotName;
    if (is_dir($finalSnapshotDir)) {
        throw new RuntimeException('Snapshot directory already exists: ' . $snapshotName);
    }

    $tempWorkDir = $snapshotsDir . DIRECTORY_SEPARATOR . '._work_' . $snapshotName;
    if (is_dir($tempWorkDir)) {
        throw new RuntimeException(
            'Temporary workspace already exists (refusing overwrite/delete): ' . basename($tempWorkDir)
        );
    }
    if (!@mkdir($tempWorkDir, 0775, true) && !is_dir($tempWorkDir)) {
        throw new RuntimeException('Cannot create temporary backup workspace.');
    }

    return [
        'snapshot_name' => $snapshotName,
        'temp_work_dir' => $tempWorkDir,
        'final_snapshot_dir' => $finalSnapshotDir,
    ];
}

function orange_backup_run_php_native_snapshot(
    string $projectRoot,
    string $backupRoot,
    string $logFile,
    ?array $env,
    string $backendName,
    string $backupEngineVersion,
    array $exporterWarnings,
    callable $createRawSqlDump,
    array $exporterMaintenanceNotes = [],
    array $runOptions = []
): array {
    $env ??= orange_backup_load_env_array($projectRoot);
    $skipRetention = !empty($runOptions['skip_retention']);

    if (!orange_backup_has_gzip_support() || !orange_backup_has_ziparchive_support()) {
        return ['ok' => false, 'backend' => $backendName, 'message' => 'gzip or ZipArchive support is unavailable.', 'snapshot' => null];
    }

    // BL-04: claim work dir before exporter/config so collision refuses without side effects on peers.
    $snapshotsDir = orange_backup_path_inside_root($backupRoot, 'snapshots');
    $forcedName = isset($runOptions['inject_forced_snapshot_name'])
        ? (string) $runOptions['inject_forced_snapshot_name']
        : null;
    $claimed = orange_backup_claim_owned_work_dir($snapshotsDir, $forcedName !== '' ? $forcedName : null);
    $snapshotName = $claimed['snapshot_name'];
    $tempWorkDir = $claimed['temp_work_dir'];
    $finalSnapshotDir = $claimed['final_snapshot_dir'];
    $ownedTempWorkDir = $tempWorkDir;
    $finalizedSnapshotId = null;

    try {
        // Declared test injects only (OR-CURSOR-022-R2 B3 evidence; no hosting/DB required).
        if (!empty($runOptions['inject_fail_before_finalize'])) {
            throw new RuntimeException('Injected export failure before finalize.');
        }

        if (!empty($runOptions['inject_synthetic_finalize'])) {
            orange_backup_runner_log($logFile, 'Running synthetic finalize inject (no live DB).');
            $dumpFileName = 'orange_db.sql.gz';
            $uploadsArchiveName = 'uploads.zip';
            $gz = gzopen($tempWorkDir . DIRECTORY_SEPARATOR . $dumpFileName, 'wb9');
            gzwrite($gz, "-- synthetic\n");
            gzclose($gz);
            $zip = new ZipArchive();
            $zip->open($tempWorkDir . DIRECTORY_SEPARATOR . $uploadsArchiveName, ZipArchive::CREATE);
            $zip->addFromString('keep.txt', 'x');
            $zip->close();
            file_put_contents(
                $tempWorkDir . DIRECTORY_SEPARATOR . 'manifest.json',
                json_encode([
                    'package_type' => 'full_disaster',
                    'schema_revision' => 125,
                    'dump_file' => $dumpFileName,
                    'uploads_file' => $uploadsArchiveName,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            file_put_contents(
                $tempWorkDir . DIRECTORY_SEPARATOR . 'health.json',
                json_encode(['package_status' => 'healthy', 'healthy' => true, 'schema_revision' => 125], JSON_UNESCAPED_UNICODE)
            );
        } else {
            require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
            require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php';

            $db = orange_backup_load_db_settings($projectRoot);
            $uploadsDir = $projectRoot . DIRECTORY_SEPARATOR . 'uploads';
            if (!is_dir($uploadsDir) || !is_readable($uploadsDir)) {
                throw new RuntimeException('uploads directory not found or not readable.');
            }

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
        }

        if (!@rename($tempWorkDir, $finalSnapshotDir)) {
            throw new RuntimeException('Cannot finalize snapshot directory rename.');
        }
        $tempWorkDir = '';
        $ownedTempWorkDir = '';
        $finalizedSnapshotId = $snapshotName;

        // Optional test inject: fail verify after rename while keeping finalized package.
        if (!empty($runOptions['inject_fail_after_finalize_verify']) || !empty($runOptions['inject_synthetic_finalize'])) {
            $verifyFull = [
                'ok' => false,
                'errors' => ['Injected post-finalize verify failure'],
                'overall_result' => 'fail',
            ];
        } else {
            $verifyFull = orange_backup_verify_full_package($finalSnapshotDir);
        }
        if (!$verifyFull['ok']) {
            orange_backup_runner_log(
                $logFile,
                'Post-finalize verify failed for snapshot=' . $snapshotName . '; package kept unprotected.',
                'ERROR'
            );

            return [
                'ok' => false,
                'backend' => $backendName,
                'message' => 'Post-finalize full backup verification failed: ' . implode('; ', $verifyFull['errors'] ?? ['verify_failed']),
                'snapshot' => $snapshotName,
                'package_created' => true,
                'failure_stage' => 'post_finalize_verify',
                'protection_status' => 'unprotected',
                'verification' => $verifyFull,
            ];
        }

        if ($skipRetention) {
            orange_backup_runner_log($logFile, 'Retention skipped for this run (skip_retention=1).');
        } else {
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
        }
        orange_backup_runner_log($logFile, 'Backup snapshot ready: ' . $finalSnapshotDir);

        return [
            'ok' => true,
            'backend' => $backendName,
            'message' => 'PHP ' . $backendName . ' backup completed successfully.',
            'snapshot' => $snapshotName,
            'package_created' => true,
        ];
    } catch (Throwable $e) {
        if ($ownedTempWorkDir !== '' && is_dir($ownedTempWorkDir)) {
            orange_backup_remove_dir($ownedTempWorkDir);
            orange_backup_runner_log($logFile, 'Removed incomplete temporary backup workspace owned by this run.', 'WARN');
        }

        if ($finalizedSnapshotId !== null && is_dir($finalSnapshotDir)) {
            return [
                'ok' => false,
                'backend' => $backendName,
                'message' => $e->getMessage(),
                'snapshot' => $finalizedSnapshotId,
                'package_created' => true,
                'failure_stage' => 'post_finalize',
                'protection_status' => 'unprotected',
            ];
        }

        return [
            'ok' => false,
            'backend' => $backendName,
            'message' => $e->getMessage(),
            'snapshot' => null,
            'package_created' => false,
            'failure_stage' => 'export',
            'protection_status' => 'not_started',
        ];
    }
}

/**
 * @return array{ok:bool,backend:string,message:string,snapshot:?string}
 */
/**
 * @param array{skip_retention?:bool} $runOptions
 */
function orange_backup_run_via_php_mysqldump(string $projectRoot, string $backupRoot, string $logFile, ?array $env = null, array $runOptions = []): array
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
        },
        [],
        $runOptions
    );
}

/**
 * @return array{ok:bool,backend:string,message:string,snapshot:?string}
 */
/**
 * @param array{skip_retention?:bool} $runOptions
 */
function orange_backup_run_via_pdo(string $projectRoot, string $backupRoot, string $logFile, ?array $env = null, array $runOptions = []): array
{
    $env ??= orange_backup_load_env_array($projectRoot);
    $detected = orange_backup_detect_mysqldump($env);
    if ($detected['path'] !== null) {
        return ['ok' => false, 'backend' => 'php_pdo', 'message' => 'PDO fallback skipped because mysqldump is available.', 'snapshot' => null];
    }

    require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php';
    $pdo = db();
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
        is_array($preflight['maintenance_notes'] ?? null) ? $preflight['maintenance_notes'] : [],
        $runOptions
    );
}
/**
 * @param array{
 *   skip_retention?:bool,
 *   skip_stale_workspace_cleanup?:bool,
 *   pin_baseline?:bool,
 *   round_id?:string,
 *   created_by?:string
 * } $options
 * @return array{ok:bool,exit_code:int,backend:?string,message:string,snapshot:?string,log_file:string,pin?:array<string,mixed>|null}
 */
function orange_backup_run_full(string $projectRoot, ?string $backupRootOverride = null, array $options = []): array
{
    $projectRoot = realpath($projectRoot) ?: $projectRoot;
    // P0-1: block conflicting full backups while restore maintenance is active
    // (pre-restore backup runs before maintenance_active).
    require_once __DIR__ . '/restore/restore_maintenance_enforcement.php';
    orange_restore_maint_enforcement_library_mutation_guard('conflicting_backup_job');

    require_once __DIR__ . DIRECTORY_SEPARATOR . 'backup_schema_contract.php';

    $skipRetention = !empty($options['skip_retention']);
    $skipStaleCleanup = !empty($options['skip_stale_workspace_cleanup']);
    $pinBaseline = !empty($options['pin_baseline']);
    $roundId = trim((string) ($options['round_id'] ?? ''));

    // BL-02 / B1: validate + impose safe baseline option set before any side effect.
    if ($pinBaseline) {
        try {
            orange_backup_validate_baseline_round_id($roundId);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'exit_code' => 2,
                'backend' => null,
                'message' => $e->getMessage(),
                'snapshot' => null,
                'log_file' => '',
                'pin' => null,
                'failure_stage' => 'baseline_options_validation',
                'protection_status' => 'not_started',
                'package_created' => false,
                'baseline_state' => 'NOT_CREATED',
            ];
        }
        // Impose safe set (shared contract for CLI/runner). Scheduled default has no pin_baseline.
        $skipRetention = true;
        $skipStaleCleanup = true;
    }
    $baselineMode = $pinBaseline || $skipRetention || $skipStaleCleanup;
    $runOptions = ['skip_retention' => $skipRetention];

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
    if ($pinBaseline) {
        orange_backup_runner_log($logFile, 'Baseline mode: imposed skip_retention=1 skip_stale_workspace_cleanup=1');
    }
    if ($skipRetention) {
        orange_backup_runner_log($logFile, 'Option skip_retention=1');
    }
    if ($skipStaleCleanup) {
        orange_backup_runner_log($logFile, 'Option skip_stale_workspace_cleanup=1');
    }

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
            'pin' => null,
            'failure_stage' => 'lock',
            'protection_status' => 'not_started',
            'package_created' => false,
            'baseline_state' => 'NOT_CREATED',
        ];
    }

    try {
        // B1: select/refuse unsupported baseline backend BEFORE stale cleanup or export.
        if (!empty($options['inject_selected_backend'])) {
            $backend = (string) $options['inject_selected_backend'];
            $report = ['selected_backend' => $backend, 'blockers' => []];
        } else {
            $report = orange_backup_collect_environment_report($projectRoot);
            $backend = $report['selected_backend'] ?? null;
        }
        if ($backend === null) {
            $blockers = implode(' | ', $report['blockers'] ?? ['No backup backend available on this server.']);
            throw new RuntimeException($blockers);
        }

        if ($baselineMode && !in_array((string) $backend, ['php_pdo', 'php_mysqldump'], true)) {
            $msg = 'Baseline/skip_retention refused for backend=' . (string) $backend
                . ' (supported: php_pdo, php_mysqldump). No export/cleanup performed.';
            orange_backup_runner_log($logFile, $msg, 'ERROR');

            return [
                'ok' => false,
                'exit_code' => 3,
                'backend' => (string) $backend,
                'message' => $msg,
                'snapshot' => null,
                'log_file' => $logFile,
                'pin' => null,
                'failure_stage' => 'backend_not_supported_for_baseline',
                'protection_status' => 'not_started',
                'package_created' => false,
                'baseline_state' => 'NOT_CREATED',
            ];
        }

        if (!$skipStaleCleanup) {
            orange_backup_clean_stale_workspaces($backupRoot, static function (string $message, string $level = 'INFO') use ($logFile): void {
                orange_backup_runner_log($logFile, $message, $level);
            });
        } else {
            orange_backup_runner_log($logFile, 'Stale ._work_ cleanup skipped for this run.');
        }

        orange_backup_runner_log($logFile, 'Selected backend=' . $backend);
        $env = orange_backup_load_env_array($projectRoot);
        $result = match ($backend) {
            'powershell' => orange_backup_run_via_powershell($projectRoot, $backupRoot, $logFile, $env),
            'php_mysqldump' => orange_backup_run_via_php_mysqldump($projectRoot, $backupRoot, $logFile, $env, $runOptions),
            'php_pdo' => orange_backup_run_via_pdo($projectRoot, $backupRoot, $logFile, $env, $runOptions),
            default => ['ok' => false, 'backend' => (string) $backend, 'message' => 'Unsupported backup backend.', 'snapshot' => null],
        };

        if (!$result['ok']) {
            orange_backup_runner_log($logFile, $result['message'], 'ERROR');
            $ensured = orange_backup_provenance_ensure_cli_execution($backupRoot, 'full');
            if (!empty($ensured['execution_id'])) {
                orange_backup_provenance_finish_execution($backupRoot, (string) $ensured['execution_id'], [
                    'overall_status' => 'failed',
                    'completed_at_utc' => gmdate('c'),
                    'error_summary' => substr((string) ($result['message'] ?? 'full_backup_failed'), 0, 240),
                ]);
            }

            // B3: preserve finalized snapshot identity when package exists but is not qualified.
            $created = !empty($result['package_created']);
            $snap = isset($result['snapshot']) ? (string) $result['snapshot'] : '';
            if ($snap !== '' && preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $snap)) {
                $created = true;
            } else {
                $snap = '';
            }
            $stage = (string) ($result['failure_stage'] ?? ($created ? 'post_finalize_verify' : 'export'));
            $out = [
                'ok' => false,
                'exit_code' => 1,
                'backend' => $result['backend'],
                'message' => $result['message'],
                'snapshot' => $snap !== '' ? $snap : null,
                'log_file' => $logFile,
                'pin' => null,
                'failure_stage' => $stage,
                'protection_status' => $created ? 'unprotected' : 'not_started',
                'package_created' => $created,
                'verification' => $result['verification'] ?? null,
            ];
            $out['baseline_state'] = orange_backup_baseline_state_from_result($out);

            return $out;
        }

        orange_backup_runner_log($logFile, $result['message']);
        $snapshotId = isset($result['snapshot']) ? (string) $result['snapshot'] : '';
        // BL-03: backend ok without owned snapshot identity is not baseline success.
        if ($snapshotId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $snapshotId)) {
            return [
                'ok' => false,
                'exit_code' => 1,
                'backend' => $result['backend'],
                'message' => 'Backup backend reported success without a process-owned snapshot identity.',
                'snapshot' => $snapshotId !== '' ? $snapshotId : null,
                'log_file' => $logFile,
                'pin' => null,
                'failure_stage' => 'snapshot_identity',
                'protection_status' => 'unprotected',
            ];
        }

        $snapshotPath = orange_backup_path_inside_root($backupRoot, 'snapshots/' . $snapshotId);
        if (!is_dir($snapshotPath)) {
            return [
                'ok' => false,
                'exit_code' => 1,
                'backend' => $result['backend'],
                'message' => 'Snapshot identity not found on disk: ' . $snapshotId,
                'snapshot' => $snapshotId,
                'log_file' => $logFile,
                'pin' => null,
                'failure_stage' => 'snapshot_identity',
                'protection_status' => 'unprotected',
            ];
        }

        orange_backup_runner_log($logFile, 'Snapshot=' . $snapshotId);
        $manifest = orange_backup_provenance_read_json_file(
            $snapshotPath . DIRECTORY_SEPARATOR . ORANGE_BACKUP_MANIFEST_FILE
        );
        $prov = orange_backup_provenance_after_full_success(
            $backupRoot,
            $snapshotId,
            $snapshotPath,
            (int) ($manifest['schema_revision'] ?? 0)
        );
        if (empty($prov['ok'])) {
            orange_backup_runner_log(
                $logFile,
                'Provenance sidecar warning: ' . (string) ($prov['warning'] ?? 'unavailable'),
                'WARN'
            );
        }

        $pinInfo = null;
        if ($pinBaseline) {
            try {
                $jobId = orange_backup_baseline_framework_job_id($roundId);
                $pinResult = orange_backup_retention_pin_package($backupRoot, $snapshotId, [
                    'framework_job_id' => $jobId,
                    'reason' => ORANGE_BACKUP_RETENTION_PIN_REASON_TEST_BASELINE,
                    'purpose' => ORANGE_BACKUP_RETENTION_PIN_REASON_TEST_BASELINE,
                    'created_by' => (string) ($options['created_by'] ?? 'or_cursor_022_baseline'),
                    'identity_tag' => $roundId,
                ]);
                // Re-read from store (not memory alone).
                $public = orange_backup_retention_pin_public($backupRoot, $snapshotId);
                if ($public === null || empty($public['retention_pinned'])) {
                    throw new RuntimeException('Pin write did not persist for snapshot ' . $snapshotId);
                }
                $pinInfo = $public;
                orange_backup_runner_log(
                    $logFile,
                    'Retention pin applied reason=test_round_baseline pin_id=' . (string) ($public['pin_id'] ?? '')
                );
            } catch (Throwable $pinEx) {
                // Keep snapshot; do not auto-delete or recreate. Package create may have succeeded.
                orange_backup_runner_log($logFile, 'Pin failure: ' . $pinEx->getMessage(), 'ERROR');

                $pinFail = [
                    'ok' => false,
                    'exit_code' => 4,
                    'backend' => $result['backend'],
                    'message' => 'Package created but protection failed: ' . $pinEx->getMessage(),
                    'snapshot' => $snapshotId,
                    'log_file' => $logFile,
                    'pin' => null,
                    'failure_stage' => 'pin',
                    'protection_status' => 'unprotected',
                    'package_created' => true,
                ];
                $pinFail['baseline_state'] = orange_backup_baseline_state_from_result($pinFail);

                return $pinFail;
            }
        }

        orange_backup_runner_log($logFile, 'Orange full backup completed successfully.');

        $okOut = [
            'ok' => true,
            'exit_code' => 0,
            'backend' => $result['backend'],
            'message' => $result['message'],
            'snapshot' => $snapshotId,
            'log_file' => $logFile,
            'pin' => $pinInfo,
            'failure_stage' => null,
            'protection_status' => $pinBaseline ? 'pinned' : 'not_requested',
            'package_created' => true,
            'round_id' => $pinBaseline ? $roundId : null,
        ];
        $okOut['baseline_state'] = orange_backup_baseline_state_from_result($okOut);

        return $okOut;
    } finally {
        orange_backup_release_lock();
    }
}
