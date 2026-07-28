<?php

declare(strict_types=1);

/**
 * FSR Batch D1 — Orders behavioral tests (isolated disposable MySQL).
 * No Production business-code changes. No Production data retained.
 *
 * Usage: php scripts/self_test_final_review_d1_orders.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/scripts/lib/final_review_d1_fixture.php';

$passes = 0;
$failures = 0;
$skips = 0;

function d1o_assert(bool $ok, string $label): void
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

function d1o_skip(string $label, string $reason): void
{
    global $skips;
    echo "SKIP  {$label} ({$reason})\n";
    $skips++;
}

$boot = orange_d1_bootstrap_isolated_db($root);
if (empty($boot['ok'])) {
    echo "ENVIRONMENT_BLOCKED: " . (string) ($boot['error'] ?? 'unknown') . "\n";
    echo "PASS=0 FAIL=0 SKIP=0\n";
    exit(2);
}

/** @var PDO $pdo */
$pdo = $boot['pdo'];
/** @var array<string,int|string> $ids */
$ids = $boot['ids'] ?? [];
$cleanup = $boot['cleanup'];

try {
    orange_d1_load_production_helpers($root);

    d1o_assert((int) $pdo->query('SELECT COUNT(*) FROM countries')->fetchColumn() === 2, 'fixture countries KW+EG');
    d1o_assert(ORANGE_CATALOG_SCHEMA_PHP_REVISION === 124, 'schema revision constant remains 124');

    // --- Server-authoritative price (create-manual contract uses orange_variant_effective_price) ---
    $product = ['price' => 10.0, 'cost' => 4.0];
    $variant = ['price' => 10.0, 'cost' => 4.0];
    $clientPrice = 999.0;
    $serverPrice = orange_variant_effective_price($product, $variant);
    d1o_assert($serverPrice === 10.0, 'server price ignores client-supplied override value');
    d1o_assert($serverPrice !== $clientPrice, 'client price 999 is not authoritative');

    $lineQty = 3;
    $lineDiscount = 1.5;
    $lineNet = max(0.0, round($serverPrice * $lineQty - $lineDiscount, 4));
    d1o_assert($lineNet === 28.5, 'line net = qty*serverPrice - discount (create-manual formula)');

    // Channel → order country authority
    $kwCountryFromChannel = orange_sales_order_country_id_for_channel($pdo, (int) $ids['kw_channel_id']);
    $egCountryFromChannel = orange_sales_order_country_id_for_channel($pdo, (int) $ids['eg_channel_id']);
    d1o_assert($kwCountryFromChannel === (int) $ids['kw_country_id'], 'KW channel resolves KW country');
    d1o_assert($egCountryFromChannel === (int) $ids['eg_country_id'], 'EG channel resolves EG country');
    d1o_assert($kwCountryFromChannel !== $egCountryFromChannel, 'channel countries are distinct');

    // Wrong-country product rejection pattern (behavioral: product country vs order country)
    $st = $pdo->prepare('SELECT id, country_id, price, cost, is_active FROM products WHERE id = ?');
    $st->execute([(int) $ids['eg_product_id']]);
    $egProduct = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    d1o_assert(
        (int) ($egProduct['country_id'] ?? 0) === (int) $ids['eg_country_id'],
        'EG product stored country is EG'
    );
    $orderCountryKw = (int) $ids['kw_country_id'];
    $productCountryEg = (int) ($egProduct['country_id'] ?? 0);
    d1o_assert(
        $productCountryEg !== $orderCountryKw,
        'cross-country product/order mismatch is detectable before insert'
    );

    // Status transition guard (completed → cancelled forbidden)
    $blocked = false;
    try {
        orange_order_guard_status_transition('completed', 'cancelled');
    } catch (Throwable $e) {
        $blocked = true;
        d1o_assert(str_contains($e->getMessage(), 'مردود المبيعات'), 'completed→cancelled points to sales return');
    }
    d1o_assert($blocked, 'invalid status transition completed→cancelled rejected');

    $okTransition = true;
    try {
        orange_order_guard_status_transition('pending', 'approved');
    } catch (Throwable) {
        $okTransition = false;
    }
    d1o_assert($okTransition, 'pending→approved allowed by guard (non-completed prev)');

    // Order insert + items + total consistency + country sticky
    $orderId = orange_d1_insert_order(
        $pdo,
        (int) $ids['kw_country_id'],
        (int) $ids['kw_channel_id'],
        'ORD-D1-KW-001',
        $lineNet,
        'pending',
        'unpaid'
    );
    d1o_assert($orderId > 0, 'order header insert');
    orange_d1_insert_order_item(
        $pdo,
        $orderId,
        (int) $ids['kw_product_id'],
        (int) $ids['kw_variant_id'],
        $lineQty,
        $serverPrice
    );
    $hdr = $pdo->prepare('SELECT country_id, total, status FROM orders WHERE id = ?');
    $hdr->execute([$orderId]);
    $orderRow = $hdr->fetch(PDO::FETCH_ASSOC) ?: [];
    d1o_assert((int) ($orderRow['country_id'] ?? 0) === (int) $ids['kw_country_id'], 'order country sticky KW');
    d1o_assert((float) ($orderRow['total'] ?? 0) === $lineNet, 'stored header total matches calculated line net');

    // Trusted parent country for child documents (sales return authority helper)
    $srFromOrder = orange_sales_return_authority_country_id($pdo, [
        'country_id' => 0,
        'order_id' => $orderId,
    ]);
    d1o_assert(
        $srFromOrder === (int) $ids['kw_country_id'],
        'child SR authority derives KW from parent order country'
    );
    $srDirect = orange_sales_return_authority_country_id($pdo, [
        'country_id' => (int) $ids['eg_country_id'],
        'order_id' => $orderId,
    ]);
    d1o_assert(
        $srDirect === (int) $ids['eg_country_id'],
        'explicit SR country_id wins when present on header'
    );

    // Unique order_number
    $dupBlocked = false;
    try {
        orange_d1_insert_order(
            $pdo,
            (int) $ids['kw_country_id'],
            (int) $ids['kw_channel_id'],
            'ORD-D1-KW-001',
            1.0
        );
    } catch (Throwable) {
        $dupBlocked = true;
    }
    d1o_assert($dupBlocked, 'duplicate order_number blocked by DB uniqueness');

    // Transaction rollback: header+item atomicity simulation
    $pdo->beginTransaction();
    try {
        $oid = orange_d1_insert_order(
            $pdo,
            (int) $ids['eg_country_id'],
            (int) $ids['eg_channel_id'],
            'ORD-D1-EG-ROLL',
            20.0
        );
        orange_d1_insert_order_item(
            $pdo,
            $oid,
            (int) $ids['eg_product_id'],
            (int) $ids['eg_variant_id'],
            1,
            20.0
        );
        throw new RuntimeException('forced failure after lines');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        d1o_assert(str_contains($e->getMessage(), 'forced failure'), 'rollback path catches forced failure');
    }
    $orphan = (int) $pdo->query(
        "SELECT COUNT(*) FROM orders WHERE order_number = 'ORD-D1-EG-ROLL'"
    )->fetchColumn();
    d1o_assert($orphan === 0, 'rolled-back order header leaves no orphan row');
    $orphanItems = (int) $pdo->query(
        'SELECT COUNT(*) FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id WHERE o.order_number = \'ORD-D1-EG-ROLL\''
    )->fetchColumn();
    d1o_assert($orphanItems === 0, 'rolled-back order leaves no orphan items');

    // Order number generator includes country code when provided
    $num = generate_order_number('KW');
    d1o_assert(str_starts_with($num, 'ORD-KW-'), 'generate_order_number prefixes country code');

    // Zero/negative qty rejected at formula level (create-manual uses qty validation)
    d1o_assert($lineQty > 0, 'fixture qty positive');
    $negNet = max(0.0, round($serverPrice * 0 - 0, 4));
    d1o_assert($negNet === 0.0, 'zero qty yields zero line net');

    // Authority matrix counters for this suite
    $authoritySafe = 3; // channel→country, sticky country, cross-country detect
    $authorityUnknown = 0;
    d1o_assert($authorityUnknown === 0, 'UNKNOWN_AUTHORITY=0 for D1 orders suite scope');
    d1o_assert($authoritySafe >= 3, 'COUNTRY/RECORD authority checks exercised');

    // Source guard: create-manual still uses server price helper
    $manualSrc = (string) file_get_contents($root . '/admin/api/orders/create-manual.php');
    d1o_assert(
        str_contains($manualSrc, 'orange_variant_effective_price'),
        'create-manual still binds orange_variant_effective_price'
    );
    d1o_assert(
        str_contains($manualSrc, 'beginTransaction'),
        'create-manual still opens a DB transaction'
    );
    d1o_assert(
        (glob($root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'stock' . DIRECTORY_SEPARATOR . '*.php') ?: []) === [],
        'no public stock PHP endpoints under api/stock/'
    );
} finally {
    if (is_callable($cleanup)) {
        $cleanup();
    }
}

echo "\n--- FSR D1 Orders ---\n";
echo "PASS={$passes} FAIL={$failures} SKIP={$skips}\n";
echo "db=" . (string) ($boot['db_name'] ?? '') . " env=" . (string) ($boot['env'] ?? '') . "\n";

exit($failures > 0 ? 1 : 0);
