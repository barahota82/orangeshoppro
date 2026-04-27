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

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $customerId = (int) ($data['customer_id'] ?? 0);
    $channel = trim((string) ($data['channel'] ?? $data['payment_channel'] ?? ''));
    if ($channel === '') {
        $channel = 'credit';
    }
    $orderIdOpt = (int) ($data['order_id'] ?? 0);
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    $notes = trim((string) ($data['notes'] ?? ''));

    if (!in_array($channel, ['cash', 'online', 'credit'], true) || $items === []) {
        json_response(['success' => false, 'message' => 'بيانات مردود المبيعات غير صحيحة'], 422);
    }

    if ($channel === 'credit' && $customerId <= 0) {
        json_response(['success' => false, 'message' => 'مردود مبيعات آجل يتطلّب عميلاً.'], 422);
    }

    $pdo->beginTransaction();

    if ($orderIdOpt > 0) {
        $chk = $pdo->prepare('SELECT id FROM orders WHERE id = ? LIMIT 1');
        $chk->execute([$orderIdOpt]);
        if (!$chk->fetch()) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'الطلب المرجعي غير موجود'], 422);
        }
    }

    if ($channel === 'credit') {
        if (!orange_table_exists($pdo, 'customers')) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'جدول العملاء غير متوفر'], 422);
        }
        $cchk = $pdo->prepare('SELECT id FROM customers WHERE id = ? LIMIT 1');
        $cchk->execute([$customerId]);
        if (!$cchk->fetch()) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'العميل غير موجود'], 422);
        }
    }

    $revenueTotal = 0.0;
    $cogsTotal = 0.0;
    $normalizedItems = [];
    foreach ($items as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            throw new RuntimeException('عنصر مردود غير مكتمل');
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
            throw new RuntimeException('صافي سطر المردود غير صالح (سعر/كمية/خصم)');
        }
        $unitCost = orange_sales_return_resolve_unit_cost($pdo, $productId, (float) $line['cost']);
        $lineCost = round($unitCost * $qty, 4);
        $revenueTotal += $net;
        $cogsTotal += $lineCost;
        $line['line_net'] = $net;
        $line['line_cogs'] = $lineCost;
        $normalizedItems[] = $line;
    }
    $revenueTotal = round($revenueTotal, 4);
    $cogsTotal = round($cogsTotal, 4);

    $tmpNum = 'SR-TMP-' . bin2hex(random_bytes(6));
    $pdo->prepare(
        'INSERT INTO sales_returns (return_number, order_id, customer_id, channel_id, type, total, notes)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([
        $tmpNum,
        $orderIdOpt > 0 ? $orderIdOpt : null,
        $customerId > 0 ? $customerId : null,
        null,
        $channel,
        $revenueTotal,
        $notes !== '' ? $notes : null,
    ]);
    $returnId = (int) $pdo->lastInsertId();
    $retNum = 'SR-' . $returnId;
    $pdo->prepare('UPDATE sales_returns SET return_number = ? WHERE id = ?')->execute([$retNum, $returnId]);

    $hasVariant = orange_table_has_column($pdo, 'sales_return_items', 'variant_id');
    foreach ($normalizedItems as $line) {
        orange_sales_return_add_line_stock($pdo, $line['product_id'], $line['variant_id'], $line['qty']);
        if ($hasVariant) {
            $pdo->prepare(
                'INSERT INTO sales_return_items (sales_return_id, product_id, variant_id, qty, price, line_discount)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                $returnId,
                $line['product_id'],
                $line['variant_id'],
                $line['qty'],
                $line['price'],
                $line['line_discount'],
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO sales_return_items (sales_return_id, product_id, qty, price, line_discount)
                 VALUES (?,?,?,?,?)'
            )->execute([
                $returnId,
                $line['product_id'],
                $line['qty'],
                $line['price'],
                $line['line_discount'],
            ]);
        }
    }

    $now = date('Y-m-d H:i:s');
    $refRev = 'SR-' . $returnId . '-RS';
    $refCogs = 'SR-' . $returnId . '-RC';
    $inventoryId = orange_gl_account_id($pdo, 'inventory');
    $cogsRetId = orange_gl_cogs_return_account_id($pdo);

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
        $cogsDesc = 'مردود تكلفة مبيعات — مستند مردود';
        if (orange_gl_use_pending_queue($pdo)) {
            orange_gl_pending_enqueue_simple($pdo, [
                'reference' => $refCogs,
                'source_label' => $retNum,
                'movement_at' => $now,
                'voucher_date' => $now,
                'account_debit' => $inventoryId,
                'account_credit' => $cogsRetId,
                'amount' => $cogsTotal,
                'description' => $cogsDesc,
                'entry_type' => 'order_return_cogs',
            ]);
        } else {
            orange_journal_insert_line($pdo, [
                'date' => $now,
                'account_debit' => $inventoryId,
                'account_credit' => $cogsRetId,
                'amount' => $cogsTotal,
                'reference' => $refCogs,
                'description' => $cogsDesc,
                'entry_type' => 'order_return_cogs',
            ]);
        }
    }

    $pdo->commit();
    audit_log('sales_return_create', 'تم إنشاء مردود مبيعات رقم: ' . $returnId, 'sales_returns', $returnId);
    json_response(['success' => true, 'message' => 'تم حفظ مردود المبيعات', 'sales_return_id' => $returnId]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_gl_api_catch_json($e, 'تعذر حفظ مردود المبيعات');
}
