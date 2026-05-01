<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_unified_product_helpers.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        json_response(['success' => false, 'code' => 'product_invalid_id', 'message' => t('product_invalid_id')], 422);
    }

    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$id]);
    $product = $stmt->fetch();

    if (
        $product !== false
        && is_array($product)
        && !orange_storefront_product_in_active_unified_chain($pdo, $id)
    ) {
        $product = false;
    }

    if (!$product) {
        json_response(['success' => false, 'code' => 'product_not_found', 'message' => t('product_not_found')], 404);
    }

    $imagesStmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY id ASC");
    $imagesStmt->execute([$id]);
    $images = $imagesStmt->fetchAll();

    $variantsStmt = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = ? ORDER BY color ASC, size ASC, id ASC");
    $variantsStmt->execute([$id]);
    $variants = $variantsStmt->fetchAll();

    json_response([
        'success' => true,
        'product' => $product,
        'images' => $images,
        'variants' => $variants
    ]);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
