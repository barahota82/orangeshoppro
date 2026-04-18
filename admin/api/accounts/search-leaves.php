<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/account_tree.php';
require_admin_api();

try {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_table_exists($pdo, 'accounts')) {
        json_response(['success' => false, 'message' => 'جدول الحسابات غير متوفر'], 500);
    }

    $q = trim((string) ($_GET['q'] ?? ''));

    $cols = 'a.id, a.name, a.code';
    if (orange_table_has_column($pdo, 'accounts', 'parent_id')) {
        $cols .= ', a.parent_id';
    }
    if (orange_table_has_column($pdo, 'accounts', 'is_group')) {
        $cols .= ', a.is_group';
    }
    $sql = 'SELECT ' . $cols . ' FROM accounts a WHERE ' . orange_accounts_posting_leaf_where_sql($pdo, 'a');
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (a.code LIKE ? OR a.name LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY COALESCE(a.code, \'\'), a.name ASC LIMIT 80';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows);
    $allowed = orange_accounts_posting_leaf_id_set($pdo, $ids);
    $out = [];
    foreach ($rows as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id <= 0 || ! isset($allowed[$id])) {
            continue;
        }
        $out[] = [
            'id' => $id,
            'code' => (string) ($r['code'] ?? ''),
            'name' => (string) ($r['name'] ?? ''),
        ];
    }

    json_response(['success' => true, 'accounts' => $out]);
} catch (Throwable $e) {
    api_error($e, 'تعذر البحث في الحسابات');
}
