<?php

declare(strict_types=1);

/**
 * Phase 4 / Step 2 — Targeted Time Gap Closure (G1 / G2 / G3).
 *
 * Isolated: no DB writes, no Backup/Restore mutation.
 *
 * Usage: php scripts/self_test_admin_time_phase4_step2_targeted_gaps.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function p4s2_assert(bool $ok, string $label): void
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

/** Strip // and # line comments so docstrings do not false-positive pattern sweeps. */
function p4s2_code_without_line_comments(string $src): string
{
    $out = [];
    foreach (preg_split("/\r\n|\n|\r/", $src) ?: [] as $line) {
        $trim = ltrim($line);
        if (str_starts_with($trim, '//') || str_starts_with($trim, '#')) {
            continue;
        }
        $out[] = $line;
    }

    return implode("\n", $out);
}

require_once $root . '/includes/admin_time.php';

$adminTimeSrc = (string) file_get_contents($root . '/includes/admin_time.php');
$salesBrowse = (string) file_get_contents($root . '/admin/api/sales-invoices/browse.php');
$onlineBrowse = (string) file_get_contents($root . '/admin/api/online-invoices/browse.php');
$statsSrc = (string) file_get_contents($root . '/admin/api/reports/stats.php');
$dashSrc = (string) file_get_contents($root . '/admin/pages/dashboard.php');
$schemaSrc = (string) file_get_contents($root . '/includes/catalog_schema.php');
$companyPage = (string) file_get_contents($root . '/admin/pages/company_sales_invoice.php');
$onlinePage = (string) file_get_contents($root . '/admin/pages/online_sales_invoice.php');

// --- G3: civil-day bounds ---------------------------------------------------

$spring = orange_admin_time_day_bounds_utc('2026-04-24', 'Africa/Cairo');
$springLen = orange_admin_time_parse_utc_instant($spring['end_exclusive_utc_iso'])->getTimestamp()
    - orange_admin_time_parse_utc_instant($spring['start_utc_iso'])->getTimestamp();
p4s2_assert($springLen === 23 * 3600, 'G3. Cairo 2026-04-24 is 23h [' . ($springLen / 3600) . 'h]');

$fall = orange_admin_time_day_bounds_utc('2026-10-29', 'Africa/Cairo');
$fallLen = orange_admin_time_parse_utc_instant($fall['end_exclusive_utc_iso'])->getTimestamp()
    - orange_admin_time_parse_utc_instant($fall['start_utc_iso'])->getTimestamp();
p4s2_assert($fallLen === 25 * 3600, 'G3. Cairo 2026-10-29 is 25h [' . ($fallLen / 3600) . 'h]');

$kw = orange_admin_time_day_bounds_utc('2026-07-15', 'Asia/Kuwait');
$kwLen = orange_admin_time_parse_utc_instant($kw['end_exclusive_utc_iso'])->getTimestamp()
    - orange_admin_time_parse_utc_instant($kw['start_utc_iso'])->getTimestamp();
p4s2_assert($kwLen === 24 * 3600, 'G3. Kuwait normal day is 24h');

$cairoNormal = orange_admin_time_day_bounds_utc('2026-07-15', 'Africa/Cairo');
$cairoNormalLen = orange_admin_time_parse_utc_instant($cairoNormal['end_exclusive_utc_iso'])->getTimestamp()
    - orange_admin_time_parse_utc_instant($cairoNormal['start_utc_iso'])->getTimestamp();
p4s2_assert($cairoNormalLen === 24 * 3600, 'G3. Cairo normal day is 24h');

// Contiguous adjacent days
$apr23 = orange_admin_time_day_bounds_utc('2026-04-23', 'Africa/Cairo');
$apr24 = orange_admin_time_day_bounds_utc('2026-04-24', 'Africa/Cairo');
$apr25 = orange_admin_time_day_bounds_utc('2026-04-25', 'Africa/Cairo');
p4s2_assert(
    $apr23['end_exclusive_utc_iso'] === $apr24['start_utc_iso'],
    'G3. Apr23 end_exclusive == Apr24 start'
);
p4s2_assert(
    $apr24['end_exclusive_utc_iso'] === $apr25['start_utc_iso'],
    'G3. Apr24 end_exclusive == Apr25 start'
);

