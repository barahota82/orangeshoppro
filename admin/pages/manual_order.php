<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_taxonomy_migrate.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$channels = $pdo->query('SELECT id, name FROM channels WHERE is_active = 1 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);

$prodCols = 'id, name, price, cost, has_colors, has_sizes';
$pProdCols = 'p.id, p.name, p.price, p.cost, p.has_colors, p.has_sizes';
if (orange_table_has_column($pdo, 'products', 'item_code')) {
    $prodCols .= ', item_code';
    $pProdCols .= ', p.item_code';
}
if (orange_table_has_column($pdo, 'products', 'barcode')) {
    $prodCols .= ', barcode';
    $pProdCols .= ', p.barcode';
}

$catalogNavUnified = function_exists('orange_catalog_nav_use_unified') && orange_catalog_nav_use_unified($pdo);
if (
    $catalogNavUnified
    && function_exists('orange_table_exists')
    && orange_table_exists($pdo, 'product_types')
    && orange_table_exists($pdo, 'catalog_subcategories')
) {
    $products = $pdo->query(
        "
        SELECT DISTINCT $pProdCols
        FROM products p
        INNER JOIN product_types pt ON pt.id = p.product_type_id AND pt.is_active = 1
        INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id AND ucs.is_active = 1
        INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
        INNER JOIN catalog_sections ucs2 ON ucs2.id = ucc.catalog_section_id AND ucs2.is_active = 1
        INNER JOIN departments d ON d.id = ucs2.department_id AND d.is_active = 1
        WHERE p.is_active = 1
        ORDER BY p.name ASC
    "
    )->fetchAll(PDO::FETCH_ASSOC);
} else {
$products = $pdo->query(
        "SELECT $prodCols FROM products WHERE is_active = 1 ORDER BY name ASC"
)->fetchAll(PDO::FETCH_ASSOC);
}

$variants = $pdo->query('SELECT * FROM product_variants ORDER BY product_id ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
$variantsByProduct = [];
foreach ($variants as $v) {
    $pid = (int) $v['product_id'];
    if (!isset($variantsByProduct[$pid])) {
        $variantsByProduct[$pid] = [];
    }
    $variantsByProduct[$pid][] = $v;
}

/** @var array<int,int> */
$moPendingReservedByVariant = [];
if (orange_table_exists($pdo, 'stock_movements') && orange_table_has_column($pdo, 'stock_movements', 'variant_id')) {
    $vidsAll = [];
    foreach ($variants as $v) {
        $vidsAll[] = (int) $v['id'];
    }
    $vidsAll = array_values(array_unique(array_filter($vidsAll)));
    foreach (array_chunk($vidsAll, 400) as $chunk) {
        if ($chunk === []) {
            continue;
        }
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $q = $pdo->prepare(
            "SELECT variant_id, COALESCE(SUM(qty), 0) AS q FROM stock_movements WHERE type = 'pending_order' AND variant_id IN ($ph) GROUP BY variant_id"
        );
        $q->execute($chunk);
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $moPendingReservedByVariant[(int) $row['variant_id']] = (int) $row['q'];
        }
    }
}

$allocPickCode = static function (string $base, array &$usedLower): string {
    $code = trim($base);
    if ($code === '') {
        $code = 'X';
    }
    $trial = $code;
    $n = 0;
    for (;;) {
        $k = function_exists('mb_strtolower') ? mb_strtolower($trial, 'UTF-8') : strtolower($trial);
        if (!isset($usedLower[$k])) {
            $usedLower[$k] = true;

            return $trial;
        }
        $n++;
        $trial = $code . '-' . $n;
    }
};

$pickRows = [];
$codesLower = [];
foreach ($products as $p) {
    $pid = (int) $p['id'];
    $pcode = trim((string) ($p['item_code'] ?? ''));
    $vlist = $variantsByProduct[$pid] ?? [];
    $pbarcode = trim((string) ($p['barcode'] ?? ''));
    if ($vlist === []) {
        $base = $pcode !== '' ? $pcode : ('P' . $pid);
        $code = $allocPickCode($base, $codesLower);
        $pickRows[] = [
            'code' => $code,
            'barcode' => $pbarcode,
            'product_id' => $pid,
            'variant_id' => 0,
            'name' => (string) $p['name'],
            'color' => '',
            'size' => '',
            'price' => (float) $p['price'],
            'stock_available' => 0,
            'stock_reserved' => 0,
            'stock_total' => 0,
        ];
        continue;
    }
    foreach ($vlist as $v) {
        $vid = (int) $v['id'];
        $vcode = trim((string) ($v['item_code'] ?? ''));
        $vbarcode = trim((string) ($v['barcode'] ?? ''));
        $rowBarcode = $vbarcode !== '' ? $vbarcode : $pbarcode;
        if ($vcode !== '') {
            $base = $vcode;
        } elseif ($pcode !== '') {
            $base = $pcode . '-' . $vid;
        } else {
            $base = 'P' . $pid . '-V' . $vid;
        }
        $code = $allocPickCode($base, $codesLower);
        $avail = (int) ($v['stock_quantity'] ?? 0);
        $res = (int) ($moPendingReservedByVariant[$vid] ?? 0);
        $pickRows[] = [
            'code' => $code,
            'barcode' => $rowBarcode,
            'product_id' => $pid,
            'variant_id' => $vid,
            'name' => (string) $p['name'],
            'color' => (string) ($v['color'] ?? ''),
            'size' => (string) ($v['size'] ?? ''),
            'price' => (float) $p['price'],
            'stock_available' => $avail,
            'stock_reserved' => $res,
            'stock_total' => $avail + $res,
        ];
    }
}
?>
<div class="page-title page-title--stacked">
    <h1>فاتورة مبيعات</h1>
    <p class="page-subtitle">مستند بيع داخلي مرتبط بالعميل والقناة. <strong>كل بند</strong> يُربَط بصنف مسجّل: <strong>كود الصنف</strong> أو <strong>الباركود</strong> (حقلا <code dir="ltr">item_code</code> و<code dir="ltr">barcode</code> في قاعدة البيانات، أو كود تلقائي مثل P12) مع اللون/المقاس عند وجود متغيرات. حقول <strong>سعر الوحدة</strong> و<strong>الخصم</strong> تتبع تنسيق المبالغ (ثلاث خانات عشرية، ويسمح الخصم بالقيم السالبة كزيادة). بعد الحفظ تفتح الفاتورة الرسمية.</p>
</div>

<div class="card">
    <h3>بيانات العميل</h3>
    <div class="form-grid">
        <div><label>الاسم</label><input type="text" id="mo_name" required></div>
        <div><label>الهاتف</label><input type="text" id="mo_phone" required></div>
        <div><label>المنطقة</label><input type="text" id="mo_area"></div>
        <div><label>العنوان</label><input type="text" id="mo_address"></div>
        <div style="grid-column:1/-1;"><label>ملاحظات</label><input type="text" id="mo_notes"></div>
        <div style="grid-column:1/-1;">
            <label>قناة العملاء (تتبّع المصدر — المخزن واحد)</label>
            <select id="mo_channel" aria-describedby="mo_channel_hint">
                <?php foreach ($channels as $ch): ?>
                    <option value="<?php echo (int)$ch['id']; ?>"><?php echo htmlspecialchars((string)$ch['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <p id="mo_channel_hint" class="card-hint" style="margin:0.35rem 0 0;">لتنظيم عملاء تيك توك وغيرها؛ لا يغيّر مخزوناً منفصلاً.</p>
        </div>
        <div style="grid-column:1/-1;">
            <label>نوع البيع</label>
            <select id="mo_payment_terms">
                <option value="cash" selected>نقدي</option>
                <option value="credit">آجل</option>
                <option value="online">أونلاين</option>
            </select>
        </div>
        <div style="grid-column:1/-1;">
            <label for="mo_paid">مدفوع الآن (اختياري — يظهر على الفاتورة)</label>
            <input type="number" id="mo_paid" class="admin-inp-money" step="0.001" min="0" value="0" lang="en" dir="ltr" placeholder="0">
        </div>
    </div>
</div>

<div class="card mo-invoice-card">
    <div class="mo-invoice-doc-head">
        <h3 class="mo-invoice-doc-head__title">بنود الفاتورة</h3>
        <span class="mo-invoice-doc-head__badge">مسودة قبل الحفظ</span>
    </div>
    <p class="card-hint" style="margin-top:0;">أدخل <strong>كود الصنف أو الباركود</strong> واضغط Enter أو استخدم أيقونة <strong>البحث</strong> ثم <strong>دبل كليك</strong> على الصف. في نافذة البحث تظهر <strong>إجمالي المخزون</strong> و<strong>المحجوز</strong> (طلبات ويب معلّقة) و<strong>الصافي المتاح</strong>. عدّل الكمية أو الخصم — <strong>صافي سعر الصنف</strong> يتحدّث تلقائياً.</p>
    <?php if ($products === []): ?>
        <p class="mo-invoice-alert">لا توجد منتجات نشطة — <strong>لا يمكن حفظ فاتورة المبيعات</strong> حتى تُضاف منتجات من شاشة «المنتجات».</p>
    <?php endif; ?>
    <div class="admin-doc-frame mo-invoice-frame">
        <div class="table-wrap">
            <table class="admin-table admin-doc-lines-table mo-lines-table">
                <thead>
                    <tr>
                        <th class="mo-col-idx">#</th>
                        <th class="mo-th-code">كود / باركود</th>
                        <th class="mo-th-name">اسم الصنف</th>
                        <th class="mo-th-var">اللون / المقاس</th>
                        <th class="mo-col-qty-h">الكمية</th>
                        <th class="mo-col-money-h">سعر الوحدة</th>
                        <th class="mo-col-money-h">الخصم</th>
                        <th class="mo-col-net-h">صافي السطر</th>
                        <th class="mo-col-del-h" aria-label="حذف السطر"></th>
                    </tr>
                </thead>
                <tbody id="mo_lines_body"></tbody>
            </table>
        </div>
    </div>
    <div class="mo-invoice-summary" id="mo_invoice_summary" aria-live="polite">
        <div class="mo-invoice-summary__grid">
            <div class="mo-invoice-summary__cell">
                <span class="mo-invoice-summary__label">بنود مُدخَلة</span>
                <span class="mo-invoice-summary__val" id="mo_live_count">0</span>
            </div>
            <div class="mo-invoice-summary__cell">
                <span class="mo-invoice-summary__label">المجموع قبل الخصم</span>
                <span class="mo-invoice-summary__val" id="mo_live_gross">0.000</span>
                <span class="mo-invoice-summary__unit">KD</span>
            </div>
            <div class="mo-invoice-summary__cell">
                <span class="mo-invoice-summary__label">مجموع الخصومات</span>
                <span class="mo-invoice-summary__val" id="mo_live_discsum">0.000</span>
                <span class="mo-invoice-summary__unit">KD</span>
            </div>
            <div class="mo-invoice-summary__cell mo-invoice-summary__cell--net">
                <span class="mo-invoice-summary__label">صافي الفاتورة</span>
                <span class="mo-invoice-summary__val mo-invoice-summary__val--net" id="mo_live_net">0.000</span>
                <span class="mo-invoice-summary__unit">KD</span>
            </div>
        </div>
        <p class="mo-invoice-summary__hint" id="mo_live_paid_hint"></p>
    </div>
    <div class="actions admin-doc-lines-toolbar" style="margin-top:12px;">
        <button type="button" class="btn-secondary" id="mo_btn_addline" onclick="moAddLine()" <?php echo $products === [] ? 'disabled' : ''; ?>>+ سطر</button>
        <button type="button" id="mo_btn_save" onclick="moSubmit()" <?php echo $products === [] ? 'disabled' : ''; ?>>حفظ وتسجيل الفاتورة</button>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=orders'), ENT_QUOTES, 'UTF-8'); ?>">الطلبات</a>
    </div>
</div>

<div id="mo_pick_modal" class="mo-pick-modal" hidden>
    <div class="mo-pick-modal__backdrop" id="mo_pick_backdrop"></div>
    <div class="mo-pick-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="mo_pick_title">
        <h4 id="mo_pick_title" class="mo-pick-modal__title">اختيار صنف</h4>
        <input type="search" id="mo_pick_filter" class="admin-inp mo-pick-modal__search" placeholder="ابحث بالكود أو الاسم أو اللون أو المقاس…" autocomplete="off" lang="ar" dir="rtl">
        <div class="mo-pick-modal__scroller table-wrap">
            <table class="admin-table mo-pick-table">
                <thead>
                    <tr>
                        <th>الكود</th>
                        <th>الباركود</th>
                        <th>الاسم</th>
                        <th>اللون</th>
                        <th>المقاس</th>
                        <th class="mo-pick-num-h">إجمالي</th>
                        <th class="mo-pick-num-h">محجوز</th>
                        <th class="mo-pick-num-h">صافي</th>
                        <th class="mo-pick-num-h">سعر</th>
                    </tr>
                </thead>
                <tbody id="mo_pick_body"></tbody>
            </table>
        </div>
        <p class="card-hint mo-pick-modal__hint">انقر نقراً مزدوجاً على السطر لاختيار الصنف وإغلاق القائمة.</p>
    </div>
</div>

<script>
var MO_PICK_ROWS = <?php echo json_encode($pickRows, JSON_UNESCAPED_UNICODE); ?>;

function moNormCodeKey(s) {
    s = String(s || '').trim();
    return s.replace(/[A-Z]/g, function (ch) {
        return ch.toLowerCase();
    });
}

var MO_PICK_BY_CODE = {};
(function () {
    function reg(row, rawKey) {
        var k = moNormCodeKey(rawKey);
        if (!k || MO_PICK_BY_CODE[k]) {
            return;
        }
        MO_PICK_BY_CODE[k] = row;
    }
    for (var i = 0; i < MO_PICK_ROWS.length; i++) {
        var r = MO_PICK_ROWS[i];
        reg(r, r.code);
        if (r.barcode) {
            reg(r, r.barcode);
        }
    }
})();

var moPickTargetRow = null;

function moEsc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
}

function moFmtKd(n) {
    var x = Number(n);
    if (!isFinite(x)) {
        x = 0;
    }
    return x.toFixed(3);
}

function moParseMoneyEl(el) {
    if (!el) {
        return 0;
    }
    return parseFloat(String(el.value || '0').replace(',', '.')) || 0;
}

function moVarLabel(row) {
    var c = row.color || '';
    var z = row.size || '';
    if (c && z) {
        return c + ' / ' + z;
    }
    return c || z || '—';
}

function moClearLine(tr) {
    tr.querySelector('.mo-code').value = '';
    tr.querySelector('.mo-product-id').value = '';
    tr.querySelector('.mo-variant-id').value = '';
    tr.querySelector('.mo-name').value = '';
    tr.querySelector('.mo-var-label').value = '';
    tr.querySelector('.mo-price').value = moFmtKd(0);
    tr.querySelector('.mo-disc').value = moFmtKd(0);
    tr.querySelector('.mo-line-net').value = moFmtKd(0);
}

function moApplyPick(tr, row) {
    tr.querySelector('.mo-code').value = row.code;
    tr.querySelector('.mo-product-id').value = String(row.product_id);
    tr.querySelector('.mo-variant-id').value = String(row.variant_id || '');
    tr.querySelector('.mo-name').value = row.name;
    tr.querySelector('.mo-var-label').value = moVarLabel(row);
    tr.querySelector('.mo-price').value = moFmtKd(row.price);
    moUpdateLineNet(tr);
}

function moUpdateLineNet(tr) {
    var netEl = tr.querySelector('.mo-line-net');
    if (!netEl) {
        return;
    }
    var pid = parseInt(tr.querySelector('.mo-product-id').value, 10) || 0;
    if (pid <= 0) {
        netEl.value = moFmtKd(0);
        return;
    }
    var q = parseInt(tr.querySelector('.mo-q').value, 10) || 0;
    var up = moParseMoneyEl(tr.querySelector('.mo-price'));
    var disc = moParseMoneyEl(tr.querySelector('.mo-disc'));
    if (disc < 0) {
        disc = 0;
    }
    var ln = Math.max(0, q * up - disc);
    netEl.value = moFmtKd(ln);
}

function moResolveCodeForRow(tr) {
    var raw = tr.querySelector('.mo-code').value.trim();
    if (raw === '') {
        moClearLine(tr);
        moSyncTrailingRows();
        moRecalcTotals();
        return;
    }
    var row = MO_PICK_BY_CODE[moNormCodeKey(raw)];
    if (row) {
        moApplyPick(tr, row);
        moSyncTrailingRows();
        moRecalcTotals();
        return;
    }
    alert('كود أو باركود غير معروف: ' + raw);
}

function moRenumberRows() {
    var tb = document.getElementById('mo_lines_body');
    if (!tb) {
        return;
    }
    var rows = tb.querySelectorAll('tr');
    for (var i = 0; i < rows.length; i++) {
        var c = rows[i].querySelector('.mo-col-idx');
        if (c) {
            c.textContent = String(i + 1);
        }
    }
}

function moRecalcTotals() {
    var tb = document.getElementById('mo_lines_body');
    var gross = 0;
    var discsum = 0;
    var net = 0;
    var count = 0;
    if (tb) {
        var rows = tb.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            moUpdateLineNet(r);
            var pid = parseInt(r.querySelector('.mo-product-id').value, 10) || 0;
            var q = parseInt(r.querySelector('.mo-q').value, 10) || 0;
            var disc = moParseMoneyEl(r.querySelector('.mo-disc'));
            if (pid <= 0 || q < 1) {
                continue;
            }
            var up = moParseMoneyEl(r.querySelector('.mo-price'));
            var lg = q * up;
            gross += lg;
            discsum += disc;
            var ln = Math.max(0, lg - disc);
            net += ln;
            count++;
        }
    }
    var elG = document.getElementById('mo_live_gross');
    var elD = document.getElementById('mo_live_discsum');
    var elN = document.getElementById('mo_live_net');
    var elC = document.getElementById('mo_live_count');
    if (elG) {
        elG.textContent = moFmtKd(gross);
    }
    if (elD) {
        elD.textContent = moFmtKd(discsum);
    }
    if (elN) {
        elN.textContent = moFmtKd(net);
    }
    if (elC) {
        elC.textContent = String(count);
    }
    var hint = document.getElementById('mo_live_paid_hint');
    var paidEl = document.getElementById('mo_paid');
    var paid = paidEl ? moParseMoneyEl(paidEl) : 0;
    if (paid < 0) {
        paid = 0;
    }
    if (hint) {
        if (paid > 0) {
            var bal = net - paid;
            hint.textContent =
                'المدفوع الآن: ' +
                moFmtKd(paid) +
                ' KD — الباقي بعد المدفوع: ' +
                moFmtKd(bal) +
                ' KD (يُثبَّت رسمياً عند الحفظ).';
        } else {
            hint.textContent = '';
        }
    }
}

function moLineRowHtml() {
    return (
        '<td class="mo-col-idx"></td>' +
        '<td class="mo-cell-code">' +
        '<div class="mo-code-wrap">' +
        '<input type="text" class="mo-code admin-inp" autocomplete="off" placeholder="كود أو باركود" dir="ltr" lang="en">' +
        '<button type="button" class="mo-code-search" title="بحث عن صنف" aria-label="بحث عن صنف">' +
        '<svg class="mo-icon mo-icon--search" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>' +
        '</button>' +
        '</div>' +
        '<input type="hidden" class="mo-product-id" value="">' +
        '<input type="hidden" class="mo-variant-id" value="">' +
        '</td>' +
        '<td class="mo-cell-name"><input type="text" class="mo-name admin-inp" readonly tabindex="-1" placeholder="—"></td>' +
        '<td class="mo-cell-var"><input type="text" class="mo-var-label admin-inp" readonly tabindex="-1" placeholder="—"></td>' +
        '<td class="mo-col-qty"><input type="number" class="mo-q admin-inp-qty" min="1" step="1" value="1" inputmode="numeric" lang="en" dir="ltr"></td>' +
        '<td class="mo-col-money"><input type="text" class="mo-price admin-inp-money" data-money-allow-zero readonly tabindex="-1" inputmode="decimal" lang="en" dir="ltr" placeholder="0.000" value="0.000"></td>' +
        '<td class="mo-col-money"><input type="text" class="mo-disc admin-inp-money" data-money-allow-zero data-money-allow-negative inputmode="decimal" lang="en" dir="ltr" placeholder="0.000" value="0.000"></td>' +
        '<td class="mo-col-net"><input type="text" class="mo-line-net" readonly tabindex="-1" value="0.000" lang="en" dir="ltr"></td>' +
        '<td class="mo-col-del"><button type="button" class="mo-row-delete" onclick="moRemoveRow(this)" title="حذف السطر" aria-label="حذف السطر">' +
        '<svg class="mo-icon mo-icon--trash" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>' +
        '</button></td>'
    );
}

function moAddLine() {
    var tb = document.getElementById('mo_lines_body');
    if (!tb) {
        return;
    }
    var tr = document.createElement('tr');
    tr.innerHTML = moLineRowHtml();
    tb.appendChild(tr);
    moRenumberRows();
    moRecalcTotals();
}

function moRemoveRow(btn) {
    var tb = document.getElementById('mo_lines_body');
    if (!tb) {
        return;
    }
    if (tb.querySelectorAll('tr').length <= 1) {
        var tr = btn.closest('tr');
        moClearLine(tr);
        tr.querySelector('.mo-q').value = '1';
        moSyncTrailingRows();
        return;
    }
    btn.closest('tr').remove();
    moRenumberRows();
    moSyncTrailingRows();
}

function moRowIsBlank(tr) {
    var pid = parseInt(tr.querySelector('.mo-product-id').value, 10) || 0;
    var q = parseInt(tr.querySelector('.mo-q').value, 10) || 0;
    if (q < 1) {
        return true;
    }
    return pid <= 0;
}

function moTrimExtraTrailingBlanks() {
    var tb = document.getElementById('mo_lines_body');
    if (!tb) {
        return;
    }
    for (;;) {
        var rows = tb.querySelectorAll('tr');
        if (rows.length < 2) {
            return;
        }
        var a = rows[rows.length - 2];
        var b = rows[rows.length - 1];
        if (moRowIsBlank(a) && moRowIsBlank(b)) {
            a.remove();
            moRenumberRows();
        } else {
            return;
        }
    }
}

function moSyncTrailingRows() {
    moTrimExtraTrailingBlanks();
    var tb = document.getElementById('mo_lines_body');
    if (!tb) {
        return;
    }
    var rows = tb.querySelectorAll('tr');
    if (rows.length === 0) {
        moAddLine();
        return;
    }
    var last = rows[rows.length - 1];
    if (!moRowIsBlank(last)) {
        moAddLine();
    }
}

function moRenderPickTable(filterText) {
    var body = document.getElementById('mo_pick_body');
    if (!body) {
        return;
    }
    var t = String(filterText || '').trim().toLowerCase();
    body.innerHTML = '';
    for (var i = 0; i < MO_PICK_ROWS.length; i++) {
        var r = MO_PICK_ROWS[i];
        if (t) {
            var hay = (
                r.code +
                ' ' +
                (r.barcode || '') +
                ' ' +
                r.name +
                ' ' +
                (r.color || '') +
                ' ' +
                (r.size || '')
            ).toLowerCase();
            if (hay.indexOf(t) === -1) {
                continue;
            }
        }
        var tr = document.createElement('tr');
        tr.className = 'mo-pick-row';
        tr.setAttribute('data-pick-idx', String(i));
        var bc = r.barcode ? moEsc(r.barcode) : '—';
        var st = typeof r.stock_total === 'number' ? r.stock_total : 0;
        var sr = typeof r.stock_reserved === 'number' ? r.stock_reserved : 0;
        var sa = typeof r.stock_available === 'number' ? r.stock_available : 0;
        tr.innerHTML =
            '<td>' + moEsc(r.code) + '</td>' +
            '<td dir="ltr">' + bc + '</td>' +
            '<td>' + moEsc(r.name) + '</td>' +
            '<td>' + moEsc(r.color || '') + '</td>' +
            '<td>' + moEsc(r.size || '') + '</td>' +
            '<td class="mo-pick-num" dir="ltr">' +
            st +
            '</td>' +
            '<td class="mo-pick-num" dir="ltr">' +
            sr +
            '</td>' +
            '<td class="mo-pick-num" dir="ltr">' +
            sa +
            '</td>' +
            '<td class="mo-pick-num" dir="ltr">' +
            moFmtKd(r.price) +
            '</td>';
        body.appendChild(tr);
    }
}

function moOpenPick(tr) {
    moPickTargetRow = tr;
    var modal = document.getElementById('mo_pick_modal');
    var fil = document.getElementById('mo_pick_filter');
    if (!modal) {
        return;
    }
    if (fil) {
        fil.value = '';
    }
    moRenderPickTable('');
    modal.removeAttribute('hidden');
    if (fil) {
        setTimeout(function () {
            fil.focus();
        }, 0);
    }
}

function moClosePick() {
    moPickTargetRow = null;
    var modal = document.getElementById('mo_pick_modal');
    if (modal) {
        modal.setAttribute('hidden', '');
    }
}

function moBindPickModal() {
    var modal = document.getElementById('mo_pick_modal');
    var bd = document.getElementById('mo_pick_backdrop');
    var fil = document.getElementById('mo_pick_filter');
    var body = document.getElementById('mo_pick_body');
    if (!modal || !body) {
        return;
    }
    if (bd) {
        bd.addEventListener('click', moClosePick);
    }
    if (fil) {
        fil.addEventListener('input', function () {
            moRenderPickTable(fil.value);
        });
    }
    body.addEventListener('dblclick', function (e) {
        var tr = e.target.closest('tr[data-pick-idx]');
        if (!tr || !moPickTargetRow) {
            return;
        }
        var idx = parseInt(tr.getAttribute('data-pick-idx'), 10);
        if (idx < 0 || idx >= MO_PICK_ROWS.length) {
            return;
        }
        moApplyPick(moPickTargetRow, MO_PICK_ROWS[idx]);
        moClosePick();
        moSyncTrailingRows();
        moRecalcTotals();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hasAttribute('hidden')) {
            moClosePick();
        }
    });
}

