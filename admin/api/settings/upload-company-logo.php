<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_admin_api();

try {
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        json_response(['success' => false, 'message' => 'لم يُرفع ملف'], 422);
    }

    $file = $_FILES['logo'];
    $maxSize = 4 * 1024 * 1024;
    if ((int) $file['size'] > $maxSize) {
        json_response(['success' => false, 'message' => 'حجم الملف أكبر من 4 ميجا'], 422);
    }

    $mime = mime_content_type($file['tmp_name']);
    $allowed = ['image/png', 'image/webp', 'image/jpeg'];
    if (!in_array($mime, $allowed, true)) {
        json_response(['success' => false, 'message' => 'نوع الملف غير مسموح — PNG أو WebP أو JPEG'], 422);
    }

    $ext = match ($mime) {
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/jpeg' => 'jpg',
        default => 'png',
    };

    $uploadDir = __DIR__ . '/../../../uploads/company';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = 'company-logo.' . $ext;
    $dest = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        json_response(['success' => false, 'message' => 'فشل حفظ الملف على السيرفر'], 500);
    }

    json_response(['success' => true, 'filename' => $filename]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر رفع شعار الشركة');
}
