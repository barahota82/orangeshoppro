<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/countries.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$srCountryId = orange_admin_context_country_id($pdo);
$srCustomersCountrySql = orange_sql_country_and_fragment($pdo, 'customers', 'customers', $srCountryId);
$srProductsCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'products', $srCountryId);

// س13: جدول customers يستخدم name_ar وليس name — اختيار العمود الصحيح ديناميكياً.
$hasCustomers = orange_table_exists($pdo, 'customers');
$customers = [];
if ($hasCustomers) {
    $custNameCol = orange_table_has_column($pdo, 'customers', 'name_ar') ? 'name_ar' : (orange_table_has_column($pdo, 'customers', 'name') ? 'name' : 'name_ar');
    try {
        $customers = $pdo->query("SELECT id, $custNameCol AS name, phone FROM customers WHERE 1=1" . $srCustomersCountrySql . " ORDER BY $custNameCol ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $customers = [];
        if (function_exists('error_log')) {
            error_log('[orange] sales_returns customers list: ' . $e->getMessage());
        }
    }
}

$products = [];
try {
    $products = $pdo->query(
        'SELECT id, name, price, cost, has_colors, has_sizes FROM products WHERE is_active = 1' . $srProductsCountrySql . ' ORDER BY name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] sales_returns products list: ' . $e->getMessage());
    }
}

// س15 — prefill عميل من شاشة العملاء عبر ?customer_id=ID
$srPrefillCustomerId = 0;
$prefillCustomerIdRaw = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
if ($prefillCustomerIdRaw > 0 && $hasCustomers) {
    foreach ($customers as $cRow) {
        if ((int) ($cRow['id'] ?? 0) === $prefillCustomerIdRaw) {
            $srPrefillCustomerId = $prefillCustomerIdRaw;
            break;
        }
    }
}

$variantsByProduct = [];
try {
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
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] sales_returns variants list: ' . $e->getMessage());
    }
}

// س13: تحقق وجود sales_returns + اسم عمود customers الصحيح (name_ar).
$recent = [];
$hasSalesReturns = orange_table_exists($pdo, 'sales_returns');
if ($hasSalesReturns) {
    $recentSql = 'SELECT sr.*';
    if ($hasCustomers) {
        $custNameColRecent = orange_table_has_column($pdo, 'customers', 'name_ar') ? 'name_ar' : (orange_table_has_column($pdo, 'customers', 'name') ? 'name' : 'name_ar');
        $recentSql .= ', c.' . $custNameColRecent . ' AS customer_name';
    }
    $recentSql .= ' FROM sales_returns sr';
    if ($hasCustomers) {
        $recentSql .= ' LEFT JOIN customers c ON c.id = sr.customer_id';
    }
    if ($srCountryId > 0 && $hasCustomers && orange_table_has_country_id($pdo, 'customers')) {
        $recentSql .= ' WHERE c.country_id = ' . (int) $srCountryId;
    }
    $recentSql .= ' ORDER BY sr.id DESC LIMIT 50';
    try {
        $recent = $pdo->query($recentSql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $recent = [];
        if (function_exists('error_log')) {
            error_log('[orange] sales_returns recent list: ' . $e->getMessage());
        }
    }
}

function sr_channel_label(string $t): string
{
    return match ($t) {
        'online' => 'أونلاين',
        'credit' => 'آجل',
        default => 'نقدي',
    };
}
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>مردود مبيعات</h1>
        <p class="page-subtitle">تسجيل إرجاع بضاعة من العميل؛ <strong>يُزاد المخزون فور الحفظ</strong> ويُولَّد قيد إيراد/تكلفة بمراجع <code dir="ltr">SR-{id}-RS</code> و <code dir="ltr">SR-{id}-RC</code> حسب «حسابات القيود التلقائية» (نقدي / أونلاين / آجل).</p>
    </div>
</div>

<div class="card" id="sr_edit_banner" hidden>
    <p class="card-hint" style="margin:0 0 10px;"><strong>وضع التعديل</strong> — مردود <span id="sr_edit_banner_id"></span>.</p>
    <button type="button" class="btn-secondary" onclick="srCancelEdit()">إلغاء التعديل</button>
</div>

<div class="card">
    <h2 class="card-title">بيانات المردود</h2>
    <div class="form-grid">
        <div>
            <label id="sr_customer_label">العميل</label>
            <select id="sr_customer" <?php echo !$hasCustomers ? 'disabled' : ''; ?>>
                <option value="0">— بدون عميل —</option>
                <?php foreach ($customers as $c):
                    $cId = (int) $c['id'];
                    $selected = ($srPrefillCustomerId > 0 && $cId === $srPrefillCustomerId) ? ' selected' : '';
                    ?>
                    <option value="<?php echo $cId; ?>"<?php echo $selected; ?>><?php echo htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!$hasCustomers): ?>
                <p class="card-hint" style="margin:0.35rem 0 0;">جدول العملاء غير متوفر.</p>
            <?php endif; ?>
        </div>
        <div>
            <label>قناة التحصيل</label>
            <select id="sr_channel" onchange="srOnChannelChange()">
                <option value="cash">نقدي</option>
                <option value="online">أونلاين</option>
                <option value="credit">آجل</option>
            </select>
        </div>
        <div>
            <label>مرجع طلب (اختياري)</label>
            <input type="number" id="sr_order_id" min="0" step="1" placeholder="رقم الطلب" lang="en" dir="ltr" class="admin-inp-qty">
        </div>
        <div style="grid-column:1/-1;">
            <label>ملاحظات</label>
            <input type="text" id="sr_notes" placeholder="رقم إذن الإرجاع، …">
        </div>
    </div>
