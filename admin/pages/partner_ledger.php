<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$suppliers = orange_table_exists($pdo, 'suppliers')
    ? $pdo->query('SELECT id, name, phone FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC)
    : [];

$customers = orange_table_exists($pdo, 'customers')
    ? $pdo->query('SELECT id, name_ar, phone FROM customers ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC)
    : [];

$supBal = [];
foreach ($suppliers as $s) {
    $supBal[(int)$s['id']] = orange_party_balance_supplier($pdo, (int)$s['id']);
}
$custBal = [];
foreach ($customers as $c) {
    $custBal[(int)$c['id']] = orange_party_balance_customer($pdo, (int)$c['id']);
}

$recent = [];
if (orange_party_subledger_ready($pdo)) {
    $recent = $pdo->query(
        'SELECT ps.*, jv.voucher_date, jv.reference, jv.entry_type
         FROM party_subledger ps
         INNER JOIN journal_vouchers jv ON jv.id = ps.voucher_id
         ORDER BY ps.id DESC
         LIMIT 40'
    )->fetchAll(PDO::FETCH_ASSOC);
}

$stmtPartyPayload = ['customer' => [], 'supplier' => []];
foreach ($customers as $c) {
    $stmtPartyPayload['customer'][] = [
        'id' => (int) $c['id'],
        'label' => $c['name_ar'] . ' — ' . $c['phone'] . ' (رصيد ' . number_format($custBal[(int) $c['id']] ?? 0, 3) . ')',
    ];
}
foreach ($suppliers as $s) {
    $stmtPartyPayload['supplier'][] = [
        'id' => (int) $s['id'],
        'label' => $s['name'] . ($s['phone'] ? ' — ' . $s['phone'] : '') . ' (ذمة ' . number_format($supBal[(int) $s['id']] ?? 0, 3) . ')',
    ];
}
$stmtPartyJson = json_encode($stmtPartyPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
if ($stmtPartyJson === false) {
    $stmtPartyJson = '{}';
}
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>ذمم العملاء والموردين</h1>
        <p class="page-subtitle">
            تُسجَّل الذمم تلقائياً عند <strong>تسليم طلب آجل</strong> (إن وُجد هاتف للعميل) وعند <strong>شراء آجل</strong> مع اختيار مورد.
            استخدم سندات القبض/الدفع أدناه لتحريك النقدية مقابل ذمم العملاء والموردين — مع قيود محاسبية متزامنة.
            <a href="/admin/index.php?page=partner_reports">تقارير الذمم الشاملة ومطابقة الدليل</a>
        </p>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h3 class="card-title">سند قبض من عميل</h3>
        <div class="form-grid">
            <div style="grid-column:1/-1;">
                <label for="rec_cust">العميل</label>
                <select id="rec_cust">
                    <?php if (!$customers): ?>
                        <option value="0">— لا يوجد عملاء — أضف عميلاً أدناه</option>
                    <?php endif; ?>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>">
                            <?php echo htmlspecialchars($c['name_ar'] . ' — ' . $c['phone'], ENT_QUOTES, 'UTF-8'); ?>
                            (رصيد <?php echo number_format($custBal[(int)$c['id']] ?? 0, 3); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="rec_amt">المبلغ</label>
                <input type="number" id="rec_amt" class="admin-inp-money" step="any" min="0.01" value="" inputmode="decimal" lang="en" dir="ltr">
            </div>
            <div>
                <label for="rec_date">التاريخ</label>
                <input type="date" id="rec_date" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div style="grid-column:1/-1;">
                <label for="rec_desc">البيان</label>
                <input type="text" id="rec_desc" placeholder="تحصيل / دفعة">
            </div>
            <div style="grid-column:1/-1;" class="form-check">
                <label><input type="checkbox" id="rec_allow_excess"> السماح بقبض يزيد عن رصيد الذمة (سلفة / دفعة مقدمة)</label>
            </div>
            <div style="grid-column:1/-1; margin-top:10px; padding-top:12px; border-top:1px solid var(--border, #e5e5e5);">
                <p class="card-hint" style="margin:0 0 8px;">تخصيص اختياري على طلبات ذات رصيد (مجموع التخصيصات ≤ مبلغ القبض).</p>
                <button type="button" class="btn-secondary" onclick="loadAllocReceipt()">تحميل الطلبات ذات الرصيد</button>
                <div class="table-wrap" style="margin-top:8px;">
                    <table>
                        <thead><tr><th>مستند</th><th>متبقي</th><th>تخصيص</th></tr></thead>
                        <tbody id="alloc_receipt_tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="actions" style="margin-top:12px;">
            <button type="button" onclick="doReceipt()">تسجيل القبض</button>
        </div>
    </div>
    <div class="card">
        <h3 class="card-title">سند دفع لمورد</h3>
        <div class="form-grid">
            <div style="grid-column:1/-1;">
                <label for="pay_sup">المورد</label>
                <select id="pay_sup">
                    <?php if (!$suppliers): ?>
                        <option value="0">— لا يوجد موردون — أضف من المشتريات</option>
                    <?php endif; ?>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?php echo (int)$s['id']; ?>">
                            <?php echo htmlspecialchars($s['name'] . ($s['phone'] ? ' — ' . $s['phone'] : ''), ENT_QUOTES, 'UTF-8'); ?>
                            (ذمة <?php echo number_format($supBal[(int)$s['id']] ?? 0, 3); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="pay_amt">المبلغ</label>
                <input type="number" id="pay_amt" class="admin-inp-money" step="any" min="0.01" value="" inputmode="decimal" lang="en" dir="ltr">
            </div>
            <div>
                <label for="pay_date">التاريخ</label>
                <input type="date" id="pay_date" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div style="grid-column:1/-1;">
                <label for="pay_desc">البيان</label>
                <input type="text" id="pay_desc" placeholder="دفعة مورد">
            </div>
            <div style="grid-column:1/-1;" class="form-check">
                <label><input type="checkbox" id="pay_allow_excess"> السماح بدفع يزيد عن الذمة (دفعة مقدمة للمورد)</label>
            </div>
            <div style="grid-column:1/-1; margin-top:10px; padding-top:12px; border-top:1px solid var(--border, #e5e5e5);">
                <p class="card-hint" style="margin:0 0 8px;">تخصيص اختياري على مشتريات آجلة مفتوحة (مجموع التخصيصات ≤ مبلغ الدفع).</p>
                <button type="button" class="btn-secondary" onclick="loadAllocPay()">تحميل المشتريات ذات الذمة</button>
                <div class="table-wrap" style="margin-top:8px;">
                    <table>
                        <thead><tr><th>مستند</th><th>متبقي</th><th>تخصيص</th></tr></thead>
                        <tbody id="alloc_pay_tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="actions" style="margin-top:12px;">
            <button type="button" onclick="doPay()">تسجيل الدفع</button>
        </div>
    </div>
</div>

<div class="card">
    <h3 class="card-title">كشف حساب طرف</h3>
    <p class="card-hint">حركات الذمم المرتبطة بالعميل أو المورد مع الرصيد الجاري بعد كل سند.</p>
    <div class="form-grid">
        <div>
            <span class="label-like">نوع الطرف</span>
            <div class="form-check" style="margin-top:6px;">
                <label><input type="radio" name="stmt_kind" value="customer" checked onchange="stmtRefreshSelect()"> عميل</label>
                &nbsp;&nbsp;
                <label><input type="radio" name="stmt_kind" value="supplier" onchange="stmtRefreshSelect()"> مورد</label>
            </div>
        </div>
        <div style="grid-column:1/-1;">
            <label for="stmt_party">الطرف</label>
            <select id="stmt_party"></select>
        </div>
    </div>
    <div class="actions" style="margin-top:10px;">
        <button type="button" class="btn-secondary" onclick="loadStatement()">عرض الكشف</button>
    </div>
    <p id="stmt_balance_line" style="margin-top:12px;font-weight:600;"></p>
    <div class="table-wrap" style="margin-top:8px;">
        <table>
            <thead>
                <tr>
                    <th>التاريخ</th>
                    <th>مرجع السند</th>
                    <th>نوع القيد</th>
                    <th>مدين</th>
                    <th>دائن</th>
                    <th>الرصيد بعد الحركة</th>
                    <th>ملاحظة</th>
                </tr>
            </thead>
            <tbody id="stmt_tbody">
                <tr><td colspan="7" class="muted">اختر الطرف ثم اضغط «عرض الكشف».</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3 class="card-title">أعمار الذمة (FIFO)</h3>
    <p class="card-hint">
        توزيع الرصيد المفتوح حسب عمر أقدم حركات الذمة غير المسددة (افتراض: تُسدَّد بالأقدمية).
        اختر نفس الطرف أعلاه في «كشف الحساب»، وتاريخ المرجع لحساب عدد الأيام.
    </p>
    <div class="form-grid">
        <div>
            <label for="aging_as_of">اعتباراً من تاريخ</label>
            <input type="date" id="aging_as_of" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>">
        </div>
    </div>
    <div class="actions" style="margin-top:10px;">
        <button type="button" class="btn-secondary" onclick="loadAging()">حساب أعمار الذمة</button>
    </div>
    <p id="aging_summary" style="margin-top:12px;font-weight:600;"></p>
    <div class="table-wrap" style="margin-top:8px;">
        <table>
            <thead>
                <tr><th>الفترة</th><th>المبلغ</th></tr>
            </thead>
            <tbody id="aging_tbody">
                <tr><td colspan="2" class="muted">استخدم نفس اختيار الطرف في «كشف الحساب» ثم اضغط الحساب.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3 class="card-title">إضافة عميل يدوياً</h3>
    <p class="card-hint">الهاتف هو المعرّف الفريد؛ يُستخدم لربط الطلبات عند التسليم.</p>
    <div class="form-grid">
        <div>
            <label for="new_c_name">الاسم</label>
            <input type="text" id="new_c_name" placeholder="اسم العميل">
        </div>
        <div>
            <label for="new_c_phone">الهاتف</label>
            <input type="text" id="new_c_phone" placeholder="مثال: 5xxxxxxxx">
        </div>
        <div>
            <label for="new_c_limit">حد ائتمان (اختياري)</label>
            <input type="number" id="new_c_limit" class="admin-inp-money" step="any" min="0" placeholder="فارغ = بلا حد" inputmode="decimal" lang="en" dir="ltr">
        </div>
    </div>
    <div class="actions" style="margin-top:10px;">
        <button type="button" class="btn-secondary" onclick="saveCustomer()">حفظ العميل</button>
    </div>
</div>

<div class="card">
    <h3 class="card-title">أرصدة العملاء (ذمم مدينة)</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>الاسم</th><th>الهاتف</th><th>الرصيد (عليه لنا)</th></tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $c): ?>
                    <tr>
                        <td><?php echo (int)$c['id']; ?></td>
                        <td><?php echo htmlspecialchars((string)$c['name_ar'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$c['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format($custBal[(int)$c['id']] ?? 0, 3); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3 class="card-title">أرصدة الموردين (ذمم دائنة)</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>الاسم</th><th>الهاتف</th><th>الذمة (لنا له)</th></tr>
            </thead>
            <tbody>
                <?php foreach ($suppliers as $s): ?>
                    <tr>
                        <td><?php echo (int)$s['id']; ?></td>
                        <td><?php echo htmlspecialchars((string)$s['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($s['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format($supBal[(int)$s['id']] ?? 0, 3); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="card-hint">إضافة مورد من صفحة <a href="/admin/index.php?page=purchases">المشتريات</a>.</p>
</div>

<div class="card">
    <h3 class="card-title">آخر حركات الذمم</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>التاريخ</th>
                    <th>النوع</th>
                    <th>طرف</th>
                    <th>مدين</th>
                    <th>دائن</th>
                    <th>مرجع سند</th>
                    <th>ملاحظة</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(substr((string)$r['voucher_date'], 0, 10), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$r['entry_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($r['party_kind'] . ' #' . $r['party_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format((float)$r['debit'], 3); ?></td>
                        <td><?php echo number_format((float)$r['credit'], 3); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['memo'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
var ORANGE_STMT_PARTIES = <?php echo $stmtPartyJson; ?>;

function stmtRefreshSelect() {
    var k = document.querySelector('input[name="stmt_kind"]:checked');
    k = k ? k.value : 'customer';
    var list = (ORANGE_STMT_PARTIES && ORANGE_STMT_PARTIES[k]) ? ORANGE_STMT_PARTIES[k] : [];
    var sel = document.getElementById('stmt_party');
    sel.innerHTML = '';
    if (!list.length) {
        var o = document.createElement('option');
        o.value = '0';
        o.textContent = k === 'supplier' ? '— لا يوجد موردون —' : '— لا يوجد عملاء —';
        sel.appendChild(o);
        return;
    }
    list.forEach(function (p) {
        var opt = document.createElement('option');
        opt.value = String(p.id);
        opt.textContent = p.label;
        sel.appendChild(opt);
    });
}

function loadAging() {
    var k = document.querySelector('input[name="stmt_kind"]:checked');
    k = k ? k.value : 'customer';
    var id = parseInt(document.getElementById('stmt_party').value, 10) || 0;
    var asOf = document.getElementById('aging_as_of').value;
    var tb = document.getElementById('aging_tbody');
    var sumEl = document.getElementById('aging_summary');
    if (id <= 0) {
        tb.innerHTML = '<tr><td colspan="2">اختر طرفاً من قائمة «كشف الحساب» أولاً.</td></tr>';
        sumEl.textContent = '';
        return;
    }
    if (!asOf) {
        alert('اختر تاريخ المرجع');
        return;
    }
    tb.innerHTML = '<tr><td colspan="2">جاري الحساب…</td></tr>';
    sumEl.textContent = '';
    postJSON('/admin/api/partners/aging.php', { party_kind: k, party_id: id, as_of: asOf }).then(function (r) {
        if (!r.success || !r.aging) {
            tb.innerHTML = '<tr><td colspan="2">' + (r.message || 'فشل') + '</td></tr>';
            return;
        }
        var g = r.aging;
        var bal = Number(g.balance).toFixed(3);
        var openB = Number(g.open_in_buckets).toFixed(3);
        var pre = Number(g.prepayment || 0).toFixed(3);
        sumEl.textContent =
            'رصيد الذمة: ' + bal +
            ' — مجموع الفترات: ' + openB +
            (Number(g.prepayment) > 0.0001 ? ' — دفعة مقدمة / سلفة: ' + pre : '');
        var labels = g.bucket_labels_ar || {};
        var b = g.buckets || {};
        var order = ['days_0_30', 'days_31_60', 'days_61_90', 'days_91_plus'];
        tb.innerHTML = '';
        order.forEach(function (key) {
            var tr = document.createElement('tr');
            var lab = labels[key] || key;
            tr.innerHTML = '<td>' + escapeHtml(lab) + '</td><td>' + Number(b[key] || 0).toFixed(3) + '</td>';
            tb.appendChild(tr);
        });
    }).catch(function (e) {
        tb.innerHTML = '<tr><td colspan="2">' + (e.message || String(e)) + '</td></tr>';
    });
}

function loadStatement() {
    var k = document.querySelector('input[name="stmt_kind"]:checked');
    k = k ? k.value : 'customer';
    var id = parseInt(document.getElementById('stmt_party').value, 10) || 0;
    var tb = document.getElementById('stmt_tbody');
    var balEl = document.getElementById('stmt_balance_line');
    if (id <= 0) {
        tb.innerHTML = '<tr><td colspan="7">لا يوجد طرف للعرض.</td></tr>';
        balEl.textContent = '';
        return;
    }
    tb.innerHTML = '<tr><td colspan="7">جاري التحميل…</td></tr>';
    balEl.textContent = '';
    postJSON('/admin/api/partners/statement.php', { party_kind: k, party_id: id }).then(function (r) {
        if (!r.success) {
            tb.innerHTML = '<tr><td colspan="7">' + (r.message || 'فشل') + '</td></tr>';
            return;
        }
        balEl.textContent = 'الرصيد الحالي: ' + Number(r.balance).toFixed(3) + (k === 'customer' ? ' (عليه لنا)' : ' (ذمة للمورد)');
        var lines = r.lines || [];
        if (!lines.length) {
            tb.innerHTML = '<tr><td colspan="7">لا توجد حركات في دفتر الذمم لهذا الطرف.</td></tr>';
            return;
        }
        tb.innerHTML = '';
        lines.forEach(function (row) {
            var tr = document.createElement('tr');
            var d = (row.voucher_date || '').toString().slice(0, 10);
            tr.innerHTML =
                '<td>' + d + '</td>' +
                '<td>' + escapeHtml(row.reference || '') + '</td>' +
                '<td>' + escapeHtml(row.entry_type || '') + '</td>' +
                '<td>' + Number(row.debit).toFixed(3) + '</td>' +
                '<td>' + Number(row.credit).toFixed(3) + '</td>' +
                '<td>' + Number(row.balance).toFixed(3) + '</td>' +
                '<td>' + escapeHtml((row.memo || row.voucher_description || '').toString()) + '</td>';
            tb.appendChild(tr);
        });
    }).catch(function (e) {
        tb.innerHTML = '<tr><td colspan="7">' + (e.message || String(e)) + '</td></tr>';
    });
}

function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function loadAllocReceipt() {
    var id = parseInt(document.getElementById('rec_cust').value, 10) || 0;
    var tb = document.getElementById('alloc_receipt_tbody');
    if (id <= 0) { alert('اختر عميلاً'); return; }
    tb.innerHTML = '<tr><td colspan="3">جاري التحميل…</td></tr>';
    postJSON('/admin/api/partners/open-items.php', { party_kind: 'customer', party_id: id }).then(function (r) {
        if (!r.success) {
            tb.innerHTML = '<tr><td colspan="3">' + (r.message || 'فشل') + '</td></tr>';
            return;
        }
        tb.innerHTML = '';
        var items = r.items || [];
        items.forEach(function (it) {
            var tr = document.createElement('tr');
            tr.setAttribute('data-ref-type', it.ref_type);
            tr.setAttribute('data-ref-id', String(it.ref_id));
            tr.innerHTML = '<td>' + escapeHtml(it.label) + '</td><td>' + Number(it.open).toFixed(3) + '</td><td><input type="number" class="alloc-amt admin-inp-money" step="any" min="0" placeholder="0.000" inputmode="decimal" lang="en" dir="ltr"></td>';
            tb.appendChild(tr);
        });
        if (!items.length) {
            tb.innerHTML = '<tr><td colspan="3" class="muted">لا توجد طلبات مفتوحة.</td></tr>';
        }
    }).catch(function (e) {
        tb.innerHTML = '<tr><td colspan="3">' + (e.message || String(e)) + '</td></tr>';
    });
}

function loadAllocPay() {
    var id = parseInt(document.getElementById('pay_sup').value, 10) || 0;
    var tb = document.getElementById('alloc_pay_tbody');
    if (id <= 0) { alert('اختر مورداً'); return; }
    tb.innerHTML = '<tr><td colspan="3">جاري التحميل…</td></tr>';
    postJSON('/admin/api/partners/open-items.php', { party_kind: 'supplier', party_id: id }).then(function (r) {
        if (!r.success) {
            tb.innerHTML = '<tr><td colspan="3">' + (r.message || 'فشل') + '</td></tr>';
            return;
        }
        tb.innerHTML = '';
        var items = r.items || [];
        items.forEach(function (it) {
            var tr = document.createElement('tr');
            tr.setAttribute('data-ref-type', it.ref_type);
            tr.setAttribute('data-ref-id', String(it.ref_id));
            tr.innerHTML = '<td>' + escapeHtml(it.label) + '</td><td>' + Number(it.open).toFixed(3) + '</td><td><input type="number" class="alloc-amt admin-inp-money" step="any" min="0" placeholder="0.000" inputmode="decimal" lang="en" dir="ltr"></td>';
            tb.appendChild(tr);
        });
        if (!items.length) {
            tb.innerHTML = '<tr><td colspan="3" class="muted">لا توجد مشتريات آجلة مفتوحة.</td></tr>';
        }
    }).catch(function (e) {
        tb.innerHTML = '<tr><td colspan="3">' + (e.message || String(e)) + '</td></tr>';
    });
}

function collectAllocTbody(tbodyId) {
    var tb = document.getElementById(tbodyId);
    if (!tb) return [];
    var out = [];
    tb.querySelectorAll('tr[data-ref-type]').forEach(function (tr) {
        var inp = tr.querySelector('.alloc-amt');
        var amt = parseFloat(inp && inp.value ? inp.value : '0');
        if (amt <= 0) return;
        out.push({
            ref_type: tr.getAttribute('data-ref-type'),
            ref_id: parseInt(tr.getAttribute('data-ref-id'), 10),
            amount: amt
        });
    });
    return out;
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', stmtRefreshSelect);
} else {
    stmtRefreshSelect();
}

function doReceipt() {
    var id = parseInt(document.getElementById('rec_cust').value, 10) || 0;
    var amt = parseFloat(document.getElementById('rec_amt').value || '0');
    var d = document.getElementById('rec_date').value;
    var desc = document.getElementById('rec_desc').value.trim();
    if (id <= 0 || amt <= 0 || !d) { alert('أكمل العميل والمبلغ والتاريخ'); return; }
    postJSON('/admin/api/partners/customer-receipt.php', {
        customer_id: id,
        amount: amt,
        date: d,
        description: desc || 'قبض عميل',
        allow_excess: document.getElementById('rec_allow_excess').checked,
        allocations: collectAllocTbody('alloc_receipt_tbody')
    }).then(function (r) {
        alert(r.message || (r.success ? 'تم' : 'فشل'));
        if (r.success) location.reload();
    }).catch(function (e) { alert(e.message || String(e)); });
}
function doPay() {
    var id = parseInt(document.getElementById('pay_sup').value, 10) || 0;
    var amt = parseFloat(document.getElementById('pay_amt').value || '0');
    var d = document.getElementById('pay_date').value;
    var desc = document.getElementById('pay_desc').value.trim();
    if (id <= 0 || amt <= 0 || !d) { alert('أكمل المورد والمبلغ والتاريخ'); return; }
    postJSON('/admin/api/partners/supplier-payment.php', {
        supplier_id: id,
        amount: amt,
        date: d,
        description: desc || 'دفع مورد',
        allow_excess: document.getElementById('pay_allow_excess').checked,
        allocations: collectAllocTbody('alloc_pay_tbody')
    }).then(function (r) {
        alert(r.message || (r.success ? 'تم' : 'فشل'));
        if (r.success) location.reload();
    }).catch(function (e) { alert(e.message || String(e)); });
}
function saveCustomer() {
    var n = document.getElementById('new_c_name').value.trim();
    var p = document.getElementById('new_c_phone').value.trim();
    var limRaw = document.getElementById('new_c_limit').value.trim();
    if (!p) { alert('الهاتف مطلوب'); return; }
    var payload = { name_ar: n || 'عميل', phone: p };
    if (limRaw === '') {
        payload.credit_limit = null;
    } else {
        var lim = parseFloat(limRaw);
        if (isNaN(lim) || lim < 0) { alert('حد ائتمان غير صالح'); return; }
        payload.credit_limit = lim <= 0 ? null : lim;
    }
    postJSON('/admin/api/customers/save.php', payload)
        .then(function (r) {
            alert(r.message || (r.success ? 'تم' : 'فشل'));
            if (r.success) location.reload();
        })
        .catch(function (e) { alert(e.message || String(e)); });
}
</script>
