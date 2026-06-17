<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/inventory_reconciliation.php';
require_once __DIR__ . '/../../../includes/stocktake_archive.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_admin_api();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_inventory_reconciliation_ready($pdo)) {
        json_response(['success' => false, 'message' => 'جداول الجرد غير جاهزة'], 500);
    }

    $ctxCountryId = orange_admin_settings_effective_country_id($pdo);
    $recId = (int) ($_GET['id'] ?? 0);
    $attachmentId = trim((string) ($_GET['attachment_id'] ?? ''));
    $inline = (int) ($_GET['inline'] ?? 0) === 1;

    $picked = null;
    if ($recId <= 0) {
        // فرع المسودة: عرض/تنزيل مرفق مؤقت قبل الحفظ (token + path).
        $draftToken = trim((string) ($_GET['token'] ?? ''));
        $path = ltrim(str_replace('\\', '/', trim((string) ($_GET['path'] ?? ''))), '/');
        $expectedPrefix = 'stocktake/_drafts/' . $draftToken . '/';
        if (! orange_stocktake_archive_is_valid_draft_token($draftToken)
            || ! str_starts_with($path, $expectedPrefix) || str_contains($path, '..')) {
            json_response(['success' => false, 'message' => 'بيانات التنزيل غير صالحة'], 422);
        }
        $picked = [
            'path' => $path,
            'mime' => trim((string) ($_GET['mime'] ?? '')),
            'original_name' => (string) ($_GET['name'] ?? ''),
        ];
    } else {
        if ($attachmentId === '') {
            json_response(['success' => false, 'message' => 'بيانات التنزيل غير مكتملة'], 422);
        }
        $rec = orange_inventory_reconciliation_archive_get($pdo, $recId, $ctxCountryId);
        if ($rec === null) {
            json_response(['success' => false, 'message' => 'سجل الجرد غير موجود'], 404);
        }
        foreach (($rec['attachments'] ?? []) as $item) {
            if ((string) ($item['id'] ?? '') === $attachmentId) {
                $picked = $item;
                break;
            }
        }
        if ($picked === null) {
            json_response(['success' => false, 'message' => 'المرفق غير موجود'], 404);
        }
    }

    $abs = orange_stocktake_archive_abs_path((string) ($picked['path'] ?? ''));
    if (! orange_stocktake_archive_is_within_upload_root($abs)) {
        json_response(['success' => false, 'message' => 'مسار المرفق غير آمن'], 422);
    }
    if (! is_file($abs) || ! is_readable($abs)) {
        json_response(['success' => false, 'message' => 'ملف المرفق غير متاح'], 404);
    }

    $mime = trim((string) ($picked['mime'] ?? 'application/octet-stream'));
    if ($mime === '') {
        $mime = 'application/octet-stream';
    }
    $downloadName = orange_stocktake_archive_safe_original_name((string) ($picked['original_name'] ?? ''));
    if ($downloadName === 'file') {
        $downloadName = basename($abs);
    }
    $size = (int) filesize($abs);
    $encoded = rawurlencode($downloadName);
    $dispositionType = ($inline && (str_starts_with($mime, 'image/') || $mime === 'application/pdf')) ? 'inline' : 'attachment';
    $disposition = $dispositionType . "; filename*=UTF-8''" . $encoded;

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('Content-Disposition: ' . $disposition);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, no-store, no-cache, must-revalidate');
    readfile($abs);
    exit;
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تنزيل مرفق الجرد');
}
