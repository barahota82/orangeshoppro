<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/gl_settings.php';
require_once __DIR__ . '/journal_voucher.php';
require_once __DIR__ . '/account_tree.php';

/** @return array<string, string> */
function orange_year_end_close_phase_labels(): array
{
    return [
        'PL' => 'إقفال الإيرادات والمصروفات',
        'RE' => 'ترحيل الصافي إلى الأرباح المحتجزة',
        'LR' => 'تخصيص الاحتياطي القانوني',
    ];
}

/**
 * @return list<array{account_id:int,debit:float,credit:float,memo:string,yec_phase:string}>
 *
 * @throws RuntimeException|InvalidArgumentException
 */
function orange_year_end_close_build_lines(
    PDO $pdo,
    int $fiscalYearId,
    ?int $incomeSummaryAccountId = null,
    ?int $retainedEarningsAccountId = null
): array {
    orange_catalog_ensure_schema($pdo);
    if (! orange_journal_vouchers_ready($pdo)) {
        throw new RuntimeException('جداول السندات غير متوفرة.');
    }

    $fySt = $pdo->prepare('SELECT * FROM fiscal_years WHERE id = ? LIMIT 1');
    $fySt->execute([$fiscalYearId]);
    $fy = $fySt->fetch(PDO::FETCH_ASSOC);
    if (! $fy) {
        throw new RuntimeException('السنة المالية غير موجودة.');
    }

    $tb = orange_voucher_account_totals($pdo, $fiscalYearId, ['year_end_close', 'opening_balance']);

    $classes = [];
    foreach (array_keys($tb) as $aid) {
        $classes[(int) $aid] = orange_accounts_account_pl_role($pdo, (int) $aid);
    }

    $eps = 0.0001;
    $needsIncomeClose = false;
    foreach ($tb as $aid => $t) {
        $class = $classes[(int) $aid] ?? 'unclassified';
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
        return [];
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
            . 'حدّدهما في «حسابات القيود التلقائية» أو من نافذة إقفال السنة.'
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
                'حسابات الإقفال يجب أن تكون حسابات فرعية (أوراق ترحيل).'
            );
        }
    }

    $plLines = [];
    $summaryDr = 0.0;
    $summaryCr = 0.0;

    foreach ($tb as $aid => $t) {
        $aid = (int) $aid;
        $class = $classes[$aid] ?? 'unclassified';
        $deb = (float) $t['debit'];
        $cred = (float) $t['credit'];
        if ($class === 'revenue') {
            $b = round($cred - $deb, 4);
            if (abs($b) < $eps) {
                continue;
            }
            $plLines[] = ['account_id' => $aid, 'debit' => $b, 'credit' => 0.0, 'memo' => 'إقفال إيراد', 'yec_phase' => 'PL'];
            $plLines[] = ['account_id' => $incomeSummaryId, 'debit' => 0.0, 'credit' => $b, 'memo' => 'إقفال إيراد', 'yec_phase' => 'PL'];
            $summaryCr += $b;
        } elseif ($class === 'expense' || $class === 'cogs') {
            $b = round($deb - $cred, 4);
            if (abs($b) < $eps) {
                continue;
            }
            $plLines[] = ['account_id' => $aid, 'debit' => 0.0, 'credit' => $b, 'memo' => 'إقفال مصروف/تكلفة', 'yec_phase' => 'PL'];
            $plLines[] = ['account_id' => $incomeSummaryId, 'debit' => $b, 'credit' => 0.0, 'memo' => 'إقفال مصروف/تكلفة', 'yec_phase' => 'PL'];
            $summaryDr += $b;
        }
    }

    if ($plLines === []) {
        return [];
    }

    $net = round($summaryCr - $summaryDr, 4);
    $all = $plLines;
    if ($net > $eps) {
        $all[] = ['account_id' => $incomeSummaryId, 'debit' => $net, 'credit' => 0.0, 'memo' => 'إقفال ملخص الدخل', 'yec_phase' => 'RE'];
        $all[] = ['account_id' => $retainedId, 'debit' => 0.0, 'credit' => $net, 'memo' => 'صافي الدخل إلى المحتجز', 'yec_phase' => 'RE'];
    } elseif ($net < -$eps) {
        $loss = abs($net);
        $all[] = ['account_id' => $retainedId, 'debit' => $loss, 'credit' => 0.0, 'memo' => 'صافي خسارة', 'yec_phase' => 'RE'];
        $all[] = ['account_id' => $incomeSummaryId, 'debit' => 0.0, 'credit' => $loss, 'memo' => 'إقفال ملخص الدخل', 'yec_phase' => 'RE'];
    }

    if ($net > $eps) {
        $lrPct = orange_gl_legal_reserve_percent_of_current_year_profit($pdo);
        $legalReserveId = orange_gl_account_id_optional($pdo, 'legal_reserve') ?? 0;
        if ($lrPct > 0.0 && $legalReserveId > 0) {
            $chk->execute([$legalReserveId]);
            if ($chk->fetch() && orange_accounts_account_is_posting_leaf($pdo, $legalReserveId)) {
                $reserveAmt = round($net * ($lrPct / 100.0), 4);
                if ($reserveAmt >= $eps) {
                    $all[] = ['account_id' => $retainedId, 'debit' => $reserveAmt, 'credit' => 0.0, 'memo' => 'تخصيص احتياطي قانوني', 'yec_phase' => 'LR'];
                    $all[] = ['account_id' => $legalReserveId, 'debit' => 0.0, 'credit' => $reserveAmt, 'memo' => 'تخصيص احتياطي قانوني', 'yec_phase' => 'LR'];
                }
            }
        }
    }

    return $all;
}

