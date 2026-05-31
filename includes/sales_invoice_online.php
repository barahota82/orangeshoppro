<?php

declare(strict_types=1);

/**
 * GAP-SALE-DOC-01 مرحلة 3 — فاتورة أونلاين (INV-O): مساعدات API مشتركة.
 *
 * @see docs/archive/ORANGE_SALES_INVOICE_DOC_UI_HANDOFF.txt
 */

require_once __DIR__ . '/sales_invoice_company.php';
require_once __DIR__ . '/order_fulfillment.php';
require_once __DIR__ . '/edit_lock.php';
require_once __DIR__ . '/journal_voucher.php';
require_once __DIR__ . '/phone_validation.php';

/**
 * @return array{sql:string,params:list<mixed>}|null
 */
function orange_sales_invoice_online_country_filter(PDO $pdo, int $countryId, string $alias = 'o'): ?array
{
    return orange_sales_invoice_company_country_filter($pdo, $countryId, $alias);
}

/**
 * شروط SQL لطلبات INV-O (مكتملة + رقم فاتورة أونلاين).
 */
function orange_sales_invoice_online_scope_sql(PDO $pdo, string $alias = 'o'): string
{
    $sql = '';
    if (orange_table_has_column($pdo, 'orders', 'invoice_number')) {
        $sql .= " AND {$alias}.invoice_number IS NOT NULL AND {$alias}.invoice_number <> ''"
            . " AND {$alias}.invoice_number LIKE 'INV-O-%'";
    }
    if (orange_table_has_column($pdo, 'orders', 'status')) {
        $sql .= " AND {$alias}.status = 'completed'";
    }
    if (orange_table_has_column($pdo, 'orders', 'order_source')) {
        $sql .= " AND ({$alias}.order_source IS NULL OR {$alias}.order_source <> 'company')";
    }

    return $sql;
}

/**
 * @throws RuntimeException
 * @return array<string, mixed>
 */
function orange_sales_invoice_online_load_order(PDO $pdo, int $orderId): array
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

    if ((string) ($order['status'] ?? '') !== 'completed') {
        throw new RuntimeException('فاتورة أونلاين متاحة للطلبات المكتملة فقط', 422);
    }

    $inv = orange_table_has_column($pdo, 'orders', 'invoice_number')
        ? trim((string) ($order['invoice_number'] ?? ''))
        : '';
    if ($inv === '' || !str_starts_with($inv, 'INV-O-')) {
        throw new RuntimeException('هذا الطلب ليس فاتورة أونلاين (INV-O)', 422);
    }

    if (orange_table_has_column($pdo, 'orders', 'order_source')
        && trim((string) ($order['order_source'] ?? '')) === 'company') {
        throw new RuntimeException('هذا الطلب ليس فاتورة أونلاين', 422);
    }

    return $order;
}

function orange_sales_invoice_online_reference(PDO $pdo, int $orderId, array $order): string
{
    $inv = trim((string) ($order['invoice_number'] ?? ''));
    if ($inv !== '') {
        return $inv;
    }
    $countryId = (int) ($order['country_id'] ?? 0);
    if ($countryId <= 0) {
        $countryId = orange_admin_context_country_id($pdo);
    }

    return orange_country_document_ref($pdo, 'INV-O', $orderId, max(0, $countryId));
}

function orange_sales_invoice_online_gl_posted(PDO $pdo, array $order): bool
{
    $orderNumber = trim((string) ($order['order_number'] ?? ''));
    if ($orderNumber === '') {
        return false;
    }
    $countryId = (int) ($order['country_id'] ?? 0);

    return orange_order_forward_delivery_accounting_exists(
        $pdo,
        $orderNumber,
        $countryId > 0 ? $countryId : null
    );
}

/**
 * @return array{order:array<string,mixed>,items:list<array<string,mixed>>,customer:array<string,mixed>|null}
 */
function orange_sales_invoice_online_document_payload(PDO $pdo, int $orderId): array
{
    $order = orange_sales_invoice_online_load_order($pdo, $orderId);
    $countryId = (int) ($order['country_id'] ?? 0);
    if ($countryId <= 0) {
        $countryId = orange_admin_context_country_id($pdo);
    }

    $items = orange_sales_invoice_company_load_items($pdo, $orderId);

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

    $completedRaw = orange_table_has_column($pdo, 'orders', 'completed_at')
        ? trim((string) ($order['completed_at'] ?? ''))
        : '';

    $orderOut = [
        'id' => $orderId,
        'order_number' => (string) ($order['order_number'] ?? ''),
        'reference' => orange_sales_invoice_online_reference($pdo, $orderId, $order),
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
            ? trim((string) ($order['payment_terms'] ?? 'online'))
            : 'online',
        'status' => (string) ($order['status'] ?? ''),
        'status_label' => 'تم التسليم',
        'total' => (float) ($order['total'] ?? 0),
        'subtotal' => $subtotal,
        'created_at' => (string) ($order['created_at'] ?? ''),
        'created_at_dmy' => !empty($order['created_at'])
            ? orange_format_date_dmY((string) $order['created_at'])
            : '',
        'completed_at' => $completedRaw,
        'completed_at_dmy' => $completedRaw !== '' ? orange_format_date_dmY($completedRaw) : '',
        'country_id' => $countryId,
        'gl_posted' => orange_sales_invoice_online_gl_posted($pdo, $order),
    ];

    return [
        'order' => $orderOut,
        'items' => $items,
        'customer' => $customerOut,
    ];
}

