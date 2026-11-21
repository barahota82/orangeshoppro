<?php

declare(strict_types=1);

/**
 * حارس مختبر مركزي: لا يكفي GLOBAL/ENV وحدهما.
 * التفعيل فقط عبر m05_lab_guard / قفل ملفات تحت tools/m05_c1c8_lab/_evidence
 * مع إثبات DSN المعزول 127.0.0.1|localhost:13306/orange_m05_c1c8.
 */

function orange_lab_runtime_lock_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'm05_c1c8_lab'
        . DIRECTORY_SEPARATOR . '_evidence' . DIRECTORY_SEPARATOR . '.lab_runtime_lock';
}

/**
 * @return array{ok:bool,reason:string}
 */
function orange_lab_runtime_dsn_matches_lab(): array
{
    if (!defined('DB_HOST') || !defined('DB_PORT') || !defined('DB_NAME')) {
        return ['ok' => false, 'reason' => 'db_constants_undefined'];
    }
    $host = (string) DB_HOST;
    $port = (int) DB_PORT;
    $name = (string) DB_NAME;
    if (!in_array($host, ['127.0.0.1', 'localhost'], true) || $port !== 13306 || $name !== 'orange_m05_c1c8') {
        return ['ok' => false, 'reason' => 'dsn_not_lab:' . $host . ':' . $port . '/' . $name];
    }

    return ['ok' => true, 'reason' => 'ok'];
}

/**
 * يُستدعى فقط من بوابة المختبر بعد التحقق من .env + PDO.
 *
 * @return array{token:string,path:string,expires:int}
 */
function orange_lab_runtime_activate(string $marker, int $ttlSec = 7200): array
{
    $dsn = orange_lab_runtime_dsn_matches_lab();
    if (!$dsn['ok']) {
        throw new RuntimeException('lab_runtime_activate_blocked:' . $dsn['reason']);
    }
    $dir = dirname(orange_lab_runtime_lock_path());
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('lab_runtime_lock_dir_failed');
    }
    $token = hash('sha256', $marker . '|' . bin2hex(random_bytes(16)) . '|' . (string) time());
    $expires = time() + max(60, $ttlSec);
    $payload = [
        'token' => $token,
        'marker' => $marker,
        'expires' => $expires,
        'dsn' => (string) DB_HOST . ':' . (int) DB_PORT . '/' . (string) DB_NAME,
        'sapi' => PHP_SAPI,
        'pid' => getmypid(),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('lab_runtime_lock_encode_failed');
    }
    $path = orange_lab_runtime_lock_path();
    if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('lab_runtime_lock_write_failed');
    }
    $GLOBALS['orange_lab_runtime'] = [
        'active' => true,
        'token' => $token,
        'expires' => $expires,
    ];

    return ['token' => $token, 'path' => $path, 'expires' => $expires];
}

function orange_lab_runtime_deactivate(): void
{
    $path = orange_lab_runtime_lock_path();
    if (is_file($path)) {
        @unlink($path);
    }
    unset($GLOBALS['orange_lab_runtime']);
}

function orange_lab_runtime_is_active(): bool
{
    $dsn = orange_lab_runtime_dsn_matches_lab();
    if (!$dsn['ok']) {
        return false;
    }
    $mem = $GLOBALS['orange_lab_runtime'] ?? null;
    if (is_array($mem) && !empty($mem['active']) && is_string($mem['token'] ?? null) && ($mem['token'] ?? '') !== '') {
        if ((int) ($mem['expires'] ?? 0) >= time()) {
            return true;
        }
    }
    $path = orange_lab_runtime_lock_path();
    if (!is_file($path)) {
        return false;
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return false;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return false;
    }
    if ((int) ($data['expires'] ?? 0) < time()) {
        return false;
    }
    $expectDsn = (string) DB_HOST . ':' . (int) DB_PORT . '/' . (string) DB_NAME;
    if ((string) ($data['dsn'] ?? '') !== $expectDsn) {
        return false;
    }
    $tok = (string) ($data['token'] ?? '');
    if ($tok === '' || strlen($tok) < 32) {
        return false;
    }

    return true;
}

/**
 * تفاصيل قصيرة آمنة لـ UTF-8 (لا substr البايتات على العربية).
 */
function orange_lab_utf8_clip(string $s, int $maxChars = 240): string
{
    if ($maxChars < 8) {
        $maxChars = 8;
    }
    if (function_exists('mb_substr')) {
        return (string) mb_substr($s, 0, $maxChars, 'UTF-8');
    }
    if (strlen($s) <= $maxChars) {
        return $s;
    }

    return substr($s, 0, $maxChars);
}
