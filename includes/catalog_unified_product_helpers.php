<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/catalog_sizing_dictionary.php';

/**
 * يستنتج تصنيف المنتج: مصدر الحقيقة **`product_type_id`** فقط (المرحلة 5 — لا category_id على المنتج).
 *
 * @return array{product_type_id:int}|array{error:string}
 */
function orange_catalog_resolve_product_classification(PDO $pdo, array $data): array
{
    $ptIn = isset($data['product_type_id']) ? (int) $data['product_type_id'] : 0;
    if (!orange_table_exists($pdo, 'product_types')) {
        return ['error' => 'جدول أنواع المنتجات غير متوفر. أكمل تهيئة الشجرة الموحّدة أولاً.'];
    }
    if ($ptIn <= 0) {
        return ['error' => 'يجب اختيار «نوع المنتج» في الشجرة الموحّدة (ورقة product_types).'];
    }

    $st = $pdo->prepare('SELECT id FROM product_types WHERE id = ? LIMIT 1');
    $st->execute([$ptIn]);
    if (! $st->fetchColumn()) {
        return ['error' => 'نوع المنتج المختار غير موجود.'];
    }

    return [
        'product_type_id' => $ptIn,
    ];
}

/**
 * جزء واحد من كود الصنف: slug لاتيني/أرقام مختصر، أو i{id} عند الفراغ.
 * (مُبقى للتوافق؛ الكود الحالي رقمي بحت — انظر orange_catalog_generate_product_item_code_from_tree).
 */
function orange_catalog_item_code_segment_from_slug(?string $slug, int $id): string
{
    $s = strtolower(trim((string) $slug));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    if ($s !== '') {
        return strlen($s) > 14 ? substr($s, 0, 14) : $s;
    }

    return 'i' . max(0, $id);
}

/**
 * الترتيب الرقمي (1-based) لعقدة شجرة ضمن أبيها حسب (sort_order, id).
 * أساس الترقيم الرقمي للكود؛ مُشتق تلقائياً (قد يتغيّر عند إعادة الترتيب أو حذف عقدة أسبق).
 * أسماء الجداول تأتي من قائمة ثابتة داخلية (ليست مدخلات مستخدم).
 */
