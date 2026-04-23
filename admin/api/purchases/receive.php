<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
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
    $selCols = 'id, product_id, qty, qty_received';
    if ($hasV) {
        $selCols .= ', variant_id';
    }
    $itemsStmt = $pdo->prepare('SELECT ' . $selCols . ' FROM purchase_items WHERE purchase_id = ? ORDER BY id ASC');
    $itemsStmt->execute([$purchaseId]);
    $rows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();

    $totalReceivedUnits = 0;
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
    }

    if ($mode === 'lines') {
        foreach ($receiveByItemId as $left) {
            if ($left > 0) {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => 'سطر استلام يتجاوز الكمية المتبقية أو معرف غير تابع لهذه الفاتورة'], 422);
            }
        }
    }

    $pdo->commit();

    if ($totalReceivedUnits <= 0) {
        json_response(['success' => true, 'message' => 'لا كمية متبقية للاستلام', 'received_units' => 0]);
    }

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
