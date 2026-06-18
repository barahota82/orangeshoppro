<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function orange_storefront_normalize_email(string $raw): ?string
{
    $e = strtolower(trim($raw));
    if ($e === '') {
        return null;
    }

    return $e;
}

/** قصّ آمن لحقول الملف التعريفي (UTF-8 عند توفر mbstring). */
function orange_storefront_clip_utf8(string $s, int $maxLen): string
{
    if ($maxLen <= 0) {
        return '';
    }
    $collapsed = preg_replace('/\s+/u', ' ', $s);
    $t = trim((string) ($collapsed ?? ''));
    if ($t === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($t, 'UTF-8') > $maxLen) {
            return mb_substr($t, 0, $maxLen, 'UTF-8');
        }

        return $t;
    }
    if (strlen($t) > $maxLen) {
        return substr($t, 0, $maxLen);
    }

    return $t;
}

/**
 * قناة نشطة من جدول channels (مع إعادة توجيه orange/blue/black → path slug بعد الترحيل).
 */
function orange_storefront_valid_channel_slug(PDO $pdo, string $raw): string
{
    return orange_storefront_normalize_channel_slug($pdo, $raw);
}

function orange_storefront_account_session_key(): string
{
    return 'orange_storefront_account_id';
}

function orange_storefront_account_channel_session_key(): string
{
    return 'orange_storefront_account_channel';
}

/** س13: هاتف آخر طلب ضيف في الجلسة — لعرض «طلباتي» (pending/approved) دون تسجيل كامل. */
function orange_storefront_guest_orders_phone_session_key(): string
{
    return 'orange_sf_guest_recent_phone';
}

function orange_storefront_set_guest_orders_phone(string $phoneNorm): void
{
    $p = trim($phoneNorm);
    if ($p === '') {
        return;
    }
    $_SESSION[orange_storefront_guest_orders_phone_session_key()] = $p;
}

function orange_storefront_clear_guest_orders_phone(): void
{
    unset($_SESSION[orange_storefront_guest_orders_phone_session_key()]);
}

function orange_storefront_guest_orders_phone_from_session(): ?string
{
    $k = orange_storefront_guest_orders_phone_session_key();
    if (empty($_SESSION[$k])) {
        return null;
    }
    $p = trim((string) $_SESSION[$k]);

    return $p !== '' ? $p : null;
}

function storefront_account_logout(): void
{
    unset(
        $_SESSION[orange_storefront_account_session_key()],
        $_SESSION[orange_storefront_account_channel_session_key()]
    );
}

function storefront_account_login(PDO $pdo, int $accountId): void
{
    if ($accountId <= 0) {
        return;
    }
    orange_storefront_clear_guest_orders_phone();
    require_once __DIR__ . '/catalog_schema.php';
    if (!orange_table_exists($pdo, 'storefront_accounts')) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT registered_channel_slug FROM storefront_accounts WHERE id = ? AND email_verified_at IS NOT NULL LIMIT 1'
    );
    $st->execute([$accountId]);
    $slug = $st->fetchColumn();
    $_SESSION[orange_storefront_account_session_key()] = $accountId;
    $ch = ($slug !== false && $slug !== null && (string) $slug !== '')
        ? orange_storefront_valid_channel_slug($pdo, (string) $slug)
        : orange_storefront_valid_channel_slug($pdo, '');
    $_SESSION[orange_storefront_account_channel_session_key()] = $ch;
}

/**
 * @return array{
 *   id: int,
 *   email: string,
 *   email_verified_at: string|null,
 *   registered_channel_slug: string,
 *   customer_name?: string|null,
 *   customer_phone?: string|null,
 *   customer_phone_country_dial?: string|null,
 *   customer_phone_national?: string|null,
 *   customer_area?: string|null,
 *   customer_address?: string|null,
 *   customer_notes?: string|null
 * }|null
 */
