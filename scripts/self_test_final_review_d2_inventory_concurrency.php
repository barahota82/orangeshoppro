<?php

declare(strict_types=1);

/**
 * FSR Batch D2 — true MySQL concurrency / race behavioral tests (proc_open workers).
 *
 * Usage: php scripts/self_test_final_review_d2_inventory_concurrency.php
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
$envBlocked = 0;

function d2c_assert(bool $ok, string $label): void
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
 * @return list<array<string,mixed>>
 */
function d2c_run_workers(string $projectRoot, string $dbName, string $scenario, int $n = 2): array
{
    $php = orange_d2_php_bin();
    $worker = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'lib'
        . DIRECTORY_SEPARATOR . 'final_review_d2_concurrency_worker.php';
    if (!is_file($php) && $php !== 'php') {
        throw new RuntimeException('php binary missing');
    }
    if (!function_exists('proc_open')) {
        throw new RuntimeException('proc_open unavailable');
    }

    $tmpDir = sys_get_temp_dir();
    $procs = [];
    $files = [];
    for ($i = 1; $i <= $n; $i++) {
        $files[$i] = $tmpDir . DIRECTORY_SEPARATOR . 'd2c_' . $dbName . '_' . $scenario . '_' . $i . '.json';
        @unlink($files[$i]);
        $cmd = [$php, $worker, $dbName, $scenario, (string) $i, $files[$i]];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new RuntimeException('proc_open failed');
        }
        fclose($pipes[0]);
        $procs[$i] = ['proc' => $proc, 'pipes' => $pipes];
    }

    $results = [];
    foreach ($procs as $i => $p) {
        stream_get_contents($p['pipes'][1]);
        stream_get_contents($p['pipes'][2]);
        fclose($p['pipes'][1]);
        fclose($p['pipes'][2]);
        proc_close($p['proc']);
        $raw = is_file($files[$i]) ? (string) file_get_contents($files[$i]) : '';
        $decoded = json_decode($raw, true);
        $results[] = is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'no_result_file', 'worker_id' => $i];
        @unlink($files[$i]);
    }

    return $results;
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
/** @var string $dbName */
$dbName = (string) ($boot['db_name'] ?? '');
$cleanup = $boot['cleanup'];

