<?php

declare(strict_types=1);

/**
 * GAP-SALE-DOC-01 — فاتورة مبيعات شركة (INV-C): مساعدات API مشتركة.
 *
 * @see docs/archive/ORANGE_SALES_INVOICE_DOC_UI_HANDOFF.txt
 */

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/document_sequences.php';
require_once __DIR__ . '/invoice_ancillary_lines.php';
require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/party_subledger.php';
require_once __DIR__ . '/date_format.php';
require_once __DIR__ . '/admin_permissions.php';

/**
 * @return array{sql:string,params:list<mixed>}|null
 */
function orange_sales_invoice_company_country_filter(PDO $pdo, int $countryId, string $alias = 'o'): ?array
{
    if ($countryId <= 0) {
        return null;
    }

    return orange_sql_filter_country_id($pdo, 'orders', $alias, $countryId);
}

/**
 * شروط SQL لطلبات فاتورة الشركة (INV-C) — idempotent مع sales_invoices.php.
 */
function orange_sales_invoice_company_scope_sql(PDO $pdo, string $alias = 'o'): string
{
    $sql = '';
    if (orange_table_has_column($pdo, 'orders', 'order_source')) {
        $sql .= " AND {$alias}.order_source = 'company'";
    }
    if (orange_table_has_column($pdo, 'orders', 'invoice_number')) {
        $sql .= " AND {$alias}.invoice_number IS NOT NULL AND {$alias}.invoice_number <> ''"
            . " AND {$alias}.invoice_number LIKE 'INV-C-%'";
    }
    if (orange_table_has_column($pdo, 'orders', 'status')) {
        $sql .= " AND {$alias}.status = 'completed'";
    }

    return $sql;
}

/**
 * @throws RuntimeException
 * @return array<string, mixed>
 */
