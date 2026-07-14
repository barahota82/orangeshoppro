<?php

declare(strict_types=1);

/**
 * Phase 2E — Resume Full Restore from persisted job state.
 *
 * Usage:
 *   php scripts/backup/restore_resume_full.php --job=JOB_ID [--admin-id=N] [--password=SECRET] [--confirm=PHRASE]
 *
 * Mutating resume steps require fresh Super Admin re-auth when applicable.
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

if ($jobId === '') {
    fwrite(STDERR, "Usage: php restore_resume_full.php --job=JOB_ID [--admin-id=N] [--password=SECRET] [--confirm=RESTORE|ROLLBACK]\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_e2e_orchestrator.php';

try {
    $pdo = db();
    $result = orange_restore_e2e_resume_full($pdo, [
        'project_root' => $projectRoot,
        'job_id' => $jobId,
        'admin_id' => $adminId,
        'password' => $password,
        'confirmation_phrase' => $confirmation,
    ]);

    echo 'ok=1' . PHP_EOL;
    echo 'job_id=' . (string) ($result['job_id'] ?? '') . PHP_EOL;
    echo 'status=' . (string) ($result['status'] ?? '') . PHP_EOL;
    echo 'stopped=' . (($result['stopped'] ?? false) ? '1' : '0') . PHP_EOL;
    echo 'terminal=' . (($result['terminal'] ?? false) ? '1' : '0') . PHP_EOL;
    echo 'next_action=' . (string) (($result['action']['action'] ?? '') ?: '') . PHP_EOL;
    echo 'instruction=' . (string) ($result['action']['instruction'] ?? '') . PHP_EOL;
    echo 'production_writes=' . (($result['production_writes'] ?? false) ? '1' : '0') . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
