<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/sales_invoice_online.php';
require_admin_api();

$orderId = (int) ($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    json_response(['success' => false, 'message' => 'معرف الفاتورة مطلوب'], 422);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $payload = orange_sales_invoice_online_document_payload($pdo, $orderId);
    json_response([
        'success' => true,
        'invoice' => $payload['order'],
        'items' => $payload['items'],
        'customer' => $payload['customer'],
    ]);
} catch (RuntimeException $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 422;
    }
    json_response(['success' => false, 'message' => $e->getMessage()], $code);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحميل فاتورة أونلاين');
}
