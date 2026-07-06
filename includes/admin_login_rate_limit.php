<?php

declare(strict_types=1);

/**
 * PR-SEC-02 — admin login rate limiting helpers (foundation only; not wired to login yet).
 * Persistent dual-scope throttling: username + client IP (REMOTE_ADDR only).
 */

function orange_admin_login_client_ip(): string
{
    $raw = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($raw === '') {
        return '0.0.0.0';
    }

    return $raw;
}

function orange_admin_login_rate_limit_scope_username(string $username): string
{
    $normalized = strtolower(trim($username));
    if ($normalized === '') {
        return '';
    }
    if (strlen($normalized) > 100) {
        $normalized = substr($normalized, 0, 100);
    }

    return $normalized;
}

function orange_admin_login_rate_limit_scope_ip_key(string $ip): string
{
    return hash('sha256', $ip);
}

function orange_admin_login_rate_limit_env_int(string $key, int $default, int $min, int $max): int
{
    global $env;
    $envArr = is_array($env ?? null) ? $env : [];
    $raw = $envArr[$key] ?? getenv($key);
    if ($raw === false || $raw === null || $raw === '') {
        return $default;
    }
    if (!is_numeric($raw)) {
        return $default;
    }
    $value = (int) $raw;

    return max($min, min($max, $value));
}

/**
 * @return array{
 *     max_attempts_username:int,
 *     max_attempts_ip:int,
 *     window_seconds:int,
 *     lock_seconds:int
 * }
 */
function orange_admin_login_rate_limit_settings(): array
{
    return [
        'max_attempts_username' => orange_admin_login_rate_limit_env_int(
            'ORANGE_ADMIN_LOGIN_MAX_ATTEMPTS_USERNAME',
            5,
            1,
            100
        ),
        'max_attempts_ip' => orange_admin_login_rate_limit_env_int(
            'ORANGE_ADMIN_LOGIN_MAX_ATTEMPTS_IP',
            30,
            1,
            1000
        ),
        'window_seconds' => orange_admin_login_rate_limit_env_int(
            'ORANGE_ADMIN_LOGIN_WINDOW_SECONDS',
            900,
            60,
            86400
        ),
        'lock_seconds' => orange_admin_login_rate_limit_env_int(
            'ORANGE_ADMIN_LOGIN_LOCK_SECONDS',
            900,
            60,
            86400
        ),
    ];
}

function orange_admin_login_rate_limit_table_ready(PDO $pdo): bool
{
    require_once __DIR__ . '/catalog_schema.php';

    return orange_table_exists($pdo, 'orange_admin_login_throttle');
}

