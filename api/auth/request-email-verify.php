<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/order_helpers.php';
require_once __DIR__ . '/../../includes/storefront_account.php';
require_once __DIR__ . '/../../includes/orange_mail.php';
require_once __DIR__ . '/../../includes/phone_validation.php';
require_once __DIR__ . '/../../includes/delivery_areas.php';
require_once __DIR__ . '/../../includes/storefront_order_email.php';
require_once __DIR__ . '/../../includes/countries.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'storefront_accounts')) {
        json_response(['success' => false, 'code' => 'service_unavailable', 'message' => t('storefront_register_service_unavailable')], 503);
    }

    $data = get_json_input();
    orange_storefront_apply_lang_from_payload($data);
    $lang = isset($data['lang']) ? (string) $data['lang'] : 'en';
    if (!preg_match('/^(en|ar|fil|hi)$/', $lang)) {
        $lang = 'en';
    }

    $customerDaId = null;
    $rawEmail = isset($data['email']) ? (string) $data['email'] : '';
    $email = orange_storefront_normalize_email($rawEmail);
    if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['success' => false, 'code' => 'invalid_email', 'message' => t('checkout_invalid_email')], 422);
    }

    $orderNumberLink = isset($data['order_number']) ? trim((string) $data['order_number']) : '';
    $orderVerifyPhone = isset($data['order_verify_phone']) ? trim((string) $data['order_verify_phone']) : '';
    $hasOrderNum = $orderNumberLink !== '';
    $hasVerifyPh = $orderVerifyPhone !== '';
    if ($hasOrderNum !== $hasVerifyPh) {
        json_response(['success' => false, 'code' => 'order_link_incomplete', 'message' => t('track_signup_order_required')], 422);
    }
    $trackCtx = $hasOrderNum && $hasVerifyPh;

    $customerPhoneCountryDial = null;
    $customerPhoneNational = null;

    $nameRaw = isset($data['name']) ? trim((string) $data['name']) : '';
    $phoneRaw = isset($data['phone']) ? trim((string) $data['phone']) : '';
    $areaRaw = isset($data['area']) ? trim((string) $data['area']) : '';
    $addressRaw = isset($data['address']) ? trim((string) $data['address']) : '';
    $notesRaw = isset($data['notes']) ? trim((string) $data['notes']) : '';

    $channelSlug = isset($data['channel']) ? (string) $data['channel'] : '';
    $channelSlug = orange_storefront_valid_channel_slug($pdo, $channelSlug);

    $sfCountryId = orange_storefront_current_country_id($pdo);
    if ($sfCountryId > 0 && orange_channels_has_country_column($pdo)) {
        $channelRowSt = $pdo->prepare('SELECT id FROM channels WHERE slug = ? AND is_active = 1 AND country_id = ? LIMIT 1');
        $channelRowSt->execute([$channelSlug, $sfCountryId]);
    } else {
        $channelRowSt = $pdo->prepare('SELECT id FROM channels WHERE slug = ? AND is_active = 1 LIMIT 1');
        $channelRowSt->execute([$channelSlug]);
    }
    $channelIdForCountry = (int) ($channelRowSt->fetchColumn() ?: 0);
    $accountCountryId = orange_country_id_for_channel($pdo, $channelIdForCountry);
    $hasAccountCountryCol = orange_table_has_column($pdo, 'storefront_accounts', 'country_id');

    /** @var list<array<string, mixed>>|null */
    $trackSignupItemsForMail = null;

    if ($trackCtx) {
        $vphNorm = orange_normalize_customer_phone($orderVerifyPhone, null);
        if ($vphNorm === null) {
            json_response(['success' => false, 'code' => 'invalid_phone', 'message' => t('checkout_invalid_phone')], 422);
        }
        $orderVerifyPhone = $vphNorm;
        $ost = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1');
        $ost->execute([$orderNumberLink]);
        $orderRow = $ost->fetch(PDO::FETCH_ASSOC);
        if (!$orderRow) {
            json_response(['success' => false, 'code' => 'order_not_found', 'message' => t('track_order_not_found')], 404);
        }
        if ($hasAccountCountryCol) {
            $oc = (int) ($orderRow['country_id'] ?? 0);
            if ($oc > 0) {
                $accountCountryId = $oc;
            }
        }
        if (!orange_order_phones_match_for_lookup($orderVerifyPhone, (string) ($orderRow['phone'] ?? ''))) {
            json_response(['success' => false, 'code' => 'order_link_mismatch', 'message' => t('track_signup_order_mismatch')], 404);
        }
        $itemsMailStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
        $itemsMailStmt->execute([(int) ($orderRow['id'] ?? 0)]);
        $trackSignupItemsForMail = $itemsMailStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($phoneRaw !== '') {
            $regCc2Parsed = orange_storefront_parse_api_phone_country(trim((string) ($data['phone_country'] ?? '')));
            if (!$regCc2Parsed['full_intl'] && $regCc2Parsed['dial'] === '') {
                json_response(['success' => false, 'code' => 'phone_country_required', 'message' => t('phone_country_required')], 422);
            }
            $prNorm = orange_normalize_customer_phone(
                $phoneRaw,
                $regCc2Parsed['full_intl'] ? null : $regCc2Parsed['dial'],
                $regCc2Parsed['full_intl']
            );
            if ($prNorm === null || !orange_order_phones_match_for_lookup($prNorm, (string) ($orderRow['phone'] ?? ''))) {
                json_response(['success' => false, 'code' => 'signup_phone_mismatch', 'message' => t('track_signup_order_mismatch')], 422);
            }
        }

        $oName = trim((string) ($orderRow['customer_name'] ?? ''));
        $oArea = trim((string) ($orderRow['area'] ?? ''));
        $oAddress = trim((string) ($orderRow['address'] ?? ''));
        $oNotes = trim((string) ($orderRow['notes'] ?? ''));
        $oDaId = isset($orderRow['delivery_area_id']) ? (int) $orderRow['delivery_area_id'] : 0;

        try {
            $resAr = orange_storefront_resolve_registration_area(
                $pdo,
                $data,
                $lang,
                $areaRaw !== '' ? $areaRaw : $oArea,
                $oDaId > 0 ? $oDaId : null
            );
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'code' => 'invalid_delivery_area', 'message' => $e->getMessage()], 422);
        }
        $customerArea = orange_storefront_clip_utf8($resAr['area'], 255);
        $customerDaId = $resAr['delivery_area_id'];

        $customerName = $nameRaw !== '' ? orange_storefront_clip_utf8($nameRaw, 255) : orange_storefront_clip_utf8($oName, 255);
        $customerAddress = $addressRaw !== '' ? orange_storefront_clip_utf8($addressRaw, 4000) : orange_storefront_clip_utf8($oAddress, 4000);
        $customerNotes = $notesRaw !== '' ? orange_storefront_clip_utf8($notesRaw, 4000) : orange_storefront_clip_utf8($oNotes, 4000);
        $customerPhone = orange_storefront_clip_utf8(trim((string) ($orderRow['phone'] ?? '')), 64);

        if (orange_table_has_column($pdo, 'orders', 'phone_country_dial')) {
            $v = $orderRow['phone_country_dial'] ?? null;
            if ($v !== null && (string) $v !== '') {
                $d = preg_replace('/\D+/', '', (string) $v);
                $customerPhoneCountryDial = ($d !== '') ? substr($d, 0, 8) : null;
            }
        }
        if (orange_table_has_column($pdo, 'orders', 'phone_national')) {
            $v = $orderRow['phone_national'] ?? null;
            if ($v !== null && (string) $v !== '') {
                $n = preg_replace('/\D+/', '', (string) $v);
                $customerPhoneNational = ($n !== '') ? substr($n, 0, 32) : null;
            }
        }

        if ($customerName === '' || $customerArea === '' || $customerAddress === '' || $customerPhone === '') {
            json_response(['success' => false, 'code' => 'missing_fields', 'message' => t('checkout_required_fields')], 422);
        }

        orange_storefront_sync_order_and_customer_after_track_signup(
            $pdo,
            $orderRow,
            $email,
            $customerName,
            $customerArea,
            $customerAddress,
            $customerNotes,
            $customerDaId
        );

        $chId = (int) ($orderRow['channel_id'] ?? 0);
        if ($chId > 0) {
            $cst = $pdo->prepare('SELECT slug FROM channels WHERE id = ? AND is_active = 1 LIMIT 1');
            $cst->execute([$chId]);
            $slugRow = $cst->fetch(PDO::FETCH_ASSOC);
            if ($slugRow && !empty($slugRow['slug'])) {
                $channelSlug = orange_storefront_valid_channel_slug($pdo, (string) $slugRow['slug']);
            }
        }
    } else {
        if ($nameRaw === '' || $phoneRaw === '' || $addressRaw === '') {
            json_response(['success' => false, 'code' => 'missing_fields', 'message' => t('checkout_required_fields')], 422);
        }
        $regCcParsed = orange_storefront_parse_api_phone_country(trim((string) ($data['phone_country'] ?? '')));
        if (!$regCcParsed['full_intl'] && $regCcParsed['dial'] === '') {
            json_response(['success' => false, 'code' => 'phone_country_required', 'message' => t('phone_country_required')], 422);
        }
        $phoneNormReg = orange_normalize_customer_phone(
            $phoneRaw,
            $regCcParsed['full_intl'] ? null : $regCcParsed['dial'],
            $regCcParsed['full_intl']
        );
        if ($phoneNormReg === null) {
            json_response(['success' => false, 'code' => 'invalid_phone', 'message' => t('checkout_invalid_phone')], 422);
        }

        try {
            $resAr = orange_storefront_resolve_registration_area($pdo, $data, $lang, $areaRaw, null);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'code' => 'invalid_delivery_area', 'message' => $e->getMessage()], 422);
        }
        $customerArea = orange_storefront_clip_utf8($resAr['area'], 255);
        $customerDaId = $resAr['delivery_area_id'];
        if ($customerArea === '') {
            json_response(['success' => false, 'code' => 'missing_fields', 'message' => t('checkout_required_fields')], 422);
        }

        $customerName = orange_storefront_clip_utf8($nameRaw, 255);
        $customerPhone = orange_storefront_clip_utf8($phoneNormReg, 64);
        $regDialForParts = $regCcParsed['full_intl'] ? null : $regCcParsed['dial'];
        $regParts = orange_storefront_phone_storage_parts($phoneRaw, $regDialForParts);
        $customerPhoneCountryDial = $regParts['country_dial'];
        $customerPhoneNational = $regParts['national'];
        $customerAddress = orange_storefront_clip_utf8($addressRaw, 4000);
        $customerNotes = $notesRaw === '' ? '' : orange_storefront_clip_utf8($notesRaw, 4000);
    }

    if (!$trackCtx) {
        require_once __DIR__ . '/../../includes/storefront_phone_merge.php';
        $dupAcc = orange_storefront_find_verified_account_by_phone($pdo, $customerPhone);
        if ($dupAcc !== null && orange_table_exists($pdo, 'storefront_phone_merge_requests')) {
            $dupEmailRaw = (string) ($dupAcc['email'] ?? '');
            $dupEmail = orange_storefront_normalize_email($dupEmailRaw) ?? strtolower(trim($dupEmailRaw));
            if ($dupEmail !== '' && strcasecmp($email, $dupEmail) !== 0) {
                try {
                    $mergeCreated = orange_storefront_create_phone_merge_request(
                        $pdo,
                        (int) ($dupAcc['id'] ?? 0),
                        $customerPhone,
                        $email,
                        $channelSlug,
                        $customerName,
                        $customerDaId !== null && (int) $customerDaId > 0 ? (int) $customerDaId : null,
                        $customerArea,
                        $customerAddress,
                        $customerNotes,
                        $customerPhoneCountryDial,
                        $customerPhoneNational
                    );
                } catch (Throwable $e) {
                    if (function_exists('error_log')) {
                        error_log('[orange] request-email-verify phone merge: ' . $e->getMessage());
                    }
                    json_response(['success' => false, 'code' => 'server_error', 'message' => t('api_request_failed')], 500);
                }
                $chRow = get_channel_by_slug($channelSlug);
                $waTemplate = t('storefront_register_phone_merge_wa_body');
                $waText = str_replace(
                    ['{token}', '{email}', '{site}'],
                    [$mergeCreated['plain_token'], $email, orange_site_public_origin()],
                    $waTemplate
                );
                $waHref = is_array($chRow) ? storefront_whatsapp_href($chRow, $waText) : null;
                json_response([
                    'success' => true,
                    'merge_required' => true,
                    'merge_token' => $mergeCreated['plain_token'],
                    'merge_request_id' => $mergeCreated['id'],
                    'whatsapp_href' => $waHref,
                    'existing_email_masked' => orange_storefront_mask_email_for_display((string) ($dupAcc['email'] ?? '')),
                    'message' => t('storefront_register_phone_merge_intro'),
                    'channel' => $channelSlug,
                ]);
            }
        }
    }

    $st = $hasAccountCountryCol
        ? $pdo->prepare(
            'SELECT id, email_verified_at, verify_email_sent_at, registered_channel_slug
             FROM storefront_accounts WHERE email = ? AND country_id = ? LIMIT 1'
        )
        : $pdo->prepare(
            'SELECT id, email_verified_at, verify_email_sent_at, registered_channel_slug FROM storefront_accounts WHERE email = ? LIMIT 1'
        );
    if ($hasAccountCountryCol) {
        $st->execute([$email, $accountCountryId]);
    } else {
        $st->execute([$email]);
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if ($row && !empty($row['email_verified_at'])) {
        $chSlug = orange_storefront_valid_channel_slug(
            $pdo,
            (string) ($row['registered_channel_slug'] ?? '')
        );
        json_response([
            'success' => true,
            'message' => t('api_ok'),
            'already_verified' => true,
            'channel' => $chSlug,
        ]);
    }

    $sentAt = $row['verify_email_sent_at'] ?? null;
    if ($row && $sentAt !== null && $sentAt !== '' && strtotime((string) $sentAt) > time() - 60) {
        json_response(['success' => true, 'message' => t('api_ok'), 'cooldown' => true, 'channel' => $channelSlug]);
    }

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);

    $accountIdAfter = 0;
    $hasDaCol = orange_table_has_column($pdo, 'storefront_accounts', 'customer_delivery_area_id');
    $hasSfaDial = orange_table_has_column($pdo, 'storefront_accounts', 'customer_phone_country_dial');
    $hasSfaNat = orange_table_has_column($pdo, 'storefront_accounts', 'customer_phone_national');
    if (!$row) {
        if ($hasDaCol) {
            $ic = 'email, registered_channel_slug, customer_name, customer_phone';
            $iv = '?, ?, ?, ?';
            $ip = [$email, $channelSlug, $customerName, $customerPhone];
            if ($hasSfaDial) {
                $ic .= ', customer_phone_country_dial';
                $iv .= ', ?';
                $ip[] = $customerPhoneCountryDial;
            }
            if ($hasSfaNat) {
                $ic .= ', customer_phone_national';
                $iv .= ', ?';
                $ip[] = $customerPhoneNational;
            }
            if ($hasAccountCountryCol) {
                $ic .= ', country_id';
                $iv .= ', ?';
                $ip[] = $accountCountryId;
            }
            $ic .= ', customer_delivery_area_id, customer_area, customer_address, customer_notes, verify_token_hash, verify_token_expires_at, verify_email_sent_at';
            $iv .= ', ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 48 HOUR), NOW()';
            array_push(
                $ip,
                $customerDaId !== null && $customerDaId > 0 ? $customerDaId : null,
                $customerArea,
                $customerAddress,
                $customerNotes,
                $hash
            );
            $ins = $pdo->prepare('INSERT INTO storefront_accounts (' . $ic . ') VALUES (' . $iv . ')');
            $ins->execute($ip);
        } else {
            $ic = 'email, registered_channel_slug, customer_name, customer_phone';
            $iv = '?, ?, ?, ?';
            $ip = [$email, $channelSlug, $customerName, $customerPhone];
            if ($hasSfaDial) {
                $ic .= ', customer_phone_country_dial';
                $iv .= ', ?';
                $ip[] = $customerPhoneCountryDial;
            }
            if ($hasSfaNat) {
                $ic .= ', customer_phone_national';
                $iv .= ', ?';
                $ip[] = $customerPhoneNational;
            }
            if ($hasAccountCountryCol) {
                $ic .= ', country_id';
                $iv .= ', ?';
                $ip[] = $accountCountryId;
            }
            $ic .= ', customer_area, customer_address, customer_notes, verify_token_hash, verify_token_expires_at, verify_email_sent_at';
            $iv .= ', ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 48 HOUR), NOW()';
            array_push($ip, $customerArea, $customerAddress, $customerNotes, $hash);
            $ins = $pdo->prepare('INSERT INTO storefront_accounts (' . $ic . ') VALUES (' . $iv . ')');
            $ins->execute($ip);
        }
        $accountIdAfter = (int) $pdo->lastInsertId();
    } else {
        if ($hasDaCol) {
            $setSql = 'verify_token_hash = ?, verify_token_expires_at = DATE_ADD(NOW(), INTERVAL 48 HOUR), verify_email_sent_at = NOW(),
                 registered_channel_slug = COALESCE(registered_channel_slug, ?),
                 customer_name = ?, customer_phone = ?';
            $up = [$hash, $channelSlug, $customerName, $customerPhone];
            if ($hasSfaDial) {
                $setSql .= ', customer_phone_country_dial = ?';
                $up[] = $customerPhoneCountryDial;
            }
            if ($hasSfaNat) {
                $setSql .= ', customer_phone_national = ?';
                $up[] = $customerPhoneNational;
            }
            $setSql .= ', customer_delivery_area_id = ?, customer_area = ?, customer_address = ?, customer_notes = ?
                 WHERE id = ? AND email_verified_at IS NULL';
            array_push(
                $up,
                $customerDaId !== null && $customerDaId > 0 ? $customerDaId : null,
                $customerArea,
                $customerAddress,
                $customerNotes,
                (int) $row['id']
            );
            $upd = $pdo->prepare('UPDATE storefront_accounts SET ' . $setSql);
            $upd->execute($up);
        } else {
            $setSql = 'verify_token_hash = ?, verify_token_expires_at = DATE_ADD(NOW(), INTERVAL 48 HOUR), verify_email_sent_at = NOW(),
                 registered_channel_slug = COALESCE(registered_channel_slug, ?),
                 customer_name = ?, customer_phone = ?';
            $up = [$hash, $channelSlug, $customerName, $customerPhone];
            if ($hasSfaDial) {
                $setSql .= ', customer_phone_country_dial = ?';
                $up[] = $customerPhoneCountryDial;
            }
            if ($hasSfaNat) {
                $setSql .= ', customer_phone_national = ?';
                $up[] = $customerPhoneNational;
            }
            $setSql .= ', customer_area = ?, customer_address = ?, customer_notes = ?
                 WHERE id = ? AND email_verified_at IS NULL';
            array_push($up, $customerArea, $customerAddress, $customerNotes, (int) $row['id']);
            $upd = $pdo->prepare('UPDATE storefront_accounts SET ' . $setSql);
            $upd->execute($up);
        }
        $accountIdAfter = (int) $row['id'];
    }

    if ($trackCtx && $accountIdAfter > 0) {
        orange_storefront_link_order_to_account_row($pdo, $orderNumberLink, $accountIdAfter);
    }

    $rel = storefront_url('verify_email', $channelSlug, $lang, ['token' => $token]);
    $link = orange_site_public_origin() . $rel;

    $subjEn = 'Confirm your registration — Orange';
    $subjAr = 'تأكيد التسجيل — Orange';
    $body = "English:\nConfirm your email by opening this link (valid 48 hours):\n{$link}\n\n---\nالعربية:\nلتأكيد بريدك افتح الرابط (صالح 48 ساعة):\n{$link}\n";

    if ($trackCtx && $trackSignupItemsForMail !== null && $orderNumberLink !== '') {
        $ordM = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1');
        $ordM->execute([$orderNumberLink]);
        $orderMailRow = $ordM->fetch(PDO::FETCH_ASSOC);
        if (is_array($orderMailRow)) {
            $body .= orange_storefront_order_details_bilingual_email_appendix($orderMailRow, $trackSignupItemsForMail);
        }
    }

    if (!orange_mail_send_text($email, $subjEn . ' / ' . $subjAr, $body)) {
        if (function_exists('error_log')) {
            error_log('[orange] request-email-verify: mail() failed for ' . $email);
        }
        json_response([
            'success' => false,
            'code' => 'mail_failed',
            'message' => t('storefront_register_mail_failed'),
        ], 500);
    }

    json_response(['success' => true, 'message' => t('api_ok'), 'channel' => $channelSlug]);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
