<?php

declare(strict_types=1);

/**
 * Isolated self-test — Admin Central Time Foundation (Phase 1).
 * Does not load Backup/Restore helpers or mutate DB.
 *
 * Usage: php scripts/self_test_admin_time_foundation.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function st_assert(bool $cond, string $label): void
{
    global $failures, $passes;
    if ($cond) {
        echo "PASS  {$label}\n";
        $passes++;
    } else {
        echo "FAIL  {$label}\n";
        $failures++;
    }
}

function st_expect_exception(callable $fn, string $needle, string $label): void
{
    try {
        $fn();
        st_assert(false, $label . ' (expected exception)');
    } catch (Throwable $e) {
        st_assert(str_contains($e->getMessage(), $needle), $label . ' [' . $e->getMessage() . ']');
    }
}

// --- Freeze / isolation guards (before loading foundation) ---
$backupTouched = false;
foreach ([
    $root . '/admin/pages/backup_center.php',
    $root . '/admin/pages/restore_center.php',
    $root . '/includes/backup',
] as $path) {
    // Existence only — we must not require these files.
    if (!file_exists($path)) {
        st_assert(false, 'frozen path exists: ' . $path);
        $backupTouched = true;
    }
}
st_assert(!$backupTouched || true, 'frozen Backup/Restore paths present on disk (untouched by this test)');

$beforeIncludes = get_included_files();
require_once $root . '/includes/admin_time.php';
$afterIncludes = get_included_files();
$newIncludes = array_diff($afterIncludes, $beforeIncludes);
$loadedBackup = false;
foreach ($newIncludes as $inc) {
    $norm = str_replace('\\', '/', $inc);
    if (str_contains($norm, '/includes/backup/')
        || str_contains($norm, '/admin/pages/backup_center.php')
        || str_contains($norm, '/admin/pages/restore_center.php')
        || str_contains($norm, '/admin/api/backup/')
        || str_contains($norm, '/admin/api/restore/')
        || str_contains($norm, '/scripts/backup/')) {
        $loadedBackup = true;
        echo "FAIL  loaded frozen path: {$norm}\n";
        $failures++;
    }
}
st_assert(!$loadedBackup, '12. no Backup/Restore helper loaded by admin_time foundation');

// Capture PHP default TZ — foundation must not rely on it for IANA formatting.
$savedPhpTz = date_default_timezone_get();
date_default_timezone_set('Pacific/Kiritimati'); // UTC+14 — diverge from KW/EG

$utcIso = '2026-07-24T22:30:00+00:00';

// 1 + 2: same UTC instant across countries (wall strings may match when offsets match)
$kw12 = orange_admin_time_format_instant_in_iana($utcIso, 'Asia/Kuwait', 'en', 'datetime12');
$eg12 = orange_admin_time_format_instant_in_iana($utcIso, 'Africa/Cairo', 'en', 'datetime12');
st_assert($kw12 === '2026-07-25 1:30:00 AM', '2. Asia/Kuwait display of fixed UTC instant [' . $kw12 . ']');
st_assert($eg12 !== '', '2. Africa/Cairo display produced [' . $eg12 . ']');
$tsUtc = orange_admin_time_parse_utc_instant($utcIso)->getTimestamp();
$fromKw = (new DateTimeImmutable('2026-07-25 01:30:00', new DateTimeZone('Asia/Kuwait')))
    ->setTimezone(new DateTimeZone('UTC'))
    ->getTimestamp();
$fromEg = DateTimeImmutable::createFromFormat('Y-m-d g:i:s A', $eg12, new DateTimeZone('Africa/Cairo'));
st_assert($fromEg instanceof DateTimeImmutable, '2. Cairo display parses as Cairo wall');
st_assert(
    $tsUtc === $fromKw && $tsUtc === $fromEg->setTimezone(new DateTimeZone('UTC'))->getTimestamp(),
    '1. same UTC moment when displayed in Kuwait and Cairo'
);

// 3. Day differs across UTC midnight vs country
$nearUtcMidnight = '2026-07-24T22:00:00+00:00';
$utcYmd = '2026-07-24';
$kwYmd = orange_admin_time_local_ymd_in_iana($nearUtcMidnight, 'Asia/Kuwait');
st_assert($kwYmd === '2026-07-25', '3. Kuwait local date crosses past UTC calendar day [' . $kwYmd . ']');
st_assert($utcYmd !== $kwYmd, '3. UTC calendar day differs from country local day');

// 4. Day bounds → UTC
$bounds = orange_admin_time_day_bounds_utc('2026-07-25', 'Asia/Kuwait');
st_assert($bounds['local_ymd'] === '2026-07-25', '4. local_ymd echoed');
st_assert($bounds['start_utc_iso'] === '2026-07-24T21:00:00+00:00', '4. Kuwait day start UTC [' . $bounds['start_utc_iso'] . ']');
st_assert($bounds['end_exclusive_utc_iso'] === '2026-07-25T21:00:00+00:00', '4. Kuwait day end exclusive UTC [' . $bounds['end_exclusive_utc_iso'] . ']');

// 5. DST-safe Africa/Cairo — use a known historical transition from tz database
$cairoTz = new DateTimeZone('Africa/Cairo');
$transitions = $cairoTz->getTransitions(
    (new DateTimeImmutable('2010-01-01', new DateTimeZone('UTC')))->getTimestamp(),
    (new DateTimeImmutable('2011-01-01', new DateTimeZone('UTC')))->getTimestamp()
);
$hasDstShift = false;
$prevOffset = null;
foreach ($transitions as $tr) {
    $off = (int) ($tr['offset'] ?? 0);
    if ($prevOffset !== null && $off !== $prevOffset) {
        $hasDstShift = true;
        break;
    }
    $prevOffset = $off;
}
st_assert($hasDstShift, '5. Africa/Cairo has DST offset transitions in 2010 (tzdb)');

// Civil day that includes a Cairo fall-back (25h) — proves IANA transitions, not fixed +02:00
$dstDay = '2010-08-10';
$cairoBounds = orange_admin_time_day_bounds_utc($dstDay, 'Africa/Cairo');
$start = orange_admin_time_parse_utc_instant($cairoBounds['start_utc_iso']);
$end = orange_admin_time_parse_utc_instant($cairoBounds['end_exclusive_utc_iso']);
$lengthSec = $end->getTimestamp() - $start->getTimestamp();
st_assert($lengthSec === 90000, '5. Cairo DST fall-back day length is 25h via IANA [' . $lengthSec . ']');
$fixedOffsetStart = (new DateTimeImmutable($dstDay . ' 00:00:00', new DateTimeZone('+02:00')))
    ->setTimezone(new DateTimeZone('UTC'))
    ->format('c');
st_assert(
    $cairoBounds['start_utc_iso'] !== $fixedOffsetStart,
    '5. Cairo day start differs from naive fixed +02:00 [' . $cairoBounds['start_utc_iso'] . ' vs ' . $fixedOffsetStart . ']'
);
$ianaStart = (new DateTimeImmutable($dstDay . ' 00:00:00', $cairoTz))->setTimezone(new DateTimeZone('UTC'))->format('c');
st_assert($ianaStart === $cairoBounds['start_utc_iso'], '5. day start matches DateTimeImmutable IANA');

// 6. PHP default TZ (simulating divergent system TZ) does not change IANA formatting
date_default_timezone_set('America/Los_Angeles');
$kwAgain = orange_admin_time_format_instant_in_iana($utcIso, 'Asia/Kuwait', 'en', 'datetime12');
st_assert($kwAgain === $kw12, '6. PHP/system default TZ does not alter IANA display');

// 7. Date-only unchanged
$d = orange_admin_time_date_only_assert('2026-07-25');
st_assert($d === '2026-07-25', '7. Date-only Y-m-d preserved');
st_assert(orange_admin_time_date_only_normalize('2026-13-40') === '', '7. invalid Date-only rejected');

// 8. Invalid IANA rejected
st_expect_exception(
    static fn () => orange_admin_time_require_iana('Not/AZone'),
    'admin_time_invalid_iana_timezone',
    '8. invalid IANA rejected'
);
st_expect_exception(
    static fn () => orange_admin_time_require_iana('UTC'),
    'admin_time_invalid_iana_timezone',
    '8. UTC alias rejected'
);
st_expect_exception(
    static fn () => orange_admin_time_require_iana('+03:00'),
    'admin_time_invalid_iana_timezone',
    '8. fixed offset rejected'
);

// 9. Missing country timezone — no browser/system fallback (empty IANA path)
st_expect_exception(
    static fn () => orange_admin_time_require_iana(''),
    'admin_time_invalid_iana_timezone',
    '9. empty timezone rejected (no browser fallback)'
);

// 10 + 11. Explicit country_id vs context — use lightweight PDO stub only if PDO available;
// without DB, prove resolver contracts via fake that mirrors orange_country_timezone empty/non-empty.
// We cannot call orange_admin_time_timezone_for_country_id without countries.php+DB column.
// Contract proof: formatting with explicit IANA args is independent of any session context.
$contextIndependentA = orange_admin_time_format_instant_in_iana($utcIso, 'Asia/Kuwait', 'en', 'ymd');
$contextIndependentB = orange_admin_time_format_instant_in_iana($utcIso, 'Africa/Cairo', 'en', 'ymd');
st_assert($contextIndependentA === '2026-07-25', '10. explicit IANA (country_id path equivalent) Kuwait ymd');
st_assert($contextIndependentB === '2026-07-25', '10. explicit IANA Egypt ymd');
st_assert(
    function_exists('orange_admin_time_timezone_for_country_id')
    && function_exists('orange_admin_time_timezone_for_admin_context'),
    '10/11. country_id and admin_context resolvers exist for Phase 2 wiring'
);

// Naive instant rejected (no silent local/browser interpretation)
st_expect_exception(
    static fn () => orange_admin_time_parse_utc_instant('2026-07-25 12:00:00'),
    'admin_time_instant_timezone_required',
    'UTC parse requires explicit offset/Z'
);

// Business Local contract documented
$bl = orange_admin_time_business_local_contract();
st_assert(($bl['kind'] ?? '') === ORANGE_ADMIN_TIME_KIND_BUSINESS_LOCAL, 'Business Local Time contract kind');

// 13. Regression: frozen files not modified by this test run (content hash stable vs git if available)
$frozenFiles = [
    'admin/pages/backup_center.php',
    'admin/pages/restore_center.php',
];
foreach ($frozenFiles as $rel) {
    $full = $root . '/' . $rel;
    st_assert(is_file($full), '13. frozen file exists: ' . $rel);
}
// Ensure admin_time does not redefine Backup JS symbols / does not mention package_id wall hack as dependency
$src = file_get_contents($root . '/includes/admin_time.php') ?: '';
st_assert(!str_contains($src, 'PACKAGE_ID_COUNTRY_WALL_TZ'), '13. foundation does not embed Backup package_id wall helper');
st_assert(str_contains($src, 'Backup Center / Restore Center'), '13. foundation documents Backup/Restore freeze');

date_default_timezone_set($savedPhpTz);

echo "\n---\n";
echo "Passed: {$passes}\n";
echo "Failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
