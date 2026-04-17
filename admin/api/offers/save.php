<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();

    if (empty($data['product_id']) || !isset($data['discount'])) {
        json_response(['success' => false, 'message' => 'بيانات العرض مطلوبة'], 422);
    }

    $stmt = $pdo->prepare("
        INSERT INTO offers (product_id, discount, is_active)
        VALUES (?, ?, 1)
    ");
    $stmt->execute([
        (int)$data['product_id'],
        (float)$data['discount']
    ]);

    json_response(['success' => true, 'message' => 'تم حفظ العرض']);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
