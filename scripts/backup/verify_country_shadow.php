<?php

declare(strict_types=1);

/**
 * Phase C7 — Country Shadow Verification CLI.
 * Consumes C6 result only. No import. No production restore.
 *
 * Usage:
 *   php scripts/backup/verify_country_shadow.php --job=cc_YYYY-MM-DD_HHMMSS
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$jobId = '';
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--job=')) {
        $jobId = trim(substr($arg, strlen('--job=')));
    } elseif (str_starts_with($arg, '--package=') || str_starts_with($arg, '--path=') || str_starts_with($arg, '--db=')) {
        fwrite(STDERR, "ERROR: only --job= is allowed (no paths/DB names).\n");
        exit(2);
    }
}

if ($jobId === '') {
    fwrite(STDERR, "Usage: php verify_country_shadow.php --job=cc_YYYY-MM-DD_HHMMSS\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_paths.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_country_shadow_verify.php';

try {
    $env = orange_backup_load_env_array($projectRoot);
    $backupRoot = orange_backup_resolve_root($env);
    $workRoot = orange_restore_resolve_work_root($env);

    $result = orange_country_shadow_verify_run([
        'project_root' => $projectRoot,
        'backup_root' => $backupRoot,
        'work_root' => $workRoot,
        'job_id' => $jobId,
        'env' => $env,
    ]);

    $report = is_array($result['report'] ?? null) ? $result['report'] : [];
    $overall = strtoupper((string) ($result['overall_result'] ?? 'FAIL'));

    echo 'COUNTRY_SHADOW_VERIFY_RESULT: ' . $overall . PHP_EOL;
    echo 'COUNTRY_ID: ' . (string) ($report['country_id'] ?? '') . PHP_EOL;
    echo 'READINESS_SCORE: ' . (string) ($result['readiness_score'] ?? 0) . PHP_EOL;
    echo 'TARGET_COUNTRY: ' . (string) ($report['target_country_integrity'] ?? 'FAIL') . PHP_EOL;
    echo 'SURVIVOR_COUNTRIES: ' . (string) ($report['survivor_country_integrity'] ?? 'FAIL') . PHP_EOL;
    echo 'GLOBAL_STATE: ' . (string) ($report['global_state_integrity'] ?? 'FAIL') . PHP_EOL;
    echo 'ACCOUNTING: ' . (string) ($report['accounting_integrity'] ?? 'FAIL') . PHP_EOL;
    echo 'STOCK_FIFO: ' . (string) ($report['stock_fifo_integrity'] ?? 'FAIL') . PHP_EOL;
    echo 'COMPOSITE: ' . (string) ($report['composite_integrity'] ?? 'FAIL') . PHP_EOL;
    echo 'PRODUCTION_DB_WRITES: 0' . PHP_EOL;
    echo 'EXECUTION_PERFORMED: NO' . PHP_EOL;
    foreach ($result['blocking_reason_codes'] ?? [] as $code) {
        fwrite(STDERR, 'BLOCKER: ' . (string) $code . PHP_EOL);
    }

    exit(orange_country_shadow_verify_exit_code($result));
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    echo 'COUNTRY_SHADOW_VERIFY_RESULT: FAIL' . PHP_EOL;
    echo 'PRODUCTION_DB_WRITES: 0' . PHP_EOL;
    echo 'EXECUTION_PERFORMED: NO' . PHP_EOL;
    exit(1);
}
