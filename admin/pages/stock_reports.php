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
    'items'       => 'قائمة الأصناف',
    'balances'    => 'الجرد (أرصدة المخزون)',
    'valuation'   => 'تقييم المخزون',
    'movements'   => 'حركة المخزون',
    'move_summary'=> 'ملخص الحركة (وارد/صادر)',
    'low'         => 'النواقص (تحت الحد)',
    'stagnant'    => 'الأصناف الراكدة',
];
$reportKey = isset($_GET['r']) ? (string) $_GET['r'] : 'balances';
if (!isset($reports[$reportKey])) {
    $reportKey = 'balances';
}

$pid = isset($_GET['pid']) ? max(0, (int) $_GET['pid']) : 0;
$pidLabel = '';
$pidCode = '';
if ($pid > 0) {
    $pidHasItemCode = orange_table_has_column($pdo, 'products', 'item_code');
    $pidCodeExpr = $pidHasItemCode
        ? "COALESCE(NULLIF(TRIM(item_code), ''), CONCAT('P', id))"
        : "CONCAT('P', id)";
    $pl = $pdo->prepare("SELECT name, {$pidCodeExpr} AS code FROM products WHERE id = ? LIMIT 1");
    $pl->execute([$pid]);
    $plRow = $pl->fetch(PDO::FETCH_ASSOC);
    $pidLabel = (string) ($plRow['name'] ?? '');
    $pidCode = (string) ($plRow['code'] ?? '');
    if ($pidLabel === '') {
        $pid = 0;
    }
}
$lowTh = orange_stock_low_alert_threshold($pdo, $srCountryId);
$srPickRows = orange_cart_promo_admin_product_rows($pdo);
$srPickJson = json_encode($srPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);

$normalizeDate = static function (string $raw): ?string {
    $ymd = orange_parse_admin_date_to_ymd(trim($raw));
    return $ymd !== '' ? $ymd : null;
};
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$mFrom = isset($_GET['m_from']) ? ($normalizeDate((string) $_GET['m_from']) ?? $monthStart) : $monthStart;
$mTo = isset($_GET['m_to']) ? ($normalizeDate((string) $_GET['m_to']) ?? $today) : $today;
if (strcmp($mFrom, $mTo) > 0) {
    [$mFrom, $mTo] = [$mTo, $mFrom];
}
$mFromDisplay = orange_format_date_dmY($mFrom);
$mToDisplay = orange_format_date_dmY($mTo);
$stagnantDays = isset($_GET['days']) ? max(7, min(3650, (int) $_GET['days'])) : 90;

/* فلتر القسم (departments) ثم الفئة (catalog_categories) — الفئات مكرّرة الاسم بين الأقسام
   لذلك يُختار القسم أولاً وتُفلتَر الفئات تبعه. */
