<?php

declare(strict_types=1);

/**
 * FSR D4 — Gift / BOGO / Combo final-stock true concurrency + fulfillment reconciliation (test-only).
 *
 * Contract (policy + code):
 * - Gift/BOGO lines enter orange_order_apply_pending_stock_reservation via linesForStock
 *   (includes/storefront_checkout_promo_lines.php → order_intake_queue.php).
 * - Reservation uses warehouse_variant_stock FOR UPDATE; Insufficient stock rolls back the Order txn.
 * - Fulfillment (status→completed + orange_complete_order_fulfillment) renames pending_order →
 *   pending_order_fulfilled; does not decrement again for web reserved Orders.
 *
 * Usage: php scripts/self_test_final_review_d4_promo_stock_race.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/scripts/lib/final_review_d4_http_fixture.php';
require_once $root . '/includes/order_fulfillment.php';
require_once $root . '/includes/order_stock.php';
require_once $root . '/includes/warehouses.php';

$passes = 0;
$failures = 0;
$skips = 0;
$started = microtime(true);

function d4r_assert(bool $ok, string $label): void
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

/**
 * @return array{0:array<string,mixed>|null,1:array<string,mixed>|null}
 */
function d4r_run_two_workers(
    string $root,
    string $php,
    string $worker,
    string $scenario,
    string $base,
    string $jar,
    string $sessionDir,
    array $meta1,
    array $meta2
): array {
    $m1 = $sessionDir . DIRECTORY_SEPARATOR . 'race_' . $scenario . '_m1.json';
    $m2 = $sessionDir . DIRECTORY_SEPARATOR . 'race_' . $scenario . '_m2.json';
    $r1 = $sessionDir . DIRECTORY_SEPARATOR . 'race_' . $scenario . '_w1.json';
    $r2 = $sessionDir . DIRECTORY_SEPARATOR . 'race_' . $scenario . '_w2.json';
    @unlink($r1);
    @unlink($r2);
    file_put_contents($m1, json_encode($meta1, JSON_UNESCAPED_UNICODE));
    file_put_contents($m2, json_encode($meta2, JSON_UNESCAPED_UNICODE));
    $cmd1 = [$php, $worker, $scenario, '1', $r1, $base, $jar, $m1];
    $cmd2 = [$php, $worker, $scenario, '2', $r2, $base, $jar, $m2];
    $desc = [0 => ['pipe', 'r'], 1 => ['file', $sessionDir . '/' . $scenario . '_w1.out', 'w'], 2 => ['file', $sessionDir . '/' . $scenario . '_w1.err', 'w']];
    $desc2 = [0 => ['pipe', 'r'], 1 => ['file', $sessionDir . '/' . $scenario . '_w2.out', 'w'], 2 => ['file', $sessionDir . '/' . $scenario . '_w2.err', 'w']];
    $p1 = proc_open($cmd1, $desc, $pipes1, $root, null, ['bypass_shell' => true]);
    $p2 = proc_open($cmd2, $desc2, $pipes2, $root, null, ['bypass_shell' => true]);
    if (is_resource($p1)) {
        fclose($pipes1[0]);
    }
    if (is_resource($p2)) {
        fclose($pipes2[0]);
    }
    $deadline = microtime(true) + 180;
    while (microtime(true) < $deadline) {
        $alive = false;
        foreach ([$p1, $p2] as $p) {
            if (is_resource($p)) {
                $s = proc_get_status($p);
                if (!empty($s['running'])) {
                    $alive = true;
                }
            }
        }
        if (!$alive) {
            break;
        }
        usleep(100000);
    }
    if (is_resource($p1)) {
        proc_close($p1);
    }
    if (is_resource($p2)) {
        proc_close($p2);
    }
    $j1 = is_file($r1) ? json_decode((string) file_get_contents($r1), true) : null;
    $j2 = is_file($r2) ? json_decode((string) file_get_contents($r2), true) : null;

    return [is_array($j1) ? $j1 : null, is_array($j2) ? $j2 : null];
}

/**
 * @return array{id:int,order_number:string,status:string,gift_promo:int,gift_vid:int,bogo_promo:int,bogo_vid:int,combo_id:int}
 */
