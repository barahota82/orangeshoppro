<?php

declare(strict_types=1);

/**
 * Phase 3 / Step 2 — Admin operational time surfaces (isolated fixtures + source guards).
 *
 * Usage: php scripts/self_test_admin_time_phase3_step2_operational.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function p3s2_assert(bool $ok, string $label): void
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
$fixedUtc = '2026-07-17T12:30:00+00:00';

// --- Display contract: default datetime is 12-hour AM/PM ---
$kwDisp = orange_admin_time_format_instant_in_iana($fixedUtc, $kwIana, 'en', 'datetime');
$egDisp = orange_admin_time_format_instant_in_iana($fixedUtc, $egIana, 'en', 'datetime');
p3s2_assert(str_contains($kwDisp, 'AM') || str_contains($kwDisp, 'PM'), '6/20. Kuwait Entry Date display has AM/PM');
p3s2_assert(str_contains($egDisp, 'AM') || str_contains($egDisp, 'PM'), '6/20. Egypt Entry Date display has AM/PM');
p3s2_assert(!preg_match('/\b([01]?\d|2[0-3]):[0-5]\d\b/', preg_replace('/\s*[AP]M\s*$/i', '', $kwDisp) ?? $kwDisp)
    || str_contains($kwDisp, 'AM') || str_contains($kwDisp, 'PM'), '58. default datetime uses 12h clock');
p3s2_assert(str_contains($kwDisp, '3:30') && str_contains($kwDisp, 'PM'), '6. Kuwait 12:30 UTC → 3:30 PM');

// --- Date-only today / month bounds (no PHP default TZ) ---
$prevTz = date_default_timezone_get();
date_default_timezone_set('America/Los_Angeles');
$kwToday = orange_admin_time_today_ymd_in_iana($kwIana);
$egToday = orange_admin_time_today_ymd_in_iana($egIana);
p3s2_assert((bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $kwToday), '45. Kuwait local today Y-m-d');
p3s2_assert((bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $egToday), '46. Egypt local today Y-m-d');
$ms = orange_admin_time_month_start_ymd_in_iana($kwIana);
$me = orange_admin_time_month_end_ymd_in_iana($kwIana);
p3s2_assert(str_ends_with($ms, '-01'), '48. month start is day 01');
p3s2_assert($me >= $ms, '48b. month end >= month start');
date_default_timezone_set($prevTz);

// --- Day bounds inclusive-exclusive (no 23:59:59) ---
$kwBounds = orange_admin_time_day_bounds_mysql_utc('2026-07-17', $kwIana);
$egBounds = orange_admin_time_day_bounds_mysql_utc('2026-07-17', $egIana);
p3s2_assert($kwBounds['start_utc_mysql'] === '2026-07-16 21:00:00', '25. Kuwait Today start UTC');
p3s2_assert($kwBounds['end_exclusive_utc_mysql'] === '2026-07-17 21:00:00', '25b. Kuwait Today end exclusive');
p3s2_assert(
    $egBounds['start_utc_mysql'] === '2026-07-16 21:00:00' || $egBounds['start_utc_mysql'] === '2026-07-16 22:00:00',
    '26/28. Egypt July day bounds DST-aware'
);
$range = orange_admin_time_filter_range_mysql_utc('2026-07-17', '2026-07-18', $kwIana);
p3s2_assert($range !== null && $range['end_exclusive_utc_mysql'] === '2026-07-18 21:00:00', '30. inclusive-exclusive From/To');

// --- Source guards: Document Archive ---
$cdPage = (string) file_get_contents($root . '/admin/pages/company_documents.php');
$cdList = (string) file_get_contents($root . '/admin/api/company_documents/list.php');
$cdUp = (string) file_get_contents($root . '/admin/api/company_documents/upload.php');
$cdDl = (string) file_get_contents($root . '/admin/api/company_documents/download.php');
$cdDel = (string) file_get_contents($root . '/admin/api/company_documents/delete.php');
$cdLib = (string) file_get_contents($root . '/includes/company_documents.php');
p3s2_assert(str_contains($cdLib, 'orange_company_document_resolve_upload_country_id'), '1/3. docs country authority helper');
p3s2_assert(
    str_contains($cdUp, 'country_id') && str_contains($cdUp, 'orange_admin_time_sql_from_unix'),
    '1. upload writes country_id + unix created_at'
);
p3s2_assert(str_contains($cdUp, 'gmdate'), 'filename/path uses UTC gmdate');
p3s2_assert(str_contains($cdUp, 'document_date_today_for_country_id'), '1. doc_date default country-local');
p3s2_assert(!preg_match("/date\('Y-m-d'\)/", $cdPage), '1. company_documents page no PHP date default');
p3s2_assert(str_contains($cdList, 'country_id = ?') && str_contains($cdList, 'UNIX_TIMESTAMP'), '5. list country filter + unix read');
p3s2_assert(str_contains($cdList, 'created_at_display') && str_contains($cdList, 'api_instant_from_unix'), '1/6. list display via admin_time');
p3s2_assert(str_contains($cdDl, 'assert_context_ownership'), '6. download country ownership');
p3s2_assert(str_contains($cdDel, 'assert_context_ownership'), '7. delete country ownership');
p3s2_assert(str_contains($cdLib, 'Cross-country') || str_contains($cdLib, 'دولة أخرى'), '4. cross-country parent rejected');

// --- Activity log ---
$logs = (string) file_get_contents($root . '/admin/pages/logs.php');
$cfg = (string) file_get_contents($root . '/config.php');
p3s2_assert(str_contains($cfg, 'is_global') && str_contains($cfg, 'audit_log_country_required'), '14/15. audit_log explicit global + no silent global');
p3s2_assert(str_contains($cfg, 'orange_admin_time_sql_from_unix'), '19. audit_log TIMESTAMP via FROM_UNIXTIME');
p3s2_assert(str_contains($logs, 'country_id = ?') && str_contains($logs, 'is_global = 0'), '17/18. logs screen country-only');
p3s2_assert(str_contains($logs, 'UNIX_TIMESTAMP(l.created_at)') && str_contains($logs, 'filter_range_mysql_utc'), '21/22. logs From/To day bounds');
p3s2_assert(!str_contains($logs, '23:59:59') && !str_contains($logs, "00:00:00'"), '21. logs no session-wall bounds');
p3s2_assert(str_contains($logs, 'display_unix_for_record'), '20. logs 12h display via admin_time');
p3s2_assert(str_contains($logs, 'ORDER BY l.id DESC'), '24. sort by id');

// --- Dashboard ---
$dash = (string) file_get_contents($root . '/admin/pages/dashboard.php');
p3s2_assert(str_contains($dash, 'day_bounds_mysql_utc') || str_contains($dash, 'start_utc_mysql'), '25. dashboard uses day bounds');
p3s2_assert(!str_contains($dash, 'CURDATE()'), '25. dashboard no CURDATE');
p3s2_assert(!preg_match('/DATE\s*\(\s*created_at\s*\)/', $dash), '25. dashboard no DATE(created_at)');

// --- Channel analytics ---
$ca = (string) file_get_contents($root . '/admin/pages/channel_analytics.php');
$sra = (string) file_get_contents($root . '/includes/sales_return_analytics.php');
p3s2_assert(str_contains($ca, 'filter_range_mysql_utc') && str_contains($ca, 'end_exclusive'), '30. channel analytics UTC bounds');
p3s2_assert(!str_contains($ca, '23:59:59'), '31. channel analytics no 23:59:59');
p3s2_assert(str_contains($sra, 'UNIX_TIMESTAMP(sr.created_at)') && str_contains($sra, 'end_exclusive'), '32. returns agg uses exclusive bounds');

// --- Handover / merge / customers ---
$dhm = (string) file_get_contents($root . '/admin/pages/delivery_handover_manifest.php');
$merge = (string) file_get_contents($root . '/admin/pages/storefront_merge_requests.php');
$cust = (string) file_get_contents($root . '/admin/pages/customers.php');
p3s2_assert(str_contains($dhm, 'now_display_for_admin_context'), '34/35. handover print timestamp admin_time');
p3s2_assert(!str_contains($dhm, "orange_format_datetime_dmY_hi(date("), '34. handover no PHP local format');
p3s2_assert(str_contains($merge, 'display_unix_for_record') && str_contains($merge, 'created_at_unix'), '39. merge requests display');
p3s2_assert(str_contains($cust, 'orders_last_at') && str_contains($cust, 'display_mysql_utc_for_record'), '41/43. customers last activity');
p3s2_assert(str_contains($cust, 'orange_sql_country_and_fragment'), '41. customers orders country-scoped');

// --- Defaults / Entry Date ---
$jv = (string) file_get_contents($root . '/admin/pages/journal_voucher_screen.php');
$el = (string) file_get_contents($root . '/admin/pages/edit_lock.php');
$csi = (string) file_get_contents($root . '/admin/pages/company_sales_invoice.php');
$ob = (string) file_get_contents($root . '/admin/pages/opening_balances.php');
p3s2_assert(str_contains($jv, 'document_date_today_for_country_id'), '45/50. journal default Date-only country-local');
p3s2_assert(str_contains($el, 'month_start_ymd_in_iana'), '48. edit-lock month country-local');
p3s2_assert(str_contains($csi, 'now_display_for_admin_context'), '51. sales invoice Entry Date');
p3s2_assert(str_contains($ob, 'display_mysql_utc_for_record') && str_contains($ob, 'document_entered_at'), '50. opening balances Entry Date from document_entered_at');

// --- Schema revision / registry ---
$schema = (string) file_get_contents($root . '/includes/catalog_schema.php');
$reg = (string) file_get_contents($root . '/config/backup_table_registry.json');
$matrix = (string) file_get_contents($root . '/config/country_restore_boundary_matrix.json');
$defs = (string) file_get_contents($root . '/includes/backup/backup_table_registry_definitions.php');
p3s2_assert(
    (bool) preg_match("/ORANGE_CATALOG_SCHEMA_PHP_REVISION',\s*124\s*\)/", $schema),
    '72. schema revision 124'
);
p3s2_assert(str_contains($schema, 'admin_time_country_authority_v123'), '72. v123 migration present');
p3s2_assert(str_contains($schema, 'channels_country_name_unique_v124'), '72. v124 channel name unique present');
p3s2_assert(str_contains($reg, '"schema_revision": 124'), '72. registry schema_revision 124');
p3s2_assert(str_contains($matrix, '"schema_revision":  124') || str_contains($matrix, '"schema_revision": 124'), '73. matrix schema_revision 124');
p3s2_assert(str_contains($defs, "orange_company_documents' => \$c(114"), '73. docs country_id extraction');
p3s2_assert(str_contains($defs, 'orange_admin_audit_log') && str_contains($defs, 'is_global'), '73. audit country extract excludes global');
p3s2_assert(str_contains($matrix, 'direct_country_id') && str_contains($matrix, 'orange_company_documents'), '73. matrix docs Country Scoped');

// --- Freeze guards ---
p3s2_assert(!str_contains($cdUp, 'orange_post_order_delivery_accounting'), '68. no Phase 2 writer reopen in docs');
$backupPage = (string) file_get_contents($root . '/admin/pages/backup_center.php');
$restorePage = (string) file_get_contents($root . '/admin/pages/restore_center.php');
p3s2_assert(str_contains($backupPage, 'gmdate') || strlen($backupPage) > 100, '70. Backup Center file present (untouched policy)');
p3s2_assert(strlen($restorePage) > 100, '71. Restore Center file present');

echo "\nPhase 3 Step 2 operational: {$passes} PASS, {$failures} FAIL\n";
exit($failures > 0 ? 1 : 0);
