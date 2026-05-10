<?php

declare(strict_types=1);

$pdo = db();
require_once __DIR__ . '/../../includes/catalog_schema.php';

orange_catalog_ensure_schema($pdo);

$tablesReady = orange_table_exists($pdo, 'size_families')
    && orange_table_exists($pdo, 'size_family_sizes')
    && orange_table_exists($pdo, 'advisory_sizing_guides');

$families = [];
$sizesByFamily = [];
if ($tablesReady) {
    try {
        $families = $pdo->query('SELECT id, name_ar, name_en FROM size_families WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $sStmt = $pdo->query('SELECT id, size_family_id, label_ar, label_en, sort_order FROM size_family_sizes WHERE is_active = 1 ORDER BY size_family_id ASC, sort_order ASC, id ASC');
        foreach ($sStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $fid = (int) $s['size_family_id'];
            if (!isset($sizesByFamily[$fid])) {
                $sizesByFamily[$fid] = [];
            }
            $sizesByFamily[$fid][] = $s;
        }
    } catch (Throwable $e) {
        $families = [];
        $sizesByFamily = [];
    }
}

$sizesJson = json_encode($sizesByFamily, JSON_UNESCAPED_UNICODE);
if ($sizesJson === false) {
    $sizesJson = '{}';
}
?>
<div class="page-title">
    <h1>دليل المقاس الاسترشادي (عرض للعميل)</h1>
</div>

<?php if (!$tablesReady): ?>
<div class="card">
    <div class="alert-error">الجداول غير جاهزة. زر <strong>الأدمن</strong> أو أي صفحة كتالوج لتهيئة المخطّط، ثم حدّث.</div>
</div>
<?php else: ?>

<div class="card">
    <h3>اختيار عائلة المقاسات</h3>
    <div class="form-grid" style="max-width:720px;">
        <div>
            <label for="asg_family">عائلة المقاسات</label>
            <select id="asg_family">
                <option value="0">— اختر —</option>
                <?php foreach ($families as $f): ?>
                    <option value="<?php echo (int) $f['id']; ?>"><?php echo htmlspecialchars((string) ($f['name_ar'] ?: $f['name_en']), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="align-self:end;">
            <button type="button" class="btn" id="asg_load_btn">تحميل الأدلة</button>
            <button type="button" class="btn-secondary" id="asg_new_btn" style="margin-inline-start:8px;">دليل جديد</button>
        </div>
    </div>
    <div id="asg_list_wrap" style="margin-top:12px;display:none;">
        <h4>أدلة مرتبطة بالعائلة</h4>
        <ul id="asg_list" class="asg-guide-list"></ul>
    </div>
</div>

<div class="card" id="asg_editor" style="display:none;margin-top:16px;">
    <h3 id="asg_editor_title">تعديل دليل</h3>
    <input type="hidden" id="asg_edit_id" value="0">
    <div class="form-grid" style="max-width:900px;">
        <div>
            <label for="asg_scope">نوع النموذج</label>
            <select id="asg_scope">
                <option value="upper">علوي</option>
                <option value="lower">سفلي</option>
                <option value="single">مفرد (مثل حذاء)</option>
            </select>
        </div>
        <div>
            <label for="asg_active">نشط</label>
            <select id="asg_active">
                <option value="1">نعم</option>
                <option value="0">لا</option>
            </select>
        </div>
    </div>
    <p class="card-hint" style="margin:8px 0;">
        <strong>اسم داخلي (عربي فقط):</strong> للتمييز بين النماذج في لوحة التحكم فقط — <strong>لا يُعرض للعميل</strong>.
        عنوان الحوار للعميل ثابت من الترجمة (مثل «دليل المقاسات» / Size Guide). عند اختيار المنتج «علوي وسفلي» يظهر للعميل عنوان فرعي ثابت «مقاسات علوية» ثم «مقاسات سفلية» حسب الجدول.
    </p>
    <div class="form-grid" style="max-width:900px;">
        <div style="grid-column:1/-1;"><label for="asg_name_ar">اسم النموذج (داخلي — عربي فقط)</label><input type="text" id="asg_name_ar" maxlength="191" placeholder="مثال: علوي قمصان EU"></div>
    </div>

    <h4 style="margin-top:20px;">تعريف الأعمدة</h4>
    <p class="card-hint" style="margin:0 0 8px;">
        <strong>عمود النظام:</strong> كود إنجليزي صغير (حروف وأرقام وشرطة سفلية)، مثل <code>eu</code> أو <code>cn</code> — اتركه فارغاً لعمود يظهر دائماً مع أي نظام. لتسمية مخصّصة في المتجر أضف مفتاح ترجمة <code>sizing_display_system_الكود</code> في الإعدادات.
    </p>
    <p class="card-hint" style="margin:0 0 8px;">
        <strong>ترجمة تلقائية:</strong> بعد توقف الكتابة في <strong>عربي / EN</strong> لصفوف <strong>تعريف الأعمدة</strong> ولـ <strong>صف عنوان (مجموعة)</strong> يُستدعى نفس مسار الترجمة كبقية الأدمن (EN + Fil + Hi من العربي؛ وتحديث Fil + Hi من الإنجليزي). خلايا <strong>صف بيانات</strong> لا تُترجم تلقائياً (أرقام/نطاقات).
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:8px;">
        <div>
            <label for="asg_col_count">عدد الأعمدة</label>
            <input type="number" id="asg_col_count" min="1" max="24" value="3" style="width:5rem;">
        </div>
        <button type="button" class="btn-secondary" id="asg_gen_cols">توليد صفوف العناوين</button>
    </div>
    <div style="overflow-x:auto;">
        <table class="data-table" id="asg_cols_table">
            <thead><tr><th>ترتيب</th><th>عربي</th><th>EN</th><th>Fil</th><th>Hi</th><th>نوع القيمة</th><th>وحدة (عرض)</th><th>تخزين الطول</th><th>عمود النظام (كود)</th></tr></thead>
            <tbody id="asg_cols_body"></tbody>
        </table>
    </div>

    <h4 style="margin-top:20px;">صفوف الجدول</h4>
    <p class="card-hint" style="margin:0 0 8px;">
        <strong>أسهل تسجيل:</strong> بعد تعريف الأعمدة استخدم <strong>«إضافة صف لكل مقاس من العائلة»</strong> — يُنشئ صفاً لكل مقاس نشط ويربطه تلقائياً (بدون تكرار لنفس المقاس). خلّي <strong>أول عمود</strong> اسم المقاس (Alpha) و<strong>اترك خليته فاضية</strong> ليظهر للعميل من العائلة بلغته؛ ثم املأ باقي الأعمدة (EU، الصدر، …).
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;align-items:center;">
        <button type="button" class="btn-secondary" id="asg_row_data">+ صف بيانات</button>
        <button type="button" class="btn-secondary" id="asg_row_label">+ صف عنوان (مجموعة)</button>
        <button type="button" class="btn" id="asg_bulk_rows" title="يضيف صف بيانات لكل مقاس نشط في العائلة المختارة، مع ربط المقاس وتخطي المربوط مسبقاً">إضافة صف لكل مقاس من العائلة</button>
    </div>
    <div id="asg_rows_box"></div>

    <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn" id="asg_save_btn">حفظ</button>
        <button type="button" class="btn-secondary" id="asg_cancel_btn">إلغاء</button>
    </div>
</div>

<script>
(function () {
    var ADVISORY_API = '/admin/api/advisory_sizing_guides/manage.php';
    var FAMILY_SIZES = <?php echo $sizesJson; ?>;

    function fid() {
        return parseInt(document.getElementById('asg_family').value, 10) || 0;
    }

    function sizeOptionsHtml(selectedId) {
        var f = fid();
        var rows = FAMILY_SIZES[String(f)] || [];
        var h = '<option value="0">— بدون ربط / تسمية حرة —</option>';
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var id = parseInt(r.id, 10) || 0;
            var lab = (r.label_ar || r.label_en || '').replace(/</g, '');
            h += '<option value="' + id + '"' + (id === selectedId ? ' selected' : '') + '>' + lab + '</option>';
        }
        return h;
    }

    function esc(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
    }

    function asgNormalizeDisplaySystem(s) {
        s = String(s || '').toLowerCase().trim().replace(/[^a-z0-9_]/g, '');
        return s.length > 32 ? s.slice(0, 32) : s;
    }

    async function asgTranslateNamesInRow(tr, opts) {
        opts = opts || {};
        var silent = !!opts.silent;
        var forceFromArabic = !!opts.forceFromArabic;
        if (typeof postJSON !== 'function') {
            if (!silent) {
                alert('الترجمة غير جاهزة — انتظر اكتمال تحميل الصفحة ثم أعد المحاولة');
            }
            return;
        }
        var arEl = tr.querySelector('.asg-c-ar');
        var enEl = tr.querySelector('.asg-c-en');
        var filEl = tr.querySelector('.asg-c-fil');
        var hiEl = tr.querySelector('.asg-c-hi');
        if (!arEl || !enEl) {
            return;
        }
        try {
            var payload = {
                name_ar: arEl.value.trim(),
                name_en: forceFromArabic ? '' : enEl.value.trim()
            };
            var res = await postJSON('/admin/api/translate/names.php', payload);
            if (!res || !res.success) {
                if (!silent) {
                    alert((res && res.message) ? res.message : 'فشل الترجمة');
                }
                return;
            }
            var t = res.translations || {};
            if (t.name_en) {
                enEl.value = t.name_en;
            }
            if (t.name_fil && filEl) {
                filEl.value = t.name_fil;
            }
            if (t.name_hi && hiEl) {
                hiEl.value = t.name_hi;
            }
        } catch (e) {
            if (!silent) {
                alert('فشل طلب الترجمة من السيرفر');
            }
        }
    }

    function bindColRowAutoTranslate(tr) {
        var ar = tr.querySelector('.asg-c-ar');
        var en = tr.querySelector('.asg-c-en');
        if (!ar || !en || ar.dataset.asgTranslateBound === '1') {
            return;
        }
        ar.dataset.asgTranslateBound = '1';
        en.dataset.asgTranslateBound = '1';
        ar.addEventListener('input', function () {
            var t = ar.value.trim();
            if (!t) {
                tr.querySelector('.asg-c-en').value = '';
                tr.querySelector('.asg-c-fil').value = '';
                tr.querySelector('.asg-c-hi').value = '';
                return;
            }
            clearTimeout(ar._asgTrTimer);
            ar._asgTrTimer = setTimeout(function () {
                asgTranslateNamesInRow(tr, { silent: true, forceFromArabic: true });
            }, 650);
        });
        en.addEventListener('input', function () {
            var t = en.value.trim();
            if (!t) {
                return;
            }
            clearTimeout(en._asgTrTimer);
            en._asgTrTimer = setTimeout(function () {
                asgTranslateNamesInRow(tr, { silent: true, forceFromArabic: false });
            }, 580);
        });
    }

    async function asgTranslateNamesInLabelBlock(div, opts) {
        opts = opts || {};
        var silent = !!opts.silent;
        var forceFromArabic = !!opts.forceFromArabic;
        if (typeof postJSON !== 'function') {
            if (!silent) {
                alert('الترجمة غير جاهزة — انتظر اكتمال تحميل الصفحة ثم أعد المحاولة');
            }
            return;
        }
        var arEl = div.querySelector('.asg-l-ar');
        var enEl = div.querySelector('.asg-l-en');
        var filEl = div.querySelector('.asg-l-fil');
        var hiEl = div.querySelector('.asg-l-hi');
        if (!arEl || !enEl) {
            return;
        }
        try {
            var payload = {
                name_ar: arEl.value.trim(),
                name_en: forceFromArabic ? '' : enEl.value.trim()
            };
            var res = await postJSON('/admin/api/translate/names.php', payload);
            if (!res || !res.success) {
                if (!silent) {
                    alert((res && res.message) ? res.message : 'فشل الترجمة');
                }
                return;
            }
            var t = res.translations || {};
            if (t.name_en) {
                enEl.value = t.name_en;
            }
            if (t.name_fil && filEl) {
                filEl.value = t.name_fil;
            }
            if (t.name_hi && hiEl) {
                hiEl.value = t.name_hi;
            }
        } catch (e) {
            if (!silent) {
                alert('فشل طلب الترجمة من السيرفر');
            }
        }
    }

    function bindLabelRowAutoTranslate(div) {
        var ar = div.querySelector('.asg-l-ar');
        var en = div.querySelector('.asg-l-en');
        if (!ar || !en || ar.dataset.asgTranslateBound === '1') {
            return;
        }
        ar.dataset.asgTranslateBound = '1';
        en.dataset.asgTranslateBound = '1';
        ar.addEventListener('input', function () {
            var t = ar.value.trim();
            if (!t) {
                div.querySelector('.asg-l-en').value = '';
                div.querySelector('.asg-l-fil').value = '';
                div.querySelector('.asg-l-hi').value = '';
                return;
            }
            clearTimeout(ar._asgLblTimer);
            ar._asgLblTimer = setTimeout(function () {
                asgTranslateNamesInLabelBlock(div, { silent: true, forceFromArabic: true });
            }, 650);
        });
        en.addEventListener('input', function () {
            var t = en.value.trim();
            if (!t) {
                return;
            }
            clearTimeout(en._asgLblTimer);
            en._asgLblTimer = setTimeout(function () {
                asgTranslateNamesInLabelBlock(div, { silent: true, forceFromArabic: false });
            }, 580);
        });
    }

    function genColRows(n) {
        var tb = document.getElementById('asg_cols_body');
        tb.innerHTML = '';
        for (var i = 0; i < n; i++) {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td><input type="number" class="asg-c-sort" value="' + (i + 1) + '" style="width:4rem;"></td>' +
                '<td><input type="text" class="asg-c-ar" maxlength="191"></td>' +
                '<td><input type="text" class="asg-c-en" maxlength="191"></td>' +
                '<td><input type="text" class="asg-c-fil" maxlength="191"></td>' +
                '<td><input type="text" class="asg-c-hi" maxlength="191"></td>' +
                '<td><select class="asg-c-vk"><option value="text">نص</option><option value="number">رقم</option></select></td>' +
                '<td><input type="text" class="asg-c-unit" maxlength="64" placeholder="مثال cm"></td>' +
                '<td><select class="asg-c-stor" title="قيمة بالسم: رقم واحد أو نطاق مثل 84-88؛ العميل يحوّل cm/inch">' +
                '<option value="">—</option><option value="length_cm">قياس بالسم — رقم أو نطاق (عرض cm/inch)</option></select></td>' +
                '<td><input type="text" class="asg-c-dsys" maxlength="32" placeholder="فارغ=عام" title="كود إنجليزي صغير (مثل eu، cn) — يُجمّع أعمدة العرض للعميل" style="width:7rem;"></td>';
            tr.querySelector('.asg-c-stor').addEventListener('change', function () {
                var vk = tr.querySelector('.asg-c-vk');
                if (tr.querySelector('.asg-c-stor').value === 'length_cm') {
                    vk.value = 'number';
                }
            });
            bindColRowAutoTranslate(tr);
            tb.appendChild(tr);
        }
    }

    function readColumns() {
        var trs = document.querySelectorAll('#asg_cols_body tr');
        var out = [];
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
                display_system: asgNormalizeDisplaySystem(tr.querySelector('.asg-c-dsys').value)
            });
        }
        return out;
    }

    function fillColumns(cols) {
        document.getElementById('asg_col_count').value = String(Math.max(1, cols.length));
        genColRows(cols.length);
        var trs = document.querySelectorAll('#asg_cols_body tr');
        for (var i = 0; i < cols.length && i < trs.length; i++) {
            var c = cols[i];
            var tr = trs[i];
            tr.querySelector('.asg-c-sort').value = String(c.sort_order != null ? c.sort_order : (i + 1));
            tr.querySelector('.asg-c-ar').value = c.label_ar || '';
            tr.querySelector('.asg-c-en').value = c.label_en || '';
            tr.querySelector('.asg-c-fil').value = c.label_fil || '';
            tr.querySelector('.asg-c-hi').value = c.label_hi || '';
            tr.querySelector('.asg-c-vk').value = (c.value_kind === 'number') ? 'number' : 'text';
            tr.querySelector('.asg-c-unit').value = c.unit_hint || '';
            if (tr.querySelector('.asg-c-stor')) {
                tr.querySelector('.asg-c-stor').value = (c.storage_measure === 'length_cm') ? 'length_cm' : '';
            }
            if (tr.querySelector('.asg-c-dsys')) {
                tr.querySelector('.asg-c-dsys').value = asgNormalizeDisplaySystem(c.display_system || '');
            }
        }
    }

    var rowSeq = 0;

    function addDataRow(prefill) {
        prefill = prefill || {};
        var wrap = document.getElementById('asg_rows_box');
        var id = 'asg_r_' + (++rowSeq);
        var cols = readColumns();
        var n = Math.max(1, cols.length);
        var cells = prefill.cells || [];
        while (cells.length < n) cells.push('');
        var sid = parseInt(prefill.size_family_size_id, 10) || 0;
        var div = document.createElement('div');
        div.className = 'asg-row-block card';
        div.dataset.rowKind = 'data';
        div.id = id;
        var cellInputs = '';
        for (var j = 0; j < n; j++) {
            var lab = (cols[j] && (cols[j].label_ar || cols[j].label_en)) ? esc(cols[j].label_ar || cols[j].label_en) : ('عمود ' + (j + 1));
            cellInputs += '<div><label>' + lab + '</label><input type="text" class="asg-cell" data-ix="' + j + '" value="' + esc(cells[j]) + '"></div>';
        }
        div.innerHTML =
            '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">' +
            '<strong>صف بيانات</strong>' +
            '<button type="button" class="btn-secondary asg-rm">حذف الصف</button></div>' +
            '<div style="margin-top:8px;"><label>ربط بمقاس العائلة (اختياري)</label>' +
            '<select class="asg-sfs">' + sizeOptionsHtml(sid) + '</select></div>' +
            '<div class="form-grid" style="margin-top:10px;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));">' + cellInputs + '</div>';
        div.querySelector('.asg-rm').onclick = function () { div.remove(); };
        wrap.appendChild(div);
    }

    function addLabelRow(prefill) {
        prefill = prefill || {};
        var wrap = document.getElementById('asg_rows_box');
        var div = document.createElement('div');
        div.className = 'asg-row-block card';
        div.dataset.rowKind = 'label';
        div.style.borderLeft = '4px solid #0ea5e9';
        div.innerHTML =
            '<div style="display:flex;justify-content:space-between;align-items:center;">' +
            '<strong>صف عنوان</strong><button type="button" class="btn-secondary asg-rm">حذف</button></div>' +
            '<div class="form-grid" style="margin-top:8px;">' +
            '<div><label>عربي</label><input type="text" class="asg-l-ar" maxlength="191" value="' + esc(prefill.label_ar) + '"></div>' +
            '<div><label>EN</label><input type="text" class="asg-l-en" maxlength="191" value="' + esc(prefill.label_en) + '"></div>' +
            '<div><label>Fil</label><input type="text" class="asg-l-fil" maxlength="191" value="' + esc(prefill.label_fil) + '"></div>' +
            '<div><label>Hi</label><input type="text" class="asg-l-hi" maxlength="191" value="' + esc(prefill.label_hi) + '"></div></div>';
        div.querySelector('.asg-rm').onclick = function () { div.remove(); };
        bindLabelRowAutoTranslate(div);
        wrap.appendChild(div);
    }

    function clearRows() {
        document.getElementById('asg_rows_box').innerHTML = '';
    }

    function readRowsPayload() {
        var blocks = document.querySelectorAll('#asg_rows_box .asg-row-block');
        var rows = [];
        var b = 0;
        for (var i = 0; i < blocks.length; i++) {
            var el = blocks[i];
            var rk = el.dataset.rowKind === 'label' ? 'label' : 'data';
            b++;
            if (rk === 'label') {
                rows.push({
                    row_kind: 'label',
                    sort_order: b,
                    label_ar: el.querySelector('.asg-l-ar').value.trim(),
                    label_en: el.querySelector('.asg-l-en').value.trim(),
                    label_fil: el.querySelector('.asg-l-fil').value.trim(),
                    label_hi: el.querySelector('.asg-l-hi').value.trim(),
                    cells: []
                });
            } else {
                var sfs = parseInt(el.querySelector('.asg-sfs').value, 10) || 0;
                var ins = el.querySelectorAll('.asg-cell');
                var cells = [];
                for (var j = 0; j < ins.length; j++) {
                    cells.push(ins[j].value);
                }
                rows.push({
                    row_kind: 'data',
                    sort_order: b,
                    size_family_size_id: sfs,
                    cells: cells
                });
            }
        }
        return rows;
    }

    function refreshSizeSelects() {
        document.querySelectorAll('.asg-sfs').forEach(function (sel) {
            var cur = parseInt(sel.value, 10) || 0;
            sel.innerHTML = sizeOptionsHtml(cur);
        });
    }

    document.getElementById('asg_gen_cols').onclick = function () {
        var n = parseInt(document.getElementById('asg_col_count').value, 10) || 3;
        n = Math.min(24, Math.max(1, n));
        genColRows(n);
    };

    document.getElementById('asg_row_data').onclick = function () { addDataRow({}); refreshSizeSelects(); };
    document.getElementById('asg_row_label').onclick = function () { addLabelRow({}); };

    function asgCollectLinkedSizeIds() {
        var out = {};
        document.querySelectorAll('#asg_rows_box .asg-row-block').forEach(function (block) {
            if (block.dataset.rowKind !== 'data') {
                return;
            }
            var sel = block.querySelector('.asg-sfs');
            if (!sel) {
                return;
            }
            var v = parseInt(sel.value, 10) || 0;
            if (v > 0) {
                out[v] = true;
            }
        });
        return out;
    }

    document.getElementById('asg_bulk_rows').onclick = function () {
        var f = fid();
        if (f <= 0) {
            alert('اختر عائلة مقاسات أولاً من أعلى الصفحة');
            return;
        }
        var fam = FAMILY_SIZES[String(f)] || [];
        if (!fam.length) {
            alert('لا توجد مقاسات نشطة لهذه العائلة — راجع عائلات المقاسات');
            return;
        }
        var cols = readColumns();
        if (!cols.length) {
            alert('عرّف الأعمدة أولاً (عدد الأعمدة ثم توليد صفوف العناوين)');
            return;
        }
        var linked = asgCollectLinkedSizeIds();
        var toAdd = [];
        for (var i = 0; i < fam.length; i++) {
            var rid = parseInt(fam[i].id, 10) || 0;
            if (rid > 0 && !linked[rid]) {
                toAdd.push(rid);
            }
        }
        if (!toAdd.length) {
            alert('كل مقاسات العائلة لها صف مربوط بالفعل — لا يوجد جديد للإضافة');
            return;
        }
        if (!confirm('سيتم إضافة ' + toAdd.length + ' صف بيانات، كل صف مربوط بمقاس من العائلة. المتابعة؟')) {
            return;
        }
        var n = Math.max(1, cols.length);
        function emptyCells() {
            var a = [];
            for (var k = 0; k < n; k++) {
                a.push('');
            }
            return a;
        }
        for (var j = 0; j < toAdd.length; j++) {
            addDataRow({ cells: emptyCells(), size_family_size_id: toAdd[j] });
        }
        refreshSizeSelects();
    };

    async function loadList() {
        var f = fid();
        if (f <= 0) { alert('اختر عائلة'); return; }
        var res = await postJSON(ADVISORY_API, { action: 'list_by_family', size_family_id: f });
        if (!res.success) { alert(res.message || 'خطأ'); return; }
        var ul = document.getElementById('asg_list');
        ul.innerHTML = '';
        (res.guides || []).forEach(function (g) {
            var li = document.createElement('li');
            var sk = g.scope_kind || '';
            var title = (g.name_ar || g.name_en || '') + ' — ' + sk;
            li.innerHTML = esc(title) +
                ' <button type="button" class="btn-secondary asg-ed" data-id="' + g.id + '">تعديل</button>' +
                ' <button type="button" class="btn-secondary asg-del" data-id="' + g.id + '">حذف</button>';
            li.querySelector('.asg-ed').onclick = function () { loadGuide(parseInt(g.id, 10)); };
            li.querySelector('.asg-del').onclick = async function () {
                if (!confirm('حذف الدليل؟')) return;
                var r2 = await postJSON(ADVISORY_API, { action: 'delete', id: parseInt(g.id, 10) });
                if (!r2.success) { alert(r2.message || 'خطأ'); return; }
                loadList();
            };
            ul.appendChild(li);
        });
        document.getElementById('asg_list_wrap').style.display = 'block';
    }

    async function loadGuide(id) {
        var res = await postJSON(ADVISORY_API, { action: 'get', id: id });
        if (!res.success) { alert(res.message || 'خطأ'); return; }
        var g = res.guide;
        document.getElementById('asg_edit_id').value = String(g.id);
        document.getElementById('asg_scope').value = g.scope_kind || 'upper';
        document.getElementById('asg_active').value = String(parseInt(g.is_active, 10) ? 1 : 0);
        document.getElementById('asg_name_ar').value = g.name_ar || '';
        fillColumns(res.columns || []);
        clearRows();
        (res.rows || []).forEach(function (r) {
            if (r.row_kind === 'label') {
                addLabelRow(r);
            } else {
                addDataRow({ cells: r.cells || [], size_family_size_id: r.size_family_size_id });
            }
        });
        refreshSizeSelects();
        document.getElementById('asg_editor').style.display = 'block';
        document.getElementById('asg_editor_title').textContent = 'تعديل دليل #' + g.id;
        document.getElementById('asg_editor').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function openNew() {
        var f = fid();
        if (f <= 0) { alert('اختر عائلة أولاً'); return; }
        document.getElementById('asg_edit_id').value = '0';
        document.getElementById('asg_scope').value = 'upper';
        document.getElementById('asg_active').value = '1';
        document.getElementById('asg_name_ar').value = '';
        genColRows(3);
        clearRows();
        addDataRow({});
        refreshSizeSelects();
        document.getElementById('asg_editor').style.display = 'block';
        document.getElementById('asg_editor_title').textContent = 'دليل جديد';
    }

    document.getElementById('asg_load_btn').onclick = loadList;
    document.getElementById('asg_new_btn').onclick = openNew;

    document.getElementById('asg_family').onchange = function () {
        refreshSizeSelects();
    };

    document.getElementById('asg_save_btn').onclick = async function () {
        var f = fid();
        if (f <= 0) { alert('اختر عائلة'); return; }
        var payload = {
            action: 'save',
            id: parseInt(document.getElementById('asg_edit_id').value, 10) || 0,
            size_family_id: f,
            scope_kind: document.getElementById('asg_scope').value,
            name_ar: document.getElementById('asg_name_ar').value.trim(),
            is_active: parseInt(document.getElementById('asg_active').value, 10),
            columns: readColumns(),
            rows: readRowsPayload()
        };
        var res = await postJSON(ADVISORY_API, payload);
        if (!res.success) { alert(res.message || 'خطأ'); return; }
        alert('تم الحفظ');
        document.getElementById('asg_edit_id').value = String(res.id || payload.id);
        loadList();
    };

    document.getElementById('asg_cancel_btn').onclick = function () {
        document.getElementById('asg_editor').style.display = 'none';
    };

    genColRows(3);
})();
</script>
<style>
.asg-guide-list { list-style: none; padding: 0; margin: 0; }
.asg-guide-list li { margin: 8px 0; padding: 8px; background: #f8fafc; border-radius: 6px; }
.asg-row-block { margin-bottom: 10px; padding: 12px; }
</style>

<?php endif; ?>
