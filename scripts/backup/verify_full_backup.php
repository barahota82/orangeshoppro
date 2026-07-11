<?php

declare(strict_types=1);

/**
 * Read-only verification for a full-disaster backup package.
 *
 * Usage:
 *   php scripts/backup/verify_full_backup.php --package=PATH
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$packagePath = '';
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--package=')) {
        $packagePath = substr($arg, strlen('--package='));
    }
}

if ($packagePath === '') {
    fwrite(STDERR, "Usage: php verify_full_backup.php --package=PATH\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_full.php';

$result = orange_backup_verify_full_package($packagePath);

foreach ($result['warnings'] as $warning) {
    fwrite(STDOUT, "WARN: {$warning}\n");
}

if (!$result['ok']) {
    foreach ($result['errors'] as $error) {
        fwrite(STDERR, "ERROR: {$error}\n");
    }
    exit(1);
}

echo "OK: full-disaster package verified.\n";
if (is_array($result['manifest'])) {
    echo 'package_type=' . ($result['manifest']['package_type'] ?? '') . "\n";
    echo 'schema_revision=' . ($result['manifest']['schema_revision'] ?? '') . "\n";
    echo 'backup_status=' . ($result['manifest']['backup_status'] ?? '') . "\n";
}

exit(0);
