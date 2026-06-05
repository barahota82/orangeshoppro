<?php

declare(strict_types=1);

require_once __DIR__ . '/payment_core.php';

/**
 * طبقة بوابة الدفع الموحّدة (المرحلة 2).
 *
 * مبدأ: موصِّل واحد لكل مزوّد بواجهة موحّدة. الأسرار في `.env.php` فقط (لا Git).
 * **معطّلة افتراضياً**: بلا مفاتيح env أو بلا تفعيل طريقة `gateway` per دولة = لا تعمل.
 *
 * واجهة الموصِّل المتوقَّعة (دوال بادئة orange_gateway_{provider}_*):
 *   *_is_configured(array $cfg): bool
 *   *_create_session(array $cfg, array $order, string $returnUrl, string $errorUrl): array
 *       => ['ok'=>bool,'url'=>string,'provider_ref'=>string,'error'=>string]
 *   *_verify(array $cfg, string $providerRef): array
 *       => ['ok'=>bool,'status'=>'paid|pending|failed','amount'=>float,'currency'=>string,'raw'=>array,'error'=>string]
 *   *_webhook_verify(array $cfg, string $rawBody, array $headers): array
 *       => ['ok'=>bool,'provider_ref'=>string,'error'=>string]
 */

/** قراءة `.env.php` (مصفوفة) — مُخزَّنة. لا تُنشئ الملف؛ غيابه = لا إعدادات. */
function orange_payment_env(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $path = __DIR__ . '/../../.env.php';
    if (!is_file($path)) {
        $cache = [];

        return $cache;
    }
    try {
        $arr = require $path;
    } catch (Throwable $e) {
        $arr = [];
    }
    $cache = is_array($arr) ? $arr : [];

    return $cache;
}

/** المزوّد الافتراضي للبوابة (قابل للتوسعة). */
function orange_payment_gateway_default_provider(): string
{
    $env = orange_payment_env();
    $p = trim((string) ($env['PAYMENT_GATEWAY_PROVIDER'] ?? 'myfatoorah'));

    return $p !== '' ? strtolower($p) : 'myfatoorah';
}

/**
 * إعدادات مزوّد من env (غير سرية في الكود — تُقرأ من .env.php على السيرفر).
 *
 * @return array<string,mixed>
 */
function orange_payment_gateway_config(string $provider): array
{
    $env = orange_payment_env();
    $provider = strtolower(trim($provider));
    $mode = strtolower(trim((string) ($env['PAYMENT_GATEWAY_MODE'] ?? 'test')));
    $mode = in_array($mode, ['test', 'live'], true) ? $mode : 'test';

    if ($provider === 'myfatoorah') {
        $base = trim((string) ($env['PAYMENT_MYF_BASE_URL'] ?? ''));
        if ($base === '') {
            $base = $mode === 'live' ? 'https://api.myfatoorah.com' : 'https://apitest.myfatoorah.com';
        }

        return [
            'provider' => 'myfatoorah',
            'mode' => $mode,
            'base_url' => rtrim($base, '/'),
            'token' => trim((string) ($env['PAYMENT_MYF_TOKEN'] ?? '')),
            'webhook_secret' => trim((string) ($env['PAYMENT_MYF_WEBHOOK_SECRET'] ?? '')),
        ];
    }

    return [
        'provider' => $provider,
        'mode' => $mode,
    ];
}

/** هل طريقة بوابة مفعّلة لهذه الدولة (في payment_methods)؟ */
function orange_payment_gateway_method_active(PDO $pdo, ?int $countryId = null): bool
{
    foreach (orange_payment_methods_for_country($pdo, $countryId) as $m) {
        if (($m['method'] ?? '') === 'gateway') {
            return true;
        }
    }

    return false;
}

/** هل البوابة جاهزة فعلياً (مفعّلة per دولة + مُعدّة في env)؟ */
function orange_payment_gateway_ready(PDO $pdo, ?int $countryId = null): bool
{
    if (!orange_payment_gateway_method_active($pdo, $countryId)) {
        return false;
    }
    $provider = orange_payment_gateway_default_provider();
    $cfg = orange_payment_gateway_config($provider);

    return orange_payment_gateway_is_configured($provider, $cfg);
}

