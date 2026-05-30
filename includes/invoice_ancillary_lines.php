<?php

declare(strict_types=1);

require_once __DIR__ . '/invoice_ancillary_lines_schema.php';
require_once __DIR__ . '/account_tree.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/gl_settings.php';

/** doc_kind على orange_invoice_extra_lines */
function orange_invoice_ancillary_doc_kind_purchase(): string
{
    return 'purchase';
}

function orange_invoice_ancillary_doc_kind_sales(): string
{
    return 'sales';
}

/**
 * @return list<string>
 */
function orange_invoice_ancillary_doc_kinds(): array
{
    return [
        orange_invoice_ancillary_doc_kind_purchase(),
        orange_invoice_ancillary_doc_kind_sales(),
    ];
}

/**
 * @return list<string>
 */
function orange_invoice_ancillary_invoice_contexts(): array
{
    return ['sales', 'purchase', 'both'];
}

/**
 * @return array<string, array{label_ar: string, side: string, contexts: list<string>}>
 */
function orange_invoice_ancillary_line_kind_catalog(): array
{
    return [
        'sales_credit_revenue' => [
            'label_ar' => 'مبيعات — دائن (إيراد/شحن/رسوم)',
            'side' => 'credit',
            'contexts' => ['sales', 'both'],
        ],
        'sales_debit_contra' => [
            'label_ar' => 'مبيعات — مدين (خصم مسموح)',
            'side' => 'debit',
            'contexts' => ['sales', 'both'],
        ],
        'sales_credit_liability' => [
            'label_ar' => 'مبيعات — دائن (VAT مستحق)',
            'side' => 'credit',
            'contexts' => ['sales', 'both'],
        ],
        'purchase_debit_asset' => [
            'label_ar' => 'مشتريات — مدين (مخزون/أصل)',
            'side' => 'debit',
            'contexts' => ['purchase', 'both'],
        ],
        'purchase_debit_landed' => [
            'label_ar' => 'مشتريات — مدين (شحن توريد/landed cost)',
            'side' => 'debit',
            'contexts' => ['purchase', 'both'],
        ],
        'purchase_debit_vat_input' => [
            'label_ar' => 'مشتريات — مدين (VAT مدخلات)',
            'side' => 'debit',
            'contexts' => ['purchase', 'both'],
        ],
        'purchase_credit_contra' => [
            'label_ar' => 'مشتريات — دائن (خصم مكتسب)',
            'side' => 'credit',
            'contexts' => ['purchase', 'both'],
        ],
    ];
}

function orange_invoice_ancillary_line_kind_is_valid(string $lineKind): bool
{
    return isset(orange_invoice_ancillary_line_kind_catalog()[trim($lineKind)]);
}

/**
 * @return 'debit'|'credit'|null
 */
function orange_invoice_ancillary_line_kind_side(string $lineKind): ?string
{
    $cat = orange_invoice_ancillary_line_kind_catalog();
    $k = trim($lineKind);

    return isset($cat[$k]) ? (string) $cat[$k]['side'] : null;
}

function orange_invoice_ancillary_context_for_doc_kind(string $docKind): string
{
    return $docKind === orange_invoice_ancillary_doc_kind_sales() ? 'sales' : 'purchase';
}

function orange_invoice_ancillary_context_matches(string $invoiceContext, string $docKind): bool
{
    $ctx = trim($invoiceContext);
    if ($ctx === 'both') {
        return true;
    }
    $need = orange_invoice_ancillary_context_for_doc_kind($docKind);

    return $ctx === $need;
}

function orange_invoice_ancillary_line_kind_matches_context(string $lineKind, string $invoiceContext): bool
{
    $cat = orange_invoice_ancillary_line_kind_catalog();
    $k = trim($lineKind);
    $ctx = trim($invoiceContext);
    if (!isset($cat[$k])) {
        return false;
    }
    if ($ctx === 'both') {
        return true;
    }

    return in_array($ctx, $cat[$k]['contexts'], true);
}

