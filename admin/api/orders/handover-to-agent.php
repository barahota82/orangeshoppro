<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/delivery_agents.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/admin_time.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    if (!orange_table_has_column($pdo, 'orders', 'delivery_agent_id')) {
        json_response(['success' => false, 'message' => 'عمود delivery_agent_id غير جاهز'], 422);
    }

    $agentId = (int) ($data['agent_id'] ?? 0);
    $orderIds = [];
    if (isset($data['order_ids']) && is_array($data['order_ids'])) {
        foreach ($data['order_ids'] as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $orderIds[] = $id;
            }
        }
    }
    $orderIds = array_values(array_unique($orderIds));

    if ($agentId <= 0) {
        json_response(['success' => false, 'message' => 'اختر مندوباً'], 422);
    }
    if ($orderIds === []) {
        json_response(['success' => false, 'message' => 'حدّد طلباً واحداً على الأقل'], 422);
    }

    $agent = orange_delivery_agent_row_by_id($pdo, $agentId);
    if (!$agent) {
        json_response(['success' => false, 'message' => 'المندوب غير موجود'], 404);
    }
    if (!in_array((string) ($agent['status'] ?? ''), orange_delivery_agent_assignable_statuses(), true)) {
        json_response(['success' => false, 'message' => 'المندوب غير نشط — لا يمكن التوزيع عليه'], 422);
    }

    orange_admin_assert_row_country($pdo, 'delivery_agents', $agentId);

    $allowedStatuses = orange_delivery_agent_reassignable_order_statuses();
    $pdo->beginTransaction();
    $updated = 0;

    foreach ($orderIds as $orderId) {
        orange_admin_assert_entity_country($pdo, 'orders', $orderId);
        $st = $pdo->prepare('SELECT id, status, order_source FROM orders WHERE id = ? LIMIT 1');
        $st->execute([$orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new RuntimeException('طلب غير موجود: #' . $orderId);
        }
        $status = strtolower(trim((string) ($order['status'] ?? '')));
        if (!in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException('الطلب #' . $orderId . ' غير مؤهل للتوزيع (الحالة: ' . $status . ')');
        }
        $src = trim((string) ($order['order_source'] ?? 'website'));
        if ($src === 'company') {
            throw new RuntimeException('طلب الشركة #' . $orderId . ' خارج مسار توزيع المناديب');
        }
        $pdo->prepare('UPDATE orders SET delivery_agent_id = ?, updated_at = ? WHERE id = ?')
            ->execute([$agentId, orange_admin_time_utc_now_mysql(), $orderId]);
        ++$updated;
    }

    $pdo->commit();

    json_response([
        'success' => true,
        'message' => 'تم توزيع ' . $updated . ' طلب/طلبات على المندوب',
        'updated' => $updated,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر توزيع الطلبات على المندوب');
}
