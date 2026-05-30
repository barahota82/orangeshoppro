<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/edit_lock.php';

require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $kind = trim((string) ($_GET['doc_kind'] ?? ''));
    $entityId = (int) ($_GET['entity_id'] ?? 0);
    $countryId = (int) ($_GET['country_id'] ?? 0);
    if ($kind === '' || $entityId <= 0) {
        json_response(['success' => false, 'message' => 'معاملات ناقصة'], 422);
    }
    $locked = orange_edit_lock_is_locked($pdo, $kind, $entityId, $countryId > 0 ? $countryId : null);
    json_response([
        'success' => true,
        'is_locked' => $locked,
        'doc_kind' => $kind,
        'entity_id' => $entityId,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر قراءة حالة القفل');
}
