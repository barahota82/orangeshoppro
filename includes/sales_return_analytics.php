<?php

declare(strict_types=1);

require_once __DIR__ . '/document_sequences.php';
require_once __DIR__ . '/sales_doc_channel.php';

/**
 * مصدر الفاتورة المرجعية: company | online | ''.
 */
function orange_sales_return_source_kind_from_order(array $order): string
{
    $inv = trim((string) ($order['invoice_number'] ?? ''));
    if (str_starts_with($inv, 'INV-O-')) {
        return 'online';
    }
    if (str_starts_with($inv, 'INV-C-')) {
        return 'company';
    }

    return orange_order_invoice_number_kind($order);
}

/**
 * مرجع الفاتورة/الطلب المحفوظ للتقارير (لقطة عند الحفظ).
 */
function orange_sales_return_invoice_reference_from_order(array $order, int $orderId): string
{
    $inv = trim((string) ($order['invoice_number'] ?? ''));
    if ($inv !== '') {
        return $inv;
    }
    $orderNum = trim((string) ($order['order_number'] ?? ''));
    if ($orderNum !== '') {
        return $orderNum;
    }
    $kind = orange_sales_return_source_kind_from_order($order);
    $tag = $kind === 'company' ? 'INV-C' : 'INV-O';

    return $tag . '-' . $orderId;
}

/**
 * @return array{source_kind: string, invoice_reference: string, channel_id: ?int, country_id: ?int}
 */
function orange_sales_return_analytics_from_order_row(array $order, int $orderId): array
{
    $countryId = null;
    if (isset($order['country_id']) && (int) $order['country_id'] > 0) {
        $countryId = (int) $order['country_id'];
    }
    $channelId = null;
    if (isset($order['channel_id']) && (int) $order['channel_id'] > 0) {
        $channelId = (int) $order['channel_id'];
    }

    return [
        'source_kind' => orange_sales_return_source_kind_from_order($order),
        'invoice_reference' => orange_sales_return_invoice_reference_from_order($order, $orderId),
        'channel_id' => $channelId,
        'country_id' => $countryId,
    ];
}

/**
 * @return array{source_kind: string, invoice_reference: string, channel_id: ?int, country_id: ?int}|null
 */
