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

    $invoiceContext = trim((string) ($_GET['invoice_context'] ?? ''));
    $ctxFilter = $invoiceContext !== '' ? $invoiceContext : null;
    if ($ctxFilter !== null && !in_array($ctxFilter, orange_invoice_ancillary_invoice_contexts(), true)) {
        json_response(['success' => false, 'message' => 'سياق الفاتورة غير صالح'], 422);
    }

    $search = trim((string) ($_GET['q'] ?? ''));
    $countryId = orange_admin_context_country_id($pdo);
    $rows = orange_invoice_ancillary_presets_list($pdo, $countryId, $ctxFilter, $search, false);

    $ctxLabels = orange_invoice_ancillary_invoice_context_labels();
    $out = [];
    foreach ($rows as $row) {
        $ctx = (string) ($row['invoice_context'] ?? '');
        $lineKind = (string) ($row['line_kind'] ?? '');
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'account_id' => (int) ($row['account_id'] ?? 0),
            'account_code' => (string) ($row['account_code'] ?? ''),
            'account_name' => (string) ($row['account_name'] ?? ''),
            'label_ar' => (string) ($row['label_ar'] ?? ''),
            'label_en' => (string) ($row['label_en'] ?? ''),
            'invoice_context' => $ctx,
            'invoice_context_label' => $ctxLabels[$ctx] ?? $ctx,
            'line_kind' => $lineKind,
            'line_kind_label' => orange_invoice_ancillary_line_kind_label($lineKind),
            'default_show_on_print' => (int) ($row['default_show_on_print'] ?? 0) === 1,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
        ];
    }

    json_response(['success' => true, 'presets' => $out]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحميل القائمة');
}
