<?php

declare(strict_types=1);

/**
 * FSR D4 concurrency worker — separate PHP process / MySQL connection.
 *
 * Usage:
 *   php scripts/lib/final_review_d4_concurrency_worker.php <db> <scenario> <worker_id> <result_file>
 *
 * Scenarios: loyalty_earn | loyalty_redeem | loyalty_expire | cart_promo_resolve
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
require_once $root . '/scripts/lib/final_review_d4_fixture.php';
orange_d4_load_production_helpers($root);
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
    'points' => 0,
    'voucher_id' => 0,
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

    $meta = static function (PDO $pdo, string $k): string {
        $st = $pdo->prepare('SELECT v FROM _d4_race_meta WHERE k = ? LIMIT 1');
        $st->execute([$k]);

        return (string) ($st->fetchColumn() ?: '');
    };

    usleep(12000 * $workerId);
    orange_d2_set_admin_country(1, 'kw');

    if ($scenario === 'loyalty_earn') {
        $orderId = (int) $meta($pdo, 'order_id');
        $cust = (int) $meta($pdo, 'customer_id');
        $net = (float) $meta($pdo, 'net_sales');
        $order = ['id' => $orderId, 'customer_id' => $cust, 'order_number' => 'D4-RACE'];
        try {
            orange_loyalty_earn_for_order($pdo, $order, 1, $net);
            $cnt = (int) $pdo->query(
                "SELECT COUNT(*) FROM loyalty_ledger WHERE kind='earn' AND ref_type='order' AND ref_id={$orderId}"
            )->fetchColumn();
            $out['ok'] = $cnt === 1;
            $out['points'] = $cnt;
            $slot = orange_gl_voucher_slot_find($pdo, 'order', $orderId, 'loyalty-earn');
            $out['voucher_id'] = (int) ($slot['journal_voucher_id'] ?? 0);
        } catch (Throwable $e) {
            $cnt = (int) $pdo->query(
                "SELECT COUNT(*) FROM loyalty_ledger WHERE kind='earn' AND ref_type='order' AND ref_id={$orderId}"
            )->fetchColumn();
            $out['ok'] = $cnt === 1;
            $out['error'] = $e->getMessage();
            $out['deadlock'] = str_contains(strtolower($e->getMessage()), 'deadlock');
            $out['points'] = $cnt;
        }
    } elseif ($scenario === 'loyalty_redeem') {
        $cust = (int) $meta($pdo, 'customer_id');
        $refId = 88000 + $workerId;
        try {
            $pdo->beginTransaction();
            $r = orange_loyalty_apply_redemption($pdo, $cust, 1, 100000, 50.0, 'order', $refId);
            $pdo->commit();
            $out['ok'] = (int) ($r['points'] ?? 0) > 0;
            $out['points'] = (int) ($r['points'] ?? 0);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $out['error'] = $e->getMessage();
            $out['deadlock'] = str_contains(strtolower($e->getMessage()), 'deadlock');
        }
    } elseif ($scenario === 'loyalty_expire') {
        try {
            $res = orange_loyalty_expire_due($pdo, 1);
            $out['ok'] = true;
            $out['points'] = (int) ($res['layers'] ?? 0);
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
            $out['deadlock'] = str_contains(strtolower($e->getMessage()), 'deadlock');
        }
    } elseif ($scenario === 'cart_promo_resolve') {
        $sub = (float) $meta($pdo, 'subtotal');
        $r = orange_cart_promotion_resolve($pdo, $sub, true, 1);
        $out['ok'] = $r !== null;
        $out['points'] = (int) ($r['id'] ?? 0);
    } else {
        $out['error'] = 'unknown_scenario';
    }
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
    $out['deadlock'] = str_contains(strtolower($e->getMessage()), 'deadlock');
}

file_put_contents($resultFile, json_encode($out, JSON_UNESCAPED_UNICODE) . "\n");
exit(!empty($out['ok']) || $out['error'] === '' ? 0 : 1);
