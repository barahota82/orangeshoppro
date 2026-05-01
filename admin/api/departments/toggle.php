<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_sizing_department_sync.php';
require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();

    $deptId = (int) ($data['id'] ?? 0);
    $stmt = $pdo->prepare('UPDATE departments SET is_active = ? WHERE id = ?');
    $stmt->execute([
        (int) ($data['is_active'] ?? 0),
        $deptId,
    ]);
    if ($deptId > 0) {
        orange_catalog_sync_commercial_kind_for_department($pdo, $deptId);
    }

    json_response(['success' => true, 'message' => 'تم تحديث حالة القسم']);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحديث حالة القسم');
}
