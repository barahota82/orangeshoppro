<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/opening_stock_lock.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    if (!array_key_exists('locked', $data)) {
        json_response(['success' => false, 'message' => 'حدّد حالة الإقفال'], 422);
    }

    $locked = !empty($data['locked']);
    $countryId = orange_admin_context_country_id($pdo);
    if ($countryId <= 0) {
        json_response(['success' => false, 'message' => 'حدّد سياق الدولة في الأدمن'], 422);
    }

    orange_opening_stock_set_locked($pdo, $locked, $countryId);

    json_response([
        'success' => true,
        'message' => $locked ? 'تم إقفال رصيد المخزون الافتتاحي' : 'تم فك إقفال رصيد المخزون الافتتاحي',
        'locked' => $locked,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ حالة الإقفال');
}
