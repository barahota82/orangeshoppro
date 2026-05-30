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

    $ordered = $data['ordered_ids'] ?? $data['ids'] ?? [];
    if (!is_array($ordered) || $ordered === []) {
        json_response(['success' => false, 'message' => 'قائمة الترتيب مطلوبة'], 422);
    }

    $countryId = orange_admin_context_country_id($pdo);
    orange_invoice_ancillary_presets_reorder($pdo, $ordered, $countryId);

    json_response(['success' => true, 'message' => 'تم حفظ الترتيب']);
} catch (RuntimeException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ الترتيب');
}
