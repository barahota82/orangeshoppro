<?php

declare(strict_types=1);

/**
 * Phase 3B.4C — CLI worker: production database import only.
 *
 * Usage:
 *   php scripts/backup/restore_import_production.php --job=JOB_ID
 *
 * Accepts --job= only. Never accepts paths, SQL files, database names, or shell fragments.
 * Never: file cutover, uploads rename, rollback, maintenance release, completion.
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
        || str_starts_with($arg, '--sql=')
        || str_starts_with($arg, '--db=')
        || str_starts_with($arg, '--database=')
    ) {
        fwrite(STDERR, "ERROR: arbitrary path/SQL/database arguments are not allowed.\n");
        exit(2);
    }
}

if ($jobId === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $jobId)) {
    fwrite(STDERR, "Usage: php restore_import_production.php --job=JOB_ID\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_admin.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_import.php';

try {
    $ctx = orange_restore_admin_context($projectRoot);
    $workRoot = (string) ($ctx['work_root'] ?? '');
    $backupRoot = (string) ($ctx['backup_root'] ?? '');
    if ($workRoot === '' || $backupRoot === '') {
        fwrite(STDERR, "ERROR: restore work/backup root unavailable.\n");
        exit(1);
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);

    $result = orange_restore_prod_import_run_cli([
        'project_root' => $projectRoot,
        'work_root' => $workRoot,
        'backup_root' => $backupRoot,
        'job_id' => $jobId,
        'owner' => 'cli',
    ]);

    $status = (string) ($result['status'] ?? '');
    $overall = !empty($result['ok']) ? 'PASS' : 'FAIL';

    echo 'PRODUCTION_IMPORT_RESULT: ' . $overall . PHP_EOL;
    echo 'JOB_ID: ' . (string) ($result['job_id'] ?? $jobId) . PHP_EOL;
    echo 'STATUS: ' . ($status !== '' ? $status : 'N/A') . PHP_EOL;
    echo 'FILES_SWITCHED: NO' . PHP_EOL;
    echo 'ROLLBACK_EXECUTED: NO' . PHP_EOL;
    echo 'MAINTENANCE_RELEASED: NO' . PHP_EOL;
    echo 'EXECUTION_STARTED: NO' . PHP_EOL;
    echo 'PRODUCTION_CUTOVER_ALLOWED: NO' . PHP_EOL;
    echo 'WARNING: Application files have NOT been switched.' . PHP_EOL;

    if (!empty($result['code'])) {
        echo 'CODE: ' . (string) $result['code'] . PHP_EOL;
    }

    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $e) {
    $code = trim($e->getMessage()) ?: 'production_import_failed';
    fwrite(STDERR, 'ERROR: ' . $code . PHP_EOL);
    echo 'PRODUCTION_IMPORT_RESULT: FAIL' . PHP_EOL;
    echo 'JOB_ID: ' . $jobId . PHP_EOL;
    echo 'CODE: ' . $code . PHP_EOL;
    echo 'FILES_SWITCHED: NO' . PHP_EOL;
    echo 'ROLLBACK_EXECUTED: NO' . PHP_EOL;
    echo 'MAINTENANCE_RELEASED: NO' . PHP_EOL;
    echo 'EXECUTION_STARTED: NO' . PHP_EOL;
    exit(1);
}
