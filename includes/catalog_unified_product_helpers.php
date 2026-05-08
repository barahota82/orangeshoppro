<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/catalog_sizing_dictionary.php';

/**
 * يستنتج حقول تصنيف المنتج: مع تفعيل المتجر الموحّد يكون مصدر الحقيقة **`product_type_id`** فقط؛
 * تُشتقّ `category_id` / `subcategory_id` من الورقة أو تُخزَّن NULL عند غياب جسر الترحيل (ورق جديدة نقية).
 *
 * @return array{category_id:?int,subcategory_id:?int,product_type_id:?int}|array{error:string}
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

    $cache = orange_catalog_legacy_classification_cache_for_product_type($pdo, $ptIn);

    return [
        'category_id' => $cache['legacy_category_id'],
        'subcategory_id' => $cache['legacy_subcategory_id'],
        'product_type_id' => $ptIn,
    ];
}

/**
 * جزء واحد من كود الصنف: slug لاتيني/أرقام مختصر، أو i{id} عند الفراغ.
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

    $p1 = orange_catalog_item_code_segment_from_slug($row['dep_slug'] ?? null, $depId);
    $p2 = orange_catalog_item_code_segment_from_slug($row['sec_slug'] ?? null, $secId);
    $p3 = orange_catalog_item_code_segment_from_slug($row['cat_slug'] ?? null, $catId);
    $p4 = orange_catalog_item_code_segment_from_slug($row['sub_slug'] ?? null, $subId);
    $p5 = orange_catalog_item_code_segment_from_slug($row['pt_slug'] ?? null, $ptId);
    $base = implode('-', [$p1, $p2, $p3, $p4, $p5]);
    $base = trim(preg_replace('/-+/', '-', $base), '-');
    if ($base === '') {
        $base = 'pt' . $productTypeId;
    }
    $suffix = '-P' . $productId;
    $maxBase = 64 - strlen($suffix);
    if ($maxBase < 4) {
        return 'P' . $productId;
    }
    if (strlen($base) > $maxBase) {
        $base = substr($base, 0, $maxBase);
        $base = rtrim($base, '-');
        if ($base === '') {
            $base = 'pt' . $productTypeId;
        }
    }

    return $base . $suffix;
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
        $st = $pdo->prepare(
            'SELECT 1
             FROM products p
             INNER JOIN product_types pt ON pt.id = p.product_type_id AND pt.is_active = 1
             INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id AND ucs.is_active = 1
             INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
             INNER JOIN catalog_sections ucs2 ON ucs2.id = ucc.catalog_section_id AND ucs2.is_active = 1
             INNER JOIN departments d ON d.id = ucs2.department_id AND d.is_active = 1
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
