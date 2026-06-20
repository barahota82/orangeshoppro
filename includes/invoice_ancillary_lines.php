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

function orange_invoice_ancillary_doc_kind_sales_return(): string
{
    return 'sales_return';
}

/**
 * @return list<string>
 */
function orange_invoice_ancillary_doc_kinds(): array
{
    return [
        orange_invoice_ancillary_doc_kind_purchase(),
        orange_invoice_ancillary_doc_kind_sales(),
        orange_invoice_ancillary_doc_kind_sales_return(),
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
            'label_ar' => 'دائن — إيراد للشركة (شحن/رسوم خدمة)',
            'side' => 'credit',
            'contexts' => ['sales', 'both'],
        ],
        'sales_debit_contra' => [
            'label_ar' => 'مدين — خصم مسموح للعميل (يُخصم من الفاتورة)',
            'side' => 'debit',
            'contexts' => ['sales', 'both'],
        ],
        'sales_credit_liability' => [
            'label_ar' => 'دائن — ضريبة مستحقة للدولة (VAT)',
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
    if ($docKind === orange_invoice_ancillary_doc_kind_sales()
        || $docKind === orange_invoice_ancillary_doc_kind_sales_return()) {
        return 'sales';
    }

    return 'purchase';
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

/**
 * مفاتيح نظامية محجوزة للربط التلقائي بين preset ومنطق الفاتورة.
 *
 * @return array<string, array{label_ar:string, invoice_context:string, line_kind:string}>
 */
function orange_invoice_ancillary_system_key_catalog(): array
{
    return [
        'delivery_fee_charge' => [
            'label_ar' => 'رسوم التوصيل',
            'invoice_context' => 'sales',
            'line_kind' => 'sales_credit_revenue',
        ],
        'delivery_fee_discount' => [
            'label_ar' => 'خصم رسوم التوصيل',
            'invoice_context' => 'sales',
            'line_kind' => 'sales_debit_contra',
        ],
        'promo_cart_discount' => [
            'label_ar' => 'خصم السلة (عرض ترويجي)',
            'invoice_context' => 'sales',
            'line_kind' => 'sales_debit_contra',
        ],
        'promo_combo_discount' => [
            'label_ar' => 'خصم الكومبو (عرض ترويجي)',
            'invoice_context' => 'sales',
            'line_kind' => 'sales_debit_contra',
        ],
        'promo_gift_discount' => [
            'label_ar' => 'خصم هدية ترويجية',
            'invoice_context' => 'sales',
            'line_kind' => 'sales_debit_contra',
        ],
        'promo_bogo_discount' => [
            'label_ar' => 'خصم اشترِ واحصل (BOGO)',
            'invoice_context' => 'sales',
            'line_kind' => 'sales_debit_contra',
        ],
        'product_offer_discount' => [
            'label_ar' => 'خصم عرض المنتج',
            'invoice_context' => 'sales',
            'line_kind' => 'sales_debit_contra',
        ],
        'loyalty_points_redemption' => [
            'label_ar' => 'خصم استبدال نقاط الولاء',
            'invoice_context' => 'sales',
            'line_kind' => 'sales_debit_contra',
        ],
    ];
}

/**
 * @return list<string>
 */
function orange_invoice_ancillary_system_key_values(): array
{
    return array_keys(orange_invoice_ancillary_system_key_catalog());
}

function orange_invoice_ancillary_system_key_normalize(?string $raw): ?string
{
    $v = strtolower(trim((string) $raw));
    if ($v === '') {
        return null;
    }

    return in_array($v, orange_invoice_ancillary_system_key_values(), true) ? $v : null;
}

function orange_invoice_ancillary_system_key_meta(string $systemKey): ?array
{
    $key = orange_invoice_ancillary_system_key_normalize($systemKey);
    if ($key === null) {
        return null;
    }
    $cat = orange_invoice_ancillary_system_key_catalog();

    return $cat[$key] ?? null;
}

/**
 * @return list<string>
 */
function orange_invoice_ancillary_auto_delivery_system_keys(): array
{
    return ['delivery_fee_charge', 'delivery_fee_discount'];
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
    $hasSystemKey = orange_table_has_column($pdo, 'orange_invoice_line_presets', 'system_key');
    $systemKeySelect = $hasSystemKey ? ', p.system_key' : ', NULL AS system_key';
    $sql = 'SELECT p.*, a.code AS account_code, a.name AS account_name
            ' . $systemKeySelect . '
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
        if ($hasSystemKey) {
            $sql .= ' AND (p.label_ar LIKE ? OR p.label_en LIKE ? OR p.system_key LIKE ? OR a.code LIKE ? OR a.name LIKE ?)';
        } else {
            $sql .= ' AND (p.label_ar LIKE ? OR p.label_en LIKE ? OR a.code LIKE ? OR a.name LIKE ?)';
        }
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        if ($hasSystemKey) {
            $params[] = $like;
        }
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
    $systemKeyRaw = trim((string) ($data['system_key'] ?? ''));
    $systemKey = null;
    if ($systemKeyRaw !== '') {
        $systemKey = orange_invoice_ancillary_system_key_normalize($systemKeyRaw);
        if ($systemKey === null) {
            throw new RuntimeException('مفتاح النظام غير صالح.');
        }
    }
    if (!in_array($invoiceContext, orange_invoice_ancillary_invoice_contexts(), true)) {
        throw new RuntimeException('سياق الفاتورة غير صالح.');
    }
    if (!orange_invoice_ancillary_line_kind_is_valid($lineKind)) {
        throw new RuntimeException('نوع البند غير معتمد.');
    }
    if (!orange_invoice_ancillary_line_kind_matches_context($lineKind, $invoiceContext)) {
        throw new RuntimeException('نوع البند لا يطابق سياق الفاتورة.');
    }
    if ($systemKey !== null) {
        $meta = orange_invoice_ancillary_system_key_meta($systemKey);
        if ($meta === null) {
            throw new RuntimeException('مفتاح النظام غير معتمد.');
        }
        if ($invoiceContext !== (string) ($meta['invoice_context'] ?? 'sales')) {
            throw new RuntimeException('هذا مفتاح نظامي مخصص لسياق مبيعات فقط.');
        }
        if ($lineKind !== (string) ($meta['line_kind'] ?? '')) {
            throw new RuntimeException('نوع البند لا يطابق مفتاح النظام المختار.');
        }
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
    $hasSystemKeyCol = orange_table_has_column($pdo, 'orange_invoice_line_presets', 'system_key');

    if ($id > 0) {
        try {
            $updateSql = 'UPDATE orange_invoice_line_presets
                 SET account_id = ?, label_ar = ?, label_en = ?, invoice_context = ?, line_kind = ?,';
            if ($hasSystemKeyCol) {
                $updateSql .= ' system_key = ?,';
            }
            $updateSql .= ' default_show_on_print = ?, sort_order = ?, is_active = ?
                 WHERE id = ? AND country_id = ?';
            $params = [
                $accountId,
                $labelAr,
                $labelEn,
                $invoiceContext,
                $lineKind,
            ];
            if ($hasSystemKeyCol) {
                $params[] = $systemKey;
            }
            $params[] = $defaultShow;
            $params[] = $sortOrder;
            $params[] = $isActive;
            $params[] = $id;
            $params[] = $countryId;
            $pdo->prepare($updateSql)->execute($params);
        } catch (PDOException $e) {
            $dbCode = (int) ($e->errorInfo[1] ?? 0);
            if ($dbCode === 1062 && str_contains($e->getMessage(), 'uq_oilp_country_system_key')) {
                throw new RuntimeException('مفتاح النظام مستخدم مسبقاً في preset آخر لنفس الدولة.');
            }
            throw $e;
        }

        return $id;
    }

    try {
        if ($hasSystemKeyCol) {
            $pdo->prepare(
                'INSERT INTO orange_invoice_line_presets
                    (country_id, account_id, label_ar, label_en, invoice_context, line_kind, system_key,
                     default_show_on_print, sort_order, is_active)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $countryId,
                $accountId,
                $labelAr,
                $labelEn,
                $invoiceContext,
                $lineKind,
                $systemKey,
                $defaultShow,
                $sortOrder,
                $isActive,
            ]);
        } else {
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
        }
    } catch (PDOException $e) {
        $dbCode = (int) ($e->errorInfo[1] ?? 0);
        if ($dbCode === 1062 && str_contains($e->getMessage(), 'uq_oilp_country_system_key')) {
            throw new RuntimeException('مفتاح النظام مستخدم مسبقاً في preset آخر لنفس الدولة.');
        }
        throw $e;
    }

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

function orange_invoice_ancillary_preset_next_sort(PDO $pdo, ?int $countryId = null): int
{
    if (!orange_table_exists($pdo, 'orange_invoice_line_presets')) {
        return 1;
    }
    $cid = orange_gl_settings_effective_country_id($pdo, $countryId);
    $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM orange_invoice_line_presets WHERE country_id = ?');
    $st->execute([$cid]);
    $n = (int) $st->fetchColumn();

    return $n > 0 ? $n : 1;
}

/**
 * @param list<int> $orderedIds
 *
 * @throws RuntimeException
 */
function orange_invoice_ancillary_presets_reorder(PDO $pdo, array $orderedIds, ?int $countryId = null): void
{
    orange_catalog_ensure_invoice_ancillary_lines_schema($pdo);
    $cid = orange_gl_settings_effective_country_id($pdo, $countryId);
    if ($orderedIds === []) {
        return;
    }
    $sort = 0;
    $st = $pdo->prepare(
        'UPDATE orange_invoice_line_presets SET sort_order = ? WHERE id = ? AND country_id = ?'
    );
    foreach ($orderedIds as $rawId) {
        $id = (int) $rawId;
        if ($id <= 0) {
            continue;
        }
        $sort++;
        $st->execute([$sort, $id, $cid]);
        if ($st->rowCount() === 0) {
            throw new RuntimeException('تعذر إعادة ترتيب البند #' . $id . ' — تحقق من الدولة.');
        }
    }
}

/**
 * @return array<string, array<string,mixed>>
 */
function orange_invoice_ancillary_system_key_presets_map(PDO $pdo, int $countryId, bool $activeOnly = true): array
{
    if (!orange_invoice_ancillary_tables_ready($pdo)) {
        return [];
    }
    if (!orange_table_has_column($pdo, 'orange_invoice_line_presets', 'system_key')) {
        return [];
    }
    $keys = orange_invoice_ancillary_system_key_values();
    if ($keys === []) {
        return [];
    }
    $cid = orange_gl_settings_effective_country_id($pdo, $countryId);
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $sql = 'SELECT p.*, a.code AS account_code, a.name AS account_name
            FROM orange_invoice_line_presets p
            INNER JOIN accounts a ON a.id = p.account_id
            WHERE p.country_id = ?
              AND p.system_key IN (' . $ph . ')';
    if ($activeOnly) {
        $sql .= ' AND p.is_active = 1';
    }
    $sql .= ' ORDER BY p.id ASC';
    $params = [$cid];
    foreach ($keys as $k) {
        $params[] = $k;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }
    $map = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = orange_invoice_ancillary_system_key_normalize((string) ($row['system_key'] ?? ''));
        if ($key === null || isset($map[$key])) {
            continue;
        }
        $map[$key] = $row;
    }

    return $map;
}

/**
 * @param array<string,mixed> $documentTotals
 * @return array{base_fee:float,discount_fee:float,fee:float}
 */
function orange_invoice_ancillary_delivery_amounts(array $documentTotals): array
{
    $net = round(max(0.0, (float) ($documentTotals['delivery_fee'] ?? 0)), 4);
    $base = round(max(0.0, (float) ($documentTotals['delivery_fee_base'] ?? 0)), 4);
    $discount = round(max(0.0, (float) ($documentTotals['delivery_fee_discount'] ?? 0)), 4);

    if ($base <= 0.0001 && $net > 0.0001) {
        $base = $net;
    }
    if ($discount <= 0.0001 && $base > $net + 0.0001) {
        $discount = round($base - $net, 4);
    }
    if ($discount > $base) {
        $discount = $base;
    }
    $net = round(max(0.0, $base - $discount), 4);

    return [
        'base_fee' => $base,
        'discount_fee' => $discount,
        'fee' => $net,
    ];
}

/**
 * @param array<string,mixed> $documentTotals
 * @return list<array<string,mixed>>
 */
function orange_invoice_ancillary_auto_delivery_lines(PDO $pdo, int $countryId, array $documentTotals): array
{
    $presetMap = orange_invoice_ancillary_system_key_presets_map($pdo, $countryId, true);
    if ($presetMap === []) {
        return [];
    }
    $fees = orange_invoice_ancillary_delivery_amounts($documentTotals);
    $baseFee = (float) ($fees['base_fee'] ?? 0);
    $discountFee = (float) ($fees['discount_fee'] ?? 0);
    $keys = orange_invoice_ancillary_auto_delivery_system_keys();
    $out = [];

    foreach ($keys as $key) {
        $preset = $presetMap[$key] ?? null;
        if (!is_array($preset)) {
            continue;
        }
        $meta = orange_invoice_ancillary_system_key_meta($key) ?? [];
        $amount = $key === 'delivery_fee_discount' ? $discountFee : $baseFee;
        if ($amount <= 0.0001) {
            continue;
        }
        $lineKind = trim((string) ($preset['line_kind'] ?? ($meta['line_kind'] ?? '')));
        $accountId = (int) ($preset['account_id'] ?? 0);
        if ($accountId <= 0 || !orange_invoice_ancillary_line_kind_is_valid($lineKind)) {
            continue;
        }
        $labelAr = trim((string) ($preset['label_ar'] ?? ''));
        if ($labelAr === '') {
            $labelAr = (string) ($meta['label_ar'] ?? 'بند تلقائي');
        }
        $out[] = [
            'account_id' => $accountId,
            'line_kind' => $lineKind,
            'amount' => round($amount, 4),
            'label_ar' => $labelAr,
            'show_on_print' => !empty($preset['default_show_on_print']) ? 1 : 0,
            'preset_id' => (int) ($preset['id'] ?? 0) > 0 ? (int) ($preset['id'] ?? 0) : null,
            'system_key' => $key,
            'auto_delivery' => 1,
        ];
    }

    return $out;
}

/**
 * @param array<string,mixed> $documentTotals
 * @param list<array<string,mixed>> $extraInput
 * @return list<array<string,mixed>>
 */
function orange_invoice_ancillary_merge_auto_delivery_lines(
    PDO $pdo,
    int $countryId,
    array $documentTotals,
    array $extraInput
): array {
    $systemKeys = orange_invoice_ancillary_auto_delivery_system_keys();
    $presetMapAny = orange_invoice_ancillary_system_key_presets_map($pdo, $countryId, false);
    $autoPresetIds = [];
    foreach ($presetMapAny as $row) {
        if (!is_array($row)) {
            continue;
        }
        $presetId = (int) ($row['id'] ?? 0);
        if ($presetId > 0) {
            $autoPresetIds[$presetId] = true;
        }
    }

    $out = [];
    foreach ($extraInput as $line) {
        if (!is_array($line)) {
            continue;
        }
        $lineKey = orange_invoice_ancillary_system_key_normalize((string) ($line['system_key'] ?? ''));
        if ($lineKey !== null && in_array($lineKey, $systemKeys, true)) {
            continue;
        }
        $linePresetId = (int) ($line['preset_id'] ?? 0);
        if ($linePresetId > 0 && isset($autoPresetIds[$linePresetId])) {
            continue;
        }
        $out[] = $line;
    }

    foreach (orange_invoice_ancillary_auto_delivery_lines($pdo, $countryId, $documentTotals) as $autoLine) {
        $out[] = $autoLine;
    }

    return $out;
}

/**
 * مفاتيح الخصومات الترويجية/الولاء التلقائية (sales_debit_contra) المولّدة من قيم الطلب.
 * كلها تحت «خصومات العروض الترويجية»/«خصم استبدال النقاط» حسب ربط الأدمن للحساب عبر system_key.
 *
 * @return list<string>
 */
function orange_invoice_ancillary_auto_promo_system_keys(): array
{
    return [
        'promo_combo_discount',
        'promo_cart_discount',
        'promo_gift_discount',
        'promo_bogo_discount',
        'product_offer_discount',
        'loyalty_points_redemption',
    ];
}

/**
 * يولّد بنود خصم ترويجي/ولاء تلقائية من قيم الطلب (system_key => amount) وفق presets الدولة.
 * لا يُولَّد بند لمفتاح بلا preset مربوط (يبقى السلوك كما كان حتى يربط الأدمن الحساب).
 *
 * @param array<string, float> $amountsByKey
 * @return list<array<string,mixed>>
 */
function orange_invoice_ancillary_auto_promo_lines(PDO $pdo, int $countryId, array $amountsByKey): array
{
    $presetMap = orange_invoice_ancillary_system_key_presets_map($pdo, $countryId, true);
    if ($presetMap === []) {
        return [];
    }
    $out = [];
    foreach (orange_invoice_ancillary_auto_promo_system_keys() as $key) {
        $amount = round((float) ($amountsByKey[$key] ?? 0), 4);
        if ($amount <= 0.0001) {
            continue;
        }
        $preset = $presetMap[$key] ?? null;
        if (!is_array($preset)) {
            continue;
        }
        $meta = orange_invoice_ancillary_system_key_meta($key) ?? [];
        $lineKind = trim((string) ($preset['line_kind'] ?? ($meta['line_kind'] ?? '')));
        $accountId = (int) ($preset['account_id'] ?? 0);
        if ($accountId <= 0 || !orange_invoice_ancillary_line_kind_is_valid($lineKind)) {
            continue;
        }
        $labelAr = trim((string) ($preset['label_ar'] ?? ''));
        if ($labelAr === '') {
            $labelAr = (string) ($meta['label_ar'] ?? 'خصم ترويجي');
        }
        $out[] = [
            'account_id' => $accountId,
            'line_kind' => $lineKind,
            'amount' => round($amount, 4),
            'label_ar' => $labelAr,
            'show_on_print' => !empty($preset['default_show_on_print']) ? 1 : 0,
            'preset_id' => (int) ($preset['id'] ?? 0) > 0 ? (int) ($preset['id'] ?? 0) : null,
            'system_key' => $key,
            'auto_promo' => 1,
        ];
    }

    return $out;
}

/**
 * يدمج بنود الخصم الترويجي/الولاء التلقائية مع المُدخلة (يزيل أي بند بنفس مفتاح ترويجي لتفادي الازدواج).
 *
 * @param array<string, float> $amountsByKey
 * @param list<array<string,mixed>> $extraInput
 * @return list<array<string,mixed>>
 */
function orange_invoice_ancillary_merge_auto_promo_lines(
    PDO $pdo,
    int $countryId,
    array $amountsByKey,
    array $extraInput
): array {
    $systemKeys = orange_invoice_ancillary_auto_promo_system_keys();
    $out = [];
    foreach ($extraInput as $line) {
        if (!is_array($line)) {
            continue;
        }
        $lineKey = orange_invoice_ancillary_system_key_normalize((string) ($line['system_key'] ?? ''));
        if ($lineKey !== null && in_array($lineKey, $systemKeys, true)) {
            continue;
        }
        $out[] = $line;
    }
    foreach (orange_invoice_ancillary_auto_promo_lines($pdo, $countryId, $amountsByKey) as $autoLine) {
        $out[] = $autoLine;
    }

    return $out;
}

/**
 * @return array<string, string>
 */
function orange_invoice_ancillary_line_kind_label(string $lineKind): string
{
    $cat = orange_invoice_ancillary_line_kind_catalog();
    $k = trim($lineKind);

    return isset($cat[$k]) ? (string) $cat[$k]['label_ar'] : $k;
}

/**
 * @return array<string, string>
 */
function orange_invoice_ancillary_invoice_context_labels(): array
{
    return [
        'purchase' => 'مشتريات',
        'sales' => 'مبيعات',
        'both' => 'الاثنان',
    ];
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
        'SELECT e.*, a.code AS account_code, a.name AS account_name,
                p.system_key AS system_key
         FROM orange_invoice_extra_lines e
         INNER JOIN accounts a ON a.id = e.account_id
         LEFT JOIN orange_invoice_line_presets p ON p.id = e.preset_id
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
/**
 * بند VAT تلقائي محسوب بنسبة الدولة (الكويت 0% = لا بند). يعيد استخدام مسار البنود الإضافية/GL المُختبَر.
 * المبيعات/المردود: `sales_credit_liability` على حساب `vat_output`؛ المشتريات: `purchase_debit_vat_input` على `vat_input`.
 *
 * @return array<string,mixed>|null
 */
function orange_invoice_ancillary_auto_vat_extra_line(PDO $pdo, string $docKind, int $countryId, float $itemsNet): ?array
{
    $itemsNet = round($itemsNet, 4);
    if ($itemsNet <= 0.0001) {
        return null;
    }
    require_once __DIR__ . '/company_settings.php';
    require_once __DIR__ . '/gl_settings.php';
    $rate = orange_vat_rate_for_country($pdo, $countryId > 0 ? $countryId : null);
    if ($rate <= 0) {
        return null;
    }
    $isPurchase = $docKind === orange_invoice_ancillary_doc_kind_purchase();
    $settingKey = $isPurchase ? 'vat_input' : 'vat_output';
    $lineKind = $isPurchase ? 'purchase_debit_vat_input' : 'sales_credit_liability';
    $accountId = orange_gl_account_id_optional($pdo, $settingKey, $countryId > 0 ? $countryId : null);
    if ($accountId === null || (int) $accountId <= 0) {
        return null;
    }
    $amount = round($itemsNet * $rate / 100.0, 4);
    if ($amount <= 0.0001) {
        return null;
    }
    $rateLabel = rtrim(rtrim(number_format($rate, 3, '.', ''), '0'), '.');

    return [
        'account_id' => (int) $accountId,
        'line_kind' => $lineKind,
        'amount' => $amount,
        'label_ar' => 'ضريبة القيمة المضافة (' . $rateLabel . '%)',
        'show_on_print' => 1,
        'preset_id' => 0,
        'auto_vat' => 1,
    ];
}

/**
 * يدمج بند VAT التلقائي مع البنود المُدخلة (يستبدل أي بند VAT من نفس النوع لتفادي الازدواج).
 *
 * @param list<array<string,mixed>> $extraInput
 * @return list<array<string,mixed>>
 */
function orange_invoice_ancillary_merge_auto_vat(PDO $pdo, string $docKind, int $countryId, float $itemsNet, array $extraInput): array
{
    $vat = orange_invoice_ancillary_auto_vat_extra_line($pdo, $docKind, $countryId, $itemsNet);
    if ($vat === null) {
        return $extraInput;
    }
    $out = [];
    foreach ($extraInput as $line) {
        if (is_array($line) && (string) ($line['line_kind'] ?? '') === (string) $vat['line_kind']) {
            continue;
        }
        $out[] = $line;
    }
    $out[] = $vat;

    return $out;
}

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

/**
 * @return array<string, array{label_ar: string, side: string, contexts: list<string>}>
 */
function orange_invoice_ancillary_purchase_line_kind_catalog(): array
{
    $all = orange_invoice_ancillary_line_kind_catalog();
    $out = [];
    foreach ($all as $key => $meta) {
        if (str_starts_with($key, 'purchase_') && $key !== 'purchase_debit_asset') {
            $out[$key] = $meta;
        }
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function orange_invoice_ancillary_parse_request_lines(array $data, string $docKind): array
{
    $raw = $data['extra_lines'] ?? $data['ancillary_lines'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $amount = round((float) ($row['amount'] ?? 0), 4);
        if ($amount <= 0.0001) {
            continue;
        }
        $accountId = (int) ($row['account_id'] ?? 0);
        $lineKind = trim((string) ($row['line_kind'] ?? ''));
        if ($accountId <= 0 || !orange_invoice_ancillary_line_kind_is_valid($lineKind)) {
            continue;
        }
        $systemKey = orange_invoice_ancillary_system_key_normalize((string) ($row['system_key'] ?? ''));
        $out[] = [
            'account_id' => $accountId,
            'line_kind' => $lineKind,
            'amount' => $amount,
            'label_ar' => trim((string) ($row['label_ar'] ?? '')),
            'show_on_print' => !empty($row['show_on_print']) ? 1 : 0,
            'preset_id' => isset($row['preset_id']) && (int) $row['preset_id'] > 0
                ? (int) $row['preset_id']
                : null,
            'system_key' => $systemKey,
        ];
    }

    return $out;
}

/**
 * @param array<string, mixed>|null $afterPost
 * @return array<string, mixed>|null
 */
function orange_gl_after_post_scale_payable_amount(?array $afterPost, float $oldAmount, float $newAmount): ?array
{
    if ($afterPost === null || abs($oldAmount - $newAmount) < 0.0001) {
        return $afterPost;
    }
    $scaleEntry = static function (array &$entry) use ($oldAmount, $newAmount): void {
        foreach (['debit', 'credit'] as $side) {
            if (!isset($entry[$side])) {
                continue;
            }
            $v = round((float) $entry[$side], 4);
            if ($v > 0.0001 && abs($v - $oldAmount) < 0.0002) {
                $entry[$side] = $newAmount;
            }
        }
    };
    if (isset($afterPost['party_subledger']) && is_array($afterPost['party_subledger'])) {
        $scaleEntry($afterPost['party_subledger']);
    }
    if (isset($afterPost['party_subledger_entries']) && is_array($afterPost['party_subledger_entries'])) {
        foreach ($afterPost['party_subledger_entries'] as &$entry) {
            if (is_array($entry)) {
                $scaleEntry($entry);
            }
        }
        unset($entry);
    }

    return $afterPost;
}

/**
 * @param array<string, mixed> $line
 */
function orange_gl_posting_line_scale_amount(array &$line, float $oldAmount, float $newAmount): void
{
    foreach (['debit', 'credit'] as $side) {
        if (!isset($line[$side])) {
            continue;
        }
        $v = round((float) $line[$side], 4);
        if ($v > 0.0001 && abs($v - $oldAmount) < 0.0002) {
            $line[$side] = $newAmount;
        }
    }
}

/**
 * دمج بنود الفاتورة الإضافية في حزمة ترحيل GL (مشتريات/مبيعات — v1 مشتريات).
 *
 * @param array{
 *   is_multi: bool,
 *   lines: list<array{account_id:int,debit:float,credit:float,memo:string}>,
 *   debit: int,
 *   credit: int,
 *   voucher_description: string,
 *   after_post: array|null,
 *   legacy_ap_subledger?: bool
 * } $glB
 * @param list<array<string, mixed>> $extraLines
 * @return array<string, mixed>
 *
 * @throws RuntimeException
 */
function orange_gl_posting_bundle_apply_invoice_ancillary(
    array $glB,
    array $extraLines,
    float $itemsNetAmount
): array {
    if ($extraLines === []) {
        return $glB;
    }
    $ancRows = orange_invoice_ancillary_extra_lines_journal_rows($extraLines);
    if ($ancRows === []) {
        return $glB;
    }
    $itemsNetAmount = round($itemsNetAmount, 4);
    $totals = orange_invoice_ancillary_extra_lines_totals($extraLines);
    $delta = round($totals['debit'] - $totals['credit'], 4);
    $payable = round($itemsNetAmount + $delta, 4);
    if ($payable < -0.0001) {
        throw new RuntimeException('مجموع البنود الإضافية يجعل صافي الذمة سالباً.');
    }

    if (!$glB['is_multi']) {
        $debitAcct = (int) ($glB['debit'] ?? 0);
        $creditAcct = (int) ($glB['credit'] ?? 0);
        if ($debitAcct <= 0 || $creditAcct <= 0) {
            throw new RuntimeException('تعذر بناء قيد مركّب — حسابات الترحيل الأساسية غير مكتملة.');
        }
        $lines = [
            [
                'account_id' => $debitAcct,
                'debit' => $itemsNetAmount,
                'credit' => 0.0,
                'memo' => 'فاتورة مشتريات — أصناف',
            ],
        ];
        foreach ($ancRows as $row) {
            $lines[] = $row;
        }
        $lines[] = [
            'account_id' => $creditAcct,
            'debit' => 0.0,
            'credit' => $payable,
            'memo' => 'فاتورة مشtريات — ذمة/نقد',
        ];
        $glB['is_multi'] = true;
        $glB['lines'] = $lines;
        $glB['debit'] = 0;
        $glB['credit'] = 0;
    } else {
        foreach ($glB['lines'] as &$line) {
            if (is_array($line)) {
                orange_gl_posting_line_scale_amount($line, $itemsNetAmount, $payable);
            }
        }
        unset($line);
        $glB['lines'] = array_merge($glB['lines'], $ancRows);
    }

    if ($glB['after_post'] !== null) {
        $glB['after_post'] = orange_gl_after_post_scale_payable_amount(
            $glB['after_post'],
            $itemsNetAmount,
            $payable
        );
    }

    return $glB;
}

/**
 * @param list<array<string, mixed>> $extraLines
 */
function orange_invoice_ancillary_purchase_payable_total(float $itemsNetAmount, array $extraLines): float
{
    $itemsNetAmount = round($itemsNetAmount, 4);
    if ($extraLines === []) {
        return $itemsNetAmount;
    }
    $totals = orange_invoice_ancillary_extra_lines_totals($extraLines);
    $delta = round($totals['debit'] - $totals['credit'], 4);

    return round($itemsNetAmount + $delta, 4);
}

/**
 * @return array<string, array{label_ar: string, side: string, contexts: list<string>}>
 */
function orange_invoice_ancillary_sales_line_kind_catalog(): array
{
    $all = orange_invoice_ancillary_line_kind_catalog();
    $out = [];
    foreach ($all as $key => $meta) {
        if (str_starts_with($key, 'sales_')) {
            $out[$key] = $meta;
        }
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $extraLines
 */
function orange_invoice_ancillary_sales_receivable_total(float $itemsNetAmount, array $extraLines): float
{
    $itemsNetAmount = round($itemsNetAmount, 4);
    if ($extraLines === []) {
        return $itemsNetAmount;
    }
    $totals = orange_invoice_ancillary_extra_lines_totals($extraLines);
    $delta = round($totals['debit'] - $totals['credit'], 4);

    return round($itemsNetAmount - $delta, 4);
}

/**
 * @param list<array<string, mixed>> $extraLines
 */
function orange_invoice_ancillary_sales_customer_print_net(array $extraLines): float
{
    $net = 0.0;
    foreach ($extraLines as $row) {
        if (!is_array($row) || (int) ($row['show_on_print'] ?? 0) !== 1) {
            continue;
        }
        $amount = round((float) ($row['amount'] ?? 0), 4);
        if ($amount <= 0.0001) {
            continue;
        }
        $side = orange_invoice_ancillary_line_kind_side((string) ($row['line_kind'] ?? ''));
        if ($side === 'credit') {
            $net += $amount;
        } elseif ($side === 'debit') {
            $net -= $amount;
        }
    }

    return round($net, 4);
}

/**
 * @param array{
 *   is_multi: bool,
 *   lines: list<array{account_id:int,debit:float,credit:float,memo:string}>,
 *   debit: int,
 *   credit: int,
 *   voucher_description: string,
 *   after_post: array|null,
 *   legacy_ar_subledger?: bool
 * } $glB
 * @param list<array<string, mixed>> $extraLines
 * @return array<string, mixed>
 *
 * @throws RuntimeException
 */
function orange_gl_posting_bundle_apply_sales_ancillary(
    array $glB,
    array $extraLines,
    float $itemsNetAmount
): array {
    if ($extraLines === []) {
        return $glB;
    }
    $ancRows = orange_invoice_ancillary_extra_lines_journal_rows($extraLines);
    if ($ancRows === []) {
        return $glB;
    }
    $itemsNetAmount = round($itemsNetAmount, 4);
    $receivable = orange_invoice_ancillary_sales_receivable_total($itemsNetAmount, $extraLines);
    if ($receivable < -0.0001) {
        throw new RuntimeException('مجموع البنود الإضافية يجعل صافي المديونية سالباً.');
    }

    if (!$glB['is_multi']) {
        $debitAcct = (int) ($glB['debit'] ?? 0);
        $creditAcct = (int) ($glB['credit'] ?? 0);
        if ($debitAcct <= 0 || $creditAcct <= 0) {
            throw new RuntimeException('تعذر بناء قيد مركّب — حسابات الترحيل الأساسية غير مكتملة.');
        }
        $lines = [
            [
                'account_id' => $debitAcct,
                'debit' => $receivable,
                'credit' => 0.0,
                'memo' => 'فاتورة مبيعات — ذمة/تحصيل',
            ],
            [
                'account_id' => $creditAcct,
                'debit' => 0.0,
                'credit' => $itemsNetAmount,
                'memo' => 'فاتورة مبيعات — إيراد أصناف',
            ],
        ];
        foreach ($ancRows as $row) {
            $lines[] = $row;
        }
        $glB['is_multi'] = true;
        $glB['lines'] = $lines;
        $glB['debit'] = 0;
        $glB['credit'] = 0;
    } else {
        foreach ($glB['lines'] as &$line) {
            if (is_array($line)) {
                orange_gl_posting_line_scale_amount($line, $itemsNetAmount, $receivable);
            }
        }
        unset($line);
        $glB['lines'] = array_merge($glB['lines'], $ancRows);
    }

    if ($glB['after_post'] !== null) {
        $glB['after_post'] = orange_gl_after_post_scale_payable_amount(
            $glB['after_post'],
            $itemsNetAmount,
            $receivable
        );
    }

    return $glB;
}
