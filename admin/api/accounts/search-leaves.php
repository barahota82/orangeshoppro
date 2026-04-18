<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_table_exists($pdo, 'accounts')) {
        json_response(['success' => false, 'message' => 'جدول الحسابات غير متوفر'], 500);
    }

    $q = trim((string) ($_GET['q'] ?? ''));
    $hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');

    $cols = 'id, name, code';
    $sql = 'SELECT ' . $cols . ' FROM accounts WHERE 1=1';
    $params = [];
    if ($hasGrp) {
        $sql .= ' AND COALESCE(is_group, 0) = 0';
    }
    if ($q !== '') {
        $sql .= ' AND (code LIKE ? OR name LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY COALESCE(code, \'\'), name ASC LIMIT 80';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) $r['id'],
            'code' => (string) ($r['code'] ?? ''),
            'name' => (string) ($r['name'] ?? ''),
        ];
    }

    json_response(['success' => true, 'accounts' => $out]);
} catch (Throwable $e) {
    api_error($e, 'تعذر البحث في الحسابات');
}
