<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

/**
 * س15 + بند 10: API بحث في جدول `customers` لشاشة كشف الحساب الموحّدة (وضع العميل).
 *
 * GET q=نص (اسم/هاتف/كود). يُعيد JSON: { success, customers: [{ id, code, name, phone, area }] }
 */
try {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_table_exists($pdo, 'customers')) {
        json_response(['success' => false, 'message' => 'جدول العملاء غير متوفر'], 500);
    }

    $q = trim((string) ($_GET['q'] ?? ''));

    $hasCode = orange_table_has_column($pdo, 'customers', 'code');
    $hasArea = orange_table_has_column($pdo, 'customers', 'area');
    $cols = 'c.id, c.name_ar AS name, c.phone';
    if ($hasCode) {
        $cols .= ', c.code';
    }
    if ($hasArea) {
        $cols .= ', c.area';
    }

    $sql = 'SELECT ' . $cols . ' FROM customers c';
    $params = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $conds = ['c.name_ar LIKE ?', 'c.phone LIKE ?'];
        $params[] = $like;
        $params[] = $like;
        if ($hasCode) {
            $conds[] = 'c.code LIKE ?';
            $params[] = $like;
        }
        $sql .= ' WHERE (' . implode(' OR ', $conds) . ')';
    }
    $sql .= ' ORDER BY c.id DESC LIMIT 80';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $out[] = [
            'id' => $id,
            'code' => $hasCode ? (string) ($r['code'] ?? '') : '',
            'name' => (string) ($r['name'] ?? ''),
            'phone' => (string) ($r['phone'] ?? ''),
            'area' => $hasArea ? (string) ($r['area'] ?? '') : '',
        ];
    }

    json_response(['success' => true, 'customers' => $out]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر البحث في العملاء');
}
