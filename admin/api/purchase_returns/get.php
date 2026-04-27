<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
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

$hasV = orange_table_has_column($pdo, 'purchase_return_items', 'variant_id');
$cols = 'id, product_id, qty, cost';
if ($hasV) {
    $cols .= ', variant_id';
}
$it = $pdo->prepare(
    'SELECT ' . $cols . ' FROM purchase_return_items WHERE purchase_return_id = ? ORDER BY id ASC'
);
$it->execute([$returnId]);
$items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];

json_response([
    'success' => true,
    'purchase_return' => $header,
    'items' => $items,
]);
