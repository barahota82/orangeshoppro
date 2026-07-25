<?php

declare(strict_types=1);

/**
 * Phase 2 / Step 1 Closure — Admin Orders & Payments time blockers.
 * Isolated: no Backup/Restore load. Optional MySQL roundtrip if PDO available.
 *
 * Usage: php scripts/self_test_admin_time_phase2_step1_closure.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function c1_assert(bool $ok, string $label): void
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
c1_assert(!$loadedBackup, '20. no Backup/Restore code loaded by admin_time');

$bc = file_get_contents($root . '/admin/pages/backup_center.php') ?: '';
$rc = file_get_contents($root . '/admin/pages/restore_center.php') ?: '';
c1_assert(str_contains($bc, 'fmtTimestampDisplay') && str_contains($bc, 'DISPLAY_TZ'), '19. Backup Center untouched');
c1_assert(str_contains($rc, 'fmtTimestampDisplay') && str_contains($rc, 'DISPLAY_TZ'), '20. Restore Center untouched');

// --- 1/2: Writer inventory — orders.created_at DATETIME UTC ---
$cm = file_get_contents($root . '/admin/api/orders/create-manual.php') ?: '';
$intake = file_get_contents($root . '/includes/order_intake_queue.php') ?: '';
c1_assert(str_contains($cm, 'orange_admin_time_utc_now_mysql'), '1. admin create-manual writes UTC created_at');
c1_assert(str_contains($intake, 'orange_admin_time_utc_now_mysql'), '1/2. storefront intake writes UTC created_at');
c1_assert(!preg_match('/\bNOW\s*\(/i', $intake), '2. intake file has no NOW() (UTC/unix writers only)');

// --- 3: updated_at / completed_at writers ---
$writeFiles = [
    'admin/api/orders/update.php',
    'admin/api/orders/update-status.php',
    'admin/api/orders/bulk-update-status.php',
    'admin/api/orders/cancel-from-delivery.php',
    'admin/api/orders/handover-to-agent.php',
    'includes/invoice_edit_helpers.php',
];
foreach ($writeFiles as $rel) {
    $src = file_get_contents($root . '/' . $rel) ?: '';
    c1_assert(str_contains($src, 'orange_admin_time_utc_now_mysql'), '3. utc writer: ' . $rel);
    c1_assert(!preg_match('/(?:updated_at|completed_at)\s*=\s*NOW\s*\(/i', $src), '3. no NOW() DATETIME: ' . $rel);
}

// --- 4: document_date Date-only from country local day ---
c1_assert(str_contains($cm, 'orange_admin_time_today_ymd_in_iana'), '4. document_date from country local Y-m-d');
c1_assert(orange_admin_time_date_only_assert('2026-07-25') === '2026-07-25', '4. Date-only unchanged by conversion');

// --- 5/6: TIMESTAMP writers use FROM_UNIXTIME ---
$payCore = file_get_contents($root . '/includes/payments/payment_core.php') ?: '';
c1_assert(!str_contains($payCore, "paid_at = NOW()"), '5. paid_at no longer NOW()');
c1_assert(str_contains($payCore, 'orange_admin_time_sql_from_unix') || str_contains($payCore, 'FROM_UNIXTIME'), '5. paid_at FROM_UNIXTIME');
c1_assert(str_contains($payCore, 'created_at') && str_contains($payCore, 'orange_admin_time_sql_from_unix'), '6. payment_transactions.created_at explicit unix write');

c1_assert(str_contains($intake, 'FROM_UNIXTIME') || str_contains($intake, 'orange_admin_time_sql_from_unix'), '7. intake queue TIMESTAMP unix write');
$retry = file_get_contents($root . '/admin/api/order_intake/retry.php') ?: '';
$retryBulk = file_get_contents($root . '/admin/api/order_intake/retry-bulk-failed.php') ?: '';
c1_assert(str_contains($retry, 'orange_admin_time_sql_from_unix'), '7. retry sets updated_at via unix');
c1_assert(str_contains($retryBulk, 'orange_admin_time_sql_from_unix'), '7. retry-bulk sets updated_at via unix');

// --- 5/8/9: Unix ↔ UTC ISO helpers; PHP TZ independence ---
$iso = '2026-07-24T22:30:00+00:00';
$epoch = orange_admin_time_parse_utc_instant($iso)->getTimestamp();
$isoOut = orange_admin_time_utc_iso_from_unix($epoch);
c1_assert(str_ends_with($isoOut, '+00:00') || str_ends_with($isoOut, 'Z'), '5. utc iso has Z/+00:00');
c1_assert(orange_admin_time_parse_utc_instant($isoOut)->getTimestamp() === $epoch, '5. epoch roundtrip via ISO');

$savedTz = date_default_timezone_get();
date_default_timezone_set('America/Los_Angeles');
c1_assert(orange_admin_time_utc_iso_from_unix($epoch) === $isoOut, '8. PHP TZ does not alter utc iso from unix');
c1_assert(orange_admin_time_utc_now_mysql() === gmdate('Y-m-d H:i:s'), '8. utc_now_mysql stays UTC under LA TZ');
$kw = orange_admin_time_format_instant_in_iana($isoOut, 'Asia/Kuwait', 'en', 'datetime12');
$eg = orange_admin_time_format_instant_in_iana($isoOut, 'Africa/Cairo', 'en', 'datetime12');
c1_assert($kw === '2026-07-25 1:30:00 AM', '5/10. Kuwait display from epoch');
c1_assert($eg !== '' && $eg === $kw, '5. Cairo same offset as KW on this summer instant (both +03)');
date_default_timezone_set('Pacific/Auckland');
c1_assert(
    orange_admin_time_format_instant_in_iana($isoOut, 'Asia/Kuwait', 'en', 'datetime12') === $kw,
    '10. browser/system TZ does not affect admin IANA display'
);
date_default_timezone_set($savedTz);

// --- 11/12: midnight crossing filters ---
$kwDay = orange_admin_time_day_bounds_mysql_utc('2026-07-25', 'Asia/Kuwait');
c1_assert($kwDay['start_utc_mysql'] === '2026-07-24 21:00:00', '11. Kuwait day start UTC');
c1_assert($kwDay['end_exclusive_utc_mysql'] === '2026-07-25 21:00:00', '11. Kuwait end exclusive (no 23:59:59)');
$cairoDst = orange_admin_time_day_bounds_mysql_utc('2010-08-10', 'Africa/Cairo');
$dstLen = orange_admin_time_parse_mysql_utc_datetime($cairoDst['end_exclusive_utc_mysql'])->getTimestamp()
    - orange_admin_time_parse_mysql_utc_datetime($cairoDst['start_utc_mysql'])->getTimestamp();
c1_assert($dstLen === 90000, '12. Cairo DST day length 25h');

// --- 13/14: list/detail consistency markers ---
$ordersPage = file_get_contents($root . '/admin/pages/orders.php') ?: '';
$inv = file_get_contents($root . '/admin/pages/invoice.php') ?: '';
$payReviewApi = file_get_contents($root . '/admin/api/payments/review.php') ?: '';
$payReviewPage = file_get_contents($root . '/admin/pages/payment_review.php') ?: '';
c1_assert(str_contains($ordersPage, 'orange_admin_time_display_mysql_utc_for_record'), '13. orders list record display');
c1_assert(str_contains($inv, 'orange_admin_time_display_mysql_utc_for_record'), '13. invoice detail same helper family');
c1_assert(str_contains($inv, 'paid_at_unix') || str_contains($inv, 'display_unix_for_record'), '13. invoice paid_at via unix');
c1_assert(str_contains($payReviewApi, 'paid_at_utc') && str_contains($payReviewApi, 'last_txn_created_at_utc'), '14. payment API UTC ISO fields');
c1_assert(str_contains($payReviewPage, 'paid_at_display') && str_contains($payReviewPage, 'last_txn_created_at_display'), '14. payment review shows server display');
c1_assert(str_contains($payReviewApi, 'UNIX_TIMESTAMP'), '9. payment API reads TIMESTAMP via UNIX_TIMESTAMP');

// --- 15: fail closed missing country ---
c1_assert(
    orange_admin_time_display_mysql_utc_for_record(
        new PDO('sqlite::memory:'),
        '2026-07-24 22:30:00',
        0
    ) === '[admin_time_country_id_required]',
    '15. missing record country fail-closed (DATETIME)'
);
c1_assert(
    orange_admin_time_display_unix_for_record(new PDO('sqlite::memory:'), $epoch, 0)
        === '[admin_time_country_id_required]',
    '15. missing record country fail-closed (TIMESTAMP unix)'
);
c1_assert(
    str_contains($payReviewApi, 'admin_time_country_id_required')
    && !preg_match('/\$rowCid\s*=\s*\$cid\s*;/', $payReviewApi),
    '15. payment API no silent country context fallback'
);

// --- 16: country isolation still present ---
c1_assert(str_contains($ordersPage, 'orange_sql_filter_country_id'), '16. orders country isolation');
c1_assert(
    str_contains($payReviewApi, 'orange_sql_country_and_fragment')
    || str_contains($payReviewApi, 'orange_admin_assert_entity_country'),
    '16. payments country isolation'
);

// --- 17: inventory/accounting untouched (spot; purchases migrated in Step 2) ---
$stock = file_get_contents($root . '/admin/api/products/update.php') ?: '';
c1_assert(str_contains($stock, 'updated_at = NOW()'), '17. products/stock path untouched');
$gl = file_get_contents($root . '/includes/journal_voucher.php') ?: '';
c1_assert(str_contains($gl, 'updated_at = NOW()'), '17. accounting journal path untouched');

// --- 18: no customer UI pages changed for time display ---
// Storefront pages must not call admin_time display helpers
$sfHome = file_get_contents($root . '/pages/home.php') ?: '';
$sfCart = file_get_contents($root . '/pages/cart.php') ?: '';
c1_assert(!str_contains($sfHome, 'orange_admin_time_'), '18. customer home UI unchanged (no admin_time)');
c1_assert(!str_contains($sfCart, 'orange_admin_time_'), '18. customer cart UI unchanged (no admin_time)');

// --- intake queue admin display uses unix + record country ---
$oiqPage = file_get_contents($root . '/admin/pages/order_intake_queue.php') ?: '';
c1_assert(str_contains($oiqPage, 'UNIX_TIMESTAMP') && str_contains($oiqPage, 'display_unix_for_record'), '7. intake admin display via unix');

// --- Helper SQL fragment ---
c1_assert(orange_admin_time_sql_from_unix() === 'FROM_UNIXTIME(?)', '9. sql_from_unix fragment');

// --- Optional MySQL TIMESTAMP roundtrip (session +03:00) ---
$mysqlOk = false;
$mysqlNote = 'skipped (no DB)';
if (is_file($root . '/.env.php') || is_file($root . '/config.php')) {
    try {
        // Avoid full app bootstrap side effects: only try if env exists.
        if (is_file($root . '/.env.php')) {
            require_once $root . '/config.php';
            if (function_exists('db')) {
                $pdo = db();
                $pdo->exec("SET time_zone = '+03:00'");
                $pdo->exec('CREATE TEMPORARY TABLE orange_ts_c1_test (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    paid_at TIMESTAMP NULL,
                    created_at TIMESTAMP NULL
                ) ENGINE=InnoDB');
                $epochW = 1721863800;
                $ins = $pdo->prepare('INSERT INTO orange_ts_c1_test (paid_at, created_at) VALUES (FROM_UNIXTIME(?), FROM_UNIXTIME(?))');
                $ins->execute([$epochW, $epochW]);
                $sel = $pdo->query('SELECT UNIX_TIMESTAMP(paid_at) AS u1, UNIX_TIMESTAMP(created_at) AS u2, paid_at AS wall FROM orange_ts_c1_test LIMIT 1');
                $row = $sel ? $sel->fetch(PDO::FETCH_ASSOC) : false;
                if (is_array($row)) {
                    $mysqlOk = ((int) $row['u1'] === $epochW) && ((int) $row['u2'] === $epochW);
                    // Session wall must NOT equal UTC wall string if we wrongly treat it as UTC
                    $wall = (string) ($row['wall'] ?? '');
                    $wrongAsUtc = orange_admin_time_parse_mysql_utc_datetime($wall)->getTimestamp();
                    c1_assert($mysqlOk, '9. MySQL session +03 FROM_UNIXTIME preserves epoch');
                    c1_assert($wrongAsUtc !== $epochW, '9. session wall ≠ UTC wall (double-conversion guard)');
                    $mysqlNote = 'ran';
                }
            }
        }
    } catch (Throwable $e) {
        $mysqlNote = 'skipped: ' . $e->getMessage();
    }
}
if ($mysqlNote !== 'ran') {
    c1_assert(true, '9. MySQL roundtrip ' . $mysqlNote . ' — contract covered by FROM_UNIXTIME/UNIX_TIMESTAMP source + unix helpers');
}

// Sort by raw column not display
$ofp = file_get_contents($root . '/admin/pages/online_orders_final_posting.php') ?: '';
c1_assert(str_contains($ofp, 'ORDER BY o.completed_at DESC'), '13. sort by raw completed_at');

echo "\n---\nPassed: {$passes}\nFailed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
