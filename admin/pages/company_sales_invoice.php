<?php

declare(strict_types=1);

/**
 * GAP-SALE-DOC-01 — فاتورة مبيعات شركة (INV-C) — مستند v2.
 * المرحلة 0: تسجيل الصفحة + APIs؛ واجهة المستند الكاملة في المرحلة 1.
 */

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$sv2Caps = orange_admin_caps_for_page($admin, $pdo, 'company_sales_invoice');

$indexBase = storefront_public_path('/admin/index.php');
$legacyManual = htmlspecialchars($indexBase . '?page=manual_order', ENT_QUOTES, 'UTF-8');
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>فاتورة مبيعات</h1>
        <p class="page-subtitle card-hint" style="margin:0.35rem 0 0;">
            جاري بناء شاشة المستند الموحّدة (مثل المشتريات) — المرحلة 1.
            حتى اكتمالها استخدم
            <a href="<?php echo $legacyManual; ?>">فاتورة مبيعات (الشاشة الحالية)</a>.
        </p>
    </div>
</div>

<div class="card">
    <h3 class="card-title">المرحلة 0 — جاهز</h3>
    <ul class="card-hint" style="margin:0;padding-right:1.25rem;line-height:1.65;">
        <li><code>GET /admin/api/sales-invoices/get.php?order_id=…</code></li>
        <li><code>POST /admin/api/sales-invoices/browse.php</code> — <code>action: nav|search</code></li>
        <li><code>POST /admin/api/sales-invoices/update.php</code> — ترويسة الفاتورة (INV-C)</li>
        <li><code>GET /admin/api/customers/search.php?picker=1&amp;q=…</code> — اختيار عميل + رصيد ذمم</li>
    </ul>
    <p class="card-hint" style="margin:12px 0 0;">
        مرجع: <code>docs/archive/ORANGE_SALES_INVOICE_DOC_UI_HANDOFF.txt</code>
    </p>
</div>

<?php if (!$sv2Caps['can_edit']): ?>
<div class="card" style="border:1px solid #fcd34d;background:#fffbeb;">
    <p class="card-hint" style="margin:0;">صلاحية «تعديل» غير مفعّلة لهذه الشاشة — التحديث عبر API مقفول.</p>
</div>
<?php endif; ?>
