<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/fiscal_years.php';
require_once __DIR__ . '/journal_types.php';

function orange_journal_vouchers_ready(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'journal_vouchers') && orange_table_exists($pdo, 'journal_lines');
}

/**
 * @return array{sql:string, params:list<int>}
 */
function orange_gl_voucher_country_bind(PDO $pdo, string $jvAlias = 'jv', ?int $countryId = null): array
{
    if (!orange_table_has_country_id($pdo, 'journal_vouchers')) {
        return ['sql' => '', 'params' => []];
    }
    if ($countryId === null) {
        require_once __DIR__ . '/countries.php';
        $countryId = orange_admin_context_country_id($pdo);
    }
    if ($countryId <= 0) {
        return ['sql' => '', 'params' => []];
    }
    $col = trim($jvAlias) !== '' ? trim($jvAlias) . '.country_id' : 'journal_vouchers.country_id';

    return ['sql' => ' AND ' . $col . ' = ?', 'params' => [$countryId]];
}

function orange_journal_voucher_resolve_country_id(PDO $pdo, array $header): int
{
    if (!orange_table_has_country_id($pdo, 'journal_vouchers')) {
        return 0;
    }
    if (isset($header['country_id']) && (int) $header['country_id'] > 0) {
        return (int) $header['country_id'];
    }
    require_once __DIR__ . '/countries.php';

    return orange_admin_context_country_id($pdo);
}

function orange_journal_voucher_stamp_country(PDO $pdo, int $voucherId, array $header): void
{
    if ($voucherId <= 0 || !orange_table_has_country_id($pdo, 'journal_vouchers')) {
        return;
    }
    $cid = orange_journal_voucher_resolve_country_id($pdo, $header);
    if ($cid <= 0) {
        return;
    }
    $pdo->prepare(
        'UPDATE journal_vouchers SET country_id = ? WHERE id = ? AND (country_id IS NULL OR country_id = 0)'
    )->execute([$cid, $voucherId]);
}

/**
 * @throws RuntimeException
 */
function orange_journal_voucher_assert_admin_context(PDO $pdo, int $voucherId): void
{
    if ($voucherId <= 0 || !orange_table_has_country_id($pdo, 'journal_vouchers')) {
        return;
    }
    require_once __DIR__ . '/countries.php';
    $ctx = orange_admin_context_country_id($pdo);
    if ($ctx <= 0) {
        return;
    }
    $st = $pdo->prepare('SELECT country_id FROM journal_vouchers WHERE id = ? LIMIT 1');
    $st->execute([$voucherId]);
    $rowCid = (int) ($st->fetchColumn() ?: 0);
    if ($rowCid > 0 && $rowCid !== $ctx) {
        throw new RuntimeException('السند لا يتبع الدولة المختارة في لوحة التحكم.');
    }
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
 * حذف قيود/طابور «استلام المخزون» المرتبطة بفاتورة شراء (PUR-{id}-RCV-*) عند تعديل أو حذف الفاتورة.
 *
 * @throws RuntimeException
 */
function orange_purchase_remove_receive_accounting(PDO $pdo, int $purchaseId): void
{
    orange_catalog_ensure_schema($pdo);
    if ($purchaseId <= 0) {
        return;
    }
    $like = 'PUR-' . $purchaseId . '-RCV-%';
    if (orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        $pdo->prepare(
            "DELETE FROM orange_gl_pending_movements WHERE reference LIKE ? AND entry_type = 'purchase_receive'"
        )->execute([$like]);
    }
    if (!orange_journal_vouchers_ready($pdo)) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT reference FROM journal_vouchers WHERE reference LIKE ? ORDER BY id ASC'
    );
    $st->execute([$like]);
    $refs = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($refs as $ref) {
        $r = trim((string) $ref);
        if ($r !== '') {
            orange_voucher_delete_by_reference($pdo, $r);
        }
    }
}

/**
 * حذف قيد مردود المشتريات (نفس منطق حذف قيد الشراء حسب المرجع).
 *
 * @throws RuntimeException
 */
function orange_purchase_return_remove_accounting(PDO $pdo, string $returnReference): void
{
    orange_purchase_remove_accounting($pdo, $returnReference);
}

