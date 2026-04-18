<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();

    $variantId = (int)($data['variant_id'] ?? 0);
    $newStock = (int)($data['stock'] ?? 0);
    $movementType = (string)($data['movement_type'] ?? 'manual_adjustment');
    $allowedTypes = ['manual_adjustment', 'opening_balance'];
    if (!in_array($movementType, $allowedTypes, true)) {
        $movementType = 'manual_adjustment';
    }
    $reasonIn = trim((string)($data['reason'] ?? ''));
    $reason = $reasonIn !== ''
        ? $reasonIn
        : ($movementType === 'opening_balance' ? 'رصيد افتتاحي' : 'تعديل يدوي للمخزون');

    $stmt = $pdo->prepare("SELECT * FROM product_variants WHERE id = ? LIMIT 1");
    $stmt->execute([$variantId]);
    $variant = $stmt->fetch();

    if (!$variant) {
        json_response(['success' => false, 'message' => 'Variant غير موجود'], 404);
    }

    $oldStock = (int)$variant['stock_quantity'];

    $pdo->beginTransaction();

    $pdo->prepare("UPDATE product_variants SET stock_quantity = ? WHERE id = ?")
        ->execute([$newStock, $variantId]);

    $moveStmt = $pdo->prepare("
        INSERT INTO stock_movements (
            product_id, variant_id, type, qty, old_stock, new_stock, reason, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, NOW()
        )
    ");
    $moveStmt->execute([
        (int)$variant['product_id'],
        $variantId,
        $movementType,
        abs($newStock - $oldStock),
        $oldStock,
        $newStock,
        $reason
    ]);

    $pdo->commit();

    json_response(['success' => true, 'message' => 'تم تعديل المخزون']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
