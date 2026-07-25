<?php

declare(strict_types=1);

require_once __DIR__ . '/purchase_helpers.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/warehouses.php';

/**
 * دولة سجل مردود المبيعات للوقت/العرض: country_id المباشر إن وُجد، وإلا من Order الأصلي.
 * 0 = غير قابلة للإثبات → Fail Closed عند العرض.
 */
function orange_sales_return_authority_country_id(PDO $pdo, array $header): int
{
    $direct = (int) ($header['country_id'] ?? 0);
    if ($direct > 0) {
        return $direct;
    }
    $orderId = (int) ($header['order_id'] ?? 0);
    if ($orderId > 0 && orange_table_has_country_id($pdo, 'orders')) {
        $st = $pdo->prepare('SELECT country_id FROM orders WHERE id = ? LIMIT 1');
        $st->execute([$orderId]);
        $cid = (int) ($st->fetchColumn() ?: 0);
        if ($cid > 0) {
            return $cid;
        }
    }

    return 0;
}

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
 * تكلفة الوحدة للتكلفة: من الطلب، وإلا المصدر الموثوق COALESCE(تكلفة المتغيّر، تكلفة المنتج).
 */
function orange_sales_return_resolve_unit_cost(PDO $pdo, int $productId, float $requestCost, int $variantId = 0): float
{
    if ($requestCost >= 0 && $requestCost > 0.0001) {
        return round($requestCost, 4);
    }
    if ($variantId > 0 && orange_table_has_column($pdo, 'product_variants', 'cost')) {
        $stv = $pdo->prepare(
            'SELECT COALESCE(pv.cost, p.cost, 0) FROM product_variants pv
             INNER JOIN products p ON p.id = pv.product_id
             WHERE pv.id = ? AND pv.product_id = ? LIMIT 1'
        );
        $stv->execute([$variantId, $productId]);
        $cv = $stv->fetchColumn();
        if ($cv !== false && $cv !== null) {
            return round(max(0.0, (float) $cv), 4);
        }
    }
    $st = $pdo->prepare('SELECT COALESCE(cost, 0) FROM products WHERE id = ? LIMIT 1');
    $st->execute([$productId]);
    $c = (float) $st->fetchColumn();

    return round(max(0.0, $c), 4);
}

/**
 * زيادة المخزون عند قبول مرتجع من عميل (مخزن دولة المنتج).
 */
function orange_sales_return_add_line_stock(PDO $pdo, int $productId, int $variantId, int $qty): void
{
    if ($productId <= 0 || $variantId <= 0 || $qty <= 0) {
        throw new RuntimeException('بيانات سطر مردود مخزون غير صالحة');
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
            'لا يمكن عكس مردود المبيعات: رصيد المخزون (' . $oldStock . ') أقل من الكمية المعادة (' . $qty . ').'
        );
    }
    $pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity - ? WHERE id = ? AND product_id = ?')
        ->execute([$qty, $variantId, $productId]);
}

function orange_sales_return_line_key(int $productId, int $variantId): string
{
    return $productId . ':' . $variantId;
}

/**
 * @return array<string, int> مفتاح product_id:variant_id => مجموع الكميات المُرجَعة سابقاً لنفس الطلب
 */
function orange_sales_return_returned_qty_map(PDO $pdo, int $orderId, ?int $excludeReturnId = null): array
{
    if ($orderId <= 0 || !orange_table_exists($pdo, 'sales_returns') || !orange_table_exists($pdo, 'sales_return_items')) {
        return [];
    }

    $hasVariant = orange_table_has_column($pdo, 'sales_return_items', 'variant_id');
    $sql = 'SELECT sri.product_id, ' . ($hasVariant ? 'COALESCE(sri.variant_id, 0)' : '0') . ' AS variant_id, SUM(sri.qty) AS qty_sum
        FROM sales_return_items sri
        INNER JOIN sales_returns sr ON sr.id = sri.sales_return_id
        WHERE sr.order_id = ?';
    $params = [$orderId];
    if ($excludeReturnId !== null && $excludeReturnId > 0) {
        $sql .= ' AND sr.id <> ?';
        $params[] = $excludeReturnId;
    }
    $sql .= ' GROUP BY sri.product_id, variant_id';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $map = [];
    foreach ($rows as $row) {
        $key = orange_sales_return_line_key(
            (int) ($row['product_id'] ?? 0),
            (int) ($row['variant_id'] ?? 0)
        );
        $map[$key] = (int) ($row['qty_sum'] ?? 0);
    }

    return $map;
}

