<?php

declare(strict_types=1);

/**
 * FSR D3 concurrency worker — separate PHP process / MySQL connection.
 *
 * Usage:
 *   php scripts/lib/final_review_d3_concurrency_worker.php <db> <scenario> <worker_id> <result_file>
 *
 * Scenarios: voucher_number | pending_post | purchase_slot | loyalty_simple_gl
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
require_once $root . '/scripts/lib/final_review_d3_fixture.php';
orange_d3_load_production_helpers($root);
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
        $st = $pdo->prepare('SELECT v FROM _d3_race_meta WHERE k = ? LIMIT 1');
        $st->execute([$k]);

        return (string) ($st->fetchColumn() ?: '');
    };

    usleep(15000 * $workerId);
    orange_d2_set_admin_country(1, 'kw');

    if ($scenario === 'voucher_number') {
        $cash = (int) $meta($pdo, 'cash_id');
        $sales = (int) $meta($pdo, 'sales_id');
        // orange_voucher_post owns its transaction — do not wrap in an outer commit.
        try {
            $vid = orange_d3_post_manual($pdo, 1, 'race-' . $workerId, [
                ['account_id' => $cash, 'debit' => 1.0, 'credit' => 0.0, 'memo' => 'r1'],
                ['account_id' => $sales, 'debit' => 0.0, 'credit' => 1.0, 'memo' => 'r2'],
            ]);
            $out['ok'] = $vid > 0;
            $out['voucher_id'] = $vid;
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
            $out['deadlock'] = str_contains(strtolower($e->getMessage()), 'deadlock');
        }
    } elseif ($scenario === 'pending_post') {
        // Cold worker process — PENDING-03 ownTx repair must allow first post without warm.
        orange_d3_enable_pending_queue(true);
        $pid = (int) $meta($pdo, 'pending_id');
        $res = orange_gl_pending_post_by_ids($pdo, [$pid]);
        $posted = is_array($res['posted'] ?? null) ? $res['posted'] : [];
        $out['ok'] = in_array($pid, array_map('intval', $posted), true);
        $out['error'] = implode('; ', array_map('strval', is_array($res['errors'] ?? null) ? $res['errors'] : []));
        $out['voucher_id'] = $pid;
    } elseif ($scenario === 'purchase_slot') {
        $purchaseId = (int) $meta($pdo, 'purchase_id');
        $sup = (int) $meta($pdo, 'supplier_id');
        $amount = (float) $meta($pdo, 'amount');
        $glB = orange_gl_purchase_invoice_posting_bundle($pdo, 'credit', $sup, $purchaseId, $amount, 1);
        $times = orange_gl_posting_times_for_country($pdo, 1, '2026-07-19');
        // Slot helper / voucher_post manage transactions; outer wrap can break commit semantics.
        try {
            $vid = orange_gl_voucher_immediate_post_bundle_for_slot(
                $pdo,
                [
                    'doc_kind' => 'purchase',
                    'entity_id' => $purchaseId,
                    'slot_key' => 'invoice',
                    'entry_type' => 'purchase',
                    'country_id' => 1,
                ],
                [
                    'voucher_date' => $times['voucher_date'],
                    'document_entered_at' => $times['document_entered_at'],
                    'description' => (string) ($glB['voucher_description'] ?? 'شراء'),
                    'entry_type' => 'purchase',
                    'country_id' => 1,
                ],
                $glB,
                $amount,
                isset($glB['after_post']) ? json_encode($glB['after_post'], JSON_UNESCAPED_UNICODE) : null
            );
            $out['ok'] = $vid > 0;
            $out['voucher_id'] = $vid;
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
            $out['deadlock'] = str_contains(strtolower($e->getMessage()), 'deadlock');
        }
    } elseif ($scenario === 'loyalty_simple_gl') {
        orange_d3_enable_pending_queue(false);
        $debit = (int) $meta($pdo, 'loy_debit');
        $credit = (int) $meta($pdo, 'loy_credit');
        $refId = (int) $meta($pdo, 'loy_ref_id');
        $amount = (float) $meta($pdo, 'loy_amount');
        try {
            orange_loyalty_post_simple_gl(
                $pdo,
                $debit,
                $credit,
                $amount,
                1,
                'D3 loyalty race',
                'loyalty_earn',
                'order',
                $refId,
                'loyalty-earn'
            );
            $slot = orange_gl_voucher_slot_find($pdo, 'order', $refId, 'loyalty-earn');
            $vid = (int) ($slot['journal_voucher_id'] ?? 0);
            $out['ok'] = $vid > 0;
            $out['voucher_id'] = $vid;
        } catch (Throwable $e) {
            $slot = orange_gl_voucher_slot_find($pdo, 'order', $refId, 'loyalty-earn');
            $vid = (int) ($slot['journal_voucher_id'] ?? 0);
            if ($vid > 0) {
                $out['ok'] = true;
                $out['voucher_id'] = $vid;
                $out['error'] = 'adopted_existing:' . $e->getMessage();
            } else {
                $out['error'] = $e->getMessage();
                $out['deadlock'] = str_contains(strtolower($e->getMessage()), 'deadlock');
            }
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
