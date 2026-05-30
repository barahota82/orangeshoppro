<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/bank_reconciliation.php';
require_admin_api();

try {
    if (! orange_bank_reconciliation_ready(db())) {
        json_response(['success' => false, 'message' => 'جداول تسوية البنك غير جاهزة'], 500);
    }

    $raw = '';
    if (isset($_FILES['file']) && is_array($_FILES['file']) && (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $tmp = (string) ($_FILES['file']['tmp_name'] ?? '');
        if ($tmp !== '' && is_readable($tmp)) {
            $content = file_get_contents($tmp);
            $raw = is_string($content) ? $content : '';
        }
    }
    if ($raw === '') {
        $data = get_json_input();
        $raw = trim((string) ($data['csv'] ?? ''));
    }

    if ($raw === '') {
        json_response(['success' => false, 'message' => 'ملف CSV فارغ أو غير مرفوع'], 422);
    }

    $lines = orange_bank_reconciliation_parse_csv($raw);
    if ($lines === []) {
        json_response(['success' => false, 'message' => 'لم تُستخرج أسطر — تأكد من الأعمدة: date, description, amount'], 422);
    }

    json_response([
        'success' => true,
        'lines' => $lines,
        'count' => count($lines),
        'message' => 'تم استيراد ' . count($lines) . ' سطراً',
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
