<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = getenv('ORANGE_BACKUP_PROJECT_ROOT');
if ($root === false || $root === '') {
    fwrite(STDERR, "ORANGE_BACKUP_PROJECT_ROOT not set\n");
    exit(2);
}
chdir($root);
$envPath = $root . DIRECTORY_SEPARATOR . '.env.php';
if (!is_file($envPath)) {
    fwrite(STDERR, "Missing .env.php\n");
    exit(2);
}
$env = require $envPath;
if (!is_array($env)) {
    $env = [];
}
require $root . DIRECTORY_SEPARATOR . 'config.php';
echo json_encode([
    'host' => DB_HOST,
    'name' => DB_NAME,
    'user' => DB_USER,
    'pass' => DB_PASS,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
