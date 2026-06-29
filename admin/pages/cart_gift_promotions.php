<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/cart_promo_products.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$hasTable = orange_table_exists($pdo, 'cart_gift_promotions');
$cgpPickRows = orange_cart_promo_admin_product_rows($pdo);
$cgpPickJson = json_encode($cgpPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);
?>
<div class="page-title">
    <h1>عروض الهدايا (مجموعة اختيار / هدية ثابتة)</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>
<?php require_once __DIR__ . '/../../includes/offer_gl_link_card.php'; echo orange_offer_gl_link_card_html($pdo, ['promo_gift_discount']); ?>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>cart_gift_promotions</code> غير جاهز.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل</h3>
    <input type="hidden" id="cgp_id" value="0">
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;margin-bottom:14px;">
        <div style="max-width:120px;">
            <label for="cgp_sort">الترتيب</label>
            <input type="number" id="cgp_sort" class="admin-inp" value="0" readonly tabindex="-1" title="يُحدَّد تلقائياً" style="background:#f1f5f9;color:#64748b;cursor:not-allowed;text-align:center;">
        </div>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" id="cgp_active" checked> نشط
        </label>
    </div>
    <div class="form-grid">
        <div>
            <label for="cgp_name_ar">اسم العرض (عربي)</label>
            <input type="text" id="cgp_name_ar" class="admin-inp" dir="auto" placeholder="مثال: هدية عند تجاوز المبلغ">
        </div>
        <div>
            <label for="cgp_name_en">English</label>
            <input type="text" id="cgp_name_en" class="admin-inp" dir="ltr" lang="en" placeholder="Free gift over threshold">
        </div>
    </div>
    <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:18px;align-items:center;">
        <button type="button" class="btn-secondary" onclick="cgpTranslateOfferFromAr()">ترجمة تلقائية من العربي</button>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" id="cgp_show_name"> <strong>السماح بظهور الاسم للعميل</strong>
        </label>
    </div>
    <div class="cgp-split" style="margin-top:14px;">
        <!-- النصف الأيمن: إعدادات الهدية -->
        <div class="cgp-half">
            <h4 style="margin:0 0 10px;">إعدادات الهدية</h4>
            <div style="margin:0 0 12px;">
                <label><strong>نوع الهدية</strong></label>
                <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-top:6px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="radio" name="cgp_kind" value="choice" checked onchange="cgpToggleKind()"> اختيار من مجموعة منتجات
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="radio" name="cgp_kind" value="fixed" onchange="cgpToggleKind()"> هدية ثابتة (منتج واحد)
                    </label>
                </div>
            </div>
            <div id="cgp_block_pool" style="margin:0 0 12px;">
                <label>منتجات مجموعة الاختيار</label>
                <div style="margin:6px 0 8px;">
                    <button type="button" class="btn-secondary" id="cgp_pool_add_btn">إضافة منتج (دبل كليك من القائمة)</button>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>كود</th><th>المنتج</th><th></th></tr></thead>
                        <tbody id="cgp_pool_body"></tbody>
                    </table>
                </div>
            </div>
            <div id="cgp_block_fixed" style="margin:0 0 12px;display:none;">
                <label>منتج الهدية الثابتة</label>
                <div style="margin:6px 0 8px;">
                    <button type="button" class="btn-secondary" id="cgp_fixed_pick_btn">اختيار منتج (دبل كليك من القائمة)</button>
                </div>
                <p id="cgp_fixed_label" class="page-subtitle" style="margin:0;">— لم يُختر منتج —</p>
                <input type="hidden" id="cgp_fixed_pid" value="0">
            </div>
            <div style="margin:0 0 12px;">
                <label for="cgp_gift_charge_kind"><strong>تسعير بند الهدية</strong></label>
                <select id="cgp_gift_charge_kind" class="admin-inp" style="max-width:28rem;margin-top:6px;" onchange="cgpToggleGiftCharge()">
                    <option value="free">مجانية بالكامل (سطر هدية بسعر صفر)</option>
                    <option value="percent_off">خصم نسبة من سعر التجزئة للوحدة</option>
                    <option value="amount_off_unit">خصم مبلغ ثابت من سعر التجزئة للوحدة</option>
                    <option value="fixed_unit">سعر بيع ثابت للوحدة (د.ك)</option>
                </select>
            </div>
            <div id="cgp_gift_charge_val_wrap" style="display:none;">
                <label id="cgp_gift_charge_val_label">القيمة</label>
                <input type="number" id="cgp_gift_charge_val" class="admin-inp" min="0" step="0.0001" style="max-width:14rem;" dir="ltr" value="0">
            </div>
        </div>

        <!-- النصف الأيسر: الحد والفترة -->
        <div class="cgp-half">
            <h4 style="margin:0 0 10px;">الحد والفترة</h4>
            <div class="form-grid">
                <div style="grid-column:1/-1;">
                    <label>الحد الأدنى لمجموع السلة (د.ك) — 0 يعني بدون شرط مبلغ</label>
                    <input type="text" id="cgp_min" class="admin-inp-money" inputmode="decimal" lang="en" dir="ltr" placeholder="0">
                </div>
                <div>
                    <label for="cgp_valid_from">بداية العرض <span dir="ltr">*</span></label>
                    <input type="text" id="cgp_valid_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off" required>
                </div>
                <div>
                    <label for="cgp_valid_to">نهاية العرض <span dir="ltr">*</span></label>
                    <input type="text" id="cgp_valid_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off" required>
                </div>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:18px 22px;align-items:center;margin-top:12px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="cgp_always_on"> <strong>التفعيل الدائم</strong>
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="cgp_reg"> <strong>للمسجّلين فقط</strong>
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="cgp_first_delivered"> <strong>أول طلب مُسلَّم</strong>
                </label>
            </div>
        </div>
    </div>
    <style>
    .cgp-split { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    .cgp-half { border:1px solid #e2e8f0; border-radius:10px; padding:14px; background:#f8fafc; }
    @media (max-width: 720px) { .cgp-split { grid-template-columns:1fr; } }
    </style>
    <div class="admin-form-actions">
        <button type="button" onclick="saveCartGiftPromotion()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ</button>
        <button type="button" class="btn-secondary" onclick="resetCartGiftPromotionForm()">جديد</button>
    </div>
</div>

<div class="card">
    <h3>القواعد</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الاسم</th>
                    <th>حد أدنى</th>
                    <th>النوع</th>
                    <th>التفاصيل</th>
                    <th>تسعير</th>
                    <th>نطاق</th>
                    <th>الفترة</th>
                    <th>الحالة</th>
                    <th>ترتيب</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="cgp_tbody"></tbody>
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
            <tbody id="cgp_history_tbody">
                <tr><td colspan="6" class="muted">جارٍ التحميل...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin_cart_promo_product_pick.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
<?php require __DIR__ . '/../partials/cart_promo_schedule_js.inc.php'; ?>
var CGP_PICK_ROWS = <?php echo $cgpPickJson !== false ? $cgpPickJson : '[]'; ?>;
var cgpNameArTimer = null;
var cgpNameEnTimer = null;
var CGP_ROWS = [];

function cgpComputeNextSort() {
    var max = 0;
    (CGP_ROWS || []).forEach(function (r) {
        var s = parseInt(r.sort_order, 10) || 0;
        if (s > max) max = s;
    });
    return max + 1;
}

function cgpPickMeta(pid) {
    var id = parseInt(pid, 10) || 0;
    for (var i = 0; i < CGP_PICK_ROWS.length; i++) {
        if (parseInt(CGP_PICK_ROWS[i].product_id, 10) === id) {
            return CGP_PICK_ROWS[i];
        }
    }
    return { product_id: id, code: 'P' + id, name: '' };
}

function cgpFmtPoolIds(ids) {
    if (!ids || !ids.length) return '—';
    return ids.map(function (pid) {
        var m = cgpPickMeta(pid);
        return (m.code ? m.code + ' — ' : '') + (m.name || ('#' + pid));
    }).join('؛ ');
}

function cgpPoolRows() {
    var tb = document.getElementById('cgp_pool_body');
    if (!tb) return [];
    var out = [];
    tb.querySelectorAll('tr').forEach(function (tr) {
        var pid = parseInt(tr.getAttribute('data-product-id'), 10) || 0;
        if (pid > 0) out.push(pid);
    });
    return out;
}

function cgpAddPoolRow(pid) {
    var id = parseInt(pid, 10) || 0;
    if (id <= 0) return;
    var tb = document.getElementById('cgp_pool_body');
    if (!tb) return;
    var dup = false;
    tb.querySelectorAll('tr').forEach(function (tr) {
        if (parseInt(tr.getAttribute('data-product-id'), 10) === id) dup = true;
    });
    if (dup) return;
    var m = cgpPickMeta(id);
    var tr = document.createElement('tr');
    tr.setAttribute('data-product-id', String(id));
    tr.innerHTML =
        '<td dir="ltr">' + (m.code ? String(m.code) : ('P' + id)) + '</td>' +
        '<td>' + (m.name ? String(m.name) : '') + '</td>' +
        '<td><button type="button" class="btn-secondary cgp-rm">&times;</button></td>';
    tr.querySelector('.cgp-rm').addEventListener('click', function () { tr.remove(); });
    tb.appendChild(tr);
}

function cgpRenderPool(ids) {
    var tb = document.getElementById('cgp_pool_body');
    if (!tb) return;
    tb.innerHTML = '';
    (ids || []).forEach(function (pid) { cgpAddPoolRow(pid); });
}

function cgpSetFixed(pid) {
    var id = parseInt(pid, 10) || 0;
    document.getElementById('cgp_fixed_pid').value = String(id);
    var lab = document.getElementById('cgp_fixed_label');
    if (!lab) return;
    if (id <= 0) {
        lab.textContent = '— لم يُختر منتج —';
        return;
    }
    var m = cgpPickMeta(id);
    lab.textContent = (m.code ? m.code + ' — ' : '') + (m.name || ('منتج #' + id));
}

function cgpOpenPick(onPick) {
    if (!window.OrangeCartPromoProductPick) return;
    OrangeCartPromoProductPick.open(CGP_PICK_ROWS, onPick);
}

function cgpToggleKind() {
    const fixed = document.querySelector('input[name="cgp_kind"]:checked');
    const isFixed = fixed && fixed.value === 'fixed';
    document.getElementById('cgp_block_fixed').style.display = isFixed ? 'block' : 'none';
    document.getElementById('cgp_block_pool').style.display = isFixed ? 'none' : 'block';
}

function cgpToggleGiftCharge() {
    const sel = document.getElementById('cgp_gift_charge_kind');
    const k = sel ? sel.value : 'free';
    const wrap = document.getElementById('cgp_gift_charge_val_wrap');
    const lab = document.getElementById('cgp_gift_charge_val_label');
    const inp = document.getElementById('cgp_gift_charge_val');
    if (!wrap || !lab || !inp) return;
    if (k === 'free') {
        wrap.style.display = 'none';
        inp.value = '0';
        return;
    }
    wrap.style.display = 'block';
    if (k === 'percent_off') {
        lab.textContent = 'نسبة الخصم (0–100)';
        inp.max = '100';
        inp.step = '0.01';
    } else if (k === 'fixed_unit') {
        lab.textContent = 'سعر الوحدة (د.ك)';
        inp.removeAttribute('max');
        inp.step = '0.0001';
    } else {
        lab.textContent = 'المبلغ المخصوم من سعر التجزئة للوحدة (د.ك)';
        inp.removeAttribute('max');
        inp.step = '0.0001';
    }
}

function resetCartGiftPromotionForm() {
    document.getElementById('cgp_id').value = '0';
    document.getElementById('cgp_name_ar').value = '';
    document.getElementById('cgp_name_en').value = '';
    document.getElementById('cgp_show_name').checked = false;
    document.getElementById('cgp_min').value = '';
    document.getElementById('cgp_sort').value = String(cgpComputeNextSort());
    cgpRenderPool([]);
    cgpSetFixed(0);
    document.querySelector('input[name="cgp_kind"][value="choice"]').checked = true;
    document.getElementById('cgp_reg').checked = false;
    document.getElementById('cgp_first_delivered').checked = false;
    document.getElementById('cgp_active').checked = true;
    ocpSetAlwaysOn('cgp', false);
    ocpDefaultScheduleDates('cgp');
    document.getElementById('cgp_gift_charge_kind').value = 'free';
    document.getElementById('cgp_gift_charge_val').value = '0';
    cgpToggleKind();
    cgpToggleGiftCharge();
}

function editCartGiftPromotion(row) {
    document.getElementById('cgp_id').value = String(row.id != null ? row.id : 0);
    document.getElementById('cgp_name_ar').value = row.name_ar != null ? String(row.name_ar) : '';
    document.getElementById('cgp_name_en').value = row.name_en != null ? String(row.name_en) : '';
    document.getElementById('cgp_show_name').checked = parseInt(row.show_name_to_customer, 10) === 1;
    document.getElementById('cgp_min').value = row.min_subtotal != null ? String(row.min_subtotal) : '';
    document.getElementById('cgp_sort').value = String(row.sort_order != null ? row.sort_order : 0);
    const kind = (row.gift_kind || 'choice') === 'fixed' ? 'fixed' : 'choice';
    document.querySelector('input[name="cgp_kind"][value="' + kind + '"]').checked = true;
    cgpToggleKind();
    cgpRenderPool(row.pool_product_ids || row.pool_variant_ids || []);
    cgpSetFixed(row.fixed_product_id || row.fixed_variant_id || 0);
    document.getElementById('cgp_reg').checked = parseInt(row.requires_registered_account, 10) === 1;
    document.getElementById('cgp_first_delivered').checked = parseInt(row.first_delivered_order_only, 10) === 1;
    document.getElementById('cgp_active').checked = parseInt(row.is_active, 10) === 1;
    ocpSetAlwaysOn('cgp', parseInt(row.is_always_on, 10) === 1);
    ocpSetDmyFromIso('cgp_valid_from', row.valid_from);
    ocpSetDmyFromIso('cgp_valid_to', row.valid_to);
    var gck = (row.gift_unit_charge_kind || 'free').toLowerCase();
    var allowed = { free: 1, percent_off: 1, fixed_unit: 1, amount_off_unit: 1 };
    document.getElementById('cgp_gift_charge_kind').value = allowed[gck] ? gck : 'free';
    document.getElementById('cgp_gift_charge_val').value =
        row.gift_unit_charge_value != null && row.gift_unit_charge_value !== '' ? String(row.gift_unit_charge_value) : '0';
    cgpToggleGiftCharge();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function escCgp(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;');
}

function cgpRowName(row) {
    var ar = (row.name_ar != null ? String(row.name_ar) : '').trim();
    if (ar !== '') return ar;
    var en = (row.name_en != null ? String(row.name_en) : '').trim();
    return en !== '' ? en : ('#' + row.id);
}

async function cgpTranslateNames(opts) {
    var silent = !!(opts && opts.silent);
    var forceFromArabic = !!(opts && opts.forceFromArabic);
    var arEl = document.getElementById('cgp_name_ar');
    var enEl = document.getElementById('cgp_name_en');
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

function cgpScheduleNameFromAr() {
    var arEl = document.getElementById('cgp_name_ar');
    if (!arEl) return;
    clearTimeout(cgpNameArTimer);
    cgpNameArTimer = setTimeout(function () {
        cgpTranslateNames({ silent: true, forceFromArabic: true });
    }, 700);
}

function cgpScheduleNameFromEn() {
    var enEl = document.getElementById('cgp_name_en');
    if (!enEl || enEl.value.trim() === '') return;
    clearTimeout(cgpNameEnTimer);
    cgpNameEnTimer = setTimeout(function () {
        cgpTranslateNames({ silent: true, forceFromArabic: false });
    }, 600);
}

async function cgpTranslateOfferFromAr() {
    await cgpTranslateNames({ silent: false, forceFromArabic: true });
}
window.cgpTranslateOfferFromAr = cgpTranslateOfferFromAr;

async function loadCartGiftPromotions() {
    const res = await postJSON('/admin/api/cart_gift_promotions/manage.php', { action: 'list' });
    if (!res.success) {
        alert(res.message || 'خطأ');
        return;
    }
    const rows = res.data || [];
    CGP_ROWS = rows;
    const tb = document.getElementById('cgp_tbody');
    tb.innerHTML = '';
    rows.forEach(function (r) {
        const kind = (r.gift_kind || 'choice') === 'fixed' ? 'ثابتة' : 'اختيار';
        let det = '';
        if ((r.gift_kind || '') === 'fixed') {
            det = escCgp(cgpFmtPoolIds([r.fixed_product_id || r.fixed_variant_id].filter(Boolean)));
        } else {
            det = escCgp(cgpFmtPoolIds(r.pool_product_ids || r.pool_variant_ids || []));
        }
        var gcharge = 'مجانية';
        var gck = (r.gift_unit_charge_kind || 'free').toLowerCase();
        if (gck === 'percent_off') gcharge = 'خصم %';
        else if (gck === 'fixed_unit') gcharge = 'سعر ثابت';
        else if (gck === 'amount_off_unit') gcharge = 'خصم مبلغ';
        var cgpScope = (parseInt(r.requires_registered_account, 10) === 1 ? 'مسجّل فقط' : 'جميع الزوّار') + (parseInt(r.first_delivered_order_only, 10) === 1 ? ' • أول طلب مُسلَّم' : '');
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + escCgp(String(r.id)) + '</td>' +
            '<td>' + escCgp(cgpRowName(r)) + '</td>' +
            '<td dir="ltr">' + escCgp(String(r.min_subtotal)) + '</td>' +
            '<td>' + kind + '</td>' +
            '<td style="max-width:18rem;">' + det + '</td>' +
            '<td>' + escCgp(gcharge) + '</td>' +
            '<td>' + escCgp(cgpScope) + '</td>' +
            '<td dir="ltr">' + escCgp(ocpScheduleLabel(r)) + '</td>' +
            '<td>' + escCgp(ocpStatusLabel(r)) + '</td>' +
            '<td>' + escCgp(String(r.sort_order)) + '</td>' +
            '<td><button type="button" class="btn-secondary" data-cgp-edit="' + escCgp(String(r.id)) + '">تعديل</button></td>';
        tb.appendChild(tr);
    });
    tb.querySelectorAll('[data-cgp-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = parseInt(btn.getAttribute('data-cgp-edit'), 10);
            const row = rows.find(function (x) { return parseInt(x.id, 10) === id; });
            if (row) editCartGiftPromotion(row);
        });
    });
    if ((parseInt(document.getElementById('cgp_id').value, 10) || 0) === 0) {
        document.getElementById('cgp_sort').value = String(cgpComputeNextSort());
    }
}

async function saveCartGiftPromotion() {
    const kindEl = document.querySelector('input[name="cgp_kind"]:checked');
    const res = await postJSON('/admin/api/cart_gift_promotions/manage.php', {
        action: 'save',
        id: parseInt(document.getElementById('cgp_id').value, 10) || 0,
        name_ar: document.getElementById('cgp_name_ar').value.trim(),
        name_en: document.getElementById('cgp_name_en').value.trim(),
        show_name_to_customer: document.getElementById('cgp_show_name').checked ? 1 : 0,
        min_subtotal: document.getElementById('cgp_min').value.trim(),
        requires_registered_account: document.getElementById('cgp_reg').checked ? 1 : 0,
        first_delivered_order_only: document.getElementById('cgp_first_delivered').checked ? 1 : 0,
        is_active: document.getElementById('cgp_active').checked ? 1 : 0,
        is_always_on: ocpIsAlwaysOn('cgp') ? 1 : 0,
        gift_kind: kindEl ? kindEl.value : 'choice',
        fixed_product_id: parseInt(document.getElementById('cgp_fixed_pid').value, 10) || 0,
        pool_product_ids: cgpPoolRows(),
        gift_unit_charge_kind: document.getElementById('cgp_gift_charge_kind').value,
        gift_unit_charge_value: parseFloat(document.getElementById('cgp_gift_charge_val').value) || 0,
        valid_from: ocpGetIso('cgp_valid_from'),
        valid_to: ocpGetIso('cgp_valid_to')
    });
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
    if (res.success) {
        resetCartGiftPromotionForm();
        loadCartGiftPromotions();
        loadCartGiftAlwaysOnHistory();
    }
}

function cgpAdminName(name) {
    var s = String(name || '').trim();
    return s !== '' ? s : '—';
}

async function loadCartGiftAlwaysOnHistory() {
    var tb = document.getElementById('cgp_history_tbody');
    if (!tb) return;
    var res = await postJSON('/admin/api/cart_gift_promotions/manage.php', { action: 'always_on_history' });
    if (!res || !res.success || !Array.isArray(res.data)) {
        tb.innerHTML = '<tr><td colspan="6" class="muted">تعذر تحميل السجل.</td></tr>';
        return;
    }
    var rows = res.data;
    if (!rows.length) {
        tb.innerHTML = '<tr><td colspan="6" class="muted">لا توجد عمليات تفعيل دائم مسجلة بعد.</td></tr>';
        return;
    }
    tb.innerHTML = '';
    rows.forEach(function (row) {
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + escCgp(String(row.id || '')) + '</td>' +
            '<td>عرض #' + escCgp(String(row.promotion_id || '')) + '</td>' +
            '<td dir="ltr">' + escCgp(String(row.started_at || '')) + '</td>' +
            '<td dir="ltr">' + escCgp(String(row.ended_at || '')) + '</td>' +
            '<td>' + escCgp(cgpAdminName(row.started_by_name)) + '</td>' +
            '<td>' + escCgp(cgpAdminName(row.ended_by_name)) + '</td>';
        tb.appendChild(tr);
    });
}

(function () {
    var arEl = document.getElementById('cgp_name_ar');
    var enEl = document.getElementById('cgp_name_en');
    if (arEl) arEl.addEventListener('input', cgpScheduleNameFromAr);
    if (enEl) enEl.addEventListener('input', cgpScheduleNameFromEn);
})();
document.getElementById('cgp_pool_add_btn').addEventListener('click', function () {
    cgpOpenPick(function (row) { cgpAddPoolRow(row.product_id); });
});
document.getElementById('cgp_fixed_pick_btn').addEventListener('click', function () {
    cgpOpenPick(function (row) { cgpSetFixed(row.product_id); });
});
cgpToggleKind();
cgpToggleGiftCharge();
ocpBindAlwaysOn('cgp');
ocpDefaultScheduleDates('cgp');
loadCartGiftPromotions();
loadCartGiftAlwaysOnHistory();
</script>
