<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
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
    $phone = trim((string) ($data['phone'] ?? ''));
    $phoneSql = $phone === '' ? null : $phone;
    $notesRaw = trim((string) ($data['notes'] ?? ''));
    $notesSql = $notesRaw === '' ? null : (function_exists('mb_substr') ? mb_substr($notesRaw, 0, 255, 'UTF-8') : substr($notesRaw, 0, 255));
    $hasCode = orange_table_has_column($pdo, 'suppliers', 'code');
    $codeSql = orange_supplier_normalize_code($pdo, $data['code'] ?? '');

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
        if ($hasCode) {
            $pdo->prepare('UPDATE suppliers SET name = ?, phone = ?, notes = ?, code = ? WHERE id = ?')->execute([$name, $phoneSql, $notesSql, $codeSql, $idIn]);
        } else {
            $pdo->prepare('UPDATE suppliers SET name = ?, phone = ?, notes = ? WHERE id = ?')->execute([$name, $phoneSql, $notesSql, $idIn]);
        }
        audit_log('supplier_update', 'تحديث مورد #' . $idIn . ' — ' . $name, 'suppliers', $idIn);
        json_response(['success' => true, 'message' => 'تم تحديث بيانات المورد', 'id' => $idIn]);

        return;
    }

    $assertCodeUnique(0);
    if ($hasCode) {
        $pdo->prepare('INSERT INTO suppliers (name, phone, notes, code) VALUES (?, ?, ?, ?)')->execute([$name, $phoneSql, $notesSql, $codeSql]);
    } else {
        $pdo->prepare('INSERT INTO suppliers (name, phone, notes) VALUES (?, ?, ?)')->execute([$name, $phoneSql, $notesSql]);
    }
    $newId = (int) $pdo->lastInsertId();
    audit_log('supplier_create', 'مورد جديد: ' . $name, 'suppliers', $newId);
    json_response(['success' => true, 'message' => 'تم إضافة المورد', 'id' => $newId]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ المورد');
}
