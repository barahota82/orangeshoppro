<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/purchase_return_helpers.php';
require_admin_api();

$purchaseId = (int) ($_GET['purchase_id'] ?? $_POST['purchase_id'] ?? 0);
if ($purchaseId <= 0) {
    $ref = trim((string) ($_GET['reference'] ?? $_POST['reference'] ?? ''));
    if (preg_match('/^PUR-(\d+)$/i', $ref, $m)) {
        $purchaseId = (int) $m[1];
    } elseif ($ref !== '' && ctype_digit($ref)) {
        $purchaseId = (int) $ref;
    }
}
if ($purchaseId <= 0) {
    json_response(['success' => false, 'message' => 'أدخل رقم فاتورة شراء صالحاً (PUR- أو رقم)'], 422);
}

$pdo = db();
orange_catalog_ensure_schema($pdo);

$hasSupplierInvoiceCol = orange_table_has_column($pdo, 'purchases', 'supplier_invoice_number');
$purCols = 'id, supplier_id, type, notes, total';
if ($hasSupplierInvoiceCol) {
    $purCols = 'id, supplier_id, supplier_invoice_number, type, notes, total';
}
$hasInvDiscount = orange_table_has_column($pdo, 'purchases', 'invoice_discount_raw');
$hasSubtotal = orange_table_has_column($pdo, 'purchases', 'subtotal');
$hasPiDiscount = orange_table_has_column($pdo, 'purchase_items', 'discount_raw');
if ($hasInvDiscount) {
    $purCols .= ', invoice_discount_raw, invoice_discount_amount';
}
if ($hasSubtotal) {
    $purCols .= ', subtotal';
}

$st = $pdo->prepare("SELECT $purCols FROM purchases WHERE id = ? LIMIT 1");
$st->execute([$purchaseId]);
$purchase = $st->fetch(PDO::FETCH_ASSOC);
if (!$purchase) {
    json_response(['success' => false, 'message' => 'فاتورة الشراء غير موجودة'], 404);
}

try {
    orange_admin_assert_entity_country($pdo, 'purchases', $purchaseId);
} catch (RuntimeException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 403);
}

$purRef = 'PUR-' . $purchaseId;
$accRow = orange_accounting_row_by_reference($pdo, $purRef);
if (orange_accounting_is_locked($pdo, $accRow)) {
    json_response([
        'success' => false,
        'message' => 'لا يمكن استرجاع بنود فاتورة شراء مرتبطة بسنة مالية مغلقة',
        'suggest_admin' => orange_gl_suggest_admin_fiscal_years_screen(),
    ], 422);
}

$hasV = orange_table_has_column($pdo, 'purchase_items', 'variant_id');
$base = 'SELECT pi.product_id, pi.qty, pi.cost';
if ($hasPiDiscount) {
    $base .= ', pi.discount_raw, pi.discount_amount';
}
if ($hasV) {
    $base .= ', COALESCE(pi.variant_id, 0) AS variant_id
        FROM purchase_items pi
        WHERE pi.purchase_id = ?';
} else {
    $base .= ', 0 AS variant_id FROM purchase_items pi WHERE pi.purchase_id = ?';
}

$stmt = $pdo->prepare($base . ' ORDER BY pi.id ASC');
$stmt->execute([$purchaseId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$purchasedMap = orange_purchase_purchased_qty_map($pdo, $purchaseId);
$returnedMap = orange_purchase_return_returned_qty_map($pdo, $purchaseId);

$items = [];
foreach ($rows as $row) {
    $productId = (int) ($row['product_id'] ?? 0);
    $variantId = (int) ($row['variant_id'] ?? 0);
    $key = orange_purchase_return_line_key($productId, $variantId);
    $purchasedQty = (int) ($purchasedMap[$key] ?? (int) ($row['qty'] ?? 0));
    $returnedQty = (int) ($returnedMap[$key] ?? 0);
    $availableQty = max(0, $purchasedQty - $returnedQty);
    if ($availableQty <= 0) {
        continue;
    }

    $items[] = [
        'product_id' => $productId,
        'variant_id' => $variantId,
        'qty' => $availableQty,
        'qty_purchased' => $purchasedQty,
        'qty_already_returned' => $returnedQty,
        'qty_available' => $availableQty,
        'cost' => (float) ($row['cost'] ?? 0),
        'discount_raw' => $hasPiDiscount ? trim((string) ($row['discount_raw'] ?? '')) : '',
        'discount_amount' => $hasPiDiscount ? (float) ($row['discount_amount'] ?? 0) : 0.0,
    ];
}

if ($items === []) {
    json_response([
        'success' => false,
        'message' => 'لا توجد كميات متبقية للإرجاع من هذه الفاتورة (ربما أُرجِعت بالكامل سابقاً)',
    ], 422);
}

json_response([
    'success' => true,
    'purchase' => [
        'id' => (int) ($purchase['id'] ?? 0),
        'reference' => $purRef,
        'supplier_id' => $purchase['supplier_id'] !== null ? (int) $purchase['supplier_id'] : 0,
        'type' => (string) ($purchase['type'] ?? 'cash'),
        'notes' => (string) ($purchase['notes'] ?? ''),
        'supplier_invoice_number' => $hasSupplierInvoiceCol
            ? trim((string) ($purchase['supplier_invoice_number'] ?? ''))
            : '',
        'subtotal' => $hasSubtotal ? (float) ($purchase['subtotal'] ?? 0) : (float) ($purchase['total'] ?? 0),
        'invoice_discount_raw' => $hasInvDiscount ? trim((string) ($purchase['invoice_discount_raw'] ?? '')) : '',
        'invoice_discount_amount' => $hasInvDiscount ? (float) ($purchase['invoice_discount_amount'] ?? 0) : 0.0,
    ],
    'items' => $items,
]);
