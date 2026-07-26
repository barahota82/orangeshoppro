<?php

declare(strict_types=1);

/**
 * Phase 3 Step 4 — Channel country resolution (GAP-C1 / GAP-C2).
 *
 * Usage: php scripts/self_test_admin_time_phase3_step4_channel_resolution.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function ch_c_assert(bool $ok, string $label): void
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

$config = (string) file_get_contents($root . '/config.php');
$countries = (string) file_get_contents($root . '/includes/countries.php');
$cartPromo = (string) file_get_contents($root . '/includes/cart_promotion_country.php');
$spm = (string) file_get_contents($root . '/includes/storefront_promo_messages.php');
$home = (string) file_get_contents($root . '/pages/home.php');
$sfPolicy = (string) file_get_contents($root . '/docs/archive/ORANGE_STOREFRONT_POLICY_REFERENCE.txt');
$adminPolicy = (string) file_get_contents($root . '/docs/archive/ORANGE_ADMIN_TIME_POLICY.txt');

ch_c_assert(str_contains($config, 'function orange_storefront_request_channel_country_state'), 'channel state helper');
ch_c_assert(str_contains($config, 'SELECT country_id FROM channels WHERE slug = ?'), '1-4. channel DB country_id');
ch_c_assert(str_contains($config, 'SELECT country_id FROM channels WHERE path_segment = ?'), 'path_segment country');

// Precedence: channel before cookie; no GET country authority in current_country_code
$fnStart = strpos($countries, 'function orange_storefront_current_country_code');
ch_c_assert($fnStart !== false, 'current_country_code exists');
$fnBody = $fnStart !== false ? substr($countries, $fnStart, 1800) : '';
ch_c_assert(str_contains($fnBody, 'orange_storefront_request_channel_country_state')
    || str_contains($fnBody, 'orange_storefront_country_code_from_request_channel'), 'channel before other sources');
ch_c_assert(!preg_match('/\$_GET\s*\[\s*[\'"]country[\'"]\s*\]/', $fnBody), '5-6. no $_GET[country] authority in current_country_code');
ch_c_assert(str_contains($fnBody, "\$memo = ''") || str_contains($fnBody, '$memo = "";'), '10-14. fail-closed empty memo');
ch_c_assert(!str_contains($fnBody, "orange_country_row_by_code(\$pdo, 'kw'")
    && !str_contains($fnBody, "orange_countries_storefront_active"), '10. no kw/first-active fallback in resolver');

ch_c_assert(str_contains($countries, "\$memo = 0") && str_contains($countries, 'function orange_storefront_current_country_id'), '14. current_country_id can be 0');
ch_c_assert(
    preg_match('/function orange_storefront_current_country_id[\s\S]{0,500}orange_countries_default_id/', $countries) !== 1,
    '10. current_country_id no default_id fallback'
);

// disambiguate no default Kuwait
$dis = strpos($config, 'function orange_storefront_disambiguate_channel_country_ids');
$disBody = $dis !== false ? substr($config, $dis, 900) : '';
ch_c_assert(str_contains($disBody, 'return 0') && !str_contains($disBody, 'orange_countries_default_id'), 'ambiguous channel → 0 not default');

// country switch → trusted main channel
$sw = strpos($config, 'function storefront_country_switch_href');
$swBody = $sw !== false ? substr($config, $sw, 900) : '';
ch_c_assert(str_contains($swBody, 'orange_storefront_main_channel_slug_for_country'), 'switch uses trusted main channel');
ch_c_assert(!str_contains($swBody, "params['country']") && !str_contains($swBody, 'country\' =>'), 'switch no ?country= inject');

// SQL fragments
ch_c_assert(
    str_contains($countries, 'AND 1=0')
    && preg_match('/function orange_sql_country_and_fragment[\s\S]{0,400}IS NULL/', $countries) !== 1,
    '15. sql_country_and_fragment no NULL-as-KW; fail-closed'
);
ch_c_assert(
    preg_match('/function orange_sql_filter_storefront_row_belongs_to_country[\s\S]{0,800}IS NULL OR/', $countries) !== 1,
    '15-18. storefront row filter no NULL OR'
);

// Cart promo admin
ch_c_assert(
    str_contains($cartPromo, 'return $cid > 0 ? $cid : 0')
    || (str_contains($cartPromo, 'orange_admin_context_country_id') && !str_contains($cartPromo, 'orange_countries_default_id')),
    'cart_promotion_admin_country_id no silent default_id'
);

// SPM / home still channel country
ch_c_assert(str_contains($spm, 'AND country_id = ?'), '17. SPM evaluator country_id = ?');
ch_c_assert(str_contains($home, 'orange_storefront_current_country_id'), '15. home uses storefront country id');
ch_c_assert(!str_contains($home, 'orange_admin_context_country_id'), 'no admin context on home');

ch_c_assert(str_contains($sfPolicy, 'Channel DB') || str_contains($sfPolicy, 'قناة') || str_contains($sfPolicy, '?country'), 'docs updated for channel authority');
ch_c_assert(str_contains($adminPolicy, '97') || str_contains($adminPolicy, 'Gaps = 0') || str_contains($adminPolicy, 'TARGETED'), 'admin time policy notes repair');

echo "\n--- Channel resolution isolation ---\n";
echo "PASS={$passes} FAIL={$failures}\n";
exit($failures > 0 ? 1 : 0);
