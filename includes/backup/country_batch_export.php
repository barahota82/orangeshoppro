<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_environment.php';
require_once __DIR__ . '/backup_runner.php';
require_once __DIR__ . '/country_export.php';

const ORANGE_CRP_BATCH_LOCK_RELATIVE = 'locks/orange_crp_batch.lock';

const ORANGE_CRP_BATCH_ENV_RETENTION_DAILY = 'ORANGE_CRP_RETENTION_DAILY';
const ORANGE_CRP_BATCH_ENV_RETENTION_WEEKLY = 'ORANGE_CRP_RETENTION_WEEKLY';
const ORANGE_CRP_BATCH_ENV_RETENTION_MONTHLY = 'ORANGE_CRP_RETENTION_MONTHLY';

/** @var resource|null */
$orangeCrpBatchLockHandle = null;

/**
 * Country-scoped header tables used to detect recoverable inactive countries.
 *
 * @return list<string>
 */
function orange_crp_batch_historical_data_tables(): array
{
    return [
        'purchases',
        'purchase_returns',
        'orders',
        'sales_returns',
        'journal_vouchers',
        'stock_movements',
        'customers',
        'suppliers',
    ];
}

/**
 * @return array{daily:int,weekly:int,monthly:int}
 */
function orange_crp_batch_retention_config(array $env): array
{
    return [
        'daily' => max(1, (int) ($env[ORANGE_CRP_BATCH_ENV_RETENTION_DAILY] ?? 7)),
        'weekly' => max(1, (int) ($env[ORANGE_CRP_BATCH_ENV_RETENTION_WEEKLY] ?? 4)),
        'monthly' => max(1, (int) ($env[ORANGE_CRP_BATCH_ENV_RETENTION_MONTHLY] ?? 6)),
    ];
}

function orange_crp_batch_lock_path(string $backupRoot): string
{
    $locksDir = orange_backup_path_inside_root($backupRoot, 'locks');
    if (!is_dir($locksDir) && !@mkdir($locksDir, 0775, true) && !is_dir($locksDir)) {
        throw new RuntimeException('Cannot create locks directory under BackupRoot.');
    }

    return $locksDir . DIRECTORY_SEPARATOR . basename(ORANGE_CRP_BATCH_LOCK_RELATIVE);
}

/**
 * @return array{acquired:bool,reason:string,path:string}
 */
function orange_crp_batch_acquire_lock(string $backupRoot): array
{
    global $orangeCrpBatchLockHandle;

    $path = orange_crp_batch_lock_path($backupRoot);
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
                'reason' => 'Another country package batch is already running (pid ' . $pid . ').',
                'path' => $path,
            ];
        }
    }

    $handle = @fopen($path, 'c+b');
    if ($handle === false) {
        throw new RuntimeException('Cannot open CRP batch lock file: ' . $path);
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);

        return [
            'acquired' => false,
            'reason' => 'Another country package batch holds the lock file.',
            'path' => $path,
        ];
    }

    $payload = json_encode([
        'pid' => getmypid(),
        'started_at' => gmdate('c'),
        'hostname' => php_uname('n'),
        'sapi' => PHP_SAPI,
        'lock_type' => 'crp_batch',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, $payload !== false ? $payload : '{}');
    fflush($handle);

    $orangeCrpBatchLockHandle = $handle;

    return [
        'acquired' => true,
        'reason' => '',
        'path' => $path,
    ];
}

function orange_crp_batch_release_lock(): void
{
    global $orangeCrpBatchLockHandle;

    if (!is_resource($orangeCrpBatchLockHandle)) {
        return;
    }

    $meta = stream_get_meta_data($orangeCrpBatchLockHandle);
    $path = (string) ($meta['uri'] ?? '');
    flock($orangeCrpBatchLockHandle, LOCK_UN);
    fclose($orangeCrpBatchLockHandle);
    $orangeCrpBatchLockHandle = null;
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
}

/**
 * @return list<array{id:int,code:string,label:string,is_active:bool}>
 */
