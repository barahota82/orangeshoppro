<?php

declare(strict_types=1);

/**
 * FSR Batch D4 — Promotion usage (first_delivered) + concurrent resolve determinism.
 * Schema 124 has no global promo usage counter table; usage = first_delivered_order_only + schedule.
 *
 * Usage: php scripts/self_test_final_review_d4_promotion_usage_concurrency.php
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

function d4u_assert(bool $ok, string $label): void
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
function d4u_run_workers(string $projectRoot, string $dbName, string $scenario, int $n = 2): array
{
    $php = orange_d4_php_bin();
    $worker = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'lib'
        . DIRECTORY_SEPARATOR . 'final_review_d4_concurrency_worker.php';
    $tmpDir = sys_get_temp_dir();
    $procs = [];
    $files = [];
    for ($i = 1; $i <= $n; $i++) {
        $files[$i] = $tmpDir . DIRECTORY_SEPARATOR . 'd4u_' . $dbName . '_' . $scenario . '_' . $i . '.json';
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
    $phone = '96551112233';

    d4u_assert(
        orange_cart_promo_buyer_first_delivered_ok($pdo, null, $phone, $kw) === true,
        'usage: new phone eligible for first_delivered'
    );
    $oid = orange_d1_insert_order($pdo, $kw, (int) $ids['kw_channel_id'], 'D4-USE-1', 9.0, 'completed', 'paid');
    $pdo->prepare('UPDATE orders SET phone = ? WHERE id = ?')->execute([$phone, $oid]);
    d4u_assert(
        orange_cart_promo_buyer_first_delivered_ok($pdo, null, $phone, $kw) === false,
        'usage: completed order consumes first_delivered eligibility'
    );

    // Concurrent resolve determinism
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS _d4_race_meta (
            k VARCHAR(64) PRIMARY KEY,
            v VARCHAR(191) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->prepare('REPLACE INTO _d4_race_meta (k,v) VALUES (?,?)')->execute(['subtotal', '40']);
    $r = d4u_run_workers($root, $dbName, 'cart_promo_resolve', 2);
    $promoIds = [];
    foreach ($r as $row) {
        if (!empty($row['ok'])) {
            $promoIds[] = (int) ($row['points'] ?? 0);
        }
        echo 'NOTE  worker=' . (int) ($row['worker_id'] ?? 0) . ' promo_id=' . (int) ($row['points'] ?? 0) . "\n";
    }
    d4u_assert(count($promoIds) === 2 && count(array_unique($promoIds)) === 1, 'usage: concurrent resolve same promo');

    // Rollback: failed txn must not invent usage rows (no usage table — assert no promo_pause_log spam from resolve)
    $pauseBefore = orange_table_exists($pdo, 'promo_pause_log')
        ? (int) $pdo->query('SELECT COUNT(*) FROM promo_pause_log')->fetchColumn()
        : 0;
    try {
        $pdo->beginTransaction();
        orange_cart_promotion_resolve($pdo, 50.0, true, $kw);
        throw new RuntimeException('d4_forced_rollback');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        d4u_assert(str_contains($e->getMessage(), 'd4_forced_rollback'), 'usage: forced rollback path');
    }
    $pauseAfter = orange_table_exists($pdo, 'promo_pause_log')
        ? (int) $pdo->query('SELECT COUNT(*) FROM promo_pause_log')->fetchColumn()
        : 0;
    d4u_assert($pauseAfter === $pauseBefore, 'usage: resolve+rollback leaves no pause log side effect');

    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    if ($failures > 0) {
        echo "RESULT=FSR_D4_PROVEN_PROMOTION_LOYALTY_GAPS_FOUND\n";
        exit(1);
    }
    echo "RESULT=FSR_D4_PROMOTION_USAGE_CONCURRENCY_OK\n";
    exit(0);
} catch (Throwable $e) {
    echo "FAIL  uncaught: " . $e->getMessage() . "\n";
    echo "PASS={$passes} FAIL=" . ($failures + 1) . " SKIP={$skips}\n";
    echo "RESULT=FSR_D4_PROVEN_PROMOTION_LOYALTY_GAPS_FOUND\n";
    exit(1);
} finally {
    if (is_callable($cleanup)) {
        $cleanup();
    }
}