function moBindLinesBody() {
    var tb = document.getElementById('mo_lines_body');
    if (!tb || tb.getAttribute('data-mo-bound') === '1') {
        return;
    }
    tb.setAttribute('data-mo-bound', '1');
    tb.addEventListener('click', function (e) {
        var btn = e.target.closest('.mo-code-search');
        if (!btn) {
            return;
        }
        var tr = btn.closest('tr');
        if (tr && tr.parentElement === tb) {
            moOpenPick(tr);
        }
    });
    tb.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') {
            return;
        }
        var inp = e.target;
        if (!inp || !inp.classList || !inp.classList.contains('mo-code')) {
            return;
        }
        e.preventDefault();
        moResolveCodeForRow(inp.closest('tr'));
    });
    tb.addEventListener('change', function () {
        moSyncTrailingRows();
        moRecalcTotals();
    });
    tb.addEventListener('input', function () {
        moSyncTrailingRows();
        moRecalcTotals();
    });
    tb.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab' || e.shiftKey) {
            return;
        }
        var ta = e.target;
        if (!ta || !ta.classList || !ta.classList.contains('mo-disc')) {
            return;
        }
        var tr = ta.closest('tr');
        if (!tr || tr.parentElement !== tb) {
            return;
        }
        var rows = tb.querySelectorAll('tr');
        if (tr !== rows[rows.length - 1]) {
            return;
        }
        e.preventDefault();
        moSyncTrailingRows();
        var rows2 = tb.querySelectorAll('tr');
        var next = rows2[rows2.length - 1];
        var codeInp = next && next.querySelector('.mo-code');
        if (codeInp) {
            codeInp.focus();
        }
    });
}

