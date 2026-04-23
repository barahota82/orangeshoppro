<?php

declare(strict_types=1);

$pdo = db();
orange_catalog_ensure_schema($pdo);
$hasTable = orange_table_exists($pdo, 'cart_gift_promotions');
?>
<div class="page-title page-title--stacked">
    <h1>عروض الهدايا (مجموعة اختيار / هدية ثابتة)</h1>
    <p class="page-subtitle">تكميل <strong>س4</strong>: عند تحقق حد أدنى لمجموع السلة (يمكن أن يكون 0) يُضاف بند هدية بسعر صفر مع حجز المخزون مثل باقي البنود.
        يعمل بجانب «عروض مجموع السلة» (خصم مبلغ). <strong>نوع العرض:</strong> إما <em>هدية ثابتة</em> (رقم متغير واحد) أو <em>اختيار من مجموعة</em> (قائمة أرقام متغيرات يختار منها العميل في العربة).</p>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>cart_gift_promotions</code> غير جاهز.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل</h3>
    <input type="hidden" id="cgp_id" value="0">
    <div class="form-grid">
        <div><label>الحد الأدنى لمجموع السلة (د.ك) — 0 يعني بدون شرط مبلغ</label><input type="text" id="cgp_min" class="admin-inp-money" inputmode="decimal" lang="en" dir="ltr" placeholder="0"></div>
        <div><label>الترتيب</label><input type="number" id="cgp_sort" value="0" style="max-width:120px;"></div>
        <div style="grid-column:1/-1;">
            <label><strong>نوع الهدية</strong></label>
            <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-top:6px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="radio" name="cgp_kind" value="choice" checked onchange="cgpToggleKind()"> اختيار من مجموعة (أرقام متغيرات)
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="radio" name="cgp_kind" value="fixed" onchange="cgpToggleKind()"> هدية ثابتة (متغير واحد)
                </label>
            </div>
        </div>
        <div id="cgp_block_pool" style="grid-column:1/-1;">
            <label>أرقام متغيرات المنتج (Variant IDs) — مفصولة بفاصلة أو سطر جديد</label>
            <div style="margin:6px 0 8px;">
                <button type="button" class="btn-secondary" onclick="orangeOpenVariantPicker({ mode: 'pool', targetId: 'cgp_pool' })">اختيار بصري — إضافة للقائمة</button>
            </div>
            <textarea id="cgp_pool" rows="3" class="admin-inp" dir="ltr" style="width:100%;max-width:40rem;font-family:monospace;" placeholder="101, 102, 103"></textarea>
        </div>
        <div id="cgp_block_fixed" style="grid-column:1/-1;display:none;">
            <label>رقم المتغير الثابت (Variant ID)</label>
            <div style="margin:6px 0 8px;">
                <button type="button" class="btn-secondary" onclick="orangeOpenVariantPicker({ mode: 'fixed', targetId: 'cgp_fixed' })">اختيار بصري — متغير واحد</button>
            </div>
            <input type="number" id="cgp_fixed" class="admin-inp" min="1" step="1" style="max-width:12rem;" dir="ltr">
        </div>
        <div style="grid-column:1/-1;">
            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;max-width:52rem;line-height:1.45;">
                <input type="checkbox" id="cgp_reg" style="margin-top:4px;flex-shrink:0;">
                <span><strong>للمسجّلين فقط</strong> — عند التفعيل لا يُطبَّق العرض إلا لحساب مفعّل.</span>
            </label>
        </div>
        <div style="display:flex;align-items:flex-end;gap:8px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="cgp_active" checked> نشط
            </label>
        </div>
    </div>
    <div class="admin-form-actions">
        <button type="button" onclick="saveCartGiftPromotion()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ</button>
        <button type="button" class="btn-secondary" onclick="resetCartGiftPromotionForm()">جديد</button>
    </div>
</div>

<div class="card">
    <h3>القواعد</h3>
    <p class="page-subtitle" style="margin-top:0;">عند تعدّد القواعد يُختار أعلى حد أدنى يحقق مجموع السلة.</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>حد أدنى</th>
                    <th>النوع</th>
                    <th>التفاصيل</th>
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

<script>
function cgpToggleKind() {
    const fixed = document.querySelector('input[name="cgp_kind"]:checked');
    const isFixed = fixed && fixed.value === 'fixed';
    document.getElementById('cgp_block_fixed').style.display = isFixed ? 'block' : 'none';
    document.getElementById('cgp_block_pool').style.display = isFixed ? 'none' : 'block';
}

function resetCartGiftPromotionForm() {
    document.getElementById('cgp_id').value = '0';
    document.getElementById('cgp_min').value = '';
    document.getElementById('cgp_sort').value = '0';
    document.getElementById('cgp_pool').value = '';
    document.getElementById('cgp_fixed').value = '';
    document.querySelector('input[name="cgp_kind"][value="choice"]').checked = true;
    document.getElementById('cgp_reg').checked = false;
    document.getElementById('cgp_active').checked = true;
    cgpToggleKind();
}

function editCartGiftPromotion(row) {
    document.getElementById('cgp_id').value = String(row.id != null ? row.id : 0);
    document.getElementById('cgp_min').value = row.min_subtotal != null ? String(row.min_subtotal) : '';
    document.getElementById('cgp_sort').value = String(row.sort_order != null ? row.sort_order : 0);
    const kind = (row.gift_kind || 'choice') === 'fixed' ? 'fixed' : 'choice';
    document.querySelector('input[name="cgp_kind"][value="' + kind + '"]').checked = true;
    cgpToggleKind();
    const pool = row.pool_variant_ids || [];
    document.getElementById('cgp_pool').value = Array.isArray(pool) ? pool.join(', ') : '';
    document.getElementById('cgp_fixed').value =
        row.fixed_variant_id != null && row.fixed_variant_id !== '' ? String(row.fixed_variant_id) : '';
    document.getElementById('cgp_reg').checked = parseInt(row.requires_registered_account, 10) === 1;
    document.getElementById('cgp_active').checked = parseInt(row.is_active, 10) === 1;
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
        if ((r.gift_kind || '') === 'fixed' && r.fixed_variant_id) {
            det = 'متغير #' + escCgp(String(r.fixed_variant_id));
        } else if (Array.isArray(r.pool_variant_ids)) {
            det = escCgp(r.pool_variant_ids.join(', '));
        }
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + escCgp(String(r.id)) + '</td>' +
            '<td dir="ltr">' + escCgp(String(r.min_subtotal)) + '</td>' +
            '<td>' + kind + '</td>' +
            '<td dir="ltr" style="max-width:14rem;word-break:break-all;">' + det + '</td>' +
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
        fixed_variant_id: parseInt(document.getElementById('cgp_fixed').value, 10) || 0,
        pool_variant_ids_text: document.getElementById('cgp_pool').value
    });
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
    if (res.success) {
        resetCartGiftPromotionForm();
        loadCartGiftPromotions();
    }
}

cgpToggleKind();
loadCartGiftPromotions();
</script>
