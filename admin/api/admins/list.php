<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/admin_permissions.php';
require_once __DIR__ . '/../../../includes/edit_lock_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    orange_catalog_ensure_edit_lock_schema($pdo);
    $cols = 'id, username, display_name, is_active, is_superuser, created_at';
    if (orange_table_has_column($pdo, 'admins', 'country_id')) {
        $cols .= ', country_id';
    }
    $stmt = $pdo->query('SELECT ' . $cols . ' FROM admins ORDER BY id ASC');
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $countryLabels = [];
    if (orange_table_exists($pdo, 'countries')) {
        foreach (orange_countries_admin_list($pdo) as $cRow) {
            $countryLabels[(int) ($cRow['id'] ?? 0)] = trim((string) ($cRow['name_ar'] ?? ''));
        }
    }
    foreach ($admins as &$aRow) {
        $cid = isset($aRow['country_id']) ? (int) $aRow['country_id'] : 0;
        $aRow['country_label'] = $cid > 0 ? ($countryLabels[$cid] ?? ('#' . $cid)) : '';
    }
    unset($aRow);
    $perms = [];
    if (orange_table_exists($pdo, 'admin_permissions')) {
        $lockSel = orange_table_has_column($pdo, 'admin_permissions', 'can_lock')
            ? ', can_lock, can_unlock' : '';
        $p = $pdo->query('SELECT admin_id, resource_key, can_view, can_edit, can_delete' . $lockSel . ' FROM admin_permissions');
        while ($row = $p->fetch(PDO::FETCH_ASSOC)) {
            $aid = (int) $row['admin_id'];
            if (!isset($perms[$aid])) {
                $perms[$aid] = [];
            }
            $perms[$aid][$row['resource_key']] = [
                'can_view' => (int) $row['can_view'] === 1,
                'can_edit' => (int) $row['can_edit'] === 1,
                'can_delete' => (int) $row['can_delete'] === 1,
                'can_lock' => isset($row['can_lock']) && (int) $row['can_lock'] === 1,
                'can_unlock' => isset($row['can_unlock']) && (int) $row['can_unlock'] === 1,
            ];
        }
    }
    json_response([
        'success' => true,
        'admins' => $admins,
        'permissions_by_admin' => $perms,
        'permission_tree' => orange_admin_permission_mega_sections(),
        'page_labels' => orange_admin_permission_page_labels(),
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر جلب المستخدمين');
}
