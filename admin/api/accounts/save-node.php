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

    $inheritMap = [];
    if ($parentId !== null && $parentId > 0) {
        $inheritMap = orange_accounts_inherit_mapping_from_parent($pdo, $parentId);
    } else {
        $inheritMap = orange_accounts_default_mapping_for_tree_context($pdo, 0);
    }
    $sanMap = orange_accounts_sanitize_saved_mapping($pdo, $data);
    $finalMap = orange_accounts_merge_mapping_payload($sanMap, $inheritMap, $isGroup);

    $normalBalance = null;
    if ($isGroup !== 1) {
        $nbUse = $finalMap['normal_balance'] ?? null;
        if ($nbUse === 'credit' || $nbUse === 'debit') {
            $normalBalance = $nbUse;
        } else {
            $nbRaw = strtolower(trim((string) ($data['normal_balance'] ?? 'debit')));
            $normalBalance = $nbRaw === 'credit' ? 'credit' : 'debit';
        }
    }

    $cfStore = $finalMap['cashflow_section'] ?? 'none';
    if ($cfStore === null || $cfStore === '') {
        $cfStore = 'none';
    }

    if ($name === '') {
        json_response(['success' => false, 'message' => 'اسم الحساب مطلوب'], 422);
    }
    if ($id <= 0 && ($parentId === null || $parentId <= 0)) {
        json_response([
            'success' => false,
            'message' => 'لا يُنشأ حساب جذر من هنا. افتح «اضافة الدليل المحاسبي» ثم أضف الجذر، أو اختر حساباً أباً من الشجرة واضغط «إضافة».',
        ], 422);
    }
    if ($parentId !== null) {
        $chk = $pdo->prepare('SELECT id FROM accounts WHERE id = ? LIMIT 1');
        $chk->execute([$parentId]);
        if (! $chk->fetch()) {
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
        $hasPar = orange_table_has_column($pdo, 'accounts', 'parent_id');
        $hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');
        $hasNameEn = orange_table_has_column($pdo, 'accounts', 'name_en');
        $hasSuspended = orange_table_has_column($pdo, 'accounts', 'is_suspended');
        $hasNb = orange_table_has_column($pdo, 'accounts', 'normal_balance');
        $hasAt = orange_table_has_column($pdo, 'accounts', 'account_type');
        $hasRlId = orange_table_has_column($pdo, 'accounts', 'report_line_id');

        if ($id <= 0) {
            $code = orange_accounts_suggest_child_code($pdo, $parentId);
            $dup = $pdo->prepare('SELECT id FROM accounts WHERE code = ? LIMIT 1');
            $dup->execute([$code]);
            if ($dup->fetch()) {
                json_response(['success' => false, 'message' => 'تعذر توليد كود فريد — أعد المحاولة'], 409);
            }
            $cols = ['name', 'code'];
            $vals = [$name, $code];
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
            if ($hasAt) {
                $cols[] = 'account_type';
                $cols[] = 'report_section';
                $cols[] = 'cashflow_section';
                $vals[] = $finalMap['account_type'] ?? null;
                $vals[] = $finalMap['report_section'] ?? null;
                $vals[] = $cfStore;
            }
            if ($hasRlId) {
                $cols[] = 'report_line_id';
                $vals[] = isset($finalMap['report_line_id']) && (int) $finalMap['report_line_id'] > 0 ? (int) $finalMap['report_line_id'] : null;
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
        if (! $exRow) {
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
        if ($hasAt) {
            $sets[] = 'account_type = ?';
            $sets[] = 'report_section = ?';
            $sets[] = 'cashflow_section = ?';
            $vals[] = $finalMap['account_type'] ?? null;
            $vals[] = $finalMap['report_section'] ?? null;
            $vals[] = $cfStore;
        }
        if ($hasRlId) {
            $sets[] = 'report_line_id = ?';
            $vals[] = isset($finalMap['report_line_id']) && (int) $finalMap['report_line_id'] > 0 ? (int) $finalMap['report_line_id'] : null;
        }
        $vals[] = $id;
        $pdo->prepare('UPDATE accounts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        audit_log('account_update', 'تحديث حساب #' . $id, 'accounts', $id);
        json_response(['success' => true, 'message' => 'تم حفظ الحساب', 'id' => $id, 'code' => $code]);
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
    }
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ الحساب');
}
