<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/account_tree.php';
require_once __DIR__ . '/accounting_report_mapping.php';
require_once __DIR__ . '/journal_voucher.php';
require_once __DIR__ . '/fiscal_years.php';
require_once __DIR__ . '/gl_settings.php';

/**
 * @return 'operating'|'investing'|'financing'|'none'
 */
function orange_cash_flow_cf_section_normalize(?string $raw): string
{
    $s = strtolower(trim((string) $raw));

    return in_array($s, ['operating', 'investing', 'financing'], true) ? $s : 'none';
}

function orange_cash_flow_section_label_ar(string $section): string
{
    return match ($section) {
        'operating' => 'الأنشطة التشغيلية',
        'investing' => 'الأنشطة الاستثمارية',
        'financing' => 'أنشطة التمويل',
        default => 'غير مصنّف',
    };
}

/**
 * @return list<array<string, mixed>>
 */
function orange_cash_flow_leaf_rows(PDO $pdo): array
{
    orange_catalog_ensure_schema($pdo);
    $lw = orange_accounts_posting_leaf_where_sql($pdo, 'a');
    $hasCf = orange_table_has_column($pdo, 'accounts', 'cashflow_section');
    $hasAt = orange_table_has_column($pdo, 'accounts', 'account_type');
    $hasRl = orange_table_has_column($pdo, 'accounts', 'report_line_id');

    $cols = 'a.id, a.code, a.name';
    if ($hasAt) {
        $cols .= ', a.account_type';
    }
    if ($hasCf) {
        $cols .= ', a.cashflow_section';
    }
    $join = '';
    if ($hasRl && orange_table_exists($pdo, 'report_line_master')) {
        $cols .= ', rlm.code AS report_line_master_code';
        $join = ' LEFT JOIN report_line_master rlm ON rlm.id = a.report_line_id ';
    }

    $sql = 'SELECT ' . $cols . ' FROM accounts a' . $join . ' WHERE ' . $lw;
    $params = [];
    $countryFilter = orange_accounts_sql_country_filter($pdo, 'a');
    if ($countryFilter !== null) {
        $sql .= $countryFilter['sql'];
        $params = $countryFilter['params'];
    }
    $sql .= ' ORDER BY COALESCE(a.code, \'\'), a.name ASC';

    try {
        if ($params !== []) {
            $st = $pdo->prepare($sql);
            $st->execute($params);

            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange_cash_flow_leaf_rows] ' . $e->getMessage());
        }

        return [];
    }
}

/**
 * @param list<array<string, mixed>> $leaves
 *
 * @return array<int, array<string, mixed>>
 */
