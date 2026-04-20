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
    $cols = ['id', 'email', 'email_verified_at'];
    if ($hasCh) {
        $cols[] = 'registered_channel_slug';
    }
    if ($hasProfile) {
        array_push($cols, 'customer_name', 'customer_phone', 'customer_area', 'customer_address', 'customer_notes');
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
        $out['customer_area'] = isset($row['customer_area']) && $row['customer_area'] !== null && (string) $row['customer_area'] !== ''
            ? (string) $row['customer_area'] : null;
        $out['customer_address'] = isset($row['customer_address']) && $row['customer_address'] !== null && (string) $row['customer_address'] !== ''
            ? (string) $row['customer_address'] : null;
        $out['customer_notes'] = isset($row['customer_notes']) && $row['customer_notes'] !== null && (string) $row['customer_notes'] !== ''
            ? (string) $row['customer_notes'] : null;
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
