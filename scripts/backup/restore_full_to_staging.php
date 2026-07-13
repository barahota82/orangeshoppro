<?php

declare(strict_types=1);

/**
 * Phase 2B.1 — Full disaster restore into STAGING only (CLI).
 *
 * Usage:
 *   php scripts/backup/restore_full_to_staging.php --package=PATH
 *
 * Never writes production DB or production uploads.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$packagePath = '';
$skipFreshBackup = false;
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--package=')) {
        $packagePath = trim(substr($arg, strlen('--package=')));
    }
    if ($arg === '--skip-fresh-backup') {
        $skipFreshBackup = true;
    }
}

if ($packagePath === '') {
    fwrite(STDERR, "Usage: php restore_full_to_staging.php --package=PATH [--skip-fresh-backup]\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_full_staging.php';

try {
    $result = orange_restore_full_staging_run([
        'project_root' => $projectRoot,
        'package_path' => $packagePath,
        'skip_fresh_backup' => $skipFreshBackup,
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
