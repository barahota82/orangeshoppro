<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/payments/payment_gateway.php';
require_once __DIR__ . '/../../includes/backup/restore/restore_maintenance_enforcement.php';

orange_restore_maint_enforcement_api_mutation_guard('application_write_api');

header('Content-Type: application/json; charset=utf-8');

function gwc_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    gwc_json(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $pdo = db();
    orange_catalog_ensure_storefront_page($pdo);
    orange_payments_ensure_schema($pdo);

    $cid = (int) orange_storefront_current_country_id($pdo);
    if (!orange_payment_gateway_ready($pdo, $cid)) {
        gwc_json(['success' => false, 'message' => 'الدفع الإلكتروني غير مفعّل حالياً'], 403);
    }

    $body = json_decode((string) file_get_contents('php://input'), true);
    $body = is_array($body) ? $body : $_POST;
    $orderNumber = trim((string) ($body['order_number'] ?? ''));
    $phoneRaw = trim((string) ($body['phone'] ?? ''));
    if ($orderNumber === '' || $phoneRaw === '') {
        gwc_json(['success' => false, 'message' => 'رقم الطلب والهاتف مطلوبان'], 422);
    }

    $hasPaidCol = orange_table_has_column($pdo, 'orders', 'amount_paid');
    $st = $pdo->prepare('SELECT id, order_number, customer_name, phone, total' . ($hasPaidCol ? ', amount_paid' : '') . ', payment_status FROM orders WHERE order_number = ? LIMIT 1');
    $st->execute([$orderNumber]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        gwc_json(['success' => false, 'message' => 'الطلب غير موجود'], 404);
    }
    $tail = static function (string $d): string { return substr(preg_replace('/\D+/', '', $d), -8); };
    if ($tail($phoneRaw) === '' || $tail($phoneRaw) !== $tail((string) ($order['phone'] ?? ''))) {
        gwc_json(['success' => false, 'message' => 'بيانات الطلب غير مطابقة'], 403);
    }
    if ((string) ($order['payment_status'] ?? '') === 'paid') {
        gwc_json(['success' => false, 'message' => 'الطلب مدفوع بالفعل'], 409);
    }

    $orderId = (int) $order['id'];
    $due = round((float) $order['total'] - (float) ($order['amount_paid'] ?? 0), 3);
    if ($due <= 0) {
        $due = round((float) $order['total'], 3);
    }
    $currency = orange_country_functional_currency_code($pdo, $cid);

    $provider = orange_payment_gateway_default_provider();
    $prefix = orange_payment_gateway_load($provider);
    $cfg = orange_payment_gateway_config($provider);
    if ($prefix === null || !orange_payment_gateway_is_configured($provider, $cfg)) {
        gwc_json(['success' => false, 'message' => 'مزوّد الدفع غير مُعدّ'], 503);
    }

    $returnUrl = storefront_absolute_url(storefront_public_path('/pages/payment-return.php?order=' . rawurlencode($orderNumber)));
    $errorUrl = $returnUrl;

    $createFn = $prefix . '_create_session';
    $res = $createFn($cfg, [
        'order_number' => (string) $order['order_number'],
        'amount' => $due,
        'currency' => $currency,
        'name' => (string) ($order['customer_name'] ?? ''),
        'phone' => (string) ($order['phone'] ?? ''),
        'email' => '',
    ], $returnUrl, $errorUrl);

    if (empty($res['ok']) || trim((string) ($res['url'] ?? '')) === '') {
        gwc_json(['success' => false, 'message' => (string) ($res['error'] ?? 'تعذر بدء الدفع')], 502);
    }

    $providerRef = (string) $res['provider_ref'];
    orange_payment_record_transaction($pdo, [
        'order_id' => $orderId,
        'country_id' => $cid,
        'method' => 'gateway',
        'provider' => $provider,
        'amount' => $due,
        'currency' => $currency,
        'status' => 'pending_review',
        'provider_ref' => $providerRef,
        'txn_uuid' => orange_payment_gateway_txn_uuid($provider, $providerRef),
    ]);
    orange_payment_set_order_status($pdo, $orderId, 'pending_review', 'gateway', null);

    gwc_json(['success' => true, 'payment_url' => (string) $res['url']]);
} catch (Throwable $e) {
    gwc_json(['success' => false, 'message' => 'تعذر إتمام العملية'], 500);
}
