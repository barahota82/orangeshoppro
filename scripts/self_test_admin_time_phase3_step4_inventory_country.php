<?php

declare(strict_types=1);

/**
 * Phase 3 Step 4 — Inventory NULL-country isolation (screens 17/41/42).
 *
 * Usage: php scripts/self_test_admin_time_phase3_step4_inventory_country.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function inv_c_assert(bool $ok, string $label): void
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

$stk = (string) file_get_contents($root . '/includes/stock_adjustment_voucher.php');
$osv = (string) file_get_contents($root . '/includes/opening_stock_voucher.php');
$ir = (string) file_get_contents($root . '/includes/inventory_reconciliation.php');
$wh = (string) file_get_contents($root . '/includes/warehouses.php');
$policy = (string) file_get_contents($root . '/docs/archive/ORANGE_ADMIN_TIME_POLICY.txt');

foreach (['stock_adjustment' => $stk, 'opening_stock' => $osv, 'inventory_recon' => $ir] as $name => $src) {
    inv_c_assert(
        substr_count($src, 'country_id IS NULL OR country_id = ?') === 0
        && substr_count($src, 'sv.country_id IS NULL OR') === 0
        && substr_count($src, 'ir.country_id IS NULL OR') === 0,
        "1-6. {$name}: no IS NULL OR country_id = ? in ordinary paths"
    );
}

inv_c_assert(str_contains($stk, 'AND sv.country_id = ?'), '1. stock adj list/search country_id = ?');
inv_c_assert(str_contains($stk, "AND country_id = ?"), '1. stock adj nav country_id = ?');
inv_c_assert(str_contains($osv, 'AND sv.country_id = ?') || str_contains($osv, 'AND country_id = ?'), '1. opening stock scoped');
inv_c_assert(str_contains($ir, 'AND ir.country_id = ?'), '1. inventory recon list scoped');

inv_c_assert(
    str_contains($stk, 'rowCid <= 0 || $rowCid !== $countryId')
    || (str_contains($stk, '$rowCid <= 0') && str_contains($stk, 'return null')),
    '9. stock adj get rejects NULL/mismatch'
);
inv_c_assert(
    str_contains($osv, '$rowCid <= 0 || $rowCid !== $countryId'),
    '9. opening stock get rejects NULL/mismatch'
);
inv_c_assert(
    str_contains($ir, '$rowCid <= 0 || $rowCid !== $countryId'),
    '9. inventory recon get rejects NULL/mismatch'
);

inv_c_assert(
    str_contains($stk, 'DELETE FROM stock_adjustment_voucher')
    && str_contains($stk, 'country_id = ?')
    && preg_match('/DELETE FROM stock_adjustment_voucher[\s\S]{0,120}country_id/', $stk) === 1,
    '11. delete draft scoped by country_id'
);

inv_c_assert(str_contains($wh, 'function orange_inventory_normalize_null_country_ids'), '13-15. normalize helper exists');
inv_c_assert(str_contains($wh, 'from_warehouse') && str_contains($wh, 'blocked_ambiguous'), '13-15. warehouse parent + blocked_ambiguous');
inv_c_assert(str_contains($stk, 'orange_inventory_normalize_null_country_ids'), '13. stock list calls normalize');
inv_c_assert(str_contains($osv, 'orange_inventory_normalize_null_country_ids'), '13. opening stock calls normalize');
inv_c_assert(str_contains($ir, 'orange_inventory_normalize_null_country_ids'), '13. recon list calls normalize');

// Business freeze signals
inv_c_assert(str_contains($stk, 'orange_inventory_reconciliation_variant_unit_cost') || str_contains($stk, 'unit_cost'), '16. costing path still present');
inv_c_assert(str_contains($stk, 'journal_voucher') || str_contains($stk, 'orange_gl'), '16. GL path still present');
inv_c_assert(!str_contains($stk, 'ORANGE_CATALOG_SCHEMA_PHP_REVISION'), 'no schema bump in stock adj');

inv_c_assert(
    str_contains($policy, 'قيد تسوية مخزون') || str_contains($policy, 'Inventory NULL') || str_contains($policy, 'stock_adjustment'),
    'docs mention inventory country closure'
);

echo "\n--- Inventory country isolation ---\n";
echo "PASS={$passes} FAIL={$failures}\n";
exit($failures > 0 ? 1 : 0);
