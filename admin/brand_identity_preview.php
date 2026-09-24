<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = $root . '/config.php';
if (is_file($config)) {
    require_once $config;
}
require_once $root . '/includes/brand_identity_preview_render.php';

$surface = trim((string) ($_GET['surface'] ?? 'login'));
$viewport = trim((string) ($_GET['viewport'] ?? 'desktop'));
$releaseId = (int) ($_GET['release_id'] ?? $_GET['orange_brand_preview'] ?? 0);
$token = trim((string) ($_GET['token'] ?? ''));
$locale = trim((string) ($_GET['locale'] ?? 'ar'));

if (!in_array($surface, orange_brand_identity_preview_surfaces(), true)) {
    $surface = 'login';
}
if (!isset(orange_brand_identity_preview_viewport_widths()[$viewport])) {
    $viewport = 'desktop';
}

$allowed = false;
if ($releaseId > 0 && $token !== '' && orange_brand_identity_preview_token_ok($releaseId, $token)) {
    $allowed = true;
} elseif (function_exists('current_admin') && current_admin() !== null && function_exists('orange_brand_identity_require_admin')) {
    try {
        orange_brand_identity_require_admin('view');
        $allowed = true;
    } catch (Throwable $e) {
        $allowed = false;
    }
}

if (!$allowed) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'forbidden';
    exit;
}

if ($releaseId > 0) {
    orange_brand_identity_runtime_bind_preview_release($releaseId);
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
echo orange_brand_identity_preview_document($surface, $viewport, $locale);
exit;