function current_storefront_account(PDO $pdo): ?array
{
    $k = orange_storefront_account_session_key();
    if (empty($_SESSION[$k])) {
        return null;
    }
    $id = (int) $_SESSION[$k];
    if ($id <= 0) {
        return null;
    }
    require_once __DIR__ . '/catalog_schema.php';
    if (!orange_table_exists($pdo, 'storefront_accounts')) {
        return null;
    }
    $hasCh = orange_table_has_column($pdo, 'storefront_accounts', 'registered_channel_slug');
    $hasProfile = orange_table_has_column($pdo, 'storefront_accounts', 'customer_name');
    $hasDa = orange_table_has_column($pdo, 'storefront_accounts', 'customer_delivery_area_id');
    $cols = ['id', 'email', 'email_verified_at'];
    if ($hasCh) {
        $cols[] = 'registered_channel_slug';
    }
    if ($hasProfile) {
        array_push($cols, 'customer_name', 'customer_phone', 'customer_area', 'customer_address', 'customer_notes');
        if (orange_table_has_column($pdo, 'storefront_accounts', 'customer_phone_country_dial')) {
            $cols[] = 'customer_phone_country_dial';
        }
        if (orange_table_has_column($pdo, 'storefront_accounts', 'customer_phone_national')) {
            $cols[] = 'customer_phone_national';
        }
        if ($hasDa) {
            $cols[] = 'customer_delivery_area_id';
        }
    }
    $st = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM storefront_accounts WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['email_verified_at'])) {
        storefront_account_logout();

        return null;
    }
    $regSlug = orange_storefront_valid_channel_slug($pdo, '');
    if ($hasCh && isset($row['registered_channel_slug']) && (string) $row['registered_channel_slug'] !== '') {
        $regSlug = orange_storefront_valid_channel_slug($pdo, (string) $row['registered_channel_slug']);
    }

    $out = [
        'id' => (int) $row['id'],
        'email' => (string) $row['email'],
        'email_verified_at' => $row['email_verified_at'] !== null ? (string) $row['email_verified_at'] : null,
        'registered_channel_slug' => $regSlug,
    ];
    if ($hasProfile) {
        $out['customer_name'] = isset($row['customer_name']) && $row['customer_name'] !== null && (string) $row['customer_name'] !== ''
            ? (string) $row['customer_name'] : null;
        $out['customer_phone'] = isset($row['customer_phone']) && $row['customer_phone'] !== null && (string) $row['customer_phone'] !== ''
            ? (string) $row['customer_phone'] : null;
        if (isset($row['customer_phone_country_dial'])) {
            $out['customer_phone_country_dial'] = $row['customer_phone_country_dial'] !== null && (string) $row['customer_phone_country_dial'] !== ''
                ? (string) $row['customer_phone_country_dial'] : null;
        }
        if (isset($row['customer_phone_national'])) {
            $out['customer_phone_national'] = $row['customer_phone_national'] !== null && (string) $row['customer_phone_national'] !== ''
                ? (string) $row['customer_phone_national'] : null;
        }
        $out['customer_area'] = isset($row['customer_area']) && $row['customer_area'] !== null && (string) $row['customer_area'] !== ''
            ? (string) $row['customer_area'] : null;
        $out['customer_address'] = isset($row['customer_address']) && $row['customer_address'] !== null && (string) $row['customer_address'] !== ''
            ? (string) $row['customer_address'] : null;
        $out['customer_notes'] = isset($row['customer_notes']) && $row['customer_notes'] !== null && (string) $row['customer_notes'] !== ''
            ? (string) $row['customer_notes'] : null;
        if ($hasDa) {
            $da = isset($row['customer_delivery_area_id']) ? (int) $row['customer_delivery_area_id'] : 0;
            $out['customer_delivery_area_id'] = $da > 0 ? $da : null;
        }
    }

    return $out;
}

/**
 * @return array{ok: bool, reason: string, account_id?: int}
 */
function orange_storefront_verify_email_token(PDO $pdo, string $token): array
{
    $token = trim($token);
    if (strlen($token) < 16) {
        return ['ok' => false, 'reason' => 'bad'];
    }
    $hash = hash('sha256', $token);
    $st = $pdo->prepare(
        'SELECT id, email_verified_at, verify_token_expires_at FROM storefront_accounts WHERE verify_token_hash = ? LIMIT 1'
    );
    $st->execute([$hash]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'reason' => 'bad'];
    }
    $aid = (int) $row['id'];
    if (!empty($row['email_verified_at'])) {
        return ['ok' => true, 'reason' => 'already', 'account_id' => $aid];
    }
    $exp = $row['verify_token_expires_at'] ?? null;
    if ($exp !== null && $exp !== '' && strtotime((string) $exp) < time()) {
        return ['ok' => false, 'reason' => 'expired'];
    }
    $upd = $pdo->prepare(
        'UPDATE storefront_accounts SET email_verified_at = NOW(), verify_token_hash = \'\', verify_token_expires_at = NULL WHERE id = ?'
    );
    $upd->execute([$aid]);

    return ['ok' => true, 'reason' => 'fresh', 'account_id' => $aid];
}

