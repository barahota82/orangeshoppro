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
        json_response(['success' => false, 'message' => 'جداول سند تعديل الرصيد غير جاهزة'], 500);
    }

    $ctxCountryId = orange_admin_settings_effective_country_id($pdo);
    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? 'get'));

    if ($action === 'variant_info') {
        $variantId = (int) ($data['variant_id'] ?? 0);
        $warehouseId = orange_warehouse_default_id_for_country($pdo, $ctxCountryId);
        $info = orange_stock_adjustment_variant_info($pdo, $variantId, $warehouseId);
        if ($info === null) {
            json_response(['success' => false, 'message' => 'الصنف غير موجود'], 404);
        }
        json_response(['success' => true, 'variant' => $info]);
    }

    $id = (int) ($data['id'] ?? 0);
    $sv = orange_stock_adjustment_voucher_get($pdo, $id, $ctxCountryId);
    if ($sv === null) {
        json_response(['success' => false, 'message' => 'السند غير موجود'], 404);
    }
    json_response(['success' => true, 'voucher' => $sv]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
