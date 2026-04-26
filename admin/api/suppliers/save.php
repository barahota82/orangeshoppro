<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/phone_validation.php';
require_once __DIR__ . '/../../../includes/account_tree.php';
require_admin_api();

function orange_supplier_normalize_code(PDO $pdo, $raw): ?string
{
    if (!orange_table_has_column($pdo, 'suppliers', 'code')) {
        return null;
    }
    $s = trim((string) $raw);
    if ($s === '') {
        return null;
    }

    return function_exists('mb_substr') ? mb_substr($s, 0, 32, 'UTF-8') : substr($s, 0, 32);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'suppliers')) {
        json_response(['success' => false, 'message' => 'جدول الموردين غير متوفر'], 500);
    }
    $data = get_json_input();
    $idIn = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        json_response(['success' => false, 'message' => 'اسم المورد مطلوب'], 422);
    }
    $phoneRaw = trim((string) ($data['phone'] ?? ''));
    $phoneSql = null;
    if ($phoneRaw !== '') {
        $phoneNorm = orange_normalize_customer_phone($phoneRaw, null);
        if ($phoneNorm === null) {
            json_response([
                'success' => false,
                'message' => 'رقم الهاتف غير صالح. استخدم + أو 00 مع كود الدولة، أو أدخل رقماً وطنياً صالحاً.',
            ], 422);
        }
        $phoneSql = $phoneNorm;
    }
    $notesRaw = trim((string) ($data['notes'] ?? ''));
    $notesSql = $notesRaw === '' ? null : (function_exists('mb_substr') ? mb_substr($notesRaw, 0, 255, 'UTF-8') : substr($notesRaw, 0, 255));
    $hasCode = orange_table_has_column($pdo, 'suppliers', 'code');
    $hasPayableAcc = orange_table_has_column($pdo, 'suppliers', 'payable_account_id');
    $codeSql = orange_supplier_normalize_code($pdo, $data['code'] ?? '');

    $payableAccountSql = null;
    if ($hasPayableAcc) {
        $pRaw = isset($data['payable_account_id']) ? (int) $data['payable_account_id'] : 0;
        if ($pRaw <= 0) {
            json_response(['success' => false, 'message' => 'حساب ذمة المورد في الدليل إلزامي — أنشئ حساباً فرعياً تحت الخصوم واختره (لا يُستخدم حساب مجمع).'], 422);
        }
        if (!orange_accounts_account_is_posting_leaf($pdo, $pRaw)) {
            json_response(['success' => false, 'message' => 'حساب ذمة المورد يجب أن يكون حساباً فرعياً (ورقة ترحيل) في الدليل.'], 422);
        }
        $payableAccountSql = $pRaw;
    }

    if ($phoneSql !== null) {
        if ($idIn > 0) {
            $dup = $pdo->prepare('SELECT id FROM suppliers WHERE phone = ? AND id != ? LIMIT 1');
            $dup->execute([$phoneSql, $idIn]);
        } else {
            $dup = $pdo->prepare('SELECT id FROM suppliers WHERE phone = ? LIMIT 1');
            $dup->execute([$phoneSql]);
        }
        if ($dup->fetchColumn()) {
            json_response(['success' => false, 'message' => 'هذا الهاتف مسجّل لمورد آخر'], 409);
        }
    }

    $assertCodeUnique = static function (int $excludeId) use ($pdo, $codeSql, $hasCode): void {
        if (!$hasCode || $codeSql === null) {
            return;
        }
        if ($excludeId > 0) {
            $cd = $pdo->prepare('SELECT id FROM suppliers WHERE code = ? AND id != ? LIMIT 1');
            $cd->execute([$codeSql, $excludeId]);
        } else {
            $cd = $pdo->prepare('SELECT id FROM suppliers WHERE code = ? LIMIT 1');
            $cd->execute([$codeSql]);
        }
        if ($cd->fetchColumn()) {
            json_response(['success' => false, 'message' => 'كود المورد مستخدم بالفعل'], 409);
        }
    };

    if ($idIn > 0) {
        $exRow = $pdo->prepare('SELECT id FROM suppliers WHERE id = ? LIMIT 1');
        $exRow->execute([$idIn]);
        if (!$exRow->fetchColumn()) {
            json_response(['success' => false, 'message' => 'المورد غير موجود'], 404);
        }
        $assertCodeUnique($idIn);
        if ($hasCode && $hasPayableAcc) {
            $pdo->prepare('UPDATE suppliers SET name = ?, phone = ?, notes = ?, code = ?, payable_account_id = ? WHERE id = ?')->execute([$name, $phoneSql, $notesSql, $codeSql, $payableAccountSql, $idIn]);
        } elseif ($hasCode) {
            $pdo->prepare('UPDATE suppliers SET name = ?, phone = ?, notes = ?, code = ? WHERE id = ?')->execute([$name, $phoneSql, $notesSql, $codeSql, $idIn]);
        } elseif ($hasPayableAcc) {
            $pdo->prepare('UPDATE suppliers SET name = ?, phone = ?, notes = ?, payable_account_id = ? WHERE id = ?')->execute([$name, $phoneSql, $notesSql, $payableAccountSql, $idIn]);
        } else {
            $pdo->prepare('UPDATE suppliers SET name = ?, phone = ?, notes = ? WHERE id = ?')->execute([$name, $phoneSql, $notesSql, $idIn]);
        }
        audit_log('supplier_update', 'تحديث مورد #' . $idIn . ' — ' . $name, 'suppliers', $idIn);
        json_response(['success' => true, 'message' => 'تم تحديث بيانات المورد', 'id' => $idIn]);

        return;
    }

    $assertCodeUnique(0);
    if ($hasCode && $hasPayableAcc) {
        $pdo->prepare('INSERT INTO suppliers (name, phone, notes, code, payable_account_id) VALUES (?, ?, ?, ?, ?)')->execute([$name, $phoneSql, $notesSql, $codeSql, $payableAccountSql]);
    } elseif ($hasCode) {
        $pdo->prepare('INSERT INTO suppliers (name, phone, notes, code) VALUES (?, ?, ?, ?)')->execute([$name, $phoneSql, $notesSql, $codeSql]);
    } elseif ($hasPayableAcc) {
        $pdo->prepare('INSERT INTO suppliers (name, phone, notes, payable_account_id) VALUES (?, ?, ?, ?)')->execute([$name, $phoneSql, $notesSql, $payableAccountSql]);
    } else {
        $pdo->prepare('INSERT INTO suppliers (name, phone, notes) VALUES (?, ?, ?)')->execute([$name, $phoneSql, $notesSql]);
    }
    $newId = (int) $pdo->lastInsertId();
    audit_log('supplier_create', 'مورد جديد: ' . $name, 'suppliers', $newId);
    json_response(['success' => true, 'message' => 'تم إضافة المورد', 'id' => $newId]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ المورد');
}
