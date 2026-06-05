<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/sales_return_analytics.php';
require_once __DIR__ . '/../../includes/sales_doc_channel.php';
require_once __DIR__ . '/../../includes/company_settings.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$srrCompanyNameAr = orange_company_settings_name_ar($pdo);

$repCountryId = orange_admin_context_country_id($pdo);
$repMoney = orange_admin_currency_context($pdo);
$repDecimals = (int) ($repMoney['decimals'] ?? 3);
$repUnit = (string) ($repMoney['unit'] ?? '');

$fromRaw = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
$toRaw = isset($_GET['to']) ? trim((string) $_GET['to']) : '';
$sourceFilter = isset($_GET['source']) ? trim((string) $_GET['source']) : 'all';
$payFilter = isset($_GET['pay']) ? trim((string) $_GET['pay']) : 'all';
$channelFilter = isset($_GET['channel_id']) ? (int) $_GET['channel_id'] : 0;
$exportCsv = isset($_GET['export']) && (string) $_GET['export'] === 'csv';

if (!in_array($sourceFilter, ['all', 'company', 'online'], true)) {
    $sourceFilter = 'all';
}
if (!in_array($payFilter, ['all', 'cash', 'online', 'credit'], true)) {
    $payFilter = 'all';
}

$fromYmd = $fromRaw !== '' ? orange_parse_admin_date_to_ymd($fromRaw) : '';
$toYmd = $toRaw !== '' ? orange_parse_admin_date_to_ymd($toRaw) : '';
$fromIn = $fromYmd !== '' ? orange_format_date_dmY($fromYmd) : $fromRaw;
$toIn = $toYmd !== '' ? orange_format_date_dmY($toYmd) : $toRaw;

$hasSourceKind = orange_table_has_column($pdo, 'sales_returns', 'source_kind');
$hasInvoiceRef = orange_table_has_column($pdo, 'sales_returns', 'invoice_reference');
$hasAnalytics = $hasSourceKind && $hasInvoiceRef;
$hasCreatedAt = orange_table_has_column($pdo, 'sales_returns', 'created_at');
$hasReturnNumber = orange_table_has_column($pdo, 'sales_returns', 'return_number');
$hasSrCountry = orange_table_has_country_id($pdo, 'sales_returns');
$hasChannels = orange_table_exists($pdo, 'channels');
$hasCustomers = orange_table_exists($pdo, 'customers');
$custNameCol = 'name_ar';
if ($hasCustomers && !orange_table_has_column($pdo, 'customers', 'name_ar')) {
    $custNameCol = orange_table_has_column($pdo, 'customers', 'name') ? 'name' : 'name_ar';
}