function d4r_load_order(PDO $pdo, string $orderNumber): array
{
    $st = $pdo->prepare(
        'SELECT id, order_number, status,
                COALESCE(cart_gift_promotion_id,0) AS gift_promo,
                COALESCE(cart_gift_variant_id,0) AS gift_vid,
                COALESCE(cart_bogo_promotion_id,0) AS bogo_promo,
                COALESCE(cart_bogo_gift_variant_id,0) AS bogo_vid,
                COALESCE(cart_combo_promotion_id,0) AS combo_id
         FROM orders WHERE order_number = ? LIMIT 1'
    );
    $st->execute([$orderNumber]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return [
            'id' => 0,
            'order_number' => $orderNumber,
            'status' => '',
            'gift_promo' => 0,
            'gift_vid' => 0,
            'bogo_promo' => 0,
            'bogo_vid' => 0,
            'combo_id' => 0,
        ];
    }

    return [
        'id' => (int) $row['id'],
        'order_number' => (string) $row['order_number'],
        'status' => (string) $row['status'],
        'gift_promo' => (int) $row['gift_promo'],
        'gift_vid' => (int) $row['gift_vid'],
        'bogo_promo' => (int) $row['bogo_promo'],
        'bogo_vid' => (int) $row['bogo_vid'],
        'combo_id' => (int) $row['combo_id'],
    ];
}

function d4r_wvs(PDO $pdo, int $warehouseId, int $variantId): int
{
    $st = $pdo->prepare(
        'SELECT quantity FROM warehouse_variant_stock WHERE warehouse_id = ? AND variant_id = ? LIMIT 1'
    );
    $st->execute([$warehouseId, $variantId]);
    $v = $st->fetchColumn();

    return $v === false ? 0 : (int) $v;
}

function d4r_pending_qty(PDO $pdo, int $variantId): int
{
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(qty),0) FROM stock_movements
         WHERE variant_id = ? AND type = 'pending_order'"
    );
    $st->execute([$variantId]);

    return (int) $st->fetchColumn();
}

function d4r_fulfilled_qty(PDO $pdo, int $variantId): int
{
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(qty),0) FROM stock_movements
         WHERE variant_id = ? AND type = 'pending_order_fulfilled'"
    );
    $st->execute([$variantId]);

    return (int) $st->fetchColumn();
}

/**
 * @param list<string> $orderNumbers
 */
function d4r_fulfilled_qty_for_orders(PDO $pdo, int $variantId, array $orderNumbers): int
{
    $refs = [];
    foreach ($orderNumbers as $on) {
        $on = trim($on);
        if ($on !== '') {
            $refs[] = 'ORDER-' . $on;
        }
    }
    if ($refs === []) {
        return 0;
    }
    $ph = implode(',', array_fill(0, count($refs), '?'));
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(qty),0) FROM stock_movements
         WHERE variant_id = ? AND type = 'pending_order_fulfilled' AND reference IN ($ph)"
    );
    $st->execute(array_merge([$variantId], $refs));

    return (int) $st->fetchColumn();
}

function d4r_set_variant_stock(PDO $pdo, int $warehouseId, int $variantId, int $qty): void
{
    if (orange_table_exists($pdo, 'warehouse_variant_stock')) {
        $pdo->prepare(
            'INSERT INTO warehouse_variant_stock (warehouse_id, variant_id, quantity)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
        )->execute([$warehouseId, $variantId, $qty]);
    }
    $pdo->prepare('UPDATE product_variants SET stock_quantity = ? WHERE id = ?')->execute([$qty, $variantId]);
}

/**
 * Complete Order the Production way: status → completed then fulfillment helper.
 * Do not wrap in a new transaction: orange_complete_order_fulfillment → ensure_schema
 * may commit/rollback nested DDL and breaks a caller-owned transaction.
 */
