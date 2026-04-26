<?php

declare(strict_types=1);

require_once __DIR__ . '/gl_settings.php';

/**
 * إيراد تسليم طلب نقدي (وليس أونلاين ولا آجل): أربعة أسطر — عملاء نقدي ثم خزينة.
 *
 * @return array{lines: list<array{account_id:int,debit:float,credit:float,memo:string}>}
 *
 * @throws RuntimeException
 */
function orange_gl_cash_delivery_sale_four_lines(PDO $pdo, float $amount, string $memoSaleLeg, string $memoCashLeg): array
{
    $amount = round($amount, 4);
    if ($amount <= 0.0001) {
        throw new RuntimeException('مبلغ إيراد التسليم غير صالح.');
    }
    $arCash = orange_gl_account_id($pdo, 'ar_cash');
    $sales = orange_gl_account_id($pdo, 'sales_revenue_cash');
    $cash = orange_gl_account_id($pdo, 'cash');
    if ($arCash === $sales || $arCash === $cash || $sales === $cash) {
        throw new RuntimeException(
            'يجب أن يختلف حساب العملاء النقدي عن إيراد المبيعات النقدية وعن الخزينة — راجع «حسابات القيود التلقائية».'
        );
    }

    return [
        'lines' => [
            ['account_id' => $arCash, 'debit' => $amount, 'credit' => 0.0, 'memo' => $memoSaleLeg],
            ['account_id' => $sales, 'debit' => 0.0, 'credit' => $amount, 'memo' => $memoSaleLeg],
            ['account_id' => $cash, 'debit' => $amount, 'credit' => 0.0, 'memo' => $memoCashLeg],
            ['account_id' => $arCash, 'debit' => 0.0, 'credit' => $amount, 'memo' => $memoCashLeg],
        ],
    ];
}