/** @var list<array{id:int,name:string}> */
$channelOptions = [];
if ($hasChannels) {
    $chSql = 'SELECT id, name FROM channels WHERE 1=1';
    if ($repCountryId > 0 && orange_channels_has_country_column($pdo)) {
        $chSql .= orange_sql_country_and_fragment($pdo, 'channels', 'channels', $repCountryId);
    }
    $chSql .= ' ORDER BY name ASC';
    $channelOptions = $pdo->query($chSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{0: string, 1: list<mixed>}
 */
function orange_sales_returns_report_where(
    PDO $pdo,
    bool $hasCreatedAt,
    string $fromYmd,
    string $toYmd,
    string $sourceFilter,
    string $payFilter,
    int $channelFilter,
    int $countryId,
    bool $hasSrCountry,
    bool $hasSourceKind
): array {
    $where = ' WHERE 1=1';
    $params = [];

    if ($countryId > 0 && $hasSrCountry) {
        $where .= ' AND sr.country_id = ?';
        $params[] = $countryId;
    }

    if ($hasCreatedAt && $fromYmd !== '') {
        $where .= ' AND DATE(sr.created_at) >= ?';
        $params[] = $fromYmd;
    }
    if ($hasCreatedAt && $toYmd !== '') {
        $where .= ' AND DATE(sr.created_at) <= ?';
        $params[] = $toYmd;
    }
    if ($hasSourceKind && $sourceFilter !== 'all') {
        $where .= ' AND sr.source_kind = ?';
        $params[] = $sourceFilter;
    }
    if ($payFilter !== 'all') {
        $where .= ' AND sr.type = ?';
        $params[] = $payFilter;
    }
    [$chFilterSql, $chFilterParams] = orange_sales_returns_report_channel_filter_sql($pdo, $channelFilter);
    $where .= $chFilterSql;
    foreach ($chFilterParams as $p) {
        $params[] = $p;
    }

    return [$where, $params];
}

[$whereSql, $whereParams] = orange_sales_returns_report_where(
    $pdo,
    $hasCreatedAt,
    $fromYmd,
    $toYmd,
    $sourceFilter,
    $payFilter,
    $channelFilter,
    $repCountryId,
    $hasSrCountry,
    $hasSourceKind
);

$joinCh = $hasChannels ? ' LEFT JOIN channels ch ON ch.id = sr.channel_id' : '';
$joinCust = $hasCustomers ? ' LEFT JOIN customers c ON c.id = sr.customer_id' : '';

$summary = ['cnt' => 0, 'total' => 0.0];
if (orange_table_exists($pdo, 'sales_returns')) {
    $stSum = $pdo->prepare(
        'SELECT COUNT(*) AS cnt, COALESCE(SUM(sr.total), 0) AS total_sum
         FROM sales_returns sr' . $whereSql
    );
    $stSum->execute($whereParams);
    $sumRow = $stSum->fetch(PDO::FETCH_ASSOC);
    if (is_array($sumRow)) {
        $summary['cnt'] = (int) ($sumRow['cnt'] ?? 0);
        $summary['total'] = (float) ($sumRow['total_sum'] ?? 0);
    }
}

/** @var list<array<string, mixed>> */
$bySource = [];
/** @var list<array<string, mixed>> */
$byPayment = [];
/** @var list<array<string, mixed>> */
$byMarketingChannel = [];
/** @var list<array<string, mixed>> */
$byProduct = [];
/** @var list<array<string, mixed>> */
$detailRows = [];

if (orange_table_exists($pdo, 'sales_returns') && orange_table_exists($pdo, 'sales_return_items')) {
    if ($hasAnalytics) {
        $stSrc = $pdo->prepare(
            'SELECT COALESCE(sr.source_kind, \'\') AS sk,
                    COUNT(*) AS cnt,
                    COALESCE(SUM(sr.total), 0) AS total_sum
             FROM sales_returns sr' . $whereSql . '
             GROUP BY COALESCE(sr.source_kind, \'\')
             ORDER BY total_sum DESC'
        );
        $stSrc->execute($whereParams);
        $bySource = $stSrc->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $stPay = $pdo->prepare(
        'SELECT sr.type AS pay_type,
                COUNT(*) AS cnt,
                COALESCE(SUM(sr.total), 0) AS total_sum
         FROM sales_returns sr' . $whereSql . '
         GROUP BY sr.type
         ORDER BY total_sum DESC'
    );
    $stPay->execute($whereParams);
    $byPayment = $stPay->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($hasChannels && orange_table_has_column($pdo, 'sales_returns', 'channel_id')) {
        $mcSkSelect = $hasSourceKind ? 'sr.source_kind' : "'' AS source_kind";
        $mcSkGroup = $hasSourceKind ? 'sr.source_kind, ' : '';
        $stMc = $pdo->prepare(
            'SELECT sr.channel_id, ' . $mcSkSelect . ', ch.name AS channel_name_db,
                    COUNT(*) AS cnt,
                    COALESCE(SUM(sr.total), 0) AS total_sum
             FROM sales_returns sr' . $joinCh . $whereSql . '
             GROUP BY sr.channel_id, ' . $mcSkGroup . 'ch.name
             ORDER BY total_sum DESC'
        );
        $stMc->execute($whereParams);
        $mcRaw = $stMc->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $mcMerged = [];
        foreach ($mcRaw as $row) {
            $label = orange_sales_return_marketing_channel_label(
                isset($row['channel_id']) ? (int) $row['channel_id'] : 0,
                (string) ($row['channel_name_db'] ?? ''),
                (string) ($row['source_kind'] ?? '')
            );
            if (!isset($mcMerged[$label])) {
                $mcMerged[$label] = ['channel_name' => $label, 'cnt' => 0, 'total_sum' => 0.0];
            }
            $mcMerged[$label]['cnt'] += (int) ($row['cnt'] ?? 0);
            $mcMerged[$label]['total_sum'] += (float) ($row['total_sum'] ?? 0);
        }
        $byMarketingChannel = array_values($mcMerged);
        usort($byMarketingChannel, static function (array $a, array $b): int {
            return ($b['total_sum'] <=> $a['total_sum']) ?: ($b['cnt'] <=> $a['cnt']);
        });
    }

    $prodNameExpr = orange_table_exists($pdo, 'products')
        ? (orange_table_has_column($pdo, 'products', 'name_ar')
            ? 'COALESCE(NULLIF(TRIM(p.name_ar), \'\'), CONCAT(\'#\', p.id))'
            : 'CONCAT(\'#\', p.id)')
        : 'CONCAT(\'#\', sri.product_id)';

    $stProd = $pdo->prepare(
        'SELECT ' . $prodNameExpr . ' AS product_label,
                SUM(sri.qty) AS qty_sum,
                COALESCE(SUM((sri.qty * sri.price) - sri.line_discount), 0) AS line_total
         FROM sales_return_items sri
         INNER JOIN sales_returns sr ON sr.id = sri.sales_return_id
         LEFT JOIN products p ON p.id = sri.product_id' . $whereSql . '
         GROUP BY sri.product_id, product_label
         ORDER BY line_total DESC
         LIMIT 25'
    );
    $stProd->execute($whereParams);
    $byProduct = $stProd->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $detailLimit = $exportCsv ? 5000 : 300;
    $detailCols = 'sr.id, sr.total, sr.type, sr.notes';
    if ($hasCreatedAt) {
        $detailCols .= ', sr.created_at';
    }
    if ($hasReturnNumber) {
        $detailCols .= ', sr.return_number';
    }
    if ($hasSourceKind) {
        $detailCols .= ', sr.source_kind';
    }
    if ($hasInvoiceRef) {
        $detailCols .= ', sr.invoice_reference';
    }
    if ($hasCustomers) {
        $detailCols .= ', c.' . $custNameCol . ' AS customer_name';
    }
    if ($hasChannels) {
        $detailCols .= ', sr.channel_id, ch.name AS channel_name_db';
    }

    $stDet = $pdo->prepare(
        'SELECT ' . $detailCols . '
         FROM sales_returns sr' . $joinCh . $joinCust . $whereSql . '
         ORDER BY sr.id DESC
         LIMIT ' . (int) $detailLimit
    );
    $stDet->execute($whereParams);
    $detailRows = $stDet->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($detailRows as &$detRow) {
        $detRow['channel_name'] = orange_sales_return_marketing_channel_label(
            isset($detRow['channel_id']) ? (int) $detRow['channel_id'] : 0,
            (string) ($detRow['channel_name_db'] ?? ''),
            (string) ($detRow['source_kind'] ?? '')
        );
    }
    unset($detRow);
}

if ($exportCsv) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="sales_returns_report.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fputcsv($out, [
            'مردود',
            'تاريخ',
            'مرجع فاتورة',
            'مصدر',
            'تحصيل',
            'قناة تسويق',
            'عميل',
            'المبلغ',
            'ملاحظات',
        ]);
        foreach ($detailRows as $dr) {
            $retRef = $hasReturnNumber && trim((string) ($dr['return_number'] ?? '')) !== ''
                ? trim((string) $dr['return_number'])
                : ('SR-' . (int) ($dr['id'] ?? 0));
            $created = $hasCreatedAt ? orange_format_date_dmY((string) ($dr['created_at'] ?? '')) : '';
            fputcsv($out, [
                $retRef,
                $created,
                (string) ($dr['invoice_reference'] ?? ''),
                orange_sales_return_source_kind_label((string) ($dr['source_kind'] ?? '')),
                orange_sales_return_payment_type_label((string) ($dr['type'] ?? '')),
                (string) ($dr['channel_name'] ?? ''),
                (string) ($dr['customer_name'] ?? ''),
                (string) ($dr['total'] ?? '0'),
                (string) ($dr['notes'] ?? ''),
            ]);
        }
        fclose($out);
    }
    exit;
}

