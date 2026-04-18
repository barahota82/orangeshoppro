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
    $nameEn = trim((string) ($data['name_en'] ?? ''));
    $isSuspended = !empty($data['is_suspended']) ? 1 : 0;
    $nbRaw = strtolower(trim((string) ($data['normal_balance'] ?? 'debit')));
    $normalBalance = $nbRaw === 'credit' ? 'credit' : 'debit';

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
    if ($parentId !== null && orange_accounts_node_depth($pdo, $parentId) >= orange_accounts_max_tree_depth()) {
        json_response(['success' => false, 'message' => 'لا يمكن إضافة فرع تحت المستوى الخامس — أقصى عمق للدليل خمسة مستويات'], 422);
    }

    $lock = orange_accounts_lock_name($parentId);
    $lk = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 20)')->fetchColumn();
    if ((int) $lk !== 1) {
        json_response(['success' => false, 'message' => 'تعذر قفل الشجرة — أعد المحاولة'], 423);
    }
    try {
        $hasClass = orange_table_has_column($pdo, 'accounts', 'account_class');
        $hasPar = orange_table_has_column($pdo, 'accounts', 'parent_id');
        $hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');
        $hasNameEn = orange_table_has_column($pdo, 'accounts', 'name_en');
        $hasSuspended = orange_table_has_column($pdo, 'accounts', 'is_suspended');
        $hasNb = orange_table_has_column($pdo, 'accounts', 'normal_balance');

        if ($id <= 0) {
            $code = orange_accounts_suggest_child_code($pdo, $parentId);
            $dup = $pdo->prepare('SELECT id FROM accounts WHERE code = ? LIMIT 1');
            $dup->execute([$code]);
            if ($dup->fetch()) {
                json_response(['success' => false, 'message' => 'تعذر توليد كود فريد — أعد المحاولة'], 409);
            }
            $accountClass = 'unclassified';
            $cols = ['name', 'code'];
            $vals = [$name, $code];
            if ($hasClass) {
                $cols[] = 'account_class';
                $vals[] = $accountClass;
            }
            if ($hasPar) {
                $cols[] = 'parent_id';
                $vals[] = $parentId;
            }
            if ($hasGrp) {
                $cols[] = 'is_group';
                $vals[] = $isGroup;
            }
            if ($hasNameEn) {
                $cols[] = 'name_en';
                $vals[] = $nameEn;
            }
            if ($hasSuspended) {
                $cols[] = 'is_suspended';
                $vals[] = $isSuspended;
            }
            if ($hasNb) {
                $cols[] = 'normal_balance';
                $vals[] = $normalBalance;
            }
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare('INSERT INTO accounts (' . implode(',', $cols) . ') VALUES (' . $ph . ')')->execute($vals);
            $newId = (int) $pdo->lastInsertId();
            audit_log('account_create', 'حساب جديد: ' . $code . ' ' . $name, 'accounts', $newId);
            json_response(['success' => true, 'message' => 'تم إنشاء الحساب', 'id' => $newId, 'code' => $code]);
        }

        $exSt = $pdo->prepare('SELECT code FROM accounts WHERE id = ? LIMIT 1');
        $exSt->execute([$id]);
        $exRow = $exSt->fetch(PDO::FETCH_ASSOC);
        if (!$exRow) {
            json_response(['success' => false, 'message' => 'الحساب غير موجود'], 404);
        }
        $code = trim((string) ($exRow['code'] ?? ''));
        if ($code === '') {
            $code = orange_accounts_suggest_child_code($pdo, $parentId);
        }

        $dup = $pdo->prepare('SELECT id FROM accounts WHERE code = ? AND id <> ? LIMIT 1');
        $dup->execute([$code, $id]);
        if ($dup->fetch()) {
            json_response(['success' => false, 'message' => 'الكود مستخدم لحساب آخر'], 409);
        }

        $sets = ['name = ?', 'code = ?'];
        $vals = [$name, $code];
        if ($hasPar) {
            $sets[] = 'parent_id = ?';
            $vals[] = $parentId;
        }
        if ($hasGrp) {
            $sets[] = 'is_group = ?';
            $vals[] = $isGroup;
        }
        if ($hasNameEn) {
            $sets[] = 'name_en = ?';
            $vals[] = $nameEn;
        }
        if ($hasSuspended) {
            $sets[] = 'is_suspended = ?';
            $vals[] = $isSuspended;
        }
        if ($hasNb) {
            $sets[] = 'normal_balance = ?';
            $vals[] = $normalBalance;
        }
        $vals[] = $id;
        $pdo->prepare('UPDATE accounts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        audit_log('account_update', 'تحديث حساب #' . $id, 'accounts', $id);
        json_response(['success' => true, 'message' => 'تم حفظ الحساب', 'id' => $id, 'code' => $code]);
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
    }
} catch (Throwable $e) {
    api_error($e, 'تعذر حفظ الحساب');
}
