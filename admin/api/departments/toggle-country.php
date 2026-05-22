<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/department_countries.php';
require_admin_api();

try {
    orange_department_countries_require_global_admin();
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $id = (int) ($data['id'] ?? 0);
    $countryId = orange_admin_context_country_id($pdo);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف القسم مطلوب'], 422);
    }
    if ($countryId <= 0) {
        json_response(['success' => false, 'message' => 'اختر دولة من الشريط أولاً'], 422);
    }
    if (!orange_table_exists($pdo, 'departments')) {
        json_response(['success' => false, 'message' => 'جدول الأقسام غير موجود'], 500);
    }
    $chk = $pdo->prepare('SELECT id FROM departments WHERE id = ? LIMIT 1');
    $chk->execute([$id]);
    if (!$chk->fetch()) {
        json_response(['success' => false, 'message' => 'القسم غير موجود'], 404);
    }
    $active = (int) ($data['is_active'] ?? 0) === 1;
    orange_department_countries_set($pdo, $id, $countryId, $active);
    json_response([
        'success' => true,
        'message' => $active ? 'تم تفعيل القسم في الدولة الحالية' : 'تم إخفاء القسم في الدولة الحالية',
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحديث تفعيل القسم في الدولة');
}