function orange_payment_gateway_is_configured(string $provider, array $cfg): bool
{
    $fn = 'orange_gateway_' . $provider . '_is_configured';
    if (function_exists($fn)) {
        return (bool) $fn($cfg);
    }
    /* تحميل الموصِّل عند الطلب. */
    $file = __DIR__ . '/gateway_' . preg_replace('/[^a-z0-9_]/', '', $provider) . '.php';
    if (is_file($file)) {
        require_once $file;
        if (function_exists($fn)) {
            return (bool) $fn($cfg);
        }
    }

    return false;
}

/** مرجع idempotency موحّد لحركة بوابة. */
function orange_payment_gateway_txn_uuid(string $provider, string $providerRef): string
{
    return 'gw_' . preg_replace('/[^a-z0-9_]/', '', strtolower($provider)) . '_' . substr(md5($providerRef), 0, 24);
}

/**
 * تسوية دفعة بوابة مؤكَّدة خادمياً (idempotent): تسجيل الحركة + وضع الطلب paid + نقطة GL.
 * يُستدعى **فقط** بعد verify ناجح بحالة paid.
 *
 * @param array{status:string,amount:float,currency:string,raw:array} $verify
 * @return array{ok:bool,already:bool,message:string}
 */
function orange_payment_gateway_settle(PDO $pdo, int $orderId, ?int $countryId, string $provider, string $providerRef, array $verify): array
{
    if ($orderId <= 0 || ($verify['status'] ?? '') !== 'paid') {
        return ['ok' => false, 'already' => false, 'message' => 'غير مدفوع'];
    }
    $amount = round((float) ($verify['amount'] ?? 0), 4);
    $txnUuid = orange_payment_gateway_txn_uuid($provider, $providerRef);

    $sel = $pdo->prepare('SELECT id, status FROM payment_transactions WHERE txn_uuid = ? LIMIT 1');
    $sel->execute([$txnUuid]);
    $existing = $sel->fetch(PDO::FETCH_ASSOC) ?: [];
    $alreadyPaid = $existing && ($existing['status'] ?? '') === 'paid';

    orange_payment_record_transaction($pdo, [
        'order_id' => $orderId,
        'country_id' => $countryId,
        'method' => 'gateway',
        'provider' => $provider,
        'amount' => $amount,
        'currency' => (string) ($verify['currency'] ?? ''),
        'status' => 'paid',
        'provider_ref' => $providerRef,
        'txn_uuid' => $txnUuid,
        'raw_payload' => json_encode($verify['raw'] ?? [], JSON_UNESCAPED_UNICODE),
    ]);
    /* لو كانت الحركة سابقاً غير paid، حدّثها (record_transaction لا يحدّث الموجود). */
    if ($existing && !$alreadyPaid) {
        $pdo->prepare('UPDATE payment_transactions SET status = ?, amount = ? WHERE txn_uuid = ?')
            ->execute(['paid', $amount, $txnUuid]);
    }

    if (!$alreadyPaid) {
        orange_payment_set_order_status($pdo, $orderId, 'paid', 'gateway', $amount > 0 ? $amount : null);
        /* نقطة ربط GL (تُوصَّل بقرار حساب التحصيل per دولة — راجع ORANGE_ONLINE_PAYMENT_READINESS). */
        orange_payment_post_receipt_gl_hook($pdo, $orderId, $amount, $countryId, 'gateway');
    }

    return ['ok' => true, 'already' => $alreadyPaid, 'message' => $alreadyPaid ? 'مؤكَّد مسبقاً' : 'تم تأكيد الدفع'];
}

/** تحميل موصِّل المزوّد. يعيد اسم البادئة أو null. */
function orange_payment_gateway_load(string $provider): ?string
{
    $provider = strtolower(preg_replace('/[^a-z0-9_]/', '', $provider));
    if ($provider === '') {
        return null;
    }
    $file = __DIR__ . '/gateway_' . $provider . '.php';
    if (!is_file($file)) {
        return null;
    }
    require_once $file;

    return 'orange_gateway_' . $provider;
}
