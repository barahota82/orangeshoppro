<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_permissions.php';

/** @var array<string, mixed> $admin — من admin/index.php */
$pdo = db();
if (!orange_admin_is_superuser($admin) && orange_admin_permissions_matrix($pdo, (int) $admin['id']) === []) {
    echo '<div class="card" style="border:1px solid #f59e0b; background:#fffbeb; margin-bottom:16px;">'
        . '<p style="margin:0;"><strong>تنبيه:</strong> حسابك بدون صلاحيات مفصّلة بعد. يمكنك عرض هذه الصفحة فقط حتى يحدّد المشرف العام صلاحياتك من «المستخدمون والصلاحيات»، أو يُفعَّل لك عمود <code>is_superuser = 1</code> في قاعدة البيانات.</p>'
        . '</div>';
}
$ordersToday = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$salesToday = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$productsCount = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
?>
<div class="page-title page-title--stacked">
    <h1>الرئيسية</h1>
    <p class="page-subtitle">نظرة سريعة على نشاط اليوم — بيانات فورية من قاعدة الطلبات والمنتجات.</p>
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
        <h3>طلبات قيد الانتظار</h3>
        <div class="value"><?php echo $pendingOrders; ?></div>
    </div>
    <div class="card stat-card">
        <h3>عدد المنتجات</h3>
        <div class="value"><?php echo $productsCount; ?></div>
    </div>
</div>

<?php if (orange_admin_is_superuser($admin)): ?>
<div class="card">
    <h3>التحقق من نشر الكود</h3>
    <p class="small">إذا كان النشر أوتوماتيكياً ولا ترى تغييرات في الشاشات، افتح الرابط التالي — يجب أن يظهر JSON وكل عناصر <code>markers</code> تكون <code>true</code>. (اختياري: عرّف <code>ORANGE_BUILD_REF</code> في <code>.env.php</code> ليظهر مرجع البناء.)</p>
    <p style="margin:0;"><a href="/admin/api/system/deploy-check.php" target="_blank" rel="noopener">فتح فحص النشر</a></p>
</div>
<?php endif; ?>

<div class="card">
    <h3>عن هذه اللوحة</h3>
    <p class="card-hint" style="margin:0;">واجهة موحّدة للمخزون، المبيعات، المشتريات، المحاسبة، الذمم، وأرشيف المستندات. يُنصح بضبط <strong>بيانات الشركة</strong> و<strong>قنوات العملاء</strong> و<strong>حسابات القيود التلقائية</strong> قبل التوسع في الفريق.</p>
</div>
