<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/product_delete_policy.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $productId = (int) ($data['id'] ?? 0);

    if ($productId <= 0) {
        json_response(['success' => false, 'message' => 'معرف المنتج مطلوب'], 422);
    }

    $productStmt = $pdo->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
    $productStmt->execute([$productId]);
    if (!$productStmt->fetch()) {
        json_response(['success' => false, 'message' => 'المنتج غير موجود'], 404);
    }

    try {
        orange_admin_assert_entity_country($pdo, 'products', $productId);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    $blockReasons = orange_product_delete_history_block_reasons($pdo, $productId);
    if ($blockReasons !== []) {
        json_response([
            'success' => false,
            'code' => 'product_has_history',
            'message' => orange_product_delete_history_block_message(),
            'reasons' => $blockReasons,
        ], 422);
    }

    $pdo->beginTransaction();
    orange_product_delete_catalog_hard($pdo, $productId);
    $pdo->commit();

    audit_log('product_delete', 'تم حذف المنتج رقم: ' . $productId, 'products', $productId);
    json_response(['success' => true, 'message' => 'تم حذف المنتج']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر حذف المنتج');
}
