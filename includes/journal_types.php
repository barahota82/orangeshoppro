<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * @return list<array<string, mixed>>
 */
function orange_journal_types_list(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'journal_types')) {
        return [];
    }

    return $pdo->query('SELECT * FROM journal_types ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * ترميز اللاتيني للبادئة (أحرف وأرقام فقط، يُحوَّل لكبير).
 */
function orange_journal_type_normalize_code(string $raw): string
{
    $s = strtoupper(preg_replace('/\s+/', '', trim($raw)));
    $s = preg_replace('/[^A-Z0-9]/', '', $s);

    return $s ?? '';
}

/**
 * التعريف المرجعي لأنواع اليوميات (الترتيب = sort_order).
 *
 * @return list<array{0:string,1:string,2:string}>
 */
function orange_journal_types_canonical_rows(): array
{
    return [
        ['OBV', 'سند رصيد افتتاحي', 'Opening balance voucher'],
        ['JE', 'سند قيد', 'Journal entry'],
        ['RV', 'سند قبض', 'Receipt voucher'],
        ['CRR', 'قبض عميل آجل', 'Customer AR receipt'],
        ['PV', 'سند صرف', 'Payment voucher'],
        ['SPR', 'صرف مورد آجل', 'Supplier AP payment'],
        ['YEC', 'قيد الإقفال السنوي', 'Year-end closing entry'],
        ['PIN', 'فاتورة مشتريات', 'Purchase invoice'],
        ['PDN', 'مردود مشتريات', 'Purchase return'],
        ['CSI', 'مبيعات نقدي', 'Cash sales'],
        ['CGC', 'تكلفة مبيعات نقدي', 'Cost of cash sales'],
        ['SCR', 'مردود مبيعات نقدي', 'Cash sales return'],
        ['CSR', 'تكلفة مردود مبيعات نقدي', 'Cost of cash sales return'],
        ['SIN', 'مبيعات أجل', 'Credit sales'],
        ['CGT', 'تكلفة مبيعات أجل', 'Cost of credit sales'],
        ['SRR', 'مردود مبيعات أجل', 'Credit sales return'],
        ['CGR', 'تكلفة مردود مبيعات أجل', 'Cost of credit sales return'],
        ['OSI', 'مبيعات الاونلاين', 'Online sales'],
        ['CGO', 'تكلفة مبيعات الاونلاين', 'Cost of online sales'],
        ['OSR', 'مردود مبيعات الاونلاين', 'Online sales return'],
        ['COR', 'تكلفة مردود مبيعات الاونلاين', 'Cost of online sales return'],
    ];
}

/**
 * دمج قائمة الأنواع المرجعية في الجدول: يحدّث الأسماء والترتيب للأكواد الموجودة ويُدخل الناقص.
 * يُستدعى عند الحاجة أكثر من مرة في نفس الطلب (مثلاً استعادة بعد جدول فارغ).
 *
 * @throws Throwable عند فشل المعاملة
 */
function orange_journal_types_merge_canonical_defaults(PDO $pdo): void
{
    if (!orange_table_exists($pdo, 'journal_types')) {
        return;
    }

    $rows = orange_journal_types_canonical_rows();
    $sel = $pdo->prepare('SELECT id FROM journal_types WHERE code = ? LIMIT 1');
    $ins = $pdo->prepare(
        'INSERT INTO journal_types (code, name_ar, name_en, sort_order) VALUES (?,?,?,?)'
    );
    $upd = $pdo->prepare(
        'UPDATE journal_types SET name_ar = ?, name_en = ?, sort_order = ? WHERE id = ?'
    );

    $pdo->beginTransaction();
    try {
        foreach ($rows as $idx => $r) {
            $ord = $idx + 1;
            $code = $r[0];
            $sel->execute([$code]);
            $id = $sel->fetchColumn();
            if ($id !== false && (int) $id > 0) {
                $upd->execute([$r[1], $r[2], $ord, (int) $id]);
            } else {
                $ins->execute([$code, $r[1], $r[2], $ord]);
            }
        }
        orange_journal_types_retire_obsolete_exp_type($pdo);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * إزالة نوع اليومية EXP (قيد مصروف) بعد الاعتماد على سند الصرف؛ يُنظّف القواعد والارتباطات ثم يحذف الصف.
 */
function orange_journal_types_retire_obsolete_exp_type(PDO $pdo): void
{
    if (!orange_table_exists($pdo, 'journal_types')) {
        return;
    }
    $st = $pdo->prepare('SELECT id FROM journal_types WHERE UPPER(TRIM(code)) = ? LIMIT 1');
    $st->execute(['EXP']);
    $raw = $st->fetchColumn();
    if ($raw === false || $raw === null) {
        return;
    }
    $expId = (int) $raw;
    if ($expId <= 0) {
        return;
    }
    if (orange_table_exists($pdo, 'orange_gl_journal_type_rules')) {
        $pdo->prepare('DELETE FROM orange_gl_journal_type_rules WHERE journal_type_id = ?')->execute([$expId]);
    }
    if (orange_table_has_column($pdo, 'orange_gl_account_settings', 'journal_type_id')) {
        $pdo->prepare('UPDATE orange_gl_account_settings SET journal_type_id = NULL WHERE journal_type_id = ?')->execute([$expId]);
    }
    $pdo->prepare('DELETE FROM journal_types WHERE id = ?')->execute([$expId]);
}

/**
 * يضمن وجود الصفوف المرجعية وترتيبها (يُستدعى من catalog_schema).
 * مرة واحدة لكل طلب HTTP كحد أقصى لتفادي تكرار المعاملة عند عدة استدعاءات ensure_schema.
 */
function orange_journal_types_sync_canonical_defaults(PDO $pdo): void
{
    static $done = false;
    if ($done || !orange_table_exists($pdo, 'journal_types')) {
        return;
    }

    orange_journal_types_merge_canonical_defaults($pdo);
    $done = true;
}

/**
 * Map journal_types.code (canonical) to orange_gl_pending_movements.entry_type values.
 * Unknown or unmapped codes return [] (caller should not apply entry_type filter).
 *
 * @return list<string>
 */
function orange_gl_entry_types_for_journal_type_code(string $code): array
{
    $code = orange_journal_type_normalize_code($code);
    static $map = [
        'OBV' => ['opening_balance'],
        'JE' => ['manual', 'general', 'other_voucher'],
        'RV' => ['receipt_voucher'],
        'PV' => ['payment_voucher', 'expense', 'expense_adjustment', 'expense_reversal'],
        'CRR' => ['customer_receipt'],
        'SPR' => ['supplier_payment'],
        'YEC' => ['year_end_close'],
        'PIN' => ['purchase', 'purchase_receive'],
        'PDN' => ['purchase_return'],
        'CSI' => ['order_delivery_sale'],
        'SIN' => ['order_delivery_sale'],
        'OSI' => ['order_delivery_sale'],
        'CGC' => ['order_delivery_cogs'],
        'CGT' => ['order_delivery_cogs'],
        'CGO' => ['order_delivery_cogs'],
        'SCR' => ['order_return_sale'],
        'SRR' => ['order_return_sale'],
        'OSR' => ['order_return_sale'],
        'CSR' => ['order_return_cogs'],
        'CGR' => ['order_return_cogs'],
        'COR' => ['order_return_cogs'],
    ];
    return $map[$code] ?? [];
}

/**
 * @return list<string>
 */
function orange_gl_entry_types_for_journal_type_id(PDO $pdo, int $journalTypeId): array
{
    if ($journalTypeId <= 0 || !orange_table_exists($pdo, 'journal_types')) {
        return [];
    }
    $st = $pdo->prepare('SELECT code FROM journal_types WHERE id = ? LIMIT 1');
    $st->execute([$journalTypeId]);
    $code = (string) $st->fetchColumn();
    if ($code === '') {
        return [];
    }
    return orange_gl_entry_types_for_journal_type_code($code);
}

function orange_journal_type_code_by_id(PDO $pdo, int $id): string
{
    if ($id <= 0 || !orange_table_exists($pdo, 'journal_types')) {
        return '';
    }
    $st = $pdo->prepare('SELECT code FROM journal_types WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $c = $st->fetchColumn();
    if ($c === false || $c === null) {
        return '';
    }

    return orange_journal_type_normalize_code((string) $c);
}

function orange_journal_type_id_by_code(PDO $pdo, string $code): int
{
    $norm = orange_journal_type_normalize_code($code);
    if ($norm === '' || !orange_table_exists($pdo, 'journal_types')) {
        return 0;
    }
    orange_journal_types_sync_canonical_defaults($pdo);
    $st = $pdo->prepare('SELECT id FROM journal_types WHERE code = ? LIMIT 1');
    $st->execute([$norm]);
    $id = $st->fetchColumn();

    return ($id !== false && $id !== null) ? (int) $id : 0;
}

/**
 * مطابقة كود journal_types من entry_type عندما يكون المسار فريداً.
 * القيم الفارغة تعني أن النوع يشارك عدة يوميات (مثل order_delivery_sale) — يُستخدم دلو تسلسل منفصل.
 */
function orange_journal_type_code_from_entry_type(string $entryType): string
{
    $k = strtolower(trim($entryType));
    static $map = [
        'opening_balance' => 'OBV',
        'manual' => 'JE',
        'general' => 'JE',
        'other_voucher' => 'JE',
        'migrated' => 'JE',
        'receipt_voucher' => 'RV',
        'payment_voucher' => 'PV',
        'customer_receipt' => 'CRR',
        'supplier_payment' => 'SPR',
        'year_end_close' => 'YEC',
        'purchase' => 'PIN',
        'purchase_receive' => 'PIN',
        'purchase_return' => 'PDN',
        'expense' => 'PV',
        'expense_adjustment' => 'PV',
        'expense_reversal' => 'PV',
    ];

    return $map[$k] ?? '';
}
