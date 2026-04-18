<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/admin_permissions.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $stmt = $pdo->query(
        'SELECT id, username, display_name, is_active, is_superuser, created_at FROM admins ORDER BY id ASC'
    );
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $perms = [];
    if (orange_table_exists($pdo, 'admin_permissions')) {
        $p = $pdo->query('SELECT admin_id, resource_key, can_view, can_edit, can_delete FROM admin_permissions');
        while ($row = $p->fetch(PDO::FETCH_ASSOC)) {
            $aid = (int) $row['admin_id'];
            if (!isset($perms[$aid])) {
                $perms[$aid] = [];
            }
            $perms[$aid][$row['resource_key']] = [
                'can_view' => (int) $row['can_view'] === 1,
                'can_edit' => (int) $row['can_edit'] === 1,
                'can_delete' => (int) $row['can_delete'] === 1,
            ];
        }
    }
    json_response([
        'success' => true,
        'admins' => $admins,
        'permissions_by_admin' => $perms,
        'resource_labels' => orange_admin_resource_labels(),
    ]);
} catch (Throwable $e) {
    api_error($e, 'تعذر جلب المستخدمين');
}
