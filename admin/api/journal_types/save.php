<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/journal_types.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'journal_types')) {
        json_response(['success' => false, 'message' => 'جدول أنواع اليوميات غير متوفر'], 500);
    }

    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? ''));

    if ($action === 'delete') {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            json_response(['success' => false, 'message' => 'المعرف مطلوب'], 422);
        }
        $d = $pdo->prepare('DELETE FROM journal_types WHERE id = ?');
        $d->execute([$id]);
        if ($d->rowCount() === 0) {
            json_response(['success' => false, 'message' => 'السجل غير موجود'], 404);
        }
        audit_log('journal_type_delete', 'حذف نوع يومية #' . $id, 'journal_types', $id);
        json_response(['success' => true, 'message' => 'تم الحذف']);

        return;
    }

    if ($action === 'save_rows') {
        $rows = $data['rows'] ?? [];
        if (!is_array($rows)) {
            json_response(['success' => false, 'message' => 'بيانات غير صالحة'], 422);
        }

        $normalized = [];
        $seenCodes = [];
        foreach ($rows as $i => $r) {
            if (!is_array($r)) {
                continue;
            }
            $id = (int) ($r['id'] ?? 0);
            $code = orange_journal_type_normalize_code((string) ($r['code'] ?? ''));
            $nameAr = trim((string) ($r['name_ar'] ?? ''));
            $nameEn = trim((string) ($r['name_en'] ?? ''));
            if ($id <= 0 && $code === '' && $nameAr === '' && $nameEn === '') {
                continue;
            }
            $rowNum = $i + 1;
            if ($code === '') {
                json_response(['success' => false, 'message' => 'ترميز الكود مطلوب (صف ' . $rowNum . ')'], 422);
            }
            if (strlen($code) < 2 || strlen($code) > 32) {
                json_response(['success' => false, 'message' => 'ترميز الكود من حرفين إلى 32 (صف ' . $rowNum . ')'], 422);
            }
            if ($nameAr === '' || $nameEn === '') {
                json_response(['success' => false, 'message' => 'الاسم العربي والإنجليزي مطلوبان (صف ' . $rowNum . ')'], 422);
            }
            $lc = strtolower($code);
            if (isset($seenCodes[$lc])) {
                json_response(['success' => false, 'message' => 'ترميز «' . $code . '» مكرر في الجدول'], 422);
            }
            $seenCodes[$lc] = true;
            $normalized[] = [
                'id' => $id,
                'code' => $code,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
            ];
        }

        if ($normalized === []) {
            json_response(['success' => false, 'message' => 'لا توجد صفوف صالحة للحفظ'], 422);
        }

        $dupOther = $pdo->prepare('SELECT id FROM journal_types WHERE code = ? AND id <> ? LIMIT 1');
        $dupAny = $pdo->prepare('SELECT id FROM journal_types WHERE code = ? LIMIT 1');

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO journal_types (code, name_ar, name_en, sort_order) VALUES (?,?,?,?)'
            );
            $upd = $pdo->prepare(
                'UPDATE journal_types SET code = ?, name_ar = ?, name_en = ?, sort_order = ? WHERE id = ?'
            );

            foreach ($normalized as $idx => $row) {
                $ord = $idx + 1;
                $id = (int) $row['id'];
                $code = $row['code'];
                if ($id > 0) {
                    $dupOther->execute([$code, $id]);
                    if ($dupOther->fetch()) {
                        $pdo->rollBack();
                        json_response(['success' => false, 'message' => 'الترميز «' . $code . '» مستخدم في سجل آخر'], 422);
                    }
                    $upd->execute([$code, $row['name_ar'], $row['name_en'], $ord, $id]);
                    if ($upd->rowCount() === 0) {
                        $pdo->rollBack();
                        json_response(['success' => false, 'message' => 'سجل غير موجود للتحديث: #' . $id], 422);
                    }
                } else {
                    $dupAny->execute([$code]);
                    if ($dupAny->fetch()) {
                        $pdo->rollBack();
                        json_response(['success' => false, 'message' => 'الترميز «' . $code . '» موجود مسبقاً'], 422);
                    }
                    $ins->execute([$code, $row['name_ar'], $row['name_en'], $ord]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException && isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) {
                json_response(['success' => false, 'message' => 'ترميز مكرر — راجع أعمدة الكود'], 422);
            }
            throw $e;
        }

        audit_log('journal_type_batch_save', 'تحديث أنواع اليوميات', 'journal_types', null);
        json_response(['success' => true, 'message' => 'تم حفظ أنواع اليوميات']);

        return;
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    api_error($e, 'تعذر حفظ أنواع اليوميات');
}
