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

    $previewCountryId = (int) ($data['preview_country_id'] ?? 0);
    if ($previewCountryId <= 0) {
        $previewCountryId = orange_admin_context_country_id($pdo);
    }
    if ($previewCountryId <= 0) {
        json_response(['success' => false, 'message' => 'اختر دولة المعاينة'], 422);
    }

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
            $cwMap = orange_product_ensure_colorways($pdo, $draftId, $variantsIn, $hasColors);
            $variantStmt = $pdo->prepare(
                'INSERT INTO product_variants (product_id, product_colorway_id, size_family_size_id, size, color, stock_quantity)
                 VALUES (?,?,?,?,?,?)'
            );
            $dPriceUnified = ((int) ($data['price_unified'] ?? 1) === 1);
            $dCostUnified = ((int) ($data['cost_unified'] ?? 1) === 1);
            $dProductPrice = (float) ($data['price'] ?? 0);
            $dProductCost = (float) ($data['cost'] ?? 0);
            $dHasVarPriceCost = orange_table_has_column($pdo, 'product_variants', 'price')
                && orange_table_has_column($pdo, 'product_variants', 'cost');
            $dVarPCUpd = $dHasVarPriceCost
                ? $pdo->prepare('UPDATE product_variants SET price = ?, cost = ? WHERE id = ? LIMIT 1')
                : null;
            foreach ($variantsIn as $variant) {
                try {
                    $p = (int) ($variant['primary_color_id'] ?? 0);
                    $s = (int) ($variant['secondary_color_id'] ?? 0);
                    $pp = (int) ($variant['primary_pattern_id'] ?? 0);
                    $sp = (int) ($variant['secondary_pattern_id'] ?? 0);
                    $szId = (int) ($variant['size_family_size_id'] ?? 0);
                    $stock = max(0, (int) ($variant['stock_quantity'] ?? 0));

                    if (! $hasColors) {
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
                        continue;
                    }

                    $sizeFamilySizeId = ($hasSizes && $szId > 0) ? $szId : null;
                    $sizeRow = null;
                    if ($sizeFamilySizeId !== null && $sizeFamilyId !== null) {
                        $szStmt = $pdo->prepare('SELECT * FROM size_family_sizes WHERE id = ? AND size_family_id = ? LIMIT 1');
                        $szStmt->execute([$sizeFamilySizeId, $sizeFamilyId]);
                        $sizeRow = $szStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                        if (! $sizeRow) {
                            $sizeFamilySizeId = null;
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

                    $variantStmt->execute([$draftId, $colorwayId, $sizeFamilySizeId, $sizeLabel, $colorLabel, $stock]);
                    $newDraftVid = (int) $pdo->lastInsertId();
                    if ($dVarPCUpd !== null && $newDraftVid > 0) {
                        $effPrice = $dPriceUnified
                            ? $dProductPrice
                            : ((array_key_exists('price', $variant) && $variant['price'] !== null && $variant['price'] !== '') ? (float) $variant['price'] : $dProductPrice);
                        $effCost = $dCostUnified
                            ? $dProductCost
                            : ((array_key_exists('cost', $variant) && $variant['cost'] !== null && $variant['cost'] !== '') ? (float) $variant['cost'] : $dProductCost);
                        $dVarPCUpd->execute([$effPrice, $effCost, $newDraftVid]);
                    }
                } catch (Throwable $ve) {
                    /* صف متغيّر معطوب لا يكسر المعاينة */
                    continue;
                }
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
    orange_preview_set_session($adminId, $previewCountryId, $draftId, 86400);

    $channelSlug = orange_storefront_main_channel_slug_for_country($pdo, $previewCountryId);
    if ($channelSlug === null || $channelSlug === '') {
        try {
            $anySlug = $pdo->query('SELECT slug FROM channels WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
            $channelSlug = ($anySlug !== false && $anySlug !== null) ? (string) $anySlug : '';
        } catch (Throwable $ce) {
            $channelSlug = '';
        }
    }
    if ($channelSlug === '') {
        json_response(['success' => false, 'message' => 'لا توجد قناة نشطة لدولة المعاينة'], 422);
    }

    $previewUrl = storefront_url('home', (string) $channelSlug, 'ar');
    $productUrl = $draftId > 0
        ? storefront_url('product', (string) $channelSlug, 'ar', ['id' => $draftId])
        : null;

    json_response([
        'success' => true,
        'message' => $draftId > 0 ? 'تم تجهيز المعاينة' : 'فُتحت المعاينة للتصفّح (بلا منتج بعد)',
        'draft_id' => $draftId,
        'browse_only' => $draftId === 0,
        'channel' => $channelSlug,
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
