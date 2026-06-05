<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/payments/payment_core.php';
require_once __DIR__ . '/../../includes/upload_paths.php';

header('Content-Type: application/json; charset=utf-8');

function bp_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    bp_json(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $pdo = db();
    orange_catalog_ensure_storefront_page($pdo);
    orange_payments_ensure_schema($pdo);

    $orderNumber = trim((string) ($_POST['order_number'] ?? ''));
    $phoneRaw = trim((string) ($_POST['phone'] ?? ''));
    $reference = trim((string) ($_POST['reference'] ?? ''));
    if ($orderNumber === '' || $phoneRaw === '') {
        bp_json(['success' => false, 'message' => 'رقم الطلب والهاتف مطلوبان'], 422);
    }

    /* تحقق ملكية الطلب: رقم الطلب + مطابقة آخر أرقام الهاتف (مثل مسار التتبّع). */
    $phoneDigits = preg_replace('/\D+/', '', $phoneRaw);
    $st = $pdo->prepare('SELECT id, phone, total FROM orders WHERE order_number = ? LIMIT 1');
    $st->execute([$orderNumber]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        bp_json(['success' => false, 'message' => 'الطلب غير موجود'], 404);
    }
    $orderPhoneDigits = preg_replace('/\D+/', '', (string) ($order['phone'] ?? ''));
    $tail = static function (string $d): string { return substr($d, -8); };
    if ($phoneDigits === '' || $tail($phoneDigits) !== $tail($orderPhoneDigits)) {
        bp_json(['success' => false, 'message' => 'بيانات الطلب غير مطابقة'], 403);
    }
    $orderId = (int) $order['id'];

    if (!isset($_FILES['proof']) || (int) ($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        bp_json(['success' => false, 'message' => 'يرجى إرفاق إثبات التحويل'], 422);
    }
    $tmp = (string) ($_FILES['proof']['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        bp_json(['success' => false, 'message' => 'ملف غير صالح'], 422);
    }
    if ((int) ($_FILES['proof']['size'] ?? 0) > 8 * 1024 * 1024) {
        bp_json(['success' => false, 'message' => 'الملف كبير جداً (الحد 8MB)'], 422);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf'];
    if (!isset($allowed[$mime])) {
        bp_json(['success' => false, 'message' => 'النوع غير مدعوم (صورة أو PDF)'], 422);
    }
    $dir = orange_payment_ensure_proof_dir();
    if ($dir === null) {
        bp_json(['success' => false, 'message' => 'تعذر حفظ الإثبات حالياً'], 500);
    }
    $name = 'pay_' . $orderId . '_' . date('Ymd') . '_' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($tmp, $dir . DIRECTORY_SEPARATOR . $name)) {
        bp_json(['success' => false, 'message' => 'تعذر حفظ الإثبات'], 500);
    }

    $cid = (int) orange_storefront_current_country_id($pdo);
    $txnUuid = 'bankweb_' . $orderId . '_' . substr(md5($reference . '|' . $name), 0, 16);
    orange_payment_record_transaction($pdo, [
        'order_id' => $orderId,
        'country_id' => $cid,
        'method' => 'bank',
        'provider' => 'manual',
        'amount' => 0,
        'currency' => orange_country_functional_currency_code($pdo, $cid),
        'status' => 'pending_review',
        'provider_ref' => $reference,
        'proof_file' => $name,
        'txn_uuid' => $txnUuid,
    ]);
    if (orange_table_has_column($pdo, 'payment_transactions', 'proof_file')) {
        $pdo->prepare('UPDATE payment_transactions SET proof_file = ? WHERE txn_uuid = ?')->execute([$name, $txnUuid]);
    }
    orange_payment_set_order_status($pdo, $orderId, 'pending_review', 'bank', null);

    bp_json(['success' => true, 'message' => 'تم استلام الإثبات — بانتظار تأكيد الإدارة']);
} catch (Throwable $e) {
    bp_json(['success' => false, 'message' => 'تعذر إتمام العملية'], 500);
}
