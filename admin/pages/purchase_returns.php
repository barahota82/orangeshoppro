<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/date_format.php';
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

$suppliers = $pdo->query('SELECT id, name, phone FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

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
    'SELECT pr.*, s.name AS supplier_name
     FROM purchase_returns pr
     LEFT JOIN suppliers s ON s.id = pr.supplier_id
     ORDER BY pr.id DESC
     LIMIT 50'
)->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>مردود مشتريات</h1>
        <p class="page-subtitle">تسجيل إرجاع بضاعة للمورد؛ <strong>يُنقص المخزون فور الحفظ</strong> ويُولَّد قيداً بمرجع <code dir="ltr">PR-</code> حسب نوع اليومية <strong>PDN</strong> في «حسابات القيود التلقائية» (قسم ٢).</p>
    </div>
</div>

<div class="card" id="pr_edit_banner" hidden>
    <p class="card-hint" style="margin:0 0 10px;"><strong>وضع التعديل</strong> — مردود <span id="pr_edit_banner_id"></span>.</p>
    <button type="button" class="btn-secondary" onclick="prCancelEdit()">إلغاء التعديل</button>
</div>

<div class="card">
    <h2 class="card-title">بيانات المردود</h2>
    <div class="form-grid">
        <div>
            <label>المورد (اختياري للنقدي)</label>
            <select id="pr_supplier">
                <option value="0">— بدون مورد محدد —</option>
                <?php foreach ($suppliers as $s): ?>
                    <option value="<?php echo (int) $s['id']; ?>"><?php echo htmlspecialchars((string) $s['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>نوع المردود</label>
            <select id="pr_type">
                <option value="cash">نقدي</option>
                <option value="credit">آجل</option>
            </select>
        </div>
        <div>
            <label>مرجع فاتورة شراء (اختياري)</label>
            <input type="number" id="pr_purchase_id" min="0" step="1" placeholder="رقم فاتورة الشراء" lang="en" dir="ltr" class="admin-inp-qty">
        </div>
        <div style="grid-column:1/-1;">
            <label>ملاحظات</label>
            <input type="text" id="pr_notes" placeholder="رقم إذن الإرجاع، …">
        </div>
    </div>
</div>

<div class="card">
    <h2 class="card-title">أسطر الأصناف</h2>
    <?php if ($products === []): ?>
        <p class="card-hint">لا توجد منتجات نشطة.</p>
    <?php else: ?>
    <p class="card-hint" style="margin-top:0;">يُخصم من مخزون المتغير عند الحفظ؛ لا يُقبل سطراً تتجاوز فيه الكمية الرصيد الحالي. <?php echo $purUnifiedCatalogGrouping ? 'قائمة الأصناف مُجمَّعة حسب <strong>فئة الشجرة الموحّدة</strong> (من نوع المنتج).' : ''; ?></p>
    <div class="admin-doc-frame">
        <div class="table-wrap">
            <table class="admin-table admin-doc-lines-table">
                <thead>
                    <tr>
                        <th class="pur-col-idx">#</th>
                        <th>الصنف</th>
                        <th>المتغير</th>
                        <th>الكمية</th>
                        <th>تكلفة الوحدة</th>
                        <th class="admin-doc-col-actions" aria-label="حذف"></th>
                    </tr>
                </thead>
                <tbody id="pr_lines_body"></tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    <div class="actions admin-doc-lines-toolbar" style="margin-top:12px;">
        <button type="button" class="btn-secondary" onclick="prAddLine()">+ سطر</button>
        <button type="button" id="pr_submit_btn" onclick="prSubmit()">حفظ مردود المشتريات</button>
    </div>
    <p class="card-hint" style="margin-top:12px;margin-bottom:0;"><strong>المجموع:</strong> <span id="pr_total_preview">0.00</span> KD</p>
</div>

<div class="card">
    <h2 class="card-title">آخر مردودات المشتريات</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>المرجع</th>
                    <th>التاريخ</th>
                    <th>المورد</th>
                    <th>النوع</th>
                    <th>الإجمالي</th>
                    <th>شراء مرجعي</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $r): ?>
                <tr>
                    <td><?php echo (int) $r['id']; ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) ($r['return_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(orange_format_datetime_dmY_hi((string) ($r['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($r['supplier_name'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo ($r['type'] ?? '') === 'credit' ? 'آجل' : 'نقدي'; ?></td>
                    <td><?php echo number_format((float) ($r['total'] ?? 0), 2); ?> KD</td>
                    <td dir="ltr"><?php echo $r['purchase_id'] ? (int) $r['purchase_id'] : '—'; ?></td>
                    <td>
                        <button type="button" class="btn-secondary" onclick="prEdit(<?php echo (int) $r['id']; ?>)">تعديل</button>
                        <button type="button" class="btn-danger" onclick="prDelete(<?php echo (int) $r['id']; ?>)">حذف</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
var PR_PRODUCTS = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE); ?>;
var PR_VARIANTS_BY_PID = <?php echo json_encode($variantsByProduct, JSON_UNESCAPED_UNICODE); ?>;
var PR_GROUP_BY_UNIFIED_CAT = <?php echo $purUnifiedCatalogGrouping ? 'true' : 'false'; ?>;
var PR_EDIT_ID = 0;

function prEsc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
}

function prProductOptionsHtml() {
    var blank = '<option value="" data-cost="0">' + prEsc('— اختر صنفاً —') + '</option>';
    var optHtml = '';
    PR_PRODUCTS.forEach(function (p) {
        optHtml +=
            '<option value="' +
            p.id +
            '" data-cost="' +
            String(parseFloat(p.cost) || 0) +
            '">' +
            prEsc(p.name) +
            '</option>';
    });
    if (typeof PR_GROUP_BY_UNIFIED_CAT === 'undefined' || !PR_GROUP_BY_UNIFIED_CAT) {
        return blank + optHtml;
    }
    var buckets = {};
    var orderKeys = [];
    PR_PRODUCTS.forEach(function (p) {
        var k = String((p.catalog_group_label || '').trim() || 'غير مصنَّف بالشجرة الموحَّدة');
        if (!Object.prototype.hasOwnProperty.call(buckets, k)) {
            buckets[k] = [];
            orderKeys.push(k);
        }
        buckets[k].push(p);
    });
    var chunks = '';
    orderKeys.forEach(function (gk) {
        chunks += '<optgroup label="' + prEsc(gk) + '">';
        buckets[gk].forEach(function (p) {
            chunks +=
                '<option value="' +
                p.id +
                '" data-cost="' +
                String(parseFloat(p.cost) || 0) +
                '">' +
                prEsc(p.name) +
                '</option>';
        });
        chunks += '</optgroup>';
    });
    return blank + chunks;
}

function prRenumberRows() {
    var tb = document.getElementById('pr_lines_body');
    if (!tb) return;
    var rows = tb.querySelectorAll('tr');
    for (var i = 0; i < rows.length; i++) {
        var c = rows[i].querySelector('.pur-col-idx');
        if (c) c.textContent = String(i + 1);
    }
}

function prLineChanged(sel) {
    var tr = sel.closest('tr');
    if (!tr) return;
    var pid = parseInt(sel.value, 10) || 0;
    var vcell = tr.querySelector('.pr-v');
    if (!vcell) return;
    vcell.innerHTML = '';
    var vs = document.createElement('select');
    vs.className = 'pr-v-sel';
    if (!pid || !PR_VARIANTS_BY_PID[pid] || !PR_VARIANTS_BY_PID[pid].length) {
        vs.innerHTML = '<option value="0">' + prEsc('—') + '</option>';
        vcell.appendChild(vs);
        return;
    }
    vs.innerHTML = PR_VARIANTS_BY_PID[pid].map(function (v) {
        return '<option value="' + v.id + '">' + prEsc(v.label) + '</option>';
    }).join('');
    vcell.appendChild(vs);
    var costIn = tr.querySelector('.pr-c');
    var opt = sel.options[sel.selectedIndex];
    if (costIn && opt && opt.getAttribute('data-cost')) {
        costIn.value = String(opt.getAttribute('data-cost'));
    }
}

function prAddLine() {
    var tb = document.getElementById('pr_lines_body');
    if (!tb) return;
    var tr = document.createElement('tr');
    tr.className = 'pr-line';
    tr.innerHTML =
        '<td class="pur-col-idx"></td>' +
        '<td><select class="pr-p" style="min-width:12rem;">' + prProductOptionsHtml() + '</select></td>' +
        '<td class="pr-v"></td>' +
        '<td><input type="number" class="pr-q admin-inp-qty" min="1" step="1" value="1" inputmode="numeric" lang="en" dir="ltr"></td>' +
        '<td><input type="number" class="pr-c admin-inp-money" min="0" step="any" value="0" inputmode="decimal" lang="en" dir="ltr"></td>' +
        '<td><button type="button" class="btn-secondary" onclick="prRemoveRow(this)">حذف</button></td>';
    tb.appendChild(tr);
    prRenumberRows();
    var sel = tr.querySelector('.pr-p');
    if (sel) {
        sel.addEventListener('change', function () { prLineChanged(sel); });
        prLineChanged(sel);
    }
    tr.querySelector('.pr-q').addEventListener('input', prRecalcPreview);
    tr.querySelector('.pr-c').addEventListener('input', prRecalcPreview);
    prRecalcPreview();
}

function prRemoveRow(btn) {
    var tb = document.getElementById('pr_lines_body');
    if (!tb) return;
    if (tb.querySelectorAll('tr').length <= 1) {
        var tr = btn.closest('tr');
        tr.querySelector('.pr-p').value = '';
        prLineChanged(tr.querySelector('.pr-p'));
        tr.querySelector('.pr-q').value = '1';
        tr.querySelector('.pr-c').value = '0';
        prRecalcPreview();
        return;
    }
    btn.closest('tr').remove();
    prRenumberRows();
    prRecalcPreview();
}

function prRecalcPreview() {
    var tb = document.getElementById('pr_lines_body');
    var el = document.getElementById('pr_total_preview');
    if (!tb || !el) return;
    var sum = 0;
    tb.querySelectorAll('tr.pr-line').forEach(function (r) {
        var pid = parseInt(r.querySelector('.pr-p').value, 10) || 0;
        var q = parseInt(r.querySelector('.pr-q').value, 10) || 0;
        var c = parseFloat(r.querySelector('.pr-c').value) || 0;
        if (pid && q >= 1) sum += q * c;
    });
    el.textContent = sum.toFixed(2);
}

function prBindLinesBody() {
    var tb = document.getElementById('pr_lines_body');
    if (!tb) return;
    tb.addEventListener('change', function (e) {
        if (e.target && e.target.classList.contains('pr-p')) prLineChanged(e.target);
    });
}

function prCancelEdit() {
    PR_EDIT_ID = 0;
    document.getElementById('pr_edit_banner').hidden = true;
    document.getElementById('pr_supplier').value = '0';
    document.getElementById('pr_type').value = 'cash';
    document.getElementById('pr_purchase_id').value = '';
    document.getElementById('pr_notes').value = '';
    var tb = document.getElementById('pr_lines_body');
    if (tb) tb.innerHTML = '';
    prAddLine();
}

function prEdit(id) {
    getJSON('/admin/api/purchase_returns/get.php?purchase_return_id=' + encodeURIComponent(String(id))).then(function (res) {
        if (!res.success || !res.purchase_return) {
            alert(res.message || 'فشل التحميل');
            return;
        }
        var p = res.purchase_return;
        PR_EDIT_ID = id;
        document.getElementById('pr_edit_banner').hidden = false;
        document.getElementById('pr_supplier').value = String(p.supplier_id || 0);
        document.getElementById('pr_type').value = p.type === 'credit' ? 'credit' : 'cash';
        document.getElementById('pr_purchase_id').value = p.purchase_id ? String(p.purchase_id) : '';
        document.getElementById('pr_notes').value = p.notes || '';
        var tb = document.getElementById('pr_lines_body');
        tb.innerHTML = '';
        var items = res.items || [];
        for (var i = 0; i < items.length; i++) {
            prAddLine();
            var rows = tb.querySelectorAll('tr.pr-line');
            var tr = rows[rows.length - 1];
            tr.querySelector('.pr-p').value = String(items[i].product_id);
            prLineChanged(tr.querySelector('.pr-p'));
            var vsel = tr.querySelector('.pr-v-sel');
            if (vsel) vsel.value = String(items[i].variant_id || 0);
            tr.querySelector('.pr-q').value = String(items[i].qty);
            tr.querySelector('.pr-c').value = String(items[i].cost);
        }
        prRecalcPreview();
        document.getElementById('pr_edit_banner_id').textContent = '#' + id;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

function prSubmit() {
    var supplier = parseInt(document.getElementById('pr_supplier').value, 10) || 0;
    var type = document.getElementById('pr_type').value;
    var purchaseRef = parseInt(document.getElementById('pr_purchase_id').value, 10) || 0;
    var notes = document.getElementById('pr_notes').value.trim();
    var tb = document.getElementById('pr_lines_body');
    if (!tb) {
        alert('لا توجد أصناف');
        return;
    }
    var rows = tb.querySelectorAll('tr.pr-line');
    var items = [];
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var pid = parseInt(r.querySelector('.pr-p').value, 10) || 0;
        var vsel = r.querySelector('.pr-v-sel');
        var vid = vsel ? (parseInt(vsel.value, 10) || 0) : 0;
        var q = parseInt(r.querySelector('.pr-q').value, 10) || 0;
        var c = parseFloat(r.querySelector('.pr-c').value) || 0;
        if (!pid || q < 1) continue;
        items.push({ product_id: pid, variant_id: vid, qty: q, cost: c });
    }
    if (!items.length) {
        alert('أضف سطراً صالحاً');
        return;
    }
    var url = '/admin/api/purchase_returns/create.php';
    var payload = {
        supplier_id: supplier,
        type: type,
        notes: notes,
        items: items
    };
    if (purchaseRef > 0) payload.purchase_id = purchaseRef;
    if (PR_EDIT_ID) {
        url = '/admin/api/purchase_returns/update.php';
        payload.id = PR_EDIT_ID;
        payload.action = 'update';
    }
    postJSON(url, payload).then(function (res) {
        if (res.success) {
            alert(res.message || 'تم');
            location.reload();
            return;
        }
        if (!orangeAdminOfferSuggestOnFailure(res, 'فشل')) {
            alert(res.message || 'فشل');
        }
    });
}

function prDelete(id) {
    if (!confirm('حذف مردود المشتريات؟ سيعاد المخزون ويُحذف القيد PR-' + id + '.')) return;
    postJSON('/admin/api/purchase_returns/update.php', { id: id, action: 'delete' }).then(function (res) {
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

if (document.getElementById('pr_lines_body')) {
    prAddLine();
    prBindLinesBody();
}
</script>
