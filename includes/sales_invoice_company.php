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
require_once __DIR__ . '/warehouses.php';
require_once __DIR__ . '/sales_doc_channel.php';

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

    $order['channel_name'] = orange_sales_order_channel_label(
        isset($order['channel_id']) ? (int) $order['channel_id'] : 0,
        (string) ($order['channel_name'] ?? '')
    );

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

    return orange_sales_invoice_ref_preview($pdo, 'company', $countryId);
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
        $custCols = 'id, name_ar, phone';
        if (orange_table_has_column($pdo, 'customers', 'code')) {
            $custCols .= ', code';
        }
        if (orange_table_has_column($pdo, 'customers', 'area')) {
            $custCols .= ', area';
        }
        if (orange_table_has_column($pdo, 'customers', 'address')) {
            $custCols .= ', address';
        }
        $cSt = $pdo->prepare('SELECT ' . $custCols . ' FROM customers WHERE id = ? LIMIT 1');
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
                'area' => orange_table_has_column($pdo, 'customers', 'area')
                    ? trim((string) ($cRow['area'] ?? ''))
                    : '',
                'address' => orange_table_has_column($pdo, 'customers', 'address')
                    ? trim((string) ($cRow['address'] ?? ''))
                    : '',
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
        'document_date' => orange_table_has_column($pdo, 'orders', 'document_date')
            ? (string) ($order['document_date'] ?? '')
            : '',
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

/**
 * @param list<array<string,mixed>> $itemsIn
 * @return array{total:float,items:list<array<string,mixed>>}
 */
function orange_sales_invoice_company_validate_items(PDO $pdo, array $itemsIn, int $countryId): array
{
    require_once __DIR__ . '/catalog_unified_product_helpers.php';

    if ($itemsIn === []) {
        throw new RuntimeException('أضف سطرًا واحدًا على الأقل من المنتجات المسجّرة');
    }

    $total = 0.0;
    $validated = [];

    foreach ($itemsIn as $item) {
        if (!is_array($item)) {
            continue;
        }
        $lineDiscount = (float) ($item['line_discount'] ?? 0);
        $pid = isset($item['product_id']) ? (int) $item['product_id'] : 0;
        if ($pid <= 0) {
            throw new RuntimeException('يُقبل فقط بيع منتجات مسجّلة في «المنتجات» — لا بند نصي أو سعر يدوي بدون صنف');
        }
        try {
            orange_admin_assert_entity_country($pdo, 'products', $pid);
        } catch (RuntimeException $e) {
            throw new RuntimeException($e->getMessage(), 403);
        }

        $qty = max(1, (int) ($item['qty'] ?? 0));
        $productStmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
        $productStmt->execute([$pid]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new RuntimeException('منتج غير موجود: ' . $pid);
        }
        if (!orange_storefront_product_in_active_unified_chain($pdo, $pid)) {
            throw new RuntimeException(
                'المنتج غير ضمن الكتالوج الموحّد النشط أو غير مرتبط بسلسلة التصنيف الصالحة: ' . $pid
            );
        }

        $variantIdIn = isset($item['variant_id']) ? (int) $item['variant_id'] : 0;
        $color = isset($item['color']) ? trim((string) $item['color']) : '';
        $size = isset($item['size']) ? trim((string) $item['size']) : '';
        $variant = null;

        if ((int) ($product['has_colors'] ?? 0) === 1 || (int) ($product['has_sizes'] ?? 0) === 1) {
            if ($variantIdIn > 0) {
                $vStmt = $pdo->prepare(
                    'SELECT * FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1'
                );
                $vStmt->execute([$variantIdIn, $pid]);
                $variant = $vStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$variant) {
                $variantStmt = $pdo->prepare(
                    'SELECT * FROM product_variants
                     WHERE product_id = ? AND color = ? AND size = ?
                     LIMIT 1'
                );
                $variantStmt->execute([$pid, $color, $size]);
                $variant = $variantStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$variant) {
                throw new RuntimeException('لم يُعثر على متغير للمنتج: ' . ($product['name'] ?? $pid));
            }
            $variantIdIn = (int) ($variant['id'] ?? 0);
            $available = orange_warehouse_effective_variant_stock($pdo, $variantIdIn, $countryId);
            if ($available < $qty) {
                throw new RuntimeException('مخزون غير كافٍ: ' . ($product['name'] ?? $pid));
            }
        }

        $price = (float) ($product['price'] ?? 0);
        $cost = (float) ($product['cost'] ?? 0);
        $lineNet = max(0.0, round($price * $qty - $lineDiscount, 4));
        $total += $lineNet;

        $validated[] = [
            'product' => $product,
            'qty' => $qty,
            'color' => $variant ? (string) ($variant['color'] ?? '') : $color,
            'size' => $variant ? (string) ($variant['size'] ?? '') : $size,
            'variant_id' => $variant ? (int) ($variant['id'] ?? 0) : 0,
            'price' => $price,
            'cost' => $cost,
            'line_discount' => $lineDiscount,
        ];
    }

    if ($validated === []) {
        throw new RuntimeException('أضف سطرًا واحدًا على الأقل من المنتجات المسجّلة');
    }

    return ['total' => round($total, 4), 'items' => $validated];
}

