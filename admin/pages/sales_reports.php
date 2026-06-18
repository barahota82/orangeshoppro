<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/accounting_report_money.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/sales_return_analytics.php';

$pdo = orange_admin_page_pdo();
$countryId = function_exists('orange_admin_context_country_id') ? (int) orange_admin_context_country_id($pdo) : 0;
$companyNameAr = orange_company_settings_name_ar($pdo);

$tabs = [
    'invoices' => 'فواتير المبيعات',
    'returns' => 'مردودات المبيعات',
    'customers' => 'ملخص العملاء',
    'monthly' => 'ملخص شهري',
    'items' => 'تحليل الأصناف',
];
$tab = isset($_GET['r']) ? (string) $_GET['r'] : 'invoices';
if (!isset($tabs[$tab])) {
    $tab = 'invoices';
}

$parseDate = static function (string $raw): ?string {
    $ymd = orange_parse_admin_date_to_ymd(trim($raw));
    return $ymd !== '' ? $ymd : null;
};
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$from = isset($_GET['from']) ? ($parseDate((string) $_GET['from']) ?? $monthStart) : $monthStart;
$to = isset($_GET['to']) ? ($parseDate((string) $_GET['to']) ?? $today) : $today;
if (strcmp($from, $to) > 0) {
    [$from, $to] = [$to, $from];
}
$fromDisplay = orange_format_date_dmY($from);
$toDisplay = orange_format_date_dmY($to);

$source = isset($_GET['src']) ? trim((string) $_GET['src']) : 'all';
if (!in_array($source, ['all', 'company', 'online'], true)) {
    $source = 'all';
}

$customerId = isset($_GET['customer_id']) ? max(0, (int) $_GET['customer_id']) : 0;
$productId = isset($_GET['product_id']) ? max(0, (int) $_GET['product_id']) : 0;
$hideZero = isset($_GET['hz']) && (string) $_GET['hz'] === '1';

$hasCustomers = orange_table_exists($pdo, 'customers');
$hasProducts = orange_table_exists($pdo, 'products');
$hasProductVariants = orange_table_exists($pdo, 'product_variants');
$hasOrders = orange_table_exists($pdo, 'orders');
$hasOrderItems = orange_table_exists($pdo, 'order_items');
$hasSalesReturns = orange_table_exists($pdo, 'sales_returns');
$hasSalesReturnItems = orange_table_exists($pdo, 'sales_return_items');

$hasCustomerCode = $hasCustomers && orange_table_has_column($pdo, 'customers', 'code');
$hasCustomerNameAr = $hasCustomers && orange_table_has_column($pdo, 'customers', 'name_ar');
$hasCustomerName = $hasCustomers && orange_table_has_column($pdo, 'customers', 'name');
$customerNameCol = $hasCustomerNameAr ? 'name_ar' : ($hasCustomerName ? 'name' : '');
$customerCodeExpr = $hasCustomerCode
    ? "COALESCE(NULLIF(TRIM(c.code), ''), CONCAT('CUS-', c.id))"
    : "CONCAT('CUS-', c.id)";
$customerNameExpr = $customerNameCol !== ''
    ? "COALESCE(NULLIF(TRIM(c.{$customerNameCol}), ''), CONCAT('#', c.id))"
    : "CONCAT('#', c.id)";

$hasItemCode = $hasProducts && orange_table_has_column($pdo, 'products', 'item_code');
$hasProductName = $hasProducts && orange_table_has_column($pdo, 'products', 'name');
$hasProductNameAr = $hasProducts && orange_table_has_column($pdo, 'products', 'name_ar');
$itemCodeExpr = $hasItemCode
    ? "COALESCE(NULLIF(TRIM(p.item_code), ''), CONCAT('P', p.id))"
    : "CONCAT('P', p.id)";
if ($hasProductName && $hasProductNameAr) {
    $productNameExpr = "COALESCE(NULLIF(TRIM(p.name), ''), NULLIF(TRIM(p.name_ar), ''), CONCAT('#', p.id))";
} elseif ($hasProductName) {
    $productNameExpr = "COALESCE(NULLIF(TRIM(p.name), ''), CONCAT('#', p.id))";
} elseif ($hasProductNameAr) {
    $productNameExpr = "COALESCE(NULLIF(TRIM(p.name_ar), ''), CONCAT('#', p.id))";
} else {
    $productNameExpr = "CONCAT('#', p.id)";
}

