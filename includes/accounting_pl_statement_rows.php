<?php

declare(strict_types=1);

require_once __DIR__ . '/accounting_report_mapping.php';

/**
 * قطاع واحد من قائمة الدخل الزمنية (إيراد / تكم / مصروف).
 *
 * @param 'revenue'|'cogs'|'expense' $plClass
 * @param 'income_statement'|'trading_account' $sectionPolicy
 *        — `income_statement`: إيراد/تكم → report_section فارغ أو trading (شاشة أرباح وخسائر).
 *        — `trading_account`: إيراد/تكم → يُقبل أيضاً pnl و none؛ وتُحمَّل دليل المتاجرة بـ bucket يستند للشجرة
 *           عند تعارض `account_type` (مثل leaf تحت فرع الإيرادات مسجَّل مصروفاً في القاعدة).
 *
 * @return list<array<string, mixed>>
 */
function orange_accounts_build_pl_statement_section_lines(
    PDO $pdo,
    array $accountsLeaf,
    array $tbRange,
    array $tbBefore,
    string $plClass,
    string $sectionPolicy = 'income_statement'
): array {
    $out = [];
    $hasSec = orange_table_has_column($pdo, 'accounts', 'report_section');
    $expectSec = ['revenue' => 'trading', 'cogs' => 'trading', 'expense' => 'pnl'];
    $legacyHeadingMap = [
        'revenue' => 'إيرادات ومبيعات — تصنيف افتراضي (دورة الشجرة عند غياب سطر المرجع)',
        'cogs' => 'تكلفة المبيعات — تصنيف افتراضي (دورة الشجرة)',
        'expense' => 'مصروفات — تصنيف افتراضي (دورة الشجرة)',
    ];

    foreach ($accountsLeaf as $a) {
        $aid = (int) ($a['id'] ?? 0);
        if ($aid <= 0) {
            continue;
        }
        $mapLeaf = orange_accounts_map_row_from_leaf_account_row($a);
        $bucket = (
            $sectionPolicy === 'trading_account'
            && ($plClass === 'revenue' || $plClass === 'cogs')
        )
            ? orange_accounts_pnl_bucket_for_trading_row($pdo, $aid, $mapLeaf)
            : orange_accounts_pnl_bucket_for_report($pdo, $aid, $mapLeaf);
        if ($bucket !== $plClass) {
            continue;
        }
        if ($hasSec) {
            $sec = orange_accounts_normalize_report_section_value(isset($a['report_section']) ? (string) $a['report_section'] : '');
            if (
                $sectionPolicy === 'trading_account'
                && ($plClass === 'revenue' || $plClass === 'cogs')
            ) {
                if ($sec !== '') {
                    $allowedTrading = ['none', 'trading', 'pnl'];
                    $ok = in_array($sec, $allowedTrading, true);
                    if (! $ok && in_array($sec, ['balance_sheet', 'cashflow'], true)) {
                        $ok = orange_accounts_account_pl_role($pdo, $aid) === $plClass;
                    }
                    if (! $ok) {
                        continue;
                    }
                }
            } else {
                $want = $expectSec[$plClass] ?? '';
                if ($want !== '' && $sec !== '' && $sec !== $want) {
                    continue;
                }
            }
        }
        $d0 = $c0 = $d1 = $c1 = 0.0;
        if (isset($tbBefore[$aid])) {
            $d0 = (float) $tbBefore[$aid]['debit'];
            $c0 = (float) $tbBefore[$aid]['credit'];
        }
        if (isset($tbRange[$aid])) {
            $d1 = (float) $tbRange[$aid]['debit'];
            $c1 = (float) $tbRange[$aid]['credit'];
        }
        if ($plClass === 'revenue') {
            $open = $c0 - $d0;
            $period = $c1 - $d1;
        } else {
            $open = $d0 - $c0;
            $period = $d1 - $c1;
        }
        $closing = $open + $period;
        if (abs($open) < 0.0001 && abs($period) < 0.0001 && abs($closing) < 0.0001) {
            continue;
        }

        $mappedHeading = trim((string) ($a['report_line_heading_ar'] ?? ''));
        $sortKey = (int) ($a['report_line_sort'] ?? 500000);
        if ($mappedHeading === '') {
            $mappedHeading = $legacyHeadingMap[$plClass] ?? '—';
            $sortKey = ['revenue' => 301000, 'cogs' => 302000, 'expense' => 303000][$plClass] ?? 399999;
        }

        $code = trim((string) ($a['code'] ?? ''));
        $nm = (string) ($a['name'] ?? '');
        $out[] = [
            'code' => $code,
            'name' => $nm,
            'opening' => $open,
            'period' => $period,
            'closing' => $closing,
            '_section_heading' => $mappedHeading,
            '_section_sort' => $sortKey,
            'report_line_master_code' => strtolower(trim((string) ($a['report_line_master_code'] ?? ''))),
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
