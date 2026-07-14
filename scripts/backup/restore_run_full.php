<?php

declare(strict_types=1);

/**
 * Phase 2E — Start Full Restore (pre-approval workflow only).
 *
 * Usage:
 *   php scripts/backup/restore_run_full.php --package=PATH --admin-id=N --password=SECRET --confirm=RESTORE
 *
 * Stops at awaiting_owner_approval. No automatic approval or merge.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$packagePath = '';
$adminId = 0;
$password = '';
$confirmation = '';

foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--package=')) {
        $packagePath = trim(substr($arg, strlen('--package=')));
    } elseif (str_starts_with($arg, '--admin-id=')) {
        $adminId = (int) substr($arg, strlen('--admin-id='));
    } elseif (str_starts_with($arg, '--password=')) {
        $password = substr($arg, strlen('--password='));
    } elseif (str_starts_with($arg, '--confirm=')) {
        $confirmation = substr($arg, strlen('--confirm='));
    }
}

if ($packagePath === '' || $adminId <= 0 || $password === '' || trim($confirmation) === '') {
    fwrite(STDERR, "Usage: php restore_run_full.php --package=PATH --admin-id=N --password=SECRET --confirm=RESTORE\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_e2e_orchestrator.php';

try {
    $pdo = db();
    $result = orange_restore_e2e_start_full($pdo, [
        'project_root' => $projectRoot,
        'package_path' => $packagePath,
        'admin_id' => $adminId,
        'password' => $password,
        'confirmation_phrase' => $confirmation,
    ]);

    echo 'ok=1' . PHP_EOL;
    echo 'job_id=' . (string) ($result['job_id'] ?? '') . PHP_EOL;
    echo 'status=' . (string) ($result['status'] ?? '') . PHP_EOL;
    echo 'stopped=1' . PHP_EOL;
    echo 'next_action=' . (string) (($result['action']['action'] ?? '') ?: '') . PHP_EOL;
    echo 'instruction=' . (string) ($result['action']['instruction'] ?? '') . PHP_EOL;
    echo 'production_writes=0' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
