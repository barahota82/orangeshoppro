<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_table_exists($pdo, 'pattern_dictionary')) {
        json_response(['success' => false, 'message' => 'جدول الأنماط غير مهيأ'], 500);
    }
    $data = get_json_input();
    $pdo->prepare('UPDATE pattern_dictionary SET is_active = ? WHERE id = ? LIMIT 1')->execute([
        (int) ($data['is_active'] ?? 0),
        (int) ($data['id'] ?? 0),
    ]);
    json_response(['success' => true, 'message' => 'تم تحديث حالة النمط']);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذّر تحديث حالة النمط');
}