/**
 * حذف قيود مردود مبيعات مستند (إيراد + تكلفة مجمّعة).
 *
 * @throws RuntimeException
 */
function orange_sales_return_remove_accounting(PDO $pdo, int $returnId): void
{
    orange_catalog_ensure_schema($pdo);
    $rs = 'SR-' . $returnId . '-RS';
    $rc = 'SR-' . $returnId . '-RC';
    if (orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        $pdo->prepare('DELETE FROM orange_gl_pending_movements WHERE reference IN (?,?)')->execute([$rs, $rc]);
    }
    orange_purchase_remove_accounting($pdo, $rs);
    orange_purchase_remove_accounting($pdo, $rc);
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
 * رقم العرض اليومية: التسلسل داخل سنة المالية وحسب دليل اليومية؛ يُكمِّل إلى id قبل التعبئة القديمة.
 *
 * @param array<string, mixed> $voucherRow
 */
function orange_journal_voucher_display_number(array $voucherRow): int
{
    $vs = isset($voucherRow['voucher_serial']) ? (int) $voucherRow['voucher_serial'] : 0;

    return $vs > 0 ? $vs : (int) ($voucherRow['id'] ?? 0);
}

/**
 * دليل واحد ضمن كل سنة لتسلسل قبض عميل آجل / صرف مورد مقابل عمومي؛ وقيم «جزء واحد لوغاريتماً» بالنسبة لأنواع دون كود وحيد.
 *
 * @return array{journal_type_id:int|null,journal_serial_bucket:string}
 */
function orange_journal_voucher_resolve_serial_meta(PDO $pdo, string $entryType, ?int $overrideJournalTypeId = null): array
{
    orange_catalog_ensure_schema($pdo);
    orange_journal_types_sync_canonical_defaults($pdo);
    $ov = (int) ($overrideJournalTypeId ?? 0);
    if ($ov > 0) {
        return ['journal_type_id' => $ov, 'journal_serial_bucket' => 'JT' . $ov];
    }
    $code = orange_journal_type_code_from_entry_type($entryType);
    if ($code !== '') {
        $jid = orange_journal_type_id_by_code($pdo, $code);
        if ($jid > 0) {
            return ['journal_type_id' => $jid, 'journal_serial_bucket' => 'JT' . $jid];
        }
    }
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($entryType));
    if ($safe === '') {
        $safe = 'unknown';
    }
    if (strlen($safe) > 40) {
        $safe = substr($safe, 0, 40);
    }

    return ['journal_type_id' => null, 'journal_serial_bucket' => 'ET:' . $safe];
}

function orange_journal_voucher_next_serial(PDO $pdo, int $fiscalYearId, string $bucket): int
{
    if ($fiscalYearId <= 0 || !orange_table_has_column($pdo, 'journal_vouchers', 'voucher_serial')) {
        return 1;
    }
    $st = $pdo->prepare(
        'SELECT COALESCE(MAX(voucher_serial), 0) + 1 FROM journal_vouchers WHERE fiscal_year_id = ? AND journal_serial_bucket = ?'
    );
    $st->execute([$fiscalYearId, $bucket]);

    return (int) $st->fetchColumn();
}

/**
 * تعبئة journal_serial_bucket + voucher_serial بعد إضافة أعمدة المخطّط (عملية واحدة ثقيلة عند وجود نقص).
 *
 * يُفرَض تنفيذه من catalog_schema؛ لا تعتمد عليه في مسار كل طلب خارج ensure_schema ما لم توجد نقائص فعلياً.
 */