function orange_admin_login_rate_limit_now_sql(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * @return array{allowed:bool,locked:bool,retry_after_seconds:?int}
 */
function orange_admin_login_rate_limit_allowed_result(): array
{
    return [
        'allowed' => true,
        'locked' => false,
        'retry_after_seconds' => null,
    ];
}

/**
 * @return array{allowed:bool,locked:bool,retry_after_seconds:?int}
 */
function orange_admin_login_rate_limit_locked_result(int $retryAfterSeconds): array
{
    return [
        'allowed' => false,
        'locked' => true,
        'retry_after_seconds' => max(1, $retryAfterSeconds),
    ];
}

/**
 * @param array<string, mixed>|null $row
 * @return array{allowed:bool,locked:bool,retry_after_seconds:?int}
 */
function orange_admin_login_rate_limit_evaluate_row(
    ?array $row,
    int $maxAttempts,
    int $windowSeconds,
    int $lockSeconds,
    string $nowSql
): array {
    if (!is_array($row)) {
        return orange_admin_login_rate_limit_allowed_result();
    }

    $lockedUntilRaw = trim((string) ($row['locked_until'] ?? ''));
    if ($lockedUntilRaw !== '') {
        $lockedUntilTs = strtotime($lockedUntilRaw);
        $nowTs = strtotime($nowSql);
        if ($lockedUntilTs !== false && $nowTs !== false && $lockedUntilTs > $nowTs) {
            return orange_admin_login_rate_limit_locked_result($lockedUntilTs - $nowTs);
        }
    }

    $windowStartedRaw = trim((string) ($row['window_started_at'] ?? ''));
    $failedCount = max(0, (int) ($row['failed_count'] ?? 0));
    if ($windowStartedRaw !== '' && $failedCount > 0) {
        $windowStartedTs = strtotime($windowStartedRaw);
        $nowTs = strtotime($nowSql);
        if ($windowStartedTs !== false && $nowTs !== false && ($nowTs - $windowStartedTs) <= $windowSeconds) {
            if ($failedCount >= $maxAttempts) {
                return orange_admin_login_rate_limit_locked_result($lockSeconds);
            }
        }
    }

    return orange_admin_login_rate_limit_allowed_result();
}

/**
 * @return array<string, mixed>|null
 */
function orange_admin_login_rate_limit_fetch_scope_row(
    PDO $pdo,
    string $scopeType,
    string $scopeKey
): ?array {
    $st = $pdo->prepare(
        'SELECT id, scope_type, scope_key, failed_count, window_started_at, locked_until
         FROM orange_admin_login_throttle
         WHERE scope_type = ? AND scope_key = ?
         LIMIT 1'
    );
    $st->execute([$scopeType, $scopeKey]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @return array{allowed:bool,locked:bool,retry_after_seconds:?int}
 */
function orange_admin_login_rate_limit_check(PDO $pdo, string $username): array
{
    try {
        if (!orange_admin_login_rate_limit_table_ready($pdo)) {
            return orange_admin_login_rate_limit_allowed_result();
        }

        $settings = orange_admin_login_rate_limit_settings();
        $nowSql = orange_admin_login_rate_limit_now_sql();
        $usernameKey = orange_admin_login_rate_limit_scope_username($username);
        $ipKey = orange_admin_login_rate_limit_scope_ip_key(orange_admin_login_client_ip());

        if ($usernameKey !== '') {
            $usernameRow = orange_admin_login_rate_limit_fetch_scope_row($pdo, 'username', $usernameKey);
            $usernameResult = orange_admin_login_rate_limit_evaluate_row(
                $usernameRow,
                $settings['max_attempts_username'],
                $settings['window_seconds'],
                $settings['lock_seconds'],
                $nowSql
            );
            if (!$usernameResult['allowed']) {
                return $usernameResult;
            }
        }

        $ipRow = orange_admin_login_rate_limit_fetch_scope_row($pdo, 'ip', $ipKey);
        $ipResult = orange_admin_login_rate_limit_evaluate_row(
            $ipRow,
            $settings['max_attempts_ip'],
            $settings['window_seconds'],
            $settings['lock_seconds'],
            $nowSql
        );
        if (!$ipResult['allowed']) {
            return $ipResult;
        }

        return orange_admin_login_rate_limit_allowed_result();
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_admin_login_rate_limit_check: ' . $e->getMessage());
        }

        return orange_admin_login_rate_limit_allowed_result();
    }
}

function orange_admin_login_rate_limit_record_scope_failure(
    PDO $pdo,
    string $scopeType,
    string $scopeKey,
    int $maxAttempts,
    int $windowSeconds,
    int $lockSeconds,
    string $nowSql
): void {
    if ($scopeKey === '') {
        return;
    }

    $pdo->beginTransaction();
    try {
        $select = $pdo->prepare(
            'SELECT id, failed_count, window_started_at, locked_until
             FROM orange_admin_login_throttle
             WHERE scope_type = ? AND scope_key = ?
             LIMIT 1
             FOR UPDATE'
        );
        $select->execute([$scopeType, $scopeKey]);
        $row = $select->fetch(PDO::FETCH_ASSOC);

        $failedCount = 0;
        $windowStartedAt = $nowSql;
        if (is_array($row)) {
            $failedCount = max(0, (int) ($row['failed_count'] ?? 0));
            $windowStartedRaw = trim((string) ($row['window_started_at'] ?? ''));
            $windowStartedTs = $windowStartedRaw !== '' ? strtotime($windowStartedRaw) : false;
            $nowTs = strtotime($nowSql);
            if ($windowStartedTs !== false && $nowTs !== false && ($nowTs - $windowStartedTs) <= $windowSeconds) {
                $windowStartedAt = $windowStartedRaw;
            } else {
                $failedCount = 0;
            }
        }

        $failedCount++;
        $lockedUntil = null;
        if ($failedCount >= $maxAttempts) {
            $lockUntilTs = strtotime($nowSql);
            if ($lockUntilTs !== false) {
                $lockedUntil = date('Y-m-d H:i:s', $lockUntilTs + $lockSeconds);
            }
        }

        if (is_array($row)) {
            $upd = $pdo->prepare(
                'UPDATE orange_admin_login_throttle
                 SET failed_count = ?, window_started_at = ?, locked_until = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?
                 LIMIT 1'
            );
            $upd->execute([$failedCount, $windowStartedAt, $lockedUntil, (int) ($row['id'] ?? 0)]);
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO orange_admin_login_throttle
                    (scope_type, scope_key, failed_count, window_started_at, locked_until)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $ins->execute([$scopeType, $scopeKey, $failedCount, $windowStartedAt, $lockedUntil]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function orange_admin_login_rate_limit_record_failure(PDO $pdo, string $username): void
{
    try {
        if (!orange_admin_login_rate_limit_table_ready($pdo)) {
            return;
        }

        $settings = orange_admin_login_rate_limit_settings();
        $nowSql = orange_admin_login_rate_limit_now_sql();
        $usernameKey = orange_admin_login_rate_limit_scope_username($username);
        $ipKey = orange_admin_login_rate_limit_scope_ip_key(orange_admin_login_client_ip());

        if ($usernameKey !== '') {
            orange_admin_login_rate_limit_record_scope_failure(
                $pdo,
                'username',
                $usernameKey,
                $settings['max_attempts_username'],
                $settings['window_seconds'],
                $settings['lock_seconds'],
                $nowSql
            );
        }

        orange_admin_login_rate_limit_record_scope_failure(
            $pdo,
            'ip',
            $ipKey,
            $settings['max_attempts_ip'],
            $settings['window_seconds'],
            $settings['lock_seconds'],
            $nowSql
        );
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_admin_login_rate_limit_record_failure: ' . $e->getMessage());
        }
    }
}

function orange_admin_login_rate_limit_clear_scope(PDO $pdo, string $scopeType, string $scopeKey): void
{
    if ($scopeKey === '') {
        return;
    }

    $del = $pdo->prepare(
        'DELETE FROM orange_admin_login_throttle
         WHERE scope_type = ? AND scope_key = ?
         LIMIT 1'
    );
    $del->execute([$scopeType, $scopeKey]);
}

function orange_admin_login_rate_limit_clear(PDO $pdo, string $username): void
{
    try {
        if (!orange_admin_login_rate_limit_table_ready($pdo)) {
            return;
        }

        $usernameKey = orange_admin_login_rate_limit_scope_username($username);
        $ipKey = orange_admin_login_rate_limit_scope_ip_key(orange_admin_login_client_ip());

        $pdo->beginTransaction();
        try {
            if ($usernameKey !== '') {
                orange_admin_login_rate_limit_clear_scope($pdo, 'username', $usernameKey);
            }
            orange_admin_login_rate_limit_clear_scope($pdo, 'ip', $ipKey);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_admin_login_rate_limit_clear: ' . $e->getMessage());
        }
    }
}
