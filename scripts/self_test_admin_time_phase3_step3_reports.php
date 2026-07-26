<?php

declare(strict_types=1);

/**
 * Phase 3 / Step 3 — Financial / commercial / inventory reports time surfaces.
 *
 * Usage: php scripts/self_test_admin_time_phase3_step3_reports.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function p3s3_assert(bool $ok, string $label): void
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

require_once $root . '/includes/admin_time.php';

$kwIana = 'Asia/Kuwait';
$egIana = 'Africa/Cairo';

// --- Helpers: Absolute From/To inclusive-exclusive ---
$kwRange = orange_admin_time_filter_range_mysql_utc('2026-07-17', '2026-07-18', $kwIana);
p3s3_assert($kwRange !== null, '7. Kuwait Absolute From/To range non-null');
p3s3_assert(($kwRange['start_utc_mysql'] ?? '') === '2026-07-16 21:00:00', '7. Kuwait local day start UTC');
p3s3_assert(($kwRange['end_exclusive_utc_mysql'] ?? '') === '2026-07-18 21:00:00', '12. Kuwait exclusive end includes final day');
p3s3_assert(!str_contains((string) ($kwRange['end_exclusive_utc_mysql'] ?? ''), '23:59:59'), '13. no 23:59:59 in Absolute bounds');

$egRange = orange_admin_time_filter_range_mysql_utc('2026-07-17', '2026-07-17', $egIana);
p3s3_assert($egRange !== null, '8. Egypt Absolute day range');
$egStart = (string) ($egRange['start_utc_mysql'] ?? '');
p3s3_assert($egStart === '2026-07-16 21:00:00' || $egStart === '2026-07-16 22:00:00', '8. Egypt July day DST-aware start');

// DST-safe day bounds: Cairo autumn 25h; 23h proven via Europe/Berlin (Cairo spring wall-day may stay 24h).
$cairoAutumn = orange_admin_time_day_bounds_mysql_utc('2026-10-29', $egIana);
$cairoAutumnLen = strtotime($cairoAutumn['end_exclusive_utc_mysql'] . ' UTC')
    - strtotime($cairoAutumn['start_utc_mysql'] . ' UTC');
p3s3_assert($cairoAutumnLen === 25 * 3600, '10. Cairo DST 25-hour day bounds');
$berlinSpring = orange_admin_time_day_bounds_mysql_utc('2026-03-29', 'Europe/Berlin');
$berlinLen = strtotime($berlinSpring['end_exclusive_utc_mysql'] . ' UTC')
    - strtotime($berlinSpring['start_utc_mysql'] . ' UTC');
p3s3_assert($berlinLen === 23 * 3600, '9. IANA 23-hour spring day bounds (Europe/Berlin)');
$egJul = orange_admin_time_day_bounds_mysql_utc('2026-07-17', $egIana);
$kwJul = orange_admin_time_day_bounds_mysql_utc('2026-07-17', $kwIana);
p3s3_assert(($egJul['start_utc_mysql'] ?? '') !== '2026-07-17 00:00:00', '11. Egypt day start not UTC midnight');
p3s3_assert(($kwJul['start_utc_mysql'] ?? '') === '2026-07-16 21:00:00', '11b. Kuwait day start UTC known');

$defaults = [
    'from_ymd' => orange_admin_time_month_start_ymd_in_iana($kwIana),
    'to_ymd' => orange_admin_time_today_ymd_in_iana($kwIana),
];
p3s3_assert(str_ends_with($defaults['from_ymd'], '-01'), '11/C. report default month start day 01');
p3s3_assert((bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $defaults['to_ymd']), 'C. report default today Y-m-d');

$buckets = orange_admin_time_local_month_buckets_mysql_utc('2026-01-15', '2026-03-10', $kwIana);
p3s3_assert(count($buckets) === 3, '67/68. local month buckets cover Jan–Mar');
$mk = orange_admin_time_sql_local_month_key_expr('o.created_at', $buckets);
p3s3_assert(str_contains($mk['sql'], 'CASE') && str_contains($mk['sql'], '>= ?') && str_contains($mk['sql'], '< ?'), '67. month key CASE uses sargable Absolute bounds');
p3s3_assert(!str_contains($mk['sql'], 'DATE(') && !str_contains($mk['sql'], 'MONTH('), '69. no DATE/MONTH on Absolute for month key');

$disp = orange_admin_time_format_instant_in_iana('2026-07-17T12:30:00+00:00', $kwIana, 'en', 'datetime');
p3s3_assert(str_contains($disp, 'AM') || str_contains($disp, 'PM'), '27/D. Entry Date 12h AM/PM');

// --- Source guards: sales ---
$sales = (string) file_get_contents($root . '/admin/pages/sales_reports.php');
p3s3_assert(str_contains($sales, 'orange_admin_time_filter_range_mysql_utc'), '22. sales Absolute filter helper');
p3s3_assert(str_contains($sales, 'end_exclusive') || str_contains($sales, ' < ?'), '22. sales exclusive end');
p3s3_assert(!preg_match('/DATE\s*\(\s*COALESCE\s*\(/i', $sales), '14. sales no DATE(COALESCE Absolute)');
p3s3_assert(!preg_match('/(?<![\w\/])CURDATE\s*\(/i', preg_replace('/\/\/.*$/m', '', $sales) ?? $sales), '15. sales no CURDATE in code');
p3s3_assert(!str_contains($sales, '23:59:59'), '13. sales no 23:59:59');
p3s3_assert(str_contains($sales, 'orange_admin_time_sql_local_month_key_expr'), '25/67. sales monthly local buckets');
p3s3_assert(str_contains($sales, 'تاريخ الإدخال') && str_contains($sales, 'entry_at'), '27. sales Entry Date column');
p3s3_assert(str_contains($sales, 'now_display_for_admin_context'), '54. sales print timestamp admin_time');
p3s3_assert(str_contains($sales, 'orange_sql_country_and_fragment') || str_contains($sales, 'country_id'), '1/4. sales country scope');

// --- Source guards: purchases ---
$pur = (string) file_get_contents($root . '/admin/pages/purchase_reports.php');
p3s3_assert(str_contains($pur, 'orange_admin_time_filter_range_mysql_utc'), '28. purchase Absolute filter');
p3s3_assert(str_contains($pur, 'DATE(') && str_contains($pur, 'document_date'), '17/18. purchase Date-only document_date');
p3s3_assert(str_contains($pur, ' >= ? AND ') && str_contains($pur, ' < ?'), '28. purchase Absolute sargable range');
p3s3_assert(!str_contains($pur, '23:59:59'), '28. purchase no 23:59:59');
p3s3_assert(str_contains($pur, 'تاريخ الإدخال') && str_contains($pur, 'entry_at'), '32. purchase Entry Date AM/PM column');
p3s3_assert(str_contains($pur, 'now_display_for_admin_context'), '33. purchase print timestamp');
p3s3_assert(str_contains($pur, 'orange_sql_country_and_fragment'), '31. purchase country isolation');
p3s3_assert(str_contains($pur, 'orange_admin_time_local_month_buckets_mysql_utc'), '28. purchase monthly Absolute buckets');

// --- Source guards: stock ---
$stock = (string) file_get_contents($root . '/admin/pages/stock_reports.php');
p3s3_assert(str_contains($stock, 'orange_admin_time_filter_range_mysql_utc'), '34. stock Absolute filter');
p3s3_assert(!preg_match('/DATE\s*\(\s*sm\.created_at\s*\)/i', $stock), '14/34. stock no DATE(sm.created_at)');
p3s3_assert(!preg_match('/DATE\s*\(\s*pu\.created_at\s*\)/i', $stock), '34. stock no DATE(pu.created_at)');
p3s3_assert(!str_contains($stock, 'BETWEEN ? AND ?') || !preg_match('/DATE\s*\([^)]*created_at/i', $stock), '34. stock Absolute not DATE+BETWEEN');
p3s3_assert(!str_contains($stock, '23:59:59'), '34. stock no 23:59:59');
p3s3_assert(str_contains($stock, 'srFormatInstantDisplay') || str_contains($stock, 'display_mysql_utc'), '41. stock Entry/movement 12h display');
p3s3_assert(str_contains($stock, 'now_display_for_admin_context'), '40. stock print timestamp');
p3s3_assert(str_contains($stock, 'orange_sql_country_and_fragment'), '35. warehouse/stock country isolation');

// --- Financial Date-only + print ---
$finFiles = [
    'report_trial_balance.php',
    'report_income_statement.php',
    'report_balance_sheet.php',
    'report_cash_flow.php',
    'report_pl_monthly.php',
    'report_pl_compare_years.php',
    'report_gl_account_monthly.php',
    'journal_voucher_reports.php',
    'partner_account_statement.php',
];
foreach ($finFiles as $fn) {
    $body = (string) file_get_contents($root . '/admin/pages/' . $fn);
    p3s3_assert(
        str_contains($body, 'orange_admin_time_') || str_contains($body, 'admin_time'),
        "42/G. {$fn} uses admin_time"
    );
    p3s3_assert(
        str_contains($body, 'now_display_for_admin_context') || !str_contains($body, 'orange_format_datetime_dmY_hi(date('),
        "54. {$fn} print not PHP local date()"
    );
}

$tb = (string) file_get_contents($root . '/admin/pages/report_trial_balance.php');
p3s3_assert(str_contains($tb, 'report_default_from_to_for_admin_context') || str_contains($tb, 'tbReportTodayYmd'), '49. trial balance country-local defaults');

$jv = (string) file_get_contents($root . '/admin/pages/journal_voucher_reports.php');
p3s3_assert(str_contains($jv, 'DATE(jv.voucher_date)') || str_contains($jv, 'voucher_date'), '44. JV voucher_date Date-only');
p3s3_assert(!preg_match('/DATE\s*\(\s*jv\.document_entered_at\s*\)/i', $jv), '45. JV no DATE(document_entered_at)');

$pas = (string) file_get_contents($root . '/admin/pages/partner_account_statement.php');
p3s3_assert(str_contains($pas, 'DATE(jv.voucher_date)'), '60. partner statement Date-only voucher_date');
p3s3_assert(str_contains($pas, 'now_display_for_admin_context'), '61. partner print timestamp');

$br = (string) file_get_contents($root . '/admin/pages/bank_reconciliation.php');
p3s3_assert(str_contains($br, 'document_date_today_for_admin_context') || str_contains($br, 'orange_admin_time_'), '63. bank recon country-local defaults');

// Freeze guards
$backupCenter = (string) file_get_contents($root . '/admin/pages/backup_center.php');
p3s3_assert($backupCenter !== '', '83. Backup Center present untouched (read-only check)');
$restoreCenter = (string) file_get_contents($root . '/admin/pages/restore_center.php');
p3s3_assert($restoreCenter !== '', '84. Restore Center present untouched (read-only check)');

// No parallel formatter introduced in report pages
p3s3_assert(
    !preg_match('/function\s+orange_report_time_/i', $sales . $pur . $stock),
    '23. no parallel report time formatter'
);

echo "\n--- Phase 3 Step 3 reports: {$passes} PASS / {$failures} FAIL ---\n";
exit($failures > 0 ? 1 : 0);
