<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/sales_doc_channel.php';
require_once __DIR__ . '/../../includes/sales_return_analytics.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$caCountryId = orange_admin_context_country_id($pdo);
$caMoney = orange_admin_currency_context($pdo);
$caOrdersCountrySql = orange_sql_country_and_fragment($pdo, 'orders', 'o', $caCountryId);
$caChannelsCountrySql = orange_channels_has_country_column($pdo)
    ? orange_sql_country_and_fragment($pdo, 'channels', 'c', $caCountryId)
    : '';

$fromRaw = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
$toRaw = isset($_GET['to']) ? trim((string) $_GET['to']) : '';
$fromYmd = $fromRaw !== '' ? orange_parse_admin_date_to_ymd($fromRaw) : '';
$toYmd = $toRaw !== '' ? orange_parse_admin_date_to_ymd($toRaw) : '';
$fromOk = $fromYmd !== '';
$toOk = $toYmd !== '';
$fromIn = $fromOk ? orange_format_date_dmY($fromYmd) : $fromRaw;
$toIn = $toOk ? orange_format_date_dmY($toYmd) : $toRaw;

/**
 * @return array{0: string, 1: list<mixed>}
 */
function orange_channel_analytics_join_orders_on(bool $fromOk, string $fromYmd, bool $toOk, string $toYmd, string $ordersCountrySql = ''): array
{
    $on = 'o.channel_id = c.id';
    $params = [];
    if ($fromOk) {
        $on .= ' AND o.created_at >= ?';
        $params[] = $fromYmd . ' 00:00:00';
    }
    if ($toOk) {
        $on .= ' AND o.created_at <= ?';
        $params[] = $toYmd . ' 23:59:59';
    }
    $on .= $ordersCountrySql;

    return [$on, $params];
}

[$joinOn, $joinParams] = orange_channel_analytics_join_orders_on($fromOk, $fromYmd, $toOk, $toYmd, $caOrdersCountrySql);

$sqlChannels = "
    SELECT
        c.id,
        c.name,
        c.slug,
        c.is_active,
        COUNT(o.id) AS cnt_all,
        SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END) AS cnt_completed,
        COALESCE(SUM(CASE WHEN o.status = 'completed' THEN o.total END), 0) AS revenue_completed
    FROM channels c
    LEFT JOIN orders o ON {$joinOn}
    WHERE 1=1{$caChannelsCountrySql}
    GROUP BY c.id, c.name, c.slug, c.is_active
    ORDER BY revenue_completed DESC, cnt_completed DESC, cnt_all DESC
";
$stCh = $pdo->prepare($sqlChannels);
$stCh->execute($joinParams);
$channelRows = $stCh->fetchAll(PDO::FETCH_ASSOC);

$rank = 0;
foreach ($channelRows as &$cr) {
    ++$rank;
    $cr['_rank'] = $rank;
    $done = (int) ($cr['cnt_completed'] ?? 0);
    $rev = (float) ($cr['revenue_completed'] ?? 0);
    $cr['_avg_basket'] = $done > 0 ? $rev / $done : 0.0;
}
unset($cr);

