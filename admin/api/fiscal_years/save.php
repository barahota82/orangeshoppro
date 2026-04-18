<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/fiscal_years.php';
require_once __DIR__ . '/../../../includes/year_end_close.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'fiscal_years')) {
        json_response(['success' => false, 'message' => 'جدول السنوات المالية غير متوفر'], 500);
    }

    $data = get_json_input();
    $action = trim((string)($data['action'] ?? ''));

    if ($action === 'close') {
        $id = (int)($data['id'] ?? 0);
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
        $label = trim((string)($data['label_ar'] ?? ''));
        $start = trim((string)($data['start_date'] ?? ''));
        $end = trim((string)($data['end_date'] ?? ''));
        if ($label === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
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
        $newId = (int)$pdo->lastInsertId();
        audit_log('fiscal_year_create', 'تم إنشاء سنة مالية: ' . $label, 'fiscal_years', $newId);
        json_response(['success' => true, 'message' => 'تم إنشاء السنة المالية', 'id' => $newId]);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    api_error($e, 'تعذر حفظ السنة المالية');
}
