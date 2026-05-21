<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/journal_voucher.php';
require_once __DIR__ . '/party_subledger.php';
require_once __DIR__ . '/countries.php';

/**
 * معرف حساب ذمم الموردين دون إيقاف الصفحة إن كان الربط ناقصاً.
 */
function orange_financial_safe_ap_account_id(PDO $pdo): int
{
    if (!function_exists('orange_gl_account_id_optional')) {
        return 0;
    }
    try {
        $id = orange_gl_account_id_optional($pdo, 'accounts_payable');

        return $id !== null && $id > 0 ? (int) $id : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * نشاط ذمم الموردين (مدين/دائن) ضمن سنة مالية واحدة — من دفتر الأطراف.
 *
 * @return list<array{party_id:int, name:string, debit:float, credit:float, net:float}>
 */
function orange_financial_supplier_fy_subledger(PDO $pdo, int $fiscalYearId): array
{
    if ($fiscalYearId <= 0 || !orange_party_subledger_ready($pdo)) {
        return [];
    }
    $hasSuppliers = orange_table_exists($pdo, 'suppliers');
    $nameExpr = $hasSuppliers
        ? 'COALESCE(NULLIF(TRIM(s.name), \'\'), CONCAT(\'مورد #\', ps.party_id))'
        : 'CONCAT(\'مورد #\', ps.party_id)';
    $joinSup = $hasSuppliers ? 'LEFT JOIN suppliers s ON s.id = ps.party_id' : '';
    $countryBind = orange_gl_voucher_country_bind($pdo, 'jv');
    $sql = "SELECT ps.party_id AS party_id, {$nameExpr} AS party_name,
            COALESCE(SUM(ps.debit), 0) AS d, COALESCE(SUM(ps.credit), 0) AS c
         FROM party_subledger ps
         INNER JOIN journal_vouchers jv ON jv.id = ps.voucher_id
         {$joinSup}
         WHERE ps.party_kind = 'supplier' AND jv.fiscal_year_id = ?{$countryBind['sql']}
         GROUP BY ps.party_id, {$nameExpr}
         HAVING d > 0.0001 OR c > 0.0001
         ORDER BY party_name ASC";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$fiscalYearId], $countryBind['params']));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = round((float) $r['d'], 4);
        $c = round((float) $r['c'], 4);
        $out[] = [
            'party_id' => (int) $r['party_id'],
            'name' => (string) $r['party_name'],
            'debit' => $d,
            'credit' => $c,
            'net' => round($c - $d, 4),
        ];
    }

    return $out;
}

/**
 * رصيد دائن صافٍ لكل مورد حتى تاريخ نهاية السنة (يشمل كل السنوات حتى ذلك التاريخ).
 *
 * @return list<array{party_id:int, name:string, balance:float}>
 */
function orange_financial_supplier_balance_until_date(PDO $pdo, string $endDateInclusive): array
{
    $endDateInclusive = trim($endDateInclusive);
    if ($endDateInclusive === '' || !orange_party_subledger_ready($pdo)) {
        return [];
    }
    $hasSuppliers = orange_table_exists($pdo, 'suppliers');
    $nameExpr = $hasSuppliers
        ? 'COALESCE(NULLIF(TRIM(s.name), \'\'), CONCAT(\'مورد #\', ps.party_id))'
        : 'CONCAT(\'مورد #\', ps.party_id)';
    $joinSup = $hasSuppliers ? 'LEFT JOIN suppliers s ON s.id = ps.party_id' : '';
    $countryBind = orange_gl_voucher_country_bind($pdo, 'jv');
    $sql = "SELECT ps.party_id AS party_id, {$nameExpr} AS party_name,
            COALESCE(SUM(ps.credit - ps.debit), 0) AS bal
         FROM party_subledger ps
         INNER JOIN journal_vouchers jv ON jv.id = ps.voucher_id
         {$joinSup}
         WHERE ps.party_kind = 'supplier' AND DATE(jv.voucher_date) <= DATE(?){$countryBind['sql']}
         GROUP BY ps.party_id, {$nameExpr}
         HAVING ABS(bal) > 0.0001
         ORDER BY party_name ASC";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$endDateInclusive], $countryBind['params']));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'party_id' => (int) $r['party_id'],
            'name' => (string) $r['party_name'],
            'balance' => round((float) $r['bal'], 4),
        ];
    }

    return $out;
}

/**
 * مصروفات قديمة (سندات entry_type = expense من مسار أُزيل) مجمّعة بالبند والحساب المدين.
 *
 * @return list<array{account_id:int, label:string, amount:float}>
 */