function orange_sales_invoice_company_restore_stock_for_edit(PDO $pdo, int $orderId, array $order): void
{
    require_once __DIR__ . '/order_fulfillment.php';

    $orderNumber = trim((string) ($order['order_number'] ?? ''));
    if ($orderNumber === '') {
        return;
    }
    if (!orange_order_has_active_delivered_stock($pdo, $orderNumber)
        && !orange_order_has_fulfilled_web_reserve($pdo, $orderNumber)) {
        return;
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stockCtx = orange_warehouse_context_for_order($pdo, $order);
    $stockRef = orange_order_stock_reference($orderNumber);

    foreach ($items as $item) {
        $variant = orange_order_resolve_variant_from_item($pdo, $item);
        $qty = (int) ($item['qty'] ?? 0);
        if (!$variant || $qty <= 0) {
            continue;
        }
        $stockChange = orange_warehouse_apply_variant_delta(
            $pdo,
            (int) ($stockCtx['warehouse_id'] ?? 0),
            (int) ($variant['id'] ?? 0),
            $qty,
            0
        );
        orange_stock_movement_insert($pdo, [
            'product_id' => (int) ($item['product_id'] ?? 0),
            'variant_id' => (int) ($variant['id'] ?? 0),
            'type' => 'order_return',
            'qty' => $qty,
            'old_stock' => $stockChange['old'],
            'new_stock' => $stockChange['new'],
            'reason' => 'تعديل فاتورة شركة — إرجاع مخزون',
            'reference' => $stockRef,
            'country_id' => (int) ($stockCtx['country_id'] ?? 0),
            'warehouse_id' => (int) ($stockCtx['warehouse_id'] ?? 0),
        ]);
    }

    if (orange_table_has_column($pdo, 'stock_movements', 'reference')) {
        $pdo->prepare(
            "UPDATE stock_movements SET type = 'delivered_order_void', reason = ?
             WHERE reference = ? AND type = 'delivered_order'"
        )->execute(['تعديل فاتورة شركة — عكس تسليم', $stockRef]);
        $pdo->prepare(
            "UPDATE stock_movements SET type = 'pending_order_void', reason = ?
             WHERE reference = ? AND type = 'pending_order_fulfilled'"
        )->execute(['تعديل فاتورة شركة — إبطال حجز', $stockRef]);
    }
}

function orange_sales_invoice_company_remove_forward_accounting(PDO $pdo, array $order, int $countryId): void
{
    require_once __DIR__ . '/gl_pending_movements.php';
    require_once __DIR__ . '/journal_voucher.php';

    $orderNumber = trim((string) ($order['order_number'] ?? ''));
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderNumber === '' || $orderId <= 0) {
        return;
    }

    orange_gl_pending_remove_forward_fulfillment($pdo, $orderNumber);
    orange_gl_pending_remove_by_reference($pdo, orange_gl_pending_source_key('order', $orderId));

    if (!orange_journal_vouchers_ready($pdo)) {
        return;
    }

    $patterns = [
        'ORDER-' . $orderNumber . '-S-%',
        'ORDER-' . $orderNumber . '-C-%',
    ];
    $countryArg = $countryId > 0 ? $countryId : null;
    foreach ($patterns as $pat) {
        foreach (orange_gl_voucher_select_references_like($pdo, $pat, $countryArg) as $ref) {
            orange_voucher_delete_by_reference($pdo, (string) $ref, $countryArg);
        }
    }

    // قيود تلقائية إضافية لا يغطّيها نمط -S-/-C-: مصروف التوصيل (لا حارس تكرار له → يتضاعف بدون
    // إزالة) وكسب نقاط الولاء — تُزال/تُعكَس هنا كي يُعاد بناؤها بالقيم الجديدة عند إعادة الترحيل.
    $vExp = orange_voucher_find_by_document($pdo, 'order', $orderId, 'order_delivery_expense', $countryArg, 'delivery-expense');
    if ($vExp !== null) {
        orange_voucher_delete_by_reference($pdo, (string) ($vExp['reference'] ?? ''), $countryArg);
    }
    orange_gl_pending_remove_by_reference($pdo, orange_gl_pending_source_key('order', $orderId, 'delivery-expense'));

    require_once __DIR__ . '/loyalty.php';
    orange_loyalty_reverse_earn_for_order($pdo, $orderId);
}

/**
 * @param list<array<string,mixed>> $validatedItems from orange_sales_invoice_company_validate_items
 */
