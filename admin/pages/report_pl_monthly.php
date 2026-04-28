<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/upload_paths.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

?>
<div class="admin-fy-shell" dir="rtl">
    <h1 class="admin-fy-shell__title">قائمة إيرادات ومصروفات شهرية</h1>
    <p class="admin-fy-shell__lead">هذه الشاشة قيد التطوير — ستعرض مجاميع شهرية وفق حسابات الإيراد والمصروف.</p>
    <div class="card admin-fy-card">
        <p class="card-hint">كمؤقت استخدم <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=report_gl_account_monthly'), ENT_QUOTES, 'UTF-8'); ?>">الحركة الشهرية لحساب</a> لحساب معيّن.</p>
    </div>
</div>
