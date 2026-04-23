<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

$purchaseId = (int) ($_GET['purchase_id'] ?? 0);
if ($purchaseId <= 0) {
    json_response(['success' => false, 'message' => 'معرف الفاتورة مطلوب'], 422);
}

$pdo = db();
if (!orange_table_has_column($pdo, 'purchase_items', 'qty_received')) {
    json_response(['success' => false, 'message' => 'قاعدة البيانات تحتاج ترقية (qty_received)'], 500);
}

$st = $pdo->prepare('SELECT id, supplier_id, type, notes, total FROM purchases WHERE id = ? LIMIT 1');
$st->execute([$purchaseId]);
$purchase = $st->fetch(PDO::FETCH_ASSOC);
if (!$purchase) {
    json_response(['success' => false, 'message' => 'الفاتورة غير موجودة'], 404);
}

$hasV = orange_table_has_column($pdo, 'purchase_items', 'variant_id');

$base = 'SELECT pi.product_id, pi.qty, pi.cost, pi.qty_received,
    pr.name AS product_name, pr.is_active AS product_is_active, pr.cost AS product_cost,
    pr.has_colors, pr.has_sizes';
if ($hasV) {
    $base .= ', pi.variant_id, pv.color AS v_color, pv.size AS v_size
        FROM purchase_items pi
        INNER JOIN products pr ON pr.id = pi.product_id
        LEFT JOIN product_variants pv ON pv.id = pi.variant_id AND pv.product_id = pi.product_id
        WHERE pi.purchase_id = ?';
} else {
    $base .= ", 0 AS variant_id, '' AS v_color, '' AS v_size
        FROM purchase_items pi
        INNER JOIN products pr ON pr.id = pi.product_id
        WHERE pi.purchase_id = ?";
}

$stmt = $pdo->prepare($base . ' ORDER BY pi.id ASC');
$stmt->execute([$purchaseId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$items = [];
$hasReceived = false;
foreach ($rows as $row) {
    $recv = (int) ($row['qty_received'] ?? 0);
    if ($recv > 0) {
        $hasReceived = true;
    }
    $vid = $hasV ? (int) ($row['variant_id'] ?? 0) : 0;
    $items[] = [
        'product_id' => (int) $row['product_id'],
        'variant_id' => $vid,
        'qty' => (int) $row['qty'],
        'cost' => (float) $row['cost'],
        'qty_received' => $recv,
        'product_name' => (string) $row['product_name'],
        'is_product_active' => (int) ($row['product_is_active'] ?? 1) === 1,
        'product_cost' => (float) ($row['product_cost'] ?? 0),
        'has_colors' => (int) ($row['has_colors'] ?? 0),
        'has_sizes' => (int) ($row['has_sizes'] ?? 0),
    ];
}

json_response([
    'success' => true,
    'purchase' => [
        'id' => (int) $purchase['id'],
        'supplier_id' => $purchase['supplier_id'] !== null ? (int) $purchase['supplier_id'] : 0,
        'type' => (string) $purchase['type'],
        'notes' => (string) ($purchase['notes'] ?? ''),
        'total' => (float) $purchase['total'],
    ],
    'items' => $items,
    'has_received_stock' => $hasReceived,
]);
