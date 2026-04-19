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
    $result = orange_gl_pending_post_by_ids($pdo, $ids);
    $posted = $result['posted'];
    $errors = $result['errors'];
    $ok = count($posted) > 0;
    $msg = $ok
        ? ('تم ترحيل ' . count($posted) . ' حركة' . ($errors !== [] ? ' — مع تنبيهات: ' . implode('؛ ', $errors) : ''))
        : ($errors !== [] ? implode('؛ ', $errors) : 'لم يُحدد أي حركة للترحيل');
    $out = [
        'success' => $ok,
        'posted' => $posted,
        'errors' => $errors,
        'message' => $msg,
    ];
    if ($errors !== []) {
        $suggest = orange_gl_post_errors_suggest_admin_link($errors);
        if ($suggest !== null) {
            $out['suggest_admin'] = $suggest;
        }
    }
    json_response($out);
} catch (Throwable $e) {
    api_error($e, 'تعذر ترحيل الحركات');
}