function orange_year_end_close_yec_columns_ready(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'journal_vouchers')
        && orange_table_has_column($pdo, 'journal_vouchers', 'yec_locked')
        && orange_table_has_column($pdo, 'journal_vouchers', 'is_void');
}

/** @return ?array<string, mixed> */
function orange_year_end_close_find_active_voucher(PDO $pdo, int $fiscalYearId): ?array
{
    if ($fiscalYearId <= 0 || ! orange_journal_vouchers_ready($pdo)) {
        return null;
    }
    $sql = "SELECT * FROM journal_vouchers WHERE fiscal_year_id = ? AND entry_type = 'year_end_close'";
    if (orange_year_end_close_yec_columns_ready($pdo)) {
        $sql .= ' AND (is_void = 0 OR is_void IS NULL)';
    }
    $sql .= ' ORDER BY id DESC LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([$fiscalYearId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @param list<array{account_id:int,debit:float,credit:float,memo:string,yec_phase?:string}> $lines
 */
function orange_year_end_close_stamp_line_phases(PDO $pdo, int $voucherId, array $lines): void
{
    if ($voucherId <= 0 || ! orange_table_has_column($pdo, 'journal_lines', 'yec_phase')) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT id, line_no FROM journal_lines WHERE voucher_id = ? ORDER BY line_no ASC'
    );
    $st->execute([$voucherId]);
    $dbLines = $st->fetchAll(PDO::FETCH_ASSOC);
    $upd = $pdo->prepare('UPDATE journal_lines SET yec_phase = ? WHERE id = ?');
    foreach ($dbLines as $i => $dbRow) {
        $phase = trim((string) ($lines[$i]['yec_phase'] ?? ''));
        if ($phase !== '') {
            $upd->execute([$phase, (int) $dbRow['id']]);
        }
    }
}

/**
 * @param list<array{account_id:int,debit:float,credit:float,memo:string,yec_phase?:string}> $lines
 */
function orange_year_end_close_replace_lines(PDO $pdo, int $voucherId, array $lines, string $description, string $voucherDate): void
{
    $postLines = [];
    foreach ($lines as $ln) {
        $postLines[] = [
            'account_id' => (int) $ln['account_id'],
            'debit' => (float) $ln['debit'],
            'credit' => (float) $ln['credit'],
            'memo' => (string) $ln['memo'],
        ];
    }
    orange_voucher_update_multiline($pdo, $voucherId, [
        'voucher_date' => $voucherDate,
        'description' => $description,
    ], $postLines);
    orange_year_end_close_stamp_line_phases($pdo, $voucherId, $lines);
}

/**
 * تجهيز مسودة YEC واحدة — §9.5 الخطوة 1.
 *
 * @return array{voucher_id:int,needs_review:bool,fiscal_year_id:int}
 *
 * @throws RuntimeException|InvalidArgumentException
 */
