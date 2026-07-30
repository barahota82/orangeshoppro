<?php

declare(strict_types=1);

/**
 * D5 isolated worker: Full Backup php_pdo against detached runtime only.
 * Args: runtime_root backup_root result_json_path
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$runtimeRoot = (string) ($argv[1] ?? '');
$backupRoot = (string) ($argv[2] ?? '');
$resultPath = (string) ($argv[3] ?? '');
if ($runtimeRoot === '' || $backupRoot === '' || $resultPath === '') {
    fwrite(STDERR, "Usage: worker runtime_root backup_root result.json\n");
    exit(2);
}

require_once $runtimeRoot . '/config.php';
require_once $runtimeRoot . '/includes/catalog_schema.php';
require_once $runtimeRoot . '/includes/backup/backup_runner.php';
require_once $runtimeRoot . '/includes/backup/backup_pdo_export.php';
require_once $runtimeRoot . '/includes/backup/backup_full.php';

$out = ['ok' => false, 'error' => 'unknown'];

try {
    $env = orange_backup_load_env_array($runtimeRoot);
    $logsDir = $backupRoot . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0775, true);
    }
    $logFile = $logsDir . DIRECTORY_SEPARATOR . 'd5_full_' . gmdate('Ymd_His') . '.log';

    $lock = orange_backup_acquire_lock($backupRoot);
    if (empty($lock['acquired'])) {
        throw new RuntimeException('backup lock: ' . (string) ($lock['reason'] ?? ''));
    }

    try {
        $pdo = db();
        // Schema already sealed at 124 in the D5 fixture; avoid migration catch-up that
        // fails on dump tables without `id` (same disposable limitation as D2/D4).
        $flagPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_d5_schema_ok_worker_' . getmypid() . '.flag';
        file_put_contents($flagPath, '124');
        putenv('ORANGE_SCHEMA_OK_FLAG_PATH=' . $flagPath);
        $_ENV['ORANGE_SCHEMA_OK_FLAG_PATH'] = $flagPath;
        $dbName = defined('DB_NAME') ? (string) DB_NAME : '';
        $preflight = orange_backup_pdo_export_preflight($pdo, $dbName);
        if (empty($preflight['ready'])) {
            throw new RuntimeException((string) ($preflight['error'] ?? 'pdo preflight failed'));
        }

        $result = orange_backup_run_php_native_snapshot(
            $runtimeRoot,
            $backupRoot,
            $logFile,
            $env,
            'php_pdo',
            defined('ORANGE_BACKUP_PDO_EXPORTER_VERSION') ? (string) ORANGE_BACKUP_PDO_EXPORTER_VERSION : 'pdo',
            is_array($preflight['warnings'] ?? null) ? $preflight['warnings'] : [],
            static function (string $rawSqlFile) use ($pdo, $dbName): void {
                orange_backup_pdo_export_database($pdo, $dbName, $rawSqlFile);
            },
            is_array($preflight['maintenance_notes'] ?? null) ? $preflight['maintenance_notes'] : []
        );

        if (empty($result['ok']) || empty($result['snapshot'])) {
            throw new RuntimeException((string) ($result['message'] ?? 'backup failed'));
        }

        $packagePath = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $result['snapshot'];
        $out = [
            'ok' => true,
            'backend' => 'php_pdo',
            'snapshot' => (string) $result['snapshot'],
            'package_path' => $packagePath,
            'message' => (string) ($result['message'] ?? 'ok'),
        ];
    } finally {
        orange_backup_release_lock();
    }
} catch (Throwable $e) {
    $out = ['ok' => false, 'error' => $e->getMessage(), 'backend' => 'php_pdo'];
}

file_put_contents($resultPath, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit(!empty($out['ok']) ? 0 : 1);
