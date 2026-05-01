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
