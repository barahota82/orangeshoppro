<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * مخطط الدليل الرقمي (موحّد في النظام): المستوى الأول = أكواد رقمية 1…N، وأول مستوى للترحيل يبدأ من N+1 (افتراضياً 1–10 ثم من 11).
 * القيم تُقرأ من الجدول `accounts.code`؛ الشجرة (parent_id / is_group) تكمّل التحقق ولا تلغي قاعدة الكود.
 */
function orange_accounts_code_first_level_max_numeric(): int
{
    return 10;
}

function orange_accounts_code_min_posting_numeric(): int
{
    return orange_accounts_code_first_level_max_numeric() + 1;
}

/**
 * معرّف جذر الشجرة لأي حساب (يتسلق parent_id حتى الأب الفارغ).
 */
function orange_accounts_top_root_id(PDO $pdo, int $accountId): int
{
    if ($accountId <= 0 || ! orange_table_has_column($pdo, 'accounts', 'parent_id')) {
        return $accountId;
    }
    $cur = $accountId;
    for ($g = 0; $g < 500; ++$g) {
        $st = $pdo->prepare('SELECT parent_id FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([$cur]);
        $pid = $st->fetchColumn();
        if ($pid === false || $pid === null || (int) $pid <= 0) {
            return $cur;
        }
        $cur = (int) $pid;
    }

    return $accountId;
}

/**
 * ربط كود الجذر الرقمي (مستوى أول، 1–10) بدور قائمة الدخل والإقفال.
 * عدّل المفاتيح لتطابق جذور دليلك (العمود `accounts.code` للجذر).
 *
 * @return array<int, string> revenue | expense | cogs
 */
function orange_accounts_root_numeric_pl_roles(): array
{
    return [
        3 => 'revenue',
        4 => 'expense',
        5 => 'cogs',
    ];
}

/**
 * احتياط إن لم يُعرّف كود الجذر في root_numeric_pl_roles: رتبة الجذر من «إعداد الدليل» (ترتيب 1…N).
 *
 * @return array<int, string>
 */
function orange_accounts_root_rank_pl_roles(): array
{
    return [
        2 => 'revenue',
        3 => 'expense',
        4 => 'cogs',
    ];
}

/**
 * ربط كود الجذر الرقمي ببند الميزانية العمومية المبسطة.
 *
 * @return array<int, string> asset | liability | equity
 */
function orange_accounts_root_numeric_bs_roles(): array
{
    return [
        1 => 'asset',
        2 => 'liability',
        3 => 'equity',
    ];
}

/**
 * @return array<int, string>
 */
function orange_accounts_root_rank_bs_roles(): array
{
    return [
        1 => 'asset',
        2 => 'liability',
        3 => 'equity',
    ];
}

/**
 * دور الحساب في قائمة الدخل/إقفال السنة — مُشتق من كود الجذر وترتيبه، دون عمود account_class.
 */
function orange_accounts_account_pl_role(PDO $pdo, int $accountId): string
{
    if ($accountId <= 0) {
        return 'other';
    }
    $rootId = orange_accounts_top_root_id($pdo, $accountId);
    $st = $pdo->prepare('SELECT code FROM accounts WHERE id = ? LIMIT 1');
    $st->execute([$rootId]);
    $code = trim((string) $st->fetchColumn());
    if ($code !== '' && ctype_digit($code)) {
        $n = (int) $code;
        $byCode = orange_accounts_root_numeric_pl_roles();
        if (isset($byCode[$n])) {
            return $byCode[$n];
        }
    }
    $rank = orange_accounts_root_rank($pdo, $rootId);
    if ($rank > 0) {
        $byRank = orange_accounts_root_rank_pl_roles();
        if (isset($byRank[$rank])) {
            return $byRank[$rank];
        }
    }

    return 'other';
}

/**
 * دور الحساب في الميزانية العمومية المبسطة — من جذر الدليل (كود/ترتيب)، دون account_class.
 */
function orange_accounts_account_bs_role(PDO $pdo, int $accountId): string
{
    if ($accountId <= 0) {
        return 'other';
    }
    $rootId = orange_accounts_top_root_id($pdo, $accountId);
    $st = $pdo->prepare('SELECT code FROM accounts WHERE id = ? LIMIT 1');
    $st->execute([$rootId]);
    $code = trim((string) $st->fetchColumn());
    if ($code !== '' && ctype_digit($code)) {
        $n = (int) $code;
        $byCode = orange_accounts_root_numeric_bs_roles();
        if (isset($byCode[$n])) {
            return $byCode[$n];
        }
    }
    $rank = orange_accounts_root_rank($pdo, $rootId);
    if ($rank > 0) {
        $byRank = orange_accounts_root_rank_bs_roles();
        if (isset($byRank[$rank])) {
            return $byRank[$rank];
        }
    }

    return 'other';
}

/**
 * @return list<array<string, mixed>>
 */
function orange_accounts_flat(PDO $pdo): array
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'accounts')) {
        return [];
    }
    $hasPar = orange_table_has_column($pdo, 'accounts', 'parent_id');
    $hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');
    $hasNameEn = orange_table_has_column($pdo, 'accounts', 'name_en');
    $hasSuspended = orange_table_has_column($pdo, 'accounts', 'is_suspended');
    $hasNb = orange_table_has_column($pdo, 'accounts', 'normal_balance');
    $cols = 'id, name, code, updated_at';
    if ($hasPar) {
        $cols .= ', parent_id';
    }
    if ($hasGrp) {
        $cols .= ', is_group';
    }
    if ($hasNameEn) {
        $cols .= ', name_en';
    }
    if ($hasSuspended) {
        $cols .= ', is_suspended';
    }
    if ($hasNb) {
        $cols .= ', normal_balance';
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

/** أعمق عمق مسموح (0 = جذر … 4 = المستوى الخامس) — خمسة مستويات إجمالاً. */
function orange_accounts_max_tree_depth(): int
{
    return 4;
}

/**
 * عمق الحساب في الشجرة: 0 للجذر، 1 للابن المباشر للجذر، …
 */
function orange_accounts_node_depth(PDO $pdo, int $accountId): int
{
    if ($accountId <= 0) {
        return -1;
    }
    $depth = 0;
    $cur = $accountId;
    for ($g = 0; $g < 500; ++$g) {
        $st = $pdo->prepare('SELECT parent_id FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([$cur]);
        $pid = $st->fetchColumn();
        if ($pid === false || $pid === null || (int) $pid <= 0) {
            return $depth;
        }
        ++$depth;
        $cur = (int) $pid;
    }

    return $depth;
}

/**
 * المستوى الأول في مخطط الدليل: كود رقمي من 1 إلى orange_accounts_code_first_level_max_numeric() فقط.
 * يُستبعد من البحث والترحيل. يُستدعى من PHP حتى لا يعتمد السلوك على REGEXP في MySQL فقط.
 */
function orange_accounts_code_is_first_level_root(string $code): bool
{
    $c = trim($code);
    if ($c === '' || ! ctype_digit($c)) {
        return false;
    }

    $n = (int) $c;
    $max = orange_accounts_code_first_level_max_numeric();

    return $n >= 1 && $n <= $max;
}

/**
 * شروط SQL لحساب يُرحّل إليه في القيود:
 * - جذر الشجرة: parent_id فارغ أو 0 — لا يُعرض ولا يُقبل بالكود حتى لو لم يُعلَم is_group كـ«رئيسي».
 * - مجلد (له أبناء): يُستبعد بـ NOT EXISTS.
 * - is_group = 1 (رأس/مجموعة): يُستبعد؛ الجذور المُنشأة من إعداد الدليل تُضبط is_group=1.
 * - مخطط الدليل (موحّد): كود رقمي ضمن المستوى الأول يُستبعد؛ أول كود ترحيل رقمي = orange_accounts_code_min_posting_numeric().
 *
 * إن لم يوجد parent_id ولا is_group لا نُرجع أي حساب في بحث الأوراق (1=0) لتفادي ظهور الجميع.
 *
 * @param string $alias اسم جدول/alias في الاستعلام (مثل a)
 */
function orange_accounts_posting_leaf_where_sql(PDO $pdo, string $alias = 'a'): string
{
    $hasPar = orange_table_has_column($pdo, 'accounts', 'parent_id');
    $hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');
    $parts = [];
    if ($hasPar) {
        $parts[] = "(COALESCE({$alias}.parent_id, 0) > 0)";
        $parts[] = "NOT EXISTS (SELECT 1 FROM accounts ch WHERE ch.parent_id = {$alias}.id)";
    }
    if ($hasGrp) {
        $parts[] = "COALESCE({$alias}.is_group, 0) = 0";
    }
    if ($parts === []) {
        return '(1=0)';
    }

    if (orange_table_has_column($pdo, 'accounts', 'code')) {
        $c = "TRIM(COALESCE({$alias}.code, ''))";
        $minPosting = orange_accounts_code_min_posting_numeric();
        $parts[] = "NOT ({$c} REGEXP '^[0-9]+$' AND CAST({$c} AS UNSIGNED) < {$minPosting})";
    }

    return '(' . implode(' AND ', $parts) . ')';
}

/**
 * تصفية نتائج بحث «حسابات فرعية» في PHP — نفس قواعد ورقة الترحيل، احتياطاً لو WHERE أو INFORMATION_SCHEMA أخفقا.
 *
 * @param list<array<string, mixed>> $rows صفوف من الاستعلام (يجب أن تتضمن parent_id / is_group عند توفر العمود)
 * @return list<array{id: int, code: string, name: string}>
 */
function orange_accounts_filter_rows_for_leaf_search(PDO $pdo, array $rows): array
{
    if ($rows === []) {
        return [];
    }
    $hasPar = orange_table_has_column($pdo, 'accounts', 'parent_id');
    $hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');

    $idsWithChildren = [];
    if ($hasPar) {
        $pq = $pdo->query('SELECT DISTINCT parent_id FROM accounts WHERE COALESCE(parent_id, 0) > 0');
        if ($pq !== false) {
            foreach ($pq->fetchAll(PDO::FETCH_COLUMN) as $p) {
                $idsWithChildren[(int) $p] = true;
            }
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $code = (string) ($r['code'] ?? '');
        $name = (string) ($r['name'] ?? '');

        if (orange_accounts_code_is_first_level_root($code)) {
            continue;
        }
        if ($hasPar) {
            $pid = (int) ($r['parent_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            if (! empty($idsWithChildren[$id])) {
                continue;
            }
        }
        if ($hasGrp && (int) ($r['is_group'] ?? 0) !== 0) {
            continue;
        }

        $out[] = [
            'id' => $id,
            'code' => $code,
            'name' => $name,
        ];
    }

    return $out;
}

/**
 * معرفات الحسابات التي تقبل الترحيل فعلياً (نفس شرط orange_accounts_account_is_posting_leaf) — استعلام واحد.
 *
 * @param list<int> $ids
 * @return array<int, true>
 */
function orange_accounts_posting_leaf_id_set(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map(static fn ($x) => (int) $x, $ids), static fn ($x) => $x > 0)));
    if ($ids === []) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = 'SELECT a.id, a.code FROM accounts a WHERE a.id IN (' . $ph . ') AND ' . orange_accounts_posting_leaf_where_sql($pdo, 'a');
    $st = $pdo->prepare($sql);
    $st->execute($ids);
    $ok = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) ($row['id'] ?? 0);
        $code = (string) ($row['code'] ?? '');
        if ($id <= 0) {
            continue;
        }
        if (orange_accounts_code_is_first_level_root($code)) {
            continue;
        }
        $ok[$id] = true;
    }

    return $ok;
}

