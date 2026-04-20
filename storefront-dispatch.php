<?php

declare(strict_types=1);

/**
 * موجّه المسارات القصيرة للواجهة (مثل /tiktok /instagram-ar/cart).
 * يستدعيه IIS عبر web.config؛ يحل path_segment من جدول channels ثم يحمّل pages/*.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/catalog_schema.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$first = isset($_GET['sf_first'])
    ? strtolower((string) preg_replace('/[^a-z0-9\-]/i', '', (string) $_GET['sf_first']))
    : '';
$langToken = isset($_GET['sf_langx']) ? strtolower((string) $_GET['sf_langx']) : '';
$tail = isset($_GET['sf_tail']) ? trim(str_replace('\\', '/', (string) $_GET['sf_tail']), '/') : '';

$lang = match ($langToken) {
    '-ar' => 'ar',
    '-hi' => 'hi',
    '-ph' => 'fil',
    default => 'en',
};

if ($first === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';

    exit;
}

$previewQ = isset($_GET['sf_preview']) ? (string) $_GET['sf_preview'] : '';
$tokenPreview = ORANGE_STOREFRONT_PREVIEW_TOKEN !== ''
    && $previewQ !== ''
    && hash_equals(ORANGE_STOREFRONT_PREVIEW_TOKEN, $previewQ);
$sessionPreview = orange_storefront_preview_session_valid_for_segment($first);
$previewOk = $tokenPreview || $sessionPreview;
/* للزوار: قناة غير نشطة = 404. معاينة الإدمن (جلسة أو ?sf_preview=) تسمح بفتح المسار رغم الإيقاف */
$channelSlug = orange_channel_slug_for_path_segment($pdo, $first, !$previewOk);
if ($channelSlug === null || $channelSlug === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';

    exit;
}

$_GET['channel'] = $channelSlug;
$_GET['lang'] = $lang;
$_REQUEST['channel'] = $channelSlug;
$_REQUEST['lang'] = $lang;

if ($tail === '' || $tail === '/') {
    require __DIR__ . '/pages/home.php';

    exit;
}
if ($tail === 'cart') {
    require __DIR__ . '/pages/cart.php';

    exit;
}
if ($tail === 'track') {
    require __DIR__ . '/pages/track.php';

    exit;
}
if (preg_match('#^product/(\d+)$#', $tail, $m)) {
    $_GET['id'] = (int) $m[1];
    $_REQUEST['id'] = (int) $m[1];
    require __DIR__ . '/pages/product.php';

    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';

exit;
