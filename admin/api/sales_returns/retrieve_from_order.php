<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/sales_return_helpers.php';
require_once __DIR__ . '/../../../includes/purchase_helpers.php';
require_admin_api();

$orderId = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    $ref = trim((string) ($_GET['reference'] ?? $_POST['reference'] ?? ''));
    if (preg_match('/^INV-C-(\d+)$/i', $ref, $m)) {
        $orderId = (int) $m[1];
    } elseif (preg_match('/^INV-O-(\d+)$/i', $ref, $mO)) {
        $orderId = (int) $mO[1];
    } elseif ($ref !== '' && ctype_digit($ref)) {
        $orderId = (int) $ref;
    }
}
if ($orderId <= 0) {
    $ref = trim((string) ($_GET['reference'] ?? $_POST['reference'] ?? ''));
    if ($ref !== '' && $orderId <= 0) {
        $pdoRef = db();
        orange_catalog_ensure_schema($pdoRef);
        if (orange_table_exists($pdoRef, 'orders')) {
            $stRef = $pdoRef->prepare(
                'SELECT id FROM orders WHERE order_number = ? OR invoice_number = ? LIMIT 1'
            );
            $stRef->execute([$ref, $ref]);
            $orderId = (int) ($stRef->fetchColumn() ?: 0);
        }
    }
}
if ($orderId <= 0) {
    json_response(['success' => false, 'message' => 'أدخل رقم طلب/فاتورة صالحاً (INV-C- أو رقم)'], 422);
}

$pdo = db();
orange_catalog_ensure_schema($pdo);

$orderCols = 'id, customer_id, payment_terms, order_source, notes, total';
if (orange_table_has_column($pdo, 'orders', 'invoice_number')) {
    $orderCols .= ', invoice_number';
}
if (orange_table_has_column($pdo, 'orders', 'order_number')) {
    $orderCols .= ', order_number';
}

$st = $pdo->prepare("SELECT $orderCols FROM orders WHERE id = ? LIMIT 1");
$st->execute([$orderId]);
$order = $st->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    json_response(['success' => false, 'message' => 'الطلب المرجعي غير موجود'], 404);
}

try {
    orange_admin_assert_entity_country($pdo, 'orders', $orderId);
} catch (RuntimeException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 403);
}

$invNum = trim((string) ($order['invoice_number'] ?? ''));
$orderRef = $invNum !== '' ? $invNum : ('INV-C-' . $orderId);
$accRow = orange_accounting_row_by_reference($pdo, $orderRef);
if ($accRow === null && $invNum === '') {
    $accRow = orange_accounting_row_by_reference($pdo, 'INV-C-' . $orderId);
}
if (orange_accounting_is_locked($pdo, $accRow)) {
    json_response([
        'success' => false,
        'message' => 'لا يمكن استرجاع بنود طلب مرتبط بسنة مالية مغلقة',
        'suggest_admin' => orange_gl_suggest_admin_fiscal_years_screen(),
    ], 422);
}

$hasV = orange_table_has_column($pdo, 'order_items', 'variant_id');
$hasLineDisc = orange_table_has_column($pdo, 'order_items', 'line_discount');
$cols = 'product_id, qty, price, cost';
if ($hasV) {
    $cols .= ', COALESCE(variant_id, 0) AS variant_id';
} else {
    $cols .= ', 0 AS variant_id';
}
if ($hasLineDisc) {
    $cols .= ', line_discount';
}

$stmt = $pdo->prepare(
    'SELECT ' . $cols . ' FROM order_items WHERE order_id = ? ORDER BY id ASC'
);
$stmt->execute([$orderId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$soldMap = orange_sales_return_sold_qty_map($pdo, $orderId);
$returnedMap = orange_sales_return_returned_qty_map($pdo, $orderId);

$items = [];
foreach ($rows as $row) {
    $productId = (int) ($row['product_id'] ?? 0);
    $variantId = (int) ($row['variant_id'] ?? 0);
    if ($productId <= 0) {
        continue;
    }
    $key = orange_sales_return_line_key($productId, $variantId);
    $soldQty = (int) ($soldMap[$key] ?? (int) ($row['qty'] ?? 0));
    $returnedQty = (int) ($returnedMap[$key] ?? 0);
    $availableQty = max(0, $soldQty - $returnedQty);
    if ($availableQty <= 0) {
        continue;
    }

    $items[] = [
        'product_id' => $productId,
        'variant_id' => $variantId,
        'qty' => $availableQty,
        'qty_sold' => $soldQty,
        'qty_already_returned' => $returnedQty,
        'qty_available' => $availableQty,
        'price' => (float) ($row['price'] ?? 0),
        'line_discount' => $hasLineDisc ? (float) ($row['line_discount'] ?? 0) : 0.0,
        'cost' => (float) ($row['cost'] ?? 0),
    ];
}

if ($items === []) {
    json_response([
        'success' => false,
        'message' => 'لا توجد كميات متبقية للإرجاع من هذا الطلب (ربما أُرجِعت بالكامل سابقاً)',
    ], 422);
}

json_response([
    'success' => true,
    'order' => [
        'id' => (int) ($order['id'] ?? 0),
        'reference' => $orderRef,
        'customer_id' => $order['customer_id'] !== null ? (int) $order['customer_id'] : 0,
        'channel' => orange_sales_return_channel_from_order($order),
        'notes' => (string) ($order['notes'] ?? ''),
        'order_number' => (string) ($order['order_number'] ?? ''),
    ],
    'items' => $items,
]);
