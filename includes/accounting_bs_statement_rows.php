<?php

declare(strict_types=1);

require_once __DIR__ . '/accounting_report_mapping.php';

/**
 * أكواد report_line_master المعتبرة «متداولة» للأصول (نقد + مخزون). الباقي = غير متداول/أخرى.
 *
 * @return list<string>
 */
function orange_accounts_bs_current_asset_master_codes(): array
{
    return ['cash_and_equivalents', 'inventory', 'accounts_receivable', 'receivables', 'prepaid'];
}

/**
 * قطاع واحد من الميزانية العمومية (أصول / خصوم / حقوق ملكية) عند تاريخ محدد.
 * عند تمرير $tbPrev تُضاف قيمة `balance_prev` (رصيد سنة/تاريخ سابق للمقارنة)؛ يبقى السطر
 * إذا كان أي من الرصيدين غير صفري. كل سطر يحمل `is_current` (أصول متداولة) بحسب كود المرجع.
 *
 * @param 'asset'|'liability'|'equity' $bsClass
 * @param array<int, array{debit:float,credit:float}>|null $tbPrev
 * @return list<array<string, mixed>>
 */
function orange_accounts_build_bs_statement_section_lines(
    PDO $pdo,
    array $accountsLeaf,
    array $tbAsOf,
    string $bsClass,
    ?array $tbPrev = null
): array {
    $out = [];
    $hasSec = orange_table_has_column($pdo, 'accounts', 'report_section');
    $legacyHeadingMap = [
        'asset' => 'أصول — تصنيف افتراضي (دورة الشجرة عند غياب سطر المرجع)',
        'liability' => 'خصوم — تصنيف افتراضي (دورة الشجرة)',
        'equity' => 'حقوق ملكية — تصنيف افتراضي (دورة الشجرة)',
    ];
    $currentCodes = orange_accounts_bs_current_asset_master_codes();

    $balanceFor = static function (array $tb, int $aid) use ($bsClass): float {
        $deb = $cred = 0.0;
        if (isset($tb[$aid])) {
            $deb = (float) $tb[$aid]['debit'];
            $cred = (float) $tb[$aid]['credit'];
        }

        return $bsClass === 'asset' ? ($deb - $cred) : ($cred - $deb);
    };

    foreach ($accountsLeaf as $a) {
        $aid = (int) ($a['id'] ?? 0);
        if ($aid <= 0) {
            continue;
        }
        $mapLeaf = orange_accounts_map_row_from_leaf_account_row($a);
        $bucket = orange_accounts_bs_bucket_for_report($pdo, $aid, $mapLeaf);
        if ($bucket !== $bsClass) {
            continue;
        }
        if ($hasSec) {
            $sec = orange_accounts_normalize_report_section_value(isset($a['report_section']) ? (string) $a['report_section'] : '');
            if ($sec === 'trading' || $sec === 'pnl') {
                continue;
            }
        }
        $balance = $balanceFor($tbAsOf, $aid);
        $balancePrev = $tbPrev !== null ? $balanceFor($tbPrev, $aid) : 0.0;
        if (abs($balance) < 0.0001 && abs($balancePrev) < 0.0001) {
            continue;
        }

        $mappedHeading = trim((string) ($a['report_line_heading_ar'] ?? ''));
        $sortKey = (int) ($a['report_line_sort'] ?? 500000);
        if ($mappedHeading === '') {
            $mappedHeading = $legacyHeadingMap[$bsClass] ?? '—';
            $sortKey = ['asset' => 101000, 'liability' => 201000, 'equity' => 301000][$bsClass] ?? 399999;
        }

        $masterCode = strtolower(trim((string) ($a['report_line_master_code'] ?? '')));

        $out[] = [
            'code' => trim((string) ($a['code'] ?? '')),
            'name' => (string) ($a['name'] ?? ''),
            'balance' => $balance,
            'balance_prev' => $balancePrev,
            'is_current' => $bsClass === 'asset' && in_array($masterCode, $currentCodes, true),
            '_section_heading' => $mappedHeading,
            '_section_sort' => $sortKey,
            'report_line_master_code' => $masterCode,
        ];
    }

    usort($out, static function (array $x, array $y): int {
        $sx = (int) ($x['_section_sort'] ?? 0);
        $sy = (int) ($y['_section_sort'] ?? 0);
        if ($sx !== $sy) {
            return $sx <=> $sy;
        }

        return strcmp((string) ($x['code'] ?? ''), (string) ($y['code'] ?? ''));
    });

    return $out;
}
