<?php
$pdo = db();
$rows = $pdo->query("
    SELECT pv.*, p.name AS product_name
    FROM product_variants pv
    INNER JOIN products p ON p.id = pv.product_id
    ORDER BY p.name ASC, pv.color ASC, pv.size ASC
")->fetchAll();
?>
<div class="page-title">
    <h1>المخزون</h1>
</div>

<div class="card">
    <h3>تعديل كميات الـ Variants</h3>
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
                    <td><?php echo htmlspecialchars($r['color'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars($r['size'] ?: '-'); ?></td>
                    <td><?php echo (int)$r['stock_quantity']; ?></td>
                    <td><input type="number" id="stock_<?php echo (int)$r['id']; ?>" value="<?php echo (int)$r['stock_quantity']; ?>"></td>
                    <td><button type="button" onclick="adjustStock(<?php echo (int)$r['id']; ?>)">حفظ</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function adjustStock(variantId) {
    const stock = parseInt(document.getElementById('stock_' + variantId).value || '0', 10);
    const res = await postJSON('/admin/api/stock/adjust.php', {
        variant_id: variantId,
        stock: stock
    });
    alert(res.message || (res.success ? 'تم تعديل المخزون' : 'فشل تعديل المخزون'));
    if (res.success) location.reload();
}
</script>
