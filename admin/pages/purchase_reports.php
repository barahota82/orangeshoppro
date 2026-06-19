<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/accounting_report_money.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = orange_admin_page_pdo();
$prCountryId = function_exists('orange_admin_context_country_id') ? (int) orange_admin_context_country_id($pdo) : 0;
$companyNameAr = orange_company_settings_name_ar($pdo);

$reports = [
    'invoices' => 'فواتير المشتريات',
    'returns' => 'مردودات المشتريات',
    'suppliers' => 'ملخص الموردين',
    'monthly' => 'ملخص شهري',
    'items' => 'تحليل الأصناف',
];
$reportKey = isset($_GET['r']) ? (string) $_GET['r'] : 'invoices';
if (!isset($reports[$reportKey])) {
    $reportKey = 'invoices';
}

$normalizeDate = static function (string $raw): ?string {
    $ymd = orange_parse_admin_date_to_ymd(trim($raw));
    return $ymd !== '' ? $ymd : null;
};
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$fromDate = isset($_GET['from']) ? ($normalizeDate((string) $_GET['from']) ?? $monthStart) : $monthStart;
$toDate = isset($_GET['to']) ? ($normalizeDate((string) $_GET['to']) ?? $today) : $today;
if (strcmp($fromDate, $toDate) > 0) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}
$fromDisplay = orange_format_date_dmY($fromDate);
$toDisplay = orange_format_date_dmY($toDate);

$supplierId = isset($_GET['supplier_id']) ? max(0, (int) $_GET['supplier_id']) : 0;
$productId = isset($_GET['product_id']) ? max(0, (int) $_GET['product_id']) : 0;
$hideZero = isset($_GET['hz']) && (string) $_GET['hz'] === '1';
$supplierDetailed = isset($_GET['supplier_detail']) && (string) $_GET['supplier_detail'] === '1';
$invoiceTypeFilter = isset($_GET['invoice_type']) ? strtolower(trim((string) $_GET['invoice_type'])) : 'all';
if (!in_array($invoiceTypeFilter, ['all', 'cash', 'credit'], true)) {
    $invoiceTypeFilter = 'all';
}
$paymentStatusFilter = isset($_GET['payment_status']) ? strtolower(trim((string) $_GET['payment_status'])) : 'all';
if (!in_array($paymentStatusFilter, ['all', 'paid', 'unpaid'], true)) {
    $paymentStatusFilter = 'all';
}

$hasItemCode = orange_table_has_column($pdo, 'products', 'item_code');
$itemCodeExpr = $hasItemCode
    ? "COALESCE(NULLIF(TRIM(p.item_code), ''), CONCAT('P', p.id))"
    : "CONCAT('P', p.id)";

