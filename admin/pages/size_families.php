<?php

declare(strict_types=1);

$pdo = db();

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$sizingDictForFamilyForm = orange_table_exists($pdo, 'commercial_kind_dictionary')
    && orange_table_exists($pdo, 'sizing_category_dictionary');

$hasFamilies = false;
$hasSizes = false;
try {
    $hasFamilies = (bool) $pdo->query("SHOW TABLES LIKE 'size_families'")->fetchColumn();
    $hasSizes = (bool) $pdo->query("SHOW TABLES LIKE 'size_family_sizes'")->fetchColumn();
} catch (Throwable $e) {
    $hasFamilies = false;
    $hasSizes = false;
}

$families = [];
$sizesByFamily = [];
$nextSort = 1;

if ($hasFamilies) {
    try {
        $families = $pdo->query('SELECT * FROM size_families ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $nextSort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM size_families')->fetchColumn();
        if ($nextSort <= 0) {
            $nextSort = 1;
        }
    } catch (Throwable $e) {
        $families = [];
    }
}

if ($hasSizes && $hasFamilies) {
    try {
        $sStmt = $pdo->query('SELECT * FROM size_family_sizes ORDER BY size_family_id ASC, sort_order ASC, id ASC');
        foreach ($sStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $fid = (int) $s['size_family_id'];
            if (!isset($sizesByFamily[$fid])) {
                $sizesByFamily[$fid] = [];
            }
            $sizesByFamily[$fid][] = $s;
        }
    } catch (Throwable $e) {
        $sizesByFamily = [];
    }
}

