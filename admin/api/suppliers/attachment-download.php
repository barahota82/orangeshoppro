<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/supplier_attachments.php';

require_admin_api();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'suppliers')) {
        json_response(['success' => false, 'message' => 'جدول الموردين غير متوفر'], 500);
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'attachments_json')) {
        json_response(['success' => false, 'message' => 'حقل مرفقات المورد غير متوفر'], 422);
    }

    $supplierId = (int) ($_GET['supplier_id'] ?? 0);
    $attachmentId = trim((string) ($_GET['attachment_id'] ?? ''));
    if ($supplierId <= 0 || $attachmentId === '') {
        json_response(['success' => false, 'message' => 'بيانات التنزيل غير مكتملة'], 422);
    }

    $st = $pdo->prepare('SELECT id, attachments_json FROM suppliers WHERE id = ? LIMIT 1');
    $st->execute([$supplierId]);
    $supplier = $st->fetch(PDO::FETCH_ASSOC);
    if (!$supplier) {
        json_response(['success' => false, 'message' => 'المورد غير موجود'], 404);
    }

    $attachments = orange_supplier_attachment_decode_list((string) ($supplier['attachments_json'] ?? ''));
    $picked = null;
    foreach ($attachments as $item) {
        if ((string) ($item['id'] ?? '') === $attachmentId) {
            $picked = $item;
            break;
        }
    }
    if ($picked === null) {
        json_response(['success' => false, 'message' => 'المرفق غير موجود'], 404);
    }

    $abs = orange_supplier_attachment_abs_path((string) ($picked['path'] ?? ''));
    if (!orange_supplier_attachment_is_within_upload_root($abs)) {
        json_response(['success' => false, 'message' => 'مسار المرفق غير آمن'], 422);
    }
    if (!is_file($abs) || !is_readable($abs)) {
        json_response(['success' => false, 'message' => 'ملف المرفق غير متاح'], 404);
    }

    $mime = trim((string) ($picked['mime'] ?? 'application/octet-stream'));
    if ($mime === '') {
        $mime = 'application/octet-stream';
    }
    $downloadName = orange_supplier_attachment_safe_original_name((string) ($picked['original_name'] ?? ''));
    if ($downloadName === 'file') {
        $downloadName = basename($abs);
    }
    $size = (int) filesize($abs);
    $encoded = rawurlencode($downloadName);
    $disposition = "attachment; filename*=UTF-8''" . $encoded;

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('Content-Disposition: ' . $disposition);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, no-store, no-cache, must-revalidate');
    readfile($abs);
    exit;
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تنزيل مرفق المورد');
}
