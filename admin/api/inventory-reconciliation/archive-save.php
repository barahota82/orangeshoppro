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

    $existingId = (int) ($data['id'] ?? 0);
    $isCreate = $existingId <= 0;
    $draftToken = trim((string) ($data['token'] ?? ''));
    $stagedAttachments = is_array($data['attachments'] ?? null) ? $data['attachments'] : [];

    // التحقق: لا حفظ بدون مرفق واحد على الأقل.
    if ($isCreate) {
        if (count($stagedAttachments) < 1) {
            json_response(['success' => false, 'message' => 'يجب إرفاق مرفق واحد على الأقل قبل الحفظ'], 422);
        }
    } else {
        if (count(orange_stocktake_archive_attachments_for($pdo, $existingId)) < 1) {
            json_response(['success' => false, 'message' => 'يجب أن يحتوي السجل على مرفق واحد على الأقل'], 422);
        }
    }

    $id = orange_inventory_reconciliation_archive_save($pdo, [
        'id' => $existingId,
        'warehouse_id' => (int) ($data['warehouse_id'] ?? 0),
        'delivery_agent_id' => (int) ($data['delivery_agent_id'] ?? 0),
        'counted_at' => trim((string) ($data['counted_at'] ?? '')),
        'notes' => trim((string) ($data['notes'] ?? '')),
        'sort_order' => max(1, (int) ($data['sort_order'] ?? 0)),
    ], $ctxCountryId);

    // عند الإنشاء: نقل المرفقات من المجلد المؤقت إلى مجلد السجل وحفظ القائمة.
    if ($isCreate && orange_stocktake_archive_is_valid_draft_token($draftToken)) {
        $finalized = orange_stocktake_archive_finalize_draft($id, $draftToken, $stagedAttachments);
        orange_stocktake_archive_store_attachments($pdo, $id, $finalized);
        orange_stocktake_archive_cleanup_old_drafts();
    }

    audit_log('stocktake_archive_save', 'حفظ سجل أرشيف جرد #' . $id, 'inventory_reconciliation', $id);

    json_response([
        'success' => true,
        'message' => 'تم حفظ سجل الجرد',
        'id' => $id,
        'record' => orange_inventory_reconciliation_archive_get($pdo, $id, $ctxCountryId),
    ]);
} catch (InvalidArgumentException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
