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
        يُسجَّل كمصدر «شركة» وليس طلبًا من المتجر الإلكتروني. عند الحفظ يُخصم المخزون ويُطبَّق نفس محاسبة «تم التسليم».
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
            <label>القناة / نقطة البيع</label>
            <select id="mo_channel">
                <?php foreach ($channels as $ch): ?>
                    <option value="<?php echo (int)$ch['id']; ?>"><?php echo htmlspecialchars((string)$ch['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="grid-column:1/-1;">
            <label>نوع البيع</label>
            <select id="mo_payment_terms">
                <option value="cash" selected>نقدي</option>
                <option value="credit">آجل</option>
            </select>
        </div>
    </div>
</div>

<div class="card">
    <h3>الأصناف</h3>
    <div id="mo_lines"></div>
    <div class="actions" style="margin-top:12px;">
        <button type="button" class="btn-secondary" onclick="moAddLine()">+ سطر</button>
        <button type="button" onclick="moSubmit()">حفظ وتسجيل الفاتورة</button>
        <a class="btn btn-secondary" href="/admin/index.php?page=orders">الطلبات</a>
    </div>
</div>

<script>
var MO_PRODUCTS = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE); ?>;
var MO_VARIANTS = <?php echo json_encode($variantsByProduct, JSON_UNESCAPED_UNICODE); ?>;

function moAddLine() {
    var box = document.getElementById('mo_lines');
    var wrap = document.createElement('div');
    wrap.className = 'form-grid';
    wrap.style.marginBottom = '12px';
    wrap.style.borderBottom = '1px solid #eee';
    wrap.style.paddingBottom = '12px';
    var pid = MO_PRODUCTS.length ? String(MO_PRODUCTS[0].id) : '';
    var opts = MO_PRODUCTS.map(function (p) {
        return '<option value="' + p.id + '">' + moEsc(p.name) + '</option>';
    }).join('');
    wrap.innerHTML =
        '<div><label>منتج</label><select class="mo-p" onchange="moSyncVariant(this)">' + opts + '</select></div>' +
        '<div class="mo-v-wrap"><label>المتغير (لون/مقاس)</label><select class="mo-v"><option value="">—</option></select></div>' +
        '<div><label>الكمية</label><input type="number" class="mo-q" min="1" value="1"></div>';
    box.appendChild(wrap);
    if (pid) moSyncVariant(wrap.querySelector('.mo-p'));
}

function moEsc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
}

function moSyncVariant(sel) {
    var row = sel.closest('.form-grid');
    var pid = parseInt(sel.value, 10) || 0;
    var vsel = row.querySelector('.mo-v');
    var vwrap = row.querySelector('.mo-v-wrap');
    var list = MO_VARIANTS[String(pid)] || MO_VARIANTS[pid] || [];
    if (!list.length) {
        vwrap.style.display = 'none';
        vsel.innerHTML = '<option value="">—</option>';
        return;
    }
    vwrap.style.display = '';
    vsel.innerHTML = list.map(function (v) {
        var lab = (v.color || '') + ' / ' + (v.size || '');
        return '<option value="' + v.id + '">' + moEsc(lab) + ' (مخزون ' + (v.stock_quantity || 0) + ')</option>';
    }).join('');
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
    var rows = document.querySelectorAll('#mo_lines .form-grid');
    var items = [];
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var pid = parseInt(r.querySelector('.mo-p').value, 10) || 0;
        var q = parseInt(r.querySelector('.mo-q').value, 10) || 0;
        var vsel = r.querySelector('.mo-v');
        var vid = vsel && vsel.value ? parseInt(vsel.value, 10) : 0;
        if (!pid || q < 1) continue;
        var o = { product_id: pid, qty: q };
        if (vid) o.variant_id = vid;
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

moAddLine();
</script>
