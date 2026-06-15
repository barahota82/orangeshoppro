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
    $id = (int) ($data['id'] ?? 0);
    $adjustmentAccountId = (int) ($data['adjustment_account_id'] ?? 0);

    $result = orange_inventory_reconciliation_approve($pdo, $id, $adjustmentAccountId, $ctxCountryId);

    audit_log('inventory_reconciliation_approve', 'إقفال تقرير جرد #' . $id, 'inventory_reconciliation', $id);

    $msg = 'تم إقفال تقرير الجرد — لم يُطبَّق على المخزون. حرّر «سند تعديل الرصيد» لتطبيق الفرق بعد قرار الإدارة.';

    $rec = orange_inventory_reconciliation_get($pdo, $id, $ctxCountryId);
    json_response([
        'success' => true,
        'message' => $msg,
        'result' => $result,
        'reconciliation' => $rec,
    ]);
} catch (InvalidArgumentException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
