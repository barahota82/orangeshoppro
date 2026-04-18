<?php

declare(strict_types=1);

$pdo = db();

$suppliers = $pdo->query('SELECT id, name, phone FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$products = $pdo->query(
    'SELECT id, name, cost, has_colors, has_sizes FROM products WHERE is_active = 1 ORDER BY name ASC'
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
    'SELECT p.*, s.name AS supplier_name
     FROM purchases p
     LEFT JOIN suppliers s ON s.id = p.supplier_id
     ORDER BY p.id DESC
     LIMIT 50'
)->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>فاتورة شراء</h1>
        <p class="page-subtitle">تسجيل مشتريات نقدي أو آجل؛ يُحدَّث مخزون <strong>المتغير</strong> (لون/مقاس) المختار فقط، ويُولَّد قيد محاسبي واحد حسب نوع الشراء.</p>
    </div>
</div>

<div class="card">
    <h2 class="card-title">بيانات الفاتورة</h2>
    <p class="card-hint">الآجل يُرحَّل على ذمم الموردين؛ النقدي يُقابل الصندوق/البنك حسب إعداد الحسابات في الكود (1، 3، 5). مرتجع المشتريات وسندات الصرف ستُبنى لاحقًا كمرحلة محاسبية كاملة.</p>
    <div class="form-grid">
        <div>
            <label>المورد (اختياري)</label>
            <select id="pur_supplier">
                <option value="0">— بدون مورد محدد —</option>
                <?php foreach ($suppliers as $s): ?>
                    <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars((string)$s['name'], ENT_QUOTES, 'UTF-8'); ?></option>
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
    <div id="pur_lines"></div>
    <div class="actions" style="margin-top:12px;">
        <button type="button" class="btn-secondary" onclick="purAddLine()">+ سطر</button>
        <button type="button" onclick="purSubmit()">حفظ فاتورة الشراء</button>
    </div>
    <p class="card-hint" style="margin-top:12px;margin-bottom:0;"><strong>المجموع المحسوب:</strong> <span id="pur_total_preview">0.00</span> KD</p>
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
                    <th>ملاحظات</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $r): ?>
                <tr>
                    <td><?php echo (int)$r['id']; ?></td>
                    <td><?php echo htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['supplier_name'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo ($r['type'] ?? '') === 'credit' ? 'آجل' : 'نقدي'; ?></td>
                    <td><?php echo number_format((float)($r['total'] ?? 0), 2); ?> KD</td>
                    <td><?php echo htmlspecialchars((string)($r['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
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

function purEsc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
}

function purAddLine() {
    var box = document.getElementById('pur_lines');
    var wrap = document.createElement('div');
    wrap.className = 'form-grid pur-line';
    wrap.style.marginBottom = '12px';
    wrap.style.paddingBottom = '12px';
    wrap.style.borderBottom = '1px solid var(--border, #e5e7eb)';
    var opts = PUR_PRODUCTS.map(function (p) {
        return '<option value="' + p.id + '" data-cost="' + String(parseFloat(p.cost) || 0) + '">' + purEsc(p.name) + '</option>';
    }).join('');
    wrap.innerHTML =
        '<div><label>الصنف</label><select class="pur-p" onchange="purLineChanged(this)">' + opts + '</select></div>' +
        '<div><label>المتغير (لون / مقاس)</label><select class="pur-v"></select></div>' +
        '<div><label>الكمية</label><input type="number" class="pur-q admin-inp-qty" min="1" step="1" value="1" inputmode="numeric" lang="en" dir="ltr"></div>' +
        '<div><label>تكلفة الوحدة</label><input type="number" class="pur-c admin-inp-money" min="0" step="any" value="0" inputmode="decimal" lang="en" dir="ltr"></div>';
    box.appendChild(wrap);
    var sel = wrap.querySelector('.pur-p');
    if (sel) purLineChanged(sel);
    purRecalcPreview();
    wrap.querySelector('.pur-q').addEventListener('input', purRecalcPreview);
    wrap.querySelector('.pur-c').addEventListener('input', purRecalcPreview);
}

function purLineChanged(sel) {
    var row = sel.closest('.pur-line');
    var pid = parseInt(sel.value, 10) || 0;
    var opt = sel.options[sel.selectedIndex];
    var c = opt ? parseFloat(opt.getAttribute('data-cost') || '0') : 0;
    var inp = row.querySelector('.pur-c');
    if (inp && !isNaN(c)) inp.value = String(c);
    var vsel = row.querySelector('.pur-v');
    if (vsel) {
        var list = (PUR_VARIANTS_BY_PID && PUR_VARIANTS_BY_PID[pid]) ? PUR_VARIANTS_BY_PID[pid] : [];
        vsel.innerHTML = list.length
            ? list.map(function (v) {
                return '<option value="' + v.id + '">' + purEsc(v.label) + '</option>';
            }).join('')
            : '<option value="0">— لا متغيرات —</option>';
    }
    purRecalcPreview();
}

function purRecalcPreview() {
    var rows = document.querySelectorAll('#pur_lines .pur-line');
    var sum = 0;
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var q = parseInt(r.querySelector('.pur-q').value, 10) || 0;
        var c = parseFloat(r.querySelector('.pur-c').value) || 0;
        sum += q * c;
    }
    var el = document.getElementById('pur_total_preview');
    if (el) el.textContent = sum.toFixed(2);
}

function purSubmit() {
    var supplier = parseInt(document.getElementById('pur_supplier').value, 10) || 0;
    var type = document.getElementById('pur_type').value;
    var notes = document.getElementById('pur_notes').value.trim();
    var rows = document.querySelectorAll('#pur_lines .pur-line');
    var items = [];
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var pid = parseInt(r.querySelector('.pur-p').value, 10) || 0;
        var vid = parseInt(r.querySelector('.pur-v').value, 10) || 0;
        var q = parseInt(r.querySelector('.pur-q').value, 10) || 0;
        var c = parseFloat(r.querySelector('.pur-c').value) || 0;
        if (!pid || q < 1) continue;
        items.push({ product_id: pid, variant_id: vid, qty: q, cost: c });
    }
    if (!items.length) {
        alert('أضف سطرًا واحدًا على الأقل بصنف وكمية صحيحة');
        return;
    }
    postJSON('/admin/api/purchases/create.php', {
        supplier_id: supplier,
        type: type,
        notes: notes,
        items: items
    }).then(function (res) {
        alert(res.message || (res.success ? 'تم حفظ فاتورة الشراء' : 'فشل'));
        if (res.success) location.reload();
    });
}

function purDelete(id) {
    if (!confirm('حذف فاتورة الشراء هذه؟ سيتم عكس المخزون وحذف القيد المحاسبي المرتبط بمرجع PUR-' + id + '.')) return;
    postJSON('/admin/api/purchases/update.php', { id: id, action: 'delete' }).then(function (res) {
        alert(res.message || (res.success ? 'تم الحذف' : 'فشل'));
        if (res.success) location.reload();
    });
}

if (!PUR_PRODUCTS.length) {
    document.getElementById('pur_lines').innerHTML = '<p class="card-hint">لا توجد منتجات نشطة لإضافتها. أنشئ منتجات من «المنتجات» أولًا.</p>';
} else {
    purAddLine();
}
</script>
