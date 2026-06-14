<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/order_stock.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/warehouses.php';
require_once __DIR__ . '/../../includes/opening_stock_lock.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/company_settings.php';

$productId = (int)($_GET['product_id'] ?? 0);
if ($productId < 1) {
    echo '<div class="card"><p class="alert-error">صنف غير صالح.</p><a href="' . htmlspecialchars(storefront_public_path('/admin/index.php?page=stock'), ENT_QUOTES, 'UTF-8') . '">العودة للمستودع</a></div>';
    return;
}

$pdo = db();
$icCountryId = orange_admin_context_country_id($pdo);
$openingStockLocked = orange_opening_stock_is_locked($pdo, $icCountryId);

try {
    orange_admin_assert_entity_country($pdo, 'products', $productId);
} catch (RuntimeException $e) {
    echo '<div class="card"><p class="alert-error">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p><a href="' . htmlspecialchars(storefront_public_path('/admin/index.php?page=stock'), ENT_QUOTES, 'UTF-8') . '">العودة للمستودع</a></div>';
    return;
}

$catJoin = orange_catalog_admin_sql_join_product_category_display($pdo, 'p', null);

$stmt = $pdo->prepare("
    SELECT p.*, c.name_ar AS category_name
    FROM products p
    {$catJoin}
    WHERE p.id = ?
    LIMIT 1
");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    echo '<div class="card"><p class="alert-error">الصنف غير موجود.</p><a href="' . htmlspecialchars(storefront_public_path('/admin/index.php?page=stock'), ENT_QUOTES, 'UTF-8') . '">العودة للمستودع</a></div>';
    return;
}

$icCompanyNameAr = orange_company_settings_name_ar($pdo);
$icProductName = (string) ($product['name_ar'] ?? ($product['name'] ?? ''));

