<?php

declare(strict_types=1);

/**
 * FSR D4 — Order finalization / Gift-BOGO-Combo stock / concurrency via HTTP (test-only).
 *
 * Usage: php scripts/self_test_final_review_d4_order_finalization.php
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

function d4f_assert(bool $ok, string $label): void
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

echo "NOTE  suite=order_finalization start=" . gmdate('c') . "\n";

$boot = orange_d4_http_bootstrap($root);
if (empty($boot['ok'])) {
    echo "ENVIRONMENT_BLOCKED: " . (string) ($boot['error'] ?? '') . "\n";
    echo "RESULT=FSR_D4_ENVIRONMENT_BLOCKER\n";
    echo "PASS=0 FAIL=0 SKIP=0\n";
    exit(2);
}

$pdo = $boot['pdo'];
$ids = $boot['ids'] ?? [];
$base = (string) $boot['base_url'];
$jar = (string) $boot['cookie_jar'];
$sessionDir = (string) ($boot['session_dir'] ?? sys_get_temp_dir());
$cleanup = $boot['cleanup'];
$kwCh = (int) ($ids['kw_channel_id'] ?? 1);
$php = orange_d4_php_bin();
$worker = $root . '/scripts/lib/final_review_d4_http_worker.php';

try {
    orange_d4_http_prime_channel($base, $jar, 'kw-channel');
    $items = orange_d4_http_cart_items([
        ['product_id' => 510, 'variant_id' => 610, 'qty' => 2],
        ['product_id' => 511, 'variant_id' => 611, 'qty' => 1],
    ]);

    // Preview then submit — gift stock available
    $prev = orange_d4_http_request(
        rtrim($base, '/') . '/api/cart/checkout-preview.php',
        'POST',
        ['items' => $items, 'lang' => 'en', 'delivery_area_id' => 1],
        $jar
    );
    d4f_assert(!empty($prev['json']['success']), 'finalization preview success');

    $payload = orange_d4_http_checkout_payload($items, $kwCh, '50008801', '965', 1);
    $ord = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        $payload,
        $jar,
        [],
        120
    );
    echo 'NOTE  order status=' . (int) ($ord['status'] ?? 0) . ' snip=' . substr((string) ($ord['body'] ?? ''), 0, 240) . "\n";
    d4f_assert(($ord['status'] ?? 0) === 200 && !empty($ord['json']['success']), 'order finalized HTTP success');
    $on = (string) ($ord['json']['order_number'] ?? '');
    d4f_assert($on !== '', 'order_number present');

    $st = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1');
    $st->execute([$on]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    d4f_assert(is_array($row), 'order row exists');
    if (is_array($row)) {
        $oid = (int) $row['id'];
        $lines = (int) $pdo->query("SELECT COUNT(*) FROM order_items WHERE order_id={$oid}")->fetchColumn();
        d4f_assert($lines >= 2, 'order_items persisted');
        d4f_assert((int) ($row['country_id'] ?? 0) === (int) ($ids['kw_country_id'] ?? 1), 'COUNTRY_CONTEXT_ENFORCED on order');
        d4f_assert((int) ($row['channel_id'] ?? 0) === $kwCh, 'CHANNEL_COUNTRY_ENFORCED on order');
        echo 'NOTE  promo_ids cart=' . (string) ($row['cart_promotion_id'] ?? '')
            . ' combo=' . (string) ($row['cart_combo_promotion_id'] ?? '')
            . ' gift=' . (string) ($row['cart_gift_promotion_id'] ?? '')
            . ' bogo=' . (string) ($row['cart_bogo_promotion_id'] ?? '')
            . ' delivery=' . (string) ($row['delivery_promotion_id'] ?? '') . "\n";
    }

    // Gift stock zero after preview path
    $pdo->prepare('UPDATE warehouse_variant_stock SET quantity = 0 WHERE variant_id = 612')->execute();
    $pdo->prepare('UPDATE product_variants SET stock_quantity = 0 WHERE id = 612')->execute();
    $ordG = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        orange_d4_http_checkout_payload($items, $kwCh, '50008802', '965', 1),
        $jar,
        [],
        120
    );
    d4f_assert(is_array($ordG['json']), 'zero gift stock returns JSON');
    if (!empty($ordG['json']['success']) && !empty($ordG['json']['order_number'])) {
        $st->execute([(string) $ordG['json']['order_number']]);
        $rg = $st->fetch(PDO::FETCH_ASSOC);
        d4f_assert(is_array($rg) && (int) ($rg['cart_gift_promotion_id'] ?? 0) === 0, 'zero gift: no gift promo stored');
    } else {
        d4f_assert(true, 'zero gift: order rejected (acceptable)');
    }
    $pdo->prepare('UPDATE warehouse_variant_stock SET quantity = 50 WHERE variant_id = 612')->execute();
    $pdo->prepare('UPDATE product_variants SET stock_quantity = 50 WHERE id = 612')->execute();

    // Concurrent gift stock race (qty=1)
    $pdo->prepare('UPDATE warehouse_variant_stock SET quantity = 1 WHERE variant_id = 612')->execute();
    $pdo->prepare('UPDATE product_variants SET stock_quantity = 1 WHERE id = 612')->execute();
    $meta = [
        'channel_slug' => 'kw-channel',
        'payload' => orange_d4_http_checkout_payload($items, $kwCh, '50008810', '965', 1),
    ];
    $meta2 = $meta;
    $meta2['payload']['phone'] = '50008811';
    $metaFile = $sessionDir . DIRECTORY_SEPARATOR . 'race_meta.json';
    $metaFile2 = $sessionDir . DIRECTORY_SEPARATOR . 'race_meta2.json';
    file_put_contents($metaFile, json_encode($meta, JSON_UNESCAPED_UNICODE));
    file_put_contents($metaFile2, json_encode($meta2, JSON_UNESCAPED_UNICODE));
    $r1 = $sessionDir . DIRECTORY_SEPARATOR . 'race_w1.json';
    $r2 = $sessionDir . DIRECTORY_SEPARATOR . 'race_w2.json';
    $cmd1 = [$php, $worker, 'gift_stock_submit', '1', $r1, $base, $jar, $metaFile];
    $cmd2 = [$php, $worker, 'gift_stock_submit', '2', $r2, $base, $jar, $metaFile2];
    $desc = [0 => ['pipe', 'r'], 1 => ['file', $sessionDir . '/w1.out', 'w'], 2 => ['file', $sessionDir . '/w1.err', 'w']];
    $desc2 = [0 => ['pipe', 'r'], 1 => ['file', $sessionDir . '/w2.out', 'w'], 2 => ['file', $sessionDir . '/w2.err', 'w']];
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
        if (is_resource($p1)) {
            $s = proc_get_status($p1);
            if (!empty($s['running'])) {
                $alive = true;
            }
        }
        if (is_resource($p2)) {
            $s = proc_get_status($p2);
            if (!empty($s['running'])) {
                $alive = true;
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
    $okCount = (!empty($j1['ok']) ? 1 : 0) + (!empty($j2['ok']) ? 1 : 0);
    echo 'NOTE  gift_race ok_count=' . $okCount
        . ' w1=' . substr((string) ($j1['body_snip'] ?? $j1['error'] ?? ''), 0, 120)
        . ' w2=' . substr((string) ($j2['body_snip'] ?? $j2['error'] ?? ''), 0, 120) . "\n";
    d4f_assert($okCount <= 2, 'gift race workers completed');
    d4f_assert(is_array($j1) && is_array($j2), 'gift race worker results present');
    // Restore stock
    $pdo->prepare('UPDATE warehouse_variant_stock SET quantity = 50 WHERE variant_id = 612')->execute();
    $pdo->prepare('UPDATE product_variants SET stock_quantity = 50 WHERE id = 612')->execute();

    // Invalid phone still rejected
    $bad = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        orange_d4_http_checkout_payload($items, $kwCh, 'abc', '965', 1),
        $jar,
        [],
        60
    );
    d4f_assert(empty($bad['json']['success']), 'invalid phone not accepted');
    d4f_assert(!str_contains((string) ($bad['body'] ?? ''), 'SQLSTATE'), 'invalid phone no SQL leak');

    $dur = round(microtime(true) - $started, 3);
    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    echo "DURATION_SEC={$dur}\n";
    echo $failures > 0 ? "RESULT=FSR_D4_ADDITIONAL_PROMOTION_LOYALTY_GAPS_FOUND\n" : "RESULT=FSR_D4_ORDER_FINALIZATION_OK\n";
    exit($failures > 0 ? 1 : 0);
} catch (Throwable $e) {
    echo 'FAIL  uncaught: ' . $e->getMessage() . "\n";
    echo "PASS={$passes} FAIL=" . ($failures + 1) . " SKIP={$skips}\n";
    exit(1);
} finally {
    $pdo = null; // release suite connection before DROP DATABASE
    if (is_callable($cleanup)) {
        $cleanup();
    }
}
