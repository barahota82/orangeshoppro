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

    $invoiceContext = trim((string) ($_GET['invoice_context'] ?? 'purchase'));
    if (!in_array($invoiceContext, ['purchase', 'sales', 'both'], true)) {
        json_response(['success' => false, 'message' => 'سياق الفاتورة غير صالح'], 422);
    }

    $search = trim((string) ($_GET['q'] ?? ''));
    $countryId = orange_admin_context_country_id($pdo);
    $rows = orange_invoice_ancillary_presets_list($pdo, $countryId, $invoiceContext, $search, true);

    $out = [];
    foreach ($rows as $row) {
        // بنود العروض/الولاء/التوصيل (لها مفتاح نظامي) تُطبَّق آلياً ولا يُفترض اختيارها يدوياً —
        // نُخفيها من قائمة «إضافة بند يدوي» حتى تبقى القائمة نظيفة بلا حسابات مكرّرة.
        if (trim((string) ($row['system_key'] ?? '')) !== '') {
            continue;
        }
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'account_id' => (int) ($row['account_id'] ?? 0),
            'account_code' => (string) ($row['account_code'] ?? ''),
            'account_name' => (string) ($row['account_name'] ?? ''),
            'label_ar' => (string) ($row['label_ar'] ?? ''),
            'label_en' => (string) ($row['label_en'] ?? ''),
            'line_kind' => (string) ($row['line_kind'] ?? ''),
            'system_key' => (string) ($row['system_key'] ?? ''),
            'default_show_on_print' => (int) ($row['default_show_on_print'] ?? 0) === 1,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }

    json_response(['success' => true, 'presets' => $out]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحميل القائمة المحفوظة');
}
