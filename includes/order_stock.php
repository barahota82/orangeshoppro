<?php

declare(strict_types=1);

require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/catalog_schema.php';

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
 * Policy + triggers: docs/archive/ORANGE_STOCK_ORDER_POLICY.txt §2 — called from
 * `orange_storefront_execute_checkout_payload()` (includes/order_intake_queue.php) and from
 * `api/orders/amend-order-items.php` after `orange_order_release_pending_stock_reservation()`.
 *
 * @param array<int,array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 */
function orange_order_apply_pending_stock_reservation(PDO $pdo, string $orderNumber, array $validatedItems): void
{
    $ref = orange_order_stock_reference($orderNumber);
    if (orange_order_has_pending_stock_reservation($pdo, $orderNumber)) {
        return;
    }

    $moveStmt = $pdo->prepare("
        INSERT INTO stock_movements (
            product_id, variant_id, type, qty, old_stock, new_stock, reason, created_at, reference
        ) VALUES (
            ?, ?, 'pending_order', ?, ?, ?, 'Checkout reserve', NOW(), ?
        )
    ");

    foreach ($validatedItems as $row) {
        $vid = (int)($row['variant_id'] ?? 0);
        if ($vid <= 0) {
            continue;
        }
        $qty = (int)$row['qty'];
        if ($qty <= 0) {
            continue;
        }

        $vStmt = $pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = ? LIMIT 1 FOR UPDATE');
        $vStmt->execute([$vid]);
        $oldStock = (int)$vStmt->fetchColumn();
        if ($oldStock < $qty) {
            throw new RuntimeException('Insufficient stock for product: ' . (string)($row['product']['name'] ?? ''));
        }
        $newStock = $oldStock - $qty;

        $upd = $pdo->prepare(
            'UPDATE product_variants SET stock_quantity = ? WHERE id = ? AND stock_quantity >= ?'
        );
        $upd->execute([$newStock, $vid, $qty]);
        if ($upd->rowCount() !== 1) {
            throw new RuntimeException('Stock update failed for product: ' . (string)($row['product']['name'] ?? ''));
        }

        $moveStmt->execute([
            (int)$row['product']['id'],
            $vid,
            $qty,
            $oldStock,
            $newStock,
            $ref,
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

    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $itemsStmt->execute([(int)$order['id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $moveStmt = $pdo->prepare("
        INSERT INTO stock_movements (
            product_id, variant_id, type, qty, old_stock, new_stock, reason, created_at, reference
        ) VALUES (
            ?, ?, 'order_release', ?, ?, ?, 'Order cancelled / rejected', NOW(), ?
        )
    ");

    foreach ($items as $item) {
        $variant = orange_order_resolve_variant_from_item($pdo, $item);
        if (!$variant) {
            continue;
        }
        $vid = (int)$variant['id'];
        $qty = (int)$item['qty'];
        $vStmt = $pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = ? LIMIT 1 FOR UPDATE');
        $vStmt->execute([$vid]);
        $oldStock = (int)$vStmt->fetchColumn();
        $newStock = $oldStock + $qty;

        $pdo->prepare('UPDATE product_variants SET stock_quantity = ? WHERE id = ?')->execute([$newStock, $vid]);

        $moveStmt->execute([
            (int)$item['product_id'],
            $vid,
            $qty,
            $oldStock,
            $newStock,
            $ref,
        ]);
    }

    $pdo->prepare(
        "UPDATE stock_movements SET type = 'pending_order_void' WHERE reference = ? AND type = 'pending_order'"
    )->execute([$ref]);
}

/**
 * تسمية عربية لنوع حركة المخزون (للعرض في الواجهات).
 */
/**
 * طلبات لديها حركات مخزون type = pending_order (حجز نشط) — س28.
 *
 * @return list<array<string,mixed>>
 */
function orange_admin_orders_with_pending_stock_reservations(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'stock_movements') || !orange_table_exists($pdo, 'orders')) {
        return [];
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
            )
            ORDER BY o.created_at DESC, o.id DESC
        ';

        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
