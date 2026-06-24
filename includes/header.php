<?php

declare(strict_types=1);

/**
 * Storefront HTML header (home, product, cart, track).
 * Requires config.php already loaded by the page.
 */
if (!function_exists('current_lang')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/catalog_schema.php';
/* الترحيل الكامل orange_catalog_ensure_schema() يُستدعى من صفحة الواجهة قبل تضمين هذا الملف (IBRAHIM §2)؛ الهيدر يبقي SET NAMES + bootstrap المتجر الأساسي فقط */
orange_catalog_ensure_storefront_read_bootstrap(db());

/* معاينة المنتج قبل النشر: شارة + noindex على مستوى الموقع كاملاً. لا تكلفة على العميل (لا استعلام بلا كوكي). */
require_once __DIR__ . '/product_preview.php';
$orangePreviewActiveGlobal = orange_preview_is_active(db());

orange_send_html_no_cache_headers();

extract(storefront_toolbar_state());
require_once __DIR__ . '/countries.php';
orange_storefront_send_country_cookie($countryCode ?? orange_storefront_current_country_code(db()));
orange_storefront_send_channel_cookie($channelSlug);
orange_storefront_send_lang_cookie($lang);

$taglineCycle = storefront_tagline_cycle_messages();
$taglineInitial = $taglineCycle[0] ?? '';
$taglineJsonAttr = htmlspecialchars(json_encode($taglineCycle, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

require_once __DIR__ . '/upload_paths.php';

/* ثيم موحّد؛ لون شريط المتصفح من الهوية الافتراضية؛ الشعار من uploads/channels إن وُجد ملف مرفوع */
$theme = 'orange';
$sfDisplayName = storefront_channel_display_name($channel, $channelSlug);
$sfThemeColor = '#ff6a00';

$orangeAccountChannelForJs = '';
$orangeSfLoggedInForJs = false;
try {
    require_once __DIR__ . '/storefront_account.php';
    $pdoNavAcc = db();
    $accNav = current_storefront_account($pdoNavAcc);
    $orangeSfLoggedInForJs = $accNav !== null;
    if ($accNav && ($accNav['registered_channel_slug'] ?? '') !== '') {
        $orangeAccountChannelForJs = (string) $accNav['registered_channel_slug'];
    }
} catch (Throwable $e) {
    $orangeAccountChannelForJs = '';
    $orangeSfLoggedInForJs = false;
}

$orangeChannelLogoUrl = storefront_public_path(storefront_asset_url('/assets/images/logo.webp'));
$orangePwaApple180Url = storefront_public_path(storefront_asset_url('/assets/images/pwa-apple-180.png'));
$orangePwaApple120Url = storefront_public_path(storefront_asset_url('/assets/images/pwa-apple-120.png'));
$orangePwaIcon192Url = storefront_public_path(storefront_asset_url('/assets/images/pwa-icon-192.png'));
$orangePwaIcon512Url = storefront_public_path(storefront_asset_url('/assets/images/pwa-icon-512.png'));
$orangeWordmarkUrl = storefront_public_path(storefront_asset_url(
    storefront_asset_image_preferred_path('/assets/images/orange-company.webp')
));
$orangeManifestHref = storefront_public_path('/manifest.php?' . http_build_query(['channel' => $channelSlug, 'lang' => $lang]));

$pdoSfHdr = db();
[$_sfPathToSlug, $_sfSlugToPath, $_sfValidSlugs, $_sfPathAlt] = orange_storefront_path_maps_for_js($pdoSfHdr);
$orangeSfDefaultCh = orange_storefront_default_channel_slug($pdoSfHdr);
$orangeSfSessionPreviewBypass = orange_storefront_preview_session_matches_request();

$dir = $lang === 'ar' ? 'rtl' : 'ltr';
$orangeSchemaDegradedAttr = (defined('ORANGE_SCHEMA_DEGRADED') && ORANGE_SCHEMA_DEGRADED)
    ? ' data-orange-schema-degraded="1"'
    : '';
?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang, ENT_QUOTES, 'UTF-8'); ?>" dir="<?php echo $dir === 'rtl' ? 'rtl' : 'ltr'; ?>"<?php echo $orangeSchemaDegradedAttr; ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover, interactive-widget=resizes-content">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <?php /* خطوط من HTML وليس @import داخل main.css — يقلّل حظر التصيير وسلسلة الطلبات على أول زيارة */ ?>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&amp;family=Outfit:wght@400;500;600;700&amp;display=swap">
    <link rel="preload" as="image" href="<?php echo htmlspecialchars(storefront_absolute_url($orangeChannelLogoUrl), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="preload" as="image" href="<?php echo htmlspecialchars(storefront_absolute_url($orangeWordmarkUrl), ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="theme-color" content="<?php echo htmlspecialchars($sfThemeColor, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($sfDisplayName, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="application-name" content="<?php echo htmlspecialchars($sfDisplayName, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="manifest" href="<?php echo htmlspecialchars($orangeManifestHref, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo htmlspecialchars($orangePwaIcon192Url, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="icon" type="image/png" sizes="512x512" href="<?php echo htmlspecialchars($orangePwaIcon512Url, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo htmlspecialchars($orangePwaApple180Url, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="apple-touch-icon" sizes="120x120" href="<?php echo htmlspecialchars($orangePwaApple120Url, ENT_QUOTES, 'UTF-8'); ?>">
    <script>
    window.ORANGE_SF_PATH_TO_SLUG = <?php echo json_encode($_sfPathToSlug, JSON_UNESCAPED_UNICODE); ?>;
    window.ORANGE_SF_SLUG_TO_PATH = <?php echo json_encode($_sfSlugToPath, JSON_UNESCAPED_UNICODE); ?>;
    window.ORANGE_SF_VALID_SLUGS = <?php echo json_encode($_sfValidSlugs, JSON_UNESCAPED_UNICODE); ?>;
    window.ORANGE_SF_DEFAULT_CHANNEL_SLUG = <?php echo json_encode($orangeSfDefaultCh, JSON_UNESCAPED_UNICODE); ?>;
    (function orangeStorefrontApplySavedChannel() {
        try {
            var params = new URLSearchParams(window.location.search || '');
            if (params.get('channel')) {
                return;
            }
            var previewTok = <?php echo json_encode(ORANGE_STOREFRONT_PREVIEW_TOKEN, JSON_UNESCAPED_UNICODE); ?>;
            var previewQ = params.get('sf_preview');
            if (previewTok !== '' && previewQ !== null && previewQ === previewTok) {
                return;
            }
            if (<?php echo $orangeSfSessionPreviewBypass ? 'true' : 'false'; ?>) {
                return;
            }
            var allowed = window.ORANGE_SF_VALID_SLUGS || {};
            var ssrLang = <?php echo json_encode(in_array($lang, ['en', 'ar', 'fil', 'hi'], true) ? $lang : 'en', JSON_UNESCAPED_UNICODE); ?>;
            if (!/^(en|ar|fil|hi)$/.test(ssrLang)) {
                ssrLang = 'en';
            }
            var shortPathRe = new RegExp('^\\/(' + <?php echo json_encode($_sfPathAlt, JSON_UNESCAPED_UNICODE); ?> + ')(-ar|-hi|-ph)?(\\/.*)?$', 'i');
            var accountCh = <?php echo json_encode($orangeAccountChannelForJs, JSON_UNESCAPED_UNICODE); ?>;
            function orangeReadSfChannelCookie() {
                var name = '<?php echo htmlspecialchars(orange_storefront_channel_cookie_name(), ENT_QUOTES, 'UTF-8'); ?>=';
                var parts = (document.cookie || '').split(';');
                for (var i = 0; i < parts.length; i++) {
                    var p = parts[i].replace(/^\s+/, '');
                    if (p.indexOf(name) === 0) {
                        return p.slice(name.length).replace(/[^a-z0-9\-]/gi, '').toLowerCase();
                    }
                }
                return '';
            }
            var savedCh = '';
            var accKey = accountCh ? String(accountCh).toLowerCase() : '';
            if (accKey && allowed[accKey]) {
                savedCh = String(accountCh).replace(/[^a-z0-9\-]/gi, '').toLowerCase();
            } else {
                var rawLs = localStorage.getItem('orange_storefront_channel') || '';
                savedCh = String(rawLs).replace(/[^a-z0-9\-]/gi, '').toLowerCase();
                if (!savedCh || !allowed[savedCh]) {
                    var rawCk = orangeReadSfChannelCookie();
                    savedCh = String(rawCk).replace(/[^a-z0-9\-]/gi, '').toLowerCase();
                }
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
            function orangeParseShortStorePath(pathname) {
                var m = String(pathname).match(shortPathRe);
                if (!m) { return null; }
                var seg = m[1].toLowerCase();
                var pmap = window.ORANGE_SF_PATH_TO_SLUG || {};
                var ch = pmap[seg];
                if (!ch) { return null; }
                var tail = m[3] || '';
                return { ch: ch, tail: tail };
            }
            var short = orangeParseShortStorePath(path);
            if (short && short.ch && allowed[short.ch]) {
                return;
            }
            if (path.indexOf('/pages/') !== -1) {
                params.set('channel', savedCh);
                params.set('lang', ssrLang);
                var qs = params.toString();
                window.location.replace(rawPath + (qs ? '?' + qs : ''));
                return;
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
    <?php if (!empty($orangePreviewActiveGlobal)): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
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
    <link rel="stylesheet" href="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/css/main.css')), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/css/theme-' . $theme . '.css')), ENT_QUOTES, 'UTF-8'); ?>">
    <script>
        window.APP_LANG = <?php echo json_encode($lang, JSON_UNESCAPED_UNICODE); ?>;
        window.APP_TAGLINE_CYCLE = <?php echo json_encode($taglineCycle, JSON_UNESCAPED_UNICODE); ?>;
        window.APP_CHANNEL_ID = <?php echo (int)($channel['id'] ?? 0); ?>;
        window.APP_CHANNEL_SLUG = <?php echo json_encode($channelSlug, JSON_UNESCAPED_UNICODE); ?>;
        window.ORANGE_PREVIEW = <?php echo !empty($orangePreviewActiveGlobal) ? 'true' : 'false'; ?>;
        window.APP_COUNTRY_ID = <?php echo (int)($countryId ?? 0); ?>;
        window.ORANGE_SF_CURRENCY_UNIT = <?php echo json_encode(orange_storefront_currency_unit(db(), (int) ($countryId ?? 0)), JSON_UNESCAPED_UNICODE); ?>;
        window.APP_COUNTRY_CODE = <?php echo json_encode($countryCode ?? '', JSON_UNESCAPED_UNICODE); ?>;
        window.APP_COUNTRY_CURRENCY = <?php echo json_encode($countryCurrency ?? '', JSON_UNESCAPED_UNICODE); ?>;
        window.ORANGE_ACCOUNT_CHANNEL = <?php echo json_encode($orangeAccountChannelForJs, JSON_UNESCAPED_UNICODE); ?>;
        window.ORANGE_SF_LOGGED_IN = <?php echo $orangeSfLoggedInForJs ? 'true' : 'false'; ?>;
        window.STOREFRONT_BASE = <?php echo json_encode(PUBLIC_BASE_PATH, JSON_UNESCAPED_UNICODE); ?>;
        window.ORANGE_STOREFRONT_CART_URL = <?php echo json_encode(storefront_url('cart', $channelSlug, $lang), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        window.orangeSfCartKey = function () {
            var allowed = window.ORANGE_SF_VALID_SLUGS || {};
            var def = String(window.ORANGE_SF_DEFAULT_CHANNEL_SLUG || 'tiktok').toLowerCase();
            var ch = (typeof window.APP_CHANNEL_SLUG === 'string' && window.APP_CHANNEL_SLUG) ? window.APP_CHANNEL_SLUG : def;
            ch = String(ch).replace(/[^a-z0-9\-]/gi, '').toLowerCase();
            if (!ch || !allowed[ch]) {
                ch = allowed[def] ? def : (Object.keys(allowed)[0] || def);
            }
            return 'orange_sf_cart_' + ch;
        };
        window.orangeSfPersistChannel = function (rawCh) {
            try {
                var allowed = window.ORANGE_SF_VALID_SLUGS || {};
                var ch = String(rawCh || '').replace(/[^a-z0-9\-]/gi, '').toLowerCase();
                if (!ch || !allowed[ch]) {
                    return;
                }
                localStorage.setItem('orange_storefront_channel', ch);
                var ckName = <?php echo json_encode(orange_storefront_channel_cookie_name(), JSON_UNESCAPED_UNICODE); ?>;
                var ckPath = <?php echo json_encode(orange_storefront_channel_cookie_path(), JSON_UNESCAPED_UNICODE); ?>;
                var maxAge = 3600 * 24 * 400;
                var secure = (typeof window.location !== 'undefined' && window.location.protocol === 'https:') ? '; Secure' : '';
                document.cookie = ckName + '=' + encodeURIComponent(ch) + '; Path=' + ckPath + '; Max-Age=' + maxAge + '; SameSite=Lax' + secure;
            } catch (e) {}
        };
        (function orangeStorefrontPersistPrefs() {
            try {
                var accCh = (typeof window.ORANGE_ACCOUNT_CHANNEL === 'string') ? window.ORANGE_ACCOUNT_CHANNEL : '';
                var ch = (accCh && /^[a-z0-9\-]+$/i.test(accCh)) ? accCh : window.APP_CHANNEL_SLUG;
                if (ch) {
                    window.orangeSfPersistChannel(ch);
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
            checkout_invalid_email: <?php echo json_encode(t('checkout_invalid_email'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_invalid_phone: <?php echo json_encode(t('checkout_invalid_phone'), JSON_UNESCAPED_UNICODE); ?>,
            phone_country_full_international: <?php echo json_encode(t('phone_country_full_international'), JSON_UNESCAPED_UNICODE); ?>,
            phone_country_label: <?php echo json_encode(t('phone_country_label'), JSON_UNESCAPED_UNICODE); ?>,
            phone_country_select_placeholder: <?php echo json_encode(t('phone_country_select_placeholder'), JSON_UNESCAPED_UNICODE); ?>,
            phone_country_open_list: <?php echo json_encode(t('phone_country_open_list'), JSON_UNESCAPED_UNICODE); ?>,
            phone_country_required: <?php echo json_encode(t('phone_country_required'), JSON_UNESCAPED_UNICODE); ?>,
            validation_value_missing: <?php echo json_encode(t('validation_value_missing'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_select_area: <?php echo json_encode(t('checkout_select_area'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_saved_areas_title: <?php echo json_encode(t('checkout_saved_areas_title'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_all_areas_title: <?php echo json_encode(t('checkout_all_areas_title'), JSON_UNESCAPED_UNICODE); ?>,
            delivery_area_open_list: <?php echo json_encode(t('delivery_area_open_list'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_delivery_area_required: <?php echo json_encode(t('checkout_delivery_area_required'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_delivery_areas_unavailable: <?php echo json_encode(t('checkout_delivery_areas_unavailable'), JSON_UNESCAPED_UNICODE); ?>,
            select_color: <?php echo json_encode(t('select_color'), JSON_UNESCAPED_UNICODE); ?>,
            select_size: <?php echo json_encode(t('select_size'), JSON_UNESCAPED_UNICODE); ?>,
            added: <?php echo json_encode(t('added'), JSON_UNESCAPED_UNICODE); ?>,
            out_of_stock: <?php echo json_encode(t('out_of_stock'), JSON_UNESCAPED_UNICODE); ?>,
            low_stock: <?php echo json_encode(t('low_stock'), JSON_UNESCAPED_UNICODE); ?>,
            available_max_qty: <?php echo json_encode(t('available_max_qty'), JSON_UNESCAPED_UNICODE); ?>,
            qty_not_available: <?php echo json_encode(t('qty_not_available'), JSON_UNESCAPED_UNICODE); ?>,
            no_more_stock_for_cart: <?php echo json_encode(t('no_more_stock_for_cart'), JSON_UNESCAPED_UNICODE); ?>,
            cart_close: <?php echo json_encode(t('cart_close'), JSON_UNESCAPED_UNICODE); ?>,
            cart_remove: <?php echo json_encode(t('cart_remove'), JSON_UNESCAPED_UNICODE); ?>,
            cart_total_label: <?php echo json_encode(t('cart_total_label'), JSON_UNESCAPED_UNICODE); ?>,
            cart_subtotal_label: <?php echo json_encode(t('cart_subtotal_label'), JSON_UNESCAPED_UNICODE); ?>,
            cart_promotion_discount_label: <?php echo json_encode(t('cart_promotion_discount_label'), JSON_UNESCAPED_UNICODE); ?>,
            cart_combo_discount_label: <?php echo json_encode(t('cart_combo_discount_label'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_delivery_fee_label: <?php echo json_encode(t('checkout_delivery_fee_label'), JSON_UNESCAPED_UNICODE); ?>,
            cart_gift_promo_title: <?php echo json_encode(t('cart_gift_promo_title'), JSON_UNESCAPED_UNICODE); ?>,
            cart_gift_pick_label: <?php echo json_encode(t('cart_gift_pick_label'), JSON_UNESCAPED_UNICODE); ?>,
            cart_gift_included_fixed: <?php echo json_encode(t('cart_gift_included_fixed'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_gift_pick_required: <?php echo json_encode(t('checkout_gift_pick_required'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_gift_variant_invalid: <?php echo json_encode(t('checkout_gift_variant_invalid'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_gift_out_of_stock: <?php echo json_encode(t('checkout_gift_out_of_stock'), JSON_UNESCAPED_UNICODE); ?>,
            cart_bogo_promo_title: <?php echo json_encode(t('cart_bogo_promo_title'), JSON_UNESCAPED_UNICODE); ?>,
            cart_bogo_pick_label: <?php echo json_encode(t('cart_bogo_pick_label'), JSON_UNESCAPED_UNICODE); ?>,
            cart_bogo_included_fixed: <?php echo json_encode(t('cart_bogo_included_fixed'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_bogo_gift_pick_required: <?php echo json_encode(t('checkout_bogo_gift_pick_required'), JSON_UNESCAPED_UNICODE); ?>,
            cart_gift_register_unlock_teaser: <?php echo json_encode(t('cart_gift_register_unlock_teaser'), JSON_UNESCAPED_UNICODE); ?>,
            cart_bogo_register_unlock_teaser: <?php echo json_encode(t('cart_bogo_register_unlock_teaser'), JSON_UNESCAPED_UNICODE); ?>,
            cart_combo_register_unlock_teaser: <?php echo json_encode(t('cart_combo_register_unlock_teaser'), JSON_UNESCAPED_UNICODE); ?>,
            cart_items_count: <?php echo json_encode(t('cart_items_count'), JSON_UNESCAPED_UNICODE); ?>,
            cart_unit_price: <?php echo json_encode(t('cart_unit_price'), JSON_UNESCAPED_UNICODE); ?>,
            cart_line_subtotal: <?php echo json_encode(t('cart_line_subtotal'), JSON_UNESCAPED_UNICODE); ?>,
            cart_max_available_short: <?php echo json_encode(t('cart_max_available_short'), JSON_UNESCAPED_UNICODE); ?>,
            cart_continue_shopping: <?php echo json_encode(t('cart_continue_shopping'), JSON_UNESCAPED_UNICODE); ?>,
            cart_mini_summary_title: <?php echo json_encode(t('cart_mini_summary_title'), JSON_UNESCAPED_UNICODE); ?>,
            cart_mini_more: <?php echo json_encode(t('cart_mini_more'), JSON_UNESCAPED_UNICODE); ?>,
            cart_order_line_choice_hint: <?php echo json_encode(t('cart_order_line_choice_hint'), JSON_UNESCAPED_UNICODE); ?>,
            cart_include_in_this_order: <?php echo json_encode(t('cart_include_in_this_order'), JSON_UNESCAPED_UNICODE); ?>,
            cart_select_all_lines: <?php echo json_encode(t('cart_select_all_lines'), JSON_UNESCAPED_UNICODE); ?>,
            cart_deselect_all_lines: <?php echo json_encode(t('cart_deselect_all_lines'), JSON_UNESCAPED_UNICODE); ?>,
            cart_no_lines_selected_for_order: <?php echo json_encode(t('cart_no_lines_selected_for_order'), JSON_UNESCAPED_UNICODE); ?>,
            storefront_install_modal_title: <?php echo json_encode(t('storefront_install_modal_title'), JSON_UNESCAPED_UNICODE); ?>,
            storefront_install_modal_intro: <?php echo json_encode(t('storefront_install_modal_intro'), JSON_UNESCAPED_UNICODE); ?>,
            storefront_install_ios_steps: <?php echo json_encode(t('storefront_install_ios_steps'), JSON_UNESCAPED_UNICODE); ?>,
            storefront_install_other_steps: <?php echo json_encode(t('storefront_install_other_steps'), JSON_UNESCAPED_UNICODE); ?>,
            storefront_install_close: <?php echo json_encode(t('storefront_install_close'), JSON_UNESCAPED_UNICODE); ?>,
            storefront_register_mail_failed: <?php echo json_encode(t('storefront_register_mail_failed'), JSON_UNESCAPED_UNICODE); ?>,
            storefront_register_service_unavailable: <?php echo json_encode(t('storefront_register_service_unavailable'), JSON_UNESCAPED_UNICODE); ?>,
            storefront_register_invalid_phone: <?php echo json_encode(t('storefront_register_invalid_phone'), JSON_UNESCAPED_UNICODE); ?>,
            track_signup_order_required: <?php echo json_encode(t('track_signup_order_required'), JSON_UNESCAPED_UNICODE); ?>,
            track_signup_order_mismatch: <?php echo json_encode(t('track_signup_order_mismatch'), JSON_UNESCAPED_UNICODE); ?>,
            track_line_discount_label: <?php echo json_encode(t('track_line_discount_label'), JSON_UNESCAPED_UNICODE); ?>,
            track_email_summary_intro: <?php echo json_encode(t('track_email_summary_intro'), JSON_UNESCAPED_UNICODE); ?>,
            track_email_summary_placeholder: <?php echo json_encode(t('track_email_summary_placeholder'), JSON_UNESCAPED_UNICODE); ?>,
            track_email_summary_send: <?php echo json_encode(t('track_email_summary_send'), JSON_UNESCAPED_UNICODE); ?>,
            track_email_summary_ok: <?php echo json_encode(t('track_email_summary_ok'), JSON_UNESCAPED_UNICODE); ?>,
            track_email_summary_err: <?php echo json_encode(t('track_email_summary_err'), JSON_UNESCAPED_UNICODE); ?>,
            track_email_summary_rate_limit: <?php echo json_encode(t('track_email_summary_rate_limit'), JSON_UNESCAPED_UNICODE); ?>,
            track_share_reference_title: <?php echo json_encode(t('track_share_reference_title'), JSON_UNESCAPED_UNICODE); ?>,
            track_share_reference_hint: <?php echo json_encode(t('track_share_reference_hint'), JSON_UNESCAPED_UNICODE); ?>,
            track_share_reference_copy: <?php echo json_encode(t('track_share_reference_copy'), JSON_UNESCAPED_UNICODE); ?>,
            track_share_reference_copied: <?php echo json_encode(t('track_share_reference_copied'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_cart_items_required: <?php echo json_encode(t('checkout_cart_items_required'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_invalid_channel: <?php echo json_encode(t('checkout_invalid_channel'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_internal_error: <?php echo json_encode(t('checkout_internal_error'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_queue_busy: <?php echo json_encode(t('checkout_queue_busy'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_failed_generic: <?php echo json_encode(t('checkout_failed_generic'), JSON_UNESCAPED_UNICODE); ?>,
            intake_invalid_token: <?php echo json_encode(t('intake_invalid_token'), JSON_UNESCAPED_UNICODE); ?>,
            intake_queue_unavailable: <?php echo json_encode(t('intake_queue_unavailable'), JSON_UNESCAPED_UNICODE); ?>,
            intake_not_found: <?php echo json_encode(t('intake_not_found'), JSON_UNESCAPED_UNICODE); ?>,
            customer_cancel_ok: <?php echo json_encode(t('customer_cancel_ok'), JSON_UNESCAPED_UNICODE); ?>,
            customer_cancel_err: <?php echo json_encode(t('customer_cancel_err'), JSON_UNESCAPED_UNICODE); ?>,
            customer_cancel_not_allowed: <?php echo json_encode(t('customer_cancel_not_allowed'), JSON_UNESCAPED_UNICODE); ?>,
            customer_amend_loaded: <?php echo json_encode(t('customer_amend_loaded'), JSON_UNESCAPED_UNICODE); ?>,
            customer_amend_not_allowed: <?php echo json_encode(t('customer_amend_not_allowed'), JSON_UNESCAPED_UNICODE); ?>,
            customer_amend_phone_mismatch: <?php echo json_encode(t('customer_amend_phone_mismatch'), JSON_UNESCAPED_UNICODE); ?>,
            customer_amend_mode_banner: <?php echo json_encode(t('customer_amend_mode_banner'), JSON_UNESCAPED_UNICODE); ?>,
            customer_amend_send_order: <?php echo json_encode(t('customer_amend_send_order'), JSON_UNESCAPED_UNICODE); ?>,
            send_order: <?php echo json_encode(t('send_order'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_confirm_title: <?php echo json_encode(t('checkout_confirm_title'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_confirm_body: <?php echo json_encode(t('checkout_confirm_body'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_confirm_ok: <?php echo json_encode(t('checkout_confirm_ok'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_confirm_cancel: <?php echo json_encode(t('checkout_confirm_cancel'), JSON_UNESCAPED_UNICODE); ?>,
            checkout_overlay_registered_hint: <?php echo json_encode(t('checkout_overlay_registered_hint'), JSON_UNESCAPED_UNICODE); ?>,
            product_invalid_id: <?php echo json_encode(t('product_invalid_id'), JSON_UNESCAPED_UNICODE); ?>,
            product_not_found: <?php echo json_encode(t('product_not_found'), JSON_UNESCAPED_UNICODE); ?>,
            api_request_failed: <?php echo json_encode(t('api_request_failed'), JSON_UNESCAPED_UNICODE); ?>,
            cart_account_orders_empty: <?php echo json_encode(t('cart_account_orders_empty'), JSON_UNESCAPED_UNICODE); ?>,
            cart_account_auth_required: <?php echo json_encode(t('cart_account_auth_required'), JSON_UNESCAPED_UNICODE); ?>,
            cart_guest_orders_empty: <?php echo json_encode(t('cart_guest_orders_empty'), JSON_UNESCAPED_UNICODE); ?>,
            cart_register_promo_teaser: <?php echo json_encode(t('cart_register_promo_teaser'), JSON_UNESCAPED_UNICODE); ?>,
            cart_register_promo_teaser_action: <?php echo json_encode(t('cart_register_promo_teaser_action'), JSON_UNESCAPED_UNICODE); ?>,
            storefront_merge_wa_not_confirmed: <?php echo json_encode(t('storefront_merge_wa_not_confirmed'), JSON_UNESCAPED_UNICODE); ?>,
            storefront_merge_email_mismatch: <?php echo json_encode(t('storefront_merge_email_mismatch'), JSON_UNESCAPED_UNICODE); ?>,
            storefront_merge_invalid_token: <?php echo json_encode(t('storefront_merge_invalid_token'), JSON_UNESCAPED_UNICODE); ?>,
            storefront_merge_apply_err: <?php echo json_encode(t('storefront_merge_apply_err'), JSON_UNESCAPED_UNICODE); ?>
        };
        window.orangeStorefrontRegisterApiError = function (j, fallback) {
            if (typeof window.orangeCheckoutApiMessage === 'function') {
                var mapped = window.orangeCheckoutApiMessage(j || {});
                if (mapped) {
                    return mapped;
                }
            }
            return (j && j.message) ? String(j.message) : (fallback || '');
        };
    </script>
</head>
<body class="theme-<?php echo htmlspecialchars($theme, ENT_QUOTES, 'UTF-8'); ?> storefront<?php echo !empty($orangePreviewActiveGlobal) ? ' orange-preview-mode' : ''; ?>">
<?php if (!empty($orangePreviewActiveGlobal)): ?>
<div class="orange-preview-banner" role="status" dir="rtl" style="position:sticky;top:0;z-index:9999;background:#b45309;color:#fff;padding:8px 14px;font-size:14px;font-weight:700;text-align:center;box-shadow:0 2px 6px rgba(0,0,0,.25);">
    وضع المعاينة — تتصفّح كعميل. الكارت ذو البرواز الأخضر = منتجك غير المحفوظ، والأصفر = نموذج تجريبي. أي طلب هنا لا يُرسَل فعلياً (معاينة فقط).
    <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/api/products/preview-exit.php'), ENT_QUOTES, 'UTF-8'); ?>" style="color:#fff;text-decoration:underline;margin-inline-start:10px;">إنهاء المعاينة</a>
</div>
<?php endif; ?>
<header class="site-header" dir="ltr">
    <div class="container header-inner">
        <div class="brand-wrap">
            <img class="logo" src="<?php echo htmlspecialchars($orangeChannelLogoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" width="52" height="52" decoding="async" role="presentation">
            <div class="brand-text">
                <div class="brand-stack">
                    <div class="brand-wordmark-anchor">
                        <h1 class="brand-title-heading"><img class="brand-wordmark" src="<?php echo htmlspecialchars($orangeWordmarkUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(t('storefront_brand'), ENT_QUOTES, 'UTF-8'); ?>" width="248" height="32" decoding="async"></h1><small class="brand-tagline brand-tagline--cycle" aria-live="polite"><span class="brand-tagline__text" id="brandTaglineText" dir="auto" data-taglines="<?php echo $taglineJsonAttr; ?>"><?php echo htmlspecialchars($taglineInitial, ENT_QUOTES, 'UTF-8'); ?></span></small>
                    </div>
                </div>
            </div>
        </div>
        <div class="header-actions-cluster">
            <div class="header-actions header-actions--lang">
                <?php
                $SF_NAV_PLACEMENT = 'header';
                $SF_NAV_CLUSTER_PART = 'lang';
                include __DIR__ . '/storefront_nav_cluster.php';
                ?>
            </div>
            <div class="header-actions header-actions--toolbar">
                <?php
                $SF_NAV_PLACEMENT = 'header';
                $SF_NAV_CLUSTER_PART = 'actions';
                include __DIR__ . '/storefront_nav_cluster.php';
                ?>
            </div>
        </div>
    </div>
</header>
<main class="site-main">
