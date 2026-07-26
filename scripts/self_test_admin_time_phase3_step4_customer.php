<?php

declare(strict_types=1);

/**
 * Phase 3 / Step 4 — Customer / Storefront time surfaces + Global/Mixed closure guards.
 *
 * Usage: php scripts/self_test_admin_time_phase3_step4_customer.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function p3s4_assert(bool $ok, string $label): void
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
require_once $root . '/includes/storefront_time.php';

$kwIana = 'Asia/Kuwait';
$egIana = 'Africa/Cairo';

// --- Absolute helpers (Country IANA, 12h) ---
$kwDisp = orange_admin_time_format_instant_in_iana('2026-07-17T12:30:00+00:00', $kwIana, 'en', 'datetime');
p3s4_assert(str_contains($kwDisp, 'AM') || str_contains($kwDisp, 'PM'), '14/45. Absolute display 12h AM/PM');
p3s4_assert(!preg_match('/\b(1[3-9]|2[0-3]):[0-5]\d\b/', $kwDisp), '50. no 24h hour (13–23) in Absolute display');

$egDisp = orange_admin_time_format_instant_in_iana('2026-07-17T12:30:00Z', $egIana, 'en', 'datetime');
p3s4_assert(str_contains($egDisp, 'PM') || str_contains($egDisp, 'AM'), '10/11. Egypt Absolute display');

$dateOnly = orange_storefront_time_date_only_display('2026-07-17');
p3s4_assert($dateOnly === '17/07/2026', '40/42. Date-only Y-m-d → d/m/Y');
$dateOnlyFromDt = orange_storefront_time_date_only_display('2026-07-17 15:45:00');
p3s4_assert($dateOnlyFromDt === '17/07/2026', '18/43. Date-only takes calendar prefix only (no TZ shift)');
p3s4_assert(orange_storefront_time_date_only_display('') === '', '44. empty Date-only');

// Mock enrich without DB: parse + format via admin_time only
$utcIso = orange_admin_time_parse_mysql_utc_datetime('2026-07-17 12:30:00')->format('c');
p3s4_assert(str_ends_with($utcIso, '+00:00') || str_contains($utcIso, 'Z') || str_contains($utcIso, '+00:00'), '63. MySQL UTC → ISO with offset');

// --- Source: storefront_time.php ---
$sfTime = (string) file_get_contents($root . '/includes/storefront_time.php');
p3s4_assert(str_contains($sfTime, 'orange_storefront_time_enrich_order_row'), '16. enrich_order_row exists');
p3s4_assert(str_contains($sfTime, 'orange_admin_time_format_instant_in_iana'), '9. Country IANA via admin_time');
p3s4_assert(str_contains($sfTime, '_utc') && str_contains($sfTime, '_display'), '63/15. API utc+display fields');

// --- Source: list APIs ---
$listAcc = (string) file_get_contents($root . '/api/orders/list-storefront-orders.php');
p3s4_assert(str_contains($listAcc, 'storefront_time.php'), '7. account list uses storefront_time');
p3s4_assert(str_contains($listAcc, 'o.country_id = ?') || str_contains($listAcc, 'country_id = ?'), '1/51. account list country filter');
p3s4_assert(str_contains($listAcc, 'orange_storefront_time_enrich_order_row'), '15. account list enriches Absolute');
p3s4_assert(str_contains($listAcc, 'ORDER BY o.created_at DESC'), '13. sort by raw instant');

$listGuest = (string) file_get_contents($root . '/api/orders/list-guest-storefront-orders.php');
p3s4_assert(str_contains($listGuest, 'orange_storefront_current_country_id'), '2/5. guest list storefront country');
p3s4_assert(str_contains($listGuest, 'o.country_id = ?') || str_contains($listGuest, 'country_id = ?'), '51. guest list country filter');
p3s4_assert(str_contains($listGuest, 'orange_storefront_time_enrich_order_row'), '15. guest list Absolute enrich');

// --- Source: get-order ---
$getOrder = (string) file_get_contents($root . '/api/orders/get-order.php');
p3s4_assert(str_contains($getOrder, 'orange_storefront_current_country_id'), '17. track country authority check');
p3s4_assert(str_contains($getOrder, 'order_lookup_not_found'), '4/5. cross-country → same 404');
p3s4_assert(str_contains($getOrder, 'orange_storefront_time_enrich_order_row'), '18. track Absolute enrich');
p3s4_assert(str_contains($getOrder, 'created_at_utc') || str_contains($getOrder, 'enrich_order_row'), '63. get-order UTC contract via enrich');
p3s4_assert(!preg_match('/json_response\(\s*\[\s*[^\]]*\'order\'\s*=>\s*\$order\b/', $getOrder), '15. get-order not dumping full SELECT * row');

// --- Source: cart.js (server display only; no browser TZ on business stamps) ---
$cartJs = (string) file_get_contents($root . '/assets/js/cart.js');
p3s4_assert(str_contains($cartJs, 'created_at_display'), '12/45. cart/track uses created_at_display');
p3s4_assert(!preg_match('/new\s+Date\s*\(\s*[^)]*created_at/', $cartJs), '16/66. no new Date(created_at)');
p3s4_assert(!preg_match('/toLocaleString\s*\(/', $cartJs), '66. no toLocaleString on cart.js business time');
p3s4_assert(str_contains($cartJs, 'completed_at_display'), '18/46. track completed_at_display');
p3s4_assert(str_contains($cartJs, 'cancelled_at_display'), '18. track cancelled_at_display');

// Date.now only for OTP cooldown / intake poll — not eligibility
p3s4_assert(substr_count($cartJs, 'Date.now(') <= 6, '38. Date.now limited to non-eligibility UX');

// --- Public document Date-only ---
$pubDoc = (string) file_get_contents($root . '/includes/public_document_view.php');
p3s4_assert(str_contains($pubDoc, 'orange_public_doc_date_only_field'), '25. public doc Date-only helper');
p3s4_assert(str_contains($pubDoc, 'orange_storefront_time_date_only_display'), '25/42. no strtotime Date-only');
p3s4_assert(!str_contains($pubDoc, "document_date'] ?? (\$o['created_at']"), '23/28. no created_at fallback into document date');
p3s4_assert(!str_contains($pubDoc, "document_date'] ?? (\$h['created_at']"), '23. no created_at fallback (headers)');
p3s4_assert(!preg_match('/strtotime\s*\(/', $pubDoc), '43. public_document_view no strtotime');

$docPage = (string) file_get_contents($root . '/pages/document.php');
p3s4_assert(str_contains($docPage, 'orange_storefront_time_now_display_for_country'), '27/49. print/view generated_at Country-IANA');
p3s4_assert(str_contains($docPage, 'generated_at'), '27. generated_at label present');

// --- Labels on track/cart ---
$trackPhp = (string) file_get_contents($root . '/pages/track.php');
$cartPhp = (string) file_get_contents($root . '/pages/cart.php');
p3s4_assert(str_contains($trackPhp, 'created_label'), '45. track created_label');
p3s4_assert(str_contains($cartPhp, 'created_label'), '45. cart created_label');

// --- Global audit ordinary list excludes is_global ---
$logs = (string) file_get_contents($root . '/admin/pages/logs.php');
p3s4_assert(str_contains($logs, 'is_global = 0'), '55. ordinary activity excludes global audit');

// --- Backup/Restore freeze ---
p3s4_assert(is_file($root . '/admin/pages/backup_center.php'), '75. backup_center present (untouched expected)');
p3s4_assert(is_file($root . '/admin/pages/restore_center.php'), '75. restore_center present');

// --- Explicit Global admin surface: storefront_promo_messages when unlocked ---
$spm = (string) file_get_contents($root . '/admin/pages/storefront_promo_messages.php');
p3s4_assert(str_contains($spm, 'SPM_IS_GLOBAL') || str_contains($spm, 'spmIsGlobal'), '52. promo messages explicit global flag exists');

// --- No Asia/Kuwait hardcode in storefront customer APIs ---
p3s4_assert(!str_contains($listAcc, 'Asia/Kuwait'), '9. list account no Kuwait fallback hardcode');
p3s4_assert(!str_contains($listGuest, 'Asia/Kuwait'), '9. list guest no Kuwait fallback');
p3s4_assert(!str_contains($getOrder, 'Asia/Kuwait'), '9. get-order no Kuwait fallback');

// --- Admin Entry Date closure still 12h (regression) ---
$adminTime = (string) file_get_contents($root . '/includes/admin_time.php');
p3s4_assert(str_contains($adminTime, 'g:i A'), '73. admin_time default datetime 12h AM/PM');

echo "\n--- Phase 3 Step 4 summary ---\n";
echo "PASS={$passes} FAIL={$failures}\n";
exit($failures > 0 ? 1 : 0);
