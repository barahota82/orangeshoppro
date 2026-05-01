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
        (تُولَّد آلياً من الإنجليزي بعد تعبئة التسميات لتفادي أخطاء الإدخال) وبطاقات العرض عربي/إنجليزي.
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

<style>
    .sd-dict-form {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px 18px;
        align-items: start;
    }
    .sd-kind-form-grid {
        display: grid;
        grid-template-columns: repeat(12, minmax(0, 1fr));
        grid-template-areas:
            "active active key key key key key key key key sort sort"
            "en en en en en en ar ar ar ar ar ar";
        gap: 14px 18px;
        direction: ltr;
        align-items: start;
    }
    .sd-kind-form-grid .sd-kind-sort {
        grid-area: sort;
        justify-self: end;
        width: 100%;
    }
    .sd-kind-form-grid .sd-kind-act {
        grid-area: active;
        justify-self: start;
        width: 100%;
    }
    .sd-kind-form-grid .sd-kind-key {
        grid-area: key;
        min-width: 0;
    }
    .sd-kind-form-grid .sd-kind-ar { grid-area: ar; }
    .sd-kind-form-grid .sd-kind-en { grid-area: en; }
    .sd-kind-form-grid label,
    .sd-kind-form-grid input,
    .sd-kind-form-grid select {
        direction: rtl;
        text-align: right;
    }
    .sd-kind-form-grid #sd_kind_sort_view {
        margin-inline: 0;
        display: block;
        width: 100%;
        box-sizing: border-box;
        border: 1px solid #cbd5e1;
        border-radius: var(--radius-sm, 10px);
        font-size: 14px;
        line-height: calc(var(--input-min-h, 36px) - 2px);
        min-height: var(--input-min-h, 36px);
        height: var(--input-min-h, 36px);
        max-height: var(--input-min-h, 36px);
        padding-block: 0;
        padding-inline: 12px;
        background: #f4f6f9;
        cursor: default;
        color: var(--text, #0f172a);
        opacity: 1;
        -webkit-text-fill-color: var(--text, #0f172a);
    }
    .sd-kind-form-grid #sd_kind_key {
        width: 100%;
        max-width: none;
        box-sizing: border-box;
        cursor: default;
        background: #f4f6f9;
    }
    .sd-kind-form-grid #sd_kind_active {
        margin-inline: 0;
        display: block;
        width: 100%;
        box-sizing: border-box;
        border: 1px solid #cbd5e1;
        border-radius: var(--radius-sm, 10px);
        font-size: 14px;
        line-height: calc(var(--input-min-h, 36px) - 2px);
        min-height: var(--input-min-h, 36px);
        height: var(--input-min-h, 36px);
        max-height: var(--input-min-h, 36px);
        padding-block: 0;
        padding-inline: 12px;
        -webkit-appearance: none;
        appearance: none;
        background-color: #fff;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M2.75 4.25L6 7.55l3.25-3.3.65.64L6 8.82 2.1 4.9l.65-.65z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-size: 12px;
        background-position: left 12px center;
        padding-inline-end: 32px;
    }
    .sd-kind-form-actions {
        justify-content: flex-end;
    }
    .sd-dict-cat .sd-cat-r1-sort { grid-column: 1; max-width: var(--admin-sort-field-max-w, 220px); width: 100%; }
    .sd-dict-cat .sd-cat-r1-parent { grid-column: 2; }
    .sd-dict-cat .sd-cat-ar { grid-column: 1; }
    .sd-dict-cat .sd-cat-en { grid-column: 2; }
    .sd-dict-cat .sd-cat-key { grid-column: 1; }
    .sd-dict-cat .sd-cat-act { grid-column: 2; }
    @media (max-width: 720px) {
        .sd-dict-form { grid-template-columns: 1fr; }
        .sd-kind-form-grid {
            grid-template-columns: 1fr;
            grid-template-areas:
                "sort"
                "key"
                "active"
                "ar"
                "en";
        }
        .sd-kind-form-grid .sd-kind-sort,
        .sd-kind-form-grid .sd-kind-act {
            justify-self: start;
            max-width: var(--admin-sort-field-max-w, 220px);
        }
        .sd-dict-cat .sd-cat-r1-sort,
        .sd-dict-cat .sd-cat-r1-parent,
        .sd-dict-cat .sd-cat-ar,
        .sd-dict-cat .sd-cat-en,
        .sd-dict-cat .sd-cat-key,
        .sd-dict-cat .sd-cat-act {
            grid-column: 1 / -1;
            max-width: none;
        }
    }
