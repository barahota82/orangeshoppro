<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../../includes/catalog_labels.php';
require_once __DIR__ . '/../../../includes/product_variants_write.php';
require_once __DIR__ . '/../../../includes/product_channels.php';
require_once __DIR__ . '/../../../includes/arabic_name_duplicate.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $productId = (int)($data['id'] ?? 0);
    if ($productId <= 0) {
        json_response(['success' => false, 'message' => 'معرف المنتج مطلوب'], 422);
    }

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
    $hasColors = (int)($data['has_colors'] ?? 0) === 1;

    $schemeErr = orange_catalog_validate_size_family_matches_product_type(
        $pdo,
        $productTypeIdResolved !== null && $productTypeIdResolved > 0 ? $productTypeIdResolved : null,
        $hasSizes,
        $sizeFamilyId
    );
    if ($schemeErr !== null) {
        json_response(['success' => false, 'message' => $schemeErr], 422);
    }

    $prevProductTypeDb = null;
    if (
        orange_table_exists($pdo, 'products')
        && orange_table_has_column($pdo, 'products', 'product_type_id')
    ) {
        $curPt = $pdo->prepare('SELECT product_type_id FROM products WHERE id = ? LIMIT 1');
        $curPt->execute([$productId]);
        $crow = $curPt->fetch(PDO::FETCH_ASSOC);
        if (is_array($crow) && isset($crow['product_type_id']) && $crow['product_type_id'] !== null) {
            $prevProductTypeDb = (int) $crow['product_type_id'];
        }
    }

    $ptAssignErr = orange_catalog_validate_product_type_assignment_active(
        $pdo,
        $productTypeIdResolved !== null && $productTypeIdResolved > 0 ? $productTypeIdResolved : null,
        $prevProductTypeDb !== null && $prevProductTypeDb > 0 ? $prevProductTypeDb : null
    );
    if ($ptAssignErr !== null) {
        json_response(['success' => false, 'message' => $ptAssignErr], 422);
    }

    $sortOrder = (int)($data['sort_order'] ?? 0);

    $unifiedNav = function_exists('orange_catalog_nav_use_unified') && orange_catalog_nav_use_unified($pdo);
    $prodRows = orange_catalog_products_rows_for_arabic_name_scope(
        $pdo,
        $resolvedCategoryId,
        $productTypeIdResolved !== null && $productTypeIdResolved > 0 ? $productTypeIdResolved : null,
        $unifiedNav
    );
    if (orange_rows_normalized_arabic_conflict(is_array($prodRows) ? $prodRows : [], 'id', 'name', $nameAr, $productId)) {
        json_response(['success' => false, 'message' => orange_arabic_duplicate_blocked_message()], 409);
    }

    $seoTitleAr = trim((string)($data['seo_meta_title_ar'] ?? ''));
    $seoTitleEn = trim((string)($data['seo_meta_title_en'] ?? ''));
    $seoTitleFil = trim((string)($data['seo_meta_title_fil'] ?? ''));
    $seoTitleHi = trim((string)($data['seo_meta_title_hi'] ?? ''));
    $seoDescAr = trim((string)($data['seo_meta_description_ar'] ?? ''));
    $seoDescEn = trim((string)($data['seo_meta_description_en'] ?? ''));
    $seoDescFil = trim((string)($data['seo_meta_description_fil'] ?? ''));
    $seoDescHi = trim((string)($data['seo_meta_description_hi'] ?? ''));

    $mainImage = trim((string)($data['main_image'] ?? ''));
    $extraImagesIn = $data['extra_images'] ?? null;
    if ($mainImage === '' && is_array($extraImagesIn)) {
        foreach ($extraImagesIn as $raw) {
            $fn = basename((string)$raw);
            $fn = preg_replace('/[^a-zA-Z0-9._-]/', '', $fn);
            if ($fn !== '' && $fn !== '.' && $fn !== '..') {
                $mainImage = $fn;
                break;
            }
        }
    }

    $variantsMaybe = $data['variants'] ?? null;
    if ($variantsMaybe !== null) {
        if (!is_array($variantsMaybe) || count($variantsMaybe) === 0) {
            json_response(['success' => false, 'message' => 'مصفوفة المتغيرات مطلوبة عند تحديث المخزون من نموذج المتغيرات'], 422);
        }
        foreach ($variantsMaybe as $rv) {
            if (!is_array($rv)) {
                json_response(['success' => false, 'message' => 'صف متغير غير صالح'], 422);
            }
            if ($hasColors) {
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
            if ($hasSizes) {
                $z = isset($rv['size_family_size_id']) ? (int) $rv['size_family_size_id'] : 0;
                if ($z <= 0) {
                    json_response(['success' => false, 'message' => 'كل متغير يجب أن يرتبط بمقاس من عائلة المقاسات'], 422);
                }
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
    $itemCodeUp = $normSku($data['item_code'] ?? '');
    if (
        orange_table_has_column($pdo, 'products', 'item_code')
        && $productTypeIdResolved !== null
        && $productTypeIdResolved > 0
    ) {
        $autoItemUp = orange_catalog_generate_product_item_code_from_tree($pdo, $productTypeIdResolved, $productId);
        if ($autoItemUp !== null) {
            $itemCodeUp = $autoItemUp;
        }
    }
    $barcodeUp = $normSku($data['barcode'] ?? '');

    $setParts = [
        'name = ?',
        'name_en = ?',
        'name_fil = ?',
        'name_hi = ?',
        'description = ?',
        'description_en = ?',
        'description_fil = ?',
        'description_hi = ?',
        'seo_meta_title_ar = ?',
        'seo_meta_title_en = ?',
        'seo_meta_title_fil = ?',
        'seo_meta_title_hi = ?',
        'seo_meta_description_ar = ?',
        'seo_meta_description_en = ?',
        'seo_meta_description_fil = ?',
        'seo_meta_description_hi = ?',
    ];
    $execParams = [
        $nameAr,
        $nameEn,
        $nameFil,
        $nameHi,
        trim((string)($data['description'] ?? '')),
        trim((string)($data['description_en'] ?? '')),
        trim((string)($data['description_fil'] ?? '')),
        trim((string)($data['description_hi'] ?? '')),
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
        $setParts[] = 'category_id = ?';
        $execParams[] = $resolvedCategoryId;
    }
    if (orange_table_has_column($pdo, 'products', 'subcategory_id')) {
        $setParts[] = 'subcategory_id = ?';
        $execParams[] = $subcategoryId;
    }
    array_push($setParts,
        'product_type_id = ?',
        'size_family_id = ?',
        'sizing_guide_scope = ?',
        'price = ?',
        'cost = ?',
        'main_image = ?',
        'has_sizes = ?',
        'has_colors = ?',
        'sort_order = ?',
        'item_code = ?',
        'barcode = ?',
        'is_active = ?',
        'updated_at = NOW()'
    );
    array_push($execParams,
        $productTypeIdResolved,
        $sizeFamilyId,
        $scope,
        (float)$data['price'],
        (float)$data['cost'],
        $mainImage,
        $hasSizes ? 1 : 0,
        (int)($data['has_colors'] ?? 0),
        $sortOrder,
        $itemCodeUp,
        $barcodeUp,
        isset($data['is_active']) ? (int)$data['is_active'] : 1,
    );

    $execParams[] = $productId;

    $stmt = $pdo->prepare('
        UPDATE products
        SET ' . implode(', ', $setParts) . '
        WHERE id = ?
    ');

    $stmt->execute($execParams);

    if ($stmt->rowCount() === 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM products WHERE id = ? LIMIT 1");
        $checkStmt->execute([$productId]);
        if (!$checkStmt->fetch()) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'المنتج غير موجود'], 404);
        }
    }

    if (is_array($extraImagesIn)) {
        $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$productId]);
        $imgIns = $pdo->prepare('INSERT INTO product_images (product_id, image_path) VALUES (?, ?)');
        $mainBasename = $mainImage !== '' ? basename($mainImage) : '';
        foreach ($extraImagesIn as $raw) {
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

    if ($variantsMaybe !== null && is_array($variantsMaybe)) {
        orange_product_sync_variants_matrix(
            $pdo,
            $productId,
            $variantsMaybe,
            $hasColors,
            $hasSizes,
            $sizeFamilyId
        );
    }

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

    orange_product_attach_all_active_channels($pdo, $productId);

    audit_log('product_update', 'تم تحديث المنتج رقم: ' . $productId, 'products', $productId);
    json_response([
        'success' => true,
        'message' => 'تم تحديث المنتج',
        'item_code' => $itemCodeUp,
        'barcode' => $barcodeFinal,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر تحديث المنتج');
}
