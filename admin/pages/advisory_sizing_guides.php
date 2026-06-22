<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
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
            'SELECT id, name_ar, name_en, commercial_kind_key, sizing_category_key, size_scheme_template_id
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

$asgDraftGuides = [];
if ($tablesReady
    && orange_table_exists($pdo, 'advisory_sizing_guides')
    && orange_table_exists($pdo, 'size_families')) {
    try {
        $asgDraftGuides = $pdo->query(
            'SELECT g.id, g.scope_kind, g.name_ar, g.is_active, g.size_family_id,
                g.department_id, g.size_scheme_template_id, g.commercial_kind_key,
                (SELECT COUNT(*) FROM advisory_sizing_guide_columns c WHERE c.guide_id = g.id) AS columns_count,
                (SELECT COUNT(*) FROM advisory_sizing_guide_rows r WHERE r.guide_id = g.id) AS rows_count
             FROM advisory_sizing_guides g
             WHERE g.size_family_id IS NULL
                OR g.size_family_id = 0
                OR NOT EXISTS (SELECT 1 FROM size_families sf WHERE sf.id = g.size_family_id)
             ORDER BY g.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $asgDraftGuides = [];
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
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (!$tablesReady): ?>
<div class="card">
    <div class="alert-error">الجداول غير جاهزة. زر <strong>الأدمن</strong> أو أي صفحة كتالوج لتهيئة المخطّط، ثم حدّث.</div>
</div>
<?php else: ?>

<?php if ($asgCommercialKinds === [] && orange_table_exists($pdo, 'commercial_kind_dictionary')): ?>
<div class="card" style="margin-bottom:12px;">
    <div class="alert-error">قاموس النوع التجاري (مستوى 1) فارغ — عرّف مفاتيحاً في «قاموس هرم المقاسات» قبل اختيار النوع في المعالج.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3 style="margin:0;">إنشاء جدول مقاس إرشادي</h3>
    <input type="hidden" id="asg_family" value="0">
    <input type="hidden" id="asg_guide_sort" value="0">
    <div class="asg-wizard-row-sort" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;margin-top:12px;width:100%;box-sizing:border-box;direction:rtl;justify-content:flex-start;">
        <div style="flex:0 0 auto;width:6.5rem;min-width:6rem;display:flex;flex-direction:column;gap:4px;">
            <label for="asg_guide_sort_disp" style="margin:0;font-size:13px;white-space:nowrap;">الترتيب</label>
            <input type="text" id="asg_guide_sort_disp" readonly class="input-narrow" style="width:100%;box-sizing:border-box;text-align:center;background:#f1f5f9;cursor:default;" title="للدليل الجديد: الرقم التالي في القاعدة (يبدأ من 1 ويزيد 1 بعد كل حفظ). عند التعديل: القيمة المحفوظة.">
        </div>
    </div>
    <div class="asg-wizard-toolbar" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;margin-top:14px;width:100%;box-sizing:border-box;direction:rtl;justify-content:flex-start;">
        <div style="flex:1 1 0;min-width:10rem;display:flex;flex-direction:column;gap:4px;">
            <label for="asg_w_dept" style="margin:0;font-size:13px;white-space:nowrap;"><strong>1.</strong> القسم الرئيسي</label>
            <select id="asg_w_dept" style="width:100%;min-width:0;"><option value="0">— اختر —</option>
                <?php foreach ($asgDepartments as $d): ?>
                <option value="<?php echo (int) $d['id']; ?>"><?php echo htmlspecialchars((string) ($d['name_ar'] ?: $d['name_en']), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1 1 0;min-width:10rem;display:flex;flex-direction:column;gap:4px;">
            <label for="asg_w_family" style="margin:0;font-size:13px;white-space:nowrap;"><strong>2.</strong> عائلة المقاسات</label>
            <select id="asg_w_family" style="width:100%;min-width:0;"><option value="0">— اختر —</option>
                <?php foreach ($families as $ff): ?>
                <option value="<?php echo (int) $ff['id']; ?>"><?php echo htmlspecialchars(trim((string) ($ff['name_ar'] ?: $ff['name_en'])) ?: ('#' . (int) $ff['id']), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1 1 0;min-width:9rem;display:flex;flex-direction:column;gap:4px;">
            <label for="asg_w_layout" style="margin:0;font-size:13px;white-space:nowrap;"><strong>3.</strong> شكل الدليل</label>
            <select id="asg_w_layout" style="width:100%;min-width:0;">
                <option value="single">جدول واحد</option>
                <option value="dual">جدولان (علوي + سفلي — للأطقم)</option>
            </select>
        </div>
        <input type="hidden" id="asg_w_tpl" value="0">
        <input type="hidden" id="asg_w_ck" value="">
        <div style="flex:0 0 auto;">
            <button type="button" class="btn" id="asg_new_btn" disabled title="أكمل اختيار القسم (1) وعائلة المقاسات (2)">دليل جديد</button>
        </div>
    </div>
    <p id="asg_w_hint" class="card-hint" style="margin:12px 0 0;"></p>
</div>

<div class="card" id="asg_editor" style="display:none;margin-top:16px;">
    <h3 id="asg_editor_title">تعديل دليل</h3>
    <input type="hidden" id="asg_edit_id" value="0">
    <input type="hidden" id="asg_bound_family" value="">
    <input type="hidden" id="asg_scope" value="single">
    <input type="hidden" id="asg_active" value="1">
    <input type="hidden" id="asg_layout" value="single">
    <div class="form-grid" style="max-width:900px;">
        <div style="grid-column:1/-1;"><label for="asg_name_ar">اسم النموذج (داخلي — عربي فقط) <span style="color:#b91c1c;">*</span></label><input type="text" id="asg_name_ar" maxlength="191" placeholder="مثال: علوي قمصان EU"></div>
    </div>

    <h4 style="margin-top:20px;">تعريف الأعمدة</h4>
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:8px;">
        <div>
            <label for="asg_col_count_upper">عدد الأعمدة</label>
            <input type="number" id="asg_col_count_upper" min="1" max="24" value="3" style="width:5rem;">
        </div>
        <button type="button" class="btn asg-gen-cols" data-asg-panel="upper">توليد صفوف العناوين</button>
        <button type="button" class="btn-secondary asg-col-add" data-asg-panel="upper" title="يضيف عموداً جديداً">+ عمود</button>
        <button type="button" class="btn-secondary asg-col-remove" data-asg-panel="upper" title="يحذف آخر عمود">− حذف آخر عمود</button>
    </div>
    <div style="overflow-x:auto;">
        <table class="data-table" id="asg_cols_table_upper">
            <thead><tr><th>ترتيب</th><th>عربي <span style="color:#b91c1c;">*</span></th><th>EN <span style="color:#b91c1c;">*</span></th><th>Fil <span style="color:#b91c1c;">*</span></th><th>Hi <span style="color:#b91c1c;">*</span></th><th>نوع القيمة</th><th>وحدة (عرض)</th><th>تخزين الطول</th><th>عمود النظام (كود)</th></tr></thead>
            <tbody id="asg_cols_body_upper"></tbody>
        </table>
    </div>

    <h4 style="margin-top:20px;">صفوف الجدول</h4>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;align-items:center;">
        <button type="button" class="btn asg-bulk-rows" data-asg-panel="upper" title="يضيف صفاً لكل مقاس في عائلة المعالج؛ مع تخطي ما اختير مسبقاً">إضافة صف لكل مقاس</button>
        <button type="button" class="btn-secondary asg-row-data" data-asg-panel="upper">+ صف بيانات</button>
        <button type="button" class="btn-secondary asg-row-label" data-asg-panel="upper" title="سطر عنوان يظهر داخل الدليل للعميل">+ صف عنوان (مجموعة)</button>
    </div>
    <div id="asg_rows_box_upper"></div>

    <div id="asg_panel_lower_wrap" style="display:none;margin-top:24px;border-top:1px solid #e2e8f0;padding-top:16px;">
    <h4 style="margin-top:0;">القسم السفلي — تعريف الجدول</h4>
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:8px;">
        <div>
            <label for="asg_col_count_lower">عدد الأعمدة</label>
            <input type="number" id="asg_col_count_lower" min="1" max="24" value="3" style="width:5rem;">
        </div>
        <button type="button" class="btn asg-gen-cols" data-asg-panel="lower">توليد صفوف العناوين</button>
        <button type="button" class="btn-secondary asg-col-add" data-asg-panel="lower">+ عمود</button>
        <button type="button" class="btn-secondary asg-col-remove" data-asg-panel="lower">− حذف آخر عمود</button>
    </div>
    <div style="overflow-x:auto;">
        <table class="data-table" id="asg_cols_table_lower">
            <thead><tr><th>ترتيب</th><th>عربي <span style="color:#b91c1c;">*</span></th><th>EN <span style="color:#b91c1c;">*</span></th><th>Fil <span style="color:#b91c1c;">*</span></th><th>Hi <span style="color:#b91c1c;">*</span></th><th>نوع القيمة</th><th>وحدة (عرض)</th><th>تخزين الطول</th><th>عمود النظام (كود)</th></tr></thead>
            <tbody id="asg_cols_body_lower"></tbody>
        </table>
    </div>
    <h4 style="margin-top:20px;">صفوف الجدول — سفلي</h4>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;align-items:center;">
        <button type="button" class="btn asg-bulk-rows" data-asg-panel="lower">إضافة صف لكل مقاس</button>
        <button type="button" class="btn-secondary asg-row-data" data-asg-panel="lower">+ صف بيانات</button>
        <button type="button" class="btn-secondary asg-row-label" data-asg-panel="lower">+ صف عنوان (مجموعة)</button>
    </div>
    <div id="asg_rows_box_lower"></div>
    </div>

    <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn" id="asg_save_btn">حفظ</button>
        <button type="button" class="btn-secondary" id="asg_cancel_btn">إلغاء</button>
    </div>
</div>

<div class="card" id="asg_draft_card" style="margin-top:16px;">
    <h3 style="margin-top:0;">مكتبة جداول المقاسات الإرشادية</h3>
    <div id="asg_draft_load_err" class="alert-error" style="display:none;margin-bottom:10px;"></div>
    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>اسم النموذج (داخلي)</th>
                    <th>عائلة في القاعدة</th>
                    <th>قسم</th>
                    <th>قالب</th>
                    <th>نوع تجاري</th>
                    <th>أعمدة</th>
                    <th>صفوف</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody id="asg_draft_tbody"></tbody>
        </table>
    </div>
</div>

<?php if ($asgLibraryReady): ?>
<div class="card" id="asg_library_map_card" style="margin-top:16px;">
    <h3 style="margin-top:0;">ربط عائلة مستهلك بحزمة المكتبة ثم المزامنة</h3>
    <div class="form-grid" style="max-width:720px;">
        <div style="grid-column:1/-1;"><label for="asg_map_consumer">عائلة المستهلك</label>
            <select id="asg_map_consumer"><option value="0">— اختر —</option>
                <?php foreach ($families as $f): ?>
                <option value="<?php echo (int) $f['id']; ?>"><?php echo htmlspecialchars((string) ($f['name_ar'] ?: $f['name_en']), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="grid-column:1/-1;"><label for="asg_map_bundle">حزمة المكتبة</label>
            <select id="asg_map_bundle"><option value="0">— اختر —</option></select>
        </div>
    </div>
    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn" id="asg_map_save">حفظ الربط</button>
        <button type="button" class="btn" id="asg_map_sync">مزامنة الأدلة إلى عائلة المستهلك</button>
        <button type="button" class="btn-secondary" id="asg_map_delete">إزالة الربط</button>
    </div>
    <div style="margin-top:16px;overflow:auto;">
        <table class="data-table" id="asg_lib_unified_table">
            <thead>
                <tr>
                    <th>نوع السجل</th>
                    <th>عائلة المستهلك / #</th>
                    <th>حزمة المكتبة أو اسم النموذج</th>
                    <th>آخر تحديث / أعمدة</th>
                    <th>— / صفوف</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody id="asg_unified_lib_tbody"></tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!$asgLibraryReady): ?>
<div class="card" id="asg_guides_table_card" style="margin-top:16px;">
    <h3 style="margin-top:0;">الأدلة المحفوظة لهذه العائلة</h3>
    <div id="asg_list_wrap" style="display:block;margin-top:8px;">
        <div style="overflow-x:auto;">
            <table class="data-table" id="asg_guides_table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم النموذج (داخلي)</th>
                        <th>أعمدة</th>
                        <th>صفوف</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody id="asg_list_tbody"></tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    var ADVISORY_API = '/admin/api/advisory_sizing_guides/manage.php';
    var LIBRARY_API = '/admin/api/advisory_sizing_library/manage.php';
    var FAMILY_SIZES = <?php echo $sizesJson; ?>;
    var PREF_FAMILY = <?php echo (int) $prefSizeFamilyId; ?>;
    var FAMILIES_FULL = <?php echo $asgJson($families); ?>;
    var BUNDLE_SCOPES = <?php echo $asgJson($asgBundleScopes); ?>;
    var ASG_LIBRARY_READY = <?php echo $asgLibraryReady ? 'true' : 'false'; ?>;
    var TEMPLATE_SIZE_ROWS = <?php echo $templateSizesJson; ?>;
    var asgPreferFamilyOnce = 0;
    var ASG_FAMILY_GUIDES_CACHE = [];
    var ASG_UNBOUND_GUIDES_CACHE = [];
    /** عند فتح «دليل جديد» (مسودة غير محفوظة): توقيع القسم+القالب+النوع وقت الفتح؛ يُصفّر عند التعديل أو الإغلاق. */
    var asgNewEditorWizardSig = null;

    function wizardFamilyId() {
        var el = document.getElementById('asg_w_family');
        return el ? (parseInt(el.value, 10) || 0) : 0;
    }

    function wizardLayoutKind() {
        var el = document.getElementById('asg_w_layout');
        return (el && el.value === 'dual') ? 'dual' : 'single';
    }

    function asgSyncLayoutPanels() {
        var lk = wizardLayoutKind();
        var layEl = document.getElementById('asg_layout');
        if (layEl) {
            layEl.value = lk;
        }
        var lw = document.getElementById('asg_panel_lower_wrap');
        if (lw) {
            lw.style.display = (lk === 'dual') ? 'block' : 'none';
        }
    }

    function asgPanelList() {
        return wizardLayoutKind() === 'dual' ? ['upper', 'lower'] : ['upper'];
    }

    function asgColsBodyId(panel) {
        return 'asg_cols_body_' + panel;
    }

    function asgRowsBoxId(panel) {
        return 'asg_rows_box_' + panel;
    }

    function asgColCountId(panel) {
        return 'asg_col_count_' + panel;
    }

    function syncWizardFromFamily(famId) {
        famId = parseInt(famId, 10) || 0;
        var tplEl = document.getElementById('asg_w_tpl');
        var ckEl = document.getElementById('asg_w_ck');
        var famEl = document.getElementById('asg_w_family');
        if (famEl && famId > 0) {
            famEl.value = String(famId);
        }
        if (famId <= 0) {
            return;
        }
        for (var fi = 0; fi < FAMILIES_FULL.length; fi++) {
            if (parseInt(FAMILIES_FULL[fi].id, 10) !== famId) {
                continue;
            }
            if (tplEl) {
                tplEl.value = String(parseInt(FAMILIES_FULL[fi].size_scheme_template_id, 10) || 0);
            }
            if (ckEl) {
                ckEl.value = String(FAMILIES_FULL[fi].commercial_kind_key || '').trim();
            }
            break;
        }
    }

    function asgWizardSigStr() {
        return String(wizardDeptId()) + '|' + String(wizardFamilyId()) + '|' + wizardLayoutKind();
    }

    /** لقطة معالج 1–2–3 لإعادة تطبيقها بعد حفظ/تحديث قوائم (تجنّب فراغ الحقول أو إخفاء المحرّر). */
    function asgSnapshotWizardTriple() {
        return { d: wizardDeptId(), f: wizardFamilyId(), l: wizardLayoutKind() };
    }

    function asgApplyWizardTriple(snap) {
        if (!snap) {
            return;
        }
        var de = document.getElementById('asg_w_dept');
        var fe = document.getElementById('asg_w_family');
        var le = document.getElementById('asg_w_layout');
        if (de && snap.d > 0) {
            de.value = String(snap.d);
        }
        if (fe && snap.f > 0) {
            fe.value = String(snap.f);
            syncWizardFromFamily(snap.f);
        }
        if (le && snap.l) {
            le.value = snap.l === 'dual' ? 'dual' : 'single';
        }
        asgSyncLayoutPanels();
    }

    function asgClearNewEditorFormFields() {
        document.getElementById('asg_edit_id').value = '0';
        var bf = document.getElementById('asg_bound_family');
        if (bf) {
            bf.value = '';
        }
        document.getElementById('asg_family').value = '0';
        var gSortOpen = document.getElementById('asg_guide_sort');
        if (gSortOpen) {
            gSortOpen.value = '0';
        }
        document.getElementById('asg_scope').value = wizardLayoutKind() === 'dual' ? 'dual' : 'single';
        document.getElementById('asg_active').value = '1';
        document.getElementById('asg_name_ar').value = '';
        asgSyncLayoutPanels();
        asgPanelList().forEach(function (pn) {
            genColRows(3, pn);
            clearRows(pn);
        });
        refreshSizeSelects();
        document.getElementById('asg_editor_title').textContent = 'دليل جديد';
        asgRefreshGuideSortDisp();
    }

    function asgResetNewEditorCardHidden() {
        var ed = document.getElementById('asg_editor');
        if (ed) {
            ed.style.display = 'none';
        }
        asgNewEditorWizardSig = null;
        asgClearNewEditorFormFields();
    }

    function asgMaybeResetNewEditorIfWizardChanged() {
        var ed = document.getElementById('asg_editor');
        if (!ed || ed.style.display === 'none') {
            return;
        }
        var eid = parseInt(document.getElementById('asg_edit_id').value, 10) || 0;
        if (eid > 0) {
            return;
        }
        if (asgNewEditorWizardSig === null) {
            return;
        }
        if (!asgWizardTripleComplete() || asgWizardSigStr() !== asgNewEditorWizardSig) {
            asgResetNewEditorCardHidden();
        }
    }

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

    function asgWizardTripleComplete() {
        return wizardDeptId() > 0 && wizardFamilyId() > 0;
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
        if (preferredId > 0) {
            syncWizardFromFamily(preferredId);
            return preferredId;
        }
        var wf = wizardFamilyId();
        if (wf > 0) {
            syncWizardFromFamily(wf);
            return wf;
        }
        return 0;
    }

    function effectiveFamilySizeRows() {
        var f = fid();
        var tpl = wizardTplId();
        var tplList = [];
        if (TEMPLATE_SIZE_ROWS && typeof TEMPLATE_SIZE_ROWS === 'object') {
            tplList = TEMPLATE_SIZE_ROWS[String(tpl)] || [];
        }
        /* بدون عائلة مربوطة: المقاسات تُستخرج من صفوف قالب المعالج (2) فقط — معرفات سالبة للواجهة؛ الحفظ يرسل 0 للمسودة. */
        if (f <= 0 && tpl > 0 && tplList.length > 0) {
            var sortedTpl = tplList.slice().sort(function (a, b) {
                var so = (parseInt(a.sort_order, 10) || 0) - (parseInt(b.sort_order, 10) || 0);
                if (so !== 0) {
                    return so;
                }
                return (parseInt(a.id, 10) || 0) - (parseInt(b.id, 10) || 0);
            });
            var virt = [];
            for (var vi = 0; vi < sortedTpl.length; vi++) {
                var ts = sortedTpl[vi];
                var tsid = parseInt(ts.id, 10) || 0;
                if (tsid <= 0) {
                    continue;
                }
                virt.push({
                    id: -tsid,
                    label_ar: ts.label_ar,
                    label_en: ts.label_en,
                    sort_order: ts.sort_order,
                    scheme_template_size_id: tsid
                });
            }
            return virt;
        }
        var raw = (FAMILY_SIZES[String(f)] || []).slice();
        /* ترتيب وتصفية المقاسات حسب قالب المعالج (2) — وليس حسب قالب آخر في العائلة. */
        var allowedTplSizeIds = {};
        for (var ai = 0; ai < tplList.length; ai++) {
            var tsz = parseInt(tplList[ai].id, 10) || 0;
            if (tsz > 0) {
                allowedTplSizeIds[tsz] = true;
            }
        }
        if (tpl > 0 && tplList.length > 0 && raw.length > 0) {
            var hasLinked = false;
            for (var hi = 0; hi < raw.length; hi++) {
                if ((parseInt(raw[hi].scheme_template_size_id, 10) || 0) > 0) {
                    hasLinked = true;
                    break;
                }
            }
            if (hasLinked) {
                raw = raw.filter(function (row) {
                    var sid = parseInt(row.scheme_template_size_id, 10) || 0;
                    return sid > 0 && Object.prototype.hasOwnProperty.call(allowedTplSizeIds, sid);
                });
            }
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
            var aix = ast > 0 && Object.prototype.hasOwnProperty.call(orderIdx, ast) ? orderIdx[ast] : 9999;
            var bix = bst > 0 && Object.prototype.hasOwnProperty.call(orderIdx, bst) ? orderIdx[bst] : 9999;
            if (aix !== bix) {
                return aix - bix;
            }
            return (parseInt(a.sort_order, 10) || 0) - (parseInt(b.sort_order, 10) || 0);
        });
    }

    function applyWizardFieldsFromFamily(prefId) {
        prefId = parseInt(prefId, 10) || 0;
        if (prefId <= 0) {
            return;
        }
        var famEl = document.getElementById('asg_w_family');
        if (famEl) {
            famEl.value = String(prefId);
        }
        syncWizardFromFamily(prefId);
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
            } else {
                for (var k = 0; k < BUNDLE_SCOPES.length; k++) {
                    var by = BUNDLE_SCOPES[k];
                    if ((parseInt(by.source_size_family_id, 10) || 0) === prefId) {
                        var d2 = parseInt(by.department_id, 10) || 0;
                        if (d2 > 0) {
                            deptEl.value = String(d2);
                            break;
                        }
                    }
                }
            }
        }
    }

    async function asgRefreshResolvedContext() {
        var prefer = asgPreferFamilyOnce;
        if (prefer > 0) {
            asgPreferFamilyOnce = 0;
        }
        var id = resolveWizardFamily(prefer);
        var hid = document.getElementById('asg_family');
        var hintEl = document.getElementById('asg_w_hint');
        var nb = document.getElementById('asg_new_btn');
        var listWrap = document.getElementById('asg_list_wrap');
        var listTbody = document.getElementById('asg_list_tbody');
        if (hid) {
            hid.value = id > 0 ? String(id) : '0';
        }
        var tpl = wizardTplId();
        var ck = wizardCk();
        var dept = wizardDeptId();
        var wizOk = asgWizardTripleComplete();
        if (nb) {
            nb.disabled = !wizOk;
            nb.title = wizOk ? '' : 'أكمل اختيار القسم (1) وعائلة المقاسات (2) وشكل الدليل (3)';
        }
        if (hintEl) {
            hintEl.textContent = '';
        }
        if (id <= 0) {
            if (document.getElementById('asg_unified_lib_tbody')) {
                await asgRenderUnifiedTable({ silent: true });
            } else {
                if (listWrap) {
                    listWrap.style.display = 'block';
                }
                if (listTbody) {
                    listTbody.innerHTML = '<tr><td colspan="5" class="card-hint">أكمل القسم (1) وعائلة المقاسات (2) وشكل الدليل (3) لتُعرض هنا الأدلة المربوطة. لمسودة جديدة: بعد اكتمال المعالج استخدم «دليل جديد»؛ تُعرض المسودات في «مكتبة جداول المقاسات الإرشادية».</td></tr>';
                }
            }
            if (hintEl && tpl > 0 && ck !== '') {
                hintEl.textContent = 'لا عائلة مطابقة للقالب والنوع.';
            }
            await loadDraftList({ silent: true });
            asgRefreshGuideSortDisp();
            asgMaybeResetNewEditorIfWizardChanged();
            return;
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
        await loadList({ silent: true });
        void loadDraftList({ silent: true });
        asgRefreshGuideSortDisp();
        asgMaybeResetNewEditorIfWizardChanged();
    }

    function sizeOptionsHtml(selectedId) {
        var rows = effectiveFamilySizeRows();
        var selN = parseInt(selectedId, 10);
        if (isNaN(selN)) {
            selN = 0;
        }
        var h = '<option value="0">— اختر المقاس (حسب قالب المعالج 2) —</option>';
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var id = parseInt(r.id, 10) || 0;
            var lab = (r.label_ar || r.label_en || '').replace(/</g, '');
            h += '<option value="' + id + '"' + (id === selN ? ' selected' : '') + '>' + lab + '</option>';
        }
        return h;
    }

    function esc(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
    }

    async function asgRenderUnifiedTable(opts) {
        opts = opts || {};
        var silent = !!opts.silent;
        var tb = document.getElementById('asg_unified_lib_tbody');
        if (!tb) {
            return;
        }
        tb.innerHTML = '';
        var resMaps = await orangeAdminJsonPost(LIBRARY_API, { action: 'list_maps' });
        var maps = (resMaps && resMaps.success && resMaps.maps) ? resMaps.maps : [];
        if (!(resMaps && resMaps.success)) {
            var trErr = document.createElement('tr');
            trErr.innerHTML = '<td colspan="6" style="text-align:center;color:#94a3b8">—</td>';
            tb.appendChild(trErr);
        } else if (!maps.length) {
            var trE = document.createElement('tr');
            trE.innerHTML = '<td colspan="6" style="text-align:center;color:#94a3b8">—</td>';
            tb.appendChild(trE);
        } else {
            for (var ri = 0; ri < maps.length; ri++) {
                var m = maps[ri];
                var trM = document.createElement('tr');
                var cons = (m.consumer_ar || m.consumer_en || '');
                var bun = (m.bundle_display_internal || m.bundle_ar || m.bundle_en || '');
                var cid = parseInt(m.consumer_size_family_id, 10) || 0;
                trM.innerHTML =
                    '<td><span class="card-hint">ربط مكتبة</span></td>' +
                    '<td>' + esc(cons) + '</td>' +
                    '<td>' + esc(bun) + '</td>' +
                    '<td>' + esc(m.updated_at || '—') + '</td>' +
                    '<td>—</td>' +
                    '<td><button type="button" class="btn-secondary asg-map-sync-one" data-consumer="' + cid + '">مزامنة</button> ' +
                    '<button type="button" class="btn-secondary asg-map-unmap" data-consumer="' + cid + '">إزالة ربط</button></td>';
                tb.appendChild(trM);
            }
            tb.querySelectorAll('.asg-map-sync-one').forEach(function (btn) {
                btn.onclick = async function () {
                    var cid = parseInt(btn.getAttribute('data-consumer'), 10) || 0;
                    if (!confirm('نسخ الأدلة من حزمة المكتبة إلى «' + asgMapFamLabel(cid) + '»؟ سيُستبدل دليل علوي/سفلي/مفرد الموجود على العائلة المستهدفة إن وُجد.')) {
                        return;
                    }
                    var r2 = await orangeAdminJsonPost(LIBRARY_API, { action: 'sync_consumer', consumer_size_family_id: cid });
                    if (!r2 || !r2.success) {
                        alert((r2 && r2.message) ? r2.message : 'فشل');
                        return;
                    }
                    alert(r2.message || 'تم');
                    void asgRenderUnifiedTable({ silent: true });
                };
            });
            tb.querySelectorAll('.asg-map-unmap').forEach(function (btn) {
                btn.onclick = async function () {
                    var cid = parseInt(btn.getAttribute('data-consumer'), 10) || 0;
                    if (!confirm('إزالة ربط العائلة؟')) {
                        return;
                    }
                    var r2 = await orangeAdminJsonPost(LIBRARY_API, { action: 'delete_map', consumer_size_family_id: cid });
                    if (!r2 || !r2.success) {
                        alert((r2 && r2.message) ? r2.message : 'فشل');
                        return;
                    }
                    void asgRenderUnifiedTable({ silent: true });
                };
            });
        }

        var fidv = fid();
        if (fidv <= 0) {
            ASG_FAMILY_GUIDES_CACHE = [];
            if (!silent) {
                if (!asgWizardTripleComplete()) {
                    alert('أكمل القسم (1) وعائلة المقاسات (2) وشكل الدليل (3) أولاً');
                } else {
                    alert('لا توجد عائلة مقاسات مطابقة للمعالج (1–2–3) — يمكن العمل بمسودة من المكتبة؛ في المحرّر تُستخرج أسماء المقاسات من قالب (2). ربط الجدول بعائلة المستهلك من بطاقة «ربط عائلة مستهلك بحزمة المكتبة ثم المزامنة».');
                }
            }
            asgRefreshGuideSortDisp();
            return;
        }

        var res = await orangeAdminJsonPost(ADVISORY_API, { action: 'list_by_family', size_family_id: fidv });
        if (!res.success) {
            alert(res.message || 'خطأ');
            var trE2 = document.createElement('tr');
            trE2.innerHTML = '<td colspan="6" style="text-align:center;color:#94a3b8">—</td>';
            tb.appendChild(trE2);
            asgRefreshGuideSortDisp();
            return;
        }
        var guides = res.guides || [];
        ASG_FAMILY_GUIDES_CACHE = guides;
        if (!guides.length) {
            var tr0 = document.createElement('tr');
            tr0.innerHTML = '<td colspan="6" style="text-align:center;color:#94a3b8">—</td>';
            tb.appendChild(tr0);
        } else {
            guides.forEach(function (g) {
                var gid = parseInt(g.id, 10) || 0;
                var title = (g.name_ar || ('#' + gid));
                var cols = parseInt(String(g.columns_count != null ? g.columns_count : '0'), 10) || 0;
                var rws = parseInt(String(g.rows_count != null ? g.rows_count : '0'), 10) || 0;
                var trG = document.createElement('tr');
                trG.innerHTML =
                    '<td><span class="card-hint">دليل إرشادي</span></td>' +
                    '<td>' + gid + '</td>' +
                    '<td>' + esc(title) + '</td>' +
                    '<td>' + cols + '</td>' +
                    '<td>' + rws + '</td>' +
                    '<td><button type="button" class="btn-secondary asg-ed" data-id="' + gid + '">تعديل</button> ' +
                    '<button type="button" class="btn-secondary asg-del" data-id="' + gid + '">حذف</button></td>';
                trG.querySelector('.asg-ed').onclick = function () { loadGuide(gid); };
                trG.querySelector('.asg-del').onclick = async function () {
                    if (!confirm('حذف الدليل؟')) {
                        return;
                    }
                    var r2 = await orangeAdminJsonPost(ADVISORY_API, { action: 'delete', id: gid });
                    if (!r2.success) {
                        alert(r2.message || 'خطأ');
                        return;
                    }
                    void loadList();
                    void loadDraftList({ silent: true });
                };
                tb.appendChild(trG);
            });
        }
        asgRefreshGuideSortDisp();
    }

    function asgMaxGuideSort(guides) {
        var m = 0;
        if (!guides || !guides.length) {
            return m;
        }
        for (var i = 0; i < guides.length; i++) {
            var v = parseInt(guides[i].sort_order, 10) || 0;
            if (v > m) {
                m = v;
            }
        }
        return m;
    }

    function asgRefreshGuideSortDisp() {
        var hid = document.getElementById('asg_guide_sort');
        var disp = document.getElementById('asg_guide_sort_disp');
        if (!hid || !disp) {
            return;
        }
        var editId = parseInt(document.getElementById('asg_edit_id').value, 10) || 0;
        if (editId > 0) {
            var s = parseInt(hid.value, 10) || 0;
            disp.value = String(s);
            disp.title = 'الترتيب المحفوظ لهذا الدليل في القاعدة';
            return;
        }
        var f = fid();
        var next = 1;
        if (f > 0) {
            next = asgMaxGuideSort(ASG_FAMILY_GUIDES_CACHE) + 1;
        } else {
            var d = wizardDeptId();
            var t = wizardTplId();
            var ck = wizardCk();
            var subset = [];
            for (var j = 0; j < ASG_UNBOUND_GUIDES_CACHE.length; j++) {
                var gx = ASG_UNBOUND_GUIDES_CACHE[j];
                if ((parseInt(gx.department_id, 10) || 0) === d
                    && (parseInt(gx.size_scheme_template_id, 10) || 0) === t
                    && String(gx.commercial_kind_key || '').trim() === ck) {
                    subset.push(gx);
                }
            }
            next = asgMaxGuideSort(subset) + 1;
        }
        hid.value = '0';
        disp.value = String(next);
        disp.title = 'دليل جديد سيُحفظ بالترتيب ' + String(next) + ' في القاعدة؛ بعد كل حفظ يعرض الحقل التالي (يزيد 1).';
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

    function asgSyncColCountInput(panel) {
        panel = panel || 'upper';
        var tb = document.getElementById(asgColsBodyId(panel));
        var ce = document.getElementById(asgColCountId(panel));
        if (!tb || !ce) {
            return;
        }
        ce.value = String(Math.max(1, tb.querySelectorAll('tr').length));
    }

    function asgRenumberDataCellIx(panel) {
        if (!panel) {
            asgPanelList().forEach(function (pn) { asgRenumberDataCellIx(pn); });
            return;
        }
        document.querySelectorAll('#' + asgRowsBoxId(panel) + ' .asg-row-block[data-row-kind="data"]').forEach(function (block) {
            var ins = block.querySelectorAll('.asg-cell');
            for (var j = 0; j < ins.length; j++) {
                ins[j].setAttribute('data-ix', String(j));
            }
            asgSyncFirstDataCellFromFamily(block);
            asgUpdateFamilySizeHint(block);
        });
    }

    function asgAppendCellToAllDataRows(colIndex, panel) {
        panel = panel || 'upper';
        var cols = readColumns().filter(function (c) { return (c.panel_kind || 'upper') === panel; });
        var lab = (cols[colIndex] && (cols[colIndex].label_ar || cols[colIndex].label_en))
            ? esc(cols[colIndex].label_ar || cols[colIndex].label_en)
            : ('عمود ' + (colIndex + 1));
        document.querySelectorAll('#' + asgRowsBoxId(panel) + ' .asg-row-block[data-row-kind="data"]').forEach(function (block) {
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

    function asgSyncDataRowCellsToColumnCount(panel) {
        if (!panel) {
            asgPanelList().forEach(function (pn) { asgSyncDataRowCellsToColumnCount(pn); });
            return;
        }
        var tb = document.getElementById(asgColsBodyId(panel));
        var n = Math.max(1, tb ? tb.querySelectorAll('tr').length : 1);
        document.querySelectorAll('#' + asgRowsBoxId(panel) + ' .asg-row-block[data-row-kind="data"]').forEach(function (block) {
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
            var colsSnap = readColumns().filter(function (c) { return (c.panel_kind || 'upper') === panel; });
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
        asgRenumberDataCellIx(panel);
    }

    function genColRows(n, panel) {
        panel = panel || 'upper';
        var tb = document.getElementById(asgColsBodyId(panel));
        if (!tb) {
            return;
        }
        tb.innerHTML = '';
        for (var i = 0; i < n; i++) {
            tb.appendChild(asgCreateColumnRow(i + 1));
        }
        asgSyncColCountInput(panel);
        asgSyncDataRowCellsToColumnCount(panel);
    }

    function asgAppendColumnDef(panel) {
        panel = panel || 'upper';
        var tb = document.getElementById(asgColsBodyId(panel));
        if (!tb) {
            return;
        }
        var n = tb.querySelectorAll('tr').length;
        if (n >= 24) {
            alert('الحد الأقصى 24 عموداً');
            return;
        }
        tb.appendChild(asgCreateColumnRow(n + 1));
        asgSyncColCountInput(panel);
        asgAppendCellToAllDataRows(n, panel);
    }

    function asgRemoveLastColumnDef(panel) {
        panel = panel || 'upper';
        var tb = document.getElementById(asgColsBodyId(panel));
        if (!tb) {
            return;
        }
        var trs = tb.querySelectorAll('tr');
        if (trs.length <= 1) {
            alert('يجب أن يبقى عمود واحد على الأقل');
            return;
        }
        var lastIx = trs.length - 1;
        var lastHasData = false;
        document.querySelectorAll('#' + asgRowsBoxId(panel) + ' .asg-row-block[data-row-kind="data"]').forEach(function (block) {
            var ins = block.querySelectorAll('.asg-cell');
            if (ins[lastIx] && ins[lastIx].value.trim() !== '') {
                lastHasData = true;
            }
        });
        if (lastHasData && !confirm('آخر عمود فيه قيم في بعض الصفوف — ستُحذف من الصفوف أيضاً. المتابعة؟')) {
            return;
        }
        trs[trs.length - 1].remove();
        document.querySelectorAll('#' + asgRowsBoxId(panel) + ' .asg-row-block[data-row-kind="data"]').forEach(function (block) {
            var grid = block.querySelector('.form-grid');
            if (grid && grid.lastElementChild) {
                grid.lastElementChild.remove();
            }
        });
        asgSyncColCountInput(panel);
        asgRenumberDataCellIx(panel);
    }

    function readColumns() {
        var out = [];
        asgPanelList().forEach(function (panel) {
            var trs = document.querySelectorAll('#' + asgColsBodyId(panel) + ' tr');
            for (var i = 0; i < trs.length; i++) {
                var tr = trs[i];
                out.push({
                    panel_kind: panel,
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
        });
        return out;
    }

    function fillColumns(cols) {
        asgPanelList().forEach(function (panel) {
            var panelCols = (cols || []).filter(function (c) {
                var pk = String(c.panel_kind || 'upper').trim();
                return pk === panel;
            });
            document.getElementById(asgColCountId(panel)).value = String(Math.max(1, panelCols.length || 1));
            genColRows(Math.max(1, panelCols.length || 1), panel);
            var trs = document.querySelectorAll('#' + asgColsBodyId(panel) + ' tr');
            for (var i = 0; i < panelCols.length && i < trs.length; i++) {
                var c = panelCols[i];
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
        });
    }

    var rowSeq = 0;

    function addDataRow(prefill, panel) {
        panel = panel || 'upper';
        prefill = prefill || {};
        var wrap = document.getElementById(asgRowsBoxId(panel));
        if (!wrap) {
            return;
        }
        var id = 'asg_r_' + panel + '_' + (++rowSeq);
        var cols = readColumns().filter(function (c) { return (c.panel_kind || 'upper') === panel; });
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
                ph = ' placeholder="يُملأ تلقائياً عند اختيار المقاس (حسب القالب 2)"';
            }
            cellInputs += '<div><label>' + lab + '</label><input type="text" class="asg-cell" data-ix="' + j + '"' + ph + ' value="' + esc(cells[j]) + '"></div>';
        }
        div.innerHTML =
            '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">' +
            '<strong>صف بيانات</strong>' +
            '<button type="button" class="btn-secondary asg-rm">حذف الصف</button></div>' +
            '<div style="margin-top:8px;"><label>المقاس <span style="color:#b91c1c;">*</span> <span style="font-size:12px;color:#64748b;">(قالب المقاسات في المعالج — الحقل 2)</span></label>' +
            '<select class="asg-sfs">' + sizeOptionsHtml(sid) + '</select>' +
            '<span class="card-hint" style="display:block;margin-top:4px;font-size:12px;">القائمة مرتبة من <strong>قالب المقاسات (2)</strong> في المعالج — بلا عائلة مربوطة تُعرض صفوف القالب للمعاينة؛ عند وجود عائلة مطابقة تُعرض مقاسات العائلة المرتبطة بالقالب. أول عمود للمعاينة يُحدَّث تلقائياً عند اختيار مقاس.</span></div>' +
            '<p class="asg-family-hint card-hint" style="display:none;margin:8px 0 0;font-size:12px;line-height:1.5;"></p>' +
            '<div class="form-grid" style="margin-top:10px;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));">' + cellInputs + '</div>';
        div.querySelector('.asg-rm').onclick = function () { div.remove(); };
        wrap.appendChild(div);
        asgSyncFirstDataCellFromFamily(div);
        asgUpdateFamilySizeHint(div);
    }

    function addLabelRow(prefill, panel) {
        panel = panel || 'upper';
        prefill = prefill || {};
        var wrap = document.getElementById(asgRowsBoxId(panel));
        if (!wrap) {
            return;
        }
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

    function clearRows(panel) {
        if (panel) {
            var w1 = document.getElementById(asgRowsBoxId(panel));
            if (w1) {
                w1.innerHTML = '';
            }
            return;
        }
        asgPanelList().forEach(function (pn) { clearRows(pn); });
    }

    function readRowsPayload() {
        var rows = [];
        var b = 0;
        asgPanelList().forEach(function (panel) {
            var blocks = document.querySelectorAll('#' + asgRowsBoxId(panel) + ' .asg-row-block');
            for (var i = 0; i < blocks.length; i++) {
                var el = blocks[i];
                var rk = el.dataset.rowKind === 'label' ? 'label' : 'data';
                b++;
                if (rk === 'label') {
                    rows.push({
                        panel_kind: panel,
                        row_kind: 'label',
                        sort_order: b,
                        label_ar: el.querySelector('.asg-l-ar').value.trim(),
                        label_en: el.querySelector('.asg-l-en').value.trim(),
                        label_fil: el.querySelector('.asg-l-fil').value.trim(),
                        label_hi: el.querySelector('.asg-l-hi').value.trim(),
                        cells: []
                    });
                } else {
                    var sfsRaw = parseInt(el.querySelector('.asg-sfs').value, 10) || 0;
                    var ins = el.querySelectorAll('.asg-cell');
                    var cells = [];
                    for (var j = 0; j < ins.length; j++) {
                        cells.push(ins[j].value);
                    }
                    if (sfsRaw > 0 && cells.length > 0) {
                        cells[0] = '';
                    }
                    var sfs = sfsRaw < 0 ? 0 : sfsRaw;
                    rows.push({
                        panel_kind: panel,
                        row_kind: 'data',
                        sort_order: b,
                        size_family_size_id: sfs,
                        cells: cells
                    });
                }
            }
        });
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
        if (sid !== 0) {
            firstIn.value = asgFamilyLabelById(sid);
            firstIn.readOnly = true;
            firstIn.setAttribute('title', 'يُملأ تلقائياً من المقاس المختار (حسب القالب في المعالج)؛ للعميل بلغة صفحة المتجر');
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
        if (sid < 0) {
            hint.style.display = 'block';
            hint.textContent = 'صف من قالب المعالج (2) للمعاينة — عند الحفظ كمسودة دون عائلة يُحفظ ربط المقاس 0 حتى تُزامَن الأدلة إلى عائلة من بطاقة «ربط عائلة مستهلك بحزمة المكتبة ثم المزامنة»، ثم تختار مقاساً فعلياً من العائلة.';
            return;
        }
        if (sid <= 0) {
            hint.style.display = 'block';
            hint.textContent = fid() <= 0
                ? 'مسودة بدون عائلة: احفظ الخلايا؛ بعد مزامنة الدليل إلى عائلة من بطاقة «ربط عائلة مستهلك بحزمة المكتبة ثم المزامنة» اختر المقاس لكل صف أو «إضافة صف لكل مقاس».'
                : 'اختر مقاساً من القائمة (مرتبة حسب قالب المعالج 2) — إلزامي لكل صف بيانات.';
            return;
        }
        var name = asgFamilyLabelById(sid);
        hint.style.display = 'block';
        hint.textContent = 'أول عمود للمعاينة: «' + name + '» — للعميل بلغة صفحة المتجر (لا يُحفظ نص العمود الأول في القاعدة).';
    }

    function asgRefreshAllFamilyHints() {
        asgPanelList().forEach(function (panel) {
            document.querySelectorAll('#' + asgRowsBoxId(panel) + ' .asg-row-block[data-row-kind="data"]').forEach(function (b) {
                asgSyncFirstDataCellFromFamily(b);
                asgUpdateFamilySizeHint(b);
            });
        });
    }

    function refreshSizeSelects() {
        document.querySelectorAll('.asg-sfs').forEach(function (sel) {
            var cur = parseInt(sel.value, 10) || 0;
            sel.innerHTML = sizeOptionsHtml(cur);
        });
        asgRefreshAllFamilyHints();
    }

    document.addEventListener('change', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('asg-sfs')) {
            var div = e.target.closest('.asg-row-block');
            if (div) {
                asgSyncFirstDataCellFromFamily(div);
                asgUpdateFamilySizeHint(div);
            }
        }
    });

    document.querySelectorAll('.asg-gen-cols').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var panel = btn.getAttribute('data-asg-panel') || 'upper';
            var cur = document.querySelectorAll('#' + asgColsBodyId(panel) + ' tr').length;
            var n = parseInt(document.getElementById(asgColCountId(panel)).value, 10) || 3;
            n = Math.min(24, Math.max(1, n));
            if (cur > 0 && !confirm('«توليد صفوف العناوين» سيمسح تعريف الأعمدة الحالي ويعيد بناءه (' + n + ' أعمدة). صفوف البيانات تبقى لكن عدد خلاياها قد لا يطابق — يُفضّل استخدام + عمود / − حذف إن كنت تعدّل العدد فقط. المتابعة؟')) {
                return;
            }
            genColRows(n, panel);
        });
    });
    document.querySelectorAll('.asg-col-add').forEach(function (btn) {
        btn.addEventListener('click', function () {
            asgAppendColumnDef(btn.getAttribute('data-asg-panel') || 'upper');
        });
    });
    document.querySelectorAll('.asg-col-remove').forEach(function (btn) {
        btn.addEventListener('click', function () {
            asgRemoveLastColumnDef(btn.getAttribute('data-asg-panel') || 'upper');
        });
    });
    document.querySelectorAll('.asg-row-data').forEach(function (btn) {
        btn.addEventListener('click', function () {
            addDataRow({}, btn.getAttribute('data-asg-panel') || 'upper');
            refreshSizeSelects();
        });
    });
    document.querySelectorAll('.asg-row-label').forEach(function (btn) {
        btn.addEventListener('click', function () {
            addLabelRow({}, btn.getAttribute('data-asg-panel') || 'upper');
        });
    });

    function asgCollectLinkedSizeIds(panel) {
        panel = panel || 'upper';
        var out = {};
        document.querySelectorAll('#' + asgRowsBoxId(panel) + ' .asg-row-block').forEach(function (block) {
            if (block.dataset.rowKind !== 'data') {
                return;
            }
            var sel = block.querySelector('.asg-sfs');
            if (!sel) {
                return;
            }
            var v = parseInt(sel.value, 10) || 0;
            if (v !== 0) {
                out[v] = true;
            }
        });
        return out;
    }

    function asgBulkRowsForPanel(panel) {
        panel = panel || 'upper';
        if (!asgWizardTripleComplete()) {
            alert('أكمل القسم (1) وعائلة المقاسات (2) وشكل الدليل (3).');
            return;
        }
        var fam = effectiveFamilySizeRows();
        if (!fam.length) {
            alert('لا توجد مقاسات في عائلة المعالج — راجع عائلة المقاسات (2).');
            return;
        }
        var cols = readColumns().filter(function (c) { return (c.panel_kind || 'upper') === panel; });
        if (!cols.length) {
            alert('عرّف أعمدة ' + (panel === 'lower' ? 'القسم السفلي ' : '') + 'أولاً (عدد الأعمدة ثم توليد صفوف العناوين)');
            return;
        }
        var linked = asgCollectLinkedSizeIds(panel);
        var toAdd = [];
        for (var i = 0; i < fam.length; i++) {
            var rid = parseInt(fam[i].id, 10) || 0;
            if (rid !== 0 && !linked[rid]) {
                toAdd.push(rid);
            }
        }
        if (!toAdd.length) {
            alert('كل المقاسات المعروضة لها صف مربوط بالفعل في هذا القسم — لا يوجد جديد للإضافة');
            return;
        }
        if (!confirm('سيتم إضافة ' + toAdd.length + ' صف بيانات في ' + (panel === 'lower' ? 'القسم السفلي' : 'القسم العلوي') + '. المتابعة؟')) {
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
            addDataRow({ cells: emptyCells(), size_family_size_id: toAdd[j] }, panel);
        }
        refreshSizeSelects();
    }

    document.querySelectorAll('.asg-bulk-rows').forEach(function (btn) {
        btn.addEventListener('click', function () {
            asgBulkRowsForPanel(btn.getAttribute('data-asg-panel') || 'upper');
        });
    });

    async function loadList(opts) {
        opts = opts || {};
        if (document.getElementById('asg_unified_lib_tbody')) {
            await asgRenderUnifiedTable(opts);
            return;
        }
        var silent = !!opts.silent;
        var listWrap0 = document.getElementById('asg_list_wrap');
        if (listWrap0) {
            listWrap0.style.display = 'block';
        }
        var f = fid();
        if (f <= 0) {
            ASG_FAMILY_GUIDES_CACHE = [];
            if (!silent) {
                if (!asgWizardTripleComplete()) {
                    alert('أكمل القسم (1) وعائلة المقاسات (2) وشكل الدليل (3) أولاً');
                } else {
                    alert('لا توجد عائلة مقاسات مطابقة للمعالج (1–2–3) — يمكن العمل بمسودة من المكتبة؛ في المحرّر تُستخرج أسماء المقاسات من قالب (2). ربط الجدول بعائلة المستهلك من بطاقة «ربط عائلة مستهلك بحزمة المكتبة ثم المزامنة».');
                }
            }
            asgRefreshGuideSortDisp();
            return;
        }
        var res = await orangeAdminJsonPost(ADVISORY_API, {
            action: 'list_by_family',
            size_family_id: f,
            department_id: wizardDeptId()
        });
        if (!res.success) { alert(res.message || 'خطأ'); return; }
        var tbody = document.getElementById('asg_list_tbody');
        if (!tbody) {
            return;
        }
        tbody.innerHTML = '';
        var guides = res.guides || [];
        ASG_FAMILY_GUIDES_CACHE = guides;
        guides.forEach(function (g) {
            var gid = parseInt(g.id, 10) || 0;
            var title = (g.name_ar || ('#' + gid));
            var cols = parseInt(String(g.columns_count != null ? g.columns_count : '0'), 10) || 0;
            var rws = parseInt(String(g.rows_count != null ? g.rows_count : '0'), 10) || 0;
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td>' + gid + '</td>' +
                '<td>' + esc(title) + '</td>' +
                '<td>' + cols + '</td>' +
                '<td>' + rws + '</td>' +
                '<td><button type="button" class="btn-secondary asg-ed" data-id="' + gid + '">تعديل</button> ' +
                '<button type="button" class="btn-secondary asg-del" data-id="' + gid + '">حذف</button></td>';
            tr.querySelector('.asg-ed').onclick = function () { loadGuide(gid); };
            tr.querySelector('.asg-del').onclick = async function () {
                if (!confirm('حذف الدليل؟')) {
                    return;
                }
                var r2 = await orangeAdminJsonPost(ADVISORY_API, { action: 'delete', id: gid });
                if (!r2.success) {
                    alert(r2.message || 'خطأ');
                    return;
                }
                void loadList();
                void loadDraftList({ silent: true });
            };
            tbody.appendChild(tr);
        });
        if (!guides.length) {
            var tr0 = document.createElement('tr');
            tr0.innerHTML = '<td colspan="5" class="card-hint">لا أدلة محفوظة لهذه العائلة بعد.</td>';
            tbody.appendChild(tr0);
        }
        var listWrap = document.getElementById('asg_list_wrap');
        if (listWrap) {
            listWrap.style.display = 'block';
        }
        asgRefreshGuideSortDisp();
    }

    async function loadDraftList(opts) {
        opts = opts || {};
        var silent = !!opts.silent;
        var tb = document.getElementById('asg_draft_tbody');
        var errEl = document.getElementById('asg_draft_load_err');
        if (!tb) {
            return;
        }
        if (errEl) {
            errEl.style.display = 'none';
            errEl.textContent = '';
        }
        var res = await orangeAdminJsonPost(ADVISORY_API, { action: 'list_unbound' });
        var okUnbound = res && (res.success === true || res.success === 1 || res.success === '1' || res.success === 'true');
        if (!okUnbound) {
            var msg = (res && res.message)
                ? String(res.message)
                : 'تعذّر تحميل قائمة المسودات من الخادم.';
            if (!silent) {
                alert(msg);
                if (errEl) {
                    errEl.textContent = msg;
                    errEl.style.display = 'block';
                }
            }
            /* عند silent لا نُظهر شريط خطأ: التحديث خلفي بعد الحفظ وقد يتزامن مع طلب آخر؛ الجدول يبقى بآخر بيانات ناجحة. */
            return;
        }
        tb.innerHTML = '';
        var guides = res.guides || [];
        ASG_UNBOUND_GUIDES_CACHE = guides;
        guides.forEach(function (g) {
            var gid = parseInt(g.id, 10) || 0;
            var title = (g.name_ar || ('#' + gid));
            var cols = parseInt(String(g.columns_count != null ? g.columns_count : '0'), 10) || 0;
            var rws = parseInt(String(g.rows_count != null ? g.rows_count : '0'), 10) || 0;
            var sfDisp = (g.size_family_id == null || g.size_family_id === '') ? 'NULL' : String(parseInt(g.size_family_id, 10) || 0);
            var dDept = parseInt(g.department_id, 10) || 0;
            var dTpl = parseInt(g.size_scheme_template_id, 10) || 0;
            var dCk = String(g.commercial_kind_key || '').trim();
            var tr = document.createElement('tr');
            tr.setAttribute('data-asg-draft-row', '1');
            tr.innerHTML =
                '<td>' + gid + '</td>' +
                '<td>' + esc(title) + '</td>' +
                '<td><code>' + esc(sfDisp) + '</code></td>' +
                '<td><code>' + (dDept > 0 ? String(dDept) : '—') + '</code></td>' +
                '<td><code>' + (dTpl > 0 ? String(dTpl) : '—') + '</code></td>' +
                '<td><code>' + esc(dCk !== '' ? dCk : '—') + '</code></td>' +
                '<td>' + cols + '</td>' +
                '<td>' + rws + '</td>' +
                '<td><button type="button" class="btn-secondary asg-draft-ed" data-id="' + gid + '">تعديل</button> ' +
                '<button type="button" class="btn-secondary asg-draft-del" data-id="' + gid + '">حذف</button></td>';
            tb.appendChild(tr);
        });
        if (!guides.length) {
            var trd = document.createElement('tr');
            trd.innerHTML = '<td colspan="9" style="text-align:center;color:#94a3b8">—</td>';
            tb.appendChild(trd);
        }
        asgRefreshGuideSortDisp();
    }

    function asgWireDraftTableDelegation() {
        var tb = document.getElementById('asg_draft_tbody');
        if (!tb || tb.dataset.asgDeleg === '1') {
            return;
        }
        tb.dataset.asgDeleg = '1';
        tb.addEventListener('click', async function (ev) {
            var t = ev.target;
            if (!t || !t.closest) {
                return;
            }
            var delBtn = t.closest('.asg-draft-del');
            if (delBtn) {
                var did = parseInt(delBtn.getAttribute('data-id'), 10) || 0;
                if (!confirm('حذف هذا الدليل؟')) {
                    return;
                }
                var r2 = await orangeAdminJsonPost(ADVISORY_API, { action: 'delete', id: did });
                if (!r2 || !r2.success) {
                    alert((r2 && r2.message) ? r2.message : 'خطأ');
                    return;
                }
                void loadDraftList();
                await loadList({ silent: true });
                return;
            }
            var edBtn = t.closest('.asg-draft-ed');
            if (edBtn) {
                var eid = parseInt(edBtn.getAttribute('data-id'), 10) || 0;
                if (eid > 0) {
                    void loadGuide(eid);
                }
                return;
            }
        });
    }

    async function loadGuide(id) {
        asgNewEditorWizardSig = null;
        var res = await orangeAdminJsonPost(ADVISORY_API, { action: 'get', id: id });
        if (!res.success) { alert(res.message || 'خطأ'); return; }
        var g = res.guide;
        var gSortEl0 = document.getElementById('asg_guide_sort');
        if (gSortEl0) {
            gSortEl0.value = String(parseInt(g.sort_order, 10) || 0);
        }
        var rawFam = parseInt(String(g.size_family_id != null ? g.size_family_id : '0'), 10) || 0;
        var famInList = false;
        if (rawFam > 0) {
            for (var fxi = 0; fxi < FAMILIES_FULL.length; fxi++) {
                if (parseInt(FAMILIES_FULL[fxi].id, 10) === rawFam) {
                    famInList = true;
                    break;
                }
            }
        }
        var famId = famInList ? rawFam : 0;
        var bf = document.getElementById('asg_bound_family');
        if (bf) {
            bf.value = famId > 0 ? String(famId) : '';
        }
        if (famId > 0) {
            asgPreferFamilyOnce = famId;
            applyWizardFieldsFromFamily(famId);
            await asgRefreshResolvedContext();
        } else {
            var hid0 = document.getElementById('asg_family');
            if (hid0) {
                hid0.value = '0';
            }
            void loadDraftList({ silent: true });
        }
        (function applyGuideSavedScopeFromRow(gr) {
            var gd = parseInt(gr.department_id, 10) || 0;
            var gt = parseInt(gr.size_scheme_template_id, 10) || 0;
            var gck = String(gr.commercial_kind_key || '').trim();
            var deptEl = document.getElementById('asg_w_dept');
            var tplEl = document.getElementById('asg_w_tpl');
            var ckEl = document.getElementById('asg_w_ck');
            if (gt > 0 && tplEl) {
                tplEl.value = String(gt);
            }
            if (gck !== '' && ckEl) {
                ckEl.value = gck;
            }
            if (gd > 0 && deptEl) {
                deptEl.value = String(gd);
            }
        })(g);
        if (famId > 0) {
            await asgRefreshResolvedContext();
        }
        document.getElementById('asg_edit_id').value = String(g.id);
        var layoutKind = (g.layout_kind === 'dual') ? 'dual' : 'single';
        var layoutEl = document.getElementById('asg_w_layout');
        if (layoutEl) {
            layoutEl.value = layoutKind;
        }
        asgSyncLayoutPanels();
        document.getElementById('asg_scope').value = layoutKind === 'dual' ? 'dual' : (g.scope_kind || 'single');
        document.getElementById('asg_active').value = String(parseInt(g.is_active, 10) ? 1 : 0);
        document.getElementById('asg_name_ar').value = g.name_ar || '';
        fillColumns(res.columns || []);
        clearRows();
        (res.rows || []).forEach(function (r) {
            var panel = (r.panel_kind === 'lower') ? 'lower' : 'upper';
            if (r.row_kind === 'label') {
                addLabelRow(r, panel);
            } else {
                addDataRow({ cells: r.cells || [], size_family_size_id: r.size_family_size_id }, panel);
            }
        });
        refreshSizeSelects();
        document.getElementById('asg_editor').style.display = 'block';
        document.getElementById('asg_editor_title').textContent = 'تعديل دليل #' + g.id;
        asgRefreshGuideSortDisp();
        document.getElementById('asg_editor').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function openNew(preserveSnap) {
        if (preserveSnap && preserveSnap.d > 0 && preserveSnap.f > 0) {
            asgApplyWizardTriple(preserveSnap);
        }
        if (!asgWizardTripleComplete()) {
            alert('أكمل اختيار القسم (1) وعائلة المقاسات (2) وشكل الدليل (3) قبل «دليل جديد».');
            return;
        }
        asgClearNewEditorFormFields();
        if (preserveSnap && preserveSnap.d > 0 && preserveSnap.f > 0) {
            asgApplyWizardTriple(preserveSnap);
        }
        document.getElementById('asg_editor').style.display = 'block';
        asgNewEditorWizardSig = asgWizardSigStr();
        void asgRefreshGuideSortDisp();
    }

    document.getElementById('asg_new_btn').onclick = openNew;

    document.getElementById('asg_w_family').onchange = function () {
        syncWizardFromFamily(wizardFamilyId());
        asgSyncLayoutPanels();
        void asgRefreshResolvedContext();
    };
    document.getElementById('asg_w_layout').onchange = function () {
        asgSyncLayoutPanels();
        asgMaybeResetNewEditorIfWizardChanged();
    };
    document.getElementById('asg_w_tpl').onchange = asgRefreshResolvedContext;
    document.getElementById('asg_w_ck').onchange = asgRefreshResolvedContext;
    document.getElementById('asg_w_dept').onchange = asgRefreshResolvedContext;

    /** كل صف في جدول تعريف الأعمدة الظاهر: عربي و EN و Fil و Hi إلزامية للحفظ (باقي حقول العمود اختيارية حيث ينطبق). */
    function asgValidateColumnDefs(cols) {
        cols = cols || [];
        if (cols.length <= 0) {
            return 'عرّف الأعمدة أولاً (عدد الأعمدة ثم «توليد صفوف العناوين»).';
        }
        for (var i = 0; i < cols.length; i++) {
            var a = (cols[i].label_ar || '').trim();
            var e = (cols[i].label_en || '').trim();
            var f = (cols[i].label_fil || '').trim();
            var h = (cols[i].label_hi || '').trim();
            if (a === '' || e === '' || f === '' || h === '') {
                return 'في جدول تعريف الأعمدة، الصف ' + (i + 1) + ': أكمل الحقول الإلزامية (عربي، EN، Fil، Hi).';
            }
        }
        return '';
    }

    /** خلايا الصف بعد تفريغ العمود الأول عند ربط مقاس (مثل الحمولة المرسلة) — يطابق أعمدة الجدول بعد التحقق من عناوين الأعمدة الأربعة. */
    function asgRowCellsEffectiveForSave(r, cols, famOk) {
        var raw = (r && r.cells) ? r.cells.slice() : [];
        while (raw.length < (cols || []).length) {
            raw.push('');
        }
        var sid0 = parseInt(r.size_family_size_id, 10) || 0;
        if (famOk && sid0 > 0 && raw.length > 0) {
            raw[0] = '';
        }
        var eff = [];
        for (var ii = 0; ii < (cols || []).length; ii++) {
            eff.push(String(raw[ii] != null ? raw[ii] : '').trim());
        }
        return eff;
    }

    function asgValidateRowsBeforeSave(rows, cols) {
        cols = cols || [];
        rows = rows || [];
        var panels = asgPanelList();
        for (var pi = 0; pi < panels.length; pi++) {
            var panel = panels[pi];
            var panelPrefix = panel === 'lower' ? 'القسم السفلي: ' : '';
            var panelCols = cols.filter(function (c) { return (c.panel_kind || 'upper') === panel; });
            var colDefErr = asgValidateColumnDefs(panelCols);
            if (colDefErr) {
                return panelPrefix + colDefErr;
            }
            var panelRows = rows.filter(function (r) { return (r.panel_kind || 'upper') === panel; });
            var seen = {};
            var hasData = false;
            var famOk = fid() > 0;
            var dataRowNum = 0;
            for (var i = 0; i < panelRows.length; i++) {
                var r = panelRows[i];
                if (!r || r.row_kind !== 'data') {
                    continue;
                }
                hasData = true;
                dataRowNum++;
                var sid = parseInt(r.size_family_size_id, 10) || 0;
                if (!famOk) {
                    if (sid > 0) {
                        return 'مسودة بدون عائلة: لا تربط صفاً بمقاس فعلي قبل مزامنة الدليل إلى عائلة — استخدم بطاقة «ربط عائلة مستهلك بحزمة المكتبة ثم المزامنة»، ثم افتح التعديل على الدليل بعد ارتباطه بالعائلة المستهدفة.';
                    }
                } else {
                    if (sid <= 0) {
                        return panelPrefix + 'في صف البيانات رقم ' + dataRowNum + ': اختر مقاساً من القائمة.';
                    }
                    if (seen[sid]) {
                        return panelPrefix + 'نفس المقاس مكرر في أكثر من صف — اربط كل مقاس مرة واحدة فقط في هذا القسم.';
                    }
                    seen[sid] = true;
                }
                var effCells = asgRowCellsEffectiveForSave(r, panelCols, famOk);
                var ne = effCells.length;
                var startIx = (famOk && sid > 0) ? 1 : 0;
                if (!(famOk && sid > 0 && ne === 1)) {
                    for (var j = startIx; j < ne; j++) {
                        if (effCells[j] === '') {
                            return panelPrefix + 'أكمل جميع خلايا صف البيانات رقم ' + dataRowNum + ' (العمود ' + (j + 1) + ').';
                        }
                    }
                }
            }
            if (!hasData) {
                return panelPrefix + 'أضف صف بيانات واحداً على الأقل.';
            }
        }
        return '';
    }

    document.getElementById('asg_save_btn').onclick = async function () {
        var f = fid();
        var boundStored = parseInt(document.getElementById('asg_bound_family').value, 10) || 0;
        var isUnboundContext = boundStored <= 0;
        if (f <= 0 && !isUnboundContext) {
            alert('أكمل القسم (1) وعائلة المقاسات (2) وشكل الدليل (3)، أو افتح مسودة من «مكتبة جداول المقاسات الإرشادية»');
            return;
        }
        if (wizardDeptId() <= 0) {
            alert('اختر القسم الرئيسي (1) في بطاقة المعالج — يُحفظ مع الدليل.');
            return;
        }
        if (wizardFamilyId() <= 0) {
            alert('اختر عائلة المقاسات (2) في بطاقة المعالج — تُربط بالدليل عند الحفظ.');
            return;
        }
        var nameAr0 = document.getElementById('asg_name_ar').value.trim();
        if (nameAr0 === '') {
            alert('اسم النموذج الداخلي (عربي) إلزامي قبل الحفظ.');
            return;
        }
        var colsPayload = readColumns();
        var rowsPayload = readRowsPayload();
        var rowErr = asgValidateRowsBeforeSave(rowsPayload, colsPayload);
        if (rowErr) {
            alert(rowErr);
            return;
        }
        var payload = {
            action: 'save',
            id: parseInt(document.getElementById('asg_edit_id').value, 10) || 0,
            size_family_id: f > 0 ? f : 0,
            department_id: wizardDeptId(),
            size_scheme_template_id: wizardTplId(),
            commercial_kind_key: wizardCk(),
            scope_kind: document.getElementById('asg_scope').value,
            layout_kind: wizardLayoutKind(),
            name_ar: document.getElementById('asg_name_ar').value.trim(),
            is_active: parseInt(document.getElementById('asg_active').value, 10),
            columns: colsPayload,
            rows: rowsPayload
        };
        var res = await orangeAdminJsonPost(ADVISORY_API, payload);
        if (!res.success) {
            alert(res.message || 'خطأ');
            return;
        }
        var wizardSnap = asgSnapshotWizardTriple();
        alert('تم الحفظ');
        var bf2 = document.getElementById('asg_bound_family');
        if (bf2) {
            bf2.value = f > 0 ? String(f) : '';
        }
        await loadList({ silent: true });
        await loadDraftList({ silent: true });
        asgApplyWizardTriple(wizardSnap);
        openNew(wizardSnap);
        var ed = document.getElementById('asg_editor');
        if (ed) {
            ed.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        void asgRefreshResolvedContext();
    };

    document.getElementById('asg_cancel_btn').onclick = function () {
        document.getElementById('asg_editor').style.display = 'none';
        asgNewEditorWizardSig = null;
        void loadDraftList({ silent: true });
        void asgRenderUnifiedTable({ silent: true });
    };

    function asgMapFamLabel(id) {
        id = parseInt(id, 10) || 0;
        for (var mi = 0; mi < FAMILIES_FULL.length; mi++) {
            if (parseInt(FAMILIES_FULL[mi].id, 10) === id) {
                return (FAMILIES_FULL[mi].name_ar || FAMILIES_FULL[mi].name_en || ('#' + id));
            }
        }
        return '#' + id;
    }

    function asgFillMapBundleSelect(bundles, selectedId) {
        var sel = document.getElementById('asg_map_bundle');
        if (!sel) {
            return;
        }
        selectedId = parseInt(selectedId, 10) || 0;
        sel.innerHTML = '<option value="0">— اختر —</option>';
        for (var bi = 0; bi < bundles.length; bi++) {
            var b = bundles[bi];
            var bid = parseInt(b.id, 10) || 0;
            var lab = (b.first_guide_name_ar || b.first_guide_name_en || b.name_ar || b.name_en || ('#' + bid));
            var o = document.createElement('option');
            o.value = String(bid);
            o.textContent = lab;
            if (bid === selectedId) {
                o.selected = true;
            }
            sel.appendChild(o);
        }
    }

    async function asgLoadLibBundlesForMap() {
        if (!document.getElementById('asg_map_bundle')) {
            return [];
        }
        var res = await orangeAdminJsonPost(LIBRARY_API, { action: 'list_bundles' });
        if (!res || !res.success) {
            return [];
        }
        var bundles = res.bundles || [];
        if (ASG_LIBRARY_READY) {
            BUNDLE_SCOPES = bundles.slice();
        }
        asgFillMapBundleSelect(bundles, parseInt(document.getElementById('asg_map_bundle').value, 10) || 0);
        return bundles;
    }

    function asgLibMapWire() {
        var btnSave = document.getElementById('asg_map_save');
        if (!btnSave) {
            return;
        }
        btnSave.onclick = async function () {
            var c = parseInt(document.getElementById('asg_map_consumer').value, 10) || 0;
            var b = parseInt(document.getElementById('asg_map_bundle').value, 10) || 0;
            var res = await orangeAdminJsonPost(LIBRARY_API, { action: 'save_map', consumer_size_family_id: c, library_bundle_id: b });
            if (!res || !res.success) {
                alert((res && res.message) ? res.message : 'فشل');
                return;
            }
            alert('تم حفظ الربط');
            void asgRenderUnifiedTable({ silent: true });
        };
        document.getElementById('asg_map_sync').onclick = async function () {
            var c = parseInt(document.getElementById('asg_map_consumer').value, 10) || 0;
            if (c <= 0) {
                alert('اختر عائلة مستهلك');
                return;
            }
            if (!confirm('مزامنة الأدلة إلى هذه العائلة؟')) {
                return;
            }
            var res = await orangeAdminJsonPost(LIBRARY_API, { action: 'sync_consumer', consumer_size_family_id: c });
            if (!res || !res.success) {
                alert((res && res.message) ? res.message : 'فشل');
                return;
            }
            alert(res.message || 'تم');
            void asgRenderUnifiedTable({ silent: true });
        };
        document.getElementById('asg_map_delete').onclick = async function () {
            var c = parseInt(document.getElementById('asg_map_consumer').value, 10) || 0;
            if (c <= 0) {
                alert('اختر عائلة مستهلك');
                return;
            }
            if (!confirm('إزالة ربط عائلة المستهلك المختارة؟')) {
                return;
            }
            var res = await orangeAdminJsonPost(LIBRARY_API, { action: 'delete_map', consumer_size_family_id: c });
            if (!res || !res.success) {
                alert((res && res.message) ? res.message : 'فشل');
                return;
            }
            alert('تم');
            void asgRenderUnifiedTable({ silent: true });
        };
    }

    asgLibMapWire();

    async function asgBoot() {
        genColRows(3, 'upper');
        if (PREF_FAMILY > 0) {
            applyWizardFieldsFromFamily(PREF_FAMILY);
            asgPreferFamilyOnce = PREF_FAMILY;
        }
        await asgRefreshResolvedContext();
        void asgLoadLibBundlesForMap();
        void loadDraftList({ silent: true });
    }
    asgWireDraftTableDelegation();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', asgBoot);
    } else {
        asgBoot();
    }
})();
</script>
<style>
.asg-row-block { margin-bottom: 10px; padding: 12px; }
input.asg-cell--from-family { background: #f1f5f9; color: #475569; cursor: default; }
</style>

<?php endif; ?>
