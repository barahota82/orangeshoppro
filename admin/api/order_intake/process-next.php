<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/order_intake_queue.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'order_intake_queue')) {
        json_response(['success' => false, 'message' => 'جدول الطابور غير موجود'], 503);
    }

    $data = get_json_input();
    $count = (int) ($data['count'] ?? 5);
    $count = max(1, min(50, $count));

    $processed = 0;
    for ($i = 0; $i < $count; ++$i) {
        if (!orange_order_intake_process_next($pdo)) {
            break;
        }
        ++$processed;
    }

    if ($processed > 0) {
        audit_log(
            'order_intake_process',
            'معالجة طابور طلبات الموقع: ' . $processed . ' مهمة',
            'order_intake_queue',
            0
        );
    }

    $msg = $processed === 0
        ? 'لا توجد مهام معلّقة في الطابور'
        : ('تمت معالجة ' . $processed . ' ' . ($processed === 1 ? 'مهمة' : 'مهام'));
    json_response(['success' => true, 'processed' => $processed, 'message' => $msg]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر معالجة الطابور');
}
