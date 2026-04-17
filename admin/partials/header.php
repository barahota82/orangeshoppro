<?php
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
if (!isset($admin)) {
    $admin = require_admin_page();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>لوحة الإدارة</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/admin/assets/admin.css">
    <script src="/admin/assets/admin.js" defer></script>
</head>
<body>
<div class="admin-layout">
    <aside class="admin-sidebar">
        <div class="brand">Orange Admin</div>
        <div class="admin-user">مرحباً، <?php echo htmlspecialchars($admin['display_name'] ?: $admin['username']); ?></div>
        <nav>
            <a href="/admin/index.php?page=dashboard">الرئيسية</a>
            <a href="/admin/index.php?page=departments">الأقسام</a>
            <a href="/admin/index.php?page=categories">الفئات</a>
            <a href="/admin/index.php?page=color_dictionary">قاموس الألوان</a>
            <a href="/admin/index.php?page=size_families">عائلات المقاسات</a>
            <a href="/admin/index.php?page=products">المنتجات</a>
            <a href="/admin/index.php?page=offers">العروض</a>
            <a href="/admin/index.php?page=orders">الطلبات</a>
            <a class="admin-nav-sub" href="/admin/index.php?page=invoice">فاتورة مبيعات</a>
            <a class="admin-nav-sub" href="/admin/index.php?page=manual_order">فاتورة شركة</a>
            <a href="/admin/index.php?page=purchases">المشتريات</a>
            <a href="/admin/index.php?page=stock">المخزون</a>
            <a href="/admin/index.php?page=chart_of_accounts">الدليل المحاسبي</a>
            <a href="/admin/index.php?page=expenses">المصروفات</a>
            <a href="/admin/index.php?page=journal_entries">القيود المحاسبية</a>
            <a href="/admin/index.php?page=reports">التقارير</a>
            <a href="/admin/index.php?page=financial_report">التقارير المالية</a>
            <a href="/admin/index.php?page=logs">سجل النشاط</a>
            <a href="/admin/index.php?page=company_settings">بيانات الشركة</a>
            <a href="/admin/index.php?page=channels">الواجهات</a>
            <a href="/admin/logout.php">تسجيل الخروج</a>
        </nav>
    </aside>
    <main class="admin-main">
