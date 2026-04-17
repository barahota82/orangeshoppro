<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $id = (int)($data['id'] ?? 0);
    $nameAr = trim((string)($data['name_ar'] ?? ''));
    $nameEn = trim((string)($data['name_en'] ?? ''));
    $sort = (int)($data['sort_order'] ?? 0);
    $active = (int)($data['is_active'] ?? 1) === 0 ? 0 : 1;

    if ($nameAr === '' || $nameEn === '') {
        json_response(['success' => false, 'message' => 'يجب تعبئة الاسم العربي والإنجليزي'], 422);
    }

    if ($id > 0) {
        $dupAr = $pdo->prepare('SELECT id FROM size_families WHERE name_ar = ? AND id <> ? LIMIT 1');
        $dupAr->execute([$nameAr, $id]);
        if ($dupAr->fetch()) {
            json_response(['success' => false, 'message' => 'الاسم العربي مكرر في عائلات المقاسات'], 409);
        }
    } else {
        $dupAr = $pdo->prepare('SELECT id FROM size_families WHERE name_ar = ? LIMIT 1');
        $dupAr->execute([$nameAr]);
        if ($dupAr->fetch()) {
            json_response(['success' => false, 'message' => 'الاسم العربي مكرر في عائلات المقاسات'], 409);
        }
    }

    if ($id <= 0 && $sort <= 0) {
        $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM size_families')->fetchColumn();
        if ($sort <= 0) {
            $sort = 1;
        }
    }

    if ($id > 0) {
        $pdo->prepare(
            'UPDATE size_families SET name_ar=?, name_en=?, sort_order=?, is_active=? WHERE id=? LIMIT 1'
        )->execute([$nameAr, $nameEn, $sort, $active, $id]);
        json_response(['success' => true, 'id' => $id]);
    }

    $pdo->prepare(
        'INSERT INTO size_families (name_ar, name_en, sort_order, is_active) VALUES (?,?,?,?)'
    )->execute([$nameAr, $nameEn, $sort, $active]);
    json_response(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