function orange_sales_invoice_company_insert_items(PDO $pdo, int $orderId, array $validatedItems): void
{
    $colsStmt = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items'"
    );
    $oiCols = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $oiCols = is_array($oiCols) ? $oiCols : [];
    $hasVariantCol = in_array('variant_id', $oiCols, true);
    $hasLineDiscountCol = in_array('line_discount', $oiCols, true);

    $insertCols = ['order_id', 'product_id'];
    if ($hasVariantCol) {
        $insertCols[] = 'variant_id';
    }
    $insertCols = array_merge($insertCols, ['product_name', 'color', 'size', 'qty', 'price', 'cost']);
    if ($hasLineDiscountCol) {
        $insertCols[] = 'line_discount';
    }
    $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
    $itemStmt = $pdo->prepare(
        'INSERT INTO order_items (' . implode(',', $insertCols) . ') VALUES (' . $placeholders . ')'
    );

    foreach ($validatedItems as $row) {
        $product = $row['product'];
        $bind = [$orderId, (int) ($product['id'] ?? 0)];
        if ($hasVariantCol) {
            $bind[] = (int) ($row['variant_id'] ?? 0) ?: null;
        }
        $bind[] = (string) ($product['name'] ?? '');
        $bind[] = (string) ($row['color'] ?? '');
        $bind[] = (string) ($row['size'] ?? '');
        $bind[] = (int) ($row['qty'] ?? 0);
        $bind[] = (float) ($row['price'] ?? 0);
        $bind[] = (float) ($row['cost'] ?? 0);
        if ($hasLineDiscountCol) {
            $bind[] = (float) ($row['line_discount'] ?? 0);
        }
        $itemStmt->execute($bind);
    }
}

function orange_sales_invoice_company_repost_fulfillment(PDO $pdo, int $orderId): array
{
    require_once __DIR__ . '/order_fulfillment.php';
    require_once __DIR__ . '/document_sequences.php';

    orange_complete_order_fulfillment($pdo, $orderId);

    $ordSt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $ordSt->execute([$orderId]);
    $orderRow = $ordSt->fetch(PDO::FETCH_ASSOC) ?: [];
    orange_order_assign_inv_c_if_needed($pdo, $orderId, $orderRow);
    orange_post_order_delivery_accounting($pdo, $orderId);

    $ofCountryId = (int) ($orderRow['country_id'] ?? 0);
    if ($ofCountryId <= 0) {
        $ofCountryId = orange_admin_context_country_id($pdo);
    }
    $paymentTerms = trim((string) ($orderRow['payment_terms'] ?? 'cash'));
    $isCredit = $paymentTerms === 'credit';
    $isOnline = orange_order_delivery_sale_uses_online_revenue_account($pdo, $orderRow);
    $saleJtCode = $isOnline ? 'OSI' : ($isCredit ? 'SIN' : 'CSI');
    $cogsJtCode = $isOnline ? 'CGO' : ($isCredit ? 'CGT' : 'CGC');

    return orange_gl_posting_voucher_links($pdo, 'order', $orderId, [
        ['entry_type' => 'order_delivery_sale', 'journal_type_code' => $saleJtCode, 'label' => 'قيد المبيعات'],
        ['entry_type' => 'order_delivery_cogs', 'journal_type_code' => $cogsJtCode, 'label' => 'قيد تكلفة المبيعات'],
    ], $ofCountryId > 0 ? $ofCountryId : null);
}

function orange_sales_invoice_company_register_edit_lock(PDO $pdo, int $orderId, array $order): void
{
    require_once __DIR__ . '/edit_lock.php';
    require_once __DIR__ . '/journal_voucher.php';

    $countryId = (int) ($order['country_id'] ?? 0);
    if ($countryId <= 0) {
        $countryId = orange_admin_context_country_id($pdo);
    }
    $inv = trim((string) ($order['invoice_number'] ?? ''));
    $ref = $inv !== '' ? $inv : orange_sales_invoice_company_reference($pdo, $orderId, $order);
    $vid = null;
    if (orange_journal_vouchers_ready($pdo)) {
        $orderNumber = trim((string) ($order['order_number'] ?? ''));
        if ($orderNumber !== '') {
            $refs = orange_gl_voucher_select_references_like(
                $pdo,
                'ORDER-' . $orderNumber . '-S-%',
                $countryId > 0 ? $countryId : null
            );
            if ($refs !== []) {
                $v = orange_voucher_by_reference($pdo, (string) $refs[0], $countryId > 0 ? $countryId : null);
                if ($v) {
                    $vid = (int) ($v['id'] ?? 0);
                }
            }
        }
    }
    orange_edit_lock_register($pdo, [
        'doc_kind' => 'company_sales_invoice',
        'entity_id' => $orderId,
        'country_id' => $countryId > 0 ? $countryId : null,
        'reference' => $ref,
        'label_ar' => 'فاتورة مبيعات #' . $orderId,
        'amount' => (float) ($order['total'] ?? 0),
        'saved_at' => (string) ($order['created_at'] ?? date('Y-m-d H:i:s')),
        'journal_voucher_id' => $vid,
    ]);
}
