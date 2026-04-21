<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/delivery_areas.php';
require_admin_api();

/**
 * @param mixed $v
 */
function da_str191($v): string
{
    $s = trim((string) $v);

    return function_exists('mb_substr') ? mb_substr($s, 0, 191, 'UTF-8') : substr($s, 0, 191);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $data = get_json_input();
    if (!is_array($data) || count($data) === 0) {
        $data = $_POST;
    }
    $action = trim((string) ($data['action'] ?? 'list'));

    if (!orange_table_exists($pdo, 'delivery_areas')) {
        json_response(['success' => false, 'message' => 'جدول delivery_areas غير جاهز'], 422);
    }

    if ($action === 'list') {
        json_response(['success' => true, 'data' => orange_delivery_areas_admin_list($pdo)]);
    }

    if ($action === 'save') {
        $id = (int) ($data['id'] ?? 0);
        $nameAr = da_str191($data['name_ar'] ?? '');
        $nameEn = da_str191($data['name_en'] ?? '');
        $sortOrder = (int) ($data['sort_order'] ?? 0);
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if ($nameAr === '') {
            json_response(['success' => false, 'message' => 'اسم المنطقة بالعربي مطلوب'], 422);
        }

        if ($id > 0) {
            $st = $pdo->prepare(
                'UPDATE delivery_areas SET name_ar = ?, name_en = ?, sort_order = ?, is_active = ? WHERE id = ?'
            );
            $st->execute([$nameAr, $nameEn, $sortOrder, $isActive, $id]);
        } else {
            $st = $pdo->prepare(
                'INSERT INTO delivery_areas (name_ar, name_en, sort_order, is_active) VALUES (?, ?, ?, ?)'
            );
            $st->execute([$nameAr, $nameEn, $sortOrder, $isActive]);
        }

        json_response(['success' => true, 'message' => 'تم حفظ المنطقة']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ المنطقة');
}
