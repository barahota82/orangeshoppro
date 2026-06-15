<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/opening_stock_voucher.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_opening_stock_voucher_ready($pdo)) {
        json_response(['success' => false, 'message' => 'جداول سند أرصدة أول المدة غير جاهزة'], 500);
    }

    $ctxCountryId = orange_admin_settings_effective_country_id($pdo);
    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? 'get'));

    if ($action === 'variant_info') {
        $variantId = (int) ($data['variant_id'] ?? 0);
        $warehouseId = orange_warehouse_default_id_for_country($pdo, $ctxCountryId);
        $info = orange_opening_stock_variant_info($pdo, $variantId, $warehouseId);
        if ($info === null) {
            json_response(['success' => false, 'message' => 'الصنف غير موجود'], 404);
        }
        json_response(['success' => true, 'variant' => $info]);
    }

    if ($action === 'next_no') {
        json_response(['success' => true, 'next_no' => orange_opening_stock_voucher_next_no($pdo, $ctxCountryId)]);
    }

    if ($action === 'nav') {
        $where = trim((string) ($data['where'] ?? ''));
        $currentId = (int) ($data['current_id'] ?? 0);
        $id = orange_opening_stock_voucher_nav($pdo, $where, $currentId, $ctxCountryId);
        if ($id <= 0) {
            json_response(['success' => false, 'message' => 'لا يوجد سند في هذا الاتجاه'], 404);
        }
        json_response(['success' => true, 'id' => $id]);
    }

    if ($action === 'search') {
        $rows = orange_opening_stock_voucher_search($pdo, [
            'id_from' => (int) ($data['id_from'] ?? 0),
            'id_to' => (int) ($data['id_to'] ?? 0),
            'date_from' => trim((string) ($data['date_from'] ?? '')),
            'date_to' => trim((string) ($data['date_to'] ?? '')),
            'notes' => trim((string) ($data['notes'] ?? '')),
        ], $ctxCountryId);
        json_response(['success' => true, 'rows' => $rows]);
    }

    if ($action === 'delete') {
        $id = (int) ($data['id'] ?? 0);
        if (! orange_opening_stock_voucher_delete_draft($pdo, $id, $ctxCountryId)) {
            json_response(['success' => false, 'message' => 'تعذّر الحذف — مسودة فقط'], 422);
        }
        audit_log('opening_stock_voucher_delete', 'حذف مسودة سند رصيد افتتاحي #' . $id, 'opening_stock_voucher', $id);
        json_response(['success' => true, 'message' => 'تم حذف المسودة']);
    }

    if ($action === 'approve') {
        $id = (int) ($data['id'] ?? 0);
        $res = orange_opening_stock_voucher_approve($pdo, $id, $ctxCountryId);
        audit_log(
            'opening_stock_voucher_approve',
            'اعتماد سند رصيد افتتاحي #' . $id . ' — أسطر: ' . (int) $res['lines'],
            'opening_stock_voucher',
            $id
        );
        $sv = orange_opening_stock_voucher_get($pdo, $id, $ctxCountryId);
        json_response([
            'success' => true,
            'message' => 'تم اعتماد السند وتطبيق الأرصدة الافتتاحية',
            'voucher' => $sv,
        ]);
    }

    if ($action === 'get') {
        $id = (int) ($data['id'] ?? 0);
        $sv = orange_opening_stock_voucher_get($pdo, $id, $ctxCountryId);
        if ($sv === null) {
            json_response(['success' => false, 'message' => 'السند غير موجود'], 404);
        }
        json_response(['success' => true, 'voucher' => $sv]);
    }

    // save (create / update draft)
    $linesIn = isset($data['lines']) && is_array($data['lines']) ? $data['lines'] : [];
    $id = orange_opening_stock_voucher_save($pdo, [
        'id' => (int) ($data['id'] ?? 0),
        'document_date' => trim((string) ($data['document_date'] ?? '')),
        'notes' => trim((string) ($data['notes'] ?? '')),
    ], $linesIn, $ctxCountryId);

    audit_log('opening_stock_voucher_save', 'حفظ سند رصيد افتتاحي #' . $id, 'opening_stock_voucher', $id);

    $sv = orange_opening_stock_voucher_get($pdo, $id, $ctxCountryId);
    json_response([
        'success' => true,
        'message' => 'تم حفظ مسودة سند الرصيد الافتتاحي',
        'id' => $id,
        'voucher' => $sv,
    ]);
} catch (InvalidArgumentException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
