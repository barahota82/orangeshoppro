<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/storefront_account.php';
require_once __DIR__ . '/countries.php';

function orange_storefront_merge_request_country_id(PDO $pdo, ?string $channelSlug): int
{
    $slug = trim((string) $channelSlug);
    if ($slug !== ''
        && orange_table_exists($pdo, 'channels')
        && orange_table_has_column($pdo, 'channels', 'country_id')) {
        $st = $pdo->prepare('SELECT country_id FROM channels WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
        $cid = (int) ($st->fetchColumn() ?: 0);
        if ($cid > 0) {
            return $cid;
        }
    }

    return orange_storefront_current_country_id($pdo);
}

/**
 * س15 — حساب مفعّل بنفس الهاتف: دمج بيانات الملف بعد تأكيد واتساب (يدوي من الأدمن) ثم تطبيق العميل.
 *
 * @return array<string, mixed>|null
 */
function orange_storefront_find_verified_account_by_phone(PDO $pdo, string $normalizedPhone): ?array
{
    if (trim($normalizedPhone) === '') {
        return null;
    }
    try {
        $st = $pdo->query(
            "SELECT * FROM storefront_accounts
             WHERE email_verified_at IS NOT NULL
               AND customer_phone IS NOT NULL
               AND TRIM(customer_phone) <> ''"
        );
        if (!$st) {
            return null;
        }
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                break;
            }
            $cp = trim((string) ($row['customer_phone'] ?? ''));
            if ($cp === '') {
                continue;
            }
            if (orange_order_phones_match_for_lookup($normalizedPhone, $cp)) {
                return $row;
            }
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_storefront_find_verified_account_by_phone: ' . $e->getMessage());
        }
    }

    return null;
}

function orange_storefront_mask_email_for_display(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '' || !str_contains($email, '@')) {
        return '***';
    }
    $parts = explode('@', $email, 2);
    $local = (string) ($parts[0] ?? '');
    $dom = (string) ($parts[1] ?? '');
    if ($dom === '') {
        return '***';
    }
    $len = strlen($local);
    if ($len <= 1) {
        return '*@' . $dom;
    }

    return substr($local, 0, 2) . '***@' . $dom;
}

/**
 * @return array{plain_token: string, id: int}
 */