/**
 * يرجع معرّف الحساب فقط إذا كان الحساب موجوداً ومفعّلاً (email_verified_at).
 */
function orange_storefront_verified_account_id(PDO $pdo, int $accountId): ?int
{
    if ($accountId <= 0) {
        return null;
    }
    require_once __DIR__ . '/catalog_schema.php';
    if (!orange_table_exists($pdo, 'storefront_accounts')) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT id FROM storefront_accounts WHERE id = ? AND email_verified_at IS NOT NULL LIMIT 1'
    );
    $st->execute([$accountId]);
    $id = $st->fetchColumn();

    return ($id !== false && (int) $id > 0) ? (int) $id : null;
}

/**
 * @return array{id:int,email:string,customer_phone:string}|null
 */
function orange_storefront_verified_account_by_phone(PDO $pdo, string $phoneNorm, ?int $countryId = null): ?array
{
    $phoneNorm = trim($phoneNorm);
    if ($phoneNorm === '') {
        return null;
    }
    require_once __DIR__ . '/catalog_schema.php';
    if (!orange_table_exists($pdo, 'storefront_accounts')) {
        return null;
    }
    $hasCountry = orange_table_has_column($pdo, 'storefront_accounts', 'country_id');
    if ($countryId === null || $countryId <= 0) {
        require_once __DIR__ . '/countries.php';
        $countryId = orange_storefront_current_country_id($pdo);
    }
    require_once __DIR__ . '/order_helpers.php';
    if ($hasCountry && $countryId > 0) {
        $st = $pdo->prepare(
            'SELECT id, email, customer_phone
             FROM storefront_accounts
             WHERE country_id = ?
               AND email_verified_at IS NOT NULL
               AND customer_phone IS NOT NULL
               AND TRIM(customer_phone) <> \'\'
             ORDER BY id DESC'
        );
        $st->execute([$countryId]);
    } else {
        $st = $pdo->query(
            'SELECT id, email, customer_phone
             FROM storefront_accounts
             WHERE email_verified_at IS NOT NULL
               AND customer_phone IS NOT NULL
               AND TRIM(customer_phone) <> \'\'
             ORDER BY id DESC'
        );
    }
    if (!$st) {
        return null;
    }
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0) {
            continue;
        }
        $candidatePhone = trim((string) ($row['customer_phone'] ?? ''));
        if ($candidatePhone === '') {
            continue;
        }
        if (!orange_order_phones_match_for_lookup($phoneNorm, $candidatePhone)) {
            continue;
        }

        return [
            'id' => (int) $row['id'],
            'email' => trim((string) ($row['email'] ?? '')),
            'customer_phone' => $candidatePhone,
        ];
    }

    return null;
}

function orange_storefront_checkout_otp_hash(int $accountId, string $phoneNorm, string $otpCode): string
{
    $pepper = defined('DB_PASS') ? (string) DB_PASS : '';

    return hash('sha256', $accountId . '|' . trim($phoneNorm) . '|' . trim($otpCode) . '|' . $pepper);
}

function orange_storefront_checkout_otp_columns_ready(PDO $pdo): bool
{
    require_once __DIR__ . '/catalog_schema.php';
    if (!orange_table_exists($pdo, 'storefront_accounts')) {
        return false;
    }

    return orange_table_has_column($pdo, 'storefront_accounts', 'otp_hash')
        && orange_table_has_column($pdo, 'storefront_accounts', 'otp_expires_at')
        && orange_table_has_column($pdo, 'storefront_accounts', 'otp_sent_at')
        && orange_table_has_column($pdo, 'storefront_accounts', 'otp_attempts')
        && orange_table_has_column($pdo, 'storefront_accounts', 'otp_phone');
}

function orange_storefront_checkout_otp_clear(PDO $pdo, int $accountId): void
{
    if ($accountId <= 0 || !orange_storefront_checkout_otp_columns_ready($pdo)) {
        return;
    }
    $up = $pdo->prepare(
        'UPDATE storefront_accounts
         SET otp_hash = NULL,
             otp_expires_at = NULL,
             otp_sent_at = NULL,
             otp_attempts = 0,
             otp_phone = NULL
         WHERE id = ? LIMIT 1'
    );
    $up->execute([$accountId]);
}

