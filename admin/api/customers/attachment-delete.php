<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/customer_attachments.php';

require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'customers')) {
        json_response(['success' => false, 'message' => 'جدول العملاء غير متوفر'], 500);
    }
    if (!orange_table_has_column($pdo, 'customers', 'attachments_json')) {
        json_response(['success' => false, 'message' => 'حقل مرفقات العميل غير متوفر'], 422);
    }

    $data = get_json_input();
    $customerId = (int) ($data['customer_id'] ?? 0);
    $fileRel = trim((string) ($data['file'] ?? ''));
    $attId = trim((string) ($data['id'] ?? ''));
    if ($customerId <= 0) {
        json_response(['success' => false, 'message' => 'العميل غير محدد'], 422);
    }

    $st = $pdo->prepare('SELECT id, attachments_json FROM customers WHERE id = ? LIMIT 1');
    $st->execute([$customerId]);
    $customer = $st->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        json_response(['success' => false, 'message' => 'العميل غير موجود'], 404);
    }

    $attachments = orange_customer_attachment_decode_list((string) ($customer['attachments_json'] ?? ''));
    $kept = [];
    $removed = null;
    foreach ($attachments as $att) {
        $match = false;
        if ($attId !== '' && $att['id'] === $attId) {
            $match = true;
        } elseif ($fileRel !== '' && $att['path'] === $fileRel) {
            $match = true;
        }
        if ($match && $removed === null) {
            $removed = $att;
            continue;
        }
        $kept[] = $att;
    }
    if ($removed === null) {
        json_response(['success' => false, 'message' => 'المرفق غير موجود'], 404);
    }

    // حذف الملف الفعلي إذا داخل مجلد الـ uploads.
    $abs = orange_customer_attachment_abs_path($removed['path']);
    if (orange_customer_attachment_is_within_upload_root($abs) && is_file($abs)) {
        @unlink($abs);
    }

    $json = orange_customer_attachment_encode_list($kept);
    $up = $pdo->prepare('UPDATE customers SET attachments_json = ? WHERE id = ?');
    $up->execute([$json, $customerId]);

    audit_log('customer_attachment_delete', 'حذف مرفق العميل #' . $customerId . ' — ' . ($removed['name'] ?? $removed['path']), 'customers', $customerId);

    $out = [];
    foreach ($kept as $att) {
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
        'message' => 'تم حذف المرفق',
        'attachments' => $out,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حذف مرفق العميل');
}
