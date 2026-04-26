<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/account_tree.php';
require_once __DIR__ . '/gl_settings.php';

/**
 * حساب مدين المصروف + حساب دائن الخزينة لقيد المصروف.
 *
 * @return array{debit: int, credit: int}
 */
function orange_expense_gl_accounts(PDO $pdo, ?int $expenseAccountOverride): array
{
    $creditId = orange_gl_account_id($pdo, 'cash');
    if ($expenseAccountOverride !== null && $expenseAccountOverride > 0) {
        if (!orange_accounts_account_is_posting_leaf($pdo, $expenseAccountOverride)) {
            throw new RuntimeException('حساب المصروف يجب أن يكون فرعياً (ورقة ترحيل).');
        }
        if (orange_accounts_account_pl_role($pdo, $expenseAccountOverride) !== 'expense') {
            throw new RuntimeException('حساب المصروف يجب أن يكون ضمن جذر المصروفات في الدليل.');
        }
        $debitId = $expenseAccountOverride;
    } else {
        throw new RuntimeException('يجب اختيار حساب مصروف فرعي من الدليل لكل مصروف — لا يُستخدم حساب مصروف مجمع.');
    }

    return ['debit' => $debitId, 'credit' => $creditId];
}

/**
 * @param array{debit: int, credit: int} $pair
 * @return array{debit: int, credit: int}
 */
function orange_expense_gl_reversal_pair(array $pair): array
{
    return ['debit' => $pair['credit'], 'credit' => $pair['debit']];
}

/**
 * لعكس حذف مصروف قديم بلا expense_account_id (قبل سياسة «حساب لكل مصروف»).
 *
 * @param array<string, mixed> $row
 * @return array{debit: int, credit: int}
 */
function orange_expense_gl_pair_for_delete_row(PDO $pdo, array $row): array
{
    $oid = (int) ($row['expense_account_id'] ?? 0);
    if ($oid > 0) {
        return orange_expense_gl_accounts($pdo, $oid);
    }

    $fallback = orange_gl_account_id_optional($pdo, 'general_expense');
    if ($fallback === null || $fallback <= 0) {
        throw new RuntimeException(
            'مصروف قديم بلا حساب مصروف في الدليل — عيّن له حساباً من شاشة المصروفات أو أضف مؤقتاً مفتاح general_expense في جدول إعدادات القيود لعكس القيد.'
        );
    }

    return [
        'debit' => $fallback,
        'credit' => orange_gl_account_id($pdo, 'cash'),
    ];
}