$suppliers = [];
$supplierMap = [];
$supplierCodeMap = [];
if (orange_table_exists($pdo, 'suppliers')) {
    $hasSupplierCode = orange_table_has_column($pdo, 'suppliers', 'code');
    $supplierCodeExpr = $hasSupplierCode
        ? "COALESCE(NULLIF(TRIM(s.code), ''), CONCAT('SUP-', s.id))"
        : "CONCAT('SUP-', s.id)";
    $supplierSql = 'SELECT s.id, ' . $supplierCodeExpr . ' AS supplier_code, COALESCE(s.name, \'\') AS supplier_name FROM suppliers s WHERE 1=1'
        . orange_sql_country_and_fragment($pdo, 'suppliers', 's', $prCountryId)
        . ' ORDER BY s.name ASC, s.id ASC';
    $suppliers = $pdo->query($supplierSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($suppliers as $sRow) {
        $sid = (int) ($sRow['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $supplierMap[$sid] = (string) ($sRow['supplier_name'] ?? '');
        $supplierCodeMap[$sid] = (string) ($sRow['supplier_code'] ?? ('SUP-' . $sid));
    }
}
if ($supplierId > 0 && !isset($supplierMap[$supplierId])) {
    $supplierId = 0;
}

$products = [];
$productMap = [];
if (orange_table_exists($pdo, 'products')) {
    $productSql = 'SELECT p.id, ' . $itemCodeExpr . ' AS item_code, COALESCE(p.name, \'\') AS product_name
        FROM products p
        WHERE 1=1' . orange_sql_country_and_fragment($pdo, 'products', 'p', $prCountryId) . '
        ORDER BY p.name ASC, p.id ASC';
    $products = $pdo->query($productSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($products as $pRow) {
        $pid = (int) ($pRow['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $productMap[$pid] = [
            'name' => (string) ($pRow['product_name'] ?? ''),
            'code' => (string) ($pRow['item_code'] ?? ''),
        ];
    }
}
if ($productId > 0 && !isset($productMap[$productId])) {
    $productId = 0;
}

$supplierPickerRows = [];
foreach ($suppliers as $sRow) {
    $sid = (int) ($sRow['id'] ?? 0);
    if ($sid <= 0) {
        continue;
    }
    $sName = trim((string) ($sRow['supplier_name'] ?? ''));
    $supplierPickerRows[] = [
        'id' => $sid,
        'code' => (string) ($supplierCodeMap[$sid] ?? ('SUP-' . $sid)),
        'name' => $sName,
    ];
}

$productPickerRows = [];
foreach ($products as $pRow) {
    $pid = (int) ($pRow['id'] ?? 0);
    if ($pid <= 0) {
        continue;
    }
    $pCode = trim((string) ($pRow['item_code'] ?? ''));
    if ($pCode === '') {
        $pCode = 'P' . $pid;
    }
    $pName = trim((string) ($pRow['product_name'] ?? ''));
    $productPickerRows[] = [
        'id' => $pid,
        'code' => $pCode,
        'name' => $pName,
    ];
}

$selectedSupplierCode = $supplierId > 0 ? (string) ($supplierCodeMap[$supplierId] ?? ('SUP-' . $supplierId)) : '';
$selectedSupplierName = $supplierId > 0 ? (string) ($supplierMap[$supplierId] ?? '') : '';
$selectedProductCode = $productId > 0 ? (string) ($productMap[$productId]['code'] ?? ('P' . $productId)) : '';
$selectedProductName = $productId > 0 ? (string) ($productMap[$productId]['name'] ?? '') : '';

$hasPurchases = orange_table_exists($pdo, 'purchases');
$hasPurchaseItems = orange_table_exists($pdo, 'purchase_items');
$hasPurchaseReturns = orange_table_exists($pdo, 'purchase_returns');
$hasPurchaseReturnItems = orange_table_exists($pdo, 'purchase_return_items');

$hasPurCreatedAt = $hasPurchases && orange_table_has_column($pdo, 'purchases', 'created_at');
$hasPurDocumentDate = $hasPurchases && orange_table_has_column($pdo, 'purchases', 'document_date');
$hasPurSupplierInvoice = $hasPurchases && orange_table_has_column($pdo, 'purchases', 'supplier_invoice_number');
$hasPurSubtotal = $hasPurchases && orange_table_has_column($pdo, 'purchases', 'subtotal');
$hasPurDiscount = $hasPurchases && orange_table_has_column($pdo, 'purchases', 'invoice_discount_amount');

$hasRetCreatedAt = $hasPurchaseReturns && orange_table_has_column($pdo, 'purchase_returns', 'created_at');
$hasRetDocumentDate = $hasPurchaseReturns && orange_table_has_column($pdo, 'purchase_returns', 'document_date');
$hasRetReturnNumber = $hasPurchaseReturns && orange_table_has_column($pdo, 'purchase_returns', 'return_number');
$hasRetPurchaseId = $hasPurchaseReturns && orange_table_has_column($pdo, 'purchase_returns', 'purchase_id');
$hasRetSubtotal = $hasPurchaseReturns && orange_table_has_column($pdo, 'purchase_returns', 'subtotal');
$hasRetDiscount = $hasPurchaseReturns && orange_table_has_column($pdo, 'purchase_returns', 'invoice_discount_amount');

$hasPiVariant = $hasPurchaseItems && orange_table_has_column($pdo, 'purchase_items', 'variant_id');
$hasPiDiscount = $hasPurchaseItems && orange_table_has_column($pdo, 'purchase_items', 'discount_amount');
$hasPriVariant = $hasPurchaseReturnItems && orange_table_has_column($pdo, 'purchase_return_items', 'variant_id');
$hasPriDiscount = $hasPurchaseReturnItems && orange_table_has_column($pdo, 'purchase_return_items', 'discount_amount');
$hasPartySubledger = orange_table_exists($pdo, 'party_subledger');
$hasPartyAllocations = orange_table_exists($pdo, 'party_subledger_allocations');

$purDateExpr = static function (string $alias) use ($hasPurDocumentDate, $hasPurCreatedAt): string {
    if ($hasPurDocumentDate) {
        return 'DATE(' . $alias . '.document_date)';
    }
    if ($hasPurCreatedAt) {
        return 'DATE(' . $alias . '.created_at)';
    }
    return 'CURDATE()';
};
$retDateExpr = static function (string $alias) use ($hasRetDocumentDate, $hasRetCreatedAt): string {
    if ($hasRetDocumentDate) {
        return 'DATE(' . $alias . '.document_date)';
    }
    if ($hasRetCreatedAt) {
        return 'DATE(' . $alias . '.created_at)';
    }
    return 'CURDATE()';
};
$purchasePaymentJoinSql = static function (string $docAlias) use ($hasPartySubledger, $hasPartyAllocations): string {
    if (!$hasPartySubledger) {
        return '';
    }
    $sql = ' LEFT JOIN (
                    SELECT party_id, ref_id, COALESCE(SUM(credit - debit), 0) AS gross_amount
                    FROM party_subledger
                    WHERE party_kind = \'supplier\' AND ref_type = \'purchase\'
                    GROUP BY party_id, ref_id
                ) psl ON psl.party_id = ' . $docAlias . '.supplier_id AND psl.ref_id = ' . $docAlias . '.id';
    if ($hasPartyAllocations) {
        $sql .= ' LEFT JOIN (
                        SELECT party_id, target_ref_id, COALESCE(SUM(amount), 0) AS allocated_amount
                        FROM party_subledger_allocations
                        WHERE party_kind = \'supplier\' AND target_ref_type = \'purchase\'
                        GROUP BY party_id, target_ref_id
                    ) pal ON pal.party_id = ' . $docAlias . '.supplier_id AND pal.target_ref_id = ' . $docAlias . '.id';
    }
    return $sql;
};
$purchaseOpenAmountExpr = static function (string $docAlias) use ($hasPartySubledger, $hasPartyAllocations): string {
    if (!$hasPartySubledger) {
        return 'CASE WHEN COALESCE(' . $docAlias . '.type, \'\') = \'cash\' THEN 0 ELSE COALESCE(' . $docAlias . '.total, 0) END';
    }
    $allocatedExpr = $hasPartyAllocations ? 'COALESCE(pal.allocated_amount, 0)' : '0';
    return 'CASE
                WHEN COALESCE(' . $docAlias . '.type, \'\') = \'cash\' THEN 0
                WHEN COALESCE(psl.gross_amount, 0) <= 0 THEN COALESCE(' . $docAlias . '.total, 0)
                ELSE GREATEST(COALESCE(psl.gross_amount, 0) - ' . $allocatedExpr . ', 0)
            END';
};
$appendPurchaseTypeFilter = static function (string &$sql, array &$params, string $docAlias, string $invoiceTypeFilter): void {
    if ($invoiceTypeFilter === 'all') {
        return;
    }
    $sql .= ' AND COALESCE(' . $docAlias . '.type, \'\') = ?';
    $params[] = $invoiceTypeFilter;
};
$appendPurchasePaymentFilter = static function (
    string &$sql,
    array &$params,
    string $docAlias,
    string $paymentStatusFilter,
    callable $purchaseOpenAmountExpr
): void {
    if ($paymentStatusFilter === 'all') {
        return;
    }
    $openExpr = $purchaseOpenAmountExpr($docAlias);
    if ($paymentStatusFilter === 'paid') {
        $sql .= ' AND (' . $openExpr . ') <= 0.0001';
        return;
    }
    $sql .= ' AND (' . $openExpr . ') > 0.0001';
};
$appendReturnTypeFilter = static function (string &$sql, array &$params, string $docAlias, string $invoiceTypeFilter): void {
    if ($invoiceTypeFilter === 'all') {
        return;
    }
    $sql .= ' AND COALESCE(' . $docAlias . '.type, \'\') = ?';
    $params[] = $invoiceTypeFilter;
};
$appendReturnPaymentFilter = static function (string &$sql, string $docAlias, string $paymentStatusFilter): void {
    if ($paymentStatusFilter === 'all') {
        return;
    }
    if ($paymentStatusFilter === 'paid') {
        $sql .= ' AND COALESCE(' . $docAlias . '.type, \'\') = \'cash\'';
        return;
    }
    $sql .= ' AND COALESCE(' . $docAlias . '.type, \'\') = \'credit\'';
};

$reportMoney = orange_accounting_report_money($pdo, isset($orangeAdminMoney) ? $orangeAdminMoney : null);

$fmtMoney = static function (float $value) use ($reportMoney): string {
    return orange_accounting_report_format_amount($value, $reportMoney);
};
$fmtQty = static function (float $value): string {
    return number_format($value, 0, '.', ',');
};
$purchaseTypeLabel = static function (string $raw): string {
    $v = strtolower(trim($raw));
    if ($v === 'cash') {
        return 'نقدي';
    }
    if ($v === 'credit') {
        return 'آجل';
    }
    if ($v === 'online') {
        return 'أونلاين';
    }
    return $raw !== '' ? $raw : '—';
};
$paymentStatusLabel = static function (string $raw): string {
    $v = strtolower(trim($raw));
    if ($v === 'paid') {
        return 'مسدد';
    }
    if ($v === 'unpaid') {
        return 'غير مسدد';
    }
    return 'الكل';
};

$rows = [];
$invoiceSummary = ['count' => 0, 'subtotal' => 0.0, 'discount' => 0.0, 'net' => 0.0];
$returnSummary = ['count' => 0, 'subtotal' => 0.0, 'discount' => 0.0, 'net' => 0.0];
$supplierSummary = ['purchase_count' => 0, 'purchase_net' => 0.0, 'return_count' => 0, 'return_net' => 0.0, 'net' => 0.0];
$supplierDetailTotals = ['subtotal' => 0.0, 'discount' => 0.0, 'net' => 0.0];
$itemSummary = ['purchase_qty' => 0.0, 'purchase_value' => 0.0, 'return_qty' => 0.0, 'return_value' => 0.0, 'net_qty' => 0.0, 'net_value' => 0.0];
$monthlySummary = ['purchase_count' => 0, 'purchase_total' => 0.0, 'return_count' => 0, 'return_total' => 0.0, 'net_total' => 0.0];
$reportError = '';

try {
    if ($reportKey === 'invoices') {
        if (!$hasPurchases) {
            throw new RuntimeException('جدول فواتير المشتريات غير متاح.');
        }
        $dateSql = $purDateExpr('p');
        $subtotalExpr = $hasPurSubtotal ? 'COALESCE(p.subtotal, p.total, 0)' : 'COALESCE(p.total, 0)';
        $discountExpr = $hasPurDiscount ? 'COALESCE(p.invoice_discount_amount, 0)' : '0';
        $supplierInvSelect = $hasPurSupplierInvoice
            ? 'COALESCE(p.supplier_invoice_number, \'\') AS supplier_invoice_number, '
            : '\'\' AS supplier_invoice_number, ';
        $sql = 'SELECT p.id, ' . $dateSql . ' AS doc_date, ' . $supplierInvSelect . '
                       COALESCE(s.name, \'\') AS supplier_name,
                       COALESCE(p.type, \'\') AS purchase_type,
                       ' . $subtotalExpr . ' AS subtotal_amount,
                       ' . $discountExpr . ' AS discount_amount,
                       COALESCE(p.total, 0) AS net_amount
                FROM purchases p
                LEFT JOIN suppliers s ON s.id = p.supplier_id'
                . $purchasePaymentJoinSql('p') . '
                WHERE 1=1' . orange_sql_country_and_fragment($pdo, 'purchases', 'p', $prCountryId) . '
                  AND ' . $dateSql . ' BETWEEN ? AND ?';
        $params = [$fromDate, $toDate];
        if ($supplierId > 0) {
            $sql .= ' AND p.supplier_id = ?';
            $params[] = $supplierId;
        }
        $appendPurchaseTypeFilter($sql, $params, 'p', $invoiceTypeFilter);
        $appendPurchasePaymentFilter($sql, $params, 'p', $paymentStatusFilter, $purchaseOpenAmountExpr);
        $sql .= ' ORDER BY ' . $dateSql . ' DESC, p.id DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sub = (float) ($r['subtotal_amount'] ?? 0);
            $disc = (float) ($r['discount_amount'] ?? 0);
            $net = (float) ($r['net_amount'] ?? 0);
            $rows[] = [
                'purchase_id' => (int) ($r['id'] ?? 0),
                'reference' => 'PUR-' . (int) ($r['id'] ?? 0),
                'date' => (string) ($r['doc_date'] ?? ''),
                'supplier' => (string) ($r['supplier_name'] ?? ''),
                'supplier_invoice' => (string) ($r['supplier_invoice_number'] ?? ''),
                'type' => (string) ($r['purchase_type'] ?? ''),
                'subtotal' => $sub,
                'discount' => $disc,
                'net' => $net,
                'doc_url' => storefront_public_path('/admin/index.php?page=purchases&purchase_id=' . (int) ($r['id'] ?? 0)),
            ];
            $invoiceSummary['count']++;
            $invoiceSummary['subtotal'] += $sub;
            $invoiceSummary['discount'] += $disc;
            $invoiceSummary['net'] += $net;
        }
    } elseif ($reportKey === 'returns') {
        if (!$hasPurchaseReturns) {
            throw new RuntimeException('جدول مردودات المشتريات غير متاح.');
        }
        $dateSql = $retDateExpr('pr');
        $subtotalExpr = $hasRetSubtotal ? 'COALESCE(pr.subtotal, pr.total, 0)' : 'COALESCE(pr.total, 0)';
        $discountExpr = $hasRetDiscount ? 'COALESCE(pr.invoice_discount_amount, 0)' : '0';
        $returnRefExpr = $hasRetReturnNumber
            ? "COALESCE(NULLIF(TRIM(pr.return_number), ''), CONCAT('PR-', pr.id))"
            : "CONCAT('PR-', pr.id)";
        $purchaseRefExpr = $hasRetPurchaseId
            ? "CASE WHEN pr.purchase_id IS NOT NULL AND pr.purchase_id > 0 THEN CONCAT('PUR-', pr.purchase_id) ELSE '' END"
            : "''";
        $sql = 'SELECT COALESCE(pr.id, 0) AS return_id,
                       ' . ($hasRetPurchaseId ? 'COALESCE(pr.purchase_id, 0)' : '0') . ' AS purchase_id,
                       ' . $returnRefExpr . ' AS return_reference, ' . $purchaseRefExpr . ' AS purchase_reference,
                       ' . $dateSql . ' AS doc_date,
                       COALESCE(s.name, \'\') AS supplier_name,
                       COALESCE(pr.type, \'\') AS return_type,
                       ' . $subtotalExpr . ' AS subtotal_amount,
                       ' . $discountExpr . ' AS discount_amount,
                       COALESCE(pr.total, 0) AS net_amount
                FROM purchase_returns pr
                LEFT JOIN suppliers s ON s.id = pr.supplier_id
                WHERE 1=1' . orange_sql_country_and_fragment($pdo, 'purchase_returns', 'pr', $prCountryId) . '
                  AND ' . $dateSql . ' BETWEEN ? AND ?';
        $params = [$fromDate, $toDate];
        if ($supplierId > 0) {
            $sql .= ' AND pr.supplier_id = ?';
            $params[] = $supplierId;
        }
        $appendReturnTypeFilter($sql, $params, 'pr', $invoiceTypeFilter);
        $appendReturnPaymentFilter($sql, 'pr', $paymentStatusFilter);
        $sql .= ' ORDER BY ' . $dateSql . ' DESC, pr.id DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sub = (float) ($r['subtotal_amount'] ?? 0);
            $disc = (float) ($r['discount_amount'] ?? 0);
            $net = (float) ($r['net_amount'] ?? 0);
            $rows[] = [
                'return_id' => (int) ($r['return_id'] ?? 0),
                'reference' => (string) ($r['return_reference'] ?? ''),
                'purchase_reference' => (string) ($r['purchase_reference'] ?? ''),
                'date' => (string) ($r['doc_date'] ?? ''),
                'supplier' => (string) ($r['supplier_name'] ?? ''),
                'type' => (string) ($r['return_type'] ?? ''),
                'subtotal' => $sub,
                'discount' => $disc,
                'net' => $net,
                'doc_url' => (int) ($r['return_id'] ?? 0) > 0
                    ? storefront_public_path('/admin/index.php?page=purchase_returns&purchase_return_id=' . (int) ($r['return_id'] ?? 0))
                    : '',
                'purchase_doc_url' => (int) ($r['purchase_id'] ?? 0) > 0
                    ? storefront_public_path('/admin/index.php?page=purchases&purchase_id=' . (int) ($r['purchase_id'] ?? 0))
                    : '',
            ];
            $returnSummary['count']++;
            $returnSummary['subtotal'] += $sub;
            $returnSummary['discount'] += $disc;
            $returnSummary['net'] += $net;
        }
    } elseif ($reportKey === 'suppliers') {
        if ($supplierDetailed) {
            if ($hasPurchases) {
                $dateSql = $purDateExpr('p');
                $subtotalExpr = $hasPurSubtotal ? 'COALESCE(p.subtotal, p.total, 0)' : 'COALESCE(p.total, 0)';
                $discountExpr = $hasPurDiscount ? 'COALESCE(p.invoice_discount_amount, 0)' : '0';
                $supplierInvSelect = $hasPurSupplierInvoice
                    ? 'COALESCE(p.supplier_invoice_number, \'\') AS supplier_invoice_number, '
                    : '\'\' AS supplier_invoice_number, ';
                $sql = 'SELECT p.id, COALESCE(p.supplier_id, 0) AS sid,
                               ' . $dateSql . ' AS doc_date,
                               ' . $supplierInvSelect . '
                               COALESCE(s.name, \'\') AS supplier_name,
                               ' . $subtotalExpr . ' AS subtotal_amount,
                               ' . $discountExpr . ' AS discount_amount,
                               COALESCE(p.total, 0) AS net_amount
                        FROM purchases p
                        LEFT JOIN suppliers s ON s.id = p.supplier_id'
                        . $purchasePaymentJoinSql('p') . '
                        WHERE 1=1' . orange_sql_country_and_fragment($pdo, 'purchases', 'p', $prCountryId) . '
                          AND ' . $dateSql . ' BETWEEN ? AND ?';
                $params = [$fromDate, $toDate];
                if ($supplierId > 0) {
                    $sql .= ' AND p.supplier_id = ?';
                    $params[] = $supplierId;
                }
                $appendPurchaseTypeFilter($sql, $params, 'p', $invoiceTypeFilter);
                $appendPurchasePaymentFilter($sql, $params, 'p', $paymentStatusFilter, $purchaseOpenAmountExpr);
                $sql .= ' ORDER BY COALESCE(s.name, \'\') ASC, sid ASC, ' . $dateSql . ' ASC, p.id ASC';
                $st = $pdo->prepare($sql);
                $st->execute($params);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sid = (int) ($r['sid'] ?? 0);
                    $supplierName = trim((string) ($r['supplier_name'] ?? ''));
                    if ($supplierName === '') {
                        $supplierName = $sid > 0 ? ('#' . $sid) : 'غير محدد';
                    }
                    $subtotal = (float) ($r['subtotal_amount'] ?? 0);
                    $discount = (float) ($r['discount_amount'] ?? 0);
                    $net = (float) ($r['net_amount'] ?? 0);
                    $invoiceNumber = 'PUR-' . (int) ($r['id'] ?? 0);
                    $reference = trim((string) ($r['supplier_invoice_number'] ?? ''));
                    if ($reference === '') {
                        $reference = '—';
                    }
                    $rows[] = [
                        'supplier' => $supplierName,
                        'doc_type' => 'فاتورة شراء',
                        'invoice_number' => $invoiceNumber,
                        'reference' => $reference,
                        'date' => (string) ($r['doc_date'] ?? ''),
                        'subtotal' => $subtotal,
                        'discount' => $discount,
                        'net' => $net,
                        'invoice_url' => storefront_public_path('/admin/index.php?page=purchases&purchase_id=' . (int) ($r['id'] ?? 0)),
                        'reference_url' => '',
                    ];
                    $supplierSummary['purchase_count']++;
                    $supplierSummary['purchase_net'] += $net;
                    $supplierDetailTotals['subtotal'] += $subtotal;
                    $supplierDetailTotals['discount'] += $discount;
                    $supplierDetailTotals['net'] += $net;
                }
            }

            if ($hasPurchaseReturns) {
                $dateSql = $retDateExpr('pr');
                $returnRefExpr = $hasRetReturnNumber
                    ? "COALESCE(NULLIF(TRIM(pr.return_number), ''), CONCAT('PR-', pr.id))"
                    : "CONCAT('PR-', pr.id)";
                $purchaseRefExpr = $hasRetPurchaseId
                    ? "CASE WHEN pr.purchase_id IS NOT NULL AND pr.purchase_id > 0 THEN CONCAT('PUR-', pr.purchase_id) ELSE '' END"
                    : "''";
                $subtotalExpr = $hasRetSubtotal ? 'COALESCE(pr.subtotal, pr.total, 0)' : 'COALESCE(pr.total, 0)';
                $discountExpr = $hasRetDiscount ? 'COALESCE(pr.invoice_discount_amount, 0)' : '0';
                $sql = 'SELECT COALESCE(pr.supplier_id, 0) AS sid,
                               COALESCE(pr.id, 0) AS return_id,
                               ' . ($hasRetPurchaseId ? 'COALESCE(pr.purchase_id, 0)' : '0') . ' AS purchase_id,
                               ' . $dateSql . ' AS doc_date,
                               ' . $returnRefExpr . ' AS return_reference,
                               ' . $purchaseRefExpr . ' AS purchase_reference,
                               COALESCE(s.name, \'\') AS supplier_name,
                               ' . $subtotalExpr . ' AS subtotal_amount,
                               ' . $discountExpr . ' AS discount_amount,
                               COALESCE(pr.total, 0) AS net_amount
                        FROM purchase_returns pr
                        LEFT JOIN suppliers s ON s.id = pr.supplier_id
                        WHERE 1=1' . orange_sql_country_and_fragment($pdo, 'purchase_returns', 'pr', $prCountryId) . '
                          AND ' . $dateSql . ' BETWEEN ? AND ?';
                $params = [$fromDate, $toDate];
                if ($supplierId > 0) {
                    $sql .= ' AND pr.supplier_id = ?';
                    $params[] = $supplierId;
                }
                $appendReturnTypeFilter($sql, $params, 'pr', $invoiceTypeFilter);
                $appendReturnPaymentFilter($sql, 'pr', $paymentStatusFilter);
                $sql .= ' ORDER BY COALESCE(s.name, \'\') ASC, sid ASC, ' . $dateSql . ' ASC, pr.id ASC';
                $st = $pdo->prepare($sql);
                $st->execute($params);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sid = (int) ($r['sid'] ?? 0);
                    $supplierName = trim((string) ($r['supplier_name'] ?? ''));
                    if ($supplierName === '') {
                        $supplierName = $sid > 0 ? ('#' . $sid) : 'غير محدد';
                    }
                    $subtotal = -1.0 * (float) ($r['subtotal_amount'] ?? 0);
                    $discount = -1.0 * (float) ($r['discount_amount'] ?? 0);
                    $net = -1.0 * (float) ($r['net_amount'] ?? 0);
                    $invoiceNumber = trim((string) ($r['purchase_reference'] ?? ''));
                    if ($invoiceNumber === '') {
                        $invoiceNumber = '—';
                    }
                    $reference = trim((string) ($r['return_reference'] ?? ''));
                    if ($reference === '') {
                        $reference = '—';
                    }
                    $rows[] = [
                        'supplier' => $supplierName,
                        'doc_type' => 'مردود شراء',
                        'invoice_number' => $invoiceNumber,
                        'reference' => $reference,
                        'date' => (string) ($r['doc_date'] ?? ''),
                        'subtotal' => $subtotal,
                        'discount' => $discount,
                        'net' => $net,
                        'invoice_url' => (int) ($r['purchase_id'] ?? 0) > 0
                            ? storefront_public_path('/admin/index.php?page=purchases&purchase_id=' . (int) ($r['purchase_id'] ?? 0))
                            : '',
                        'reference_url' => (int) ($r['return_id'] ?? 0) > 0
                            ? storefront_public_path('/admin/index.php?page=purchase_returns&purchase_return_id=' . (int) ($r['return_id'] ?? 0))
                            : '',
                    ];
                    $supplierSummary['return_count']++;
                    $supplierSummary['return_net'] += abs($net);
                    $supplierDetailTotals['subtotal'] += $subtotal;
                    $supplierDetailTotals['discount'] += $discount;
                    $supplierDetailTotals['net'] += $net;
                }
            }

            $supplierSummary['net'] = (float) $supplierSummary['purchase_net'] - (float) $supplierSummary['return_net'];
            usort($rows, static function (array $a, array $b): int {
                $supplierCmp = strcmp((string) ($a['supplier'] ?? ''), (string) ($b['supplier'] ?? ''));
                if ($supplierCmp !== 0) {
                    return $supplierCmp;
                }
                $dateCmp = strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
                if ($dateCmp !== 0) {
                    return $dateCmp;
                }
                $docCmp = strcmp((string) ($a['doc_type'] ?? ''), (string) ($b['doc_type'] ?? ''));
                if ($docCmp !== 0) {
                    return $docCmp;
                }
                return strcmp((string) ($a['invoice_number'] ?? ''), (string) ($b['invoice_number'] ?? ''));
            });
        } else {
            $purchaseAgg = [];
            $returnAgg = [];

            if ($hasPurchases) {
                $purDateSql = $purDateExpr('p');
                $subtotalExpr = $hasPurSubtotal ? 'COALESCE(p.subtotal, p.total, 0)' : 'COALESCE(p.total, 0)';
                $discountExpr = $hasPurDiscount ? 'COALESCE(p.invoice_discount_amount, 0)' : '0';
                $sql = 'SELECT COALESCE(p.supplier_id, 0) AS sid,
                               COUNT(*) AS doc_count,
                               COALESCE(SUM(' . $subtotalExpr . '), 0) AS subtotal_sum,
                               COALESCE(SUM(' . $discountExpr . '), 0) AS discount_sum,
                               COALESCE(SUM(COALESCE(p.total, 0)), 0) AS net_sum
                        FROM purchases p'
                        . $purchasePaymentJoinSql('p') . '
                        WHERE 1=1' . orange_sql_country_and_fragment($pdo, 'purchases', 'p', $prCountryId) . '
                          AND ' . $purDateSql . ' BETWEEN ? AND ?';
                $params = [$fromDate, $toDate];
                if ($supplierId > 0) {
                    $sql .= ' AND p.supplier_id = ?';
                    $params[] = $supplierId;
                }
                $appendPurchaseTypeFilter($sql, $params, 'p', $invoiceTypeFilter);
                $appendPurchasePaymentFilter($sql, $params, 'p', $paymentStatusFilter, $purchaseOpenAmountExpr);
                $sql .= ' GROUP BY COALESCE(p.supplier_id, 0)';
                $st = $pdo->prepare($sql);
                $st->execute($params);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sid = (int) ($r['sid'] ?? 0);
                    $purchaseAgg[$sid] = [
                        'count' => (int) ($r['doc_count'] ?? 0),
                        'net' => (float) ($r['net_sum'] ?? 0),
                    ];
                }
            }

            if ($hasPurchaseReturns) {
                $retDateSql = $retDateExpr('pr');
                $sql = 'SELECT COALESCE(pr.supplier_id, 0) AS sid,
                               COUNT(*) AS doc_count,
                               COALESCE(SUM(COALESCE(pr.total, 0)), 0) AS net_sum
                        FROM purchase_returns pr
                        WHERE 1=1' . orange_sql_country_and_fragment($pdo, 'purchase_returns', 'pr', $prCountryId) . '
                          AND ' . $retDateSql . ' BETWEEN ? AND ?';
                $params = [$fromDate, $toDate];
                if ($supplierId > 0) {
                    $sql .= ' AND pr.supplier_id = ?';
                    $params[] = $supplierId;
                }
                $appendReturnTypeFilter($sql, $params, 'pr', $invoiceTypeFilter);
                $appendReturnPaymentFilter($sql, 'pr', $paymentStatusFilter);
                $sql .= ' GROUP BY COALESCE(pr.supplier_id, 0)';
                $st = $pdo->prepare($sql);
                $st->execute($params);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sid = (int) ($r['sid'] ?? 0);
                    $returnAgg[$sid] = [
                        'count' => (int) ($r['doc_count'] ?? 0),
                        'net' => (float) ($r['net_sum'] ?? 0),
                    ];
                }
            }

            $supplierIds = [];
            foreach (array_keys($purchaseAgg) as $sid) {
                $supplierIds[$sid] = true;
            }
            foreach (array_keys($returnAgg) as $sid) {
                $supplierIds[$sid] = true;
            }

            foreach (array_keys($supplierIds) as $sid) {
                if ($supplierId > 0 && $sid !== $supplierId) {
                    continue;
                }
                $pData = $purchaseAgg[$sid] ?? ['count' => 0, 'net' => 0.0];
                $rData = $returnAgg[$sid] ?? ['count' => 0, 'net' => 0.0];
                $supplierName = $sid > 0
                    ? (trim((string) ($supplierMap[$sid] ?? '')) !== '' ? (string) $supplierMap[$sid] : ('#' . $sid))
                    : 'غير محدد';
                $netAfterReturns = (float) $pData['net'] - (float) $rData['net'];
                $rows[] = [
                    'supplier' => $supplierName,
                    'purchase_count' => (int) $pData['count'],
                    'purchase_net' => (float) $pData['net'],
                    'return_count' => (int) $rData['count'],
                    'return_net' => (float) $rData['net'],
                    'net' => $netAfterReturns,
                ];
                $supplierSummary['purchase_count'] += (int) $pData['count'];
                $supplierSummary['purchase_net'] += (float) $pData['net'];
                $supplierSummary['return_count'] += (int) $rData['count'];
                $supplierSummary['return_net'] += (float) $rData['net'];
                $supplierSummary['net'] += $netAfterReturns;
            }

            usort($rows, static function (array $a, array $b): int {
                return strcmp((string) ($a['supplier'] ?? ''), (string) ($b['supplier'] ?? ''));
            });
        }
    } elseif ($reportKey === 'items') {
        $itemsMap = [];

        if ($hasPurchases && $hasPurchaseItems) {
            $purDateSql = $purDateExpr('pur');
            $variantExpr = $hasPiVariant ? 'COALESCE(pi.variant_id, 0)' : '0';
            $variantJoin = $hasPiVariant
                ? 'LEFT JOIN product_variants pv ON pv.id = pi.variant_id'
                : 'LEFT JOIN product_variants pv ON 1=0';
            $lineDiscountExpr = $hasPiDiscount ? 'COALESCE(pi.discount_amount, 0)' : '0';
            $sql = 'SELECT pi.product_id AS pid,
                           ' . $variantExpr . ' AS vid,
                           ' . $itemCodeExpr . ' AS item_code,
                           COALESCE(p.name, \'\') AS product_name,
                           COALESCE(pv.color, \'\') AS color,
                           COALESCE(pv.size, \'\') AS size,
                           COALESCE(SUM(COALESCE(pi.qty, 0)), 0) AS qty_sum,
                           COALESCE(SUM((COALESCE(pi.qty, 0) * COALESCE(pi.cost, 0)) - ' . $lineDiscountExpr . '), 0) AS value_sum
                    FROM purchase_items pi
                    INNER JOIN purchases pur ON pur.id = pi.purchase_id
                    INNER JOIN products p ON p.id = pi.product_id
                    ' . $variantJoin
                    . $purchasePaymentJoinSql('pur') . '
                    WHERE 1=1' . orange_sql_country_and_fragment($pdo, 'purchases', 'pur', $prCountryId) . '
                      AND ' . $purDateSql . ' BETWEEN ? AND ?';
            $params = [$fromDate, $toDate];
            if ($supplierId > 0) {
                $sql .= ' AND pur.supplier_id = ?';
                $params[] = $supplierId;
            }
            if ($productId > 0) {
                $sql .= ' AND pi.product_id = ?';
                $params[] = $productId;
            }
            $appendPurchaseTypeFilter($sql, $params, 'pur', $invoiceTypeFilter);
            $appendPurchasePaymentFilter($sql, $params, 'pur', $paymentStatusFilter, $purchaseOpenAmountExpr);
            $sql .= ' GROUP BY pi.product_id, vid, item_code, product_name, color, size
                      ORDER BY product_name ASC, pi.product_id ASC, vid ASC';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pid = (int) ($r['pid'] ?? 0);
                $vid = (int) ($r['vid'] ?? 0);
                $key = $pid . '|' . $vid;
                $itemsMap[$key] = [
                    'item_code' => (string) ($r['item_code'] ?? ''),
                    'product_name' => (string) ($r['product_name'] ?? ''),
                    'color' => (string) ($r['color'] ?? ''),
                    'size' => (string) ($r['size'] ?? ''),
                    'purchase_qty' => (float) ($r['qty_sum'] ?? 0),
                    'purchase_value' => (float) ($r['value_sum'] ?? 0),
                    'return_qty' => 0.0,
                    'return_value' => 0.0,
                ];
            }
        }

        if ($hasPurchaseReturns && $hasPurchaseReturnItems) {
            $retDateSql = $retDateExpr('pr');
            $variantExpr = $hasPriVariant ? 'COALESCE(pri.variant_id, 0)' : '0';
            $variantJoin = $hasPriVariant
                ? 'LEFT JOIN product_variants pv ON pv.id = pri.variant_id'
                : 'LEFT JOIN product_variants pv ON 1=0';
            $lineDiscountExpr = $hasPriDiscount ? 'COALESCE(pri.discount_amount, 0)' : '0';
            $sql = 'SELECT pri.product_id AS pid,
                           ' . $variantExpr . ' AS vid,
                           ' . $itemCodeExpr . ' AS item_code,
                           COALESCE(p.name, \'\') AS product_name,
                           COALESCE(pv.color, \'\') AS color,
                           COALESCE(pv.size, \'\') AS size,
                           COALESCE(SUM(COALESCE(pri.qty, 0)), 0) AS qty_sum,
                           COALESCE(SUM((COALESCE(pri.qty, 0) * COALESCE(pri.cost, 0)) - ' . $lineDiscountExpr . '), 0) AS value_sum
                    FROM purchase_return_items pri
                    INNER JOIN purchase_returns pr ON pr.id = pri.purchase_return_id
                    INNER JOIN products p ON p.id = pri.product_id
                    ' . $variantJoin . '
                    WHERE 1=1' . orange_sql_country_and_fragment($pdo, 'purchase_returns', 'pr', $prCountryId) . '
                      AND ' . $retDateSql . ' BETWEEN ? AND ?';
            $params = [$fromDate, $toDate];
            if ($supplierId > 0) {
                $sql .= ' AND pr.supplier_id = ?';
                $params[] = $supplierId;
            }
            if ($productId > 0) {
                $sql .= ' AND pri.product_id = ?';
                $params[] = $productId;
            }
            $appendReturnTypeFilter($sql, $params, 'pr', $invoiceTypeFilter);
            $appendReturnPaymentFilter($sql, 'pr', $paymentStatusFilter);
            $sql .= ' GROUP BY pri.product_id, vid, item_code, product_name, color, size
                      ORDER BY product_name ASC, pri.product_id ASC, vid ASC';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pid = (int) ($r['pid'] ?? 0);
                $vid = (int) ($r['vid'] ?? 0);
                $key = $pid . '|' . $vid;
                if (!isset($itemsMap[$key])) {
                    $itemsMap[$key] = [
                        'item_code' => (string) ($r['item_code'] ?? ''),
                        'product_name' => (string) ($r['product_name'] ?? ''),
                        'color' => (string) ($r['color'] ?? ''),
                        'size' => (string) ($r['size'] ?? ''),
                        'purchase_qty' => 0.0,
                        'purchase_value' => 0.0,
                        'return_qty' => 0.0,
                        'return_value' => 0.0,
                    ];
                }
                $itemsMap[$key]['return_qty'] = (float) ($r['qty_sum'] ?? 0);
                $itemsMap[$key]['return_value'] = (float) ($r['value_sum'] ?? 0);
            }
        }

        foreach ($itemsMap as $r) {
            $variantText = trim((string) $r['color'] . ' / ' . (string) $r['size']);
            if ($variantText === '' || $variantText === '/') {
                $variantText = '—';
            }
            $netQty = (float) $r['purchase_qty'] - (float) $r['return_qty'];
            $netValue = (float) $r['purchase_value'] - (float) $r['return_value'];
            if ($hideZero && abs($netQty) < 0.00001 && abs($netValue) < 0.00001) {
                continue;
            }
            $rows[] = [
                'item_code' => (string) $r['item_code'],
                'product_name' => (string) $r['product_name'],
                'variant' => $variantText,
                'purchase_qty' => (float) $r['purchase_qty'],
                'purchase_value' => (float) $r['purchase_value'],
                'return_qty' => (float) $r['return_qty'],
                'return_value' => (float) $r['return_value'],
                'net_qty' => $netQty,
                'net_value' => $netValue,
            ];
            $itemSummary['purchase_qty'] += (float) $r['purchase_qty'];
            $itemSummary['purchase_value'] += (float) $r['purchase_value'];
            $itemSummary['return_qty'] += (float) $r['return_qty'];
            $itemSummary['return_value'] += (float) $r['return_value'];
            $itemSummary['net_qty'] += $netQty;
            $itemSummary['net_value'] += $netValue;
        }

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) ($a['product_name'] ?? ''), (string) ($b['product_name'] ?? ''))
                ?: strcmp((string) ($a['variant'] ?? ''), (string) ($b['variant'] ?? ''));
        });
    } elseif ($reportKey === 'monthly') {
        $monthMap = [];

        if ($hasPurchases) {
            $purDateSql = $purDateExpr('p');
            $sql = 'SELECT YEAR(' . $purDateSql . ') AS yy,
                           MONTH(' . $purDateSql . ') AS mm,
                           COUNT(*) AS doc_count,
                           COALESCE(SUM(COALESCE(p.total, 0)), 0) AS total_sum
                    FROM purchases p'
                    . $purchasePaymentJoinSql('p') . '
                    WHERE 1=1' . orange_sql_country_and_fragment($pdo, 'purchases', 'p', $prCountryId) . '
                      AND ' . $purDateSql . ' BETWEEN ? AND ?';
            $params = [$fromDate, $toDate];
            if ($supplierId > 0) {
                $sql .= ' AND p.supplier_id = ?';
                $params[] = $supplierId;
            }
            $appendPurchaseTypeFilter($sql, $params, 'p', $invoiceTypeFilter);
            $appendPurchasePaymentFilter($sql, $params, 'p', $paymentStatusFilter, $purchaseOpenAmountExpr);
            $sql .= ' GROUP BY YEAR(' . $purDateSql . '), MONTH(' . $purDateSql . ')';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $yy = (int) ($r['yy'] ?? 0);
                $mm = (int) ($r['mm'] ?? 0);
                $key = sprintf('%04d-%02d', $yy, $mm);
                if (!isset($monthMap[$key])) {
                    $monthMap[$key] = [
                        'yy' => $yy,
                        'mm' => $mm,
                        'purchase_count' => 0,
                        'purchase_total' => 0.0,
                        'return_count' => 0,
                        'return_total' => 0.0,
                    ];
                }
                $monthMap[$key]['purchase_count'] += (int) ($r['doc_count'] ?? 0);
                $monthMap[$key]['purchase_total'] += (float) ($r['total_sum'] ?? 0);
            }
        }

        if ($hasPurchaseReturns) {
            $retDateSql = $retDateExpr('pr');
            $sql = 'SELECT YEAR(' . $retDateSql . ') AS yy,
                           MONTH(' . $retDateSql . ') AS mm,
                           COUNT(*) AS doc_count,
                           COALESCE(SUM(COALESCE(pr.total, 0)), 0) AS total_sum
                    FROM purchase_returns pr
                    WHERE 1=1' . orange_sql_country_and_fragment($pdo, 'purchase_returns', 'pr', $prCountryId) . '
                      AND ' . $retDateSql . ' BETWEEN ? AND ?';
            $params = [$fromDate, $toDate];
            if ($supplierId > 0) {
                $sql .= ' AND pr.supplier_id = ?';
                $params[] = $supplierId;
            }
            $appendReturnTypeFilter($sql, $params, 'pr', $invoiceTypeFilter);
            $appendReturnPaymentFilter($sql, 'pr', $paymentStatusFilter);
            $sql .= ' GROUP BY YEAR(' . $retDateSql . '), MONTH(' . $retDateSql . ')';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $yy = (int) ($r['yy'] ?? 0);
                $mm = (int) ($r['mm'] ?? 0);
                $key = sprintf('%04d-%02d', $yy, $mm);
                if (!isset($monthMap[$key])) {
                    $monthMap[$key] = [
                        'yy' => $yy,
                        'mm' => $mm,
                        'purchase_count' => 0,
                        'purchase_total' => 0.0,
                        'return_count' => 0,
                        'return_total' => 0.0,
                    ];
                }
                $monthMap[$key]['return_count'] += (int) ($r['doc_count'] ?? 0);
                $monthMap[$key]['return_total'] += (float) ($r['total_sum'] ?? 0);
            }
        }

        foreach ($monthMap as $mRow) {
            $netTotal = (float) $mRow['purchase_total'] - (float) $mRow['return_total'];
            $rows[] = [
                'month_label' => sprintf('%02d/%04d', (int) $mRow['mm'], (int) $mRow['yy']),
                'purchase_count' => (int) $mRow['purchase_count'],
                'purchase_total' => (float) $mRow['purchase_total'],
                'return_count' => (int) $mRow['return_count'],
                'return_total' => (float) $mRow['return_total'],
                'net_total' => $netTotal,
                '_sort' => sprintf('%04d%02d', (int) $mRow['yy'], (int) $mRow['mm']),
            ];
            $monthlySummary['purchase_count'] += (int) $mRow['purchase_count'];
            $monthlySummary['purchase_total'] += (float) $mRow['purchase_total'];
            $monthlySummary['return_count'] += (int) $mRow['return_count'];
            $monthlySummary['return_total'] += (float) $mRow['return_total'];
            $monthlySummary['net_total'] += $netTotal;
        }

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) ($b['_sort'] ?? ''), (string) ($a['_sort'] ?? ''));
        });
    }
} catch (Throwable $e) {
    $reportError = $e->getMessage();
    if (function_exists('error_log')) {
        error_log('[orange purchase_reports ' . $reportKey . '] ' . $e->getMessage());
    }
}

