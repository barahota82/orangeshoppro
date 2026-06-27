<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/cart_promo_products.php';
require_once __DIR__ . '/../../includes/offer_gl_link_card.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$hasTable = orange_table_exists($pdo, 'cart_combo_promotions');
$ccpPickRows = orange_cart_promo_admin_product_rows($pdo);
$ccpPickJson = json_encode($ccpPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);
?>
<div class="page-title">
    <h1>عروض الكومبو</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>
<?php echo orange_offer_gl_link_card_html($pdo, ['promo_combo_discount']); ?>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>cart_combo_promotions</code> غير جاهز.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل</h3>
    <input type="hidden" id="ccp_id" value="0">
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;margin-bottom:14px;">
        <div style="max-width:120px;">
            <label for="ccp_sort">الترتيب</label>
            <input type="number" id="ccp_sort" class="admin-inp" value="0" readonly tabindex="-1" title="يُحدَّد تلقائياً" style="background:#f1f5f9;color:#64748b;cursor:not-allowed;text-align:center;">
        </div>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" id="ccp_active" checked> نشط
        </label>
    </div>
    <div class="form-grid">
        <div>
            <label for="ccp_title_ar">اسم العرض (عربي)</label>
            <input type="text" id="ccp_title_ar" class="admin-inp" dir="auto" placeholder="مثال: كومبو نهاية الأسبوع">
        </div>
        <div>
            <label for="ccp_title_en">English</label>
            <input type="text" id="ccp_title_en" class="admin-inp" dir="ltr" lang="en" placeholder="Weekend combo">
        </div>
    </div>
    <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:18px;align-items:center;">
        <button type="button" class="btn-secondary" onclick="ccpTranslateOfferFromAr()">ترجمة تلقائية من العربي</button>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" id="ccp_show_name"> <strong>السماح بظهور الاسم للعميل</strong>
        </label>
    </div>
    <div class="form-grid" style="margin-top:12px;">
        <div><label>سعر الحزمة الواحدة (د.ك)</label><input type="text" id="ccp_price" class="admin-inp-money" inputmode="decimal" lang="en" dir="ltr" placeholder="9.5"></div>
        <?php $ocpFieldPrefix = 'ccp'; require __DIR__ . '/../partials/cart_promo_schedule_fields.inc.php'; ?>
        <div style="grid-column:1/-1;">
            <label>منتجات الحزمة</label>
            <div style="margin:6px 0 8px;">
                <button type="button" class="btn-secondary" id="ccp_add_product_btn">إضافة منتج (دبل كليك من القائمة)</button>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>كود</th><th>المنتج</th><th>الكمية</th><th></th></tr></thead>
                    <tbody id="ccp_comp_body"></tbody>
                </table>
            </div>
        </div>
        <div style="grid-column:1/-1;display:flex;flex-wrap:wrap;gap:20px;align-items:center;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="ccp_reg"> <strong>للمسجّلين فقط</strong>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="ccp_first_delivered"> <strong>أول طلب مُسلَّم</strong>
            </label>
        </div>
    </div>
    <div class="admin-form-actions">
        <button type="button" onclick="saveCartComboPromotion()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ</button>
        <button type="button" class="btn-secondary" onclick="resetCartComboPromotionForm()">جديد</button>
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
                    <th>المكوّنات</th>
                    <th>سعر الحزمة</th>
                    <th>نطاق</th>
                    <th>الفترة</th>
                    <th>الحالة</th>
                    <th>ترتيب</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="ccp_tbody"></tbody>
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
            <tbody id="ccp_history_tbody">
                <tr><td colspan="6" class="muted">جارٍ التحميل...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin_cart_promo_product_pick.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
<?php require __DIR__ . '/../partials/cart_promo_schedule_js.inc.php'; ?>
var CCP_PICK_ROWS = <?php echo $ccpPickJson !== false ? $ccpPickJson : '[]'; ?>;
var ccpNameArTimer = null;
var ccpNameEnTimer = null;

function ccpFmtComps(comps) {
    if (!comps || !comps.length) return '—';
    return comps.map(function (c) {
        var n = c.product_name || c.code || ('#' + c.product_id);
        return n + '×' + String(c.qty);
    }).join(' + ');
}

function ccpCompRows() {
    var tb = document.getElementById('ccp_comp_body');
    if (!tb) return [];
    var out = [];
    tb.querySelectorAll('tr').forEach(function (tr) {
        var pid = parseInt(tr.getAttribute('data-product-id'), 10) || 0;
        var qEl = tr.querySelector('.ccp-qty');
        var q = qEl ? parseInt(qEl.value, 10) || 0 : 0;
        if (pid > 0 && q > 0) out.push({ product_id: pid, qty: q });
    });
    return out;
}

