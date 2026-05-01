<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

require_once __DIR__ . '/../../includes/admin_unified_catalog_branch_data.php';
$orange_uc = orange_admin_uc_branch_bootstrap($pdo);
?>
<div class="page-title">
    <h1>فروع الشجرة الموحّدة (عرض شامل)</h1>
    <p class="page-subtitle" style="margin:0.35rem 0 0;font-size:0.95rem;color:#555;line-height:1.5;">نفس المستويات مُقسّمة في القائمة: <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=categories'), ENT_QUOTES, 'UTF-8'); ?>">أقسام داخلية</a>، ثم <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=subcategories'), ENT_QUOTES, 'UTF-8'); ?>">الفئة والفرع (موحّد)</a>. المسار وفق السياسة: Department → Section → Category → Subcategory → <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=product_types'), ENT_QUOTES, 'UTF-8'); ?>">نوع منتج</a> ثم SKU عبر <code>product_type_id</code>. القسم العلوي من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=departments'), ENT_QUOTES, 'UTF-8'); ?>">الأقسام الرئيسية</a>.</p>
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

<?php
require __DIR__ . '/../partials/unified_catalog_section_panel.inc.php';
require __DIR__ . '/../partials/unified_catalog_categories_panel.inc.php';
require __DIR__ . '/../partials/unified_catalog_subcategories_panel.inc.php';
require __DIR__ . '/../partials/unified_catalog_branch_style.inc.php';
require __DIR__ . '/../partials/unified_catalog_branch_script.inc.php';
?>

<?php endif; ?>
