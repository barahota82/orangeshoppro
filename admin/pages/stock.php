<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/stock_alerts.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/warehouses.php';

$pdo = db();

$stockCountryId = orange_admin_context_country_id($pdo);
$stockProductsCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $stockCountryId);

$lowStockTh = orange_stock_low_alert_threshold();
$wQtyStock = orange_warehouse_effective_qty_sql($pdo, $stockCountryId, 'pv', 'wvs_low');
$stLowList = $pdo->prepare(
    'SELECT pv.id AS variant_id, ' . $wQtyStock['expr'] . ' AS stock_quantity, pv.color, pv.size, p.id AS product_id, p.name AS product_name
     FROM product_variants pv
     INNER JOIN products p ON p.id = pv.product_id'
    . $wQtyStock['join']
    . ' WHERE p.is_active = 1 AND ' . $wQtyStock['expr'] . ' <= ?' . $stockProductsCountrySql . '
     ORDER BY ' . $wQtyStock['expr'] . ' ASC, p.name ASC, pv.id ASC'
);
$stLowList->execute([$lowStockTh]);
$lowStockRows = $stLowList->fetchAll(PDO::FETCH_ASSOC) ?: [];

$itemList = [];
$catJoin = orange_catalog_admin_sql_join_product_category_display($pdo, 'p', null);
$stockWhId = orange_warehouse_default_id_for_country($pdo, $stockCountryId);
$totalStockSub = ($stockWhId > 0 && orange_warehouses_table_exists($pdo))
    ? '(SELECT COALESCE(SUM(COALESCE(wvs_sum.quantity, pv2.stock_quantity)), 0)
        FROM product_variants pv2
        LEFT JOIN warehouse_variant_stock wvs_sum ON wvs_sum.warehouse_id = ' . (int) $stockWhId . ' AND wvs_sum.variant_id = pv2.id
        WHERE pv2.product_id = p.id)'
    : '(SELECT COALESCE(SUM(pv.stock_quantity), 0) FROM product_variants pv WHERE pv.product_id = p.id)';