function orange_cash_flow_map_by_id_from_leaves(PDO $pdo, array $leaves): array
{
    $ids = [];
    foreach ($leaves as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return orange_accounts_report_mapping_by_ids($pdo, $ids);
}

/**
 * @param list<array<string, mixed>> $leaves
 * @param array<int, array<string, mixed>> $mapById
 *
 * @return list<int>
 */
function orange_cash_flow_cash_account_ids(PDO $pdo, array $leaves, array $mapById): array
{
    $ids = [];
    try {
        $cashGl = orange_gl_account_id_optional($pdo, 'cash');
        if ($cashGl !== null && $cashGl > 0) {
            $ids[(int) $cashGl] = true;
        }
    } catch (Throwable $e) {
        // optional mapping may be unset during setup
    }

    foreach ($leaves as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $rlCode = strtolower(trim((string) ($row['report_line_master_code'] ?? '')));
        if ($rlCode === 'cash_and_equivalents') {
            $ids[$id] = true;
        }
    }

    return array_values(array_map('intval', array_keys($ids)));
}

/**
 * @param array<int, array{debit:float,credit:float}> $tb
 */
function orange_cash_flow_balance_net(array $tb, int $accountId, string $bsBucket): float
{
    $t = $tb[$accountId] ?? ['debit' => 0.0, 'credit' => 0.0];
    $deb = (float) $t['debit'];
    $cred = (float) $t['credit'];
    if ($bsBucket === 'asset') {
        return round($deb - $cred, 4);
    }
    if ($bsBucket === 'liability' || $bsBucket === 'equity') {
        return round($cred - $deb, 4);
    }

    return round($cred - $deb, 4);
}

/**
 * @param array<int, array<string, mixed>> $leafById
 * @param array<int, array<string, mixed>> $mapById
 */
function orange_cash_flow_effective_section(
    PDO $pdo,
    int $accountId,
    array $leafById,
    array $mapById,
    string $bsBucket,
    string $pnlBucket
): string {
    if (in_array($pnlBucket, ['revenue', 'cogs', 'expense'], true)) {
        return 'none';
    }
    $raw = $leafById[$accountId]['cashflow_section'] ?? 'none';
    $cf = orange_cash_flow_cf_section_normalize(is_string($raw) ? $raw : null);
    if ($cf !== 'none') {
        return $cf;
    }
    if (in_array($bsBucket, ['asset', 'liability'], true)) {
        return 'operating';
    }
    if ($bsBucket === 'equity') {
        return 'financing';
    }

    return 'none';
}

/**
 * @param list<int> $cashAccountIds
 *
 * @return array<int, string>
 */
function orange_cash_flow_voucher_section_map(PDO $pdo, int $fiscalYearId, array $cashAccountIds): array
{
    if ($fiscalYearId <= 0 || $cashAccountIds === [] || ! orange_journal_vouchers_ready($pdo)) {
        return [];
    }

    $cashSet = array_fill_keys($cashAccountIds, true);
    $sql = 'SELECT jl.voucher_id, jl.account_id, jl.debit, jl.credit, a.cashflow_section
            FROM journal_lines jl
            INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
            INNER JOIN accounts a ON a.id = jl.account_id
            WHERE jv.fiscal_year_id = ?';
    $params = [$fiscalYearId];
    $countryBind = orange_gl_voucher_country_bind($pdo, 'jv');
    $sql .= $countryBind['sql'];
    foreach ($countryBind['params'] as $cp) {
        $params[] = $cp;
    }
    $sql .= orange_journal_voucher_sql_exclude_void($pdo, 'jv');

    $st = $pdo->prepare($sql);
    $st->execute($params);

    /** @var array<int, list<array{account_id:int,debit:float,credit:float,cashflow_section:string,is_cash:bool}>> $byVoucher */
    $byVoucher = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $vid = (int) ($r['voucher_id'] ?? 0);
        $aid = (int) ($r['account_id'] ?? 0);
        if ($vid <= 0 || $aid <= 0) {
            continue;
        }
        $byVoucher[$vid][] = [
            'account_id' => $aid,
            'debit' => (float) ($r['debit'] ?? 0),
            'credit' => (float) ($r['credit'] ?? 0),
            'cashflow_section' => orange_cash_flow_cf_section_normalize($r['cashflow_section'] ?? null),
            'is_cash' => isset($cashSet[$aid]),
        ];
    }

    $out = [];
    foreach ($byVoucher as $vid => $lines) {
        $hasCash = false;
        foreach ($lines as $ln) {
            if ($ln['is_cash'] && ($ln['debit'] > 0.0001 || $ln['credit'] > 0.0001)) {
                $hasCash = true;
                break;
            }
        }
        if (! $hasCash) {
            continue;
        }

        $bestSec = 'operating';
        $bestAmt = 0.0;
        foreach ($lines as $ln) {
            if ($ln['is_cash']) {
                continue;
            }
            $sec = $ln['cashflow_section'];
            if ($sec === 'none') {
                $sec = 'operating';
            }
            $amt = $ln['debit'] + $ln['credit'];
            if ($amt > $bestAmt) {
                $bestAmt = $amt;
                $bestSec = $sec;
            }
        }
        $out[$vid] = $bestSec;
    }

    return $out;
}

/**
 * @param list<int> $cashAccountIds
 *
 * @return array{operating: array{inflow:float,outflow:float,net:float}, investing: array{inflow:float,outflow:float,net:float}, financing: array{inflow:float,outflow:float,net:float}}
 */
