<?php

declare(strict_types=1);

$pdo = db();

$itemList = $pdo->query("
    SELECT
        p.id,
        p.name,
        p.name_en,
        p.is_active,
        c.name_ar AS category_name,
        (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.id) AS variant_count,
        (SELECT COALESCE(SUM(pv.stock_quantity), 0) FROM product_variants pv WHERE pv.product_id = p.id) AS total_stock
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    ORDER BY p.sort_order ASC, p.name ASC, p.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$rows = $pdo->query("
    SELECT pv.*, p.name AS product_name
    FROM product_variants pv
    INNER JOIN products p ON p.id = pv.product_id
    ORDER BY p.name ASC, pv.color ASC, pv.size ASC, pv.id ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>المستودع</h1>
        <p class="page-subtitle">قائمة الأصناف، رصيد المخزن، كارت الصنف، وتعديل الرصيد أو الرصيد الافتتاحي.</p>
    </div>
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
                        <a class="btn-link" href="/admin/index.php?page=item_card&amp;product_id=<?php echo (int)$it['id']; ?>">كارت الصنف</a>
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
