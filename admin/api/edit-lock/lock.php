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
    $ids = isset($data['ids']) && is_array($data['ids']) ? $data['ids'] : [];
    $lockFiltered = !empty($data['lock_filtered']);
    if ($lockFiltered) {
        $df = trim((string) ($data['date_from'] ?? ''));
        $dt = trim((string) ($data['date_to'] ?? ''));
        $docKind = trim((string) ($data['doc_kind'] ?? 'all'));
        $result = orange_edit_lock_set_filtered($pdo, $admin, true, $df !== '' ? $df : null, $dt !== '' ? $dt : null, $docKind);
        $locked = $result['locked'];
        $errors = $result['errors'];
    } else {
        $result = orange_edit_lock_set_by_registry_ids($pdo, $admin, $ids, true);
        $locked = $result['locked'];
        $errors = $result['errors'];
    }
    $ok = count($locked) > 0;
    json_response([
        'success' => $ok || $errors === [],
        'message' => $ok
            ? ('تم قفل ' . count($locked) . ' مستند' . ($errors !== [] ? ' — تنبيهات: ' . implode('؛ ', $errors) : ''))
            : ('لم يُقفَل أي مستند' . ($errors !== [] ? ' — ' . implode('؛ ', $errors) : '')),
        'locked' => $locked,
        'errors' => $errors,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر قفل المستندات');
}
