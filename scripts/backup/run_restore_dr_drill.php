<?php

declare(strict_types=1);

/**
 * Phase 3B.4G — Disaster Recovery Drill CLI (isolated fixtures only).
 *
 * Usage:
 *   php scripts/backup/run_restore_dr_drill.php
 *   php scripts/backup/run_restore_dr_drill.php --mode=all
 *   php scripts/backup/run_restore_dr_drill.php --mode=success
 *   php scripts/backup/run_restore_dr_drill.php --mode=rollback
 *   php scripts/backup/run_restore_dr_drill.php --mode=all --verbose
 *
 * Resolves all fixture locations internally. Never accepts DB names, package
 * paths, upload roots, or shell fragments. Never touches real production.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$mode = 'all';
$verbose = false;

foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (!is_string($arg)) {
        continue;
    }
    if ($arg === '--verbose' || $arg === '-v') {
        $verbose = true;
        continue;
    }
    if (str_starts_with($arg, '--mode=')) {
        $mode = strtolower(trim(substr($arg, strlen('--mode='))));
        continue;
    }
    if (
        str_starts_with($arg, '--path=')
        || str_starts_with($arg, '--package=')
        || str_starts_with($arg, '--uploads=')
        || str_starts_with($arg, '--dir=')
        || str_starts_with($arg, '--db=')
        || str_starts_with($arg, '--backup=')
        || str_starts_with($arg, '--work=')
        || str_starts_with($arg, '--root=')
    ) {
        fwrite(STDERR, "ERROR: arbitrary path/directory/database arguments are not allowed.\n");
        exit(2);
    }
    if (str_starts_with($arg, '--') && !str_starts_with($arg, '--mode=') && $arg !== '--verbose') {
        fwrite(STDERR, "ERROR: unsupported argument. Allowed: --mode=success|rollback|all [--verbose]\n");
        exit(2);
    }
}

if (!in_array($mode, ['success', 'rollback', 'all'], true)) {
    fwrite(STDERR, "Usage: php run_restore_dr_drill.php [--mode=success|rollback|all] [--verbose]\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_dr_drill.php';

try {
    $result = orange_restore_dr_drill_run([
        'project_root' => $projectRoot,
        'mode' => $mode,
        'verbose' => $verbose,
    ]);
    $report = is_array($result['report'] ?? null) ? $result['report'] : [];

    echo 'DR_DRILL_RESULT: ' . (!empty($result['ok']) ? 'PASS' : 'FAIL') . PHP_EOL;
    echo 'MODE: ' . $mode . PHP_EOL;
    echo 'ENVIRONMENT: isolated_drill' . PHP_EOL;
    echo 'TESTED_COMMIT: ' . (string) ($report['tested_commit'] ?? 'unknown') . PHP_EOL;
    echo 'FULL_RESTORE_CERTIFIED: ' . (!empty($report['full_restore_certified']) ? 'YES' : 'NO') . PHP_EOL;
    echo 'COUNTRY_RESTORE_CERTIFIED: NO' . PHP_EOL;
    echo 'RECOMMENDATION: ' . (string) ($report['production_execution_recommendation'] ?? 'NOT_CERTIFIED') . PHP_EOL;
    $successRan = !empty($report['full_restore_success_drill']['ran']);
    $rollbackRan = !empty($report['rollback_drill']['ran']);
    echo 'SUCCESS_DRILL: ' . (!$successRan ? 'SKIPPED' : (!empty($report['full_restore_success_drill']['ok']) ? 'PASS' : 'FAIL')) . PHP_EOL;
    echo 'ROLLBACK_DRILL: ' . (!$rollbackRan ? 'SKIPPED' : (!empty($report['rollback_drill']['ok']) ? 'PASS' : 'FAIL')) . PHP_EOL;
    if (isset($report['failure_injection_summary']['passed'])) {
        echo 'FAILURE_INJECTIONS: '
            . (int) ($report['failure_injection_summary']['passed'] ?? 0) . '/'
            . (int) ($report['failure_injection_summary']['total'] ?? 0) . PHP_EOL;
    }
    echo 'REPORT: ' . (string) ($result['report_path'] ?? ('docs/backup/' . ORANGE_RESTORE_DR_CERT_REPORT_FILE)) . PHP_EOL;
    echo 'REAL_PRODUCTION_RESTORE: NO' . PHP_EOL;

    if ($verbose && !empty($report['open_blockers']) && is_array($report['open_blockers'])) {
        echo 'OPEN_BLOCKERS:' . PHP_EOL;
        foreach ($report['open_blockers'] as $b) {
            if (!is_array($b)) {
                continue;
            }
            echo '  - [' . (string) ($b['severity'] ?? '') . '] ' . (string) ($b['code'] ?? '') . ': '
                . (string) ($b['message'] ?? '') . PHP_EOL;
        }
    }

    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
