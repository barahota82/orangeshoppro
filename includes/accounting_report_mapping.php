<?php

declare(strict_types=1);

/**
 * تصنيف الحسابات لاستخراج التقارير: يفضّل أعمدة الخريطة في accounts، ثم أدوار الشجرة (تراث).
 *
 * @see docs/ACCOUNTING_REPORTING_POLICY_V2.md
 */

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/account_tree.php';

/**
 * @param list<int> $accountIds
 *
 * @return array<int, array<string, mixed>>
 */
function orange_accounts_report_mapping_by_ids(PDO $pdo, array $accountIds): array
{
    $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn (int $x): bool => $x > 0)));
    if ($accountIds === []) {
        return [];
    }
    $hasAt = orange_table_has_column($pdo, 'accounts', 'account_type');
    $hasSec = orange_table_has_column($pdo, 'accounts', 'report_section');
    $hasRli = orange_table_has_column($pdo, 'accounts', 'report_line_id');
    if (! $hasAt) {
        return [];
    }

    $cols = 'a.id';
    if ($hasAt) {
        $cols .= ', a.account_type';
    }
    if ($hasSec) {
        $cols .= ', a.report_section';
    }
    if ($hasRli) {
        $cols .= ', a.report_line_id';
    }
    $join = '';
    if ($hasRli && orange_table_exists($pdo, 'report_line_master')) {
        $cols .= ', rlm.code AS report_line_code';
        $cols .= ', COALESCE(NULLIF(TRIM(rlm.label_ar),\'\'), rlm.code) AS report_line_label_ar';
        $cols .= ', rlm.sort_order AS report_line_sort';
        $join = ' LEFT JOIN report_line_master rlm ON rlm.id = a.report_line_id';
    }

    $ph = implode(',', array_fill(0, count($accountIds), '?'));
    try {
        $st = $pdo->prepare('SELECT ' . $cols . ' FROM accounts a' . $join . ' WHERE a.id IN (' . $ph . ')');
        $st->execute($accountIds);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange_accounts_report_mapping_by_ids] ' . $e->getMessage());
        }

        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $out[$id] = $row;
        }
    }

    return $out;
}

/**
 * شريحة قائمة الدخل: revenue | cogs | expense | none — من الخريطة المحفوظة ثم دور الشجرة.
 */
function orange_accounts_pnl_bucket_for_report(PDO $pdo, int $accountId, ?array $mapRow = null): string
{
    $at = strtolower(trim((string) (($mapRow ?? [])['account_type'] ?? '')));
    if (in_array($at, ['revenue', 'cogs', 'expense'], true)) {
        return $at;
    }
    $pr = orange_accounts_account_pl_role($pdo, $accountId);

    return in_array($pr, ['revenue', 'cogs', 'expense'], true) ? $pr : 'other';
}

/**
 * شريحة الميزانية: asset | liability | equity | other — من الخريطة المحفوظة ثم دور الشجرة.
 */
function orange_accounts_bs_bucket_for_report(PDO $pdo, int $accountId, ?array $mapRow = null): string
{
    $at = strtolower(trim((string) (($mapRow ?? [])['account_type'] ?? '')));
    if (in_array($at, ['asset', 'liability', 'equity'], true)) {
        return $at;
    }
    $br = orange_accounts_account_bs_role($pdo, $accountId);

    return in_array($br, ['asset', 'liability', 'equity'], true) ? $br : 'other';
}

/**
 * صف خريطة لـ {@see orange_accounts_pnl_bucket_for_report} من صف ناتج عن
 * `orange_financial_report_leaf_accounts_with_mapping()` — يُعبَّأ فقط عند وجود account_type غير فارغ.
 */
function orange_accounts_map_row_from_leaf_account_row(array $leafRow): ?array
{
    if (! array_key_exists('account_type', $leafRow)) {
        return null;
    }
    $raw = trim((string) ($leafRow['account_type'] ?? ''));
    if ($raw === '') {
        return null;
    }

    return ['account_type' => $leafRow['account_type']];
}

/**
 * تطبيع قيمة report_section قبل المقارنة (حروف مخفية أو BOM داخل السلسلة ليست «عربي»؛
 * تفسّر أحياناً ظهور عمود «فارغ» تقريراً رغم وجود نص في الواجهة).
 */
function orange_accounts_normalize_report_section_value(?string $raw): string
{
    $s = (string) ($raw ?? '');
    if ($s !== '' && preg_match('//u', $s) === 1) {
        $t = preg_replace('/[\x{200B}\x{FEFF}\x{200C}\x{200D}]/u', '', $s);
        if (is_string($t)) {
            $s = $t;
        }
    }
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s, 'UTF-8');
    }

    return strtolower($s);
}

/**
 * ترتيب قطاع المتاجرة: نفس أساس `orange_accounts_pnl_bucket_for_report` مع ضبط بحسب جذور 4 و5 إذا قطعت القيم المحفوظة السطر.
 */
