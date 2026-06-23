<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
$pdo = db();
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/advisory_sizing_guides.php';

orange_catalog_ensure_schema($pdo);

$tablesReady = orange_table_exists($pdo, 'size_families')
    && orange_table_exists($pdo, 'size_family_sizes')
    && orange_table_exists($pdo, 'advisory_sizing_guides');

$departments = [];
$families = [];
$sizesByFamily = [];
$kindLabels = [];
if ($tablesReady) {
    try {
        if (orange_table_exists($pdo, 'departments')) {
            $departments = $pdo->query(
                'SELECT id, name_ar, name_en FROM departments WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $families = $pdo->query(
            'SELECT id, name_ar, name_en, commercial_kind_key, sizing_category_key, size_scheme_template_id
             FROM size_families WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $sStmt = $pdo->query(
            'SELECT id, size_family_id, label_ar, label_en, sort_order
             FROM size_family_sizes WHERE is_active = 1 ORDER BY size_family_id ASC, sort_order ASC, id ASC'
        );
        foreach ($sStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $fid = (int) $s['size_family_id'];
            $sizesByFamily[$fid][] = $s;
        }

        // تسميات النوع التجاري (لفلترة العائلات حسب نوعها هي، لا حسب القسم).
        if (orange_table_exists($pdo, 'commercial_kind_dictionary')) {
            $ckRows = $pdo->query(
                'SELECT kind_key, label_ar, label_en FROM commercial_kind_dictionary WHERE is_active = 1'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($ckRows as $r) {
                $k = trim((string) ($r['kind_key'] ?? ''));
                if ($k === '') {
                    continue;
                }
                $kindLabels[$k] = (string) (($r['label_ar'] ?? '') !== '' ? $r['label_ar'] : (($r['label_en'] ?? '') !== '' ? $r['label_en'] : $k));
            }
        }
    } catch (Throwable $e) {
        $departments = [];
        $families = [];
        $sizesByFamily = [];
        $kindLabels = [];
    }
}

$kindLabelsJson = json_encode($kindLabels, JSON_UNESCAPED_UNICODE);
if ($kindLabelsJson === false) {
    $kindLabelsJson = '{}';
}

$prefSizeFamilyId = isset($_GET['size_family_id']) ? (int) $_GET['size_family_id'] : 0;

$sizesJson = json_encode($sizesByFamily, JSON_UNESCAPED_UNICODE);
if ($sizesJson === false) {
    $sizesJson = '{}';
}
$familiesJson = json_encode($families, JSON_UNESCAPED_UNICODE);
if ($familiesJson === false) {
    $familiesJson = '[]';
}
?>
<div class="page-title">
    <h1>دليل المقاس الاسترشادي (عرض للعميل)</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (!$tablesReady): ?>
<div class="card">
    <div class="alert-error">الجداول غير جاهزة. زر <strong>الأدمن</strong> أو أي صفحة كتالوج لتهيئة المخطّط، ثم حدّث.</div>
</div>
<?php else: ?>

<div class="card">
    <h3 style="margin:0 0 14px;">إنشاء / تعديل دليل المقاس</h3>
    <input type="hidden" id="asg_edit_id" value="0">
    <div class="asg-form-grid">
        <!-- السطر الأول (يُقرأ يميناً→يساراً): القسم الرئيسي · نوع المقاس · عائلة المقاسات · الحالة -->
        <div class="asg-fg-dept">
            <label for="asg_dept">القسم الرئيسي <span style="color:#b91c1c;">*</span></label>
            <select id="asg_dept"><option value="0">— اختر —</option>
                <?php foreach ($departments as $d): ?>
                <option value="<?php echo (int) $d['id']; ?>"><?php echo htmlspecialchars((string) ($d['name_ar'] ?: $d['name_en']), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="asg-fg-kind">
            <label for="asg_kind_filter">نوع المقاس (تصفية) <span style="color:#b91c1c;">*</span></label>
            <select id="asg_kind_filter" disabled><option value="">— اختر —</option></select>
        </div>
        <div class="asg-fg-family">
            <label for="asg_family">عائلة المقاسات <span style="color:#b91c1c;">*</span></label>
            <select id="asg_family" disabled><option value="0">— اختر —</option></select>
        </div>
        <div class="asg-fg-status">
            <label for="asg_active">الحالة</label>
            <select id="asg_active"><option value="1">نشط</option><option value="0">موقوف</option></select>
        </div>
        <!-- السطر الثاني: الاسم الداخلي · شكل الدليل -->
        <div class="asg-fg-name">
            <label for="asg_name">الاسم الداخلي (عربي) <span style="color:#b91c1c;">*</span></label>
            <input type="text" id="asg_name" maxlength="191" placeholder="مثال: قمصان رجالي EU">
        </div>
        <div class="asg-fg-shape">
            <label>شكل الدليل <span style="color:#b91c1c;">*</span></label>
            <div style="display:flex;gap:16px;align-items:center;padding-top:6px;">
                <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin:0;cursor:pointer;">
                    <input type="radio" name="asg_shape" value="single" checked> جدول واحد
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin:0;cursor:pointer;">
                    <input type="radio" name="asg_shape" value="dual"> جدولان: علوي + سفلي
                </label>
            </div>
        </div>
    </div>

    <div id="asg_panels" style="margin-top:18px;"></div>

    <div class="card" style="margin-top:18px;background:#f8fafc;">
        <h4 style="margin:0 0 6px;">يطبَّق على (اختياري)</h4>
        <p class="card-hint" style="margin:0 0 12px;">لو تركت الاثنين فارغين، يصبح الدليل أساساً عاماً للقسم + العائلة. الأخصّ يفوز: دليل المنتج، ثم دليل نوع المنتج، ثم دليل العائلة. <strong>الاختيار بالنقر المزدوج</strong> على الحقل لفتح القائمة (لا كتابة).</p>
        <div class="asg-applies-grid">
            <div class="asg-picker" data-picker="types">
                <label>أنواع المنتج</label>
                <div class="asg-picker-field" id="asg_pick_types" tabindex="0" role="button" aria-label="اختر أنواع المنتج بالنقر المزدوج">
                    <span class="asg-picker-placeholder">انقر مرتين للاختيار…</span>
                </div>
                <div class="asg-picker-panel" id="asg_panel_types" hidden>
                    <div id="asg_link_types" class="asg-link-box">
                        <span class="card-hint">اختر عائلة لعرض أنواع المنتج المرشّحة.</span>
                    </div>
                </div>
            </div>
            <div class="asg-picker" data-picker="products">
                <label>منتجات</label>
                <div class="asg-picker-field" id="asg_pick_products" tabindex="0" role="button" aria-label="اختر المنتجات بالنقر المزدوج">
                    <span class="asg-picker-placeholder">انقر مرتين للاختيار…</span>
                </div>
                <div class="asg-picker-panel" id="asg_panel_products" hidden>
                    <input type="text" id="asg_link_products_search" placeholder="تصفية بالاسم (اختياري)…" style="width:100%;box-sizing:border-box;margin-bottom:6px;">
                    <div id="asg_link_products" class="asg-link-box">
                        <span class="card-hint">اختر عائلة لعرض منتجاتها.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn" id="asg_save_btn">حفظ</button>
        <button type="button" class="btn-secondary" id="asg_reset_btn">نموذج جديد</button>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <h3 style="margin-top:0;">الأدلة المحفوظة لهذه العائلة</h3>
    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr><th>#</th><th>الاسم الداخلي</th><th>الشكل</th><th>أعمدة</th><th>صفوف</th><th>أنواع</th><th>منتجات</th><th>الحالة</th><th>إجراءات</th></tr>
            </thead>
            <tbody id="asg_list_tbody"><tr><td colspan="9" class="card-hint">اختر عائلة المقاسات لعرض أدلتها.</td></tr></tbody>
        </table>
    </div>
</div>

<template id="asg_panel_tpl">
    <div class="asg-panel card" style="margin-top:14px;">
        <h4 class="asg-panel-title" style="margin:0 0 10px;"></h4>
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:8px;">
            <div>
                <label>عدد الأعمدة</label>
                <input type="number" class="asg-col-count" min="1" max="24" value="3" style="width:5rem;">
            </div>
            <button type="button" class="btn asg-gen-cols">توليد الجدول (أعمدة + مقاسات العائلة)</button>
            <button type="button" class="btn-secondary asg-col-add">+ عمود</button>
            <button type="button" class="btn-secondary asg-col-remove">− حذف آخر عمود</button>
        </div>
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead><tr><th>ترتيب</th><th>عربي *</th><th>EN *</th><th>Fil *</th><th>Hi *</th><th>نوع القيمة</th><th>وحدة</th><th>تخزين الطول</th><th>كود النظام</th></tr></thead>
                <tbody class="asg-cols-body"></tbody>
            </table>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 8px;align-items:center;">
            <button type="button" class="btn-secondary asg-row-data">+ صف بيانات</button>
            <button type="button" class="btn-secondary asg-row-label">+ صف عنوان</button>
        </div>
        <div class="asg-rows-box"></div>
    </div>
</template>

<style>
.asg-row-block { margin-bottom: 10px; padding: 12px; }
input.asg-cell--from-family { background: #f1f5f9; color: #475569; cursor: default; }
.asg-link-box label { font-weight: normal; display:flex; align-items:center; gap:6px; margin:0 0 4px; cursor:pointer; }
/* نموذج إنشاء/تعديل: السطر الأول 4 خانات (القسم/نوع/العائلة/الحالة) بعرض متساوٍ، والسطر الثاني الاسم + شكل الدليل.
   الترتيب يُقرأ يميناً→يساراً (RTL): أول عمود في الـ areas يظهر يميناً. عرض النموذج وُسِّع كي تبقى الخانات الثلاث بعرضها الحالي تقريباً. */
.asg-form-grid {
    display: grid;
    grid-template-columns: repeat(8, minmax(0, 1fr));
    grid-template-areas:
        "dept dept kind kind family family status ."
        "name name name name shape  shape  shape  shape";
    gap: 14px 18px;
    direction: rtl;
    align-items: start;
    max-width: 1340px;
}
.asg-form-grid > div { min-width: 0; }
.asg-fg-dept { grid-area: dept; }
.asg-fg-kind { grid-area: kind; }
.asg-fg-family { grid-area: family; }
.asg-fg-status { grid-area: status; }
.asg-fg-name { grid-area: name; }
.asg-fg-shape { grid-area: shape; }
.asg-form-grid label { display: block; margin: 0 0 4px; }
.asg-form-grid select,
.asg-form-grid input[type="text"] { width: 100%; box-sizing: border-box; }
.asg-form-grid select:disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }
.asg-applies-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 18px;
    direction: rtl;
    align-items: start;
    max-width: 1000px;
}
.asg-applies-grid > div { min-width: 0; }
.asg-applies-grid label { display: block; margin: 0 0 4px; }
.asg-picker { position: relative; }
.asg-picker-field {
    min-height: 38px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #fff;
    padding: 6px 8px;
    cursor: pointer;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    user-select: none;
}
.asg-picker-field:focus { outline: 2px solid #93c5fd; outline-offset: 1px; }
.asg-picker-placeholder { color: #94a3b8; font-size: 13px; }
.asg-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1e3a8a;
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 12px;
    max-width: 100%;
}
.asg-chip span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.asg-chip button { border: none; background: transparent; cursor: pointer; color: #1e3a8a; font-size: 14px; line-height: 1; padding: 0; }
.asg-picker-panel {
    position: absolute;
    z-index: 30;
    inset-inline-start: 0;
    inset-inline-end: 0;
    top: calc(100% + 4px);
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .14);
    padding: 8px;
}
.asg-picker-panel .asg-link-box { max-height: 220px; overflow: auto; }
.asg-link-box { border: 0; }
@media (max-width: 720px) {
    .asg-form-grid { grid-template-columns: 1fr; grid-template-areas: none; }
    .asg-applies-grid { grid-template-columns: 1fr; }
}
</style>

<script>
(function () {
    var ADVISORY_API = '/admin/api/advisory_sizing_guides/manage.php';
    var TRANSLATE_API = '/admin/api/translate/names.php';
    var FAMILY_SIZES = <?php echo $sizesJson; ?>;
    var FAMILIES = <?php echo $familiesJson; ?>;
    var KIND_LABELS = <?php echo $kindLabelsJson; ?>;
    var PREF_FAMILY = <?php echo (int) $prefSizeFamilyId; ?>;
    var rowSeq = 0;

    function esc(s) {
        if (s === 0) { return '0'; }
        if (!s) { return ''; }
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    async function post(url, payload) {
        if (typeof postJSON === 'function') {
            return await postJSON(url, payload);
        }
        try {
            var r = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(payload)
            });
            var text = await r.text();
            try { return JSON.parse(text); } catch (e2) { return { success: false, message: 'رد السيرفر ليس JSON صالحاً.' }; }
        } catch (e) {
            return { success: false, message: e.message || 'تعذّر الاتصال بالخادم' };
        }
    }

    function famId() { return parseInt(document.getElementById('asg_family').value, 10) || 0; }
    function deptId() { return parseInt(document.getElementById('asg_dept').value, 10) || 0; }
    function editId() { return parseInt(document.getElementById('asg_edit_id').value, 10) || 0; }
    function shape() {
        var r = document.querySelector('input[name="asg_shape"]:checked');
        return r && r.value === 'dual' ? 'dual' : 'single';
    }
    function panelKinds() { return shape() === 'dual' ? ['upper', 'lower'] : ['single']; }
    function panelTitle(pk) {
        if (pk === 'upper') { return 'الجدول العلوي'; }
        if (pk === 'lower') { return 'الجدول السفلي'; }
        return 'الجدول';
    }

    function familySizeRows() {
        var f = famId();
        var raw = (FAMILY_SIZES[String(f)] || []).slice();
        return raw.sort(function (a, b) { return (parseInt(a.sort_order, 10) || 0) - (parseInt(b.sort_order, 10) || 0); });
    }

    function sizeOptionsHtml(selectedId) {
        var rows = familySizeRows();
        var sel = parseInt(selectedId, 10) || 0;
        var h = '<option value="0">— اختر المقاس —</option>';
        for (var i = 0; i < rows.length; i++) {
            var id = parseInt(rows[i].id, 10) || 0;
            var lab = (rows[i].label_ar || rows[i].label_en || '').replace(/</g, '');
            h += '<option value="' + id + '"' + (id === sel ? ' selected' : '') + '>' + esc(lab) + '</option>';
        }
        return h;
    }
    function familyLabelById(sid) {
        var rows = familySizeRows();
        for (var i = 0; i < rows.length; i++) {
            if ((parseInt(rows[i].id, 10) || 0) === sid) {
                return (rows[i].label_ar || rows[i].label_en || ('#' + sid)).replace(/</g, '');
            }
        }
        return '#' + sid;
    }

    function normSys(s) {
        s = String(s || '').toLowerCase().trim().replace(/[^a-z0-9_]/g, '');
        return s.length > 32 ? s.slice(0, 32) : s;
    }

    async function translateRow(getAr, getEn, setEn, setFil, setHi, forceFromArabic, silent) {
        try {
            var res = await post(TRANSLATE_API, { name_ar: getAr().trim(), name_en: forceFromArabic ? '' : getEn().trim() });
            if (!res || !res.success) { return; }
            var t = res.translations || {};
            if (t.name_en) { setEn(t.name_en); }
            if (t.name_fil) { setFil(t.name_fil); }
            if (t.name_hi) { setHi(t.name_hi); }
        } catch (e) { /* silent */ }
    }

    function bindLabelTranslate(arEl, enEl, filEl, hiEl) {
        if (!arEl || !enEl || arEl.dataset.trBound === '1') { return; }
        arEl.dataset.trBound = '1';
        var run = function (force) {
            return translateRow(
                function () { return arEl.value; }, function () { return enEl.value; },
                function (v) { enEl.value = v; }, function (v) { if (filEl) { filEl.value = v; } }, function (v) { if (hiEl) { hiEl.value = v; } },
                force, true
            );
        };
        arEl.addEventListener('input', function () {
            if (!arEl.value.trim()) { enEl.value = ''; if (filEl) { filEl.value = ''; } if (hiEl) { hiEl.value = ''; } return; }
            clearTimeout(arEl._t); arEl._t = setTimeout(function () { run(true); }, 650);
        });
        enEl.addEventListener('input', function () {
            if (!enEl.value.trim()) { return; }
            clearTimeout(enEl._t); enEl._t = setTimeout(function () { run(false); }, 580);
        });
    }

    function colsBody(panel) { return panel.querySelector('.asg-cols-body'); }
    function rowsBox(panel) { return panel.querySelector('.asg-rows-box'); }

    function createColumnRow(sortOrder) {
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><input type="number" class="asg-c-sort" value="' + sortOrder + '" style="width:4rem;"></td>' +
            '<td><input type="text" class="asg-c-ar" maxlength="191"></td>' +
            '<td><input type="text" class="asg-c-en" maxlength="191"></td>' +
            '<td><input type="text" class="asg-c-fil" maxlength="191"></td>' +
            '<td><input type="text" class="asg-c-hi" maxlength="191"></td>' +
            '<td><select class="asg-c-vk"><option value="text">نص</option><option value="number">رقم</option></select></td>' +
            '<td><input type="text" class="asg-c-unit" maxlength="64" placeholder="cm"></td>' +
            '<td><select class="asg-c-stor"><option value="">—</option><option value="length_cm">قياس بالسم (عرض cm/inch)</option></select></td>' +
            '<td><input type="text" class="asg-c-dsys" maxlength="32" placeholder="فارغ=عام" style="width:7rem;"></td>';
        tr.querySelector('.asg-c-stor').addEventListener('change', function () {
            if (tr.querySelector('.asg-c-stor').value === 'length_cm') { tr.querySelector('.asg-c-vk').value = 'number'; }
        });
        bindLabelTranslate(tr.querySelector('.asg-c-ar'), tr.querySelector('.asg-c-en'), tr.querySelector('.asg-c-fil'), tr.querySelector('.asg-c-hi'));
        return tr;
    }

    function genColRows(panel, n) {
        var tb = colsBody(panel);
        tb.innerHTML = '';
        for (var i = 0; i < n; i++) { tb.appendChild(createColumnRow(i + 1)); }
        panel.querySelector('.asg-col-count').value = String(Math.max(1, n));
        syncDataCells(panel);
    }
    function appendColumnDef(panel) {
        var tb = colsBody(panel);
        var n = tb.querySelectorAll('tr').length;
        if (n >= 24) { alert('الحد الأقصى 24 عموداً'); return; }
        tb.appendChild(createColumnRow(n + 1));
        panel.querySelector('.asg-col-count').value = String(n + 1);
        syncDataCells(panel);
    }
    function removeLastColumnDef(panel) {
        var trs = colsBody(panel).querySelectorAll('tr');
        if (trs.length <= 1) { alert('يجب أن يبقى عمود واحد على الأقل'); return; }
        trs[trs.length - 1].remove();
        panel.querySelector('.asg-col-count').value = String(trs.length - 1);
        syncDataCells(panel);
    }

    function readColumns(panel) {
        var out = [];
        var trs = colsBody(panel).querySelectorAll('tr');
        for (var i = 0; i < trs.length; i++) {
            var tr = trs[i];
            out.push({
                sort_order: parseInt(tr.querySelector('.asg-c-sort').value, 10) || (i + 1),
                label_ar: tr.querySelector('.asg-c-ar').value.trim(),
                label_en: tr.querySelector('.asg-c-en').value.trim(),
                label_fil: tr.querySelector('.asg-c-fil').value.trim(),
                label_hi: tr.querySelector('.asg-c-hi').value.trim(),
                value_kind: tr.querySelector('.asg-c-vk').value,
                unit_hint: tr.querySelector('.asg-c-unit').value.trim(),
                storage_measure: tr.querySelector('.asg-c-stor').value,
                display_system: normSys(tr.querySelector('.asg-c-dsys').value)
            });
        }
        return out;
    }
    function fillColumns(panel, cols) {
        genColRows(panel, Math.max(1, cols.length));
        var trs = colsBody(panel).querySelectorAll('tr');
        for (var i = 0; i < cols.length && i < trs.length; i++) {
            var c = cols[i]; var tr = trs[i];
            tr.querySelector('.asg-c-sort').value = String(c.sort_order != null ? c.sort_order : (i + 1));
            tr.querySelector('.asg-c-ar').value = c.label_ar || '';
            tr.querySelector('.asg-c-en').value = c.label_en || '';
            tr.querySelector('.asg-c-fil').value = c.label_fil || '';
            tr.querySelector('.asg-c-hi').value = c.label_hi || '';
            tr.querySelector('.asg-c-vk').value = (c.value_kind === 'number') ? 'number' : 'text';
            tr.querySelector('.asg-c-unit').value = c.unit_hint || '';
            tr.querySelector('.asg-c-stor').value = (c.storage_measure === 'length_cm') ? 'length_cm' : '';
            tr.querySelector('.asg-c-dsys').value = normSys(c.display_system || '');
        }
    }

    function syncFirstCell(block) {
        if (!block || block.dataset.rowKind !== 'data') { return; }
        var sel = block.querySelector('.asg-sfs');
        var firstIn = block.querySelector('.asg-cell[data-ix="0"]');
        if (!firstIn) { return; }
        var sid = sel ? parseInt(sel.value, 10) || 0 : 0;
        if (sid > 0) {
            firstIn.value = familyLabelById(sid);
            firstIn.readOnly = true;
            firstIn.classList.add('asg-cell--from-family');
        } else {
            firstIn.readOnly = false;
            firstIn.classList.remove('asg-cell--from-family');
        }
    }

    function addDataRow(panel, prefill) {
        prefill = prefill || {};
        var cols = readColumns(panel);
        var n = Math.max(1, cols.length);
        var cells = prefill.cells || [];
        while (cells.length < n) { cells.push(''); }
        var sid = parseInt(prefill.size_family_size_id, 10) || 0;
        var div = document.createElement('div');
        div.className = 'asg-row-block card';
        div.dataset.rowKind = 'data';
        div.id = 'asg_r_' + (++rowSeq);
        var cellInputs = '';
        for (var j = 0; j < n; j++) {
            var lab = (cols[j] && (cols[j].label_ar || cols[j].label_en)) ? esc(cols[j].label_ar || cols[j].label_en) : ('عمود ' + (j + 1));
            cellInputs += '<div><label>' + lab + '</label><input type="text" class="asg-cell" data-ix="' + j + '" value="' + esc(cells[j]) + '"></div>';
        }
        div.innerHTML =
            '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">' +
            '<strong>صف بيانات</strong><button type="button" class="btn-secondary asg-rm">حذف الصف</button></div>' +
            '<div style="margin-top:8px;"><label>المقاس <span style="color:#b91c1c;">*</span></label>' +
            '<select class="asg-sfs">' + sizeOptionsHtml(sid) + '</select></div>' +
            '<div class="form-grid" style="margin-top:10px;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));">' + cellInputs + '</div>';
        div.querySelector('.asg-rm').onclick = function () { div.remove(); };
        div.querySelector('.asg-sfs').addEventListener('change', function () { syncFirstCell(div); });
        rowsBox(panel).appendChild(div);
        syncFirstCell(div);
    }

    function addLabelRow(panel, prefill) {
        prefill = prefill || {};
        var div = document.createElement('div');
        div.className = 'asg-row-block card';
        div.dataset.rowKind = 'label';
        div.style.borderInlineStart = '4px solid #0ea5e9';
        div.innerHTML =
            '<div style="display:flex;justify-content:space-between;align-items:center;">' +
            '<strong>صف عنوان</strong><button type="button" class="btn-secondary asg-rm">حذف</button></div>' +
            '<div class="form-grid" style="margin-top:8px;">' +
            '<div><label>عربي</label><input type="text" class="asg-l-ar" maxlength="191" value="' + esc(prefill.label_ar) + '"></div>' +
            '<div><label>EN</label><input type="text" class="asg-l-en" maxlength="191" value="' + esc(prefill.label_en) + '"></div>' +
            '<div><label>Fil</label><input type="text" class="asg-l-fil" maxlength="191" value="' + esc(prefill.label_fil) + '"></div>' +
            '<div><label>Hi</label><input type="text" class="asg-l-hi" maxlength="191" value="' + esc(prefill.label_hi) + '"></div></div>';
        div.querySelector('.asg-rm').onclick = function () { div.remove(); };
        bindLabelTranslate(div.querySelector('.asg-l-ar'), div.querySelector('.asg-l-en'), div.querySelector('.asg-l-fil'), div.querySelector('.asg-l-hi'));
        rowsBox(panel).appendChild(div);
    }

    function syncDataCells(panel) {
        var n = Math.max(1, colsBody(panel).querySelectorAll('tr').length);
        var cols = readColumns(panel);
        rowsBox(panel).querySelectorAll('.asg-row-block[data-row-kind="data"]').forEach(function (block) {
            var grid = block.querySelector('.form-grid');
            if (!grid) { return; }
            var cells = grid.querySelectorAll('.asg-cell');
            while (cells.length > n) { if (grid.lastElementChild) { grid.lastElementChild.remove(); } cells = grid.querySelectorAll('.asg-cell'); }
            while (cells.length < n) {
                var j = cells.length;
                var lab = (cols[j] && (cols[j].label_ar || cols[j].label_en)) ? esc(cols[j].label_ar || cols[j].label_en) : ('عمود ' + (j + 1));
                var wrap = document.createElement('div');
                wrap.innerHTML = '<div><label>' + lab + '</label><input type="text" class="asg-cell" data-ix="' + j + '" value=""></div>';
                if (wrap.firstElementChild) { grid.appendChild(wrap.firstElementChild); }
                cells = grid.querySelectorAll('.asg-cell');
            }
            var ins = grid.querySelectorAll('.asg-cell');
            for (var k = 0; k < ins.length; k++) { ins[k].setAttribute('data-ix', String(k)); }
            syncFirstCell(block);
        });
    }

    function refreshSizeSelects(panel) {
        panel.querySelectorAll('.asg-sfs').forEach(function (sel) {
            var cur = parseInt(sel.value, 10) || 0;
            sel.innerHTML = sizeOptionsHtml(cur);
        });
        panel.querySelectorAll('.asg-row-block[data-row-kind="data"]').forEach(syncFirstCell);
    }

    function bulkRows(panel, silent) {
        if (famId() <= 0) { if (!silent) { alert('اختر عائلة المقاسات أولاً'); } return; }
        var fam = familySizeRows();
        if (!fam.length) { if (!silent) { alert('لا توجد مقاسات مسجّلة في هذه العائلة'); } return; }
        if (!colsBody(panel).querySelectorAll('tr').length) { if (!silent) { alert('عرّف الأعمدة أولاً'); } return; }
        var used = {};
        panel.querySelectorAll('.asg-row-block[data-row-kind="data"] .asg-sfs').forEach(function (s) {
            var v = parseInt(s.value, 10) || 0; if (v > 0) { used[v] = true; }
        });
        var added = 0;
        for (var i = 0; i < fam.length; i++) {
            var rid = parseInt(fam[i].id, 10) || 0;
            if (rid > 0 && !used[rid]) { addDataRow(panel, { size_family_size_id: rid }); added++; }
        }
        if (!added && !silent) { alert('كل المقاسات المسجّلة في العائلة مضافة بالفعل في هذا الجدول'); }
    }

    // أول عمود = عمود المقاس (يُملأ تلقائياً باسم المقاس المسجّل في العائلة: Alpha = S/M/L).
    function prefillSizeColumn(panel) {
        var firstTr = colsBody(panel).querySelector('tr');
        if (!firstTr) { return; }
        var pairs = [['.asg-c-ar', 'المقاس'], ['.asg-c-en', 'Size'], ['.asg-c-fil', 'Sukat'], ['.asg-c-hi', 'साइज़']];
        for (var i = 0; i < pairs.length; i++) {
            var el = firstTr.querySelector(pairs[i][0]);
            if (el && el.value.trim() === '') { el.value = pairs[i][1]; }
        }
    }

    function wirePanel(panel) {
        // زر واحد غير هدّام: أول مرة يبني الأعمدة + كل مقاسات العائلة؛ على دليل محفوظ يضيف المقاسات الناقصة فقط دون مسح ما أُدخل.
        panel.querySelector('.asg-gen-cols').onclick = function () {
            if (famId() <= 0) { alert('اختر عائلة المقاسات أولاً'); return; }
            var hasCols = colsBody(panel).querySelectorAll('tr').length > 0;
            if (!hasCols) {
                var n = parseInt(panel.querySelector('.asg-col-count').value, 10) || 3;
                n = Math.min(24, Math.max(1, n));
                genColRows(panel, n);
                prefillSizeColumn(panel);
            }
            var before = panel.querySelectorAll('.asg-row-block[data-row-kind="data"]').length;
            bulkRows(panel, true);
            var after = panel.querySelectorAll('.asg-row-block[data-row-kind="data"]').length;
            if (hasCols) {
                if (after > before) {
                    alert('تمت إضافة ' + (after - before) + ' مقاس/مقاسات ناقصة من العائلة. أكمل القياسات ثم احفظ.');
                } else {
                    alert('لا مقاسات ناقصة — كل مقاسات العائلة موجودة في الجدول.');
                }
            }
        };
        panel.querySelector('.asg-col-add').onclick = function () { appendColumnDef(panel); };
        panel.querySelector('.asg-col-remove').onclick = function () { removeLastColumnDef(panel); };
        panel.querySelector('.asg-row-data').onclick = function () { addDataRow(panel, {}); };
        panel.querySelector('.asg-row-label').onclick = function () { addLabelRow(panel, {}); };
    }

    function buildPanels() {
        var host = document.getElementById('asg_panels');
        host.innerHTML = '';
        var tpl = document.getElementById('asg_panel_tpl');
        panelKinds().forEach(function (pk) {
            var node = tpl.content.firstElementChild.cloneNode(true);
            node.dataset.panel = pk;
            node.querySelector('.asg-panel-title').textContent = panelTitle(pk);
            host.appendChild(node);
            wirePanel(node);
            genColRows(node, 3);
        });
    }
    function getPanel(pk) { return document.querySelector('.asg-panel[data-panel="' + pk + '"]'); }
    function allPanels() { return Array.prototype.slice.call(document.querySelectorAll('.asg-panel')); }

    // ---- linking (اختيار بالنقر المزدوج: لا كتابة في الحقل؛ التحديد محفوظ في مجموعات مستقلة عن التصفية) ----
    var LINK_CACHE = { types: [], products: [] };
    var LINK_NAME = { types: {}, products: {} };   // id -> الاسم المعروض
    var LINK_SELECTED = { types: {}, products: {} }; // id -> true

    function selectedIds(name) {
        var out = [];
        Object.keys(LINK_SELECTED[name]).forEach(function (k) {
            if (LINK_SELECTED[name][k]) { var n = parseInt(k, 10) || 0; if (n > 0) { out.push(n); } }
        });
        return out;
    }
    function selectedTypeIds() { return selectedIds('types'); }
    function selectedProductIds() { return selectedIds('products'); }

    function updateChips(name) {
        var fieldId = (name === 'types') ? 'asg_pick_types' : 'asg_pick_products';
        var field = document.getElementById(fieldId);
        if (!field) { return; }
        var ids = selectedIds(name);
        if (!ids.length) {
            field.innerHTML = '<span class="asg-picker-placeholder">انقر مرتين للاختيار…</span>';
            return;
        }
        var h = '';
        ids.forEach(function (id) {
            var nm = LINK_NAME[name][id] || ('#' + id);
            h += '<span class="asg-chip"><span>' + esc(nm) + '</span><button type="button" data-rm="' + id + '" title="إزالة">×</button></span>';
        });
        field.innerHTML = h;
        field.querySelectorAll('button[data-rm]').forEach(function (b) {
            b.addEventListener('click', function (ev) {
                ev.stopPropagation();
                var v = b.getAttribute('data-rm');
                LINK_SELECTED[name][v] = false;
                var listId = (name === 'types') ? 'asg_link_types' : 'asg_link_products';
                var cb = document.querySelector('#' + listId + ' input[type="checkbox"][value="' + v + '"]');
                if (cb) { cb.checked = false; }
                updateChips(name);
            });
        });
    }

    function renderLinkTypes() {
        var box = document.getElementById('asg_link_types');
        if (!LINK_CACHE.types.length) { box.innerHTML = '<span class="card-hint">لا أنواع منتج مرشّحة لهذه العائلة/القسم.</span>'; updateChips('types'); return; }
        var h = '';
        LINK_CACHE.types.forEach(function (t) {
            var id = parseInt(t.id, 10) || 0;
            var nm = esc(t.name_ar || t.name_en || ('#' + id));
            var other = parseInt(t.default_advisory_sizing_guide_id, 10) || 0;
            var checked = LINK_SELECTED.types[id] ? ' checked' : '';
            var note = (other > 0 && !LINK_SELECTED.types[id]) ? ' <span class="card-hint">(مربوط بدليل آخر #' + other + ')</span>' : '';
            h += '<label><input type="checkbox" value="' + id + '"' + checked + '> ' + nm + note + '</label>';
        });
        box.innerHTML = h;
        updateChips('types');
    }
    function renderLinkProducts(filter) {
        var box = document.getElementById('asg_link_products');
        filter = (filter || '').trim().toLowerCase();
        if (!LINK_CACHE.products.length) { box.innerHTML = '<span class="card-hint">لا منتجات في هذه العائلة.</span>'; updateChips('products'); return; }
        var h = '';
        LINK_CACHE.products.forEach(function (p) {
            var id = parseInt(p.id, 10) || 0;
            var nm = (p.name_ar || p.name_en || ('#' + id));
            if (filter && nm.toLowerCase().indexOf(filter) === -1) { return; }
            var other = parseInt(p.sizing_advisory_guide_id, 10) || 0;
            var checked = LINK_SELECTED.products[id] ? ' checked' : '';
            var note = (other > 0 && !LINK_SELECTED.products[id]) ? ' <span class="card-hint">(دليل آخر #' + other + ')</span>' : '';
            h += '<label><input type="checkbox" value="' + id + '"' + checked + '> ' + esc(nm) + note + '</label>';
        });
        box.innerHTML = h || '<span class="card-hint">لا نتائج للتصفية.</span>';
        updateChips('products');
    }
    async function loadLinkTargets(preTypeIds, preProductIds) {
        LINK_SELECTED.types = {}; (preTypeIds || []).forEach(function (id) { LINK_SELECTED.types[parseInt(id, 10) || 0] = true; });
        LINK_SELECTED.products = {}; (preProductIds || []).forEach(function (id) { LINK_SELECTED.products[parseInt(id, 10) || 0] = true; });
        var f = famId();
        if (f <= 0) {
            LINK_CACHE.types = []; LINK_CACHE.products = [];
            LINK_NAME.types = {}; LINK_NAME.products = {};
            LINK_SELECTED.types = {}; LINK_SELECTED.products = {};
            document.getElementById('asg_link_types').innerHTML = '<span class="card-hint">اختر عائلة لعرض أنواع المنتج.</span>';
            document.getElementById('asg_link_products').innerHTML = '<span class="card-hint">اختر عائلة لعرض منتجاتها.</span>';
            updateChips('types'); updateChips('products');
            return;
        }
        var res = await post(ADVISORY_API, { action: 'list_link_targets', size_family_id: f, department_id: deptId() });
        if (!res || !res.success) { return; }
        LINK_CACHE.types = res.types || [];
        LINK_CACHE.products = res.products || [];
        LINK_NAME.types = {}; LINK_CACHE.types.forEach(function (t) { var id = parseInt(t.id, 10) || 0; LINK_NAME.types[id] = (t.name_ar || t.name_en || ('#' + id)); });
        LINK_NAME.products = {}; LINK_CACHE.products.forEach(function (p) { var id = parseInt(p.id, 10) || 0; LINK_NAME.products[id] = (p.name_ar || p.name_en || ('#' + id)); });
        renderLinkTypes();
        renderLinkProducts(document.getElementById('asg_link_products_search').value);
    }

    function closeAllPickers() {
        ['asg_panel_types', 'asg_panel_products'].forEach(function (id) {
            var p = document.getElementById(id);
            if (p) { p.setAttribute('hidden', ''); }
        });
    }
    function bindPicker(name) {
        var fieldId = (name === 'types') ? 'asg_pick_types' : 'asg_pick_products';
        var panelId = (name === 'types') ? 'asg_panel_types' : 'asg_panel_products';
        var listId = (name === 'types') ? 'asg_link_types' : 'asg_link_products';
        var field = document.getElementById(fieldId);
        var panel = document.getElementById(panelId);
        if (!field || !panel) { return; }
        field.addEventListener('dblclick', function () {
            var wasOpen = !panel.hasAttribute('hidden');
            closeAllPickers();
            if (!wasOpen) { panel.removeAttribute('hidden'); }
        });
        field.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                var wasOpen = !panel.hasAttribute('hidden');
                closeAllPickers();
                if (!wasOpen) { panel.removeAttribute('hidden'); }
            }
        });
        document.getElementById(listId).addEventListener('change', function (ev) {
            if (ev.target && ev.target.matches('input[type="checkbox"]')) {
                LINK_SELECTED[name][ev.target.value] = ev.target.checked;
                updateChips(name);
            }
        });
    }

    // ---- list ----
    function shapeLabel(lk) { return lk === 'dual' ? 'علوي + سفلي' : 'واحد'; }
    async function loadList() {
        var tb = document.getElementById('asg_list_tbody');
        var f = famId();
        if (f <= 0) { tb.innerHTML = '<tr><td colspan="9" class="card-hint">اختر عائلة المقاسات لعرض أدلتها.</td></tr>'; return; }
        var res = await post(ADVISORY_API, { action: 'list_by_family', size_family_id: f });
        if (!res || !res.success) { tb.innerHTML = '<tr><td colspan="9" class="alert-error">' + esc((res && res.message) || 'خطأ') + '</td></tr>'; return; }
        var guides = res.guides || [];
        if (!guides.length) { tb.innerHTML = '<tr><td colspan="9" class="card-hint">لا أدلة محفوظة لهذه العائلة بعد.</td></tr>'; return; }
        tb.innerHTML = '';
        guides.forEach(function (g) {
            var gid = parseInt(g.id, 10) || 0;
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td>' + gid + '</td>' +
                '<td>' + esc(g.name_ar || ('#' + gid)) + '</td>' +
                '<td>' + shapeLabel(g.layout_kind) + '</td>' +
                '<td>' + (parseInt(g.columns_count, 10) || 0) + '</td>' +
                '<td>' + (parseInt(g.rows_count, 10) || 0) + '</td>' +
                '<td>' + (parseInt(g.types_count, 10) || 0) + '</td>' +
                '<td>' + (parseInt(g.products_count, 10) || 0) + '</td>' +
                '<td>' + (parseInt(g.is_active, 10) ? 'نشط' : 'موقوف') + '</td>' +
                '<td><button type="button" class="btn-secondary asg-ed" data-id="' + gid + '">تعديل</button> ' +
                '<button type="button" class="btn-secondary asg-del" data-id="' + gid + '">حذف</button></td>';
            tr.querySelector('.asg-ed').onclick = function () { loadGuide(gid); };
            tr.querySelector('.asg-del').onclick = async function () {
                if (!confirm('حذف الدليل؟ سيُلغى ربطه بأنواع المنتج والمنتجات.')) { return; }
                var r2 = await post(ADVISORY_API, { action: 'delete', id: gid });
                if (!r2 || !r2.success) { alert((r2 && r2.message) || 'خطأ'); return; }
                loadList();
            };
            tb.appendChild(tr);
        });
    }

    function resetForm() {
        document.getElementById('asg_edit_id').value = '0';
        document.getElementById('asg_dept').value = '0';
        document.getElementById('asg_kind_filter').value = '';
        rebuildFamilyOptions();
        document.getElementById('asg_family').value = '0';
        document.getElementById('asg_name').value = '';
        document.getElementById('asg_active').value = '1';
        var single = document.querySelector('input[name="asg_shape"][value="single"]');
        if (single) { single.checked = true; }
        applyCascadeState();
        buildPanels();
        loadLinkTargets([], []);
        loadList();
    }

    async function loadGuide(id) {
        var res = await post(ADVISORY_API, { action: 'get', id: id });
        if (!res || !res.success) { alert((res && res.message) || 'خطأ'); return; }
        var g = res.guide;
        document.getElementById('asg_edit_id').value = String(g.id);
        var fam = parseInt(g.size_family_id, 10) || 0;
        var dep = parseInt(g.department_id, 10) || 0;
        document.getElementById('asg_dept').value = String(dep);
        // عند التعديل: اشتق «نوع المقاس» من العائلة المحفوظة كي يتسق التدرّج، ثم فعّل الحقول.
        var famObj = null;
        for (var fi = 0; fi < FAMILIES.length; fi++) {
            if ((parseInt(FAMILIES[fi].id, 10) || 0) === fam) { famObj = FAMILIES[fi]; break; }
        }
        var derivedKind = famObj ? String(famObj.commercial_kind_key || '') : '';
        document.getElementById('asg_kind_filter').value = derivedKind;
        rebuildFamilyOptions();
        var famSel = document.getElementById('asg_family');
        var hasOpt = false;
        for (var oi = 0; oi < famSel.options.length; oi++) {
            if ((parseInt(famSel.options[oi].value, 10) || 0) === fam) { hasOpt = true; break; }
        }
        if (!hasOpt && famObj) {
            var o = document.createElement('option');
            o.value = String(fam);
            o.textContent = (famObj.name_ar || famObj.name_en || ('#' + fam));
            famSel.appendChild(o);
        }
        famSel.value = String(fam);
        // التعديل يسمح بإظهار/تغيير الحقلين بصرف النظر عن التدرّج.
        document.getElementById('asg_kind_filter').disabled = false;
        famSel.disabled = false;
        document.getElementById('asg_name').value = g.name_ar || '';
        document.getElementById('asg_active').value = String(parseInt(g.is_active, 10) ? 1 : 0);
        var lk = (g.layout_kind === 'dual') ? 'dual' : 'single';
        var rb = document.querySelector('input[name="asg_shape"][value="' + lk + '"]');
        if (rb) { rb.checked = true; }
        buildPanels();
        var pks = panelKinds();
        var colsByPanel = {}; var rowsByPanel = {};
        pks.forEach(function (pk) { colsByPanel[pk] = []; rowsByPanel[pk] = []; });
        (res.columns || []).forEach(function (c) {
            var pk = (lk === 'dual') ? (c.panel_kind === 'lower' ? 'lower' : 'upper') : 'single';
            if (colsByPanel[pk]) { colsByPanel[pk].push(c); }
        });
        (res.rows || []).forEach(function (r) {
            var pk = (lk === 'dual') ? (r.panel_kind === 'lower' ? 'lower' : 'upper') : 'single';
            if (rowsByPanel[pk]) { rowsByPanel[pk].push(r); }
        });
        pks.forEach(function (pk) {
            var panel = getPanel(pk);
            if (!panel) { return; }
            if (colsByPanel[pk].length) { fillColumns(panel, colsByPanel[pk]); }
            rowsBox(panel).innerHTML = '';
            rowsByPanel[pk].forEach(function (r) {
                if (r.row_kind === 'label') { addLabelRow(panel, r); }
                else { addDataRow(panel, { cells: r.cells || [], size_family_size_id: r.size_family_size_id }); }
            });
            refreshSizeSelects(panel);
        });
        await loadList();
        await loadLinkTargets(res.linked_product_type_ids || [], res.linked_product_ids || []);
        document.querySelector('.page-title').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function collectPayload() {
        var columns = []; var rows = [];
        var err = '';
        allPanels().forEach(function (panel) {
            if (err) { return; }
            var pk = panel.dataset.panel;
            var cols = readColumns(panel);
            for (var i = 0; i < cols.length; i++) {
                var c = cols[i];
                if (!c.label_ar || !c.label_en || !c.label_fil || !c.label_hi) {
                    err = 'في ' + panelTitle(pk) + ': أكمل عربي/EN/Fil/Hi لكل عمود (الصف ' + (i + 1) + ').'; return;
                }
                c.panel_kind = pk; columns.push(c);
            }
            var blocks = panel.querySelectorAll('.asg-rows-box .asg-row-block');
            var dataCount = 0; var so = 0;
            blocks.forEach(function (b) {
                if (err) { return; }
                so++;
                if (b.dataset.rowKind === 'label') {
                    rows.push({
                        panel_kind: pk, row_kind: 'label', sort_order: so,
                        label_ar: b.querySelector('.asg-l-ar').value.trim(),
                        label_en: b.querySelector('.asg-l-en').value.trim(),
                        label_fil: b.querySelector('.asg-l-fil').value.trim(),
                        label_hi: b.querySelector('.asg-l-hi').value.trim(),
                        cells: []
                    });
                    return;
                }
                dataCount++;
                var sid = parseInt(b.querySelector('.asg-sfs').value, 10) || 0;
                if (sid <= 0) { err = 'في ' + panelTitle(pk) + ': صف البيانات رقم ' + dataCount + ' بلا مقاس.'; return; }
                var ins = b.querySelectorAll('.asg-cell');
                var cells = [];
                for (var j = 0; j < ins.length; j++) { cells.push(ins[j].value); }
                if (cells.length > 0) { cells[0] = ''; }
                rows.push({ panel_kind: pk, row_kind: 'data', sort_order: so, size_family_size_id: sid, cells: cells });
            });
            if (dataCount === 0) { err = 'في ' + panelTitle(pk) + ': أضف صف بيانات واحداً على الأقل.'; }
        });
        return { columns: columns, rows: rows, err: err };
    }

    document.getElementById('asg_save_btn').onclick = async function () {
        if (deptId() <= 0) { alert('اختر القسم الرئيسي.'); return; }
        if (kindFilter() === '') { alert('اختر نوع المقاس.'); return; }
        if (famId() <= 0) { alert('اختر عائلة المقاسات.'); return; }
        if (document.getElementById('asg_name').value.trim() === '') { alert('الاسم الداخلي إلزامي.'); return; }
        var pr = collectPayload();
        if (pr.err) { alert(pr.err); return; }
        var payload = {
            action: 'save',
            id: editId(),
            size_family_id: famId(),
            department_id: deptId(),
            layout_kind: shape(),
            name_ar: document.getElementById('asg_name').value.trim(),
            is_active: parseInt(document.getElementById('asg_active').value, 10),
            columns: pr.columns,
            rows: pr.rows,
            link_product_type_ids: selectedTypeIds(),
            link_product_ids: selectedProductIds()
        };
        var res = await post(ADVISORY_API, payload);
        if (!res || !res.success) { alert((res && res.message) || 'خطأ'); return; }
        alert('تم الحفظ');
        resetForm();
        await loadList();
    };

    document.getElementById('asg_reset_btn').onclick = function () { resetForm(); };

    // ---- فلترة العائلات حسب نوعها هي (النوع التجاري) — لا علاقة لها بالقسم ----
    function kindFilter() { return String((document.getElementById('asg_kind_filter') || {}).value || ''); }

    function rebuildKindFilter() {
        var sel = document.getElementById('asg_kind_filter');
        if (!sel) { return; }
        var prev = sel.value;
        var seen = {}, kinds = [];
        FAMILIES.forEach(function (f) {
            var ck = String(f.commercial_kind_key || '');
            if (ck !== '' && !seen[ck]) { seen[ck] = true; kinds.push(ck); }
        });
        var html = '<option value="">— اختر —</option>';
        kinds.forEach(function (ck) {
            html += '<option value="' + esc(ck) + '">' + esc(KIND_LABELS[ck] || ck) + '</option>';
        });
        sel.innerHTML = html;
        sel.value = (prev && seen[prev]) ? prev : '';
    }
    function familyAllowed(fam) {
        var ck = kindFilter();
        if (ck !== '' && String(fam.commercial_kind_key || '') !== ck) { return false; }
        return true;
    }
    function rebuildFamilyOptions() {
        var sel = document.getElementById('asg_family');
        var prev = parseInt(sel.value, 10) || 0;
        var html = '<option value="0">— اختر —</option>';
        var keepPrev = false;
        FAMILIES.forEach(function (f) {
            if (!familyAllowed(f)) { return; }
            var id = parseInt(f.id, 10) || 0;
            if (id === prev) { keepPrev = true; }
            html += '<option value="' + id + '">' + esc(f.name_ar || f.name_en || ('#' + id)) + '</option>';
        });
        sel.innerHTML = html;
        sel.value = keepPrev ? String(prev) : '0';
    }

    // تفعيل تدرّجي: القسم يفعّل «نوع المقاس»، ونوع المقاس يفعّل «عائلة المقاسات».
    function applyCascadeState() {
        var d = deptId();
        var kindSel = document.getElementById('asg_kind_filter');
        var famSel = document.getElementById('asg_family');
        kindSel.disabled = !(d > 0);
        famSel.disabled = !(d > 0 && kindFilter() !== '');
    }

    function onFamilyChange() {
        allPanels().forEach(refreshSizeSelects);
        loadList();
        loadLinkTargets(selectedTypeIds(), selectedProductIds());
    }
    document.getElementById('asg_family').onchange = onFamilyChange;
    document.getElementById('asg_dept').onchange = function () {
        if (deptId() <= 0) {
            document.getElementById('asg_kind_filter').value = '';
            document.getElementById('asg_family').value = '0';
            rebuildFamilyOptions();
            applyCascadeState();
            onFamilyChange();
            return;
        }
        applyCascadeState();
        loadLinkTargets(selectedTypeIds(), selectedProductIds());
    };
    document.getElementById('asg_kind_filter').addEventListener('change', function () {
        document.getElementById('asg_family').value = '0';
        rebuildFamilyOptions();
        applyCascadeState();
        onFamilyChange();
    });
    document.querySelectorAll('input[name="asg_shape"]').forEach(function (r) {
        r.addEventListener('change', function () {
            if (document.querySelector('.asg-rows-box .asg-row-block') && !confirm('تغيير الشكل سيعيد بناء الجداول ويمسح ما أدخلته. المتابعة؟')) {
                var cur = (document.querySelectorAll('.asg-panel').length > 1) ? 'dual' : 'single';
                var back = document.querySelector('input[name="asg_shape"][value="' + cur + '"]');
                if (back) { back.checked = true; }
                return;
            }
            buildPanels();
        });
    });

    function boot() {
        buildPanels();
        rebuildKindFilter();
        rebuildFamilyOptions();
        applyCascadeState();
        bindPicker('types');
        bindPicker('products');
        document.addEventListener('click', function (ev) {
            if (!ev.target.closest('.asg-picker')) { closeAllPickers(); }
        });
        if (famId() > 0) { loadList(); loadLinkTargets([], []); }
        document.getElementById('asg_link_products_search').addEventListener('input', function () {
            renderLinkProducts(this.value);
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
</script>

<?php endif; ?>
