<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/../../includes/catalog_unified_product_helpers.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$catalogNavUnified = orange_catalog_nav_use_unified($pdo);

$productTypesForForm = [];
if (orange_table_exists($pdo, 'product_types')) {
    try {
        $ptTreeOrder = orange_table_exists($pdo, 'catalog_subcategories')
            && orange_table_exists($pdo, 'catalog_categories')
            && orange_table_exists($pdo, 'catalog_sections')
            && orange_table_exists($pdo, 'departments');
        if ($ptTreeOrder) {
            $productTypesForForm = $pdo->query(
                'SELECT pt.id, pt.slug, pt.name_ar, pt.name_en,
                        pt.expected_commercial_kind_key, pt.expected_sizing_category_key,
                        d.id AS department_id, d.name_ar AS department_name_ar, d.name_en AS department_name_en
                 FROM product_types pt
                 INNER JOIN catalog_subcategories csub ON csub.id = pt.catalog_subcategory_id
                 INNER JOIN catalog_categories cc ON cc.id = csub.catalog_category_id
                 INNER JOIN catalog_sections cs ON cs.id = cc.catalog_section_id
                 INNER JOIN departments d ON d.id = cs.department_id
                 WHERE pt.is_active = 1
                 ORDER BY d.sort_order ASC, d.id ASC, cs.sort_order ASC, cs.id ASC,
                          cc.sort_order ASC, cc.id ASC, csub.sort_order ASC, csub.id ASC,
                          pt.sort_order ASC, pt.id ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $productTypesForForm = $pdo->query(
                'SELECT id, slug, name_ar, name_en,
                        expected_commercial_kind_key, expected_sizing_category_key,
                        NULL AS department_id, NULL AS department_name_ar, NULL AS department_name_en
                 FROM product_types WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $productTypesForForm = [];
    }
}
$productTypeDepartmentsForForm = [];
/** عند وجود أقسام مرتبطة بأنواع المنتج: خطوة «القسم أولاً» ثم أنواع القسم فقط في الواجهة. */
$orangeProductTypeDeptStepEnabled = false;
foreach ($productTypesForForm as $ptRow) {
    if (!is_array($ptRow)) {
        continue;
    }
    $depId = (int) ($ptRow['department_id'] ?? 0);
    if ($depId <= 0 || isset($productTypeDepartmentsForForm[$depId])) {
        continue;
    }
    $depLabel = trim((string) (($ptRow['department_name_ar'] ?? '') ?: ($ptRow['department_name_en'] ?? '')));
    if ($depLabel === '') {
        $depLabel = 'قسم #' . $depId;
    }
    $productTypeDepartmentsForForm[$depId] = [
        'id' => $depId,
        'label' => $depLabel,
    ];
}
$orangeProductTypeDeptStepEnabled = $productTypeDepartmentsForForm !== [];

$productTypeTrailsForJs = [];
if ($catalogNavUnified && orange_table_exists($pdo, 'product_types') && orange_table_exists($pdo, 'catalog_sections')
    && orange_table_exists($pdo, 'departments')) {
    try {
        $trailRows = $pdo->query(
            'SELECT pt.id,
                CONCAT_WS(
                    \' ← \',
                    NULLIF(TRIM(d.name_ar), \'\'),
                    NULLIF(TRIM(cs.name_ar), \'\'),
                    NULLIF(TRIM(ucc.name_ar), \'\'),
                    NULLIF(TRIM(ucs.name_ar), \'\'),
                    NULLIF(TRIM(pt.name_ar), \'\')
                ) AS trail_ar
             FROM product_types pt
             INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id
             INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id
             INNER JOIN catalog_sections cs ON cs.id = ucc.catalog_section_id
             INNER JOIN departments d ON d.id = cs.department_id
             WHERE pt.is_active = 1'
        );
        foreach (($trailRows ? $trailRows->fetchAll(PDO::FETCH_ASSOC) : []) ?: [] as $tr) {
            if (!is_array($tr)) {
                continue;
            }
            $tid = (int) ($tr['id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            $productTypeTrailsForJs[$tid] = [
                'trail_ar' => trim((string) ($tr['trail_ar'] ?? '')),
            ];
        }
    } catch (Throwable $e) {
        $productTypeTrailsForJs = [];
    }
}

$catalogAttributesActive = [];
if (orange_table_exists($pdo, 'catalog_attributes')) {
    try {
        $catalogAttributesActive = $pdo->query(
            'SELECT id, attribute_key, label_ar, label_en, input_kind FROM catalog_attributes WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $catalogAttributesActive = [];
    }
}

$catalogAttrOptionsByAttrId = [];
if ($catalogAttributesActive !== [] && orange_table_exists($pdo, 'catalog_attribute_options')) {
    try {
        $attrIds = [];
        foreach ($catalogAttributesActive as $caRow) {
            if (! is_array($caRow)) {
                continue;
            }
            $aid = (int) ($caRow['id'] ?? 0);
            if ($aid > 0) {
                $attrIds[] = $aid;
            }
        }
        $attrIds = array_values(array_unique($attrIds));
        if ($attrIds !== []) {
            $placeholders = implode(',', array_fill(0, count($attrIds), '?'));
            $stOp = $pdo->prepare(
                "SELECT catalog_attribute_id, label_ar, label_en, sort_order
                 FROM catalog_attribute_options
                 WHERE is_active = 1 AND catalog_attribute_id IN ($placeholders)
                 ORDER BY catalog_attribute_id ASC, sort_order ASC, id ASC"
            );
            $stOp->execute($attrIds);
            $opRows = $stOp->fetchAll(PDO::FETCH_ASSOC);
            foreach (is_array($opRows) ? $opRows : [] as $or) {
                if (! is_array($or)) {
                    continue;
                }
                $aid = (int) ($or['catalog_attribute_id'] ?? 0);
                if ($aid <= 0) {
                    continue;
                }
                $catalogAttrOptionsByAttrId[$aid][] = $or;
            }
        }
    } catch (Throwable $e) {
        $catalogAttrOptionsByAttrId = [];
    }
}

$catalogAttrDefsForJs = [];
foreach ($catalogAttributesActive as $caRow) {
    if (! is_array($caRow)) {
        continue;
    }
    $caid = (int) ($caRow['id'] ?? 0);
    if ($caid <= 0) {
        continue;
    }
    $label = trim((string) (($caRow['label_ar'] ?: $caRow['label_en']) ?: ($caRow['attribute_key'] ?? '')));
    $optOut = [];
    foreach ($catalogAttrOptionsByAttrId[$caid] ?? [] as $opt) {
        if (! is_array($opt)) {
            continue;
        }
        $optAr = trim((string) ($opt['label_ar'] ?? ''));
        if ($optAr === '') {
            continue;
        }
        $optEn = trim((string) ($opt['label_en'] ?? ''));
        $optOut[] = [
            'v' => $optAr,
            'd' => $optAr . ($optEn !== '' ? ' / ' . $optEn : ''),
        ];
    }
    $catalogAttrDefsForJs[] = [
        'id' => $caid,
        'label' => $label !== '' ? $label : ('#' . $caid),
        'key' => (string) ($caRow['attribute_key'] ?? ''),
        'inputKind' => (string) ($caRow['input_kind'] ?? 'text_short'),
        'options' => $optOut,
    ];
}

$hasDepartmentsTable = false;
$hasCategoryDepartment = false;
$departmentsForProducts = [];
$hasProductTypesTable = orange_table_exists($pdo, 'product_types');

$unifiedProductList = $catalogNavUnified
    && $hasProductTypesTable
    && orange_table_has_column($pdo, 'products', 'product_type_id')
    && orange_table_exists($pdo, 'catalog_subcategories')
    && orange_table_exists($pdo, 'catalog_categories')
    && orange_table_exists($pdo, 'catalog_sections')
    && orange_table_has_column($pdo, 'catalog_categories', 'catalog_section_id');

if ($unifiedProductList) {
    $catJ = orange_catalog_admin_sql_join_product_category_display($pdo, 'p', 'pt');
    $products = $pdo->query(
        'SELECT p.*, c.name_ar AS category_name, c.id AS catalog_category_display_id,
            cs_pl.department_id AS category_department_id,
            d.name_ar AS department_name_ar, d.name_en AS department_name_en,
            pt.name_ar AS pt_name_ar_join, pt.name_en AS pt_name_en_join, pt.slug AS pt_slug_join
        FROM products p
        LEFT JOIN product_types pt ON pt.id = p.product_type_id'
        . $catJ . '
        LEFT JOIN catalog_sections cs_pl ON cs_pl.id = c.catalog_section_id
        LEFT JOIN departments d ON d.id = cs_pl.department_id
        ORDER BY p.sort_order ASC, p.id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
} elseif (
    $catalogNavUnified
    && $hasProductTypesTable
    && orange_table_has_column($pdo, 'products', 'product_type_id')
    && orange_table_exists($pdo, 'catalog_subcategories')
    && orange_table_exists($pdo, 'catalog_categories')
) {
    /* شجرة موحّدة مفعّلة لكن غياب قسم/عمود قسم — عرض فئة الكتالوج بدون ربط قسم */
    $catJ = orange_catalog_admin_sql_join_product_category_display($pdo, 'p', 'pt');
    $products = $pdo->query(
        'SELECT p.*, c.name_ar AS category_name, c.id AS catalog_category_display_id,
            NULL AS category_department_id, NULL AS department_name_ar, NULL AS department_name_en,
            pt.name_ar AS pt_name_ar_join, pt.name_en AS pt_name_en_join, pt.slug AS pt_slug_join
        FROM products p
        LEFT JOIN product_types pt ON pt.id = p.product_type_id'
        . $catJ . '
        ORDER BY p.sort_order ASC, p.id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
} else {
    /* غير مسار القائمة الكامل الموحّد: لا استعلام على جداول categories/subcategories التراثية */
    if ($hasProductTypesTable) {
        $products = $pdo->query(
            'SELECT p.*, NULL AS category_name, NULL AS catalog_category_display_id,
            NULL AS category_department_id, NULL AS department_name_ar, NULL AS department_name_en,
            pt.name_ar AS pt_name_ar_join, pt.name_en AS pt_name_en_join, pt.slug AS pt_slug_join
            FROM products p
            LEFT JOIN product_types pt ON pt.id = p.product_type_id
            ORDER BY p.sort_order ASC, p.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $products = $pdo->query(
            'SELECT p.*, NULL AS category_name, NULL AS catalog_category_display_id,
            NULL AS category_department_id, NULL AS department_name_ar, NULL AS department_name_en
            FROM products p
            ORDER BY p.sort_order ASC, p.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}

try {
    $hasDepartmentsTable = (bool) $pdo->query("SHOW TABLES LIKE 'departments'")->fetchColumn();
    if ($hasDepartmentsTable) {
        $departmentsForProducts = $pdo->query('SELECT * FROM departments ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $hasDepartmentsTable = false;
    $departmentsForProducts = [];
}
$hasCategoryDepartment = false;
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

$patterns = [];
if (orange_table_exists($pdo, 'pattern_dictionary')) {
    try {
        $patterns = $pdo->query(
            'SELECT id, name_ar, name_en FROM pattern_dictionary WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $patterns = [];
    }
}

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

$subcategoriesForJs = [];
$categoryCatalogMeta = [];
/* لا تحميل runtime من جداول taxonomy التراثية هنا (categories/subcategories). */

$unifiedActiveProductsMissingPt = 0;
if ($catalogNavUnified && orange_table_exists($pdo, 'products') && orange_table_has_column($pdo, 'products', 'product_type_id')) {
    try {
        $unifiedActiveProductsMissingPt = (int) $pdo->query(
            'SELECT COUNT(*) FROM products WHERE is_active = 1 AND (product_type_id IS NULL OR product_type_id <= 0)'
        )->fetchColumn();
    } catch (Throwable $e) {
        $unifiedActiveProductsMissingPt = 0;
    }
}
?>
<div class="page-title">
    <h1>المنتجات</h1>
</div>

<?php if ($catalogNavUnified && $unifiedActiveProductsMissingPt > 0): ?>
<div class="card" style="margin-bottom:12px;background:#fff7ed;border-color:#fdba74;">
    <p style="margin:0;color:#9a3412;line-height:1.55;"><strong>تنبيه الشجرة الموحّدة:</strong> يوجد <strong><?php echo (int) $unifiedActiveProductsMissingPt; ?></strong> منتج <strong>نشط</strong> بلا <code>product_type_id</code> صالح. وفق السياسة يجب ربط كل منتج بـ <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=product_types'), ENT_QUOTES, 'UTF-8'); ?>">نوع منتج</a> في الشجرة قبل الاعتماد الكامل؛ راجع الصفوف في الجدول أدناه.</p>
</div>
<?php endif; ?>

<?php if ($catalogNavUnified): ?>
<div class="card" style="margin-bottom:12px;">
    <p style="margin:0;color:#334155;font-size:14px;line-height:1.55;"><strong>التصنيف الموحّد:</strong> مسار المتجر للمنتج = <strong>نوع المنتج</strong> (ورقة الشجرة) في النموذج — وليس فئة/فرعاً يدوياً على المنتج. هرَم المقاس التجاري (1–2) يُضبط على الورقة فيقترح النموذج عائلات المقاسات (3–4) المتوافقة. الألوان والأنماط تُدار من القواميس وتُربَط بالمتغيرات عند «توليد المتغيرات».</p>
</div>
<?php endif; ?>

<div class="card">
    <h3 id="productFormTitle">إضافة / تعديل منتج</h3>
    <p id="productEditHint" style="display:none;margin:0 0 12px;color:#555;font-size:14px;">تعديل البيانات الأساسية. الترتيب في المتجر من الجدول فقط (↑↓ ثم حفظ الترتيب). كميات الألوان والمقاسات من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=stock'), ENT_QUOTES, 'UTF-8'); ?>">المخزون</a>.</p>
    <?php if (!$catalogNavUnified): ?>
    <div style="margin-bottom:12px;padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">
        <p style="margin:0;color:#991b1b;line-height:1.55;">تمت إزالة شاشات التصنيف التراثية (<strong>أقسام داخلية</strong> و<strong>الفئة والفروع</strong>) من لوحة التحكم. لتعديل المنتجات بالتصنيف الصحيح فعّل <strong>الشجرة الموحّدة</strong> ثم استخدم <strong>نوع المنتج</strong> فقط. المرجع: <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=unified_catalog_branches'), ENT_QUOTES, 'UTF-8'); ?>">فروع شجرة المنتجات</a> و<a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=product_types'), ENT_QUOTES, 'UTF-8'); ?>">أنواع المنتجات</a>.</p>
    </div>
    <?php endif; ?>
    <form id="productForm">
        <input type="hidden" id="product_record_id" value="0">
        <input type="hidden" id="category_id" value="">
        <input type="hidden" id="subcategory_id" value="">

        <div class="admin-product-tabs" role="tablist" aria-label="أقسام نموذج المنتج">
            <button type="button" class="admin-product-tab is-active" role="tab" id="productTabBtnBasic" aria-controls="productTabPanelBasic" aria-selected="true" data-product-tab="basic">البيانات الأساسية</button>
            <button type="button" class="admin-product-tab" role="tab" id="productTabBtnSizes" aria-controls="productTabPanelSizes" aria-selected="false" data-product-tab="sizes">المقاسات والألوان</button>
            <button type="button" class="admin-product-tab" role="tab" id="productTabBtnDescription" aria-controls="productTabPanelDescription" aria-selected="false" data-product-tab="description">وصف المنتج</button>
            <button type="button" class="admin-product-tab" role="tab" id="productTabBtnAttributes" aria-controls="productTabPanelAttributes" aria-selected="false" data-product-tab="attributes">سمات المنتج</button>
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
                    <div class="admin-sort-field-wrap">
                        <label>الترتيب (في المتجر)</label>
                        <input type="text" id="product_sort_order" class="admin-sort-field admin-sort-field--muted" value="<?php echo (int)$nextProductSort; ?>" readonly tabindex="-1" autocomplete="off" inputmode="numeric">
                    </div>
                    <div>
                        <label>مسار الشجرة الموحّدة (مقتطف)</label>
                        <div id="product_department_hint" style="padding:8px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;min-height:20px;">—</div>
                        <small style="display:block;color:#666;margin-top:4px;">مرجع: <code id="product_dept_cat_ref" style="font-size:13px;">—</code></small>
                    </div>
                    <div>
                        <label>حالة العرض</label>
                        <select id="product_is_active">
                            <option value="1">نشط</option>
                            <option value="0">مخفي</option>
                        </select>
                    </div>
                </div>
            </div>
            <?php if ($orangeProductTypeDeptStepEnabled): ?>
            <div class="orange-product-main-department-block">
                <label for="product_main_department_id">القسم الرئيسي — مطلوب أولاً</label>
                <select id="product_main_department_id" required>
                    <option value="">— اختر القسم الرئيسي —</option>
                    <?php foreach ($productTypeDepartmentsForForm as $ptDep): ?>
                        <option value="<?php echo (int) ($ptDep['id'] ?? 0); ?>"><?php echo htmlspecialchars((string) ($ptDep['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="display:block;color:#666;margin-top:4px;">اختر القسم أولاً؛ تُعرض بعدها فقط أنواع المنتج التابعة لهذا القسم.</small>
            </div>
            <?php endif; ?>
            <div id="product_type_block" class="orange-product-type-block">
                <label for="product_type_id">نوع المنتج (ورقة الشجرة الموحّدة) — مطلوب</label>
                <select id="product_type_id" required>
                    <option value="">اختر نوع المنتج</option>
                    <?php foreach ($productTypesForForm as $prt): ?>
                        <?php
                        $ptIdOpt = (int) ($prt['id'] ?? 0);
                        $ptSlug = htmlspecialchars((string) ($prt['slug'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $ptLabel = htmlspecialchars((string) (($prt['name_ar'] ?: $prt['name_en']) ?: ('#' . $prt['id'])), ENT_QUOTES, 'UTF-8');
                        $ptExpCk = htmlspecialchars(trim((string) ($prt['expected_commercial_kind_key'] ?? '')), ENT_QUOTES, 'UTF-8');
                        $ptExpSk = htmlspecialchars(trim((string) ($prt['expected_sizing_category_key'] ?? '')), ENT_QUOTES, 'UTF-8');
                        $ptDeptIdOpt = (int) ($prt['department_id'] ?? 0);
                        $ptTrailTitle = '';
                        if ($catalogNavUnified && $ptIdOpt > 0 && isset($productTypeTrailsForJs[$ptIdOpt]['trail_ar'])) {
                            $ptTrailTitle = trim((string) $productTypeTrailsForJs[$ptIdOpt]['trail_ar']);
                        }
                        $ptTitleAttr = $ptTrailTitle !== '' ? ' title="' . htmlspecialchars($ptTrailTitle, ENT_QUOTES, 'UTF-8') . '"' : '';
                        ?>
                        <option value="<?php echo $ptIdOpt; ?>" data-slug="<?php echo $ptSlug; ?>" data-expected-kind="<?php echo $ptExpCk; ?>" data-expected-cat="<?php echo $ptExpSk; ?>" data-department-id="<?php echo $ptDeptIdOpt; ?>"<?php echo $ptTitleAttr; ?>><?php echo $ptLabel; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
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
            <div>
                <label for="product_item_code">كود الصنف (اختياري)</label>
                <input type="text" id="product_item_code" maxlength="64" autocomplete="off" dir="ltr" lang="en" placeholder="SKU">
            </div>
            <div>
                <label for="product_barcode">الباركود (اختياري)</label>
                <input type="text" id="product_barcode" maxlength="64" autocomplete="off" dir="ltr" lang="en" placeholder="EAN / UPC">
            </div>
        </div>
        </div>
        </div>

        <div id="productTabPanelSizes" class="admin-product-tab-panel" role="tabpanel" aria-labelledby="productTabBtnSizes">
        <div class="admin-product-section">
        <h4 class="admin-product-subsection-title">المقاسات والألوان</h4>
        <?php if ($colors === []): ?>
        <p style="margin:0 0 12px;padding:10px 12px;background:#fff7ed;border:1px solid #fdba74;border-radius:8px;color:#9a3412;font-size:13px;">قاموس <strong>الألوان</strong> فارغ — لن تظهر خيارات في خلطات اللون. أضف ألواناً من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=color_dictionary'), ENT_QUOTES, 'UTF-8'); ?>">قاموس الألوان</a> قبل تفعيل «له ألوان؟».</p>
        <?php endif; ?>
        <?php if ($patterns === [] && orange_table_exists($pdo, 'pattern_dictionary')): ?>
        <p style="margin:0 0 12px;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;color:#64748b;font-size:13px;">قاموس <strong>الأنماط</strong> بلا صفوف نشطة — يمكنك المتابعة بألوان فقط، أو إضافة أنماط من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=pattern_dictionary'), ENT_QUOTES, 'UTF-8'); ?>">أنماط الألوان</a>.</p>
        <?php endif; ?>
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
                        <?php
                        $famSch = htmlspecialchars(trim((string) ($f['size_scheme_key'] ?? '')), ENT_QUOTES, 'UTF-8');
                        $famCk = htmlspecialchars(trim((string) ($f['commercial_kind_key'] ?? '')), ENT_QUOTES, 'UTF-8');
                        $famSk = htmlspecialchars(trim((string) ($f['sizing_category_key'] ?? '')), ENT_QUOTES, 'UTF-8');
                        ?>
                        <option value="<?php echo (int)$f['id']; ?>" data-size-scheme="<?php echo $famSch; ?>" data-commercial-kind="<?php echo $famCk; ?>" data-sizing-category="<?php echo $famSk; ?>"><?php echo htmlspecialchars($f['name_ar'] ?: $f['name_en']); ?></option>
                    <?php endforeach; ?>
                </select>
                <small id="size_family_scheme_hint" style="display:none;margin-top:4px;line-height:1.45;color:#64748b;"></small>
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
        </div>
        </div>

        <div id="productTabPanelDescription" class="admin-product-tab-panel" role="tabpanel" aria-labelledby="productTabBtnDescription">
        <div class="admin-product-section">
        <h4 class="admin-product-subsection-title">وصف المنتج</h4>
        <div class="form-grid product-form-tab-basic-grid">
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
            <div style="grid-column:1/-1;">
                <h4 class="admin-product-subsection-title" style="margin:8px 0 4px;">SEO — عناوين ووصف الميتا (اختياري)</h4>
            </div>
            <div>
                <label for="seo_meta_title_ar">عنوان الميتا (عربي)</label>
                <input type="text" id="seo_meta_title_ar" maxlength="191">
            </div>
            <div>
                <label for="seo_meta_title_en">Meta title (English)</label>
                <input type="text" id="seo_meta_title_en" maxlength="191" lang="en" dir="ltr">
            </div>
            <div>
                <label for="seo_meta_title_fil">Meta title (Filipino)</label>
                <input type="text" id="seo_meta_title_fil" maxlength="191" lang="en" dir="ltr">
            </div>
            <div>
                <label for="seo_meta_title_hi">Meta title (Hindi)</label>
                <input type="text" id="seo_meta_title_hi" maxlength="191" lang="hi" dir="ltr">
            </div>
            <div style="grid-column:1/-1;">
                <label for="seo_meta_description_ar">وصف الميتا (عربي)</label>
                <textarea id="seo_meta_description_ar" rows="2"></textarea>
            </div>
            <div style="grid-column:1/-1;">
                <label for="seo_meta_description_en">Meta description (English)</label>
                <textarea id="seo_meta_description_en" rows="2" lang="en" dir="ltr"></textarea>
            </div>
            <div style="grid-column:1/-1;">
                <label for="seo_meta_description_fil">Meta description (Filipino)</label>
                <textarea id="seo_meta_description_fil" rows="2" lang="en" dir="ltr"></textarea>
            </div>
            <div style="grid-column:1/-1;">
                <label for="seo_meta_description_hi">Meta description (Hindi)</label>
                <textarea id="seo_meta_description_hi" rows="2" lang="hi" dir="ltr"></textarea>
            </div>
        </div>
        </div>
        </div>

        <div id="productTabPanelAttributes" class="admin-product-tab-panel" role="tabpanel" aria-labelledby="productTabBtnAttributes">
        <div class="admin-product-section">
        <h4 class="admin-product-subsection-title">سمات المنتج</h4>
        <div class="form-grid product-form-tab-basic-grid">
        <?php if ($catalogAttributesActive !== []): ?>
        <div style="grid-column:1/-1;">
            <p style="margin:0 0 10px;font-size:13px;color:#64748b;">لكل سطر: اختر نوع السمة ثم القيمة. استخدم «إضافة سمة أخرى» لصف إضافي.</p>
            <div id="orangeCatalogAttrRows"></div>
            <button type="button" class="btn-secondary" id="orangeCatalogAttrAddRowBtn" style="margin-top:6px;">إضافة سمة أخرى</button>
        </div>
        <?php else: ?>
        <div style="grid-column:1/-1;">
            <p style="margin:0;color:#64748b;">لا توجد سمات كتالوج نشطة حالياً.</p>
        </div>
        <?php endif; ?>
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
                <div id="main_image_preview" style="display:none;margin-top:10px;"></div>
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
    <?php if ($catalogNavUnified && $hasProductTypesTable): ?>
    <p style="margin:4px 0 12px;font-size:13px;color:#92400e;background:#fffbeb;padding:10px 12px;border-radius:8px;border:1px solid #fcd34d;">مع تفعيل التصنيف الموحّد، يُشترط اختيار نوع منتج على كل منتج جديد؛ راجع عمود «نوع (موحّد)» في الجدول وصحّح الصفوف التي تظهر تنبيه «ناقص».</p>
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
                    <?php if ($hasProductTypesTable): ?>
                    <th>نوع (موحّد)</th>
                    <?php endif; ?>
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
                $pCatId = isset($p['catalog_category_display_id']) && (int) $p['catalog_category_display_id'] > 0
                    ? (int) $p['catalog_category_display_id']
                    : (isset($p['category_id']) ? (int) $p['category_id'] : 0);
                $pDeptLabel = (string) ($p['department_name_ar'] ?: $p['department_name_en'] ?: '');
                if ($pDeptLabel === '') {
                    $pDeptLabel = '—';
                }
                $deptCatRef = $pDeptId . '-' . $pCatId;
                ?>
                <?php
                $pPtId = isset($p['product_type_id']) && $p['product_type_id'] !== null ? (int) $p['product_type_id'] : 0;
                $pPtCell = '';
                $pPtStyle = '';
                if ($hasProductTypesTable) {
                    if ($pPtId <= 0) {
                        $pPtCell = htmlspecialchars(
                            'ناقص — يُصلح بتعديل المنتج',
                            ENT_QUOTES,
                            'UTF-8'
                        );
                        $pPtStyle = ' style="background:#fef2f2;color:#991b1b;font-weight:600;"';
                    } else {
                        $pPtLabel = trim((string) ($p['pt_name_ar_join'] ?? ''))
                            ?: trim((string) ($p['pt_name_en_join'] ?? ''))
                            ?: trim((string) ($p['pt_slug_join'] ?? ''));
                        $pPtCell = htmlspecialchars($pPtLabel !== '' ? $pPtLabel : ('#' . $pPtId), ENT_QUOTES, 'UTF-8')
                            . ' <span style="color:#64748b;font-size:12px;">(#' . $pPtId . ')</span>';
                    }
                }
                ?>
                <tr data-id="<?php echo (int)$p['id']; ?>" data-dept-id="<?php echo $pDeptId; ?>" data-category-id="<?php echo $pCatId; ?>"<?php echo $hasProductTypesTable ? ' data-product-type-id="' . $pPtId . '"' : ''; ?>>
                    <td><?php echo (int)$p['id']; ?></td>
                    <td><?php echo (int)($p['sort_order'] ?? 0); ?></td>
                    <td><?php echo htmlspecialchars($p['name']); ?></td>
                    <td><?php echo htmlspecialchars($pDeptLabel); ?><?php echo $pDeptId > 0 ? ' <span style="color:#64748b;font-size:12px;">(#' . $pDeptId . ')</span>' : ''; ?></td>
                    <td><?php echo htmlspecialchars($p['category_name'] ?: '-'); ?><?php echo $pCatId > 0 ? ' <span style="color:#64748b;font-size:12px;">(#' . $pCatId . ')</span>' : ''; ?></td>
                    <td><code style="font-size:13px;"><?php echo htmlspecialchars($deptCatRef, ENT_QUOTES, 'UTF-8'); ?></code></td>
                    <?php if ($hasProductTypesTable): ?>
                    <td<?php echo $pPtStyle !== '' ? $pPtStyle : ''; ?>><?php echo $pPtCell; ?></td>
                    <?php endif; ?>
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
window.ORANGE_PUBLIC_BASE_PATH = <?php echo json_encode(PUBLIC_BASE_PATH === '' ? '' : rtrim(PUBLIC_BASE_PATH, '/'), JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_COLORS = <?php echo json_encode($colors, JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_PATTERNS = <?php echo json_encode($patterns, JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_FAMILIES = <?php echo json_encode($familiesOut, JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_SUBCATEGORIES = <?php echo json_encode($subcategoriesForJs, JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_CATEGORY_META = <?php echo json_encode($categoryCatalogMeta, JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_CATALOG_NAV_UNIFIED = <?php echo $catalogNavUnified ? 'true' : 'false'; ?>;
window.ORANGE_PT_DEPT_STEP_ENABLED = <?php echo $orangeProductTypeDeptStepEnabled ? 'true' : 'false'; ?>;
window.ORANGE_PRODUCT_TYPE_TRAIL = <?php echo json_encode($productTypeTrailsForJs, JSON_UNESCAPED_UNICODE); ?>;
window.PRODUCT_EXTRA_IMAGES = [];
window.PRODUCT_NEXT_SORT = <?php echo (int)$nextProductSort; ?>;
window.ORANGE_CATALOG_ATTR_DEFS = <?php echo json_encode($catalogAttrDefsForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;

const PRODUCT_MSG = {
    E_REORDER: 'بيانات الترتيب غير صحيحة',
    OK_REORDER: 'تم حفظ ترتيب المنتجات',
    OK_TOG: 'تم تحديث الحالة'
};

/** اعتماد التصنيف الموحّد فقط: التلميح يُحدَّث من الورقة المختارة. */
function orangeSyncLegacyFieldsFromProductType() {
    updateProductCatalogHint();
}

const orangeProductTypeOptionSeed = [];

function orangeSeedProductTypeOptions() {
    if (orangeProductTypeOptionSeed.length) {
        return;
    }
    const ptEl = document.getElementById('product_type_id');
    if (!ptEl) {
        return;
    }
    Array.from(ptEl.options).forEach(function (opt) {
        const v = String(opt.value || '').trim();
        if (v === '') {
            return;
        }
        orangeProductTypeOptionSeed.push({
            value: v,
            label: String(opt.textContent || '').trim(),
            slug: String(opt.getAttribute('data-slug') || ''),
            expectedKind: String(opt.getAttribute('data-expected-kind') || ''),
            expectedCat: String(opt.getAttribute('data-expected-cat') || ''),
            departmentId: parseInt(String(opt.getAttribute('data-department-id') || '0'), 10) || 0,
            title: String(opt.getAttribute('title') || '')
        });
    });
}

function orangeFindProductTypeSeedById(ptId) {
    const want = String(parseInt(String(ptId || '0'), 10) || 0);
    if (want === '0') {
        return null;
    }
    for (let i = 0; i < orangeProductTypeOptionSeed.length; i++) {
        if (orangeProductTypeOptionSeed[i].value === want) {
            return orangeProductTypeOptionSeed[i];
        }
    }
    return null;
}

function orangeSyncMainDepartmentFromProductType() {
    if (window.ORANGE_PT_DEPT_STEP_ENABLED !== true) {
        return;
    }
    const depEl = document.getElementById('product_main_department_id');
    const ptEl = document.getElementById('product_type_id');
    if (!depEl || !ptEl || !ptEl.value) {
        return;
    }
    orangeSeedProductTypeOptions();
    const seed = orangeFindProductTypeSeedById(ptEl.value);
    if (!seed) {
        return;
    }
    const depId = parseInt(String(seed.departmentId || '0'), 10) || 0;
    depEl.value = depId > 0 ? String(depId) : '';
}

function orangeApplyProductTypeDepartmentFilter(preserveTypeValue) {
    const depEl = document.getElementById('product_main_department_id');
    const ptEl = document.getElementById('product_type_id');
    if (!ptEl) {
        return;
    }
    orangeSeedProductTypeOptions();
    const keepVal = preserveTypeValue ? String(ptEl.value || '').trim() : '';
    const depStep = window.ORANGE_PT_DEPT_STEP_ENABLED === true;
    const depId = depEl ? (parseInt(depEl.value || '0', 10) || 0) : 0;
    ptEl.innerHTML = '';
    const placeholder = document.createElement('option');
    placeholder.value = '';
    if (depStep && depId <= 0) {
        placeholder.textContent = 'اختر القسم الرئيسي أولاً';
        ptEl.appendChild(placeholder);
        ptEl.value = '';
        orangeSyncLegacyFieldsFromProductType();
        orangeApplySizeFamilySchemeFilter();
        return;
    }
    placeholder.textContent = 'اختر نوع المنتج';
    ptEl.appendChild(placeholder);
    orangeProductTypeOptionSeed.forEach(function (seed) {
        const seedDepId = parseInt(String(seed.departmentId || '0'), 10) || 0;
        if (depStep && depId > 0 && seedDepId !== depId) {
            return;
        }
        if (!depStep && depId > 0 && seedDepId !== depId) {
            return;
        }
        const opt = document.createElement('option');
        opt.value = seed.value;
        opt.textContent = seed.label;
        if (seed.slug !== '') {
            opt.setAttribute('data-slug', seed.slug);
        }
        if (seed.expectedKind !== '') {
            opt.setAttribute('data-expected-kind', seed.expectedKind);
        }
        if (seed.expectedCat !== '') {
            opt.setAttribute('data-expected-cat', seed.expectedCat);
        }
        opt.setAttribute('data-department-id', String(seedDepId > 0 ? seedDepId : ''));
        if (seed.title !== '') {
            opt.setAttribute('title', seed.title);
        }
        ptEl.appendChild(opt);
    });
    if (keepVal !== '' && Array.from(ptEl.options).some(function (o) { return o.value === keepVal; })) {
        ptEl.value = keepVal;
    } else {
        ptEl.value = '';
    }
    orangeSyncLegacyFieldsFromProductType();
    orangeApplySizeFamilySchemeFilter();
}

function orangeGetSelectedProductTypeExpectedKind() {
    const el = document.getElementById('product_type_id');
    if (!el || !el.value) {
        return '';
    }
    const opt = el.options[el.selectedIndex];
    return opt ? String(opt.getAttribute('data-expected-kind') || '').trim() : '';
}

function orangeGetSelectedProductTypeExpectedCat() {
    const el = document.getElementById('product_type_id');
    if (!el || !el.value) {
        return '';
    }
    const opt = el.options[el.selectedIndex];
    return opt ? String(opt.getAttribute('data-expected-cat') || '').trim() : '';
}

function orangeApplySizeFamilySchemeFilter() {
    const famSel = document.getElementById('size_family_id');
    const hint = document.getElementById('size_family_scheme_hint');
    if (!famSel) {
        return;
    }
    const hs = document.getElementById('has_sizes') && document.getElementById('has_sizes').value === '1';
    if (!hs) {
        for (let i = 0; i < famSel.options.length; i++) {
            famSel.options[i].disabled = false;
        }
        if (hint) {
            hint.style.display = 'none';
            hint.textContent = '';
        }
        return;
    }

    const expKind = orangeGetSelectedProductTypeExpectedKind();
    const expCat = orangeGetSelectedProductTypeExpectedCat();
    let currentVal = famSel.value;
    let selectedIsBad = false;

    for (let i = 0; i < famSel.options.length; i++) {
        const o = famSel.options[i];
        if (!o.value) {
            o.disabled = false;
            continue;
        }
        const fk = String(o.getAttribute('data-commercial-kind') || '').trim();
        const fsk = String(o.getAttribute('data-sizing-category') || '').trim();
        let ok = true;
        if (expKind !== '' && expCat !== '') {
            ok = fk === expKind && fsk === expCat;
        }
        o.disabled = !ok;
        if (!ok && o.value === currentVal) {
            selectedIsBad = true;
        }
    }

    if (selectedIsBad) {
        famSel.value = '';
    } else if (currentVal && famSel.selectedOptions.length && famSel.selectedOptions[0].disabled) {
        famSel.value = '';
    }

    if (hint) {
        hint.style.display = 'block';
        if (expKind !== '' && expCat !== '') {
            hint.textContent =
                'نطاق نوع المنتج على هرَم المقاس: النوع التجاري «' +
                expKind +
                '» وفئة القياس «' +
                expCat +
                '». تُعرض عائلات المقاسات المطابقة لهذا الهرم فقط (أي مخطط مقاس مستوى 3 ضمن نفس الفئة).';
        } else {
            hint.textContent =
                'نوع المنتج المختار بلا نطاق هرَم (1–2) — تظهر كل العائلات النشطة. عند ضبط النوع التجاري وفئة القياس في «أنواع المنتجات» تُصفّى القائمة تلقائياً.';
        }
    }
}

function orangeCatalogAttrDefs() {
    return Array.isArray(window.ORANGE_CATALOG_ATTR_DEFS) ? window.ORANGE_CATALOG_ATTR_DEFS : [];
}

function orangeCatalogAttrFindDef(attrId) {
    const want = String(parseInt(String(attrId || '0'), 10) || 0);
    if (want === '0') {
        return null;
    }
    const defs = orangeCatalogAttrDefs();
    for (let i = 0; i < defs.length; i++) {
        if (String(defs[i].id) === want) {
            return defs[i];
        }
    }
    return null;
}

function orangeCatalogAttrClearAllRows() {
    const mount = document.getElementById('orangeCatalogAttrRows');
    if (mount) {
        mount.innerHTML = '';
    }
}

function orangeCatalogAttrUpdateRemoveButtons() {
    const mount = document.getElementById('orangeCatalogAttrRows');
    if (!mount) {
        return;
    }
    const rows = mount.querySelectorAll('.orange-pav-dynamic-row');
    const n = rows.length;
    rows.forEach(function (r) {
        const btn = r.querySelector('.orange-pav-row-remove');
        if (btn) {
            btn.disabled = n <= 1;
        }
    });
}

function orangeCatalogAttrBuildTypeSelect(selectedAttrId) {
    const sel = document.createElement('select');
    sel.className = 'orange-pav-type-select admin-sort-field';
    sel.style.width = '100%';
    sel.style.maxWidth = '360px';
    const ph = document.createElement('option');
    ph.value = '';
    ph.textContent = '— نوع السمة —';
    sel.appendChild(ph);
    orangeCatalogAttrDefs().forEach(function (def) {
        const o = document.createElement('option');
        o.value = String(def.id);
        const k = def.key ? String(def.key) : '';
        o.textContent = (def.label || k || '#' + def.id) + (k ? ' (' + k + ')' : '');
        sel.appendChild(o);
    });
    const sid = parseInt(String(selectedAttrId || '0'), 10) || 0;
    if (sid > 0) {
        sel.value = String(sid);
    }
    return sel;
}

function orangeCatalogAttrFillValueWrap(row, attrId, presetVal) {
    const wrap = row.querySelector('.orange-pav-value-wrap');
    if (!wrap) {
        return;
    }
    wrap.innerHTML = '';
    const labelVal = document.createElement('label');
    labelVal.textContent = 'القيمة';
    labelVal.style.display = 'block';
    labelVal.style.marginBottom = '4px';
    labelVal.style.fontWeight = '500';
    wrap.appendChild(labelVal);
    const aid = parseInt(String(attrId || '0'), 10) || 0;
    const preset = presetVal != null ? String(presetVal) : '';
    if (aid <= 0) {
        const dis = document.createElement('input');
        dis.type = 'text';
        dis.disabled = true;
        dis.className = 'orange-pav-value-input';
        dis.placeholder = 'اختر نوع السمة أولاً';
        dis.style.width = '100%';
        dis.style.maxWidth = '520px';
        wrap.appendChild(dis);
        return;
    }
    const def = orangeCatalogAttrFindDef(aid);
    if (!def) {
        const dis = document.createElement('input');
        dis.type = 'text';
        dis.disabled = true;
        dis.className = 'orange-pav-value-input';
        dis.value = preset;
        wrap.appendChild(dis);
        return;
    }
    const opts = def.options && Array.isArray(def.options) ? def.options : [];
    if (opts.length) {
        const s = document.createElement('select');
        s.className = 'orange-pav-value-input admin-sort-field';
        s.style.width = '100%';
        s.style.maxWidth = '520px';
        const o0 = document.createElement('option');
        o0.value = '';
        o0.textContent = '— بدون —';
        s.appendChild(o0);
        opts.forEach(function (op) {
            const o = document.createElement('option');
            o.value = String(op.v || '');
            o.textContent = String(op.d || op.v || '');
            s.appendChild(o);
        });
        wrap.appendChild(s);
        if (preset !== '') {
            s.value = preset;
            if (s.value !== preset) {
                s.value = '';
            }
        }
        return;
    }
    if (def.inputKind === 'boolean') {
        const s = document.createElement('select');
        s.className = 'orange-pav-value-input admin-sort-field';
        s.style.width = '100%';
        s.style.maxWidth = '520px';
        const o0 = document.createElement('option');
        o0.value = '';
        o0.textContent = '— بدون —';
        s.appendChild(o0);
        ['نعم', 'لا'].forEach(function (t) {
            const o = document.createElement('option');
            o.value = t;
            o.textContent = t;
            s.appendChild(o);
        });
        wrap.appendChild(s);
        if (preset !== '') {
            s.value = preset;
        }
        return;
    }
    const inp = document.createElement('input');
    inp.type = 'text';
    inp.className = 'orange-pav-value-input';
    inp.maxLength = 767;
    inp.dir = 'auto';
    inp.autocomplete = 'off';
    inp.style.width = '100%';
    inp.style.maxWidth = '520px';
    inp.value = preset;
    wrap.appendChild(inp);
}

function orangeCatalogAttrAppendRow(selectedAttrId, presetVal) {
    const mount = document.getElementById('orangeCatalogAttrRows');
    if (!mount) {
        return;
    }
    const row = document.createElement('div');
    row.className = 'orange-pav-dynamic-row';
    row.style.display = 'flex';
    row.style.gap = '12px';
    row.style.alignItems = 'flex-end';
    row.style.flexWrap = 'wrap';
    row.style.marginBottom = '12px';
    const col1 = document.createElement('div');
    col1.style.flex = '1';
    col1.style.minWidth = '180px';
    const lbl1 = document.createElement('label');
    lbl1.textContent = 'نوع السمة';
    lbl1.style.display = 'block';
    lbl1.style.marginBottom = '4px';
    lbl1.style.fontWeight = '500';
    const typeSel = orangeCatalogAttrBuildTypeSelect(selectedAttrId);
    col1.appendChild(lbl1);
    col1.appendChild(typeSel);
    const col2 = document.createElement('div');
    col2.className = 'orange-pav-value-wrap';
    col2.style.flex = '1.2';
    col2.style.minWidth = '200px';
    const btnRm = document.createElement('button');
    btnRm.type = 'button';
    btnRm.className = 'btn-secondary orange-pav-row-remove';
    btnRm.textContent = 'حذف السطر';
    btnRm.addEventListener('click', function () {
        orangeCatalogAttrRemoveRow(row);
    });
    row.appendChild(col1);
    row.appendChild(col2);
    row.appendChild(btnRm);
    mount.appendChild(row);
    const aid = parseInt(String(selectedAttrId || '0'), 10) || 0;
    orangeCatalogAttrFillValueWrap(row, aid, presetVal);
    typeSel.addEventListener('change', function () {
        const id = parseInt(typeSel.value || '0', 10) || 0;
        orangeCatalogAttrFillValueWrap(row, id, '');
    });
    orangeCatalogAttrUpdateRemoveButtons();
}

function orangeCatalogAttrRemoveRow(row) {
    const mount = document.getElementById('orangeCatalogAttrRows');
    if (!mount || !row) {
        return;
    }
    row.remove();
    if (!mount.querySelector('.orange-pav-dynamic-row')) {
        orangeCatalogAttrAppendRow(0, '');
    }
    orangeCatalogAttrUpdateRemoveButtons();
}

function orangeCatalogAttrAddEmptyRow() {
    if (!orangeCatalogAttrDefs().length) {
        return;
    }
    orangeCatalogAttrAppendRow(0, '');
}

function orangeCollectCatalogAttributePayload() {
    const mount = document.getElementById('orangeCatalogAttrRows');
    if (!mount) {
        return [];
    }
    const byId = new Map();
    mount.querySelectorAll('.orange-pav-dynamic-row').forEach(function (row) {
        const typeSel = row.querySelector('.orange-pav-type-select');
        const id = parseInt(typeSel && typeSel.value ? typeSel.value : '0', 10) || 0;
        if (id <= 0) {
            return;
        }
        const inp = row.querySelector('.orange-pav-value-input');
        if (!inp || inp.disabled) {
            return;
        }
        const v = String(inp.value || '').trim();
        if (v === '') {
            return;
        }
        byId.set(id, v);
    });
    const out = [];
    byId.forEach(function (v, id) {
        out.push({ catalog_attribute_id: id, value_raw: v });
    });
    return out;
}

function orangeClearCatalogAttributeInputs() {
    orangeCatalogAttrClearAllRows();
    if (orangeCatalogAttrDefs().length) {
        orangeCatalogAttrAppendRow(0, '');
    }
}

function orangeApplyCatalogAttributeValuesFromProduct(p) {
    orangeCatalogAttrClearAllRows();
    if (!orangeCatalogAttrDefs().length) {
        return;
    }
    const pavs = p && Array.isArray(p.catalog_attribute_values) ? p.catalog_attribute_values : [];
    const rows = [];
    pavs.forEach(function (row) {
        const id = parseInt(String(row.catalog_attribute_id || '0'), 10) || 0;
        if (id <= 0) {
            return;
        }
        const val = row.value_raw != null ? String(row.value_raw) : '';
        if (String(val).trim() === '') {
            return;
        }
        if (!orangeCatalogAttrFindDef(id)) {
            return;
        }
        rows.push({ id: id, val: val });
    });
    if (rows.length === 0) {
        orangeCatalogAttrAppendRow(0, '');
        return;
    }
    rows.forEach(function (r) {
        orangeCatalogAttrAppendRow(r.id, r.val);
    });
    orangeCatalogAttrAppendRow(0, '');
}

function adminEscAttr(s) {
    return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;');
}
function adminPublicPath(path) {
    const raw = typeof window.ORANGE_PUBLIC_BASE_PATH === 'string' ? window.ORANGE_PUBLIC_BASE_PATH : '';
    const base = raw.replace(/\/+$/, '');
    const p = path.charAt(0) === '/' ? path : '/' + path;
    return base + p;
}
function adminProductImageBasename(filename) {
    const fn = String(filename || '').trim();
    if (!fn) {
        return '';
    }
    const parts = fn.split(/[/\\]/);
    return parts[parts.length - 1] || '';
}
/** معاينة الصورة الرئيسية: يفضّل ‎webp‎ المرافق كما في الواجهة. */
function adminSetMainImagePreview(filename) {
    const mount = document.getElementById('main_image_preview');
    if (!mount) {
        return;
    }
    const base = adminProductImageBasename(filename);
    if (!base) {
        mount.innerHTML = '';
        mount.style.display = 'none';
        return;
    }
    const lower = base.toLowerCase();
    const prefix = adminPublicPath('/uploads/products/');
    const orig = prefix + encodeURIComponent(base);
    const style = 'max-height:140px;border-radius:8px;border:1px solid #ddd;';
    if (lower.endsWith('.webp')) {
        mount.innerHTML = '<img alt="" style="' + style + '" src="' + adminEscAttr(orig) + '">';
    } else {
        const stem = base.indexOf('.') !== -1 ? base.slice(0, base.lastIndexOf('.')) : base;
        const webp = prefix + encodeURIComponent(stem + '.webp');
        mount.innerHTML =
            '<picture><source type="image/webp" srcset="' +
            adminEscAttr(webp) +
            '"><img alt="" style="' +
            style +
            '" src="' +
            adminEscAttr(orig) +
            '"></picture>';
    }
    mount.style.display = 'block';
}

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
    if (sel.tagName !== 'SELECT') {
        if (preserveId === null || preserveId === '' || preserveId === undefined) {
            sel.value = '';
        } else {
            sel.value = String(preserveId);
        }
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
    adminSetMainImagePreview(fn);
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
                    adminSetMainImagePreview('');
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
        adminSetMainImagePreview(j.filename);
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
    document.getElementById('seo_meta_title_ar').value = '';
    document.getElementById('seo_meta_title_en').value = '';
    document.getElementById('seo_meta_title_fil').value = '';
    document.getElementById('seo_meta_title_hi').value = '';
    document.getElementById('seo_meta_description_ar').value = '';
    document.getElementById('seo_meta_description_en').value = '';
    document.getElementById('seo_meta_description_fil').value = '';
    document.getElementById('seo_meta_description_hi').value = '';
    const depClear = document.getElementById('product_main_department_id');
    if (depClear) {
        depClear.value = '';
    }
    orangeApplyProductTypeDepartmentFilter(false);
    const ptClear = document.getElementById('product_type_id');
    if (ptClear) {
        ptClear.value = '';
    }
    document.getElementById('category_id').value = '';
    rebuildSubcategoryOptions(null);
    updateProductCatalogHint();
    document.getElementById('price').value = '';
    document.getElementById('cost').value = '';
    const pic = document.getElementById('product_item_code');
    const pbc = document.getElementById('product_barcode');
    if (pic) {
        pic.value = '';
    }
    if (pbc) {
        pbc.value = '';
    }
    document.getElementById('main_image').value = '';
    document.getElementById('main_image_file').value = '';
    adminSetMainImagePreview('');
    document.getElementById('has_sizes').value = '0';
    document.getElementById('has_colors').value = '0';
    document.getElementById('size_family_id').value = '';
    document.getElementById('sizing_guide_scope').value = 'none';
    document.getElementById('product_is_active').value = '1';
    document.getElementById('colorwaysBox').innerHTML = '';
    document.getElementById('variantsBox').innerHTML = '';
    window.PRODUCT_EXTRA_IMAGES = [];
    renderGalleryUploadList();
    orangeClearCatalogAttributeInputs();
    onHasFlagsChange();
    productFormShowTab('basic');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function productFormShowTab(tab) {
    const map = {
        basic: 'productTabPanelBasic',
        sizes: 'productTabPanelSizes',
        description: 'productTabPanelDescription',
        attributes: 'productTabPanelAttributes',
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
        document.getElementById('seo_meta_title_ar').value = p.seo_meta_title_ar || '';
        document.getElementById('seo_meta_title_en').value = p.seo_meta_title_en || '';
        document.getElementById('seo_meta_title_fil').value = p.seo_meta_title_fil || '';
        document.getElementById('seo_meta_title_hi').value = p.seo_meta_title_hi || '';
        document.getElementById('seo_meta_description_ar').value = p.seo_meta_description_ar || '';
        document.getElementById('seo_meta_description_en').value = p.seo_meta_description_en || '';
        document.getElementById('seo_meta_description_fil').value = p.seo_meta_description_fil || '';
        document.getElementById('seo_meta_description_hi').value = p.seo_meta_description_hi || '';
        document.getElementById('category_id').value = String(p.category_id || '');
        const sid = parseInt(p.subcategory_id, 10) || 0;
        rebuildSubcategoryOptions(sid > 0 ? sid : null);
        updateProductCatalogHint();
        const ptId = parseInt(String(p.product_type_id || '0'), 10) || 0;
        const depSel = document.getElementById('product_main_department_id');
        if (depSel) {
            orangeSeedProductTypeOptions();
            const seed = orangeFindProductTypeSeedById(ptId);
            depSel.value = seed && seed.departmentId > 0 ? String(seed.departmentId) : '';
            orangeApplyProductTypeDepartmentFilter(false);
        }
        const pte = document.getElementById('product_type_id');
        if (pte) {
            pte.value = ptId > 0 ? String(ptId) : '';
            if (ptId > 0 && pte.value !== String(ptId)) {
                orangeSeedProductTypeOptions();
                const seedRetry = orangeFindProductTypeSeedById(ptId);
                const depRetry = document.getElementById('product_main_department_id');
                if (depRetry && seedRetry && parseInt(String(seedRetry.departmentId || '0'), 10) > 0) {
                    depRetry.value = String(seedRetry.departmentId);
                    orangeApplyProductTypeDepartmentFilter(false);
                    pte.value = String(ptId);
                }
            }
            orangeSyncMainDepartmentFromProductType();
            if (window.ORANGE_PT_DEPT_STEP_ENABLED === true) {
                orangeApplyProductTypeDepartmentFilter(true);
            }
            orangeSyncLegacyFieldsFromProductType();
        }
        document.getElementById('price').value = String(p.price != null ? p.price : '');
        document.getElementById('cost').value = String(p.cost != null ? p.cost : '');
        const picEl = document.getElementById('product_item_code');
        const pbcEl = document.getElementById('product_barcode');
        if (picEl) {
            picEl.value = p.item_code != null && String(p.item_code) !== '' ? String(p.item_code) : '';
        }
        if (pbcEl) {
            pbcEl.value = p.barcode != null && String(p.barcode) !== '' ? String(p.barcode) : '';
        }
        const extrasEarly = Array.isArray(p.extra_images) ? p.extra_images.slice() : [];
        let mainFn = (p.main_image || '').trim();
        if (!mainFn && extrasEarly.length) {
            mainFn = extrasEarly[0];
        }
        document.getElementById('main_image').value = mainFn;
        adminSetMainImagePreview(mainFn);
        document.getElementById('has_sizes').value = parseInt(p.has_sizes, 10) === 1 ? '1' : '0';
        document.getElementById('has_colors').value = parseInt(p.has_colors, 10) === 1 ? '1' : '0';
        document.getElementById('size_family_id').value = p.size_family_id ? String(p.size_family_id) : '';
        document.getElementById('sizing_guide_scope').value = p.sizing_guide_scope || 'none';
        document.getElementById('colorwaysBox').innerHTML = '';
        document.getElementById('variantsBox').innerHTML = '';
        const vm = Array.isArray(p.variant_matrix_rows) ? p.variant_matrix_rows : [];
        window.PRODUCT_EXTRA_IMAGES = extrasEarly;
        renderGalleryUploadList();
        onHasFlagsChange();
        orangeApplyCatalogAttributeValuesFromProduct(p);
        buildColorwaysForEditFromVm(vm);
        const needMatrix =
            parseInt(p.has_colors, 10) === 1 ||
            parseInt(p.has_sizes, 10) === 1 ||
            (vm && vm.length > 0);
        if (needMatrix) {
            generateVariants();
            applyVariantStocksFromVm(vm);
        }
        if (needMatrix && !document.querySelectorAll('#variantsBox tbody tr').length) {
            document.getElementById('variantsBox').innerHTML =
                '<p class="admin-variants-edit-note">لم تُستخرج المتغيرات — راجع الشاشة ثم استخدم «توليد المتغيرات» وحفظ.</p>';
        }
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
    orangeApplySizeFamilySchemeFilter();
}

function patternOptionsHtml() {
    let h = '<option value="0">—</option>';
    (window.ORANGE_PATTERNS || []).forEach(pt => {
        const t = (pt.name_ar || pt.name_en || '').replace(/</g,'');
        h += `<option value="${pt.id}">${t}</option>`;
    });
    return h;
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
    const base = adminProductImageBasename(mainImg);
    if (!base) {
        return '<span class="admin-variant-thumb-placeholder" title="ارفع صورة من تبويب الصور">؟</span>';
    }
    const lower = base.toLowerCase();
    const prefix = adminPublicPath('/uploads/products/');
    const orig = prefix + encodeURIComponent(base);
    if (lower.endsWith('.webp')) {
        return (
            '<img src="' +
            adminEscAttr(orig) +
            '" alt="" class="admin-variant-thumb" width="48" height="48" loading="lazy">'
        );
    }
    const stem = base.indexOf('.') !== -1 ? base.slice(0, base.lastIndexOf('.')) : base;
    const webp = prefix + encodeURIComponent(stem + '.webp');
    return (
        '<picture class="admin-variant-thumb-picture"><source type="image/webp" srcset="' +
        adminEscAttr(webp) +
        '"><img src="' +
        adminEscAttr(orig) +
        '" alt="" class="admin-variant-thumb" width="48" height="48" loading="lazy"></picture>'
    );
}

function addColorwayRow() {
    const box = document.getElementById('colorwaysBox');
    const div = document.createElement('div');
    div.className = 'cw-row form-grid cw-row--compact';
    div.innerHTML = `
        <div><label>أساسي</label><select class="cw-p">${colorOptionsHtml()}</select></div>
        <div><label>ثانوي (اختياري)</label><select class="cw-s">${colorOptionsHtml()}</select></div>
        <div><label>نمط أساسي (اختياري)</label><select class="cw-pp">${patternOptionsHtml()}</select></div>
        <div><label>نمط ثانوي (اختياري)</label><select class="cw-sp">${patternOptionsHtml()}</select></div>
    `;
    box.appendChild(div);
}

function adminVariantRowStockKey(r) {
    if (!r || typeof r !== 'object') {
        return '';
    }
    const a = [
        parseInt(r.primary_color_id, 10) || 0,
        parseInt(r.secondary_color_id, 10) || 0,
        parseInt(r.primary_pattern_id, 10) || 0,
        parseInt(r.secondary_pattern_id, 10) || 0,
        parseInt(r.size_family_size_id, 10) || 0
    ];
    return a.join(':');
}

function adminVariantTrStockKey(tr) {
    if (!tr) {
        return '';
    }
    const gv = (sel) => parseInt((tr.querySelector(sel) && tr.querySelector(sel).value) || '0', 10) || 0;
    return [gv('.v-p'), gv('.v-s'), gv('.v-pp'), gv('.v-sp'), gv('.v-zid')].join(':');
}

function buildColorwaysForEditFromVm(vm) {
    const hc = document.getElementById('has_colors').value === '1';
    if (!hc) {
        return;
    }
    const box = document.getElementById('colorwaysBox');
    box.innerHTML = '';
    const seen = new Set();
    (vm || []).forEach((r) => {
        const k = [r.primary_color_id || 0, r.secondary_color_id || 0, r.primary_pattern_id || 0, r.secondary_pattern_id || 0].join(':');
        if (seen.has(k)) {
            return;
        }
        seen.add(k);
        addColorwayRow();
        const rows = box.querySelectorAll('.cw-row');
        const last = rows[rows.length - 1];
        if (!last) {
            return;
        }
        const pEl = last.querySelector('.cw-p');
        const sEl = last.querySelector('.cw-s');
        const ppEl = last.querySelector('.cw-pp');
        const spEl = last.querySelector('.cw-sp');
        if (pEl) {
            pEl.value = String(parseInt(r.primary_color_id, 10) || 0);
        }
        if (sEl) {
            sEl.value = String(parseInt(r.secondary_color_id, 10) || 0);
        }
        if (ppEl) {
            ppEl.value = String(parseInt(r.primary_pattern_id, 10) || 0);
        }
        if (spEl) {
            spEl.value = String(parseInt(r.secondary_pattern_id, 10) || 0);
        }
    });
}

function applyVariantStocksFromVm(vm) {
    const map = {};
    (vm || []).forEach((r) => {
        map[adminVariantRowStockKey(r)] = parseInt(r.stock_quantity, 10) || 0;
    });
    document.querySelectorAll('#variantsBox tbody tr').forEach((tr) => {
        const k = adminVariantTrStockKey(tr);
        const inp = tr.querySelector('.v-stock');
        if (inp && Object.prototype.hasOwnProperty.call(map, k)) {
            inp.value = String(map[k]);
        }
    });
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
        combos.push({ primary_color_id: 0, secondary_color_id: 0, primary_pattern_id: 0, secondary_pattern_id: 0, size_family_size_id: 0, stock: 0 });
    } else if (hasC && hasS) {
        document.querySelectorAll('#colorwaysBox .cw-row').forEach(row => {
            const p = parseInt(row.querySelector('.cw-p').value, 10) || 0;
            const s = parseInt(row.querySelector('.cw-s').value, 10) || 0;
            const pp = parseInt((row.querySelector('.cw-pp') && row.querySelector('.cw-pp').value) || '0', 10) || 0;
            const sp = parseInt((row.querySelector('.cw-sp') && row.querySelector('.cw-sp').value) || '0', 10) || 0;
            if (!p) {
                return;
            }
            sizes.forEach(sz => {
                combos.push({ primary_color_id: p, secondary_color_id: s, primary_pattern_id: pp, secondary_pattern_id: sp, size_family_size_id: sz.id, stock: 0 });
            });
        });
    } else if (hasC && !hasS) {
        document.querySelectorAll('#colorwaysBox .cw-row').forEach(row => {
            const p = parseInt(row.querySelector('.cw-p').value, 10) || 0;
            const s = parseInt(row.querySelector('.cw-s').value, 10) || 0;
            const pp = parseInt((row.querySelector('.cw-pp') && row.querySelector('.cw-pp').value) || '0', 10) || 0;
            const sp = parseInt((row.querySelector('.cw-sp') && row.querySelector('.cw-sp').value) || '0', 10) || 0;
            if (!p) return;
            combos.push({ primary_color_id: p, secondary_color_id: s, primary_pattern_id: pp, secondary_pattern_id: sp, size_family_size_id: 0, stock: 0 });
        });
    } else if (!hasC && hasS) {
        sizes.forEach(sz => {
            combos.push({ primary_color_id: 0, secondary_color_id: 0, primary_pattern_id: 0, secondary_pattern_id: 0, size_family_size_id: sz.id, stock: 0 });
        });
    }

    if (!combos.length) {
        alert('لا توجد تركيبات');
        return;
    }

    const thumbCell = adminVariantReferenceThumbHtml();
    let html = '<p class="admin-variants-lead">كل صف يمثل <strong>نفس الصنف</strong> مع دمج لون ونمط اختياري × مقاس. عمود «صورة المرجع» يعكس الصورة الرئيسية الحالية (من تبويب الصور).</p>';
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
        const ppt = (window.ORANGE_PATTERNS || []).find(x => String(x.id) === String(c.primary_pattern_id));
        const spt = (window.ORANGE_PATTERNS || []).find(x => String(x.id) === String(c.secondary_pattern_id));
        let patPhrase = '';
        if (ppt) patPhrase += (ppt.name_ar || ppt.name_en);
        if (spt) patPhrase += (patPhrase ? ' · ' : '') + (spt.name_ar || spt.name_en);
        if (patPhrase) {
            colorLabel = colorLabel === '—' ? patPhrase : colorLabel + ' — ' + patPhrase;
        }
        const dots = [adminColorSwatchHtml(p), adminColorSwatchHtml(s && (!p || String(s.id) !== String(p.id)) ? s : null)].filter(Boolean).join('');
        const colorCell = '<div class="admin-variant-color-cell">' + dots + '<span class="admin-variant-color-names">' + colorLabel + '</span></div>' +
            `<input type="hidden" class="v-p" value="${c.primary_color_id}"><input type="hidden" class="v-s" value="${c.secondary_color_id}">` +
            `<input type="hidden" class="v-pp" value="${c.primary_pattern_id || 0}"><input type="hidden" class="v-sp" value="${c.secondary_pattern_id || 0}">`;
        html += `<tr>
            <td class="td-ref-img">${thumbCell}</td>
            <td>${colorCell}</td>
            <td><span class="admin-variant-size-pill">${szLabel}</span><input type="hidden" class="v-zid" value="${c.size_family_size_id}"></td>
            <td class="td-stock"><input type="number" class="v-stock admin-inp-qty admin-input-narrow" min="0" step="1" value="0" inputmode="numeric" lang="en" dir="ltr" data-idx="${idx}"></td>
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

    if (window.ORANGE_PT_DEPT_STEP_ENABLED === true) {
        const depSaveEl = document.getElementById('product_main_department_id');
        const depSaveVal = depSaveEl ? (parseInt(depSaveEl.value || '0', 10) || 0) : 0;
        if (depSaveVal <= 0) {
            productFormShowTab('basic');
            alert('يجب اختيار «القسم الرئيسي» أولاً، ثم نوع المنتج التابع لهذا القسم.');
            return;
        }
    }
    const ptEl = document.getElementById('product_type_id');
    const ptVal = ptEl ? (parseInt(ptEl.value || '0', 10) || 0) : 0;
    if (ptVal <= 0) {
        productFormShowTab('basic');
        if (window.ORANGE_PT_DEPT_STEP_ENABLED === true) {
            alert('اختر «القسم الرئيسي» ثم «نوع المنتج» من الأنواع المعروضة لهذا القسم فقط.');
        } else {
            alert('يجب اختيار «نوع المنتج» قبل الحفظ.');
        }
        return;
    }

    const hsCheck = parseInt(document.getElementById('has_sizes').value || '0', 10) === 1;
    if (hsCheck) {
        const ptSave = document.getElementById('product_type_id');
        const ptIdSave = ptSave && ptSave.value ? (parseInt(ptSave.value, 10) || 0) : 0;
        if (ptIdSave > 0) {
            const expKind = orangeGetSelectedProductTypeExpectedKind();
            const expCat = orangeGetSelectedProductTypeExpectedCat();
            const famSel = document.getElementById('size_family_id');
            if (famSel && famSel.value) {
                const fo = famSel.options[famSel.selectedIndex];
                const fk = fo ? String(fo.getAttribute('data-commercial-kind') || '').trim() : '';
                const fsk = fo ? String(fo.getAttribute('data-sizing-category') || '').trim() : '';
                if (expKind !== '' && expCat !== '' && (fk !== expKind || fsk !== expCat)) {
                    productFormShowTab('sizes');
                    alert('عائلة المقاسات لا تطابق نطاق هرَم نوع المنتج (النوع التجاري «' + expKind + '» / فئة «' + expCat + '»).');
                    return;
                }
            }
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
            seo_meta_title_ar: document.getElementById('seo_meta_title_ar').value.trim(),
            seo_meta_title_en: document.getElementById('seo_meta_title_en').value.trim(),
            seo_meta_title_fil: document.getElementById('seo_meta_title_fil').value.trim(),
            seo_meta_title_hi: document.getElementById('seo_meta_title_hi').value.trim(),
            seo_meta_description_ar: document.getElementById('seo_meta_description_ar').value.trim(),
            seo_meta_description_en: document.getElementById('seo_meta_description_en').value.trim(),
            seo_meta_description_fil: document.getElementById('seo_meta_description_fil').value.trim(),
            seo_meta_description_hi: document.getElementById('seo_meta_description_hi').value.trim(),
            category_id: parseInt(document.getElementById('category_id').value, 10) || 0,
            product_type_id: (function () {
                const pel = document.getElementById('product_type_id');
                if (!pel || !pel.value) {
                    return 0;
                }
                const n = parseInt(pel.value, 10);

                return n > 0 ? n : 0;
            }()),
            price: parseFloat(document.getElementById('price').value || '0'),
            cost: parseFloat(document.getElementById('cost').value || '0'),
            main_image: document.getElementById('main_image').value.trim() || (window.PRODUCT_EXTRA_IMAGES && window.PRODUCT_EXTRA_IMAGES[0] ? window.PRODUCT_EXTRA_IMAGES[0] : ''),
            has_sizes: parseInt(document.getElementById('has_sizes').value, 10),
            has_colors: parseInt(document.getElementById('has_colors').value, 10),
            size_family_id: parseInt(document.getElementById('size_family_id').value, 10) || 0,
            sizing_guide_scope: document.getElementById('sizing_guide_scope').value,
            sort_order: parseInt(document.getElementById('product_sort_order').value || '0', 10),
            is_active: parseInt(document.getElementById('product_is_active').value, 10),
            item_code: (document.getElementById('product_item_code') && document.getElementById('product_item_code').value.trim()) || '',
            barcode: (document.getElementById('product_barcode') && document.getElementById('product_barcode').value.trim()) || ''
        };
        const subEl = document.getElementById('subcategory_id');
        if (subEl) {
            const sv = subEl.value.trim();
            payload.subcategory_id = sv === '' ? null : parseInt(sv, 10);
        }
        payload.extra_images = window.PRODUCT_EXTRA_IMAGES || [];
        payload.catalog_attribute_values = orangeCollectCatalogAttributePayload();
        const hsUp = parseInt(document.getElementById('has_sizes').value, 10) === 1;
        const hcUp = parseInt(document.getElementById('has_colors').value, 10) === 1;
        const varRowsUp = Array.from(document.querySelectorAll('#variantsBox tbody tr'));
        if ((hsUp || hcUp) && !varRowsUp.length) {
            productFormShowTab('variants');
            alert('ولّد المتغيرات أو حمّل المصفوفة قبل التحديث');
            return;
        }
        if (varRowsUp.length) {
            payload.variants = varRowsUp.map((tr) => ({
                primary_color_id: parseInt(tr.querySelector('.v-p').value, 10) || 0,
                secondary_color_id: parseInt(tr.querySelector('.v-s').value, 10) || 0,
                primary_pattern_id: parseInt((tr.querySelector('.v-pp') && tr.querySelector('.v-pp').value) || '0', 10) || 0,
                secondary_pattern_id: parseInt((tr.querySelector('.v-sp') && tr.querySelector('.v-sp').value) || '0', 10) || 0,
                size_family_size_id: parseInt(tr.querySelector('.v-zid').value, 10) || 0,
                stock_quantity: parseInt(tr.querySelector('.v-stock').value || '0', 10)
            }));
        }
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
        primary_pattern_id: parseInt((tr.querySelector('.v-pp') && tr.querySelector('.v-pp').value) || '0', 10) || 0,
        secondary_pattern_id: parseInt((tr.querySelector('.v-sp') && tr.querySelector('.v-sp').value) || '0', 10) || 0,
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
        seo_meta_title_ar: document.getElementById('seo_meta_title_ar').value.trim(),
        seo_meta_title_en: document.getElementById('seo_meta_title_en').value.trim(),
        seo_meta_title_fil: document.getElementById('seo_meta_title_fil').value.trim(),
        seo_meta_title_hi: document.getElementById('seo_meta_title_hi').value.trim(),
        seo_meta_description_ar: document.getElementById('seo_meta_description_ar').value.trim(),
        seo_meta_description_en: document.getElementById('seo_meta_description_en').value.trim(),
        seo_meta_description_fil: document.getElementById('seo_meta_description_fil').value.trim(),
        seo_meta_description_hi: document.getElementById('seo_meta_description_hi').value.trim(),
        category_id: parseInt(document.getElementById('category_id').value, 10) || 0,
        product_type_id: (function () {
            const pel = document.getElementById('product_type_id');
            if (!pel || !pel.value) {
                return 0;
            }
            const n = parseInt(pel.value, 10);

            return n > 0 ? n : 0;
        }()),
        price: parseFloat(document.getElementById('price').value || '0'),
        cost: parseFloat(document.getElementById('cost').value || '0'),
        main_image: document.getElementById('main_image').value.trim() || (window.PRODUCT_EXTRA_IMAGES && window.PRODUCT_EXTRA_IMAGES[0] ? window.PRODUCT_EXTRA_IMAGES[0] : ''),
        has_sizes: parseInt(document.getElementById('has_sizes').value, 10),
        has_colors: parseInt(document.getElementById('has_colors').value, 10),
        size_family_id: parseInt(document.getElementById('size_family_id').value, 10) || 0,
        sizing_guide_scope: document.getElementById('sizing_guide_scope').value,
        extra_images: window.PRODUCT_EXTRA_IMAGES || [],
        item_code: (document.getElementById('product_item_code') && document.getElementById('product_item_code').value.trim()) || '',
        barcode: (document.getElementById('product_barcode') && document.getElementById('product_barcode').value.trim()) || '',
        variants
    };
    const subElNew = document.getElementById('subcategory_id');
    if (subElNew) {
        const sv2 = subElNew.value.trim();
        payload.subcategory_id = sv2 === '' ? null : parseInt(sv2, 10);
    }

    payload.catalog_attribute_values = orangeCollectCatalogAttributePayload();

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
    const ptEl = document.getElementById('product_type_id');
    const trailMap = window.ORANGE_PRODUCT_TYPE_TRAIL || {};
    if (window.ORANGE_CATALOG_NAV_UNIFIED) {
        if (!hint || !refEl || !ptEl) {
            return;
        }
        const pid = parseInt(ptEl.value || '0', 10) || 0;
        const row = trailMap[pid];
        if (!row || !String(row.trail_ar || '').trim()) {
            hint.textContent = pid > 0 ? '— اختر نوع منتج نشط لعرض المسار' : '—';
            refEl.textContent = pid > 0 ? 'pt-' + pid : '—';
            return;
        }
        hint.textContent = String(row.trail_ar || '').trim();
        refEl.textContent = 'pt-' + pid;
        return;
    }
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
        hint.textContent = 'بدون قسم — فعّل الشجرة الموحّدة أو اربط القسم عبر الترحيل';
    }
    refEl.textContent = meta.ref;
}

const categorySelectEl = document.getElementById('category_id');
if (categorySelectEl && categorySelectEl.tagName === 'SELECT') {
    categorySelectEl.addEventListener('change', function () {
        rebuildSubcategoryOptions(null);
        updateProductCatalogHint();
    });
}

const orangeProductDepartmentSelectEl = document.getElementById('product_main_department_id');
const orangeProductTypeSelectEl = document.getElementById('product_type_id');
orangeSeedProductTypeOptions();
orangeApplyProductTypeDepartmentFilter(false);
if (orangeProductDepartmentSelectEl) {
    orangeProductDepartmentSelectEl.addEventListener('change', function () {
        orangeApplyProductTypeDepartmentFilter(true);
    });
}
if (orangeProductTypeSelectEl) {
    orangeProductTypeSelectEl.addEventListener('change', function () {
        orangeSyncMainDepartmentFromProductType();
        if (window.ORANGE_PT_DEPT_STEP_ENABLED === true) {
            orangeApplyProductTypeDepartmentFilter(true);
        }
        orangeSyncLegacyFieldsFromProductType();
        orangeApplySizeFamilySchemeFilter();
        if (window.ORANGE_CATALOG_NAV_UNIFIED) {
            updateProductCatalogHint();
        }
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
