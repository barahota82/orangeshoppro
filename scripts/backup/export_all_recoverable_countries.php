<?php

declare(strict_types=1);

/**
 * Automatic Country Recovery Package batch export — Phase 1B.3.
 *
 * Discovers recoverable countries dynamically and exports one package per country.
 *
 * Usage:
 *   php scripts/backup/export_all_recoverable_countries.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_batch_export.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $result = orange_crp_batch_export_all($pdo, $projectRoot);
    orange_crp_batch_print_summary($result);
    if (!($result['ok'] ?? false)) {
        fwrite(STDERR, (string) ($result['message'] ?? 'Country package batch failed.') . "\n");
    }
    exit((int) ($result['exit_code'] ?? 1));
} catch (Throwable $e) {
    fwrite(STDERR, 'export_all_recoverable_countries failed: ' . $e->getMessage() . "\n");
    exit(1);
}
