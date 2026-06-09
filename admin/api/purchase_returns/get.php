<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

$pdo = db();
orange_catalog_ensure_schema($pdo);
$returnId = (int) ($_GET['purchase_return_id'] ?? $_GET['id'] ?? 0);
if ($returnId <= 0) {
    json_response(['success' => false, 'message' => 'معرف غير صالح'], 422);
}

$st = $pdo->prepare('SELECT * FROM purchase_returns WHERE id = ? LIMIT 1');
$st->execute([$returnId]);
$header = $st->fetch(PDO::FETCH_ASSOC);
if (!$header) {
    json_response(['success' => false, 'message' => 'غير موجود'], 404);
}

try {
    $supplierId = (int) ($header['supplier_id'] ?? 0);
    if ($supplierId > 0) {
        orange_admin_assert_entity_country($pdo, 'suppliers', $supplierId);
    }
} catch (RuntimeException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 403);
}

$hasV = orange_table_has_column($pdo, 'purchase_return_items', 'variant_id');
$hasPriDiscount = orange_table_has_column($pdo, 'purchase_return_items', 'discount_raw');
$hasRetInvDiscount = orange_table_has_column($pdo, 'purchase_returns', 'invoice_discount_raw');
$hasRetSubtotal = orange_table_has_column($pdo, 'purchase_returns', 'subtotal');
$cols = 'id, product_id, qty, cost';
if ($hasV) {
    $cols .= ', variant_id';
}
if ($hasPriDiscount) {
    $cols .= ', discount_raw, discount_amount';
}
$it = $pdo->prepare(
    'SELECT ' . $cols . ' FROM purchase_return_items WHERE purchase_return_id = ? ORDER BY id ASC'
);
$it->execute([$returnId]);
$items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];

json_response([
    'success' => true,
    'purchase_return' => [
        'id' => (int) ($header['id'] ?? 0),
        'supplier_id' => $header['supplier_id'] !== null ? (int) $header['supplier_id'] : 0,
        'purchase_id' => isset($header['purchase_id']) && $header['purchase_id'] !== null
            ? (int) $header['purchase_id']
            : 0,
        'type' => (string) ($header['type'] ?? ''),
        'notes' => (string) ($header['notes'] ?? ''),
        'total' => (float) ($header['total'] ?? 0),
        'subtotal' => $hasRetSubtotal ? (float) ($header['subtotal'] ?? 0) : (float) ($header['total'] ?? 0),
        'invoice_discount_raw' => $hasRetInvDiscount ? trim((string) ($header['invoice_discount_raw'] ?? '')) : '',
        'invoice_discount_amount' => $hasRetInvDiscount ? (float) ($header['invoice_discount_amount'] ?? 0) : 0.0,
        'return_number' => (string) ($header['return_number'] ?? ''),
        'document_date' => (string) ($header['document_date'] ?? ''),
        'created_at' => (string) ($header['created_at'] ?? ''),
    ],
    'items' => array_map(static function (array $row) use ($hasPriDiscount): array {
        $item = [
            'product_id' => (int) ($row['product_id'] ?? 0),
            'variant_id' => isset($row['variant_id']) ? (int) $row['variant_id'] : 0,
            'qty' => (int) ($row['qty'] ?? 0),
            'cost' => (float) ($row['cost'] ?? 0),
        ];
        if ($hasPriDiscount) {
            $item['discount_raw'] = trim((string) ($row['discount_raw'] ?? ''));
            $item['discount_amount'] = (float) ($row['discount_amount'] ?? 0);
        }

        return $item;
    }, $items),
]);
