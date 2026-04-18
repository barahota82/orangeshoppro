<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * @return list<array<string, mixed>>
 */
function orange_accounts_flat(PDO $pdo): array
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'accounts')) {
        return [];
    }
    $hasClass = orange_table_has_column($pdo, 'accounts', 'account_class');
    $hasPar = orange_table_has_column($pdo, 'accounts', 'parent_id');
    $hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');
    $cols = 'id, name, code, updated_at';
    if ($hasClass) {
        $cols .= ', account_class';
    }
    if ($hasPar) {
        $cols .= ', parent_id';
    }
    if ($hasGrp) {
        $cols .= ', is_group';
    }
    $rows = $pdo->query('SELECT ' . $cols . ' FROM accounts ORDER BY COALESCE(code, \'\'), id')->fetchAll(PDO::FETCH_ASSOC);

    return $rows;
}

/**
 * @param list<array<string, mixed>> $flat
 * @return list<array<string, mixed>>
 */
function orange_accounts_build_tree(array $flat): array
{
    $normPid = static function ($p): int {
        if ($p === null || $p === '') {
            return 0;
        }

        return (int) $p;
    };
    $walk = null;
    $walk = static function (int $pid) use (&$walk, $flat, $normPid): array {
        $out = [];
        foreach ($flat as $r) {
            if ($normPid($r['parent_id'] ?? null) !== $pid) {
                continue;
            }
            $row = $r;
            $row['children'] = $walk((int) $r['id']);
            $out[] = $row;
        }

        return $out;
    };

    return $walk(0);
}

function orange_accounts_is_descendant(PDO $pdo, int $ancestorId, int $nodeId): bool
{
    if ($ancestorId <= 0 || $nodeId <= 0 || !orange_table_has_column($pdo, 'accounts', 'parent_id')) {
        return false;
    }
    $cur = $nodeId;
    $guard = 0;
    while ($cur > 0 && $guard < 500) {
        if ($cur === $ancestorId) {
            return true;
        }
        $st = $pdo->prepare('SELECT parent_id FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([$cur]);
        $p = $st->fetchColumn();
        $cur = $p !== false && $p !== null ? (int) $p : 0;
        ++$guard;
    }

    return false;
}

/**
 * اقتراح كود فرعي تحت أب (نمط: كود_الأب + لاحقة رقمية، مثل 110201 تحت 1102).
 * يُستدعى داخل معاملة بعد GET_LOCK عند الحاجة.
 */
function orange_accounts_suggest_child_code(PDO $pdo, ?int $parentId): string
{
    orange_catalog_ensure_schema($pdo);
    if ($parentId === null || $parentId <= 0) {
        $st = $pdo->query(
            "SELECT code FROM accounts WHERE (parent_id IS NULL OR parent_id = 0)
             AND code IS NOT NULL AND code <> '' AND code REGEXP '^[0-9]+$'"
        );
        $max = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $max = max($max, (int) $c);
        }
        if ($max === 0) {
            $st2 = $pdo->query('SELECT MAX(id) FROM accounts');
            $max = (int) $st2->fetchColumn();

            return (string) max(1, $max + 1);
        }

        return (string) ($max + 1);
    }

    $pst = $pdo->prepare('SELECT code FROM accounts WHERE id = ? LIMIT 1');
    $pst->execute([$parentId]);
    $pc = $pst->fetchColumn();
    $prefix = $pc !== false && $pc !== null && $pc !== '' ? (string) $pc : (string) $parentId;

    $st = $pdo->prepare(
        'SELECT code FROM accounts WHERE parent_id = ? AND code IS NOT NULL AND code <> \'\''
    );
    $st->execute([$parentId]);
    $maxSuffix = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
        $c = (string) $c;
        if ($prefix !== '' && str_starts_with($c, $prefix) && strlen($c) > strlen($prefix)) {
            $rest = substr($c, strlen($prefix));
            if ($rest !== '' && ctype_digit($rest)) {
                $maxSuffix = max($maxSuffix, (int) $rest);
            }
        }
    }

    if ($maxSuffix === 0) {
        return $prefix . '01';
    }

    return $prefix . (string) ($maxSuffix + 1);
}

/**
 * قفل اسم آمن لـ MySQL GET_LOCK.
 */
function orange_accounts_lock_name(?int $parentId): string
{
    $p = $parentId !== null && $parentId > 0 ? $parentId : 0;

    return 'orange_acc_tree_' . $p;
}

/**
 * @param list<array<string, mixed>> $flat
 * @return array<int, int> id => depth (0 = جذر)
 */
function orange_accounts_depth_by_id(array $flat): array
{
    $byId = [];
    foreach ($flat as $r) {
        $byId[(int) $r['id']] = $r;
    }
    $memo = [];
    $walk = null;
    $walk = static function (int $id) use (&$walk, &$memo, $byId): int {
        if (isset($memo[$id])) {
            return $memo[$id];
        }
        $p = isset($byId[$id]['parent_id']) ? (int) $byId[$id]['parent_id'] : 0;
        if ($p <= 0 || !isset($byId[$p])) {
            $memo[$id] = 0;

            return 0;
        }
        $memo[$id] = 1 + $walk($p);

        return $memo[$id];
    };
    foreach (array_keys($byId) as $id) {
        $walk((int) $id);
    }

    return $memo;
}
