<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/storefront_account.php';
require_once __DIR__ . '/../../includes/orange_mail.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'storefront_accounts')) {
        json_response(['success' => false, 'message' => 'Service unavailable'], 503);
    }

    $data = get_json_input();
    $rawEmail = isset($data['email']) ? (string) $data['email'] : '';
    $email = orange_storefront_normalize_email($rawEmail);
    if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['success' => false, 'message' => 'Invalid email'], 422);
    }

    $nameRaw = isset($data['name']) ? trim((string) $data['name']) : '';
    $phoneRaw = isset($data['phone']) ? trim((string) $data['phone']) : '';
    $areaRaw = isset($data['area']) ? trim((string) $data['area']) : '';
    $addressRaw = isset($data['address']) ? trim((string) $data['address']) : '';
    $notesRaw = isset($data['notes']) ? trim((string) $data['notes']) : '';
    if ($nameRaw === '' || $phoneRaw === '' || $areaRaw === '' || $addressRaw === '') {
        json_response(['success' => false, 'message' => 'Missing required fields'], 422);
    }
    $digits = preg_replace('/\D+/', '', $phoneRaw) ?? '';
    if (strlen($digits) < 5) {
        json_response(['success' => false, 'message' => 'Invalid phone'], 422);
    }

    $customerName = orange_storefront_clip_utf8($nameRaw, 255);
    $customerPhone = orange_storefront_clip_utf8($phoneRaw, 64);
    $customerArea = orange_storefront_clip_utf8($areaRaw, 255);
    $customerAddress = orange_storefront_clip_utf8($addressRaw, 4000);
    $customerNotes = $notesRaw === '' ? '' : orange_storefront_clip_utf8($notesRaw, 4000);

    $st = $pdo->prepare(
        'SELECT id, email_verified_at, verify_email_sent_at, registered_channel_slug FROM storefront_accounts WHERE email = ? LIMIT 1'
    );
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if ($row && !empty($row['email_verified_at'])) {
        $chSlug = orange_storefront_valid_channel_slug(
            $pdo,
            (string) ($row['registered_channel_slug'] ?? '')
        );
        json_response([
            'success' => true,
            'message' => 'ok',
            'already_verified' => true,
            'channel' => $chSlug,
        ]);
    }

    $sentAt = $row['verify_email_sent_at'] ?? null;
    if ($row && $sentAt !== null && $sentAt !== '' && strtotime((string) $sentAt) > time() - 60) {
        json_response(['success' => true, 'message' => 'ok', 'cooldown' => true]);
    }

    $channelSlug = isset($data['channel']) ? (string) $data['channel'] : '';
    $channelSlug = orange_storefront_valid_channel_slug($pdo, $channelSlug);
    $lang = isset($data['lang']) ? (string) $data['lang'] : 'en';
    if (!preg_match('/^(en|ar|fil|hi)$/', $lang)) {
        $lang = 'en';
    }

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);

    if (!$row) {
        $ins = $pdo->prepare(
            'INSERT INTO storefront_accounts (email, registered_channel_slug, customer_name, customer_phone, customer_area, customer_address, customer_notes, verify_token_hash, verify_token_expires_at, verify_email_sent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 48 HOUR), NOW())'
        );
        $ins->execute([
            $email,
            $channelSlug,
            $customerName,
            $customerPhone,
            $customerArea,
            $customerAddress,
            $customerNotes,
            $hash,
        ]);
    } else {
        $upd = $pdo->prepare(
            'UPDATE storefront_accounts SET verify_token_hash = ?, verify_token_expires_at = DATE_ADD(NOW(), INTERVAL 48 HOUR), verify_email_sent_at = NOW(),
             registered_channel_slug = COALESCE(registered_channel_slug, ?),
             customer_name = ?, customer_phone = ?, customer_area = ?, customer_address = ?, customer_notes = ?
             WHERE id = ? AND email_verified_at IS NULL'
        );
        $upd->execute([
            $hash,
            $channelSlug,
            $customerName,
            $customerPhone,
            $customerArea,
            $customerAddress,
            $customerNotes,
            (int) $row['id'],
        ]);
    }

    $rel = storefront_url('verify_email', $channelSlug, $lang, ['token' => $token]);
    $link = orange_site_public_origin() . $rel;

    $subjEn = 'Confirm your registration — Orange';
    $subjAr = 'تأكيد التسجيل — Orange';
    $body = "English:\nConfirm your email by opening this link (valid 48 hours):\n{$link}\n\n---\nالعربية:\nلتأكيد بريدك افتح الرابط (صالح 48 ساعة):\n{$link}\n";

    if (!orange_mail_send_text($email, $subjEn . ' / ' . $subjAr, $body)) {
        if (function_exists('error_log')) {
            error_log('[orange] request-email-verify: mail() failed for ' . $email);
        }
        json_response(['success' => false, 'message' => 'Email could not be sent. Ask the store to configure MAIL_FROM in .env.php.'], 500);
    }

    json_response(['success' => true, 'message' => 'ok']);
} catch (Throwable $e) {
    api_error($e, 'Request failed');
}
