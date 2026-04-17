<?php
require_once __DIR__ . '/../../config.php';

try {
    $pdo = db();

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

    $sql .= " ORDER BY p.id DESC";

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
