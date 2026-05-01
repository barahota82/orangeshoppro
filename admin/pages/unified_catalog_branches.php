<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_taxonomy_migrate.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$unifiedActive = orange_catalog_nav_use_unified($pdo);

$hasUnified = orange_table_exists($pdo, 'catalog_sections')
    && orange_table_exists($pdo, 'catalog_categories')
    && orange_table_exists($pdo, 'catalog_subcategories')
    && orange_table_exists($pdo, 'departments');

$departments = [];
$sectionsFlat = [];
$categoriesFlat = [];
$subcatsFlat = [];

if ($hasUnified && orange_table_exists($pdo, 'departments')) {
    try {
        $departments = $pdo->query(
            'SELECT id, name_ar, name_en, slug FROM departments WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $sectionsFlat = $pdo->query(
            'SELECT cs.id, cs.slug, cs.name_ar, cs.name_en, cs.name_fil, cs.name_hi, cs.department_id, cs.sort_order, cs.is_active,
                    COALESCE(NULLIF(TRIM(d.name_ar), \'\'), d.name_en, d.slug) AS dept_label
             FROM catalog_sections cs
             INNER JOIN departments d ON d.id = cs.department_id
             ORDER BY d.sort_order ASC, d.id ASC, cs.sort_order ASC, cs.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $categoriesFlat = $pdo->query(
            'SELECT cc.id, cc.slug, cc.name_ar, cc.name_en, cc.name_fil, cc.name_hi, cc.catalog_section_id, cc.sort_order, cc.is_active,
                    cs.slug AS sec_slug,
                    COALESCE(NULLIF(TRIM(cs.name_ar), \'\'), cs.name_en, cs.slug) AS sec_label,
                    COALESCE(NULLIF(TRIM(d.name_ar), \'\'), d.name_en, d.slug) AS dept_label
             FROM catalog_categories cc
             INNER JOIN catalog_sections cs ON cs.id = cc.catalog_section_id
             INNER JOIN departments d ON d.id = cs.department_id
             ORDER BY d.sort_order ASC, d.id ASC, cs.sort_order ASC, cs.id ASC, cc.sort_order ASC, cc.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $subcatsFlat = $pdo->query(
            'SELECT csub.id, csub.slug, csub.name_ar, csub.name_en, csub.name_fil, csub.name_hi, csub.catalog_category_id, csub.sort_order, csub.is_active,
                    COALESCE(NULLIF(TRIM(cc.name_ar), \'\'), cc.name_en, cc.slug) AS cat_label,
                    COALESCE(NULLIF(TRIM(cs.name_ar), \'\'), cs.name_en, cs.slug) AS sec_label,
                    COALESCE(NULLIF(TRIM(d.name_ar), \'\'), d.name_en, d.slug) AS dept_label
             FROM catalog_subcategories csub
             INNER JOIN catalog_categories cc ON cc.id = csub.catalog_category_id
             INNER JOIN catalog_sections cs ON cs.id = cc.catalog_section_id
             INNER JOIN departments d ON d.id = cs.department_id
             ORDER BY d.sort_order ASC, d.id ASC, cs.sort_order ASC, cs.id ASC, cc.sort_order ASC, cc.id ASC, csub.sort_order ASC, csub.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $departments = [];
        $sectionsFlat = [];
        $categoriesFlat = [];
        $subcatsFlat = [];
    }
}

$sectionSelectOptions = [];
foreach ($sectionsFlat as $s) {
    if (! is_array($s)) {
        continue;
    }
    $sid = (int) ($s['id'] ?? 0);
    if ($sid <= 0) {
        continue;
    }
    $sectionSelectOptions[] = [
        'id' => $sid,
        'label' => trim((string) ($s['dept_label'] ?? '')) . ' ← ' . trim((string) (($s['name_ar'] ?: $s['name_en']) ?: $s['slug'] ?? '')),
    ];
}

$categorySelectOptions = [];
foreach ($categoriesFlat as $c) {
    if (! is_array($c)) {
        continue;
    }
    $cid = (int) ($c['id'] ?? 0);
    if ($cid <= 0) {
        continue;
    }
    $categorySelectOptions[] = [
        'id' => $cid,
        'label' => trim((string) ($c['dept_label'] ?? '')) . ' ← ' . trim((string) ($c['sec_label'] ?? '')) . ' ← '
            . trim((string) (($c['name_ar'] ?: $c['name_en']) ?: $c['slug'] ?? '')),
    ];
}

$sectionOptsJson = json_encode($sectionSelectOptions, JSON_UNESCAPED_UNICODE) ?: '[]';
$categoryOptsJson = json_encode($categorySelectOptions, JSON_UNESCAPED_UNICODE) ?: '[]';
?>
<div class="page-title">
    <h1>فروع الشجرة الموحّدة</h1>
    <p class="page-subtitle" style="margin:0.35rem 0 0;font-size:0.95rem;color:#555;line-height:1.5;">المستويات <strong>قسم داخلي (Section)</strong> → <strong>فئة</strong> → <strong>تصنيف فرعي</strong> تحت جدايل <code>catalog_*</code> وفق ERD؛ بعدها تُعرَّف <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=product_types'), ENT_QUOTES, 'UTF-8'); ?>">أنواع المنتجات</a> كورقة قبل SKU. الأقسام العلوية <code>departments</code> تُدار من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=departments'), ENT_QUOTES, 'UTF-8'); ?>">صفحة الأقسام</a>.</p>
</div>

<?php if (!$hasUnified): ?>
<div class="card">
    <div class="alert-error">جدايل الشجرة الموحّدة غير مهيّأة.</div>
</div>
<?php else: ?>

<?php if (! $unifiedActive): ?>
<div class="card" style="margin-bottom:12px;background:#fffbeb;border-color:#fcd34d;">
    <p style="margin:0;color:#92400e;">مسار المتجر الموحّد لم يُفعَّل بعد (ترحيل البيانات). يمكن إنشاء الفروع الآن لتجهيز البنية قبل ربط المنتجات بـ <code>product_type_id</code>.</p>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:14px;">
    <h3 style="margin-top:0;">1 — أقسام داخلية (catalog_sections)</h3>
    <input type="hidden" id="uc_sec_id" value="0">
    <div class="uc-form-grid">
        <div><label for="uc_sec_department_id">القسم (department)</label>
            <select id="uc_sec_department_id">
                <?php foreach ($departments as $d): ?>
                    <?php if (! is_array($d)) { continue; } ?>
                    <option value="<?php echo (int) ($d['id'] ?? 0); ?>"><?php echo htmlspecialchars((string) (($d['name_ar'] ?: $d['name_en']) ?: $d['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($departments === []): ?>
                <small style="color:#b45309;display:block;margin-top:4px;">لا توجد أقسام نشطة — أضف قسمًا من لوحة الأقسام.</small>
            <?php endif; ?>
        </div>
        <div><label for="uc_sec_slug">slug</label>
            <input type="text" id="uc_sec_slug" dir="ltr" maxlength="191" autocomplete="off" <?php echo $departments === [] ? 'disabled' : ''; ?>>
        </div>
        <div><label for="uc_sec_sort">ترتيب</label>
            <input type="number" id="uc_sec_sort" min="1" step="1" value="" placeholder="تلقائي" <?php echo $departments === [] ? 'disabled' : ''; ?>>
        </div>
        <div><label for="uc_sec_name_ar">عربي</label><input type="text" id="uc_sec_name_ar" <?php echo $departments === [] ? 'disabled' : ''; ?>></div>
        <div><label for="uc_sec_name_fil">Filipino</label><input type="text" id="uc_sec_name_fil" <?php echo $departments === [] ? 'disabled' : ''; ?>></div>
        <div><label for="uc_sec_name_en">English</label><input type="text" id="uc_sec_name_en" dir="ltr" <?php echo $departments === [] ? 'disabled' : ''; ?>></div>
        <div><label for="uc_sec_name_hi">Hindi</label><input type="text" id="uc_sec_name_hi" <?php echo $departments === [] ? 'disabled' : ''; ?>></div>
        <div><label for="uc_sec_active">نشط</label>
            <select id="uc_sec_active" <?php echo $departments === [] ? 'disabled' : ''; ?>><option value="1">نعم</option><option value="0">لا</option></select>
        </div>
    </div>
    <div class="actions" style="margin-top:12px;gap:8px;flex-wrap:wrap;">
        <button type="button" onclick="saveUcSection()" <?php echo $departments === [] ? 'disabled' : ''; ?>>حفظ القسم الداخلي</button>
        <button type="button" class="btn-secondary" onclick="translateUc('sec')" <?php echo $departments === [] ? 'disabled' : ''; ?>>ترجمة</button>
        <button type="button" class="btn-secondary" onclick="resetUcSection()" <?php echo $departments === [] ? 'disabled' : ''; ?>>جديد</button>
    </div>
    <?php if ($sectionsFlat !== []): ?>
    <div style="overflow-x:auto;margin-top:16px;">
        <table class="uc-table"><thead><tr>
            <th>#</th><th>مسار</th><th>slug</th><th>عربي</th><th>ترتيب</th><th>نشط</th><th>إجراء</th>
        </tr></thead><tbody>
        <?php foreach ($sectionsFlat as $row): ?>
            <?php if (! is_array($row)) { continue; } ?>
            <tr>
                <td><?php echo (int) ($row['id'] ?? 0); ?></td>
                <td><?php echo htmlspecialchars(trim((string) ($row['dept_label'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                <td dir="ltr"><?php echo htmlspecialchars((string) ($row['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string) ($row['name_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo (int) ($row['sort_order'] ?? 0); ?></td>
                <td><?php echo ((int) ($row['is_active'] ?? 0) === 1) ? '√' : '—'; ?></td>
                <td><button type="button" class="btn-secondary uc-edit-sec" data-json="<?php echo htmlspecialchars(json_encode([
                    'id' => (int) ($row['id'] ?? 0),
                    'department_id' => (int) ($row['department_id'] ?? 0),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'name_ar' => (string) ($row['name_ar'] ?? ''),
                    'name_en' => (string) ($row['name_en'] ?? ''),
                    'name_fil' => (string) ($row['name_fil'] ?? ''),
                    'name_hi' => (string) ($row['name_hi'] ?? ''),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'is_active' => (int) ($row['is_active'] ?? 1),
                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">تعديل</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom:14px;">
    <h3 style="margin-top:0;">2 — فئات الموحّد (catalog_categories)</h3>
    <input type="hidden" id="uc_cat_id" value="0">
    <div class="uc-form-grid">
        <div style="grid-column:1/-1;">
            <label for="uc_cat_section_id">القسم الداخلي الأم</label>
            <select id="uc_cat_section_id" <?php echo $sectionSelectOptions === [] ? 'disabled' : ''; ?>>
                <option value="">— اختر —</option>
                <?php foreach ($sectionSelectOptions as $opt): ?>
                    <option value="<?php echo (int) $opt['id']; ?>"><?php echo htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($sectionSelectOptions === []): ?>
                <small style="color:#b45309;display:block;margin-top:4px;">أنشئ قسمًا داخليًا أولًا.</small>
            <?php endif; ?>
        </div>
        <div><label for="uc_cat_slug">slug</label><input type="text" id="uc_cat_slug" dir="ltr" maxlength="191" autocomplete="off" <?php echo $sectionSelectOptions === [] ? 'disabled' : ''; ?>></div>
        <div><label for="uc_cat_sort">ترتيب</label><input type="number" id="uc_cat_sort" min="1" step="1" value="" placeholder="تلقائي" <?php echo $sectionSelectOptions === [] ? 'disabled' : ''; ?>></div>
        <div><label for="uc_cat_name_ar">عربي</label><input type="text" id="uc_cat_name_ar" <?php echo $sectionSelectOptions === [] ? 'disabled' : ''; ?>></div>
        <div><label for="uc_cat_name_fil">Filipino</label><input type="text" id="uc_cat_name_fil" <?php echo $sectionSelectOptions === [] ? 'disabled' : ''; ?>></div>
        <div><label for="uc_cat_name_en">English</label><input type="text" id="uc_cat_name_en" dir="ltr" <?php echo $sectionSelectOptions === [] ? 'disabled' : ''; ?>></div>
        <div><label for="uc_cat_name_hi">Hindi</label><input type="text" id="uc_cat_name_hi" <?php echo $sectionSelectOptions === [] ? 'disabled' : ''; ?>></div>
        <div><label for="uc_cat_active">نشط</label><select id="uc_cat_active" <?php echo $sectionSelectOptions === [] ? 'disabled' : ''; ?>><option value="1">نعم</option><option value="0">لا</option></select></div>
    </div>
    <div class="actions" style="margin-top:12px;gap:8px;flex-wrap:wrap;">
        <button type="button" onclick="saveUcCategory()" <?php echo $sectionSelectOptions === [] ? 'disabled' : ''; ?>>حفظ الفئة</button>
        <button type="button" class="btn-secondary" onclick="translateUc('cat')" <?php echo $sectionSelectOptions === [] ? 'disabled' : ''; ?>>ترجمة</button>
        <button type="button" class="btn-secondary" onclick="resetUcCategory()" <?php echo $sectionSelectOptions === [] ? 'disabled' : ''; ?>>جديد</button>
    </div>
    <?php if ($categoriesFlat !== []): ?>
    <div style="overflow-x:auto;margin-top:16px;">
        <table class="uc-table"><thead><tr>
            <th>#</th><th>مسار</th><th>slug</th><th>عربي</th><th>ترتيب</th><th>نشط</th><th>إجراء</th>
        </tr></thead><tbody>
        <?php foreach ($categoriesFlat as $row): ?>
            <?php if (! is_array($row)) { continue; } ?>
            <tr>
                <td><?php echo (int) ($row['id'] ?? 0); ?></td>
                <td><?php echo htmlspecialchars(trim((string) ($row['dept_label'] ?? '')) . ' ← ' . trim((string) ($row['sec_label'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                <td dir="ltr"><?php echo htmlspecialchars((string) ($row['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string) ($row['name_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo (int) ($row['sort_order'] ?? 0); ?></td>
                <td><?php echo ((int) ($row['is_active'] ?? 0) === 1) ? '√' : '—'; ?></td>
                <td><button type="button" class="btn-secondary uc-edit-cat" data-json="<?php echo htmlspecialchars(json_encode([
                    'id' => (int) ($row['id'] ?? 0),
                    'catalog_section_id' => (int) ($row['catalog_section_id'] ?? 0),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'name_ar' => (string) ($row['name_ar'] ?? ''),
                    'name_en' => (string) ($row['name_en'] ?? ''),
                    'name_fil' => (string) ($row['name_fil'] ?? ''),
                    'name_hi' => (string) ($row['name_hi'] ?? ''),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'is_active' => (int) ($row['is_active'] ?? 1),
                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">تعديل</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom:14px;">
    <h3 style="margin-top:0;">3 — تصنيفات فرعية (catalog_subcategories)</h3>
    <input type="hidden" id="uc_sub_id" value="0">
    <div class="uc-form-grid">
        <div style="grid-column:1/-1;">
            <label for="uc_sub_category_id">الفئة الموحّدة الأم</label>
            <select id="uc_sub_category_id" <?php echo $categorySelectOptions === [] ? 'disabled' : ''; ?>>
                <option value="">— اختر —</option>
                <?php foreach ($categorySelectOptions as $opt): ?>
                    <option value="<?php echo (int) $opt['id']; ?>"><?php echo htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($categorySelectOptions === []): ?>
                <small style="color:#b45309;display:block;margin-top:4px;">أنشئ فئة موحّدة أولًا.</small>
            <?php endif; ?>
        </div>
        <div><label for="uc_sub_slug">slug</label><input type="text" id="uc_sub_slug" dir="ltr" maxlength="191" autocomplete="off" <?php echo $categorySelectOptions === [] ? 'disabled' : ''; ?>></div>
        <div><label for="uc_sub_sort">ترتيب</label><input type="number" id="uc_sub_sort" min="1" step="1" value="" placeholder="تلقائي" <?php echo $categorySelectOptions === [] ? 'disabled' : ''; ?>></div>
        <div><label for="uc_sub_name_ar">عربي</label><input type="text" id="uc_sub_name_ar" <?php echo $categorySelectOptions === [] ? 'disabled' : ''; ?>></div>
        <div><label for="uc_sub_name_fil">Filipino</label><input type="text" id="uc_sub_name_fil" <?php echo $categorySelectOptions === [] ? 'disabled' : ''; ?>></div>
        <div><label for="uc_sub_name_en">English</label><input type="text" id="uc_sub_name_en" dir="ltr" <?php echo $categorySelectOptions === [] ? 'disabled' : ''; ?>></div>
        <div><label for="uc_sub_name_hi">Hindi</label><input type="text" id="uc_sub_name_hi" <?php echo $categorySelectOptions === [] ? 'disabled' : ''; ?>></div>
        <div><label for="uc_sub_active">نشط</label><select id="uc_sub_active" <?php echo $categorySelectOptions === [] ? 'disabled' : ''; ?>><option value="1">نعم</option><option value="0">لا</option></select></div>
    </div>
    <div class="actions" style="margin-top:12px;gap:8px;flex-wrap:wrap;">
        <button type="button" onclick="saveUcSubcategory()" <?php echo $categorySelectOptions === [] ? 'disabled' : ''; ?>>حفظ التصنيف الفرعي</button>
        <button type="button" class="btn-secondary" onclick="translateUc('sub')" <?php echo $categorySelectOptions === [] ? 'disabled' : ''; ?>>ترجمة</button>
        <button type="button" class="btn-secondary" onclick="resetUcSubcategory()" <?php echo $categorySelectOptions === [] ? 'disabled' : ''; ?>>جديد</button>
    </div>
    <?php if ($subcatsFlat !== []): ?>
    <div style="overflow-x:auto;margin-top:16px;">
        <table class="uc-table"><thead><tr>
            <th>#</th><th>مسار</th><th>slug</th><th>عربي</th><th>ترتيب</th><th>نشط</th><th>إجراء</th>
        </tr></thead><tbody>
        <?php foreach ($subcatsFlat as $row): ?>
            <?php if (! is_array($row)) { continue; } ?>
            <tr>
                <td><?php echo (int) ($row['id'] ?? 0); ?></td>
                <td><?php echo htmlspecialchars(trim((string) ($row['dept_label'] ?? '')) . ' ← ' . trim((string) ($row['sec_label'] ?? '')) . ' ← ' . trim((string) ($row['cat_label'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                <td dir="ltr"><?php echo htmlspecialchars((string) ($row['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string) ($row['name_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo (int) ($row['sort_order'] ?? 0); ?></td>
                <td><?php echo ((int) ($row['is_active'] ?? 0) === 1) ? '√' : '—'; ?></td>
                <td><button type="button" class="btn-secondary uc-edit-sub" data-json="<?php echo htmlspecialchars(json_encode([
                    'id' => (int) ($row['id'] ?? 0),
                    'catalog_category_id' => (int) ($row['catalog_category_id'] ?? 0),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'name_ar' => (string) ($row['name_ar'] ?? ''),
                    'name_en' => (string) ($row['name_en'] ?? ''),
                    'name_fil' => (string) ($row['name_fil'] ?? ''),
                    'name_hi' => (string) ($row['name_hi'] ?? ''),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'is_active' => (int) ($row['is_active'] ?? 1),
                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">تعديل</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
    <?php endif; ?>
</div>

<style>
.uc-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px;direction:ltr;}
.uc-form-grid label,.uc-form-grid input,.uc-form-grid select{direction:rtl;text-align:right;}
.uc-form-grid #uc_sec_slug,#uc_cat_slug,#uc_sub_slug,#uc_sec_name_en,#uc_cat_name_en,#uc_sub_name_en{text-align:left;direction:ltr;}
@media(max-width:860px){.uc-form-grid{grid-template-columns:1fr;}}
.uc-table{border-collapse:collapse;width:100%;font-size:0.93rem;}
.uc-table th,.uc-table td{padding:10px;border-bottom:1px solid #f0f1f5;vertical-align:top;}
.uc-table thead th{border-bottom-color:#e8e9ec;text-align:right;}
.uc-table thead th:last-child{text-align:center;}
.uc-table tbody td:last-child{text-align:center;}
</style>

<script>
const ucSectionOptions = <?php echo $sectionOptsJson; ?>;
const ucCategoryOptions = <?php echo $categoryOptsJson; ?>;
let ucTimers = {};
let ucEnTimers = {};
let ucSaving = false;

function parseSortPayload(raw) {
    const t = String(raw || '').trim();
    return t === '' ? 0 : ((parseInt(t, 10) || 0));
}

async function ucPost(url, payload) {
    ucSaving = true;
    try {
        const res = await postJSON(url, payload);
        alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
        if (res.success) location.reload();
    } catch (e) {
        alert('فشل الاتصال بالخادم أثناء الحفظ');
    } finally {
        ucSaving = false;
    }
}

function resetUcSection() {
    document.getElementById('uc_sec_id').value = '0';
    var dsel = document.getElementById('uc_sec_department_id');
    if (dsel && dsel.options.length) dsel.selectedIndex = 0;
    document.getElementById('uc_sec_slug').value = '';
    document.getElementById('uc_sec_sort').value = '';
    document.getElementById('uc_sec_name_ar').value = '';
    document.getElementById('uc_sec_name_en').value = '';
    document.getElementById('uc_sec_name_fil').value = '';
    document.getElementById('uc_sec_name_hi').value = '';
    document.getElementById('uc_sec_active').value = '1';
}

function editUcSection(j) {
    document.getElementById('uc_sec_id').value = String(j.id);
    document.getElementById('uc_sec_department_id').value = String(j.department_id);
    document.getElementById('uc_sec_slug').value = j.slug || '';
    document.getElementById('uc_sec_sort').value = j.sort_order > 0 ? String(j.sort_order) : '';
    document.getElementById('uc_sec_name_ar').value = j.name_ar || '';
    document.getElementById('uc_sec_name_en').value = j.name_en || '';
    document.getElementById('uc_sec_name_fil').value = j.name_fil || '';
    document.getElementById('uc_sec_name_hi').value = j.name_hi || '';
    document.getElementById('uc_sec_active').value = String(j.is_active === 0 ? 0 : 1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function saveUcSection() {
    if (ucSaving) return;
    const id = parseInt(document.getElementById('uc_sec_id').value || '0', 10) || 0;
    const payload = {
        department_id: parseInt(document.getElementById('uc_sec_department_id').value || '0', 10) || 0,
        slug: document.getElementById('uc_sec_slug').value.trim(),
        name_ar: document.getElementById('uc_sec_name_ar').value.trim(),
        name_en: document.getElementById('uc_sec_name_en').value.trim(),
        name_fil: document.getElementById('uc_sec_name_fil').value.trim(),
        name_hi: document.getElementById('uc_sec_name_hi').value.trim(),
        sort_order: parseSortPayload(document.getElementById('uc_sec_sort').value),
        is_active: parseInt(document.getElementById('uc_sec_active').value || '1', 10) ? 1 : 0
    };
    if (id > 0) payload.id = id;
    ucPost('/admin/api/unified_catalog/save_section.php', payload);
}

function resetUcCategory() {
    document.getElementById('uc_cat_id').value = '0';
    document.getElementById('uc_cat_section_id').value = '';
    document.getElementById('uc_cat_slug').value = '';
    document.getElementById('uc_cat_sort').value = '';
    document.getElementById('uc_cat_name_ar').value = '';
    document.getElementById('uc_cat_name_en').value = '';
    document.getElementById('uc_cat_name_fil').value = '';
    document.getElementById('uc_cat_name_hi').value = '';
    document.getElementById('uc_cat_active').value = '1';
}

function editUcCategory(j) {
    document.getElementById('uc_cat_id').value = String(j.id);
    ucEnsureOption('uc_cat_section_id', j.catalog_section_id, ucSectionOptions);
    document.getElementById('uc_cat_slug').value = j.slug || '';
    document.getElementById('uc_cat_sort').value = j.sort_order > 0 ? String(j.sort_order) : '';
    document.getElementById('uc_cat_name_ar').value = j.name_ar || '';
    document.getElementById('uc_cat_name_en').value = j.name_en || '';
    document.getElementById('uc_cat_name_fil').value = j.name_fil || '';
    document.getElementById('uc_cat_name_hi').value = j.name_hi || '';
    document.getElementById('uc_cat_active').value = String(j.is_active === 0 ? 0 : 1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function ucEnsureOption(selectId, val, pool) {
    const sel = document.getElementById(selectId);
    if (!sel || val == null) return;
    const v = String(val);
    const exists = Array.prototype.some.call(sel.options, function (o) { return o.value === v; });
    if (!exists && Array.isArray(pool)) {
        const hit = pool.find(function (x) { return String(x.id) === v; });
        const opt = document.createElement('option');
        opt.value = v;
        opt.textContent = hit && hit.label ? hit.label : ('#' + v);
        sel.insertBefore(opt, sel.options[1] || null);
    }
    sel.value = v;
}

function saveUcCategory() {
    if (ucSaving) return;
    const id = parseInt(document.getElementById('uc_cat_id').value || '0', 10) || 0;
    const payload = {
        catalog_section_id: parseInt(document.getElementById('uc_cat_section_id').value || '0', 10) || 0,
        slug: document.getElementById('uc_cat_slug').value.trim(),
        name_ar: document.getElementById('uc_cat_name_ar').value.trim(),
        name_en: document.getElementById('uc_cat_name_en').value.trim(),
        name_fil: document.getElementById('uc_cat_name_fil').value.trim(),
        name_hi: document.getElementById('uc_cat_name_hi').value.trim(),
        sort_order: parseSortPayload(document.getElementById('uc_cat_sort').value),
        is_active: parseInt(document.getElementById('uc_cat_active').value || '1', 10) ? 1 : 0
    };
    if (id > 0) payload.id = id;
    ucPost('/admin/api/unified_catalog/save_category.php', payload);
}

function resetUcSubcategory() {
    document.getElementById('uc_sub_id').value = '0';
    document.getElementById('uc_sub_category_id').value = '';
    document.getElementById('uc_sub_slug').value = '';
    document.getElementById('uc_sub_sort').value = '';
    document.getElementById('uc_sub_name_ar').value = '';
    document.getElementById('uc_sub_name_en').value = '';
    document.getElementById('uc_sub_name_fil').value = '';
    document.getElementById('uc_sub_name_hi').value = '';
    document.getElementById('uc_sub_active').value = '1';
}

function editUcSubcategory(j) {
    document.getElementById('uc_sub_id').value = String(j.id);
    ucEnsureOption('uc_sub_category_id', j.catalog_category_id, ucCategoryOptions);
    document.getElementById('uc_sub_slug').value = j.slug || '';
    document.getElementById('uc_sub_sort').value = j.sort_order > 0 ? String(j.sort_order) : '';
    document.getElementById('uc_sub_name_ar').value = j.name_ar || '';
    document.getElementById('uc_sub_name_en').value = j.name_en || '';
    document.getElementById('uc_sub_name_fil').value = j.name_fil || '';
    document.getElementById('uc_sub_name_hi').value = j.name_hi || '';
    document.getElementById('uc_sub_active').value = String(j.is_active === 0 ? 0 : 1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function saveUcSubcategory() {
    if (ucSaving) return;
    const id = parseInt(document.getElementById('uc_sub_id').value || '0', 10) || 0;
    const payload = {
        catalog_category_id: parseInt(document.getElementById('uc_sub_category_id').value || '0', 10) || 0,
        slug: document.getElementById('uc_sub_slug').value.trim(),
        name_ar: document.getElementById('uc_sub_name_ar').value.trim(),
        name_en: document.getElementById('uc_sub_name_en').value.trim(),
        name_fil: document.getElementById('uc_sub_name_fil').value.trim(),
        name_hi: document.getElementById('uc_sub_name_hi').value.trim(),
        sort_order: parseSortPayload(document.getElementById('uc_sub_sort').value),
        is_active: parseInt(document.getElementById('uc_sub_active').value || '1', 10) ? 1 : 0
    };
    if (id > 0) payload.id = id;
    ucPost('/admin/api/unified_catalog/save_subcategory.php', payload);
}

async function translateUc(which) {
    const map = {
        sec: { ar: 'uc_sec_name_ar', en: 'uc_sec_name_en', fil: 'uc_sec_name_fil', hi: 'uc_sec_name_hi' },
        cat: { ar: 'uc_cat_name_ar', en: 'uc_cat_name_en', fil: 'uc_cat_name_fil', hi: 'uc_cat_name_hi' },
        sub: { ar: 'uc_sub_name_ar', en: 'uc_sub_name_en', fil: 'uc_sub_name_fil', hi: 'uc_sub_name_hi' }
    };
    const m = map[which];
    if (!m) return;
    const forceFromArabic = true;
    try {
        const payload = {
            name_ar: document.getElementById(m.ar).value.trim(),
            name_en: forceFromArabic ? '' : document.getElementById(m.en).value.trim()
        };
        const res = await postJSON('/admin/api/translate/names.php', payload);
        if (!res || !res.success) {
            alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        const t = res.translations || {};
        if (t.name_en) document.getElementById(m.en).value = t.name_en;
        if (t.name_fil) document.getElementById(m.fil).value = t.name_fil;
        if (t.name_hi) document.getElementById(m.hi).value = t.name_hi;
    } catch (e) {
        alert('فشل طلب الترجمة');
    }
}

function scheduleUcTranslate(which) {
    const map = {
        sec: { ar: 'uc_sec_name_ar', en: 'uc_sec_name_en', fil: 'uc_sec_name_fil', hi: 'uc_sec_name_hi' },
        cat: { ar: 'uc_cat_name_ar', en: 'uc_cat_name_en', fil: 'uc_cat_name_fil', hi: 'uc_cat_name_hi' },
        sub: { ar: 'uc_sub_name_ar', en: 'uc_sub_name_en', fil: 'uc_sub_name_fil', hi: 'uc_sub_name_hi' }
    };
    const m = map[which];
    if (!m) return;
    const arEl = document.getElementById(m.ar);
    if (ucTimers[which]) clearTimeout(ucTimers[which]);
    ucTimers[which] = setTimeout(async function () {
        const nameAr = arEl.value.trim();
        if (!nameAr) {
            document.getElementById(m.en).value = '';
            document.getElementById(m.fil).value = '';
            document.getElementById(m.hi).value = '';
            return;
        }
        try {
            const res = await postJSON('/admin/api/translate/names.php', { name_ar: nameAr, name_en: '' });
            if (res && res.success) {
                const t = res.translations || {};
                if (t.name_en) document.getElementById(m.en).value = t.name_en;
                if (t.name_fil) document.getElementById(m.fil).value = t.name_fil;
                if (t.name_hi) document.getElementById(m.hi).value = t.name_hi;
            }
        } catch (e) { /* silent */ }
    }, 700);
}

function scheduleUcFromEn(which) {
    const map = {
        sec: { en: 'uc_sec_name_en', fil: 'uc_sec_name_fil', hi: 'uc_sec_name_hi' },
        cat: { en: 'uc_cat_name_en', fil: 'uc_cat_name_fil', hi: 'uc_cat_name_hi' },
        sub: { en: 'uc_sub_name_en', fil: 'uc_sub_name_fil', hi: 'uc_sub_name_hi' }
    };
    const m = map[which];
    if (!m) return;
    if (ucEnTimers[which]) clearTimeout(ucEnTimers[which]);
    ucEnTimers[which] = setTimeout(async function () {
        const nameEn = document.getElementById(m.en).value.trim();
        if (!nameEn) return;
        try {
            const res = await postJSON('/admin/api/translate/names.php', { name_ar: '', name_en: nameEn });
            if (res && res.success) {
                const t = res.translations || {};
                if (t.name_fil) document.getElementById(m.fil).value = t.name_fil;
                if (t.name_hi) document.getElementById(m.hi).value = t.name_hi;
            }
        } catch (e) { /* silent */ }
    }, 650);
}

(function () {
    document.addEventListener('click', function (ev) {
        var b = ev.target.closest('.uc-edit-sec');
        if (b && b.dataset.json) {
            try { editUcSection(JSON.parse(b.dataset.json)); } catch (e) { alert('تعذر قراءة البيانات'); }
            return;
        }
        b = ev.target.closest('.uc-edit-cat');
        if (b && b.dataset.json) {
            try { editUcCategory(JSON.parse(b.dataset.json)); } catch (e) { alert('تعذر قراءة البيانات'); }
            return;
        }
        b = ev.target.closest('.uc-edit-sub');
        if (b && b.dataset.json) {
            try { editUcSubcategory(JSON.parse(b.dataset.json)); } catch (e) { alert('تعذر قراءة البيانات'); }
        }
    });
    [['uc_sec_name_ar', 'sec'], ['uc_cat_name_ar', 'cat'], ['uc_sub_name_ar', 'sub']].forEach(function (pair) {
        var el = document.getElementById(pair[0]);
        if (el) el.addEventListener('input', function () { scheduleUcTranslate(pair[1]); });
    });
    [['uc_sec_name_en', 'sec'], ['uc_cat_name_en', 'cat'], ['uc_sub_name_en', 'sub']].forEach(function (pair) {
        var el = document.getElementById(pair[0]);
        if (el) el.addEventListener('input', function () { scheduleUcFromEn(pair[1]); });
    });
})();
</script>

<?php endif; ?>
