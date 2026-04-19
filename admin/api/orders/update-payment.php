<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_has_column($pdo, 'orders', 'amount_paid')) {
        json_response(['success' => false, 'message' => 'عمود المدفوع غير مُهيأ — شغّل ترحيل قاعدة البيانات'], 422);
    }
    $data = get_json_input();
    $orderId = (int) ($data['order_id'] ?? 0);
    if ($orderId <= 0) {
        json_response(['success' => false, 'message' => 'معرف الطلب مطلوب'], 422);
    }
    $st = $pdo->prepare('SELECT id, total FROM orders WHERE id = ? LIMIT 1');
    $st->execute([$orderId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(['success' => false, 'message' => 'الطلب غير موجود'], 404);
    }
    $total = max(0.0, (float) ($row['total'] ?? 0));
    $paid = max(0.0, (float) ($data['amount_paid'] ?? 0));
    if ($paid > $total + 0.0001) {
        $paid = $total;
    }
    $pdo->prepare('UPDATE orders SET amount_paid = ? WHERE id = ?')->execute([$paid, $orderId]);
    json_response(['success' => true, 'message' => 'تم حفظ المدفوع']);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
