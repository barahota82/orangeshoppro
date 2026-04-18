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
    $name = trim((string) ($data['name_ar'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    if ($phone === '') {
        json_response(['success' => false, 'message' => 'رقم الهاتف مطلوب كمعرّف للعميل'], 422);
    }
    if ($name === '') {
        $name = 'عميل';
    }

    $hasLimit = orange_table_has_column($pdo, 'customers', 'credit_limit');
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

    $st = $pdo->prepare('SELECT id FROM customers WHERE phone = ? LIMIT 1');
    $st->execute([$phone]);
    $ex = $st->fetchColumn();
    if ($ex) {
        $id = (int) $ex;
        if ($hasLimit && array_key_exists('credit_limit', $data)) {
            $pdo->prepare('UPDATE customers SET name_ar = ?, credit_limit = ? WHERE id = ?')->execute([$name, $creditLimitSql, $id]);
        } else {
            $pdo->prepare('UPDATE customers SET name_ar = ? WHERE id = ?')->execute([$name, $id]);
        }
        audit_log('customer_update', 'تحديث عميل: ' . $phone, 'customers', $id);
        json_response(['success' => true, 'message' => 'تم تحديث بيانات العميل', 'id' => $id]);
    }
    if ($hasLimit) {
        $pdo->prepare('INSERT INTO customers (name_ar, phone, credit_limit) VALUES (?, ?, ?)')->execute([$name, $phone, $creditLimitSql]);
    } else {
        $pdo->prepare('INSERT INTO customers (name_ar, phone) VALUES (?, ?)')->execute([$name, $phone]);
    }
    $id = (int) $pdo->lastInsertId();
    audit_log('customer_create', 'عميل جديد: ' . $phone, 'customers', $id);
    json_response(['success' => true, 'message' => 'تم إضافة العميل', 'id' => $id]);
} catch (Throwable $e) {
    api_error($e, 'تعذر حفظ العميل');
}
