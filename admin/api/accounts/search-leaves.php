<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/account_tree.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

try {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_table_exists($pdo, 'accounts')) {
        json_response(['success' => false, 'message' => 'جدول الحسابات غير متوفر'], 500);
    }

    $countryFilter = orange_accounts_sql_country_filter($pdo, 'a');
    $q = trim((string) ($_GET['q'] ?? ''));
    $mode = trim((string) ($_GET['mode'] ?? ''));

    if ($mode === 'parents') {
        $hasPar = orange_table_has_column($pdo, 'accounts', 'parent_id');
        $hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');
        if (!$hasPar && !$hasGrp) {
            json_response(['success' => true, 'accounts' => []]);
        }
        $conditions = [];
        if ($hasPar) {
            $conditions[] = 'EXISTS (SELECT 1 FROM accounts ch WHERE ch.parent_id = a.id)';
        }
        if ($hasGrp) {
            $conditions[] = 'a.is_group = 1';
        }
        $where = '(' . implode(' OR ', $conditions) . ')';
        $sql = 'SELECT a.id, a.code, a.name FROM accounts a WHERE ' . $where;
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (a.code LIKE ? OR a.name LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($countryFilter !== null) {
            $sql .= $countryFilter['sql'];
            $params = array_merge($params, $countryFilter['params']);
        }
        $sql .= ' ORDER BY COALESCE(a.code, \'\'), a.name ASC LIMIT 500';
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
                'code' => (string) ($r['code'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
            ];
        }
        json_response(['success' => true, 'accounts' => $out]);
    }

    $cols = 'a.id, a.name, a.code';
    if (orange_table_has_column($pdo, 'accounts', 'parent_id')) {
        $cols .= ', a.parent_id';
    }
    if (orange_table_has_column($pdo, 'accounts', 'is_group')) {
        $cols .= ', a.is_group';
    }

    $leafWhere = orange_accounts_posting_leaf_where_sql($pdo, 'a');
    if (trim($leafWhere) === '(1=0)') {
        $sql = 'SELECT ' . $cols . ' FROM accounts a WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (a.code LIKE ? OR a.name LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($countryFilter !== null) {
            $sql .= $countryFilter['sql'];
            $params = array_merge($params, $countryFilter['params']);
        }
        $sql .= ' ORDER BY COALESCE(a.code, \'\'), a.name ASC LIMIT 500';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $rows = orange_accounts_filter_rows_for_leaf_search($pdo, $rows);
        if ($q !== '') {
            $qLower = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
            $rows = array_values(array_filter(
                $rows,
                static function (array $r) use ($qLower): bool {
                    $hayRaw = trim((string) ($r['code'] ?? '')) . ' ' . trim((string) ($r['name'] ?? ''));
                    $hay = function_exists('mb_strtolower') ? mb_strtolower($hayRaw, 'UTF-8') : strtolower($hayRaw);
                    if ($hay === '') {
                        return false;
                    }
                    if (function_exists('mb_strpos')) {
                        return mb_strpos($hay, $qLower, 0, 'UTF-8') !== false;
                    }

                    return strpos($hay, $qLower) !== false;
                }
            ));
        }
        $rows = array_slice($rows, 0, 80);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'code' => (string) ($r['code'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
            ];
        }
        json_response(['success' => true, 'accounts' => $out]);
    }

    $sql = 'SELECT ' . $cols . ' FROM accounts a WHERE ' . $leafWhere;
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (a.code LIKE ? OR a.name LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if ($countryFilter !== null) {
        $sql .= $countryFilter['sql'];
        $params = array_merge($params, $countryFilter['params']);
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
    orange_admin_api_catch($e, 'تعذر البحث في الحسابات');
}
