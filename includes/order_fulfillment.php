<?php

declare(strict_types=1);

require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/order_stock.php';
require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/gl_settings.php';
require_once __DIR__ . '/journal_write.php';
require_once __DIR__ . '/party_subledger.php';
require_once __DIR__ . '/party_allocations.php';

/**
 * Stock + accounting when an order is marked completed (website or company manual).
 * Caller must have set orders.status = completed before calling.
 */
function orange_complete_order_fulfillment(PDO $pdo, int $orderId): void
{
    orange_catalog_ensure_schema($pdo);

    $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || ($order['status'] ?? '') !== 'completed') {
        throw new RuntimeException('الطلب غير مكتمل أو غير موجود');
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $paymentTerms = 'cash';
    if (orange_table_has_column($pdo, 'orders', 'payment_terms')) {
        $paymentTerms = orange_normalize_payment_terms($order['payment_terms'] ?? 'cash');
    }
    $isCredit = ($paymentTerms === 'credit');
    $isOnline = ($paymentTerms === 'online');

    $inventoryId = orange_gl_account_id($pdo, 'inventory');
    if ($isOnline) {
        $debitReceivable = orange_gl_account_id($pdo, 'cash');
        $salesId = orange_gl_account_id($pdo, 'sales_revenue_online');
        $cogsId = orange_gl_account_id($pdo, 'cogs_online');
    } elseif ($isCredit) {
        $debitReceivable = orange_gl_account_id($pdo, 'ar_credit');
        $salesId = orange_gl_account_id($pdo, 'sales_revenue_credit');
        $cogsId = orange_gl_account_id($pdo, 'cogs_credit');
    } else {
        $debitReceivable = orange_gl_account_id($pdo, 'cash');
        $salesId = orange_gl_account_id($pdo, 'sales_revenue_cash');
        $cogsId = orange_gl_account_id($pdo, 'cogs_cash');
    }

    $orderNumber = (string)($order['order_number'] ?? '');
    $ref = orange_order_stock_reference($orderNumber);
    // Web checkout already decremented stock; do not deduct again on complete.
    $stockAlreadyReserved = $orderNumber !== ''
        && orange_order_has_pending_stock_reservation($pdo, $orderNumber);

    $customerIdForAr = 0;
    if ($isCredit && orange_table_exists($pdo, 'customers')) {
        $customerIdForAr = orange_ensure_customer(
            $pdo,
            (string) ($order['customer_name'] ?? ''),
            (string) ($order['phone'] ?? '')
        );
        if ($customerIdForAr > 0 && orange_table_has_column($pdo, 'orders', 'customer_id')) {
            $pdo->prepare('UPDATE orders SET customer_id = ? WHERE id = ?')->execute([
                $customerIdForAr,
                (int) $order['id'],
            ]);
        }
    }

    $creditSaleTotal = 0.0;
    foreach ($items as $item) {
        $creditSaleTotal = round($creditSaleTotal + (float) $item['price'] * (int) $item['qty'], 4);
    }
    if ($isCredit && $customerIdForAr > 0 && $creditSaleTotal > 0.0001) {
        $lim = orange_party_customer_credit_limit($pdo, $customerIdForAr);
        if ($lim !== null) {
            $bal = orange_party_balance_customer($pdo, $customerIdForAr);
            if ($bal + $creditSaleTotal > $lim + 0.02) {
                throw new RuntimeException(
                    'تجاوز حد الائتمان للعميل (الحد: ' . number_format($lim, 3)
                    . ' — الرصيد الحالي: ' . number_format($bal, 3)
                    . ' — إضافة التسليم: ' . number_format($creditSaleTotal, 3) . ').'
                );
            }
        }
    }

    foreach ($items as $idx => $item) {
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

        $now = date('Y-m-d H:i:s');
        $lineKey = isset($item['id']) ? (string) (int) $item['id'] : (string) $idx;
        $saleRef = 'ORDER-' . $order['order_number'] . '-S-' . $lineKey;
        $cogsRef = 'ORDER-' . $order['order_number'] . '-C-' . $lineKey;
        $vSale = orange_journal_insert_line($pdo, [
            'date' => $now,
            'account_debit' => $debitReceivable,
            'account_credit' => $salesId,
            'amount' => $salesAmount,
            'reference' => $saleRef,
            'description' => $isOnline
                ? 'قيد مبيعات أونلاين — تسليم'
                : ($isCredit ? 'قيد مبيعات آجل — تسليم' : 'قيد مبيعات نقدي — تسليم'),
            'entry_type' => 'order_delivery_sale',
        ]);
        if ($isCredit && $customerIdForAr > 0) {
            orange_party_subledger_record(
                $pdo,
                'customer',
                $customerIdForAr,
                $vSale,
                $salesAmount,
                0,
                'order',
                (int) $order['id'],
                'مبيعات آجل — تسليم'
            );
        }
        orange_journal_insert_line($pdo, [
            'date' => $now,
            'account_debit' => $cogsId,
            'account_credit' => $inventoryId,
            'amount' => $costAmount,
            'reference' => $cogsRef,
            'description' => $isOnline
                ? 'قيد تكلفة مبيعات أونلاين — تسليم'
                : ($isCredit ? 'قيد تكلفة مبيعات آجل — تسليم' : 'قيد تكلفة مبيعات نقدي — تسليم'),
            'entry_type' => 'order_delivery_cogs',
        ]);
    }

    if ($stockAlreadyReserved) {
        $pdo->prepare(
            "UPDATE stock_movements SET type = 'pending_order_fulfilled'
             WHERE reference = ? AND type = 'pending_order'"
        )->execute([$ref]);
    }
}
