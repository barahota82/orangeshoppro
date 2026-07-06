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

    $ctxCountryId = orange_admin_settings_effective_country_id($pdo);
    $data = get_json_input();
    $recId = (int) ($data['id'] ?? 0);
    $attachmentId = trim((string) ($data['attachment_id'] ?? ''));

    // فرع المسودة: حذف مرفق مؤقت قبل حفظ السجل (id<=0 + token + path).
    if ($recId <= 0) {
        $draftToken = trim((string) ($data['token'] ?? ''));
        $path = ltrim(str_replace('\\', '/', trim((string) ($data['path'] ?? ''))), '/');
        $expectedPrefix = 'stocktake/_drafts/' . $draftToken . '/';
        if (! orange_stocktake_archive_is_valid_draft_token($draftToken)
            || ! str_starts_with($path, $expectedPrefix) || str_contains($path, '..')) {
            json_response(['success' => false, 'message' => 'بيانات الحذف غير صالحة'], 422);
        }
        $abs = orange_stocktake_archive_abs_path($path);
        if (orange_stocktake_archive_is_within_upload_root($abs) && is_file($abs)) {
            @unlink($abs);
        }
        json_response([
            'success' => true,
            'message' => 'تم حذف المرفق',
        ]);
    }

    if ($attachmentId === '') {
        json_response(['success' => false, 'message' => 'بيانات الحذف غير مكتملة'], 422);
    }

    $rec = orange_inventory_reconciliation_archive_get($pdo, $recId, $ctxCountryId);
    if ($rec === null) {
        json_response(['success' => false, 'message' => 'سجل الجرد غير موجود'], 404);
    }

    $attachments = orange_stocktake_archive_attachments_for($pdo, $recId);
    $kept = [];
    $removed = null;
    foreach ($attachments as $item) {
        if ((string) ($item['id'] ?? '') === $attachmentId) {
            $removed = $item;
            continue;
        }
        $kept[] = $item;
    }
    if ($removed === null) {
        json_response(['success' => false, 'message' => 'المرفق غير موجود'], 404);
    }

    $abs = orange_stocktake_archive_abs_path((string) ($removed['path'] ?? ''));
    if (orange_stocktake_archive_is_within_upload_root($abs) && is_file($abs)) {
        @unlink($abs);
    }

    orange_stocktake_archive_store_attachments($pdo, $recId, $kept);
    audit_log('stocktake_archive_attachment_delete', 'حذف مرفق جرد #' . $recId, 'inventory_reconciliation', $recId);

    json_response([
        'success' => true,
        'message' => 'تم حذف المرفق',
        'attachments' => $kept,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حذف مرفق الجرد');
}
