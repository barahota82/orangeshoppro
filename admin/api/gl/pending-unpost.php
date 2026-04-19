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
    $data = get_json_input();
    $ids = $data['ids'] ?? [];
    if (!is_array($ids)) {
        json_response(['success' => false, 'message' => 'معرفات غير صالحة'], 422);
    }
    $result = orange_gl_pending_unpost_by_ids($pdo, $ids);
    $unposted = $result['unposted'];
    $errors = $result['errors'];
    $ok = count($unposted) > 0;
    $msg = $ok
        ? ('تم فك ترحيل ' . count($unposted) . ' حركة' . ($errors !== [] ? ' — تنبيهات: ' . implode('؛ ', $errors) : ''))
        : ($errors !== [] ? implode('؛ ', $errors) : 'لم تُحدَّد حركات مرحّلة للفك أو لا توجد صفوف مطابقة');
    if ($ok) {
        audit_log(
            'gl_pending_unpost',
            'فك ترحيل طابور محاسبة: ' . implode(',', array_map('strval', $unposted)),
            'orange_gl_pending_movements',
            0
        );
    }
    $out = [
        'success' => $ok,
        'unposted' => $unposted,
        'errors' => $errors,
        'message' => $msg,
    ];
    if ($errors !== []) {
        $suggest = orange_gl_unpost_errors_suggest_admin_link($errors);
        if ($suggest !== null) {
            $out['suggest_admin'] = $suggest;
        }
    }
    json_response($out);
} catch (Throwable $e) {
    orange_gl_api_catch_json($e, 'تعذر فك ترحيل الحركات');
}
