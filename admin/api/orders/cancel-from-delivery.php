<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/order_stock.php';
require_once __DIR__ . '/../../../includes/order_fulfillment.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/loyalty.php';
require_once __DIR__ . '/../../../includes/admin_time.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $orderId = (int) ($data['order_id'] ?? 0);
    if ($orderId <= 0) {
        json_response(['success' => false, 'message' => 'بيانات غير صحيحة'], 422);
    }

    orange_admin_assert_entity_country($pdo, 'orders', $orderId);

    $pdo->beginTransaction();

    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $st->execute([$orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('الطلب غير موجود');
    }

    $prevStatus = strtolower(trim((string) ($order['status'] ?? '')));
    $eligible = ['approved', 'on_the_way'];
    if (!in_array($prevStatus, $eligible, true)) {
        throw new RuntimeException('الطلب غير مؤهل للإلغاء من مسار التسليم — الحالة: ' . $prevStatus);
    }

    $src = trim((string) ($order['order_source'] ?? 'website'));
    if ($src === 'company') {
        throw new RuntimeException('طلب الشركة — استخدم شاشة الطلبات العادية');
    }

    orange_order_release_pending_stock_reservation($pdo, $order);
    if (in_array($prevStatus, ['pending', 'approved', 'on_the_way'], true)) {
        orange_loyalty_restore_for_order($pdo, $orderId);
    }

    $pdo->prepare('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?')
        ->execute(['cancelled', orange_admin_time_utc_now_mysql(), $orderId]);

    $pdo->commit();

    json_response([
        'success' => true,
        'message' => 'تم إلغاء الطلب (مرتجع كامل) — cancelled',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر إلغاء الطلب من مسار التسليم');
}
