<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $productId = (int) ($data['id'] ?? 0);
    if ($productId <= 0) {
        json_response(['success' => false, 'message' => 'معرف المنتج مطلوب'], 422);
    }
    try {
        orange_admin_assert_entity_country($pdo, 'products', $productId);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    $stmt = $pdo->prepare('UPDATE products SET is_active = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([
        (int)($data['is_active'] ?? 0),
        $productId,
    ]);

    json_response(['success' => true, 'message' => 'OK_TOG']);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحديث حالة المنتج');
}