$customers = [];
$customerMap = [];
if ($hasCustomers) {
    $sql = 'SELECT c.id, ' . $customerCodeExpr . ' AS customer_code, ' . $customerNameExpr . ' AS customer_name
            FROM customers c
            WHERE 1=1'
        . orange_sql_country_and_fragment($pdo, 'customers', 'c', $countryId)
        . ' ORDER BY customer_name ASC, c.id ASC';
    $customers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($customers as $row) {
        $cid = (int) ($row['id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $customerMap[$cid] = [
            'name' => (string) ($row['customer_name'] ?? ''),
            'code' => (string) ($row['customer_code'] ?? ('CUS-' . $cid)),
        ];
    }
}
if ($customerId > 0 && !isset($customerMap[$customerId])) {
    $customerId = 0;
}

$products = [];
$productMap = [];
if ($hasProducts) {
    $sql = 'SELECT p.id, ' . $itemCodeExpr . ' AS item_code, ' . $productNameExpr . ' AS product_name
            FROM products p
            WHERE 1=1'
        . orange_sql_country_and_fragment($pdo, 'products', 'p', $countryId)
        . ' ORDER BY product_name ASC, p.id ASC';
    $products = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($products as $row) {
        $pid = (int) ($row['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $productMap[$pid] = [
            'name' => (string) ($row['product_name'] ?? ''),
            'code' => (string) ($row['item_code'] ?? ('P' . $pid)),
        ];
    }
}
if ($productId > 0 && !isset($productMap[$productId])) {
    $productId = 0;
}

$customerPickerRows = [];
foreach ($customers as $row) {
    $cid = (int) ($row['id'] ?? 0);
    if ($cid <= 0) {
        continue;
    }
    $customerPickerRows[] = [
        'id' => $cid,
        'code' => (string) ($customerMap[$cid]['code'] ?? ('CUS-' . $cid)),
        'name' => (string) ($customerMap[$cid]['name'] ?? ''),
    ];
}

$productPickerRows = [];
foreach ($products as $row) {
    $pid = (int) ($row['id'] ?? 0);
    if ($pid <= 0) {
        continue;
    }
    $productPickerRows[] = [
        'id' => $pid,
        'code' => (string) ($productMap[$pid]['code'] ?? ('P' . $pid)),
        'name' => (string) ($productMap[$pid]['name'] ?? ''),
    ];
}

$selectedCustomerCode = $customerId > 0 ? (string) ($customerMap[$customerId]['code'] ?? ('CUS-' . $customerId)) : '';
$selectedCustomerName = $customerId > 0 ? (string) ($customerMap[$customerId]['name'] ?? '') : '';
$selectedProductCode = $productId > 0 ? (string) ($productMap[$productId]['code'] ?? ('P' . $productId)) : '';
$selectedProductName = $productId > 0 ? (string) ($productMap[$productId]['name'] ?? '') : '';

$hasOrdCreatedAt = $hasOrders && orange_table_has_column($pdo, 'orders', 'created_at');
$hasOrdCompletedAt = $hasOrders && orange_table_has_column($pdo, 'orders', 'completed_at');
$hasOrdSource = $hasOrders && orange_table_has_column($pdo, 'orders', 'order_source');
$hasOrdPaymentTerms = $hasOrders && orange_table_has_column($pdo, 'orders', 'payment_terms');
$hasOrdInvoiceNumber = $hasOrders && orange_table_has_column($pdo, 'orders', 'invoice_number');
$hasOrdOrderNumber = $hasOrders && orange_table_has_column($pdo, 'orders', 'order_number');
$hasOrdCustomerId = $hasOrders && orange_table_has_column($pdo, 'orders', 'customer_id');
$hasOrdCustomerName = $hasOrders && orange_table_has_column($pdo, 'orders', 'customer_name');

$hasOiLineDiscount = $hasOrderItems && orange_table_has_column($pdo, 'order_items', 'line_discount');
$hasOiVariant = $hasOrderItems && orange_table_has_column($pdo, 'order_items', 'variant_id');
$hasOiColor = $hasOrderItems && orange_table_has_column($pdo, 'order_items', 'color');
$hasOiSize = $hasOrderItems && orange_table_has_column($pdo, 'order_items', 'size');

$hasSrCreatedAt = $hasSalesReturns && orange_table_has_column($pdo, 'sales_returns', 'created_at');
$hasSrSourceKind = $hasSalesReturns && orange_table_has_column($pdo, 'sales_returns', 'source_kind');
$hasSrInvoiceRef = $hasSalesReturns && orange_table_has_column($pdo, 'sales_returns', 'invoice_reference');
$hasSrReturnNumber = $hasSalesReturns && orange_table_has_column($pdo, 'sales_returns', 'return_number');
$hasSrCustomerId = $hasSalesReturns && orange_table_has_column($pdo, 'sales_returns', 'customer_id');
$hasSrType = $hasSalesReturns && orange_table_has_column($pdo, 'sales_returns', 'type');

$hasSriVariant = $hasSalesReturnItems && orange_table_has_column($pdo, 'sales_return_items', 'variant_id');
$hasSriLineDiscount = $hasSalesReturnItems && orange_table_has_column($pdo, 'sales_return_items', 'line_discount');

$orderDateExpr = static function (string $alias) use ($hasOrdCompletedAt, $hasOrdCreatedAt): string {
    if ($hasOrdCompletedAt && $hasOrdCreatedAt) {
        return 'DATE(COALESCE(' . $alias . '.completed_at, ' . $alias . '.created_at))';
    }
    if ($hasOrdCompletedAt) {
        return 'DATE(' . $alias . '.completed_at)';
    }
    if ($hasOrdCreatedAt) {
        return 'DATE(' . $alias . '.created_at)';
    }
    return 'CURDATE()';
};
$returnDateExpr = static function (string $alias) use ($hasSrCreatedAt): string {
    return $hasSrCreatedAt ? 'DATE(' . $alias . '.created_at)' : 'CURDATE()';
};

$reportMoney = orange_accounting_report_money($pdo, isset($orangeAdminMoney) ? $orangeAdminMoney : null);
$fmtMoney = static fn(float $v): string => orange_accounting_report_format_amount($v, $reportMoney);
$fmtQty = static fn(float $v): string => number_format($v, 0, '.', ',');
$sourceLabel = static function (string $v): string {
    $v = strtolower(trim($v));
    if ($v === 'company') {
        return 'شركة';
    }
    if ($v === 'website' || $v === 'online') {
        return 'أونلاين';
    }
    return $v !== '' ? $v : '—';
};
$paymentLabel = static function (string $v): string {
    $v = strtolower(trim($v));
    if ($v === 'cash') {
        return 'نقدي';
    }
    if ($v === 'credit') {
        return 'آجل';
    }
    if ($v === 'online') {
        return 'أونلاين';
    }
    return $v !== '' ? $v : '—';
};
$resolveReturnSource = static function (array $row) use ($hasSrSourceKind, $hasSrInvoiceRef): string {
    $s = $hasSrSourceKind ? trim((string) ($row['source_kind'] ?? '')) : '';
    if ($s !== '') {
        return $s;
    }
    if ($hasSrInvoiceRef) {
        $ref = strtoupper(trim((string) ($row['invoice_reference'] ?? '')));
        if (str_starts_with($ref, 'INV-C-')) {
            return 'company';
        }
        if (str_starts_with($ref, 'INV-O-')) {
            return 'online';
        }
    }
    return '';
};

$rows = [];
$invoiceSummary = ['count' => 0, 'subtotal' => 0.0, 'discount' => 0.0, 'net' => 0.0];
$returnSummary = ['count' => 0, 'total' => 0.0];
$customerSummary = ['sales_count' => 0, 'sales_total' => 0.0, 'return_count' => 0, 'return_total' => 0.0, 'net' => 0.0];
$itemSummary = ['sales_qty' => 0.0, 'sales_value' => 0.0, 'return_qty' => 0.0, 'return_value' => 0.0, 'net_qty' => 0.0, 'net_value' => 0.0];
$monthlySummary = ['sales_count' => 0, 'sales_total' => 0.0, 'return_count' => 0, 'return_total' => 0.0, 'net_total' => 0.0];
$reportError = '';

try {
    if ($tab === 'invoices') {
        if (!$hasOrders) {
            throw new RuntimeException('جدول المبيعات غير متاح.');
        }
        $dateSql = $orderDateExpr('o');
        $lineDiscountExpr = $hasOiLineDiscount ? 'COALESCE(oi.line_discount, 0)' : '0';
        $linesJoin = $hasOrderItems
            ? 'LEFT JOIN (
                   SELECT oi.order_id, COALESCE(SUM((COALESCE(oi.qty,0) * COALESCE(oi.price,0)) - ' . $lineDiscountExpr . '),0) AS lines_subtotal
                   FROM order_items oi
                   GROUP BY oi.order_id
               ) ols ON ols.order_id = o.id'
            : '';
        $referenceExpr = $hasOrdInvoiceNumber
            ? ($hasOrdOrderNumber
                ? "COALESCE(NULLIF(TRIM(o.invoice_number),''), NULLIF(TRIM(o.order_number),''), CONCAT('ORD-', o.id))"
                : "COALESCE(NULLIF(TRIM(o.invoice_number),''), CONCAT('ORD-', o.id))")
            : ($hasOrdOrderNumber ? "COALESCE(NULLIF(TRIM(o.order_number),''), CONCAT('ORD-', o.id))" : "CONCAT('ORD-', o.id)");
        $sql = 'SELECT ' . $referenceExpr . ' AS reference,
                       ' . $dateSql . ' AS doc_date,
                       ' . ($hasOrdCustomerId ? 'COALESCE(o.customer_id,0)' : '0') . ' AS customer_id,
                       ' . ($hasOrdCustomerName ? "COALESCE(o.customer_name,'')" : "''") . ' AS customer_name_raw,
                       ' . ($hasOrdSource ? "COALESCE(o.order_source,'website')" : "'website'") . ' AS source_kind,
                       ' . ($hasOrdPaymentTerms ? "COALESCE(o.payment_terms,'cash')" : "'cash'") . ' AS pay_type,
                       COALESCE(ols.lines_subtotal, COALESCE(o.total,0)) AS subtotal_amount,
                       COALESCE(o.total,0) AS net_amount
                FROM orders o
                ' . $linesJoin . '
                WHERE 1=1'
                . orange_sql_country_and_fragment($pdo, 'orders', 'o', $countryId)
                . " AND o.status = 'completed'
                  AND " . $dateSql . ' BETWEEN ? AND ?';
        $params = [$from, $to];
        if ($source === 'company') {
            if ($hasOrdSource) {
                $sql .= " AND COALESCE(o.order_source,'website') = 'company'";
            } elseif ($hasOrdInvoiceNumber) {
                $sql .= " AND UPPER(COALESCE(o.invoice_number,'')) LIKE 'INV-C-%'";
            }
        } elseif ($source === 'online') {
            if ($hasOrdSource) {
                $sql .= " AND COALESCE(o.order_source,'website') <> 'company'";
            } elseif ($hasOrdInvoiceNumber) {
                $sql .= " AND UPPER(COALESCE(o.invoice_number,'')) LIKE 'INV-O-%'";
            }
        }
        if ($customerId > 0 && $hasOrdCustomerId) {
            $sql .= ' AND o.customer_id = ?';
            $params[] = $customerId;
        }
        if ($productId > 0 && $hasOrderItems) {
            $sql .= ' AND EXISTS (SELECT 1 FROM order_items oi2 WHERE oi2.order_id = o.id AND oi2.product_id = ?)';
            $params[] = $productId;
        }
        $sql .= ' ORDER BY ' . $dateSql . ' DESC, o.id DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cid = (int) ($r['customer_id'] ?? 0);
            $customerName = trim((string) ($r['customer_name_raw'] ?? ''));
            if ($customerName === '' && $cid > 0 && isset($customerMap[$cid])) {
                $customerName = (string) $customerMap[$cid]['name'];
            }
            if ($customerName === '') {
                $customerName = $cid > 0 ? ('#' . $cid) : 'غير محدد';
            }
            $sub = (float) ($r['subtotal_amount'] ?? 0);
            $net = (float) ($r['net_amount'] ?? 0);
            $disc = $sub > $net ? ($sub - $net) : 0.0;
            $rows[] = [
                'reference' => (string) ($r['reference'] ?? ''),
                'date' => (string) ($r['doc_date'] ?? ''),
                'customer' => $customerName,
                'source' => (string) ($r['source_kind'] ?? ''),
                'payment' => (string) ($r['pay_type'] ?? ''),
                'subtotal' => $sub,
                'discount' => $disc,
                'net' => $net,
            ];
            $invoiceSummary['count']++;
            $invoiceSummary['subtotal'] += $sub;
            $invoiceSummary['discount'] += $disc;
            $invoiceSummary['net'] += $net;
        }
    } elseif ($tab === 'returns') {
        if (!$hasSalesReturns) {
            throw new RuntimeException('جدول مردودات المبيعات غير متاح.');
        }
        $dateSql = $returnDateExpr('sr');
        $referenceExpr = $hasSrReturnNumber
            ? "COALESCE(NULLIF(TRIM(sr.return_number),''), CONCAT('SR-', sr.id))"
            : "CONCAT('SR-', sr.id)";
        $sql = 'SELECT ' . $referenceExpr . ' AS reference,
                       ' . $dateSql . ' AS doc_date,
                       ' . ($hasSrCustomerId ? 'COALESCE(sr.customer_id,0)' : '0') . ' AS customer_id,
                       ' . ($hasSrInvoiceRef ? "COALESCE(sr.invoice_reference,'')" : "''") . ' AS invoice_reference,
                       ' . ($hasSrSourceKind ? "COALESCE(sr.source_kind,'')" : "''") . ' AS source_kind,
                       ' . ($hasSrType ? "COALESCE(sr.type,'cash')" : "'cash'") . ' AS pay_type,
                       COALESCE(sr.total,0) AS total_amount
                FROM sales_returns sr
                WHERE 1=1'
            . orange_sql_country_and_fragment($pdo, 'sales_returns', 'sr', $countryId)
            . ' AND ' . $dateSql . ' BETWEEN ? AND ?';
        $params = [$from, $to];
        if ($source !== 'all') {
            if ($hasSrSourceKind) {
                $sql .= ' AND sr.source_kind = ?';
                $params[] = $source;
            } elseif ($hasSrInvoiceRef) {
                $sql .= $source === 'company'
                    ? " AND UPPER(COALESCE(sr.invoice_reference,'')) LIKE 'INV-C-%'"
                    : " AND UPPER(COALESCE(sr.invoice_reference,'')) LIKE 'INV-O-%'";
            }
        }
        if ($customerId > 0 && $hasSrCustomerId) {
            $sql .= ' AND sr.customer_id = ?';
            $params[] = $customerId;
        }
        if ($productId > 0 && $hasSalesReturnItems) {
            $sql .= ' AND EXISTS (SELECT 1 FROM sales_return_items sri2 WHERE sri2.sales_return_id = sr.id AND sri2.product_id = ?)';
            $params[] = $productId;
        }
        $sql .= ' ORDER BY ' . $dateSql . ' DESC, sr.id DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cid = (int) ($r['customer_id'] ?? 0);
            $customerName = $cid > 0 && isset($customerMap[$cid]) ? (string) $customerMap[$cid]['name'] : ($cid > 0 ? ('#' . $cid) : 'غير محدد');
            $rows[] = [
                'reference' => (string) ($r['reference'] ?? ''),
                'date' => (string) ($r['doc_date'] ?? ''),
                'customer' => $customerName,
                'invoice_reference' => (string) ($r['invoice_reference'] ?? ''),
                'source' => $resolveReturnSource($r),
                'payment' => (string) ($r['pay_type'] ?? 'cash'),
                'total' => (float) ($r['total_amount'] ?? 0),
            ];
            $returnSummary['count']++;
            $returnSummary['total'] += (float) ($r['total_amount'] ?? 0);
        }
    } elseif ($tab === 'customers') {
        $salesAgg = [];
        $returnAgg = [];

        if ($hasOrders) {
            $dateSql = $orderDateExpr('o');
            $sql = 'SELECT ' . ($hasOrdCustomerId ? 'COALESCE(o.customer_id,0)' : '0') . ' AS customer_id,
                           COUNT(*) AS cnt,
                           COALESCE(SUM(COALESCE(o.total,0)),0) AS total_sum
                    FROM orders o
                    WHERE 1=1'
                . orange_sql_country_and_fragment($pdo, 'orders', 'o', $countryId)
                . " AND o.status = 'completed'
                  AND " . $dateSql . ' BETWEEN ? AND ?';
            $params = [$from, $to];
            if ($source === 'company') {
                if ($hasOrdSource) {
                    $sql .= " AND COALESCE(o.order_source,'website') = 'company'";
                }
            } elseif ($source === 'online') {
                if ($hasOrdSource) {
                    $sql .= " AND COALESCE(o.order_source,'website') <> 'company'";
                }
            }
            if ($customerId > 0 && $hasOrdCustomerId) {
                $sql .= ' AND o.customer_id = ?';
                $params[] = $customerId;
            }
            if ($productId > 0 && $hasOrderItems) {
                $sql .= ' AND EXISTS (SELECT 1 FROM order_items oi2 WHERE oi2.order_id = o.id AND oi2.product_id = ?)';
                $params[] = $productId;
            }
            $sql .= ' GROUP BY customer_id';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cid = (int) ($r['customer_id'] ?? 0);
                $salesAgg[$cid] = ['count' => (int) ($r['cnt'] ?? 0), 'total' => (float) ($r['total_sum'] ?? 0)];
            }
        }

        if ($hasSalesReturns) {
            $dateSql = $returnDateExpr('sr');
            $sql = 'SELECT ' . ($hasSrCustomerId ? 'COALESCE(sr.customer_id,0)' : '0') . ' AS customer_id,
                           COUNT(*) AS cnt,
                           COALESCE(SUM(COALESCE(sr.total,0)),0) AS total_sum
                    FROM sales_returns sr
                    WHERE 1=1'
                . orange_sql_country_and_fragment($pdo, 'sales_returns', 'sr', $countryId)
                . ' AND ' . $dateSql . ' BETWEEN ? AND ?';
            $params = [$from, $to];
            if ($source !== 'all' && $hasSrSourceKind) {
                $sql .= ' AND sr.source_kind = ?';
                $params[] = $source;
            }
            if ($customerId > 0 && $hasSrCustomerId) {
                $sql .= ' AND sr.customer_id = ?';
                $params[] = $customerId;
            }
            if ($productId > 0 && $hasSalesReturnItems) {
                $sql .= ' AND EXISTS (SELECT 1 FROM sales_return_items sri2 WHERE sri2.sales_return_id = sr.id AND sri2.product_id = ?)';
                $params[] = $productId;
            }
            $sql .= ' GROUP BY customer_id';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cid = (int) ($r['customer_id'] ?? 0);
                $returnAgg[$cid] = ['count' => (int) ($r['cnt'] ?? 0), 'total' => (float) ($r['total_sum'] ?? 0)];
            }
        }

        $allIds = [];
        foreach (array_keys($salesAgg) as $cid) {
            $allIds[$cid] = true;
        }
        foreach (array_keys($returnAgg) as $cid) {
            $allIds[$cid] = true;
        }
        foreach (array_keys($allIds) as $cid) {
            if ($customerId > 0 && $cid !== $customerId) {
                continue;
            }
            $s = $salesAgg[$cid] ?? ['count' => 0, 'total' => 0.0];
            $r = $returnAgg[$cid] ?? ['count' => 0, 'total' => 0.0];
            $name = $cid > 0 && isset($customerMap[$cid]) ? (string) $customerMap[$cid]['name'] : ($cid > 0 ? ('#' . $cid) : 'غير محدد');
            $net = (float) $s['total'] - (float) $r['total'];
            $rows[] = [
                'customer' => $name,
                'sales_count' => (int) $s['count'],
                'sales_total' => (float) $s['total'],
                'return_count' => (int) $r['count'],
                'return_total' => (float) $r['total'],
                'net' => $net,
            ];
            $customerSummary['sales_count'] += (int) $s['count'];
            $customerSummary['sales_total'] += (float) $s['total'];
            $customerSummary['return_count'] += (int) $r['count'];
            $customerSummary['return_total'] += (float) $r['total'];
            $customerSummary['net'] += $net;
        }
    } elseif ($tab === 'items') {
        $itemsMap = [];

        if ($hasOrders && $hasOrderItems) {
            $dateSql = $orderDateExpr('o');
            $variantExpr = $hasOiVariant ? 'COALESCE(oi.variant_id,0)' : '0';
            $variantJoin = ($hasOiVariant && $hasProductVariants) ? 'LEFT JOIN product_variants pv ON pv.id = oi.variant_id' : 'LEFT JOIN product_variants pv ON 1=0';
            $colorExpr = $hasOiColor ? "COALESCE(NULLIF(TRIM(oi.color),''), COALESCE(pv.color,''))" : "COALESCE(pv.color,'')";
            $sizeExpr = $hasOiSize ? "COALESCE(NULLIF(TRIM(oi.size),''), COALESCE(pv.size,''))" : "COALESCE(pv.size,'')";
            $lineDiscountExpr = $hasOiLineDiscount ? 'COALESCE(oi.line_discount,0)' : '0';
            $sql = 'SELECT COALESCE(oi.product_id,0) AS product_id,
                           ' . $variantExpr . ' AS variant_id,
                           ' . $colorExpr . ' AS color,
                           ' . $sizeExpr . ' AS size,
                           COALESCE(SUM(COALESCE(oi.qty,0)),0) AS qty_sum,
                           COALESCE(SUM((COALESCE(oi.qty,0) * COALESCE(oi.price,0)) - ' . $lineDiscountExpr . '),0) AS value_sum
                    FROM order_items oi
                    INNER JOIN orders o ON o.id = oi.order_id
                    ' . $variantJoin . '
                    WHERE 1=1'
                . orange_sql_country_and_fragment($pdo, 'orders', 'o', $countryId)
                . " AND o.status = 'completed'
                  AND " . $dateSql . ' BETWEEN ? AND ?';
            $params = [$from, $to];
            if ($source === 'company' && $hasOrdSource) {
                $sql .= " AND COALESCE(o.order_source,'website') = 'company'";
            } elseif ($source === 'online' && $hasOrdSource) {
                $sql .= " AND COALESCE(o.order_source,'website') <> 'company'";
            }
            if ($customerId > 0 && $hasOrdCustomerId) {
                $sql .= ' AND o.customer_id = ?';
                $params[] = $customerId;
            }
            if ($productId > 0) {
                $sql .= ' AND oi.product_id = ?';
                $params[] = $productId;
            }
            $sql .= ' GROUP BY product_id, variant_id, color, size';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pid = (int) ($r['product_id'] ?? 0);
                $vid = (int) ($r['variant_id'] ?? 0);
                $color = trim((string) ($r['color'] ?? ''));
                $size = trim((string) ($r['size'] ?? ''));
                $key = $pid . '|' . $vid . '|' . $color . '|' . $size;
                $itemsMap[$key] = [
                    'product_id' => $pid,
                    'item_code' => $pid > 0 && isset($productMap[$pid]) ? (string) $productMap[$pid]['code'] : '',
                    'product_name' => $pid > 0 && isset($productMap[$pid]) ? (string) $productMap[$pid]['name'] : 'غير محدد',
                    'variant' => trim($color . ' / ' . $size),
                    'sales_qty' => (float) ($r['qty_sum'] ?? 0),
                    'sales_value' => (float) ($r['value_sum'] ?? 0),
                    'return_qty' => 0.0,
                    'return_value' => 0.0,
                ];
            }
        }

        if ($hasSalesReturns && $hasSalesReturnItems) {
            $dateSql = $returnDateExpr('sr');
            $variantExpr = $hasSriVariant ? 'COALESCE(sri.variant_id,0)' : '0';
            $variantJoin = ($hasSriVariant && $hasProductVariants) ? 'LEFT JOIN product_variants pv ON pv.id = sri.variant_id' : 'LEFT JOIN product_variants pv ON 1=0';
            $lineDiscountExpr = $hasSriLineDiscount ? 'COALESCE(sri.line_discount,0)' : '0';
            $sql = 'SELECT COALESCE(sri.product_id,0) AS product_id,
                           ' . $variantExpr . ' AS variant_id,
                           COALESCE(pv.color, \'\') AS color,
                           COALESCE(pv.size, \'\') AS size,
                           COALESCE(SUM(COALESCE(sri.qty,0)),0) AS qty_sum,
                           COALESCE(SUM((COALESCE(sri.qty,0) * COALESCE(sri.price,0)) - ' . $lineDiscountExpr . '),0) AS value_sum
                    FROM sales_return_items sri
                    INNER JOIN sales_returns sr ON sr.id = sri.sales_return_id
                    ' . $variantJoin . '
                    WHERE 1=1'
                . orange_sql_country_and_fragment($pdo, 'sales_returns', 'sr', $countryId)
                . ' AND ' . $dateSql . ' BETWEEN ? AND ?';
            $params = [$from, $to];
            if ($source !== 'all' && $hasSrSourceKind) {
                $sql .= ' AND sr.source_kind = ?';
                $params[] = $source;
            }
            if ($customerId > 0 && $hasSrCustomerId) {
                $sql .= ' AND sr.customer_id = ?';
                $params[] = $customerId;
            }
            if ($productId > 0) {
                $sql .= ' AND sri.product_id = ?';
                $params[] = $productId;
            }
            $sql .= ' GROUP BY product_id, variant_id, color, size';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pid = (int) ($r['product_id'] ?? 0);
                $vid = (int) ($r['variant_id'] ?? 0);
                $color = trim((string) ($r['color'] ?? ''));
                $size = trim((string) ($r['size'] ?? ''));
                $key = $pid . '|' . $vid . '|' . $color . '|' . $size;
                if (!isset($itemsMap[$key])) {
                    $itemsMap[$key] = [
                        'product_id' => $pid,
                        'item_code' => $pid > 0 && isset($productMap[$pid]) ? (string) $productMap[$pid]['code'] : '',
                        'product_name' => $pid > 0 && isset($productMap[$pid]) ? (string) $productMap[$pid]['name'] : 'غير محدد',
                        'variant' => trim($color . ' / ' . $size),
                        'sales_qty' => 0.0,
                        'sales_value' => 0.0,
                        'return_qty' => 0.0,
                        'return_value' => 0.0,
                    ];
                }
                $itemsMap[$key]['return_qty'] = (float) ($r['qty_sum'] ?? 0);
                $itemsMap[$key]['return_value'] = (float) ($r['value_sum'] ?? 0);
            }
        }

        foreach ($itemsMap as $row) {
            $variant = trim((string) $row['variant']);
            if ($variant === '' || $variant === '/') {
                $variant = '—';
            }
            $netQty = (float) $row['sales_qty'] - (float) $row['return_qty'];
            $netValue = (float) $row['sales_value'] - (float) $row['return_value'];
            if ($hideZero && abs($netQty) < 0.00001 && abs($netValue) < 0.00001) {
                continue;
            }
            $rows[] = [
                'item_code' => (string) $row['item_code'],
                'product_name' => (string) $row['product_name'],
                'variant' => $variant,
                'sales_qty' => (float) $row['sales_qty'],
                'sales_value' => (float) $row['sales_value'],
                'return_qty' => (float) $row['return_qty'],
                'return_value' => (float) $row['return_value'],
                'net_qty' => $netQty,
                'net_value' => $netValue,
            ];
            $itemSummary['sales_qty'] += (float) $row['sales_qty'];
            $itemSummary['sales_value'] += (float) $row['sales_value'];
            $itemSummary['return_qty'] += (float) $row['return_qty'];
            $itemSummary['return_value'] += (float) $row['return_value'];
            $itemSummary['net_qty'] += $netQty;
            $itemSummary['net_value'] += $netValue;
        }
    } elseif ($tab === 'monthly') {
        $monthMap = [];

        if ($hasOrders) {
            $dateSql = $orderDateExpr('o');
            $sql = 'SELECT YEAR(' . $dateSql . ') AS yy, MONTH(' . $dateSql . ') AS mm,
                           COUNT(*) AS cnt, COALESCE(SUM(COALESCE(o.total,0)),0) AS total_sum
                    FROM orders o
                    WHERE 1=1'
                . orange_sql_country_and_fragment($pdo, 'orders', 'o', $countryId)
                . " AND o.status = 'completed'
                  AND " . $dateSql . ' BETWEEN ? AND ?';
            $params = [$from, $to];
            if ($source === 'company' && $hasOrdSource) {
                $sql .= " AND COALESCE(o.order_source,'website') = 'company'";
            } elseif ($source === 'online' && $hasOrdSource) {
                $sql .= " AND COALESCE(o.order_source,'website') <> 'company'";
            }
            if ($customerId > 0 && $hasOrdCustomerId) {
                $sql .= ' AND o.customer_id = ?';
                $params[] = $customerId;
            }
            if ($productId > 0 && $hasOrderItems) {
                $sql .= ' AND EXISTS (SELECT 1 FROM order_items oi2 WHERE oi2.order_id = o.id AND oi2.product_id = ?)';
                $params[] = $productId;
            }
            $sql .= ' GROUP BY YEAR(' . $dateSql . '), MONTH(' . $dateSql . ')';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = sprintf('%04d-%02d', (int) ($r['yy'] ?? 0), (int) ($r['mm'] ?? 0));
                if (!isset($monthMap[$k])) {
                    $monthMap[$k] = ['yy' => (int) ($r['yy'] ?? 0), 'mm' => (int) ($r['mm'] ?? 0), 'sales_count' => 0, 'sales_total' => 0.0, 'return_count' => 0, 'return_total' => 0.0];
                }
                $monthMap[$k]['sales_count'] += (int) ($r['cnt'] ?? 0);
                $monthMap[$k]['sales_total'] += (float) ($r['total_sum'] ?? 0);
            }
        }

        if ($hasSalesReturns) {
            $dateSql = $returnDateExpr('sr');
            $sql = 'SELECT YEAR(' . $dateSql . ') AS yy, MONTH(' . $dateSql . ') AS mm,
                           COUNT(*) AS cnt, COALESCE(SUM(COALESCE(sr.total,0)),0) AS total_sum
                    FROM sales_returns sr
                    WHERE 1=1'
                . orange_sql_country_and_fragment($pdo, 'sales_returns', 'sr', $countryId)
                . ' AND ' . $dateSql . ' BETWEEN ? AND ?';
            $params = [$from, $to];
            if ($source !== 'all' && $hasSrSourceKind) {
                $sql .= ' AND sr.source_kind = ?';
                $params[] = $source;
            }
            if ($customerId > 0 && $hasSrCustomerId) {
                $sql .= ' AND sr.customer_id = ?';
                $params[] = $customerId;
            }
            if ($productId > 0 && $hasSalesReturnItems) {
                $sql .= ' AND EXISTS (SELECT 1 FROM sales_return_items sri2 WHERE sri2.sales_return_id = sr.id AND sri2.product_id = ?)';
                $params[] = $productId;
            }
            $sql .= ' GROUP BY YEAR(' . $dateSql . '), MONTH(' . $dateSql . ')';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = sprintf('%04d-%02d', (int) ($r['yy'] ?? 0), (int) ($r['mm'] ?? 0));
                if (!isset($monthMap[$k])) {
                    $monthMap[$k] = ['yy' => (int) ($r['yy'] ?? 0), 'mm' => (int) ($r['mm'] ?? 0), 'sales_count' => 0, 'sales_total' => 0.0, 'return_count' => 0, 'return_total' => 0.0];
                }
                $monthMap[$k]['return_count'] += (int) ($r['cnt'] ?? 0);
                $monthMap[$k]['return_total'] += (float) ($r['total_sum'] ?? 0);
            }
        }

        foreach ($monthMap as $m) {
            $net = (float) $m['sales_total'] - (float) $m['return_total'];
            $rows[] = [
                'month_label' => sprintf('%02d/%04d', (int) $m['mm'], (int) $m['yy']),
                'sales_count' => (int) $m['sales_count'],
                'sales_total' => (float) $m['sales_total'],
                'return_count' => (int) $m['return_count'],
                'return_total' => (float) $m['return_total'],
                'net_total' => $net,
                '_sort' => sprintf('%04d%02d', (int) $m['yy'], (int) $m['mm']),
            ];
            $monthlySummary['sales_count'] += (int) $m['sales_count'];
            $monthlySummary['sales_total'] += (float) $m['sales_total'];
            $monthlySummary['return_count'] += (int) $m['return_count'];
            $monthlySummary['return_total'] += (float) $m['return_total'];
            $monthlySummary['net_total'] += $net;
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['_sort'] ?? ''), (string) ($a['_sort'] ?? '')));
    }
} catch (Throwable $e) {
    $reportError = $e->getMessage();
    if (function_exists('error_log')) {
        error_log('[orange sales_reports ' . $tab . '] ' . $e->getMessage());
    }
}

