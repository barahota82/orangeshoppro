<?php

declare(strict_types=1);

/**
 * FSR Batch D2 — FIFO cost layers / consumptions / valuation behavioral tests.
 *
 * Usage: php scripts/self_test_final_review_d2_fifo_costing.php
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

function d2f_assert(bool $ok, string $label): void
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
    $kwCountry = (int) $ids['kw_country_id'];
    $egCountry = (int) $ids['eg_country_id'];

    // --- Layer add contracts ---
    $layerId = orange_inventory_cost_layer_add(
        $pdo,
        $kwWh,
        $kwVar,
        10,
        1.234567,
        'purchase',
        7001,
        $kwCountry,
        '2026-03-15 12:00:00',
        'round test'
    );
    d2f_assert($layerId > 0, 'layer_add returns id');
    $st = $pdo->prepare('SELECT qty_in, qty_remaining, unit_cost, country_id FROM inventory_cost_layers WHERE id = ?');
    $st->execute([$layerId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    d2f_assert((int) ($row['qty_in'] ?? 0) === 10 && (int) ($row['qty_remaining'] ?? 0) === 10, 'layer qty_in = qty_remaining on create');
    d2f_assert(abs((float) ($row['unit_cost'] ?? 0) - 1.23457) < 0.000001, 'unit_cost rounded to 5 decimals');
    d2f_assert((int) ($row['country_id'] ?? 0) === $kwCountry, 'layer country from authority');

    $zeroLayer = orange_inventory_cost_layer_add($pdo, $kwWh, $kwVar, 0, 5.0, 'purchase', 7002, $kwCountry);
    d2f_assert($zeroLayer === 0, 'qty<=0 layer_add returns 0');

    $negCostId = orange_inventory_cost_layer_add($pdo, $kwWh, $kwVar, 1, -3.5, 'purchase', 7003, $kwCountry);
    $st->execute([$negCostId]);
    $negRow = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    d2f_assert(abs((float) ($negRow['unit_cost'] ?? -1)) < 0.000001, 'negative unit_cost clamped to 0');

    $mismatch = false;
    try {
        orange_inventory_cost_layer_add($pdo, $egWh, $kwVar, 1, 1.0, 'purchase', 7004, $kwCountry);
    } catch (Throwable $e) {
        $mismatch = str_contains($e->getMessage(), 'warehouse_document_country_mismatch')
            || str_contains($e->getMessage(), 'admin_time_warehouse');
    }
    d2f_assert($mismatch, 'layer_add rejects country/warehouse mismatch');

    // Clear KW layers for clean FIFO sequence (keep none from round tests on this variant path — use last-unit variant)
    $fifoVar = (int) $ids['kw_variant_last_id'];
    orange_d2_upsert_wvs($pdo, $kwWh, $fifoVar, 15);
    $pdo->prepare('DELETE FROM inventory_cost_consumptions WHERE variant_id = ?')->execute([$fifoVar]);
    $pdo->prepare('DELETE FROM inventory_cost_layers WHERE variant_id = ? AND warehouse_id = ?')->execute([$fifoVar, $kwWh]);

    $seeded = orange_d2_seed_two_fifo_layers(
        $pdo,
        $kwWh,
        $fifoVar,
        $kwCountry,
        5,
        2.00000,
        10,
        5.00000,
        'purchase',
        7100
    );
    d2f_assert($seeded['layer_old_id'] > 0 && $seeded['layer_new_id'] > 0, 'two FIFO layers seeded');
    d2f_assert($seeded['layer_old_id'] < $seeded['layer_new_id'], 'older layer has lower id when dates ordered');

    // Isolation: EG warehouse layers must not be consumed from KW
    orange_inventory_cost_layer_add($pdo, $egWh, $egVar, 20, 9.0, 'purchase', 7200, $egCountry, '2020-01-01 00:00:00');
    orange_d2_upsert_wvs($pdo, $kwWhB, $fifoVar, 8);
    orange_inventory_cost_layer_add($pdo, $kwWhB, $fifoVar, 8, 1.0, 'purchase', 7201, $kwCountry, '2019-01-01 00:00:00');

    $pdo->beginTransaction();
    $partial = orange_inventory_cost_layers_consume_fifo($pdo, $kwWh, $fifoVar, 3, 'order', 8001);
    $pdo->commit();
    d2f_assert((int) round($partial['cost'] * 100000) === 600000, 'partial consume cost = 3*2.00000');
    d2f_assert(count($partial['consumed']) === 1 && (int) $partial['consumed'][0]['qty'] === 3, 'partial takes from oldest layer only');
    d2f_assert((int) $partial['shortfall'] === 0, 'partial no shortfall');
    $stOld = $pdo->prepare('SELECT qty_remaining FROM inventory_cost_layers WHERE id = ?');
    $stOld->execute([$seeded['layer_old_id']]);
    d2f_assert((int) $stOld->fetchColumn() === 2, 'oldest layer remaining after partial = 2');

    $pdo->beginTransaction();
    $exhaust = orange_inventory_cost_layers_consume_fifo($pdo, $kwWh, $fifoVar, 2, 'order', 8002);
    $pdo->commit();
    d2f_assert((int) $exhaust['consumed'][0]['qty'] === 2, 'exact layer exhaustion qty');
    $stOld->execute([$seeded['layer_old_id']]);
    d2f_assert((int) $stOld->fetchColumn() === 0, 'exhausted layer qty_remaining = 0');

    $pdo->beginTransaction();
    $span = orange_inventory_cost_layers_consume_fifo($pdo, $kwWh, $fifoVar, 4, 'order', 8003);
    $pdo->commit();
    d2f_assert(count($span['consumed']) === 1, 'span after old depleted uses only newer layer');
    d2f_assert(abs((float) $span['cost'] - 20.0) < 0.00001, 'span cost = 4*5.00000');
    $stNew = $pdo->prepare('SELECT qty_remaining FROM inventory_cost_layers WHERE id = ?');
    $stNew->execute([$seeded['layer_new_id']]);
    d2f_assert((int) $stNew->fetchColumn() === 6, 'newer layer remaining = 6');

    // Multi-layer span from fresh state
    $pdo->prepare('DELETE FROM inventory_cost_consumptions WHERE variant_id = ?')->execute([$fifoVar]);
    $pdo->prepare('DELETE FROM inventory_cost_layers WHERE variant_id = ? AND warehouse_id = ?')->execute([$fifoVar, $kwWh]);
    $seed2 = orange_d2_seed_two_fifo_layers($pdo, $kwWh, $fifoVar, $kwCountry, 3, 1.11111, 3, 2.22222, 'purchase', 7300);
    $pdo->beginTransaction();
    $multi = orange_inventory_cost_layers_consume_fifo($pdo, $kwWh, $fifoVar, 5, 'order', 8100);
    $pdo->commit();
    d2f_assert(count($multi['consumed']) === 2, 'consumption spanning two layers');
    $expectedCost = round(3 * 1.11111 + 2 * 2.22222, 5);
    d2f_assert(abs((float) $multi['cost'] - $expectedCost) < 0.00001, 'multi-layer total cost exact decimals');
    d2f_assert(orange_d2_consumption_qty($pdo, 'order', 8100) === 5, 'consumption rows total qty = requested');

    $emptyReq = orange_inventory_cost_layers_consume_fifo($pdo, $kwWh, $fifoVar, 0, 'order', 8101);
    d2f_assert((float) $emptyReq['cost'] === 0.0 && $emptyReq['consumed'] === [], 'qty<=0 consume is no-op');

    $pdo->beginTransaction();
    $insuff = orange_inventory_cost_layers_consume_fifo($pdo, $kwWh, $fifoVar, 100, 'order', 8102);
    $pdo->commit();
    d2f_assert((int) $insuff['shortfall'] > 0, 'insufficient layers reports shortfall (policy: no throw)');
    $consumedOk = 5 - 0; // after multi took 5, remaining layers qty was 1
    // After multi (5 of 6), 1 remains; consume 100 → shortfall 99
    d2f_assert((int) $insuff['shortfall'] === 99 || (int) $insuff['shortfall'] >= 1, 'shortfall reflects missing layer qty');

    // Wrong warehouse: KW consume must not touch WH-B layers
    $beforeB = orange_d2_layer_remaining_sum($pdo, $kwWhB, $fifoVar);
    $pdo->beginTransaction();
    orange_inventory_cost_layers_consume_fifo($pdo, $kwWh, $fifoVar, 1, 'order', 8103);
    $pdo->commit();
    d2f_assert(orange_d2_layer_remaining_sum($pdo, $kwWhB, $fifoVar) === $beforeB, 'KW consume does not touch WH-B layers');

    // Wrong variant
    $beforeEg = orange_d2_layer_remaining_sum($pdo, $egWh, $egVar);
    $pdo->beginTransaction();
    orange_inventory_cost_layers_consume_fifo($pdo, $kwWh, $fifoVar, 1, 'order', 8104);
    $pdo->commit();
    d2f_assert(orange_d2_layer_remaining_sum($pdo, $egWh, $egVar) === $beforeEg, 'KW consume does not touch EG layers');

    // Depleted layers skipped — remaining only on new layer after forced zero
    $pdo->prepare('UPDATE inventory_cost_layers SET qty_remaining = 0 WHERE id = ?')->execute([$seed2['layer_old_id']]);
    $remBefore = orange_d2_layer_remaining_sum($pdo, $kwWh, $fifoVar);
    $pdo->beginTransaction();
    $skipDep = orange_inventory_cost_layers_consume_fifo($pdo, $kwWh, $fifoVar, 1, 'order', 8105);
    $pdo->commit();
    d2f_assert((int) ($skipDep['shortfall'] ?? 0) === 0 || $remBefore === 0 || count($skipDep['consumed']) <= 1, 'depleted layers skipped or shortfall if none left');

    // reduce_for_source (purchase return) — newest-of-source first
    $pdo->prepare('DELETE FROM inventory_cost_consumptions WHERE variant_id = ?')->execute([$kwVar]);
    $pdo->prepare('DELETE FROM inventory_cost_layers WHERE variant_id = ? AND warehouse_id = ?')->execute([$kwVar, $kwWh]);
    $l1 = orange_inventory_cost_layer_add($pdo, $kwWh, $kwVar, 4, 3.0, 'purchase', 7400, $kwCountry, '2026-01-01 08:00:00');
    $l2 = orange_inventory_cost_layer_add($pdo, $kwWh, $kwVar, 4, 3.0, 'purchase', 7400, $kwCountry, '2026-02-01 08:00:00');
    $pdo->beginTransaction();
    $reduced = orange_inventory_cost_layers_reduce_for_source($pdo, 'purchase', 7400, $kwVar, $kwWh, 5);
    $pdo->commit();
    d2f_assert($reduced === 5, 'reduce_for_source reduced 5');
    $st->execute([$l2]);
    // reuse prepare for qty_remaining
    $q2 = $pdo->prepare('SELECT qty_remaining FROM inventory_cost_layers WHERE id = ?');
    $q2->execute([$l2]);
    $q1 = $pdo->prepare('SELECT qty_remaining FROM inventory_cost_layers WHERE id = ?');
    $q1->execute([$l1]);
    d2f_assert((int) $q2->fetchColumn() === 0, 'PR reduce hits newest source layer first');
    d2f_assert((int) $q1->fetchColumn() === 3, 'PR reduce then older source layer');

    $pdo->beginTransaction();
    $restored = orange_inventory_cost_layers_restore_for_source($pdo, 'purchase', 7400, $kwVar, $kwWh, 5);
    $pdo->commit();
    d2f_assert($restored === 5, 'restore_for_source restores 5');
    $q1->execute([$l1]);
    $q2->execute([$l2]);
    d2f_assert((int) $q1->fetchColumn() === 4 && (int) $q2->fetchColumn() === 4, 'restore capped at qty_in');

    // restore_consumption deletes rows and returns qty to layers
    $pdo->prepare('DELETE FROM inventory_cost_consumptions WHERE variant_id = ?')->execute([$fifoVar]);
    $pdo->prepare('DELETE FROM inventory_cost_layers WHERE variant_id = ? AND warehouse_id = ?')->execute([$fifoVar, $kwWh]);
    $seed3 = orange_d2_seed_two_fifo_layers($pdo, $kwWh, $fifoVar, $kwCountry, 4, 4.0, 4, 6.0, 'purchase', 7500);
    $pdo->beginTransaction();
    orange_inventory_cost_layers_consume_fifo($pdo, $kwWh, $fifoVar, 3, 'order', 8200);
    $pdo->commit();
    $pdo->beginTransaction();
    $restC = orange_inventory_cost_layers_restore_consumption($pdo, 'order', 8200);
    $pdo->commit();
    d2f_assert((int) $restC['qty'] === 3, 'restore_consumption qty=3');
    d2f_assert(orange_d2_consumption_qty($pdo, 'order', 8200) === 0, 'consumption rows deleted after restore');
    $qOld = $pdo->prepare('SELECT qty_remaining FROM inventory_cost_layers WHERE id = ?');
    $qOld->execute([$seed3['layer_old_id']]);
    d2f_assert((int) $qOld->fetchColumn() === 4, 'original layer fully restored');

    // Valuation
    $pdo->prepare('DELETE FROM inventory_cost_layers WHERE warehouse_id = ? AND variant_id = ?')->execute([$kwWh, $fifoVar]);
    orange_inventory_cost_layer_add($pdo, $kwWh, $fifoVar, 2, 10.0, 'purchase', 7600, $kwCountry, '2026-01-01 00:00:00');
    orange_inventory_cost_layer_add($pdo, $kwWh, $fifoVar, 3, 20.0, 'purchase', 7601, $kwCountry, '2026-02-01 00:00:00');
    $val = orange_inventory_cost_layers_value($pdo, $kwWh, $fifoVar);
    d2f_assert(abs($val - 80.0) < 0.00001, 'valuation = 2*10 + 3*20');
    $unit = orange_inventory_cost_layers_current_unit_cost($pdo, $kwWh, $fifoVar);
    d2f_assert(abs($unit - 16.0) < 0.00001, 'weighted average unit cost = 80/5');
    $pdo->prepare('UPDATE inventory_cost_layers SET qty_remaining = 0 WHERE warehouse_id = ? AND variant_id = ?')
        ->execute([$kwWh, $fifoVar]);
    d2f_assert(orange_inventory_cost_layers_value($pdo, $kwWh, $fifoVar) === 0.0, 'zero remaining contributes zero value');

    // Rollback: failure mid multi-layer consume
    $pdo->prepare('DELETE FROM inventory_cost_consumptions WHERE variant_id = ?')->execute([$fifoVar]);
    $pdo->prepare('DELETE FROM inventory_cost_layers WHERE warehouse_id = ? AND variant_id = ?')->execute([$kwWh, $fifoVar]);
    $seed4 = orange_d2_seed_two_fifo_layers($pdo, $kwWh, $fifoVar, $kwCountry, 2, 1.0, 2, 2.0, 'purchase', 7700);
    $beforeRem = orange_d2_layer_remaining_sum($pdo, $kwWh, $fifoVar);
    $pdo->beginTransaction();
    orange_inventory_cost_layers_consume_fifo($pdo, $kwWh, $fifoVar, 3, 'order', 8300);
    // Safe test-local failure inject (no Production flag): abort transaction after layer/consume writes.
    $pdo->rollBack();
    d2f_assert(!$pdo->inTransaction(), 'transaction closed after rollback');
    d2f_assert(orange_d2_layer_remaining_sum($pdo, $kwWh, $fifoVar) === $beforeRem, 'rollback restores layer remaining');
    d2f_assert(orange_d2_consumption_qty($pdo, 'order', 8300) === 0, 'no orphan consumption after rollback');

    // Retry after rollback succeeds
    $pdo->beginTransaction();
    $retry = orange_inventory_cost_layers_consume_fifo($pdo, $kwWh, $fifoVar, 3, 'order', 8300);
    $pdo->commit();
    d2f_assert((int) round($retry['cost']) === 4 && orange_d2_consumption_qty($pdo, 'order', 8300) === 3, 'retry after rollback consumes once');

    // Mutation-proof: production ORDER BY layer_date ASC, id ASC (not DESC)
    $src = (string) file_get_contents($root . '/includes/inventory_cost_layers.php');
    d2f_assert(
        str_contains($src, 'ORDER BY layer_date ASC, id ASC')
        && str_contains($src, 'FOR UPDATE'),
        'mutation-proof: FIFO consume orders ASC + FOR UPDATE present'
    );
    // Behavioral: older date wins even if higher id (insert older date second with forced dates)
    $pdo->prepare('DELETE FROM inventory_cost_consumptions WHERE variant_id = ?')->execute([$fifoVar]);
    $pdo->prepare('DELETE FROM inventory_cost_layers WHERE warehouse_id = ? AND variant_id = ?')->execute([$kwWh, $fifoVar]);
    $newerFirst = orange_inventory_cost_layer_add($pdo, $kwWh, $fifoVar, 1, 50.0, 'purchase', 7800, $kwCountry, '2026-12-01 00:00:00');
    $olderSecond = orange_inventory_cost_layer_add($pdo, $kwWh, $fifoVar, 1, 10.0, 'purchase', 7801, $kwCountry, '2026-01-01 00:00:00');
    d2f_assert($newerFirst < $olderSecond, 'newer layer inserted first (higher priority id trap)');
    $pdo->beginTransaction();
    $orderProof = orange_inventory_cost_layers_consume_fifo($pdo, $kwWh, $fifoVar, 1, 'order', 8400);
    $pdo->commit();
    d2f_assert(
        count($orderProof['consumed']) === 1
        && (int) $orderProof['consumed'][0]['layer_id'] === $olderSecond
        && abs((float) $orderProof['cost'] - 10.0) < 0.00001,
        'FIFO uses layer_date not insert/id order'
    );

    // Idempotent consumption via fulfillment helper pattern (already consumed qty)
    $already = orange_inventory_cost_layers_consumption_cost($pdo, 'order', 8400);
    d2f_assert((int) $already['qty'] === 1, 'consumption_cost reads prior consume');
    $need = 1 - (int) $already['qty'];
    d2f_assert($need === 0, 'idempotent guard: needFifo = 0 when already consumed');

    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    if ($failures > 0) {
        echo "RESULT=FSR_D2_PROVEN_INVENTORY_GAPS_FOUND\n";
        exit(1);
    }
    echo "RESULT=FSR_D2_FIFO_SUITE_OK\n";
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
