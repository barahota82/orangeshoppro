<?php

declare(strict_types=1);

/**
 * Phase 2 / Step 4 Closure — permanent filler + updated_at visibility + always_on short-circuit.
 *
 * Usage: php scripts/self_test_admin_time_phase2_step4_closure.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function s4c_assert(bool $ok, string $label): void
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
require_once $root . '/includes/cart_promo_schedule.php';
require_once $root . '/includes/promo_always_on.php';
require_once $root . '/includes/product_offers.php';

$pastFrom = '2000-01-01 00:00:00';
$pastTo = '2000-01-02 23:59:59';
$futureFrom = '2090-01-01 00:00:00';
$futureTo = '2090-12-31 23:59:59';

// 1–6 Permanent PHP evaluator ignores filler (past / future)
s4c_assert(orange_cart_promo_is_within_schedule($pastFrom, $pastTo, true), '1. permanent cart past filler still active');
s4c_assert(orange_cart_promo_is_within_schedule($futureFrom, $futureTo, true), '2. permanent cart future filler not upcoming/excluded');
s4c_assert(orange_cart_promo_is_within_schedule($pastFrom, $pastTo, true), '3. permanent gift uses same short-circuit');
s4c_assert(orange_cart_promo_is_within_schedule($pastFrom, $pastTo, true), '4. permanent BOGO uses same short-circuit');
s4c_assert(orange_cart_promo_is_within_schedule($pastFrom, $pastTo, true), '5. permanent combo uses same short-circuit');
s4c_assert(
    orange_product_offer_row_is_customer_effective([
        'is_active' => 1,
        'is_always_on' => 1,
        'valid_from' => $pastFrom,
        'valid_to' => $pastTo,
        'auto_paused_at' => null,
        'auto_paused_reason' => '',
    ]),
    '6. permanent product offer effective despite past filler'
);

// Scheduled still respects bounds
s4c_assert(!orange_cart_promo_is_within_schedule($pastFrom, $pastTo, false), '15. scheduled past window expired');
s4c_assert(!orange_cart_promo_is_within_schedule($futureFrom, $futureTo, false), '15. scheduled future window not active yet');

// 7 Delivery date-only permanent short-circuit (no PDO: function returns true before country lookup)
s4c_assert(
    orange_cart_promo_is_within_date_only_schedule(
        new class extends PDO {
            public function __construct()
            {
            }
        },
        0,
        '2000-01-01',
        '2000-01-02',
        true
    ),
    '7. permanent delivery date-only ignores bounds (always_on first)'
);

// 8 SPM NULL architecture + SQL short-circuit
$spmSrc = file_get_contents($root . '/includes/storefront_promo_messages.php') ?: '';
$spmManage = file_get_contents($root . '/admin/api/storefront_promo_messages/manage.php') ?: '';
s4c_assert(
    str_contains($spmSrc, 'is_always_on = 1 OR')
    && str_contains($spmManage, '$isAlwaysOn ? null'),
    '8. SPM permanent keeps NULL boundaries path'
);
s4c_assert(
    str_contains($spmSrc, "\$item['valid_from'] = ''")
    && str_contains($spmSrc, "\$item['valid_to'] = ''"),
    '8/10. SPM admin list clears schedule for always_on'
);

// 9 Monitor SQL always_on OR window
$monSrc = file_get_contents($root . '/includes/cart_promo_monitor.php') ?: '';
s4c_assert(
    str_contains($monSrc, 't.is_always_on = 1 OR (t.valid_from <= UTC_TIMESTAMP()'),
    '9. monitor does not exclude permanent via filler window alone'
);

// Shared SQL schedule fragment
$schedSrc = file_get_contents($root . '/includes/cart_promo_schedule.php') ?: '';
s4c_assert(
    str_contains($schedSrc, 'is_always_on = 1')
    && str_contains($schedSrc, 'UTC_TIMESTAMP()'),
    '9/SQL. cart schedule_sql short-circuits always_on'
);

// 10–11 Admin localize hides filler; keeps always_on label
$fakePdo = new class extends PDO {
    public function __construct()
    {
    }
};
$localized = orange_cart_promo_admin_localize_schedule_row(
    $fakePdo,
    [
        'is_always_on' => 1,
        'country_id' => 1,
        'valid_from' => $pastFrom,
        'valid_to' => $pastTo,
    ],
    1
);
s4c_assert(($localized['schedule_label'] ?? '') === 'تفعيل دائم', '10. admin schedule_label permanent');
s4c_assert(($localized['valid_from'] ?? 'x') === '' && ($localized['valid_to'] ?? 'x') === '', '10/11. filler cleared from admin valid_*');
s4c_assert(($localized['valid_from_utc'] ?? '') === $pastFrom, '11. filler retained only as valid_from_utc internal');

$jsSrc = file_get_contents($root . '/admin/partials/cart_promo_schedule_js.inc.php') ?: '';
s4c_assert(
    str_contains($jsSrc, "if (parseInt(r.is_always_on, 10) === 1)")
    && str_contains($jsSrc, "return 'تفعيل دائم'"),
    '10. list JS shows تفعيل دائم not filler dates'
);
s4c_assert(
    str_contains($jsSrc, 'Empty clears the field')
    || (str_contains($jsSrc, 'if (!iso)') && str_contains($jsSrc, "el.value = ''")),
    '11. edit JS clears empty ISO (no leftover filler)'
);

// Delivery list uses localize
$delSrc = file_get_contents($root . '/includes/delivery_areas.php') ?: '';
s4c_assert(
    str_contains($delSrc, 'orange_cart_promo_admin_localize_schedule_row'),
    '7/10. delivery admin list localizes / clears permanent filler'
);

// 12 Country switch / DST: always_on true regardless of IANA (no country in is_within_schedule)
date_default_timezone_set('America/Los_Angeles');
s4c_assert(orange_cart_promo_is_within_schedule($pastFrom, $pastTo, true), '12. PHP TZ switch does not affect permanent');
date_default_timezone_set('Africa/Cairo');
s4c_assert(orange_cart_promo_is_within_schedule($futureFrom, $futureTo, true), '12/13. Cairo TZ + future filler still permanent');
date_default_timezone_set('Asia/Kuwait');

// 14 updated_at not referenced in promotion admin surfaces
$promoPaths = [
    'admin/pages/cart_promotions.php',
    'admin/pages/cart_gift_promotions.php',
    'admin/pages/cart_bogo_promotions.php',
    'admin/pages/cart_combo_promotions.php',
    'admin/pages/delivery_promotions.php',
    'admin/pages/offers.php',
    'admin/pages/storefront_promo_messages.php',
    'admin/api/cart_promotions/manage.php',
    'admin/api/cart_gift_promotions/manage.php',
    'admin/api/cart_bogo_promotions/manage.php',
    'admin/api/cart_combo_promotions/manage.php',
    'admin/api/delivery_promotions/manage.php',
    'admin/api/offers/save.php',
    'admin/api/storefront_promo_messages/manage.php',
];
$updatedAtHit = false;
foreach ($promoPaths as $rel) {
    $src = file_get_contents($root . '/' . $rel) ?: '';
    if (preg_match('/\bupdated_at\b/', $src)) {
        $updatedAtHit = true;
        echo "NOTE  updated_at in {$rel}\n";
    }
}
s4c_assert(!$updatedAtHit, '14. updated_at not visible in Step 4 promotion admin pages/APIs (internal-only)');

// Customer effective + stock unpause use always_on
s4c_assert(
    orange_cart_promo_row_eligible_stock_auto_unpause([
        'is_active' => 1,
        'auto_paused_reason' => 'promo_stock',
        'is_always_on' => 1,
        'valid_from' => $pastFrom,
        'valid_to' => $pastTo,
    ]),
    '9. health/auto-unpause schedule check honors always_on'
);

// Freeze markers
$bc = file_get_contents($root . '/admin/pages/backup_center.php') ?: '';
$rc = file_get_contents($root . '/admin/pages/restore_center.php') ?: '';
s4c_assert(str_contains($bc, 'fmtTimestampDisplay') && str_contains($rc, 'fmtTimestampDisplay'), 'freeze Backup/Restore untouched');
$jv = file_get_contents($root . '/includes/journal_voucher.php') ?: '';
s4c_assert(str_contains($jv, 'updated_at = ?') && !str_contains($jv, 'updated_at = NOW()'), 'freeze Step 5 accounting complete (UTC writers)');

echo "\n---\nPassed: {$passes}\nFailed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
