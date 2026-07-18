<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/storefront_phone_merge.php';
require_once __DIR__ . '/../../includes/storefront_account.php';
require_once __DIR__ . '/../../includes/backup/restore/restore_maintenance_enforcement.php';

orange_restore_maint_enforcement_api_mutation_guard('application_write_api');

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    orange_storefront_apply_lang_from_payload($data);
    $token = trim((string) ($data['merge_token'] ?? ''));
    $emailRaw = trim((string) ($data['email'] ?? ''));
    $email = orange_storefront_normalize_email($emailRaw);
    if ($email === null) {
        json_response(['success' => false, 'code' => 'invalid_email', 'message' => t('checkout_invalid_email')], 422);
    }
    try {
        orange_storefront_apply_phone_merge_profile($pdo, $token, $email);
    } catch (RuntimeException $e) {
        $m = $e->getMessage();
        if ($m === 'wa_not_confirmed') {
            json_response(['success' => false, 'code' => 'merge_wa_not_confirmed', 'message' => t('storefront_merge_wa_not_confirmed')], 422);
        }
        if ($m === 'email_mismatch') {
            json_response(['success' => false, 'code' => 'merge_email_mismatch', 'message' => t('storefront_merge_email_mismatch')], 422);
        }
        if ($m === 'invalid_or_expired_token' || $m === 'missing_token') {
            json_response(['success' => false, 'code' => 'merge_invalid_token', 'message' => t('storefront_merge_invalid_token')], 422);
        }
        json_response(['success' => false, 'code' => 'merge_failed', 'message' => t('storefront_merge_apply_err')], 422);
    }
    json_response(['success' => true, 'message' => t('storefront_merge_apply_ok')]);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
