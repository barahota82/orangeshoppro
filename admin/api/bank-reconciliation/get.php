<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/bank_reconciliation.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_bank_reconciliation_ready($pdo)) {
        json_response(['success' => false, 'message' => 'جداول تسوية البنك غير جاهزة'], 500);
    }

    $ctxCountryId = orange_admin_settings_effective_country_id($pdo);
    $data = get_json_input();
    $id = (int) ($data['id'] ?? 0);

    $rec = orange_bank_reconciliation_get($pdo, $id, $ctxCountryId);
    if ($rec === null) {
        json_response(['success' => false, 'message' => 'جلسة التسوية غير موجودة'], 404);
    }

    json_response(['success' => true, 'reconciliation' => $rec]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحميل تسوية البنك');
}
