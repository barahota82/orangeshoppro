<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$hasDepartmentsTable = false;
$hasCategoryDepartment = false;
try {
    $hasDepartmentsTable = (bool) $pdo->query("SHOW TABLES LIKE 'departments'")->fetchColumn();
    if ($hasDepartmentsTable) {
        $colStmt = $pdo->query("SHOW COLUMNS FROM categories LIKE 'department_id'");
        $hasCategoryDepartment = (bool) $colStmt->fetch();
    }
} catch (Throwable $e) {
    $hasDepartmentsTable = false;
    $hasCategoryDepartment = false;
}

$categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
$departmentsForProducts = [];
if ($hasDepartmentsTable) {
    $departmentsForProducts = $pdo->query('SELECT * FROM departments ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
}

if ($hasDepartmentsTable && $hasCategoryDepartment) {
    $products = $pdo->query(
        'SELECT p.*, c.name_ar AS category_name, c.department_id AS category_department_id,
            d.name_ar AS department_name_ar, d.name_en AS department_name_en
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN departments d ON d.id = c.department_id
        ORDER BY p.sort_order ASC, p.id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
} else {
    $products = $pdo->query(
        'SELECT p.*, c.name_ar AS category_name, NULL AS category_department_id,
            NULL AS department_name_ar, NULL AS department_name_en
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        ORDER BY p.sort_order ASC, p.id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
}
$nextProductSort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM products')->fetchColumn();
if ($nextProductSort < 1) {
    $nextProductSort = 1;
}

$colorSelectCols = 'id, name_ar, name_en';
if (orange_table_has_column($pdo, 'color_dictionary', 'hex_code')) {
    $colorSelectCols .= ', hex_code';
}
$colors = $pdo->query(
    'SELECT ' . $colorSelectCols . ' FROM color_dictionary WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$families = $pdo->query('SELECT * FROM size_families WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
$famSizes = $pdo->query(
    'SELECT * FROM size_family_sizes WHERE is_active = 1 ORDER BY size_family_id ASC, sort_order ASC, id ASC'
)->fetchAll(PDO::FETCH_ASSOC);
$familiesOut = [];
foreach ($families as $f) {
    $fid = (int)$f['id'];
    $f['sizes'] = [];
    foreach ($famSizes as $sz) {
        if ((int)$sz['size_family_id'] === $fid) {
            $f['sizes'][] = $sz;
        }
    }
    $familiesOut[] = $f;
}

$hasSubcategoriesTable = false;
$subcategoriesForJs = [];
$hasProductSubcategoryColumn = false;
try {
    $hasSubcategoriesTable = (bool) $pdo->query("SHOW TABLES LIKE 'subcategories'")->fetchColumn();
    $hasProductSubcategoryColumn = orange_table_has_column($pdo, 'products', 'subcategory_id');
    if ($hasSubcategoriesTable && $hasProductSubcategoryColumn) {
        $subRows = $pdo->query(
            'SELECT id, category_id, name_ar, name_en FROM subcategories WHERE is_active = 1 ORDER BY category_id ASC, sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($subRows as $sr) {
            $subcategoriesForJs[] = [
                'id' => (int) $sr['id'],
                'category_id' => (int) $sr['category_id'],
                'label' => (string) ($sr['name_ar'] ?: $sr['name_en'] ?: ('#' . $sr['id'])),
            ];
        }
    }
} catch (Throwable $e) {
    $hasSubcategoriesTable = false;
    $subcategoriesForJs = [];
    $hasProductSubcategoryColumn = false;
}

/** @var array<int, array{dept_id: int, dept_label: string, ref: string}> */
$categoryCatalogMeta = [];
foreach ($categories as $cat) {
    $cid = (int) $cat['id'];
    $did = isset($cat['department_id']) && $cat['department_id'] !== null ? (int) $cat['department_id'] : 0;
    $deptLabel = '';
    if ($hasDepartmentsTable && $did > 0 && $departmentsForProducts !== []) {
        foreach ($departmentsForProducts as $d) {
            if ((int) $d['id'] === $did) {
                $deptLabel = (string) ($d['name_ar'] ?: $d['name_en'] ?: '');
                break;
            }
        }
    }
    $categoryCatalogMeta[$cid] = [
        'dept_id' => $did,
        'dept_label' => $deptLabel,
        'ref' => $did . '-' . $cid,
    ];
}
?>
<div class="page-title">
    <h1>المنتجات</h1>
</div>

<div class="card" style="margin-bottom:12px;">
    <p style="margin:0;">قبل إضافة منتج بمقاسات: عرّف <a href="/admin/index.php?page=size_families">عائلات المقاسات</a>.
        قبل الألوان: <a href="/admin/index.php?page=color_dictionary">قاموس الألوان</a>.</p>
</div>

<div class="card">
    <h3 id="productFormTitle">إضافة / تعديل منتج</h3>
    <p id="productEditHint" style="display:none;margin:0 0 12px;color:#555;font-size:14px;">تعديل البيانات الأساسية. الترتيب في المتجر من الجدول فقط (↑↓ ثم حفظ الترتيب). كميات الألوان والمقاسات من <a href="/admin/index.php?page=stock">المخزون</a>.</p>
    <form id="productForm">
        <input type="hidden" id="product_record_id" value="0">
        <p class="admin-product-form-intro">مسار العمل: <strong>البيانات الأساسية</strong> ← <strong>المقاسات والألوان</strong> ← <strong>الصور</strong> (صورة مرجعية للصنف) ← <strong>المتغيرات</strong> ثم «توليد المتغيرات». زر «حفظ المنتج» يطبّق كل التبويبات دفعة واحدة.</p>

        <div class="admin-product-tabs" role="tablist" aria-label="أقسام نموذج المنتج">
            <button type="button" class="admin-product-tab is-active" role="tab" id="productTabBtnBasic" aria-controls="productTabPanelBasic" aria-selected="true" data-product-tab="basic">البيانات الأساسية</button>
            <button type="button" class="admin-product-tab" role="tab" id="productTabBtnSizes" aria-controls="productTabPanelSizes" aria-selected="false" data-product-tab="sizes">المقاسات والألوان</button>
            <button type="button" class="admin-product-tab" role="tab" id="productTabBtnImages" aria-controls="productTabPanelImages" aria-selected="false" data-product-tab="images">الصور</button>
            <button type="button" class="admin-product-tab" role="tab" id="productTabBtnVariants" aria-controls="productTabPanelVariants" aria-selected="false" data-product-tab="variants">المتغيرات</button>
        </div>

        <div class="admin-product-tab-panels">
        <div id="productTabPanelBasic" class="admin-product-tab-panel is-active" role="tabpanel" aria-labelledby="productTabBtnBasic">
        <div class="admin-product-section">
        <h4 class="admin-product-subsection-title">البيانات الأساسية</h4>
        <div class="form-grid product-form-tab-basic-grid">
            <div class="product-form-basic-top3">
                <div class="form-grid-3 product-form-basic-top3-inner">
                    <div>
                        <label>الترتيب (في المتجر)</label>
                        <input type="text" id="product_sort_order" value="<?php echo (int)$nextProductSort; ?>" readonly tabindex="-1" autocomplete="off" inputmode="numeric" style="max-width:140px;background:#f1f3f5;cursor:default;color:#444;">
                        <small style="display:block;color:#666;margin-top:4px;">يُعرض للمراجعة فقط. الترتيب من ↑↓ في الجدول ثم «حفظ الترتيب».</small>
                    </div>
                    <div>
                        <label>حالة العرض</label>
                        <select id="product_is_active">
                            <option value="1">نشط</option>
                            <option value="0">مخفي</option>
                        </select>
                    </div>
                    <div>
                        <label>القسم (يُستنتج من الفئة)</label>
                        <div id="product_department_hint" style="padding:8px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;min-height:20px;">—</div>
                        <small style="display:block;color:#666;margin-top:4px;">مرجع قسم-فئة: <code id="product_dept_cat_ref" style="font-size:13px;">—</code></small>
                    </div>
                </div>
            </div>
            <div <?php echo ($hasSubcategoriesTable && $hasProductSubcategoryColumn) ? '' : 'style="grid-column:1/-1;"'; ?>>
                <label>الفئة (ضمن القسم)</label>
                <select id="category_id" required>
                    <option value="">اختر الفئة</option>
                    <?php if ($hasDepartmentsTable && $hasCategoryDepartment && $departmentsForProducts !== []): ?>
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
                        <?php foreach ($departmentsForProducts as $dep): ?>
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
                <?php if ($hasDepartmentsTable && $hasCategoryDepartment): ?>
                    <small style="display:block;color:#666;margin-top:4px;">كل فئة تحت قسمها لتفادي الخلط بين فئات متشابهة.</small>
                <?php elseif (!$hasDepartmentsTable || !$hasCategoryDepartment): ?>
                    <small style="display:block;color:#f59e0b;margin-top:4px;">لربط الفئات بالأقسام: من صفحة <a href="/admin/index.php?page=categories">الفئات</a>.</small>
                <?php endif; ?>
            </div>
            <?php if ($hasSubcategoriesTable && $hasProductSubcategoryColumn): ?>
            <div>
                <label for="subcategory_id">فئة فرعية (اختياري)</label>
                <select id="subcategory_id">
                    <option value="">— بدون —</option>
                </select>
                <small style="display:block;color:#666;margin-top:4px;">يُحدَّث حسب الفئة المختارة. أضف التصنيفات من
                    <a href="/admin/index.php?page=subcategories">فئات فرعية</a>.</small>
            </div>
            <?php endif; ?>
            <div>
                <label>اسم المنتج (العربي)</label>
                <input type="text" id="name" required>
            </div>
            <div>
                <label>English</label>
                <input type="text" id="name_en" required>
            </div>
            <div>
                <label>Filipino</label>
                <input type="text" id="name_fil" required>
            </div>
            <div>
                <label>Hindi</label>
                <input type="text" id="name_hi" required>
            </div>
            <div>
                <label>السعر</label>
                <input type="number" id="price" class="admin-inp-money" step="any" min="0" required inputmode="decimal" lang="en" dir="ltr">
            </div>
            <div>
                <label>التكلفة</label>
                <input type="number" id="cost" class="admin-inp-money" step="any" min="0" required inputmode="decimal" lang="en" dir="ltr">
            </div>
            <div style="grid-column:1/-1;">
                <label>الوصف (عربي)</label>
                <textarea id="description" rows="3"></textarea>
            </div>
            <div style="grid-column:1/-1;">
                <label>Description (English)</label>
                <textarea id="description_en" rows="3"></textarea>
            </div>
            <div style="grid-column:1/-1;">
                <label>Description (Filipino)</label>
                <textarea id="description_fil" rows="3"></textarea>
            </div>
            <div style="grid-column:1/-1;">
                <label>Description (Hindi)</label>
                <textarea id="description_hi" rows="3"></textarea>
            </div>
        </div>
        </div>
        </div>

        <div id="productTabPanelSizes" class="admin-product-tab-panel" role="tabpanel" aria-labelledby="productTabBtnSizes">
        <div class="admin-product-section">
        <h4 class="admin-product-subsection-title">المقاسات والألوان</h4>
        <div class="form-grid">
            <div>
                <label>له مقاسات؟</label>
                <select id="has_sizes" onchange="onHasFlagsChange()">
                    <option value="0">لا</option>
                    <option value="1">نعم</option>
                </select>
            </div>
            <div>
                <label>له ألوان؟</label>
                <select id="has_colors" onchange="onHasFlagsChange()">
                    <option value="0">لا</option>
                    <option value="1">نعم</option>
                </select>
            </div>
            <div>
                <label>عائلة المقاسات</label>
                <select id="size_family_id" disabled>
                    <option value="">—</option>
                    <?php foreach ($familiesOut as $f): ?>
                        <option value="<?php echo (int)$f['id']; ?>"><?php echo htmlspecialchars($f['name_ar'] ?: $f['name_en']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>دليل المقاس الاسترشادي (عرض)</label>
                <select id="sizing_guide_scope">
                    <option value="none">بدون</option>
                    <option value="upper">علوي</option>
                    <option value="lower">سفلي</option>
                    <option value="both">علوي وسفلي</option>
                </select>
            </div>
        </div>

        <div id="colorwaysSection" class="card admin-nested-panel" style="display:none;">
            <h4 class="admin-nested-panel__title">تركيبات اللون (أساسي / ثانوي اختياري)</h4>
            <div id="colorwaysBox"></div>
            <button type="button" class="btn-secondary" onclick="addColorwayRow()">+ صف لون</button>
        </div>

        <div id="productAdvancedSizingSlot" class="card admin-placeholder-panel">
            <h4 class="admin-product-subsection-title admin-placeholder-panel__title">ربط مقاس × لون وأوصاف المقاس (تطوير لاحق)</h4>
            <p class="admin-placeholder-panel__text">مساحة جاهزة لجدول فرعي: كل مقاس مع لونه وعائلة المقاسات ونصوص الوصف الخاصة بالمنتج.</p>
        </div>
        </div>
        </div>

        <div id="productTabPanelImages" class="admin-product-tab-panel" role="tabpanel" aria-labelledby="productTabBtnImages">
        <div class="admin-product-section">
        <h4 class="admin-product-subsection-title">الصور</h4>
        <div class="form-grid">
            <div style="grid-column:1/-1;">
                <label>الصورة الرئيسية — رفع ملف</label>
                <input type="hidden" id="main_image" value="">
                <input type="file" id="main_image_file" accept="image/jpeg,image/png,image/webp,image/gif">
                <button type="button" class="btn-secondary" style="margin-top:8px;" onclick="uploadMainProductImage()">رفع الصورة الرئيسية</button>
                <div style="margin-top:10px;">
                    <img id="main_image_preview" alt="" style="display:none;max-height:140px;border-radius:8px;border:1px solid #ddd;">
                </div>
                <p class="admin-product-image-hint">الصورة هنا <strong>مرجع للصنف</strong> (ما يظهر للعميل). المتغيرات (لون × مقاس) تُربط بنفس الصنف؛ صورة لكل لون يمكن إضافتها لاحقاً في التطوير. <strong>أول صورة تُرفع</strong> تُعتبر الرئيسية ما لم تغيّرها. يُثبت الربط عند «حفظ المنتج».</p>
            </div>
            <div style="grid-column:1/-1;">
                <label>صور إضافية للمعرض (عدة ملفات)</label>
                <input type="file" id="gallery_files" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                <button type="button" class="btn-secondary" style="margin-top:8px;" onclick="uploadGalleryProductImages()">رفع صور المعرض</button>
                <ul id="gallery_upload_list" style="margin:10px 0 0;padding-inline-start:20px;font-size:13px;"></ul>
            </div>
        </div>
        </div>
        </div>

        <div id="productTabPanelVariants" class="admin-product-tab-panel" role="tabpanel" aria-labelledby="productTabBtnVariants">
        <div class="admin-product-section">
        <h4 class="admin-product-subsection-title">المتغيرات</h4>
        <div id="variantsBox"></div>
        </div>
        </div>
        </div>

        <div class="admin-product-form-actions admin-product-form-actions--bar">
            <p class="admin-product-save-hint">حفظ واحد لكل الحقول أعلاه.</p>
            <div class="actions admin-product-form-actions__buttons">
                <button type="button" class="btn-secondary" id="btnProductTranslate" onclick="translateProductLocalesFromArabic()">ترجمة تلقائية من العربي</button>
                <button type="button" id="btnGenerateVariants" onclick="generateVariants()">توليد المتغيرات</button>
                <button type="button" class="btn-secondary" id="btnSaveProduct" onclick="saveProduct()">حفظ المنتج</button>
                <button type="button" class="btn-secondary" onclick="resetProductForm()">منتج جديد</button>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;">قائمة المنتجات</h3>
        <div class="actions">
            <button type="button" class="btn" onclick="saveProductsOrder()">حفظ الترتيب</button>
        </div>
    </div>
    <p style="margin:8px 0 12px;font-size:13px;color:#666;">الترتيب في المتجر: تصاعدي حسب «الترتيب» ثم رقم المنتج (مثل الفئات). استخدم ↑↓ ثم احفظ.</p>
    <?php if ($hasDepartmentsTable && $hasCategoryDepartment && $departmentsForProducts !== []): ?>
    <div class="form-grid" style="margin-bottom:12px;max-width:420px;">
        <div>
            <label for="productTableDeptFilter">تصفية الجدول حسب القسم</label>
            <select id="productTableDeptFilter">
                <option value="">كل الأقسام</option>
                <?php foreach ($departmentsForProducts as $dep): ?>
                    <option value="<?php echo (int) $dep['id']; ?>"><?php echo htmlspecialchars((string) ($dep['name_ar'] ?: $dep['name_en'])); ?></option>
                <?php endforeach; ?>
                <option value="0">بدون قسم</option>
            </select>
        </div>
    </div>
    <?php endif; ?>
    <div class="table-wrap cat-dep-list-wrap" data-list="products">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الترتيب</th>
                    <th>الاسم</th>
                    <th>القسم</th>
                    <th>الفئة</th>
                    <th title="رقم القسم من الفئة · رقم الفئة — للمطابقة مع المتجر دون لبس (مثلاً 1-3 وليس 13)">مرجع قسم-فئة</th>
                    <th>دليل مقاس</th>
                    <th>السعر</th>
                    <th>التكلفة</th>
                    <th>الحالة</th>
                    <th class="prod-ops-col">إجراءات</th>
                </tr>
            </thead>
            <tbody id="productsTbody">
                <?php foreach ($products as $p): ?>
                <?php
                $pDeptId = isset($p['category_department_id']) && $p['category_department_id'] !== null
                    ? (int) $p['category_department_id'] : 0;
                $pCatId = isset($p['category_id']) ? (int) $p['category_id'] : 0;
                $pDeptLabel = (string) ($p['department_name_ar'] ?: $p['department_name_en'] ?: '');
                if ($pDeptLabel === '') {
                    $pDeptLabel = '—';
                }
                $deptCatRef = $pDeptId . '-' . $pCatId;
                ?>
                <tr data-id="<?php echo (int)$p['id']; ?>" data-dept-id="<?php echo $pDeptId; ?>" data-category-id="<?php echo $pCatId; ?>">
                    <td><?php echo (int)$p['id']; ?></td>
                    <td><?php echo (int)($p['sort_order'] ?? 0); ?></td>
                    <td><?php echo htmlspecialchars($p['name']); ?></td>
                    <td><?php echo htmlspecialchars($pDeptLabel); ?><?php echo $pDeptId > 0 ? ' <span style="color:#64748b;font-size:12px;">(#' . $pDeptId . ')</span>' : ''; ?></td>
                    <td><?php echo htmlspecialchars($p['category_name'] ?: '-'); ?><?php echo $pCatId > 0 ? ' <span style="color:#64748b;font-size:12px;">(#' . $pCatId . ')</span>' : ''; ?></td>
                    <td><code style="font-size:13px;"><?php echo htmlspecialchars($deptCatRef, ENT_QUOTES, 'UTF-8'); ?></code></td>
                    <td><?php echo htmlspecialchars((string)($p['sizing_guide_scope'] ?? 'none')); ?></td>
                    <td><?php echo number_format((float)$p['price'], 2); ?></td>
                    <td><?php echo number_format((float)$p['cost'], 2); ?></td>
                    <td><?php echo (int)$p['is_active'] === 1 ? 'نشط' : 'مخفي'; ?></td>
                    <td class="prod-row-ops">
                        <div class="prod-ops-wrap">
                            <div class="prod-ops-arrows">
                                <button type="button" class="btn-secondary prod-btn-reorder" onclick="moveProductRow(this,'up')" aria-label="أعلى">↑</button>
                                <button type="button" class="btn-secondary prod-btn-reorder" onclick="moveProductRow(this,'down')" aria-label="أسفل">↓</button>
                            </div>
                            <div class="prod-ops-main">
                                <button type="button" class="btn-secondary" onclick="loadProductForEdit(<?php echo (int)$p['id']; ?>)">تعديل</button>
                                <button type="button" class="prod-btn-toggle" onclick="toggleProductActive(<?php echo (int)$p['id']; ?>, <?php echo (int)$p['is_active']; ?>)">
                                    <?php echo (int)$p['is_active'] === 1 ? 'إخفاء' : 'إظهار'; ?>
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
window.ORANGE_COLORS = <?php echo json_encode($colors, JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_FAMILIES = <?php echo json_encode($familiesOut, JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_SUBCATEGORIES = <?php echo json_encode($subcategoriesForJs, JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_CATEGORY_META = <?php echo json_encode($categoryCatalogMeta, JSON_UNESCAPED_UNICODE); ?>;
window.PRODUCT_EXTRA_IMAGES = [];
window.PRODUCT_NEXT_SORT = <?php echo (int)$nextProductSort; ?>;

const PRODUCT_MSG = {
    E_REORDER: 'بيانات الترتيب غير صحيحة',
    OK_REORDER: 'تم حفظ ترتيب المنتجات',
    OK_TOG: 'تم تحديث الحالة'
};

let productTranslateTimer = null;
let productEnTranslateTimer = null;
let productDescTranslateTimer = null;
let productDescEnTranslateTimer = null;

async function translateProductLocalesFromArabic() {
    await translateProductNames({ forceFromArabic: true, silent: false });
    await translateProductDescriptions({ forceFromArabic: true, silent: false });
}

async function translateProductNames(opts = {}) {
    const silent = !!opts.silent;
    const forceFromArabic = !!opts.forceFromArabic;
    try {
        const payload = {
            name_ar: document.getElementById('name').value.trim(),
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
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة من السيرفر');
    }
}

function scheduleProductAutoTranslate() {
    const nameAr = document.getElementById('name').value.trim();
    if (!nameAr) {
        document.getElementById('name_en').value = '';
        document.getElementById('name_fil').value = '';
        document.getElementById('name_hi').value = '';
        return;
    }
    clearTimeout(productTranslateTimer);
    productTranslateTimer = setTimeout(() => translateProductNames({ silent: true, forceFromArabic: true }), 600);
}

function scheduleProductTranslateFromEnglish() {
    const nameEn = document.getElementById('name_en').value.trim();
    if (!nameEn) {
        return;
    }
    clearTimeout(productEnTranslateTimer);
    productEnTranslateTimer = setTimeout(() => translateProductNames({ silent: true, forceFromArabic: false }), 550);
}

async function translateProductDescriptions(opts = {}) {
    const silent = !!opts.silent;
    const forceFromArabic = !!opts.forceFromArabic;
    try {
        const payload = {
            description_ar: document.getElementById('description').value.trim(),
            description_en: forceFromArabic ? '' : document.getElementById('description_en').value.trim()
        };
        const res = await postJSON('/admin/api/translate/descriptions.php', payload);
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل ترجمة الوصف');
            return;
        }
        const t = res.translations || {};
        if (t.description_en) document.getElementById('description_en').value = t.description_en;
        if (t.description_fil) document.getElementById('description_fil').value = t.description_fil;
        if (t.description_hi) document.getElementById('description_hi').value = t.description_hi;
    } catch (e) {
        if (!silent) alert('فشل طلب ترجمة الوصف من السيرفر');
    }
}

function scheduleProductDescriptionAutoTranslate() {
    const descAr = document.getElementById('description').value.trim();
    if (!descAr) {
        document.getElementById('description_en').value = '';
        document.getElementById('description_fil').value = '';
        document.getElementById('description_hi').value = '';
        return;
    }
    clearTimeout(productDescTranslateTimer);
    productDescTranslateTimer = setTimeout(() => translateProductDescriptions({ silent: true, forceFromArabic: true }), 800);
}

function scheduleProductDescriptionFromEnglish() {
    const descEn = document.getElementById('description_en').value.trim();
    if (!descEn) {
        return;
    }
    clearTimeout(productDescEnTranslateTimer);
    productDescEnTranslateTimer = setTimeout(() => translateProductDescriptions({ silent: true, forceFromArabic: false }), 750);
}

function rebuildSubcategoryOptions(preserveId) {
    const sel = document.getElementById('subcategory_id');
    if (!sel) {
        return;
    }
    const catId = parseInt(document.getElementById('category_id').value || '0', 10);
    let want;
    if (preserveId === undefined) {
        want = sel.value;
    } else if (preserveId === null || preserveId === '') {
        want = '';
    } else {
        want = String(preserveId);
    }
    sel.innerHTML = '<option value="">— بدون —</option>';
    (window.ORANGE_SUBCATEGORIES || []).forEach(function (s) {
        if (s.category_id !== catId) {
            return;
        }
        const o = document.createElement('option');
        o.value = String(s.id);
        o.textContent = s.label;
        sel.appendChild(o);
    });
    if (want && Array.from(sel.options).some(function (opt) { return opt.value === want; })) {
        sel.value = want;
    } else {
        sel.value = '';
    }
}

function assignMainImageFromGalleryIfEmpty() {
    const mainEl = document.getElementById('main_image');
    const list = window.PRODUCT_EXTRA_IMAGES || [];
    if (!mainEl || mainEl.value.trim() || !list.length) {
        return;
    }
    const fn = list[0];
    mainEl.value = fn;
    const prev = document.getElementById('main_image_preview');
    if (prev) {
        prev.src = '/uploads/products/' + fn;
        prev.style.display = 'block';
    }
}

function renderGalleryUploadList() {
    const ul = document.getElementById('gallery_upload_list');
    if (!ul) return;
    ul.innerHTML = '';
    (window.PRODUCT_EXTRA_IMAGES || []).forEach((name, i) => {
        const li = document.createElement('li');
        li.textContent = name + ' ';
        const rm = document.createElement('button');
        rm.type = 'button';
        rm.textContent = 'حذف';
        rm.className = 'btn-secondary';
        rm.style.marginInlineStart = '8px';
        rm.onclick = () => {
            const mainEl = document.getElementById('main_image');
            const removed = window.PRODUCT_EXTRA_IMAGES[i];
            const wasMain = mainEl && mainEl.value.trim() === removed;
            window.PRODUCT_EXTRA_IMAGES.splice(i, 1);
            if (wasMain) {
                mainEl.value = '';
                assignMainImageFromGalleryIfEmpty();
                if (!mainEl.value.trim()) {
                    const pv = document.getElementById('main_image_preview');
                    if (pv) {
                        pv.src = '';
                        pv.style.display = 'none';
                    }
                }
            }
            renderGalleryUploadList();
        };
        li.appendChild(rm);
        ul.appendChild(li);
    });
}

async function uploadMainProductImage() {
    const inp = document.getElementById('main_image_file');
    if (!inp || !inp.files || !inp.files[0]) {
        alert('اختر ملف صورة');
        return;
    }
    const fd = new FormData();
    fd.append('image', inp.files[0]);
    try {
        const r = await fetch('/admin/api/uploads/product-image.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (!j.success) {
            alert(j.message || 'فشل الرفع');
            return;
        }
        document.getElementById('main_image').value = j.filename;
        const prev = document.getElementById('main_image_preview');
        prev.src = '/uploads/products/' + j.filename;
        prev.style.display = 'block';
        inp.value = '';
    } catch (e) {
        alert('خطأ في الاتصال أثناء الرفع');
    }
}

function setProductFormEditMode(isEdit) {
    const hint = document.getElementById('productEditHint');
    const btnGen = document.getElementById('btnGenerateVariants');
    const sortEl = document.getElementById('product_sort_order');
    const title = document.getElementById('productFormTitle');
    const btnSave = document.getElementById('btnSaveProduct');
    if (hint) {
        hint.style.display = isEdit ? 'block' : 'none';
    }
    if (btnGen) {
        btnGen.style.display = isEdit ? 'none' : '';
    }
    if (sortEl) {
        sortEl.readOnly = true;
        sortEl.setAttribute('readonly', 'readonly');
        sortEl.tabIndex = -1;
        if (!isEdit) {
            sortEl.value = String(window.PRODUCT_NEXT_SORT || 1);
        }
    }
    if (title) {
        title.textContent = isEdit ? 'تعديل منتج' : 'إضافة / تعديل منتج';
    }
    if (btnSave) {
        btnSave.textContent = isEdit ? 'تحديث المنتج' : 'حفظ المنتج';
    }
}

function resetProductForm() {
    document.getElementById('product_record_id').value = '0';
    setProductFormEditMode(false);
    document.getElementById('name').value = '';
    document.getElementById('name_en').value = '';
    document.getElementById('name_fil').value = '';
    document.getElementById('name_hi').value = '';
    document.getElementById('description').value = '';
    document.getElementById('description_en').value = '';
    document.getElementById('description_fil').value = '';
    document.getElementById('description_hi').value = '';
    document.getElementById('category_id').selectedIndex = 0;
    rebuildSubcategoryOptions(null);
    updateProductCatalogHint();
    document.getElementById('price').value = '';
    document.getElementById('cost').value = '';
    document.getElementById('main_image').value = '';
    document.getElementById('main_image_file').value = '';
    const prev = document.getElementById('main_image_preview');
    if (prev) {
        prev.src = '';
        prev.style.display = 'none';
    }
    document.getElementById('has_sizes').value = '0';
    document.getElementById('has_colors').value = '0';
    document.getElementById('size_family_id').value = '';
    document.getElementById('sizing_guide_scope').value = 'none';
    document.getElementById('product_is_active').value = '1';
    document.getElementById('colorwaysBox').innerHTML = '';
    document.getElementById('variantsBox').innerHTML = '';
    window.PRODUCT_EXTRA_IMAGES = [];
    renderGalleryUploadList();
    onHasFlagsChange();
    productFormShowTab('basic');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function productFormShowTab(tab) {
    const map = {
        basic: 'productTabPanelBasic',
        sizes: 'productTabPanelSizes',
        images: 'productTabPanelImages',
        variants: 'productTabPanelVariants'
    };
    const key = map[tab] ? tab : 'basic';
    const panelId = map[key];
    document.querySelectorAll('.admin-product-tab-panel').forEach(function (el) {
        el.classList.toggle('is-active', el.id === panelId);
    });
    document.querySelectorAll('.admin-product-tab').forEach(function (btn) {
        const on = btn.getAttribute('data-product-tab') === key;
        btn.classList.toggle('is-active', on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
}

(function initProductFormTabs() {
    document.querySelectorAll('.admin-product-tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const t = btn.getAttribute('data-product-tab');
            if (t) {
                productFormShowTab(t);
            }
        });
    });
})();

async function loadProductForEdit(id) {
    try {
        const res = await fetch('/admin/api/products/get.php?id=' + encodeURIComponent(id));
        const j = await res.json();
        if (!j.success || !j.product) {
            alert(j.message || 'تعذر تحميل المنتج');
            return;
        }
        const p = j.product;
        document.getElementById('product_record_id').value = String(p.id);
        setProductFormEditMode(true);
        document.getElementById('product_sort_order').value = String(parseInt(p.sort_order, 10) || 0);
        document.getElementById('product_is_active').value = String(parseInt(p.is_active, 10) === 0 ? 0 : 1);
        document.getElementById('name').value = p.name || '';
        document.getElementById('name_en').value = p.name_en || '';
        document.getElementById('name_fil').value = p.name_fil || '';
        document.getElementById('name_hi').value = p.name_hi || '';
        document.getElementById('description').value = p.description || '';
        document.getElementById('description_en').value = p.description_en || '';
        document.getElementById('description_fil').value = p.description_fil || '';
        document.getElementById('description_hi').value = p.description_hi || '';
        document.getElementById('category_id').value = String(p.category_id || '');
        const sid = parseInt(p.subcategory_id, 10) || 0;
        rebuildSubcategoryOptions(sid > 0 ? sid : null);
        updateProductCatalogHint();
        document.getElementById('price').value = String(p.price != null ? p.price : '');
        document.getElementById('cost').value = String(p.cost != null ? p.cost : '');
        const extrasEarly = Array.isArray(p.extra_images) ? p.extra_images.slice() : [];
        let mainFn = (p.main_image || '').trim();
        if (!mainFn && extrasEarly.length) {
            mainFn = extrasEarly[0];
        }
        document.getElementById('main_image').value = mainFn;
        const prev = document.getElementById('main_image_preview');
        if (mainFn && prev) {
            prev.src = '/uploads/products/' + mainFn;
            prev.style.display = 'block';
        } else if (prev) {
            prev.src = '';
            prev.style.display = 'none';
        }
        document.getElementById('has_sizes').value = parseInt(p.has_sizes, 10) === 1 ? '1' : '0';
        document.getElementById('has_colors').value = parseInt(p.has_colors, 10) === 1 ? '1' : '0';
        document.getElementById('size_family_id').value = p.size_family_id ? String(p.size_family_id) : '';
        document.getElementById('sizing_guide_scope').value = p.sizing_guide_scope || 'none';
        document.getElementById('colorwaysBox').innerHTML = '';
        document.getElementById('variantsBox').innerHTML =
            '<p class="admin-variants-edit-note">المتغيرات والمخزون الحالي: من صفحة <a href="/admin/index.php?page=stock">المستودع / المخزون</a>.</p>';
        window.PRODUCT_EXTRA_IMAGES = extrasEarly;
        renderGalleryUploadList();
        onHasFlagsChange();
        const sortRO = document.getElementById('product_sort_order');
        if (sortRO) {
            sortRO.readOnly = true;
            sortRO.setAttribute('readonly', 'readonly');
            sortRO.tabIndex = -1;
        }
        productFormShowTab('basic');
        document.getElementById('productForm').scrollIntoView({ behavior: 'smooth' });
    } catch (e) {
        alert('فشل التحميل');
    }
}

function moveProductRow(btn, dir) {
    const tr = btn.closest('tr');
    if (!tr) {
        return;
    }
    const tbody = document.getElementById('productsTbody');
    if (!tbody) {
        return;
    }
    if (dir === 'up') {
        const prev = tr.previousElementSibling;
        if (prev) {
            tbody.insertBefore(tr, prev);
        }
    } else {
        const next = tr.nextElementSibling;
        if (next) {
            tbody.insertBefore(next, tr);
        }
    }
}

async function saveProductsOrder() {
    const tbody = document.getElementById('productsTbody');
    if (!tbody) {
        return;
    }
    const ids = Array.from(tbody.querySelectorAll('tr[data-id]'))
        .map((tr) => parseInt(tr.getAttribute('data-id') || '0', 10))
        .filter((id) => id > 0);
    const res = await postJSON('/admin/api/products/reorder-save.php', { ordered_ids: ids });
    const rawMsg = res.message || (res.success ? 'OK_REORDER' : 'فشل');
    alert(PRODUCT_MSG[rawMsg] || rawMsg);
    if (res.success) {
        location.reload();
    }
}

async function toggleProductActive(id, isActive) {
    const res = await postJSON('/admin/api/products/toggle.php', {
        id: id,
        is_active: isActive ? 0 : 1,
    });
    const rawMsg = res.message || (res.success ? 'OK_TOG' : 'فشل');
    alert(PRODUCT_MSG[rawMsg] || rawMsg);
    if (res.success) {
        location.reload();
    }
}

async function uploadGalleryProductImages() {
    const inp = document.getElementById('gallery_files');
    if (!inp || !inp.files || !inp.files.length) {
        alert('اختر ملفات الصور');
        return;
    }
    for (let i = 0; i < inp.files.length; i++) {
        const fd = new FormData();
        fd.append('image', inp.files[i]);
        try {
            const r = await fetch('/admin/api/uploads/product-image.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const j = await r.json();
            if (j.success && j.filename) {
                window.PRODUCT_EXTRA_IMAGES.push(j.filename);
            } else if (j.message) {
                alert(j.message);
            }
        } catch (e) {
            alert('خطأ في الاتصال أثناء الرفع');
            return;
        }
    }
    inp.value = '';
    assignMainImageFromGalleryIfEmpty();
    renderGalleryUploadList();
}

function onHasFlagsChange() {
    const hs = document.getElementById('has_sizes').value === '1';
    const hc = document.getElementById('has_colors').value === '1';
    document.getElementById('size_family_id').disabled = !hs;
    document.getElementById('colorwaysSection').style.display = hc ? 'block' : 'none';
    if (hc && !document.querySelector('#colorwaysBox .cw-row')) {
        addColorwayRow();
    }
}

function colorOptionsHtml() {
    let h = '<option value="0">—</option>';
    (window.ORANGE_COLORS || []).forEach(c => {
        const t = (c.name_ar || c.name_en || '').replace(/</g,'');
        h += `<option value="${c.id}">${t}</option>`;
    });
    return h;
}

function adminColorSwatchHtml(col) {
    if (!col) {
        return '';
    }
    let hex = String(col.hex_code || '').trim();
    if (hex && hex.charAt(0) !== '#') {
        hex = '#' + hex;
    }
    const valid = /^#[0-9A-Fa-f]{3,8}$/.test(hex);
    const bg = valid ? hex : '#e5e7eb';
    const name = String(col.name_ar || col.name_en || '').replace(/"/g, '&quot;');
    return '<span class="admin-color-swatch" style="background:' + bg + '" title="' + name + '"></span>';
}

function adminVariantReferenceThumbHtml() {
    const mainImg = (document.getElementById('main_image') && document.getElementById('main_image').value || '').trim();
    if (!mainImg) {
        return '<span class="admin-variant-thumb-placeholder" title="ارفع صورة من تبويب الصور">؟</span>';
    }
    const safe = mainImg.replace(/"/g, '');
    return '<img src="/uploads/products/' + safe + '" alt="" class="admin-variant-thumb" width="48" height="48" loading="lazy">';
}

function addColorwayRow() {
    const box = document.getElementById('colorwaysBox');
    const div = document.createElement('div');
    div.className = 'cw-row form-grid cw-row--compact';
    div.innerHTML = `
        <div><label>أساسي</label><select class="cw-p">${colorOptionsHtml()}</select></div>
        <div><label>ثانوي (اختياري)</label><select class="cw-s">${colorOptionsHtml()}</select></div>
    `;
    box.appendChild(div);
}

function sizesForFamily(fid) {
    const fam = (window.ORANGE_FAMILIES || []).find(f => String(f.id) === String(fid));
    return fam && fam.sizes ? fam.sizes : [];
}

function generateVariants() {
    const hasC = document.getElementById('has_colors').value === '1';
    const hasS = document.getElementById('has_sizes').value === '1';
    const famId = parseInt(document.getElementById('size_family_id').value, 10) || 0;
    const box = document.getElementById('variantsBox');

    if (hasS && !famId) {
        alert('اختر عائلة مقاسات');
        return;
    }
    if (hasC) {
        const rows = document.querySelectorAll('#colorwaysBox .cw-row');
        if (!rows.length) {
            alert('أضف صف لون واحد على الأقل');
            return;
        }
    }

    let sizes = [{ id: 0, label_ar: '', label_en: '' }];
    if (hasS) {
        sizes = sizesForFamily(famId);
        if (!sizes.length) {
            alert('لا توجد مقاسات في العائلة المختارة');
            return;
        }
    }

    let combos = [];
    if (!hasC && !hasS) {
        combos.push({ primary_color_id: 0, secondary_color_id: 0, size_family_size_id: 0, stock: 0 });
    } else if (hasC && hasS) {
        document.querySelectorAll('#colorwaysBox .cw-row').forEach(row => {
            const p = parseInt(row.querySelector('.cw-p').value, 10) || 0;
            const s = parseInt(row.querySelector('.cw-s').value, 10) || 0;
            if (!p) {
                return;
            }
            sizes.forEach(sz => {
                combos.push({ primary_color_id: p, secondary_color_id: s, size_family_size_id: sz.id, stock: 0 });
            });
        });
    } else if (hasC && !hasS) {
        document.querySelectorAll('#colorwaysBox .cw-row').forEach(row => {
            const p = parseInt(row.querySelector('.cw-p').value, 10) || 0;
            const s = parseInt(row.querySelector('.cw-s').value, 10) || 0;
            if (!p) return;
            combos.push({ primary_color_id: p, secondary_color_id: s, size_family_size_id: 0, stock: 0 });
        });
    } else if (!hasC && hasS) {
        sizes.forEach(sz => {
            combos.push({ primary_color_id: 0, secondary_color_id: 0, size_family_size_id: sz.id, stock: 0 });
        });
    }

    if (!combos.length) {
        alert('لا توجد تركيبات');
        return;
    }

    const thumbCell = adminVariantReferenceThumbHtml();
    let html = '<p class="admin-variants-lead">كل صف يمثل <strong>نفس الصنف</strong> مع دمج لون × مقاس. عمود «صورة المرجع» يعكس الصورة الرئيسية الحالية (من تبويب الصور).</p>';
    html += '<div class="table-wrap admin-table-wrap-elevated"><table class="admin-table admin-variants-matrix"><thead><tr>';
    html += '<th class="col-ref-img">صورة المرجع</th><th>اللون</th><th>المقاس</th><th class="col-stock">مخزون أولي</th>';
    html += '</tr></thead><tbody>';
    combos.forEach((c, idx) => {
        const sz = sizes.find(x => String(x.id) === String(c.size_family_size_id));
        const szLabel = sz ? (sz.label_ar || sz.label_en || ('#' + sz.id)) : '-';
        const p = (window.ORANGE_COLORS || []).find(x => String(x.id) === String(c.primary_color_id));
        const s = (window.ORANGE_COLORS || []).find(x => String(x.id) === String(c.secondary_color_id));
        let colorLabel = '';
        if (p) colorLabel += (p.name_ar || p.name_en);
        if (s) colorLabel += (colorLabel ? ' + ' : '') + (s.name_ar || s.name_en);
        if (!colorLabel) colorLabel = '—';
        const dots = [adminColorSwatchHtml(p), adminColorSwatchHtml(s && (!p || String(s.id) !== String(p.id)) ? s : null)].filter(Boolean).join('');
        const colorCell = '<div class="admin-variant-color-cell">' + dots + '<span class="admin-variant-color-names">' + colorLabel + '</span></div>' +
            `<input type="hidden" class="v-p" value="${c.primary_color_id}"><input type="hidden" class="v-s" value="${c.secondary_color_id}">`;
        html += `<tr>
            <td class="td-ref-img">${thumbCell}</td>
            <td>${colorCell}</td>
            <td><span class="admin-variant-size-pill">${szLabel}</span><input type="hidden" class="v-zid" value="${c.size_family_size_id}"></td>
            <td class="td-stock"><input type="number" class="v-stock admin-input-narrow" min="0" value="0" data-idx="${idx}"></td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    box.innerHTML = html;
    productFormShowTab('variants');
}

async function saveProduct() {
    const nameFields = [
        { id: 'name', label: 'الاسم العربي' },
        { id: 'name_en', label: 'English' },
        { id: 'name_fil', label: 'Filipino' },
        { id: 'name_hi', label: 'Hindi' }
    ];
    for (let i = 0; i < nameFields.length; i++) {
        const f = nameFields[i];
        if (!document.getElementById(f.id).value.trim()) {
            productFormShowTab('basic');
            alert('يجب إضافة خانة ' + f.label + ' قبل الحفظ');
            return;
        }
    }

    assignMainImageFromGalleryIfEmpty();
    const mainVal = document.getElementById('main_image').value.trim();
    const hasAnyImage = mainVal || (window.PRODUCT_EXTRA_IMAGES && window.PRODUCT_EXTRA_IMAGES.length);
    if (!hasAnyImage) {
        productFormShowTab('images');
        alert('ارفع صورة واحدة على الأقل قبل الحفظ');
        return;
    }

    const recordId = parseInt(document.getElementById('product_record_id').value || '0', 10);

    if (recordId > 0) {
        const payload = {
            id: recordId,
            name: document.getElementById('name').value.trim(),
            name_en: document.getElementById('name_en').value.trim(),
            name_fil: document.getElementById('name_fil').value.trim(),
            name_hi: document.getElementById('name_hi').value.trim(),
            description: document.getElementById('description').value.trim(),
            description_en: document.getElementById('description_en').value.trim(),
            description_fil: document.getElementById('description_fil').value.trim(),
            description_hi: document.getElementById('description_hi').value.trim(),
            category_id: parseInt(document.getElementById('category_id').value, 10),
            price: parseFloat(document.getElementById('price').value || '0'),
            cost: parseFloat(document.getElementById('cost').value || '0'),
            main_image: document.getElementById('main_image').value.trim() || (window.PRODUCT_EXTRA_IMAGES && window.PRODUCT_EXTRA_IMAGES[0] ? window.PRODUCT_EXTRA_IMAGES[0] : ''),
            has_sizes: parseInt(document.getElementById('has_sizes').value, 10),
            has_colors: parseInt(document.getElementById('has_colors').value, 10),
            size_family_id: parseInt(document.getElementById('size_family_id').value, 10) || 0,
            sizing_guide_scope: document.getElementById('sizing_guide_scope').value,
            sort_order: parseInt(document.getElementById('product_sort_order').value || '0', 10),
            is_active: parseInt(document.getElementById('product_is_active').value, 10)
        };
        const subEl = document.getElementById('subcategory_id');
        if (subEl) {
            const sv = subEl.value.trim();
            payload.subcategory_id = sv === '' ? null : parseInt(sv, 10);
        }
        payload.extra_images = window.PRODUCT_EXTRA_IMAGES || [];
        const res = await postJSON('/admin/api/products/update.php', payload);
        alert(res.message || (res.success ? 'تم التحديث' : 'فشل'));
        if (res.success) {
            location.reload();
        }
        return;
    }

    const rows = Array.from(document.querySelectorAll('#variantsBox tbody tr'));
    if (!rows.length) {
        productFormShowTab('variants');
        alert('ولّد المتغيرات أولاً');
        return;
    }

    const variants = rows.map((tr) => ({
        primary_color_id: parseInt(tr.querySelector('.v-p').value, 10) || 0,
        secondary_color_id: parseInt(tr.querySelector('.v-s').value, 10) || 0,
        size_family_size_id: parseInt(tr.querySelector('.v-zid').value, 10) || 0,
        stock_quantity: parseInt(tr.querySelector('.v-stock').value || '0', 10)
    }));

    const payload = {
        name: document.getElementById('name').value.trim(),
        name_en: document.getElementById('name_en').value.trim(),
        name_fil: document.getElementById('name_fil').value.trim(),
        name_hi: document.getElementById('name_hi').value.trim(),
        description: document.getElementById('description').value.trim(),
        description_en: document.getElementById('description_en').value.trim(),
        description_fil: document.getElementById('description_fil').value.trim(),
        description_hi: document.getElementById('description_hi').value.trim(),
        category_id: parseInt(document.getElementById('category_id').value, 10),
        price: parseFloat(document.getElementById('price').value || '0'),
        cost: parseFloat(document.getElementById('cost').value || '0'),
        main_image: document.getElementById('main_image').value.trim() || (window.PRODUCT_EXTRA_IMAGES && window.PRODUCT_EXTRA_IMAGES[0] ? window.PRODUCT_EXTRA_IMAGES[0] : ''),
        has_sizes: parseInt(document.getElementById('has_sizes').value, 10),
        has_colors: parseInt(document.getElementById('has_colors').value, 10),
        size_family_id: parseInt(document.getElementById('size_family_id').value, 10) || 0,
        sizing_guide_scope: document.getElementById('sizing_guide_scope').value,
        extra_images: window.PRODUCT_EXTRA_IMAGES || [],
        variants
    };
    const subElNew = document.getElementById('subcategory_id');
    if (subElNew) {
        const sv2 = subElNew.value.trim();
        payload.subcategory_id = sv2 === '' ? null : parseInt(sv2, 10);
    }

    const res = await postJSON('/admin/api/products/create.php', payload);
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
    if (res.success) {
        location.reload();
    }
}

document.getElementById('name').addEventListener('input', scheduleProductAutoTranslate);
document.getElementById('name_en').addEventListener('input', scheduleProductTranslateFromEnglish);
document.getElementById('description').addEventListener('input', scheduleProductDescriptionAutoTranslate);
document.getElementById('description_en').addEventListener('input', scheduleProductDescriptionFromEnglish);

function updateProductCatalogHint() {
    const hint = document.getElementById('product_department_hint');
    const refEl = document.getElementById('product_dept_cat_ref');
    const sel = document.getElementById('category_id');
    if (!hint || !refEl || !sel) {
        return;
    }
    const id = parseInt(sel.value, 10) || 0;
    const meta = window.ORANGE_CATEGORY_META && window.ORANGE_CATEGORY_META[id];
    if (!meta) {
        hint.textContent = '—';
        refEl.textContent = '—';
        return;
    }
    if (meta.dept_id > 0 && meta.dept_label) {
        hint.textContent = meta.dept_label + ' (#' + meta.dept_id + ')';
    } else if (meta.dept_id > 0) {
        hint.textContent = 'قسم #' + meta.dept_id;
    } else {
        hint.textContent = 'بدون قسم — عيّن القسم من صفحة الفئات إن لزم';
    }
    refEl.textContent = meta.ref;
}

const categorySelectEl = document.getElementById('category_id');
if (categorySelectEl) {
    categorySelectEl.addEventListener('change', function () {
        rebuildSubcategoryOptions(null);
        updateProductCatalogHint();
    });
}
rebuildSubcategoryOptions(null);
updateProductCatalogHint();

setProductFormEditMode(false);
onHasFlagsChange();

(function () {
    const style = document.createElement('style');
    style.textContent = `
        .cat-dep-list-wrap[data-list="products"]{
            overflow-x:auto;
            max-width:100%;
            -webkit-overflow-scrolling:touch;
        }
        .cat-dep-list-wrap[data-list="products"] > table{
            min-width:1180px;
            width:100%;
            border-collapse:collapse;
            table-layout:fixed;
        }
        .cat-dep-list-wrap[data-list="products"] > table th,
        .cat-dep-list-wrap[data-list="products"] > table td{
            vertical-align:middle;
        }
        .cat-dep-list-wrap[data-list="products"] table .prod-ops-col,
        .cat-dep-list-wrap[data-list="products"] table .prod-row-ops{
            width:200px !important;
            min-width:200px !important;
            max-width:200px !important;
            box-sizing:border-box !important;
            text-align:center !important;
            vertical-align:middle !important;
            padding:6px 8px !important;
        }
        .prod-ops-wrap{
            display:grid;
            grid-template-columns:38px minmax(0,1fr);
            gap:8px;
            align-items:center;
            margin:0 auto;
            max-width:100%;
            direction:rtl;
        }
        .prod-ops-arrows{
            display:flex;
            flex-direction:column;
            gap:4px;
            align-items:center;
            justify-content:center;
        }
        .cat-dep-list-wrap[data-list="products"] .prod-ops-wrap button.prod-btn-reorder{
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
        .prod-ops-main{
            display:flex;
            flex-direction:column;
            gap:5px;
            min-width:0;
        }
        .cat-dep-list-wrap[data-list="products"] .prod-ops-main .btn-secondary,
        .cat-dep-list-wrap[data-list="products"] .prod-ops-main .prod-btn-toggle{
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
})();

(function () {
    const sel = document.getElementById('productTableDeptFilter');
    const tbody = document.getElementById('productsTbody');
    if (!sel || !tbody) {
        return;
    }
    sel.addEventListener('change', function () {
        const v = String(sel.value);
        tbody.querySelectorAll('tr[data-id]').forEach(function (tr) {
            const did = tr.getAttribute('data-dept-id') || '0';
            if (v === '') {
                tr.style.display = '';
                return;
            }
            tr.style.display = did === v ? '' : 'none';
        });
    });
})();
</script>
