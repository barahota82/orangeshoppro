<?php

declare(strict_types=1);

/**
 * توليد password_hash (bcrypt) بدون تسجيل دخول — فقط إذا عُيّن ORANGE_HASH_GENERATOR_KEY في .env.php
 * والرابط يتضمن نفس المفتاح: /admin/hash-generator.php?key=...
 *
 * بعد ضبط كلمة السر في جدول admins: احذف المفتاح من .env.php أو افرغه.
 */
require_once __DIR__ . '/../config.php';
orange_send_html_no_cache_headers();

$provided = (string) ($_GET['key'] ?? '');
if (ORANGE_HASH_GENERATOR_KEY === '' || !hash_equals(ORANGE_HASH_GENERATOR_KEY, $provided)) {
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>404</title></head><body style="font-family:sans-serif;padding:2rem;">غير موجود</body></html>';
    exit;
}

$keyQ = rawurlencode($provided);
$actionUrl = '/admin/hash-generator.php?key=' . $keyQ;

$flashHash = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gen_hash'])) {
    $plain = (string) ($_POST['plain_password'] ?? '');
    if ($plain === '') {
        $_SESSION['orange_hg_err'] = 'أدخل كلمة السر.';
    } else {
        $_SESSION['orange_hg_hash'] = password_hash($plain, PASSWORD_DEFAULT);
    }
    header('Location: ' . $actionUrl, true, 303);
    exit;
}

if (!empty($_SESSION['orange_hg_hash']) && is_string($_SESSION['orange_hg_hash'])) {
    $flashHash = $_SESSION['orange_hg_hash'];
    unset($_SESSION['orange_hg_hash']);
}
if (!empty($_SESSION['orange_hg_err']) && is_string($_SESSION['orange_hg_err'])) {
    $flashErr = $_SESSION['orange_hg_err'];
    unset($_SESSION['orange_hg_err']);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>توليد هاش كلمة السر</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(admin_asset_url('/admin/assets/admin.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="admin-login-page">
    <div class="login-card" style="max-width:520px;">
        <h1>توليد هاش (استعادة وصول)</h1>
        <p class="card-hint" style="margin-bottom:1rem;">
            انسخ الهاش إلى <code>admins.password_hash</code> ثم <strong>احذف</strong> <code>ORANGE_HASH_GENERATOR_KEY</code> من <code>.env.php</code>.
        </p>
        <?php if ($flashErr !== ''): ?>
            <div class="alert-error"><?php echo htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="post" action="<?php echo htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
            <label for="plain_password">كلمة السر الجديدة</label>
            <input type="password" id="plain_password" name="plain_password" required autocomplete="new-password">
            <button type="submit" name="gen_hash" value="1">توليد الهاش</button>
        </form>
        <?php if ($flashHash !== ''): ?>
            <div style="margin-top:1.25rem;">
                <label>النتيجة (انسخها كاملة)</label>
                <textarea readonly rows="3" style="width:100%;font-family:monospace;font-size:12px;"><?php echo htmlspecialchars($flashHash, ENT_QUOTES, 'UTF-8'); ?></textarea>
                <p class="card-hint" style="margin-top:8px;">
                    <code style="word-break:break-all;">UPDATE admins SET password_hash = '<?php echo htmlspecialchars($flashHash, ENT_QUOTES, 'UTF-8'); ?>' WHERE username = 'admin' LIMIT 1;</code>
                </p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
