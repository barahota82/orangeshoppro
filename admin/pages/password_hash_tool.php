<?php

declare(strict_types=1);

/** @var array<string, mixed> $admin — من admin/index.php */

$flashHash = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $plain = (string) ($_POST['plain_password'] ?? '');
    if ($plain === '') {
        $_SESSION['orange_ph_flash_err'] = 'أدخل كلمة السر المراد تشفيرها.';
    } else {
        $_SESSION['orange_ph_flash_hash'] = password_hash($plain, PASSWORD_DEFAULT);
        if (function_exists('audit_log')) {
            audit_log('password_hash_tool', 'استخدام أداة توليد هاش كلمة السر (دون تسجيل النص)', 'admins', (int) ($admin['id'] ?? 0));
        }
    }
    header('Location: /admin/index.php?page=password_hash_tool', true, 303);
    exit;
}

if (!empty($_SESSION['orange_ph_flash_hash']) && is_string($_SESSION['orange_ph_flash_hash'])) {
    $flashHash = $_SESSION['orange_ph_flash_hash'];
    unset($_SESSION['orange_ph_flash_hash']);
}
if (!empty($_SESSION['orange_ph_flash_err']) && is_string($_SESSION['orange_ph_flash_err'])) {
    $flashErr = $_SESSION['orange_ph_flash_err'];
    unset($_SESSION['orange_ph_flash_err']);
}
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>توليد هاش كلمة السر</h1>
        <p class="page-subtitle">
            للمشرف العام فقط. تكتب كلمة السر مرة واحدة ثم تُعرض <strong>الهاش فقط</strong>؛ الحقل يُفرغ تلقائياً بعد الإرسال (لا يبقى النص في الصفحة عند التحديث).
            انسخ الهاش إلى قاعدة البيانات أو استخدم «المستخدمون والصلاحيات» لتحديث كلمة السر من الواجهة.
        </p>
    </div>
</div>

<div class="card" style="max-width:640px;">
    <h2 class="card-title">إدخال</h2>
    <?php if ($flashErr !== ''): ?>
        <p class="card-hint" style="color:#b91c1c;"><?php echo htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <form method="post" action="/admin/index.php?page=password_hash_tool" class="form-grid" autocomplete="off">
        <div style="grid-column:1/-1;">
            <label for="plain_password">كلمة السر (تُزال من المتصفح بعد التوليد)</label>
            <input type="password" id="plain_password" name="plain_password" required
                   autocomplete="new-password" style="max-width:100%;">
        </div>
        <div style="grid-column:1/-1;">
            <button type="submit">توليد الهاش</button>
        </div>
    </form>
</div>

<?php if ($flashHash !== ''): ?>
<div class="card" style="max-width:640px; margin-top:16px;">
    <h2 class="card-title">النتيجة</h2>
    <p class="card-hint">انسخ السطر كاملاً (يبدأ عادة بـ <code>$2y$</code>) ثم يمكنك إغلاق الصفحة؛ كلمة السر الأصلية لم تُخزَّن.</p>
    <textarea readonly rows="3" style="width:100%; font-family:monospace; font-size:13px;"><?php echo htmlspecialchars($flashHash, ENT_QUOTES, 'UTF-8'); ?></textarea>
    <p class="card-hint" style="margin-top:12px; margin-bottom:0;">
        مثال SQL (عدّل اسم المستخدم):<br>
        <code style="word-break:break-all;">UPDATE admins SET password_hash = '<?php echo htmlspecialchars($flashHash, ENT_QUOTES, 'UTF-8'); ?>' WHERE username = 'admin' LIMIT 1;</code>
    </p>
</div>
<?php endif; ?>
