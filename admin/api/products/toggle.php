<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();

    $stmt = $pdo->prepare('UPDATE products SET is_active = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([
        (int)($data['is_active'] ?? 0),
        (int)($data['id'] ?? 0),
    ]);

    json_response(['success' => true, 'message' => 'OK_TOG']);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحديث حالة المنتج');
}
