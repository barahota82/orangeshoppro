<?php

declare(strict_types=1);

/**
 * Disaster Recovery Validation (DRV) CLI — Phase 1C.
 *
 * Usage:
 *   php scripts/backup/validate_backup_recoverability.php --package=PATH
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$packagePath = '';
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--package=')) {
        $packagePath = trim(substr($arg, strlen('--package=')));
    }
}

if ($packagePath === '') {
    fwrite(STDERR, "Usage: php validate_backup_recoverability.php --package=PATH\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'recovery_validation.php';

$report = orange_recovery_validate_package($packagePath);
$reportPath = orange_recovery_write_report_file($report);

echo 'overall_result=' . (string) ($report['overall_result'] ?? 'fail') . PHP_EOL;
echo 'recovery_score=' . (int) ($report['recovery_score'] ?? 0) . PHP_EOL;
echo 'package_type=' . (string) ($report['package_type'] ?? '') . PHP_EOL;
echo 'validation_engine_version=' . (string) ($report['validation_engine_version'] ?? '') . PHP_EOL;
if ($reportPath !== null) {
    echo 'recovery_validation_report=' . $reportPath . PHP_EOL;
}

foreach ($report['warnings'] ?? [] as $warning) {
    fwrite(STDOUT, 'WARN: ' . (string) $warning . PHP_EOL);
}
foreach ($report['errors'] ?? [] as $error) {
    fwrite(STDERR, 'ERROR: ' . (string) $error . PHP_EOL);
}

exit(orange_recovery_validation_exit_code($report));
