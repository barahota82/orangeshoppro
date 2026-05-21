<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/stock_alerts.php';
require_once __DIR__ . '/../../includes/countries.php';

/** @var array<string, mixed> $admin — من admin/index.php */
$pdo = db();
$dashCountryId = orange_admin_context_country_id($pdo);
$dashOrdersSql = orange_sql_country_and_fragment($pdo, 'orders', 'orders', $dashCountryId);
$dashOrdersAliasSql = orange_sql_country_and_fragment($pdo, 'orders', 'o', $dashCountryId);
$dashProductsSql = orange_sql_country_and_fragment($pdo, 'products', 'p', $dashCountryId);
if (!orange_admin_is_superuser($admin) && orange_admin_permissions_matrix($pdo, (int) $admin['id']) === []) {
    echo '<div class="card" style="border:1px solid #f59e0b; background:#fffbeb; margin-bottom:16px;">'
        . '<p style="margin:0;"><strong>تنبيه:</strong> حسابك بدون صلاحيات مفصّلة بعد. يمكنك عرض هذه الصفحة فقط حتى يحدّد المشرف العام صلاحياتك من «المستخدمون والصلاحيات»، أو يُفعَّل لك عمود <code>is_superuser = 1</code> في قاعدة البيانات.</p>'
        . '</div>';
}
$ordersToday = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()" . $dashOrdersSql)->fetchColumn();
$salesToday = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at) = CURDATE()" . $dashOrdersSql)->fetchColumn();
$pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'" . $dashOrdersSql)->fetchColumn();
$productsCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE 1=1" . orange_sql_country_and_fragment($pdo, 'products', 'products', $dashCountryId))->fetchColumn();

$lowStockThDash = orange_stock_low_alert_threshold();
$stLowDash = $pdo->prepare(
    'SELECT COUNT(*) FROM product_variants pv
     INNER JOIN products p ON p.id = pv.product_id
     WHERE p.is_active = 1 AND pv.stock_quantity <= ?' . $dashProductsSql
);
$stLowDash->execute([$lowStockThDash]);
$lowStockVariantsDash = (int) $stLowDash->fetchColumn();

$intakePending = 0;
$intakeFailed = 0;
$intakeQueueVisible = orange_admin_may($admin, $pdo, 'sales', 'view')
    && orange_table_exists($pdo, 'order_intake_queue');
if ($intakeQueueVisible) {
    try {
        $intakePending = (int) $pdo->query(
            "SELECT COUNT(*) FROM order_intake_queue WHERE status = 'pending'"
        )->fetchColumn();
        $intakeFailed = (int) $pdo->query(
            "SELECT COUNT(*) FROM order_intake_queue WHERE status = 'failed'"
        )->fetchColumn();
    } catch (Throwable $e) {
        $intakePending = 0;
        $intakeFailed = 0;
    }
}
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
    <?php if (orange_admin_may($admin, $pdo, 'warehouse', 'view')): ?>
    <div class="card stat-card" style="<?php echo $lowStockVariantsDash > 0 ? 'border:1px solid #f59e0b;' : ''; ?>">
        <h3>قارب على النفاذ</h3>
        <div class="value"><?php echo $lowStockVariantsDash; ?></div>
        <p class="card-hint" style="margin:8px 0 0;font-size:0.88rem;">متغيرات ≤ <?php echo (int) $lowStockThDash; ?> — نشطة</p>
        <p style="margin:10px 0 0;"><a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=stock#low-stock-variants'), ENT_QUOTES, 'UTF-8'); ?>">المستودع</a></p>
    </div>
    <?php endif; ?>
</div>

<?php if ($intakeQueueVisible): ?>
<div class="card" style="margin-bottom:16px;">
    <h3>طابور طلبات الموقع (واجهة الزوار)</h3>
    <p class="card-hint" style="margin:0 0 10px;">
        طلبات السلة تُسجَّل هنا قبل إنشاء سجل الطلب بالتسلسل.
        معلّق: <strong><?php echo (int) $intakePending; ?></strong>
        — فاشل: <strong><?php echo (int) $intakeFailed; ?></strong>
    </p>
    <p style="margin:0;">
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=order_intake_queue'), ENT_QUOTES, 'UTF-8'); ?>">إدارة الطابور</a>
        <?php if ($intakePending > 0 || $intakeFailed > 0): ?>
            <span class="muted" style="margin-inline-start:8px;">راجع الفاشل أو شغّل «معالجة يدوية» عند الحاجة.</span>
        <?php endif; ?>
    </p>
</div>
<?php endif; ?>

<?php if (orange_admin_is_superuser($admin)): ?>
<div class="card">
    <h3>التحقق من نشر الكود</h3>
    <p class="small">إذا كان النشر أوتوماتيكياً ولا ترى تغييرات في الشاشات، افتح الرابط التالي — يجب أن يظهر JSON وكل عناصر <code>markers</code> تكون <code>true</code>. (اختياري: عرّف <code>ORANGE_BUILD_REF</code> في <code>.env.php</code> ليظهر مرجع البناء.)</p>
    <p style="margin:0;"><a href="<?php echo htmlspecialchars(storefront_public_path('/admin/api/system/deploy-check.php'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">فتح فحص النشر</a></p>
</div>
<?php endif; ?>

<div class="card">
    <h3>عن هذه اللوحة</h3>
    <p class="card-hint" style="margin:0;">واجهة موحّدة للمخزون، المبيعات، المشتريات، المحاسبة، الذمم، وأرشيف المستندات. يُنصح بضبط <strong>بيانات الشركة</strong> و<strong>قنوات العملاء</strong> و<strong>حسابات القيود التلقائية</strong> قبل التوسع في الفريق.</p>
</div>
