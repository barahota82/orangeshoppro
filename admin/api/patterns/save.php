<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/arabic_name_duplicate.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_table_exists($pdo, 'pattern_dictionary')) {
        json_response(['success' => false, 'message' => 'جدول الأنماط غير مهيأ'], 500);
    }
    $data = get_json_input();

    $id = (int) ($data['id'] ?? 0);
    $nameAr = trim((string) ($data['name_ar'] ?? ''));
    $nameEn = trim((string) ($data['name_en'] ?? ''));
    $nameFil = trim((string) ($data['name_fil'] ?? ''));
    $nameHi = trim((string) ($data['name_hi'] ?? ''));
    $sort = (int) ($data['sort_order'] ?? 0);
    $active = (int) ($data['is_active'] ?? 1) === 0 ? 0 : 1;

    if ($nameAr === '' || $nameEn === '' || $nameFil === '' || $nameHi === '') {
        json_response(['success' => false, 'message' => 'عبّئ العربي والإنجليزي، واستخدم «ترجمة تلقائية» لباقي اللغات أو اكتبها يدوياً'], 422);
    }

    $patRows = $pdo->query('SELECT id, name_ar FROM pattern_dictionary')->fetchAll(PDO::FETCH_ASSOC);
    $excludeId = $id > 0 ? $id : null;
    if (orange_rows_normalized_arabic_conflict(is_array($patRows) ? $patRows : [], 'id', 'name_ar', $nameAr, $excludeId)) {
        json_response(['success' => false, 'message' => orange_arabic_duplicate_blocked_message()], 409);
    }

    if ($id <= 0 && $sort <= 0) {
        $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM pattern_dictionary')->fetchColumn();
        if ($sort <= 0) {
            $sort = 1;
        }
    }

    if ($id > 0) {
        $pdo->prepare(
            'UPDATE pattern_dictionary SET name_ar=?, name_en=?, name_fil=?, name_hi=?, sort_order=?, is_active=? WHERE id=? LIMIT 1'
        )->execute([$nameAr, $nameEn, $nameFil, $nameHi, $sort, $active, $id]);
        json_response(['success' => true, 'id' => $id]);
    }

    $pdo->prepare(
        'INSERT INTO pattern_dictionary (name_ar, name_en, name_fil, name_hi, sort_order, is_active) VALUES (?,?,?,?,?,?)'
    )->execute([$nameAr, $nameEn, $nameFil, $nameHi, $sort, $active]);
    json_response(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذّر حفظ النمط');
}
