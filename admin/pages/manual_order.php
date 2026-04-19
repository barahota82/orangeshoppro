<?php
$pdo = db();
$channels = $pdo->query('SELECT id, name FROM channels WHERE is_active = 1 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
$products = $pdo->query(
    'SELECT id, name, price, cost, has_colors, has_sizes FROM products WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll(PDO::FETCH_ASSOC);
$variants = $pdo->query('SELECT * FROM product_variants ORDER BY product_id ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
$variantsByProduct = [];
foreach ($variants as $v) {
    $pid = (int)$v['product_id'];
    if (!isset($variantsByProduct[$pid])) {
        $variantsByProduct[$pid] = [];
    }
    $variantsByProduct[$pid][] = $v;
}
?>
<div class="page-title">
    <h1>فاتورة / طلب شركة (خارج الموقع)</h1>
    <p style="margin:0.35rem 0 0;font-size:0.95rem;opacity:0.9;">
        يُسجَّل كمصدر «شركة» وليس طلبًا من المتجر الإلكتروني. المخزون <strong>موحّد</strong> للشركة؛ اختيار القناة أدناه لتتبّع العميل ومصدر الطلب فقط. عند الحفظ يُخصم المخزون الرئيسي ويُطبَّق نفس محاسبة «تم التسليم».
    </p>
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
    </div>
</div>

<div class="card">
    <h3>الأصناف</h3>
    <p class="card-hint" style="margin-top:0;">جدول بنود داخل إطار واحد؛ اختر منتجاً والكمية — يُضاف سطر فارغ تلقائياً. <kbd class="admin-kbd">Tab</kbd> من الكمية لسطر جديد؛ <kbd class="admin-kbd">←</kbd> <kbd class="admin-kbd">→</kbd> <kbd class="admin-kbd">↑</kbd> <kbd class="admin-kbd">↓</kbd> للتنقل بين الخلايا.</p>
    <?php if ($products === []): ?>
        <p class="card-hint">لا توجد منتجات نشطة. أضف منتجات من «المنتجات» أولاً.</p>
    <?php else: ?>
    <div class="admin-doc-frame">
        <div class="table-wrap">
            <table class="admin-table admin-doc-lines-table mo-lines-table">
                <thead>
                    <tr>
                        <th class="mo-col-idx">#</th>
                        <th>المنتج</th>
                        <th>المتغير (لون/مقاس)</th>
                        <th>الكمية</th>
                        <th class="admin-doc-col-actions" aria-label="حذف السطر"></th>
                    </tr>
                </thead>
                <tbody id="mo_lines_body"></tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    <div class="actions admin-doc-lines-toolbar" style="margin-top:12px;">
        <button type="button" class="btn-secondary" onclick="moAddLine()">+ سطر</button>
        <button type="button" onclick="moSubmit()">حفظ وتسجيل الفاتورة</button>
        <a class="btn btn-secondary" href="/admin/index.php?page=orders">الطلبات</a>
    </div>
</div>

<script>
var MO_PRODUCTS = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE); ?>;
var MO_VARIANTS = <?php echo json_encode($variantsByProduct, JSON_UNESCAPED_UNICODE); ?>;

function moEsc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
}

function moProductOptionsHtml() {
    var o = '<option value="">' + moEsc('— اختر منتجاً —') + '</option>';
    return o + MO_PRODUCTS.map(function (p) {
        return '<option value="' + p.id + '">' + moEsc(p.name) + '</option>';
    }).join('');
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

function moAddLine() {
    var tb = document.getElementById('mo_lines_body');
    if (!tb) {
        return;
    }
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td class="mo-col-idx"></td>' +
        '<td><select class="mo-p" style="min-width:12rem;">' + moProductOptionsHtml() + '</select></td>' +
        '<td class="mo-v-cell"><select class="mo-v"><option value="">—</option></select></td>' +
        '<td><input type="number" class="mo-q admin-inp-qty" min="1" step="1" value="1" inputmode="numeric" lang="en" dir="ltr"></td>' +
        '<td><button type="button" class="btn-secondary admin-doc-line-remove" onclick="moRemoveRow(this)">حذف</button></td>';
    tb.appendChild(tr);
    moRenumberRows();
    var sel = tr.querySelector('.mo-p');
    if (sel) {
        moSyncVariant(sel);
    }
}

function moRemoveRow(btn) {
    var tb = document.getElementById('mo_lines_body');
    if (!tb) {
        return;
    }
    if (tb.querySelectorAll('tr').length <= 1) {
        var tr = btn.closest('tr');
        tr.querySelector('.mo-p').value = '';
        tr.querySelector('.mo-q').value = '1';
        moSyncVariant(tr.querySelector('.mo-p'));
        moSyncTrailingRows();
        return;
    }
    btn.closest('tr').remove();
    moRenumberRows();
    moSyncTrailingRows();
}

function moRowIsBlank(tr) {
    var pid = parseInt(tr.querySelector('.mo-p').value, 10) || 0;
    var q = parseInt(tr.querySelector('.mo-q').value, 10) || 0;
    return pid <= 0 || q < 1;
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

function moSyncVariant(sel) {
    var row = sel.closest('tr');
    var pid = parseInt(sel.value, 10) || 0;
    var vsel = row.querySelector('.mo-v');
    var vcell = row.querySelector('.mo-v-cell');
    var list = MO_VARIANTS[String(pid)] || MO_VARIANTS[pid] || [];
    if (!list.length) {
        if (vcell) {
            vcell.setAttribute('hidden', '');
        }
        vsel.innerHTML = '<option value="">—</option>';
        return;
    }
    if (vcell) {
        vcell.removeAttribute('hidden');
    }
    vsel.innerHTML = list.map(function (v) {
        var lab = (v.color || '') + ' / ' + (v.size || '');
        return '<option value="' + v.id + '">' + moEsc(lab) + ' (مخزون ' + (v.stock_quantity || 0) + ')</option>';
    }).join('');
}

function moBindLinesBody() {
    var tb = document.getElementById('mo_lines_body');
    if (!tb || tb.getAttribute('data-mo-bound') === '1') {
        return;
    }
    tb.setAttribute('data-mo-bound', '1');
    tb.addEventListener('change', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('mo-p')) {
            moSyncVariant(e.target);
        }
        moSyncTrailingRows();
    });
    tb.addEventListener('input', function () {
        moSyncTrailingRows();
    });
    tb.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab' || e.shiftKey) {
            return;
        }
        var ta = e.target;
        if (!ta || !ta.classList || !ta.classList.contains('mo-q')) {
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
        var sel = next && next.querySelector('.mo-p');
        if (sel) {
            sel.focus();
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
        alert('لا توجد منتجات');
        return;
    }
    var rows = tb.querySelectorAll('tr');
    var items = [];
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var pid = parseInt(r.querySelector('.mo-p').value, 10) || 0;
        var q = parseInt(r.querySelector('.mo-q').value, 10) || 0;
        var vsel = r.querySelector('.mo-v');
        var vid = vsel && vsel.value ? parseInt(vsel.value, 10) : 0;
        if (!pid || q < 1) {
            continue;
        }
        var o = { product_id: pid, qty: q };
        if (vid) {
            o.variant_id = vid;
        }
        items.push(o);
    }
    if (!items.length) {
        alert('أضف صنفًا واحدًا على الأقل');
        return;
    }
    postJSON('/admin/api/orders/create-manual.php', {
        customer_name: name,
        phone: phone,
        area: document.getElementById('mo_area').value.trim(),
        address: document.getElementById('mo_address').value.trim(),
        notes: document.getElementById('mo_notes').value.trim(),
        channel_id: channel,
        payment_terms: document.getElementById('mo_payment_terms').value || 'cash',
        items: items
    }).then(function (res) {
        alert(res.message || (res.success ? 'تم' : 'فشل'));
        if (res.success && res.order_id) {
            location.href = '/admin/index.php?page=invoice&order_id=' + encodeURIComponent(String(res.order_id));
        }
    });
}

if (document.getElementById('mo_lines_body')) {
    moAddLine();
    moBindLinesBody();
    moSyncTrailingRows();
}
</script>
