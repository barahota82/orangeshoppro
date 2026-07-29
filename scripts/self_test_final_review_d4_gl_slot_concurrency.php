<?php

declare(strict_types=1);

/**
 * FSR D4 — concurrent Storefront gl_slot allocation (test-only).
 *
 * Usage: php scripts/self_test_final_review_d4_gl_slot_concurrency.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/scripts/lib/final_review_d4_http_fixture.php';

$passes = 0;
$failures = 0;
$skips = 0;
$started = microtime(true);

function d4gc_assert(bool $ok, string $label): void
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

echo 'NOTE  suite=gl_slot_concurrency start=' . gmdate('c') . "\n";

$boot = orange_d4_http_bootstrap($root);
if (empty($boot['ok'])) {
    echo 'ENVIRONMENT_BLOCKED: ' . (string) ($boot['error'] ?? '') . "\n";
    echo "SKIP  live_gl_slot_concurrency\n";
    exit(2);
}

$pdo = $boot['pdo'];
$ids = $boot['ids'] ?? [];
$base = (string) $boot['base_url'];
$sessionDir = (string) ($boot['session_dir'] ?? sys_get_temp_dir());
$cleanup = $boot['cleanup'];
$php = orange_d4_php_bin();
$worker = $root . '/scripts/lib/final_review_d4_http_worker.php';

try {
    $metaPath = $sessionDir . DIRECTORY_SEPARATOR . 'gl_slot_conc_meta.json';
    $items = orange_d4_http_cart_items([
        ['product_id' => 510, 'variant_id' => 610, 'qty' => 1],
        ['product_id' => 511, 'variant_id' => 611, 'qty' => 1],
    ]);
    $results = [];
    $procs = [];
    for ($w = 1; $w <= 2; $w++) {
        $payload = orange_d4_http_checkout_payload(
            $items,
            (int) ($ids['kw_channel_id'] ?? 1),
            '50008' . (string) (100 + $w),
            '965',
            1
        );
        $meta = [
            'channel_slug' => 'kw-channel',
            'payload' => $payload,
        ];
        $mp = $sessionDir . DIRECTORY_SEPARATOR . 'meta_w' . $w . '.json';
        file_put_contents($mp, json_encode($meta, JSON_UNESCAPED_UNICODE));
        $rf = $sessionDir . DIRECTORY_SEPARATOR . 'result_w' . $w . '.json';
        $jar = $sessionDir . DIRECTORY_SEPARATOR . 'cookies_base.txt';
        file_put_contents($jar, '');
        $cmd = [$php, $worker, 'checkout_submit', (string) $w, $rf, $base, $jar, $mp];
        $desc = [0 => ['pipe', 'r'], 1 => ['file', $sessionDir . '/w' . $w . '.out', 'w'], 2 => ['file', $sessionDir . '/w' . $w . '.err', 'w']];
        $p = proc_open($cmd, $desc, $pipes, $root, null, ['bypass_shell' => true]);
        if (is_resource($p)) {
            fclose($pipes[0]);
            $procs[$w] = ['proc' => $p, 'result' => $rf];
        }
    }
    foreach ($procs as $w => $info) {
        proc_close($info['proc']);
        $raw = is_file($info['result']) ? (string) file_get_contents($info['result']) : '';
        $j = json_decode($raw, true);
        $results[$w] = is_array($j) ? $j : [];
        echo 'NOTE  worker=' . $w . ' ok=' . (!empty($j['ok']) ? '1' : '0')
            . ' order=' . (string) ($j['order_number'] ?? '') . "\n";
    }
    d4gc_assert(!empty($results[1]['ok']) && !empty($results[2]['ok']), 'two concurrent Storefront Orders succeed');

    $allSlots = [];
    foreach ([1, 2] as $w) {
        $on = (string) ($results[$w]['order_number'] ?? '');
        if ($on === '') {
            continue;
        }
        $oid = (int) $pdo->query('SELECT id FROM orders WHERE order_number = ' . $pdo->quote($on))->fetchColumn();
        $slots = $pdo->query(
            'SELECT gl_slot FROM order_items WHERE order_id = ' . $oid . ' ORDER BY gl_slot ASC'
        )->fetchAll(PDO::FETCH_COLUMN);
        $seen = [];
        $ok = true;
        foreach ($slots as $s) {
            $n = (int) $s;
            if ($n <= 0 || isset($seen[$n])) {
                $ok = false;
            }
            $seen[$n] = true;
            $allSlots[] = $oid . ':' . $n;
        }
        d4gc_assert($ok && count($slots) >= 2, 'worker ' . $w . ' distinct positive gl_slots per order');
        echo 'NOTE  worker_' . $w . '_slots=' . implode(',', array_map('strval', $slots)) . "\n";
    }
    // Cross-order: same gl_slot number may exist on different orders (UNIQUE is per order_id).
    d4gc_assert(count($allSlots) >= 4, 'concurrent orders produced item slots');
    echo 'NOTE  lock_mechanism=SELECT MAX(gl_slot) FOR UPDATE per order_id + UNIQUE(order_id,gl_slot)' . "\n";
} catch (Throwable $e) {
    echo 'FAIL  uncaught: ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    $pdo = null; // release suite connection before DROP DATABASE
    if (is_callable($cleanup)) {
        $cleanup();
    }
}

echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
echo 'DURATION_SEC=' . round(microtime(true) - $started, 3) . "\n";
exit($failures > 0 ? 1 : 0);