$variants = $pdo->prepare("
    SELECT * FROM product_variants WHERE product_id = ? ORDER BY color ASC, size ASC, id ASC
");
$variants->execute([$productId]);
$variants = $variants->fetchAll(PDO::FETCH_ASSOC);
foreach ($variants as &$icVarRow) {
    $icVarRow['stock_quantity'] = orange_warehouse_effective_variant_stock(
        $pdo,
        (int) ($icVarRow['id'] ?? 0),
        $icCountryId
    );
}
unset($icVarRow);

$icTotalStock = 0;
foreach ($variants as $vSum) {
    $icTotalStock += (int) ($vSum['stock_quantity'] ?? 0);
}

$icValidDate = static function (string $raw): string {
    $raw = trim($raw);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : '';
};
$icFrom = isset($_GET['from']) ? $icValidDate((string) $_GET['from']) : '';
$icTo = isset($_GET['to']) ? $icValidDate((string) $_GET['to']) : '';
if ($icFrom !== '' && $icTo !== '' && strcmp($icFrom, $icTo) > 0) {
    [$icFrom, $icTo] = [$icTo, $icFrom];
}
$icHasRange = $icFrom !== '' || $icTo !== '';
$icToday = date('Y-m-d');

/*
 * المخزون يتأثر بمصدرين لا يكتبان سطراً في stock_movements:
 *   الشراء (+) ومردود الشراء (−) — يحدّثان كمية المخزن وطبقات التكلفة فقط.
 * لذلك نجمع الحركات من كل المصادر، ونحسب الرصيد الافتتاحي عكسياً:
 *   الافتتاحي = الرصيد الحالي − صافي كل الحركات التي تاريخها ≥ «من».
 * فيطابق الرصيد الختامي المخزون الفعلي عندما يكون «إلى» = اليوم (أو بلا مدى).
 */
$hasPurCreatedAt = orange_table_has_column($pdo, 'purchases', 'created_at');
$hasPurReturns = orange_table_exists($pdo, 'purchase_returns') && orange_table_exists($pdo, 'purchase_return_items');
$hasPriVariant = $hasPurReturns && orange_table_has_column($pdo, 'purchase_return_items', 'variant_id');
/* الكمية التي تدخل المخزن فعلاً = qty_received عند وجوده (عكس التخفيض يعتمده أيضاً)، وإلا qty. */
$icPuQtyExpr = orange_table_has_column($pdo, 'purchase_items', 'qty_received') ? 'pi.qty_received' : 'pi.qty';

$icDeltaSince = 0;
$smSinceSql = 'SELECT COALESCE(SUM(new_stock - old_stock), 0) FROM stock_movements WHERE product_id = ?';
$smSinceParams = [$productId];
if ($icFrom !== '') {
    $smSinceSql .= ' AND DATE(created_at) >= ?';
    $smSinceParams[] = $icFrom;
}
$icSt = $pdo->prepare($smSinceSql);
$icSt->execute($smSinceParams);
$icDeltaSince += (int) $icSt->fetchColumn();

if ($hasPurCreatedAt) {
    $puSinceSql = 'SELECT COALESCE(SUM(' . $icPuQtyExpr . '), 0) FROM purchase_items pi
        INNER JOIN purchases pu ON pu.id = pi.purchase_id WHERE pi.product_id = ?';
    $puSinceParams = [$productId];
    if ($icFrom !== '') {
        $puSinceSql .= ' AND DATE(pu.created_at) >= ?';
        $puSinceParams[] = $icFrom;
    }
    $icSt = $pdo->prepare($puSinceSql);
    $icSt->execute($puSinceParams);
    $icDeltaSince += (int) $icSt->fetchColumn();
}
if ($hasPurReturns) {
    $prSinceSql = 'SELECT COALESCE(SUM(pri.qty), 0) FROM purchase_return_items pri
        INNER JOIN purchase_returns pr ON pr.id = pri.purchase_return_id WHERE pri.product_id = ?';
    $prSinceParams = [$productId];
    if ($icFrom !== '') {
        $prSinceSql .= ' AND DATE(pr.created_at) >= ?';
        $prSinceParams[] = $icFrom;
    }
    $icSt = $pdo->prepare($prSinceSql);
    $icSt->execute($prSinceParams);
    $icDeltaSince -= (int) $icSt->fetchColumn();
}
$icOpening = $icTotalStock - $icDeltaSince;

/* حركات المدى من كل المصادر، مصنّفة. */
$icEvents = [];

$icMvSql = "SELECT sm.created_at, sm.type, sm.old_stock, sm.new_stock, sm.reference, sm.reason,
        pv.color AS variant_color, pv.size AS variant_size
    FROM stock_movements sm
    LEFT JOIN product_variants pv ON pv.id = sm.variant_id
    WHERE sm.product_id = ?";
$icMvParams = [$productId];
if ($icFrom !== '') {
    $icMvSql .= ' AND DATE(sm.created_at) >= ?';
    $icMvParams[] = $icFrom;
}
if ($icTo !== '') {
    $icMvSql .= ' AND DATE(sm.created_at) <= ?';
    $icMvParams[] = $icTo;
}
$icMvSql .= ' ORDER BY sm.created_at ASC, sm.id ASC LIMIT 2000';
$icMvSt = $pdo->prepare($icMvSql);
$icMvSt->execute($icMvParams);
$icSaleTypes = ['delivered_order', 'pending_order_fulfilled'];
$icSaleReturnTypes = ['order_return', 'delivered_order_void', 'order_amend_release'];
$icReserveTypes = ['pending_order', 'order_release', 'pending_order_void'];
$icAdjustTypes = ['manual_adjustment', 'inventory_count', 'opening_balance'];
foreach ($icMvSt->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $type = (string) $m['type'];
    $delta = (int) $m['new_stock'] - (int) $m['old_stock'];
    if (in_array($type, $icSaleTypes, true)) {
        $cat = 'sale';
    } elseif (in_array($type, $icSaleReturnTypes, true)) {
        $cat = 'sale_return';
    } elseif (in_array($type, $icReserveTypes, true)) {
        $cat = 'reserve';
    } elseif (in_array($type, $icAdjustTypes, true)) {
        $cat = 'adjust';
    } else {
        $cat = 'other';
    }
    $icEvents[] = [
        'at' => (string) $m['created_at'],
        'cat' => $cat,
        'delta' => $delta,
        'label' => orange_stock_movement_type_label_ar($type),
        'reference' => (string) ($m['reference'] ?? ''),
        'reason' => (string) ($m['reason'] ?? ''),
        'variant' => trim(((string) ($m['variant_color'] ?? '')) . ' / ' . ((string) ($m['variant_size'] ?? ''))),
    ];
}

if ($hasPurCreatedAt) {
    $puDetSql = 'SELECT pu.id AS doc_id, pu.created_at, ' . $icPuQtyExpr . ' AS qty, pv.color, pv.size
        FROM purchase_items pi
        INNER JOIN purchases pu ON pu.id = pi.purchase_id
        LEFT JOIN product_variants pv ON pv.id = pi.variant_id
        WHERE pi.product_id = ?';
    $puDetParams = [$productId];
    if ($icFrom !== '') {
        $puDetSql .= ' AND DATE(pu.created_at) >= ?';
        $puDetParams[] = $icFrom;
    }
    if ($icTo !== '') {
        $puDetSql .= ' AND DATE(pu.created_at) <= ?';
        $puDetParams[] = $icTo;
    }
    $icSt = $pdo->prepare($puDetSql);
    $icSt->execute($puDetParams);
    foreach ($icSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $icEvents[] = [
            'at' => (string) $r['created_at'],
            'cat' => 'purchase',
            'delta' => (int) $r['qty'],
            'label' => 'فاتورة شراء',
            'reference' => 'PUR-' . (int) $r['doc_id'],
            'reason' => '',
            'variant' => trim(((string) ($r['color'] ?? '')) . ' / ' . ((string) ($r['size'] ?? ''))),
        ];
    }
}
if ($hasPurReturns) {
    $prDetSql = 'SELECT pr.id AS doc_id, pr.created_at, pri.qty, '
        . ($hasPriVariant ? 'pv.color, pv.size' : 'NULL AS color, NULL AS size') . '
        FROM purchase_return_items pri
        INNER JOIN purchase_returns pr ON pr.id = pri.purchase_return_id
        ' . ($hasPriVariant ? 'LEFT JOIN product_variants pv ON pv.id = pri.variant_id' : '') . '
        WHERE pri.product_id = ?';
    $prDetParams = [$productId];
    if ($icFrom !== '') {
        $prDetSql .= ' AND DATE(pr.created_at) >= ?';
        $prDetParams[] = $icFrom;
    }
    if ($icTo !== '') {
        $prDetSql .= ' AND DATE(pr.created_at) <= ?';
        $prDetParams[] = $icTo;
    }
    $icSt = $pdo->prepare($prDetSql);
    $icSt->execute($prDetParams);
    foreach ($icSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $icEvents[] = [
            'at' => (string) $r['created_at'],
            'cat' => 'purchase_return',
            'delta' => -((int) $r['qty']),
            'label' => 'مردود مشتريات',
            'reference' => 'PRET-' . (int) $r['doc_id'],
            'reason' => '',
            'variant' => trim(((string) ($r['color'] ?? '')) . ' / ' . ((string) ($r['size'] ?? ''))),
        ];
    }
}

usort($icEvents, static function (array $a, array $b): int {
    return strcmp((string) $a['at'], (string) $b['at']);
});

$icSummary = [
    'purchase' => 0, 'purchase_return' => 0, 'sale' => 0,
    'sale_return' => 0, 'adjust' => 0, 'reserve' => 0, 'other' => 0,
];
$icPeriodIn = 0;
$icPeriodOut = 0;
$icRunning = $icOpening;
foreach ($icEvents as $idx => $ev) {
    $d = (int) $ev['delta'];
    $icRunning += $d;
    $icSummary[$ev['cat']] += $d;
    if ($d > 0) {
        $icPeriodIn += $d;
    } elseif ($d < 0) {
        $icPeriodOut += -$d;
    }
    $icEvents[$idx]['balance'] = $icRunning;
}
$icPeriodNet = $icPeriodIn - $icPeriodOut;
$icClosing = $icRunning;
$icCloseIsNow = ($icTo === '' || $icTo === $icToday);
$icReconcileDiff = $icClosing - $icTotalStock;

$img = storefront_product_image_href((string) ($product['main_image'] ?? ''));

$icCompany = orange_sales_doc_print_company($pdo, $icCountryId);
$icCompanyName = $icCompany['company_name_ar'];
$icCompanyLogo = $icCompany['logo_url'];
$icCompanyCr = $icCompany['commercial_register'];
$icCompanyFooter = $icCompany['invoice_footer'];
$icPrintDate = orange_format_date_dmY(date('Y-m-d'));
?>
<div class="page-title gl-acc-stmt-no-print">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1>كارت الصنف</h1>
            <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <div class="ic-print-actions">
            <button type="button" class="btn-secondary" onclick="window.print()">طباعة كارت الصنف</button>
        </div>
    </div>
</div>
<p class="page-subtitle">
    <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=stock#balances'), ENT_QUOTES, 'UTF-8'); ?>">← المستودع</a>
    &nbsp;·&nbsp;
    <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=products'), ENT_QUOTES, 'UTF-8'); ?>">المنتجات</a>
    &nbsp;·&nbsp;
    <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=stock_reports'), ENT_QUOTES, 'UTF-8'); ?>">تقارير المخزن</a>
</p>

<div class="ic-print-area">
<div class="card item-card-print-sheet">
    <header class="gl-acc-stmt-print-banner bs-report-banner">
        <div class="bs-report-brand">
            <?php if ($icCompanyLogo !== ''): ?>
                <img class="bs-report-logo" src="<?php echo htmlspecialchars($icCompanyLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="">
            <?php endif; ?>
            <div class="bs-report-brand-text">
                <?php if ($icCompanyName !== ''): ?>
                    <p class="bs-report-company"><?php echo htmlspecialchars($icCompanyName, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <?php if ($icCompanyCr !== ''): ?>
                    <p class="bs-report-cr">سجل تجاري: <span dir="ltr"><?php echo htmlspecialchars($icCompanyCr, ENT_QUOTES, 'UTF-8'); ?></span></p>
                <?php endif; ?>
            </div>
        </div>
        <h2 class="gl-acc-stmt-print-title bs-report-title">
            <span class="gl-acc-stmt-print-title-ar" lang="ar">كارت صنف</span>
            <span class="bs-report-asof" lang="ar">بتاريخ <span dir="ltr"><?php echo htmlspecialchars($icPrintDate, ENT_QUOTES, 'UTF-8'); ?></span></span>
        </h2>
    </header>
    <p class="muted" style="margin:0;font-size:0.9rem;">
        <strong>الصنف:</strong> <?php echo htmlspecialchars((string) $product['name'], ENT_QUOTES, 'UTF-8'); ?>
        &nbsp;|&nbsp; <strong>رقم:</strong> <span dir="ltr"><?php echo (int) $product['id']; ?></span>
        &nbsp;|&nbsp; <strong>إجمالي الرصيد:</strong> <?php echo (int) $icTotalStock; ?>
    </p>
</div>

<div class="card item-card-header">
    <div class="item-card-main">
        <?php if ($img !== ''): ?>
            <div class="item-card-image"><img src="<?php echo htmlspecialchars($img); ?>" alt=""></div>
        <?php endif; ?>
        <div>
            <h2 class="item-card-title"><?php echo htmlspecialchars($product['name']); ?></h2>
            <?php if (!empty($product['name_en'])): ?>
                <p class="muted"><?php echo htmlspecialchars($product['name_en']); ?></p>
            <?php endif; ?>
            <p><strong>التصنيف:</strong> <?php echo htmlspecialchars($product['category_name'] ?: '—'); ?></p>
            <p><strong>السعر:</strong> <?php echo htmlspecialchars(number_format((float)$product['price'], 2)); ?>
                &nbsp;|&nbsp; <strong>التكلفة:</strong> <?php echo htmlspecialchars(number_format((float)$product['cost'], 2)); ?></p>
            <p><strong>رقم المنتج:</strong> <?php echo (int)$product['id']; ?></p>
        </div>
    </div>
</div>

<div class="card" id="variants">
    <h2 class="card-title">المتغيرات والرصيد</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th># متغير</th>
                    <th>اللون</th>
                    <th>المقاس</th>
                    <th>الرصيد</th>
                    <th>رصيد جديد</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($variants as $v): ?>
                <tr>
                    <td><?php echo (int)$v['id']; ?></td>
                    <td><?php echo htmlspecialchars($v['color'] ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars($v['size'] ?: '—'); ?></td>
                    <td><?php echo (int)$v['stock_quantity']; ?></td>
                    <td>
                        <input type="number" min="0" step="1" class="input-stock admin-inp-qty" inputmode="numeric" lang="en" dir="ltr" id="card_stock_<?php echo (int)$v['id']; ?>" value="<?php echo (int)$v['stock_quantity']; ?>">
                    </td>
                    <td class="stock-actions">
                        <button type="button" class="btn btn-secondary" onclick="cardAdjustStock(<?php echo (int)$v['id']; ?>, 'manual_adjustment')">تعديل رصيد</button>
                        <?php if (!$openingStockLocked): ?>
                        <button type="button" class="btn btn-outline" onclick="cardAdjustStock(<?php echo (int)$v['id']; ?>, 'opening_balance')">رصيد افتتاحي</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card gl-acc-stmt-no-print">
    <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
        <input type="hidden" name="page" value="item_card">
        <input type="hidden" name="product_id" value="<?php echo (int) $productId; ?>">
        <div>
            <label for="ic_from">من تاريخ</label>
            <input type="date" id="ic_from" name="from" class="admin-inp" lang="en" dir="ltr" value="<?php echo htmlspecialchars($icFrom, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div>
            <label for="ic_to">إلى تاريخ</label>
            <input type="date" id="ic_to" name="to" class="admin-inp" lang="en" dir="ltr" value="<?php echo htmlspecialchars($icTo, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div><button type="submit">عرض الفترة</button></div>
        <?php if ($icHasRange): ?>
            <div><a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=item_card&product_id=' . (int) $productId), ENT_QUOTES, 'UTF-8'); ?>">كل الحركات</a></div>
        <?php endif; ?>
    </form>
</div>

<style>
.ic-summary { display:flex; flex-wrap:wrap; gap:10px; margin:4px 0 12px; }
.ic-box { min-width:8rem; flex:1 1 8rem; border:1px solid #e2e8f0; border-radius:10px; background:#f8fafc; padding:9px 12px; display:flex; flex-direction:column; gap:3px; }
.ic-box-label { font-size:0.8rem; color:#64748b; }
.ic-box-val { font-size:1.18rem; font-weight:700; color:#0f172a; }
.ic-box-open { background:#eff6ff; border-color:#bfdbfe; }
.ic-box-close { background:#f0fdf4; border-color:#bbf7d0; }
.ic-box-val.ic-pos { color:#15803d; }
.ic-box-val.ic-neg { color:#b91c1c; }
</style>
<div class="card">
    <h2 class="card-title"><?php echo $icHasRange ? 'كرت حركة الصنف خلال الفترة' : 'كرت حركة الصنف (كل الحركات)'; ?></h2>
    <div class="ic-summary">
        <div class="ic-box ic-box-open">
            <span class="ic-box-label">الرصيد الافتتاحي<?php echo $icFrom !== '' ? ' (' . htmlspecialchars(orange_format_date_dmY($icFrom), ENT_QUOTES, 'UTF-8') . ')' : ''; ?></span>
            <span class="ic-box-val" dir="ltr"><?php echo (int) $icOpening; ?></span>
        </div>
        <div class="ic-box"><span class="ic-box-label">مشتريات (+)</span><span class="ic-box-val ic-pos" dir="ltr"><?php echo (int) $icSummary['purchase']; ?></span></div>
        <div class="ic-box"><span class="ic-box-label">مردود مشتريات (−)</span><span class="ic-box-val ic-neg" dir="ltr"><?php echo (int) $icSummary['purchase_return']; ?></span></div>
        <div class="ic-box"><span class="ic-box-label">مبيعات (−)</span><span class="ic-box-val ic-neg" dir="ltr"><?php echo (int) $icSummary['sale']; ?></span></div>
        <div class="ic-box"><span class="ic-box-label">مردود مبيعات (+)</span><span class="ic-box-val ic-pos" dir="ltr"><?php echo (int) $icSummary['sale_return']; ?></span></div>
        <?php if ((int) $icSummary['adjust'] !== 0): ?>
            <div class="ic-box"><span class="ic-box-label">تعديل/تسوية (±)</span><span class="ic-box-val" dir="ltr"><?php echo (int) $icSummary['adjust']; ?></span></div>
        <?php endif; ?>
        <?php if ((int) $icSummary['reserve'] !== 0): ?>
            <div class="ic-box"><span class="ic-box-label">حجز/إفراج (±)</span><span class="ic-box-val" dir="ltr"><?php echo (int) $icSummary['reserve']; ?></span></div>
        <?php endif; ?>
        <?php if ((int) $icSummary['other'] !== 0): ?>
            <div class="ic-box"><span class="ic-box-label">حركات أخرى (±)</span><span class="ic-box-val" dir="ltr"><?php echo (int) $icSummary['other']; ?></span></div>
        <?php endif; ?>
        <div class="ic-box ic-box-close">
            <span class="ic-box-label">الرصيد الختامي<?php echo $icTo !== '' ? ' (' . htmlspecialchars(orange_format_date_dmY($icTo), ENT_QUOTES, 'UTF-8') . ')' : ''; ?></span>
            <span class="ic-box-val" dir="ltr"><?php echo (int) $icClosing; ?></span>
        </div>
    </div>
    <p class="page-subtitle gl-acc-stmt-no-print" style="margin:0 0 10px;">
        الرصيد الفعلي بالمخزن الآن: <strong dir="ltr"><?php echo (int) $icTotalStock; ?></strong>
        <?php if ($icCloseIsNow): ?>
            <?php if ($icReconcileDiff === 0): ?>
                <span style="color:#15803d;font-weight:700;">— مطابق للرصيد الختامي ✓</span>
            <?php else: ?>
                <span style="color:#b91c1c;font-weight:700;">— فرق <span dir="ltr"><?php echo (int) $icReconcileDiff; ?></span></span>
            <?php endif; ?>
        <?php else: ?>
            <span class="muted">(الرصيد الختامي محسوب حتى نهاية المدى المحدد)</span>
        <?php endif; ?>
    </p>
    <div class="table-wrap">
        <table data-export-name="<?php echo htmlspecialchars('كارت الصنف - ' . $icProductName, ENT_QUOTES, 'UTF-8'); ?>" data-export-target=".ic-print-actions" data-export-company="<?php echo htmlspecialchars($icCompanyNameAr, ENT_QUOTES, 'UTF-8'); ?>">
            <thead>
                <tr>
                    <th>التاريخ</th>
                    <th>الحركة</th>
                    <th>لون/مقاس</th>
                    <th>وارد</th>
                    <th>صادر</th>
                    <th>الرصيد</th>
                    <th>مرجع</th>
                    <th>السبب</th>
                </tr>
            </thead>
            <tbody>
                <tr style="background:#eff6ff;font-weight:700;">
                    <td colspan="5">رصيد بداية المدة<?php echo $icFrom !== '' ? ' (' . htmlspecialchars(orange_format_date_dmY($icFrom), ENT_QUOTES, 'UTF-8') . ')' : ''; ?></td>
                    <td dir="ltr"><?php echo (int) $icOpening; ?></td>
                    <td>—</td>
                    <td>—</td>
                </tr>
                <?php foreach ($icEvents as $ev): ?>
                <?php $d = (int) $ev['delta']; $vlbl = trim((string) $ev['variant']); ?>
                <tr>
                    <td><?php echo htmlspecialchars(orange_format_datetime_dmY_hi((string) $ev['at'])); ?></td>
                    <td><?php echo htmlspecialchars((string) $ev['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(($vlbl !== '' && $vlbl !== '/') ? $vlbl : '—'); ?></td>
                    <td dir="ltr"><?php echo $d > 0 ? (int) $d : ''; ?></td>
                    <td dir="ltr"><?php echo $d < 0 ? (int) (-$d) : ''; ?></td>
                    <td dir="ltr"><strong><?php echo (int) $ev['balance']; ?></strong></td>
                    <td dir="ltr"><code><?php echo htmlspecialchars((string) $ev['reference'] !== '' ? (string) $ev['reference'] : '—'); ?></code></td>
                    <td><?php echo htmlspecialchars((string) $ev['reason']); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:#f0fdf4;font-weight:700;">
                    <td colspan="5">رصيد نهاية المدة<?php echo $icTo !== '' ? ' (' . htmlspecialchars(orange_format_date_dmY($icTo), ENT_QUOTES, 'UTF-8') . ')' : ''; ?></td>
                    <td dir="ltr"><?php echo (int) $icClosing; ?></td>
                    <td>—</td>
                    <td>—</td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php if ($icEvents === []): ?>
        <p class="muted">لا توجد حركات على الصنف في المدى المحدد.</p>
    <?php endif; ?>
</div>

<?php if ($icCompanyFooter !== ''): ?>
<div class="card item-card-legal-print">
    <p class="bs-report-legal-footer" style="border-top:none;margin-top:0;"><?php echo htmlspecialchars($icCompanyFooter, ENT_QUOTES, 'UTF-8'); ?></p>
</div>
<?php endif; ?>
</div>

<script>
async function cardAdjustStock(variantId, movementType) {
    const el = document.getElementById('card_stock_' + variantId);
    const stock = parseInt(el.value || '0', 10);
    const label = movementType === 'opening_balance' ? 'تسجيل الرصيد الافتتاحي؟' : 'حفظ تعديل المخزون؟';
    if (!confirm(label)) return;
    const res = await postJSON('/admin/api/stock/adjust.php', {
        variant_id: variantId,
        stock: stock,
        movement_type: movementType
    });
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) location.reload();
}
</script>
