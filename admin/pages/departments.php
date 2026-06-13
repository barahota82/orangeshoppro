<?php
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/department_countries.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

/** @var array<string,mixed> $admin من admin/index.php */
$depAdmin = isset($admin) && is_array($admin) ? $admin : [];
$depCanManageGlobal = $depAdmin !== [] && orange_admin_has_full_access($depAdmin);
$depCountryId = orange_admin_context_country_id($pdo);
$depCountryRow = orange_country_row_by_id($pdo, $depCountryId, false);
$depCountryLabel = trim((string) ($depCountryRow['name_ar'] ?? ''));
if ($depCountryLabel === '' && $depCountryRow !== null) {
    $depCountryLabel = trim((string) ($depCountryRow['name_en'] ?? ''));
}
if ($depCountryLabel === '') {
    $depCountryLabel = orange_countries_display_code(orange_admin_context_country_code($pdo));
}
$depCountryActiveMap = orange_department_countries_active_map($pdo, $depCountryId);

$hasDepartmentsTable = (bool)$pdo->query("SHOW TABLES LIKE 'departments'")->fetchColumn();
$departments = [];
$hasNameFil = false;
$hasNameHi = false;

if ($hasDepartmentsTable) {
    try {
        $hasNameFil = (bool)$pdo->query("SHOW COLUMNS FROM departments LIKE 'name_fil'")->fetch();
        $hasNameHi = (bool)$pdo->query("SHOW COLUMNS FROM departments LIKE 'name_hi'")->fetch();
    } catch (Throwable $e) {
        $hasNameFil = false;
        $hasNameHi = false;
    }

    $departments = $pdo->query("
        SELECT
            d.*,
            " . ($hasNameFil ? "d.name_fil" : "''") . " AS name_fil_safe,
            " . ($hasNameHi ? "d.name_hi" : "''") . " AS name_hi_safe
        FROM departments d
        ORDER BY d.sort_order ASC, d.id ASC
    ")->fetchAll();
}

$nextSort = 1;
if ($hasDepartmentsTable) {
    try {
        $nextSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM departments")->fetchColumn();
        if ($nextSort <= 0) {
            $nextSort = 1;
        }
    } catch (Throwable $e) {
        $nextSort = 1;
    }
}
?>
<div class="page-title">
    <h1>الأقسام الرئيسية</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (!$hasDepartmentsTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>departments</code> غير موجود. أضفه في قاعدة البيانات لتفعيل الشاشة.</div>
</div>
<?php elseif (!$depCanManageGlobal): ?>
<div class="card" style="border:1px solid #e2e8f0;background:#f8fafc;margin-bottom:12px;">
    <p class="card-hint" style="margin:0;line-height:1.55;">قائمة الأقسام أدناه <strong>للقراءة</strong> — إضافة الأقسام وتفعيلها في الدول للمشرف العام فقط.</p>
</div>
<?php endif; ?>

<?php if ($depCanManageGlobal): ?>
<div class="card">
    <h3>إضافة / تعديل قسم</h3>
    <input type="hidden" id="dept_record_id" value="0">
    <div class="form-grid dep-form-grid">
        <div class="dep-slug">
            <label for="slug">Slug</label>
            <div class="dep-slug-field">
                <input type="text" id="slug" dir="ltr" lang="en" disabled>
                <span class="dep-slug-frost" aria-hidden="true"></span>
            </div>
        </div>
        <div class="dep-sort admin-sort-field-wrap">
            <label for="sort_order">الترتيب (تلقائي)</label>
            <input type="number" id="sort_order" class="admin-sort-field admin-sort-field--muted" value="<?php echo (int)$nextSort; ?>" disabled>
        </div>
        <div class="dep-ar">
            <label for="name_ar">الاسم العربي</label>
            <input type="text" id="name_ar">
        </div>
        <div class="dep-en">
            <label for="name_en">English</label>
            <input type="text" id="name_en" dir="ltr">
        </div>
        <div class="dep-hi">
            <label for="name_hi">Hindi</label>
            <input type="text" id="name_hi">
        </div>
        <div class="dep-fil">
            <label for="name_fil">Filipino</label>
            <input type="text" id="name_fil">
        </div>
    </div>
    <div class="actions dep-form-actions" style="margin-top:14px;">
        <button type="button" onclick="saveDepartment()" <?php echo !$hasDepartmentsTable ? 'disabled' : ''; ?>>حفظ القسم</button>
        <button type="button" class="btn-secondary" onclick="translateDepartment({ forceFromArabic: true })">ترجمة</button>
        <button type="button" class="btn-secondary" onclick="resetDepartmentForm()">جديد</button>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;">قائمة الأقسام</h3>
        <div class="actions">
            <?php if ($depCanManageGlobal): ?>
            <button type="button" class="btn-secondary" onclick="saveDepartmentsOrder()">حفظ الترتيب</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-wrap cat-dep-list-wrap" data-list="departments" style="margin-top:10px;">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>العربي</th>
                    <th>English</th>
                    <th>Filipino</th>
                    <th>Hindi</th>
                    <th>Slug</th>
                    <th>الترتيب</th>
                    <th>الكتالوج</th>
                    <?php if ($depCountryId > 0): ?>
                    <th>في <?php echo htmlspecialchars($depCountryLabel, ENT_QUOTES, 'UTF-8'); ?></th>
                    <?php endif; ?>
                    <th class="dep-ops-col">إجراءات</th>
                </tr>
            </thead>
            <tbody id="departmentsTbody">
                <?php foreach ($departments as $dep):
                    $depId = (int) $dep['id'];
                    $depMasterActive = (int) ($dep['is_active'] ?? 0) === 1;
                    $depCountryActive = !empty($depCountryActiveMap[$depId]);
                    ?>
                <tr data-id="<?php echo $depId; ?>">
                    <td><?php echo $depId; ?></td>
                    <td><?php echo htmlspecialchars((string)$dep['name_ar']); ?></td>
                    <td><?php echo htmlspecialchars((string)$dep['name_en']); ?></td>
                    <td><?php echo htmlspecialchars((string)$dep['name_fil_safe']); ?></td>
                    <td><?php echo htmlspecialchars((string)$dep['name_hi_safe']); ?></td>
                    <td><?php echo htmlspecialchars((string)$dep['slug']); ?></td>
                    <td><?php echo (int)$dep['sort_order']; ?></td>
                    <td><?php echo $depMasterActive ? 'مفعّل' : 'مخفي كلياً'; ?></td>
                    <?php if ($depCountryId > 0): ?>
                    <td><?php echo $depCountryActive ? 'نشط' : 'مخفي'; ?></td>
                    <?php endif; ?>
                    <td class="dep-row-ops">
                        <div class="dep-ops-wrap">
                            <?php if ($depCanManageGlobal): ?>
                            <div class="dep-ops-arrows">
                                <button type="button" class="btn-secondary dep-btn-reorder" onclick="moveDepartmentRow(this,'up')" aria-label="أعلى">↑</button>
                                <button type="button" class="btn-secondary dep-btn-reorder" onclick="moveDepartmentRow(this,'down')" aria-label="أسفل">↓</button>
                            </div>
                            <div class="dep-ops-main">
                                <button type="button" class="btn-secondary dep-edit-btn" data-dep-json="<?php echo htmlspecialchars(json_encode([
                                    'id' => $depId,
                                    'name_ar' => (string)$dep['name_ar'],
                                    'name_en' => (string)$dep['name_en'],
                                    'name_fil' => (string)$dep['name_fil_safe'],
                                    'name_hi' => (string)$dep['name_hi_safe'],
                                    'slug' => (string)$dep['slug'],
                                    'sort_order' => (int)$dep['sort_order'],
                                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">تعديل</button>
                                <?php if ($depCountryId > 0): ?>
                                <button type="button" class="dep-btn-toggle-country" onclick="toggleDepartmentCountry(<?php echo $depId; ?>, <?php echo $depCountryActive ? 1 : 0; ?>)">
                                    <?php echo $depCountryActive ? 'إخفاء هنا' : 'تفعيل هنا'; ?>
                                </button>
                                <?php endif; ?>
                                <button type="button" class="dep-btn-toggle-master" onclick="toggleDepartmentMaster(<?php echo $depId; ?>, <?php echo $depMasterActive ? 1 : 0; ?>)">
                                    <?php echo $depMasterActive ? 'إخفاء كلياً' : 'تفعيل كلياً'; ?>
                                </button>
                            </div>
                            <?php else: ?>
                            <span class="muted" style="font-size:0.9rem;">—</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const DEPT_API_MSG = {
    E_DEPT_ID: 'معرف القسم مطلوب للتعديل. اضغط «تعديل» من الجدول ثم احفظ.',
    E_AR: 'يجب إضافة خانة الاسم العربي قبل الحفظ',
    E_EN: 'يجب إضافة خانة الاسم الإنجليزي قبل الحفظ',
    E_FIL: 'يجب إضافة خانة Filipino قبل الحفظ',
    E_HI: 'يجب إضافة خانة Hindi قبل الحفظ',
    E_SLUG: 'يجب إضافة خانة Slug قبل الحفظ',
    E_DUP: 'لا يمكن الحفظ: الاسم العربي مكرر أو يطابق اسماً موجوداً عند اعتبار الحروف المتشابهة (أ إ آ ا — ه ة — ي ى، وأيضاً بعد حذف التشكيل وطي المسافات). استخدم اسماً أوضح أو أضف تمييزاً بسيطاً.',
    OK_UPD: 'تم تحديث القسم'
};
let isSavingDepartment = false;
let autoSlugTouched = false;
let translateTimer = null;
let deptEnTranslateTimer = null;
const defaultNextSort = <?php echo (int)$nextSort; ?>;

function resetDepartmentForm() {
    document.getElementById('dept_record_id').value = '0';
    document.getElementById('name_ar').value = '';
    document.getElementById('name_en').value = '';
    document.getElementById('name_fil').value = '';
    document.getElementById('name_hi').value = '';
    document.getElementById('slug').value = '';
    document.getElementById('sort_order').value = String(defaultNextSort || 1);
    autoSlugTouched = false;
}

function editDepartment(dep) {
    document.getElementById('dept_record_id').value = String(dep.id != null ? dep.id : 0);
    document.getElementById('name_ar').value = dep.name_ar || '';
    document.getElementById('name_en').value = dep.name_en || '';
    document.getElementById('name_fil').value = dep.name_fil || '';
    document.getElementById('name_hi').value = dep.name_hi || '';
    document.getElementById('slug').value = dep.slug || '';
    document.getElementById('sort_order').value = String(dep.sort_order || 0);
    autoSlugTouched = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function slugify(str) {
    return String(str || '')
        .toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/[\s-]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function refreshSlugIfAuto() {
    if (autoSlugTouched) return;
    const slug = slugify(document.getElementById('name_en').value.trim());
    if (slug) {
        document.getElementById('slug').value = slug;
    }
}

async function translateDepartment(opts = {}) {
    const silent = !!opts.silent;
    const forceFromArabic = !!opts.forceFromArabic;
    try {
        const payload = {
            name_ar: document.getElementById('name_ar').value.trim(),
            name_en: forceFromArabic ? '' : document.getElementById('name_en').value.trim()
        };
        const res = await postJSON('/admin/api/translate/names.php', payload);
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        const t = res.translations || {};
        if (t.name_en) document.getElementById('name_en').value = t.name_en;
        if (t.name_fil) document.getElementById('name_fil').value = t.name_fil;
        if (t.name_hi) document.getElementById('name_hi').value = t.name_hi;
        refreshSlugIfAuto();
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة من السيرفر');
    }
}

function scheduleAutoTranslate() {
    const nameAr = document.getElementById('name_ar').value.trim();
    if (!nameAr) {
        document.getElementById('name_en').value = '';
        document.getElementById('name_fil').value = '';
        document.getElementById('name_hi').value = '';
        if (!autoSlugTouched) document.getElementById('slug').value = '';
        return;
    }
    clearTimeout(translateTimer);
    translateTimer = setTimeout(() => translateDepartment({ silent: true, forceFromArabic: true }), 600);
}

function scheduleDepartmentTranslateFromEnglish() {
    const nameEn = document.getElementById('name_en').value.trim();
    if (!nameEn) {
        return;
    }
    clearTimeout(deptEnTranslateTimer);
    deptEnTranslateTimer = setTimeout(() => translateDepartment({ silent: true, forceFromArabic: false }), 550);
}

function onDepartmentNameEnInput() {
    refreshSlugIfAuto();
    scheduleDepartmentTranslateFromEnglish();
}

async function saveDepartment() {
    if (isSavingDepartment) return;
    isSavingDepartment = true;

    const slugEl = document.getElementById('slug');
    if (!slugEl.value.trim()) {
        slugEl.value = slugify(document.getElementById('name_en').value.trim());
    }

    const requiredFields = [
        { id: 'name_ar', label: 'الاسم العربي' },
        { id: 'name_en', label: 'English' },
        { id: 'name_fil', label: 'Filipino' },
        { id: 'name_hi', label: 'Hindi' },
        { id: 'slug', label: 'Slug' }
    ];

    for (const field of requiredFields) {
        const val = document.getElementById(field.id).value.trim();
        if (!val) {
            alert('يجب إضافة خانة ' + field.label + ' قبل الحفظ');
            isSavingDepartment = false;
            return;
        }
    }

    try {
        const rawId = parseInt(String(document.getElementById('dept_record_id').value || '0').trim(), 10);
        const recordId = Number.isFinite(rawId) && rawId > 0 ? rawId : 0;
        const payload = {
            name_ar: document.getElementById('name_ar').value.trim(),
            name_en: document.getElementById('name_en').value.trim(),
            name_fil: document.getElementById('name_fil').value.trim(),
            name_hi: document.getElementById('name_hi').value.trim(),
            slug: document.getElementById('slug').value.trim(),
            sort_order: parseInt(document.getElementById('sort_order').value || '0', 10)
        };
        if (recordId > 0) {
            payload.id = recordId;
        }
        const url = recordId > 0 ? '/admin/api/departments/update.php' : '/admin/api/departments/save.php';
        const res = await postJSON(url, payload);
        const rawMsg = res.message || (res.success ? 'تم الحفظ' : 'فشل الحفظ');
        alert(DEPT_API_MSG[rawMsg] || rawMsg);
        if (res.success) location.reload();
    } catch (e) {
        alert('فشل الاتصال بالخادم أثناء الحفظ');
    } finally {
        isSavingDepartment = false;
    }
}

async function toggleDepartmentCountry(id, isActive) {
    const res = await postJSON('/admin/api/departments/toggle-country.php', {
        id: id,
        is_active: isActive ? 0 : 1
    });
    alert(res.message || (res.success ? 'تم التعديل' : 'فشل التعديل'));
    if (res.success) location.reload();
}

async function toggleDepartmentMaster(id, isActive) {
    const res = await postJSON('/admin/api/departments/toggle.php', {
        id: id,
        is_active: isActive ? 0 : 1
    });
    alert(res.message || (res.success ? 'تم التعديل' : 'فشل التعديل'));
    if (res.success) location.reload();
}

function moveDepartmentRow(btn, dir) {
    const tr = btn.closest('tr');
    if (!tr) return;
    const tbody = document.getElementById('departmentsTbody');
    if (!tbody) return;
    if (dir === 'up') {
        const prev = tr.previousElementSibling;
        if (prev) tbody.insertBefore(tr, prev);
    } else {
        const next = tr.nextElementSibling;
        if (next) tbody.insertBefore(next, tr);
    }
}

async function saveDepartmentsOrder() {
    const tbody = document.getElementById('departmentsTbody');
    if (!tbody) return;
    const ids = Array.from(tbody.querySelectorAll('tr[data-id]'))
        .map((tr) => parseInt(tr.getAttribute('data-id') || '0', 10))
        .filter((id) => id > 0);
    const res = await postJSON('/admin/api/departments/reorder-save.php', { ordered_ids: ids });
    alert(res.message || (res.success ? 'تم حفظ الترتيب' : 'فشل حفظ الترتيب'));
    if (res.success) location.reload();
}

document.getElementById('slug').addEventListener('input', () => { autoSlugTouched = true; });
document.getElementById('name_en').addEventListener('input', onDepartmentNameEnInput);
document.getElementById('name_ar').addEventListener('input', scheduleAutoTranslate);
document.getElementById('name_ar').addEventListener('change', () => {
    const ar = document.getElementById('name_ar').value.trim();
    if (!ar) return;
    translateDepartment({ silent: true, forceFromArabic: true });
});

const translateBtnDep = document.querySelector('.dep-form-actions button.btn-secondary');
if (translateBtnDep) {
    translateBtnDep.onclick = () => translateDepartment({ forceFromArabic: true });
}

(() => {
    const style = document.createElement('style');
    style.textContent = `
        .dep-form-grid{
            display:grid;
            grid-template-columns:repeat(12,minmax(0,1fr));
            grid-template-areas:
                "slug slug slug slug slug slug slug slug slug sort sort sort"
                "en en en en en en ar ar ar ar ar ar"
                "hi hi hi hi hi hi fil fil fil fil fil fil";
            gap:14px 18px;
            direction:ltr;
        }
        .dep-form-grid .dep-slug{grid-area:slug;min-width:0}
        .dep-form-grid .dep-slug-field{
            position:relative;
            display:block;
            width:100%;
            border-radius:var(--radius-sm,10px);
            overflow:hidden;
        }
        .dep-form-grid .dep-slug-frost{
            position:absolute;
            inset:0;
            z-index:1;
            pointer-events:none;
            border-radius:inherit;
            box-sizing:border-box;
            border:1px solid rgba(203,213,225,0.75);
            background:rgba(248,250,252,0.42);
            backdrop-filter:blur(8px) saturate(1.15);
            -webkit-backdrop-filter:blur(8px) saturate(1.15);
        }
        .dep-form-grid .dep-slug-field #slug{
            position:relative;
            z-index:0;
        }
        .dep-form-grid .dep-sort{
            grid-area:sort;
            justify-self:end;
            width:100%;
            max-width:var(--admin-sort-field-max-w,220px);
        }
        .dep-form-grid .dep-ar{grid-area:ar}
        .dep-form-grid .dep-en{grid-area:en}
        .dep-form-grid .dep-hi{grid-area:hi}
        .dep-form-grid .dep-fil{grid-area:fil}
        .dep-form-grid label,
        .dep-form-grid input,
        .dep-form-grid select{direction:rtl;text-align:right}
        .dep-form-grid #slug{direction:ltr;text-align:right}
        .dep-form-grid #name_en{direction:ltr;text-align:right}
        .dep-form-grid .dep-sort.admin-sort-field-wrap{max-width:var(--admin-sort-field-max-w,220px)}
        .dep-form-grid #sort_order{
            margin-inline:0;
            display:block;
            width:100%;
            box-sizing:border-box;
            border:1px solid #cbd5e1;
            border-radius:var(--radius-sm,10px);
            font-size:14px;
            line-height:calc(var(--input-min-h,36px) - 2px);
            min-height:var(--input-min-h,36px);
            height:var(--input-min-h,36px);
            max-height:var(--input-min-h,36px);
            padding-block:0;
            padding-inline:12px;
        }
        .dep-form-grid .dep-slug-field #slug{
            margin-inline:0;
            display:block;
            width:100%;
            box-sizing:border-box;
            border:1px solid #cbd5e1;
            border-radius:var(--radius-sm,10px);
            font-size:14px;
            line-height:calc(var(--input-min-h,36px) - 2px);
            min-height:var(--input-min-h,36px);
            height:var(--input-min-h,36px);
            max-height:var(--input-min-h,36px);
            padding-block:0;
            padding-inline:12px;
            background:#f8fafc;
        }
        .dep-form-grid #name_ar,
        .dep-form-grid #name_en,
        .dep-form-grid #name_fil,
        .dep-form-grid #name_hi{
            margin-inline:0;
            display:block;
            width:100%;
            max-width:none;
            box-sizing:border-box;
            border:1px solid #cbd5e1;
            border-radius:var(--radius-sm,10px);
            font-size:14px;
            line-height:1.4;
            min-height:var(--input-min-h,36px);
            padding:8px 12px;
        }
        .dep-form-grid input#sort_order::-webkit-outer-spin-button,
        .dep-form-grid input#sort_order::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
        .dep-form-grid input#sort_order{-moz-appearance:textfield;appearance:textfield}
        .dep-form-actions{justify-content:flex-end}
        @media (max-width: 860px){
            .dep-form-grid{
                grid-template-columns:1fr;
                grid-template-areas:
                    "sort"
                    "slug"
                    "ar"
                    "en"
                    "fil"
                    "hi";
            }
            .dep-form-grid .dep-sort{justify-self:start}
        }
        .cat-dep-list-wrap[data-list="departments"]{
            overflow-x:auto;
            max-width:none;
            -webkit-overflow-scrolling:touch;
        }
        .cat-dep-list-wrap[data-list="departments"] > table{
            min-width:820px;
            width:100%;
            border-collapse:collapse;
            table-layout:fixed;
        }
        .cat-dep-list-wrap[data-list="departments"] > table th,
        .cat-dep-list-wrap[data-list="departments"] > table td{
            vertical-align:middle;
        }
        .cat-dep-list-wrap[data-list="departments"] table .dep-ops-col,
        .cat-dep-list-wrap[data-list="departments"] table .dep-row-ops{
            width:200px !important;
            min-width:200px !important;
            max-width:200px !important;
            box-sizing:border-box !important;
            text-align:center !important;
            vertical-align:middle !important;
            padding:6px 8px !important;
        }
        .dep-ops-wrap{
            display:grid;
            grid-template-columns:38px minmax(0,1fr);
            gap:8px;
            align-items:center;
            margin:0 auto;
            max-width:100%;
            direction:rtl;
        }
        .dep-ops-arrows{
            display:flex;
            flex-direction:column;
            gap:4px;
            align-items:center;
            justify-content:center;
        }
        .cat-dep-list-wrap[data-list="departments"] .dep-ops-wrap button.dep-btn-reorder{
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
        .dep-ops-main{
            display:flex;
            flex-direction:column;
            gap:5px;
            min-width:0;
        }
        .cat-dep-list-wrap[data-list="departments"] .dep-ops-main .btn-secondary,
        .cat-dep-list-wrap[data-list="departments"] .dep-ops-main .dep-btn-toggle{
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

    const tbody = document.getElementById('departmentsTbody');
    if (!tbody) return;
    tbody.addEventListener('click', function (ev) {
        const btn = orangeAdminClosest(ev, '.dep-edit-btn');
        if (!btn || !btn.dataset.depJson) return;
        try {
            editDepartment(JSON.parse(btn.dataset.depJson));
        } catch (err) {
            alert('تعذر قراءة بيانات القسم للتعديل');
        }
    });
})();
</script>
