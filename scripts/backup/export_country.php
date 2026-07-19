<?php

declare(strict_types=1);

/**
 * Country Recovery Package (CRP) export CLI — Phase C3 (export only).
 *
 * Uses frozen boundary matrix (C1.1/C2). Does not run Country Restore.
 *
 * Usage:
 *   php scripts/backup/export_country.php --country-id=N
 *   php scripts/backup/export_country.php --country-id=N --output=PATH
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$countryId = 0;
$outputPath = '';
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--country-id=')) {
        $countryId = (int) substr($arg, strlen('--country-id='));
    } elseif (str_starts_with($arg, '--output=')) {
        $outputPath = trim(substr($arg, strlen('--output=')));
    }
}

if ($countryId <= 0) {
    fwrite(STDERR, "Usage: php export_country.php --country-id=N [--output=PATH]\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_export.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $result = orange_country_export_run($pdo, [
        'country_id' => $countryId,
        'project_root' => $projectRoot,
        'output_path' => $outputPath,
    ]);
    if (!($result['ok'] ?? false)) {
        fwrite(STDERR, 'export_country failed: ' . (string) ($result['message'] ?? 'unknown') . "\n");
        exit(1);
    }
    echo "OK: country recovery package exported.\n";
    echo 'package_path=' . (string) ($result['package_path'] ?? '') . "\n";
    if (is_array($result['manifest'])) {
        echo 'package_status=' . (string) ($result['manifest']['package_status'] ?? '') . "\n";
        echo 'country_code=' . (string) ($result['manifest']['country_code'] ?? '') . "\n";
        echo 'schema_revision=' . (string) ($result['manifest']['schema_revision'] ?? '') . "\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'export_country failed: ' . $e->getMessage() . "\n");
    exit(1);
}
