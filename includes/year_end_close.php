<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/gl_settings.php';
require_once __DIR__ . '/journal_voucher.php';
require_once __DIR__ . '/account_tree.php';

/**
 * قيود إقفال الإيرادات والمصروفات إلى ملخص الدخل (آخر يوم بالسنة)، ثم قيد من ملخص الدخل إلى الأرباح المحتجزة (أول يوم بالسنة التالية).
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
    $refPl = 'YEC-PL-' . $fiscalYearId;
    $refRe = 'YEC-RE-' . $fiscalYearId;
    $refLegacy = 'YEC-' . $fiscalYearId;
    $stChk = $pdo->prepare('SELECT id FROM journal_vouchers WHERE reference IN (?,?,?) LIMIT 1');
    $stChk->execute([$refPl, $refRe, $refLegacy]);
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
    $plLines = $lines;
    $reLines = [];
    if ($net > $eps) {
        $reLines[] = ['account_id' => $incomeSummaryId, 'debit' => $net, 'credit' => 0, 'memo' => 'إقفال ملخص الدخل'];
        $reLines[] = ['account_id' => $retainedId, 'debit' => 0, 'credit' => $net, 'memo' => 'صافي الدخل إلى المحتجز'];
    } elseif ($net < -$eps) {
        $loss = abs($net);
        $reLines[] = ['account_id' => $retainedId, 'debit' => $loss, 'credit' => 0, 'memo' => 'صافي خسارة'];
        $reLines[] = ['account_id' => $incomeSummaryId, 'debit' => 0, 'credit' => $loss, 'memo' => 'إقفال ملخص الدخل'];
    }

    if ($plLines === []) {
        return;
    }

    $endDate = (string) $fy['end_date'];
    orange_voucher_post($pdo, [
        'voucher_date' => $endDate . ' 18:00:00',
        'reference' => $refPl,
        'description' => 'إقفال سنة مالية — إيرادات ومصروفات إلى ملخص الدخل',
        'entry_type' => 'year_end_close',
    ], $plLines);

    if ($reLines !== []) {
        $nextFy = orange_fiscal_year_next_after_end($pdo, $endDate);
        if ($nextFy === null) {
            throw new RuntimeException(
                'قيد نقل ملخص الدخل إلى الأرباح المحتجزة يحتاج سنة مالية تالية (أول يومها عادة مباشرة بعد '
                . $endDate . '). أنشئ السنة التالية من «السنوات المالية» ثم أعد الإقفال.'
            );
        }
        if ((int) ($nextFy['is_closed'] ?? 0) === 1) {
            throw new RuntimeException(
                'السنة المالية التالية «' . trim((string) ($nextFy['label_ar'] ?? '')) . '» مغلقة — افتحها أو ألغِ إقفالها لترحيل قيد المحتجز في أول يومها.'
            );
        }
        $nextStart = (string) ($nextFy['start_date'] ?? '');
        if ($nextStart === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextStart)) {
            throw new RuntimeException('تاريخ بداية السنة المالية التالية غير صالح.');
        }
        orange_voucher_post($pdo, [
            'voucher_date' => $nextStart . ' 10:00:00',
            'reference' => $refRe,
            'description' => 'إقفال سنة مالية — من ملخص الدخل إلى الأرباح المحتجزة (أول يوم السنة التالية)',
            'entry_type' => 'year_end_close',
        ], $reLines);
    }
}

/**
 * فك إغلاق سنة مالية: حذف سند/سندات الإقفال المحاسبي (year_end_close) ثم إعادة فتح السنة.
 * يُستخدم لتصحيح أخطاء قبل إعادة الإقفال. راجع أرصدة أول المدة للسنة التالية إن وُجدت.
 *
 * @return array{removed_year_end_vouchers:int}
 *
 * @throws RuntimeException|InvalidArgumentException
 */
function orange_fiscal_year_reopen(PDO $pdo, int $fiscalYearId): array
{
    orange_catalog_ensure_schema($pdo);
    if ($fiscalYearId <= 0 || ! orange_table_exists($pdo, 'fiscal_years')) {
        throw new InvalidArgumentException('معرف السنة غير صالح.');
    }
    $fySt = $pdo->prepare('SELECT id, is_closed FROM fiscal_years WHERE id = ? LIMIT 1');
    $fySt->execute([$fiscalYearId]);
    $fy = $fySt->fetch(PDO::FETCH_ASSOC);
    if (! $fy) {
        throw new RuntimeException('السنة المالية غير موجودة.');
    }
    if ((int) $fy['is_closed'] !== 1) {
        throw new RuntimeException('السنة المالية ليست مغلقة — لا حاجة لفك الإقفال.');
    }

    $pdo->beginTransaction();
    try {
        $removed = 0;
        if (orange_journal_vouchers_ready($pdo)) {
            $refPl = 'YEC-PL-' . $fiscalYearId;
            $refRe = 'YEC-RE-' . $fiscalYearId;
            $refLegacy = 'YEC-' . $fiscalYearId;
            $st = $pdo->prepare(
                'SELECT id FROM journal_vouchers WHERE reference IN (?,?,?) OR (fiscal_year_id = ? AND entry_type = ?)'
            );
            $st->execute([$refPl, $refRe, $refLegacy, $fiscalYearId, 'year_end_close']);
            /** @var list<int> $ids */
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $ids = array_values(array_unique(array_filter($ids, static function (int $i): bool {
                return $i > 0;
            })));
            if ($ids !== []) {
                if (orange_table_exists($pdo, 'orange_gl_pending_movements')) {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $pdo->prepare(
                        "UPDATE orange_gl_pending_movements SET status = 'pending', journal_voucher_id = NULL, posted_at = NULL WHERE journal_voucher_id IN ($ph)"
                    )->execute($ids);
                }
                $delPh = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("DELETE FROM journal_vouchers WHERE id IN ($delPh)")->execute($ids);
                $removed = count($ids);
            }
        }

        $u = $pdo->prepare('UPDATE fiscal_years SET is_closed = 0, closed_at = NULL WHERE id = ? AND is_closed = 1');
        $u->execute([$fiscalYearId]);
        if ($u->rowCount() === 0) {
            throw new RuntimeException('تعذر تحديث حالة السنة المالية.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['removed_year_end_vouchers' => $removed];
}
