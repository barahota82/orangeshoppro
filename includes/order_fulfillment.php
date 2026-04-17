<?php

declare(strict_types=1);

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

    foreach ($items as $item) {
        $variant = null;
        $vid = isset($item['variant_id']) ? (int)$item['variant_id'] : 0;
        if ($vid > 0) {
            $vStmt = $pdo->prepare(
                'SELECT * FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1'
            );
            $vStmt->execute([$vid, (int)$item['product_id']]);
            $variant = $vStmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$variant) {
            $variantStmt = $pdo->prepare(
                'SELECT * FROM product_variants
                WHERE product_id = ? AND color = ? AND size = ?
                LIMIT 1'
            );
            $variantStmt->execute([
                (int)$item['product_id'],
                (string)$item['color'],
                (string)$item['size'],
            ]);
            $variant = $variantStmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($variant) {
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
}
