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
<div class="page-title page-title--stacked">
    <h1>فاتورة مبيعات — طلب شركة</h1>
    <p class="page-subtitle">مستند بيع داخلي مرتبط بالعميل والقناة. البنود من الكتالوج تخصم المخزون تلقائياً؛ <strong>البند النصي</strong> (بدون منتج) يظهر في الفاتورة ولا يغيّر المخزون. بعد الحفظ تفتح صفحة الفاتورة الرسمية مع التسلسل والمدفوع والباقي.</p>
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
    <p class="card-hint" style="margin-top:0;">اختر <strong>منتجاً</strong> أو <strong>بنداً نصياً</strong> (حقل الوصف يظهر عند اختيار «بدون منتج»). الأعمدة: كمية، سعر الوحدة، خصم بالدينار — <strong>الصافي</strong> يُحسب في الملخص أسفل الجدول وفي الفاتورة المطبوعة. <kbd class="admin-kbd">Tab</kbd> من «خصم» لسطر جديد؛ الأسهم للتنقل.</p>
    <?php if ($products === []): ?>
        <p class="mo-invoice-alert">لا توجد منتجات نشطة في الكتالوج — يمكنك العمل الآن بـ <strong>بنود نصية وأسعار يدوية</strong> فقط، أو إضافة منتجات من «المنتجات» لربط المخزون.</p>
    <?php endif; ?>
    <div class="admin-doc-frame mo-invoice-frame">
        <div class="table-wrap">
            <table class="admin-table admin-doc-lines-table mo-lines-table">
                <thead>
                    <tr>
                        <th class="mo-col-idx">#</th>
                        <th>منتج / وصف</th>
                        <th>المتغير (لون/مقاس)</th>
                        <th>الكمية</th>
                        <th>سعر الوحدة</th>
                        <th>خصم</th>
                        <th class="admin-doc-col-actions" aria-label="حذف السطر"></th>
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

function moFindProduct(id) {
    for (var i = 0; i < MO_PRODUCTS.length; i++) {
        if (String(MO_PRODUCTS[i].id) === String(id)) {
            return MO_PRODUCTS[i];
        }
    }
    return null;
}

