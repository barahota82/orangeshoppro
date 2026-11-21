<?php

declare(strict_types=1);

/**
 * Plesk scheduled-task entry point for full disaster backup.
 *
 * Usage:
 *   php scripts/backup/run_full_backup.php
 *   php scripts/backup/run_full_backup.php --skip-retention --skip-stale-workspace-cleanup --pin-baseline --round-id=OR-TEST-YYYYMMDD
 *
 * Default scheduled invocation must NOT pass baseline flags (scheduled retention unchanged).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_schema_contract.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_runner.php';

/**
 * @param list<string> $argv
 * @return array{skip_retention:bool,skip_stale_workspace_cleanup:bool,pin_baseline:bool,round_id:string,created_by:string}
 */
function orange_backup_parse_full_cli_options(array $argv): array
{
    $opts = [
        'skip_retention' => false,
        'skip_stale_workspace_cleanup' => false,
        'pin_baseline' => false,
        'round_id' => '',
        'created_by' => 'run_full_backup_cli',
    ];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--skip-retention') {
            $opts['skip_retention'] = true;
            continue;
        }
        if ($arg === '--skip-stale-workspace-cleanup') {
            $opts['skip_stale_workspace_cleanup'] = true;
            continue;
        }
        if ($arg === '--pin-baseline') {
            $opts['pin_baseline'] = true;
            continue;
        }
        if (str_starts_with($arg, '--round-id=')) {
            $opts['round_id'] = trim(substr($arg, strlen('--round-id=')));
            continue;
        }
        if (str_starts_with($arg, '--created-by=')) {
            $opts['created_by'] = trim(substr($arg, strlen('--created-by=')));
        }
    }

    return $opts;
}

try {
    $options = orange_backup_parse_full_cli_options($_SERVER['argv'] ?? []);
    // BL-02 / B1: early validation; runner imposes safe skip flags when pin_baseline=true.
    if (!empty($options['pin_baseline'])) {
        orange_backup_validate_baseline_round_id((string) $options['round_id']);
        $options['skip_retention'] = true;
        $options['skip_stale_workspace_cleanup'] = true;
    }
    $result = orange_backup_run_full($projectRoot, null, $options);
    echo json_encode([
        'ok' => $result['ok'],
        'backend' => $result['backend'],
        'snapshot' => $result['snapshot'],
        'log_file' => $result['log_file'],
        'message' => $result['message'],
        'pin' => $result['pin'] ?? null,
        'failure_stage' => $result['failure_stage'] ?? null,
        'protection_status' => $result['protection_status'] ?? null,
        'package_created' => !empty($result['package_created']),
        'verification' => $result['verification'] ?? null,
        'baseline_state' => $result['baseline_state'] ?? orange_backup_baseline_state_from_result($result),
        'publication_ready' => false,
        'options' => [
            'skip_retention' => !empty($options['skip_retention']),
            'skip_stale_workspace_cleanup' => !empty($options['skip_stale_workspace_cleanup']),
            'pin_baseline' => !empty($options['pin_baseline']),
            'round_id' => (string) ($options['round_id'] ?? ''),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

    exit($result['exit_code']);
} catch (Throwable $e) {
    fwrite(STDERR, 'run_full_backup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