function orange_sales_invoice_company_load_order(PDO $pdo, int $orderId): array
{
    if ($orderId <= 0) {
        throw new RuntimeException('معرف الفاتورة مطلوب');
    }

    $st = $pdo->prepare(
        'SELECT o.*, c.name AS channel_name
         FROM orders o
         LEFT JOIN channels c ON c.id = o.channel_id
         WHERE o.id = ?
         LIMIT 1'
    );
    $st->execute([$orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('الفاتورة غير موجودة', 404);
    }

    orange_admin_assert_entity_country($pdo, 'orders', $orderId);

    if (orange_table_has_column($pdo, 'orders', 'order_source')
        && trim((string) ($order['order_source'] ?? '')) !== 'company') {
        throw new RuntimeException('هذا الطلب ليس فاتورة شركة (INV-C)', 422);
    }

    $inv = orange_table_has_column($pdo, 'orders', 'invoice_number')
        ? trim((string) ($order['invoice_number'] ?? ''))
        : '';
    if ($inv !== '' && !str_starts_with($inv, 'INV-C-')) {
        throw new RuntimeException('رقم الفاتورة ليس من نوع INV-C', 422);
    }

    return $order;
}

function orange_sales_invoice_company_reference(PDO $pdo, int $orderId, array $order): string
{
    $inv = trim((string) ($order['invoice_number'] ?? ''));
    if ($inv !== '') {
        return $inv;
    }
    $countryId = (int) ($order['country_id'] ?? 0);
    if ($countryId <= 0) {
        $countryId = orange_admin_context_country_id($pdo);
    }

    return orange_country_document_ref($pdo, 'INV-C', $orderId, max(0, $countryId));
}

/**
 * @return list<array<string, mixed>>
 */
function orange_sales_invoice_company_load_items(PDO $pdo, int $orderId): array
{
    $hasVariant = orange_table_has_column($pdo, 'order_items', 'variant_id');
    $hasLineDiscount = orange_table_has_column($pdo, 'order_items', 'line_discount');
    $hasProductId = orange_table_has_column($pdo, 'order_items', 'product_id');

    $sql = 'SELECT oi.*';
    if ($hasProductId) {
        $sql .= ', p.name AS catalog_product_name, p.is_active AS product_is_active, p.price AS catalog_price, p.cost AS catalog_cost';
        $sql .= ', p.has_colors, p.has_sizes';
        if (orange_table_has_column($pdo, 'products', 'item_code')) {
            $sql .= ', p.item_code';
        }
        if (orange_table_has_column($pdo, 'products', 'barcode')) {
            $sql .= ', p.barcode';
        }
    }
    if ($hasVariant) {
        $sql .= ', pv.color AS v_color, pv.size AS v_size';
    }
    $sql .= ' FROM order_items oi';
    if ($hasProductId) {
        $sql .= ' LEFT JOIN products p ON p.id = oi.product_id';
    }
    if ($hasVariant) {
        $sql .= ' LEFT JOIN product_variants pv ON pv.id = oi.variant_id';
    }
    $sql .= ' WHERE oi.order_id = ? ORDER BY oi.id ASC';

    $st = $pdo->prepare($sql);
    $st->execute([$orderId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $row) {
        $pid = $hasProductId ? (int) ($row['product_id'] ?? 0) : 0;
        $vid = $hasVariant ? (int) ($row['variant_id'] ?? 0) : 0;
        $productName = (string) ($row['product_name'] ?? '');
        if ($productName === '' && $hasProductId) {
            $productName = (string) ($row['catalog_product_name'] ?? '');
        }
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'product_id' => $pid,
            'variant_id' => $vid,
            'product_name' => $productName,
            'color' => (string) ($row['color'] ?? ($row['v_color'] ?? '')),
            'size' => (string) ($row['size'] ?? ($row['v_size'] ?? '')),
            'qty' => (int) ($row['qty'] ?? 0),
            'price' => (float) ($row['price'] ?? 0),
            'cost' => (float) ($row['cost'] ?? 0),
            'line_discount' => $hasLineDiscount ? (float) ($row['line_discount'] ?? 0) : 0.0,
            'line_net' => orange_order_item_line_net($row),
            'is_product_active' => !$hasProductId || (int) ($row['product_is_active'] ?? 1) === 1,
            'item_code' => $hasProductId && isset($row['item_code']) ? (string) $row['item_code'] : '',
            'barcode' => $hasProductId && isset($row['barcode']) ? (string) $row['barcode'] : '',
        ];
    }

    return $out;
}

/**
 * @return array{order:array<string,mixed>,items:list<array<string,mixed>>,extra_lines:list<array<string,mixed>>,customer:array<string,mixed>|null}
 */
function orange_sales_invoice_company_document_payload(PDO $pdo, int $orderId): array
{
    $order = orange_sales_invoice_company_load_order($pdo, $orderId);
    $countryId = (int) ($order['country_id'] ?? 0);
    if ($countryId <= 0) {
        $countryId = orange_admin_context_country_id($pdo);
    }

    $items = orange_sales_invoice_company_load_items($pdo, $orderId);
    $extraLines = orange_invoice_ancillary_extra_lines_for_doc(
        $pdo,
        orange_invoice_ancillary_doc_kind_sales(),
        $orderId
    );
    $extraOut = [];
    foreach ($extraLines as $row) {
        $extraOut[] = [
            'id' => (int) ($row['id'] ?? 0),
            'account_id' => (int) ($row['account_id'] ?? 0),
            'account_code' => (string) ($row['account_code'] ?? ''),
            'account_name' => (string) ($row['account_name'] ?? ''),
            'line_kind' => (string) ($row['line_kind'] ?? ''),
            'amount' => (float) ($row['amount'] ?? 0),
            'label_ar' => (string) ($row['label_ar'] ?? ''),
            'show_on_print' => (int) ($row['show_on_print'] ?? 0) === 1,
            'preset_id' => isset($row['preset_id']) && (int) $row['preset_id'] > 0
                ? (int) $row['preset_id']
                : 0,
        ];
    }

    $customerId = orange_table_has_column($pdo, 'orders', 'customer_id')
        ? (int) ($order['customer_id'] ?? 0)
        : 0;
    $customerOut = null;
    if ($customerId > 0 && orange_table_exists($pdo, 'customers')) {
        $codeCol = orange_table_has_column($pdo, 'customers', 'code') ? ', code' : '';
        $cSt = $pdo->prepare('SELECT id, name_ar, phone' . $codeCol . ' FROM customers WHERE id = ? LIMIT 1');
        $cSt->execute([$customerId]);
        $cRow = $cSt->fetch(PDO::FETCH_ASSOC);
        if ($cRow) {
            $customerOut = [
                'id' => (int) $cRow['id'],
                'code' => orange_table_has_column($pdo, 'customers', 'code')
                    ? trim((string) ($cRow['code'] ?? ''))
                    : '',
                'name_ar' => (string) ($cRow['name_ar'] ?? ''),
                'phone' => (string) ($cRow['phone'] ?? ''),
                'current_balance' => round((float) orange_party_balance_customer($pdo, $customerId), 3),
            ];
        }
    }

    $subtotal = 0.0;
    foreach ($items as $it) {
        $subtotal = round($subtotal + (float) ($it['line_net'] ?? 0), 4);
    }

    $orderOut = [
        'id' => $orderId,
        'order_number' => (string) ($order['order_number'] ?? ''),
        'reference' => orange_sales_invoice_company_reference($pdo, $orderId, $order),
        'invoice_number' => trim((string) ($order['invoice_number'] ?? '')),
        'customer_id' => $customerId,
        'customer_name' => (string) ($order['customer_name'] ?? ''),
        'phone' => (string) ($order['phone'] ?? ''),
        'phone_country_dial' => orange_table_has_column($pdo, 'orders', 'phone_country_dial')
            ? trim((string) ($order['phone_country_dial'] ?? ''))
            : '',
        'phone_national' => orange_table_has_column($pdo, 'orders', 'phone_national')
            ? trim((string) ($order['phone_national'] ?? ''))
            : '',
        'area' => (string) ($order['area'] ?? ''),
        'address' => (string) ($order['address'] ?? ''),
        'notes' => (string) ($order['notes'] ?? ''),
        'channel_id' => (int) ($order['channel_id'] ?? 0),
        'channel_name' => (string) ($order['channel_name'] ?? ''),
        'payment_terms' => orange_table_has_column($pdo, 'orders', 'payment_terms')
            ? trim((string) ($order['payment_terms'] ?? 'cash'))
            : 'cash',
        'amount_paid' => orange_table_has_column($pdo, 'orders', 'amount_paid')
            ? (float) ($order['amount_paid'] ?? 0)
            : 0.0,
        'status' => (string) ($order['status'] ?? ''),
        'total' => (float) ($order['total'] ?? 0),
        'subtotal' => $subtotal,
        'created_at' => (string) ($order['created_at'] ?? ''),
        'created_at_dmy' => !empty($order['created_at'])
            ? orange_format_date_dmY((string) $order['created_at'])
            : '',
        'country_id' => $countryId,
    ];

    return [
        'order' => $orderOut,
        'items' => $items,
        'extra_lines' => $extraOut,
        'customer' => $customerOut,
    ];
}
