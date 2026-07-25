<?php

declare(strict_types=1);

/**
 * Phase 2 self-test — Admin Orders / Payments time migration (isolated).
 * No DB writes. No Backup/Restore load. No production data.
 *
 * Usage: php scripts/self_test_admin_time_phase2_orders_payments.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function p2_assert(bool $ok, string $label): void
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
$after = get_included_files();
$loadedBackup = false;
foreach (array_diff($after, $before) as $inc) {
    $n = str_replace('\\', '/', $inc);
    if (preg_match('#/(includes/backup|admin/pages/backup_center|admin/pages/restore_center|admin/api/backup|admin/api/restore|scripts/backup)/#', $n)) {
        $loadedBackup = true;
    }
}
p2_assert(!$loadedBackup, '16. no Backup/Restore code loaded');

// Source regression: frozen files still use their own formatters (untouched content markers)
$bc = file_get_contents($root . '/admin/pages/backup_center.php') ?: '';
$rc = file_get_contents($root . '/admin/pages/restore_center.php') ?: '';
p2_assert(str_contains($bc, 'fmtTimestampDisplay') && str_contains($bc, 'DISPLAY_TZ'), '17. Backup Center formatter intact');
p2_assert(str_contains($rc, 'fmtTimestampDisplay') && str_contains($rc, 'DISPLAY_TZ'), '17. Restore Center formatter intact');

// Migrated admin write paths use utc_now_mysql (not NOW()) for DATETIME stamps
$writeFiles = [
    'admin/api/orders/create-manual.php',
    'admin/api/orders/update.php',
    'admin/api/orders/update-status.php',
    'admin/api/orders/bulk-update-status.php',
    'admin/api/orders/cancel-from-delivery.php',
    'admin/api/orders/handover-to-agent.php',
    'includes/invoice_edit_helpers.php',
];
foreach ($writeFiles as $rel) {
    $src = file_get_contents($root . '/' . $rel) ?: '';
    p2_assert(
        str_contains($src, 'orange_admin_time_utc_now_mysql'),
        '15/write uses utc_now_mysql: ' . $rel
    );
    // No remaining NOW() on orders timestamp assignments in these files
    if (preg_match('/(?:created_at|updated_at|completed_at)\s*=\s*NOW\s*\(/i', $src)) {
        p2_assert(false, 'no NOW() on order DATETIME in ' . $rel);
    } else {
        p2_assert(true, 'no NOW() on order DATETIME in ' . $rel);
    }
}

// Step 1 closure: payment_core TIMESTAMP writers use FROM_UNIXTIME (no NOW())
$payCore = file_get_contents($root . '/includes/payments/payment_core.php') ?: '';
p2_assert(!str_contains($payCore, "paid_at = NOW()"), 'closure: payment_core paid_at no longer NOW()');
p2_assert(
    str_contains($payCore, 'orange_admin_time_sql_from_unix') || str_contains($payCore, 'FROM_UNIXTIME'),
    'closure: payment_core uses unix→FROM_UNIXTIME for TIMESTAMP'
);
$intakeSrc = file_get_contents($root . '/includes/order_intake_queue.php') ?: '';
p2_assert(str_contains($intakeSrc, 'orange_admin_time_utc_now_mysql'), 'closure: storefront intake orders.created_at UTC');

// 1–4: UTC moment + Kuwait/Cairo display + midnight crossing (fixtures, no DB)
$utcIso = '2026-07-24T22:30:00+00:00';
$mysqlUtc = '2026-07-24 22:30:00';
$parsed = orange_admin_time_parse_mysql_utc_datetime($mysqlUtc);
p2_assert($parsed->format('c') === '2026-07-24T22:30:00+00:00', '1. MySQL UTC DATETIME parses as UTC');
$kw = orange_admin_time_format_instant_in_iana($utcIso, 'Asia/Kuwait', 'en', 'datetime12');
$eg = orange_admin_time_format_instant_in_iana($utcIso, 'Africa/Cairo', 'en', 'datetime12');
p2_assert($kw === '2026-07-25 1:30:00 AM', '1/2. Kuwait display');
p2_assert($eg !== '', '2. Cairo display');
p2_assert(
    orange_admin_time_local_ymd_in_iana($utcIso, 'Asia/Kuwait') === '2026-07-25',
    '3. Kuwait business day after UTC evening'
);

// 5: stored instant unchanged when formatting for different countries
$ts = orange_admin_time_parse_mysql_utc_datetime($mysqlUtc)->getTimestamp();
$kwBack = (new DateTimeImmutable('2026-07-25 01:30:00', new DateTimeZone('Asia/Kuwait')))
    ->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
p2_assert($ts === $kwBack, '5. stored UTC instant identity across display zones');

// 6: PHP default TZ must not alter formatting
$saved = date_default_timezone_get();
date_default_timezone_set('America/Los_Angeles');
p2_assert(
    orange_admin_time_format_instant_in_iana($utcIso, 'Asia/Kuwait', 'en', 'datetime12') === $kw,
    '6. browser/system TZ does not affect IANA display'
);
date_default_timezone_set($saved);

// 9–11: day filters (Kuwait + Cairo DST day)
$kwRange = orange_admin_time_day_bounds_mysql_utc('2026-07-25', 'Asia/Kuwait');
p2_assert($kwRange['start_utc_mysql'] === '2026-07-24 21:00:00', '10. Kuwait day start UTC mysql');
p2_assert($kwRange['end_exclusive_utc_mysql'] === '2026-07-25 21:00:00', '10. Kuwait day end exclusive');
$cairoDst = orange_admin_time_day_bounds_mysql_utc('2010-08-10', 'Africa/Cairo');
$len = orange_admin_time_parse_utc_instant(
    orange_admin_time_parse_mysql_utc_datetime($cairoDst['end_exclusive_utc_mysql'])->format('c')
)->getTimestamp()
    - orange_admin_time_parse_utc_instant(
        orange_admin_time_parse_mysql_utc_datetime($cairoDst['start_utc_mysql'])->format('c')
    )->getTimestamp();
p2_assert($len === 90000, '11. Cairo DST filter day length 25h');

$range = orange_admin_time_filter_range_mysql_utc('2026-07-25', '2026-07-25', 'Asia/Kuwait');
p2_assert(
    is_array($range)
    && $range['start_utc_mysql'] === '2026-07-24 21:00:00'
    && $range['end_exclusive_utc_mysql'] === '2026-07-25 21:00:00',
    '10. filter range inclusive/exclusive'
);

// 12: Date-only
p2_assert(orange_admin_time_date_only_assert('2026-07-25') === '2026-07-25', '12. Date-only unchanged');

// 13: sorting uses raw timestamps in SQL (orders.php ORDER BY o.id DESC — identity order; final posting ORDER BY completed_at)
$ofp = file_get_contents($root . '/admin/pages/online_orders_final_posting.php') ?: '';
p2_assert(str_contains($ofp, 'ORDER BY o.completed_at DESC'), '13. sort by completed_at column not display text');
p2_assert(str_contains($ofp, 'orange_admin_time_filter_range_mysql_utc'), '9/10. final posting uses country day bounds');

// 14: country filter still present on orders / payments
$ordersPage = file_get_contents($root . '/admin/pages/orders.php') ?: '';
$payReview = file_get_contents($root . '/admin/api/payments/review.php') ?: '';
p2_assert(str_contains($ordersPage, 'orange_sql_filter_country_id'), '14. orders country isolation');
p2_assert(str_contains($payReview, 'orange_sql_country_and_fragment') || str_contains($payReview, 'orange_admin_assert_entity_country'), '14. payments country isolation');

// Display uses record country / helpers
p2_assert(
    str_contains($ordersPage, 'orange_admin_time_display_mysql_utc_for_record')
    || str_contains($ordersPage, 'orange_admin_time_display_mysql_utc_or_dash'),
    '7. orders list display helper'
);
$inv = file_get_contents($root . '/admin/pages/invoice.php') ?: '';
p2_assert(
    str_contains($inv, 'orange_admin_time_display_mysql_utc_for_record')
    || str_contains($inv, 'orange_admin_time_display_mysql_utc_or_dash'),
    '7. invoice detail display helper'
);
p2_assert(str_contains($payReview, 'created_at_display'), '9. payment API exposes created_at_display');
p2_assert(str_contains($payReview, 'paid_at_utc') && str_contains($payReview, 'last_txn_created_at_utc'), '9. payment API exposes TIMESTAMP as UTC ISO');

// create-manual document_date uses country local today
$cm = file_get_contents($root . '/admin/api/orders/create-manual.php') ?: '';
p2_assert(str_contains($cm, 'orange_admin_time_today_ymd_in_iana'), '1/12. document_date from country local day');

// Out-of-scope screens not modified for this commit set (spot-check purchases/stock still have NOW if they did)
$purch = file_get_contents($root . '/admin/api/purchases/update.php') ?: '';
p2_assert(str_contains($purch, 'updated_at = NOW()'), '17. purchases untouched (still NOW())');

echo "\n---\nPassed: {$passes}\nFailed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
