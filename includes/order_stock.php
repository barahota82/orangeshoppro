<?php

declare(strict_types=1);

require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/warehouses.php';

/**
 * Stock reference key shared by reservation / fulfillment / release (matches journal ref prefix).
 */
function orange_order_stock_reference(string $orderNumber): string
{
    return 'ORDER-' . $orderNumber;
}

function orange_order_has_pending_stock_reservation(PDO $pdo, string $orderNumber): bool
{
    $ref = orange_order_stock_reference($orderNumber);
    $stmt = $pdo->prepare(
        "SELECT 1 FROM stock_movements WHERE reference = ? AND type = 'pending_order' LIMIT 1"
    );
    $stmt->execute([$ref]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Decrement variant stock for a web/WhatsApp checkout (pending order). Idempotent per order reference.
 *
 * @param array<int,array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 */
function orange_order_apply_pending_stock_reservation(
    PDO $pdo,
    string $orderNumber,
    array $validatedItems,
    ?int $countryId = null,
    ?int $warehouseId = null
): void {
    $ref = orange_order_stock_reference($orderNumber);
    if (orange_order_has_pending_stock_reservation($pdo, $orderNumber)) {
        return;
    }

    require_once __DIR__ . '/countries.php';
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_storefront_current_country_id($pdo);
    }
    if ($warehouseId === null || $warehouseId <= 0) {
        $warehouseId = orange_warehouse_default_id_for_country($pdo, $countryId);
    }

    foreach ($validatedItems as $row) {
        $vid = (int)($row['variant_id'] ?? 0);
        if ($vid <= 0) {
            continue;
        }
        $qty = (int)$row['qty'];
        if ($qty <= 0) {
            continue;
        }

        $stockChange = orange_warehouse_apply_variant_delta($pdo, $warehouseId, $vid, -$qty, 0);
        orange_stock_movement_insert($pdo, [
            'product_id' => (int)$row['product']['id'],
            'variant_id' => $vid,
            'type' => 'pending_order',
            'qty' => $qty,
            'old_stock' => $stockChange['old'],
            'new_stock' => $stockChange['new'],
            'reason' => 'Checkout reserve',
            'reference' => $ref,
            'country_id' => $countryId,
            'warehouse_id' => $warehouseId > 0 ? $warehouseId : null,
        ]);
    }
}

/**
 * When a pending (web) order is cancelled or rejected, return reserved quantities.
 */
function orange_order_release_pending_stock_reservation(PDO $pdo, array $order): void
{
    $orderNumber = (string)($order['order_number'] ?? '');
    if ($orderNumber === '') {
        return;
    }
    $ref = orange_order_stock_reference($orderNumber);
    $chk = $pdo->prepare(
        "SELECT 1 FROM stock_movements WHERE reference = ? AND type = 'pending_order' LIMIT 1"
    );
    $chk->execute([$ref]);
    if (!$chk->fetchColumn()) {
        return;
    }

    require_once __DIR__ . '/countries.php';
    $countryId = isset($order['country_id']) ? (int) $order['country_id'] : 0;
    $warehouseId = isset($order['warehouse_id']) ? (int) $order['warehouse_id'] : 0;
    if ($countryId <= 0 && isset($order['channel_id'])) {
        $countryId = orange_country_id_for_channel($pdo, (int) $order['channel_id']);
    }
    if ($countryId <= 0) {
        $countryId = orange_storefront_current_country_id($pdo);
    }
    if ($warehouseId <= 0) {
        $warehouseId = orange_warehouse_default_id_for_country($pdo, $countryId);
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $itemsStmt->execute([(int)$order['id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($items as $item) {
        $variant = orange_order_resolve_variant_from_item($pdo, $item);
        if (!$variant) {
            continue;
        }
        $vid = (int)$variant['id'];
        $qty = (int)$item['qty'];
        $stockChange = orange_warehouse_apply_variant_delta($pdo, $warehouseId, $vid, $qty, 0);
        orange_stock_movement_insert($pdo, [
            'product_id' => (int)$item['product_id'],
            'variant_id' => $vid,
            'type' => 'order_release',
            'qty' => $qty,
            'old_stock' => $stockChange['old'],
            'new_stock' => $stockChange['new'],
            'reason' => 'Order cancelled / rejected',
            'reference' => $ref,
            'country_id' => $countryId,
            'warehouse_id' => $warehouseId > 0 ? $warehouseId : null,
        ]);
    }

    $pdo->prepare(
        "UPDATE stock_movements SET type = 'pending_order_void' WHERE reference = ? AND type = 'pending_order'"
    )->execute([$ref]);
}

/**
 * طلبات لديها حركات مخزون type = pending_order (حجز نشط) — س28.
 *
 * @return list<array<string,mixed>>
 */
function orange_admin_orders_with_pending_stock_reservations(PDO $pdo, ?int $countryId = null): array
{
    if (!orange_table_exists($pdo, 'stock_movements') || !orange_table_exists($pdo, 'orders')) {
        return [];
    }
    require_once __DIR__ . '/countries.php';
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_admin_context_country_id($pdo);
    }
    try {
        $sql = '
            SELECT o.*, c.name AS channel_name,
                (
                    SELECT COALESCE(SUM(s.qty), 0)
                    FROM stock_movements s
                    WHERE s.reference = CONCAT(\'ORDER-\', o.order_number)
                      AND s.type = \'pending_order\'
                ) AS reserved_qty
            FROM orders o
            LEFT JOIN channels c ON c.id = o.channel_id
            WHERE EXISTS (
                SELECT 1 FROM stock_movements sm
                WHERE sm.reference = CONCAT(\'ORDER-\', o.order_number)
                  AND sm.type = \'pending_order\'
                LIMIT 1
            )';
        $params = [];
        $countryFilter = orange_sql_filter_country_id($pdo, 'orders', 'o', $countryId);
        if ($countryFilter !== null) {
            $sql .= $countryFilter['sql'];
            $params[] = $countryFilter['param'];
        }
        $sql .= ' ORDER BY o.created_at DESC, o.id DESC';
        if ($params === []) {
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_admin_orders_with_pending_stock_reservations: ' . $e->getMessage());
        }

        return [];
    }
}

function orange_stock_movement_type_label_ar(string $type): string
{
    static $map = [
        'pending_order' => 'حجز مخزون (طلب ويب)',
        'pending_order_fulfilled' => 'إغلاق حجز بعد التسليم',
        'pending_order_void' => 'إلغاء حجز طلب',
        'delivered_order' => 'تسليم طلب — صرف مخزون',
        'delivered_order_void' => 'عكس تسليم يدوي',
        'order_return' => 'إرجاع مخزون — إلغاء تسليم',
        'order_release' => 'إرجاع كمية محجوزة',
        'manual_adjustment' => 'تعديل يدوي',
        'opening_balance' => 'رصيد افتتاحي',
    ];
    $type = trim($type);

    return $map[$type] ?? $type;
}
