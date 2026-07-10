<?php

declare(strict_types=1);

/**
 * Accounting Lifecycle V2 — Phase 0 Core Engine (slot registry + in-place rebuild).
 * Infrastructure only: not wired into document API paths until Phase 1+.
 */

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/journal_voucher.php';
require_once __DIR__ . '/gl_pending_movements.php';
require_once __DIR__ . '/party_subledger.php';
require_once __DIR__ . '/party_allocations.php';

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
            $voucherDate = strlen($candidate) === 10 ? $candidate . ' 12:00:00' : $candidate;
        }
    }
    if ($voucherDate === '') {
        $voucherDate = date('Y-m-d H:i:s');
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
        ? 'UPDATE journal_vouchers SET voucher_date = ?, description = ?, updated_at = NOW() WHERE id = ?'
        : 'UPDATE journal_vouchers SET voucher_date = ?, description = ? WHERE id = ?';

    $chk = $pdo->prepare('SELECT id FROM accounts WHERE id = ? LIMIT 1');
    $pdo->prepare('DELETE FROM journal_lines WHERE voucher_id = ?')->execute([$voucherId]);
    $upd = $pdo->prepare($updSql);
    $upd->execute([$voucherDate, $description, $voucherId]);
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
        orange_gl_voucher_slot_assert_may_rebuild($pdo, $vid);
        orange_voucher_rebuild_automatic($pdo, $vid, $headerPatch, $lines);
        orange_gl_party_subledger_replace_for_voucher($pdo, $vid, $afterPostJson);
        if (orange_gl_voucher_slots_ready($pdo)) {
            $pdo->prepare('UPDATE orange_gl_voucher_slots SET updated_at = NOW() WHERE id = ?')
                ->execute([(int) ($slot['id'] ?? 0)]);
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
