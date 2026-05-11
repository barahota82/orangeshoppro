<?php

declare(strict_types=1);

$pdo = db();
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/advisory_sizing_library.php';

orange_catalog_ensure_schema($pdo);

$tablesReady = orange_table_exists($pdo, 'size_families')
    && orange_table_exists($pdo, 'size_family_sizes')
    && orange_table_exists($pdo, 'advisory_sizing_guides');

$families = [];
$sizesByFamily = [];
$asgDepartments = [];
$asgTemplates = [];
$asgCommercialKinds = [];
$asgBundleScopes = [];
$asgLibraryReady = false;
if ($tablesReady) {
    try {
        $families = $pdo->query(
            'SELECT id, name_ar, name_en, commercial_kind_key, size_scheme_template_id
             FROM size_families WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $sStmt = $pdo->query(
            'SELECT id, size_family_id, label_ar, label_en, sort_order, scheme_template_size_id
             FROM size_family_sizes WHERE is_active = 1 ORDER BY size_family_id ASC, sort_order ASC, id ASC'
        );
        foreach ($sStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $fid = (int) $s['size_family_id'];
            if (!isset($sizesByFamily[$fid])) {
                $sizesByFamily[$fid] = [];
            }
            $sizesByFamily[$fid][] = $s;
        }
        if (orange_table_exists($pdo, 'departments')) {
            $asgDepartments = $pdo->query(
                'SELECT id, name_ar, name_en FROM departments WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if (orange_table_exists($pdo, 'size_scheme_templates')) {
            $asgTemplates = $pdo->query(
                'SELECT id, name_ar, name_en FROM size_scheme_templates WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if (orange_table_exists($pdo, 'commercial_kind_dictionary')) {
            $asgCommercialKinds = $pdo->query(
                'SELECT kind_key, label_ar, label_en FROM commercial_kind_dictionary WHERE is_active = 1 ORDER BY sort_order ASC, kind_key ASC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $asgLibraryReady = orange_advisory_sizing_library_tables_ready($pdo);
        if ($asgLibraryReady && orange_table_exists($pdo, 'advisory_sizing_library_bundles')) {
            $asgBundleScopes = $pdo->query(
                'SELECT department_id, size_scheme_template_id, commercial_kind_key, source_size_family_id
                 FROM advisory_sizing_library_bundles WHERE COALESCE(is_active, 1) = 1'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $families = [];
        $sizesByFamily = [];
        $asgDepartments = [];
        $asgTemplates = [];
        $asgCommercialKinds = [];
        $asgBundleScopes = [];
        $asgLibraryReady = false;
    }
}

$templateSizesByTpl = [];
if ($tablesReady && orange_table_exists($pdo, 'size_scheme_template_sizes')) {
    try {
        $tsStmt = $pdo->query(
            'SELECT id, template_id, label_ar, label_en, sort_order
             FROM size_scheme_template_sizes WHERE is_active = 1
             ORDER BY template_id ASC, sort_order ASC, id ASC'
        );
        foreach (($tsStmt ? $tsStmt->fetchAll(PDO::FETCH_ASSOC) : []) ?: [] as $tsRow) {
            $tid = (int) ($tsRow['template_id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            if (!isset($templateSizesByTpl[$tid])) {
                $templateSizesByTpl[$tid] = [];
            }
            $templateSizesByTpl[$tid][] = $tsRow;
        }
    } catch (Throwable $e) {
        $templateSizesByTpl = [];
    }
}

$prefSizeFamilyId = isset($_GET['size_family_id']) ? (int) $_GET['size_family_id'] : 0;
if ($prefSizeFamilyId > 0 && $tablesReady) {
    $foundPref = false;
    foreach ($families as $ff) {
        if ((int) ($ff['id'] ?? 0) === $prefSizeFamilyId) {
            $foundPref = true;
            break;
        }
    }
    if (!$foundPref) {
        $prefSizeFamilyId = 0;
    }
}

$sizesJson = json_encode($sizesByFamily, JSON_UNESCAPED_UNICODE);
if ($sizesJson === false) {
    $sizesJson = '{}';
}
$templateSizesJson = json_encode($templateSizesByTpl, JSON_UNESCAPED_UNICODE);
if ($templateSizesJson === false) {
    $templateSizesJson = '{}';
}
$asgJson = static function (array $rows): string {
    $j = json_encode($rows, JSON_UNESCAPED_UNICODE);

    return $j !== false ? $j : '[]';
};
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
    <h3>القسم والقالب والنوع التجاري</h3>
    <input type="hidden" id="asg_family" value="0">
    <div class="asg-wizard-toolbar" style="display:flex;flex-wrap:nowrap;align-items:flex-end;gap:12px;direction:ltr;justify-content:space-between;width:100%;box-sizing:border-box;margin-top:8px;">
        <div style="flex:0 0 auto;">
            <button type="button" class="btn" id="asg_new_btn" disabled>دليل جديد</button>
        </div>
        <div class="asg-wizard-fields" style="display:flex;flex-wrap:nowrap;flex:1 1 auto;gap:10px;align-items:flex-end;min-width:0;direction:rtl;justify-content:flex-end;overflow-x:auto;">
            <div style="flex:1 1 0;min-width:10rem;display:flex;flex-direction:column;gap:4px;">
                <label for="asg_w_dept" style="margin:0;font-size:13px;white-space:nowrap;"><strong>1.</strong> القسم الرئيسي</label>
                <select id="asg_w_dept" style="width:100%;min-width:0;"><option value="0">— الكل / بدون تصفية بالقسم —</option>
                    <?php foreach ($asgDepartments as $d): ?>
                    <option value="<?php echo (int) $d['id']; ?>"><?php echo htmlspecialchars((string) ($d['name_ar'] ?: $d['name_en']), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1 1 0;min-width:10rem;display:flex;flex-direction:column;gap:4px;">
                <label for="asg_w_tpl" style="margin:0;font-size:13px;white-space:nowrap;"><strong>2.</strong> قالب المقاسات</label>
                <select id="asg_w_tpl" style="width:100%;min-width:0;"><option value="0">— اختر —</option>
                    <?php foreach ($asgTemplates as $t): ?>
                    <option value="<?php echo (int) $t['id']; ?>"><?php echo htmlspecialchars((string) ($t['name_ar'] ?: $t['name_en']), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1 1 0;min-width:10rem;display:flex;flex-direction:column;gap:4px;">
                <label for="asg_w_ck" style="margin:0;font-size:13px;white-space:nowrap;"><strong>3.</strong> النوع التجاري (مستوى 1)</label>
                <select id="asg_w_ck" style="width:100%;min-width:0;"><option value="">— اختر —</option>
                    <?php foreach ($asgCommercialKinds as $k): ?>
                    <option value="<?php echo htmlspecialchars((string) $k['kind_key'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) ($k['label_ar'] ?: $k['label_en'] ?: $k['kind_key']), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    <p id="asg_w_hint" class="card-hint" style="margin:12px 0 0;"></p>
    <h4 style="margin:16px 0 8px;">الأدلة</h4>
    <div id="asg_list_wrap" style="display:none;">
        <ul id="asg_list" class="asg-guide-list"></ul>
    </div>
</div>

<div class="card" id="asg_editor" style="display:none;margin-top:16px;">
    <h3 id="asg_editor_title">تعديل دليل</h3>
    <input type="hidden" id="asg_edit_id" value="0">
    <input type="hidden" id="asg_scope" value="single">
    <input type="hidden" id="asg_active" value="1">
    <div class="form-grid" style="max-width:900px;">
        <div style="grid-column:1/-1;"><label for="asg_name_ar">اسم النموذج (داخلي — عربي فقط)</label><input type="text" id="asg_name_ar" maxlength="191" placeholder="مثال: علوي قمصان EU"></div>
    </div>

    <h4 style="margin-top:20px;">تعريف الأعمدة</h4>
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:8px;">
        <div>
            <label for="asg_col_count">عدد الأعمدة</label>
            <input type="number" id="asg_col_count" min="1" max="24" value="3" style="width:5rem;">
        </div>
        <button type="button" class="btn-secondary" id="asg_gen_cols">توليد صفوف العناوين</button>
        <button type="button" class="btn-secondary" id="asg_col_add" title="يضيف عموداً جديداً في النهاية ويضيف خلية فارغة لكل صف بيانات">+ عمود</button>
        <button type="button" class="btn-secondary" id="asg_col_remove" title="يحذف آخر عمود من التعريف وآخر خلية من كل صف بيانات">− حذف آخر عمود</button>
    </div>
    <div style="overflow-x:auto;">
        <table class="data-table" id="asg_cols_table">
            <thead><tr><th>ترتيب</th><th>عربي</th><th>EN</th><th>Fil</th><th>Hi</th><th>نوع القيمة</th><th>وحدة (عرض)</th><th>تخزين الطول</th><th>عمود النظام (كود)</th></tr></thead>
            <tbody id="asg_cols_body"></tbody>
        </table>
    </div>

    <h4 style="margin-top:20px;">صفوف الجدول</h4>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;align-items:center;">
        <button type="button" class="btn-secondary" id="asg_row_data">+ صف بيانات</button>
        <button type="button" class="btn-secondary" id="asg_row_label" title="سطر عنوان يظهر داخل الدليل للعميل — مفيد لو جدول واحد فيه أكثر من مجموعة أو عنوان فرعي؛ لجدول مسطح واحد غالباً لا تحتاجه">+ صف عنوان (مجموعة)</button>
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
    var PREF_FAMILY = <?php echo (int) $prefSizeFamilyId; ?>;
    var FAMILIES_FULL = <?php echo $asgJson($families); ?>;
    var BUNDLE_SCOPES = <?php echo $asgJson($asgBundleScopes); ?>;
    var ASG_LIBRARY_READY = <?php echo $asgLibraryReady ? 'true' : 'false'; ?>;
    var TEMPLATE_SIZE_ROWS = <?php echo $templateSizesJson; ?>;
    var asgPreferFamilyOnce = 0;

    async function orangeAdminJsonPost(url, payload) {
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
            try {
                return JSON.parse(text);
            } catch (e2) {
                return { success: false, message: 'رد السيرفر ليس JSON صالحاً.' };
            }
        } catch (e) {
            return { success: false, message: e.message || 'تعذّر الاتصال بالخادم' };
        }
    }

    function fid() {
        return parseInt(document.getElementById('asg_family').value, 10) || 0;
    }

    function wizardTplId() {
        return parseInt(document.getElementById('asg_w_tpl').value, 10) || 0;
    }

    function wizardCk() {
        return (document.getElementById('asg_w_ck').value || '').trim();
    }

    function wizardDeptId() {
        return parseInt(document.getElementById('asg_w_dept').value, 10) || 0;
    }

    function familyMatchesTplCk(famId, tpl, ck) {
        famId = parseInt(famId, 10) || 0;
        if (famId <= 0) {
            return false;
        }
        for (var i = 0; i < FAMILIES_FULL.length; i++) {
            if (parseInt(FAMILIES_FULL[i].id, 10) !== famId) {
                continue;
            }
            var fck = String(FAMILIES_FULL[i].commercial_kind_key || '').trim();
            var ftpl = parseInt(FAMILIES_FULL[i].size_scheme_template_id, 10) || 0;
            return fck === ck && ftpl === tpl;
        }
        return false;
    }

    function resolveWizardFamily(preferredId) {
        preferredId = parseInt(preferredId, 10) || 0;
        var tpl = wizardTplId();
        var ck = wizardCk();
        if (tpl <= 0 || ck === '') {
            return 0;
        }
        var dept = wizardDeptId();
        if (ASG_LIBRARY_READY && dept > 0 && BUNDLE_SCOPES && BUNDLE_SCOPES.length) {
            for (var bi = 0; bi < BUNDLE_SCOPES.length; bi++) {
                var b = BUNDLE_SCOPES[bi];
                var bd = parseInt(b.department_id, 10) || 0;
                var bt = parseInt(b.size_scheme_template_id, 10) || 0;
                var bck = String(b.commercial_kind_key || '').trim();
                if (bd !== dept || bt !== tpl || bck !== ck) {
                    continue;
                }
                var sid = parseInt(b.source_size_family_id, 10) || 0;
                if (sid > 0 && familyMatchesTplCk(sid, tpl, ck)) {
                    return sid;
                }
            }
        }
        if (preferredId > 0 && familyMatchesTplCk(preferredId, tpl, ck)) {
            return preferredId;
        }
        for (var j = 0; j < FAMILIES_FULL.length; j++) {
            var f = FAMILIES_FULL[j];
            var fid0 = parseInt(f.id, 10) || 0;
            if (fid0 <= 0) {
                continue;
            }
            if (String(f.commercial_kind_key || '').trim() !== ck) {
                continue;
            }
            if ((parseInt(f.size_scheme_template_id, 10) || 0) !== tpl) {
                continue;
            }
            return fid0;
        }
        return 0;
    }

    function effectiveFamilySizeRows() {
        var f = fid();
        var raw = FAMILY_SIZES[String(f)] || [];
        var tpl = wizardTplId();
        var tplList = [];
        if (TEMPLATE_SIZE_ROWS && typeof TEMPLATE_SIZE_ROWS === 'object') {
            tplList = TEMPLATE_SIZE_ROWS[String(tpl)] || [];
        }
        if (!tplList.length) {
            return raw.slice().sort(function (a, b) {
                return (parseInt(a.sort_order, 10) || 0) - (parseInt(b.sort_order, 10) || 0);
            });
        }
        var orderIdx = {};
        for (var li = 0; li < tplList.length; li++) {
            var tid = parseInt(tplList[li].id, 10) || 0;
            if (tid > 0) {
                orderIdx[tid] = li;
            }
        }
        return raw.slice().sort(function (a, b) {
            var ast = parseInt(a.scheme_template_size_id, 10) || 0;
            var bst = parseInt(b.scheme_template_size_id, 10) || 0;
            var ai = ast > 0 && Object.prototype.hasOwnProperty.call(orderIdx, ast) ? orderIdx[ast] : 9999;
            var bi = bst > 0 && Object.prototype.hasOwnProperty.call(orderIdx, bst) ? orderIdx[bst] : 9999;
            if (ai !== bi) {
                return ai - bi;
            }
            return (parseInt(a.sort_order, 10) || 0) - (parseInt(b.sort_order, 10) || 0);
        });
    }

    function applyWizardFieldsFromFamily(prefId) {
        prefId = parseInt(prefId, 10) || 0;
        if (prefId <= 0) {
            return;
        }
        var meta = null;
        for (var i = 0; i < FAMILIES_FULL.length; i++) {
            if (parseInt(FAMILIES_FULL[i].id, 10) === prefId) {
                meta = FAMILIES_FULL[i];
                break;
            }
        }
        if (!meta) {
            return;
        }
        var tplEl = document.getElementById('asg_w_tpl');
        var ckEl = document.getElementById('asg_w_ck');
        var deptEl = document.getElementById('asg_w_dept');
        if (tplEl) {
            tplEl.value = String(parseInt(meta.size_scheme_template_id, 10) || 0);
        }
        if (ckEl) {
            ckEl.value = String(meta.commercial_kind_key || '').trim();
        }
        if (deptEl && ASG_LIBRARY_READY && BUNDLE_SCOPES && BUNDLE_SCOPES.length) {
            var t = parseInt(meta.size_scheme_template_id, 10) || 0;
            var ck = String(meta.commercial_kind_key || '').trim();
            var foundDept = 0;
            for (var j = 0; j < BUNDLE_SCOPES.length; j++) {
                var bx = BUNDLE_SCOPES[j];
                if ((parseInt(bx.source_size_family_id, 10) || 0) === prefId
                    && (parseInt(bx.size_scheme_template_id, 10) || 0) === t
                    && String(bx.commercial_kind_key || '').trim() === ck) {
                    foundDept = parseInt(bx.department_id, 10) || 0;
                    if (foundDept > 0) {
                        break;
                    }
                }
            }
            if (foundDept > 0) {
                deptEl.value = String(foundDept);
            }
        }
    }

    function asgRefreshResolvedContext() {
        var prefer = asgPreferFamilyOnce;
        if (prefer > 0) {
            asgPreferFamilyOnce = 0;
        }
        var id = resolveWizardFamily(prefer);
        var hid = document.getElementById('asg_family');
        var hintEl = document.getElementById('asg_w_hint');
        var nb = document.getElementById('asg_new_btn');
        var listWrap = document.getElementById('asg_list_wrap');
        var listUl = document.getElementById('asg_list');
        if (hid) {
            hid.value = id > 0 ? String(id) : '0';
        }
        var tpl = wizardTplId();
        var ck = wizardCk();
        var dept = wizardDeptId();
        if (hintEl) {
            hintEl.textContent = '';
        }
        if (id <= 0) {
            if (nb) {
                nb.disabled = true;
            }
            if (listWrap) {
                listWrap.style.display = 'none';
            }
            if (listUl) {
                listUl.innerHTML = '';
            }
            if (hintEl && tpl > 0 && ck !== '') {
                hintEl.textContent = 'لا عائلة مطابقة للقالب والنوع.';
            }
            return;
        }
        if (nb) {
            nb.disabled = false;
        }
        if (hintEl && dept > 0 && ASG_LIBRARY_READY && BUNDLE_SCOPES && BUNDLE_SCOPES.length) {
            var gotBundle = false;
            for (var bj = 0; bj < BUNDLE_SCOPES.length; bj++) {
                var bb = BUNDLE_SCOPES[bj];
                if ((parseInt(bb.source_size_family_id, 10) || 0) === id
                    && (parseInt(bb.department_id, 10) || 0) === dept
                    && (parseInt(bb.size_scheme_template_id, 10) || 0) === tpl
                    && String(bb.commercial_kind_key || '').trim() === ck) {
                    gotBundle = true;
                    break;
                }
            }
            if (!gotBundle) {
                hintEl.textContent = 'لا حزمة مطابقة لهذا القسم.';
            }
        }
        refreshSizeSelects();
        void loadList({ silent: true });
    }

    function sizeOptionsHtml(selectedId) {
        var rows = effectiveFamilySizeRows();
        var h = '<option value="0">— اختر المقاس من العائلة —</option>';
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
            var res = await orangeAdminJsonPost('/admin/api/translate/names.php', payload);
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
            var res = await orangeAdminJsonPost('/admin/api/translate/names.php', payload);
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

    function asgBindColumnStorageChange(tr) {
        tr.querySelector('.asg-c-stor').addEventListener('change', function () {
            var vk = tr.querySelector('.asg-c-vk');
            if (tr.querySelector('.asg-c-stor').value === 'length_cm') {
                vk.value = 'number';
            }
        });
    }

    function asgCreateColumnRow(sortOrder) {
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><input type="number" class="asg-c-sort" value="' + sortOrder + '" style="width:4rem;"></td>' +
            '<td><input type="text" class="asg-c-ar" maxlength="191"></td>' +
            '<td><input type="text" class="asg-c-en" maxlength="191"></td>' +
            '<td><input type="text" class="asg-c-fil" maxlength="191"></td>' +
            '<td><input type="text" class="asg-c-hi" maxlength="191"></td>' +
            '<td><select class="asg-c-vk"><option value="text">نص</option><option value="number">رقم</option></select></td>' +
            '<td><input type="text" class="asg-c-unit" maxlength="64" placeholder="مثال cm"></td>' +
            '<td><select class="asg-c-stor" title="قيمة بالسم: رقم واحد أو نطاق مثل 84-88؛ العميل يحوّل cm/inch">' +
            '<option value="">—</option><option value="length_cm">قياس بالسم — رقم أو نطاق (عرض cm/inch)</option></select></td>' +
            '<td><input type="text" class="asg-c-dsys" maxlength="32" placeholder="فارغ=عام" title="كود إنجليزي صغير (مثل eu، cn) — يُجمّع أعمدة العرض للعميل" style="width:7rem;"></td>';
        asgBindColumnStorageChange(tr);
        bindColRowAutoTranslate(tr);
        return tr;
    }

    function asgSyncColCountInput() {
        var n = document.querySelectorAll('#asg_cols_body tr').length;
        document.getElementById('asg_col_count').value = String(Math.max(1, n));
    }

    function asgRenumberDataCellIx() {
        document.querySelectorAll('#asg_rows_box .asg-row-block[data-row-kind="data"]').forEach(function (block) {
            var ins = block.querySelectorAll('.asg-cell');
            for (var j = 0; j < ins.length; j++) {
                ins[j].setAttribute('data-ix', String(j));
            }
            asgSyncFirstDataCellFromFamily(block);
            asgUpdateFamilySizeHint(block);
        });
    }

    function asgAppendCellToAllDataRows(colIndex) {
        var cols = readColumns();
        var lab = (cols[colIndex] && (cols[colIndex].label_ar || cols[colIndex].label_en))
            ? esc(cols[colIndex].label_ar || cols[colIndex].label_en)
            : ('عمود ' + (colIndex + 1));
        document.querySelectorAll('#asg_rows_box .asg-row-block[data-row-kind="data"]').forEach(function (block) {
            var grid = block.querySelector('.form-grid');
            if (!grid) {
                return;
            }
            var wrap = document.createElement('div');
            wrap.innerHTML = '<div><label>' + lab + '</label><input type="text" class="asg-cell" data-ix="' + colIndex + '" value=""></div>';
            if (wrap.firstElementChild) {
                grid.appendChild(wrap.firstElementChild);
            }
        });
    }

    function asgSyncDataRowCellsToColumnCount() {
        var n = Math.max(1, document.querySelectorAll('#asg_cols_body tr').length);
        document.querySelectorAll('#asg_rows_box .asg-row-block[data-row-kind="data"]').forEach(function (block) {
            var grid = block.querySelector('.form-grid');
            if (!grid) {
                return;
            }
            var cells = grid.querySelectorAll('.asg-cell');
            while (cells.length > n) {
                if (grid.lastElementChild) {
                    grid.lastElementChild.remove();
                }
                cells = grid.querySelectorAll('.asg-cell');
            }
            var colsSnap = readColumns();
            while (cells.length < n) {
                var j = cells.length;
                var lab = (colsSnap[j] && (colsSnap[j].label_ar || colsSnap[j].label_en))
                    ? esc(colsSnap[j].label_ar || colsSnap[j].label_en)
                    : ('عمود ' + (j + 1));
                var wrap = document.createElement('div');
                wrap.innerHTML = '<div><label>' + lab + '</label><input type="text" class="asg-cell" data-ix="' + j + '" value=""></div>';
                if (wrap.firstElementChild) {
                    grid.appendChild(wrap.firstElementChild);
                }
                cells = grid.querySelectorAll('.asg-cell');
            }
        });
        asgRenumberDataCellIx();
    }

    function genColRows(n) {
        var tb = document.getElementById('asg_cols_body');
        tb.innerHTML = '';
        for (var i = 0; i < n; i++) {
            tb.appendChild(asgCreateColumnRow(i + 1));
        }
        asgSyncColCountInput();
        asgSyncDataRowCellsToColumnCount();
    }

    function asgAppendColumnDef() {
        var tb = document.getElementById('asg_cols_body');
        var n = tb.querySelectorAll('tr').length;
        if (n >= 24) {
            alert('الحد الأقصى 24 عموداً');
            return;
        }
        tb.appendChild(asgCreateColumnRow(n + 1));
        asgSyncColCountInput();
        asgAppendCellToAllDataRows(n);
    }

    function asgRemoveLastColumnDef() {
        var tb = document.getElementById('asg_cols_body');
        var trs = tb.querySelectorAll('tr');
        if (trs.length <= 1) {
            alert('يجب أن يبقى عمود واحد على الأقل');
            return;
        }
        var lastIx = trs.length - 1;
        var lastHasData = false;
        document.querySelectorAll('#asg_rows_box .asg-row-block[data-row-kind="data"]').forEach(function (block) {
            var ins = block.querySelectorAll('.asg-cell');
            if (ins[lastIx] && ins[lastIx].value.trim() !== '') {
                lastHasData = true;
            }
        });
        if (lastHasData && !confirm('آخر عمود فيه قيم في بعض الصفوف — ستُحذف من الصفوف أيضاً. المتابعة؟')) {
            return;
        }
        trs[trs.length - 1].remove();
        document.querySelectorAll('#asg_rows_box .asg-row-block[data-row-kind="data"]').forEach(function (block) {
            var grid = block.querySelector('.form-grid');
            if (grid && grid.lastElementChild) {
                grid.lastElementChild.remove();
            }
        });
        asgSyncColCountInput();
        asgRenumberDataCellIx();
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
            var ph = '';
            if (j === 0) {
                ph = ' placeholder="يُملأ تلقائياً عند اختيار المقاس من العائلة"';
            }
            cellInputs += '<div><label>' + lab + '</label><input type="text" class="asg-cell" data-ix="' + j + '"' + ph + ' value="' + esc(cells[j]) + '"></div>';
        }
        div.innerHTML =
            '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">' +
            '<strong>صف بيانات</strong>' +
            '<button type="button" class="btn-secondary asg-rm">حذف الصف</button></div>' +
            '<div style="margin-top:8px;"><label>المقاس من العائلة <span style="color:#b91c1c;">*</span></label>' +
            '<select class="asg-sfs">' + sizeOptionsHtml(sid) + '</select>' +
            '<span class="card-hint" style="display:block;margin-top:4px;font-size:12px;">إلزامي: اختر <strong>المقاس من العائلة</strong> — أول عمود يُحدَّث تلقائياً للمعاينة؛ للعميل يُعرض بلغة المتجر من العائلة.</span></div>' +
            '<p class="asg-family-hint card-hint" style="display:none;margin:8px 0 0;font-size:12px;line-height:1.5;"></p>' +
            '<div class="form-grid" style="margin-top:10px;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));">' + cellInputs + '</div>';
        div.querySelector('.asg-rm').onclick = function () { div.remove(); };
        wrap.appendChild(div);
        asgUpdateFamilySizeHint(div);
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
                if (sfs > 0 && cells.length > 0) {
                    cells[0] = '';
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

    function asgFamilyLabelById(sizeId) {
        var rows = effectiveFamilySizeRows();
        for (var i = 0; i < rows.length; i++) {
            if (parseInt(rows[i].id, 10) === sizeId) {
                return (rows[i].label_ar || rows[i].label_en || ('#' + sizeId)).replace(/</g, '');
            }
        }
        return '#' + sizeId;
    }

    function asgSyncFirstDataCellFromFamily(block) {
        if (!block || block.dataset.rowKind !== 'data') {
            return;
        }
        var sel = block.querySelector('.asg-sfs');
        var firstIn = block.querySelector('.asg-cell[data-ix="0"]');
        if (!firstIn) {
            return;
        }
        var sid = sel ? parseInt(sel.value, 10) || 0 : 0;
        if (sid > 0) {
            firstIn.value = asgFamilyLabelById(sid);
            firstIn.readOnly = true;
            firstIn.setAttribute('title', 'يُملأ تلقائياً من المقاس المختار؛ للعميل بلغة صفحة المتجر من العائلة');
            firstIn.classList.add('asg-cell--from-family');
        } else {
            firstIn.readOnly = false;
            firstIn.removeAttribute('title');
            firstIn.classList.remove('asg-cell--from-family');
        }
    }

    function asgUpdateFamilySizeHint(block) {
        if (!block || block.dataset.rowKind !== 'data') {
            return;
        }
        var hint = block.querySelector('.asg-family-hint');
        if (!hint) {
            return;
        }
        var sel = block.querySelector('.asg-sfs');
        var sid = sel ? parseInt(sel.value, 10) || 0 : 0;
        if (sid <= 0) {
            hint.style.display = 'block';
            hint.textContent = 'اختر مقاساً من العائلة — إلزامي لكل صف بيانات.';
            return;
        }
        var name = asgFamilyLabelById(sid);
        hint.style.display = 'block';
        hint.textContent = 'أول عمود للمعاينة: «' + name + '» — للعميل يُعرض بلغة صفحة المتجر من العائلة (لا يُحفظ نص العمود الأول في القاعدة).';
    }

    function asgRefreshAllFamilyHints() {
        document.querySelectorAll('#asg_rows_box .asg-row-block[data-row-kind="data"]').forEach(function (b) {
            asgSyncFirstDataCellFromFamily(b);
            asgUpdateFamilySizeHint(b);
        });
    }

    function refreshSizeSelects() {
        document.querySelectorAll('.asg-sfs').forEach(function (sel) {
            var cur = parseInt(sel.value, 10) || 0;
            sel.innerHTML = sizeOptionsHtml(cur);
        });
        asgRefreshAllFamilyHints();
    }

    document.getElementById('asg_rows_box').addEventListener('change', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('asg-sfs')) {
            var div = e.target.closest('.asg-row-block');
            if (div) {
                asgSyncFirstDataCellFromFamily(div);
                asgUpdateFamilySizeHint(div);
            }
        }
    });

    document.getElementById('asg_gen_cols').onclick = function () {
        var cur = document.querySelectorAll('#asg_cols_body tr').length;
        var n = parseInt(document.getElementById('asg_col_count').value, 10) || 3;
        n = Math.min(24, Math.max(1, n));
        if (cur > 0 && !confirm('«توليد صفوف العناوين» سيمسح تعريف الأعمدة الحالي ويعيد بناءه (' + n + ' أعمدة). صفوف البيانات تبقى لكن عدد خلاياها قد لا يطابق — يُفضّل استخدام + عمود / − حذف إن كنت تعدّل العدد فقط. المتابعة؟')) {
            return;
        }
        genColRows(n);
    };
    document.getElementById('asg_col_add').onclick = function () { asgAppendColumnDef(); };
    document.getElementById('asg_col_remove').onclick = function () { asgRemoveLastColumnDef(); };

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
            alert('أكمل اختيار قالب المقاسات والنوع التجاري (الخطوتان 2 و 3) حتى تُحدَّد عائلة المقاسات تلقائياً');
            return;
        }
        var fam = effectiveFamilySizeRows();
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

    async function loadList(opts) {
        opts = opts || {};
        var silent = !!opts.silent;
        var f = fid();
        if (f <= 0) {
            if (!silent) {
                alert('أكمل اختيار قالب المقاسات والنوع التجاري (2 و 3) أولاً');
            }
            return;
        }
        var res = await orangeAdminJsonPost(ADVISORY_API, { action: 'list_by_family', size_family_id: f });
        if (!res.success) { alert(res.message || 'خطأ'); return; }
        var ul = document.getElementById('asg_list');
        ul.innerHTML = '';
        (res.guides || []).forEach(function (g) {
            var li = document.createElement('li');
            var title = (g.name_ar || g.name_en || ('#' + g.id));
            li.innerHTML = esc(title) +
                ' <button type="button" class="btn-secondary asg-ed" data-id="' + g.id + '">تعديل</button>' +
                ' <button type="button" class="btn-secondary asg-del" data-id="' + g.id + '">حذف</button>';
            li.querySelector('.asg-ed').onclick = function () { loadGuide(parseInt(g.id, 10)); };
            li.querySelector('.asg-del').onclick = async function () {
                if (!confirm('حذف الدليل؟')) return;
                var r2 = await orangeAdminJsonPost(ADVISORY_API, { action: 'delete', id: parseInt(g.id, 10) });
                if (!r2.success) { alert(r2.message || 'خطأ'); return; }
                loadList();
            };
            ul.appendChild(li);
        });
        document.getElementById('asg_list_wrap').style.display = 'block';
    }

    async function loadGuide(id) {
        var res = await orangeAdminJsonPost(ADVISORY_API, { action: 'get', id: id });
        if (!res.success) { alert(res.message || 'خطأ'); return; }
        var g = res.guide;
        document.getElementById('asg_edit_id').value = String(g.id);
        document.getElementById('asg_scope').value = g.scope_kind || 'single';
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
        if (f <= 0) {
            alert('أكمل اختيار قالب المقاسات والنوع التجاري (2 و 3) أولاً');
            return;
        }
        document.getElementById('asg_edit_id').value = '0';
        document.getElementById('asg_scope').value = 'single';
        document.getElementById('asg_active').value = '1';
        document.getElementById('asg_name_ar').value = '';
        genColRows(3);
        clearRows();
        refreshSizeSelects();
        document.getElementById('asg_editor').style.display = 'block';
        document.getElementById('asg_editor_title').textContent = 'دليل جديد';
    }

    document.getElementById('asg_new_btn').onclick = openNew;

    document.getElementById('asg_w_tpl').onchange = asgRefreshResolvedContext;
    document.getElementById('asg_w_ck').onchange = asgRefreshResolvedContext;
    document.getElementById('asg_w_dept').onchange = asgRefreshResolvedContext;

    function asgValidateRowsBeforeSave(rows) {
        var seen = {};
        var hasData = false;
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            if (!r || r.row_kind !== 'data') {
                continue;
            }
            hasData = true;
            var sid = parseInt(r.size_family_size_id, 10) || 0;
            if (sid <= 0) {
                return 'كل صف بيانات يجب اختيار مقاس من العائلة له (لا يوجد «بدون ربط»). استخدم «إضافة صف لكل مقاس» أو اختر المقاس من القائمة.';
            }
            if (seen[sid]) {
                return 'مقاس العائلة مكرر في أكثر من صف — اربط كل مقاس مرة واحدة فقط.';
            }
            seen[sid] = true;
        }
        if (!hasData) {
            return 'أضف صف بيانات واحداً على الأقل (مع اختيار مقاس من العائلة لكل صف).';
        }
        return '';
    }

    document.getElementById('asg_save_btn').onclick = async function () {
        var f = fid();
        if (f <= 0) {
            alert('أكمل اختيار قالب المقاسات والنوع التجاري (2 و 3) أولاً');
            return;
        }
        var rowsPayload = readRowsPayload();
        var rowErr = asgValidateRowsBeforeSave(rowsPayload);
        if (rowErr) {
            alert(rowErr);
            return;
        }
        var payload = {
            action: 'save',
            id: parseInt(document.getElementById('asg_edit_id').value, 10) || 0,
            size_family_id: f,
            scope_kind: document.getElementById('asg_scope').value,
            name_ar: document.getElementById('asg_name_ar').value.trim(),
            is_active: parseInt(document.getElementById('asg_active').value, 10),
            columns: readColumns(),
            rows: rowsPayload
        };
        var res = await orangeAdminJsonPost(ADVISORY_API, payload);
        if (!res.success) { alert(res.message || 'خطأ'); return; }
        alert('تم الحفظ');
        document.getElementById('asg_edit_id').value = String(res.id || payload.id);
        loadList();
    };

    document.getElementById('asg_cancel_btn').onclick = function () {
        document.getElementById('asg_editor').style.display = 'none';
    };

    function asgBoot() {
        genColRows(3);
        if (PREF_FAMILY > 0) {
            applyWizardFieldsFromFamily(PREF_FAMILY);
            asgPreferFamilyOnce = PREF_FAMILY;
        }
        asgRefreshResolvedContext();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', asgBoot);
    } else {
        asgBoot();
    }
})();
</script>
<style>
.asg-guide-list { list-style: none; padding: 0; margin: 0; }
.asg-guide-list li { margin: 8px 0; padding: 8px; background: #f8fafc; border-radius: 6px; }
.asg-row-block { margin-bottom: 10px; padding: 12px; }
input.asg-cell--from-family { background: #f1f5f9; color: #475569; cursor: default; }
</style>

<?php endif; ?>
