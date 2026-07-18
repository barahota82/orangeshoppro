<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/storefront_account.php';
require_once __DIR__ . '/../../includes/phone_validation.php';
require_once __DIR__ . '/../../includes/orange_mail.php';
require_once __DIR__ . '/../../includes/backup/restore/restore_maintenance_enforcement.php';

orange_restore_maint_enforcement_api_mutation_guard('application_write_api');

/**
 * إخفاء البريد للعرض في الواجهة (مثل: ab***@domain.com).
 */
function orange_checkout_mask_email(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '' || !str_contains($email, '@')) {
        return '***';
    }
    $parts = explode('@', $email, 2);
    $local = (string) ($parts[0] ?? '');
    $domain = (string) ($parts[1] ?? '');
    if ($domain === '') {
        return '***';
    }
    if (strlen($local) <= 1) {
        return '*@' . $domain;
    }

    return substr($local, 0, 2) . '***@' . $domain;
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_storefront_checkout_otp_columns_ready($pdo)) {
        json_response([
            'success' => false,
            'code' => 'otp_service_unavailable',
            'message' => t('checkout_otp_service_unavailable'),
        ], 503);
    }

    $data = get_json_input();
    orange_storefront_apply_lang_from_payload($data);

    $phoneCountry = orange_storefront_parse_api_phone_country((string) ($data['phone_country'] ?? ''));
    if (!$phoneCountry['full_intl'] && $phoneCountry['dial'] === '') {
        json_response([
            'success' => false,
            'code' => 'phone_country_required',
            'message' => t('phone_country_required'),
        ], 422);
    }
    $phoneRaw = trim((string) ($data['phone'] ?? ''));
    $dialForNational = $phoneCountry['full_intl'] ? null : $phoneCountry['dial'];
    $phoneNorm = orange_normalize_customer_phone($phoneRaw, $dialForNational, $phoneCountry['full_intl']);
    if ($phoneNorm === null) {
        json_response([
            'success' => false,
            'code' => 'invalid_phone',
            'message' => t('checkout_invalid_phone'),
        ], 422);
    }

    $countryId = orange_storefront_current_country_id($pdo);
    $account = orange_storefront_verified_account_by_phone($pdo, $phoneNorm, $countryId);
    if ($account === null || empty($account['email']) || !filter_var((string) $account['email'], FILTER_VALIDATE_EMAIL)) {
        json_response([
            'success' => false,
            'code' => 'otp_account_not_found',
            'message' => t('checkout_otp_account_not_found'),
        ], 404);
    }
    $accountId = (int) ($account['id'] ?? 0);
    $accountEmail = strtolower(trim((string) ($account['email'] ?? '')));
    if ($accountId <= 0 || $accountEmail === '') {
        json_response([
            'success' => false,
            'code' => 'otp_account_not_found',
            'message' => t('checkout_otp_account_not_found'),
        ], 404);
    }

    $stateSt = $pdo->prepare('SELECT otp_sent_at FROM storefront_accounts WHERE id = ? LIMIT 1');
    $stateSt->execute([$accountId]);
    $stateRow = $stateSt->fetch(PDO::FETCH_ASSOC) ?: [];
    $cooldownSeconds = 60;
    $sentAt = isset($stateRow['otp_sent_at']) ? trim((string) $stateRow['otp_sent_at']) : '';
    if ($sentAt !== '') {
        $sentTs = strtotime($sentAt);
        if ($sentTs !== false) {
            $elapsed = time() - $sentTs;
            $remaining = $cooldownSeconds - $elapsed;
            if ($remaining > 0) {
                json_response([
                    'success' => true,
                    'cooldown' => true,
                    'cooldown_seconds' => $remaining,
                    'masked_email' => orange_checkout_mask_email($accountEmail),
                    'message' => t('checkout_otp_cooldown'),
                ]);
            }
        }
    }

    $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash = orange_storefront_checkout_otp_hash($accountId, $phoneNorm, $otpCode);
    $expiryMinutes = 10;
    $up = $pdo->prepare(
        'UPDATE storefront_accounts
         SET otp_hash = ?,
             otp_expires_at = DATE_ADD(NOW(), INTERVAL ' . $expiryMinutes . ' MINUTE),
             otp_sent_at = NOW(),
             otp_attempts = 0,
             otp_phone = ?
         WHERE id = ? LIMIT 1'
    );
    $up->execute([$otpHash, $phoneNorm, $accountId]);

    $subject = 'Orange checkout OTP / رمز تحقق Orange';
    $body = "English:\n"
        . "Your checkout OTP code is: {$otpCode}\n"
        . "This code expires in {$expiryMinutes} minutes.\n\n"
        . "---\n"
        . "العربية:\n"
        . "رمز التحقق لإتمام الطلب هو: {$otpCode}\n"
        . "صلاحية الرمز {$expiryMinutes} دقائق.\n";

    if (!orange_mail_send_text($accountEmail, $subject, $body)) {
        orange_storefront_checkout_otp_clear($pdo, $accountId);
        json_response([
            'success' => false,
            'code' => 'otp_send_failed',
            'message' => t('checkout_otp_send_failed'),
        ], 500);
    }

    json_response([
        'success' => true,
        'cooldown' => false,
        'cooldown_seconds' => $cooldownSeconds,
        'expires_in_seconds' => $expiryMinutes * 60,
        'masked_email' => orange_checkout_mask_email($accountEmail),
        'message' => t('checkout_otp_sent'),
    ]);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}

