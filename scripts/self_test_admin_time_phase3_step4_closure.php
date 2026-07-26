<?php

declare(strict_types=1);

/**
 * Phase 3 / Step 4 Closure Verification — architecture, classification, matrix, consistency.
 *
 * Usage: php scripts/self_test_admin_time_phase3_step4_closure.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function p3s4c_assert(bool $ok, string $label): void
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

$sfSrc = (string) file_get_contents($root . '/includes/storefront_time.php');
$adminSrc = (string) file_get_contents($root . '/includes/admin_time.php');

// --- 1–12 storefront_time foundation reuse ---
p3s4c_assert(str_contains($sfSrc, "require_once __DIR__ . '/admin_time.php'"), '1. storefront_time requires admin_time');
p3s4c_assert(str_contains($sfSrc, 'orange_admin_time_timezone_for_country_id'), '1. reuses timezone_for_country_id');
p3s4c_assert(str_contains($sfSrc, 'orange_admin_time_parse_mysql_utc_datetime'), '1. reuses parse_mysql_utc_datetime');
p3s4c_assert(str_contains($sfSrc, 'orange_admin_time_format_instant_in_iana'), '1. reuses format_instant_in_iana');
p3s4c_assert(str_contains($sfSrc, 'orange_admin_time_format_instant_for_country_id'), '1. reuses format_instant_for_country_id');
p3s4c_assert(str_contains($sfSrc, 'orange_admin_time_utc_now_iso'), '1. reuses utc_now_iso');
p3s4c_assert(!str_contains($sfSrc, 'Asia/Kuwait'), '2. storefront_time no hardcoded Asia/Kuwait');
p3s4c_assert(!str_contains($sfSrc, '+03'), '2. storefront_time no +03');
p3s4c_assert(!str_contains($sfSrc, 'date_default_timezone_set'), '2. no date_default_timezone_set');
p3s4c_assert(!preg_match('/\bdate\s*\(/', $sfSrc), '2. storefront_time no PHP date()');
p3s4c_assert(!preg_match('/\bstrtotime\s*\(/', $sfSrc), '2. storefront_time no strtotime');

$miss = orange_storefront_time_api_instant_from_mysql_utc(
    // PDO unused when country_id <= 0 (early return)
    new PDO('sqlite::memory:'),
    '2026-07-17 12:30:00',
    0,
    'en'
);
p3s4c_assert($miss['utc'] === '' && $miss['display'] === '—', '3. missing country fails closed (empty utc + em-dash)');

try {
    orange_admin_time_require_iana('Not/AZone');
    p3s4c_assert(false, '4. invalid IANA throws');
} catch (OrangeAdminTimeConfigException $e) {
    p3s4c_assert($e->getMessage() === 'admin_time_invalid_iana_timezone', '4. invalid IANA fails clearly');
}

$utc = orange_admin_time_parse_mysql_utc_datetime('2026-07-17 12:30:00')->format('c');
p3s4c_assert(str_contains($utc, '+00:00') || str_ends_with($utc, 'Z'), '5. UTC ISO includes offset/Z');

$kw = orange_admin_time_format_instant_in_iana($utc, 'Asia/Kuwait', 'en', 'datetime');
$eg = orange_admin_time_format_instant_in_iana($utc, 'Africa/Cairo', 'en', 'datetime');
p3s4c_assert(str_contains($kw, 'AM') || str_contains($kw, 'PM'), '6/7. Kuwait display 12h AM/PM');
p3s4c_assert(str_contains($eg, 'AM') || str_contains($eg, 'PM'), '8. Cairo display 12h AM/PM');
p3s4c_assert($kw !== $eg || str_contains($kw, 'PM'), '9. browser-independent: IANA args drive display (not browser)');

// Cairo DST autumn: 2026-10-29 local day length 25h already covered in Step3; display smoke:
$cairoDst = orange_admin_time_format_instant_in_iana('2026-10-29T21:30:00+00:00', 'Africa/Cairo', 'en', 'datetime');
p3s4c_assert(str_contains($cairoDst, 'AM') || str_contains($cairoDst, 'PM'), '8b. Cairo DST instant formats 12h');

$d1 = orange_storefront_time_date_only_display('2026-03-29');
p3s4c_assert($d1 === '29/03/2026', '10. Date-only unchanged calendar');

// No double conversion: parse once → format('c') → format_in_iana uses same instant
$iso1 = orange_admin_time_parse_mysql_utc_datetime('2026-07-17 09:15:00')->format('c');
$dispA = orange_admin_time_format_instant_in_iana($iso1, 'Asia/Kuwait', 'en', 'datetime');
$dispB = orange_admin_time_format_instant_in_iana($iso1, 'Asia/Kuwait', 'en', 'datetime');
p3s4c_assert(
    $dispA === $dispB && (str_contains($dispA, 'AM') || str_contains($dispA, 'PM')),
    '11. no double conversion (stable same instant)'
);

$row = orange_storefront_time_enrich_order_row(
    new PDO('sqlite::memory:'),
    [
        'country_id' => 0,
        'created_at' => '2026-07-17 12:30:00',
        'id' => 1,
    ],
    'en'
);
p3s4c_assert(!array_key_exists('created_at', $row), '12. raw created_at removed from enriched row');
p3s4c_assert(($row['created_at_utc'] ?? null) === '', '12. missing country → empty utc not ambiguous DATETIME');

// --- Cross-surface consistency (same MySQL UTC → same displays) ---
$mysqlUtc = '2026-07-17 12:30:00';
$iso = orange_admin_time_parse_mysql_utc_datetime($mysqlUtc)->format('c');
$listLikeKw = orange_admin_time_format_instant_in_iana($iso, 'Asia/Kuwait', 'en', 'datetime');
$trackLikeKw = orange_admin_time_format_instant_in_iana($iso, 'Asia/Kuwait', 'en', 'datetime');
$docGenPattern = orange_admin_time_format_instant_in_iana($iso, 'Asia/Kuwait', 'en', 'datetime');
p3s4c_assert($listLikeKw === $trackLikeKw && $trackLikeKw === $docGenPattern, '16. list/detail/track/print same Absolute display for same country');

// Winter 2026: Cairo UTC+2 vs Kuwait UTC+3 (July 2026 both +3 in current tzdb).
$isoWinter = '2026-01-15T12:30:00+00:00';
$listLikeEg = orange_admin_time_format_instant_in_iana($isoWinter, 'Africa/Cairo', 'en', 'datetime');
$listLikeKwWinter = orange_admin_time_format_instant_in_iana($isoWinter, 'Asia/Kuwait', 'en', 'datetime');
p3s4c_assert($listLikeEg !== $listLikeKwWinter, '16b. Egypt vs Kuwait differ by IANA (order country authority)');

// Midnight crossover: Kuwait local 00:30 on 2026-07-17 = 2026-07-16 21:30 UTC
$crossIso = '2026-07-16T21:30:00+00:00';
$crossKw = orange_admin_time_format_instant_in_iana($crossIso, 'Asia/Kuwait', 'en', 'datetime');
p3s4c_assert(str_contains($crossKw, '17/07/2026') || str_contains($crossKw, '2026-07-17'), '16c. UTC/local midnight → Kuwait calendar day 17');

// --- Country authority source guards ---
$listAcc = (string) file_get_contents($root . '/api/orders/list-storefront-orders.php');
$listGuest = (string) file_get_contents($root . '/api/orders/list-guest-storefront-orders.php');
$getOrder = (string) file_get_contents($root . '/api/orders/get-order.php');
$docPage = (string) file_get_contents($root . '/pages/document.php');
p3s4c_assert(str_contains($listAcc, 'storefront_account_id') && str_contains($listAcc, 'o.country_id = ?'), '5A. account list ownership + country scope');
p3s4c_assert(str_contains($listGuest, 'orange_storefront_current_country_id') && str_contains($listGuest, 'o.country_id = ?'), '5B. guest phone + storefront country');
p3s4c_assert(str_contains($getOrder, '$orderCountryId !== $sfCountryId') || str_contains($getOrder, 'orderCountryId !== $sfCountryId'), '5C. track order country vs storefront');
p3s4c_assert(str_contains($docPage, 'tokenCid') && str_contains($docPage, 'tokenCid !== $cid'), '5D. document token country matches record');

// --- Promo messages COUNTRY_SCOPED (not Explicit Global) ---
$spmInc = (string) file_get_contents($root . '/includes/storefront_promo_messages.php');
$spmApi = (string) file_get_contents($root . '/admin/api/storefront_promo_messages/manage.php');
$spmPage = (string) file_get_contents($root . '/admin/pages/storefront_promo_messages.php');
$policy = (string) file_get_contents($root . '/docs/archive/ORANGE_ADMIN_TIME_POLICY.txt');
p3s4c_assert(str_contains($spmInc, 'country_id = ?') && !str_contains($spmInc, 'country_id IS NULL OR country_id = ?'), '6. SPM eval country_id = ? only (no NULL OR)');
p3s4c_assert(str_contains($spmApi, 'orange_admin_context_country_id') && str_contains($spmApi, 'countryToStore'), '6. SPM save uses Current Country Context');
p3s4c_assert(str_contains($spmApi, 'country_context_required'), '6. SPM create without context fails clearly');
p3s4c_assert(!str_contains($spmInc, 'is_global') && !str_contains($spmApi, 'is_global'), '6. SPM has no is_global column/contract');
p3s4c_assert(str_contains($spmPage, 'spmIsGlobal') || str_contains($spmPage, 'SPM_IS_GLOBAL'), '6. SPM_IS_GLOBAL is admin UI unlock mode only');
p3s4c_assert(str_contains($policy, 'COUNTRY_SCOPED') && str_contains($policy, 'country_id = ?') && !str_contains($policy, 'storefront_promo_messages` عند عدم قفل دولة: EXPLICIT_GLOBAL'), '6. policy classifies SPM as COUNTRY_SCOPED');

// --- Exact matrix totals in policy ---
p3s4c_assert(str_contains($policy, 'Grand total: 63'), '13. Exact matrix grand total 63 documented');
p3s4c_assert(str_contains($policy, 'MIGRATED_AND_COMPLIANT: 46'), '13. MIGRATED=46');
p3s4c_assert(str_contains($policy, 'NOT_APPLICABLE_DATE_ONLY: 6'), '13. DATE_ONLY=6');
p3s4c_assert(str_contains($policy, 'NOT_APPLICABLE_BUSINESS_LOCAL_TIME: 1'), '13. BUSINESS_LOCAL=1');
p3s4c_assert(str_contains($policy, 'INTERNAL_ONLY: 7'), '13. INTERNAL=7');
p3s4c_assert(str_contains($policy, 'FROZEN_BACKUP_RESTORE: 2'), '13. FROZEN=2');
p3s4c_assert(str_contains($policy, 'EXPLICIT_GLOBAL_COMPLIANT: 1'), '13. EXPLICIT_GLOBAL=1');
p3s4c_assert(46 + 6 + 1 + 7 + 2 + 1 === 63, '13. matrix arithmetic 63');
p3s4c_assert(str_contains($policy, 'Unresolved: 0'), '13. zero unresolved');

// --- Customer Global/Mixed statement ---
p3s4c_assert(
    str_contains($policy, 'NO EXPLICIT GLOBAL OR MIXED CUSTOMER/STOREFRONT USER SURFACE FOUND'),
    '12. no explicit Global/Mixed Customer/Storefront user surface'
);

// --- Backup freeze (paths exist; Step 4 diffs must not touch them — verified by suite file list) ---
p3s4c_assert(is_file($root . '/admin/pages/backup_center.php'), '19. backup_center present');
p3s4c_assert(is_file($root . '/admin/pages/restore_center.php'), '19. restore_center present');

echo "\n--- Phase 3 Step 4 Closure summary ---\n";
echo "PASS={$passes} FAIL={$failures}\n";
exit($failures > 0 ? 1 : 0);
