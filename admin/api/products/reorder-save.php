<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $ids = $data['ordered_ids'] ?? [];
    if (!is_array($ids) || count($ids) === 0) {
        json_response(['success' => false, 'message' => 'E_REORDER'], 422);
    }

    $pdo->beginTransaction();
    $u = $pdo->prepare('UPDATE products SET sort_order = ? WHERE id = ?');
    $sort = 1;
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $u->execute([$sort, $id]);
            $sort++;
        }
    }
    $pdo->commit();
    json_response(['success' => true, 'message' => 'OK_REORDER']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
