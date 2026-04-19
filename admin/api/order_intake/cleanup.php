<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/admin_permissions.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $stAdmin = $pdo->prepare('SELECT * FROM admins WHERE id = ? AND is_active = 1 LIMIT 1');
    $stAdmin->execute([(int) $_SESSION['admin_id']]);
    $admin = $stAdmin->fetch(PDO::FETCH_ASSOC);
    if (!$admin || !orange_admin_may($admin, $pdo, 'sales', 'delete')) {
        json_response(['success' => false, 'message' => 'تنظيف الطابور يتطلب صلاحية حذف على المبيعات'], 403);
    }

    if (!orange_table_exists($pdo, 'order_intake_queue')) {
        json_response(['success' => false, 'message' => 'جدول الطابور غير موجود'], 503);
    }

    $data = get_json_input();
    $completedDays = (int) ($data['completed_older_than_days'] ?? 30);
    $failedDays = (int) ($data['failed_older_than_days'] ?? 90);
    $maxRows = (int) ($data['max_rows_per_status'] ?? 2000);
    $maxRows = max(100, min(10000, $maxRows));

    $completedDays = max(1, min(3650, $completedDays));
    $failedDays = max(1, min(3650, $failedDays));

    $delCompleted = $pdo->prepare(
        "DELETE FROM order_intake_queue WHERE status = 'completed' AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT {$maxRows}"
    );
    $delCompleted->execute([$completedDays]);
    $nCompleted = $delCompleted->rowCount();

    $delFailed = $pdo->prepare(
        "DELETE FROM order_intake_queue WHERE status = 'failed' AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT {$maxRows}"
    );
    $delFailed->execute([$failedDays]);
    $nFailed = $delFailed->rowCount();

    audit_log(
        'order_intake_cleanup',
        'تنظيف طابور طلبات الموقع: مكتمل قديم>' . $completedDays . 'd حذف ' . $nCompleted . '، فاشل قديم>' . $failedDays . 'd حذف ' . $nFailed,
        'order_intake_queue',
        0
    );

    json_response([
        'success' => true,
        'deleted_completed' => $nCompleted,
        'deleted_failed' => $nFailed,
        'message' => 'تم التنظيف',
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