function orange_catalog_node_ordinal(PDO $pdo, string $table, ?string $parentColumn, ?int $parentId, int $rowId): int
{
    if ($rowId <= 0) {
        return 0;
    }
    try {
        $st = $pdo->prepare("SELECT sort_order FROM {$table} WHERE id = ? LIMIT 1");
        $st->execute([$rowId]);
        $so = $st->fetchColumn();
        if ($so === false) {
            return 0;
        }
        $so = (int) $so;
        $where = '(sort_order < ? OR (sort_order = ? AND id <= ?))';
        $params = [$so, $so, $rowId];
        if ($parentColumn !== null && $parentId !== null) {
            $where = "{$parentColumn} = ? AND " . $where;
            array_unshift($params, $parentId);
        }
        $st2 = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        $st2->execute($params);

        return (int) $st2->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * تسلسل المنتج الرقمي (1-based) داخل نوع المنتج حسب (sort_order, id).
 */
function orange_catalog_product_ordinal_in_type(PDO $pdo, int $productTypeId, int $productId): int
{
    if ($productTypeId <= 0 || $productId <= 0) {
        return 0;
    }
    try {
        $st = $pdo->prepare('SELECT sort_order FROM products WHERE id = ? LIMIT 1');
        $st->execute([$productId]);
        $so = $st->fetchColumn();
        $so = ($so === false) ? 0 : (int) $so;
        $st2 = $pdo->prepare(
            'SELECT COUNT(*) FROM products
             WHERE product_type_id = ?
               AND (sort_order < ? OR (sort_order = ? AND id <= ?))'
        );
        $st2->execute([$productTypeId, $so, $so, $productId]);

        return (int) $st2->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * يولّد كود صنف داخلي من سلسلة الشجرة الموحّدة (قسم ← قسم كتالوج ← فئة ← تحت-فئة ← نوع) + معرف المنتج لضمان التفرد.
 * يُعاد null إن تعذّر الربط (لا يوجد مسار موحّد لهذا product_type_id).
 *
 * @return string|null
 */
function orange_catalog_generate_product_item_code_from_tree(PDO $pdo, int $productTypeId, int $productId): ?string
{
    if ($productTypeId <= 0 || $productId <= 0) {
        return null;
    }
    foreach (['product_types', 'catalog_subcategories', 'catalog_categories', 'catalog_sections', 'departments'] as $t) {
        if (! orange_table_exists($pdo, $t)) {
            return null;
        }
    }
    if (! orange_table_has_column($pdo, 'product_types', 'catalog_subcategory_id')) {
        return null;
    }

    $dSlug = orange_table_has_column($pdo, 'departments', 'slug') ? 'd.slug AS dep_slug' : "'' AS dep_slug";
    $csSlug = orange_table_has_column($pdo, 'catalog_sections', 'slug') ? 'cs.slug AS sec_slug' : "'' AS sec_slug";
    $ccSlug = orange_table_has_column($pdo, 'catalog_categories', 'slug') ? 'cc.slug AS cat_slug' : "'' AS cat_slug";
    $csubSlug = orange_table_has_column($pdo, 'catalog_subcategories', 'slug') ? 'csub.slug AS sub_slug' : "'' AS sub_slug";
    $ptSlug = orange_table_has_column($pdo, 'product_types', 'slug') ? 'pt.slug AS pt_slug' : "'' AS pt_slug";

    try {
        $st = $pdo->prepare(
            "SELECT d.id AS dep_id, cs.id AS sec_id, cc.id AS cat_id, csub.id AS sub_id, pt.id AS pt_id,
                    {$dSlug}, {$csSlug}, {$ccSlug}, {$csubSlug}, {$ptSlug}
             FROM product_types pt
             INNER JOIN catalog_subcategories csub ON csub.id = pt.catalog_subcategory_id
             INNER JOIN catalog_categories cc ON cc.id = csub.catalog_category_id
             INNER JOIN catalog_sections cs ON cs.id = cc.catalog_section_id
             INNER JOIN departments d ON d.id = cs.department_id
             WHERE pt.id = ?
             LIMIT 1"
        );
        $st->execute([$productTypeId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    if (! is_array($row)) {
        return null;
    }

    $depId = (int) ($row['dep_id'] ?? 0);
    $secId = (int) ($row['sec_id'] ?? 0);
    $catId = (int) ($row['cat_id'] ?? 0);
    $subId = (int) ($row['sub_id'] ?? 0);
    $ptId = (int) ($row['pt_id'] ?? 0);
    if ($depId <= 0 || $ptId <= 0) {
        return null;
    }

    /*
     * كود رقمي بحت (قرار المالك 2026-06-16): DD SS CC UU TT NNNN
     *   DD=القسم، SS=قسم الكتالوج، CC=الفئة، UU=تحت‑الفئة، TT=نوع المنتج (خانتان لكلٍّ، الترتيب داخل الأب)
     *   NNNN=تسلسل المنتج داخل نوع المنتج (4 خانات). الإجمالي 14 رقماً.
     * الترقيم مُشتق تلقائياً من (sort_order, id)؛ قد يتغيّر عند إعادة الترتيب/الحذف.
     * لو تجاوز مستوى 99 ابناً (أو المنتجات 9999) يتمدّد عدد الخانات بدل التصادم.
     */
    $dd = orange_catalog_node_ordinal($pdo, 'departments', null, null, $depId);
    $ss = orange_catalog_node_ordinal($pdo, 'catalog_sections', 'department_id', $depId, $secId);
    $cc = orange_catalog_node_ordinal($pdo, 'catalog_categories', 'catalog_section_id', $secId, $catId);
    $uu = orange_catalog_node_ordinal($pdo, 'catalog_subcategories', 'catalog_category_id', $catId, $subId);
    $tt = orange_catalog_node_ordinal($pdo, 'product_types', 'catalog_subcategory_id', $subId, $ptId);
    $seq = orange_catalog_product_ordinal_in_type($pdo, $productTypeId, $productId);

    return sprintf('%02d%02d%02d%02d%02d%04d', $dd, $ss, $cc, $uu, $tt, $seq);
}

/**
 * يحدّث باركود المنتج وباركود كل متغير من بصمة SHA-256 (64 hex) لمحتوى كانوني
 * يشمل: كود الصنف، نوع المنتج، القسم، الاسم العربي، السمات، وكل المتغيرات (مقاس ولون/colorway).
 * لا يُقصد بهذا توليد EAN-13 قياسي؛ الحقل الداخلي varchar(64) يستوعب البصمة فقط.
 *
 * @return array{product_barcode:?string, variant_updates:int}
 */
function orange_catalog_refresh_product_barcodes(PDO $pdo, int $productId): array
{
    $out = ['product_barcode' => null, 'variant_updates' => 0];
    if ($productId <= 0 || ! orange_table_exists($pdo, 'products')) {
        return $out;
    }
    if (! orange_table_has_column($pdo, 'products', 'barcode')) {
        return $out;
    }

    $joinDept = '';
    if (
        orange_table_exists($pdo, 'product_types')
        && orange_table_has_column($pdo, 'products', 'product_type_id')
        && orange_table_exists($pdo, 'catalog_subcategories')
        && orange_table_exists($pdo, 'catalog_categories')
        && orange_table_exists($pdo, 'catalog_sections')
        && orange_table_exists($pdo, 'departments')
    ) {
        $joinDept = '
            LEFT JOIN product_types orange_bc_pt ON orange_bc_pt.id = p.product_type_id
            LEFT JOIN catalog_subcategories orange_bc_csub ON orange_bc_csub.id = orange_bc_pt.catalog_subcategory_id
            LEFT JOIN catalog_categories orange_bc_cc ON orange_bc_cc.id = orange_bc_csub.catalog_category_id
            LEFT JOIN catalog_sections orange_bc_cs ON orange_bc_cs.id = orange_bc_cc.catalog_section_id
            LEFT JOIN departments orange_bc_d ON orange_bc_d.id = orange_bc_cs.department_id';
    }

    try {
        if ($joinDept === '') {
            $st = $pdo->prepare(
                'SELECT p.id, p.name, p.item_code, p.product_type_id, p.has_sizes, p.has_colors, 0 AS dept_id
                 FROM products p WHERE p.id = ? LIMIT 1'
            );
        } else {
            $st = $pdo->prepare(
                'SELECT p.id, p.name, p.item_code, p.product_type_id, p.has_sizes, p.has_colors,
                        COALESCE(orange_bc_d.id, 0) AS dept_id
                 FROM products p'
                . $joinDept . '
                 WHERE p.id = ?
                 LIMIT 1'
            );
        }
        $st->execute([$productId]);
        $prow = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $out;
    }
    if (! is_array($prow)) {
        return $out;
    }

    $pid = (int) ($prow['id'] ?? 0);
    $itemCode = trim((string) ($prow['item_code'] ?? ''));
    $ptId = (int) ($prow['product_type_id'] ?? 0);
    $deptId = (int) ($prow['dept_id'] ?? 0);
    $nameAr = trim((string) ($prow['name'] ?? ''));
    $hasSizes = (int) ($prow['has_sizes'] ?? 0);
    $hasColors = (int) ($prow['has_colors'] ?? 0);

    $attrRows = [];
    if (orange_table_exists($pdo, 'product_attribute_values')) {
        try {
            $a = $pdo->prepare(
                'SELECT catalog_attribute_id, value_raw FROM product_attribute_values WHERE product_id = ? ORDER BY catalog_attribute_id ASC'
            );
            $a->execute([$pid]);
            while ($ar = $a->fetch(PDO::FETCH_ASSOC)) {
                if (! is_array($ar)) {
                    continue;
                }
                $attrRows[] = [
                    'id' => (int) ($ar['catalog_attribute_id'] ?? 0),
                    'v' => trim((string) ($ar['value_raw'] ?? '')),
                ];
            }
        } catch (Throwable $e) {
            $attrRows = [];
        }
    }

    $varRows = [];
    if (orange_table_exists($pdo, 'product_variants')) {
        try {
            $v = $pdo->prepare(
                'SELECT id, product_colorway_id, size_family_size_id, size, color
                 FROM product_variants WHERE product_id = ? ORDER BY id ASC'
            );
            $v->execute([$pid]);
            while ($vr = $v->fetch(PDO::FETCH_ASSOC)) {
                if (! is_array($vr)) {
                    continue;
                }
                $varRows[] = [
                    'id' => (int) ($vr['id'] ?? 0),
                    'cw' => isset($vr['product_colorway_id']) && $vr['product_colorway_id'] !== null ? (int) $vr['product_colorway_id'] : null,
                    'szid' => isset($vr['size_family_size_id']) && $vr['size_family_size_id'] !== null ? (int) $vr['size_family_size_id'] : null,
                    'sz' => trim((string) ($vr['size'] ?? '')),
                    'clr' => trim((string) ($vr['color'] ?? '')),
                ];
            }
        } catch (Throwable $e) {
            $varRows = [];
        }
    }

    $encode = static function (array $payload): string {
        $flags = JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $json = json_encode($payload, $flags);
        if ($json === false) {
            $json = '{}';
        }

        return hash('sha256', $json);
    };

    $productCanon = [
        'bc_v' => 1,
        'pid' => $pid,
        'ic' => $itemCode,
        'pt' => $ptId,
        'dep' => $deptId,
        'n' => $nameAr,
        'hs' => $hasSizes,
        'hc' => $hasColors,
        'attrs' => $attrRows,
        'vars' => $varRows,
    ];
    $productBc = $encode($productCanon);
    $out['product_barcode'] = $productBc;

    try {
        $pdo->prepare('UPDATE products SET barcode = ? WHERE id = ? LIMIT 1')->execute([$productBc, $pid]);
    } catch (Throwable $e) {
        return $out;
    }

    if (! orange_table_exists($pdo, 'product_variants') || ! orange_table_has_column($pdo, 'product_variants', 'barcode')) {
        return $out;
    }

    $updV = $pdo->prepare('UPDATE product_variants SET barcode = ? WHERE id = ? LIMIT 1');
    foreach ($varRows as $vr) {
        $vid = (int) ($vr['id'] ?? 0);
        if ($vid <= 0) {
            continue;
        }
        $vCanon = [
            'bc_v' => 1,
            'pid' => $pid,
            'vid' => $vid,
            'ic' => $itemCode,
            'pt' => $ptId,
            'dep' => $deptId,
            'n' => $nameAr,
            'attrs' => $attrRows,
            'cw' => $vr['cw'],
            'szid' => $vr['szid'],
            'sz' => $vr['sz'],
            'clr' => $vr['clr'],
        ];
        $vbc = $encode($vCanon);
        try {
            $updV->execute([$vbc, $vid]);
            $out['variant_updates']++;
        } catch (Throwable $e) {
            continue;
        }
    }

    return $out;
}

/**
 * سلسلة LEFT JOIN لعرض صف فئة باسم مستعار `c` (name_ar / name_en) في استعلامات الأدمن عن المنتجات.
 * مصدر العرض الموحّد فقط: `c` = catalog_categories عبر product_type_id.
 * عند غياب بنية الشجرة الموحّدة الكاملة تُعاد قيم null دون الرجوع إلى taxonomy legacy.
 *
 * @param string|null $existingProductTypesAlias اسم مستعار لـ product_types إن وُجد مسبقاً في الاستعلام (مثل pt)؛
 *        null = يُنشأ JOIN داخلي باسم orange_disp_pt عند الحاجة.
 */
function orange_catalog_admin_sql_join_product_category_display(PDO $pdo, string $productsAlias = 'p', ?string $existingProductTypesAlias = null): string
{
    $p = preg_replace('/[^A-Za-z0-9_]/', '', $productsAlias) ?: 'p';
    if (orange_table_exists($pdo, 'product_types')
        && orange_table_exists($pdo, 'catalog_subcategories')
        && orange_table_exists($pdo, 'catalog_categories')
        && orange_table_has_column($pdo, 'products', 'product_type_id')
    ) {
        $pt = null;
        if ($existingProductTypesAlias !== null && $existingProductTypesAlias !== '') {
            $pt = preg_replace('/[^A-Za-z0-9_]/', '', $existingProductTypesAlias);
        }
        if ($pt === null || $pt === '') {
            $pt = 'orange_disp_pt';

            return "\n LEFT JOIN product_types {$pt} ON {$pt}.id = {$p}.product_type_id"
                . "\n LEFT JOIN catalog_subcategories orange_disp_ucs ON orange_disp_ucs.id = {$pt}.catalog_subcategory_id"
                . "\n LEFT JOIN catalog_categories c ON c.id = orange_disp_ucs.catalog_category_id";
        }

        return "\n LEFT JOIN catalog_subcategories orange_disp_ucs ON orange_disp_ucs.id = {$pt}.catalog_subcategory_id"
            . "\n LEFT JOIN catalog_categories c ON c.id = orange_disp_ucs.catalog_category_id";
    }

    return "\n LEFT JOIN (SELECT NULL AS id, NULL AS name_ar, NULL AS name_en, NULL AS catalog_section_id) c ON 1=1";
}

/**
 * عند تعيين أو تبديل المعرف إلى نوع منتج جديد: ورقة **product_types** يجب أن تكون نشطة؛
 * يُستثنى الطلب الذي يترك المعرف كما كان (منتج قائم لا يُغيّر النوع) حتى لا يُقفَل التعديل آليًا بعد تعطيل الورقة.
 *
 * @return string|null رسالة خطأ أو null
 */
function orange_catalog_validate_product_type_assignment_active(PDO $pdo, ?int $newProductTypeId, ?int $previousProductTypeId): ?string
{
    $newId = $newProductTypeId !== null && $newProductTypeId > 0 ? $newProductTypeId : null;
    if ($newId === null) {
        return null;
    }
    if (!function_exists('orange_table_exists') || !orange_table_exists($pdo, 'product_types')) {
        return null;
    }
    $prevId = $previousProductTypeId !== null && $previousProductTypeId > 0 ? $previousProductTypeId : null;
    if ($prevId !== null && $prevId === $newId) {
        return null;
    }

    $st = $pdo->prepare('SELECT is_active FROM product_types WHERE id = ? LIMIT 1');
    $st->execute([$newId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return 'نوع المنتج المحدد غير موجود في الشجرة الموحّدة.';
    }
    if ((int) ($row['is_active'] ?? 0) !== 1) {
        return 'يجب أن يكون نوع المنتج نشطًا عند تعيينه أو تبديل إليه. فعّل الورقة من «أنواع المنتجات الموحدة» أو اختر نوعًا نشطًا.';
    }

    return null;
}

/**
 * هرم المقاس على نوع المنتج (سياسة حالية): يُحدَّد بنوع المنتج عبر
 * `expected_commercial_kind_key` + `expected_sizing_category_key` فقط؛ أي `size_scheme_key` (مستوى 3)
 * مسموح على العائلة طالما تطابق 1–2. عمود `expected_size_scheme_key` لم يعد يُستخدم في المنطق.
 *
 * @return string|null رسالة خطأ عربية أو null إن كان التحقق غير لازم أو ناجحاً
 */
function orange_catalog_validate_size_family_matches_product_type(
    PDO $pdo,
    ?int $productTypeId,
    bool $productHasSizes,
    ?int $sizeFamilyId
): ?string {
    if (!$productHasSizes || $sizeFamilyId === null || $sizeFamilyId <= 0) {
        return null;
    }
    if ($productTypeId === null || $productTypeId <= 0) {
        return null;
    }
    if (!function_exists('orange_table_exists') || !orange_table_exists($pdo, 'product_types')) {
        return null;
    }
    $hasExpCk = function_exists('orange_table_has_column') && orange_table_has_column($pdo, 'product_types', 'expected_commercial_kind_key');
    $hasExpSk = function_exists('orange_table_has_column') && orange_table_has_column($pdo, 'product_types', 'expected_sizing_category_key');
    if (! $hasExpCk || ! $hasExpSk) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT expected_commercial_kind_key, expected_sizing_category_key FROM product_types WHERE id = ? LIMIT 1'
    );
    $st->execute([$productTypeId]);
    $pt = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($pt)) {
        return null;
    }
    $expCk = trim((string) ($pt['expected_commercial_kind_key'] ?? ''));
    $expSk = trim((string) ($pt['expected_sizing_category_key'] ?? ''));

    if ($expSk === '' && $expCk === '') {
        return null;
    }
    if ($expSk !== '' && $expCk === '') {
        return 'على نوع المنتج: عند ضبط فئة قياس متوقعة يجب تعبئة النوع التجاري (expected_commercial_kind_key) أيضاً.';
    }
    if ($expCk !== '' && $expSk === '') {
        return 'على نوع المنتج: عند ضبط النوع التجاري المتوقع يجب تعبئة فئة القياس (expected_sizing_category_key) أيضاً.';
    }

    if (!orange_table_exists($pdo, 'size_families')) {
        return null;
    }
    $fs = $pdo->prepare(
        'SELECT commercial_kind_key, sizing_category_key FROM size_families WHERE id = ? LIMIT 1'
    );
    $fs->execute([$sizeFamilyId]);
    $row = $fs->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return 'عائلة المقاسات المختارة غير موجودة.';
    }
    $famCk = trim((string) ($row['commercial_kind_key'] ?? ''));
    $famSk = trim((string) ($row['sizing_category_key'] ?? ''));

    $dicErr = orange_catalog_validate_size_family_dictionary_consistency($pdo, $expCk, $expSk);
    if ($dicErr !== null) {
        return 'توقّع هرَم المقاس على نوع المنتج غير متسق مع القاموس المرجعي: ' . $dicErr;
    }
    if ($famCk === '' || $famSk === '') {
        return 'عائلة المقاسات يجب أن تحمل النوع التجاري وفئة القياس (المستويان 1–2) لمطابقة نطاق نوع المنتج — صفحة عائلات المقاسات.';
    }
    if ($famCk !== $expCk || $famSk !== $expSk) {
        return 'هرَم المقاس للعائلة (النوع التجاري «' . $famCk . '» / فئة «' . $famSk
            . '») لا يطابق النطاق على نوع المنتج («' . $expCk . '» / «' . $expSk . '»). اختر عائلة ضمن نفس فئة القياس أو عدّل الورقة.';
    }

    return null;
}

/**
 * استبدال قيم الصفات المخزَّنة للمنتج (صفات نشطة معرّفة في catalog_attributes فقط).
 *
 * @param list<array<string,mixed>>|mixed $incoming صفوف { catalog_attribute_id, value_raw } أو معادل
 */
function orange_catalog_save_product_attribute_values(PDO $pdo, int $productId, mixed $incoming): void
{
    if ($productId <= 0 || !orange_table_exists($pdo, 'product_attribute_values')) {
        return;
    }
    if (!orange_table_exists($pdo, 'catalog_attributes')) {
        return;
    }

    $pdo->prepare('DELETE FROM product_attribute_values WHERE product_id = ?')->execute([$productId]);

    if (!is_array($incoming) || $incoming === []) {
        return;
    }

    $validIds = [];
    try {
        $st = $pdo->query(
            'SELECT id FROM catalog_attributes WHERE is_active = 1 AND id IS NOT NULL'
        );
        $cols = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach (is_array($cols) ? $cols : [] as $r) {
            $aid = isset($r['id']) ? (int) $r['id'] : 0;
            if ($aid > 0) {
                $validIds[$aid] = true;
            }
        }
    } catch (Throwable $e) {
        return;
    }

    $ins = $pdo->prepare(
        'INSERT INTO product_attribute_values (product_id, catalog_attribute_id, value_raw) VALUES (?,?,?)'
    );
    $maxLen = 767;

    foreach ($incoming as $row) {
        if (!is_array($row)) {
            continue;
        }
        $aid = isset($row['catalog_attribute_id']) ? (int) $row['catalog_attribute_id'] : 0;
        if ($aid <= 0 || !isset($validIds[$aid])) {
            continue;
        }
        $raw = trim((string) ($row['value_raw'] ?? ''));
        if ($raw === '') {
            continue;
        }
        if (function_exists('mb_strlen') && mb_strlen($raw, 'UTF-8') > $maxLen) {
            $raw = mb_substr($raw, 0, $maxLen, 'UTF-8');
        } elseif (strlen($raw) > $maxLen) {
            $raw = substr($raw, 0, $maxLen);
        }
        $ins->execute([$productId, $aid, $raw]);
    }
}

/**
 * في وضع الترحيل الموحّد للواجهة: المنتج مرئٍ فقط إذا ارتبط بسلسلة catalog نشطة ومطابقة لاستعلام الصفحة الرئيسية الموحّد.
 * خارج الوضع الموحّد تُعاد دائماً true لتفويض بوابة المتجر القديمة.
 */
function orange_storefront_product_in_active_unified_chain(PDO $pdo, int $productId): bool
{
    if ($productId <= 0) {
        return false;
    }
    if (!function_exists('orange_catalog_nav_use_unified') || !orange_catalog_nav_use_unified($pdo)) {
        return true;
    }
    if (
        !function_exists('orange_table_exists')
        || !orange_table_exists($pdo, 'product_types')
        || !orange_table_exists($pdo, 'catalog_subcategories')
    ) {
        return true;
    }
    try {
        require_once __DIR__ . '/department_countries.php';
        require_once __DIR__ . '/countries.php';
        $sfCountryId = orange_storefront_current_country_id($pdo);
        $depActiveSql = orange_department_country_active_sql($pdo, 'd', $sfCountryId);
        $st = $pdo->prepare(
            'SELECT 1
             FROM products p
             INNER JOIN product_types pt ON pt.id = p.product_type_id AND pt.is_active = 1
             INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id AND ucs.is_active = 1
             INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
             INNER JOIN catalog_sections ucs2 ON ucs2.id = ucc.catalog_section_id AND ucs2.is_active = 1
             INNER JOIN departments d ON d.id = ucs2.department_id AND (' . $depActiveSql . ')
             WHERE p.id = ? AND p.is_active = 1
             LIMIT 1'
        );
        $st->execute([$productId]);

        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_storefront_product_in_active_unified_chain: ' . $e->getMessage());
        }

        return true;
    }
}

/**
 * معرف `catalog_categories` المرتبط بالمنتج عبر `product_types` → التصنيف الفرعي الموحَّد؛
 * أو 0 إذا تعذّر الاستنتاج.
 */
function orange_catalog_product_catalog_category_id(PDO $pdo, int $productId): int
{
    if ($productId <= 0 || !function_exists('orange_table_exists')) {
        return 0;
    }
    if (!orange_table_exists($pdo, 'products') || !orange_table_exists($pdo, 'product_types') || !orange_table_exists($pdo, 'catalog_subcategories')) {
        return 0;
    }
    try {
        $st = $pdo->prepare(
            'SELECT ucs.catalog_category_id AS cid
             FROM products p
             INNER JOIN product_types pt ON pt.id = p.product_type_id
             INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id
             WHERE p.id = ?
             LIMIT 1'
        );
        $st->execute([$productId]);
        $v = $st->fetchColumn();

        return $v !== false && $v !== null ? (int) $v : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * معرّف `catalog_categories` المستنتج من ورقة `product_types` (عبر التصنيف الفرعي الموحّد).
 * يُستخدم قبل إنشاء صف منتج لضبط نطاق تكرار الاسم العربي في وضع التنقّل الموحّد.
 */
function orange_catalog_catalog_category_id_for_product_type(PDO $pdo, int $productTypeId): int
{
    if ($productTypeId <= 0 || !function_exists('orange_table_exists')) {
        return 0;
    }
    if (!orange_table_exists($pdo, 'product_types') || !orange_table_exists($pdo, 'catalog_subcategories')) {
        return 0;
    }
    try {
        $st = $pdo->prepare(
            'SELECT ucs.catalog_category_id
             FROM product_types pt
             INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id
             WHERE pt.id = ?
             LIMIT 1'
        );
        $st->execute([$productTypeId]);
        $v = $st->fetchColumn();

        return $v !== false && $v !== null ? (int) $v : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * صفوف {id,name} للمنتجات ضمن نفس نطاق تكرار الاسم العربي وفق فئة الكتالوج الموحّدة.
 *
 * @return list<array<string,mixed>>
 */
function orange_catalog_products_rows_for_arabic_name_scope(
    PDO $pdo,
    ?int $resolvedLegacyCategoryId,
    ?int $productTypeId,
    bool $unifiedNav
): array {
    if (!orange_table_exists($pdo, 'products')) {
        return [];
    }
    if ($productTypeId !== null && $productTypeId > 0) {
        $ccid = orange_catalog_catalog_category_id_for_product_type($pdo, $productTypeId);
        if ($ccid > 0) {
            try {
                $st = $pdo->prepare(
                    'SELECT p.id, p.name
                     FROM products p
                     INNER JOIN product_types pt ON pt.id = p.product_type_id
                     INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id
                     WHERE ucs.catalog_category_id = ?'
                );
                $st->execute([$ccid]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);

                return is_array($rows) ? $rows : [];
            } catch (Throwable $e) {
                // fallback أدناه
            }
        } else {
            try {
                $st = $pdo->prepare(
                    'SELECT p.id, p.name FROM products p
                     INNER JOIN product_types pt ON pt.id = p.product_type_id
                     WHERE pt.catalog_subcategory_id = (
                         SELECT catalog_subcategory_id FROM product_types WHERE id = ? LIMIT 1
                     )'
                );
                $st->execute([$productTypeId]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);

                return is_array($rows) ? $rows : [];
            } catch (Throwable $e) {
                // fallback أدناه
            }
        }
    }

    return [];
}

/**
 * عند تنقّل الواجهة الموحَّد: تتطلّب عروض السلة المتغيرة أن تُشارك منتجات داخل سلسلة الكتالوج النشطة
 * كي لا تُحفظ قواعد لا تطبّق على المتجر الفعلي. خارج الوضع الموحَّد لا يُفرض قيد (سلوك المتجر القديم).
 *
 * @param iterable<int> $variantIds
 * @return string|null رسالة خطأ عربية أو null
 */
function orange_admin_validate_variants_storefront_chain(PDO $pdo, iterable $variantIds): ?string
{
    $uniq = [];
    foreach ($variantIds as $vid) {
        $n = (int) $vid;
        if ($n > 0) {
            $uniq[$n] = true;
        }
    }
    $ids = array_keys($uniq);
    if ($ids === []) {
        return null;
    }
    if (!function_exists('orange_table_exists') || !orange_table_exists($pdo, 'product_variants')) {
        return null;
    }

    sort($ids, SORT_NUMERIC);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare('SELECT id, product_id FROM product_variants WHERE id IN (' . $ph . ')');
        $st->execute($ids);
        $map = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $vid = (int) ($row['id'] ?? 0);
            $pid = (int) ($row['product_id'] ?? 0);
            if ($vid > 0) {
                $map[$vid] = $pid;
            }
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_admin_validate_variants_storefront_chain: ' . $e->getMessage());
        }

        return null;
    }

    foreach ($ids as $vid) {
        if (!isset($map[$vid])) {
            return 'المتغير #' . $vid . ' غير موجود.';
        }
        $pid = (int) $map[$vid];
        if ($pid <= 0) {
            return 'المتغير #' . $vid . ' غير مرتبط بمنتج صالح.';
        }
        if (!orange_storefront_product_in_active_unified_chain($pdo, $pid)) {
            return 'المتغير #' . $vid . ' مرتبط بمنتج خارج سلسلة الكتالوج الموحَّد النشطة؛ لن يظهر للعميل في الواجهة عند التنقّل الموحَّد. اختر متغيرات من منتجات في الشجرة الموحّدة.';
        }
    }

    return null;
}

/**
 * لقائمة المنتجات في الواجهة: facet من معلمات attr_{attribute_key} في GET (صفات is_filterable فقط).
 *
 * @param list<mixed> $params
 * @return array{0:string,1:list<mixed>}
 */
function orange_storefront_products_append_attr_filters_sql(PDO $pdo, string $sql, array $params, string $productAlias = 'p'): array
{
    if (!function_exists('orange_table_exists')
        || !orange_table_exists($pdo, 'catalog_attributes')
        || !orange_table_exists($pdo, 'product_attribute_values')) {
        return [$sql, $params];
    }
    $pa = preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $productAlias) ? $productAlias : 'p';

    foreach ($_GET as $gk => $gv) {
        if (! is_string($gk) || ! str_starts_with($gk, 'attr_')) {
            continue;
        }
        $ak = substr($gk, 5);
        if ($ak === '' || ! preg_match('/^[a-zA-Z0-9_]{1,80}$/', $ak)) {
            continue;
        }
        $valRaw = trim((string) $gv);
        if ($valRaw === '') {
            continue;
        }
        if (function_exists('mb_strlen') && mb_strlen($valRaw, 'UTF-8') > 400) {
            continue;
        }
        $sql .= ' AND EXISTS (
            SELECT 1 FROM product_attribute_values __pav
            INNER JOIN catalog_attributes __ca ON __ca.id = __pav.catalog_attribute_id
                AND __ca.is_active = 1 AND __ca.is_filterable = 1 AND __ca.attribute_key = ?
            WHERE __pav.product_id = ' . $pa . '.id AND __pav.value_raw = ?
        )';
        $params[] = $ak;
        $params[] = $valRaw;
    }

    return [$sql, $params];
}

/**
 * @return array<int, array<string, string>> product_id => { attribute_key => value_raw }
 */
function orange_storefront_product_attr_map(PDO $pdo, array $productIds): array
{
    $out = [];
    $ids = array_values(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0));
    if ($ids === [] || !orange_table_exists($pdo, 'product_attribute_values') || !orange_table_exists($pdo, 'catalog_attributes')) {
        return $out;
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare(
            'SELECT pav.product_id, ca.attribute_key, pav.value_raw
             FROM product_attribute_values pav
             INNER JOIN catalog_attributes ca ON ca.id = pav.catalog_attribute_id AND ca.is_active = 1
             WHERE pav.product_id IN (' . $ph . ')'
        );
        $st->execute($ids);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (! is_array($row)) {
                continue;
            }
            $pid = (int) ($row['product_id'] ?? 0);
            $k = trim((string) ($row['attribute_key'] ?? ''));
            $v = trim((string) ($row['value_raw'] ?? ''));
            if ($pid <= 0 || $k === '' || $v === '') {
                continue;
            }
            if (! isset($out[$pid])) {
                $out[$pid] = [];
            }
            $out[$pid][$k] = $v;
        }
    } catch (Throwable $e) {
        return $out;
    }

    return $out;
}

/**
 * @param array<string, string> $attrs
 */
function orange_storefront_attr_data_attribute(array $attrs): string
{
    if ($attrs === []) {
        return '';
    }
    $parts = [];
    foreach ($attrs as $k => $v) {
        $k = trim((string) $k);
        $v = trim((string) $v);
        if ($k === '' || $v === '') {
            continue;
        }
        $parts[] = rawurlencode($k) . ':' . rawurlencode($v);
    }

    return implode(';', $parts);
}

/**
 * @return list<array{attribute_key:string,label:string,values:list<array{value:string,count:int}>}>
 */
function orange_storefront_home_filterable_facets(PDO $pdo, string $lang, int $countryId): array
{
    if (
        !function_exists('orange_catalog_nav_use_unified') || !orange_catalog_nav_use_unified($pdo)
        || !orange_table_exists($pdo, 'catalog_attributes')
        || !orange_table_exists($pdo, 'product_attribute_values')
        || !orange_table_exists($pdo, 'product_types')
    ) {
        return [];
    }
    require_once __DIR__ . '/department_countries.php';
    $depActiveSql = orange_department_country_active_sql($pdo, 'd', $countryId);
    $countrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $countryId);
    $labelCol = match ($lang) {
        'ar' => 'ca.label_ar',
        'fil' => 'ca.label_fil',
        'hi' => 'ca.label_hi',
        default => 'ca.label_en',
    };
    try {
        $sql = '
            SELECT ca.attribute_key AS k, ' . $labelCol . ' AS lbl, ca.label_ar, ca.label_en,
                   pav.value_raw AS v, COUNT(DISTINCT p.id) AS cnt
            FROM catalog_attributes ca
            INNER JOIN product_attribute_values pav ON pav.catalog_attribute_id = ca.id
            INNER JOIN products p ON p.id = pav.product_id AND p.is_active = 1' . $countrySql . '
            INNER JOIN product_types pt ON pt.id = p.product_type_id AND pt.is_active = 1
            INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id AND ucs.is_active = 1
            INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
            INNER JOIN catalog_sections ucs2 ON ucs2.id = ucc.catalog_section_id AND ucs2.is_active = 1
            INNER JOIN departments d ON d.id = ucs2.department_id AND (' . $depActiveSql . ')
            WHERE ca.is_active = 1 AND ca.is_filterable = 1
            GROUP BY ca.id, ca.attribute_key, ca.label_ar, ca.label_en, ca.label_fil, ca.label_hi, pav.value_raw
            ORDER BY ca.sort_order ASC, ca.id ASC, cnt DESC
        ';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byKey = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $k = trim((string) ($r['k'] ?? ''));
            $v = trim((string) ($r['v'] ?? ''));
            if ($k === '' || $v === '') {
                continue;
            }
            if (! isset($byKey[$k])) {
                $lbl = trim((string) ($r['lbl'] ?? ''));
                if ($lbl === '') {
                    $lbl = trim((string) ($r['label_ar'] ?? '')) ?: trim((string) ($r['label_en'] ?? '')) ?: $k;
                }
                $byKey[$k] = ['attribute_key' => $k, 'label' => $lbl, 'values' => []];
            }
            if (count($byKey[$k]['values']) >= 24) {
                continue;
            }
            $byKey[$k]['values'][] = ['value' => $v, 'count' => (int) ($r['cnt'] ?? 0)];
        }

        return array_values($byKey);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_storefront_home_filterable_facets: ' . $e->getMessage());
        }

        return [];
    }
}

