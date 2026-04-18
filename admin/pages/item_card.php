<?php

declare(strict_types=1);

$productId = (int)($_GET['product_id'] ?? 0);
if ($productId < 1) {
    echo '<div class="card"><p class="alert-error">صنف غير صالح.</p><a href="/admin/index.php?page=stock">العودة للمستودع</a></div>';
    return;
}

$pdo = db();

$stmt = $pdo->prepare("
    SELECT p.*, c.name_ar AS category_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.id = ?
    LIMIT 1
");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    echo '<div class="card"><p class="alert-error">الصنف غير موجود.</p><a href="/admin/index.php?page=stock">العودة للمستودع</a></div>';
    return;
}

$variants = $pdo->prepare("
    SELECT * FROM product_variants WHERE product_id = ? ORDER BY color ASC, size ASC, id ASC
");
$variants->execute([$productId]);
$variants = $variants->fetchAll(PDO::FETCH_ASSOC);

$movements = $pdo->prepare("
    SELECT sm.*, pv.color AS variant_color, pv.size AS variant_size
    FROM stock_movements sm
    LEFT JOIN product_variants pv ON pv.id = sm.variant_id
    WHERE sm.product_id = ?
    ORDER BY sm.created_at DESC, sm.id DESC
    LIMIT 80
");
$movements->execute([$productId]);
$movements = $movements->fetchAll(PDO::FETCH_ASSOC);

$img = $product['main_image'] ? '/uploads/products/' . rawurlencode($product['main_image']) : '';
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>كارت الصنف</h1>
        <p class="page-subtitle">
            <a href="/admin/index.php?page=stock#balances">← المستودع</a>
            &nbsp;·&nbsp;
            <a href="/admin/index.php?page=products">المنتجات</a>
        </p>
    </div>
</div>

<div class="card item-card-header">
    <div class="item-card-main">
        <?php if ($img !== ''): ?>
            <div class="item-card-image"><img src="<?php echo htmlspecialchars($img); ?>" alt=""></div>
        <?php endif; ?>
        <div>
            <h2 class="item-card-title"><?php echo htmlspecialchars($product['name']); ?></h2>
            <?php if (!empty($product['name_en'])): ?>
                <p class="muted"><?php echo htmlspecialchars($product['name_en']); ?></p>
            <?php endif; ?>
            <p><strong>التصنيف:</strong> <?php echo htmlspecialchars($product['category_name'] ?: '—'); ?></p>
            <p><strong>السعر:</strong> <?php echo htmlspecialchars(number_format((float)$product['price'], 2)); ?>
                &nbsp;|&nbsp; <strong>التكلفة:</strong> <?php echo htmlspecialchars(number_format((float)$product['cost'], 2)); ?></p>
            <p><strong>رقم المنتج:</strong> <?php echo (int)$product['id']; ?></p>
        </div>
    </div>
</div>

<div class="card" id="variants">
    <h2 class="card-title">المتغيرات والرصيد</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th># متغير</th>
                    <th>اللون</th>
                    <th>المقاس</th>
                    <th>الرصيد</th>
                    <th>رصيد جديد</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($variants as $v): ?>
                <tr>
                    <td><?php echo (int)$v['id']; ?></td>
                    <td><?php echo htmlspecialchars($v['color'] ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars($v['size'] ?: '—'); ?></td>
                    <td><?php echo (int)$v['stock_quantity']; ?></td>
                    <td>
                        <input type="number" min="0" step="1" class="input-stock admin-inp-qty" inputmode="numeric" lang="en" dir="ltr" id="card_stock_<?php echo (int)$v['id']; ?>" value="<?php echo (int)$v['stock_quantity']; ?>">
                    </td>
                    <td class="stock-actions">
                        <button type="button" class="btn btn-secondary" onclick="cardAdjustStock(<?php echo (int)$v['id']; ?>, 'manual_adjustment')">تعديل رصيد</button>
                        <button type="button" class="btn btn-outline" onclick="cardAdjustStock(<?php echo (int)$v['id']; ?>, 'opening_balance')">رصيد افتتاحي</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2 class="card-title">آخر حركات المخزون</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>التاريخ</th>
                    <th>النوع</th>
                    <th>لون/مقاس</th>
                    <th>كمية</th>
                    <th>قبل → بعد</th>
                    <th>السبب</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movements as $m): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$m['created_at']); ?></td>
                    <td><code><?php echo htmlspecialchars((string)$m['type']); ?></code></td>
                    <td><?php echo htmlspecialchars(trim(($m['variant_color'] ?: '') . ' / ' . ($m['variant_size'] ?: '')) ?: '—'); ?></td>
                    <td><?php echo (int)$m['qty']; ?></td>
                    <td><?php echo (int)$m['old_stock']; ?> → <?php echo (int)$m['new_stock']; ?></td>
                    <td><?php echo htmlspecialchars((string)($m['reason'] ?? '')); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (!$movements): ?>
        <p class="muted">لا توجد حركات مسجلة لهذا الصنف.</p>
    <?php endif; ?>
</div>

<script>
async function cardAdjustStock(variantId, movementType) {
    const el = document.getElementById('card_stock_' + variantId);
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
