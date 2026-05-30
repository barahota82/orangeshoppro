<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/edit_lock.php';

require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف غير صالح'], 422);
    }
    $preview = orange_edit_lock_preview($pdo, $id);
    json_response([
        'success' => true,
        'lines' => $preview['lines'],
        'meta' => $preview['meta'],
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر معاينة المستند');
}
