<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/order_fulfillment.php';
require_once __DIR__ . '/../../../includes/order_stock.php';
require_once __DIR__ . '/../../../includes/delivery_agents.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/admin_time.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $status = strtolower(trim((string) ($data['status'] ?? '')));
    $agentId = (int) ($data['agent_id'] ?? 0);

    $allowedStatus = ['on_the_way', 'completed'];
    if (!in_array($status, $allowedStatus, true)) {
        json_response(['success' => false, 'message' => 'حالة غير مدعومة للتحديث الجماعي'], 422);
    }
    if ($agentId <= 0) {
        json_response(['success' => false, 'message' => 'اختر مندوباً'], 422);
    }
    if (!orange_table_has_column($pdo, 'orders', 'delivery_agent_id')) {
        json_response(['success' => false, 'message' => 'عمود delivery_agent_id غير جاهز'], 422);
    }

    $agent = orange_delivery_agent_row_by_id($pdo, $agentId);
    if (!$agent) {
        json_response(['success' => false, 'message' => 'المندوب غير موجود'], 404);
    }
    orange_admin_assert_row_country($pdo, 'delivery_agents', $agentId);

    $adminCountryId = orange_admin_context_country_id($pdo);

    $sql = "
        SELECT o.id, o.status, o.order_number
        FROM orders o
        WHERE o.delivery_agent_id = ?
          AND o.status NOT IN ('cancelled', 'rejected', 'completed')
    ";
    $params = [$agentId];
    $countryFilter = orange_sql_filter_country_id($pdo, 'orders', 'o', $adminCountryId);
    if ($countryFilter !== null) {
        $sql .= $countryFilter['sql'];
        $params[] = $countryFilter['param'];
    }

    if ($status === 'on_the_way') {
        $sql .= " AND o.status = 'approved'";
    } elseif ($status === 'completed') {
        $sql .= " AND o.status = 'on_the_way'";
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($rows === []) {
        json_response(['success' => false, 'message' => 'لا توجد طلبات مؤهلة للتحديث'], 422);
    }

    $pdo->beginTransaction();
    $count = 0;

    foreach ($rows as $row) {
        $orderId = (int) ($row['id'] ?? 0);
        if ($orderId <= 0) {
            continue;
        }
        orange_admin_assert_entity_country($pdo, 'orders', $orderId);

        $prevStatus = (string) ($row['status'] ?? '');
        orange_order_guard_status_transition($prevStatus, $status);

        $utcNow = orange_admin_time_utc_now_mysql();
        $setCompletedAt = ($status === 'completed'
            && orange_table_has_column($pdo, 'orders', 'completed_at'));
        if ($setCompletedAt) {
            $pdo->prepare('UPDATE orders SET status = ?, completed_at = ?, updated_at = ? WHERE id = ?')
                ->execute([$status, $utcNow, $utcNow, $orderId]);
        } else {
            $pdo->prepare('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?')
                ->execute([$status, $utcNow, $orderId]);
        }

        if ($status === 'completed') {
            orange_complete_order_fulfillment($pdo, $orderId);
        }

        ++$count;
    }

    $pdo->commit();

    $label = $status === 'on_the_way' ? 'بالطريق' : 'تم التوصيل';
    json_response([
        'success' => true,
        'message' => 'تم تحديث ' . $count . ' طلب إلى «' . $label . '»',
        'updated' => $count,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر التحديث الجماعي للطلبات');
}
