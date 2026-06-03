<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/warehouses.php';
require_once __DIR__ . '/../../includes/stock_alerts.php';
require_once __DIR__ . '/../../includes/order_stock.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = orange_admin_page_pdo();
$srCountryId = function_exists('orange_admin_context_country_id') ? (int) orange_admin_context_country_id($pdo) : 0;

$reports = [
    'balances'  => 'أرصدة المخزون',
    'valuation' => 'تقييم المخزون',
    'low'       => 'النواقص (تحت الحد)',
    'movements' => 'حركة المخزون',
    'stagnant'  => 'الأصناف الراكدة',
];
$reportKey = isset($_GET['r']) ? (string) $_GET['r'] : 'balances';
if (!isset($reports[$reportKey])) {
    $reportKey = 'balances';
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$lowTh = orange_stock_low_alert_threshold();

$normalizeDate = static function (string $raw): ?string {
    $raw = trim($raw);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : null;
};
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$mFrom = isset($_GET['m_from']) ? ($normalizeDate((string) $_GET['m_from']) ?? $monthStart) : $monthStart;
$mTo = isset($_GET['m_to']) ? ($normalizeDate((string) $_GET['m_to']) ?? $today) : $today;
if (strcmp($mFrom, $mTo) > 0) {
    [$mFrom, $mTo] = [$mTo, $mFrom];
}
$stagnantDays = isset($_GET['days']) ? max(7, min(3650, (int) $_GET['days'])) : 90;

$company = orange_sales_doc_print_company($pdo, $srCountryId);
$reportFmtMoney = static function (float $v): string {
    return number_format($v, 2, '.', ',');
};
$productCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $srCountryId);
$catJoin = orange_catalog_admin_sql_join_product_category_display($pdo, 'p', null);

$rows = [];
$grandQty = 0;
$grandValue = 0.0;
$reportError = '';

