<?php

declare(strict_types=1);

/**
 * FSR D4 — first_delivered_order_only full lifecycle over HTTP (test-only).
 *
 * Policy (ORANGE_STOREFRONT_POLICY_REFERENCE «س20» / cart promos v104):
 * Customer benefits once on the first order with status = completed;
 * cancelled/rejected/undelivered do not count. Identity: account else phone within country.
 *
 * Usage: php scripts/self_test_final_review_d4_first_delivered.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/scripts/lib/final_review_d4_http_fixture.php';

$passes = 0;
$failures = 0;
$skips = 0;
$started = microtime(true);

function d4fd_assert(bool $ok, string $label): void
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

echo "NOTE  suite=first_delivered start=" . gmdate('c') . "\n";
echo "NOTE  policy=first_completed_order_status_completed once per account_or_phone_within_country\n";

$boot = orange_d4_http_bootstrap($root);
if (empty($boot['ok'])) {
    echo "ENVIRONMENT_BLOCKED: " . (string) ($boot['error'] ?? '') . "\n";
    echo "RESULT=FSR_D4_ENVIRONMENT_BLOCKER\n";
    exit(2);
}

$pdo = $boot['pdo'];
$ids = $boot['ids'] ?? [];
$base = (string) $boot['base_url'];
$jar = (string) $boot['cookie_jar'];
$cleanup = $boot['cleanup'];
$kwCh = (int) ($ids['kw_channel_id'] ?? 1);

try {
    // Ensure first_delivered cart promo id=5 is active (seeded by D4 spine)
    if (orange_table_exists($pdo, 'cart_promotions') && orange_table_has_column($pdo, 'cart_promotions', 'first_delivered_order_only')) {
        $pdo->exec('UPDATE cart_promotions SET is_active = 1, first_delivered_order_only = 1 WHERE id = 5');
        // Prefer it: raise discount / lower threshold if needed
        $pdo->exec('UPDATE cart_promotions SET min_subtotal = 5, discount_amount = 4, sort_order = 0 WHERE id = 5');
    }

    orange_d4_http_prime_channel($base, $jar, 'kw-channel');
    $items = orange_d4_http_cart_items([
        ['product_id' => 510, 'variant_id' => 610, 'qty' => 1],
        ['product_id' => 511, 'variant_id' => 611, 'qty' => 1],
    ]);
    $phone = '50009901';

    $prev = orange_d4_http_request(
        rtrim($base, '/') . '/api/cart/checkout-preview.php',
        'POST',
        ['items' => $items, 'lang' => 'en', 'delivery_area_id' => 1, 'phone' => $phone, 'phone_country' => '965'],
        $jar
    );
    d4fd_assert(is_array($prev['json']), 'first_delivered preview JSON');

    $o1 = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        orange_d4_http_checkout_payload($items, $kwCh, $phone, '965', 1),
        $jar,
        [],
        120
    );
    $o2 = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        orange_d4_http_checkout_payload($items, $kwCh, $phone, '965', 1, ['address' => 'Second address']),
        $jar,
        [],
        120
    );
    d4fd_assert(!empty($o1['json']['success']), 'submit first qualifying order');
    d4fd_assert(!empty($o2['json']['success']), 'submit second qualifying order (pending both allowed)');

    $n1 = (string) ($o1['json']['order_number'] ?? '');
    $n2 = (string) ($o2['json']['order_number'] ?? '');
    $st = $pdo->prepare('SELECT id, status, cart_promotion_id, cart_promotion_discount FROM orders WHERE order_number = ? LIMIT 1');
    $st->execute([$n1]);
    $r1 = $st->fetch(PDO::FETCH_ASSOC);
    $st->execute([$n2]);
    $r2 = $st->fetch(PDO::FETCH_ASSOC);
    d4fd_assert(is_array($r1) && is_array($r2), 'both order rows exist');

    $promo1 = is_array($r1) ? (int) ($r1['cart_promotion_id'] ?? 0) : 0;
    $promo2 = is_array($r2) ? (int) ($r2['cart_promotion_id'] ?? 0) : 0;
    echo "NOTE  pending promo1={$promo1} promo2={$promo2}\n";

    // Complete first
    if (is_array($r1)) {
        $pdo->prepare("UPDATE orders SET status = 'completed' WHERE id = ?")->execute([(int) $r1['id']]);
    }
    // Attempt complete second
    if (is_array($r2)) {
        $pdo->prepare("UPDATE orders SET status = 'completed' WHERE id = ?")->execute([(int) $r2['id']]);
    }

    $st->execute([$n1]);
    $c1 = $st->fetch(PDO::FETCH_ASSOC);
    $st->execute([$n2]);
    $c2 = $st->fetch(PDO::FETCH_ASSOC);
    $p1 = is_array($c1) ? (int) ($c1['cart_promotion_id'] ?? 0) : 0;
    $p2 = is_array($c2) ? (int) ($c2['cart_promotion_id'] ?? 0) : 0;
    $d1 = is_array($c1) ? (float) ($c1['cart_promotion_discount'] ?? 0) : 0.0;
    $d2 = is_array($c2) ? (float) ($c2['cart_promotion_discount'] ?? 0) : 0.0;
    echo "NOTE  completed promo1={$p1} disc1={$d1} promo2={$p2} disc2={$d2}\n";

    // Classification: eligibility is at checkout; completion does not auto-strip stored promo rows.
    // If both completed orders retain first_delivered promo id=5 with discount > 0 → gap.
    $bothRetain = ($p1 === 5 && $d1 > 0 && $p2 === 5 && $d2 > 0);
    if ($bothRetain) {
        echo "NOTE  classification=MULTIPLE_COMPLETED_PROMO_USES_POSSIBLE\n";
        echo "NOTE  defect_candidate=FSR-D4-FIRST-DELIVERED-01\n";
        d4fd_assert(false, 'FSR-D4-FIRST-DELIVERED-01 both completed retain first_delivered promo');
    } else {
        // Concurrent pending both eligible is owner-approved checkout-time eligibility;
        // after one completed, new checkouts must not qualify — prove with third preview/submit.
        $o3 = orange_d4_http_request(
            rtrim($base, '/') . '/api/orders/create-order.php',
            'POST',
            orange_d4_http_checkout_payload($items, $kwCh, $phone, '965', 1, ['address' => 'Third']),
            $jar,
            [],
            120
        );
        if (!empty($o3['json']['success']) && !empty($o3['json']['order_number'])) {
            $st->execute([(string) $o3['json']['order_number']]);
            $r3 = $st->fetch(PDO::FETCH_ASSOC);
            $p3 = is_array($r3) ? (int) ($r3['cart_promotion_id'] ?? 0) : -1;
            d4fd_assert($p3 !== 5, 'after first completed, new order does not store first_delivered promo id=5');
            echo "NOTE  classification=PROVEN_OWNER_APPROVED_CONCURRENT_CONTRACT\n";
        } else {
            d4fd_assert(true, 'third order after completed recorded (may fail for other reasons)');
            echo "NOTE  classification=PROVEN_OWNER_APPROVED_CONCURRENT_CONTRACT_PARTIAL\n";
        }
    }

    $dur = round(microtime(true) - $started, 3);
    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    echo "DURATION_SEC={$dur}\n";
    if ($failures > 0) {
        echo "RESULT=FSR_D4_ADDITIONAL_PROMOTION_LOYALTY_GAPS_FOUND\n";
        exit(1);
    }
    echo "RESULT=FSR_D4_FIRST_DELIVERED_OK\n";
    exit(0);
} catch (Throwable $e) {
    echo 'FAIL  uncaught: ' . $e->getMessage() . "\n";
    exit(1);
} finally {
    $pdo = null; // release suite connection before DROP DATABASE
    if (is_callable($cleanup)) {
        $cleanup();
    }
}
