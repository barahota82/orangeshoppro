<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/cart_promo_products.php';
require_once __DIR__ . '/../../includes/cart_promo_schedule.php';
require_once __DIR__ . '/../../includes/promo_always_on.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/product_offers.php';
require_once __DIR__ . '/../../includes/currency.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$offersCountryId = orange_admin_context_country_id($pdo);
$offersMoney = isset($orangeAdminMoney) && is_array($orangeAdminMoney)
    ? $orangeAdminMoney
    : orange_admin_currency_context($pdo);
$offersProductsCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $offersCountryId);
$ofrPickRows = orange_cart_promo_admin_product_rows($pdo, $offersCountryId);
$ofrPickJson = json_encode($ofrPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);

$hasScheduleCols = orange_table_has_column($pdo, 'offers', 'valid_from');
$hasSortCol = orange_table_has_column($pdo, 'offers', 'sort_order');

$orderSql = $hasSortCol ? 'o.sort_order ASC, o.id DESC' : 'o.id DESC';
$offers = $pdo->query(
    '
    SELECT o.*, p.name AS product_name
    FROM offers o
    INNER JOIN products p ON p.id = o.product_id
    WHERE 1=1' . $offersProductsCountrySql . '
    ORDER BY ' . $orderSql . '
'
)->fetchAll();
$offerAlwaysHistory = orange_promo_always_on_history_list($pdo, 'offers', $offersCountryId);
?>
<div class="page-title">
    <h1>عروض المنتجات</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>
<?php require_once __DIR__ . '/../../includes/offer_gl_link_card.php'; echo orange_offer_gl_link_card_html($pdo, ['product_offer_discount']); ?>