function orange_cash_flow_direct_section_totals(PDO $pdo, int $fiscalYearId, array $cashAccountIds): array
{
    $empty = static fn (): array => ['inflow' => 0.0, 'outflow' => 0.0, 'net' => 0.0];
    $sections = [
        'operating' => $empty(),
        'investing' => $empty(),
        'financing' => $empty(),
    ];
    if ($fiscalYearId <= 0 || $cashAccountIds === [] || ! orange_journal_vouchers_ready($pdo)) {
        return $sections;
    }

    $voucherSec = orange_cash_flow_voucher_section_map($pdo, $fiscalYearId, $cashAccountIds);
    $cashSet = array_fill_keys($cashAccountIds, true);

    $sql = 'SELECT jl.voucher_id, jl.debit, jl.credit
            FROM journal_lines jl
            INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
            WHERE jv.fiscal_year_id = ? AND jl.account_id IN (' . implode(',', array_fill(0, count($cashAccountIds), '?')) . ')';
    $params = array_merge([$fiscalYearId], $cashAccountIds);
    $countryBind = orange_gl_voucher_country_bind($pdo, 'jv');
    $sql .= $countryBind['sql'];
    foreach ($countryBind['params'] as $cp) {
        $params[] = $cp;
    }
    $sql .= orange_journal_voucher_sql_exclude_void($pdo, 'jv');

    $st = $pdo->prepare($sql);
    $st->execute($params);

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $vid = (int) ($r['voucher_id'] ?? 0);
        $deb = (float) ($r['debit'] ?? 0);
        $cred = (float) ($r['credit'] ?? 0);
        if ($vid <= 0 || ($deb <= 0.0001 && $cred <= 0.0001)) {
            continue;
        }
        $sec = $voucherSec[$vid] ?? 'operating';
        if (! isset($sections[$sec])) {
            $sec = 'operating';
        }
        $sections[$sec]['inflow'] += $deb;
        $sections[$sec]['outflow'] += $cred;
    }

    foreach ($sections as $k => $v) {
        $sections[$k]['inflow'] = round($v['inflow'], 4);
        $sections[$k]['outflow'] = round($v['outflow'], 4);
        $sections[$k]['net'] = round($v['inflow'] - $v['outflow'], 4);
    }

    return $sections;
}

/**
 * @param list<int> $cashAccountIds
 *
 * @return array{cash_begin: float, cash_end: float, net_change: float}
 */
function orange_cash_flow_cash_open_close(
    PDO $pdo,
    int $fiscalYearId,
    array $cashAccountIds,
    string $fyStartYmd,
    string $fyEndYmd
): array {
    $zero = ['cash_begin' => 0.0, 'cash_end' => 0.0, 'net_change' => 0.0];
    if ($cashAccountIds === [] || ! orange_journal_vouchers_ready($pdo)) {
        return $zero;
    }

    $before = orange_voucher_account_totals_strictly_before_date($pdo, $fyStartYmd, ['year_end_close']);
    $dayAfterEnd = date('Y-m-d', strtotime($fyEndYmd . ' +1 day') ?: time());
    $throughEnd = orange_voucher_account_totals_strictly_before_date($pdo, $dayAfterEnd, ['year_end_close']);

    $begin = 0.0;
    $end = 0.0;
    foreach ($cashAccountIds as $aid) {
        $aid = (int) $aid;
        $begin += orange_cash_flow_balance_net($before, $aid, 'asset');
        $end += orange_cash_flow_balance_net($throughEnd, $aid, 'asset');
    }

    $begin = round($begin, 4);
    $end = round($end, 4);

    return [
        'cash_begin' => $begin,
        'cash_end' => $end,
        'net_change' => round($end - $begin, 4),
    ];
}

/**
 * @param list<int> $cashAccountIds
 *
 * @return array<string, mixed>|null
 */
