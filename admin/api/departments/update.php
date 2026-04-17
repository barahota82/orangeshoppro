<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'E_DEPT_ID'], 422);
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

    $dup = $pdo->prepare('SELECT id FROM departments WHERE name_ar = ? AND id <> ? LIMIT 1');
    $dup->execute([$nameAr, $id]);
    if ($dup->fetch()) {
        json_response(['success' => false, 'message' => 'E_DUP'], 409);
    }

    $slugBase = $slug;
    $candidate = $slugBase;
    $i = 2;
    while (true) {
        $s = $pdo->prepare('SELECT id FROM departments WHERE slug = ? AND id <> ? LIMIT 1');
        $s->execute([$candidate, $id]);
        if (!$s->fetch()) {
            break;
        }
        $candidate = $slugBase . '-' . $i;
        $i++;
    }

    if ($sort <= 0) {
        $so = $pdo->prepare('SELECT sort_order FROM departments WHERE id = ? LIMIT 1');
        $so->execute([$id]);
        $sort = (int)$so->fetchColumn();
    }

    $stmtCols = $pdo->query("
        SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments'
    ");
    $colNames = $stmtCols ? $stmtCols->fetchAll(PDO::FETCH_COLUMN) : [];
    $colNames = is_array($colNames) ? $colNames : [];
    $hasNameFil = in_array('name_fil', $colNames, true);
    $hasNameHi = in_array('name_hi', $colNames, true);

    if ($hasNameFil && $hasNameHi) {
        $stmt = $pdo->prepare(
            'UPDATE departments SET name_en=?, name_ar=?, name_fil=?, name_hi=?, slug=?, sort_order=? WHERE id=? LIMIT 1'
        );
        $stmt->execute([$nameEn, $nameAr, $nameFil, $nameHi, $candidate, $sort, $id]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE departments SET name_en=?, name_ar=?, slug=?, sort_order=? WHERE id=? LIMIT 1'
        );
        $stmt->execute([$nameEn, $nameAr, $candidate, $sort, $id]);
    }

    json_response(['success' => true, 'message' => 'OK_UPD', 'slug' => $candidate]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
