<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/cart_promo_products.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$hasTable = orange_table_exists($pdo, 'cart_gift_promotions');
$cgpPickRows = orange_cart_promo_admin_product_rows($pdo);
$cgpPickJson = json_encode($cgpPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);
?>
<div class="page-title page-title--stacked">
    <h1>عروض الهدايا</h1>
    <p class="page-subtitle">هدية تلقائية عند حد أدنى لمجموع السلة — منتج كامل في الأدمن؛ العميل يختار اللون/المقاس عند الدفع.</p>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>cart_gift_promotions</code> غير جاهز.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل قاعدة</h3>
    <input type="hidden" id="cgp_id" value="0">
    <div class="ocp-form">
        <section class="ocp-section">
            <h4 class="ocp-section__title">١ — شرط مجموع السلة</h4>
            <div class="ocp-section__body ocp-meta-row">
                <div>
                    <label>الحد الأدنى (د.ك)</label>
                    <input type="text" id="cgp_min" class="admin-inp-money" inputmode="decimal" lang="en" dir="ltr" placeholder="0">
                    <p class="card-hint" style="margin:4px 0 0;">0 = بدون شرط مبلغ.</p>
                </div>
                <div>
                    <label>الترتيب</label>
                    <input type="number" id="cgp_sort" value="0" class="admin-inp" min="0" step="1">
                </div>
            </div>
        </section>
        <section class="ocp-section">
            <h4 class="ocp-section__title">٢ — المنتج المهدى</h4>
            <div class="ocp-section__body">
                <div class="ocp-choices">
                    <label class="ocp-choice">
                        <input type="radio" name="cgp_kind" value="choice" checked onchange="cgpToggleKind()">
                        <span>اختيار من مجموعة منتجات</span>
                    </label>
                    <label class="ocp-choice">
                        <input type="radio" name="cgp_kind" value="fixed" onchange="cgpToggleKind()">
                        <span>هدية ثابتة (منتج واحد)</span>
                    </label>
                </div>
                <div id="cgp_block_pool" class="ocp-product-panel">
                    <div class="ocp-toolbar">
                        <button type="button" class="btn-secondary" id="cgp_pool_add_btn">+ إضافة منتج</button>
                        <span class="card-hint">نقرتان للاختيار — يظهر للعميل كل ألوان/مقاسات المنتجات المضافة.</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>كود</th><th>المنتج</th><th></th></tr></thead>
                            <tbody id="cgp_pool_body"></tbody>
                        </table>
                    </div>
                </div>
                <div id="cgp_block_fixed" class="ocp-panel" style="display:none;">
                    <div class="ocp-fixed-pick">
                        <button type="button" class="btn-secondary" id="cgp_fixed_pick_btn">اختيار منتج</button>
                        <p id="cgp_fixed_label" class="ocp-fixed-pick__label">— لم يُختر منتج —</p>
                    </div>
                    <input type="hidden" id="cgp_fixed_pid" value="0">
                </div>
            </div>
        </section>
        <section class="ocp-section">
            <h4 class="ocp-section__title">٣ — تسعير بند الهدية</h4>
            <div class="ocp-section__body ocp-meta-row">
                <div style="grid-column:1/-1;">
                    <label for="cgp_gift_charge_kind">نوع التسعير</label>
                    <select id="cgp_gift_charge_kind" class="admin-inp" onchange="cgpToggleGiftCharge()">
                        <option value="free">مجانية بالكامل</option>
                        <option value="percent_off">خصم نسبة من التجزئة</option>
                        <option value="amount_off_unit">خصم مبلغ من التجزئة للوحدة</option>
                        <option value="fixed_unit">سعر بيع ثابت للوحدة (د.ك)</option>
                    </select>
                </div>
                <div id="cgp_gift_charge_val_wrap" style="display:none;">
                    <label id="cgp_gift_charge_val_label">القيمة</label>
                    <input type="number" id="cgp_gift_charge_val" class="admin-inp" min="0" step="0.0001" dir="ltr" value="0">
                </div>
            </div>
        </section>
        <section class="ocp-section">
            <h4 class="ocp-section__title">٤ — النطاق والحالة</h4>
            <div class="ocp-section__body ocp-flags">
                <label class="ocp-flag">
                    <input type="checkbox" id="cgp_reg">
                    <span><strong>للمسجّلين فقط</strong></span>
                </label>
                <label class="ocp-flag">
                    <input type="checkbox" id="cgp_active" checked>
                    <span><strong>نشط</strong></span>
                </label>
            </div>
        </section>
    </div>
    <div class="admin-form-actions">
        <button type="button" onclick="saveCartGiftPromotion()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ</button>
        <button type="button" class="btn-secondary" onclick="resetCartGiftPromotionForm()">قاعدة جديدة</button>
    </div>