function orange_journal_vouchers_backfill_serial_numbers(PDO $pdo): void
{
    if (!orange_journal_vouchers_ready($pdo)
        || !orange_table_has_column($pdo, 'journal_vouchers', 'voucher_serial')
        || !orange_table_has_column($pdo, 'journal_vouchers', 'journal_serial_bucket')) {
        return;
    }
    orange_journal_types_sync_canonical_defaults($pdo);
    $need = (int) $pdo->query(
        'SELECT COUNT(*) FROM journal_vouchers WHERE voucher_serial <= 0 OR TRIM(COALESCE(journal_serial_bucket,\'\')) = \'\''
    )->fetchColumn();
    if ($need <= 0) {
        return;
    }
    $rows = $pdo->query(
        'SELECT id, entry_type, fiscal_year_id, voucher_date FROM journal_vouchers WHERE voucher_serial <= 0 OR TRIM(COALESCE(journal_serial_bucket,\'\')) = \'\' ORDER BY COALESCE(fiscal_year_id,0) ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return;
    }
    $upd = $pdo->prepare(
        'UPDATE journal_vouchers SET journal_type_id = ?, journal_serial_bucket = ?, voucher_serial = ? WHERE id = ?'
    );
    $counters = [];
    foreach ($rows as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $et = trim((string) ($r['entry_type'] ?? 'general'));
        if ($et === '') {
            $et = 'general';
        }
        $meta = orange_journal_voucher_resolve_serial_meta($pdo, $et, null);
        $fyId = (int) ($r['fiscal_year_id'] ?? 0);
        $vdRaw = trim((string) ($r['voucher_date'] ?? ''));
        if ($fyId <= 0 && $vdRaw !== '') {
            orange_catalog_ensure_schema($pdo);
            $fy = orange_fiscal_find_for_date($pdo, $vdRaw);
            if ($fy) {
                $fyId = (int) ($fy['id']);
                $pdo->prepare(
                    'UPDATE journal_vouchers SET fiscal_year_id = ? WHERE id = ? AND (fiscal_year_id IS NULL OR fiscal_year_id <= 0)'
                )->execute([$fyId, $id]);
            }
        }
        if ($fyId <= 0) {
            $fyId = 0;
        }
        $b = $meta['journal_serial_bucket'];
        $jid = isset($meta['journal_type_id']) ? $meta['journal_type_id'] : null;
        $jidSql = ($jid !== null && (int) $jid > 0) ? (int) $jid : null;
        $key = $fyId . '|' . $b;
        $counters[$key] = ($counters[$key] ?? 0) + 1;
        $ser = $counters[$key];
        $upd->execute([$jidSql, $b, $ser, $id]);
    }
}

