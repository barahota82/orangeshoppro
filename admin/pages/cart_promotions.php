<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/offer_gl_link_card.php';
$pdo = db();
orange_catalog_ensure_schema($pdo);
$hasTable = orange_table_exists($pdo, 'cart_promotions');
?>
<div class="page-title">
    <h1>عروض مجموع السلة</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php echo orange_offer_gl_link_card_html($pdo, ['promo_cart_discount']); ?>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>cart_promotions</code> غير جاهز.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل</h3>
    <input type="hidden" id="cp_id" value="0">
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;margin-bottom:14px;">
        <div style="max-width:120px;">
            <label for="cp_sort">الترتيب</label>
            <input type="number" id="cp_sort" class="admin-inp" value="0" readonly tabindex="-1" title="يُحدَّد تلقائياً" style="background:#f1f5f9;color:#64748b;cursor:not-allowed;text-align:center;">
        </div>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" id="cp_active" checked> نشط
        </label>
    </div>
    <div class="form-grid">
        <div>
            <label for="cp_name_ar">اسم العرض (عربي)</label>
            <input type="text" id="cp_name_ar" class="admin-inp" dir="auto" placeholder="مثال: خصم سلة نهاية الأسبوع">
        </div>
        <div>
            <label for="cp_name_en">English</label>
            <input type="text" id="cp_name_en" class="admin-inp" dir="ltr" lang="en" placeholder="Weekend cart discount">
        </div>
    </div>
    <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:18px;align-items:center;">
        <button type="button" class="btn-secondary" onclick="cpTranslateOfferFromAr()">ترجمة تلقائية من العربي</button>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" id="cp_show_name"> <strong>السماح بظهور الاسم للعميل</strong>
        </label>
    </div>
    <div class="form-grid" style="margin-top:12px;">
        <div><label>الحد الأدنى لمجموع السلة (د.ك)</label><input type="text" id="cp_min" class="admin-inp-money" inputmode="decimal" lang="en" dir="ltr" placeholder="10"></div>
        <div><label>مبلغ الخصم (د.ك)</label><input type="text" id="cp_disc" class="admin-inp-money" inputmode="decimal" lang="en" dir="ltr" placeholder="2"></div>
        <?php $ocpFieldPrefix = 'cp'; require __DIR__ . '/../partials/cart_promo_schedule_fields.inc.php'; ?>
        <div style="grid-column:1/-1;display:flex;flex-wrap:wrap;gap:20px;align-items:center;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="cp_reg"> <strong>للمسجّلين فقط</strong>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="cp_first_delivered"> <strong>أول طلب مُسلَّم</strong>
            </label>
        </div>
    </div>
    <div class="admin-form-actions">
        <button type="button" onclick="saveCartPromotion()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ</button>
        <button type="button" class="btn-secondary" onclick="resetCartPromotionForm()">جديد</button>
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
                    <th>خصم</th>
                    <th>نطاق العرض</th>
                    <th>الفترة</th>
                    <th>الحالة</th>
                    <th>ترتيب</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="cp_tbody"></tbody>
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
            <tbody id="cp_history_tbody">
                <tr><td colspan="6" class="muted">جارٍ التحميل...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
<?php require __DIR__ . '/../partials/cart_promo_schedule_js.inc.php'; ?>
var cpNameArTimer = null;
var cpNameEnTimer = null;

function resetCartPromotionForm() {
    document.getElementById('cp_id').value = '0';
    document.getElementById('cp_name_ar').value = '';
    document.getElementById('cp_name_en').value = '';
    document.getElementById('cp_show_name').checked = false;
    document.getElementById('cp_min').value = '';
    document.getElementById('cp_disc').value = '';
    document.getElementById('cp_sort').value = '0';
    document.getElementById('cp_reg').checked = false;
    document.getElementById('cp_first_delivered').checked = false;
    document.getElementById('cp_active').checked = true;
    ocpSetAlwaysOn('cp', false);
    ocpDefaultScheduleDates('cp');
}