</div>

<div class="card">
    <h2 class="card-title">أسطر الأصناف</h2>
    <?php if ($products === []): ?>
        <p class="card-hint">لا توجد منتجات نشطة.</p>
    <?php else: ?>
    <p class="card-hint" style="margin-top:0;">يُزاد مخزون المتغير عند الحفظ. سعر الوحدة يُستخدم لصافي الإيراد؛ <strong>تكلفة الوحدة</strong> اختيارية (إن تُركت فارغة تُؤخذ من بطاقة المنتج).</p>
    <div class="admin-doc-frame">
        <div class="table-wrap">
            <table class="admin-table admin-doc-lines-table">
                <thead>
                    <tr>
                        <th class="pur-col-idx">#</th>
                        <th>الصنف</th>
                        <th>المتغير</th>
                        <th>الكمية</th>
                        <th>سعر الوحدة</th>
                        <th>تكلفة الوحدة (اختياري)</th>
                        <th class="admin-doc-col-actions" aria-label="حذف"></th>
                    </tr>
                </thead>
                <tbody id="sr_lines_body"></tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    <div class="actions admin-doc-lines-toolbar" style="margin-top:12px;">
        <button type="button" class="btn-secondary" onclick="srAddLine()">+ سطر</button>
        <button type="button" id="sr_submit_btn" onclick="srSubmit()">حفظ مردود المبيعات</button>
    </div>
    <p class="card-hint" style="margin-top:12px;margin-bottom:0;"><strong>صافي الإيراد (تقديري):</strong> <span id="sr_total_preview">0.00</span> KD</p>
</div>

