<?php

declare(strict_types=1);

require_once __DIR__ . '/../../admin_permissions.php';
require_once __DIR__ . '/restore_job.php';
require_once __DIR__ . '/restore_approval.php';

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

/**
 * @param array<string, mixed> $admin
 */
function orange_restore_reauth_assert_restore_permission(array $admin, PDO $pdo, string $jobType): void
{
    orange_restore_assert_superuser_operator($admin);

    if ($jobType === ORANGE_RESTORE_JOB_TYPE_FULL) {
        if (!orange_admin_may_backup_restore_full($admin, $pdo)) {
            throw new RuntimeException('Operator lacks backup_restore_full permission.');
        }

        return;
    }

    if ($jobType === ORANGE_RESTORE_JOB_TYPE_COUNTRY) {
        if (!orange_admin_may_backup_restore_country($admin, $pdo)) {
            throw new RuntimeException('Operator lacks backup_restore_country permission.');
        }

        return;
    }

    throw new RuntimeException('Unknown restore job type for permission check.');
}

function orange_restore_reauth_timestamp(): string
{
    return gmdate('c');
}

/**
 * Resolve admin PDO for restore re-authentication (test override or live config).
 */
function orange_restore_resolve_admin_pdo(array $options): PDO
{
    $adminPdo = $options['admin_pdo_override'] ?? null;
    if ($adminPdo instanceof PDO) {
        return $adminPdo;
    }

    require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config.php';

    return db();
}

/**
 * Assert merge-time operator re-authentication (Super Admin + permission + password + phrase).
 *
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function orange_restore_assert_production_mutating_reauth(
    PDO $adminPdo,
    array $job,
    int $adminId,
    string $password,
    string $confirmationPhrase
): array {
    $admin = orange_restore_reauth_load_admin($adminPdo, $adminId);
    orange_restore_reauth_assert_restore_permission($admin, $adminPdo, (string) ($job['job_type'] ?? ''));
    if (!orange_restore_verify_operator_password($adminPdo, $adminId, $password)) {
        throw new RuntimeException('Operator password re-authentication failed.');
    }

    if (!orange_restore_validate_confirmation_phrase(
        (string) ($job['job_type'] ?? ''),
        $confirmationPhrase,
        (string) ($job['country_code'] ?? '')
    )) {
        throw new RuntimeException('Confirmation phrase mismatch.');
    }

    return $admin;
}

/**
 * Validate options and assert production-mutating re-authentication before side effects.
 *
 * @param array<string, mixed> $options
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function orange_restore_require_production_mutating_credentials(array $options, array $job): array
{
    $adminId = (int) ($options['admin_id'] ?? 0);
    $password = (string) ($options['password'] ?? '');
    $confirmationPhrase = (string) ($options['confirmation_phrase'] ?? '');

    if ($adminId <= 0) {
        throw new InvalidArgumentException('admin_id is required.');
    }
    if ($password === '') {
        throw new InvalidArgumentException('password is required for merge-time re-authentication.');
    }
    if (trim($confirmationPhrase) === '') {
        throw new InvalidArgumentException('confirmation phrase is required.');
    }

    return orange_restore_assert_production_mutating_reauth(
        orange_restore_resolve_admin_pdo($options),
        $job,
        $adminId,
        $password,
        $confirmationPhrase
    );
}
