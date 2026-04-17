<?php
$pdo = db();

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$orderNumberLookup = isset($_GET['order_number']) ? trim((string)$_GET['order_number']) : '';

if ($orderId <= 0 && $orderNumberLookup !== '') {
    $st = $pdo->prepare('SELECT id FROM orders WHERE order_number = ? LIMIT 1');
    $st->execute([$orderNumberLookup]);
    $orderId = (int)$st->fetchColumn();
}

$order = null;
$items = [];
$channelName = '';

if ($orderId > 0) {
    $stmt = $pdo->prepare('
        SELECT o.*, c.name AS channel_name
        FROM orders o
        LEFT JOIN channels c ON c.id = o.channel_id
        WHERE o.id = ?
        LIMIT 1
    ');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($order) {
        $channelName = (string)($order['channel_name'] ?? '');
        $it = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
        $it->execute([$orderId]);
        $items = $it->fetchAll(PDO::FETCH_ASSOC);
    }
}

$recentForPicker = [];
if (!$order) {
    try {
        $recentForPicker = $pdo->query(
            'SELECT o.id, o.order_number, o.customer_name, o.total, o.status, o.created_at, o.order_source
             FROM orders o
             ORDER BY o.id DESC
             LIMIT 30'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $recentForPicker = [];
    }
}
?>
<div class="page-title">
    <h1>فاتورة مبيعات</h1>
    <p style="margin:0.35rem 0 0;font-size:0.95rem;opacity:0.9;">اختر طلبًا من القائمة أو افتحها من صفحة الطلبات.</p>
</div>

<style>
    .invoice-wrap { max-width: 800px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; }
    .invoice-wrap h2 { margin-top: 0; }
    .invoice-meta { margin: 16px 0; line-height: 1.6; }
    .invoice-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    .invoice-table th, .invoice-table td { border: 1px solid #ddd; padding: 8px; text-align: right; }
    .invoice-table th { background: #f5f5f5; }
    .invoice-actions { margin-top: 20px; }
    .invoice-picker { margin-bottom: 1rem; display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
    .invoice-picker label { display: flex; flex-direction: column; gap: 4px; font-size: 0.9rem; }
    .invoice-picker input[type="text"] { min-width: 200px; padding: 8px; }
    @media print {
        .invoice-actions, .invoice-picker, .page-title p, .admin-sidebar, .admin-user, .brand { display: none !important; }
        .admin-main { margin: 0 !important; padding: 0 !important; }
        body { background: #fff !important; }
    }
</style>

<?php if (!$order): ?>
<div class="card">
    <h3>فتح فاتورة</h3>
    <?php if ($orderId > 0 || $orderNumberLookup !== ''): ?>
        <div class="alert-error" style="margin-bottom:12px;">لم يتم العثور على الطلب<?php echo $orderId > 0 ? ' (المعرّف: ' . (int)$orderId . ')' : ''; ?><?php echo $orderNumberLookup !== '' ? ' (الرقم: ' . htmlspecialchars($orderNumberLookup, ENT_QUOTES, 'UTF-8') . ')' : ''; ?>.</div>
    <?php endif; ?>
    <form method="get" action="" class="invoice-picker">
        <input type="hidden" name="page" value="invoice">
        <label>
            رقم الطلب (الظاهر للعميل)
            <input type="text" name="order_number" value="<?php echo htmlspecialchars($orderNumberLookup, ENT_QUOTES, 'UTF-8'); ?>" placeholder="مثال: ORD-123">
        </label>
        <button type="submit">عرض</button>
    </form>
    <p style="margin:0 0 12px;"><a class="btn-secondary" href="/admin/index.php?page=orders">← كل الطلبات</a></p>
    <?php if ($recentForPicker): ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>رقم الطلب</th>
                    <th>المصدر</th>
                    <th>العميل</th>
                    <th>الإجمالي</th>
                    <th>الحالة</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentForPicker as $r): ?>
                <tr>
                    <td><?php echo (int)$r['id']; ?></td>
                    <td><?php echo htmlspecialchars((string)$r['order_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php
                        $rs = (string)($r['order_source'] ?? 'website');
                        echo $rs === 'company' ? 'شركة' : 'موقع';
                    ?></td>
                    <td><?php echo htmlspecialchars((string)$r['customer_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo number_format((float)$r['total'], 2); ?> KD</td>
                    <td><?php echo htmlspecialchars((string)$r['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><a class="btn-secondary" href="/admin/index.php?page=invoice&amp;order_id=<?php echo (int)$r['id']; ?>">فتح</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>

<div class="invoice-wrap">
    <h2>فاتورة مبيعات / Invoice</h2>
    <div class="invoice-meta">
        <?php
        $orderSrc = (string)($order['order_source'] ?? 'website');
        $orderSrcAr = $orderSrc === 'company'
            ? 'فاتورة شركة — لم يُنشأ هذا الطلب من الموقع'
            : 'طلب من الموقع الإلكتروني';
        ?>
        <div><strong>المصدر:</strong> <?php echo htmlspecialchars($orderSrcAr, ENT_QUOTES, 'UTF-8'); ?></div>
        <div><strong>رقم الطلب:</strong> <?php echo htmlspecialchars((string)$order['order_number'], ENT_QUOTES, 'UTF-8'); ?></div>
        <div><strong>العميل:</strong> <?php echo htmlspecialchars((string)$order['customer_name'], ENT_QUOTES, 'UTF-8'); ?></div>
        <div><strong>الهاتف:</strong> <?php echo htmlspecialchars((string)$order['phone'], ENT_QUOTES, 'UTF-8'); ?></div>
        <div><strong>المنطقة:</strong> <?php echo htmlspecialchars((string)$order['area'], ENT_QUOTES, 'UTF-8'); ?></div>
        <div><strong>العنوان:</strong> <?php echo htmlspecialchars((string)$order['address'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php if ($channelName !== ''): ?>
            <div><strong>القناة:</strong> <?php echo htmlspecialchars($channelName, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <div><strong>الحالة:</strong> <?php echo htmlspecialchars((string)$order['status'], ENT_QUOTES, 'UTF-8'); ?></div>
        <div><strong>الإجمالي:</strong> <?php echo number_format((float)$order['total'], 2); ?> KD</div>
    </div>

    <table class="invoice-table">
        <thead>
            <tr>
                <th>الوصف</th>
                <th>اللون</th>
                <th>المقاس</th>
                <th>الكمية</th>
                <th>السعر</th>
                <th>الإجمالي</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $row): ?>
                <?php $line = (float)$row['price'] * (int)$row['qty']; ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$row['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['color'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['size'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int)$row['qty']; ?></td>
                    <td><?php echo number_format((float)$row['price'], 2); ?> KD</td>
                    <td><?php echo number_format($line, 2); ?> KD</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="invoice-actions">
        <a class="btn-secondary" href="/admin/index.php?page=invoice">فواتير أخرى</a>
        <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
    </div>
</div>
<?php endif; ?>
