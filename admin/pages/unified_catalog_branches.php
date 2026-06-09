<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

require_once __DIR__ . '/../../includes/admin_unified_catalog_branch_data.php';
$orange_uc = orange_admin_uc_branch_bootstrap($pdo);
?>
<div class="page-title">
    <h1>فروع شجرة المنتجات</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (!$orange_uc['has_unified_tables']): ?>
<div class="card">
    <div class="alert-error">جدايل الشجرة الموحّدة غير مهيّأة.</div>
</div>
<?php else: ?>

<?php if (! $orange_uc['unified_nav_active']): ?>
<div class="card" style="margin-bottom:12px;background:#fffbeb;border-color:#fcd34d;">
    <p style="margin:0;color:#92400e;">مسار المتجر الموحّد لم يُفعَّل بعد (ترحيل البيانات). يمكن إنشاء الفروع الآن لتجهيز البنية قبل ربط المنتجات بـ <code>product_type_id</code>.</p>
</div>
<?php endif; ?>

<?php if (!empty($orange_uc['deps_empty_for_sections'])): ?>
<div class="card" style="margin-bottom:12px;background:#f0f9ff;border:1px solid #bae6fd;">
    <p style="margin:0;color:#0c4a6e;line-height:1.55;"><strong>الخطوة 1:</strong> لا يوجد قسم رئيسي نشط بعد. أنشئ على الأقل قسماً واحداً من
        <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=departments'), ENT_QUOTES, 'UTF-8'); ?>">الأقسام الرئيسية</a>
        ثم ارجع لهذه الصفحة لإضافة أقسام الكتالوج (Section) تحته.</p>
</div>
<?php endif; ?>

<?php
require __DIR__ . '/../partials/unified_catalog_section_panel.inc.php';
require __DIR__ . '/../partials/unified_catalog_categories_panel.inc.php';
require __DIR__ . '/../partials/unified_catalog_subcategories_panel.inc.php';
require __DIR__ . '/../partials/unified_catalog_branch_style.inc.php';
require __DIR__ . '/../partials/unified_catalog_branch_script.inc.php';
?>

<?php endif; ?>
