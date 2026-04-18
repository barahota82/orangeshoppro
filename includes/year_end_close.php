<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/gl_settings.php';
require_once __DIR__ . '/journal_voucher.php';

/**
 * قيود إقفال الإيرادات والمصروفات إلى ملخص الدخل ثم إلى الأرباح المحتجزة.
 *
 * @throws RuntimeException|InvalidArgumentException
 */
function orange_fiscal_year_end_accounting_close(PDO $pdo, int $fiscalYearId): void
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_journal_vouchers_ready($pdo)) {
        throw new RuntimeException('جداول السندات غير متوفرة.');
    }
    $stChk = $pdo->prepare('SELECT id FROM journal_vouchers WHERE fiscal_year_id = ? AND entry_type = ? LIMIT 1');
    $stChk->execute([$fiscalYearId, 'year_end_close']);
    if ($stChk->fetch()) {
        throw new RuntimeException('تم تنفيذ الإقفال المحاسبي لهذه السنة مسبقاً.');
    }

    $fySt = $pdo->prepare('SELECT * FROM fiscal_years WHERE id = ? LIMIT 1');
    $fySt->execute([$fiscalYearId]);
    $fy = $fySt->fetch(PDO::FETCH_ASSOC);
    if (!$fy) {
        throw new RuntimeException('السنة المالية غير موجودة.');
    }
    if ((int) $fy['is_closed'] === 1) {
        throw new RuntimeException('السنة مغلقة — لا يمكن تشغيل الإقفال المحاسبي.');
    }

    $incomeSummaryId = orange_gl_account_id($pdo, 'income_summary');
    $retainedId = orange_gl_account_id($pdo, 'retained_earnings');

    $tb = orange_voucher_account_totals($pdo, $fiscalYearId, ['year_end_close', 'opening_balance']);

    $acctStmt = $pdo->query('SELECT id, account_class FROM accounts');
    $classes = [];
    while ($r = $acctStmt->fetch(PDO::FETCH_ASSOC)) {
        $classes[(int) $r['id']] = strtolower(trim((string) ($r['account_class'] ?? 'unclassified')));
    }

    $eps = 0.0001;
    $lines = [];
    $summaryDr = 0.0;
    $summaryCr = 0.0;

    foreach ($tb as $aid => $t) {
        $class = $classes[$aid] ?? 'unclassified';
        $deb = (float) $t['debit'];
        $cred = (float) $t['credit'];
        if ($class === 'revenue') {
            $b = round($cred - $deb, 4);
            if (abs($b) < $eps) {
                continue;
            }
            $lines[] = ['account_id' => $aid, 'debit' => $b, 'credit' => 0, 'memo' => 'إقفال إيراد'];
            $lines[] = ['account_id' => $incomeSummaryId, 'debit' => 0, 'credit' => $b, 'memo' => 'إقفال إيراد'];
            $summaryCr += $b;
        } elseif ($class === 'expense' || $class === 'cogs') {
            $b = round($deb - $cred, 4);
            if (abs($b) < $eps) {
                continue;
            }
            $lines[] = ['account_id' => $aid, 'debit' => 0, 'credit' => $b, 'memo' => 'إقفال مصروف/تكلفة'];
            $lines[] = ['account_id' => $incomeSummaryId, 'debit' => $b, 'credit' => 0, 'memo' => 'إقفال مصروف/تكلفة'];
            $summaryDr += $b;
        }
    }

    $net = round($summaryCr - $summaryDr, 4);
    if ($net > $eps) {
        $lines[] = ['account_id' => $incomeSummaryId, 'debit' => $net, 'credit' => 0, 'memo' => 'إقفال ملخص الدخل'];
        $lines[] = ['account_id' => $retainedId, 'debit' => 0, 'credit' => $net, 'memo' => 'صافي الدخل إلى المحتجز'];
    } elseif ($net < -$eps) {
        $loss = abs($net);
        $lines[] = ['account_id' => $retainedId, 'debit' => $loss, 'credit' => 0, 'memo' => 'صافي خسارة'];
        $lines[] = ['account_id' => $incomeSummaryId, 'debit' => 0, 'credit' => $loss, 'memo' => 'إقفال ملخص الدخل'];
    }

    if ($lines === []) {
        return;
    }

    orange_voucher_post($pdo, [
        'voucher_date' => $fy['end_date'] . ' 18:00:00',
        'reference' => 'YEC-' . $fiscalYearId,
        'description' => 'إقفال سنة مالية — إيرادات ومصروفات وملخص الدخل',
        'entry_type' => 'year_end_close',
    ], $lines);
}
