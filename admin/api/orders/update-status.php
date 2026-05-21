<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/order_stock.php';
require_once __DIR__ . '/../../../includes/order_fulfillment.php';
require_once __DIR__ . '/../../../includes/countries.php';
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
    orange_admin_assert_entity_country($pdo, 'orders', $orderId);

    $prevStatus = (string) ($order['status'] ?? '');

    if (
        in_array($status, ['cancelled', 'rejected'], true)
        && in_array($prevStatus, ['pending', 'approved', 'on_the_way'], true)
    ) {
        orange_order_release_pending_stock_reservation($pdo, $order);
    }

    $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$status, $orderId]);

    if ($status === 'completed' && $prevStatus !== 'completed') {
        orange_complete_order_fulfillment($pdo, $orderId);
    }

    if ($prevStatus === 'completed' && in_array($status, ['cancelled', 'rejected'], true)) {
        orange_order_reverse_completed_fulfillment($pdo, $orderId, $prevStatus, $status);
    }

    $pdo->commit();

    json_response(['success' => true, 'message' => 'تم تحديث حالة الطلب']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر تحديث حالة الطلب');
}
