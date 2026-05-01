<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_taxonomy_migrate.php';

/**
 * يستنتج حقول منتج تصنيفيًا: مصدر حقيقة `product_type_id` عند تفعيل الترحيل الموحّد،
 * مع إبقاء category_id/subcategory_id مشتَّقة للتوافق مع الشاشات القديمة والقوائم.
 *
 * @return array{category_id:int,subcategory_id:?int,product_type_id:?int}|array{error:string}
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

        $st = $pdo->prepare('SELECT id, slug FROM product_types WHERE id = ? LIMIT 1');
        $st->execute([$ptIn]);
        $ptRow = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($ptRow)) {
            return ['error' => 'نوع المنتج المختار غير موجود.'];
        }

        $slug = (string) ($ptRow['slug'] ?? '');

        if (preg_match('/^legacy-ptype-sub-(\d+)$/', $slug, $m)) {
            $legacySub = (int) $m[1];
            $q = $pdo->prepare('SELECT category_id FROM subcategories WHERE id = ? LIMIT 1');
            $q->execute([$legacySub]);
            $crow = $q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($crow)) {
                return ['error' => 'تعارض بين نوع المنتج والشجرة القديمة؛ راجع الترحيل.'];
            }
            $catResolved = (int) ($crow['category_id'] ?? 0);

            return [
                'category_id' => $catResolved > 0 ? $catResolved : $catIn,
                'subcategory_id' => $legacySub > 0 ? $legacySub : null,
                'product_type_id' => $ptIn,
            ];
        }

        if (preg_match('/^legacy-ptype-cat-(\d+)$/u', $slug, $m)) {
            $legacyCat = (int) $m[1];

            return [
                'category_id' => $legacyCat > 0 ? $legacyCat : $catIn,
                'subcategory_id' => null,
                'product_type_id' => $ptIn,
            ];
        }

        if ($catIn <= 0) {
            return ['error' => 'هذا نوع منتج خارج مسار الترحيل الآلي؛ اختر فئة المتجر المعروضة (مشتقة) لتطابق الواجهة الحالية أو أنشئ نمط legacy للورقة.'];
        }

        [$subOk, $subcategoryId, $subErr] = orange_product_resolve_subcategory_id($pdo, $catIn, $rawSub);
        if (!$subOk) {
            return ['error' => $subErr];
        }

        return [
            'category_id' => $catIn,
            'subcategory_id' => $subcategoryId,
            'product_type_id' => $ptIn,
        ];
    }

    if ($catIn <= 0) {
        return ['error' => 'البيانات الأساسية مطلوبة']; // يطابق رسالة كان يُعتمد مع category_id فارغة
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
 * هرم المقاس (مستوى 3): يطابق `size_families.size_scheme_key` مع `product_types.expected_size_scheme_key`
 * عند ضبط المتوقع على الورقة وفتح المقاسات على المنتج.
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
    $fs = $pdo->prepare('SELECT size_scheme_key FROM size_families WHERE id = ? LIMIT 1');
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

    return null;
}
