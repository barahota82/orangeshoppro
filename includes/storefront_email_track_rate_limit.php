<?php

declare(strict_types=1);

/**
 * FSR-SEC-03 — shared server-side throttle for public track order-summary email.
 *
 * Reuses orange_admin_login_throttle (schema rev ≤124) with scope_type=et_mail.
 * Key = HMAC fingerprint of trusted IP + normalized subject (no raw PII in keys).
 * Windows/epoch: DATETIME columns store UTC wall (gmdate); comparisons use unix time().
 */

const ORANGE_EMAIL_TRACK_RL_SCOPE = 'et_mail';
const ORANGE_EMAIL_TRACK_RL_MAX_PER_HOUR = 8;
const ORANGE_EMAIL_TRACK_RL_WINDOW_SECONDS = 3600;
const ORANGE_EMAIL_TRACK_RL_COOLDOWN_SECONDS = 90;

/**
 * Trusted client IP — REMOTE_ADDR only (same policy as admin login throttle).
 */
function orange_email_track_client_ip(): string
{
    require_once __DIR__ . '/admin_login_rate_limit.php';

    return orange_admin_login_client_ip();
}

function orange_email_track_rate_limit_table_ready(PDO $pdo): bool
{
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $st = $pdo->query(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'orange_admin_login_throttle' LIMIT 1"
            );

            return $st !== false && (bool) $st->fetchColumn();
        }
        require_once __DIR__ . '/catalog_schema.php';

        return function_exists('orange_table_exists')
            && orange_table_exists($pdo, 'orange_admin_login_throttle');
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Pepper from server-only DB_PASS (never logged / never in filenames).
 */
function orange_email_track_rate_limit_pepper(): string
{
    $pass = defined('DB_PASS') ? (string) DB_PASS : '';
    if ($pass === '') {
        return 'orange_email_track_pepper_unavailable';
    }

    return $pass . '|orange_email_track_v1';
}

/**
 * Normalized subject fingerprint (hex SHA-256). No raw PII in return value.
 *
 * @param non-empty-string $orderNumberTrimmed
 * @param non-empty-string $phoneNorm international normalized phone
 * @param non-empty-string $emailLower trimmed lowercase email
 */
function orange_email_track_rate_limit_subject_fingerprint(
    string $orderNumberTrimmed,
    string $phoneNorm,
    string $emailLower,
    int $storefrontCountryId
): string
{
    $payload = $orderNumberTrimmed . "\n" . $phoneNorm . "\n" . $emailLower . "\n" . (string) max(0, $storefrontCountryId);

    return hash_hmac('sha256', $payload, orange_email_track_rate_limit_pepper());
}

/**
 * Shared bucket key (hex) binding IP + subject fingerprint.
 */
function orange_email_track_rate_limit_scope_key(string $clientIp, string $subjectFingerprintHex): string
{
    return hash_hmac(
        'sha256',
        $clientIp . "\n" . $subjectFingerprintHex,
        orange_email_track_rate_limit_pepper()
    );
}

/** Parse MySQL DATETIME stored as UTC wall → unix epoch. */
function orange_email_track_parse_utc_mysql(?string $sql): ?int
{
    $sql = trim((string) $sql);
    if ($sql === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $sql, new DateTimeZone('UTC'));
    if (!$dt instanceof DateTimeImmutable) {
        return null;
    }

    return $dt->getTimestamp();
}

/**
 * @return array{allowed:bool,retry_after_seconds:int,reason:string}
 */
function orange_email_track_rate_limit_allowed_result(): array
{
    return [
        'allowed' => true,
        'retry_after_seconds' => 0,
        'reason' => '',
    ];
}

/**
 * @return array{allowed:bool,retry_after_seconds:int,reason:string}
 */
function orange_email_track_rate_limit_blocked_result(int $retryAfterSeconds, string $reason): array
{
    return [
        'allowed' => false,
        'retry_after_seconds' => max(1, $retryAfterSeconds),
        'reason' => $reason,
    ];
}

/**
 * Atomic check-and-consume against shared storage.
 * Fail-closed: throws RuntimeException with code email_track_limiter_unavailable.
 *
 * @return array{allowed:bool,retry_after_seconds:int,reason:string}
 */
