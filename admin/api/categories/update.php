<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'E_CAT_ID'], 422);
    }

    $depId = (int)($data['department_id'] ?? 0);
    $nameAr = trim((string)($data['name_ar'] ?? ''));
    $nameEn = trim((string)($data['name_en'] ?? ''));
    $nameFil = trim((string)($data['name_fil'] ?? ''));
    $nameHi = trim((string)($data['name_hi'] ?? ''));
    $slug = trim((string)($data['slug'] ?? ''));
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

    $dup = $pdo->prepare("SELECT id FROM categories WHERE name_ar = ? AND department_id = ? AND id <> ? LIMIT 1");
    $dup->execute([$nameAr, $depId, $id]);
    if ($dup->fetch()) {
        json_response(['success' => false, 'message' => 'هذه الفئة مسجلة بالفعل بنفس القسم — الاسم العربي مكرر'], 409);
    }

    $slugBase = $slug;
    $candidate = $slugBase;
    $i = 2;
    while (true) {
        $s = $pdo->prepare("SELECT id FROM categories WHERE slug = ? AND id <> ? LIMIT 1");
        $s->execute([$candidate, $id]);
        if (!$s->fetch()) break;
        $candidate = $slugBase . '-' . $i;
        $i++;
    }

    $stmt = $pdo->prepare("UPDATE categories SET department_id=?, name_en=?, name_ar=?, name_fil=?, name_hi=?, slug=? WHERE id=? LIMIT 1");
    $stmt->execute([$depId, $nameEn, $nameAr, $nameFil, $nameHi, $candidate, $id]);

    json_response(['success' => true, 'message' => 'OK_UPD', 'slug' => $candidate]);
} catch (Throwable $e) {
    json_response(['success'=>false,'message'=>$e->getMessage()],500);
}
