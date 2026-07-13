<?php

declare(strict_types=1);

/**
 * Phase 2B.2 — Country Recovery Package restore → staging (CLI).
 *
 * Usage:
 *   php scripts/backup/restore_country_to_staging.php --package=PATH
 *
 * PATH must point to country_packages/{country_code}/{timestamp}
 * Never writes production DB or production uploads.
 * Fresh backup rollback anchor is mandatory — no bypass flags.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$packagePath = '';
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--package=')) {
        $packagePath = trim(substr($arg, strlen('--package=')));
    }
    if ($arg === '--skip-fresh-backup') {
        fwrite(STDERR, "ERROR: --skip-fresh-backup is not supported (fresh backup anchor is mandatory).\n");
        exit(2);
    }
}

if ($packagePath === '') {
    fwrite(STDERR, "Usage: php restore_country_to_staging.php --package=PATH\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_country_staging.php';

try {
    $result = orange_restore_country_staging_run([
        'project_root' => $projectRoot,
        'package_path' => $packagePath,
    ]);
    echo 'ok=1' . PHP_EOL;
    echo 'job_id=' . (string) ($result['job_id'] ?? '') . PHP_EOL;
    echo 'report=' . (string) ($result['report_path'] ?? '') . PHP_EOL;
    echo 'staging_manifest=' . (string) ($result['staging_manifest_path'] ?? '') . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
