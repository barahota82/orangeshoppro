
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/journal_write.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/purchase_helpers.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
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

    $hasPiVariant = orange_table_has_column($pdo, 'purchase_items', 'variant_id');

    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['qty'] ?? 0);
        $cost = (float)($item['cost'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            throw new RuntimeException('عنصر شراء غير مكتمل');
        }

        $variantId = orange_purchase_resolve_variant_id(
            $pdo,
            $productId,
            (int)($item['variant_id'] ?? 0)
        );

        if ($hasPiVariant) {
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
            'amount' => $computedTotal,
            'reference' => $purRef,
            'description' => 'شراء نقدي',
            'entry_type' => 'purchase',
        ]);
    } else {
        orange_journal_insert_line($pdo, [
            'date' => $now,
            'account_debit' => $inventoryId,
            'account_credit' => $apId,
            'amount' => $computedTotal,
            'reference' => $purRef,
            'description' => 'شراء آجل — ذمم موردين',
            'entry_type' => 'purchase',
        ]);
    }

    orange_purchase_record_ap_subledger($pdo, $purchaseId, $supplierId, $type, $computedTotal);

    $pdo->commit();
    audit_log('purchase_create', 'تم إنشاء فاتورة شراء رقم: ' . $purchaseId, 'purchases', $purchaseId);
    json_response(['success' => true, 'message' => 'تم حفظ عملية الشراء', 'purchase_id' => $purchaseId]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e, 'تعذر حفظ عملية الشراء');
}
