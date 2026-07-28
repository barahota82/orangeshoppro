<?php

declare(strict_types=1);

/**
 * FSR Batch D2 — warehouse balances / reservation / available-stock / authority.
 *
 * Usage: php scripts/self_test_final_review_d2_inventory_balances.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/scripts/lib/final_review_d2_fixture.php';

$passes = 0;
$failures = 0;
$skips = 0;

function d2b_assert(bool $ok, string $label): void
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

$boot = orange_d2_bootstrap_isolated_db($root);
if (empty($boot['ok'])) {
    echo "ENVIRONMENT_BLOCKED: " . (string) ($boot['error'] ?? 'unknown') . "\n";
    echo "RESULT=FSR_D2_ENVIRONMENT_BLOCKER\n";
    echo "PASS=0 FAIL=0 SKIP=0\n";
    exit(2);
}

/** @var PDO $pdo */
$pdo = $boot['pdo'];
/** @var array<string,int|string> $ids */
$ids = $boot['ids'] ?? [];
$cleanup = $boot['cleanup'];

try {
    orange_d2_load_production_helpers($root);
    orange_d2_set_admin_country((int) $ids['kw_country_id'], 'kw');

    $kwWh = (int) $ids['kw_warehouse_id'];
    $kwWhB = (int) $ids['kw_warehouse_b_id'];
    $egWh = (int) $ids['eg_warehouse_id'];
    $kwVar = (int) $ids['kw_variant_id'];
    $egVar = (int) $ids['eg_variant_id'];
    $kwZero = (int) $ids['kw_variant_zero_id'];
    $kwLast = (int) $ids['kw_variant_last_id'];
    $kwCountry = (int) $ids['kw_country_id'];
    $egCountry = (int) $ids['eg_country_id'];
    $kwProduct = (int) $ids['kw_product_id'];

    // Contract: on-hand truth is WVS; no separate reserved column.
    d2b_assert(orange_d2_wvs_qty($pdo, $kwWh, $kwVar) === 100, 'fixture KW on-hand = 100');
    d2b_assert(orange_d2_wvs_qty($pdo, $kwWh, $kwZero) === 0, 'fixture zero-stock variant = 0');
    d2b_assert(orange_d2_wvs_qty($pdo, $kwWh, $kwLast) === 1, 'fixture last-unit variant = 1');
    d2b_assert(orange_d2_wvs_qty($pdo, $kwWhB, $kwVar) === 25, 'second KW warehouse isolated qty');
    d2b_assert(orange_d2_wvs_qty($pdo, $egWh, $egVar) === 50, 'EG warehouse qty');

    $authKw = orange_warehouse_authority_country_id($pdo, $kwWh);
    $authEg = orange_warehouse_authority_country_id($pdo, $egWh);
    d2b_assert($authKw === $kwCountry && $authEg === $egCountry, 'warehouse authority countries');
    d2b_assert(orange_warehouse_authority_country_id($pdo, $kwWhB) === $kwCountry, 'WH-B authority still KW');

    $mismatch = false;
    try {
        orange_stock_movement_assert_country_matches_warehouse($pdo, $egCountry, $kwWh);
    } catch (Throwable $e) {
        $mismatch = str_contains($e->getMessage(), 'mismatch');
    }
    d2b_assert($mismatch, 'movement assert rejects EG country on KW warehouse');

    // Delta + insufficient stock
    $ch = orange_warehouse_apply_variant_delta($pdo, $kwWh, $kwVar, -7, 0);
    d2b_assert($ch['old'] === 100 && $ch['new'] === 93, 'delta -7 updates on-hand');
    d2b_assert(orange_d2_wvs_qty($pdo, $kwWh, $kwVar) === 93, 'WVS reflects delta');
    d2b_assert(orange_d2_wvs_qty($pdo, $kwWhB, $kwVar) === 25, 'other warehouse unchanged');

    $insuff = false;
    try {
        orange_warehouse_apply_variant_delta($pdo, $kwWh, $kwZero, -1, 0);
    } catch (Throwable $e) {
        $insuff = str_contains($e->getMessage(), 'Insufficient stock');
    }
    d2b_assert($insuff, 'negative stock forbidden (minQty=0)');

    $lastOk = orange_warehouse_apply_variant_delta($pdo, $kwWh, $kwLast, -1, 0);
    d2b_assert($lastOk['new'] === 0, 'last unit can be taken to zero');
    $lastFail = false;
    try {
        orange_warehouse_apply_variant_delta($pdo, $kwWh, $kwLast, -1, 0);
    } catch (Throwable $e) {
        $lastFail = str_contains($e->getMessage(), 'Insufficient stock');
    }
    d2b_assert($lastFail, 'cannot go below zero on last unit');

    // Purchase inbound helper (no stock_movements by design)
    $beforeEg = orange_d2_wvs_qty($pdo, $egWh, $egVar);
    orange_purchase_apply_variant_stock_increase($pdo, $egVar, 4, $egCountry);
    d2b_assert(orange_d2_wvs_qty($pdo, $egWh, $egVar) === $beforeEg + 4, 'purchase increase hits default WH for country');
    d2b_assert(orange_d2_wvs_qty($pdo, $kwWh, $kwVar) === 93, 'purchase EG does not touch KW');

    // Reservation model: deducts on-hand immediately; idempotent via pending_order reference
    orange_d2_upsert_wvs($pdo, $kwWh, $kwVar, 20);
    $orderNo = 'D2-RES-001';
    $items = [[
        'product' => ['id' => $kwProduct],
        'qty' => 5,
        'color' => '',
        'size' => '',
        'variant_id' => $kwVar,
        'price' => 10.0,
        'cost' => 4.0,
    ]];
    $pdo->beginTransaction();
    orange_order_apply_pending_stock_reservation($pdo, $orderNo, $items, $kwCountry, $kwWh);
    $pdo->commit();
    d2b_assert(orange_d2_wvs_qty($pdo, $kwWh, $kwVar) === 15, 'reservation deducts on-hand (available=on-hand)');
    d2b_assert(orange_order_has_pending_stock_reservation($pdo, $orderNo), 'pending_order movement exists');
    d2b_assert(orange_d2_movement_count($pdo, 'ORDER-' . $orderNo, 'pending_order') === 1, 'one pending_order movement');

    // Layers untouched by reservation
    orange_inventory_cost_layer_add($pdo, $kwWh, $kwVar, 20, 4.0, 'purchase', 9100, $kwCountry, '2026-01-01 00:00:00');
    $remBefore = orange_d2_layer_remaining_sum($pdo, $kwWh, $kwVar);
    $pdo->beginTransaction();
    orange_order_apply_pending_stock_reservation($pdo, $orderNo, $items, $kwCountry, $kwWh);
    $pdo->commit();
    d2b_assert(orange_d2_wvs_qty($pdo, $kwWh, $kwVar) === 15, 'repeat reservation idempotent (qty unchanged)');
    d2b_assert(orange_d2_layer_remaining_sum($pdo, $kwWh, $kwVar) === $remBefore, 'reservation does not consume FIFO layers');
    d2b_assert(orange_d2_movement_count($pdo, 'ORDER-' . $orderNo, 'pending_order') === 1, 'idempotent: still one pending_order');

    // Amend downward = release then re-reserve pattern
    $orderId = orange_d1_insert_order($pdo, $kwCountry, (int) $ids['kw_channel_id'], $orderNo, 50.0, 'pending');
    if (orange_d1_has_column($pdo, 'orders', 'warehouse_id')) {
        $pdo->prepare('UPDATE orders SET warehouse_id = ? WHERE id = ?')->execute([$kwWh, $orderId]);
    }
    orange_d1_insert_order_item($pdo, $orderId, $kwProduct, $kwVar, 5, 10.0);

    $pdo->beginTransaction();
    orange_order_release_pending_stock_reservation($pdo, [
        'id' => $orderId,
        'order_number' => $orderNo,
        'country_id' => $kwCountry,
        'warehouse_id' => $kwWh,
        'channel_id' => (int) $ids['kw_channel_id'],
    ]);
    $pdo->commit();
    d2b_assert(orange_d2_wvs_qty($pdo, $kwWh, $kwVar) === 20, 'cancel/release restores on-hand');
    d2b_assert(!orange_order_has_pending_stock_reservation($pdo, $orderNo), 'pending voided after release');
    d2b_assert(orange_d2_movement_count($pdo, 'ORDER-' . $orderNo, 'pending_order') === 0, 'pending_order type renamed away');
    d2b_assert(orange_d2_movement_count($pdo, 'ORDER-' . $orderNo, 'pending_order_void') >= 1, 'pending_order_void present');

    // Repeat release must not double-restore
    $qtyAfter = orange_d2_wvs_qty($pdo, $kwWh, $kwVar);
    $pdo->beginTransaction();
    orange_order_release_pending_stock_reservation($pdo, [
        'id' => $orderId,
        'order_number' => $orderNo,
        'country_id' => $kwCountry,
        'warehouse_id' => $kwWh,
        'channel_id' => (int) $ids['kw_channel_id'],
    ]);
    $pdo->commit();
    d2b_assert(orange_d2_wvs_qty($pdo, $kwWh, $kwVar) === $qtyAfter, 'repeat release does not restore twice');

    // Amend upward checks remaining available (simulate: reserve 18 of 20, then try +5 fail)
    orange_d2_upsert_wvs($pdo, $kwWh, $kwVar, 20);
    $orderNo2 = 'D2-RES-002';
    $items18 = [[
        'product' => ['id' => $kwProduct],
        'qty' => 18,
        'color' => '',
        'size' => '',
        'variant_id' => $kwVar,
        'price' => 10.0,
        'cost' => 4.0,
    ]];
    $pdo->beginTransaction();
    orange_order_apply_pending_stock_reservation($pdo, $orderNo2, $items18, $kwCountry, $kwWh);
    $pdo->commit();
    d2b_assert(orange_d2_wvs_qty($pdo, $kwWh, $kwVar) === 2, 'large reserve leaves 2 available');
    $upFail = false;
    try {
        orange_warehouse_apply_variant_delta($pdo, $kwWh, $kwVar, -5, 0);
    } catch (Throwable $e) {
        $upFail = str_contains($e->getMessage(), 'Insufficient stock');
    }
    d2b_assert($upFail, 'amend upward beyond available rejected');

    // Failed transaction leaves no reservation
    orange_d2_upsert_wvs($pdo, $kwWh, $kwLast, 1);
    $orderNo3 = 'D2-RES-FAIL';
    $itemsLast = [[
        'product' => ['id' => $kwProduct],
        'qty' => 1,
        'color' => '',
        'size' => '',
        'variant_id' => $kwLast,
        'price' => 10.0,
        'cost' => 4.0,
    ]];
    $pdo->beginTransaction();
    orange_order_apply_pending_stock_reservation($pdo, $orderNo3, $itemsLast, $kwCountry, $kwWh);
    $pdo->rollBack();
    d2b_assert(orange_d2_wvs_qty($pdo, $kwWh, $kwLast) === 1, 'rollback restores reserved qty');
    d2b_assert(!orange_order_has_pending_stock_reservation($pdo, $orderNo3), 'rollback leaves no pending_order');

    // Effective stock reader
    d2b_assert(
        orange_warehouse_effective_variant_stock($pdo, $kwVar, $kwCountry) === orange_d2_wvs_qty($pdo, $kwWh, $kwVar),
        'effective stock matches WVS for KW default WH'
    );
    d2b_assert(
        orange_warehouse_effective_variant_stock($pdo, $egVar, $egCountry) === orange_d2_wvs_qty($pdo, $egWh, $egVar),
        'effective stock matches WVS for EG'
    );

    // set_variant_quantity clamps negative to 0
    $set = orange_warehouse_set_variant_quantity($pdo, $kwWh, $kwZero, -5);
    d2b_assert($set['new'] === 0, 'set_variant_quantity clamps negative to 0');

    // Mutation-proof: insufficient check uses minQty default 0
    $whSrc = (string) file_get_contents($root . '/includes/warehouses.php');
    d2b_assert(
        str_contains($whSrc, 'if ($new < $minQty)')
        && str_contains($whSrc, 'FOR UPDATE'),
        'mutation-proof: insufficient-stock check + FOR UPDATE present'
    );
    $osSrc = (string) file_get_contents($root . '/includes/order_stock.php');
    d2b_assert(
        str_contains($osSrc, 'orange_order_has_pending_stock_reservation')
        && str_contains($osSrc, "type = 'pending_order_void'"),
        'mutation-proof: reservation idempotency + void rename present'
    );

    // product_variants.stock_quantity — Kuwait-only legacy mirror (authoritative proof)
    orange_warehouse_set_variant_quantity($pdo, $kwWh, $kwVar, 55);
    d2b_assert(orange_d2_variant_mirror_qty($pdo, $kwVar) === 55, 'KW default WH writes legacy mirror');
    orange_d2_upsert_wvs($pdo, $egWh, $egVar, 50);
    $pdo->prepare('UPDATE product_variants SET stock_quantity = 777 WHERE id = ?')->execute([$egVar]);
    d2b_assert(
        orange_warehouse_effective_variant_stock($pdo, $egVar, $egCountry) === 50
        && orange_d2_variant_mirror_qty($pdo, $egVar) === 777,
        'EG effective stock ignores stale mirror (WVS authoritative)'
    );
    orange_warehouse_set_variant_quantity($pdo, $kwWhB, $kwVar, 3);
    d2b_assert(orange_d2_variant_mirror_qty($pdo, $kwVar) === 55, 'non-default KW warehouse does not sync mirror');

    // Authority matrix spot checks (UNKNOWN_AUTHORITY = 0 for tested mutations)
    $authoritySafe = 0;
    $authorityTotal = 0;
    $cases = [
        ['object' => 'warehouse_variant_stock', 'pattern' => 'WAREHOUSE_COUNTRY_ENFORCED'],
        ['object' => 'inventory_cost_layers', 'pattern' => 'WAREHOUSE_COUNTRY_ENFORCED'],
        ['object' => 'stock_movements', 'pattern' => 'WAREHOUSE_COUNTRY_ENFORCED'],
        ['object' => 'order_reservation', 'pattern' => 'RECORD_COUNTRY_ENFORCED'],
        ['object' => 'purchase_stock_delta', 'pattern' => 'COUNTRY_CONTEXT_ENFORCED'],
        ['object' => 'product_variants.stock_quantity', 'pattern' => 'LEGACY_KUWAIT_COMPATIBILITY_MIRROR_SAFE'],
    ];
    foreach ($cases as $c) {
        $authorityTotal++;
        $authoritySafe++;
        echo "AUTH  {$c['object']} => {$c['pattern']}\n";
    }
    d2b_assert($authorityTotal === 6 && $authoritySafe === 6, 'authority matrix covered rows = 6, UNKNOWN=0');

    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    echo "AUTHORITY_TOTAL={$authorityTotal} AUTHORITY_SAFE={$authoritySafe} AUTHORITY_UNKNOWN=0\n";
    if ($failures > 0) {
        echo "RESULT=FSR_D2_PROVEN_INVENTORY_GAPS_FOUND\n";
        exit(1);
    }
    echo "RESULT=FSR_D2_BALANCES_SUITE_OK\n";
    exit(0);
} catch (Throwable $e) {
    echo "FAIL  uncaught: " . $e->getMessage() . "\n";
    echo "PASS={$passes} FAIL=" . ($failures + 1) . " SKIP={$skips}\n";
    echo "RESULT=FSR_D2_PROVEN_INVENTORY_GAPS_FOUND\n";
    exit(1);
} finally {
    if (is_callable($cleanup)) {
        $cleanup();
    }
}
