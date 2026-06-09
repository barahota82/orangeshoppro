<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/stock_alerts.php';
require_once __DIR__ . '/../../includes/cart_promo_monitor.php';
require_once __DIR__ . '/../../includes/cart_promotion_country.php';

/** @var array<string, mixed> $admin — من admin/index.php */
$pdo = db();
orange_catalog_ensure_schema($pdo);

if (!orange_admin_may($admin, $pdo, 'products', 'view')) {
    echo '<div class="card"><div class="alert-error">غير مصرح بعرض صحة العروض.</div></div>';

    return;
}

$healthCountryId = orange_cart_promotion_admin_country_id($pdo);
$tablesReady = orange_cart_promo_monitor_tables_ready($pdo);
$resync = isset($_GET['resync']) && (string) $_GET['resync'] === '1';
$rows = $tablesReady ? orange_cart_promo_monitor_rows_for_admin($pdo, $healthCountryId, $resync) : [];
$pauseLog = $tablesReady ? orange_cart_promo_recent_pause_log($pdo, $healthCountryId, 40) : [];
$statusLabels = orange_cart_promo_monitor_status_labels_ar();
$lowTh = orange_stock_low_alert_threshold();

$statusClass = static function (string $status): string {
    return match ($status) {
        'would_pause' => 'ocp-health--would',
        'paused' => 'ocp-health--paused',
        'warn' => 'ocp-health--warn',
        'ok' => 'ocp-health--ok',
        default => 'ocp-health--na',
    };
};
?>
<style>
.ocp-health-badge{display:inline-block;padding:2px 10px;border-radius:6px;font-size:.85rem;font-weight:600}
.ocp-health--would{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.ocp-health--paused{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}
.ocp-health--warn{background:#fffbeb;color:#b45309;border:1px solid #fde68a}
.ocp-health--ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
.ocp-health--na{background:#f3f4f6;color:#4b5563;border:1px solid #e5e7eb}
</style>

<div class="page-title">
    <h1>صحة عروض السلة والمنتجات</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (!$tablesReady): ?>
<div class="card">
    <div class="alert-error">جداول المراقبة (v82/v83) غير جاهزة بعد — حدّث الصفحة بعد ترحيل المخطط.</div>
</div>
<?php else: ?>

<div class="card" style="margin-bottom:16px;">
    <h3>إجراءات</h3>
    <p class="card-hint" style="margin:0 0 12px;">
        <strong>تحديث الفحص:</strong> يعيد حساب الحالة دون إيقاف جديد.
        <strong>فحص وإيقاف:</strong> يوقف ما نفد مخزونه (نفس زر الرئيسية).
    </p>
    <div class="admin-form-actions" style="margin:0;">
        <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=cart_promo_health&resync=1'), ENT_QUOTES, 'UTF-8'); ?>">تحديث الفحص (قراءة فقط)</a>
        <button type="button" class="btn-secondary" id="cph_run_pause_btn">فحص وإيقاف الآن</button>
    </div>
</div>

<div class="card">
    <h3>آخر فحص — قواعد ضمن الفترة أو موقوفة مؤقتاً</h3>
    <?php if ($rows === []): ?>
    <p class="muted">لا سجلات بعد. اضغط «تحديث الفحص» أو «فحص وإيقاف».</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>النوع</th>
                    <th>#</th>
                    <th>الحالة</th>
                    <th>التفاصيل</th>
                    <th>آخر فحص</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r):
                    $st = (string) ($r['status'] ?? '');
                    $pg = (string) ($r['admin_page'] ?? '');
                    $rid = (int) ($r['id'] ?? 0);
                    $href = storefront_public_path('/admin/index.php?page=' . rawurlencode($pg));
                    ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) ($r['kind'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo $rid; ?></td>
                    <td>
                        <span class="ocp-health-badge <?php echo htmlspecialchars($statusClass($st), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($statusLabels[$st] ?? $st, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars((string) ($r['detail'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr" class="muted"><?php echo htmlspecialchars(substr((string) ($r['checked_at'] ?? ''), 0, 16), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><a href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3>سجل الإيقاف التلقائي</h3>
    <p class="card-hint" style="margin:0 0 10px;">كل إيقاف جديد من المحرك أو مسار المتجر يُسجَّل هنا (تدقيق).</p>
    <?php if ($pauseLog === []): ?>
    <p class="muted">لا أحداث إيقاف مسجّلة بعد.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>النوع</th>
                    <th>#</th>
                    <th>السبب</th>
                    <th>الوقت</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pauseLog as $ev):
                    $pg = (string) ($ev['admin_page'] ?? '');
                    $href = storefront_public_path('/admin/index.php?page=' . rawurlencode($pg));
                    ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) ($ev['kind'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo (int) ($ev['id'] ?? 0); ?></td>
                    <td><?php echo htmlspecialchars((string) ($ev['reason_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr" class="muted"><?php echo htmlspecialchars(substr((string) ($ev['paused_at'] ?? ''), 0, 16), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><a href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
document.getElementById('cph_run_pause_btn')?.addEventListener('click', async function () {
    if (!confirm('تشغيل فحص مخزون كل العروض النشطة وإيقاف النافد؟')) return;
    var res = await postJSON('/admin/api/cart_promo_stock_health/run.php', { all_countries: false });
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) {
        window.location.href = <?php echo json_encode(storefront_public_path('/admin/index.php?page=cart_promo_health&resync=1'), JSON_UNESCAPED_UNICODE); ?>;
    }
});
</script>

<?php endif; ?>