function orange_cash_flow_build_report(
    PDO $pdo,
    int $fiscalYearId,
    string $method,
    ?array $fyRow = null
): ?array {
    if ($fiscalYearId <= 0 || ! orange_journal_vouchers_ready($pdo)) {
        return null;
    }

    $method = $method === 'direct' ? 'direct' : 'indirect';
    $leaves = orange_cash_flow_leaf_rows($pdo);
    $mapById = orange_cash_flow_map_by_id_from_leaves($pdo, $leaves);
    $leafById = [];
    foreach ($leaves as $row) {
        $leafById[(int) ($row['id'] ?? 0)] = $row;
    }

    $cashIds = orange_cash_flow_cash_account_ids($pdo, $leaves, $mapById);
    $fyStart = trim((string) ($fyRow['start_date'] ?? ''));
    $fyEnd = trim((string) ($fyRow['end_date'] ?? ''));
    if ($fyStart === '' || $fyEnd === '') {
        foreach (orange_fiscal_years_list($pdo) as $yr) {
            if ((int) ($yr['id'] ?? 0) === $fiscalYearId) {
                $fyStart = trim((string) ($yr['start_date'] ?? ''));
                $fyEnd = trim((string) ($yr['end_date'] ?? ''));
                $fyRow = $yr;
                break;
            }
        }
    }

    $cashOc = orange_cash_flow_cash_open_close($pdo, $fiscalYearId, $cashIds, $fyStart, $fyEnd);
    $fyLabel = trim((string) ($fyRow['label_ar'] ?? ''));
    if ($fyLabel === '') {
        $fyLabel = 'سنة #' . $fiscalYearId;
    }

    $rows = [];
    $sectionsOut = [];

    if ($method === 'direct') {
        $secTotals = orange_cash_flow_direct_section_totals($pdo, $fiscalYearId, $cashIds);
        foreach (['operating', 'investing', 'financing'] as $sec) {
            $t = $secTotals[$sec];
            $sectionsOut[$sec] = $t;
            $rows[] = ['kind' => 'section', 'label' => orange_cash_flow_section_label_ar($sec), 'amount' => null, 'bold' => true];
            $rows[] = ['kind' => 'line', 'label' => 'وارد (مدين نقد)', 'amount' => $t['inflow'], 'indent' => 1];
            $rows[] = ['kind' => 'line', 'label' => 'صادر (دائن نقد)', 'amount' => -$t['outflow'], 'indent' => 1];
            $rows[] = ['kind' => 'subtotal', 'label' => 'صافي ' . orange_cash_flow_section_label_ar($sec), 'amount' => $t['net'], 'indent' => 1, 'bold' => true];
        }
    } else {
        $pl = orange_accounts_fy_pl_summary_from_vouchers($pdo, $fiscalYearId, $mapById);
        $netIncome = (float) ($pl['net'] ?? 0);
        $tb = orange_voucher_account_totals($pdo, $fiscalYearId, ['opening_balance', 'year_end_close']);

        $operatingAdj = [];
        $investingLines = [];
        $financingLines = [];
        $cashSet = array_fill_keys($cashIds, true);

        foreach ($leaves as $leaf) {
            $aid = (int) ($leaf['id'] ?? 0);
            if ($aid <= 0 || isset($cashSet[$aid])) {
                continue;
            }
            $map = $mapById[$aid] ?? null;
            $pnl = orange_accounts_pnl_bucket_for_report($pdo, $aid, $map);
            $bs = orange_accounts_bs_bucket_for_report($pdo, $aid, $map);
            if (in_array($pnl, ['revenue', 'cogs', 'expense'], true)) {
                continue;
            }
            $sec = orange_cash_flow_effective_section($pdo, $aid, $leafById, $mapById, $bs, $pnl);
            if ($sec === 'none') {
                continue;
            }
            $delta = orange_cash_flow_balance_net($tb, $aid, $bs);
            if (abs($delta) < 0.0001) {
                continue;
            }

            $code = trim((string) ($leaf['code'] ?? ''));
            $name = trim((string) ($leaf['name'] ?? ''));
            $label = ($code !== '' ? $code . ' — ' : '') . $name;

            if ($sec === 'operating') {
                if ($bs === 'asset') {
                    $cashEffect = -$delta;
                } else {
                    $cashEffect = $delta;
                }
                $operatingAdj[] = ['label' => $label, 'amount' => round($cashEffect, 4)];
            } elseif ($sec === 'investing') {
                $cashEffect = $bs === 'asset' ? -$delta : $delta;
                $investingLines[] = ['label' => $label, 'amount' => round($cashEffect, 4)];
            } else {
                $cashEffect = in_array($bs, ['liability', 'equity'], true) ? $delta : -$delta;
                $financingLines[] = ['label' => $label, 'amount' => round($cashEffect, 4)];
            }
        }

        $operatingNet = $netIncome;
        foreach ($operatingAdj as $adj) {
            $operatingNet += (float) $adj['amount'];
        }
        $investingNet = 0.0;
        foreach ($investingLines as $ln) {
            $investingNet += (float) $ln['amount'];
        }
        $financingNet = 0.0;
        foreach ($financingLines as $ln) {
            $financingNet += (float) $ln['amount'];
        }

        $sectionsOut = [
            'operating' => ['net' => round($operatingNet, 4), 'lines' => $operatingAdj, 'net_income' => $netIncome],
            'investing' => ['net' => round($investingNet, 4), 'lines' => $investingLines],
            'financing' => ['net' => round($financingNet, 4), 'lines' => $financingLines],
        ];

        $rows[] = ['kind' => 'section', 'label' => orange_cash_flow_section_label_ar('operating'), 'amount' => null, 'bold' => true];
        $rows[] = ['kind' => 'line', 'label' => 'صافي الربح (قائمة الدخل)', 'amount' => $netIncome, 'indent' => 1];
        foreach ($operatingAdj as $adj) {
            $rows[] = ['kind' => 'line', 'label' => 'تغيّر: ' . $adj['label'], 'amount' => (float) $adj['amount'], 'indent' => 1];
        }
        $rows[] = ['kind' => 'subtotal', 'label' => 'صافي النقد من التشغيل', 'amount' => round($operatingNet, 4), 'indent' => 1, 'bold' => true];

        $rows[] = ['kind' => 'section', 'label' => orange_cash_flow_section_label_ar('investing'), 'amount' => null, 'bold' => true];
        if ($investingLines === []) {
            $rows[] = ['kind' => 'line', 'label' => '— لا حركة مصنّفة —', 'amount' => 0.0, 'indent' => 1, 'muted' => true];
        } else {
            foreach ($investingLines as $ln) {
                $rows[] = ['kind' => 'line', 'label' => $ln['label'], 'amount' => (float) $ln['amount'], 'indent' => 1];
            }
        }
        $rows[] = ['kind' => 'subtotal', 'label' => 'صافي النقد من الاستثمار', 'amount' => round($investingNet, 4), 'indent' => 1, 'bold' => true];

        $rows[] = ['kind' => 'section', 'label' => orange_cash_flow_section_label_ar('financing'), 'amount' => null, 'bold' => true];
        if ($financingLines === []) {
            $rows[] = ['kind' => 'line', 'label' => '— لا حركة مصنّفة —', 'amount' => 0.0, 'indent' => 1, 'muted' => true];
        } else {
            foreach ($financingLines as $ln) {
                $rows[] = ['kind' => 'line', 'label' => $ln['label'], 'amount' => (float) $ln['amount'], 'indent' => 1];
            }
        }
        $rows[] = ['kind' => 'subtotal', 'label' => 'صافي النقد من التمويل', 'amount' => round($financingNet, 4), 'indent' => 1, 'bold' => true];
    }

    $computedNet = 0.0;
    if ($method === 'direct') {
        foreach ($sectionsOut as $t) {
            $computedNet += (float) ($t['net'] ?? 0);
        }
    } else {
        $computedNet = (float) ($sectionsOut['operating']['net'] ?? 0)
            + (float) ($sectionsOut['investing']['net'] ?? 0)
            + (float) ($sectionsOut['financing']['net'] ?? 0);
    }
    $computedNet = round($computedNet, 4);

    $rows[] = ['kind' => 'total', 'label' => 'صافي التغيّر في النقد (محسوب)', 'amount' => $computedNet, 'bold' => true];
    $rows[] = ['kind' => 'line', 'label' => 'نقد أول المدة', 'amount' => $cashOc['cash_begin'], 'indent' => 0];
    $rows[] = ['kind' => 'line', 'label' => 'نقد آخر المدة', 'amount' => $cashOc['cash_end'], 'indent' => 0, 'bold' => true];

    return [
        'method' => $method,
        'fiscal_year_id' => $fiscalYearId,
        'fy_label' => $fyLabel,
        'period' => $fyStart . ' — ' . $fyEnd,
        'cash_account_ids' => $cashIds,
        'cash_begin' => $cashOc['cash_begin'],
        'cash_end' => $cashOc['cash_end'],
        'net_change_computed' => $computedNet,
        'net_change_actual' => $cashOc['net_change'],
        'sections' => $sectionsOut,
        'rows' => $rows,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function orange_cash_flow_indirect(PDO $pdo, int $fiscalYearId, ?array $fyRow = null): ?array
{
    return orange_cash_flow_build_report($pdo, $fiscalYearId, 'indirect', $fyRow);
}

/**
 * @return array<string, mixed>|null
 */
function orange_cash_flow_direct(PDO $pdo, int $fiscalYearId, ?array $fyRow = null): ?array
{
    return orange_cash_flow_build_report($pdo, $fiscalYearId, 'direct', $fyRow);
}
