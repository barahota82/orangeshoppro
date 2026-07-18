<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/storefront_account.php';
require_once __DIR__ . '/../../includes/phone_validation.php';
require_once __DIR__ . '/../../includes/backup/restore/restore_maintenance_enforcement.php';

orange_restore_maint_enforcement_api_mutation_guard('application_write_api');

function orange_checkout_mask_email_verify(string $email): string
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

    $otpCode = preg_replace('/\D+/', '', (string) ($data['otp'] ?? ''));
    if (!is_string($otpCode) || strlen($otpCode) !== 6) {
        json_response([
            'success' => false,
            'code' => 'otp_invalid_format',
            'message' => t('checkout_otp_invalid'),
        ], 422);
    }

    $countryId = orange_storefront_current_country_id($pdo);
    $account = orange_storefront_verified_account_by_phone($pdo, $phoneNorm, $countryId);
    if ($account === null || empty($account['email'])) {
        json_response([
            'success' => false,
            'code' => 'otp_account_not_found',
            'message' => t('checkout_otp_account_not_found'),
        ], 404);
    }
    $accountId = (int) ($account['id'] ?? 0);
    $accountEmail = strtolower(trim((string) ($account['email'] ?? '')));
    if ($accountId <= 0) {
        json_response([
            'success' => false,
            'code' => 'otp_account_not_found',
            'message' => t('checkout_otp_account_not_found'),
        ], 404);
    }

    $otpSt = $pdo->prepare(
        'SELECT otp_hash, otp_expires_at, otp_attempts, otp_phone
         FROM storefront_accounts
         WHERE id = ? LIMIT 1'
    );
    $otpSt->execute([$accountId]);
    $otpRow = $otpSt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($otpRow)) {
        json_response([
            'success' => false,
            'code' => 'otp_not_requested',
            'message' => t('checkout_otp_not_requested'),
        ], 422);
    }

    $storedHash = trim((string) ($otpRow['otp_hash'] ?? ''));
    $storedPhone = trim((string) ($otpRow['otp_phone'] ?? ''));
    $expiresAtRaw = trim((string) ($otpRow['otp_expires_at'] ?? ''));
    $attempts = max(0, (int) ($otpRow['otp_attempts'] ?? 0));
    $maxAttempts = 5;
    if ($storedHash === '' || $storedPhone === '' || $expiresAtRaw === '' || $storedPhone !== $phoneNorm) {
        json_response([
            'success' => false,
            'code' => 'otp_not_requested',
            'message' => t('checkout_otp_not_requested'),
        ], 422);
    }

    if ($attempts >= $maxAttempts) {
        orange_storefront_checkout_otp_clear($pdo, $accountId);
        json_response([
            'success' => false,
            'code' => 'otp_max_attempts',
            'message' => t('checkout_otp_max_attempts'),
        ], 422);
    }

    $expiresTs = strtotime($expiresAtRaw);
    if ($expiresTs === false || $expiresTs < time()) {
        orange_storefront_checkout_otp_clear($pdo, $accountId);
        json_response([
            'success' => false,
            'code' => 'otp_expired',
            'message' => t('checkout_otp_expired'),
        ], 422);
    }

    $incomingHash = orange_storefront_checkout_otp_hash($accountId, $phoneNorm, $otpCode);
    if (!hash_equals($storedHash, $incomingHash)) {
        $nextAttempts = $attempts + 1;
        if ($nextAttempts >= $maxAttempts) {
            orange_storefront_checkout_otp_clear($pdo, $accountId);
            json_response([
                'success' => false,
                'code' => 'otp_max_attempts',
                'message' => t('checkout_otp_max_attempts'),
                'attempts_left' => 0,
            ], 422);
        }
        $upAttempts = $pdo->prepare(
            'UPDATE storefront_accounts SET otp_attempts = ? WHERE id = ? LIMIT 1'
        );
        $upAttempts->execute([$nextAttempts, $accountId]);
        json_response([
            'success' => false,
            'code' => 'otp_invalid',
            'message' => t('checkout_otp_invalid'),
            'attempts_left' => max(0, $maxAttempts - $nextAttempts),
        ], 422);
    }

    storefront_account_login($pdo, $accountId);
    orange_storefront_checkout_otp_clear($pdo, $accountId);

    json_response([
        'success' => true,
        'message' => t('checkout_otp_verified'),
        'account' => [
            'id' => $accountId,
            'masked_email' => orange_checkout_mask_email_verify($accountEmail),
        ],
    ]);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}

