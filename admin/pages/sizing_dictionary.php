<?php

declare(strict_types=1);

$pdo = db();

$tablesReady = orange_table_exists($pdo, 'commercial_kind_dictionary')
    && orange_table_exists($pdo, 'sizing_category_dictionary');
?>
<div class="page-title">
    <h1>قاموس هرَم المقاس — المستويان 1 و2</h1>
    <p class="page-subtitle" style="margin:0.35rem 0 0;font-size:0.95rem;color:#555;line-height:1.5;">
        يحدِّد هذا القاموس مفاتيح <strong>commercial_kind_key</strong> و<strong>sizing_category_key</strong>
        وبطاقات العرض (عربي/إنجليزي).
        بعد إضافة صف نشط لهذا النمط أو لفئة ضمن نوع تجاري، يُفرَض مطابقتها عند حفظ
        <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=size_families'), ENT_QUOTES, 'UTF-8'); ?>">عائلات المقاسات</a>.
        فارغ تماماً = لا إجبار مرجعي (السلوك السابق).
    </p>
</div>

<?php if (!$tablesReady): ?>
<div class="card">
    <div class="alert-error">
        الجدايل <code>commercial_kind_dictionary</code> أو <code>sizing_category_dictionary</code> غير متاحة. حدّث المخطّط من لوحة متصلة بالسيرفر ثم حدّث الصفحة.
    </div>
</div>
<?php else: ?>

<div class="card">
    <h3>نوع تجاري (المستوى 1)</h3>
    <input type="hidden" id="sd_kind_old_key" value="">
    <div class="form-grid" style="gap:12px;">
        <div>
            <label>مفتاح EN (<code>kind_key</code>)</label>
            <input type="text" id="sd_kind_key" maxlength="32" autocomplete="off" <?php echo !$tablesReady ? 'disabled' : ''; ?>>
        </div>
        <div>
            <label>التسمية العربية</label>
            <input type="text" id="sd_kind_label_ar" maxlength="191" <?php echo !$tablesReady ? 'disabled' : ''; ?>>
        </div>
        <div>
            <label>التسمية الإنجليزية</label>
            <input type="text" id="sd_kind_label_en" maxlength="191" <?php echo !$tablesReady ? 'disabled' : ''; ?>>
        </div>
        <div>
            <label>الترتيب</label>
            <input type="number" id="sd_kind_sort" value="0" style="max-width:120px;">
        </div>
        <div>
            <label>نشط</label>
            <select id="sd_kind_active">
                <option value="1">نعم</option>
                <option value="0">لا</option>
            </select>
        </div>
    </div>
    <div class="actions" style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;">
        <button type="button" id="sd_kind_save_btn" onclick="sdSaveKind()">حفظ النوع</button>
        <button type="button" class="btn-secondary" onclick="sdResetKindForm()">صف جديد</button>
    </div>
</div>

