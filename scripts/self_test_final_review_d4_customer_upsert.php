<?php

declare(strict_types=1);

/**
 * FSR D4 — Customer required-fields upsert contract (test-only).
 *
 * Proves new Customer INSERT uses explicit empty area/address (Schema NOT NULL,
 * no DEFAULT), Order keeps real delivery data, existing profile is preserved,
 * and Fixture does not invent DEFAULT masking.
 *
 * Usage: php scripts/self_test_final_review_d4_customer_upsert.php
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

function d4c_assert(bool $ok, string $label): void
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

echo 'NOTE  suite=customer_upsert start=' . gmdate('c') . "\n";

$src = (string) file_get_contents($root . '/includes/order_intake_queue.php');
d4c_assert(
    str_contains($src, "FSR-D4-CUSTOMER-REQUIRED-FIELDS-01")
    && preg_match("/\\\$cols\\[\\]\\s*=\\s*'area'/", $src) === 1
    && preg_match("/\\\$cols\\[\\]\\s*=\\s*'address'/", $src) === 1,
    'Production INSERT adds explicit area/address columns'
);
d4c_assert(
    preg_match("/\\\$hasArea.*?\\\$params\\[\\]\\s*=\\s*'';/s", $src) === 1
    || (str_contains($src, "\$params[] = '';") && str_contains($src, "\$cols[] = 'area'")),
    'Production binds explicit empty strings for new Customer'
);
// Existing-customer UPDATE must not assign area/address from order delivery.
$updateBlock = '';
if (preg_match('/if \(\$existing !== false.*?return \$id;\s*\}/s', $src, $m)) {
    $updateBlock = $m[0];
}
d4c_assert(
    $updateBlock !== ''
    && !str_contains($updateBlock, "area = ?")
    && !str_contains($updateBlock, "address = ?"),
    'existing Customer UPDATE omits area/address'
);

$boot = orange_d4_http_bootstrap($root);
if (empty($boot['ok'])) {
    echo 'ENVIRONMENT_BLOCKED: ' . (string) ($boot['error'] ?? '') . "\n";
    $skips++;
    echo "SKIP  live_customer_upsert_http\n";
    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    echo 'DURATION_SEC=' . round(microtime(true) - $started, 3) . "\n";
    exit($failures > 0 ? 1 : 2);
}

$pdo = $boot['pdo'];
$ids = $boot['ids'] ?? [];
$base = (string) $boot['base_url'];
$jar = (string) $boot['cookie_jar'];
$sessionDir = (string) ($boot['session_dir'] ?? sys_get_temp_dir());
$cleanup = $boot['cleanup'];

try {
    $areaMeta = $pdo->query(
        "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'area' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $addrMeta = $pdo->query(
        "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'address' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    echo 'NOTE  customers.area type=' . (string) ($areaMeta['COLUMN_TYPE'] ?? '')
        . ' nullable=' . (string) ($areaMeta['IS_NULLABLE'] ?? '')
        . ' default=' . var_export($areaMeta['COLUMN_DEFAULT'] ?? null, true) . "\n";
    echo 'NOTE  customers.address type=' . (string) ($addrMeta['COLUMN_TYPE'] ?? '')
        . ' nullable=' . (string) ($addrMeta['IS_NULLABLE'] ?? '')
        . ' default=' . var_export($addrMeta['COLUMN_DEFAULT'] ?? null, true) . "\n";
    d4c_assert(
        strtoupper((string) ($areaMeta['IS_NULLABLE'] ?? '')) === 'NO'
        && ($areaMeta['COLUMN_DEFAULT'] === null)
        && strtoupper((string) ($addrMeta['IS_NULLABLE'] ?? '')) === 'NO'
        && ($addrMeta['COLUMN_DEFAULT'] === null),
        'disposable Schema: area/address NOT NULL with no DEFAULT (no fixture masking)'
    );

    orange_d4_http_prime_channel($base, $jar, 'kw-channel');
    $items = orange_d4_http_cart_items([
        ['product_id' => 510, 'variant_id' => 610, 'qty' => 1],
        ['product_id' => 511, 'variant_id' => 611, 'qty' => 1],
    ]);

    // --- New KW guest ---
    $kwPhone = '50008801';
    $payload = orange_d4_http_checkout_payload($items, (int) ($ids['kw_channel_id'] ?? 1), $kwPhone, '965', 1);
    $payload['area'] = 'Salmiya Delivery Area';
    $payload['address'] = 'Block 1 Street Order Addr KW';
    $ord = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        $payload,
        $jar,
        [],
        120
    );
    echo 'NOTE  kw_new status=' . (int) ($ord['status'] ?? 0)
        . ' snip=' . substr((string) ($ord['body'] ?? ''), 0, 240) . "\n";
    $log = $sessionDir . DIRECTORY_SEPARATOR . 'php_error.log';
    $logTxt = is_file($log) ? (string) file_get_contents($log) : '';
    d4c_assert(!str_contains($logTxt, 'HY093'), 'no HY093');
    d4c_assert(!str_contains($logTxt, "Field 'area' doesn't have a default value"), 'no Error 1364 area');
    d4c_assert(!empty($ord['json']['success']), 'new KW guest order success');
    $on = (string) ($ord['json']['order_number'] ?? '');
    d4c_assert($on !== '', 'KW order_number present');

    $ost = $pdo->prepare('SELECT customer_id, phone, area, address, country_id, status, total FROM orders WHERE order_number = ? LIMIT 1');
    $ost->execute([$on]);
    $orderRow = $ost->fetch(PDO::FETCH_ASSOC);
    d4c_assert(is_array($orderRow), 'KW order row exists');
    $cid = is_array($orderRow) ? (int) ($orderRow['customer_id'] ?? 0) : 0;
    d4c_assert($cid > 0, 'KW customer_id linked');
    // Production overwrites free-text area from delivery_area_id label (authoritative list).
    $orderArea = is_array($orderRow) ? trim((string) ($orderRow['area'] ?? '')) : '';
    $orderAddress = is_array($orderRow) ? trim((string) ($orderRow['address'] ?? '')) : '';
    echo 'NOTE  kw_order_area=' . $orderArea . ' (payload free-text may be replaced by delivery_area label)' . "\n";
    d4c_assert($orderArea !== '', 'Order area = delivery (authoritative non-empty)');
    d4c_assert($orderAddress === 'Block 1 Street Order Addr KW', 'Order address = delivery');

    $cst = $pdo->prepare('SELECT id, phone, area, address, country_id FROM customers WHERE id = ? LIMIT 1');
    $cst->execute([$cid]);
    $cust = $cst->fetch(PDO::FETCH_ASSOC);
    d4c_assert(is_array($cust) && (string) ($cust['area'] ?? 'x') === '', 'new Customer area is explicit empty');
    d4c_assert(is_array($cust) && (string) ($cust['address'] ?? 'x') === '', 'new Customer address is explicit empty');
    d4c_assert(is_array($cust) && (string) ($cust['phone'] ?? '') === '+96550008801', 'Customer phone canonical');
    d4c_assert(
        is_array($orderRow) && (string) ($orderRow['area'] ?? '') !== (string) ($cust['area'] ?? 'mismatch'),
        'Order vs Customer area not swapped (order nonempty, profile empty)'
    );

    // --- Existing Customer preserves nonempty profile ---
    $pdo->prepare('UPDATE customers SET area = ?, address = ? WHERE id = ?')
        ->execute(['Existing Profile Area', 'Existing Profile Address', $cid]);
    $payload2 = orange_d4_http_checkout_payload($items, (int) ($ids['kw_channel_id'] ?? 1), $kwPhone, '965', 1);
    $payload2['area'] = 'New Order Area Only';
    $payload2['address'] = 'New Order Address Only';
    $ord2 = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        $payload2,
        $jar,
        [],
        120
    );
    d4c_assert(!empty($ord2['json']['success']), 'existing Customer second order success');
    $on2 = (string) ($ord2['json']['order_number'] ?? '');
    $ost->execute([$on2]);
    $order2 = $ost->fetch(PDO::FETCH_ASSOC);
    d4c_assert(is_array($order2) && (int) ($order2['customer_id'] ?? 0) === $cid, 'same Customer id reused');
    $order2Area = is_array($order2) ? trim((string) ($order2['area'] ?? '')) : '';
    d4c_assert($order2Area !== '', 'second Order area updated (authoritative non-empty)');
    $cst->execute([$cid]);
    $cust2 = $cst->fetch(PDO::FETCH_ASSOC);
    d4c_assert(is_array($cust2) && (string) ($cust2['area'] ?? '') === 'Existing Profile Area', 'existing Customer area preserved');
    d4c_assert(is_array($cust2) && (string) ($cust2['address'] ?? '') === 'Existing Profile Address', 'existing Customer address preserved');

    // --- Egypt new guest ---
    $jarEg = $sessionDir . DIRECTORY_SEPARATOR . 'cookies_eg_cust.txt';
    file_put_contents($jarEg, '');
    // EG warehouse stock for 501/601 (same as gl_slot suite) — D1 seed alone can leave WVS empty.
    $egWh = (int) ($ids['eg_warehouse_id'] ?? 20);
    if (orange_table_exists($pdo, 'warehouse_variant_stock')) {
        try {
            $pdo->prepare(
                'INSERT INTO warehouse_variant_stock (warehouse_id, variant_id, quantity)
                 VALUES (?, 601, 50)
                 ON DUPLICATE KEY UPDATE quantity = GREATEST(quantity, VALUES(quantity))'
            )->execute([$egWh]);
        } catch (Throwable) {
            try {
                $pdo->exec('UPDATE warehouse_variant_stock SET quantity = 50 WHERE variant_id = 601');
            } catch (Throwable) {
            }
        }
    }
    orange_d4_http_prime_channel($base, $jarEg, 'eg-channel');
    // EG products are country-scoped (501), not KW combo SKUs (510/511).
    $egItems = orange_d4_http_cart_items([
        ['product_id' => 501, 'variant_id' => 601, 'qty' => 1],
    ]);
    $egPhone = '100000088';
    $egPayload = orange_d4_http_checkout_payload($egItems, (int) ($ids['eg_channel_id'] ?? 2), $egPhone, '20', 1);
    $egPayload['area'] = 'Cairo Delivery';
    $egPayload['address'] = 'EG Order Street 9';
    $egOrd = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        $egPayload,
        $jarEg,
        [],
        120
    );
    echo 'NOTE  eg_new status=' . (int) ($egOrd['status'] ?? 0)
        . ' snip=' . substr((string) ($egOrd['body'] ?? ''), 0, 200) . "\n";
    d4c_assert(!empty($egOrd['json']['success']) && !empty($egOrd['json']['order_number']), 'new EG guest order success');
    if (!empty($egOrd['json']['success']) && !empty($egOrd['json']['order_number'])) {
        $egOn = (string) $egOrd['json']['order_number'];
        $ost->execute([$egOn]);
        $egOrder = $ost->fetch(PDO::FETCH_ASSOC);
        $egCid = is_array($egOrder) ? (int) ($egOrder['customer_id'] ?? 0) : 0;
        d4c_assert($egCid > 0 && $egCid !== $cid, 'EG Customer distinct from KW');
        $cst->execute([$egCid]);
        $egCust = $cst->fetch(PDO::FETCH_ASSOC);
        d4c_assert(is_array($egCust) && (string) ($egCust['area'] ?? 'x') === '', 'EG new Customer area empty');
        d4c_assert(is_array($egCust) && (string) ($egCust['address'] ?? 'x') === '', 'EG new Customer address empty');
        $egOrderArea = is_array($egOrder) ? trim((string) ($egOrder['area'] ?? '')) : '';
        d4c_assert($egOrderArea !== '', 'EG Order area delivery (authoritative non-empty)');
        d4c_assert(
            is_array($egCust) && (int) ($egCust['country_id'] ?? 0) === (int) ($ids['eg_country_id'] ?? 0),
            'EG Customer country = channel country'
        );
    }

    d4c_assert(!str_contains(strtolower((string) ($ord['body'] ?? '')), 'sqlstate'), 'no SQL leak in KW HTTP');
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
    echo "RESULT=FSR_D4_CUSTOMER_UPSERT_GAPS\n";
    exit(1);
}
echo "RESULT=FSR_D4_CUSTOMER_UPSERT_OK\n";
exit(0);
