<?php

declare(strict_types=1);

require_once __DIR__ . '/currency.php';

/**
 * سياق عملة التقارير المحاسبية — من $orangeAdminMoney في index أو من دولة الأدmin.
 *
 * @param array{code?:string,unit?:string,decimals?:int}|null $fromIndex
 * @return array{code:string, unit:string, decimals:int}
 */
function orange_accounting_report_money(PDO $pdo, ?array $fromIndex = null): array
{
    if (
        is_array($fromIndex)
        && isset($fromIndex['decimals'], $fromIndex['code'], $fromIndex['unit'])
    ) {
        return [
            'code' => (string) $fromIndex['code'],
            'unit' => (string) $fromIndex['unit'],
            'decimals' => (int) $fromIndex['decimals'],
        ];
    }

    return orange_admin_currency_context($pdo);
}

/** تنسيق مبلغ تقرير بدون رمز العملة (جدول/طباعة). */
function orange_accounting_report_format_amount(float $amount, array $ctx): string
{
    return number_format($amount, (int) $ctx['decimals'], '.', ',');
}

/** تنسيق مبلغ تقرير مع وحدة العرض (ملخص/بطاقة). */
function orange_accounting_report_format_money(PDO $pdo, float $amount, ?array $ctx = null): string
{
    if ($ctx === null) {
        $ctx = orange_accounting_report_money($pdo);
    }

    return orange_format_money_for_context($ctx, $amount);
}

/** سطر تذييل الطباعة مع أرقام صفحات ديناميكية (counter(page/pages) في CSS). */
function orange_accounting_report_print_metafoot_markup(
    string $printDatetime,
    string $dateLabel = 'تاريخ ووقت الطباعة',
    string $extraClass = ''
): string {
    $dt = htmlspecialchars($printDatetime, ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8');
    $class = 'gl-acc-stmt-print-metafoot ta-report-metafoot';
    if ($extraClass !== '') {
        $class .= ' ' . $extraClass;
    }

    return '<p class="' . $class . '" dir="ltr">'
        . $label . ': ' . $dt
        . ' <span class="ta-report-page-suffix">— '
        . '<span class="ta-report-page-label">صفحة </span>'
        . '<span class="ta-report-page-num"></span>'
        . '<span class="ta-report-page-of"> من </span>'
        . '<span class="ta-report-page-total"></span>'
        . '</span></p>';
}

/** تذييل داخل tfoot لجدول ta-report-print-table — يتكرر أسفل كل صفحة طباعة. */
function orange_accounting_report_print_tfoot_html(
    int $colspan,
    string $printDatetime,
    string $dateLabel = 'تاريخ ووقت الطباعة'
): string {
    return '<tfoot class="ta-report-print-tfoot"><tr class="ta-report-footer-row"><td colspan="'
        . max(1, $colspan)
        . '" class="ta-report-footer-cell">'
        . orange_accounting_report_print_metafoot_markup($printDatetime, $dateLabel)
        . '</td></tr></tfoot>';
}
