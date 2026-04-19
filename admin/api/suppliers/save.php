<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

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

    if ($idIn > 0) {
        $exRow = $pdo->prepare('SELECT id FROM suppliers WHERE id = ? LIMIT 1');
        $exRow->execute([$idIn]);
        if (!$exRow->fetchColumn()) {
            json_response(['success' => false, 'message' => 'المورد غير موجود'], 404);
        }
        $pdo->prepare('UPDATE suppliers SET name = ?, phone = ?, notes = ? WHERE id = ?')->execute([$name, $phoneSql, $notesSql, $idIn]);
        audit_log('supplier_update', 'تحديث مورد #' . $idIn . ' — ' . $name, 'suppliers', $idIn);
        json_response(['success' => true, 'message' => 'تم تحديث بيانات المورد', 'id' => $idIn]);

        return;
    }

    $pdo->prepare('INSERT INTO suppliers (name, phone, notes) VALUES (?, ?, ?)')->execute([$name, $phoneSql, $notesSql]);
    $newId = (int) $pdo->lastInsertId();
    audit_log('supplier_create', 'مورد جديد: ' . $name, 'suppliers', $newId);
    json_response(['success' => true, 'message' => 'تم إضافة المورد', 'id' => $newId]);
} catch (Throwable $e) {
    api_error($e, 'تعذر حفظ المورد');
}
