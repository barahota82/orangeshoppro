<?php

declare(strict_types=1);

require_once __DIR__ . '/gl_settings.php';

/**
 * @return array{lines: list<array{account_id:int,debit:float,credit:float,memo:string}>}
 *
 * @throws RuntimeException
 */
function orange_gl_bridge_delivery_sale_four_lines(
    PDO $pdo,
    float $amount,
    string $arSettingKey,
    string $salesSettingKey,
    string $memoSaleLeg,
    string $memoCashLeg
): array {
    $amount = round($amount, 4);
    if ($amount <= 0.0001) {
        throw new RuntimeException('مبلغ إيراد التسليم غير صالح.');
    }
    $ar = orange_gl_account_id($pdo, $arSettingKey);
    $sales = orange_gl_account_id($pdo, $salesSettingKey);
    $cash = orange_gl_account_id($pdo, 'cash');
    if ($ar === $sales || $ar === $cash || $sales === $cash) {
        throw new RuntimeException(
            'يجب أن يختلف حساب الوسيط (عملاء) عن إيراد المبيعات وعن الخزينة — راجع «حسابات القيود التلقائية».'
        );
    }

    return [
        'lines' => [
            ['account_id' => $ar, 'debit' => $amount, 'credit' => 0.0, 'memo' => $memoSaleLeg],
            ['account_id' => $sales, 'debit' => 0.0, 'credit' => $amount, 'memo' => $memoSaleLeg],
            ['account_id' => $cash, 'debit' => $amount, 'credit' => 0.0, 'memo' => $memoCashLeg],
            ['account_id' => $ar, 'debit' => 0.0, 'credit' => $amount, 'memo' => $memoCashLeg],
        ],
    ];
}

/**
 * إيراد تسليم طلب نقدي: عملاء نقدي ثم خزينة.
 *
 * @return array{lines: list<array{account_id:int,debit:float,credit:float,memo:string}>}
 */
function orange_gl_cash_delivery_sale_four_lines(PDO $pdo, float $amount, string $memoSaleLeg, string $memoCashLeg): array
{
    return orange_gl_bridge_delivery_sale_four_lines(
        $pdo,
        $amount,
        'ar_cash',
        'sales_revenue_cash',
        $memoSaleLeg,
        $memoCashLeg
    );
}

/**
 * إيراد تسليم طلب أونلاين: نفس وسيط عملاء النقدي (ar_cash) ثم الخزينة؛ الإيراد على sales_revenue_online.
 *
 * @return array{lines: list<array{account_id:int,debit:float,credit:float,memo:string}>}
 */
function orange_gl_online_delivery_sale_four_lines(PDO $pdo, float $amount, string $memoSaleLeg, string $memoCashLeg): array
{
    return orange_gl_bridge_delivery_sale_four_lines(
        $pdo,
        $amount,
        'ar_cash',
        'sales_revenue_online',
        $memoSaleLeg,
        $memoCashLeg
    );
}