function orange_financial_registered_expenses_fy(PDO $pdo, int $fiscalYearId): array
{
    if ($fiscalYearId <= 0 || !orange_journal_vouchers_ready($pdo)) {
        return [];
    }
    $hasExpenses = orange_table_exists($pdo, 'expenses');
    $joinExp = $hasExpenses ? 'LEFT JOIN expenses e ON jv.reference = CONCAT(\'EXP-\', e.id)' : '';
    $labelExpr = $hasExpenses
        ? 'COALESCE(NULLIF(TRIM(e.name), \'\'), NULLIF(TRIM(jl.memo), \'\'), NULLIF(TRIM(jv.description), \'\'), jv.reference)'
        : 'COALESCE(NULLIF(TRIM(jl.memo), \'\'), NULLIF(TRIM(jv.description), \'\'), jv.reference)';

    $countryBind = orange_gl_voucher_country_bind($pdo, 'jv');
    $sql = "SELECT jl.account_id AS account_id,
            {$labelExpr} AS expense_label,
            COALESCE(SUM(jl.debit), 0) AS amt
         FROM journal_vouchers jv
         INNER JOIN journal_lines jl ON jl.voucher_id = jv.id AND jl.debit > 0.0001
         {$joinExp}
         WHERE jv.fiscal_year_id = ? AND jv.entry_type = 'expense'{$countryBind['sql']}
         GROUP BY jl.account_id, {$labelExpr}
         HAVING amt > 0.0001
         ORDER BY expense_label ASC, jl.account_id ASC";

    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$fiscalYearId], $countryBind['params']));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'account_id' => (int) $r['account_id'],
            'label' => (string) $r['expense_label'],
            'amount' => round((float) $r['amt'], 4),
        ];
    }

    return $out;
}

/**
 * تفصيل حركة حسابات المصروف (جذر مصروفات فقط) حسب مذكرة السطر/بيان السند.
 *
 * @param list<int> $expenseAccountIds
 * @return array<int, list<array{sublabel:string, debit:float, credit:float}>>
 */
function orange_financial_expense_account_line_breakdown(PDO $pdo, int $fiscalYearId, array $expenseAccountIds): array
{
    if ($fiscalYearId <= 0 || $expenseAccountIds === [] || !orange_journal_vouchers_ready($pdo)) {
        return [];
    }
    $expenseAccountIds = array_values(array_unique(array_filter(array_map('intval', $expenseAccountIds), static fn (int $v): bool => $v > 0)));
    if ($expenseAccountIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($expenseAccountIds), '?'));
    $countryBind = orange_gl_voucher_country_bind($pdo, 'jv');
    $subExpr = "COALESCE(NULLIF(TRIM(jl.memo), ''), NULLIF(TRIM(jv.description), ''), '—')";
    $sql = "SELECT jl.account_id AS account_id,
            {$subExpr} AS sublabel,
            COALESCE(SUM(jl.debit), 0) AS d, COALESCE(SUM(jl.credit), 0) AS c
         FROM journal_lines jl
         INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
         WHERE jv.fiscal_year_id = ?
           AND jl.account_id IN ({$placeholders})
           AND jv.entry_type NOT IN ('opening_balance', 'year_end_close'){$countryBind['sql']}
         GROUP BY jl.account_id, {$subExpr}
         HAVING d > 0.0001 OR c > 0.0001
         ORDER BY jl.account_id ASC, sublabel ASC";
    $params = array_merge([$fiscalYearId], $expenseAccountIds, $countryBind['params']);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $aid = (int) $r['account_id'];
        if (!isset($out[$aid])) {
            $out[$aid] = [];
        }
        $out[$aid][] = [
            'sublabel' => (string) $r['sublabel'],
            'debit' => round((float) $r['d'], 4),
            'credit' => round((float) $r['c'], 4),
        ];
    }

    return $out;
}

require_once __DIR__ . '/account_tree.php';

/**
 * صفوف حسابات الترحيل مع عنوان سطر تقرير مرجعي (عند وجود report_line_id ومخططه).
 *
 * @return list<array<string, mixed>>
 */
function orange_financial_report_leaf_accounts_with_mapping(PDO $pdo): array
{
    orange_catalog_ensure_schema($pdo);
    $lw = orange_accounts_posting_leaf_where_sql($pdo, 'a');
    $hasRl = orange_table_has_column($pdo, 'accounts', 'report_line_id');
    $hasAt = orange_table_has_column($pdo, 'accounts', 'account_type');
    $hasSec = orange_table_has_column($pdo, 'accounts', 'report_section');
    $cols = 'a.id, a.code, a.name';
    if ($hasAt) {
        $cols .= ', a.account_type';
    }
    if ($hasSec) {
        $cols .= ', a.report_section';
    }
    if ($hasRl) {
        $cols .= ', a.report_line_id';
    }
    $join = '';
    if ($hasRl && orange_table_exists($pdo, 'report_line_master')) {
        $cols .= ', COALESCE(NULLIF(TRIM(rlm.label_ar),\'\'), rlm.code) AS report_line_heading_ar';
        $cols .= ', rlm.sort_order AS report_line_sort';
        $cols .= ', rlm.code AS report_line_master_code';
        $join = ' LEFT JOIN report_line_master rlm ON rlm.id = a.report_line_id ';
    }

    try {
        $rows = $pdo->query(
            'SELECT ' . $cols . ' FROM accounts a' . $join . ' WHERE ' . $lw . ' ORDER BY COALESCE(a.code, \'\'), a.name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange_financial_report_leaf_accounts_with_mapping] ' . $e->getMessage());
        }

        return [];
    }

    return is_array($rows) ? $rows : [];
}