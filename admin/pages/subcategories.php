<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$hasSubcategoriesTable = orange_table_exists($pdo, 'subcategories');
$hasProductSubcategoryColumn = orange_table_has_column($pdo, 'products', 'subcategory_id');

$hasDepartmentsTable = false;
$hasCategoryDepartment = false;
if ($hasDepartmentsTable = (bool) $pdo->query("SHOW TABLES LIKE 'departments'")->fetchColumn()) {
    $hasCategoryDepartment = (bool) $pdo->query("SHOW COLUMNS FROM categories LIKE 'department_id'")->fetch();
}

$categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
$departmentsForPage = [];
if ($hasDepartmentsTable) {
    $departmentsForPage = $pdo->query('SELECT * FROM departments ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
}

$subcategories = [];
$nextSort = 1;
if ($hasSubcategoriesTable) {
    $subcategories = $pdo->query(
        'SELECT s.*, c.name_ar AS category_name_ar, c.name_en AS category_name_en
         FROM subcategories s
         INNER JOIN categories c ON c.id = s.category_id
         ORDER BY s.sort_order ASC, s.id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $nextSort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM subcategories')->fetchColumn();
    if ($nextSort < 1) {
        $nextSort = 1;
    }
}
?>
<div class="page-title">
    <h1>فئات فرعية</h1>
</div>

<?php if (!$hasSubcategoriesTable || !$hasProductSubcategoryColumn): ?>
<div class="card">
    <div class="alert-error">
        <?php if (!$hasSubcategoriesTable): ?>
            جدول <code>subcategories</code> غير موجود في قاعدة البيانات.
        <?php else: ?>
            عمود <code>products.subcategory_id</code> غير موجود.
        <?php endif; ?>
        بعد إنشاء الجدول/العمود ستظهر هذه الصفحة وستُفعَّل خانة «فئة فرعية» في نموذج المنتج.
    </div>
</div>
<?php return; endif; ?>

<div class="card">
    <p style="margin:0 0 12px;font-size:14px;color:#555;">
        الفئة الفرعية مستوى تحت <strong>الفئة</strong> (مثل: تيشرتات / بناطيل تحت «ملابس نسائية»). يُربط المنتج بها من
        <a href="/admin/index.php?page=products">المنتجات</a> بعد اختيار الفئة.
    </p>
</div>

<div class="card">
    <h3>إضافة / تعديل فئة فرعية</h3>
    <input type="hidden" id="subcat_record_id" value="0">
    <div class="form-grid" style="max-width:920px;">
        <div style="grid-column:1/-1;">
            <label for="subcat_category_id">الفئة (الأم)</label>
            <select id="subcat_category_id" required>
                <option value="">اختر الفئة</option>
                <?php if ($hasDepartmentsTable && $hasCategoryDepartment && $departmentsForPage !== []): ?>
                    <?php
                    $catsByDept = [];
                    foreach ($categories as $cat) {
                        $did = isset($cat['department_id']) && $cat['department_id'] !== null ? (int) $cat['department_id'] : 0;
                        if (!isset($catsByDept[$did])) {
                            $catsByDept[$did] = [];
                        }
                        $catsByDept[$did][] = $cat;
                    }
                    ?>
                    <?php foreach ($departmentsForPage as $dep): ?>
                        <?php
                        $did = (int) $dep['id'];
                        $deptCats = $catsByDept[$did] ?? [];
                        if ($deptCats === []) {
                            continue;
                        }
                        $ogLabel = (string) ($dep['name_ar'] ?: $dep['name_en'] ?: ('#' . $did));
                        ?>
                        <optgroup label="<?php echo htmlspecialchars($ogLabel, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php foreach ($deptCats as $cat): ?>
                                <option value="<?php echo (int) $cat['id']; ?>"><?php echo htmlspecialchars($cat['name_ar'] ?: $cat['name_en']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                    <?php if (!empty($catsByDept[0])): ?>
                        <optgroup label="بدون قسم">
                            <?php foreach ($catsByDept[0] as $cat): ?>
                                <option value="<?php echo (int) $cat['id']; ?>"><?php echo htmlspecialchars($cat['name_ar'] ?: $cat['name_en']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                <?php else: ?>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int) $cat['id']; ?>"><?php echo htmlspecialchars($cat['name_ar'] ?: $cat['name_en']); ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        <div>
            <label>الترتيب (تلقائي)</label>
            <input type="number" id="subcat_sort_order" value="<?php echo (int) $nextSort; ?>" disabled style="max-width:140px;">
        </div>
        <div>
            <label>الاسم العربي</label>
            <input type="text" id="subcat_name_ar">
        </div>
        <div>
            <label>English</label>
            <input type="text" id="subcat_name_en">
        </div>
        <div>
            <label>Filipino</label>
            <input type="text" id="subcat_name_fil">
        </div>
        <div>
            <label>Hindi</label>
            <input type="text" id="subcat_name_hi">
        </div>
        <div style="grid-column:1/-1;">
            <label>Slug</label>
            <input type="text" id="subcat_slug" disabled>
        </div>
    </div>
    <div class="actions" style="margin-top:14px;">
        <button type="button" id="btnSubcatSave" onclick="saveSubcategory()">حفظ</button>
        <button type="button" class="btn-secondary" onclick="translateSubcategory({ forceFromArabic: true })">ترجمة تلقائية</button>
        <button type="button" class="btn-secondary" onclick="resetSubcategoryForm()">جديد</button>
    </div>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;">قائمة الفئات الفرعية</h3>
        <div class="actions">
            <button type="button" class="btn-secondary" onclick="saveSubcategoriesOrder()">حفظ الترتيب</button>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الفئة</th>
                    <th>العربي</th>
                    <th>English</th>
                    <th>Slug</th>
                    <th>الترتيب</th>
                    <th>الحالة</th>
                    <th style="min-width:200px;">إجراءات</th>
                </tr>
            </thead>
            <tbody id="subcategoriesTbody">
                <?php foreach ($subcategories as $s): ?>
                <tr data-id="<?php echo (int) $s['id']; ?>">
                    <td><?php echo (int) $s['id']; ?></td>
                    <td><?php echo htmlspecialchars((string) ($s['category_name_ar'] ?: $s['category_name_en'] ?: '-')); ?></td>
                    <td><?php echo htmlspecialchars((string) $s['name_ar']); ?></td>
                    <td><?php echo htmlspecialchars((string) ($s['name_en'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string) $s['slug']); ?></td>
                    <td><?php echo (int) $s['sort_order']; ?></td>
                    <td><?php echo (int) $s['is_active'] === 1 ? 'ظاهر' : 'مخفي'; ?></td>
                    <td>
                        <button type="button" class="btn-secondary subcat-edit-btn" data-subcat-json="<?php echo htmlspecialchars(json_encode([
                            'id' => (int) $s['id'],
                            'category_id' => (int) $s['category_id'],
                            'name_ar' => (string) $s['name_ar'],
                            'name_en' => (string) ($s['name_en'] ?? ''),
                            'name_fil' => (string) ($s['name_fil'] ?? ''),
                            'name_hi' => (string) ($s['name_hi'] ?? ''),
                            'slug' => (string) $s['slug'],
                            'sort_order' => (int) $s['sort_order'],
                        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">تعديل</button>
                        <button type="button" class="btn-secondary" onclick="toggleSubcategory(<?php echo (int) $s['id']; ?>, <?php echo (int) $s['is_active']; ?>)">
                            <?php echo (int) $s['is_active'] === 1 ? 'إخفاء' : 'إظهار'; ?>
                        </button>
                        <button type="button" class="btn-secondary" onclick="moveSubcategoryRow(this,'up')" aria-label="أعلى">↑</button>
                        <button type="button" class="btn-secondary" onclick="moveSubcategoryRow(this,'down')" aria-label="أسفل">↓</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const SUBCAT_MSG = {
    E_CAT: 'اختر الفئة الأم',
    E_AR: 'أدخل الاسم العربي',
    E_EN: 'أدخل الاسم الإنجليزي',
    E_FIL: 'أدخل Filipino',
    E_HI: 'أدخل Hindi',
    E_SLUG: 'أدخل Slug',
    E_ID: 'معرّف غير صالح',
    E_CAT_INVALID: 'الفئة غير موجودة',
    E_NO_TABLE: 'جدول الفئات الفرعية غير متوفر',
    E_DUP: 'اسم عربي مكرر ضمن نفس الفئة',
    E_REORDER: 'بيانات الترتيب غير صحيحة',
    OK_SAV: 'تم الحفظ',
    OK_UPD: 'تم التحديث',
    OK_TOG: 'تم تحديث الحالة',
    OK_REORDER: 'تم حفظ الترتيب'
};
let isSavingSubcat = false;
let subcatAutoSlugTouched = false;
const subcatDefaultNextSort = <?php echo (int) $nextSort; ?>;
let subcatTranslateTimer = null;
let subcatEnTranslateTimer = null;

function resetSubcategoryForm() {
    document.getElementById('subcat_record_id').value = '0';
    document.getElementById('subcat_category_id').value = '';
    document.getElementById('subcat_name_ar').value = '';
    document.getElementById('subcat_name_en').value = '';
    document.getElementById('subcat_name_fil').value = '';
    document.getElementById('subcat_name_hi').value = '';
    document.getElementById('subcat_slug').value = '';
    document.getElementById('subcat_sort_order').value = String(subcatDefaultNextSort || 1);
    subcatAutoSlugTouched = false;
}

function editSubcategory(row) {
    document.getElementById('subcat_record_id').value = String(row.id != null ? row.id : 0);
    document.getElementById('subcat_category_id').value = String(row.category_id || '');
    document.getElementById('subcat_name_ar').value = row.name_ar || '';
    document.getElementById('subcat_name_en').value = row.name_en || '';
    document.getElementById('subcat_name_fil').value = row.name_fil || '';
    document.getElementById('subcat_name_hi').value = row.name_hi || '';
    document.getElementById('subcat_slug').value = row.slug || '';
    document.getElementById('subcat_sort_order').value = String(row.sort_order || 0);
    subcatAutoSlugTouched = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function slugifySubcat(str) {
    str = String(str || '').toLowerCase();
    str = str.replace(/[^a-z0-9\s-]/g, '');
    str = str.replace(/[\s-]+/g, '-');
    str = str.replace(/^-+|-+$/g, '');
    return str;
}

function refreshSubcatSlugIfAuto() {
    if (subcatAutoSlugTouched) return;
    const nameEn = document.getElementById('subcat_name_en').value.trim();
    const slugEl = document.getElementById('subcat_slug');
    const next = slugifySubcat(nameEn);
    if (next) slugEl.value = next;
}

async function translateSubcategory(opts) {
    opts = opts || {};
    const silent = !!opts.silent;
    const forceFromArabic = !!opts.forceFromArabic;
    try {
        const currentEn = document.getElementById('subcat_name_en').value.trim();
        const payload = {
            name_ar: document.getElementById('subcat_name_ar').value.trim(),
            name_en: forceFromArabic ? '' : currentEn
        };
        const res = await postJSON('/admin/api/translate/names.php', payload);
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        const t = res.translations || {};
        if (t.name_en) document.getElementById('subcat_name_en').value = t.name_en;
        if (t.name_fil) document.getElementById('subcat_name_fil').value = t.name_fil;
        if (t.name_hi) document.getElementById('subcat_name_hi').value = t.name_hi;
        refreshSubcatSlugIfAuto();
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة');
    }
}

async function saveSubcategory() {
    if (isSavingSubcat) return;
    isSavingSubcat = true;
    const categoryId = parseInt(document.getElementById('subcat_category_id').value || '0', 10);
    if (categoryId <= 0) {
        alert(SUBCAT_MSG.E_CAT);
        isSavingSubcat = false;
        return;
    }
    const fields = [
        { id: 'subcat_name_ar', msg: SUBCAT_MSG.E_AR },
        { id: 'subcat_name_en', msg: SUBCAT_MSG.E_EN },
        { id: 'subcat_name_fil', msg: SUBCAT_MSG.E_FIL },
        { id: 'subcat_name_hi', msg: SUBCAT_MSG.E_HI },
        { id: 'subcat_slug', msg: SUBCAT_MSG.E_SLUG }
    ];
    for (const f of fields) {
        if (!document.getElementById(f.id).value.trim()) {
            alert(f.msg);
            isSavingSubcat = false;
            return;
        }
    }
    try {
        const rawId = parseInt(String(document.getElementById('subcat_record_id').value || '0').trim(), 10);
        const recordId = Number.isFinite(rawId) && rawId > 0 ? rawId : 0;
        const payload = {
            category_id: categoryId,
            name_ar: document.getElementById('subcat_name_ar').value.trim(),
            name_en: document.getElementById('subcat_name_en').value.trim(),
            name_fil: document.getElementById('subcat_name_fil').value.trim(),
            name_hi: document.getElementById('subcat_name_hi').value.trim(),
            slug: document.getElementById('subcat_slug').value.trim(),
            sort_order: parseInt(document.getElementById('subcat_sort_order').value || '0', 10)
        };
        if (recordId > 0) {
            payload.id = recordId;
        }
        const url = recordId > 0 ? '/admin/api/subcategories/update.php' : '/admin/api/subcategories/save.php';
        const res = await postJSON(url, payload);
        const rawMsg = res.message || (res.success ? 'OK_SAV' : 'فشل');
        alert(SUBCAT_MSG[rawMsg] || rawMsg);
        if (res.success) {
            location.reload();
        }
    } catch (e) {
        alert('فشل الاتصال بالخادم');
    } finally {
        isSavingSubcat = false;
    }
}

async function toggleSubcategory(id, isActive) {
    const res = await postJSON('/admin/api/subcategories/toggle.php', {
        id: id,
        is_active: isActive ? 0 : 1
    });
    const rawMsg = res.message || (res.success ? 'OK_TOG' : 'فشل');
    alert(SUBCAT_MSG[rawMsg] || rawMsg);
    if (res.success) {
        location.reload();
    }
}

function moveSubcategoryRow(btn, dir) {
    const tr = btn.closest('tr');
    if (!tr) return;
    const tbody = document.getElementById('subcategoriesTbody');
    if (!tbody) return;
    if (dir === 'up') {
        const prev = tr.previousElementSibling;
        if (prev) tbody.insertBefore(tr, prev);
    } else {
        const next = tr.nextElementSibling;
        if (next) tbody.insertBefore(next, tr);
    }
}

async function saveSubcategoriesOrder() {
    const tbody = document.getElementById('subcategoriesTbody');
    if (!tbody) return;
    const ids = Array.from(tbody.querySelectorAll('tr[data-id]'))
        .map(tr => parseInt(tr.getAttribute('data-id') || '0', 10))
        .filter(id => id > 0);
    const res = await postJSON('/admin/api/subcategories/reorder-save.php', { ordered_ids: ids });
    const rawMsg = res.message || (res.success ? 'OK_REORDER' : 'فشل');
    alert(SUBCAT_MSG[rawMsg] || rawMsg);
    if (res.success) {
        location.reload();
    }
}

function scheduleSubcatAutoTranslate() {
    if (subcatAutoSlugTouched) return;
    const nameAr = document.getElementById('subcat_name_ar').value.trim();
    if (!nameAr) {
        document.getElementById('subcat_name_en').value = '';
        document.getElementById('subcat_name_fil').value = '';
        document.getElementById('subcat_name_hi').value = '';
        if (!subcatAutoSlugTouched) document.getElementById('subcat_slug').value = '';
        return;
    }
    clearTimeout(subcatTranslateTimer);
    subcatTranslateTimer = setTimeout(() => translateSubcategory({ silent: true, forceFromArabic: true }), 650);
}

function scheduleSubcatFromEnglish() {
    const nameEn = document.getElementById('subcat_name_en').value.trim();
    if (!nameEn) return;
    clearTimeout(subcatEnTranslateTimer);
    subcatEnTranslateTimer = setTimeout(() => translateSubcategory({ silent: true, forceFromArabic: false }), 600);
}

function onSubcatNameEnInput() {
    refreshSubcatSlugIfAuto();
    scheduleSubcatFromEnglish();
}

document.getElementById('subcat_slug').addEventListener('input', () => { subcatAutoSlugTouched = true; });
document.getElementById('subcat_name_en').addEventListener('input', onSubcatNameEnInput);
document.getElementById('subcat_name_ar').addEventListener('input', scheduleSubcatAutoTranslate);

(function () {
    const tbody = document.getElementById('subcategoriesTbody');
    if (!tbody) return;
    tbody.addEventListener('click', function (ev) {
        const btn = ev.target.closest('.subcat-edit-btn');
        if (!btn || !btn.dataset.subcatJson) return;
        try {
            editSubcategory(JSON.parse(btn.dataset.subcatJson));
        } catch (err) {
            alert('تعذر قراءة البيانات');
        }
    });
})();
</script>