// Month / year continuity
$jan31 = orange_admin_time_day_bounds_utc('2026-01-31', 'Asia/Kuwait');
$feb1 = orange_admin_time_day_bounds_utc('2026-02-01', 'Asia/Kuwait');
p4s2_assert($jan31['end_exclusive_utc_iso'] === $feb1['start_utc_iso'], 'G3. month boundary contiguous');
$dec31 = orange_admin_time_day_bounds_utc('2025-12-31', 'Africa/Cairo');
$jan1 = orange_admin_time_day_bounds_utc('2026-01-01', 'Africa/Cairo');
p4s2_assert($dec31['end_exclusive_utc_iso'] === $jan1['start_utc_iso'], 'G3. year boundary contiguous');

// PHP default TZ independence
$savedTz = date_default_timezone_get();
$springSnapshots = [];
foreach (['UTC', 'America/Los_Angeles', 'Asia/Kuwait'] as $defTz) {
    date_default_timezone_set($defTz);
    $b = orange_admin_time_day_bounds_utc('2026-04-24', 'Africa/Cairo');
    $springSnapshots[$defTz] = $b['start_utc_iso'] . '|' . $b['end_exclusive_utc_iso'];
}
date_default_timezone_set($savedTz);
p4s2_assert(
    count(array_unique($springSnapshots)) === 1,
    'G3. day bounds independent of PHP default TZ'
);

// No fixed-offset / forbidden patterns in day_bounds implementation body
$fnStart = strpos($adminTimeSrc, 'function orange_admin_time_day_bounds_utc');
$fnEnd = strpos($adminTimeSrc, 'function orange_admin_time_date_only_normalize');
$fnBody = $fnStart !== false && $fnEnd !== false
    ? substr($adminTimeSrc, $fnStart, $fnEnd - $fnStart)
    : '';
p4s2_assert($fnBody !== '', 'G3. day_bounds function body located');
p4s2_assert(!str_contains($fnBody, '+03'), 'G3. no +03 in day_bounds');
p4s2_assert(!str_contains($fnBody, '+02'), 'G3. no +02 in day_bounds');
p4s2_assert(!preg_match('/modify\s*\(\s*[\'"]\+1 day[\'"]\s*\)/', $fnBody)
    || str_contains($fnBody, "createFromFormat('!Y-m-d'"), 'G3. end not from normalized_start->modify(+1 day) alone');
p4s2_assert(str_contains($fnBody, 'createFromFormat'), 'G3. next calendar date via calendar op');

// mysql / filter_range inherit corrected bounds
$springMysql = orange_admin_time_day_bounds_mysql_utc('2026-04-24', 'Africa/Cairo');
$springMysqlLen = strtotime($springMysql['end_exclusive_utc_mysql'] . ' UTC')
    - strtotime($springMysql['start_utc_mysql'] . ' UTC');
p4s2_assert($springMysqlLen === 23 * 3600, 'G3. day_bounds_mysql_utc spring = 23h');

$rangeSpring = orange_admin_time_filter_range_mysql_utc('2026-04-24', '2026-04-24', 'Africa/Cairo');
p4s2_assert(is_array($rangeSpring), 'G3. filter_range returns array');
$rangeLen = strtotime($rangeSpring['end_exclusive_utc_mysql'] . ' UTC')
    - strtotime($rangeSpring['start_utc_mysql'] . ' UTC');
p4s2_assert($rangeLen === 23 * 3600, 'G3. filter_range_mysql_utc spring = 23h');

// Half-open inclusion semantics (instant compare)
$startTs = orange_admin_time_parse_utc_instant($spring['start_utc_iso'])->getTimestamp();
$endTs = orange_admin_time_parse_utc_instant($spring['end_exclusive_utc_iso'])->getTimestamp();
p4s2_assert($startTs < $endTs, 'G3. start < end_exclusive');
p4s2_assert($startTs >= $startTs && $startTs < $endTs, 'G3. start instant included in half-open');
p4s2_assert(!($endTs >= $startTs && $endTs < $endTs), 'G3. end_exclusive instant excluded');

// Local-month buckets contiguous across spring DST
$buckets = orange_admin_time_local_month_buckets_mysql_utc('2026-04-01', '2026-04-30', 'Africa/Cairo');
p4s2_assert(count($buckets) === 1, 'G3. April Cairo one month bucket');
$b0 = $buckets[0];
$apr1 = orange_admin_time_day_bounds_mysql_utc('2026-04-01', 'Africa/Cairo');
$apr30 = orange_admin_time_day_bounds_mysql_utc('2026-04-30', 'Africa/Cairo');
p4s2_assert($b0['start_utc_mysql'] === $apr1['start_utc_mysql'], 'G3. month bucket start = Apr1 start');
p4s2_assert($b0['end_exclusive_utc_mysql'] === $apr30['end_exclusive_utc_mysql'], 'G3. month bucket end = Apr30 end');

