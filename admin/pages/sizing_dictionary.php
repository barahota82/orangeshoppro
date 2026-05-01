<?php

declare(strict_types=1);

$pdo = db();

$tablesReady = orange_table_exists($pdo, 'commercial_kind_dictionary')
    && orange_table_exists($pdo, 'sizing_category_dictionary');
$sdUseDepartmentKinds = $tablesReady && orange_table_exists($pdo, 'departments');
?>
<div class="page-title">
    <h1>قاموس هرَم المقاس — المستويان 1 و2</h1>
    <?php if ($sdUseDepartmentKinds): ?>
    <p class="page-subtitle" style="margin:0.35rem 0 0;font-size:0.95rem;color:#555;line-height:1.5;">
        <strong>المستوى 1</strong> = <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=departments'), ENT_QUOTES, 'UTF-8'); ?>">الأقسام الرئيسية</a> (<code>departments</code>):
        يُنشأ لكل قسم مفتاح تلقائي في الهرم بصيغة <code>d</code> + رقم المعرف (مثل <code>d3</code>) ويُحدَّث مع الاسم والترتيب والحالة عند حفظ القسم.
        <strong>المستوى 2</strong> = فئات القياس تحت كل قسم؛ تُعرَّف هنا (مفتاح <code>sizing_category_key</code> يُولَّد من الإنجليزي).
        عند وجود صفوف مرجعية نشطة يُفرَض تطابقها عند حفظ
        <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=size_families'), ENT_QUOTES, 'UTF-8'); ?>">عائلات المقاسات</a>.
    </p>
    <p class="page-subtitle" style="margin:0.5rem 0 0;font-size:0.92rem;color:#14532d;line-height:1.55;background:#ecfdf5;border:1px solid #6ee7b7;border-radius:10px;padding:10px 12px;">
        <strong>ملاحظة:</strong> صفوف «نوع تجاري» القديمة غير المرتبطة بقسم (مثل <code>clothing</code>) تظهر أسفل الجدول ولا تزال قابلة للتعديل من هذه الصفحة حتى تُرحَّل يدوياً إلى مفاتيح <code>dرقم</code> إن رغبت.
    </p>
    <?php else: ?>
    <p class="page-subtitle" style="margin:0.35rem 0 0;font-size:0.95rem;color:#555;line-height:1.5;">
        يحدِّد هذا القاموس مفاتيح <strong>commercial_kind_key</strong> و<strong>sizing_category_key</strong>
        (تُولَّد آلياً من الإنجليزي بعد تعبئة التسميات لتفادي أخطاء الإدخال) وبطاقات العرض عربي/إنجليزي.
        بعد إضافة صف نشط لهذا النمط أو لفئة ضمن نوع تجاري، يُفرَض مطابقتها عند حفظ
        <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=size_families'), ENT_QUOTES, 'UTF-8'); ?>">عائلات المقاسات</a>.
        فارغ تماماً = لا إجبار مرجعي (السلوك السابق).
    </p>
    <p class="page-subtitle" style="margin:0.5rem 0 0;font-size:0.92rem;color:#92400e;line-height:1.55;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px 12px;">
        <strong>بدون جدول أقسام رئيسية:</strong> المستوى 1 يُدار يدوياً من هذه الصفحة. عند تفعيل <code>departments</code> يُفضَّل ربط الهرم بالأقسام الرئيسية (مزامنة تلقائية) لتفادي تكرار التصنيف.
    </p>
    <?php endif; ?>
</div>

<?php if (!$tablesReady): ?>
<div class="card">
    <div class="alert-error">
        الجدايل <code>commercial_kind_dictionary</code> أو <code>sizing_category_dictionary</code> غير متاحة. حدّث المخطّط من لوحة متصلة بالسيرفر ثم حدّث الصفحة.
    </div>
</div>
<?php else: ?>
<?php
$sdNextKindSort = 1;
if (! $sdUseDepartmentKinds) {
    try {
        $sdNextKindSort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM commercial_kind_dictionary')->fetchColumn();
    } catch (Throwable $e) {
        $sdNextKindSort = 1;
    }
    if ($sdNextKindSort < 1) {
        $sdNextKindSort = 1;
    }
}
?>

