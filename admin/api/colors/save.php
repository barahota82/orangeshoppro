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
    $nameFil = trim((string)($data['name_fil'] ?? ''));
    $nameHi = trim((string)($data['name_hi'] ?? ''));
    $hex = trim((string)($data['hex_code'] ?? ''));
    // Placeholder from the form; R/G/B are not hex digits so validation would reject it.
    if (strcasecmp($hex, '#RRGGBB') === 0) {
        $hex = '';
    }
    if ($hex !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) {
        json_response(['success' => false, 'message' => 'لون Hex: اتركه فارغاً أو مثال صالح مثل #FFFFFF (6 أرقام/حروف سداسية بعد #)'], 422);
    }
    $sort = (int)($data['sort_order'] ?? 0);
    $active = (int)($data['is_active'] ?? 1) === 0 ? 0 : 1;

    if ($nameAr === '' || $nameEn === '' || $nameFil === '' || $nameHi === '') {
        json_response(['success' => false, 'message' => 'عبّئ العربي والإنجليزي، واستخدم «ترجمة تلقائية» لباقي اللغات أو اكتبها يدوياً'], 422);
    }

    if ($id > 0) {
        $dupAr = $pdo->prepare('SELECT id FROM color_dictionary WHERE name_ar = ? AND id <> ? LIMIT 1');
        $dupAr->execute([$nameAr, $id]);
        if ($dupAr->fetch()) {
            json_response(['success' => false, 'message' => 'الاسم العربي مكرر في قاموس الألوان'], 409);
        }
    } else {
        $dupAr = $pdo->prepare('SELECT id FROM color_dictionary WHERE name_ar = ? LIMIT 1');
        $dupAr->execute([$nameAr]);
        if ($dupAr->fetch()) {
            json_response(['success' => false, 'message' => 'الاسم العربي مكرر في قاموس الألوان'], 409);
        }
    }

    if ($id <= 0 && $sort <= 0) {
        $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM color_dictionary')->fetchColumn();
        if ($sort <= 0) {
            $sort = 1;
        }
    }

    if ($id > 0) {
        $pdo->prepare(
            'UPDATE color_dictionary SET name_ar=?, name_en=?, name_fil=?, name_hi=?, hex_code=?, sort_order=?, is_active=? WHERE id=? LIMIT 1'
        )->execute([$nameAr, $nameEn, $nameFil, $nameHi, $hex === '' ? null : $hex, $sort, $active, $id]);
        json_response(['success' => true, 'id' => $id]);
    }

    $pdo->prepare(
        'INSERT INTO color_dictionary (name_ar, name_en, name_fil, name_hi, hex_code, sort_order, is_active) VALUES (?,?,?,?,?,?,?)'
    )->execute([$nameAr, $nameEn, $nameFil, $nameHi, $hex === '' ? null : $hex, $sort, $active]);
    json_response(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
