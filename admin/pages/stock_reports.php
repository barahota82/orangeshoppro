<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/warehouses.php';
require_once __DIR__ . '/../../includes/stock_alerts.php';
require_once __DIR__ . '/../../includes/order_stock.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/cart_promo_products.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/accounting_report_money.php';
require_once __DIR__ . '/../../includes/inventory_cost_layers.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = orange_admin_page_pdo();
$srCountryId = function_exists('orange_admin_context_country_id') ? (int) orange_admin_context_country_id($pdo) : 0;
$companyNameAr = orange_company_settings_name_ar($pdo);

$reports = [
    'balances'    => 'الجرد (أرصدة المخزون)',
    'valuation'   => 'تقييم المخزون',
    'low'         => 'النواقص (تحت الحد)',
    'movements'   => 'حركة المخزون',
    'move_summary'=> 'ملخص الحركة (وارد/صادر)',
    'stagnant'    => 'الأصناف الراكدة',
];
$reportKey = isset($_GET['r']) ? (string) $_GET['r'] : 'balances';
if (!isset($reports[$reportKey])) {
    $reportKey = 'balances';
}

$pid = isset($_GET['pid']) ? max(0, (int) $_GET['pid']) : 0;
$pidLabel = '';
if ($pid > 0) {
    $pl = $pdo->prepare('SELECT name FROM products WHERE id = ? LIMIT 1');
    $pl->execute([$pid]);
    $pidLabel = (string) ($pl->fetchColumn() ?: '');
    if ($pidLabel === '') {
        $pid = 0;
    }
}
$lowTh = orange_stock_low_alert_threshold();
$srPickRows = orange_cart_promo_admin_product_rows($pdo);
$srPickJson = json_encode($srPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);

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

