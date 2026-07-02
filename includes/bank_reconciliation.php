<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/acc10_schema.php';
require_once __DIR__ . '/account_tree.php';
require_once __DIR__ . '/cash_flow_report.php';
require_once __DIR__ . '/journal_voucher.php';
require_once __DIR__ . '/fiscal_years.php';
require_once __DIR__ . '/gl_pending_movements.php';
require_once __DIR__ . '/admin_settings_country.php';

function orange_bank_reconciliation_ready(PDO $pdo): bool
{
    orange_catalog_ensure_schema($pdo);
    orange_catalog_ensure_acc10_schema($pdo);

    return orange_table_exists($pdo, 'bank_reconciliation')
        && orange_table_exists($pdo, 'bank_reconciliation_line');
}

/**
 * @return list<array{id:int,code:string,name:string}>
 */
function orange_bank_reconciliation_bank_account_options(PDO $pdo): array
{
    $leaves = orange_cash_flow_leaf_rows($pdo);
    $mapById = orange_cash_flow_map_by_id_from_leaves($pdo, $leaves);
    $cashIds = array_fill_keys(orange_cash_flow_cash_account_ids($pdo, $leaves, $mapById), true);

    $out = [];
    foreach ($leaves as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $bs = orange_accounts_bs_bucket_for_report($pdo, $id, $mapById[$id] ?? null);
        $rl = strtolower(trim((string) ($row['report_line_master_code'] ?? '')));
        if (! isset($cashIds[$id]) && $bs !== 'asset' && $rl !== 'cash_and_equivalents') {
            continue;
        }
        $code = trim((string) ($row['code'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $out[] = [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'label' => ($code !== '' ? $code . ' — ' : '') . $name,
        ];
    }

    usort($out, static fn (array $a, array $b): int => strcmp($a['code'] . $a['name'], $b['code'] . $b['name']));

    return $out;
}

function orange_bank_reconciliation_gl_balance(PDO $pdo, int $accountId, string $asOfYmd): float
{
    if ($accountId <= 0 || $asOfYmd === '' || ! orange_journal_vouchers_ready($pdo)) {
        return 0.0;
    }
    $ts = strtotime($asOfYmd . ' 12:00:00');
    $dayAfter = $ts ? date('Y-m-d', strtotime('+1 day', $ts)) : $asOfYmd;
    $tb = orange_voucher_account_totals_strictly_before_date($pdo, $dayAfter, ['year_end_close']);

    return orange_cash_flow_balance_net($tb, $accountId, 'asset');
}

/**
 * @return array<string, mixed>|null
 */
function orange_bank_reconciliation_get(PDO $pdo, int $id, ?int $countryId = null): ?array
{
    if ($id <= 0 || ! orange_bank_reconciliation_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM bank_reconciliation WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (! $row) {
        return null;
    }

    if ($countryId !== null && $countryId > 0 && isset($row['country_id']) && (int) $row['country_id'] > 0) {
        if ((int) $row['country_id'] !== $countryId) {
            return null;
        }
    }

    $periodTo = trim((string) ($row['period_to'] ?? ''));
    if ($periodTo === '') {
        $periodTo = date('Y-m-d');
    }
    $accountId = (int) ($row['account_id'] ?? 0);
    $glLive = orange_bank_reconciliation_gl_balance($pdo, $accountId, $periodTo);

    $stL = $pdo->prepare(
        'SELECT id, line_date, description, amount, sort_order, source
         FROM bank_reconciliation_line WHERE reconciliation_id = ? ORDER BY sort_order ASC, id ASC'
    );
    $stL->execute([$id]);
    $lines = $stL->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmtBal = (float) ($row['statement_balance'] ?? 0);
    $variance = round($stmtBal - $glLive, 4);

    return [
        'header' => $row,
        'lines' => $lines,
        'gl_balance_live' => $glLive,
        'variance_live' => $variance,
        'lines_sum' => round(array_sum(array_map(static fn (array $ln): float => (float) ($ln['amount'] ?? 0), $lines)), 4),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function orange_bank_reconciliation_list(PDO $pdo, ?int $countryId = null, int $limit = 50): array
{
    if (! orange_bank_reconciliation_ready($pdo)) {
        return [];
    }

    $sql = 'SELECT br.*, a.code AS account_code, a.name AS account_name
            FROM bank_reconciliation br
            INNER JOIN accounts a ON a.id = br.account_id
            WHERE 1=1';
    $params = [];
    if ($countryId !== null && $countryId > 0 && orange_table_has_column($pdo, 'bank_reconciliation', 'country_id')) {
        $sql .= ' AND (br.country_id IS NULL OR br.country_id = ?)';
        $params[] = $countryId;
    }
    $sql .= ' ORDER BY br.id DESC LIMIT ' . max(1, min(200, $limit));

    if ($params !== []) {
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param list<array{line_date?:string,description?:string,amount?:float|int|string,source?:string}> $linesIn
 *
 * @return list<array{line_date:?string,description:string,amount:float,source:string}>
 */
function orange_bank_reconciliation_normalize_lines(array $linesIn): array
{
    $out = [];
    foreach ($linesIn as $ln) {
        if (! is_array($ln)) {
            continue;
        }
        $dateRaw = trim((string) ($ln['line_date'] ?? $ln['date'] ?? ''));
        $date = null;
        if ($dateRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)) {
            $date = $dateRaw;
        }
        $desc = trim((string) ($ln['description'] ?? $ln['desc'] ?? ''));
        $amt = round((float) ($ln['amount'] ?? 0), 4);
        if ($desc === '' && abs($amt) < 0.0001) {
            continue;
        }
        $src = strtolower(trim((string) ($ln['source'] ?? 'manual')));
        if (! in_array($src, ['manual', 'import'], true)) {
            $src = 'manual';
        }
        $out[] = [
            'line_date' => $date,
            'description' => $desc !== '' ? $desc : '—',
            'amount' => $amt,
            'source' => $src,
        ];
    }

    return $out;
}

/**
 * Parse CSV text (UTF-8, optional BOM). Columns: date, description, amount.
 *
 * @return list<array{line_date:?string,description:string,amount:float,source:string}>
 */
function orange_bank_reconciliation_parse_csv(string $csvText): array
{
    $csvText = preg_replace('/^\xEF\xBB\xBF/', '', $csvText) ?? $csvText;
    $csvText = trim($csvText);
    if ($csvText === '') {
        return [];
    }

    $fp = fopen('php://temp', 'r+');
    if ($fp === false) {
        return [];
    }
    fwrite($fp, $csvText);
    rewind($fp);

    $rows = [];
    $header = null;
    $lineNo = 0;
    while (($cols = fgetcsv($fp)) !== false) {
        ++$lineNo;
        if ($cols === [null] || $cols === false) {
            continue;
        }
        $cols = array_map(static fn ($c) => trim((string) $c), $cols);
        if ($header === null) {
            $lower = array_map(static fn ($c) => strtolower($c), $cols);
            if (in_array('amount', $lower, true) || in_array('description', $lower, true) || in_array('date', $lower, true)) {
                $header = $lower;
                continue;
            }
        }

        $dateCol = $cols[0] ?? '';
        $descCol = $cols[1] ?? '';
        $amtCol = $cols[2] ?? ($cols[1] ?? '0');
        if ($header !== null) {
            $idxDate = array_search('date', $header, true);
            $idxDesc = array_search('description', $header, true);
            if ($idxDesc === false) {
                $idxDesc = array_search('desc', $header, true);
            }
            $idxAmt = array_search('amount', $header, true);
            if ($idxDate !== false) {
                $dateCol = $cols[$idxDate] ?? '';
            }
            if ($idxDesc !== false) {
                $descCol = $cols[$idxDesc] ?? '';
            }
            if ($idxAmt !== false) {
                $amtCol = $cols[$idxAmt] ?? '0';
            }
        }

        $isoDate = orange_bank_reconciliation_parse_date_flexible($dateCol);
        $amt = orange_bank_reconciliation_parse_amount($amtCol);
        if ($descCol === '' && abs($amt) < 0.0001) {
            continue;
        }
        $rows[] = [
            'line_date' => $isoDate,
            'description' => $descCol !== '' ? $descCol : '—',
            'amount' => $amt,
            'source' => 'import',
        ];
    }
    fclose($fp);

    return $rows;
}

function orange_bank_reconciliation_parse_date_flexible(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw)) {
        return $raw;
    }
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $raw, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }
    $ts = strtotime($raw);

    return $ts ? date('Y-m-d', $ts) : null;
}

function orange_bank_reconciliation_parse_amount(string $raw): float
{
    $raw = trim(str_replace([',', ' '], ['', ''], $raw));
    if ($raw === '') {
        return 0.0;
    }

    return round((float) $raw, 4);
}

/**
 * @param list<array{line_date:?string,description:string,amount:float,source:string}> $lines
 */
function orange_bank_reconciliation_save(
    PDO $pdo,
    array $headerIn,
    array $lines,
    ?int $countryId = null
): int {
    if (! orange_bank_reconciliation_ready($pdo)) {
        throw new RuntimeException('جداول تسوية البنك غير جاهزة.');
    }

    $id = (int) ($headerIn['id'] ?? 0);
    $accountId = (int) ($headerIn['account_id'] ?? 0);
    $fyId = (int) ($headerIn['fiscal_year_id'] ?? 0);
    $periodFrom = trim((string) ($headerIn['period_from'] ?? ''));
    $periodTo = trim((string) ($headerIn['period_to'] ?? ''));
    $statementBalance = round((float) ($headerIn['statement_balance'] ?? 0), 4);
    $notes = trim((string) ($headerIn['notes'] ?? ''));

    if ($accountId <= 0 || ! orange_accounts_account_is_posting_leaf($pdo, $accountId)) {
        throw new InvalidArgumentException('اختر حساب بنك/نقد (ورقة ترحيل).');
    }
    if ($periodTo === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodTo)) {
        throw new InvalidArgumentException('تاريخ نهاية الفترة مطلوب (YYYY-MM-DD).');
    }
    if ($periodFrom !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodFrom)) {
        throw new InvalidArgumentException('تاريخ بداية الفترة غير صالح.');
    }

    if ($id > 0) {
        $existing = orange_bank_reconciliation_get($pdo, $id, $countryId);
        if ($existing === null) {
            throw new InvalidArgumentException('جلسة التسوية غير موجودة.');
        }
        if ((string) ($existing['header']['status'] ?? '') === 'closed') {
            throw new InvalidArgumentException('جلسة مغلقة — لا يمكن تعديلها.');
        }
    }

    $glBalance = orange_bank_reconciliation_gl_balance($pdo, $accountId, $periodTo);
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_admin_settings_effective_country_id($pdo);
    }

    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $upd = $pdo->prepare(
                'UPDATE bank_reconciliation SET account_id = ?, fiscal_year_id = ?, period_from = ?, period_to = ?,
                    gl_balance = ?, statement_balance = ?, notes = ?, country_id = ? WHERE id = ? AND status = \'draft\''
            );
            $upd->execute([
                $accountId,
                $fyId > 0 ? $fyId : null,
                $periodFrom !== '' ? $periodFrom : null,
                $periodTo,
                $glBalance,
                $statementBalance,
                $notes !== '' ? $notes : null,
                $countryId > 0 ? $countryId : null,
                $id,
            ]);
            if ($upd->rowCount() === 0) {
                throw new RuntimeException('تعذّر تحديث التسوية.');
            }
            $pdo->prepare('DELETE FROM bank_reconciliation_line WHERE reconciliation_id = ?')->execute([$id]);
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO bank_reconciliation
                (account_id, fiscal_year_id, period_from, period_to, gl_balance, statement_balance, status, notes, country_id)
                VALUES (?, ?, ?, ?, ?, ?, \'draft\', ?, ?)'
            );
            $ins->execute([
                $accountId,
                $fyId > 0 ? $fyId : null,
                $periodFrom !== '' ? $periodFrom : null,
                $periodTo,
                $glBalance,
                $statementBalance,
                $notes !== '' ? $notes : null,
                $countryId > 0 ? $countryId : null,
            ]);
            $id = (int) $pdo->lastInsertId();
        }

        $stLine = $pdo->prepare(
            'INSERT INTO bank_reconciliation_line (reconciliation_id, line_date, description, amount, sort_order, source)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $sort = 0;
        foreach ($lines as $ln) {
            $stLine->execute([
                $id,
                $ln['line_date'],
                $ln['description'],
                $ln['amount'],
                ++$sort,
                $ln['source'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $id;
}

/**
 * @return array{voucher_id:int, variance:float, gl_balance:float}
 */
function orange_bank_reconciliation_close(
    PDO $pdo,
    int $id,
    int $adjustmentAccountId,
    ?int $countryId = null
): array {
    $rec = orange_bank_reconciliation_get($pdo, $id, $countryId);
    if ($rec === null) {
        throw new InvalidArgumentException('جلسة التسوية غير موجودة.');
    }
    $header = $rec['header'];
    if ((string) ($header['status'] ?? '') === 'closed') {
        throw new InvalidArgumentException('التسوية مغلقة مسبقاً.');
    }

    $accountId = (int) ($header['account_id'] ?? 0);
    $periodTo = trim((string) ($header['period_to'] ?? ''));
    $statementBalance = round((float) ($header['statement_balance'] ?? 0), 4);
    $glBalance = orange_bank_reconciliation_gl_balance($pdo, $accountId, $periodTo);
    $variance = round($statementBalance - $glBalance, 4);

    $voucherId = 0;
    $pdo->beginTransaction();
    try {
        if (abs($variance) >= 0.0001) {
            if ($adjustmentAccountId <= 0 || ! orange_accounts_account_is_posting_leaf($pdo, $adjustmentAccountId)) {
                throw new InvalidArgumentException('حساب تسوية الفرق (ورقة ترحيل) مطلوب عند وجود فرق.');
            }
            if ($adjustmentAccountId === $accountId) {
                throw new InvalidArgumentException('حساب التسوية يجب أن يختلف عن حساب البنك.');
            }

            $memo = 'تسوية بنك #' . $id . ' — ' . ($variance > 0 ? 'رفع رصيد GL' : 'خفض رصيد GL');
            $abs = abs($variance);
            if ($variance > 0) {
                $lines = [
                    ['account_id' => $accountId, 'debit' => $abs, 'credit' => 0.0, 'memo' => $memo],
                    ['account_id' => $adjustmentAccountId, 'debit' => 0.0, 'credit' => $abs, 'memo' => $memo],
                ];
            } else {
                $lines = [
                    ['account_id' => $accountId, 'debit' => 0.0, 'credit' => $abs, 'memo' => $memo],
                    ['account_id' => $adjustmentAccountId, 'debit' => $abs, 'credit' => 0.0, 'memo' => $memo],
                ];
            }

            $voucherDate = $periodTo . ' 18:00:00';
            $desc = 'تسوية بنك — جلسة #' . $id;
            if (orange_gl_use_pending_queue($pdo)) {
                $pendingId = orange_gl_pending_enqueue_multi(
                    $pdo,
                    $lines,
                    'bank_recon_' . $id,
                    'BRC-' . $id,
                    $voucherDate,
                    $voucherDate,
                    $desc,
                    'general'
                );
                if ($pendingId <= 0) {
                    throw new RuntimeException('تعذّر إدراج قيد التسوية في الطابور.');
                }
                $voucherId = 0;
            } else {
                $voucherId = orange_voucher_post($pdo, [
                    'voucher_date' => $voucherDate,
                    'description' => $desc,
                    'entry_type' => 'general',
                    'country_id' => $countryId,
                ], $lines);
            }
        }

        $upd = $pdo->prepare(
            'UPDATE bank_reconciliation SET status = \'closed\', gl_balance = ?, statement_balance = ?,
                journal_voucher_id = ?, closed_at = NOW() WHERE id = ? AND status = \'draft\''
        );
        $upd->execute([
            $glBalance,
            $statementBalance,
            $voucherId > 0 ? $voucherId : null,
            $id,
        ]);
        if ($upd->rowCount() === 0) {
            throw new RuntimeException('تعذّر إقفال التسوية.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'voucher_id' => $voucherId,
        'variance' => $variance,
        'gl_balance' => $glBalance,
        'queued' => $voucherId === 0 && abs($variance) >= 0.0001,
    ];
}

function orange_bank_reconciliation_delete_draft(PDO $pdo, int $id, ?int $countryId = null): bool
{
    $rec = orange_bank_reconciliation_get($pdo, $id, $countryId);
    if ($rec === null || (string) ($rec['header']['status'] ?? '') !== 'draft') {
        return false;
    }
    $st = $pdo->prepare('DELETE FROM bank_reconciliation WHERE id = ? AND status = \'draft\'');
    $st->execute([$id]);

    return $st->rowCount() > 0;
}