try {
    if ($reportKey === 'balances' || $reportKey === 'valuation') {
        $wq = orange_warehouse_effective_qty_sql($pdo, $srCountryId, 'pv', 'wvs_sr');
        $searchSql = '';
        $params = [];
        if ($q !== '') {
            $searchSql = ' AND (p.name LIKE ? OR p.name_en LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        $sql = 'SELECT p.id AS product_id, p.name AS product_name, p.cost AS cost,
                       COALESCE(c.name_ar, \'\') AS category_name,
                       COALESCE(SUM(' . $wq['expr'] . '), 0) AS total_qty
                FROM products p
                ' . $catJoin . '
                INNER JOIN product_variants pv ON pv.product_id = p.id
                ' . $wq['join'] . '
                WHERE p.is_active = 1' . $productCountrySql . $searchSql . '
                GROUP BY p.id, p.name, p.cost, category_name
                ORDER BY p.name ASC, p.id ASC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $qty = (int) $r['total_qty'];
            $cost = (float) $r['cost'];
            $value = $qty * $cost;
            $grandQty += $qty;
            $grandValue += $value;
            $rows[] = [
                'product_id' => (int) $r['product_id'],
                'product_name' => (string) $r['product_name'],
                'category_name' => (string) $r['category_name'],
                'qty' => $qty,
                'cost' => $cost,
                'value' => $value,
            ];
        }
    } elseif ($reportKey === 'low') {
        $wq = orange_warehouse_effective_qty_sql($pdo, $srCountryId, 'pv', 'wvs_low');
        $sql = 'SELECT p.id AS product_id, p.name AS product_name, pv.id AS variant_id,
                       pv.color, pv.size, ' . $wq['expr'] . ' AS qty
                FROM product_variants pv
                INNER JOIN products p ON p.id = pv.product_id
                ' . $wq['join'] . '
                WHERE p.is_active = 1 AND ' . $wq['expr'] . ' <= ?' . $productCountrySql . '
                ORDER BY ' . $wq['expr'] . ' ASC, p.name ASC, pv.id ASC';
        $st = $pdo->prepare($sql);
        $st->execute([$lowTh]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'product_id' => (int) $r['product_id'],
                'product_name' => (string) $r['product_name'],
                'variant' => trim(((string) ($r['color'] ?? '')) . ' / ' . ((string) ($r['size'] ?? ''))),
                'qty' => (int) $r['qty'],
            ];
        }
    } elseif ($reportKey === 'movements') {
        $mvCountrySql = orange_sql_country_and_fragment($pdo, 'stock_movements', 'sm', $srCountryId);
        $sql = 'SELECT sm.created_at, sm.type, sm.qty, sm.old_stock, sm.new_stock, sm.reference, sm.reason,
                       p.name AS product_name, pv.color, pv.size
                FROM stock_movements sm
                INNER JOIN products p ON p.id = sm.product_id
                LEFT JOIN product_variants pv ON pv.id = sm.variant_id
                WHERE DATE(sm.created_at) BETWEEN ? AND ?' . $mvCountrySql;
        $params = [$mFrom, $mTo];
        if ($q !== '') {
            $sql .= ' AND p.name LIKE ?';
            $params[] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY sm.created_at DESC, sm.id DESC LIMIT 1000';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'created_at' => (string) $r['created_at'],
                'type' => (string) $r['type'],
                'qty' => (int) $r['qty'],
                'old_stock' => (int) $r['old_stock'],
                'new_stock' => (int) $r['new_stock'],
                'reference' => (string) ($r['reference'] ?? ''),
                'reason' => (string) ($r['reason'] ?? ''),
                'product_name' => (string) $r['product_name'],
                'variant' => trim(((string) ($r['color'] ?? '')) . ' / ' . ((string) ($r['size'] ?? ''))),
            ];
        }
    } elseif ($reportKey === 'stagnant') {
        $wq = orange_warehouse_effective_qty_sql($pdo, $srCountryId, 'pv', 'wvs_stag');
        $cutoff = date('Y-m-d', strtotime('-' . $stagnantDays . ' days'));
        $sql = 'SELECT p.id AS product_id, p.name AS product_name, pv.id AS variant_id,
                       pv.color, pv.size, ' . $wq['expr'] . ' AS qty,
                       (SELECT MAX(sm.created_at) FROM stock_movements sm WHERE sm.variant_id = pv.id) AS last_move
                FROM product_variants pv
                INNER JOIN products p ON p.id = pv.product_id
                ' . $wq['join'] . '
                WHERE p.is_active = 1 AND ' . $wq['expr'] . ' > 0' . $productCountrySql . '
                HAVING (last_move IS NULL OR DATE(last_move) < ?)
                ORDER BY last_move ASC, p.name ASC, pv.id ASC';
        $st = $pdo->prepare($sql);
        $st->execute([$cutoff]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'product_id' => (int) $r['product_id'],
                'product_name' => (string) $r['product_name'],
                'variant' => trim(((string) ($r['color'] ?? '')) . ' / ' . ((string) ($r['size'] ?? ''))),
                'qty' => (int) $r['qty'],
                'last_move' => (string) ($r['last_move'] ?? ''),
            ];
        }
    }
} catch (Throwable $e) {
    $reportError = $e->getMessage();
    if (function_exists('error_log')) {
        error_log('[orange stock_reports ' . $reportKey . '] ' . $e->getMessage());
    }
}

$companyName = $company['company_name_ar'];
$companyLogo = $company['logo_url'];
$companyCr = $company['commercial_register'];
$companyFooter = $company['invoice_footer'];
$todayDmY = orange_format_date_dmY($today);
$printDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
$reportTitle = $reports[$reportKey];

?>
<div class="page-title page-title--stacked gl-acc-stmt-no-print">
    <div>
        <h1>تقارير المخزن</h1>
        <p class="page-subtitle">
            <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=stock'), ENT_QUOTES, 'UTF-8'); ?>">← المستودع</a>
        </p>
    </div>
    <div>
        <button type="button" class="btn-secondary" onclick="window.print()">طباعة التقرير</button>
    </div>
</div>

