<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/account_tree.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'accounts') || !orange_table_has_column($pdo, 'accounts', 'parent_id')) {
        json_response(['success' => false, 'message' => 'جدول الحسابات غير مكتمل'], 500);
    }

    $data = get_json_input();
    $id = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['name'] ?? ''));
    $nameEn = trim((string) ($data['name_en'] ?? ''));
    $codeIn = trim((string) ($data['code'] ?? ''));

    if ($name === '') {
        json_response(['success' => false, 'message' => 'الاسم بالعربية مطلوب'], 422);
    }

    $lock = orange_accounts_lock_name(null);
    $lk = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 20)')->fetchColumn();
    if ((int) $lk !== 1) {
        json_response(['success' => false, 'message' => 'تعذر قفل الشجرة — أعد المحاولة'], 423);
    }
    try {
        $hasClass = orange_table_has_column($pdo, 'accounts', 'account_class');
        $hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');
        $hasNameEn = orange_table_has_column($pdo, 'accounts', 'name_en');
        $hasSuspended = orange_table_has_column($pdo, 'accounts', 'is_suspended');
        $hasNb = orange_table_has_column($pdo, 'accounts', 'normal_balance');

        if ($id <= 0) {
            $code = $codeIn !== '' ? $codeIn : orange_accounts_suggest_child_code($pdo, null);
            $dup = $pdo->prepare('SELECT id FROM accounts WHERE code = ? LIMIT 1');
            $dup->execute([$code]);
            if ($dup->fetch()) {
                json_response(['success' => false, 'message' => 'الكود مستخدم لحساب آخر'], 409);
            }
            $cols = ['name', 'code'];
            $vals = [$name, $code];
            if ($hasClass) {
                $cols[] = 'account_class';
                $vals[] = 'unclassified';
            }
            $cols[] = 'parent_id';
            $vals[] = null;
            if ($hasGrp) {
                $cols[] = 'is_group';
                $vals[] = 1;
            }
            if ($hasNameEn) {
                $cols[] = 'name_en';
                $vals[] = $nameEn;
            }
            if ($hasSuspended) {
                $cols[] = 'is_suspended';
                $vals[] = 0;
            }
            if ($hasNb) {
                $cols[] = 'normal_balance';
                $vals[] = 'debit';
            }
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare('INSERT INTO accounts (' . implode(',', $cols) . ') VALUES (' . $ph . ')')->execute($vals);
            $newId = (int) $pdo->lastInsertId();
            audit_log('account_create', 'جذر دليل (إعداد): ' . $code . ' ' . $name, 'accounts', $newId);
            json_response(['success' => true, 'message' => 'تم إضافة الحساب الجذر', 'id' => $newId, 'code' => $code]);
        }

        $exSt = $pdo->prepare('SELECT id, code, parent_id FROM accounts WHERE id = ? LIMIT 1');
        $exSt->execute([$id]);
        $ex = $exSt->fetch(PDO::FETCH_ASSOC);
        if (!$ex) {
            json_response(['success' => false, 'message' => 'الحساب غير موجود'], 404);
        }
        $pid = isset($ex['parent_id']) ? (int) $ex['parent_id'] : 0;
        if ($pid > 0) {
            json_response(['success' => false, 'message' => 'هذا ليس حساباً جذرياً'], 422);
        }

        $oldCode = trim((string) ($ex['code'] ?? ''));
        $code = $codeIn !== '' ? $codeIn : $oldCode;
        if ($code === '') {
            $code = orange_accounts_suggest_child_code($pdo, null);
        }

        if ($code !== $oldCode) {
            $ch = $pdo->prepare('SELECT COUNT(*) FROM accounts WHERE parent_id = ?');
            $ch->execute([$id]);
            if ((int) $ch->fetchColumn() > 0) {
                json_response([
                    'success' => false,
                    'message' => 'لا يمكن تغيير كود حساب له فروع. احذف أو انقل الفروع أولاً.',
                ], 422);
            }
        }

        $dup = $pdo->prepare('SELECT id FROM accounts WHERE code = ? AND id <> ? LIMIT 1');
        $dup->execute([$code, $id]);
        if ($dup->fetch()) {
            json_response(['success' => false, 'message' => 'الكود مستخدم لحساب آخر'], 409);
        }

        $sets = ['name = ?', 'code = ?'];
        $vals = [$name, $code];
        if ($hasNameEn) {
            $sets[] = 'name_en = ?';
            $vals[] = $nameEn;
        }
        $vals[] = $id;
        $pdo->prepare('UPDATE accounts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        audit_log('account_update', 'تحديث جذر دليل (إعداد) #' . $id, 'accounts', $id);
        json_response(['success' => true, 'message' => 'تم حفظ الحساب الجذر', 'id' => $id, 'code' => $code]);
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
    }
} catch (Throwable $e) {
    api_error($e, 'تعذر حفظ جذر الدليل');
}