$company = orange_sales_doc_print_company($pdo, $prCountryId);
$companyName = (string) ($company['company_name_ar'] ?? '');
$companyLogo = (string) ($company['logo_url'] ?? '');
$companyCr = (string) ($company['commercial_register'] ?? '');
$todayDmY = orange_format_date_dmY($today);
$printDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
$reportTitle = $reports[$reportKey];
$headerSupplierSummary = $supplierId > 0 && isset($supplierMap[$supplierId])
    ? (string) $supplierMap[$supplierId]
    : 'الكل';
$headerInvoiceTypeSummary = $invoiceTypeFilter === 'all'
    ? 'الكل'
    : $purchaseTypeLabel($invoiceTypeFilter);
$headerPaymentStatusSummary = $paymentStatusFilter === 'all'
    ? 'الكل'
    : $paymentStatusLabel($paymentStatusFilter);
$hideSupplierColumnInSuppliersReport = $reportKey === 'suppliers' && $supplierId > 0;

$subtitleParts = [
    'من ' . orange_format_date_dmY($fromDate) . ' إلى ' . orange_format_date_dmY($toDate),
];
if ($reportKey !== 'suppliers' && $supplierId > 0 && isset($supplierMap[$supplierId])) {
    $subtitleParts[] = 'المورد: ' . $supplierMap[$supplierId];
}
if ($productId > 0 && isset($productMap[$productId])) {
    $subtitleParts[] = 'الصنف: ' . (string) ($productMap[$productId]['name'] ?? '');
}
if ($reportKey !== 'suppliers' && $invoiceTypeFilter !== 'all') {
    $subtitleParts[] = 'نوع الفاتورة: ' . $purchaseTypeLabel($invoiceTypeFilter);
}
if ($reportKey !== 'suppliers' && $paymentStatusFilter !== 'all') {
    $subtitleParts[] = 'حالة السداد: ' . $paymentStatusLabel($paymentStatusFilter);
}
$filterSubtitle = implode(' — ', $subtitleParts);