</div>

<div class="card ocp-list-card">
    <h3>القواعد المحفوظة</h3>
    <p class="card-hint">عند تعدّد القواعد يُختار أعلى حد أدنى يحقق مجموع السلة.</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>حد أدنى</th>
                    <th>النوع</th>
                    <th>التفاصيل</th>
                    <th>تسعير</th>
                    <th>نطاق</th>
                    <th>ترتيب</th>
                    <th>نشط</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="cgp_tbody"></tbody>
        </table>
    </div>
</div>

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin_cart_promo_product_pick.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
var CGP_PICK_ROWS = <?php echo $cgpPickJson !== false ? $cgpPickJson : '[]'; ?>;

function ocpEmptyRow(tb, cols, msg) {
    if (!tb) return;
    if (tb.querySelector('tr[data-product-id], tr[data-ocp-empty]')) return;
    var tr = document.createElement('tr');
    tr.setAttribute('data-ocp-empty', '1');
    tr.innerHTML = '<td colspan="' + cols + '" class="ocp-empty-row">' + msg + '</td>';
    tb.appendChild(tr);
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
    tb.querySelectorAll('tr[data-product-id]').forEach(function (tr) {
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
    tb.querySelectorAll('tr[data-product-id]').forEach(function (tr) {
        if (parseInt(tr.getAttribute('data-product-id'), 10) === id) dup = true;
    });
    if (dup) return;
    var empty = tb.querySelector('tr[data-ocp-empty]');
    if (empty) empty.remove();
    var m = cgpPickMeta(id);
    var tr = document.createElement('tr');
    tr.setAttribute('data-product-id', String(id));
    tr.innerHTML =
        '<td dir="ltr">' + (m.code ? String(m.code) : ('P' + id)) + '</td>' +
        '<td>' + (m.name ? String(m.name) : '') + '</td>' +
        '<td><button type="button" class="btn-secondary cgp-rm">&times;</button></td>';
    tr.querySelector('.cgp-rm').addEventListener('click', function () {
        tr.remove();
        if (!tb.querySelector('tr[data-product-id]')) {
            ocpEmptyRow(tb, 3, 'لا منتجات — اضغط «إضافة منتج»');
        }
    });
    tb.appendChild(tr);
}

function cgpRenderPool(ids) {
    var tb = document.getElementById('cgp_pool_body');
    if (!tb) return;
    tb.innerHTML = '';
    (ids || []).forEach(function (pid) { cgpAddPoolRow(pid); });
    if (!tb.querySelector('tr[data-product-id]')) {
        ocpEmptyRow(tb, 3, 'لا منتجات — اضغط «إضافة منتج»');
    }
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
        lab.textContent = 'المبلغ المخصوم من التجزئة (د.ك)';
        inp.removeAttribute('max');
        inp.step = '0.0001';
    }
}

function resetCartGiftPromotionForm() {
    document.getElementById('cgp_id').value = '0';
    document.getElementById('cgp_min').value = '';
    document.getElementById('cgp_sort').value = '0';
    cgpRenderPool([]);
    cgpSetFixed(0);
    document.querySelector('input[name="cgp_kind"][value="choice"]').checked = true;
    document.getElementById('cgp_reg').checked = false;
    document.getElementById('cgp_active').checked = true;
    document.getElementById('cgp_gift_charge_kind').value = 'free';
    document.getElementById('cgp_gift_charge_val').value = '0';
    cgpToggleKind();
    cgpToggleGiftCharge();
}

function editCartGiftPromotion(row) {
    document.getElementById('cgp_id').value = String(row.id != null ? row.id : 0);
    document.getElementById('cgp_min').value = row.min_subtotal != null ? String(row.min_subtotal) : '';
    document.getElementById('cgp_sort').value = String(row.sort_order != null ? row.sort_order : 0);
    const kind = (row.gift_kind || 'choice') === 'fixed' ? 'fixed' : 'choice';
    document.querySelector('input[name="cgp_kind"][value="' + kind + '"]').checked = true;
    cgpToggleKind();
    cgpRenderPool(row.pool_product_ids || row.pool_variant_ids || []);
    cgpSetFixed(row.fixed_product_id || row.fixed_variant_id || 0);
    document.getElementById('cgp_reg').checked = parseInt(row.requires_registered_account, 10) === 1;
    document.getElementById('cgp_active').checked = parseInt(row.is_active, 10) === 1;
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

async function loadCartGiftPromotions() {
    const res = await postJSON('/admin/api/cart_gift_promotions/manage.php', { action: 'list' });
    if (!res.success) {
        alert(res.message || 'خطأ');
        return;
    }
    const rows = res.data || [];
    const tb = document.getElementById('cgp_tbody');
    tb.innerHTML = '';
    rows.forEach(function (r) {
        const kind = (r.gift_kind || 'choice') === 'fixed' ? 'ثابتة' : 'اختيار';
        let det = '';
        if ((r.gift_kind || '') === 'fixed') {
            det = escCgp(cgpFmtPoolIds([r.fixed_product_id || r.fixed_variant_id].filter(function (x) { return x > 0; })));
        } else {
            det = escCgp(cgpFmtPoolIds(r.pool_product_ids || r.pool_variant_ids || []));
        }
        var gcharge = 'مجانية';
        var gck = (r.gift_unit_charge_kind || 'free').toLowerCase();
        if (gck === 'percent_off') gcharge = 'خصم %';
        else if (gck === 'fixed_unit') gcharge = 'سعر ثابت';
        else if (gck === 'amount_off_unit') gcharge = 'خصم مبلغ';
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + escCgp(String(r.id)) + '</td>' +
            '<td dir="ltr">' + escCgp(String(r.min_subtotal)) + '</td>' +
            '<td>' + kind + '</td>' +
            '<td style="max-width:18rem;">' + det + '</td>' +
            '<td>' + escCgp(gcharge) + '</td>' +
            '<td>' + (parseInt(r.requires_registered_account, 10) === 1 ? 'مسجّل فقط' : 'جميع الزوّار') + '</td>' +
            '<td>' + escCgp(String(r.sort_order)) + '</td>' +
            '<td>' + (parseInt(r.is_active, 10) === 1 ? 'نعم' : 'لا') + '</td>' +
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
}

async function saveCartGiftPromotion() {
    const kindEl = document.querySelector('input[name="cgp_kind"]:checked');
    const res = await postJSON('/admin/api/cart_gift_promotions/manage.php', {
        action: 'save',
        id: parseInt(document.getElementById('cgp_id').value, 10) || 0,
        min_subtotal: document.getElementById('cgp_min').value.trim(),
        sort_order: parseInt(document.getElementById('cgp_sort').value, 10) || 0,
        requires_registered_account: document.getElementById('cgp_reg').checked ? 1 : 0,
        is_active: document.getElementById('cgp_active').checked ? 1 : 0,
        gift_kind: kindEl ? kindEl.value : 'choice',
        fixed_product_id: parseInt(document.getElementById('cgp_fixed_pid').value, 10) || 0,
        pool_product_ids: cgpPoolRows(),
        gift_unit_charge_kind: document.getElementById('cgp_gift_charge_kind').value,
        gift_unit_charge_value: parseFloat(document.getElementById('cgp_gift_charge_val').value) || 0
    });
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
    if (res.success) {
        resetCartGiftPromotionForm();
        loadCartGiftPromotions();
    }
}

document.getElementById('cgp_pool_add_btn').addEventListener('click', function () {
    cgpOpenPick(function (row) { cgpAddPoolRow(row.product_id); });
});
document.getElementById('cgp_fixed_pick_btn').addEventListener('click', function () {
    cgpOpenPick(function (row) { cgpSetFixed(row.product_id); });
});
cgpToggleKind();
cgpToggleGiftCharge();
cgpRenderPool([]);
loadCartGiftPromotions();
</script>
