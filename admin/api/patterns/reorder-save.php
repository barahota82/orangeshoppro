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
    $ids = $data['ordered_ids'] ?? [];
    if (! is_array($ids) || count($ids) === 0) {
        json_response(['success' => false, 'message' => 'بيانات غير صحيحة'], 422);
    }

    $pdo->beginTransaction();
    $u = $pdo->prepare('UPDATE pattern_dictionary SET sort_order = ? WHERE id = ? LIMIT 1');
    $sort = 1;
    foreach ($ids as $pid) {
        $pid = (int) $pid;
        if ($pid > 0) {
            $u->execute([$sort, $pid]);
            $sort++;
        }
    }
    $pdo->commit();
    json_response(['success' => true, 'message' => 'تم حفظ ترتيب الأنماط']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذّر حفظ ترتيب الأنماط');
}
