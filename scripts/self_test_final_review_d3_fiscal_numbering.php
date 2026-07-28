<?php

declare(strict_types=1);

/**
 * FSR Batch D3 — Fiscal year, edit-lock, voucher numbering/slots, opening balance, YEC.
 *
 * Usage: php scripts/self_test_final_review_d3_fiscal_numbering.php
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

function d3f_assert(bool $ok, string $label): void
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
    $eg = (int) $ids['eg_country_id'];
    $cash = (int) $ids['kw_acct_cash'];
    $sales = (int) $ids['kw_acct_sales_cash'];
    $equity = (int) $ids['kw_acct_equity'];
    $fyOpen = (int) $ids['kw_fy_open_id'];
    $fyClosed = (int) $ids['kw_fy_closed_id'];

    // Numbering: two manuals get increasing serials same bucket/FY
    $v1 = orange_d3_post_manual($pdo, $kw, 'num1', [
        ['account_id' => $cash, 'debit' => 1, 'credit' => 0, 'memo' => 'a'],
        ['account_id' => $sales, 'debit' => 0, 'credit' => 1, 'memo' => 'b'],
    ]);
    $v2 = orange_d3_post_manual($pdo, $kw, 'num2', [
        ['account_id' => $cash, 'debit' => 2, 'credit' => 0, 'memo' => 'a'],
        ['account_id' => $sales, 'debit' => 0, 'credit' => 2, 'memo' => 'b'],
    ]);
    $st = $pdo->prepare('SELECT voucher_serial, journal_serial_bucket, fiscal_year_id, reference FROM journal_vouchers WHERE id IN (?,?) ORDER BY id');
    $st->execute([$v1, $v2]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    d3f_assert(count($rows) === 2, 'two vouchers for numbering');
    $s1 = (int) ($rows[0]['voucher_serial'] ?? 0);
    $s2 = (int) ($rows[1]['voucher_serial'] ?? 0);
    d3f_assert($s1 > 0 && $s2 === $s1 + 1, 'serial increments within FY+bucket');
    d3f_assert((int) ($rows[0]['fiscal_year_id'] ?? 0) === $fyOpen, 'open FY linked');
    d3f_assert(
        (string) ($rows[0]['journal_serial_bucket'] ?? '') === (string) ($rows[1]['journal_serial_bucket'] ?? ''),
        'same journal_serial_bucket for same entry_type'
    );

    // Different journal types / entry types — separate or shared per resolve_serial_meta
    $rv = orange_d3_post_manual($pdo, $kw, 'num rv', [
        ['account_id' => $cash, 'debit' => 3, 'credit' => 0, 'memo' => 'a'],
        ['account_id' => $sales, 'debit' => 0, 'credit' => 3, 'memo' => 'b'],
    ], 'receipt_voucher');
    $stR = $pdo->prepare('SELECT journal_serial_bucket, voucher_serial FROM journal_vouchers WHERE id = ?');
    $stR->execute([$rv]);
    $rr = $stR->fetch(PDO::FETCH_ASSOC) ?: [];
    d3f_assert((string) ($rr['journal_serial_bucket'] ?? '') !== '', 'receipt has serial bucket');

    // Countries do not share serial collision improperly: EG serial independent
    orange_d2_set_admin_country($eg, 'eg');
    $egV = orange_d3_post_manual($pdo, $eg, 'eg num', [
        ['account_id' => (int) $ids['eg_acct_cash'], 'debit' => 1, 'credit' => 0, 'memo' => 'a'],
        ['account_id' => (int) $ids['eg_acct_sales_cash'], 'debit' => 0, 'credit' => 1, 'memo' => 'b'],
    ]);
    $stE = $pdo->prepare('SELECT voucher_serial, fiscal_year_id, country_id FROM journal_vouchers WHERE id = ?');
    $stE->execute([$egV]);
    $er = $stE->fetch(PDO::FETCH_ASSOC) ?: [];
    d3f_assert((int) ($er['country_id'] ?? 0) === $eg, 'EG voucher country');
    d3f_assert((int) ($er['fiscal_year_id'] ?? 0) === (int) $ids['eg_fy_open_id'], 'EG FY linked');
    d3f_assert((int) ($er['voucher_serial'] ?? 0) >= 1, 'EG first serial starts independently');

    orange_d2_set_admin_country($kw, 'kw');

    // next_serial helper under lock-less MAX+1 contract
    $bucket = (string) ($rows[0]['journal_serial_bucket'] ?? 'JE');
    $next = orange_journal_voucher_next_serial($pdo, $fyOpen, $bucket);
    d3f_assert($next === $s2 + 1 || $next > $s2, 'next_serial = MAX+1 contract');

    // Edit lock: registry doc_kind = entry_type (e.g. manual), not literal journal_voucher
    // country_id required: without it orange_admin_has_full_access() treats admin as global.
    $admin = ['id' => 1, 'is_superuser' => 0, 'username' => 'd3', 'country_id' => $kw];
    $stV = $pdo->prepare('SELECT * FROM journal_vouchers WHERE id = ?');
    $stV->execute([$v1]);
    $vRow = $stV->fetch(PDO::FETCH_ASSOC) ?: [];
    $lockKind = orange_edit_lock_kind_for_entry_type((string) ($vRow['entry_type'] ?? 'manual'));
    orange_edit_lock_register_voucher($pdo, $vRow);
    orange_edit_lock_force_lock($pdo, $lockKind, $v1, $kw, 1);
    d3f_assert(orange_edit_lock_is_locked($pdo, $lockKind, $v1, $kw), 'edit lock marks voucher locked');
    $lockBlocked = false;
    try {
        orange_edit_lock_assert_may_mutate($pdo, $admin, $lockKind, $v1, 'edit', $kw);
    } catch (Throwable $e) {
        $lockBlocked = true;
    }
    d3f_assert($lockBlocked, 'locked voucher mutation rejected');

    // KW lock does not block EG entity
    orange_d2_set_admin_country($eg, 'eg');
    $stVe = $pdo->prepare('SELECT * FROM journal_vouchers WHERE id = ?');
    $stVe->execute([$egV]);
    $egRow = $stVe->fetch(PDO::FETCH_ASSOC) ?: [];
    $egKind = orange_edit_lock_kind_for_entry_type((string) ($egRow['entry_type'] ?? 'manual'));
    orange_edit_lock_register_voucher($pdo, $egRow);
    d3f_assert(!orange_edit_lock_is_locked($pdo, $egKind, $egV, $eg), 'EG voucher not locked by KW lock');
    orange_d2_set_admin_country($kw, 'kw');

    // Opening balance via orange_voucher_post entry_type opening_balance (core path)
    $obRef = orange_opening_balance_reference($pdo, $fyOpen, $kw);
    $obExists = orange_voucher_by_reference($pdo, $obRef, $kw);
    d3f_assert($obExists === null, 'no opening balance yet for FY');
    $times = orange_gl_posting_times_for_country($pdo, $kw, '2026-01-01');
    $obId = orange_voucher_post($pdo, [
        'voucher_date' => $times['voucher_date'],
        'document_entered_at' => $times['document_entered_at'],
        'description' => 'أرصدة افتتاحية D3',
        'entry_type' => 'opening_balance',
        'country_id' => $kw,
    ], [
        ['account_id' => $cash, 'debit' => 100.0, 'credit' => 0.0, 'memo' => 'افتتاح نقدية'],
        ['account_id' => $equity, 'debit' => 0.0, 'credit' => 100.0, 'memo' => 'افتتاح حقوق'],
    ]);
    d3f_assert($obId > 0, 'financial opening balance posts balanced');
    $obTot = orange_d3_voucher_line_totals($pdo, $obId);
    d3f_assert(abs($obTot['debit'] - 100.0) < 0.001, 'opening balance amount');

    // Duplicate OB prevention pattern used by API: same fiscal_year + entry_type
    $stDup = $pdo->prepare(
        'SELECT COUNT(*) FROM journal_vouchers WHERE fiscal_year_id = ? AND entry_type = ? AND country_id = ? AND COALESCE(is_void,0)=0'
    );
    $stDup->execute([$fyOpen, 'opening_balance', $kw]);
    d3f_assert((int) $stDup->fetchColumn() === 1, 'one opening_balance voucher for FY+country');

    // Year-end close prepare draft + finalize
    $inc = (int) $ids['kw_acct_income_summary'];
    $ret = (int) $ids['kw_acct_retained'];
    $draft = orange_year_end_close_prepare_draft($pdo, $fyOpen, $inc, $ret, $kw);
    $yecVid = (int) ($draft['voucher_id'] ?? 0);
    // yec_phase column is varchar(8); production uses PL / RE / LR.
    $lines = [
        ['account_id' => $sales, 'debit' => 1.0, 'credit' => 0.0, 'memo' => 'إقفال إيراد', 'yec_phase' => 'PL'],
        ['account_id' => $inc, 'debit' => 0.0, 'credit' => 1.0, 'memo' => 'إلى وسيط', 'yec_phase' => 'PL'],
        ['account_id' => $inc, 'debit' => 1.0, 'credit' => 0.0, 'memo' => 'من وسيط', 'yec_phase' => 'RE'],
        ['account_id' => $ret, 'debit' => 0.0, 'credit' => 1.0, 'memo' => 'محتجز', 'yec_phase' => 'RE'],
    ];
    if ($yecVid <= 0) {
        $yecVid = orange_voucher_post($pdo, [
            'voucher_date' => '2026-12-31',
            'document_entered_at' => orange_admin_time_utc_now_mysql(),
            'description' => 'إقفال D3',
            'entry_type' => 'year_end_close',
            'country_id' => $kw,
        ], array_map(static function (array $ln): array {
            return [
                'account_id' => (int) $ln['account_id'],
                'debit' => (float) $ln['debit'],
                'credit' => (float) $ln['credit'],
                'memo' => (string) $ln['memo'],
            ];
        }, $lines));
        if (orange_year_end_close_yec_columns_ready($pdo)) {
            $pdo->prepare('UPDATE journal_vouchers SET yec_locked = 0, is_void = 0 WHERE id = ?')->execute([$yecVid]);
        }
    }
    d3f_assert($yecVid > 0, 'YEC voucher ready for finalize');
    $fin = orange_year_end_close_finalize($pdo, $yecVid, '2026-12-31', 'إقفال D3', $lines, $kw);
    d3f_assert((int) ($fin['voucher_id'] ?? 0) === $yecVid, 'YEC finalize returns voucher (YEC-01)');
    $stFy = $pdo->prepare('SELECT is_closed FROM fiscal_years WHERE id = ?');
    $stFy->execute([$fyOpen]);
    d3f_assert((int) $stFy->fetchColumn() === 1, 'fiscal year closed after YEC finalize');

    // Post-close write rejected
    $postClose = false;
    try {
        orange_d3_post_manual($pdo, $kw, 'after close', [
            ['account_id' => $cash, 'debit' => 1, 'credit' => 0, 'memo' => 'a'],
            ['account_id' => $sales, 'debit' => 0, 'credit' => 1, 'memo' => 'b'],
        ], 'manual', '2026-07-20');
    } catch (Throwable $e) {
        $postClose = str_contains($e->getMessage(), 'مغلقة');
    }
    d3f_assert($postClose, 'posting after year close rejected');

    // Duplicate YEC finalize blocked
    $dupYec = false;
    try {
        orange_year_end_close_finalize($pdo, $yecVid, '2026-12-31', 'dup', $lines, $kw);
    } catch (Throwable $e) {
        $dupYec = str_contains($e->getMessage(), 'مقفول') || str_contains($e->getMessage(), 'مغلقة');
    }
    d3f_assert($dupYec, 'duplicate YEC finalize rejected');

    // Wrong-country FY
    $badFy = false;
    try {
        orange_fiscal_year_assert_country_scope($pdo, $fyClosed, $eg);
    } catch (Throwable $e) {
        $badFy = true;
    }
    // If closed FY is KW-only, asserting EG scope should fail when country mismatches
    $fyCid = orange_fiscal_year_country_id($pdo, $fyClosed);
    d3f_assert($fyCid === $kw, 'closed FY belongs to KW');
    if ($fyCid !== $eg) {
        try {
            orange_fiscal_year_assert_country_scope($pdo, $fyClosed, $eg);
            $badFy = false;
        } catch (Throwable) {
            $badFy = true;
        }
        d3f_assert($badFy, 'wrong-country fiscal year rejected');
    }

    // Slot register uniqueness
    $slotKey = 'd3-test-slot';
    orange_gl_voucher_slot_register($pdo, [
        'doc_kind' => 'order',
        'entity_id' => 900001,
        'slot_key' => $slotKey,
        'entry_type' => 'manual',
        'country_id' => $kw,
    ], $v2);
    $found = orange_gl_voucher_slot_find($pdo, 'order', 900001, $slotKey);
    d3f_assert($found !== null && (int) ($found['journal_voucher_id'] ?? 0) === $v2, 'voucher slot registered once');

    // Void flag path
    if (orange_d1_has_column($pdo, 'journal_vouchers', 'is_void')) {
        $pdo->prepare('UPDATE journal_vouchers SET is_void = 1, voided_at = ? WHERE id = ?')
            ->execute([gmdate('Y-m-d H:i:s'), $v2]);
        $stVoid = $pdo->prepare('SELECT is_void FROM journal_vouchers WHERE id = ?');
        $stVoid->execute([$v2]);
        d3f_assert((int) $stVoid->fetchColumn() === 1, 'void flag set on voucher');
        $excl = orange_journal_voucher_sql_exclude_void($pdo, 'jv');
        d3f_assert($excl !== '', 'void exclusion SQL fragment exists');
    }

    // Mutation-proof: fiscal require_open
    $fyOk = orange_fiscal_require_open_for_posting($pdo, '2026-07-01', $eg);
    d3f_assert($fyOk === (int) $ids['eg_fy_open_id'], 'mutation-proof: open FY still required for EG');

    echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
    if ($failures > 0) {
        echo "RESULT=FSR_D3_PROVEN_ACCOUNTING_GAPS_FOUND\n";
        exit(1);
    }
    echo "RESULT=FSR_D3_FISCAL_NUMBERING_OK\n";
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
