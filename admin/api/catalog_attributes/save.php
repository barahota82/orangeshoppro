<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_attribute_helpers.php';
require_once __DIR__ . '/../../../includes/orange_dictionary_locale.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_table_exists($pdo, 'catalog_attributes')) {
        json_response(['success' => false, 'message' => 'جدول catalog_attributes غير متاح بعد تهيئة المخطط.'], 503);
    }

    $data = get_json_input();
    if (isset($data['locales']) && is_array($data['locales'])) {
        $data['locales'] = orange_dictionary_locale_strip_public($data['locales']);
    }
    $id = (int) ($data['id'] ?? 0);
    $countryId = function_exists('orange_admin_context_country_id') ? (int) orange_admin_context_country_id($pdo) : 0;
    $localeMode = orange_dictionary_locale_use_payload($pdo, $countryId, $data);

    if ($localeMode) {
        $preview = orange_dictionary_locale_preview($pdo, $countryId, 'catalog_attribute', $id, $data);
        $labelAr = $preview['label_ar'];
        $labelEn = $preview['label_en'];
        $labelFil = $preview['label_fil'];
        $labelHi = $preview['label_hi'];
        $guard = orange_dictionary_locale_guard($pdo, $countryId, true, (string) ($data['base_text'] ?? ''), $labelEn, $labelAr, $labelEn, 'عنوان السمة بالعربي مطلوب.');
        if ($guard !== null) {
            json_response(['success' => false, 'message' => $guard], 422);
        }
    } else {
        $labelAr = trim((string) ($data['label_ar'] ?? ''));
        $labelEn = trim((string) ($data['label_en'] ?? ''));
        $labelFil = trim((string) ($data['label_fil'] ?? ''));
        $labelHi = trim((string) ($data['label_hi'] ?? ''));
        if ($labelAr === '') {
            json_response(['success' => false, 'message' => 'عنوان السمة بالعربي مطلوب.'], 422);
        }
    }

    if ($id > 0) {
        $prevKey = $pdo->prepare('SELECT attribute_key FROM catalog_attributes WHERE id = ? LIMIT 1');
        $prevKey->execute([$id]);
        $keyRaw = trim((string) $prevKey->fetchColumn());
        if ($keyRaw === '') {
            json_response(['success' => false, 'message' => 'السجل غير موجود.'], 404);
        }
    } else {
        $keyRaw = orange_catalog_attribute_key_allocate_unique($pdo, $labelEn, $labelAr, 0);
    }

    if ($keyRaw === '' || !preg_match('/^[a-z][a-z0-9_-]{1,79}$/', $keyRaw)) {
        json_response([
            'success' => false,
            'message' => 'تعذر توليد attribute_key صالح. جرّب تعبئة English أو غيّر عنوان العربي.',
        ], 422);
    }

    $inputKind = trim((string) ($data['input_kind'] ?? 'text_short'));
    $allowedKinds = ['text_short', 'text_long', 'enum_single', 'multi', 'boolean'];
    if (!in_array($inputKind, $allowedKinds, true)) {
        json_response(['success' => false, 'message' => 'نوع الحقل غير مسموح.'], 422);
    }

    $sortOrder = (int) ($data['sort_order'] ?? 0);
    $filterable = (int) ($data['is_filterable'] ?? 0) === 1 ? 1 : 0;
    $active = (int) ($data['is_active'] ?? 1) === 0 ? 0 : 1;

    if ($id > 0) {
        if ($sortOrder <= 0) {
            $prevSort = $pdo->prepare('SELECT sort_order FROM catalog_attributes WHERE id = ? LIMIT 1');
            $prevSort->execute([$id]);
            $sortOrder = (int) $prevSort->fetchColumn();
        }
    } elseif ($sortOrder <= 0) {
        $next = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM catalog_attributes')->fetchColumn();
        $sortOrder = $next >= 1 ? $next : 1;
    }
    if ($sortOrder <= 0) {
        $sortOrder = 1;
    }

    $dupStmt = $pdo->prepare('SELECT id FROM catalog_attributes WHERE attribute_key = ? AND id <> ? LIMIT 1');
    $dupStmt->execute([$keyRaw, max(0, $id)]);
    if ($dupStmt->fetchColumn()) {
        json_response(['success' => false, 'message' => 'المفتاح الإنجليزي مستخدم لسمة أخرى.'], 409);
    }

    $requiresOptions = ($inputKind === 'enum_single' || $inputKind === 'multi');
    $localeOptions = [];
    $optsOut = [];
    if ($localeMode) {
        $seen = [];
        foreach (is_array($data['options'] ?? null) ? $data['options'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $optionLocales = is_array($row['locales'] ?? null) ? orange_dictionary_locale_strip_public($row['locales']) : [];
            $optionData = ['base_text' => (string) ($row['base_text'] ?? ''), 'locales' => $optionLocales];
            $optionPreview = orange_dictionary_locale_preview($pdo, $countryId, 'catalog_attribute_option', (int) ($row['id'] ?? 0), $optionData);
            $optionBase = trim((string) ($row['base_text'] ?? ''));
            if ($optionBase === '') {
                continue;
            }
            $optionGuard = orange_dictionary_locale_guard($pdo, $countryId, true, $optionBase, $optionPreview['label_en'], $optionPreview['label_ar'], $optionPreview['label_en'], 'قيمة الخيار تحتاج نص اللغة الأساسية والنص الإنجليزي المرجعي.');
            if ($optionGuard !== null) {
                json_response(['success' => false, 'message' => $optionGuard], 422);
            }
            $nk = function_exists('mb_strtolower') ? mb_strtolower($optionPreview['label_ar'], 'UTF-8') : strtolower($optionPreview['label_ar']);
            if ($optionPreview['label_ar'] !== '' && isset($seen[$nk])) {
                json_response(['success' => false, 'message' => 'قيمة عربية مكررة في قائمة الخيارات لنفس السمة.'], 422);
            }
            if ($optionPreview['label_ar'] !== '') {
                $seen[$nk] = true;
            }
            $localeOptions[] = [
                'id' => (int) ($row['id'] ?? 0),
                'base_text' => $optionBase,
                'locales' => $optionLocales,
                'label_ar' => $optionPreview['label_ar'],
                'label_en' => $optionPreview['label_en'],
            ];
        }
        if ($requiresOptions && $localeOptions === []) {
            json_response(['success' => false, 'message' => 'نوع الحقل «قائمة واحدة» أو «متعدّد القيم» يتطلب إضافة قيمة معرّفة واحدة على الأقل (عربي) قبل الحفظ.'], 422);
        }
        if (!$requiresOptions) {
            $localeOptions = [];
        }
    } else {
        if (array_key_exists('options', $data) && is_array($data['options'])) {
            foreach ($data['options'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $lar = trim((string) ($row['label_ar'] ?? ''));
                if ($lar === '') {
                    continue;
                }
                $optsOut[] = [
                    'label_ar' => $lar,
                    'label_en' => trim((string) ($row['label_en'] ?? '')),
                    'label_fil' => trim((string) ($row['label_fil'] ?? '')),
                    'label_hi' => trim((string) ($row['label_hi'] ?? '')),
                ];
            }
        }
        if ($requiresOptions && $optsOut === []) {
            json_response(['success' => false, 'message' => 'نوع الحقل «قائمة واحدة» أو «متعدّد القيم» يتطلب إضافة قيمة معرّفة واحدة على الأقل (عربي) قبل الحفظ.'], 422);
        }
        if (!$requiresOptions) {
            $optsOut = [];
        }
    }

    $keptIds = [];
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            if ($localeMode) {
                $pdo->prepare(
                    'UPDATE catalog_attributes SET attribute_key = ?, input_kind = ?, is_filterable = ?, sort_order = ?, is_active = ? WHERE id = ? LIMIT 1'
                )->execute([$keyRaw, $inputKind, $filterable, $sortOrder, $active, $id]);
            } else {
                $pdo->prepare(
                    'UPDATE catalog_attributes SET attribute_key = ?, label_ar = ?, label_en = ?, label_fil = ?, label_hi = ?,
                        input_kind = ?, is_filterable = ?, sort_order = ?, is_active = ? WHERE id = ? LIMIT 1'
                )->execute([$keyRaw, $labelAr, $labelEn, $labelFil, $labelHi, $inputKind, $filterable, $sortOrder, $active, $id]);
            }
            $savedId = $id;
        } else {
            $pdo->prepare(
                'INSERT INTO catalog_attributes (
                    attribute_key, label_ar, label_en, label_fil, label_hi,
                    input_kind, is_filterable, sort_order, is_active
                ) VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $keyRaw,
                $localeMode ? '' : $labelAr,
                $localeMode ? '' : $labelEn,
                $localeMode ? '' : $labelFil,
                $localeMode ? '' : $labelHi,
                $inputKind,
                $filterable,
                $sortOrder,
                $active,
            ]);
            $savedId = (int) $pdo->lastInsertId();
        }

        if ($localeMode) {
            $before = $pdo->prepare('SELECT id FROM catalog_attribute_options WHERE catalog_attribute_id = ?');
            $before->execute([$savedId]);
            $beforeIds = array_map('intval', $before->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $keptIds = orange_catalog_attribute_options_sync_stable($pdo, $savedId, $localeOptions);
            foreach (array_diff($beforeIds, $keptIds) as $droppedId) {
                orange_dictionary_locale_delete_entity($pdo, 'catalog_attribute_option', (int) $droppedId);
            }
            orange_dictionary_locale_save($pdo, $countryId, 'catalog_attribute', $savedId, trim((string) ($data['base_text'] ?? '')), is_array($data['locales'] ?? null) ? $data['locales'] : [], false);
            foreach ($localeOptions as $index => $option) {
                $optionId = (int) ($keptIds[$index] ?? 0);
                if ($optionId <= 0) {
                    throw new RuntimeException('option_id_missing');
                }
                orange_dictionary_locale_save($pdo, $countryId, 'catalog_attribute_option', $optionId, $option['base_text'], $option['locales'], false);
            }
        } else {
            orange_catalog_attribute_options_replace($pdo, $savedId, $optsOut);
        }
        audit_log('catalog_attribute_save', ($id > 0 ? 'تحديث' : 'إضافة') . ' سمة كتالوج: ' . $keyRaw, 'catalog_attributes', $savedId);
        $pdo->commit();
        json_response(['success' => true, 'id' => $savedId, 'option_ids' => $localeMode ? $keptIds : []]);
    } catch (\InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ سمة الكتالوج');
}
