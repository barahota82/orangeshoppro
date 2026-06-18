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
    $pageLabels = orange_admin_permission_page_labels();
    $hasLockCols = orange_table_has_column($pdo, 'admin_permissions', 'can_lock');
    $hasUnlockCols = orange_table_has_column($pdo, 'admin_permissions', 'can_unlock');
    $hasPrintCols = orange_table_has_column($pdo, 'admin_permissions', 'can_print');
    $hasExportCols = orange_table_has_column($pdo, 'admin_permissions', 'can_export');
    $pdo->prepare('DELETE FROM admin_permissions WHERE admin_id = ?')->execute([$adminId]);
    $fields = ['admin_id', 'resource_key', 'can_view', 'can_edit', 'can_delete'];
    if ($hasLockCols) {
        $fields[] = 'can_lock';
    }
    if ($hasUnlockCols) {
        $fields[] = 'can_unlock';
    }
    if ($hasPrintCols) {
        $fields[] = 'can_print';
    }
    if ($hasExportCols) {
        $fields[] = 'can_export';
    }
    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $ins = $pdo->prepare(
        'INSERT INTO admin_permissions (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')'
    );
    foreach ($matrix as $resourceKey => $flags) {
        if (!is_array($flags)) {
            continue;
        }
        $page = orange_admin_page_from_perm_key((string) $resourceKey);
        if ($page === null) {
            $page = (string) $resourceKey;
        }
        if (!isset($pageLabels[$page]) || $page === 'admin_users' || $page === 'countries') {
            continue;
        }
        $storageKey = orange_admin_perm_storage_key($page);
        $v = !empty($flags['can_view']) ? 1 : 0;
        $e = !empty($flags['can_edit']) ? 1 : 0;
        $d = !empty($flags['can_delete']) ? 1 : 0;
        $l = !empty($flags['can_lock']) ? 1 : 0;
        $u = !empty($flags['can_unlock']) ? 1 : 0;
        $p = !empty($flags['can_print']) ? 1 : 0;
        $x = !empty($flags['can_export']) ? 1 : 0;
        if ($v === 0 && $e === 0 && $d === 0 && $l === 0 && $u === 0 && $p === 0 && $x === 0) {
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
        if ($p === 1 || $x === 1) {
            $v = 1;
        }
        $params = [$adminId, $storageKey, $v, $e, $d];
        if ($hasLockCols) {
            $params[] = $l;
        }
        if ($hasUnlockCols) {
            $params[] = $u;
        }
        if ($hasPrintCols) {
            $params[] = $p;
        }
        if ($hasExportCols) {
            $params[] = $x;
        }
        $ins->execute($params);
    }
    audit_log('admin_permissions', 'تحديث صلاحيات مستخدم #' . $adminId, 'admins', $adminId);
    json_response(['success' => true, 'message' => 'تم حفظ الصلاحيات']);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ الصلاحيات');
}