$baseUrl = storefront_public_path('/admin/index.php') . '?page=sales_returns_report';
?>
<div class="page-title">
    <h1>تقرير مردودات المبيعات</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;">
        تحليل مفصّل للمردودات حسب المصدر (شركة / أونلاين)، قناة التحصيل، قناة التسويق، والمنتج.
        <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=sales_returns'), ENT_QUOTES, 'UTF-8'); ?>">مردود المبيعات</a>
        ·
        <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=reports'), ENT_QUOTES, 'UTF-8'); ?>">تقارير المبيعات</a>
    </p>
</div>

<?php if (!$hasAnalytics): ?>
<div class="card">
    <p class="muted" style="margin:0;">جاري تجهيز أعمدة التحليل — حدّث الصفحة بعد لحظات (ترحيل v78).</p>
</div>
<?php endif; ?>

<form method="get" class="card" style="margin-bottom:1rem;">
    <input type="hidden" name="page" value="sales_returns_report">
    <div class="form-grid orange-doc-header-row" style="grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr)); gap:10px; align-items:end;">
        <div>
            <label for="srr_from">من تاريخ</label>
            <input type="text" id="srr_from" name="from" value="<?php echo htmlspecialchars($fromIn, ENT_QUOTES, 'UTF-8'); ?>" placeholder="dd/mm/yyyy" dir="ltr">
        </div>
        <div>
            <label for="srr_to">إلى تاريخ</label>
            <input type="text" id="srr_to" name="to" value="<?php echo htmlspecialchars($toIn, ENT_QUOTES, 'UTF-8'); ?>" placeholder="dd/mm/yyyy" dir="ltr">
        </div>
        <?php if ($hasSourceKind): ?>
        <div>
            <label for="srr_source">مصدر الفاتورة</label>
            <select id="srr_source" name="source">
                <option value="all"<?php echo $sourceFilter === 'all' ? ' selected' : ''; ?>>الكل</option>
                <option value="company"<?php echo $sourceFilter === 'company' ? ' selected' : ''; ?>>شركة (INV-C)</option>
                <option value="online"<?php echo $sourceFilter === 'online' ? ' selected' : ''; ?>>أونلاين (INV-O)</option>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label for="srr_pay">قناة التحصيل</label>
            <select id="srr_pay" name="pay">
                <option value="all"<?php echo $payFilter === 'all' ? ' selected' : ''; ?>>الكل</option>
                <option value="cash"<?php echo $payFilter === 'cash' ? ' selected' : ''; ?>>نقدي</option>
                <option value="online"<?php echo $payFilter === 'online' ? ' selected' : ''; ?>>أونلاين</option>
                <option value="credit"<?php echo $payFilter === 'credit' ? ' selected' : ''; ?>>آجل</option>
            </select>
        </div>
        <?php if ($channelOptions !== [] || $hasAnalytics): ?>
        <div>
            <label for="srr_channel">قناة التسويق</label>
            <select id="srr_channel" name="channel_id">
                <option value="0"<?php echo $channelFilter === 0 ? ' selected' : ''; ?>>الكل</option>
                <option value="-1"<?php echo $channelFilter === -1 ? ' selected' : ''; ?>><?php echo htmlspecialchars(orange_sales_company_direct_channel_label(), ENT_QUOTES, 'UTF-8'); ?> — مبيعات شركة مباشرة</option>
                <?php foreach ($channelOptions as $ch): ?>
                <option value="<?php echo (int) ($ch['id'] ?? 0); ?>"<?php echo $channelFilter === (int) ($ch['id'] ?? 0) ? ' selected' : ''; ?>>
                    <?php echo htmlspecialchars((string) ($ch['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <button type="submit">عرض التقرير</button>
        </div>
    </div>
</form>

<div class="grid-4" style="margin-bottom:1rem;">
    <div class="card stat-card"><h3>عدد المردودات</h3><div class="value"><?php echo (int) $summary['cnt']; ?></div></div>
    <div class="card stat-card"><h3>إجمالي قيمة المردود</h3><div class="value"><?php echo number_format($summary['total'], $repDecimals); ?> <?php echo htmlspecialchars($repUnit, ENT_QUOTES, 'UTF-8'); ?></div></div>
</div>

<?php
$csvQ = $_GET;
$csvQ['page'] = 'sales_returns_report';
$csvQ['export'] = 'csv';
$csvHref = $baseUrl . '&' . http_build_query($csvQ);
?>
<p style="margin:0 0 1rem;">
    <a class="btn btn-secondary" href="<?php echo htmlspecialchars($csvHref, ENT_QUOTES, 'UTF-8'); ?>">تصدير CSV (تفاصيل)</a>
</p>

<div class="grid-2" style="gap:1rem; margin-bottom:1rem;">
<?php if ($bySource !== []): ?>
<div class="card">
    <h3>حسب مصدر الفاتورة</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>المصدر</th><th>العدد</th><th>القيمة</th></tr></thead>
            <tbody>
            <?php foreach ($bySource as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars(orange_sales_return_source_kind_label((string) ($row['sk'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($row['cnt'] ?? 0); ?></td>
                    <td><?php echo number_format((float) ($row['total_sum'] ?? 0), $repDecimals); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h3>حسب قناة التحصيل</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>التحصيل</th><th>العدد</th><th>القيمة</th></tr></thead>
            <tbody>
            <?php foreach ($byPayment as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars(orange_sales_return_payment_type_label((string) ($row['pay_type'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($row['cnt'] ?? 0); ?></td>
                    <td><?php echo number_format((float) ($row['total_sum'] ?? 0), $repDecimals); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<?php if ($byMarketingChannel !== []): ?>
<div class="card" style="margin-bottom:1rem;">
    <h3>حسب قناة التسويق (واتساب / …)</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>القناة</th><th>العدد</th><th>قيمة المردود</th></tr></thead>
            <tbody>
            <?php foreach ($byMarketingChannel as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) ($row['channel_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($row['cnt'] ?? 0); ?></td>
                    <td><?php echo number_format((float) ($row['total_sum'] ?? 0), $repDecimals); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($byProduct !== []): ?>
<div class="card" style="margin-bottom:1rem;">
    <h3>أكثر المنتجات مرتجعة (أعلى 25)</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>المنتج</th><th>الكمية</th><th>قيمة الأسطر</th></tr></thead>
            <tbody>
            <?php foreach ($byProduct as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) ($row['product_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($row['qty_sum'] ?? 0); ?></td>
                    <td><?php echo number_format((float) ($row['line_total'] ?? 0), $repDecimals); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h3>تفاصيل المردودات<?php echo $detailRows !== [] ? ' (' . count($detailRows) . ')' : ''; ?></h3>
    <?php if ($detailRows === []): ?>
        <p class="muted" style="margin:0;">لا توجد مردودات في المدى المحدد.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table data-export-name="تفاصيل مردودات المبيعات" data-export-company="<?php echo htmlspecialchars($srrCompanyNameAr, ENT_QUOTES, 'UTF-8'); ?>">
            <thead>
                <tr>
                    <th>مردود</th>
                    <th>تاريخ</th>
                    <th>مرجع فاتورة</th>
                    <th>مصدر</th>
                    <th>تحصيل</th>
                    <th>قناة</th>
                    <th>عميل</th>
                    <th>المبلغ</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($detailRows as $dr): ?>
                <?php
                $retRef = $hasReturnNumber && trim((string) ($dr['return_number'] ?? '')) !== ''
                    ? trim((string) $dr['return_number'])
                    : ('SR-' . (int) ($dr['id'] ?? 0));
                $srLink = storefront_public_path('/admin/index.php?page=sales_returns&sales_return_id=' . (int) ($dr['id'] ?? 0));
                ?>
                <tr>
                    <td><a href="<?php echo htmlspecialchars($srLink, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($retRef, ENT_QUOTES, 'UTF-8'); ?></a></td>
                    <td><?php echo $hasCreatedAt ? htmlspecialchars(orange_format_date_dmY((string) ($dr['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) ($dr['invoice_reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(orange_sales_return_source_kind_label((string) ($dr['source_kind'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(orange_sales_return_payment_type_label((string) ($dr['type'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($dr['channel_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($dr['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo number_format((float) ($dr['total'] ?? 0), $repDecimals); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
