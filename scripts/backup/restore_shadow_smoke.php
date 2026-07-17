<?php

declare(strict_types=1);

/**
 * Phase 3B.3B7 — CLI worker: isolated shadow end-to-end smoke + cutover readiness.
 *
 * Usage:
 *   php scripts/backup/restore_shadow_smoke.php --job=JOB_ID
 *
 * Never accepts package/path arguments. Never touches production. Never cutover.
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
    fwrite(STDERR, "Usage: php restore_shadow_smoke.php --job=JOB_ID\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_admin.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_shadow_smoke.php';

try {
    $ctx = orange_restore_admin_context($projectRoot);
    $workRoot = (string) ($ctx['work_root'] ?? '');
    $backupRoot = (string) ($ctx['backup_root'] ?? '');
    if ($workRoot === '' || $backupRoot === '') {
        fwrite(STDERR, "ERROR: restore work/backup root unavailable.\n");
        exit(1);
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);

    $result = orange_restore_shadow_smoke_run_cli($projectRoot, $workRoot, $backupRoot, $jobId, 'cli');

    $overall = strtoupper((string) ($result['result'] ?? 'FAIL'));
    $decision = (string) (($result['cutover_readiness']['status'] ?? '') ?: '');

    echo 'SHADOW_SMOKE_RESULT: ' . $overall . PHP_EOL;
    echo 'JOB_ID: ' . (string) ($result['job_id'] ?? $jobId) . PHP_EOL;
    echo 'READINESS_SCORE: ' . (string) (int) ($result['readiness_score'] ?? 0) . PHP_EOL;
    echo 'CUTOVER_READINESS: ' . ($decision !== '' ? $decision : 'N/A') . PHP_EOL;
    echo 'PRODUCTION_CUTOVER_ALLOWED: NO' . PHP_EOL;
    echo 'PRODUCTION_TOUCHED: NO' . PHP_EOL;
    echo 'EXECUTION_STARTED: NO' . PHP_EOL;
    echo 'MAINTENANCE: NO' . PHP_EOL;

    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $e) {
    $code = trim($e->getMessage()) ?: 'shadow_smoke_failed';
    fwrite(STDERR, 'ERROR: ' . $code . PHP_EOL);
    echo 'SHADOW_SMOKE_RESULT: FAIL' . PHP_EOL;
    echo 'JOB_ID: ' . $jobId . PHP_EOL;
    echo 'CODE: ' . $code . PHP_EOL;
    echo 'PRODUCTION_CUTOVER_ALLOWED: NO' . PHP_EOL;
    echo 'PRODUCTION_TOUCHED: NO' . PHP_EOL;
    echo 'EXECUTION_STARTED: NO' . PHP_EOL;
    exit(1);
}
