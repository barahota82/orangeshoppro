<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$adminCountryId = orange_admin_context_country_id($pdo);
$prodMoney = orange_admin_currency_context($pdo);
$productsCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $adminCountryId);

$catalogNavUnified = orange_catalog_nav_use_unified($pdo);
$ptDefaultAdvGuideMap = orange_catalog_product_type_default_advisory_guide_map($pdo);

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
/**
 * إظهار خطوة «القسم أولاً» وتصفية الأنواع حسب القسم عند: التصنيف الموحّد مفعّل، أو وُجدت أقسام مرتبطة بأنواع المنتج في الاستعلام.
 * (لا نخفي الخانة فقط لأن إعداد «الواجهة الموحّدة» على السيرفر غير مفعّل بينما البيانات فيها أقسام.)
 */
$orangeProductTypeDeptStepEnabled = $catalogNavUnified || $productTypeDepartmentsForForm !== [];
$orangeUnifiedDeptCatalogBroken = $catalogNavUnified && $productTypeDepartmentsForForm === [];

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
        WHERE 1=1' . $productsCountrySql . '
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
        WHERE 1=1' . $productsCountrySql . '
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
            WHERE 1=1' . $productsCountrySql . '
            ORDER BY p.sort_order ASC, p.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $products = $pdo->query(
            'SELECT p.*, NULL AS category_name, NULL AS catalog_category_display_id,
            NULL AS category_department_id, NULL AS department_name_ar, NULL AS department_name_en
            FROM products p
            WHERE 1=1' . $productsCountrySql . '
            ORDER BY p.sort_order ASC, p.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (
    isset($products) && is_array($products) && $products !== []
    && orange_table_has_column($pdo, 'products', 'sizing_advisory_guide_id')
    && orange_table_exists($pdo, 'advisory_sizing_guides')
) {
    $advGuideIds = [];
    foreach ($products as $pr) {
        if (!is_array($pr)) {
            continue;
        }
        $gid = (int) ($pr['sizing_advisory_guide_id'] ?? 0);
        if ($gid > 0) {
            $advGuideIds[$gid] = true;
        }
    }
    $advGuideLabelMap = [];
    if ($advGuideIds !== []) {
        $idList = array_keys($advGuideIds);
        $in = implode(',', array_map(static function ($x) {
            return (string) (int) $x;
        }, $idList));
        try {
            $gl = $pdo->query(
                "SELECT id, name_ar FROM advisory_sizing_guides WHERE id IN ($in)"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach (is_array($gl) ? $gl : [] as $gr) {
                if (!is_array($gr) || !isset($gr['id'])) {
                    continue;
                }
                $advGuideLabelMap[(int) $gr['id']] = trim((string) ($gr['name_ar'] ?? ''));
            }
        } catch (Throwable $e) {
            $advGuideLabelMap = [];
        }
    }
    foreach ($products as $k => $pr) {
        if (!is_array($pr)) {
            continue;
        }
        $gid = (int) ($pr['sizing_advisory_guide_id'] ?? 0);
        $products[$k]['_advisory_guide_label'] = $gid > 0 ? ($advGuideLabelMap[$gid] ?? ('#' . $gid)) : '';
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

$orangeAdminCardPreviewCssHref = storefront_public_path('/assets/css/main.css');
$orangeAdminCardPreviewViewLabel = t('view_product');
$orangeAdminSfProductUrlPartsForJs = [
    'channel' => orange_storefront_default_channel_slug($pdo),
    'lang' => 'ar',
];
?>
<div class="page-title">
    <h1>المنتجات</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if ($catalogNavUnified && $unifiedActiveProductsMissingPt > 0): ?>
<div class="card" style="margin-bottom:12px;background:#fff7ed;border-color:#fdba74;">
    <p style="margin:0;color:#9a3412;line-height:1.55;"><strong>تنبيه الشجرة الموحّدة:</strong> يوجد <strong><?php echo (int) $unifiedActiveProductsMissingPt; ?></strong> منتج <strong>نشط</strong> بلا <code>product_type_id</code> صالح. وفق السياسة يجب ربط كل منتج بـ <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=product_types'), ENT_QUOTES, 'UTF-8'); ?>">نوع منتج</a> في الشجرة قبل الاعتماد الكامل؛ راجع الصفوف في الجدول أدناه.</p>
</div>
<?php endif; ?>

<div class="card">
    <h3 id="productFormTitle">إضافة / تعديل منتج</h3>
    <p id="productEditHint" style="display:none;margin:0 0 12px;color:#555;font-size:14px;">تعديل البيانات الأساسية. الترتيب في المتجر من الجدول فقط (↑↓ ثم حفظ الترتيب). كميات الألوان والمقاسات من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=stock'), ENT_QUOTES, 'UTF-8'); ?>">المخزون</a>.</p>
    <form id="productForm">
        <style id="orangeProductsTabsNoGapFix">
            #productForm > .admin-product-tab-panels {
                display: block !important;
                min-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            #productForm > .admin-product-tab-panels > .admin-product-tab-panel {
                display: none !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            #productForm > .admin-product-tab-panels > .admin-product-tab-panel.is-active {
                display: block !important;
            }
            #productForm > .admin-product-tab-panels > .admin-product-tab-panel > .admin-product-section {
                margin-top: 0 !important;
                padding-top: 0 !important;
            }
        </style>
        <input type="hidden" id="product_record_id" value="0">
        <input type="hidden" id="category_id" value="">
        <input type="hidden" id="subcategory_id" value="">

        <div class="admin-product-tabs" role="tablist" aria-label="أقسام نموذج المنتج">
            <button type="button" class="admin-product-tab is-active" role="tab" id="productTabBtnBasic" aria-controls="productTabPanelBasic" aria-selected="true" data-product-tab="basic">البيانات الأساسية</button>
            <button type="button" class="admin-product-tab" role="tab" id="productTabBtnSizes" aria-controls="productTabPanelSizes" aria-selected="false" data-product-tab="sizes">الألوان</button>
            <button type="button" class="admin-product-tab" role="tab" id="productTabBtnImages" aria-controls="productTabPanelImages" aria-selected="false" data-product-tab="images">صور المنتج العامة</button>
            <button type="button" class="admin-product-tab" role="tab" id="productTabBtnVariants" aria-controls="productTabPanelVariants" aria-selected="false" data-product-tab="variants">المتغيرات والباركود</button>
            <button type="button" class="admin-product-tab" role="tab" id="productTabBtnAttributes" aria-controls="productTabPanelAttributes" aria-selected="false" data-product-tab="attributes">سمات المنتج</button>
            <button type="button" class="admin-product-tab" role="tab" id="productTabBtnDescription" aria-controls="productTabPanelDescription" aria-selected="false" data-product-tab="description">وصف المنتج</button>
            <button type="button" class="admin-product-tab" role="tab" id="productTabBtnCardPreview" aria-controls="productTabPanelCardPreview" aria-selected="false" data-product-tab="cardpreview">معاينة كارت المنتج</button>
        </div>

        <div class="admin-product-tab-panels">
        <div id="productTabPanelBasic" class="admin-product-tab-panel is-active" role="tabpanel" aria-labelledby="productTabBtnBasic">
        <div class="admin-product-section">
        <h4 class="admin-product-subsection-title">البيانات الأساسية</h4>
        <div class="product-form-tab-basic-layout">
            <div class="product-form-basic-top3">
                <div class="form-grid product-form-basic-top3-inner">
                    <div class="admin-sort-field-wrap">
                        <label>الترتيب (في المتجر)</label>
                        <input type="text" id="product_sort_order" class="admin-sort-field admin-sort-field--muted" value="<?php echo (int)$nextProductSort; ?>" readonly tabindex="-1" autocomplete="off" inputmode="numeric" dir="ltr" lang="en">
                    </div>
                    <div>
                        <label>مسار الشجرة الموحّدة (مقتطف)</label>
                        <div id="product_department_hint" class="product-basic-field-like">—</div>
                    </div>
                </div>
                <?php if ($orangeProductTypeDeptStepEnabled && $orangeUnifiedDeptCatalogBroken): ?>
                <div style="margin-top:12px;padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;font-size:13px;line-height:1.5;">
                    التصنيف الموحّد مفعّل لكن لا تظهر <strong>أقسام رئيسية</strong> مربوطة بأنواع المنتج (سلسلة departments ← الأقسام ← … ← الأنواع).
                    لن يُسمح بإضافة منتج من هذه الشاشة حتى يُكمل الربط في القاعدة؛ راجع الترحيل والجداول أو استعلام أنواع المنتج مع <code>department_id</code>.
                </div>
                <?php endif; ?>
                <div class="form-grid product-basic-class-row product-form-basic-top3-inner" style="margin-top:12px;">
                    <?php if ($orangeProductTypeDeptStepEnabled): ?>
                    <div class="product-basic-class-cell">
                        <label for="product_main_department_id">القسم الرئيسي</label>
                        <select id="product_main_department_id" required<?php echo $orangeUnifiedDeptCatalogBroken ? ' disabled' : ''; ?>>
                            <option value="">— اختر القسم الرئيسي —</option>
                            <?php foreach ($productTypeDepartmentsForForm as $ptDep): ?>
                                <option value="<?php echo (int) ($ptDep['id'] ?? 0); ?>"><?php echo htmlspecialchars((string) ($ptDep['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div id="product_type_block" class="product-basic-class-cell"<?php echo $orangeProductTypeDeptStepEnabled ? '' : ' style="grid-column:1 / -1"'; ?>>
                        <label for="product_type_id">نوع المنتج</label>
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
                </div>
                <div class="form-grid product-form-basic-top3-inner" style="margin-top:12px;">
                    <div>
                        <label>اسم المنتج (العربي)</label>
                        <input type="text" id="name" required>
                    </div>
                    <div>
                        <label>English</label>
                        <input type="text" id="name_en" required>
                    </div>
                </div>
                <div class="form-grid product-form-basic-top3-inner" style="margin-top:12px;">
                    <div>
                        <label>Filipino</label>
                        <input type="text" id="name_fil" required>
                    </div>
                    <div>
                        <label>Hindi</label>
                        <input type="text" id="name_hi" required>
                    </div>
                </div>
                <div class="form-grid form-grid-3 product-form-basic-top3-inner" style="margin-top:12px;">
                    <div>
                        <label>السعر</label>
                        <input type="number" id="price" class="admin-inp-money" step="any" min="0" required inputmode="decimal" lang="en" dir="ltr">
                    </div>
                    <div>
                        <label>التكلفة</label>
                        <input type="number" id="cost" class="admin-inp-money" step="any" min="0" required inputmode="decimal" lang="en" dir="ltr">
                    </div>
                    <div class="product-basic-class-cell">
                        <label for="product_is_active">حالة العرض</label>
                        <select id="product_is_active">
                            <option value="1">نشط</option>
                            <option value="0">مخفي</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid form-grid-3 product-basic-class-row product-form-basic-top3-inner" style="margin-top:12px;">
                    <div id="product_basic_size_family_wrap" class="product-basic-class-cell">
                        <label for="size_family_id">عائلة المقاسات</label>
                        <select id="size_family_id">
                            <option value="">— بلا مقاسات (اتركها فارغة) —</option>
                            <?php foreach ($familiesOut as $f): ?>
                                <?php
                                $famSch = htmlspecialchars(trim((string) ($f['size_scheme_key'] ?? '')), ENT_QUOTES, 'UTF-8');
                                $famCk = htmlspecialchars(trim((string) ($f['commercial_kind_key'] ?? '')), ENT_QUOTES, 'UTF-8');
                                $famSk = htmlspecialchars(trim((string) ($f['sizing_category_key'] ?? '')), ENT_QUOTES, 'UTF-8');
                                ?>
                                <option value="<?php echo (int)$f['id']; ?>" data-size-scheme="<?php echo $famSch; ?>" data-commercial-kind="<?php echo $famCk; ?>" data-sizing-category="<?php echo $famSk; ?>"><?php echo htmlspecialchars($f['name_ar'] ?: $f['name_en']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="product_basic_size_guide_wrap" class="product-basic-class-cell">
                        <label for="sizing_advisory_guide_id">دليل المقاس الاسترشادي (عرض)</label>
                        <select id="sizing_advisory_guide_id" disabled>
                            <option value="0">بدون</option>
                        </select>
                    </div>
                    <div class="product-basic-class-cell" id="product_basic_has_colors_wrap">
                        <label for="has_colors">له ألوان ؟</label>
                        <select id="has_colors" onchange="onHasFlagsChange({ clearGeneratedMatrix: true })">
                            <option value="0">لا</option>
                            <option value="1">نعم</option>
                        </select>
                    </div>
                </div>

                <div id="product_size_pick_panel" class="card admin-nested-panel" style="display:none;margin-top:12px;">
                    <h4 class="admin-nested-panel__title">مقاسات المنتج (بدون ألوان)</h4>
                    <p class="card-hint" style="margin:0 0 10px;font-size:13px;line-height:1.55;">
                        عندما يكون المنتج <strong>بمقاسات فقط دون ألوان</strong>، حدّد المقاسات من العائلة التي اخترتها أعلاه ثم <strong>توليد المتغيرات</strong> من تبويب المتغيرات.
                        إذا كان المنتج <strong>له ألوان</strong>، تُحدَّد المقاسات <strong>لكل صف لون</strong> من تبويب <strong>الألوان</strong>.
                    </p>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;align-items:center;">
                        <button type="button" class="btn-secondary" style="font-size:12px;padding:4px 10px;" onclick="orangeSizePickSetAll(true)">تحديد الكل</button>
                        <button type="button" class="btn-secondary" style="font-size:12px;padding:4px 10px;" onclick="orangeSizePickSetAll(false)">إلغاء الكل</button>
                    </div>
                    <div id="product_size_pick_checkboxes" class="product-size-pick-grid"></div>
                    <p id="product_size_pick_empty" style="display:none;margin:8px 0 0;color:#9a3412;font-size:13px;">لا توجد مقاسات نشطة في هذه العائلة — راجع <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=size_families'), ENT_QUOTES, 'UTF-8'); ?>">عائلات المقاسات</a>.</p>
                </div>

                <div class="form-grid product-form-basic-top3-inner" style="margin-top:12px;">
                    <div>
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                            <label for="product_item_code" style="margin:0;">كود الصنف</label>
                            <button type="button" class="btn-secondary" style="font-size:12px;padding:4px 10px;" onclick="orangeCopyProductField('product_item_code')">نسخ</button>
                        </div>
                        <input type="text" id="product_item_code" maxlength="64" autocomplete="off" dir="ltr" lang="en" placeholder="يُولَّد عند الحفظ" readonly>
                    </div>
                    <div>
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                            <label for="product_barcode" style="margin:0;">الباركود</label>
                            <button type="button" class="btn-secondary" style="font-size:12px;padding:4px 10px;" onclick="orangeCopyProductField('product_barcode')">نسخ</button>
                        </div>
                        <input type="text" id="product_barcode" maxlength="64" autocomplete="off" dir="ltr" lang="en" placeholder="يُولَّد بعد الحفظ" readonly>
                    </div>
                </div>
            </div>
        </div>
        </div>
        </div>

        <div id="productTabPanelSizes" class="admin-product-tab-panel" role="tabpanel" aria-labelledby="productTabBtnSizes" hidden>
        <div class="admin-product-section">
        <h4 class="admin-product-subsection-title">الألوان</h4>
        <?php if ($colors === []): ?>
        <p style="margin:0 0 12px;padding:10px 12px;background:#fff7ed;border:1px solid #fdba74;border-radius:8px;color:#9a3412;font-size:13px;">قاموس <strong>الألوان</strong> فارغ — لن تظهر خيارات في خلطات اللون. أضف ألواناً من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=color_dictionary'), ENT_QUOTES, 'UTF-8'); ?>">قاموس الألوان</a> قبل تفعيل «له ألوان؟».</p>
        <?php endif; ?>
        <?php if ($patterns === [] && orange_table_exists($pdo, 'pattern_dictionary')): ?>
        <p style="margin:0 0 12px;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;color:#64748b;font-size:13px;">قاموس <strong>الأنماط</strong> بلا صفوف نشطة — يمكنك المتابعة بألوان فقط، أو إضافة أنماط من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=pattern_dictionary'), ENT_QUOTES, 'UTF-8'); ?>">أنماط الألوان</a>.</p>
        <?php endif; ?>
        <p class="card-hint" style="margin:0 0 12px;font-size:13px;line-height:1.55;color:#64748b;">يُضبَط <strong>له ألوان ؟</strong> من تبويب <strong>البيانات الأساسية</strong> (صف: عائلة المقاسات، دليل المقاس الاسترشادي، له ألوان ؟). قائمة <strong>مقاسات المنتج (بدون ألوان)</strong> تظهر هناك عند اختيار عائلة مقاسات مع «لا» للألوان.</p>

        <div id="colorwaysSection" class="card admin-nested-panel" style="display:none;">
            <h4 class="admin-nested-panel__title">تركيبات اللون (أساسي / ثانوي اختياري)</h4>
            <p id="colorways_sizes_hint" class="card-hint" style="display:none;margin:0 0 10px;font-size:13px;line-height:1.55;">
                لكل صف لون: حدّد بالأسفل <strong>المقاسات المتاحة لهذا اللون</strong> من عائلة المقاسات المختارة في البيانات الأساسية. المقاسات الجديدة في العائلة تظهر هنا تلقائياً بعد تحديث الصفحة.
                الصفوف المسجّلة مسبقاً تظهر باهتة؛ إن وُجد <strong>مخزون</strong> على متغير لا يُلغى اختياره من هنا.
            </p>
            <div id="colorwaysBox"></div>
            <button type="button" class="btn-secondary" onclick="addColorwayRow()">+ صف لون</button>
        </div>
        </div>
        </div>

        <div id="productTabPanelImages" class="admin-product-tab-panel" role="tabpanel" aria-labelledby="productTabBtnImages" hidden>
        <div class="admin-product-section">
        <h4 class="admin-product-subsection-title">صور المنتج العامة</h4>
        <p class="card-hint" style="margin:0 0 12px;font-size:13px;line-height:1.55;color:#64748b;">صور <strong>المنتج العامة</strong> (رئيسية + معرض) للقوائم والاحتياط. إن كان المنتج <strong>له ألوان</strong> يمكن إضافة <strong>معرض لكل لون</strong> من تبويب <strong>الألوان</strong> أسفل صف اللون — وإلا يُعرض في المتجر نفس المعرض العام.</p>
        <div class="form-grid">
            <div style="grid-column:1/-1;">
                <label>الصورة الرئيسية — رفع ملف</label>
                <input type="hidden" id="main_image" value="">
                <input type="file" id="main_image_file" accept="image/jpeg,image/png,image/webp,image/gif">
                <button type="button" class="btn-secondary" id="btn_clear_main_image_file_selection" style="margin-top:6px;display:none;">مسح اختيار الملف (قبل الرفع)</button>
                <button type="button" class="btn-secondary" style="margin-top:8px;" onclick="uploadMainProductImage()">رفع الصورة الرئيسية</button>
                <div id="main_image_preview_row" class="admin-main-image-preview-row" style="display:none;margin-top:12px;">
                    <div id="main_image_preview" class="admin-main-image-preview-mount"></div>
                    <div class="admin-main-image-preview-actions">
                        <button type="button" class="btn-secondary" id="btn_remove_main_product_image">إزالة الصورة الرئيسية</button>
                        <span class="admin-main-image-preview-hint card-hint" style="display:block;margin-top:6px;font-size:12px;">تُزال التعيين كرئيسية فقط؛ إن وُجدت صور في المعرض تُختار أولها تلقائياً إن أمكن.</span>
                    </div>
                </div>
            </div>
            <div style="grid-column:1/-1;">
                <label>صور إضافية للمعرض (عدة ملفات)</label>
                <input type="file" id="gallery_files" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                <button type="button" class="btn-secondary" id="btn_clear_gallery_file_selection" style="margin-top:6px;display:none;">مسح اختيار الملفات (قبل الرفع)</button>
                <ul id="gallery_files_pending_list" class="admin-product-gallery-upload-list admin-product-gallery-pending-list" style="margin:10px 0 0;padding:0;list-style:none;display:flex;flex-wrap:wrap;gap:10px;"></ul>
                <button type="button" class="btn-secondary" style="margin-top:8px;" onclick="uploadGalleryProductImages()">رفع صور المعرض</button>
                <ul id="gallery_upload_list" class="admin-product-gallery-upload-list" style="margin:12px 0 0;padding:0;list-style:none;display:flex;flex-wrap:wrap;gap:10px;"></ul>
            </div>
        </div>
        </div>
        </div>

        <div id="productTabPanelVariants" class="admin-product-tab-panel" role="tabpanel" aria-labelledby="productTabBtnVariants" hidden>
        <div class="admin-product-section">
        <h4 class="admin-product-subsection-title">المتغيرات والباركود</h4>
        <p class="card-hint" style="margin:0 0 12px;font-size:13px;line-height:1.55;color:#64748b;">كل منتج — بما فيه <strong>بدون ألوان وبدون مقاسات</strong> — يحتاج <strong>صف بيع واحد على الأقل</strong> في الجدول أدناه؛ يظهر <strong>باركود المتغير</strong> بعد الحفظ. <strong>منتج جديد:</strong> أكمل البيانات في التبويبات ثم اضغط «توليد المتغيرات»؛ بعد ظهور الجدول يُفعّل «حفظ المنتج». المنتج البسيط = صف واحد في الجدول بعد التوليد.</p>
        <div id="variantsBox"></div>
        </div>
        </div>

        <div id="productTabPanelAttributes" class="admin-product-tab-panel" role="tabpanel" aria-labelledby="productTabBtnAttributes" hidden>
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

        <div id="productTabPanelDescription" class="admin-product-tab-panel" role="tabpanel" aria-labelledby="productTabBtnDescription" hidden>
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
                <p class="card-hint" style="margin:0 0 8px;font-size:13px;line-height:1.55;color:#64748b;">
                    للمنتجات المهمة: اكتب عنواناً ووصفاً مخصّصين لنتائج Google ومشاركة الرابط.
                    <strong>اترك الحقل فارغاً</strong> ليُملأ تلقائياً من اسم المنتج ووصفه عند الحفظ وفي المتجر.
                </p>
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
            <div id="seoEffectivePreviewWrap" class="card-hint" style="grid-column:1/-1;margin:0;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;line-height:1.55;">
                <strong>معاينة SEO الفعلية (عربي):</strong>
                <div id="seoEffectivePreviewTitle" style="margin-top:6px;color:#0f172a;"></div>
                <div id="seoEffectivePreviewDesc" style="margin-top:4px;color:#475569;"></div>
            </div>
        </div>
        </div>
        </div>

        <div id="productTabPanelCardPreview" class="admin-product-tab-panel" role="tabpanel" aria-labelledby="productTabBtnCardPreview" hidden>
        <div class="admin-product-section">
        <h4 class="admin-product-subsection-title">محاكاة بصرية لكارت وصفحة المنتج</h4>
        <p class="card-hint" style="margin:0 0 12px;font-size:13px;line-height:1.55;color:#64748b;">هذه محاكاة داخل الأدمن بنفس أصناف المتجر (<code>main.css</code>) وتُحدَّث مباشرة من حقول النموذج قبل الحفظ. يمكنك التنقّل بين: <strong>كارت القائمة</strong>، <strong>صفحة المنتج</strong>، و<strong>عرض موبايل</strong>، أو تشغيل <strong>محاكاة شاملة</strong> (قائمة + صفحة منتج). بعد الحفظ يظهر رابط الصفحة الحقيقية كما للزائر.</p>
        <div class="admin-product-preview-mode-row" style="margin:0 0 12px;">
            <label for="orangeAdminProductPreviewMode" style="margin:0;">وضع المحاكاة</label>
            <select id="orangeAdminProductPreviewMode">
                <option value="flow">محاكاة شاملة (قائمة + صفحة المنتج)</option>
                <option value="card">كارت القائمة فقط</option>
                <option value="product">صفحة المنتج (سطح مكتب)</option>
                <option value="mobile">صفحة المنتج (موبايل)</option>
            </select>
            <button type="button" class="btn-secondary" id="orangeAdminProductPreviewRefreshNow">تحديث الآن</button>
        </div>
        <p id="orangeAdminProductFullPreviewWrap" class="card-hint" style="margin:0 0 12px;display:none;font-size:13px;">
            <a id="orangeAdminProductFullPreviewLink" class="btn-secondary" href="#" target="_blank" rel="noopener noreferrer">فتح صفحة المنتج كاملة في المتجر (كما للزائر)</a>
            <span style="display:block;margin-top:6px;color:#64748b;font-size:12px;">يستخدم القناة الافتراضية واللغة العربية في الرابط؛ إن لم يطابق رابطك المعتاد عدّل القناة من شاشة القنوات أو افتح المنتج من الواجهة.</span>
        </p>
        <div class="admin-product-card-preview-frame-wrap">
            <iframe id="orangeAdminProductCardPreviewFrame" class="admin-product-card-preview-frame" title="معاينة كارت المنتج في المتجر"></iframe>
        </div>
        </div>
        </div>
        </div>

        <div class="admin-product-form-actions admin-product-form-actions--bar">
            <div class="actions admin-product-form-actions__buttons">
                <button type="button" class="btn-secondary" id="btnProductTranslate" onclick="translateProductLocalesFromArabic()">ترجمة تلقائية من العربي</button>
                <button type="button" class="btn" id="btnGenerateVariants" onclick="generateVariants()" disabled>توليد المتغيرات</button>
                <button type="button" class="btn-secondary" id="btnSaveProduct" onclick="saveProduct()" disabled>حفظ المنتج</button>
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
                    <th>دليل استرشادي</th>
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
                $pCatId = isset($p['catalog_category_display_id']) ? (int) $p['catalog_category_display_id'] : 0;
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
                    <td><?php
                    $agl = trim((string) ($p['_advisory_guide_label'] ?? ''));
                    echo htmlspecialchars($agl !== '' ? $agl : 'بدون', ENT_QUOTES, 'UTF-8');
                    ?></td>
                    <td><?php echo number_format((float) $p['price'], $prodMoney['decimals']); ?></td>
                    <td><?php echo number_format((float) $p['cost'], $prodMoney['decimals']); ?></td>
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
window.ORANGE_ADMIN_CURRENCY_UNIT = <?php echo json_encode($prodMoney['unit'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORANGE_COLORS = <?php echo json_encode($colors, JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_PATTERNS = <?php echo json_encode($patterns, JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_FAMILIES = <?php echo json_encode($familiesOut, JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_SUBCATEGORIES = <?php echo json_encode($subcategoriesForJs, JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_CATEGORY_META = <?php echo json_encode($categoryCatalogMeta, JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_CATALOG_NAV_UNIFIED = <?php echo $catalogNavUnified ? 'true' : 'false'; ?>;
window.ORANGE_PT_DEPT_STEP_ENABLED = <?php echo $orangeProductTypeDeptStepEnabled ? 'true' : 'false'; ?>;
window.ORANGE_PT_DEPT_OPTIONS_COUNT = <?php echo (int) count($productTypeDepartmentsForForm); ?>;
window.ORANGE_PRODUCT_TYPE_TRAIL = <?php echo json_encode($productTypeTrailsForJs, JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_PT_DEFAULT_ADV_GUIDE = <?php echo json_encode($ptDefaultAdvGuideMap, JSON_UNESCAPED_UNICODE); ?>;
window.PRODUCT_EXTRA_IMAGES = [];
window.ORANGE_PRODUCT_VARIANTS_READY_FOR_SAVE = false;
window.ORANGE_MAIN_PENDING_IMAGE_URL = null;
window.ORANGE_GALLERY_PENDING_URLS = [];
window.PRODUCT_NEXT_SORT = <?php echo (int)$nextProductSort; ?>;
window.ORANGE_CATALOG_ATTR_DEFS = <?php echo json_encode($catalogAttrDefsForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
window.ORANGE_ADMIN_CARD_PREVIEW_CSS = <?php echo json_encode($orangeAdminCardPreviewCssHref, JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_ADMIN_VIEW_PRODUCT_LABEL = <?php echo json_encode($orangeAdminCardPreviewViewLabel, JSON_UNESCAPED_UNICODE); ?>;
window.ORANGE_ADMIN_SF_PRODUCT_URL_PARTS = <?php echo json_encode($orangeAdminSfProductUrlPartsForJs, JSON_UNESCAPED_UNICODE); ?>;

const PRODUCT_MSG = {
    E_REORDER: 'بيانات الترتيب غير صحيحة',
    OK_REORDER: 'تم حفظ ترتيب المنتجات',
    OK_TOG: 'تم تحديث الحالة'
};

/** اعتماد التصنيف الموحّد فقط: التلميح يُحدَّث من الورقة المختارة. */
function orangeProductApplyDefaultAdvisoryFromProductType(onlyIfEmpty) {
    const advEl = document.getElementById('sizing_advisory_guide_id');
    const ptEl = document.getElementById('product_type_id');
    if (!advEl || advEl.disabled || !ptEl) {
        return;
    }
    const cur = parseInt(String(advEl.value || '0'), 10) || 0;
    if (onlyIfEmpty && cur > 0) {
        return;
    }
    const ptId = parseInt(String(ptEl.value || '0'), 10) || 0;
    if (ptId <= 0) {
        return;
    }
    const map = window.ORANGE_PT_DEFAULT_ADV_GUIDE || {};
    const gid = parseInt(String(map[ptId] != null ? map[ptId] : (map[String(ptId)] != null ? map[String(ptId)] : '0')), 10) || 0;
    if (gid <= 0) {
        return;
    }
    const hit = advEl.querySelector('option[value="' + gid + '"]');
    if (hit) {
        advEl.value = String(gid);
    }
}

function orangeSyncLegacyFieldsFromProductType() {
    updateProductCatalogHint();
    const famEl = document.getElementById('size_family_id');
    const famId = famEl ? (parseInt(String(famEl.value || '0'), 10) || 0) : 0;
    if (famId > 0 && orangeProductEffectiveHasSizes()) {
        void orangeProductRefreshAdvisoryGuideSelect(0);
    }
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
        orangeApplyProductBasicStepLocks();
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
    orangeApplyProductBasicStepLocks();
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

/** صنف له مقاسات عندما تُختار عائلة مقاسات (لا يعتمد على خانة منفصلة). */
function orangeProductEffectiveHasSizes() {
    const famSel = document.getElementById('size_family_id');
    if (!famSel) {
        return false;
    }
    const v = parseInt(String(famSel.value || '0'), 10) || 0;
    return v > 0;
}

function orangeProductBasicRecordIsEdit() {
    const r = document.getElementById('product_record_id');
    return r ? (parseInt(String(r.value || '0'), 10) || 0) > 0 : false;
}

function orangeProductBasicDeptOk() {
    if (window.ORANGE_PT_DEPT_STEP_ENABLED !== true) {
        return true;
    }
    const d = document.getElementById('product_main_department_id');
    if (!d) {
        return true;
    }
    if (d.disabled) {
        return true;
    }
    return (parseInt(String(d.value || '0'), 10) || 0) > 0;
}

function orangeProductBasicTypeOk() {
    const pt = document.getElementById('product_type_id');
    return !!(pt && !pt.disabled && (parseInt(String(pt.value || '0'), 10) || 0) > 0);
}

function orangeProductBasicPriceOk() {
    const pe = document.getElementById('price');
    const ce = document.getElementById('cost');
    if (!pe || !ce) {
        return false;
    }
    const ps = String(pe.value || '').trim();
    const cs = String(ce.value || '').trim();
    if (ps === '' || cs === '') {
        return false;
    }
    const p = parseFloat(ps);
    const c = parseFloat(cs);
    return !isNaN(p) && !isNaN(c) && p >= 0 && c >= 0;
}

/** منتج بسيط: بلا ألوان وبلا مقاسات — صف بيع واحد (باركود بعد الحفظ). */
function orangeProductIsSimpleSkuMatrix() {
    const hcEl = document.getElementById('has_colors');
    if (hcEl && String(hcEl.value || '') === '1') {
        return false;
    }
    return !orangeProductEffectiveHasSizes();
}

/** منتج جديد (لم يُحفظ بعد) — يُستخدم لتسلسل «توليد المتغيرات» ثم «الحفظ». */
function orangeProductWizardIsNew() {
    const id = parseInt(String(document.getElementById('product_record_id') && document.getElementById('product_record_id').value || '0'), 10) || 0;
    return id <= 0;
}

function orangeProductInvalidateVariantsReadyForSave() {
    if (orangeProductWizardIsNew()) {
        window.ORANGE_PRODUCT_VARIANTS_READY_FOR_SAVE = false;
        orangeApplyProductWizardActionButtons();
    }
}

/**
 * عند تغيير إعدادات تكوين المتغيرات قبل الحفظ (ألوان/مقاسات) يجب
 * إلغاء مصفوفة التوليد السابقة وإجبار إعادة التوليد من المعطيات الجديدة.
 */
function orangeProductClearGeneratedVariantsMatrixIfNeeded() {
    if (!orangeProductWizardIsNew()) {
        return;
    }
    const box = document.getElementById('variantsBox');
    if (!box) {
        return;
    }
    if (!box.querySelector('tbody tr')) {
        return;
    }
    box.innerHTML = '';
}

/**
 * تحقق موحّد من اكتمال البيانات قبل توليد المتغيرات أو الحفظ (بدون اشتراط جدول المتغيرات).
 * يُستعمل لتفعيل زر «توليد المتغيرات» وللتحقق قبل الحفظ — أي نفس الشروط لما قبل المصفوفة.
 * @returns {null|{tab:string,message:string}}
 */
function orangeProductValidateWizardBeforeMatrix() {
    const nameFields = [
        { id: 'name', label: 'الاسم العربي' },
        { id: 'name_en', label: 'English' },
        { id: 'name_fil', label: 'Filipino' },
        { id: 'name_hi', label: 'Hindi' }
    ];
    for (let i = 0; i < nameFields.length; i++) {
        const f = nameFields[i];
        if (!document.getElementById(f.id).value.trim()) {
            return { tab: 'basic', message: 'يجب إضافة خانة ' + f.label + ' قبل المتابعة.' };
        }
    }

    if (window.ORANGE_PT_DEPT_STEP_ENABLED === true) {
        const depOptCount = parseInt(String(window.ORANGE_PT_DEPT_OPTIONS_COUNT || '0'), 10) || 0;
        if (depOptCount <= 0) {
            return {
                tab: 'basic',
                message:
                    'التصنيف الموحّد مفعّل لكن لا توجد أقسام مربوطة بأنواع المنتج في القاعدة — أصلح الربط ثم أعد تحميل الصفحة.'
            };
        }
        const depSaveEl = document.getElementById('product_main_department_id');
        const depSaveVal = depSaveEl && !depSaveEl.disabled ? (parseInt(depSaveEl.value || '0', 10) || 0) : 0;
        if (depSaveVal <= 0) {
            return { tab: 'basic', message: 'يجب اختيار «القسم الرئيسي» أولاً، ثم نوع المنتج التابع لهذا القسم.' };
        }
    }
    if (!orangeProductBasicTypeOk()) {
        if (window.ORANGE_PT_DEPT_STEP_ENABLED === true) {
            return { tab: 'basic', message: 'اختر «القسم الرئيسي» ثم «نوع المنتج» من الأنواع المعروضة لهذا القسم فقط.' };
        }
        return { tab: 'basic', message: 'يجب اختيار «نوع المنتج» قبل المتابعة.' };
    }

    if (!orangeProductBasicPriceOk()) {
        return { tab: 'basic', message: 'أدخل السعر والتكلفة (أرقام ≥ 0) قبل المتابعة.' };
    }

    const hsCheck = orangeProductEffectiveHasSizes();
    if (hsCheck) {
        const sfam = document.getElementById('size_family_id');
        const sfamId = sfam ? (parseInt(sfam.value || '0', 10) || 0) : 0;
        if (sfamId <= 0) {
            return { tab: 'basic', message: 'اختر عائلة مقاسات من البيانات الأساسية.' };
        }
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
                    return {
                        tab: 'basic',
                        message:
                            'عائلة المقاسات لا تطابق نطاق هرَم نوع المنتج (النوع التجاري «' +
                            expKind +
                            '» / فئة «' +
                            expCat +
                            '»).'
                    };
                }
            }
        }
    }

    const hasColorsUi = document.getElementById('has_colors') && document.getElementById('has_colors').value === '1';
    const hsForSave = orangeProductEffectiveHasSizes();
    if (hasColorsUi) {
        const cwRows = document.querySelectorAll('#colorwaysBox .cw-row');
        let hasValidColorway = false;
        cwRows.forEach(function (row) {
            const pEl = row.querySelector('.cw-p');
            const pv = pEl ? parseInt(pEl.value || '0', 10) || 0 : 0;
            if (pv > 0) {
                hasValidColorway = true;
            }
        });
        if (!hasValidColorway) {
            return {
                tab: 'sizes',
                message: 'الصنف بخيار «له ألوان ؟ = نعم»: أضف صف لوناً واختر لوناً أساسياً من القاموس قبل المتابعة.'
            };
        }
        if (hsForSave) {
            let allSized = true;
            cwRows.forEach(function (row) {
                const pEl = row.querySelector('.cw-p');
                const pv = pEl ? parseInt(pEl.value || '0', 10) || 0 : 0;
                if (pv <= 0) {
                    return;
                }
                const cbs = row.querySelectorAll('.cw-size-cb:checked');
                if (!cbs.length) {
                    allSized = false;
                }
            });
            if (!allSized) {
                return {
                    tab: 'sizes',
                    message: 'لكل صف لون اخترت له لوناً أساسياً: حدّد مقاساً واحداً على الأقل من قائمة المقاسات تحت الصف قبل المتابعة.'
                };
            }
        }
    } else if (hsForSave) {
        const mount = document.getElementById('product_size_pick_checkboxes');
        const hasPickUi = !!(mount && mount.querySelector('.product-size-pick-cb'));
        const checkedPick = mount ? mount.querySelectorAll('.product-size-pick-cb:checked').length : 0;
        if (hasPickUi && checkedPick < 1) {
            return {
                tab: 'basic',
                message: 'اختر مقاساً واحداً على الأقل من قائمة «مقاسات المنتج (بدون ألوان)» في البيانات الأساسية قبل المتابعة.'
            };
        }
        if (!hasPickUi) {
            const famEl = document.getElementById('size_family_id');
            const famId0 = famEl ? parseInt(famEl.value || '0', 10) || 0 : 0;
            const szList0 = famId0 ? sizesForFamily(famId0) : [];
            if (szList0.length > 0) {
                return {
                    tab: 'basic',
                    message: 'اختر مقاساً واحداً على الأقل من قائمة «مقاسات المنتج (بدون ألوان)» في البيانات الأساسية قبل المتابعة.'
                };
            }
        }
    }

    assignMainImageFromGalleryIfEmpty();
    const mainVal = document.getElementById('main_image').value.trim();
    const hasGeneralImage = !!(mainVal || (window.PRODUCT_EXTRA_IMAGES && window.PRODUCT_EXTRA_IMAGES.length));
    let hasColorwayUploadedImage = false;
    if (hasColorsUi) {
        document.querySelectorAll('#colorwaysBox .cw-row').forEach(function (row) {
            const pEl = row.querySelector('.cw-p');
            const pv = pEl ? parseInt(pEl.value || '0', 10) || 0 : 0;
            if (pv <= 0) {
                return;
            }
            if (row.querySelector('.cw-gallery-list li[data-fn]')) {
                hasColorwayUploadedImage = true;
            }
        });
    }
    if (!hasGeneralImage && !hasColorwayUploadedImage) {
        if (hasColorsUi) {
            return {
                tab: 'sizes',
                message:
                    'لا توجد صورة عامة للمنتج: إمّا ترفع صورة من تبويب «صور المنتج العامة»، أو ترفع صورة خاصة بأحد ألوان المنتج من تبويب «الألوان» (تحت صف اللون) قبل المتابعة.'
            };
        }
        return { tab: 'images', message: 'ارفع صورة واحدة على الأقل (رئيسية أو معرض) قبل المتابعة.' };
    }

    return null;
}

function orangeApplyCatalogAttributeLocks() {
    const edit = orangeProductBasicRecordIsEdit();
    const typeOk = orangeProductBasicTypeOk();
    const unlocked = edit || typeOk;
    const addBtn = document.getElementById('orangeCatalogAttrAddRowBtn');
    if (addBtn) {
        addBtn.disabled = !unlocked;
    }
    document.querySelectorAll('#orangeCatalogAttrRows .orange-pav-dynamic-row').forEach(function (row) {
        const typeSel = row.querySelector('.orange-pav-type-select');
        const tid = typeSel ? (parseInt(typeSel.value || '0', 10) || 0) : 0;
        if (typeSel) {
            typeSel.disabled = !unlocked;
        }
        row.querySelectorAll('.orange-pav-value-input').forEach(function (inp) {
            if (!unlocked) {
                inp.disabled = true;
                return;
            }
            inp.disabled = tid <= 0;
        });
        const rm = row.querySelector('.orange-pav-row-remove');
        if (rm) {
            rm.disabled = !unlocked;
        }
    });
    orangeCatalogAttrUpdateRemoveButtons();
    if (!unlocked) {
        document.querySelectorAll('#orangeCatalogAttrRows .orange-pav-row-remove').forEach(function (btn) {
            btn.disabled = true;
        });
    }
}

/** أزرار «توليد المتغيرات» / «حفظ المنتج» لمنتج جديد: توليد بعد اكتمال المعطيات؛ الحفظ بعد توليد ناجح. */
function orangeApplyProductWizardActionButtons() {
    const btnGen = document.getElementById('btnGenerateVariants');
    const btnSave = document.getElementById('btnSaveProduct');
    if (!btnGen || !btnSave) {
        return;
    }
    if (orangeProductBasicRecordIsEdit()) {
        btnGen.style.display = 'none';
        btnGen.disabled = true;
        btnSave.disabled = false;
        return;
    }
    btnGen.style.display = '';
    const ok = orangeProductValidateWizardBeforeMatrix() === null;
    btnGen.disabled = !ok;
    const canSave = ok && !!window.ORANGE_PRODUCT_VARIANTS_READY_FOR_SAVE;
    btnSave.disabled = !canSave;
}

/** تسلسل البيانات الأساسية: قسم ← نوع ← (عربي/إنجليزي/فلبيني/هندي + سعر + تكلفة + حالة العرض) ← بعد سعر وتكلفة صالحين ← عائلة المقاسات و«له ألوان؟» (تعديل = كل الحقول مفعّلة). */
function orangeApplyProductBasicStepLocks() {
    const edit = orangeProductBasicRecordIsEdit();
    const deptOk = orangeProductBasicDeptOk();
    const typeOk = orangeProductBasicTypeOk();
    const priceOk = orangeProductBasicPriceOk();

    const ptEl = document.getElementById('product_type_id');
    if (ptEl) {
        ptEl.disabled = edit ? false : !deptOk;
    }

    ['name', 'name_en', 'name_fil', 'name_hi', 'price', 'cost'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) {
            el.disabled = edit ? false : !typeOk;
        }
    });
    const isAct = document.getElementById('product_is_active');
    if (isAct) {
        isAct.disabled = edit ? false : !typeOk;
    }

    document.querySelectorAll('#product_size_pick_panel button[onclick^="orangeSizePickSetAll"]').forEach(function (btn) {
        btn.disabled = edit ? false : !priceOk;
    });

    onHasFlagsChange();
    orangeApplyCatalogAttributeLocks();
    orangeApplyProductWizardActionButtons();
}

function orangeApplySizeFamilySchemeFilter() {
    const famSel = document.getElementById('size_family_id');
    if (!famSel) {
        return;
    }

    const expKind = orangeGetSelectedProductTypeExpectedKind();
    const expCat = orangeGetSelectedProductTypeExpectedCat();
    const filterOn = expKind !== '' && expCat !== '';
    let currentVal = famSel.value;
    let selectedIsBad = false;

    for (let i = 0; i < famSel.options.length; i++) {
        const o = famSel.options[i];
        if (!o.value) {
            o.hidden = false;
            o.disabled = false;
            continue;
        }
        const fk = String(o.getAttribute('data-commercial-kind') || '').trim();
        const fsk = String(o.getAttribute('data-sizing-category') || '').trim();
        let ok = true;
        if (filterOn) {
            ok = fk === expKind && fsk === expCat;
        }
        if (filterOn) {
            o.hidden = !ok;
            o.disabled = false;
        } else {
            o.hidden = false;
            o.disabled = false;
        }
        if (!ok && o.value === currentVal) {
            selectedIsBad = true;
        }
    }

    if (selectedIsBad) {
        famSel.value = '';
    } else if (
        currentVal &&
        famSel.selectedOptions.length &&
        (famSel.selectedOptions[0].disabled || famSel.selectedOptions[0].hidden)
    ) {
        famSel.value = '';
    }
    orangeRefreshSizePickPanel();
    orangeRefreshAllColorwaySizePickers();
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
        orangeApplyCatalogAttributeLocks();
        orangeApplyProductWizardActionButtons();
    });
    orangeCatalogAttrUpdateRemoveButtons();
    orangeApplyCatalogAttributeLocks();
    orangeApplyProductWizardActionButtons();
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
    orangeApplyCatalogAttributeLocks();
    orangeApplyProductWizardActionButtons();
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

function adminEscHtml(s) {
    return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function orangeCopyProductField(elementId) {
    const el = document.getElementById(elementId);
    const v = el && String(el.value || '').trim();
    if (!v) {
        alert('لا توجد قيمة بعد — احفظ المنتج أولاً أو أكمل البيانات.');
        return;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(v).then(function () {
            alert('تم النسخ.');
        }).catch(function () {
            alert('تعذر النسخ من المتصفح — انسخ يدوياً.');
        });
    } else {
        alert('المتصفح لا يدعم النسخ التلقائي — انسخ يدوياً.');
    }
}

function orangeCopyVariantBarcode(btn) {
    const tr = btn && btn.closest ? btn.closest('tr') : null;
    const el = tr && tr.querySelector('.v-barcode-display');
    const v = el && String(el.textContent || '').trim();
    if (!v || v === '—') {
        alert('لا يوجد باركود لهذا الصف بعد — احفظ المنتج ثم أعد فتح التعديل أو حدّث الصفحة.');
        return;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(v).then(function () {
            alert('تم النسخ.');
        }).catch(function () {
            alert('تعذر النسخ من المتصفح — انسخ يدوياً.');
        });
    } else {
        alert('المتصفح لا يدعم النسخ التلقائي — انسخ يدوياً.');
    }
}

function adminPublicPath(path) {
    const raw = typeof window.ORANGE_PUBLIC_BASE_PATH === 'string' ? window.ORANGE_PUBLIC_BASE_PATH : '';
    const base = raw.replace(/\/+$/, '');
    const p = path.charAt(0) === '/' ? path : '/' + path;
    return base + p;
}

/** مسارات واجهات الأدمن تحت ‎/admin/‎ مع احترام ‎PUBLIC_BASE_PATH‎ (مثلاً نطاق فرعي على الاستضافة). */
function adminApiPath(path) {
    const s = String(path || '').trim().replace(/^\/+/, '');
    const tail = s.indexOf('admin/') === 0 ? s.slice('admin/'.length) : s;
    return adminPublicPath('/admin/' + tail);
}
function adminProductImageBasename(filename) {
    const fn = String(filename || '').trim();
    if (!fn) {
        return '';
    }
    const parts = fn.split(/[/\\]/);
    return parts[parts.length - 1] || '';
}

/** مسارات رفع المنتج (أصلي + webp مرافق) لمعاينات الأدمن. */
function adminProductImageUploadedUrls(filename) {
    const base = adminProductImageBasename(filename);
    if (!base) {
        return null;
    }
    const lower = base.toLowerCase();
    const prefix = adminPublicPath('/uploads/products/');
    const orig = prefix + encodeURIComponent(base);
    if (lower.endsWith('.webp')) {
        return { orig: orig, webp: null, isWebp: true };
    }
    const stem = base.indexOf('.') !== -1 ? base.slice(0, base.lastIndexOf('.')) : base;
    const webp = prefix + encodeURIComponent(stem + '.webp');
    return { orig: orig, webp: webp, isWebp: false };
}

/** HTML مصغّر لصورة مرفوعة (نفس منطق الصورة الرئيسية / تبويب الألوان). */
function adminProductUploadThumbPictureInnerHtml(filename, sizePx) {
    const u = adminProductImageUploadedUrls(filename);
    const wh = parseInt(sizePx, 10) || 48;
    if (!u) {
        return '';
    }
    const style =
        'width:' +
        wh +
        'px;height:' +
        wh +
        'px;object-fit:cover;border-radius:6px;border:1px solid #cbd5e1;flex-shrink:0;display:block;';
    if (u.isWebp) {
        return '<img alt="" loading="lazy" style="' + style + '" src="' + adminEscAttr(u.orig) + '">';
    }
    return (
        '<picture class="admin-product-upload-thumb-picture"><source type="image/webp" srcset="' +
        adminEscAttr(u.webp) +
        '"><img alt="" loading="lazy" style="' +
        style +
        '" src="' +
        adminEscAttr(u.orig) +
        '"></picture>'
    );
}

/** إلغاء تعيين الصورة الرئيسية الحالية؛ إن وُجدت صور معرض تُختار أولها كرئيسية (انظر assignMainImageFromGalleryIfEmpty). */
function orangeRemoveMainProductImageDesignation() {
    const mainEl = document.getElementById('main_image');
    if (!mainEl || !mainEl.value.trim()) {
        return;
    }
    mainEl.value = '';
    assignMainImageFromGalleryIfEmpty();
    adminSetMainImagePreview(mainEl.value.trim());
    orangeRefreshVariantReferenceThumbs();
    orangeScheduleProductCardPreviewRefresh();
    orangeProductInvalidateVariantsReadyForSave();
}

/** معاينة الصورة الرئيسية: يفضّل ‎webp‎ المرافق كما في الواجهة. */
function adminSetMainImagePreview(filename) {
    const mount = document.getElementById('main_image_preview');
    const row = document.getElementById('main_image_preview_row');
    if (!mount) {
        return;
    }
    const base = adminProductImageBasename(filename);
    if (!base) {
        mount.innerHTML = '';
        if (row) {
            row.style.display = 'none';
        }
        orangeRefreshVariantReferenceThumbs();
        orangeScheduleProductCardPreviewRefresh();
        orangeApplyProductWizardActionButtons();
        return;
    }
    const lower = base.toLowerCase();
    const prefix = adminPublicPath('/uploads/products/');
    const orig = prefix + encodeURIComponent(base);
    const style = 'max-height:160px;max-width:100%;border-radius:8px;border:1px solid #ddd;';
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
    if (row) {
        row.style.display = 'flex';
    }
    orangeRefreshVariantReferenceThumbs();
    orangeScheduleProductCardPreviewRefresh();
    orangeApplyProductWizardActionButtons();
}

/** يحدّث عمود «صورة المرجع» في جدول المتغيرات بعد رفع صور (بدون إعادة توليد). */
function orangeRefreshVariantReferenceThumbs() {
    const box = document.getElementById('variantsBox');
    if (!box) {
        return;
    }
    const rows = box.querySelectorAll('tbody tr');
    if (!rows.length) {
        return;
    }
    rows.forEach(function (tr) {
        const td = tr.querySelector('.td-ref-img');
        if (!td) {
            return;
        }
        const vp = tr.querySelector('.v-p');
        const vs = tr.querySelector('.v-s');
        const vpp = tr.querySelector('.v-pp');
        const vsp = tr.querySelector('.v-sp');
        const p = vp ? (parseInt(vp.value, 10) || 0) : 0;
        const s = vs ? (parseInt(vs.value, 10) || 0) : 0;
        const pp = vpp ? (parseInt(vpp.value, 10) || 0) : 0;
        const sp = vsp ? (parseInt(vsp.value, 10) || 0) : 0;
        td.innerHTML = adminVariantReferenceThumbHtmlForColorway(p, s, pp, sp);
    });
}

let orangeCardPreviewTimer = null;

function orangeScheduleProductCardPreviewRefresh() {
    clearTimeout(orangeCardPreviewTimer);
    orangeCardPreviewTimer = setTimeout(function () {
        orangeRefreshProductCardPreview();
    }, 420);
}

function orangeAdminProductCardPreviewTitle() {
    const ar = document.getElementById('name') && document.getElementById('name').value.trim();
    if (ar) {
        return ar;
    }
    const en = document.getElementById('name_en') && document.getElementById('name_en').value.trim();
    if (en) {
        return en;
    }
    const fil = document.getElementById('name_fil') && document.getElementById('name_fil').value.trim();
    if (fil) {
        return fil;
    }
    const hi = document.getElementById('name_hi') && document.getElementById('name_hi').value.trim();
    if (hi) {
        return hi;
    }
    return '—';
}

function orangeAdminProductCardPreviewImageSrc() {
    const mainEl = document.getElementById('main_image');
    const main = mainEl ? mainEl.value.trim() : '';
    const ex0 =
        window.PRODUCT_EXTRA_IMAGES && window.PRODUCT_EXTRA_IMAGES[0]
            ? String(window.PRODUCT_EXTRA_IMAGES[0]).trim()
            : '';
    const fn = main || ex0;
    if (!fn) {
        return '';
    }
    return adminPublicPath('/uploads/products/') + encodeURIComponent(adminProductImageBasename(fn));
}

function orangeAdminProductCardPreviewPriceFormatted() {
    const pEl = document.getElementById('price');
    const raw = pEl ? String(pEl.value || '').trim().replace(',', '.') : '';
    const n = parseFloat(raw);
    const v = Number.isFinite(n) && n >= 0 ? n : 0;
    const unit = (typeof window.ORANGE_ADMIN_CURRENCY_UNIT === 'string' && window.ORANGE_ADMIN_CURRENCY_UNIT !== '')
        ? window.ORANGE_ADMIN_CURRENCY_UNIT
        : ((window.OrangeMoney && typeof window.OrangeMoney.currencyUnit === 'function') ? window.OrangeMoney.currencyUnit() : 'KD');
    if (window.OrangeMoney && typeof window.OrangeMoney.formatAmount === 'function') {
        return window.OrangeMoney.formatAmount(v) + ' ' + unit;
    }
    return v.toFixed(2) + ' ' + unit;
}

function orangeAdminPreviewMode() {
    const sel = document.getElementById('orangeAdminProductPreviewMode');
    const mode = sel ? String(sel.value || '').trim().toLowerCase() : '';
    return (mode === 'card' || mode === 'product' || mode === 'mobile') ? mode : 'flow';
}

function orangeAdminCollectPreviewGalleryUrls() {
    const out = [];
    const seen = Object.create(null);
    const pushUrl = function (u) {
        const v = String(u || '').trim();
        if (!v || seen[v]) {
            return;
        }
        seen[v] = true;
        out.push(v);
    };
    if (window.ORANGE_MAIN_PENDING_IMAGE_URL) {
        pushUrl(window.ORANGE_MAIN_PENDING_IMAGE_URL);
    }
    const curMain = orangeAdminProductCardPreviewImageSrc();
    if (curMain) {
        pushUrl(curMain);
    }
    (window.ORANGE_GALLERY_PENDING_URLS || []).forEach(function (u) {
        pushUrl(u);
    });
    (window.PRODUCT_EXTRA_IMAGES || []).forEach(function (name) {
        const fn = String(name || '').trim();
        if (!fn) {
            return;
        }
        pushUrl(adminPublicPath('/uploads/products/') + encodeURIComponent(adminProductImageBasename(fn)));
    });
    return out;
}

function orangeAdminProductPreviewDescription() {
    const ar = document.getElementById('description') && document.getElementById('description').value.trim();
    if (ar) {
        return ar;
    }
    const en = document.getElementById('description_en') && document.getElementById('description_en').value.trim();
    if (en) {
        return en;
    }
    const fil = document.getElementById('description_fil') && document.getElementById('description_fil').value.trim();
    if (fil) {
        return fil;
    }
    const hi = document.getElementById('description_hi') && document.getElementById('description_hi').value.trim();
    if (hi) {
        return hi;
    }
    return '';
}

function orangeAdminCwRowDisplayParts(row) {
    if (!row || !row.querySelector) {
        return null;
    }
    const p = parseInt((row.querySelector('.cw-p') && row.querySelector('.cw-p').value) || '0', 10) || 0;
    if (!p) {
        return null;
    }
    const s = parseInt((row.querySelector('.cw-s') && row.querySelector('.cw-s').value) || '0', 10) || 0;
    const pp = parseInt((row.querySelector('.cw-pp') && row.querySelector('.cw-pp').value) || '0', 10) || 0;
    const sp = parseInt((row.querySelector('.cw-sp') && row.querySelector('.cw-sp').value) || '0', 10) || 0;
    const pCol = (window.ORANGE_COLORS || []).find(function (x) {
        return String(x.id) === String(p);
    });
    const sCol = (window.ORANGE_COLORS || []).find(function (x) {
        return String(x.id) === String(s);
    });
    let colorLabel = '';
    if (pCol) {
        colorLabel += pCol.name_ar || pCol.name_en || '';
    }
    if (sCol && (!pCol || String(sCol.id) !== String(pCol.id))) {
        colorLabel += (colorLabel ? ' + ' : '') + (sCol.name_ar || sCol.name_en || '');
    }
    const ppt = (window.ORANGE_PATTERNS || []).find(function (x) {
        return String(x.id) === String(pp);
    });
    const spt = (window.ORANGE_PATTERNS || []).find(function (x) {
        return String(x.id) === String(sp);
    });
    let patPhrase = '';
    if (ppt) {
        patPhrase += ppt.name_ar || ppt.name_en || '';
    }
    if (spt) {
        patPhrase += (patPhrase ? ' · ' : '') + (spt.name_ar || spt.name_en || '');
    }
    let colorOut = colorLabel || '';
    let patOut = patPhrase || '';
    if (patOut && !colorOut) {
        colorOut = patOut;
        patOut = '';
    }
    if (!colorOut && !patOut) {
        return null;
    }
    return { color: colorOut, pattern: patOut };
}

function orangeAdminProductCardPreviewVariantMetaHtml() {
    const hc = document.getElementById('has_colors') && document.getElementById('has_colors').value === '1';
    if (!hc) {
        return '';
    }
    const lines = [];
    document.querySelectorAll('#colorwaysBox .cw-row').forEach(function (row) {
        const parts = orangeAdminCwRowDisplayParts(row);
        if (!parts) {
            return;
        }
        let line = '<div class="product-card-variant-line">';
        if (parts.color) {
            line += '<span class="product-card-color">' + adminEscHtml(parts.color) + '</span>';
        }
        if (parts.pattern) {
            line += '<span class="product-card-pattern">' + adminEscHtml(parts.pattern) + '</span>';
        }
        line += '</div>';
        lines.push(line);
    });
    if (!lines.length) {
        return '';
    }
    return '<div class="product-card-variant-meta" dir="auto">' + lines.join('') + '</div>';
}

function orangeAdminProductPreviewColorOptionsHtml() {
    const hasColors = document.getElementById('has_colors') && document.getElementById('has_colors').value === '1';
    if (!hasColors) {
        return '';
    }
    const chips = [];
    const seen = Object.create(null);
    document.querySelectorAll('#colorwaysBox .cw-row').forEach(function (row) {
        const parts = orangeAdminCwRowDisplayParts(row);
        if (!parts) {
            return;
        }
        const key = String(parts.color || '') + '|' + String(parts.pattern || '');
        if (seen[key]) {
            return;
        }
        seen[key] = true;
        let chip = '<button type="button" class="chip color-chip" data-color="' + adminEscAttr(key) + '" onclick="return false;">';
        if (parts.color) {
            chip += '<span class="chip-text chip-text--color">' + adminEscHtml(parts.color) + '</span>';
        }
        if (parts.pattern) {
            chip += '<span class="chip-text chip-text--pattern">' + adminEscHtml(parts.pattern) + '</span>';
        }
        chip += '</button>';
        chips.push(chip);
    });
    if (!chips.length) {
        return '<div class="option-block"><label>اللون</label><p class="card-hint" style="margin:6px 0 0;">أضف صف لون من تبويب «الألوان» لتظهر المحاكاة بشكل كامل.</p></div>';
    }
    return '<div class="option-block"><label>اللون</label><div class="chips">' + chips.join('') + '</div></div>';
}

function orangeAdminProductPreviewSizeLabels() {
    if (!orangeProductEffectiveHasSizes()) {
        return [];
    }
    const famId = parseInt(String(document.getElementById('size_family_id') && document.getElementById('size_family_id').value || '0'), 10) || 0;
    if (famId <= 0) {
        return [];
    }
    const allSizes = sizesForFamily(famId) || [];
    const byId = Object.create(null);
    allSizes.forEach(function (sz) {
        const sid = parseInt(String(sz && sz.id != null ? sz.id : '0'), 10) || 0;
        if (sid <= 0) {
            return;
        }
        byId[sid] = String((sz.label_ar || sz.label_en || ('#' + sid)) || '');
    });
    const labels = [];
    const seen = Object.create(null);
    const addById = function (sid) {
        const id = parseInt(String(sid || '0'), 10) || 0;
        if (id <= 0 || seen[id]) {
            return;
        }
        seen[id] = true;
        labels.push(byId[id] || ('#' + id));
    };
    document.querySelectorAll('#product_size_pick_checkboxes .product-size-pick-cb:checked').forEach(function (cb) {
        addById(cb.value);
    });
    document.querySelectorAll('#colorwaysBox .cw-size-cb:checked').forEach(function (cb) {
        addById(cb.value);
    });
    if (!labels.length) {
        allSizes.forEach(function (sz) {
            addById(sz && sz.id);
        });
    }
    return labels;
}

function orangeAdminProductPreviewSizeOptionsHtml() {
    if (!orangeProductEffectiveHasSizes()) {
        return '';
    }
    const labels = orangeAdminProductPreviewSizeLabels();
    if (!labels.length) {
        return '<div class="option-block"><label>المقاس</label><p class="card-hint" style="margin:6px 0 0;">اختر عائلة مقاسات ومقاساً واحداً على الأقل لعرض شرائح المقاس.</p></div>';
    }
    const chips = labels.map(function (lb) {
        return '<button type="button" class="chip size-chip" data-size="' + adminEscAttr(lb) + '" onclick="return false;">' + adminEscHtml(lb) + '</button>';
    });
    return '<div class="option-block"><label>المقاس</label><div class="chips">' + chips.join('') + '</div></div>';
}

function orangeAdminPreviewCardArticleHtml(titleEsc, priceEsc, imgBlock, variantHtml, viewLblEsc) {
    return '<article class="product-card">' +
        imgBlock +
        '<div class="product-body"><h3>' + titleEsc + '</h3>' +
        variantHtml +
        '<div class="price-row"><strong>' + priceEsc + '</strong></div><a class="btn" href="#" onclick="return false;">' + viewLblEsc + '</a></div>' +
        '</article>';
}

function orangeAdminPreviewProductPageHtml(opts) {
    const titleEsc = opts.titleEsc;
    const descEsc = opts.descEsc;
    const priceEsc = opts.priceEsc;
    const galleryUrls = Array.isArray(opts.galleryUrls) ? opts.galleryUrls : [];
    const colorHtml = opts.colorHtml || '';
    const sizeHtml = opts.sizeHtml || '';
    const showSizingBtn = !!opts.showSizingBtn;
    let slides = '';
    if (galleryUrls.length) {
        galleryUrls.forEach(function (url) {
            slides += '<div class="product-gallery__slide"><img class="product-gallery__img" src="' + adminEscAttr(url) + '" alt="' + titleEsc + '" loading="lazy"></div>';
        });
    } else {
        slides = '<div class="product-gallery__slide"><div class="product-gallery__img" style="display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:13px;">لا صورة بعد</div></div>';
    }
    let dots = '';
    let thumbs = '';
    if (galleryUrls.length > 1) {
        for (let i = 0; i < galleryUrls.length; i++) {
            dots += '<button type="button" class="product-gallery__dot' + (i === 0 ? ' is-active' : '') + '" role="tab" aria-selected="' + (i === 0 ? 'true' : 'false') + '" data-index="' + i + '" aria-label="' + (i + 1) + ' / ' + galleryUrls.length + '"></button>';
            thumbs += '<button type="button" class="thumb' + (i === 0 ? ' active' : '') + '" data-gallery-index="' + i + '"><img src="' + adminEscAttr(galleryUrls[i]) + '" alt=""></button>';
        }
    }
    let descHtml = '';
    if (descEsc) {
        descHtml = '<p class="product-desc product-info__desc">' + descEsc.replace(/\n/g, '<br>') + '</p>';
    }
    return '<div class="product-page-toolbar product-page-toolbar--dual"><a class="product-page__back" href="#" onclick="return false;">العودة للمتجر</a><a class="product-page__close" href="#" onclick="return false;" aria-label="إغلاق"><span aria-hidden="true">&times;</span></a></div>' +
        '<div class="product-page card-box">' +
            '<div class="product-gallery" data-gallery-count="' + String(galleryUrls.length || 1) + '">' +
                '<div class="product-gallery__stage">' +
                    (galleryUrls.length > 1 ? '<button type="button" class="product-gallery__nav product-gallery__nav--prev" aria-label="السابق"><span aria-hidden="true">‹</span></button>' : '') +
                    '<div class="product-gallery__viewport"' + (galleryUrls.length > 1 ? ' tabindex="0"' : '') + '><div class="product-gallery__track">' + slides + '</div></div>' +
                    (galleryUrls.length > 1 ? '<button type="button" class="product-gallery__nav product-gallery__nav--next" aria-label="التالي"><span aria-hidden="true">›</span></button>' : '') +
                '</div>' +
                (galleryUrls.length > 1 ? '<div class="product-gallery__dots" role="tablist" aria-label="مؤشرات الصور">' + dots + '</div><div class="thumbs product-gallery__thumbs">' + thumbs + '</div>' : '') +
            '</div>' +
            '<div class="product-info">' +
                '<h2 class="product-info__title">' + titleEsc + '</h2>' +
                '<div class="price-row product-info__price"><strong>' + priceEsc + '</strong></div>' +
                descHtml +
                colorHtml +
                sizeHtml +
                '<div class="option-block qty-block"><label>الكمية</label><div class="qty-control"><button type="button" onclick="return false;">-</button><input type="number" value="1" min="1"><button type="button" onclick="return false;">+</button></div></div>' +
                (showSizingBtn ? '<div class="option-block product-info__sizing"><button type="button" class="btn-secondary" onclick="return false;">دليل المقاس</button></div>' : '') +
                '<div class="actions-row product-info__actions"><button type="button" class="btn product-add-cart-btn" onclick="return false;">أضف إلى السلة</button></div>' +
            '</div>' +
        '</div>';
}

/** رابط صفحة المنتج على الواجهة (بعد الحفظ فقط — يحتاج معرفاً في القاعدة). */
function orangeAdminBuildStorefrontProductPageUrl(productId) {
    const id = parseInt(String(productId != null ? productId : '0'), 10) || 0;
    if (id <= 0) {
        return '';
    }
    const parts = window.ORANGE_ADMIN_SF_PRODUCT_URL_PARTS;
    if (!parts || String(parts.channel || '').trim() === '') {
        return '';
    }
    const q = new URLSearchParams({
        channel: String(parts.channel).trim(),
        lang: String(parts.lang || 'ar').trim() || 'ar',
        id: String(id),
    });
    return adminPublicPath('/pages/product.php') + '?' + q.toString();
}

function orangeAdminRefreshStorefrontProductPageLink() {
    const wrap = document.getElementById('orangeAdminProductFullPreviewWrap');
    const a = document.getElementById('orangeAdminProductFullPreviewLink');
    if (!wrap || !a) {
        return;
    }
    const rid = document.getElementById('product_record_id');
    const id = rid ? parseInt(String(rid.value || '0'), 10) || 0 : 0;
    const url = orangeAdminBuildStorefrontProductPageUrl(id);
    if (!url) {
        wrap.style.display = 'none';
        a.setAttribute('href', '#');
        return;
    }
    wrap.style.display = 'block';
    a.setAttribute('href', url);
}

/** يعيد بناء iframe المحاكاة البصرية (قائمة/صفحة منتج/موبايل) بنفس أصناف المتجر (main.css داخل الإطار). */
function orangeRefreshProductCardPreview() {
    const frame = document.getElementById('orangeAdminProductCardPreviewFrame');
    if (!frame) {
        return;
    }
    const cssUrl = typeof window.ORANGE_ADMIN_CARD_PREVIEW_CSS === 'string' ? window.ORANGE_ADMIN_CARD_PREVIEW_CSS.trim() : '';
    if (!cssUrl) {
        frame.srcdoc =
            '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"></head><body><p>تعذّر تحميل أنماط المعاينة.</p></body></html>';
        return;
    }
    const mode = orangeAdminPreviewMode();
    if (mode === 'flow') {
        frame.style.height = 'min(88vh, 980px)';
    } else if (mode === 'mobile') {
        frame.style.height = 'min(84vh, 860px)';
    } else {
        frame.style.height = 'min(72vh, 640px)';
    }
    const titlePlain = orangeAdminProductCardPreviewTitle();
    const title = adminEscHtml(titlePlain);
    const viewLbl = adminEscHtml(window.ORANGE_ADMIN_VIEW_PRODUCT_LABEL || '');
    const imgSrc = orangeAdminProductCardPreviewImageSrc();
    const galleryUrls = orangeAdminCollectPreviewGalleryUrls();
    const variantHtml = orangeAdminProductCardPreviewVariantMetaHtml();
    const colorOptionsHtml = orangeAdminProductPreviewColorOptionsHtml();
    const sizeOptionsHtml = orangeAdminProductPreviewSizeOptionsHtml();
    const descEsc = adminEscHtml(orangeAdminProductPreviewDescription());
    const priceStr = adminEscHtml(orangeAdminProductCardPreviewPriceFormatted());
    const imgBlock = imgSrc
        ? '<div class="product-image-wrap"><img src="' +
          adminEscAttr(imgSrc) +
          '" alt="' +
          title +
          '" loading="lazy" decoding="async"></div>'
        : '<div class="product-image-wrap" style="min-height:220px;display:flex;align-items:center;justify-content:center;font-size:0.9rem;color:var(--muted);padding:12px;text-align:center;">لا صورة بعد — أضف صورة من تبويب «صور المنتج العامة»</div>';
    const cardHtml = orangeAdminPreviewCardArticleHtml(title, priceStr, imgBlock, variantHtml, viewLbl);
    const productPageHtml = orangeAdminPreviewProductPageHtml({
        titleEsc: title,
        descEsc: descEsc,
        priceEsc: priceStr,
        galleryUrls: galleryUrls,
        colorHtml: colorOptionsHtml,
        sizeHtml: sizeOptionsHtml,
        showSizingBtn: orangeProductEffectiveHasSizes()
    });
    let shellClass = 'admin-preview-mode-card';
    let contentHtml = '';
    if (mode === 'product') {
        shellClass = 'admin-preview-mode-product';
        contentHtml =
            '<section class="admin-preview-section">' +
                '<h3 class="admin-preview-heading">محاكاة صفحة المنتج — سطح مكتب</h3>' +
                '<div class="container">' + productPageHtml + '</div>' +
            '</section>';
    } else if (mode === 'mobile') {
        shellClass = 'admin-preview-mode-mobile';
        contentHtml =
            '<section class="admin-preview-section">' +
                '<h3 class="admin-preview-heading">محاكاة صفحة المنتج — موبايل</h3>' +
                '<div class="admin-preview-mobile-wrap"><div class="container">' + productPageHtml + '</div></div>' +
            '</section>';
    } else if (mode === 'flow') {
        shellClass = 'admin-preview-mode-flow';
        const previewUnit = (typeof window.ORANGE_ADMIN_CURRENCY_UNIT === 'string' && window.ORANGE_ADMIN_CURRENCY_UNIT !== '') ? window.ORANGE_ADMIN_CURRENCY_UNIT : 'KD';
        const filler1 = orangeAdminPreviewCardArticleHtml(adminEscHtml('منتج مشابه A'), adminEscHtml('9.90 ' + previewUnit), '<div class="product-image-wrap" style="min-height:220px;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:12px;">صورة</div>', '', viewLbl);
        const filler2 = orangeAdminPreviewCardArticleHtml(adminEscHtml('منتج مشابه B'), adminEscHtml('7.50 ' + previewUnit), '<div class="product-image-wrap" style="min-height:220px;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:12px;">صورة</div>', '', viewLbl);
        contentHtml =
            '<section class="admin-preview-section">' +
                '<h3 class="admin-preview-heading">1) محاكاة صفحة القوائم (الكروت)</h3>' +
                '<div class="products-grid admin-preview-products-grid">' + filler1 + cardHtml + filler2 + '</div>' +
            '</section>' +
            '<section class="admin-preview-section">' +
                '<h3 class="admin-preview-heading">2) محاكاة صفحة المنتج بعد فتح الكارت</h3>' +
                '<div class="container">' + productPageHtml + '</div>' +
            '</section>';
    } else {
        shellClass = 'admin-preview-mode-card';
        contentHtml =
            '<section class="admin-preview-section">' +
                '<h3 class="admin-preview-heading">محاكاة كارت القائمة</h3>' +
                '<div class="products-grid admin-preview-products-grid admin-preview-products-grid--single">' + cardHtml + '</div>' +
            '</section>';
    }
    const galleryScript =
        '(function(){' +
            'function initGallery(g){' +
                'var track=g.querySelector(".product-gallery__track"); if(!track){return;}' +
                'var slides=Array.prototype.slice.call(g.querySelectorAll(".product-gallery__slide")); if(!slides.length){return;}' +
                'var dots=Array.prototype.slice.call(g.querySelectorAll(".product-gallery__dot"));' +
                'var thumbs=Array.prototype.slice.call(g.querySelectorAll(".thumb"));' +
                'var prev=g.querySelector(".product-gallery__nav--prev");' +
                'var next=g.querySelector(".product-gallery__nav--next");' +
                'var idx=0;' +
                'function render(){' +
                    'track.style.transform="translateX(-"+(idx*100)+"%)";' +
                    'dots.forEach(function(b,i){b.classList.toggle("is-active",i===idx);b.setAttribute("aria-selected",i===idx?"true":"false");});' +
                    'thumbs.forEach(function(b,i){b.classList.toggle("active",i===idx);});' +
                '}' +
                'function go(i){var n=slides.length; if(!n){return;} idx=((i%n)+n)%n; render();}' +
                'if(prev){prev.addEventListener("click",function(){go(idx-1);});}' +
                'if(next){next.addEventListener("click",function(){go(idx+1);});}' +
                'dots.forEach(function(b){b.addEventListener("click",function(){go(parseInt(String(b.getAttribute("data-index")||"0"),10)||0);});});' +
                'thumbs.forEach(function(b){b.addEventListener("click",function(){go(parseInt(String(b.getAttribute("data-gallery-index")||"0"),10)||0);});});' +
                'render();' +
            '}' +
            'Array.prototype.slice.call(document.querySelectorAll(".product-gallery")).forEach(initGallery);' +
        '})();';
    const doc =
        '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><link rel="stylesheet" href="' +
        adminEscAttr(cssUrl) +
        '"><style>' +
        'body.storefront{background:#f1f5f9;}' +
        '.admin-preview-shell{padding:16px 12px 28px;min-height:100vh;}' +
        '.admin-preview-section{margin:0 0 18px;}' +
        '.admin-preview-heading{margin:0 0 10px;font-size:14px;color:#e2e8f0;background:#0f172a;border-radius:8px;padding:8px 10px;}' +
        '.admin-preview-products-grid{grid-template-columns:repeat(3,minmax(0,300px));justify-content:center;gap:14px;padding:2px 2px 12px;}' +
        '.admin-preview-products-grid--single{grid-template-columns:minmax(0,300px);}' +
        '.admin-preview-mode-mobile .admin-preview-mobile-wrap{max-width:390px;margin:0 auto;padding:0 2px;}' +
        '.admin-preview-mode-mobile .container{padding-inline:8px;}' +
        '.admin-preview-mode-product .container,.admin-preview-mode-flow .container{padding-inline:6px;}' +
        '@media (max-width:980px){.admin-preview-products-grid{grid-template-columns:minmax(0,320px);}}' +
        '</style></head><body class="storefront ' + shellClass + '"><div class="admin-preview-shell">' +
        contentHtml +
        '</div><script>' + galleryScript + '<\/script></body></html>';
    frame.srcdoc = doc;
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
        orangeApplyProductBasicStepLocks();
        orangeScheduleProductCardPreviewRefresh();
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
        orangeApplyProductBasicStepLocks();
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

function orangeRevokeMainPendingImageUrl() {
    if (window.ORANGE_MAIN_PENDING_IMAGE_URL) {
        try {
            URL.revokeObjectURL(window.ORANGE_MAIN_PENDING_IMAGE_URL);
        } catch (e) {}
        window.ORANGE_MAIN_PENDING_IMAGE_URL = null;
    }
}

function orangeRevokeGalleryPendingUrls() {
    (window.ORANGE_GALLERY_PENDING_URLS || []).forEach(function (u) {
        try {
            if (u) {
                URL.revokeObjectURL(u);
            }
        } catch (e) {}
    });
    window.ORANGE_GALLERY_PENDING_URLS = [];
}

/** معاينة ملف الصورة الرئيسية المختار قبل الرفع؛ عند عدم اختيار ملف تُعرض الصورة المرفوعة الحالية إن وُجدت. */
function orangeRefreshMainImageFileInputPreview() {
    const inp = document.getElementById('main_image_file');
    const btnClear = document.getElementById('btn_clear_main_image_file_selection');
    orangeRevokeMainPendingImageUrl();
    const mount = document.getElementById('main_image_preview');
    const row = document.getElementById('main_image_preview_row');
    if (!mount || !row) {
        return;
    }
    const f = inp && inp.files && inp.files[0];
    if (f) {
        window.ORANGE_MAIN_PENDING_IMAGE_URL = URL.createObjectURL(f);
        const style = 'max-height:160px;max-width:100%;border-radius:8px;border:1px solid #ddd;';
        mount.innerHTML =
            '<img alt="" style="' +
            style +
            '" src="' +
            adminEscAttr(window.ORANGE_MAIN_PENDING_IMAGE_URL) +
            '">';
        row.style.display = 'flex';
        if (btnClear) {
            btnClear.style.display = '';
        }
        orangeRefreshVariantReferenceThumbs();
        orangeScheduleProductCardPreviewRefresh();
        orangeApplyProductWizardActionButtons();
        return;
    }
    if (btnClear) {
        btnClear.style.display = 'none';
    }
    adminSetMainImagePreview(document.getElementById('main_image') ? document.getElementById('main_image').value.trim() : '');
    orangeApplyProductWizardActionButtons();
}

function orangeRenderGalleryPendingPreviews() {
    const inp = document.getElementById('gallery_files');
    const ul = document.getElementById('gallery_files_pending_list');
    const btnClr = document.getElementById('btn_clear_gallery_file_selection');
    orangeRevokeGalleryPendingUrls();
    if (!ul) {
        return;
    }
    ul.innerHTML = '';
    const n = inp && inp.files ? inp.files.length : 0;
    if (!n) {
        if (btnClr) {
            btnClr.style.display = 'none';
        }
        return;
    }
    if (btnClr) {
        btnClr.style.display = '';
    }
    for (let i = 0; i < inp.files.length; i++) {
        const file = inp.files[i];
        const url = URL.createObjectURL(file);
        window.ORANGE_GALLERY_PENDING_URLS.push(url);
        const li = document.createElement('li');
        li.className = 'admin-product-gallery-upload-item';
        const thumbWrap = document.createElement('div');
        thumbWrap.className = 'admin-product-gallery-upload-thumb';
        const img = document.createElement('img');
        img.alt = '';
        img.style.cssText =
            'width:56px;height:56px;object-fit:cover;border-radius:6px;border:1px solid #cbd5e1;display:block;';
        img.src = url;
        thumbWrap.appendChild(img);
        const cap = document.createElement('span');
        cap.className = 'admin-product-gallery-upload-filename';
        cap.textContent = file.name || '#' + String(i + 1);
        cap.setAttribute('dir', 'ltr');
        const rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'btn-secondary admin-product-gallery-upload-remove';
        rm.textContent = 'إزالة';
        const idx = i;
        rm.addEventListener('click', function () {
            orangeGalleryPendingRemoveOneAt(idx);
        });
        li.appendChild(thumbWrap);
        li.appendChild(cap);
        li.appendChild(rm);
        ul.appendChild(li);
    }
}

function orangeGalleryPendingRemoveOneAt(removeIndex) {
    const inp = document.getElementById('gallery_files');
    if (!inp || !inp.files || inp.files.length <= removeIndex) {
        orangeRenderGalleryPendingPreviews();
        return;
    }
    const dt = new DataTransfer();
    for (let i = 0; i < inp.files.length; i++) {
        if (i !== removeIndex) {
            dt.items.add(inp.files[i]);
        }
    }
    inp.files = dt.files;
    orangeRenderGalleryPendingPreviews();
}

function renderGalleryUploadList() {
    const ul = document.getElementById('gallery_upload_list');
    if (!ul) {
        return;
    }
    ul.innerHTML = '';
    (window.PRODUCT_EXTRA_IMAGES || []).forEach(function (name) {
        const fn = String(name || '').trim();
        if (!fn) {
            return;
        }
        const li = document.createElement('li');
        li.className = 'admin-product-gallery-upload-item';
        const thumbWrap = document.createElement('div');
        thumbWrap.className = 'admin-product-gallery-upload-thumb';
        thumbWrap.innerHTML = adminProductUploadThumbPictureInnerHtml(fn, 56);
        const cap = document.createElement('span');
        cap.className = 'admin-product-gallery-upload-filename';
        cap.textContent = fn;
        cap.setAttribute('dir', 'ltr');
        cap.setAttribute('lang', 'en');
        const rm = document.createElement('button');
        rm.type = 'button';
        rm.textContent = 'إزالة';
        rm.className = 'btn-secondary admin-product-gallery-upload-remove';
        rm.onclick = function () {
            const mainEl = document.getElementById('main_image');
            const removed = fn;
            const wasMain = mainEl && mainEl.value.trim() === removed;
            const arr = window.PRODUCT_EXTRA_IMAGES || [];
            const j = arr.indexOf(removed);
            if (j >= 0) {
                arr.splice(j, 1);
            }
            if (wasMain) {
                mainEl.value = '';
                assignMainImageFromGalleryIfEmpty();
                if (!mainEl.value.trim()) {
                    adminSetMainImagePreview('');
                } else {
                    adminSetMainImagePreview(mainEl.value.trim());
                }
            }
            renderGalleryUploadList();
            orangeProductInvalidateVariantsReadyForSave();
        };
        li.appendChild(thumbWrap);
        li.appendChild(cap);
        li.appendChild(rm);
        ul.appendChild(li);
    });
    orangeRefreshVariantReferenceThumbs();
    orangeScheduleProductCardPreviewRefresh();
    orangeApplyProductWizardActionButtons();
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
        const r = await fetch(adminApiPath('api/uploads/product-image.php'), { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (!j.success) {
            alert(j.message || 'فشل الرفع');
            return;
        }
        document.getElementById('main_image').value = j.filename;
        inp.value = '';
        orangeRefreshMainImageFileInputPreview();
        orangeProductInvalidateVariantsReadyForSave();
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
    window.ORANGE_PRODUCT_VARIANTS_READY_FOR_SAVE = false;
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
    if (typeof window.orangeRefreshSeoEffectivePreview === 'function') {
        window.orangeRefreshSeoEffectivePreview();
    }
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
    orangeRevokeMainPendingImageUrl();
    const gfReset = document.getElementById('gallery_files');
    if (gfReset) {
        gfReset.value = '';
    }
    orangeRevokeGalleryPendingUrls();
    const gpend = document.getElementById('gallery_files_pending_list');
    if (gpend) {
        gpend.innerHTML = '';
    }
    const bgf = document.getElementById('btn_clear_gallery_file_selection');
    if (bgf) {
        bgf.style.display = 'none';
    }
    const bm = document.getElementById('btn_clear_main_image_file_selection');
    if (bm) {
        bm.style.display = 'none';
    }
    adminSetMainImagePreview('');
    document.getElementById('has_colors').value = '0';
    document.getElementById('size_family_id').value = '';
    const advReset = document.getElementById('sizing_advisory_guide_id');
    if (advReset) {
        advReset.innerHTML = '<option value="0">بدون</option>';
        advReset.value = '0';
        advReset.disabled = true;
    }
    document.getElementById('product_is_active').value = '1';
    document.getElementById('colorwaysBox').innerHTML = '';
    document.getElementById('variantsBox').innerHTML = '';
    window.PRODUCT_EXTRA_IMAGES = [];
    renderGalleryUploadList();
    orangeClearCatalogAttributeInputs();
    orangeApplyProductBasicStepLocks();
    productFormShowTab('basic');
    orangeAdminRefreshStorefrontProductPageLink();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function productFormShowTab(tab) {
    const map = {
        basic: 'productTabPanelBasic',
        sizes: 'productTabPanelSizes',
        description: 'productTabPanelDescription',
        attributes: 'productTabPanelAttributes',
        images: 'productTabPanelImages',
        variants: 'productTabPanelVariants',
        cardpreview: 'productTabPanelCardPreview'
    };
    const key = map[tab] ? tab : 'basic';
    const panelId = map[key];
    document.querySelectorAll('.admin-product-tab-panel').forEach(function (el) {
        const active = el.id === panelId;
        el.classList.toggle('is-active', active);
        if (active) {
            el.removeAttribute('hidden');
            el.style.display = 'block';
        } else {
            el.setAttribute('hidden', 'hidden');
            el.style.display = 'none';
        }
        el.style.marginTop = '0';
        el.style.paddingTop = '0';
    });
    document.querySelectorAll('.admin-product-tab').forEach(function (btn) {
        const on = btn.getAttribute('data-product-tab') === key;
        btn.classList.toggle('is-active', on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    orangeNormalizeProductTabPanelsNoGap();
    if (key === 'variants') {
        orangeRefreshVariantReferenceThumbs();
    }
    if (key === 'cardpreview') {
        orangeRefreshProductCardPreview();
        orangeAdminRefreshStorefrontProductPageLink();
    }
}

function orangeNormalizeProductTabPanelsNoGap() {
    const wrap = document.querySelector('#productForm > .admin-product-tab-panels');
    if (wrap) {
        wrap.style.minHeight = '0';
        wrap.style.height = 'auto';
        wrap.style.marginTop = '0';
        wrap.style.paddingTop = '0';
    }
    const active = wrap ? wrap.querySelector('.admin-product-tab-panel.is-active') : null;
    if (!active) {
        return;
    }
    active.style.minHeight = '0';
    active.style.height = 'auto';
    active.style.marginTop = '0';
    active.style.paddingTop = '0';
    const sec = active.querySelector('.admin-product-section');
    if (sec) {
        sec.style.marginTop = '0';
        sec.style.paddingTop = '0';
    }
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
        const res = await fetch(adminApiPath('api/products/get.php?id=' + encodeURIComponent(id)));
        const j = await res.json();
        if (!j.success || !j.product) {
            alert(j.message || 'تعذر تحميل المنتج');
            return;
        }
        const p = j.product;
        const mifLoad = document.getElementById('main_image_file');
        if (mifLoad) {
            mifLoad.value = '';
        }
        const gfLoad = document.getElementById('gallery_files');
        if (gfLoad) {
            gfLoad.value = '';
        }
        orangeRevokeMainPendingImageUrl();
        orangeRevokeGalleryPendingUrls();
        const gplLoad = document.getElementById('gallery_files_pending_list');
        if (gplLoad) {
            gplLoad.innerHTML = '';
        }
        const bgsLoad = document.getElementById('btn_clear_gallery_file_selection');
        if (bgsLoad) {
            bgsLoad.style.display = 'none';
        }
        const bmsLoad = document.getElementById('btn_clear_main_image_file_selection');
        if (bmsLoad) {
            bmsLoad.style.display = 'none';
        }
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
        if (typeof window.orangeRefreshSeoEffectivePreview === 'function') {
            window.orangeRefreshSeoEffectivePreview();
        }
        document.getElementById('category_id').value = String(p.catalog_category_display_id || '');
        const sid = parseInt(p.catalog_subcategory_display_id, 10) || 0;
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
        document.getElementById('has_colors').value = parseInt(p.has_colors, 10) === 1 ? '1' : '0';
        document.getElementById('size_family_id').value = p.size_family_id ? String(p.size_family_id) : '';
        const advPick = parseInt(String(p.sizing_advisory_guide_id != null ? p.sizing_advisory_guide_id : '0'), 10) || 0;
        void orangeProductRefreshAdvisoryGuideSelect(advPick);
        document.getElementById('colorwaysBox').innerHTML = '';
        document.getElementById('variantsBox').innerHTML = '';
        const vm = Array.isArray(p.variant_matrix_rows) ? p.variant_matrix_rows : [];
        window.PRODUCT_EXTRA_IMAGES = extrasEarly;
        renderGalleryUploadList();
        orangeApplyCatalogAttributeValuesFromProduct(p);
        buildColorwaysForEditFromVm(vm, Array.isArray(p.colorway_images) ? p.colorway_images : []);
        const hasSizesEff = orangeProductEffectiveHasSizes();
        if (hasSizesEff && parseInt(p.has_colors, 10) === 1) {
            orangeApplyColorwaySizesFromVariantMatrix(vm);
        } else {
            orangeApplySizePickFromVariantMatrix(vm);
        }
        const needMatrix =
            parseInt(p.has_colors, 10) === 1 ||
            hasSizesEff ||
            (vm && vm.length > 0);
        if (needMatrix) {
            generateVariants();
            applyVariantStocksFromVm(vm);
            applyVariantBarcodesFromVm(vm);
        }
        if (needMatrix && !document.querySelectorAll('#variantsBox tbody tr').length) {
            document.getElementById('variantsBox').innerHTML =
                '<p class="admin-variants-edit-note">لم تُستخرج المتغيرات — راجع الشاشة ثم استخدم «توليد المتغيرات» وحفظ.</p>';
        }
        if (!document.querySelectorAll('#variantsBox tbody tr').length && orangeProductIsSimpleSkuMatrix()) {
            generateVariants();
            applyVariantStocksFromVm(vm);
            applyVariantBarcodesFromVm(vm);
        }
        orangeApplyProductBasicStepLocks();
        orangeScheduleProductCardPreviewRefresh();
        orangeAdminRefreshStorefrontProductPageLink();
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
            const r = await fetch(adminApiPath('api/uploads/product-image.php'), { method: 'POST', body: fd, credentials: 'same-origin' });
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
    orangeRenderGalleryPendingPreviews();
    assignMainImageFromGalleryIfEmpty();
    renderGalleryUploadList();
    orangeRefreshVariantReferenceThumbs();
    orangeProductInvalidateVariantsReadyForSave();
}

function orangeProductSizingSaveFields() {
    const advEl = document.getElementById('sizing_advisory_guide_id');
    const adv = advEl ? parseInt(String(advEl.value || '0'), 10) || 0 : 0;
    return {
        sizing_advisory_guide_id: adv,
        sizing_guide_scope: 'none',
    };
}

async function orangeProductRefreshAdvisoryGuideSelect(preserveId) {
    const sel = document.getElementById('sizing_advisory_guide_id');
    if (!sel) {
        return;
    }
    const famEl = document.getElementById('size_family_id');
    const famId = famEl ? parseInt(String(famEl.value || '0'), 10) || 0 : 0;
    const wantKeep = preserveId !== undefined && preserveId !== null;
    const prev = wantKeep ? (parseInt(String(preserveId), 10) || 0) : (parseInt(String(sel.value || '0'), 10) || 0);
    sel.innerHTML = '<option value="0">بدون</option>';
    if (!famId) {
        sel.disabled = true;
        sel.value = '0';
        return;
    }
    const allowTier = orangeProductBasicRecordIsEdit() || orangeProductBasicPriceOk();
    const hs = orangeProductEffectiveHasSizes();
    if (!allowTier || !hs) {
        sel.disabled = true;
        sel.value = '0';
        return;
    }
    sel.disabled = false;
    try {
        const res = await postJSON(adminApiPath('api/advisory_sizing_guides/manage.php'), {
            action: 'list_by_family',
            size_family_id: famId,
        });
        if (!res || !res.success) {
            sel.value = '0';
            return;
        }
        const guides = res.guides || [];
        guides.forEach(function (g) {
            const active = parseInt(String(g.is_active != null ? g.is_active : '1'), 10) || 0;
            if (active !== 1) {
                return;
            }
            const cols = parseInt(String(g.columns_count != null ? g.columns_count : '0'), 10) || 0;
            const rws = parseInt(String(g.rows_count != null ? g.rows_count : '0'), 10) || 0;
            if (cols < 1 || rws < 1) {
                return;
            }
            const id = parseInt(String(g.id != null ? g.id : '0'), 10) || 0;
            if (id <= 0) {
                return;
            }
            const lab = String(g.name_ar || g.name_en || ('#' + id)).replace(/</g, '');
            const o = document.createElement('option');
            o.value = String(id);
            o.textContent = lab;
            sel.appendChild(o);
        });
        if (prev > 0) {
            const hit = sel.querySelector('option[value="' + prev + '"]');
            if (hit) {
                sel.value = String(prev);
            } else {
                sel.value = '0';
            }
        } else {
            sel.value = '0';
            orangeProductApplyDefaultAdvisoryFromProductType(true);
        }
    } catch (e) {
        sel.value = '0';
    }
}

function onHasFlagsChange(options) {
    const hcEl = document.getElementById('has_colors');
    const hc = hcEl && hcEl.value === '1';
    const allowSizeTier = orangeProductBasicRecordIsEdit() || orangeProductBasicPriceOk();
    const famSel = document.getElementById('size_family_id');
    if (famSel) {
        if (!allowSizeTier) {
            famSel.disabled = true;
            if (!orangeProductBasicRecordIsEdit()) {
                famSel.value = '';
            }
        } else {
            famSel.disabled = false;
        }
    }
    if (hcEl) {
        hcEl.disabled = !allowSizeTier;
    }
    const famWrap = document.getElementById('product_basic_size_family_wrap');
    if (famWrap) {
        famWrap.style.display = 'block';
    }
    const colorwaysSec = document.getElementById('colorwaysSection');
    if (colorwaysSec) {
        colorwaysSec.style.display = hc && allowSizeTier ? 'block' : 'none';
    }
    if (hc && allowSizeTier && !document.querySelector('#colorwaysBox .cw-row')) {
        addColorwayRow();
    }
    orangeApplySizeFamilySchemeFilter();
    const hs = orangeProductEffectiveHasSizes();
    const sgAdv = document.getElementById('sizing_advisory_guide_id');
    if (sgAdv) {
        if (!allowSizeTier || !hs) {
            sgAdv.innerHTML = '<option value="0">بدون</option>';
            sgAdv.value = '0';
            sgAdv.disabled = true;
        } else {
            sgAdv.disabled = false;
            void orangeProductRefreshAdvisoryGuideSelect();
        }
    }
    const cwh = document.getElementById('colorways_sizes_hint');
    if (cwh) {
        cwh.style.display = hs && hc && allowSizeTier ? 'block' : 'none';
    }
    if (hs && hc && allowSizeTier) {
        orangeRefreshAllColorwaySizePickers();
    }
    if (options && options.clearGeneratedMatrix) {
        orangeProductClearGeneratedVariantsMatrixIfNeeded();
    }
    orangeScheduleProductCardPreviewRefresh();
    orangeProductInvalidateVariantsReadyForSave();
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

/** يطابق صف الألوان (cw-row) بمفتاح نفس صف المتغير (لون أساسي/ثانوي + نمط). */
function adminFindColorwayRowByVariantIds(p, s, pp, sp) {
    const p0 = parseInt(p, 10) || 0;
    const s0 = parseInt(s, 10) || 0;
    const pp0 = parseInt(pp, 10) || 0;
    const sp0 = parseInt(sp, 10) || 0;
    const rows = document.querySelectorAll('#colorwaysBox .cw-row');
    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const rp = parseInt((row.querySelector('.cw-p') && row.querySelector('.cw-p').value) || '0', 10) || 0;
        const rs = parseInt((row.querySelector('.cw-s') && row.querySelector('.cw-s').value) || '0', 10) || 0;
        const rpp = parseInt((row.querySelector('.cw-pp') && row.querySelector('.cw-pp').value) || '0', 10) || 0;
        const rsp = parseInt((row.querySelector('.cw-sp') && row.querySelector('.cw-sp').value) || '0', 10) || 0;
        if (rp === p0 && rs === s0 && rpp === pp0 && rsp === sp0) {
            return row;
        }
    }
    return null;
}

/**
 * اسم ملف الصورة المعروضة كمرجع لصف متغير (بعد مطابقة صف اللون).
 * بوجود ألوان: صور معرض ذلك اللون أولاً، ثم الرئيسية، ثم أول صورة معرض عامة.
 */
function adminVariantReferenceThumbEffectiveFilenameForColorway(p, s, pp, sp) {
    const hc = document.getElementById('has_colors') && String(document.getElementById('has_colors').value || '') === '1';
    const mainEl = document.getElementById('main_image');
    const main = mainEl ? String(mainEl.value || '').trim() : '';
    if (!hc) {
        return main;
    }
    const cwRow = adminFindColorwayRowByVariantIds(p, s, pp, sp);
    if (cwRow) {
        const li = cwRow.querySelector('.cw-gallery-list li[data-fn]');
        if (li) {
            const fn = String(li.getAttribute('data-fn') || '').trim();
            if (fn) {
                return fn;
            }
        }
    }
    if (main) {
        return main;
    }
    const ex = window.PRODUCT_EXTRA_IMAGES || [];
    if (ex.length && ex[0]) {
        return String(ex[0]).trim();
    }
    return '';
}

function adminVariantReferenceThumbHtmlForFilename(mainImg) {
    const hc = document.getElementById('has_colors') && String(document.getElementById('has_colors').value || '') === '1';
    const phTitle = hc
        ? 'ارفع صورة رئيسية أو صور معرض من تبويب الصور، أو صور للون من تبويب الألوان'
        : 'ارفع الصورة الرئيسية من تبويب الصور (منتج بلا ألوان)';
    const trimmed = String(mainImg || '').trim();
    if (!trimmed) {
        return '<span class="admin-variant-thumb-placeholder" title="' + adminEscAttr(phTitle) + '">؟</span>';
    }
    const base = adminProductImageBasename(trimmed);
    if (!base) {
        return '<span class="admin-variant-thumb-placeholder" title="' + adminEscAttr(phTitle) + '">؟</span>';
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

function adminVariantReferenceThumbHtmlForColorway(p, s, pp, sp) {
    const fn = adminVariantReferenceThumbEffectiveFilenameForColorway(p, s, pp, sp);
    return adminVariantReferenceThumbHtmlForFilename(fn);
}

function orangeColorwayRowKeyFromRow(row) {
    if (!row) {
        return '';
    }
    const gv = function (sel) {
        return parseInt((row.querySelector(sel) && row.querySelector(sel).value) || '0', 10) || 0;
    };
    return [gv('.cw-p'), gv('.cw-s'), gv('.cw-pp'), gv('.cw-sp')].join(':');
}

function orangeFillColorwayRowSizes(rowEl) {
    const mount = rowEl && rowEl.querySelector ? rowEl.querySelector('.cw-sizes-mount') : null;
    if (!mount) {
        return;
    }
    const hs = orangeProductEffectiveHasSizes();
    if (!hs) {
        mount.innerHTML = '';
        return;
    }
    const famSel = document.getElementById('size_family_id');
    const famId = famSel ? (parseInt(famSel.value, 10) || 0) : 0;
    if (!famId) {
        mount.innerHTML = '<p class="card-hint" style="margin:0;">اختر <strong>عائلة المقاسات</strong> من تبويب البيانات الأساسية أولاً.</p>';
        return;
    }
    const sizes = sizesForFamily(famId);
    mount.innerHTML = '';
    const leg = document.createElement('div');
    leg.className = 'card-hint';
    leg.style.margin = '0 0 6px';
    leg.textContent = 'المقاسات المتاحة لهذا اللون (من العائلة):';
    mount.appendChild(leg);
    const grid = document.createElement('div');
    grid.className = 'product-size-pick-grid cw-sizes-grid';
    sizes.forEach(function (sz) {
        const sid = parseInt(String(sz.id != null ? sz.id : '0'), 10) || 0;
        if (sid <= 0) {
            return;
        }
        const wrap = document.createElement('label');
        wrap.className = 'product-size-pick-item cw-size-lb';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'cw-size-cb';
        cb.value = String(sid);
        cb.checked = false;
        const span = document.createElement('span');
        span.textContent = String(sz.label_ar || sz.label_en || ('#' + sid)).replace(/</g, '');
        wrap.appendChild(cb);
        wrap.appendChild(span);
        grid.appendChild(wrap);
    });
    mount.appendChild(grid);
}

function orangeRefreshAllColorwaySizePickers() {
    const hs = orangeProductEffectiveHasSizes();
    const hc = document.getElementById('has_colors') && document.getElementById('has_colors').value === '1';
    if (!hs || !hc) {
        return;
    }
    document.querySelectorAll('#colorwaysBox .cw-row').forEach(function (row) {
        orangeFillColorwayRowSizes(row);
    });
}

function orangeApplyColorwaySizesFromVariantMatrix(vm) {
    const hs = orangeProductEffectiveHasSizes();
    const hc = document.getElementById('has_colors') && document.getElementById('has_colors').value === '1';
    if (!hs || !hc) {
        return;
    }
    window.ORANGE_CW_SIZE_SILENT = true;
    try {
    orangeRefreshAllColorwaySizePickers();
    const byCw = {};
    (vm || []).forEach(function (r) {
        const ck = [
            parseInt(String(r.primary_color_id != null ? r.primary_color_id : '0'), 10) || 0,
            parseInt(String(r.secondary_color_id != null ? r.secondary_color_id : '0'), 10) || 0,
            parseInt(String(r.primary_pattern_id != null ? r.primary_pattern_id : '0'), 10) || 0,
            parseInt(String(r.secondary_pattern_id != null ? r.secondary_pattern_id : '0'), 10) || 0
        ].join(':');
        const z = parseInt(String(r.size_family_size_id != null ? r.size_family_size_id : '0'), 10) || 0;
        if (z <= 0) {
            return;
        }
        if (!byCw[ck]) {
            byCw[ck] = {};
        }
        const vid = parseInt(String(r.variant_id != null ? r.variant_id : '0'), 10) || 0;
        const bc = r.variant_barcode != null ? String(r.variant_barcode).trim() : '';
        const sq = parseInt(String(r.stock_quantity != null ? r.stock_quantity : '0'), 10) || 0;
        byCw[ck][z] = { vid: vid, hasBarcode: bc !== '', stockQty: sq };
    });
    document.querySelectorAll('#colorwaysBox .cw-row').forEach(function (row) {
        const ck = orangeColorwayRowKeyFromRow(row);
        const mapZ = byCw[ck] || {};
        row.querySelectorAll('.cw-size-cb').forEach(function (cb) {
            const id = parseInt(cb.value, 10) || 0;
            const meta = mapZ[id];
            cb.checked = !!meta;
            cb.classList.toggle('cw-size-cb--persisted', !!(meta && meta.vid > 0));
            const lb = cb.closest('.cw-size-lb');
            if (lb) {
                lb.classList.toggle('cw-size-lb--dim', !!(meta && meta.vid > 0));
            }
            cb.removeAttribute('data-variant-id');
            cb.removeAttribute('data-has-barcode');
            cb.removeAttribute('data-stock-qty');
            cb.disabled = false;
            if (meta && meta.vid > 0) {
                cb.setAttribute('data-variant-id', String(meta.vid));
                if (meta.hasBarcode) {
                    cb.setAttribute('data-has-barcode', '1');
                }
                cb.setAttribute('data-stock-qty', String(meta.stockQty || 0));
                if ((meta.stockQty || 0) > 0) {
                    cb.disabled = true;
                    cb.title = 'يوجد مخزون على هذا المتغير — لا يُلغى من هنا';
                } else {
                    cb.title = 'مسجّل مسبقاً — يمكن إلغاء التفعيل مع تأكيد عند الحفظ';
                }
            } else {
                cb.title = '';
            }
        });
    });
    } finally {
        window.ORANGE_CW_SIZE_SILENT = false;
    }
}

function orangeColorwaySizeCheckboxBeforeUncheck(cb) {
    if (!cb || cb.checked) {
        return true;
    }
    const st = parseInt(cb.getAttribute('data-stock-qty') || '0', 10) || 0;
    if (st > 0) {
        cb.checked = true;
        alert('يوجد مخزون على هذا المتغير — عدّل الرصيد من شاشة المخزون ثم عُد لتعديل التشكيلة.');
        return false;
    }
    const vid = cb.getAttribute('data-variant-id');
    if (vid) {
        if (!confirm('هذا المقاس مسجّل لهذا اللون في النظام. إلغاء التفعيل يزيله من مصفوفة المتغيرات عند الحفظ (إن لم يكن له مخزون). متابعة؟')) {
            cb.checked = true;
            return false;
        }
    }
    return true;
}

function addColorwayRow() {
    const box = document.getElementById('colorwaysBox');
    const div = document.createElement('div');
    div.className = 'cw-row cw-row--with-sizes';
    div.innerHTML = `
        <div class="cw-row-colors form-grid cw-row--compact">
            <div><label>أساسي</label><select class="cw-p">${colorOptionsHtml()}</select></div>
            <div><label>ثانوي (اختياري)</label><select class="cw-s">${colorOptionsHtml()}</select></div>
            <div><label>نمط أساسي (اختياري)</label><select class="cw-pp">${patternOptionsHtml()}</select></div>
            <div><label>نمط ثانوي (اختياري)</label><select class="cw-sp">${patternOptionsHtml()}</select></div>
        </div>
        <div class="cw-sizes-mount" style="margin-top:10px;width:100%;"></div>
        <div class="cw-row-gallery card-hint" style="margin-top:12px;padding-top:10px;border-top:1px solid #e2e8f0;">
            <strong>صور هذا اللون (اختياري)</strong>
            <p style="margin:4px 0 8px;font-size:12px;color:#64748b;">تظهر في المتجر عند اختيار هذا اللون؛ إن تُركت فارغة يُستخدم معرض المنتج العام من تبويب «صور المنتج العامة».</p>
            <input type="file" class="cw-gallery-files" accept="image/jpeg,image/png,image/webp,image/gif" multiple style="display:none">
            <button type="button" class="btn-secondary cw-gallery-upload-btn" style="margin-bottom:8px;">رفع صور لهذا اللون</button>
            <ul class="cw-gallery-list" style="margin:0;padding-inline-start:18px;list-style:none;display:flex;flex-wrap:wrap;gap:8px;"></ul>
        </div>
    `;
    box.appendChild(div);
    orangeFillColorwayRowSizes(div);
    orangeScheduleProductCardPreviewRefresh();
}

function orangeColorwayRowCwKey(row) {
    if (!row) {
        return '';
    }
    const gv = function (sel) {
        const el = row.querySelector(sel);
        return el ? (parseInt(el.value || '0', 10) || 0) : 0;
    };
    return [gv('.cw-p'), gv('.cw-s'), gv('.cw-pp'), gv('.cw-sp')].join(':');
}

function orangeCwGalleryAppendThumb(row, filename) {
    const ul = row.querySelector('.cw-gallery-list');
    if (!ul || !filename) {
        return;
    }
    const li = document.createElement('li');
    li.style.cssText = 'display:inline-flex;align-items:center;gap:6px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:4px 8px;font-size:12px;';
    li.setAttribute('data-fn', filename);
    const prefix = adminPublicPath('/uploads/products/');
    li.innerHTML =
        '<img src="' +
        adminEscAttr(prefix + encodeURIComponent(filename)) +
        '" alt="" width="40" height="40" style="object-fit:cover;border-radius:4px;">' +
        '<span dir="ltr" lang="en">' +
        adminEscAttr(filename) +
        '</span>' +
        '<button type="button" class="btn-secondary cw-gallery-remove" style="font-size:11px;padding:2px 6px;">إزالة</button>';
    ul.appendChild(li);
}

function orangeCollectColorwayImagesPayload() {
    const hc = document.getElementById('has_colors') && document.getElementById('has_colors').value === '1';
    if (!hc) {
        return [];
    }
    const out = [];
    document.querySelectorAll('#colorwaysBox .cw-row').forEach(function (row) {
        const p = parseInt((row.querySelector('.cw-p') && row.querySelector('.cw-p').value) || '0', 10) || 0;
        if (!p) {
            return;
        }
        const imgs = [];
        row.querySelectorAll('.cw-gallery-list li[data-fn]').forEach(function (li) {
            const fn = li.getAttribute('data-fn');
            if (fn) {
                imgs.push(fn);
            }
        });
        if (imgs.length) {
            out.push({ cw_key: orangeColorwayRowCwKey(row), images: imgs });
        }
    });
    return out;
}

async function orangeUploadColorwayGalleryFiles(row, fileList) {
    if (!row || !fileList || !fileList.length) {
        return;
    }
    for (let i = 0; i < fileList.length; i++) {
        const fd = new FormData();
        fd.append('image', fileList[i]);
        try {
            const r = await fetch(adminApiPath('api/uploads/product-image.php'), { method: 'POST', body: fd, credentials: 'same-origin' });
            const j = await r.json();
            if (j.success && j.filename) {
                orangeCwGalleryAppendThumb(row, j.filename);
            } else if (j.message) {
                alert(j.message);
            }
        } catch (e) {
            alert('خطأ في الاتصال أثناء الرفع');
            return;
        }
    }
    orangeRefreshVariantReferenceThumbs();
    orangeScheduleProductCardPreviewRefresh();
    orangeApplyProductWizardActionButtons();
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

function buildColorwaysForEditFromVm(vm, colorwayImageGroups) {
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
        const grp = (colorwayImageGroups || []).find(function (g) {
            return g && String(g.cw_key || '') === k;
        });
        if (grp && Array.isArray(grp.images)) {
            grp.images.forEach(function (fn) {
                if (fn) {
                    orangeCwGalleryAppendThumb(last, String(fn));
                }
            });
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
        const disp = tr.querySelector('.v-stock-display');
        if (disp && Object.prototype.hasOwnProperty.call(map, k)) {
            disp.textContent = String(map[k]);
        }
    });
}

function applyVariantBarcodesFromVm(vm) {
    const map = {};
    (vm || []).forEach((r) => {
        const bc = r.variant_barcode != null ? String(r.variant_barcode).trim() : '';
        map[adminVariantRowStockKey(r)] = bc;
    });
    document.querySelectorAll('#variantsBox tbody tr').forEach((tr) => {
        const k = adminVariantTrStockKey(tr);
        const disp = tr.querySelector('.v-barcode-display');
        if (!disp) {
            return;
        }
        if (Object.prototype.hasOwnProperty.call(map, k) && map[k]) {
            disp.textContent = map[k];
        } else {
            disp.textContent = '—';
        }
    });
}

function sizesForFamily(fid) {
    const fam = (window.ORANGE_FAMILIES || []).find(f => String(f.id) === String(fid));
    return fam && fam.sizes ? fam.sizes : [];
}

function orangeSizePickSetAll(checked) {
    document.querySelectorAll('#product_size_pick_checkboxes .product-size-pick-cb').forEach(function (cb) {
        cb.checked = !!checked;
    });
    orangeProductClearGeneratedVariantsMatrixIfNeeded();
    orangeProductInvalidateVariantsReadyForSave();
}

function orangeRefreshSizePickPanel() {
    const panel = document.getElementById('product_size_pick_panel');
    const mount = document.getElementById('product_size_pick_checkboxes');
    const emptyNote = document.getElementById('product_size_pick_empty');
    if (!panel || !mount) {
        return;
    }
    const allowSz = orangeProductBasicRecordIsEdit() || orangeProductBasicPriceOk();
    if (!allowSz) {
        panel.style.display = 'none';
        if (emptyNote) {
            emptyNote.style.display = 'none';
        }
        return;
    }
    const hs = orangeProductEffectiveHasSizes();
    const hc = document.getElementById('has_colors') && document.getElementById('has_colors').value === '1';
    if (hs && hc) {
        panel.style.display = 'none';
        mount.innerHTML = '';
        if (emptyNote) {
            emptyNote.style.display = 'none';
        }
        return;
    }
    const famSel = document.getElementById('size_family_id');
    const famId = famSel ? (parseInt(famSel.value, 10) || 0) : 0;
    if (!hs || !famId) {
        panel.style.display = 'none';
        mount.innerHTML = '';
        if (emptyNote) {
            emptyNote.style.display = 'none';
        }
        return;
    }
    const sizes = sizesForFamily(famId);
    panel.style.display = 'block';
    mount.innerHTML = '';
    if (!sizes.length) {
        if (emptyNote) {
            emptyNote.style.display = 'block';
        }
        return;
    }
    if (emptyNote) {
        emptyNote.style.display = 'none';
    }
    sizes.forEach(function (sz) {
        const sid = parseInt(String(sz.id != null ? sz.id : '0'), 10) || 0;
        if (sid <= 0) {
            return;
        }
        const wrap = document.createElement('label');
        wrap.className = 'product-size-pick-item';
        wrap.style.display = 'inline-flex';
        wrap.style.alignItems = 'center';
        wrap.style.gap = '6px';
        wrap.style.cursor = 'pointer';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'product-size-pick-cb';
        cb.value = String(sid);
        cb.checked = false;
        const span = document.createElement('span');
        const t = String(sz.label_ar || sz.label_en || ('#' + sid)).replace(/</g, '');
        span.textContent = t;
        wrap.appendChild(cb);
        wrap.appendChild(span);
        mount.appendChild(wrap);
        cb.addEventListener('change', function () {
            orangeProductClearGeneratedVariantsMatrixIfNeeded();
            orangeProductInvalidateVariantsReadyForSave();
        });
    });
}

function orangeApplySizePickFromVariantMatrix(vm) {
    const hs = orangeProductEffectiveHasSizes();
    const hc = document.getElementById('has_colors') && document.getElementById('has_colors').value === '1';
    if (!hs) {
        return;
    }
    if (hc) {
        return;
    }
    const famSel = document.getElementById('size_family_id');
    const famId = famSel ? (parseInt(famSel.value, 10) || 0) : 0;
    if (!famId) {
        return;
    }
    orangeRefreshSizePickPanel();
    const idsInVm = new Set();
    (vm || []).forEach(function (r) {
        const z = parseInt(String(r.size_family_size_id != null ? r.size_family_size_id : '0'), 10) || 0;
        if (z > 0) {
            idsInVm.add(z);
        }
    });
    if (!idsInVm.size) {
        return;
    }
    document.querySelectorAll('#product_size_pick_checkboxes .product-size-pick-cb').forEach(function (cb) {
        const id = parseInt(cb.value, 10) || 0;
        cb.checked = idsInVm.has(id);
    });
}

function orangeGetPickedSizesForVariantGen(famId, allSizes) {
    const mount = document.getElementById('product_size_pick_checkboxes');
    if (!mount || !mount.querySelector('.product-size-pick-cb')) {
        return [];
    }
    const checked = Array.from(mount.querySelectorAll('.product-size-pick-cb:checked'))
        .map(function (cb) {
            return parseInt(cb.value, 10) || 0;
        })
        .filter(function (id) {
            return id > 0;
        });
    if (!checked.length) {
        return [];
    }
    const want = new Set(checked);
    return allSizes.filter(function (sz) {
        return want.has(parseInt(String(sz.id != null ? sz.id : '0'), 10) || 0);
    });
}

function generateVariants() {
    const wizErr = orangeProductValidateWizardBeforeMatrix();
    if (wizErr) {
        productFormShowTab(wizErr.tab);
        alert(wizErr.message);
        return;
    }
    assignMainImageFromGalleryIfEmpty();
    const hasC = document.getElementById('has_colors').value === '1';
    const hasS = orangeProductEffectiveHasSizes();
    const famId = parseInt(document.getElementById('size_family_id').value, 10) || 0;
    const box = document.getElementById('variantsBox');

    if (hasS && !famId) {
        productFormShowTab('basic');
        alert('اختر عائلة مقاسات من البيانات الأساسية.');
        return;
    }
    if (hasC) {
        const rows = document.querySelectorAll('#colorwaysBox .cw-row');
        if (!rows.length) {
            alert('أضف صف لون واحد على الأقل');
            return;
        }
    }

    let sizeLookupList = [{ id: 0, label_ar: '', label_en: '' }];
    if (hasS) {
        const allSz0 = sizesForFamily(famId);
        if (!allSz0.length) {
            alert('لا توجد مقاسات في العائلة المختارة');
            return;
        }
        sizeLookupList = allSz0;
    }

    let combos = [];
    if (!hasC && !hasS) {
        combos.push({ primary_color_id: 0, secondary_color_id: 0, primary_pattern_id: 0, secondary_pattern_id: 0, size_family_size_id: 0, stock: 0 });
    } else if (hasC && hasS) {
        let anyCombo = false;
        document.querySelectorAll('#colorwaysBox .cw-row').forEach(function (row) {
            const p = parseInt(row.querySelector('.cw-p').value, 10) || 0;
            const s = parseInt(row.querySelector('.cw-s').value, 10) || 0;
            const pp = parseInt((row.querySelector('.cw-pp') && row.querySelector('.cw-pp').value) || '0', 10) || 0;
            const sp = parseInt((row.querySelector('.cw-sp') && row.querySelector('.cw-sp').value) || '0', 10) || 0;
            if (!p) {
                return;
            }
            const cbs = row.querySelectorAll('.cw-size-cb:checked');
            if (!cbs.length) {
                return;
            }
            anyCombo = true;
            cbs.forEach(function (cb) {
                const zid = parseInt(cb.value, 10) || 0;
                if (zid <= 0) {
                    return;
                }
                combos.push({
                    primary_color_id: p,
                    secondary_color_id: s,
                    primary_pattern_id: pp,
                    secondary_pattern_id: sp,
                    size_family_size_id: zid,
                    stock: 0
                });
            });
        });
        if (!anyCombo || !combos.length) {
            alert('لكل صف لون حدّد مقاساً واحداً على الأقل من قائمة المقاسات أسفل الألوان (واختر عائلة من البيانات الأساسية).');
            return;
        }
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
        const picked = orangeGetPickedSizesForVariantGen(famId, sizeLookupList);
        if (!picked.length) {
            alert('حدّد مقاساً واحداً على الأقل من قائمة المقاسات (منتج بمقاسات دون ألوان).');
            return;
        }
        picked.forEach(function (sz) {
            combos.push({ primary_color_id: 0, secondary_color_id: 0, primary_pattern_id: 0, secondary_pattern_id: 0, size_family_size_id: sz.id, stock: 0 });
        });
    }

    if (!combos.length) {
        alert('لا توجد تركيبات');
        return;
    }

    let html = '<p class="admin-variants-lead"><strong>منتج بلا ألوان وبلا مقاسات:</strong> صف واحد = SKU واحد وباركود واحد بعد الحفظ. <strong>منتج بلون أو بمقاسات:</strong> كل صف يمثل نفس الصنف مع دمج لون ونمط اختياري × مقاس. عمود «صورة المرجع»: عند رفع صور لكل لون من تبويب الألوان تُعرض صورة ذلك اللون لكل صف؛ وإلا تُستخدم الصورة الرئيسية أو معرض الصور العام. <strong>الكميات:</strong> لا تُدخل من هنا — بعد الحفظ عالج المخزون من <a href="' + adminPublicPath('/admin/index.php?page=stock') + '">شاشة المخزون</a> (رصيد افتتاحي أو تعديل) أو من <a href="' + adminPublicPath('/admin/index.php?page=purchases') + '">استلام فاتورة شراء</a>.</p>';
    html += '<div class="table-wrap admin-table-wrap-elevated"><table class="admin-table admin-variants-matrix"><thead><tr>';
    html += '<th class="col-ref-img">صورة المرجع</th><th>اللون</th><th>المقاس</th><th class="col-vbar">باركود المتغير (بعد الحفظ)</th><th class="col-stock">المخزون الحالي (عرض)</th>';
    html += '</tr></thead><tbody>';
    combos.forEach((c, idx) => {
        const sz = sizeLookupList.find(x => String(x.id) === String(c.size_family_size_id));
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
        const thumbCell = adminVariantReferenceThumbHtmlForColorway(
            c.primary_color_id,
            c.secondary_color_id,
            c.primary_pattern_id || 0,
            c.secondary_pattern_id || 0
        );
        html += `<tr>
            <td class="td-ref-img">${thumbCell}</td>
            <td>${colorCell}</td>
            <td><span class="admin-variant-size-pill">${szLabel}</span><input type="hidden" class="v-zid" value="${c.size_family_size_id}"></td>
            <td class="td-vbar"><code class="v-barcode-display admin-variant-barcode-readonly" dir="ltr" lang="en">—</code> <button type="button" class="btn-secondary" style="font-size:11px;padding:2px 8px;" onclick="orangeCopyVariantBarcode(this)">نسخ</button></td>
            <td class="td-stock"><span class="v-stock-display admin-variant-stock-readonly" data-idx="${idx}">0</span><input type="hidden" class="v-stock" value="0" tabindex="-1" autocomplete="off"></td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    box.innerHTML = html;
    if (orangeProductWizardIsNew()) {
        window.ORANGE_PRODUCT_VARIANTS_READY_FOR_SAVE = true;
        orangeApplyProductWizardActionButtons();
    }
    productFormShowTab('variants');
    orangeScheduleProductCardPreviewRefresh();
}

async function saveProduct() {
    const wizErr = orangeProductValidateWizardBeforeMatrix();
    if (wizErr) {
        productFormShowTab(wizErr.tab);
        alert(wizErr.message.replace('قبل المتابعة.', 'قبل الحفظ.'));
        return;
    }

    if (orangeProductWizardIsNew() && !window.ORANGE_PRODUCT_VARIANTS_READY_FOR_SAVE) {
        productFormShowTab('variants');
        alert('اضغط «توليد المتغيرات» أولاً بعد اكتمال البيانات، ثم احفظ.');
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
            has_sizes: orangeProductEffectiveHasSizes() ? 1 : 0,
            has_colors: parseInt(document.getElementById('has_colors').value, 10),
            size_family_id: parseInt(document.getElementById('size_family_id').value, 10) || 0,
            ...orangeProductSizingSaveFields(),
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
        payload.colorway_images = orangeCollectColorwayImagesPayload();
        const hsUp = orangeProductEffectiveHasSizes();
        const hcUp = parseInt(document.getElementById('has_colors').value, 10) === 1;
        let varRowsUp = Array.from(document.querySelectorAll('#variantsBox tbody tr'));
        if (!varRowsUp.length && orangeProductIsSimpleSkuMatrix()) {
            generateVariants();
            varRowsUp = Array.from(document.querySelectorAll('#variantsBox tbody tr'));
        }
        if ((hsUp || hcUp) && !varRowsUp.length) {
            productFormShowTab('variants');
            alert('ولّد المتغيرات أو حمّل المصفوفة قبل التحديث');
            return;
        }
        if (orangeProductIsSimpleSkuMatrix() && !varRowsUp.length) {
            productFormShowTab('variants');
            alert('تعذر تجهيز صف البيع — استخدم «توليد جدول البيع / المتغيرات» ثم احفظ.');
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
            if (res.item_code && document.getElementById('product_item_code')) {
                document.getElementById('product_item_code').value = String(res.item_code);
            }
            if (res.barcode != null && res.barcode !== '' && document.getElementById('product_barcode')) {
                document.getElementById('product_barcode').value = String(res.barcode);
            }
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
        has_sizes: orangeProductEffectiveHasSizes() ? 1 : 0,
        has_colors: parseInt(document.getElementById('has_colors').value, 10),
        size_family_id: parseInt(document.getElementById('size_family_id').value, 10) || 0,
        ...orangeProductSizingSaveFields(),
        is_active: parseInt(document.getElementById('product_is_active').value, 10),
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
    payload.colorway_images = orangeCollectColorwayImagesPayload();

    const res = await postJSON('/admin/api/products/create.php', payload);
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
    if (res.success) {
        if (res.item_code && document.getElementById('product_item_code')) {
            document.getElementById('product_item_code').value = String(res.item_code);
        }
        if (res.barcode != null && res.barcode !== '' && document.getElementById('product_barcode')) {
            document.getElementById('product_barcode').value = String(res.barcode);
        }
        location.reload();
    }
}

document.getElementById('name').addEventListener('input', scheduleProductAutoTranslate);
document.getElementById('name_en').addEventListener('input', scheduleProductTranslateFromEnglish);
document.getElementById('description').addEventListener('input', scheduleProductDescriptionAutoTranslate);
document.getElementById('description_en').addEventListener('input', scheduleProductDescriptionFromEnglish);

function updateProductCatalogHint() {
    const hint = document.getElementById('product_department_hint');
    const ptEl = document.getElementById('product_type_id');
    const trailMap = window.ORANGE_PRODUCT_TYPE_TRAIL || {};
    if (window.ORANGE_CATALOG_NAV_UNIFIED) {
        if (!hint || !ptEl) {
            return;
        }
        const pid = parseInt(ptEl.value || '0', 10) || 0;
        const row = trailMap[pid];
        if (!row || !String(row.trail_ar || '').trim()) {
            hint.textContent = pid > 0 ? '— اختر نوع منتج نشط لعرض المسار' : '—';
            return;
        }
        hint.textContent = String(row.trail_ar || '').trim();
        return;
    }
    const sel = document.getElementById('category_id');
    if (!hint || !sel) {
        return;
    }
    const id = parseInt(sel.value, 10) || 0;
    const meta = window.ORANGE_CATEGORY_META && window.ORANGE_CATEGORY_META[id];
    if (!meta) {
        hint.textContent = '—';
        return;
    }
    if (meta.dept_id > 0 && meta.dept_label) {
        hint.textContent = meta.dept_label + ' (#' + meta.dept_id + ')';
    } else if (meta.dept_id > 0) {
        hint.textContent = 'قسم #' + meta.dept_id;
    } else {
        hint.textContent = 'بدون قسم — فعّل الشجرة الموحّدة أو اربط القسم عبر الترحيل';
    }
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
        orangeProductInvalidateVariantsReadyForSave();
        orangeApplyProductTypeDepartmentFilter(true);
    });
}
if (orangeProductTypeSelectEl) {
    orangeProductTypeSelectEl.addEventListener('change', function () {
        orangeProductInvalidateVariantsReadyForSave();
        orangeSyncMainDepartmentFromProductType();
        if (window.ORANGE_PT_DEPT_STEP_ENABLED === true) {
            orangeApplyProductTypeDepartmentFilter(true);
        }
        orangeSyncLegacyFieldsFromProductType();
        orangeApplyProductBasicStepLocks();
        if (window.ORANGE_CATALOG_NAV_UNIFIED) {
            updateProductCatalogHint();
        }
    });
}
const orangeSizeFamilySelectEl = document.getElementById('size_family_id');
if (orangeSizeFamilySelectEl) {
    orangeSizeFamilySelectEl.addEventListener('change', function () {
        orangeProductClearGeneratedVariantsMatrixIfNeeded();
        orangeProductInvalidateVariantsReadyForSave();
        orangeApplyProductBasicStepLocks();
        void orangeProductRefreshAdvisoryGuideSelect(0);
    });
}
const btnRemoveMainProductImage = document.getElementById('btn_remove_main_product_image');
if (btnRemoveMainProductImage) {
    btnRemoveMainProductImage.addEventListener('click', function () {
        orangeRemoveMainProductImageDesignation();
    });
}

const mainImageFileEl = document.getElementById('main_image_file');
if (mainImageFileEl) {
    mainImageFileEl.addEventListener('change', function () {
        orangeRefreshMainImageFileInputPreview();
    });
}
const btnClearMainImageFileSel = document.getElementById('btn_clear_main_image_file_selection');
if (btnClearMainImageFileSel) {
    btnClearMainImageFileSel.addEventListener('click', function () {
        const inp = document.getElementById('main_image_file');
        if (inp) {
            inp.value = '';
        }
        orangeRefreshMainImageFileInputPreview();
    });
}
const galleryFilesEl = document.getElementById('gallery_files');
if (galleryFilesEl) {
    galleryFilesEl.addEventListener('change', function () {
        orangeRenderGalleryPendingPreviews();
    });
}
const btnClearGalleryFileSel = document.getElementById('btn_clear_gallery_file_selection');
if (btnClearGalleryFileSel) {
    btnClearGalleryFileSel.addEventListener('click', function () {
        const inp = document.getElementById('gallery_files');
        if (inp) {
            inp.value = '';
        }
        orangeRenderGalleryPendingPreviews();
    });
}

const orangeCatalogAttrAddRowBtnEl = document.getElementById('orangeCatalogAttrAddRowBtn');
if (orangeCatalogAttrAddRowBtnEl) {
    orangeCatalogAttrAddRowBtnEl.addEventListener('click', function () {
        orangeCatalogAttrAddEmptyRow();
    });
}

const orangeColorwaysBoxEl = document.getElementById('colorwaysBox');
if (orangeColorwaysBoxEl) {
    orangeColorwaysBoxEl.addEventListener('click', function (ev) {
        const rm = ev.target.closest && ev.target.closest('.cw-gallery-remove');
        if (rm) {
            const li = rm.closest('li');
            if (li) {
                li.remove();
            }
            orangeRefreshVariantReferenceThumbs();
            orangeScheduleProductCardPreviewRefresh();
            return;
        }
        const up = ev.target.closest && ev.target.closest('.cw-gallery-upload-btn');
        if (up) {
            const row = up.closest('.cw-row');
            const finp = row && row.querySelector('.cw-gallery-files');
            if (finp) {
                finp.click();
            }
        }
    });
    orangeColorwaysBoxEl.addEventListener('change', function (ev) {
        const t = ev.target;
        if (t && t.classList && t.classList.contains('cw-gallery-files') && t.files && t.files.length) {
            const row = t.closest('.cw-row');
            orangeUploadColorwayGalleryFiles(row, t.files).then(function () {
                t.value = '';
            });
            return;
        }
        if (window.ORANGE_CW_SIZE_SILENT) {
            return;
        }
        if (t && t.classList && t.classList.contains('cw-size-cb') && !t.checked) {
            if (!orangeColorwaySizeCheckboxBeforeUncheck(t)) {
                orangeScheduleProductCardPreviewRefresh();
                orangeApplyProductWizardActionButtons();
                return;
            }
        }
        if (
            t &&
            t.classList &&
            (
                t.classList.contains('cw-p') ||
                t.classList.contains('cw-s') ||
                t.classList.contains('cw-pp') ||
                t.classList.contains('cw-sp') ||
                t.classList.contains('cw-size-cb')
            )
        ) {
            orangeProductClearGeneratedVariantsMatrixIfNeeded();
            orangeProductInvalidateVariantsReadyForSave();
        }
        orangeScheduleProductCardPreviewRefresh();
        orangeApplyProductWizardActionButtons();
    });
}
rebuildSubcategoryOptions(null);
updateProductCatalogHint();

setProductFormEditMode(false);
orangeClearCatalogAttributeInputs();
orangeApplyProductBasicStepLocks();
orangeNormalizeProductTabPanelsNoGap();
const orangePreviewModeEl = document.getElementById('orangeAdminProductPreviewMode');
if (orangePreviewModeEl) {
    orangePreviewModeEl.addEventListener('change', function () {
        orangeRefreshProductCardPreview();
    });
}
const orangePreviewRefreshNowBtn = document.getElementById('orangeAdminProductPreviewRefreshNow');
if (orangePreviewRefreshNowBtn) {
    orangePreviewRefreshNowBtn.addEventListener('click', function () {
        orangeRefreshProductCardPreview();
    });
}

['name', 'name_en', 'name_fil', 'name_hi', 'price', 'cost'].forEach(function (id) {
    const el = document.getElementById(id);
    if (el) {
        const refresh = function () {
            if (id === 'name' || id === 'name_en' || id === 'name_fil' || id === 'name_hi' || id === 'price' || id === 'cost') {
                orangeProductInvalidateVariantsReadyForSave();
            }
            orangeApplyProductBasicStepLocks();
            if (id === 'name' || id === 'name_en' || id === 'name_fil' || id === 'name_hi' || id === 'price') {
                orangeScheduleProductCardPreviewRefresh();
            }
        };
        el.addEventListener('input', refresh);
        el.addEventListener('change', refresh);
        el.addEventListener('paste', function () {
            queueMicrotask(refresh);
        });
        el.addEventListener('cut', refresh);
    }
});
orangeScheduleProductCardPreviewRefresh();

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

(function () {
    function seoPlainFromHtml(html) {
        const tmp = document.createElement('div');
        tmp.innerHTML = html || '';
        return (tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
    }
    function seoTruncate(text, max) {
        text = (text || '').trim();
        if (!text) return '';
        return text.length > max ? text.slice(0, Math.max(0, max - 3)) + '...' : text;
    }
    function seoEffectiveAr() {
        const nameEl = document.getElementById('name');
        const descEl = document.getElementById('description');
        const titleEl = document.getElementById('seo_meta_title_ar');
        const metaDescEl = document.getElementById('seo_meta_description_ar');
        const name = nameEl ? nameEl.value.trim() : '';
        const descSrc = descEl ? descEl.value.trim() : '';
        let title = titleEl ? titleEl.value.trim() : '';
        let desc = metaDescEl ? metaDescEl.value.trim() : '';
        if (!title && name) title = seoTruncate(name, 191);
        if (!desc && descSrc) desc = seoTruncate(seoPlainFromHtml(descSrc), 160);
        return { title: title || '—', desc: desc || '—' };
    }
    function refreshSeoEffectivePreview() {
        const titleNode = document.getElementById('seoEffectivePreviewTitle');
        const descNode = document.getElementById('seoEffectivePreviewDesc');
        if (!titleNode || !descNode) return;
        const eff = seoEffectiveAr();
        titleNode.textContent = eff.title;
        descNode.textContent = eff.desc;
    }
    [
        'name', 'description',
        'seo_meta_title_ar', 'seo_meta_description_ar'
    ].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', refreshSeoEffectivePreview);
    });
    refreshSeoEffectivePreview();
    window.orangeRefreshSeoEffectivePreview = refreshSeoEffectivePreview;
})();
</script>
