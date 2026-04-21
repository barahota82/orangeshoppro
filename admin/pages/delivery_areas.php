<?php

declare(strict_types=1);

$pdo = db();
orange_catalog_ensure_schema($pdo);
$hasTable = orange_table_exists($pdo, 'delivery_areas');
?>
<div class="page-title page-title--stacked">
    <h1>مناطق التوصيل</h1>
    <p class="page-subtitle">قائمة اختيار للعملاء في العربة والتسجيل والتتبع (سياسة س8). العربية للعرض في الواجهة العربية؛ الإنجليزية للغات الأخرى.</p>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>delivery_areas</code> غير جاهز.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل منطقة</h3>
    <input type="hidden" id="da_id" value="0">
    <div class="form-grid">
        <div><label>الاسم العربي</label><input type="text" id="da_name_ar" maxlength="191" autocomplete="off"></div>
        <div><label>English</label><input type="text" id="da_name_en" maxlength="191" autocomplete="off" lang="en" dir="ltr"></div>
        <div><label>الترتيب</label><input type="number" id="da_sort_order" value="0" style="max-width:120px;"></div>
        <div style="display:flex;align-items:flex-end;gap:8px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="da_is_active" checked> نشط
            </label>
        </div>
    </div>
    <div class="admin-form-actions" style="display:flex;flex-wrap:wrap;gap:10px;">
        <button type="button" onclick="saveDeliveryArea()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ</button>
        <button type="button" class="btn-secondary" onclick="translateDeliveryAreaFromAr()">ترجمة تلقائية من العربي</button>
        <button type="button" class="btn-secondary" onclick="resetDeliveryAreaForm()">جديد</button>
    </div>
</div>

<div class="card">
    <h3>القائمة</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>عربي</th>
                    <th>English</th>
                    <th>ترتيب</th>
                    <th>نشط</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="da_tbody"></tbody>
        </table>
    </div>
</div>

<script>
let daArTimer = null;
let daEnTimer = null;

function resetDeliveryAreaForm() {
    document.getElementById('da_id').value = '0';
    document.getElementById('da_name_ar').value = '';
    document.getElementById('da_name_en').value = '';
    document.getElementById('da_sort_order').value = '0';
    document.getElementById('da_is_active').checked = true;
}

async function translateDeliveryArea(opts) {
    const silent = !!(opts && opts.silent);
    const forceFromArabic = !!(opts && opts.forceFromArabic);
    try {
        const payload = {
            name_ar: document.getElementById('da_name_ar').value.trim(),
            name_en: forceFromArabic ? '' : document.getElementById('da_name_en').value.trim()
        };
        const res = await postJSON('/admin/api/translate/names.php', payload);
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        const t = res.translations || {};
        if (t.name_en) document.getElementById('da_name_en').value = t.name_en;
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة');
    }
}

function scheduleDaFromAr() {
    const ar = document.getElementById('da_name_ar').value.trim();
    if (!ar) {
        document.getElementById('da_name_en').value = '';
        return;
    }
    clearTimeout(daArTimer);
    daArTimer = setTimeout(function () { translateDeliveryArea({ silent: true, forceFromArabic: true }); }, 700);
}

function scheduleDaFromEn() {
    const en = document.getElementById('da_name_en').value.trim();
    if (!en) return;
    clearTimeout(daEnTimer);
    daEnTimer = setTimeout(function () { translateDeliveryArea({ silent: true, forceFromArabic: false }); }, 600);
}

async function translateDeliveryAreaFromAr() {
    await translateDeliveryArea({ silent: false, forceFromArabic: true });
}

document.getElementById('da_name_ar').addEventListener('input', scheduleDaFromAr);
document.getElementById('da_name_en').addEventListener('input', scheduleDaFromEn);

function editDeliveryArea(row) {
    document.getElementById('da_id').value = String(row.id != null ? row.id : 0);
    document.getElementById('da_name_ar').value = row.name_ar || '';
    document.getElementById('da_name_en').value = row.name_en || '';
    document.getElementById('da_sort_order').value = String(row.sort_order != null ? row.sort_order : 0);
    document.getElementById('da_is_active').checked = parseInt(row.is_active, 10) === 1;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function loadDeliveryAreas() {
    const res = await postJSON('/admin/api/delivery_areas/manage.php', { action: 'list' });
    if (!res.success) {
        alert(res.message || 'خطأ');
        return;
    }
    const rows = res.data || [];
    const tb = document.getElementById('da_tbody');
    tb.innerHTML = '';
    rows.forEach(function (r) {
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + escHtml(String(r.id)) + '</td>' +
            '<td>' + escHtml(String(r.name_ar || '')) + '</td>' +
            '<td dir="ltr">' + escHtml(String(r.name_en || '')) + '</td>' +
            '<td>' + escHtml(String(r.sort_order != null ? r.sort_order : '')) + '</td>' +
            '<td>' + (parseInt(r.is_active, 10) === 1 ? 'نعم' : 'لا') + '</td>' +
            '<td><button type="button" class="btn-secondary" data-da-edit="' + escAttr(String(r.id)) + '">تعديل</button></td>';
        tb.appendChild(tr);
    });
    tb.querySelectorAll('[data-da-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = parseInt(btn.getAttribute('data-da-edit'), 10);
            const row = rows.find(function (x) { return parseInt(x.id, 10) === id; });
            if (row) editDeliveryArea(row);
        });
    });
}

function escHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;');
}
function escAttr(s) {
    return String(s).replace(/"/g, '&quot;');
}

async function saveDeliveryArea() {
    const res = await postJSON('/admin/api/delivery_areas/manage.php', {
        action: 'save',
        id: parseInt(document.getElementById('da_id').value, 10) || 0,
        name_ar: document.getElementById('da_name_ar').value.trim(),
        name_en: document.getElementById('da_name_en').value.trim(),
        sort_order: parseInt(document.getElementById('da_sort_order').value, 10) || 0,
        is_active: document.getElementById('da_is_active').checked ? 1 : 0
    });
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
    if (res.success) {
        resetDeliveryAreaForm();
        loadDeliveryAreas();
    }
}

loadDeliveryAreas();
</script>
