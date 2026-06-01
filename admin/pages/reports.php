<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';

$pdo = db();
$reportsCountryId = orange_admin_context_country_id($pdo);
$reportsCurrencyCode = orange_admin_context_currency_code($pdo);
$reportsCurrencyDecimals = orange_currency_decimals_for_code($reportsCurrencyCode);
$reportsCurrencyUnit = orange_currency_display_unit($reportsCurrencyCode);
$reportsOrdersSql = orange_sql_country_and_fragment($pdo, 'orders', 'orders', $reportsCountryId);
$reportsOrdersAliasSql = orange_sql_country_and_fragment($pdo, 'orders', 'o', $reportsCountryId);

$totalOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE 1=1" . $reportsOrdersSql)->fetchColumn();
$totalSales = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'completed'" . $reportsOrdersSql)->fetchColumn();
$pending = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'" . $reportsOrdersSql)->fetchColumn();
$completed = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'completed'" . $reportsOrdersSql)->fetchColumn();

/** @var list<array{area_label: string, order_count: int, revenue_kd: float}> */
$salesByArea = [];
if (orange_table_exists($pdo, 'orders')) {
    $joinDa = orange_table_exists($pdo, 'delivery_areas') && orange_table_has_column($pdo, 'orders', 'delivery_area_id');
    if ($joinDa) {
        $areaExpr = "COALESCE(NULLIF(TRIM(da.name_ar), ''), NULLIF(TRIM(o.area), ''), '—')";
        $sqlArea = "SELECT {$areaExpr} AS area_label, COUNT(*) AS order_count, COALESCE(SUM(o.total), 0) AS revenue_kd
            FROM orders o
            LEFT JOIN delivery_areas da ON da.id = o.delivery_area_id
            WHERE o.status = 'completed'" . $reportsOrdersAliasSql . "
            GROUP BY {$areaExpr}
            ORDER BY revenue_kd DESC";
        $salesByArea = $pdo->query($sqlArea)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $areaExpr = "COALESCE(NULLIF(TRIM(o.area), ''), '—')";
        $sqlArea = "SELECT {$areaExpr} AS area_label, COUNT(*) AS order_count, COALESCE(SUM(o.total), 0) AS revenue_kd
            FROM orders o
            WHERE o.status = 'completed'" . $reportsOrdersAliasSql . "
            GROUP BY {$areaExpr}
            ORDER BY revenue_kd DESC";
        $salesByArea = $pdo->query($sqlArea)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

$topProducts = $pdo->query("
    SELECT oi.product_name, SUM(oi.qty) AS total_qty
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    WHERE o.status = 'completed'" . $reportsOrdersAliasSql . "
    GROUP BY oi.product_name
    ORDER BY total_qty DESC
    LIMIT 10
")->fetchAll();
?>
<div class="page-title">
    <h1>التقارير</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;">لتحليل <strong>كل قناة على حدة</strong> (طلبات، إيراد، أكثر منتج، ترتيب النشاط) استخدم
        <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=channel_analytics'), ENT_QUOTES, 'UTF-8'); ?>">تحليل القنوات</a>.
        لتفصيل <strong>مردودات المبيعات</strong> (مصدر الفاتورة، التحصيل، قناة التسويق، المنتجات) استخدم
        <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=sales_returns_report'), ENT_QUOTES, 'UTF-8'); ?>">تقرير مردودات المبيعات</a>.</p>
</div>

<div class="grid-4">
    <div class="card stat-card"><h3>إجمالي الطلبات</h3><div class="value"><?php echo $totalOrders; ?></div></div>
    <div class="card stat-card"><h3>إجمالي المبيعات</h3><div class="value"><?php echo number_format($totalSales, $reportsCurrencyDecimals); ?> <?php echo htmlspecialchars($reportsCurrencyUnit, ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="card stat-card"><h3>Pending</h3><div class="value"><?php echo $pending; ?></div></div>
    <div class="card stat-card"><h3>Delivered</h3><div class="value"><?php echo $completed; ?></div>    </div>
</div>

<?php if (orange_table_exists($pdo, 'orders')): ?>
<div class="card">
    <h3>مبيعات مكتملة حسب منطقة التوصيل</h3>
    <p class="card-hint" style="margin:0 0 0.75rem;">حسب سياسة التسجيل: تجميع الطلبات المسلّمة (<code>completed</code>) — الاسم من جدول المناطق عند توفر <code>delivery_area_id</code>، وإلا نص المنطقة المحفوظ على الطلب.</p>
    <?php if ($salesByArea === []): ?>
        <p class="page-subtitle" style="margin-top:0;">لا توجد طلبات مكتملة بعد لعرض تفصيل المناطق.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>المنطقة</th>
                    <th>عدد الطلبات</th>
                    <th>إجمالي الإيراد (<?php echo htmlspecialchars($reportsCurrencyUnit, ENT_QUOTES, 'UTF-8'); ?>)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($salesByArea as $ar): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)($ar['area_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int)($ar['order_count'] ?? 0); ?></td>
                    <td><?php echo number_format((float)($ar['revenue_kd'] ?? 0), $reportsCurrencyDecimals); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h3>أكثر المنتجات مبيعًا</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>المنتج</th>
                    <th>الكمية المباعة</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topProducts as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                    <td><?php echo (int)$row['total_qty']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
