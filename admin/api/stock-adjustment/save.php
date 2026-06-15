<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/stock_adjustment_voucher.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_stock_adjustment_voucher_ready($pdo)) {
        json_response(['success' => false, 'message' => 'جداول قيد تسوية المخزون غير جاهزة'], 500);
    }

    $ctxCountryId = orange_admin_settings_effective_country_id($pdo);
    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? 'save'));

    if ($action === 'delete') {
        $id = (int) ($data['id'] ?? 0);
        if (! orange_stock_adjustment_voucher_delete_draft($pdo, $id, $ctxCountryId)) {
            json_response(['success' => false, 'message' => 'تعذّر الحذف — مسودة فقط'], 422);
        }
        audit_log('stock_adjustment_voucher_delete', 'حذف مسودة قيد تسوية مخزون #' . $id, 'stock_adjustment_voucher', $id);
        json_response(['success' => true, 'message' => 'تم حذف المسودة']);
    }

    $warehouseId = orange_warehouse_default_id_for_country($pdo, $ctxCountryId);
    $linesIn = isset($data['lines']) && is_array($data['lines']) ? $data['lines'] : [];
    $lines = orange_stock_adjustment_voucher_normalize_lines($pdo, $warehouseId, $linesIn);

    $glLinesIn = isset($data['gl_lines']) && is_array($data['gl_lines']) ? $data['gl_lines'] : [];

    $id = orange_stock_adjustment_voucher_save($pdo, [
        'id' => (int) ($data['id'] ?? 0),
        'document_date' => trim((string) ($data['document_date'] ?? '')),
        'notes' => trim((string) ($data['notes'] ?? '')),
        'gl_lines' => $glLinesIn,
    ], $lines, $ctxCountryId);

    audit_log('stock_adjustment_voucher_save', 'حفظ قيد تسوية مخزون #' . $id, 'stock_adjustment_voucher', $id);

    $sv = orange_stock_adjustment_voucher_get($pdo, $id, $ctxCountryId);
    json_response([
        'success' => true,
        'message' => 'تم حفظ مسودة قيد تسوية المخزون',
        'id' => $id,
        'voucher' => $sv,
    ]);
} catch (InvalidArgumentException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
