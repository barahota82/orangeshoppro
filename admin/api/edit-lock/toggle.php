<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/edit_lock.php';

require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $admin = current_admin();
    if (!$admin) {
        json_response(['success' => false, 'message' => 'غير مصرح'], 401);
    }
    $data = get_json_input();
    $kind = trim((string) ($data['doc_kind'] ?? ''));
    $entityId = (int) ($data['entity_id'] ?? 0);
    $lock = !empty($data['lock']);
    $countryId = (int) ($data['country_id'] ?? 0);
    if ($kind === '' || $entityId <= 0) {
        json_response(['success' => false, 'message' => 'نوع المستند والمعرف مطلوبان'], 422);
    }
    if (!orange_edit_lock_kind_is_supported($kind)) {
        json_response(['success' => false, 'message' => 'نوع مستند غير مدعوم'], 422);
    }
    $page = orange_edit_lock_page_for_kind($kind);
    if ($lock && !orange_admin_may_lock($admin, $pdo, $page)) {
        json_response(['success' => false, 'message' => 'لا تملك صلاحية قفل'], 403);
    }
    if (!$lock && !orange_admin_may_unlock($admin, $pdo, $page)) {
        json_response(['success' => false, 'message' => 'لا تملك صلاحية فك القفل'], 403);
    }
    $row = orange_edit_lock_registry_row($pdo, $kind, $entityId, $countryId > 0 ? $countryId : null);
    if ($row === null) {
        json_response(['success' => false, 'message' => 'المستند غير مسجّل في سجل الإقفال — احفظه أولاً'], 404);
    }
    $regId = (int) ($row['id'] ?? 0);
    $result = orange_edit_lock_set_by_registry_ids($pdo, $admin, [$regId], $lock);
    $ok = $lock ? count($result['locked']) > 0 : count($result['unlocked']) > 0;
    json_response([
        'success' => $ok,
        'message' => $ok
            ? ($lock ? 'تم قفل المستند' : 'تم فك قفل المستند')
            : (implode('؛ ', $result['errors']) ?: 'تعذر تنفيذ العملية'),
        'is_locked' => $lock,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تغيير حالة القفل');
}
