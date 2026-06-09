<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/warehouses.php';
require_once __DIR__ . '/../../includes/opening_stock_lock.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$osbCountryId = orange_admin_context_country_id($pdo);
$openingStockLocked = orange_opening_stock_is_locked($pdo, $osbCountryId);
$osbProductsCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $osbCountryId);
$wQtyOsb = orange_warehouse_effective_qty_sql($pdo, $osbCountryId, 'pv', 'wvs_osb');

$rows = $pdo->query(
    'SELECT pv.id, pv.color, pv.size, p.name AS product_name, '
    . $wQtyOsb['expr'] . ' AS stock_quantity
     FROM product_variants pv
     INNER JOIN products p ON p.id = pv.product_id'
    . $wQtyOsb['join']
    . ' WHERE 1=1' . $osbProductsCountrySql . '
     ORDER BY p.name ASC, pv.color ASC, pv.size ASC, pv.id ASC'
)->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="admin-fy-shell" dir="rtl">
    <div class="page-title">
        <h1>أرصدة أول المدة المخزنية</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

<?php if ($openingStockLocked): ?>
    <div class="alert-warning" style="margin-bottom:12px;">
        <strong>مقفول:</strong> لا يمكن تسجيل أو تعديل أرصدة افتتاحية مخزنية لهذه الدولة. أزل الإقفال أدناه إذا احتجت إدخالاً إضافياً.
    </div>
<?php endif; ?>

<div class="card admin-fy-card" style="margin-bottom:12px;">
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
        <input type="checkbox" id="osbLockToggle" <?php echo $openingStockLocked ? 'checked' : ''; ?>>
        <span><strong>مقفول</strong> — منع أي رصيد افتتاحي مخزني جديد (حسب دولة الأدمن الحالية)</span>
    </label>
</div>

<div class="card admin-fy-card">
    <h3 class="card-title">كميات المخزون الافتتاحية (حسب المتغير)</h3>
    <div class="table-wrap admin-fy-table-wrap">
        <table class="admin-fy-table">
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
                        <input type="number" min="0" step="1" class="input-stock admin-inp-qty" inputmode="numeric" lang="en" dir="ltr" id="osb_<?php echo (int) $r['id']; ?>" value="<?php echo (int) $r['stock_quantity']; ?>" aria-label="كمية رصيد افتتاحي"<?php echo $openingStockLocked ? ' disabled' : ''; ?>>
                    </td>
                    <td class="stock-actions">
                        <button type="button" class="btn-secondary" onclick="osbSaveRow(<?php echo (int) $r['id']; ?>)"<?php echo $openingStockLocked ? ' disabled' : ''; ?>>تسجيل الرصيد الافتتاحي</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($rows === []): ?>
        <p class="card-hint">لا توجد متغيرات منتجات — أضف منتجات ومتغيرات من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=products'), ENT_QUOTES, 'UTF-8'); ?>">المنتجات</a>.</p>
    <?php endif; ?>
</div>

</div>

<script>
var osbOpeningLocked = <?php echo $openingStockLocked ? 'true' : 'false'; ?>;

document.getElementById('osbLockToggle').addEventListener('change', function () {
    var locked = this.checked;
    var msg = locked
        ? 'إقفال رصيد المخزون الافتتاحي؟ لن يُسمح بإدخال أرصدة افتتاحية جديدة.'
        : 'فك إقفال رصيد المخزون الافتتاحي؟';
    if (!confirm(msg)) {
        this.checked = !locked;
        return;
    }
    postJSON('/admin/api/stock/opening-stock-lock.php', { locked: locked }).then(function (res) {
        alert(res.message || (res.success ? 'تم' : 'فشل'));
        if (res.success) {
            location.reload();
        } else {
            document.getElementById('osbLockToggle').checked = !locked;
        }
    }).catch(function (e) {
        alert(e.message || String(e));
        document.getElementById('osbLockToggle').checked = !locked;
    });
});

function osbSaveRow(variantId) {
    if (osbOpeningLocked) {
        alert('رصيد المخزون الافتتاحي مقفول');
        return;
    }
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
