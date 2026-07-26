<?php

declare(strict_types=1);

/**
 * معاينة المنتج قبل النشر — فتح جلسة معاينة موقعية + (اختياري) حفظ «صفّ ظِلّ/مسودّة» متساهل.
 * المرجع: docs/archive/ORANGE_PRODUCT_PREPUBLISH_PREVIEW_ROLLOUT.txt
 *
 * النموذج (محدّث): الجلسة عبر $_SESSION['orange_product_preview'] (admin/country/draft/exp).
 *   - تُفتح المعاينة دائماً للتصفّح كعميل حتى دون إدخال منتج (draft_id=0).
 *   - يُنشأ صفّ المسودّة (الكارت الأخضر) فقط عند توفّر اسم + نوع منتج.
 *   - يتحمّل بيانات ناقصة (جوهر المعاينة) فلا يطبّق تحقّقات create/update الصارمة.
 *   - الصفّ دائماً is_active=0، is_preview_draft=1 (لا يظهر للعميل في أي قائمة؛ يُحقَن منفصلاً للأدمن).
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../../includes/product_channels.php';
require_once __DIR__ . '/../../../includes/catalog_labels.php';
require_once __DIR__ . '/../../../includes/product_variants_write.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/product_colorway_images.php';
require_once __DIR__ . '/../../../includes/product_preview.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $data = get_json_input();
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    if ($adminId <= 0) {
        json_response(['success' => false, 'message' => 'جلسة الأدمن غير صالحة'], 401);
    }

    $sourceId = (int) ($data['preview_source_product_id'] ?? $data['product_id'] ?? 0);
    if ($sourceId < 0) {
        $sourceId = 0;
    }

    /*
     * سلطة دولة المعاينة (قرار المالك 2026-07-27):
     * - منتج موجود → products.country_id ويجب أن يطابق سياق الأدمن.
     * - منتج جديد → Current Admin Country Context.
     * - لا ثقة بـ preview_country_id من العميل.
     */
    $adminCountryId = orange_admin_context_country_id($pdo);
    if ($adminCountryId <= 0) {
        json_response(['success' => false, 'code' => 'admin_country_required', 'message' => 'سياق دولة الأدمن غير صالح'], 422);
    }

    $previewCountryId = 0;
    if ($sourceId > 0) {
        if (!orange_table_has_column($pdo, 'products', 'country_id')) {
            json_response(['success' => false, 'message' => 'عمود دولة المنتج غير جاهز'], 422);
        }
        $stSrc = $pdo->prepare(
            'SELECT id, country_id, is_preview_draft FROM products WHERE id = ? LIMIT 1'
        );
        $stSrc->execute([$sourceId]);
        $srcRow = $stSrc->fetch(PDO::FETCH_ASSOC);
        if (!is_array($srcRow)) {
            json_response(['success' => false, 'message' => 'المنتج غير موجود'], 404);
        }
        if ((int) ($srcRow['is_preview_draft'] ?? 0) === 1) {
            json_response(['success' => false, 'message' => 'لا يمكن معاينة صف ظل كمنتج مصدر'], 422);
        }
        $productCountryId = (int) ($srcRow['country_id'] ?? 0);
        if ($productCountryId <= 0) {
            json_response(['success' => false, 'code' => 'product_country_missing', 'message' => 'المنتج بلا دولة — لا معاينة'], 422);
        }
        if ($productCountryId !== $adminCountryId) {
            json_response([
                'success' => false,
                'code' => 'preview_country_mismatch',
                'message' => 'لا يمكن معاينة منتج من دولة أخرى داخل السياق الحالي',
            ], 403);
        }
        $previewCountryId = $productCountryId;
    } else {
        $previewCountryId = $adminCountryId;
    }

    /* تجاهل أي دولة مرسلة من العميل — السلطة من الخادم فقط. */
    unset($data['preview_country_id']);

    $requestedChannelId = (int) ($data['preview_channel_id'] ?? $data['channel_id'] ?? 0);
    $countryChannels = orange_preview_channels_for_country($pdo, $previewCountryId);
    if ($countryChannels === []) {
        json_response([
            'success' => false,
            'code' => 'no_channel_for_country',
            'message' => 'لا توجد قناة لهذه الدولة. أنشئ قناة من شاشة قنوات العملاء أولًا.',
        ], 422);
    }

    $mainChannelId = 0;
    foreach ($countryChannels as $chRow) {
        if ((int) ($chRow['is_country_default'] ?? 0) === 1) {
            $mainChannelId = (int) $chRow['id'];
            break;
        }
    }

    $resolvedChannelId = 0;
    if (count($countryChannels) === 1) {
        $resolvedChannelId = (int) $countryChannels[0]['id'];
    } elseif ($requestedChannelId > 0) {
        $resolvedChannelId = $requestedChannelId;
    } elseif ($mainChannelId > 0) {
        $resolvedChannelId = $mainChannelId;
    } else {
        json_response([
            'success' => false,
            'code' => 'preview_channel_required',
            'message' => 'اختر قناة المعاينة صراحةً — لا توجد قناة رئيسية لهذه الدولة',
        ], 422);
    }

    $resolvedChannel = orange_preview_resolve_channel_for_country($pdo, $previewCountryId, $resolvedChannelId);
    if ($resolvedChannel === null) {
        json_response([
            'success' => false,
            'code' => 'preview_channel_invalid',
            'message' => 'قناة المعاينة غير صالحة أو لا تتبع دولة المنتج',
        ], 422);
    }
    $channelSlug = (string) $resolvedChannel['slug'];
    $resolvedChannelId = (int) $resolvedChannel['id'];

    /* نوع المنتج لم يَعُد شرطاً لفتح المعاينة — فقط لإظهار كارت المسودّة الأخضر. */
    $productTypeId = (int) ($data['product_type_id'] ?? 0);
    if ($productTypeId <= 0) {
        $class = orange_catalog_resolve_product_classification($pdo, $data);
        if (is_array($class) && (int) ($class['product_type_id'] ?? 0) > 0) {
            $productTypeId = (int) $class['product_type_id'];
        }
    }

    $nameAr = trim((string) ($data['name'] ?? ''));
    $hasColumns = orange_table_has_column($pdo, 'products', 'is_preview_draft');

    /* يُنشأ الكارت الأخضر فقط عند توفّر اسم + نوع منتج + جاهزية الأعمدة. */
    $canCreateDraft = $hasColumns && $nameAr !== '' && $productTypeId > 0;

    /*
     * إعادة استخدام صفّ ظِلّ واحد لكل أدمن (يبقى نفس الـid عبر فتحات المعاينة، فلا يتصاعد العدّاد).
     * نُبقي الأقدم للاستخدام ونحذف أي نسخ زائدة. عند التصفّح بلا منتج نحذف الصفّ كلياً (لا كارت أخضر).
     */
    $reuseDraftId = 0;
    if ($hasColumns) {
        try {
            $oldStmt = $pdo->prepare('SELECT id FROM products WHERE is_preview_draft = 1 AND preview_admin_id = ? ORDER BY id ASC');
            $oldStmt->execute([$adminId]);
            $existingDrafts = array_map('intval', $oldStmt->fetchAll(PDO::FETCH_COLUMN));
            if ($existingDrafts !== []) {
                $reuseDraftId = (int) array_shift($existingDrafts);
                foreach ($existingDrafts as $extraId) {
                    orange_preview_delete_draft_row($pdo, (int) $extraId);
                }
            }
        } catch (Throwable $ce) {
            $reuseDraftId = 0;
        }
    }

    $draftId = 0;
    $token = orange_preview_generate_token();
    $expiresAt = date('Y-m-d H:i:s', time() + 86400);

    if ($canCreateDraft) {
        $nameEn = trim((string) ($data['name_en'] ?? ''));
        $nameFil = trim((string) ($data['name_fil'] ?? ''));
        $nameHi = trim((string) ($data['name_hi'] ?? ''));

        $hasColors = (int) ($data['has_colors'] ?? 0) === 1;
        $sizeFamilyId = (int) ($data['size_family_id'] ?? 0);
        if ($sizeFamilyId <= 0) {
            $sizeFamilyId = null;
        }
        $hasSizes = ((int) ($data['has_sizes'] ?? 0) === 1) || ($sizeFamilyId !== null);

        $scope = trim((string) ($data['sizing_guide_scope'] ?? 'none'));
        if (! in_array($scope, ['none', 'upper', 'lower', 'both', 'single'], true)) {
            $scope = 'none';
        }
        $advisoryGuideId = (int) ($data['sizing_advisory_guide_id'] ?? 0);
        if ($advisoryGuideId <= 0 || ! $hasSizes) {
            $advisoryGuideId = null;
        }

        $normSku = static function ($raw): ?string {
            $s = trim((string) $raw);
            if ($s === '') {
                return null;
            }

            return function_exists('mb_substr') ? mb_substr($s, 0, 64, 'UTF-8') : substr($s, 0, 64);
        };

        $mainImage = trim((string) ($data['main_image'] ?? ''));
        $extraImages = $data['extra_images'] ?? null;
        if ($mainImage === '' && is_array($extraImages)) {
            foreach ($extraImages as $raw) {
                $fn = preg_replace('/[^a-zA-Z0-9._-]/', '', basename((string) $raw));
                if ($fn !== '' && $fn !== '.' && $fn !== '..') {
                    $mainImage = $fn;
                    break;
                }
            }
        }

        $pdo->beginTransaction();

        $cols = ['name', 'name_en', 'name_fil', 'name_hi', 'product_type_id', 'price', 'cost', 'main_image', 'has_sizes', 'has_colors', 'sort_order'];
        $vals = [$nameAr, $nameEn, $nameFil, $nameHi, $productTypeId, (float) ($data['price'] ?? 0), (float) ($data['cost'] ?? 0), $mainImage, $hasSizes ? 1 : 0, $hasColors ? 1 : 0, 0];

        $optional = [
            'description' => trim((string) ($data['description'] ?? '')),
            'description_en' => trim((string) ($data['description_en'] ?? '')),
            'description_fil' => trim((string) ($data['description_fil'] ?? '')),
            'description_hi' => trim((string) ($data['description_hi'] ?? '')),
            'seo_meta_title_ar' => trim((string) ($data['seo_meta_title_ar'] ?? '')),
            'seo_meta_title_en' => trim((string) ($data['seo_meta_title_en'] ?? '')),
            'seo_meta_title_fil' => trim((string) ($data['seo_meta_title_fil'] ?? '')),
            'seo_meta_title_hi' => trim((string) ($data['seo_meta_title_hi'] ?? '')),
            'seo_meta_description_ar' => trim((string) ($data['seo_meta_description_ar'] ?? '')),
            'seo_meta_description_en' => trim((string) ($data['seo_meta_description_en'] ?? '')),
            'seo_meta_description_fil' => trim((string) ($data['seo_meta_description_fil'] ?? '')),
            'seo_meta_description_hi' => trim((string) ($data['seo_meta_description_hi'] ?? '')),
            'size_family_id' => $sizeFamilyId,
            'sizing_guide_scope' => $scope,
            'sizing_advisory_guide_id' => $advisoryGuideId,
            'item_code' => $normSku($data['item_code'] ?? ''),
            'barcode' => $normSku($data['barcode'] ?? ''),
            'country_id' => $previewCountryId,
            'price_unified' => ((int) ($data['price_unified'] ?? 1) === 1) ? 1 : 0,
            'cost_unified' => ((int) ($data['cost_unified'] ?? 1) === 1) ? 1 : 0,
        ];
        foreach ($optional as $col => $val) {
            if (orange_table_has_column($pdo, 'products', $col)) {
                $cols[] = $col;
                $vals[] = $val;
            }
        }

        $cols[] = 'is_active';
        $vals[] = 0;
        $cols[] = 'is_preview_draft';
        $vals[] = 1;
        $cols[] = 'preview_admin_id';
        $vals[] = $adminId;
        $cols[] = 'preview_source_product_id';
        $vals[] = $sourceId > 0 ? $sourceId : null;
        $cols[] = 'preview_token';
        $vals[] = $token;
        $cols[] = 'preview_expires_at';
        $vals[] = $expiresAt;

        if ($reuseDraftId > 0) {
            /* إعادة استخدام نفس الصفّ (id ثابت) — نُفرّغ أبناءه ثم نُحدّث حقوله. */
            orange_preview_clear_draft_children($pdo, $reuseDraftId);
            $setSql = implode(', ', array_map(static fn ($c) => $c . ' = ?', $cols));
            $updSql = 'UPDATE products SET ' . $setSql . ' WHERE id = ? AND is_preview_draft = 1';
            $pdo->prepare($updSql)->execute(array_merge($vals, [$reuseDraftId]));
            $draftId = $reuseDraftId;
        } else {
            $placeholders = implode(', ', array_fill(0, count($vals), '?')) . ', NOW()';
            $insSql = 'INSERT INTO products (' . implode(', ', $cols) . ', created_at) VALUES (' . $placeholders . ')';
            $pdo->prepare($insSql)->execute($vals);
            $draftId = (int) $pdo->lastInsertId();
        }

        /* المتغيّرات — كمية المخزون المُدخلة تُحفظ كما هي (قرار المالك: المعاينة تُظهر الكمية المُدخلة). */
        $variantsIn = $data['variants'] ?? null;
        if (is_array($variantsIn) && count($variantsIn) > 0) {
            try {
                // منع الجذر: منتج بلا أبعاد ⇒ متغيّر واحد فقط (يطابق سلوك الحفظ النهائي).
                if (!$hasColors && !$hasSizes && count($variantsIn) > 1) {
                    $variantsIn = [reset($variantsIn)];
                }

                // مقاس غير صالح ⇒ بلا مقاس (متساهل — لا يكسر المعاينة).
                foreach ($variantsIn as &$previewVariantRow) {
                    if (!is_array($previewVariantRow)) {
                        continue;
                    }
                    $previewSzId = (int) ($previewVariantRow['size_family_size_id'] ?? 0);
                    if ($hasSizes && $previewSzId > 0 && $sizeFamilyId !== null) {
                        $previewSzStmt = $pdo->prepare(
                            'SELECT id FROM size_family_sizes WHERE id = ? AND size_family_id = ? LIMIT 1'
                        );
                        $previewSzStmt->execute([$previewSzId, $sizeFamilyId]);
                        if (!$previewSzStmt->fetchColumn()) {
                            unset($previewVariantRow['size_family_size_id']);
                        }
                    }
                }
                unset($previewVariantRow);

                // صف واحد لكل هوية مصفوفة — آخر صف في الحمولة يفوز عند التكرار.
                $dedupedPreviewVariants = [];
                $previewStockByFp = [];
                foreach ($variantsIn as $previewVariantRow) {
                    if (!is_array($previewVariantRow)) {
                        continue;
                    }
                    $previewCwKey = orange_product_variant_cw_row_key($previewVariantRow, $hasColors);
                    $previewSzRaw = isset($previewVariantRow['size_family_size_id']) ? (int) $previewVariantRow['size_family_size_id'] : 0;
                    $previewMatrixFp = ($hasColors ? $previewCwKey : '-') . '|' . ($hasSizes && $previewSzRaw > 0 ? (string) $previewSzRaw : '0');
                    $dedupedPreviewVariants[(string) $previewMatrixFp] = $previewVariantRow;
                    $previewStockByFp[(string) $previewMatrixFp] = max(0, (int) ($previewVariantRow['stock_quantity'] ?? 0));
                }
                $variantsIn = array_values($dedupedPreviewVariants);

                $dPriceUnified = ((int) ($data['price_unified'] ?? 1) === 1);
                $dCostUnified = ((int) ($data['cost_unified'] ?? 1) === 1);
                $dProductPrice = (float) ($data['price'] ?? 0);
                $dProductCost = (float) ($data['cost'] ?? 0);
                foreach ($variantsIn as &$previewVariantRow) {
                    if (!is_array($previewVariantRow)) {
                        continue;
                    }
                    $previewVariantRow['price'] = $dPriceUnified
                        ? $dProductPrice
                        : ((array_key_exists('price', $previewVariantRow) && $previewVariantRow['price'] !== null && $previewVariantRow['price'] !== '')
                            ? (float) $previewVariantRow['price'] : $dProductPrice);
                    $previewVariantRow['cost'] = $dCostUnified
                        ? $dProductCost
                        : ((array_key_exists('cost', $previewVariantRow) && $previewVariantRow['cost'] !== null && $previewVariantRow['cost'] !== '')
                            ? (float) $previewVariantRow['cost'] : $dProductCost);
                }
                unset($previewVariantRow);

                orange_product_sync_variants_matrix(
                    $pdo,
                    $draftId,
                    $variantsIn,
                    $hasColors,
                    $hasSizes,
                    $sizeFamilyId
                );

                // matrix sync يُدخل stock_quantity=0 — نُعيد كميات المعاينة المُدخلة على product_variants.
                if ($previewStockByFp !== []) {
                    $previewVarLst = $pdo->prepare(
                        'SELECT v.id, v.size_family_size_id,
                                cw.primary_color_id, cw.secondary_color_id, cw.primary_pattern_id, cw.secondary_pattern_id
                         FROM product_variants v
                         LEFT JOIN product_colorways cw ON cw.id = v.product_colorway_id
                         WHERE v.product_id = ?'
                    );
                    $previewVarLst->execute([$draftId]);
                    $previewStockUpd = $pdo->prepare(
                        'UPDATE product_variants SET stock_quantity = ? WHERE id = ? LIMIT 1'
                    );
                    while ($previewVarRow = $previewVarLst->fetch(PDO::FETCH_ASSOC)) {
                        if (!is_array($previewVarRow)) {
                            continue;
                        }
                        $previewCwKeyDb = orange_product_db_row_colorway_key($previewVarRow, $hasColors);
                        $previewSid = isset($previewVarRow['size_family_size_id']) && $previewVarRow['size_family_size_id'] !== null
                            ? (int) $previewVarRow['size_family_size_id'] : 0;
                        $previewFpDb = $previewCwKeyDb . '|' . $previewSid;
                        if (array_key_exists($previewFpDb, $previewStockByFp)) {
                            $previewStockUpd->execute([$previewStockByFp[$previewFpDb], (int) $previewVarRow['id']]);
                        }
                    }
                }
            } catch (Throwable $ve) {
                /* صف متغيّر معطوب لا يكسر المعاينة */
            }
        }

        /* الصور */
        if (is_array($extraImages) && orange_table_exists($pdo, 'product_images')) {
            $imgIns = $pdo->prepare('INSERT INTO product_images (product_id, image_path) VALUES (?, ?)');
            $mainBasename = $mainImage !== '' ? basename($mainImage) : '';
            foreach ($extraImages as $raw) {
                $fn = preg_replace('/[^a-zA-Z0-9._-]/', '', basename((string) $raw));
                if ($fn === '' || $fn === '.' || $fn === '..' || ($mainBasename !== '' && $fn === $mainBasename)) {
                    continue;
                }
                $imgIns->execute([$draftId, $fn]);
            }
        }

        if (function_exists('orange_product_sync_colorway_images_from_payload')) {
            orange_product_sync_colorway_images_from_payload($pdo, $draftId, $data['colorway_images'] ?? null, $hasColors);
        }
        if (function_exists('orange_product_attach_all_active_channels')) {
            orange_product_attach_all_active_channels($pdo, $draftId);
        }
        if (array_key_exists('catalog_attribute_values', $data) && function_exists('orange_catalog_save_product_attribute_values')) {
            try {
                orange_catalog_save_product_attribute_values($pdo, $draftId, $data['catalog_attribute_values']);
            } catch (Throwable $ae) {
                /* صفات ناقصة لا تكسر المعاينة */
            }
        }

        $pdo->commit();
    } elseif ($reuseDraftId > 0) {
        /* تصفّح بلا منتج: احذف صفّ الظِلّ القابل لإعادة الاستخدام كي لا يبقى كارت أخضر قديم. */
        orange_preview_delete_draft_row($pdo, $reuseDraftId);
    }

    /* فتح جلسة المعاينة (تتصفّح الموقع كعميل؛ draft_id=0 = بلا منتج). */
    orange_preview_set_session($adminId, $previewCountryId, $draftId, 86400, $resolvedChannelId);

    $previewUrl = storefront_url('home', $channelSlug, 'ar');
    $productUrl = $draftId > 0
        ? storefront_url('product', $channelSlug, 'ar', ['id' => $draftId])
        : null;

    json_response([
        'success' => true,
        'message' => $draftId > 0 ? 'تم تجهيز المعاينة' : 'فُتحت المعاينة للتصفّح (بلا منتج بعد)',
        'draft_id' => $draftId,
        'browse_only' => $draftId === 0,
        'channel' => $channelSlug,
        'channel_id' => $resolvedChannelId,
        'country_id' => $previewCountryId,
        'preview_url' => $previewUrl,
        'product_url' => $productUrl,
        'expires_at' => $expiresAt,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر تجهيز المعاينة');
}
