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
$ofrNextSort = 1;
if ($hasSortCol) {
    $ofrMaxSort = 0;
    foreach ($offers as $ofrRow) {
        $ofrSortVal = (int) ($ofrRow['sort_order'] ?? 0);
        if ($ofrSortVal > $ofrMaxSort) {
            $ofrMaxSort = $ofrSortVal;
        }
    }
    $ofrNextSort = $ofrMaxSort + 1;
}
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
            <input type="number" id="ofr_sort" class="admin-inp" value="<?php echo (int) $ofrNextSort; ?>" readonly tabindex="-1" title="يُحدَّد تلقائياً" style="background:#f1f5f9;color:#64748b;cursor:not-allowed;text-align:center;" dir="ltr">
        </div>
        <?php endif; ?>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" id="ofr_active" checked> نشط
        </label>
    </div>
    <div class="form-grid">
        <div>
            <label for="ofr_name_ar">اسم العرض (عربي)</label>
            <input type="text" id="ofr_name_ar" class="admin-inp" dir="auto" placeholder="مثال: تخفيضات نهاية الموسم">
        </div>
        <div>
            <label for="ofr_name_en">English</label>
            <input type="text" id="ofr_name_en" class="admin-inp" dir="ltr" lang="en" placeholder="End of season sale">
        </div>
    </div>
    <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:18px;align-items:center;">
        <button type="button" class="btn-secondary" onclick="ofrTranslateOfferFromAr()">ترجمة تلقائية من العربي</button>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" id="ofr_show_name"> <strong>السماح بظهور الاسم للعميل</strong>
        </label>
    </div>

    <div class="ofr-split" style="margin-top:14px;">
        <!-- النصف الأيمن: المنتج وبياناته (مراجعة قبل تحديد الخصم) -->
        <div class="ofr-half">
            <h4 style="margin:0 0 10px;">المنتج</h4>
            <div style="margin:0 0 10px;">
                <button type="button" class="btn-secondary" id="ofr_pick_btn">اختيار منتج (دبل كليك من القائمة)</button>
            </div>
            <input type="hidden" id="offer_product_id" value="0">
            <p id="ofr_product_label" class="page-subtitle" style="margin:0 0 10px;">— لم يُختر منتج —</p>
            <div class="ofr-prod-facts">
                <div class="ofr-fact"><span>التكلفة</span><b id="ofr_fact_cost" dir="ltr">—</b></div>
                <div class="ofr-fact"><span>سعر البيع</span><b id="ofr_fact_price" dir="ltr">—</b></div>
                <div class="ofr-fact ofr-fact-after"><span>السعر بعد الخصم</span><b id="ofr_fact_after" dir="ltr">—</b></div>
            </div>
            <p id="ofr_below_cost_warn" style="display:none;margin:10px 0 0;color:#dc2626;font-weight:700;">⚠ السعر بعد الخصم أقل من التكلفة — غير مسموح بالحفظ.</p>
        </div>

        <!-- النصف الأيسر: الخصم والفترة -->
        <div class="ofr-half">
            <h4 style="margin:0 0 10px;">الخصم والفترة</h4>
            <div class="form-grid">
                <div>
                    <label for="discount">قيمة الخصم (<?php echo htmlspecialchars((string) ($offersMoney['unit'] ?? 'KWD'), ENT_QUOTES, 'UTF-8'); ?>)</label>
                    <input type="number" id="discount" class="admin-inp-money" step="any" min="0" inputmode="decimal" lang="en" dir="ltr">
                </div>
                <div>
                    <label>&nbsp;</label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;height:38px;">
                        <input type="checkbox" id="ofr_always_on">
                        <span><strong>التفعيل الدائم</strong></span>
                    </label>
                </div>
                <div>
                    <label>&nbsp;</label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;height:38px;" title="عند التفعيل يظهر السعر القديم مشطوباً بجوار سعر العرض للعميل (يتطلب الترخيص اللازم)">
                        <input type="checkbox" id="ofr_show_old_price">
                        <span><strong>إظهار السعر القديم</strong></span>
                    </label>
                </div>
                <div>
                    <label for="ofr_valid_from">بداية العرض <span dir="ltr">*</span></label>
                    <input type="text" id="ofr_valid_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off" required>
                </div>
                <div>
                    <label for="ofr_valid_to">نهاية العرض <span dir="ltr">*</span></label>
                    <input type="text" id="ofr_valid_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off" required>
                </div>
            </div>
        </div>
    </div>
    <style>
    .ofr-split { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    .ofr-half { border:1px solid #e2e8f0; border-radius:10px; padding:14px; background:#f8fafc; }
    .ofr-prod-facts { display:flex; flex-direction:column; gap:6px; }
    .ofr-fact { display:flex; justify-content:space-between; gap:12px; padding:6px 10px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; }
    .ofr-fact span { color:#64748b; }
    .ofr-fact b { font-variant-numeric:tabular-nums; }
    .ofr-fact-after b { color:#0f766e; }
    @media (max-width: 720px) { .ofr-split { grid-template-columns:1fr; } }
    </style>
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
var OFR_ROWS = <?php echo json_encode(array_values($offers), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;

function ofrComputeNextSort() {
    var max = 0;
    (OFR_ROWS || []).forEach(function (r) {
        var s = parseInt(r.sort_order, 10) || 0;
        if (s > max) max = s;
    });
    return max + 1;
}

var OFR_MONEY_UNIT = <?php echo json_encode((string) ($offersMoney['unit'] ?? 'KWD'), JSON_UNESCAPED_UNICODE); ?>;
var OFR_MONEY_DECIMALS = <?php echo (int) ($offersMoney['decimals'] ?? 3); ?>;
var ofrSelProduct = { price: null, cost: null };

function ofrFmtMoney(v) {
    if (v == null || isNaN(v)) return '—';
    return Number(v).toFixed(OFR_MONEY_DECIMALS) + ' ' + OFR_MONEY_UNIT;
}

function ofrSetProduct(row) {
    document.getElementById('offer_product_id').value = String(row.product_id || 0);
    var code = row.code ? row.code + ' — ' : '';
    document.getElementById('ofr_product_label').textContent = code + (row.name || '');
    ofrSelProduct.price = (row.price != null && row.price !== '') ? Number(row.price) : null;
    ofrSelProduct.cost = (row.cost != null && row.cost !== '') ? Number(row.cost) : null;
    ofrRenderFacts();
}

// يحدّث التكلفة/سعر البيع/السعر بعد الخصم + تنبيه ما دون التكلفة. يُرجِع true إذا الحالة سليمة للحفظ.
function ofrRenderFacts() {
    var costEl = document.getElementById('ofr_fact_cost');
    var priceEl = document.getElementById('ofr_fact_price');
    var afterEl = document.getElementById('ofr_fact_after');
    var warnEl = document.getElementById('ofr_below_cost_warn');
    var cost = ofrSelProduct.cost;
    var price = ofrSelProduct.price;
    if (costEl) costEl.textContent = ofrFmtMoney(cost);
    if (priceEl) priceEl.textContent = ofrFmtMoney(price);
    var discount = parseFloat(document.getElementById('discount').value || '0') || 0;
    var ok = true;
    var after = null;
    if (price != null) {
        after = price - discount;
        if (afterEl) afterEl.textContent = ofrFmtMoney(after);
        var below = (cost != null && cost > 0 && after < cost) || after <= 0;
        if (warnEl) warnEl.style.display = (discount > 0 && below) ? 'block' : 'none';
        if (discount > 0 && below) ok = false;
    } else {
        if (afterEl) afterEl.textContent = '—';
        if (warnEl) warnEl.style.display = 'none';
    }
    return ok;
}

document.getElementById('ofr_pick_btn').addEventListener('click', function () {
    if (!window.OrangeCartPromoProductPick) return;
    OrangeCartPromoProductPick.open(window.OFR_PICK_ROWS || [], ofrSetProduct);
});

var ofrNameArTimer = null;
var ofrNameEnTimer = null;

async function ofrTranslateNames(opts) {
    var silent = !!(opts && opts.silent);
    var forceFromArabic = !!(opts && opts.forceFromArabic);
    var arEl = document.getElementById('ofr_name_ar');
    var enEl = document.getElementById('ofr_name_en');
    if (!arEl || !enEl) return;
    try {
        var res = await postJSON('/admin/api/translate/names.php', {
            name_ar: arEl.value.trim(),
            name_en: forceFromArabic ? '' : enEl.value.trim()
        });
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        if (res.name_en != null) enEl.value = String(res.name_en);
    } catch (e) {
        if (!silent) alert('تعذر الاتصال بخدمة الترجمة');
    }
}

function ofrScheduleNameFromAr() {
    var arEl = document.getElementById('ofr_name_ar');
    if (!arEl) return;
    clearTimeout(ofrNameArTimer);
    ofrNameArTimer = setTimeout(function () {
        ofrTranslateNames({ silent: true, forceFromArabic: true });
    }, 700);
}

function ofrScheduleNameFromEn() {
    var enEl = document.getElementById('ofr_name_en');
    if (!enEl || enEl.value.trim() === '') return;
    clearTimeout(ofrNameEnTimer);
    ofrNameEnTimer = setTimeout(function () {
        ofrTranslateNames({ silent: true, forceFromArabic: false });
    }, 600);
}

async function ofrTranslateOfferFromAr() {
    await ofrTranslateNames({ silent: false, forceFromArabic: true });
}
window.ofrTranslateOfferFromAr = ofrTranslateOfferFromAr;

function ofrResetForm() {
    document.getElementById('ofr_id').value = '0';
    document.getElementById('offer_product_id').value = '0';
    document.getElementById('ofr_product_label').textContent = '— لم يُختر منتج —';
    document.getElementById('ofr_name_ar').value = '';
    document.getElementById('ofr_name_en').value = '';
    document.getElementById('ofr_show_name').checked = false;
    var showOldResetEl = document.getElementById('ofr_show_old_price');
    if (showOldResetEl) showOldResetEl.checked = false;
    document.getElementById('discount').value = '';
    ofrSelProduct = { price: null, cost: null };
    ofrRenderFacts();
    var sortEl = document.getElementById('ofr_sort');
    if (sortEl) sortEl.value = String(ofrComputeNextSort());
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
    var pickRow = (window.OFR_PICK_ROWS || []).find(function (r) {
        return parseInt(r.product_id, 10) === parseInt(row.product_id, 10);
    });
    ofrSelProduct.price = (pickRow && pickRow.price != null && pickRow.price !== '') ? Number(pickRow.price) : null;
    ofrSelProduct.cost = (pickRow && pickRow.cost != null && pickRow.cost !== '') ? Number(pickRow.cost) : null;
    document.getElementById('ofr_name_ar').value = row.name_ar != null ? String(row.name_ar) : '';
    document.getElementById('ofr_name_en').value = row.name_en != null ? String(row.name_en) : '';
    document.getElementById('ofr_show_name').checked = parseInt(row.show_name_to_customer, 10) === 1;
    var showOldEl = document.getElementById('ofr_show_old_price');
    if (showOldEl) showOldEl.checked = parseInt(row.show_old_price_to_customer, 10) === 1;
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
    ofrRenderFacts();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function saveOffer() {
    var payload = {
        id: parseInt(document.getElementById('ofr_id').value, 10) || 0,
        product_id: parseInt(document.getElementById('offer_product_id').value, 10),
        discount: parseFloat(document.getElementById('discount').value || '0'),
        name_ar: document.getElementById('ofr_name_ar').value.trim(),
        name_en: document.getElementById('ofr_name_en').value.trim(),
        show_name_to_customer: document.getElementById('ofr_show_name').checked ? 1 : 0,
        show_old_price_to_customer: (document.getElementById('ofr_show_old_price') && document.getElementById('ofr_show_old_price').checked) ? 1 : 0
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
    if (!(payload.discount > 0)) {
        alert('قيمة الخصم يجب أن تكون أكبر من صفر');
        return;
    }
    if (!ofrRenderFacts()) {
        alert('السعر بعد الخصم أقل من تكلفة المنتج (أو صفر/سالب) — قلّل قيمة الخصم قبل الحفظ.');
        return;
    }
    var res = await postJSON('/admin/api/offers/save.php', payload);
    alert(res.message || (res.success ? 'تم حفظ العرض' : 'فشل حفظ العرض'));
    if (res.success) location.reload();
}

(function () {
    var arEl = document.getElementById('ofr_name_ar');
    var enEl = document.getElementById('ofr_name_en');
    if (arEl) arEl.addEventListener('input', ofrScheduleNameFromAr);
    if (enEl) enEl.addEventListener('input', ofrScheduleNameFromEn);
    var discEl = document.getElementById('discount');
    if (discEl) discEl.addEventListener('input', ofrRenderFacts);
})();
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
