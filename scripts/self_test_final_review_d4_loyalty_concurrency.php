<?php

declare(strict_types=1);

/**
 * FSR Batch D4 — true MySQL concurrency for Loyalty earn/redeem/expire + promo resolve.
 *
 * Usage: php scripts/self_test_final_review_d4_loyalty_concurrency.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/scripts/lib/final_review_d4_fixture.php';

$passes = 0;
$failures = 0;
$skips = 0;

function d4c_assert(bool $ok, string $label): void
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
function d4c_run_workers(string $projectRoot, string $dbName, string $scenario, int $n = 2): array
{
    $php = orange_d4_php_bin();
    $worker = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'lib'
        . DIRECTORY_SEPARATOR . 'final_review_d4_concurrency_worker.php';
    $tmpDir = sys_get_temp_dir();
    $procs = [];
    $files = [];
    for ($i = 1; $i <= $n; $i++) {
        $files[$i] = $tmpDir . DIRECTORY_SEPARATOR . 'd4c_' . $dbName . '_' . $scenario . '_' . $i . '.json';
        @unlink($files[$i]);
        $cmd = [$php, $worker, $dbName, $scenario, (string) $i, $files[$i]];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
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

$boot = orange_d4_bootstrap_isolated_db($root);
if (empty($boot['ok'])) {
    echo "ENVIRONMENT_BLOCKED: " . (string) ($boot['error'] ?? 'unknown') . "\n";
    echo "RESULT=FSR_D4_ENVIRONMENT_BLOCKER\n";
    echo "PASS=0 FAIL=0 SKIP=0\n";
    exit(2);
}

/** @var PDO $pdo */
$pdo = $boot['pdo'];
/** @var array<string,int|string> $ids */
$ids = $boot['ids'] ?? [];
$dbName = (string) ($boot['db_name'] ?? '');
$cleanup = $boot['cleanup'];

