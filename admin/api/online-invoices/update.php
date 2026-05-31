<?php

declare(strict_types=1);

/**
 * GAP-SALE-DOC-01 مرحلة 3 — تحديث فاتورة أونلاين (INV-O): ترويسة + بنود دون إعادة final-posting.
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/sales_invoice_online.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $orderId = (int) ($data['order_id'] ?? $data['id'] ?? 0);
    if ($orderId <= 0) {
        json_response(['success' => false, 'message' => 'معرف الفاتورة مطلوب'], 422);
    }

    $adminRow = current_admin();
    $result = orange_sales_invoice_online_apply_update($pdo, $orderId, $data, $adminRow);
    $payload = $result['payload'];
    $note = $result['items_gl_note'];

    $response = [
        'success' => true,
        'message' => $note !== null ? $note : 'تم حفظ فاتورة أونلاين',
        'order_id' => $orderId,
        'invoice' => $payload['order'],
        'items' => $payload['items'],
        'customer' => $payload['customer'],
    ];
    if ($note !== null) {
        $response['gl_sync_note'] = $note;
    }

    json_response($response);
} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 422;
    }
    json_response(['success' => false, 'message' => $e->getMessage()], $code);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر تحديث فاتورة أونلاين');
}
