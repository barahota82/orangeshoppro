<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();

    $stmt = $pdo->prepare("UPDATE departments SET is_active = ? WHERE id = ?");
    $stmt->execute([
        (int)($data['is_active'] ?? 0),
        (int)($data['id'] ?? 0)
    ]);

    json_response(['success' => true, 'message' => 'تم تحديث حالة القسم']);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
