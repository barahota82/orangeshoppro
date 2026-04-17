<?php
require_once __DIR__ . '/../config.php';

if (current_admin()) {
    header('Location: /admin/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username !== '' && $password !== '') {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            admin_login((int)$admin['id']);
            header('Location: /admin/index.php');
            exit;
        }

        $error = 'اسم المستخدم أو كلمة المرور غير صحيحة';
    } else {
        $error = 'يرجى إدخال البيانات';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>دخول الأدمن</title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-login-page">
    <div class="login-card">
        <h1>لوحة الإدارة</h1>
        <?php if ($error): ?>
            <div class="alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="post">
            <label>اسم المستخدم</label>
            <input type="text" name="username" required>

            <label>كلمة المرور</label>
            <input type="password" name="password" required>

            <button type="submit">دخول</button>
        </form>
    </div>
</body>
</html>
