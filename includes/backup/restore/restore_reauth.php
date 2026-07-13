<?php

declare(strict_types=1);

require_once __DIR__ . '/../../admin_permissions.php';
require_once __DIR__ . '/restore_job.php';

/**
 * Re-authentication gate — current session is NOT sufficient (owner policy §4).
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
 * @return array<string, mixed>
 */
function orange_restore_reauth_load_admin(PDO $pdo, int $adminId): array
{
    if ($adminId <= 0) {
        throw new RuntimeException('Invalid admin id for restore re-authentication.');
    }
    $st = $pdo->prepare(
        'SELECT id, username, display_name, is_active, is_superuser FROM admins WHERE id = ? LIMIT 1'
    );
    $st->execute([$adminId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Restore operator admin not found: ' . (string) $adminId);
    }
    if ((int) ($row['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('Restore operator admin is inactive.');
    }

    return $row;
}

/**
 * @param array<string, mixed> $admin
 */
function orange_restore_assert_superuser_operator(array $admin): void
{
    if ((int) ($admin['is_superuser'] ?? 0) !== 1) {
        throw new RuntimeException('Restore operator must be Super Admin.');
    }
}

function orange_restore_reauth_has_execute_permission(PDO $pdo, int $adminId, string $permissionKey): bool
{
    $st = $pdo->prepare(
        'SELECT can_edit FROM admin_permissions WHERE admin_id = ? AND resource_key = ? LIMIT 1'
    );
    $st->execute([$adminId, $permissionKey]);
    $value = $st->fetchColumn();

    return (int) $value === 1;
}

/**
 * @param array<string, mixed> $admin
 */
function orange_restore_reauth_assert_restore_permission(array $admin, PDO $pdo, string $jobType): void
{
    orange_restore_assert_superuser_operator($admin);
    $adminId = (int) ($admin['id'] ?? 0);

    if ($jobType === ORANGE_RESTORE_JOB_TYPE_FULL) {
        if (!orange_restore_reauth_has_execute_permission($pdo, $adminId, 'backup_restore_full')) {
            throw new RuntimeException('Operator lacks executable backup_restore_full permission.');
        }

        return;
    }

    if ($jobType === ORANGE_RESTORE_JOB_TYPE_COUNTRY) {
        if (!orange_restore_reauth_has_execute_permission($pdo, $adminId, 'backup_restore_country')) {
            throw new RuntimeException('Operator lacks executable backup_restore_country permission.');
        }

        return;
    }

    throw new RuntimeException('Unknown restore job type for permission check.');
}

function orange_restore_reauth_timestamp(): string
{
    return gmdate('c');
}
