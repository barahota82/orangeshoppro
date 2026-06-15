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

    if ($action === 'next_no') {
        json_response(['success' => true, 'next_no' => orange_stock_adjustment_voucher_next_no($pdo, $ctxCountryId)]);
    }

    if ($action === 'nav') {
        $where = trim((string) ($data['where'] ?? ''));
        $currentId = (int) ($data['current_id'] ?? 0);
        $navId = orange_stock_adjustment_voucher_nav($pdo, $where, $currentId, $ctxCountryId);
        if ($navId <= 0) {
            json_response(['success' => false, 'message' => 'لا يوجد سند في هذا الاتجاه'], 404);
        }
        json_response(['success' => true, 'id' => $navId]);
    }

    if ($action === 'search') {
        $rows = orange_stock_adjustment_voucher_search($pdo, [
            'id_from' => (int) ($data['id_from'] ?? 0),
            'id_to' => (int) ($data['id_to'] ?? 0),
            'date_from' => trim((string) ($data['date_from'] ?? '')),
            'date_to' => trim((string) ($data['date_to'] ?? '')),
            'notes' => trim((string) ($data['notes'] ?? '')),
        ], $ctxCountryId);
        json_response(['success' => true, 'rows' => $rows]);
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
