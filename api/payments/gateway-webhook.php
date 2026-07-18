<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/payments/payment_gateway.php';
require_once __DIR__ . '/../../includes/backup/restore/restore_maintenance_enforcement.php';

// Owner has not allowlisted payment callbacks during maintenance (default: block).
orange_restore_maint_enforcement_api_mutation_guard('application_write_api', [
    'is_payment_callback' => true,
    'payment_callback_allowlisted' => false,
]);

header('Content-Type: application/json; charset=utf-8');

function gww_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    gww_json(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $pdo = db();
    orange_payments_ensure_schema($pdo);

    $provider = orange_payment_gateway_default_provider();
    $prefix = orange_payment_gateway_load($provider);
    $cfg = orange_payment_gateway_config($provider);
    if ($prefix === null || !orange_payment_gateway_is_configured($provider, $cfg)) {
        gww_json(['success' => false, 'message' => 'not configured'], 503);
    }

    $rawBody = (string) file_get_contents('php://input');
    $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
    if (!is_array($headers)) {
        $headers = [];
    }

    /* تحقق التوقيع (طبقة منع تلاعب) — الأمان الحاسم في التأكيد الخادمي أدناه. */
    $whFn = $prefix . '_webhook_verify';
    $wh = $whFn($cfg, $rawBody, $headers);
    if (empty($wh['ok'])) {
        gww_json(['success' => false, 'message' => (string) ($wh['error'] ?? 'invalid')], 400);
    }
    $invoiceId = (string) ($wh['provider_ref'] ?? '');
    if ($invoiceId === '') {
        gww_json(['success' => false, 'message' => 'no ref'], 422);
    }

    /* تأكيد خادمي مستقل (لا نثق بمحتوى الإشعار). */
    $verifyFn = $prefix . '_verify';
    $verify = $verifyFn($cfg, $invoiceId, 'InvoiceId');
    if (empty($verify['ok'])) {
        gww_json(['success' => false, 'message' => 'verify failed'], 502);
    }

    $orderRef = trim((string) ($verify['order_ref'] ?? ''));
    $orderId = 0;
    $cid = 0;
    if ($orderRef !== '') {
        $st = $pdo->prepare('SELECT id, country_id FROM orders WHERE order_number = ? LIMIT 1');
        $st->execute([$orderRef]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $orderId = (int) ($row['id'] ?? 0);
        $cid = (int) ($row['country_id'] ?? 0);
    }
    if ($orderId <= 0) {
        /* fallback: عبر حركة معلّقة سابقة. */
        $st = $pdo->prepare('SELECT order_id, country_id FROM payment_transactions WHERE txn_uuid = ? LIMIT 1');
        $st->execute([orange_payment_gateway_txn_uuid($provider, $invoiceId)]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $orderId = (int) ($row['order_id'] ?? 0);
        $cid = (int) ($row['country_id'] ?? 0);
    }
    if ($orderId <= 0) {
        gww_json(['success' => false, 'message' => 'order not found'], 404);
    }

    if (($verify['status'] ?? '') === 'paid') {
        $r = orange_payment_gateway_settle($pdo, $orderId, $cid > 0 ? $cid : null, $provider, $invoiceId, $verify);
        gww_json(['success' => true, 'message' => $r['message'] ?? 'ok']);
    }
    if (($verify['status'] ?? '') === 'failed') {
        orange_payment_set_order_status($pdo, $orderId, 'failed', 'gateway', null);
        gww_json(['success' => true, 'message' => 'failed recorded']);
    }

    gww_json(['success' => true, 'message' => 'pending']);
} catch (Throwable $e) {
    gww_json(['success' => false, 'message' => 'error'], 500);
}
