<?php

declare(strict_types=1);

/**
 * Phase 2D.4 — Manual production rollback CLI (job-scoped anchor only).
 *
 * Prerequisites: eligible job state; global restore lock held;
 * maintenance mode enabled and verified; Super Admin + backup_restore_full.
 *
 * Usage:
 *   printf "%s\n" "SECRET" | php scripts/backup/restore_full_rollback.php --job=JOB_ID --admin-id=N --password-stdin --confirm=ROLLBACK
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$jobId = '';
$adminId = 0;
$password = '';
$confirmation = '';
$passwordFromStdin = false;
$passwordEnvName = 'ORANGE_RESTORE_OPERATOR_PASSWORD';
$refusedArgPassword = false;

foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--job=')) {
        $jobId = trim(substr($arg, strlen('--job=')));
    } elseif (str_starts_with($arg, '--admin-id=')) {
        $adminId = (int) substr($arg, strlen('--admin-id='));
    } elseif (str_starts_with($arg, '--password=')) {
        $refusedArgPassword = true;
    } elseif ($arg === '--password-stdin') {
        $passwordFromStdin = true;
    } elseif (str_starts_with($arg, '--password-env=')) {
        $passwordEnvName = trim(substr($arg, strlen('--password-env=')));
    } elseif (str_starts_with($arg, '--confirm=')) {
        $confirmation = substr($arg, strlen('--confirm='));
    }
}

if ($refusedArgPassword) {
    fwrite(STDERR, "Refusing --password=SECRET because command-line arguments can leak via process lists and shell history. Use --password-stdin or --password-env=NAME.\n");
    exit(2);
}

if ($passwordFromStdin) {
    $password = trim((string) stream_get_contents(STDIN));
} elseif ($passwordEnvName !== '') {
    $envPassword = getenv($passwordEnvName);
    if (is_string($envPassword)) {
        $password = $envPassword;
    }
}

if ($jobId === '' || $adminId <= 0 || $password === '' || trim($confirmation) === '') {
    fwrite(STDERR, "Usage: php restore_full_rollback.php --job=JOB_ID --admin-id=N (--password-stdin | --password-env=NAME) --confirm=ROLLBACK\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_orchestrator.php';

try {
    $result = orange_restore_orchestrator_rollback([
        'project_root' => $projectRoot,
        'job_id' => $jobId,
        'admin_id' => $adminId,
        'password' => $password,
        'confirmation_phrase' => $confirmation,
    ]);

    echo 'ok=1' . PHP_EOL;
    echo 'job_id=' . (string) ($result['job_id'] ?? '') . PHP_EOL;
    echo 'status=' . (string) ($result['status'] ?? '') . PHP_EOL;
    echo 'rollback_executed=1' . PHP_EOL;
    echo 'automatic_rollback=0' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
