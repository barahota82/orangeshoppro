<?php

declare(strict_types=1);

require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/order_stock.php';

/**
 * Stock + accounting when an order is marked completed (website or company manual).
 * Caller must have set orders.status = completed before calling.
 */
function orange_complete_order_fulfillment(PDO $pdo, int $orderId): void
{
    $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || ($order['status'] ?? '') !== 'completed') {
        throw new RuntimeException('الطلب غير مكتمل أو غير موجود');
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $cashId = (int)$pdo->query("SELECT id FROM accounts WHERE name = 'Cash' LIMIT 1")->fetchColumn();
    $salesId = (int)$pdo->query("SELECT id FROM accounts WHERE name = 'Sales' LIMIT 1")->fetchColumn();
    $inventoryId = (int)$pdo->query("SELECT id FROM accounts WHERE name = 'Inventory' LIMIT 1")->fetchColumn();
    $cogsId = (int)$pdo->query("SELECT id FROM accounts WHERE name = 'COGS' LIMIT 1")->fetchColumn();

    $orderNumber = (string)($order['order_number'] ?? '');
    $ref = orange_order_stock_reference($orderNumber);
    // Web checkout already decremented stock; do not deduct again on complete.
    $stockAlreadyReserved = $orderNumber !== ''
        && orange_order_has_pending_stock_reservation($pdo, $orderNumber);

    foreach ($items as $item) {
        $variant = orange_order_resolve_variant_from_item($pdo, $item);

        if ($variant && !$stockAlreadyReserved) {
            $oldStock = (int)$variant['stock_quantity'];
            $newStock = max(0, $oldStock - (int)$item['qty']);

            $pdo->prepare('UPDATE product_variants SET stock_quantity = ? WHERE id = ?')
                ->execute([$newStock, (int)$variant['id']]);

            $moveStmt = $pdo->prepare("
                INSERT INTO stock_movements (
                    product_id, variant_id, type, qty, old_stock, new_stock, reason, created_at
                ) VALUES (
                    ?, ?, 'delivered_order', ?, ?, ?, 'Order delivered', NOW()
                )
            ");
            $moveStmt->execute([
                (int)$item['product_id'],
                (int)$variant['id'],
                (int)$item['qty'],
                $oldStock,
                $newStock,
            ]);
        }

        $salesAmount = (float)$item['price'] * (int)$item['qty'];
        $costAmount = (float)$item['cost'] * (int)$item['qty'];

        $journalStmt = $pdo->prepare('
            INSERT INTO journal_entries (
                date, account_debit, account_credit, amount, reference, description
            ) VALUES (
                NOW(), ?, ?, ?, ?, ?
            )
        ');

        $journalStmt->execute([
            $cashId,
            $salesId,
            $salesAmount,
            'ORDER-' . $order['order_number'],
            'Sales entry for delivered order',
        ]);

        $journalStmt->execute([
            $cogsId,
            $inventoryId,
            $costAmount,
            'ORDER-' . $order['order_number'],
            'COGS entry for delivered order',
        ]);
    }

    if ($stockAlreadyReserved) {
        $pdo->prepare(
            "UPDATE stock_movements SET type = 'pending_order_fulfilled'
             WHERE reference = ? AND type = 'pending_order'"
        )->execute([$ref]);
    }
}
