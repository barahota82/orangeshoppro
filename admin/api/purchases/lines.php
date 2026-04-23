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

$hasV = orange_table_has_column($pdo, 'purchase_items', 'variant_id');

$st = $pdo->prepare('SELECT id FROM purchases WHERE id = ? LIMIT 1');
$st->execute([$purchaseId]);
if (!$st->fetchColumn()) {
    json_response(['success' => false, 'message' => 'الفاتورة غير موجودة'], 404);
}

$base = 'SELECT pi.id AS item_id, pi.product_id, pi.qty, pi.qty_received, pr.name AS product_name';
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

$stmt = $pdo->prepare($base);
$stmt->execute([$purchaseId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$items = [];
foreach ($rows as $row) {
    $qty = (int) ($row['qty'] ?? 0);
    $recv = (int) ($row['qty_received'] ?? 0);
    $rem = max(0, $qty - $recv);
    $c = trim((string) ($row['v_color'] ?? ''));
    $s = trim((string) ($row['v_size'] ?? ''));
    $vid = (int) ($row['variant_id'] ?? 0);
    $label = ($c !== '' || $s !== '')
        ? trim($c . ($c !== '' && $s !== '' ? ' / ' : '') . $s)
        : ($vid > 0 ? ('#' . $vid) : '—');
    $items[] = [
        'item_id' => (int) $row['item_id'],
        'product_id' => (int) $row['product_id'],
        'product_name' => (string) $row['product_name'],
        'variant_label' => $label,
        'qty' => $qty,
        'qty_received' => $recv,
        'remaining' => $rem,
    ];
}

json_response(['success' => true, 'purchase_id' => $purchaseId, 'items' => $items]);
