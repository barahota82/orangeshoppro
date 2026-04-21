<?php

declare(strict_types=1);

$pdo = db();
orange_catalog_ensure_schema($pdo);
$hasTable = orange_table_exists($pdo, 'cart_promotions');
?>
<div class="page-title page-title--stacked">
    <h1>عروض مجموع السلة</h1>
    <p class="page-subtitle">خصم تلقائي عند تجاوز حد أدنى لمجموع السلة (مثال: 10 د.ك → خصم 2 د.ك). يُحسب على السيرفر عند الطلب.
        <strong>نطاق كل قاعدة:</strong> إمّا <strong>لجميع زوّار الموقع</strong> (ضيف ومسجّل) أو <strong>للمسجّلين فقط</strong> — يحددها الأدمن لكل صف بالأسفل.</p>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>cart_promotions</code> غير جاهز.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل</h3>
    <input type="hidden" id="cp_id" value="0">
    <div class="form-grid">
        <div><label>الحد الأدنى لمجموع السلة (د.ك)</label><input type="text" id="cp_min" class="admin-inp-money" inputmode="decimal" lang="en" dir="ltr" placeholder="10"></div>
        <div><label>مبلغ الخصم (د.ك)</label><input type="text" id="cp_disc" class="admin-inp-money" inputmode="decimal" lang="en" dir="ltr" placeholder="2"></div>
        <div><label>الترتيب</label><input type="number" id="cp_sort" value="0" style="max-width:120px;"></div>
        <div style="grid-column:1/-1;">
            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;max-width:52rem;line-height:1.45;">
                <input type="checkbox" id="cp_reg" style="margin-top:4px;flex-shrink:0;">
                <span><strong>للمسجّلين فقط</strong> — عند تفعيلها لا يُطبَّق الخصم إلا لحساب مفعّل (بريد مؤكد). عند <strong>عدم</strong> التفعيل يكون العرض <strong>لجميع الزوّار</strong> (ضيف ومسجّل).</span>
            </label>
        </div>
        <div style="display:flex;align-items:flex-end;gap:8px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="cp_active" checked> نشط
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
    <p class="page-subtitle" style="margin-top:0;">عند تعدّد القواعد يُختار أعلى حد أدنى يحقق مجموع السلة الحالي (طبقة أعلى).</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>حد أدنى</th>
                    <th>خصم</th>
                    <th>نطاق العرض</th>
                    <th>ترتيب</th>
                    <th>نشط</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="cp_tbody"></tbody>
        </table>
    </div>
</div>

<script>
function resetCartPromotionForm() {
    document.getElementById('cp_id').value = '0';
    document.getElementById('cp_min').value = '';
    document.getElementById('cp_disc').value = '';
    document.getElementById('cp_sort').value = '0';
    document.getElementById('cp_reg').checked = false;
    document.getElementById('cp_active').checked = true;
}

function editCartPromotion(row) {
    document.getElementById('cp_id').value = String(row.id != null ? row.id : 0);
    document.getElementById('cp_min').value = row.min_subtotal != null ? String(row.min_subtotal) : '';
    document.getElementById('cp_disc').value = row.discount_amount != null ? String(row.discount_amount) : '';
    document.getElementById('cp_sort').value = String(row.sort_order != null ? row.sort_order : 0);
    document.getElementById('cp_reg').checked = parseInt(row.requires_registered_account, 10) === 1;
    document.getElementById('cp_active').checked = parseInt(row.is_active, 10) === 1;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function escCp(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;');
}

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
            '<td dir="ltr">' + escCp(String(r.min_subtotal)) + '</td>' +
            '<td dir="ltr">' + escCp(String(r.discount_amount)) + '</td>' +
            '<td>' + (parseInt(r.requires_registered_account, 10) === 1 ? 'مسجّل فقط' : 'جميع الزوّار') + '</td>' +
            '<td>' + escCp(String(r.sort_order)) + '</td>' +
            '<td>' + (parseInt(r.is_active, 10) === 1 ? 'نعم' : 'لا') + '</td>' +
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
        min_subtotal: document.getElementById('cp_min').value.trim(),
        discount_amount: document.getElementById('cp_disc').value.trim(),
        sort_order: parseInt(document.getElementById('cp_sort').value, 10) || 0,
        requires_registered_account: document.getElementById('cp_reg').checked ? 1 : 0,
        is_active: document.getElementById('cp_active').checked ? 1 : 0
    });
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
    if (res.success) {
        resetCartPromotionForm();
        loadCartPromotions();
    }
}

loadCartPromotions();
</script>
