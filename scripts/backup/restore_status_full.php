<?php

declare(strict_types=1);

/**
 * Phase 2E — Read-only Full Restore status (no secrets).
 *
 * Usage:
 *   php scripts/backup/restore_status_full.php --job=JOB_ID
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$jobId = '';
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--job=')) {
        $jobId = trim(substr($arg, strlen('--job=')));
    }
}

if ($jobId === '') {
    fwrite(STDERR, "Usage: php restore_status_full.php --job=JOB_ID\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_orchestrator.php';

try {
    $report = orange_restore_orchestrator_e2e_status([
        'project_root' => $projectRoot,
        'job_id' => $jobId,
    ]);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
