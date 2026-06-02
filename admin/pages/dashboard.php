<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/stock_alerts.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/order_intake_queue.php';
require_once __DIR__ . '/../../includes/warehouses.php';
require_once __DIR__ . '/../../includes/cart_promo_schedule.php';

/** @var array<string, mixed> $admin — من admin/index.php */
$pdo = db();
orange_catalog_ensure_schema($pdo);
$dashPromoPausedAlerts = orange_cart_promo_admin_auto_paused_alerts($pdo);
$dashCountryId = orange_admin_context_country_id($pdo);
$dashMoney = orange_admin_currency_context($pdo);
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
$wQtyDash = orange_warehouse_effective_qty_sql($pdo, $dashCountryId, 'pv', 'wvs_dash');
$stLowDash = $pdo->prepare(
    'SELECT COUNT(*) FROM product_variants pv
     INNER JOIN products p ON p.id = pv.product_id'
    . $wQtyDash['join']
    . ' WHERE p.is_active = 1 AND ' . $wQtyDash['expr'] . ' <= ?' . $dashProductsSql
);
$stLowDash->execute([$lowStockThDash]);
$lowStockVariantsDash = (int) $stLowDash->fetchColumn();

$intakePending = 0;
$intakeFailed = 0;
$intakeQueueVisible = orange_admin_may($admin, $pdo, 'sales', 'view')
    && orange_table_exists($pdo, 'order_intake_queue');
if ($intakeQueueVisible) {
    try {
        $intakeScope = orange_order_intake_sql_country_scope($pdo, 'oiq', $dashCountryId);
        $intakeJoin = $intakeScope !== null ? $intakeScope['join'] : '';
        $intakeWhere = $intakeScope !== null ? $intakeScope['where'] : '';
        $intakeParams = $intakeScope !== null ? $intakeScope['params'] : [];
        $stPending = $pdo->prepare(
            'SELECT COUNT(*) FROM order_intake_queue oiq' . $intakeJoin
            . " WHERE oiq.status = 'pending'" . $intakeWhere
        );
        $stPending->execute($intakeParams);
        $intakePending = (int) $stPending->fetchColumn();
        $stFailed = $pdo->prepare(
            'SELECT COUNT(*) FROM order_intake_queue oiq' . $intakeJoin
            . " WHERE oiq.status = 'failed'" . $intakeWhere
        );
        $stFailed->execute($intakeParams);
        $intakeFailed = (int) $stFailed->fetchColumn();
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

<?php if ($dashPromoPausedAlerts !== []): ?>
<div class="card" style="margin-bottom:16px;border:1px solid #f59e0b;background:#fffbeb;">
    <h3>عروض متوقفة تلقائياً (مخزون)</h3>
    <p class="card-hint" style="margin:0 0 10px;">العرض لا يظهر للعميل. السبب يوضّح إن التوقف لـ <strong>نفاد مخزون منتجات العرض</strong> أو <strong>عدم توفر الهدية</strong>. لتفعيله: عالج المخزون ثم افتح العرض ومدّد «نهاية العرض» واحفظ.</p>
    <ul style="margin:0;padding-inline-start:1.25rem;line-height:1.6;">
        <?php
        foreach ($dashPromoPausedAlerts as $alert):
            $pg = (string) ($alert['page'] ?? $alert['table'] ?? 'cart_gift_promotions');
            $href = storefront_public_path('/admin/index.php?page=' . rawurlencode($pg));
            ?>
        <li>
            <a href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($alert['label'], ENT_QUOTES, 'UTF-8'); ?></a>
            <?php if ($alert['paused_at'] !== ''): ?>
            <span class="muted" dir="ltr"> — <?php echo htmlspecialchars(substr((string) $alert['paused_at'], 0, 16), ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (orange_admin_may($admin, $pdo, 'products', 'view')): ?>
<div class="card" style="margin-bottom:16px;">
    <h3>فحص مخزون العروض</h3>
    <p class="card-hint" style="margin:0 0 10px;">يفحص العروض النشطة ضمن الفترة ويوقف النافد و<strong>يعيد تفعيل</strong> ما أُوقف للمخزون فقط عند عودة الكمية (الفترة سارية + «نشط»). الدولة: حسب اختيارك في لوحة التحكم.</p>
    <button type="button" class="btn-secondary" id="dash_promo_stock_health_btn">فحص العروض الآن</button>
</div>
<script>
document.getElementById('dash_promo_stock_health_btn')?.addEventListener('click', async function () {
    if (!confirm('تشغيل فحص مخزون كل العروض النشطة لهذه الدولة؟')) return;
    var res = await postJSON('/admin/api/cart_promo_stock_health/run.php', { all_countries: false });
    alert(res.message || (res.success ? 'تم الفحص' : 'فشل الفحص'));
    if (res.success) location.reload();
});
</script>
<?php endif; ?>

<div class="grid-4">
    <div class="card stat-card">
        <h3>طلبات اليوم</h3>
        <div class="value"><?php echo $ordersToday; ?></div>
    </div>
    <div class="card stat-card">
        <h3>مبيعات اليوم</h3>
        <div class="value"><?php echo orange_format_money_for_context($dashMoney, $salesToday); ?></div>
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
