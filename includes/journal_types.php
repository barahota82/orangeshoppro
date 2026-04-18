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
        ['PV', 'سند صرف', 'Payment voucher'],
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
 * يضمن وجود الصفوف المرجعية وترتيبها في القواعد التي أُنشئت قبل تحديث الزرع.
 * يُستدعى مرة واحدة لكل طلب HTTP كحد أقصى.
 */
function orange_journal_types_sync_canonical_defaults(PDO $pdo): void
{
    static $done = false;
    if ($done || !orange_table_exists($pdo, 'journal_types')) {
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
        $pdo->commit();
        $done = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
