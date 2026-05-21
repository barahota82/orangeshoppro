<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    if (empty($data['product_id']) || !isset($data['discount'])) {
        json_response(['success' => false, 'message' => 'بيانات العرض مطلوبة'], 422);
    }

    $pid = (int) $data['product_id'];
    $ch = $pdo->prepare('SELECT id FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
    $ch->execute([$pid]);
    if (!$ch->fetchColumn()) {
        json_response(['success' => false, 'message' => 'المنتج غير موجود أو غير نشط'], 422);
    }
    try {
        orange_admin_assert_entity_country($pdo, 'products', $pid);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }
    if (!orange_storefront_product_in_active_unified_chain($pdo, $pid)) {
        json_response([
            'success' => false,
            'message' => 'المنتج غير ضمن الكتالوج الموحّد النشط — لا يمكن ربط عرض به في الوضع الحالي.',
        ], 422);
    }

    $stmt = $pdo->prepare("
        INSERT INTO offers (product_id, discount, is_active)
        VALUES (?, ?, 1)
    ");
    $stmt->execute([
        $pid,
        (float)$data['discount']
    ]);

    json_response(['success' => true, 'message' => 'تم حفظ العرض']);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ العرض');
}
