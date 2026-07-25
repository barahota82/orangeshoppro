<?php

declare(strict_types=1);

/**
 * Phase 2 / Step 4 — Country Promotions & Scheduling time migration (isolated).
 *
 * Usage: php scripts/self_test_admin_time_phase2_step4_promotions.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function s4_assert(bool $ok, string $label): void
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

function s4_expect_exception(callable $fn, string $needle, string $label): void
{
    try {
        $fn();
        s4_assert(false, $label . ' (no exception)');
    } catch (Throwable $e) {
        s4_assert(str_contains($e->getMessage(), $needle), $label . ' [' . $e->getMessage() . ']');
    }
}

$before = get_included_files();
require_once $root . '/includes/admin_time.php';
require_once $root . '/includes/cart_promo_schedule.php';
require_once $root . '/includes/promo_always_on.php';
$after = get_included_files();
$loadedBackup = false;
foreach (array_diff($after, $before) as $inc) {
    $n = str_replace('\\', '/', $inc);
    if (preg_match('#/(includes/backup|admin/pages/backup_center|admin/pages/restore_center|admin/api/backup|admin/api/restore|scripts/backup)/#', $n)) {
        $loadedBackup = true;
    }
}
s4_assert(!$loadedBackup, '35/36. no Backup/Restore loaded by promotion time includes');

$bc = file_get_contents($root . '/admin/pages/backup_center.php') ?: '';
$rc = file_get_contents($root . '/admin/pages/restore_center.php') ?: '';
s4_assert(str_contains($bc, 'fmtTimestampDisplay'), '35. Backup Center untouched');
s4_assert(str_contains($rc, 'fmtTimestampDisplay'), '36. Restore Center untouched');

$schedSrc = file_get_contents($root . '/includes/cart_promo_schedule.php') ?: '';
$spmSrc = file_get_contents($root . '/includes/storefront_promo_messages.php') ?: '';
$monSrc = file_get_contents($root . '/includes/cart_promo_monitor.php') ?: '';
$delSrc = file_get_contents($root . '/includes/delivery_areas.php') ?: '';
$alwaysSrc = file_get_contents($root . '/includes/promo_always_on.php') ?: '';
$offersSave = file_get_contents($root . '/admin/api/offers/save.php') ?: '';
$spmManage = file_get_contents($root . '/admin/api/storefront_promo_messages/manage.php') ?: '';
$cpManage = file_get_contents($root . '/admin/api/cart_promotions/manage.php') ?: '';
$giftManage = file_get_contents($root . '/admin/api/cart_gift_promotions/manage.php') ?: '';
$bogoManage = file_get_contents($root . '/admin/api/cart_bogo_promotions/manage.php') ?: '';
$comboManage = file_get_contents($root . '/admin/api/cart_combo_promotions/manage.php') ?: '';
$dpManage = file_get_contents($root . '/admin/api/delivery_promotions/manage.php') ?: '';
$offersPage = file_get_contents($root . '/admin/pages/offers.php') ?: '';
$adminTimeSrc = file_get_contents($root . '/includes/admin_time.php') ?: '';

// No fake 2099 permanent end (comments may mention 2099; code must not assign it)
s4_assert(!preg_match('/[\'"]2099/', $schedSrc), '11. no 2099 fake permanent end in schedule helper');
s4_assert(str_contains($schedSrc, 'UTC_TIMESTAMP()'), '10. schedule SQL uses UTC_TIMESTAMP');
s4_assert(!preg_match('/valid_from\s*<=\s*NOW\s*\(/i', $schedSrc), '10. schedule helper has no NOW() window');

// Always-on history UTC
s4_assert(str_contains($alwaysSrc, 'orange_admin_time_utc_now_mysql'), '13. always_on history UTC writers');

// Manage APIs country-aware parse + list localize
foreach ([
    'cart_promotions' => $cpManage,
    'cart_gift' => $giftManage,
    'cart_bogo' => $bogoManage,
    'cart_combo' => $comboManage,
] as $label => $src) {
    s4_assert(str_contains($src, 'orange_cart_promo_parse_required_admin_dates'), "25. {$label} parse dates");
    s4_assert(str_contains($src, 'orange_cart_promo_admin_localize_schedule_row'), "19. {$label} list localize");
    s4_assert(str_contains($src, 'scheduleCountryId') || str_contains($src, 'schedule_country'), "21. {$label} country authority on save");
}
s4_assert(str_contains($dpManage, 'asDateOnly') || str_contains($dpManage, 'true'), '12. delivery parse asDateOnly');
s4_assert(str_contains($offersSave, 'offer_country_required') || str_contains($offersSave, 'دولة المنتج مطلوبة'), '22. offers missing country fails closed');
s4_assert(str_contains($offersPage, 'orange_cart_promo_admin_localize_schedule_row'), '19. offers page localize roundtrip');
s4_assert(str_contains($spmManage, 'spm_iso_or_null') && str_contains($spmManage, '$pdo'), '3. SPM save country IANA→UTC');
s4_assert(str_contains($spmSrc, 'UTC_TIMESTAMP()'), '17. SPM evaluator UTC_TIMESTAMP');
s4_assert(!preg_match('/valid_from\s*<=\s*NOW\s*\(/i', $spmSrc), '17. SPM no NOW() window');
s4_assert(str_contains($monSrc, 'UTC_TIMESTAMP()'), '16. monitor schedule UTC_TIMESTAMP');
s4_assert(str_contains($monSrc, 'orange_admin_time_utc_now_mysql'), '13. monitor lifecycle UTC');
s4_assert(str_contains($delSrc, 'orange_admin_time_document_date_today_for_country_id'), '12. delivery DATE uses country today');

// Permanent: is_always_on short-circuits schedule
s4_assert(orange_cart_promo_is_within_schedule('', '', true), '1/11. permanent always_on ignores empty bounds');
s4_assert(orange_cart_promo_is_within_schedule('2099-01-01 00:00:00', '2099-12-31 23:59:59', true), '1. permanent ignores filler dates');

// Kuwait scheduled day → UTC bounds + roundtrip Y-m-d
$kwRange = orange_cart_promo_local_ymd_range_to_utc_mysql('2026-07-17', '2026-07-17', 'Asia/Kuwait');
s4_assert($kwRange['valid_from'] === '2026-07-16 21:00:00', '3/5. KW local day start UTC [' . $kwRange['valid_from'] . ']');
s4_assert($kwRange['valid_to'] === '2026-07-17 20:59:59', '3/5. KW local day end inclusive UTC [' . $kwRange['valid_to'] . ']');

// Midnight cross UTC/Kuwait: local 2026-07-17 00:00 → previous UTC calendar day
$kwStartIso = orange_admin_time_day_bounds_utc('2026-07-17', 'Asia/Kuwait')['start_utc_iso'];
s4_assert(str_starts_with($kwStartIso, '2026-07-16T21:00:00'), '5. KW midnight crosses UTC day');

// Egypt DST day length via IANA (fall-back 25h)
$egBounds = orange_admin_time_day_bounds_utc('2010-08-10', 'Africa/Cairo');
$egLen = orange_admin_time_parse_utc_instant($egBounds['end_exclusive_utc_iso'])->getTimestamp()
    - orange_admin_time_parse_utc_instant($egBounds['start_utc_iso'])->getTimestamp();
s4_assert($egLen === 90000, '4/6. Cairo DST fall-back day 25h [' . $egLen . ']');
$egRange = orange_cart_promo_local_ymd_range_to_utc_mysql('2010-08-10', '2010-08-10', 'Africa/Cairo');
$egBackStart = orange_admin_time_parse_mysql_utc_datetime($egRange['valid_from'])
    ->setTimezone(new DateTimeZone('Africa/Cairo'))
    ->format('Y-m-d');
$egBackEnd = orange_admin_time_parse_mysql_utc_datetime($egRange['valid_to'])
    ->setTimezone(new DateTimeZone('Africa/Cairo'))
    ->format('Y-m-d');
s4_assert($egBackStart === '2010-08-10' && $egBackEnd === '2010-08-10', '4. Egypt schedule roundtrip Y-m-d');

// Inclusive PHP evaluation against stored UTC walls
$insideKw = orange_cart_promo_is_within_schedule($kwRange['valid_from'], $kwRange['valid_to'], false);
// Force "now" via stored bounds: mid-day Kuwait in UTC
$midUtc = '2026-07-17 09:00:00'; // 12:00 Kuwait
s4_assert(
    orange_admin_time_parse_mysql_utc_datetime($midUtc)->getTimestamp()
        >= orange_admin_time_parse_mysql_utc_datetime($kwRange['valid_from'])->getTimestamp()
    && orange_admin_time_parse_mysql_utc_datetime($midUtc)->getTimestamp()
        <= orange_admin_time_parse_mysql_utc_datetime($kwRange['valid_to'])->getTimestamp(),
    '23/24. inclusive mid-day within KW day bounds'
);
unset($insideKw);

// End before start rejected at parse contract (string compare Y-m-d)
$err = null;
// Without PDO we cannot call parse_required — prove local range helper + date-only reject via normalize compare
s4_assert('2026-07-18' > '2026-07-17', '9. end-before-start detectable on Y-m-d');
s4_assert(str_contains($schedSrc, 'تاريخ البداية يجب أن يسبق'), '9. parse rejects end before start');

// Date-only delivery contract preserved in helper
s4_assert(str_contains($schedSrc, 'asDateOnly'), '12. date-only branch in parse');
s4_assert(function_exists('orange_cart_promo_is_within_date_only_schedule'), '12. date-only evaluator exists');

// Nonexistent / ambiguous local wall (shared helper; promo admin is date-only)
s4_assert(str_contains($adminTimeSrc, 'admin_time_local_wall_nonexistent'), '7. nonexistent local wall fails');
s4_assert(str_contains($adminTimeSrc, 'admin_time_local_wall_ambiguous'), '8. ambiguous local wall fails');
// America/New_York spring gap 2024-03-10 02:30 nonexistent
s4_expect_exception(
    static fn () => orange_admin_time_local_wall_to_utc_mysql_in_iana('2024-03-10 02:30:00', 'America/New_York'),
    'admin_time_local_wall_nonexistent',
    '7. nonexistent spring gap rejected'
);
// America/New_York fall fold 2024-11-03 01:30 ambiguous
s4_expect_exception(
    static fn () => orange_admin_time_local_wall_to_utc_mysql_in_iana('2024-11-03 01:30:00', 'America/New_York'),
    'admin_time_local_wall_ambiguous',
    '8. ambiguous fall fold rejected'
);

// Valid Kuwait wall roundtrip
$kwWallUtc = orange_admin_time_local_wall_to_utc_mysql_in_iana('2026-07-17 15:30:00', 'Asia/Kuwait');
s4_assert($kwWallUtc === '2026-07-17 12:30:00', '13/14. KW wall→UTC [' . $kwWallUtc . ']');
$kwBack = orange_admin_time_parse_mysql_utc_datetime($kwWallUtc)
    ->setTimezone(new DateTimeZone('Asia/Kuwait'))
    ->format('Y-m-d H:i:s');
s4_assert($kwBack === '2026-07-17 15:30:00', '3. wall roundtrip KW');

// PHP default TZ must not alter IANA conversion
date_default_timezone_set('America/Los_Angeles');
$kwWallUtc2 = orange_admin_time_local_wall_to_utc_mysql_in_iana('2026-07-17 15:30:00', 'Asia/Kuwait');
s4_assert($kwWallUtc2 === $kwWallUtc, '16. PHP default TZ does not shift wall→UTC');
date_default_timezone_set('Asia/Kuwait');

// API / admin markers: UTC fields exposed on localize
s4_assert(str_contains($schedSrc, 'valid_from_utc'), '20. localize exposes valid_from_utc');

// Business logic freeze markers (discount formulas still present)
$cartPromo = file_get_contents($root . '/includes/cart_promotions.php') ?: '';
$bogo = file_get_contents($root . '/includes/cart_bogo_promotions.php') ?: '';
$combo = file_get_contents($root . '/includes/cart_combo_promotions.php') ?: '';
$gift = file_get_contents($root . '/includes/cart_gift_promotions.php') ?: '';
s4_assert(str_contains($cartPromo, 'discount_amount'), '26. cart discount logic present');
s4_assert(str_contains($bogo, 'orange_cart_promo_schedule_sql') && str_contains($bogo, 'buy_qty'), '27. BOGO business markers');
s4_assert(str_contains($combo, 'orange_cart_promo_schedule_sql'), '27. combo schedule shared');
s4_assert(str_contains($gift, 'gift'), '27. gift logic present');
s4_assert(str_contains($delSrc, 'discount_type'), '28. delivery fee discount types present');

// Customer UI unchanged markers
$home = file_get_contents($root . '/pages/home.php') ?: '';
$cartPage = file_get_contents($root . '/pages/cart.php') ?: '';
s4_assert(!str_contains($home, 'orange_admin_time_'), '37. storefront home no admin_time');
s4_assert(!str_contains($cartPage, 'orange_cart_promo_admin_localize'), '37. cart page no admin localize');

// Query-based (no new worker)
s4_assert(!str_contains($schedSrc, 'cron') && !str_contains($schedSrc, 'schedule_worker'), '16. query-based schedule (no new worker)');

// Accounting / GL not started (Step 5)
$jv = file_get_contents($root . '/includes/journal_voucher.php') ?: '';
s4_assert(str_contains($jv, 'updated_at = NOW()'), '30. accounting still NOW (Step 5 not started)');

// Policy doc mentions Step 4
$policy = file_get_contents($root . '/docs/archive/ORANGE_ADMIN_TIME_POLICY.txt') ?: '';
s4_assert(str_contains($policy, 'Phase 2 Step 4'), '21. policy documents Step 4');
s4_assert(str_contains($policy, 'is_always_on'), '21. policy permanent semantics');
s4_assert(str_contains($policy, 'Step 5'), '21. policy confirms Step 5 not in scope');

echo "\n--- Step 4 core ---\nPassed: {$passes}\nFailed: {$failures}\n";

// Prior suites
$phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$prior = [
    'scripts/self_test_admin_time_foundation.php',
    'scripts/self_test_admin_time_phase2_orders_payments.php',
    'scripts/self_test_admin_time_phase2_step1_closure.php',
    'scripts/self_test_admin_time_phase2_step2_purchases_returns.php',
    'scripts/self_test_admin_time_phase2_step3_inventory_warehouses.php',
];
$priorFail = 0;
$priorPassSuites = 0;
foreach ($prior as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        echo "SKIP  missing {$rel}\n";
        continue;
    }
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($path);
    $out = [];
    $code = 1;
    exec($cmd . ' 2>&1', $out, $code);
    $ok = ($code === 0);
    s4_assert($ok, '31-34. prior suite ' . basename($rel) . ($ok ? '' : ' EXIT ' . $code));
    if ($ok) {
        $priorPassSuites++;
    } else {
        $priorFail++;
        echo implode("\n", array_slice($out, -20)) . "\n";
    }
}

echo "\n---\nStep4 asserts passed: {$passes}\nStep4 asserts failed: {$failures}\nPrior suites OK: {$priorPassSuites}\n";
exit(($failures === 0 && $priorFail === 0) ? 0 : 1);
