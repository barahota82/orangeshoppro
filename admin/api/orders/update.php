<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/order_stock.php';
require_once __DIR__ . '/../../../includes/order_fulfillment.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف الطلب مطلوب'], 422);
    }

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) {
        json_response(['success' => false, 'message' => 'الطلب غير موجود'], 404);
    }

    $status = trim((string)($data['status'] ?? $order['status']));
    $allowed = ['pending', 'approved', 'on_the_way', 'completed', 'rejected', 'cancelled'];
    if (!in_array($status, $allowed, true)) {
        json_response(['success' => false, 'message' => 'حالة الطلب غير صحيحة'], 422);
    }

    $pdo->beginTransaction();

    $prevStatus = (string)($order['status'] ?? '');
    if (
        in_array($status, ['cancelled', 'rejected'], true)
        && in_array($prevStatus, ['pending', 'approved', 'on_the_way'], true)
    ) {
        orange_order_release_pending_stock_reservation($pdo, $order);
    }

    $pdo->prepare("
        UPDATE orders
        SET customer_name = ?, phone = ?, area = ?, address = ?, notes = ?, channel_id = ?, status = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([
        trim((string)($data['customer_name'] ?? $order['customer_name'])),
        trim((string)($data['phone'] ?? $order['phone'])),
        trim((string)($data['area'] ?? $order['area'])),
        trim((string)($data['address'] ?? $order['address'])),
        trim((string)($data['notes'] ?? $order['notes'])),
        isset($data['channel_id']) ? (int)$data['channel_id'] : (int)$order['channel_id'],
        $status,
        $id
    ]);

    if ($status === 'completed' && $prevStatus !== 'completed') {
        orange_complete_order_fulfillment($pdo, $id);
    }

    $pdo->commit();

    audit_log('order_update', 'تم تعديل الطلب رقم: ' . $order['order_number'], 'orders', $id);
    json_response(['success' => true, 'message' => 'تم تعديل الطلب']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e, 'تعذر تعديل الطلب');
}
