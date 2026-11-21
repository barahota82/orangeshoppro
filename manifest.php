<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/catalog_schema.php';
require_once __DIR__ . '/includes/storefront_account.php';
require_once __DIR__ . '/includes/upload_paths.php';

$pdo = db();
orange_catalog_ensure_storefront_read_bootstrap($pdo);
require_once __DIR__ . '/includes/countries.php';

$countryCode = '';
if (isset($_GET['country']) && (string) $_GET['country'] !== '') {
    $countryCode = orange_countries_normalize_code((string) $_GET['country']);
}
if ($countryCode === '') {
    $countryCode = orange_storefront_current_country_code($pdo);
}
if ($countryCode === '') {
    $saved = orange_storefront_read_saved_country_code();
    if ($saved !== null) {
        $countryCode = $saved;
    }
}

$lang = current_lang();
$startKey = $countryCode !== '' ? $countryCode : 'store';
$startUrl = storefront_url('home', $startKey, $lang);
$scope = PUBLIC_BASE_PATH === '' ? '/' : rtrim(PUBLIC_BASE_PATH, '/') . '/';

/* لون PWA موحّد — اسم التطبيق من علامة المتجر (س32: دولة لا قناة) */
$themeColor = '#ff6a00';
$fullName = t('storefront_brand');
if ($countryCode !== '') {
    $crow = orange_country_row_by_code($pdo, $countryCode, false);
    if ($crow !== null) {
        $label = orange_country_label_from_row($crow, $lang);
        if ($label !== '') {
            $fullName = $fullName . ' — ' . $label;
        }
    }
}

$shortName = $fullName;
if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($shortName, 'UTF-8') > 12) {
        $shortName = mb_substr($shortName, 0, 12, 'UTF-8') . '…';
    }
} elseif (strlen($shortName) > 12) {
    $shortName = substr($shortName, 0, 12) . '…';
}

/** روابط مطلقة: Chrome/Edge على الويندوز يعتمدون عليها لأيقونة التثبيت والاختصار */
$pwaIconSrc = static function (string $file): string {
    $rel = storefront_public_path(storefront_asset_url('/assets/images/' . $file));

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
