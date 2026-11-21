<?php

declare(strict_types=1);

/**
 * OR-CURSOR-022 / R2 — Full Disaster baseline for a test round without wiping peers.
 *
 * Always imposes: skip_retention + skip_stale_workspace_cleanup + pin reason=test_round_baseline.
 * Validates round_id BEFORE any side effect. Non-PHP backends are refused before cleanup (BL-01/B1).
 *
 * Usage:
 *   php scripts/backup/run_test_baseline_backup.php --round-id=OR-TEST-20261121
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_schema_contract.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_runner.php';

$roundId = '';
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--round-id=')) {
        $roundId = trim(substr($arg, strlen('--round-id=')));
    }
}

try {
    orange_backup_validate_baseline_round_id($roundId);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    fwrite(STDERR, "Usage: php scripts/backup/run_test_baseline_backup.php --round-id=OR-TEST-YYYYMMDD\n");
    exit(2);
}

try {
    $result = orange_backup_run_full($projectRoot, null, [
        // Explicit flags; runner also imposes them when pin_baseline=true (B1 contract).
        'skip_retention' => true,
        'skip_stale_workspace_cleanup' => true,
        'pin_baseline' => true,
        'round_id' => $roundId,
        'created_by' => 'run_test_baseline_backup',
    ]);
    $baselineState = orange_backup_baseline_state_from_result($result);
    $payload = [
        'ok' => (bool) ($result['ok'] ?? false),
        'round_id' => $roundId,
        'backend' => $result['backend'] ?? null,
        'snapshot' => $result['snapshot'] ?? null,
        'pin' => $result['pin'] ?? null,
        'log_file' => $result['log_file'] ?? null,
        'message' => $result['message'] ?? '',
        'failure_stage' => $result['failure_stage'] ?? null,
        'protection_status' => $result['protection_status'] ?? null,
        'package_created' => !empty($result['package_created']),
        'verification' => $result['verification'] ?? null,
        'consistency_note' => 'DB export and uploads archive are sequential; use a quiet single-writer window. No Stop Pool by default.',
        'publication_ready' => false,
        'baseline_state' => $baselineState,
    ];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit((int) ($result['exit_code'] ?? ($result['ok'] ? 0 : 1)));
} catch (Throwable $e) {
    fwrite(STDERR, 'run_test_baseline_backup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
