<?php

declare(strict_types=1);

/**
 * FSR Batch D3 — Pending GL queue + Party Subledger behavioral tests.
 *
 * Usage: php scripts/self_test_final_review_d3_pending_subledger.php
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

function d3p_assert(bool $ok, string $label): void
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
$cleanup = $boot['cleanup'];

try {
    orange_d2_set_admin_country((int) $ids['kw_country_id'], 'kw');
    $kw = (int) $ids['kw_country_id'];
    $cash = (int) $ids['kw_acct_cash'];
    $ar = (int) $ids['kw_acct_ar'];
    $ap = (int) $ids['kw_acct_ap'];
    $cust = (int) $ids['kw_customer_id'];
    $sup = (int) $ids['kw_supplier_id'];

    $fkDebit = (string) $pdo->query(
        "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orange_gl_pending_movements'
           AND COLUMN_NAME = 'account_debit' AND REFERENCED_TABLE_NAME = 'accounts'
         LIMIT 1"
    )->fetchColumn();
    d3p_assert($fkDebit !== '', 'schema has FK on pending.account_debit → accounts.id');

    orange_d3_enable_pending_queue(true);
    d3p_assert(orange_gl_use_pending_queue($pdo), 'pending queue enabled for tests');

    $times = orange_gl_posting_times_for_country($pdo, $kw, '2026-07-18');
    $multiKey = orange_gl_pending_source_key('journal', 7001, 'd3-multi');
    $multiLines = [
        ['account_id' => $ar, 'debit' => 0.0, 'credit' => 12.0, 'memo' => 'تحصيل'],
        ['account_id' => $cash, 'debit' => 12.0, 'credit' => 0.0, 'memo' => 'صندوق'],
    ];
    $after = orange_gl_after_post_json_with_country(json_encode([
        'party_subledger' => [
            'party_kind' => 'customer',
            'party_id' => $cust,
            'debit' => 0.0,
            'credit' => 12.0,
            'ref_type' => 'customer_receipt',
            'ref_id' => 7001,
            'memo' => 'D3 receipt',
        ],
    ], JSON_UNESCAPED_UNICODE), $kw);

    // Cold-process first multi enqueue + post (PENDING-02/03; no prior voucher_post warm).
    $pidMulti = orange_gl_pending_enqueue_multi(
        $pdo,
        $multiLines,
        $multiKey,
        'D3-PEND-MULTI',
        $times['movement_at'],
        $times['voucher_date'],
        'قيد معلّق متعدد الأسطر',
        'receipt_voucher',
        $after
    );
    $stMulti = $pdo->prepare('SELECT * FROM orange_gl_pending_movements WHERE reference = ?');
    $stMulti->execute([$multiKey]);
    $multiRow = $stMulti->fetch(PDO::FETCH_ASSOC) ?: [];
    d3p_assert($pidMulti > 0 && $multiRow !== [], 'multi-line pending enqueue persists a row (PENDING-02)');
    d3p_assert((int) ($multiRow['account_debit'] ?? 0) === $cash, 'multi summary account_debit from lines');
    d3p_assert((int) ($multiRow['account_credit'] ?? 0) === $ar, 'multi summary account_credit from lines');
    d3p_assert((int) ($multiRow['account_debit'] ?? 0) > 0 && (int) ($multiRow['account_credit'] ?? 0) > 0, 'no Account ID 0');
    d3p_assert((int) ($multiRow['multi_line'] ?? 0) === 1, 'multi_line flag set');

    $pidMulti2 = orange_gl_pending_enqueue_multi(
        $pdo,
        $multiLines,
        $multiKey,
        'D3-PEND-MULTI',
        $times['movement_at'],
        $times['voucher_date'],
        'قيد معلّق متعدد الأسطر dup',
        'receipt_voucher',
        $after
    );
    $stMultiCnt = $pdo->prepare('SELECT COUNT(*) FROM orange_gl_pending_movements WHERE reference = ?');
    $stMultiCnt->execute([$multiKey]);
    d3p_assert(
        (int) $stMultiCnt->fetchColumn() === 1 && $pidMulti2 === $pidMulti,
        'duplicate multi enqueue returns existing id (no second row)'
    );

    $unbalanced = false;
    try {
        orange_gl_pending_enqueue_multi(
            $pdo,
            [
                ['account_id' => $cash, 'debit' => 5.0, 'credit' => 0.0, 'memo' => 'a'],
                ['account_id' => $ar, 'debit' => 0.0, 'credit' => 4.0, 'memo' => 'b'],
            ],
            orange_gl_pending_source_key('journal', 7009, 'unbal'),
            'unbal',
            $times['movement_at'],
            $times['voucher_date'],
            'unbalanced',
            'manual',
            orange_gl_after_post_json_with_country(null, $kw)
        );
    } catch (Throwable $e) {
        $unbalanced = str_contains($e->getMessage(), 'غير متوازن');
    }
    d3p_assert($unbalanced, 'unbalanced multi-line enqueue rejected');

    $pendSrc = (string) file_get_contents($root . '/includes/gl_pending_movements.php');
    $fnPos = strpos($pendSrc, 'function orange_gl_pending_post_by_ids');
    $fnBody = $fnPos === false ? '' : substr($pendSrc, $fnPos, 4500);
    $hookPos = strpos($fnBody, '$hookRaw');
    $applyPos = strpos($fnBody, 'orange_gl_pending_apply_country_from_hook');
    d3p_assert(
        $hookPos !== false && $applyPos !== false && $hookPos < $applyPos,
        'source: hook assigned before apply_country (PENDING-01)'
    );

    $jvBefore = (int) $pdo->query('SELECT COUNT(*) FROM journal_vouchers')->fetchColumn();
    $postRes = orange_gl_pending_post_by_ids($pdo, [$pidMulti]);
    $postedIds = is_array($postRes['posted'] ?? null) ? $postRes['posted'] : [];
    $postErrors = is_array($postRes['errors'] ?? null) ? $postRes['errors'] : [];
    echo 'NOTE  cold_multi_post=' . json_encode($postRes, JSON_UNESCAPED_UNICODE) . "\n";
    d3p_assert(
        $postErrors === [] && in_array($pidMulti, array_map('intval', $postedIds), true),
        'cold-process first pending post succeeds (PENDING-03)'
    );
    $stMulti->execute([$multiKey]);
    $multiRow2 = $stMulti->fetch(PDO::FETCH_ASSOC) ?: [];
    $jvId = (int) ($multiRow2['journal_voucher_id'] ?? 0);
    d3p_assert((string) ($multiRow2['status'] ?? '') === 'posted' && $jvId > 0, 'pending marked posted with voucher');
    $jvAfter = (int) $pdo->query('SELECT COUNT(*) FROM journal_vouchers')->fetchColumn();
    d3p_assert($jvAfter === $jvBefore + 1, 'pending-to-voucher creates exactly one journal');
    $stJv = $pdo->prepare('SELECT country_id FROM journal_vouchers WHERE id = ?');
    $stJv->execute([$jvId]);
    d3p_assert((int) $stJv->fetchColumn() === $kw, 'pending post stamps voucher country from stored hook');
    $stSubPend = $pdo->prepare(
        'SELECT COUNT(*) FROM party_subledger WHERE party_kind = ? AND party_id = ? AND voucher_id = ?'
    );
    $stSubPend->execute(['customer', $cust, $jvId]);
    d3p_assert((int) $stSubPend->fetchColumn() === 1, 'after_post party_subledger created once');
    orange_gl_pending_post_by_ids($pdo, [$pidMulti]);
    $jvEnd = (int) $pdo->query('SELECT COUNT(*) FROM journal_vouchers')->fetchColumn();
    d3p_assert($jvEnd === $jvAfter, 'repost same pending does not duplicate voucher');

    // Wrong admin country rejects post
    $pidEg = orange_gl_pending_enqueue_multi(
        $pdo,
        [
            ['account_id' => $cash, 'debit' => 3.0, 'credit' => 0.0, 'memo' => 'a'],
            ['account_id' => $ar, 'debit' => 0.0, 'credit' => 3.0, 'memo' => 'b'],
        ],
        orange_gl_pending_source_key('journal', 7011, 'kw-ctx'),
        'kw-ctx',
        $times['movement_at'],
        $times['voucher_date'],
        'kw pending',
        'manual',
        orange_gl_after_post_json_with_country(null, $kw)
    );
    orange_d2_set_admin_country((int) $ids['eg_country_id'], 'eg');
    $badCtx = orange_gl_pending_post_by_ids($pdo, [$pidEg]);
    $badErrs = is_array($badCtx['errors'] ?? null) ? $badCtx['errors'] : [];
    d3p_assert($badErrs !== [] && ($badCtx['posted'] ?? []) === [], 'mismatched admin country rejects pending post');
    orange_d2_set_admin_country($kw, 'kw');

    $simpleKey = orange_gl_pending_source_key('journal', 7002, 'd3-simple');
    $pid = orange_gl_pending_enqueue_simple($pdo, [
        'reference' => $simpleKey,
        'source_label' => 'D3-PEND-7002',
        'movement_at' => $times['movement_at'],
        'voucher_date' => $times['voucher_date'],
        'account_debit' => $cash,
        'account_credit' => $ar,
        'amount' => 9.0,
        'description' => 'قيد معلّق بسيط D3',
        'entry_type' => 'receipt_voucher',
        'after_post_json' => orange_gl_after_post_json_with_country(null, $kw),
    ]);
    d3p_assert($pid > 0, 'simple pending movement enqueued once');
    $postSimple = orange_gl_pending_post_by_ids($pdo, [$pid]);
    d3p_assert(
        in_array($pid, array_map('intval', is_array($postSimple['posted'] ?? null) ? $postSimple['posted'] : []), true),
        'simple pending posts after multi path'
    );

    orange_d3_enable_pending_queue(false);

    // Isolate subledger math from earlier pending after_post on $cust.
    $pdo->prepare('DELETE FROM party_subledger WHERE party_kind = ? AND party_id = ?')->execute(['customer', $cust]);

    $vid = orange_d3_post_manual($pdo, $kw, 'AR open', [
        ['account_id' => $ar, 'debit' => 50.0, 'credit' => 0.0, 'memo' => 'ذمة'],
        ['account_id' => (int) $ids['kw_acct_sales_credit'], 'debit' => 0.0, 'credit' => 50.0, 'memo' => 'مبيعات'],
    ], 'manual');
    orange_party_subledger_record($pdo, 'customer', $cust, $vid, 50.0, 0.0, 'order', 8001, 'D3 AR');
    d3p_assert(abs(orange_party_balance_customer($pdo, $cust) - 50.0) < 0.001, 'customer AR balance +50');

    $vidPay = orange_d3_post_manual($pdo, $kw, 'Customer receipt', [
        ['account_id' => $ar, 'debit' => 0.0, 'credit' => 20.0, 'memo' => 'سداد'],
        ['account_id' => $cash, 'debit' => 20.0, 'credit' => 0.0, 'memo' => 'صندوق'],
    ], 'customer_receipt');
    orange_party_subledger_record($pdo, 'customer', $cust, $vidPay, 0.0, 20.0, 'customer_receipt', $vidPay, 'D3 receipt');
    d3p_assert(abs(orange_party_balance_customer($pdo, $cust) - 30.0) < 0.001, 'customer receipt reduces AR by 20');

    $stSub = $pdo->prepare('SELECT COUNT(*) FROM party_subledger WHERE party_kind = ? AND party_id = ? AND voucher_id = ?');
    $stSub->execute(['customer', $cust, $vidPay]);
    d3p_assert((int) $stSub->fetchColumn() === 1, 'subledger row once for receipt voucher');

    $vidAp = orange_d3_post_manual($pdo, $kw, 'AP open', [
        ['account_id' => (int) $ids['kw_acct_inventory'], 'debit' => 40.0, 'credit' => 0.0, 'memo' => 'مخزون'],
        ['account_id' => $ap, 'debit' => 0.0, 'credit' => 40.0, 'memo' => 'ذمة مورد'],
    ], 'manual');
    orange_party_subledger_record($pdo, 'supplier', $sup, $vidAp, 0.0, 40.0, 'purchase', 9001, 'D3 AP');
    d3p_assert(abs(orange_party_balance_supplier($pdo, $sup) - 40.0) < 0.001, 'supplier AP balance +40 (credit)');

    $vidSp = orange_d3_post_manual($pdo, $kw, 'Supplier payment', [
        ['account_id' => $ap, 'debit' => 15.0, 'credit' => 0.0, 'memo' => 'سداد مورد'],
        ['account_id' => $cash, 'debit' => 0.0, 'credit' => 15.0, 'memo' => 'من الصندوق'],
    ], 'supplier_payment');
    orange_party_subledger_record($pdo, 'supplier', $sup, $vidSp, 15.0, 0.0, 'supplier_payment', $vidSp, 'D3 pay');
    d3p_assert(abs(orange_party_balance_supplier($pdo, $sup) - 25.0) < 0.001, 'supplier payment reduces AP');

    $stmtLines = orange_party_statement_lines($pdo, 'customer', $cust, $kw);
    d3p_assert(count($stmtLines) >= 2, 'customer statement has movements');

    orange_d3_enable_pending_queue(false);
    d3p_assert(!orange_gl_use_pending_queue($pdo), 'mutation-proof: pending flag off restores immediate mode');

    $jtSrc = (string) file_get_contents($root . '/includes/journal_types.php');
    $updSrc = (string) file_get_contents($root . '/includes/journal_voucher.php');
    $mergePos = strpos($jtSrc, 'function orange_journal_types_merge_canonical_defaults');
    // update_multiline ownTx sits after long validation — window must cover it (FSR-D3-YEC-01).
    $mergeBody = $mergePos === false ? '' : substr($jtSrc, $mergePos, 8000);
    $updPos = strpos($updSrc, 'function orange_voucher_update_multiline');
    $updBody = $updPos === false ? '' : substr($updSrc, $updPos, 12000);
    d3p_assert(
        (bool) preg_match('/\$ownTx\s*=\s*!\$pdo->inTransaction\(\)/', $mergeBody)
        && (bool) preg_match('/\$ownTx\s*=\s*!\$pdo->inTransaction\(\)/', $updBody)
        && str_contains($mergeBody, 'if ($ownTx)')
        && str_contains($updBody, 'if ($ownTx)'),
        'mutation-proof: journal_types merge and update_multiline use ownTx'
    );

    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    if ($failures > 0) {
        echo "RESULT=FSR_D3_PROVEN_ACCOUNTING_GAPS_FOUND\n";
        exit(1);
    }
    echo "RESULT=FSR_D3_PENDING_SUBLEDGER_OK\n";
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
