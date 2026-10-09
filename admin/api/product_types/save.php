<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_sizing_dictionary.php';
require_once __DIR__ . '/../../../includes/arabic_name_duplicate.php';
require_once __DIR__ . '/../../../includes/orange_catalog_name_locale.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_table_exists($pdo, 'product_types') || !orange_table_exists($pdo, 'catalog_subcategories')) {
        json_response(['success' => false, 'message' => 'جداول الشجرة الموحّدة غير جاهزة بعد تهيئة المخطط.'], 503);
    }

    $data = get_json_input();
    $id = (int) ($data['id'] ?? 0);

    $sanitizeSlug = static function (string $raw): string {
        $t = strtolower(trim($raw));
        if ($t !== '' && !preg_match('/^[a-z0-9][a-z0-9_-]{0,190}$/', $t)) {
            return '';
        }

        return $t;
    };

    $sanitizeKind32 = static function (string $raw): string {
        $t = strtolower(trim($raw));
        $t = (string) (preg_replace('/[^a-z0-9_-]/', '', $t) ?? '');

        return strlen($t) > 32 ? substr($t, 0, 32) : $t;
    };

    $sanitizeCat64 = static function (string $raw): string {
        $t = strtolower(trim($raw));
        $t = (string) (preg_replace('/[^a-z0-9_-]/', '', $t) ?? '');

        return strlen($t) > 64 ? substr($t, 0, 64) : $t;
    };

    $subId = (int) ($data['catalog_subcategory_id'] ?? 0);
    $slugRaw = $sanitizeSlug((string) ($data['slug'] ?? ''));
    $countryId = function_exists('orange_admin_context_country_id') ? (int) orange_admin_context_country_id($pdo) : 0;
    $localeMode = orange_catalog_name_locale_use_payload($pdo, $countryId, $data);
    if ($localeMode && trim((string) ($data['base_text'] ?? '')) === '' && !($id > 0 && !empty($data['base_explicit_empty']))) {
        json_response(['success' => false, 'message' => 'اسم اللغة الأساسية مطلوب.'], 422);
    }
    if ($localeMode) {
        $preview = orange_catalog_name_locale_preview($pdo, $countryId, 'product_type', $id, $data);
        $nameAr = $preview['name_ar'];
        $nameEn = $preview['name_en'];
        $nameFil = $preview['name_fil'];
        $nameHi = $preview['name_hi'];
        if ($slugRaw === '') {
            $slugRaw = $sanitizeSlug(orange_catalog_name_locale_kept_slug($pdo, 'product_types', $id, ''));
        }
    } else {
        $nameAr = trim((string) ($data['name_ar'] ?? ''));
        $nameEn = trim((string) ($data['name_en'] ?? ''));
        $nameFil = trim((string) ($data['name_fil'] ?? ''));
        $nameHi = trim((string) ($data['name_hi'] ?? ''));
    }
    $expCk = $sanitizeKind32((string) ($data['expected_commercial_kind_key'] ?? ''));
    $expSk = $sanitizeCat64((string) ($data['expected_sizing_category_key'] ?? ''));
    $defaultAdvGuideId = isset($data['default_advisory_sizing_guide_id']) ? (int) $data['default_advisory_sizing_guide_id'] : 0;
    if ($defaultAdvGuideId <= 0) {
        $defaultAdvGuideId = null;
    } elseif (orange_table_exists($pdo, 'advisory_sizing_guides')) {
        $gst = $pdo->prepare('SELECT id, is_active FROM advisory_sizing_guides WHERE id = ? LIMIT 1');
        $gst->execute([$defaultAdvGuideId]);
        $grow = $gst->fetch(PDO::FETCH_ASSOC);
        if (! is_array($grow) || (int) ($grow['is_active'] ?? 0) !== 1) {
            json_response(['success' => false, 'message' => 'دليل المقاس الاسترشادي الافتراضي غير موجود أو غير نشط.'], 422);
        }
    }
    $sortOrder = (int) ($data['sort_order'] ?? 0);
    $active = (int) ($data['is_active'] ?? 1) === 0 ? 0 : 1;

    if ($subId <= 0) {
        json_response(['success' => false, 'message' => 'يجب اختيار تصنيف فرعي موحّد (الورقة الأم مباشرة تحت الشجرة).'], 422);
    }

    if ($slugRaw === '') {
        json_response(['success' => false, 'message' => 'المعرِّف اللاتيني (slug): حرف أو رقم إنجليزي أولًا، ثم أحرف صغيرة وأرقام وشرطة _ أو -.'], 422);
    }

    $allowExplicitEmpty = $localeMode && $id > 0 && !empty($data['base_explicit_empty']);
    $guard = orange_catalog_name_locale_guard($pdo, $countryId, $localeMode, (string) ($data['base_text'] ?? ''), $nameEn, $nameAr, $nameEn, 'الاسم العربي لنوع المنتج مطلوب.', $allowExplicitEmpty);
    if ($guard !== null && !$localeMode && $nameAr !== '' && $nameEn === '') {
        $guard = 'الاسم الإنجليزي مطلوب.';
    }
    if ($guard !== null) {
        json_response(['success' => false, 'message' => $guard], 422);
    }

    if ($expSk !== '' && $expCk === '') {
        json_response(['success' => false, 'message' => 'عند ضبط فئة قياس متوقعة على نوع المنتج يجب تعبئة النوع التجاري (commercial_kind_key).'], 422);
    }
    if ($expCk !== '' && $expSk === '') {
        json_response(['success' => false, 'message' => 'عند ضبط النوع التجاري المتوقع على نوع المنتج يجب تعبئة فئة القياس (sizing_category_key).'], 422);
    }
    if ($expCk !== '' && $expSk !== '') {
        $hierErr = orange_catalog_validate_size_family_dictionary_consistency($pdo, $expCk, $expSk);
        if ($hierErr !== null) {
            json_response(['success' => false, 'message' => $hierErr], 422);
        }
    }

    if (function_exists('orange_catalog_sizing_dictionary_kinds_enforced') && orange_catalog_sizing_dictionary_kinds_enforced($pdo)) {
        if ($expCk === '' || $expSk === '') {
            json_response([
                'success' => false,
                'message' => 'مع وجود أنواع تجارية نشطة في القاموس المرجعي، يجب اختيار النوع التجاري وفئة القياس المتوقّة على ورقة نوع المنتج (هرَم المقاس 1–2).',
            ], 422);
        }
    }

    $subChk = $pdo->prepare('SELECT id FROM catalog_subcategories WHERE id = ? LIMIT 1');
    $subChk->execute([$subId]);
    if (!$subChk->fetch()) {
        json_response(['success' => false, 'message' => 'التصنيف الفرعي الموحّد غير موجود.'], 404);
    }

    $dupSlug = $pdo->prepare(
        'SELECT id FROM product_types WHERE catalog_subcategory_id = ? AND slug = ? AND id <> ? LIMIT 1'
    );
    $dupSlug->execute([$subId, $slugRaw, max(0, $id)]);
    if ($dupSlug->fetchColumn()) {
        json_response(['success' => false, 'message' => 'هذا المعرِّف (slug) مستخدم لنوع آخر تحت نفس الفرع.'], 409);
    }

    $ptRows = $pdo->prepare('SELECT id, name_ar FROM product_types WHERE catalog_subcategory_id = ?');
    $ptRows->execute([$subId]);
    $siblingRows = $ptRows->fetchAll(PDO::FETCH_ASSOC);
    $excludeId = $id > 0 ? $id : null;
    $typeDup = $localeMode
        ? orange_catalog_name_locale_names_conflict($pdo, 'product_type', 'catalog_subcategory_id', $subId, $id, (string) (orange_country_locale_roles_read($pdo, $countryId)['base'] ?? ''), (string) ($data['base_text'] ?? ''))
        : orange_rows_normalized_arabic_conflict(is_array($siblingRows) ? $siblingRows : [], 'id', 'name_ar', $nameAr, $excludeId);
    if ($typeDup) {
        json_response(['success' => false, 'message' => orange_arabic_duplicate_blocked_message()], 409);
    }

    if ($sortOrder <= 0 && $id <= 0) {
        $nextSt = $pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM product_types WHERE catalog_subcategory_id = ?'
        );
        $nextSt->execute([$subId]);
        $sortOrder = (int) $nextSt->fetchColumn();
        if ($sortOrder <= 0) {
            $sortOrder = 1;
        }
    }
    if ($sortOrder <= 0) {
        $sortOrder = 1;
    }

    $hasDefaultAdvCol = orange_table_has_column($pdo, 'product_types', 'default_advisory_sizing_guide_id');

    if ($id > 0) {
        $exists = $pdo->prepare('SELECT id FROM product_types WHERE id = ? LIMIT 1');
        $exists->execute([$id]);
        if (!$exists->fetch()) {
            json_response(['success' => false, 'message' => 'السجل غير موجود.'], 404);
        }

        $updSql = $localeMode
            ? 'UPDATE product_types SET catalog_subcategory_id = ?, slug = ?,
                expected_size_scheme_key = ?, expected_commercial_kind_key = ?, expected_sizing_category_key = ?'
            : 'UPDATE product_types SET catalog_subcategory_id = ?, slug = ?, name_ar = ?, name_en = ?, name_fil = ?, name_hi = ?,
                expected_size_scheme_key = ?, expected_commercial_kind_key = ?, expected_sizing_category_key = ?';
        $updParams = $localeMode
            ? [$subId, $slugRaw, '', $expCk, $expSk]
            : [$subId, $slugRaw, $nameAr, $nameEn, $nameFil, $nameHi, '', $expCk, $expSk];
        if ($hasDefaultAdvCol) {
            $updSql .= ', default_advisory_sizing_guide_id = ?';
            $updParams[] = $defaultAdvGuideId;
        }
        $updSql .= ', sort_order = ?, is_active = ? WHERE id = ?';
        $updParams[] = $sortOrder;
        $updParams[] = $active;
        $updParams[] = $id;
        if ($localeMode) {
            $pdo->beginTransaction();
        }
        $pdo->prepare($updSql)->execute($updParams);
        if ($localeMode) {
            orange_catalog_name_locale_commit_payload($pdo, $countryId, 'product_type', $id, $data);
            $pdo->commit();
        }
        audit_log('product_type_save', 'تحديث نوع منتج (شجرة موحّدة): ' . $slugRaw, 'product_types', $id);
        json_response([
            'success' => true,
            'id' => $id,
            'catalog_subcategory_id' => $subId,
            'sort_order' => $sortOrder,
        ]);
    }

    $insCols = 'catalog_subcategory_id, slug, name_ar, name_en, name_fil, name_hi,
            expected_size_scheme_key, expected_commercial_kind_key, expected_sizing_category_key';
    $insPh = '?,?,?,?,?,?,?,?,?';
    $insParams = $localeMode
        ? [$subId, $slugRaw, '', '', '', '', '', $expCk, $expSk]
        : [$subId, $slugRaw, $nameAr, $nameEn, $nameFil, $nameHi, '', $expCk, $expSk];
    if ($hasDefaultAdvCol) {
        $insCols .= ', default_advisory_sizing_guide_id';
        $insPh .= ',?';
        $insParams[] = $defaultAdvGuideId;
    }
    $insCols .= ', sort_order, is_active';
    $insPh .= ',?,?';
    $insParams[] = $sortOrder;
    $insParams[] = $active;
    if ($localeMode) {
        $pdo->beginTransaction();
    }
    $pdo->prepare('INSERT INTO product_types (' . $insCols . ') VALUES (' . $insPh . ')')->execute($insParams);
    $newId = (int) $pdo->lastInsertId();
    if ($localeMode) {
        orange_catalog_name_locale_commit_payload($pdo, $countryId, 'product_type', $newId, $data);
        $pdo->commit();
    }
    audit_log('product_type_save', 'إضافة نوع منتج (شجرة موحّدة): ' . $slugRaw, 'product_types', $newId);
    json_response([
        'success' => true,
        'id' => $newId,
        'catalog_subcategory_id' => $subId,
        'sort_order' => $sortOrder,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر حفظ نوع المنتج في الشجرة الموحّدة');
}
