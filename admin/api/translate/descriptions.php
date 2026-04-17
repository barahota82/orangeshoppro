<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_admin_api();
require_once __DIR__ . '/../lib/translate_names_lib.php';

try {
    $data = get_json_input();
    $descAr = trim((string) ($data['description_ar'] ?? ''));
    $descEn = trim((string) ($data['description_en'] ?? ''));
    if ($descAr === '' && $descEn === '') {
        json_response(['success' => false, 'message' => 'أدخل وصفًا للترجمة'], 422);
    }

    $t = translate_descriptions_from_ar_en($descAr, $descEn);
    json_response([
        'success' => true,
        'translations' => [
            'description_en' => $t['description_en'],
            'description_fil' => $t['description_fil'],
            'description_hi' => $t['description_hi'],
        ],
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
