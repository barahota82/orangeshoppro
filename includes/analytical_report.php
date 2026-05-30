<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/analytical_dimensions.php';
require_once __DIR__ . '/accounting_report_mapping.php';
require_once __DIR__ . '/journal_voucher.php';
require_once __DIR__ . '/fiscal_years.php';
require_once __DIR__ . '/admin_settings_country.php';

/**
 * @return array{
 *   dimension:array<string,mixed>,
 *   mode:string,
 *   fiscal_year_id:int,
 *   rows:list<array<string,mixed>>,
 *   totals:array<string,float>
 * }
 */
function orange_analytical_report_build(
    PDO $pdo,
    int $fiscalYearId,
    int $dimensionId,
    string $mode = 'pl',
    ?int $countryId = null
): array {
    if (! orange_journal_vouchers_ready($pdo) || ! orange_analytical_dimensions_ready($pdo)) {
        throw new RuntimeException('جداول السندات أو الأبعاد غير جاهزة.');
    }
    if ($fiscalYearId <= 0) {
        throw new InvalidArgumentException('السنة المالية مطلوبة.');
    }
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_admin_settings_effective_country_id($pdo);
    }

    orange_analytical_dimension_seed_v1($pdo);
    $dim = orange_analytical_dimension_get($pdo, $dimensionId, $countryId);
    if ($dim === null) {
        throw new InvalidArgumentException('البُعد التحليلي غير موجود.');
    }

    $mode = strtolower(trim($mode));
    if (! in_array($mode, ['pl', 'movement'], true)) {
        $mode = 'pl';
    }

    $values = orange_analytical_dimension_values_list($pdo, $dimensionId, false);
    $valueMap = [];
    foreach ($values as $v) {
        $vid = (int) ($v['id'] ?? 0);
        if ($vid > 0) {
            $valueMap[$vid] = $v;
        }
    }

    $sql = 'SELECT jl.account_id, jl.debit, jl.credit, jl.dimension_value_id
            FROM journal_lines jl
            INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
            WHERE jv.fiscal_year_id = ?';
    $params = [$fiscalYearId];
    $countryBind = orange_gl_voucher_country_bind($pdo, 'jv');
    $sql .= $countryBind['sql'];
    foreach ($countryBind['params'] as $cp) {
        $params[] = $cp;
    }
    $sql .= orange_journal_voucher_sql_exclude_void($pdo, 'jv');
    $sql .= ' AND (jl.dimension_value_id IS NULL OR jl.dimension_value_id IN (
                SELECT id FROM analytical_dimension_value WHERE dimension_id = ?
            ))';
    $params[] = $dimensionId;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /** @var array<int|string, array{value_id:int|string,code:string,label:string,revenue:float,cogs:float,expense:float,debit:float,credit:float}> $buckets */
    $buckets = [];
    $initBucket = static function (int|string $key, array $valRow = []): array {
        $label = trim((string) ($valRow['label_ar'] ?? ''));
        if ($label === '') {
            $label = trim((string) ($valRow['label_en'] ?? ''));
        }
        $code = trim((string) ($valRow['code'] ?? ''));

        return [
            'value_id' => $key,
            'code' => $code,
            'label' => $label !== '' ? $label : ($key === 0 || $key === '0' ? '— بدون بُعد —' : ('#' . $key)),
            'revenue' => 0.0,
            'cogs' => 0.0,
            'expense' => 0.0,
            'debit' => 0.0,
            'credit' => 0.0,
        ];
    };

    foreach ($valueMap as $vid => $vRow) {
        $buckets[$vid] = $initBucket($vid, $vRow);
    }
    $buckets[0] = $initBucket(0);

    $accountIds = [];
    foreach ($raw as $row) {
        $aid = (int) ($row['account_id'] ?? 0);
        if ($aid > 0) {
            $accountIds[$aid] = true;
        }
    }
    $mapById = orange_accounts_report_mapping_by_ids($pdo, array_keys($accountIds));

    foreach ($raw as $row) {
        $aid = (int) ($row['account_id'] ?? 0);
        $debit = (float) ($row['debit'] ?? 0);
        $credit = (float) ($row['credit'] ?? 0);
        $vid = (int) ($row['dimension_value_id'] ?? 0);
        if ($vid <= 0 || ! isset($buckets[$vid])) {
            $vid = 0;
        }
        if (! isset($buckets[$vid])) {
            $buckets[$vid] = $initBucket($vid, $valueMap[$vid] ?? []);
        }

        $buckets[$vid]['debit'] += $debit;
        $buckets[$vid]['credit'] += $credit;

        if ($mode === 'pl') {
            $mapRow = $mapById[$aid] ?? null;
            $bucket = orange_accounts_pnl_bucket_for_report($pdo, $aid, $mapRow);
            if ($bucket === 'revenue') {
                $buckets[$vid]['revenue'] += $credit - $debit;
            } elseif ($bucket === 'cogs') {
                $buckets[$vid]['cogs'] += $debit - $credit;
            } elseif ($bucket === 'expense') {
                $buckets[$vid]['expense'] += $debit - $credit;
            }
        }
    }

    $rows = [];
    $totals = [
        'revenue' => 0.0,
        'cogs' => 0.0,
        'expense' => 0.0,
        'gross_profit' => 0.0,
        'net_profit' => 0.0,
        'debit' => 0.0,
        'credit' => 0.0,
        'net_movement' => 0.0,
    ];

    foreach ($buckets as $b) {
        $rev = round((float) ($b['revenue'] ?? 0), 4);
        $cogs = round((float) ($b['cogs'] ?? 0), 4);
        $exp = round((float) ($b['expense'] ?? 0), 4);
        $deb = round((float) ($b['debit'] ?? 0), 4);
        $cre = round((float) ($b['credit'] ?? 0), 4);
        $gross = round($rev - $cogs, 4);
        $net = round($gross - $exp, 4);
        $netMv = round($deb - $cre, 4);

        if ($mode === 'movement' && abs($deb) < 0.0001 && abs($cre) < 0.0001) {
            continue;
        }
        if ($mode === 'pl' && abs($rev) < 0.0001 && abs($cogs) < 0.0001 && abs($exp) < 0.0001) {
            continue;
        }

        $rows[] = [
            'value_id' => $b['value_id'],
            'code' => $b['code'],
            'label' => $b['label'],
            'revenue' => $rev,
            'cogs' => $cogs,
            'expense' => $exp,
            'gross_profit' => $gross,
            'net_profit' => $net,
            'debit' => $deb,
            'credit' => $cre,
            'net_movement' => $netMv,
        ];

        $totals['revenue'] += $rev;
        $totals['cogs'] += $cogs;
        $totals['expense'] += $exp;
        $totals['debit'] += $deb;
        $totals['credit'] += $cre;
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });

    $totals['gross_profit'] = round($totals['revenue'] - $totals['cogs'], 4);
    $totals['net_profit'] = round($totals['gross_profit'] - $totals['expense'], 4);
    $totals['net_movement'] = round($totals['debit'] - $totals['credit'], 4);
    foreach ($totals as $k => $v) {
        $totals[$k] = round((float) $v, 4);
    }

    return [
        'dimension' => $dim,
        'mode' => $mode,
        'fiscal_year_id' => $fiscalYearId,
        'rows' => $rows,
        'totals' => $totals,
    ];
}