/* فلتر الفئة (catalog_categories) للجرد/التقييم. */
$catId = isset($_GET['cat']) ? max(0, (int) $_GET['cat']) : 0;
$catOptions = [];
try {
    if (orange_table_exists($pdo, 'catalog_categories')) {
        $catOptions = $pdo->query(
            'SELECT id, name_ar FROM catalog_categories WHERE is_active = 1 ORDER BY sort_order ASC, name_ar ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $catOptions = [];
}

$hasItemCode = orange_table_has_column($pdo, 'products', 'item_code');
$canLastSupplier = orange_table_exists($pdo, 'purchases')
    && orange_table_exists($pdo, 'purchase_items')
    && orange_table_exists($pdo, 'suppliers')
    && orange_table_has_column($pdo, 'purchases', 'supplier_id')
    && orange_table_has_column($pdo, 'purchase_items', 'product_id');

$company = orange_sales_doc_print_company($pdo, $srCountryId);
$reportFmtMoney = static function (float $v): string {
    return number_format($v, 2, '.', ',');
};
$productCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $srCountryId);
$catJoin = orange_catalog_admin_sql_join_product_category_display($pdo, 'p', null);
$itemCodeExpr = $hasItemCode ? "COALESCE(NULLIF(TRIM(p.item_code), ''), CONCAT('P', p.id))" : "CONCAT('P', p.id)";

$rows = [];
$grandQty = 0;
$grandValue = 0.0;
$grandValuePrev = 0.0;
$valGroups = [];
$valShowPrev = false;
$valPrevEnd = '';
$reportError = '';

try {
    if ($reportKey === 'balances') {
        /* الجرد: سطر لكل متغير (لون/مقاس) + كود + تكلفة + قيمة. */
        $wq = orange_warehouse_effective_qty_sql($pdo, $srCountryId, 'pv', 'wvs_sr');
        $filterSql = '';
        $params = [];
        if ($pid > 0) {
            $filterSql .= ' AND p.id = ?';
            $params[] = $pid;
        }
        if ($catId > 0) {
            $filterSql .= ' AND c.id = ?';
            $params[] = $catId;
        }
        $sql = 'SELECT p.id AS product_id, p.name AS product_name, p.cost AS cost,
                       ' . $itemCodeExpr . ' AS item_code,
                       COALESCE(c.name_ar, \'\') AS category_name,
                       pv.color, pv.size, ' . $wq['expr'] . ' AS qty
                FROM products p
                ' . $catJoin . '
                INNER JOIN product_variants pv ON pv.product_id = p.id
                ' . $wq['join'] . '
                WHERE p.is_active = 1' . $productCountrySql . $filterSql . '
                ORDER BY p.name ASC, p.id ASC, pv.color ASC, pv.size ASC, pv.id ASC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $qty = (int) $r['qty'];
            $cost = (float) $r['cost'];
            $value = $qty * $cost;
            $grandQty += $qty;
            $grandValue += $value;
            $rows[] = [
                'item_code' => (string) $r['item_code'],
                'product_name' => (string) $r['product_name'],
                'category_name' => (string) $r['category_name'],
                'variant' => trim(((string) ($r['color'] ?? '')) . ' / ' . ((string) ($r['size'] ?? ''))),
                'qty' => $qty,
                'cost' => $cost,
                'value' => $value,
            ];
        }
    } elseif ($reportKey === 'valuation') {
        /* تقييم: لكل صنف (كمية × تكلفة) مُجمّعاً حسب الفئة + مقارنة نهاية السنة المالية السابقة. */
        $wq = orange_warehouse_effective_qty_sql($pdo, $srCountryId, 'pv', 'wvs_sr');

        /* نهاية السنة المالية السابقة للمقارنة (qty من حركات المخزون). */
        $valYears = orange_fiscal_years_list($pdo);
        $valCurStart = '';
        foreach ($valYears as $vy) {
            $s = (string) ($vy['start_date'] ?? '');
            $e = (string) ($vy['end_date'] ?? '');
            if ($s !== '' && $e !== '' && strcmp($s, $today) <= 0 && strcmp($today, $e) <= 0) {
                $valCurStart = $s;
                break;
            }
        }
        if ($valCurStart === '' && $valYears !== []) {
            $valCurStart = (string) ($valYears[0]['start_date'] ?? '');
        }
        $valPrevEnd = '';
        foreach ($valYears as $vy) {
            $s = (string) ($vy['start_date'] ?? '');
            if ($valCurStart !== '' && $s !== '' && strcmp($s, $valCurStart) < 0) {
                $e = (string) ($vy['end_date'] ?? '');
                if ($e !== '' && strcmp($e, $valPrevEnd) > 0) {
                    $valPrevEnd = $e;
                }
            }
        }
        $valShowPrev = $valPrevEnd !== '';

        $filterSql = '';
        $params = [];
        $prevExpr = '0';
        if ($valShowPrev) {
            /* رصيد المتغير كما في نهاية السنة السابقة = new_stock لآخر حركة ≤ ذلك التاريخ. */
            $prevExpr = 'COALESCE((SELECT sm_v.new_stock FROM stock_movements sm_v
                          WHERE sm_v.variant_id = pv.id AND DATE(sm_v.created_at) <= ?
                          ORDER BY sm_v.created_at DESC, sm_v.id DESC LIMIT 1), 0)';
            $params[] = $valPrevEnd;
        }
        if ($pid > 0) {
            $filterSql .= ' AND p.id = ?';
            $params[] = $pid;
        }
        if ($catId > 0) {
            $filterSql .= ' AND c.id = ?';
            $params[] = $catId;
        }
        $sql = 'SELECT p.id AS product_id, p.name AS product_name, p.cost AS cost,
                       ' . $itemCodeExpr . ' AS item_code,
                       COALESCE(c.name_ar, \'\') AS category_name,
                       COALESCE(SUM(' . $wq['expr'] . '), 0) AS total_qty,
                       COALESCE(SUM(' . $prevExpr . '), 0) AS prev_qty
                FROM products p
                ' . $catJoin . '
                INNER JOIN product_variants pv ON pv.product_id = p.id
                ' . $wq['join'] . '
                WHERE p.is_active = 1' . $productCountrySql . $filterSql . '
                GROUP BY p.id, p.name, p.cost, item_code, category_name
                ORDER BY category_name ASC, p.name ASC, p.id ASC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        /* FIFO م4: قيمة التقييم لكل منتج = Σ(qty_remaining × unit_cost) من طبقات التكلفة
           لمخزن الدولة الافتراضي (مصدر الحقيقة)، مع احتياطي qty × products.cost عند غياب طبقات. */
        $valWarehouseId = orange_warehouse_default_id_for_country($pdo, $srCountryId);
        $fifoValueByProduct = [];
        if ($valWarehouseId > 0 && orange_inventory_cost_layers_table_exists($pdo)) {
            $stFifo = $pdo->prepare(
                'SELECT pv.product_id AS pid,
                        COALESCE(SUM(icl.qty_remaining * icl.unit_cost), 0) AS val
                 FROM inventory_cost_layers icl
                 INNER JOIN product_variants pv ON pv.id = icl.variant_id
                 WHERE icl.qty_remaining > 0 AND icl.warehouse_id = ?
                 GROUP BY pv.product_id'
            );
            $stFifo->execute([$valWarehouseId]);
            foreach ($stFifo->fetchAll(PDO::FETCH_ASSOC) as $fr) {
                $fifoValueByProduct[(int) $fr['pid']] = round((float) $fr['val'], 4);
            }
        }
        $valGlCheck = orange_inventory_cost_layers_gl_balance_check($pdo, $srCountryId);
        $valGroups = [];
        $grandValuePrev = 0.0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $qty = (int) $r['total_qty'];
            $cardCost = (float) $r['cost'];
            $pidRow = (int) $r['product_id'];
            $value = array_key_exists($pidRow, $fifoValueByProduct)
                ? $fifoValueByProduct[$pidRow]
                : round($qty * $cardCost, 4);
            $cost = $qty > 0 ? round($value / $qty, 4) : $cardCost;
            $prevQty = (int) $r['prev_qty'];
            $valuePrev = $prevQty * $cost;
            $grandQty += $qty;
            $grandValue += $value;
            $grandValuePrev += $valuePrev;
            $catName = trim((string) $r['category_name']) !== '' ? (string) $r['category_name'] : '—';
            if (!isset($valGroups[$catName])) {
                $valGroups[$catName] = ['rows' => [], 'sub_value' => 0.0, 'sub_value_prev' => 0.0];
            }
            $valGroups[$catName]['rows'][] = [
                'item_code' => (string) $r['item_code'],
                'product_name' => (string) $r['product_name'],
                'qty' => $qty,
                'cost' => $cost,
                'value' => $value,
                'value_prev' => $valuePrev,
            ];
            $valGroups[$catName]['sub_value'] += $value;
            $valGroups[$catName]['sub_value_prev'] += $valuePrev;
        }
    } elseif ($reportKey === 'low') {
        $wq = orange_warehouse_effective_qty_sql($pdo, $srCountryId, 'pv', 'wvs_low');
        $lastSupplierSelect = $canLastSupplier
            ? ", (SELECT s.name FROM purchase_items pi2
                    INNER JOIN purchases pu2 ON pu2.id = pi2.purchase_id
                    INNER JOIN suppliers s ON s.id = pu2.supplier_id
                    WHERE pi2.product_id = p.id
                    ORDER BY pu2.id DESC LIMIT 1) AS last_supplier"
            : ', NULL AS last_supplier';
        $sql = 'SELECT p.id AS product_id, p.name AS product_name, pv.id AS variant_id,
                       pv.color, pv.size, ' . $wq['expr'] . ' AS qty' . $lastSupplierSelect . '
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
                'last_supplier' => trim((string) ($r['last_supplier'] ?? '')),
            ];
        }
    } elseif ($reportKey === 'move_summary') {
        /* ملخص الحركة لكل صنف في المدى: وارد (+) / صادر (−) / صافي من فرق الرصيد. */
        $mvCountrySql = orange_sql_country_and_fragment($pdo, 'stock_movements', 'sm', $srCountryId);
        $filterSql = '';
        $params = [$mFrom, $mTo];
        if ($pid > 0) {
            $filterSql = ' AND sm.product_id = ?';
            $params[] = $pid;
        }
        $sql = 'SELECT p.id AS product_id, p.name AS product_name,
                       SUM(CASE WHEN (sm.new_stock - sm.old_stock) > 0 THEN (sm.new_stock - sm.old_stock) ELSE 0 END) AS qin,
                       SUM(CASE WHEN (sm.new_stock - sm.old_stock) < 0 THEN (sm.old_stock - sm.new_stock) ELSE 0 END) AS qout
                FROM stock_movements sm
                INNER JOIN products p ON p.id = sm.product_id
                WHERE DATE(sm.created_at) BETWEEN ? AND ?' . $mvCountrySql . $filterSql . '
                GROUP BY p.id, p.name
                HAVING qin <> 0 OR qout <> 0
                ORDER BY p.name ASC, p.id ASC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $qin = (int) $r['qin'];
            $qout = (int) $r['qout'];
            $rows[] = [
                'product_id' => (int) $r['product_id'],
                'product_name' => (string) $r['product_name'],
                'qin' => $qin,
                'qout' => $qout,
                'net' => $qin - $qout,
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
        if ($pid > 0) {
            $sql .= ' AND sm.product_id = ?';
            $params[] = $pid;
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
<div class="page-title gl-acc-stmt-no-print">
    <h1>تقارير المخزن</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<div class="card gl-acc-stmt-no-print">
    <div class="sr-tabs">
        <?php foreach ($reports as $key => $label): ?>
            <a class="sr-tab<?php echo $key === $reportKey ? ' is-active' : ''; ?>"
               href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=stock_reports&r=' . rawurlencode($key)), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="sr-filter-form" style="margin-top:12px;display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
        <input type="hidden" name="page" value="stock_reports">
        <input type="hidden" name="r" value="<?php echo htmlspecialchars($reportKey, ENT_QUOTES, 'UTF-8'); ?>">
        <?php if (in_array($reportKey, ['balances', 'valuation', 'movements', 'move_summary'], true)): ?>
            <div>
                <label for="sr_pid_label">الصنف (دبل كليك للاختيار)</label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="hidden" id="sr_pid" name="pid" value="<?php echo (int) $pid; ?>">
                    <input type="text" id="sr_pid_label" class="admin-inp" readonly
                        title="دبل كليك لاختيار صنف"
                        style="cursor:pointer;min-width:16rem;"
                        placeholder="كل الأصناف — دبل كليك للاختيار"
                        value="<?php echo htmlspecialchars($pidLabel, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="button" class="btn-secondary" id="sr_pick_btn">اختيار صنف</button>
                    <?php if ($pid > 0): ?>
                        <button type="button" class="btn-secondary" id="sr_pick_clear">الكل</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if (in_array($reportKey, ['balances', 'valuation'], true) && $catOptions !== []): ?>
            <div>
                <label for="sr_cat">الفئة</label>
                <select id="sr_cat" name="cat" class="admin-inp">
                    <option value="0">كل الفئات</option>
                    <?php foreach ($catOptions as $co): ?>
                        <option value="<?php echo (int) $co['id']; ?>" <?php echo ((int) $co['id'] === $catId) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) ($co['name_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <?php if (in_array($reportKey, ['movements', 'move_summary'], true)): ?>
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
        <div class="sr-print-actions" style="display:flex;gap:8px;align-items:center;margin-inline-start:auto;">
            <button type="button" class="btn-secondary" onclick="window.print()">طباعة التقرير</button>
        </div>
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

        <?php if ($reportKey === 'valuation' && isset($valGlCheck) && is_array($valGlCheck)): ?>
            <?php $valGlDiff = (float) ($valGlCheck['diff'] ?? 0); $valGlOk = abs($valGlDiff) < 0.01; ?>
            <style>@media print{.sr-print-hide{display:none !important;}}</style>
            <div class="sr-print-hide" style="margin:8px 0;padding:10px 14px;border-radius:8px;border:1px solid <?php echo $valGlOk ? '#bbf7d0' : '#fecaca'; ?>;background:<?php echo $valGlOk ? '#f0fdf4' : '#fef2f2'; ?>;font-size:13px;">
                <strong>اختبار اتزان قيمة المخزون مقابل حساب المخزون (GL):</strong>
                قيمة الطبقات (FIFO): <span dir="ltr"><?php echo $reportFmtMoney((float) ($valGlCheck['layers_value'] ?? 0)); ?></span>
                &nbsp;•&nbsp; رصيد حساب المخزون GL: <span dir="ltr"><?php echo $reportFmtMoney((float) ($valGlCheck['gl_balance'] ?? 0)); ?></span>
                &nbsp;•&nbsp; الفرق: <span dir="ltr"><?php echo $reportFmtMoney($valGlDiff); ?></span>
                <?php if ($valGlOk): ?>
                    <span style="color:#15803d;font-weight:700;">— متّزن ✓</span>
                <?php else: ?>
                    <span style="color:#b91c1c;font-weight:700;">— يوجد فرق (راجع القيود المعلّقة/الافتتاحي)</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap">
            <table class="admin-fy-table gl-acc-stmt-table ta-report-table" data-export-name="<?php echo htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8'); ?>" data-export-target=".sr-print-actions" data-export-company="<?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($reportKey === 'balances'): ?>
                    <thead><tr><th>الكود</th><th>الصنف</th><th>التصنيف</th><th>اللون / المقاس</th><th class="gl-acc-stmt-col-num">الكمية</th><th class="gl-acc-stmt-col-num">التكلفة</th><th class="gl-acc-stmt-col-num">القيمة</th></tr></thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="7" class="muted">لا أصناف.</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <td dir="ltr"><?php echo htmlspecialchars($r['item_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r['category_name'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r['variant'] !== '/' ? $r['variant'] : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['qty']; ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $reportFmtMoney((float) $r['cost']); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $reportFmtMoney((float) $r['value']); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot><tr><th colspan="4">الإجمالي</th><th class="gl-acc-stmt-col-num"><?php echo (int) $grandQty; ?></th><th class="gl-acc-stmt-col-num">—</th><th class="gl-acc-stmt-col-num"><?php echo $reportFmtMoney($grandValue); ?></th></tr></tfoot>
                <?php elseif ($reportKey === 'valuation'): ?>
                    <?php $valCols = $valShowPrev ? 6 : 5; ?>
                    <thead><tr><th>الكود</th><th>الصنف</th><th class="gl-acc-stmt-col-num">الكمية</th><th class="gl-acc-stmt-col-num">التكلفة</th><th class="gl-acc-stmt-col-num">القيمة الحالية</th><?php if ($valShowPrev): ?><th class="gl-acc-stmt-col-num">نهاية <?php echo htmlspecialchars(orange_format_date_dmY($valPrevEnd), ENT_QUOTES, 'UTF-8'); ?></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php if ($valGroups === []): ?>
                            <tr><td colspan="<?php echo (int) $valCols; ?>" class="muted">لا أصناف.</td></tr>
                        <?php else: foreach ($valGroups as $catName => $grp): ?>
                            <tr class="ta-report-section"><td colspan="<?php echo (int) $valCols; ?>"><?php echo htmlspecialchars((string) $catName, ENT_QUOTES, 'UTF-8'); ?></td></tr>
                            <?php foreach ($grp['rows'] as $r): ?>
                            <tr>
                                <td dir="ltr"><?php echo htmlspecialchars($r['item_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['qty']; ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $reportFmtMoney((float) $r['cost']); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $reportFmtMoney((float) $r['value']); ?></td>
                                <?php if ($valShowPrev): ?><td class="gl-acc-stmt-col-num"><?php echo $reportFmtMoney((float) $r['value_prev']); ?></td><?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="ta-report-subtotal">
                                <td class="gl-acc-stmt-col-num muted">—</td>
                                <td><strong>إجمالي <?php echo htmlspecialchars((string) $catName, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td class="gl-acc-stmt-col-num">—</td>
                                <td class="gl-acc-stmt-col-num">—</td>
                                <td class="gl-acc-stmt-col-num"><strong><?php echo $reportFmtMoney((float) $grp['sub_value']); ?></strong></td>
                                <?php if ($valShowPrev): ?><td class="gl-acc-stmt-col-num"><strong><?php echo $reportFmtMoney((float) $grp['sub_value_prev']); ?></strong></td><?php endif; ?>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot><tr><th colspan="2">الإجمالي العام</th><th class="gl-acc-stmt-col-num"><?php echo (int) $grandQty; ?></th><th class="gl-acc-stmt-col-num">—</th><th class="gl-acc-stmt-col-num"><?php echo $reportFmtMoney($grandValue); ?></th><?php if ($valShowPrev): ?><th class="gl-acc-stmt-col-num"><?php echo $reportFmtMoney($grandValuePrev); ?></th><?php endif; ?></tr></tfoot>
                <?php elseif ($reportKey === 'low'): ?>
                    <thead><tr><th>#</th><th>الصنف</th><th>اللون / المقاس</th><th class="gl-acc-stmt-col-num">الرصيد</th><th>آخر مورد</th></tr></thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="5" class="muted">لا نواقص ضمن الحد (<?php echo (int) $lowTh; ?>).</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <td dir="ltr"><?php echo (int) $r['product_id']; ?></td>
                                <td><?php echo htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r['variant'] !== '/' ? $r['variant'] : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['qty']; ?></td>
                                <td><?php echo htmlspecialchars(($r['last_supplier'] ?? '') !== '' ? $r['last_supplier'] : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                <?php elseif ($reportKey === 'move_summary'): ?>
                    <thead><tr><th>#</th><th>الصنف</th><th class="gl-acc-stmt-col-num">وارد</th><th class="gl-acc-stmt-col-num">صادر</th><th class="gl-acc-stmt-col-num">الصافي</th></tr></thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="5" class="muted">لا حركة في المدى.</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <td dir="ltr"><?php echo (int) $r['product_id']; ?></td>
                                <td><?php echo htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['qin']; ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['qout']; ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['net']; ?></td>
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
        <?php echo orange_accounting_report_print_metafoot_markup($printDatetime); ?>
    </div>
</div>

<style>
.sr-tabs { display:flex; flex-wrap:wrap; gap:8px; }
.sr-tab { padding:7px 14px; border:1px solid #cbd5e1; border-radius:8px; background:#f8fafc; color:#334155; text-decoration:none; font-size:0.92rem; }
.sr-tab.is-active { background:#0f172a; color:#fff; border-color:#0f172a; }
</style>

<?php if (in_array($reportKey, ['balances', 'valuation', 'movements', 'move_summary'], true)): ?>
<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin_cart_promo_product_pick.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
(function () {
    var SR_PICK_ROWS = <?php echo $srPickJson !== false ? $srPickJson : '[]'; ?>;
    var pidInput = document.getElementById('sr_pid');
    var pidLabel = document.getElementById('sr_pid_label');
    var pickBtn = document.getElementById('sr_pick_btn');
    var clearBtn = document.getElementById('sr_pick_clear');
    var form = document.getElementById('bs_report_form') || (pidInput ? pidInput.closest('form') : null);

    function openPicker() {
        if (!window.OrangeCartPromoProductPick) {
            return;
        }
        OrangeCartPromoProductPick.open(SR_PICK_ROWS, function (row) {
            if (!pidInput) {
                return;
            }
            pidInput.value = parseInt(row.product_id, 10) || 0;
            if (pidLabel) {
                pidLabel.value = (row.code ? row.code + ' — ' : '') + row.name;
            }
            if (form) {
                form.submit();
            }
        });
    }

    if (pickBtn) {
        pickBtn.addEventListener('click', openPicker);
    }
    if (pidLabel) {
        pidLabel.addEventListener('dblclick', openPicker);
    }
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (pidInput) {
                pidInput.value = '0';
            }
            if (pidLabel) {
                pidLabel.value = '';
            }
            if (form) {
                form.submit();
            }
        });
    }
})();
</script>
<?php endif; ?>
