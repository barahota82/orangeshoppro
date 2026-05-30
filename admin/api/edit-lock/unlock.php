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
    $unlockFiltered = !empty($data['unlock_filtered']);
    if ($unlockFiltered) {
        [$df, $dt, $docKind, $entryTypes] = orange_edit_lock_filter_args_from_payload($pdo, $data);
        $result = orange_edit_lock_set_filtered($pdo, $admin, false, $df, $dt, $docKind, $entryTypes);
        $unlocked = $result['unlocked'];
        $errors = $result['errors'];
    } else {
        $result = orange_edit_lock_set_by_registry_ids($pdo, $admin, $ids, false);
        $unlocked = $result['unlocked'];
        $errors = $result['errors'];
    }
    $ok = count($unlocked) > 0;
    json_response([
        'success' => $ok || $errors === [],
        'message' => $ok
            ? ('تم فك قفل ' . count($unlocked) . ' مستند' . ($errors !== [] ? ' — تنبيهات: ' . implode('؛ ', $errors) : ''))
            : ('لم يُفك قفل أي مستند' . ($errors !== [] ? ' — ' . implode('؛ ', $errors) : '')),
        'unlocked' => $unlocked,
        'errors' => $errors,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر فك قفل المستندات');
}
