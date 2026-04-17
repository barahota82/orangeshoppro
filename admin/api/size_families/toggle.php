<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $pdo->prepare('UPDATE size_families SET is_active = ? WHERE id = ? LIMIT 1')->execute([
        (int) ($data['is_active'] ?? 0),
        (int) ($data['id'] ?? 0),
    ]);
    json_response(['success' => true, 'message' => 'تم تحديث حالة العائلة']);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
