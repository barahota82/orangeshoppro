<?php

declare(strict_types=1);

/**
 * Resolve and validate ORANGE_BACKUP_ROOT (no secrets).
 *
 * Usage:
 *   php scripts/backup/resolve_backup_root.php [--project-root=PATH] [--override=PATH]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
$override = null;

foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--project-root=')) {
        $projectRoot = substr($arg, strlen('--project-root='));
    } elseif (str_starts_with($arg, '--override=')) {
        $override = substr($arg, strlen('--override='));
    }
}

require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_paths.php';

$env = [];
$envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env.php';
if (is_file($envPath)) {
    $loaded = require $envPath;
    if (is_array($loaded)) {
        $env = $loaded;
    }
}

try {
    $resolved = orange_backup_resolve_root($env, $override);
    echo json_encode([
        'backup_root' => $resolved,
        'ok' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'resolve_backup_root failed: ' . $e->getMessage() . "\n");
    exit(1);
}
