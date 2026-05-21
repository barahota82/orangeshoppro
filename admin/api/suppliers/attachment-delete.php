<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/supplier_attachments.php';
require_once __DIR__ . '/../../../includes/admin_permissions.php';
require_once __DIR__ . '/../../../includes/countries.php';

require_admin_api();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $adminSt = $pdo->prepare('SELECT * FROM admins WHERE id = ? AND is_active = 1 LIMIT 1');
    $adminSt->execute([$adminId]);
    $admin = $adminSt->fetch(PDO::FETCH_ASSOC);
    if (!$admin || !orange_admin_may($admin, $pdo, 'partners', 'delete')) {
        json_response(['success' => false, 'message' => 'لا تملك صلاحية حذف مرفقات المورد'], 403);
    }
    if (!orange_table_exists($pdo, 'suppliers')) {
        json_response(['success' => false, 'message' => 'جدول الموردين غير متوفر'], 500);
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'attachments_json')) {
        json_response(['success' => false, 'message' => 'حقل مرفقات المورد غير متوفر'], 422);
    }

    $data = get_json_input();
    $supplierId = (int) ($data['supplier_id'] ?? 0);
    $attachmentId = trim((string) ($data['attachment_id'] ?? ''));
    if ($supplierId <= 0 || $attachmentId === '') {
        json_response(['success' => false, 'message' => 'بيانات حذف المرفق غير مكتملة'], 422);
    }

    $st = $pdo->prepare('SELECT id, attachments_json FROM suppliers WHERE id = ? LIMIT 1');
    $st->execute([$supplierId]);
    $supplier = $st->fetch(PDO::FETCH_ASSOC);
    if (!$supplier) {
        json_response(['success' => false, 'message' => 'المورد غير موجود'], 404);
    }
    try {
        orange_admin_assert_entity_country($pdo, 'suppliers', $supplierId);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    $attachments = orange_supplier_attachment_decode_list((string) ($supplier['attachments_json'] ?? ''));
    $deleted = null;
    $remaining = [];
    foreach ($attachments as $item) {
        if ($deleted === null && (string) ($item['id'] ?? '') === $attachmentId) {
            $deleted = $item;
            continue;
        }
        $remaining[] = $item;
    }
    if ($deleted === null) {
        json_response(['success' => false, 'message' => 'المرفق غير موجود'], 404);
    }

    $abs = orange_supplier_attachment_abs_path((string) ($deleted['path'] ?? ''));
    if (is_file($abs)) {
        if (!orange_supplier_attachment_is_within_upload_root($abs)) {
            json_response(['success' => false, 'message' => 'مسار المرفق غير آمن'], 422);
        }
        @unlink($abs);
        if (is_file($abs)) {
            json_response(['success' => false, 'message' => 'تعذر حذف الملف من الخادم'], 500);
        }
    }

    $json = orange_supplier_attachment_encode_list($remaining);
    $up = $pdo->prepare('UPDATE suppliers SET attachments_json = ? WHERE id = ?');
    $up->execute([$json, $supplierId]);

    audit_log('supplier_attachment_delete', 'حذف مرفق للمورد #' . $supplierId . ' — ' . (string) ($deleted['name'] ?? ''), 'suppliers', $supplierId);
    json_response([
        'success' => true,
        'message' => 'تم حذف المرفق نهائياً',
        'attachments' => $remaining,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حذف مرفق المورد');
}
