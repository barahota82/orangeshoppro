<?php

declare(strict_types=1);

/**
 * Phase 3B.3B6 — CLI worker: extract package files into shadow workspace only.
 *
 * Usage:
 *   php scripts/backup/restore_shadow_files.php --job=JOB_ID
 *
 * Never accepts package/path arguments. Never overwrites production. Never cutover/rename.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$jobId = '';
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--job=')) {
        $jobId = trim(substr($arg, strlen('--job=')));
    } elseif (str_starts_with($arg, '--package=') || str_starts_with($arg, '--path=')) {
        fwrite(STDERR, "ERROR: arbitrary package/path arguments are not allowed.\n");
        exit(2);
    }
}

if ($jobId === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $jobId)) {
    fwrite(STDERR, "Usage: php restore_shadow_files.php --job=JOB_ID\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_admin.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_shadow_files.php';

try {
    $ctx = orange_restore_admin_context($projectRoot);
    $workRoot = (string) ($ctx['work_root'] ?? '');
    $backupRoot = (string) ($ctx['backup_root'] ?? '');
    if ($workRoot === '' || $backupRoot === '') {
        fwrite(STDERR, "ERROR: restore work/backup root unavailable.\n");
        exit(1);
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);

    $result = orange_restore_shadow_files_run_cli($projectRoot, $workRoot, $backupRoot, $jobId, 'cli');

    if (!empty($result['ok'])) {
        echo 'SHADOW_FILES_RESULT: PASS' . PHP_EOL;
        echo 'JOB_ID: ' . (string) ($result['job_id'] ?? $jobId) . PHP_EOL;
        echo 'FILES_EXTRACTED: ' . (string) (int) ($result['files_extracted'] ?? 0) . PHP_EOL;
        echo 'PRODUCTION_TOUCHED: NO' . PHP_EOL;
        echo 'CUTOVER: NO' . PHP_EOL;
        echo 'RENAME: NO' . PHP_EOL;
        echo 'EXECUTION_STARTED: NO' . PHP_EOL;
        exit(0);
    }

    echo 'SHADOW_FILES_RESULT: FAIL' . PHP_EOL;
    echo 'JOB_ID: ' . (string) ($result['job_id'] ?? $jobId) . PHP_EOL;
    echo 'CODE: ' . (string) ($result['code'] ?? 'shadow_files_failed') . PHP_EOL;
    echo 'PRODUCTION_TOUCHED: NO' . PHP_EOL;
    echo 'EXECUTION_STARTED: NO' . PHP_EOL;
    exit(1);
} catch (Throwable $e) {
    $code = trim($e->getMessage()) ?: 'shadow_files_failed';
    fwrite(STDERR, 'ERROR: ' . $code . PHP_EOL);
    echo 'SHADOW_FILES_RESULT: FAIL' . PHP_EOL;
    echo 'JOB_ID: ' . $jobId . PHP_EOL;
    echo 'CODE: ' . $code . PHP_EOL;
    echo 'PRODUCTION_TOUCHED: NO' . PHP_EOL;
    echo 'EXECUTION_STARTED: NO' . PHP_EOL;
    exit(1);
}
