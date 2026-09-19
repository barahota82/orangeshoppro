<?php

declare(strict_types=1);

/**
 * Accounting Lifecycle V2 — slot registry + in-place rebuild (Phase 0 core, Phase 1A wiring helpers).
 */

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/journal_voucher.php';
require_once __DIR__ . '/gl_pending_movements.php';
require_once __DIR__ . '/party_subledger.php';
require_once __DIR__ . '/party_allocations.php';
require_once __DIR__ . '/admin_time.php';
require_once __DIR__ . '/gl_posting_time.php';

function orange_gl_voucher_slots_ready(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'orange_gl_voucher_slots');
}

/**
 * Pending-queue suffix for a logical slot (`main` → no suffix).
 */
function orange_gl_voucher_slot_pending_suffix(string $slotKey): string
{
    $sk = trim($slotKey);
    if ($sk === '' || strtolower($sk) === 'main') {
        return '';
    }
    if (preg_match('/^sale-ex-(\d+)$/i', $sk, $m)) {
        return 'sale-EX' . $m[1];
    }

    return $sk;
}

/**
 * ref_type for orange_gl_pending_source_key — doc_kind matches Orange pending ref types.
 */
function orange_gl_voucher_slot_pending_ref_type(string $docKind): string
{
    $t = strtolower(trim($docKind));
    if ($t === '') {
        throw new InvalidArgumentException('نوع المستند (doc_kind) غير صالح.');
    }

    return $t;
}

/**
 * Canonical src:… key for a document slot (compatible with orange_gl_pending_source_key).
 */
function orange_gl_voucher_slot_source_key(string $docKind, int $entityId, string $slotKey): string
{
    return orange_gl_pending_source_key(
        orange_gl_voucher_slot_pending_ref_type($docKind),
        $entityId,
        orange_gl_voucher_slot_pending_suffix($slotKey)
    );
}

/**
 * @return array<string, mixed>
 */
function orange_gl_voucher_slot_normalize_spec(array $slotSpec): array
{
    $docKind = orange_gl_voucher_slot_pending_ref_type((string) ($slotSpec['doc_kind'] ?? ''));
    $entityId = (int) ($slotSpec['entity_id'] ?? 0);
    $slotKey = trim((string) ($slotSpec['slot_key'] ?? ''));
    if ($slotKey === '') {
        $slotKey = 'main';
    }
    $entryType = trim((string) ($slotSpec['entry_type'] ?? ''));
    if ($entityId <= 0) {
        throw new InvalidArgumentException('معرف المستند (entity_id) غير صالح.');
    }
    if ($entryType === '') {
        throw new InvalidArgumentException('نوع القيد (entry_type) مطلوب.');
    }
    $countryId = isset($slotSpec['country_id']) ? (int) $slotSpec['country_id'] : 0;
    $journalTypeId = isset($slotSpec['journal_type_id']) ? (int) $slotSpec['journal_type_id'] : 0;

    return [
        'doc_kind' => $docKind,
        'entity_id' => $entityId,
        'slot_key' => $slotKey,
        'entry_type' => $entryType,
        'country_id' => $countryId > 0 ? $countryId : null,
        'journal_type_id' => $journalTypeId > 0 ? $journalTypeId : null,
        'source_key' => orange_gl_voucher_slot_source_key($docKind, $entityId, $slotKey),
    ];
}

/**
 * @return array<string, mixed>|null
 */
function orange_gl_voucher_slot_row_to_array(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'country_id' => isset($row['country_id']) && $row['country_id'] !== null ? (int) $row['country_id'] : null,
        'doc_kind' => (string) ($row['doc_kind'] ?? ''),
        'entity_id' => (int) ($row['entity_id'] ?? 0),
        'slot_key' => (string) ($row['slot_key'] ?? ''),
        'source_key' => (string) ($row['source_key'] ?? ''),
        'entry_type' => (string) ($row['entry_type'] ?? ''),
        'journal_type_id' => isset($row['journal_type_id']) && $row['journal_type_id'] !== null
            ? (int) $row['journal_type_id'] : null,
        'journal_voucher_id' => (int) ($row['journal_voucher_id'] ?? 0),
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function orange_gl_voucher_slot_find(PDO $pdo, string $docKind, int $entityId, string $slotKey): ?array
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_gl_voucher_slots_ready($pdo) || $entityId <= 0) {
        return null;
    }
    $sk = trim($slotKey);
    if ($sk === '') {
        $sk = 'main';
    }
    $dk = orange_gl_voucher_slot_pending_ref_type($docKind);
    $st = $pdo->prepare(
        'SELECT * FROM orange_gl_voucher_slots
         WHERE doc_kind = ? AND entity_id = ? AND slot_key = ? LIMIT 1'
    );
    $st->execute([$dk, $entityId, $sk]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ? orange_gl_voucher_slot_row_to_array($row) : null;
}

/**
 * @return array<string, mixed>|null
 */
function orange_gl_voucher_slot_find_by_source_key(PDO $pdo, string $sourceKey): ?array
{
    orange_catalog_ensure_schema($pdo);
    $key = trim($sourceKey);
    if (!orange_gl_voucher_slots_ready($pdo) || $key === '') {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM orange_gl_voucher_slots WHERE source_key = ? LIMIT 1');
    $st->execute([$key]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ? orange_gl_voucher_slot_row_to_array($row) : null;
}

/**
 * @return list<array<string, mixed>>
 */
function orange_gl_voucher_slot_list_for_document(PDO $pdo, string $docKind, int $entityId): array
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_gl_voucher_slots_ready($pdo) || $entityId <= 0) {
        return [];
    }
    $dk = orange_gl_voucher_slot_pending_ref_type($docKind);
    $st = $pdo->prepare(
        'SELECT * FROM orange_gl_voucher_slots
         WHERE doc_kind = ? AND entity_id = ?
         ORDER BY id ASC'
    );
    $st->execute([$dk, $entityId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[] = orange_gl_voucher_slot_row_to_array($row);
    }

    return $out;
}

/**
 * Register an existing voucher in the slot registry (first post).
 *
 * @param array{doc_kind:string,entity_id:int,slot_key?:string,entry_type:string,country_id?:int,journal_type_id?:int} $slotSpec
 *
 * @throws InvalidArgumentException|RuntimeException
 */
function orange_gl_voucher_slot_register(PDO $pdo, array $slotSpec, int $journalVoucherId): void
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_gl_voucher_slots_ready($pdo)) {
        throw new RuntimeException('جدول سجل خانات GL غير جاهز.');
    }
    if ($journalVoucherId <= 0) {
        throw new InvalidArgumentException('معرف السند غير صالح.');
    }
    $spec = orange_gl_voucher_slot_normalize_spec($slotSpec);
    $v = orange_voucher_by_id($pdo, $journalVoucherId);
    if ($v === null) {
        throw new InvalidArgumentException('السند غير موجود.');
    }
    $jtId = $spec['journal_type_id'];
    if ($jtId === null) {
        $jtRaw = (int) ($v['journal_type_id'] ?? 0);
        $jtId = $jtRaw > 0 ? $jtRaw : null;
    }
    $countryId = $spec['country_id'];
    if ($countryId === null) {
        $cRaw = orange_journal_voucher_resolve_country_id($pdo, $v);
        $countryId = $cRaw > 0 ? $cRaw : null;
    }
    $pdo->prepare(
        'INSERT INTO orange_gl_voucher_slots
            (country_id, doc_kind, entity_id, slot_key, source_key, entry_type, journal_type_id, journal_voucher_id)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $countryId,
        $spec['doc_kind'],
        $spec['entity_id'],
        $spec['slot_key'],
        $spec['source_key'],
        $spec['entry_type'],
        $jtId,
        $journalVoucherId,
    ]);
}

