<?php
require_once __DIR__ . '/../../config.php';

try {
    $pdo = db();

    $sql = "
        SELECT c.*
        FROM categories c
        WHERE c.is_active = 1
          AND EXISTS (
              SELECT 1
              FROM products p
              WHERE p.category_id = c.id
                AND p.is_active = 1
          )
        ORDER BY c.sort_order ASC, c.id ASC
    ";

    $categories = $pdo->query($sql)->fetchAll();

    json_response([
        'success' => true,
        'categories' => $categories
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}
