<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_environment.php';
require_once __DIR__ . '/backup_runner.php';
require_once __DIR__ . '/backup_retention.php';
require_once __DIR__ . '/country_export.php';

const ORANGE_CRP_BATCH_LOCK_RELATIVE = 'locks/orange_crp_batch.lock';

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
        'customers',
        'suppliers',
        'purchases',
        'purchase_returns',
        'orders',
        'sales_returns',
        'journal_vouchers',
        'stock_movements',
        'inventory_cost_layers',
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
        $code = strtolower(trim((string) ($row['code'] ?? '')));
        $ar = trim((string) ($row['name_ar'] ?? ''));
        $en = trim((string) ($row['name_en'] ?? ''));
        $label = $en !== '' ? $en : $ar;
        $countries[] = [
            'id' => $id,
            'code' => $code !== '' ? $code : ('c' . $id),
            'label' => $label !== '' ? $label : $code,
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
        ];
    }

    return $countries;
}

function orange_crp_batch_country_has_fifo_consumptions(PDO $pdo, int $countryId): bool
{
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'catalog_schema.php';
    if (!orange_table_exists($pdo, 'inventory_cost_consumptions') || !orange_table_exists($pdo, 'inventory_cost_layers')) {
        return false;
    }

    $st = $pdo->prepare(
        'SELECT 1
         FROM inventory_cost_consumptions icc
         INNER JOIN inventory_cost_layers icl ON icl.id = icc.layer_id
         WHERE icl.country_id = ?
         LIMIT 1'
    );
    if ($st === false) {
        return false;
    }
    $st->execute([$countryId]);

    return $st->fetchColumn() !== false;
}

