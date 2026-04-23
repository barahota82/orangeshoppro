<?php

declare(strict_types=1);

$pdo = db();
orange_catalog_ensure_schema($pdo);
$hasTable = orange_table_exists($pdo, 'cart_combo_promotions');
?>
<div class="page-title page-title--stacked">
    <h1>عروض الكومبو (حزمة متغيرات)</h1>
    <p class="page-subtitle">عند وجود <strong>كل</strong> المكوّنات في العربة بالكميات المطلوبة، يُحسب للعميل سعر الحزمة <code>combo_price</code> بدلاً من مجموع أسعار القطع بالتجزئة (أقصى توفير يُختار تلقائياً عند تعدّد القواعد النشطة).
        <strong>النطاق:</strong> للجميع أو <strong>للمسجّلين فقط</strong> لكل قاعدة.</p>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>cart_combo_promotions</code> غير جاهز.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل</h3>
    <input type="hidden" id="ccp_id" value="0">
    <div class="form-grid">
        <div style="grid-column:1/-1;"><label>عنوان داخلي (عربي) — اختياري</label><input type="text" id="ccp_title_ar" class="admin-inp" style="max-width:40rem;"></div>
        <div style="grid-column:1/-1;"><label>عنوان داخلي (إنجليزي) — اختياري</label><input type="text" id="ccp_title_en" class="admin-inp" style="max-width:40rem;" dir="ltr" lang="en"></div>
        <div><label>سعر الحزمة الواحدة (د.ك)</label><input type="text" id="ccp_price" class="admin-inp-money" inputmode="decimal" lang="en" dir="ltr" placeholder="9.5"></div>
        <div><label>الترتيب</label><input type="number" id="ccp_sort" value="0" style="max-width:120px;"></div>
        <div style="grid-column:1/-1;">
            <label>المكوّنات — سطر لكل متغير: <code dir="ltr">variant_id qty</code> أو <code dir="ltr">variant_id, qty</code> (متغيران مختلفان على الأقل)</label>
            <div style="margin:6px 0 8px;">
                <button type="button" class="btn-secondary" onclick="orangeOpenVariantPicker({ mode: 'lines', targetId: 'ccp_comp' })">اختيار بصري — إضافة سطر (متغير + كمية)</button>
            </div>
            <textarea id="ccp_comp" rows="5" class="admin-inp" dir="ltr" style="width:100%;max-width:40rem;font-family:monospace;" placeholder="101 1&#10;205 1"></textarea>
        </div>
        <div style="grid-column:1/-1;">
            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;max-width:52rem;line-height:1.45;">
                <input type="checkbox" id="ccp_reg" style="margin-top:4px;flex-shrink:0;">
                <span><strong>للمسجّلين فقط</strong> — لا يُطبَّق إلا لحساب مفعّل (بريد مؤكد).</span>
            </label>
        </div>
        <div style="display:flex;align-items:flex-end;gap:8px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="ccp_active" checked> نشط
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
                    <th>عنوان</th>
                    <th>المكوّنات</th>
                    <th>سعر الحزمة</th>
                    <th>نطاق</th>
                    <th>ترتيب</th>
                    <th>نشط</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="ccp_tbody"></tbody>
        </table>
    </div>
</div>

<script>
function ccpFmtComps(comps) {
    if (!comps || !comps.length) return '—';
    return comps.map(function (c) {
        return String(c.variant_id) + '×' + String(c.qty);
    }).join(' + ');
}

function resetCartComboPromotionForm() {
    document.getElementById('ccp_id').value = '0';
    document.getElementById('ccp_title_ar').value = '';
    document.getElementById('ccp_title_en').value = '';
    document.getElementById('ccp_price').value = '';
    document.getElementById('ccp_sort').value = '0';
    document.getElementById('ccp_comp').value = '';
    document.getElementById('ccp_reg').checked = false;
    document.getElementById('ccp_active').checked = true;
}

function editCartComboPromotion(row) {
    document.getElementById('ccp_id').value = String(row.id != null ? row.id : 0);
    document.getElementById('ccp_title_ar').value = row.title_ar != null ? String(row.title_ar) : '';
    document.getElementById('ccp_title_en').value = row.title_en != null ? String(row.title_en) : '';
    document.getElementById('ccp_price').value = row.combo_price != null ? String(row.combo_price) : '';
    document.getElementById('ccp_sort').value = String(row.sort_order != null ? row.sort_order : 0);
    document.getElementById('ccp_reg').checked = parseInt(row.requires_registered_account, 10) === 1;
    document.getElementById('ccp_active').checked = parseInt(row.is_active, 10) === 1;
    var lines = [];
    (row.components || []).forEach(function (c) {
        lines.push(String(c.variant_id) + ' ' + String(c.qty));
    });
    document.getElementById('ccp_comp').value = lines.join('\n');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function escCcp(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;');
}

async function loadCartComboPromotions() {
    var res = await postJSON('/admin/api/cart_combo_promotions/manage.php', { action: 'list' });
    var tb = document.getElementById('ccp_tbody');
    if (!res.success || !Array.isArray(res.data)) {
        tb.innerHTML = '<tr><td colspan="8">تعذر التحميل</td></tr>';
        return;
    }
    var rows = res.data;
    tb.innerHTML = '';
    rows.forEach(function (r) {
        var title = (r.title_ar && String(r.title_ar).trim()) ? escCcp(String(r.title_ar)) : ('#' + r.id);
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + escCcp(String(r.id)) + '</td>' +
            '<td>' + title + '</td>' +
            '<td dir="ltr" style="font-family:monospace;font-size:0.85rem;">' + escCcp(ccpFmtComps(r.components)) + '</td>' +
            '<td dir="ltr">' + escCcp(String(r.combo_price)) + '</td>' +
            '<td>' + (parseInt(r.requires_registered_account, 10) === 1 ? 'مسجّل فقط' : 'الكل') + '</td>' +
            '<td>' + escCcp(String(r.sort_order)) + '</td>' +
            '<td>' + (parseInt(r.is_active, 10) === 1 ? 'نعم' : 'لا') + '</td>' +
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
        combo_price: document.getElementById('ccp_price').value,
        sort_order: parseInt(document.getElementById('ccp_sort').value, 10) || 0,
        requires_registered_account: document.getElementById('ccp_reg').checked ? 1 : 0,
        is_active: document.getElementById('ccp_active').checked ? 1 : 0,
        components_text: document.getElementById('ccp_comp').value
    });
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
    if (res.success) {
        resetCartComboPromotionForm();
        loadCartComboPromotions();
    }
}

loadCartComboPromotions();
</script>
