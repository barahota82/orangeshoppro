<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/fiscal_years.php';
require_once __DIR__ . '/../../../includes/year_end_close.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../../includes/admin_time.php';
require_admin_api();

/**
 * @param array<string, mixed> $row
 * @return array{0: string, 1: string, 2: string, 3: int}
 */
function orange_fiscal_normalize_save_row(array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    $start = trim((string) ($row['start_date'] ?? ''));
    $end = trim((string) ($row['end_date'] ?? ''));
    $isClosed = ! empty($row['is_closed']) && ! in_array($row['is_closed'], [0, '0', false, 'false'], true) ? 1 : 0;
    $label = trim((string) ($row['label_ar'] ?? ''));
    if ($label === '' && preg_match('/^(\d{4})-\d{2}-\d{2}$/', $start, $m)) {
        $label = 'سنة ' . $m[1];
    }

    return [$start, $end, $label, $isClosed];
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_table_exists($pdo, 'fiscal_years')) {
        json_response(['success' => false, 'message' => 'جدول السنوات المالية غير متوفر'], 500);
    }

    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? ''));
    $ctxCountryId = orange_admin_settings_effective_country_id($pdo);
    $fyScoped = orange_fiscal_years_has_country_column($pdo);

    if ($action === 'delete') {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            json_response(['success' => false, 'message' => 'معرف السنة مطلوب'], 422);
        }
        if (orange_fiscal_year_has_journal_activity($pdo, $id)) {
            json_response(['success' => false, 'message' => 'لا يمكن حذف سنة عليها قيود أو مستندات'], 422);
        }
        if ($fyScoped) {
            $d = $pdo->prepare('DELETE FROM fiscal_years WHERE id = ? AND country_id = ?');
            $d->execute([$id, $ctxCountryId]);
        } else {
            $d = $pdo->prepare('DELETE FROM fiscal_years WHERE id = ?');
            $d->execute([$id]);
        }
        if ($d->rowCount() === 0) {
            json_response(['success' => false, 'message' => 'السنة غير موجودة'], 404);
        }
        audit_log('fiscal_year_delete', 'حذف سنة مالية #' . $id, 'fiscal_years', $id);
        json_response(['success' => true, 'message' => 'تم حذف السنة المالية']);
    }

    if ($action === 'save_rows') {
        $rows = $data['rows'] ?? [];
        if (! is_array($rows)) {
            json_response(['success' => false, 'message' => 'بيانات غير صالحة'], 422);
        }

        $normalized = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $id = (int) ($r['id'] ?? 0);
            $start = trim((string) ($r['start_date'] ?? ''));
            $end = trim((string) ($r['end_date'] ?? ''));
            if ($id <= 0 && $start === '' && $end === '') {
                continue;
            }
            [$start, $end, $label, $isClosed,] = orange_fiscal_normalize_save_row($r);
            if ($label === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                json_response(['success' => false, 'message' => 'أكمل السنة والتواريخ لكل الصفوف المحفوظة (YYYY-MM-DD)'], 422);
            }
            if ($start > $end) {
                json_response(['success' => false, 'message' => 'تاريخ بداية السنة يجب أن يكون قبل أو يساوي نهايتها'], 422);
            }
            $normalized[] = [
                'id' => $id,
                'label_ar' => $label,
                'start_date' => $start,
                'end_date' => $end,
                'is_closed' => $isClosed,
            ];
        }

        foreach ($normalized as $i => $a) {
            foreach ($normalized as $j => $b) {
                if ($i === $j) {
                    continue;
                }
                if (! ($a['end_date'] < $b['start_date'] || $a['start_date'] > $b['end_date'])) {
                    json_response(['success' => false, 'message' => 'فترتان متداخلتان في الجدول — راجع التواريخ'], 422);
                }
            }
        }

        if ($normalized === []) {
            json_response(['success' => false, 'message' => 'لا توجد صفوف صالحة للحفظ'], 422);
        }

        $pdo->beginTransaction();
        try {
            $selPrev = $fyScoped
                ? $pdo->prepare('SELECT is_closed, closed_at FROM fiscal_years WHERE id = ? AND country_id = ? LIMIT 1')
                : $pdo->prepare('SELECT is_closed, closed_at FROM fiscal_years WHERE id = ? LIMIT 1');
            $ins = $fyScoped
                ? $pdo->prepare('INSERT INTO fiscal_years (country_id, label_ar, start_date, end_date, is_closed) VALUES (?, ?, ?, ?, ?)')
                : $pdo->prepare('INSERT INTO fiscal_years (label_ar, start_date, end_date, is_closed) VALUES (?, ?, ?, ?)');
            $upd = $fyScoped
                ? $pdo->prepare('UPDATE fiscal_years SET label_ar = ?, start_date = ?, end_date = ?, is_closed = ?, closed_at = ? WHERE id = ? AND country_id = ?')
                : $pdo->prepare('UPDATE fiscal_years SET label_ar = ?, start_date = ?, end_date = ?, is_closed = ?, closed_at = ? WHERE id = ?');

            foreach ($normalized as $row) {
                $id = (int) $row['id'];
                $isClosed = (int) $row['is_closed'];
                $closedAt = null;

                if ($id > 0) {
                    if (orange_fiscal_range_overlaps_existing($pdo, $row['start_date'], $row['end_date'], $id, $ctxCountryId)) {
                        $pdo->rollBack();
                        json_response(['success' => false, 'message' => 'فترة تتقاطع مع سنة أخرى في القاعدة'], 422);
                    }
                    $fyScoped ? $selPrev->execute([$id, $ctxCountryId]) : $selPrev->execute([$id]);
                    $prev = $selPrev->fetch(PDO::FETCH_ASSOC);
                    if (! $prev) {
                        $pdo->rollBack();
                        json_response(['success' => false, 'message' => 'سنة غير موجودة: #' . $id], 422);
                    }
                    $wasClosed = (int) ($prev['is_closed'] ?? 0) === 1;
                    if ($wasClosed && $isClosed === 0) {
                        $pdo->rollBack();
                        json_response([
                            'success' => false,
                            'message' => 'لا يمكن فتح سنة مغلقة من زر «حفظ» — استخدم «فك الإقفال» في عمود الإقفال المحاسبي لحذف سند الإقفال وإعادة فتح السنة.',
                        ], 422);
                    }
                    if ($isClosed === 1 && ! $wasClosed) {
                        $closedAt = orange_admin_time_utc_now_mysql();
                    } elseif ($isClosed === 1 && $wasClosed) {
                        $closedAt = $prev['closed_at'] ?: orange_admin_time_utc_now_mysql();
                    } else {
                        $closedAt = null;
                    }
                    if ($fyScoped) {
                        $upd->execute([
                            $row['label_ar'],
                            $row['start_date'],
                            $row['end_date'],
                            $isClosed,
                            $closedAt,
                            $id,
                            $ctxCountryId,
                        ]);
                    } else {
                        $upd->execute([
                            $row['label_ar'],
                            $row['start_date'],
                            $row['end_date'],
                            $isClosed,
                            $closedAt,
                            $id,
                        ]);
                    }
                } else {
                    if (orange_fiscal_range_overlaps_existing($pdo, $row['start_date'], $row['end_date'], null, $ctxCountryId)) {
                        $pdo->rollBack();
                        json_response(['success' => false, 'message' => 'فترة تتقاطع مع سنة أخرى في القاعدة'], 422);
                    }
                    if ($fyScoped) {
                        $ins->execute([
                            $ctxCountryId,
                            $row['label_ar'],
                            $row['start_date'],
                            $row['end_date'],
                            $isClosed,
                        ]);
                    } else {
                        $ins->execute([
                            $row['label_ar'],
                            $row['start_date'],
                            $row['end_date'],
                            $isClosed,
                        ]);
                    }
                    if ($isClosed === 1) {
                        $newId = (int) $pdo->lastInsertId();
                        $pdo->prepare('UPDATE fiscal_years SET closed_at = ? WHERE id = ?')
                            ->execute([orange_admin_time_utc_now_mysql(), $newId]);
                    }
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        audit_log('fiscal_year_batch_save', 'تحديث جدول السنوات المالية', 'fiscal_years', null);
        json_response(['success' => true, 'message' => 'تم حفظ السنوات المالية']);
    }

    if ($action === 'close') {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            json_response(['success' => false, 'message' => 'معرف السنة مطلوب'], 422);
        }
        $accountingClose = true;
        if (array_key_exists('accounting_close', $data)) {
            $v = $data['accounting_close'];
            if ($v === false || $v === 0 || $v === '0' || $v === 'false') {
                $accountingClose = false;
            }
        }

        $incomeSummaryAccountId = (isset($data['income_summary_account_id']) && (int) $data['income_summary_account_id'] > 0)
            ? (int) $data['income_summary_account_id'] : null;
        $retainedEarningsAccountId = (isset($data['retained_earnings_account_id']) && (int) $data['retained_earnings_account_id'] > 0)
            ? (int) $data['retained_earnings_account_id'] : null;

        $pdo->beginTransaction();
        try {
            orange_fiscal_year_assert_country_scope($pdo, $id, $ctxCountryId);
            if ($accountingClose) {
                $prep = orange_year_end_close_prepare_draft($pdo, $id, $incomeSummaryAccountId, $retainedEarningsAccountId, $ctxCountryId);
                $vid = (int) ($prep['voucher_id'] ?? 0);
                if ($vid > 0) {
                    $pdo->commit();
                    audit_log('fiscal_year_yec_prepare', 'تجهيز YEC للسنة #' . $id, 'journal_vouchers', $vid);
                    json_response([
                        'success' => true,
                        'message' => 'تم تجهيز سند الإقفال — راجع الأسطر ثم احفظ لإغلاق السنة.',
                        'voucher_id' => $vid,
                        'redirect' => storefront_public_path('/admin/index.php?page=year_end_close_vouchers&id=' . $vid),
                    ]);
                }
                $closedAtUtc = orange_admin_time_utc_now_mysql();
                $u = $fyScoped
                    ? $pdo->prepare('UPDATE fiscal_years SET is_closed = 1, closed_at = ? WHERE id = ? AND country_id = ? AND is_closed = 0')
                    : $pdo->prepare('UPDATE fiscal_years SET is_closed = 1, closed_at = ? WHERE id = ? AND is_closed = 0');
                $fyScoped ? $u->execute([$closedAtUtc, $id, $ctxCountryId]) : $u->execute([$closedAtUtc, $id]);
                if ($u->rowCount() === 0) {
                    $pdo->rollBack();
                    json_response(['success' => false, 'message' => 'تعذر إغلاق السنة (غير موجودة أو مغلقة مسبقاً)'], 422);
                }
                $pdo->commit();
                audit_log('fiscal_year_close', 'إغلاق سنة #' . $id . ' بلا قيود YEC (لا إيراد/مصروف)', 'fiscal_years', $id);
                json_response([
                    'success' => true,
                    'message' => 'لا توجد إيرادات/مصروفات للإقفال — تم إغلاق السنة إدارياً.',
                ]);
            }
            $closedAtUtc = orange_admin_time_utc_now_mysql();
            $u = $fyScoped
                ? $pdo->prepare('UPDATE fiscal_years SET is_closed = 1, closed_at = ? WHERE id = ? AND country_id = ? AND is_closed = 0')
                : $pdo->prepare('UPDATE fiscal_years SET is_closed = 1, closed_at = ? WHERE id = ? AND is_closed = 0');
            $fyScoped ? $u->execute([$closedAtUtc, $id, $ctxCountryId]) : $u->execute([$closedAtUtc, $id]);
            if ($u->rowCount() === 0) {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => 'تعذر إغلاق السنة (غير موجودة أو مغلقة مسبقاً)'], 422);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        audit_log('fiscal_year_close', 'تم إغلاق سنة مالية رقم: ' . $id, 'fiscal_years', $id);
        json_response([
            'success' => true,
            'message' => 'تم إغلاق السنة إدارياً دون قيود إقفال تلقائية.',
        ]);
    }

    if ($action === 'reopen') {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            json_response(['success' => false, 'message' => 'معرف السنة مطلوب'], 422);
        }
        try {
            $info = orange_fiscal_year_reopen($pdo, $id, $ctxCountryId);
        } catch (Throwable $e) {
            if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) {
                json_response(['success' => false, 'message' => $e->getMessage()], 422);
            }
            orange_gl_api_catch_json($e, 'تعذر فك إقفال السنة');
        }
        $n = (int) ($info['voided_year_end_vouchers'] ?? 0);
        $vid = (int) ($info['voucher_id'] ?? 0);
        audit_log('fiscal_year_reopen', 'فك إقفال سنة #' . $id . ' — void YEC: ' . $n, 'fiscal_years', $id);
        $hint = ' راجع أرصدة أول المدة للسنة التالية إن كانت مُرحَّلة.';
        $redirect = $vid > 0
            ? storefront_public_path('/admin/index.php?page=year_end_close_vouchers&id=' . $vid)
            : null;
        json_response([
            'success' => true,
            'message' => ($n > 0
                ? 'تم إلغاء ' . $n . ' سند إقفال (void) وإعادة فتح السنة — صحّح السند ثم احفظ من جديد.'
                : 'تم إعادة فتح السنة (إغلاق إداري أو بلا قيود).')
                . $hint,
            'voided_year_end_vouchers' => $n,
            'voucher_id' => $vid,
            'redirect' => $redirect,
        ]);
    }

    if ($action === 'create') {
        $label = trim((string) ($data['label_ar'] ?? ''));
        $start = trim((string) ($data['start_date'] ?? ''));
        $end = trim((string) ($data['end_date'] ?? ''));
        if ($label === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            json_response(['success' => false, 'message' => 'الاسم وتاريخ البداية والنهاية مطلوبة بصيغة YYYY-MM-DD'], 422);
        }
        if ($start > $end) {
            json_response(['success' => false, 'message' => 'تاريخ البداية يجب أن يكون قبل أو يساوي نهاية السنة'], 422);
        }
        if (orange_fiscal_range_overlaps_existing($pdo, $start, $end, null, $ctxCountryId)) {
            json_response(['success' => false, 'message' => 'الفترة تتقاطع مع سنة مالية أخرى — عدّل التواريخ'], 422);
        }
        if ($fyScoped) {
            $ins = $pdo->prepare('INSERT INTO fiscal_years (country_id, label_ar, start_date, end_date, is_closed) VALUES (?, ?, ?, ?, 0)');
            $ins->execute([$ctxCountryId, $label, $start, $end]);
        } else {
            $ins = $pdo->prepare('INSERT INTO fiscal_years (label_ar, start_date, end_date, is_closed) VALUES (?, ?, ?, 0)');
            $ins->execute([$label, $start, $end]);
        }
        $newId = (int) $pdo->lastInsertId();
        audit_log('fiscal_year_create', 'تم إنشاء سنة مالية: ' . $label, 'fiscal_years', $newId);
        json_response(['success' => true, 'message' => 'تم إنشاء السنة المالية', 'id' => $newId]);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_gl_api_catch_json($e, 'تعذر حفظ السنة المالية');
}