$depId = isset($_GET['dep']) ? max(0, (int) $_GET['dep']) : 0;
$catId = isset($_GET['cat']) ? max(0, (int) $_GET['cat']) : 0;
/* إخفاء الأصناف/المتغيّرات ذات الرصيد صفر (قائمة الأصناف/الأرصدة/التقييم). */
$hideZero = isset($_GET['hz']) && (string) $_GET['hz'] === '1';
$depOptions = [];
$catOptions = [];
try {
    if (orange_table_exists($pdo, 'departments')) {
        $depOptions = $pdo->query(
            'SELECT id, name_ar FROM departments WHERE is_active = 1 ORDER BY sort_order ASC, name_ar ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if (orange_table_exists($pdo, 'catalog_categories') && orange_table_exists($pdo, 'catalog_sections')) {
        /* كل فئة مع قسمها (عبر القسم الفرعي catalog_sections) لتعاقُب القائمة في الواجهة. */
        $catOptions = $pdo->query(
            'SELECT cc.id, cc.name_ar, cs.department_id
             FROM catalog_categories cc
             INNER JOIN catalog_sections cs ON cs.id = cc.catalog_section_id
             WHERE cc.is_active = 1
             ORDER BY cc.sort_order ASC, cc.name_ar ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $depOptions = [];
    $catOptions = [];
}
/* تأكيد اتساق: إن كانت الفئة المختارة لا تتبع القسم المختار، أهمل الفئة. */
if ($catId > 0 && $depId > 0) {
    $catBelongs = false;
    foreach ($catOptions as $co) {
        if ((int) ($co['id'] ?? 0) === $catId && (int) ($co['department_id'] ?? 0) === $depId) {
            $catBelongs = true;
            break;
        }
    }
    if (!$catBelongs) {
        $catId = 0;
    }
}
/* جملة فلتر القسم عند عدم اختيار فئة محددة (الفئة أدق فتُقدَّم). */
$depFilterSql = '';
$depFilterParam = null;
if ($catId <= 0 && $depId > 0) {
    $depFilterSql = ' AND c.catalog_section_id IN (SELECT cs_dep.id FROM catalog_sections cs_dep WHERE cs_dep.department_id = ?)';
    $depFilterParam = $depId;
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

/* تجميع التقارير برأس «القسم ← الفئة» (يعتمد على alias c من $catJoin = catalog_categories). */
$grpCatJoinExtra = ' LEFT JOIN catalog_sections cs_grp ON cs_grp.id = c.catalog_section_id'
    . ' LEFT JOIN departments d_grp ON d_grp.id = cs_grp.department_id';
$grpSelectCols = "COALESCE(d_grp.name_ar, '') AS group_dep_name, COALESCE(c.id, 0) AS group_cat_id, COALESCE(c.name_ar, '') AS group_cat_name";
$grpOrderBy = 'd_grp.sort_order ASC, d_grp.name_ar ASC, c.sort_order ASC, c.name_ar ASC';
$grpKey = static function (array $r): string {
    return (string) ($r['group_dep_name'] ?? '') . '|' . (string) ($r['group_cat_id'] ?? '0') . '|' . (string) ($r['group_cat_name'] ?? '');
};
$grpLabel = static function (array $r): string {
    $dep = trim((string) ($r['group_dep_name'] ?? ''));
    $cat = trim((string) ($r['group_cat_name'] ?? ''));
    if ($cat === '') {
        $cat = 'بدون تصنيف';
    }
    return $dep !== '' ? ($dep . ' ← ' . $cat) : $cat;
};

$rows = [];
$moveGroups = [];
$grandQty = 0;
$grandValue = 0.0;
$grandValuePrev = 0.0;
$valGroups = [];
$valShowPrev = false;
$valPrevEnd = '';
$reportError = '';

try {
    if ($reportKey === 'items') {
        /* قائمة الأصناف: سطر لكل منتج (تصنيف + عدد المتغيرات + إجمالي الرصيد + الحالة). */
        $wqi = orange_warehouse_effective_qty_sql($pdo, $srCountryId, 'pv2', 'wvs_items');
        $totalStockSub = '(SELECT COALESCE(SUM(' . $wqi['expr'] . '), 0)
            FROM product_variants pv2'
            . $wqi['join']
            . ' WHERE pv2.product_id = p.id)';
        $filterSql = '';
        $params = [];
        if ($pid > 0) {
            $filterSql .= ' AND p.id = ?';
            $params[] = $pid;
        }
        if ($catId > 0) {
            $filterSql .= ' AND c.id = ?';
            $params[] = $catId;
        } elseif ($depFilterSql !== '') {
            $filterSql .= $depFilterSql;
            $params[] = $depFilterParam;
        }
        $sql = 'SELECT p.id AS product_id, p.name AS product_name, p.is_active,
                       ' . $itemCodeExpr . ' AS item_code,
                       COALESCE(c.name_ar, \'\') AS category_name,
                       ' . $grpSelectCols . ',
                       (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.id) AS variant_count,
                       ' . $totalStockSub . ' AS total_stock
                FROM products p
                ' . $catJoin . $grpCatJoinExtra . '
                WHERE 1=1' . $productCountrySql . $filterSql . '
                ORDER BY ' . $grpOrderBy . ', p.sort_order ASC, p.name ASC, p.id ASC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $qty = (int) $r['total_stock'];
            if ($hideZero && $qty <= 0) {
                continue;
            }
            $grandQty += $qty;
            $rows[] = [
                'item_code' => (string) $r['item_code'],
                'product_name' => (string) $r['product_name'],
                'category_name' => (string) $r['category_name'],
                'group_dep_name' => (string) ($r['group_dep_name'] ?? ''),
                'group_cat_id' => (int) ($r['group_cat_id'] ?? 0),
                'group_cat_name' => (string) ($r['group_cat_name'] ?? ''),
                'variant_count' => (int) $r['variant_count'],
                'total_stock' => $qty,
                'is_active' => !empty($r['is_active']),
            ];
        }
    } elseif ($reportKey === 'balances') {
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
        } elseif ($depFilterSql !== '') {
            $filterSql .= $depFilterSql;
            $params[] = $depFilterParam;
        }
        $sql = 'SELECT p.id AS product_id, p.name AS product_name, p.cost AS cost,
                       ' . $itemCodeExpr . ' AS item_code,
                       COALESCE(c.name_ar, \'\') AS category_name,
                       ' . $grpSelectCols . ',
                       pv.color, pv.size, ' . $wq['expr'] . ' AS qty
                FROM products p
                ' . $catJoin . $grpCatJoinExtra . '
                INNER JOIN product_variants pv ON pv.product_id = p.id
                ' . $wq['join'] . '
                WHERE p.is_active = 1' . $productCountrySql . $filterSql . '
                ORDER BY ' . $grpOrderBy . ', p.name ASC, p.id ASC, pv.color ASC, pv.size ASC, pv.id ASC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $qty = (int) $r['qty'];
            if ($hideZero && $qty <= 0) {
                continue;
            }
            $cost = (float) $r['cost'];
            $value = $qty * $cost;
            $grandQty += $qty;
            $grandValue += $value;
            $rows[] = [
                'item_code' => (string) $r['item_code'],
                'product_name' => (string) $r['product_name'],
                'category_name' => (string) $r['category_name'],
                'group_dep_name' => (string) ($r['group_dep_name'] ?? ''),
                'group_cat_id' => (int) ($r['group_cat_id'] ?? 0),
                'group_cat_name' => (string) ($r['group_cat_name'] ?? ''),
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
        } elseif ($depFilterSql !== '') {
            $filterSql .= $depFilterSql;
            $params[] = $depFilterParam;
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
                       ' . $itemCodeExpr . ' AS item_code,
                       pv.color, pv.size, ' . $wq['expr'] . ' AS qty,
                       ' . $grpSelectCols . $lastSupplierSelect . '
                FROM product_variants pv
                INNER JOIN products p ON p.id = pv.product_id
                ' . $catJoin . $grpCatJoinExtra . '
                ' . $wq['join'] . '
                WHERE p.is_active = 1 AND ' . $wq['expr'] . ' <= ?' . $productCountrySql . '
                ORDER BY ' . $grpOrderBy . ', ' . $wq['expr'] . ' ASC, p.name ASC, pv.id ASC';
        $st = $pdo->prepare($sql);
        $st->execute([$lowTh]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'product_id' => (int) $r['product_id'],
                'item_code' => (string) $r['item_code'],
                'product_name' => (string) $r['product_name'],
                'group_dep_name' => (string) ($r['group_dep_name'] ?? ''),
                'group_cat_id' => (int) ($r['group_cat_id'] ?? 0),
                'group_cat_name' => (string) ($r['group_cat_name'] ?? ''),
                'variant' => trim(((string) ($r['color'] ?? '')) . ' / ' . ((string) ($r['size'] ?? ''))),
                'qty' => (int) $r['qty'],
                'last_supplier' => trim((string) ($r['last_supplier'] ?? '')),
            ];
        }
    } elseif ($reportKey === 'move_summary') {
        /*
         * ملخص الحركة لكل صنف (ق1): رصيد أول + وارد + صادر + الرصيد الحالي — من كل المصادر.
         * stock_movements لا يحوي الشراء/مردود الشراء؛ لذا نجمعهما من جداولهما، ونحسب «رصيد أول»
         * بالمطابقة العكسية: الرصيد الفعلي الحالي − صافي كل الحركات منذ «من» (مُجمَّعاً لكل صنف).
         * الرصيد الحالي = رصيد أول + وارد − صادر،
         * فيطابق المخزون الفعلي عند «إلى» = اليوم.
         */
        $msMvCountrySql = orange_sql_country_and_fragment($pdo, 'stock_movements', 'sm', $srCountryId);
        $msHasPurCreatedAt = orange_table_has_column($pdo, 'purchases', 'created_at');
        $msHasPurReturns = orange_table_exists($pdo, 'purchase_returns') && orange_table_exists($pdo, 'purchase_return_items');
        $msPuQtyExpr = orange_table_has_column($pdo, 'purchase_items', 'qty_received') ? 'pi.qty_received' : 'pi.qty';
        $msPidSm = $pid > 0 ? ' AND sm.product_id = ?' : '';
        $msPidPi = $pid > 0 ? ' AND pi.product_id = ?' : '';
        $msPidPri = $pid > 0 ? ' AND pri.product_id = ?' : '';

        $wq = orange_warehouse_effective_qty_sql($pdo, $srCountryId, 'pv', 'wvs_ms');

        /* (1) الرصيد الفعلي الحالي + اسم الصنف لكل صنف ضمن نطاق الدولة. */
        $msCur = [];
        $curSql = 'SELECT p.id AS pid, p.name AS pname, ' . $itemCodeExpr . ' AS item_code,
                          COALESCE(SUM(' . $wq['expr'] . '), 0) AS cur
                   FROM products p
                   INNER JOIN product_variants pv ON pv.product_id = p.id
                   ' . $wq['join'] . '
                   WHERE 1=1' . $productCountrySql . ($pid > 0 ? ' AND p.id = ?' : '') . '
                   GROUP BY p.id, p.name, item_code';
        $st = $pdo->prepare($curSql);
        $st->execute($pid > 0 ? [$pid] : []);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $msCur[(int) $r['pid']] = ['name' => (string) $r['pname'], 'item_code' => (string) $r['item_code'], 'cur' => (int) $r['cur']];
        }

        /* (2) صافي كل الحركات منذ «من» لكل صنف (لاستخراج رصيد أول عكسياً). */
        $msDeltaSince = [];
        $msSmSince = 'SELECT sm.product_id AS pid, COALESCE(SUM(sm.new_stock - sm.old_stock), 0) AS v
                      FROM stock_movements sm
                      WHERE DATE(sm.created_at) >= ?' . $msMvCountrySql . $msPidSm . '
                      GROUP BY sm.product_id';
        $st = $pdo->prepare($msSmSince);
        $st->execute($pid > 0 ? [$mFrom, $pid] : [$mFrom]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $msDeltaSince[(int) $r['pid']] = ($msDeltaSince[(int) $r['pid']] ?? 0) + (int) $r['v'];
        }
        if ($msHasPurCreatedAt) {
            $msPuSince = 'SELECT pi.product_id AS pid, COALESCE(SUM(' . $msPuQtyExpr . '), 0) AS v
                          FROM purchase_items pi
                          INNER JOIN purchases pu ON pu.id = pi.purchase_id
                          WHERE DATE(pu.created_at) >= ?' . $msPidPi . '
                          GROUP BY pi.product_id';
            $st = $pdo->prepare($msPuSince);
            $st->execute($pid > 0 ? [$mFrom, $pid] : [$mFrom]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $msDeltaSince[(int) $r['pid']] = ($msDeltaSince[(int) $r['pid']] ?? 0) + (int) $r['v'];
            }
        }
        if ($msHasPurReturns) {
            $msPrSince = 'SELECT pri.product_id AS pid, COALESCE(SUM(pri.qty), 0) AS v
                          FROM purchase_return_items pri
                          INNER JOIN purchase_returns pr ON pr.id = pri.purchase_return_id
                          WHERE DATE(pr.created_at) >= ?' . $msPidPri . '
                          GROUP BY pri.product_id';
            $st = $pdo->prepare($msPrSince);
            $st->execute($pid > 0 ? [$mFrom, $pid] : [$mFrom]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $msDeltaSince[(int) $r['pid']] = ($msDeltaSince[(int) $r['pid']] ?? 0) - (int) $r['v'];
            }
        }

        /* (3) وارد/صادر داخل الفترة [من,إلى] لكل صنف من كل المصادر. */
        $msIn = [];
        $msOut = [];
        $msSmIo = 'SELECT sm.product_id AS pid,
                       SUM(CASE WHEN (sm.new_stock - sm.old_stock) > 0 THEN (sm.new_stock - sm.old_stock) ELSE 0 END) AS qin,
                       SUM(CASE WHEN (sm.new_stock - sm.old_stock) < 0 THEN (sm.old_stock - sm.new_stock) ELSE 0 END) AS qout
                   FROM stock_movements sm
                   WHERE DATE(sm.created_at) BETWEEN ? AND ?' . $msMvCountrySql . $msPidSm . '
                   GROUP BY sm.product_id';
        $st = $pdo->prepare($msSmIo);
        $st->execute($pid > 0 ? [$mFrom, $mTo, $pid] : [$mFrom, $mTo]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $p = (int) $r['pid'];
            $msIn[$p] = ($msIn[$p] ?? 0) + (int) $r['qin'];
            $msOut[$p] = ($msOut[$p] ?? 0) + (int) $r['qout'];
        }
        if ($msHasPurCreatedAt) {
            $msPuIo = 'SELECT pi.product_id AS pid, COALESCE(SUM(' . $msPuQtyExpr . '), 0) AS v
                       FROM purchase_items pi
                       INNER JOIN purchases pu ON pu.id = pi.purchase_id
                       WHERE DATE(pu.created_at) BETWEEN ? AND ?' . $msPidPi . '
                       GROUP BY pi.product_id';
            $st = $pdo->prepare($msPuIo);
            $st->execute($pid > 0 ? [$mFrom, $mTo, $pid] : [$mFrom, $mTo]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $p = (int) $r['pid'];
                $msIn[$p] = ($msIn[$p] ?? 0) + (int) $r['v'];
            }
        }
        if ($msHasPurReturns) {
            $msPrIo = 'SELECT pri.product_id AS pid, COALESCE(SUM(pri.qty), 0) AS v
                       FROM purchase_return_items pri
                       INNER JOIN purchase_returns pr ON pr.id = pri.purchase_return_id
                       WHERE DATE(pr.created_at) BETWEEN ? AND ?' . $msPidPri . '
                       GROUP BY pri.product_id';
            $st = $pdo->prepare($msPrIo);
            $st->execute($pid > 0 ? [$mFrom, $mTo, $pid] : [$mFrom, $mTo]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $p = (int) $r['pid'];
                $msOut[$p] = ($msOut[$p] ?? 0) + (int) $r['v'];
            }
        }

        /* (4) الصفوف: الأصناف ذات الحركة في الفترة (أو الصنف المحدّد)، ضمن نطاق الدولة فقط. */
        $msPidSet = [];
        foreach (array_keys($msIn) as $p) {
            $msPidSet[$p] = true;
        }
        foreach (array_keys($msOut) as $p) {
            $msPidSet[$p] = true;
        }
        if ($pid > 0) {
            $msPidSet[$pid] = true;
        }
        foreach (array_keys($msPidSet) as $p) {
            if (!isset($msCur[$p])) {
                continue;
            }
            $opening = (int) $msCur[$p]['cur'] - (int) ($msDeltaSince[$p] ?? 0);
            $qin = (int) ($msIn[$p] ?? 0);
            $qout = (int) ($msOut[$p] ?? 0);
            $rows[] = [
                'product_id' => $p,
                'item_code' => (string) ($msCur[$p]['item_code'] ?? ''),
                'product_name' => (string) $msCur[$p]['name'],
                'opening' => $opening,
                'qin' => $qin,
                'qout' => $qout,
                'closing' => $opening + $qin - $qout,
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) $a['product_name'], (string) $b['product_name']) ?: ($a['product_id'] <=> $b['product_id']);
        });
    } elseif ($reportKey === 'movements') {
        /*
         * حركة المخزون كسجل شامل (قرار المالك 2026-06-15): يدمج كل المصادر
         * (stock_movements + المشتريات + مردود المشتريات) مع رصيد افتتاحي ورصيد جارٍ ورصيد ختامي،
         * مُجمَّعاً لكل صنف على حدة (نفس منطق كرت الصنف، لمنتج محدّد أو لكل المنتجات).
         */
        $mvCountrySql = orange_sql_country_and_fragment($pdo, 'stock_movements', 'sm', $srCountryId);
        $mvHasPurCreatedAt = orange_table_has_column($pdo, 'purchases', 'created_at');
        $mvHasPurReturns = orange_table_exists($pdo, 'purchase_returns') && orange_table_exists($pdo, 'purchase_return_items');
        $mvHasPriVariant = $mvHasPurReturns && orange_table_has_column($pdo, 'purchase_return_items', 'variant_id');
        $mvPuQtyExpr = orange_table_has_column($pdo, 'purchase_items', 'qty_received') ? 'pi.qty_received' : 'pi.qty';
        $mvPidSm = $pid > 0 ? ' AND sm.product_id = ?' : '';
        $mvPidPi = $pid > 0 ? ' AND pi.product_id = ?' : '';
        $mvPidPri = $pid > 0 ? ' AND pri.product_id = ?' : '';

        $wq = orange_warehouse_effective_qty_sql($pdo, $srCountryId, 'pv', 'wvs_mv');

        /* (1) الرصيد الفعلي الحالي + اسم الصنف لكل صنف ضمن نطاق الدولة. */
        $mvCur = [];
        $curSql = 'SELECT p.id AS pid, p.name AS pname, COALESCE(SUM(' . $wq['expr'] . '), 0) AS cur
                   FROM products p
                   INNER JOIN product_variants pv ON pv.product_id = p.id
                   ' . $wq['join'] . '
                   WHERE 1=1' . $productCountrySql . ($pid > 0 ? ' AND p.id = ?' : '') . '
                   GROUP BY p.id, p.name';
        $st = $pdo->prepare($curSql);
        $st->execute($pid > 0 ? [$pid] : []);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mvCur[(int) $r['pid']] = ['name' => (string) $r['pname'], 'cur' => (int) $r['cur']];
        }

        /* (2) صافي كل الحركات منذ «من» لكل صنف (لاستخراج رصيد أول عكسياً). */
        $mvDeltaSince = [];
        $q = 'SELECT sm.product_id AS pid, COALESCE(SUM(sm.new_stock - sm.old_stock), 0) AS v
              FROM stock_movements sm
              WHERE DATE(sm.created_at) >= ?' . $mvCountrySql . $mvPidSm . '
              GROUP BY sm.product_id';
        $st = $pdo->prepare($q);
        $st->execute($pid > 0 ? [$mFrom, $pid] : [$mFrom]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mvDeltaSince[(int) $r['pid']] = ($mvDeltaSince[(int) $r['pid']] ?? 0) + (int) $r['v'];
        }
        if ($mvHasPurCreatedAt) {
            $q = 'SELECT pi.product_id AS pid, COALESCE(SUM(' . $mvPuQtyExpr . '), 0) AS v
                  FROM purchase_items pi
                  INNER JOIN purchases pu ON pu.id = pi.purchase_id
                  WHERE DATE(pu.created_at) >= ?' . $mvPidPi . '
                  GROUP BY pi.product_id';
            $st = $pdo->prepare($q);
            $st->execute($pid > 0 ? [$mFrom, $pid] : [$mFrom]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $mvDeltaSince[(int) $r['pid']] = ($mvDeltaSince[(int) $r['pid']] ?? 0) + (int) $r['v'];
            }
        }
        if ($mvHasPurReturns) {
            $q = 'SELECT pri.product_id AS pid, COALESCE(SUM(pri.qty), 0) AS v
                  FROM purchase_return_items pri
                  INNER JOIN purchase_returns pr ON pr.id = pri.purchase_return_id
                  WHERE DATE(pr.created_at) >= ?' . $mvPidPri . '
                  GROUP BY pri.product_id';
            $st = $pdo->prepare($q);
            $st->execute($pid > 0 ? [$mFrom, $pid] : [$mFrom]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $mvDeltaSince[(int) $r['pid']] = ($mvDeltaSince[(int) $r['pid']] ?? 0) - (int) $r['v'];
            }
        }

        /* (3) أحداث الفترة [من,إلى] لكل صنف من كل المصادر. */
        $mvEvents = [];
        $q = 'SELECT sm.product_id AS pid, sm.created_at AS at, sm.type, sm.old_stock, sm.new_stock, sm.reference, pv.color, pv.size
              FROM stock_movements sm
              LEFT JOIN product_variants pv ON pv.id = sm.variant_id
              WHERE DATE(sm.created_at) BETWEEN ? AND ?' . $mvCountrySql . $mvPidSm . '
              ORDER BY sm.created_at ASC, sm.id ASC';
        $st = $pdo->prepare($q);
        $st->execute($pid > 0 ? [$mFrom, $mTo, $pid] : [$mFrom, $mTo]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $p = (int) $r['pid'];
            $mvEvents[$p][] = [
                'at' => (string) $r['at'],
                'label' => orange_stock_movement_type_label_ar((string) $r['type']),
                'variant' => trim(((string) ($r['color'] ?? '')) . ' / ' . ((string) ($r['size'] ?? ''))),
                'delta' => (int) $r['new_stock'] - (int) $r['old_stock'],
                'reference' => (string) ($r['reference'] ?? ''),
            ];
        }
        if ($mvHasPurCreatedAt) {
            $q = 'SELECT pi.product_id AS pid, pu.id AS doc_id, pu.created_at AS at, ' . $mvPuQtyExpr . ' AS qty, pv.color, pv.size
                  FROM purchase_items pi
                  INNER JOIN purchases pu ON pu.id = pi.purchase_id
                  LEFT JOIN product_variants pv ON pv.id = pi.variant_id
                  WHERE DATE(pu.created_at) BETWEEN ? AND ?' . $mvPidPi . '
                  ORDER BY pu.created_at ASC, pu.id ASC';
            $st = $pdo->prepare($q);
            $st->execute($pid > 0 ? [$mFrom, $mTo, $pid] : [$mFrom, $mTo]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $p = (int) $r['pid'];
                $mvEvents[$p][] = [
                    'at' => (string) $r['at'],
                    'label' => 'فاتورة شراء',
                    'variant' => trim(((string) ($r['color'] ?? '')) . ' / ' . ((string) ($r['size'] ?? ''))),
                    'delta' => (int) $r['qty'],
                    'reference' => 'PUR-' . (int) $r['doc_id'],
                ];
            }
        }
        if ($mvHasPurReturns) {
            $q = 'SELECT pri.product_id AS pid, pr.id AS doc_id, pr.created_at AS at, pri.qty, '
                . ($mvHasPriVariant ? 'pv.color, pv.size' : 'NULL AS color, NULL AS size') . '
                  FROM purchase_return_items pri
                  INNER JOIN purchase_returns pr ON pr.id = pri.purchase_return_id
                  ' . ($mvHasPriVariant ? 'LEFT JOIN product_variants pv ON pv.id = pri.variant_id' : '') . '
                  WHERE DATE(pr.created_at) BETWEEN ? AND ?' . $mvPidPri . '
                  ORDER BY pr.created_at ASC, pr.id ASC';
            $st = $pdo->prepare($q);
            $st->execute($pid > 0 ? [$mFrom, $mTo, $pid] : [$mFrom, $mTo]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $p = (int) $r['pid'];
                $mvEvents[$p][] = [
                    'at' => (string) $r['at'],
                    'label' => 'مردود مشتريات',
                    'variant' => trim(((string) ($r['color'] ?? '')) . ' / ' . ((string) ($r['size'] ?? ''))),
                    'delta' => -((int) $r['qty']),
                    'reference' => 'PRET-' . (int) $r['doc_id'],
                ];
            }
        }

        /* (4) لكل صنف: ترتيب زمني + رصيد جارٍ من رصيد أول، مع رصيد ختامي وإجمالي وارد/صادر. */
        foreach ($mvEvents as $p => $evs) {
            if (!isset($mvCur[$p])) {
                continue;
            }
            usort($evs, static function (array $a, array $b): int {
                return strcmp((string) $a['at'], (string) $b['at']);
            });
            $opening = (int) $mvCur[$p]['cur'] - (int) ($mvDeltaSince[$p] ?? 0);
            $running = $opening;
            $sumIn = 0;
            $sumOut = 0;
            $lines = [];
            foreach ($evs as $ev) {
                $d = (int) $ev['delta'];
                $running += $d;
                if ($d > 0) {
                    $sumIn += $d;
                } elseif ($d < 0) {
                    $sumOut += -$d;
                }
                $lines[] = [
                    'at' => (string) $ev['at'],
                    'label' => (string) $ev['label'],
                    'variant' => (string) $ev['variant'],
                    'in' => $d > 0 ? $d : 0,
                    'out' => $d < 0 ? -$d : 0,
                    'balance' => $running,
                    'reference' => (string) $ev['reference'],
                ];
            }
            $moveGroups[] = [
                'product_id' => $p,
                'product_name' => (string) $mvCur[$p]['name'],
                'opening' => $opening,
                'closing' => $running,
                'sum_in' => $sumIn,
                'sum_out' => $sumOut,
                'lines' => $lines,
            ];
        }
        usort($moveGroups, static function (array $a, array $b): int {
            return strcmp((string) $a['product_name'], (string) $b['product_name']) ?: ($a['product_id'] <=> $b['product_id']);
        });
    } elseif ($reportKey === 'stagnant') {
        $wq = orange_warehouse_effective_qty_sql($pdo, $srCountryId, 'pv', 'wvs_stag');
        $cutoff = date('Y-m-d', strtotime('-' . $stagnantDays . ' days'));
        $sql = 'SELECT p.id AS product_id, p.name AS product_name, pv.id AS variant_id,
                       ' . $itemCodeExpr . ' AS item_code,
                       pv.color, pv.size, ' . $wq['expr'] . ' AS qty,
                       (SELECT MAX(sm.created_at) FROM stock_movements sm WHERE sm.variant_id = pv.id) AS last_move,
                       (SELECT sm2.type FROM stock_movements sm2 WHERE sm2.variant_id = pv.id ORDER BY sm2.created_at DESC, sm2.id DESC LIMIT 1) AS last_move_type
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
                'item_code' => (string) $r['item_code'],
                'product_name' => (string) $r['product_name'],
                'variant' => trim(((string) ($r['color'] ?? '')) . ' / ' . ((string) ($r['size'] ?? ''))),
                'qty' => (int) $r['qty'],
                'last_move' => (string) ($r['last_move'] ?? ''),
                'last_move_type' => (string) ($r['last_move_type'] ?? ''),
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
        <?php if (in_array($reportKey, ['items', 'balances', 'valuation', 'movements', 'move_summary'], true)): ?>
            <div>
                <label for="sr_pid_code">الصنف (دبل كليك للاختيار)</label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="hidden" id="sr_pid" name="pid" value="<?php echo (int) $pid; ?>">
                    <input type="text" id="sr_pid_code" class="admin-inp" readonly
                        title="يُملأ تلقائياً عند اختيار الصنف"
                        style="width:11rem;background:#f4f4f5;" dir="ltr"
                        placeholder="الكود"
                        value="<?php echo htmlspecialchars($pidCode, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="text" id="sr_pid_name" class="admin-inp" readonly
                        title="دبل كليك لاختيار صنف"
                        style="cursor:pointer;min-width:20rem;"
                        placeholder="كل الأصناف — دبل كليك للاختيار"
                        value="<?php echo htmlspecialchars($pidLabel, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($pid > 0): ?>
                        <button type="button" class="btn-secondary" id="sr_pick_clear">الكل</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if (in_array($reportKey, ['items', 'balances', 'valuation'], true) && $catOptions !== []): ?>
            <?php if ($depOptions !== []): ?>
            <div>
                <label for="sr_dep">القسم</label>
                <select id="sr_dep" name="dep" class="admin-inp" style="width:10.25rem;">
                    <option value="0">كل الأقسام</option>
                    <?php foreach ($depOptions as $depo): ?>
                        <option value="<?php echo (int) $depo['id']; ?>" <?php echo ((int) $depo['id'] === $depId) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) ($depo['name_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <label for="sr_cat">الفئة</label>
                <select id="sr_cat" name="cat" class="admin-inp" style="width:13.5rem;" <?php echo ($depOptions !== [] && $depId <= 0) ? 'disabled' : ''; ?>>
                    <option value="0">كل الفئات</option>
                    <?php foreach ($catOptions as $co): ?>
                        <?php $coDep = (int) ($co['department_id'] ?? 0); ?>
                        <option value="<?php echo (int) $co['id']; ?>"
                            data-dep="<?php echo $coDep; ?>"
                            <?php echo ((int) $co['id'] === $catId) ? 'selected' : ''; ?>
                            <?php echo ($depId > 0 && $coDep !== $depId) ? 'hidden disabled' : ''; ?>>
                            <?php echo htmlspecialchars((string) ($co['name_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <?php if (in_array($reportKey, ['items', 'balances'], true)): ?>
            <div style="display:flex;align-items:flex-end;">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;white-space:nowrap;font-weight:600;">
                    <input type="checkbox" name="hz" value="1" onchange="this.form.submit()" <?php echo $hideZero ? 'checked' : ''; ?>>
                    إخفاء الرصيد صفر
                </label>
            </div>
        <?php endif; ?>
        <?php if (in_array($reportKey, ['movements', 'move_summary'], true)): ?>
            <div>
                <label for="sr_from">من تاريخ</label>
                <input type="text" id="sr_from" name="m_from" class="admin-inp orange-inp-dmy" lang="en" dir="ltr" value="<?php echo htmlspecialchars($mFromDisplay, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <label for="sr_to">إلى تاريخ</label>
                <input type="text" id="sr_to" name="m_to" class="admin-inp orange-inp-dmy" lang="en" dir="ltr" value="<?php echo htmlspecialchars($mToDisplay, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        <?php endif; ?>
        <?php if ($reportKey === 'stagnant'): ?>
            <div>
                <label for="sr_days">راكد منذ (يوم) — بلا حركة</label>
                <input type="number" id="sr_days" name="days" class="admin-inp" min="7" max="3650" step="1" lang="en" dir="ltr" value="<?php echo (int) $stagnantDays; ?>">
            </div>
        <?php endif; ?>
        <div class="sr-print-actions" style="display:flex;gap:8px;align-items:center;margin-inline-start:auto;">
            <button type="submit">عرض</button>
            <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
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
            <?php $srFixedCols = in_array($reportKey, ['items', 'balances', 'low', 'stagnant'], true); ?>
            <table class="admin-fy-table gl-acc-stmt-table ta-report-table<?php echo $srFixedCols ? ' sr-grouped-cols' : ''; ?>" data-export-name="<?php echo htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8'); ?>" data-export-target=".sr-print-actions" data-export-company="<?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($reportKey === 'items'): ?>
                    <colgroup><col style="width:9.5rem"><col><col style="width:15rem"><col style="width:6rem"><col style="width:6rem"></colgroup>
                <?php elseif ($reportKey === 'balances'): ?>
                    <colgroup><col style="width:9.5rem"><col><col style="width:12rem"><col style="width:6rem"><col style="width:7rem"><col style="width:8rem"></colgroup>
                <?php elseif ($reportKey === 'low'): ?>
                    <colgroup><col style="width:9.5rem"><col><col style="width:12rem"><col style="width:6rem"><col style="width:16rem"></colgroup>
                <?php elseif ($reportKey === 'stagnant'): ?>
                    <colgroup><col style="width:9.5rem"><col><col style="width:12rem"><col style="width:6rem"><col style="width:8.5rem"><col style="width:10rem"></colgroup>
                <?php endif; ?>
                <?php if ($reportKey === 'items'): ?>
                    <thead><tr><th class="sr-code-cell">الكود</th><th>الصنف</th><th class="gl-acc-stmt-col-num">عدد المتغيرات</th><th class="gl-acc-stmt-col-num">إجمالي الرصيد</th><th>الحالة</th></tr></thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="5" class="muted">لا أصناف.</td></tr>
                        <?php else: $srGrp = null; foreach ($rows as $r): ?>
                            <?php $k = $grpKey($r); if ($k !== $srGrp): $srGrp = $k; ?>
                                <tr class="ta-report-section"><td colspan="5"><?php echo htmlspecialchars($grpLabel($r), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                            <?php endif; ?>
                            <tr>
                                <td dir="ltr" class="sr-code-cell"><?php echo htmlspecialchars($r['item_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['variant_count']; ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['total_stock']; ?></td>
                                <td><?php echo $r['is_active'] ? 'نشط' : 'موقوف'; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot><tr><th colspan="3">الإجمالي</th><th class="gl-acc-stmt-col-num"><?php echo (int) $grandQty; ?></th><th></th></tr></tfoot>
                <?php elseif ($reportKey === 'balances'): ?>
                    <thead><tr><th class="sr-code-cell">الكود</th><th>الصنف</th><th class="sr-col-variant">اللون / المقاس</th><th class="gl-acc-stmt-col-num">الكمية</th><th class="gl-acc-stmt-col-num">التكلفة</th><th class="gl-acc-stmt-col-num">القيمة</th></tr></thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="6" class="muted">لا أصناف.</td></tr>
                        <?php else: $srGrp = null; foreach ($rows as $r): ?>
                            <?php $k = $grpKey($r); if ($k !== $srGrp): $srGrp = $k; ?>
                                <tr class="ta-report-section"><td colspan="6"><?php echo htmlspecialchars($grpLabel($r), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                            <?php endif; ?>
                            <tr>
                                <td dir="ltr" class="sr-code-cell"><?php echo htmlspecialchars($r['item_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="sr-col-variant"><?php echo htmlspecialchars($r['variant'] !== '/' ? $r['variant'] : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['qty']; ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $reportFmtMoney((float) $r['cost']); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $reportFmtMoney((float) $r['value']); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot><tr><th colspan="3">الإجمالي</th><th class="gl-acc-stmt-col-num"><?php echo (int) $grandQty; ?></th><th class="gl-acc-stmt-col-num">—</th><th class="gl-acc-stmt-col-num"><?php echo $reportFmtMoney($grandValue); ?></th></tr></tfoot>
                <?php elseif ($reportKey === 'valuation'): ?>
                    <?php $valCols = $valShowPrev ? 6 : 5; ?>
                    <thead><tr><th>الكود</th><th>الصنف</th><th class="gl-acc-stmt-col-num sr-col-qty">الكمية</th><th class="gl-acc-stmt-col-num">التكلفة</th><th class="gl-acc-stmt-col-num">القيمة الحالية</th><?php if ($valShowPrev): ?><th class="gl-acc-stmt-col-num">نهاية <?php echo htmlspecialchars(orange_format_date_dmY($valPrevEnd), ENT_QUOTES, 'UTF-8'); ?></th><?php endif; ?></tr></thead>
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
                    <thead><tr><th class="sr-code-cell">الكود</th><th>الصنف</th><th class="sr-col-variant">اللون / المقاس</th><th class="gl-acc-stmt-col-num sr-col-qty">الرصيد</th><th>آخر مورد</th></tr></thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="5" class="muted">لا نواقص ضمن الحد (<?php echo (int) $lowTh; ?>).</td></tr>
                        <?php else: $srGrp = null; foreach ($rows as $r): ?>
                            <?php $k = $grpKey($r); if ($k !== $srGrp): $srGrp = $k; ?>
                                <tr class="ta-report-section"><td colspan="5"><?php echo htmlspecialchars($grpLabel($r), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                            <?php endif; ?>
                            <tr>
                                <td dir="ltr" class="sr-code-cell"><?php echo htmlspecialchars($r['item_code'] !== '' ? $r['item_code'] : ('P' . $r['product_id']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="sr-col-variant"><?php echo htmlspecialchars($r['variant'] !== '/' ? $r['variant'] : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['qty']; ?></td>
                                <td><?php echo htmlspecialchars(($r['last_supplier'] ?? '') !== '' ? $r['last_supplier'] : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                <?php elseif ($reportKey === 'move_summary'): ?>
                    <thead><tr><th class="sr-code-cell">الكود</th><th>الصنف</th><th class="gl-acc-stmt-col-num sr-col-qty">رصيد أول</th><th class="gl-acc-stmt-col-num sr-col-qty">وارد</th><th class="gl-acc-stmt-col-num sr-col-qty">صادر</th><th class="gl-acc-stmt-col-num sr-col-qty">الرصيد الحالي</th></tr></thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="6" class="muted">لا حركة في المدى.</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <td dir="ltr" class="sr-code-cell"><?php echo htmlspecialchars($r['item_code'] !== '' ? $r['item_code'] : ('P' . $r['product_id']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['opening']; ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['qin']; ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['qout']; ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['closing']; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                <?php elseif ($reportKey === 'movements'): ?>
                    <thead><tr><th>التاريخ</th><th>النوع</th><th class="sr-col-variant">لون/مقاس</th><th class="gl-acc-stmt-col-num sr-col-qty">وارد</th><th class="gl-acc-stmt-col-num sr-col-qty">صادر</th><th class="gl-acc-stmt-col-num sr-col-qty">الرصيد</th><th>مرجع</th></tr></thead>
                    <tbody>
                        <?php if ($moveGroups === []): ?>
                            <tr><td colspan="7" class="muted">لا حركات في المدى.</td></tr>
                        <?php else: foreach ($moveGroups as $g): ?>
                            <tr class="ta-report-section"><td colspan="7"><?php echo htmlspecialchars($g['product_name'] . ' (#' . $g['product_id'] . ')', ENT_QUOTES, 'UTF-8'); ?></td></tr>
                            <tr>
                                <td colspan="5"><strong>رصيد أول</strong></td>
                                <td class="gl-acc-stmt-col-num"><strong><?php echo (int) $g['opening']; ?></strong></td>
                                <td class="muted">—</td>
                            </tr>
                            <?php foreach ($g['lines'] as $ln): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(orange_format_datetime_dmY_hi($ln['at']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($ln['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="sr-col-variant"><?php echo htmlspecialchars($ln['variant'] !== '/' ? $ln['variant'] : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $ln['in'] > 0 ? (int) $ln['in'] : '—'; ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $ln['out'] > 0 ? (int) $ln['out'] : '—'; ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $ln['balance']; ?></td>
                                <td dir="ltr"><code><?php echo htmlspecialchars($ln['reference'] !== '' ? $ln['reference'] : '—', ENT_QUOTES, 'UTF-8'); ?></code></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="ta-report-subtotal">
                                <td colspan="3"><strong>رصيد ختامي — <?php echo htmlspecialchars($g['product_name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td class="gl-acc-stmt-col-num"><strong><?php echo (int) $g['sum_in']; ?></strong></td>
                                <td class="gl-acc-stmt-col-num"><strong><?php echo (int) $g['sum_out']; ?></strong></td>
                                <td class="gl-acc-stmt-col-num"><strong><?php echo (int) $g['closing']; ?></strong></td>
                                <td class="muted">—</td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                <?php elseif ($reportKey === 'stagnant'): ?>
                    <thead><tr><th class="sr-code-cell">الكود</th><th>الصنف</th><th class="sr-col-variant">اللون / المقاس</th><th class="gl-acc-stmt-col-num sr-col-qty">الرصيد</th><th>تاريخ آخر حركة</th><th>نوع آخر حركة</th></tr></thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="6" class="muted">لا أصناف راكدة منذ <?php echo (int) $stagnantDays; ?> يوم.</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <td dir="ltr" class="sr-code-cell"><?php echo htmlspecialchars($r['item_code'] !== '' ? $r['item_code'] : ('P' . $r['product_id']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="sr-col-variant"><?php echo htmlspecialchars($r['variant'] !== '/' ? $r['variant'] : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo (int) $r['qty']; ?></td>
                                <td><?php echo $r['last_move'] !== '' ? htmlspecialchars(orange_format_date_dmY(substr($r['last_move'], 0, 10)), ENT_QUOTES, 'UTF-8') : 'بلا حركة'; ?></td>
                                <td><?php echo $r['last_move_type'] !== '' ? htmlspecialchars(orange_stock_movement_type_label_ar($r['last_move_type']), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                <?php endif; ?>
            </table>
        </div>

        <?php echo orange_accounting_report_print_metafoot_markup($printDatetime); ?>
    </div>
</div>

<style>
.sr-tabs { display:flex; flex-wrap:wrap; gap:8px; }
.sr-tab { padding:7px 14px; border:1px solid #cbd5e1; border-radius:8px; background:#f8fafc; color:#334155; text-decoration:none; font-size:0.92rem; }
.sr-tab.is-active { background:#0f172a; color:#fff; border-color:#0f172a; }
</style>

<?php if (in_array($reportKey, ['items', 'balances', 'valuation', 'movements', 'move_summary'], true)): ?>
<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin_cart_promo_product_pick.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
(function () {
    var SR_PICK_ROWS = <?php echo $srPickJson !== false ? $srPickJson : '[]'; ?>;
    var pidInput = document.getElementById('sr_pid');
    var pidCode = document.getElementById('sr_pid_code');
    var pidName = document.getElementById('sr_pid_name');
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
            if (pidCode) {
                pidCode.value = row.code || '';
            }
            if (pidName) {
                pidName.value = row.name || '';
            }
            if (form) {
                form.submit();
            }
        });
    }

    if (pidName) {
        pidName.addEventListener('dblclick', openPicker);
    }
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (pidInput) {
                pidInput.value = '0';
            }
            if (pidCode) {
                pidCode.value = '';
            }
            if (pidName) {
                pidName.value = '';
            }
            if (form) {
                form.submit();
            }
        });
    }

    /* تعاقُب القسم → الفئة: عند تغيير القسم تُعرض فئات ذلك القسم فقط، وتُصفّر الفئة إن لم تَعُد ضمنه. */
    var depSel = document.getElementById('sr_dep');
    var catSel = document.getElementById('sr_cat');
    if (depSel && catSel) {
        var syncCats = function (resetIfMismatch) {
            var dep = parseInt(depSel.value, 10) || 0;
            /* كل الأقسام: لا اختيار فئة محددة (مكرّرة) — تُقفل على «كل الفئات». */
            if (dep === 0) {
                catSel.value = '0';
                catSel.disabled = true;
                return;
            }
            catSel.disabled = false;
            var opts = catSel.options;
            for (var i = 0; i < opts.length; i++) {
                var opt = opts[i];
                if (!opt.value || opt.value === '0') {
                    continue;
                }
                var oDep = parseInt(opt.getAttribute('data-dep'), 10) || 0;
                var show = (oDep === dep);
                opt.hidden = !show;
                opt.disabled = !show;
                if (!show && resetIfMismatch && opt.selected) {
                    catSel.value = '0';
                }
            }
        };
        depSel.addEventListener('change', function () {
            syncCats(true);
        });
        syncCats(false);
    }
})();
</script>
<?php endif; ?>
