<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/journal_write.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/purchase_helpers.php';
require_admin_api();

function reverse_purchase_stock(PDO $pdo, int $purchaseId): void
{
    $hasV = orange_table_has_column($pdo, 'purchase_items', 'variant_id');
    $sql = $hasV
        ? 'SELECT product_id, variant_id, qty FROM purchase_items WHERE purchase_id = ?'
        : 'SELECT product_id, qty FROM purchase_items WHERE purchase_id = ?';
    $itemsStmt = $pdo->prepare($sql);
    $itemsStmt->execute([$purchaseId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $item) {
        $qty = (int) ($item['qty'] ?? 0);
        $pid = (int) ($item['product_id'] ?? 0);
        if ($qty <= 0 || $pid <= 0) {
            continue;
        }
        $vid = $hasV ? (int) ($item['variant_id'] ?? 0) : 0;
        if ($vid > 0) {
            $pdo->prepare(
                'UPDATE product_variants SET stock_quantity = GREATEST(stock_quantity - ?, 0) WHERE id = ? AND product_id = ?'
            )->execute([$qty, $vid, $pid]);
        } else {
            $pdo->prepare(
                'UPDATE product_variants SET stock_quantity = GREATEST(stock_quantity - ?, 0) WHERE product_id = ?'
            )->execute([$qty, $pid]);
        }
    }
}

function apply_purchase_items(PDO $pdo, int $purchaseId, array $items): float
{
    $hasV = orange_table_has_column($pdo, 'purchase_items', 'variant_id');
    $total = 0.0;
    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['qty'] ?? 0);
        $cost = (float)($item['cost'] ?? 0);
        if ($productId <= 0 || $qty <= 0 || $cost < 0) {
            throw new RuntimeException('عنصر شراء غير صحيح');
        }
        $total += $qty * $cost;
        $variantId = orange_purchase_resolve_variant_id(
            $pdo,
            $productId,
            (int)($item['variant_id'] ?? 0)
        );
        if ($hasV) {
            $pdo->prepare(
                'INSERT INTO purchase_items (purchase_id, product_id, variant_id, qty, cost) VALUES (?, ?, ?, ?, ?)'
            )->execute([$purchaseId, $productId, $variantId, $qty, $cost]);
        } else {
            $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, qty, cost) VALUES (?, ?, ?, ?)")
                ->execute([$purchaseId, $productId, $qty, $cost]);
        }
        $pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id = ?')
            ->execute([$qty, $variantId]);
    }

    return $total;
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $purchaseId = (int)($data['id'] ?? 0);
    $action = trim((string)($data['action'] ?? 'update'));
    if ($purchaseId <= 0) {
        json_response(['success' => false, 'message' => 'معرف عملية الشراء مطلوب'], 422);
    }

    $stmt = $pdo->prepare("SELECT * FROM purchases WHERE id = ? LIMIT 1");
    $stmt->execute([$purchaseId]);
    $purchase = $stmt->fetch();
    if (!$purchase) {
        json_response(['success' => false, 'message' => 'عملية الشراء غير موجودة'], 404);
    }

    $purRef = 'PUR-' . $purchaseId;
    $accRow = orange_accounting_row_by_reference($pdo, $purRef);
    if (orange_accounting_is_locked($pdo, $accRow)) {
        json_response(['success' => false, 'message' => 'لا يمكن تعديل أو حذف شراء مرتبط بسنة مالية مغلقة'], 422);
    }

    $pdo->beginTransaction();
    reverse_purchase_stock($pdo, $purchaseId);
    $pdo->prepare("DELETE FROM purchase_items WHERE purchase_id = ?")->execute([$purchaseId]);

    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM journal_entries WHERE reference = ?")->execute(['PUR-' . $purchaseId]);
        $pdo->prepare("DELETE FROM purchases WHERE id = ?")->execute([$purchaseId]);
        $pdo->commit();
        audit_log('purchase_delete', 'تم حذف فاتورة شراء رقم: ' . $purchaseId, 'purchases', $purchaseId);
        json_response(['success' => true, 'message' => 'تم حذف عملية الشراء']);
    }

    $type = trim((string)($data['type'] ?? $purchase['type']));
    $supplierId = (int)($data['supplier_id'] ?? (int)$purchase['supplier_id']);
    $notes = trim((string)($data['notes'] ?? (string)$purchase['notes']));
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    if (!in_array($type, ['cash', 'credit'], true) || count($items) === 0) {
        json_response(['success' => false, 'message' => 'بيانات التعديل غير صحيحة'], 422);
    }

    $newTotal = apply_purchase_items($pdo, $purchaseId, $items);
    $pdo->prepare("UPDATE purchases SET supplier_id = ?, total = ?, type = ?, notes = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$supplierId > 0 ? $supplierId : null, $newTotal, $type, $notes, $purchaseId]);

    orange_purchase_remove_accounting($pdo, $purRef);

    $inventoryId = orange_gl_account_id($pdo, 'inventory');
    $cashId = orange_gl_account_id($pdo, 'cash');
    $apId = orange_gl_account_id($pdo, 'accounts_payable');
    $purRef = 'PUR-' . $purchaseId;
    $now = date('Y-m-d H:i:s');
    if ($type === 'cash') {
        orange_journal_insert_line($pdo, [
            'date' => $now,
            'account_debit' => $inventoryId,
            'account_credit' => $cashId,
            'amount' => $newTotal,
            'reference' => $purRef,
            'description' => 'شراء نقدي',
            'entry_type' => 'purchase',
        ]);
    } else {
        orange_journal_insert_line($pdo, [
            'date' => $now,
            'account_debit' => $inventoryId,
            'account_credit' => $apId,
            'amount' => $newTotal,
            'reference' => $purRef,
            'description' => 'شراء آجل — ذمم موردين',
            'entry_type' => 'purchase',
        ]);
    }

    orange_purchase_record_ap_subledger($pdo, $purchaseId, $supplierId, $type, $newTotal);

    $pdo->commit();
    audit_log('purchase_update', 'تم تعديل فاتورة شراء رقم: ' . $purchaseId, 'purchases', $purchaseId);
    json_response(['success' => true, 'message' => 'تم تعديل عملية الشراء']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e, 'تعذر معالجة عملية الشراء');
}
