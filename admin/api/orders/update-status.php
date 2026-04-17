<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $orderId = (int)($data['order_id'] ?? 0);
    $status = trim((string)($data['status'] ?? ''));

    $allowed = ['pending', 'approved', 'on_the_way', 'completed', 'rejected', 'cancelled'];
    if ($orderId <= 0 || !in_array($status, $allowed, true)) {
        json_response(['success' => false, 'message' => 'بيانات غير صحيحة'], 422);
    }

    $pdo->beginTransaction();

    $orderStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch();

    if (!$order) {
        throw new RuntimeException('الطلب غير موجود');
    }

    $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$status, $orderId]);

    if ($status === 'completed' && $order['status'] !== 'completed') {
        require_once __DIR__ . '/../../../includes/order_fulfillment.php';
        orange_complete_order_fulfillment($pdo, $orderId);
    }

    $pdo->commit();

    json_response(['success' => true, 'message' => 'تم تحديث حالة الطلب']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