function orange_crp_batch_country_has_historical_data(PDO $pdo, int $countryId): bool
{
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'catalog_schema.php';

    foreach (orange_crp_batch_historical_data_tables() as $tableName) {
        if (!orange_table_exists($pdo, $tableName)) {
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

    return orange_crp_batch_country_has_fifo_consumptions($pdo, $countryId);
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
 *   started_at:string,
 *   finished_at:string,
 *   discovered:list<array<string,mixed>>,
 *   selected:list<array<string,mixed>>,
 *   skipped:list<array<string,mixed>>,
 *   succeeded:list<array<string,mixed>>,
 *   failed:list<array<string,mixed>>,
 *   retention:list<array<string,mixed>>
 * }
 */
function orange_crp_batch_export_all(PDO $pdo, string $projectRoot, array $options = []): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Country batch export is CLI-only.');
    }

    $startedAt = gmdate('c');
    $projectRoot = realpath($projectRoot) ?: $projectRoot;
    $env = orange_backup_load_env_array($projectRoot);
    $backupRoot = orange_backup_resolve_root($env, isset($options['backup_root']) ? (string) $options['backup_root'] : null);
    $retentionDays = orange_backup_retention_days($env);

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
    orange_backup_runner_log($logFile, 'started_at=' . $startedAt);
    orange_backup_runner_log($logFile, 'ProjectRoot=' . $projectRoot);
    orange_backup_runner_log($logFile, 'BackupRoot=' . $backupRoot);
    orange_backup_runner_log($logFile, 'retention_days=' . $retentionDays);

    $discovery = orange_crp_batch_discover_countries($pdo);
    $discovered = $discovery['discovered'];
    $selected = $discovery['selected'];
    $skipped = $discovery['skipped'];

    orange_backup_runner_log($logFile, 'Countries discovered=' . count($discovered));
    foreach ($discovered as $entry) {
        orange_backup_runner_log(
            $logFile,
            sprintf(
                'Discovered country id=%d code=%s active=%s',
                (int) ($entry['id'] ?? 0),
                (string) ($entry['code'] ?? ''),
                !empty($entry['is_active']) ? 'yes' : 'no'
            )
        );
    }

    orange_backup_runner_log($logFile, 'Countries selected=' . count($selected));
    foreach ($selected as $entry) {
        orange_backup_runner_log(
            $logFile,
            sprintf(
                'Selected country id=%d code=%s reason=%s',
                (int) ($entry['id'] ?? 0),
                (string) ($entry['code'] ?? ''),
                (string) ($entry['selection_reason'] ?? '')
            )
        );
    }

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
        $finishedAt = gmdate('c');
        orange_backup_runner_log($logFile, 'finished_at=' . $finishedAt);

        return [
            'ok' => false,
            'exit_code' => orange_crp_batch_compute_exit_code([], true),
            'message' => $lock['reason'],
            'log_file' => $logFile,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'discovered' => $discovered,
            'selected' => $selected,
            'skipped' => $skipped,
            'succeeded' => [],
            'failed' => [],
            'retention' => [],
        ];
    }

    $succeeded = [];
    $failed = [];
    $currentPackagesByCountry = [];
    $retentionSummary = [];

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
                if ($packagePath === '' || !is_dir($packagePath)) {
                    throw new RuntimeException('Country export did not produce a finalized package path.');
                }

                $verify = orange_country_export_verify_package($packagePath);
                if (!$verify['ok']) {
                    throw new RuntimeException('Country package verification failed: ' . implode('; ', $verify['errors']));
                }

                $packageName = basename($packagePath);
                $packageStatus = (string) (($result['manifest']['package_status'] ?? '') !== ''
                    ? $result['manifest']['package_status']
                    : ($verify['manifest']['package_status'] ?? 'healthy'));
                orange_backup_runner_log(
                    $logFile,
                    'Country export success id=' . $countryId
                    . ' code=' . $countryCode
                    . ' package=' . $packagePath
                    . ' package_status=' . $packageStatus
                );

                $currentPackagesByCountry[$countryCode] = $packageName;
                $succeeded[] = [
                    'id' => $countryId,
                    'code' => $countryCode,
                    'label' => (string) ($country['label'] ?? ''),
                    'package_path' => $packagePath,
                    'package_status' => $packageStatus,
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

        orange_backup_runner_log($logFile, 'Applying country package retention after batch exports.');
        $retentionResults = orange_backup_retention_apply_all_country_packages(
            $backupRoot,
            $currentPackagesByCountry,
            $retentionDays,
            static function (string $message, string $level = 'INFO') use ($logFile): void {
                orange_backup_runner_log($logFile, $message, $level);
            }
        );
        foreach ($retentionResults as $countryCode => $result) {
            $retentionSummary[] = [
                'country_code' => $countryCode,
                'kept' => count($result['kept'] ?? []),
                'deleted' => count($result['deleted'] ?? []),
                'errors' => $result['errors'] ?? [],
            ];
        }
    } finally {
        orange_crp_batch_release_lock();
    }

    $exitCode = orange_crp_batch_compute_exit_code($failed);
    $batchOk = $exitCode === 0;
    $summary = $batchOk
        ? 'Country package batch completed successfully.'
        : 'Country package batch completed with failures.';
    $finishedAt = gmdate('c');

    orange_backup_runner_log($logFile, 'Countries succeeded=' . count($succeeded));
    orange_backup_runner_log($logFile, 'Countries failed=' . count($failed));
    orange_backup_runner_log($logFile, $summary, $batchOk ? 'INFO' : 'ERROR');
    orange_backup_runner_log($logFile, 'finished_at=' . $finishedAt);

    return [
        'ok' => $batchOk,
        'exit_code' => $exitCode,
        'message' => $summary,
        'log_file' => $logFile,
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
        'discovered' => $discovered,
        'selected' => $selected,
        'skipped' => $skipped,
        'succeeded' => $succeeded,
        'failed' => $failed,
        'retention' => $retentionSummary,
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
    echo 'started_at=' . (string) ($result['started_at'] ?? '') . PHP_EOL;
    echo 'finished_at=' . (string) ($result['finished_at'] ?? '') . PHP_EOL;
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
            'success id=%d code=%s package_path=%s package_status=%s' . PHP_EOL,
            (int) ($entry['id'] ?? 0),
            (string) ($entry['code'] ?? ''),
            (string) ($entry['package_path'] ?? ''),
            (string) ($entry['package_status'] ?? '')
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

    foreach ($result['retention'] ?? [] as $entry) {
        echo sprintf(
            'retention country=%s kept=%d deleted=%d errors=%d' . PHP_EOL,
            (string) ($entry['country_code'] ?? ''),
            (int) ($entry['kept'] ?? 0),
            (int) ($entry['deleted'] ?? 0),
            count($entry['errors'] ?? [])
        );
    }

    echo 'message=' . (string) ($result['message'] ?? '') . PHP_EOL;
}
