<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';

require_admin_api();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    json_response(['success' => false, 'message' => 'لم يُرسل ملف'], 422);
}

$err = (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
    json_response(['success' => false, 'message' => 'فشل الرفع'], 422);
}

$tmp = (string)($_FILES['image']['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    json_response(['success' => false, 'message' => 'ملف غير صالح'], 422);
}

$maxBytes = 8 * 1024 * 1024;
if ((int)($_FILES['image']['size'] ?? 0) > $maxBytes) {
    json_response(['success' => false, 'message' => 'الملف كبير جداً'], 422);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($tmp);
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];
if (!isset($allowed[$mime])) {
    json_response(['success' => false, 'message' => 'نوع الصورة غير مدعوم'], 422);
}

$ext = $allowed[$mime];
$dir = __DIR__ . '/../../../uploads/products';
if (!is_dir($dir)) {
    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
        json_response(['success' => false, 'message' => 'تعذر إنشاء مجلد الصور'], 500);
    }
}

$name = 'p_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$dest = $dir . DIRECTORY_SEPARATOR . $name;

if (!move_uploaded_file($tmp, $dest)) {
    json_response(['success' => false, 'message' => 'تعذر حفظ الملف'], 500);
}

json_response(['success' => true, 'filename' => $name]);
