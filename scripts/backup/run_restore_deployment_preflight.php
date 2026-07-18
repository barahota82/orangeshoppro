<?php

declare(strict_types=1);

/**
 * Fail-closed production deployment preflight for Full Restore.
 *
 * Usage:
 *   php scripts/backup/run_restore_deployment_preflight.php
 *   php scripts/backup/run_restore_deployment_preflight.php --verbose
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$verbose = false;
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (!is_string($arg)) {
        continue;
    }
    if ($arg === '--verbose' || $arg === '-v') {
        $verbose = true;
        continue;
    }
    if (str_starts_with($arg, '--path=') || str_starts_with($arg, '--db=') || str_starts_with($arg, '--root=')) {
        fwrite(STDERR, "ERROR: arbitrary path/database arguments are not allowed.\n");
        exit(2);
    }
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_deployment_preflight.php';

$result = orange_restore_deployment_preflight_run(['project_root' => $projectRoot]);
echo 'DEPLOYMENT_PREFLIGHT: ' . (!empty($result['ok']) ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'VERSION: ' . (string) ($result['version'] ?? '') . PHP_EOL;
echo 'DB_NAME: ' . (string) ($result['db_name'] ?? '') . PHP_EOL;
echo 'ENVIRONMENT: ' . ((string) ($result['environment'] ?? '') !== '' ? (string) $result['environment'] : '(unset)') . PHP_EOL;
echo 'BLOCKERS: ' . count($result['blockers'] ?? []) . PHP_EOL;
foreach ($result['blockers'] ?? [] as $b) {
    echo 'BLOCKER: ' . $b . PHP_EOL;
}
if ($verbose) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}
exit(!empty($result['ok']) ? 0 : 1);
