<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/cart_promo_products.php';
require_once __DIR__ . '/../../includes/cart_promo_schedule.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/product_offers.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$offersCountryId = orange_admin_context_country_id($pdo);
$offersProductsCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $offersCountryId);
$ofrPickRows = orange_cart_promo_admin_product_rows($pdo, $offersCountryId);
$ofrPickJson = json_encode($ofrPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);

$hasScheduleCols = orange_table_has_column($pdo, 'offers', 'valid_from');

$offers = $pdo->query(
    '
    SELECT o.*, p.name AS product_name
    FROM offers o
    INNER JOIN products p ON p.id = o.product_id
    WHERE 1=1' . $offersProductsCountrySql . '
    ORDER BY o.id DESC
'
)->fetchAll();
?>
<div class="page-title page-title--stacked">
    <h1>عروض المنتجات</h1>
    <p class="page-subtitle">العرض <strong>الخامس</strong> للمستهلك (س4): خصم مبلغ من <code dir="ltr">products.price</code> — يظهر في تبويب «العروض» بالرئيسية.
        اختيار المنتج: <strong>نقرتان</strong> من قائمة المخزن. <strong>بداية ونهاية العرض إلزاميان</strong>.</p>
</div>

<div class="card">
    <h3>إضافة / تعديل عرض</h3>
    <input type="hidden" id="ofr_id" value="0">
    <div class="form-grid">
        <div style="grid-column:1/-1;">
            <label>المنتج</label>
            <div style="margin:6px 0 8px;">
                <button type="button" class="btn-secondary" id="ofr_pick_btn">اختيار منتج (دبل كليك من القائمة)</button>
            </div>
            <p id="ofr_product_label" class="page-subtitle" style="margin:0;">— لم يُختر منتج —</p>
            <input type="hidden" id="offer_product_id" value="0">
        </div>
        <div>
            <label>قيمة الخصم (د.ك)</label>
            <input type="number" id="discount" class="admin-inp-money" step="any" min="0" inputmode="decimal" lang="en" dir="ltr">
        </div>
        <?php if ($hasScheduleCols): ?>
        <?php $ocpFieldPrefix = 'ofr'; require __DIR__ . '/../partials/cart_promo_schedule_fields.inc.php'; ?>
        <?php endif; ?>
    </div>
    <div class="actions" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
        <button type="button" onclick="saveOffer()">حفظ العرض</button>
        <button type="button" class="btn-secondary" onclick="ofrResetForm()">عرض جديد</button>
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
                    <?php if ($hasScheduleCols): ?><th>الفترة</th><?php endif; ?>
                    <th>الحالة</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($offers as $o): ?>
                <tr>
                    <td><?php echo (int) $o['id']; ?></td>
                    <td><?php echo htmlspecialchars((string) $o['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo number_format((float) $o['discount'], 3); ?></td>
                    <?php if ($hasScheduleCols): ?>
                    <td dir="ltr"><?php echo htmlspecialchars(orange_cart_promo_admin_schedule_label($o), ENT_QUOTES, 'UTF-8'); ?></td>
                    <?php endif; ?>
                    <td><?php
                        $reason = trim((string) ($o['auto_paused_reason'] ?? ''));
                        if ($reason === 'promo_stock') {
                            echo 'موقوف — نفاد مخزون العرض';
                        } elseif ($reason === 'gift_stock') {
                            echo 'موقوف — عدم توفر الهدية';
                        } elseif ((int) ($o['is_active'] ?? 0) !== 1) {
                            echo 'مخفي';
                        } else {
                            echo 'نشط';
                        }
                    ?></td>
                    <td><button type="button" class="btn-secondary btn-sm" onclick='ofrEdit(<?php echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>)'>تعديل</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin_cart_promo_product_pick.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php if ($hasScheduleCols): require __DIR__ . '/../partials/cart_promo_schedule_js.inc.php'; endif; ?>
<script>
window.OFR_PICK_ROWS = <?php echo $ofrPickJson !== false ? $ofrPickJson : '[]'; ?>;

function ofrSetProduct(row) {
    document.getElementById('offer_product_id').value = String(row.product_id || 0);
    var code = row.code ? row.code + ' — ' : '';
    document.getElementById('ofr_product_label').textContent = code + (row.name || '');
}

document.getElementById('ofr_pick_btn').addEventListener('click', function () {
    if (!window.OrangeCartPromoProductPick) return;
    OrangeCartPromoProductPick.open(window.OFR_PICK_ROWS || [], ofrSetProduct);
});

function ofrResetForm() {
    document.getElementById('ofr_id').value = '0';
    document.getElementById('offer_product_id').value = '0';
    document.getElementById('ofr_product_label').textContent = '— لم يُختر منتج —';
    document.getElementById('discount').value = '';
    if (typeof ocpDefaultScheduleDates === 'function') {
        ocpDefaultScheduleDates('ofr');
    }
}

function ofrEdit(row) {
    document.getElementById('ofr_id').value = String(row.id || 0);
    document.getElementById('offer_product_id').value = String(row.product_id || 0);
    document.getElementById('ofr_product_label').textContent = (row.product_name || '') + ' (#' + row.product_id + ')';
    document.getElementById('discount').value = row.discount != null ? String(row.discount) : '';
    if (typeof ocpSetDmyFromIso === 'function') {
        ocpSetDmyFromIso('ofr_valid_from', row.valid_from || '');
        ocpSetDmyFromIso('ofr_valid_to', row.valid_to || '');
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function saveOffer() {
    var payload = {
        id: parseInt(document.getElementById('ofr_id').value, 10) || 0,
        product_id: parseInt(document.getElementById('offer_product_id').value, 10),
        discount: parseFloat(document.getElementById('discount').value || '0')
    };
    if (typeof ocpSchedulePayload === 'function') {
        Object.assign(payload, ocpSchedulePayload('ofr'));
    }
    if (!payload.product_id) {
        alert('اختر المنتج من القائمة (نقرتان)');
        return;
    }
    var res = await postJSON('/admin/api/offers/save.php', payload);
    alert(res.message || (res.success ? 'تم حفظ العرض' : 'فشل حفظ العرض'));
    if (res.success) location.reload();
}

if (typeof ocpDefaultScheduleDates === 'function') {
    ocpDefaultScheduleDates('ofr');
}
</script>
