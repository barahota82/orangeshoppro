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

/**
 * حزمة قيد إيراد مردود مبيعات (مستند مستقل، نقدي / أونلاين / آجل).
 *
 * @return array{
 *   is_multi: bool,
 *   lines: list<array{account_id:int,debit:float,credit:float,memo:string}>,
 *   debit: int,
 *   credit: int,
 *   voucher_description: string,
 *   after_post: array|null,
 *   legacy_ar_subledger: bool
 * }
 *
 * @throws RuntimeException
 */
function orange_gl_sales_return_revenue_bundle(
    PDO $pdo,
    string $channel,
    int $customerId,
    int $returnId,
    float $amount
): array {
    $amount = round($amount, 4);
    if ($amount <= 0.0001) {
        throw new RuntimeException('مبلغ مردود المبيعات غير صالح.');
    }
    $channel = trim($channel);
    if (!in_array($channel, ['cash', 'online', 'credit'], true)) {
        throw new RuntimeException('قناة مردود المبيعات غير صالحة.');
    }

    $revCode = $channel === 'credit' ? 'SRR' : ($channel === 'online' ? 'OSR' : 'SCR');
    $revRule = orange_gl_order_delivery_setting_keys_from_rule($pdo, $revCode);
    if ($revRule !== null) {
        $revDebit = orange_gl_account_id($pdo, $revRule['debit_key']);
        $revCredit = orange_gl_account_id($pdo, $revRule['credit_key']);
        if ($revDebit === $revCredit) {
            throw new RuntimeException(
                'قاعدة مردود الإيراد (' . $revCode . '): المدين والدائن يجب أن يختلفا — راجع «ربط أنواع اليومية».'
            );
        }
        $after = null;
        $legacy = false;
        if ($channel === 'credit' && $customerId > 0 && $amount > 0.0001) {
            $after = [
                'party_subledger' => [
                    'party_kind' => 'customer',
                    'party_id' => $customerId,
                    'debit' => 0.0,
                    'credit' => $amount,
                    'ref_type' => 'sales_return',
                    'ref_id' => $returnId,
                    'memo' => 'مردود مبيعات آجل',
                ],
            ];
            $legacy = true;
        }

        return [
            'is_multi' => false,
            'lines' => [],
            'debit' => $revDebit,
            'credit' => $revCredit,
            'voucher_description' => $channel === 'credit'
                ? 'مردود مبيعات آجل'
                : ($channel === 'online' ? 'مردود مبيعات أونلاين' : 'مردود مبيعات نقدي'),
            'after_post' => $after,
            'legacy_ar_subledger' => $legacy,
        ];
    }

    $revDebit = orange_gl_sales_return_revenue_debit_account_id($pdo, $channel);

    if ($channel === 'credit') {
        if ($customerId <= 0) {
            throw new RuntimeException('مردود مبيعات آجل يتطلّب عميلاً.');
        }
        $arId = orange_gl_account_id($pdo, 'ar_credit');
        $after = null;
        $legacy = $customerId > 0;
        if ($legacy && $amount > 0.0001) {
            $after = [
                'party_subledger' => [
                    'party_kind' => 'customer',
                    'party_id' => $customerId,
                    'debit' => 0.0,
                    'credit' => $amount,
                    'ref_type' => 'sales_return',
                    'ref_id' => $returnId,
                    'memo' => 'مردود مبيعات آجل',
                ],
            ];
        }

        return [
            'is_multi' => false,
            'lines' => [],
            'debit' => $revDebit,
            'credit' => $arId,
            'voucher_description' => 'مردود مبيعات آجل',
            'after_post' => $after,
            'legacy_ar_subledger' => $legacy,
        ];
    }

    $cashId = orange_gl_account_id($pdo, 'cash');

    return [
        'is_multi' => false,
        'lines' => [],
        'debit' => $revDebit,
        'credit' => $cashId,
        'voucher_description' => $channel === 'online' ? 'مردود مبيعات أونلاين' : 'مردود مبيعات نقدي',
        'after_post' => null,
        'legacy_ar_subledger' => false,
    ];
}

/**
 * حسابات قيد مردود تكلفة المبيعات (مخزون مدين / تكلفة مردود دائن) من القسم ١ أو من قاعدة CSR/CGR/COR.
 *
 * @return array{debit: int, credit: int}
 *
 * @throws RuntimeException
 */
function orange_gl_sales_return_cogs_accounts(PDO $pdo, string $channel): array
{
    $channel = trim($channel);
    if (!in_array($channel, ['cash', 'online', 'credit'], true)) {
        throw new RuntimeException('قناة مردود المبيعات غير صالحة.');
    }
    $inventoryId = orange_gl_account_id($pdo, 'inventory');
    $cogsRetId = orange_gl_cogs_return_account_id($pdo);
    $cogsCode = $channel === 'credit' ? 'CGR' : ($channel === 'online' ? 'COR' : 'CSR');
    $rule = orange_gl_order_delivery_setting_keys_from_rule($pdo, $cogsCode);
    if ($rule === null) {
        return ['debit' => $inventoryId, 'credit' => $cogsRetId];
    }
    $deb = orange_gl_account_id($pdo, $rule['debit_key']);
    $cred = orange_gl_account_id($pdo, $rule['credit_key']);
    if ($deb === $cred) {
        throw new RuntimeException(
            'قاعدة مردود التكلفة (' . $cogsCode . '): المدين والدائن يجب أن يختلفا — راجع «ربط أنواع اليومية».'
        );
    }

    return ['debit' => $deb, 'credit' => $cred];
}
