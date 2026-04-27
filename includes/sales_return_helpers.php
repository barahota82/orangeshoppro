<?php

declare(strict_types=1);

require_once __DIR__ . '/purchase_helpers.php';

/**
 * صافي سطر مردود مبيعات (سعر × كمية − خصم السطر).
 */
function orange_sales_return_line_net(array $item): float
{
    $qty = (int) ($item['qty'] ?? 0);
    $price = (float) ($item['price'] ?? 0);
    $disc = (float) ($item['line_discount'] ?? 0);
    if ($qty <= 0 || $price < 0 || $disc < 0) {
        return 0.0;
    }

    return round(max(0.0, ($qty * $price) - $disc), 4);
}

/**
 * تكلفة الوحدة للتكلفة: من الطلب أو من المنتج.
 */
function orange_sales_return_resolve_unit_cost(PDO $pdo, int $productId, float $requestCost): float
{
    if ($requestCost >= 0 && $requestCost > 0.0001) {
        return round($requestCost, 4);
    }
    $st = $pdo->prepare('SELECT COALESCE(cost, 0) FROM products WHERE id = ? LIMIT 1');
    $st->execute([$productId]);
    $c = (float) $st->fetchColumn();

    return round(max(0.0, $c), 4);
}

/**
 * زيادة المخزون عند قبول مرتجع من عميل.
 */
function orange_sales_return_add_line_stock(PDO $pdo, int $productId, int $variantId, int $qty): void
{
    if ($productId <= 0 || $variantId <= 0 || $qty <= 0) {
        throw new RuntimeException('بيانات سطر مردود مخزون غير صالحة');
    }
    $pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id = ? AND product_id = ?')
        ->execute([$qty, $variantId, $productId]);
}

/**
 * عكس زيادة المخزون عند حذف/تعديل مردود.
 *
 * @throws RuntimeException
 */
function orange_sales_return_undo_line_stock(PDO $pdo, int $productId, int $variantId, int $qty): void
{
    if ($productId <= 0 || $variantId <= 0 || $qty <= 0) {
        return;
    }
    $vStmt = $pdo->prepare(
        'SELECT stock_quantity FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1 FOR UPDATE'
    );
    $vStmt->execute([$variantId, $productId]);
    $oldStock = (int) $vStmt->fetchColumn();
    if ($oldStock < $qty) {
        throw new RuntimeException(
            'لا يمكن عكس مردود المبيعات: رصيد المخزون (' . $oldStock . ') أقل من الكمية المعادة (' . $qty . ').'
        );
    }
    $pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity - ? WHERE id = ? AND product_id = ?')
        ->execute([$qty, $variantId, $productId]);
}
