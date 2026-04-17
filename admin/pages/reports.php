<?php
$pdo = db();
$totalOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalSales = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'completed'")->fetchColumn();
$pending = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$completed = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'completed'")->fetchColumn();

$topProducts = $pdo->query("
    SELECT oi.product_name, SUM(oi.qty) AS total_qty
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    WHERE o.status = 'completed'
    GROUP BY oi.product_name
    ORDER BY total_qty DESC
    LIMIT 10
")->fetchAll();
?>
<div class="page-title">
    <h1>التقارير</h1>
</div>

<div class="grid-4">
    <div class="card stat-card"><h3>إجمالي الطلبات</h3><div class="value"><?php echo $totalOrders; ?></div></div>
    <div class="card stat-card"><h3>إجمالي المبيعات</h3><div class="value"><?php echo number_format($totalSales,2); ?> KD</div></div>
    <div class="card stat-card"><h3>Pending</h3><div class="value"><?php echo $pending; ?></div></div>
    <div class="card stat-card"><h3>Delivered</h3><div class="value"><?php echo $completed; ?></div></div>
</div>

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
