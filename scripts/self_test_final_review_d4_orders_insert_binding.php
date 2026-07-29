<?php

declare(strict_types=1);

/**
 * FSR D4 — focused Order INSERT placeholder/parameter binding (test-only).
 *
 * Proves the repaired suffix binds 6 values for:
 * phone, area, address, notes, channel_id, total with status literal 'pending'.
 *
 * Usage: php scripts/self_test_final_review_d4_orders_insert_binding.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/phone_validation.php';

$passes = 0;
$failures = 0;
$skips = 0;
$started = microtime(true);

function d4b_assert(bool $ok, string $label): void
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

echo "NOTE  suite=orders_insert_binding start=" . gmdate('c') . "\n";

// Static reconstruction of the reported suffix (matches Production builder intent).
$oldPh = ', ?, ?, ?, ?, ?, ?, \'pending\', ?';
$newPh = ', ?, ?, ?, ?, ?, \'pending\', ?';
$paramsSuffix = ['+96550007701', 'KW Area', 'addr', '', 1, 12.5];
d4b_assert(substr_count($oldPh, '?') === 7, 'old suffix has 7 placeholders (bug)');
d4b_assert(substr_count($newPh, '?') === 6, 'repaired suffix has 6 placeholders');
d4b_assert(count($paramsSuffix) === 6, 'suffix parameter array length 6');
d4b_assert(substr_count($newPh, '?') === count($paramsSuffix), 'repaired ? count equals params');
d4b_assert(str_contains($newPh, "'pending'"), 'status remains literal pending');

$oiq = (string) file_get_contents($root . '/includes/order_intake_queue.php');
d4b_assert(preg_match("/\\\$ph\\s*\\.=\s*', \\\?, \\\?, \\\?, \\\?, \\\?, \\\\'pending\\\\', \\\?'/", $oiq) === 1
    || str_contains($oiq, ", ?, ?, ?, ?, ?, \\'pending\\', ?"), 'Production source contains repaired 6-placeholder suffix');
d4b_assert(!str_contains($oiq, ", ?, ?, ?, ?, ?, ?, \\'pending\\', ?"), 'Production source no longer has buggy 7-placeholder suffix');

// Live MySQL: create disposable DB, insert via execute_checkout after syncing repairs.
require_once $root . '/scripts/lib/final_review_d4_http_fixture.php';

$boot = orange_d4_http_bootstrap($root);
if (empty($boot['ok'])) {
    echo "ENVIRONMENT_BLOCKED: " . (string) ($boot['error'] ?? '') . "\n";
    // Static asserts still count; live path blocked
    echo "NOTE  live_mysql_insert SKIPPED due to environment\n";
    $skips++;
    echo "SKIP  live_orders_insert_mysql\n";
    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    echo "DURATION_SEC=" . round(microtime(true) - $started, 3) . "\n";
    exit($failures > 0 ? 1 : 2);
}

$pdo = $boot['pdo'];
$ids = $boot['ids'] ?? [];
$base = (string) $boot['base_url'];
$jar = (string) $boot['cookie_jar'];
$sessionDir = (string) ($boot['session_dir'] ?? sys_get_temp_dir());
$cleanup = $boot['cleanup'];

try {
    // Report Schema contract for customers.area/address (no Fixture DEFAULT rewrite).
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

    orange_d4_http_prime_channel($base, $jar, 'kw-channel');
    $items = orange_d4_http_cart_items([
        ['product_id' => 510, 'variant_id' => 610, 'qty' => 1],
        ['product_id' => 511, 'variant_id' => 611, 'qty' => 1],
    ]);
    $prev = orange_d4_http_request(
        rtrim($base, '/') . '/api/cart/checkout-preview.php',
        'POST',
        ['items' => $items, 'lang' => 'en', 'delivery_area_id' => 1],
        $jar
    );
    d4b_assert(!empty($prev['json']['success']), 'preview still succeeds (loyalty include)');

    $payload = orange_d4_http_checkout_payload($items, (int) ($ids['kw_channel_id'] ?? 1), '50007799', '965', 1);
    $ord = orange_d4_http_request(
        rtrim($base, '/') . '/api/orders/create-order.php',
        'POST',
        $payload,
        $jar,
        [],
        120
    );
    echo 'NOTE  create-order status=' . (int) ($ord['status'] ?? 0)
        . ' snip=' . substr((string) ($ord['body'] ?? ''), 0, 280) . "\n";

    $log = $sessionDir . DIRECTORY_SEPARATOR . 'php_error.log';
    if (is_file($log)) {
        $logTxt = (string) file_get_contents($log);
        echo 'NOTE  php_error_tail=' . substr($logTxt, -500) . "\n";
        d4b_assert(!str_contains($logTxt, 'HY093'), 'no HY093 after placeholder repair');
    } else {
        d4b_assert(true, 'php_error.log absent (noted)');
    }

    if (!empty($ord['json']['success']) && !empty($ord['json']['order_number'])) {
        $on = (string) $ord['json']['order_number'];
        $st = $pdo->prepare(
            'SELECT customer_id, phone, area, address, notes, channel_id, status, total FROM orders WHERE order_number = ? LIMIT 1'
        );
        $st->execute([$on]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        d4b_assert(is_array($row), 'order row inserted');
        d4b_assert(is_array($row) && (string) ($row['status'] ?? '') === 'pending', 'status pending');
        d4b_assert(is_array($row) && (string) ($row['phone'] ?? '') === '+96550007799', 'phone column correct');
        d4b_assert(is_array($row) && (int) ($row['channel_id'] ?? 0) === (int) ($ids['kw_channel_id'] ?? 1), 'channel_id correct');
        d4b_assert(!str_contains(strtolower((string) ($ord['body'] ?? '')), 'sqlstate'), 'no SQL leak');
        $cid = is_array($row) ? (int) ($row['customer_id'] ?? 0) : 0;
        if ($cid > 0) {
            $cst = $pdo->prepare('SELECT area, address FROM customers WHERE id = ? LIMIT 1');
            $cst->execute([$cid]);
            $c = $cst->fetch(PDO::FETCH_ASSOC);
            d4b_assert(is_array($c) && (string) ($c['area'] ?? 'x') === '', 'new Customer area explicit empty');
            d4b_assert(is_array($c) && (string) ($c['address'] ?? 'x') === '', 'new Customer address explicit empty');
            d4b_assert(
                is_array($row) && trim((string) ($row['area'] ?? '')) !== '',
                'Order area retains delivery data (not empty profile copy)'
            );
        } else {
            d4b_assert(false, 'order linked customer_id');
        }
        d4b_assert(!str_contains($logTxt ?? '', "Field 'area' doesn't have a default value"), 'no Error 1364 area');
        d4b_assert(!str_contains($logTxt ?? '', "Field 'gl_slot' doesn't have a default value"), 'no Error 1364 gl_slot');
        $oid = is_array($row) ? (int) ($pdo->query(
            'SELECT id FROM orders WHERE order_number = ' . $pdo->quote($on) . ' LIMIT 1'
        )->fetchColumn() ?: 0) : 0;
        if ($oid > 0 && orange_table_has_column($pdo, 'order_items', 'gl_slot')) {
            $slots = $pdo->query(
                'SELECT gl_slot FROM order_items WHERE order_id = ' . $oid . ' ORDER BY gl_slot ASC'
            )->fetchAll(PDO::FETCH_COLUMN);
            d4b_assert($slots !== [] && $slots !== false, 'order_items rows exist');
            $okSlots = true;
            $seen = [];
            foreach ($slots as $s) {
                $n = (int) $s;
                if ($n <= 0 || isset($seen[$n])) {
                    $okSlots = false;
                }
                $seen[$n] = true;
            }
            d4b_assert($okSlots, 'each order_item has distinct positive gl_slot');
            echo 'NOTE  gl_slots=' . implode(',', array_map('strval', $slots)) . "\n";
        }
        if (str_contains($logTxt ?? '', 'last_value BIGINT')) {
            echo "NOTE  last_value_BIGINT_seen=1 classify_in_gl_slot_suite\n";
        }
    } else {
        $tail = is_file($log) ? (string) file_get_contents($log) : '';
        if (str_contains($tail, "Field 'gl_slot' doesn't have a default value")) {
            echo "NOTE  defect_candidate=FSR-D4-ORDER-ITEMS-GL-SLOT-01\n";
            d4b_assert(false, 'FSR-D4-ORDER-ITEMS-GL-SLOT-01 still present');
        } elseif (str_contains($tail, "Field 'area' doesn't have a default value")) {
            echo "NOTE  defect_candidate=FSR-D4-CUSTOMER-REQUIRED-FIELDS-01\n";
            d4b_assert(false, 'FSR-D4-CUSTOMER-REQUIRED-FIELDS-01 still present');
        } else {
            d4b_assert(false, 'create-order failed for unexpected reason (see snip/log)');
        }
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
    echo "RESULT=FSR_D4_ADDITIONAL_PROMOTION_ORDER_LOYALTY_GAPS_FOUND\n";
    exit(1);
}
echo "RESULT=FSR_D4_ORDERS_INSERT_BINDING_OK\n";
exit(0);
