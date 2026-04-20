<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/catalog_schema.php';
require_once __DIR__ . '/includes/storefront_account.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$slug = isset($_GET['channel']) && (string) $_GET['channel'] !== ''
    ? (string) $_GET['channel']
    : (orange_storefront_read_saved_channel_slug() ?? orange_storefront_default_channel_slug($pdo));
$slug = orange_storefront_valid_channel_slug($pdo, $slug);

$lang = isset($_GET['lang']) ? strtolower(trim((string) $_GET['lang'])) : 'en';
if (!in_array($lang, ['en', 'ar', 'fil', 'hi'], true)) {
    $lang = 'en';
}

$startUrl = storefront_url('home', $slug, $lang);
$scope = PUBLIC_BASE_PATH === '' ? '/' : rtrim(PUBLIC_BASE_PATH, '/') . '/';

/* لون PWA موحّد — اسم التطبيق يتبع القناة (WEB / ONLINE / TIKTOK) */
$themeColor = '#ff6a00';
$ch = get_channel_by_slug($slug);
$fullName = storefront_channel_display_name($ch ?? ['name' => ''], $slug);

$shortName = $fullName;
if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($shortName, 'UTF-8') > 12) {
        $shortName = mb_substr($shortName, 0, 12, 'UTF-8') . '…';
    }
} elseif (strlen($shortName) > 12) {
    $shortName = substr($shortName, 0, 12) . '…';
}

$pub = PUBLIC_BASE_PATH === '' ? '' : PUBLIC_BASE_PATH;
/** روابط مطلقة: Chrome/Edge على الويندوز يعتمدون عليها لأيقونة التثبيت والاختصار */
$pwaIconSrc = static function (string $file) use ($pub): string {
    $rel = $pub . storefront_asset_url('/assets/images/' . $file);

    return storefront_absolute_url($rel);
};

$manifest = [
    'name' => $fullName,
    'short_name' => $shortName,
    'description' => $fullName,
    'start_url' => $startUrl,
    'scope' => $scope,
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => '#050505',
    'theme_color' => $themeColor,
    'icons' => [
        [
            'src' => $pwaIconSrc('pwa-icon-144.png'),
            'sizes' => '144x144',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => $pwaIconSrc('pwa-icon-192.png'),
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => $pwaIconSrc('pwa-icon-512.png'),
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => $pwaIconSrc('pwa-icon-1024.png'),
            'sizes' => '1024x1024',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
    ],
    'lang' => $lang,
    'dir' => $lang === 'ar' ? 'rtl' : 'ltr',
];

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$flags = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
echo json_encode($manifest, $flags);
