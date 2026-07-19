<?php

declare(strict_types=1);

/**
 * Phase C6 — Country Shadow Restore CLI.
 *
 * Restores CRP into isolated Country Shadow DB only. Never production.
 *
 * Usage:
 *   php scripts/backup/restore_country_shadow.php --package=YYYY-MM-DD_HHMMSS
 *   php scripts/backup/restore_country_shadow.php --package=YYYY-MM-DD_HHMMSS --country=kw
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$packageId = '';
$countryCode = '';
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--package=')) {
        $packageId = trim(substr($arg, strlen('--package=')));
    } elseif (str_starts_with($arg, '--country=')) {
        $countryCode = trim(substr($arg, strlen('--country=')));
    } elseif (str_starts_with($arg, '--path=')) {
        fwrite(STDERR, "ERROR: arbitrary path arguments are not allowed.\n");
        exit(2);
    }
}

if ($packageId === '') {
    fwrite(STDERR, "Usage: php restore_country_shadow.php --package=YYYY-MM-DD_HHMMSS [--country=cc]\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_paths.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_country_shadow.php';

try {
    $env = orange_backup_load_env_array($projectRoot);
    $backupRoot = orange_backup_resolve_root($env);
    $workRoot = orange_restore_resolve_work_root($env);

    $result = orange_country_shadow_run([
        'project_root' => $projectRoot,
        'backup_root' => $backupRoot,
        'work_root' => $workRoot,
        'package_id' => $packageId,
        'country_code' => $countryCode,
        'env' => $env,
    ]);

    $status = strtoupper((string) ($result['status'] ?? 'failed'));
    echo 'COUNTRY_SHADOW_RESULT: ' . (!empty($result['ok']) ? 'PASS' : 'FAIL') . PHP_EOL;
    echo 'STATUS: ' . $status . PHP_EOL;
    echo 'RUN_ID: ' . (string) ($result['run_id'] ?? '') . PHP_EOL;
    echo 'SHADOW_DB: ' . (string) ($result['shadow_db'] ?? '') . PHP_EOL;
    echo 'PRODUCTION_TOUCHED: NO' . PHP_EOL;
    echo 'COUNTRY_PRODUCTION_RESTORE: DISABLED' . PHP_EOL;
    if (!empty($result['report_path'])) {
        echo 'REPORT: ' . basename((string) $result['report_path']) . PHP_EOL;
    }
    if (empty($result['ok'])) {
        echo 'CODE: ' . (string) ($result['code'] ?? 'failed') . PHP_EOL;
        exit(1);
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    echo 'COUNTRY_SHADOW_RESULT: FAIL' . PHP_EOL;
    echo 'PRODUCTION_TOUCHED: NO' . PHP_EOL;
    exit(1);
}