function editCartPromotion(row) {
    document.getElementById('cp_id').value = String(row.id != null ? row.id : 0);
    document.getElementById('cp_name_ar').value = row.name_ar != null ? String(row.name_ar) : '';
    document.getElementById('cp_name_en').value = row.name_en != null ? String(row.name_en) : '';
    document.getElementById('cp_show_name').checked = parseInt(row.show_name_to_customer, 10) === 1;
    document.getElementById('cp_min').value = row.min_subtotal != null ? String(row.min_subtotal) : '';
    document.getElementById('cp_disc').value = row.discount_amount != null ? String(row.discount_amount) : '';
    document.getElementById('cp_sort').value = String(row.sort_order != null ? row.sort_order : 0);
    document.getElementById('cp_reg').checked = parseInt(row.requires_registered_account, 10) === 1;
    document.getElementById('cp_first_delivered').checked = parseInt(row.first_delivered_order_only, 10) === 1;
    document.getElementById('cp_active').checked = parseInt(row.is_active, 10) === 1;
    ocpSetAlwaysOn('cp', parseInt(row.is_always_on, 10) === 1);
    ocpSetDmyFromIso('cp_valid_from', row.valid_from);
    ocpSetDmyFromIso('cp_valid_to', row.valid_to);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function escCp(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;');
}

function cpRowName(row) {
    var ar = (row.name_ar != null ? String(row.name_ar) : '').trim();
    if (ar !== '') return ar;
    var en = (row.name_en != null ? String(row.name_en) : '').trim();
    return en !== '' ? en : '—';
}

async function cpTranslateNames(opts) {
    var silent = !!(opts && opts.silent);
    var forceFromArabic = !!(opts && opts.forceFromArabic);
    var arEl = document.getElementById('cp_name_ar');
    var enEl = document.getElementById('cp_name_en');
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

function cpScheduleNameFromAr() {
    var arEl = document.getElementById('cp_name_ar');
    if (!arEl) return;
    clearTimeout(cpNameArTimer);
    cpNameArTimer = setTimeout(function () {
        cpTranslateNames({ silent: true, forceFromArabic: true });
    }, 700);
}

function cpScheduleNameFromEn() {
    var enEl = document.getElementById('cp_name_en');
    if (!enEl || enEl.value.trim() === '') return;
    clearTimeout(cpNameEnTimer);
    cpNameEnTimer = setTimeout(function () {
        cpTranslateNames({ silent: true, forceFromArabic: false });
    }, 600);
}

async function cpTranslateOfferFromAr() {
    await cpTranslateNames({ silent: false, forceFromArabic: true });
}
window.cpTranslateOfferFromAr = cpTranslateOfferFromAr;

async function loadCartPromotions() {
    const res = await postJSON('/admin/api/cart_promotions/manage.php', { action: 'list' });
    if (!res.success) {
        alert(res.message || 'خطأ');
        return;
    }
    const rows = res.data || [];
    const tb = document.getElementById('cp_tbody');
    tb.innerHTML = '';
    rows.forEach(function (r) {
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + escCp(String(r.id)) + '</td>' +
            '<td>' + escCp(cpRowName(r)) + '</td>' +
            '<td dir="ltr">' + escCp(String(r.min_subtotal)) + '</td>' +
            '<td dir="ltr">' + escCp(String(r.discount_amount)) + '</td>' +
            '<td>' + (parseInt(r.requires_registered_account, 10) === 1 ? 'مسجّل فقط' : 'جميع الزوّار') + (parseInt(r.first_delivered_order_only, 10) === 1 ? ' • أول طلب مُسلَّم' : '') + '</td>' +
            '<td dir="ltr">' + escCp(ocpScheduleLabel(r)) + '</td>' +
            '<td>' + escCp(ocpStatusLabel(r)) + '</td>' +
            '<td>' + escCp(String(r.sort_order)) + '</td>' +
            '<td><button type="button" class="btn-secondary" data-cp-edit="' + escCp(String(r.id)) + '">تعديل</button></td>';
        tb.appendChild(tr);
    });
    tb.querySelectorAll('[data-cp-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = parseInt(btn.getAttribute('data-cp-edit'), 10);
            const row = rows.find(function (x) { return parseInt(x.id, 10) === id; });
            if (row) editCartPromotion(row);
        });
    });
}

async function saveCartPromotion() {
    const res = await postJSON('/admin/api/cart_promotions/manage.php', {
        action: 'save',
        id: parseInt(document.getElementById('cp_id').value, 10) || 0,
        name_ar: document.getElementById('cp_name_ar').value.trim(),
        name_en: document.getElementById('cp_name_en').value.trim(),
        show_name_to_customer: document.getElementById('cp_show_name').checked ? 1 : 0,
        min_subtotal: document.getElementById('cp_min').value.trim(),
        discount_amount: document.getElementById('cp_disc').value.trim(),
        requires_registered_account: document.getElementById('cp_reg').checked ? 1 : 0,
        first_delivered_order_only: document.getElementById('cp_first_delivered').checked ? 1 : 0,
        is_active: document.getElementById('cp_active').checked ? 1 : 0,
        is_always_on: ocpIsAlwaysOn('cp') ? 1 : 0,
        valid_from: ocpGetIso('cp_valid_from'),
        valid_to: ocpGetIso('cp_valid_to')
    });
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
    if (res.success) {
        resetCartPromotionForm();
        loadCartPromotions();
        loadCartPromotionAlwaysOnHistory();
    }
}

function cpAdminName(name) {
    var s = String(name || '').trim();
    return s !== '' ? s : '—';
}

async function loadCartPromotionAlwaysOnHistory() {
    var tb = document.getElementById('cp_history_tbody');
    if (!tb) return;
    var res = await postJSON('/admin/api/cart_promotions/manage.php', { action: 'always_on_history' });
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
            '<td>' + escCp(String(row.id || '')) + '</td>' +
            '<td>عرض #' + escCp(String(row.promotion_id || '')) + '</td>' +
            '<td dir="ltr">' + escCp(String(row.started_at || '')) + '</td>' +
            '<td dir="ltr">' + escCp(String(row.ended_at || '')) + '</td>' +
            '<td>' + escCp(cpAdminName(row.started_by_name)) + '</td>' +
            '<td>' + escCp(cpAdminName(row.ended_by_name)) + '</td>';
        tb.appendChild(tr);
    });
}

(function () {
    var arEl = document.getElementById('cp_name_ar');
    var enEl = document.getElementById('cp_name_en');
    if (arEl) arEl.addEventListener('input', cpScheduleNameFromAr);
    if (enEl) enEl.addEventListener('input', cpScheduleNameFromEn);
})();
ocpBindAlwaysOn('cp');
ocpDefaultScheduleDates('cp');
loadCartPromotions();
loadCartPromotionAlwaysOnHistory();
</script>
