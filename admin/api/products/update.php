<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();

    $productId = (int)($data['id'] ?? 0);
    if ($productId <= 0) {
        json_response(['success' => false, 'message' => 'معرف المنتج مطلوب'], 422);
    }

    if (empty($data['name']) || empty($data['category_id']) || !isset($data['price']) || !isset($data['cost'])) {
        json_response(['success' => false, 'message' => 'البيانات الأساسية مطلوبة'], 422);
    }

    $stmt = $pdo->prepare("
        UPDATE products
        SET name = ?, description = ?, category_id = ?, price = ?, cost = ?,
            main_image = ?, has_sizes = ?, has_colors = ?, is_active = ?, updated_at = NOW()
        WHERE id = ?
    ");

    $stmt->execute([
        trim((string)$data['name']),
        trim((string)($data['description'] ?? '')),
        (int)$data['category_id'],
        (float)$data['price'],
        (float)$data['cost'],
        trim((string)($data['main_image'] ?? '')),
        (int)($data['has_sizes'] ?? 0),
        (int)($data['has_colors'] ?? 0),
        isset($data['is_active']) ? (int)$data['is_active'] : 1,
        $productId
    ]);

    if ($stmt->rowCount() === 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM products WHERE id = ? LIMIT 1");
        $checkStmt->execute([$productId]);
        if (!$checkStmt->fetch()) {
            json_response(['success' => false, 'message' => 'المنتج غير موجود'], 404);
        }
    }

    audit_log('product_update', 'تم تحديث المنتج رقم: ' . $productId, 'products', $productId);
    json_response(['success' => true, 'message' => 'تم تحديث المنتج']);
} catch (Throwable $e) {
    api_error($e, 'تعذر تحديث المنتج');
}
