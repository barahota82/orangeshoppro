<?php
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
if (!isset($admin)) {
    $admin = require_admin_page();
}
$orangeAdminPage = isset($page) ? (string) $page : 'dashboard';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>لوحة الإدارة</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(admin_asset_url('/admin/assets/admin.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <script src="<?php echo htmlspecialchars(admin_asset_url('/admin/assets/admin.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</head>
<body>
<div class="admin-layout">
    <aside class="admin-sidebar">
        <div class="brand">Orange Admin</div>
        <div class="admin-user">مرحباً، <?php echo htmlspecialchars($admin['display_name'] ?: $admin['username']); ?></div>
        <nav>
            <a href="/admin/index.php?page=dashboard" class="<?php echo $orangeAdminPage === 'dashboard' ? 'is-active' : ''; ?>">الرئيسية</a>
            <a href="/admin/index.php?page=departments" class="<?php echo $orangeAdminPage === 'departments' ? 'is-active' : ''; ?>">الأقسام</a>
            <a href="/admin/index.php?page=categories" class="<?php echo $orangeAdminPage === 'categories' ? 'is-active' : ''; ?>">الفئات</a>
            <a href="/admin/index.php?page=color_dictionary" class="<?php echo $orangeAdminPage === 'color_dictionary' ? 'is-active' : ''; ?>">قاموس الألوان</a>
            <a href="/admin/index.php?page=size_families" class="<?php echo $orangeAdminPage === 'size_families' ? 'is-active' : ''; ?>">عائلات المقاسات</a>
            <a href="/admin/index.php?page=products" class="<?php echo $orangeAdminPage === 'products' ? 'is-active' : ''; ?>">المنتجات</a>
            <a href="/admin/index.php?page=offers" class="<?php echo $orangeAdminPage === 'offers' ? 'is-active' : ''; ?>">العروض</a>
            <a href="/admin/index.php?page=orders" class="<?php echo $orangeAdminPage === 'orders' ? 'is-active' : ''; ?>">الطلبات</a>
            <a class="admin-nav-sub<?php echo $orangeAdminPage === 'invoice' ? ' is-active' : ''; ?>" href="/admin/index.php?page=invoice">فاتورة مبيعات</a>
            <a class="admin-nav-sub<?php echo $orangeAdminPage === 'manual_order' ? ' is-active' : ''; ?>" href="/admin/index.php?page=manual_order">فاتورة شركة</a>
            <a href="/admin/index.php?page=purchases" class="<?php echo $orangeAdminPage === 'purchases' ? 'is-active' : ''; ?>">المشتريات</a>
            <a href="/admin/index.php?page=stock" class="<?php echo $orangeAdminPage === 'stock' ? 'is-active' : ''; ?>">المخزون</a>
            <a href="/admin/index.php?page=chart_of_accounts" class="<?php echo $orangeAdminPage === 'chart_of_accounts' ? 'is-active' : ''; ?>">الدليل المحاسبي</a>
            <a href="/admin/index.php?page=expenses" class="<?php echo $orangeAdminPage === 'expenses' ? 'is-active' : ''; ?>">المصروفات</a>
            <a href="/admin/index.php?page=journal_entries" class="<?php echo $orangeAdminPage === 'journal_entries' ? 'is-active' : ''; ?>">القيود المحاسبية</a>
            <a href="/admin/index.php?page=reports" class="<?php echo $orangeAdminPage === 'reports' ? 'is-active' : ''; ?>">التقارير</a>
            <a href="/admin/index.php?page=financial_report" class="<?php echo $orangeAdminPage === 'financial_report' ? 'is-active' : ''; ?>">التقارير المالية</a>
            <a href="/admin/index.php?page=logs" class="<?php echo $orangeAdminPage === 'logs' ? 'is-active' : ''; ?>">سجل النشاط</a>
            <a href="/admin/index.php?page=company_settings" class="<?php echo $orangeAdminPage === 'company_settings' ? 'is-active' : ''; ?>">بيانات الشركة</a>
            <a href="/admin/index.php?page=channels" class="<?php echo $orangeAdminPage === 'channels' ? 'is-active' : ''; ?>">الواجهات</a>
            <a href="/admin/logout.php">تسجيل الخروج</a>
        </nav>
    </aside>
    <main class="admin-main">
