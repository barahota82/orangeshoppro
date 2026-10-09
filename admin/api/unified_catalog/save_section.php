<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/arabic_name_duplicate.php';
require_once __DIR__ . '/../../../includes/catalog_unified_branch_slug.php';
require_once __DIR__ . '/../../../includes/orange_catalog_name_locale.php';
require_admin_api();

try {
    $pdo = db();

    if (!orange_table_exists($pdo, 'catalog_sections')) {
        json_response(['success' => false, 'message' => 'جدول catalog_sections غير متاح — راجع المخطط أو الترحيل.'], 422);
    }
    if (!orange_table_exists($pdo, 'departments')) {
        json_response(['success' => false, 'message' => 'جدول departments غير متاح — راجع المخطط.'], 422);
    }

    $data = get_json_input();
    $id = (int) ($data['id'] ?? 0);

    $depId = (int) ($data['department_id'] ?? 0);
    $countryId = function_exists('orange_admin_context_country_id') ? (int) orange_admin_context_country_id($pdo) : 0;
    $localeMode = orange_catalog_name_locale_use_payload($pdo, $countryId, $data);
    if ($localeMode && trim((string) ($data['base_text'] ?? '')) === '' && !($id > 0 && !empty($data['base_explicit_empty']))) {
        json_response(['success' => false, 'message' => 'اسم اللغة الأساسية مطلوب.'], 422);
    }
    if ($localeMode) {
        $preview = orange_catalog_name_locale_preview($pdo, $countryId, 'catalog_section', $id, $data);
        $nameAr = $preview['name_ar'];
        $nameEn = $preview['name_en'];
        $nameFil = $preview['name_fil'];
        $nameHi = $preview['name_hi'];
        $data['slug'] = orange_catalog_name_locale_kept_slug($pdo, 'catalog_sections', $id, (string) ($data['slug'] ?? ''));
    } else {
        $nameAr = trim((string) ($data['name_ar'] ?? ''));
        $nameEn = trim((string) ($data['name_en'] ?? ''));
        $nameFil = trim((string) ($data['name_fil'] ?? ''));
        $nameHi = trim((string) ($data['name_hi'] ?? ''));
    }
    $sortOrder = (int) ($data['sort_order'] ?? 0);
    $active = (int) ($data['is_active'] ?? 1) === 0 ? 0 : 1;

    if ($depId <= 0) {
        json_response(['success' => false, 'message' => 'اختر القسم (department) الأب.'], 422);
    }
    $allowExplicitEmpty = $localeMode && $id > 0 && !empty($data['base_explicit_empty']);
    $guard = orange_catalog_name_locale_guard($pdo, $countryId, $localeMode, (string) ($data['base_text'] ?? ''), $nameEn, $nameAr, $nameEn, 'الاسم العربي والإنجليزي مطلوبان.', $allowExplicitEmpty);
    if ($guard !== null) {
        json_response(['success' => false, 'message' => $guard], 422);
    }

    $slugResolved = orange_catalog_unified_branch_slug_resolve(
        (string) ($data['slug'] ?? ''),
        $nameEn,
        $nameAr
    );
    $slugRaw = orange_catalog_unified_branch_slug_allocate(
        $slugResolved,
        static function (string $cand) use ($pdo, $depId, $id): bool {
            $st = $pdo->prepare(
                'SELECT id FROM catalog_sections WHERE department_id = ? AND slug = ? AND id <> ? LIMIT 1'
            );
            $st->execute([$depId, $cand, max(0, $id)]);

            return (bool) $st->fetchColumn();
        }
    );

    $dChk = $pdo->prepare('SELECT id FROM departments WHERE id = ? LIMIT 1');
    $dChk->execute([$depId]);
    if (!$dChk->fetch()) {
        json_response(['success' => false, 'message' => 'القسم المختار غير موجود.'], 404);
    }

    $sib = $pdo->prepare('SELECT id, name_ar FROM catalog_sections WHERE department_id = ?');
    $sib->execute([$depId]);
    $sibRows = $sib->fetchAll(PDO::FETCH_ASSOC);
    $sectionDup = $localeMode
        ? orange_catalog_name_locale_names_conflict($pdo, 'catalog_section', 'department_id', $depId, $id, (string) (orange_country_locale_roles_read($pdo, $countryId)['base'] ?? ''), (string) ($data['base_text'] ?? ''))
        : orange_rows_normalized_arabic_conflict(is_array($sibRows) ? $sibRows : [], 'id', 'name_ar', $nameAr, $id > 0 ? $id : null);
    if ($sectionDup) {
        json_response(['success' => false, 'message' => orange_arabic_duplicate_blocked_message()], 409);
    }

    if ($sortOrder <= 0 && $id <= 0) {
        $nextSt = $pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM catalog_sections WHERE department_id = ?'
        );
        $nextSt->execute([$depId]);
        $sortOrder = (int) $nextSt->fetchColumn();
        if ($sortOrder <= 0) {
            $sortOrder = 1;
        }
    }
    if ($sortOrder <= 0) {
        $sortOrder = 1;
    }

    if ($id > 0) {
        $ex = $pdo->prepare('SELECT id FROM catalog_sections WHERE id = ? LIMIT 1');
        $ex->execute([$id]);
        if (!$ex->fetch()) {
            json_response(['success' => false, 'message' => 'السجل غير موجود.'], 404);
        }
        if ($localeMode) {
            $pdo->beginTransaction();
            $pdo->prepare(
                'UPDATE catalog_sections SET department_id = ?, slug = ?, sort_order = ?, is_active = ? WHERE id = ?'
            )->execute([$depId, $slugRaw, $sortOrder, $active, $id]);
            orange_catalog_name_locale_commit_payload($pdo, $countryId, 'catalog_section', $id, $data);
            $pdo->commit();
        } else {
            $pdo->prepare(
                'UPDATE catalog_sections SET department_id = ?, slug = ?, name_ar = ?, name_en = ?, name_fil = ?, name_hi = ?,
                    sort_order = ?, is_active = ? WHERE id = ?'
            )->execute([$depId, $slugRaw, $nameAr, $nameEn, $nameFil, $nameHi, $sortOrder, $active, $id]);
        }
        audit_log('unified_catalog_section_save', 'تحديث قسم كتالوج موحّد: ' . $slugRaw, 'catalog_sections', $id);
        json_response(['success' => true, 'id' => $id]);
    }

    if ($localeMode) {
        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO catalog_sections (
                department_id, slug, name_ar, name_en, name_fil, name_hi, sort_order, is_active
            ) VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$depId, $slugRaw, '', '', '', '', $sortOrder, $active]);
        $newId = (int) $pdo->lastInsertId();
        orange_catalog_name_locale_commit_payload($pdo, $countryId, 'catalog_section', $newId, $data);
        $pdo->commit();
    } else {
        $pdo->prepare(
            'INSERT INTO catalog_sections (
                department_id, slug, name_ar, name_en, name_fil, name_hi, sort_order, is_active
            ) VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$depId, $slugRaw, $nameAr, $nameEn, $nameFil, $nameHi, $sortOrder, $active]);
        $newId = (int) $pdo->lastInsertId();
    }
    audit_log('unified_catalog_section_save', 'إضافة قسم كتالوج موحّد: ' . $slugRaw, 'catalog_sections', $newId);
    json_response(['success' => true, 'id' => $newId]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر حفظ قسم الشجرة الموحّدة');
}
