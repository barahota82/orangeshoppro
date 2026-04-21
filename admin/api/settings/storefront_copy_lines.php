<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';

require_admin_api();

const STOREFRONT_COPY_SCOPES = ['home_hero', 'header_tagline'];

/**
 * @return array<string, mixed>
 */
function storefront_copy_req_data(): array
{
    $data = get_json_input();
    if (is_array($data) && count($data) > 0) {
        return $data;
    }

    return $_POST;
}

/** @param mixed $v */
function storefront_copy_norm_scope($v): ?string
{
    $s = trim((string) $v);

    return in_array($s, STOREFRONT_COPY_SCOPES, true) ? $s : null;
}

/** @param mixed $v */
function storefront_copy_line_str($v): string
{
    $s = trim((string) $v);

    return mb_substr($s, 0, 500, 'UTF-8');
}

function storefront_copy_next_sort_order(PDO $pdo, string $scope): int
{
    $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM storefront_copy_lines WHERE scope = ?');
    $st->execute([$scope]);

    return (int) $st->fetchColumn() + 1;
}

/**
 * @return list<int>
 */
function storefront_copy_ordered_ids_for_scope(PDO $pdo, string $scope): array
{
    $st = $pdo->prepare('SELECT id FROM storefront_copy_lines WHERE scope = ? ORDER BY sort_order ASC, id ASC');
    $st->execute([$scope]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = (int) ($r['id'] ?? 0);
    }

    return array_values(array_filter($out, static fn (int $id): bool => $id > 0));
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_table_exists($pdo, 'storefront_copy_lines')) {
        json_response(['success' => false, 'message' => 'جدول storefront_copy_lines غير جاهز'], 422);
    }

    $data = storefront_copy_req_data();
    $action = trim((string) ($data['action'] ?? ''));

    if ($action === 'list') {
        $scope = storefront_copy_norm_scope($data['scope'] ?? '');
        if ($scope === null) {
            json_response(['success' => false, 'message' => 'نطاق غير صالح'], 422);
        }
        $st = $pdo->prepare(
            'SELECT * FROM storefront_copy_lines WHERE scope = ? ORDER BY sort_order ASC, id ASC'
        );
        $st->execute([$scope]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        json_response(['success' => true, 'data' => is_array($rows) ? $rows : []]);
    }

    if ($action === 'save') {
        $scope = storefront_copy_norm_scope($data['scope'] ?? '');
        if ($scope === null) {
            json_response(['success' => false, 'message' => 'نطاق غير صالح'], 422);
        }
        $id = (int) ($data['id'] ?? 0);
        $isActive = (int) ($data['is_active'] ?? 1) === 0 ? 0 : 1;
        $textAr = storefront_copy_line_str($data['text_ar'] ?? '');
        $textEn = storefront_copy_line_str($data['text_en'] ?? '');
        $textFil = storefront_copy_line_str($data['text_fil'] ?? '');
        $textHi = storefront_copy_line_str($data['text_hi'] ?? '');
        if ($textAr === '' && $textEn === '' && $textFil === '' && $textHi === '') {
            json_response(['success' => false, 'message' => 'أدخل نصاً بلغة واحدة على الأقل'], 422);
        }

        if ($id > 0) {
            $chk = $pdo->prepare('SELECT id, sort_order FROM storefront_copy_lines WHERE id = ? AND scope = ? LIMIT 1');
            $chk->execute([$id, $scope]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if (!is_array($existing)) {
                json_response(['success' => false, 'message' => 'السجل غير موجود'], 404);
            }
            $sort = (int) ($existing['sort_order'] ?? 0);
            $st = $pdo->prepare(
                'UPDATE storefront_copy_lines SET sort_order = ?, is_active = ?, text_ar = ?, text_en = ?, text_fil = ?, text_hi = ? WHERE id = ? AND scope = ?'
            );
            $st->execute([$sort, $isActive, $textAr, $textEn, $textFil, $textHi, $id, $scope]);
            json_response(['success' => true, 'message' => 'تم حفظ التعديلات', 'id' => $id]);
        }

        $sort = storefront_copy_next_sort_order($pdo, $scope);
        $st = $pdo->prepare(
            'INSERT INTO storefront_copy_lines (scope, sort_order, is_active, text_ar, text_en, text_fil, text_hi) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$scope, $sort, $isActive, $textAr, $textEn, $textFil, $textHi]);
        $newId = (int) $pdo->lastInsertId();
        json_response(['success' => true, 'message' => 'تمت الإضافة', 'id' => $newId]);
    }

    if ($action === 'move') {
        $scope = storefront_copy_norm_scope($data['scope'] ?? '');
        $id = (int) ($data['id'] ?? 0);
        $dir = trim((string) ($data['direction'] ?? ''));
        if ($scope === null || $id <= 0 || ($dir !== 'up' && $dir !== 'down')) {
            json_response(['success' => false, 'message' => 'بيانات غير صالحة'], 422);
        }
        $ids = storefront_copy_ordered_ids_for_scope($pdo, $scope);
        if ($ids === []) {
            json_response(['success' => false, 'message' => 'لا توجد بيانات'], 404);
        }
        $idx = null;
        foreach ($ids as $i => $rowId) {
            if ($rowId === $id) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            json_response(['success' => false, 'message' => 'السجل غير موجود'], 404);
        }
        $swapIdx = $dir === 'up' ? $idx - 1 : $idx + 1;
        if ($swapIdx < 0 || $swapIdx >= count($ids)) {
            json_response(['success' => false, 'message' => 'لا يمكن النقل في هذا الاتجاه'], 422);
        }
        $tmp = $ids[$idx];
        $ids[$idx] = $ids[$swapIdx];
        $ids[$swapIdx] = $tmp;
        $pdo->beginTransaction();
        try {
            $u = $pdo->prepare('UPDATE storefront_copy_lines SET sort_order = ? WHERE id = ? AND scope = ?');
            foreach ($ids as $i => $rowId) {
                $u->execute([$i + 1, $rowId, $scope]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        json_response(['success' => true, 'message' => 'تم تحديث الترتيب']);
    }

    if ($action === 'delete') {
        $id = (int) ($data['id'] ?? 0);
        $scope = storefront_copy_norm_scope($data['scope'] ?? '');
        if ($id <= 0 || $scope === null) {
            json_response(['success' => false, 'message' => 'بيانات غير صالحة'], 422);
        }
        $st = $pdo->prepare('DELETE FROM storefront_copy_lines WHERE id = ? AND scope = ?');
        $st->execute([$id, $scope]);
        json_response(['success' => true, 'message' => 'تم الحذف']);
    }

    if ($action === 'toggle') {
        $id = (int) ($data['id'] ?? 0);
        $scope = storefront_copy_norm_scope($data['scope'] ?? '');
        $isActive = (int) ($data['is_active'] ?? -1);
        if ($id <= 0 || $scope === null || ($isActive !== 0 && $isActive !== 1)) {
            json_response(['success' => false, 'message' => 'بيانات غير صالحة'], 422);
        }
        $chk = $pdo->prepare('SELECT id FROM storefront_copy_lines WHERE id = ? AND scope = ? LIMIT 1');
        $chk->execute([$id, $scope]);
        if (!$chk->fetch()) {
            json_response(['success' => false, 'message' => 'السجل غير موجود'], 404);
        }
        $st = $pdo->prepare('UPDATE storefront_copy_lines SET is_active = ? WHERE id = ? AND scope = ?');
        $st->execute([$isActive, $id, $scope]);
        json_response([
            'success' => true,
            'message' => $isActive === 1 ? 'تم التفعيل' : 'تم الإخفاء',
            'is_active' => $isActive,
        ]);
    }

    json_response(['success' => false, 'message' => 'إجراء غير مدعوم'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ نصوص الواجهة');
}