function moSubmit() {
    var name = document.getElementById('mo_name').value.trim();
    var phone = document.getElementById('mo_phone').value.trim();
    var channel = parseInt(document.getElementById('mo_channel').value, 10) || 0;
    if (!name || !phone) {
        alert('الاسم والهاتف مطلوبان');
        return;
    }
    if (!channel) {
        alert('اختر قناة');
        return;
    }
    var tb = document.getElementById('mo_lines_body');
    if (!tb) {
        alert('لا يوجد جدول بنود');
        return;
    }
    var rows = tb.querySelectorAll('tr');
    var items = [];
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var pid = parseInt(r.querySelector('.mo-product-id').value, 10) || 0;
        var q = parseInt(r.querySelector('.mo-q').value, 10) || 0;
        var disc = parseFloat(String((r.querySelector('.mo-disc') && r.querySelector('.mo-disc').value) || '0').replace(',', '.')) || 0;
        var vid = parseInt(r.querySelector('.mo-variant-id').value, 10) || 0;
        if (pid <= 0 || q < 1) {
            continue;
        }
        var o = { product_id: pid, qty: q, line_discount: disc };
        if (vid) {
            o.variant_id = vid;
        }
        items.push(o);
    }
    if (!items.length) {
        alert('أضف سطراً واحداً على الأقل وحدّد صنفاً (كود) لكل سطر');
        return;
    }
    var paidEl = document.getElementById('mo_paid');
    var paid = paidEl ? parseFloat(String(paidEl.value || '0').replace(',', '.')) || 0 : 0;
    if (paid < 0) {
        paid = 0;
    }
    postJSON('/admin/api/orders/create-manual.php', {
        customer_name: name,
        phone: phone,
        area: document.getElementById('mo_area').value.trim(),
        address: document.getElementById('mo_address').value.trim(),
        notes: document.getElementById('mo_notes').value.trim(),
        channel_id: channel,
        payment_terms: document.getElementById('mo_payment_terms').value || 'cash',
        amount_paid: paid,
        items: items
    }).then(function (res) {
        alert(res.message || (res.success ? 'تم' : 'فشل'));
        if (res.success && res.order_id) {
            var __pub = typeof window.ORANGE_PUBLIC_BASE_PATH === 'string' ? window.ORANGE_PUBLIC_BASE_PATH.replace(/\/+$/, '') : '';
            location.href = __pub + '/admin/index.php?page=invoice&order_id=' + encodeURIComponent(String(res.order_id));
        }
    });
}

moBindPickModal();
moAddLine();
moBindLinesBody();
moSyncTrailingRows();
(function () {
    var mp = document.getElementById('mo_paid');
    if (mp) {
        mp.addEventListener('input', moRecalcTotals);
        mp.addEventListener('change', moRecalcTotals);
    }
})();
</script>
