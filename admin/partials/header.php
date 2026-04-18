<?php
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
if (!isset($admin)) {
    $admin = require_admin_page();
}
$orangeAdminPage = isset($page) ? (string) $page : 'dashboard';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';
$pdoNav = db();
orange_catalog_ensure_schema($pdoNav);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>لوحة الإدارة</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(admin_asset_url('/admin/assets/admin.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <script src="<?php echo htmlspecialchars(admin_asset_url('/admin/assets/admin.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <script src="<?php echo htmlspecialchars(admin_asset_url('/admin/assets/admin-money-fields.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</head>
<body>
<div class="admin-layout">
    <aside class="admin-sidebar">
        <div class="brand">Orange Admin</div>
        <div class="admin-user">مرحباً، <?php echo htmlspecialchars($admin['display_name'] ?: $admin['username']); ?></div>
        <nav>
            <?php
            /**
             * تقسيم القائمة: ١ المحاسبة والذمم — ٢ المخازن والمبيعات والمشتريات — إعدادات عامة.
             *
             * @param array{page:string,href:string,label:string,class:string,sub:bool} $nl
             */
            $orangeRenderNavLink = static function (array $nl) use ($admin, $pdoNav, $orangeAdminPage): void {
                if (!orange_admin_nav_visible($admin, $pdoNav, $nl['page'])) {
                    return;
                }
                $active = ($nl['page'] === 'stock' && ($orangeAdminPage === 'stock' || $orangeAdminPage === 'item_card'))
                    || $orangeAdminPage === $nl['page'];
                $cls = trim($nl['class'] . ($active ? ' is-active' : ''));
                echo '<a href="' . htmlspecialchars($nl['href'], ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($nl['label'], ENT_QUOTES, 'UTF-8') . '</a>';
            };

            $navDashboard = [
                ['page' => 'dashboard', 'href' => '/admin/index.php?page=dashboard', 'label' => 'الرئيسية', 'class' => '', 'sub' => false],
            ];

            $navAccounting = [
                ['page' => 'chart_of_accounts', 'href' => '/admin/index.php?page=chart_of_accounts', 'label' => 'الدليل المحاسبي', 'class' => '', 'sub' => false],
                ['page' => 'fiscal_years', 'href' => '/admin/index.php?page=fiscal_years', 'label' => 'السنوات المالية', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'opening_balances', 'href' => '/admin/index.php?page=opening_balances', 'label' => 'أرصدة أول المدة المالية', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'gl_account_settings', 'href' => '/admin/index.php?page=gl_account_settings', 'label' => 'حسابات القيود التلقائية', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'partner_ledger', 'href' => '/admin/index.php?page=partner_ledger', 'label' => 'ذمم العملاء والموردين', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'partner_reports', 'href' => '/admin/index.php?page=partner_reports', 'label' => 'تقارير الذمم الشاملة', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'expenses', 'href' => '/admin/index.php?page=expenses', 'label' => 'المصروفات', 'class' => '', 'sub' => false],
                ['page' => 'journal_entries', 'href' => '/admin/index.php?page=journal_entries', 'label' => 'القيود المحاسبية', 'class' => '', 'sub' => false],
                ['page' => 'financial_report', 'href' => '/admin/index.php?page=financial_report', 'label' => 'التقارير المالية', 'class' => '', 'sub' => false],
                ['page' => 'logs', 'href' => '/admin/index.php?page=logs', 'label' => 'سجل النشاط', 'class' => 'admin-nav-sub', 'sub' => true],
            ];

            $navOps = [
                ['page' => 'departments', 'href' => '/admin/index.php?page=departments', 'label' => 'الأقسام', 'class' => '', 'sub' => false],
                ['page' => 'categories', 'href' => '/admin/index.php?page=categories', 'label' => 'الفئات', 'class' => '', 'sub' => false],
                ['page' => 'subcategories', 'href' => '/admin/index.php?page=subcategories', 'label' => 'فئات فرعية', 'class' => '', 'sub' => false],
                ['page' => 'color_dictionary', 'href' => '/admin/index.php?page=color_dictionary', 'label' => 'قاموس الألوان', 'class' => '', 'sub' => false],
                ['page' => 'size_families', 'href' => '/admin/index.php?page=size_families', 'label' => 'عائلات المقاسات', 'class' => '', 'sub' => false],
                ['page' => 'products', 'href' => '/admin/index.php?page=products', 'label' => 'المنتجات', 'class' => '', 'sub' => false],
                ['page' => 'offers', 'href' => '/admin/index.php?page=offers', 'label' => 'العروض', 'class' => '', 'sub' => false],
                ['page' => 'orders', 'href' => '/admin/index.php?page=orders', 'label' => 'الطلبات', 'class' => '', 'sub' => false],
                ['page' => 'invoice', 'href' => '/admin/index.php?page=invoice', 'label' => 'فاتورة مبيعات', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'manual_order', 'href' => '/admin/index.php?page=manual_order', 'label' => 'فاتورة شركة', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'purchases', 'href' => '/admin/index.php?page=purchases', 'label' => 'المشتريات', 'class' => '', 'sub' => false],
                ['page' => 'stock', 'href' => '/admin/index.php?page=stock', 'label' => 'المستودع', 'class' => '', 'sub' => false],
                ['page' => 'reports', 'href' => '/admin/index.php?page=reports', 'label' => 'تقارير المبيعات', 'class' => 'admin-nav-sub', 'sub' => true],
            ];

            $navSettings = [
                ['page' => 'company_settings', 'href' => '/admin/index.php?page=company_settings', 'label' => 'بيانات الشركة', 'class' => '', 'sub' => false],
                ['page' => 'channels', 'href' => '/admin/index.php?page=channels', 'label' => 'الواجهات (المتجر)', 'class' => '', 'sub' => false],
                ['page' => 'admin_users', 'href' => '/admin/index.php?page=admin_users', 'label' => 'المستخدمون والصلاحيات', 'class' => 'admin-nav-sub', 'sub' => true],
            ];

            foreach ($navDashboard as $nl) {
                $orangeRenderNavLink($nl);
            }

            echo '<div class="admin-nav-section-title" role="presentation">١ — المحاسبة والذمم</div>';
            foreach ($navAccounting as $nl) {
                $orangeRenderNavLink($nl);
            }

            echo '<div class="admin-nav-section-title" role="presentation">٢ — المخازن والمبيعات والمشتريات</div>';
            foreach ($navOps as $nl) {
                $orangeRenderNavLink($nl);
            }

            echo '<div class="admin-nav-section-title admin-nav-section-title--muted" role="presentation">إعدادات عامة</div>';
            foreach ($navSettings as $nl) {
                $orangeRenderNavLink($nl);
            }
            ?>
            <a href="/admin/logout.php">تسجيل الخروج</a>
        </nav>
    </aside>
    <main class="admin-main">
