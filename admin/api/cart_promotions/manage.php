<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/cart_promotions.php';
require_admin_api();

/**
 * @param mixed $v
 */
function cp_money($v): float
{
    $f = (float) $v;

    return $f >= 0 ? round($f, 4) : 0.0;
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $data = get_json_input();
    if (!is_array($data) || count($data) === 0) {
        $data = $_POST;
    }
    $action = trim((string) ($data['action'] ?? 'list'));

    if (!orange_table_exists($pdo, 'cart_promotions')) {
        json_response(['success' => false, 'message' => 'جدول cart_promotions غير جاهز'], 422);
    }

    if ($action === 'list') {
        json_response(['success' => true, 'data' => orange_cart_promotions_admin_list($pdo)]);
    }

    if ($action === 'save') {
        $id = (int) ($data['id'] ?? 0);
        $minSub = cp_money($data['min_subtotal'] ?? 0);
        $disc = cp_money($data['discount_amount'] ?? 0);
        $reqReg = !empty($data['requires_registered_account']) ? 1 : 0;
        $sortOrder = (int) ($data['sort_order'] ?? 0);
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if ($minSub <= 0) {
            json_response(['success' => false, 'message' => 'الحد الأدنى للمجموع يجب أن يكون أكبر من صفر'], 422);
        }
        if ($disc <= 0) {
            json_response(['success' => false, 'message' => 'قيمة الخصم يجب أن تكون أكبر من صفر'], 422);
        }
        if ($disc > $minSub + 0.00001) {
            json_response(['success' => false, 'message' => 'الخصم لا يجب أن يتجاوز الحد الأدنى للمجموع'], 422);
        }

        if ($id > 0) {
            $st = $pdo->prepare(
                'UPDATE cart_promotions SET min_subtotal = ?, discount_amount = ?, requires_registered_account = ?, sort_order = ?, is_active = ? WHERE id = ?'
            );
            $st->execute([$minSub, $disc, $reqReg, $sortOrder, $isActive, $id]);
        } else {
            $st = $pdo->prepare(
                'INSERT INTO cart_promotions (min_subtotal, discount_amount, requires_registered_account, sort_order, is_active) VALUES (?, ?, ?, ?, ?)'
            );
            $st->execute([$minSub, $disc, $reqReg, $sortOrder, $isActive]);
        }

        json_response(['success' => true, 'message' => 'تم حفظ عرض السلة']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ عرض السلة');
}
