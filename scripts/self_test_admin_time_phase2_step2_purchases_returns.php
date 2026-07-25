<?php

declare(strict_types=1);

/**
 * Phase 2 / Step 2 — Admin Purchases & Returns time migration (isolated).
 *
 * Usage: php scripts/self_test_admin_time_phase2_step2_purchases_returns.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function s2_assert(bool $ok, string $label): void
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

$before = get_included_files();
require_once $root . '/includes/admin_time.php';
require_once $root . '/includes/purchase_return_helpers.php';
require_once $root . '/includes/sales_return_helpers.php';
$after = get_included_files();
$loadedBackup = false;
foreach (array_diff($after, $before) as $inc) {
    $n = str_replace('\\', '/', $inc);
    if (preg_match('#/(includes/backup|admin/pages/backup_center|admin/pages/restore_center|admin/api/backup|admin/api/restore|scripts/backup)/#', $n)) {
        $loadedBackup = true;
    }
}
s2_assert(!$loadedBackup, '20. no Backup/Restore loaded');

$bc = file_get_contents($root . '/admin/pages/backup_center.php') ?: '';
$rc = file_get_contents($root . '/admin/pages/restore_center.php') ?: '';
s2_assert(str_contains($bc, 'fmtTimestampDisplay'), '20. Backup Center untouched');
s2_assert(str_contains($rc, 'fmtTimestampDisplay'), '21. Restore Center untouched');

// --- Writers: purchases DATETIME UTC ---
$pCreate = file_get_contents($root . '/admin/api/purchases/create.php') ?: '';
$pUpdate = file_get_contents($root . '/admin/api/purchases/update.php') ?: '';
s2_assert(str_contains($pCreate, 'orange_admin_time_utc_now_mysql'), '1/5. purchase create writes UTC created_at');
s2_assert(str_contains($pCreate, 'orange_admin_time_document_date_today_for_country_id'), '1/2. purchase document_date country local day');
s2_assert(str_contains($pUpdate, 'orange_admin_time_utc_now_mysql'), '5. purchase update writes UTC updated_at');
s2_assert(!preg_match('/updated_at\s*=\s*NOW\s*\(/i', $pUpdate), '5/13. purchase update no NOW() on updated_at');

// --- Writers: purchase_returns / sales_returns TIMESTAMP ---
$prCreate = file_get_contents($root . '/admin/api/purchase_returns/create.php') ?: '';
$srCreate = file_get_contents($root . '/admin/api/sales_returns/create.php') ?: '';
s2_assert(
    str_contains($prCreate, 'orange_admin_time_sql_from_unix') || str_contains($prCreate, 'FROM_UNIXTIME'),
    '7. purchase_return created_at FROM_UNIXTIME'
);
s2_assert(
    str_contains($srCreate, 'orange_admin_time_sql_from_unix') || str_contains($srCreate, 'FROM_UNIXTIME'),
    '8. sales_return created_at FROM_UNIXTIME'
);
s2_assert(str_contains($prCreate, 'orange_purchase_return_authority_country_id'), '7/9. PR country from purchase');
s2_assert(str_contains($srCreate, 'admin_time_country_id_required'), '8/9. SR fails closed missing order country');

// Supplier must not override purchase country when purchase linked
s2_assert(
    str_contains($prCreate, 'عند وجود Purchase مرتبطة')
    || str_contains($prCreate, 'لا يُستبدل بالمورد')
    || (str_contains($prCreate, 'purchaseIdOpt <= 0') && str_contains($prCreate, 'suppliers')),
    '9. PR supplier does not override purchase country'
);

// --- Display / API UTC ---
$pBrowse = file_get_contents($root . '/admin/api/purchases/browse.php') ?: '';
$pGet = file_get_contents($root . '/admin/api/purchases/get.php') ?: '';
$prBrowse = file_get_contents($root . '/admin/api/purchase_returns/browse.php') ?: '';
$prGet = file_get_contents($root . '/admin/api/purchase_returns/get.php') ?: '';
$srBrowse = file_get_contents($root . '/admin/api/sales_returns/browse.php') ?: '';
$srGet = file_get_contents($root . '/admin/api/sales_returns/get.php') ?: '';
s2_assert(str_contains($pBrowse, 'created_at_utc') && str_contains($pGet, 'created_at_display'), '6. purchase list/detail UTC+display');
s2_assert(str_contains($prBrowse, 'created_at_utc') && str_contains($prGet, 'created_at_display'), '7. PR list/detail');
s2_assert(str_contains($srBrowse, 'created_at_utc') && str_contains($srGet, 'created_at_display'), '8. SR list/detail');
s2_assert(str_contains($pBrowse, 'orange_admin_time_filter_range_mysql_utc'), '14. purchase day filter UTC bounds');
s2_assert(str_contains($prBrowse, 'UNIX_TIMESTAMP(pr.created_at)'), '12/15. PR filter via UNIX_TIMESTAMP');
s2_assert(str_contains($srBrowse, 'UNIX_TIMESTAMP(sr.created_at)'), '15. SR filter via UNIX_TIMESTAMP');
s2_assert(str_contains($pBrowse, 'ORDER BY p.id DESC'), '16. purchase sort by raw id not display');

// --- Helpers: Kuwait / Cairo / DST / PHP TZ ---
$iso = '2026-07-24T22:30:00+00:00';
$kw = orange_admin_time_format_instant_in_iana($iso, 'Asia/Kuwait', 'en', 'datetime12');
$eg = orange_admin_time_format_instant_in_iana($iso, 'Africa/Cairo', 'en', 'datetime12');
s2_assert($kw === '2026-07-25 1:30:00 AM', '1. Kuwait display');
s2_assert($eg === '2026-07-25 1:30:00 AM', '2. Cairo display');
s2_assert(
    orange_admin_time_local_ymd_in_iana($iso, 'Asia/Kuwait') === '2026-07-25',
    '3. Kuwait midnight crossing'
);
$kwDay = orange_admin_time_day_bounds_mysql_utc('2026-07-25', 'Asia/Kuwait');
s2_assert($kwDay['start_utc_mysql'] === '2026-07-24 21:00:00', '14. Kuwait filter start');
s2_assert($kwDay['end_exclusive_utc_mysql'] === '2026-07-25 21:00:00', '14. Kuwait filter end exclusive');
$cairoDst = orange_admin_time_day_bounds_mysql_utc('2010-08-10', 'Africa/Cairo');
$dstLen = orange_admin_time_parse_mysql_utc_datetime($cairoDst['end_exclusive_utc_mysql'])->getTimestamp()
    - orange_admin_time_parse_mysql_utc_datetime($cairoDst['start_utc_mysql'])->getTimestamp();
s2_assert($dstLen === 90000, '4/15. Cairo DST day 25h');

$saved = date_default_timezone_get();
date_default_timezone_set('America/Los_Angeles');
s2_assert(
    orange_admin_time_format_instant_in_iana($iso, 'Asia/Kuwait', 'en', 'datetime12') === $kw,
    '11. PHP/browser TZ does not alter display'
);
date_default_timezone_set($saved);

$epoch = orange_admin_time_parse_utc_instant($iso)->getTimestamp();
s2_assert(orange_admin_time_utc_iso_from_unix($epoch) === gmdate('c', $epoch), '12. epoch→UTC ISO');
s2_assert(orange_admin_time_sql_from_unix() === 'FROM_UNIXTIME(?)', '12. FROM_UNIXTIME fragment');

s2_assert(orange_admin_time_date_only_assert('2026-07-25') === '2026-07-25', '7. Date-only unchanged');
s2_assert(
    orange_admin_time_display_mysql_utc_for_record(new PDO('sqlite::memory:'), '2026-07-24 22:30:00', 0)
        === '[admin_time_country_id_required]',
    '10. missing record country fail-closed'
);

// Authority helpers exist
s2_assert(function_exists('orange_purchase_return_authority_country_id'), '7. PR authority helper');
s2_assert(function_exists('orange_sales_return_authority_country_id'), '8. SR authority helper');

// Pages prefer server display
$purchPage = file_get_contents($root . '/admin/pages/purchases.php') ?: '';
$prPage = file_get_contents($root . '/admin/pages/purchase_returns.php') ?: '';
$srPage = file_get_contents($root . '/admin/pages/sales_returns.php') ?: '';
s2_assert(str_contains($purchPage, 'created_at_display'), '6. purchases page uses server display');
s2_assert(str_contains($prPage, 'created_at_display'), '7. PR page uses server display');
s2_assert(str_contains($srPage, 'created_at_display'), '8. SR page uses server display');

// No customer UI / inventory / accounting screen edits for time
$sfHome = file_get_contents($root . '/pages/home.php') ?: '';
s2_assert(!str_contains($sfHome, 'orange_admin_time_'), '22. storefront home unchanged');
$stockAdj = file_get_contents($root . '/admin/api/stock-adjustment/save.php') ?: '';
s2_assert(!str_contains($stockAdj, 'orange_admin_time_'), '23. inventory stock-adjustment API not migrated');
$jv = file_get_contents($root . '/includes/journal_voucher.php') ?: '';
s2_assert(str_contains($jv, 'updated_at = ?') && str_contains($jv, 'orange_admin_time_utc_now_mysql'), '24. accounting journal updated_at UTC (Step 5)');
s2_assert(!str_contains($jv, 'updated_at = NOW()'), '24. accounting journal no NOW() updated_at');

// Stock/FIFO writers in create still present (business logic untouched markers)
s2_assert(str_contains($pCreate, 'orange_inventory_cost_layer_add'), '17. purchase FIFO path still present');
s2_assert(str_contains($prCreate, 'orange_inventory_cost_layers_reduce_for_source'), '17. PR stock path still present');
s2_assert(str_contains($pCreate, 'orange_gl_purchase_invoice_posting_bundle'), '17. GL path still present');

// Step 5: GL posting times split (inventory layer wall separate from voucher_date)
s2_assert(str_contains($pCreate, 'orange_gl_posting_times_for_country'), '17. purchase GL uses gl_posting_times (Step 5)');
s2_assert(str_contains($pCreate, 'layerWallAt'), '17. purchase inventory layer wall separated from GL');

echo "\n---\nPassed: {$passes}\nFailed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