function orange_invoice_ancillary_tables_ready(PDO $pdo): bool
{
    orange_catalog_ensure_invoice_ancillary_lines_schema($pdo);

    return orange_table_exists($pdo, 'orange_invoice_line_presets')
        && orange_table_exists($pdo, 'orange_invoice_extra_lines');
}

function orange_invoice_ancillary_doc_kind_for_line_kind(string $lineKind): string
{
    $k = trim($lineKind);

    return str_starts_with($k, 'sales_')
        ? orange_invoice_ancillary_doc_kind_sales()
        : orange_invoice_ancillary_doc_kind_purchase();
}

/**
 * @throws RuntimeException
 */
function orange_invoice_ancillary_assert_account_for_line(
    PDO $pdo,
    int $accountId,
    int $countryId,
    string $lineKind,
    string $docKind
): void {
    if ($accountId <= 0) {
        throw new RuntimeException('حساب البند غير صالح.');
    }
    if (!orange_invoice_ancillary_line_kind_is_valid($lineKind)) {
        throw new RuntimeException('نوع البند غير معتمد.');
    }
    $ctx = orange_invoice_ancillary_context_for_doc_kind($docKind);
    if (!orange_invoice_ancillary_line_kind_matches_context($lineKind, $ctx)) {
        throw new RuntimeException('نوع البند لا ينطبق على هذا المستند.');
    }
    if (!orange_accounts_account_is_posting_leaf($pdo, $accountId)) {
        throw new RuntimeException('يجب اختيار حساب ورقة للترحيل — لا حساب مجموعة.');
    }
    $acctCountry = orange_account_country_id($pdo, $accountId);
    if ($countryId > 0 && $acctCountry > 0 && $acctCountry !== $countryId) {
        throw new RuntimeException('حساب البند لا ينتمي لدولة المستند.');
    }
}

/**
 * @return list<array<string, mixed>>
 */
