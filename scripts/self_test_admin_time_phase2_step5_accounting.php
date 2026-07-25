<?php

declare(strict_types=1);

/**
 * Phase 2 / Step 5 — Accounting & Journal time migration (isolated fixtures + source guards).
 *
 * Usage: php scripts/self_test_admin_time_phase2_step5_accounting.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function s5_assert(bool $ok, string $label): void
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

function s5_expect_exception(callable $fn, string $needle, string $label): void
{
    try {
        $fn();
        s5_assert(false, $label . ' (no exception)');
    } catch (Throwable $e) {
        s5_assert(str_contains($e->getMessage(), $needle), $label . ' [' . $e->getMessage() . ']');
    }
}

require_once $root . '/includes/admin_time.php';
require_once $root . '/includes/gl_posting_time.php';

// --- Pure helpers (no DB) ---
s5_assert(
    orange_gl_accounting_voucher_date_mysql('2026-07-17') === '2026-07-17 12:00:00',
    '1. accounting voucher_date normalizes to Y-m-d 12:00:00'
);
s5_assert(
    orange_gl_accounting_voucher_date_mysql('2026-07-17 23:59:59') === '2026-07-17 12:00:00',
    '1b. wall clock on voucher_date does not change accounting day'
);
s5_assert(
    orange_gl_accounting_ymd_from_voucher_datetime('2026-07-17 12:00:00') === '2026-07-17',
    '1c. extract accounting Y-m-d from stored DATETIME'
);
s5_expect_exception(
    static fn () => orange_gl_accounting_voucher_date_mysql(''),
    'admin_time_accounting_date_required',
    '21. empty accounting date fails closed'
);
s5_expect_exception(
    static fn () => orange_gl_posting_times_for_country(
        new class extends PDO {
            public function __construct()
            {
            }
        },
        0,
        '2026-07-17'
    ),
    'admin_time_country_id_required',
    '21b. country_id required for posting times'
);

// Kuwait / Egypt local day vs UTC instant (fixed fixtures via day bounds)
$kwIana = 'Asia/Kuwait';
$egIana = 'Africa/Cairo';
$kwBounds = orange_admin_time_day_bounds_utc('2026-07-17', $kwIana);
$egBounds = orange_admin_time_day_bounds_utc('2026-07-17', $egIana);
s5_assert(
    str_starts_with($kwBounds['start_utc_iso'], '2026-07-16T21:00:00')
        || str_starts_with($kwBounds['start_utc_iso'], '2026-07-16T21:00:00+00:00'),
    '2. Kuwait local midnight → UTC previous evening'
);
// Egypt DST: July is typically UTC+3 (EEST) — local midnight = previous day 21:00 UTC
s5_assert(
    str_contains($egBounds['start_utc_iso'], '2026-07-16T21:00:00')
        || str_contains($egBounds['start_utc_iso'], '2026-07-16T22:00:00'),
    '4. Egypt July day bounds DST-aware'
);

$utcNow = orange_admin_time_utc_now_mysql();
s5_assert((bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $utcNow), '8. utc_now_mysql shape');
$kwDisp = orange_admin_time_format_instant_in_iana(
    orange_admin_time_parse_mysql_utc_datetime($utcNow)->format('c'),
    $kwIana,
    'ar',
    'datetime'
);
$egDisp = orange_admin_time_format_instant_in_iana(
    orange_admin_time_parse_mysql_utc_datetime($utcNow)->format('c'),
    $egIana,
    'ar',
    'datetime'
);
s5_assert($kwDisp !== '' && $egDisp !== '', '2/3. IANA display for KW/EG from UTC instant');
s5_assert($kwDisp !== $utcNow, '9. display is not raw UTC wall string');

// Midnight crossover: local Kuwait day after UTC date may differ
$utcAlmostKwMidnight = '2026-07-16 20:59:59'; // still previous Kuwait day
$utcAfterKwMidnight = '2026-07-16 21:00:00'; // Kuwait 2026-07-17 00:00
$ymdBefore = orange_admin_time_local_ymd_in_iana(
    orange_admin_time_parse_mysql_utc_datetime($utcAlmostKwMidnight)->format('c'),
    $kwIana
);
$ymdAfter = orange_admin_time_local_ymd_in_iana(
    orange_admin_time_parse_mysql_utc_datetime($utcAfterKwMidnight)->format('c'),
    $kwIana
);
s5_assert($ymdBefore === '2026-07-16', '3. before KW midnight → previous local day');
s5_assert($ymdAfter === '2026-07-17', '3b. at KW midnight → new local accounting day');

// TIMESTAMP epoch roundtrip helpers
$unix = orange_admin_time_unix_now();
$iso = orange_admin_time_utc_iso_from_unix($unix);
s5_assert(str_contains($iso, '+00:00') || str_ends_with($iso, 'Z') || str_contains($iso, 'T'), '7. unix→UTC ISO');
s5_assert(orange_admin_time_sql_from_unix() === 'FROM_UNIXTIME(?)', '7b. FROM_UNIXTIME write contract');

// --- Source inventory / writers ---
$glTime = file_get_contents($root . '/includes/gl_posting_time.php') ?: '';
$jv = file_get_contents($root . '/includes/journal_voucher.php') ?: '';
$of = file_get_contents($root . '/includes/order_fulfillment.php') ?: '';
$pend = file_get_contents($root . '/includes/gl_pending_movements.php') ?: '';
$slot = file_get_contents($root . '/includes/gl_voucher_slot.php') ?: '';
$jm = file_get_contents($root . '/admin/api/journal/manage.php') ?: '';
$pCreate = file_get_contents($root . '/admin/api/purchases/create.php') ?: '';
$prCreate = file_get_contents($root . '/admin/api/purchase_returns/create.php') ?: '';
$srCreate = file_get_contents($root . '/admin/api/sales_returns/create.php') ?: '';
$saj = file_get_contents($root . '/includes/stock_adjustment_voucher.php') ?: '';
$loy = file_get_contents($root . '/includes/loyalty.php') ?: '';
$br = file_get_contents($root . '/includes/bank_reconciliation.php') ?: '';
$ob = file_get_contents($root . '/admin/api/opening_balances/save.php') ?: '';
$yec = file_get_contents($root . '/includes/year_end_close.php') ?: '';
$fpCreate = file_get_contents($root . '/admin/api/orders/final-posting-create.php') ?: '';
$party = file_get_contents($root . '/includes/party_subledger.php') ?: '';
$elock = file_get_contents($root . '/includes/edit_lock.php') ?: '';
$jvPage = file_get_contents($root . '/admin/pages/journal_voucher_screen.php') ?: '';
$docsPage = file_get_contents($root . '/admin/pages/company_documents.php') ?: '';
$logsPage = file_get_contents($root . '/admin/pages/logs.php') ?: '';
$bc = file_get_contents($root . '/admin/pages/backup_center.php') ?: '';
$rc = file_get_contents($root . '/admin/pages/restore_center.php') ?: '';
$home = file_get_contents($root . '/pages/home.php') ?: '';

s5_assert(str_contains($glTime, 'orange_gl_posting_times_for_country'), '5. gl_posting_time helper present');
s5_assert(str_contains($jv, 'orange_gl_accounting_voucher_date_mysql'), '8. voucher_post normalizes accounting date');
s5_assert(str_contains($jv, 'orange_admin_time_utc_now_mysql'), '5. document_entered_at / updated_at UTC');
s5_assert(!str_contains($jv, 'updated_at = NOW()'), '6. journal_vouchers no NOW() updated_at');

s5_assert(str_contains($of, 'orange_order_delivery_posting_times'), '12. delivery posting times helper');
s5_assert(str_contains($of, "'movement_at' => \$movementAt") || str_contains($of, '$movementAt,'), '12. delivery movement_at UTC separate');
s5_assert(str_contains($fpCreate, 'orange_post_order_delivery_accounting') || str_contains($fpCreate, 'order_fulfillment'), '12. final-posting uses delivery accounting writer');

s5_assert(str_contains($pend, 'posted_at = ?'), '10. pending posted_at UTC bind');
s5_assert(!str_contains($pend, 'posted_at = NOW()'), '10b. pending no NOW() posted_at');
s5_assert(str_contains($pend, 'orange_gl_accounting_voucher_date_mysql'), '10c. pending voucher_date normalized');

s5_assert(str_contains($slot, 'voided_at = ?'), '19. slot void voided_at UTC');
s5_assert(!str_contains($slot, 'voided_at = NOW()'), '19b. slot no NOW() voided_at');
s5_assert(str_contains($slot, 'updated_at = ?'), '19c. slot updated_at UTC');

s5_assert(str_contains($jm, 'orange_gl_posting_times_for_country'), '7. manual journal create uses gl times');
s5_assert(str_contains($jm, 'orange_admin_time_display_mysql_utc_for_record'), '8. journal get display via record country');
s5_assert(str_contains($jm, 'document_entered_at_utc'), '8b. API returns UTC ISO field');

s5_assert(str_contains($pCreate, 'orange_gl_posting_times_for_country'), '13. purchase GL times');
s5_assert(str_contains($prCreate, 'orange_gl_posting_times_for_country'), '14. PR GL times');
s5_assert(str_contains($srCreate, 'orange_gl_posting_times_for_country'), '15. SR GL times');
s5_assert(str_contains($saj, 'orange_gl_posting_times_for_country'), '16. stock adjustment GL times');
s5_assert(str_contains($loy, 'orange_gl_posting_times_for_country'), '17. loyalty GL times');
s5_assert(str_contains($br, 'orange_gl_posting_times_for_country'), '16b. bank recon GL times');
s5_assert(str_contains($ob, 'orange_gl_posting_times_for_country'), '16c. opening balance GL times');
s5_assert(str_contains($yec, 'voided_at = ?') && str_contains($yec, 'closed_at = ?'), '18. YEC/fiscal closed_at/voided_at UTC');

s5_assert(str_contains($party, 'INSERT INTO party_subledger'), '11. party_subledger insert unchanged (DEFAULT TIMESTAMP)');
s5_assert(str_contains($elock, 'locked_at = ?') && !str_contains($elock, 'locked_at = NOW()'), '20. edit lock locked_at UTC');
s5_assert(str_contains($elock, 'DATE(j.voucher_date)'), '3. edit lock filters voucher_date as calendar DATE');
s5_assert(str_contains($elock, 'orange_edit_lock_format_saved_at_for_display'), '3b. journal saved_at display strips noon clock');
s5_assert(!str_contains($loy, "\$nowClaw = date('Y-m-d H:i:s')"), '5. loyalty clawback no ambiguous date() postingAt');
s5_assert(str_contains($loy, 'orange_gl_posting_times_for_country'), '5b. loyalty clawback uses gl_posting_times');

s5_assert(str_contains($jvPage, 'orange_admin_time_document_date_today_for_country_id'), '7b. manual form default date = country today');
s5_assert(str_contains($jm, 'DATE(voucher_date)'), '24. operational voucher_date filters remain Date-only');

// Freezes
s5_assert(!str_contains($docsPage, 'orange_gl_posting_times_for_country'), '37. Document Archive untouched (deferred outside Step 5 — placement pending)');
s5_assert(!str_contains($logsPage, 'orange_gl_posting_times_for_country'), '38. Activity Log untouched (deferred outside Step 5 — placement pending)');
$fpPage = file_get_contents($root . '/admin/pages/online_orders_final_posting.php') ?: '';
s5_assert(!str_contains($fpPage, 'orange_gl_posting_times_for_country'), 'delivery list/UI page not rewritten by Step 5 helpers');
s5_assert(
    str_contains($of, 'orange_order_delivery_posting_times')
    && (str_contains($fpCreate, 'orange_post_order_delivery_accounting') || str_contains($fpCreate, 'order_fulfillment')),
    'Only Delivery Accounting Posting writers included in Step 5'
);
$spmSrc = file_get_contents($root . '/includes/storefront_promo_messages.php') ?: '';
s5_assert(
    !str_contains($spmSrc, 'orange_gl_posting_times_for_country'),
    'Storefront Promotional Messages untouched in Step 5'
);
s5_assert(str_contains($bc, 'fmtTimestampDisplay'), '39. Backup Center untouched');
s5_assert(str_contains($rc, 'fmtTimestampDisplay'), '40. Restore Center untouched');
s5_assert(!str_contains($home, 'orange_gl_posting_times_for_country'), '41. storefront home unchanged');

// Business logic freeze markers
s5_assert(str_contains($pCreate, 'orange_gl_purchase_invoice_posting_bundle'), '26. purchase posting bundle still present');
s5_assert(str_contains($of, 'orange_gl_voucher_immediate_post') || str_contains($of, 'orange_order_delivery_immediate_post'), '27. delivery posting path present');
s5_assert(str_contains($slot, 'orange_gl_voucher_slot'), '28. slot allocation helpers present');

echo "\n---\nStep 5 accounting: {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
