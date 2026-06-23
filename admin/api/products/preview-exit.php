<?php

declare(strict_types=1);

/**
 * إنهاء جلسة معاينة المنتج: حذف الكوكي ثم العودة لمتجر الواجهة.
 * المرجع: docs/archive/ORANGE_PRODUCT_PREPUBLISH_PREVIEW_ROLLOUT.txt
 * لا يتطلّب جلسة أدمن — مجرّد مسح كوكي المعاينة الخاص بالمتصفّح (غير ضار).
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/product_preview.php';

$isHttps = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (PHP_VERSION_ID >= 70300) {
    setcookie(orange_preview_cookie_name(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
} else {
    setcookie(orange_preview_cookie_name(), '', time() - 3600, '/; samesite=Lax', '', $isHttps, true);
}
unset($_COOKIE[orange_preview_cookie_name()]);

$home = storefront_url('home', current_channel_slug(), current_lang());
header('Location: ' . $home);
echo 'تم إنهاء المعاينة.';
