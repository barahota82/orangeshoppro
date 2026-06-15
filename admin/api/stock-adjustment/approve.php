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
    $id = (int) ($data['id'] ?? 0);

    $result = orange_stock_adjustment_voucher_approve($pdo, $id, $ctxCountryId);

    audit_log('stock_adjustment_voucher_approve', 'اعتماد سند تعديل رصيد #' . $id, 'stock_adjustment_voucher', $id);

    $msg = 'تم اعتماد السند وتطبيق التعديل على المخزون';
    if (! empty($result['queued'])) {
        $msg .= ' — قيد القيمة في طابور الترحيل؛ أكمل من «إقفال الحركات»';
    } elseif (($result['voucher_id'] ?? 0) > 0) {
        $msg .= ' — سند #' . (int) $result['voucher_id'];
    } elseif (abs((float) ($result['total_value'] ?? 0)) < 0.0001) {
        $msg .= ' — لا قيمة محاسبية (تكلفة صفر)';
    }

    $sv = orange_stock_adjustment_voucher_get($pdo, $id, $ctxCountryId);
    json_response([
        'success' => true,
        'message' => $msg,
        'result' => $result,
        'voucher' => $sv,
    ]);
} catch (InvalidArgumentException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
