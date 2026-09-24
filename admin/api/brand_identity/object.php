<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/brand_identity_admin.php';

$sha = strtolower(trim((string) ($_GET['sha'] ?? '')));
if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
    http_response_code(404);
    exit;
}

$pdo = orange_brand_identity_runtime_control_pdo();
if ($pdo === null) {
    http_response_code(404);
    exit;
}

try {
    $obj = orange_brand_identity_object($pdo, $sha);
    $abs = orange_brand_identity_object_abs_path($pdo, $sha);
} catch (Throwable $e) {
    http_response_code(404);
    exit;
}

if ($obj === null || $abs === null || !is_file($abs)) {
    http_response_code(404);
    exit;
}

$download = trim((string) ($_GET['download'] ?? '')) === '1';
$isCurrent = orange_brand_identity_object_is_current_active($pdo, $sha);

if ($download || !$isCurrent) {
    orange_brand_identity_require_admin('view');
}

$mime = (string) ($obj['mime'] ?? 'application/octet-stream');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($abs));
header('X-Content-Type-Options: nosniff');
$base = basename((string) ($obj['original_filename'] ?? ($sha . '.bin')));
if ($download) {
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $base) . '"');
} else {
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $base) . '"');
}
readfile($abs);
exit;
