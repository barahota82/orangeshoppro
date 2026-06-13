<?php

declare(strict_types=1);

require_once __DIR__ . '/supplier_payable_account.php';
require_once __DIR__ . '/gl_settings.php';
require_once __DIR__ . '/journal_types.php';

function orange_purchase_gl_country_id(PDO $pdo, ?int $countryId): int
{
    return orange_gl_settings_effective_country_id($pdo, $countryId);
}

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
    float $amount,
    ?int $countryId = null
): array {
    if (!in_array($purchaseType, ['cash', 'credit'], true)) {
        throw new RuntimeException('نوع الشراء غير صالح');
    }

    $amount = round($amount, 4);
    $glCountryId = orange_purchase_gl_country_id($pdo, $countryId);
    $glAcct = static fn (string $key): int => orange_gl_account_id($pdo, $key, $glCountryId);
    $jtByCode = static fn (string $code): int => orange_journal_type_id_by_code($pdo, $code, $glCountryId);

    if ($purchaseType === 'credit') {
        if ($supplierId <= 0) {
            throw new RuntimeException('شراء آجل يتطلّب مورداً مربوطاً بحساب ذمة.');
        }
        $apId = orange_supplier_required_payable_account_id($pdo, $supplierId);
        $pinId = $jtByCode('PIN');
        $rule = ($pinId > 0) ? orange_gl_journal_type_rule_for_terms($pdo, $pinId, 'credit') : null;

        if ($rule === null) {
            $inventoryId = $glAcct('inventory');
            $debitAssetId = $inventoryId;
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
                'debit' => $debitAssetId,
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
        $d = $glAcct($dk);
        $c = $ck !== '' ? $glAcct($ck) : $apId;
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
        $pinId = $jtByCode('PIN');
        $rule = ($pinId > 0) ? orange_gl_journal_type_rule_for_terms($pdo, $pinId, 'cash') : null;
        if ($rule === null) {
            $purchaseDebit = $glAcct('inventory');
            $cashCredit = $glAcct('cash');
        } else {
            $dk = trim((string) ($rule['debit_setting_key'] ?? ''));
            $ck = trim((string) ($rule['credit_setting_key'] ?? ''));
            if ($dk === '' || $ck === '') {
                throw new RuntimeException(
                    'قاعدة فاتورة مشتريات نقدي غير مكتملة — راجع «حسابات القيود التلقائية» (قسم ٢).'
                );
            }
            $purchaseDebit = $glAcct($dk);
            $cashCredit = $glAcct($ck);
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
    $pinId = $jtByCode('PIN');
    $rule = ($pinId > 0) ? orange_gl_journal_type_rule_for_terms($pdo, $pinId, 'cash') : null;
    if ($rule === null) {
        $inventoryId = $glAcct('inventory');
        $cashId = $glAcct('cash');

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
    $d = $glAcct($dk);
    $c = $glAcct($ck);
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
    float $amount,
    ?int $countryId = null
): array {
    if (!in_array($returnType, ['cash', 'credit'], true)) {
        throw new RuntimeException('نوع مردود المشتريات غير صالح');
    }

    $amount = round($amount, 4);
    $glCountryId = orange_purchase_gl_country_id($pdo, $countryId);
    $glAcct = static fn (string $key): int => orange_gl_account_id($pdo, $key, $glCountryId);
    $jtByCode = static fn (string $code): int => orange_journal_type_id_by_code($pdo, $code, $glCountryId);

    if ($returnType === 'credit') {
        if ($supplierId <= 0) {
            throw new RuntimeException('مردود مشتريات آجل يتطلّب مورداً مربوطاً بحساب ذمة.');
        }
        $apId = orange_supplier_required_payable_account_id($pdo, $supplierId);
        $pdnId = $jtByCode('PDN');
        $rule = ($pdnId > 0) ? orange_gl_journal_type_rule_for_terms($pdo, $pdnId, 'credit') : null;

        if ($rule === null) {
            $inventoryId = $glAcct('inventory');
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
        $d = $dk !== '' ? $glAcct($dk) : $apId;
        $c = $glAcct($ck);
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
        $pdnId = $jtByCode('PDN');
        $rule = ($pdnId > 0) ? orange_gl_journal_type_rule_for_terms($pdo, $pdnId, 'cash') : null;
        if ($rule === null) {
            $inventoryCr = $glAcct('inventory');
            $cashDr = $glAcct('cash');
        } else {
            $dk = trim((string) ($rule['debit_setting_key'] ?? ''));
            $ck = trim((string) ($rule['credit_setting_key'] ?? ''));
            if ($dk === '' || $ck === '') {
                throw new RuntimeException(
                    'قاعدة مردود مشتريات نقدي غير مكتملة — راجع «حسابات القيود التلقائية» (قسم ٢).'
                );
            }
            // نفس دلالة PIN نقدي: dk = جانب المخزون/المشتريات، ck = النقدية — السند معكوس محاسبياً.
            $inventoryCr = $glAcct($dk);
            $cashDr = $glAcct($ck);
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
    $pdnId = $jtByCode('PDN');
    $rule = ($pdnId > 0) ? orange_gl_journal_type_rule_for_terms($pdo, $pdnId, 'cash') : null;
    if ($rule === null) {
        $cashId = $glAcct('cash');
        $inventoryId = $glAcct('inventory');

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
    $cashSide = $glAcct($ck);
    $invSide = $glAcct($dk);
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

/**
 * خصم الفاتورة على المشتريات (قرار مالك 2026-06-13): لا يمسّ تكلفة المخزن.
 *
 * المخزون يُسجَّل بإجمالي صافي الأصناف (subtotal بعد خصم الأصناف فقط)، أما خصم الفاتورة
 * فيُثبَّت في «خصم مكتسب على المشتريات» (purchase_discount)، والتمويل (ذمة/نقد) يبقى على
 * الصافي. عند المردود يُعكَس الخصم المكتسب بحصة المردود (الخصم المُدخَل على المردود).
 *
 * يُستدعى بعد بناء الحزمة الأساسية بمبلغ **الصافي** (كما اليوم). يضيف زوجاً متوازناً:
 *   - شراء : مدين مخزون / دائن خصم مكتسب   (يرفع المخزون إلى الإجمالي ويثبت الخصم).
 *   - مردود: مدين خصم مكتسب / دائن مخزون   (يعكس حصة المردود من الخصم).
 * ويحوّل الحزمة إلى سند متعدد الأسطر عند الحاجة ليُرحَّل القيد المركّب في سند واحد.
 *
 * @param array<string,mixed> $glB
 * @return array<string,mixed>
 * @throws RuntimeException
 */
function orange_gl_purchase_apply_invoice_discount_lines(
    PDO $pdo,
    array $glB,
    float $netAmount,
    float $discountAmt,
    ?int $countryId,
    bool $isReturn
): array {
    $discountAmt = round($discountAmt, 4);
    if ($discountAmt <= 0.0005) {
        return $glB;
    }
    $netAmount = round($netAmount, 4);
    $glCountryId = orange_purchase_gl_country_id($pdo, $countryId);
    $inventoryId = orange_gl_account_id_optional($pdo, 'inventory', $glCountryId);
    $discId = orange_gl_account_id_optional($pdo, 'purchase_discount', $glCountryId);
    if ($discId === null || $discId <= 0) {
        throw new RuntimeException(
            'لتسجيل خصم فاتورة المشتريات اربط «حساب خصم مكتسب على المشتريات» في «حسابات القيود التلقائية».'
        );
    }
    if ($inventoryId === null || $inventoryId <= 0) {
        throw new RuntimeException('حساب المخزون غير مربوط في «حسابات القيود التلقائية».');
    }

    if ($isReturn) {
        $discPair = [
            ['account_id' => $discId, 'debit' => $discountAmt, 'credit' => 0.0, 'memo' => 'عكس خصم مكتسب على المشتريات (حصة المردود)'],
            ['account_id' => $inventoryId, 'debit' => 0.0, 'credit' => $discountAmt, 'memo' => 'مردود — تكلفة الأصناف بالصافي (لا تتأثر بخصم الفاتورة)'],
        ];
    } else {
        $discPair = [
            ['account_id' => $inventoryId, 'debit' => $discountAmt, 'credit' => 0.0, 'memo' => 'تكلفة الأصناف بالصافي (لا تتأثر بخصم الفاتورة)'],
            ['account_id' => $discId, 'debit' => 0.0, 'credit' => $discountAmt, 'memo' => 'إثبات خصم مكتسب على المشتريات'],
        ];
    }

    if (empty($glB['is_multi'])) {
        $debitAcct = (int) ($glB['debit'] ?? 0);
        $creditAcct = (int) ($glB['credit'] ?? 0);
        if ($debitAcct <= 0 || $creditAcct <= 0) {
            throw new RuntimeException('تعذر بناء قيد مركّب لخصم فاتورة المشتريات — حسابات الترحيل الأساسية غير مكتملة.');
        }
        $desc = (string) ($glB['voucher_description'] ?? '');
        $base = [
            ['account_id' => $debitAcct, 'debit' => $netAmount, 'credit' => 0.0, 'memo' => $desc],
            ['account_id' => $creditAcct, 'debit' => 0.0, 'credit' => $netAmount, 'memo' => $desc],
        ];
        $glB['is_multi'] = true;
        $glB['lines'] = array_merge($base, $discPair);
        $glB['debit'] = 0;
        $glB['credit'] = 0;
    } else {
        $glB['lines'] = array_merge((array) $glB['lines'], $discPair);
    }

    return $glB;
}