<?php if (!$hasScheduleCols): ?>
<div class="card" style="border:1px solid #dc2626;background:#fef2f2;margin-bottom:16px;">
    <p style="margin:0;"><strong>تنبيه:</strong> أعمدة فترة العرض غير جاهزة بعد على قاعدة البيانات. نفّذ <code>git pull</code> ثم أعد تحميل هذه الصفحة (الترحيل v80 يعمل تلقائياً).</p>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل عرض</h3>
    <input type="hidden" id="ofr_id" value="0">
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;margin-bottom:14px;">
        <?php if ($hasSortCol): ?>
        <div style="max-width:120px;">
            <label for="ofr_sort">الترتيب</label>
            <input type="number" id="ofr_sort" class="admin-inp" value="0" readonly tabindex="-1" title="يُحدَّد تلقائياً" style="background:#f1f5f9;color:#64748b;cursor:not-allowed;text-align:center;" dir="ltr">
        </div>
        <?php endif; ?>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" id="ofr_active" checked> نشط
        </label>
    </div>
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
            <label for="discount">قيمة الخصم (<?php echo htmlspecialchars((string) ($offersMoney['unit'] ?? 'KWD'), ENT_QUOTES, 'UTF-8'); ?>)</label>
            <input type="number" id="discount" class="admin-inp-money" step="any" min="0" inputmode="decimal" lang="en" dir="ltr">
        </div>
        <div>
            <label for="ofr_valid_from">بداية العرض <span dir="ltr">*</span></label>
            <input type="text" id="ofr_valid_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off" required>
        </div>
        <div>
            <label for="ofr_valid_to">نهاية العرض <span dir="ltr">*</span></label>
            <input type="text" id="ofr_valid_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off" required>
        </div>
        <div style="grid-column:1/-1;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;max-width:22rem;">
                <input type="checkbox" id="ofr_always_on">
                <span><strong>التفعيل الدائم</strong></span>
            </label>
        </div>
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
                    <?php if ($hasSortCol): ?><th>الترتيب</th><?php endif; ?>
                    <th>الفترة</th>
                    <th>الحالة</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($offers as $o): ?>
                <tr>
                    <td><?php echo (int) $o['id']; ?></td>
                    <td><?php echo htmlspecialchars((string) $o['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars(orange_format_money_for_context($offersMoney, (float) $o['discount'], false), ENT_QUOTES, 'UTF-8'); ?></td>
                    <?php if ($hasSortCol): ?>
                    <td dir="ltr"><?php echo (int) ($o['sort_order'] ?? 0); ?></td>
                    <?php endif; ?>
                    <td dir="ltr"><?php echo $hasScheduleCols ? htmlspecialchars(orange_cart_promo_admin_schedule_label($o), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                    <td><?php
                        $reason = trim((string) ($o['auto_paused_reason'] ?? ''));
                        if ($reason === 'promo_stock') {
                            echo 'موقوف — نفاد مخزون العرض';
                        } elseif ($reason === 'gift_stock') {
                            echo 'موقوف — عدم توفر الهدية';
                        } elseif ((int) ($o['is_active'] ?? 0) !== 1) {
                            echo 'مخفي';
                        } elseif ((int) ($o['is_always_on'] ?? 0) === 1) {
                            echo 'نشط (دائم)';
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

<div class="card">
    <h3>سجل التفعيل الدائم</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>العرض</th>
                    <th>بداية التفعيل الدائم</th>
                    <th>نهاية التفعيل الدائم</th>
                    <th>بواسطة</th>
                    <th>إنهاء بواسطة</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($offerAlwaysHistory === []): ?>
                <tr><td colspan="6" class="muted">لا توجد عمليات تفعيل دائم مسجلة بعد.</td></tr>
                <?php else: ?>
                <?php foreach ($offerAlwaysHistory as $log): ?>
                <tr>
                    <td><?php echo (int) ($log['id'] ?? 0); ?></td>
                    <td><?php echo 'عرض #' . (int) ($log['promotion_id'] ?? 0); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) ($log['started_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) ($log['ended_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) (($log['started_by_name'] ?? '') !== '' ? $log['started_by_name'] : '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) (($log['ended_by_name'] ?? '') !== '' ? $log['ended_by_name'] : '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin_cart_promo_product_pick.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
<?php require __DIR__ . '/../partials/cart_promo_schedule_js.inc.php'; ?>
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
    var sortEl = document.getElementById('ofr_sort');
    if (sortEl) sortEl.value = '0';
    var activeEl = document.getElementById('ofr_active');
    if (activeEl) activeEl.checked = true;
    if (typeof ocpSetAlwaysOn === 'function') {
        ocpSetAlwaysOn('ofr', false);
    }
    if (typeof ocpDefaultScheduleDates === 'function') {
        ocpDefaultScheduleDates('ofr');
    }
}

function ofrEdit(row) {
    document.getElementById('ofr_id').value = String(row.id || 0);
    document.getElementById('offer_product_id').value = String(row.product_id || 0);
    document.getElementById('ofr_product_label').textContent = (row.product_name || '') + ' (#' + row.product_id + ')';
    document.getElementById('discount').value = row.discount != null ? String(row.discount) : '';
    var sortEl = document.getElementById('ofr_sort');
    if (sortEl) sortEl.value = String(row.sort_order != null ? row.sort_order : 0);
    var activeEl = document.getElementById('ofr_active');
    if (activeEl) activeEl.checked = parseInt(row.is_active, 10) === 1;
    if (typeof ocpSetAlwaysOn === 'function') {
        ocpSetAlwaysOn('ofr', parseInt(row.is_always_on, 10) === 1);
    }
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
    var activeEl = document.getElementById('ofr_active');
    payload.is_active = (activeEl && activeEl.checked) ? 1 : 0;
    payload.is_always_on = (typeof ocpIsAlwaysOn === 'function' && ocpIsAlwaysOn('ofr')) ? 1 : 0;
    if (typeof ocpSchedulePayload === 'function') {
        Object.assign(payload, ocpSchedulePayload('ofr'));
    }
    if (!payload.product_id) {
        alert('اختر المنتج من القائمة (نقرتان)');
        return;
    }
    if (payload.is_always_on !== 1 && (!payload.valid_from || !payload.valid_to)) {
        alert('تاريخ بداية ونهاية العرض إلزاميان');
        return;
    }
    var res = await postJSON('/admin/api/offers/save.php', payload);
    alert(res.message || (res.success ? 'تم حفظ العرض' : 'فشل حفظ العرض'));
    if (res.success) location.reload();
}

if (typeof ocpDefaultScheduleDates === 'function') {
    ocpDefaultScheduleDates('ofr');
}
if (typeof ocpBindAlwaysOn === 'function') {
    ocpBindAlwaysOn('ofr');
}
if (typeof orangeInitDmyInputs === 'function') {
    orangeInitDmyInputs(document);
}
</script>
