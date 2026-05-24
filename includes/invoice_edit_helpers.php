<?php

declare(strict_types=1);

require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/order_stock.php';
require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/warehouses.php';
require_once __DIR__ . '/cart_promotions.php';
require_once __DIR__ . '/cart_combo_promotions.php';
require_once __DIR__ . '/storefront_checkout_promo_lines.php';
require_once __DIR__ . '/order_intake_queue.php';
require_once __DIR__ . '/storefront_account.php';
require_once __DIR__ . '/order_fulfillment.php';

/**
 * @return list<string>
 */
function orange_invoice_edit_allowed_statuses(): array
{
    return ['approved', 'on_the_way'];
}

function orange_invoice_edit_is_gift_line(PDO $pdo, array $item, array $order): bool
{
    $price = (float) ($item['price'] ?? 0);
    if ($price <= 0.00001) {
        return true;
    }
    $vid = (int) ($item['variant_id'] ?? 0);
    if ($vid <= 0) {
        return false;
    }
    if (orange_table_has_column($pdo, 'orders', 'cart_gift_variant_id')) {
        if ($vid === (int) ($order['cart_gift_variant_id'] ?? 0)) {
            return true;
        }
    }
    if (orange_table_has_column($pdo, 'orders', 'cart_bogo_gift_variant_id')) {
        if ($vid === (int) ($order['cart_bogo_gift_variant_id'] ?? 0)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<int, array<string, mixed>> $items
 * @return list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}>
 */
function orange_invoice_edit_paid_lines_to_validated(PDO $pdo, array $items, array $order): array
{
    $out = [];
    foreach ($items as $item) {
        if (orange_invoice_edit_is_gift_line($pdo, $item, $order)) {
            continue;
        }
        $variant = orange_order_resolve_variant_from_item($pdo, $item);
        if (!$variant) {
            continue;
        }
        $pid = (int) ($item['product_id'] ?? $variant['product_id'] ?? 0);
        $prodSt = $pdo->prepare('SELECT id, name FROM products WHERE id = ? LIMIT 1');
        $prodSt->execute([$pid]);
        $prod = $prodSt->fetch(PDO::FETCH_ASSOC);
        if (!$prod) {
            continue;
        }
        $out[] = [
            'product' => ['id' => (int) $prod['id'], 'name' => (string) ($prod['name'] ?? $item['product_name'] ?? '')],
            'qty' => (int) ($item['qty'] ?? 0),
            'color' => (string) ($item['color'] ?? ''),
            'size' => (string) ($item['size'] ?? ''),
            'variant_id' => (int) $variant['id'],
            'price' => (float) ($item['price'] ?? 0),
            'cost' => (float) ($item['cost'] ?? 0),
        ];
    }

    return $out;
}

function orange_invoice_edit_return_stock_for_qty(
    PDO $pdo,
    array $order,
    array $item,
    int $qtyReturn
): void {
    if ($qtyReturn <= 0) {
        return;
    }
    $variant = orange_order_resolve_variant_from_item($pdo, $item);
    if (!$variant) {
        return;
    }
    $stockCtx = orange_warehouse_context_for_order($pdo, $order);
    $ref = orange_order_stock_reference((string) ($order['order_number'] ?? ''));
    $stockChange = orange_warehouse_apply_variant_delta(
        $pdo,
        $stockCtx['warehouse_id'],
        (int) $variant['id'],
        $qtyReturn,
        0
    );
    orange_stock_movement_insert($pdo, [
        'product_id' => (int) ($item['product_id'] ?? 0),
        'variant_id' => (int) $variant['id'],
        'type' => 'order_amend_release',
        'qty' => $qtyReturn,
        'old_stock' => $stockChange['old'],
        'new_stock' => $stockChange['new'],
        'reason' => 'Invoice edit — partial return',
        'reference' => $ref,
        'country_id' => $stockCtx['country_id'],
        'warehouse_id' => $stockCtx['warehouse_id'],
    ]);
}

/**
 * @param array<int, array{item_id:int, qty:int}> $changes
 * @return array{total:float, promo_summary:array<string,mixed>}
 */
function orange_invoice_edit_apply(PDO $pdo, int $orderId, array $changes, bool $markCompleted): array
{
    orange_catalog_ensure_schema($pdo);

    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $st->execute([$orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('الطلب غير موجود');
    }
    $status = (string) ($order['status'] ?? '');
    if (!in_array($status, orange_invoice_edit_allowed_statuses(), true)) {
        throw new RuntimeException('الطلب غير مؤهل للتعديل — الحالة: ' . $status);
    }
    $src = trim((string) ($order['order_source'] ?? 'website'));
    if ($src === 'company') {
        throw new RuntimeException('تعديل الفاتورة للطلبات الأونلاين فقط');
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
    $itemsStmt->execute([$orderId]);
    $allItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byId = [];
    foreach ($allItems as $row) {
        $byId[(int) ($row['id'] ?? 0)] = $row;
    }

    foreach ($changes as $chg) {
        $itemId = (int) ($chg['item_id'] ?? 0);
        $newQty = (int) ($chg['qty'] ?? 0);
        if ($itemId <= 0 || !isset($byId[$itemId])) {
            continue;
        }
        $item = $byId[$itemId];
        if (orange_invoice_edit_is_gift_line($pdo, $item, $order)) {
            throw new RuntimeException('لا يمكن تعديل سطر الهدية مباشرة — عدّل البنود المدفوعة');
        }
        $oldQty = (int) ($item['qty'] ?? 0);
        if ($newQty < 0) {
            $newQty = 0;
        }
        if ($newQty < $oldQty) {
            orange_invoice_edit_return_stock_for_qty($pdo, $order, $item, $oldQty - $newQty);
        }
        if ($newQty === 0) {
            $pdo->prepare('DELETE FROM order_items WHERE id = ? AND order_id = ?')->execute([$itemId, $orderId]);
            unset($byId[$itemId]);
        } elseif ($newQty !== $oldQty) {
            $pdo->prepare('UPDATE order_items SET qty = ? WHERE id = ? AND order_id = ?')
                ->execute([$newQty, $itemId, $orderId]);
            $byId[$itemId]['qty'] = $newQty;
        }
    }

    // Remove gift/BOGO lines before recalc
    foreach ($byId as $id => $item) {
        if (orange_invoice_edit_is_gift_line($pdo, $item, $order)) {
            orange_invoice_edit_return_stock_for_qty($pdo, $order, $item, (int) ($item['qty'] ?? 0));
            $pdo->prepare('DELETE FROM order_items WHERE id = ?')->execute([$id]);
            unset($byId[$id]);
        }
    }

    $paidValidated = orange_invoice_edit_paid_lines_to_validated($pdo, array_values($byId), $order);
    if ($paidValidated === []) {
        throw new RuntimeException('يجب أن يبقى بند مدفوع واحد على الأقل');
    }

    $buyerRegistered = false;
    if (orange_table_has_column($pdo, 'orders', 'storefront_account_id')) {
        $buyerRegistered = (int) ($order['storefront_account_id'] ?? 0) > 0;
    }

    $subtotal = 0.0;
    foreach ($paidValidated as $row) {
        $subtotal = round($subtotal + (float) $row['price'] * (int) $row['qty'], 4);
    }

    $countryId = (int) ($order['country_id'] ?? 0);
    $comboPick = orange_cart_combo_best_match($pdo, $paidValidated, $buyerRegistered, $countryId > 0 ? $countryId : null);
    $comboDiscount = $comboPick !== null ? (float) $comboPick['discount'] : 0.0;
    $comboId = $comboPick !== null ? (int) $comboPick['id'] : null;
    $netAfterCombo = max(0.0, round($subtotal - $comboDiscount, 4));
    $promoPick = orange_cart_promotion_resolve($pdo, $netAfterCombo, $buyerRegistered, $countryId > 0 ? $countryId : null);
    $promoDiscount = $promoPick !== null ? (float) $promoPick['discount'] : 0.0;
    $promoId = $promoPick !== null ? (int) $promoPick['id'] : null;
    $orderTotal = max(0.0, round($netAfterCombo - $promoDiscount, 4));

    $payload = [
        'gift_variant_id' => (int) ($order['cart_gift_variant_id'] ?? 0),
        'bogo_gift_variant_id' => (int) ($order['cart_bogo_gift_variant_id'] ?? 0),
    ];
    $promoBundle = orange_storefront_build_promotional_gift_lines(
        $pdo,
        $payload,
        $paidValidated,
        $subtotal,
        $buyerRegistered,
        $countryId > 0 ? $countryId : null
    );
    $giftLine = $promoBundle['giftLine'];
    $giftPromoId = $promoBundle['giftPromoId'];
    $giftVariantId = $promoBundle['giftVariantId'];
    $bogoLine = $promoBundle['bogoLine'];
    $bogoPromoId = $promoBundle['bogoPromoId'];
    $bogoGiftVariantId = $promoBundle['bogoGiftVariantId'];

    if ($giftLine !== null) {
        orange_storefront_insert_order_items_for_order($pdo, $orderId, [$giftLine]);
    }
    if ($bogoLine !== null) {
        orange_storefront_insert_order_items_for_order($pdo, $orderId, [$bogoLine]);
    }

    $setParts = ['total = ?', 'updated_at = NOW()'];
    $updParams = [$orderTotal];
    if (orange_table_has_column($pdo, 'orders', 'cart_combo_promotion_id')) {
        $setParts[] = 'cart_combo_promotion_id = ?';
        $setParts[] = 'cart_combo_discount = ?';
        $updParams[] = $comboId > 0 ? $comboId : null;
        $updParams[] = $comboDiscount;
    }
    if (orange_table_has_column($pdo, 'orders', 'cart_promotion_id')) {
        $setParts[] = 'cart_promotion_id = ?';
        $setParts[] = 'cart_promotion_discount = ?';
        $updParams[] = $promoId > 0 ? $promoId : null;
        $updParams[] = $promoDiscount;
    }
    if (orange_table_has_column($pdo, 'orders', 'cart_gift_promotion_id')) {
        $setParts[] = 'cart_gift_promotion_id = ?';
        $setParts[] = 'cart_gift_variant_id = ?';
        $updParams[] = $giftPromoId > 0 ? $giftPromoId : null;
        $updParams[] = $giftVariantId > 0 ? $giftVariantId : null;
    }
    if (orange_table_has_column($pdo, 'orders', 'cart_bogo_promotion_id')) {
        $setParts[] = 'cart_bogo_promotion_id = ?';
        $setParts[] = 'cart_bogo_gift_variant_id = ?';
        $updParams[] = $bogoPromoId > 0 ? $bogoPromoId : null;
        $updParams[] = $bogoGiftVariantId > 0 ? $bogoGiftVariantId : null;
    }
    $updParams[] = $orderId;
    $pdo->prepare('UPDATE orders SET ' . implode(', ', $setParts) . ' WHERE id = ?')->execute($updParams);

    if ($markCompleted) {
        $pdo->prepare('UPDATE orders SET status = ?, completed_at = NOW() WHERE id = ?')
            ->execute(['completed', $orderId]);
        orange_complete_order_fulfillment($pdo, $orderId);
    }

    return [
        'total' => $orderTotal,
        'promo_summary' => [
            'subtotal' => $subtotal,
            'combo_discount' => $comboDiscount,
            'cart_promotion_discount' => $promoDiscount,
            'has_gift' => $giftLine !== null,
            'has_bogo' => $bogoLine !== null,
        ],
    ];
}
