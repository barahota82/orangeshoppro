<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

try {
    $pdo = db();
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

    audit_log('order_update', 'تم تعديل الطلب رقم: ' . $order['order_number'], 'orders', $id);
    json_response(['success' => true, 'message' => 'تم تعديل الطلب']);
} catch (Throwable $e) {
    api_error($e, 'تعذر تعديل الطلب');
}
