<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/currency.php';
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

/** معاينة رقم السند (MAX(id)+1) ضمن دولة الأدمن — للعرض فقط (GAP-09). */
function orange_gl_voucher_next_id_preview(PDO $pdo, ?int $countryId = null): int
{
    if (!orange_journal_vouchers_ready($pdo)) {
        return 1;
    }
    $bind = orange_gl_voucher_country_bind($pdo, 'jv', $countryId);
    $sql = 'SELECT COALESCE(MAX(jv.id), 0) + 1 FROM journal_vouchers jv WHERE 1=1' . $bind['sql'];
    if ($bind['params'] === []) {
        return (int) $pdo->query($sql)->fetchColumn();
    }
    $st = $pdo->prepare($sql);
    $st->execute($bind['params']);

    return (int) $st->fetchColumn();
}

/** هل وُجد سند بمرجع LIKE ضمن دولة السياق؟ (GAP-09) */
function orange_gl_voucher_reference_like_exists(PDO $pdo, string $likePattern, ?int $countryId = null): bool
{
    if (!orange_journal_vouchers_ready($pdo) || trim($likePattern) === '') {
        return false;
    }
    $bind = orange_gl_voucher_country_bind($pdo, '', $countryId);
    $st = $pdo->prepare(
        'SELECT 1 FROM journal_vouchers WHERE reference LIKE ?' . $bind['sql'] . ' LIMIT 1'
    );
    $st->execute(array_merge([$likePattern], $bind['params']));

    return (bool) $st->fetchColumn();
}

/**
 * @return list<string>
 */
function orange_gl_voucher_select_references_like(PDO $pdo, string $likePattern, ?int $countryId = null): array
{
    if (!orange_journal_vouchers_ready($pdo) || trim($likePattern) === '') {
        return [];
    }
    $bind = orange_gl_voucher_country_bind($pdo, '', $countryId);
    $st = $pdo->prepare(
        'SELECT reference FROM journal_vouchers WHERE reference LIKE ?' . $bind['sql'] . ' ORDER BY id ASC'
    );
    $st->execute(array_merge([$likePattern], $bind['params']));

    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
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
        orange_journal_voucher_stamp_currency($pdo, $voucherId, $header);

        return;
    }
    $cid = orange_journal_voucher_resolve_country_id($pdo, $header);
    if ($cid <= 0) {
        orange_journal_voucher_stamp_currency($pdo, $voucherId, $header);

        return;
    }
    $pdo->prepare(
        'UPDATE journal_vouchers SET country_id = ? WHERE id = ? AND (country_id IS NULL OR country_id = 0)'
    )->execute([$cid, $voucherId]);
    orange_journal_voucher_stamp_currency($pdo, $voucherId, $header);
}