function orange_crp_batch_load_countries(PDO $pdo): array
{
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'catalog_schema.php';
    if (!orange_table_exists($pdo, 'countries')) {
        return [];
    }

    $st = $pdo->query(
        'SELECT id, code, name_ar, name_en, is_active
         FROM countries
         ORDER BY sort_order ASC, id ASC'
    );
    if ($st === false) {
        return [];
    }

    $countries = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $code = orange_country_export_safe_country_code((string) ($row['code'] ?? ''), $id);
        $ar = trim((string) ($row['name_ar'] ?? ''));
        $en = trim((string) ($row['name_en'] ?? ''));
        $label = $en !== '' ? $en : $ar;
        $countries[] = [
            'id' => $id,
            'code' => $code,
            'label' => $label !== '' ? $label : $code,
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
        ];
    }

    return $countries;
}

function orange_crp_batch_country_has_historical_data(PDO $pdo, int $countryId): bool
{
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'catalog_schema.php';

    foreach (orange_crp_batch_historical_data_tables() as $tableName) {
        if (!orange_table_exists($pdo, $tableName)) {
            continue;
        }
        if (!orange_table_has_column($pdo, $tableName, 'country_id')) {
            continue;
        }
        $st = $pdo->prepare('SELECT 1 FROM `' . str_replace('`', '``', $tableName) . '` WHERE country_id = ? LIMIT 1');
        if ($st === false) {
            continue;
        }
        $st->execute([$countryId]);
        if ($st->fetchColumn() !== false) {
            return true;
        }
    }

    return false;
}

/**
 * @param array{id:int,code:string,label:string,is_active:bool} $country
 * @return array{selected:bool,reason:string}
 */
function orange_crp_batch_classify_country(PDO $pdo, array $country): array
{
    if ($country['is_active']) {
        return ['selected' => true, 'reason' => 'active'];
    }
    if (orange_crp_batch_country_has_historical_data($pdo, $country['id'])) {
        return ['selected' => true, 'reason' => 'inactive_with_historical_data'];
    }

    return ['selected' => false, 'reason' => 'inactive_empty_template'];
}

/**
 * @return array{
 *   discovered:list<array{id:int,code:string,label:string,is_active:bool}>,
 *   selected:list<array{id:int,code:string,label:string,is_active:bool,selection_reason:string}>,
 *   skipped:list<array{id:int,code:string,label:string,is_active:bool,skip_reason:string}>
 * }
 */
function orange_crp_batch_discover_countries(PDO $pdo): array
{
    $discovered = orange_crp_batch_load_countries($pdo);
    $selected = [];
    $skipped = [];

    foreach ($discovered as $country) {
        $classification = orange_crp_batch_classify_country($pdo, $country);
        if ($classification['selected']) {
            $selected[] = array_merge($country, ['selection_reason' => $classification['reason']]);
        } else {
            $skipped[] = array_merge($country, ['skip_reason' => $classification['reason']]);
        }
    }

    return [
        'discovered' => $discovered,
        'selected' => $selected,
        'skipped' => $skipped,
    ];
}

function orange_crp_batch_package_is_healthy(string $packageDir): bool
{
    if (!is_dir($packageDir)) {
        return false;
    }
    $healthPath = $packageDir . DIRECTORY_SEPARATOR . 'health.json';
    if (!is_file($healthPath)) {
        return false;
    }
    $health = json_decode((string) file_get_contents($healthPath), true);
    if (!is_array($health)) {
        return false;
    }
    if (($health['package_status'] ?? '') !== 'healthy') {
        return false;
    }

    $verify = orange_country_export_verify_package($packageDir);

    return $verify['ok'];
}

/**
 * @return list<array{name:string,path:string,mtime:int}>
 */
function orange_crp_batch_list_package_dirs(string $countryPackagesDir): array
{
    if (!is_dir($countryPackagesDir)) {
        return [];
    }

    $dirs = [];
    foreach (scandir($countryPackagesDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $entry)) {
            continue;
        }
        $full = $countryPackagesDir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($full)) {
            $dirs[] = [
                'name' => $entry,
                'path' => $full,
                'mtime' => filemtime($full) ?: time(),
            ];
        }
    }

    usort($dirs, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

    return $dirs;
}

