<?php

declare(strict_types=1);

/**
 * Finalize a full-disaster backup workspace (manifest, health, checksums).
 *
 * Usage:
 *   php scripts/backup/finalize_full_backup.php --workspace=PATH --backup-root=PATH --dump-file=db.sql.gz --uploads-file=uploads.zip [--project-root=PATH]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
$workspace = '';
$backupRoot = '';
$dumpFile = '';
$uploadsFile = '';

foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--project-root=')) {
        $projectRoot = substr($arg, strlen('--project-root='));
    } elseif (str_starts_with($arg, '--workspace=')) {
        $workspace = substr($arg, strlen('--workspace='));
    } elseif (str_starts_with($arg, '--backup-root=')) {
        $backupRoot = substr($arg, strlen('--backup-root='));
    } elseif (str_starts_with($arg, '--dump-file=')) {
        $dumpFile = substr($arg, strlen('--dump-file='));
    } elseif (str_starts_with($arg, '--uploads-file=')) {
        $uploadsFile = substr($arg, strlen('--uploads-file='));
    }
}

if ($workspace === '' || $backupRoot === '' || $dumpFile === '' || $uploadsFile === '') {
    fwrite(STDERR, "Usage: php finalize_full_backup.php --workspace=PATH --backup-root=PATH --dump-file=NAME --uploads-file=NAME [--project-root=PATH]\n");
    exit(2);
}

require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_full.php';

$env = [];
$envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env.php';
if (is_file($envPath)) {
    $loaded = require $envPath;
    if (is_array($loaded)) {
        $env = $loaded;
    }
}

$metadata = [];
$metadataOk = false;

if (is_file($envPath)) {
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php';
    try {
        $pdo = db();
        orange_catalog_ensure_schema($pdo);
        $metadata = orange_backup_collect_safe_metadata($pdo, $projectRoot, $env);
        $metadataOk = true;
    } catch (Throwable $e) {
        fwrite(STDERR, 'Metadata collection warning: ' . $e->getMessage() . "\n");
    }
}

try {
    $result = orange_backup_full_finalize_workspace([
        'workspace' => $workspace,
        'backup_root' => $backupRoot,
        'dump_file' => $dumpFile,
        'uploads_file' => $uploadsFile,
        'metadata' => $metadata,
        'metadata_ok' => $metadataOk,
        'env' => $env,
    ]);

    $status = $result['package_status'];
    echo json_encode([
        'package_status' => $status,
        'backup_status' => $result['manifest']['backup_status'] ?? null,
        'manifest_file' => ORANGE_BACKUP_MANIFEST_FILE,
        'health_file' => ORANGE_BACKUP_HEALTH_FILE,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

    exit($status === 'failed' ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'finalize_full_backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
