<?php

declare(strict_types=1);

/**
 * س13: بعد التتبع — إرسال تفاصيل الطلب كاملة بالبريد (بعد التحقق من رقم الطلب + الهاتف).
 * لا ينشئ حساباً؛ مكافئ لملحق البريد في التسجيل من شاشة التتبع.
 *
 * FSR-SEC-03: session layer + shared server-side IP+subject throttle (et_mail).
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/order_helpers.php';
require_once __DIR__ . '/../../includes/phone_validation.php';
require_once __DIR__ . '/../../includes/storefront_order_email.php';
require_once __DIR__ . '/../../includes/orange_mail.php';
require_once __DIR__ . '/../../includes/storefront_email_track_rate_limit.php';
require_once __DIR__ . '/../../includes/backup/restore/restore_maintenance_enforcement.php';

orange_restore_maint_enforcement_api_mutation_guard('application_write_api');

/**
 * Generic non-enumerating throttle response (no Order existence signal).
 */
function orange_email_track_rate_limited_exit(int $retryAfterSeconds): void
{
    if ($retryAfterSeconds > 0) {
        header('Retry-After: ' . (string) $retryAfterSeconds);
    }
    json_response([
        'success' => false,
        'code' => 'email_track_rate_limited',
        'message' => t('track_email_summary_rate_limit'),
    ], 429);
}

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        json_response(['success' => false, 'code' => 'post_required', 'message' => t('api_post_only')], 405);
    }

    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $data = get_json_input();
    orange_storefront_apply_lang_from_payload($data);

    $orderNumber = trim((string) ($data['order_number'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $rawEmail = isset($data['email']) ? trim((string) $data['email']) : '';

    if ($orderNumber === '' || $phone === '') {
        json_response(['success' => false, 'code' => 'invalid_input', 'message' => t('track_missing_fields')], 422);
    }

    $email = strtolower($rawEmail);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['success' => false, 'code' => 'invalid_email', 'message' => t('checkout_invalid_email')], 422);
    }

    $phoneNorm = orange_normalize_customer_phone($phone, null);
    if ($phoneNorm === null) {
        json_response(['success' => false, 'code' => 'invalid_phone', 'message' => t('checkout_invalid_phone')], 422);
    }

    $sfCountryId = 0;
    if (function_exists('orange_storefront_current_country_id')) {
        $sfCountryId = (int) orange_storefront_current_country_id($pdo);
    }

    $clientIp = orange_email_track_client_ip();
    $subjectFp = orange_email_track_rate_limit_subject_fingerprint(
        $orderNumber,
        $phoneNorm,
        $email,
        $sfCountryId
    );

    $sessionGate = orange_email_track_session_rate_limit_check($orderNumber, $phoneNorm, $email);
    if (!$sessionGate['allowed']) {
        orange_email_track_rate_limited_exit((int) $sessionGate['retry_after_seconds']);
    }

    try {
        $sharedGate = orange_email_track_rate_limit_consume($pdo, $clientIp, $subjectFp);
    } catch (RuntimeException $e) {
        if ((string) $e->getMessage() === 'email_track_limiter_unavailable') {
            if (function_exists('error_log')) {
                error_log('[orange] email-track-order-summary: shared limiter unavailable');
            }
            json_response([
                'success' => false,
                'code' => 'email_track_limiter_unavailable',
                'message' => t('track_email_summary_err'),
            ], 503);
        }
        throw $e;
    }
    if (!$sharedGate['allowed']) {
        orange_email_track_rate_limited_exit((int) $sharedGate['retry_after_seconds']);
    }

    orange_email_track_session_rate_limit_record($orderNumber, $phoneNorm, $email);

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1');
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || !orange_order_phones_match_for_lookup($phoneNorm, (string) ($order['phone'] ?? ''))) {
        json_response(['success' => false, 'code' => 'not_found', 'message' => t('track_order_not_found')], 404);
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
    $itemsStmt->execute([(int) $order['id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $subjEn = 'Your order details — Orange';
    $subjAr = 'تفاصيل طلبك — Orange';
    $body = "English:\nBelow is a copy of your order details after tracking.\n\n---\nالعربية:\nفيما يلي نسخة من تفاصيل طلبك بعد التتبع.\n";
    $body .= orange_storefront_order_details_bilingual_email_appendix($order, $items);

    if (!orange_mail_send_text($email, $subjEn . ' / ' . $subjAr, $body)) {
        if (function_exists('error_log')) {
            error_log('[orange] email-track-order-summary: mail() failed');
        }
        json_response([
            'success' => false,
            'code' => 'mail_failed',
            'message' => t('storefront_register_mail_failed'),
        ], 500);
    }

    json_response(['success' => true, 'message' => t('track_email_summary_ok')]);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
