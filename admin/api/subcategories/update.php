<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/arabic_name_duplicate.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_table_exists($pdo, 'subcategories')) {
        json_response(['success' => false, 'message' => 'E_NO_TABLE'], 500);
    }

    $data = get_json_input();
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'E_ID'], 422);
    }

    $categoryId = (int)($data['category_id'] ?? 0);
    if ($categoryId <= 0) {
        json_response(['success' => false, 'message' => 'E_CAT'], 422);
    }

    $catStmt = $pdo->prepare('SELECT id, department_id FROM categories WHERE id = ? LIMIT 1');
    $catStmt->execute([$categoryId]);
    $catRow = $catStmt->fetch(PDO::FETCH_ASSOC);
    if (!$catRow) {
        json_response(['success' => false, 'message' => 'E_CAT_INVALID'], 422);
    }

    $depId = null;
    if (isset($catRow['department_id']) && $catRow['department_id'] !== null && (int)$catRow['department_id'] > 0) {
        $depId = (int)$catRow['department_id'];
    }

    $nameAr = trim((string)($data['name_ar'] ?? ''));
    $nameEn = trim((string)($data['name_en'] ?? ''));
    $nameFil = trim((string)($data['name_fil'] ?? ''));
    $nameHi = trim((string)($data['name_hi'] ?? ''));
    $slug = trim((string)($data['slug'] ?? ''));
    $sort = (int)($data['sort_order'] ?? 0);

    if ($nameAr === '') {
        json_response(['success' => false, 'message' => 'E_AR'], 422);
    }
    if ($nameEn === '') {
        json_response(['success' => false, 'message' => 'E_EN'], 422);
    }
    if ($nameFil === '') {
        json_response(['success' => false, 'message' => 'E_FIL'], 422);
    }
    if ($nameHi === '') {
        json_response(['success' => false, 'message' => 'E_HI'], 422);
    }
    if ($slug === '') {
        json_response(['success' => false, 'message' => 'E_SLUG'], 422);
    }

    $dupStmt = $pdo->prepare('SELECT id, name_ar FROM subcategories WHERE category_id = ?');
    $dupStmt->execute([$categoryId]);
    $dupRows = $dupStmt->fetchAll(PDO::FETCH_ASSOC);
    if (orange_rows_normalized_arabic_conflict(is_array($dupRows) ? $dupRows : [], 'id', 'name_ar', $nameAr, $id)) {
        json_response(['success' => false, 'message' => orange_arabic_duplicate_blocked_message()], 409);
    }

    $slugBase = $slug;
    $candidate = $slugBase;
    $i = 2;
    while (true) {
        $s = $pdo->prepare('SELECT id FROM subcategories WHERE slug = ? AND id <> ? LIMIT 1');
        $s->execute([$candidate, $id]);
        if (!$s->fetch()) {
            break;
        }
        $candidate = $slugBase . '-' . $i;
        $i++;
    }

    $stmt = $pdo->prepare(
        'UPDATE subcategories SET
            department_id = ?, category_id = ?, name_ar = ?, name_en = ?, name_fil = ?, name_hi = ?,
            slug = ?, sort_order = ?, updated_at = NOW()
         WHERE id = ? LIMIT 1'
    );
    $stmt->execute([
        $depId,
        $categoryId,
        $nameAr,
        $nameEn,
        $nameFil,
        $nameHi,
        $candidate,
        $sort,
        $id,
    ]);

    json_response(['success' => true, 'message' => 'OK_UPD', 'slug' => $candidate]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
