<?php

declare(strict_types=1);

$pdo = db();

require_once __DIR__ . '/../../includes/catalog_schema.php';

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

$tablesReady = $hasFamilies && $hasSizes;
?>
<div class="page-title">
    <h1>عائلات المقاسات</h1>
    <p class="page-subtitle" style="margin:0.35rem 0 0;font-size:0.95rem;color:#555;line-height:1.65;word-wrap:break-word;overflow-wrap:anywhere;">
        <strong>المستوى 3</strong> في هذه الشاشة = <code>size_scheme_key</code> (مخطّط العائلة) يُولَّد تلقائياً من <strong>1 + 2 + English</strong>. قبلها في الهرم: <strong>1</strong> = <code>commercial_kind_key</code>، <strong>2</strong> = <code>sizing_category_key</code> — من جداول
        <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=sizing_dictionary'), ENT_QUOTES, 'UTF-8'); ?>">قاموس هرَم المقاس</a>.
        <strong>المستوى 4</strong> = القيم في «مقاسات داخل العائلة» (<code>size_family_sizes</code>).
    </p>
    <details class="sf-hero-details" style="margin:0.75rem 0 0;padding:12px 14px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;max-width:100%;box-sizing:border-box;">
        <summary style="cursor:pointer;font-weight:600;color:#334155;list-style-position:outside;">كيف أختار المستوى 2 وأربطه بالمستوى 3؟</summary>
        <ol style="margin:10px 0 0;padding-inline-start:1.25rem;line-height:1.7;color:#444;font-size:0.92rem;word-wrap:break-word;">
            <li>من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=sizing_dictionary'), ENT_QUOTES, 'UTF-8'); ?>">قاموس هرَم المقاس</a> اختر <strong>النوع التجاري</strong> في القائمة، ثم انظر جدول <strong>«فئات القياس ضمن النوع المحدّد»</strong>.</li>
            <li><?php if ($sizingDictForFamilyForm): ?>في النموذج أدناه اختر <strong>النوع التجاري</strong> ثم <strong>فئة القياس</strong> من القائمتين (نفس أسلوب «فئة قياس» في القاموس).<?php else: ?>انسخ قيمة عمود <strong>المفتاح</strong> للفئة المطلوبة — هذه هي <code>sizing_category_key</code> (مستوى 2). وانسخ <strong>مفتاح</strong> النوع من الجدول الأول — <code>commercial_kind_key</code> (مستوى 1).<?php endif; ?></li>
            <li>يُولَّد <code>size_scheme_key</code> للمستوى 3 تلقائياً بالترتيب: <strong>النوع التجاري</strong> ثم <strong>فئة القياس</strong> ثم <strong>English</strong> (مثال: <code>clothing_tops_mens</code>).</li>
            <li>احفظ العائلة، ثم من «مقاسات داخل العائلة» أضف صفوف المقاسات (المستوى 4).</li>
        </ol>
        <p style="margin:10px 0 0;font-size:0.88rem;color:#64748b;line-height:1.55;">عند تفعيل قاموس نشط، يُفرَض تطابق المفاتيح عند الحفظ. ربط <code>expected_size_scheme_key</code> من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=product_types'), ENT_QUOTES, 'UTF-8'); ?>">أنواع المنتجات</a> يستعمل نفس الهرم للتحقق.</p>
    </details>
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
            "siz siz siz siz siz siz comm comm comm comm comm comm"
            "en en en en en en ar ar ar ar ar ar"
            "help help help help help help help help help help help help";
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
    .sf-fam-form-grid .sf-fam-comm {
        grid-area: comm;
        min-width: 0;
    }
    .sf-fam-form-grid .sf-fam-siz {
        grid-area: siz;
        min-width: 0;
    }
    .sf-fam-form-grid .sf-fam-ar { grid-area: ar; }
    .sf-fam-form-grid .sf-fam-en { grid-area: en; }
    .sf-fam-form-grid .sf-fam-help {
        grid-area: help;
        margin: 0;
        font-size: 0.88rem;
        color: #555;
        line-height: 1.5;
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
    .sf-fam-form-grid .sf-fam-siz label {
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
    .sf-fam-form-grid select#fam_sizing_category_key {
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
    .sf-fam-form-grid select#fam_sizing_category_key {
        max-width: none;
    }
    .sf-fam-form-grid #fam_name_ar,
    .sf-fam-form-grid #fam_name_en {
        width: 100%;
        max-width: none;
        box-sizing: border-box;
    }
    .sf-fam-form-actions {
        justify-content: flex-end;
    }
    @media (max-width: 720px) {
        .sf-fam-form-grid {
            grid-template-columns: 1fr;
            grid-template-areas:
                "sort"
                "scheme"
                "active"
                "comm"
                "siz"
                "ar"
                "en"
                "help";
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
            <label>الترتيب (تلقائي)</label>
            <input type="number" id="fam_sort" class="admin-sort-field admin-sort-field--muted" value="<?php echo (int) $nextSort; ?>" disabled>
        </div>
        <div class="sf-fam-scheme">
            <label><code>size_scheme_key</code> (مستوى 3 — EN)</label>
            <input type="text" id="fam_size_scheme_key" maxlength="64" placeholder="clothing_tops_mens" readonly tabindex="-1" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
            <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;line-height:1.4;">يُولَّد تلقائياً بالترتيب: النوع التجاري (1) + فئة القياس (2) + English — للقراءة فقط (حتى 64 محرفاً بعد التعقيم).</small>
        </div>
        <div class="sf-fam-active admin-sort-field-wrap">
            <label>نشط</label>
            <select id="fam_active" class="admin-sort-field" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
                <option value="1">نعم</option>
                <option value="0">لا</option>
            </select>
        </div>
        <div class="sf-fam-comm">
            <?php if ($sizingDictForFamilyForm): ?>
            <label>النوع التجاري (مستوى 1)</label>
            <select id="fam_commercial_kind_key" class="admin-sort-field" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
                <option value="">— اختر النوع —</option>
            </select>
            <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;line-height:1.4;">قائمة من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=sizing_dictionary'), ENT_QUOTES, 'UTF-8'); ?>">قاموس هرَم المقاس</a> (نفس بطاقة «فئة قياس»).</small>
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
            <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;line-height:1.4;">تُحمَّل بعد اختيار النوع التجاري من القائمة.</small>
            <?php else: ?>
            <label><code>sizing_category_key</code> (مستوى 2)</label>
            <input type="text" id="fam_sizing_category_key" class="admin-sort-field" maxlength="64" placeholder="tops" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
            <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;line-height:1.4;">أدخل المفتاح يدوياً.</small>
            <?php endif; ?>
        </div>
        <div class="sf-fam-ar">
            <label>الاسم العربي</label>
            <input type="text" id="fam_name_ar" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
        </div>
        <div class="sf-fam-en">
            <label>English</label>
            <input type="text" id="fam_name_en" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
        </div>
        <p class="sf-fam-help">إن كان <code>size_scheme_key</code> غير فارغ بعد التوليد، فالحفظ يرفض دون تعبئة <code>commercial_kind_key</code> و<code>sizing_category_key</code> أيضاً (استكمال الهرم عند مستوى المخطّط).</p>
    </div>
    <div class="actions sf-fam-form-actions" style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;">
        <button type="button" onclick="saveFamily()" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>حفظ العائلة</button>
        <button type="button" class="btn-secondary" onclick="translateFamilyEn({ forceFromArabic: true })" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>ترجمة إلى English</button>
        <button type="button" class="btn-secondary" onclick="resetFamilyForm()" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>جديد</button>
    </div>
</div>

<div class="card">
    <h3>مقاسات داخل العائلة</h3>
    <div class="form-grid">
        <div style="grid-column:1/-1;">
            <label>اختر العائلة</label>
            <select id="sizes_family_id" onchange="loadSizesEditor()" <?php echo !$tablesReady ? 'disabled' : ''; ?>>
                <option value="">--</option>
                <?php foreach ($families as $f): ?>
                    <option value="<?php echo (int) $f['id']; ?>"><?php echo htmlspecialchars($f['name_ar'] ?: $f['name_en'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div id="sizesEditor" style="margin-top:12px;"></div>
    <div class="actions sf-sizes-actions" style="margin-top:14px;">
        <button type="button" class="btn-secondary" onclick="addSizeRow()" <?php echo !$tablesReady ? 'disabled' : ''; ?>>+ صف مقاس</button>
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
    <div class="table-wrap cat-dep-list-wrap" data-list="size-families">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>العربي</th>
                    <th>English</th>
                    <th>scheme</th>
                    <th>عدد المقاسات</th>
                    <th>الترتيب</th>
                    <th>الحالة</th>
                    <th class="sf-ops-col">إجراءات</th>
                </tr>
            </thead>
            <tbody id="orange-families-list-tbody">
                <?php foreach ($families as $f): ?>
                <tr data-id="<?php echo (int) $f['id']; ?>">
                    <td><?php echo (int) $f['id']; ?></td>
                    <td><?php echo htmlspecialchars((string) $f['name_ar'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) $f['name_en'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($f['size_scheme_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo isset($sizesByFamily[(int) $f['id']]) ? count($sizesByFamily[(int) $f['id']]) : 0; ?></td>
                    <td><?php echo (int) $f['sort_order']; ?></td>
                    <td><?php echo (int) $f['is_active'] === 1 ? 'ظاهر' : 'مخفي'; ?></td>
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
                                    'size_scheme_key' => (string) ($f['size_scheme_key'] ?? ''),
                                    'commercial_kind_key' => (string) ($f['commercial_kind_key'] ?? ''),
                                    'sizing_category_key' => (string) ($f['sizing_category_key'] ?? ''),
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
const defaultNextFamilySort = <?php echo (int) $nextSort; ?>;
var FAM_SIZING_DICT_SELECTS = <?php echo $sizingDictForFamilyForm ? 'true' : 'false'; ?>;
var FAM_SD_API = '/admin/api/sizing_dictionary/manage.php';
let familyTranslateTimer = null;
let isSavingFamily = false;

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

function famApplyAutoSizeSchemeKey() {
    var keyEl = document.getElementById('fam_size_scheme_key');
    var enEl = document.getElementById('fam_name_en');
    if (!keyEl || !enEl) return;
    var ck = famSizingSlugKey(famHierarchyFieldValue('fam_commercial_kind_key'), 32);
    var sk = famSizingSlugKey(famHierarchyFieldValue('fam_sizing_category_key'), 64);
    var en = famSizingSlugKey(enEl.value, 64);
    var parts = [];
    if (ck) parts.push(ck);
    if (sk) parts.push(sk);
    if (en) parts.push(en);
    var combined = parts.join('_');
    if (combined.length > 64) {
        combined = combined.substring(0, 64);
    }
    keyEl.value = combined;
}

function famEnsureSelectOption(sel, value, label) {
    if (!sel || !value) return;
    var v = String(value);
    if ([].some.call(sel.options, function (o) { return o.value === v; })) return;
    var o = document.createElement('option');
    o.value = v;
    o.textContent = label || v;
    sel.appendChild(o);
}

async function famLoadKindsIntoSelect(preferredKind) {
    var sel = document.getElementById('fam_commercial_kind_key');
    if (!sel || sel.tagName !== 'SELECT' || typeof postJSON !== 'function') {
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
            sel.appendChild(o);
        });
        if (prev) {
            famEnsureSelectOption(sel, prev, prev + ' (غير مدرَج في القاموس)');
            sel.value = prev;
        } else {
            sel.value = '';
        }
    } catch (e) { /* ignore */ } finally {
        famApplyAutoSizeSchemeKey();
    }
}

async function famLoadSizingCategoriesIntoSelect(preferredCat) {
    var kindSel = document.getElementById('fam_commercial_kind_key');
    var catSel = document.getElementById('fam_sizing_category_key');
    if (!kindSel || kindSel.tagName !== 'SELECT' || !catSel || catSel.tagName !== 'SELECT' || typeof postJSON !== 'function') {
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
            catSel.appendChild(o);
        });
        if (prev) {
            famEnsureSelectOption(catSel, prev, prev + ' (غير مدرَج في القاموس)');
            catSel.value = prev;
        } else {
            catSel.value = '';
        }
    } catch (e) { /* ignore */ } finally {
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
        catSel.addEventListener('change', famApplyAutoSizeSchemeKey);
    }
    void famLoadKindsIntoSelect('').then(function () {
        return famLoadSizingCategoriesIntoSelect('');
    });
}

function resetFamilyForm() {
    document.getElementById('fam_id').value = '0';
    document.getElementById('fam_name_ar').value = '';
    document.getElementById('fam_name_en').value = '';
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
}

async function editFamily(f) {
    document.getElementById('fam_id').value = String(f.id != null ? f.id : 0);
    document.getElementById('fam_name_ar').value = f.name_ar || '';
    document.getElementById('fam_name_en').value = f.name_en || '';
    if (FAM_SIZING_DICT_SELECTS) {
        await famLoadKindsIntoSelect(f.commercial_kind_key || '');
        await famLoadSizingCategoriesIntoSelect(f.sizing_category_key || '');
    } else {
        document.getElementById('fam_commercial_kind_key').value = f.commercial_kind_key || '';
        document.getElementById('fam_sizing_category_key').value = f.sizing_category_key || '';
    }
    famApplyAutoSizeSchemeKey();
    document.getElementById('fam_sort').value = String(f.sort_order ?? 0);
    document.getElementById('fam_active').value = String(f.is_active === 0 ? 0 : 1);
    document.getElementById('sizes_family_id').value = String(f.id);
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
            famApplyAutoSizeSchemeKey();
        }
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة من السيرفر');
    }
}

function scheduleFamilyEnTranslate() {
    var nameAr = document.getElementById('fam_name_ar').value.trim();
    if (!nameAr) {
        document.getElementById('fam_name_en').value = '';
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
    if (!document.getElementById('fam_name_ar').value.trim() || !document.getElementById('fam_name_en').value.trim()) {
        alert('يجب تعبئة الاسم العربي والإنجليزي قبل الحفظ');
        isSavingFamily = false;
        return;
    }
    try {
        var rawId = parseInt(String(document.getElementById('fam_id').value || '0').trim(), 10);
        var recordId = (Number.isFinite(rawId) && rawId > 0) ? rawId : 0;
        var payload = {
            name_ar: document.getElementById('fam_name_ar').value.trim(),
            name_en: document.getElementById('fam_name_en').value.trim(),
            size_scheme_key: document.getElementById('fam_size_scheme_key').value.trim(),
            commercial_kind_key: document.getElementById('fam_commercial_kind_key').value.trim(),
            sizing_category_key: document.getElementById('fam_sizing_category_key').value.trim(),
            sort_order: parseInt(document.getElementById('fam_sort').value || '0', 10),
            is_active: parseInt(document.getElementById('fam_active').value, 10)
        };
        if (recordId > 0) payload.id = recordId;
        var res = await postJSON('/admin/api/size_families/save.php', payload);
        alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
        if (res.success) location.reload();
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
    var fid = parseInt(document.getElementById('sizes_family_id').value, 10) || 0;
    var box = document.getElementById('sizesEditor');
    if (!fid) {
        box.innerHTML = '';
        return;
    }
    var rows = ORANGE_SIZES_BY_FAMILY[String(fid)] || ORANGE_SIZES_BY_FAMILY[fid] || [];
    var html = '<div class="table-wrap"><table><thead><tr><th>id</th><th>عربي</th><th>EN</th><th>Fil</th><th>Hi</th><th>طول القدم (سم)</th><th>ترتيب</th></tr></thead><tbody>';
    if (!rows.length) {
        html += '<tr class="size-row" data-new="1"><td>0</td><td><input type="text" class="s-la"></td><td><input type="text" class="s-le"></td><td><input type="text" class="s-lf" placeholder="Fil"></td><td><input type="text" class="s-lh" placeholder="Hi"></td><td><input type="text" class="s-fl" placeholder="مثال 24.5"></td><td><input type="number" class="s-so" value="0"></td></tr>';
    } else {
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var fl = (r.foot_length_cm != null && r.foot_length_cm !== '') ? String(r.foot_length_cm) : '';
            html += '<tr class="size-row" data-id="' + r.id + '"><td>' + r.id + '</td><td><input type="text" class="s-la" value="' + escapeAttr(r.label_ar) + '"></td><td><input type="text" class="s-le" value="' + escapeAttr(r.label_en) + '"></td><td><input type="text" class="s-lf" placeholder="Fil" value="' + escapeAttr(r.label_fil) + '"></td><td><input type="text" class="s-lh" placeholder="Hi" value="' + escapeAttr(r.label_hi) + '"></td><td><input type="text" class="s-fl" placeholder="اختياري" value="' + escapeAttr(fl) + '"></td><td><input type="number" class="s-so" value="' + (Number(r.sort_order) || 0) + '"></td></tr>';
        }
    }
    html += '</tbody></table></div>';
    box.innerHTML = html;
}

function escapeAttr(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
}

function addSizeRow() {
    var fid = parseInt(document.getElementById('sizes_family_id').value, 10) || 0;
    if (!fid) {
        alert('اختر عائلة أولاً');
        return;
    }
    var tbody = document.querySelector('#sizesEditor tbody');
    if (!tbody) return;
    var tr = document.createElement('tr');
    tr.className = 'size-row';
    tr.setAttribute('data-new', '1');
    tr.innerHTML = '<td>0</td><td><input type="text" class="s-la"></td><td><input type="text" class="s-le"></td><td><input type="text" class="s-lf" placeholder="Fil"></td><td><input type="text" class="s-lh" placeholder="Hi"></td><td><input type="text" class="s-fl" placeholder="اختياري"></td><td><input type="number" class="s-so" value="0"></td>';
    tbody.appendChild(tr);
}

async function saveSizesForFamily() {
    var familyId = parseInt(document.getElementById('sizes_family_id').value, 10) || 0;
    if (!familyId) {
        alert('اختر عائلة');
        return;
    }
    var rows = [];
    var trs = document.querySelectorAll('#sizesEditor tr.size-row');
    for (var idx = 0; idx < trs.length; idx++) {
        var tr = trs[idx];
        var id = parseInt(tr.getAttribute('data-id') || '0', 10) || 0;
        var laEl = tr.querySelector('.s-la');
        var leEl = tr.querySelector('.s-le');
        var lfEl = tr.querySelector('.s-lf');
        var lhEl = tr.querySelector('.s-lh');
        var flEl = tr.querySelector('.s-fl');
        var soEl = tr.querySelector('.s-so');
        var la = laEl ? String(laEl.value || '').trim() : '';
        var le = leEl ? String(leEl.value || '').trim() : '';
        var lf = lfEl ? String(lfEl.value || '').trim() : '';
        var lh = lhEl ? String(lhEl.value || '').trim() : '';
        var fl = flEl ? String(flEl.value || '').trim() : '';
        var so = soEl ? parseInt(soEl.value || String(idx), 10) : idx;
        if (isNaN(so)) so = idx;
        if (la === '' && le === '') continue;
        var row = { id: id, label_ar: la, label_en: le, label_fil: lf, label_hi: lh, sort_order: so };
        if (fl !== '') row.foot_length_cm = fl;
        rows.push(row);
    }
    var res = await postJSON('/admin/api/size_families/save_sizes.php', { family_id: familyId, sizes: rows });
    alert(res.message || (res.success ? 'تم حفظ المقاسات' : 'فشل'));
    if (res.success) location.reload();
}

document.getElementById('fam_name_ar').addEventListener('input', scheduleFamilyEnTranslate);
document.getElementById('fam_name_ar').addEventListener('change', function () {
    if (document.getElementById('fam_name_ar').value.trim()) {
        translateFamilyEn({ silent: true, forceFromArabic: true });
    }
});
document.getElementById('fam_name_en').addEventListener('input', famApplyAutoSizeSchemeKey);
document.getElementById('fam_name_en').addEventListener('change', famApplyAutoSizeSchemeKey);

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
        .sf-sizes-actions{justify-content:flex-end}
        .cat-dep-list-wrap[data-list="size-families"]{
            overflow-x:auto;
            max-width:100%;
            -webkit-overflow-scrolling:touch;
        }
        .cat-dep-list-wrap[data-list="size-families"] > table{
            min-width:860px;
            width:100%;
            border-collapse:collapse;
            table-layout:fixed;
        }
        .cat-dep-list-wrap[data-list="size-families"] > table th,
        .cat-dep-list-wrap[data-list="size-families"] > table td{
            vertical-align:middle;
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
    if (!tbody) return;
    tbody.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.sf-edit-btn');
        if (!btn || !btn.dataset.familyJson) return;
        try {
            void editFamily(JSON.parse(btn.dataset.familyJson));
        } catch (err) {
            alert('تعذر قراءة بيانات العائلة للتعديل');
        }
    });

    function famRunInitWhenPostJsonReady() {
        if (typeof postJSON !== 'function') {
            setTimeout(famRunInitWhenPostJsonReady, 30);
            return;
        }
        famInitSizingHierarchySelects();
    }
    famRunInitWhenPostJsonReady();
})();
</script>
