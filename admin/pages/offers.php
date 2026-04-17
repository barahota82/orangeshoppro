<?php
$pdo = db();
$products = $pdo->query("SELECT id, name FROM products WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$offers = $pdo->query("
    SELECT o.*, p.name AS product_name
    FROM offers o
    INNER JOIN products p ON p.id = o.product_id
    ORDER BY o.id DESC
")->fetchAll();
?>
<div class="page-title">
    <h1>العروض</h1>
</div>

<div class="card">
    <h3>إضافة عرض</h3>
    <div class="form-grid">
        <div>
            <label>المنتج</label>
            <select id="offer_product_id">
                <option value="">اختر المنتج</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>قيمة الخصم</label>
            <input type="number" id="discount" step="0.01">
        </div>
    </div>
    <div class="actions" style="margin-top:14px;">
        <button type="button" onclick="saveOffer()">حفظ العرض</button>
    </div>
</div>

<div class="card">
    <h3>قائمة العروض</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>المنتج</th>
                    <th>الخصم</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($offers as $o): ?>
                <tr>
                    <td><?php echo (int)$o['id']; ?></td>
                    <td><?php echo htmlspecialchars($o['product_name']); ?></td>
                    <td><?php echo number_format((float)$o['discount'], 2); ?></td>
                    <td><?php echo (int)$o['is_active'] === 1 ? 'نشط' : 'مخفي'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function saveOffer() {
    const payload = {
        product_id: parseInt(document.getElementById('offer_product_id').value, 10),
        discount: parseFloat(document.getElementById('discount').value || '0')
    };
    const res = await postJSON('/admin/api/offers/save.php', payload);
    alert(res.message || (res.success ? 'تم حفظ العرض' : 'فشل حفظ العرض'));
    if (res.success) location.reload();
}
</script>
