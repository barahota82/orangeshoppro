<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/invoice_edit_helpers.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/countries.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
$order = null;
$items = [];
$paidItems = [];
$giftItems = [];
$err = '';

if ($orderId > 0) {
    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $st->execute([$orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$order) {
        $err = 'الطلب غير موجود';
    } elseif (!in_array((string) ($order['status'] ?? ''), orange_invoice_edit_allowed_statuses(), true)) {
        $err = 'الطلب غير مؤهل للتعديل في هذه الحالة';
    } else {
        $it = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
        $it->execute([$orderId]);
        $items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($items as $row) {
            if (orange_invoice_edit_is_gift_line($pdo, $row, $order)) {
                $giftItems[] = $row;
            } else {
                $paidItems[] = $row;
            }
        }
    }
}

$money = orange_admin_currency_context($pdo);
$comboDisc = (float) ($order['cart_combo_discount'] ?? 0);
$promoDisc = (float) ($order['cart_promotion_discount'] ?? 0);
?>
<div class="admin-fy-shell" dir="rtl">
    <h1 class="admin-fy-shell__title">تعديل بنود الطلب (أونلاين)</h1>
    <p class="admin-fy-shell__lead">
        مرتجع جزئي قبل التسليم — تعديل الكميات يُعيد حساب العروض تلقائياً (§13.11.9.7).
        <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=delivery_order_search'), ENT_QUOTES, 'UTF-8'); ?>">بحث التسليم</a>
    </p>

<?php if ($orderId <= 0): ?>
<div class="card"><p class="muted">افتح الصفحة مع <code>?order_id=</code> من بحث التسليم.</p></div>
<?php elseif ($err !== ''): ?>
<div class="card"><div class="alert-error"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div></div>
<?php else: ?>

<div class="card admin-fy-card">
    <p><strong>طلب:</strong> <?php echo htmlspecialchars((string) ($order['order_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
        — <?php echo htmlspecialchars((string) ($order['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>

    <h3 class="card-title">بنود مدفوعة</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>الصنف</th>
                    <th>لون/مقاس</th>
                    <th>الكمية</th>
                    <th>السعر</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($paidItems as $row): ?>
                <tr data-item-id="<?php echo (int) ($row['id'] ?? 0); ?>">
                    <td><?php echo htmlspecialchars((string) ($row['product_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(trim((string) ($row['color'] ?? '') . ' / ' . (string) ($row['size'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><input type="number" min="0" class="ie-qty" value="<?php echo (int) ($row['qty'] ?? 0); ?>" style="width:80px;"></td>
                    <td><?php echo orange_format_money_for_context($money, (float) ($row['price'] ?? 0)); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card-hint" style="margin-top:16px;padding:12px;border:1px solid #e2e8f0;border-radius:8px;">
        <strong>ملخص العروض (بعد الحفظ):</strong>
        <ul style="margin:8px 0 0;padding-right:1.2rem;">
            <?php if ($comboDisc > 0.00001): ?>
            <li>خصم كومبو: −<?php echo htmlspecialchars(number_format($comboDisc, 3), ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endif; ?>
            <?php if ($promoDisc > 0.00001): ?>
            <li>خصم السلة: −<?php echo htmlspecialchars(number_format($promoDisc, 3), ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endif; ?>
            <?php foreach ($giftItems as $g): ?>
            <li>هدية/BOGO: <?php echo htmlspecialchars((string) ($g['product_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> × <?php echo (int) ($g['qty'] ?? 0); ?></li>
            <?php endforeach; ?>
            <?php if ($comboDisc <= 0.00001 && $promoDisc <= 0.00001 && $giftItems === []): ?>
            <li class="muted">—</li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="admin-form-actions" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:16px;">
        <button type="button" onclick="invoiceEditSave(false)">حفظ التعديل</button>
        <button type="button" class="btn-success" onclick="invoiceEditSave(true)">حفظ + تم التسليم</button>
        <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=invoice&order_id=' . $orderId), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">معاينة طباعة</a>
    </div>
</div>

<script>
async function invoiceEditSave(completeAfter) {
    var changes = [];
    document.querySelectorAll('tr[data-item-id]').forEach(function (tr) {
        var id = parseInt(tr.getAttribute('data-item-id'), 10);
        var inp = tr.querySelector('.ie-qty');
        if (!id || !inp) return;
        changes.push({ item_id: id, qty: parseInt(inp.value, 10) || 0 });
    });
    if (!confirm(completeAfter ? 'حفظ التعديل ثم «تم التسليم» (مخزون فقط)؟' : 'حفظ التعديل؟')) return;
    var res = await postJSON('/admin/api/orders/amend-invoice-items.php', {
        order_id: <?php echo (int) $orderId; ?>,
        changes: changes,
        mark_completed: completeAfter ? 1 : 0
    });
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) {
        if (completeAfter) {
            window.location.href = <?php echo json_encode(storefront_public_path('/admin/index.php?page=online_orders_final_posting'), JSON_UNESCAPED_UNICODE); ?>;
        } else {
            location.reload();
        }
    }
}
</script>
<?php endif; ?>
</div>
