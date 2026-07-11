<?php

declare(strict_types=1);

/**
 * Full-backup metadata helper for orange_backup.ps1 (no secrets).
 *
 * Usage:
 *   php scripts/backup/backup_metadata.php --project-root=D:\orange
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = '';
$argvLocal = $_SERVER['argv'] ?? [];
foreach ($argvLocal as $arg) {
    if (str_starts_with($arg, '--project-root=')) {
        $projectRoot = substr($arg, strlen('--project-root='));
    }
}
if ($projectRoot === '') {
    $projectRoot = dirname(__DIR__, 2);
}

require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_paths.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_full.php';

try {
    $envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env.php';
    $env = is_file($envPath) ? (require $envPath) : [];
    if (!is_array($env)) {
        $env = [];
    }

    $backupRootDefault = null;
    try {
        $backupRootDefault = orange_backup_resolve_root($env);
    } catch (Throwable) {
        $backupRootDefault = null;
    }

    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $meta = orange_backup_collect_safe_metadata($pdo, $projectRoot, $env);
    $meta['backup_root_resolved'] = $backupRootDefault;
    $meta['code_schema_revision'] = ORANGE_CATALOG_SCHEMA_PHP_REVISION;

    echo json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'backup_metadata failed: ' . $e->getMessage() . "\n");
    exit(1);
}
