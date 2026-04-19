<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/upload_paths.php';
require_once __DIR__ . '/../../../includes/company_documents.php';
require_admin_api();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

$titleAr = trim((string) ($_POST['title_ar'] ?? ''));
$docType = trim((string) ($_POST['doc_type'] ?? 'other'));
$refNum = trim((string) ($_POST['reference_number'] ?? ''));
$docDateRaw = trim((string) ($_POST['doc_date'] ?? ''));
$entityTable = trim((string) ($_POST['entity_table'] ?? ''));
$entityId = trim((string) ($_POST['entity_id'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));

$typeLabels = orange_company_document_type_labels();
if (!isset($typeLabels[$docType])) {
    json_response(['success' => false, 'message' => 'نوع المستند غير صالح'], 422);
}

$entityPresets = orange_company_document_entity_presets();
if ($entityTable !== '' && !array_key_exists($entityTable, $entityPresets)) {
    json_response(['success' => false, 'message' => 'نوع الربط غير صالح'], 422);
}
if ($entityTable === '') {
    $entityId = '';
}
if ($entityTable !== '' && $entityId === '') {
    json_response(['success' => false, 'message' => 'معرّف الكيان مطلوب عند اختيار الربط'], 422);
}

if ($titleAr === '') {
    json_response(['success' => false, 'message' => 'عنوان المستند مطلوب'], 422);
}

$docDate = null;
if ($docDateRaw !== '') {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $docDateRaw) !== 1) {
        json_response(['success' => false, 'message' => 'تاريخ المستند بصيغة YYYY-MM-DD'], 422);
    }
    $docDate = $docDateRaw;
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    json_response(['success' => false, 'message' => 'لم يُرفع ملف'], 422);
}
$err = (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
    json_response(['success' => false, 'message' => 'فشل الرفع'], 422);
}

$tmp = (string) ($_FILES['file']['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    json_response(['success' => false, 'message' => 'ملف غير صالح'], 422);
}

$maxBytes = 40 * 1024 * 1024;
if ((int) ($_FILES['file']['size'] ?? 0) > $maxBytes) {
    json_response(['success' => false, 'message' => 'الملف يتجاوز 40 ميجابايت'], 422);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($tmp) ?: '';
$allowed = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'text/plain' => 'txt',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
];
if (!isset($allowed[$mime])) {
    json_response(['success' => false, 'message' => 'نوع الملف غير مسموح (PDF، صور، Word، Excel، نص)'], 422);
}

$orig = (string) ($_FILES['file']['name'] ?? 'file');
$origBase = basename($orig);
$origBase = preg_replace('/[^a-zA-Z0-9._\x{0600}-\x{06FF}\-]+/u', '_', $origBase) ?? 'file';
if ($origBase === '' || $origBase === '_') {
    $origBase = 'document';
}

$dir = orange_ensure_company_docs_month_dir();
if ($dir === null) {
    json_response([
        'success' => false,
        'message' => 'تعذر إنشاء مجلد أرشيف المستندات. أنشئ uploads/company_docs وامنح الكتابة.',
    ], 500);
}

$ext = $allowed[$mime];
$safe = 'd_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
$dest = $dir . DIRECTORY_SEPARATOR . $safe;
if (!move_uploaded_file($tmp, $dest)) {
    json_response(['success' => false, 'message' => 'تعذر حفظ الملف'], 500);
}

$rel = 'company_docs' . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m') . DIRECTORY_SEPARATOR . $safe;
$rel = str_replace('\\', '/', $rel);

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $admin = current_admin();
    $aid = ($admin && !empty($admin['id'])) ? (int) $admin['id'] : null;
    if ($aid !== null && $aid <= 0) {
        $aid = null;
    }

    $st = $pdo->prepare(
        'INSERT INTO orange_company_documents (
            title_ar, doc_type, reference_number, doc_date, entity_table, entity_id, notes,
            storage_path, original_filename, mime_type, file_size, created_by_admin_id
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $st->execute([
        $titleAr,
        $docType,
        $refNum,
        $docDate,
        $entityTable,
        $entityId,
        $notes === '' ? null : $notes,
        $rel,
        $origBase,
        $mime,
        (int) filesize($dest),
        $aid,
    ]);
    $newId = (int) $pdo->lastInsertId();
    audit_log('company_document_upload', 'رفع مستند: ' . $titleAr, 'orange_company_documents', $newId);
    json_response(['success' => true, 'message' => 'تم حفظ المستند', 'id' => $newId]);
} catch (Throwable $e) {
    @unlink($dest);
    if (function_exists('error_log')) {
        error_log('[orange] company doc upload: ' . $e->getMessage());
    }
    json_response(['success' => false, 'message' => 'تعذر تسجيل المستند'], 500);
}
