<?php

declare(strict_types=1);

/**
 * Storefront HTML header (home, product, cart, track).
 * Requires config.php already loaded by the page.
 */
if (!function_exists('current_lang')) {
    require_once __DIR__ . '/../config.php';
}
orange_send_html_no_cache_headers();

extract(storefront_toolbar_state());

$taglineCycle = storefront_tagline_cycle_messages();
$taglineInitial = $taglineCycle[0] ?? '';
$taglineJsonAttr = htmlspecialchars(json_encode($taglineCycle, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

$theme = preg_replace('/[^a-z0-9\-]/i', '', (string)($channel['slug'] ?? 'orange'));
if ($theme === '' || !is_file(__DIR__ . '/../assets/css/theme-' . $theme . '.css')) {
    $theme = 'orange';
}

/* لون شريط المتصفح/PWA — موحّد مع حزمة البراند (#FF6A00) لكل القنوات */
$sfThemeColor = '#ff6a00';

$orangeAccountChannelForJs = '';
try {
    require_once __DIR__ . '/storefront_account.php';
    $pdoNavAcc = db();
    $accNav = current_storefront_account($pdoNavAcc);
    if ($accNav && ($accNav['registered_channel_slug'] ?? '') !== '') {
        $orangeAccountChannelForJs = (string) $accNav['registered_channel_slug'];
    }
} catch (Throwable $e) {
    $orangeAccountChannelForJs = '';
}

$orangePubBase = PUBLIC_BASE_PATH === '' ? '' : PUBLIC_BASE_PATH;
$orangeChannelLogoFile = preg_replace(
    '/[^a-z0-9._\-]/i',
    '',
    (string) ($channel['logo'] ?? 'logo-orange.png')
) ?: 'logo-orange.png';
/* نفس منطق CSS/JS: ?v=filemtime حتى لا يبقى الكمبيوتر على شعار PNG قديم بعد التحديث */
$orangeChannelLogoUrl = $orangePubBase . storefront_asset_url('/assets/images/' . $orangeChannelLogoFile);
$orangeWordmarkUrl = $orangePubBase . storefront_asset_url('/assets/images/orange-company-wordmark.png');
$orangeManifestHref = $orangePubBase . '/manifest.php?' . http_build_query(['channel' => $channelSlug, 'lang' => $lang]);

$dir = $lang === 'ar' ? 'rtl' : 'ltr';
?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang, ENT_QUOTES, 'UTF-8'); ?>" dir="<?php echo $dir === 'rtl' ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="theme-color" content="<?php echo htmlspecialchars($sfThemeColor, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="manifest" href="<?php echo htmlspecialchars($orangeManifestHref, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($orangeChannelLogoUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <script>
    (function orangeStorefrontApplySavedChannel() {
        try {
            var params = new URLSearchParams(window.location.search || '');
            if (params.get('channel')) {
                return;
            }
            var allowed = { orange: 1, blue: 1, black: 1 };
            var accountCh = <?php echo json_encode($orangeAccountChannelForJs, JSON_UNESCAPED_UNICODE); ?>;
            var savedCh = '';
            if (accountCh && allowed[String(accountCh).toLowerCase()]) {
                savedCh = String(accountCh).replace(/[^a-z0-9\-]/gi, '').toLowerCase();
            } else {
                var raw = localStorage.getItem('orange_storefront_channel') || '';
                savedCh = String(raw).replace(/[^a-z0-9\-]/gi, '').toLowerCase();
            }
            if (!savedCh || !allowed[savedCh]) {
                return;
            }
            var __sfBase = <?php echo json_encode(PUBLIC_BASE_PATH, JSON_UNESCAPED_UNICODE); ?> || '';
            var rawPath = window.location.pathname || '';
            var path = rawPath;
            if (__sfBase && path.indexOf(__sfBase) === 0) {
                path = path.slice(__sfBase.length) || '/';
            }
            if (!path || path.charAt(0) !== '/') {
                path = '/' + (path || '');
            }
            var navLang = localStorage.getItem('orange_storefront_lang') || localStorage.getItem('site_lang') || 'en';
            if (!/^(en|ar|fil|hi)$/.test(navLang)) {
                navLang = 'en';
            }
            function orangeSegForChannel(ch) {
                if (ch === 'black') { return 'web'; }
                if (ch === 'blue') { return 'online'; }
                return 'tiktok';
            }
            function orangeSuffixForLang(lang) {
                if (lang === 'ar') { return '-ar'; }
                if (lang === 'hi') { return '-hi'; }
                if (lang === 'fil') { return '-ph'; }
                return '';
            }
            function orangeParseShortStorePath(pathname) {
                var m = String(pathname).match(/^\/(web|online|tiktok)(-ar|-hi|-ph)?(\/.*)?$/i);
                if (!m) { return null; }
                var seg = m[1].toLowerCase();
                var ch = seg === 'web' ? 'black' : (seg === 'online' ? 'blue' : 'orange');
                var suff = m[2] || '';
                var tail = m[3] || '';
                return { ch: ch, tail: tail };
            }
            if (path.indexOf('/pages/') !== -1) {
                params.set('channel', savedCh);
                params.set('lang', navLang);
                var qs = params.toString();
                window.location.replace(rawPath + (qs ? '?' + qs : ''));
                return;
            }
            var short = orangeParseShortStorePath(path);
            if (short && short.ch !== savedCh) {
                var np = __sfBase + '/' + orangeSegForChannel(savedCh) + orangeSuffixForLang(navLang) + (short.tail || '');
                np = np.replace(/\/{2,}/g, '/');
                window.location.replace(np + (window.location.search || ''));
            }
        } catch (e) {}
    })();
    </script>
    <?php
    $orangeHeadTitle = isset($ORANGE_STOREFRONT_PAGE_TITLE) && (string) $ORANGE_STOREFRONT_PAGE_TITLE !== ''
        ? (string) $ORANGE_STOREFRONT_PAGE_TITLE
        : t('storefront_brand');
    $orangeHeadDesc = isset($ORANGE_STOREFRONT_META_DESCRIPTION) ? trim((string) $ORANGE_STOREFRONT_META_DESCRIPTION) : '';
    $orangeCanonical = isset($ORANGE_STOREFRONT_CANONICAL_URL) ? trim((string) $ORANGE_STOREFRONT_CANONICAL_URL) : '';
    $orangeOgImage = isset($ORANGE_STOREFRONT_OG_IMAGE) ? trim((string) $ORANGE_STOREFRONT_OG_IMAGE) : '';
    $orangeOgType = isset($ORANGE_STOREFRONT_OG_TYPE) && (string) $ORANGE_STOREFRONT_OG_TYPE !== ''
        ? (string) $ORANGE_STOREFRONT_OG_TYPE
        : 'website';
    ?>
    <title><?php echo htmlspecialchars($orangeHeadTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php if ($orangeHeadDesc !== ''): ?>
    <meta name="description" content="<?php echo htmlspecialchars($orangeHeadDesc, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <?php if ($orangeCanonical !== ''): ?>
    <link rel="canonical" href="<?php echo htmlspecialchars($orangeCanonical, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <meta property="og:title" content="<?php echo htmlspecialchars($orangeHeadTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($orangeHeadDesc !== ''): ?>
    <meta property="og:description" content="<?php echo htmlspecialchars($orangeHeadDesc, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <meta property="og:type" content="<?php echo htmlspecialchars($orangeOgType, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($orangeCanonical !== ''): ?>
    <meta property="og:url" content="<?php echo htmlspecialchars($orangeCanonical, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <?php if ($orangeOgImage !== ''): ?>
    <meta property="og:image" content="<?php echo htmlspecialchars($orangeOgImage, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(storefront_asset_url('/assets/css/main.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(storefront_asset_url('/assets/css/theme-' . $theme . '.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <script>
        window.APP_LANG = <?php echo json_encode($lang, JSON_UNESCAPED_UNICODE); ?>;
        window.APP_TAGLINE_CYCLE = <?php echo json_encode($taglineCycle, JSON_UNESCAPED_UNICODE); ?>;
        window.APP_CHANNEL_ID = <?php echo (int)($channel['id'] ?? 0); ?>;
        window.APP_CHANNEL_SLUG = <?php echo json_encode($channelSlug, JSON_UNESCAPED_UNICODE); ?>;
        window.ORANGE_ACCOUNT_CHANNEL = <?php echo json_encode($orangeAccountChannelForJs, JSON_UNESCAPED_UNICODE); ?>;
        window.STOREFRONT_BASE = <?php echo json_encode(PUBLIC_BASE_PATH, JSON_UNESCAPED_UNICODE); ?>;
        window.orangeSfCartKey = function () {
            var ch = (typeof window.APP_CHANNEL_SLUG === 'string' && window.APP_CHANNEL_SLUG) ? window.APP_CHANNEL_SLUG : 'orange';
            ch = String(ch).replace(/[^a-z0-9\-]/gi, '').toLowerCase();
            if (!ch) {
                ch = 'orange';
            }
            if (ch !== 'orange' && ch !== 'blue' && ch !== 'black') {
                ch = 'orange';
            }
            return 'orange_sf_cart_' + ch;
        };
        (function orangeStorefrontPersistPrefs() {
            try {
                var accCh = (typeof window.ORANGE_ACCOUNT_CHANNEL === 'string') ? window.ORANGE_ACCOUNT_CHANNEL : '';
                var ch = (accCh && /^[a-z0-9\-]+$/i.test(accCh)) ? accCh : window.APP_CHANNEL_SLUG;
                if (ch) {
                    localStorage.setItem('orange_storefront_channel', String(ch).replace(/[^a-z0-9\-]/gi, '').toLowerCase());
                }
                if (window.APP_LANG) {
                    localStorage.setItem('orange_storefront_lang', String(window.APP_LANG));
                }
            } catch (e) {}
        })();
        window.APP_T = {
            empty_cart: <?php echo json_encode(t('empty_cart'), JSON_UNESCAPED_UNICODE); ?>,
            cart_empty_subtitle: <?php echo json_encode(t('cart_empty_subtitle'), JSON_UNESCAPED_UNICODE); ?>,
            cart_remove_confirm: <?php echo json_encode(t('cart_remove_confirm'), JSON_UNESCAPED_UNICODE); ?>,
            item_removed_from_cart: <?php echo json_encode(t('item_removed_from_cart'), JSON_UNESCAPED_UNICODE); ?>,
            color: <?php echo json_encode(t('color'), JSON_UNESCAPED_UNICODE); ?>,
            size: <?php echo json_encode(t('size'), JSON_UNESCAPED_UNICODE); ?>,
            quantity: <?php echo json_encode(t('quantity'), JSON_UNESCAPED_UNICODE); ?>,
            order_number: <?php echo json_encode(t('order_number'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_queue_wait: <?php echo json_encode(t('checkout_queue_wait'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_queue_timeout: <?php echo json_encode(t('checkout_queue_timeout'), JSON_UNESCAPED_UNICODE); ?>,
            track_missing_fields: <?php echo json_encode(t('track_missing_fields'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_required_fields: <?php echo json_encode(t('checkout_required_fields'), JSON_UNESCAPED_UNICODE); ?>,
            select_color: <?php echo json_encode(t('select_color'), JSON_UNESCAPED_UNICODE); ?>,
            select_size: <?php echo json_encode(t('select_size'), JSON_UNESCAPED_UNICODE); ?>,
            added: <?php echo json_encode(t('added'), JSON_UNESCAPED_UNICODE); ?>,
            out_of_stock: <?php echo json_encode(t('out_of_stock'), JSON_UNESCAPED_UNICODE); ?>,
            low_stock: <?php echo json_encode(t('low_stock'), JSON_UNESCAPED_UNICODE); ?>,
            available_max_qty: <?php echo json_encode(t('available_max_qty'), JSON_UNESCAPED_UNICODE); ?>,
            no_more_stock_for_cart: <?php echo json_encode(t('no_more_stock_for_cart'), JSON_UNESCAPED_UNICODE); ?>,
            cart_close: <?php echo json_encode(t('cart_close'), JSON_UNESCAPED_UNICODE); ?>,
            cart_remove: <?php echo json_encode(t('cart_remove'), JSON_UNESCAPED_UNICODE); ?>,
            cart_total_label: <?php echo json_encode(t('cart_total_label'), JSON_UNESCAPED_UNICODE); ?>,
            cart_items_count: <?php echo json_encode(t('cart_items_count'), JSON_UNESCAPED_UNICODE); ?>,
            cart_unit_price: <?php echo json_encode(t('cart_unit_price'), JSON_UNESCAPED_UNICODE); ?>,
            cart_line_subtotal: <?php echo json_encode(t('cart_line_subtotal'), JSON_UNESCAPED_UNICODE); ?>,
            cart_max_available_short: <?php echo json_encode(t('cart_max_available_short'), JSON_UNESCAPED_UNICODE); ?>,
            cart_continue_shopping: <?php echo json_encode(t('cart_continue_shopping'), JSON_UNESCAPED_UNICODE); ?>,
            cart_mini_summary_title: <?php echo json_encode(t('cart_mini_summary_title'), JSON_UNESCAPED_UNICODE); ?>,
            cart_mini_more: <?php echo json_encode(t('cart_mini_more'), JSON_UNESCAPED_UNICODE); ?>
        };
    </script>
</head>
<body class="theme-<?php echo htmlspecialchars($theme, ENT_QUOTES, 'UTF-8'); ?> storefront">
<header class="site-header" dir="ltr">
    <div class="container header-inner">
        <div class="brand-wrap">
            <img class="logo" src="<?php echo htmlspecialchars($orangeChannelLogoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" width="52" height="52" decoding="async" role="presentation">
            <div class="brand-text">
                <div class="brand-stack">
                    <div class="brand-wordmark-anchor">
                        <h1 class="brand-title-heading"><img class="brand-wordmark" src="<?php echo htmlspecialchars($orangeWordmarkUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(t('storefront_brand'), ENT_QUOTES, 'UTF-8'); ?>" decoding="async"></h1>
                        <small class="brand-tagline brand-tagline--cycle" aria-live="polite"><span class="brand-tagline__text" id="brandTaglineText" dir="auto" data-taglines="<?php echo $taglineJsonAttr; ?>"><?php echo htmlspecialchars($taglineInitial, ENT_QUOTES, 'UTF-8'); ?></span></small>
                    </div>
                </div>
            </div>
        </div>
        <div class="header-actions header-actions--toolbar">
            <?php
            $SF_NAV_PLACEMENT = 'header';
            include __DIR__ . '/storefront_nav_cluster.php';
            ?>
        </div>
    </div>
</header>
<main class="site-main">
