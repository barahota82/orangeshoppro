<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();
    $productId = (int)($data['id'] ?? 0);

    if ($productId <= 0) {
        json_response(['success' => false, 'message' => 'معرف المنتج مطلوب'], 422);
    }

    $pdo->beginTransaction();

    $productStmt = $pdo->prepare("SELECT id FROM products WHERE id = ? LIMIT 1");
    $productStmt->execute([$productId]);
    if (!$productStmt->fetch()) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'المنتج غير موجود'], 404);
    }

    $pdo->prepare("DELETE FROM offers WHERE product_id = ?")->execute([$productId]);
    $pdo->prepare("DELETE FROM product_channels WHERE product_id = ?")->execute([$productId]);
    $pdo->prepare("DELETE FROM product_images WHERE product_id = ?")->execute([$productId]);
    $pdo->prepare("DELETE FROM stock_movements WHERE product_id = ?")->execute([$productId]);
    $pdo->prepare("DELETE FROM product_variants WHERE product_id = ?")->execute([$productId]);
    $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$productId]);

    $pdo->commit();
    audit_log('product_delete', 'تم حذف المنتج رقم: ' . $productId, 'products', $productId);
    json_response(['success' => true, 'message' => 'تم حذف المنتج']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e, 'تعذر حذف المنتج');
}
