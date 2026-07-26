<?php

declare(strict_types=1);

/**
 * Pre-Phase-4 — Product Preview country/channel authority.
 *
 * Usage: php scripts/self_test_pre_phase4_product_preview_country_channel.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function pp_assert(bool $ok, string $label): void
{
    global $failures, $passes;
    if ($ok) {
        echo "PASS  {$label}\n";
        $passes++;
    } else {
        echo "FAIL  {$label}\n";
        $failures++;
    }
}

$products = (string) file_get_contents($root . '/admin/pages/products.php');
$api = (string) file_get_contents($root . '/admin/api/products/save-preview-draft.php');
$previewLib = (string) file_get_contents($root . '/includes/product_preview.php');
$countries = (string) file_get_contents($root . '/includes/countries.php');

pp_assert(!str_contains($products, 'orange_countries_storefront_active'), 'UI no longer uses storefront_active for preview country');
pp_assert(str_contains($products, 'orangeFullPreviewCountryLabel'), 'read-only preview country label');
pp_assert(str_contains($products, 'orangeFullPreviewChannel'), 'channel selector present');
pp_assert(str_contains($products, 'لا توجد قناة لهذه الدولة'), 'zero-channel message in UI');
pp_assert(str_contains($products, 'معاينة سريعة'), 'quick preview wording');
pp_assert(str_contains($products, 'orange_preview_channels_for_country'), 'channels loaded via country helper');
pp_assert(
    str_contains($products, 'orangePreviewMainChannelSlug')
    || str_contains($products, "'channel' => \$orangePreviewMainChannelSlug"),
    'live helper uses same-country channel slug'
);
pp_assert(!preg_match(
    '/ORANGE_ADMIN_SF_PRODUCT_URL_PARTS[\s\S]{0,200}orange_storefront_default_channel_slug\(\$pdo\)\s*;/',
    $products
), 'no default_channel_slug without country for SF parts');

pp_assert(str_contains($api, 'preview_country_mismatch'), 'API rejects cross-country product');
pp_assert(str_contains($api, 'no_channel_for_country'), 'API 422 when zero channels');
pp_assert(str_contains($api, 'preview_channel_required'), 'API requires explicit channel when no main');
pp_assert(str_contains($api, 'unset($data[\'preview_country_id\'])')
    || str_contains($api, 'لا ثقة بـ preview_country_id'), 'client preview_country_id not trusted');
pp_assert(!preg_match(
    '/SELECT slug FROM channels WHERE is_active = 1 ORDER BY id ASC LIMIT 1/',
    $api
), 'no global first-active channel fallback');
pp_assert(str_contains($api, 'orange_preview_resolve_channel_for_country'), 'channel resolved server-side');
pp_assert(str_contains($api, 'orange_preview_set_session($adminId, $previewCountryId, $draftId, 86400, $resolvedChannelId)'),
    'session stores channel_id');

pp_assert(str_contains($previewLib, 'function orange_preview_channels_for_country'), 'channels helper');
pp_assert(str_contains($previewLib, 'function orange_preview_resolve_channel_for_country'), 'resolve helper');
pp_assert(str_contains($previewLib, "'channel_id'"), 'session includes channel_id');

$storeFn = strpos($countries, 'function orange_admin_store_country_context');
$storeBody = $storeFn !== false ? substr($countries, $storeFn, 1200) : '';
pp_assert(
    str_contains($storeBody, 'orange_product_preview')
    || str_contains($storeBody, 'orange_preview_clear_session'),
    'admin country switch clears stale preview session'
);

echo "\n--- summary ---\n";
echo "PASS={$passes} FAIL={$failures}\n";
exit($failures > 0 ? 1 : 0);