<style>
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
    .sd-cat-form-grid {
        display: grid;
        grid-template-columns: repeat(12, minmax(0, 1fr));
        grid-template-areas:
            "active active key key key key key key key key sort sort"
            "parent parent parent parent parent parent parent parent parent parent parent parent"
            "en en en en en en ar ar ar ar ar ar";
        gap: 14px 18px;
        direction: ltr;
        align-items: start;
    }
    .sd-cat-form-grid .sd-cat-sort {
        grid-area: sort;
        justify-self: end;
        width: 100%;
    }
    .sd-cat-form-grid .sd-cat-act {
        grid-area: active;
        justify-self: start;
        width: 100%;
    }
    .sd-cat-form-grid .sd-cat-key {
        grid-area: key;
        min-width: 0;
    }
    .sd-cat-form-grid .sd-cat-parent {
        grid-area: parent;
        min-width: 0;
    }
    .sd-cat-form-grid .sd-cat-ar { grid-area: ar; }
    .sd-cat-form-grid .sd-cat-en { grid-area: en; }
    .sd-cat-form-grid label,
    .sd-cat-form-grid input,
    .sd-cat-form-grid select {
        direction: rtl;
        text-align: right;
    }
    .sd-cat-form-grid #sd_cat_sort_view {
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
    .sd-cat-form-grid #sd_cat_key {
        width: 100%;
        max-width: none;
        box-sizing: border-box;
        cursor: default;
        background: #f4f6f9;
    }
    .sd-cat-form-grid #sd_cat_active {
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
    .sd-cat-form-grid #sd_cat_parent_kind {
        width: 100%;
        max-width: none;
        box-sizing: border-box;
    }
    .sd-cat-form-actions {
        justify-content: flex-end;
    }
    @media (max-width: 720px) {
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
        .sd-cat-form-grid {
            grid-template-columns: 1fr;
            grid-template-areas:
                "sort"
                "key"
                "active"
                "parent"
                "ar"
                "en";
        }
        .sd-cat-form-grid .sd-cat-sort,
        .sd-cat-form-grid .sd-cat-act {
            justify-self: start;
            max-width: var(--admin-sort-field-max-w, 220px);
        }
    }
</style>

