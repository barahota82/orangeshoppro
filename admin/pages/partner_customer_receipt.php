<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/gl_settings.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$prefillStmtKind = in_array((string) ($_GET['stmt_party_kind'] ?? ''), ['customer', 'supplier'], true)
    ? (string) $_GET['stmt_party_kind']
    : '';
$prefillStmtId = (int) ($_GET['stmt_party_id'] ?? 0);

$customers = orange_table_exists($pdo, 'customers')
    ? $pdo->query('SELECT id, name_ar, phone FROM customers ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC)
    : [];

$custBal = [];
foreach ($customers as $c) {
    $custBal[(int) $c['id']] = orange_party_balance_customer($pdo, (int) $c['id']);
}

$stmtPartyPayload = ['customer' => []];
foreach ($customers as $c) {
    $stmtPartyPayload['customer'][] = [
        'id' => (int) $c['id'],
        'label' => $c['name_ar'] . ' — ' . $c['phone'] . ' (رصيد ' . number_format($custBal[(int) $c['id']] ?? 0, 3) . ')',
    ];
}
$stmtPartyJson = json_encode($stmtPartyPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
if ($stmtPartyJson === false) {
    $stmtPartyJson = '{}';
}

$hubLedger = storefront_public_path('/admin/index.php?page=partner_ledger');
$supplierVoucher = storefront_public_path('/admin/index.php?page=partner_supplier_payment');
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>سند قبض / عميل</h1>
        <p class="page-subtitle">
            تسجيل قبض نقدي مقابل ذمة عميل مع قيد محاسبي. للموردين استخدم
            <a href="<?php echo htmlspecialchars($supplierVoucher, ENT_QUOTES, 'UTF-8'); ?>">سند صرف / مورد</a>.
            نظرة عامة وكشوف مشتركة:
            <a href="<?php echo htmlspecialchars($hubLedger, ENT_QUOTES, 'UTF-8'); ?>">ذمم العملاء والموردين</a>
            —
            <a href="<?php echo htmlspecialchars($hubLedger . '#partner-account-statement', ENT_QUOTES, 'UTF-8'); ?>">كشف حساب</a>
            —
            <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_reports'), ENT_QUOTES, 'UTF-8'); ?>">تقارير الذمم</a>
        </p>
    </div>
</div>

<div class="card" id="partner-receipt-voucher">
    <h3 class="card-title">سند قبض من عميل</h3>
    <div class="form-grid">
        <div style="grid-column:1/-1;">
            <label for="rec_cust">العميل</label>
            <select id="rec_cust">
                <?php if (!$customers): ?>
                    <option value="0">— لا يوجد عملاء — أضف عميلاً أدناه</option>
                <?php endif; ?>
                <?php foreach ($customers as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>">
                        <?php echo htmlspecialchars($c['name_ar'] . ' — ' . $c['phone'], ENT_QUOTES, 'UTF-8'); ?>
                        (رصيد <?php echo number_format($custBal[(int) $c['id']] ?? 0, 3); ?>)
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

<div class="card" id="partner-account-statement">
    <h3 class="card-title">كشف حساب عميل</h3>
    <p class="card-hint">حركات الذمم للعميل مع الرصيد الجاري بعد كل سند.</p>
    <div class="form-grid">
        <div style="grid-column:1/-1;">
            <label for="stmt_party">العميل</label>
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
                <tr><td colspan="7" class="muted">اختر العميل ثم اضغط «عرض الكشف».</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3 class="card-title">أعمار الذمة (FIFO) — عميل</h3>
    <p class="card-hint">
        توزيع الرصيد المفتوح حسب عمر أقدم حركات الذمة غير المسددة. اختر العميل في «كشف الحساب» أعلاه.
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
                <tr><td colspan="2" class="muted">اختر عميلاً واعرض الكشف ثم احسب الأعمار.</td></tr>
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
                        <td><?php echo (int) $c['id']; ?></td>
                        <td><?php echo htmlspecialchars((string) $c['name_ar'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) $c['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format($custBal[(int) $c['id']] ?? 0, 3); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
var ORANGE_STMT_PARTIES = <?php echo $stmtPartyJson; ?>;
var ORANGE_STMT_PARTY_KIND = 'customer';
var ORANGE_STMT_PREFILL = <?php echo json_encode(['kind' => $prefillStmtKind, 'id' => $prefillStmtId], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var GL_ENTRY_LABELS = <?php echo json_encode(orange_gl_entry_type_labels_map(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
function glEntryTypeLabel(et) {
    et = String(et == null ? '' : et);
    return GL_ENTRY_LABELS[et] || et || '';
}

function stmtRefreshSelect() {
    var list = (ORANGE_STMT_PARTIES && ORANGE_STMT_PARTIES.customer) ? ORANGE_STMT_PARTIES.customer : [];
    var sel = document.getElementById('stmt_party');
    sel.innerHTML = '';
    if (!list.length) {
        var o = document.createElement('option');
        o.value = '0';
        o.textContent = '— لا يوجد عملاء —';
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
    var id = parseInt(document.getElementById('stmt_party').value, 10) || 0;
    var asOf = document.getElementById('aging_as_of').value;
    var tb = document.getElementById('aging_tbody');
    var sumEl = document.getElementById('aging_summary');
    if (id <= 0) {
        tb.innerHTML = '<tr><td colspan="2">اختر عميلاً من قائمة «كشف الحساب» أولاً.</td></tr>';
        sumEl.textContent = '';
        return;
    }
    if (!asOf) {
        alert('اختر تاريخ المرجع');
        return;
    }
    tb.innerHTML = '<tr><td colspan="2">جاري الحساب…</td></tr>';
    sumEl.textContent = '';
    postJSON('/admin/api/partners/aging.php', { party_kind: 'customer', party_id: id, as_of: asOf }).then(function (r) {
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
    var id = parseInt(document.getElementById('stmt_party').value, 10) || 0;
    var tb = document.getElementById('stmt_tbody');
    var balEl = document.getElementById('stmt_balance_line');
    if (id <= 0) {
        tb.innerHTML = '<tr><td colspan="7">لا يوجد عميل للعرض.</td></tr>';
        balEl.textContent = '';
        return;
    }
    tb.innerHTML = '<tr><td colspan="7">جاري التحميل…</td></tr>';
    balEl.textContent = '';
    postJSON('/admin/api/partners/statement.php', { party_kind: 'customer', party_id: id }).then(function (r) {
        if (!r.success) {
            tb.innerHTML = '<tr><td colspan="7">' + (r.message || 'فشل') + '</td></tr>';
            return;
        }
        balEl.textContent = 'الرصيد الحالي: ' + Number(r.balance).toFixed(3) + ' (عليه لنا)';
        var lines = r.lines || [];
        if (!lines.length) {
            tb.innerHTML = '<tr><td colspan="7">لا توجد حركات في دفتر الذمم لهذا العميل.</td></tr>';
            return;
        }
        tb.innerHTML = '';
        lines.forEach(function (row) {
            var tr = document.createElement('tr');
            var d = (row.voucher_date_display || '').toString() || (row.voucher_date || '').toString().slice(0, 10);
            tr.innerHTML =
                '<td>' + escapeHtml(d) + '</td>' +
                '<td>' + escapeHtml(row.reference || '') + '</td>' +
                '<td title="' + escapeHtml(row.entry_type || '') + '">' + escapeHtml(glEntryTypeLabel(row.entry_type)) + '</td>' +
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

function orangePartnerStmtInit() {
    stmtRefreshSelect();
    if (!ORANGE_STMT_PREFILL || !ORANGE_STMT_PREFILL.kind || !ORANGE_STMT_PREFILL.id) {
        return;
    }
    var k = String(ORANGE_STMT_PREFILL.kind);
    var id = parseInt(ORANGE_STMT_PREFILL.id, 10) || 0;
    if (k !== 'customer' || id <= 0) {
        return;
    }
    var sel = document.getElementById('stmt_party');
    if (sel && sel.querySelector('option[value="' + id + '"]')) {
        sel.value = String(id);
        loadStatement();
    }
    var sec = document.getElementById('partner-account-statement');
    if (sec) {
        setTimeout(function () {
            sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 250);
    }
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', orangePartnerStmtInit);
} else {
    orangePartnerStmtInit();
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
        if (r.success) {
            alert(r.message || 'تم');
            location.reload();
            return;
        }
        if (!orangeAdminOfferSuggestOnFailure(r, 'فشل')) {
            alert(r.message || 'فشل');
        }
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