try {
    $itemList = $pdo->query("
    SELECT
        p.id,
        p.name,
        p.name_en,
        p.is_active,
        c.name_ar AS category_name,
        (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.id) AS variant_count,
        {$totalStockSub} AS total_stock
    FROM products p
    {$catJoin}
    WHERE 1=1{$stockProductsCountrySql}
    ORDER BY p.sort_order ASC, p.name ASC, p.id ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $itemList = [];
}

$wQtyRows = orange_warehouse_effective_qty_sql($pdo, $stockCountryId, 'pv', 'wvs_rows');
$rows = $pdo->query("
    SELECT pv.*, p.name AS product_name, " . $wQtyRows['expr'] . " AS stock_quantity_effective
    FROM product_variants pv
    INNER JOIN products p ON p.id = pv.product_id"
    . $wQtyRows['join']
    . "
    WHERE 1=1{$stockProductsCountrySql}
    ORDER BY p.name ASC, pv.color ASC, pv.size ASC, pv.id ASC
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$stockRow) {
    if (isset($stockRow['stock_quantity_effective'])) {
        $stockRow['stock_quantity'] = (int) $stockRow['stock_quantity_effective'];
    }
}
unset($stockRow);
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>المستودع</h1>
        <p class="page-subtitle">قائمة الأصناف، رصيد المخزن، كارت الصنف، وتعديل الرصيد أو الرصيد الافتتاحي.</p>
    </div>
</div>

<div class="card" id="low-stock-variants" style="border:1px solid #f59e0b; background:#fffbeb;">
    <h2 class="card-title">تنبيه: قارب على النفاذ (س5)</h2>
    <p class="card-hint" style="margin:0 0 10px;">
        متغيرات المنتجات <strong>النشطة</strong> التي رصيدها الحالي ≤ <strong><?php echo (int) $lowStockTh; ?></strong> قطعة.
        عدّل الرصيد من جدول «رصيد المخزن» أدناه أو من <strong>كارت الصنف</strong>.
    </p>
    <?php if ($lowStockRows === []): ?>
        <p class="page-subtitle" style="margin:0;">لا توجد متغيرات ضمن عتبة التنبيه حالياً.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>المنتج</th>
                    <th>اللون</th>
                    <th>المقاس</th>
                    <th>الرصيد</th>
                    <th>كارت الصنف</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lowStockRows as $lr): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) ($lr['product_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($lr['color'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($lr['size'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><strong><?php echo (int) ($lr['stock_quantity'] ?? 0); ?></strong></td>
                    <td>
                        <a class="btn-link" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=item_card&product_id=' . (int) ($lr['product_id'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?>">فتح الكارت</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card" id="item-list">
    <h2 class="card-title">قائمة الأصناف</h2>
    <p class="card-hint">ملخص حسب المنتج — اضغط «كارت الصنف» لحركات المخزون والتفاصيل.</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الصنف</th>
                    <th>التصنيف</th>
                    <th>عدد المتغيرات</th>
                    <th>إجمالي الرصيد</th>
                    <th>الحالة</th>
                    <th>كارت الصنف</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itemList as $it): ?>
                <tr>
                    <td><?php echo (int)$it['id']; ?></td>
                    <td><?php echo htmlspecialchars($it['name']); ?></td>
                    <td><?php echo htmlspecialchars($it['category_name'] ?: '—'); ?></td>
                    <td><?php echo (int)$it['variant_count']; ?></td>
                    <td><strong><?php echo (int)$it['total_stock']; ?></strong></td>
                    <td><?php echo !empty($it['is_active']) ? 'نشط' : 'موقوف'; ?></td>
                    <td>
                        <a class="btn-link" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=item_card&product_id=' . (int) $it['id']), ENT_QUOTES, 'UTF-8'); ?>">كارت الصنف</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="balances">
    <h2 class="card-title">رصيد المخزن (لون / مقاس)</h2>
    <p class="card-hint">
        <strong>حفظ التعديل:</strong> تسجيل كحركة يومية عادية.
        <strong>رصيد افتتاحي:</strong> نفس تحديث الكمية مع نوع حركة منفصل للتقارير (مثلاً بداية فترة أو أول إدخال).
    </p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>المنتج</th>
                    <th>اللون</th>
                    <th>المقاس</th>
                    <th>الرصيد الحالي</th>
                    <th>الرصيد الجديد</th>
                    <th>التحكم</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['product_name']); ?></td>
                    <td><?php echo htmlspecialchars($r['color'] ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars($r['size'] ?: '—'); ?></td>
                    <td><?php echo (int)$r['stock_quantity']; ?></td>
                    <td>
                        <input type="number" min="0" step="1" class="input-stock admin-inp-qty" inputmode="numeric" lang="en" dir="ltr" id="stock_<?php echo (int)$r['id']; ?>" value="<?php echo (int)$r['stock_quantity']; ?>">
                    </td>
                    <td class="stock-actions">
                        <button type="button" class="btn btn-secondary" onclick="adjustStock(<?php echo (int)$r['id']; ?>, 'manual_adjustment')">حفظ التعديل</button>
                        <button type="button" class="btn btn-outline" onclick="adjustStock(<?php echo (int)$r['id']; ?>, 'opening_balance')">رصيد افتتاحي</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function adjustStock(variantId, movementType) {
    const el = document.getElementById('stock_' + variantId);
    const stock = parseInt(el.value || '0', 10);
    const label = movementType === 'opening_balance' ? 'تسجيل الرصيد الافتتاحي؟' : 'حفظ تعديل المخزون؟';
    if (!confirm(label)) return;
    const res = await postJSON('/admin/api/stock/adjust.php', {
        variant_id: variantId,
        stock: stock,
        movement_type: movementType
    });
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) location.reload();
}
</script>
