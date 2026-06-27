<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/sales_return_analytics.php';
require_once __DIR__ . '/../../../includes/invoice_ancillary_lines.php';
require_admin_api();

$pdo = db();
orange_catalog_ensure_schema($pdo);
$returnId = (int) ($_GET['sales_return_id'] ?? $_GET['id'] ?? 0);
if ($returnId <= 0) {
    json_response(['success' => false, 'message' => 'معرف غير صالح'], 422);
}

$st = $pdo->prepare('SELECT * FROM sales_returns WHERE id = ? LIMIT 1');
$st->execute([$returnId]);
$header = $st->fetch(PDO::FETCH_ASSOC);
if (!$header) {
    json_response(['success' => false, 'message' => 'غير موجود'], 404);
}

try {
    $srCustomerId = (int) ($header['customer_id'] ?? 0);
    $srOrderId = (int) ($header['order_id'] ?? 0);
    if ($srCustomerId > 0) {
        orange_admin_assert_entity_country($pdo, 'customers', $srCustomerId);
    } elseif ($srOrderId > 0) {
        orange_admin_assert_entity_country($pdo, 'orders', $srOrderId);
    }
} catch (RuntimeException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 403);
}

$hasV = orange_table_has_column($pdo, 'sales_return_items', 'variant_id');
$cols = 'id, product_id, qty, price, line_discount';
if ($hasV) {
    $cols .= ', variant_id';
}
$it = $pdo->prepare(
    'SELECT ' . $cols . ' FROM sales_return_items WHERE sales_return_id = ? ORDER BY id ASC'
);
$it->execute([$returnId]);
$items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];

$orderReference = '';
if (orange_table_has_column($pdo, 'sales_returns', 'invoice_reference')) {
    $orderReference = trim((string) ($header['invoice_reference'] ?? ''));
}
$srOrderChannelId = 0;
if ($srOrderId > 0 && orange_table_exists($pdo, 'orders')) {
    $hasOrderChannelId = orange_table_has_column($pdo, 'orders', 'channel_id');
    $oCols = 'id, order_source';
    if (orange_table_has_column($pdo, 'orders', 'invoice_number')) {
        $oCols .= ', invoice_number';
    }
    if (orange_table_has_column($pdo, 'orders', 'order_number')) {
        $oCols .= ', order_number';
    }
    if ($hasOrderChannelId) {
        $oCols .= ', channel_id';
    }
    $ost = $pdo->prepare("SELECT $oCols FROM orders WHERE id = ? LIMIT 1");
    $ost->execute([$srOrderId]);
    $orow = $ost->fetch(PDO::FETCH_ASSOC);
    if (is_array($orow)) {
        if ($orderReference === '') {
            $orderReference = orange_sales_return_invoice_reference_from_order($orow, $srOrderId);
        }
        $srOrderChannelId = $hasOrderChannelId ? (int) ($orow['channel_id'] ?? 0) : 0;
    }
}

json_response([
    'success' => true,
    'sales_return' => [
        'id' => (int) ($header['id'] ?? 0),
        'customer_id' => $header['customer_id'] !== null ? (int) $header['customer_id'] : 0,
        'order_id' => isset($header['order_id']) && $header['order_id'] !== null
            ? (int) $header['order_id']
            : 0,
        'order_reference' => $orderReference,
        'channel_id' => $srOrderChannelId,
        'source_kind' => (string) ($header['source_kind'] ?? ''),
        'type' => (string) ($header['type'] ?? 'cash'),
        'channel' => (string) ($header['type'] ?? 'cash'),
        'notes' => (string) ($header['notes'] ?? ''),
        'total' => (float) ($header['total'] ?? 0),
        'return_number' => (string) ($header['return_number'] ?? ''),
        'document_date' => (string) ($header['document_date'] ?? ''),
        'created_at' => (string) ($header['created_at'] ?? ''),
    ],
    'items' => array_map(static function (array $row): array {
        return [
            'product_id' => (int) ($row['product_id'] ?? 0),
            'variant_id' => isset($row['variant_id']) ? (int) $row['variant_id'] : 0,
            'qty' => (int) ($row['qty'] ?? 0),
            'price' => (float) ($row['price'] ?? 0),
            'line_discount' => (float) ($row['line_discount'] ?? 0),
        ];
    }, $items),
    'extra_lines' => orange_invoice_ancillary_extra_lines_for_doc(
        $pdo,
        orange_invoice_ancillary_doc_kind_sales_return(),
        $returnId
    ),
    'loyalty_clawback' => (static function (PDO $pdo, int $returnId): array {
        if (!orange_table_exists($pdo, 'loyalty_ledger')) {
            return ['total_points' => 0];
        }
        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(-points), 0) FROM loyalty_ledger
             WHERE kind = 'return_clawback' AND ref_type = 'sales_return' AND ref_id = ?"
        );
        $st->execute([$returnId]);

        return ['total_points' => (int) $st->fetchColumn()];
    })($pdo, $returnId),
]);
