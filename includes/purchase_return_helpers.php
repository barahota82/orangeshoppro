<?php

declare(strict_types=1);

require_once __DIR__ . '/purchase_helpers.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/warehouses.php';

/**
 * خفض مخزون المتغير عند تسجيل مردود مشتريات (مخزن دولة المنتج).
 *
 * @throws RuntimeException
 */
function orange_purchase_return_apply_line_stock(PDO $pdo, int $productId, int $variantId, int $qty): void
{
    if ($productId <= 0 || $variantId <= 0 || $qty <= 0) {
        throw new RuntimeException('بيانات سطر مردود غير صالحة');
    }
    $countryId = orange_product_country_id($pdo, $productId);
    $warehouseId = orange_warehouse_default_id_for_country($pdo, $countryId);
    if ($warehouseId > 0 && orange_warehouses_table_exists($pdo)) {
        orange_warehouse_apply_variant_delta($pdo, $warehouseId, $variantId, -$qty, 0);

        return;
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
    $countryId = orange_product_country_id($pdo, $productId);
    $warehouseId = orange_warehouse_default_id_for_country($pdo, $countryId);
    if ($warehouseId > 0 && orange_warehouses_table_exists($pdo)) {
        orange_warehouse_apply_variant_delta($pdo, $warehouseId, $variantId, $qty, 0);

        return;
    }
    $pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id = ? AND product_id = ?')
        ->execute([$qty, $variantId, $productId]);
}
