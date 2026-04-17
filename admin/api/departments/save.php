<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/arabic_name_duplicate.php';
require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();

    $stmtCols = $pdo->query("
        SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments'
    ");
    $colNames = $stmtCols ? $stmtCols->fetchAll(PDO::FETCH_COLUMN) : [];
    $colNames = is_array($colNames) ? $colNames : [];
    $hasNameFil = in_array('name_fil', $colNames, true);
    $hasNameHi = in_array('name_hi', $colNames, true);

    $nameAr = trim((string)($data['name_ar'] ?? ''));
    $nameEn = trim((string)($data['name_en'] ?? ''));
    $nameFil = trim((string)($data['name_fil'] ?? ''));
    $nameHi = trim((string)($data['name_hi'] ?? ''));
    $slug = trim((string)($data['slug'] ?? ''));
    $sort = (int)($data['sort_order'] ?? 0);

    if ($nameAr === '') {
        json_response(['success' => false, 'message' => 'يجب إضافة خانة الاسم العربي قبل الحفظ'], 422);
    }
    if ($nameEn === '') {
        json_response(['success' => false, 'message' => 'يجب إضافة خانة الاسم الإنجليزي قبل الحفظ'], 422);
    }
    if ($nameFil === '') {
        json_response(['success' => false, 'message' => 'يجب إضافة خانة Filipino قبل الحفظ'], 422);
    }
    if ($nameHi === '') {
        json_response(['success' => false, 'message' => 'يجب إضافة خانة Hindi قبل الحفظ'], 422);
    }
    if ($slug === '') {
        json_response(['success' => false, 'message' => 'يجب إضافة خانة Slug قبل الحفظ'], 422);
    }

    $depRows = $pdo->query('SELECT id, name_ar FROM departments')->fetchAll(PDO::FETCH_ASSOC);
    if (orange_rows_normalized_arabic_conflict(is_array($depRows) ? $depRows : [], 'id', 'name_ar', $nameAr, null)) {
        json_response(['success' => false, 'message' => orange_arabic_duplicate_blocked_message()], 409);
    }

    $slugBase = $slug;
    $candidate = $slugBase;
    $i = 2;
    while (true) {
        $s = $pdo->prepare('SELECT id FROM departments WHERE slug = ? LIMIT 1');
        $s->execute([$candidate]);
        if (!$s->fetch()) {
            break;
        }
        $candidate = $slugBase . '-' . $i;
        $i++;
    }

    if ($sort <= 0) {
        $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM departments')->fetchColumn();
        if ($sort <= 0) {
            $sort = 1;
        }
    }

    if ($hasNameFil && $hasNameHi) {
        $stmt = $pdo->prepare(
            'INSERT INTO departments (name_en, name_ar, name_fil, name_hi, slug, is_active, sort_order) VALUES (?, ?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([$nameEn, $nameAr, $nameFil, $nameHi, $candidate, $sort]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO departments (name_en, name_ar, slug, is_active, sort_order) VALUES (?, ?, ?, 1, ?)'
        );
        $stmt->execute([$nameEn, $nameAr, $candidate, $sort]);
    }

    json_response(['success' => true, 'message' => 'تم حفظ القسم', 'slug' => $candidate]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
