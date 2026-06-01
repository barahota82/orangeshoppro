<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/cart_promo_products.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$hasTable = orange_table_exists($pdo, 'cart_combo_promotions');
$ccpPickRows = orange_cart_promo_admin_product_rows($pdo);
$ccpPickJson = json_encode($ccpPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);
?>
<div class="page-title page-title--stacked">
    <h1>عروض الكومبو</h1>
    <p class="page-subtitle">حزمة منتجات بأي لون أو مقاس — سعر ثابت للحزمة عند توفّر الكميات في العربة.</p>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>cart_combo_promotions</code> غير جاهز.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل قاعدة</h3>
    <input type="hidden" id="ccp_id" value="0">
    <div class="ocp-form">
        <section class="ocp-section">
            <h4 class="ocp-section__title">١ — عناوين داخلية (اختياري)</h4>
            <div class="ocp-section__body ocp-meta-row">
                <div style="grid-column:1/-1;">
                    <label>عنوان (عربي)</label>
                    <input type="text" id="ccp_title_ar" class="admin-inp">
                </div>
                <div style="grid-column:1/-1;">
                    <label>عنوان (إنجليزي)</label>
                    <input type="text" id="ccp_title_en" class="admin-inp" dir="ltr" lang="en">
                </div>
            </div>
        </section>
        <section class="ocp-section">
            <h4 class="ocp-section__title">٢ — الحزمة والمنتجات</h4>
            <div class="ocp-section__body">
                <div class="ocp-meta-row">
                    <div>
                        <label>سعر الحزمة الواحدة (د.ك)</label>
                        <input type="text" id="ccp_price" class="admin-inp-money" inputmode="decimal" lang="en" dir="ltr" placeholder="9.5">
                    </div>
                </div>
                <div class="ocp-product-panel">
                    <div class="ocp-toolbar">
                        <button type="button" class="btn-secondary" id="ccp_add_product_btn">+ إضافة منتج</button>
                        <span class="card-hint">نقرتان على المنتج في القائمة — أي لون أو مقاس يُحسب ضمن الحزمة.</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>كود</th><th>المنتج</th><th>الكمية</th><th></th></tr></thead>
                            <tbody id="ccp_comp_body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
        <section class="ocp-section">
            <h4 class="ocp-section__title">٣ — الترتيب والنطاق</h4>
            <div class="ocp-section__body">
                <div class="ocp-meta-row">
                    <div>
                        <label>الترتيب</label>
                        <input type="number" id="ccp_sort" value="0" class="admin-inp" min="0" step="1">
                    </div>
                </div>
                <div class="ocp-flags">
                    <label class="ocp-flag">
                        <input type="checkbox" id="ccp_reg">
                        <span><strong>للمسجّلين فقط</strong> — حساب مفعّل (بريد مؤكد).</span>
                    </label>
                    <label class="ocp-flag">
                        <input type="checkbox" id="ccp_active" checked>
                        <span><strong>نشط</strong></span>
                    </label>
                </div>
            </div>
        </section>
    </div>
    <div class="admin-form-actions">
        <button type="button" onclick="saveCartComboPromotion()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ</button>
        <button type="button" class="btn-secondary" onclick="resetCartComboPromotionForm()">قاعدة جديدة</button>
    </div>
</div>

<div class="card ocp-list-card">
    <h3>القواعد المحفوظة</h3>
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

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin_cart_promo_product_pick.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
var CCP_PICK_ROWS = <?php echo $ccpPickJson !== false ? $ccpPickJson : '[]'; ?>;

function ocpEmptyRow(tb, cols, msg) {
    if (!tb) return;
    if (tb.querySelector('tr[data-product-id], tr[data-ocp-empty]')) return;
    var tr = document.createElement('tr');
    tr.setAttribute('data-ocp-empty', '1');
    tr.innerHTML = '<td colspan="' + cols + '" class="ocp-empty-row">' + msg + '</td>';
    tb.appendChild(tr);
}

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
    tb.querySelectorAll('tr[data-product-id]').forEach(function (tr) {
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
    (comps || []).forEach(function (c) { ccpAddCompRow(c); });
    if (!tb.querySelector('tr[data-product-id]')) {
        ocpEmptyRow(tb, 4, 'لا منتجات — اضغط «إضافة منتج»');
    }
}

function ccpAddCompRow(c) {
    var tb = document.getElementById('ccp_comp_body');
    if (!tb) return;
    var empty = tb.querySelector('tr[data-ocp-empty]');
    if (empty) empty.remove();
    var pid = parseInt(c.product_id, 10) || 0;
    var tr = document.createElement('tr');
    tr.setAttribute('data-product-id', String(pid));
    tr.innerHTML =
        '<td dir="ltr">' + (c.code ? String(c.code) : ('P' + pid)) + '</td>' +
        '<td>' + (c.product_name ? String(c.product_name) : '') + '</td>' +
        '<td><input type="number" class="ccp-qty admin-inp-qty" min="1" step="1" value="' + (parseInt(c.qty, 10) || 1) + '" style="width:5rem;"></td>' +
        '<td><button type="button" class="btn-secondary ccp-rm">&times;</button></td>';
    tr.querySelector('.ccp-rm').addEventListener('click', function () {
        tr.remove();
        if (!tb.querySelector('tr[data-product-id]')) {
            ocpEmptyRow(tb, 4, 'لا منتجات — اضغط «إضافة منتج»');
        }
    });
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
    document.getElementById('ccp_price').value = '';
    document.getElementById('ccp_sort').value = '0';
    ccpRenderComps([]);
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
    ccpRenderComps(row.components || []);
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
            '<td dir="ltr" style="font-size:0.85rem;">' + escCcp(ccpFmtComps(r.components)) + '</td>' +
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
            if (row) editCartComboPromotion(row);
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
        components: ccpCompRows()
    });
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
    if (res.success) {
        resetCartComboPromotionForm();
        loadCartComboPromotions();
    }
}

document.getElementById('ccp_add_product_btn').addEventListener('click', ccpOpenPick);
ccpRenderComps([]);
loadCartComboPromotions();
</script>