<div class="card">
    <h3 style="margin-top:0;">فئة قياس (المستوى 2)</h3>
    <input type="hidden" id="sd_cat_old_key" value="">
    <div class="form-grid" style="gap:12px;">
        <div>
            <label>النوع التجاري</label>
            <select id="sd_cat_parent_kind" <?php echo !$tablesReady ? 'disabled' : ''; ?>></select>
        </div>
        <div>
            <label>مفتاح EN (<code>category_key</code>)</label>
            <input type="text" id="sd_cat_key" maxlength="64" autocomplete="off">
        </div>
        <div>
            <label>التسمية العربية</label>
            <input type="text" id="sd_cat_label_ar" maxlength="191">
        </div>
        <div>
            <label>التسمية الإنجليزية</label>
            <input type="text" id="sd_cat_label_en" maxlength="191">
        </div>
        <div>
            <label>الترتيب</label>
            <input type="number" id="sd_cat_sort" value="0" style="max-width:120px;">
        </div>
        <div>
            <label>نشط</label>
            <select id="sd_cat_active">
                <option value="1">نعم</option>
                <option value="0">لا</option>
            </select>
        </div>
    </div>
    <div class="actions" style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;">
        <button type="button" onclick="sdSaveCategory()">حفظ الفئة</button>
        <button type="button" class="btn-secondary" onclick="sdResetCatForm(false)">مسح النموذج</button>
    </div>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;">الأنواع التجارية</h3>
        <button type="button" class="btn-secondary" onclick="sdReloadAll()">تحديث القائمة</button>
    </div>
    <div class="table-wrap" style="margin-top:10px;">
        <table>
            <thead>
                <tr>
                    <th>المفتاح</th>
                    <th>عرض عربي</th>
                    <th>عرض EN</th>
                    <th>ترتيب</th>
                    <th>نشط</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody id="sd_kinds_tbody"></tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3 style="margin-top:0;">فئات القياس ضمن النوع المحدّد</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>المفتاح</th>
                    <th>عرض عربي</th>
                    <th>عرض EN</th>
                    <th>ترتيب</th>
                    <th>نشط</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody id="sd_cats_tbody"></tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const api = '/admin/api/sizing_dictionary/manage.php';

    window.sdReloadAll = async function () {
        await sdLoadKinds(true);
        await sdLoadCategories();
    };

    window.sdResetKindForm = function () {
        document.getElementById('sd_kind_old_key').value = '';
        document.getElementById('sd_kind_key').value = '';
        document.getElementById('sd_kind_label_ar').value = '';
        document.getElementById('sd_kind_label_en').value = '';
        document.getElementById('sd_kind_sort').value = '0';
        document.getElementById('sd_kind_active').value = '1';
    };

    window.sdResetCatForm = function (preserveKindDropdown) {
        preserveKindDropdown = !!preserveKindDropdown;
        document.getElementById('sd_cat_old_key').value = '';
        document.getElementById('sd_cat_key').value = '';
        document.getElementById('sd_cat_label_ar').value = '';
        document.getElementById('sd_cat_label_en').value = '';
        document.getElementById('sd_cat_sort').value = '0';
        document.getElementById('sd_cat_active').value = '1';
        if (!preserveKindDropdown) {
            document.getElementById('sd_cat_parent_kind').value = '';
        }
    };

    function sdRefreshKindSelect(kinds, preferred) {
        const sel = document.getElementById('sd_cat_parent_kind');
        const prev = preferred || sel.value || '';
        sel.innerHTML = '';
        const opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = '— اختر النوع —';
        sel.appendChild(opt0);
        (kinds || []).forEach(function (k) {
            const o = document.createElement('option');
            o.value = k.kind_key;
            o.textContent = (k.label_ar || k.kind_key) + ' (' + k.kind_key + ')';
            sel.appendChild(o);
        });
        if (prev && [...sel.options].some(function (x) { return x.value === prev; })) {
            sel.value = prev;
        }
    }

    window.sdLoadKinds = async function (refreshSelect) {
        try {
            const res = await postJSON(api, { action: 'list_kinds' });
            if (!res || !res.success) {
                alert(res && res.message ? res.message : 'تعذر تحميل الأنواع');
                return;
            }
            const kinds = res.kinds || [];
            const tb = document.getElementById('sd_kinds_tbody');
            tb.innerHTML = '';
            kinds.forEach(function (k) {
                const tr = document.createElement('tr');
                tr.innerHTML =
                    '<td><code>' + escapeHtml(k.kind_key || '') + '</code></td>' +
                    '<td>' + escapeHtml(k.label_ar || '') + '</td>' +
                    '<td>' + escapeHtml(k.label_en || '') + '</td>' +
                    '<td>' + String(k.sort_order != null ? k.sort_order : '') + '</td>' +
                    '<td>' + ((parseInt(k.is_active, 10) === 1) ? 'نعم' : 'لا') + '</td>' +
                    '<td>' +
                        '<button type="button" class="btn-secondary" style="margin-left:6px;">تعديل</button>' +
                        '<button type="button" class="btn-secondary">حذف</button>' +
                    '</td>';
                const btns = tr.querySelectorAll('button');
                btns[0].onclick = function () { sdEditKind(k); };
                btns[1].onclick = function () { sdDeleteKind(k.kind_key); };
                tb.appendChild(tr);
            });
            if (refreshSelect) {
                sdRefreshKindSelect(kinds, prevKindForCats || document.getElementById('sd_cat_parent_kind').value);
            }
        } catch (e) {
            alert('خطأ شبكة أو خادم');
        }
    };

    let prevKindForCats = '';

    window.sdLoadCategories = async function () {
        const sel = document.getElementById('sd_cat_parent_kind');
        let ck = sel.value;
        const tb = document.getElementById('sd_cats_tbody');
        if (!ck) {
            tb.innerHTML = '';
            return;
        }
        prevKindForCats = ck;
        try {
            const res = await postJSON(api, { action: 'list_categories', commercial_kind_key: ck });
            if (!res || !res.success) {
                alert(res && res.message ? res.message : 'تعذر تحميل الفئات');
                return;
            }
            const cats = res.categories || [];
            tb.innerHTML = '';
            cats.forEach(function (c) {
                const tr = document.createElement('tr');
                tr.innerHTML =
                    '<td><code>' + escapeHtml(c.category_key || '') + '</code></td>' +
                    '<td>' + escapeHtml(c.label_ar || '') + '</td>' +
                    '<td>' + escapeHtml(c.label_en || '') + '</td>' +
                    '<td>' + String(c.sort_order != null ? c.sort_order : '') + '</td>' +
                    '<td>' + ((parseInt(c.is_active, 10) === 1) ? 'نعم' : 'لا') + '</td>' +
                    '<td>' +
                        '<button type="button" class="btn-secondary" style="margin-left:6px;">تعديل</button>' +
                        '<button type="button" class="btn-secondary">حذف</button>' +
                    '</td>';
                const btns = tr.querySelectorAll('button');
                btns[0].onclick = function () { sdEditCategory(c); };
                btns[1].onclick = function () { sdDeleteCategory(c.commercial_kind_key, c.category_key); };
                tb.appendChild(tr);
            });
        } catch (e) {
            alert('خطأ شبكة أو خادم');
        }
    };

    window.sdEditKind = function (k) {
        document.getElementById('sd_kind_old_key').value = k.kind_key || '';
        document.getElementById('sd_kind_key').value = k.kind_key || '';
        document.getElementById('sd_kind_label_ar').value = k.label_ar || '';
        document.getElementById('sd_kind_label_en').value = k.label_en || '';
        document.getElementById('sd_kind_sort').value = String(k.sort_order != null ? k.sort_order : 0);
        document.getElementById('sd_kind_active').value = (parseInt(k.is_active, 10) === 0 ? '0' : '1');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    window.sdSaveKind = async function () {
        try {
            const payload = {
                action: 'save_kind',
                old_kind_key: document.getElementById('sd_kind_old_key').value.trim(),
                kind_key: document.getElementById('sd_kind_key').value.trim(),
                label_ar: document.getElementById('sd_kind_label_ar').value.trim(),
                label_en: document.getElementById('sd_kind_label_en').value.trim(),
                sort_order: parseInt(document.getElementById('sd_kind_sort').value, 10) || 0,
                is_active: parseInt(document.getElementById('sd_kind_active').value, 10),
            };
            const res = await postJSON(api, payload);
            if (!res || !res.success) {
                alert(res && res.message ? res.message : 'فشل الحفظ');
                return;
            }
            sdResetKindForm();
            await sdReloadAll();
        } catch (e) {
            alert('فشل الحفظ');
        }
    };

    window.sdDeleteKind = async function (kindKey) {
        if (!confirm('حذف النوع وجميع فئاته المعرّفة تحته في القاموس؟ تأكّد أنه غير مستخدم في عائلات مقاس.')) return;
        try {
            const res = await postJSON(api, { action: 'delete_kind', kind_key: kindKey });
            if (!res || !res.success) {
                alert(res && res.message ? res.message : 'تعذر الحذف');
                return;
            }
            sdResetKindForm();
            sdResetCatForm(false);
            await sdReloadAll();
        } catch (e) {
            alert('تعذر الحذف');
        }
    };

    window.sdEditCategory = function (c) {
        document.getElementById('sd_cat_parent_kind').value = c.commercial_kind_key || '';
        document.getElementById('sd_cat_old_key').value = c.category_key || '';
        document.getElementById('sd_cat_key').value = c.category_key || '';
        document.getElementById('sd_cat_label_ar').value = c.label_ar || '';
        document.getElementById('sd_cat_label_en').value = c.label_en || '';
        document.getElementById('sd_cat_sort').value = String(c.sort_order != null ? c.sort_order : 0);
        document.getElementById('sd_cat_active').value = (parseInt(c.is_active, 10) === 0 ? '0' : '1');
        window.scrollTo({ top: 200, behavior: 'smooth' });
    };

    window.sdSaveCategory = async function () {
        try {
            const payload = {
                action: 'save_category',
                commercial_kind_key: document.getElementById('sd_cat_parent_kind').value.trim(),
                old_category_key: document.getElementById('sd_cat_old_key').value.trim(),
                category_key: document.getElementById('sd_cat_key').value.trim(),
                label_ar: document.getElementById('sd_cat_label_ar').value.trim(),
                label_en: document.getElementById('sd_cat_label_en').value.trim(),
                sort_order: parseInt(document.getElementById('sd_cat_sort').value, 10) || 0,
                is_active: parseInt(document.getElementById('sd_cat_active').value, 10),
            };
            const res = await postJSON(api, payload);
            if (!res || !res.success) {
                alert(res && res.message ? res.message : 'فشل الحفظ');
                return;
            }
            sdResetCatForm(true);
            await sdLoadKinds(true);
            await sdLoadCategories();
        } catch (e) {
            alert('فشل الحفظ');
        }
    };

    window.sdDeleteCategory = async function (ck, catKey) {
        if (!confirm('حذف فئة القياس من القاموس؟')) return;
        try {
            const res = await postJSON(api, { action: 'delete_category', commercial_kind_key: ck, category_key: catKey });
            if (!res || !res.success) {
                alert(res && res.message ? res.message : 'تعذر الحذف');
                return;
            }
            sdResetCatForm(false);
            await sdLoadCategories();
        } catch (e) {
            alert('تعذر الحذف');
        }
    };

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    document.getElementById('sd_cat_parent_kind').addEventListener('change', function () {
        sdLoadCategories();
    });

    // postJSON يعرّفها admin.js المحمّلة بـ defer؛ السكربت المضمّن هنا ينفَّذ أثناء التحليل قبلها.
    function sdInitSizingDictionary() {
        sdReloadAll();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sdInitSizingDictionary);
    } else {
        sdInitSizingDictionary();
    }
})();
</script>

<?php endif; ?>
