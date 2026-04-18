<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/account_tree.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'accounts')) {
        json_response(['success' => false, 'message' => 'جدول الحسابات غير متوفر'], 500);
    }
    $data = get_json_input();
    $id = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['name'] ?? ''));
    $parentRaw = $data['parent_id'] ?? null;
    $parentId = ($parentRaw === null || $parentRaw === '') ? null : (int) $parentRaw;
    if ($parentId !== null && $parentId <= 0) {
        $parentId = null;
    }
    $isGroup = !empty($data['is_group']) ? 1 : 0;
    $accountClass = trim((string) ($data['account_class'] ?? 'unclassified'));
    $code = trim((string) ($data['code'] ?? ''));
    if ($name === '') {
        json_response(['success' => false, 'message' => 'اسم الحساب مطلوب'], 422);
    }
    if ($parentId !== null) {
        $chk = $pdo->prepare('SELECT id FROM accounts WHERE id = ? LIMIT 1');
        $chk->execute([$parentId]);
        if (!$chk->fetch()) {
            json_response(['success' => false, 'message' => 'الحساب الأب غير موجود'], 404);
        }
    }
    if ($parentId !== null && $id > 0 && ($parentId === $id || orange_accounts_is_descendant($pdo, $id, $parentId))) {
        json_response(['success' => false, 'message' => 'لا يمكن جعل الحساب تحت نفسه أو تحت أحد فروعه'], 422);
    }

    $lock = orange_accounts_lock_name($parentId);
    $lk = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 20)')->fetchColumn();
    if ((int) $lk !== 1) {
        json_response(['success' => false, 'message' => 'تعذر قفل الشجرة — أعد المحاولة'], 423);
    }
    try {
        if ($code === '') {
            $code = orange_accounts_suggest_child_code($pdo, $parentId);
        }
        $hasClass = orange_table_has_column($pdo, 'accounts', 'account_class');
        $hasPar = orange_table_has_column($pdo, 'accounts', 'parent_id');
        $hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');

        if ($id <= 0) {
            $dup = $pdo->prepare('SELECT id FROM accounts WHERE code = ? LIMIT 1');
            $dup->execute([$code]);
            if ($dup->fetch()) {
                json_response(['success' => false, 'message' => 'الكود مستخدم — اضغط «اقتراح كود» ثم أعد المحاولة'], 409);
            }
            if ($hasPar && $hasGrp && $hasClass) {
                $pdo->prepare(
                    'INSERT INTO accounts (name, code, account_class, parent_id, is_group) VALUES (?,?,?,?,?)'
                )->execute([$name, $code, $accountClass, $parentId, $isGroup]);
            } elseif ($hasPar && $hasGrp) {
                $pdo->prepare(
                    'INSERT INTO accounts (name, code, parent_id, is_group) VALUES (?,?,?,?)'
                )->execute([$name, $code, $parentId, $isGroup]);
            } else {
                $pdo->prepare('INSERT INTO accounts (name, code) VALUES (?,?)')->execute([$name, $code]);
            }
            $newId = (int) $pdo->lastInsertId();
            audit_log('account_create', 'حساب جديد: ' . $code . ' ' . $name, 'accounts', $newId);
            json_response(['success' => true, 'message' => 'تم إنشاء الحساب', 'id' => $newId, 'code' => $code]);
        }

        $dup = $pdo->prepare('SELECT id FROM accounts WHERE code = ? AND id <> ? LIMIT 1');
        $dup->execute([$code, $id]);
        if ($dup->fetch()) {
            json_response(['success' => false, 'message' => 'الكود مستخدم لحساب آخر'], 409);
        }
        if ($hasPar && $hasGrp && $hasClass) {
            $pdo->prepare(
                'UPDATE accounts SET name = ?, code = ?, account_class = ?, parent_id = ?, is_group = ? WHERE id = ?'
            )->execute([$name, $code, $accountClass, $parentId, $isGroup, $id]);
        } elseif ($hasPar && $hasGrp) {
            $pdo->prepare(
                'UPDATE accounts SET name = ?, code = ?, parent_id = ?, is_group = ? WHERE id = ?'
            )->execute([$name, $code, $parentId, $isGroup, $id]);
        } else {
            $pdo->prepare('UPDATE accounts SET name = ?, code = ? WHERE id = ?')->execute([$name, $code, $id]);
        }
        audit_log('account_update', 'تحديث حساب #' . $id, 'accounts', $id);
        json_response(['success' => true, 'message' => 'تم حفظ الحساب', 'id' => $id, 'code' => $code]);
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
    }
} catch (Throwable $e) {
    api_error($e, 'تعذر حفظ الحساب');
}
