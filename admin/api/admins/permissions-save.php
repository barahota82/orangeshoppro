<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/admin_permissions.php';
require_once __DIR__ . '/../../../includes/edit_lock_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    orange_catalog_ensure_edit_lock_schema($pdo);
    if (!orange_table_exists($pdo, 'admin_permissions')) {
        json_response(['success' => false, 'message' => 'جدول الصلاحيات غير جاهز'], 500);
    }
    $data = get_json_input();
    $adminId = (int) ($data['admin_id'] ?? 0);
    $matrix = $data['permissions'] ?? null;
    if ($adminId <= 0 || !is_array($matrix)) {
        json_response(['success' => false, 'message' => 'بيانات غير صالحة'], 422);
    }
    $chk = $pdo->prepare('SELECT id, is_superuser FROM admins WHERE id = ? LIMIT 1');
    $chk->execute([$adminId]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(['success' => false, 'message' => 'المستخدم غير موجود'], 404);
    }
    if (!empty($row['is_superuser'])) {
        json_response(['success' => false, 'message' => 'المشرف العام يملك كل الصلاحيات — لا حاجة لمصفوفة'], 422);
    }
    $labels = orange_admin_resource_labels();
    $hasLockCols = orange_table_has_column($pdo, 'admin_permissions', 'can_lock');
    $pdo->prepare('DELETE FROM admin_permissions WHERE admin_id = ?')->execute([$adminId]);
    if ($hasLockCols) {
        $ins = $pdo->prepare(
            'INSERT INTO admin_permissions (admin_id, resource_key, can_view, can_edit, can_delete, can_lock, can_unlock)
             VALUES (?,?,?,?,?,?,?)'
        );
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO admin_permissions (admin_id, resource_key, can_view, can_edit, can_delete) VALUES (?,?,?,?,?)'
        );
    }
    foreach ($matrix as $resourceKey => $flags) {
        if (!is_array($flags) || !isset($labels[$resourceKey])) {
            continue;
        }
        $v = !empty($flags['can_view']) ? 1 : 0;
        $e = !empty($flags['can_edit']) ? 1 : 0;
        $d = !empty($flags['can_delete']) ? 1 : 0;
        $l = !empty($flags['can_lock']) ? 1 : 0;
        $u = !empty($flags['can_unlock']) ? 1 : 0;
        if ($v === 0 && $e === 0 && $d === 0 && $l === 0 && $u === 0) {
            continue;
        }
        if ($e === 1 && $v === 0) {
            $v = 1;
        }
        if ($d === 1) {
            $v = 1;
            $e = 1;
        }
        if ($l === 1 || $u === 1) {
            $v = 1;
        }
        if ($hasLockCols) {
            $ins->execute([$adminId, $resourceKey, $v, $e, $d, $l, $u]);
        } else {
            $ins->execute([$adminId, $resourceKey, $v, $e, $d]);
        }
    }
    audit_log('admin_permissions', 'تحديث صلاحيات مستخدم #' . $adminId, 'admins', $adminId);
    json_response(['success' => true, 'message' => 'تم حفظ الصلاحيات']);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ الصلاحيات');
}
