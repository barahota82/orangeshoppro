<?php

declare(strict_types=1);

function orange_storefront_normalize_email(string $raw): ?string
{
    $e = strtolower(trim($raw));
    if ($e === '') {
        return null;
    }

    return $e;
}

/**
 * قناة نشطة من جدول channels أو orange الافتراضية.
 */
function orange_storefront_valid_channel_slug(PDO $pdo, string $raw): string
{
    $slug = preg_replace('/[^a-z0-9\-]/i', '', strtolower(trim($raw)));
    if ($slug === '') {
        $slug = 'orange';
    }
    $st = $pdo->prepare('SELECT slug FROM channels WHERE slug = ? AND is_active = 1 LIMIT 1');
    $st->execute([$slug]);
    $found = $st->fetchColumn();

    return $found ? (string) $found : 'orange';
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
        : 'orange';
    $_SESSION[orange_storefront_account_channel_session_key()] = $ch;
}

/**
 * @return array{id: int, email: string, email_verified_at: string|null, registered_channel_slug: string}|null
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
    $cols = $hasCh ? 'id, email, email_verified_at, registered_channel_slug' : 'id, email, email_verified_at';
    $st = $pdo->prepare('SELECT ' . $cols . ' FROM storefront_accounts WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['email_verified_at'])) {
        storefront_account_logout();

        return null;
    }
    $regSlug = 'orange';
    if ($hasCh && isset($row['registered_channel_slug']) && (string) $row['registered_channel_slug'] !== '') {
        $regSlug = orange_storefront_valid_channel_slug($pdo, (string) $row['registered_channel_slug']);
    }

    return [
        'id' => (int) $row['id'],
        'email' => (string) $row['email'],
        'email_verified_at' => $row['email_verified_at'] !== null ? (string) $row['email_verified_at'] : null,
        'registered_channel_slug' => $regSlug,
    ];
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
