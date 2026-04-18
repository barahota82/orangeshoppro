<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/account_tree.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $parentRaw = $data['parent_id'] ?? null;
    $parentId = ($parentRaw === null || $parentRaw === '') ? null : (int) $parentRaw;
    if ($parentId !== null && $parentId <= 0) {
        $parentId = null;
    }
    if ($parentId !== null) {
        $chk = $pdo->prepare('SELECT id FROM accounts WHERE id = ? LIMIT 1');
        $chk->execute([$parentId]);
        if (!$chk->fetch()) {
            json_response(['success' => false, 'message' => 'الحساب الأب غير موجود'], 404);
        }
    }
    $lock = orange_accounts_lock_name($parentId);
    $lk = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 15)')->fetchColumn();
    if ((int) $lk !== 1) {
        json_response(['success' => false, 'message' => 'الخادم مشغول بقفل شجرة الحسابات — أعد المحاولة'], 423);
    }
    try {
        $code = orange_accounts_suggest_child_code($pdo, $parentId);
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
    }
    json_response(['success' => true, 'suggested_code' => $code, 'parent_id' => $parentId]);
} catch (Throwable $e) {
    api_error($e, 'تعذر اقتراح الكود');
}
