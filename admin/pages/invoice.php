<?php

require_once __DIR__ . '/../../includes/order_helpers.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/document_sequences.php';

/**
 * @param array<string, mixed> $order
 */
function orange_invoice_assign_number_if_needed(PDO $pdo, array &$order, int $orderId): void
{
    if ($orderId <= 0 || !orange_table_has_column($pdo, 'orders', 'invoice_number')) {
        return;
    }
    $invNum = trim((string) ($order['invoice_number'] ?? ''));
    if ($invNum !== '') {
        return;
    }
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT invoice_number FROM orders WHERE id = ? FOR UPDATE');
        $lock->execute([$orderId]);
        $current = $lock->fetchColumn();
        $curStr = $current !== false ? trim((string) $current) : '';
        if ($curStr !== '') {
            $order['invoice_number'] = $curStr;
            $pdo->commit();

            return;
        }
        $next = orange_sequence_next($pdo, 'sales_invoice');
        $formatted = 'INV-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        $upd = $pdo->prepare('UPDATE orders SET invoice_number = ? WHERE id = ?');
        $upd->execute([$formatted, $orderId]);
        $order['invoice_number'] = $formatted;
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (function_exists('error_log')) {
            error_log('[orange] invoice number assign: ' . $e->getMessage());
        }
    }
}

function orange_invoice_format_datetime_display(?string $dt): string
{
    if ($dt === null || trim($dt) === '') {
        return '—';
    }
    $t = strtotime($dt);

    return $t !== false ? date('d/m/Y H:i', $t) : $dt;
}

function orange_invoice_logo_url(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $raw) === 1) {
        return $raw;
    }
    if (isset($raw[0]) && $raw[0] === '/') {
        return $raw;
    }

    return '/uploads/' . rawurlencode(basename($raw));
}

/**
 * @return array<string, string>
 */
function orange_invoice_load_company(PDO $pdo): array
{
    $defaults = [
        'company_name_ar' => '',
        'company_name_en' => '',
        'company_logo' => '',
        'commercial_register' => '',
        'phones' => '',
        'address' => '',
        'vat_number' => '',
        'invoice_footer' => '',
    ];
    try {
        orange_catalog_ensure_schema($pdo);
        if (!orange_table_exists($pdo, 'company_settings')) {
            return $defaults;
        }
        $row = $pdo->query('SELECT * FROM company_settings ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $defaults;
        }

        return array_merge($defaults, $row);
    } catch (Throwable $e) {
        return $defaults;
    }
}

$pdo = db();
orange_catalog_ensure_schema($pdo);

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
$companyProfile = [];
$linesSubtotal = 0.0;

$orderStatusAr = [
    'pending' => 'قيد الانتظار',
    'approved' => 'مقبول',
    'rejected' => 'مرفوض',
    'on_the_way' => 'بالطريق',
    'completed' => 'تم التوصيل',
    'cancelled' => 'ملغي',
];

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
        $companyProfile = orange_invoice_load_company($pdo);
        orange_invoice_assign_number_if_needed($pdo, $order, $orderId);
        foreach ($items as $row) {
            $linesSubtotal += (float)$row['price'] * (int)$row['qty'];
        }
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

$orderTotalVal = $order ? (float)$order['total'] : 0.0;
$linesMismatch = $order && abs($linesSubtotal - $orderTotalVal) > 0.009;
?>
<div class="page-title">
    <h1>فاتورة مبيعات</h1>
    <p style="margin:0.35rem 0 0;font-size:0.95rem;opacity:0.9;">مستند رسمي للعميل — يُخصَّص من «بيانات الشركة». افتح الفاتورة من الطلبات أو أدخل رقم الطلب.</p>
</div>

