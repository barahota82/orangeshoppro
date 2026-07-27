<?php

declare(strict_types=1);

/**
 * FSR Batch A — email-track shared server-side rate limit (FSR-SEC-03).
 *
 * Uses SQLite in-memory / temp-file fixtures mirroring orange_admin_login_throttle
 * so the suite is safe without Production DB / .env.php / real email.
 *
 * Usage: php scripts/self_test_final_review_email_track_rate_limit.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;
$skipped = 0;

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function et_assert(bool $ok, string $label): void
{
    global $failures, $passes;
    if ($ok) {
        echo "PASS  {$label}\n";
        $passes++;
    } else {
        echo "FAIL  {$label}\n";
        $failures++;
    }
}

if (!defined('DB_PASS')) {
    define('DB_PASS', 'test_pepper_secret_for_fsr_batch_a');
}

require_once $root . '/includes/admin_login_rate_limit.php';

if (!function_exists('orange_table_exists')) {
    function orange_table_exists(PDO $pdo, string $table): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
            $st->execute([$table]);

            return (bool) $st->fetchColumn();
        }

        return false;
    }
}

require_once $root . '/includes/storefront_email_track_rate_limit.php';

function et_sqlite_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE orange_admin_login_throttle (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scope_type TEXT NOT NULL,
            scope_key TEXT NOT NULL,
            failed_count INTEGER NOT NULL DEFAULT 0,
            window_started_at TEXT NOT NULL,
            locked_until TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL,
            UNIQUE (scope_type, scope_key)
        )'
    );

    return $pdo;
}

$pdo = et_sqlite_pdo();
et_assert(orange_email_track_rate_limit_table_ready($pdo), 'throttle table ready on SQLite fixture');

$schemaSrc = (string) file_get_contents($root . '/includes/catalog_schema.php');
et_assert(
    str_contains($schemaSrc, "define('ORANGE_CATALOG_SCHEMA_PHP_REVISION', 124)"),
    'schema revision remains 124 in catalog_schema.php'
);

$baseNow = time();
$ipA = '203.0.113.10';
$ipB = '203.0.113.11';
$order = 'ORD-ET-TEST-' . bin2hex(random_bytes(3));
$phone = '+96550001111';
$emailNorm = 'user@example.com';
$country = 1;

$fp1 = orange_email_track_rate_limit_subject_fingerprint($order, $phone, $emailNorm, $country);
et_assert(
    $fp1 === orange_email_track_rate_limit_subject_fingerprint(trim('  ' . $order . '  '), $phone, $emailNorm, $country),
    'order trim canonicalizes subject fingerprint'
);
$fpUpper = orange_email_track_rate_limit_subject_fingerprint($order, $phone, strtolower('User@Example.COM'), $country);
et_assert($fp1 === $fpUpper, 'email lowercase maps to same fingerprint');
et_assert(
    $fp1 !== orange_email_track_rate_limit_subject_fingerprint($order . 'X', $phone, $emailNorm, $country),
    'different order uses distinct fingerprint'
);
et_assert(
    !str_contains($fp1, $phone) && !str_contains($fp1, $emailNorm) && !str_contains($fp1, $order),
    'fingerprint stores no raw PII'
);

$scopeKey = orange_email_track_rate_limit_scope_key($ipA, $fp1);
et_assert(
    !str_contains($scopeKey, $phone) && !str_contains($scopeKey, $ipA) && !str_contains($scopeKey, $order),
    'scope key stores no raw IP/PII'
);

$r1 = orange_email_track_rate_limit_consume($pdo, $ipA, $fp1, $baseNow);
et_assert($r1['allowed'] === true, '1. first attempt permitted');

$r2 = orange_email_track_rate_limit_consume($pdo, $ipA, $fp1, $baseNow + 10);
et_assert($r2['allowed'] === false && $r2['reason'] === 'cooldown', '2. second inside 90s blocked (cooldown)');
et_assert($r2['retry_after_seconds'] >= 1, '2b. retry_after present');

$r3 = orange_email_track_rate_limit_consume($pdo, $ipA, $fp1, $baseNow + 20);
et_assert($r3['allowed'] === false, '5. without PHP session, shared limiter still blocks');

$afterCool = $baseNow + ORANGE_EMAIL_TRACK_RL_COOLDOWN_SECONDS + 1;
for ($i = 2; $i <= ORANGE_EMAIL_TRACK_RL_MAX_PER_HOUR; $i++) {
    $t = $afterCool + (($i - 2) * (ORANGE_EMAIL_TRACK_RL_COOLDOWN_SECONDS + 1));
    $rx = orange_email_track_rate_limit_consume($pdo, $ipA, $fp1, $t);
    et_assert($rx['allowed'] === true, "hourly fill allow #{$i}");
}
$tBlock = $afterCool + ((ORANGE_EMAIL_TRACK_RL_MAX_PER_HOUR - 1) * (ORANGE_EMAIL_TRACK_RL_COOLDOWN_SECONDS + 1));
$blockedHour = orange_email_track_rate_limit_consume($pdo, $ipA, $fp1, $tBlock);
et_assert($blockedHour['allowed'] === false && $blockedHour['reason'] === 'hourly', '3. more than 8/hour blocked');

$fpOther = orange_email_track_rate_limit_subject_fingerprint($order . '-B', $phone, $emailNorm, $country);
$rOther = orange_email_track_rate_limit_consume($pdo, $ipA, $fpOther, $baseNow);
et_assert($rOther['allowed'] === true, '11. different subject fingerprint distinct bucket');

$rIpB = orange_email_track_rate_limit_consume($pdo, $ipB, $fp1, $baseNow);
et_assert($rIpB['allowed'] === true, '12. same fingerprint other IP separate bucket');

$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.50';
$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
et_assert(orange_email_track_client_ip() === '203.0.113.99', '13. X-Forwarded-For ignored; REMOTE_ADDR only');

$savedTz = date_default_timezone_get();
date_default_timezone_set('Pacific/Kiritimati');
$tProbe = time();
et_assert(
    orange_email_track_parse_utc_mysql(gmdate('Y-m-d H:i:s', $tProbe)) === $tProbe,
    '27/28. UTC parse timezone-independent'
);
date_default_timezone_set($savedTz);

$pdo2 = et_sqlite_pdo();
$sk = orange_email_track_rate_limit_scope_key($ipA, $fp1);
orange_email_track_rate_limit_consume($pdo2, $ipA, $fp1, $baseNow);
$oldWindow = gmdate('Y-m-d H:i:s', $baseNow - ORANGE_EMAIL_TRACK_RL_WINDOW_SECONDS - 10);
$pdo2->prepare(
    'UPDATE orange_admin_login_throttle SET failed_count = 8, window_started_at = ?, locked_until = NULL
     WHERE scope_type = ? AND scope_key = ?'
)->execute([$oldWindow, ORANGE_EMAIL_TRACK_RL_SCOPE, $sk]);
$rExpired = orange_email_track_rate_limit_consume($pdo2, $ipA, $fp1, $baseNow + 5);
et_assert($rExpired['allowed'] === true, '29. expired window becomes available again');

$pdo3 = et_sqlite_pdo();
$seedNow = $baseNow + 100000;
$pdo3->prepare(
    'INSERT INTO orange_admin_login_throttle (scope_type, scope_key, failed_count, window_started_at, locked_until)
     VALUES (?, ?, 7, ?, NULL)'
)->execute([ORANGE_EMAIL_TRACK_RL_SCOPE, $sk, gmdate('Y-m-d H:i:s', $seedNow)]);
$c1 = orange_email_track_rate_limit_consume($pdo3, $ipA, $fp1, $seedNow + 1);
$c2 = orange_email_track_rate_limit_consume($pdo3, $ipA, $fp1, $seedNow + 2);
et_assert($c1['allowed'] === true, '19a. last slot first caller');
et_assert($c2['allowed'] === false, '19b. second cannot consume same last slot');

$dbFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_et_rl_' . bin2hex(random_bytes(4)) . '.sqlite';
$pdoFile = new PDO('sqlite:' . $dbFile);
$pdoFile->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdoFile->exec(
    'CREATE TABLE orange_admin_login_throttle (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        scope_type TEXT NOT NULL,
        scope_key TEXT NOT NULL,
        failed_count INTEGER NOT NULL DEFAULT 0,
        window_started_at TEXT NOT NULL,
        locked_until TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        UNIQUE (scope_type, scope_key)
    )'
);
$pdoFile->prepare(
    'INSERT INTO orange_admin_login_throttle (scope_type, scope_key, failed_count, window_started_at, locked_until)
     VALUES (?, ?, 7, ?, NULL)'
)->execute([ORANGE_EMAIL_TRACK_RL_SCOPE, $sk, gmdate('Y-m-d H:i:s', $seedNow + 500)]);
$pdoFile = null;

$worker = <<<'PHP'
<?php
declare(strict_types=1);
if (!defined('DB_PASS')) {
    define('DB_PASS', 'test_pepper_secret_for_fsr_batch_a');
}
$root = $argv[1];
require_once $root . '/includes/admin_login_rate_limit.php';
if (!function_exists('orange_table_exists')) {
    function orange_table_exists(PDO $pdo, string $table): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
        $st->execute([$table]);
        return (bool) $st->fetchColumn();
    }
}
require_once $root . '/includes/storefront_email_track_rate_limit.php';
$dbFile = $argv[2];
$ip = $argv[3];
$fp = $argv[4];
$now = (int) $argv[5];
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout=5000');
try {
    $r = orange_email_track_rate_limit_consume($pdo, $ip, $fp, $now);
    echo $r['allowed'] ? "ALLOW\n" : "DENY\n";
} catch (Throwable $e) {
    echo "ERR\n";
}
PHP;
$workerFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_et_rl_worker_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($workerFile, $worker);
$phpBin = PHP_BINARY;
$nowPar = $seedNow + 501;
$cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($workerFile) . ' ' . escapeshellarg($root)
    . ' ' . escapeshellarg($dbFile) . ' ' . escapeshellarg($ipA) . ' ' . escapeshellarg($fp1)
    . ' ' . escapeshellarg((string) $nowPar);
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$p1 = proc_open($cmd, $descriptors, $pipes1);
$p2 = proc_open($cmd, $descriptors, $pipes2);
$out1 = is_resource($p1) ? (string) stream_get_contents($pipes1[1]) : '';
$out2 = is_resource($p2) ? (string) stream_get_contents($pipes2[1]) : '';
if (is_resource($p1)) {
    fclose($pipes1[1]);
    fclose($pipes1[2]);
    proc_close($p1);
}
if (is_resource($p2)) {
    fclose($pipes2[1]);
    fclose($pipes2[2]);
    proc_close($p2);
}
@unlink($workerFile);
@unlink($dbFile);
$allows = (str_contains($out1, 'ALLOW') ? 1 : 0) + (str_contains($out2, 'ALLOW') ? 1 : 0);
$denies = (str_contains($out1, 'DENY') ? 1 : 0) + (str_contains($out2, 'DENY') ? 1 : 0);
et_assert($allows === 1 && $denies === 1, '16/concurrency. parallel workers: one ALLOW one DENY');

$ep = (string) file_get_contents($root . '/api/orders/email-track-order-summary.php');
et_assert(str_contains($ep, 'orange_email_track_rate_limit_consume'), 'endpoint uses shared limiter');
et_assert(str_contains($ep, 'email_track_rate_limited'), 'stable 429 code');
et_assert(str_contains($ep, 'Retry-After'), 'Retry-After header');
et_assert(str_contains($ep, 'POST'), 'POST method enforced');
et_assert(
    strpos($ep, 'orange_email_track_rate_limit_consume') < strpos($ep, 'SELECT * FROM orders'),
    'shared throttle before order lookup'
);
et_assert(!preg_match('/error_log\([^)]*\$email/', $ep), '17. no raw email in error_log');
et_assert(str_contains($ep, 'email_track_limiter_unavailable'), 'fail-closed path');

$reqEmail = (string) file_get_contents($root . '/api/auth/request-email-verify.php');
$reqOtp = (string) file_get_contents($root . '/api/auth/request-checkout-email-otp.php');
et_assert(str_contains($reqEmail, 'verify_email_sent_at'), 'sibling verify-email: SERVER_SIDE account cooldown');
et_assert(str_contains($reqOtp, 'otp_sent_at'), 'sibling checkout OTP: SERVER_SIDE account cooldown');
et_assert(!str_contains($reqEmail, 'orange_track_summary_mail_rate'), 'sibling verify-email not session mail-rate');
et_assert(!str_contains($reqOtp, 'orange_track_summary_mail_rate'), 'sibling OTP not session mail-rate');

$_SESSION = [];
$sg1 = orange_email_track_session_rate_limit_check($order, $phone, $emailNorm, $baseNow);
et_assert($sg1['allowed'] === true, '4a. session layer allows first');
orange_email_track_session_rate_limit_record($order, $phone, $emailNorm, $baseNow);
$sg2 = orange_email_track_session_rate_limit_check($order, $phone, $emailNorm, $baseNow + 5);
et_assert($sg2['allowed'] === false, '4b. session cooldown compatible');

$js = (string) file_get_contents($root . '/assets/js/cart.js');
et_assert(str_contains($js, 'email_track_rate_limited'), 'cart.js handles email_track_rate_limited');

$policy = (string) file_get_contents($root . '/docs/archive/ORANGE_STOREFRONT_POLICY_REFERENCE.txt');
et_assert(str_contains($policy, 'FSR-SEC-03'), 'policy archive documents FSR-SEC-03');

echo "\n--- FSR email-track rate limit ---\n";
echo "PASS={$passes} FAIL={$failures} SKIP={$skipped}\n";
exit($failures > 0 ? 1 : 0);
