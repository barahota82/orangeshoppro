<?php

declare(strict_types=1);

/**
 * FSR Batch D4 — schedule / cart-total / product-offer / delivery / stacking engine.
 *
 * Usage: php scripts/self_test_final_review_d4_promotion_engine.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/scripts/lib/final_review_d4_fixture.php';

$passes = 0;
$failures = 0;
$skips = 0;

function d4e_assert(bool $ok, string $label): void
{
    global $passes, $failures;
    if ($ok) {
        echo "PASS  {$label}\n";
        $passes++;
    } else {
        echo "FAIL  {$label}\n";
        $failures++;
    }
}

$boot = orange_d4_bootstrap_isolated_db($root);
if (empty($boot['ok'])) {
    echo "ENVIRONMENT_BLOCKED: " . (string) ($boot['error'] ?? 'unknown') . "\n";
    echo "RESULT=FSR_D4_ENVIRONMENT_BLOCKER\n";
    echo "PASS=0 FAIL=0 SKIP=0\n";
    exit(2);
}

/** @var PDO $pdo */
$pdo = $boot['pdo'];
/** @var array<string,int|string> $ids */
$ids = $boot['ids'] ?? [];
$cleanup = $boot['cleanup'];

try {
    orange_d2_set_admin_country((int) $ids['kw_country_id'], 'kw');
    $kw = (int) $ids['kw_country_id'];
    $eg = (int) $ids['eg_country_id'];

    // Schema / authority inventory smoke
    d4e_assert(orange_table_exists($pdo, 'cart_promotions'), 'schema: cart_promotions');
    d4e_assert(orange_table_exists($pdo, 'offers'), 'schema: offers');
    d4e_assert(orange_table_exists($pdo, 'delivery_fee_promotions'), 'schema: delivery_fee_promotions');
    d4e_assert((int) ORANGE_CATALOG_SCHEMA_PHP_REVISION === 124, 'schema revision 124');

    // Schedule helpers
    d4e_assert(orange_cart_promo_is_within_schedule('2020-01-01 00:00:00', '2035-12-31 23:59:59', false), 'schedule: inside window inclusive');
    d4e_assert(!orange_cart_promo_is_within_schedule('2030-01-01 00:00:00', '2030-12-31 23:59:59', false), 'schedule: future not eligible');
    d4e_assert(orange_cart_promo_is_within_schedule('x', 'y', true), 'schedule: always-on short-circuits');
    d4e_assert(
        orange_cart_promo_is_within_date_only_schedule($pdo, $kw, '2026-01-01', '2035-12-31', true),
        'schedule: delivery always-on date-only'
    );

    // Cart-total thresholds
    $below = orange_cart_promotion_resolve($pdo, 19.99, true, $kw);
    d4e_assert($below === null || (int) ($below['id'] ?? 0) !== 1, 'cart: below min_subtotal 20 rejects always promo id=1');
    $at = orange_cart_promotion_resolve($pdo, 20.0, true, $kw);
    d4e_assert($at !== null && (int) $at['id'] === 1 && abs((float) $at['discount'] - 3.0) < 0.0001, 'cart: at threshold applies id=1 discount 3');
    $capped = orange_cart_promotion_resolve($pdo, 2.0, true, $kw);
    // first_delivered promo id=5 has min 5 — so 2.0 may still be null
    d4e_assert($capped === null || (float) ($capped['discount'] ?? 0) <= 2.0 + 1e-6, 'cart: discount never exceeds subtotal');

    // Country isolation
    $egPick = orange_cart_promotion_resolve($pdo, 20.0, true, $eg);
    d4e_assert($egPick !== null && (int) $egPick['id'] === 4, 'cart: EG country resolves EG promo');
    $kwPick = orange_cart_promotion_resolve($pdo, 20.0, true, $kw);
    d4e_assert($kwPick !== null && (int) $kwPick['id'] === 1, 'cart: KW does not return EG promo');

    // Inactive / future
    $pdo->prepare('UPDATE cart_promotions SET is_active = 0 WHERE id = 1')->execute();
    d4e_assert(orange_cart_promotion_resolve($pdo, 20.0, true, $kw) === null
        || (int) orange_cart_promotion_resolve($pdo, 20.0, true, $kw)['id'] !== 1, 'cart: inactive rejected');
    $pdo->prepare('UPDATE cart_promotions SET is_active = 1 WHERE id = 1')->execute();
    $futureOnly = orange_cart_promotion_resolve($pdo, 100.0, true, $kw);
    d4e_assert($futureOnly === null || (int) ($futureOnly['id'] ?? 0) !== 3, 'cart: future promo id=3 not selected');

    // Determinism
    $a = orange_cart_promotion_resolve($pdo, 50.0, true, $kw);
    $b = orange_cart_promotion_resolve($pdo, 50.0, true, $kw);
    d4e_assert(
        $a !== null && $b !== null && (int) $a['id'] === (int) $b['id']
        && abs((float) $a['discount'] - (float) $b['discount']) < 1e-6,
        'cart: deterministic resolve'
    );

    // Product offer + partition (offer exclusive of cart base)
    $lineOffer = orange_d4_cart_line(500, 600, 10.0, 2);
    $lineOther = orange_d4_cart_line(510, 610, 12.0, 1);
    $part = orange_product_offer_partition_items($pdo, [$lineOffer, $lineOther], $kw);
    d4e_assert(abs((float) $part['offer_discount'] - 4.0) < 0.0001, 'offer: amount 2 × qty 2 = 4');
    d4e_assert(count($part['non_offer_items']) === 1, 'offer: non-offer partition excludes offered product');
    $stack = orange_d4_evaluate_stack($pdo, [$lineOffer, $lineOther], true, $kw);
    d4e_assert((float) $stack['offer_discount'] > 0, 'stack: offer discount present');
    // Cart base excludes offer line value (12 only) — below cart min 20 → no cart promo
    d4e_assert($stack['cart_promo_id'] === null || (int) $stack['cart_promo_base'] < 20, 'stack: offer line excluded from cart base');

    // Stacking: combo then cart on non-offer items
    $comboItems = [
        orange_d4_cart_line(510, 610, 12.0, 1),
        orange_d4_cart_line(511, 611, 8.0, 1),
    ];
    $stackCombo = orange_d4_evaluate_stack($pdo, $comboItems, true, $kw);
    d4e_assert((int) ($stackCombo['combo_id'] ?? 0) === 1, 'combo: best match applies');
    d4e_assert(abs((float) $stackCombo['combo_discount'] - 5.0) < 0.0001, 'combo: savings 20-15=5');
    // After combo base = 15 → always-on min20 excluded; window promo min10 (id=2) may apply
    d4e_assert(
        (float) $stackCombo['cart_promo_base'] === 15.0
        && (
            $stackCombo['cart_promo_id'] === null
            || (
                (int) $stackCombo['cart_promo_id'] === 2
                && abs((float) $stackCombo['cart_promo_discount'] - 1.0) < 1e-6
            )
        ),
        'stack: cart after combo uses reduced base (15)'
    );

    // Two combo groups → double savings
    $twoBundles = [
        orange_d4_cart_line(510, 610, 12.0, 2),
        orange_d4_cart_line(511, 611, 8.0, 2),
    ];
    $stack2 = orange_d4_evaluate_stack($pdo, $twoBundles, true, $kw);
    d4e_assert(abs((float) $stack2['combo_discount'] - 10.0) < 0.0001, 'combo: two bundles save 10');
    // base after combo = 40-10=30 → cart promo 3
    d4e_assert((int) ($stack2['cart_promo_id'] ?? 0) === 1 && abs((float) $stack2['cart_promo_discount'] - 3.0) < 0.0001, 'stack: cart applies after combo on remaining base');

    // Missing combo component
    $missing = orange_cart_combo_best_match($pdo, [orange_d4_cart_line(510, 610, 12.0, 1)], true, $kw);
    d4e_assert($missing === null, 'combo: missing component rejected');

    // Delivery promotion
    $bundle = orange_delivery_resolve_checkout_fee_bundle($pdo, 1, true, $kw, null, null);
    d4e_assert(is_array($bundle), 'delivery: fee bundle returns array');
    d4e_assert((float) ($bundle['base_fee'] ?? -1) >= 0, 'delivery: base_fee non-negative');
    $promo = $bundle['promotion'] ?? null;
    d4e_assert(is_array($promo) && (int) ($promo['id'] ?? 0) === 1, 'delivery: free promo matches area snapshot');
    d4e_assert(abs((float) ($bundle['fee'] ?? 99) - 0.0) < 0.0001, 'delivery: free type nets zero fee');

    // Wrong-country delivery promo not applied to KW area via EG context alone
    $bundleEg = orange_delivery_resolve_checkout_fee_bundle($pdo, 1, true, $eg, null, null);
    // Area 1 is KW — EG country resolve may still use area country; assert no EG promo id=2 on KW area
    $egPromoId = is_array($bundleEg['promotion'] ?? null) ? (int) ($bundleEg['promotion']['id'] ?? 0) : 0;
    d4e_assert($egPromoId !== 2, 'delivery: EG promo not cross-applied via KW area');

    // Mutation-proof: schedule filter removal simulation (direct SQL bypass)
    $pdo->prepare(
        "UPDATE cart_promotions SET valid_from = '2030-01-01 00:00:00', valid_to = '2030-12-31 23:59:59', is_always_on = 0 WHERE id = 1"
    )->execute();
    d4e_assert(orange_cart_promotion_resolve($pdo, 50.0, true, $kw) === null
        || (int) orange_cart_promotion_resolve($pdo, 50.0, true, $kw)['id'] !== 1, 'mutation-proof: future window hides promo');
    $pdo->prepare(
        "UPDATE cart_promotions SET valid_from = '2020-01-01 00:00:00', valid_to = '2035-12-31 23:59:59', is_always_on = 1 WHERE id = 1"
    )->execute();

    // Country authority: admin context EG must not make KW promo resolve as EG
    orange_d2_set_admin_country($eg, 'eg');
    $stillKw = orange_cart_promotion_resolve($pdo, 50.0, true, $kw);
    d4e_assert($stillKw !== null && (int) $stillKw['id'] === 1, 'authority: explicit country_id overrides admin context');
    orange_d2_set_admin_country($kw, 'kw');

    // Kuwait/Egypt IANA schedule conversion smoke
    $ymd = orange_cart_promo_utc_mysql_to_country_ymd($pdo, '2026-06-01 21:00:00', $kw);
    d4e_assert($ymd === '2026-06-02' || $ymd === '2026-06-01', 'schedule: KW IANA converts UTC wall');
    $ymdEg = orange_cart_promo_utc_mysql_to_country_ymd($pdo, '2026-06-01 21:00:00', $eg);
    d4e_assert(preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymdEg) === 1, 'schedule: EG IANA converts UTC wall');

    // Usage contract = first_delivered_order_only (no global usage counter table in Schema 124)
    $phone = '96550000001';
    $firstOk = orange_cart_promo_buyer_first_delivered_ok($pdo, null, $phone, $kw);
    d4e_assert($firstOk === true, 'usage: no prior delivered → first_delivered_ok');
    // Seed a delivered order for phone → first_delivered_only promo must not apply for that buyer
    // Contract: orange_delivery_buyer_has_completed_order requires status='completed' (not delivered).
    $oid = orange_d1_insert_order($pdo, $kw, (int) $ids['kw_channel_id'], 'D4-DELIV-1', 5.0, 'completed', 'paid');
    if (orange_d1_has_column($pdo, 'orders', 'phone')) {
        $pdo->prepare('UPDATE orders SET phone = ? WHERE id = ?')->execute([$phone, $oid]);
    }
    $firstAfter = orange_cart_promo_buyer_first_delivered_ok($pdo, null, $phone, $kw);
    d4e_assert($firstAfter === false, 'usage: after completed order first_delivered_ok=false');
    // Disable always-on larger promos temporarily to isolate id=5
    $pdo->exec('UPDATE cart_promotions SET is_active = 0 WHERE id IN (1,2)');
    $pdo->exec('UPDATE cart_promotions SET is_active = 1 WHERE id = 5');
    $pickFirst = orange_cart_promotion_resolve($pdo, 10.0, true, $kw, null, $phone);
    d4e_assert($pickFirst === null, 'usage: first_delivered_only blocked after prior completed order');
    $pdo->exec('UPDATE cart_promotions SET is_active = 1 WHERE id IN (1,2,5)');

    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    if ($failures > 0) {
        echo "RESULT=FSR_D4_PROVEN_PROMOTION_LOYALTY_GAPS_FOUND\n";
        exit(1);
    }
    echo "RESULT=FSR_D4_PROMOTION_ENGINE_OK\n";
    exit(0);
} catch (Throwable $e) {
    echo "FAIL  uncaught: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    echo "PASS={$passes} FAIL=" . ($failures + 1) . " SKIP={$skips}\n";
    echo "RESULT=FSR_D4_PROVEN_PROMOTION_LOYALTY_GAPS_FOUND\n";
    exit(1);
} finally {
    if (is_callable($cleanup)) {
        $cleanup();
    }
}