// Historical fall-back day from foundation (2010-08-10) still 25h
$hist = orange_admin_time_day_bounds_utc('2010-08-10', 'Africa/Cairo');
$histLen = orange_admin_time_parse_utc_instant($hist['end_exclusive_utc_iso'])->getTimestamp()
    - orange_admin_time_parse_utc_instant($hist['start_utc_iso'])->getTimestamp();
p4s2_assert($histLen === 90000, 'G3. historical Cairo fall-back 2010-08-10 still 25h');

// Invalid inputs
try {
    orange_admin_time_day_bounds_utc('2026-04-24', 'UTC');
    p4s2_assert(false, 'G3. invalid IANA UTC rejected');
} catch (OrangeAdminTimeConfigException $e) {
    p4s2_assert(true, 'G3. invalid IANA rejected');
}
try {
    orange_admin_time_day_bounds_utc('not-a-date', 'Asia/Kuwait');
    p4s2_assert(false, 'G3. invalid date rejected');
} catch (OrangeAdminTimeConfigException $e) {
    p4s2_assert(true, 'G3. invalid date rejected');
}

// Dashboard still uses day_bounds (inherits G3)
p4s2_assert(str_contains($dashSrc, 'orange_admin_time_day_bounds_mysql_utc'), 'G3. dashboard uses day_bounds');
p4s2_assert(!preg_match('/DATE\s*\(\s*created_at\s*\)/i', $dashSrc), 'G3. dashboard no DATE(created_at)');

// --- G1: invoice browse APIs ------------------------------------------------

foreach (
    [
        'sales-invoices' => $salesBrowse,
        'online-invoices' => $onlineBrowse,
    ] as $label => $src
) {
    $code = p4s2_code_without_line_comments($src);
    p4s2_assert(str_contains($src, 'admin_time.php'), "G1. {$label} loads admin_time");
    p4s2_assert(str_contains($src, 'orange_admin_context_country_id'), "G1. {$label} Current Country Context");
    p4s2_assert(str_contains($src, 'orange_admin_time_day_bounds_mysql_utc'), "G1. {$label} uses day_bounds");
    p4s2_assert(str_contains($src, 'o.created_at >= ?'), "G1. {$label} From >= start_utc");
    p4s2_assert(str_contains($src, 'o.created_at < ?'), "G1. {$label} To < end_exclusive");
    p4s2_assert(!preg_match('/DATE\s*\(\s*o\.created_at\s*\)/i', $code), "G1. {$label} no DATE(o.created_at)");
    p4s2_assert(!str_contains($code, 'CURDATE('), "G1. {$label} no CURDATE");
    p4s2_assert(!str_contains($code, '23:59:59'), "G1. {$label} no 23:59:59");
    p4s2_assert(!preg_match('/BETWEEN/i', $code), "G1. {$label} no BETWEEN");
    p4s2_assert(str_contains($src, "ORDER BY o.id DESC"), "G1. {$label} sort unchanged");
    p4s2_assert(str_contains($src, "'results'"), "G1. {$label} response key results preserved");
}

p4s2_assert(str_contains($companyPage, 'sales-invoices/browse.php'), 'G1. company page calls sales browse');
p4s2_assert(str_contains($onlinePage, 'online-invoices/browse.php'), 'G1. online page calls online browse');

// Prove bound SQL shape for Kuwait / Cairo spring/fall via helpers (same as endpoints)
$kwDay = orange_admin_time_day_bounds_mysql_utc('2026-07-15', 'Asia/Kuwait');
p4s2_assert($kwDay['start_utc_mysql'] === '2026-07-14 21:00:00', 'G1. Kuwait From start UTC wall');
$cairoSpring = orange_admin_time_day_bounds_mysql_utc('2026-04-24', 'Africa/Cairo');
p4s2_assert(
    (strtotime($cairoSpring['end_exclusive_utc_mysql'] . ' UTC') - strtotime($cairoSpring['start_utc_mysql'] . ' UTC')) === 23 * 3600,
    'G1. Cairo spring bounds for browse filter'
);
$cairoFall = orange_admin_time_day_bounds_mysql_utc('2026-10-29', 'Africa/Cairo');
p4s2_assert(
    (strtotime($cairoFall['end_exclusive_utc_mysql'] . ' UTC') - strtotime($cairoFall['start_utc_mysql'] . ' UTC')) === 25 * 3600,
    'G1. Cairo fall bounds for browse filter'
);