function orange_journal_voucher_stamp_currency(PDO $pdo, int $voucherId, array $header): void
{
    if ($voucherId <= 0 || !orange_table_has_column($pdo, 'journal_vouchers', 'currency_code')) {
        return;
    }
    $cid = orange_journal_voucher_resolve_country_id($pdo, $header);
    $cur = orange_gl_functional_currency_code($pdo, $cid > 0 ? $cid : null);
    $pdo->prepare(
        'UPDATE journal_vouchers SET currency_code = ? WHERE id = ? AND (currency_code IS NULL OR currency_code = \'\')'
    )->execute([$cur, $voucherId]);
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
    $st = $pdo->prepare('SELECT country_id, currency_code FROM journal_vouchers WHERE id = ? LIMIT 1');
    $st->execute([$voucherId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    $rowCid = (int) ($row['country_id'] ?? 0);
    if ($rowCid > 0 && $rowCid !== $ctx) {
        throw new RuntimeException('السند لا يتبع الدولة المختارة في لوحة التحكم.');
    }
    if (orange_table_has_column($pdo, 'journal_vouchers', 'currency_code')) {
        $ctxCur = orange_admin_context_currency_code($pdo);
        $rowCur = strtoupper(trim((string) ($row['currency_code'] ?? '')));
        if ($rowCur !== '' && $rowCur !== $ctxCur) {
            throw new RuntimeException('عملة السند لا تطابق عملة الدولة المختارة في لوحة التحكم.');
        }
    }
}

/**
 * @return array<string, mixed>|null
 */
function orange_voucher_by_reference(PDO $pdo, string $reference, ?int $countryId = null): ?array
{
    if (!orange_journal_vouchers_ready($pdo)) {
        return null;
    }
    $bind = orange_gl_voucher_country_bind($pdo, '', $countryId);
    $st = $pdo->prepare(
        'SELECT * FROM journal_vouchers WHERE reference = ?' . $bind['sql'] . ' ORDER BY id DESC LIMIT 1'
    );
    $st->execute(array_merge([$reference], $bind['params']));

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
    $countryId = orange_journal_voucher_resolve_country_id($pdo, $voucherRow);
    $fy = orange_fiscal_find_for_date($pdo, $d, $countryId > 0 ? $countryId : null);

    return $fy ? ((int) $fy['is_closed'] === 1) : false;
}

/**
 * @throws RuntimeException
 */
function orange_voucher_delete_by_reference(PDO $pdo, string $reference, ?int $countryId = null): void
{
    if (!orange_journal_vouchers_ready($pdo)) {
        return;
    }
    $v = orange_voucher_by_reference($pdo, $reference, $countryId);
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
 * @param int|string $purchaseIdOrLegacyRef معرف الفاتورة أو مرجع قديم PUR-{id}
 *
 * @throws RuntimeException
 */
function orange_purchase_remove_accounting(PDO $pdo, int|string $purchaseIdOrLegacyRef, ?int $countryId = null): void
{
    orange_catalog_ensure_schema($pdo);
    $purchaseId = 0;
    $legacyRef = '';
    if (is_int($purchaseIdOrLegacyRef)) {
        $purchaseId = $purchaseIdOrLegacyRef;
    } elseif (preg_match('/^PUR-(\d+)$/i', trim((string) $purchaseIdOrLegacyRef), $m)) {
        $purchaseId = (int) $m[1];
        $legacyRef = trim((string) $purchaseIdOrLegacyRef);
    } else {
        $legacyRef = trim((string) $purchaseIdOrLegacyRef);
    }
    if (orange_journal_vouchers_ready($pdo)) {
        $v = null;
        if ($purchaseId > 0) {
            $v = orange_voucher_find_by_document($pdo, 'purchase', $purchaseId, 'purchase', $countryId);
        }
        if ($v === null && $legacyRef !== '') {
            $v = orange_voucher_by_reference($pdo, $legacyRef, $countryId);
        }
        if ($v) {
            orange_voucher_delete_by_reference($pdo, (string) ($v['reference'] ?? ''), $countryId);

            return;
        }
    }
    if (!orange_table_exists($pdo, 'journal_entries')) {
        return;
    }
    $st = $pdo->prepare('SELECT * FROM journal_entries WHERE reference = ? LIMIT 1');
    $lookupRef = $legacyRef !== '' ? $legacyRef : ($purchaseId > 0 ? 'PUR-' . $purchaseId : '');
    if ($lookupRef === '') {
        return;
    }
    $st->execute([$lookupRef]);
    $j = $st->fetch(PDO::FETCH_ASSOC);
    if ($j && orange_fiscal_is_closed_for_entry($pdo, $j)) {
        throw new RuntimeException('لا يمكن حذف قيد شراء في سنة مالية مغلقة.');
    }
    $pdo->prepare('DELETE FROM journal_entries WHERE reference = ?')->execute([$lookupRef]);
}

/**
 * حذف قيود/طابور «استلام المخزون» المرتبطة بفاتورة شراء (PUR-{id}-RCV-*) عند تعديل أو حذف الفاتورة.
 *
 * @throws RuntimeException
 */
function orange_purchase_remove_receive_accounting(PDO $pdo, int $purchaseId, ?int $countryId = null): void
{
    orange_catalog_ensure_schema($pdo);
    if ($purchaseId <= 0) {
        return;
    }
    if ($countryId === null || $countryId <= 0) {
        if (orange_table_exists($pdo, 'purchases') && orange_table_has_column($pdo, 'purchases', 'country_id')) {
            $stP = $pdo->prepare('SELECT country_id FROM purchases WHERE id = ? LIMIT 1');
            $stP->execute([$purchaseId]);
            $countryId = (int) ($stP->fetchColumn() ?: 0);
        }
        if ($countryId <= 0) {
            $countryId = orange_admin_context_country_id($pdo);
        }
    }
    $likeLegacy = 'PUR-' . $purchaseId . '-RCV-%';
    $srcPrefix = 'src:purchase_receive:' . $purchaseId;
    if (orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        $pdo->prepare(
            "DELETE FROM orange_gl_pending_movements WHERE entry_type = 'purchase_receive'
             AND (reference LIKE ? OR reference LIKE ?)"
        )->execute([$likeLegacy, $srcPrefix . '%']);
    }
    if (!orange_journal_vouchers_ready($pdo)) {
        return;
    }
    $refs = orange_gl_voucher_select_references_like($pdo, $likeLegacy, $countryId > 0 ? $countryId : null);
    foreach ($refs as $ref) {
        $r = trim((string) $ref);
        if ($r !== '') {
            orange_voucher_delete_by_reference($pdo, $r, $countryId > 0 ? $countryId : null);
        }
    }
    if (orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        $stV = $pdo->prepare(
            'SELECT journal_voucher_id FROM orange_gl_pending_movements
             WHERE reference LIKE ? AND journal_voucher_id IS NOT NULL AND journal_voucher_id > 0'
        );
        $stV->execute([$srcPrefix . '%']);
        foreach ($stV->fetchAll(PDO::FETCH_COLUMN) ?: [] as $vidRaw) {
            $v = orange_voucher_by_id($pdo, (int) $vidRaw);
            if ($v !== null) {
                orange_voucher_delete_by_reference($pdo, (string) ($v['reference'] ?? ''), $countryId > 0 ? $countryId : null);
            }
        }
    }
}

/**
 * حذف قيد مردود المشتريات (نفس منطق حذف قيد الشراء حسب المصدر).
 *
 * @throws RuntimeException
 */
function orange_purchase_return_remove_accounting(PDO $pdo, int|string $returnIdOrLegacyRef, ?int $countryId = null): void
{
    $returnId = 0;
    $legacyRef = '';
    if (is_int($returnIdOrLegacyRef)) {
        $returnId = $returnIdOrLegacyRef;
    } elseif (preg_match('/^PR-(\d+)$/i', trim((string) $returnIdOrLegacyRef), $m)) {
        $returnId = (int) $m[1];
        $legacyRef = trim((string) $returnIdOrLegacyRef);
    } else {
        $legacyRef = trim((string) $returnIdOrLegacyRef);
    }
    if ($returnId > 0) {
        $v = orange_voucher_find_by_document($pdo, 'purchase_return', $returnId, 'purchase_return', $countryId);
        if ($v !== null) {
            orange_voucher_delete_by_reference($pdo, (string) ($v['reference'] ?? ''), $countryId);

            return;
        }
    }
    if ($legacyRef !== '') {
        orange_purchase_remove_accounting($pdo, $legacyRef, $countryId);
    }
}

/**
 * حذف قيود مردود مبيعات مستند (إيراد + تكلفة مجمّعة).
 *
 * @throws RuntimeException
 */
function orange_sales_return_remove_accounting(PDO $pdo, int $returnId, ?int $countryId = null): void
{
    orange_catalog_ensure_schema($pdo);
    foreach (['order_return_sale', 'order_return_cogs'] as $et) {
        $suffix = $et === 'order_return_cogs' ? 'cogs' : 'sale';
        $v = orange_voucher_find_by_document($pdo, 'sales_return', $returnId, $et, $countryId, $suffix);
        if ($v !== null) {
            orange_voucher_delete_by_reference($pdo, (string) ($v['reference'] ?? ''), $countryId);
        }
    }
    $rs = 'SR-' . $returnId . '-RS';
    $rc = 'SR-' . $returnId . '-RC';
    if (orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        $pdo->prepare('DELETE FROM orange_gl_pending_movements WHERE reference IN (?,?,?,?)')->execute([
            $rs,
            $rc,
            orange_gl_pending_source_key('sales_return', $returnId, 'sale'),
            orange_gl_pending_source_key('sales_return', $returnId, 'cogs'),
        ]);
    }
    orange_purchase_remove_accounting($pdo, $rs, $countryId);
    orange_purchase_remove_accounting($pdo, $rc, $countryId);
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

/** SQL fragment: exclude voided journal vouchers from GL totals (§9.6). */
function orange_journal_voucher_sql_exclude_void(PDO $pdo, string $jvAlias = 'jv'): string
{
    if (! orange_table_exists($pdo, 'journal_vouchers')
        || ! orange_table_has_column($pdo, 'journal_vouchers', 'is_void')) {
        return '';
    }

    return ' AND (' . $jvAlias . '.is_void = 0 OR ' . $jvAlias . '.is_void IS NULL)';
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

/** رمز السوق في مرجع السند (KW، EG، UAE، …). */
function orange_voucher_country_display_code(PDO $pdo, ?int $countryId = null): string
{
    return orange_opening_balance_country_code($pdo, $countryId);
}

function orange_voucher_journal_type_code(
    PDO $pdo,
    string $entryType,
    ?int $journalTypeId = null,
    ?int $countryId = null
): string {
    $jtId = (int) ($journalTypeId ?? 0);
    if ($jtId > 0) {
        $fromId = orange_journal_type_code_by_id($pdo, $jtId);
        if ($fromId !== '') {
            return $fromId;
        }
    }
    $fromEt = orange_journal_type_code_from_entry_type($entryType);
    if ($fromEt !== '') {
        return $fromEt;
    }

    return 'JE';
}

/** مرجع السند: {كود نوع اليومية}-{رمز الدولة}-{voucher_serial}. */
function orange_voucher_auto_reference(
    PDO $pdo,
    string $entryType,
    int $voucherSerial,
    ?int $countryId = null,
    ?int $journalTypeId = null
): string {
    $code = orange_voucher_journal_type_code($pdo, $entryType, $journalTypeId, $countryId);
    $cc = orange_voucher_country_display_code($pdo, $countryId);
    if ($cc === '') {
        $cc = 'XX';
    }
    $serial = $voucherSerial > 0 ? $voucherSerial : 1;

    return $code . '-' . $cc . '-' . $serial;
}

/** معاينة المرجع قبل الحفظ (التسلسل التالي ضمن السنة ونوع اليومية). */
function orange_voucher_auto_reference_preview(
    PDO $pdo,
    string $entryType,
    int $fyId,
    ?int $countryId = null,
    ?int $journalTypeId = null
): string {
    if ($fyId <= 0) {
        return '';
    }
    $meta = orange_journal_voucher_resolve_serial_meta(
        $pdo,
        $entryType,
        ($journalTypeId ?? 0) > 0 ? (int) $journalTypeId : null,
        $countryId
    );
    $next = orange_journal_voucher_next_serial($pdo, $fyId, $meta['journal_serial_bucket']);
    $jid = isset($meta['journal_type_id']) && $meta['journal_type_id'] !== null
        ? (int) $meta['journal_type_id']
        : (($journalTypeId ?? 0) > 0 ? (int) $journalTypeId : null);

    return orange_voucher_auto_reference($pdo, $entryType, $next, $countryId, $jid);
}

/**
 * مفتاح داخلي لصف طابور GL — المرجع المحاسبي يُولَّد عند الترحيل في orange_voucher_post.
 */
function orange_gl_pending_source_key(string $refType, int $refId, string $suffix = ''): string
{
    $t = strtolower(trim($refType));
    if ($t === '' || $refId <= 0) {
        throw new InvalidArgumentException('مفتاح مصدر الطابور غير صالح.');
    }
    $key = 'src:' . $t . ':' . $refId;
    $suffix = trim($suffix);
    if ($suffix !== '') {
        $key .= ':' . $suffix;
    }

    return $key;
}

/**
 * @return array<string, mixed>|null
 */
function orange_voucher_find_by_document(
    PDO $pdo,
    string $refType,
    int $refId,
    ?string $entryType = null,
    ?int $countryId = null,
    string $suffix = ''
): ?array {
    orange_catalog_ensure_schema($pdo);
    if ($refId <= 0 || trim($refType) === '') {
        return null;
    }
    if (orange_table_exists($pdo, 'party_subledger')) {
        $jvBind = orange_gl_voucher_country_bind($pdo, 'jv', $countryId);
        $sql = 'SELECT jv.* FROM journal_vouchers jv
                INNER JOIN party_subledger ps ON ps.voucher_id = jv.id
                WHERE ps.ref_type = ? AND ps.ref_id = ?' . $jvBind['sql'];
        $params = [$refType, $refId];
        if ($entryType !== null && trim($entryType) !== '') {
            $sql .= ' AND jv.entry_type = ?';
            $params[] = trim($entryType);
        }
        $sql .= ' ORDER BY jv.id DESC LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute(array_merge($params, $jvBind['params']));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }
    if (orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        $srcKey = orange_gl_pending_source_key($refType, $refId, $suffix);
        $stP = $pdo->prepare(
            'SELECT journal_voucher_id FROM orange_gl_pending_movements
             WHERE reference = ? AND journal_voucher_id IS NOT NULL AND journal_voucher_id > 0
             ORDER BY id DESC LIMIT 1'
        );
        $stP->execute([$srcKey]);
        $vid = (int) $stP->fetchColumn();
        if ($vid > 0) {
            $v = orange_voucher_by_id($pdo, $vid);
            if ($v !== null) {
                if ($entryType === null || trim($entryType) === '' || trim((string) ($v['entry_type'] ?? '')) === trim($entryType)) {
                    return $v;
                }
            }
        }
    }
    $legacy = orange_voucher_legacy_reference_for_document($refType, $refId, $suffix, $entryType);
    if ($legacy !== '') {
        return orange_voucher_by_reference($pdo, $legacy, $countryId);
    }

    return null;
}

/**
 * @return list<array<string, mixed>>
 */
function orange_voucher_list_by_document(
    PDO $pdo,
    string $refType,
    int $refId,
    ?array $entryTypes = null,
    ?int $countryId = null
): array {
    orange_catalog_ensure_schema($pdo);
    if ($refId <= 0 || trim($refType) === '') {
        return [];
    }
    $found = [];
    $seen = [];
    $add = static function (?array $row) use (&$found, &$seen): void {
        if ($row === null) {
            return;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || isset($seen[$id])) {
            return;
        }
        $seen[$id] = true;
        $found[] = $row;
    };
    if ($entryTypes === null || $entryTypes === []) {
        $add(orange_voucher_find_by_document($pdo, $refType, $refId, null, $countryId));
    } else {
        foreach ($entryTypes as $et) {
            $add(orange_voucher_find_by_document($pdo, $refType, $refId, (string) $et, $countryId));
        }
    }
    if (orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        $like = 'src:' . strtolower(trim($refType)) . ':' . $refId . '%';
        $stP = $pdo->prepare(
            'SELECT journal_voucher_id FROM orange_gl_pending_movements
             WHERE reference LIKE ? AND journal_voucher_id IS NOT NULL AND journal_voucher_id > 0'
        );
        $stP->execute([$like]);
        foreach ($stP->fetchAll(PDO::FETCH_COLUMN) ?: [] as $vidRaw) {
            $vid = (int) $vidRaw;
            if ($vid <= 0) {
                continue;
            }
            $v = orange_voucher_by_id($pdo, $vid);
            if ($v === null) {
                continue;
            }
            if ($entryTypes !== null && $entryTypes !== []) {
                $etV = trim((string) ($v['entry_type'] ?? ''));
                if (!in_array($etV, $entryTypes, true)) {
                    continue;
                }
            }
            $add($v);
        }
    }
    foreach (orange_voucher_legacy_references_for_document($refType, $refId) as $legacyRef) {
        $add(orange_voucher_by_reference($pdo, $legacyRef, $countryId));
    }

    return $found;
}

/**
 * روابط فتح القيود المُولَّدة في «سندات أخرى» بعد حفظ مستند تشغيلي.
 *
 * @param list<array{entry_type:string,journal_type_code:string,label?:string,suffix?:string}> $specs
 * @return list<array{voucher_id:int,journal_type_id:int,entry_type:string,label:string,journal_type_code:string}>
 */
function orange_gl_posting_voucher_links(
    PDO $pdo,
    string $refType,
    int $refId,
    array $specs,
    ?int $countryId = null
): array {
    $out = [];
    foreach ($specs as $spec) {
        $et = trim((string) ($spec['entry_type'] ?? ''));
        $code = strtoupper(trim((string) ($spec['journal_type_code'] ?? '')));
        $label = trim((string) ($spec['label'] ?? $code));
        $suffix = trim((string) ($spec['suffix'] ?? ''));
        if ($et === '' || $code === '') {
            continue;
        }
        $v = orange_voucher_find_by_document($pdo, $refType, $refId, $et, $countryId, $suffix);
        $vid = $v !== null ? (int) ($v['id'] ?? 0) : 0;
        $jtId = $v !== null ? (int) ($v['journal_type_id'] ?? 0) : 0;
        if ($jtId <= 0) {
            $jtId = orange_journal_type_id_by_code($pdo, $code, $countryId);
        }
        $out[] = [
            'voucher_id' => $vid,
            'journal_type_id' => $jtId,
            'entry_type' => $et,
            'label' => $label !== '' ? $label : $code,
            'journal_type_code' => $code,
        ];
    }

    return $out;
}

function orange_voucher_legacy_reference_for_document(
    string $refType,
    int $refId,
    string $suffix = '',
    ?string $entryType = null
): string {
    $t = strtolower(trim($refType));
    if ($t === 'purchase' && ($entryType === null || $entryType === 'purchase')) {
        return 'PUR-' . $refId;
    }
    if ($t === 'purchase_return' && ($entryType === null || $entryType === 'purchase_return')) {
        return 'PR-' . $refId;
    }
    if ($t === 'sales_return') {
        $sfx = strtolower(trim($suffix));
        if ($sfx === 'cogs' || $entryType === 'order_return_cogs') {
            return 'SR-' . $refId . '-RC';
        }

        return 'SR-' . $refId . '-RS';
    }
    if ($t === 'opening_balance') {
        return 'OB-' . $refId;
    }

    return '';
}

/**
 * @return list<string>
 */
function orange_voucher_legacy_references_for_document(string $refType, int $refId): array
{
    $t = strtolower(trim($refType));
    if ($t === 'purchase') {
        return ['PUR-' . $refId];
    }
    if ($t === 'purchase_return') {
        return ['PR-' . $refId];
    }
    if ($t === 'sales_return') {
        return ['SR-' . $refId . '-RS', 'SR-' . $refId . '-RC'];
    }
    if ($t === 'opening_balance') {
        return ['OB-' . $refId];
    }

    return [];
}

function orange_voucher_by_id(PDO $pdo, int $voucherId): ?array
{
    if ($voucherId <= 0 || !orange_journal_vouchers_ready($pdo)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM journal_vouchers WHERE id = ? LIMIT 1');
    $st->execute([$voucherId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * دليل واحد ضمن كل سنة لتسلسل قبض عميل آجل / صرف مورد مقابل عمومي؛ وقيم «جزء واحد لوغاريتماً» بالنسبة لأنواع دون كود وحيد.
 *
 * @return array{journal_type_id:int|null,journal_serial_bucket:string}
 */
function orange_journal_voucher_resolve_serial_meta(PDO $pdo, string $entryType, ?int $overrideJournalTypeId = null, ?int $countryId = null): array
{
    orange_catalog_ensure_schema($pdo);
    orange_journal_types_sync_canonical_defaults($pdo, $countryId);
    $ov = (int) ($overrideJournalTypeId ?? 0);
    if ($ov > 0) {
        return ['journal_type_id' => $ov, 'journal_serial_bucket' => 'JT' . $ov];
    }
    $code = orange_journal_type_code_from_entry_type($entryType);
    if ($code !== '') {
        $jid = orange_journal_type_id_by_code($pdo, $code, $countryId);
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
        'SELECT id, entry_type, fiscal_year_id, voucher_date'
        . (orange_table_has_country_id($pdo, 'journal_vouchers') ? ', country_id' : '')
        . ' FROM journal_vouchers WHERE voucher_serial <= 0 OR TRIM(COALESCE(journal_serial_bucket,\'\')) = \'\' ORDER BY COALESCE(fiscal_year_id,0) ASC, id ASC'
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
        $voucherCountryId = orange_table_has_country_id($pdo, 'journal_vouchers')
            ? (int) ($r['country_id'] ?? 0)
            : 0;
        $meta = orange_journal_voucher_resolve_serial_meta($pdo, $et, null, $voucherCountryId > 0 ? $voucherCountryId : null);
        $fyId = (int) ($r['fiscal_year_id'] ?? 0);
        $vdRaw = trim((string) ($r['voucher_date'] ?? ''));
        if ($fyId <= 0 && $vdRaw !== '') {
            orange_catalog_ensure_schema($pdo);
            $fy = orange_fiscal_find_for_date($pdo, $vdRaw, $voucherCountryId > 0 ? $voucherCountryId : null);
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
    $entryType = trim((string) ($header['entry_type'] ?? 'general'));
    if ($entryType === '') {
        $entryType = 'general';
    }

    $voucherCountryId = orange_journal_voucher_resolve_country_id($pdo, $header);
    $currencyCode = orange_gl_functional_currency_code($pdo, $voucherCountryId > 0 ? $voucherCountryId : null);

    $totalD = 0.0;
    $totalC = 0.0;
    $norm = [];
    $lineNo = 0;
    foreach ($lines as $ln) {
        $aid = (int) ($ln['account_id'] ?? 0);
        $d = orange_gl_round_money((float) ($ln['debit'] ?? 0), $currencyCode);
        $c = orange_gl_round_money((float) ($ln['credit'] ?? 0), $currencyCode);
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
    if (!orange_gl_money_is_balanced($totalD, $totalC, $currencyCode)) {
        throw new InvalidArgumentException('السند غير متوازن: مجموع المدين ' . $totalD . ' ≠ مجموع الدائن ' . $totalC);
    }

    orange_gl_assert_voucher_accounts_country(
        $pdo,
        array_column($norm, 'account_id'),
        $voucherCountryId
    );

    $fyId = orange_fiscal_require_open_for_posting($pdo, $date, $voucherCountryId > 0 ? $voucherCountryId : null);

    $overrideJt = isset($header['journal_type_id']) ? (int) $header['journal_type_id'] : 0;
    $metaSerial = orange_journal_voucher_resolve_serial_meta(
        $pdo,
        $entryType,
        $overrideJt > 0 ? $overrideJt : null,
        $voucherCountryId > 0 ? $voucherCountryId : null
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
        $referenceSql = orange_voucher_auto_reference(
            $pdo,
            $entryType,
            $nextSerial,
            $voucherCountryId > 0 ? $voucherCountryId : null,
            $jidSerial
        );

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
                    $referenceSql = orange_voucher_auto_reference(
                        $pdo,
                        $entryType,
                        $nextSerial,
                        $voucherCountryId > 0 ? $voucherCountryId : null,
                        $jidSerial
                    );
                }
            }
            if (!$inserted) {
                throw $lastErr instanceof Throwable
                    ? $lastErr
                    : new RuntimeException('تعذر تعيين رقم قيد لتسلسل اليومية.');
            }
        } elseif (orange_table_has_column($pdo, 'journal_vouchers', 'document_entered_at')) {
            $referenceSql = orange_voucher_auto_reference(
                $pdo,
                $entryType,
                1,
                $voucherCountryId > 0 ? $voucherCountryId : null,
                null
            );
            $pdo->prepare(
                'INSERT INTO journal_vouchers (voucher_date, document_entered_at, reference, description, entry_type, fiscal_year_id) VALUES (?,?,?,?,?,?)'
            )->execute([$date, $docEntered, $referenceSql, $description, $entryType, $fyId]);
        } else {
            $referenceSql = orange_voucher_auto_reference(
                $pdo,
                $entryType,
                1,
                $voucherCountryId > 0 ? $voucherCountryId : null,
                null
            );
            $pdo->prepare(
                'INSERT INTO journal_vouchers (voucher_date, reference, description, entry_type, fiscal_year_id) VALUES (?,?,?,?,?)'
            )->execute([$date, $referenceSql, $description, $entryType, $fyId]);
        }
        $vid = (int) $pdo->lastInsertId();
        if (!$hasSerialCols && $vid > 0) {
            $referenceSql = orange_voucher_auto_reference(
                $pdo,
                $entryType,
                $vid,
                $voucherCountryId > 0 ? $voucherCountryId : null,
                null
            );
            $pdo->prepare('UPDATE journal_vouchers SET reference = ? WHERE id = ?')->execute([$referenceSql, $vid]);
        }
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
    $ex = $pdo->prepare('SELECT id, reference FROM journal_vouchers WHERE id = ? LIMIT 1');
    $ex->execute([$voucherId]);
    $existing = $ex->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
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
    $referenceSql = trim((string) ($existing['reference'] ?? ''));
    if ($referenceSql === '') {
        $referenceSql = null;
    }

    $voucherCountryId = orange_journal_voucher_resolve_country_id($pdo, $header);
    $currencyCode = orange_gl_functional_currency_code($pdo, $voucherCountryId > 0 ? $voucherCountryId : null);

    $totalD = 0.0;
    $totalC = 0.0;
    $norm = [];
    $lineNo = 0;
    foreach ($postLines as $ln) {
        $aid = (int) ($ln['account_id'] ?? 0);
        $d = orange_gl_round_money((float) ($ln['debit'] ?? 0), $currencyCode);
        $c = orange_gl_round_money((float) ($ln['credit'] ?? 0), $currencyCode);
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
    if (!orange_gl_money_is_balanced($totalD, $totalC, $currencyCode)) {
        throw new InvalidArgumentException('السند غير متوازن: مجموع المدين ' . $totalD . ' ≠ مجموع الدائن ' . $totalC);
    }

    orange_gl_assert_voucher_accounts_country(
        $pdo,
        array_column($norm, 'account_id'),
        $voucherCountryId
    );

    $fyId = orange_fiscal_require_open_for_posting($pdo, $date, $voucherCountryId > 0 ? $voucherCountryId : null);
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
        orange_journal_voucher_stamp_currency($pdo, $voucherId, $header);
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
    $sql .= orange_journal_voucher_sql_exclude_void($pdo, 'jv');
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
    $sql .= orange_journal_voucher_sql_exclude_void($pdo, 'jv');
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
    $sql .= orange_journal_voucher_sql_exclude_void($pdo, 'jv');
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
 * @return array{id:int,code:string,name:string}|null
 */
function orange_journal_voucher_map_cash_account_for_country(PDO $pdo, int $accountId, int $preferCountryId): ?array
{
    if ($accountId <= 0 || !orange_table_exists($pdo, 'accounts')) {
        return null;
    }
    $cols = 'id, code, name';
    if (orange_table_has_country_id($pdo, 'accounts')) {
        $cols .= ', country_id';
    }
    $st = $pdo->prepare('SELECT ' . $cols . ' FROM accounts WHERE id = ? LIMIT 1');
    $st->execute([$accountId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    if ($preferCountryId > 0 && orange_table_has_country_id($pdo, 'accounts')) {
        $accCountry = (int) ($row['country_id'] ?? 0);
        if ($accCountry > 0 && $accCountry !== $preferCountryId) {
            $code = trim((string) ($row['code'] ?? ''));
            if ($code !== '') {
                $stMap = $pdo->prepare(
                    'SELECT id, code, name FROM accounts WHERE code = ? AND country_id = ? LIMIT 1'
                );
                $stMap->execute([$code, $preferCountryId]);
                $mapped = $stMap->fetch(PDO::FETCH_ASSOC);
                if ($mapped) {
                    $row = $mapped;
                }
            }
        }
    }
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    return [
        'id' => $id,
        'code' => (string) ($row['code'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
    ];
}

/**
 * حساب الخزينة لسند قبض/صرف — يحاول الدولة الحالية ثم الافتراضية ثم أي ربط cash في الإعدادات.
 *
 * @return array{id:int,code:string,name:string}|null
 */
function orange_journal_voucher_resolve_cash_account(PDO $pdo, ?int $countryId = null): ?array
{
    require_once __DIR__ . '/gl_settings.php';

    orange_catalog_ensure_schema($pdo);
    $ctxCountry = ($countryId !== null && $countryId > 0)
        ? $countryId
        : orange_admin_context_country_id($pdo);

    $tryCountries = [];
    if ($ctxCountry > 0) {
        $tryCountries[] = $ctxCountry;
    }
    $defaultId = orange_countries_default_id($pdo);
    if ($defaultId > 0 && !in_array($defaultId, $tryCountries, true)) {
        $tryCountries[] = $defaultId;
    }

    $accountIds = [];
    foreach ($tryCountries as $cid) {
        try {
            $opt = orange_gl_account_id_optional($pdo, 'cash', $cid);
            if ($opt !== null && $opt > 0) {
                $accountIds[] = (int) $opt;
            }
        } catch (Throwable $e) {
            // عرض الشاشة: لا نمنع سطر الخزينة إذا كان الربط ليس «ورقة ترحيل» بعد.
        }
        $raw = orange_gl_setting_bound_account_id_raw($pdo, 'cash', $cid);
        if ($raw > 0) {
            $accountIds[] = $raw;
        }
    }

    if (orange_table_exists($pdo, 'orange_gl_account_settings')) {
        $stAny = $pdo->query(
            "SELECT account_id FROM orange_gl_account_settings
             WHERE setting_key = 'cash' AND account_id > 0
             ORDER BY country_id ASC"
        );
        foreach ($stAny->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $accountIds[] = (int) ($r['account_id'] ?? 0);
        }
    }

    $seen = [];
    foreach ($accountIds as $accId) {
        if ($accId <= 0 || isset($seen[$accId])) {
            continue;
        }
        $seen[$accId] = true;
        $row = orange_journal_voucher_map_cash_account_for_country($pdo, $accId, $ctxCountry);
        if ($row !== null) {
            return $row;
        }
    }

    return null;
}

/**
 * @return array{id:int,code:string,name:string,placement:string}|null
 */
function orange_journal_voucher_cash_lock_for_screen(PDO $pdo, string $entryType, ?int $countryId = null): ?array
{
    if (!in_array($entryType, ['receipt_voucher', 'payment_voucher'], true)) {
        return null;
    }
    $acc = orange_journal_voucher_resolve_cash_account($pdo, $countryId);
    if ($acc === null || (int) ($acc['id'] ?? 0) <= 0) {
        return null;
    }

    return [
        'id' => (int) $acc['id'],
        'code' => (string) ($acc['code'] ?? ''),
        'name' => (string) ($acc['name'] ?? ''),
        'placement' => $entryType === 'receipt_voucher' ? 'last' : 'first',
    ];
}
