<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=UTF-8');

/**
 * بدون HEALTH_CHECK_KEY في .env.php: ردّ بسيط فقط (لا يكشف اتصال DB أو أعداد جداول).
 * مع مفتاح: أرسل ?key=... مطابقاً لإظهار فحص DB/الجلسة (للمراقبة الداخلية فقط).
 */
$key = trim((string) ($env['HEALTH_CHECK_KEY'] ?? ''));
$provided = isset($_GET['key']) ? (string) $_GET['key'] : '';

if ($key === '') {
    echo "OK\n";
    exit;
}

if ($provided === '' || !hash_equals($key, $provided)) {
    http_response_code(404);
    exit;
}

echo "PHP OK\n";

try {
    $pdo = db();
    echo "DB OK\n";
    $r = $pdo->query('SELECT COUNT(*) c FROM admins')->fetch();
    echo 'admins table OK, count=' . (int) ($r['c'] ?? 0) . "\n";
} catch (Throwable $e) {
    echo 'DB/admins ERROR: ' . $e->getMessage() . "\n";
}

try {
    $_SESSION['__t'] = '1';
    echo "SESSION OK\n";
} catch (Throwable $e) {
    echo 'SESSION ERROR: ' . $e->getMessage() . "\n";
}
