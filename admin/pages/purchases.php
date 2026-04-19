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
    <?php if ($products === []): ?>
        <p class="card-hint">لا توجد منتجات نشطة لإضافتها. أنشئ منتجات من «المنتجات» أولًا.</p>
    <?php else: ?>
    <p class="card-hint" style="margin-top:0;">بنود الفاتورة في جدول داخل إطار واحد؛ بعد اختيار صنف وكمية يُفتح سطر جديد تلقائياً. <kbd class="admin-kbd">Tab</kbd> من خانة «تكلفة الوحدة» ينقلك لسطر جديد.</p>
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

function purProductOptionsHtml() {
    var o = '<option value="" data-cost="0">' + purEsc('— اختر صنفاً —') + '</option>';
    return o + PUR_PRODUCTS.map(function (p) {
        return '<option value="' + p.id + '" data-cost="' + String(parseFloat(p.cost) || 0) + '">' + purEsc(p.name) + '</option>';
    }).join('');
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
    postJSON('/admin/api/purchases/create.php', {
        supplier_id: supplier,
        type: type,
        notes: notes,
        items: items
    }).then(function (res) {
        if (res.success) {
            alert(res.message || 'تم حفظ فاتورة الشراء');
            location.reload();
            return;
        }
        if (!orangeAdminOfferSuggestOnFailure(res, 'فشل')) {
            alert(res.message || 'فشل');
        }
    });
}

function purDelete(id) {
    if (!confirm('حذف فاتورة الشراء هذه؟ سيتم عكس المخزون وحذف القيد المحاسبي المرتبط بمرجع PUR-' + id + '.')) return;
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
