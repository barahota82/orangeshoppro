<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/fiscal_years.php';
require_once __DIR__ . '/../../../includes/year_end_close.php';
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

    if ($action === 'delete') {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            json_response(['success' => false, 'message' => 'معرف السنة مطلوب'], 422);
        }
        if (orange_fiscal_year_has_journal_activity($pdo, $id)) {
            json_response(['success' => false, 'message' => 'لا يمكن حذف سنة عليها قيود أو مستندات'], 422);
        }
        $d = $pdo->prepare('DELETE FROM fiscal_years WHERE id = ?');
        $d->execute([$id]);
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
            $selPrev = $pdo->prepare('SELECT is_closed, closed_at FROM fiscal_years WHERE id = ? LIMIT 1');
            $ins = $pdo->prepare('INSERT INTO fiscal_years (label_ar, start_date, end_date, is_closed) VALUES (?, ?, ?, ?)');
            $upd = $pdo->prepare('UPDATE fiscal_years SET label_ar = ?, start_date = ?, end_date = ?, is_closed = ?, closed_at = ? WHERE id = ?');

            foreach ($normalized as $row) {
                $id = (int) $row['id'];
                $isClosed = (int) $row['is_closed'];
                $closedAt = null;

                if ($id > 0) {
                    if (orange_fiscal_range_overlaps_existing($pdo, $row['start_date'], $row['end_date'], $id)) {
                        $pdo->rollBack();
                        json_response(['success' => false, 'message' => 'فترة تتقاطع مع سنة أخرى في القاعدة'], 422);
                    }
                    $selPrev->execute([$id]);
                    $prev = $selPrev->fetch(PDO::FETCH_ASSOC);
                    if (! $prev) {
                        $pdo->rollBack();
                        json_response(['success' => false, 'message' => 'سنة غير موجودة: #' . $id], 422);
                    }
                    $wasClosed = (int) ($prev['is_closed'] ?? 0) === 1;
                    if ($isClosed === 1 && ! $wasClosed) {
                        $closedAt = date('Y-m-d H:i:s');
                    } elseif ($isClosed === 1 && $wasClosed) {
                        $closedAt = $prev['closed_at'] ?: date('Y-m-d H:i:s');
                    } else {
                        $closedAt = null;
                    }
                    $upd->execute([
                        $row['label_ar'],
                        $row['start_date'],
                        $row['end_date'],
                        $isClosed,
                        $closedAt,
                        $id,
                    ]);
                } else {
                    if (orange_fiscal_range_overlaps_existing($pdo, $row['start_date'], $row['end_date'], null)) {
                        $pdo->rollBack();
                        json_response(['success' => false, 'message' => 'فترة تتقاطع مع سنة أخرى في القاعدة'], 422);
                    }
                    $ins->execute([
                        $row['label_ar'],
                        $row['start_date'],
                        $row['end_date'],
                        $isClosed,
                    ]);
                    if ($isClosed === 1) {
                        $newId = (int) $pdo->lastInsertId();
                        $pdo->prepare('UPDATE fiscal_years SET closed_at = NOW() WHERE id = ?')->execute([$newId]);
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

        $pdo->beginTransaction();
        try {
            if ($accountingClose) {
                orange_fiscal_year_end_accounting_close($pdo, $id);
            }
            $u = $pdo->prepare('UPDATE fiscal_years SET is_closed = 1, closed_at = NOW() WHERE id = ? AND is_closed = 0');
            $u->execute([$id]);
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
            'message' => $accountingClose
                ? 'تم الإقفال المحاسبي (إن وُجدت إيرادات/مصروفات مصنفة) ثم إغلاق السنة.'
                : 'تم إغلاق السنة إدارياً دون قيود إقفال تلقائية.',
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
        if (orange_fiscal_range_overlaps_existing($pdo, $start, $end, null)) {
            json_response(['success' => false, 'message' => 'الفترة تتقاطع مع سنة مالية أخرى — عدّل التواريخ'], 422);
        }
        $ins = $pdo->prepare('INSERT INTO fiscal_years (label_ar, start_date, end_date, is_closed) VALUES (?, ?, ?, 0)');
        $ins->execute([$label, $start, $end]);
        $newId = (int) $pdo->lastInsertId();
        audit_log('fiscal_year_create', 'تم إنشاء سنة مالية: ' . $label, 'fiscal_years', $newId);
        json_response(['success' => true, 'message' => 'تم إنشاء السنة المالية', 'id' => $newId]);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    api_error($e, 'تعذر حفظ السنة المالية');
}
