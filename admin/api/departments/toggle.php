<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/department_countries.php';
require_admin_api();

try {
    orange_department_countries_require_global_admin();
    $pdo = db();
    $data = get_json_input();

    $stmt = $pdo->prepare('UPDATE departments SET is_active = ? WHERE id = ?');
    $stmt->execute([
        (int) ($data['is_active'] ?? 0),
        (int) ($data['id'] ?? 0),
    ]);

    json_response(['success' => true, 'message' => 'تم تحديث حالة القسم في الكتالوج العام']);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحديث حالة القسم');
}
