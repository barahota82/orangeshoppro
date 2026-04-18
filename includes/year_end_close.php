<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/gl_settings.php';
require_once __DIR__ . '/journal_voucher.php';
require_once __DIR__ . '/account_tree.php';

/**
 * قيود إقفال الإيرادات والمصروفات إلى ملخص الدخل ثم إلى الأرباح المحتجزة.
 *
 * @param ?int $incomeSummaryAccountId معرف حساب ملخص الدخل (وسيط)؛ إن مرّر > 0 يُستخدم بدل الربط الاختياري في الجدول.
 * @param ?int $retainedEarningsAccountId معرف حساب الأرباح المحتجزة؛ إن مرّر > 0 يُستخدم بدل الربط الاختياري.
 *
 * @throws RuntimeException|InvalidArgumentException
 */
function orange_fiscal_year_end_accounting_close(PDO $pdo, int $fiscalYearId, ?int $incomeSummaryAccountId = null, ?int $retainedEarningsAccountId = null): void
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

    $tb = orange_voucher_account_totals($pdo, $fiscalYearId, ['year_end_close', 'opening_balance']);

    $classes = [];
    foreach (array_keys($tb) as $aid) {
        $aid = (int) $aid;
        $classes[$aid] = orange_accounts_account_pl_role($pdo, $aid);
    }

    $eps = 0.0001;
    $needsIncomeClose = false;
    foreach ($tb as $aid => $t) {
        $class = $classes[$aid] ?? 'unclassified';
        $deb = (float) $t['debit'];
        $cred = (float) $t['credit'];
        if ($class === 'revenue') {
            $b = round($cred - $deb, 4);
            if (abs($b) >= $eps) {
                $needsIncomeClose = true;
                break;
            }
        } elseif ($class === 'expense' || $class === 'cogs') {
            $b = round($deb - $cred, 4);
            if (abs($b) >= $eps) {
                $needsIncomeClose = true;
                break;
            }
        }
    }

    if (! $needsIncomeClose) {
        return;
    }

    $incomeSummaryId = ($incomeSummaryAccountId !== null && $incomeSummaryAccountId > 0)
        ? $incomeSummaryAccountId
        : (orange_gl_account_id_optional($pdo, 'income_summary') ?? 0);
    $retainedId = ($retainedEarningsAccountId !== null && $retainedEarningsAccountId > 0)
        ? $retainedEarningsAccountId
        : (orange_gl_account_id_optional($pdo, 'retained_earnings') ?? 0);

    if ($incomeSummaryId <= 0 || $retainedId <= 0) {
        throw new RuntimeException(
            'قيود إقفال الإيرادات والمصروفات تتطلب حساب ملخص الدخل (وسيط) وحساب الأرباح المحتجزة. '
            . 'حدّدهما في نافذة «إقفال محاسبي» عند إغلاق السنة، أو اربطهما مسبقاً في قاعدة البيانات (مفاتيح income_summary و retained_earnings).'
        );
    }

    $chk = $pdo->prepare('SELECT id FROM accounts WHERE id = ? LIMIT 1');
    foreach ([$incomeSummaryId, $retainedId] as $aid) {
        $chk->execute([$aid]);
        if (! $chk->fetch()) {
            throw new RuntimeException('أحد حسابات الإقفال المحددة غير موجود في الدليل المحاسبي.');
        }
        if (! orange_accounts_account_is_posting_leaf($pdo, $aid)) {
            throw new RuntimeException(
                'حسابات الإقفال يجب أن تكون حسابات فرعية (أوراق ترحيل) — لا يُستخدم جذر الدليل أو حساب له أبناء.'
            );
        }
    }

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