?>
<div class="page-title gl-acc-stmt-no-print">
    <h1>تقارير المشتريات</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<div class="card gl-acc-stmt-no-print">
    <div class="prr-tabs">
        <?php foreach ($reports as $key => $label): ?>
            <a class="prr-tab<?php echo $key === $reportKey ? ' is-active' : ''; ?>"
                href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=purchase_reports&r=' . rawurlencode($key)), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="prr-filter-form" style="margin-top:12px;display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
        <input type="hidden" name="page" value="purchase_reports">
        <input type="hidden" name="r" value="<?php echo htmlspecialchars($reportKey, ENT_QUOTES, 'UTF-8'); ?>">

        <div class="prr-main-row">
            <div class="prr-date-field">
                <label for="prr_from">من تاريخ</label>
                <input type="text" id="prr_from" name="from" class="admin-inp orange-inp-dmy" lang="en" dir="ltr" value="<?php echo htmlspecialchars($fromDisplay, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="prr-date-field prr-date-field-to">
                <label for="prr_to">إلى تاريخ</label>
                <input type="text" id="prr_to" name="to" class="admin-inp orange-inp-dmy" lang="en" dir="ltr" value="<?php echo htmlspecialchars($toDisplay, ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <?php if (in_array($reportKey, ['invoices', 'returns', 'suppliers', 'items', 'monthly'], true)): ?>
                <div class="prr-supplier-field-wrap">
                    <label for="prr_supplier_name">المورد (دبل كليك للاختيار)</label>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <input type="hidden" id="prr_supplier_id" name="supplier_id" value="<?php echo (int) $supplierId; ?>">
                        <input type="text" id="prr_supplier_code" class="admin-inp" readonly
                            title="يُملأ تلقائياً عند اختيار المورد"
                            style="width:9rem;background:#f4f4f5;" dir="ltr" lang="en"
                            placeholder="الكود"
                            value="<?php echo htmlspecialchars($selectedSupplierCode, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="text" id="prr_supplier_name" class="admin-inp prr-name-input prr-supplier-name" readonly
                            title="دبل كليك لاختيار المورد"
                            style="cursor:pointer;min-width:15rem;"
                            placeholder="كل الموردين — دبل كليك للاختيار"
                            value="<?php echo htmlspecialchars($selectedSupplierName, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($reportKey === 'items'): ?>
                <div class="prr-product-field-wrap">
                    <label for="prr_product_name">الصنف (دبل كليك للاختيار)</label>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <input type="hidden" id="prr_product_id" name="product_id" value="<?php echo (int) $productId; ?>">
                        <input type="text" id="prr_product_code" class="admin-inp" readonly
                            title="يُملأ تلقائياً عند اختيار الصنف"
                            style="width:10rem;background:#f4f4f5;" dir="ltr" lang="en"
                            placeholder="الكود"
                            value="<?php echo htmlspecialchars($selectedProductCode, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="text" id="prr_product_name" class="admin-inp prr-name-input prr-product-name" readonly
                            title="دبل كليك لاختيار الصنف"
                            style="cursor:pointer;min-width:18rem;"
                            placeholder="كل الأصناف — دبل كليك للاختيار"
                            value="<?php echo htmlspecialchars($selectedProductName, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="prr-options-row">
            <div class="prr-type-wrap">
                <label for="prr_invoice_type">نوع الفاتورة</label>
                <select id="prr_invoice_type" name="invoice_type" class="admin-inp">
                    <option value="all"<?php echo $invoiceTypeFilter === 'all' ? ' selected' : ''; ?>>الكل</option>
                    <option value="cash"<?php echo $invoiceTypeFilter === 'cash' ? ' selected' : ''; ?>>نقدي</option>
                    <option value="credit"<?php echo $invoiceTypeFilter === 'credit' ? ' selected' : ''; ?>>آجل</option>
                </select>
            </div>
            <div class="prr-payment-status-wrap">
                <label for="prr_payment_status">حالة السداد</label>
                <select id="prr_payment_status" name="payment_status" class="admin-inp">
                    <option value="all"<?php echo $paymentStatusFilter === 'all' ? ' selected' : ''; ?>>الكل</option>
                    <option value="paid"<?php echo $paymentStatusFilter === 'paid' ? ' selected' : ''; ?>>مسدد</option>
                    <option value="unpaid"<?php echo $paymentStatusFilter === 'unpaid' ? ' selected' : ''; ?>>غير مسدد</option>
                </select>
            </div>
            <?php if ($reportKey === 'items'): ?>
                <div class="prr-items-zero-wrap">
                    <label for="prr_hide_zero">إخفاء الصافي صفر</label>
                    <div class="prr-checkbox-control">
                        <input type="checkbox" id="prr_hide_zero" name="hz" value="1" <?php echo $hideZero ? 'checked' : ''; ?>>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($reportKey === 'suppliers'): ?>
                <div class="prr-suppliers-detail-wrap">
                    <label for="prr_supplier_detail">تقرير تفصيلي</label>
                    <div class="prr-checkbox-control">
                        <input type="checkbox" id="prr_supplier_detail" name="supplier_detail" value="1" <?php echo $supplierDetailed ? 'checked' : ''; ?>>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="prr-print-actions">
            <button type="submit">عرض</button>
            <button type="button" class="btn-secondary" data-orange-perm="print" onclick="window.print()">طباعة</button>
        </div>
    </form>