/**
 * @param array{voucher_date:string,reference?:?string,description:string,entry_type?:string,document_entered_at?:string,journal_type_id?:int} $header
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

    $voucherCountryId = orange_journal_voucher_resolve_country_id($pdo, $header);
    orange_gl_assert_voucher_accounts_country(
        $pdo,
        array_column($norm, 'account_id'),
        $voucherCountryId
    );

    $fyId = orange_fiscal_require_open_for_posting($pdo, $date);

    $overrideJt = isset($header['journal_type_id']) ? (int) $header['journal_type_id'] : 0;
    $metaSerial = orange_journal_voucher_resolve_serial_meta(
        $pdo,
        $entryType,
        $overrideJt > 0 ? $overrideJt : null
    );
    $hasSerialCols = orange_table_has_column($pdo, 'journal_vouchers', 'voucher_serial')
        && orange_table_has_column($pdo, 'journal_vouchers', 'journal_serial_bucket');
    $jidSerial = isset($metaSerial['journal_type_id']) && $metaSerial['journal_type_id'] !== null
        ? (int) $metaSerial['journal_type_id']
        : null;

    $chk = $pdo->prepare('SELECT id FROM accounts WHERE id = ? LIMIT 1');

    $docEntered = trim((string) ($header['document_entered_at'] ?? ''));
    if ($docEntered === '' || strlen($docEntered) < 8) {
        $docEntered = date('Y-m-d H:i:s');
    }
    if (strlen($docEntered) === 10) {
        $docEntered .= ' ' . date('H:i:s');
    }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    $vid = 0;
    try {
        $nextSerial = 1;
        if ($hasSerialCols) {
            $nextSerial = orange_journal_voucher_next_serial($pdo, $fyId, $metaSerial['journal_serial_bucket']);
        }
        $bucket = $metaSerial['journal_serial_bucket'];

        $inserted = false;
        $lastErr = null;
        if ($hasSerialCols && orange_table_has_column($pdo, 'journal_vouchers', 'journal_type_id')) {
            $maxDup = 12;
            for ($attempt = 0; $attempt < $maxDup; ++$attempt) {
                try {
                    if (orange_table_has_column($pdo, 'journal_vouchers', 'document_entered_at')) {
                        $pdo->prepare(
                            'INSERT INTO journal_vouchers (
                                voucher_date, document_entered_at, reference, description, entry_type, fiscal_year_id,
                                journal_type_id, journal_serial_bucket, voucher_serial
                             ) VALUES (?,?,?,?,?,?,?,?,?)'
                        )->execute([
                            $date,
                            $docEntered,
                            $referenceSql,
                            $description,
                            $entryType,
                            $fyId,
                            $jidSerial,
                            $bucket,
                            $nextSerial,
                        ]);
                    } else {
                        $pdo->prepare(
                            'INSERT INTO journal_vouchers (
                                voucher_date, reference, description, entry_type, fiscal_year_id,
                                journal_type_id, journal_serial_bucket, voucher_serial
                             ) VALUES (?,?,?,?,?,?,?,?)'
                        )->execute([
                            $date,
                            $referenceSql,
                            $description,
                            $entryType,
                            $fyId,
                            $jidSerial,
                            $bucket,
                            $nextSerial,
                        ]);
                    }
                    $inserted = true;
                    break;
                } catch (\PDOException $e) {
                    $lastErr = $e;
                    $dup = strpos($e->getMessage(), 'Duplicate') !== false
                        || (int) ($e->errorInfo[1] ?? 0) === 1062;
                    if (!$dup || $attempt >= $maxDup - 1) {
                        throw $e;
                    }
                    $nextSerial = orange_journal_voucher_next_serial($pdo, $fyId, $bucket);
                }
            }
            if (!$inserted) {
                throw $lastErr instanceof Throwable
                    ? $lastErr
                    : new RuntimeException('تعذر تعيين رقم قيد لتسلسل اليومية.');
            }
        } elseif (orange_table_has_column($pdo, 'journal_vouchers', 'document_entered_at')) {
            $pdo->prepare(
                'INSERT INTO journal_vouchers (voucher_date, document_entered_at, reference, description, entry_type, fiscal_year_id) VALUES (?,?,?,?,?,?)'
            )->execute([$date, $docEntered, $referenceSql, $description, $entryType, $fyId]);
        } else {
            $pdo->prepare(
                'INSERT INTO journal_vouchers (voucher_date, reference, description, entry_type, fiscal_year_id) VALUES (?,?,?,?,?)'
            )->execute([$date, $referenceSql, $description, $entryType, $fyId]);
        }
        $vid = (int) $pdo->lastInsertId();
        orange_journal_voucher_stamp_country($pdo, $vid, $header);

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

        if ($ownTx && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
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
 * تحديث سند موجود (نفس entry_type) مع استبدال الأسطر — للشاشات اليدوية فقط حسب حماية الـ API.
 *
 * @param array{voucher_date:string,reference?:string,description:string} $header
 * @param list<array{account_id:int,debit:float,credit:float,memo:string}> $postLines
 *
 * @throws InvalidArgumentException
 */