/**
 * @return array<string, int> مفتاح product_id:variant_id => كمية مباعة في الطلب
 */
function orange_sales_return_sold_qty_map(PDO $pdo, int $orderId): array
{
    if ($orderId <= 0 || !orange_table_exists($pdo, 'order_items')) {
        return [];
    }

    $hasVariant = orange_table_has_column($pdo, 'order_items', 'variant_id');
    $sql = 'SELECT product_id, ' . ($hasVariant ? 'COALESCE(variant_id, 0)' : '0') . ' AS variant_id, qty
        FROM order_items WHERE order_id = ? ORDER BY id ASC';
    $st = $pdo->prepare($sql);
    $st->execute([$orderId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $map = [];
    foreach ($rows as $row) {
        $productId = (int) ($row['product_id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }
        $key = orange_sales_return_line_key(
            $productId,
            (int) ($row['variant_id'] ?? 0)
        );
        $map[$key] = ($map[$key] ?? 0) + (int) ($row['qty'] ?? 0);
    }

    return $map;
}

/**
 * قناة مردود المبيعات من طلب مرجعي (نقدي / أونلاين / آجل).
 */
function orange_sales_return_channel_from_order(array $order): string
{
    $terms = strtolower(trim((string) ($order['payment_terms'] ?? 'cash')));
    if ($terms === 'credit') {
        return 'credit';
    }
    $src = strtolower(trim((string) ($order['order_source'] ?? '')));
    if (in_array($src, ['website', 'online', 'storefront'], true)) {
        return 'online';
    }

    return 'cash';
}

/**
 * @throws RuntimeException
 */
function orange_sales_return_lock_reference_order(PDO $pdo, int $orderId): void
{
    if ($orderId <= 0) {
        return;
    }

    $st = $pdo->prepare('SELECT id FROM orders WHERE id = ? LIMIT 1 FOR UPDATE');
    $st->execute([$orderId]);
    if (!$st->fetch()) {
        throw new RuntimeException('الطلب المرجعي غير موجود');
    }
}

/**
 * @param list<array{product_id:int,variant_id?:int,qty:int}> $items
 *
 * @throws RuntimeException
 */
function orange_sales_return_assert_qty_against_order(
    PDO $pdo,
    int $orderId,
    array $items,
    ?int $excludeReturnId = null
): void {
    if ($orderId <= 0 || $items === []) {
        return;
    }

    $sold = orange_sales_return_sold_qty_map($pdo, $orderId);
    if ($sold === []) {
        throw new RuntimeException('الطلب المرجعي بلا أصناف');
    }

    $returned = orange_sales_return_returned_qty_map($pdo, $orderId, $excludeReturnId);
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
        $key = orange_sales_return_line_key($productId, $variantId);
        $requested[$key] = ($requested[$key] ?? 0) + $qty;
    }

    foreach ($requested as $key => $reqQty) {
        $soldQty = (int) ($sold[$key] ?? 0);
        if ($soldQty <= 0) {
            throw new RuntimeException('صنف في المردود غير موجود في الطلب المرجعي');
        }
        $alreadyReturned = (int) ($returned[$key] ?? 0);
        $available = max(0, $soldQty - $alreadyReturned);
        if ($reqQty > $available) {
            throw new RuntimeException(
                'الكمية المرجعة (' . $reqQty . ') تتجاوز المتاح (' . $available . ') للصنف #' . explode(':', $key)[0]
            );
        }
    }
}
