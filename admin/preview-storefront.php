<?php

declare(strict_types=1);

/**
 * يضبط جلسة معاينة واجهة المتجر (بدون إظهار سر في رابط المتجر) ثم يعيد التوجيه للمسار القصير.
 * يتطلّب تسجيل دخول إدمن؛ صالحة ~15 دقيقة لنفس المستعرض.
 */
require_once __DIR__ . '/../config.php';
orange_send_html_no_cache_headers();
require_once __DIR__ . '/../includes/catalog_schema.php';
require_once __DIR__ . '/../includes/upload_paths.php';

require_admin_page();

$ps = isset($_GET['ps']) ? strtolower((string) preg_replace('/[^a-z0-9\-]/i', '', (string) $_GET['ps'])) : '';
if ($ps === '' || in_array($ps, orange_storefront_reserved_path_segments(), true)) {
    header('Location: ' . storefront_public_path('/admin/index.php?page=channels'), true, 302);
    exit;
}

$pdo = db();
orange_catalog_ensure_schema($pdo);
$st = $pdo->prepare('SELECT 1 FROM channels WHERE path_segment = ? LIMIT 1');
$st->execute([$ps]);
if (!$st->fetchColumn()) {
    header('Location: ' . storefront_public_path('/admin/index.php?page=channels'), true, 302);
    exit;
}

$_SESSION['orange_sf_preview'] = [
    'path_segment' => $ps,
    'exp' => time() + 900,
];

$pathPrefix = PUBLIC_BASE_PATH === '' ? '' : rtrim(PUBLIC_BASE_PATH, '/');
$dest = ($pathPrefix === '' ? '' : $pathPrefix) . '/' . rawurlencode($ps);
if (ORANGE_SITE_PUBLIC_ORIGIN !== '') {
    $dest = rtrim(ORANGE_SITE_PUBLIC_ORIGIN, '/') . $dest;
}
header('Location: ' . $dest, true, 302);
exit;
