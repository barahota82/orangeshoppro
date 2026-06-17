<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/inventory_reconciliation.php';
require_once __DIR__ . '/../../../includes/stocktake_archive.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_admin_api();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_inventory_reconciliation_ready($pdo)) {
        json_response(['success' => false, 'message' => 'جداول الجرد غير جاهزة'], 500);
    }
    if (! orange_table_has_column($pdo, 'inventory_reconciliation', 'attachments_json')) {
        json_response(['success' => false, 'message' => 'حقل مرفقات الجرد غير متوفر'], 422);
    }

    $ctxCountryId = orange_admin_settings_effective_country_id($pdo);
    $recId = (int) ($_POST['id'] ?? 0);
    $draftToken = trim((string) ($_POST['token'] ?? ''));
    $isDraft = $recId <= 0;
    $maxAttachments = 20;

    if ($isDraft) {
        if (! orange_stocktake_archive_is_valid_draft_token($draftToken)) {
            json_response(['success' => false, 'message' => 'جلسة الإضافة غير صالحة — أعد تحميل الصفحة'], 422);
        }
        $dir = orange_ensure_stocktake_archive_draft_dir($draftToken);
        if ($dir === null) {
            json_response(['success' => false, 'message' => 'تعذر تجهيز مجلد المرفقات المؤقت'], 500);
        }
        $existingCount = 0;
        foreach ((array) @scandir($dir) as $f) {
            if ($f !== '.' && $f !== '..' && is_file($dir . DIRECTORY_SEPARATOR . $f)) {
                $existingCount++;
            }
        }
        if ($existingCount >= $maxAttachments) {
            json_response(['success' => false, 'message' => 'الحد الأقصى للمرفقات هو 20 ملفاً'], 422);
        }
    } else {
        $rec = orange_inventory_reconciliation_archive_get($pdo, $recId, $ctxCountryId);
        if ($rec === null) {
            json_response(['success' => false, 'message' => 'سجل الجرد غير موجود'], 404);
        }
        $attachments = orange_stocktake_archive_attachments_for($pdo, $recId);
        if (count($attachments) >= $maxAttachments) {
            json_response(['success' => false, 'message' => 'الحد الأقصى للمرفقات هو 20 ملفاً'], 422);
        }
        $dir = orange_ensure_stocktake_archive_dir($recId);
        if ($dir === null) {
            json_response(['success' => false, 'message' => 'تعذر تجهيز مجلد مرفقات الجرد'], 500);
        }
    }

    if (! isset($_FILES['file']) || ! is_array($_FILES['file'])) {
        json_response(['success' => false, 'message' => 'اختر ملفاً للرفع'], 422);
    }
    if ((int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['success' => false, 'message' => 'فشل رفع الملف'], 422);
    }

    $tmp = (string) ($_FILES['file']['tmp_name'] ?? '');
    if ($tmp === '' || ! is_uploaded_file($tmp)) {
        json_response(['success' => false, 'message' => 'ملف غير صالح'], 422);
    }

    $maxBytes = 25 * 1024 * 1024;
    $uploadSize = (int) ($_FILES['file']['size'] ?? 0);
    if ($uploadSize <= 0 || $uploadSize > $maxBytes) {
        json_response(['success' => false, 'message' => 'حجم الملف يجب أن يكون حتى 25 ميجابايت'], 422);
    }

    $originalName = orange_stocktake_archive_safe_original_name((string) ($_FILES['file']['name'] ?? 'file'));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower(trim((string) ($finfo->file($tmp) ?: '')));
    if (! orange_stocktake_archive_is_allowed($mime, $originalName)) {
        json_response(['success' => false, 'message' => 'الأنواع المسموحة: PDF وصور و Excel و Word'], 422);
    }

    $displayName = orange_stocktake_archive_safe_display_name(
        (string) ($_POST['attachment_name'] ?? ''),
        (string) pathinfo($originalName, PATHINFO_FILENAME)
    );

    $ext = orange_stocktake_archive_extension_from_mime($mime, $originalName);
    $safe = 'st_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $destAbs = $dir . DIRECTORY_SEPARATOR . $safe;
    if (! move_uploaded_file($tmp, $destAbs)) {
        json_response(['success' => false, 'message' => 'تعذر حفظ الملف على الخادم'], 500);
    }

    $finalSize = is_file($destAbs) ? (int) filesize($destAbs) : 0;
    $newItem = [
        'id' => bin2hex(random_bytes(8)),
        'name' => $displayName,
        'path' => ($isDraft ? ('stocktake/_drafts/' . $draftToken . '/') : ('stocktake/' . $recId . '/')) . basename($destAbs),
        'mime' => $mime,
        'size' => $finalSize,
        'uploaded_at' => date('Y-m-d H:i:s'),
        'original_name' => $originalName,
    ];

    if ($isDraft) {
        json_response([
            'success' => true,
            'message' => 'تم رفع المرفق',
            'attachment' => $newItem,
        ]);
    }

    $attachments[] = $newItem;
    orange_stocktake_archive_store_attachments($pdo, $recId, $attachments);
    audit_log('stocktake_archive_attachment_upload', 'رفع مرفق جرد #' . $recId . ' — ' . $displayName, 'inventory_reconciliation', $recId);

    json_response([
        'success' => true,
        'message' => 'تم رفع المرفق',
        'attachments' => $attachments,
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
