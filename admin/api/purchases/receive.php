<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/journal_write.php';
require_once __DIR__ . '/../../../includes/purchase_helpers.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $purchaseId = (int) ($data['purchase_id'] ?? 0);
    $mode = trim((string) ($data['mode'] ?? 'all'));
    if ($purchaseId <= 0) {
        json_response(['success' => false, 'message' => 'معرف فاتورة الشراء مطلوب'], 422);
    }
    if ($mode !== 'all' && $mode !== 'lines') {
        json_response(['success' => false, 'message' => 'وضع الاستلام غير صالح'], 422);
    }

    if (!orange_table_has_column($pdo, 'purchase_items', 'qty_received')) {
        json_response(['success' => false, 'message' => 'قاعدة البيانات تحتاج ترقية (qty_received)'], 500);
    }

    $stmt = $pdo->prepare('SELECT id FROM purchases WHERE id = ? LIMIT 1');
    $stmt->execute([$purchaseId]);
    if (!$stmt->fetchColumn()) {
        json_response(['success' => false, 'message' => 'عملية الشراء غير موجودة'], 404);
    }

    $purRef = 'PUR-' . $purchaseId;
    $accRow = orange_accounting_row_by_reference($pdo, $purRef);
    if (orange_accounting_is_locked($pdo, $accRow)) {
        json_response([
            'success' => false,
            'message' => 'لا يمكن استلام بضاعة على شراء مرتبط بسنة مالية مغلقة',
            'suggest_admin' => orange_gl_suggest_admin_fiscal_years_screen(),
        ], 422);
    }

    $linesIn = isset($data['lines']) && is_array($data['lines']) ? $data['lines'] : [];
    $receiveByItemId = [];
    if ($mode === 'lines') {
        foreach ($linesIn as $row) {
            $iid = (int) ($row['item_id'] ?? 0);
            $q = (int) ($row['qty'] ?? 0);
            if ($iid <= 0 || $q <= 0) {
                continue;
            }
            $receiveByItemId[$iid] = ($receiveByItemId[$iid] ?? 0) + $q;
        }
        if ($receiveByItemId === []) {
            json_response(['success' => false, 'message' => 'لم يُرسل أي سطر استلام صالح'], 422);
        }
    }

    $hasV = orange_table_has_column($pdo, 'purchase_items', 'variant_id');
    $selCols = 'id, product_id, qty, qty_received, cost';
    if ($hasV) {
        $selCols .= ', variant_id';
    }
    $itemsStmt = $pdo->prepare('SELECT ' . $selCols . ' FROM purchase_items WHERE purchase_id = ? ORDER BY id ASC');
    $itemsStmt->execute([$purchaseId]);
    $rows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();

    $totalReceivedUnits = 0;
    $receiveGrniValue = 0.0;
    foreach ($rows as $row) {
        $itemId = (int) ($row['id'] ?? 0);
        $productId = (int) ($row['product_id'] ?? 0);
        $ordered = (int) ($row['qty'] ?? 0);
        $had = (int) ($row['qty_received'] ?? 0);
        if ($itemId <= 0 || $productId <= 0 || $ordered <= 0) {
            continue;
        }
        $cap = $ordered - $had;
        if ($cap <= 0) {
            continue;
        }

        if ($mode === 'all') {
            $delta = $cap;
        } else {
            $want = (int) ($receiveByItemId[$itemId] ?? 0);
            $delta = min($cap, $want);
            if ($delta > 0) {
                $receiveByItemId[$itemId] = $want - $delta;
            }
        }

        if ($delta <= 0) {
            continue;
        }

        $vid = $hasV ? (int) ($row['variant_id'] ?? 0) : 0;
        if ($vid <= 0) {
            $vid = orange_purchase_resolve_variant_id($pdo, $productId, 0);
        }

        $pdo->prepare(
            'UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id = ? AND product_id = ?'
        )->execute([$delta, $vid, $productId]);

        $pdo->prepare('UPDATE purchase_items SET qty_received = qty_received + ? WHERE id = ? AND purchase_id = ?')
            ->execute([$delta, $itemId, $purchaseId]);

        $totalReceivedUnits += $delta;
        $lineCost = (float) ($row['cost'] ?? 0);
        if ($lineCost > 0 && $delta > 0) {
            $receiveGrniValue = round($receiveGrniValue + round($lineCost * $delta, 4), 4);
        }
    }

    if ($mode === 'lines') {
        foreach ($receiveByItemId as $left) {
            if ($left > 0) {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => 'سطر استلام يتجاوز الكمية المتبقية أو معرف غير تابع لهذه الفاتورة'], 422);
            }
        }
    }

    if ($totalReceivedUnits <= 0) {
        $pdo->commit();
        json_response(['success' => true, 'message' => 'لا كمية متبقية للاستلام', 'received_units' => 0]);
    }

    if ($receiveGrniValue > 0.0001) {
        $invId = orange_gl_account_id($pdo, 'inventory');
        $clearingId = orange_gl_purchase_receipt_clearing_account_id($pdo);
        $now = date('Y-m-d H:i:s');
        $rcvRef = 'PUR-' . $purchaseId . '-RCV-' . bin2hex(random_bytes(5));
        $rcvDesc = 'قيد استلام مخزون — فاتورة شراء #' . $purchaseId;
        if (orange_gl_use_pending_queue($pdo)) {
            orange_gl_pending_enqueue_simple($pdo, [
                'reference' => $rcvRef,
                'source_label' => $purRef,
                'movement_at' => $now,
                'voucher_date' => $now,
                'account_debit' => $invId,
                'account_credit' => $clearingId,
                'amount' => $receiveGrniValue,
                'description' => $rcvDesc,
                'entry_type' => 'purchase_receive',
            ]);
        } else {
            orange_journal_insert_line($pdo, [
                'date' => $now,
                'account_debit' => $invId,
                'account_credit' => $clearingId,
                'amount' => $receiveGrniValue,
                'reference' => $rcvRef,
                'description' => $rcvDesc,
                'entry_type' => 'purchase_receive',
            ]);
        }
    }

    $pdo->commit();

    audit_log('purchase_receive', 'استلام مخزون لفاتورة شراء: ' . $purchaseId . ' وحدات: ' . $totalReceivedUnits, 'purchases', $purchaseId);
    json_response([
        'success' => true,
        'message' => 'تم تسجيل الاستلام في المخزون',
        'received_units' => $totalReceivedUnits,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_gl_api_catch_json($e, 'تعذر تسجيل استلام الشراء');
}
