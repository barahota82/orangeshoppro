<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/order_fulfillment.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $orderIds = [];
    if (isset($data['order_ids']) && is_array($data['order_ids'])) {
        foreach ($data['order_ids'] as $rawId) {
            $id = (int) $rawId;
            if ($id > 0) {
                $orderIds[] = $id;
            }
        }
    } elseif (isset($data['order_id'])) {
        $id = (int) $data['order_id'];
        if ($id > 0) {
            $orderIds[] = $id;
        }
    }

    $orderIds = array_values(array_unique($orderIds));
    if ($orderIds === []) {
        json_response(['success' => false, 'message' => 'حدّد طلباً واحداً على الأقل'], 422);
    }

    $pdo->beginTransaction();

    $posted = [];
    $skipped = [];
    foreach ($orderIds as $orderId) {
        orange_admin_assert_entity_country($pdo, 'orders', $orderId);

        $st = $pdo->prepare('SELECT id, order_number, status FROM orders WHERE id = ? LIMIT 1');
        $st->execute([$orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('طلب غير موجود: #' . $orderId);
        }
        if (($row['status'] ?? '') !== 'completed') {
            throw new RuntimeException(
                'الطلب ' . (string) ($row['order_number'] ?? $orderId) . ' ليس في حالة «تم التسليم»'
            );
        }

        $orderNumber = (string) ($row['order_number'] ?? '');
        if ($orderNumber !== '' && orange_order_forward_delivery_accounting_exists($pdo, $orderNumber)) {
            $skipped[] = $orderId;
            continue;
        }

        orange_post_order_delivery_accounting($pdo, $orderId);
        $posted[] = $orderId;
    }

    $pdo->commit();

    json_response([
        'success' => true,
        'message' => 'تم إنشاء القيود',
        'posted_order_ids' => $posted,
        'skipped_order_ids' => $skipped,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر إنشاء القيود');
}
