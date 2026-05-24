<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/invoice_edit_helpers.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $orderId = (int) ($data['order_id'] ?? 0);
    $changes = $data['changes'] ?? [];
    $markCompleted = !empty($data['mark_completed']);

    if ($orderId <= 0 || !is_array($changes)) {
        json_response(['success' => false, 'message' => 'بيانات غير صحيحة'], 422);
    }

    orange_admin_assert_entity_country($pdo, 'orders', $orderId);

    $normalized = [];
    foreach ($changes as $row) {
        if (!is_array($row)) {
            continue;
        }
        $normalized[] = [
            'item_id' => (int) ($row['item_id'] ?? 0),
            'qty' => (int) ($row['qty'] ?? 0),
        ];
    }

    $pdo->beginTransaction();
    $result = orange_invoice_edit_apply($pdo, $orderId, $normalized, $markCompleted);
    $pdo->commit();

    json_response([
        'success' => true,
        'message' => $markCompleted ? 'تم الحفظ وتم التسليم' : 'تم حفظ التعديل',
        'total' => $result['total'],
        'promo_summary' => $result['promo_summary'],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر حفظ تعديل الفاتورة');
}