function orange_sales_invoice_online_register_edit_lock(PDO $pdo, int $orderId, array $order): void
{
    $countryId = (int) ($order['country_id'] ?? 0);
    if ($countryId <= 0) {
        $countryId = orange_admin_context_country_id($pdo);
    }
    $ref = orange_sales_invoice_online_reference($pdo, $orderId, $order);
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
        'doc_kind' => 'online_sales_invoice',
        'entity_id' => $orderId,
        'country_id' => $countryId > 0 ? $countryId : null,
        'reference' => $ref,
        'label_ar' => 'فاتورة أونلاين #' . $orderId,
        'amount' => (float) ($order['total'] ?? 0),
        'saved_at' => (string) ($order['created_at'] ?? date('Y-m-d H:i:s')),
        'journal_voucher_id' => $vid,
    ]);
}

/**
 * تحديث INV-O — ترويسة + بنود في الطلب فقط؛ **لا** إعادة ترحيل GL ولا مسار final-posting.
 *
 * @param array<string, mixed> $data
 * @return array{payload:array<string,mixed>,items_gl_note:?string}
 */
function orange_sales_invoice_online_apply_update(PDO $pdo, int $orderId, array $data, ?array $adminRow): array
{
    $order = orange_sales_invoice_online_load_order($pdo, $orderId);
    $orderCountryId = (int) ($order['country_id'] ?? 0);
    if ($orderCountryId <= 0) {
        $orderCountryId = orange_admin_context_country_id($pdo);
    }

    if ($adminRow) {
        orange_edit_lock_assert_may_mutate(
            $pdo,
            $adminRow,
            'online_sales_invoice',
            $orderId,
            'edit',
            $orderCountryId > 0 ? $orderCountryId : null
        );
    }

    $orderNumber = trim((string) ($order['order_number'] ?? ''));
    if ($orderNumber !== '' && orange_order_fulfillment_vouchers_exist($pdo, $orderNumber, $orderCountryId > 0 ? $orderCountryId : null)) {
        $refs = orange_gl_voucher_select_references_like(
            $pdo,
            'ORDER-' . $orderNumber . '-S-%',
            $orderCountryId > 0 ? $orderCountryId : null
        );
        foreach ($refs as $ref) {
            $v = orange_voucher_by_reference($pdo, (string) $ref, $orderCountryId > 0 ? $orderCountryId : null);
            if ($v && orange_accounting_is_locked($pdo, $v)) {
                throw new RuntimeException('لا يمكن تعديل فاتورة مرتبطة بسنة مالية مغلقة');
            }
        }
    }

    $customerName = trim((string) ($data['customer_name'] ?? $order['customer_name'] ?? ''));
    if ($customerName === '') {
        throw new RuntimeException('اسم العميل مطلوب');
    }

    $phoneRawIn = trim((string) ($data['phone'] ?? $order['phone'] ?? ''));
    if ($phoneRawIn === '') {
        throw new RuntimeException('رقم الهاتف مطلوب');
    }

    $ctxDial = orange_admin_context_phone_dial($pdo);
    $phoneCountryRaw = trim((string) ($data['phone_country'] ?? ''));
    if ($phoneCountryRaw === '' && orange_table_has_column($pdo, 'orders', 'phone_country_dial')) {
        $phoneCountryRaw = trim((string) ($order['phone_country_dial'] ?? ''));
    }
    if ($phoneCountryRaw === '') {
        $phoneCountryRaw = $ctxDial;
    }
    $pcParsed = orange_storefront_parse_api_phone_country($phoneCountryRaw);
    $dialForNational = (string) ($pcParsed['dial'] ?? '');
    if ($phoneRawIn !== '' && $dialForNational === '' && empty($pcParsed['international_single_field'])) {
        throw new RuntimeException('كود الدولة غير صالح');
    }
    if ($phoneRawIn !== '' && preg_match('/^\s*(\+|00)/', $phoneRawIn)) {
        throw new RuntimeException('أدخل الرقم المحلي في حقل الهاتف؛ ضع كود الدولة في قائمة كود الدولة.');
    }
    $phoneNorm = orange_normalize_customer_phone(
        $phoneRawIn,
        $dialForNational !== '' ? $dialForNational : null,
        !empty($pcParsed['international_single_field'])
    );
    if ($phoneNorm === null) {
        throw new RuntimeException('رقم الهاتف غير صالح');
    }

    $channelId = isset($data['channel_id']) ? (int) $data['channel_id'] : (int) ($order['channel_id'] ?? 0);
    if ($channelId <= 0) {
        throw new RuntimeException('قناة العملاء مطلوبة');
    }
    orange_admin_assert_row_country($pdo, 'channels', $channelId);
    $chSt = $pdo->prepare('SELECT id FROM channels WHERE id = ? AND is_active = 1 LIMIT 1');
    $chSt->execute([$channelId]);
    if (!$chSt->fetchColumn()) {
        throw new RuntimeException('قناة غير صالحة');
    }

    $itemsIn = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    if ($itemsIn === []) {
        throw new RuntimeException('أضف سطرًا واحدًا على الأقل من المنتجات المسجّرة');
    }

    $area = trim((string) ($data['area'] ?? $order['area'] ?? ''));
    $address = trim((string) ($data['address'] ?? $order['address'] ?? ''));
    $notes = trim((string) ($data['notes'] ?? $order['notes'] ?? ''));

    $glPosted = orange_sales_invoice_online_gl_posted($pdo, $order);

    $validated = orange_sales_invoice_company_validate_items($pdo, $itemsIn, $orderCountryId);
    $total = (float) $validated['total'];
    $validatedItems = $validated['items'];

    $pdo->beginTransaction();

    $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);

    $sets = [
        'customer_name = ?',
        'phone = ?',
        'area = ?',
        'address = ?',
        'notes = ?',
        'channel_id = ?',
        'total = ?',
    ];
    $params = [$customerName, $phoneNorm, $area, $address, $notes, $channelId, $total];

    if (orange_table_has_column($pdo, 'orders', 'phone_country_dial')) {
        $sets[] = 'phone_country_dial = ?';
        $params[] = $dialForNational !== '' ? $dialForNational : null;
    }
    if (orange_table_has_column($pdo, 'orders', 'phone_national')) {
        $sets[] = 'phone_national = ?';
        $params[] = $phoneRawIn !== '' ? $phoneRawIn : null;
    }
    if (orange_table_has_column($pdo, 'orders', 'updated_at')) {
        $sets[] = 'updated_at = NOW()';
    }

    $params[] = $orderId;
    $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

    $profile = [
        'area' => $area,
        'address' => $address,
        'phone_country_dial' => $dialForNational !== '' ? $dialForNational : null,
        'phone_national' => $phoneRawIn !== '' ? $phoneRawIn : null,
    ];
    if (orange_table_has_column($pdo, 'orders', 'delivery_area_id')) {
        $profile['delivery_area_id'] = isset($data['delivery_area_id'])
            ? (int) $data['delivery_area_id']
            : (int) ($order['delivery_area_id'] ?? 0);
    }
    $customerIdIn = (int) ($data['customer_id'] ?? $order['customer_id'] ?? 0);
    $customerId = orange_ensure_customer_with_profile(
        $pdo,
        $customerName,
        $phoneNorm,
        $profile,
        $orderCountryId > 0 ? $orderCountryId : null
    );
    if ($customerId > 0 && orange_table_has_column($pdo, 'orders', 'customer_id')) {
        $pdo->prepare('UPDATE orders SET customer_id = ? WHERE id = ?')->execute([$customerId, $orderId]);
    } elseif ($customerIdIn > 0 && orange_table_has_column($pdo, 'orders', 'customer_id')) {
        $pdo->prepare('UPDATE orders SET customer_id = ? WHERE id = ?')->execute([$customerIdIn, $orderId]);
    }

    orange_sales_invoice_company_insert_items($pdo, $orderId, $validatedItems);

    $ordSt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $ordSt->execute([$orderId]);
    $orderRow = $ordSt->fetch(PDO::FETCH_ASSOC) ?: [];
    orange_sales_invoice_online_register_edit_lock($pdo, $orderId, $orderRow);

    if ($adminRow) {
        orange_edit_lock_log_mutation($pdo, 'online_sales_invoice', $orderId, 'edit');
    }

    $pdo->commit();

    $itemsNote = $glPosted
        ? 'تم حفظ التعديل على الطلب/الفاتورة. القيود المرحّلة سابقاً **لم** تُعاد حساباً — راجع المحاسبة أو مردود المبيعات إن لزم.'
        : null;

    return [
        'payload' => orange_sales_invoice_online_document_payload($pdo, $orderId),
        'items_gl_note' => $itemsNote,
    ];
}
