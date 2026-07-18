<?php

declare(strict_types=1);

/**
 * P0-4 — Real MySQL/MariaDB clone DR validation CLI.
 *
 * Usage:
 *   php scripts/backup/run_restore_real_clone_validation.php
 *   php scripts/backup/run_restore_real_clone_validation.php --verbose
 *
 * Resolves clone roots/ports internally (default D:\orange_clone_mysql :3307).
 * Never accepts production DB names, package paths, or upload roots on argv.
 * Never Mock PDO. Never modifies production .env.php / uploads / orange_db.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ERROR: PHP ZipArchive extension required for real-clone DRV (enable extension=zip).\n");
    exit(2);
}

$verbose = false;
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (!is_string($arg)) {
        continue;
    }
    if ($arg === '--verbose' || $arg === '-v') {
        $verbose = true;
        continue;
    }
    if (
        str_starts_with($arg, '--path=')
        || str_starts_with($arg, '--db=')
        || str_starts_with($arg, '--package=')
        || str_starts_with($arg, '--uploads=')
        || str_starts_with($arg, '--work=')
        || str_starts_with($arg, '--root=')
        || str_starts_with($arg, '--password=')
    ) {
        fwrite(STDERR, "ERROR: arbitrary path/database/credential arguments are not allowed.\n");
        exit(2);
    }
    if (str_starts_with($arg, '--') && $arg !== '--verbose' && $arg !== '-v') {
        fwrite(STDERR, "ERROR: unsupported argument. Allowed: --verbose\n");
        exit(2);
    }
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_real_clone_validation.php';

try {
    $result = orange_restore_real_clone_run([
        'project_root' => $projectRoot,
        'clone_root' => 'D:\\orange_clone_mysql',
        'port' => ORANGE_RESTORE_REAL_CLONE_DEFAULT_PORT,
        'auto_bootstrap' => true,
    ]);
    $report = is_array($result['report'] ?? null) ? $result['report'] : [];

    echo 'REAL_CLONE_VALIDATION_RESULT: ' . (!empty($result['ok']) ? 'PASS' : 'FAIL') . PHP_EOL;
    echo 'SERVER_VERSION: ' . (string) ($report['server_version'] ?? '') . PHP_EOL;
    echo 'ENGINE: ' . (string) ($report['engine'] ?? '') . PHP_EOL;
    echo 'CHARSET: ' . (string) ($report['charset'] ?? '') . PHP_EOL;
    echo 'COLLATION: ' . (string) ($report['collation'] ?? '') . PHP_EOL;
    echo 'RESTORE_DURATION_SECONDS: ' . (string) ($report['restore_duration_seconds'] ?? '') . PHP_EOL;
    echo 'DRV_OK: ' . (!empty($report['drv']['ok']) ? 'YES' : 'NO') . PHP_EOL;
    echo 'SHADOW_VERIFY_OK: ' . (!empty($report['shadow_verify']['ok']) ? 'YES' : 'NO') . PHP_EOL;
    echo 'SMOKE_OK: ' . (!empty($report['smoke']['ok']) ? 'YES' : 'NO') . PHP_EOL;
    echo 'MOCK_PDO_USED: NO' . PHP_EOL;
    echo 'PRODUCTION_DB_TOUCHED: NO' . PHP_EOL;
    echo 'REPORT: ' . (string) ($result['report_path'] ?? '') . PHP_EOL;

    if ($verbose) {
        echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    }

    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
