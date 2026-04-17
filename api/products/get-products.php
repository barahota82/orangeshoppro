<?php
require_once __DIR__ . '/../../config.php';

try {
    $pdo = db();

    $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
    $channelId = isset($_GET['channel_id']) ? (int)$_GET['channel_id'] : 0;

    $sql = "
        SELECT p.*
        FROM products p
        INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
    ";

    $params = [];
    $where = ["p.is_active = 1"];

    if ($channelId > 0) {
        $sql .= " INNER JOIN product_channels pc ON pc.product_id = p.id ";
        $where[] = "pc.channel_id = ?";
        $params[] = $channelId;
    }

    if ($categoryId > 0) {
        $where[] = "p.category_id = ?";
        $params[] = $categoryId;
    }

    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
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
