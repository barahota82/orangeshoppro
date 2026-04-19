<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'customers')) {
        json_response(['success' => false, 'message' => 'جدول العملاء غير متوفر'], 500);
    }
    $data = get_json_input();
    $idIn = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['name_ar'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    if ($phone === '') {
        json_response(['success' => false, 'message' => 'رقم الهاتف مطلوب كمعرّف للعميل'], 422);
    }
    if ($name === '') {
        $name = 'عميل';
    }

    $hasLimit = orange_table_has_column($pdo, 'customers', 'credit_limit');
    $hasNotes = orange_table_has_column($pdo, 'customers', 'notes');
    $creditLimitSql = null;
    if ($hasLimit && array_key_exists('credit_limit', $data)) {
        $rawLim = $data['credit_limit'];
        if ($rawLim === null || $rawLim === '') {
            $creditLimitSql = null;
        } else {
            $f = round((float) $rawLim, 4);
            $creditLimitSql = $f > 0.0001 ? $f : null;
        }
    }
    $notesRaw = trim((string) ($data['notes'] ?? ''));
    $notesSql = $notesRaw === '' ? null : (function_exists('mb_substr') ? mb_substr($notesRaw, 0, 255, 'UTF-8') : substr($notesRaw, 0, 255));

    if ($idIn > 0) {
        $exRow = $pdo->prepare('SELECT id FROM customers WHERE id = ? LIMIT 1');
        $exRow->execute([$idIn]);
        if (!$exRow->fetchColumn()) {
            json_response(['success' => false, 'message' => 'العميل غير موجود'], 404);
        }
        $dup = $pdo->prepare('SELECT id FROM customers WHERE phone = ? AND id != ? LIMIT 1');
        $dup->execute([$phone, $idIn]);
        if ($dup->fetchColumn()) {
            json_response(['success' => false, 'message' => 'هاتف مسجّل لعميل آخر'], 409);
        }
        if ($hasLimit && $hasNotes) {
            $pdo->prepare('UPDATE customers SET name_ar = ?, phone = ?, credit_limit = ?, notes = ? WHERE id = ?')->execute([$name, $phone, $creditLimitSql, $notesSql, $idIn]);
        } elseif ($hasLimit) {
            $pdo->prepare('UPDATE customers SET name_ar = ?, phone = ?, credit_limit = ? WHERE id = ?')->execute([$name, $phone, $creditLimitSql, $idIn]);
        } elseif ($hasNotes) {
            $pdo->prepare('UPDATE customers SET name_ar = ?, phone = ?, notes = ? WHERE id = ?')->execute([$name, $phone, $notesSql, $idIn]);
        } else {
            $pdo->prepare('UPDATE customers SET name_ar = ?, phone = ? WHERE id = ?')->execute([$name, $phone, $idIn]);
        }
        audit_log('customer_update', 'تحديث عميل #' . $idIn . ' — ' . $phone, 'customers', $idIn);
        json_response(['success' => true, 'message' => 'تم تحديث بيانات العميل', 'id' => $idIn]);

        return;
    }

    $st = $pdo->prepare('SELECT id FROM customers WHERE phone = ? LIMIT 1');
    $st->execute([$phone]);
    $ex = $st->fetchColumn();
    if ($ex) {
        $id = (int) $ex;
        if ($hasLimit && $hasNotes) {
            $pdo->prepare('UPDATE customers SET name_ar = ?, credit_limit = ?, notes = ? WHERE id = ?')->execute([$name, $creditLimitSql, $notesSql, $id]);
        } elseif ($hasLimit && array_key_exists('credit_limit', $data)) {
            $pdo->prepare('UPDATE customers SET name_ar = ?, credit_limit = ? WHERE id = ?')->execute([$name, $creditLimitSql, $id]);
        } elseif ($hasNotes) {
            $pdo->prepare('UPDATE customers SET name_ar = ?, notes = ? WHERE id = ?')->execute([$name, $notesSql, $id]);
        } else {
            $pdo->prepare('UPDATE customers SET name_ar = ? WHERE id = ?')->execute([$name, $id]);
        }
        audit_log('customer_update', 'تحديث عميل: ' . $phone, 'customers', $id);
        json_response(['success' => true, 'message' => 'تم تحديث بيانات العميل', 'id' => $id]);

        return;
    }

    if ($hasLimit && $hasNotes) {
        $pdo->prepare('INSERT INTO customers (name_ar, phone, credit_limit, notes) VALUES (?, ?, ?, ?)')->execute([$name, $phone, $creditLimitSql, $notesSql]);
    } elseif ($hasLimit) {
        $pdo->prepare('INSERT INTO customers (name_ar, phone, credit_limit) VALUES (?, ?, ?)')->execute([$name, $phone, $creditLimitSql]);
    } elseif ($hasNotes) {
        $pdo->prepare('INSERT INTO customers (name_ar, phone, notes) VALUES (?, ?, ?)')->execute([$name, $phone, $notesSql]);
    } else {
        $pdo->prepare('INSERT INTO customers (name_ar, phone) VALUES (?, ?)')->execute([$name, $phone]);
    }
    $newId = (int) $pdo->lastInsertId();
    audit_log('customer_create', 'عميل جديد: ' . $phone, 'customers', $newId);
    json_response(['success' => true, 'message' => 'تم إضافة العميل', 'id' => $newId]);
} catch (Throwable $e) {
    api_error($e, 'تعذر حفظ العميل');
}
