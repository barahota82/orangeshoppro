<?php

declare(strict_types=1);

$pdo = db();
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

orange_catalog_ensure_schema($pdo);

$tablesReady = orange_table_exists($pdo, 'size_scheme_templates')
    && orange_table_exists($pdo, 'size_scheme_template_sizes');

$templates = [];
$nextSort = 1;
if ($tablesReady) {
    try {
        $templates = $pdo->query(
            'SELECT t.*,
                (SELECT COUNT(*) FROM size_scheme_template_sizes s WHERE s.template_id = t.id) AS sizes_count
             FROM size_scheme_templates t
             ORDER BY t.sort_order ASC, t.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $nextSort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM size_scheme_templates')->fetchColumn();
        if ($nextSort <= 0) {
            $nextSort = 1;
        }
    } catch (Throwable $e) {
        $templates = [];
    }
}
?>
<div class="page-title">
    <h1>قوالب المقاسات</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (!$tablesReady): ?>
<div class="card">
    <div class="alert-error">جداول قوالب المقاسات غير جاهزة. حدّث الصفحة بعد تهيئة المخطّط.</div>
</div>
<?php else: ?>

<div class="card" id="sst_form_card" tabindex="-1">
    <h3>إضافة / تعديل قالب</h3>
    <style>
        /* صف واحد RTL: يمين ← يسار = ترتيب → EN (أساسي) → عربي (= نسخ EN مثل Fil) → Fil → Hi → نشط. */
        #sst_form_card .sst-header-row-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin-bottom: 2px;
        }
        #sst_form_card .sst-header-row {
            display: grid;
            /* ترتيب ضيق | أربعة أعمدة متساوية للأسماء | نشط ضيق جداً (نعم/لا) */
            grid-template-columns:
                minmax(3rem, 4.25rem)
                minmax(0, 1fr)
                minmax(0, 1fr)
                minmax(0, 1fr)
                minmax(0, 1fr)
                minmax(4.5rem, 5.5rem);
            column-gap: 12px;
            row-gap: 0;
            direction: rtl;
            align-items: stretch;
            justify-items: stretch;
            min-width: 0;
        }
        #sst_form_card .sst-header-row .admin-sort-field-wrap {
            min-width: 0;
            /* admin.css يفرض max-width:220px على .admin-sort-field-wrap فيكسر أعمدة grid 1fr */
            max-width: none;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 6px;
        }
        #sst_form_card .sst-header-row label {
            margin-bottom: 0;
            width: 100%;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            line-height: 1.25;
        }
        #sst_form_card .sst-header-row .admin-sort-field-wrap:first-child label {
            text-align: center;
        }
        #sst_form_card .sst-header-row .admin-sort-field-wrap:last-child label {
            text-align: center;
        }
        #sst_form_card .sst-header-row .admin-sort-field-wrap:not(:first-child):not(:last-child) label {
            text-align: right;
        }
        #sst_form_card .sst-header-row label,
        #sst_form_card .sst-header-row input,
        #sst_form_card .sst-header-row select {
            direction: rtl;
        }
        #sst_form_card .sst-header-row input.admin-sort-field,
        #sst_form_card .sst-header-row input[type="text"],
        #sst_form_card .sst-header-row select#sst_active.admin-sort-field {
            margin-inline: 0;
            margin-top: 0;
            display: block;
            width: 100%;
            max-width: 100%;
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
        }
        #sst_form_card .sst-header-row #sst_sort_display {
            text-align: center;
            font-weight: 600;
            cursor: default;
        }
        #sst_form_card .sst-header-row select#sst_active {
            text-align: right;
            -webkit-appearance: none;
            appearance: none;
            background-color: #fff;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M2.75 4.25L6 7.55l3.25-3.3.65.64L6 8.82 2.1 4.9l.65-.65z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-size: 12px;
            background-position: left 8px center;
            padding-inline-end: 26px;
            padding-inline-start: 10px;
        }
        /* حقول صف المقاسات: EN + العربي + Fil + Hi — العربي بنفس شكل Fil (muted + خلفية للقراءة فقط) */
        #sst_sizes_tbody input.sst-le,
        #sst_sizes_tbody input.sst-la.admin-sort-field,
        #sst_sizes_tbody input.sst-lf.admin-sort-field,
        #sst_sizes_tbody input.sst-lh.admin-sort-field {
            margin-inline: 0;
            margin-top: 0;
            display: block;
            width: 100%;
            max-width: 100%;
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
        }
        #sst_sizes_tbody input.sst-la[readonly],
        #sst_sizes_tbody input.sst-lf[readonly],
        #sst_sizes_tbody input.sst-lh[readonly] {
            background: #f4f6f9;
            cursor: default;
        }
    </style>
    <input type="hidden" id="sst_id" value="0">
    <div class="sst-header-row-wrap">
        <div class="sst-header-row">
            <div class="admin-sort-field-wrap">
                <label for="sst_sort_display">الترتيب</label>
                <input type="text" id="sst_sort_display" class="admin-sort-field admin-sort-field--muted" readonly tabindex="-1" value="<?php echo (int) $nextSort; ?>" title="ترتيب ظهور القالب في القائمة" aria-readonly="true" autocomplete="off" style="background:#f4f4f5;">
            </div>
            <div class="admin-sort-field-wrap">
                <label for="sst_name_en">English</label>
                <input type="text" id="sst_name_en" maxlength="191" autocomplete="off">
            </div>
            <div class="admin-sort-field-wrap">
                <label for="sst_name_ar">الاسم العربي</label>
                <input type="text" id="sst_name_ar" class="admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" autocomplete="off" title="يُملأ تلقائياً بنفس نص الإنجليزي">
            </div>
            <div class="admin-sort-field-wrap">
                <label for="sst_name_fil">Filipino</label>
                <input type="text" id="sst_name_fil" class="admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" autocomplete="off" title="يُملأ تلقائياً بنفس نص الإنجليزي">
            </div>
            <div class="admin-sort-field-wrap">
                <label for="sst_name_hi">Hindi</label>
                <input type="text" id="sst_name_hi" class="admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" autocomplete="off" title="يُملأ تلقائياً بنفس نص الإنجليزي">
            </div>
            <div class="admin-sort-field-wrap">
                <label for="sst_active">نشط</label>
                <select id="sst_active" class="admin-sort-field">
                    <option value="1">نعم</option>
                    <option value="0">لا</option>
                </select>
            </div>
        </div>
    </div>
    <h4 style="margin:18px 0 8px;font-size:1rem;">مقاسات داخل القالب</h4>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:3.2rem;">ترتيب</th>
                    <th>EN</th>
                    <th>عربي</th>
                    <th>Fil</th>
                    <th>Hi</th>
                    <th>طول القدم (سم)</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="sst_sizes_tbody"></tbody>
        </table>
    </div>
    <div class="actions admin-actions--start" style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;">
        <button type="button" class="btn-secondary" onclick="sstResetForm()">جديد</button>
        <button type="button" onclick="sstAddSizeRow()">+ صف مقاس</button>
        <button type="button" class="btn-secondary" onclick="sstSyncAllDerivedFromEnglish()">ترجمة</button>
        <button type="button" onclick="sstSave()">حفظ القالب</button>
    </div>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;">قائمة القوالب</h3>
        <div class="actions admin-actions--start">
            <button type="button" class="btn-secondary" onclick="sstSaveTemplatesListOrder()">حفظ الترتيب</button>
        </div>
    </div>
    <div class="table-wrap cat-dep-list-wrap" data-list="sst-templates" style="margin-top:10px;">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>English</th>
                    <th>العربي</th>
                    <th>عدد المقاسات</th>
                    <th>الترتيب</th>
                    <th>الحالة</th>
                    <th class="pd-ops-col">إجراءات</th>
                </tr>
            </thead>
            <tbody id="orange-sst-templates-list-tbody">
                <?php foreach ($templates as $t): ?>
                <tr data-id="<?php echo (int) $t['id']; ?>">
                    <td><?php echo (int) $t['id']; ?></td>
                    <td><?php echo htmlspecialchars((string) $t['name_en'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) $t['name_ar'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($t['sizes_count'] ?? 0); ?></td>
                    <td><?php echo (int) $t['sort_order']; ?></td>
                    <td><?php echo (int) $t['is_active'] === 1 ? 'ظاهر' : 'مخفي'; ?></td>
                    <td class="pd-row-ops">
                        <div class="pd-ops-wrap">
                            <div class="pd-ops-arrows">
                                <button type="button" class="btn-secondary pd-btn-reorder" onclick="sstMoveTemplateListRow(this,'up')" aria-label="أعلى">↑</button>
                                <button type="button" class="btn-secondary pd-btn-reorder" onclick="sstMoveTemplateListRow(this,'down')" aria-label="أسفل">↓</button>
                            </div>
                            <div class="pd-ops-main">
                                <button type="button" class="btn-secondary" data-sst-edit="<?php echo (int) $t['id']; ?>">تعديل</button>
                                <button type="button" class="btn-danger" data-sst-del="<?php echo (int) $t['id']; ?>">حذف</button>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const SST_API = '/admin/api/size_scheme_templates/manage.php';
const SST_REORDER_API = '/admin/api/size_scheme_templates/reorder-save.php';
const SST_NEXT_SORT = <?php echo (int) $nextSort; ?>;
let sstHeaderEnTimer = null;

function sstMoveTemplateListRow(btn, dir) {
    var tr = btn.closest('tr');
    if (!tr) return;
    var tbody = document.getElementById('orange-sst-templates-list-tbody');
    if (!tbody) return;
    if (dir === 'up') {
        var prev = tr.previousElementSibling;
        if (prev) tbody.insertBefore(tr, prev);
    } else {
        var next = tr.nextElementSibling;
        if (next) tbody.insertBefore(next, tr);
    }
}

async function sstSaveTemplatesListOrder() {
    var tbody = document.getElementById('orange-sst-templates-list-tbody');
    if (!tbody) return;
    var ids = Array.from(tbody.querySelectorAll('tr[data-id]'))
        .map(function (tr) { return parseInt(tr.getAttribute('data-id') || '0', 10); })
        .filter(function (id) { return id > 0; });
    try {
        var res = await postJSON(SST_REORDER_API, { ordered_ids: ids });
        alert(res.message || (res.success ? 'تم حفظ الترتيب' : 'فشل حفظ الترتيب'));
        if (res.success) location.reload();
    } catch (e) {
        alert('فشل الاتصال بالخادم أثناء حفظ الترتيب');
    }
}

function sstEscapeAttr(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
}

function sstRefreshSizeRowOrder() {
    document.querySelectorAll('#sst_sizes_tbody tr.sst-size-row').forEach(function (tr, i) {
        var c = tr.querySelector('td.sst-ord');
        if (c) {
            c.textContent = String(i + 1);
        }
    });
}

function sstRemoveSizeRow(btn) {
    var tr = btn.closest ? btn.closest('tr.sst-size-row') : null;
    if (tr) {
        tr.remove();
    }
    sstRefreshSizeRowOrder();
}

function sstAddSizeRow() {
    var tb = document.getElementById('sst_sizes_tbody');
    if (!tb) return;
    var tr = document.createElement('tr');
    tr.className = 'sst-size-row';
    tr.innerHTML = '<td class="sst-ord">1</td>' +
        '<td><input type="text" class="sst-le admin-sort-field" maxlength="191" autocomplete="off"></td>' +
        '<td><input type="text" class="sst-la admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" placeholder="= EN" title="نسخة من عمود EN" autocomplete="off" aria-readonly="true"></td>' +
        '<td><input type="text" class="sst-lf admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" placeholder="= EN" title="نسخة من عمود EN" autocomplete="off" aria-readonly="true"></td>' +
        '<td><input type="text" class="sst-lh admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" placeholder="= EN" title="نسخة من عمود EN" autocomplete="off" aria-readonly="true"></td>' +
        '<td><input type="text" class="sst-fl" placeholder="اختياري"></td>' +
        '<td><button type="button" class="btn-secondary" onclick="sstRemoveSizeRow(this)">حذف الصف</button></td>';
    tb.appendChild(tr);
    sstRefreshSizeRowOrder();
}

function sstCollectSizes() {
    var rows = [];
    document.querySelectorAll('#sst_sizes_tbody tr.sst-size-row').forEach(function (tr, idx) {
        var la = tr.querySelector('.sst-la');
        var le = tr.querySelector('.sst-le');
        var lf = tr.querySelector('.sst-lf');
        var lh = tr.querySelector('.sst-lh');
        var fl = tr.querySelector('.sst-fl');
        var o = {
            label_ar: la ? String(la.value || '').trim() : '',
            label_en: le ? String(le.value || '').trim() : '',
            label_fil: lf ? String(lf.value || '').trim() : '',
            label_hi: lh ? String(lh.value || '').trim() : '',
            sort_order: idx + 1
        };
        var flv = fl ? String(fl.value || '').trim() : '';
        if (flv !== '') {
            o.foot_length_cm = flv;
        }
        var sid = parseInt(tr.getAttribute('data-sst-size-id') || '0', 10) || 0;
        if (sid > 0) {
            o.id = sid;
        }
        if (o.label_ar === '' && o.label_en === '') {
            return;
        }
        rows.push(o);
    });
    return rows;
}

function sstResetForm() {
    document.getElementById('sst_id').value = '0';
    document.getElementById('sst_name_ar').value = '';
    document.getElementById('sst_name_en').value = '';
    document.getElementById('sst_name_fil').value = '';
    document.getElementById('sst_name_hi').value = '';
    var nextSo = SST_NEXT_SORT || 1;
    var sortDisp = document.getElementById('sst_sort_display');
    if (sortDisp) {
        sortDisp.value = String(nextSo);
    }
    document.getElementById('sst_active').value = '1';
    var tb = document.getElementById('sst_sizes_tbody');
    if (tb) {
        tb.innerHTML = '';
    }
    sstAddSizeRow();
    sstSyncHeaderArFilHiFromEn();
}

/** عربي / Fil / Hi = نسخ حرفية لعمود الإنجليزي (مثل سلوك Fil السابق للعربي). */
function sstSyncHeaderArFilHiFromEn() {
    var enEl = document.getElementById('sst_name_en');
    var arEl = document.getElementById('sst_name_ar');
    var filEl = document.getElementById('sst_name_fil');
    var hiEl = document.getElementById('sst_name_hi');
    if (!enEl || !arEl || !filEl || !hiEl) {
        return;
    }
    var v = enEl.value;
    if (!String(v).trim()) {
        arEl.value = '';
        filEl.value = '';
        hiEl.value = '';
        return;
    }
    arEl.value = v;
    filEl.value = v;
    hiEl.value = v;
}

function sstSyncRowArFilHiFromEn(tr) {
    var le = tr.querySelector('.sst-le');
    var la = tr.querySelector('.sst-la');
    var lf = tr.querySelector('.sst-lf');
    var lh = tr.querySelector('.sst-lh');
    if (!le) {
        return;
    }
    var v = le.value;
    if (!String(v).trim()) {
        if (la) {
            la.value = '';
        }
        if (lf) {
            lf.value = '';
        }
        if (lh) {
            lh.value = '';
        }
        return;
    }
    if (la) {
        la.value = v;
    }
    if (lf) {
        lf.value = v;
    }
    if (lh) {
        lh.value = v;
    }
}

function scheduleSstHeaderFromEn() {
    clearTimeout(sstHeaderEnTimer);
    sstHeaderEnTimer = setTimeout(function () {
        sstSyncHeaderArFilHiFromEn();
    }, 600);
}

function scheduleSstRowFromEn(tr) {
    clearTimeout(tr._sstEnTimer);
    tr._sstEnTimer = setTimeout(function () {
        sstSyncRowArFilHiFromEn(tr);
    }, 550);
}

function sstSyncAllDerivedFromEnglish() {
    var en = document.getElementById('sst_name_en').value.trim();
    if (!en) {
        alert('أدخل English للقالب أولاً');
        return;
    }
    sstSyncHeaderArFilHiFromEn();
    document.querySelectorAll('#sst_sizes_tbody tr.sst-size-row').forEach(function (tr) {
        var le = tr.querySelector('.sst-le');
        if (le && String(le.value || '').trim()) {
            sstSyncRowArFilHiFromEn(tr);
        }
    });
}

async function sstSave() {
    var id = parseInt(document.getElementById('sst_id').value || '0', 10) || 0;
    var payload = {
        action: 'save',
        id: id,
        name_ar: document.getElementById('sst_name_ar').value.trim(),
        name_en: document.getElementById('sst_name_en').value.trim(),
        name_fil: document.getElementById('sst_name_fil').value.trim(),
        name_hi: document.getElementById('sst_name_hi').value.trim(),
        is_active: parseInt(document.getElementById('sst_active').value, 10),
        sizes: sstCollectSizes()
    };
    try {
        var res = await postJSON(SST_API, payload);
        alert(res.message || (res.success ? 'تم الحفظ' : 'فشل الحفظ'));
        if (res.success) {
            location.reload();
        }
    } catch (e) {
        alert('فشل الاتصال بالخادم');
    }
}

async function sstLoadOne(tplId) {
    try {
        var res = await postJSON(SST_API, { action: 'get', id: tplId });
        if (!res || !res.success || !res.template) {
            alert((res && res.message) ? res.message : 'تعذر تحميل القالب');
            return;
        }
        var t = res.template;
        document.getElementById('sst_id').value = String(t.id);
        document.getElementById('sst_name_ar').value = t.name_ar || '';
        document.getElementById('sst_name_en').value = t.name_en || '';
        document.getElementById('sst_name_fil').value = t.name_fil || '';
        document.getElementById('sst_name_hi').value = t.name_hi || '';
        var so = t.sort_order != null ? parseInt(String(t.sort_order), 10) : 0;
        if (!Number.isFinite(so) || so < 1) {
            so = 1;
        }
        var sortDisp = document.getElementById('sst_sort_display');
        if (sortDisp) {
            sortDisp.value = String(so);
        }
        document.getElementById('sst_active').value = (parseInt(t.is_active, 10) === 0 ? '0' : '1');
        var tb = document.getElementById('sst_sizes_tbody');
        tb.innerHTML = '';
        var sizes = res.sizes || [];
        if (!sizes.length) {
            sstAddSizeRow();
            sstSyncHeaderArFilHiFromEn();
        } else {
            sizes.forEach(function (r, idx) {
                var tr = document.createElement('tr');
                tr.className = 'sst-size-row';
                if (r.id != null && parseInt(String(r.id), 10) > 0) {
                    tr.setAttribute('data-sst-size-id', String(parseInt(String(r.id), 10)));
                }
                var fl = (r.foot_length_cm != null && r.foot_length_cm !== '') ? String(r.foot_length_cm) : '';
                tr.innerHTML = '<td class="sst-ord">' + String(idx + 1) + '</td>' +
                    '<td><input type="text" class="sst-le admin-sort-field" maxlength="191" autocomplete="off" value="' + sstEscapeAttr(r.label_en) + '"></td>' +
                    '<td><input type="text" class="sst-la admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" placeholder="= EN" title="نسخة من عمود EN" autocomplete="off" aria-readonly="true" value="' + sstEscapeAttr(r.label_ar) + '"></td>' +
                    '<td><input type="text" class="sst-lf admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" placeholder="= EN" title="نسخة من عمود EN" autocomplete="off" aria-readonly="true" value="' + sstEscapeAttr(r.label_fil) + '"></td>' +
                    '<td><input type="text" class="sst-lh admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" placeholder="= EN" title="نسخة من عمود EN" autocomplete="off" aria-readonly="true" value="' + sstEscapeAttr(r.label_hi) + '"></td>' +
                    '<td><input type="text" class="sst-fl" value="' + sstEscapeAttr(fl) + '"></td>' +
                    '<td><button type="button" class="btn-secondary" onclick="sstRemoveSizeRow(this)">حذف الصف</button></td>';
                tb.appendChild(tr);
                sstSyncRowArFilHiFromEn(tr);
            });
            sstRefreshSizeRowOrder();
        }
        sstSyncHeaderArFilHiFromEn();
        document.getElementById('sst_form_card').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) {
        alert('خطأ شبكة');
    }
}

(function () {
    var card = document.getElementById('sst_form_card');
    if (!card) {
        return;
    }
    card.addEventListener('input', function (ev) {
        var t = ev.target;
        if (!t) {
            return;
        }
        if (t.id === 'sst_name_en') {
            scheduleSstHeaderFromEn();
            return;
        }
        if (t.id === 'sst_name_ar' || t.id === 'sst_name_fil' || t.id === 'sst_name_hi') {
            return;
        }
        var tr = t.closest ? t.closest('tr.sst-size-row') : null;
        if (!tr) {
            return;
        }
        if (t.classList && t.classList.contains('sst-le')) {
            scheduleSstRowFromEn(tr);
        }
    });
})();

document.addEventListener('click', function (ev) {
    var ed = orangeAdminClosest(ev, '[data-sst-edit]');
    if (ed) {
        var id = parseInt(ed.getAttribute('data-sst-edit') || '0', 10);
        if (id > 0) {
            sstLoadOne(id);
        }
        return;
    }
    var del = orangeAdminClosest(ev, '[data-sst-del]');
    if (del) {
        var did = parseInt(del.getAttribute('data-sst-del') || '0', 10);
        if (did > 0 && confirm('حذف القالب وجميع مقاساته من القائمة المرجعية؟')) {
            postJSON(SST_API, { action: 'delete', id: did }).then(function (res) {
                alert(res.message || (res.success ? 'تم الحذف' : 'فشل الحذف'));
                if (res.success) {
                    location.reload();
                }
            }).catch(function () {
                alert('فشل الاتصال');
            });
        }
    }
});

(function () {
    var style = document.createElement('style');
    style.textContent =
        '.cat-dep-list-wrap[data-list="sst-templates"]{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:none;}' +
        '.cat-dep-list-wrap[data-list="sst-templates"] > table{min-width:860px;width:100%;border-collapse:collapse;table-layout:fixed;}' +
        '.cat-dep-list-wrap[data-list="sst-templates"] > table th,' +
        '.cat-dep-list-wrap[data-list="sst-templates"] > table td{vertical-align:middle;}' +
        '.cat-dep-list-wrap[data-list="sst-templates"] table .pd-ops-col,' +
        '.cat-dep-list-wrap[data-list="sst-templates"] table .pd-row-ops{width:200px !important;min-width:200px !important;max-width:200px !important;box-sizing:border-box !important;text-align:center !important;vertical-align:middle !important;padding:6px 8px !important;}' +
        '.cat-dep-list-wrap[data-list="sst-templates"] .pd-ops-wrap{display:grid;grid-template-columns:38px minmax(0,1fr);gap:8px;align-items:center;margin:0 auto;max-width:100%;direction:rtl;}' +
        '.cat-dep-list-wrap[data-list="sst-templates"] .pd-ops-arrows{display:flex;flex-direction:column;gap:4px;align-items:center;justify-content:center;}' +
        '.cat-dep-list-wrap[data-list="sst-templates"] .pd-ops-wrap button.pd-btn-reorder{width:32px !important;min-width:32px !important;height:28px !important;margin:0 !important;padding:0 !important;font-size:13px !important;line-height:1 !important;border-radius:6px !important;display:inline-flex !important;align-items:center;justify-content:center;}' +
        '.cat-dep-list-wrap[data-list="sst-templates"] .pd-ops-main{display:flex;flex-direction:column;gap:5px;min-width:0;}' +
        '.cat-dep-list-wrap[data-list="sst-templates"] .pd-ops-main .btn-secondary,' +
        '.cat-dep-list-wrap[data-list="sst-templates"] .pd-ops-main .btn-danger{width:100% !important;margin:0 !important;padding:6px 8px !important;font-size:12px !important;line-height:1.2 !important;border-radius:6px !important;box-sizing:border-box !important;min-height:30px !important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}';
    document.head.appendChild(style);
})();

sstResetForm();
</script>

<?php endif; ?>
