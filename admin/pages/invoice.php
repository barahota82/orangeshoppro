<?php

require_once __DIR__ . '/../../includes/order_helpers.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/document_sequences.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/invoice_ancillary_lines.php';

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
    // §13.5.7.3 — أونلاين: لا INV-O عند طباعة invoice.php؛ INV-C عند create-manual؛ INV-O عند «إنشاء القiود».
    $src = trim((string) ($order['order_source'] ?? 'website'));
    if ($src !== 'company') {
        return;
    }
    try {
        orange_order_assign_inv_c_if_needed($pdo, $orderId, $order);
        $st = $pdo->prepare('SELECT invoice_number FROM orders WHERE id = ? LIMIT 1');
        $st->execute([$orderId]);
        $order['invoice_number'] = trim((string) ($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] invoice number assign: ' . $e->getMessage());
        }
    }
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
    $path = (isset($raw[0]) && $raw[0] === '/')
        ? $raw
        : '/uploads/' . rawurlencode(basename($raw));

    return storefront_public_path($path);
}

/**
 * @return array<string, string>
 */
function orange_invoice_load_company(PDO $pdo, ?int $countryId = null): array
{
    $defaults = [
        'company_name_ar' => '',
        'company_name_en' => '',
        'company_logo' => '',
        'commercial_register' => '',
        'phones' => '',
        'address' => '',
        'vat_number' => '',
        'invoice_footer_ar' => '',
        'invoice_footer_en' => '',
    ];
    try {
        orange_catalog_ensure_schema($pdo);
        $row = orange_company_settings_row($pdo, $countryId);
        if (!is_array($row)) {
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
$invoiceCopy = isset($_GET['copy']) ? strtolower(trim((string) $_GET['copy'])) : '';
if (!in_array($invoiceCopy, ['customer', 'receipt'], true)) {
    $invoiceCopy = 'customer';
}

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
$invoicePrintExtras = [];
$invoiceExtraPrintNet = 0.0;

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
        $companyProfile = orange_invoice_load_company($pdo, (int) ($order['country_id'] ?? 0) ?: null);
        orange_invoice_assign_number_if_needed($pdo, $order, $orderId);
        foreach ($items as $row) {
            $linesSubtotal += orange_order_item_line_net($row);
        }
        $allExtraLines = orange_invoice_ancillary_extra_lines_for_doc(
            $pdo,
            orange_invoice_ancillary_doc_kind_sales(),
            $orderId
        );
        foreach ($allExtraLines as $exRow) {
            if ((int) ($exRow['show_on_print'] ?? 0) !== 1) {
                continue;
            }
            $amt = round((float) ($exRow['amount'] ?? 0), 4);
            if ($amt <= 0.0001) {
                continue;
            }
            $side = orange_invoice_ancillary_line_kind_side((string) ($exRow['line_kind'] ?? ''));
            $signed = $side === 'credit' ? $amt : ($side === 'debit' ? -$amt : $amt);
            $label = trim((string) ($exRow['label_ar'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($exRow['account_name'] ?? ''));
            }
            if ($label === '') {
                $label = 'بند إضافي';
            }
            $invoicePrintExtras[] = [
                'label' => $label,
                'amount' => $amt,
                'signed' => $signed,
            ];
            $invoiceExtraPrintNet += $signed;
        }
        $invoiceExtraPrintNet = round($invoiceExtraPrintNet, 4);
    }
}

$recentForPicker = [];
if (!$order) {
    try {
        $recentForPicker = $pdo->query(
            'SELECT o.id, o.order_number, o.customer_name, o.total, o.status, o.created_at, o.order_source, o.payment_terms
             FROM orders o
             ORDER BY o.id DESC
             LIMIT 30'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $recentForPicker = [];
    }
}

$orderTotalVal = $order ? (float)$order['total'] : 0.0;
$cartPromoDisc = 0.0;
if ($order && orange_table_has_column($pdo, 'orders', 'cart_promotion_discount')) {
    $cartPromoDisc = max(0.0, (float)($order['cart_promotion_discount'] ?? 0));
}
$cartComboDisc = 0.0;
if ($order && orange_table_has_column($pdo, 'orders', 'cart_combo_discount')) {
    $cartComboDisc = max(0.0, (float)($order['cart_combo_discount'] ?? 0));
}
$linesExpectedNet = round($linesSubtotal - $cartComboDisc - $cartPromoDisc, 4);
$linesMismatch = $order && abs($linesExpectedNet - $orderTotalVal) > 0.009;
$invMoney = orange_order_currency_context($pdo, is_array($order) ? $order : null);
$invCurUnit = (string) $invMoney['unit'];
$invCurDec = (int) $invMoney['decimals'];
$amountPaidVal = 0.0;
if ($order && orange_table_has_column($pdo, 'orders', 'amount_paid')) {
    $amountPaidVal = max(0.0, (float) ($order['amount_paid'] ?? 0));
}
$balanceDueVal = $order ? max(0.0, round($orderTotalVal - $amountPaidVal, $invCurDec)) : 0.0;
$invoiceCustomerTotal = $order ? round($orderTotalVal + $invoiceExtraPrintNet, 4) : 0.0;
$invoiceCustomerBalance = $order ? max(0.0, round($invoiceCustomerTotal - $amountPaidVal, $invCurDec)) : 0.0;
$invFmt = static function (float $amount, bool $withUnit = true) use ($invMoney): string {
    return orange_format_money_for_context($invMoney, $amount, $withUnit);
};
?>
<div class="admin-fy-shell invoice-admin-shell" dir="rtl">
    <div class="page-title">
        <h1>فاتورة أونلاين</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
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
    .invoice-totals-row--cart-promo { color: #0f766e; font-weight: 600; }
    .invoice-footer-legal {
        margin-top: 1.25rem;
        padding-top: 1rem;
        border-top: 1px dashed #cbd5e1;
        font-size: 0.82rem;
        color: #475569;
        line-height: 1.6;
    }
    .invoice-actions { margin-top: 1.25rem; display: flex; flex-wrap: wrap; gap: 0.5rem; }
    .invoice-workflow-bar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        margin-bottom: 18px;
        padding: 14px 18px;
        background: linear-gradient(180deg, #ffffff 0%, #f1f5f9 100%);
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        box-shadow: 0 4px 18px rgba(15, 23, 42, 0.07);
        position: sticky;
        top: 6px;
        z-index: 6;
    }
    .invoice-workflow-bar__meta {
        display: flex;
        flex-direction: column;
        gap: 6px;
        font-size: 0.9rem;
        line-height: 1.45;
        color: #334155;
        min-width: min(100%, 22rem);
    }
    .invoice-workflow-bar__number strong {
        font-size: 1.15rem;
        color: #0f172a;
        letter-spacing: 0.02em;
    }
    .invoice-workflow-bar__saved {
        display: inline-block;
        margin-inline-start: 8px;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
        background: #dcfce7;
        color: #166534;
    }
    .invoice-workflow-bar__pending {
        display: inline-block;
        margin-inline-start: 8px;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
        background: #ffedd5;
        color: #9a3412;
    }
    .invoice-workflow-bar__order { font-size: 0.85rem; color: #64748b; }
    .invoice-workflow-bar__actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }
    table.invoice-recent-picker tbody tr {
        cursor: pointer;
    }
    table.invoice-recent-picker tbody tr:focus {
        outline: 2px solid #ea580c;
        outline-offset: -2px;
        background: rgba(234, 88, 12, 0.07);
    }
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
    .invoice-payment-box {
        margin-top: 1.1rem;
        padding: 0.9rem 1rem;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #f8fafc;
        max-width: 420px;
    }
    .invoice-payment-box h3 { margin: 0 0 0.5rem; font-size: 0.95rem; }
    .invoice-payment-box .row-pay { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 8px; }
    .invoice-payment-box input[type="number"] { min-width: 8rem; padding: 8px; border-radius: 6px; border: 1px solid #cbd5e1; }
    @media print {
        .invoice-workflow-bar, .invoice-payment-box, .invoice-actions, .invoice-picker, .page-title, .admin-fy-shell__title, .admin-fy-shell__lead, .admin-header-wrap, .admin-topbar, .admin-nav-backdrop, .admin-nav-drawer, .admin-mega-backdrop, .admin-mega-panel, .admin-user, .brand { display: none !important; }
        .admin-main { margin: 0 !important; padding: 0 !important; }
        body { background: #fff !important; }
        .invoice-doc { box-shadow: none; border: none; max-width: none; }
    }
</style>

<?php if (!$order): ?>
<div class="card admin-fy-card">
    <h3 class="card-title">فتح فاتورة</h3>
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
    <p style="margin:0 0 12px;"><a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=orders'), ENT_QUOTES, 'UTF-8'); ?>">← كل الطلبات</a></p>
    <?php if ($recentForPicker): ?>
    <p class="card-hint" style="margin:0 0 10px;">انقر صفاً داخل الجدول ثم استخدم <kbd class="admin-kbd">↑</kbd> <kbd class="admin-kbd">↓</kbd> للتنقل و <kbd class="admin-kbd">Enter</kbd> لفتح الفاتورة.</p>
    <div class="table-wrap admin-fy-table-wrap">
        <table class="invoice-recent-picker admin-fy-table">
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
                    <td><?php echo $invFmt((float) $r['total']); ?></td>
                    <td><?php
                        $rst = strtolower(trim((string) ($r['status'] ?? '')));
                        echo htmlspecialchars($orderStatusAr[$rst] ?? (string) ($r['status'] ?? ''), ENT_QUOTES, 'UTF-8');
                    ?></td>
                    <td><a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=invoice&order_id=' . (int) $r['id']), ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<script>
(function () {
    var tb = document.querySelector('table.invoice-recent-picker tbody');
    if (!tb) {
        return;
    }
    function rows() {
        return Array.prototype.slice.call(tb.querySelectorAll('tr'));
    }
    rows().forEach(function (tr) {
        tr.setAttribute('tabindex', '-1');
    });
    tb.addEventListener('click', function (e) {
        var tr = orangeAdminClosest(e, 'tr');
        if (tr && tr.parentElement === tb) {
            tr.focus();
        }
    });
    tb.addEventListener('keydown', function (e) {
        var tr = orangeAdminClosest(e, 'tr');
        if (!tr || tr.parentElement !== tb) {
            return;
        }
        var list = rows();
        var i = list.indexOf(tr);
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (i >= 0 && i < list.length - 1) {
                list[i + 1].focus();
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (i > 0) {
                list[i - 1].focus();
            }
        } else if (e.key === 'Enter') {
            var a = tr.querySelector('a[href]');
            if (a) {
                window.location.href = a.getAttribute('href');
            }
        }
    });
})();
</script>
<?php else: ?>
<?php
    $logoSrc = orange_invoice_logo_url((string)($companyProfile['company_logo'] ?? ''));
    $nameAr = trim((string)($companyProfile['company_name_ar'] ?? ''));
    $nameEn = trim((string)($companyProfile['company_name_en'] ?? ''));
    $cr = trim((string)($companyProfile['commercial_register'] ?? ''));
    $phones = trim((string)($companyProfile['phones'] ?? ''));
    $addr = trim((string)($companyProfile['address'] ?? ''));
    $vat = trim((string)($companyProfile['vat_number'] ?? ''));
    $footerLegal = trim((string)($companyProfile['invoice_footer_ar'] ?? ''));
    if ($footerLegal === '') {
        $footerLegal = trim((string)($companyProfile['invoice_footer_en'] ?? ''));
    }
    $formalInv = trim((string)($order['invoice_number'] ?? ''));
    $orderSrc = (string)($order['order_source'] ?? 'website');
    $orderSrcAr = $orderSrc === 'company'
        ? 'طلب مسجّل كمصدر شركة (داخلي)'
        : 'طلب وارد من واجهة المتجر';
    $ost = strtolower(trim((string) ($order['status'] ?? '')));
    $statusLabel = $orderStatusAr[$ost] ?? (string) ($order['status'] ?? '');
    $ptAr = 'بيع ' . orange_order_payment_terms_label_ar($order['payment_terms'] ?? 'cash');
    $copyLabelAr = $invoiceCopy === 'receipt' ? 'نسخة التوقيع بالاستلام' : 'نسخة العميل';
?>

<div class="invoice-workflow-bar" role="region" aria-label="إجراءات الفاتورة">
    <div class="invoice-workflow-bar__meta">
        <div><span class="invoice-workflow-bar__pending"><?php echo htmlspecialchars($copyLabelAr, ENT_QUOTES, 'UTF-8'); ?></span></div>
        <?php if ($formalInv !== ''): ?>
            <div>
                <span class="invoice-workflow-bar__number">رقم الفاتورة: <strong><?php echo htmlspecialchars($formalInv, ENT_QUOTES, 'UTF-8'); ?></strong></span>
                <span class="invoice-workflow-bar__saved">مسلسل محفوظ</span>
            </div>
            <div class="invoice-workflow-bar__order">مرتبط بالطلب: <strong><?php echo htmlspecialchars((string)$order['order_number'], ENT_QUOTES, 'UTF-8'); ?></strong> — لا يتغيّر الرقم بعد التخصيص.</div>
        <?php else: ?>
            <div>
                <?php if ($orderSrc === 'company'): ?>
                <span class="invoice-workflow-bar__number">رقم الفاتورة: <strong style="color:#94a3b8;font-weight:600;">سيُخصَّص <code>INV-C-</code>…</strong></span>
                <span class="invoice-workflow-bar__pending">أول عرض — طلب شركة</span>
                <?php else: ?>
                <span class="invoice-workflow-bar__number">رقم الفاتورة الرسمية: <strong style="color:#94a3b8;font-weight:600;">—</strong></span>
                <span class="invoice-workflow-bar__pending">يُخصَّص <code>INV-O-</code> عند «إنشاء القيود»</span>
                <?php endif; ?>
            </div>
            <div class="invoice-workflow-bar__order">مستند تسليم للطلب <?php echo htmlspecialchars((string)$order['order_number'], ENT_QUOTES, 'UTF-8'); ?> — <?php echo $orderSrc === 'company' ? 'يُخصَّص <code>INV-C-</code> تلقائياً عند أول فتح.' : 'بدون رقم فاتورة رسمية حتى الترحيل المحاسبي.'; ?></div>
        <?php endif; ?>
    </div>
    <div class="invoice-workflow-bar__actions">
        <button type="button" class="btn" onclick="window.print()">طباعة / PDF</button>
        <?php if ($orderId > 0): ?>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=invoice&order_id=' . $orderId . '&copy=customer'), ENT_QUOTES, 'UTF-8'); ?>">نسخة العميل</a>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=invoice&order_id=' . $orderId . '&copy=receipt'), ENT_QUOTES, 'UTF-8'); ?>">نسخة التوقيع</a>
        <?php endif; ?>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=company_sales_invoice'), ENT_QUOTES, 'UTF-8'); ?>">+ فاتورة مبيعات</a>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=orders'), ENT_QUOTES, 'UTF-8'); ?>">الطلبات</a>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=invoice'), ENT_QUOTES, 'UTF-8'); ?>">فاتورة أخرى</a>
    </div>
</div>

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
                <p class="invoice-doc-sub">بيع للشركة (مخزن موحّد) — <?php echo htmlspecialchars($orderSrcAr, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
        <div class="invoice-doc-meta-box">
            <?php if ($formalInv !== ''): ?>
                <div><strong>رقم الفاتورة:</strong> <?php echo htmlspecialchars($formalInv, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php else: ?>
                <div><strong>رقم الفاتورة:</strong> <span style="color:#94a3b8;">يُخصص تلقائياً عند أول عرض</span></div>
            <?php endif; ?>
            <div><strong>رقم الطلب:</strong> <?php echo htmlspecialchars((string)$order['order_number'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div><strong>تاريخ الطلب:</strong> <?php echo htmlspecialchars(orange_format_datetime_dmY_hi((string)($order['created_at'] ?? '')) ?: '—', ENT_QUOTES, 'UTF-8'); ?></div>
            <div><strong>طباعة:</strong> <?php echo htmlspecialchars(orange_format_datetime_dmY_hi(date('Y-m-d H:i:s')), ENT_QUOTES, 'UTF-8'); ?></div>
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

        <?php if ($channelName !== ''): ?>
        <div class="invoice-panel" style="margin-bottom:1rem;background:#f8fafc;border-style:dashed;">
            <h3 style="margin-top:0;">تتبّع مصدر الطلب</h3>
            <p style="margin:0 0 0.65rem;font-size:0.88rem;color:#64748b;line-height:1.5;">القناة هنا لمعرفة أين وصلك العميل (مثل تيك توك أو واتساب) وتنظيم ملفّه — <strong>لا</strong> تعني مخزناً أو بيعاً منفصلاً؛ المخزون الرئيسي للشركة واحد لكل القنوات.</p>
            <div><strong>قناة العملاء:</strong> <?php echo htmlspecialchars($channelName, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php endif; ?>

        <div class="invoice-grid-2">
            <div class="invoice-panel">
                <h3>بيانات العميل</h3>
                <div><strong>الاسم:</strong> <?php echo htmlspecialchars((string)$order['customer_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div><strong>الهاتف:</strong> <?php echo htmlspecialchars((string)$order['phone'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div><strong>المنطقة:</strong> <?php echo htmlspecialchars((string)$order['area'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div><strong>العنوان:</strong> <?php echo htmlspecialchars((string)$order['address'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="invoice-panel">
                <h3>حالة المستند</h3>
                <div><strong>حالة الطلب:</strong> <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                <div><strong>نوع البيع:</strong> <?php echo htmlspecialchars($ptAr, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>

        <?php if ($linesMismatch): ?>
            <div class="invoice-warn">تنبيه: <?php
            if ($cartComboDisc > 0.00001 || $cartPromoDisc > 0.00001) {
                echo 'المتوقع من البنود بعد الخصومات: ' . orange_format_money_for_context($invMoney, $linesSubtotal, false);
                if ($cartComboDisc > 0.00001) {
                    echo ' − كومبو ' . orange_format_money_for_context($invMoney, $cartComboDisc, false);
                }
                if ($cartPromoDisc > 0.00001) {
                    echo ' − عرض السلة ' . orange_format_money_for_context($invMoney, $cartPromoDisc, false);
                }
                echo ' = ' . $invFmt($linesExpectedNet) . ' — ';
            } else {
                echo 'صافي البنود ' . $invFmt($linesSubtotal) . ' — ';
            }
            ?>لا يطابق إجمالي الطلب المحفوظ (<?php echo $invFmt($orderTotalVal); ?>). راجع الطلب أو الخصومات.</div>
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
                    <th class="num">خصم</th>
                    <th class="num">الصافي</th>
                </tr>
            </thead>
            <tbody>
                <?php $ln = 0; foreach ($items as $row): ?>
                    <?php
                    ++$ln;
                    $disc = orange_order_item_line_discount($row);
                    $lineNet = orange_order_item_line_net($row);
                    ?>
                    <tr>
                        <td class="num"><?php echo (int)$ln; ?></td>
                        <td><?php echo htmlspecialchars((string)$row['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['color'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['size'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="num"><?php echo (int)$row['qty']; ?></td>
                        <td class="num"><?php echo $invFmt((float) $row['price']); ?></td>
                        <td class="num"><?php echo $disc > 0.0001 ? $invFmt($disc) : '—'; ?></td>
                        <td class="num"><?php echo $invFmt($lineNet); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($invoicePrintExtras as $exPrint): ?>
                    <tr>
                        <td class="num">+</td>
                        <td colspan="6"><?php echo htmlspecialchars((string) $exPrint['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="num"><?php echo ($exPrint['signed'] < -0.0001 ? '−' : '') . $invFmt(abs((float) $exPrint['signed'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="invoice-totals">
            <div class="invoice-totals-inner">
                <div class="invoice-totals-row">
                    <span>صافي البنود</span>
                    <span><?php echo $invFmt($linesSubtotal); ?></span>
                </div>
                <?php if ($cartComboDisc > 0.00001): ?>
                <div class="invoice-totals-row invoice-totals-row--cart-promo">
                    <span>خصم كومبو</span>
                    <span>−<?php echo $invFmt($cartComboDisc); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($cartPromoDisc > 0.00001): ?>
                <div class="invoice-totals-row invoice-totals-row--cart-promo">
                    <span>خصم عرض السلة</span>
                    <span>−<?php echo $invFmt($cartPromoDisc); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($invoiceExtraPrintNet > 0.0001 || $invoiceExtraPrintNet < -0.0001): ?>
                <div class="invoice-totals-row">
                    <span>بنود إضافية (للعميل)</span>
                    <span><?php echo ($invoiceExtraPrintNet < 0 ? '−' : '') . $invFmt(abs($invoiceExtraPrintNet)); ?></span>
                </div>
                <?php endif; ?>
                <div class="invoice-totals-row grand">
                    <span>إجمالي الفاتورة<?php echo $invoicePrintExtras !== [] ? ' (شامل البنود الإضافية)' : ''; ?></span>
                    <span><?php echo $invFmt($invoicePrintExtras !== [] ? $invoiceCustomerTotal : $orderTotalVal); ?></span>
                </div>
                <?php if (orange_table_has_column($pdo, 'orders', 'amount_paid')): ?>
                <div class="invoice-totals-row">
                    <span>مدفوع</span>
                    <span><?php echo $invFmt($amountPaidVal); ?></span>
                </div>
                <div class="invoice-totals-row grand" style="border-top:1px dashed #cbd5e1;margin-top:0.35rem;padding-top:0.45rem;">
                    <span>الباقي</span>
                    <span><?php echo $invFmt($invoicePrintExtras !== [] ? $invoiceCustomerBalance : $balanceDueVal); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (orange_table_has_column($pdo, 'orders', 'amount_paid')): ?>
        <div class="invoice-payment-box invoice-actions">
            <h3>تحديث المدفوع</h3>
            <p style="margin:0;font-size:0.85rem;color:#64748b;">لتسجيل ما دفعه العميل (لا يغيّر إجمالي الفاتورة).</p>
            <div class="row-pay">
                <label for="inv_amount_paid_inp" style="font-weight:600;">مدفوع (<?php echo htmlspecialchars($invCurUnit, ENT_QUOTES, 'UTF-8'); ?>)</label>
                <input type="number" id="inv_amount_paid_inp" step="<?php echo htmlspecialchars($orangeAdminMoneyStep ?? orange_admin_money_step($pdo), ENT_QUOTES, 'UTF-8'); ?>" min="0" lang="en" dir="ltr"
                    value="<?php echo htmlspecialchars((string) $amountPaidVal, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="button" class="btn-secondary" onclick="invSaveAmountPaid()">حفظ</button>
            </div>
            <p class="card-hint" style="margin:8px 0 0;font-size:0.82rem;">الباقي بعد التعديل: <strong id="inv_balance_live"><?php echo orange_format_money_for_context($invMoney, $balanceDueVal, false); ?></strong> <?php echo htmlspecialchars($invCurUnit, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <script>
        (function () {
            var total = <?php echo json_encode($orderTotalVal); ?>;
            var invDec = <?php echo (int) $invCurDec; ?>;
            var inp = document.getElementById('inv_amount_paid_inp');
            var live = document.getElementById('inv_balance_live');
            function syncLive() {
                if (!inp || !live) return;
                var p = parseFloat(String(inp.value || '0').replace(',', '.')) || 0;
                if (p < 0) p = 0;
                if (p > total) p = total;
                var factor = Math.pow(10, invDec);
                var bal = Math.max(0, Math.round((total - p) * factor) / factor);
                live.textContent = bal.toFixed(invDec);
            }
            if (inp) {
                inp.addEventListener('input', syncLive);
            }
            window.invSaveAmountPaid = function () {
                var p = parseFloat(String((inp && inp.value) || '0').replace(',', '.')) || 0;
                postJSON('/admin/api/orders/update-payment.php', {
                    order_id: <?php echo (int) $orderId; ?>,
                    amount_paid: p
                }).then(function (r) {
                    if (r.success) {
                        alert(r.message || 'تم الحفظ');
                        location.reload();
                        return;
                    }
                    alert(r.message || 'فشل');
                }).catch(function (e) { alert(e.message || String(e)); });
            };
        })();
        </script>
        <?php endif; ?>

        <?php if ($footerLegal !== ''): ?>
            <div class="invoice-footer-legal"><?php echo nl2br(htmlspecialchars($footerLegal, ENT_QUOTES, 'UTF-8')); ?></div>
        <?php endif; ?>

        <?php if ($invoiceCopy === 'receipt'): ?>
        <div class="invoice-panel" style="margin-top:1.5rem;border:2px dashed #64748b;padding:1rem;">
            <h3 style="margin-top:0;">استلام البضاعة</h3>
            <p style="margin:0 0 1rem;font-size:0.9rem;color:#475569;">أقرّ باستلام البنود أعلاه كاملة وبالحالة المذكورة.</p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div><strong>اسم المستلم:</strong> ___________________________</div>
                <div><strong>التاريخ:</strong> ___________________________</div>
            </div>
            <div style="margin-top:1.5rem;"><strong>التوقيع:</strong> ___________________________</div>
        </div>
        <?php endif; ?>

        <div class="invoice-actions">
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=invoice'), ENT_QUOTES, 'UTF-8'); ?>">فاتورة أخرى</a>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=orders'), ENT_QUOTES, 'UTF-8'); ?>">الطلبات</a>
            <button type="button" class="btn" onclick="window.print()">طباعة / PDF</button>
        </div>
    </div>
</div>
<?php endif; ?>

</div>
