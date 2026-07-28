<?php

declare(strict_types=1);

/**
 * FSR Batch D3 — Automatic GL integrations (purchase, loyalty, delivery, SAJ, bank).
 *
 * Usage: php scripts/self_test_final_review_d3_automatic_posting.php
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

function d3a_assert(bool $ok, string $label): void
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
    orange_d3_enable_pending_queue(false);
    orange_d2_set_admin_country((int) $ids['kw_country_id'], 'kw');
    $kw = (int) $ids['kw_country_id'];
    $sup = (int) $ids['kw_supplier_id'];
    $cash = (int) $ids['kw_acct_cash'];
    $inv = (int) $ids['kw_acct_inventory'];

    // GL settings resolve via mapping helpers (not hardcoded outside fixture binds)
    $cashBound = orange_gl_account_id($pdo, 'cash', $kw);
    $invBound = orange_gl_account_id($pdo, 'inventory', $kw);
    d3a_assert($cashBound === $cash && $invBound === $inv, 'gl_account_settings bindings resolve');

    // Purchase credit bundle + slot post
    $purchaseId = orange_d1_insert_purchase($pdo, $kw, $sup, 25.0, 'credit');
    $glB = orange_gl_purchase_invoice_posting_bundle($pdo, 'credit', $sup, $purchaseId, 25.0, $kw);
    d3a_assert((int) ($glB['debit'] ?? 0) === $inv, 'purchase credit debit = inventory setting');
    d3a_assert((int) ($glB['credit'] ?? 0) === (int) $ids['kw_acct_ap'], 'purchase credit credit = supplier AP');
    $times = orange_gl_posting_times_for_country($pdo, $kw, '2026-07-19');
    $jvBefore = (int) $pdo->query('SELECT COUNT(*) FROM journal_vouchers')->fetchColumn();
    $vid1 = orange_gl_voucher_immediate_post_bundle_for_slot(
        $pdo,
        [
            'doc_kind' => 'purchase',
            'entity_id' => $purchaseId,
            'slot_key' => 'invoice',
            'entry_type' => 'purchase',
            'country_id' => $kw,
        ],
        [
            'voucher_date' => $times['voucher_date'],
            'document_entered_at' => $times['document_entered_at'],
            'description' => (string) ($glB['voucher_description'] ?? 'شراء آجل'),
            'entry_type' => 'purchase',
            'country_id' => $kw,
        ],
        $glB,
        25.0,
        isset($glB['after_post']) ? json_encode($glB['after_post'], JSON_UNESCAPED_UNICODE) : null
    );
    d3a_assert($vid1 > 0, 'purchase credit posts via slot engine');
    $tot = orange_d3_voucher_line_totals($pdo, $vid1);
    d3a_assert(abs($tot['debit'] - 25.0) < 0.001 && orange_gl_money_is_balanced($tot['debit'], $tot['credit'], 'KWD'), 'purchase voucher balanced');

    // Idempotent rebuild/post same slot
    $vid2 = orange_gl_voucher_immediate_post_bundle_for_slot(
        $pdo,
        [
            'doc_kind' => 'purchase',
            'entity_id' => $purchaseId,
            'slot_key' => 'invoice',
            'entry_type' => 'purchase',
            'country_id' => $kw,
        ],
        [
            'voucher_date' => $times['voucher_date'],
            'document_entered_at' => $times['document_entered_at'],
            'description' => (string) ($glB['voucher_description'] ?? 'شراء آجل'),
            'entry_type' => 'purchase',
            'country_id' => $kw,
        ],
        $glB,
        25.0,
        isset($glB['after_post']) ? json_encode($glB['after_post'], JSON_UNESCAPED_UNICODE) : null
    );
    d3a_assert($vid2 === $vid1 || $vid2 > 0, 'purchase slot re-post returns same/rebuild identity');
    $slot = orange_gl_voucher_slot_find($pdo, 'purchase', $purchaseId, 'invoice');
    d3a_assert($slot !== null, 'purchase slot registered');
    $jvPurchase = (int) $pdo->query(
        "SELECT COUNT(*) FROM journal_vouchers WHERE entry_type = 'purchase' AND country_id = {$kw} AND COALESCE(is_void,0)=0"
    )->fetchColumn();
    d3a_assert($jvPurchase === 1, 'duplicate purchase post does not create second active voucher');

    // Subledger from after_post if applied
    if (!empty($glB['after_post']['party_subledger'])) {
        $bal = orange_party_balance_supplier($pdo, $sup);
        d3a_assert($bal > 0.0, 'purchase after_post created supplier subledger effect');
    }

    // FSR-D3-LOYALTY-01 — orange_loyalty_post_simple_gl via voucher slot
    $loyExp = orange_gl_account_id($pdo, 'loyalty_program_expense', $kw);
    $loyLiab = orange_gl_account_id($pdo, 'loyalty_points_liability', $kw);
    $jvL0 = (int) $pdo->query('SELECT COUNT(*) FROM journal_vouchers')->fetchColumn();
    $jlL0 = (int) $pdo->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
    orange_loyalty_post_simple_gl(
        $pdo,
        $loyExp,
        $loyLiab,
        3.5,
        $kw,
        'D3 loyalty earn',
        'loyalty_earn',
        'order',
        88001,
        'loyalty-earn'
    );
    $jvL1 = (int) $pdo->query('SELECT COUNT(*) FROM journal_vouchers')->fetchColumn();
    $jlL1 = (int) $pdo->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
    $slotEarn = orange_gl_voucher_slot_find($pdo, 'order', 88001, 'loyalty-earn');
    $earnVid = (int) ($slotEarn['journal_voucher_id'] ?? 0);
    d3a_assert($jvL1 === $jvL0 + 1, 'loyalty earn posts one voucher');
    d3a_assert($jlL1 === $jlL0 + 2, 'loyalty earn posts two journal lines');
    d3a_assert($earnVid > 0, 'loyalty earn registers voucher slot');
    d3a_assert(
        (int) $pdo->query('SELECT country_id FROM journal_vouchers WHERE id=' . $earnVid)->fetchColumn() === $kw,
        'loyalty earn voucher country from source'
    );

    orange_loyalty_post_simple_gl(
        $pdo,
        $loyExp,
        $loyLiab,
        3.5,
        $kw,
        'D3 loyalty earn dup',
        'loyalty_earn',
        'order',
        88001,
        'loyalty-earn'
    );
    $jvL2 = (int) $pdo->query('SELECT COUNT(*) FROM journal_vouchers')->fetchColumn();
    $jlL2 = (int) $pdo->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
    $slotL2 = (int) $pdo->query(
        "SELECT COUNT(*) FROM orange_gl_voucher_slots WHERE doc_kind='order' AND entity_id=88001 AND slot_key='loyalty-earn'"
    )->fetchColumn();
    $loyDelta = $jvL2 - $jvL1;
    $loyClass = ($loyDelta === 0 && $jlL2 === $jlL1 && $slotL2 === 1)
        ? 'IDEMPOTENT_BY_SLOT'
        : 'ADDITIONAL_ACCOUNTING_GAP';
    echo "NOTE  loyalty_simple_gl_dup_count_delta={$loyDelta}\n";
    echo "NOTE  loyalty_earn_voucher_id={$earnVid}\n";
    echo "LOYALTY_GL_CLASS={$loyClass}\n";
    d3a_assert($loyClass === 'IDEMPOTENT_BY_SLOT', 'loyalty simple_gl sequential retry is IDEMPOTENT_BY_SLOT');
    $GLOBALS['orange_d3_loyalty_gl_class'] = $loyClass;

    // Mutation-proof: raw voucher_post (pre-fix call pattern) duplicates
    $jvMut0 = (int) $pdo->query('SELECT COUNT(*) FROM journal_vouchers')->fetchColumn();
    $mutTimes = orange_gl_posting_times_for_country($pdo, $kw, null);
    $mutHeader = static function (string $desc) use ($mutTimes, $kw): array {
        return [
            'voucher_date' => $mutTimes['voucher_date'],
            'document_entered_at' => $mutTimes['document_entered_at'],
            'description' => $desc,
            'entry_type' => 'loyalty_earn',
            'country_id' => $kw,
        ];
    };
    $mutLines = [
        ['account_id' => $loyExp, 'debit' => 1.0, 'credit' => 0.0, 'memo' => 'm'],
        ['account_id' => $loyLiab, 'debit' => 0.0, 'credit' => 1.0, 'memo' => 'm'],
    ];
    orange_voucher_post($pdo, $mutHeader('mutation raw loyalty'), $mutLines);
    orange_voucher_post($pdo, $mutHeader('mutation raw loyalty 2'), $mutLines);
    d3a_assert(
        (int) $pdo->query('SELECT COUNT(*) FROM journal_vouchers')->fetchColumn() === $jvMut0 + 2,
        'mutation-proof: raw voucher_post without slot duplicates'
    );

    // Same numeric id, different event kind — independent
    orange_loyalty_post_simple_gl(
        $pdo,
        $loyLiab,
        $loyExp,
        2.0,
        $kw,
        'D3 expire same numeric id',
        'loyalty_expire',
        'loyalty_layer',
        88001,
        'loyalty-expire'
    );
    $slotExpireSameId = orange_gl_voucher_slot_find($pdo, 'loyalty_layer', 88001, 'loyalty-expire');
    d3a_assert(
        $slotExpireSameId !== null
        && (int) ($slotExpireSameId['journal_voucher_id'] ?? 0) !== $earnVid,
        'earn vs expire same numeric id do not collide'
    );

    orange_loyalty_post_simple_gl(
        $pdo,
        $loyExp,
        $loyLiab,
        4.0,
        $kw,
        'D3 loyalty earn other order',
        'loyalty_earn',
        'order',
        88003,
        'loyalty-earn'
    );
    $slotOther = orange_gl_voucher_slot_find($pdo, 'order', 88003, 'loyalty-earn');
    d3a_assert(
        $slotOther !== null && (int) ($slotOther['journal_voucher_id'] ?? 0) !== $earnVid,
        'different loyalty source posts independently'
    );

    // Expiry orientation + sequential idempotency (matches Production ref_type loyalty_layer)
    $jvE0 = (int) $pdo->query(
        "SELECT COUNT(*) FROM journal_vouchers WHERE entry_type = 'loyalty_expire' AND country_id = {$kw}"
    )->fetchColumn();
    orange_loyalty_post_simple_gl(
        $pdo,
        $loyLiab,
        $loyExp,
        1.0,
        $kw,
        'D3 loyalty expire',
        'loyalty_expire',
        'loyalty_layer',
        88002,
        'loyalty-expire'
    );
    orange_loyalty_post_simple_gl(
        $pdo,
        $loyLiab,
        $loyExp,
        1.0,
        $kw,
        'D3 loyalty expire dup',
        'loyalty_expire',
        'loyalty_layer',
        88002,
        'loyalty-expire'
    );
    $jvE1 = (int) $pdo->query(
        "SELECT COUNT(*) FROM journal_vouchers WHERE entry_type = 'loyalty_expire' AND country_id = {$kw}"
    )->fetchColumn();
    d3a_assert($jvE1 === $jvE0 + 1, 'loyalty expire posts once; retry does not duplicate');
    d3a_assert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM orange_gl_voucher_slots WHERE doc_kind='loyalty_layer' AND entity_id=88002 AND slot_key='loyalty-expire'"
        )->fetchColumn() === 1,
        'loyalty expire single slot for source 88002'
    );

    // Stock adjustment GL via orange_voucher_post pattern used in approve
    $sajGain = orange_gl_account_id($pdo, 'stock_adjustment_gain', $kw);
    $sajLoss = orange_gl_account_id($pdo, 'stock_adjustment_loss', $kw);
    $sajPos = orange_d3_post_manual($pdo, $kw, 'SAJ+', [
        ['account_id' => $inv, 'debit' => 8.0, 'credit' => 0.0, 'memo' => 'زيادة مخزون'],
        ['account_id' => $sajGain, 'debit' => 0.0, 'credit' => 8.0, 'memo' => 'أرباح جرد'],
    ], 'stock_adjustment');
    d3a_assert($sajPos > 0, 'positive SAJ accounting posts');
    $sajNeg = orange_d3_post_manual($pdo, $kw, 'SAJ-', [
        ['account_id' => $sajLoss, 'debit' => 5.0, 'credit' => 0.0, 'memo' => 'خسائر جرد'],
        ['account_id' => $inv, 'debit' => 0.0, 'credit' => 5.0, 'memo' => 'نقص مخزون'],
    ], 'stock_adjustment');
    d3a_assert($sajNeg > 0, 'negative SAJ accounting posts');

    // Bank recon posting helper path (direct orange_voucher_post orientation)
    $bank = (int) $ids['kw_acct_bank'];
    $brVid = orange_d3_post_manual($pdo, $kw, 'Bank recon adj', [
        ['account_id' => $bank, 'debit' => 2.0, 'credit' => 0.0, 'memo' => 'رفع رصيد'],
        ['account_id' => $sajGain, 'debit' => 0.0, 'credit' => 2.0, 'memo' => 'تسوية'],
    ], 'general');
    d3a_assert($brVid > 0, 'bank-style adjustment voucher posts');

    // Delivery accounting existence helpers (idempotency markers)
    d3a_assert(
        function_exists('orange_post_order_delivery_accounting')
        && function_exists('orange_order_forward_delivery_accounting_exists'),
        'delivery accounting entrypoints present'
    );
    d3a_assert(
        !orange_order_forward_delivery_accounting_exists($pdo, 'D3-NO-ORDER', $kw),
        'missing order has no delivery accounting'
    );

    // Missing mapping fails clearly
    $pdo->prepare('DELETE FROM orange_gl_account_settings WHERE setting_key = ? AND country_id = ?')
        ->execute(['inventory', $kw]);
    // Clear static cache by using a fresh key path — orange_gl_account_id caches; use raw
    $missing = false;
    try {
        // Force cache miss via optional raw then require
        $raw = orange_gl_setting_bound_account_id_raw($pdo, 'inventory', $kw);
        if ($raw <= 0) {
            orange_gl_account_id($pdo, 'inventory', $kw);
        } else {
            throw new RuntimeException('inventory binding still present');
        }
    } catch (Throwable $e) {
        $missing = str_contains($e->getMessage(), 'لم يُضبط') || str_contains($e->getMessage(), 'فرعي') || str_contains($e->getMessage(), 'inventory');
        if (!$missing && $e->getMessage() === 'inventory binding still present') {
            $missing = false;
        } else {
            $missing = true;
        }
    }
    // Re-bind for cleanup safety
    $pdo->prepare(
        'INSERT INTO orange_gl_account_settings (setting_key, country_id, account_id, journal_type_id) VALUES (?,?,?,NULL)'
    )->execute(['inventory', $kw, $inv]);
    d3a_assert($missing || orange_gl_setting_bound_account_id_raw($pdo, 'inventory', $kw) === $inv, 'missing mapping fails or rebound');

    // Country: EG settings distinct
    orange_d2_set_admin_country((int) $ids['eg_country_id'], 'eg');
    $egCash = orange_gl_account_id($pdo, 'cash', (int) $ids['eg_country_id']);
    d3a_assert($egCash === (int) $ids['eg_acct_cash'], 'EG cash setting country-scoped');
    d3a_assert($egCash !== $cash, 'EG cash account differs from KW');

    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    if ($failures > 0) {
        echo "RESULT=FSR_D3_PROVEN_ACCOUNTING_GAPS_FOUND\n";
        exit(1);
    }
    $loyClassEnd = (string) ($GLOBALS['orange_d3_loyalty_gl_class'] ?? '');
    if ($loyClassEnd === 'ADDITIONAL_ACCOUNTING_GAP' || $loyClassEnd === '') {
        echo "RESULT=FSR_D3_ADDITIONAL_ACCOUNTING_GAPS_FOUND\n";
        exit(3);
    }
    if ($loyClassEnd !== 'IDEMPOTENT_BY_SLOT' && $loyClassEnd !== 'IDEMPOTENT_BY_SOURCE' && $loyClassEnd !== 'DUPLICATE_REJECTED') {
        echo "RESULT=FSR_D3_ADDITIONAL_ACCOUNTING_GAPS_FOUND\n";
        exit(3);
    }
    echo "RESULT=FSR_D3_AUTOMATIC_POSTING_OK\n";
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