function moProductOptionsHtml() {
    var o = '<option value="">' + moEsc('— بند نصي (بدون منتج) —') + '</option>';
    if (!MO_PRODUCTS.length) {
        return o;
    }
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
            var pid = parseInt(r.querySelector('.mo-p').value, 10) || 0;
            var q = parseInt(r.querySelector('.mo-q').value, 10) || 0;
            var disc = moParseMoneyEl(r.querySelector('.mo-disc'));
            if (disc < 0) {
                disc = 0;
            }
            if (pid <= 0) {
                var free = (r.querySelector('.mo-free') && r.querySelector('.mo-free').value || '').trim();
                if (free === '' || q < 1) {
                    continue;
                }
                var up = moParseMoneyEl(r.querySelector('.mo-price'));
                var lg = q * up;
                gross += lg;
                discsum += disc;
                var ln = lg - disc;
                if (ln < 0) {
                    ln = 0;
                }
                net += ln;
                count++;
                continue;
            }
            if (q < 1) {
                continue;
            }
            var up2 = moParseMoneyEl(r.querySelector('.mo-price'));
            var lg2 = q * up2;
            gross += lg2;
            discsum += disc;
            var ln2 = lg2 - disc;
            if (ln2 < 0) {
                ln2 = 0;
            }
            net += ln2;
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

function moSyncRowMode(tr) {
    var sel = tr.querySelector('.mo-p');
    var pid = sel ? parseInt(sel.value, 10) || 0 : 0;
    var freeInp = tr.querySelector('.mo-free');
    var priceInp = tr.querySelector('.mo-price');
    if (freeInp) {
        freeInp.style.display = pid > 0 ? 'none' : 'block';
        if (pid > 0) {
            freeInp.value = '';
        }
    }
    if (priceInp) {
        if (pid > 0) {
            priceInp.readOnly = true;
            var pr = moFindProduct(pid);
            if (pr) {
                priceInp.value = String(parseFloat(pr.price) || 0);
            }
            moSyncVariant(sel);
        } else {
            priceInp.readOnly = false;
            var vcell = tr.querySelector('.mo-v-cell');
            if (vcell) {
                vcell.setAttribute('hidden', '');
            }
            var vsel = tr.querySelector('.mo-v');
            if (vsel) {
                vsel.innerHTML = '<option value="">—</option>';
            }
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
        '<td><select class="mo-p" style="min-width:10rem;">' + moProductOptionsHtml() + '</select>' +
        '<input type="text" class="mo-free" style="display:none;width:100%;min-width:10rem;margin-top:6px;" placeholder="وصف البند (بدون منتج بالكتالوج)"></td>' +
        '<td class="mo-v-cell"><select class="mo-v"><option value="">—</option></select></td>' +
        '<td><input type="number" class="mo-q admin-inp-qty" min="1" step="1" value="1" inputmode="numeric" lang="en" dir="ltr"></td>' +
        '<td><input type="number" class="mo-price admin-inp-money" step="any" min="0" value="0" lang="en" dir="ltr"></td>' +
        '<td><input type="number" class="mo-disc admin-inp-money" step="any" min="0" value="0" lang="en" dir="ltr"></td>' +
        '<td><button type="button" class="btn-secondary admin-doc-line-remove" onclick="moRemoveRow(this)">حذف</button></td>';
    tb.appendChild(tr);
    moRenumberRows();
    var sel = tr.querySelector('.mo-p');
    if (sel) {
        moSyncRowMode(tr);
    }
    moRecalcTotals();
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
        var fr = tr.querySelector('.mo-free');
        if (fr) {
            fr.value = '';
        }
        var pr = tr.querySelector('.mo-price');
        if (pr) {
            pr.value = '0';
        }
        var di = tr.querySelector('.mo-disc');
        if (di) {
            di.value = '0';
        }
        moSyncRowMode(tr);
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
    var free = (tr.querySelector('.mo-free') && tr.querySelector('.mo-free').value || '').trim();
    if (q < 1) {
        return true;
    }
    if (pid > 0) {
        return false;
    }
    return free === '';
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

function moBindLinesBody() {
    var tb = document.getElementById('mo_lines_body');
    if (!tb || tb.getAttribute('data-mo-bound') === '1') {
        return;
    }
    tb.setAttribute('data-mo-bound', '1');
    tb.addEventListener('change', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('mo-p')) {
            moSyncRowMode(e.target.closest('tr'));
        }
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
        alert('لا يوجد جدول بنود');
        return;
    }
    var rows = tb.querySelectorAll('tr');
    var items = [];
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var pid = parseInt(r.querySelector('.mo-p').value, 10) || 0;
        var q = parseInt(r.querySelector('.mo-q').value, 10) || 0;
        var disc = parseFloat(String((r.querySelector('.mo-disc') && r.querySelector('.mo-disc').value) || '0').replace(',', '.')) || 0;
        if (disc < 0) {
            disc = 0;
        }
        var vsel = r.querySelector('.mo-v');
        var vid = vsel && vsel.value ? parseInt(vsel.value, 10) : 0;
        if (pid <= 0) {
            var free = (r.querySelector('.mo-free') && r.querySelector('.mo-free').value || '').trim();
            if (free === '' || q < 1) {
                continue;
            }
            var up = parseFloat(String((r.querySelector('.mo-price') && r.querySelector('.mo-price').value) || '0').replace(',', '.')) || 0;
            items.push({
                product_id: null,
                product_name: free,
                qty: q,
                unit_price: up,
                line_discount: disc
            });
            continue;
        }
        if (q < 1) {
            continue;
        }
        var o = { product_id: pid, qty: q, line_discount: disc };
        if (vid) {
            o.variant_id = vid;
        }
        items.push(o);
    }
    if (!items.length) {
        alert('أضف سطراً واحداً على الأقل (منتج أو وصف نصي)');
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
            location.href = '/admin/index.php?page=invoice&order_id=' + encodeURIComponent(String(res.order_id));
        }
    });
}

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