</style>

<div class="card">
    <h3>نوع تجاري (المستوى 1)</h3>
    <input type="hidden" id="sd_kind_old_key" value="">
    <div class="form-grid sd-kind-form-grid">
        <div class="sd-kind-sort admin-sort-field-wrap">
            <label>الترتيب (تلقائي)</label>
            <input type="hidden" id="sd_kind_sort" value="0">
            <input type="text" id="sd_kind_sort_view" class="admin-sort-field admin-sort-field--muted" readonly disabled tabindex="-1" value="تلقائي">
            <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;line-height:1.4;">يُحدَّد تلقائياً عند حفظ نوع جديد؛ ويُعرَض الرقم الحالي عند التعديل.</small>
        </div>
        <div class="sd-kind-key">
            <label>مفتاح EN (<code>kind_key</code>) — للقراءة فقط</label>
            <input type="text" id="sd_kind_key" maxlength="32" autocomplete="off" <?php echo !$tablesReady ? 'disabled' : ''; ?> readonly tabindex="-1">
            <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;line-height:1.4;">يُحسب آلياً من الإنجليزي: حروف صغيرة وأرقام فقط مع <code>_</code> و<code>-</code> (حتى 32 محرفاً).</small>
        </div>
        <div class="sd-kind-act admin-sort-field-wrap">
            <label>نشط</label>
            <select id="sd_kind_active" class="admin-sort-field">
                <option value="1">نعم</option>
                <option value="0">لا</option>
            </select>
        </div>
        <div class="sd-kind-ar">
            <label>الاسم عربي</label>
            <input type="text" id="sd_kind_label_ar" maxlength="191" <?php echo !$tablesReady ? 'disabled' : ''; ?>>
            <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;line-height:1.4;">عند التوقف عن الكتابة يُحدَّث الإنجليزي صامتاً (لتوليد المفتاح) وفق آلية ترجمة الأسماء في الأدمن.</small>
        </div>
        <div class="sd-kind-en">
            <label>English</label>
            <input type="text" id="sd_kind_label_en" maxlength="191" <?php echo !$tablesReady ? 'disabled' : ''; ?>>
        </div>
    </div>
    <div class="actions sd-kind-form-actions" style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;">
        <button type="button" id="sd_kind_save_btn" onclick="sdSaveKind()">حفظ النوع</button>
        <button type="button" class="btn-secondary" onclick="sdResetKindForm()">صف جديد</button>
    </div>
</div>

