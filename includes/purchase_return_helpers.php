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

function orange_purchase_return_line_key(int $productId, int $variantId): string
{
    return $productId . ':' . $variantId;
}

/**
 * @return array<string, int> مفتاح product_id:variant_id => مجموع الكميات المُرجَعة سابقاً
 */
function orange_purchase_return_returned_qty_map(PDO $pdo, int $purchaseId, ?int $excludeReturnId = null): array
{
    if ($purchaseId <= 0 || !orange_table_exists($pdo, 'purchase_returns') || !orange_table_exists($pdo, 'purchase_return_items')) {
        return [];
    }

    $hasVariant = orange_table_has_column($pdo, 'purchase_return_items', 'variant_id');
    $sql = 'SELECT pri.product_id, ' . ($hasVariant ? 'COALESCE(pri.variant_id, 0)' : '0') . ' AS variant_id, SUM(pri.qty) AS qty_sum
        FROM purchase_return_items pri
        INNER JOIN purchase_returns pr ON pr.id = pri.purchase_return_id
        WHERE pr.purchase_id = ?';
    $params = [$purchaseId];
    if ($excludeReturnId !== null && $excludeReturnId > 0) {
        $sql .= ' AND pr.id <> ?';
        $params[] = $excludeReturnId;
    }
    $sql .= ' GROUP BY pri.product_id, variant_id';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $map = [];
    foreach ($rows as $row) {
        $key = orange_purchase_return_line_key(
            (int) ($row['product_id'] ?? 0),
            (int) ($row['variant_id'] ?? 0)
        );
        $map[$key] = (int) ($row['qty_sum'] ?? 0);
    }

    return $map;
}

/**
 * @return array<string, int> مفتاح product_id:variant_id => كمية الشراء الأصلية
 */
function orange_purchase_purchased_qty_map(PDO $pdo, int $purchaseId): array
{
    if ($purchaseId <= 0) {
        return [];
    }

    $hasVariant = orange_table_has_column($pdo, 'purchase_items', 'variant_id');
    $sql = 'SELECT product_id, ' . ($hasVariant ? 'COALESCE(variant_id, 0)' : '0') . ' AS variant_id, qty
        FROM purchase_items WHERE purchase_id = ? ORDER BY id ASC';
    $st = $pdo->prepare($sql);
    $st->execute([$purchaseId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $map = [];
    foreach ($rows as $row) {
        $key = orange_purchase_return_line_key(
            (int) ($row['product_id'] ?? 0),
            (int) ($row['variant_id'] ?? 0)
        );
        $map[$key] = ($map[$key] ?? 0) + (int) ($row['qty'] ?? 0);
    }

    return $map;
}

function orange_purchase_return_parse_discount_amount(string $discountRaw, float $lineGross): float
{
    $discountRaw = trim($discountRaw);
    if ($discountRaw === '' || $lineGross <= 0) {
        return 0.0;
    }

    $discountAmt = 0.0;
    if (str_ends_with($discountRaw, '%')) {
        $pct = (float) rtrim($discountRaw, '%');
        $discountAmt = round($lineGross * $pct / 100, 4);
    } else {
        $discountAmt = round((float) $discountRaw, 4);
    }
    if ($discountAmt < 0) {
        $discountAmt = 0.0;
    }
    if ($discountAmt > $lineGross) {
        $discountAmt = $lineGross;
    }

    return $discountAmt;
}

/**
 * @param list<array{product_id:int,variant_id?:int,qty:int}> $items
 *
 * @throws RuntimeException
 */
function orange_purchase_return_assert_qty_against_purchase(PDO $pdo, int $purchaseId, array $items): void
{
    if ($purchaseId <= 0 || $items === []) {
        return;
    }

    $purchased = orange_purchase_purchased_qty_map($pdo, $purchaseId);
    if ($purchased === []) {
        throw new RuntimeException('فاتورة الشراء المرجعية بلا أصناف');
    }

    $returned = orange_purchase_return_returned_qty_map($pdo, $purchaseId);
    $requested = [];

    foreach ($items as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            continue;
        }
        $variantId = orange_purchase_resolve_variant_id(
            $pdo,
            $productId,
            (int) ($item['variant_id'] ?? 0)
        );
        $key = orange_purchase_return_line_key($productId, $variantId);
        $requested[$key] = ($requested[$key] ?? 0) + $qty;
    }

    foreach ($requested as $key => $reqQty) {
        $purchasedQty = (int) ($purchased[$key] ?? 0);
        if ($purchasedQty <= 0) {
            throw new RuntimeException('صنف في المردود غير موجود في فاتورة الشراء المرجعية');
        }
        $alreadyReturned = (int) ($returned[$key] ?? 0);
        $available = max(0, $purchasedQty - $alreadyReturned);
        if ($reqQty > $available) {
            throw new RuntimeException(
                'الكمية المرجعة (' . $reqQty . ') تتجاوز المتاح (' . $available . ') للصنف #' . explode(':', $key)[0]
            );
        }
    }
}
