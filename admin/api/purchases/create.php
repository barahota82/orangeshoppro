
<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();

    $supplierId = (int)($data['supplier_id'] ?? 0);
    $type = trim((string)($data['type'] ?? ''));
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    $notes = trim((string)($data['notes'] ?? ''));

    if (!in_array($type, ['cash', 'credit'], true) || count($items) === 0) {
        json_response(['success' => false, 'message' => 'بيانات الشراء غير صحيحة'], 422);
    }

    $pdo->beginTransaction();

    $computedTotal = 0.0;
    foreach ($items as $item) {
        $qty = (int)($item['qty'] ?? 0);
        $cost = (float)($item['cost'] ?? 0);
        if ($qty <= 0 || $cost < 0) {
            throw new RuntimeException('عنصر شراء غير صالح');
        }
        $computedTotal += ($qty * $cost);
    }

    $stmt = $pdo->prepare("INSERT INTO purchases (supplier_id, total, type, notes) VALUES (?, ?, ?, ?)");
    $stmt->execute([$supplierId > 0 ? $supplierId : null, $computedTotal, $type, $notes]);
    $purchaseId = (int)$pdo->lastInsertId();

    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['qty'] ?? 0);
        $cost = (float)($item['cost'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            throw new RuntimeException('عنصر شراء غير مكتمل');
        }

        $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, qty, cost) VALUES (?, ?, ?, ?)")
            ->execute([$purchaseId, $productId, $qty, $cost]);

        // Increase stock on matching variants for this product.
        $pdo->prepare("UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE product_id = ?")
            ->execute([$qty, $productId]);
    }

    if ($type === 'cash') {
        $pdo->prepare("
            INSERT INTO journal_entries (date, account_debit, account_credit, amount, reference, description, entry_type)
            VALUES (NOW(), 1, 3, ?, ?, ?, 'purchase')
        ")->execute([$computedTotal, 'PUR-' . $purchaseId, 'Cash purchase']);
    } else {
        $pdo->prepare("
            INSERT INTO journal_entries (date, account_debit, account_credit, amount, reference, description, entry_type)
            VALUES (NOW(), 3, 5, ?, ?, ?, 'purchase')
        ")->execute([$computedTotal, 'PUR-' . $purchaseId, 'Credit purchase']);
    }

    $pdo->commit();
    audit_log('purchase_create', 'تم إنشاء فاتورة شراء رقم: ' . $purchaseId, 'purchases', $purchaseId);
    json_response(['success' => true, 'message' => 'تم حفظ عملية الشراء', 'purchase_id' => $purchaseId]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e, 'تعذر حفظ عملية الشراء');
}