function orange_storefront_create_phone_merge_request(
    PDO $pdo,
    int $accountId,
    string $phoneNormalized,
    string $proposedEmailNorm,
    string $channelSlug,
    string $customerName,
    ?int $deliveryAreaId,
    string $customerArea,
    string $customerAddress,
    string $customerNotes,
    ?string $phoneCountryDial,
    ?string $phoneNational
): array {
    if (!orange_table_exists($pdo, 'storefront_phone_merge_requests')) {
        throw new RuntimeException('storefront_phone_merge_requests missing');
    }
    if ($accountId <= 0) {
        throw new RuntimeException('invalid account');
    }
    $token = bin2hex(random_bytes(16));
    $hash = hash('sha256', $token);
    $expires = (new DateTimeImmutable('+48 hours'))->format('Y-m-d H:i:s');
    $ch = $channelSlug !== '' ? substr(preg_replace('/[^a-z0-9\-]/i', '', $channelSlug) ?? '', 0, 32) : null;
    if ($ch === '') {
        $ch = null;
    }
    $mergeCountryId = orange_storefront_merge_request_country_id($pdo, $ch ?? '');
    $hasCountryCol = orange_table_has_column($pdo, 'storefront_phone_merge_requests', 'country_id');

    if ($hasCountryCol) {
        $ins = $pdo->prepare(
            'INSERT INTO storefront_phone_merge_requests (
                country_id, storefront_account_id, phone_normalized, proposed_email, proposed_channel_slug,
                proposed_name, proposed_delivery_area_id, proposed_area, proposed_address, proposed_notes,
                proposed_phone_country_dial, proposed_phone_national,
                merge_token_hash, expires_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([
            $mergeCountryId,
            $accountId,
            substr($phoneNormalized, 0, 64),
            substr($proposedEmailNorm, 0, 255),
            $ch,
            $customerName !== '' ? $customerName : null,
            $deliveryAreaId !== null && $deliveryAreaId > 0 ? $deliveryAreaId : null,
            $customerArea !== '' ? $customerArea : null,
            $customerAddress !== '' ? $customerAddress : null,
            $customerNotes !== '' ? $customerNotes : null,
            $phoneCountryDial !== null && $phoneCountryDial !== '' ? substr($phoneCountryDial, 0, 8) : null,
            $phoneNational !== null && $phoneNational !== '' ? substr($phoneNational, 0, 32) : null,
            $hash,
            $expires,
        ]);
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO storefront_phone_merge_requests (
                storefront_account_id, phone_normalized, proposed_email, proposed_channel_slug,
                proposed_name, proposed_delivery_area_id, proposed_area, proposed_address, proposed_notes,
                proposed_phone_country_dial, proposed_phone_national,
                merge_token_hash, expires_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([
            $accountId,
            substr($phoneNormalized, 0, 64),
            substr($proposedEmailNorm, 0, 255),
            $ch,
            $customerName !== '' ? $customerName : null,
            $deliveryAreaId !== null && $deliveryAreaId > 0 ? $deliveryAreaId : null,
            $customerArea !== '' ? $customerArea : null,
            $customerAddress !== '' ? $customerAddress : null,
            $customerNotes !== '' ? $customerNotes : null,
            $phoneCountryDial !== null && $phoneCountryDial !== '' ? substr($phoneCountryDial, 0, 8) : null,
            $phoneNational !== null && $phoneNational !== '' ? substr($phoneNational, 0, 32) : null,
            $hash,
            $expires,
        ]);
    }

    return ['plain_token' => $token, 'id' => (int) $pdo->lastInsertId()];
}

/**
 * تطبيق بيانات الملف على الحساب القائم؛ لا يغيّر البريد المؤكَّد.
 *
 * @return array{account_id: int}
 */
function orange_storefront_apply_phone_merge_profile(PDO $pdo, string $plainToken, string $proposedEmailNorm): array
{
    if (!orange_table_exists($pdo, 'storefront_phone_merge_requests')) {
        throw new RuntimeException('storefront_phone_merge_requests missing');
    }
    $plainToken = trim($plainToken);
    if ($plainToken === '') {
        throw new RuntimeException('missing_token');
    }
    $emailNorm = orange_storefront_normalize_email($proposedEmailNorm);
    if ($emailNorm === null || $emailNorm === '') {
        throw new RuntimeException('invalid_email');
    }
    $hash = hash('sha256', $plainToken);
    $st = $pdo->prepare(
        'SELECT * FROM storefront_phone_merge_requests
         WHERE merge_token_hash = ? AND consumed_at IS NULL AND expires_at > NOW()
         LIMIT 1'
    );
    $st->execute([$hash]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !is_array($row)) {
        throw new RuntimeException('invalid_or_expired_token');
    }
    if (empty($row['wa_confirmed_at'])) {
        throw new RuntimeException('wa_not_confirmed');
    }
    if (strcasecmp((string) ($row['proposed_email'] ?? ''), $emailNorm) !== 0) {
        throw new RuntimeException('email_mismatch');
    }

    $accId = (int) ($row['storefront_account_id'] ?? 0);
    if ($accId <= 0) {
        throw new RuntimeException('invalid_account');
    }

    $accSt = $pdo->prepare('SELECT id, email, email_verified_at FROM storefront_accounts WHERE id = ? LIMIT 1');
    $accSt->execute([$accId]);
    $acc = $accSt->fetch(PDO::FETCH_ASSOC);
    if (!$acc || empty($acc['email_verified_at'])) {
        throw new RuntimeException('account_not_verified');
    }

    $name = isset($row['proposed_name']) ? trim((string) $row['proposed_name']) : '';
    $area = isset($row['proposed_area']) ? trim((string) $row['proposed_area']) : '';
    $addr = isset($row['proposed_address']) ? trim((string) $row['proposed_address']) : '';
    $notes = isset($row['proposed_notes']) ? trim((string) $row['proposed_notes']) : '';
    $daId = isset($row['proposed_delivery_area_id']) ? (int) $row['proposed_delivery_area_id'] : 0;
    $dial = isset($row['proposed_phone_country_dial']) ? trim((string) $row['proposed_phone_country_dial']) : '';
    $nat = isset($row['proposed_phone_national']) ? trim((string) $row['proposed_phone_national']) : '';
    $phoneNorm = isset($row['phone_normalized']) ? trim((string) $row['phone_normalized']) : '';

    $set = 'customer_name = ?, customer_area = ?, customer_address = ?, customer_notes = ?';
    $params = [
        $name !== '' ? orange_storefront_clip_utf8($name, 255) : null,
        $area !== '' ? orange_storefront_clip_utf8($area, 255) : null,
        $addr !== '' ? orange_storefront_clip_utf8($addr, 4000) : null,
        $notes !== '' ? orange_storefront_clip_utf8($notes, 4000) : null,
    ];
    if (orange_table_has_column($pdo, 'storefront_accounts', 'customer_delivery_area_id')) {
        $set .= ', customer_delivery_area_id = ?';
        $params[] = $daId > 0 ? $daId : null;
    }
    if ($phoneNorm !== '' && orange_table_has_column($pdo, 'storefront_accounts', 'customer_phone')) {
        $set .= ', customer_phone = ?';
        $params[] = orange_storefront_clip_utf8($phoneNorm, 64);
    }
    if ($dial !== '' && orange_table_has_column($pdo, 'storefront_accounts', 'customer_phone_country_dial')) {
        $set .= ', customer_phone_country_dial = ?';
        $params[] = substr(preg_replace('/\D+/', '', $dial) ?? '', 0, 8);
    }
    if ($nat !== '' && orange_table_has_column($pdo, 'storefront_accounts', 'customer_phone_national')) {
        $set .= ', customer_phone_national = ?';
        $params[] = substr(preg_replace('/\D+/', '', $nat) ?? '', 0, 32);
    }
    $params[] = $accId;

    $upd = $pdo->prepare('UPDATE storefront_accounts SET ' . $set . ' WHERE id = ?');
    $upd->execute($params);

    $pdo->prepare('UPDATE storefront_phone_merge_requests SET consumed_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);

    return ['account_id' => $accId];
}
