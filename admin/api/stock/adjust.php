<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/warehouses.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
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

    $countryId = orange_admin_context_country_id($pdo);
    $warehouseId = orange_warehouse_default_id_for_country($pdo, $countryId);

    $pdo->beginTransaction();

    $stockChange = orange_warehouse_set_variant_quantity($pdo, $warehouseId, $variantId, $newStock);

    orange_stock_movement_insert($pdo, [
        'product_id' => (int)$variant['product_id'],
        'variant_id' => $variantId,
        'type' => $movementType,
        'qty' => abs($stockChange['new'] - $stockChange['old']),
        'old_stock' => $stockChange['old'],
        'new_stock' => $stockChange['new'],
        'reason' => $reason,
        'country_id' => $countryId > 0 ? $countryId : null,
        'warehouse_id' => $warehouseId > 0 ? $warehouseId : null,
    ]);

    $pdo->commit();

    json_response(['success' => true, 'message' => 'تم تعديل المخزون']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر تعديل المخزون');
}
