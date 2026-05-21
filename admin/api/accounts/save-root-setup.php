<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/account_tree.php';
require_once __DIR__ . '/../../../includes/countries.php';
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

    if ($name === '') {
        json_response(['success' => false, 'message' => 'الاسم بالعربية مطلوب'], 422);
    }

    $lock = orange_accounts_lock_name(null);
    $lk = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 20)')->fetchColumn();
    if ((int) $lk !== 1) {
        json_response(['success' => false, 'message' => 'تعذر قفل الشجرة — أعد المحاولة'], 423);
    }
    try {
        $hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');
        $hasNameEn = orange_table_has_column($pdo, 'accounts', 'name_en');
        $hasSuspended = orange_table_has_column($pdo, 'accounts', 'is_suspended');
        $hasNb = orange_table_has_column($pdo, 'accounts', 'normal_balance');
        $hasCountryId = orange_table_has_column($pdo, 'accounts', 'country_id');
        $writeCountryId = orange_accounts_resolve_country_id_for_write($pdo, null);

        if ($id <= 0) {
            // كود الجذر الجديد دائماً من النظام: أكبر كود رقمي للجذور + 1 (لا إدخال يدوي).
            $code = orange_accounts_suggest_child_code($pdo, null);
            if ($hasCountryId && $writeCountryId > 0) {
                $dup = $pdo->prepare('SELECT id FROM accounts WHERE code = ? AND country_id = ? LIMIT 1');
                $dup->execute([$code, $writeCountryId]);
            } else {
                $dup = $pdo->prepare('SELECT id FROM accounts WHERE code = ? LIMIT 1');
                $dup->execute([$code]);
            }
            if ($dup->fetch()) {
                json_response(['success' => false, 'message' => 'الكود مستخدم لحساب آخر'], 409);
            }
            $cols = ['name', 'code'];
            $vals = [$name, $code];
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
                $vals[] = null;
            }
            if ($hasCountryId && $writeCountryId > 0) {
                $cols[] = 'country_id';
                $vals[] = $writeCountryId;
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
        try {
            orange_admin_assert_entity_country($pdo, 'accounts', $id);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }
        $pid = isset($ex['parent_id']) ? (int) $ex['parent_id'] : 0;
        if ($pid > 0) {
            json_response(['success' => false, 'message' => 'هذا ليس حساباً جذرياً'], 422);
        }

        $oldCode = trim((string) ($ex['code'] ?? ''));
        // الكود لا يُعدَّل من شاشة الإعداد: يبقى كما في القاعدة، أو يُولَّد لو كان فارغاً.
        $code = $oldCode !== '' ? $oldCode : orange_accounts_suggest_child_code($pdo, null);

        $dupCountryId = orange_account_country_id($pdo, $id);
        if ($dupCountryId <= 0) {
            $dupCountryId = $writeCountryId;
        }
        if ($hasCountryId && $dupCountryId > 0) {
            $dup = $pdo->prepare('SELECT id FROM accounts WHERE code = ? AND country_id = ? AND id <> ? LIMIT 1');
            $dup->execute([$code, $dupCountryId, $id]);
        } else {
            $dup = $pdo->prepare('SELECT id FROM accounts WHERE code = ? AND id <> ? LIMIT 1');
            $dup->execute([$code, $id]);
        }
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
    orange_admin_api_catch($e, 'تعذر حفظ جذر الدليل');
}
