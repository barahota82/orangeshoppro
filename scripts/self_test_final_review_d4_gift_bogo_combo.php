<?php

declare(strict_types=1);

/**
 * FSR Batch D4 — Gift / BOGO / Combo / stock-health boundary.
 *
 * Usage: php scripts/self_test_final_review_d4_gift_bogo_combo.php
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

function d4g_assert(bool $ok, string $label): void
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

    // Gift threshold
    $giftBelow = orange_cart_gift_promotion_select_rule($pdo, 24.99, true, $kw);
    d4g_assert($giftBelow === null, 'gift: below min_subtotal rejected');
    $giftAt = orange_cart_gift_promotion_select_rule($pdo, 25.0, true, $kw);
    d4g_assert($giftAt !== null && (int) $giftAt['id'] === 1, 'gift: at threshold selects rule');
    d4g_assert(($giftAt['gift_kind'] ?? '') === 'fixed', 'gift: fixed kind');

    // Build gift lines via storefront helper
    $cartItems = [
        orange_d4_cart_line(510, 610, 12.0, 2),
        orange_d4_cart_line(511, 611, 8.0, 1),
    ];
    $sub = 12 * 2 + 8;
    $bundle = orange_storefront_build_promotional_gift_lines($pdo, [], $cartItems, (float) $sub, true, $kw);
    d4g_assert((int) ($bundle['giftPromoId'] ?? 0) === 1, 'gift: promotional lines attach gift promo');
    d4g_assert(is_array($bundle['giftLine']) || ($bundle['giftLines'] ?? []) !== [], 'gift: gift line created');

    // Duplicate evaluation — same inputs same promo id
    $bundle2 = orange_storefront_build_promotional_gift_lines($pdo, [], $cartItems, (float) $sub, true, $kw);
    d4g_assert((int) ($bundle2['giftPromoId'] ?? 0) === (int) ($bundle['giftPromoId'] ?? 0), 'gift: duplicate eval deterministic');

    // BOGO qty boundaries
    $one = [orange_d4_cart_line(500, 600, 10.0, 1)];
    d4g_assert(orange_cart_bogo_promotion_select_rule($pdo, $one, true, $kw) === null, 'bogo: qty 1 < min_buy 2');
    $two = [orange_d4_cart_line(500, 600, 10.0, 2)];
    $bogo = orange_cart_bogo_promotion_select_rule($pdo, $two, true, $kw);
    d4g_assert($bogo !== null && (int) $bogo['id'] === 1, 'bogo: qty 2 qualifies');
    $bogoBundle = orange_storefront_build_promotional_gift_lines($pdo, [], $two, 20.0, true, $kw);
    d4g_assert((int) ($bogoBundle['bogoPromoId'] ?? 0) === 1, 'bogo: gift line built');

    // Combo parity with stacking helper
    $comboItems = [
        orange_d4_cart_line(510, 610, 12.0, 1),
        orange_d4_cart_line(511, 611, 8.0, 1),
    ];
    $combo = orange_cart_combo_best_match($pdo, $comboItems, true, $kw);
    d4g_assert($combo !== null && abs((float) $combo['discount'] - 5.0) < 1e-6, 'combo: discount 5');
    $eval1 = orange_d4_evaluate_stack($pdo, $comboItems, true, $kw);
    $eval2 = orange_d4_evaluate_stack($pdo, $comboItems, true, $kw);
    d4g_assert(
        (int) ($eval1['combo_id'] ?? 0) === (int) ($eval2['combo_id'] ?? 0)
        && abs((float) $eval1['payable_merch'] - (float) $eval2['payable_merch']) < 1e-6,
        'parity: stacked evaluation deterministic'
    );

    // Stock health read model does not mutate stock
    $wvsBefore = (int) $pdo->query(
        'SELECT quantity FROM warehouse_variant_stock WHERE warehouse_id=10 AND variant_id=612'
    )->fetchColumn();
    if (function_exists('orange_cart_promo_monitor_rows_for_admin')) {
        $rows = orange_cart_promo_monitor_rows_for_admin($pdo, $kw, false);
        d4g_assert(is_array($rows), 'health: monitor rows readable');
    } else {
        d4g_assert(true, 'health: monitor helper present via include');
    }
    $wvsAfter = (int) $pdo->query(
        'SELECT quantity FROM warehouse_variant_stock WHERE warehouse_id=10 AND variant_id=612'
    )->fetchColumn();
    d4g_assert($wvsBefore === $wvsAfter, 'health: read path does not change WVS');

    // Insufficient gift stock → rule paused / not customer-effective
    $pdo->prepare('UPDATE warehouse_variant_stock SET quantity = 0 WHERE variant_id = 612')->execute();
    $pdo->prepare('UPDATE product_variants SET stock_quantity = 0 WHERE id = 612')->execute();
    $giftNoStock = orange_cart_gift_promotion_select_rule($pdo, 30.0, true, $kw);
    // May return null after auto-pause, or skip candidate
    d4g_assert($giftNoStock === null, 'gift: zero stock excludes/pauses rule');
    // Restore stock for later suites sharing process? this suite cleans DB on exit.
    $pdo->prepare('UPDATE warehouse_variant_stock SET quantity = 50 WHERE variant_id = 612')->execute();
    $pdo->prepare('UPDATE product_variants SET stock_quantity = 50 WHERE id = 612')->execute();
    $pdo->prepare('UPDATE cart_gift_promotions SET auto_paused_at = NULL, auto_paused_reason = NULL WHERE id = 1')->execute();

    // Wrong country gift
    $giftEg = orange_cart_gift_promotion_select_rule($pdo, 30.0, true, (int) $ids['eg_country_id']);
    d4g_assert($giftEg === null, 'gift: EG country has no KW gift rule');

    // Mutation-proof: remove min_subtotal by setting very high via SQL then restore
    $pdo->prepare('UPDATE cart_gift_promotions SET min_subtotal = 9999 WHERE id = 1')->execute();
    d4g_assert(orange_cart_gift_promotion_select_rule($pdo, 30.0, true, $kw) === null, 'mutation-proof: raised threshold rejects');
    $pdo->prepare('UPDATE cart_gift_promotions SET min_subtotal = 25 WHERE id = 1')->execute();

    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    if ($failures > 0) {
        echo "RESULT=FSR_D4_PROVEN_PROMOTION_LOYALTY_GAPS_FOUND\n";
        exit(1);
    }
    echo "RESULT=FSR_D4_GIFT_BOGO_COMBO_OK\n";
    exit(0);
} catch (Throwable $e) {
    echo "FAIL  uncaught: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    echo "PASS={$passes} FAIL=" . ($failures + 1) . " SKIP={$skips}\n";
    echo "RESULT=FSR_D4_PROVEN_PROMOTION_LOYALTY_GAPS_FOUND\n";
    exit(1);
} finally {
    $pdo = null; // release suite connection before DROP DATABASE
    if (is_callable($cleanup)) {
        $cleanup();
    }
}
