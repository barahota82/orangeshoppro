<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$adminCountryId = orange_admin_context_country_id($pdo);
$ordersMoney = orange_admin_currency_context($pdo);
$q = trim((string) ($_GET['q'] ?? ''));
$results = [];

if ($q !== '') {
    $sql = "
        SELECT o.*
        FROM orders o
        WHERE o.status IN ('on_the_way', 'approved')
          AND (o.order_source IS NULL OR o.order_source = '' OR o.order_source = 'website')
    ";
    $params = [];
    $cf = orange_sql_filter_country_id($pdo, 'orders', 'o', $adminCountryId);
    if ($cf !== null) {
        $sql .= $cf['sql'];
        $params[] = $cf['param'];
    }
    $like = '%' . $q . '%';
    $sql .= ' AND (o.order_number LIKE ? OR o.phone LIKE ? OR o.customer_name LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $sql .= ' ORDER BY o.id DESC LIMIT 50';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $results = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$indexBase = storefront_public_path('/admin/index.php');
?>
<div class="admin-fy-shell" dir="rtl">
    <h1 class="admin-fy-shell__title">بحث التسليم</h1>
    <p class="admin-fy-shell__lead">
        بحث عن طلب قبل إغلاق دفعة المندوب — <strong>مرتجع كامل</strong> → إلغاء؛ <strong>جزئي</strong> → تعديل الفاتورة.
    </p>

<div class="card admin-fy-card">
    <form method="get" action="<?php echo htmlspecialchars($indexBase, ENT_QUOTES, 'UTF-8'); ?>" class="admin-toolbar" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <input type="hidden" name="page" value="delivery_order_search">
        <label class="admin-toolbar__field" style="flex:1;min-width:240px;">
            <span>رقم الطلب / الهاتف / الاسم</span>
            <input type="search" name="q" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
        </label>
        <button type="submit">بحث</button>
    </form>

    <?php if ($q !== ''): ?>
    <h3 class="card-title" style="margin-top:16px;">نتائج البحث</h3>
    <?php if ($results === []): ?>
    <p class="muted">لا توجد نتائج.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>رقم الطلب</th>
                    <th>العميل</th>
                    <th>الهاتف</th>
                    <th>الحالة</th>
                    <th>الإجمالي</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $o): ?>
                <?php
                $oid = (int) ($o['id'] ?? 0);
                $st = (string) ($o['status'] ?? '');
                ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) ($o['order_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($o['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) ($o['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo orange_format_money_for_context($ordersMoney, (float) ($o['total'] ?? 0)); ?></td>
                    <td class="actions">
                        <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=invoice_edit&order_id=' . $oid), ENT_QUOTES, 'UTF-8'); ?>">تعديل جزئي</a>
                        <button type="button" class="btn-danger" onclick="dosCancelOrder(<?php echo $oid; ?>)">إلغاء (مرتجع كامل)</button>
                        <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=invoice&order_id=' . $oid), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">طباعة</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="card-hint" style="margin-top:10px;">مرتجع جزئي → <strong>تعديل جزئي</strong> يفتح <code>invoice_edit</code> (إعادة حساب العروض + «حفظ + تم التسليم»).</p>
    <?php endif; ?>
    <?php endif; ?>
</div>
</div>

<script>
async function dosCancelOrder(orderId) {
    if (!confirm('إلغاء الطلب (مرتجع كامل) — cancelled؟')) return;
    var res = await postJSON('/admin/api/orders/update-status.php', { order_id: orderId, status: 'cancelled' });
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) location.reload();
}
</script>