</div>

<div class="card prr-print-area gl-acc-stmt-print">
    <div class="gl-acc-stmt-print-sheet ta-report-print-sheet bs-report-sheet">
        <header class="gl-acc-stmt-print-banner bs-report-banner">
            <div class="bs-report-brand">
                <?php if ($companyLogo !== ''): ?>
                    <img class="bs-report-logo" src="<?php echo htmlspecialchars($companyLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                <?php endif; ?>
                <div class="bs-report-brand-text">
                    <?php if ($companyName !== ''): ?>
                        <p class="bs-report-company"><?php echo htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                    <?php if ($companyCr !== ''): ?>
                        <p class="bs-report-cr">سجل تجاري: <span dir="ltr"><?php echo htmlspecialchars($companyCr, ENT_QUOTES, 'UTF-8'); ?></span></p>
                    <?php endif; ?>
                </div>
            </div>
            <h2 class="gl-acc-stmt-print-title bs-report-title">
                <span class="gl-acc-stmt-print-title-ar" lang="ar"><?php echo htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="bs-report-asof" lang="ar"><?php echo htmlspecialchars($filterSubtitle, ENT_QUOTES, 'UTF-8'); ?></span>
            </h2>
            <?php if ($reportKey === 'suppliers'): ?>
                <div class="prr-report-filter-row" lang="ar">
                    <div class="prr-report-filter-cell">
                        <span class="prr-report-filter-label">المورد</span>
                        <span class="prr-report-filter-value"><?php echo htmlspecialchars($headerSupplierSummary, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="prr-report-filter-cell">
                        <span class="prr-report-filter-label">نوع الفاتورة</span>
                        <span class="prr-report-filter-value"><?php echo htmlspecialchars($headerInvoiceTypeSummary, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="prr-report-filter-cell">
                        <span class="prr-report-filter-label">حالة السداد</span>
                        <span class="prr-report-filter-value"><?php echo htmlspecialchars($headerPaymentStatusSummary, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </header>

        <?php if ($reportError !== ''): ?>
            <p class="card-hint" style="color:#b91c1c;">تعذّر توليد التقرير: <?php echo htmlspecialchars($reportError, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap">
            <?php
            $prrTableClass = '';
            if ($reportKey === 'invoices' || $reportKey === 'returns') {
                $prrTableClass = ' prr-doc-table';
            } elseif ($reportKey === 'suppliers') {
                $prrTableClass = $supplierDetailed ? ' prr-suppliers-table prr-suppliers-detail-table' : ' prr-suppliers-table';
            } elseif ($reportKey === 'items') {
                $prrTableClass = ' prr-items-table';
            } elseif ($reportKey === 'monthly') {
                $prrTableClass = ' prr-monthly-table';
            }
            ?>
            <table class="admin-fy-table gl-acc-stmt-table ta-report-table<?php echo $prrTableClass; ?>"
                data-export-name="<?php echo htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8'); ?>"
                data-export-target=".prr-print-actions"
                data-export-company="<?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($reportKey === 'invoices' || $reportKey === 'returns'): ?>
                    <colgroup>
                        <col class="prr-col-ref">
                        <col class="prr-col-date">
                        <col class="prr-col-supplier">
                        <col class="prr-col-supplier-doc">
                        <col class="prr-col-type">
                        <col class="prr-col-money">
                        <col class="prr-col-money">
                        <col class="prr-col-money">
                    </colgroup>
                <?php elseif ($reportKey === 'suppliers'): ?>
                    <?php if ($supplierDetailed): ?>
                        <?php if ($hideSupplierColumnInSuppliersReport): ?>
                            <colgroup>
                                <col class="prr-supd-nosup-col-doc">
                                <col class="prr-supd-nosup-col-invoice">
                                <col class="prr-supd-nosup-col-ref">
                                <col class="prr-supd-nosup-col-date">
                                <col class="prr-supd-nosup-col-money">
                                <col class="prr-supd-nosup-col-money">
                                <col class="prr-supd-nosup-col-money">
                            </colgroup>
                        <?php else: ?>
                            <colgroup>
                                <col class="prr-supd-col-name">
                                <col class="prr-supd-col-doc">
                                <col class="prr-supd-col-invoice">
                                <col class="prr-supd-col-ref">
                                <col class="prr-supd-col-date">
                                <col class="prr-supd-col-money">
                                <col class="prr-supd-col-money">
                                <col class="prr-supd-col-money">
                            </colgroup>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($hideSupplierColumnInSuppliersReport): ?>
                            <colgroup>
                                <col class="prr-sup-nosup-col-count">
                                <col class="prr-sup-nosup-col-money">
                                <col class="prr-sup-nosup-col-count">
                                <col class="prr-sup-nosup-col-money">
                                <col class="prr-sup-nosup-col-money">
                            </colgroup>
                        <?php else: ?>
                            <colgroup>
                                <col class="prr-sup-col-name">
                                <col class="prr-sup-col-count">
                                <col class="prr-sup-col-money">
                                <col class="prr-sup-col-count">
                                <col class="prr-sup-col-money">
                                <col class="prr-sup-col-money">
                            </colgroup>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php elseif ($reportKey === 'items'): ?>
                    <colgroup>
                        <col class="prr-item-col-code">
                        <col class="prr-item-col-name">
                        <col class="prr-item-col-variant">
                        <col class="prr-item-col-qty">
                        <col class="prr-item-col-money">
                        <col class="prr-item-col-qty">
                        <col class="prr-item-col-money">
                        <col class="prr-item-col-qty">
                        <col class="prr-item-col-money">
                    </colgroup>
                <?php elseif ($reportKey === 'monthly'): ?>
                    <colgroup>
                        <col class="prr-mon-col-month">
                        <col class="prr-mon-col-count">
                        <col class="prr-mon-col-money">
                        <col class="prr-mon-col-count">
                        <col class="prr-mon-col-money">
                        <col class="prr-mon-col-money">
                    </colgroup>
                <?php endif; ?>
                <?php if ($reportKey === 'invoices'): ?>
                    <thead>
                    <tr>
                        <th>المرجع</th>
                        <th>التاريخ</th>
                        <th>المورد</th>
                        <th>رقم فاتورة المورد</th>
                        <th>النوع</th>
                        <th class="gl-acc-stmt-col-num">الإجمالي</th>
                        <th class="gl-acc-stmt-col-num">الخصم</th>
                        <th class="gl-acc-stmt-col-num">الصافي</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="8" class="muted">لا توجد فواتير مشتريات في المدى المحدد.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr>
                            <td dir="ltr">
                                <?php if ((string) ($r['doc_url'] ?? '') !== ''): ?>
                                    <a href="<?php echo htmlspecialchars((string) $r['doc_url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $r['reference'], ENT_QUOTES, 'UTF-8'); ?></a>
                                <?php else: ?>
                                    <?php echo htmlspecialchars((string) $r['reference'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </td>
                            <td dir="ltr"><?php echo htmlspecialchars(orange_format_date_dmY((string) $r['date']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['supplier'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars((string) $r['supplier_invoice'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($purchaseTypeLabel((string) $r['type']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['subtotal']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['discount']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['net']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                    <tr>
                        <th colspan="5">الإجمالي (<?php echo (int) $invoiceSummary['count']; ?>)</th>
                        <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $invoiceSummary['subtotal']); ?></th>
                        <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $invoiceSummary['discount']); ?></th>
                        <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $invoiceSummary['net']); ?></th>
                    </tr>
                    </tfoot>
                <?php elseif ($reportKey === 'returns'): ?>
                    <thead>
                    <tr>
                        <th>مرجع المردود</th>
                        <th>التاريخ</th>
                        <th>المورد</th>
                        <th>فاتورة الشراء</th>
                        <th>النوع</th>
                        <th class="gl-acc-stmt-col-num">الإجمالي</th>
                        <th class="gl-acc-stmt-col-num">الخصم</th>
                        <th class="gl-acc-stmt-col-num">الصافي</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="8" class="muted">لا توجد مردودات مشتريات في المدى المحدد.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr>
                            <td dir="ltr">
                                <?php if ((string) ($r['doc_url'] ?? '') !== ''): ?>
                                    <a href="<?php echo htmlspecialchars((string) $r['doc_url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $r['reference'], ENT_QUOTES, 'UTF-8'); ?></a>
                                <?php else: ?>
                                    <?php echo htmlspecialchars((string) $r['reference'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </td>
                            <td dir="ltr"><?php echo htmlspecialchars(orange_format_date_dmY((string) $r['date']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['supplier'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="ltr">
                                <?php if ((string) ($r['purchase_doc_url'] ?? '') !== '' && (string) ($r['purchase_reference'] ?? '') !== ''): ?>
                                    <a href="<?php echo htmlspecialchars((string) $r['purchase_doc_url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $r['purchase_reference'], ENT_QUOTES, 'UTF-8'); ?></a>
                                <?php else: ?>
                                    <?php echo htmlspecialchars((string) $r['purchase_reference'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($purchaseTypeLabel((string) $r['type']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['subtotal']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['discount']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['net']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                    <tr>
                        <th colspan="5">الإجمالي (<?php echo (int) $returnSummary['count']; ?>)</th>
                        <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $returnSummary['subtotal']); ?></th>
                        <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $returnSummary['discount']); ?></th>
                        <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $returnSummary['net']); ?></th>
                    </tr>
                    </tfoot>
                <?php elseif ($reportKey === 'suppliers'): ?>
                    <?php if ($supplierDetailed): ?>
                        <?php
                        $supplierDetailColspan = $hideSupplierColumnInSuppliersReport ? 7 : 8;
                        $supplierDetailFooterColspan = $hideSupplierColumnInSuppliersReport ? 4 : 5;
                        ?>
                        <thead>
                        <tr>
                            <?php if (!$hideSupplierColumnInSuppliersReport): ?>
                                <th>المورد</th>
                            <?php endif; ?>
                            <th>نوع السند</th>
                            <th>رقم الفاتورة</th>
                            <th>المرجع</th>
                            <th>التاريخ</th>
                            <th class="gl-acc-stmt-col-num">الإجمالي</th>
                            <th class="gl-acc-stmt-col-num">الخصم</th>
                            <th class="gl-acc-stmt-col-num">الصافي</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="<?php echo (int) $supplierDetailColspan; ?>" class="muted">لا توجد تفاصيل فواتير/مردود الموردين في المدى المحدد.</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <?php if (!$hideSupplierColumnInSuppliersReport): ?>
                                    <td><?php echo htmlspecialchars((string) $r['supplier'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php endif; ?>
                                <td><?php echo htmlspecialchars((string) $r['doc_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td dir="ltr">
                                    <?php if ((string) ($r['invoice_url'] ?? '') !== '' && (string) ($r['invoice_number'] ?? '') !== '—'): ?>
                                        <a href="<?php echo htmlspecialchars((string) $r['invoice_url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $r['invoice_number'], ENT_QUOTES, 'UTF-8'); ?></a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars((string) $r['invoice_number'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php endif; ?>
                                </td>
                                <td dir="ltr">
                                    <?php if ((string) ($r['reference_url'] ?? '') !== '' && (string) ($r['reference'] ?? '') !== '—'): ?>
                                        <a href="<?php echo htmlspecialchars((string) $r['reference_url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $r['reference'], ENT_QUOTES, 'UTF-8'); ?></a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars((string) $r['reference'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php endif; ?>
                                </td>
                                <td dir="ltr"><?php echo htmlspecialchars(orange_format_date_dmY((string) $r['date']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['subtotal']); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['discount']); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['net']); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                        <tfoot>
                        <tr>
                            <th colspan="<?php echo (int) $supplierDetailFooterColspan; ?>">الإجمالي — شراء: <?php echo (int) $supplierSummary['purchase_count']; ?> | مردود: <?php echo (int) $supplierSummary['return_count']; ?></th>
                            <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $supplierDetailTotals['subtotal']); ?></th>
                            <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $supplierDetailTotals['discount']); ?></th>
                            <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $supplierDetailTotals['net']); ?></th>
                        </tr>
                        </tfoot>
                    <?php else: ?>
                        <?php $supplierSummaryColspan = $hideSupplierColumnInSuppliersReport ? 5 : 6; ?>
                        <thead>
                        <tr>
                            <?php if (!$hideSupplierColumnInSuppliersReport): ?>
                                <th>المورد</th>
                            <?php endif; ?>
                            <th class="gl-acc-stmt-col-num">عدد فواتير الشراء</th>
                            <th class="gl-acc-stmt-col-num">صافي المشتريات</th>
                            <th class="gl-acc-stmt-col-num">عدد المردودات</th>
                            <th class="gl-acc-stmt-col-num">صافي المردودات</th>
                            <th class="gl-acc-stmt-col-num">الصافي بعد المردود</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="<?php echo (int) $supplierSummaryColspan; ?>" class="muted">لا توجد بيانات موردين في المدى المحدد.</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <?php if (!$hideSupplierColumnInSuppliersReport): ?>
                                    <td><?php echo htmlspecialchars((string) $r['supplier'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php endif; ?>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['purchase_count']; ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['purchase_net']); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['return_count']; ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['return_net']); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['net']); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                        <tfoot>
                        <?php if ($hideSupplierColumnInSuppliersReport): ?>
                            <tr>
                                <th colspan="5">الإجمالي</th>
                            </tr>
                            <tr>
                                <th class="gl-acc-stmt-col-num"><?php echo (int) $supplierSummary['purchase_count']; ?></th>
                                <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $supplierSummary['purchase_net']); ?></th>
                                <th class="gl-acc-stmt-col-num"><?php echo (int) $supplierSummary['return_count']; ?></th>
                                <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $supplierSummary['return_net']); ?></th>
                                <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $supplierSummary['net']); ?></th>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <th>الإجمالي</th>
                                <th class="gl-acc-stmt-col-num"><?php echo (int) $supplierSummary['purchase_count']; ?></th>
                                <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $supplierSummary['purchase_net']); ?></th>
                                <th class="gl-acc-stmt-col-num"><?php echo (int) $supplierSummary['return_count']; ?></th>
                                <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $supplierSummary['return_net']); ?></th>
                                <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $supplierSummary['net']); ?></th>
                            </tr>
                        <?php endif; ?>
                        </tfoot>
                    <?php endif; ?>
                <?php elseif ($reportKey === 'items'): ?>
                    <thead>
                    <tr>
                        <th>الكود</th>
                        <th>الصنف</th>
                        <th>اللون / المقاس</th>
                        <th class="gl-acc-stmt-col-num">كمية مشتريات</th>
                        <th class="gl-acc-stmt-col-num">قيمة مشتريات</th>
                        <th class="gl-acc-stmt-col-num">كمية مردود</th>
                        <th class="gl-acc-stmt-col-num">قيمة مردود</th>
                        <th class="gl-acc-stmt-col-num">صافي الكمية</th>
                        <th class="gl-acc-stmt-col-num">صافي القيمة</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="9" class="muted">لا توجد حركة أصناف مشتريات في المدى المحدد.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr>
                            <td dir="ltr"><?php echo htmlspecialchars((string) $r['item_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['variant'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtQty((float) $r['purchase_qty']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['purchase_value']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtQty((float) $r['return_qty']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['return_value']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtQty((float) $r['net_qty']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['net_value']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                    <tr>
                        <th colspan="3">الإجمالي</th>
                        <th class="gl-acc-stmt-col-num"><?php echo $fmtQty((float) $itemSummary['purchase_qty']); ?></th>
                        <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $itemSummary['purchase_value']); ?></th>
                        <th class="gl-acc-stmt-col-num"><?php echo $fmtQty((float) $itemSummary['return_qty']); ?></th>
                        <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $itemSummary['return_value']); ?></th>
                        <th class="gl-acc-stmt-col-num"><?php echo $fmtQty((float) $itemSummary['net_qty']); ?></th>
                        <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $itemSummary['net_value']); ?></th>
                    </tr>
                    </tfoot>
                <?php elseif ($reportKey === 'monthly'): ?>
                    <thead>
                    <tr>
                        <th>الشهر</th>
                        <th class="gl-acc-stmt-col-num">عدد فواتير الشراء</th>
                        <th class="gl-acc-stmt-col-num">صافي المشتريات</th>
                        <th class="gl-acc-stmt-col-num">عدد المردودات</th>
                        <th class="gl-acc-stmt-col-num">صافي المردودات</th>
                        <th class="gl-acc-stmt-col-num">الصافي</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="6" class="muted">لا توجد بيانات شهرية في المدى المحدد.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr>
                            <td dir="ltr"><?php echo htmlspecialchars((string) $r['month_label'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo (int) $r['purchase_count']; ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['purchase_total']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo (int) $r['return_count']; ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['return_total']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['net_total']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                    <tr>
                        <th>الإجمالي</th>
                        <th class="gl-acc-stmt-col-num"><?php echo (int) $monthlySummary['purchase_count']; ?></th>
                        <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $monthlySummary['purchase_total']); ?></th>
                        <th class="gl-acc-stmt-col-num"><?php echo (int) $monthlySummary['return_count']; ?></th>
                        <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $monthlySummary['return_total']); ?></th>
                        <th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $monthlySummary['net_total']); ?></th>
                    </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <?php echo orange_accounting_report_print_metafoot_markup($printDatetime); ?>
    </div>
</div>

<div class="gl-pick-modal" id="prr_supplier_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="prr_supplier_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="prr_supplier_pick_title">
        <h3 id="prr_supplier_pick_title" class="gl-pick-modal__title">اختيار المورد</h3>
        <p class="muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
        <input type="search" id="prr_supplier_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو اسم المورد..." autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="prr_supplier_pick_list"></ul>
        <button type="button" class="btn-secondary" id="prr_supplier_pick_close">إغلاق</button>
    </div>
</div>

<div class="gl-pick-modal" id="prr_product_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="prr_product_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="prr_product_pick_title">
        <h3 id="prr_product_pick_title" class="gl-pick-modal__title">اختيار الصنف</h3>
        <p class="muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
        <input type="search" id="prr_product_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو اسم الصنف..." autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="prr_product_pick_list"></ul>
        <button type="button" class="btn-secondary" id="prr_product_pick_close">إغلاق</button>
    </div>
</div>

<style>
.prr-tabs { display:flex; flex-wrap:wrap; gap:8px; }
.prr-tab {
    padding:7px 14px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    background:#f8fafc;
    color:#334155;
    text-decoration:none;
    font-size:0.92rem;
}
.prr-tab.is-active { background:#0f172a; color:#fff; border-color:#0f172a; }
.prr-report-filter-row {
    margin-top: 8px;
    width: 100%;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
}
.prr-report-filter-cell {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #f8fafc;
    padding: 4px 8px;
    min-width: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}
.prr-report-filter-label {
    font-size: 0.82rem;
    font-weight: 700;
    color: #0f172a;
    white-space: nowrap;
}
.prr-report-filter-value {
    font-size: 0.82rem;
    color: #334155;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.prr-filter-form .prr-main-row {
    flex-basis: 100%;
    display: flex;
    flex-wrap: nowrap;
    gap: 6px;
    align-items: flex-end;
    overflow-x: auto;
    min-width: 0;
    padding-bottom: 2px;
}
.prr-filter-form .prr-date-field {
    flex: 0 0 auto;
}
.prr-filter-form .prr-date-field-to {
    margin-inline-start: 0.2rem;
}
.prr-filter-form #prr_from,
.prr-filter-form #prr_to {
    width: 6.6rem;
    min-width: 6.6rem;
}
.prr-filter-form .prr-main-row .prr-supplier-field-wrap,
.prr-filter-form .prr-main-row .prr-product-field-wrap {
    flex: 1 1 24rem;
    min-width: 20rem;
}
.prr-filter-form .prr-supplier-name {
    min-width: 13rem !important;
}
.prr-filter-form .prr-product-name {
    min-width: 14rem !important;
}
.prr-filter-form .prr-options-row {
    flex-basis: 100%;
    display: flex;
    flex-wrap: nowrap;
    gap: 10px;
    align-items: center;
    min-height: 2.1rem;
    overflow-x: auto;
}
.prr-filter-form .prr-type-wrap,
.prr-filter-form .prr-payment-status-wrap {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 11rem;
}
.prr-filter-form .prr-items-zero-wrap {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.prr-filter-form .prr-suppliers-detail-wrap {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.prr-filter-form .prr-checkbox-control {
    min-height: 2.1rem;
    display: flex;
    align-items: center;
    justify-content: center;
}
.prr-filter-form .prr-checkbox-control input[type="checkbox"] {
    width: 1rem;
    height: 1rem;
    cursor: pointer;
}
.prr-filter-form .prr-print-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-basis: 100%;
    justify-content: flex-end;
}
.prr-doc-table {
    table-layout: fixed;
    min-width: 72rem;
}
.prr-doc-table .prr-col-ref { width: 10%; }
.prr-doc-table .prr-col-date { width: 9%; }
.prr-doc-table .prr-col-supplier { width: 25%; }
.prr-doc-table .prr-col-supplier-doc { width: 13%; }
.prr-doc-table .prr-col-type { width: 11%; }
.prr-doc-table .prr-col-money { width: 10.666%; }
.prr-suppliers-table {
    table-layout: fixed;
    min-width: 64rem;
}
.prr-suppliers-table .prr-sup-col-name { width: 34%; }
.prr-suppliers-table .prr-sup-col-count { width: 10%; }
.prr-suppliers-table .prr-sup-col-money { width: 15.333%; }
.prr-suppliers-detail-table {
    min-width: 80rem;
}
.prr-suppliers-detail-table .prr-supd-col-name { width: 24%; }
.prr-suppliers-detail-table .prr-supd-col-doc { width: 12%; }
.prr-suppliers-detail-table .prr-supd-col-invoice { width: 12%; }
.prr-suppliers-detail-table .prr-supd-col-ref { width: 14%; }
.prr-suppliers-detail-table .prr-supd-col-date { width: 10%; }
.prr-suppliers-detail-table .prr-supd-col-money { width: 9.333%; }
.prr-suppliers-detail-table .prr-supd-nosup-col-doc { width: 15%; }
.prr-suppliers-detail-table .prr-supd-nosup-col-invoice { width: 16%; }
.prr-suppliers-detail-table .prr-supd-nosup-col-ref { width: 18%; }
.prr-suppliers-detail-table .prr-supd-nosup-col-date { width: 12%; }
.prr-suppliers-detail-table .prr-supd-nosup-col-money { width: 13%; }
.prr-suppliers-table .prr-sup-nosup-col-count { width: 14%; }
.prr-suppliers-table .prr-sup-nosup-col-money { width: 24%; }
.prr-items-table {
    table-layout: fixed;
    min-width: 82rem;
}
.prr-items-table .prr-item-col-code { width: 10%; }
.prr-items-table .prr-item-col-name { width: 24%; }
.prr-items-table .prr-item-col-variant { width: 16%; }
.prr-items-table .prr-item-col-qty { width: 7%; }
.prr-items-table .prr-item-col-money { width: 9.666%; }
.prr-monthly-table {
    table-layout: fixed;
    min-width: 58rem;
}
.prr-monthly-table .prr-mon-col-month { width: 26%; }
.prr-monthly-table .prr-mon-col-count { width: 10%; }
.prr-monthly-table .prr-mon-col-money { width: 18%; }
@media (max-width: 960px) {
    .prr-report-filter-row {
        grid-template-columns: 1fr;
    }
}
@media print {
    .prr-report-filter-row {
        margin-top: 6px;
        gap: 6px;
    }
    .prr-report-filter-cell {
        padding: 3px 6px;
    }
    .prr-report-filter-label,
    .prr-report-filter-value {
        font-size: 0.78rem;
    }
}
</style>

<?php
$prDocTitle = $reportTitle . ' - ' . date('Y-m-d');
?>
<script>
(function () {
    var supplierRows = <?php echo json_encode($supplierPickerRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?> || [];
    var productRows = <?php echo json_encode($productPickerRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?> || [];

    var supplierIdEl = document.getElementById('prr_supplier_id');
    var supplierCodeEl = document.getElementById('prr_supplier_code');
    var supplierNameEl = document.getElementById('prr_supplier_name');
    var supplierModal = document.getElementById('prr_supplier_pick_modal');
    var supplierBackdrop = document.getElementById('prr_supplier_pick_backdrop');
    var supplierCloseBtn = document.getElementById('prr_supplier_pick_close');
    var supplierSearchEl = document.getElementById('prr_supplier_pick_q');
    var supplierListEl = document.getElementById('prr_supplier_pick_list');

    var productIdEl = document.getElementById('prr_product_id');
    var productCodeEl = document.getElementById('prr_product_code');
    var productNameEl = document.getElementById('prr_product_name');
    var productModal = document.getElementById('prr_product_pick_modal');
    var productBackdrop = document.getElementById('prr_product_pick_backdrop');
    var productCloseBtn = document.getElementById('prr_product_pick_close');
    var productSearchEl = document.getElementById('prr_product_pick_q');
    var productListEl = document.getElementById('prr_product_pick_list');

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function syncBodyLock() {
        var anyOpen = (supplierModal && !supplierModal.hidden) || (productModal && !productModal.hidden);
        document.body.classList.toggle('gl-pick-open', anyOpen);
    }

    function supplierSet(row) {
        if (!supplierIdEl || !supplierCodeEl || !supplierNameEl) {
            return;
        }
        if (!row) {
            supplierIdEl.value = '0';
            supplierCodeEl.value = '';
            supplierNameEl.value = '';
            return;
        }
        supplierIdEl.value = String(parseInt(String(row.id || '0'), 10) || 0);
        supplierCodeEl.value = String(row.code || '');
        supplierNameEl.value = String(row.name || '');
    }

    function supplierRender(query) {
        if (!supplierListEl) {
            return;
        }
        var q = String(query || '').trim().toLowerCase();
        var filtered = supplierRows.filter(function (r) {
            if (!q) {
                return true;
            }
            var hay = (String(r.code || '') + ' ' + String(r.name || '')).toLowerCase();
            return hay.indexOf(q) !== -1;
        });
        supplierListEl.innerHTML = '';
        if (!filtered.length) {
            supplierListEl.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>';
            return;
        }
        filtered.forEach(function (r) {
            var li = document.createElement('li');
            li.className = 'gl-pick-item';
            li.setAttribute('role', 'button');
            li.tabIndex = 0;
            li.innerHTML = '<span dir="ltr">' + esc(r.code || '') + '</span> — ' + esc(r.name || '');
            li.addEventListener('dblclick', function () {
                supplierSet(r);
                supplierClose();
            });
            li.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    supplierSet(r);
                    supplierClose();
                }
            });
            supplierListEl.appendChild(li);
        });
    }

    function supplierOpen() {
        if (!supplierModal) {
            return;
        }
        supplierModal.hidden = false;
        supplierModal.setAttribute('aria-hidden', 'false');
        syncBodyLock();
        if (supplierSearchEl) {
            supplierSearchEl.value = '';
            supplierRender('');
            supplierSearchEl.focus();
        } else {
            supplierRender('');
        }
    }

    function supplierClose() {
        if (!supplierModal) {
            return;
        }
        supplierModal.hidden = true;
        supplierModal.setAttribute('aria-hidden', 'true');
        syncBodyLock();
    }

    function productSet(row) {
        if (!productIdEl || !productCodeEl || !productNameEl) {
            return;
        }
        if (!row) {
            productIdEl.value = '0';
            productCodeEl.value = '';
            productNameEl.value = '';
            return;
        }
        productIdEl.value = String(parseInt(String(row.id || '0'), 10) || 0);
        productCodeEl.value = String(row.code || '');
        productNameEl.value = String(row.name || '');
    }

    function productRender(query) {
        if (!productListEl) {
            return;
        }
        var q = String(query || '').trim().toLowerCase();
        var filtered = productRows.filter(function (r) {
            if (!q) {
                return true;
            }
            var hay = (String(r.code || '') + ' ' + String(r.name || '')).toLowerCase();
            return hay.indexOf(q) !== -1;
        });
        productListEl.innerHTML = '';
        if (!filtered.length) {
            productListEl.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>';
            return;
        }
        filtered.forEach(function (r) {
            var li = document.createElement('li');
            li.className = 'gl-pick-item';
            li.setAttribute('role', 'button');
            li.tabIndex = 0;
            li.innerHTML = '<span dir="ltr">' + esc(r.code || '') + '</span> — ' + esc(r.name || '');
            li.addEventListener('dblclick', function () {
                productSet(r);
                productClose();
            });
            li.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    productSet(r);
                    productClose();
                }
            });
            productListEl.appendChild(li);
        });
    }

    function productOpen() {
        if (!productModal) {
            return;
        }
        productModal.hidden = false;
        productModal.setAttribute('aria-hidden', 'false');
        syncBodyLock();
        if (productSearchEl) {
            productSearchEl.value = '';
            productRender('');
            productSearchEl.focus();
        } else {
            productRender('');
        }
    }

    function productClose() {
        if (!productModal) {
            return;
        }
        productModal.hidden = true;
        productModal.setAttribute('aria-hidden', 'true');
        syncBodyLock();
    }

    if (supplierCodeEl && supplierNameEl) {
        supplierNameEl.addEventListener('dblclick', supplierOpen);
        supplierNameEl.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                supplierOpen();
            }
        });
        if (supplierBackdrop) {
            supplierBackdrop.addEventListener('click', supplierClose);
        }
        if (supplierCloseBtn) {
            supplierCloseBtn.addEventListener('click', supplierClose);
        }
        if (supplierSearchEl) {
            supplierSearchEl.addEventListener('input', function () {
                supplierRender(supplierSearchEl.value || '');
            });
        }
    }

    if (productCodeEl && productNameEl) {
        productNameEl.addEventListener('dblclick', productOpen);
        productNameEl.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                productOpen();
            }
        });
        if (productBackdrop) {
            productBackdrop.addEventListener('click', productClose);
        }
        if (productCloseBtn) {
            productCloseBtn.addEventListener('click', productClose);
        }
        if (productSearchEl) {
            productSearchEl.addEventListener('input', function () {
                productRender(productSearchEl.value || '');
            });
        }
    }

    window.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') {
            return;
        }
        if (supplierModal && !supplierModal.hidden) {
            supplierClose();
        }
        if (productModal && !productModal.hidden) {
            productClose();
        }
    });
})();
</script>
<script>
(function () {
    var reportTitle = <?php echo json_encode($prDocTitle, JSON_UNESCAPED_UNICODE); ?>;
    var originalTitle = document.title;
    window.orangeAdminVoucherPrintTitle = reportTitle;
    window.addEventListener('afterprint', function () { document.title = originalTitle; });
})();
</script>