function orange_accounts_pnl_bucket_for_trading_row(PDO $pdo, int $accountId, ?array $mapRowFromLeaf): string
{
    $b = orange_accounts_pnl_bucket_for_report($pdo, $accountId, $mapRowFromLeaf);
    $tree = orange_accounts_account_pl_role($pdo, $accountId);

    if ($b === 'expense' && ($tree === 'revenue' || $tree === 'cogs')) {
        return $tree;
    }

    if ($b === 'other' && ($tree === 'revenue' || $tree === 'cogs')) {
        return $tree;
    }

    return $b;
}

/** تسمية عربية لقسم التقرير (قيم account_tree / الحقول المحفوظة). */
function orange_accounts_report_section_label_ar(?string $sec): string
{
    $s = strtolower(trim((string) $sec));

    return match ($s) {
        'balance_sheet' => 'الميزانية العمومية',
        'trading' => 'المتاجرة',
        'pnl' => 'قائمة الدخل',
        'none' => '—',
        '' => '—',
        default => $s,
    };
}

/** لتجميع أسطر تقارير (ميزان المراجعة): ترتيب نوع الحساب في العرض ليكون مستقرًا. */
function orange_accounts_type_sort_rank(?string $at): int
{
    $s = strtolower(trim((string) $at));

    return match ($s) {
        'asset' => 10,
        'liability' => 20,
        'equity' => 30,
        'revenue' => 40,
        'cogs' => 50,
        'expense' => 60,
        'other' => 70,
        default => 99,
    };
}

/**
 * تسميات عربية ومفاتيح ترتيب لصف تقرير مستندًا إلى صف خريطة (من orange_accounts_report_mapping_by_ids أو مصفوفة فارغة).
 *
 * @return array<string, mixed>
 */
function orange_accounts_report_display_and_sort_meta(?array $mapRow): array
{
    $m = $mapRow ?? [];
    $secLabel = orange_accounts_report_section_label_ar(isset($m['report_section']) ? (string) $m['report_section'] : null);
    $rlLabel = trim((string) ($m['report_line_label_ar'] ?? ''));
    if ($rlLabel === '' && trim((string) ($m['report_line_code'] ?? '')) !== '') {
        $rlLabel = trim((string) $m['report_line_code']);
    }
    if ($rlLabel === '') {
        $rlLabel = '—';
    }
    $atRaw = strtolower(trim((string) ($m['account_type'] ?? '')));
    $secRaw = strtolower(trim((string) ($m['report_section'] ?? '')));
    $rlSort = isset($m['report_line_sort']) ? (int) $m['report_line_sort'] : 999999;

    return [
        'sec_label' => $secLabel,
        'line_label' => $rlLabel,
        '_sk_at' => orange_accounts_type_sort_rank($atRaw !== '' ? $atRaw : null),
        '_sk_sec' => $secRaw,
        '_sk_rl' => $rlSort,
    ];
}

/** ترتيب صفوف التقارير حسب نوع الحساب، قسم التقرير، سطر المرجع، ثم الكود. */
function orange_accounts_report_tb_rows_compare(array $x, array $y): int
{
    $c = ((int) ($x['_sk_at'] ?? 99)) <=> ((int) ($y['_sk_at'] ?? 99));
    if ($c !== 0) {
        return $c;
    }
    $c = strcmp((string) ($x['_sk_sec'] ?? ''), (string) ($y['_sk_sec'] ?? ''));
    if ($c !== 0) {
        return $c;
    }
    $c = ((int) ($x['_sk_rl'] ?? 999999)) <=> ((int) ($y['_sk_rl'] ?? 999999));
    if ($c !== 0) {
        return $c;
    }

    return strcmp((string) ($x['code'] ?? ''), (string) ($y['code'] ?? ''));
}

/**
 * ملخص قائمة الدخل لسنة مالية واحدة من السندات (بدون أرصدة افتتاح ولا قيود إقفال)، بخريطة الحساب المحفوظة ثم دور الشجرة.
 *
 * @param array<int, array<string, mixed>> $mapById ناتج orange_accounts_report_mapping_by_ids
 *
 * @return array{revenue: float, cogs_expense: float, net: float}
 */
function orange_accounts_fy_pl_summary_from_vouchers(PDO $pdo, int $fiscalYearId, array $mapById): array
{
    require_once __DIR__ . '/journal_voucher.php';
    $zero = ['revenue' => 0.0, 'cogs_expense' => 0.0, 'net' => 0.0];
    if ($fiscalYearId <= 0 || ! function_exists('orange_journal_vouchers_ready') || ! orange_journal_vouchers_ready($pdo)) {
        return $zero;
    }
    $tbPl = orange_voucher_account_totals($pdo, $fiscalYearId, ['opening_balance', 'year_end_close']);
    $plRevenue = 0.0;
    $plExpense = 0.0;
    foreach ($tbPl as $aid => $t) {
        $cls = orange_accounts_pnl_bucket_for_report($pdo, (int) $aid, $mapById[(int) $aid] ?? null);
        $deb = (float) $t['debit'];
        $cred = (float) $t['credit'];
        if ($cls === 'revenue') {
            $plRevenue += ($cred - $deb);
        } elseif ($cls === 'expense' || $cls === 'cogs') {
            $plExpense += ($deb - $cred);
        }
    }
    $net = round($plRevenue - $plExpense, 2);

    return [
        'revenue' => round($plRevenue, 2),
        'cogs_expense' => round($plExpense, 2),
        'net' => $net,
    ];
}
