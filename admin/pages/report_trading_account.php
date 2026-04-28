<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/upload_paths.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

?>
<div class="admin-fy-shell" dir="rtl">
    <h1 class="admin-fy-shell__title">قائمة حساب المتاجرة</h1>
    <p class="admin-fy-shell__lead">هذه الشاشة قيد التطوير — ستُبنى وفق تعريف المتاجرة في دليلكم (مبيعات، تكلفة، مجمل ربح، إلخ).</p>
    <div class="card admin-fy-card">
        <p class="card-hint">مؤقتاً راجع <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=financial_report#report-income'), ENT_QUOTES, 'UTF-8'); ?>">قائمة الدخل</a> ضمن التقارير المالية.</p>
    </div>
</div>
