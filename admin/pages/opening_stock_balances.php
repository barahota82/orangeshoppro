<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$rows = $pdo->query("
    SELECT pv.*, p.name AS product_name
    FROM product_variants pv
    INNER JOIN products p ON p.id = pv.product_id
    ORDER BY p.name ASC, pv.color ASC, pv.size ASC, pv.id ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>أرصدة أول المدة المخزنية</h1>
        <p class="page-subtitle">
            مخصّصة لسيناريو <strong>نقل من نظام قديم</strong> عندما لا يُستورد المخزون تلقائياً: تُحدَّد هنا كمية كل متغير (لون/مقاس) كـ <strong>رصيد افتتاحي</strong> في السجلات.
            <strong>ليست إدخالاً دورياً يومياً</strong> — التشغيل العادي يستخدم شاشة <a href="/admin/index.php?page=stock">المستودع</a> أو حركات الشراء والبيع.
            الأرصدة <a href="/admin/index.php?page=opening_balances">المالية الافتتاحية</a> تُسجَّل في شاشة منفصلة.
        </p>
    </div>
</div>

<div class="card">
    <h3 class="card-title">كميات المخزون الافتتاحية (حسب المتغير)</h3>
    <p class="muted" style="margin:0 0 10px;">
        أدخل <strong>الكمية الجديدة</strong> ثم اضغط <strong>تسجيل الرصيد الافتتاحي</strong> للصف. يُسجَّل النوع «رصيد افتتاحي» في حركات المخزون (كما في المستودع).
        الكميات: <strong>أعداد صحيحة فقط</strong>، بدون سالب.
    </p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>المنتج</th>
                    <th>اللون</th>
                    <th>المقاس</th>
                    <th>الرصيد الحالي</th>
                    <th>الكمية (افتتاحي)</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) $r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($r['color'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($r['size'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) $r['stock_quantity']; ?></td>
                    <td>
                        <input type="number" min="0" step="1" class="input-stock admin-inp-qty" inputmode="numeric" lang="en" dir="ltr" id="osb_<?php echo (int) $r['id']; ?>" value="<?php echo (int) $r['stock_quantity']; ?>" aria-label="كمية رصيد افتتاحي">
                    </td>
                    <td class="stock-actions">
                        <button type="button" class="btn-secondary" onclick="osbSaveRow(<?php echo (int) $r['id']; ?>)">تسجيل الرصيد الافتتاحي</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($rows === []): ?>
        <p class="card-hint">لا توجد متغيرات منتجات — أضف منتجات ومتغيرات من <a href="/admin/index.php?page=products">المنتجات</a>.</p>
    <?php endif; ?>
</div>

<script>
function osbSaveRow(variantId) {
    var el = document.getElementById('osb_' + variantId);
    var stock = parseInt(el && el.value ? el.value : '0', 10);
    if (isNaN(stock) || stock < 0) {
        alert('كمية غير صالحة');
        return;
    }
    if (!confirm('تسجيل هذه الكمية كرصيد افتتاحي مخزني لهذا المتغير؟')) {
        return;
    }
    postJSON('/admin/api/stock/adjust.php', {
        variant_id: variantId,
        stock: stock,
        movement_type: 'opening_balance',
        reason: 'أرصدة أول المدة المخزنية'
    }).then(function (res) {
        alert(res.message || (res.success ? 'تم' : 'فشل'));
        if (res.success) {
            location.reload();
        }
    }).catch(function (e) {
        alert(e.message || String(e));
    });
}
</script>