/**
 * يثبّط معرّف الحساب على الطلب عند الدفع فقط إذا كان الحساب مفعّلاً بالبريد والهاتف يطابق الطلب.
 */
function orange_storefront_resolve_order_account_link(PDO $pdo, int $claimedAccountId, string $checkoutPhoneNorm): ?int
{
    if ($claimedAccountId <= 0 || trim($checkoutPhoneNorm) === '') {
        return null;
    }
    $verifiedId = orange_storefront_verified_account_id($pdo, $claimedAccountId);
    if ($verifiedId === null) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT id, customer_phone FROM storefront_accounts WHERE id = ? LIMIT 1'
    );
    $st->execute([$verifiedId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['customer_phone'])) {
        return null;
    }
    require_once __DIR__ . '/order_helpers.php';
    if (!orange_order_phones_match_for_lookup($checkoutPhoneNorm, (string) $row['customer_phone'])) {
        return null;
    }

    return $verifiedId;
}

/**
 * بعد التسجيل من التتبع: ربط الطلب بصف الحساب (قبل أو بعد تأكيد البريد) إن تطابق الهاتف.
 */
function orange_storefront_link_order_to_account_row(PDO $pdo, string $orderNumber, int $accountId): void
{
    $orderNumber = trim($orderNumber);
    if ($orderNumber === '' || $accountId <= 0) {
        return;
    }
    require_once __DIR__ . '/catalog_schema.php';
    if (!orange_table_exists($pdo, 'orders') || !orange_table_has_column($pdo, 'orders', 'storefront_account_id')) {
        return;
    }
    if (!orange_table_exists($pdo, 'storefront_accounts')) {
        return;
    }
    $oSt = $pdo->prepare('SELECT id, phone FROM orders WHERE order_number = ? LIMIT 1');
    $oSt->execute([$orderNumber]);
    $ord = $oSt->fetch(PDO::FETCH_ASSOC);
    if (!$ord) {
        return;
    }
    $aSt = $pdo->prepare('SELECT customer_phone FROM storefront_accounts WHERE id = ? LIMIT 1');
    $aSt->execute([$accountId]);
    $aph = $aSt->fetchColumn();
    if ($aph === false || $aph === null || trim((string) $aph) === '') {
        return;
    }
    require_once __DIR__ . '/order_helpers.php';
    if (!orange_order_phones_match_for_lookup((string) ($ord['phone'] ?? ''), (string) $aph)) {
        return;
    }
    $pdo->prepare('UPDATE orders SET storefront_account_id = ? WHERE id = ?')->execute([$accountId, (int) $ord['id']]);
}

/**
 * س14: ضيف — إلغاء قبل موافقة الشركة فقط (pending). مسجّل داخل جلسة — قبل الشحن: pending أو approved.
 *
 * @param array<string,mixed>|null $account ناتج current_storefront_account() أو null
 */
function orange_storefront_customer_may_cancel_order(PDO $pdo, array $order, ?array $account, string $phoneNorm): bool
{
    $st = strtolower(trim((string) ($order['status'] ?? '')));
    if ($st === 'pending') {
        return true;
    }
    if ($st !== 'approved') {
        return false;
    }
    if ($account === null || empty($account['id'])) {
        return false;
    }
    require_once __DIR__ . '/catalog_schema.php';
    require_once __DIR__ . '/order_helpers.php';
    if (orange_table_exists($pdo, 'orders') && orange_table_has_column($pdo, 'orders', 'storefront_account_id')) {
        $oid = (int) ($order['storefront_account_id'] ?? 0);
        if ($oid > 0 && $oid === (int) $account['id']) {
            return true;
        }
    }
    $cp = isset($account['customer_phone']) ? trim((string) $account['customer_phone']) : '';
    if ($cp !== '' && orange_order_phones_match_for_lookup($phoneNorm, $cp)) {
        return true;
    }

    return false;
}

/** س22: تعديل بنود الطلب — نفس نافذة الإلغاء (قبل الشحن). */
function orange_storefront_customer_may_amend_order_items(PDO $pdo, array $order, ?array $account, string $phoneNorm): bool
{
    return orange_storefront_customer_may_cancel_order($pdo, $order, $account, $phoneNorm);
}
