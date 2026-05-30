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
    $action = trim((string) ($data['action'] ?? 'save'));

    if ($action === 'delete') {
        $id = (int) ($data['id'] ?? 0);
        if (! orange_inventory_reconciliation_delete_draft($pdo, $id, $ctxCountryId)) {
            json_response(['success' => false, 'message' => 'تعذّر الحذف — مسودة فقط'], 422);
        }
        audit_log('inventory_reconciliation_delete', 'حذف مسودة جرد #' . $id, 'inventory_reconciliation', $id);
        json_response(['success' => true, 'message' => 'تم حذف المسودة']);
    }

    $warehouseId = (int) ($data['warehouse_id'] ?? 0);
    $linesIn = isset($data['lines']) && is_array($data['lines']) ? $data['lines'] : [];
    $lines = orange_inventory_reconciliation_normalize_lines($pdo, $warehouseId, $linesIn);

    $id = orange_inventory_reconciliation_save($pdo, [
        'id' => (int) ($data['id'] ?? 0),
        'warehouse_id' => $warehouseId,
        'counted_at' => trim((string) ($data['counted_at'] ?? '')),
        'notes' => trim((string) ($data['notes'] ?? '')),
    ], $lines, $ctxCountryId);

    audit_log('inventory_reconciliation_save', 'حفظ جرد #' . $id, 'inventory_reconciliation', $id);

    $rec = orange_inventory_reconciliation_get($pdo, $id, $ctxCountryId);
    json_response([
        'success' => true,
        'message' => 'تم حفظ جلسة الجرد',
        'id' => $id,
        'reconciliation' => $rec,
    ]);
} catch (InvalidArgumentException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