/**
 * هل الحساب ورقة ترحيل (تحت أب، بلا أبناء، وليس مجموعة is_group)؟
 */
function orange_accounts_account_is_posting_leaf(PDO $pdo, int $accountId): bool
{
    if ($accountId <= 0 || !orange_table_exists($pdo, 'accounts')) {
        return false;
    }
    $sql = 'SELECT a.code FROM accounts a WHERE a.id = ? AND ' . orange_accounts_posting_leaf_where_sql($pdo, 'a') . ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([$accountId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (! $row) {
        return false;
    }
    if (orange_accounts_code_is_first_level_root((string) ($row['code'] ?? ''))) {
        return false;
    }

    return true;
}

/**
 * اقتراح كود فرعي تحت أب — مخطط ثابت لطول اللاحقة:
 * - تحت الجذر (المستوى 2): كود الأب + رقم بدون أصفار أمامية (11، 12… ثم 110… إن لزم).
 * - المستوى 3 و 4: لاحقة رقمان 01–99 (1101، 110101).
 * - المستوى 5 (الأخير): لاحقة 5 أرقام (…00001).
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
            return '1';
        }

        return (string) ($max + 1);
    }

    $pst = $pdo->prepare('SELECT code FROM accounts WHERE id = ? LIMIT 1');
    $pst->execute([$parentId]);
    $pc = $pst->fetchColumn();
    $prefix = $pc !== false && $pc !== null && $pc !== '' ? (string) $pc : (string) $parentId;
    $parentDepth = orange_accounts_node_depth($pdo, $parentId);
    $newDepth = $parentDepth + 1;
    if ($newDepth > orange_accounts_max_tree_depth()) {
        throw new RuntimeException('تجاوز أقصى عمق للدليل (خمسة مستويات)');
    }

    $st = $pdo->prepare(
        'SELECT code FROM accounts WHERE parent_id = ? AND code IS NOT NULL AND code <> \'\''
    );
    $st->execute([$parentId]);
    $maxSuffix = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
        $c = (string) $c;
        if ($prefix === '' || ! str_starts_with($c, $prefix) || strlen($c) <= strlen($prefix)) {
            continue;
        }
        $rest = substr($c, strlen($prefix));
        if ($rest === '' || ! ctype_digit($rest)) {
            continue;
        }
        if ($newDepth === 2 || $newDepth === 3) {
            if (strlen($rest) !== 2) {
                continue;
            }
            $maxSuffix = max($maxSuffix, (int) $rest);
        } elseif ($newDepth === 4) {
            if (strlen($rest) !== 5) {
                continue;
            }
            $maxSuffix = max($maxSuffix, (int) $rest);
        } else {
            $maxSuffix = max($maxSuffix, (int) $rest);
        }
    }

    $next = $maxSuffix + 1;
    if ($newDepth === 1) {
        return $prefix . (string) $next;
    }
    if ($newDepth === 2 || $newDepth === 3) {
        if ($next > 99) {
            throw new RuntimeException('نفدت الأكواد في هذا المستوى (الحد 99)');
        }

        return $prefix . str_pad((string) $next, 2, '0', STR_PAD_LEFT);
    }
    if ($newDepth === 4) {
        if ($next > 99999) {
            throw new RuntimeException('نفدت الأكواد في هذا المستوى (الحد 99999)');
        }

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    throw new LogicException('orange_accounts_suggest_child_code: depth out of range');
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

/**
 * حسابات الجذر مرتبة لشاشة «إعداد الدليل»: الترتيب يحدد رقم الصف؛ أول أربعة لا تُحذف.
 *
 * @return list<array<string, mixed>>
 */
function orange_accounts_roots_ordered(PDO $pdo): array
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'accounts') || !orange_table_has_column($pdo, 'accounts', 'parent_id')) {
        return [];
    }
    $hasNameEn = orange_table_has_column($pdo, 'accounts', 'name_en');
    $cols = 'id, name, code';
    if ($hasNameEn) {
        $cols .= ', name_en';
    }
    $sql = 'SELECT ' . $cols . ' FROM accounts WHERE (parent_id IS NULL OR parent_id = 0)'
        . " ORDER BY CASE WHEN code REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, CAST(code AS UNSIGNED), code, id";
    $stmt = $pdo->query($sql);
    if ($stmt === false) {
        return [];
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rank = 1;
    $out = [];
    foreach ($rows as $r) {
        $r['rank'] = $rank;
        $r['can_delete'] = $rank > 4;
        ++$rank;
        $out[] = $r;
    }

    return $out;
}

