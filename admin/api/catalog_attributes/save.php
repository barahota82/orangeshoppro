<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
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

    $keyRaw = trim((string) ($data['attribute_key'] ?? ''));
    $labelAr = trim((string) ($data['label_ar'] ?? ''));
    $labelEn = trim((string) ($data['label_en'] ?? ''));
    $labelFil = trim((string) ($data['label_fil'] ?? ''));
    $labelHi = trim((string) ($data['label_hi'] ?? ''));

    if ($labelAr === '') {
        json_response(['success' => false, 'message' => 'عنوان السمة بالعربي مطلوب.'], 422);
    }

    if ($keyRaw === '' || !preg_match('/^[a-z][a-z0-9_-]{1,79}$/', $keyRaw)) {
        json_response([
            'success' => false,
            'message' => 'المفتاح (attribute_key): حرف إنجليزي صغير أولاً، ثم حروف صغيرة/أرقام/ شرطة سفلية أو وسط؛ الطول حتى 80.',
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

    if ($sortOrder <= 0 && $id <= 0) {
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

    if ($id > 0) {
        $chk = $pdo->prepare('SELECT id FROM catalog_attributes WHERE id = ? LIMIT 1');
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            json_response(['success' => false, 'message' => 'السجل غير موجود.'], 404);
        }

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
        audit_log('catalog_attribute_save', 'تحديث سمة كتالوج: ' . $keyRaw, 'catalog_attributes', $id);
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
    audit_log('catalog_attribute_save', 'إضافة سمة كتالوج: ' . $keyRaw, 'catalog_attributes', $newId);
    json_response(['success' => true, 'id' => $newId]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ سمة الكتالوج');
}
