<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_attribute_helpers.php';
require_admin_api();

/**
 * ترحيل الموحَّد — تعريف سمات كتالوج (مفاتيح إنجليزية ثابتة + عناوين عرض).
 */
try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_table_exists($pdo, 'catalog_attributes')) {
        json_response(['success' => false, 'message' => 'جدول catalog_attributes غير متاح بعد تهيئة المخطط.'], 503);
    }

    $data = get_json_input();
    $id = (int) ($data['id'] ?? 0);

    $labelAr = trim((string) ($data['label_ar'] ?? ''));
    $labelEn = trim((string) ($data['label_en'] ?? ''));
    $labelFil = trim((string) ($data['label_fil'] ?? ''));
    $labelHi = trim((string) ($data['label_hi'] ?? ''));

    if ($labelAr === '') {
        json_response(['success' => false, 'message' => 'عنوان السمة بالعربي مطلوب.'], 422);
    }

    /* attribute_key دائماً من الخادم: إنشاء = توليد؛ تحديث = المفتاح المحفوظ (لا يُقبل من العميل). */
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

    $dupStmt = $pdo->prepare(
        'SELECT id FROM catalog_attributes WHERE attribute_key = ? AND id <> ? LIMIT 1'
    );
    $dupStmt->execute([$keyRaw, max(0, $id)]);
    if ($dupStmt->fetchColumn()) {
        json_response(['success' => false, 'message' => 'المفتاح الإنجليزي مستخدم لسمة أخرى.'], 409);
    }

    $requiresOptions = ($inputKind === 'enum_single' || $inputKind === 'multi');
    $optsOut = [];
    if (array_key_exists('options', $data) && is_array($data['options'])) {
        foreach ($data['options'] as $row) {
            if (! is_array($row)) {
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
        json_response([
            'success' => false,
            'message' => 'نوع الحقل «قائمة واحدة» أو «متعدّد القيم» يتطلب إضافة قيمة معرّفة واحدة على الأقل (عربي) قبل الحفظ.',
        ], 422);
    }
    if (! $requiresOptions) {
        $optsOut = [];
    }

    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $pdo->prepare(
                'UPDATE catalog_attributes SET attribute_key = ?, label_ar = ?, label_en = ?, label_fil = ?, label_hi = ?,
                    input_kind = ?, is_filterable = ?, sort_order = ?, is_active = ? WHERE id = ? LIMIT 1'
            )->execute([
                $keyRaw,
                $labelAr,
                $labelEn,
                $labelFil,
                $labelHi,
                $inputKind,
                $filterable,
                $sortOrder,
                $active,
                $id,
            ]);
            orange_catalog_attribute_options_replace($pdo, $id, $optsOut);
            audit_log('catalog_attribute_save', 'تحديث سمة كتالوج: ' . $keyRaw, 'catalog_attributes', $id);
            $pdo->commit();
            json_response(['success' => true, 'id' => $id]);
        }

        $pdo->prepare(
            'INSERT INTO catalog_attributes (
                attribute_key, label_ar, label_en, label_fil, label_hi,
                input_kind, is_filterable, sort_order, is_active
            ) VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $keyRaw,
            $labelAr,
            $labelEn,
            $labelFil,
            $labelHi,
            $inputKind,
            $filterable,
            $sortOrder,
            $active,
        ]);
        $newId = (int) $pdo->lastInsertId();
        orange_catalog_attribute_options_replace($pdo, $newId, $optsOut);
        audit_log('catalog_attribute_save', 'إضافة سمة كتالوج: ' . $keyRaw, 'catalog_attributes', $newId);
        $pdo->commit();
        json_response(['success' => true, 'id' => $newId]);
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
