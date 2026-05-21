<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/upload_paths.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';

require_admin_api();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

$channelId = (int) ($_POST['channel_id'] ?? $_GET['channel_id'] ?? 0);
if ($channelId > 0) {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    try {
        orange_admin_assert_row_country($pdo, 'channels', $channelId);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }
}

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    json_response(['success' => false, 'message' => 'لم يُرسل ملف'], 422);
}

$err = (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
    json_response(['success' => false, 'message' => 'فشل الرفع'], 422);
}

$tmp = (string) ($_FILES['image']['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    json_response(['success' => false, 'message' => 'ملف غير صالح'], 422);
}

$maxBytes = 4 * 1024 * 1024;
if ((int) ($_FILES['image']['size'] ?? 0) > $maxBytes) {
    json_response(['success' => false, 'message' => 'الملف كبير جداً (حد أقصى 4 ميجا)'], 422);
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
$dir = orange_ensure_channels_upload_dir();
if ($dir === null) {
    json_response([
        'success' => false,
        'message' => 'تعذر إنشاء مجلد uploads/channels أو الكتابة فيه. أنشئه يدوياً وامنح التطبيق صلاحية الكتابة.',
    ], 500);
}

$name = 'ch_' . date('Ymd') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
$dest = $dir . DIRECTORY_SEPARATOR . $name;

if (!move_uploaded_file($tmp, $dest)) {
    json_response(['success' => false, 'message' => 'تعذر حفظ الملف'], 500);
}

orange_image_write_webp_beside($dest);

json_response(['success' => true, 'filename' => $name]);
