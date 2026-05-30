<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/inventory_reconciliation.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_inventory_reconciliation_ready($pdo)) {
        json_response(['success' => false, 'message' => 'جداول تسوية المخزون غير جاهزة'], 500);
    }

    $ctxCountryId = orange_admin_settings_effective_country_id($pdo);
    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? 'get'));

    if ($action === 'stock_snapshot') {
        $warehouseId = (int) ($data['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            json_response(['success' => false, 'message' => 'warehouse_id مطلوب'], 422);
        }
        orange_inventory_reconciliation_assert_warehouse_country($pdo, $warehouseId, $ctxCountryId);
        $rows = orange_inventory_reconciliation_stock_lines_for_warehouse($pdo, $warehouseId, $ctxCountryId);
        $lines = [];
        foreach ($rows as $row) {
            $qty = (int) ($row['qty_system'] ?? 0);
            $vid = (int) ($row['variant_id'] ?? 0);
            $unitCost = orange_inventory_reconciliation_variant_unit_cost($pdo, $vid);
            $lines[] = [
                'variant_id' => $vid,
                'product_name' => (string) ($row['product_name'] ?? ''),
                'color' => (string) ($row['color'] ?? ''),
                'size' => (string) ($row['size'] ?? ''),
                'item_code' => (string) ($row['item_code'] ?? ''),
                'qty_system' => $qty,
                'qty_counted' => $qty,
                'qty_variance' => 0,
                'unit_cost' => $unitCost,
                'value_variance' => 0.0,
            ];
        }
        json_response(['success' => true, 'lines' => $lines, 'warehouse_id' => $warehouseId]);
    }

    $id = (int) ($data['id'] ?? 0);
    $rec = orange_inventory_reconciliation_get($pdo, $id, $ctxCountryId);
    if ($rec === null) {
        json_response(['success' => false, 'message' => 'جلسة الجرد غير موجودة'], 404);
    }

    json_response(['success' => true, 'reconciliation' => $rec]);
} catch (InvalidArgumentException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
