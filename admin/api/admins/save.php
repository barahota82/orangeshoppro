<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/admin_permissions.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $id = (int) ($data['id'] ?? 0);
    $username = trim((string) ($data['username'] ?? ''));
    $displayName = trim((string) ($data['display_name'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $isActive = array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1;
    $isSuper = !empty($data['is_superuser']) ? 1 : 0;

    if ($username === '') {
        json_response(['success' => false, 'message' => 'اسم المستخدم مطلوب'], 422);
    }

    if ($id <= 0) {
        if ($password === '') {
            json_response(['success' => false, 'message' => 'كلمة المرور مطلوبة للمستخدم الجديد'], 422);
        }
        $chk = $pdo->prepare('SELECT id FROM admins WHERE username = ? LIMIT 1');
        $chk->execute([$username]);
        if ($chk->fetch()) {
            json_response(['success' => false, 'message' => 'اسم المستخدم مستخدم'], 409);
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $hasSuper = orange_table_has_column($pdo, 'admins', 'is_superuser');
        if ($hasSuper) {
            $pdo->prepare(
                'INSERT INTO admins (username, password_hash, display_name, is_active, is_superuser) VALUES (?,?,?,?,?)'
            )->execute([$username, $hash, $displayName !== '' ? $displayName : $username, $isActive, $isSuper]);
        } else {
            $pdo->prepare(
                'INSERT INTO admins (username, password_hash, display_name, is_active) VALUES (?,?,?,?)'
            )->execute([$username, $hash, $displayName !== '' ? $displayName : $username, $isActive]);
        }
        $newId = (int) $pdo->lastInsertId();
        audit_log('admin_create', 'مستخدم إدارة جديد: ' . $username, 'admins', $newId);
        json_response(['success' => true, 'message' => 'تم إنشاء المستخدم', 'id' => $newId]);
    }

    $chk = $pdo->prepare('SELECT id FROM admins WHERE username = ? AND id <> ? LIMIT 1');
    $chk->execute([$username, $id]);
    if ($chk->fetch()) {
        json_response(['success' => false, 'message' => 'اسم المستخدم مستخدم'], 409);
    }
    if ($password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (orange_table_has_column($pdo, 'admins', 'is_superuser')) {
            $pdo->prepare(
                'UPDATE admins SET username = ?, password_hash = ?, display_name = ?, is_active = ?, is_superuser = ? WHERE id = ?'
            )->execute([$username, $hash, $displayName !== '' ? $displayName : $username, $isActive, $isSuper, $id]);
        } else {
            $pdo->prepare(
                'UPDATE admins SET username = ?, password_hash = ?, display_name = ?, is_active = ? WHERE id = ?'
            )->execute([$username, $hash, $displayName !== '' ? $displayName : $username, $isActive, $id]);
        }
    } else {
        if (orange_table_has_column($pdo, 'admins', 'is_superuser')) {
            $pdo->prepare(
                'UPDATE admins SET username = ?, display_name = ?, is_active = ?, is_superuser = ? WHERE id = ?'
            )->execute([$username, $displayName !== '' ? $displayName : $username, $isActive, $isSuper, $id]);
        } else {
            $pdo->prepare(
                'UPDATE admins SET username = ?, display_name = ?, is_active = ? WHERE id = ?'
            )->execute([$username, $displayName !== '' ? $displayName : $username, $isActive, $id]);
        }
    }
    audit_log('admin_update', 'تحديث مستخدم إدارة #' . $id, 'admins', $id);
    json_response(['success' => true, 'message' => 'تم حفظ المستخدم']);
} catch (Throwable $e) {
    api_error($e, 'تعذر حفظ المستخدم');
}
