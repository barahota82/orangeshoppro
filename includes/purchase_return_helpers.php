<?php

declare(strict_types=1);

require_once __DIR__ . '/purchase_helpers.php';

/**
 * خفض مخزون المتغير عند تسجيل مردود مشتريات.
 *
 * @throws RuntimeException
 */
function orange_purchase_return_apply_line_stock(PDO $pdo, int $productId, int $variantId, int $qty): void
{
    if ($productId <= 0 || $variantId <= 0 || $qty <= 0) {
        throw new RuntimeException('بيانات سطر مردود غير صالحة');
    }
    $vStmt = $pdo->prepare(
        'SELECT stock_quantity FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1 FOR UPDATE'
    );
    $vStmt->execute([$variantId, $productId]);
    $oldStock = (int) $vStmt->fetchColumn();
    if ($oldStock < $qty) {
        throw new RuntimeException(
            'الكمية المرجعة (' . $qty . ') تتجاوز رصيد المخزون المتاح (' . $oldStock . ') للمتغير #' . $variantId
        );
    }
    $pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity - ? WHERE id = ? AND product_id = ?')
        ->execute([$qty, $variantId, $productId]);
}

/**
 * إعادة المخزون عند حذف/تعديل مردود (عكس التسجيل).
 */
function orange_purchase_return_restore_line_stock(PDO $pdo, int $productId, int $variantId, int $qty): void
{
    if ($productId <= 0 || $variantId <= 0 || $qty <= 0) {
        return;
    }
    $pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id = ? AND product_id = ?')
        ->execute([$qty, $variantId, $productId]);
}
