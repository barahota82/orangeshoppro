<?php

declare(strict_types=1);

/**
 * أساس الدفع الإلكتروني — نواة موحّدة (المرحلة 0). مُعطّل افتراضياً.
 * يُعيد استخدامه موصِّلا التحويل البنكي (م1) والبوابة (م2) لاحقاً.
 * @see docs/archive/ORANGE_ONLINE_PAYMENT_READINESS.txt
 */

require_once __DIR__ . '/payment_schema.php';
require_once __DIR__ . '/../catalog_schema.php';

/** @return list<string> */
function orange_payment_statuses(): array
{
    return ['unpaid', 'pending_review', 'paid', 'failed', 'refunded'];
}

function orange_payment_status_is_valid(string $status): bool
{
    return in_array($status, orange_payment_statuses(), true);
}

/** @return array<string,string> */
function orange_payment_status_labels(): array
{
    return [
        'unpaid' => 'غير مدفوع',
        'pending_review' => 'بانتظار تأكيد الدفع',
        'paid' => 'مدفوع',
        'failed' => 'فشل الدفع',
        'refunded' => 'مُسترَد',
    ];
}

function orange_payment_status_label(string $status): string
{
    return orange_payment_status_labels()[$status] ?? $status;
}

/**
 * المفتاح الرئيسي للتشغيل (موجود) — يبقى المرجع لتفعيل الدفع أونلاين.
 */
function orange_payment_online_master_enabled(PDO $pdo, ?int $countryId = null): bool
{
    if (!function_exists('orange_storefront_payment_online_enabled')) {
        $f = __DIR__ . '/../storefront_payment_settings.php';
        if (is_file($f)) {
            require_once $f;
        }
    }
    if (function_exists('orange_storefront_payment_online_enabled')) {
        return (bool) orange_storefront_payment_online_enabled($pdo, $countryId, false);
    }

    return false;
}

/**
 * طرق الدفع النشطة لدولة (الافتراضي: لا شيء — الدفع مُعطّل حتى تُفعَّل طريقة).
 *
 * @return list<array{method:string,provider:string,sort_order:int}>
 */
function orange_payment_methods_for_country(PDO $pdo, ?int $countryId = null): array
{
    orange_payments_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'payment_methods')) {
        return [];
    }
    $cid = $countryId !== null && $countryId > 0 ? $countryId : 0;
    if ($cid <= 0 && function_exists('orange_admin_context_country_id')) {
        $cid = (int) orange_admin_context_country_id($pdo);
    }
    $st = $pdo->prepare(
        'SELECT method, provider, sort_order FROM payment_methods
         WHERE is_active = 1 AND (country_id = ? OR country_id IS NULL)
         ORDER BY sort_order ASC, id ASC'
    );
    $st->execute([$cid]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'method' => (string) ($r['method'] ?? ''),
            'provider' => (string) ($r['provider'] ?? ''),
            'sort_order' => (int) ($r['sort_order'] ?? 0),
        ];
    }

    return $out;
}

/**
 * تسجيل حركة دفع مع idempotency عبر txn_uuid (لا ازدواج).
 * يُرجع ['created'=>bool, 'id'=>int] — created=false إن كانت الحركة مسجّلة مسبقاً.
 *
 * @param array{order_id?:int,country_id?:int,method?:string,provider?:string,amount?:float,currency?:string,status?:string,provider_ref?:string,txn_uuid?:string,raw_payload?:string} $data
 * @return array{created:bool,id:int}
 */
function orange_payment_record_transaction(PDO $pdo, array $data): array
{
    orange_payments_ensure_schema($pdo);
    $txnUuid = trim((string) ($data['txn_uuid'] ?? ''));
    if ($txnUuid === '') {
        $txnUuid = 'pt_' . bin2hex(random_bytes(12));
    }

    $sel = $pdo->prepare('SELECT id FROM payment_transactions WHERE txn_uuid = ? LIMIT 1');
    $sel->execute([$txnUuid]);
    $existingId = (int) ($sel->fetchColumn() ?: 0);
    if ($existingId > 0) {
        return ['created' => false, 'id' => $existingId];
    }

    $status = (string) ($data['status'] ?? 'pending');
    $st = $pdo->prepare(
        'INSERT INTO payment_transactions
            (order_id, country_id, method, provider, amount, currency, status, provider_ref, txn_uuid, raw_payload)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $st->execute([
        (int) ($data['order_id'] ?? 0) ?: null,
        (int) ($data['country_id'] ?? 0) ?: null,
        (string) ($data['method'] ?? ''),
        (string) ($data['provider'] ?? ''),
        round((float) ($data['amount'] ?? 0), 4),
        (string) ($data['currency'] ?? ''),
        $status,
        (string) ($data['provider_ref'] ?? ''),
        $txnUuid,
        isset($data['raw_payload']) ? (string) $data['raw_payload'] : null,
    ]);

    return ['created' => true, 'id' => (int) $pdo->lastInsertId()];
}

/**
 * تحديث حالة دفع الطلب (idempotent على مستوى الحركة). لا يُرحّل GL هنا —
 * ترحيل قيد التحصيل يُوصَّل عند تفعيل م1/م2 عبر orange_payment_post_receipt_gl_hook().
 *
 * @return bool true إذا غُيّرت الحالة فعلياً
 */
function orange_payment_set_order_status(
    PDO $pdo,
    int $orderId,
    string $status,
    ?string $method = null,
    ?float $amountPaid = null
): bool {
    orange_payments_ensure_schema($pdo);
    if ($orderId <= 0 || !orange_payment_status_is_valid($status)) {
        return false;
    }
    if (!orange_table_has_column($pdo, 'orders', 'payment_status')) {
        return false;
    }
    $sets = ['payment_status = ?'];
    $params = [$status];
    if ($method !== null && orange_table_has_column($pdo, 'orders', 'payment_method')) {
        $sets[] = 'payment_method = ?';
        $params[] = $method;
    }
    if ($amountPaid !== null && orange_table_has_column($pdo, 'orders', 'amount_paid')) {
        $sets[] = 'amount_paid = ?';
        $params[] = round($amountPaid, 4);
    }
    if ($status === 'paid' && orange_table_has_column($pdo, 'orders', 'paid_at')) {
        $sets[] = 'paid_at = NOW()';
    }
    $params[] = $orderId;
    $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

    return true;
}

/**
 * نقطة ربط ترحيل قيد التحصيل (GL) عند الدفع المؤكَّد — **غير مفعّلة في المرحلة 0**.
 * تُنفَّذ عند تفعيل م1/م2 (تحتاج قرار حساب البنك/الخزينة per دولة — راجع المخطط).
 */
function orange_payment_post_receipt_gl_hook(PDO $pdo, int $orderId, float $amount, ?int $countryId, string $method): bool
{
    /* م0: لا ترحيل GL تلقائي — يُوصَّل بمنطق سند القبض عند التفعيل (account mapping per country). */
    return false;
}
