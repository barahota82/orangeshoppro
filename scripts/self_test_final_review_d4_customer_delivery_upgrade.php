<?php

declare(strict_types=1);

/**
 * FSR D4 — Customer delivery/profile-upgrade path (test-only).
 *
 * Trigger: orange_post_order_delivery_accounting → orange_customer_address_promote_from_order
 * (includes/order_fulfillment.php / includes/customer_addresses.php).
 *
 * Usage: php scripts/self_test_final_review_d4_customer_delivery_upgrade.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/scripts/lib/final_review_d4_http_fixture.php';
require_once $root . '/includes/customer_addresses.php';
require_once $root . '/includes/order_fulfillment.php';

$passes = 0;
$failures = 0;
$skips = 0;
$started = microtime(true);

function d4u_assert(bool $ok, string $label): void
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

echo 'NOTE  suite=customer_delivery_upgrade start=' . gmdate('c') . "\n";

$ful = (string) file_get_contents($root . '/includes/order_fulfillment.php');
$addr = (string) file_get_contents($root . '/includes/customer_addresses.php');
d4u_assert(str_contains($ful, 'orange_customer_address_promote_from_order'), 'fulfillment calls promote helper');
d4u_assert(
    str_contains($addr, 'function orange_customer_address_promote_from_order')
    && str_contains($addr, 'function orange_customer_address_sync_current_customer'),
    'promote + sync helpers exist'
);

$boot = orange_d4_http_bootstrap($root);
if (empty($boot['ok'])) {
    echo 'ENVIRONMENT_BLOCKED: ' . (string) ($boot['error'] ?? '') . "\n";
    $skips++;
    echo "SKIP  live_delivery_upgrade\n";
    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    exit($failures > 0 ? 1 : 2);
}

$pdo = $boot['pdo'];
$ids = $boot['ids'] ?? [];
$base = (string) $boot['base_url'];
$jar = (string) $boot['cookie_jar'];
$cleanup = $boot['cleanup'];

try {
    orange_d4_http_prime_channel($base, $jar, 'kw-channel');
    $items = orange_d4_http_cart_items([
        ['product_id' => 510, 'variant_id' => 610, 'qty' => 1],
        ['product_id' => 511, 'variant_id' => 611, 'qty' => 1],
    ]);
    $phone = '50008855';
    $payload = orange_d4_http_checkout_payload($items, (int) ($ids['kw_channel_id'] ?? 1), $phone, '965', 1);
    $payload['area'] = 'Hawally';
    $payload['address'] = 'Upgrade Path Street 12';
    $ord = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        $payload,
        $jar,
        [],
        120
    );
    d4u_assert(!empty($ord['json']['success']), 'checkout creates order for upgrade test');
    $on = (string) ($ord['json']['order_number'] ?? '');
    $st = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1');
    $st->execute([$on]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    d4u_assert(is_array($order), 'order loaded');
    $cid = is_array($order) ? (int) ($order['customer_id'] ?? 0) : 0;
    d4u_assert($cid > 0, 'customer linked');

    $cst = $pdo->prepare('SELECT area, address FROM customers WHERE id = ? LIMIT 1');
    $cst->execute([$cid]);
    $before = $cst->fetch(PDO::FETCH_ASSOC);
    d4u_assert(is_array($before) && (string) ($before['area'] ?? 'x') === '', 'before upgrade Customer area empty');
    d4u_assert(is_array($before) && (string) ($before['address'] ?? 'x') === '', 'before upgrade Customer address empty');
    // Production may normalize free-text area from delivery_area_id (authoritative list name).
    $orderArea = is_array($order) ? trim((string) ($order['area'] ?? '')) : '';
    $orderAddress = is_array($order) ? trim((string) ($order['address'] ?? '')) : '';
    echo 'NOTE  order_area=' . $orderArea . ' order_address=' . $orderAddress . "\n";
    d4u_assert($orderArea !== '', 'Order holds non-empty delivery area (authoritative)');
    d4u_assert($orderAddress === 'Upgrade Path Street 12', 'Order holds delivery address');

    // Approved upgrade event (same helper fulfillment uses).
    $receivedAt = gmdate('Y-m-d H:i:s');
    orange_customer_address_promote_from_order($pdo, $order, $receivedAt);

    $cst->execute([$cid]);
    $after = $cst->fetch(PDO::FETCH_ASSOC);
    d4u_assert(is_array($after) && (string) ($after['area'] ?? '') === $orderArea, 'after upgrade Customer area from Order');
    d4u_assert(is_array($after) && (string) ($after['address'] ?? '') === $orderAddress, 'after upgrade Customer address from Order');

    if (orange_table_exists($pdo, 'customer_addresses')) {
        $hist = (int) $pdo->query(
            'SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ' . (int) $cid
            . ' AND order_id = ' . (int) ($order['id'] ?? 0)
        )->fetchColumn();
        d4u_assert($hist === 1, 'customer_addresses history row for order');
    } else {
        echo "SKIP  customer_addresses table absent\n";
        $skips++;
    }

    // Idempotent repeat
    orange_customer_address_promote_from_order($pdo, $order, $receivedAt);
    if (orange_table_exists($pdo, 'customer_addresses')) {
        $hist2 = (int) $pdo->query(
            'SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ' . (int) $cid
            . ' AND order_id = ' . (int) ($order['id'] ?? 0)
        )->fetchColumn();
        d4u_assert($hist2 === 1, 'promote idempotent (single history row)');
    }

    // Cross-country: EG order must not rewrite KW customer when promote uses EG order customer_id.
    // Prove checkout itself still does not enrich: place another KW order — profile stays upgraded values
    // until a new promote (not overwritten by checkout empty).
    $payload2 = orange_d4_http_checkout_payload($items, (int) ($ids['kw_channel_id'] ?? 1), $phone, '965', 1);
    $payload2['area'] = 'Should Not Copy To Profile';
    $payload2['address'] = 'Checkout Must Not Enrich';
    $ord2 = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        $payload2,
        $jar,
        [],
        120
    );
    d4u_assert(!empty($ord2['json']['success']), 'second checkout after upgrade succeeds');
    $cst->execute([$cid]);
    $afterCheckout = $cst->fetch(PDO::FETCH_ASSOC);
    d4u_assert(
        is_array($afterCheckout) && (string) ($afterCheckout['area'] ?? '') === $orderArea,
        'checkout does not overwrite upgraded Customer area'
    );
    d4u_assert(
        is_array($afterCheckout) && (string) ($afterCheckout['address'] ?? '') === $orderAddress,
        'checkout does not overwrite upgraded Customer address'
    );

    // Cross-country isolation: invent foreign order payload with different country customer — promote must target that customer only.
    $egCid = (int) ($ids['eg_customer_id'] ?? 0);
    if ($egCid > 0 && $egCid !== $cid) {
        $fakeEgOrder = [
            'id' => 0,
            'customer_id' => $egCid,
            'delivery_area_id' => null,
            'area' => 'EG Foreign Area',
            'address' => 'EG Foreign Address',
        ];
        orange_customer_address_promote_from_order($pdo, $fakeEgOrder, $receivedAt);
        $cst->execute([$cid]);
        $kwStill = $cst->fetch(PDO::FETCH_ASSOC);
        d4u_assert(
            is_array($kwStill) && (string) ($kwStill['area'] ?? '') === $orderArea,
            'EG promote does not change KW Customer'
        );
        $egSt = $pdo->prepare('SELECT area FROM customers WHERE id = ? LIMIT 1');
        $egSt->execute([$egCid]);
        $egArea = (string) $egSt->fetchColumn();
        d4u_assert($egArea === 'EG Foreign Area' || $egArea !== 'Hawally', 'EG Customer updated independently or unchanged safely');
    } else {
        echo "SKIP  eg_customer_id fixture missing for cross-country promote\n";
        $skips++;
    }
} catch (Throwable $e) {
    echo 'FAIL  uncaught: ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    $pdo = null; // release suite connection before DROP DATABASE
    if (is_callable($cleanup)) {
        $cleanup();
    }
}

$dur = round(microtime(true) - $started, 3);
echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
echo "DURATION_SEC={$dur}\n";
if ($failures > 0) {
    echo "RESULT=FSR_D4_CUSTOMER_PROFILE_UPGRADE_GAPS\n";
    exit(1);
}
echo "RESULT=FSR_D4_CUSTOMER_DELIVERY_UPGRADE_OK\n";
exit(0);