$company = orange_sales_doc_print_company($pdo, $countryId);
$companyName = (string) ($company['company_name_ar'] ?? '');
$companyLogo = (string) ($company['logo_url'] ?? '');
$companyCr = (string) ($company['commercial_register'] ?? '');
$printDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
$reportTitle = $tabs[$tab];

$subtitleParts = ['من ' . orange_format_date_dmY($from) . ' إلى ' . orange_format_date_dmY($to)];
if ($source === 'company') {
    $subtitleParts[] = 'المصدر: شركة';
} elseif ($source === 'online') {
    $subtitleParts[] = 'المصدر: أونلاين';
}
if ($customerId > 0 && isset($customerMap[$customerId])) {
    $subtitleParts[] = 'العميل: ' . $customerMap[$customerId]['name'];
}
if ($productId > 0 && isset($productMap[$productId])) {
    $subtitleParts[] = 'الصنف: ' . $productMap[$productId]['name'];
}
$filterSubtitle = implode(' — ', $subtitleParts);
?>
<div class="page-title gl-acc-stmt-no-print">
    <h1>تقارير المبيعات</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<div class="card gl-acc-stmt-no-print">
    <div class="srr-tabs">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="srr-tab<?php echo $key === $tab ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=sales_reports&r=' . rawurlencode($key)), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="srr-filter-form" style="margin-top:12px;display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
        <input type="hidden" name="page" value="sales_reports">
        <input type="hidden" name="r" value="<?php echo htmlspecialchars($tab, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="srr-main-row">
            <div class="srr-date-field">
                <label for="srr_from">من تاريخ</label>
                <input type="text" id="srr_from" name="from" class="admin-inp orange-inp-dmy" lang="en" dir="ltr" value="<?php echo htmlspecialchars($fromDisplay, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="srr-date-field srr-date-field-to">
                <label for="srr_to">إلى تاريخ</label>
                <input type="text" id="srr_to" name="to" class="admin-inp orange-inp-dmy" lang="en" dir="ltr" value="<?php echo htmlspecialchars($toDisplay, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="srr-customer-field-wrap">
                <label for="srr_customer_name">العميل (دبل كليك للاختيار)</label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="hidden" id="srr_customer_id" name="customer_id" value="<?php echo (int) $customerId; ?>">
                    <input type="text" id="srr_customer_code" class="admin-inp" readonly
                        title="يُملأ تلقائياً عند اختيار العميل"
                        style="width:9rem;background:#f4f4f5;" dir="ltr" lang="en"
                        placeholder="الكود"
                        value="<?php echo htmlspecialchars($selectedCustomerCode, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="text" id="srr_customer_name" class="admin-inp srr-name-input srr-customer-name" readonly
                        title="دبل كليك لاختيار العميل"
                        style="cursor:pointer;min-width:15rem;"
                        placeholder="كل العملاء — دبل كليك للاختيار"
                        value="<?php echo htmlspecialchars($selectedCustomerName, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
            <?php if ($tab === 'items'): ?>
                <div class="srr-product-field-wrap">
                    <label for="srr_product_name">الصنف (دبل كليك للاختيار)</label>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <input type="hidden" id="srr_product_id" name="product_id" value="<?php echo (int) $productId; ?>">
                        <input type="text" id="srr_product_code" class="admin-inp" readonly
                            title="يُملأ تلقائياً عند اختيار الصنف"
                            style="width:10rem;background:#f4f4f5;" dir="ltr" lang="en"
                            placeholder="الكود"
                            value="<?php echo htmlspecialchars($selectedProductCode, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="text" id="srr_product_name" class="admin-inp srr-name-input srr-product-name" readonly
                            title="دبل كليك لاختيار الصنف"
                            style="cursor:pointer;min-width:18rem;"
                            placeholder="كل الأصناف — دبل كليك للاختيار"
                            value="<?php echo htmlspecialchars($selectedProductName, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <div class="srr-options-row">
            <div class="srr-source-wrap">
                <label for="srr_src">مصدر الفاتورة</label>
                <select id="srr_src" name="src" class="admin-inp">
                    <option value="all"<?php echo $source === 'all' ? ' selected' : ''; ?>>الكل</option>
                    <option value="company"<?php echo $source === 'company' ? ' selected' : ''; ?>>شركة</option>
                    <option value="online"<?php echo $source === 'online' ? ' selected' : ''; ?>>أونلاين</option>
                </select>
            </div>
            <?php if ($tab === 'items'): ?>
                <div class="srr-items-zero-wrap">
                    <label class="srr-items-zero-label" style="display:flex;align-items:center;gap:6px;cursor:pointer;white-space:nowrap;font-weight:600;">
                        <input type="checkbox" name="hz" value="1" <?php echo $hideZero ? 'checked' : ''; ?>>
                        إخفاء الصافي صفر
                    </label>
                </div>
            <?php endif; ?>
            <?php if ($tab !== 'items'): ?>
                <span class="srr-options-placeholder" aria-hidden="true">&nbsp;</span>
            <?php endif; ?>
        </div>
        <div class="srr-print-actions">
            <button type="submit">عرض</button>
            <button type="button" class="btn-secondary" data-orange-perm="print" onclick="window.print()">طباعة</button>
        </div>
    </form>
