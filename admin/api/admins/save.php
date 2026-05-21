<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/admin_permissions.php';
require_once __DIR__ . '/../../../includes/countries.php';
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
    $hasAdminCountry = orange_table_has_column($pdo, 'admins', 'country_id');
    $countryIdSql = null;
    if ($hasAdminCountry) {
        if ($isSuper === 1) {
            $countryIdSql = null;
        } else {
            $cidRaw = (int) ($data['country_id'] ?? 0);
            $countryIdSql = $cidRaw > 0 ? $cidRaw : null;
            if ($countryIdSql !== null) {
                $cRow = orange_country_row_by_id($pdo, $countryIdSql, false);
                if ($cRow === null) {
                    json_response(['success' => false, 'message' => 'الدولة المختارة للمستخدم غير موجودة'], 422);
                }
            }
        }
    }

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
        if ($hasSuper && $hasAdminCountry) {
            $pdo->prepare(
                'INSERT INTO admins (username, password_hash, display_name, is_active, is_superuser, country_id) VALUES (?,?,?,?,?,?)'
            )->execute([$username, $hash, $displayName !== '' ? $displayName : $username, $isActive, $isSuper, $countryIdSql]);
        } elseif ($hasSuper) {
            $pdo->prepare(
                'INSERT INTO admins (username, password_hash, display_name, is_active, is_superuser) VALUES (?,?,?,?,?)'
            )->execute([$username, $hash, $displayName !== '' ? $displayName : $username, $isActive, $isSuper]);
        } elseif ($hasAdminCountry) {
            $pdo->prepare(
                'INSERT INTO admins (username, password_hash, display_name, is_active, country_id) VALUES (?,?,?,?,?)'
            )->execute([$username, $hash, $displayName !== '' ? $displayName : $username, $isActive, $countryIdSql]);
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
        if (orange_table_has_column($pdo, 'admins', 'is_superuser') && $hasAdminCountry) {
            $pdo->prepare(
                'UPDATE admins SET username = ?, password_hash = ?, display_name = ?, is_active = ?, is_superuser = ?, country_id = ? WHERE id = ?'
            )->execute([$username, $hash, $displayName !== '' ? $displayName : $username, $isActive, $isSuper, $countryIdSql, $id]);
        } elseif (orange_table_has_column($pdo, 'admins', 'is_superuser')) {
            $pdo->prepare(
                'UPDATE admins SET username = ?, password_hash = ?, display_name = ?, is_active = ?, is_superuser = ? WHERE id = ?'
            )->execute([$username, $hash, $displayName !== '' ? $displayName : $username, $isActive, $isSuper, $id]);
        } elseif ($hasAdminCountry) {
            $pdo->prepare(
                'UPDATE admins SET username = ?, password_hash = ?, display_name = ?, is_active = ?, country_id = ? WHERE id = ?'
            )->execute([$username, $hash, $displayName !== '' ? $displayName : $username, $isActive, $countryIdSql, $id]);
        } else {
            $pdo->prepare(
                'UPDATE admins SET username = ?, password_hash = ?, display_name = ?, is_active = ? WHERE id = ?'
            )->execute([$username, $hash, $displayName !== '' ? $displayName : $username, $isActive, $id]);
        }
    } else {
        if (orange_table_has_column($pdo, 'admins', 'is_superuser') && $hasAdminCountry) {
            $pdo->prepare(
                'UPDATE admins SET username = ?, display_name = ?, is_active = ?, is_superuser = ?, country_id = ? WHERE id = ?'
            )->execute([$username, $displayName !== '' ? $displayName : $username, $isActive, $isSuper, $countryIdSql, $id]);
        } elseif (orange_table_has_column($pdo, 'admins', 'is_superuser')) {
            $pdo->prepare(
                'UPDATE admins SET username = ?, display_name = ?, is_active = ?, is_superuser = ? WHERE id = ?'
            )->execute([$username, $displayName !== '' ? $displayName : $username, $isActive, $isSuper, $id]);
        } elseif ($hasAdminCountry) {
            $pdo->prepare(
                'UPDATE admins SET username = ?, display_name = ?, is_active = ?, country_id = ? WHERE id = ?'
            )->execute([$username, $displayName !== '' ? $displayName : $username, $isActive, $countryIdSql, $id]);
        } else {
            $pdo->prepare(
                'UPDATE admins SET username = ?, display_name = ?, is_active = ? WHERE id = ?'
            )->execute([$username, $displayName !== '' ? $displayName : $username, $isActive, $id]);
        }
    }
    audit_log('admin_update', 'تحديث مستخدم إدارة #' . $id, 'admins', $id);
    json_response(['success' => true, 'message' => 'تم حفظ المستخدم']);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ المستخدم');
}
