<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

function reverse_purchase_stock(PDO $pdo, int $purchaseId): void
{
    $itemsStmt = $pdo->prepare("SELECT product_id, qty FROM purchase_items WHERE purchase_id = ?");
    $itemsStmt->execute([$purchaseId]);
    $items = $itemsStmt->fetchAll();
    foreach ($items as $item) {
        $pdo->prepare("UPDATE product_variants SET stock_quantity = GREATEST(stock_quantity - ?, 0) WHERE product_id = ?")
            ->execute([(int)$item['qty'], (int)$item['product_id']]);
    }
}

function apply_purchase_items(PDO $pdo, int $purchaseId, array $items): float
{
    $total = 0.0;
    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['qty'] ?? 0);
        $cost = (float)($item['cost'] ?? 0);
        if ($productId <= 0 || $qty <= 0 || $cost < 0) {
            throw new RuntimeException('عنصر شراء غير صحيح');
        }
        $total += $qty * $cost;
        $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, qty, cost) VALUES (?, ?, ?, ?)")
            ->execute([$purchaseId, $productId, $qty, $cost]);
        $pdo->prepare("UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE product_id = ?")
            ->execute([$qty, $productId]);
    }
    return $total;
}

try {
    $pdo = db();
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

    $pdo->prepare("DELETE FROM journal_entries WHERE reference = ?")->execute(['PUR-' . $purchaseId]);
    if ($type === 'cash') {
        $pdo->prepare("INSERT INTO journal_entries (date, account_debit, account_credit, amount, reference, description, entry_type)
            VALUES (NOW(), 1, 3, ?, ?, ?, 'purchase')")
            ->execute([$newTotal, 'PUR-' . $purchaseId, 'Cash purchase']);
    } else {
        $pdo->prepare("INSERT INTO journal_entries (date, account_debit, account_credit, amount, reference, description, entry_type)
            VALUES (NOW(), 3, 5, ?, ?, ?, 'purchase')")
            ->execute([$newTotal, 'PUR-' . $purchaseId, 'Credit purchase']);
    }

    $pdo->commit();
    audit_log('purchase_update', 'تم تعديل فاتورة شراء رقم: ' . $purchaseId, 'purchases', $purchaseId);
    json_response(['success' => true, 'message' => 'تم تعديل عملية الشراء']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e, 'تعذر معالجة عملية الشراء');
}
