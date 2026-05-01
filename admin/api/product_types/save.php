<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/arabic_name_duplicate.php';
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

    $sanitizeScheme = static function (string $raw): string {
        $t = strtolower(trim($raw));
        $t = (string) (preg_replace('/[^a-z0-9_-]/', '', $t) ?? '');
        if (strlen($t) > 64) {
            $t = substr($t, 0, 64);
        }

        return $t;
    };

    $subId = (int) ($data['catalog_subcategory_id'] ?? 0);
    $slugRaw = $sanitizeSlug((string) ($data['slug'] ?? ''));
    $nameAr = trim((string) ($data['name_ar'] ?? ''));
    $nameEn = trim((string) ($data['name_en'] ?? ''));
    $nameFil = trim((string) ($data['name_fil'] ?? ''));
    $nameHi = trim((string) ($data['name_hi'] ?? ''));
    $scheme = $sanitizeScheme((string) ($data['expected_size_scheme_key'] ?? ''));
    $sortOrder = (int) ($data['sort_order'] ?? 0);
    $active = (int) ($data['is_active'] ?? 1) === 0 ? 0 : 1;

    if ($subId <= 0) {
        json_response(['success' => false, 'message' => 'يجب اختيار تصنيف فرعي موحّد (الورقة الأم مباشرة تحت الشجرة).'], 422);
    }

    if ($slugRaw === '') {
        json_response(['success' => false, 'message' => 'المعرِّف اللاتيني (slug): حرف أو رقم إنجليزي أولًا، ثم أحرف صغيرة وأرقام وشرطة _ أو -.'], 422);
    }

    if ($nameAr === '') {
        json_response(['success' => false, 'message' => 'الاسم العربي لنوع المنتج مطلوب.'], 422);
    }

    if ($nameEn === '') {
        json_response(['success' => false, 'message' => 'الاسم الإنجليزي مطلوب.'], 422);
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
    if (orange_rows_normalized_arabic_conflict(is_array($siblingRows) ? $siblingRows : [], 'id', 'name_ar', $nameAr, $excludeId)) {
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

    if ($id > 0) {
        $exists = $pdo->prepare('SELECT id FROM product_types WHERE id = ? LIMIT 1');
        $exists->execute([$id]);
        if (!$exists->fetch()) {
            json_response(['success' => false, 'message' => 'السجل غير موجود.'], 404);
        }

        $pdo->prepare(
            'UPDATE product_types SET catalog_subcategory_id = ?, slug = ?, name_ar = ?, name_en = ?, name_fil = ?, name_hi = ?,
                expected_size_scheme_key = ?, sort_order = ?, is_active = ? WHERE id = ? LIMIT 1'
        )->execute([
            $subId,
            $slugRaw,
            $nameAr,
            $nameEn,
            $nameFil,
            $nameHi,
            $scheme,
            $sortOrder,
            $active,
            $id,
        ]);
        audit_log('product_type_save', 'تحديث نوع منتج (شجرة موحّدة): ' . $slugRaw, 'product_types', $id);
        json_response(['success' => true, 'id' => $id]);
    }

    $pdo->prepare(
        'INSERT INTO product_types (
            catalog_subcategory_id, slug, name_ar, name_en, name_fil, name_hi,
            expected_size_scheme_key, sort_order, is_active
        ) VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        $subId,
        $slugRaw,
        $nameAr,
        $nameEn,
        $nameFil,
        $nameHi,
        $scheme,
        $sortOrder,
        $active,
    ]);
    $newId = (int) $pdo->lastInsertId();
    audit_log('product_type_save', 'إضافة نوع منتج (شجرة موحّدة): ' . $slugRaw, 'product_types', $newId);
    json_response(['success' => true, 'id' => $newId]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ نوع المنتج في الشجرة الموحّدة');
}