try {
    orange_d2_set_admin_country((int) $ids['kw_country_id'], 'kw');
    $kw = (int) $ids['kw_country_id'];
    $cust = (int) $ids['kw_customer_id'];

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS _d4_race_meta (
            k VARCHAR(64) PRIMARY KEY,
            v VARCHAR(191) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // 1) Concurrent earn same order
    $orderId = orange_d1_insert_order($pdo, $kw, (int) $ids['kw_channel_id'], 'D4-RACE-EARN', 15.0, 'delivered', 'paid');
    if (orange_d1_has_column($pdo, 'orders', 'customer_id')) {
        $pdo->prepare('UPDATE orders SET customer_id = ? WHERE id = ?')->execute([$cust, $orderId]);
    }
    $pdo->prepare('REPLACE INTO _d4_race_meta (k,v) VALUES (?,?)')->execute(['order_id', (string) $orderId]);
    $pdo->prepare('REPLACE INTO _d4_race_meta (k,v) VALUES (?,?)')->execute(['customer_id', (string) $cust]);
    $pdo->prepare('REPLACE INTO _d4_race_meta (k,v) VALUES (?,?)')->execute(['net_sales', '15']);
    $r1 = d4c_run_workers($root, $dbName, 'loyalty_earn', 2);
    foreach ($r1 as $r) {
        echo 'NOTE  loyalty_earn worker=' . (int) ($r['worker_id'] ?? 0)
            . ' ok=' . (!empty($r['ok']) ? '1' : '0')
            . ' points=' . (int) ($r['points'] ?? 0)
            . ' err=' . (string) ($r['error'] ?? '') . "\n";
    }
    $earnCnt = (int) $pdo->query(
        "SELECT COUNT(*) FROM loyalty_ledger WHERE kind='earn' AND ref_type='order' AND ref_id={$orderId}"
    )->fetchColumn();
    $slot = orange_gl_voucher_slot_find($pdo, 'order', $orderId, 'loyalty-earn');
    d4c_assert($earnCnt === 1, 'earn race: single ledger earn row');
    d4c_assert($slot !== null && (int) ($slot['journal_voucher_id'] ?? 0) > 0, 'earn race: single GL slot voucher');

    // 2) Concurrent redeem final balance
    // Seed a fresh customer layer with exactly 8 points (min_redeem=5)
    $pdo->prepare(
        "INSERT INTO loyalty_ledger
            (country_id, customer_id, kind, points, points_remaining, point_value, expires_at, ref_type, ref_id, memo)
         VALUES (1, ?, 'earn', 8, 8, 0.01, '2035-01-01 00:00:00', 'order', ?, 'race redeem pool')"
    )->execute([$cust, 77001]);
    $pdo->prepare('REPLACE INTO _d4_race_meta (k,v) VALUES (?,?)')->execute(['customer_id', (string) $cust]);
    $balBefore = orange_loyalty_balance_points($pdo, $cust, $kw);
    $r2 = d4c_run_workers($root, $dbName, 'loyalty_redeem', 2);
    $spentSum = 0;
    $okRedeem = 0;
    foreach ($r2 as $r) {
        $spentSum += (int) ($r['points'] ?? 0);
        if (!empty($r['ok'])) {
            $okRedeem++;
        }
        echo 'NOTE  loyalty_redeem worker=' . (int) ($r['worker_id'] ?? 0)
            . ' ok=' . (!empty($r['ok']) ? '1' : '0')
            . ' points=' . (int) ($r['points'] ?? 0)
            . ' err=' . (string) ($r['error'] ?? '') . "\n";
    }
    $balAfter = orange_loyalty_balance_points($pdo, $cust, $kw);
    d4c_assert($okRedeem >= 1, 'redeem race: at least one spender');
    d4c_assert($balAfter >= 0 && $balAfter < $balBefore, 'redeem race: balance decreased safely');
    d4c_assert($spentSum <= $balBefore, 'redeem race: spent sum ≤ prior balance');

    // 3) Concurrent expire same expired layer
    $pdo->prepare(
        "INSERT INTO loyalty_ledger
            (country_id, customer_id, kind, points, points_remaining, point_value, expires_at, ref_type, ref_id, memo)
         VALUES (1, ?, 'earn', 4, 4, 0.01, '2019-01-01 00:00:00', 'order', ?, 'race expire')"
    )->execute([$cust, 77002]);
    $r3 = d4c_run_workers($root, $dbName, 'loyalty_expire', 2);
    foreach ($r3 as $r) {
        echo 'NOTE  loyalty_expire worker=' . (int) ($r['worker_id'] ?? 0)
            . ' layers=' . (int) ($r['points'] ?? 0)
            . ' err=' . (string) ($r['error'] ?? '') . "\n";
    }
    $expCnt = (int) $pdo->query(
        "SELECT COUNT(*) FROM loyalty_ledger WHERE kind='expire' AND ref_type='loyalty_layer'
         AND ref_id IN (SELECT id FROM loyalty_ledger WHERE ref_id=77002 AND kind='earn')"
    )->fetchColumn();
    // Simpler: expire rows for layers that had ref 77002
    $layerId = (int) $pdo->query(
        "SELECT id FROM loyalty_ledger WHERE kind='earn' AND ref_type='order' AND ref_id=77002 LIMIT 1"
    )->fetchColumn();
    $expForLayer = (int) $pdo->query(
        "SELECT COUNT(*) FROM loyalty_ledger WHERE kind='expire' AND ref_type='loyalty_layer' AND ref_id={$layerId}"
    )->fetchColumn();
    d4c_assert($expForLayer === 1, 'expire race: single expire effect for layer');

    // 4) Concurrent cart resolve determinism
    $pdo->prepare('REPLACE INTO _d4_race_meta (k,v) VALUES (?,?)')->execute(['subtotal', '50']);
    $r4 = d4c_run_workers($root, $dbName, 'cart_promo_resolve', 2);
    $idsResolved = [];
    foreach ($r4 as $r) {
        if (!empty($r['ok'])) {
            $idsResolved[] = (int) ($r['points'] ?? 0);
        }
        echo 'NOTE  cart_promo_resolve worker=' . (int) ($r['worker_id'] ?? 0)
            . ' promo_id=' . (int) ($r['points'] ?? 0) . "\n";
    }
    d4c_assert(count($idsResolved) === 2 && count(array_unique($idsResolved)) === 1, 'cart resolve race: same promo id');

    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    if ($failures > 0) {
        echo "RESULT=FSR_D4_PROVEN_PROMOTION_LOYALTY_GAPS_FOUND\n";
        exit(1);
    }
    echo "RESULT=FSR_D4_LOYALTY_CONCURRENCY_OK\n";
    exit(0);
} catch (Throwable $e) {
    echo "FAIL  uncaught: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    echo "PASS={$passes} FAIL=" . ($failures + 1) . " SKIP={$skips}\n";
    echo "RESULT=FSR_D4_PROVEN_PROMOTION_LOYALTY_GAPS_FOUND\n";
    exit(1);
} finally {
    if (is_callable($cleanup)) {
        $cleanup();
    }
}
