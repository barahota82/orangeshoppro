<?php

declare(strict_types=1);

/**
 * Storefront HTML header (home, product, cart, track).
 * Requires config.php already loaded by the page.
 */
if (!function_exists('current_lang')) {
    require_once __DIR__ . '/../config.php';
}

$lang = current_lang();
$slug = current_channel_slug();
$channel = get_channel_by_slug($slug);
if (!$channel) {
    $channel = [
        'id' => 0,
        'name' => 'Orange',
        'slug' => $slug !== '' ? $slug : 'orange',
        'logo' => 'logo-orange.png',
    ];
}

$theme = preg_replace('/[^a-z0-9\-]/i', '', (string)($channel['slug'] ?? 'orange'));
if ($theme === '' || !is_file(__DIR__ . '/../assets/css/theme-' . $theme . '.css')) {
    $theme = 'orange';
}

$dir = $lang === 'ar' ? 'rtl' : 'ltr';
$channelSlug = (string)($channel['slug']);
$pageKind = storefront_current_page_kind();
$storefrontExtra = [];
if ($pageKind === 'product' && isset($_GET['id'])) {
    $storefrontExtra['id'] = (int)$_GET['id'];
}
?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang, ENT_QUOTES, 'UTF-8'); ?>" dir="<?php echo $dir === 'rtl' ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars(t('storefront_brand'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/theme-<?php echo htmlspecialchars($theme, ENT_QUOTES, 'UTF-8'); ?>.css">
    <script>
        window.APP_LANG = <?php echo json_encode($lang, JSON_UNESCAPED_UNICODE); ?>;
        window.APP_CHANNEL_ID = <?php echo (int)($channel['id'] ?? 0); ?>;
        window.APP_T = {
            empty_cart: <?php echo json_encode(t('empty_cart'), JSON_UNESCAPED_UNICODE); ?>,
            color: <?php echo json_encode(t('color'), JSON_UNESCAPED_UNICODE); ?>,
            size: <?php echo json_encode(t('size'), JSON_UNESCAPED_UNICODE); ?>,
            quantity: <?php echo json_encode(t('quantity'), JSON_UNESCAPED_UNICODE); ?>,
            order_number: <?php echo json_encode(t('order_number'), JSON_UNESCAPED_UNICODE); ?>,
            select_color: <?php echo json_encode(t('select_color'), JSON_UNESCAPED_UNICODE); ?>,
            select_size: <?php echo json_encode(t('select_size'), JSON_UNESCAPED_UNICODE); ?>,
            added: <?php echo json_encode(t('added'), JSON_UNESCAPED_UNICODE); ?>
        };
    </script>
</head>
<body class="theme-<?php echo htmlspecialchars($theme, ENT_QUOTES, 'UTF-8'); ?> storefront">
<header class="site-header">
    <div class="container header-inner">
        <div class="brand-wrap">
            <img class="logo" src="/assets/images/<?php echo htmlspecialchars((string)($channel['logo'] ?? 'logo-orange.png'), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(t('storefront_brand'), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="brand-text">
                <div class="brand-stack">
                    <h1><?php echo htmlspecialchars(t('storefront_brand'), ENT_QUOTES, 'UTF-8'); ?></h1>
                    <small class="brand-tagline brand-tagline--ar" dir="rtl"><?php echo htmlspecialchars(storefront_tagline_ar(), ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
            </div>
        </div>
        <div class="header-actions">
            <nav class="lang-dropdown-nav" aria-label="<?php echo htmlspecialchars(t('language'), ENT_QUOTES, 'UTF-8'); ?>">
                <?php
                $langOpts = storefront_lang_options();
                $currentLangLabel = (string)($langOpts[$lang]['label'] ?? $lang);
                ?>
                <details class="lang-dropdown">
                    <summary class="lang-dropdown__summary">
                        <span class="lang-dropdown__icon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false">
                                <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10Z" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                        </span>
                        <span class="lang-dropdown__current"><?php echo htmlspecialchars($currentLangLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="lang-dropdown__chev" aria-hidden="true"></span>
                    </summary>
                    <ul class="lang-dropdown__list">
                        <?php foreach ($langOpts as $lc => $meta) {
                            $href = storefront_url($pageKind, $channelSlug, $lc, $storefrontExtra);
                            $isActive = $lc === $lang;
                            $label = (string)($meta['label'] ?? $lc);
                            ?>
                        <li>
                            <a class="lang-dropdown__option<?php echo $isActive ? ' is-active' : ''; ?>"
                               href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"
                               hreflang="<?php echo htmlspecialchars($lc, ENT_QUOTES, 'UTF-8'); ?>"
                               <?php echo $isActive ? 'aria-current="true"' : ''; ?>>
                                <span class="lang-dropdown__check" aria-hidden="true"><?php echo $isActive ? '✓' : ''; ?></span>
                                <span class="lang-dropdown__label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                        </li>
                        <?php } ?>
                    </ul>
                </details>
            </nav>
            <a class="icon-btn" href="<?php echo htmlspecialchars(storefront_url('cart', $channelSlug, $lang), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('cart'), ENT_QUOTES, 'UTF-8'); ?></a>
            <a class="icon-btn" href="<?php echo htmlspecialchars(storefront_url('track', $channelSlug, $lang), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('track_order'), ENT_QUOTES, 'UTF-8'); ?></a>
        </div>
    </div>
</header>
<main class="site-main">
