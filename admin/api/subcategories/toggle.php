<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_table_exists($pdo, 'subcategories')) {
        json_response(['success' => false, 'message' => 'E_NO_TABLE'], 500);
    }

    $data = get_json_input();

    $stmt = $pdo->prepare('UPDATE subcategories SET is_active = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
    $stmt->execute([
        (int)($data['is_active'] ?? 0),
        (int)($data['id'] ?? 0),
    ]);

    json_response(['success' => true, 'message' => 'OK_TOG']);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
