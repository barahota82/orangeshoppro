<?php

declare(strict_types=1);

/**
 * FSR D2 concurrency worker — separate PHP process / MySQL connection.
 *
 * Usage:
 *   php scripts/lib/final_review_d2_concurrency_worker.php <db> <scenario> <worker_id> <result_file>
 *
 * Scenarios:
 *   reserve_last | consume_last | pr_last | sr_last | saj_dup | consume_split | fulfill_web_dup
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$db = (string) ($argv[1] ?? '');
$scenario = (string) ($argv[2] ?? '');
$workerId = (int) ($argv[3] ?? 0);
$resultFile = (string) ($argv[4] ?? '');

if ($db === '' || $scenario === '' || $workerId <= 0 || $resultFile === '') {
    fwrite(STDERR, "usage: worker.php <db> <scenario> <worker_id> <result_file>\n");
    exit(2);
}
if (!preg_match('/^orange_d1_[a-zA-Z0-9_]+$/', $db)) {
    fwrite(STDERR, "invalid db\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
require_once $root . '/scripts/lib/final_review_d2_fixture.php';
orange_d2_load_production_helpers($root);
// Inherit/re-apply schema gate for this process (avoid dump catch-up mid-race).
$flag = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_d2_schema_ok_' . $db . '.flag';
if (is_file($flag)) {
    putenv('ORANGE_SCHEMA_OK_FLAG_PATH=' . $flag);
    $_ENV['ORANGE_SCHEMA_OK_FLAG_PATH'] = $flag;
}

$out = [
    'worker_id' => $workerId,
    'scenario' => $scenario,
    'ok' => false,
    'error' => '',
    'qty' => null,
    'deadlock' => false,
];

try {
    $pdo = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4',
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $pdo->exec('SET NAMES utf8mb4');
    $pdo->exec('SET time_zone = \'+00:00\'');
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

    $kwWh = 10;
    $kwVar = 600;
    $kwLast = 604;
    $kwCountry = 1;
    $kwProduct = 500;

    // Small stagger so both enter critical section close together.
    usleep(20000 * $workerId);

    if ($scenario === 'reserve_last') {
        $orderNo = 'D2-RACE-RES-' . $workerId;
        $pdo->beginTransaction();
        try {
            orange_order_apply_pending_stock_reservation($pdo, $orderNo, [[
                'product' => ['id' => $kwProduct],
                'qty' => 1,
                'color' => '',
                'size' => '',
                'variant_id' => $kwLast,
                'price' => 10.0,
                'cost' => 4.0,
            ]], $kwCountry, $kwWh);
            $pdo->commit();
            $out['ok'] = true;
            $out['qty'] = orange_d2_wvs_qty($pdo, $kwWh, $kwLast);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $out['error'] = $e->getMessage();
            $out['deadlock'] = str_contains(strtolower($e->getMessage()), 'deadlock');
            $out['qty'] = orange_d2_wvs_qty($pdo, $kwWh, $kwLast);
        }
    } elseif ($scenario === 'consume_last') {
        $pdo->beginTransaction();
        try {
            $r = orange_inventory_cost_layers_consume_fifo(
                $pdo,
                $kwWh,
                $kwVar,
                1,
                'order',
                900000 + $workerId
            );
            if ((int) ($r['shortfall'] ?? 0) > 0 || ($r['consumed'] ?? []) === []) {
                throw new RuntimeException('shortfall_or_empty');
            }
            // Also enforce WVS last-unit race
            orange_warehouse_apply_variant_delta($pdo, $kwWh, $kwVar, -1, 0);
            $pdo->commit();
            $out['ok'] = true;
            $out['qty'] = orange_d2_wvs_qty($pdo, $kwWh, $kwVar);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $out['error'] = $e->getMessage();
            $out['deadlock'] = str_contains(strtolower($e->getMessage()), 'deadlock');
            $out['qty'] = orange_d2_wvs_qty($pdo, $kwWh, $kwVar);
        }
    } elseif ($scenario === 'pr_last') {
        $pdo->beginTransaction();
        try {
            orange_purchase_return_apply_line_stock($pdo, $kwProduct, $kwLast, 1);
            orange_inventory_cost_layers_reduce_for_source($pdo, 'purchase', 9400, $kwLast, $kwWh, 1);
            $pdo->commit();
            $out['ok'] = true;
            $out['qty'] = orange_d2_wvs_qty($pdo, $kwWh, $kwLast);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $out['error'] = $e->getMessage();
            $out['deadlock'] = str_contains(strtolower($e->getMessage()), 'deadlock');
            $out['qty'] = orange_d2_wvs_qty($pdo, $kwWh, $kwLast);
        }
    } elseif ($scenario === 'sr_last') {
        $meta = static function (PDO $pdo, string $k): int {
            $st = $pdo->prepare('SELECT v FROM _d2_race_meta WHERE k = ? LIMIT 1');
            $st->execute([$k]);

            return (int) ($st->fetchColumn() ?: 0);
        };
        $orderId = $meta($pdo, 'sr_order_id');
        $productId = $meta($pdo, 'sr_product_id');
        $variantId = $meta($pdo, 'sr_variant_id');
        $pdo->beginTransaction();
        try {
            orange_sales_return_lock_reference_order($pdo, $orderId);
            orange_sales_return_assert_qty_against_order($pdo, $orderId, [[
                'product_id' => $productId,
                'variant_id' => $variantId,
                'qty' => 1,
            ]]);
            // Document row so returned_qty_map sees the claim (same tx as stock).
            $tmpNum = 'D2-SR-RACE-' . $workerId . '-' . getmypid();
            $pdo->prepare(
                'INSERT INTO sales_returns (return_number, order_id, type, total, notes)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$tmpNum, $orderId, 'cash', 10.0, 'd2 race']);
            $returnId = (int) $pdo->lastInsertId();
            if (orange_d1_has_column($pdo, 'sales_return_items', 'variant_id')) {
                $pdo->prepare(
                    'INSERT INTO sales_return_items (sales_return_id, product_id, variant_id, qty, price, line_discount)
                     VALUES (?,?,?,?,?,?)'
                )->execute([$returnId, $productId, $variantId, 1, 10.0, 0]);
            } else {
                $pdo->prepare(
                    'INSERT INTO sales_return_items (sales_return_id, product_id, qty, price)
                     VALUES (?,?,?,?)'
                )->execute([$returnId, $productId, 1, 10.0]);
            }
            orange_sales_return_add_line_stock($pdo, $productId, $variantId, 1);
            orange_inventory_cost_layer_add(
                $pdo,
                $kwWh,
                $variantId,
                1,
                4.0,
                'sale_return',
                $returnId,
                $kwCountry,
                null,
                $tmpNum
            );
            $pdo->commit();
            $out['ok'] = true;
            $out['qty'] = orange_d2_wvs_qty($pdo, $kwWh, $variantId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $out['error'] = $e->getMessage();
            $out['deadlock'] = str_contains(strtolower($e->getMessage()), 'deadlock');
            $out['qty'] = orange_d2_wvs_qty($pdo, $kwWh, $variantId);
        }
    } elseif ($scenario === 'saj_dup') {
        // Two workers try to consume same adjust source once — second should see shortfall/insufficient
        $pdo->beginTransaction();
        try {
            orange_warehouse_apply_variant_delta($pdo, $kwWh, $kwVar, -1, 0);
            $r = orange_inventory_cost_layers_consume_fifo($pdo, $kwWh, $kwVar, 1, 'stock_adj', 9500);
            if ((int) ($r['shortfall'] ?? 0) > 0) {
                throw new RuntimeException('shortfall');
            }
            $pdo->commit();
            $out['ok'] = true;
            $out['qty'] = orange_d2_wvs_qty($pdo, $kwWh, $kwVar);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $out['error'] = $e->getMessage();
            $out['deadlock'] = str_contains(strtolower($e->getMessage()), 'deadlock');
            $out['qty'] = orange_d2_wvs_qty($pdo, $kwWh, $kwVar);
        }
    } elseif ($scenario === 'consume_split') {
        $take = $workerId === 1 ? 2 : 3;
        $pdo->beginTransaction();
        try {
            $r = orange_inventory_cost_layers_consume_fifo(
                $pdo,
                $kwWh,
                $kwVar,
                $take,
                'order',
                960000 + $workerId
            );
            if ((int) ($r['shortfall'] ?? 0) > 0) {
                throw new RuntimeException('shortfall:' . (int) $r['shortfall']);
            }
            orange_warehouse_apply_variant_delta($pdo, $kwWh, $kwVar, -$take, 0);
            $pdo->commit();
            $out['ok'] = true;
            $out['qty'] = orange_d2_wvs_qty($pdo, $kwWh, $kwVar);
            $out['took'] = $take;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $out['error'] = $e->getMessage();
            $out['deadlock'] = str_contains(strtolower($e->getMessage()), 'deadlock');
            $out['qty'] = orange_d2_wvs_qty($pdo, $kwWh, $kwVar);
            $out['took'] = 0;
        }
    } elseif ($scenario === 'fulfill_web_dup') {
        // Two processes race the real production fulfillment entry for one reserved web order.
        // Caller transaction mirrors admin update-status (ISSUE-05: no orders FOR UPDATE here).
        $meta = static function (PDO $pdo, string $k): int {
            $st = $pdo->prepare('SELECT v FROM _d2_race_meta WHERE k = ? LIMIT 1');
            $st->execute([$k]);

            return (int) ($st->fetchColumn() ?: 0);
        };
        $orderId = $meta($pdo, 'ful_order_id');
        $variantId = $meta($pdo, 'ful_variant_id');
        $pdo->beginTransaction();
        try {
            orange_complete_order_fulfillment($pdo, $orderId);
            $pdo->commit();
            $out['ok'] = true;
            $out['qty'] = orange_d2_wvs_qty($pdo, $kwWh, $variantId > 0 ? $variantId : $kwVar);
            $out['already_done'] = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $out['error'] = $e->getMessage();
            $out['deadlock'] = str_contains(strtolower($e->getMessage()), 'deadlock');
            $out['qty'] = orange_d2_wvs_qty($pdo, $kwWh, $variantId > 0 ? $variantId : $kwVar);
        }
    } else {
        $out['error'] = 'unknown_scenario';
    }
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
    $out['deadlock'] = str_contains(strtolower($e->getMessage()), 'deadlock');
}

file_put_contents($resultFile, json_encode($out, JSON_UNESCAPED_UNICODE) . "\n");
exit($out['ok'] ? 0 : 1);
