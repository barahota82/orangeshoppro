<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/journal_write.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/sales_return_helpers.php';
require_once __DIR__ . '/../../../includes/purchase_helpers.php';
require_once __DIR__ . '/../../../includes/sales_gl_accounts.php';
require_admin_api();

function reverse_sales_return_stock(PDO $pdo, int $returnId): void
{
    $hasV = orange_table_has_column($pdo, 'sales_return_items', 'variant_id');
    $cols = 'product_id, qty';
    if ($hasV) {
        $cols .= ', variant_id';
    }
    $st = $pdo->prepare('SELECT ' . $cols . ' FROM sales_return_items WHERE sales_return_id = ?');
    $st->execute([$returnId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $pid = (int) ($row['product_id'] ?? 0);
        $qty = (int) ($row['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) {
            continue;
        }
        $vid = $hasV ? (int) ($row['variant_id'] ?? 0) : 0;
        if ($vid <= 0) {
            $vid = orange_purchase_resolve_variant_id($pdo, $pid, 0);
        }
        orange_sales_return_undo_line_stock($pdo, $pid, $vid, $qty);
    }
}

/**
 * @return array{revenue: float, cogs: float}
 */
function apply_sales_return_items(PDO $pdo, int $returnId, array $items): array
{
    $hasV = orange_table_has_column($pdo, 'sales_return_items', 'variant_id');
    $revenueTotal = 0.0;
    $cogsTotal = 0.0;
    foreach ($items as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            throw new RuntimeException('عنصر مردود غير صحيح');
        }
        $variantId = orange_purchase_resolve_variant_id(
            $pdo,
            $productId,
            (int) ($item['variant_id'] ?? 0)
        );
        $line = [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'qty' => $qty,
            'price' => (float) ($item['price'] ?? 0),
            'line_discount' => (float) ($item['line_discount'] ?? 0),
            'cost' => (float) ($item['cost'] ?? -1),
        ];
        $net = orange_sales_return_line_net($line);
        if ($net <= 0.0001) {
            throw new RuntimeException('صافي سطر المردود غير صالح');
        }
        $unitCost = orange_sales_return_resolve_unit_cost($pdo, $productId, (float) $line['cost']);
        $lineCost = round($unitCost * $qty, 4);
        $revenueTotal += $net;
        $cogsTotal += $lineCost;
        orange_sales_return_add_line_stock($pdo, $productId, $variantId, $qty);
        if ($hasV) {
            $pdo->prepare(
                'INSERT INTO sales_return_items (sales_return_id, product_id, variant_id, qty, price, line_discount)
                 VALUES (?,?,?,?,?,?)'
            )->execute([$returnId, $productId, $variantId, $qty, $line['price'], $line['line_discount']]);
        } else {
            $pdo->prepare(
                'INSERT INTO sales_return_items (sales_return_id, product_id, qty, price, line_discount)
                 VALUES (?,?,?,?,?)'
            )->execute([$returnId, $productId, $qty, $line['price'], $line['line_discount']]);
        }
    }

    return ['revenue' => round($revenueTotal, 4), 'cogs' => round($cogsTotal, 4)];
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $returnId = (int) ($data['id'] ?? 0);
    $action = trim((string) ($data['action'] ?? 'update'));
    if ($returnId <= 0) {
        json_response(['success' => false, 'message' => 'معرف مردود المبيعات مطلوب'], 422);
    }

    $st = $pdo->prepare('SELECT * FROM sales_returns WHERE id = ? LIMIT 1');
    $st->execute([$returnId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(['success' => false, 'message' => 'غير موجود'], 404);
    }

    $refRev = 'SR-' . $returnId . '-RS';
    $accRow = orange_accounting_row_by_reference($pdo, $refRev);
    if (orange_accounting_is_locked($pdo, $accRow)) {
        json_response([
            'success' => false,
            'message' => 'لا يمكن تعديل أو حذف مردود مرتبط بسنة مالية مغلقة',
            'suggest_admin' => orange_gl_suggest_admin_fiscal_years_screen(),
        ], 422);
    }

    $pdo->beginTransaction();
    reverse_sales_return_stock($pdo, $returnId);
    $pdo->prepare('DELETE FROM sales_return_items WHERE sales_return_id = ?')->execute([$returnId]);

    if ($action === 'delete') {
        orange_sales_return_remove_accounting($pdo, $returnId);
        $pdo->prepare('DELETE FROM sales_returns WHERE id = ?')->execute([$returnId]);
        $pdo->commit();
        audit_log('sales_return_delete', 'تم حذف مردود مبيعات رقم: ' . $returnId, 'sales_returns', $returnId);
        json_response(['success' => true, 'message' => 'تم حذف مردود المبيعات']);
    }

    $channel = trim((string) ($data['channel'] ?? $data['payment_channel'] ?? (string) ($row['type'] ?? 'credit')));
    if ($channel === '') {
        $channel = 'credit';
    }
    $customerId = (int) ($data['customer_id'] ?? (int) ($row['customer_id'] ?? 0));
    $orderIdOpt = (int) ($data['order_id'] ?? (int) ($row['order_id'] ?? 0));
    $notes = trim((string) ($data['notes'] ?? (string) ($row['notes'] ?? '')));
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

    if (!in_array($channel, ['cash', 'online', 'credit'], true) || $items === []) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'بيانات التعديل غير صحيحة'], 422);
    }
    if ($channel === 'credit' && $customerId <= 0) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'مردود آجل يتطلّب عميلاً'], 422);
    }
    if ($channel === 'credit' && orange_table_exists($pdo, 'customers')) {
        $cchk = $pdo->prepare('SELECT id FROM customers WHERE id = ? LIMIT 1');
        $cchk->execute([$customerId]);
        if (!$cchk->fetch()) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'العميل غير موجود'], 422);
        }
    }
    if ($orderIdOpt > 0) {
        $chk = $pdo->prepare('SELECT id FROM orders WHERE id = ? LIMIT 1');
        $chk->execute([$orderIdOpt]);
        if (!$chk->fetch()) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'الطلب المرجعي غير موجود'], 422);
        }
    }

    $totals = apply_sales_return_items($pdo, $returnId, $items);
    $revenueTotal = $totals['revenue'];
    $cogsTotal = $totals['cogs'];

    $pdo->prepare(
        'UPDATE sales_returns SET order_id = ?, customer_id = ?, type = ?, total = ?, notes = ? WHERE id = ?'
    )->execute([
        $orderIdOpt > 0 ? $orderIdOpt : null,
        $customerId > 0 ? $customerId : null,
        $channel,
        $revenueTotal,
        $notes !== '' ? $notes : null,
        $returnId,
    ]);

    orange_sales_return_remove_accounting($pdo, $returnId);

    $retNum = 'SR-' . $returnId;
    $now = date('Y-m-d H:i:s');
    $refCogs = 'SR-' . $returnId . '-RC';

    if ($revenueTotal > 0.0001) {
        $glRev = orange_gl_sales_return_revenue_bundle($pdo, $channel, $customerId, $returnId, $revenueTotal);
        $afterJson = $glRev['after_post'] !== null
            ? json_encode($glRev['after_post'], JSON_UNESCAPED_UNICODE)
            : null;
        if (orange_gl_use_pending_queue($pdo)) {
            orange_gl_pending_enqueue_simple($pdo, [
                'reference' => $refRev,
                'source_label' => $retNum,
                'movement_at' => $now,
                'voucher_date' => $now,
                'account_debit' => $glRev['debit'],
                'account_credit' => $glRev['credit'],
                'amount' => $revenueTotal,
                'description' => $glRev['voucher_description'],
                'entry_type' => 'order_return_sale',
                'after_post_json' => $afterJson,
            ]);
        } else {
            orange_journal_insert_line($pdo, [
                'date' => $now,
                'account_debit' => $glRev['debit'],
                'account_credit' => $glRev['credit'],
                'amount' => $revenueTotal,
                'reference' => $refRev,
                'description' => $glRev['voucher_description'],
                'entry_type' => 'order_return_sale',
            ]);
            if ($glRev['legacy_ar_subledger']) {
                orange_sales_return_record_ar_subledger($pdo, $returnId, $customerId, $channel, $revenueTotal);
            }
        }
    }

    if ($cogsTotal > 0.0001) {
        $glCogs = orange_gl_sales_return_cogs_accounts($pdo, $channel);
        $cogsDesc = 'مردود تكلفة مبيعات — مستند مردود';
        if (orange_gl_use_pending_queue($pdo)) {
            orange_gl_pending_enqueue_simple($pdo, [
                'reference' => $refCogs,
                'source_label' => $retNum,
                'movement_at' => $now,
                'voucher_date' => $now,
                'account_debit' => $glCogs['debit'],
                'account_credit' => $glCogs['credit'],
                'amount' => $cogsTotal,
                'description' => $cogsDesc,
                'entry_type' => 'order_return_cogs',
            ]);
        } else {
            orange_journal_insert_line($pdo, [
                'date' => $now,
                'account_debit' => $glCogs['debit'],
                'account_credit' => $glCogs['credit'],
                'amount' => $cogsTotal,
                'reference' => $refCogs,
                'description' => $cogsDesc,
                'entry_type' => 'order_return_cogs',
            ]);
        }
    }

    $pdo->commit();
    audit_log('sales_return_update', 'تم تعديل مردود مبيعات رقم: ' . $returnId, 'sales_returns', $returnId);
    json_response(['success' => true, 'message' => 'تم تحديث مردود المبيعات']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_gl_api_catch_json($e, 'تعذر تحديث مردود المبيعات');
}