$orphanSql = '
    SELECT
        COUNT(*) AS cnt_all,
        SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) AS cnt_completed,
        COALESCE(SUM(CASE WHEN status = \'completed\' THEN total END), 0) AS revenue_completed
    FROM orders
    WHERE (channel_id IS NULL OR channel_id = 0)' . orange_sql_country_and_fragment($pdo, 'orders', 'orders', $caCountryId) . '
';
$orphanParams = [];
if ($fromOk) {
    $orphanSql .= ' AND created_at >= ?';
    $orphanParams[] = $fromYmd . ' 00:00:00';
}
if ($toOk) {
    $orphanSql .= ' AND created_at <= ?';
    $orphanParams[] = $toYmd . ' 23:59:59';
}
$stOr = $pdo->prepare($orphanSql);
$stOr->execute($orphanParams);
$orphan = $stOr->fetch(PDO::FETCH_ASSOC) ?: ['cnt_all' => 0, 'cnt_completed' => 0, 'revenue_completed' => 0];

$companyDirectSql = '
    SELECT
        COUNT(*) AS cnt_all,
        SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) AS cnt_completed,
        COALESCE(SUM(CASE WHEN status = \'completed\' THEN total END), 0) AS revenue_completed
    FROM orders o
    WHERE 1=1' . orange_sales_company_direct_orders_sql($pdo, 'o') . $caOrdersCountrySql;
$companyDirectParams = [];
if ($fromOk) {
    $companyDirectSql .= ' AND o.created_at >= ?';
    $companyDirectParams[] = $fromYmd . ' 00:00:00';
}
if ($toOk) {
    $companyDirectSql .= ' AND o.created_at <= ?';
    $companyDirectParams[] = $toYmd . ' 23:59:59';
}
$stCo = $pdo->prepare($companyDirectSql);
$stCo->execute($companyDirectParams);
$companyDirect = $stCo->fetch(PDO::FETCH_ASSOC) ?: ['cnt_all' => 0, 'cnt_completed' => 0, 'revenue_completed' => 0];
$companyInvoiceUrl = storefront_public_path('/admin/index.php?page=company_sales_invoice');
$returnsReportUrl = storefront_public_path('/admin/index.php?page=sales_returns_report');

/** @var array<int, array{cnt: int, total: float}> */
$returnsByChannelId = [];
$companyReturns = ['cnt' => 0, 'total' => 0.0];
$hasSrTable = orange_table_exists($pdo, 'sales_returns');
$hasSrChannel = $hasSrTable && orange_table_has_column($pdo, 'sales_returns', 'channel_id');
$hasSrCreated = $hasSrTable && orange_table_has_column($pdo, 'sales_returns', 'created_at');
$hasSrSource = $hasSrTable && orange_table_has_column($pdo, 'sales_returns', 'source_kind');

if ($hasSrChannel) {
    [$srBaseSql, $srBaseParams] = orange_sales_returns_date_country_where(
        $pdo,
        'sr',
        $hasSrCreated,
        $fromYmd,
        $toYmd,
        $caCountryId
    );
    $stRetCh = $pdo->prepare(
        'SELECT sr.channel_id AS cid, COUNT(*) AS cnt, COALESCE(SUM(sr.total), 0) AS total_sum
         FROM sales_returns sr
         WHERE sr.channel_id IS NOT NULL AND sr.channel_id > 0' . $srBaseSql . '
         GROUP BY sr.channel_id'
    );
    $stRetCh->execute($srBaseParams);
    foreach ($stRetCh->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rr) {
        $cid = (int) ($rr['cid'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $returnsByChannelId[$cid] = [
            'cnt' => (int) ($rr['cnt'] ?? 0),
            'total' => (float) ($rr['total_sum'] ?? 0),
        ];
    }

    if ($hasSrSource) {
        $stRetCo = $pdo->prepare(
            'SELECT COUNT(*) AS cnt, COALESCE(SUM(sr.total), 0) AS total_sum
             FROM sales_returns sr
             WHERE (sr.channel_id IS NULL OR sr.channel_id = 0)
               AND sr.source_kind = \'company\'' . $srBaseSql
        );
        $stRetCo->execute($srBaseParams);
        $coRetRow = $stRetCo->fetch(PDO::FETCH_ASSOC);
        if (is_array($coRetRow)) {
            $companyReturns = [
                'cnt' => (int) ($coRetRow['cnt'] ?? 0),
                'total' => (float) ($coRetRow['total_sum'] ?? 0),
            ];
        }
    }
}

/** @var list<array<string, mixed>> */
$unifiedChannelRows = [];
foreach ($channelRows as $cr) {
    $cid = (int) ($cr['id'] ?? 0);
    $salesRev = (float) ($cr['revenue_completed'] ?? 0);
    $ret = $returnsByChannelId[$cid] ?? ['cnt' => 0, 'total' => 0.0];
    $retTotal = (float) $ret['total'];
    $unifiedChannelRows[] = [
        'id' => $cid,
        'name' => (string) ($cr['name'] ?? ''),
        'slug' => (string) ($cr['slug'] ?? ''),
        'is_active' => (int) ($cr['is_active'] ?? 0),
        'sales_cnt_all' => (int) ($cr['cnt_all'] ?? 0),
        'sales_cnt_done' => (int) ($cr['cnt_completed'] ?? 0),
        'sales_rev' => $salesRev,
        'ret_cnt' => (int) $ret['cnt'],
        'ret_total' => $retTotal,
        'net' => round($salesRev - $retTotal, 4),
        '_top' => $topByChannel[$cid] ?? null,
    ];
}

$coSalesRev = (float) ($companyDirect['revenue_completed'] ?? 0);
$coRetTotal = (float) $companyReturns['total'];
$unifiedChannelRows[] = [
    'id' => 0,
    'name' => orange_sales_company_direct_channel_label(),
    'slug' => 'company',
    'is_active' => 1,
    'sales_cnt_all' => (int) ($companyDirect['cnt_all'] ?? 0),
    'sales_cnt_done' => (int) ($companyDirect['cnt_completed'] ?? 0),
    'sales_rev' => $coSalesRev,
    'ret_cnt' => (int) $companyReturns['cnt'],
    'ret_total' => $coRetTotal,
    'net' => round($coSalesRev - $coRetTotal, 4),
    '_top' => null,
];

usort($unifiedChannelRows, static function (array $a, array $b): int {
    return ($b['net'] <=> $a['net']) ?: ($b['sales_rev'] <=> $a['sales_rev']);
});
$rank = 0;
foreach ($unifiedChannelRows as &$ur) {
    $ur['_rank'] = ++$rank;
    $done = (int) ($ur['sales_cnt_done'] ?? 0);
    $ur['_avg_basket'] = $done > 0 ? ((float) ($ur['sales_rev'] ?? 0)) / $done : 0.0;
}
unset($ur);

$topSql = '
    SELECT o.channel_id, oi.product_name, SUM(oi.qty) AS qty_sum,
           SUM(oi.qty * oi.price) AS revenue_lines
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id AND o.status = \'completed\'
    WHERE o.channel_id IS NOT NULL AND o.channel_id > 0
';
$topParams = [];
if ($fromOk) {
    $topSql .= ' AND o.created_at >= ?';
    $topParams[] = $fromYmd . ' 00:00:00';
}
if ($toOk) {
    $topSql .= ' AND o.created_at <= ?';
    $topParams[] = $toYmd . ' 23:59:59';
}
$topSql .= $caOrdersCountrySql . ' GROUP BY o.channel_id, oi.product_name ORDER BY o.channel_id ASC, qty_sum DESC';

$stTop = $pdo->prepare($topSql);
$stTop->execute($topParams);
$topRaw = $stTop->fetchAll(PDO::FETCH_ASSOC);

/** @var array<int, array{product_name: string, qty_sum: float, revenue_lines: float}> */
$topByChannel = [];
foreach ($topRaw as $tr) {
    $cid = (int) ($tr['channel_id'] ?? 0);
    if ($cid <= 0) {
        continue;
    }
    if (!isset($topByChannel[$cid])) {
        $topByChannel[$cid] = [
            'product_name' => (string) $tr['product_name'],
            'qty_sum' => (float) $tr['qty_sum'],
            'revenue_lines' => (float) $tr['revenue_lines'],
        ];
    }
}

$channelsUrl = storefront_public_path('/admin/index.php?page=channels');
$ordersUrl = storefront_public_path('/admin/index.php?page=orders');
?>
<div class="page-title">
    <h1>تحليل القنوات</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;max-width:900px;line-height:1.55;">
        القنوات عندكم تمثّل <strong>واجهات البيع</strong> (مثل تيك توك / واتساب / …) وتوزيع العملاء — بحسب مذكرة النظام: مسارات منفصلة و<code>channel_id</code> على الطلب، مع <strong>مخزون ومبيعات شركة واحدة</strong>.
        هذا التقرير يقيّس «شغل» كل قناة: <strong>مبيعات مكتملة</strong>، <strong>مردودات</strong> (حسب تاريخ المردود)، <strong>الصافي</strong>، وأكثر منتج حركةً.
        مبيعات شركة مباشرة (قناة «<?php echo htmlspecialchars(orange_sales_company_direct_channel_label(), ENT_QUOTES, 'UTF-8'); ?>») تظهر كصف مستقل.
        توجيه الزائر من دومين الشركة إلى القناة التي فتحها يُنفَّذ في الواجهة العامة؛ هنا نعرض نتائج الطلبات المحفوظة فقط.
    </p>
</div>

<div class="card">
    <h3 class="card-title">فلترة بالتاريخ (حسب تاريخ إنشاء الطلب)</h3>
    <form method="get" action="" class="form-grid" style="align-items:end;max-width:720px;">
        <input type="hidden" name="page" value="channel_analytics">
        <div>
            <label for="ca_from">من</label>
            <input type="text" id="ca_from" name="from" class="admin-inp orange-inp-dmy"
                value="<?php echo htmlspecialchars($fromIn, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off">
        </div>
        <div>
            <label for="ca_to">إلى</label>
            <input type="text" id="ca_to" name="to" class="admin-inp orange-inp-dmy"
                value="<?php echo htmlspecialchars($toIn, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off">
        </div>
        <div class="actions" style="margin:0;">
            <button type="submit">تطبيق</button>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=channel_analytics'), ENT_QUOTES, 'UTF-8'); ?>">كل الفترات</a>
        </div>
    </form>
</div>

<div class="card">
    <h3 class="card-title">ملخص القنوات — بيع ومردود وصافي</h3>
    <p class="card-hint">
        المبيعات حسب تاريخ إنشاء الطلب (<code>completed</code>)؛ المردودات حسب تاريخ المردود.
        الترتيب حسب <strong>الصافي</strong> (إيراد مكتمل − مردودات).
    </p>
    <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>القناة</th>
                    <th>Slug</th>
                    <th>طلبات مكتملة</th>
                    <th>إيراد مكتمل</th>
                    <th>مردودات</th>
                    <th>قيمة المردود</th>
                    <th>الصافي</th>
                    <th>متوسط سلة</th>
                    <th>أكثر منتج</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($unifiedChannelRows as $ur): ?>
                    <?php $top = $ur['_top'] ?? null; ?>
                <tr<?php echo (int) ($ur['id'] ?? 0) === 0 ? ' style="background:#f8fafc;"' : ''; ?>>
                    <td><?php echo (int) ($ur['_rank'] ?? 0); ?></td>
                    <td>
                        <?php echo htmlspecialchars((string) ($ur['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ((int) ($ur['id'] ?? 0) === 0): ?>
                            <span class="badge" title="فواتير شركة INV-C بدون قناة تسويق">شركة مباشرة</span>
                        <?php elseif ((int) ($ur['is_active'] ?? 0) !== 1): ?>
                            <span class="badge" title="القناة غير نشطة">مخفية</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo htmlspecialchars((string) ($ur['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                    <td><?php echo (int) ($ur['sales_cnt_done'] ?? 0); ?></td>
                    <td><?php echo number_format((float) ($ur['sales_rev'] ?? 0), $caMoney['decimals']); ?></td>
                    <td><?php echo (int) ($ur['ret_cnt'] ?? 0); ?></td>
                    <td><?php echo number_format((float) ($ur['ret_total'] ?? 0), $caMoney['decimals']); ?></td>
                    <td><strong><?php echo number_format((float) ($ur['net'] ?? 0), $caMoney['decimals']); ?></strong></td>
                    <td><?php echo number_format((float) ($ur['_avg_basket'] ?? 0), $caMoney['decimals']); ?></td>
                    <td><?php
                    if ($top) {
                        echo htmlspecialchars($top['product_name'], ENT_QUOTES, 'UTF-8')
                            . ' (<strong>' . (int) $top['qty_sum'] . '</strong>)';
                    } else {
                        echo '—';
                    }
                    ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($channelRows === [] && (int) ($companyDirect['cnt_completed'] ?? 0) === 0 && (int) $companyReturns['cnt'] === 0): ?>
        <p class="card-hint">لا توجد حركة في الفترة — راجع <a href="<?php echo htmlspecialchars($channelsUrl, ENT_QUOTES, 'UTF-8'); ?>">قنوات العملاء</a>.</p>
    <?php endif; ?>
</div>

<?php if ((int) ($orphan['cnt_all'] ?? 0) > (int) ($companyDirect['cnt_all'] ?? 0)): ?>
<div class="card">
    <h3 class="card-title">طلبات بلا قناة (أخرى — غير «الشركة» المباشرة)</h3>
    <p class="card-hint">طلبات بدون <code>channel_id</code> ولا تُحسب ضمن صف «الشركة» أعلاه.</p>
    <ul style="line-height:1.7;">
        <li>إجمالي الطلبات: <strong><?php echo (int) $orphan['cnt_all']; ?></strong></li>
        <li>مكتملة: <strong><?php echo (int) $orphan['cnt_completed']; ?></strong></li>
        <li>إيراد مكتمل: <strong><?php echo orange_format_money_for_context($caMoney, (float) $orphan['revenue_completed']); ?></strong></li>
    </ul>
</div>
<?php endif; ?>

<div class="card">
    <h3 class="card-title">روابط سريعة</h3>
    <p>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars($channelsUrl, ENT_QUOTES, 'UTF-8'); ?>">إدارة القنوات</a>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars($ordersUrl, ENT_QUOTES, 'UTF-8'); ?>">الطلبات</a>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars($returnsReportUrl, ENT_QUOTES, 'UTF-8'); ?>">تقرير مردودات المبيعات</a>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=reports'), ENT_QUOTES, 'UTF-8'); ?>">تقارير المبيعات العامة</a>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars($companyInvoiceUrl, ENT_QUOTES, 'UTF-8'); ?>">فاتورة مبيعات الشركة</a>
    </p>
</div>