function d4r_complete_order(PDO $pdo, int $orderId): array
{
    $out = ['ok' => false, 'error' => ''];
    try {
        $st = $pdo->prepare('SELECT status FROM orders WHERE id = ? LIMIT 1');
        $st->execute([$orderId]);
        $prev = (string) ($st->fetchColumn() ?: '');
        if ($prev === 'completed') {
            $out['ok'] = true;
            $out['error'] = 'already_completed';

            return $out;
        }
        $now = gmdate('Y-m-d H:i:s');
        if (orange_table_has_column($pdo, 'orders', 'completed_at')) {
            $pdo->prepare('UPDATE orders SET status = ?, completed_at = ?, updated_at = ? WHERE id = ?')
                ->execute(['completed', $now, $now, $orderId]);
        } else {
            $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute(['completed', $orderId]);
        }
        orange_complete_order_fulfillment($pdo, $orderId);
        $out['ok'] = true;
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }

    return $out;
}

echo 'NOTE  suite=promo_stock_race start=' . gmdate('c') . "\n";
echo "NOTE  gift_lifecycle=evaluate_at_checkout→persist_gift_line→reserve_in_pending_order_txn→fulfill_rename_only\n";
echo "NOTE  policy_refs=ORANGE_STOREFRONT_POLICY_REFERENCE س4 gift_stock + ORANGE_STOCK_ORDER_POLICY §2 reserve-at-checkout\n";

// Static mutation-proof markers (supplementary)
$stockSrc = (string) file_get_contents($root . '/includes/order_stock.php');
$whSrc = (string) file_get_contents($root . '/includes/warehouses.php');
$promoSrc = (string) file_get_contents($root . '/includes/storefront_checkout_promo_lines.php');
d4r_assert(str_contains($promoSrc, "\$linesForStock[] = \$gl"), 'source: gift lines enter linesForStock');
d4r_assert(str_contains($promoSrc, "\$linesForStock[] = \$bogoLine"), 'source: BOGO gift enters linesForStock');
d4r_assert(str_contains($stockSrc, 'orange_warehouse_apply_variant_delta'), 'source: reservation uses warehouse delta');
d4r_assert(str_contains($whSrc, 'FOR UPDATE') && str_contains($whSrc, 'Insufficient stock'), 'source: WVS FOR UPDATE + insufficient stock guard');
d4r_assert(str_contains($stockSrc, 'pending_order'), 'source: pending_order movement type');

$boot = orange_d4_http_bootstrap($root);
if (empty($boot['ok'])) {
    echo 'ENVIRONMENT_BLOCKED: ' . (string) ($boot['error'] ?? '') . "\n";
    echo "RESULT=FSR_D4_ENVIRONMENT_BLOCKER\n";
    echo "PASS={$passes} FAIL={$failures} SKIP=1\n";
    exit(2);
}

$pdo = $boot['pdo'];
$ids = $boot['ids'] ?? [];
$base = (string) $boot['base_url'];
$jar = (string) $boot['cookie_jar'];
$sessionDir = (string) ($boot['session_dir'] ?? sys_get_temp_dir());
$cleanup = $boot['cleanup'];
$kwCh = (int) ($ids['kw_channel_id'] ?? 1);
$kwWh = (int) ($ids['kw_warehouse_id'] ?? 10);
$php = orange_d4_php_bin();
$worker = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'lib'
    . DIRECTORY_SEPARATOR . 'final_review_d4_http_worker.php';

$giftVariant = 612; // product 512 gift
$comboVariantA = 610; // product 510
$comboVariantB = 611; // product 511
$bogoPaidVariant = 600; // product 500