function orange_year_end_close_prepare_draft(
    PDO $pdo,
    int $fiscalYearId,
    ?int $incomeSummaryAccountId = null,
    ?int $retainedEarningsAccountId = null
): array {
    orange_catalog_ensure_schema($pdo);

    $fySt = $pdo->prepare('SELECT * FROM fiscal_years WHERE id = ? LIMIT 1');
    $fySt->execute([$fiscalYearId]);
    $fy = $fySt->fetch(PDO::FETCH_ASSOC);
    if (! $fy) {
        throw new RuntimeException('السنة المالية غير موجودة.');
    }
    if ((int) ($fy['is_closed'] ?? 0) === 1) {
        throw new RuntimeException('السنة مغلقة — لا يمكن تجهيز إقفال جديد.');
    }

    $existing = orange_year_end_close_find_active_voucher($pdo, $fiscalYearId);
    if ($existing !== null && orange_year_end_close_yec_columns_ready($pdo)
        && (int) ($existing['yec_locked'] ?? 0) === 1) {
        throw new RuntimeException('تم إقفال هذه السنة محاسبياً — استخدم «فك الإقفال» للتصحيح.');
    }

    $lines = orange_year_end_close_build_lines($pdo, $fiscalYearId, $incomeSummaryAccountId, $retainedEarningsAccountId);
    if ($lines === []) {
        return ['voucher_id' => 0, 'needs_review' => false, 'fiscal_year_id' => $fiscalYearId];
    }

    $endDate = (string) $fy['end_date'] . ' 18:00:00';
    $description = 'إقفال سنة مالية — ' . trim((string) ($fy['label_ar'] ?? ''));

    $pdo->beginTransaction();
    try {
        orange_year_end_close_void_unlocked_legacy($pdo, $fiscalYearId, (int) ($existing['id'] ?? 0));

        $voucherId = 0;
        if ($existing !== null && (int) ($existing['yec_locked'] ?? 0) === 0) {
            $voucherId = (int) $existing['id'];
            orange_year_end_close_replace_lines($pdo, $voucherId, $lines, $description, $endDate);
            if (orange_year_end_close_yec_columns_ready($pdo)) {
                $pdo->prepare(
                    'UPDATE journal_vouchers SET is_void = 0, voided_at = NULL, yec_locked = 0 WHERE id = ?'
                )->execute([$voucherId]);
            }
        } else {
            $voucherId = orange_voucher_post($pdo, [
                'voucher_date' => $endDate,
                'description' => $description,
                'entry_type' => 'year_end_close',
            ], array_map(static function (array $ln): array {
                return [
                    'account_id' => (int) $ln['account_id'],
                    'debit' => (float) $ln['debit'],
                    'credit' => (float) $ln['credit'],
                    'memo' => (string) $ln['memo'],
                ];
            }, $lines));
            orange_year_end_close_stamp_line_phases($pdo, $voucherId, $lines);
            if (orange_year_end_close_yec_columns_ready($pdo)) {
                $pdo->prepare(
                    'UPDATE journal_vouchers SET yec_locked = 0, is_void = 0, voided_at = NULL WHERE id = ?'
                )->execute([$voucherId]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['voucher_id' => $voucherId, 'needs_review' => true, 'fiscal_year_id' => $fiscalYearId];
}

/** void أي مسودات YEC قديمة (مثلاً 3 سندات legacy) ما عدا المُبقى. */
function orange_year_end_close_void_unlocked_legacy(PDO $pdo, int $fiscalYearId, int $keepId = 0): void
{
    if (! orange_year_end_close_yec_columns_ready($pdo)) {
        return;
    }
    $sql = "SELECT id FROM journal_vouchers WHERE fiscal_year_id = ? AND entry_type = 'year_end_close'
            AND (is_void = 0 OR is_void IS NULL) AND yec_locked = 0";
    $params = [$fiscalYearId];
    if ($keepId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $keepId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    foreach ($ids as $vid) {
        orange_year_end_close_void_voucher($pdo, $vid, false);
    }
}

function orange_year_end_close_void_voucher(PDO $pdo, int $voucherId, bool $reopenFiscalYear = true): void
{
    if ($voucherId <= 0) {
        throw new InvalidArgumentException('معرف السند غير صالح.');
    }
    if (! orange_year_end_close_yec_columns_ready($pdo)) {
        throw new RuntimeException('أعمدة YEC غير جاهزة — حدّث المخطط.');
    }
    $st = $pdo->prepare(
        "SELECT id, fiscal_year_id, yec_locked FROM journal_vouchers WHERE id = ? AND entry_type = 'year_end_close' LIMIT 1"
    );
    $st->execute([$voucherId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (! $v) {
        throw new RuntimeException('سند YEC غير موجود.');
    }
    $fyId = (int) ($v['fiscal_year_id'] ?? 0);

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE journal_vouchers SET is_void = 1, voided_at = NOW(), yec_locked = 0 WHERE id = ?'
        )->execute([$voucherId]);

        if ($reopenFiscalYear && $fyId > 0) {
            $pdo->prepare('UPDATE fiscal_years SET is_closed = 0, closed_at = NULL WHERE id = ?')
                ->execute([$fyId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * حفظ = إقفال — §9.5 الخطوة 3.
 *
 * @param list<array{account_id:int,debit:float,credit:float,memo:string,yec_phase?:string}> $lines
 *
 * @return array{voucher_id:int,fiscal_year_id:int}
 */
function orange_year_end_close_finalize(
    PDO $pdo,
    int $voucherId,
    string $voucherDate,
    string $description,
    array $lines
): array {
    if ($voucherId <= 0) {
        throw new InvalidArgumentException('معرف السند غير صالح.');
    }
    if (! orange_year_end_close_yec_columns_ready($pdo)) {
        throw new RuntimeException('أعمدة YEC غير جاهزة.');
    }

    $st = $pdo->prepare(
        "SELECT * FROM journal_vouchers WHERE id = ? AND entry_type = 'year_end_close' LIMIT 1"
    );
    $st->execute([$voucherId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (! $v) {
        throw new RuntimeException('سند YEC غير موجود.');
    }
    if ((int) ($v['yec_locked'] ?? 0) === 1 && (int) ($v['is_void'] ?? 0) === 0) {
        throw new RuntimeException('السند مقفول — لا يمكن تعديله.');
    }

    $fyId = (int) ($v['fiscal_year_id'] ?? 0);
    $fySt = $pdo->prepare('SELECT id, is_closed FROM fiscal_years WHERE id = ? LIMIT 1');
    $fySt->execute([$fyId]);
    $fy = $fySt->fetch(PDO::FETCH_ASSOC);
    if (! $fy) {
        throw new RuntimeException('السنة المالية المرتبطة غير موجودة.');
    }
    if ((int) ($fy['is_closed'] ?? 0) === 1 && (int) ($v['is_void'] ?? 0) === 0 && (int) ($v['yec_locked'] ?? 0) === 1) {
        throw new RuntimeException('السنة مغلقة مسبقاً.');
    }

    $pdo->beginTransaction();
    try {
        orange_year_end_close_replace_lines($pdo, $voucherId, $lines, $description, $voucherDate);
        $pdo->prepare(
            'UPDATE journal_vouchers SET is_void = 0, voided_at = NULL, yec_locked = 1 WHERE id = ?'
        )->execute([$voucherId]);

        if ($fyId > 0) {
            $pdo->prepare('UPDATE fiscal_years SET is_closed = 1, closed_at = NOW() WHERE id = ? AND is_closed = 0')
                ->execute([$fyId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['voucher_id' => $voucherId, 'fiscal_year_id' => $fyId];
}

/**
 * @deprecated استخدم orange_year_end_close_prepare_draft + orange_year_end_close_finalize
 */
function orange_fiscal_year_end_accounting_close(PDO $pdo, int $fiscalYearId, ?int $incomeSummaryAccountId = null, ?int $retainedEarningsAccountId = null): void
{
    $info = orange_year_end_close_prepare_draft($pdo, $fiscalYearId, $incomeSummaryAccountId, $retainedEarningsAccountId);
    if ((int) ($info['voucher_id'] ?? 0) <= 0) {
        return;
    }
    throw new RuntimeException(
        'إقفال السنة يتطلب مراجعة وحفظ من شاشة «قيود الإقفال السنوية» — استخدم مسار fiscal_years → إقفال.'
    );
}

/**
 * فك إقفال سنة مالية: void سند YEC ثم إعادة فتح السنة — §9.6.
 *
 * @return array{voided_year_end_vouchers:int,voucher_id:int}
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

    $voided = 0;
    $lastVid = 0;

    if (orange_journal_vouchers_ready($pdo)) {
        if (orange_year_end_close_yec_columns_ready($pdo)) {
            $st = $pdo->prepare(
                "SELECT id FROM journal_vouchers WHERE fiscal_year_id = ? AND entry_type = 'year_end_close'
                 AND (is_void = 0 OR is_void IS NULL) ORDER BY id DESC"
            );
            $st->execute([$fiscalYearId]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
            foreach ($ids as $vid) {
                orange_year_end_close_void_voucher($pdo, $vid, false);
                ++$voided;
                $lastVid = $vid;
            }
            if ($voided === 0) {
                $pdo->prepare('UPDATE fiscal_years SET is_closed = 0, closed_at = NULL WHERE id = ?')
                    ->execute([$fiscalYearId]);
            } else {
                $pdo->prepare('UPDATE fiscal_years SET is_closed = 0, closed_at = NULL WHERE id = ?')
                    ->execute([$fiscalYearId]);
            }
        } else {
            $st = $pdo->prepare(
                'SELECT id FROM journal_vouchers WHERE fiscal_year_id = ? AND entry_type = ?'
            );
            $st->execute([$fiscalYearId, 'year_end_close']);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if ($ids !== []) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("DELETE FROM journal_vouchers WHERE id IN ($ph)")->execute($ids);
                $voided = count($ids);
                $lastVid = (int) ($ids[0] ?? 0);
            }
            $pdo->prepare('UPDATE fiscal_years SET is_closed = 0, closed_at = NULL WHERE id = ?')
                ->execute([$fiscalYearId]);
        }
    } else {
        $pdo->prepare('UPDATE fiscal_years SET is_closed = 0, closed_at = NULL WHERE id = ?')
            ->execute([$fiscalYearId]);
    }

    return ['voided_year_end_vouchers' => $voided, 'voucher_id' => $lastVid];
}