/**
 * Clear is_void so a retired slot voucher may be reposted on a later edit.
 */
function orange_gl_voucher_slot_clear_void_for_rebuild(PDO $pdo, int $voucherId): void
{
    if ($voucherId <= 0 || !orange_table_has_column($pdo, 'journal_vouchers', 'is_void')) {
        return;
    }
    $hasVoidedAt = orange_table_has_column($pdo, 'journal_vouchers', 'voided_at');
    if ($hasVoidedAt) {
        $pdo->prepare(
            'UPDATE journal_vouchers SET is_void = 0, voided_at = NULL WHERE id = ? AND is_void = 1'
        )->execute([$voucherId]);
    } else {
        $pdo->prepare('UPDATE journal_vouchers SET is_void = 0 WHERE id = ? AND is_void = 1')->execute([$voucherId]);
    }
}

/**
 * @throws RuntimeException
 */
function orange_gl_voucher_slot_assert_may_rebuild(PDO $pdo, int $voucherId, ?array $voucherRow = null): void
{
    if ($voucherId <= 0) {
        throw new InvalidArgumentException('معرف السند غير صالح.');
    }
    if ($voucherRow === null) {
        $voucherRow = orange_voucher_by_id($pdo, $voucherId);
    }
    if ($voucherRow === null) {
        throw new InvalidArgumentException('السند غير موجود.');
    }
    if (orange_table_has_column($pdo, 'journal_vouchers', 'is_void')
        && (int) ($voucherRow['is_void'] ?? 0) === 1) {
        throw new RuntimeException('لا يمكن إعادة بناء سند ملغى.');
    }
    if (orange_fiscal_is_closed_for_voucher($pdo, $voucherRow)) {
        $ref = trim((string) ($voucherRow['reference'] ?? ''));
        $msg = 'لا يمكن إعادة بناء سند في سنة مالية مغلقة.';
        if ($ref !== '') {
            $msg .= ' (' . $ref . ')';
        }
        throw new RuntimeException($msg);
    }
}

/**
 * Replace journal lines inside an existing voucher without changing identity columns.
 *
 * @param array{voucher_date?:string,description?:string,country_id?:int} $allowedHeaderPatch
 * @param list<array{account_id:int,debit:float,credit:float,memo:string}> $lines
 *
 * @throws InvalidArgumentException|RuntimeException
 */
function orange_voucher_replace_lines_preserve_identity(
    PDO $pdo,
    int $voucherId,
    array $allowedHeaderPatch,
    array $lines,
    array $existingVoucherRow
): void {
    if (!orange_journal_vouchers_ready($pdo)) {
        throw new RuntimeException('جداول السندات غير جاهزة.');
    }
    if ($voucherId <= 0) {
        throw new InvalidArgumentException('معرف السند غير صالح.');
    }

    $existingFyId = (int) ($existingVoucherRow['fiscal_year_id'] ?? 0);
    $existingDate = (string) ($existingVoucherRow['voucher_date'] ?? '');
    $voucherDate = $existingDate;
    if (isset($allowedHeaderPatch['voucher_date'])) {
        $candidate = trim((string) $allowedHeaderPatch['voucher_date']);
        if ($candidate !== '') {
            $voucherDate = orange_gl_accounting_voucher_date_mysql($candidate);
        }
    }
    if ($voucherDate === '') {
        $cidFallback = orange_journal_voucher_resolve_country_id($pdo, $existingVoucherRow);
        if ($cidFallback <= 0) {
            throw new RuntimeException('دولة السند مطلوبة لتحديد تاريخ القيد المحاسبي');
        }
        $voucherDate = orange_gl_posting_times_for_country($pdo, $cidFallback, null)['voucher_date'];
    } else {
        $voucherDate = orange_gl_accounting_voucher_date_mysql($voucherDate);
    }

    $description = array_key_exists('description', $allowedHeaderPatch)
        ? trim((string) $allowedHeaderPatch['description'])
        : trim((string) ($existingVoucherRow['description'] ?? ''));
    if ($description === '') {
        throw new InvalidArgumentException('بيان السند مطلوب.');
    }

    $headerForCountry = array_merge($existingVoucherRow, $allowedHeaderPatch);
    $voucherCountryId = orange_journal_voucher_resolve_country_id($pdo, $headerForCountry);
    $currencyCode = orange_gl_functional_currency_code($pdo, $voucherCountryId > 0 ? $voucherCountryId : null);

    if ($existingFyId > 0) {
        $fyForDate = orange_fiscal_find_for_date(
            $pdo,
            $voucherDate,
            $voucherCountryId > 0 ? $voucherCountryId : null
        );
        $fyForDateId = $fyForDate ? (int) ($fyForDate['id'] ?? 0) : 0;
        if ($fyForDateId > 0 && $fyForDateId !== $existingFyId) {
            throw new RuntimeException('لا يمكن تغيير تاريخ السند إلى سنة مالية مختلفة أثناء إعادة البناء.');
        }
        orange_fiscal_require_open_for_posting(
            $pdo,
            $voucherDate,
            $voucherCountryId > 0 ? $voucherCountryId : null
        );
    }

    $totalD = 0.0;
    $totalC = 0.0;
    $norm = [];
    $lineNo = 0;
    foreach ($lines as $ln) {
        try {
            $parsed = orange_journal_normalize_voucher_line_input(
                $pdo,
                $ln,
                ++$lineNo,
                $currencyCode,
                $voucherCountryId > 0 ? $voucherCountryId : null
            );
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'سطر بلا مبلغ.') {
                continue;
            }
            throw $e;
        }
        $norm[] = $parsed;
        $totalD += $parsed['debit'];
        $totalC += $parsed['credit'];
    }
    if ($norm === []) {
        throw new InvalidArgumentException('السند بدون أسطر.');
    }
    if (!orange_gl_money_is_balanced($totalD, $totalC, $currencyCode)) {
        throw new InvalidArgumentException('السند غير متوازن: مجموع المدين ' . $totalD . ' ≠ مجموع الدائن ' . $totalC);
    }

    orange_gl_assert_voucher_accounts_country(
        $pdo,
        array_column($norm, 'account_id'),
        $voucherCountryId
    );

    $hasUpdatedAt = orange_table_has_column($pdo, 'journal_vouchers', 'updated_at');
    $updSql = $hasUpdatedAt
        ? 'UPDATE journal_vouchers SET voucher_date = ?, description = ?, updated_at = ? WHERE id = ?'
        : 'UPDATE journal_vouchers SET voucher_date = ?, description = ? WHERE id = ?';

    $chk = $pdo->prepare('SELECT id FROM accounts WHERE id = ? LIMIT 1');
    $pdo->prepare('DELETE FROM journal_lines WHERE voucher_id = ?')->execute([$voucherId]);
    $upd = $pdo->prepare($updSql);
    if ($hasUpdatedAt) {
        $upd->execute([$voucherDate, $description, orange_admin_time_utc_now_mysql(), $voucherId]);
    } else {
        $upd->execute([$voucherDate, $description, $voucherId]);
    }
    foreach ($norm as $row) {
        $chk->execute([$row['account_id']]);
        if (!$chk->fetch()) {
            throw new InvalidArgumentException('حساب غير موجود في الدليل: ' . $row['account_id']);
        }
        orange_journal_insert_voucher_line($pdo, $voucherId, $row);
    }
    orange_journal_voucher_stamp_currency($pdo, $voucherId, $headerForCountry);
}