$hasSizeTemplates = false;
$sizeTemplatesList = [];
if ($hasFamilies) {
    try {
        if (orange_table_exists($pdo, 'size_scheme_templates')) {
            $hasSizeTemplates = true;
            $sizeTemplatesList = $pdo->query(
                'SELECT id, name_ar, name_en FROM size_scheme_templates WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $hasSizeTemplates = false;
        $sizeTemplatesList = [];
    }
}

$tablesReady = $hasFamilies && $hasSizes;
?>
<div class="page-title">
    <h1>عائلات المقاسات</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (!$tablesReady): ?>
<div class="card">
    <div class="alert-error">جداول <code>size_families</code> أو <code>size_family_sizes</code> غير متاحة. تحقق من صلاحيات قاعدة البيانات ثم حدّث الصفحة.</div>
</div>
<?php endif; ?>

<style>
    /* نفس منطق شبكة بطاقة «نوع تجاري (المستوى 1)» + صف الـ parent من «فئة قياس» في sizing_dictionary.php */
    .sf-fam-form-grid {
        display: grid;
        grid-template-columns: repeat(12, minmax(0, 1fr));
        grid-template-areas:
            "active active scheme scheme scheme scheme scheme scheme scheme scheme sort sort"
            "hrow hrow hrow hrow hrow hrow hrow hrow hrow hrow hrow hrow"
            "names names names names names names names names names names names names";
        gap: 14px 18px;
        direction: ltr;
        align-items: start;
    }
    .sf-fam-form-grid .sf-fam-sort {
        grid-area: sort;
        justify-self: end;
        width: 100%;
    }
    .sf-fam-form-grid .sf-fam-active {
        grid-area: active;
        justify-self: start;
        width: 100%;
    }
    .sf-fam-form-grid .sf-fam-scheme {
        grid-area: scheme;
        min-width: 0;
    }
    .sf-fam-form-grid .sf-fam-hierarchy-row {
        grid-area: hrow;
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px 18px;
        direction: rtl;
        align-items: start;
        min-width: 0;
    }
    .sf-fam-form-grid .sf-fam-hierarchy-row > .sf-fam-comm,
    .sf-fam-form-grid .sf-fam-hierarchy-row > .sf-fam-siz,
    .sf-fam-form-grid .sf-fam-hierarchy-row > .sf-fam-tpl {
        min-width: 0;
    }
    .sf-fam-form-grid .sf-fam-names-row {
        grid-area: names;
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px 18px;
        direction: rtl;
        align-items: start;
        min-width: 0;
    }
    .sf-fam-form-grid .sf-fam-names-row > div {
        min-width: 0;
    }
    .sf-fam-form-grid .sf-fam-names-row .admin-sort-field-wrap {
        max-width: none;
        width: 100%;
    }
    .sf-fam-form-grid .sf-fam-names-row input.admin-sort-field {
        margin-inline: 0;
        display: block;
        width: 100%;
        max-width: none;
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
    .sf-fam-form-grid .sf-fam-names-row input.admin-sort-field--muted[readonly] {
        background: #f4f6f9;
        cursor: default;
    }
    .sf-fam-form-grid label,
    .sf-fam-form-grid input,
    .sf-fam-form-grid select,
    .sf-fam-form-grid small {
        direction: rtl;
        text-align: right;
    }
    .sf-fam-form-grid .sf-fam-scheme label,
    .sf-fam-form-grid .sf-fam-comm label,
    .sf-fam-form-grid .sf-fam-siz label,
    .sf-fam-form-grid .sf-fam-tpl label {
        word-break: break-word;
        overflow-wrap: anywhere;
    }
    .sf-fam-form-grid #fam_sort {
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
        text-align: center;
    }
    .sf-fam-form-grid #fam_size_scheme_key {
        width: 100%;
        max-width: none;
        box-sizing: border-box;
        cursor: default;
        background: #f4f6f9;
    }
    .sf-fam-form-grid #fam_active,
    .sf-fam-form-grid select#fam_commercial_kind_key,
    .sf-fam-form-grid select#fam_sizing_category_key,
    .sf-fam-form-grid select#sizes_template_pick {
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
    .sf-fam-form-grid input#fam_commercial_kind_key,
    .sf-fam-form-grid input#fam_sizing_category_key {
        width: 100%;
        max-width: none;
        box-sizing: border-box;
    }
    /* يتجاوز حد admin.css على select.admin-sort-field (220px) ليطابق عرض #fam_name_ar داخل الشبكة */
    .sf-fam-form-grid select#fam_commercial_kind_key,
    .sf-fam-form-grid select#fam_sizing_category_key,
    .sf-fam-form-grid select#sizes_template_pick {
        max-width: none;
    }
    .sf-fam-form-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        justify-content: flex-start;
        flex-direction: row;
        direction: ltr;
    }
    .sf-fam-form-actions > button,
    .sf-fam-form-actions > a.btn-secondary {
        min-height: var(--input-min-h, 40px);
        box-sizing: border-box;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    /* إظهار زر الترجمة لاحقاً: أزل class التالي من الزر أو عيّن display:inline-flex في التطوير */
    .sf-fam-form-actions .sf-fam-translate-btn--hidden {
        display: none !important;
    }
    @media (max-width: 720px) {
        .sf-fam-form-grid .sf-fam-hierarchy-row {
            grid-template-columns: 1fr;
        }
        .sf-fam-form-grid .sf-fam-names-row {
            grid-template-columns: 1fr;
        }
        .sf-fam-form-grid {
            grid-template-columns: 1fr;
            grid-template-areas:
                "sort"
                "scheme"
                "active"
                "hrow"
                "names";
        }
        .sf-fam-form-grid .sf-fam-sort,
        .sf-fam-form-grid .sf-fam-active {
            justify-self: start;
            max-width: var(--admin-sort-field-max-w, 220px);
        }
    }
</style>

<div class="card" id="sf_section_family_form" tabindex="-1">
    <h3>إضافة / تعديل عائلة</h3>
    <input type="hidden" id="fam_id" value="0">
    <div class="form-grid sf-fam-form-grid">
        <div class="sf-fam-sort admin-sort-field-wrap">
            <label>الترتيب</label>
            <input type="number" id="fam_sort" class="admin-sort-field admin-sort-field--muted" value="<?php echo (int) $nextSort; ?>" disabled>
        </div>
        <div class="sf-fam-scheme">
            <label for="fam_size_scheme_key">size_scheme_key</label>
            <input type="text" id="fam_size_scheme_key" maxlength="64" readonly tabindex="-1" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
        </div>
        <div class="sf-fam-active admin-sort-field-wrap">
            <label>نشط</label>
            <select id="fam_active" class="admin-sort-field" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
                <option value="1">نعم</option>
                <option value="0">لا</option>
            </select>
        </div>
        <div class="sf-fam-hierarchy-row">
            <div class="sf-fam-comm">
                <?php if ($sizingDictForFamilyForm): ?>
                <label>النوع التجاري (مستوى 1)</label>
                <select id="fam_commercial_kind_key" class="admin-sort-field" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
                    <option value="">— اختر النوع —</option>
                </select>
                <?php else: ?>
                <label><code>commercial_kind_key</code> (مستوى 1)</label>
                <input type="text" id="fam_commercial_kind_key" class="admin-sort-field" maxlength="32" placeholder="clothing" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
                <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;line-height:1.4;">أدخل المفتاح يدوياً أو فعّل جدايل القاموس في المخطّط.</small>
                <?php endif; ?>
            </div>
            <div class="sf-fam-siz">
                <?php if ($sizingDictForFamilyForm): ?>
                <label>فئة القياس (مستوى 2)</label>
                <select id="fam_sizing_category_key" class="admin-sort-field" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
                    <option value="">— اختر النوع أولاً —</option>
                </select>
                <?php else: ?>
                <label><code>sizing_category_key</code> (مستوى 2)</label>
                <input type="text" id="fam_sizing_category_key" class="admin-sort-field" maxlength="64" placeholder="tops" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
                <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;line-height:1.4;">أدخل المفتاح يدوياً.</small>
                <?php endif; ?>
            </div>
            <div class="sf-fam-tpl">
                <label>قالب المقاسات</label>
                <?php if ($hasSizeTemplates && count($sizeTemplatesList) > 0): ?>
                <select id="sizes_template_pick" class="admin-sort-field" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
                    <option value="">— اختر قالباً —</option>
                    <?php foreach ($sizeTemplatesList as $tpl): ?>
                    <option value="<?php echo (int) $tpl['id']; ?>" data-name-en="<?php echo htmlspecialchars((string) ($tpl['name_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) ($tpl['name_ar'] ?: $tpl['name_en']), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <select id="sizes_template_pick" class="admin-sort-field" style="display:none;" aria-hidden="true" <?php echo !$hasFamilies ? 'disabled' : ''; ?>><option value=""></option></select>
                <span style="font-size:0.88rem;color:#92400e;">لا توجد قوالب مفعّلة.</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="sf-fam-names-row">
            <div class="admin-sort-field-wrap">
                <label for="fam_name_ar">الاسم العربي</label>
                <input type="text" id="fam_name_ar" class="admin-sort-field<?php echo $sizingDictForFamilyForm ? ' admin-sort-field--muted' : ''; ?>" maxlength="191" autocomplete="off"<?php
                if ($sizingDictForFamilyForm && $hasFamilies) {
                    echo ' readonly tabindex="-1" aria-readonly="true" title="يُشتق تلقائياً من النوع التجاري وفئة القياس والقالب — لا يُعدَّل يدوياً"';
                }
                echo !$hasFamilies ? ' disabled' : '';
                ?>>
            </div>
            <div class="admin-sort-field-wrap">
                <label for="fam_name_en">English</label>
                <input type="text" id="fam_name_en" class="admin-sort-field<?php echo $sizingDictForFamilyForm ? ' admin-sort-field--muted' : ''; ?>" maxlength="191" autocomplete="off"<?php
                if ($sizingDictForFamilyForm && $hasFamilies) {
                    echo ' readonly tabindex="-1" aria-readonly="true" title="يُشتق تلقائياً من النوع التجاري وفئة القياس والقالب — لا يُعدَّل يدوياً"';
                }
                echo !$hasFamilies ? ' disabled' : '';
                ?>>
            </div>
            <div class="admin-sort-field-wrap">
                <label for="fam_name_fil">Filipino</label>
                <input type="text" id="fam_name_fil" class="admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" autocomplete="off" title="يُطابق English" aria-readonly="true" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
            </div>
            <div class="admin-sort-field-wrap">
                <label for="fam_name_hi">Hindi</label>
                <input type="text" id="fam_name_hi" class="admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" autocomplete="off" title="يُطابق English" aria-readonly="true" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
            </div>
        </div>
    </div>
    <div class="actions sf-fam-form-actions" style="margin-top:14px;">
        <button type="button" class="btn-secondary" onclick="resetFamilyForm()" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>جديد</button>
        <button type="button" class="btn-secondary sf-fam-translate-btn--hidden" id="fam_btn_translate_en" onclick="translateFamilyEn({ forceFromArabic: true })" <?php echo !$hasFamilies ? 'disabled' : ''; ?> title="مخفي عن المستخدم؛ يبقى لاستدعاء الترجمة عند التطوير">ترجمة إلى English</button>
        <?php if ($hasSizeTemplates && count($sizeTemplatesList) > 0): ?>
        <button type="button" class="btn-secondary" id="fam_btn_import_template" onclick="importSizeTemplateRows()" <?php echo !$hasFamilies || !$tablesReady ? 'disabled' : ''; ?>>تحميل المقاسات من القالب</button>
        <?php else: ?>
        <button type="button" class="btn-secondary" id="fam_btn_import_template" disabled title="لا توجد قوالب">تحميل المقاسات من القالب</button>
        <?php endif; ?>
        <a class="btn-secondary" id="fam_link_manage_templates" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=size_scheme_templates'), ENT_QUOTES, 'UTF-8'); ?>">إدارة القوالب</a>
        <button type="button" onclick="saveFamily()" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>حفظ العائلة</button>
    </div>
</div>

<div class="card">
    <h3>مقاسات داخل العائلة</h3>
    <div id="sizesEditor" style="margin-top:12px;"></div>
    <div class="actions sf-sizes-actions" style="margin-top:14px;">
        <button type="button" class="btn-secondary" onclick="famAddEmptySizeRow()" <?php echo !$tablesReady ? 'disabled' : ''; ?>>إضافة مقاس</button>
        <button type="button" onclick="saveSizesForFamily()" <?php echo !$tablesReady ? 'disabled' : ''; ?>>حفظ المقاسات</button>
    </div>
</div>

<?php if ($hasFamilies): ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;">قائمة العائلات</h3>
        <div class="actions">
            <button type="button" class="btn-secondary" onclick="saveFamiliesOrder()">حفظ الترتيب</button>
        </div>
    </div>
    <div class="table-wrap cat-dep-list-wrap" data-list="size-families" style="margin-top:10px;">
        <table>
            <thead>
                <tr>
                    <th class="sf-col-id">#</th>
                    <th class="sf-col-ar">العربي</th>
                    <th class="sf-col-en">English</th>
                    <th class="sf-col-scheme">scheme</th>
                    <th class="sf-col-szcount">عدد المقاسات</th>
                    <th class="sf-col-sort">الترتيب</th>
                    <th class="sf-col-status">الحالة</th>
                    <th class="sf-ops-col">إجراءات</th>
                </tr>
            </thead>
            <tbody id="orange-families-list-tbody">
                <?php foreach ($families as $f): ?>
                <tr data-id="<?php echo (int) $f['id']; ?>">
                    <td class="sf-col-id"><?php echo (int) $f['id']; ?></td>
                    <td class="sf-col-ar"><?php echo htmlspecialchars((string) $f['name_ar'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="sf-col-en"><?php echo htmlspecialchars((string) $f['name_en'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="sf-col-scheme"><?php echo htmlspecialchars((string) ($f['size_scheme_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="sf-col-szcount"><?php echo isset($sizesByFamily[(int) $f['id']]) ? count($sizesByFamily[(int) $f['id']]) : 0; ?></td>
                    <td class="sf-col-sort"><?php echo (int) $f['sort_order']; ?></td>
                    <td class="sf-col-status"><?php echo (int) $f['is_active'] === 1 ? 'ظاهر' : 'مخفي'; ?></td>
                    <td class="sf-row-ops">
                        <div class="sf-ops-wrap">
                            <div class="sf-ops-arrows">
                                <button type="button" class="btn-secondary sf-btn-reorder" onclick="moveFamilyRow(this,'up')" aria-label="أعلى">↑</button>
                                <button type="button" class="btn-secondary sf-btn-reorder" onclick="moveFamilyRow(this,'down')" aria-label="أسفل">↓</button>
                            </div>
                            <div class="sf-ops-main">
                                <button type="button" class="btn-secondary sf-edit-btn" data-family-json="<?php echo htmlspecialchars(json_encode([
                                    'id' => (int) $f['id'],
                                    'name_ar' => (string) $f['name_ar'],
                                    'name_en' => (string) $f['name_en'],
                                    'name_fil' => (string) ($f['name_fil'] ?? ''),
                                    'name_hi' => (string) ($f['name_hi'] ?? ''),
                                    'size_scheme_key' => (string) ($f['size_scheme_key'] ?? ''),
                                    'commercial_kind_key' => (string) ($f['commercial_kind_key'] ?? ''),
                                    'sizing_category_key' => (string) ($f['sizing_category_key'] ?? ''),
                                    'size_scheme_template_id' => (int) ($f['size_scheme_template_id'] ?? 0),
                                    'sort_order' => (int) $f['sort_order'],
                                    'is_active' => (int) $f['is_active'],
                                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">تعديل</button>
                                <button type="button" class="sf-btn-toggle" onclick="toggleFamily(<?php echo (int) $f['id']; ?>, <?php echo (int) $f['is_active']; ?>)">
                                    <?php echo (int) $f['is_active'] === 1 ? 'إخفاء' : 'إظهار'; ?>
                                </button>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
var ORANGE_SIZES_BY_FAMILY = <?php echo json_encode($sizesByFamily, JSON_UNESCAPED_UNICODE); ?>;
var SST_IMPORT_API = '/admin/api/size_scheme_templates/manage.php';
var FAM_HAS_SIZE_TEMPLATES = <?php echo ($hasSizeTemplates && count($sizeTemplatesList) > 0) ? 'true' : 'false'; ?>;
const defaultNextFamilySort = <?php echo (int) $nextSort; ?>;
var FAM_SIZING_DICT_SELECTS = <?php echo $sizingDictForFamilyForm ? 'true' : 'false'; ?>;
var FAM_SD_API = '/admin/api/sizing_dictionary/manage.php';
let familyTranslateTimer = null;
let famEnFilHiTimer = null;
let isSavingFamily = false;

function famSyncFamFilHiFromEn() {
    var enEl = document.getElementById('fam_name_en');
    var filEl = document.getElementById('fam_name_fil');
    var hiEl = document.getElementById('fam_name_hi');
    if (!enEl || !filEl || !hiEl) {
        return;
    }
    var v = enEl.value;
    if (!String(v).trim()) {
        filEl.value = '';
        hiEl.value = '';
        return;
    }
    filEl.value = v;
    hiEl.value = v;
}

function famSizingSlugKey(raw, maxLen) {
    var t = String(raw || '').trim().toLowerCase();
    t = t.replace(/[^a-z0-9_-]/g, '');
    if (t.length > maxLen) {
        t = t.substring(0, maxLen);
    }
    return t;
}

function famHierarchyFieldValue(id) {
    var el = document.getElementById(id);
    if (!el) return '';
    return String(el.value || '').trim();
}

/** مستوى 3: slug(فئة القياس) + '_' + slug(English اسم القالب). */
function famApplyAutoSizeSchemeKey() {
    var keyEl = document.getElementById('fam_size_scheme_key');
    if (!keyEl) return;
    var sk = famSizingSlugKey(famHierarchyFieldValue('fam_sizing_category_key'), 64);
    var tplEl = document.getElementById('sizes_template_pick');
    var tplSlug = '';
    if (tplEl && tplEl.tagName === 'SELECT' && String(tplEl.value || '').trim() !== '') {
        var opt = tplEl.options[tplEl.selectedIndex];
        var ne = opt && opt.getAttribute('data-name-en') ? String(opt.getAttribute('data-name-en')) : '';
        tplSlug = famSizingSlugKey(ne, 64);
        if (!tplSlug) {
            tplSlug = famSizingSlugKey('tpl_' + String(tplEl.value || '').trim(), 64);
        }
    }
    var combined = '';
    if (sk && tplSlug) {
        combined = sk + '_' + tplSlug;
    } else if (sk) {
        combined = sk;
    }
    if (combined.length > 64) {
        combined = combined.substring(0, 64);
    }
    keyEl.value = combined;
}

function famReadSelectOptionLabels(sel) {
    if (!sel || sel.tagName !== 'SELECT') {
        return { ar: '', en: '' };
    }
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !String(sel.value || '').trim()) {
        return { ar: '', en: '' };
    }
    return {
        ar: String(opt.getAttribute('data-label-ar') || '').trim(),
        en: String(opt.getAttribute('data-label-en') || '').trim()
    };
}

/** تسمية القالب المختار: EN من data-name-en ثم نص الخيار (غالباً عربي). */
function famReadTemplatePickLabels() {
    var tplEl = document.getElementById('sizes_template_pick');
    if (!tplEl || tplEl.tagName !== 'SELECT' || !String(tplEl.value || '').trim()) {
        return { ar: '', en: '' };
    }
    var opt = tplEl.options[tplEl.selectedIndex];
    if (!opt) {
        return { ar: '', en: '' };
    }
    var en = String(opt.getAttribute('data-name-en') || '').trim();
    var ar = String(opt.textContent || '').trim();
    return { ar: ar, en: en };
}

/**
 * عند التعديل: استنتاج id القالب من size_scheme_key + sizing_category_key
 * (نفس قاعدة famApplyAutoSizeSchemeKey: slug(فئة) + '_' + slug(EN القالب)).
 */
function famResolveTemplateIdForEdit(f) {
    var scheme = String(f.size_scheme_key || '').trim().toLowerCase();
    var catKey = famSizingSlugKey(String(f.sizing_category_key || '').trim(), 64);
    var tplEl = document.getElementById('sizes_template_pick');
    if (!tplEl || tplEl.tagName !== 'SELECT' || !scheme) {
        return '';
    }
    var i;
    for (i = 0; i < tplEl.options.length; i++) {
        var o = tplEl.options[i];
        var tid = String(o.value || '').trim();
        if (!tid) {
            continue;
        }
        var ne = String(o.getAttribute('data-name-en') || '').trim();
        var tplSlug = famSizingSlugKey(ne, 64);
        if (!tplSlug) {
            tplSlug = famSizingSlugKey('tpl_' + tid, 64);
        }
        var combined = (catKey && tplSlug) ? (catKey + '_' + tplSlug) : (catKey || tplSlug);
        if (combined && combined === scheme) {
            return tid;
        }
    }
    if (catKey && scheme.indexOf(catKey + '_') === 0) {
        var suff = scheme.substring((catKey + '_').length);
        for (i = 0; i < tplEl.options.length; i++) {
            var o2 = tplEl.options[i];
            var tid2 = String(o2.value || '').trim();
            if (!tid2) {
                continue;
            }
            var ne2 = String(o2.getAttribute('data-name-en') || '').trim();
            var ts2 = famSizingSlugKey(ne2, 64) || famSizingSlugKey('tpl_' + tid2, 64);
            if (ts2 && ts2 === suff) {
                return tid2;
            }
        }
    }
    return '';
}

/**
 * من تسميات القاموس: عربي/EN من label_ar + label؛ ومع قالب مقاسات:
 * «الأساس - تسمية_القالب» (تسمية القالب: EN إن وُجدت وإلا نص الخيار).
 */
function famApplyAutoNamesFromDictionary() {
    if (!FAM_SIZING_DICT_SELECTS) {
        return;
    }
    var kSel = document.getElementById('fam_commercial_kind_key');
    var cSel = document.getElementById('fam_sizing_category_key');
    var arEl = document.getElementById('fam_name_ar');
    var enEl = document.getElementById('fam_name_en');
    if (!kSel || !cSel || !arEl || !enEl) {
        return;
    }
    var k = famReadSelectOptionLabels(kSel);
    var c = famReadSelectOptionLabels(cSel);
    var arParts = [k.ar, c.ar].filter(function (x) { return x; });
    var enParts = [k.en, c.en].filter(function (x) { return x; });
    var arBase = arParts.join(' ');
    var enBase = enParts.join(' ');
    var tpl = famReadTemplatePickLabels();
    var tToken = String(tpl.en || tpl.ar || '').trim();
    if (tToken && (arBase || enBase)) {
        if (arBase) {
            arEl.value = arBase + ' - ' + tToken;
        } else {
            arEl.value = '';
        }
        if (enBase) {
            enEl.value = enBase + ' - ' + tToken;
        } else {
            enEl.value = '';
        }
    } else {
        arEl.value = arBase;
        enEl.value = enBase;
    }
    famSyncFamFilHiFromEn();
}

function famEnsureSelectOption(sel, value, label, dataLabels) {
    if (!sel || !value) return;
    var v = String(value);
    if ([].some.call(sel.options, function (o) { return o.value === v; })) return;
    var o = document.createElement('option');
    o.value = v;
    o.textContent = label || v;
    dataLabels = dataLabels || {};
    if (dataLabels.labelAr != null) {
        o.setAttribute('data-label-ar', String(dataLabels.labelAr));
    }
    if (dataLabels.labelEn != null) {
        o.setAttribute('data-label-en', String(dataLabels.labelEn));
    }
    sel.appendChild(o);
}

async function famLoadKindsIntoSelect(preferredKind) {
    var sel = document.getElementById('fam_commercial_kind_key');
    if (!sel || sel.tagName !== 'SELECT' || typeof postJSON !== 'function') {
        famApplyAutoNamesFromDictionary();
        famApplyAutoSizeSchemeKey();
        return;
    }
    var prev = preferredKind !== undefined && preferredKind !== null ? String(preferredKind) : String(sel.value || '');
    try {
        var res = await postJSON(FAM_SD_API, { action: 'list_kinds' });
        if (!res || !res.success) return;
        var kinds = res.kinds || [];
        sel.innerHTML = '';
        var opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = '— اختر النوع —';
        sel.appendChild(opt0);
        kinds.forEach(function (k) {
            var o = document.createElement('option');
            o.value = k.kind_key || '';
            o.textContent = (k.label_ar || k.kind_key || '') + ' (' + (k.kind_key || '') + ')';
            o.setAttribute('data-label-ar', String(k.label_ar != null ? k.label_ar : ''));
            o.setAttribute('data-label-en', String(k.label_en != null ? k.label_en : ''));
            sel.appendChild(o);
        });
        if (prev) {
            famEnsureSelectOption(sel, prev, prev + ' (غير مدرَج في القاموس)', { labelAr: prev, labelEn: prev });
            sel.value = prev;
        } else {
            sel.value = '';
        }
    } catch (e) { /* ignore */ } finally {
        famApplyAutoNamesFromDictionary();
        famApplyAutoSizeSchemeKey();
    }
}

async function famLoadSizingCategoriesIntoSelect(preferredCat) {
    var kindSel = document.getElementById('fam_commercial_kind_key');
    var catSel = document.getElementById('fam_sizing_category_key');
    if (!kindSel || kindSel.tagName !== 'SELECT' || !catSel || catSel.tagName !== 'SELECT' || typeof postJSON !== 'function') {
        famApplyAutoNamesFromDictionary();
        famApplyAutoSizeSchemeKey();
        return;
    }
    var ck = String(kindSel.value || '').trim();
    var prev = preferredCat !== undefined && preferredCat !== null ? String(preferredCat) : String(catSel.value || '');
    catSel.innerHTML = '';
    var opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = ck ? '— فئة القياس —' : '— اختر النوع أولاً —';
    catSel.appendChild(opt0);
    if (!ck) {
        catSel.value = '';
        famApplyAutoNamesFromDictionary();
        famApplyAutoSizeSchemeKey();
        return;
    }
    try {
        var res = await postJSON(FAM_SD_API, { action: 'list_categories', commercial_kind_key: ck });
        if (!res || !res.success) return;
        (res.categories || []).forEach(function (c) {
            var o = document.createElement('option');
            o.value = c.category_key || '';
            o.textContent = (c.label_ar || c.category_key || '') + ' (' + (c.category_key || '') + ')';
            o.setAttribute('data-label-ar', String(c.label_ar != null ? c.label_ar : ''));
            o.setAttribute('data-label-en', String(c.label_en != null ? c.label_en : ''));
            catSel.appendChild(o);
        });
        if (prev) {
            famEnsureSelectOption(catSel, prev, prev + ' (غير مدرَج في القاموس)', { labelAr: prev, labelEn: prev });
            catSel.value = prev;
        } else {
            catSel.value = '';
        }
    } catch (e) { /* ignore */ } finally {
        famApplyAutoNamesFromDictionary();
        famApplyAutoSizeSchemeKey();
    }
}

function famInitSizingHierarchySelects() {
    if (!FAM_SIZING_DICT_SELECTS) return;
    var kindSel = document.getElementById('fam_commercial_kind_key');
    var catSel = document.getElementById('fam_sizing_category_key');
    if (!kindSel || kindSel.tagName !== 'SELECT') return;
    kindSel.addEventListener('change', function () {
        void famLoadSizingCategoriesIntoSelect('');
    });
    if (catSel && catSel.tagName === 'SELECT') {
        catSel.addEventListener('change', function () {
            famApplyAutoNamesFromDictionary();
            famApplyAutoSizeSchemeKey();
        });
    }
    void famLoadKindsIntoSelect('').then(function () {
        return famLoadSizingCategoriesIntoSelect('');
    });
}

function resetFamilyForm() {
    document.getElementById('fam_id').value = '0';
    document.getElementById('fam_name_ar').value = '';
    document.getElementById('fam_name_en').value = '';
    var filEl = document.getElementById('fam_name_fil');
    var hiEl = document.getElementById('fam_name_hi');
    if (filEl) filEl.value = '';
    if (hiEl) hiEl.value = '';
    var tplPick = document.getElementById('sizes_template_pick');
    if (tplPick && tplPick.tagName === 'SELECT') {
        tplPick.value = '';
    }
    famApplyAutoSizeSchemeKey();
    var ckEl = document.getElementById('fam_commercial_kind_key');
    var skEl = document.getElementById('fam_sizing_category_key');
    if (FAM_SIZING_DICT_SELECTS && ckEl && ckEl.tagName === 'SELECT') {
        void famLoadKindsIntoSelect('').then(function () {
            return famLoadSizingCategoriesIntoSelect('');
        });
    } else {
        if (ckEl) ckEl.value = '';
        if (skEl) skEl.value = '';
        famApplyAutoSizeSchemeKey();
    }
    document.getElementById('fam_sort').value = String(defaultNextFamilySort || 1);
    document.getElementById('fam_active').value = '1';
    loadSizesEditor();
}

async function editFamily(f) {
    document.getElementById('fam_id').value = String(f.id != null ? f.id : 0);
    if (FAM_SIZING_DICT_SELECTS) {
        await famLoadKindsIntoSelect(f.commercial_kind_key || '');
        await famLoadSizingCategoriesIntoSelect(f.sizing_category_key || '');
        var tplPick = document.getElementById('sizes_template_pick');
        if (tplPick && tplPick.tagName === 'SELECT') {
            var byScheme = famResolveTemplateIdForEdit(f) || '';
            var byCol = '';
            if (f.size_scheme_template_id != null) {
                var tc = parseInt(String(f.size_scheme_template_id), 10) || 0;
                if (tc > 0 && [].some.call(tplPick.options, function (o) { return String(o.value) === String(tc); })) {
                    byCol = String(tc);
                }
            }
            tplPick.value = byScheme || byCol || '';
        }
        document.getElementById('fam_name_ar').value = String(f.name_ar || '');
        document.getElementById('fam_name_en').value = String(f.name_en || '');
        famSyncFamFilHiFromEn();
    } else {
        document.getElementById('fam_name_ar').value = f.name_ar || '';
        document.getElementById('fam_name_en').value = f.name_en || '';
        document.getElementById('fam_commercial_kind_key').value = f.commercial_kind_key || '';
        document.getElementById('fam_sizing_category_key').value = f.sizing_category_key || '';
        famSyncFamFilHiFromEn();
    }
    famApplyAutoSizeSchemeKey();
    var keyEl = document.getElementById('fam_size_scheme_key');
    if (keyEl && f.size_scheme_key != null && String(f.size_scheme_key).trim() !== '') {
        var wantKey = String(f.size_scheme_key).trim();
        if (String(keyEl.value || '').trim() !== wantKey) {
            keyEl.value = wantKey;
        }
    }
    document.getElementById('fam_sort').value = String(f.sort_order ?? 0);
    document.getElementById('fam_active').value = String(f.is_active === 0 ? 0 : 1);
    loadSizesEditor();
    var sec = document.getElementById('sf_section_family_form');
    if (sec) {
        sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
        setTimeout(function () {
            try {
                document.getElementById('fam_name_ar').focus({ preventScroll: true });
            } catch (e) {
                document.getElementById('fam_name_ar').focus();
            }
        }, 350);
    }
}

async function translateFamilyEn(opts) {
    opts = opts || {};
    var silent = !!opts.silent;
    var forceFromArabic = !!opts.forceFromArabic;
    try {
        var payload = {
            name_ar: document.getElementById('fam_name_ar').value.trim(),
            name_en: forceFromArabic ? '' : document.getElementById('fam_name_en').value.trim()
        };
        var res = await postJSON('/admin/api/translate/names.php', payload);
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        var t = res.translations || {};
        if (t.name_en) {
            document.getElementById('fam_name_en').value = t.name_en;
        }
        famSyncFamFilHiFromEn();
        famApplyAutoSizeSchemeKey();
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة من السيرفر');
    }
}

function scheduleFamilyEnTranslate() {
    var nameAr = document.getElementById('fam_name_ar').value.trim();
    if (!nameAr) {
        document.getElementById('fam_name_en').value = '';
        famSyncFamFilHiFromEn();
        famApplyAutoSizeSchemeKey();
        return;
    }
    clearTimeout(familyTranslateTimer);
    familyTranslateTimer = setTimeout(function () {
        translateFamilyEn({ silent: true, forceFromArabic: true });
    }, 600);
}

async function saveFamily() {
    if (isSavingFamily) return;
    isSavingFamily = true;
    famApplyAutoSizeSchemeKey();
    famSyncFamFilHiFromEn();
    if (!document.getElementById('fam_name_ar').value.trim() || !document.getElementById('fam_name_en').value.trim()) {
        alert('يجب تعبئة الاسم العربي والإنجليزي قبل الحفظ');
        isSavingFamily = false;
        return;
    }
    if (FAM_SIZING_DICT_SELECTS) {
        var kindEl = document.getElementById('fam_commercial_kind_key');
        var catEl = document.getElementById('fam_sizing_category_key');
        var ck = kindEl ? String(kindEl.value || '').trim() : '';
        var sk = catEl ? String(catEl.value || '').trim() : '';
        if (!ck) {
            alert('اختر النوع التجاري (مستوى 1) قبل الحفظ.');
            isSavingFamily = false;
            return;
        }
        if (!sk) {
            alert('اختر فئة القياس (مستوى 2) قبل الحفظ.');
            isSavingFamily = false;
            return;
        }
        if (FAM_HAS_SIZE_TEMPLATES) {
            var tplEl = document.getElementById('sizes_template_pick');
            var tid = tplEl && tplEl.tagName === 'SELECT' ? (parseInt(tplEl.value || '0', 10) || 0) : 0;
            if (tid <= 0) {
                alert('اختر قالب المقاسات قبل الحفظ.');
                isSavingFamily = false;
                return;
            }
        }
    }
    try {
        var rawId = parseInt(String(document.getElementById('fam_id').value || '0').trim(), 10);
        var recordId = (Number.isFinite(rawId) && rawId > 0) ? rawId : 0;
        var payload = {
            name_ar: document.getElementById('fam_name_ar').value.trim(),
            name_en: document.getElementById('fam_name_en').value.trim(),
            name_fil: document.getElementById('fam_name_fil').value.trim(),
            name_hi: document.getElementById('fam_name_hi').value.trim(),
            size_scheme_key: document.getElementById('fam_size_scheme_key').value.trim(),
            commercial_kind_key: document.getElementById('fam_commercial_kind_key').value.trim(),
            sizing_category_key: document.getElementById('fam_sizing_category_key').value.trim(),
            sort_order: parseInt(document.getElementById('fam_sort').value || '0', 10),
            is_active: parseInt(document.getElementById('fam_active').value, 10)
        };
        if (recordId > 0) payload.id = recordId;
        var tplPickSave = document.getElementById('sizes_template_pick');
        var tplRefSave = (tplPickSave && tplPickSave.tagName === 'SELECT') ? (parseInt(tplPickSave.value || '0', 10) || 0) : 0;
        payload.size_scheme_template_id = tplRefSave;
        var res = await postJSON('/admin/api/size_families/save.php', payload);
        if (!res || !res.success) {
            alert((res && res.message) ? res.message : 'فشل');
            return;
        }
        var savedId = parseInt(String(res.id != null ? res.id : (recordId > 0 ? recordId : 0)), 10) || 0;
        if (savedId > 0) {
            document.getElementById('fam_id').value = String(savedId);
        }
        var sizeRowsPayload = famCollectSizesRowsFromEditor();
        if (sizeRowsPayload.length > 0 && savedId > 0) {
            var syncTpl = document.getElementById('sizes_template_pick');
            var syncTplId = (syncTpl && syncTpl.tagName === 'SELECT') ? (parseInt(syncTpl.value || '0', 10) || 0) : 0;
            var sz = await postJSON('/admin/api/size_families/save_sizes.php', {
                family_id: savedId,
                sizes: sizeRowsPayload,
                sync_template_id: syncTplId
            });
            if (!sz || !sz.success) {
                alert('تم حفظ العائلة لكن حفظ المقاسات فشل: ' + ((sz && sz.message) ? sz.message : 'خطأ'));
                return;
            }
            alert('تم حفظ العائلة والمقاسات');
        } else {
            alert(res.message || 'تم الحفظ');
        }
        location.reload();
    } catch (e) {
        alert('فشل الاتصال بالخادم أثناء الحفظ');
    } finally {
        isSavingFamily = false;
    }
}

async function toggleFamily(id, isActive) {
    var res = await postJSON('/admin/api/size_families/toggle.php', {
        id: id,
        is_active: isActive ? 0 : 1
    });
    alert(res.message || (res.success ? 'تم التعديل' : 'فشل التعديل'));
    if (res.success) location.reload();
}

function moveFamilyRow(btn, dir) {
    var tr = btn.closest('tr');
    if (!tr) return;
    var tbody = document.getElementById('orange-families-list-tbody');
    if (!tbody) return;
    if (dir === 'up') {
        var prev = tr.previousElementSibling;
        if (prev) tbody.insertBefore(tr, prev);
    } else {
        var next = tr.nextElementSibling;
        if (next) tbody.insertBefore(next, tr);
    }
}

async function saveFamiliesOrder() {
    var tbody = document.getElementById('orange-families-list-tbody');
    if (!tbody) return;
    var ids = Array.from(tbody.querySelectorAll('tr[data-id]'))
        .map(function (tr) { return parseInt(tr.getAttribute('data-id') || '0', 10); })
        .filter(function (id) { return id > 0; });
    var res = await postJSON('/admin/api/size_families/reorder-save.php', { ordered_ids: ids });
    alert(res.message || (res.success ? 'تم حفظ الترتيب' : 'فشل حفظ الترتيب'));
    if (res.success) location.reload();
}

function loadSizesEditor() {
    var fid = parseInt(String(document.getElementById('fam_id').value || '0').trim(), 10) || 0;
    var box = document.getElementById('sizesEditor');
    if (!fid) {
        box.innerHTML = '';
        return;
    }
    var rows = ORANGE_SIZES_BY_FAMILY[String(fid)] || ORANGE_SIZES_BY_FAMILY[fid] || [];
    var thead = '<thead><tr><th>ترتيب</th><th>EN</th><th>عربي</th><th>Fil</th><th>Hi</th></tr></thead>';
    var html = '<div class="table-wrap"><table>' + thead + '<tbody>';
    if (!rows.length) {
        html += '</tbody></table></div>';
    } else {
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var tplSz = parseInt(String(r.scheme_template_size_id != null ? r.scheme_template_size_id : '0'), 10) || 0;
            html += '<tr class="size-row" data-id="' + r.id + '" data-scheme-template-size-id="' + tplSz + '"><td><input type="number" class="s-so admin-sort-field" autocomplete="off" value="' + (Number(r.sort_order) || 0) + '"></td><td><input type="text" class="s-le admin-sort-field" maxlength="191" autocomplete="off" value="' + escapeAttr(r.label_en) + '"></td><td><input type="text" class="s-la admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" placeholder="= EN" title="نسخة من عمود EN" autocomplete="off" aria-readonly="true" value=""></td><td><input type="text" class="s-lf admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" value=""></td><td><input type="text" class="s-lh admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" value=""></td></tr>';
        }
        html += '</tbody></table></div>';
    }
    box.innerHTML = html;
    if (rows.length) {
        box.querySelectorAll('tr.size-row').forEach(function (tr) {
            famSyncRowLabelsFromEn(tr);
        });
    }
}

function escapeAttr(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
}

/** نسخ EN إلى عربي / Fil / Hi (مثل شاشة قوالب المقاسات). */
function famSyncRowLabelsFromEn(tr) {
    if (!tr) {
        return;
    }
    var leEl = tr.querySelector('.s-le');
    var v = leEl ? String(leEl.value || '') : '';
    var la = tr.querySelector('.s-la');
    var lf = tr.querySelector('.s-lf');
    var lh = tr.querySelector('.s-lh');
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

function famEnsureSizesEditorEmptyTable() {
    var box = document.getElementById('sizesEditor');
    if (!box) {
        return null;
    }
    var tbody = box.querySelector('tbody');
    if (tbody) {
        return tbody;
    }
    var thead = '<thead><tr><th>ترتيب</th><th>EN</th><th>عربي</th><th>Fil</th><th>Hi</th></tr></thead>';
    box.innerHTML = '<div class="table-wrap"><table>' + thead + '<tbody></tbody></table></div>';
    return box.querySelector('tbody');
}

function famAddEmptySizeRow() {
    var tbody = famEnsureSizesEditorEmptyTable();
    if (!tbody) {
        return;
    }
    var n = tbody.querySelectorAll('tr.size-row').length;
    var tr = document.createElement('tr');
    tr.className = 'size-row';
    tr.setAttribute('data-new', '1');
    tr.innerHTML = '<td><input type="number" class="s-so admin-sort-field" autocomplete="off" value="' + String(n + 1) + '"></td>' +
        '<td><input type="text" class="s-le admin-sort-field" maxlength="191" autocomplete="off" value=""></td>' +
        '<td><input type="text" class="s-la admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" placeholder="= EN" title="نسخة من عمود EN" autocomplete="off" aria-readonly="true" value=""></td>' +
        '<td><input type="text" class="s-lf admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" value=""></td>' +
        '<td><input type="text" class="s-lh admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" value=""></td>';
    tbody.appendChild(tr);
}

function famCollectSizesRowsFromEditor() {
    var trs = document.querySelectorAll('#sizesEditor tr.size-row');
    var rows = [];
    for (var idx = 0; idx < trs.length; idx++) {
        var tr = trs[idx];
        var id = parseInt(tr.getAttribute('data-id') || '0', 10) || 0;
        var leEl = tr.querySelector('.s-le');
        var soEl = tr.querySelector('.s-so');
        var le = leEl ? String(leEl.value || '').trim() : '';
        if (le === '') {
            continue;
        }
        var la = le;
        var lf = le;
        var lh = le;
        var so = soEl ? parseInt(soEl.value || String(idx), 10) : idx;
        if (isNaN(so)) so = idx;
        var row = { id: id, label_ar: la, label_en: le, label_fil: lf, label_hi: lh, sort_order: so };
        var tplL = parseInt(tr.getAttribute('data-scheme-template-size-id') || '0', 10) || 0;
        if (tplL > 0) {
            row.scheme_template_size_id = tplL;
        }
        rows.push(row);
    }
    return rows;
}

/**
 * @param {number} tid
 * @param {{ allowDraftFamily?: boolean }} opts allowDraftFamily: عائلة جديدة بدون fam_id بعد
 */
async function famApplyTemplateSizesToEditor(tid, opts) {
    opts = opts || {};
    var allowDraftFamily = !!opts.allowDraftFamily;
    var fid = parseInt(String(document.getElementById('fam_id').value || '0').trim(), 10) || 0;
    if (!allowDraftFamily && !fid) {
        alert('احفظ العائلة أولاً (أو اضغط «تعديل» على عائلة من القائمة) ثم حمّل المقاسات من القالب.');
        return false;
    }
    if (!tid) {
        alert('اختر قالباً أولاً');
        return false;
    }
    try {
        var res = await postJSON(SST_IMPORT_API, { action: 'get', id: tid });
        if (!res || !res.success) {
            alert((res && res.message) ? res.message : 'تعذر تحميل القالب');
            return false;
        }
        var sizes = res.sizes || [];
        if (!sizes.length) {
            alert('القالب لا يحتوي على مقاسات');
            return false;
        }
        var tbody = document.querySelector('#sizesEditor tbody');
        if (!tbody) {
            if (fid > 0) {
                loadSizesEditor();
                tbody = document.querySelector('#sizesEditor tbody');
            } else {
                tbody = famEnsureSizesEditorEmptyTable();
            }
        }
        if (!tbody) {
            return false;
        }
        var existing = tbody.querySelectorAll('tr.size-row').length;
        var existingByTpl = Object.create(null);
        var existingByOrdinal = [];
        if (existing > 0) {
            tbody.querySelectorAll('tr.size-row').forEach(function (rowTr) {
                var oldId = parseInt(rowTr.getAttribute('data-id') || '0', 10) || 0;
                var oldTpl = parseInt(rowTr.getAttribute('data-scheme-template-size-id') || '0', 10) || 0;
                if (oldId > 0) {
                    existingByOrdinal.push(oldId);
                    if (oldTpl > 0 && existingByTpl[String(oldTpl)] == null) {
                        existingByTpl[String(oldTpl)] = oldId;
                    }
                }
            });
        }
        if (existing > 0) {
            if (!confirm('سيتم استبدال جميع صفوف المقاسات في الجدول بمحتوى القالب. متابعة؟')) {
                return false;
            }
            while (tbody.firstChild) {
                tbody.removeChild(tbody.firstChild);
            }
        }
        for (var i = 0; i < sizes.length; i++) {
            var r = sizes[i];
            var tr = document.createElement('tr');
            tr.className = 'size-row';
            var tplSzId = parseInt(String(r.id != null ? r.id : '0'), 10) || 0;
            if (tplSzId > 0) {
                tr.setAttribute('data-scheme-template-size-id', String(tplSzId));
            }
            var keepId = 0;
            if (tplSzId > 0 && existingByTpl[String(tplSzId)] != null) {
                keepId = parseInt(String(existingByTpl[String(tplSzId)]), 10) || 0;
            } else if (i < existingByOrdinal.length) {
                keepId = parseInt(String(existingByOrdinal[i]), 10) || 0;
            }
            if (keepId > 0) {
                tr.setAttribute('data-id', String(keepId));
            } else {
                tr.setAttribute('data-new', '1');
            }
            tr.innerHTML = '<td><input type="number" class="s-so admin-sort-field" autocomplete="off" value="' + (Number(r.sort_order) || (i + 1)) + '"></td><td><input type="text" class="s-le admin-sort-field" maxlength="191" autocomplete="off" value="' + escapeAttr(r.label_en || '') + '"></td><td><input type="text" class="s-la admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" placeholder="= EN" title="نسخة من عمود EN" autocomplete="off" aria-readonly="true" value=""></td><td><input type="text" class="s-lf admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" value=""></td><td><input type="text" class="s-lh admin-sort-field admin-sort-field--muted" maxlength="191" readonly tabindex="-1" value=""></td>';
            tbody.appendChild(tr);
            famSyncRowLabelsFromEn(tr);
        }
        return true;
    } catch (e) {
        alert('خطأ شبكة أو خادم');
        return false;
    }
}

async function famAutoImportOnTemplateChange() {
    if (!FAM_HAS_SIZE_TEMPLATES) {
        return;
    }
    var pick = document.getElementById('sizes_template_pick');
    var tid = pick ? (parseInt(pick.value || '0', 10) || 0) : 0;
    if (!tid) {
        return;
    }
    await famApplyTemplateSizesToEditor(tid, { allowDraftFamily: true });
}

async function importSizeTemplateRows() {
    if (!FAM_HAS_SIZE_TEMPLATES) {
        alert('لا توجد قوالب مقاسات. أنشئ قالباً من «إدارة القوالب» أولاً.');
        return;
    }
    var pick = document.getElementById('sizes_template_pick');
    var tid = pick ? (parseInt(pick.value || '0', 10) || 0) : 0;
    await famApplyTemplateSizesToEditor(tid, { allowDraftFamily: false });
}

async function saveSizesForFamily() {
    var familyId = parseInt(String(document.getElementById('fam_id').value || '0').trim(), 10) || 0;
    if (!familyId) {
        alert('احفظ العائلة أولاً أو افتح عائلة محفوظة من «تعديل» قبل حفظ المقاسات.');
        return;
    }
    var rows = famCollectSizesRowsFromEditor();
    if (!rows.length) {
        alert('حمّل المقاسات من قالب أولاً (لا يوجد صف في الجدول).');
        return;
    }
    var syncPick = document.getElementById('sizes_template_pick');
    var syncTplId2 = (syncPick && syncPick.tagName === 'SELECT') ? (parseInt(syncPick.value || '0', 10) || 0) : 0;
    var res = await postJSON('/admin/api/size_families/save_sizes.php', {
        family_id: familyId,
        sizes: rows,
        sync_template_id: syncTplId2
    });
    alert(res.message || (res.success ? 'تم حفظ المقاسات' : 'فشل'));
    if (res.success) location.reload();
}

(function famBindFamilyNameTranslateIfLegacy() {
    if (FAM_SIZING_DICT_SELECTS) {
        return;
    }
    document.getElementById('fam_name_ar').addEventListener('input', scheduleFamilyEnTranslate);
    document.getElementById('fam_name_ar').addEventListener('change', function () {
        if (document.getElementById('fam_name_ar').value.trim()) {
            translateFamilyEn({ silent: true, forceFromArabic: true });
        }
    });
})();

(function famBindFamNameEnFilHiSync() {
    var enEl = document.getElementById('fam_name_en');
    if (!enEl) {
        return;
    }
    if (FAM_SIZING_DICT_SELECTS) {
        return;
    }
    enEl.addEventListener('input', function () {
        clearTimeout(famEnFilHiTimer);
        famEnFilHiTimer = setTimeout(famSyncFamFilHiFromEn, 450);
    });
})();

(function famBindLegacyHierarchyKeyRefresh() {
    ['fam_commercial_kind_key', 'fam_sizing_category_key'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el && el.tagName === 'INPUT') {
            el.addEventListener('input', famApplyAutoSizeSchemeKey);
            el.addEventListener('change', famApplyAutoSizeSchemeKey);
        }
    });
})();

(function () {
    var style = document.createElement('style');
    style.textContent = `
        .sf-sizes-actions{justify-content:flex-start;flex-direction:row;direction:ltr}
        #sizesEditor input.s-la[readonly],
        #sizesEditor input.s-lf[readonly],
        #sizesEditor input.s-lh[readonly]{
            cursor:default;
            opacity:0.92;
        }
        .cat-dep-list-wrap[data-list="size-families"]{
            overflow-x:auto;
            max-width:none;
            -webkit-overflow-scrolling:touch;
        }
        .cat-dep-list-wrap[data-list="size-families"] > table{
            min-width:960px;
            width:100%;
            border-collapse:collapse;
            table-layout:fixed;
        }
        .cat-dep-list-wrap[data-list="size-families"] > table th,
        .cat-dep-list-wrap[data-list="size-families"] > table td{
            vertical-align:middle;
        }
        .cat-dep-list-wrap[data-list="size-families"] > table th.sf-col-id,
        .cat-dep-list-wrap[data-list="size-families"] > table td.sf-col-id{
            width:52px;
            max-width:52px;
            text-align:center;
            padding-inline:6px !important;
        }
        .cat-dep-list-wrap[data-list="size-families"] > table th.sf-col-ar,
        .cat-dep-list-wrap[data-list="size-families"] > table td.sf-col-ar,
        .cat-dep-list-wrap[data-list="size-families"] > table th.sf-col-en,
        .cat-dep-list-wrap[data-list="size-families"] > table td.sf-col-en{
            min-width:0;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .cat-dep-list-wrap[data-list="size-families"] > table th.sf-col-scheme,
        .cat-dep-list-wrap[data-list="size-families"] > table td.sf-col-scheme{
            width:32%;
            min-width:220px;
            word-break:break-word;
            overflow-wrap:anywhere;
        }
        .cat-dep-list-wrap[data-list="size-families"] > table th.sf-col-szcount{
            white-space:normal;
            line-height:1.25;
            font-size:11px;
            font-weight:700;
        }
        .cat-dep-list-wrap[data-list="size-families"] > table th.sf-col-szcount,
        .cat-dep-list-wrap[data-list="size-families"] > table td.sf-col-szcount{
            width:4.25rem;
            max-width:5rem;
            text-align:center;
            padding-inline:4px !important;
            font-variant-numeric:tabular-nums;
        }
        .cat-dep-list-wrap[data-list="size-families"] > table td.sf-col-szcount{
            white-space:nowrap;
        }
        .cat-dep-list-wrap[data-list="size-families"] > table th.sf-col-sort,
        .cat-dep-list-wrap[data-list="size-families"] > table td.sf-col-sort{
            width:3.75rem;
            max-width:4.25rem;
            text-align:center;
            white-space:nowrap;
            padding-inline:4px !important;
            font-variant-numeric:tabular-nums;
        }
        .cat-dep-list-wrap[data-list="size-families"] > table th.sf-col-status,
        .cat-dep-list-wrap[data-list="size-families"] > table td.sf-col-status{
            width:4.5rem;
            max-width:5rem;
            text-align:center;
            white-space:nowrap;
            padding-inline:4px !important;
        }
        .cat-dep-list-wrap[data-list="size-families"] table .sf-ops-col,
        .cat-dep-list-wrap[data-list="size-families"] table .sf-row-ops{
            width:200px !important;
            min-width:200px !important;
            max-width:200px !important;
            box-sizing:border-box !important;
            text-align:center !important;
            vertical-align:middle !important;
            padding:6px 8px !important;
        }
        .cat-dep-list-wrap[data-list="size-families"] .sf-ops-wrap{
            display:grid;
            grid-template-columns:38px minmax(0,1fr);
            gap:8px;
            align-items:center;
            margin:0 auto;
            max-width:100%;
            direction:rtl;
        }
        .cat-dep-list-wrap[data-list="size-families"] .sf-ops-arrows{
            display:flex;
            flex-direction:column;
            gap:4px;
            align-items:center;
            justify-content:center;
        }
        .cat-dep-list-wrap[data-list="size-families"] .sf-ops-wrap button.sf-btn-reorder{
            width:32px !important;
            min-width:32px !important;
            height:28px !important;
            margin:0 !important;
            padding:0 !important;
            font-size:13px !important;
            line-height:1 !important;
            border-radius:6px !important;
            display:inline-flex !important;
            align-items:center;
            justify-content:center;
        }
        .cat-dep-list-wrap[data-list="size-families"] .sf-ops-main{
            display:flex;
            flex-direction:column;
            gap:5px;
            min-width:0;
        }
        .cat-dep-list-wrap[data-list="size-families"] .sf-ops-main .btn-secondary,
        .cat-dep-list-wrap[data-list="size-families"] .sf-ops-main .sf-btn-toggle{
            width:100% !important;
            margin:0 !important;
            padding:6px 8px !important;
            font-size:12px !important;
            line-height:1.2 !important;
            border-radius:6px !important;
            box-sizing:border-box !important;
            min-height:30px !important;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
    `;
    document.head.appendChild(style);

    var tbody = document.getElementById('orange-families-list-tbody');
    if (tbody) {
        tbody.addEventListener('click', function (ev) {
            var btn = orangeAdminClosest(ev, '.sf-edit-btn');
            if (!btn || !btn.dataset.familyJson) return;
            try {
                void editFamily(JSON.parse(btn.dataset.familyJson));
            } catch (err) {
                alert('تعذر قراءة بيانات العائلة للتعديل');
            }
        });
    }

    function famRunInitWhenPostJsonReady() {
        if (typeof postJSON !== 'function') {
            setTimeout(famRunInitWhenPostJsonReady, 30);
            return;
        }
        famInitSizingHierarchySelects();
        var tplPick = document.getElementById('sizes_template_pick');
        if (tplPick && tplPick.tagName === 'SELECT' && !tplPick._sfSchemeBound) {
            tplPick._sfSchemeBound = true;
            tplPick.addEventListener('change', function () {
                famApplyAutoNamesFromDictionary();
                famApplyAutoSizeSchemeKey();
                void famAutoImportOnTemplateChange();
            });
        }
        var sizesBox = document.getElementById('sizesEditor');
        if (sizesBox && !sizesBox._famLeBound) {
            sizesBox._famLeBound = true;
            sizesBox.addEventListener('input', function (ev) {
                var t = ev.target;
                if (!t || !t.classList || !t.classList.contains('s-le')) {
                    return;
                }
                var tr = t.closest ? t.closest('tr.size-row') : null;
                if (!tr) {
                    return;
                }
                famSyncRowLabelsFromEn(tr);
            });
        }
        loadSizesEditor();
    }
    famRunInitWhenPostJsonReady();
})();
</script>