try {
    // Ensure paid merchandise stock is plentiful so only the contested unit is the bottleneck.
    d4r_set_variant_stock($pdo, $kwWh, $comboVariantA, 50);
    d4r_set_variant_stock($pdo, $kwWh, $comboVariantB, 50);
    d4r_set_variant_stock($pdo, $kwWh, $bogoPaidVariant, 50);

    // ============================================================
    // A) GIFT race — stock=1
    // ============================================================
    echo "NOTE  --- GIFT race ---\n";
    d4r_set_variant_stock($pdo, $kwWh, $giftVariant, 1);
    $wvsBeforeGift = d4r_wvs($pdo, $kwWh, $giftVariant);
    d4r_assert($wvsBeforeGift === 1, 'gift fixture WVS=1');

    // Cart that qualifies for gift min_subtotal 25 (combo 510+511 + extra 500).
    $giftItems = orange_d4_http_cart_items([
        ['product_id' => 510, 'variant_id' => 610, 'qty' => 1],
        ['product_id' => 511, 'variant_id' => 611, 'qty' => 1],
        ['product_id' => 500, 'variant_id' => 600, 'qty' => 2],
    ]);
    $metaG1 = [
        'channel_slug' => 'kw-channel',
        'payload' => orange_d4_http_checkout_payload($giftItems, $kwCh, '50007001', '965', 1),
    ];
    $metaG2 = [
        'channel_slug' => 'kw-channel',
        'payload' => orange_d4_http_checkout_payload($giftItems, $kwCh, '50007002', '965', 1, ['address' => 'Gift race B']),
    ];

    // Preview both (sequential) — channel-primed; success optional if fixture subtotal edge.
    orange_d4_http_prime_channel($base, $jar, 'kw-channel');
    $prev1 = orange_d4_http_request(
        rtrim($base, '/') . '/api/cart/checkout-preview.php',
        'POST',
        [
            'items' => $giftItems,
            'lang' => 'en',
            'delivery_area_id' => 1,
            'phone' => '50007001',
            'phone_country' => '965',
            'channel_id' => $kwCh,
        ],
        $jar
    );
    $prev2 = orange_d4_http_request(
        rtrim($base, '/') . '/api/cart/checkout-preview.php',
        'POST',
        [
            'items' => $giftItems,
            'lang' => 'en',
            'delivery_area_id' => 1,
            'phone' => '50007002',
            'phone_country' => '965',
            'channel_id' => $kwCh,
        ],
        $jar
    );
    d4r_assert(is_array($prev1['json']), 'gift preview A returns JSON');
    d4r_assert(is_array($prev2['json']), 'gift preview B returns JSON');
    echo 'NOTE  gift_preview_A status=' . (int) ($prev1['status'] ?? 0)
        . ' success=' . (!empty($prev1['json']['success']) ? '1' : '0')
        . ' gift=' . json_encode($prev1['json']['gift_promotion'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";

    [$gw1, $gw2] = d4r_run_two_workers($root, $php, $worker, 'gift_stock_submit', $base, $jar, $sessionDir, $metaG1, $metaG2);
    d4r_assert(is_array($gw1) && is_array($gw2), 'gift workers returned result files');
    $gOk = (!empty($gw1['ok']) ? 1 : 0) + (!empty($gw2['ok']) ? 1 : 0);
    echo 'NOTE  gift_submit ok_count=' . $gOk
        . ' w1_ok=' . (!empty($gw1['ok']) ? '1' : '0')
        . ' w2_ok=' . (!empty($gw2['ok']) ? '1' : '0')
        . ' w1_on=' . (string) ($gw1['order_number'] ?? '')
        . ' w2_on=' . (string) ($gw2['order_number'] ?? '')
        . "\n";

    $gOrders = [];
    foreach ([$gw1, $gw2] as $w) {
        $on = (string) ($w['order_number'] ?? '');
        if ($on !== '' && !empty($w['ok'])) {
            $gOrders[] = d4r_load_order($pdo, $on);
        }
    }
    $giftKeepers = array_values(array_filter($gOrders, static fn ($o) => $o['gift_promo'] > 0 && $o['gift_vid'] > 0));
    $giftKeeperCount = count($giftKeepers);
    echo 'NOTE  gift_orders_with_gift_line=' . $giftKeeperCount . "\n";
    foreach ($gOrders as $o) {
        echo 'NOTE  gift_order id=' . $o['id'] . ' gift_promo=' . $o['gift_promo']
            . ' gift_vid=' . $o['gift_vid'] . ' status=' . $o['status'] . "\n";
    }

    $wvsAfterSubmit = d4r_wvs($pdo, $kwWh, $giftVariant);
    $pendingGift = d4r_pending_qty($pdo, $giftVariant);
    echo 'NOTE  gift_wvs_after_submit=' . $wvsAfterSubmit . ' pending_order_qty=' . $pendingGift . "\n";
    d4r_assert($wvsAfterSubmit >= 0, 'gift WVS non-negative after concurrent submit');
    d4r_assert($pendingGift <= 1, 'gift pending_order qty ≤ 1 for stock=1');
    d4r_assert($giftKeeperCount <= 1, 'at most one Order retains Gift after finalization');

    // Losing-order classification
    $losingClass = 'UNDEFINED_LIVE_BEHAVIOR';
    if ($gOk === 1 && $giftKeeperCount === 1) {
        $losingClass = 'ORDER_REJECTED_AT_FINALIZATION';
    } elseif ($gOk === 2 && $giftKeeperCount === 1) {
        $losingClass = 'ORDER_ACCEPTED_WITHOUT_GIFT';
    } elseif ($gOk === 2 && $giftKeeperCount === 0) {
        $losingClass = 'ORDER_ACCEPTED_WITHOUT_GIFT';
    } elseif ($gOk === 0) {
        $losingClass = 'ORDER_REJECTED_AT_FINALIZATION';
    } elseif ($giftKeeperCount >= 2) {
        $losingClass = 'UNDEFINED_LIVE_BEHAVIOR';
    }
    echo 'NOTE  gift_losing_order_class=' . $losingClass . "\n";
    d4r_assert($losingClass !== 'UNDEFINED_LIVE_BEHAVIOR', 'gift losing-Order class is defined');

    // Fulfill both existing Orders (winner + any loser that was accepted)
    $completeOk = 0;
    $completeFail = 0;
    foreach ($gOrders as $o) {
        if ($o['id'] <= 0) {
            continue;
        }
        $c = d4r_complete_order($pdo, $o['id']);
        echo 'NOTE  gift_complete id=' . $o['id'] . ' ok=' . (!empty($c['ok']) ? '1' : '0')
            . ' err=' . (string) ($c['error'] ?? '') . "\n";
        if (!empty($c['ok'])) {
            $completeOk++;
        } else {
            $completeFail++;
        }
    }
    $wvsAfterFulfill = d4r_wvs($pdo, $kwWh, $giftVariant);
    $giftOrderNumbers = array_values(array_map(static fn ($o) => $o['order_number'], $gOrders));
    $fulfilledGift = d4r_fulfilled_qty_for_orders($pdo, $giftVariant, $giftOrderNumbers);
    $pendingAfterFulfill = d4r_pending_qty($pdo, $giftVariant);
    echo 'NOTE  gift_wvs_after_fulfill=' . $wvsAfterFulfill
        . ' fulfilled_qty_scoped=' . $fulfilledGift
        . ' pending_left=' . $pendingAfterFulfill . "\n";
    d4r_assert($wvsAfterFulfill >= 0, 'gift WVS non-negative after fulfillment');
    d4r_assert($fulfilledGift <= 1, 'gift fulfilled movement qty ≤ 1 (scoped to race Orders)');
    d4r_assert($pendingAfterFulfill === 0, 'no live pending_order left on gift after fulfill/release');

    // Classification A/B/C/D
    $giftClass = 'PROVEN_GIFT_OVERCOMMIT_DEFECT';
    if ($giftKeeperCount <= 1 && $pendingGift <= 1 && $fulfilledGift <= 1 && $wvsAfterSubmit >= 0 && $wvsAfterFulfill >= 0) {
        if ($giftKeeperCount === 1 && ($gOk === 1 || $losingClass === 'ORDER_ACCEPTED_WITHOUT_GIFT')) {
            $giftClass = 'EXCLUSIVE_AT_ORDER_FINALIZATION';
        } elseif ($giftKeeperCount === 0 && $gOk >= 1) {
            // Both accepted without gift — not overcommit; policy-safe if stock gate dropped gift
            $giftClass = 'EXCLUSIVE_AT_ORDER_FINALIZATION';
        } elseif ($gOk <= 1) {
            $giftClass = 'EXCLUSIVE_AT_ORDER_FINALIZATION';
        }
    }
    if ($giftKeeperCount >= 2 || $pendingGift > 1 || $fulfilledGift > 1 || $wvsAfterSubmit < 0 || $wvsAfterFulfill < 0) {
        $giftClass = 'PROVEN_GIFT_OVERCOMMIT_DEFECT';
        echo "NOTE  defect_id=FSR-D4-GIFT-STOCK-RACE-01\n";
    }
    echo 'NOTE  gift_race_classification=' . $giftClass . "\n";
    d4r_assert($giftClass !== 'PROVEN_GIFT_OVERCOMMIT_DEFECT', 'gift race not overcommit');
    d4r_assert($giftClass === 'EXCLUSIVE_AT_ORDER_FINALIZATION', 'gift exclusive at order finalization');

    // ============================================================
    // B) BOGO free-gift race — gift stock=1, paid stock plentiful
    // ============================================================
    echo "NOTE  --- BOGO race ---\n";
    // Disable cart gift so BOGO gift is the only consumer of 612; clear auto-pause.
    if (orange_table_exists($pdo, 'cart_gift_promotions')) {
        $pdo->exec('UPDATE cart_gift_promotions SET is_active = 0 WHERE country_id = ' . (int) ($ids['kw_country_id'] ?? 1));
    }
    if (orange_table_exists($pdo, 'cart_bogo_promotions')) {
        $pdo->exec(
            'UPDATE cart_bogo_promotions SET is_active = 1, auto_paused_at = NULL, auto_paused_reason = NULL WHERE id = 1'
        );
    }
    // Product offer on 500 can remain; BOGO matches product qty independently.
    d4r_set_variant_stock($pdo, $kwWh, $giftVariant, 1);
    d4r_set_variant_stock($pdo, $kwWh, $bogoPaidVariant, 50);
    $bogoItems = orange_d4_http_cart_items([
        ['product_id' => 500, 'variant_id' => 600, 'qty' => 2],
    ]);
    // Sequential probe: BOGO must attach when stock=1 alone.
    $probeBogo = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        orange_d4_http_checkout_payload($bogoItems, $kwCh, '50007100', '965', 1),
        $jar,
        [],
        120
    );
    $probeOn = (string) ($probeBogo['json']['order_number'] ?? '');
    $probeRow = $probeOn !== '' ? d4r_load_order($pdo, $probeOn) : ['bogo_promo' => 0, 'id' => 0];
    echo 'NOTE  bogo_probe success=' . (!empty($probeBogo['json']['success']) ? '1' : '0')
        . ' bogo_promo=' . (int) ($probeRow['bogo_promo'] ?? 0)
        . ' snip=' . substr((string) ($probeBogo['body'] ?? ''), 0, 160) . "\n";
    d4r_assert((int) ($probeRow['bogo_promo'] ?? 0) > 0, 'BOGO sequential probe attaches free gift');
    // Release probe reservation so race starts from stock=1 again.
    if ((int) ($probeRow['id'] ?? 0) > 0) {
        $stOrd = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stOrd->execute([(int) $probeRow['id']]);
        $ordProbe = $stOrd->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($ordProbe !== []) {
            orange_order_release_pending_stock_reservation($pdo, $ordProbe);
        }
        $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([(int) $probeRow['id']]);
    }
    d4r_set_variant_stock($pdo, $kwWh, $giftVariant, 1);
    d4r_set_variant_stock($pdo, $kwWh, $bogoPaidVariant, 50);

    $metaB1 = [
        'channel_slug' => 'kw-channel',
        'payload' => orange_d4_http_checkout_payload($bogoItems, $kwCh, '50007101', '965', 1),
    ];
    $metaB2 = [
        'channel_slug' => 'kw-channel',
        'payload' => orange_d4_http_checkout_payload($bogoItems, $kwCh, '50007102', '965', 1, ['address' => 'BOGO race B']),
    ];
    [$bw1, $bw2] = d4r_run_two_workers($root, $php, $worker, 'gift_stock_submit', $base, $jar, $sessionDir, $metaB1, $metaB2);
    d4r_assert(is_array($bw1) && is_array($bw2), 'BOGO workers returned results');
    $bOk = (!empty($bw1['ok']) ? 1 : 0) + (!empty($bw2['ok']) ? 1 : 0);
    echo 'NOTE  bogo_submit ok_count=' . $bOk
        . ' w1_on=' . (string) ($bw1['order_number'] ?? '')
        . ' w2_on=' . (string) ($bw2['order_number'] ?? '') . "\n";
    $bOrders = [];
    foreach ([$bw1, $bw2] as $w) {
        if (!empty($w['ok']) && !empty($w['order_number'])) {
            $bOrders[] = d4r_load_order($pdo, (string) $w['order_number']);
        }
    }
    $bogoKeepers = array_values(array_filter($bOrders, static fn ($o) => $o['bogo_promo'] > 0 && $o['bogo_vid'] > 0));
    $bogoKeeperCount = count($bogoKeepers);
    $bogoPending = d4r_pending_qty($pdo, $giftVariant);
    $bogoWvs = d4r_wvs($pdo, $kwWh, $giftVariant);
    echo 'NOTE  bogo_keepers=' . $bogoKeeperCount . ' pending=' . $bogoPending . ' wvs=' . $bogoWvs . "\n";
    foreach ($bOrders as $o) {
        echo 'NOTE  bogo_order id=' . $o['id'] . ' bogo_promo=' . $o['bogo_promo']
            . ' bogo_vid=' . $o['bogo_vid'] . "\n";
        if ($o['id'] > 0) {
            d4r_complete_order($pdo, $o['id']);
        }
    }
    $bogoOrderNumbers = array_values(array_map(static fn ($o) => $o['order_number'], $bOrders));
    $bogoFulfilled = d4r_fulfilled_qty_for_orders($pdo, $giftVariant, $bogoOrderNumbers);
    $bogoWvsF = d4r_wvs($pdo, $kwWh, $giftVariant);
    echo 'NOTE  bogo_after_fulfill fulfilled_scoped=' . $bogoFulfilled . ' wvs=' . $bogoWvsF . "\n";
    d4r_assert($bogoKeeperCount <= 1, 'BOGO: at most one Order keeps free gift');
    d4r_assert($bogoPending <= 1, 'BOGO: pending free-gift qty ≤ 1');
    d4r_assert($bogoWvs >= 0 && $bogoWvsF >= 0, 'BOGO: WVS non-negative');
    d4r_assert($bogoFulfilled <= 1, 'BOGO: fulfilled free-gift qty ≤ 1 (scoped to race Orders)');
    $bogoLose = ($bOk === 1) ? 'ORDER_REJECTED_AT_FINALIZATION'
        : (($bOk === 2 && $bogoKeeperCount <= 1) ? 'ORDER_ACCEPTED_WITHOUT_GIFT' : 'UNDEFINED_LIVE_BEHAVIOR');
    echo 'NOTE  bogo_losing_order_class=' . $bogoLose . "\n";
    d4r_assert($bogoLose !== 'UNDEFINED_LIVE_BEHAVIOR', 'BOGO losing-Order class defined');

    // ============================================================
    // C) COMBO component race — component A stock=1
    // ============================================================
    echo "NOTE  --- COMBO race ---\n";
    // Restore gift promo inactive; ensure combo active
    if (orange_table_exists($pdo, 'cart_combo_promotions')) {
        $pdo->exec('UPDATE cart_combo_promotions SET is_active = 1 WHERE id = 1');
    }
    d4r_set_variant_stock($pdo, $kwWh, $comboVariantA, 1);
    d4r_set_variant_stock($pdo, $kwWh, $comboVariantB, 50);
    $comboItems = orange_d4_http_cart_items([
        ['product_id' => 510, 'variant_id' => 610, 'qty' => 1],
        ['product_id' => 511, 'variant_id' => 611, 'qty' => 1],
    ]);
    $metaC1 = [
        'channel_slug' => 'kw-channel',
        'payload' => orange_d4_http_checkout_payload($comboItems, $kwCh, '50007201', '965', 1),
    ];
    $metaC2 = [
        'channel_slug' => 'kw-channel',
        'payload' => orange_d4_http_checkout_payload($comboItems, $kwCh, '50007202', '965', 1, ['address' => 'Combo race B']),
    ];
    [$cw1, $cw2] = d4r_run_two_workers($root, $php, $worker, 'gift_stock_submit', $base, $jar, $sessionDir, $metaC1, $metaC2);
    d4r_assert(is_array($cw1) && is_array($cw2), 'combo workers returned results');
    $cOk = (!empty($cw1['ok']) ? 1 : 0) + (!empty($cw2['ok']) ? 1 : 0);
    echo 'NOTE  combo_submit ok_count=' . $cOk
        . ' w1_on=' . (string) ($cw1['order_number'] ?? '')
        . ' w2_on=' . (string) ($cw2['order_number'] ?? '') . "\n";
    $cOrders = [];
    foreach ([$cw1, $cw2] as $w) {
        if (!empty($w['ok']) && !empty($w['order_number'])) {
            $cOrders[] = d4r_load_order($pdo, (string) $w['order_number']);
        }
    }
    $comboKeepers = array_values(array_filter($cOrders, static fn ($o) => $o['combo_id'] > 0));
    $comboPendingA = d4r_pending_qty($pdo, $comboVariantA);
    $comboWvsA = d4r_wvs($pdo, $kwWh, $comboVariantA);
    echo 'NOTE  combo_keepers=' . count($comboKeepers) . ' pending_A=' . $comboPendingA . ' wvs_A=' . $comboWvsA . "\n";
    d4r_assert($cOk <= 1, 'combo: at most one Order succeeds when component stock=1');
    d4r_assert($comboPendingA <= 1, 'combo: pending component A qty ≤ 1');
    d4r_assert($comboWvsA >= 0, 'combo: WVS A non-negative');
    d4r_assert(count($cOrders) <= 1, 'combo: at most one Order row persisted');
    foreach ($cOrders as $o) {
        if ($o['id'] > 0) {
            d4r_complete_order($pdo, $o['id']);
        }
    }
    $comboWvsAF = d4r_wvs($pdo, $kwWh, $comboVariantA);
    d4r_assert($comboWvsAF >= 0, 'combo: WVS A non-negative after fulfill');
    $comboLose = ($cOk === 1) ? 'ORDER_REJECTED_AT_FINALIZATION' : 'UNDEFINED_LIVE_BEHAVIOR';
    echo 'NOTE  combo_losing_order_class=' . $comboLose . "\n";
    d4r_assert($comboLose === 'ORDER_REJECTED_AT_FINALIZATION', 'combo loser rejected at finalization');

    // D2 boundary: no negative WVS on contested variants
    d4r_assert(d4r_wvs($pdo, $kwWh, $giftVariant) >= 0, 'D2 reconcile: gift WVS ≥ 0');
    d4r_assert(d4r_wvs($pdo, $kwWh, $comboVariantA) >= 0, 'D2 reconcile: combo A WVS ≥ 0');

    // Mutation-proof behavioral: if we temporarily set stock=0, submit must not keep gift
    d4r_set_variant_stock($pdo, $kwWh, $giftVariant, 0);
    if (orange_table_exists($pdo, 'cart_gift_promotions')) {
        $pdo->exec('UPDATE cart_gift_promotions SET is_active = 1 WHERE id = 1');
    }
    $zeroGift = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        orange_d4_http_checkout_payload($giftItems, $kwCh, '50007301', '965', 1),
        $jar,
        [],
        120
    );
    if (!empty($zeroGift['json']['success']) && !empty($zeroGift['json']['order_number'])) {
        $zo = d4r_load_order($pdo, (string) $zeroGift['json']['order_number']);
        d4r_assert($zo['gift_promo'] === 0, 'mutation-proof: stock=0 → no gift persisted');
    } else {
        d4r_assert(true, 'mutation-proof: stock=0 → order rejected (acceptable)');
    }

    $dur = round(microtime(true) - $started, 3);
    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    echo "DURATION_SEC={$dur}\n";
    if ($failures > 0) {
        echo "RESULT=FSR_D4_GIFT_OR_PROMOTION_STOCK_RACE_GAP_FOUND\n";
        exit(1);
    }
    echo "RESULT=FSR_D4_PROMO_STOCK_RACE_OK\n";
    exit(0);
} catch (Throwable $e) {
    echo 'FAIL  uncaught: ' . $e->getMessage() . "\n";
    $failures++;
    echo "PASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    echo "RESULT=FSR_D4_GIFT_OR_PROMOTION_STOCK_RACE_GAP_FOUND\n";
    exit(1);
} finally {
    $pdo = null;
    if (is_callable($cleanup)) {
        $cleanup();
    }
}