<div class="card gl-acc-stmt-no-print">
    <div class="sr-tabs">
        <?php foreach ($reports as $key => $label): ?>
            <a class="sr-tab<?php echo $key === $reportKey ? ' is-active' : ''; ?>"
               href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=stock_reports&r=' . rawurlencode($key)), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endforeach; ?>
        <a class="sr-tab" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=stock'), ENT_QUOTES, 'UTF-8'); ?>">كارت الصنف (من المستودع)</a>
    </div>

    <form method="get" class="sr-filter-form" style="margin-top:12px;display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
        <input type="hidden" name="page" value="stock_reports">
        <input type="hidden" name="r" value="<?php echo htmlspecialchars($reportKey, ENT_QUOTES, 'UTF-8'); ?>">
        <?php if (in_array($reportKey, ['balances', 'valuation', 'movements'], true)): ?>
            <div>
                <label for="sr_q">بحث باسم الصنف</label>
                <input type="text" id="sr_q" name="q" class="admin-inp" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
            </div>
        <?php endif; ?>
        <?php if ($reportKey === 'movements'): ?>
            <div>
                <label for="sr_from">من تاريخ</label>
                <input type="date" id="sr_from" name="m_from" class="admin-inp" lang="en" dir="ltr" value="<?php echo htmlspecialchars($mFrom, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <label for="sr_to">إلى تاريخ</label>
                <input type="date" id="sr_to" name="m_to" class="admin-inp" lang="en" dir="ltr" value="<?php echo htmlspecialchars($mTo, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        <?php endif; ?>
        <?php if ($reportKey === 'stagnant'): ?>
            <div>
                <label for="sr_days">راكد منذ (يوم) — بلا حركة</label>
                <input type="number" id="sr_days" name="days" class="admin-inp" min="7" max="3650" step="1" lang="en" dir="ltr" value="<?php echo (int) $stagnantDays; ?>">
            </div>
        <?php endif; ?>
        <div><button type="submit">عرض</button></div>
    </form>
</div>

