<?php
$pdo = db();

$hasDepartmentsTable = false;
$hasCategoryDepartment = false;
try {
    $hasDepartmentsTable = (bool)$pdo->query("SHOW TABLES LIKE 'departments'")->fetchColumn();
    if ($hasDepartmentsTable) {
        $colStmt = $pdo->query("SHOW COLUMNS FROM categories LIKE 'department_id'");
        $hasCategoryDepartment = (bool)$colStmt->fetch();
    }
} catch (Throwable $e) {
    $hasDepartmentsTable = false;
    $hasCategoryDepartment = false;
}

$departments = [];
if ($hasDepartmentsTable) {
    $departments = $pdo->query("SELECT * FROM departments ORDER BY sort_order ASC, id ASC")->fetchAll();
}

if ($hasCategoryDepartment) {
    $categories = $pdo->query("
        SELECT c.*, d.name_ar AS department_name_ar, d.name_en AS department_name_en
        FROM categories c
        LEFT JOIN departments d ON d.id = c.department_id
        ORDER BY c.sort_order ASC, c.id ASC
    ")->fetchAll();
} else {
    $categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, id ASC")->fetchAll();
}

$nextSort = 1;
try {
    $nextSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM categories")->fetchColumn();
    if ($nextSort <= 0) $nextSort = 1;
} catch (Throwable $e) {
    $nextSort = 1;
}
?>
<div class="page-title">
    <h1>الفئات</h1>
</div>

<?php if (!$hasDepartmentsTable): ?>
<div class="card">
    <div class="alert-error">جدول الأقسام غير موجود بعد. أنشئ جدول <code>departments</code> أولًا لربط الفئات بالأقسام.</div>
</div>
<?php elseif (!$hasCategoryDepartment): ?>
<div class="card">
    <div class="alert-error">عمود <code>department_id</code> غير موجود في جدول <code>categories</code>. أضف العمود لتفعيل الربط.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل فئة</h3>
    <input type="hidden" id="cat_record_id" value="0">
    <div class="form-grid cat-form-grid">
        <div class="cat-sort">
            <label>الترتيب (تلقائي)</label>
            <input type="number" id="sort_order" value="<?php echo (int)$nextSort; ?>" disabled style="max-width:140px;">
        </div>
        <div class="cat-dep">
            <label>القسم</label>
            <select id="department_id" <?php echo (!$hasDepartmentsTable || !$hasCategoryDepartment) ? 'disabled' : ''; ?>>
                <option value="">اختر القسم</option>
                <?php foreach ($departments as $dep): ?>
                    <option value="<?php echo (int)$dep['id']; ?>">
                        <?php echo htmlspecialchars((string)($dep['name_ar'] ?: $dep['name_en'])); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cat-ar">
            <label>الاسم العربي</label>
            <input type="text" id="name_ar">
        </div>
        <div class="cat-en">
            <label>English</label>
            <input type="text" id="name_en">
        </div>
        <div class="cat-hi">
            <label>Hindi</label>
            <input type="text" id="name_hi">
        </div>
        <div class="cat-fil">
            <label>Filipino</label>
            <input type="text" id="name_fil">
        </div>
        <div class="cat-slug">
            <label>Slug</label>
            <input type="text" id="slug" disabled>
        </div>
    </div>
    <div class="actions cat-form-actions" style="margin-top:14px;">
        <button type="button" onclick="saveCategory()">حفظ الفئة</button>
        <button type="button" class="btn-secondary" onclick="translateCategory()">ترجمة تلقائية</button>
        <button type="button" class="btn-secondary" onclick="resetCategoryForm()">جديد</button>
    </div>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;">قائمة الفئات</h3>
        <div class="actions">
            <button type="button" class="btn-secondary" onclick="saveCategoriesOrder()">حفظ الترتيب</button>
        </div>
    </div>
    <div class="table-wrap cat-dep-list-wrap" data-list="categories">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>القسم</th>
                    <th>العربي</th>
                    <th>الإنجليزي</th>
                    <th>Filipino</th>
                    <th>Hindi</th>
                    <th>Slug</th>
                    <th>الترتيب</th>
                    <th>الحالة</th>
                    <th class="cat-ops-col">إجراءات</th>
                </tr>
            </thead>
            <tbody id="categoriesTbody">
                <?php foreach ($categories as $cat): ?>
                <tr data-id="<?php echo (int)$cat['id']; ?>">
                    <td><?php echo (int)$cat['id']; ?></td>
                    <td><?php echo htmlspecialchars((string)($cat['department_name_ar'] ?? $cat['department_name_en'] ?? '-')); ?></td>
                    <td><?php echo htmlspecialchars($cat['name_ar']); ?></td>
                    <td><?php echo htmlspecialchars($cat['name_en']); ?></td>
                    <td><?php echo htmlspecialchars((string)($cat['name_fil'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($cat['name_hi'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars($cat['slug']); ?></td>
                    <td><?php echo (int)$cat['sort_order']; ?></td>
                    <td><?php echo (int)$cat['is_active'] === 1 ? 'ظاهر' : 'مخفي'; ?></td>
                    <td class="cat-row-ops">
                        <div class="cat-ops-wrap">
                            <div class="cat-ops-arrows">
                                <button type="button" class="btn-secondary cat-btn-reorder" onclick="moveCategoryRow(this,'up')">↑</button>
                                <button type="button" class="btn-secondary cat-btn-reorder" onclick="moveCategoryRow(this,'down')">↓</button>
                            </div>
                            <div class="cat-ops-main">
                                <button type="button" class="btn-secondary cat-edit-btn" data-cat-json="<?php echo htmlspecialchars(json_encode([
                                    'id' => (int)$cat['id'],
                                    'department_id' => (int)($cat['department_id'] ?? 0),
                                    'name_ar' => (string)$cat['name_ar'],
                                    'name_en' => (string)$cat['name_en'],
                                    'name_fil' => (string)($cat['name_fil'] ?? ''),
                                    'name_hi' => (string)($cat['name_hi'] ?? ''),
                                    'slug' => (string)$cat['slug'],
                                    'sort_order' => (int)$cat['sort_order']
                                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">تعديل</button>
                                <button type="button" class="cat-btn-toggle" onclick="toggleCategory(<?php echo (int)$cat['id']; ?>, <?php echo (int)$cat['is_active']; ?>)">
                                    <?php echo (int)$cat['is_active'] === 1 ? 'إخفاء' : 'إظهار'; ?>
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

<script>
const CAT_API_MSG = {
    E_CAT_ID: 'معرّف الفئة مطلوب للتعديل. اضغط «تعديل» من الجدول ثم احفظ.',
    E_DEP: 'يجب اختيار القسم قبل الحفظ',
    E_DB: 'ربط الفئات بالأقسام غير مفعّل في قاعدة البيانات بعد',
    E_AR: 'يجب إضافة خانة الاسم العربي قبل الحفظ',
    E_EN: 'يجب إضافة خانة الاسم الإنجليزي قبل الحفظ',
    E_FIL: 'يجب إضافة خانة Filipino قبل الحفظ',
    E_HI: 'يجب إضافة خانة Hindi قبل الحفظ',
    E_SLUG: 'يجب إضافة خانة Slug قبل الحفظ',
    E_DUP: 'هذه الفئة مسجلة بالفعل بنفس القسم',
    E_REORDER: 'بيانات الترتيب غير صحيحة',
    OK_SAV: 'تم حفظ الفئة',
    OK_UPD: 'تم تحديث الفئة',
    OK_REORDER: 'تم حفظ ترتيب الفئات',
    OK_TOG: 'تم تحديث حالة الفئة'
};
let isSavingCategory = false;
let autoSlugTouched = false;
const defaultNextSort = <?php echo (int)$nextSort; ?>;
let translateTimer = null;

function resetCategoryForm() {
    document.getElementById('cat_record_id').value = '0';
    document.getElementById('department_id').value = '';
    document.getElementById('name_ar').value = '';
    document.getElementById('name_en').value = '';
    document.getElementById('name_fil').value = '';
    document.getElementById('name_hi').value = '';
    document.getElementById('slug').value = '';
    document.getElementById('sort_order').value = String(defaultNextSort || 1);
    autoSlugTouched = false;
}

function editCategory(cat) {
    document.getElementById('cat_record_id').value = String(cat.id != null ? cat.id : 0);
    document.getElementById('department_id').value = String(cat.department_id || '');
    document.getElementById('name_ar').value = cat.name_ar || '';
    document.getElementById('name_en').value = cat.name_en || '';
    document.getElementById('name_fil').value = cat.name_fil || '';
    document.getElementById('name_hi').value = cat.name_hi || '';
    document.getElementById('slug').value = cat.slug || '';
    document.getElementById('sort_order').value = String(cat.sort_order || 0);
    autoSlugTouched = false; // اسمح بالتحديث التلقائي أثناء التعديل حتى يلمس المستخدم slug يدويًا
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function slugify(str) {
    str = String(str || '').toLowerCase();
    str = str.replace(/[^a-z0-9\s-]/g, '');
    str = str.replace(/[\s-]+/g, '-');
    str = str.replace(/^-+|-+$/g, '');
    return str;
}

function refreshSlugIfAuto() {
    if (autoSlugTouched) return;
    const nameEn = document.getElementById('name_en').value.trim();
    const slugEl = document.getElementById('slug');
    const next = slugify(nameEn);
    if (next) slugEl.value = next;
}

async function translateCategory(opts = {}) {
    const silent = !!opts.silent;
    const forceFromArabic = !!opts.forceFromArabic;
    try {
        const currentEn = document.getElementById('name_en').value.trim();
        const payload = {
            name_ar: document.getElementById('name_ar').value.trim(),
            name_en: forceFromArabic ? '' : currentEn
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

async function saveCategory() {
    if (isSavingCategory) return;
    isSavingCategory = true;
    const departmentId = parseInt(document.getElementById('department_id').value || '0', 10);
    if (departmentId <= 0) {
        alert('اختر القسم أولًا');
        isSavingCategory = false;
        return;
    }
    const requiredFields = [
        { id: 'name_ar', label: 'الاسم العربي' },
        { id: 'name_en', label: 'الاسم الإنجليزي' },
        { id: 'name_fil', label: 'Filipino' },
        { id: 'name_hi', label: 'Hindi' },
        { id: 'slug', label: 'Slug' }
    ];
    for (const field of requiredFields) {
        const val = document.getElementById(field.id).value.trim();
        if (!val) {
            alert('يجب إضافة خانة ' + field.label + ' قبل الحفظ');
            isSavingCategory = false;
            return;
        }
    }
    try {
        const rawId = parseInt(String(document.getElementById('cat_record_id').value || '0').trim(), 10);
        const recordId = Number.isFinite(rawId) && rawId > 0 ? rawId : 0;
        const payload = {
            department_id: departmentId,
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
        const url = recordId > 0 ? '/admin/api/categories/update.php' : '/admin/api/categories/save.php';
        const res = await postJSON(url, payload);
        const rawMsg = res.message || (res.success ? 'OK_SAV' : 'فشل الحفظ');
        alert(CAT_API_MSG[rawMsg] || rawMsg);
        if (res.success) location.reload();
    } catch (e) {
        alert('فشل الاتصال بالخادم أثناء الحفظ');
    } finally {
        isSavingCategory = false;
    }
}

async function toggleCategory(id, isActive) {
    const res = await postJSON('/admin/api/categories/toggle.php', {
        id: id,
        is_active: isActive ? 0 : 1
    });
    const rawMsg = res.message || (res.success ? 'OK_TOG' : 'فشل التعديل');
    alert(CAT_API_MSG[rawMsg] || rawMsg);
    if (res.success) location.reload();
}

function moveCategoryRow(btn, dir) {
    const tr = btn.closest('tr');
    if (!tr) return;
    const tbody = document.getElementById('categoriesTbody');
    if (!tbody) return;
    if (dir === 'up') {
        const prev = tr.previousElementSibling;
        if (prev) tbody.insertBefore(tr, prev);
    } else {
        const next = tr.nextElementSibling;
        if (next) tbody.insertBefore(next, tr);
    }
}

async function saveCategoriesOrder() {
    const tbody = document.getElementById('categoriesTbody');
    if (!tbody) return;
    const ids = Array.from(tbody.querySelectorAll('tr[data-id]'))
        .map(tr => parseInt(tr.getAttribute('data-id') || '0', 10))
        .filter(id => id > 0);
    const res = await postJSON('/admin/api/categories/reorder-save.php', { ordered_ids: ids });
    const rawMsg = res.message || (res.success ? 'OK_REORDER' : 'فشل حفظ الترتيب');
    alert(CAT_API_MSG[rawMsg] || rawMsg);
    if (res.success) location.reload();
}

function scheduleAutoTranslate() {
    if (autoSlugTouched) return;
    const nameAr = document.getElementById('name_ar').value.trim();
    if (!nameAr) {
        document.getElementById('name_en').value = '';
        document.getElementById('name_fil').value = '';
        document.getElementById('name_hi').value = '';
        if (!autoSlugTouched) document.getElementById('slug').value = '';
        return;
    }
    clearTimeout(translateTimer);
    translateTimer = setTimeout(async () => {
        // عند تغيير العربي نحدث الترجمات/slug تلقائيًا (ما لم يكن المستخدم لمس slug يدويًا)
        await translateCategory({ silent: true, forceFromArabic: true });
    }, 600);
}

document.getElementById('slug').addEventListener('input', () => { autoSlugTouched = true; });
document.getElementById('name_en').addEventListener('input', refreshSlugIfAuto);
document.getElementById('name_ar').addEventListener('input', scheduleAutoTranslate);
document.getElementById('name_ar').addEventListener('change', async () => {
    // عند الانتهاء من تعديل العربي، أعد الترجمة من العربي مباشرة
    if (autoSlugTouched) return;
    const ar = document.getElementById('name_ar').value.trim();
    if (!ar) return;
    await translateCategory({ silent: true, forceFromArabic: true });
});

// زر "ترجمة تلقائية" يجب أن يترجم من العربي دائمًا
const translateBtn = document.querySelector('.cat-form-actions button.btn-secondary');
if (translateBtn) {
    translateBtn.onclick = () => translateCategory({ forceFromArabic: true });
}

// تحسين شكل أزرار الجدول لتكون متلاصقة
(() => {
    const style = document.createElement('style');
    style.textContent = `
        .cat-form-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            grid-template-areas:
                "blank sort"
                "ar dep"
                "fil en"
                "slug hi";
            gap:14px 18px;
            direction:ltr;
        }
        .cat-form-grid .cat-sort{
            grid-area:sort;
            justify-self:end;
            width:100%;
            max-width:180px;
        }
        .cat-form-grid .cat-dep{grid-area:dep}
        .cat-form-grid .cat-ar{grid-area:ar}
        .cat-form-grid .cat-en{grid-area:en}
        .cat-form-grid .cat-hi{grid-area:hi}
        .cat-form-grid .cat-fil{grid-area:fil}
        .cat-form-grid .cat-slug{grid-area:slug}
        .cat-form-grid label,
        .cat-form-grid input,
        .cat-form-grid select{direction:rtl;text-align:right}
        .cat-form-grid #sort_order{max-width:140px;margin-right:0;margin-left:auto;display:block}
        .cat-form-actions{justify-content:flex-end}
        @media (max-width: 860px){
            .cat-form-grid{grid-template-columns:1fr}
            .cat-form-grid .cat-sort,
            .cat-form-grid .cat-dep,
            .cat-form-grid .cat-ar,
            .cat-form-grid .cat-en,
            .cat-form-grid .cat-hi,
            .cat-form-grid .cat-fil,
            .cat-form-grid .cat-slug{grid-column:1}
            .cat-form-grid #sort_order{max-width:100%}
        }
        .cat-dep-list-wrap[data-list="categories"]{
            overflow-x:auto;
            max-width:100%;
            -webkit-overflow-scrolling:touch;
        }
        .cat-dep-list-wrap[data-list="categories"] > table{
            min-width:900px;
            width:100%;
            border-collapse:collapse;
            table-layout:fixed;
        }
        .cat-dep-list-wrap[data-list="categories"] > table th,
        .cat-dep-list-wrap[data-list="categories"] > table td{
            vertical-align:middle;
        }
        .cat-dep-list-wrap[data-list="categories"] table .cat-ops-col,
        .cat-dep-list-wrap[data-list="categories"] table .cat-row-ops{
            width:200px !important;
            min-width:200px !important;
            max-width:200px !important;
            box-sizing:border-box !important;
            text-align:center !important;
            vertical-align:middle !important;
            padding:6px 8px !important;
        }
        .cat-ops-wrap{
            display:grid;
            grid-template-columns:38px minmax(0,1fr);
            gap:8px;
            align-items:center;
            margin:0 auto;
            max-width:100%;
            direction:rtl;
        }
        .cat-ops-arrows{
            display:flex;
            flex-direction:column;
            gap:4px;
            align-items:center;
            justify-content:center;
        }
        .cat-dep-list-wrap[data-list="categories"] .cat-ops-wrap button.cat-btn-reorder{
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
        .cat-ops-main{
            display:flex;
            flex-direction:column;
            gap:5px;
            min-width:0;
        }
        .cat-dep-list-wrap[data-list="categories"] .cat-ops-main .btn-secondary,
        .cat-dep-list-wrap[data-list="categories"] .cat-ops-main .cat-btn-toggle{
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

    const tbody = document.getElementById('categoriesTbody');
    if (!tbody) return;
    tbody.addEventListener('click', function (ev) {
        const btn = ev.target.closest('.cat-edit-btn');
        if (!btn || !btn.dataset.catJson) return;
        try {
            editCategory(JSON.parse(btn.dataset.catJson));
        } catch (err) {
            alert('تعذر قراءة بيانات الفئة للتعديل');
        }
    });
})();
</script>
