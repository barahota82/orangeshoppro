<?php

declare(strict_types=1);

/**
 * Plesk scheduled-task entry point for full disaster backup.
 *
 * Usage:
 *   php scripts/backup/run_full_backup.php
 *
 * Plesk Scheduled Tasks → Run a PHP script → scripts/backup/run_full_backup.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_runner.php';

try {
    $result = orange_backup_run_full($projectRoot);
    echo json_encode([
        'ok' => $result['ok'],
        'backend' => $result['backend'],
        'snapshot' => $result['snapshot'],
        'log_file' => $result['log_file'],
        'message' => $result['message'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

    exit($result['exit_code']);
} catch (Throwable $e) {
    fwrite(STDERR, 'run_full_backup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
