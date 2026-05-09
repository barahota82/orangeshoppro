<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../../includes/product_channels.php';
require_once __DIR__ . '/../../../includes/catalog_labels.php';
require_once __DIR__ . '/../../../includes/product_variants_write.php';
require_once __DIR__ . '/../../../includes/product_colorway_images.php';
require_once __DIR__ . '/../../../includes/arabic_name_duplicate.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    if (empty($data['name']) || !isset($data['price']) || !isset($data['cost'])) {
        json_response(['success' => false, 'message' => 'البيانات الأساسية مطلوبة'], 422);
    }

    $nameEn = trim((string)($data['name_en'] ?? ''));
    $nameFil = trim((string)($data['name_fil'] ?? ''));
    $nameHi = trim((string)($data['name_hi'] ?? ''));
    if ($nameEn === '' || $nameFil === '' || $nameHi === '') {
        json_response(['success' => false, 'message' => 'أسماء المنتج بلغات English / Filipino / Hindi مطلوبة'], 422);
    }

    $class = orange_catalog_resolve_product_classification($pdo, $data);
    if (isset($class['error'])) {
        json_response(['success' => false, 'message' => $class['error']], 422);
    }

    $resolvedCategoryId = $class['category_id'] ?? null;
    if ($resolvedCategoryId !== null && (int) $resolvedCategoryId <= 0) {
        $resolvedCategoryId = null;
    } elseif ($resolvedCategoryId !== null) {
        $resolvedCategoryId = (int) $resolvedCategoryId;
    }
    $subcategoryId = $class['subcategory_id'] ?? null;
    if ($subcategoryId !== null && (int) $subcategoryId <= 0) {
        $subcategoryId = null;
    } elseif ($subcategoryId !== null) {
        $subcategoryId = (int) $subcategoryId;
    }
    $productTypeIdResolved = isset($class['product_type_id']) ? $class['product_type_id'] : null;
    if ($productTypeIdResolved !== null) {
        $productTypeIdResolved = (int) $productTypeIdResolved;
    }

    $nameAr = trim((string)$data['name']);
    $unifiedNav = function_exists('orange_catalog_nav_use_unified') && orange_catalog_nav_use_unified($pdo);
    $prodRows = orange_catalog_products_rows_for_arabic_name_scope(
        $pdo,
        $resolvedCategoryId,
        $productTypeIdResolved !== null && $productTypeIdResolved > 0 ? $productTypeIdResolved : null,
        $unifiedNav
    );
    if (orange_rows_normalized_arabic_conflict(is_array($prodRows) ? $prodRows : [], 'id', 'name', $nameAr, null)) {
        json_response(['success' => false, 'message' => orange_arabic_duplicate_blocked_message()], 409);
    }

    $hasColors = (int)($data['has_colors'] ?? 0) === 1;

    $sizeFamilyId = isset($data['size_family_id']) ? (int)$data['size_family_id'] : 0;
    if ($sizeFamilyId <= 0) {
        $sizeFamilyId = null;
    }
    $hasSizes = $sizeFamilyId !== null && $sizeFamilyId > 0;

    $scope = trim((string)($data['sizing_guide_scope'] ?? 'none'));
    $allowedScopes = ['none', 'upper', 'lower', 'both'];
    if (!in_array($scope, $allowedScopes, true)) {
        $scope = 'none';
    }
    if (!$hasSizes) {
        $scope = 'none';
    }

    $schemeErr = orange_catalog_validate_size_family_matches_product_type(
        $pdo,
        $productTypeIdResolved !== null && $productTypeIdResolved > 0 ? $productTypeIdResolved : null,
        $hasSizes,
        $sizeFamilyId
    );
    if ($schemeErr !== null) {
        json_response(['success' => false, 'message' => $schemeErr], 422);
    }

    $ptAssignErr = orange_catalog_validate_product_type_assignment_active(
        $pdo,
        $productTypeIdResolved !== null && $productTypeIdResolved > 0 ? $productTypeIdResolved : null,
        null
    );
    if ($ptAssignErr !== null) {
        json_response(['success' => false, 'message' => $ptAssignErr], 422);
    }

    $variantsIn = $data['variants'] ?? null;
    if (!is_array($variantsIn) || count($variantsIn) === 0) {
        json_response(['success' => false, 'message' => 'يجب توليد صفوف المتغيرات'], 422);
    }

    if ($hasColors) {
        foreach ($variantsIn as $rv) {
            $rp = isset($rv['primary_color_id']) ? (int) $rv['primary_color_id'] : 0;
            if ($rp <= 0) {
                json_response(['success' => false, 'message' => 'كل متغير ملون يجب أن يحدد لوناً أساسياً من القاموس'], 422);
            }
            $ppPat = isset($rv['primary_pattern_id']) ? (int) $rv['primary_pattern_id'] : 0;
            $spPat = isset($rv['secondary_pattern_id']) ? (int) $rv['secondary_pattern_id'] : 0;
            if ($ppPat > 0 && ! orange_pattern_dictionary_id_is_active_posting($pdo, $ppPat)) {
                json_response(['success' => false, 'message' => 'نمط أساسي غير صالح أو غير نشط — راجع قاموس أنماط الألوان'], 422);
            }
            if ($spPat > 0 && ! orange_pattern_dictionary_id_is_active_posting($pdo, $spPat)) {
                json_response(['success' => false, 'message' => 'نمط ثانوي غير صالح أو غير نشط — راجع قاموس أنماط الألوان'], 422);
            }
        }
    }

    if ($hasSizes) {
        foreach ($variantsIn as $rv) {
            $z = isset($rv['size_family_size_id']) ? (int)$rv['size_family_size_id'] : 0;
            if ($z <= 0) {
                json_response(['success' => false, 'message' => 'كل متغير يجب أن يرتبط بمقاس من عائلة المقاسات'], 422);
            }
        }
    }

    $pdo->beginTransaction();

    $normSku = static function ($raw): ?string {
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }

        return function_exists('mb_substr') ? mb_substr($s, 0, 64, 'UTF-8') : substr($s, 0, 64);
    };
    $itemCodeIns = $normSku($data['item_code'] ?? '');
    $barcodeIns = $normSku($data['barcode'] ?? '');

    $nextSort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM products')->fetchColumn();
    if ($nextSort < 1) {
        $nextSort = 1;
    }

    $descAr = trim((string)($data['description'] ?? ''));
    $descEn = trim((string)($data['description_en'] ?? ''));
    $descFil = trim((string)($data['description_fil'] ?? ''));
    $descHi = trim((string)($data['description_hi'] ?? ''));

    $seoTitleAr = trim((string)($data['seo_meta_title_ar'] ?? ''));
    $seoTitleEn = trim((string)($data['seo_meta_title_en'] ?? ''));
    $seoTitleFil = trim((string)($data['seo_meta_title_fil'] ?? ''));
    $seoTitleHi = trim((string)($data['seo_meta_title_hi'] ?? ''));
    $seoDescAr = trim((string)($data['seo_meta_description_ar'] ?? ''));
    $seoDescEn = trim((string)($data['seo_meta_description_en'] ?? ''));
    $seoDescFil = trim((string)($data['seo_meta_description_fil'] ?? ''));
    $seoDescHi = trim((string)($data['seo_meta_description_hi'] ?? ''));

    $mainImage = trim((string)($data['main_image'] ?? ''));
    $extraImagesForMain = $data['extra_images'] ?? null;
    if ($mainImage === '' && is_array($extraImagesForMain)) {
        foreach ($extraImagesForMain as $raw) {
            $fn = basename((string)$raw);
            $fn = preg_replace('/[^a-zA-Z0-9._-]/', '', $fn);
            if ($fn !== '' && $fn !== '.' && $fn !== '..') {
                $mainImage = $fn;
                break;
            }
        }
    }

    $columnNames = [
        'name', 'name_en', 'name_fil', 'name_hi',
        'description', 'description_en', 'description_fil', 'description_hi',
        'seo_meta_title_ar', 'seo_meta_title_en', 'seo_meta_title_fil', 'seo_meta_title_hi',
        'seo_meta_description_ar', 'seo_meta_description_en', 'seo_meta_description_fil', 'seo_meta_description_hi',
    ];
    $execParams = [
        $nameAr,
        $nameEn,
        $nameFil,
        $nameHi,
        $descAr,
        $descEn,
        $descFil,
        $descHi,
        $seoTitleAr,
        $seoTitleEn,
        $seoTitleFil,
        $seoTitleHi,
        $seoDescAr,
        $seoDescEn,
        $seoDescFil,
        $seoDescHi,
    ];
    if (orange_table_has_column($pdo, 'products', 'category_id')) {
        $columnNames[] = 'category_id';
        $execParams[] = $resolvedCategoryId;
    }
    if (orange_table_has_column($pdo, 'products', 'subcategory_id')) {
        $columnNames[] = 'subcategory_id';
        $execParams[] = $subcategoryId;
    }
    $columnNames[] = 'product_type_id';
    $columnNames[] = 'size_family_id';
    $columnNames[] = 'sizing_guide_scope';
    $columnNames[] = 'price';
    $columnNames[] = 'cost';
    $columnNames[] = 'main_image';
    $columnNames[] = 'has_sizes';
    $columnNames[] = 'has_colors';
    $columnNames[] = 'sort_order';
    $columnNames[] = 'item_code';
    $columnNames[] = 'barcode';

    $execParams[] = $productTypeIdResolved;
    $execParams[] = $sizeFamilyId;
    $execParams[] = $scope;
    $execParams[] = (float) $data['price'];
    $execParams[] = (float) $data['cost'];
    $execParams[] = $mainImage;
    $execParams[] = $hasSizes ? 1 : 0;
    $execParams[] = $hasColors ? 1 : 0;
    $execParams[] = $nextSort;
    $execParams[] = $itemCodeIns;
    $execParams[] = $barcodeIns;

    $columnNamesWithMeta = array_merge($columnNames, ['is_active', 'created_at']);
    $placeholdersBody = implode(', ', array_fill(0, count($execParams), '?')) . ', 1, NOW()';
    $stmt = $pdo->prepare(
        'INSERT INTO products (' . implode(', ', $columnNamesWithMeta) . ') VALUES (' . $placeholdersBody . ')'
    );

    $stmt->execute($execParams);

    $productId = (int)$pdo->lastInsertId();

    $itemCodeFinal = $itemCodeIns;
    if (
        orange_table_has_column($pdo, 'products', 'item_code')
        && $productTypeIdResolved !== null
        && $productTypeIdResolved > 0
    ) {
        $autoItem = orange_catalog_generate_product_item_code_from_tree($pdo, $productTypeIdResolved, $productId);
        if ($autoItem !== null) {
            $pdo->prepare('UPDATE products SET item_code = ? WHERE id = ?')->execute([$autoItem, $productId]);
            $itemCodeFinal = $autoItem;
        }
    }

    $cwMap = orange_product_ensure_colorways($pdo, $productId, $variantsIn, $hasColors);

    $variantStmt = $pdo->prepare(
        'INSERT INTO product_variants (
            product_id, product_colorway_id, size_family_size_id, size, color, stock_quantity
        ) VALUES (?,?,?,?,?,?)'
    );

    foreach ($variantsIn as $variant) {
        $p = isset($variant['primary_color_id']) ? (int) $variant['primary_color_id'] : 0;
        $s = isset($variant['secondary_color_id']) ? (int) $variant['secondary_color_id'] : 0;
        $pp = isset($variant['primary_pattern_id']) ? (int) $variant['primary_pattern_id'] : 0;
        $sp = isset($variant['secondary_pattern_id']) ? (int) $variant['secondary_pattern_id'] : 0;
        $szId = isset($variant['size_family_size_id']) ? (int) $variant['size_family_size_id'] : 0;
        /* الكمية تُضبط من شاشة المخزون أو استلام المشتريات — لا من نموذج المنتج */
        $stock = 0;

        if (!$hasColors) {
            $cwKey = '-';
        } else {
            $p = $p > 0 ? $p : null;
            $s = $s > 0 ? $s : null;
            $pp = $pp > 0 ? $pp : null;
            $sp = $sp > 0 ? $sp : null;
            $cwKey = ($p ?? 0) . ':' . ($s ?? 0) . ':' . ($pp ?? 0) . ':' . ($sp ?? 0);
        }

        $colorwayId = $cwMap[$cwKey] ?? null;
        if ($colorwayId === null) {
            throw new RuntimeException('Missing colorway mapping');
        }

        $sizeFamilySizeId = $hasSizes && $szId > 0 ? $szId : null;

        $sizeRow = null;
        if ($sizeFamilySizeId !== null) {
            $szStmt = $pdo->prepare(
                'SELECT * FROM size_family_sizes WHERE id = ? AND size_family_id = ? LIMIT 1'
            );
            $szStmt->execute([$sizeFamilySizeId, $sizeFamilyId]);
            $sizeRow = $szStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$sizeRow) {
                throw new RuntimeException('Invalid size for selected family');
            }
        }

        $colorLabel = orange_colorway_display_label(
            $pdo,
            $hasColors ? $p : null,
            $hasColors ? $s : null,
            $hasColors ? $pp : null,
            $hasColors ? $sp : null,
            'ar'
        );
        $sizeLabel = orange_size_display_label($sizeRow);

        $variantStmt->execute([
            $productId,
            $colorwayId,
            $sizeFamilySizeId,
            $sizeLabel,
            $colorLabel,
            $stock,
        ]);
    }

    $extraImages = $data['extra_images'] ?? null;
    if (is_array($extraImages)) {
        $imgIns = $pdo->prepare('INSERT INTO product_images (product_id, image_path) VALUES (?, ?)');
        $mainBasename = $mainImage !== '' ? basename($mainImage) : '';
        foreach ($extraImages as $raw) {
            $fn = basename((string)$raw);
            $fn = preg_replace('/[^a-zA-Z0-9._-]/', '', $fn);
            if ($fn === '' || $fn === '.' || $fn === '..') {
                continue;
            }
            if ($mainBasename !== '' && $fn === $mainBasename) {
                continue;
            }
            $imgIns->execute([$productId, $fn]);
        }
    }

    orange_product_sync_colorway_images_from_payload($pdo, $productId, $data['colorway_images'] ?? null, $hasColors);

    orange_product_attach_all_active_channels($pdo, $productId);

    if (array_key_exists('catalog_attribute_values', $data)) {
        orange_catalog_save_product_attribute_values($pdo, $productId, $data['catalog_attribute_values']);
    }

    $barcodeFinal = null;
    try {
        $bcRes = orange_catalog_refresh_product_barcodes($pdo, $productId);
        $barcodeFinal = $bcRes['product_barcode'] ?? null;
    } catch (Throwable $e) {
        $barcodeFinal = null;
    }

    $pdo->commit();

    json_response([
        'success' => true,
        'message' => 'تم حفظ المنتج بنجاح',
        'product_id' => $productId,
        'item_code' => $itemCodeFinal,
        'barcode' => $barcodeFinal,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر حفظ المنتج');
}
