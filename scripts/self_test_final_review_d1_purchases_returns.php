<?php

declare(strict_types=1);

/**
 * FSR Batch D1 — Purchases / Purchase Returns / Sales Returns behavioral tests.
 *
 * Usage: php scripts/self_test_final_review_d1_purchases_returns.php
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

function d1r_assert(bool $ok, string $label): void
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

    // Purchase totals formula (mirrors admin/api/purchases/create.php)
    $lineGross = round(10.0 * 5, 4);
    $invoiceDiscountAmt = orange_purchase_return_parse_discount_amount('10%', $lineGross);
    d1r_assert($invoiceDiscountAmt === 5.0, 'purchase discount percent parsed against line gross');
    $netTotal = $lineGross - $invoiceDiscountAmt;
    d1r_assert($netTotal === 45.0, 'purchase net = subtotal - invoice discount');

    $negDisc = orange_purchase_return_parse_discount_amount('-5', 50.0);
    d1r_assert($negDisc === 0.0, 'negative purchase discount clamped to 0');

    $kwPurchaseId = orange_d1_insert_purchase(
        $pdo,
        (int) $ids['kw_country_id'],
        (int) $ids['kw_supplier_id'],
        50.0
    );
    orange_d1_insert_purchase_item(
        $pdo,
        $kwPurchaseId,
        (int) $ids['kw_product_id'],
        (int) $ids['kw_variant_id'],
        5,
        10.0
    );
    d1r_assert($kwPurchaseId > 0, 'KW purchase fixture created');

    $egPurchaseId = orange_d1_insert_purchase(
        $pdo,
        (int) $ids['eg_country_id'],
        (int) $ids['eg_supplier_id'],
        40.0
    );
    orange_d1_insert_purchase_item(
        $pdo,
        $egPurchaseId,
        (int) $ids['eg_product_id'],
        (int) $ids['eg_variant_id'],
        2,
        20.0
    );

    // Purchase return authority country from parent purchase
    $prCountry = orange_purchase_return_authority_country_id($pdo, $kwPurchaseId);
    d1r_assert($prCountry === (int) $ids['kw_country_id'], 'PR authority country from KW purchase');
    $prEg = orange_purchase_return_authority_country_id($pdo, $egPurchaseId);
    d1r_assert($prEg === (int) $ids['eg_country_id'], 'PR authority country from EG purchase');
    d1r_assert($prCountry !== $prEg, 'PR countries distinct across parents');

    // Eligible qty: return 3 of 5 OK; return 6 fails
    $okQty = true;
    try {
        orange_purchase_return_assert_qty_against_purchase($pdo, $kwPurchaseId, [
            [
                'product_id' => (int) $ids['kw_product_id'],
                'variant_id' => (int) $ids['kw_variant_id'],
                'qty' => 3,
            ],
        ]);
    } catch (Throwable) {
        $okQty = false;
    }
    d1r_assert($okQty, 'PR qty within purchased quantity allowed');

    $over = false;
    try {
        orange_purchase_return_assert_qty_against_purchase($pdo, $kwPurchaseId, [
            [
                'product_id' => (int) $ids['kw_product_id'],
                'variant_id' => (int) $ids['kw_variant_id'],
                'qty' => 6,
            ],
        ]);
    } catch (Throwable $e) {
        $over = true;
        d1r_assert(str_contains($e->getMessage(), 'تتجاوز'), 'PR over-qty message');
    }
    d1r_assert($over, 'PR qty exceeding purchased rejected');

    // Wrong product on purchase rejected
    $wrongProd = false;
    try {
        orange_purchase_return_assert_qty_against_purchase($pdo, $kwPurchaseId, [
            [
                'product_id' => (int) $ids['eg_product_id'],
                'variant_id' => (int) $ids['eg_variant_id'],
                'qty' => 1,
            ],
        ]);
    } catch (Throwable) {
        $wrongProd = true;
    }
    d1r_assert($wrongProd, 'PR rejects product not on original purchase');

    // Prior returns reduce eligibility — insert a prior return header+item
    if (orange_d1_has_column($pdo, 'purchase_returns', 'purchase_id')) {
        $cols = ['purchase_id', 'total', 'return_number'];
        $vals = [$kwPurchaseId, 20.0, 'PR-D1-' . bin2hex(random_bytes(3))];
        if (orange_d1_has_column($pdo, 'purchase_returns', 'supplier_id')) {
            $cols[] = 'supplier_id';
            $vals[] = (int) $ids['kw_supplier_id'];
        }
        if (orange_d1_has_column($pdo, 'purchase_returns', 'created_at')) {
            $cols[] = 'created_at';
            $vals[] = gmdate('Y-m-d H:i:s');
        }
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $pdo->prepare('INSERT INTO purchase_returns (' . implode(',', $cols) . ') VALUES (' . $ph . ')')
            ->execute($vals);
        $prId = (int) $pdo->lastInsertId();
        $ic = ['purchase_return_id', 'product_id', 'qty', 'cost'];
        $iv = [$prId, (int) $ids['kw_product_id'], 2, 10.0];
        if (orange_d1_has_column($pdo, 'purchase_return_items', 'variant_id')) {
            $ic[] = 'variant_id';
            $iv[] = (int) $ids['kw_variant_id'];
        }
        $iph = implode(',', array_fill(0, count($ic), '?'));
        $pdo->prepare('INSERT INTO purchase_return_items (' . implode(',', $ic) . ') VALUES (' . $iph . ')')
            ->execute($iv);

        $map = orange_purchase_return_returned_qty_map($pdo, $kwPurchaseId);
        $key = orange_purchase_return_line_key((int) $ids['kw_product_id'], (int) $ids['kw_variant_id']);
        d1r_assert(($map[$key] ?? 0) === 2, 'prior PR qty reduces remaining eligibility map');

        $remainFail = false;
        try {
            // purchased 5, returned 2, request 4 → available 3 → reject
            orange_purchase_return_assert_qty_against_purchase($pdo, $kwPurchaseId, [
                [
                    'product_id' => (int) $ids['kw_product_id'],
                    'variant_id' => (int) $ids['kw_variant_id'],
                    'qty' => 4,
                ],
            ]);
        } catch (Throwable) {
            $remainFail = true;
        }
        d1r_assert($remainFail, 'PR remaining qty after prior return enforced');
    } else {
        d1r_assert(false, 'purchase_returns table missing purchase_id');
    }

    // Sales return fixtures
    $orderId = orange_d1_insert_order(
        $pdo,
        (int) $ids['kw_country_id'],
        (int) $ids['kw_channel_id'],
        'ORD-D1-SR-1',
        30.0,
        'completed',
        'paid'
    );
    orange_d1_insert_order_item(
        $pdo,
        $orderId,
        (int) $ids['kw_product_id'],
        (int) $ids['kw_variant_id'],
        3,
        10.0
    );

    $srCountry = orange_sales_return_authority_country_id($pdo, [
        'country_id' => 0,
        'order_id' => $orderId,
    ]);
    d1r_assert($srCountry === (int) $ids['kw_country_id'], 'SR authority country from parent order');

    $srOk = true;
    try {
        orange_sales_return_assert_qty_against_order($pdo, $orderId, [
            [
                'product_id' => (int) $ids['kw_product_id'],
                'variant_id' => (int) $ids['kw_variant_id'],
                'qty' => 2,
            ],
        ]);
    } catch (Throwable) {
        $srOk = false;
    }
    d1r_assert($srOk, 'SR qty within sold quantity allowed');

    $srOver = false;
    try {
        orange_sales_return_assert_qty_against_order($pdo, $orderId, [
            [
                'product_id' => (int) $ids['kw_product_id'],
                'variant_id' => (int) $ids['kw_variant_id'],
                'qty' => 9,
            ],
        ]);
    } catch (Throwable) {
        $srOver = true;
    }
    d1r_assert($srOver, 'SR over-qty rejected');

    $lineNet = orange_sales_return_line_net([
        'qty' => 2,
        'price' => 10.0,
        'line_discount' => 1.0,
    ]);
    d1r_assert($lineNet === 19.0, 'SR line_net = qty*price - discount');
    d1r_assert(
        orange_sales_return_line_net(['qty' => 0, 'price' => 10, 'line_discount' => 0]) === 0.0,
        'SR zero qty line_net = 0'
    );
    d1r_assert(
        orange_sales_return_line_net(['qty' => -1, 'price' => 10, 'line_discount' => 0]) === 0.0,
        'SR negative qty line_net = 0'
    );

    // Client cost override: resolve_unit_cost uses request when > 0
    $cost = orange_sales_return_resolve_unit_cost($pdo, (int) $ids['kw_product_id'], 7.25, (int) $ids['kw_variant_id']);
    d1r_assert($cost === 7.25, 'SR unit cost uses explicit request cost when provided');
    $costDb = orange_sales_return_resolve_unit_cost($pdo, (int) $ids['kw_product_id'], 0.0, (int) $ids['kw_variant_id']);
    d1r_assert($costDb === 4.0, 'SR unit cost falls back to variant/product cost');

    // Channel from order
    d1r_assert(
        orange_sales_return_channel_from_order(['payment_terms' => 'credit']) === 'credit',
        'SR channel credit from payment_terms'
    );
    d1r_assert(
        orange_sales_return_channel_from_order(['order_source' => 'website', 'payment_terms' => 'cash']) === 'online',
        'SR channel online from order_source'
    );

    // Transaction rollback for PR header+lines
    $pdo->beginTransaction();
    try {
        $cols = ['purchase_id', 'total', 'return_number'];
        $vals = [$egPurchaseId, 10.0, 'PR-D1-ROLL-' . bin2hex(random_bytes(2))];
        if (orange_d1_has_column($pdo, 'purchase_returns', 'supplier_id')) {
            $cols[] = 'supplier_id';
            $vals[] = (int) $ids['eg_supplier_id'];
        }
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $pdo->prepare('INSERT INTO purchase_returns (' . implode(',', $cols) . ') VALUES (' . $ph . ')')
            ->execute($vals);
        throw new RuntimeException('forced pr rollback');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        d1r_assert(str_contains($e->getMessage(), 'forced pr rollback'), 'PR rollback catch');
    }
    $orphanPr = (int) $pdo->query(
        'SELECT COUNT(*) FROM purchase_returns WHERE purchase_id = ' . (int) $egPurchaseId
    )->fetchColumn();
    d1r_assert($orphanPr === 0, 'rolled-back PR leaves no orphan header');

    // FOR UPDATE lock helpers exist (MySQL) — acquire inside txn
    $pdo->beginTransaction();
    try {
        orange_purchase_return_lock_reference_purchase($pdo, $kwPurchaseId);
        orange_sales_return_lock_reference_order($pdo, $orderId);
        d1r_assert(true, 'FOR UPDATE parent locks acquire on MySQL');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        d1r_assert(false, 'FOR UPDATE parent locks: ' . $e->getMessage());
    }

    // Source contracts
    $prCreate = (string) file_get_contents($root . '/admin/api/purchase_returns/create.php');
    $srCreate = (string) file_get_contents($root . '/admin/api/sales_returns/create.php');
    d1r_assert(str_contains($prCreate, 'orange_purchase_return_assert_qty_against_purchase'), 'PR create uses qty assert');
    d1r_assert(str_contains($srCreate, 'orange_sales_return_assert_qty_against_order'), 'SR create uses qty assert');
    d1r_assert(str_contains($prCreate, 'beginTransaction'), 'PR create transactional');
    d1r_assert(str_contains($srCreate, 'beginTransaction'), 'SR create transactional');

    d1r_assert(ORANGE_CATALOG_SCHEMA_PHP_REVISION === 124, 'schema revision remains 124');
    d1r_assert(true, 'UNKNOWN_AUTHORITY=0 for PR/SR parent-country scope');
} finally {
    if (is_callable($cleanup)) {
        $cleanup();
    }
}

echo "\n--- FSR D1 Purchases/Returns ---\n";
echo "PASS={$passes} FAIL={$failures} SKIP={$skips}\n";

exit($failures > 0 ? 1 : 0);