<div class="card">
    <h3 style="margin-top:0;">فئة قياس (المستوى 2)</h3>
    <input type="hidden" id="sd_cat_old_key" value="">
    <div class="sd-dict-form sd-dict-cat">
        <div class="sd-cat-r1-sort">
            <label>الترتيب (تلقائي)</label>
            <input type="hidden" id="sd_cat_sort" value="0">
            <input type="text" id="sd_cat_sort_view" class="admin-sort-field admin-sort-field--muted" readonly disabled tabindex="-1" value="تلقائي">
            <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;line-height:1.4;">يُحدَّد تلقائياً عند حفظ فئة جديدة ضمن نوع تجاري؛ ويُعرَض الرقم الحالي عند التعديل.</small>
        </div>
        <div class="sd-cat-r1-parent">
            <label>النوع التجاري</label>
            <select id="sd_cat_parent_kind" <?php echo !$tablesReady ? 'disabled' : ''; ?>></select>
        </div>
        <div class="sd-cat-ar">
            <label>الاسم عربي</label>
            <input type="text" id="sd_cat_label_ar" maxlength="191">
        </div>
        <div class="sd-cat-en">
            <label>English</label>
            <input type="text" id="sd_cat_label_en" maxlength="191">
        </div>
        <div class="sd-cat-key">
            <label>مفتاح EN (<code>category_key</code>) — للقراءة فقط</label>
            <input type="text" id="sd_cat_key" maxlength="64" autocomplete="off" readonly tabindex="-1" class="admin-sort-field admin-sort-field--muted">
            <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;line-height:1.4;">يُحسب آلياً من الإنجليزي (حتى 64 محرفاً) بنفس قواعد المفتاح أعلاه.</small>
        </div>
        <div class="sd-cat-act">
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
    const SD_SORT_AUTO_LABEL = 'تلقائي';

    function sdSyncKindSortView() {
        const hid = document.getElementById('sd_kind_sort');
        const vw = document.getElementById('sd_kind_sort_view');
        const oldK = document.getElementById('sd_kind_old_key').value.trim();
        if (oldK === '') {
            hid.value = '0';
            vw.value = SD_SORT_AUTO_LABEL;
            return;
        }
        const n = parseInt(String(hid.value || '0'), 10) || 0;
        hid.value = String(n);
        vw.value = String(n);
    }

    function sdSyncCatSortView() {
        const hid = document.getElementById('sd_cat_sort');
        const vw = document.getElementById('sd_cat_sort_view');
        const oldC = document.getElementById('sd_cat_old_key').value.trim();
        if (oldC === '') {
            hid.value = '0';
            vw.value = SD_SORT_AUTO_LABEL;
            return;
        }
        const n = parseInt(String(hid.value || '0'), 10) || 0;
        hid.value = String(n);
        vw.value = String(n);
    }

    /** يطابق تعقيم السيرفر: أحرف صغيرة + a-z0-9_- فقط */
    function sdSizingSlugKey(raw, maxLen) {
        let t = String(raw || '').trim().toLowerCase();
        t = t.replace(/[^a-z0-9_-]/g, '');
        if (t.length > maxLen) {
            t = t.substring(0, maxLen);
        }
        return t;
    }

    function sdApplyAutoKindKey() {
        const en = document.getElementById('sd_kind_label_en').value;
        document.getElementById('sd_kind_key').value = sdSizingSlugKey(en, 32);
    }

    function sdApplyAutoCatKey() {
        const en = document.getElementById('sd_cat_label_en').value;
        document.getElementById('sd_cat_key').value = sdSizingSlugKey(en, 64);
    }

    let sdKindArTranslateTimer = null;
    async function sdKindFillEnFromArDebounced() {
        const arEl = document.getElementById('sd_kind_label_ar');
        const enEl = document.getElementById('sd_kind_label_en');
        const ar = arEl.value.trim();
        if (!ar) {
            sdApplyAutoKindKey();
            return;
        }
        try {
            const res = await postJSON('/admin/api/translate/names.php', { name_ar: ar, name_en: '' });
            if (res && res.success && res.translations && res.translations.name_en) {
                enEl.value = res.translations.name_en;
            }
        } catch (e) {}
        sdApplyAutoKindKey();
    }

    let sdCatArTranslateTimer = null;
    async function sdCatFillEnFromArDebounced() {
        const arEl = document.getElementById('sd_cat_label_ar');
        const enEl = document.getElementById('sd_cat_label_en');
        const ar = arEl.value.trim();
        if (!ar) {
            sdApplyAutoCatKey();
            return;
        }
        try {
            const res = await postJSON('/admin/api/translate/names.php', { name_ar: ar, name_en: '' });
            if (res && res.success && res.translations && res.translations.name_en) {
                enEl.value = res.translations.name_en;
            }
        } catch (e) {}
        sdApplyAutoCatKey();
    }

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
        sdSyncKindSortView();
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
        sdSyncCatSortView();
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
        document.getElementById('sd_kind_label_ar').value = k.label_ar || '';
        document.getElementById('sd_kind_label_en').value = k.label_en || '';
        sdApplyAutoKindKey();
        document.getElementById('sd_kind_sort').value = String(k.sort_order != null ? k.sort_order : 0);
        sdSyncKindSortView();
        document.getElementById('sd_kind_active').value = (parseInt(k.is_active, 10) === 0 ? '0' : '1');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    window.sdSaveKind = async function () {
        try {
            sdApplyAutoKindKey();
            let la = document.getElementById('sd_kind_label_ar').value.trim();
            let le = document.getElementById('sd_kind_label_en').value.trim();
            let kk = document.getElementById('sd_kind_key').value.trim();
            if (kk === '' && la !== '') {
                await sdKindFillEnFromArDebounced();
                kk = document.getElementById('sd_kind_key').value.trim();
                le = document.getElementById('sd_kind_label_en').value.trim();
                la = document.getElementById('sd_kind_label_ar').value.trim();
            }
            if (kk === '') {
                alert('أدخل الاسم العربي أو English لتُولَّد المفتاح آلياً قبل الحفظ.');
                return;
            }
            if (la === '' && le === '') {
                alert('عبِّئ الاسم العربي أو English على الأقل.');
                return;
            }
            const payload = {
                action: 'save_kind',
                old_kind_key: document.getElementById('sd_kind_old_key').value.trim(),
                kind_key: kk,
                label_ar: la,
                label_en: le,
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
        document.getElementById('sd_cat_label_ar').value = c.label_ar || '';
        document.getElementById('sd_cat_label_en').value = c.label_en || '';
        sdApplyAutoCatKey();
        document.getElementById('sd_cat_sort').value = String(c.sort_order != null ? c.sort_order : 0);
        sdSyncCatSortView();
        document.getElementById('sd_cat_active').value = (parseInt(c.is_active, 10) === 0 ? '0' : '1');
        window.scrollTo({ top: 200, behavior: 'smooth' });
    };

    window.sdSaveCategory = async function () {
        try {
            sdApplyAutoCatKey();
            const parent = document.getElementById('sd_cat_parent_kind').value.trim();
            if (parent === '') {
                alert('اختر النوع التجاري قبل حفظ الفئة.');
                return;
            }
            let la = document.getElementById('sd_cat_label_ar').value.trim();
            let le = document.getElementById('sd_cat_label_en').value.trim();
            let ck = document.getElementById('sd_cat_key').value.trim();
            if (ck === '' && la !== '') {
                await sdCatFillEnFromArDebounced();
                ck = document.getElementById('sd_cat_key').value.trim();
                le = document.getElementById('sd_cat_label_en').value.trim();
                la = document.getElementById('sd_cat_label_ar').value.trim();
            }
            if (ck === '') {
                alert('أدخل الاسم العربي أو English لتُولَّد المفتاح آلياً قبل الحفظ.');
                return;
            }
            if (la === '' && le === '') {
                alert('عبِّئ الاسم العربي أو English على الأقل.');
                return;
            }
            const payload = {
                action: 'save_category',
                commercial_kind_key: parent,
                old_category_key: document.getElementById('sd_cat_old_key').value.trim(),
                category_key: ck,
                label_ar: la,
                label_en: le,
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

    document.getElementById('sd_kind_label_en').addEventListener('input', sdApplyAutoKindKey);
    document.getElementById('sd_kind_label_ar').addEventListener('input', function () {
        clearTimeout(sdKindArTranslateTimer);
        sdKindArTranslateTimer = setTimeout(sdKindFillEnFromArDebounced, 700);
    });
    document.getElementById('sd_cat_label_en').addEventListener('input', sdApplyAutoCatKey);
    document.getElementById('sd_cat_label_ar').addEventListener('input', function () {
        clearTimeout(sdCatArTranslateTimer);
        sdCatArTranslateTimer = setTimeout(sdCatFillEnFromArDebounced, 700);
    });

    // postJSON يعرّفها admin.js المحمّلة بـ defer؛ السكربت المضمّن هنا ينفَّذ أثناء التحليل قبلها.
    function sdInitSizingDictionary() {
        sdSyncKindSortView();
        sdSyncCatSortView();
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