function orange_invoice_ancillary_presets_list(
    PDO $pdo,
    ?int $countryId = null,
    ?string $invoiceContext = null,
    ?string $search = null,
    bool $activeOnly = true
): array {
    if (!orange_invoice_ancillary_tables_ready($pdo)) {
        return [];
    }
    $cid = orange_gl_settings_effective_country_id($pdo, $countryId);
    $sql = 'SELECT p.*, a.code AS account_code, a.name AS account_name
            FROM orange_invoice_line_presets p
            INNER JOIN accounts a ON a.id = p.account_id
            WHERE p.country_id = ?';
    $params = [$cid];
    if ($activeOnly) {
        $sql .= ' AND p.is_active = 1';
    }
    if ($invoiceContext !== null && trim($invoiceContext) !== '' && trim($invoiceContext) !== 'both') {
        $sql .= ' AND (p.invoice_context = ? OR p.invoice_context = \'both\')';
        $params[] = trim($invoiceContext);
    }
    $q = trim((string) ($search ?? ''));
    if ($q !== '') {
        $sql .= ' AND (p.label_ar LIKE ? OR p.label_en LIKE ? OR a.code LIKE ? OR a.name LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY p.sort_order ASC, p.id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

/**
 * @param array<string, mixed> $data
 * @return int preset id
 *
 * @throws RuntimeException
 */
function orange_invoice_ancillary_preset_save(PDO $pdo, array $data): int
{
    orange_catalog_ensure_invoice_ancillary_lines_schema($pdo);
    $id = (int) ($data['id'] ?? 0);
    $countryId = orange_gl_settings_effective_country_id($pdo, isset($data['country_id']) ? (int) $data['country_id'] : null);
    $accountId = (int) ($data['account_id'] ?? 0);
    $lineKind = trim((string) ($data['line_kind'] ?? ''));
    $invoiceContext = trim((string) ($data['invoice_context'] ?? 'both'));
    if (!in_array($invoiceContext, orange_invoice_ancillary_invoice_contexts(), true)) {
        throw new RuntimeException('سياق الفاتورة غير صالح.');
    }
    if (!orange_invoice_ancillary_line_kind_is_valid($lineKind)) {
        throw new RuntimeException('نوع البند غير معتمد.');
    }
    if (!orange_invoice_ancillary_line_kind_matches_context($lineKind, $invoiceContext)) {
        throw new RuntimeException('نوع البند لا يطابق سياق الفاتورة.');
    }
    $docKindForAssert = $invoiceContext === 'sales'
        ? orange_invoice_ancillary_doc_kind_sales()
        : ($invoiceContext === 'purchase'
            ? orange_invoice_ancillary_doc_kind_purchase()
            : orange_invoice_ancillary_doc_kind_for_line_kind($lineKind));
    orange_invoice_ancillary_assert_account_for_line(
        $pdo,
        $accountId,
        $countryId,
        $lineKind,
        $docKindForAssert
    );
    $labelAr = trim((string) ($data['label_ar'] ?? ''));
    $labelEn = trim((string) ($data['label_en'] ?? ''));
    if ($labelAr === '') {
        $stNm = $pdo->prepare('SELECT name FROM accounts WHERE id = ? LIMIT 1');
        $stNm->execute([$accountId]);
        $labelAr = trim((string) $stNm->fetchColumn());
    }
    $defaultShow = !empty($data['default_show_on_print']) ? 1 : 0;
    $sortOrder = (int) ($data['sort_order'] ?? 0);
    $isActive = !array_key_exists('is_active', $data) || !empty($data['is_active']) ? 1 : 0;

    if ($id > 0) {
        $pdo->prepare(
            'UPDATE orange_invoice_line_presets
             SET account_id = ?, label_ar = ?, label_en = ?, invoice_context = ?, line_kind = ?,
                 default_show_on_print = ?, sort_order = ?, is_active = ?
             WHERE id = ? AND country_id = ?'
        )->execute([
            $accountId,
            $labelAr,
            $labelEn,
            $invoiceContext,
            $lineKind,
            $defaultShow,
            $sortOrder,
            $isActive,
            $id,
            $countryId,
        ]);

        return $id;
    }

    $pdo->prepare(
        'INSERT INTO orange_invoice_line_presets
            (country_id, account_id, label_ar, label_en, invoice_context, line_kind,
             default_show_on_print, sort_order, is_active)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        $countryId,
        $accountId,
        $labelAr,
        $labelEn,
        $invoiceContext,
        $lineKind,
        $defaultShow,
        $sortOrder,
        $isActive,
    ]);

    return (int) $pdo->lastInsertId();
}

function orange_invoice_ancillary_preset_deactivate(PDO $pdo, int $presetId, ?int $countryId = null): bool
{
    if ($presetId <= 0 || !orange_table_exists($pdo, 'orange_invoice_line_presets')) {
        return false;
    }
    $cid = orange_gl_settings_effective_country_id($pdo, $countryId);
    $st = $pdo->prepare('UPDATE orange_invoice_line_presets SET is_active = 0 WHERE id = ? AND country_id = ?');
    $st->execute([$presetId, $cid]);

    return $st->rowCount() > 0;
}

/**
 * @return list<array<string, mixed>>
 */
function orange_invoice_ancillary_extra_lines_for_doc(PDO $pdo, string $docKind, int $docId): array
{
    if ($docId <= 0 || !orange_invoice_ancillary_tables_ready($pdo)) {
        return [];
    }
    $docKind = trim($docKind);
    if (!in_array($docKind, orange_invoice_ancillary_doc_kinds(), true)) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT e.*, a.code AS account_code, a.name AS account_name
         FROM orange_invoice_extra_lines e
         INNER JOIN accounts a ON a.id = e.account_id
         WHERE e.doc_kind = ? AND e.doc_id = ?
         ORDER BY e.sort_order ASC, e.id ASC'
    );
    $st->execute([$docKind, $docId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

function orange_invoice_ancillary_extra_lines_delete_for_doc(PDO $pdo, string $docKind, int $docId): void
{
    if ($docId <= 0 || !orange_table_exists($pdo, 'orange_invoice_extra_lines')) {
        return;
    }
    $pdo->prepare('DELETE FROM orange_invoice_extra_lines WHERE doc_kind = ? AND doc_id = ?')
        ->execute([trim($docKind), $docId]);
}

/**
 * @param list<array<string, mixed>> $lines
 *
 * @throws RuntimeException
 */
function orange_invoice_ancillary_extra_lines_replace_for_doc(
    PDO $pdo,
    string $docKind,
    int $docId,
    int $countryId,
    array $lines
): void {
    orange_catalog_ensure_invoice_ancillary_lines_schema($pdo);
    if ($docId <= 0) {
        throw new RuntimeException('معرف المستند غير صالح.');
    }
    $docKind = trim($docKind);
    if (!in_array($docKind, orange_invoice_ancillary_doc_kinds(), true)) {
        throw new RuntimeException('نوع المستند غير صالح.');
    }
    $cid = orange_gl_settings_effective_country_id($pdo, $countryId);

    orange_invoice_ancillary_extra_lines_delete_for_doc($pdo, $docKind, $docId);

    if ($lines === []) {
        return;
    }

    $ins = $pdo->prepare(
        'INSERT INTO orange_invoice_extra_lines
            (doc_kind, doc_id, country_id, account_id, line_kind, amount, label_ar, show_on_print, sort_order, preset_id)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );

    $sort = 0;
    foreach ($lines as $row) {
        if (!is_array($row)) {
            continue;
        }
        $amount = round((float) ($row['amount'] ?? 0), 4);
        if ($amount <= 0.0001) {
            continue;
        }
        $accountId = (int) ($row['account_id'] ?? 0);
        $lineKind = trim((string) ($row['line_kind'] ?? ''));
        orange_invoice_ancillary_assert_account_for_line($pdo, $accountId, $cid, $lineKind, $docKind);
        $labelAr = trim((string) ($row['label_ar'] ?? ''));
        $show = !empty($row['show_on_print']) ? 1 : 0;
        $presetId = isset($row['preset_id']) && (int) $row['preset_id'] > 0 ? (int) $row['preset_id'] : null;
        $sort++;
        $ins->execute([
            $docKind,
            $docId,
            $cid,
            $accountId,
            $lineKind,
            $amount,
            $labelAr,
            $show,
            $sort,
            $presetId,
        ]);
    }
}

/**
 * @param array<string, mixed> $line row from orange_invoice_extra_lines
 * @return array{account_id: int, debit: float, credit: float, memo: string}|null
 */
function orange_invoice_ancillary_extra_line_journal_row(array $line): ?array
{
    $accountId = (int) ($line['account_id'] ?? 0);
    $amount = round((float) ($line['amount'] ?? 0), 4);
    if ($accountId <= 0 || $amount <= 0.0001) {
        return null;
    }
    $side = orange_invoice_ancillary_line_kind_side((string) ($line['line_kind'] ?? ''));
    if ($side === null) {
        return null;
    }
    $memo = trim((string) ($line['label_ar'] ?? ''));
    if ($memo === '') {
        $memo = 'بند فاتورة';
    }

    return [
        'account_id' => $accountId,
        'debit' => $side === 'debit' ? $amount : 0.0,
        'credit' => $side === 'credit' ? $amount : 0.0,
        'memo' => $memo,
    ];
}

/**
 * @param list<array<string, mixed>> $extraLines
 * @return list<array{account_id: int, debit: float, credit: float, memo: string}>
 */
function orange_invoice_ancillary_extra_lines_journal_rows(array $extraLines): array
{
    $out = [];
    foreach ($extraLines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $jr = orange_invoice_ancillary_extra_line_journal_row($line);
        if ($jr !== null) {
            $out[] = $jr;
        }
    }

    return $out;
}

/**
 * @return array{debit: float, credit: float}
 */
function orange_invoice_ancillary_extra_lines_totals(array $extraLines): array
{
    $debit = 0.0;
    $credit = 0.0;
    foreach (orange_invoice_ancillary_extra_lines_journal_rows($extraLines) as $jr) {
        $debit += (float) $jr['debit'];
        $credit += (float) $jr['credit'];
    }

    return ['debit' => round($debit, 4), 'credit' => round($credit, 4)];
}
