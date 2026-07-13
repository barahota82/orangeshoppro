<?php

declare(strict_types=1);

/**
 * Re-authentication gate — current session is NOT sufficient (owner policy §4).
 * Used by future restore CLIs before any job stage that mutates state.
 */
function orange_restore_verify_operator_password(PDO $pdo, int $adminId, string $password): bool
{
    if ($adminId <= 0 || $password === '') {
        return false;
    }
    $st = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$adminId]);
    $hash = $st->fetchColumn();
    if (!is_string($hash) || $hash === '') {
        return false;
    }

    return password_verify($password, $hash);
}

/**
 * @param array<string, mixed> $admin Row from admins table
 */
function orange_restore_assert_superuser_operator(array $admin): void
{
    if ((int) ($admin['is_superuser'] ?? 0) !== 1) {
        throw new RuntimeException('Restore operator must be Super Admin.');
    }
}

function orange_restore_reauth_timestamp(): string
{
    return gmdate('c');
}
