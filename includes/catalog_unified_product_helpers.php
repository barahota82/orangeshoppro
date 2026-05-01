<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_taxonomy_migrate.php';

/**
 * يستنتج حقول تصنيف المنتج: مع تفعيل المتجر الموحّد يكون مصدر الحقيقة **`product_type_id`** فقط؛
 * تُشتقّ `category_id` / `subcategory_id` من الورقة أو تُخزَّن NULL عند غياب جسر الترحيل (ورق جديدة نقية).
 *
 * @return array{category_id:?int,subcategory_id:?int,product_type_id:?int}|array{error:string}
 */
function orange_catalog_resolve_product_classification(PDO $pdo, array $data): array
{
    $unified = function_exists('orange_catalog_nav_use_unified') && orange_catalog_nav_use_unified($pdo);

    $ptIn = isset($data['product_type_id']) ? (int) $data['product_type_id'] : 0;
    $catIn = isset($data['category_id']) ? (int) $data['category_id'] : 0;
    $rawSub = $data['subcategory_id'] ?? null;

    if ($unified) {
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

    if ($catIn <= 0) {
        return ['error' => 'البيانات الأساسية مطلوبة'];
    }

    [$subOk, $subcategoryId, $subErr] = orange_product_resolve_subcategory_id($pdo, $catIn, $rawSub);
    if (!$subOk) {
        return ['error' => $subErr];
    }

    $resolvedPt = null;
    if ($ptIn > 0) {
        $chk = $pdo->prepare('SELECT id FROM product_types WHERE id = ? LIMIT 1');
        $chk->execute([$ptIn]);
        if (!$chk->fetchColumn()) {
            return ['error' => 'نوع المنتج (الشجرة الموحّدة) غير صالح.'];
        }
        $resolvedPt = $ptIn;
    }

    return [
        'category_id' => $catIn,
        'subcategory_id' => $subcategoryId,
        'product_type_id' => $resolvedPt,
    ];
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
        return 'يجب أن يكون نوع المنتج نشطًا عند تعيينه أو تبديل إليه. فعّل الورقة من «أنواع المنتجات (موحّد)» أو اختر نوعًا نشطًا.';
    }

    return null;
}

/**
 * هرم المقاس: يطابق مخطط العائلة مع `product_types.expected_size_scheme_key`؛
 * وعند وجود متوقع يُلزم مستويات 1–2 على العائلة (commercial_kind_key، sizing_category_key).
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
    $st = $pdo->prepare('SELECT expected_size_scheme_key FROM product_types WHERE id = ? LIMIT 1');
    $st->execute([$productTypeId]);
    $pt = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($pt)) {
        return null;
    }
    $expected = trim((string) ($pt['expected_size_scheme_key'] ?? ''));
    if ($expected === '') {
        return null;
    }
    if (!orange_table_exists($pdo, 'size_families')) {
        return null;
    }
    $fs = $pdo->prepare(
        'SELECT size_scheme_key, commercial_kind_key, sizing_category_key FROM size_families WHERE id = ? LIMIT 1'
    );
    $fs->execute([$sizeFamilyId]);
    $row = $fs->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return 'عائلة المقاسات المختارة غير موجودة.';
    }
    $actual = trim((string) ($row['size_scheme_key'] ?? ''));
    if ($actual === '') {
        return 'يجب ضبط size_scheme_key لعائلة المقاسات المختارة لمطابقة نوع المنتج («' . $expected . '»). راجع صفحة عائلات المقاسات.';
    }
    if ($actual !== $expected) {
        return 'مخطط المقاس في العائلة («' . $actual . '») لا يطابق المخطط المتوقع لنوع المنتج («' . $expected . '»). غيّر العائلة أو نوع المنتج أو حدّث المفاتيح في الأدمن.';
    }

    $ck = trim((string) ($row['commercial_kind_key'] ?? ''));
    $sk = trim((string) ($row['sizing_category_key'] ?? ''));
    if ($ck === '' || $sk === '') {
        return 'في وضع مخطط مقاس متوقّع على نوع المنتج يجب ملء النوع التجاري وفئة القياس (commercial_kind_key و sizing_category_key) على عائلة المقاسات لاستكمال هرَم المقاس — صفحة عائلات المقاسات.';
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
 * صفوف {id,name} للمنتجات ضمن نفس نطاق تكرار الاسم العربي: في التنقّل الموحّد حسب فئة الكتالوج الموحّدة،
 * وإلا حسب `category_id` القديم.
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
    if ($unifiedNav && $productTypeId !== null && $productTypeId > 0) {
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
    try {
        if ($resolvedLegacyCategoryId === null || $resolvedLegacyCategoryId <= 0) {
            return [];
        }
        $st = $pdo->prepare('SELECT id, name FROM products WHERE category_id = ?');
        $st->execute([$resolvedLegacyCategoryId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
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
