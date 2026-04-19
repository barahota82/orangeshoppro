<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$fromIn = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
$toIn = isset($_GET['to']) ? trim((string) $_GET['to']) : '';
$fromOk = $fromIn !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromIn) === 1;
$toOk = $toIn !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toIn) === 1;

/**
 * @return array{0: string, 1: list<mixed>}
 */
function orange_channel_analytics_join_orders_on(bool $fromOk, string $fromIn, bool $toOk, string $toIn): array
{
    $on = 'o.channel_id = c.id';
    $params = [];
    if ($fromOk) {
        $on .= ' AND o.created_at >= ?';
        $params[] = $fromIn . ' 00:00:00';
    }
    if ($toOk) {
        $on .= ' AND o.created_at <= ?';
        $params[] = $toIn . ' 23:59:59';
    }

    return [$on, $params];
}

[$joinOn, $joinParams] = orange_channel_analytics_join_orders_on($fromOk, $fromIn, $toOk, $toIn);

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
    WHERE (channel_id IS NULL OR channel_id = 0)
';
$orphanParams = [];
if ($fromOk) {
    $orphanSql .= ' AND created_at >= ?';
    $orphanParams[] = $fromIn . ' 00:00:00';
}
if ($toOk) {
    $orphanSql .= ' AND created_at <= ?';
    $orphanParams[] = $toIn . ' 23:59:59';
}
$stOr = $pdo->prepare($orphanSql);
$stOr->execute($orphanParams);
$orphan = $stOr->fetch(PDO::FETCH_ASSOC) ?: ['cnt_all' => 0, 'cnt_completed' => 0, 'revenue_completed' => 0];

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
    $topParams[] = $fromIn . ' 00:00:00';
}
if ($toOk) {
    $topSql .= ' AND o.created_at <= ?';
    $topParams[] = $toIn . ' 23:59:59';
}
$topSql .= ' GROUP BY o.channel_id, oi.product_name ORDER BY o.channel_id ASC, qty_sum DESC';

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

$channelsUrl = '/admin/index.php?page=channels';
$ordersUrl = '/admin/index.php?page=orders';
?>
<div class="page-title">
    <h1>تحليل أداء قنوات العملاء</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;max-width:900px;line-height:1.55;">
        القنوات عندكم تمثّل <strong>واجهات البيع</strong> (مثل تيك توك / واتساب / …) وتوزيع العملاء — بحسب مذكرة النظام: مسارات منفصلة و<code>channel_id</code> على الطلب، مع <strong>مخزون ومبيعات شركة واحدة</strong>.
        هذا التقرير يقيّس «شغل» كل قناة: عدد الطلبات، التسليمات، الإيراد، وأكثر منتج حركةً داخل القناة.
        توجيه الزائر من دومين الشركة إلى القناة التي فتحها يُنفَّذ في الواجهة العامة؛ هنا نعرض نتائج الطلبات المحفوظة فقط.
    </p>
</div>

<div class="card">
    <h3 class="card-title">فلترة بالتاريخ (حسب تاريخ إنشاء الطلب)</h3>
    <form method="get" action="" class="form-grid" style="align-items:end;max-width:720px;">
        <input type="hidden" name="page" value="channel_analytics">
        <div>
            <label for="ca_from">من</label>
            <input type="date" id="ca_from" name="from" value="<?php echo htmlspecialchars($fromIn, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div>
            <label for="ca_to">إلى</label>
            <input type="date" id="ca_to" name="to" value="<?php echo htmlspecialchars($toIn, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="actions" style="margin:0;">
            <button type="submit">تطبيق</button>
            <a class="btn btn-secondary" href="/admin/index.php?page=channel_analytics">كل الفترات</a>
        </div>
    </form>
</div>

<div class="card">
    <h3 class="card-title">ملخص القنوات — ترتيب النشاط (حسب إيراد الطلبات المكتملة)</h3>
    <p class="card-hint">المركز 1 = أعلى إيراد من طلبات <code>completed</code> في الفترة المختارة.</p>
    <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>القناة</th>
                    <th>Slug</th>
                    <th>طلبات (كل الحالات)</th>
                    <th>طلبات مكتملة</th>
                    <th>إيراد مكتمل (KD)</th>
                    <th>متوسط سلة مكتملة</th>
                    <th>أكثر منتج (كمية)</th>
                    <th>إيراد ذلك المنتج (تقريبي)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($channelRows as $cr): ?>
                    <?php
                    $cid = (int) $cr['id'];
                    $top = $topByChannel[$cid] ?? null;
                    ?>
                <tr>
                    <td><?php echo (int) ($cr['_rank'] ?? 0); ?></td>
                    <td>
                        <?php echo htmlspecialchars((string) ($cr['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ((int) ($cr['is_active'] ?? 0) !== 1): ?>
                            <span class="badge" title="القناة غير نشطة">مخفية</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo htmlspecialchars((string) ($cr['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                    <td><?php echo (int) ($cr['cnt_all'] ?? 0); ?></td>
                    <td><?php echo (int) ($cr['cnt_completed'] ?? 0); ?></td>
                    <td><?php echo number_format((float) ($cr['revenue_completed'] ?? 0), 3); ?></td>
                    <td><?php echo number_format((float) ($cr['_avg_basket'] ?? 0), 3); ?></td>
                    <td><?php
                    if ($top) {
                        echo htmlspecialchars($top['product_name'], ENT_QUOTES, 'UTF-8')
                            . ' (<strong>' . (int) $top['qty_sum'] . '</strong>)';
                    } else {
                        echo '—';
                    }
                    ?></td>
                    <td><?php echo $top ? number_format($top['revenue_lines'], 3) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($channelRows === []): ?>
        <p class="card-hint">لا توجد قنوات معرّفة — أضف قنوات من <a href="<?php echo htmlspecialchars($channelsUrl, ENT_QUOTES, 'UTF-8'); ?>">قنوات العملاء</a>.</p>
    <?php endif; ?>
</div>

<?php if ((int) ($orphan['cnt_all'] ?? 0) > 0): ?>
<div class="card">
    <h3 class="card-title">طلبات بلا قناة</h3>
    <p class="card-hint">طلبات لا تحمل <code>channel_id</code> — راجع تسجيل الطلب أو التوجيه من المتجر.</p>
    <ul style="line-height:1.7;">
        <li>إجمالي الطلبات: <strong><?php echo (int) $orphan['cnt_all']; ?></strong></li>
        <li>مكتملة: <strong><?php echo (int) $orphan['cnt_completed']; ?></strong></li>
        <li>إيراد مكتمل: <strong><?php echo number_format((float) $orphan['revenue_completed'], 3); ?> KD</strong></li>
    </ul>
    <p><a class="btn btn-secondary" href="<?php echo htmlspecialchars($ordersUrl, ENT_QUOTES, 'UTF-8'); ?>">الطلبات</a></p>
</div>
<?php endif; ?>

<div class="card">
    <h3 class="card-title">روابط سريعة</h3>
    <p>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars($channelsUrl, ENT_QUOTES, 'UTF-8'); ?>">إدارة القنوات</a>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars($ordersUrl, ENT_QUOTES, 'UTF-8'); ?>">الطلبات</a>
        <a class="btn btn-secondary" href="/admin/index.php?page=reports">تقارير المبيعات العامة</a>
    </p>
</div>
