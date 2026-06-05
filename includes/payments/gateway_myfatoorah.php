<?php

declare(strict_types=1);

/**
 * موصِّل MyFatoorah (MyFatoorah v2 — Hosted).
 *
 * يقرأ الإعدادات من `.env.php` فقط (Token / Mode / BaseURL / Webhook secret).
 * لا يلمس بيانات البطاقة (Hosted). التأكيد دائماً خادمي عبر GetPaymentStatus.
 */

function orange_gateway_myfatoorah_is_configured(array $cfg): bool
{
    return trim((string) ($cfg['token'] ?? '')) !== ''
        && trim((string) ($cfg['base_url'] ?? '')) !== '';
}

/**
 * نداء HTTP JSON موحّد للبوابة.
 *
 * @return array{ok:bool,http:int,json:array,error:string}
 */
function orange_gateway_myfatoorah_request(array $cfg, string $endpoint, array $payload): array
{
    $url = rtrim((string) $cfg['base_url'], '/') . '/' . ltrim($endpoint, '/');
    $token = (string) $cfg['token'];
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http' => 0, 'json' => [], 'error' => 'cURL غير متاح على الخادم'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'http' => $http, 'json' => [], 'error' => $err !== '' ? $err : 'فشل الاتصال بالبوابة'];
    }
    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'http' => $http, 'json' => [], 'error' => 'استجابة غير صالحة من البوابة'];
    }
    $isOk = $http >= 200 && $http < 300 && (bool) ($json['IsSuccess'] ?? false);

    return ['ok' => $isOk, 'http' => $http, 'json' => $json, 'error' => $isOk ? '' : (string) ($json['Message'] ?? 'خطأ من البوابة')];
}

/**
 * إنشاء جلسة دفع (SendPayment) — يعيد رابط الدفع و InvoiceId كمرجع.
 *
 * @param array{order_number:string,amount:float,currency:string,name:string,phone:string,email:string,country_code:string} $order
 * @return array{ok:bool,url:string,provider_ref:string,error:string}
 */
function orange_gateway_myfatoorah_create_session(array $cfg, array $order, string $returnUrl, string $errorUrl): array
{
    $amount = round((float) ($order['amount'] ?? 0), 3);
    if ($amount <= 0) {
        return ['ok' => false, 'url' => '', 'provider_ref' => '', 'error' => 'مبلغ غير صالح'];
    }
    $payload = [
        'InvoiceValue' => $amount,
        'CustomerName' => mb_substr(trim((string) ($order['name'] ?? 'Customer')), 0, 100),
        'DisplayCurrencyIso' => strtoupper((string) ($order['currency'] ?? 'KWD')),
        'CustomerReference' => (string) ($order['order_number'] ?? ''),
        'CallBackUrl' => $returnUrl,
        'ErrorUrl' => $errorUrl !== '' ? $errorUrl : $returnUrl,
        'Language' => 'AR',
        'NotificationOption' => 'LNK',
    ];
    $phone = preg_replace('/\D+/', '', (string) ($order['phone'] ?? ''));
    if ($phone !== '') {
        $payload['CustomerMobile'] = substr($phone, -11);
    }
    $email = trim((string) ($order['email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $payload['CustomerEmail'] = $email;
    }

    $res = orange_gateway_myfatoorah_request($cfg, '/v2/SendPayment', $payload);
    if (!$res['ok']) {
        return ['ok' => false, 'url' => '', 'provider_ref' => '', 'error' => $res['error']];
    }
    $data = $res['json']['Data'] ?? [];
    $invoiceUrl = trim((string) ($data['InvoiceURL'] ?? ''));
    $invoiceId = (string) ($data['InvoiceId'] ?? '');
    if ($invoiceUrl === '' || $invoiceId === '') {
        return ['ok' => false, 'url' => '', 'provider_ref' => '', 'error' => 'لم تُرجع البوابة رابط الدفع'];
    }

    return ['ok' => true, 'url' => $invoiceUrl, 'provider_ref' => $invoiceId, 'error' => ''];
}

/**
 * تأكيد خادمي لحالة الدفع (GetPaymentStatus بـ InvoiceId).
 *
 * @return array{ok:bool,status:string,amount:float,currency:string,raw:array,error:string}
 */
function orange_gateway_myfatoorah_verify(array $cfg, string $providerRef, string $keyType = 'InvoiceId'): array
{
    $providerRef = trim($providerRef);
    $keyType = in_array($keyType, ['InvoiceId', 'PaymentId'], true) ? $keyType : 'InvoiceId';
    if ($providerRef === '') {
        return ['ok' => false, 'status' => 'failed', 'amount' => 0.0, 'currency' => '', 'raw' => [], 'error' => 'مرجع فارغ'];
    }
    $res = orange_gateway_myfatoorah_request($cfg, '/v2/GetPaymentStatus', [
        'Key' => $providerRef,
        'KeyType' => $keyType,
    ]);
    if (!$res['ok']) {
        return ['ok' => false, 'status' => 'failed', 'amount' => 0.0, 'currency' => '', 'raw' => $res['json'], 'error' => $res['error']];
    }
    $data = $res['json']['Data'] ?? [];
    $mfStatus = strtolower((string) ($data['InvoiceStatus'] ?? ''));
    $status = 'pending';
    if (in_array($mfStatus, ['paid'], true)) {
        $status = 'paid';
    } elseif (in_array($mfStatus, ['failed', 'expired', 'canceled', 'cancelled'], true)) {
        $status = 'failed';
    }
    $amount = (float) ($data['InvoiceValue'] ?? 0);

    return [
        'ok' => true,
        'status' => $status,
        'amount' => $amount,
        'currency' => strtoupper((string) ($data['DisplayCurrencyIso'] ?? '')),
        'invoice_id' => (string) ($data['InvoiceId'] ?? $providerRef),
        'order_ref' => (string) ($data['CustomerReference'] ?? ''),
        'raw' => $data,
        'error' => '',
    ];
}

/**
 * تحقق توقيع الـ webhook (HMAC-SHA256 على الجسم بسر مشترك).
 * الأمان الحاسم يبقى في التأكيد الخادمي (verify)؛ التوقيع طبقة منع تلاعب.
 *
 * @return array{ok:bool,provider_ref:string,error:string}
 */
function orange_gateway_myfatoorah_webhook_verify(array $cfg, string $rawBody, array $headers): array
{
    $secret = trim((string) ($cfg['webhook_secret'] ?? ''));
    $given = '';
    foreach ($headers as $k => $v) {
        if (strtolower((string) $k) === 'myfatoorah-signature') {
            $given = trim((string) $v);
            break;
        }
    }
    if ($secret !== '') {
        $calc = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        if ($given === '' || !hash_equals($calc, $given)) {
            return ['ok' => false, 'provider_ref' => '', 'error' => 'توقيع غير صالح'];
        }
    }
    $json = json_decode($rawBody, true);
    $data = is_array($json) ? ($json['Data'] ?? $json) : [];
    $ref = (string) ($data['InvoiceId'] ?? ($data['Invoice']['Id'] ?? ''));
    if ($ref === '') {
        return ['ok' => false, 'provider_ref' => '', 'error' => 'لا InvoiceId في الإشعار'];
    }

    return ['ok' => true, 'provider_ref' => $ref, 'error' => ''];
}
