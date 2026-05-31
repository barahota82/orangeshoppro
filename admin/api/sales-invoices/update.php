<?php

declare(strict_types=1);

/**
 * GAP-SALE-DOC-01 مرحلة 0 — تحديث ترويسة فاتورة شركة (INV-C) فقط؛ البنود في المرحلة 1.
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/sales_invoice_company.php';
require_once __DIR__ . '/../../../includes/phone_validation.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $orderId = (int) ($data['order_id'] ?? $data['id'] ?? 0);
    if ($orderId <= 0) {
        json_response(['success' => false, 'message' => 'معرف الفاتورة مطلوب'], 422);
    }

    $order = orange_sales_invoice_company_load_order($pdo, $orderId);

    $customerName = trim((string) ($data['customer_name'] ?? $order['customer_name'] ?? ''));
    if ($customerName === '') {
        json_response(['success' => false, 'message' => 'اسم العميل مطلوب'], 422);
    }

    $phoneRawIn = trim((string) ($data['phone'] ?? $order['phone'] ?? ''));
    if ($phoneRawIn === '') {
        json_response(['success' => false, 'message' => 'رقم الهاتف مطلوب'], 422);
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
        json_response(['success' => false, 'message' => 'كود الدولة غير صالح'], 422);
    }
    if ($phoneRawIn !== '' && preg_match('/^\s*(\+|00)/', $phoneRawIn)) {
        json_response([
            'success' => false,
            'message' => 'أدخل الرقم المحلي في حقل الهاتف؛ ضع كود الدولة في قائمة كود الدولة.',
        ], 422);
    }
    $phoneNorm = orange_normalize_customer_phone(
        $phoneRawIn,
        $dialForNational !== '' ? $dialForNational : null,
        !empty($pcParsed['international_single_field'])
    );
    if ($phoneNorm === null) {
        json_response(['success' => false, 'message' => 'رقم الهاتف غير صالح'], 422);
    }

    $channelId = isset($data['channel_id']) ? (int) $data['channel_id'] : (int) ($order['channel_id'] ?? 0);
    if ($channelId <= 0) {
        json_response(['success' => false, 'message' => 'قناة العملاء مطلوبة'], 422);
    }
    try {
        orange_admin_assert_row_country($pdo, 'channels', $channelId);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }
    $chSt = $pdo->prepare('SELECT id FROM channels WHERE id = ? AND is_active = 1 LIMIT 1');
    $chSt->execute([$channelId]);
    if (!$chSt->fetchColumn()) {
        json_response(['success' => false, 'message' => 'قناة غير صالحة'], 422);
    }

    $paymentTerms = orange_normalize_payment_terms(
        $data['payment_terms'] ?? ($order['payment_terms'] ?? 'cash')
    );
    if ($paymentTerms === 'credit') {
        $customerIdIn = (int) ($data['customer_id'] ?? $order['customer_id'] ?? 0);
        $civilChk = orange_customer_credit_sale_civil_check($pdo, $customerIdIn, $phoneNorm);
        if (!$civilChk['ok']) {
            json_response(['success' => false, 'message' => $civilChk['message']], 422);
        }
    }

    $area = trim((string) ($data['area'] ?? $order['area'] ?? ''));
    $address = trim((string) ($data['address'] ?? $order['address'] ?? ''));
    $notes = trim((string) ($data['notes'] ?? $order['notes'] ?? ''));

    $total = (float) ($order['total'] ?? 0);
    $amountPaid = orange_table_has_column($pdo, 'orders', 'amount_paid')
        ? max(0.0, (float) ($data['amount_paid'] ?? $order['amount_paid'] ?? 0))
        : 0.0;
    $amountPaid = min($amountPaid, $total);

    $orderCountryId = (int) ($order['country_id'] ?? 0);
    if ($orderCountryId <= 0) {
        $orderCountryId = orange_admin_context_country_id($pdo);
    }

    $pdo->beginTransaction();

    $sets = [
        'customer_name = ?',
        'phone = ?',
        'area = ?',
        'address = ?',
        'notes = ?',
        'channel_id = ?',
    ];
    $params = [$customerName, $phoneNorm, $area, $address, $notes, $channelId];

    if (orange_table_has_column($pdo, 'orders', 'payment_terms')) {
        $sets[] = 'payment_terms = ?';
        $params[] = $paymentTerms;
    }
    if (orange_table_has_column($pdo, 'orders', 'amount_paid')) {
        $sets[] = 'amount_paid = ?';
        $params[] = $amountPaid;
    }
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
    $customerId = orange_ensure_customer_with_profile(
        $pdo,
        $customerName,
        $phoneNorm,
        $profile,
        $orderCountryId > 0 ? $orderCountryId : null
    );
    if ($customerId > 0 && orange_table_has_column($pdo, 'orders', 'customer_id')) {
        $pdo->prepare('UPDATE orders SET customer_id = ? WHERE id = ?')->execute([$customerId, $orderId]);
    }

    $pdo->commit();

    $payload = orange_sales_invoice_company_document_payload($pdo, $orderId);
    json_response([
        'success' => true,
        'message' => 'تم حفظ بيانات الفاتورة',
        'invoice' => $payload['order'],
        'items' => $payload['items'],
        'extra_lines' => $payload['extra_lines'],
        'customer' => $payload['customer'],
    ]);
} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 422;
    }
    json_response(['success' => false, 'message' => $e->getMessage()], $code);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر تحديث فاتورة المبيعات');
}
