<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/customer_attachments.php';
require_once __DIR__ . '/../../../includes/countries.php';

require_admin_api();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'customers')) {
        json_response(['success' => false, 'message' => 'جدول العملاء غير متوفر'], 500);
    }
    if (!orange_table_has_column($pdo, 'customers', 'attachments_json')) {
        json_response(['success' => false, 'message' => 'حقل مرفقات العميل غير متوفر'], 422);
    }

    $customerId = (int) ($_POST['customer_id'] ?? 0);
    if ($customerId <= 0) {
        json_response(['success' => false, 'message' => 'احفظ العميل أولاً ثم أضف المرفقات'], 422);
    }

    $st = $pdo->prepare('SELECT id, attachments_json FROM customers WHERE id = ? LIMIT 1');
    $st->execute([$customerId]);
    $customer = $st->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        json_response(['success' => false, 'message' => 'العميل غير موجود'], 404);
    }
    try {
        orange_admin_assert_entity_country($pdo, 'customers', $customerId);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    $attachments = orange_customer_attachment_decode_list((string) ($customer['attachments_json'] ?? ''));
    $maxAttachments = orange_customer_attachment_max_count();
    if (count($attachments) >= $maxAttachments) {
        json_response([
            'success' => false,
            'message' => 'الحد الأقصى لمرفقات العميل هو ' . $maxAttachments . ' ملفات',
        ], 422);
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        json_response(['success' => false, 'message' => 'اختر ملفاً للرفع'], 422);
    }
    $err = (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        json_response(['success' => false, 'message' => 'فشل رفع الملف'], 422);
    }

    $tmp = (string) ($_FILES['file']['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        json_response(['success' => false, 'message' => 'ملف غير صالح'], 422);
    }

    $maxBytes = 20 * 1024 * 1024;
    $uploadSize = (int) ($_FILES['file']['size'] ?? 0);
    if ($uploadSize <= 0 || $uploadSize > $maxBytes) {
        json_response(['success' => false, 'message' => 'حجم الملف يجب أن يكون حتى 20 ميجابايت'], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower(trim((string) ($finfo->file($tmp) ?: '')));
    if (!orange_supplier_attachment_is_allowed_mime($mime)) {
        json_response(['success' => false, 'message' => 'الأنواع المسموحة: PDF وصور فقط'], 422);
    }

    $originalName = orange_supplier_attachment_safe_original_name((string) ($_FILES['file']['name'] ?? 'file'));
    $displayName = orange_supplier_attachment_safe_display_name(
        (string) ($_POST['name'] ?? ($_POST['attachment_name'] ?? '')),
        (string) pathinfo($originalName, PATHINFO_FILENAME)
    );

    $dir = orange_ensure_customer_attachment_dir($customerId);
    if ($dir === null) {
        json_response(['success' => false, 'message' => 'تعذر تجهيز مجلد مرفقات العميل'], 500);
    }

    $ext = orange_supplier_attachment_extension_from_mime($mime, $originalName);
    $safe = 'c_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $destAbs = $dir . DIRECTORY_SEPARATOR . $safe;
    if (!move_uploaded_file($tmp, $destAbs)) {
        json_response(['success' => false, 'message' => 'تعذر حفظ الملف على الخادم'], 500);
    }

    $optimized = str_starts_with($mime, 'image/')
        ? orange_supplier_attachment_optimize_image($destAbs, $mime)
        : orange_supplier_attachment_optimize_pdf($destAbs);
    $finalAbs = (string) ($optimized['path'] ?? $destAbs);
    $finalMime = (string) ($optimized['mime'] ?? $mime);
    $finalSize = (int) ($optimized['size'] ?? (is_file($finalAbs) ? filesize($finalAbs) : 0));
    if (!is_file($finalAbs)) {
        @unlink($destAbs);
        json_response(['success' => false, 'message' => 'تعذر تجهيز الملف بعد الرفع'], 500);
    }

    $finalName = basename($finalAbs);
    $relative = 'customers/' . $customerId . '/' . $finalName;
    $attachments[] = [
        'id' => bin2hex(random_bytes(8)),
        'name' => $displayName,
        'path' => $relative,
        'mime' => $finalMime,
        'size' => $finalSize,
        'uploaded_at' => date('Y-m-d H:i:s'),
        'original_name' => $originalName,
    ];

    $json = orange_customer_attachment_encode_list($attachments);
    $up = $pdo->prepare('UPDATE customers SET attachments_json = ? WHERE id = ?');
    $up->execute([$json, $customerId]);

    audit_log('customer_attachment_upload', 'رفع مرفق للعميل #' . $customerId . ' — ' . $displayName, 'customers', $customerId);
    // إخراج الـ attachments بشكل يلائم JS الواجهة (مع حقول file/url مختصرة).
    $out = [];
    foreach ($attachments as $att) {
        $out[] = [
            'id' => $att['id'],
            'name' => $att['name'],
            'file' => $att['path'],
            'url' => storefront_public_path('/admin/api/customers/attachment-download.php?customer_id=' . $customerId . '&id=' . rawurlencode($att['id'])),
            'mime' => $att['mime'],
            'size' => $att['size'],
            'created_at' => $att['uploaded_at'],
        ];
    }
    json_response([
        'success' => true,
        'message' => 'تم رفع المرفق',
        'attachments' => $out,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر رفع مرفق العميل');
}
