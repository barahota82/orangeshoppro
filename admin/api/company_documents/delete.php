<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/company_documents.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرّف غير صالح'], 422);
    }
    $st = $pdo->prepare('SELECT * FROM orange_company_documents WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(['success' => false, 'message' => 'المستند غير موجود'], 404);
    }

    try {
        orange_company_document_assert_context_ownership($pdo, $row);
    } catch (Throwable $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    $abs = orange_company_document_absolute_path($row);
    $pdo->prepare('DELETE FROM orange_company_documents WHERE id = ?')->execute([$id]);
    if (is_file($abs)) {
        @unlink($abs);
    }
    $docCid = (int) ($row['country_id'] ?? 0);
    audit_log(
        'company_document_delete',
        'حذف مستند أرشيف: ' . (string) ($row['title_ar'] ?? ''),
        'orange_company_documents',
        $id,
        $docCid > 0 ? ['country_id' => $docCid] : []
    );
    json_response(['success' => true, 'message' => 'تم حذف المستند']);
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] company_documents delete: ' . $e->getMessage());
    }
    json_response(['success' => false, 'message' => 'تعذر حذف المستند'], 500);
}