function orange_accounts_root_rank(PDO $pdo, int $accountId): int
{
    foreach (orange_accounts_roots_ordered($pdo) as $r) {
        if ((int) $r['id'] === $accountId) {
            return (int) $r['rank'];
        }
    }

    return 0;
}

/**
 * أسماء الجذر والمستوى الثاني في مسار الحساب (للعرض في الدليل).
 *
 * @param list<array<string, mixed>> $flat
 * @return array{root: string, category: string}
 */
function orange_coa_root_category_names(array $flat, int $nodeId): array
{
    $byId = [];
    foreach ($flat as $r) {
        $byId[(int) $r['id']] = $r;
    }
    $names = [];
    $cur = $nodeId;
    $guard = 0;
    while ($cur > 0 && isset($byId[$cur]) && $guard < 500) {
        array_unshift($names, (string) ($byId[$cur]['name'] ?? ''));
        $p = (int) ($byId[$cur]['parent_id'] ?? 0);
        $cur = $p;
        ++$guard;
    }

    return [
        'root' => $names[0] ?? '',
        'category' => $names[1] ?? '',
    ];
}

if (!function_exists('orange_render_coa_tree')) {
    /**
     * شجرة الدليل المحاسبي لواجهة الأدمن (مع بيانات للعرض والـ JS).
     *
     * @param list<array<string, mixed>> $nodes
     */
    function orange_render_coa_tree(array $nodes, int $activeId, array $flat, int $depth = 0, ?array $byId = null): void
    {
        if ($byId === null) {
            $byId = [];
            foreach ($flat as $fr) {
                $byId[(int) $fr['id']] = $fr;
            }
        }
        echo '<ul class="coa-tree-list">';
        foreach ($nodes as $n) {
            $id = (int) $n['id'];
            $code = htmlspecialchars((string) ($n['code'] ?? ''), ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars((string) ($n['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $nameEn = htmlspecialchars((string) ($n['name_en'] ?? ''), ENT_QUOTES, 'UTF-8');
            $isG = (int) ($n['is_group'] ?? 0) === 1;
            $susp = (int) ($n['is_suspended'] ?? 0) === 1;
            $nb = htmlspecialchars((string) ($n['normal_balance'] ?? 'debit'), ENT_QUOTES, 'UTF-8');
            $rc = orange_coa_root_category_names($flat, $id);
            $rootN = htmlspecialchars($rc['root'], ENT_QUOTES, 'UTF-8');
            $catN = htmlspecialchars($rc['category'], ENT_QUOTES, 'UTF-8');
            $pId = (int) ($n['parent_id'] ?? 0);
            $pCodeRaw = '';
            if ($pId > 0 && isset($byId[$pId])) {
                $pCodeRaw = (string) ($byId[$pId]['code'] ?? '');
            }
            $pCode = htmlspecialchars($pCodeRaw, ENT_QUOTES, 'UTF-8');
            $cls = $activeId === $id ? 'coa-tree-node is-active' : 'coa-tree-node';
            if ($susp) {
                $cls .= ' coa-tree-node--suspended';
            }
            echo '<li class="' . $cls . '" role="treeitem" data-id="' . $id . '" data-code="' . $code . '" data-name="' . $name . '" data-name-en="' . $nameEn . '" data-is-group="' . ($isG ? '1' : '0') . '" data-parent="' . $pId . '" data-parent-code="' . $pCode . '" data-suspended="' . ($susp ? '1' : '0') . '" data-depth="' . $depth . '" data-root-name="' . $rootN . '" data-category-name="' . $catN . '" data-normal-balance="' . $nb . '">';
            echo '<span class="coa-tree-label">' . $code . ' — ' . $name . ($isG ? ' <small>(رئيسي)</small>' : '') . ($susp ? ' <small class="coa-tree-suspended-tag">موقوف</small>' : '') . '</span>';
            if (!empty($n['children'])) {
                orange_render_coa_tree($n['children'], $activeId, $flat, $depth + 1, $byId);
            }
            echo '</li>';
        }
        echo '</ul>';
    }
}
