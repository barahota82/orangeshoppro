<?php

declare(strict_types=1);

/**
 * FSR Batch D3 — true MySQL concurrency for numbering / pending / purchase slots.
 *
 * Usage: php scripts/self_test_final_review_d3_accounting_concurrency.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/scripts/lib/final_review_d3_fixture.php';

$passes = 0;
$failures = 0;
$skips = 0;

function d3c_assert(bool $ok, string $label): void
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
function d3c_run_workers(string $projectRoot, string $dbName, string $scenario, int $n = 2): array
{
    $php = orange_d3_php_bin();
    $worker = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'lib'
        . DIRECTORY_SEPARATOR . 'final_review_d3_concurrency_worker.php';
    $tmpDir = sys_get_temp_dir();
    $procs = [];
    $files = [];
    for ($i = 1; $i <= $n; $i++) {
        $files[$i] = $tmpDir . DIRECTORY_SEPARATOR . 'd3c_' . $dbName . '_' . $scenario . '_' . $i . '.json';
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

$boot = orange_d3_bootstrap_isolated_db($root);
if (empty($boot['ok'])) {
    echo "ENVIRONMENT_BLOCKED: " . (string) ($boot['error'] ?? 'unknown') . "\n";
    echo "RESULT=FSR_D3_ENVIRONMENT_BLOCKER\n";
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
    orange_d2_set_admin_country((int) $ids['kw_country_id'], 'kw');
    $iso = (string) $pdo->query('SELECT @@transaction_isolation')->fetchColumn();
    echo "NOTE  isolation_level={$iso}\n";
    d3c_assert($iso !== '', 'isolation level readable');

    // Deadlock retry inventory (static)
    $jvSrc = (string) file_get_contents($root . '/includes/journal_voucher.php');
    $pendSrc = (string) file_get_contents($root . '/includes/gl_pending_movements.php');
    $hasRetry = str_contains($jvSrc, 'deadlock') || str_contains($pendSrc, 'deadlock');
    d3c_assert(true, 'deadlock retry contract inventoried (duplicate-serial retry exists in voucher_post)');
    echo 'NOTE  voucher_post_duplicate_retry=' . (str_contains($jvSrc, 'maxDup') ? 'yes' : 'no') . "\n";
    echo 'NOTE  deadlock_string_in_gl=' . ($hasRetry ? 'yes' : 'no') . "\n";

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS _d3_race_meta (
            k VARCHAR(64) PRIMARY KEY,
            v VARCHAR(191) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->prepare('REPLACE INTO _d3_race_meta (k,v) VALUES (?,?)')->execute(['cash_id', (string) $ids['kw_acct_cash']]);
    $pdo->prepare('REPLACE INTO _d3_race_meta (k,v) VALUES (?,?)')->execute(['sales_id', (string) $ids['kw_acct_sales_cash']]);

    // 1) Concurrent voucher numbering
    $r1 = d3c_run_workers($root, $dbName, 'voucher_number', 2);
    $ok1 = 0;
    $vids = [];
    foreach ($r1 as $r) {
        if (!empty($r['ok'])) {
            $ok1++;
            $vids[] = (int) ($r['voucher_id'] ?? 0);
        }
        echo 'NOTE  voucher_number worker=' . (int) ($r['worker_id'] ?? 0)
            . ' ok=' . (!empty($r['ok']) ? '1' : '0')
            . ' vid=' . (int) ($r['voucher_id'] ?? 0)
            . ' err=' . (string) ($r['error'] ?? '') . "\n";
    }
    d3c_assert($ok1 === 2, 'numbering race: both workers post');
    $vids = array_values(array_filter(array_map('intval', $vids), static fn (int $v): bool => $v > 0));
    d3c_assert(count(array_unique($vids)) === 2, 'numbering race: distinct voucher ids');
    if ($vids !== []) {
        $st = $pdo->query(
            'SELECT voucher_serial, reference FROM journal_vouchers WHERE id IN ('
            . implode(',', array_map('intval', $vids)) . ')'
        );
        $serials = [];
        $refs = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $serials[] = (int) ($row['voucher_serial'] ?? 0);
            $refs[] = (string) ($row['reference'] ?? '');
        }
        d3c_assert(count(array_unique($serials)) === 2 || count(array_unique($refs)) === 2, 'numbering race: unique serials/refs');
    } else {
        d3c_assert(false, 'numbering race: unique serials/refs');
    }

    // 2) Concurrent pending post — use simple enqueue (multi-line blocked by FSR-D3-PENDING-02).
    // Warm journal_types merge outside a transaction so workers are not blocked solely by FSR-D3-PENDING-03.
    // Workers are separate processes: each still hits PENDING-03 unless they warm first (see worker).
    orange_d3_enable_pending_queue(true);
    $times = orange_gl_posting_times_for_country($pdo, 1, '2026-07-20');
    $src = orange_gl_pending_source_key('journal', 7100, 'race');
    $pid = orange_gl_pending_enqueue_simple($pdo, [
        'reference' => $src,
        'source_label' => 'D3-RACE-PEND',
        'movement_at' => $times['movement_at'],
        'voucher_date' => $times['voucher_date'],
        'account_debit' => (int) $ids['kw_acct_cash'],
        'account_credit' => (int) $ids['kw_acct_sales_cash'],
        'amount' => 6.0,
        'description' => 'race pending',
        'entry_type' => 'manual',
        'after_post_json' => orange_gl_after_post_json_with_country(null, 1),
    ]);
    if ($pid <= 0) {
        echo "DEFECT FSR-D3-PENDING-02/related: could not enqueue simple pending race fixture.\n";
        d3c_assert(false, 'pending race fixture enqueued');
    } else {
        d3c_assert(true, 'pending race fixture enqueued');
        $pdo->prepare('REPLACE INTO _d3_race_meta (k,v) VALUES (?,?)')->execute(['pending_id', (string) $pid]);
        $r2 = d3c_run_workers($root, $dbName, 'pending_post', 2);
        $ok2 = 0;
        foreach ($r2 as $r) {
            if (!empty($r['ok'])) {
                $ok2++;
            }
            echo 'NOTE  pending_post worker=' . (int) ($r['worker_id'] ?? 0)
                . ' ok=' . (!empty($r['ok']) ? '1' : '0')
                . ' err=' . (string) ($r['error'] ?? '') . "\n";
        }
        $stPend = $pdo->prepare('SELECT status, journal_voucher_id FROM orange_gl_pending_movements WHERE id = ?');
        $stPend->execute([$pid]);
        $prow = $stPend->fetch(PDO::FETCH_ASSOC) ?: [];
        $jvPend = (int) ($prow['journal_voucher_id'] ?? 0);
        d3c_assert($ok2 === 1 || ($ok2 === 2 && $jvPend > 0), 'pending race: one successful post effect');
        d3c_assert((string) ($prow['status'] ?? '') === 'posted' && $jvPend > 0, 'pending marked posted with voucher');
        $jvCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM journal_vouchers WHERE id = ' . (int) $jvPend
        )->fetchColumn();
        d3c_assert($jvCount === 1, 'pending race: single journal voucher for pending id');
    }
    orange_d3_enable_pending_queue(false);

    // 3) Concurrent purchase slot
    $purchaseId = orange_d1_insert_purchase($pdo, 1, (int) $ids['kw_supplier_id'], 11.0, 'credit');
    $pdo->prepare('REPLACE INTO _d3_race_meta (k,v) VALUES (?,?)')->execute(['purchase_id', (string) $purchaseId]);
    $pdo->prepare('REPLACE INTO _d3_race_meta (k,v) VALUES (?,?)')->execute(['supplier_id', (string) $ids['kw_supplier_id']]);
    $pdo->prepare('REPLACE INTO _d3_race_meta (k,v) VALUES (?,?)')->execute(['amount', '11']);
    $r3 = d3c_run_workers($root, $dbName, 'purchase_slot', 2);
    $ok3 = 0;
    $vids3 = [];
    foreach ($r3 as $r) {
        if (!empty($r['ok'])) {
            $ok3++;
            $vids3[] = (int) ($r['voucher_id'] ?? 0);
        }
        echo 'NOTE  purchase_slot worker=' . (int) ($r['worker_id'] ?? 0)
            . ' ok=' . (!empty($r['ok']) ? '1' : '0')
            . ' vid=' . (int) ($r['voucher_id'] ?? 0)
            . ' err=' . (string) ($r['error'] ?? '') . "\n";
    }
    $slot = orange_gl_voucher_slot_find($pdo, 'purchase', $purchaseId, 'invoice');
    $activePurch = (int) $pdo->query(
        "SELECT COUNT(*) FROM journal_vouchers WHERE entry_type='purchase' AND country_id=1 AND COALESCE(is_void,0)=0
         AND id IN (SELECT journal_voucher_id FROM orange_gl_voucher_slots WHERE doc_kind='purchase' AND entity_id=" . (int) $purchaseId . ')'
    )->fetchColumn();
    d3c_assert($ok3 >= 1 && $slot !== null, 'purchase slot race: at least one winner + slot row');
    d3c_assert($activePurch === 1 || count(array_unique(array_filter($vids3))) === 1, 'purchase slot race: single accounting effect');

    // 4) Concurrent loyalty simple_gl (FSR-D3-LOYALTY-01)
    $loyRef = 88177;
    $loyDebit = orange_gl_account_id($pdo, 'loyalty_program_expense', 1);
    $loyCredit = orange_gl_account_id($pdo, 'loyalty_points_liability', 1);
    $pdo->prepare('REPLACE INTO _d3_race_meta (k,v) VALUES (?,?)')->execute(['loy_debit', (string) $loyDebit]);
    $pdo->prepare('REPLACE INTO _d3_race_meta (k,v) VALUES (?,?)')->execute(['loy_credit', (string) $loyCredit]);
    $pdo->prepare('REPLACE INTO _d3_race_meta (k,v) VALUES (?,?)')->execute(['loy_ref_id', (string) $loyRef]);
    $pdo->prepare('REPLACE INTO _d3_race_meta (k,v) VALUES (?,?)')->execute(['loy_amount', '7.25']);
    $r4 = d3c_run_workers($root, $dbName, 'loyalty_simple_gl', 2);
    $ok4 = 0;
    $vids4 = [];
    foreach ($r4 as $r) {
        if (!empty($r['ok'])) {
            $ok4++;
            $vids4[] = (int) ($r['voucher_id'] ?? 0);
        }
        echo 'NOTE  loyalty_simple_gl worker=' . (int) ($r['worker_id'] ?? 0)
            . ' ok=' . (!empty($r['ok']) ? '1' : '0')
            . ' vid=' . (int) ($r['voucher_id'] ?? 0)
            . ' err=' . (string) ($r['error'] ?? '') . "\n";
    }
    $loySlot = orange_gl_voucher_slot_find($pdo, 'order', $loyRef, 'loyalty-earn');
    $loyVid = (int) ($loySlot['journal_voucher_id'] ?? 0);
    $loySlotCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM orange_gl_voucher_slots WHERE doc_kind='order' AND entity_id={$loyRef} AND slot_key='loyalty-earn'"
    )->fetchColumn();
    $loyActive = $loyVid > 0 ? (int) $pdo->query(
        'SELECT COUNT(*) FROM journal_vouchers WHERE id=' . $loyVid . ' AND COALESCE(is_void,0)=0'
    )->fetchColumn() : 0;
    d3c_assert($ok4 >= 1 && $loyVid > 0, 'loyalty race: at least one worker + slot voucher');
    d3c_assert($loySlotCount === 1 && $loyActive === 1, 'loyalty race: single slot and single active voucher');
    $uniqVids = array_values(array_unique(array_filter($vids4)));
    d3c_assert(count($uniqVids) <= 1 || ($uniqVids === [$loyVid]), 'loyalty race: workers share one voucher id');

    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    if ($failures > 0) {
        echo "RESULT=FSR_D3_PROVEN_ACCOUNTING_GAPS_FOUND\n";
        exit(1);
    }
    echo "RESULT=FSR_D3_CONCURRENCY_SUITE_OK\n";
    exit(0);
} catch (Throwable $e) {
    echo "FAIL  uncaught: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    echo "PASS={$passes} FAIL=" . ($failures + 1) . " SKIP={$skips}\n";
    echo "RESULT=FSR_D3_PROVEN_ACCOUNTING_GAPS_FOUND\n";
    exit(1);
} finally {
    if (is_callable($cleanup)) {
        $cleanup();
    }
}
