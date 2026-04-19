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
        json_response(['success' => false, 'message' => 'حذف صف الطابور يتطلب صلاحية حذف على المبيعات'], 403);
    }

    if (!orange_table_exists($pdo, 'order_intake_queue')) {
        json_response(['success' => false, 'message' => 'جدول الطابور غير موجود'], 503);
    }

    $data = get_json_input();
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرّف غير صالح'], 422);
    }

    $chk = $pdo->prepare('SELECT id, status FROM order_intake_queue WHERE id = ? LIMIT 1');
    $chk->execute([$id]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(['success' => false, 'message' => 'الصف غير موجود'], 404);
    }
    if (($row['status'] ?? '') !== 'failed') {
        json_response(['success' => false, 'message' => 'يُسمح بحذف صفوف بحالة «فاشل» فقط'], 422);
    }

    $del = $pdo->prepare("DELETE FROM order_intake_queue WHERE id = ? AND status = 'failed'");
    $del->execute([$id]);
    if ($del->rowCount() !== 1) {
        json_response(['success' => false, 'message' => 'تعذّر الحذف'], 500);
    }

    audit_log('order_intake_delete_row', 'حذف صف فاشل من طابور طلبات الموقع #' . $id, 'order_intake_queue', $id);
    json_response(['success' => true, 'message' => 'تم حذف الصف']);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
