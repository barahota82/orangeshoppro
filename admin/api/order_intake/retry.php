<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/order_intake_queue.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/admin_time.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'order_intake_queue')) {
        json_response(['success' => false, 'message' => 'جدول الطابور غير موجود'], 503);
    }

    $data = get_json_input();
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرّف غير صالح'], 422);
    }

    try {
        orange_admin_assert_order_intake_id($pdo, $id);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    $upd = $pdo->prepare(
        "UPDATE order_intake_queue SET status = 'pending', error_message = NULL, attempts = 0,
         order_id = NULL, order_number = NULL, whatsapp_url = NULL, whatsapp_number = NULL,
         updated_at = " . orange_admin_time_sql_from_unix() . "
         WHERE id = ? AND status = 'failed'"
    );
    $upd->execute([orange_admin_time_unix_now(), $id]);
    if ($upd->rowCount() !== 1) {
        json_response(['success' => false, 'message' => 'الصف ليس بحالة فاشل أو غير موجود'], 422);
    }

    audit_log('order_intake_retry', 'إعادة طلب ويب إلى طابور الانتظار #' . $id, 'order_intake_queue', $id);
    json_response(['success' => true, 'message' => 'أُعيد الصف إلى قيد الانتظار']);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر إعادة المحاولة');
}
