<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

    $sql = "
        SELECT p.*
        FROM products p
        INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
        WHERE p.is_active = 1
    ";

    $params = [];
    if ($categoryId > 0) {
        $sql .= " AND p.category_id = ?";
        $params[] = $categoryId;
    }

    $sql .= ' ORDER BY p.sort_order ASC, p.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $products = $stmt->fetchAll();

    json_response([
        'success' => true,
        'products' => $products
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}
