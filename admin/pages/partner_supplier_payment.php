<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/date_format.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$partnerUiTodayDmy = orange_format_date_dmY(date('Y-m-d'));

$prefillStmtKind = in_array((string) ($_GET['stmt_party_kind'] ?? ''), ['customer', 'supplier'], true)
    ? (string) $_GET['stmt_party_kind']
    : '';
$prefillStmtId = (int) ($_GET['stmt_party_id'] ?? 0);

$suppliers = orange_table_exists($pdo, 'suppliers')
    ? $pdo->query('SELECT id, name, phone FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC)
    : [];

$supBal = [];
foreach ($suppliers as $s) {
    $supBal[(int) $s['id']] = orange_party_balance_supplier($pdo, (int) $s['id']);
}

$stmtPartyPayload = ['supplier' => []];
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

$hubLedger = storefront_public_path('/admin/index.php?page=partner_ledger');
$customerVoucher = storefront_public_path('/admin/index.php?page=partner_customer_receipt');
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>سند صرف / مورد</h1>
        <p class="page-subtitle">
            تسجيل دفع نقدي لمورد مقابل ذمته مع قيد محاسبي. للعملاء استخدم
            <a href="<?php echo htmlspecialchars($customerVoucher, ENT_QUOTES, 'UTF-8'); ?>">سند قبض / عميل</a>.
            نظرة عامة وكشوف مشتركة:
            <a href="<?php echo htmlspecialchars($hubLedger, ENT_QUOTES, 'UTF-8'); ?>">ذمم العملاء والموردين</a>
            —
            <a href="<?php echo htmlspecialchars($hubLedger . '#partner-account-statement', ENT_QUOTES, 'UTF-8'); ?>">كشف حساب</a>
            —
            <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_reports'), ENT_QUOTES, 'UTF-8'); ?>">تقارير الذمم</a>
        </p>
    </div>
</div>

<div class="card" id="partner-payment-voucher">
    <h3 class="card-title">سند دفع لمورد</h3>
    <div class="form-grid">
        <div style="grid-column:1/-1;">
            <label for="pay_sup">المورد</label>
            <select id="pay_sup">
                <?php if (!$suppliers): ?>
                    <option value="0">— لا يوجد موردون — أضف من المشتريات</option>
                <?php endif; ?>
                <?php foreach ($suppliers as $s): ?>
                    <option value="<?php echo (int) $s['id']; ?>">
                        <?php echo htmlspecialchars($s['name'] . ($s['phone'] ? ' — ' . $s['phone'] : ''), ENT_QUOTES, 'UTF-8'); ?>
                        (ذمة <?php echo number_format($supBal[(int) $s['id']] ?? 0, 3); ?>)
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
            <input type="text" id="pay_date" class="admin-inp orange-inp-dmy" value="<?php echo htmlspecialchars($partnerUiTodayDmy, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off">
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

<div class="card" id="partner-account-statement">
    <h3 class="card-title">كشف حساب مورد</h3>
    <p class="card-hint">حركات الذمم للمورد مع الرصيد الجاري بعد كل سند.</p>
    <div class="form-grid">
        <div style="grid-column:1/-1;">
            <label for="stmt_party">المورد</label>
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
                <tr><td colspan="7" class="muted">اختر المورد ثم اضغط «عرض الكشف».</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3 class="card-title">أعمار الذمة (FIFO) — مورد</h3>
    <p class="card-hint">
        توزيع الرصيد المفتوح حسب عمر أقدم حركات الذمة غير المسددة. اختر المورد في «كشف الحساب» أعلاه.
    </p>
    <div class="form-grid">
        <div>
            <label for="aging_as_of">اعتباراً من تاريخ</label>
            <input type="text" id="aging_as_of" class="admin-inp orange-inp-dmy" value="<?php echo htmlspecialchars($partnerUiTodayDmy, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off">
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
                <tr><td colspan="2" class="muted">اختر مورداً واعرض الكشف ثم احسب الأعمار.</td></tr>
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
                        <td><?php echo (int) $s['id']; ?></td>
                        <td><?php echo htmlspecialchars((string) $s['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($s['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format($supBal[(int) $s['id']] ?? 0, 3); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="card-hint">إدارة الموردين من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=suppliers'), ENT_QUOTES, 'UTF-8'); ?>">شاشة الموردين</a> أو عند إنشاء <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=purchases'), ENT_QUOTES, 'UTF-8'); ?>">مستند شراء</a>.</p>
</div>

<script>
var ORANGE_STMT_PARTIES = <?php echo $stmtPartyJson; ?>;
var ORANGE_STMT_PREFILL = <?php echo json_encode(['kind' => $prefillStmtKind, 'id' => $prefillStmtId], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var GL_ENTRY_LABELS = <?php echo json_encode(orange_gl_entry_type_labels_map(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
function glEntryTypeLabel(et) {
    et = String(et == null ? '' : et);
    return GL_ENTRY_LABELS[et] || et || '';
}

function stmtRefreshSelect() {
    var list = (ORANGE_STMT_PARTIES && ORANGE_STMT_PARTIES.supplier) ? ORANGE_STMT_PARTIES.supplier : [];
    var sel = document.getElementById('stmt_party');
    sel.innerHTML = '';
    if (!list.length) {
        var o = document.createElement('option');
        o.value = '0';
        o.textContent = '— لا يوجد موردون —';
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
    var asOf = orangeGetDmyValueAsIso(document.getElementById('aging_as_of'));
    var tb = document.getElementById('aging_tbody');
    var sumEl = document.getElementById('aging_summary');
    if (id <= 0) {
        tb.innerHTML = '<tr><td colspan="2">اختر مورداً من قائمة «كشف الحساب» أولاً.</td></tr>';
        sumEl.textContent = '';
        return;
    }
    if (!asOf) {
        alert('اختر تاريخ المرجع (يوم/شهر/سنة)');
        return;
    }
    tb.innerHTML = '<tr><td colspan="2">جاري الحساب…</td></tr>';
    sumEl.textContent = '';
    postJSON('/admin/api/partners/aging.php', { party_kind: 'supplier', party_id: id, as_of: asOf }).then(function (r) {
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
        tb.innerHTML = '<tr><td colspan="7">لا يوجد مورد للعرض.</td></tr>';
        balEl.textContent = '';
        return;
    }
    tb.innerHTML = '<tr><td colspan="7">جاري التحميل…</td></tr>';
    balEl.textContent = '';
    postJSON('/admin/api/partners/statement.php', { party_kind: 'supplier', party_id: id }).then(function (r) {
        if (!r.success) {
            tb.innerHTML = '<tr><td colspan="7">' + (r.message || 'فشل') + '</td></tr>';
            return;
        }
        balEl.textContent = 'الرصيد الحالي: ' + Number(r.balance).toFixed(3) + ' (ذمة للمورد)';
        var lines = r.lines || [];
        if (!lines.length) {
            tb.innerHTML = '<tr><td colspan="7">لا توجد حركات في دفتر الذمم لهذا المورد.</td></tr>';
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

function orangePartnerStmtInit() {
    stmtRefreshSelect();
    if (!ORANGE_STMT_PREFILL || !ORANGE_STMT_PREFILL.kind || !ORANGE_STMT_PREFILL.id) {
        return;
    }
    var k = String(ORANGE_STMT_PREFILL.kind);
    var id = parseInt(ORANGE_STMT_PREFILL.id, 10) || 0;
    if (k !== 'supplier' || id <= 0) {
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

function doPay() {
    var id = parseInt(document.getElementById('pay_sup').value, 10) || 0;
    var amt = parseFloat(document.getElementById('pay_amt').value || '0');
    var dIso = orangeGetDmyValueAsIso(document.getElementById('pay_date'));
    var desc = document.getElementById('pay_desc').value.trim();
    if (id <= 0 || amt <= 0 || !dIso) { alert('أكمل المورد والمبلغ والتاريخ (يوم/شهر/سنة)'); return; }
    postJSON('/admin/api/partners/supplier-payment.php', {
        supplier_id: id,
        amount: amt,
        date: dIso,
        description: desc || 'دفع مورد',
        allow_excess: document.getElementById('pay_allow_excess').checked,
        allocations: collectAllocTbody('alloc_pay_tbody')
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
</script>
