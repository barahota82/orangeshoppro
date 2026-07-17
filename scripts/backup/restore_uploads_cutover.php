<?php

declare(strict_types=1);

/**
 * Phase 3B.4D — CLI worker: production uploads cutover only.
 *
 * Usage:
 *   php scripts/backup/restore_uploads_cutover.php --job=JOB_ID
 *
 * Accepts --job= only. Never database import, rollback, maintenance release, or finalize.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$jobId = '';
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (!is_string($arg)) {
        continue;
    }
    if (str_starts_with($arg, '--job=')) {
        $jobId = trim(substr($arg, strlen('--job=')));
    } elseif (
        str_starts_with($arg, '--package=')
        || str_starts_with($arg, '--path=')
        || str_starts_with($arg, '--uploads=')
        || str_starts_with($arg, '--dir=')
    ) {
        fwrite(STDERR, "ERROR: arbitrary path/directory arguments are not allowed.\n");
        exit(2);
    }
}

if ($jobId === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $jobId)) {
    fwrite(STDERR, "Usage: php restore_uploads_cutover.php --job=JOB_ID\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_admin.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_uploads_cutover.php';

try {
    $ctx = orange_restore_admin_context($projectRoot);
    $workRoot = (string) ($ctx['work_root'] ?? '');
    $backupRoot = (string) ($ctx['backup_root'] ?? '');
    if ($workRoot === '' || $backupRoot === '') {
        fwrite(STDERR, "ERROR: restore work/backup root unavailable.\n");
        exit(1);
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);

    $result = orange_restore_uploads_cutover_run_cli([
        'project_root' => $projectRoot,
        'work_root' => $workRoot,
        'backup_root' => $backupRoot,
        'job_id' => $jobId,
        'owner' => 'cli',
    ]);

    $status = (string) ($result['status'] ?? '');
    $overall = !empty($result['ok']) ? 'PASS' : 'FAIL';

    echo 'UPLOADS_CUTOVER_RESULT: ' . $overall . PHP_EOL;
    echo 'JOB_ID: ' . (string) ($result['job_id'] ?? $jobId) . PHP_EOL;
    echo 'STATUS: ' . ($status !== '' ? $status : 'N/A') . PHP_EOL;
    echo 'DATABASE_IMPORT_PERFORMED: NO' . PHP_EOL;
    echo 'ROLLBACK_EXECUTED: NO' . PHP_EOL;
    echo 'MAINTENANCE_RELEASED: NO' . PHP_EOL;
    echo 'RESTORE_COMPLETED: NO' . PHP_EOL;
    echo 'EXECUTION_STARTED: NO' . PHP_EOL;
    echo 'WARNING: Maintenance remains active. Restore is NOT completed.' . PHP_EOL;

    if (!empty($result['code'])) {
        echo 'CODE: ' . (string) $result['code'] . PHP_EOL;
    }

    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $e) {
    $code = trim($e->getMessage()) ?: 'uploads_cutover_failed';
    fwrite(STDERR, 'ERROR: ' . $code . PHP_EOL);
    echo 'UPLOADS_CUTOVER_RESULT: FAIL' . PHP_EOL;
    echo 'JOB_ID: ' . $jobId . PHP_EOL;
    echo 'CODE: ' . $code . PHP_EOL;
    echo 'ROLLBACK_EXECUTED: NO' . PHP_EOL;
    echo 'MAINTENANCE_RELEASED: NO' . PHP_EOL;
    echo 'RESTORE_COMPLETED: NO' . PHP_EOL;
    exit(1);
}