<style>
    .invoice-doc {
        max-width: 920px;
        margin: 0 auto;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
        overflow: hidden;
    }
    .invoice-doc-header {
        display: flex;
        flex-wrap: wrap;
        gap: 1.25rem;
        justify-content: space-between;
        align-items: flex-start;
        padding: 1.35rem 1.5rem;
        background: linear-gradient(135deg, #f8fafc 0%, #fff 50%);
        border-bottom: 1px solid #e2e8f0;
    }
    .invoice-doc-brand { display: flex; gap: 1rem; align-items: flex-start; max-width: 58%; }
    .invoice-doc-logo {
        width: 72px; height: 72px; object-fit: contain;
        border: 1px solid #e2e8f0; border-radius: 8px; background: #fff;
    }
    .invoice-doc-titles h2 { margin: 0 0 0.25rem; font-size: 1.35rem; font-weight: 700; color: #0f172a; }
    .invoice-doc-titles .invoice-doc-sub { margin: 0; font-size: 0.92rem; color: #64748b; }
    .invoice-doc-titles .invoice-doc-sub-en { direction: ltr; text-align: left; font-size: 0.88rem; color: #64748b; margin-top: 0.2rem; }
    .invoice-doc-meta-box {
        min-width: 220px;
        padding: 0.85rem 1rem;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 0.88rem;
        line-height: 1.55;
    }
    .invoice-doc-meta-box strong { color: #334155; }
    .invoice-doc-badge {
        display: inline-block;
        margin-top: 0.35rem;
        padding: 0.15rem 0.5rem;
        border-radius: 4px;
        font-size: 0.75rem;
        background: #eff6ff;
        color: #1d4ed8;
    }
    .invoice-body { padding: 1.25rem 1.5rem 1.5rem; }
    .invoice-grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-bottom: 1.25rem;
    }
    @media (max-width: 720px) {
        .invoice-grid-2 { grid-template-columns: 1fr; }
        .invoice-doc-brand { max-width: 100%; }
    }
    .invoice-panel {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 0.9rem 1rem;
        background: #fafafa;
        font-size: 0.9rem;
        line-height: 1.55;
    }
    .invoice-panel h3 { margin: 0 0 0.5rem; font-size: 0.95rem; color: #0f172a; }
    .invoice-table { width: 100%; border-collapse: collapse; margin-top: 0.5rem; font-size: 0.9rem; }
    .invoice-table th, .invoice-table td { border: 1px solid #e2e8f0; padding: 0.55rem 0.65rem; text-align: right; }
    .invoice-table th { background: #f1f5f9; color: #334155; font-weight: 600; }
    .invoice-table td.num, .invoice-table th.num { text-align: center; }
    .invoice-totals {
        margin-top: 1rem;
        display: flex;
        justify-content: flex-end;
    }
    .invoice-totals-inner {
        min-width: 260px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 0.75rem 1rem;
        background: #fff;
    }
    .invoice-totals-row { display: flex; justify-content: space-between; gap: 1rem; padding: 0.25rem 0; }
    .invoice-totals-row.grand { font-weight: 700; font-size: 1.05rem; border-top: 1px solid #e2e8f0; margin-top: 0.35rem; padding-top: 0.5rem; }
    .invoice-footer-legal {
        margin-top: 1.25rem;
        padding-top: 1rem;
        border-top: 1px dashed #cbd5e1;
        font-size: 0.82rem;
        color: #475569;
        line-height: 1.6;
    }
    .invoice-actions { margin-top: 1.25rem; display: flex; flex-wrap: wrap; gap: 0.5rem; }
    .invoice-picker { margin-bottom: 1rem; display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
    .invoice-picker label { display: flex; flex-direction: column; gap: 4px; font-size: 0.9rem; }
    .invoice-picker input[type="text"] { min-width: 200px; padding: 8px; }
    .invoice-warn {
        margin-top: 0.75rem;
        padding: 0.5rem 0.75rem;
        border-radius: 6px;
        background: #fff7ed;
        border: 1px solid #fdba74;
        color: #9a3412;
        font-size: 0.85rem;
    }
    @media print {
        .invoice-actions, .invoice-picker, .page-title, .admin-sidebar, .admin-user, .brand { display: none !important; }
        .admin-main { margin: 0 !important; padding: 0 !important; }
        body { background: #fff !important; }
        .invoice-doc { box-shadow: none; border: none; max-width: none; }
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
    <p style="margin:0 0 12px;"><a class="btn btn-secondary" href="/admin/index.php?page=orders">← كل الطلبات</a></p>
    <?php if ($recentForPicker): ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>رقم الطلب</th>
                    <th>المصدر</th>
                    <th>العميل</th>
                    <th>نوع البيع</th>
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
                    <td><?php echo htmlspecialchars(orange_order_payment_terms_label_ar($r['payment_terms'] ?? 'cash'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo number_format((float)$r['total'], 2); ?> KD</td>
                    <td><?php
                        $rst = strtolower(trim((string) ($r['status'] ?? '')));
                        echo htmlspecialchars($orderStatusAr[$rst] ?? (string) ($r['status'] ?? ''), ENT_QUOTES, 'UTF-8');
                    ?></td>
                    <td><a class="btn btn-secondary" href="/admin/index.php?page=invoice&amp;order_id=<?php echo (int)$r['id']; ?>">فتح</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<?php
    $logoSrc = orange_invoice_logo_url((string)($companyProfile['company_logo'] ?? ''));
    $nameAr = trim((string)($companyProfile['company_name_ar'] ?? ''));
    $nameEn = trim((string)($companyProfile['company_name_en'] ?? ''));
    $cr = trim((string)($companyProfile['commercial_register'] ?? ''));
    $phones = trim((string)($companyProfile['phones'] ?? ''));
    $addr = trim((string)($companyProfile['address'] ?? ''));
    $vat = trim((string)($companyProfile['vat_number'] ?? ''));
    $footerLegal = trim((string)($companyProfile['invoice_footer'] ?? ''));
    $formalInv = trim((string)($order['invoice_number'] ?? ''));
    $orderSrc = (string)($order['order_source'] ?? 'website');
    $orderSrcAr = $orderSrc === 'company'
        ? 'فاتورة شركة (طلب داخلي)'
        : 'طلب وارد من الموقع';
    $ost = strtolower(trim((string) ($order['status'] ?? '')));
    $statusLabel = $orderStatusAr[$ost] ?? (string) ($order['status'] ?? '');
    $ptAr = 'بيع ' . orange_order_payment_terms_label_ar($order['payment_terms'] ?? 'cash');
?>

<div class="invoice-doc">
    <div class="invoice-doc-header">
        <div class="invoice-doc-brand">
            <?php if ($logoSrc !== ''): ?>
                <img class="invoice-doc-logo" src="<?php echo htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="">
            <?php endif; ?>
            <div class="invoice-doc-titles">
                <h2><?php echo $nameAr !== '' ? htmlspecialchars($nameAr, ENT_QUOTES, 'UTF-8') : 'فاتورة ضريبية / Tax Invoice'; ?></h2>
                <?php if ($nameEn !== ''): ?>
                    <p class="invoice-doc-sub-en"><?php echo htmlspecialchars($nameEn, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <p class="invoice-doc-sub">مستند مطابق لطلب التوريد — <?php echo htmlspecialchars($orderSrcAr, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
        <div class="invoice-doc-meta-box">
            <?php if ($formalInv !== ''): ?>
                <div><strong>رقم الفاتورة:</strong> <?php echo htmlspecialchars($formalInv, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php else: ?>
                <div><strong>رقم الفاتورة:</strong> <span style="color:#94a3b8;">يُخصص تلقائياً عند أول عرض</span></div>
            <?php endif; ?>
            <div><strong>رقم الطلب:</strong> <?php echo htmlspecialchars((string)$order['order_number'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div><strong>تاريخ الطلب:</strong> <?php echo htmlspecialchars(orange_invoice_format_datetime_display((string)($order['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></div>
            <div><strong>طباعة:</strong> <?php echo htmlspecialchars(date('d/m/Y H:i'), ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if ($vat !== ''): ?>
                <div class="invoice-doc-badge">الرقم الضريبي: <?php echo htmlspecialchars($vat, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="invoice-body">
        <?php if ($cr !== '' || $phones !== '' || $addr !== ''): ?>
        <div class="invoice-panel" style="margin-bottom:1rem;background:#fff;">
            <?php if ($cr !== ''): ?><div><strong>السجل التجاري:</strong> <?php echo htmlspecialchars($cr, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($phones !== ''): ?><div><strong>التواصل:</strong> <?php echo htmlspecialchars($phones, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($addr !== ''): ?><div><strong>العنوان:</strong> <?php echo nl2br(htmlspecialchars($addr, ENT_QUOTES, 'UTF-8')); ?></div><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="invoice-grid-2">
            <div class="invoice-panel">
                <h3>بيانات العميل</h3>
                <div><strong>الاسم:</strong> <?php echo htmlspecialchars((string)$order['customer_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div><strong>الهاتف:</strong> <?php echo htmlspecialchars((string)$order['phone'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div><strong>المنطقة:</strong> <?php echo htmlspecialchars((string)$order['area'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div><strong>العنوان:</strong> <?php echo htmlspecialchars((string)$order['address'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php if ($channelName !== ''): ?>
                    <div><strong>القناة:</strong> <?php echo htmlspecialchars($channelName, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>
            <div class="invoice-panel">
                <h3>حالة المستند</h3>
                <div><strong>حالة الطلب:</strong> <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                <div><strong>نوع البيع:</strong> <?php echo htmlspecialchars($ptAr, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>

        <?php if ($linesMismatch): ?>
            <div class="invoice-warn">تنبيه: مجموع بنود الفاتورة (<?php echo number_format($linesSubtotal, 2); ?> KD) يختلف عن إجمالي الطلب المحفوظ (<?php echo number_format($orderTotalVal, 2); ?> KD). راجع الطلب أو الخصومات.</div>
        <?php endif; ?>

        <table class="invoice-table">
            <thead>
                <tr>
                    <th class="num">#</th>
                    <th>الوصف</th>
                    <th>اللون</th>
                    <th>المقاس</th>
                    <th class="num">الكمية</th>
                    <th class="num">سعر الوحدة</th>
                    <th class="num">الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                <?php $ln = 0; foreach ($items as $row): ?>
                    <?php
                    ++$ln;
                    $line = (float)$row['price'] * (int)$row['qty'];
                    ?>
                    <tr>
                        <td class="num"><?php echo (int)$ln; ?></td>
                        <td><?php echo htmlspecialchars((string)$row['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['color'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['size'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="num"><?php echo (int)$row['qty']; ?></td>
                        <td class="num"><?php echo number_format((float)$row['price'], 3); ?> KD</td>
                        <td class="num"><?php echo number_format($line, 3); ?> KD</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="invoice-totals">
            <div class="invoice-totals-inner">
                <div class="invoice-totals-row">
                    <span>مجموع البنود</span>
                    <span><?php echo number_format($linesSubtotal, 3); ?> KD</span>
                </div>
                <div class="invoice-totals-row grand">
                    <span>الإجمالي</span>
                    <span><?php echo number_format($orderTotalVal, 3); ?> KD</span>
                </div>
            </div>
        </div>

        <?php if ($footerLegal !== ''): ?>
            <div class="invoice-footer-legal"><?php echo nl2br(htmlspecialchars($footerLegal, ENT_QUOTES, 'UTF-8')); ?></div>
        <?php endif; ?>

        <div class="invoice-actions">
            <a class="btn btn-secondary" href="/admin/index.php?page=invoice">فواتير أخرى</a>
            <a class="btn btn-secondary" href="/admin/index.php?page=orders">الطلبات</a>
            <button type="button" class="btn-secondary" onclick="window.print()">طباعة / PDF</button>
        </div>
    </div>
</div>
<?php endif; ?>
