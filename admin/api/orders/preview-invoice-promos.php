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
    $adminRestores = $data['admin_restores'] ?? [];

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

    $preview = orange_invoice_edit_preview($pdo, $orderId, $normalized, is_array($adminRestores) ? $adminRestores : []);

    json_response(array_merge(['success' => true], $preview));
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر معاينة العروض');
}
