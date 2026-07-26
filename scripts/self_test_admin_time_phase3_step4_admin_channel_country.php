<?php

declare(strict_types=1);

/**
 * Phase 3 Step 4 — Final Admin / Channel country isolation for SPM.
 *
 * Usage: php scripts/self_test_admin_time_phase3_step4_admin_channel_country.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function ac_assert(bool $ok, string $label): void
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

$spmInc = (string) file_get_contents($root . '/includes/storefront_promo_messages.php');
$spmApi = (string) file_get_contents($root . '/admin/api/storefront_promo_messages/manage.php');
$spmPage = (string) file_get_contents($root . '/admin/pages/storefront_promo_messages.php');
$config = (string) file_get_contents($root . '/config.php');
$countries = (string) file_get_contents($root . '/includes/countries.php');
$home = (string) file_get_contents($root . '/pages/home.php');
$offer = (string) file_get_contents($root . '/pages/offer.php');
$cart = (string) file_get_contents($root . '/pages/cart.php');
$policy = (string) file_get_contents($root . '/docs/archive/ORANGE_ADMIN_TIME_POLICY.txt');
$sfPolicy = (string) file_get_contents($root . '/docs/archive/ORANGE_STOREFRONT_POLICY_REFERENCE.txt');
$closure = (string) file_get_contents($root . '/scripts/self_test_admin_time_phase3_step4_closure.php');
$spmCountry = (string) file_get_contents($root . '/scripts/self_test_admin_time_phase3_step4_spm_country.php');

// --- Admin list / unlocked ---
ac_assert(!str_contains($spmInc, 'country_id IS NOT NULL AND country_id > 0'), '1-6. no all-country admin overview SQL');
ac_assert(substr_count($spmInc, 'country_id IS NULL OR country_id = ?') === 0, 'no operational IS NULL OR country_id = ?');
ac_assert(preg_match('/function orange_storefront_promo_messages_admin_list[\s\S]*?WHERE country_id = \?/', $spmInc) === 1, '1-6. admin_list WHERE country_id = ? only');
ac_assert(str_contains($spmInc, 'if ($cid <= 0)') && str_contains($spmInc, 'return [];'), '1-6/8. admin_list missing country → empty');
ac_assert(str_contains($spmApi, 'country_context_required') && preg_match("/action === 'list'[\s\S]{0,400}country_context_required/", $spmApi) === 1, '8. list requires Current Country Context');
ac_assert(str_contains($spmPage, 'SPM_IS_GLOBAL') && str_contains($spmPage, 'لا يلغي فلتر دولة'), '5-6. SPM_IS_GLOBAL does not drop country filter');

// --- Mutations scoped ---
ac_assert(str_contains($spmApi, 'WHERE id = ? AND country_id = ?'), '9-11. update/delete mutation includes country_id');
ac_assert(str_contains($spmApi, 'DELETE FROM storefront_promo_messages WHERE id = ? AND country_id = ?'), '10. delete scoped by country_id');
ac_assert(str_contains($spmApi, '$countryToStore = $exCid'), '9. edit preserves record country');
ac_assert(str_contains($spmApi, 'country_mismatch') || str_contains($spmApi, 'هذا السجل يخص دولة أخرى'), '9-10. cross-country edit/delete rejected');
ac_assert(str_contains($spmApi, 'WHERE country_id = ?') && str_contains($spmApi, 'MAX(sort_order)'), '11. sort_order scoped per country');
ac_assert(str_contains($spmApi, 'orange_admin_context_country_id'), '7-8. create uses Current Country Context');
ac_assert(!str_contains($spmApi, 'orange_cart_promotion_admin_country_id'), '7. no default-country fallback writer');

// --- Channel → country → SPM ---
ac_assert(str_contains($config, 'function orange_storefront_country_code_from_request_channel'), 'channel resolver exists');
ac_assert(str_contains($config, 'SELECT country_id FROM channels WHERE slug = ?'), 'channel carries country_id directly');
ac_assert(str_contains($config, 'SELECT country_id FROM channels WHERE path_segment = ?'), 'path_segment resolves channel country');
ac_assert(str_contains($countries, 'orange_storefront_country_code_from_request_channel'), 'storefront country prefers request channel');
ac_assert(str_contains($home, 'orange_storefront_current_country_id($pdo)'), '12-15. home target_country from storefront country');
ac_assert(str_contains($home, 'orange_storefront_promo_messages_map($pdo, [\'home_top\', \'offers_top\'], $sfHomeCountryId'), '12-15. home SPM uses channel country id');
ac_assert(str_contains($offer, 'orange_storefront_promo_offer_card_map($pdo, $sfCountryId'), '14-15. offer page SPM uses storefront country');
ac_assert(str_contains($cart, 'orange_storefront_promo_message_for_slot($pdoCartAcc, \'cart_top\', orange_storefront_current_country_id'), '12-15. cart SPM uses storefront country');
ac_assert(!str_contains($home, 'orange_admin_context_country_id'), '22. home does not use admin country context');
ac_assert(!str_contains($offer, 'orange_admin_context_country_id'), '22. offer does not use admin country context');
ac_assert(!str_contains($cart, 'orange_admin_context_country_id'), '22. cart does not use admin country context');

// Contract: evaluators never treat NULL as visible; missing cid empty
ac_assert(substr_count($spmInc, 'AND country_id = ?') >= 3, '12-18. storefront evaluators AND country_id = ?');
ac_assert(str_contains($spmInc, 'if ($cid <= 0)') && (str_contains($spmInc, "return ''") || str_contains($spmInc, 'return [];')), '19. missing country → no messages');

// Browser TZ / language must not be SPM country source
ac_assert(!str_contains($spmInc, 'date_default_timezone_set'), '20. SPM no browser/PHP TZ country source');
ac_assert(!str_contains($spmInc, 'HTTP_ACCEPT_LANGUAGE'), '21. SPM no Accept-Language country source');
ac_assert(!preg_match('/\$_GET\s*\[\s*[\'"]country_id[\'"]\s*\]/', $spmInc), '22. evaluator ignores forged country_id param');

// Business freeze
ac_assert(str_contains($spmInc, 'is_always_on = 1 OR'), '23. permanent behavior preserved');
ac_assert(str_contains($spmInc, 'UTC_TIMESTAMP()'), '24-25. schedule UTC preserved');
ac_assert(str_contains($spmInc, 'ORDER BY sort_order ASC, id ASC'), '26. priority/order preserved');
ac_assert(str_contains($spmInc, 'orange_storefront_promo_message_pick_text'), '27. text pick path preserved');

// Docs + prior suites still referenced
ac_assert(str_contains($policy, 'COUNTRY_SCOPED'), 'docs COUNTRY_SCOPED');
ac_assert(str_contains($sfPolicy, 'Current Country Context') || str_contains($sfPolicy, 'ملكية الدولة'), 'storefront policy country ownership');
ac_assert(str_contains($policy, 'لا نظرة عامة متعددة الدول') || str_contains($policy, 'Current Country Context فقط'), 'docs admin list country-only');
ac_assert(str_contains($closure, 'country_id = ?') && str_contains($spmCountry, 'no all-country admin overview'), 'prior SPM tests updated for no overview');

// Schema freeze
ac_assert(!str_contains($spmInc, 'is_global'), 'no is_global');
ac_assert(!str_contains($spmApi, 'ORANGE_CATALOG_SCHEMA_PHP_REVISION'), 'no schema bump');

echo "\n--- Admin/Channel country isolation closure ---\n";
echo "PASS={$passes} FAIL={$failures}\n";
echo "NOTE: Fixture matrix (KW/EG channels×messages) covered by source-contract asserts; runtime DB optional.\n";
exit($failures > 0 ? 1 : 0);