function orange_crp_batch_find_newest_healthy_package_name(string $countryPackagesDir): ?string
{
    foreach (orange_crp_batch_list_package_dirs($countryPackagesDir) as $dir) {
        if (orange_crp_batch_package_is_healthy($dir['path'])) {
            return $dir['name'];
        }
    }

    return null;
}

function orange_crp_batch_apply_retention(
    string $backupRoot,
    string $countryCode,
    ?string $currentPackageName,
    int $retentionDaily = 7,
    int $retentionWeekly = 4,
    int $retentionMonthly = 6
): void {
    $countryCode = orange_country_export_safe_country_code($countryCode);
    $countryPackagesDir = orange_backup_path_inside_root(
        $backupRoot,
        'country_packages' . DIRECTORY_SEPARATOR . $countryCode
    );
    $allDirs = orange_crp_batch_list_package_dirs($countryPackagesDir);
    if ($allDirs === []) {
        return;
    }

    $keep = [];
    if ($currentPackageName !== null && $currentPackageName !== '') {
        $keep[$currentPackageName] = true;
    }

    $newestHealthy = orange_crp_batch_find_newest_healthy_package_name($countryPackagesDir);
    if ($newestHealthy !== null) {
        $keep[$newestHealthy] = true;
    }

    $now = time();
    $dailyCutoff = strtotime('-' . max(1, $retentionDaily) . ' days', $now);
    if ($dailyCutoff !== false) {
        foreach ($allDirs as $dir) {
            if ($dir['mtime'] >= $dailyCutoff) {
                $keep[$dir['name']] = true;
            }
        }
    }

    for ($weekOffset = 0; $weekOffset < max(1, $retentionWeekly); $weekOffset++) {
        $weekStart = strtotime('-' . ($weekOffset * 7) . ' days midnight', $now);
        if ($weekStart === false) {
            continue;
        }
        $weekStart = strtotime('monday this week midnight', $weekStart) ?: $weekStart;
        $weekEnd = strtotime('+7 days', $weekStart) ?: ($weekStart + 604800);
        $newest = null;
        foreach ($allDirs as $dir) {
            if ($dir['mtime'] >= $weekStart && $dir['mtime'] < $weekEnd) {
                if ($newest === null || $dir['mtime'] > $newest['mtime']) {
                    $newest = $dir;
                }
            }
        }
        if ($newest !== null) {
            $keep[$newest['name']] = true;
        }
    }

    for ($monthOffset = 0; $monthOffset < max(1, $retentionMonthly); $monthOffset++) {
        $monthStart = strtotime('first day of -' . $monthOffset . ' month midnight', $now);
        $monthEnd = strtotime('first day of -' . ($monthOffset - 1) . ' month midnight', $now);
        if ($monthStart === false || $monthEnd === false) {
            continue;
        }
        $newest = null;
        foreach ($allDirs as $dir) {
            if ($dir['mtime'] >= $monthStart && $dir['mtime'] < $monthEnd) {
                if ($newest === null || $dir['mtime'] > $newest['mtime']) {
                    $newest = $dir;
                }
            }
        }
        if ($newest !== null) {
            $keep[$newest['name']] = true;
        }
    }

    $rootNorm = strtolower(rtrim(str_replace('\\', '/', realpath($backupRoot) ?: $backupRoot), '/'));
    foreach ($allDirs as $dir) {
        if (isset($keep[$dir['name']])) {
            continue;
        }
        $pathNorm = strtolower(str_replace('\\', '/', $dir['path']));
        if (!str_starts_with($pathNorm, $rootNorm . '/')) {
            throw new RuntimeException('CRP retention safety check failed.');
        }
        orange_backup_remove_dir($dir['path']);
    }
}

/**
 * @param list<array<string,mixed>> $failed
 */
function orange_crp_batch_compute_exit_code(array $failed, bool $lockFailed = false): int
{
    if ($lockFailed) {
        return 2;
    }
    if ($failed !== []) {
        return 1;
    }

    return 0;
}