function orange_email_track_rate_limit_consume(
    PDO $pdo,
    string $clientIp,
    string $subjectFingerprintHex,
    ?int $nowTs = null
): array {
    if (!orange_email_track_rate_limit_table_ready($pdo)) {
        throw new RuntimeException('email_track_limiter_unavailable', 503);
    }

    $nowTs = $nowTs ?? time();
    $scopeKey = orange_email_track_rate_limit_scope_key($clientIp, $subjectFingerprintHex);
    if (strlen($scopeKey) > 128) {
        $scopeKey = substr($scopeKey, 0, 128);
    }

    $nowUtcSql = gmdate('Y-m-d H:i:s', $nowTs);
    $windowSeconds = ORANGE_EMAIL_TRACK_RL_WINDOW_SECONDS;
    $cooldownSeconds = ORANGE_EMAIL_TRACK_RL_COOLDOWN_SECONDS;
    $maxPerHour = ORANGE_EMAIL_TRACK_RL_MAX_PER_HOUR;

    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $pdo->beginTransaction();
    try {
        // MySQL: row lock. SQLite: BEGIN serializes writers; FOR UPDATE is not portable.
        $forUpdate = ($driver === 'mysql') ? ' FOR UPDATE' : '';
        $select = $pdo->prepare(
            'SELECT id, failed_count, window_started_at, locked_until
             FROM orange_admin_login_throttle
             WHERE scope_type = ? AND scope_key = ?
             LIMIT 1' . $forUpdate
        );
        $select->execute([ORANGE_EMAIL_TRACK_RL_SCOPE, $scopeKey]);
        $row = $select->fetch(PDO::FETCH_ASSOC);

        $failedCount = 0;
        $windowStartedTs = $nowTs;
        $windowStartedSql = $nowUtcSql;

        if (is_array($row)) {
            $lockedTs = orange_email_track_parse_utc_mysql(
                isset($row['locked_until']) ? (string) $row['locked_until'] : null
            );
            if ($lockedTs !== null && $lockedTs > $nowTs) {
                $pdo->commit();

                return orange_email_track_rate_limit_blocked_result($lockedTs - $nowTs, 'cooldown');
            }

            $parsedWindow = orange_email_track_parse_utc_mysql(
                isset($row['window_started_at']) ? (string) $row['window_started_at'] : null
            );
            if ($parsedWindow !== null && ($nowTs - $parsedWindow) < $windowSeconds) {
                $windowStartedTs = $parsedWindow;
                $windowStartedSql = gmdate('Y-m-d H:i:s', $parsedWindow);
                $failedCount = max(0, (int) ($row['failed_count'] ?? 0));
            } else {
                $failedCount = 0;
                $windowStartedTs = $nowTs;
                $windowStartedSql = $nowUtcSql;
            }
        }

        if ($failedCount >= $maxPerHour) {
            $retry = max(1, $windowSeconds - ($nowTs - $windowStartedTs));
            $pdo->commit();

            return orange_email_track_rate_limit_blocked_result($retry, 'hourly');
        }

        $failedCount++;
        $lockedUntilSql = gmdate('Y-m-d H:i:s', $nowTs + $cooldownSeconds);

        if (is_array($row)) {
            $upd = $pdo->prepare(
                'UPDATE orange_admin_login_throttle
                 SET failed_count = ?, window_started_at = ?, locked_until = ?
                 WHERE id = ?'
            );
            $upd->execute([
                $failedCount,
                $windowStartedSql,
                $lockedUntilSql,
                (int) ($row['id'] ?? 0),
            ]);
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO orange_admin_login_throttle
                    (scope_type, scope_key, failed_count, window_started_at, locked_until)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $ins->execute([
                ORANGE_EMAIL_TRACK_RL_SCOPE,
                $scopeKey,
                $failedCount,
                $windowStartedSql,
                $lockedUntilSql,
            ]);
        }

        $pdo->commit();

        return orange_email_track_rate_limit_allowed_result();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof RuntimeException && (string) $e->getMessage() === 'email_track_limiter_unavailable') {
            throw $e;
        }
        if (function_exists('error_log')) {
            error_log('[orange] email_track_rate_limit_consume: ' . $e->getMessage());
        }
        throw new RuntimeException('email_track_limiter_unavailable', 503);
    }
}

/**
 * Session fast-layer (additional). Does not replace shared storage.
 *
 * @return array{allowed:bool,retry_after_seconds:int,reason:string}
 */
function orange_email_track_session_rate_limit_check(
    string $orderNumberTrimmed,
    string $phoneNorm,
    string $emailLower,
    ?int $nowTs = null
): array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return orange_email_track_rate_limit_allowed_result();
    }

    $nowTs = $nowTs ?? time();
    $rateKey = 'orange_track_summary_mail_rate';
    /** @var list<int> */
    $bucket = isset($_SESSION[$rateKey]) && is_array($_SESSION[$rateKey]) ? $_SESSION[$rateKey] : [];
    $bucket = array_values(array_filter($bucket, static function ($t) use ($nowTs) {
        return is_int($t) && ($nowTs - $t) < ORANGE_EMAIL_TRACK_RL_WINDOW_SECONDS;
    }));
    if (count($bucket) >= ORANGE_EMAIL_TRACK_RL_MAX_PER_HOUR) {
        $oldest = min($bucket);
        $retry = max(1, ORANGE_EMAIL_TRACK_RL_WINDOW_SECONDS - ($nowTs - $oldest));

        return orange_email_track_rate_limit_blocked_result($retry, 'hourly');
    }

    $coolKey = 'orange_track_summary_mail_' . hash(
        'sha256',
        $orderNumberTrimmed . '|' . $phoneNorm . '|' . $emailLower
    );
    if (isset($_SESSION[$coolKey])) {
        $prev = (int) $_SESSION[$coolKey];
        if ($prev > 0 && ($nowTs - $prev) < ORANGE_EMAIL_TRACK_RL_COOLDOWN_SECONDS) {
            return orange_email_track_rate_limit_blocked_result(
                ORANGE_EMAIL_TRACK_RL_COOLDOWN_SECONDS - ($nowTs - $prev),
                'cooldown'
            );
        }
    }

    return orange_email_track_rate_limit_allowed_result();
}

/**
 * Record session layer after shared limiter allowed the attempt.
 */
function orange_email_track_session_rate_limit_record(
    string $orderNumberTrimmed,
    string $phoneNorm,
    string $emailLower,
    ?int $nowTs = null
): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $nowTs = $nowTs ?? time();
    $rateKey = 'orange_track_summary_mail_rate';
    /** @var list<int> */
    $bucket = isset($_SESSION[$rateKey]) && is_array($_SESSION[$rateKey]) ? $_SESSION[$rateKey] : [];
    $bucket = array_values(array_filter($bucket, static function ($t) use ($nowTs) {
        return is_int($t) && ($nowTs - $t) < ORANGE_EMAIL_TRACK_RL_WINDOW_SECONDS;
    }));
    $bucket[] = $nowTs;
    $_SESSION[$rateKey] = $bucket;

    $coolKey = 'orange_track_summary_mail_' . hash(
        'sha256',
        $orderNumberTrimmed . '|' . $phoneNorm . '|' . $emailLower
    );
    $_SESSION[$coolKey] = $nowTs;
}