/**
 * V2 automatic rebuild: preserve id, reference, serial, bucket, journal_type_id, entry_type, fiscal_year_id.
 *
 * @param array{voucher_date?:string,description?:string,country_id?:int} $headerPatch
 * @param list<array{account_id:int,debit:float,credit:float,memo:string}> $lines
 *
 * @throws InvalidArgumentException|RuntimeException
 */
function orange_voucher_rebuild_automatic(PDO $pdo, int $voucherId, array $headerPatch, array $lines): void
{
    if (!orange_journal_vouchers_ready($pdo)) {
        throw new RuntimeException('جداول السندات غير جاهزة.');
    }
    $st = $pdo->prepare('SELECT * FROM journal_vouchers WHERE id = ? LIMIT 1');
    $st->execute([$voucherId]);
    $existing = $st->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        throw new InvalidArgumentException('السند غير موجود.');
    }

    orange_gl_voucher_slot_assert_may_rebuild($pdo, $voucherId, $existing);

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        orange_voucher_replace_lines_preserve_identity($pdo, $voucherId, $headerPatch, $lines, $existing);
        if ($ownTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Delete party subledger (and payment allocations) for a voucher, then replay after_post hooks.
 */
function orange_gl_party_subledger_replace_for_voucher(PDO $pdo, int $voucherId, ?string $afterPostJson): void
{
    if ($voucherId <= 0) {
        return;
    }
    orange_catalog_ensure_schema($pdo);
    if (orange_party_subledger_ready($pdo)) {
        $pdo->prepare('DELETE FROM party_subledger WHERE voucher_id = ?')->execute([$voucherId]);
    }
    if (orange_party_allocations_ready($pdo)) {
        $pdo->prepare('DELETE FROM party_subledger_allocations WHERE payment_voucher_id = ?')->execute([$voucherId]);
    }
    orange_gl_apply_voucher_after_post_hooks($pdo, $voucherId, $afterPostJson);
}

/**
 * First post for a slot: create voucher, register slot, apply party hooks.
 *
 * @param array{doc_kind:string,entity_id:int,slot_key?:string,entry_type:string,country_id?:int,journal_type_id?:int} $slotSpec
 * @param array<string, mixed> $header
 * @param list<array{account_id:int,debit:float,credit:float,memo:string}> $lines
 *
 * @throws InvalidArgumentException|RuntimeException
 */
function orange_gl_voucher_slot_create(
    PDO $pdo,
    array $slotSpec,
    array $header,
    array $lines,
    ?string $afterPostJson
): int {
    $spec = orange_gl_voucher_slot_normalize_spec($slotSpec);
    $existing = orange_gl_voucher_slot_find($pdo, $spec['doc_kind'], $spec['entity_id'], $spec['slot_key']);
    if ($existing !== null && (int) ($existing['journal_voucher_id'] ?? 0) > 0) {
        throw new RuntimeException('خانة GL مسجّلة مسبقاً — استخدم إعادة البناء.');
    }

    $headerEntry = trim((string) ($header['entry_type'] ?? ''));
    if ($headerEntry === '') {
        $header['entry_type'] = $spec['entry_type'];
    }
    if ($spec['journal_type_id'] !== null && !isset($header['journal_type_id'])) {
        $header['journal_type_id'] = $spec['journal_type_id'];
    }
    if ($spec['country_id'] !== null && !isset($header['country_id'])) {
        $header['country_id'] = $spec['country_id'];
    }

    $vid = orange_voucher_post($pdo, $header, $lines);

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        orange_gl_voucher_slot_register($pdo, $spec, $vid);
        orange_gl_party_subledger_replace_for_voucher($pdo, $vid, $afterPostJson);
        if ($ownTx) {
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
 * Rebuild an existing registered slot in place.
 *
 * @param array{doc_kind:string,entity_id:int,slot_key?:string,entry_type:string,country_id?:int,journal_type_id?:int} $slotSpec
 * @param array{voucher_date?:string,description?:string,country_id?:int} $headerPatch
 * @param list<array{account_id:int,debit:float,credit:float,memo:string}> $lines
 *
 * @throws InvalidArgumentException|RuntimeException
 */
function orange_gl_voucher_slot_rebuild(
    PDO $pdo,
    array $slotSpec,
    array $headerPatch,
    array $lines,
    ?string $afterPostJson
): int {
    $spec = orange_gl_voucher_slot_normalize_spec($slotSpec);
    $slot = orange_gl_voucher_slot_find($pdo, $spec['doc_kind'], $spec['entity_id'], $spec['slot_key']);
    if ($slot === null || (int) ($slot['journal_voucher_id'] ?? 0) <= 0) {
        throw new RuntimeException('خانة GL غير مسجّلة — استخدم الإنشاء الأول.');
    }
    $vid = (int) $slot['journal_voucher_id'];

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        orange_gl_voucher_slot_clear_void_for_rebuild($pdo, $vid);
        orange_gl_voucher_slot_assert_may_rebuild($pdo, $vid);
        orange_voucher_rebuild_automatic($pdo, $vid, $headerPatch, $lines);
        orange_gl_party_subledger_replace_for_voucher($pdo, $vid, $afterPostJson);
        if (orange_gl_voucher_slots_ready($pdo)) {
            $pdo->prepare('UPDATE orange_gl_voucher_slots SET updated_at = ? WHERE id = ?')
                ->execute([orange_admin_time_utc_now_mysql(), (int) ($slot['id'] ?? 0)]);
        }
        if ($ownTx) {
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
 * Unified entry: create slot on first post, rebuild in place when registered.
 *
 * @param array{doc_kind:string,entity_id:int,slot_key?:string,entry_type:string,country_id?:int,journal_type_id?:int} $slotSpec
 * @param array<string, mixed> $header
 * @param list<array{account_id:int,debit:float,credit:float,memo:string}> $lines
 *
 * @throws InvalidArgumentException|RuntimeException
 */
function orange_gl_voucher_post_or_rebuild_for_slot(
    PDO $pdo,
    array $slotSpec,
    array $header,
    array $lines,
    ?string $afterPostJson
): int {
    $spec = orange_gl_voucher_slot_normalize_spec($slotSpec);
    $adoptCountryId = isset($spec['country_id']) && (int) $spec['country_id'] > 0
        ? (int) $spec['country_id']
        : null;
    orange_gl_voucher_slot_adopt_legacy($pdo, $slotSpec, $adoptCountryId);
    $slot = orange_gl_voucher_slot_find($pdo, $spec['doc_kind'], $spec['entity_id'], $spec['slot_key']);
    if ($slot !== null && (int) ($slot['journal_voucher_id'] ?? 0) > 0) {
        $headerPatch = [
            'description' => (string) ($header['description'] ?? ''),
        ];
        if (isset($header['voucher_date'])) {
            $headerPatch['voucher_date'] = (string) $header['voucher_date'];
        }
        if (isset($header['country_id'])) {
            $headerPatch['country_id'] = (int) $header['country_id'];
        }

        return orange_gl_voucher_slot_rebuild($pdo, $spec, $headerPatch, $lines, $afterPostJson);
    }

    return orange_gl_voucher_slot_create($pdo, $spec, $header, $lines, $afterPostJson);
}

/**
 * @return list<array{account_id:int,debit:float,credit:float,memo:string}>
 */
function orange_gl_posting_bundle_to_lines(array $glB, float $amount): array
{
    if (!empty($glB['is_multi']) && !empty($glB['lines']) && is_array($glB['lines'])) {
        return $glB['lines'];
    }
    $amount = round($amount, 4);
    $memo = trim((string) ($glB['voucher_description'] ?? 'قيد تلقائي'));

    return [
        [
            'account_id' => (int) ($glB['debit'] ?? 0),
            'debit' => $amount,
            'credit' => 0.0,
            'memo' => $memo,
        ],
        [
            'account_id' => (int) ($glB['credit'] ?? 0),
            'debit' => 0.0,
            'credit' => $amount,
            'memo' => $memo,
        ],
    ];
}

/**
 * Exact pending-queue source_key → voucher (no party_subledger fallback).
 *
 * @return array<string, mixed>|null
 */
function orange_gl_voucher_find_by_pending_source_key(PDO $pdo, string $sourceKey): ?array
{
    orange_catalog_ensure_schema($pdo);
    $key = trim($sourceKey);
    if ($key === '' || !orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT journal_voucher_id FROM orange_gl_pending_movements
         WHERE reference = ? AND journal_voucher_id IS NOT NULL AND journal_voucher_id > 0
         ORDER BY id DESC LIMIT 1'
    );
    $st->execute([$key]);
    $vid = (int) $st->fetchColumn();
    if ($vid <= 0) {
        return null;
    }

    return orange_voucher_by_id($pdo, $vid);
}

/**
 * Adopt an existing posted voucher into the slot registry when lookup is unambiguous.
 */
function orange_gl_voucher_slot_adopt_legacy(PDO $pdo, array $slotSpec, ?int $countryId = null): bool
{
    if (!orange_gl_voucher_slots_ready($pdo)) {
        return false;
    }
    try {
        $spec = orange_gl_voucher_slot_normalize_spec($slotSpec);
    } catch (InvalidArgumentException) {
        return false;
    }
    if ($countryId === null && isset($spec['country_id']) && $spec['country_id'] !== null) {
        $countryId = (int) $spec['country_id'];
        if ($countryId <= 0) {
            $countryId = null;
        }
    }
    if (orange_gl_voucher_slot_find($pdo, $spec['doc_kind'], $spec['entity_id'], $spec['slot_key']) !== null) {
        return true;
    }

    $v = null;
    if (preg_match('/^sale-ex-(\d+)$/i', $spec['slot_key'], $exMatch)) {
        // EX slots: exact pending source_key only — never party_subledger LIMIT 1 (would match main sale).
        $exAccId = (int) $exMatch[1];
        if ($exAccId <= 0) {
            return false;
        }
        $sourceKey = orange_gl_voucher_slot_source_key($spec['doc_kind'], $spec['entity_id'], $spec['slot_key']);
        $v = orange_gl_voucher_find_by_pending_source_key($pdo, $sourceKey);
        if ($v === null) {
            return false;
        }
        $saleSlot = orange_gl_voucher_slot_find($pdo, $spec['doc_kind'], $spec['entity_id'], 'sale');
        if ($saleSlot !== null && (int) ($saleSlot['journal_voucher_id'] ?? 0) === (int) ($v['id'] ?? 0)) {
            return false;
        }
    } elseif (strtolower($spec['doc_kind']) === 'order'
        && preg_match('/^(sale|cogs)-(\d+)$/i', $spec['slot_key'], $orderLineMatch)) {
        return orange_gl_voucher_slot_adopt_legacy_order_line(
            $pdo,
            $slotSpec,
            $countryId,
            (int) $orderLineMatch[2]
        );
    } else {
        $lookupSuffix = orange_gl_voucher_slot_pending_suffix($spec['slot_key']);
        $v = orange_voucher_find_by_document(
            $pdo,
            $spec['doc_kind'],
            $spec['entity_id'],
            $spec['entry_type'],
            $countryId,
            $lookupSuffix
        );
    }
    if ($v === null) {
        return false;
    }
    orange_gl_voucher_slot_register($pdo, $spec, (int) ($v['id'] ?? 0));

    return true;
}

/**
 * Void a registered slot in place (preserve voucher id / serial / reference).
 */
function orange_gl_voucher_slot_void_registered(
    PDO $pdo,
    string $docKind,
    int $entityId,
    string $slotKey
): void {
    if (!orange_gl_voucher_slots_ready($pdo) || $entityId <= 0) {
        return;
    }
    $slot = orange_gl_voucher_slot_find($pdo, $docKind, $entityId, $slotKey);
    if ($slot === null || (int) ($slot['journal_voucher_id'] ?? 0) <= 0) {
        return;
    }
    $vid = (int) $slot['journal_voucher_id'];
    $vRow = orange_voucher_by_id($pdo, $vid);
    if ($vRow !== null
        && orange_table_has_column($pdo, 'journal_vouchers', 'is_void')
        && (int) ($vRow['is_void'] ?? 0) === 1) {
        return;
    }
    orange_gl_voucher_slot_assert_may_rebuild($pdo, $vid, $vRow);
    orange_gl_party_subledger_replace_for_voucher($pdo, $vid, null);
    $pdo->prepare('DELETE FROM journal_lines WHERE voucher_id = ?')->execute([$vid]);
    if (orange_table_has_column($pdo, 'journal_vouchers', 'is_void')) {
        $hasVoidedAt = orange_table_has_column($pdo, 'journal_vouchers', 'voided_at');
        if ($hasVoidedAt) {
            $pdo->prepare('UPDATE journal_vouchers SET is_void = 1, voided_at = ? WHERE id = ?')
                ->execute([orange_admin_time_utc_now_mysql(), $vid]);
        } else {
            $pdo->prepare('UPDATE journal_vouchers SET is_void = 1 WHERE id = ?')->execute([$vid]);
        }
    }
    if (orange_table_has_column($pdo, 'orange_gl_voucher_slots', 'updated_at')) {
        $pdo->prepare('UPDATE orange_gl_voucher_slots SET updated_at = ? WHERE id = ?')
            ->execute([orange_admin_time_utc_now_mysql(), (int) ($slot['id'] ?? 0)]);
    }
}

/**
 * Hard-delete a registered slot voucher (actual document delete policy — not edit/rebuild).
 *
 * @throws RuntimeException when fiscal period is closed
 */
function orange_gl_voucher_slot_delete_registered_voucher(PDO $pdo, int $voucherId): void
{
    if ($voucherId <= 0 || !orange_journal_vouchers_ready($pdo)) {
        return;
    }
    $v = orange_voucher_by_id($pdo, $voucherId);
    if ($v === null) {
        return;
    }
    if (orange_fiscal_is_closed_for_voucher($pdo, $v)) {
        $ref = trim((string) ($v['reference'] ?? ''));
        $msg = 'لا يمكن حذف سند في سنة مالية مغلقة.';
        if ($ref !== '') {
            $msg .= ' (' . $ref . ')';
        }
        throw new RuntimeException($msg);
    }
    orange_gl_party_subledger_replace_for_voucher($pdo, $voucherId, null);
    $pdo->prepare('DELETE FROM journal_lines WHERE voucher_id = ?')->execute([$voucherId]);
    $pdo->prepare('DELETE FROM journal_vouchers WHERE id = ?')->execute([$voucherId]);
}

/**
 * Delete all registered slot vouchers and slot rows for a source document (delete path only).
 *
 * @throws RuntimeException when fiscal period blocks voucher deletion
 */
function orange_gl_voucher_slot_delete_document_accounting(PDO $pdo, string $docKind, int $entityId): void
{
    if (!orange_gl_voucher_slots_ready($pdo) || $entityId <= 0) {
        return;
    }
    $dk = orange_gl_voucher_slot_pending_ref_type($docKind);
    foreach (orange_gl_voucher_slot_list_for_document($pdo, $docKind, $entityId) as $slot) {
        $vid = (int) ($slot['journal_voucher_id'] ?? 0);
        if ($vid > 0) {
            orange_gl_voucher_slot_delete_registered_voucher($pdo, $vid);
        }
    }
    $pdo->prepare('DELETE FROM orange_gl_voucher_slots WHERE doc_kind = ? AND entity_id = ?')
        ->execute([$dk, $entityId]);
}

/**
 * Immediate GL post/rebuild for a posting bundle through the slot engine (non-pending path).
 *
 * @param array{doc_kind:string,entity_id:int,slot_key?:string,entry_type:string,country_id?:int,journal_type_id?:int} $slotSpec
 * @param array<string, mixed> $header
 * @param array<string, mixed> $glB
 */
function orange_gl_voucher_immediate_post_bundle_for_slot(
    PDO $pdo,
    array $slotSpec,
    array $header,
    array $glB,
    float $amount,
    ?string $afterPostJson
): int {
    $lines = orange_gl_posting_bundle_to_lines($glB, $amount);

    return orange_gl_voucher_post_or_rebuild_for_slot($pdo, $slotSpec, $header, $lines, $afterPostJson);
}

/**
 * Immediate GL post/rebuild for a two-line automatic entry through the slot engine.
 *
 * @param array{doc_kind:string,entity_id:int,slot_key?:string,entry_type:string,country_id?:int,journal_type_id?:int} $slotSpec
 * @param array<string, mixed> $header
 */
function orange_gl_voucher_immediate_post_simple_for_slot(
    PDO $pdo,
    array $slotSpec,
    array $header,
    int $debitAccountId,
    int $creditAccountId,
    float $amount,
    string $description,
    ?string $afterPostJson
): int {
    $amount = round($amount, 4);
    $desc = trim($description);
    $lines = [
        ['account_id' => $debitAccountId, 'debit' => $amount, 'credit' => 0.0, 'memo' => $desc],
        ['account_id' => $creditAccountId, 'debit' => 0.0, 'credit' => $amount, 'memo' => $desc],
    ];

    return orange_gl_voucher_post_or_rebuild_for_slot($pdo, $slotSpec, $header, $lines, $afterPostJson);
}

/**
 * Retire dynamic sales-return EX slots that are no longer active (void in place, no renumber).
 *
 * @param list<int> $activeAccountIds
 */
function orange_gl_sales_return_retire_removed_ex_slots(
    PDO $pdo,
    int $returnId,
    array $activeAccountIds,
    ?int $countryId = null
): void {
    if ($returnId <= 0) {
        return;
    }
    $active = [];
    foreach ($activeAccountIds as $accRaw) {
        $accId = (int) $accRaw;
        if ($accId > 0) {
            $active[$accId] = true;
        }
    }
    foreach (orange_gl_voucher_slot_list_for_document($pdo, 'sales_return', $returnId) as $slot) {
        $sk = (string) ($slot['slot_key'] ?? '');
        if (!preg_match('/^sale-ex-(\d+)$/i', $sk, $m)) {
            continue;
        }
        $accId = (int) $m[1];
        if (!isset($active[$accId])) {
            orange_gl_voucher_slot_void_registered($pdo, 'sales_return', $returnId, $sk);
        }
    }
    if (!orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        return;
    }
    $like = orange_gl_pending_source_key('sales_return', $returnId, 'sale') . '-EX%';
    $st = $pdo->prepare(
        'SELECT DISTINCT reference, journal_voucher_id FROM orange_gl_pending_movements
         WHERE reference LIKE ? AND journal_voucher_id IS NOT NULL AND journal_voucher_id > 0'
    );
    $st->execute([$like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $ref = (string) ($row['reference'] ?? '');
        if (!preg_match('/-EX(\d+)$/i', $ref, $m)) {
            continue;
        }
        $accId = (int) $m[1];
        if (isset($active[$accId])) {
            continue;
        }
        $slotKey = 'sale-ex-' . $accId;
        if (orange_gl_voucher_slot_find($pdo, 'sales_return', $returnId, $slotKey) !== null) {
            continue;
        }
        $vid = (int) ($row['journal_voucher_id'] ?? 0);
        if ($vid <= 0) {
            continue;
        }
        $slotSpec = [
            'doc_kind' => 'sales_return',
            'entity_id' => $returnId,
            'slot_key' => $slotKey,
            'entry_type' => 'order_return_sale',
            'country_id' => $countryId,
        ];
        orange_gl_voucher_slot_adopt_legacy($pdo, $slotSpec, $countryId);
        orange_gl_voucher_slot_void_registered($pdo, 'sales_return', $returnId, $slotKey);
    }
}

/**
 * Phase 1A — immediate post/rebuild for all sales-return voucher slots (non-pending path).
 *
 * @param array<string, mixed> $glRev from orange_gl_sales_return_revenue_bundle()
 * @param list<array<string, mixed>> $extraRows from orange_invoice_ancillary_extra_lines_journal_rows()
 */
function orange_gl_sales_return_immediate_post_all_slots(
    PDO $pdo,
    int $returnId,
    string $channel,
    float $revenueTotal,
    float $cogsTotal,
    array $glRev,
    array $extraRows,
    float $customerRefundTotal,
    string $postingAt,
    string $documentEnteredAt,
    ?int $countryId
): void {
    require_once __DIR__ . '/sales_gl_accounts.php';
    require_once __DIR__ . '/journal_types.php';

    $revJtCode = $channel === 'credit' ? 'SRR' : ($channel === 'online' ? 'OSR' : 'SCR');
    $cogsJtCode = $channel === 'credit' ? 'CGR' : ($channel === 'online' ? 'COR' : 'CSR');
    $revJtId = orange_journal_type_id_by_code($pdo, $revJtCode, $countryId);
    $cogsJtId = orange_journal_type_id_by_code($pdo, $cogsJtCode, $countryId);

    if ($revenueTotal > 0.0001) {
        $afterPost = $glRev['after_post'] ?? null;
        if ($afterPost !== null && is_array($afterPost) && isset($afterPost['party_subledger'])) {
            $afterPost['party_subledger']['credit'] = round($customerRefundTotal, 4);
        }
        $afterJson = $afterPost !== null
            ? json_encode($afterPost, JSON_UNESCAPED_UNICODE)
            : null;
        $saleSlot = [
            'doc_kind' => 'sales_return',
            'entity_id' => $returnId,
            'slot_key' => 'sale',
            'entry_type' => 'order_return_sale',
            'country_id' => $countryId,
            'journal_type_id' => $revJtId > 0 ? $revJtId : null,
        ];
        $saleHeader = [
            'voucher_date' => $postingAt,
            'document_entered_at' => $documentEnteredAt,
            'description' => $glRev['voucher_description'],
            'entry_type' => 'order_return_sale',
            'country_id' => $countryId,
        ];
        if ($revJtId > 0) {
            $saleHeader['journal_type_id'] = $revJtId;
        }
        orange_gl_voucher_immediate_post_bundle_for_slot(
            $pdo,
            $saleSlot,
            $saleHeader,
            $glRev,
            $revenueTotal,
            $afterJson
        );

        $exGroups = [];
        $counterAccountId = (int) ($glRev['credit'] ?? 0);
        foreach ($extraRows as $jr) {
            $accId = (int) ($jr['account_id'] ?? 0);
            $memo = trim((string) ($jr['memo'] ?? 'بند مردود'));
            if ($accId <= 0 || $counterAccountId <= 0) {
                continue;
            }
            if ((float) ($jr['credit'] ?? 0) > 0.0001) {
                $exDebit = $accId;
                $exCredit = $counterAccountId;
                $exAmount = round((float) $jr['credit'], 4);
            } elseif ((float) ($jr['debit'] ?? 0) > 0.0001) {
                $exDebit = $counterAccountId;
                $exCredit = $accId;
                $exAmount = round((float) $jr['debit'], 4);
            } else {
                continue;
            }
            if ($exDebit === $exCredit || $exAmount <= 0.0001) {
                continue;
            }
            if (!isset($exGroups[$accId])) {
                $exGroups[$accId] = [
                    'memo' => $memo,
                    'lines' => [],
                ];
            }
            $exGroups[$accId]['lines'][] = [
                'account_id' => $exDebit,
                'debit' => $exAmount,
                'credit' => 0.0,
                'memo' => 'مردود — ' . $memo,
            ];
            $exGroups[$accId]['lines'][] = [
                'account_id' => $exCredit,
                'debit' => 0.0,
                'credit' => $exAmount,
                'memo' => 'مردود — ' . $memo,
            ];
        }
        foreach ($exGroups as $accId => $group) {
            $memo = trim((string) ($group['memo'] ?? 'بند مردود'));
            $lines = isset($group['lines']) && is_array($group['lines']) ? $group['lines'] : [];
            if ($lines === []) {
                continue;
            }
            $exSlot = [
                'doc_kind' => 'sales_return',
                'entity_id' => $returnId,
                'slot_key' => 'sale-ex-' . $accId,
                'entry_type' => 'order_return_sale',
                'country_id' => $countryId,
                'journal_type_id' => $revJtId > 0 ? $revJtId : null,
            ];
            $exHeader = [
                'voucher_date' => $postingAt,
                'document_entered_at' => $documentEnteredAt,
                'description' => 'مردود — ' . $memo,
                'entry_type' => 'order_return_sale',
                'country_id' => $countryId,
            ];
            if ($revJtId > 0) {
                $exHeader['journal_type_id'] = $revJtId;
            }
            orange_gl_voucher_post_or_rebuild_for_slot(
                $pdo,
                $exSlot,
                $exHeader,
                $lines,
                null
            );
        }
        orange_gl_sales_return_retire_removed_ex_slots($pdo, $returnId, array_keys($exGroups), $countryId);
    } else {
        orange_gl_voucher_slot_void_registered($pdo, 'sales_return', $returnId, 'sale');
        orange_gl_sales_return_retire_removed_ex_slots($pdo, $returnId, [], $countryId);
    }

    if ($cogsTotal > 0.0001) {
        $glCogs = orange_gl_sales_return_cogs_accounts($pdo, $channel);
        $cogsDesc = 'مردود تكلفة مبيعات — مستند مردود';
        $cogsSlot = [
            'doc_kind' => 'sales_return',
            'entity_id' => $returnId,
            'slot_key' => 'cogs',
            'entry_type' => 'order_return_cogs',
            'country_id' => $countryId,
            'journal_type_id' => $cogsJtId > 0 ? $cogsJtId : null,
        ];
        $cogsHeader = [
            'voucher_date' => $postingAt,
            'document_entered_at' => $documentEnteredAt,
            'description' => $cogsDesc,
            'entry_type' => 'order_return_cogs',
            'country_id' => $countryId,
        ];
        if ($cogsJtId > 0) {
            $cogsHeader['journal_type_id'] = $cogsJtId;
        }
        orange_gl_voucher_immediate_post_simple_for_slot(
            $pdo,
            $cogsSlot,
            $cogsHeader,
            (int) $glCogs['debit'],
            (int) $glCogs['credit'],
            $cogsTotal,
            $cogsDesc,
            null
        );
    } else {
        orange_gl_voucher_slot_void_registered($pdo, 'sales_return', $returnId, 'cogs');
    }
}

/**
 * Phase 1B — whether any delivery slot is registered for this order (immediate idempotency).
 */
function orange_order_delivery_slots_exist(PDO $pdo, int $orderId): bool
{
    if ($orderId <= 0 || !orange_gl_voucher_slots_ready($pdo)) {
        return false;
    }
    foreach (orange_gl_voucher_slot_list_for_document($pdo, 'order', $orderId) as $slot) {
        $sk = (string) ($slot['slot_key'] ?? '');
        $isDelivery = $sk === 'sale-agg'
            || $sk === 'delivery-expense'
            || $sk === 'loyalty-earn'
            || preg_match('/^(sale|cogs)-\d+$/i', $sk);
        if (!$isDelivery) {
            continue;
        }
        if ((int) ($slot['journal_voucher_id'] ?? 0) > 0) {
            return true;
        }
    }

    return false;
}

/**
 * Adopt a legacy order delivery line voucher (item-id suffix / ORDER-* reference) into sale-{gl_slot} / cogs-{gl_slot}.
 */
function orange_gl_voucher_slot_adopt_legacy_order_line(
    PDO $pdo,
    array $slotSpec,
    ?int $countryId,
    int $glSlot
): bool {
    if ($glSlot <= 0 || !orange_gl_voucher_slots_ready($pdo)) {
        return false;
    }
    try {
        $spec = orange_gl_voucher_slot_normalize_spec($slotSpec);
    } catch (InvalidArgumentException) {
        return false;
    }
    if (strtolower($spec['doc_kind']) !== 'order') {
        return false;
    }
    if (orange_gl_voucher_slot_find($pdo, $spec['doc_kind'], $spec['entity_id'], $spec['slot_key']) !== null) {
        return true;
    }

    $orderId = (int) $spec['entity_id'];
    $slotPrefix = '';
    if (preg_match('/^(sale|cogs)-(\d+)$/i', $spec['slot_key'], $skMatch)) {
        $slotPrefix = strtolower($skMatch[1]);
        if ((int) $skMatch[2] !== $glSlot) {
            return false;
        }
    } else {
        return false;
    }

    $v = orange_gl_voucher_find_by_pending_source_key($pdo, $spec['source_key']);
    if ($v === null) {
        require_once __DIR__ . '/order_item_gl_slot.php';
        $itemMap = orange_order_item_gl_slot_map_by_item_id($pdo, $orderId);
        foreach ($itemMap as $itemId => $mappedSlot) {
            if ((int) $mappedSlot !== $glSlot) {
                continue;
            }
            $legacySuffix = $slotPrefix . '-' . (int) $itemId;
            $legacyKey = orange_gl_voucher_slot_source_key($spec['doc_kind'], $orderId, $legacySuffix);
            $v = orange_gl_voucher_find_by_pending_source_key($pdo, $legacyKey);
            if ($v !== null) {
                break;
            }
            $v = orange_voucher_find_by_document(
                $pdo,
                'order',
                $orderId,
                $spec['entry_type'],
                $countryId,
                $legacySuffix
            );
            if ($v !== null) {
                break;
            }
        }
    }

    if ($v === null) {
        $orderNumber = '';
        if (orange_table_exists($pdo, 'orders')) {
            $st = $pdo->prepare('SELECT order_number FROM orders WHERE id = ? LIMIT 1');
            $st->execute([$orderId]);
            $orderNumber = trim((string) ($st->fetchColumn() ?: ''));
        }
        if ($orderNumber !== '') {
            require_once __DIR__ . '/order_item_gl_slot.php';
            $itemMap = orange_order_item_gl_slot_map_by_item_id($pdo, $orderId);
            foreach ($itemMap as $itemId => $mappedSlot) {
                if ((int) $mappedSlot !== $glSlot) {
                    continue;
                }
                $legacyRef = 'ORDER-' . $orderNumber . '-' . ($slotPrefix === 'sale' ? 'S' : 'C') . '-' . (int) $itemId;
                $v = orange_voucher_by_reference($pdo, $legacyRef, $countryId);
                if ($v !== null) {
                    break;
                }
            }
        }
    }

    if ($v === null) {
        return false;
    }

    orange_gl_voucher_slot_register($pdo, $spec, (int) ($v['id'] ?? 0));

    return true;
}

/**
 * Void per-line delivery slots no longer active; handle aggregate ↔ per-line mode switch.
 *
 * @param list<int> $activeGlSlots
 */
function orange_order_delivery_retire_removed_line_slots(
    PDO $pdo,
    int $orderId,
    array $activeGlSlots,
    bool $aggregateMode
): void {
    if ($orderId <= 0) {
        return;
    }
    $active = [];
    foreach ($activeGlSlots as $slotRaw) {
        $n = (int) $slotRaw;
        if ($n > 0) {
            $active[$n] = true;
        }
    }

    foreach (orange_gl_voucher_slot_list_for_document($pdo, 'order', $orderId) as $slot) {
        $sk = (string) ($slot['slot_key'] ?? '');
        if ($aggregateMode) {
            if (preg_match('/^sale-\d+$/i', $sk)) {
                orange_gl_voucher_slot_void_registered($pdo, 'order', $orderId, $sk);
                continue;
            }
            if (preg_match('/^cogs-(\d+)$/i', $sk, $mAggCogs)) {
                $glSlotAgg = (int) $mAggCogs[1];
                if (!isset($active[$glSlotAgg])) {
                    orange_gl_voucher_slot_void_registered($pdo, 'order', $orderId, $sk);
                }
            }
            continue;
        }
        if ($sk === 'sale-agg') {
            orange_gl_voucher_slot_void_registered($pdo, 'order', $orderId, $sk);
            continue;
        }
        if (preg_match('/^(sale|cogs)-(\d+)$/i', $sk, $m)) {
            $glSlot = (int) $m[2];
            if (!isset($active[$glSlot])) {
                orange_gl_voucher_slot_void_registered($pdo, 'order', $orderId, $sk);
            }
        }
    }

    if (!orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT DISTINCT reference, journal_voucher_id FROM orange_gl_pending_movements
         WHERE reference LIKE ? AND journal_voucher_id IS NOT NULL AND journal_voucher_id > 0'
    );
    $st->execute([orange_gl_pending_source_key('order', $orderId, 'sale') . '-%']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $ref = (string) ($row['reference'] ?? '');
        if (!preg_match('/:sale-(\d+)$/i', $ref, $m) && !preg_match('/:cogs-(\d+)$/i', $ref, $m)) {
            continue;
        }
        $glSlot = (int) $m[1];
        if (isset($active[$glSlot])) {
            continue;
        }
        $slotKind = str_contains($ref, ':cogs-') ? 'cogs' : 'sale';
        if ($aggregateMode && $slotKind === 'sale') {
            continue;
        }
        $slotKey = $slotKind . '-' . $glSlot;
        if (orange_gl_voucher_slot_find($pdo, 'order', $orderId, $slotKey) !== null) {
            continue;
        }
        $entryType = $slotKind === 'cogs' ? 'order_delivery_cogs' : 'order_delivery_sale';
        $slotSpec = [
            'doc_kind' => 'order',
            'entity_id' => $orderId,
            'slot_key' => $slotKey,
            'entry_type' => $entryType,
        ];
        orange_gl_voucher_slot_adopt_legacy_order_line($pdo, $slotSpec, null, $glSlot);
        orange_gl_voucher_slot_void_registered($pdo, 'order', $orderId, $slotKey);
    }
}

/**
 * Phase 1B — immediate post/rebuild for all order delivery voucher slots (non-pending path).
 *
 * @param array<string, mixed> $ctx prepared by orange_post_order_delivery_accounting()
 */
function orange_order_delivery_immediate_post_all_slots(PDO $pdo, array $ctx): void
{
    require_once __DIR__ . '/order_item_gl_slot.php';
    require_once __DIR__ . '/order_fulfillment.php';
    require_once __DIR__ . '/inventory_cost_layers.php';
    require_once __DIR__ . '/journal_types.php';
    require_once __DIR__ . '/sales_gl_accounts.php';
    require_once __DIR__ . '/delivery_areas.php';
    require_once __DIR__ . '/supplier_payable_account.php';
    require_once __DIR__ . '/loyalty.php';

    $order = $ctx['order'] ?? [];
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    $items = is_array($ctx['items'] ?? null) ? $ctx['items'] : [];
    $extraLines = is_array($ctx['extra_lines'] ?? null) ? $ctx['extra_lines'] : [];
    $ofGlCountryId = (int) ($ctx['country_id'] ?? 0);
    $isCredit = !empty($ctx['is_credit']);
    $isOnline = !empty($ctx['is_online']);
    $aggregateSalesGl = !empty($ctx['aggregate_sales_gl']);
    $orderSalesNet = round((float) ($ctx['order_sales_net'] ?? 0), 4);
    $customerIdForAr = (int) ($ctx['customer_id_for_ar'] ?? 0);
    $revenueRule = $ctx['revenue_rule'] ?? null;
    $debitReceivable = (int) ($ctx['debit_receivable'] ?? 0);
    $salesId = (int) ($ctx['sales_id'] ?? 0);
    $saleJtId = (int) ($ctx['sale_jt_id'] ?? 0);
    $cogsJtId = (int) ($ctx['cogs_jt_id'] ?? 0);
    $cogsDebitId = (int) ($ctx['cogs_debit_id'] ?? 0);
    $cogsCreditId = (int) ($ctx['cogs_credit_id'] ?? 0);
    if ($ofGlCountryId <= 0) {
        throw new RuntimeException('دولة الطلب مطلوبة لترحيل قيود التسليم الفوري');
    }
    $timesFallback = orange_gl_posting_times_for_country($pdo, $ofGlCountryId, null);
    $postingAt = trim((string) ($ctx['posting_at'] ?? ''));
    $postingAt = $postingAt !== ''
        ? orange_gl_accounting_voucher_date_mysql($postingAt)
        : $timesFallback['voucher_date'];
    $now = trim((string) ($ctx['document_entered_at'] ?? ''));
    $now = $now !== '' ? $now : $timesFallback['document_entered_at'];
    $activeGlSlots = is_array($ctx['active_gl_slots'] ?? null) ? $ctx['active_gl_slots'] : [];

    $saleDesc = $isOnline
        ? 'قيد مبيعات أونلاين — تسليم'
        : ($isCredit ? 'قيد مبيعات آجل — تسليم' : 'قيد مبيعات نقدي — تسليم');
    $cogsDesc = $isOnline
        ? 'قيد تكلفة مبيعات أونلاين — تسليم'
        : ($isCredit ? 'قيد تكلفة مبيعات آجل — تسليم' : 'قيد تكلفة مبيعات نقدي — تسليم');

    if ($aggregateSalesGl && $orderSalesNet > 0.0001) {
        orange_order_post_delivery_sale_gl_amount(
            $pdo,
            $order,
            $orderSalesNet,
            $extraLines,
            $customerIdForAr,
            $ofGlCountryId,
            $isCredit,
            $isOnline,
            is_array($revenueRule) ? $revenueRule : null,
            $debitReceivable,
            $salesId,
            $saleJtId,
            $saleDesc,
            'agg'
        );
    } elseif (!$aggregateSalesGl) {
        orange_gl_voucher_slot_void_registered($pdo, 'order', $orderId, 'sale-agg');
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $glSlot = (int) ($item['gl_slot'] ?? 0);
            orange_order_item_assert_gl_slot($glSlot);
            $salesAmount = orange_order_item_line_net($item);
            if ($salesAmount <= 0.0001) {
                orange_gl_voucher_slot_void_registered($pdo, 'order', $orderId, 'sale-' . $glSlot);
                continue;
            }
            orange_order_post_delivery_sale_gl_amount(
                $pdo,
                $order,
                $salesAmount,
                [],
                $customerIdForAr,
                $ofGlCountryId,
                $isCredit,
                $isOnline,
                is_array($revenueRule) ? $revenueRule : null,
                $debitReceivable,
                $salesId,
                $saleJtId,
                $saleDesc,
                (string) $glSlot
            );
        }
    } else {
        orange_gl_voucher_slot_void_registered($pdo, 'order', $orderId, 'sale-agg');
    }

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $glSlot = (int) ($item['gl_slot'] ?? 0);
        orange_order_item_assert_gl_slot($glSlot);
        $variant = orange_order_resolve_variant_from_item($pdo, $item);
        if ($variant) {
            $itemIdCogs = isset($item['id']) ? (int) $item['id'] : 0;
            $lineQtyCogs = (int) ($item['qty'] ?? 0);
            $consCogs = orange_inventory_cost_layers_consumption_cost($pdo, 'order', $itemIdCogs);
            $costAmount = (float) $consCogs['cost'];
            $shortCogs = $lineQtyCogs - (int) $consCogs['qty'];
            if ($shortCogs > 0) {
                $costAmount += (float) ($item['cost'] ?? 0) * $shortCogs;
            }
            $costAmount = round($costAmount, 4);
        } else {
            $costAmount = 0.0;
        }

        $cogsSlotKey = 'cogs-' . $glSlot;
        if ($costAmount > 0.0001) {
            $cogsSlot = [
                'doc_kind' => 'order',
                'entity_id' => $orderId,
                'slot_key' => $cogsSlotKey,
                'entry_type' => 'order_delivery_cogs',
                'country_id' => $ofGlCountryId > 0 ? $ofGlCountryId : null,
                'journal_type_id' => $cogsJtId > 0 ? $cogsJtId : null,
            ];
            $cogsHeader = [
                'voucher_date' => $postingAt,
                'document_entered_at' => $now,
                'description' => $cogsDesc,
                'entry_type' => 'order_delivery_cogs',
                'country_id' => $ofGlCountryId,
            ];
            if ($cogsJtId > 0) {
                $cogsHeader['journal_type_id'] = $cogsJtId;
            }
            $afterJson = orange_gl_after_post_json_with_country(null, $ofGlCountryId);
            orange_gl_voucher_immediate_post_simple_for_slot(
                $pdo,
                $cogsSlot,
                $cogsHeader,
                $cogsDebitId,
                $cogsCreditId,
                $costAmount,
                $cogsDesc,
                $afterJson
            );
        } else {
            orange_gl_voucher_slot_void_registered($pdo, 'order', $orderId, $cogsSlotKey);
        }
    }

    orange_order_delivery_retire_removed_line_slots($pdo, $orderId, $activeGlSlots, $aggregateSalesGl);

    orange_order_post_delivery_expense_gl($pdo, $order, $ofGlCountryId, $isOnline);

    $orderForLoyalty = $order;
    $orderForLoyalty['customer_id'] = $customerIdForAr;
    $loyaltyMerchandiseNet = (float) ($ctx['loyalty_merchandise_net'] ?? 0);
    if ($loyaltyMerchandiseNet <= 0.0001) {
        $loyaltyMerchandiseNet = orange_loyalty_merchandise_net_from_order($pdo, $order, $items);
    }
    orange_loyalty_earn_for_order($pdo, $orderForLoyalty, $ofGlCountryId, $loyaltyMerchandiseNet);
}