/**
 * @param callable(PDO, array<string,mixed>): array{ok:bool,package_path:?string,message:string,manifest:?array<string,mixed>} $exportRunner
 * @return array{
 *   ok:bool,
 *   exit_code:int,
 *   message:string,
 *   log_file:string,
 *   discovered:list<array<string,mixed>>,
 *   selected:list<array<string,mixed>>,
 *   skipped:list<array<string,mixed>>,
 *   succeeded:list<array<string,mixed>>,
 *   failed:list<array<string,mixed>>
 * }
 */
function orange_crp_batch_export_all(PDO $pdo, string $projectRoot, array $options = []): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Country batch export is CLI-only.');
    }

    $projectRoot = realpath($projectRoot) ?: $projectRoot;
    $env = orange_backup_load_env_array($projectRoot);
    $backupRoot = orange_backup_resolve_root($env, isset($options['backup_root']) ? (string) $options['backup_root'] : null);

    $logsDir = orange_backup_path_inside_root($backupRoot, 'logs');
    if (!is_dir($logsDir) && !@mkdir($logsDir, 0775, true) && !is_dir($logsDir)) {
        throw new RuntimeException('Cannot create logs directory under BackupRoot.');
    }
    $logFile = $logsDir . DIRECTORY_SEPARATOR . 'export_all_countries_' . gmdate('Ymd_His') . '.log';

    /** @var callable(PDO, array<string,mixed>): array{ok:bool,package_path:?string,message:string,manifest:?array<string,mixed>} $exportRunner */
    $exportRunner = $options['export_runner'] ?? static function (PDO $innerPdo, array $exportOptions): array {
        return orange_country_export_run($innerPdo, $exportOptions);
    };

    orange_backup_runner_log($logFile, 'Orange country package batch export started.');
    orange_backup_runner_log($logFile, 'ProjectRoot=' . $projectRoot);
    orange_backup_runner_log($logFile, 'BackupRoot=' . $backupRoot);

    $discovery = orange_crp_batch_discover_countries($pdo);
    $discovered = $discovery['discovered'];
    $selected = $discovery['selected'];
    $skipped = $discovery['skipped'];

    orange_backup_runner_log($logFile, 'Countries discovered=' . count($discovered));
    orange_backup_runner_log($logFile, 'Countries selected=' . count($selected));
    orange_backup_runner_log($logFile, 'Countries skipped=' . count($skipped));

    foreach ($skipped as $entry) {
        orange_backup_runner_log(
            $logFile,
            sprintf(
                'Skipped country id=%d code=%s reason=%s',
                (int) ($entry['id'] ?? 0),
                (string) ($entry['code'] ?? ''),
                (string) ($entry['skip_reason'] ?? '')
            )
        );
    }

    $lock = orange_crp_batch_acquire_lock($backupRoot);
    if (!$lock['acquired']) {
        orange_backup_runner_log($logFile, $lock['reason'], 'ERROR');

        return [
            'ok' => false,
            'exit_code' => orange_crp_batch_compute_exit_code([], true),
            'message' => $lock['reason'],
            'log_file' => $logFile,
            'discovered' => $discovered,
            'selected' => $selected,
            'skipped' => $skipped,
            'succeeded' => [],
            'failed' => [],
        ];
    }

    $retention = orange_crp_batch_retention_config($env);
    $succeeded = [];
    $failed = [];

    try {
        foreach ($selected as $country) {
            $countryId = (int) ($country['id'] ?? 0);
            $countryCode = (string) ($country['code'] ?? '');
            orange_backup_runner_log(
                $logFile,
                sprintf('Country export start id=%d code=%s reason=%s', $countryId, $countryCode, (string) ($country['selection_reason'] ?? ''))
            );

            try {
                $result = $exportRunner($pdo, [
                    'country_id' => $countryId,
                    'project_root' => $projectRoot,
                    'backup_root' => $backupRoot,
                ]);
                if (!($result['ok'] ?? false)) {
                    throw new RuntimeException((string) ($result['message'] ?? 'Country export failed.'));
                }

                $packagePath = (string) ($result['package_path'] ?? '');
                $packageName = $packagePath !== '' ? basename($packagePath) : '';
                orange_backup_runner_log($logFile, 'Country export success id=' . $countryId . ' code=' . $countryCode . ' package=' . $packagePath);

                if ($packageName !== '') {
                    orange_crp_batch_apply_retention(
                        $backupRoot,
                        $countryCode,
                        $packageName,
                        $retention['daily'],
                        $retention['weekly'],
                        $retention['monthly']
                    );
                }

                $succeeded[] = [
                    'id' => $countryId,
                    'code' => $countryCode,
                    'label' => (string) ($country['label'] ?? ''),
                    'package_path' => $packagePath,
                    'selection_reason' => (string) ($country['selection_reason'] ?? ''),
                ];
            } catch (Throwable $e) {
                orange_backup_runner_log(
                    $logFile,
                    'Country export failed id=' . $countryId . ' code=' . $countryCode . ' error=' . $e->getMessage(),
                    'ERROR'
                );
                $failed[] = [
                    'id' => $countryId,
                    'code' => $countryCode,
                    'label' => (string) ($country['label'] ?? ''),
                    'error' => $e->getMessage(),
                    'selection_reason' => (string) ($country['selection_reason'] ?? ''),
                ];
            }
        }
    } finally {
        orange_crp_batch_release_lock();
    }

    $exitCode = orange_crp_batch_compute_exit_code($failed);
    $batchOk = $exitCode === 0;
    $summary = $batchOk
        ? 'Country package batch completed successfully.'
        : 'Country package batch completed with failures.';

    orange_backup_runner_log($logFile, 'Countries succeeded=' . count($succeeded));
    orange_backup_runner_log($logFile, 'Countries failed=' . count($failed));
    orange_backup_runner_log($logFile, $summary, $batchOk ? 'INFO' : 'ERROR');

    return [
        'ok' => $batchOk,
        'exit_code' => $exitCode,
        'message' => $summary,
        'log_file' => $logFile,
        'discovered' => $discovered,
        'selected' => $selected,
        'skipped' => $skipped,
        'succeeded' => $succeeded,
        'failed' => $failed,
    ];
}