function ccpRenderComps(comps) {
    var tb = document.getElementById('ccp_comp_body');
    if (!tb) return;
    tb.innerHTML = '';
    (comps || []).forEach(function (c) {
        ccpAddCompRow(c);
    });
}

function ccpAddCompRow(c) {
    var tb = document.getElementById('ccp_comp_body');
    if (!tb) return;
    var pid = parseInt(c.product_id, 10) || 0;
    var tr = document.createElement('tr');
    tr.setAttribute('data-product-id', String(pid));
    tr.innerHTML =
        '<td dir="ltr">' + (c.code ? String(c.code) : ('P' + pid)) + '</td>' +
        '<td>' + (c.product_name ? String(c.product_name) : '') + '</td>' +
        '<td><input type="number" class="ccp-qty admin-inp-qty" min="1" step="1" value="' + (parseInt(c.qty, 10) || 1) + '" style="width:5rem;"></td>' +
        '<td><button type="button" class="btn-secondary ccp-rm">&times;</button></td>';
    tr.querySelector('.ccp-rm').addEventListener('click', function () { tr.remove(); });
    tb.appendChild(tr);
}

function ccpOpenPick() {
    if (!window.OrangeCartPromoProductPick) return;
    OrangeCartPromoProductPick.open(CCP_PICK_ROWS, function (row) {
        ccpAddCompRow({ product_id: row.product_id, code: row.code, product_name: row.name, qty: 1 });
    });
}

function resetCartComboPromotionForm() {
    document.getElementById('ccp_id').value = '0';
    document.getElementById('ccp_title_ar').value = '';
    document.getElementById('ccp_title_en').value = '';
    document.getElementById('ccp_show_name').checked = false;
    document.getElementById('ccp_price').value = '';
    document.getElementById('ccp_sort').value = '0';
    ccpRenderComps([]);
    document.getElementById('ccp_reg').checked = false;
    document.getElementById('ccp_first_delivered').checked = false;
    document.getElementById('ccp_active').checked = true;
    ocpSetAlwaysOn('ccp', false);
    ocpDefaultScheduleDates('ccp');
}

function editCartComboPromotion(row) {
    document.getElementById('ccp_id').value = String(row.id != null ? row.id : 0);
    document.getElementById('ccp_title_ar').value = row.title_ar != null ? String(row.title_ar) : '';
    document.getElementById('ccp_title_en').value = row.title_en != null ? String(row.title_en) : '';
    document.getElementById('ccp_show_name').checked = parseInt(row.show_name_to_customer, 10) === 1;
    document.getElementById('ccp_price').value = row.combo_price != null ? String(row.combo_price) : '';
    document.getElementById('ccp_sort').value = String(row.sort_order != null ? row.sort_order : 0);
    document.getElementById('ccp_reg').checked = parseInt(row.requires_registered_account, 10) === 1;
    document.getElementById('ccp_first_delivered').checked = parseInt(row.first_delivered_order_only, 10) === 1;
    document.getElementById('ccp_active').checked = parseInt(row.is_active, 10) === 1;
    ocpSetAlwaysOn('ccp', parseInt(row.is_always_on, 10) === 1);
    ocpSetDmyFromIso('ccp_valid_from', row.valid_from);
    ocpSetDmyFromIso('ccp_valid_to', row.valid_to);
    ccpRenderComps(row.components || []);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function escCcp(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;');
}

function ccpRowName(row) {
    var ar = (row.title_ar != null ? String(row.title_ar) : '').trim();
    if (ar !== '') return ar;
    var en = (row.title_en != null ? String(row.title_en) : '').trim();
    return en !== '' ? en : ('#' + row.id);
}

async function ccpTranslateNames(opts) {
    var silent = !!(opts && opts.silent);
    var forceFromArabic = !!(opts && opts.forceFromArabic);
    var arEl = document.getElementById('ccp_title_ar');
    var enEl = document.getElementById('ccp_title_en');
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

function ccpScheduleNameFromAr() {
    var arEl = document.getElementById('ccp_title_ar');
    if (!arEl) return;
    clearTimeout(ccpNameArTimer);
    ccpNameArTimer = setTimeout(function () {
        ccpTranslateNames({ silent: true, forceFromArabic: true });
    }, 700);
}

function ccpScheduleNameFromEn() {
    var enEl = document.getElementById('ccp_title_en');
    if (!enEl || enEl.value.trim() === '') return;
    clearTimeout(ccpNameEnTimer);
    ccpNameEnTimer = setTimeout(function () {
        ccpTranslateNames({ silent: true, forceFromArabic: false });
    }, 600);
}

async function ccpTranslateOfferFromAr() {
    await ccpTranslateNames({ silent: false, forceFromArabic: true });
}
window.ccpTranslateOfferFromAr = ccpTranslateOfferFromAr;

async function loadCartComboPromotions() {
    var res = await postJSON('/admin/api/cart_combo_promotions/manage.php', { action: 'list' });
    var tb = document.getElementById('ccp_tbody');
    if (!res.success || !Array.isArray(res.data)) {
        tb.innerHTML = '<tr><td colspan="9">تعذر التحميل</td></tr>';
        return;
    }
    var rows = res.data;
    tb.innerHTML = '';
    rows.forEach(function (r) {
        var scope = (parseInt(r.requires_registered_account, 10) === 1 ? 'مسجّل فقط' : 'الكل') + (parseInt(r.first_delivered_order_only, 10) === 1 ? ' • أول طلب مُسلَّم' : '');
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + escCcp(String(r.id)) + '</td>' +
            '<td>' + escCcp(ccpRowName(r)) + '</td>' +
            '<td dir="ltr" style="font-family:monospace;font-size:0.85rem;">' + escCcp(ccpFmtComps(r.components)) + '</td>' +
            '<td dir="ltr">' + escCcp(String(r.combo_price)) + '</td>' +
            '<td>' + escCcp(scope) + '</td>' +
            '<td dir="ltr">' + escCcp(ocpScheduleLabel(r)) + '</td>' +
            '<td>' + escCcp(ocpStatusLabel(r)) + '</td>' +
            '<td>' + escCcp(String(r.sort_order)) + '</td>' +
            '<td><button type="button" class="btn-secondary" data-ccp-edit="' + escCcp(String(r.id)) + '">تعديل</button></td>';
        tb.appendChild(tr);
    });
    tb.querySelectorAll('[data-ccp-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = parseInt(btn.getAttribute('data-ccp-edit'), 10);
            var row = rows.find(function (x) { return parseInt(x.id, 10) === id; });
            if (row) {
                editCartComboPromotion(row);
            }
        });
    });
}

