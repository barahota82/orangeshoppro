<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_taxonomy_migrate.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$purUnifiedCatalogGrouping =
    function_exists('orange_catalog_nav_use_unified')
    && orange_catalog_nav_use_unified($pdo)
    && orange_table_exists($pdo, 'product_types')
    && orange_table_has_column($pdo, 'products', 'product_type_id');

if ($purUnifiedCatalogGrouping) {
    try {
        $products = $pdo->query(
            'SELECT p.id, p.name, p.cost, p.has_colors, p.has_sizes,
                COALESCE(
                    NULLIF(TRIM(ucc.name_ar), \'\'),
                    NULLIF(TRIM(ucc.name_en), \'\'),
                    \'غير مرتبط بفئة الشجرة الموحّدة\'
                ) AS catalog_group_label
             FROM products p
             LEFT JOIN product_types pt ON pt.id = p.product_type_id
             LEFT JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id
             LEFT JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id
             WHERE p.is_active = 1
             ORDER BY catalog_group_label ASC, p.sort_order ASC, p.name ASC, p.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $products = $pdo->query(
            'SELECT id, name, cost, has_colors, has_sizes FROM products WHERE is_active = 1 ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($products as &$p) {
            $p['catalog_group_label'] = '';
        }
        unset($p);
        $purUnifiedCatalogGrouping = false;
    }
} else {
    $products = $pdo->query(
        'SELECT id, name, cost, has_colors, has_sizes FROM products WHERE is_active = 1 ORDER BY name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($products as &$p) {
        $p['catalog_group_label'] = '';
    }
    unset($p);
}

$hasSupplierIsActive = orange_table_has_column($pdo, 'suppliers', 'is_active');
$hasSupplierIsBlocked = orange_table_has_column($pdo, 'suppliers', 'is_blocked');
$suppliers = $pdo->query(
    ($hasSupplierIsActive || $hasSupplierIsBlocked)
        ? (
            'SELECT id, name, phone, '
            . ($hasSupplierIsActive ? 'is_active' : '1 AS is_active') . ', '
            . ($hasSupplierIsBlocked ? 'is_blocked' : '0 AS is_blocked')
            . ' FROM suppliers ORDER BY '
            . ($hasSupplierIsActive ? 'is_active DESC, ' : '')
            . ($hasSupplierIsBlocked ? 'is_blocked ASC, ' : '')
            . 'name ASC'
        )
        : 'SELECT id, name, phone, 1 AS is_active, 0 AS is_blocked FROM suppliers ORDER BY name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$variantsByProduct = [];
$vRows = $pdo->query(
    'SELECT id, product_id, color, size FROM product_variants ORDER BY product_id ASC, id ASC'
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($vRows as $vr) {
    $pid = (int) $vr['product_id'];
    if (!isset($variantsByProduct[$pid])) {
        $variantsByProduct[$pid] = [];
    }
    $c = trim((string) ($vr['color'] ?? ''));
    $s = trim((string) ($vr['size'] ?? ''));
    $label = ($c !== '' || $s !== '')
        ? trim($c . ($c !== '' && $s !== '' ? ' / ' : '') . $s)
        : ('#' . (int) $vr['id']);
    $variantsByProduct[$pid][] = [
        'id' => (int) $vr['id'],
        'label' => $label,
    ];
}

$recent = $pdo->query(
    'SELECT p.*, s.name AS supplier_name,
            (SELECT COALESCE(SUM(pi.qty), 0) FROM purchase_items pi WHERE pi.purchase_id = p.id) AS items_qty_sum,
            (SELECT COALESCE(SUM(pi.qty_received), 0) FROM purchase_items pi WHERE pi.purchase_id = p.id) AS items_received_sum
     FROM purchases p
     LEFT JOIN suppliers s ON s.id = p.supplier_id
     ORDER BY p.id DESC
     LIMIT 50'
)->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>فاتورة شراء</h1>
        <p class="page-subtitle">تسجيل مشتريات نقدي أو آجل؛ <strong>حفظ فاتورة الشراء يعني أن المخزن استلم الكميات بالكامل</strong> — تُزاد كميات <strong>المتغيرات</strong> فور الحفظ ويُرحَّل القيد على <strong>حساب المخزون</strong> (حسب «حسابات القيود التلقائية»). لا استلام لاحق ولا جزئي. مردود المشتريات من شاشة مردود المشتريات.</p>
    </div>
</div>

<div class="card" id="pur_edit_banner" hidden>
    <p class="card-hint" style="margin:0 0 10px;"><strong>وضع التعديل</strong> — فاتورة <span id="pur_edit_banner_id"></span>. الحفظ يعكس زيادة المخزون السابقة لهذه الفاتورة ثم يطبّق البنود الجديدة.</p>
    <button type="button" class="btn-secondary" onclick="purCancelEdit()">إلغاء التعديل</button>
</div>

<div class="card">
    <h2 class="card-title">بيانات الفاتورة</h2>
    <p class="card-hint">الآجل يُرحَّل على ذمم الموردين؛ النقدي يُقابل الصندوق/البنك حسب «حسابات القيود التلقائية». مردود المشتريات: <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=purchase_returns'), ENT_QUOTES, 'UTF-8'); ?>">مردود المشتريات</a> (PDN).</p>
    <div class="form-grid">
        <div>
            <label>المورد (اختياري)</label>
            <select id="pur_supplier">
                <option value="0">— بدون مورد محدد —</option>
                <?php foreach ($suppliers as $s): ?>
                    <?php
                    $supIsActive = (int) ($s['is_active'] ?? 1) === 1;
                    $supIsBlocked = (int) ($s['is_blocked'] ?? 0) === 1;
                    $supLabel = (string) ($s['name'] ?? '');
                    if (!$supIsActive) {
                        $supLabel .= ' (غير نشط)';
                    }
                    if ($supIsBlocked) {
                        $supLabel .= ' (محظور)';
                    }
                    ?>
                    <option value="<?php echo (int)$s['id']; ?>" <?php echo ($supIsActive && !$supIsBlocked) ? '' : 'disabled'; ?>>
                        <?php echo htmlspecialchars($supLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>نوع الشراء</label>
            <select id="pur_type">
                <option value="cash">نقدي</option>
                <option value="credit">آجل</option>
            </select>
        </div>
        <div style="grid-column:1/-1;">
            <label>ملاحظات</label>
            <input type="text" id="pur_notes" placeholder="رقم فاتورة المورد، شروط، …">
        </div>
    </div>
</div>

<div class="card">
    <h2 class="card-title">أسطر الأصناف</h2>
    <?php if ($products === []): ?>
        <p class="card-hint">لا توجد منتجات نشطة لإضافتها. أنشئ منتجات من «المنتجات» أولًا.</p>
    <?php else: ?>
    <p class="card-hint" style="margin-top:0;">بنود الفاتورة في جدول داخل إطار واحد؛ بعد اختيار صنف وكمية يُفتح سطر جديد تلقائياً. <kbd class="admin-kbd">Tab</kbd> من «تكلفة الوحدة» لسطر جديد؛ <kbd class="admin-kbd">←</kbd> <kbd class="admin-kbd">→</kbd> <kbd class="admin-kbd">↑</kbd> <kbd class="admin-kbd">↓</kbd> للتنقل بين الخلايا. <?php echo $purUnifiedCatalogGrouping ? 'قائمة الأصناف مُجمَّعة حسب <strong>فئة الشجرة الموحّدة</strong> المستنتجة من نوع المنتج لكل صنف.' : ''; ?></p>
    <div class="admin-doc-frame">
        <div class="table-wrap">
            <table class="admin-table admin-doc-lines-table pur-lines-table">
                <thead>
                    <tr>
                        <th class="pur-col-idx">#</th>
                        <th>الصنف</th>
                        <th>المتغير (لون / مقاس)</th>
                        <th>الكمية</th>
                        <th>تكلفة الوحدة</th>
                        <th class="admin-doc-col-actions" aria-label="حذف السطر"></th>
                    </tr>
                </thead>
                <tbody id="pur_lines_body"></tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    <div class="actions admin-doc-lines-toolbar" style="margin-top:12px;">
        <button type="button" class="btn-secondary" onclick="purAddLine()">+ سطر</button>
        <button type="button" id="pur_submit_btn" onclick="purSubmit()">حفظ فاتورة الشراء</button>
    </div>
    <p class="card-hint" style="margin-top:12px;margin-bottom:0;"><strong>المجموع المحسوب:</strong> <span id="pur_total_preview">0.00</span> KD — عند الحفظ تُحدَّث كميات المخزن تلقائياً بالكامل.</p>
</div>

<div class="card">
    <h2 class="card-title">آخر فواتير الشراء</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>التاريخ</th>
                    <th>المورد</th>
                    <th>النوع</th>
                    <th>الإجمالي</th>
                    <th>الكميات (مُسجَّلة / مُطلوبة)</th>
                    <th>ملاحظات</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $r): ?>
                <?php
                    $pq = (int) ($r['items_qty_sum'] ?? 0);
                    $pr = (int) ($r['items_received_sum'] ?? 0);
                ?>
                <tr>
                    <td><?php echo (int)$r['id']; ?></td>
                    <td><?php echo htmlspecialchars(orange_format_datetime_dmY_hi((string) ($r['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['supplier_name'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo ($r['type'] ?? '') === 'credit' ? 'آجل' : 'نقدي'; ?></td>
                    <td><?php echo number_format((float)($r['total'] ?? 0), 2); ?> KD</td>
                    <td><?php echo (int) $pr; ?> / <?php echo (int) $pq; ?></td>
                    <td><?php echo htmlspecialchars((string)($r['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <button type="button" class="btn-secondary" style="margin-left:6px;" onclick="purEdit(<?php echo (int)$r['id']; ?>)">تعديل</button>
                        <button type="button" class="btn-danger" onclick="purDelete(<?php echo (int)$r['id']; ?>)">حذف</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
var PUR_PRODUCTS = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE); ?>;
var PUR_VARIANTS_BY_PID = <?php echo json_encode($variantsByProduct, JSON_UNESCAPED_UNICODE); ?>;
var PUR_GROUP_BY_UNIFIED_CAT = <?php echo $purUnifiedCatalogGrouping ? 'true' : 'false'; ?>;


function purEsc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
}

function purProductOptionsHtml() {
    var blank = '<option value="" data-cost="0">' + purEsc('— اختر صنفاً —') + '</option>';
    var optHtml = '';
    PUR_PRODUCTS.forEach(function (p) {
        optHtml +=
            '<option value="' +
            p.id +
            '" data-cost="' +
            String(parseFloat(p.cost) || 0) +
            '">' +
            purEsc(p.name) +
            '</option>';
    });
    if (typeof PUR_GROUP_BY_UNIFIED_CAT === 'undefined' || !PUR_GROUP_BY_UNIFIED_CAT) {
        return blank + optHtml;
    }
    var buckets = {};
    var orderKeys = [];
    PUR_PRODUCTS.forEach(function (p) {
        var k = String((p.catalog_group_label || '').trim() || 'غير مصنَّف بالشجرة الموحَّدة');
        if (!Object.prototype.hasOwnProperty.call(buckets, k)) {
            buckets[k] = [];
            orderKeys.push(k);
        }
        buckets[k].push(p);
    });
    var chunks = '';
    orderKeys.forEach(function (gk) {
        chunks += '<optgroup label="' + purEsc(gk) + '">';
        buckets[gk].forEach(function (p) {
            chunks +=
                '<option value="' +
                p.id +
                '" data-cost="' +
                String(parseFloat(p.cost) || 0) +
                '">' +
                purEsc(p.name) +
                '</option>';
        });
        chunks += '</optgroup>';
    });
    return blank + chunks;
}

function purRenumberRows() {
    var tb = document.getElementById('pur_lines_body');
    if (!tb) {
        return;
    }
    var rows = tb.querySelectorAll('tr');
    for (var i = 0; i < rows.length; i++) {
        var c = rows[i].querySelector('.pur-col-idx');
        if (c) {
            c.textContent = String(i + 1);
        }
    }
}

function purAddLine() {
    var tb = document.getElementById('pur_lines_body');
    if (!tb) {
        return;
    }
    var tr = document.createElement('tr');
    tr.className = 'pur-line';
    tr.innerHTML =
        '<td class="pur-col-idx"></td>' +
        '<td><select class="pur-p" style="min-width:12rem;">' + purProductOptionsHtml() + '</select></td>' +
        '<td class="pur-v-cell"><select class="pur-v"></select></td>' +
        '<td><input type="number" class="pur-q admin-inp-qty" min="1" step="1" value="1" inputmode="numeric" lang="en" dir="ltr"></td>' +
        '<td><input type="number" class="pur-c admin-inp-money" min="0" step="any" value="0" inputmode="decimal" lang="en" dir="ltr"></td>' +
        '<td><button type="button" class="btn-secondary admin-doc-line-remove" onclick="purRemoveRow(this)">حذف</button></td>';
    tb.appendChild(tr);
    purRenumberRows();
    var sel = tr.querySelector('.pur-p');
    if (sel) {
        purLineChanged(sel);
    }
    purRecalcPreview();
}

function purRemoveRow(btn) {
    var tb = document.getElementById('pur_lines_body');
    if (!tb) {
        return;
    }
    if (tb.querySelectorAll('tr').length <= 1) {
        var tr = btn.closest('tr');
        tr.querySelector('.pur-p').value = '';
        tr.querySelector('.pur-q').value = '1';
        tr.querySelector('.pur-c').value = '0';
        purLineChanged(tr.querySelector('.pur-p'));
        purSyncTrailingRows();
        purRecalcPreview();
        return;
    }
    btn.closest('tr').remove();
    purRenumberRows();
    purSyncTrailingRows();
    purRecalcPreview();
}

function purRowIsBlank(tr) {
    var pid = parseInt(tr.querySelector('.pur-p').value, 10) || 0;
    var q = parseInt(tr.querySelector('.pur-q').value, 10) || 0;
    return pid <= 0 || q < 1;
}

function purTrimExtraTrailingBlanks() {
    var tb = document.getElementById('pur_lines_body');
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
        if (purRowIsBlank(a) && purRowIsBlank(b)) {
            a.remove();
            purRenumberRows();
        } else {
            return;
        }
    }
}

function purSyncTrailingRows() {
    purTrimExtraTrailingBlanks();
    var tb = document.getElementById('pur_lines_body');
    if (!tb) {
        return;
    }
    var rows = tb.querySelectorAll('tr');
    if (rows.length === 0) {
        purAddLine();
        return;
    }
    var last = rows[rows.length - 1];
    if (!purRowIsBlank(last)) {
        purAddLine();
    }
}

function purLineChanged(sel) {
    var row = sel.closest('tr');
    var pid = parseInt(sel.value, 10) || 0;
    var opt = sel.options[sel.selectedIndex];
    var c = opt ? parseFloat(opt.getAttribute('data-cost') || '0') : 0;
    var inp = row.querySelector('.pur-c');
    if (inp) {
        if (pid > 0 && !isNaN(c)) {
            inp.value = String(c);
        } else if (pid <= 0) {
            inp.value = '0';
        }
    }
    var vsel = row.querySelector('.pur-v');
    var vcell = row.querySelector('.pur-v-cell');
    if (vsel) {
        var list = (PUR_VARIANTS_BY_PID && PUR_VARIANTS_BY_PID[pid]) ? PUR_VARIANTS_BY_PID[pid] : [];
        vsel.innerHTML = list.length
            ? list.map(function (v) {
                return '<option value="' + v.id + '">' + purEsc(v.label) + '</option>';
            }).join('')
            : '<option value="0">— لا متغيرات —</option>';
    }
    if (vcell) {
        if (!pid || (PUR_VARIANTS_BY_PID[pid] || []).length === 0) {
            vcell.setAttribute('hidden', '');
        } else {
            vcell.removeAttribute('hidden');
        }
    }
    purRecalcPreview();
}

function purRecalcPreview() {
    var tb = document.getElementById('pur_lines_body');
    if (!tb) {
        return;
    }
    var rows = tb.querySelectorAll('tr.pur-line');
    var sum = 0;
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var q = parseInt(r.querySelector('.pur-q').value, 10) || 0;
        var c = parseFloat(r.querySelector('.pur-c').value) || 0;
        sum += q * c;
    }
    var el = document.getElementById('pur_total_preview');
    if (el) {
        el.textContent = sum.toFixed(2);
    }
}

function purBindLinesBody() {
    var tb = document.getElementById('pur_lines_body');
    if (!tb || tb.getAttribute('data-pur-bound') === '1') {
        return;
    }
    tb.setAttribute('data-pur-bound', '1');
    tb.addEventListener('change', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('pur-p')) {
            purLineChanged(e.target);
        }
        purSyncTrailingRows();
        purRecalcPreview();
    });
    tb.addEventListener('input', function () {
        purSyncTrailingRows();
        purRecalcPreview();
    });
    tb.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab' || e.shiftKey) {
            return;
        }
        var ta = e.target;
        if (!ta || !ta.classList || !ta.classList.contains('pur-c')) {
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
        purSyncTrailingRows();
        var rows2 = tb.querySelectorAll('tr');
        var next = rows2[rows2.length - 1];
        var sel = next && next.querySelector('.pur-p');
        if (sel) {
            sel.focus();
        }
    });
}

var PUR_EDIT_ID = 0;

function purSetEditUi() {
    var btn = document.getElementById('pur_submit_btn');
    var ban = document.getElementById('pur_edit_banner');
    if (btn) {
        btn.textContent = PUR_EDIT_ID ? 'حفظ التعديلات' : 'حفظ فاتورة الشراء';
    }
    if (ban) {
        ban.hidden = !PUR_EDIT_ID;
    }
}

function purMergeProductForEdit(it) {
    var pid = parseInt(it.product_id, 10) || 0;
    if (!pid) {
        return;
    }
    if (PUR_PRODUCTS.some(function (x) { return x.id === pid; })) {
        return;
    }
    PUR_PRODUCTS.push({
        id: pid,
        name: it.is_product_active ? it.product_name : (it.product_name + ' (غير نشط)'),
        cost: parseFloat(it.product_cost) || 0,
        has_colors: it.has_colors ? 1 : 0,
        has_sizes: it.has_sizes ? 1 : 0
    });
}

function purCancelEdit() {
    PUR_EDIT_ID = 0;
    var sup = document.getElementById('pur_supplier');
    var typ = document.getElementById('pur_type');
    var nte = document.getElementById('pur_notes');
    if (sup) {
        sup.value = '0';
    }
    if (typ) {
        typ.value = 'cash';
    }
    if (nte) {
        nte.value = '';
    }
    var tb = document.getElementById('pur_lines_body');
    if (tb) {
        tb.innerHTML = '';
        purAddLine();
        purSyncTrailingRows();
        purRecalcPreview();
    }
    var bid = document.getElementById('pur_edit_banner_id');
    if (bid) {
        bid.textContent = '';
    }
    purSetEditUi();
}

function purEdit(purchaseId) {
    var tb = document.getElementById('pur_lines_body');
    if (!tb) {
        alert('لا يمكن التعديل: لا توجد أصناف نشطة في النظام.');
        return;
    }
    getJSON('/admin/api/purchases/get.php?purchase_id=' + encodeURIComponent(String(purchaseId))).then(function (res) {
        if (!res.success) {
            if (!orangeAdminOfferSuggestOnFailure(res, 'فشل')) {
                alert(res.message || 'فشل');
            }
            return;
        }
        if (res.has_received_stock) {
            if (!confirm('هذه الفاتورة عليها استلام مخزون مُسجَّل. التعديل يعكس ذلك المخزون ويُصفّر أعمدة الاستلام ثم يعيد البنود كما في النموذج (قد تحتاج إعادة الاستلام لاحقاً). المتابعة؟')) {
                return;
            }
        }
        var p = res.purchase;
        var items = res.items || [];
        for (var m = 0; m < items.length; m++) {
            purMergeProductForEdit(items[m]);
        }
        PUR_EDIT_ID = parseInt(p.id, 10) || 0;
        var sup = document.getElementById('pur_supplier');
        var typ = document.getElementById('pur_type');
        var nte = document.getElementById('pur_notes');
        if (sup) {
            sup.value = String(p.supplier_id || 0);
        }
        if (typ) {
            typ.value = p.type === 'credit' ? 'credit' : 'cash';
        }
        if (nte) {
            nte.value = p.notes || '';
        }
        tb.innerHTML = '';
        for (var i = 0; i < items.length; i++) {
            purAddLine();
            var rows = tb.querySelectorAll('tr.pur-line');
            var tr = rows[rows.length - 1];
            var sel = tr.querySelector('.pur-p');
            if (sel) {
                sel.value = String(items[i].product_id);
                purLineChanged(sel);
            }
            var vsel = tr.querySelector('.pur-v');
            if (vsel) {
                vsel.value = String(items[i].variant_id || 0);
            }
            var qIn = tr.querySelector('.pur-q');
            var cIn = tr.querySelector('.pur-c');
            if (qIn) {
                qIn.value = String(items[i].qty);
            }
            if (cIn) {
                cIn.value = String(items[i].cost);
            }
        }
        purSyncTrailingRows();
        purRecalcPreview();
        var bid = document.getElementById('pur_edit_banner_id');
        if (bid) {
            bid.textContent = '#' + String(p.id);
        }
        purSetEditUi();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

function purSubmit() {
    var supplier = parseInt(document.getElementById('pur_supplier').value, 10) || 0;
    var type = document.getElementById('pur_type').value;
    var notes = document.getElementById('pur_notes').value.trim();
    var tb = document.getElementById('pur_lines_body');
    if (!tb) {
        alert('لا توجد أصناف');
        return;
    }
    var rows = tb.querySelectorAll('tr.pur-line');
    var items = [];
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var pid = parseInt(r.querySelector('.pur-p').value, 10) || 0;
        var vid = parseInt(r.querySelector('.pur-v').value, 10) || 0;
        var q = parseInt(r.querySelector('.pur-q').value, 10) || 0;
        var c = parseFloat(r.querySelector('.pur-c').value) || 0;
        if (!pid || q < 1) {
            continue;
        }
        items.push({ product_id: pid, variant_id: vid, qty: q, cost: c });
    }
    if (!items.length) {
        alert('أضف سطرًا واحدًا على الأقل بصنف وكمية صحيحة');
        return;
    }
    var url = '/admin/api/purchases/create.php';
    var payload = {
        supplier_id: supplier,
        type: type,
        notes: notes,
        items: items
    };
    if (PUR_EDIT_ID) {
        url = '/admin/api/purchases/update.php';
        payload.id = PUR_EDIT_ID;
        payload.action = 'update';
    }
    postJSON(url, payload).then(function (res) {
        if (res.success) {
            alert(res.message || (PUR_EDIT_ID ? 'تم تعديل فاتورة الشراء' : 'تم حفظ فاتورة الشراء'));
            location.reload();
            return;
        }
        if (!orangeAdminOfferSuggestOnFailure(res, 'فشل')) {
            alert(res.message || 'فشل');
        }
    });
}

function purDelete(id) {
    if (!confirm('حذف فاتورة الشراء هذه؟ سيتم عكس المخزون حسب الكميات المُسجَّلة على البنود، وحذف القيد المحاسبي المرتبط بمرجع PUR-' + id + '.')) return;
    postJSON('/admin/api/purchases/update.php', { id: id, action: 'delete' }).then(function (res) {
        if (res.success) {
            alert(res.message || 'تم الحذف');
            location.reload();
            return;
        }
        if (!orangeAdminOfferSuggestOnFailure(res, 'فشل')) {
            alert(res.message || 'فشل');
        }
    });
}

if (document.getElementById('pur_lines_body')) {
    purAddLine();
    purBindLinesBody();
    purSyncTrailingRows();
}

</script>
