<?php

declare(strict_types=1);

require_once __DIR__ . '/supplier_payable_account.php';

/**
 * حزمة ترحيل فاتورة مشتريات: قيد بسيط أو سند بأربعة أسطر (نقدي + مورد عبر الذمة).
 *
 * @return array{
 *   is_multi: bool,
 *   lines: list<array{account_id:int,debit:float,credit:float,memo:string}>,
 *   debit: int,
 *   credit: int,
 *   voucher_description: string,
 *   after_post: array|null,
 *   legacy_ap_subledger: bool
 * }
 *
 * @throws RuntimeException
 */
function orange_gl_purchase_invoice_posting_bundle(
    PDO $pdo,
    string $purchaseType,
    int $supplierId,
    int $purchaseId,
    float $amount
): array {
    if (!in_array($purchaseType, ['cash', 'credit'], true)) {
        throw new RuntimeException('نوع الشراء غير صالح');
    }

    $amount = round($amount, 4);

    if ($purchaseType === 'credit') {
        if ($supplierId <= 0) {
            throw new RuntimeException('شراء آجل يتطلّب مورداً مربوطاً بحساب ذمة.');
        }
        $apId = orange_supplier_required_payable_account_id($pdo, $supplierId);
        $pinId = orange_journal_type_id_by_code($pdo, 'PIN');
        $rule = ($pinId > 0) ? orange_gl_journal_type_rule_for_terms($pdo, $pinId, 'credit') : null;

        if ($rule === null) {
            $inventoryId = orange_gl_account_id($pdo, 'inventory');
            $sync = $supplierId > 0 && $apId > 0;
            $after = null;
            if ($sync && $amount > 0.0001) {
                $after = [
                    'party_subledger' => [
                        'party_kind' => 'supplier',
                        'party_id' => $supplierId,
                        'debit' => 0.0,
                        'credit' => $amount,
                        'ref_type' => 'purchase',
                        'ref_id' => $purchaseId,
                        'memo' => 'شراء آجل',
                    ],
                ];
            }

            return [
                'is_multi' => false,
                'lines' => [],
                'debit' => $inventoryId,
                'credit' => $apId,
                'voucher_description' => 'شراء آجل — ذمم موردين',
                'after_post' => $after,
                'legacy_ap_subledger' => $sync,
            ];
        }

        $dk = trim((string) ($rule['debit_setting_key'] ?? ''));
        $ck = trim((string) ($rule['credit_setting_key'] ?? ''));
        if ($dk === '') {
            throw new RuntimeException(
                'قاعدة فاتورة مشتريات آجل تتطلّب بند المدين — راجع «حسابات القيود التلقائية».'
            );
        }
        $d = orange_gl_account_id($pdo, $dk);
        $c = $ck !== '' ? orange_gl_account_id($pdo, $ck) : $apId;
        if ($c <= 0) {
            throw new RuntimeException('تعذر تحديد حساب الدائن — اربط ذمة المورد أو بند الدائن في القاعدة.');
        }
        if ($ck !== '' && $d === $c) {
            throw new RuntimeException('بند المدين والدائن يجب أن يختلفان في قاعدة المشتريات الآجل.');
        }
        $sync = $supplierId > 0 && $c === $apId;
        $after = null;
        if ($sync && $amount > 0.0001) {
            $after = [
                'party_subledger' => [
                    'party_kind' => 'supplier',
                    'party_id' => $supplierId,
                    'debit' => 0.0,
                    'credit' => $amount,
                    'ref_type' => 'purchase',
                    'ref_id' => $purchaseId,
                    'memo' => 'شراء آجل',
                ],
            ];
        }

        return [
            'is_multi' => false,
            'lines' => [],
            'debit' => $d,
            'credit' => $c,
            'voucher_description' => 'شراء آجل — ذمم موردين',
            'after_post' => $after,
            'legacy_ap_subledger' => $sync,
        ];
    }

    // نقدي + مورد: مرور على ذمة المورد ثم الخزينة (سند واحد بأربعة أسطر).
    if ($supplierId > 0) {
        $apId = orange_supplier_required_payable_account_id($pdo, $supplierId);
        $pinId = orange_journal_type_id_by_code($pdo, 'PIN');
        $rule = ($pinId > 0) ? orange_gl_journal_type_rule_for_terms($pdo, $pinId, 'cash') : null;
        if ($rule === null) {
            $purchaseDebit = orange_gl_account_id($pdo, 'inventory');
            $cashCredit = orange_gl_account_id($pdo, 'cash');
        } else {
            $dk = trim((string) ($rule['debit_setting_key'] ?? ''));
            $ck = trim((string) ($rule['credit_setting_key'] ?? ''));
            if ($dk === '' || $ck === '') {
                throw new RuntimeException(
                    'قاعدة فاتورة مشتريات نقدي غير مكتملة — راجع «حسابات القيود التلقائية» (قسم ٢).'
                );
            }
            $purchaseDebit = orange_gl_account_id($pdo, $dk);
            $cashCredit = orange_gl_account_id($pdo, $ck);
        }
        if ($purchaseDebit === $apId || $cashCredit === $apId) {
            throw new RuntimeException(
                'قاعدة الشراء النقدي تستخدم نفس حساب ذمة المورد — اختر بند مدين ودائن مختلفين عن ذمة هذا المورد.'
            );
        }
        if ($purchaseDebit === $cashCredit) {
            throw new RuntimeException('في قاعدة المشتريات النقدي يجب أن يختلف المدين عن الدائن.');
        }

        $voucherDescription = 'شراء نقدي — عبر ذمة المورد وتسوية نقدية';
        $memoPurchase = 'شراء نقدي — تسجيل على المورد';
        $memoPay = 'شراء نقدي — سداد من الخزينة';
        $lines = [
            ['account_id' => $purchaseDebit, 'debit' => $amount, 'credit' => 0.0, 'memo' => $memoPurchase],
            ['account_id' => $apId, 'debit' => 0.0, 'credit' => $amount, 'memo' => $memoPurchase],
            ['account_id' => $apId, 'debit' => $amount, 'credit' => 0.0, 'memo' => $memoPay],
            ['account_id' => $cashCredit, 'debit' => 0.0, 'credit' => $amount, 'memo' => $memoPay],
        ];
        $after = null;
        if ($amount > 0.0001) {
            $after = [
                'party_subledger_entries' => [
                    [
                        'party_kind' => 'supplier',
                        'party_id' => $supplierId,
                        'debit' => 0.0,
                        'credit' => $amount,
                        'ref_type' => 'purchase',
                        'ref_id' => $purchaseId,
                        'memo' => $memoPurchase,
                    ],
                    [
                        'party_kind' => 'supplier',
                        'party_id' => $supplierId,
                        'debit' => $amount,
                        'credit' => 0.0,
                        'ref_type' => 'purchase',
                        'ref_id' => $purchaseId,
                        'memo' => $memoPay,
                    ],
                ],
            ];
        }

        return [
            'is_multi' => true,
            'lines' => $lines,
            'debit' => 0,
            'credit' => 0,
            'voucher_description' => $voucherDescription,
            'after_post' => $after,
            'legacy_ap_subledger' => false,
        ];
    }

    // نقدي بدون مورد: قيد مباشر للخزينة.
    $pinId = orange_journal_type_id_by_code($pdo, 'PIN');
    $rule = ($pinId > 0) ? orange_gl_journal_type_rule_for_terms($pdo, $pinId, 'cash') : null;
    if ($rule === null) {
        $inventoryId = orange_gl_account_id($pdo, 'inventory');
        $cashId = orange_gl_account_id($pdo, 'cash');

        return [
            'is_multi' => false,
            'lines' => [],
            'debit' => $inventoryId,
            'credit' => $cashId,
            'voucher_description' => 'شراء نقدي',
            'after_post' => null,
            'legacy_ap_subledger' => false,
        ];
    }
    $dk = trim((string) ($rule['debit_setting_key'] ?? ''));
    $ck = trim((string) ($rule['credit_setting_key'] ?? ''));
    if ($dk === '' || $ck === '') {
        throw new RuntimeException(
            'قاعدة فاتورة مشتريات نقدي غير مكتملة — راجع «حسابات القيود التلقائية» (قسم ٢).'
        );
    }
    $d = orange_gl_account_id($pdo, $dk);
    $c = orange_gl_account_id($pdo, $ck);
    if ($d === $c) {
        throw new RuntimeException('في قاعدة المشتريات النقدي يجب أن يختلف المدين عن الدائن.');
    }

    return [
        'is_multi' => false,
        'lines' => [],
        'debit' => $d,
        'credit' => $c,
        'voucher_description' => 'شراء نقدي',
        'after_post' => null,
        'legacy_ap_subledger' => false,
    ];
}