function orange_sales_return_load_order_analytics(PDO $pdo, int $orderId): ?array
{
    if ($orderId <= 0 || !orange_table_exists($pdo, 'orders')) {
        return null;
    }

    $cols = 'id, order_source, channel_id';
    if (orange_table_has_column($pdo, 'orders', 'invoice_number')) {
        $cols .= ', invoice_number';
    }
    if (orange_table_has_column($pdo, 'orders', 'order_number')) {
        $cols .= ', order_number';
    }
    if (orange_table_has_country_id($pdo, 'orders')) {
        $cols .= ', country_id';
    }

    $st = $pdo->prepare("SELECT {$cols} FROM orders WHERE id = ? LIMIT 1");
    $st->execute([$orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($order)) {
        return null;
    }

    return orange_sales_return_analytics_from_order_row($order, $orderId);
}

/**
 * @param array{source_kind?: string, invoice_reference?: string, channel_id?: ?int, country_id?: ?int} $dims
 */
function orange_sales_return_persist_analytics(PDO $pdo, int $returnId, array $dims, int $fallbackCountryId = 0): void
{
    if ($returnId <= 0 || !orange_table_exists($pdo, 'sales_returns')) {
        return;
    }

    $sets = [];
    $params = [];

    if (orange_table_has_column($pdo, 'sales_returns', 'source_kind')) {
        $sk = trim((string) ($dims['source_kind'] ?? ''));
        if (!in_array($sk, ['company', 'online'], true)) {
            $sk = '';
        }
        $sets[] = 'source_kind = ?';
        $params[] = $sk !== '' ? $sk : null;
    }
    if (orange_table_has_column($pdo, 'sales_returns', 'invoice_reference')) {
        $ref = trim((string) ($dims['invoice_reference'] ?? ''));
        $sets[] = 'invoice_reference = ?';
        $params[] = $ref !== '' ? $ref : null;
    }
    if (orange_table_has_column($pdo, 'sales_returns', 'channel_id')) {
        $cid = (int) ($dims['channel_id'] ?? 0);
        $sets[] = 'channel_id = ?';
        $params[] = $cid > 0 ? $cid : null;
    }
    if (orange_table_has_column($pdo, 'sales_returns', 'country_id')) {
        $ctry = (int) ($dims['country_id'] ?? 0);
        if ($ctry <= 0 && $fallbackCountryId > 0) {
            $ctry = $fallbackCountryId;
        }
        $sets[] = 'country_id = ?';
        $params[] = $ctry > 0 ? $ctry : null;
    }

    if ($sets === []) {
        return;
    }

    $params[] = $returnId;
    $pdo->prepare('UPDATE sales_returns SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
}

/**
 * مزامنة أبعاد التحليل من الطلب المرجعي (إنشاء/تعديل).
 */
function orange_sales_return_sync_analytics_for_return(
    PDO $pdo,
    int $returnId,
    int $orderId,
    int $fallbackCountryId = 0
): void {
    $dims = [
        'source_kind' => '',
        'invoice_reference' => '',
        'channel_id' => null,
        'country_id' => $fallbackCountryId > 0 ? $fallbackCountryId : null,
    ];
    if ($orderId > 0) {
        $fromOrder = orange_sales_return_load_order_analytics($pdo, $orderId);
        if ($fromOrder !== null) {
            $dims = $fromOrder;
            if (($dims['country_id'] ?? 0) <= 0 && $fallbackCountryId > 0) {
                $dims['country_id'] = $fallbackCountryId;
            }
        }
    }
    orange_sales_return_persist_analytics($pdo, $returnId, $dims, $fallbackCountryId);
}

/**
 * تسمية عربية لمصدر الفاتورة.
 */
function orange_sales_return_source_kind_label(string $kind): string
{
    return match (trim($kind)) {
        'company' => 'فاتورة شركة',
        'online' => 'فاتورة أونلاين',
        default => '—',
    };
}

/**
 * تسمية عربية لقناة التحصيل (type).
 */
function orange_sales_return_payment_type_label(string $type): string
{
    return match (trim($type)) {
        'online' => 'أونلاين',
        'credit' => 'آجل',
        'cash' => 'نقدي',
        default => 'نقدي',
    };
}

/**
 * تسمية قناة التسويق في التقارير (تفريق مبيعات شركة مباشرة «الشركة» عن بلا قناة).
 */
function orange_sales_return_marketing_channel_label(
    ?int $channelId,
    ?string $channelNameFromDb = null,
    ?string $sourceKind = null
): string {
    if ($channelId !== null && (int) $channelId > 0) {
        return orange_sales_order_channel_label($channelId, $channelNameFromDb);
    }
    if (trim((string) ($sourceKind ?? '')) === 'company') {
        return orange_sales_company_direct_channel_label();
    }

    return '— بلا قناة —';
}

/**
 * فلتر تقرير المردود: 0 = الكل، -1 = شركة مباشرة (INV-C بلا channel_id)، &gt;0 = قناة.
 *
 * @return array{0: string, 1: list<mixed>}
 */
function orange_sales_returns_report_channel_filter_sql(int $channelFilter): array
{
    if ($channelFilter > 0) {
        return [' AND sr.channel_id = ?', [$channelFilter]];
    }
    if ($channelFilter === -1) {
        return [
            " AND (sr.channel_id IS NULL OR sr.channel_id = 0) AND sr.source_kind = 'company'",
            [],
        ];
    }

    return ['', []];
}

/**
 * @return array{0: string, 1: list<mixed>}
 */
function orange_sales_returns_date_country_where(
    PDO $pdo,
    string $alias,
    bool $hasCreatedAt,
    string $fromYmd,
    string $toYmd,
    int $countryId
): array {
    $where = '';
    $params = [];
    if ($countryId > 0 && orange_table_has_country_id($pdo, 'sales_returns')) {
        $where .= orange_sql_country_and_fragment($pdo, 'sales_returns', $alias, $countryId);
    }
    if ($hasCreatedAt && $fromYmd !== '') {
        $where .= ' AND DATE(' . $alias . '.created_at) >= ?';
        $params[] = $fromYmd;
    }
    if ($hasCreatedAt && $toYmd !== '') {
        $where .= ' AND DATE(' . $alias . '.created_at) <= ?';
        $params[] = $toYmd;
    }

    return [$where, $params];
}

/**
 * فلتر دولة: من sales_returns أو من الطلب المرجعي إن country_id على المردود فارغاً.
 */
function orange_sales_returns_country_or_order_sql(PDO $pdo, int $countryId): string
{
    if ($countryId <= 0) {
        return '';
    }
    // مطابق لتقرير مردودات المبيعات: بدون عمود country_id على sales_returns لا نُصفّي المردودات بالدولة.
    if (!orange_table_has_country_id($pdo, 'sales_returns')) {
        return '';
    }
    $parts = [];
    $srFrag = orange_sql_country_and_fragment($pdo, 'sales_returns', 'sr', $countryId);
    if ($srFrag !== '') {
        $parts[] = '(' . trim(substr($srFrag, 4)) . ')';
    }
    if (orange_table_exists($pdo, 'orders') && orange_table_has_country_id($pdo, 'orders')) {
        $oFrag = orange_sql_country_and_fragment($pdo, 'orders', 'o', $countryId);
        if ($oFrag !== '') {
            $oInner = trim(substr($oFrag, 4));
            $parts[] = '((sr.country_id IS NULL OR sr.country_id = 0) AND (' . $oInner . '))';
        }
    }
    if ($parts === []) {
        return '';
    }

    return ' AND (' . implode(' OR ', $parts) . ')';
}

/**
 * فلتر تاريخ المردودات لتحليل القنوات: مرتبط بطلب → تاريخ الطلب؛ وإلا تاريخ المردود.
 *
 * @return array{0: string, 1: list<mixed>}
 */
function orange_channel_analytics_returns_date_sql(
    bool $hasCreatedAt,
    bool $hasOrderJoin,
    string $fromYmd,
    string $toYmd
): array {
    $sql = '';
    $params = [];
    if (!$hasCreatedAt) {
        return [$sql, $params];
    }
    if ($fromYmd !== '') {
        if ($hasOrderJoin) {
            $sql .= ' AND ((sr.order_id IS NOT NULL AND sr.order_id > 0 AND o.id IS NOT NULL AND o.created_at >= ?)'
                . ' OR ((sr.order_id IS NULL OR sr.order_id = 0 OR o.id IS NULL) AND DATE(sr.created_at) >= ?))';
            $params[] = $fromYmd . ' 00:00:00';
            $params[] = $fromYmd;
        } else {
            $sql .= ' AND DATE(sr.created_at) >= ?';
            $params[] = $fromYmd;
        }
    }
    if ($toYmd !== '') {
        if ($hasOrderJoin) {
            $sql .= ' AND ((sr.order_id IS NOT NULL AND sr.order_id > 0 AND o.id IS NOT NULL AND o.created_at <= ?)'
                . ' OR ((sr.order_id IS NULL OR sr.order_id = 0 OR o.id IS NULL) AND DATE(sr.created_at) <= ?))';
            $params[] = $toYmd . ' 23:59:59';
            $params[] = $toYmd;
        } else {
            $sql .= ' AND DATE(sr.created_at) <= ?';
            $params[] = $toYmd;
        }
    }

    return [$sql, $params];
}

/**
 * قناة التسويق للمردود: >0 معرّف قناة، 0 = شركة مباشرة، -1 = لم يُعرف.
 *
 * @param array<string, mixed> $row
 */
function orange_sales_return_resolve_marketing_channel_id(PDO $pdo, array $row, int $countryId): int
{
    if (isset($row['channel_id']) && (int) $row['channel_id'] > 0) {
        return (int) $row['channel_id'];
    }
    if (isset($row['order_channel_id']) && (int) $row['order_channel_id'] > 0) {
        return (int) $row['order_channel_id'];
    }
    if (orange_sales_return_is_company_marketing_bucket($row)) {
        return 0;
    }
    $sk = trim((string) ($row['source_kind'] ?? ''));
    if ($sk === 'company') {
        return 0;
    }
    if ($sk === 'online') {
        $onlineId = orange_channel_id_for_slug($pdo, 'online', $countryId);

        return $onlineId > 0 ? $onlineId : -1;
    }
    $invRef = strtoupper(trim((string) ($row['invoice_reference'] ?? '')));
    if (str_starts_with($invRef, 'INV-O-')) {
        $onlineId = orange_channel_id_for_slug($pdo, 'online', $countryId);

        return $onlineId > 0 ? $onlineId : -1;
    }
    if (str_starts_with($invRef, 'INV-C-')) {
        return 0;
    }
    $orderInv = strtoupper(trim((string) ($row['order_invoice_number'] ?? '')));
    if (str_starts_with($orderInv, 'INV-O-')) {
        $onlineId = orange_channel_id_for_slug($pdo, 'online', $countryId);

        return $onlineId > 0 ? $onlineId : -1;
    }
    if (str_starts_with($orderInv, 'INV-C-')) {
        return 0;
    }

    return -1;
}

/**
 * هل المردود يُنسب لقناة «الشركة» (مبيعات مباشرة بلا قناة تسويق)؟
 *
 * @param array<string, mixed> $row
 */
function orange_sales_return_is_company_marketing_bucket(array $row): bool
{
    $srCh = isset($row['channel_id']) ? (int) $row['channel_id'] : 0;
    if ($srCh > 0) {
        return false;
    }
    $sk = trim((string) ($row['source_kind'] ?? ''));
    if ($sk === 'company') {
        return true;
    }
    $orderCh = isset($row['order_channel_id']) ? (int) $row['order_channel_id'] : 0;
    if ($orderCh > 0) {
        return false;
    }
    $orderSource = trim((string) ($row['order_source'] ?? ''));
    if ($orderSource === 'company') {
        return true;
    }
    $invRef = trim((string) ($row['invoice_reference'] ?? ''));
    if ($invRef !== '' && str_starts_with(strtoupper($invRef), 'INV-C-')) {
        return true;
    }
    $orderInv = trim((string) ($row['order_invoice_number'] ?? ''));

    return $orderInv !== '' && str_starts_with(strtoupper($orderInv), 'INV-C-');
}

/**
 * تجميع مردودات لتحليل القنوات — قناة من sr.channel_id أو من الطلب إن وُجد.
 *
 * @return array{
 *   by_channel: array<int, array{cnt: int, total: float}>,
 *   company: array{cnt: int, total: float},
 *   orphan: array{cnt: int, total: float}
 * }
 */
function orange_channel_analytics_aggregate_returns(
    PDO $pdo,
    bool $hasCreatedAt,
    string $fromYmd,
    string $toYmd,
    int $countryId
): array {
    $out = [
        'by_channel' => [],
        'company' => ['cnt' => 0, 'total' => 0.0],
        'orphan' => ['cnt' => 0, 'total' => 0.0],
    ];
    if (!orange_table_exists($pdo, 'sales_returns')) {
        return $out;
    }

    $hasOrderJoin = orange_table_exists($pdo, 'orders');
    $joinOrder = $hasOrderJoin ? ' LEFT JOIN orders o ON o.id = sr.order_id' : '';
    $select = 'sr.total';
    if (orange_table_has_column($pdo, 'sales_returns', 'channel_id')) {
        $select .= ', sr.channel_id';
    }
    if (orange_table_has_column($pdo, 'sales_returns', 'source_kind')) {
        $select .= ', sr.source_kind';
    }
    if (orange_table_has_column($pdo, 'sales_returns', 'invoice_reference')) {
        $select .= ', sr.invoice_reference';
    }
    if ($joinOrder !== '') {
        if (orange_table_has_column($pdo, 'orders', 'channel_id')) {
            $select .= ', o.channel_id AS order_channel_id';
        }
        if (orange_table_has_column($pdo, 'orders', 'order_source')) {
            $select .= ', o.order_source';
        }
        if (orange_table_has_column($pdo, 'orders', 'invoice_number')) {
            $select .= ', o.invoice_number AS order_invoice_number';
        }
    }

    $sql = 'SELECT ' . $select . ' FROM sales_returns sr' . $joinOrder . ' WHERE 1=1';
    $sql .= orange_sales_returns_country_or_order_sql($pdo, $countryId);
    [$dateSql, $dateParams] = orange_channel_analytics_returns_date_sql(
        $hasCreatedAt,
        $hasOrderJoin,
        $fromYmd,
        $toYmd
    );
    $sql .= $dateSql;
    $params = $dateParams;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $total = (float) ($row['total'] ?? 0);
        if ($total <= 0.0001) {
            continue;
        }
        $mktCh = orange_sales_return_resolve_marketing_channel_id($pdo, $row, $countryId);
        if ($mktCh > 0) {
            if (!isset($out['by_channel'][$mktCh])) {
                $out['by_channel'][$mktCh] = ['cnt' => 0, 'total' => 0.0];
            }
            $out['by_channel'][$mktCh]['cnt']++;
            $out['by_channel'][$mktCh]['total'] += $total;

            continue;
        }
        if ($mktCh === 0) {
            $out['company']['cnt']++;
            $out['company']['total'] += $total;

            continue;
        }
        $out['orphan']['cnt']++;
        $out['orphan']['total'] += $total;
    }

    return $out;
}