/**
 * @param array<string,mixed> $result
 */
function orange_crp_batch_print_summary(array $result): void
{
    echo '=== Country Recovery Package Batch Summary ===' . PHP_EOL;
    echo 'batch_status=' . (($result['ok'] ?? false) ? 'success' : 'failed') . PHP_EOL;
    echo 'exit_code=' . (int) ($result['exit_code'] ?? 1) . PHP_EOL;
    echo 'log_file=' . (string) ($result['log_file'] ?? '') . PHP_EOL;
    echo 'countries_discovered=' . count($result['discovered'] ?? []) . PHP_EOL;
    echo 'countries_selected=' . count($result['selected'] ?? []) . PHP_EOL;
    echo 'countries_skipped=' . count($result['skipped'] ?? []) . PHP_EOL;
    echo 'countries_succeeded=' . count($result['succeeded'] ?? []) . PHP_EOL;
    echo 'countries_failed=' . count($result['failed'] ?? []) . PHP_EOL;

    foreach ($result['skipped'] ?? [] as $entry) {
        echo sprintf(
            'skipped id=%d code=%s reason=%s' . PHP_EOL,
            (int) ($entry['id'] ?? 0),
            (string) ($entry['code'] ?? ''),
            (string) ($entry['skip_reason'] ?? '')
        );
    }

    foreach ($result['succeeded'] ?? [] as $entry) {
        echo sprintf(
            'success id=%d code=%s package_path=%s' . PHP_EOL,
            (int) ($entry['id'] ?? 0),
            (string) ($entry['code'] ?? ''),
            (string) ($entry['package_path'] ?? '')
        );
    }

    foreach ($result['failed'] ?? [] as $entry) {
        echo sprintf(
            'failed id=%d code=%s error=%s' . PHP_EOL,
            (int) ($entry['id'] ?? 0),
            (string) ($entry['code'] ?? ''),
            (string) ($entry['error'] ?? '')
        );
    }

    echo 'message=' . (string) ($result['message'] ?? '') . PHP_EOL;
}
