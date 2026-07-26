<?php

declare(strict_types=1);

/**
 * Phase 3 Step 4 — storefront_promo_messages country-scope closure.
 *
 * Usage: php scripts/self_test_admin_time_phase3_step4_spm_country.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function spm_c_assert(bool $ok, string $label): void
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
$policy = (string) file_get_contents($root . '/docs/archive/ORANGE_ADMIN_TIME_POLICY.txt');
$sfPolicy = (string) file_get_contents($root . '/docs/archive/ORANGE_STOREFRONT_POLICY_REFERENCE.txt');

// Operational evaluators: exact country match only
spm_c_assert(substr_count($spmInc, 'country_id IS NULL OR country_id = ?') === 0, '10. no operational IS NULL OR country_id = ?');
spm_c_assert(substr_count($spmInc, 'AND country_id = ?') >= 3, '5/6/7. storefront evaluators use AND country_id = ?');
spm_c_assert(str_contains($spmInc, 'if ($cid <= 0)') && str_contains($spmInc, "return ''"), '8. missing storefront country fail-closed empty');
spm_c_assert(str_contains($spmInc, 'orange_storefront_promo_messages_normalize_null_country_ids'), 'null-normalize helper exists');
spm_c_assert(str_contains($spmInc, 'blocked_ambiguous'), 'multi-active NULL → blocked_ambiguous flag');

// Writers
spm_c_assert(str_contains($spmApi, 'orange_admin_context_country_id'), '1/2/3. save uses Current Country Context');
spm_c_assert(!str_contains($spmApi, 'orange_cart_promotion_admin_country_id'), '3/11. no default-country fallback helper in SPM manage');
spm_c_assert(str_contains($spmApi, 'country_context_required'), '3. create/delete without context fails with code');
spm_c_assert(str_contains($spmApi, '$countryToStore = $exCid'), '4/12. update preserves record country');
spm_c_assert(str_contains($spmApi, 'WHERE country_id = ?'), '1. insert sort scoped by country_id = ?');
spm_c_assert(!str_contains($spmApi, 'country_id IS NULL OR country_id = ?'), '10. manage.php no NULL OR sort/list filter');

// Admin list — Current Country only (no multi-country overview)
spm_c_assert(str_contains($spmInc, 'WHERE country_id = ?'), 'admin list country filter country_id = ?');
spm_c_assert(!str_contains($spmInc, 'country_id IS NOT NULL AND country_id > 0'), 'no all-country admin overview query');
spm_c_assert(str_contains($spmApi, "action === 'list'") && str_contains($spmApi, 'country_context_required'), 'list without context fails closed');

// SPM_IS_GLOBAL UI only
spm_c_assert(str_contains($spmPage, 'SPM_IS_GLOBAL'), '11. SPM_IS_GLOBAL present as UI mode');
spm_c_assert(str_contains($spmPage, 'لا يلغي فلتر دولة'), '11. page documents SPM_IS_GLOBAL does not drop country filter');
spm_c_assert(str_contains($spmPage, 'بلا دولة') && !str_contains($spmPage, "return 'كل الدول'"), '9/11. UI does not label NULL as all-countries');

// Docs / matrix
spm_c_assert(str_contains($policy, 'COUNTRY_SCOPED') && str_contains($policy, 'country_id = ?'), 'matrix/docs COUNTRY_SCOPED');
spm_c_assert(str_contains($sfPolicy, 'تنفيذ (ملكية الدولة — 2026-07-26)'), 'storefront policy updated');
spm_c_assert(str_contains($policy, 'NO EXPLICIT GLOBAL OR MIXED CUSTOMER/STOREFRONT USER SURFACE FOUND'), 'no global customer surface');

// Permanent / schedule paths unchanged
spm_c_assert(str_contains($spmInc, 'is_always_on = 1 OR'), '13. permanent short-circuit preserved');
spm_c_assert(str_contains($spmInc, 'UTC_TIMESTAMP()'), '15. valid_from/to UTC_TIMESTAMP preserved');
spm_c_assert(str_contains($spmApi, 'spm_iso_or_null'), '15. schedule IANA→UTC writer preserved');

// Schema freeze signals
spm_c_assert(!str_contains($spmInc, 'is_global'), 'no is_global on SPM');
spm_c_assert(!str_contains($spmApi, 'ORANGE_CATALOG_SCHEMA_PHP_REVISION'), 'no schema bump in manage');

echo "\n--- SPM country-scope closure ---\n";
echo "PASS={$passes} FAIL={$failures}\n";
echo "NOTE: Local DB count of NULL setup rows requires server .env.php; normalize is idempotent at runtime.\n";
exit($failures > 0 ? 1 : 0);