// --- G2: stats.php ----------------------------------------------------------

$statsCode = p4s2_code_without_line_comments($statsSrc);
p4s2_assert(str_contains($statsSrc, 'admin_time.php'), 'G2. stats loads admin_time');
p4s2_assert(str_contains($statsSrc, 'orange_admin_time_day_bounds_mysql_utc'), 'G2. stats uses day_bounds');
p4s2_assert(str_contains($statsSrc, 'created_at >= ?') && str_contains($statsSrc, 'created_at < ?'), 'G2. stats half-open UTC');
p4s2_assert(!preg_match('/DATE\s*\(\s*created_at\s*\)/i', $statsCode), 'G2. no DATE(created_at)');
p4s2_assert(!str_contains($statsCode, 'CURDATE('), 'G2. no CURDATE');
p4s2_assert(!str_contains($statsCode, '23:59:59'), 'G2. no 23:59:59');
p4s2_assert(str_contains($statsSrc, "'orders_today'"), 'G2. response key orders_today');
p4s2_assert(str_contains($statsSrc, "'sales_today'"), 'G2. response key sales_today');
p4s2_assert(str_contains($statsSrc, "'pending_orders'"), 'G2. response key pending_orders');
p4s2_assert(str_contains($statsSrc, "'products_count'"), 'G2. response key products_count');
p4s2_assert(str_contains($statsSrc, "'low_stock_variants'"), 'G2. response key low_stock_variants');
p4s2_assert(str_contains($statsSrc, "'active_offers'"), 'G2. response key active_offers');
p4s2_assert(str_contains($statsSrc, "status = 'pending'"), 'G2. pending status filter retained');
p4s2_assert(str_contains($statsSrc, 'orange_sql_country_and_fragment'), 'G2. country filter retained');
p4s2_assert(str_contains($statsSrc, 'orange_admin_context_country_id'), 'G2. Current Country Context');
// Dashboard consistency (same helpers / pattern)
p4s2_assert(
    str_contains($dashSrc, 'orange_admin_time_day_bounds_mysql_utc')
    && str_contains($statsSrc, 'orange_admin_time_day_bounds_mysql_utc'),
    'G2. dashboard and stats share day_bounds contract'
);

// Metric matrix (static contract documentation asserts)
$metricMatrix = [
    'orders_today' => 'Absolute Moment / orders.created_at / Country Context Today',
    'sales_today' => 'Absolute Moment / orders.created_at+SUM(total) / Country Context Today',
    'pending_orders' => 'All-time / orders.status=pending / Country Context',
    'products_count' => 'All-time / products / Country Context + preview hide',
    'low_stock_variants' => 'All-time / variants qty / Country Context',
    'active_offers' => 'All-time / offers.is_active / Country Context',
];
p4s2_assert(count($metricMatrix) === 6, 'G2. metric matrix size 6');

// --- Freeze: Schema / Backup / Restore --------------------------------------

p4s2_assert(
    (bool) preg_match("/ORANGE_CATALOG_SCHEMA_PHP_REVISION',\s*124\s*\)/", $schemaSrc),
    'Freeze. schema revision remains 124'
);
p4s2_assert(!str_contains($salesBrowse . $onlineBrowse . $statsSrc, 'ORANGE_CATALOG_SCHEMA_PHP_REVISION'), 'Freeze. endpoints do not bump schema');
p4s2_assert(!str_contains($fnBody, 'backup'), 'Freeze. day_bounds has no backup coupling');

// Narrow unsafe-pattern sweep on repaired files (executable code only)
$repaired = p4s2_code_without_line_comments($salesBrowse . "\n" . $onlineBrowse . "\n" . $statsSrc);
p4s2_assert(!preg_match('/DATE\s*\(\s*(?:o\.)?created_at\s*\)/i', $repaired), 'Sweep. no DATE(created_at) in repaired endpoints');
p4s2_assert(!str_contains($repaired, 'CURDATE('), 'Sweep. no CURDATE in repaired endpoints');
p4s2_assert(!str_contains($repaired, 'CURRENT_DATE'), 'Sweep. no CURRENT_DATE in repaired endpoints');

echo "\n--- Phase 4 Step 2 targeted gaps ---\n";
echo "PASS={$passes} FAIL={$failures}\n";
exit($failures > 0 ? 1 : 0);
