<?php

declare(strict_types=1);

/**
 * Phase C4 — Verify a Country Recovery Package (CRP). VERIFY ONLY — no restore.
 *
 * Usage:
 *   php scripts/backup/verify_country_package.php --package=PATH
 *   php scripts/backup/verify_country_package.php --package=PATH --no-write-report
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$packagePath = '';
$writeReport = true;
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--package=')) {
        $packagePath = trim(substr($arg, strlen('--package=')));
    }
    if ($arg === '--no-write-report') {
        $writeReport = false;
    }
}

if ($packagePath === '') {
    fwrite(STDERR, "Usage: php verify_country_package.php --package=PATH [--no-write-report]\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_crp_verify.php';

$result = orange_crp_verify_run($packagePath, [
    'write_report' => $writeReport,
    'project_root' => $projectRoot,
]);

echo 'overall=' . $result['overall'] . "\n";
if ($result['report_path'] !== null) {
    echo 'report=' . $result['report_path'] . "\n";
}
foreach ($result['warnings'] as $code) {
    fwrite(STDOUT, "WARN: {$code}\n");
}
foreach ($result['codes'] as $code) {
    fwrite(STDERR, "FAIL: {$code}\n");
}

if ($result['overall'] === 'FAIL') {
    exit(1);
}

echo "OK: country recovery package verify {$result['overall']}.\n";
if (is_array($result['manifest'])) {
    echo 'package_type=' . (string) ($result['manifest']['package_type'] ?? '') . "\n";
    echo 'country_id=' . (string) ($result['manifest']['country_id'] ?? '') . "\n";
    echo 'schema_revision=' . (string) ($result['manifest']['schema_revision'] ?? '') . "\n";
    echo 'boundary_policy_version=' . (string) ($result['manifest']['boundary_policy_version'] ?? '') . "\n";
    echo 'dependency_graph_version=' . (string) ($result['manifest']['dependency_graph_version'] ?? '') . "\n";
    echo 'package_fingerprint=' . (string) ($result['manifest']['package_fingerprint'] ?? '') . "\n";
}

exit(0);
