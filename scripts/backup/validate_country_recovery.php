<?php

declare(strict_types=1);

/**
 * Phase C5 — Country Disaster Recovery Validation (DRV) CLI.
 * VERIFY-consuming recoverability check only — no restore/import/rollback.
 *
 * Usage:
 *   php scripts/backup/validate_country_recovery.php --package=YYYY-MM-DD_HHMMSS
 *   php scripts/backup/validate_country_recovery.php --package=YYYY-MM-DD_HHMMSS --country=kw
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$packageId = '';
$countryCode = '';
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--package=')) {
        $packageId = trim(substr($arg, strlen('--package=')));
    }
    if (str_starts_with($arg, '--country=')) {
        $countryCode = trim(substr($arg, strlen('--country=')));
    }
}

if ($packageId === '') {
    fwrite(STDERR, "Usage: php validate_country_recovery.php --package=YYYY-MM-DD_HHMMSS [--country=cc]\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_paths.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_crp_drv.php';

try {
    $env = orange_backup_load_env_array($projectRoot);
    $backupRoot = orange_backup_resolve_root($env);
    $resolved = orange_country_drv_resolve_package_id(
        $backupRoot,
        $packageId,
        $countryCode !== '' ? $countryCode : null
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'COUNTRY_DRV_RESULT: FAIL' . PHP_EOL);
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$result = orange_country_drv_run($resolved['package_path'], [
    'project_root' => $projectRoot,
    'write_report' => true,
    'package_id' => $resolved['package_id'],
]);

$report = is_array($result['report'] ?? null) ? $result['report'] : [];
$overall = strtoupper((string) ($result['overall_result'] ?? 'fail'));
$flags = is_array($result['flags'] ?? null) ? $result['flags'] : [];

echo 'COUNTRY_DRV_RESULT: ' . $overall . PHP_EOL;
echo 'COUNTRY_ID: ' . (string) ($report['country_id'] ?? '') . PHP_EOL;
echo 'RECOVERY_SCORE: ' . (string) ($result['recovery_score'] ?? 0) . PHP_EOL;
echo 'VERIFY: ' . strtoupper((string) ($result['verify_result'] ?? 'missing')) . PHP_EOL;
echo 'BOUNDARY_ISOLATION: ' . (!empty($flags['boundary_isolation_valid']) ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'DEPENDENCY_COMPLETENESS: ' . (!empty($flags['dependency_completeness_valid']) ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'COLLISION_ANALYSIS: ' . (!empty($flags['collision_analysis_valid']) ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'COMPOSITE_GRAPH: ' . (!empty($flags['composite_graph_valid']) ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'ACCOUNTING_BOUNDARY: ' . (!empty($flags['accounting_boundary_valid']) ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'STOCK_FIFO: ' . (!empty($flags['stock_fifo_valid']) ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'UPLOADS: ' . (!empty($flags['uploads_valid']) ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'SEQUENCES: ' . (!empty($flags['sequences_valid']) ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'ROLLBACK_READINESS: ' . (!empty($flags['rollback_readiness_valid']) ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'ENVIRONMENT: ' . (!empty($flags['environment_compatible']) ? 'PASS' : 'FAIL') . PHP_EOL;
if (!empty($result['report_path'])) {
    echo 'REPORT: ' . basename((string) $result['report_path']) . PHP_EOL;
}
foreach ($result['blocking_reason_codes'] ?? [] as $code) {
    fwrite(STDERR, 'BLOCKER: ' . (string) $code . PHP_EOL);
}

exit(orange_country_drv_exit_code($result));
