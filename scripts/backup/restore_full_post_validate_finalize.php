<?php

declare(strict_types=1);

/**
 * Phase 2D.4 — Production post-validation finalize CLI (maintenance disable + completed).
 *
 * Prerequisites: job in post_validation_passed, maintenance_disable_pending,
 * maintenance_disabled, or completed (artifact reconciliation); global restore lock held.
 *
 * Usage:
 *   php scripts/backup/restore_full_post_validate_finalize.php --job=JOB_ID --admin-id=N --password=SECRET --confirm=RESTORE
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$jobId = '';
$adminId = 0;
$password = '';
$confirmation = '';

foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--job=')) {
        $jobId = trim(substr($arg, strlen('--job=')));
    } elseif (str_starts_with($arg, '--admin-id=')) {
        $adminId = (int) substr($arg, strlen('--admin-id='));
    } elseif (str_starts_with($arg, '--password=')) {
        $password = substr($arg, strlen('--password='));
    } elseif (str_starts_with($arg, '--confirm=')) {
        $confirmation = substr($arg, strlen('--confirm='));
    }
}

if ($jobId === '' || $adminId <= 0 || $password === '' || trim($confirmation) === '') {
    fwrite(STDERR, "Usage: php restore_full_post_validate_finalize.php --job=JOB_ID --admin-id=N --password=SECRET --confirm=RESTORE\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_orchestrator.php';

try {
    $result = orange_restore_orchestrator_post_validation_finalize([
        'project_root' => $projectRoot,
        'job_id' => $jobId,
        'admin_id' => $adminId,
        'password' => $password,
        'confirmation_phrase' => $confirmation,
    ]);

    echo 'ok=1' . PHP_EOL;
    echo 'job_id=' . (string) ($result['job_id'] ?? '') . PHP_EOL;
    echo 'status=' . (string) ($result['status'] ?? '') . PHP_EOL;
    echo 'reconciled=' . ((bool) ($result['reconciled'] ?? false) ? '1' : '0') . PHP_EOL;
    echo 'idempotent=' . ((bool) ($result['idempotent'] ?? false) ? '1' : '0') . PHP_EOL;
    echo 'database_writes=0' . PHP_EOL;
    echo 'rollback_executed=0' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