/**
 * حزمة ترحيل مردود مشتريات (PDN): عكس منطق PIN — يقرأ قواعد نوع يومية PDN من «حسابات القيود التلقائية».
 *
 * @return array{
 *   is_multi: bool,
 *   lines: list<array{account_id:int,debit:float,credit:float,memo:string}>,
 *   debit: int,
 *   credit: int,
 *   voucher_description: string,
 *   after_post: array|null,
 *   legacy_ap_subledger: bool
 * }
 *
 * @throws RuntimeException
 */
function orange_gl_purchase_return_posting_bundle(
    PDO $pdo,
    string $returnType,
    int $supplierId,
    int $returnId,
    float $amount
): array {
    if (!in_array($returnType, ['cash', 'credit'], true)) {
        throw new RuntimeException('نوع مردود المشتريات غير صالح');
    }

    $amount = round($amount, 4);

    if ($returnType === 'credit') {
        if ($supplierId <= 0) {
            throw new RuntimeException('مردود مشتريات آجل يتطلّب مورداً مربوطاً بحساب ذمة.');
        }
        $apId = orange_supplier_required_payable_account_id($pdo, $supplierId);
        $pdnId = orange_journal_type_id_by_code($pdo, 'PDN');
        $rule = ($pdnId > 0) ? orange_gl_journal_type_rule_for_terms($pdo, $pdnId, 'credit') : null;

        if ($rule === null) {
            $inventoryId = orange_gl_account_id($pdo, 'inventory');
            $sync = $supplierId > 0 && $apId > 0;
            $after = null;
            if ($sync && $amount > 0.0001) {
                $after = [
                    'party_subledger' => [
                        'party_kind' => 'supplier',
                        'party_id' => $supplierId,
                        'debit' => $amount,
                        'credit' => 0.0,
                        'ref_type' => 'purchase_return',
                        'ref_id' => $returnId,
                        'memo' => 'مردود مشتريات آجل',
                    ],
                ];
            }

            return [
                'is_multi' => false,
                'lines' => [],
                'debit' => $apId,
                'credit' => $inventoryId,
                'voucher_description' => 'مردود مشتريات آجل — ذمم موردين',
                'after_post' => $after,
                'legacy_ap_subledger' => $sync,
            ];
        }

        $dk = trim((string) ($rule['debit_setting_key'] ?? ''));
        $ck = trim((string) ($rule['credit_setting_key'] ?? ''));
        if ($ck === '') {
            throw new RuntimeException(
                'قاعدة مردود مشتريات آجل تتطلّب بند الدائن — راجع «حسابات القيود التلقائية».'
            );
        }
        $d = $dk !== '' ? orange_gl_account_id($pdo, $dk) : $apId;
        $c = orange_gl_account_id($pdo, $ck);
        if ($c <= 0) {
            throw new RuntimeException('تعذر تحديد حساب الدائن في قاعدة مردود المشتريات الآجل.');
        }
        if ($dk !== '' && $d === $c) {
            throw new RuntimeException('بند المدين والدائن يجب أن يختلفان في قاعدة مردود المشتريات الآجل.');
        }
        $sync = $supplierId > 0 && $d === $apId;
        $after = null;
        if ($sync && $amount > 0.0001) {
            $after = [
                'party_subledger' => [
                    'party_kind' => 'supplier',
                    'party_id' => $supplierId,
                    'debit' => $amount,
                    'credit' => 0.0,
                    'ref_type' => 'purchase_return',
                    'ref_id' => $returnId,
                    'memo' => 'مردود مشتريات آجل',
                ],
            ];
        }

        return [
            'is_multi' => false,
            'lines' => [],
            'debit' => $d,
            'credit' => $c,
            'voucher_description' => 'مردود مشتريات آجل — ذمم موردين',
            'after_post' => $after,
            'legacy_ap_subledger' => $sync,
        ];
    }

    // نقدي + مورد: استرداد نقدي عبر ذمة المورد (أربعة أسطر معكوسة عن شراء نقدي).
    if ($supplierId > 0) {
        $apId = orange_supplier_required_payable_account_id($pdo, $supplierId);
        $pdnId = orange_journal_type_id_by_code($pdo, 'PDN');
        $rule = ($pdnId > 0) ? orange_gl_journal_type_rule_for_terms($pdo, $pdnId, 'cash') : null;
        if ($rule === null) {
            $inventoryCr = orange_gl_account_id($pdo, 'inventory');
            $cashDr = orange_gl_account_id($pdo, 'cash');
        } else {
            $dk = trim((string) ($rule['debit_setting_key'] ?? ''));
            $ck = trim((string) ($rule['credit_setting_key'] ?? ''));
            if ($dk === '' || $ck === '') {
                throw new RuntimeException(
                    'قاعدة مردود مشتريات نقدي غير مكتملة — راجع «حسابات القيود التلقائية» (قسم ٢).'
                );
            }
            // نفس دلالة PIN نقدي: dk = جانب المخزون/المشتريات، ck = النقدية — السند معكوس محاسبياً.
            $inventoryCr = orange_gl_account_id($pdo, $dk);
            $cashDr = orange_gl_account_id($pdo, $ck);
        }
        if ($cashDr === $apId || $inventoryCr === $apId) {
            throw new RuntimeException(
                'قاعدة مردود المشتريات النقدي تستخدم نفس حساب ذمة المورد — اختر بند مدين ودائن مختلفين عن ذمة هذا المورد.'
            );
        }
        if ($cashDr === $inventoryCr) {
            throw new RuntimeException('في قاعدة مردود المشتريات النقدي يجب أن يختلف المدين عن الدائن.');
        }

        $voucherDescription = 'مردود مشتريات نقدي — عبر ذمة المورد واسترداد نقدي';
        // ترتيب القيد: ① من المورد إلى المخزن (مدين ذمة، دائن مخزون) ② من الخزينة إلى المورد (مدين نقد، دائن ذمة).
        $memoApInv = 'مردود نقدي — من ذمة المورد إلى المخزن';
        $memoCashAp = 'مردود نقدي — من الخزينة إلى ذمة المورد';
        $lines = [
            ['account_id' => $apId, 'debit' => $amount, 'credit' => 0.0, 'memo' => $memoApInv],
            ['account_id' => $inventoryCr, 'debit' => 0.0, 'credit' => $amount, 'memo' => $memoApInv],
            ['account_id' => $cashDr, 'debit' => $amount, 'credit' => 0.0, 'memo' => $memoCashAp],
            ['account_id' => $apId, 'debit' => 0.0, 'credit' => $amount, 'memo' => $memoCashAp],
        ];
        $after = null;
        if ($amount > 0.0001) {
            $after = [
                'party_subledger_entries' => [
                    [
                        'party_kind' => 'supplier',
                        'party_id' => $supplierId,
                        'debit' => $amount,
                        'credit' => 0.0,
                        'ref_type' => 'purchase_return',
                        'ref_id' => $returnId,
                        'memo' => $memoApInv,
                    ],
                    [
                        'party_kind' => 'supplier',
                        'party_id' => $supplierId,
                        'debit' => 0.0,
                        'credit' => $amount,
                        'ref_type' => 'purchase_return',
                        'ref_id' => $returnId,
                        'memo' => $memoCashAp,
                    ],
                ],
            ];
        }

        return [
            'is_multi' => true,
            'lines' => $lines,
            'debit' => 0,
            'credit' => 0,
            'voucher_description' => $voucherDescription,
            'after_post' => $after,
            'legacy_ap_subledger' => false,
        ];
    }

    // نقدي بدون مورد: مدين نقدية، دائن مخزون (عكس شراء نقدي مباشر).
    $pdnId = orange_journal_type_id_by_code($pdo, 'PDN');
    $rule = ($pdnId > 0) ? orange_gl_journal_type_rule_for_terms($pdo, $pdnId, 'cash') : null;
    if ($rule === null) {
        $cashId = orange_gl_account_id($pdo, 'cash');
        $inventoryId = orange_gl_account_id($pdo, 'inventory');

        return [
            'is_multi' => false,
            'lines' => [],
            'debit' => $cashId,
            'credit' => $inventoryId,
            'voucher_description' => 'مردود مشتريات نقدي',
            'after_post' => null,
            'legacy_ap_subledger' => false,
        ];
    }
    $dk = trim((string) ($rule['debit_setting_key'] ?? ''));
    $ck = trim((string) ($rule['credit_setting_key'] ?? ''));
    if ($dk === '' || $ck === '') {
        throw new RuntimeException(
            'قاعدة مردود مشتريات نقدي غير مكتملة — راجع «حسابات القيود التلقائية» (قسم ٢).'
        );
    }
    // عكس سطر شراء نقدي بدون مورد: مدين ck (نقد)، دائن dk (مخزون).
    $cashSide = orange_gl_account_id($pdo, $ck);
    $invSide = orange_gl_account_id($pdo, $dk);
    if ($cashSide === $invSide) {
        throw new RuntimeException('في قاعدة مردود المشتريات النقدي يجب أن يختلف المدين عن الدائن.');
    }

    return [
        'is_multi' => false,
        'lines' => [],
        'debit' => $cashSide,
        'credit' => $invSide,
        'voucher_description' => 'مردود مشتريات نقدي',
        'after_post' => null,
        'legacy_ap_subledger' => false,
    ];
}