function orange_catalog_product_type_default_advisory_guide_id(PDO $pdo, int $productTypeId): int
{
    if ($productTypeId <= 0 || !orange_table_exists($pdo, 'product_types')) {
        return 0;
    }
    if (!orange_table_has_column($pdo, 'product_types', 'default_advisory_sizing_guide_id')) {
        return 0;
    }
    try {
        $st = $pdo->prepare(
            'SELECT default_advisory_sizing_guide_id FROM product_types WHERE id = ? LIMIT 1'
        );
        $st->execute([$productTypeId]);
        $v = $st->fetchColumn();

        return $v !== false && $v !== null ? (int) $v : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @return array<int, int> product_type_id => default_advisory_sizing_guide_id (>0 only)
 */
function orange_catalog_product_type_default_advisory_guide_map(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'product_types')
        || !orange_table_has_column($pdo, 'product_types', 'default_advisory_sizing_guide_id')) {
        return [];
    }
    try {
        $rows = $pdo->query(
            'SELECT id, default_advisory_sizing_guide_id FROM product_types
             WHERE default_advisory_sizing_guide_id IS NOT NULL AND default_advisory_sizing_guide_id > 0'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $ptId = (int) ($r['id'] ?? 0);
            $gid = (int) ($r['default_advisory_sizing_guide_id'] ?? 0);
            if ($ptId > 0 && $gid > 0) {
                $out[$ptId] = $gid;
            }
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return int 0 = valid or not applicable; catalog_categories.id when active in unified chain
 */
function orange_catalog_validate_unified_catalog_category_id(PDO $pdo, int $catalogCategoryId): int
{
    if ($catalogCategoryId <= 0 || !orange_table_exists($pdo, 'catalog_categories')) {
        return 0;
    }
    try {
        $st = $pdo->prepare(
            'SELECT ucc.id FROM catalog_categories ucc
             INNER JOIN catalog_sections cs ON cs.id = ucc.catalog_section_id AND cs.is_active = 1
             INNER JOIN departments d ON d.id = cs.department_id AND d.is_active = 1
             WHERE ucc.id = ? AND ucc.is_active = 1
             LIMIT 1'
        );
        $st->execute([$catalogCategoryId]);

        return $st->fetchColumn() ? $catalogCategoryId : 0;
    } catch (Throwable $e) {
        return 0;
    }
}
