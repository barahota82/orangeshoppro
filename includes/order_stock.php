<?php

declare(strict_types=1);

require_once __DIR__ . '/order_helpers.php';

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
