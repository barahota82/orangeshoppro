<?php

declare(strict_types=1);

/**
 * FSR D4 — order_items.gl_slot Storefront allocation (test-only).
 *
 * Usage: php scripts/self_test_final_review_d4_order_items_gl_slot.php
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

echo 'NOTE  suite=order_items_gl_slot start=' . gmdate('c') . "\n";

$src = (string) file_get_contents($root . '/includes/order_intake_queue.php');
d4g_assert(str_contains($src, 'FSR-D4-ORDER-ITEMS-GL-SLOT-01'), 'source marks gl_slot repair');
d4g_assert(str_contains($src, 'orange_order_item_allocate_gl_slot'), 'storefront uses approved allocator');
$manual = (string) file_get_contents($root . '/admin/api/orders/create-manual.php');
d4g_assert(str_contains($manual, 'orange_order_item_allocate_gl_slot'), 'manual path still uses allocator');

$boot = orange_d4_http_bootstrap($root);
if (empty($boot['ok'])) {
    echo 'ENVIRONMENT_BLOCKED: ' . (string) ($boot['error'] ?? '') . "\n";
    $skips++;
    echo "SKIP  live_gl_slot_http\n";
    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    exit($failures > 0 ? 1 : 2);
}

$pdo = $boot['pdo'];
$ids = $boot['ids'] ?? [];
$base = (string) $boot['base_url'];
$jar = (string) $boot['cookie_jar'];
$sessionDir = (string) ($boot['session_dir'] ?? sys_get_temp_dir());
$cleanup = $boot['cleanup'];

try {
    $meta = $pdo->query(
        "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'gl_slot' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    echo 'NOTE  gl_slot type=' . (string) ($meta['COLUMN_TYPE'] ?? '')
        . ' nullable=' . (string) ($meta['IS_NULLABLE'] ?? '')
        . ' default=' . var_export($meta['COLUMN_DEFAULT'] ?? null, true)
        . ' key=' . (string) ($meta['COLUMN_KEY'] ?? '') . "\n";
    d4g_assert(
        strtoupper((string) ($meta['IS_NULLABLE'] ?? '')) === 'NO'
        && ($meta['COLUMN_DEFAULT'] === null)
        && str_contains(strtolower((string) ($meta['COLUMN_TYPE'] ?? '')), 'int'),
        'Schema: gl_slot INT NOT NULL no DEFAULT'
    );
    $uq = $pdo->query(
        "SELECT INDEX_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items'
           AND COLUMN_NAME = 'gl_slot' AND NON_UNIQUE = 0 LIMIT 1"
    )->fetchColumn();
    d4g_assert(is_string($uq) && $uq !== '', 'UNIQUE index on gl_slot scope exists');
    echo 'NOTE  gl_slot_unique_index=' . (string) $uq . " (scope=order_id+gl_slot)\n";

    // document_sequences / last_value BIGINT audit
    $seqExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_sequences'"
    )->fetchColumn();
    d4g_assert($seqExists === 1, 'document_sequences exists after dump+seal');
    $log = $sessionDir . DIRECTORY_SEPARATOR . 'php_error.log';
    $logBefore = is_file($log) ? (string) file_get_contents($log) : '';

    orange_d4_http_prime_channel($base, $jar, 'kw-channel');
    $itemsMulti = orange_d4_http_cart_items([
        ['product_id' => 500, 'variant_id' => 600, 'qty' => 2],
        ['product_id' => 510, 'variant_id' => 610, 'qty' => 1],
        ['product_id' => 511, 'variant_id' => 611, 'qty' => 1],
    ]);
    $payload = orange_d4_http_checkout_payload(
        $itemsMulti,
        (int) ($ids['kw_channel_id'] ?? 1),
        '50007777',
        '965',
        1
    );
    $ord = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        $payload,
        $jar,
        [],
        120
    );
    echo 'NOTE  kw_order status=' . (int) ($ord['status'] ?? 0)
        . ' snip=' . substr((string) ($ord['body'] ?? ''), 0, 240) . "\n";
    $logAfter = is_file($log) ? (string) file_get_contents($log) : '';
    d4g_assert(!empty($ord['json']['success']), 'KW Storefront order succeeds');
    d4g_assert(!str_contains($logAfter, "Field 'gl_slot' doesn't have a default value"), 'no gl_slot 1364');
    d4g_assert(!str_contains(strtolower((string) ($ord['body'] ?? '')), 'sqlstate'), 'no SQL leak');

    $on = (string) ($ord['json']['order_number'] ?? '');
    $oid = (int) $pdo->query('SELECT id FROM orders WHERE order_number = ' . $pdo->quote($on))->fetchColumn();
    $slots = $pdo->query(
        'SELECT gl_slot FROM order_items WHERE order_id = ' . $oid . ' ORDER BY gl_slot ASC'
    )->fetchAll(PDO::FETCH_COLUMN);
    d4g_assert(count($slots) >= 3, '≥3 order_items for multi cart');
    $prev = 0;
    $allPos = true;
    foreach ($slots as $s) {
        $n = (int) $s;
        if ($n <= 0 || $n <= $prev) {
            $allPos = false;
        }
        $prev = $n;
    }
    d4g_assert($allPos, 'gl_slots positive distinct monotonic per order');
    echo 'NOTE  kw_gl_slots=' . implode(',', array_map('strval', $slots)) . "\n";

    // Customer empty profile preserved
    $cid = (int) $pdo->query('SELECT customer_id FROM orders WHERE id = ' . $oid)->fetchColumn();
    $cArea = (string) $pdo->query('SELECT area FROM customers WHERE id = ' . $cid)->fetchColumn();
    d4g_assert($cArea === '', 'new Customer area still empty');

    // last_value classification
    $hasLastValueMsg = str_contains($logAfter, 'last_value BIGINT') || str_contains($logBefore, 'last_value BIGINT');
    // Proven on local MySQL 8.4: unquoted column last_value in CREATE TABLE is ERROR 1064;
    // dump uses `last_value` (works). catalog_schema.php CREATE omits backticks → Production path defect
    // when table is missing. safe_exec swallows it. Gap id: FSR-D4-DOCUMENT-SEQUENCES-LAST-VALUE-01.
    if ($hasLastValueMsg) {
        echo "NOTE  last_value_BIGINT_message=PRESENT\n";
    } else {
        echo "NOTE  last_value_BIGINT_message=ABSENT_THIS_RUN\n";
    }
    echo "NOTE  last_value_source=includes/catalog_schema.php + catalog_bootstrap_store.php + document_sequences.php\n";
    echo "NOTE  last_value_object=document_sequences.last_value\n";
    if ($hasLastValueMsg) {
        echo "NOTE  last_value_classification=STILL_PRESENT_AFTER_REPAIR_GAP\n";
        d4g_assert(false, 'last_value 1064 must be absent after quoting repair');
    } else {
        echo "NOTE  last_value_classification=REPAIRED_IDENTIFIER_QUOTING\n";
        d4g_assert(true, 'no last_value 1064 in runtime log after quoting repair');
    }

    // Gift/BOGO lines via multi promo cart (gift variant 612)
    $giftItems = orange_d4_http_cart_items([
        ['product_id' => 510, 'variant_id' => 610, 'qty' => 2],
        ['product_id' => 511, 'variant_id' => 611, 'qty' => 1],
    ]);
    $gOrd = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        orange_d4_http_checkout_payload($giftItems, (int) ($ids['kw_channel_id'] ?? 1), '50007778', '965', 1),
        $jar,
        [],
        120
    );
    d4g_assert(!empty($gOrd['json']['success']), 'promo cart order succeeds');
    if (!empty($gOrd['json']['order_number'])) {
        $gOid = (int) $pdo->query(
            'SELECT id FROM orders WHERE order_number = ' . $pdo->quote((string) $gOrd['json']['order_number'])
        )->fetchColumn();
        $gSlots = $pdo->query(
            'SELECT gl_slot FROM order_items WHERE order_id = ' . $gOid . ' ORDER BY gl_slot ASC'
        )->fetchAll(PDO::FETCH_COLUMN);
        $gOk = true;
        $seen = [];
        foreach ($gSlots as $s) {
            $n = (int) $s;
            if ($n <= 0 || isset($seen[$n])) {
                $gOk = false;
            }
            $seen[$n] = true;
        }
        d4g_assert($gOk && $gSlots !== [], 'gift/promo order_items all have valid unique gl_slot');
        echo 'NOTE  gift_order_gl_slots=' . implode(',', array_map('strval', $gSlots)) . "\n";
        echo 'NOTE  gift_promo_id=' . (string) $pdo->query(
            'SELECT cart_gift_promotion_id FROM orders WHERE id = ' . $gOid
        )->fetchColumn() . "\n";
    }

    // Egypt product 501/601 from D1 core seed
    $egCh = (int) ($ids['eg_channel_id'] ?? 2);
    $egWh = (int) ($ids['eg_warehouse_id'] ?? 20);
    if (orange_table_exists($pdo, 'warehouse_variant_stock')) {
        try {
            $pdo->prepare(
                'INSERT INTO warehouse_variant_stock (warehouse_id, variant_id, quantity)
                 VALUES (?, 601, 50), (?, 602, 40)
                 ON DUPLICATE KEY UPDATE quantity = GREATEST(quantity, VALUES(quantity))'
            )->execute([$egWh, $egWh]);
        } catch (Throwable) {
            try {
                $pdo->exec('UPDATE warehouse_variant_stock SET quantity = 50 WHERE variant_id IN (601,602)');
            } catch (Throwable) {
            }
        }
    }
    $jarEg = $sessionDir . DIRECTORY_SEPARATOR . 'cookies_eg_gl.txt';
    file_put_contents($jarEg, '');
    orange_d4_http_prime_channel($base, $jarEg, 'eg-channel');
    $egItems = orange_d4_http_cart_items([
        ['product_id' => 501, 'variant_id' => 601, 'qty' => 1],
    ]);
    $egOrd = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        orange_d4_http_checkout_payload($egItems, $egCh, '100000099', '20', 1),
        $jarEg,
        [],
        120
    );
    echo 'NOTE  eg_order status=' . (int) ($egOrd['status'] ?? 0)
        . ' snip=' . substr((string) ($egOrd['body'] ?? ''), 0, 200) . "\n";
    if (!empty($egOrd['json']['success'])) {
        $egOn = (string) $egOrd['json']['order_number'];
        $egOid = (int) $pdo->query('SELECT id FROM orders WHERE order_number = ' . $pdo->quote($egOn))->fetchColumn();
        $egSlots = $pdo->query(
            'SELECT gl_slot FROM order_items WHERE order_id = ' . $egOid
        )->fetchAll(PDO::FETCH_COLUMN);
        d4g_assert($egSlots !== [], 'EG order_items have gl_slot');
        foreach ($egSlots as $s) {
            d4g_assert((int) $s > 0, 'EG gl_slot positive');
        }
    } else {
        d4g_assert(false, 'EG Storefront order succeeds');
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

echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
echo 'DURATION_SEC=' . round(microtime(true) - $started, 3) . "\n";
exit($failures > 0 ? 1 : 0);
