<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/arabic_name_duplicate.php';
require_once __DIR__ . '/../../../includes/catalog_unified_branch_slug.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_table_exists($pdo, 'catalog_subcategories') || !orange_table_exists($pdo, 'catalog_categories')) {
        json_response(['success' => false, 'message' => 'جداول الشجرة الموحّدة غير جاهزة.'], 503);
    }

    $data = get_json_input();
    $id = (int) ($data['id'] ?? 0);

    $categoryId = (int) ($data['catalog_category_id'] ?? 0);
    $nameAr = trim((string) ($data['name_ar'] ?? ''));
    $nameEn = trim((string) ($data['name_en'] ?? ''));
    $nameFil = trim((string) ($data['name_fil'] ?? ''));
    $nameHi = trim((string) ($data['name_hi'] ?? ''));
    $sortOrder = (int) ($data['sort_order'] ?? 0);
    $active = (int) ($data['is_active'] ?? 1) === 0 ? 0 : 1;

    if ($categoryId <= 0) {
        json_response(['success' => false, 'message' => 'يجب اختيار الفئة الموحّدة (catalog_categories) الأم.'], 422);
    }
    if ($nameAr === '' || $nameEn === '') {
        json_response(['success' => false, 'message' => 'الاسم العربي والإنجليزي مطلوبان.'], 422);
    }

    $trailSt = $pdo->prepare(
        'SELECT cc.slug AS cat_slug, cc.name_en AS cat_name_en, cs.slug AS sec_slug, cs.name_en AS sec_name_en
         FROM catalog_categories cc
         INNER JOIN catalog_sections cs ON cs.id = cc.catalog_section_id
         WHERE cc.id = ? LIMIT 1'
    );
    $trailSt->execute([$categoryId]);
    $trail = $trailSt->fetch(PDO::FETCH_ASSOC);
    $secPrefix = '';
    $catPrefix = '';
    if (is_array($trail)) {
        $secPrefix = trim((string) ($trail['sec_slug'] ?? ''));
        if ($secPrefix === '') {
            $secPrefix = trim((string) ($trail['sec_name_en'] ?? ''));
        }
        $catPrefix = trim((string) ($trail['cat_slug'] ?? ''));
        if ($catPrefix === '') {
            $catPrefix = trim((string) ($trail['cat_name_en'] ?? ''));
        }
    }
    $slugResolved = orange_catalog_unified_branch_slug_resolve_prefixed(
        (string) ($data['slug'] ?? ''),
        [$secPrefix, $catPrefix],
        $nameEn,
        $nameAr
    );
    $slugRaw = orange_catalog_unified_branch_slug_allocate(
        $slugResolved,
        static function (string $cand) use ($pdo, $categoryId, $id): bool {
            $st = $pdo->prepare(
                'SELECT id FROM catalog_subcategories WHERE catalog_category_id = ? AND slug = ? AND id <> ? LIMIT 1'
            );
            $st->execute([$categoryId, $cand, max(0, $id)]);

            return (bool) $st->fetchColumn();
        }
    );

    $subChk = $pdo->prepare('SELECT id FROM catalog_categories WHERE id = ? LIMIT 1');
    $subChk->execute([$categoryId]);
    if (!$subChk->fetch()) {
        json_response(['success' => false, 'message' => 'الفئة الموحّدة غير موجودة.'], 404);
    }

    $sib = $pdo->prepare('SELECT id, name_ar FROM catalog_subcategories WHERE catalog_category_id = ?');
    $sib->execute([$categoryId]);
    $sibRows = $sib->fetchAll(PDO::FETCH_ASSOC);
    if (orange_rows_normalized_arabic_conflict(is_array($sibRows) ? $sibRows : [], 'id', 'name_ar', $nameAr, $id > 0 ? $id : null)) {
        json_response(['success' => false, 'message' => orange_arabic_duplicate_blocked_message()], 409);
    }

    if ($sortOrder <= 0 && $id <= 0) {
        $nextSt = $pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM catalog_subcategories WHERE catalog_category_id = ?'
        );
        $nextSt->execute([$categoryId]);
        $sortOrder = (int) $nextSt->fetchColumn();
        if ($sortOrder <= 0) {
            $sortOrder = 1;
        }
    }
    if ($sortOrder <= 0) {
        $sortOrder = 1;
    }

    if ($id > 0) {
        $ex = $pdo->prepare('SELECT id FROM catalog_subcategories WHERE id = ? LIMIT 1');
        $ex->execute([$id]);
        if (!$ex->fetch()) {
            json_response(['success' => false, 'message' => 'السجل غير موجود.'], 404);
        }
        $pdo->prepare(
            'UPDATE catalog_subcategories SET catalog_category_id = ?, slug = ?, name_ar = ?, name_en = ?, name_fil = ?, name_hi = ?,
                sort_order = ?, is_active = ? WHERE id = ? LIMIT 1'
        )->execute([$categoryId, $slugRaw, $nameAr, $nameEn, $nameFil, $nameHi, $sortOrder, $active, $id]);
        audit_log('unified_catalog_subcategory_save', 'تحديث تصنيف فرعي موحّد: ' . $slugRaw, 'catalog_subcategories', $id);
        json_response(['success' => true, 'id' => $id]);
    }

    $pdo->prepare(
        'INSERT INTO catalog_subcategories (
            catalog_category_id, slug, name_ar, name_en, name_fil, name_hi, sort_order, is_active
        ) VALUES (?,?,?,?,?,?,?,?)'
    )->execute([$categoryId, $slugRaw, $nameAr, $nameEn, $nameFil, $nameHi, $sortOrder, $active]);
    $newId = (int) $pdo->lastInsertId();
    audit_log('unified_catalog_subcategory_save', 'إضافة تصنيف فرعي موحّد: ' . $slugRaw, 'catalog_subcategories', $newId);
    json_response(['success' => true, 'id' => $newId]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ التصنيف الفرعي للشجرة الموحّدة');
}
