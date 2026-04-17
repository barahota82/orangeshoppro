<?php

declare(strict_types=1);

/**
 * Storefront HTML header (home, product, cart, track).
 * Requires config.php already loaded by the page.
 */
if (!function_exists('current_lang')) {
    require_once __DIR__ . '/../config.php';
}

extract(storefront_toolbar_state());

$theme = preg_replace('/[^a-z0-9\-]/i', '', (string)($channel['slug'] ?? 'orange'));
if ($theme === '' || !is_file(__DIR__ . '/../assets/css/theme-' . $theme . '.css')) {
    $theme = 'orange';
}

$dir = $lang === 'ar' ? 'rtl' : 'ltr';
?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang, ENT_QUOTES, 'UTF-8'); ?>" dir="<?php echo $dir === 'rtl' ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
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
        <div class="header-actions header-actions--toolbar">
            <?php
            $SF_NAV_PLACEMENT = 'header';
            include __DIR__ . '/storefront_nav_cluster.php';
            ?>
        </div>
    </div>
</header>
<main class="site-main">
