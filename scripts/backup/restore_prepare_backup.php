<?php

declare(strict_types=1);

/**
 * Phase 3B.3B3 — CLI worker for mandatory pre-restore Full backup (rollback anchor).
 *
 * Usage:
 *   php scripts/backup/restore_prepare_backup.php --job=JOB_ID
 *
 * Never accepts package/path arguments. Never restores DB/files. Never enables maintenance.
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
    fwrite(STDERR, "Usage: php restore_prepare_backup.php --job=JOB_ID\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_pre_restore_backup.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_admin.php';

try {
    $ctx = orange_restore_admin_context($projectRoot);
    $workRoot = (string) ($ctx['work_root'] ?? '');
    $backupRoot = (string) ($ctx['backup_root'] ?? '');
    if ($workRoot === '' || $backupRoot === '') {
        fwrite(STDERR, "ERROR: restore work/backup root unavailable.\n");
        exit(1);
    }

    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);

    $result = orange_restore_pre_backup_run_cli(
        $projectRoot,
        $workRoot,
        $backupRoot,
        $jobId,
        'cli'
    );

    if (!empty($result['ok'])) {
        echo 'PRE_RESTORE_BACKUP_RESULT: PASS' . PHP_EOL;
        echo 'JOB_ID: ' . (string) ($result['job_id'] ?? $jobId) . PHP_EOL;
        echo 'ROLLBACK_PACKAGE_ID: ' . (string) ($result['rollback_package_id'] ?? '') . PHP_EOL;
        echo 'VERIFY: ' . (string) ($result['verify'] ?? 'PASS') . PHP_EOL;
        echo 'DRV: ' . (string) ($result['drv'] ?? 'PASS') . PHP_EOL;
        echo 'RETENTION_PINNED: ' . (!empty($result['retention_pinned']) ? 'YES' : 'NO') . PHP_EOL;
        echo 'EXECUTION_STARTED: NO' . PHP_EOL;
        exit(0);
    }

    echo 'PRE_RESTORE_BACKUP_RESULT: FAIL' . PHP_EOL;
    echo 'JOB_ID: ' . (string) ($result['job_id'] ?? $jobId) . PHP_EOL;
    echo 'CODE: ' . (string) ($result['code'] ?? 'pre_restore_backup_failed') . PHP_EOL;
    echo 'ROLLBACK_PACKAGE_ID: ' . (string) ($result['rollback_package_id'] ?? '') . PHP_EOL;
    echo 'VERIFY: ' . (string) ($result['verify'] ?? 'FAIL') . PHP_EOL;
    echo 'DRV: ' . (string) ($result['drv'] ?? 'FAIL') . PHP_EOL;
    echo 'RETENTION_PINNED: ' . (!empty($result['retention_pinned']) ? 'YES' : 'NO') . PHP_EOL;
    echo 'EXECUTION_STARTED: NO' . PHP_EOL;
    exit(1);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    fwrite(STDERR, 'ERROR: ' . ($code !== '' ? $code : 'pre_restore_backup_failed') . PHP_EOL);
    echo 'PRE_RESTORE_BACKUP_RESULT: FAIL' . PHP_EOL;
    echo 'JOB_ID: ' . $jobId . PHP_EOL;
    echo 'CODE: ' . ($code !== '' ? $code : 'pre_restore_backup_failed') . PHP_EOL;
    echo 'EXECUTION_STARTED: NO' . PHP_EOL;
    exit(1);
}