<div class="card sr-print-area gl-acc-stmt-print">
    <div class="gl-acc-stmt-print-sheet ta-report-print-sheet bs-report-sheet">
        <header class="gl-acc-stmt-print-banner bs-report-banner">
            <div class="bs-report-brand">
                <?php if ($companyLogo !== ''): ?>
                    <img class="bs-report-logo" src="<?php echo htmlspecialchars($companyLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                <?php endif; ?>
                <div class="bs-report-brand-text">
                    <?php if ($companyName !== ''): ?>
                        <p class="bs-report-company"><?php echo htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                    <?php if ($companyCr !== ''): ?>
                        <p class="bs-report-cr">سجل تجاري: <span dir="ltr"><?php echo htmlspecialchars($companyCr, ENT_QUOTES, 'UTF-8'); ?></span></p>
                    <?php endif; ?>
                </div>
            </div>
            <h2 class="gl-acc-stmt-print-title bs-report-title">
                <span class="gl-acc-stmt-print-title-ar" lang="ar"><?php echo htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="bs-report-asof" lang="ar">
                    <?php if ($reportKey === 'movements'): ?>
                        من <span dir="ltr"><?php echo htmlspecialchars(orange_format_date_dmY($mFrom), ENT_QUOTES, 'UTF-8'); ?></span>
                        إلى <span dir="ltr"><?php echo htmlspecialchars(orange_format_date_dmY($mTo), ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php else: ?>
                        كما في <span dir="ltr"><?php echo htmlspecialchars($todayDmY, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </span>
            </h2>
        </header>

        <?php if ($reportError !== ''): ?>
            <p class="card-hint" style="color:#b91c1c;">تعذّر توليد التقرير: <?php echo htmlspecialchars($reportError, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap">
            <table class="admin-fy-table gl-acc-stmt-table ta-report-table">
                <?php if ($reportKey === 'balances'): ?>
                    <thead><tr><th>#</th><th>الصنف</th><th>التصنيف</th><th class="gl-acc-stmt-col-num">إجمالي الرصيد</th></tr></thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="4" class="muted">لا أصناف.</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <td dir="ltr"><?php echo (int) $r['product_id']; ?></td>
                                <td><?php echo htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r['category_name'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['qty']; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot><tr><th colspan="3">إجمالي القطع</th><th class="gl-acc-stmt-col-num"><?php echo (int) $grandQty; ?></th></tr></tfoot>
                <?php elseif ($reportKey === 'valuation'): ?>
                    <thead><tr><th>#</th><th>الصنف</th><th class="gl-acc-stmt-col-num">الكمية</th><th class="gl-acc-stmt-col-num">التكلفة</th><th class="gl-acc-stmt-col-num">القيمة</th></tr></thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="5" class="muted">لا أصناف.</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <td dir="ltr"><?php echo (int) $r['product_id']; ?></td>
                                <td><?php echo htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['qty']; ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $reportFmtMoney((float) $r['cost']); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $reportFmtMoney((float) $r['value']); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot><tr><th colspan="2">الإجمالي</th><th class="gl-acc-stmt-col-num"><?php echo (int) $grandQty; ?></th><th class="gl-acc-stmt-col-num">—</th><th class="gl-acc-stmt-col-num"><?php echo $reportFmtMoney($grandValue); ?></th></tr></tfoot>
                <?php elseif ($reportKey === 'low'): ?>
                    <thead><tr><th>#</th><th>الصنف</th><th>اللون / المقاس</th><th class="gl-acc-stmt-col-num">الرصيد</th></tr></thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="4" class="muted">لا نواقص ضمن الحد (<?php echo (int) $lowTh; ?>).</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <td dir="ltr"><?php echo (int) $r['product_id']; ?></td>
                                <td><?php echo htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r['variant'] !== '/' ? $r['variant'] : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['qty']; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                <?php elseif ($reportKey === 'movements'): ?>
                    <thead><tr><th>التاريخ</th><th>الصنف</th><th>لون/مقاس</th><th>النوع</th><th class="gl-acc-stmt-col-num">كمية</th><th>قبل → بعد</th><th>مرجع</th></tr></thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="7" class="muted">لا حركات في المدى.</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(orange_format_datetime_dmY_hi($r['created_at']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r['variant'] !== '/' ? $r['variant'] : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(orange_stock_movement_type_label_ar($r['type']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['qty']; ?></td>
                                <td dir="ltr"><?php echo (int) $r['old_stock']; ?> → <?php echo (int) $r['new_stock']; ?></td>
                                <td dir="ltr"><code><?php echo htmlspecialchars($r['reference'] !== '' ? $r['reference'] : '—', ENT_QUOTES, 'UTF-8'); ?></code></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                <?php elseif ($reportKey === 'stagnant'): ?>
                    <thead><tr><th>#</th><th>الصنف</th><th>اللون / المقاس</th><th class="gl-acc-stmt-col-num">الرصيد</th><th>آخر حركة</th></tr></thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="5" class="muted">لا أصناف راكدة منذ <?php echo (int) $stagnantDays; ?> يوم.</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <td dir="ltr"><?php echo (int) $r['product_id']; ?></td>
                                <td><?php echo htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r['variant'] !== '/' ? $r['variant'] : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['qty']; ?></td>
                                <td><?php echo $r['last_move'] !== '' ? htmlspecialchars(orange_format_date_dmY(substr($r['last_move'], 0, 10)), ENT_QUOTES, 'UTF-8') : 'بلا حركة'; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                <?php endif; ?>
            </table>
        </div>

        <?php if ($companyFooter !== ''): ?>
            <p class="bs-report-legal-footer"><?php echo htmlspecialchars($companyFooter, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <div class="gl-acc-stmt-print-footer ta-report-print-footer">
            <p class="gl-acc-stmt-print-metafoot" dir="ltr">تاريخ ووقت الطباعة: <?php echo htmlspecialchars($printDatetime, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    </div>
</div>

<style>
.sr-tabs { display:flex; flex-wrap:wrap; gap:8px; }
.sr-tab { padding:7px 14px; border:1px solid #cbd5e1; border-radius:8px; background:#f8fafc; color:#334155; text-decoration:none; font-size:0.92rem; }
.sr-tab.is-active { background:#0f172a; color:#fff; border-color:#0f172a; }
</style>
