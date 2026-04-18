<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();
require_once __DIR__ . '/../lib/translate_names_lib.php';

try {
    $data = get_json_input();
    $nameAr = trim((string) ($data['name_ar'] ?? ''));
    $nameEn = trim((string) ($data['name_en'] ?? ''));
    if ($nameAr === '' && $nameEn === '') {
        json_response(['success' => false, 'message' => 'أدخل اسمًا للترجمة'], 422);
    }

    $t = translate_names_from_ar_en($nameAr, $nameEn);
    json_response([
        'success' => true,
        'translations' => [
            'name_en' => $t['name_en'],
            'name_fil' => $t['name_fil'],
            'name_hi' => $t['name_hi'],
        ],
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
