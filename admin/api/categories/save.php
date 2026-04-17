<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();

    $hasDepartmentsTable = (bool)$pdo->query("SHOW TABLES LIKE 'departments'")->fetchColumn();
    $hasCategoryDepartment = false;
    if ($hasDepartmentsTable) {
        $hasCategoryDepartment = (bool)$pdo->query("SHOW COLUMNS FROM categories LIKE 'department_id'")->fetch();
    }

    if (!$hasDepartmentsTable || !$hasCategoryDepartment) {
        json_response(['success' => false, 'message' => 'E_DB'], 500);
    }

    $depId = (int)($data['department_id'] ?? 0);
    $nameAr = trim((string)($data['name_ar'] ?? ''));
    $nameEn = trim((string)($data['name_en'] ?? ''));
    $nameFil = trim((string)($data['name_fil'] ?? ''));
    $nameHi = trim((string)($data['name_hi'] ?? ''));
    $slug = trim((string)($data['slug'] ?? ''));
    $sort = (int)($data['sort_order'] ?? 0);

    if ($depId <= 0) {
        json_response(['success' => false, 'message' => 'E_DEP'], 422);
    }
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

    $dup = $pdo->prepare("SELECT id FROM categories WHERE name_ar = ? AND department_id = ? LIMIT 1");
    $dup->execute([$nameAr, $depId]);
    if ($dup->fetch()) {
        json_response(['success' => false, 'message' => 'E_DUP'], 409);
    }

    $slugBase = $slug;
    $candidate = $slugBase;
    $i = 2;
    while (true) {
        $s = $pdo->prepare('SELECT id FROM categories WHERE slug = ? LIMIT 1');
        $s->execute([$candidate]);
        if (!$s->fetch()) {
            break;
        }
        $candidate = $slugBase . '-' . $i;
        $i++;
    }

    $stmt = $pdo->prepare("
        INSERT INTO categories (department_id, name_en, name_ar, name_fil, name_hi, slug, is_active, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, 1, ?)
    ");
    $stmt->execute([
        $depId,
        $nameEn,
        $nameAr,
        $nameFil,
        $nameHi,
        $candidate,
        $sort
    ]);

    json_response(['success' => true, 'message' => 'OK_SAV', 'slug' => $candidate]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
