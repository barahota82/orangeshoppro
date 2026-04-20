<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $id = (int) ($_GET['id'] ?? 0);
    $result = orange_gl_pending_movement_preview($pdo, $id);
    json_response([
        'success' => true,
        'meta' => $result['meta'],
        'lines' => $result['lines'],
    ]);
} catch (InvalidArgumentException $e) {
    if (function_exists('error_log')) {
        error_log('[orange] gl pending-preview: ' . $e->getMessage());
    }
    json_response(['success' => false, 'message' => 'الحركة غير موجودة أو غير صالحة'], 404);
} catch (Throwable $e) {
    orange_gl_api_catch_json($e, 'تعذر معاينة الحركة');
}
