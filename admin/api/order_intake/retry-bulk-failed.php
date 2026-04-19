<?php

declare(strict_types=1);

/**
 * إعادة صفوف فاشلة متعددة إلى حالة pending (حد أقصى لكل طلب).
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'order_intake_queue')) {
        json_response(['success' => false, 'message' => 'جدول الطابور غير موجود'], 503);
    }

    $data = get_json_input();
    $max = (int) ($data['max'] ?? 25);
    $max = max(1, min(200, $max));

    $upd = $pdo->prepare(
        "UPDATE order_intake_queue SET status = 'pending', error_message = NULL, attempts = 0,
         order_id = NULL, order_number = NULL, whatsapp_url = NULL, whatsapp_number = NULL
         WHERE status = 'failed' ORDER BY id ASC LIMIT {$max}"
    );
    $upd->execute();
    $n = $upd->rowCount();

    audit_log(
        'order_intake_retry_bulk',
        'إعادة جماعية لصفوف فاشلة في طابور الموقع: ' . $n . ' صف (حد أقصى طلب ' . $max . ')',
        'order_intake_queue',
        0
    );

    json_response([
        'success' => true,
        'reset_count' => $n,
        'message' => $n === 0
            ? 'لا توجد صفوف فاشلة ضمن الحد المحدد'
            : ('أُعيد ' . $n . ' صفاً إلى قيد الانتظار'),
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