</div>

<div class="card gl-acc-stmt-print">
    <div class="gl-acc-stmt-print-sheet ta-report-print-sheet bs-report-sheet">
        <header class="gl-acc-stmt-print-banner bs-report-banner">
            <div class="bs-report-brand">
                <?php if ($companyLogo !== ''): ?><img class="bs-report-logo" src="<?php echo htmlspecialchars($companyLogo, ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php endif; ?>
                <div class="bs-report-brand-text">
                    <?php if ($companyName !== ''): ?><p class="bs-report-company"><?php echo htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                    <?php if ($companyCr !== ''): ?><p class="bs-report-cr">سجل تجاري: <span dir="ltr"><?php echo htmlspecialchars($companyCr, ENT_QUOTES, 'UTF-8'); ?></span></p><?php endif; ?>
                </div>
            </div>
            <h2 class="gl-acc-stmt-print-title bs-report-title">
                <span class="gl-acc-stmt-print-title-ar" lang="ar"><?php echo htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="bs-report-asof" lang="ar"><?php echo htmlspecialchars($filterSubtitle, ENT_QUOTES, 'UTF-8'); ?></span>
            </h2>
        </header>

        <?php if ($reportError !== ''): ?>
            <p class="card-hint" style="color:#b91c1c;">تعذّر توليد التقرير: <?php echo htmlspecialchars($reportError, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap">
            <table class="admin-fy-table gl-acc-stmt-table ta-report-table" data-export-name="<?php echo htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8'); ?>" data-export-target=".srr-print-actions" data-export-company="<?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($tab === 'invoices'): ?>
                    <thead><tr><th>المرجع</th><th>التاريخ</th><th>العميل</th><th>المصدر</th><th>التحصيل</th><th class="gl-acc-stmt-col-num">إجمالي قبل الخصم</th><th class="gl-acc-stmt-col-num">الخصم</th><th class="gl-acc-stmt-col-num">الصافي</th></tr></thead>
                    <tbody>
                    <?php if ($rows === []): ?><tr><td colspan="8" class="muted">لا توجد فواتير مبيعات في المدى المحدد.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr>
                            <td dir="ltr"><?php echo htmlspecialchars((string) $r['reference'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars(orange_format_date_dmY((string) $r['date']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['customer'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($sourceLabel((string) $r['source']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($paymentLabel((string) $r['payment']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['subtotal']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['discount']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['net']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot><tr><th colspan="5">الإجمالي (<?php echo (int) $invoiceSummary['count']; ?>)</th><th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $invoiceSummary['subtotal']); ?></th><th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $invoiceSummary['discount']); ?></th><th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $invoiceSummary['net']); ?></th></tr></tfoot>
                <?php elseif ($tab === 'returns'): ?>
                    <thead><tr><th>مرجع المردود</th><th>التاريخ</th><th>العميل</th><th>مرجع الفاتورة</th><th>المصدر</th><th>التحصيل</th><th class="gl-acc-stmt-col-num">الصافي</th></tr></thead>
                    <tbody>
                    <?php if ($rows === []): ?><tr><td colspan="7" class="muted">لا توجد مردودات مبيعات في المدى المحدد.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr>
                            <td dir="ltr"><?php echo htmlspecialchars((string) $r['reference'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars(orange_format_date_dmY((string) $r['date']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['customer'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars((string) $r['invoice_reference'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($sourceLabel((string) $r['source']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(orange_sales_return_payment_type_label((string) $r['payment']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['total']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot><tr><th colspan="6">الإجمالي (<?php echo (int) $returnSummary['count']; ?>)</th><th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $returnSummary['total']); ?></th></tr></tfoot>
                <?php elseif ($tab === 'customers'): ?>
                    <thead><tr><th>العميل</th><th class="gl-acc-stmt-col-num">عدد فواتير المبيعات</th><th class="gl-acc-stmt-col-num">صافي المبيعات</th><th class="gl-acc-stmt-col-num">عدد المردودات</th><th class="gl-acc-stmt-col-num">صافي المردودات</th><th class="gl-acc-stmt-col-num">الصافي بعد المردود</th></tr></thead>
                    <tbody>
                    <?php if ($rows === []): ?><tr><td colspan="6" class="muted">لا توجد بيانات عملاء في المدى المحدد.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) $r['customer'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo (int) $r['sales_count']; ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['sales_total']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo (int) $r['return_count']; ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['return_total']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['net']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot><tr><th>الإجمالي</th><th class="gl-acc-stmt-col-num"><?php echo (int) $customerSummary['sales_count']; ?></th><th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $customerSummary['sales_total']); ?></th><th class="gl-acc-stmt-col-num"><?php echo (int) $customerSummary['return_count']; ?></th><th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $customerSummary['return_total']); ?></th><th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $customerSummary['net']); ?></th></tr></tfoot>
                <?php elseif ($tab === 'items'): ?>
                    <thead><tr><th>الكود</th><th>الصنف</th><th>اللون / المقاس</th><th class="gl-acc-stmt-col-num">كمية مبيعات</th><th class="gl-acc-stmt-col-num">قيمة مبيعات</th><th class="gl-acc-stmt-col-num">كمية مردود</th><th class="gl-acc-stmt-col-num">قيمة مردود</th><th class="gl-acc-stmt-col-num">صافي الكمية</th><th class="gl-acc-stmt-col-num">صافي القيمة</th></tr></thead>
                    <tbody>
                    <?php if ($rows === []): ?><tr><td colspan="9" class="muted">لا توجد حركة أصناف في المدى المحدد.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr>
                            <td dir="ltr"><?php echo htmlspecialchars((string) $r['item_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['variant'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtQty((float) $r['sales_qty']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['sales_value']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtQty((float) $r['return_qty']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['return_value']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtQty((float) $r['net_qty']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['net_value']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot><tr><th colspan="3">الإجمالي</th><th class="gl-acc-stmt-col-num"><?php echo $fmtQty((float) $itemSummary['sales_qty']); ?></th><th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $itemSummary['sales_value']); ?></th><th class="gl-acc-stmt-col-num"><?php echo $fmtQty((float) $itemSummary['return_qty']); ?></th><th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $itemSummary['return_value']); ?></th><th class="gl-acc-stmt-col-num"><?php echo $fmtQty((float) $itemSummary['net_qty']); ?></th><th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $itemSummary['net_value']); ?></th></tr></tfoot>
                <?php elseif ($tab === 'monthly'): ?>
                    <thead><tr><th>الشهر</th><th class="gl-acc-stmt-col-num">عدد فواتير المبيعات</th><th class="gl-acc-stmt-col-num">صافي المبيعات</th><th class="gl-acc-stmt-col-num">عدد المردودات</th><th class="gl-acc-stmt-col-num">صافي المردودات</th><th class="gl-acc-stmt-col-num">الصافي</th></tr></thead>
                    <tbody>
                    <?php if ($rows === []): ?><tr><td colspan="6" class="muted">لا توجد بيانات شهرية في المدى المحدد.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr>
                            <td dir="ltr"><?php echo htmlspecialchars((string) $r['month_label'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo (int) $r['sales_count']; ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['sales_total']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo (int) $r['return_count']; ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['return_total']); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $r['net_total']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot><tr><th>الإجمالي</th><th class="gl-acc-stmt-col-num"><?php echo (int) $monthlySummary['sales_count']; ?></th><th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $monthlySummary['sales_total']); ?></th><th class="gl-acc-stmt-col-num"><?php echo (int) $monthlySummary['return_count']; ?></th><th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $monthlySummary['return_total']); ?></th><th class="gl-acc-stmt-col-num"><?php echo $fmtMoney((float) $monthlySummary['net_total']); ?></th></tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
        <?php echo orange_accounting_report_print_metafoot_markup($printDatetime); ?>
    </div>
</div>

<div class="gl-pick-modal" id="srr_customer_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="srr_customer_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="srr_customer_pick_title">
        <h3 id="srr_customer_pick_title" class="gl-pick-modal__title">اختيار العميل</h3>
        <p class="muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
        <input type="search" id="srr_customer_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو اسم العميل..." autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="srr_customer_pick_list"></ul>
        <button type="button" class="btn-secondary" id="srr_customer_pick_close">إغلاق</button>
    </div>
</div>

<div class="gl-pick-modal" id="srr_product_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="srr_product_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="srr_product_pick_title">
        <h3 id="srr_product_pick_title" class="gl-pick-modal__title">اختيار الصنف</h3>
        <p class="muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
        <input type="search" id="srr_product_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو اسم الصنف..." autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="srr_product_pick_list"></ul>
        <button type="button" class="btn-secondary" id="srr_product_pick_close">إغلاق</button>
    </div>
</div>

<style>
.srr-tabs { display:flex; flex-wrap:wrap; gap:8px; }
.srr-tab {
    padding:7px 14px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    background:#f8fafc;
    color:#334155;
    text-decoration:none;
    font-size:0.92rem;
}
.srr-tab.is-active { background:#0f172a; color:#fff; border-color:#0f172a; }
.srr-filter-form .srr-main-row {
    flex-basis: 100%;
    display: flex;
    flex-wrap: nowrap;
    gap: 6px;
    align-items: flex-end;
    overflow-x: auto;
    min-width: 0;
    padding-bottom: 2px;
}
.srr-filter-form .srr-date-field {
    flex: 0 0 auto;
}
.srr-filter-form .srr-date-field-to {
    margin-inline-start: 0.2rem;
}
.srr-filter-form #srr_from,
.srr-filter-form #srr_to {
    width: 6.6rem;
    min-width: 6.6rem;
}
.srr-filter-form .srr-main-row .srr-customer-field-wrap,
.srr-filter-form .srr-main-row .srr-product-field-wrap {
    flex: 1 1 24rem;
    min-width: 20rem;
}
.srr-filter-form .srr-customer-name {
    min-width: 13rem !important;
}
.srr-filter-form .srr-product-name {
    min-width: 14rem !important;
}
.srr-filter-form .srr-options-row {
    flex-basis: 100%;
    display: flex;
    flex-wrap: nowrap;
    gap: 10px;
    align-items: center;
    min-height: 2.1rem;
    overflow-x: auto;
}
.srr-filter-form .srr-source-wrap {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 11rem;
}
.srr-filter-form .srr-items-zero-wrap {
    display: flex;
    align-items: center;
}
.srr-filter-form .srr-options-placeholder {
    display: inline-block;
    width: 1px;
    height: 1.6rem;
    opacity: 0;
}
.srr-filter-form .srr-print-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-basis: 100%;
    justify-content: flex-end;
}
</style>

<script>
(function () {
    var customerRows = <?php echo json_encode($customerPickerRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?> || [];
    var productRows = <?php echo json_encode($productPickerRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?> || [];

    var customerIdEl = document.getElementById('srr_customer_id');
    var customerCodeEl = document.getElementById('srr_customer_code');
    var customerNameEl = document.getElementById('srr_customer_name');
    var customerModal = document.getElementById('srr_customer_pick_modal');
    var customerBackdrop = document.getElementById('srr_customer_pick_backdrop');
    var customerCloseBtn = document.getElementById('srr_customer_pick_close');
    var customerSearchEl = document.getElementById('srr_customer_pick_q');
    var customerListEl = document.getElementById('srr_customer_pick_list');

    var productIdEl = document.getElementById('srr_product_id');
    var productCodeEl = document.getElementById('srr_product_code');
    var productNameEl = document.getElementById('srr_product_name');
    var productModal = document.getElementById('srr_product_pick_modal');
    var productBackdrop = document.getElementById('srr_product_pick_backdrop');
    var productCloseBtn = document.getElementById('srr_product_pick_close');
    var productSearchEl = document.getElementById('srr_product_pick_q');
    var productListEl = document.getElementById('srr_product_pick_list');

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function syncBodyLock() {
        var anyOpen = (customerModal && !customerModal.hidden) || (productModal && !productModal.hidden);
        document.body.classList.toggle('gl-pick-open', anyOpen);
    }

    function customerSet(row) {
        if (!customerIdEl || !customerCodeEl || !customerNameEl) {
            return;
        }
        if (!row) {
            customerIdEl.value = '0';
            customerCodeEl.value = '';
            customerNameEl.value = '';
            return;
        }
        customerIdEl.value = String(parseInt(String(row.id || '0'), 10) || 0);
        customerCodeEl.value = String(row.code || '');
        customerNameEl.value = String(row.name || '');
    }

    function customerRender(query) {
        if (!customerListEl) {
            return;
        }
        var q = String(query || '').trim().toLowerCase();
        var filtered = customerRows.filter(function (r) {
            if (!q) {
                return true;
            }
            var hay = (String(r.code || '') + ' ' + String(r.name || '')).toLowerCase();
            return hay.indexOf(q) !== -1;
        });
        customerListEl.innerHTML = '';
        if (!filtered.length) {
            customerListEl.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>';
            return;
        }
        filtered.forEach(function (r) {
            var li = document.createElement('li');
            li.className = 'gl-pick-item';
            li.setAttribute('role', 'button');
            li.tabIndex = 0;
            li.innerHTML = '<span dir="ltr">' + esc(r.code || '') + '</span> — ' + esc(r.name || '');
            li.addEventListener('dblclick', function () {
                customerSet(r);
                customerClose();
            });
            li.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    customerSet(r);
                    customerClose();
                }
            });
            customerListEl.appendChild(li);
        });
    }

    function customerOpen() {
        if (!customerModal) {
            return;
        }
        customerModal.hidden = false;
        customerModal.setAttribute('aria-hidden', 'false');
        syncBodyLock();
        if (customerSearchEl) {
            customerSearchEl.value = '';
            customerRender('');
            customerSearchEl.focus();
        } else {
            customerRender('');
        }
    }

    function customerClose() {
        if (!customerModal) {
            return;
        }
        customerModal.hidden = true;
        customerModal.setAttribute('aria-hidden', 'true');
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

    if (customerNameEl) {
        customerNameEl.addEventListener('dblclick', customerOpen);
        customerNameEl.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                customerOpen();
            }
        });
    }
    if (customerBackdrop) {
        customerBackdrop.addEventListener('click', customerClose);
    }
    if (customerCloseBtn) {
        customerCloseBtn.addEventListener('click', customerClose);
    }
    if (customerSearchEl) {
        customerSearchEl.addEventListener('input', function () {
            customerRender(customerSearchEl.value || '');
        });
    }

    if (productNameEl) {
        productNameEl.addEventListener('dblclick', productOpen);
        productNameEl.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                productOpen();
            }
        });
    }
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

    window.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') {
            return;
        }
        if (customerModal && !customerModal.hidden) {
            customerClose();
        }
        if (productModal && !productModal.hidden) {
            productClose();
        }
    });
})();
</script>

<?php $docTitle = $reportTitle . ' - ' . date('Y-m-d'); ?>
<script>
(function () {
    var reportTitle = <?php echo json_encode($docTitle, JSON_UNESCAPED_UNICODE); ?>;
    var originalTitle = document.title;
    window.orangeAdminVoucherPrintTitle = reportTitle;
    window.addEventListener('afterprint', function () { document.title = originalTitle; });
})();
</script>
