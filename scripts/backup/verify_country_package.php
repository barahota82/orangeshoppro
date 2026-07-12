<?php

declare(strict_types=1);

/**
 * Read-only verification for a Country Recovery Package (CRP).
 *
 * Usage:
 *   php scripts/backup/verify_country_package.php --package=PATH
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
    fwrite(STDERR, "Usage: php verify_country_package.php --package=PATH\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_validate.php';

$result = orange_country_export_verify_package($packagePath);

foreach ($result['warnings'] as $warning) {
    fwrite(STDOUT, "WARN: {$warning}\n");
}

if (!$result['ok']) {
    foreach ($result['errors'] as $error) {
        fwrite(STDERR, "ERROR: {$error}\n");
    }
    exit(1);
}

echo "OK: country recovery package verified.\n";
if (is_array($result['manifest'])) {
    echo 'package_type=' . (string) ($result['manifest']['package_type'] ?? '') . "\n";
    echo 'country_id=' . (string) ($result['manifest']['country_id'] ?? '') . "\n";
    echo 'country_code=' . (string) ($result['manifest']['country_code'] ?? '') . "\n";
    echo 'schema_revision=' . (string) ($result['manifest']['schema_revision'] ?? '') . "\n";
    echo 'registry_version=' . (string) ($result['manifest']['registry_version'] ?? '') . "\n";
    echo 'package_status=' . (string) ($result['manifest']['package_status'] ?? '') . "\n";
}

exit(0);
