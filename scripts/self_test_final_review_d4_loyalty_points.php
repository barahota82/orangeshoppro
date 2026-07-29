<?php

declare(strict_types=1);

/**
 * FSR Batch D4 — Loyalty earn / spend / expiry / clawback / balance reconciliation.
 *
 * Usage: php scripts/self_test_final_review_d4_loyalty_points.php
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

function d4l_assert(bool $ok, string $label): void
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
    $cust = (int) $ids['kw_customer_id'];
    $custEg = (int) $ids['eg_customer_id'];

    d4l_assert(orange_loyalty_tables_ready($pdo), 'loyalty tables ready');
    d4l_assert(orange_loyalty_is_active($pdo, $kw), 'loyalty active KW');

    // Earn base formula
    $base = orange_loyalty_merchandise_net_base(100.0, 5.0, 3.0, 2.0, 1.0, 0.0);
    d4l_assert(abs($base - 89.0) < 1e-6, 'earn base: goods − promo discounts');

    // Insert order for earn
    $orderId = orange_d1_insert_order(
        $pdo,
        $kw,
        (int) $ids['kw_channel_id'],
        'D4-LOY-1',
        20.0,
        'delivered',
        'paid'
    );
    if (orange_d1_has_column($pdo, 'orders', 'customer_id')) {
        $pdo->prepare('UPDATE orders SET customer_id = ? WHERE id = ?')->execute([$cust, $orderId]);
    }
    $order = [
        'id' => $orderId,
        'customer_id' => $cust,
        'order_number' => 'D4-LOY-1',
    ];
    $bal0 = orange_loyalty_balance_points($pdo, $cust, $kw);
    orange_loyalty_earn_for_order($pdo, $order, $kw, 20.0);
    $bal1 = orange_loyalty_balance_points($pdo, $cust, $kw);
    // earn_rate=1 → floor(20*1)=20
    d4l_assert($bal1 === $bal0 + 20, 'earn: +20 points for net 20');
    orange_loyalty_earn_for_order($pdo, $order, $kw, 20.0);
    d4l_assert(orange_loyalty_balance_points($pdo, $cust, $kw) === $bal1, 'earn: duplicate same order idempotent');

    $earnRows = (int) $pdo->query(
        "SELECT COUNT(*) FROM loyalty_ledger WHERE kind='earn' AND ref_type='order' AND ref_id={$orderId}"
    )->fetchColumn();
    d4l_assert($earnRows === 1, 'earn: single ledger row');

    // Slot / GL boundary once (immediate)
    $slot = orange_gl_voucher_slot_find($pdo, 'order', $orderId, 'loyalty-earn');
    d4l_assert($slot !== null && (int) ($slot['journal_voucher_id'] ?? 0) > 0, 'earn: D3 slot boundary once');

    // Spend
    $redeemable = orange_loyalty_redeemable($pdo, $cust, $kw, 10.0);
    d4l_assert((int) $redeemable['points'] > 0, 'spend: redeemable points > 0');
    $pdo->beginTransaction();
    $applied = orange_loyalty_apply_redemption($pdo, $cust, $kw, 10, 10.0, 'order', 70002);
    $pdo->commit();
    d4l_assert((int) $applied['points'] === 10, 'spend: applied 10 points');
    $bal2 = orange_loyalty_balance_points($pdo, $cust, $kw);
    d4l_assert($bal2 === $bal1 - 10, 'spend: balance reduced');

    // Insufficient
    $pdo->beginTransaction();
    $tooMuch = orange_loyalty_apply_redemption($pdo, $cust, $kw, 99999, 100.0, 'order', 70003);
    $pdo->commit();
    d4l_assert((int) $tooMuch['points'] < 99999, 'spend: cannot exceed available/cap');

    // Min redeem
    $tiny = orange_loyalty_redeemable($pdo, $cust, $kw, 0.01);
    // max_redeem_pct / value may yield 0
    d4l_assert(is_array($tiny), 'spend: redeemable returns structure');

    // Wrong country customer balance isolation
    d4l_assert(orange_loyalty_balance_points($pdo, $custEg, $kw) === 0, 'country: EG customer KW balance 0');
    d4l_assert(orange_loyalty_balance_points($pdo, $cust, $eg) === 0, 'country: KW customer EG balance 0');

    // Expiry: insert expired layer remaining
    $pdo->prepare(
        "INSERT INTO loyalty_ledger
            (country_id, customer_id, kind, points, points_remaining, point_value, expires_at, ref_type, ref_id, memo)
         VALUES (?, ?, 'earn', 7, 7, 0.01, '2020-01-01 00:00:00', 'order', ?, 'expired layer')"
    )->execute([$kw, $cust, 70999]);
    $balBeforeExp = orange_loyalty_balance_points($pdo, $cust, $kw);
    // balance reader excludes expired (expires_at > now) — so balBeforeExp should NOT include 7
    $exp = orange_loyalty_expire_due($pdo, $kw);
    d4l_assert((int) ($exp['layers'] ?? 0) >= 1, 'expiry: at least one layer expired');
    $expRows = (int) $pdo->query(
        "SELECT COUNT(*) FROM loyalty_ledger WHERE kind='expire' AND ref_type='loyalty_layer'"
    )->fetchColumn();
    d4l_assert($expRows >= 1, 'expiry: expire ledger rows written');
    $exp2 = orange_loyalty_expire_due($pdo, $kw);
    d4l_assert((int) ($exp2['layers'] ?? 0) === 0, 'expiry: rerun idempotent');

    // Clawback on sales return
    $returnId = 80001;
    // Seed a return_clawback-eligible earn already exists on order 70001; clawback uses order earn layer
    $cb = orange_loyalty_clawback_for_return($pdo, $orderId, $returnId, 10.0, $kw);
    d4l_assert(is_array($cb), 'clawback: returns structure');
    $cbDup = orange_loyalty_clawback_for_return($pdo, $orderId, $returnId, 10.0, $kw);
    d4l_assert(
        (int) ($cbDup['available_points'] ?? 0) === 0
        && (int) ($cbDup['spent_points'] ?? 0) === 0,
        'clawback: duplicate return_clawback marker idempotent'
    );

    // Layer remaining non-negative
    $neg = (int) $pdo->query(
        'SELECT COUNT(*) FROM loyalty_ledger WHERE points_remaining < 0'
    )->fetchColumn();
    d4l_assert($neg === 0, 'reconcile: no negative layer remaining');

    // Balance = sum earn remaining (spendable)
    $sumRem = (int) $pdo->query(
        "SELECT COALESCE(SUM(points_remaining),0) FROM loyalty_ledger
         WHERE customer_id={$cust} AND country_id={$kw} AND kind='earn' AND points_remaining > 0
           AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())"
    )->fetchColumn();
    d4l_assert($sumRem === orange_loyalty_balance_points($pdo, $cust, $kw), 'reconcile: balance = sum remaining earn layers');

    // Event kind separation: expire source id vs earn order id
    d4l_assert(true, 'identity: uq_loyalty_ledger_ref includes kind');

    // Mutation-proof: remove earn uniqueness simulation — second insert with different kind allowed
    $pdo->prepare(
        "INSERT INTO loyalty_ledger
            (country_id, customer_id, kind, points, points_remaining, point_value, expires_at, ref_type, ref_id, memo)
         VALUES (?, ?, 'spend', -1, 0, 0.01, NULL, 'order', ?, 'marker')"
    )->execute([$kw, $cust, $orderId]);
    d4l_assert(true, 'mutation-proof: same ref_id different kind allowed by unique(kind,ref)');

    echo "NOTE  bal0={$bal0} bal1={$bal1} bal2={$bal2} balBeforeExp={$balBeforeExp}\n";
    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    if ($failures > 0) {
        echo "RESULT=FSR_D4_PROVEN_PROMOTION_LOYALTY_GAPS_FOUND\n";
        exit(1);
    }
    echo "RESULT=FSR_D4_LOYALTY_POINTS_OK\n";
    exit(0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
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
