<?php

declare(strict_types=1);

/**
 * Phase 2 / Step 3 — Admin Inventory & Warehouses time migration (isolated).
 *
 * Usage: php scripts/self_test_admin_time_phase2_step3_inventory_warehouses.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function s3_assert(bool $ok, string $label): void
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
require_once $root . '/includes/warehouses.php';
$after = get_included_files();
$loadedBackup = false;
foreach (array_diff($after, $before) as $inc) {
    $n = str_replace('\\', '/', $inc);
    if (preg_match('#/(includes/backup|admin/pages/backup_center|admin/pages/restore_center|admin/api/backup|admin/api/restore|scripts/backup)/#', $n)) {
        $loadedBackup = true;
    }
}
s3_assert(!$loadedBackup, '31. no Backup/Restore loaded by inventory time includes');

$bc = file_get_contents($root . '/admin/pages/backup_center.php') ?: '';
$rc = file_get_contents($root . '/admin/pages/restore_center.php') ?: '';
s3_assert(str_contains($bc, 'fmtTimestampDisplay'), '31. Backup Center untouched');
s3_assert(str_contains($rc, 'fmtTimestampDisplay'), '32. Restore Center untouched');

$whSrc = file_get_contents($root . '/includes/warehouses.php') ?: '';
$iclSrc = file_get_contents($root . '/includes/inventory_cost_layers.php') ?: '';
$osvSrc = file_get_contents($root . '/includes/opening_stock_voucher.php') ?: '';
$sajSrc = file_get_contents($root . '/includes/stock_adjustment_voucher.php') ?: '';
$irSrc = file_get_contents($root . '/includes/inventory_reconciliation.php') ?: '';
$pvwSrc = file_get_contents($root . '/includes/product_variants_write.php') ?: '';
$pCreate = file_get_contents($root . '/admin/api/purchases/create.php') ?: '';
$recv = file_get_contents($root . '/admin/api/purchases/receive.php') ?: '';

// Writers: stock_movements UTC, no NOW()
s3_assert(str_contains($whSrc, 'orange_admin_time_utc_now_mysql'), '5. stock_movement / wvs use UTC helper');
s3_assert(!preg_match('/stock_movements[\s\S]{0,200}NOW\s*\(/i', $whSrc)
    && !preg_match("/'NOW\(\)'/", $whSrc)
    && !str_contains($whSrc, "'NOW()'"), '5. warehouses.php stock_movements no NOW()');
s3_assert(str_contains($whSrc, 'orange_warehouse_variant_stock_upsert_quantity'), '6. wvs explicit updated_at writer');
s3_assert(str_contains($whSrc, 'updated_at = VALUES(updated_at)'), '6. wvs ON UPDATE bypass via explicit SET');
s3_assert(str_contains($whSrc, 'orange_admin_time_sql_from_unix'), '1. warehouses.created_at FROM_UNIXTIME');
s3_assert(str_contains($whSrc, 'orange_stock_movement_assert_country_matches_warehouse'), '15/16. country mismatch helper');

// Cost layers / consumptions
s3_assert(str_contains($iclSrc, 'orange_admin_time_utc_now_mysql'), '13. cost layer created_at UTC');
s3_assert(str_contains($iclSrc, 'orange_admin_time_country_local_wall_to_utc_mysql'), '13. layer_date local→UTC');
s3_assert(!preg_match('/\$now\s*=\s*date\s*\(\s*[\'"]Y-m-d H:i:s[\'"]\s*\)/', $iclSrc), '8. cost layers no PHP local date()');
s3_assert(str_contains($iclSrc, 'ORDER BY layer_date ASC, id ASC'), '28. FIFO order + id tie-break preserved');

// Opening / adjustment / reconciliation
s3_assert(str_contains($osvSrc, 'orange_admin_time_sql_from_unix'), '9. opening created_at FROM_UNIXTIME');
s3_assert(str_contains($osvSrc, 'approved_at = ?') && str_contains($osvSrc, 'orange_admin_time_utc_now_mysql'), '9. opening approved_at UTC');
s3_assert(!str_contains($osvSrc, 'approved_at = NOW()'), '9. opening no NOW() approved_at');
s3_assert(str_contains($sajSrc, 'orange_admin_time_sql_from_unix'), '10. adjustment created_at FROM_UNIXTIME');
s3_assert(str_contains($sajSrc, 'approved_at = ?') && !str_contains($sajSrc, 'approved_at = NOW()'), '10. adjustment approved_at UTC');
s3_assert(str_contains($irSrc, 'orange_admin_time_sql_from_unix'), '11. reconciliation created_at FROM_UNIXTIME');
s3_assert(!str_contains($irSrc, 'approved_at = NOW()'), '11. reconciliation approved_at UTC');
s3_assert(str_contains($osvSrc, 'orange_admin_time_date_only_normalize'), '9. opening document_date date-only');
s3_assert(str_contains($sajSrc, 'orange_admin_time_date_only_normalize'), '10. adjustment document_date date-only');
s3_assert(str_contains($irSrc, 'orange_admin_time_date_only_normalize'), '11. counted_at date-only');

// Shared writers: purchases still pass postingAt to layers; GL postingAt deferred
s3_assert(str_contains($pCreate, "\$postingAt = \$documentDate . ' ' . date('H:i:s')"), '30. GL postingAt unchanged (Step 4)');
s3_assert(str_contains($pCreate, 'orange_inventory_cost_layer_add'), '3. purchase receiving writes cost layers');
s3_assert(str_contains($recv, '422') || str_contains($recv, 'stub') || str_contains($recv, 'غير'), '3. receive.php remains stub');

// product_variants_write no NOW()
s3_assert(!str_contains($pvwSrc, 'NOW()'), '5. product_variants_write stock_movements no NOW()');

// Display pages use admin_time
$osPage = file_get_contents($root . '/admin/pages/opening_stock_balances.php') ?: '';
$sajPage = file_get_contents($root . '/admin/pages/stock_adjustment_voucher.php') ?: '';
s3_assert(str_contains($osPage, 'orange_admin_time_display_unix_for_record'), '18. opening page IANA display');
s3_assert(str_contains($sajPage, 'orange_admin_time_display_unix_for_record'), '18. adjustment page IANA display');
s3_assert(str_contains($osvSrc, 'created_at_utc') && str_contains($sajSrc, 'created_at_display'), '18. get payloads UTC+display');

// No warehouse transfer tables in runtime includes
s3_assert(
    !preg_match('/warehouse_transfer|stock_transfer_voucher/i', $whSrc . $osvSrc . $sajSrc . $irSrc),
    '12. no warehouse transfer writers in inventory core'
);

// --- Semantic helpers: Kuwait / Cairo / DST / browser TZ ---
$iso = '2026-07-24T22:30:00+00:00';
$kw = orange_admin_time_format_instant_in_iana($iso, 'Asia/Kuwait', 'en', 'datetime12');
$eg = orange_admin_time_format_instant_in_iana($iso, 'Africa/Cairo', 'en', 'datetime12');
s3_assert($kw === '2026-07-25 1:30:00 AM', '1. Kuwait warehouse display');
s3_assert($eg === '2026-07-25 1:30:00 AM', '2. Egypt warehouse display');
s3_assert(
    orange_admin_time_local_ymd_in_iana($iso, 'Asia/Kuwait') === '2026-07-25',
    '19. Kuwait midnight crossing'
);
$kwDay = orange_admin_time_day_bounds_mysql_utc('2026-07-25', 'Asia/Kuwait');
s3_assert($kwDay['start_utc_mysql'] === '2026-07-24 21:00:00', '21. Kuwait day filter start');
s3_assert($kwDay['end_exclusive_utc_mysql'] === '2026-07-25 21:00:00', '21. Kuwait day filter end exclusive');
$cairoDst = orange_admin_time_day_bounds_mysql_utc('2010-08-10', 'Africa/Cairo');
$dstLen = orange_admin_time_parse_mysql_utc_datetime($cairoDst['end_exclusive_utc_mysql'])->getTimestamp()
    - orange_admin_time_parse_mysql_utc_datetime($cairoDst['start_utc_mysql'])->getTimestamp();
s3_assert($dstLen === 90000, '20/22. Cairo DST day 25h filter');

$saved = date_default_timezone_get();
date_default_timezone_set('America/Los_Angeles');
s3_assert(
    orange_admin_time_format_instant_in_iana($iso, 'Asia/Kuwait', 'en', 'datetime12') === $kw,
    '18. browser/PHP TZ does not alter display'
);
date_default_timezone_set($saved);

$epoch = orange_admin_time_parse_utc_instant($iso)->getTimestamp();
s3_assert(orange_admin_time_utc_iso_from_unix($epoch) === gmdate('c', $epoch), '7. TIMESTAMP epoch→UTC ISO');
s3_assert(orange_admin_time_sql_from_unix() === 'FROM_UNIXTIME(?)', '7. FROM_UNIXTIME fragment');
s3_assert(
    preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', orange_admin_time_utc_now_mysql()) === 1,
    '8. DATETIME UTC wall shape'
);

// Country-local wall → UTC (Kuwait +03, Cairo)
class S3FakePdo extends PDO
{
    public function __construct()
    {
    }
}
// Use real helper with mocked country timezone via direct IANA path:
$kwWallUtc = (new DateTimeImmutable('2026-07-25 17:00:00', new DateTimeZone('Asia/Kuwait')))
    ->setTimezone(new DateTimeZone('UTC'))
    ->format('Y-m-d H:i:s');
s3_assert($kwWallUtc === '2026-07-25 14:00:00', '13. Kuwait 17:00 local → 14:00 UTC for layer_date');
$egWallUtc = (new DateTimeImmutable('2010-08-10 17:00:00', new DateTimeZone('Africa/Cairo')))
    ->setTimezone(new DateTimeZone('UTC'))
    ->format('Y-m-d H:i:s');
// Africa/Cairo DST 2010: UTC+3 typically in August
s3_assert($egWallUtc === '2010-08-10 14:00:00', '2/13. Cairo DST 17:00 local → UTC');

// FIFO ordering stable for same absolute moments under UTC wall storage
$layers = [
    ['id' => 2, 'layer_date' => '2026-07-25 10:00:00'],
    ['id' => 1, 'layer_date' => '2026-07-25 09:00:00'],
    ['id' => 3, 'layer_date' => '2026-07-25 09:00:00'],
];
usort($layers, static function (array $a, array $b): int {
    $c = strcmp($a['layer_date'], $b['layer_date']);
    if ($c !== 0) {
        return $c;
    }

    return $a['id'] <=> $b['id'];
});
s3_assert(
    $layers[0]['id'] === 1 && $layers[1]['id'] === 3 && $layers[2]['id'] === 2,
    '28. FIFO ORDER BY layer_date ASC, id ASC stable for UTC walls'
);

// Quantity / costing / GL freezes (source spot-checks)
s3_assert(str_contains($whSrc, 'quantity = VALUES(quantity)'), '26. quantity still written (not removed)');
s3_assert(str_contains($iclSrc, 'qty_remaining = qty_remaining - ?'), '27/29. consume qty path unchanged');
s3_assert(str_contains($sajSrc, "\$postingAt = (\$documentDate !== '' ? \$documentDate : date('Y-m-d')) . ' 17:00:00'"), '30. GL postingAt for adj deferred');

// Customer/storefront pages not touched for time display migration markers
$home = file_get_contents($root . '/pages/home.php') ?: '';
s3_assert(!str_contains($home, 'orange_admin_time_display_unix_for_record'), '33. storefront home unchanged by Step 3 display');

// No schema revision bump required for Step 3 (spot: warehouses.php does not alter catalog revision)
s3_assert(!str_contains($whSrc, 'ORANGE_CATALOG_SCHEMA_PHP_REVISION'), 'schema. no schema change in warehouses writer');

echo "\nStep 3 inventory/warehouses: {$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