<div class="card" id="sd_kind_form_card" style="<?php echo $sdUseDepartmentKinds ? 'display:none;' : ''; ?>">
    <h3>نوع تجاري (المستوى 1)<?php if ($sdUseDepartmentKinds): ?>
        <span style="font-size:0.85rem;font-weight:normal;color:#666;"> — يدوي فقط (غير <code>dرقم</code>)</span>
    <?php endif; ?></h3>
    <input type="hidden" id="sd_kind_old_key" value="">
    <div class="form-grid sd-kind-form-grid">
        <div class="sd-kind-sort admin-sort-field-wrap">
            <label>الترتيب (تلقائي)</label>
            <input type="hidden" id="sd_kind_sort" value="0">
            <input type="text" id="sd_kind_sort_view" class="admin-sort-field admin-sort-field--muted" readonly disabled tabindex="-1" value="<?php echo (int) $sdNextKindSort; ?>">
            <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;line-height:1.4;">عند إضافة نوع جديد يُعرض <strong>رقم الترتيب التالي</strong> المتوقّع؛ عند التعديل يُعرض رقم الصف الحالي.</small>
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
    <div class="form-grid sd-cat-form-grid">
        <div class="sd-cat-sort admin-sort-field-wrap">
            <label>الترتيب (تلقائي)</label>
            <input type="hidden" id="sd_cat_sort" value="0">
            <input type="text" id="sd_cat_sort_view" class="admin-sort-field admin-sort-field--muted" readonly disabled tabindex="-1" value="1">
            <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;line-height:1.4;">بعد اختيار النوع التجاري يُعرض <strong>رقم الترتيب التالي</strong> للفئات تحته؛ عند التعديل يُعرض رقم الصف الحالي.</small>
        </div>
        <div class="sd-cat-key">
            <label>مفتاح EN (<code>category_key</code>) — للقراءة فقط</label>
            <input type="text" id="sd_cat_key" maxlength="64" autocomplete="off" readonly tabindex="-1">
            <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;line-height:1.4;">يُحسب آلياً من الإنجليزي (حتى 64 محرفاً) بنفس قواعد المفتاح أعلاه.</small>
        </div>
        <div class="sd-cat-act admin-sort-field-wrap">
            <label>نشط</label>
            <select id="sd_cat_active" class="admin-sort-field">
                <option value="1">نعم</option>
                <option value="0">لا</option>
            </select>
        </div>
        <div class="sd-cat-parent">
            <label><?php echo $sdUseDepartmentKinds ? 'القسم الرئيسي (المستوى 1)' : 'النوع التجاري'; ?></label>
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
    </div>
    <div class="actions sd-cat-form-actions" style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;">
        <button type="button" onclick="sdSaveCategory()">حفظ الفئة</button>
        <button type="button" class="btn-secondary" onclick="sdResetCatForm(false)">مسح النموذج</button>
    </div>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;"><?php echo $sdUseDepartmentKinds ? 'الأقسام الرئيسية في الهرم (المستوى 1)' : 'الأنواع التجارية'; ?></h3>
        <button type="button" class="btn-secondary" onclick="sdReloadAll()">تحديث القائمة</button>
    </div>
    <div class="table-wrap" style="margin-top:10px;">
        <table>
            <thead>
                <tr>
                    <?php if ($sdUseDepartmentKinds): ?>
                    <th>قسم #</th>
                    <?php endif; ?>
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
    const sdDepartmentKindMode = <?php echo $sdUseDepartmentKinds ? 'true' : 'false'; ?>;
    const sdDepartmentsPage = <?php echo json_encode(storefront_public_path('/admin/index.php?page=departments'), JSON_UNESCAPED_UNICODE); ?>;
    var sdNextKindSortPreview = <?php echo (int) $sdNextKindSort; ?>;
    var sdNextCatSortPreview = 1;

    function sdRefreshNextKindPreviewFromKinds(kinds) {
        var maxSo = 0;
        (kinds || []).forEach(function (k) {
            var s = parseInt(String(k.sort_order != null ? k.sort_order : '0'), 10) || 0;
            if (s > maxSo) {
                maxSo = s;
            }
        });
        sdNextKindSortPreview = maxSo + 1;
    }

    function sdRefreshNextCatPreviewFromCats(cats) {
        var maxSo = 0;
        (cats || []).forEach(function (c) {
            var s = parseInt(String(c.sort_order != null ? c.sort_order : '0'), 10) || 0;
            if (s > maxSo) {
                maxSo = s;
            }
        });
        sdNextCatSortPreview = maxSo + 1;
    }

    function sdSyncKindSortView() {
        const hid = document.getElementById('sd_kind_sort');
        const vw = document.getElementById('sd_kind_sort_view');
        const oldK = document.getElementById('sd_kind_old_key').value.trim();
        if (oldK === '') {
            hid.value = '0';
            var nk = parseInt(String(sdNextKindSortPreview), 10) || 1;
            if (nk < 1) {
                nk = 1;
            }
            vw.value = String(nk);
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
            var nc = parseInt(String(sdNextCatSortPreview), 10) || 1;
            if (nc < 1) {
                nc = 1;
            }
            vw.value = String(nc);
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
        if (sdDepartmentKindMode) {
            const wrap = document.getElementById('sd_kind_form_card');
            if (wrap) {
                wrap.style.display = 'none';
            }
        } else {
            sdSyncKindSortView();
        }
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
                const deptId = k.department_id != null && k.department_id !== '' ? parseInt(String(k.department_id), 10) : 0;
                const deptLinked = parseInt(String(k.department_linked != null ? k.department_linked : '0'), 10) === 1;
                const isDeptRow = sdDepartmentKindMode && deptLinked && deptId > 0;
                const deptCell = sdDepartmentKindMode
                    ? ('<td>' + (deptId > 0 ? String(deptId) : '—') + '</td>')
                    : '';
                tr.innerHTML =
                    deptCell +
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
                if (isDeptRow) {
                    btns[0].textContent = 'تعديل القسم';
                    btns[0].onclick = function () {
                        window.location.href = sdDepartmentsPage;
                    };
                    btns[1].style.display = 'none';
                } else {
                    btns[0].onclick = function () { sdEditKind(k); };
                    btns[1].onclick = function () { sdDeleteKind(k.kind_key); };
                }
                tb.appendChild(tr);
            });
            if (!sdDepartmentKindMode) {
                sdRefreshNextKindPreviewFromKinds(kinds);
                sdSyncKindSortView();
            }
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
            sdNextCatSortPreview = 1;
            sdSyncCatSortView();
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
            sdRefreshNextCatPreviewFromCats(cats);
            sdSyncCatSortView();
        } catch (e) {
            alert('خطأ شبكة أو خادم');
        }
    };

    window.sdEditKind = function (k) {
        const kk = (k && k.kind_key) ? String(k.kind_key) : '';
        if (sdDepartmentKindMode && /^d\d+$/.test(kk) && parseInt(String(k.department_linked != null ? k.department_linked : '0'), 10) === 1) {
            window.location.href = sdDepartmentsPage;
            return;
        }
        if (sdDepartmentKindMode) {
            const wrap = document.getElementById('sd_kind_form_card');
            if (wrap) {
                wrap.style.display = '';
            }
        }
        document.getElementById('sd_kind_old_key').value = kk;
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
            await sdReloadAll();
            sdResetKindForm();
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
            await sdReloadAll();
            sdResetKindForm();
            sdResetCatForm(false);
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
            await sdLoadKinds(true);
            await sdLoadCategories();
            sdResetCatForm(true);
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
            await sdLoadCategories();
            sdResetCatForm(false);
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
        if (!sdDepartmentKindMode) {
            sdSyncKindSortView();
        }
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
