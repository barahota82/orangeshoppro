<?php

declare(strict_types=1);

/**
 * Phase 2D.2 — Production database cutover CLI (no uploads cutover).
 *
 * Prerequisites: job in merge_precheck_passed, global restore lock held by this process,
 * maintenance mode enabled and verified for this job.
 *
 * Usage:
 *   printf "%s\n" "SECRET" | php scripts/backup/restore_full_database_cutover.php --job=JOB_ID --admin-id=N --password-stdin --confirm=RESTORE
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$jobId = '';
$adminId = 0;
$password = '';
$passwordEnv = '';
$passwordFromStdin = false;
$confirmation = '';

foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--job=')) {
        $jobId = trim(substr($arg, strlen('--job=')));
    } elseif (str_starts_with($arg, '--admin-id=')) {
        $adminId = (int) substr($arg, strlen('--admin-id='));
    } elseif ($arg === '--password-stdin') {
        $passwordFromStdin = true;
    } elseif (str_starts_with($arg, '--password-env=')) {
        $passwordEnv = trim(substr($arg, strlen('--password-env=')));
    } elseif (str_starts_with($arg, '--password=')) {
        fwrite(STDERR, "ERROR: --password=SECRET is not accepted. Use --password-stdin or --password-env=ENV_VAR.\n");
        exit(2);
    } elseif (str_starts_with($arg, '--confirm=')) {
        $confirmation = substr($arg, strlen('--confirm='));
    }
}

if ($passwordFromStdin && $passwordEnv !== '') {
    fwrite(STDERR, "ERROR: choose only one password source: --password-stdin or --password-env.\n");
    exit(2);
}
if ($passwordFromStdin) {
    $stdin = stream_get_contents(STDIN);
    $password = rtrim(is_string($stdin) ? $stdin : '', "\r\n");
} elseif ($passwordEnv !== '') {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $passwordEnv)) {
        fwrite(STDERR, "ERROR: invalid --password-env variable name.\n");
        exit(2);
    }
    $envPassword = getenv($passwordEnv);
    $password = is_string($envPassword) ? $envPassword : '';
}

if ($jobId === '' || $adminId <= 0 || $password === '' || trim($confirmation) === '') {
    fwrite(STDERR, "Usage: php restore_full_database_cutover.php --job=JOB_ID --admin-id=N (--password-stdin|--password-env=ENV_VAR) --confirm=RESTORE\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_orchestrator.php';

try {
    $result = orange_restore_orchestrator_database_cutover([
        'project_root' => $projectRoot,
        'job_id' => $jobId,
        'admin_id' => $adminId,
        'password' => $password,
        'confirmation_phrase' => $confirmation,
    ]);

    echo 'ok=1' . PHP_EOL;
    echo 'job_id=' . (string) ($result['job_id'] ?? '') . PHP_EOL;
    echo 'status=' . (string) ($result['status'] ?? '') . PHP_EOL;
    echo 'uploads_touched=0' . PHP_EOL;
    echo 'rollback_executed=0' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