function orange_voucher_update_multiline(PDO $pdo, int $voucherId, array $header, array $postLines): void
{
    if (!orange_journal_vouchers_ready($pdo)) {
        throw new RuntimeException('جداول السندات غير جاهزة.');
    }
    if ($voucherId <= 0) {
        throw new InvalidArgumentException('معرف السند غير صالح.');
    }
    $ex = $pdo->prepare('SELECT id FROM journal_vouchers WHERE id = ? LIMIT 1');
    $ex->execute([$voucherId]);
    if (!$ex->fetch()) {
        throw new InvalidArgumentException('السند غير موجود.');
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

    $totalD = 0.0;
    $totalC = 0.0;
    $norm = [];
    $lineNo = 0;
    foreach ($postLines as $ln) {
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

    $voucherCountryId = orange_journal_voucher_resolve_country_id($pdo, $header);
    orange_gl_assert_voucher_accounts_country(
        $pdo,
        array_column($norm, 'account_id'),
        $voucherCountryId
    );

    $fyId = orange_fiscal_require_open_for_posting($pdo, $date);
    $chk = $pdo->prepare('SELECT id FROM accounts WHERE id = ? LIMIT 1');

    $hasUpdatedAt = orange_table_has_column($pdo, 'journal_vouchers', 'updated_at');
    $updSql = $hasUpdatedAt
        ? 'UPDATE journal_vouchers SET voucher_date = ?, reference = ?, description = ?, fiscal_year_id = ?, updated_at = NOW() WHERE id = ?'
        : 'UPDATE journal_vouchers SET voucher_date = ?, reference = ?, description = ?, fiscal_year_id = ? WHERE id = ?';

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare($updSql);
        $upd->execute([$date, $referenceSql, $description, $fyId, $voucherId]);
        $pdo->prepare('DELETE FROM journal_lines WHERE voucher_id = ?')->execute([$voucherId]);
        $ins = $pdo->prepare(
            'INSERT INTO journal_lines (voucher_id, line_no, account_id, debit, credit, memo) VALUES (?,?,?,?,?,?)'
        );
        foreach ($norm as $row) {
            $chk->execute([$row['account_id']]);
            if (!$chk->fetch()) {
                throw new InvalidArgumentException('حساب غير موجود في الدليل: ' . $row['account_id']);
            }
            $ins->execute([
                $voucherId,
                $row['line_no'],
                $row['account_id'],
                $row['debit'],
                $row['credit'],
                $row['memo'] === '' ? null : $row['memo'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
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
    $countryBind = orange_gl_voucher_country_bind($pdo, 'jv');
    $sql .= $countryBind['sql'];
    foreach ($countryBind['params'] as $cp) {
        $params[] = $cp;
    }
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

/**
 * أرصدة مجمّعة لكل حساب ضمن مدى **تاريخ السند** (تقارير تقويمية بلا ربط بحصرية السنة المالية).
 * يُقارَن بـ **تاريخ التقويم** لأن `voucher_date` مخزّن كـ DATETIME — المقارنة بسلسلة يوم فقط (`<= '2026-04-30'`)
 * كانت تستبعد أي سند مسجَّل بعد منتصف ليل آخر يوم.
 *
 * @param list<string> $excludeEntryTypes أنواع سندات تُستبعد
 * @return array<int, array{debit:float,credit:float}>
 */
function orange_voucher_account_totals_by_voucher_date_range(
    PDO $pdo,
    string $dateFromYmd,
    string $dateToYmd,
    array $excludeEntryTypes = []
): array {
    if (! orange_journal_vouchers_ready($pdo)) {
        return [];
    }
    $dateFromYmd = trim($dateFromYmd);
    $dateToYmd = trim($dateToYmd);
    if ($dateFromYmd === '' || $dateToYmd === '' || strcmp($dateFromYmd, $dateToYmd) > 0) {
        return [];
    }
    $sql = 'SELECT jl.account_id, COALESCE(SUM(jl.debit),0) AS d, COALESCE(SUM(jl.credit),0) AS c
            FROM journal_lines jl
            INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
            WHERE DATE(jv.voucher_date) >= ? AND DATE(jv.voucher_date) <= ?';
    $params = [$dateFromYmd, $dateToYmd];
    $countryBind = orange_gl_voucher_country_bind($pdo, 'jv');
    $sql .= $countryBind['sql'];
    foreach ($countryBind['params'] as $cp) {
        $params[] = $cp;
    }
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

/**
 * أرصدة مجمّعة لكل حساب من سندات بتاريخ **أقدم من** بداية اليوم المعرّف (`DATE(voucher_date) < ?`) — رصيد أول الفترة.
 *
 * @param list<string> $excludeEntryTypes
 * @return array<int, array{debit:float,credit:float}>
 */
function orange_voucher_account_totals_strictly_before_date(
    PDO $pdo,
    string $beforeDateYmd,
    array $excludeEntryTypes = []
): array {
    if (! orange_journal_vouchers_ready($pdo)) {
        return [];
    }
    $beforeDateYmd = trim($beforeDateYmd);
    if ($beforeDateYmd === '') {
        return [];
    }
    $sql = 'SELECT jl.account_id, COALESCE(SUM(jl.debit),0) AS d, COALESCE(SUM(jl.credit),0) AS c
            FROM journal_lines jl
            INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
            WHERE DATE(jv.voucher_date) < ?';
    $params = [$beforeDateYmd];
    $countryBind = orange_gl_voucher_country_bind($pdo, 'jv');
    $sql .= $countryBind['sql'];
    foreach ($countryBind['params'] as $cp) {
        $params[] = $cp;
    }
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