try {
    orange_d2_load_production_helpers($root);
    orange_d2_set_admin_country((int) $ids['kw_country_id'], 'kw');

    $kwWh = (int) $ids['kw_warehouse_id'];
    $kwVar = (int) $ids['kw_variant_id'];
    $kwLast = (int) $ids['kw_variant_last_id'];
    $kwCountry = (int) $ids['kw_country_id'];
    $kwProduct = (int) $ids['kw_product_id'];

    if ($dbName === '' || !function_exists('proc_open')) {
        echo "ENVIRONMENT_BLOCKED: proc_open/db unavailable for concurrency\n";
        echo "RESULT=FSR_D2_ENVIRONMENT_BLOCKER\n";
        exit(2);
    }

    // Isolation level note (InnoDB default REPEATABLE READ)
    $iso = (string) $pdo->query('SELECT @@transaction_isolation')->fetchColumn();
    echo "NOTE  isolation_level={$iso}\n";
    d2c_assert($iso !== '', 'MySQL isolation level readable');

    // Deadlock/retry contract inventory (static from production code — no invented retry)
    $whSrc = (string) file_get_contents($root . '/includes/warehouses.php');
    $iclSrc = (string) file_get_contents($root . '/includes/inventory_cost_layers.php');
    $osSrc = (string) file_get_contents($root . '/includes/order_stock.php');
    $hasRetry = str_contains($whSrc, 'deadlock') || str_contains($iclSrc, 'deadlock')
        || str_contains($osSrc, 'deadlock') || str_contains($whSrc, 'lock wait');
    d2c_assert(!$hasRetry, 'deadlock/lock-wait retry: none in inventory helpers (contract=no retry)');
    echo "NOTE  deadlock_retry_contract=NONE (caller may retry whole operation)\n";

    // --- 1) Two orders reserve final unit ---
    orange_d2_upsert_wvs($pdo, $kwWh, $kwLast, 1);
    $pdo->prepare('DELETE FROM stock_movements WHERE reference LIKE ?')->execute(['ORDER-D2-RACE-RES-%']);
    $r1 = d2c_run_workers($root, $dbName, 'reserve_last', 2);
    $ok1 = 0;
    $fail1 = 0;
    foreach ($r1 as $r) {
        if (!empty($r['ok'])) {
            $ok1++;
        } else {
            $fail1++;
        }
        echo 'NOTE  reserve_last worker=' . (int) ($r['worker_id'] ?? 0)
            . ' ok=' . (!empty($r['ok']) ? '1' : '0')
            . ' err=' . (string) ($r['error'] ?? '')
            . ' deadlock=' . (!empty($r['deadlock']) ? '1' : '0') . "\n";
    }
    $finalLast = orange_d2_wvs_qty($pdo, $kwWh, $kwLast);
    d2c_assert($ok1 === 1 && $fail1 === 1, 'reserve race: exactly one winner');
    d2c_assert($finalLast === 0, 'reserve race: final on-hand = 0 (no oversell)');

    // --- 2) Two fulfillments consume final layer qty ---
    orange_d2_upsert_wvs($pdo, $kwWh, $kwVar, 1);
    $pdo->prepare('DELETE FROM inventory_cost_consumptions WHERE variant_id = ?')->execute([$kwVar]);
    $pdo->prepare('DELETE FROM inventory_cost_layers WHERE warehouse_id = ? AND variant_id = ?')->execute([$kwWh, $kwVar]);
    orange_inventory_cost_layer_add($pdo, $kwWh, $kwVar, 1, 3.0, 'purchase', 9300, $kwCountry, '2026-01-01 00:00:00');
    $r2 = d2c_run_workers($root, $dbName, 'consume_last', 2);
    $ok2 = 0;
    foreach ($r2 as $r) {
        if (!empty($r['ok'])) {
            $ok2++;
        }
        echo 'NOTE  consume_last worker=' . (int) ($r['worker_id'] ?? 0)
            . ' ok=' . (!empty($r['ok']) ? '1' : '0')
            . ' err=' . (string) ($r['error'] ?? '') . "\n";
    }
    d2c_assert($ok2 === 1, 'consume race: exactly one winner');
    d2c_assert(orange_d2_wvs_qty($pdo, $kwWh, $kwVar) === 0, 'consume race: WVS = 0');
    d2c_assert(orange_d2_layer_remaining_sum($pdo, $kwWh, $kwVar) === 0, 'consume race: layers remaining = 0');

    // --- 3) Two PR attempts on last eligible qty ---
    orange_d2_upsert_wvs($pdo, $kwWh, $kwLast, 1);
    $pdo->prepare('DELETE FROM inventory_cost_layers WHERE warehouse_id = ? AND variant_id = ?')->execute([$kwWh, $kwLast]);
    orange_inventory_cost_layer_add($pdo, $kwWh, $kwLast, 1, 4.0, 'purchase', 9400, $kwCountry, '2026-01-01 00:00:00');
    $r3 = d2c_run_workers($root, $dbName, 'pr_last', 2);
    $ok3 = 0;
    foreach ($r3 as $r) {
        if (!empty($r['ok'])) {
            $ok3++;
        }
        echo 'NOTE  pr_last worker=' . (int) ($r['worker_id'] ?? 0)
            . ' ok=' . (!empty($r['ok']) ? '1' : '0')
            . ' err=' . (string) ($r['error'] ?? '') . "\n";
    }
    d2c_assert($ok3 === 1, 'PR race: exactly one winner');
    d2c_assert(orange_d2_wvs_qty($pdo, $kwWh, $kwLast) === 0, 'PR race: on-hand = 0');

    // --- 4) Two Sales Returns race for the last eligible return quantity (order FOR UPDATE + qty assert) ---
    $srOrderNo = 'D2-RACE-SR';
    orange_d2_upsert_wvs($pdo, $kwWh, $kwLast, 5);
    $pdo->prepare('DELETE FROM inventory_cost_layers WHERE warehouse_id = ? AND variant_id = ?')->execute([$kwWh, $kwLast]);
    orange_inventory_cost_layer_add($pdo, $kwWh, $kwLast, 5, 4.0, 'purchase', 9700, $kwCountry, '2026-01-01 00:00:00');
    $srOrderId = orange_d2_insert_fulfillment_order(
        $pdo,
        $kwCountry,
        (int) $ids['kw_channel_id'],
        (int) $ids['kw_customer_id'],
        (string) $ids['kw_customer_phone'],
        $srOrderNo,
        10.0,
        'completed',
        $kwWh
    );
    orange_d1_insert_order_item($pdo, $srOrderId, $kwProduct, $kwLast, 1, 10.0);
    // Persist race target for workers
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS _d2_race_meta (
            k VARCHAR(64) PRIMARY KEY,
            v VARCHAR(191) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->prepare('REPLACE INTO _d2_race_meta (k, v) VALUES (?, ?)')->execute(['sr_order_id', (string) $srOrderId]);
    $pdo->prepare('REPLACE INTO _d2_race_meta (k, v) VALUES (?, ?)')->execute(['sr_product_id', (string) $kwProduct]);
    $pdo->prepare('REPLACE INTO _d2_race_meta (k, v) VALUES (?, ?)')->execute(['sr_variant_id', (string) $kwLast]);
    $wvsSrBefore = orange_d2_wvs_qty($pdo, $kwWh, $kwLast);
    $r4 = d2c_run_workers($root, $dbName, 'sr_last', 2);
    $ok4 = 0;
    foreach ($r4 as $r) {
        if (!empty($r['ok'])) {
            $ok4++;
        }
        echo 'NOTE  sr_last worker=' . (int) ($r['worker_id'] ?? 0)
            . ' ok=' . (!empty($r['ok']) ? '1' : '0')
            . ' err=' . (string) ($r['error'] ?? '') . "\n";
    }
    d2c_assert($ok4 === 1, 'SR race: exactly one winner on last eligible qty');
    d2c_assert(orange_d2_wvs_qty($pdo, $kwWh, $kwLast) === $wvsSrBefore + 1, 'SR race: WVS +1 once');
    $restoredLayers = (int) $pdo->query(
        "SELECT COUNT(*) FROM inventory_cost_layers WHERE source_type = 'sale_return' AND variant_id = " . (int) $kwLast
    )->fetchColumn();
    d2c_assert($restoredLayers === 1, 'SR race: exactly one sale_return layer');

    // --- 5) Duplicate adjustment consume ---
    orange_d2_upsert_wvs($pdo, $kwWh, $kwVar, 1);
    $pdo->prepare('DELETE FROM inventory_cost_consumptions WHERE variant_id = ?')->execute([$kwVar]);
    $pdo->prepare('DELETE FROM inventory_cost_layers WHERE warehouse_id = ? AND variant_id = ?')->execute([$kwWh, $kwVar]);
    orange_inventory_cost_layer_add($pdo, $kwWh, $kwVar, 1, 5.0, 'purchase', 9500, $kwCountry, '2026-01-01 00:00:00');
    $r5 = d2c_run_workers($root, $dbName, 'saj_dup', 2);
    $ok5 = 0;
    foreach ($r5 as $r) {
        if (!empty($r['ok'])) {
            $ok5++;
        }
        echo 'NOTE  saj_dup worker=' . (int) ($r['worker_id'] ?? 0)
            . ' ok=' . (!empty($r['ok']) ? '1' : '0')
            . ' err=' . (string) ($r['error'] ?? '') . "\n";
    }
    d2c_assert($ok5 === 1, 'duplicate adjust consume: exactly one effect');
    d2c_assert(orange_d2_wvs_qty($pdo, $kwWh, $kwVar) === 0, 'duplicate adjust: final qty 0');

    // --- 6) Two processes consume different quantities from same layers (2+3 from 5) ---
    orange_d2_upsert_wvs($pdo, $kwWh, $kwVar, 5);
    $pdo->prepare('DELETE FROM inventory_cost_consumptions WHERE variant_id = ?')->execute([$kwVar]);
    $pdo->prepare('DELETE FROM inventory_cost_layers WHERE warehouse_id = ? AND variant_id = ?')->execute([$kwWh, $kwVar]);
    orange_inventory_cost_layer_add($pdo, $kwWh, $kwVar, 5, 2.0, 'purchase', 9600, $kwCountry, '2026-01-01 00:00:00');
    $r6 = d2c_run_workers($root, $dbName, 'consume_split', 2);
    $took = 0;
    $ok6 = 0;
    foreach ($r6 as $r) {
        if (!empty($r['ok'])) {
            $ok6++;
            $took += (int) ($r['took'] ?? 0);
        }
        echo 'NOTE  consume_split worker=' . (int) ($r['worker_id'] ?? 0)
            . ' ok=' . (!empty($r['ok']) ? '1' : '0')
            . ' took=' . (int) ($r['took'] ?? 0)
            . ' err=' . (string) ($r['error'] ?? '') . "\n";
    }
    d2c_assert($ok6 === 2 && $took === 5, 'split consume: both succeed totaling 5');
    d2c_assert(orange_d2_wvs_qty($pdo, $kwWh, $kwVar) === 0, 'split consume: WVS 0');
    d2c_assert(orange_d2_layer_remaining_sum($pdo, $kwWh, $kwVar) === 0, 'split consume: layers 0');

    // --- 7) Concurrent orange_complete_order_fulfillment on one reserved web order ---
    // ISSUE-05 (orders FOR UPDATE) remains deferred per ORANGE_STOCK_ORDER_POLICY §17.
    // This race proves FSR-D2-FULFILL-01: after reserve, only one inventory finalize effect.
    $fulNo = 'D2-RACE-FUL';
    orange_d2_upsert_wvs($pdo, $kwWh, $kwVar, 10);
    $pdo->prepare('DELETE FROM inventory_cost_consumptions WHERE variant_id = ?')->execute([$kwVar]);
    $pdo->prepare('DELETE FROM inventory_cost_layers WHERE warehouse_id = ? AND variant_id = ?')->execute([$kwWh, $kwVar]);
    $pdo->prepare('DELETE FROM stock_movements WHERE reference = ?')->execute(['ORDER-' . $fulNo]);
    orange_inventory_cost_layer_add($pdo, $kwWh, $kwVar, 10, 3.0, 'purchase', 9800, $kwCountry, '2026-01-01 00:00:00');
    $fulOrderId = orange_d2_insert_fulfillment_order(
        $pdo,
        $kwCountry,
        (int) $ids['kw_channel_id'],
        (int) $ids['kw_customer_id'],
        (string) $ids['kw_customer_phone'],
        $fulNo,
        30.0,
        'approved',
        $kwWh
    );
    $fulItemId = orange_d1_insert_order_item($pdo, $fulOrderId, $kwProduct, $kwVar, 3, 10.0);
    $pdo->beginTransaction();
    orange_order_apply_pending_stock_reservation($pdo, $fulNo, [[
        'product' => ['id' => $kwProduct],
        'qty' => 3,
        'color' => '',
        'size' => '',
        'variant_id' => $kwVar,
        'price' => 10.0,
        'cost' => 3.0,
    ]], $kwCountry, $kwWh);
    $pdo->commit();
    $pdo->prepare("UPDATE orders SET status = 'completed' WHERE id = ?")->execute([$fulOrderId]);
    $wvsFulBefore = orange_d2_wvs_qty($pdo, $kwWh, $kwVar);
    d2c_assert($wvsFulBefore === 7, 'fulfill race setup: WVS after reserve = 7');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS _d2_race_meta (
            k VARCHAR(64) PRIMARY KEY,
            v VARCHAR(191) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->prepare('REPLACE INTO _d2_race_meta (k, v) VALUES (?, ?)')->execute(['ful_order_id', (string) $fulOrderId]);
    $pdo->prepare('REPLACE INTO _d2_race_meta (k, v) VALUES (?, ?)')->execute(['ful_variant_id', (string) $kwVar]);
    $r7 = d2c_run_workers($root, $dbName, 'fulfill_web_dup', 2);
    foreach ($r7 as $r) {
        echo 'NOTE  fulfill_web_dup worker=' . (int) ($r['worker_id'] ?? 0)
            . ' ok=' . (!empty($r['ok']) ? '1' : '0')
            . ' err=' . (string) ($r['error'] ?? '')
            . ' deadlock=' . (!empty($r['deadlock']) ? '1' : '0')
            . ' qty=' . (string) ($r['qty'] ?? '') . "\n";
    }
    $wvsFulAfter = orange_d2_wvs_qty($pdo, $kwWh, $kwVar);
    $fulFulfilled = orange_d2_movement_count($pdo, 'ORDER-' . $fulNo, 'pending_order_fulfilled');
    $fulDelivered = orange_d2_movement_count($pdo, 'ORDER-' . $fulNo, 'delivered_order');
    $fulCons = orange_d2_consumption_qty($pdo, 'order', $fulItemId);
    d2c_assert($wvsFulAfter === $wvsFulBefore, 'fulfill race: WVS unchanged (no second decrement)');
    d2c_assert($fulFulfilled === 1, 'fulfill race: exactly one pending_order_fulfilled');
    d2c_assert($fulDelivered === 0, 'fulfill race: zero delivered_order on reserved path');
    d2c_assert($fulCons === 3, 'fulfill race: FIFO consumed once (qty=3)');
    d2c_assert(orange_d2_layer_remaining_sum($pdo, $kwWh, $kwVar) === 7, 'fulfill race: layers remaining = 7');
    // Mutation-proof note: without independent pending_order_fulfilled recognition, a late
    // second worker would take the non-reserved path (WVS drop + delivered_order). Proven
    // sequentially in workflows; concurrent final state must remain one-effect.
    d2c_assert(
        $wvsFulAfter === 7 && $fulDelivered === 0 && $fulFulfilled === 1,
        'mutation-proof concurrency: reserved web finalize stays single-effect'
    );

    // Recon duplicate approve sequential (not parallel needed — already in workflows); quick concurrent status flip
    $pdo->prepare(
        'INSERT INTO inventory_reconciliation (warehouse_id, status, counted_at, country_id)
         VALUES (?, \'draft\', ?, ?)'
    )->execute([$kwWh, '2026-07-17', $kwCountry]);
    $recId = (int) $pdo->lastInsertId();
    // Simulate duplicate posting: two sequential approve — second fails
    orange_inventory_reconciliation_approve($pdo, $recId, 0, $kwCountry);
    $dup = false;
    try {
        orange_inventory_reconciliation_approve($pdo, $recId, 0, $kwCountry);
    } catch (Throwable) {
        $dup = true;
    }
    d2c_assert($dup, 'recon duplicate approve prevented');

    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips} ENV_BLOCKED={$envBlocked}\n";
    if ($failures > 0) {
        echo "RESULT=FSR_D2_PROVEN_INVENTORY_GAPS_FOUND\n";
        exit(1);
    }
    echo "RESULT=FSR_D2_CONCURRENCY_SUITE_OK\n";
    exit(0);
} catch (Throwable $e) {
    echo "FAIL  uncaught: " . $e->getMessage() . "\n";
    if (str_contains($e->getMessage(), 'proc_open') || str_contains($e->getMessage(), 'php binary')) {
        echo "RESULT=FSR_D2_ENVIRONMENT_BLOCKER\n";
        exit(2);
    }
    echo "PASS={$passes} FAIL=" . ($failures + 1) . " SKIP={$skips}\n";
    echo "RESULT=FSR_D2_PROVEN_INVENTORY_GAPS_FOUND\n";
    exit(1);
} finally {
    if (is_callable($cleanup)) {
        $cleanup();
    }
}