async function saveCartComboPromotion() {
    var res = await postJSON('/admin/api/cart_combo_promotions/manage.php', {
        action: 'save',
        id: parseInt(document.getElementById('ccp_id').value, 10) || 0,
        title_ar: document.getElementById('ccp_title_ar').value,
        title_en: document.getElementById('ccp_title_en').value,
        show_name_to_customer: document.getElementById('ccp_show_name').checked ? 1 : 0,
        combo_price: document.getElementById('ccp_price').value,
        requires_registered_account: document.getElementById('ccp_reg').checked ? 1 : 0,
        first_delivered_order_only: document.getElementById('ccp_first_delivered').checked ? 1 : 0,
        is_active: document.getElementById('ccp_active').checked ? 1 : 0,
        is_always_on: ocpIsAlwaysOn('ccp') ? 1 : 0,
        valid_from: ocpGetIso('ccp_valid_from'),
        valid_to: ocpGetIso('ccp_valid_to'),
        components: ccpCompRows()
    });
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
    if (res.success) {
        resetCartComboPromotionForm();
        loadCartComboPromotions();
        loadCartComboAlwaysOnHistory();
    }
}

function ccpAdminName(name) {
    var s = String(name || '').trim();
    return s !== '' ? s : '—';
}

async function loadCartComboAlwaysOnHistory() {
    var tb = document.getElementById('ccp_history_tbody');
    if (!tb) return;
    var res = await postJSON('/admin/api/cart_combo_promotions/manage.php', { action: 'always_on_history' });
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
            '<td>' + escCcp(String(row.id || '')) + '</td>' +
            '<td>عرض #' + escCcp(String(row.promotion_id || '')) + '</td>' +
            '<td dir="ltr">' + escCcp(String(row.started_at || '')) + '</td>' +
            '<td dir="ltr">' + escCcp(String(row.ended_at || '')) + '</td>' +
            '<td>' + escCcp(ccpAdminName(row.started_by_name)) + '</td>' +
            '<td>' + escCcp(ccpAdminName(row.ended_by_name)) + '</td>';
        tb.appendChild(tr);
    });
}

(function () {
    var arEl = document.getElementById('ccp_title_ar');
    var enEl = document.getElementById('ccp_title_en');
    if (arEl) arEl.addEventListener('input', ccpScheduleNameFromAr);
    if (enEl) enEl.addEventListener('input', ccpScheduleNameFromEn);
})();
document.getElementById('ccp_add_product_btn').addEventListener('click', ccpOpenPick);
ocpBindAlwaysOn('ccp');
ocpDefaultScheduleDates('ccp');
loadCartComboPromotions();
loadCartComboAlwaysOnHistory();
</script>