<div class="card">
    <h2 class="card-title">آخر مردودات المبيعات</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>المرجع</th>
                    <th>التاريخ</th>
                    <th>العميل</th>
                    <th>القناة</th>
                    <th>الإجمالي</th>
                    <th>طلب</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $r): ?>
                <tr>
                    <td><?php echo (int) $r['id']; ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) ($r['return_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(orange_format_datetime_dmY_hi((string) ($r['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($r['customer_name'] ?? ($r['customer_id'] ? '#' . (int) $r['customer_id'] : '—')), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(sr_channel_label((string) ($r['type'] ?? 'cash')), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo number_format((float) ($r['total'] ?? 0), 2); ?> KD</td>
                    <td dir="ltr"><?php echo !empty($r['order_id']) ? (int) $r['order_id'] : '—'; ?></td>
                    <td>
                        <button type="button" class="btn-secondary" onclick="srEdit(<?php echo (int) $r['id']; ?>)">تعديل</button>
                        <button type="button" class="btn-danger" onclick="srDelete(<?php echo (int) $r['id']; ?>)">حذف</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
var SR_PRODUCTS = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE); ?>;
var SR_VARIANTS_BY_PID = <?php echo json_encode($variantsByProduct, JSON_UNESCAPED_UNICODE); ?>;
var SR_EDIT_ID = 0;
var SR_HAS_CUSTOMERS = <?php echo $hasCustomers ? 'true' : 'false'; ?>;

function srEsc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
}

function srProductOptionsHtml() {
    var o = '<option value="" data-price="0" data-cost="0">' + srEsc('— اختر صنفاً —') + '</option>';
    return o + SR_PRODUCTS.map(function (p) {
        return '<option value="' + p.id + '" data-price="' + String(parseFloat(p.price) || 0) + '" data-cost="' + String(parseFloat(p.cost) || 0) + '">' + srEsc(p.name) + '</option>';
    }).join('');
}

function srRenumberRows() {
    var tb = document.getElementById('sr_lines_body');
    if (!tb) return;
    var rows = tb.querySelectorAll('tr');
    for (var i = 0; i < rows.length; i++) {
        var c = rows[i].querySelector('.pur-col-idx');
        if (c) c.textContent = String(i + 1);
    }
}

function srOnChannelChange() {
    var ch = document.getElementById('sr_channel');
    var lab = document.getElementById('sr_customer_label');
    if (!ch || !lab) return;
    if (ch.value === 'credit') {
        lab.textContent = 'العميل (مطلوب للآجل)';
    } else {
        lab.textContent = 'العميل (اختياري)';
    }
}

function srLineChanged(sel) {
    var tr = sel.closest('tr');
    if (!tr) return;
    var pid = parseInt(sel.value, 10) || 0;
    var vcell = tr.querySelector('.sr-v');
    if (!vcell) return;
    vcell.innerHTML = '';
    var vs = document.createElement('select');
    vs.className = 'sr-v-sel';
    if (!pid || !SR_VARIANTS_BY_PID[pid] || !SR_VARIANTS_BY_PID[pid].length) {
        vs.innerHTML = '<option value="0">' + srEsc('—') + '</option>';
        vcell.appendChild(vs);
        return;
    }
    vs.innerHTML = SR_VARIANTS_BY_PID[pid].map(function (v) {
        return '<option value="' + v.id + '">' + srEsc(v.label) + '</option>';
    }).join('');
    vcell.appendChild(vs);
    var priceIn = tr.querySelector('.sr-price');
    var costIn = tr.querySelector('.sr-cost');
    var opt = sel.options[sel.selectedIndex];
    if (opt) {
        if (priceIn && opt.getAttribute('data-price') != null) {
            priceIn.value = String(opt.getAttribute('data-price'));
        }
        if (costIn && opt.getAttribute('data-cost') != null) {
            costIn.value = String(opt.getAttribute('data-cost'));
        }
    }
}

function srAddLine() {
    var tb = document.getElementById('sr_lines_body');
    if (!tb) return;
    var tr = document.createElement('tr');
    tr.className = 'sr-line';
    tr.innerHTML =
        '<td class="pur-col-idx"></td>' +
        '<td><select class="sr-p" style="min-width:12rem;">' + srProductOptionsHtml() + '</select></td>' +
        '<td class="sr-v"></td>' +
        '<td><input type="number" class="sr-q admin-inp-qty" min="1" step="1" value="1" inputmode="numeric" lang="en" dir="ltr"></td>' +
        '<td><input type="number" class="sr-price admin-inp-money" min="0" step="any" value="0" inputmode="decimal" lang="en" dir="ltr"></td>' +
        '<td><input type="number" class="sr-cost admin-inp-money" min="0" step="any" value="" placeholder="تلقائي" inputmode="decimal" lang="en" dir="ltr"></td>' +
        '<td><button type="button" class="btn-secondary" onclick="srRemoveRow(this)">حذف</button></td>';
    tb.appendChild(tr);
    srRenumberRows();
    var sel = tr.querySelector('.sr-p');
    if (sel) {
        sel.addEventListener('change', function () { srLineChanged(sel); });
        srLineChanged(sel);
    }
    tr.querySelector('.sr-q').addEventListener('input', srRecalcPreview);
    tr.querySelector('.sr-price').addEventListener('input', srRecalcPreview);
    srRecalcPreview();
}

function srRemoveRow(btn) {
    var tb = document.getElementById('sr_lines_body');
    if (!tb) return;
    if (tb.querySelectorAll('tr').length <= 1) {
        var tr = btn.closest('tr');
        tr.querySelector('.sr-p').value = '';
        srLineChanged(tr.querySelector('.sr-p'));
        tr.querySelector('.sr-q').value = '1';
        tr.querySelector('.sr-price').value = '0';
        tr.querySelector('.sr-cost').value = '';
        srRecalcPreview();
        return;
    }
    btn.closest('tr').remove();
    srRenumberRows();
    srRecalcPreview();
}

function srRecalcPreview() {
    var tb = document.getElementById('sr_lines_body');
    var el = document.getElementById('sr_total_preview');
    if (!tb || !el) return;
    var sum = 0;
    tb.querySelectorAll('tr.sr-line').forEach(function (r) {
        var pid = parseInt(r.querySelector('.sr-p').value, 10) || 0;
        var q = parseInt(r.querySelector('.sr-q').value, 10) || 0;
        var price = parseFloat(r.querySelector('.sr-price').value) || 0;
        if (pid && q >= 1) sum += q * price;
    });
    el.textContent = sum.toFixed(2);
}

function srBindLinesBody() {
    var tb = document.getElementById('sr_lines_body');
    if (!tb) return;
    tb.addEventListener('change', function (e) {
        if (e.target && e.target.classList.contains('sr-p')) srLineChanged(e.target);
    });
}

function srCancelEdit() {
    SR_EDIT_ID = 0;
    document.getElementById('sr_edit_banner').hidden = true;
    document.getElementById('sr_customer').value = '0';
    document.getElementById('sr_channel').value = 'cash';
    srOnChannelChange();
    document.getElementById('sr_order_id').value = '';
    document.getElementById('sr_notes').value = '';
    var tb = document.getElementById('sr_lines_body');
    if (tb) tb.innerHTML = '';
    srAddLine();
}

function srEdit(id) {
    getJSON('/admin/api/sales_returns/get.php?sales_return_id=' + encodeURIComponent(String(id))).then(function (res) {
        if (!res.success || !res.sales_return) {
            alert(res.message || 'فشل التحميل');
            return;
        }
        var p = res.sales_return;
        SR_EDIT_ID = id;
        document.getElementById('sr_edit_banner').hidden = false;
        document.getElementById('sr_customer').value = String(p.customer_id || 0);
        var ch = String(p.type || 'cash');
        if (ch !== 'cash' && ch !== 'online' && ch !== 'credit') ch = 'cash';
        document.getElementById('sr_channel').value = ch;
        srOnChannelChange();
        document.getElementById('sr_order_id').value = p.order_id ? String(p.order_id) : '';
        document.getElementById('sr_notes').value = p.notes || '';
        var tb = document.getElementById('sr_lines_body');
        tb.innerHTML = '';
        var items = res.items || [];
        for (var i = 0; i < items.length; i++) {
            srAddLine();
            var rows = tb.querySelectorAll('tr.sr-line');
            var tr = rows[rows.length - 1];
            tr.querySelector('.sr-p').value = String(items[i].product_id);
            srLineChanged(tr.querySelector('.sr-p'));
            var vsel = tr.querySelector('.sr-v-sel');
            if (vsel) vsel.value = String(items[i].variant_id || 0);
            tr.querySelector('.sr-q').value = String(items[i].qty);
            tr.querySelector('.sr-price').value = String(items[i].price);
            tr.querySelector('.sr-cost').value = '';
        }
        srRecalcPreview();
        document.getElementById('sr_edit_banner_id').textContent = '#' + id;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

function srSubmit() {
    var customer = parseInt(document.getElementById('sr_customer').value, 10) || 0;
    var channel = document.getElementById('sr_channel').value;
    if (!SR_HAS_CUSTOMERS && channel === 'credit') {
        alert('مردود الآجل يتطلّب جدول العملاء');
        return;
    }
    var orderRef = parseInt(document.getElementById('sr_order_id').value, 10) || 0;
    var notes = document.getElementById('sr_notes').value.trim();
    if (channel === 'credit' && customer <= 0) {
        alert('مردود الآجل يتطلّب اختيار عميل');
        return;
    }
    var tb = document.getElementById('sr_lines_body');
    if (!tb) {
        alert('لا توجد أصناف');
        return;
    }
    var rows = tb.querySelectorAll('tr.sr-line');
    var items = [];
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var pid = parseInt(r.querySelector('.sr-p').value, 10) || 0;
        var vsel = r.querySelector('.sr-v-sel');
        var vid = vsel ? (parseInt(vsel.value, 10) || 0) : 0;
        var q = parseInt(r.querySelector('.sr-q').value, 10) || 0;
        var price = parseFloat(r.querySelector('.sr-price').value) || 0;
        var costRaw = r.querySelector('.sr-cost').value.trim();
        var costNum = costRaw === '' ? NaN : parseFloat(costRaw);
        if (!pid || q < 1) continue;
        var row = { product_id: pid, variant_id: vid, qty: q, price: price, line_discount: 0 };
        if (!isNaN(costNum) && costNum > 0.0001) row.cost = costNum;
        items.push(row);
    }
    if (!items.length) {
        alert('أضف سطراً صالحاً');
        return;
    }
    var url = '/admin/api/sales_returns/create.php';
    var payload = {
        customer_id: customer,
        channel: channel,
        notes: notes,
        items: items
    };
    if (orderRef > 0) payload.order_id = orderRef;
    if (SR_EDIT_ID) {
        url = '/admin/api/sales_returns/update.php';
        payload.id = SR_EDIT_ID;
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

function srDelete(id) {
    if (!confirm('حذف مردود المبيعات؟ سيعكس المخزون ويُزال القيد SR-' + id + '-RS / RC.')) return;
    postJSON('/admin/api/sales_returns/update.php', { id: id, action: 'delete' }).then(function (res) {
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

if (document.getElementById('sr_lines_body')) {
    srOnChannelChange();
    srAddLine();
    srBindLinesBody();
}
</script>
