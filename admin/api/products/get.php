<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api('GET');

try {
    $pdo = db();
    $productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($productId > 0) {
        $stmt = $pdo->prepare("
            SELECT p.*, c.name_ar AS category_name_ar, c.name_en AS category_name_en
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE p.id = ?
            LIMIT 1
        ");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (!$product) {
            json_response(['success' => false, 'message' => 'المنتج غير موجود'], 404);
        }

        $variantStmt = $pdo->prepare("
            SELECT id, product_id, size, color, stock_quantity
            FROM product_variants
            WHERE product_id = ?
            ORDER BY id ASC
        ");
        $variantStmt->execute([$productId]);
        $product['variants'] = $variantStmt->fetchAll();

        json_response(['success' => true, 'product' => $product]);
    }

    $rows = $pdo->query("
        SELECT p.*, c.name_ar AS category_name_ar, c.name_en AS category_name_en
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        ORDER BY p.id DESC
    ")->fetchAll();

    json_response(['success' => true, 'products' => $rows]);
} catch (Throwable $e) {
    api_error($e, 'تعذر تحميل المنتجات');
}
