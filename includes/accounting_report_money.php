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
