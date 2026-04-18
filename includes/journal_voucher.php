<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/fiscal_years.php';

function orange_journal_vouchers_ready(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'journal_vouchers') && orange_table_exists($pdo, 'journal_lines');
}

/**
 * @return array<string, mixed>|null
 */
function orange_voucher_by_reference(PDO $pdo, string $reference): ?array
{
    if (!orange_journal_vouchers_ready($pdo)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM journal_vouchers WHERE reference = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$reference]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function orange_fiscal_is_closed_for_voucher(PDO $pdo, array $voucherRow): bool
{
    orange_catalog_ensure_schema($pdo);
    $fyId = (int) ($voucherRow['fiscal_year_id'] ?? 0);
    if ($fyId > 0 && orange_table_exists($pdo, 'fiscal_years')) {
        $st = $pdo->prepare('SELECT is_closed FROM fiscal_years WHERE id = ? LIMIT 1');
        $st->execute([$fyId]);

        return (int) $st->fetchColumn() === 1;
    }
    $d = (string) ($voucherRow['voucher_date'] ?? '');

    $fy = orange_fiscal_find_for_date($pdo, $d);

    return $fy ? ((int) $fy['is_closed'] === 1) : false;
}

/**
 * @throws RuntimeException
 */
function orange_voucher_delete_by_reference(PDO $pdo, string $reference): void
{
    if (!orange_journal_vouchers_ready($pdo)) {
        return;
    }
    $v = orange_voucher_by_reference($pdo, $reference);
    if (!$v) {
        return;
    }
    if (orange_fiscal_is_closed_for_voucher($pdo, $v)) {
        throw new RuntimeException('لا يمكن حذف سند مرتبط بسنة مالية مغلقة.');
    }
    $pdo->prepare('DELETE FROM journal_vouchers WHERE id = ?')->execute([(int) $v['id']]);
}

/**
 * حذف قيد المشتريات (سند أو الجدول القديم journal_entries).
 *
 * @throws RuntimeException
 */
function orange_purchase_remove_accounting(PDO $pdo, string $purchaseReference): void
{
    orange_catalog_ensure_schema($pdo);
    if (orange_journal_vouchers_ready($pdo)) {
        $v = orange_voucher_by_reference($pdo, $purchaseReference);
        if ($v) {
            orange_voucher_delete_by_reference($pdo, $purchaseReference);

            return;
        }
    }
    if (!orange_table_exists($pdo, 'journal_entries')) {
        return;
    }
    $st = $pdo->prepare('SELECT * FROM journal_entries WHERE reference = ? LIMIT 1');
    $st->execute([$purchaseReference]);
    $j = $st->fetch(PDO::FETCH_ASSOC);
    if ($j && orange_fiscal_is_closed_for_entry($pdo, $j)) {
        throw new RuntimeException('لا يمكن حذف قيد شراء في سنة مالية مغلقة.');
    }
    $pdo->prepare('DELETE FROM journal_entries WHERE reference = ?')->execute([$purchaseReference]);
}

/**
 * @return array<string, mixed>|null صف للتحقق من إغلاق السنة (سند أو قيد قديم)
 */
function orange_accounting_row_by_reference(PDO $pdo, string $reference): ?array
{
    orange_catalog_ensure_schema($pdo);
    if (orange_journal_vouchers_ready($pdo)) {
        $v = orange_voucher_by_reference($pdo, $reference);
        if ($v) {
            return $v;
        }
    }
    if (!orange_table_exists($pdo, 'journal_entries')) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM journal_entries WHERE reference = ? LIMIT 1');
    $st->execute([$reference]);
    $j = $st->fetch(PDO::FETCH_ASSOC);

    return $j ?: null;
}

function orange_accounting_is_locked(PDO $pdo, ?array $row): bool
{
    if ($row === null) {
        return false;
    }
    if (isset($row['voucher_date'])) {
        return orange_fiscal_is_closed_for_voucher($pdo, $row);
    }

    return orange_fiscal_is_closed_for_entry($pdo, $row);
}

/**
 * @param array{voucher_date:string,reference?:?string,description:string,entry_type?:string} $header
 * @param list<array{account_id:int,debit:float,credit:float,memo?:string}> $lines
 * @return int voucher id
 */
function orange_voucher_post(PDO $pdo, array $header, array $lines): int
{
    if (!orange_journal_vouchers_ready($pdo)) {
        throw new RuntimeException('جداول السندات غير جاهزة.');
    }
    $date = (string) ($header['voucher_date'] ?? '');
    if ($date === '') {
        $date = date('Y-m-d H:i:s');
    }
    if (strlen($date) === 10) {
        $date .= ' 12:00:00';
    }
    $description = trim((string) ($header['description'] ?? ''));
    if ($description === '') {
        throw new InvalidArgumentException('بيان السند مطلوب.');
    }
    $reference = array_key_exists('reference', $header) ? trim((string) $header['reference']) : '';
    $referenceSql = $reference === '' ? null : $reference;
    $entryType = trim((string) ($header['entry_type'] ?? 'general'));
    if ($entryType === '') {
        $entryType = 'general';
    }

    $totalD = 0.0;
    $totalC = 0.0;
    $norm = [];
    $lineNo = 0;
    foreach ($lines as $ln) {
        $aid = (int) ($ln['account_id'] ?? 0);
        $d = round((float) ($ln['debit'] ?? 0), 4);
        $c = round((float) ($ln['credit'] ?? 0), 4);
        if ($aid <= 0) {
            throw new InvalidArgumentException('حساب غير صالح في سطر السند.');
        }
        if ($d < 0 || $c < 0) {
            throw new InvalidArgumentException('لا يقبل السند سالباً في المدين أو الدائن.');
        }
        if ($d > 0 && $c > 0) {
            throw new InvalidArgumentException('كل سطر إما مدين أو دائن فقط.');
        }
        if ($d === 0.0 && $c === 0.0) {
            continue;
        }
        $memo = trim((string) ($ln['memo'] ?? ''));
        if ($memo === '') {
            throw new InvalidArgumentException('بيان السطر مطلوب لكل بند في السند.');
        }
        $norm[] = ['account_id' => $aid, 'debit' => $d, 'credit' => $c, 'memo' => $memo, 'line_no' => ++$lineNo];
        $totalD += $d;
        $totalC += $c;
    }
    if ($norm === []) {
        throw new InvalidArgumentException('السند بدون أسطر.');
    }
    if (round($totalD - $totalC, 4) !== 0.0) {
        throw new InvalidArgumentException('السند غير متوازن: مجموع المدين ' . $totalD . ' ≠ مجموع الدائن ' . $totalC);
    }

    $fyId = orange_fiscal_require_open_for_posting($pdo, $date);

    $chk = $pdo->prepare('SELECT id FROM accounts WHERE id = ? LIMIT 1');

    $pdo->prepare(
        'INSERT INTO journal_vouchers (voucher_date, reference, description, entry_type, fiscal_year_id) VALUES (?,?,?,?,?)'
    )->execute([$date, $referenceSql, $description, $entryType, $fyId]);
    $vid = (int) $pdo->lastInsertId();

    $ins = $pdo->prepare(
        'INSERT INTO journal_lines (voucher_id, line_no, account_id, debit, credit, memo) VALUES (?,?,?,?,?,?)'
    );
    foreach ($norm as $row) {
        $chk->execute([$row['account_id']]);
        if (!$chk->fetch()) {
            $pdo->prepare('DELETE FROM journal_vouchers WHERE id = ?')->execute([$vid]);
            throw new InvalidArgumentException('حساب غير موجود في الدليل: ' . $row['account_id']);
        }
        $ins->execute([
            $vid,
            $row['line_no'],
            $row['account_id'],
            $row['debit'],
            $row['credit'],
            $row['memo'] === '' ? null : $row['memo'],
        ]);
    }

    return $vid;
}

/**
 * قيد بسيط سطرين (مدين / دائن) — متوافق مع الاستدعاءات القديمة.
 *
 * @param array{
 *   account_debit:int,account_credit:int,amount:float,description:string,
 *   reference?:string|null,entry_type?:string|null,date?:string
 * } $row
 * @return int معرف السند
 */
function orange_journal_insert_line(PDO $pdo, array $row): int
{
    orange_catalog_ensure_schema($pdo);
    $debit = (int) ($row['account_debit'] ?? 0);
    $credit = (int) ($row['account_credit'] ?? 0);
    $amount = round((float) ($row['amount'] ?? 0), 4);
    $description = trim((string) ($row['description'] ?? ''));
    $reference = array_key_exists('reference', $row) ? trim((string) $row['reference']) : '';
    $entryType = trim((string) ($row['entry_type'] ?? 'general'));
    $date = isset($row['date']) && $row['date'] !== '' ? (string) $row['date'] : date('Y-m-d H:i:s');

    if ($debit <= 0 || $credit <= 0 || $amount <= 0 || $description === '') {
        throw new InvalidArgumentException('بيانات القيد غير مكتملة (حسابات المدين/الدائن، المبلغ، البيان).');
    }

    return orange_voucher_post($pdo, [
        'voucher_date' => $date,
        'reference' => $reference !== '' ? $reference : null,
        'description' => $description,
        'entry_type' => $entryType !== '' ? $entryType : 'general',
    ], [
        ['account_id' => $debit, 'debit' => $amount, 'credit' => 0, 'memo' => $description],
        ['account_id' => $credit, 'debit' => 0, 'credit' => $amount, 'memo' => $description],
    ]);
}

/**
 * أرصدة مجمّعة لكل حساب ضمن سنة مالية.
 *
 * @param list<string> $excludeEntryTypes أنواع سندات تُستبعد (مثلاً قائمة دخل)
 * @return array<int, array{debit:float,credit:float}>
 */
function orange_voucher_account_totals(PDO $pdo, int $fiscalYearId, array $excludeEntryTypes = []): array
{
    if (!orange_journal_vouchers_ready($pdo) || $fiscalYearId <= 0) {
        return [];
    }
    $sql = 'SELECT jl.account_id, COALESCE(SUM(jl.debit),0) AS d, COALESCE(SUM(jl.credit),0) AS c
            FROM journal_lines jl
            INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
            WHERE jv.fiscal_year_id = ?';
    $params = [$fiscalYearId];
    if ($excludeEntryTypes !== []) {
        $placeholders = implode(',', array_fill(0, count($excludeEntryTypes), '?'));
        $sql .= ' AND jv.entry_type NOT IN (' . $placeholders . ')';
        foreach ($excludeEntryTypes as $t) {
            $params[] = $t;
        }
    }
    $sql .= ' GROUP BY jl.account_id';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $out = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $out[(int) $r['account_id']] = [
            'debit' => (float) $r['d'],
            'credit' => (float) $r['c'],
        ];
    }

    return $out;
}
