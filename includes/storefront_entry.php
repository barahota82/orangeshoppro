<?php

declare(strict_types=1);

require_once __DIR__ . '/storefront_geo.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/storefront_account.php';

/**
 * دخول الزائر من جذر الموقع (/): Geo → دولة نشطة → القناة الرئيسية؛ وإلا صفحة «غير متاح».
 * مسارات القنوات المباشرة (storefront-dispatch) لا تمرّ من هنا.
 */
function orange_storefront_handle_bare_root(PDO $pdo): void
{
    orange_catalog_ensure_storefront_read_bootstrap($pdo);

    $marketCode = orange_storefront_geo_market_code_for_visitor();
    if ($marketCode === null || $marketCode === '') {
        orange_storefront_render_region_unavailable();
    }

    $row = orange_country_row_by_code($pdo, $marketCode, true);
    if ($row === null) {
        orange_storefront_render_region_unavailable();
    }

    orange_storefront_set_country_override($marketCode);
    if (!headers_sent()) {
        orange_storefront_send_country_cookie($marketCode);
    }

    $countryId = (int) ($row['id'] ?? 0);
    $channelSlug = orange_storefront_main_channel_slug_for_country($pdo, $countryId);
    if ($channelSlug === null || $channelSlug === '') {
        orange_storefront_render_region_unavailable();
    }

    $channelSlug = orange_storefront_valid_channel_slug($pdo, $channelSlug);
    $lang = current_lang();
    header('Location: ' . storefront_url('home', $channelSlug, $lang));
    exit;
}

function orange_storefront_render_region_unavailable(): never
{
    orange_send_html_no_cache_headers();
    require __DIR__ . '/../pages/region-unavailable.php';
    exit;
}
