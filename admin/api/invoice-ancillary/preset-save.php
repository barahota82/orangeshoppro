<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/invoice_ancillary_lines.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $accountId = (int) ($data['account_id'] ?? 0);
    $lineKind = trim((string) ($data['line_kind'] ?? ''));
    $invoiceContext = trim((string) ($data['invoice_context'] ?? 'purchase'));
    $labelAr = trim((string) ($data['label_ar'] ?? ''));
    $labelEn = trim((string) ($data['label_en'] ?? ''));
    $systemKeyRaw = trim((string) ($data['system_key'] ?? ''));
    $systemKey = $systemKeyRaw !== '' ? orange_invoice_ancillary_system_key_normalize($systemKeyRaw) : null;
    $defaultShow = !empty($data['default_show_on_print']);
    $sortOrder = (int) ($data['sort_order'] ?? 0);
    $isActive = !array_key_exists('is_active', $data) || !empty($data['is_active']) ? 1 : 0;

    if ($accountId <= 0) {
        json_response(['success' => false, 'message' => 'اختر حساباً أولاً'], 422);
    }
    if (!orange_invoice_ancillary_line_kind_is_valid($lineKind)) {
        json_response(['success' => false, 'message' => 'نوع البند غير معتمد'], 422);
    }
    if (!in_array($invoiceContext, orange_invoice_ancillary_invoice_contexts(), true)) {
        json_response(['success' => false, 'message' => 'سياق الفاتورة غير صالح'], 422);
    }
    if ($systemKeyRaw !== '' && $systemKey === null) {
        json_response(['success' => false, 'message' => 'مفتاح النظام غير صالح'], 422);
    }

    $countryId = orange_admin_context_country_id($pdo);
    $presetId = orange_invoice_ancillary_preset_save($pdo, [
        'id' => (int) ($data['id'] ?? 0),
        'country_id' => $countryId,
        'account_id' => $accountId,
        'line_kind' => $lineKind,
        'invoice_context' => $invoiceContext,
        'label_ar' => $labelAr,
        'label_en' => $labelEn,
        'system_key' => $systemKey,
        'default_show_on_print' => $defaultShow,
        'sort_order' => $sortOrder,
        'is_active' => $isActive,
    ]);

    json_response([
        'success' => true,
        'message' => ((int) ($data['id'] ?? 0) > 0) ? 'تم تحديث البند' : 'تمت إضافة الحساب إلى القائمة المحفوظة',
        'preset_id' => $presetId,
    ]);
} catch (RuntimeException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ القائمة');
}
