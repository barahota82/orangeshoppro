<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/upload_paths.php';
orange_send_html_no_cache_headers();

if (current_admin()) {
    header('Location: ' . storefront_public_path('/admin/index.php?page=dashboard'));
    exit;
}

$error = '';

// يُقبل فقط إرسال النموذج عبر زر «دخول» لتجنب اعتبار أي POST فارغ/عشوائي محاولة تسجيل (بعض الاستضافات/الوكلاء).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username !== '' && $password !== '') {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        $hash = isset($admin['password_hash']) ? (string) $admin['password_hash'] : '';
        if ($admin && $hash !== '' && password_verify($password, $hash)) {
            admin_login((int)$admin['id']);
            header('Location: ' . storefront_public_path('/admin/index.php?page=dashboard'));
            exit;
        }

        if ($admin && ($hash === '' || !str_starts_with($hash, '$2'))) {
            $error = 'حساب المشرف غير مهيأ: عمود password_hash فارغ أو ليس بصيغة bcrypt. حدّثه من phpMyAdmin (قيمة password_hash من PHP) أو من شاشة المستخدمين بعد دخول مشرف عام.';
        } else {
            $error = 'اسم المستخدم أو كلمة المرور غير صحيحة';
        }
    } else {
        $error = 'يرجى إدخال البيانات';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>دخول لوحة التحكم</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@500;600;700&family=Outfit:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(storefront_public_path(admin_asset_url('/admin/assets/admin.css')), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="admin-login-page">
    <div class="login-card">
        <h1>تسجيل الدخول</h1>
        <p class="login-card__hint">لوحة التحكم المؤسسية — مساحة آمنة للفريق الداخلي فقط.</p>
        <?php if ($error): ?>
            <div class="alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="post" action="" autocomplete="off">
            <label>اسم المستخدم</label>
            <input type="text" name="username" required autocomplete="username">

            <label>كلمة المرور</label>
            <input type="password" name="password" required autocomplete="current-password">

            <button type="submit" name="admin_login" value="1">دخول</button>
        </form>
    </div>
</body>
</html>
