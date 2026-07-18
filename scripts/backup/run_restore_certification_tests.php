<?php

declare(strict_types=1);

/**
 * Phase 3B.4G — Master runner for restore certification self-tests.
 *
 * Usage:
 *   php scripts/backup/run_restore_certification_tests.php
 *
 * CLI only. Returns non-zero if any suite fails.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (!is_string($arg)) {
        continue;
    }
    if (
        str_starts_with($arg, '--path=')
        || str_starts_with($arg, '--package=')
        || str_starts_with($arg, '--db=')
        || str_starts_with($arg, '--dir=')
    ) {
        fwrite(STDERR, "ERROR: arbitrary path/directory/database arguments are not allowed.\n");
        exit(2);
    }
}

$projectRoot = dirname(__DIR__, 2);
$phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';

$suites = [
    'self_test_restore_admin.php',
    'self_test_pre_restore_backup.php',
    'self_test_shadow_restore.php',
    'self_test_shadow_verify.php',
    'self_test_shadow_files.php',
    'self_test_shadow_smoke.php',
    'self_test_maintenance_framework.php',
    'self_test_production_import.php',
    'self_test_production_uploads_cutover.php',
    'self_test_production_rollback.php',
    'self_test_production_finalize.php',
    'self_test_restore_dr_drill.php',
    'self_test_backup_admin_nav.php',
    // P0 + certification closure suites (required; no partial certification).
    'self_test_maintenance_enforcement.php',
    'self_test_legacy_restore_fencing.php',
    'self_test_production_cutover_authorization.php',
    'self_test_restore_real_clone_validation.php',
    'self_test_restore_fw_transition_matrix.php',
    'self_test_exec_lock_heartbeat.php',
    'self_test_restore_deployment_preflight.php',
    'self_test_maintenance_http_smoke.php',
    'self_test_phase2_callsite_fence.php',
];

$failed = 0;
$passed = 0;
$results = [];

echo "=== Orange Restore Certification Test Master ===\n";
echo 'PHP: ' . $phpBin . "\n";
echo 'Suites: ' . count($suites) . "\n\n";

foreach ($suites as $suite) {
    $script = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup'
        . DIRECTORY_SEPARATOR . $suite;
    if (!is_file($script)) {
        echo "MISSING: {$suite}\n";
        $failed++;
        $results[] = ['suite' => $suite, 'ok' => false, 'code' => 'missing'];
        continue;
    }
    echo "--- {$suite} ---\n";
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script);
    $output = [];
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    echo implode("\n", $output) . "\n";
    if ($code === 0) {
        echo "SUITE_RESULT: PASS\n\n";
        $passed++;
        $results[] = ['suite' => $suite, 'ok' => true, 'exit' => 0];
    } else {
        echo "SUITE_RESULT: FAIL (exit {$code})\n\n";
        $failed++;
        $results[] = ['suite' => $suite, 'ok' => false, 'exit' => $code];
    }
}

echo "=== MASTER TOTAL: {$passed} passed, {$failed} failed / " . count($suites) . " suites ===\n";
exit($failed > 0 ? 1 : 0);
