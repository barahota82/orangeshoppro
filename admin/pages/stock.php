<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/stock_alerts.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/warehouses.php';
require_once __DIR__ . '/../../includes/opening_stock_lock.php';

$pdo = db();

$stockCountryId = orange_admin_context_country_id($pdo);
$openingStockLocked = orange_opening_stock_is_locked($pdo, $stockCountryId);
$stockProductsCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $stockCountryId);

$lowStockTh = orange_stock_low_alert_threshold($pdo, $stockCountryId);
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
$wQtyTotal = orange_warehouse_effective_qty_sql($pdo, $stockCountryId, 'pv2', 'wvs_sum');
$totalStockSub = '(SELECT COALESCE(SUM(' . $wQtyTotal['expr'] . '), 0)
    FROM product_variants pv2'
    . $wQtyTotal['join']
    . ' WHERE pv2.product_id = p.id)';
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
<div class="page-title">
    <h1>المستودع</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<div class="card" id="low-stock-variants" style="border:1px solid #f59e0b; background:#fffbeb;">
    <h2 class="card-title">تنبيه: قارب على النفاذ (س5)</h2>
    <p class="card-hint" style="margin:0 0 10px;">
        متغيرات المنتجات <strong>النشطة</strong> التي رصيدها الحالي ≤ <strong><?php echo (int) $lowStockTh; ?></strong> قطعة.
        تعديل الأرصدة يتم من <strong>«أرصدة أول المدة المخزنية»</strong> أو <strong>«قيد تسوية مخزون»</strong> (بالحسابات).
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
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lowStockRows as $lr): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) ($lr['product_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($lr['color'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($lr['size'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><strong><?php echo (int) ($lr['stock_quantity'] ?? 0); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card" id="item-list">
    <h2 class="card-title">قائمة الأصناف</h2>
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
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="balances">
    <h2 class="card-title">رصيد المخزن (لون / مقاس)</h2>
    <p class="card-hint" style="margin:0 0 10px;">عرض فقط. لتعديل الأرصدة استخدم «أرصدة أول المدة المخزنية» أو «قيد تسوية مخزون» (بالحسابات).</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>المنتج</th>
                    <th>اللون</th>
                    <th>المقاس</th>
                    <th>الرصيد الحالي</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['product_name']); ?></td>
                    <td><?php echo htmlspecialchars($r['color'] ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars($r['size'] ?: '—'); ?></td>
                    <td><strong><?php echo (int)$r['stock_quantity']; ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
