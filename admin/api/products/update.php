<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../../includes/catalog_labels.php';
require_once __DIR__ . '/../../../includes/product_variants_write.php';
require_once __DIR__ . '/../../../includes/product_colorway_images.php';
require_once __DIR__ . '/../../../includes/product_channels.php';
require_once __DIR__ . '/../../../includes/arabic_name_duplicate.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/catalog_polish_phase6.php';
require_once __DIR__ . '/../../../includes/orange_product_content_locale.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    // Internal preview-copy metadata must never be accepted from a public save payload.
    if (isset($data['content_locale']) && is_array($data['content_locale'])) {
        foreach ($data['content_locale'] as &$productLocaleBlock) {
            if (!is_array($productLocaleBlock) || !isset($productLocaleBlock['locales']) || !is_array($productLocaleBlock['locales'])) {
                continue;
            }
            foreach ($productLocaleBlock['locales'] as &$productLocaleEntry) {
                if (is_array($productLocaleEntry)) {
                    unset($productLocaleEntry['preserve'], $productLocaleEntry['origin']);
                }
            }
            unset($productLocaleEntry);
        }
        unset($productLocaleBlock);
    }
    $adminCountryId = function_exists('orange_admin_context_country_id') ? (int) orange_admin_context_country_id($pdo) : 0;
    $productLocaleMode = orange_product_content_locale_use($pdo, $adminCountryId, $data);

    $productId = (int)($data['id'] ?? 0);
    if ($productId <= 0) {
        json_response(['success' => false, 'message' => 'معرف المنتج مطلوب'], 422);
    }
    try {
        orange_admin_assert_entity_country($pdo, 'products', $productId);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    $productLocaleColumns = null;
    if ($productLocaleMode) {
        try {
            orange_product_content_locale_assert_pack($pdo, $adminCountryId, $productId, $data['content_locale']);
        } catch (InvalidArgumentException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        }
        $productLocaleColumns = orange_product_content_locale_preview_columns($pdo, $adminCountryId, $productId, $data['content_locale']);
    }

    if (!isset($data['price']) || !isset($data['cost']) || (!$productLocaleMode && empty($data['name']))) {
        json_response(['success' => false, 'message' => 'البيانات الأساسية مطلوبة'], 422);
    }

    $nameEn = $productLocaleMode ? trim((string) ($productLocaleColumns['name_en'] ?? '')) : trim((string)($data['name_en'] ?? ''));
    $nameFil = $productLocaleMode ? trim((string) ($productLocaleColumns['name_fil'] ?? '')) : trim((string)($data['name_fil'] ?? ''));
    $nameHi = $productLocaleMode ? trim((string) ($productLocaleColumns['name_hi'] ?? '')) : trim((string)($data['name_hi'] ?? ''));
    if (!$productLocaleMode && ($nameEn === '' || $nameFil === '' || $nameHi === '')) {
        json_response(['success' => false, 'message' => 'أسماء المنتج بلغات English / Filipino / Hindi مطلوبة'], 422);
    }

    $class = orange_catalog_resolve_product_classification($pdo, $data);
    if (isset($class['error'])) {
        json_response(['success' => false, 'message' => $class['error']], 422);
    }

    $productTypeIdResolved = (int) ($class['product_type_id'] ?? 0);
    if ($productTypeIdResolved <= 0) {
        json_response(['success' => false, 'message' => 'نوع المنتج غير صالح'], 422);
    }

    $nameAr = $productLocaleMode ? trim((string) ($productLocaleColumns['name'] ?? '')) : trim((string)$data['name']);
    $sizeFamilyId = isset($data['size_family_id']) ? (int)$data['size_family_id'] : 0;
    if ($sizeFamilyId <= 0) {
        $sizeFamilyId = null;
    }
    $hasSizes = $sizeFamilyId !== null && $sizeFamilyId > 0;
    $scope = trim((string)($data['sizing_guide_scope'] ?? 'none'));
    $allowedScopes = ['none', 'upper', 'lower', 'both', 'single'];
    if (!in_array($scope, $allowedScopes, true)) {
        $scope = 'none';
    }
    if (!$hasSizes) {
        $scope = 'none';
    }
    $sizingAdvisoryGuideId = null;
    if (orange_table_has_column($pdo, 'products', 'sizing_advisory_guide_id')) {
        $gid = isset($data['sizing_advisory_guide_id']) ? (int) $data['sizing_advisory_guide_id'] : 0;
        if (!$hasSizes) {
            $gid = 0;
        }
        if ($gid > 0) {
            if (!orange_table_exists($pdo, 'advisory_sizing_guides')) {
                json_response(['success' => false, 'message' => 'جداول دليل المقاس غير جاهزة'], 422);
            }
            $gst = $pdo->prepare(
                'SELECT id, size_family_id, scope_kind, is_active FROM advisory_sizing_guides WHERE id = ? LIMIT 1'
            );
            $gst->execute([$gid]);
            $grow = $gst->fetch(PDO::FETCH_ASSOC);
            $famNeed = $sizeFamilyId !== null ? (int) $sizeFamilyId : 0;
            if (!is_array($grow) || (int) ($grow['size_family_id'] ?? 0) !== $famNeed) {
                json_response(['success' => false, 'message' => 'دليل المقاس المختار لا يطابق عائلة المقاسات للمنتج'], 422);
            }
            if ((int) ($grow['is_active'] ?? 0) !== 1) {
                json_response(['success' => false, 'message' => 'دليل المقاس المختار غير نشط'], 422);
            }
            $sizingAdvisoryGuideId = $gid;
            $sk = strtolower(trim((string) ($grow['scope_kind'] ?? '')));
            if (in_array($sk, ['upper', 'lower', 'single'], true)) {
                $scope = $sk;
            } else {
                $scope = 'single';
            }
        } else {
            $sizingAdvisoryGuideId = null;
        }
    }
    if (orange_table_has_column($pdo, 'products', 'sizing_advisory_guide_id') && $sizingAdvisoryGuideId === null) {
        $scope = 'none';
    }
    $hasColors = (int)($data['has_colors'] ?? 0) === 1;
    $priceUnified = ((int) ($data['price_unified'] ?? 1) === 1);
    $costUnified = ((int) ($data['cost_unified'] ?? 1) === 1);

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
        null,
        $productTypeIdResolved,
        $unifiedNav
    );
    if ($productLocaleMode) {
        $roles = orange_country_locale_roles_read($pdo, $adminCountryId);
        $baseName = trim((string) ($data['content_locale']['name']['base_text'] ?? ''));
        if (orange_product_content_locale_name_conflict($pdo, $productTypeIdResolved, $productId, (string) ($roles['base'] ?? ''), $baseName)) {
            json_response(['success' => false, 'message' => orange_arabic_duplicate_blocked_message()], 409);
        }
    } elseif (orange_rows_normalized_arabic_conflict(is_array($prodRows) ? $prodRows : [], 'id', 'name', $nameAr, $productId)) {
        json_response(['success' => false, 'message' => orange_arabic_duplicate_blocked_message()], 409);
    }

    $seoTitleAr = $productLocaleMode ? (string) ($productLocaleColumns['seo_meta_title_ar'] ?? '') : trim((string)($data['seo_meta_title_ar'] ?? ''));
    $seoTitleEn = $productLocaleMode ? (string) ($productLocaleColumns['seo_meta_title_en'] ?? '') : trim((string)($data['seo_meta_title_en'] ?? ''));
    $seoTitleFil = $productLocaleMode ? (string) ($productLocaleColumns['seo_meta_title_fil'] ?? '') : trim((string)($data['seo_meta_title_fil'] ?? ''));
    $seoTitleHi = $productLocaleMode ? (string) ($productLocaleColumns['seo_meta_title_hi'] ?? '') : trim((string)($data['seo_meta_title_hi'] ?? ''));
    $seoDescAr = $productLocaleMode ? (string) ($productLocaleColumns['seo_meta_description_ar'] ?? '') : trim((string)($data['seo_meta_description_ar'] ?? ''));
    $seoDescEn = $productLocaleMode ? (string) ($productLocaleColumns['seo_meta_description_en'] ?? '') : trim((string)($data['seo_meta_description_en'] ?? ''));
    $seoDescFil = $productLocaleMode ? (string) ($productLocaleColumns['seo_meta_description_fil'] ?? '') : trim((string)($data['seo_meta_description_fil'] ?? ''));
    $seoDescHi = $productLocaleMode ? (string) ($productLocaleColumns['seo_meta_description_hi'] ?? '') : trim((string)($data['seo_meta_description_hi'] ?? ''));

    $descAr = $productLocaleMode ? (string) ($productLocaleColumns['description'] ?? '') : trim((string)($data['description'] ?? ''));
    $descEn = $productLocaleMode ? (string) ($productLocaleColumns['description_en'] ?? '') : trim((string)($data['description_en'] ?? ''));
    $descFil = $productLocaleMode ? (string) ($productLocaleColumns['description_fil'] ?? '') : trim((string)($data['description_fil'] ?? ''));
    $descHi = $productLocaleMode ? (string) ($productLocaleColumns['description_hi'] ?? '') : trim((string)($data['description_hi'] ?? ''));

    $seoResolved = $productLocaleMode ? null : orange_product_seo_apply_defaults_for_save(
        $seoTitleAr,
        $seoTitleEn,
        $seoTitleFil,
        $seoTitleHi,
        $seoDescAr,
        $seoDescEn,
        $seoDescFil,
        $seoDescHi,
        $nameAr,
        $nameEn,
        $nameFil,
        $nameHi,
        $descAr,
        $descEn,
        $descFil,
        $descHi
    );
    if (is_array($seoResolved)) {
        $seoTitleAr = $seoResolved['seo_meta_title_ar'];
        $seoTitleEn = $seoResolved['seo_meta_title_en'];
        $seoTitleFil = $seoResolved['seo_meta_title_fil'];
        $seoTitleHi = $seoResolved['seo_meta_title_hi'];
        $seoDescAr = $seoResolved['seo_meta_description_ar'];
        $seoDescEn = $seoResolved['seo_meta_description_en'];
        $seoDescFil = $seoResolved['seo_meta_description_fil'];
        $seoDescHi = $seoResolved['seo_meta_description_hi'];
    }

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
    $priorLocaleColumns = null;
    if ($productLocaleMode) {
        $priorLoad = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $priorLoad->execute([$productId]);
        $priorRow = $priorLoad->fetch(PDO::FETCH_ASSOC);
        $priorLocaleColumns = is_array($priorRow)
            ? orange_product_content_locale_column_snapshot($priorRow)
            : orange_product_content_locale_blank_columns();
    }

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
    array_push($setParts,
        'product_type_id = ?',
        'size_family_id = ?',
        'sizing_guide_scope = ?',
    );
    array_push($execParams, $productTypeIdResolved, $sizeFamilyId, $scope);
    if (orange_table_has_column($pdo, 'products', 'sizing_advisory_guide_id')) {
        $setParts[] = 'sizing_advisory_guide_id = ?';
        $execParams[] = $sizingAdvisoryGuideId;
    }
    array_push($setParts,
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
    if (orange_table_has_column($pdo, 'products', 'price_unified')) {
        $setParts[] = 'price_unified = ?';
        $execParams[] = $priceUnified ? 1 : 0;
    }
    if (orange_table_has_column($pdo, 'products', 'cost_unified')) {
        $setParts[] = 'cost_unified = ?';
        $execParams[] = $costUnified ? 1 : 0;
    }

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
        // احسب سعر/تكلفة كل متغيّر حسب أعلام التوحيد قبل المزامنة (موحّد = قيمة الأب؛ متغيّر = قيمة الصف أو الأب احتياطاً).
        $productPrice = (float) $data['price'];
        $productCost = (float) $data['cost'];
        foreach ($variantsMaybe as &$vRow) {
            if (!is_array($vRow)) {
                continue;
            }
            $vRow['price'] = $priceUnified
                ? $productPrice
                : ((array_key_exists('price', $vRow) && $vRow['price'] !== null && $vRow['price'] !== '') ? (float) $vRow['price'] : $productPrice);
            $vRow['cost'] = $costUnified
                ? $productCost
                : ((array_key_exists('cost', $vRow) && $vRow['cost'] !== null && $vRow['cost'] !== '') ? (float) $vRow['cost'] : $productCost);
        }
        unset($vRow);
        orange_product_sync_variants_matrix(
            $pdo,
            $productId,
            $variantsMaybe,
            $hasColors,
            $hasSizes,
            $sizeFamilyId
        );
    }

    orange_product_sync_colorway_images_from_payload($pdo, $productId, $data['colorway_images'] ?? null, $hasColors);

    if (array_key_exists('catalog_attribute_values', $data)) {
        orange_catalog_save_product_attribute_values($pdo, $productId, $data['catalog_attribute_values']);
    }

    try {
        orange_catalog_refresh_variant_item_codes($pdo, $productId);
    } catch (Throwable $e) {
        // كود المتغيّر تجميلي/تفصيلي — لا يفشل التحديث بسببه
    }

    $barcodeFinal = null;
    try {
        $bcRes = orange_catalog_refresh_product_barcodes($pdo, $productId);
        $barcodeFinal = $bcRes['product_barcode'] ?? null;
    } catch (Throwable $e) {
        $barcodeFinal = null;
    }

    if ($productLocaleMode) {
            orange_product_content_locale_save(
                $pdo,
                $adminCountryId,
                $productId,
                $data['content_locale'],
                false,
                is_array($priorLocaleColumns) ? $priorLocaleColumns : orange_product_content_locale_blank_columns(),
                true
            );
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
