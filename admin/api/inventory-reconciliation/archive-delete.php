<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/inventory_reconciliation.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_admin_api();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_inventory_reconciliation_ready($pdo)) {
        json_response(['success' => false, 'message' => 'جداول الجرد غير جاهزة'], 500);
    }

    $ctxCountryId = orange_admin_settings_effective_country_id($pdo);
    $data = get_json_input();
    $id = (int) ($data['id'] ?? 0);

    if (! orange_inventory_reconciliation_archive_delete($pdo, $id, $ctxCountryId)) {
        json_response(['success' => false, 'message' => 'تعذّر حذف سجل الجرد'], 422);
    }

    audit_log('stocktake_archive_delete', 'حذف سجل أرشيف جرد #' . $id, 'inventory_reconciliation', $id);
    json_response(['success' => true, 'message' => 'تم حذف سجل الجرد ومرفقاته']);
} catch (InvalidArgumentException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حذف أرشيف الجرد');
}
