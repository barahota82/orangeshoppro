<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/arabic_name_duplicate.php';
require_once __DIR__ . '/../../../includes/catalog_unified_branch_slug.php';
require_admin_api();

try {
    $pdo = db();

    if (!orange_table_exists($pdo, 'catalog_categories') || !orange_table_exists($pdo, 'catalog_sections')) {
        json_response(['success' => false, 'message' => 'جداول الشجرة الموحّدة غير جاهزة — راجع المخطط أو الترحيل.'], 422);
    }

    $data = get_json_input();
    $id = (int) ($data['id'] ?? 0);

    $sectionId = (int) ($data['catalog_section_id'] ?? 0);
    $nameAr = trim((string) ($data['name_ar'] ?? ''));
    $nameEn = trim((string) ($data['name_en'] ?? ''));
    $nameFil = trim((string) ($data['name_fil'] ?? ''));
    $nameHi = trim((string) ($data['name_hi'] ?? ''));
    $sortOrder = (int) ($data['sort_order'] ?? 0);
    $active = (int) ($data['is_active'] ?? 1) === 0 ? 0 : 1;

    if ($sectionId <= 0) {
        json_response(['success' => false, 'message' => 'يجب اختيار القسم الداخلي الموحّد (catalog_sections).'], 422);
    }
    if ($nameAr === '' || $nameEn === '') {
        json_response(['success' => false, 'message' => 'الاسم العربي والإنجليزي مطلوبان.'], 422);
    }

    $secSt = $pdo->prepare('SELECT slug, name_en FROM catalog_sections WHERE id = ? LIMIT 1');
    $secSt->execute([$sectionId]);
    $secRow = $secSt->fetch(PDO::FETCH_ASSOC);
    $secPrefix = '';
    if (is_array($secRow)) {
        $secPrefix = trim((string) ($secRow['slug'] ?? ''));
        if ($secPrefix === '') {
            $secPrefix = trim((string) ($secRow['name_en'] ?? ''));
        }
    }
    $slugResolved = orange_catalog_unified_branch_slug_resolve_prefixed(
        (string) ($data['slug'] ?? ''),
        [$secPrefix],
        $nameEn,
        $nameAr
    );
    $slugRaw = orange_catalog_unified_branch_slug_allocate(
        $slugResolved,
        static function (string $cand) use ($pdo, $sectionId, $id): bool {
            $st = $pdo->prepare(
                'SELECT id FROM catalog_categories WHERE catalog_section_id = ? AND slug = ? AND id <> ? LIMIT 1'
            );
            $st->execute([$sectionId, $cand, max(0, $id)]);

            return (bool) $st->fetchColumn();
        }
    );

    $subChk = $pdo->prepare('SELECT id FROM catalog_sections WHERE id = ? LIMIT 1');
    $subChk->execute([$sectionId]);
    if (!$subChk->fetch()) {
        json_response(['success' => false, 'message' => 'القسم الداخلي غير موجود.'], 404);
    }

    $sib = $pdo->prepare('SELECT id, name_ar FROM catalog_categories WHERE catalog_section_id = ?');
    $sib->execute([$sectionId]);
    $sibRows = $sib->fetchAll(PDO::FETCH_ASSOC);
    if (orange_rows_normalized_arabic_conflict(is_array($sibRows) ? $sibRows : [], 'id', 'name_ar', $nameAr, $id > 0 ? $id : null)) {
        json_response(['success' => false, 'message' => orange_arabic_duplicate_blocked_message()], 409);
    }

    if ($sortOrder <= 0 && $id <= 0) {
        $nextSt = $pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM catalog_categories WHERE catalog_section_id = ?'
        );
        $nextSt->execute([$sectionId]);
        $sortOrder = (int) $nextSt->fetchColumn();
        if ($sortOrder <= 0) {
            $sortOrder = 1;
        }
    }
    if ($sortOrder <= 0) {
        $sortOrder = 1;
    }

    if ($id > 0) {
        $ex = $pdo->prepare('SELECT id FROM catalog_categories WHERE id = ? LIMIT 1');
        $ex->execute([$id]);
        if (!$ex->fetch()) {
            json_response(['success' => false, 'message' => 'السجل غير موجود.'], 404);
        }
        $pdo->prepare(
            'UPDATE catalog_categories SET catalog_section_id = ?, slug = ?, name_ar = ?, name_en = ?, name_fil = ?, name_hi = ?,
                sort_order = ?, is_active = ? WHERE id = ? LIMIT 1'
        )->execute([$sectionId, $slugRaw, $nameAr, $nameEn, $nameFil, $nameHi, $sortOrder, $active, $id]);
        audit_log('unified_catalog_category_save', 'تحديث فئة كتالوج موحّد: ' . $slugRaw, 'catalog_categories', $id);
        json_response(['success' => true, 'id' => $id]);
    }

    $pdo->prepare(
        'INSERT INTO catalog_categories (
            catalog_section_id, slug, name_ar, name_en, name_fil, name_hi, sort_order, is_active
        ) VALUES (?,?,?,?,?,?,?,?)'
    )->execute([$sectionId, $slugRaw, $nameAr, $nameEn, $nameFil, $nameHi, $sortOrder, $active]);
    $newId = (int) $pdo->lastInsertId();
    audit_log('unified_catalog_category_save', 'إضافة فئة كتالوج موحّد: ' . $slugRaw, 'catalog_categories', $newId);
    json_response(['success' => true, 'id' => $newId]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ فئة الشجرة الموحّدة');
}
