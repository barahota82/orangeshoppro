<?php

declare(strict_types=1);

require_once __DIR__ . '/phone_validation.php';

function require_fields(array $data, array $keys): void
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            throw new RuntimeException('Missing field: ' . $key);
        }
    }
}

function generate_order_number(): string
{
    return 'ORD-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function clean_whatsapp_number(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw);

    return $digits !== null && $digits !== '' ? $digits : '';
}

/**
 * مطابقة رقم هاتف العميل مع المخزّن في الطلب (مسافات، شرطات، اختلاف بسيط في البادئة).
 */
function orange_order_phones_match_for_lookup(string $input, string $stored): bool
{
    $input = trim($input);
    $stored = trim($stored);
    if ($input === '' || $stored === '') {
        return false;
    }
    if (strcasecmp($input, $stored) === 0) {
        return true;
    }
    $ni = orange_normalize_customer_phone($input, null);
    $ns = orange_normalize_customer_phone($stored, null);
    if ($ni !== null && $ns !== null && strcasecmp($ni, $ns) === 0) {
        return true;
    }
    $di = preg_replace('/\D+/', '', $input);
    $ds = preg_replace('/\D+/', '', $stored);
    if ($di === '' || $ds === '') {
        return false;
    }
    if ($di === $ds) {
        return true;
    }
    $minLen = 8;
    if (strlen($di) >= $minLen && strlen($ds) >= $minLen && substr($di, -$minLen) === substr($ds, -$minLen)) {
        return true;
    }

    return false;
}

/**
 * Sales payment mode for orders: cash (نقدي), credit (آجل), or online (أونلاين).
 */
function orange_normalize_payment_terms(mixed $raw): string
{
    $v = strtolower(trim((string) $raw));
    if ($v === 'credit') {
        return 'credit';
    }
    if ($v === 'online') {
        return 'online';
    }

    return 'cash';
}

/**
 * تسمية عربية لنوع بيع الطلب (نقدي / آجل / أونلاين) للواجهات.
 */
function orange_order_payment_terms_label_ar(mixed $raw): string
{
    $pt = orange_normalize_payment_terms($raw);
    if ($pt === 'credit') {
        return 'آجل';
    }
    if ($pt === 'online') {
        return 'أونلاين';
    }

    return 'نقدي';
}

/**
 * هل يُستخدم حساب إيراد «مبيعات أونلاين» في قيد تسليم الطلب؟
 *
 * طلبات الواجهة (order_source = website) بسياسة الدفع عند الاستلام تُرحَّل كنقدي عند التسليم
 * (قالب إيراد نقدي + خزينة فورية)، حتى لو خُزّن قديماً payment_terms = online.
 */
function orange_order_delivery_sale_uses_online_revenue_account(PDO $pdo, array $order): bool
{
    $pt = orange_normalize_payment_terms($order['payment_terms'] ?? 'cash');
    if ($pt !== 'online') {
        return false;
    }
    if (!orange_table_has_column($pdo, 'orders', 'order_source')) {
        return true;
    }

    return trim((string) ($order['order_source'] ?? '')) !== 'website';
}

/**
 * Resolve a catalog variant row for an order line (variant_id preferred, else color/size).
 *
 * @param array<string,mixed> $item
 * @return array<string,mixed>|null
 */
function orange_order_resolve_variant_from_item(PDO $pdo, array $item): ?array
{
    $pid = isset($item['product_id']) ? (int) $item['product_id'] : 0;
    if ($pid <= 0) {
        return null;
    }

    $vid = isset($item['variant_id']) ? (int)$item['variant_id'] : 0;
    if ($vid > 0) {
        $vStmt = $pdo->prepare(
            'SELECT * FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1'
        );
        $vStmt->execute([$vid, $pid]);
        $v = $vStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($v)) {
            return $v;
        }
    }
    $variantStmt = $pdo->prepare(
        'SELECT * FROM product_variants
        WHERE product_id = ? AND color = ? AND size = ?
        LIMIT 1'
    );
    $variantStmt->execute([
        $pid,
        (string)$item['color'],
        (string)$item['size'],
    ]);
    $v = $variantStmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($v)) {
        return $v;
    }
    $one = $pdo->prepare(
        'SELECT * FROM product_variants WHERE product_id = ? ORDER BY id ASC LIMIT 1'
    );
    $one->execute([$pid]);
    $v = $one->fetch(PDO::FETCH_ASSOC);
    return is_array($v) ? $v : null;
}

/**
 * خصم البند بالدينار (لا يتجاوز إجمالي السطر قبل الخصم).
 *
 * @param array<string, mixed> $item
 */
function orange_order_item_line_discount(array $item): float
{
    $v = isset($item['line_discount']) ? (float) $item['line_discount'] : 0.0;

    return round(max(0.0, $v), 4);
}

/**
 * صافي سطر الطلب بعد الخصم.
 *
 * @param array<string, mixed> $item
 */
function orange_order_item_line_net(array $item): float
{
    $qty = (int) ($item['qty'] ?? 0);
    $gross = round((float) ($item['price'] ?? 0) * $qty, 4);
    $disc = orange_order_item_line_discount($item);
    if ($disc > $gross + 0.0001) {
        return 0.0;
    }

    return max(0.0, round($gross - $disc, 4));
}

/**
 * بعد ربط التسجيل بالبريد من شاشة التتبع: تحديث صف الطلب (وبيانات العميل المرتبط إن وُجد).
 *
 * @param array<string, mixed> $order صف orders كما من قاعدة البيانات
 */
function orange_storefront_sync_order_and_customer_after_track_signup(
    PDO $pdo,
    array $order,
    string $email,
    string $name,
    string $area,
    string $address,
    string $notes,
    ?int $deliveryAreaId = null
): void {
    require_once __DIR__ . '/catalog_schema.php';

    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0 || !orange_table_exists($pdo, 'orders')) {
        return;
    }

    $hasOrderEmail = orange_table_has_column($pdo, 'orders', 'customer_email');
    $setOrder = ['customer_name = ?', 'area = ?', 'address = ?', 'notes = ?'];
    $paramsOrder = [
        $name,
        $area,
        $address,
        $notes,
    ];
    if ($hasOrderEmail) {
        $setOrder[] = 'customer_email = ?';
        $paramsOrder[] = $email;
    }
    if ($deliveryAreaId !== null && $deliveryAreaId > 0 && orange_table_has_column($pdo, 'orders', 'delivery_area_id')) {
        $setOrder[] = 'delivery_area_id = ?';
        $paramsOrder[] = $deliveryAreaId;
    }
    $paramsOrder[] = $orderId;
    $pdo->prepare('UPDATE orders SET ' . implode(', ', $setOrder) . ' WHERE id = ?')->execute($paramsOrder);

    $cid = isset($order['customer_id']) ? (int) $order['customer_id'] : 0;
    if ($cid <= 0 || !orange_table_exists($pdo, 'customers')) {
        return;
    }

    $setCust = ['name_ar = ?'];
    $paramsCust = [$name];
    if (orange_table_has_column($pdo, 'customers', 'email')) {
        $setCust[] = 'email = ?';
        $paramsCust[] = $email;
    }
    if (orange_table_has_column($pdo, 'customers', 'area')) {
        $setCust[] = 'area = ?';
        $paramsCust[] = $area;
    }
    if (orange_table_has_column($pdo, 'customers', 'address')) {
        $setCust[] = 'address = ?';
        $paramsCust[] = $address;
    }
    $paramsCust[] = $cid;
    $pdo->prepare('UPDATE customers SET ' . implode(', ', $setCust) . ' WHERE id = ?')->execute($paramsCust);
}