<?php
$pdo = db();
$ordersToday = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$salesToday = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$productsCount = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
?>
<div class="page-title">
    <h1>الرئيسية</h1>
</div>

<div class="grid-4">
    <div class="card stat-card">
        <h3>طلبات اليوم</h3>
        <div class="value"><?php echo $ordersToday; ?></div>
    </div>
    <div class="card stat-card">
        <h3>مبيعات اليوم</h3>
        <div class="value"><?php echo number_format($salesToday, 2); ?> KD</div>
    </div>
    <div class="card stat-card">
        <h3>طلبات pending</h3>
        <div class="value"><?php echo $pendingOrders; ?></div>
    </div>
    <div class="card stat-card">
        <h3>عدد المنتجات</h3>
        <div class="value"><?php echo $productsCount; ?></div>
    </div>
</div>

<div class="card">
    <h3>ملاحظات</h3>
    <p class="small">هذه المرحلة تضيف لوحة إدارة كاملة: تسجيل دخول، منتجات، فئات، طلبات، مخزون، عروض، تقارير، وواجهات.</p>
</div>
