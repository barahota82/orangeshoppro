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
            سندات القبض والصرف في شاشتين منفصلتين:
            <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_customer_receipt'), ENT_QUOTES, 'UTF-8'); ?>"><strong>سداد فواتير مبيعات آجلة</strong></a>
            —
            <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_supplier_payment'), ENT_QUOTES, 'UTF-8'); ?>"><strong>سداد فواتير مشتريات آجلة</strong></a>.
            <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_reports'), ENT_QUOTES, 'UTF-8'); ?>">تقارير الذمم المالية ومطابقة الدليل</a>
        </p>
    </div>
</div>

<div class="card" id="partner-account-statement">
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
                <tr><td colspan="2" class="muted">استخدم نفس اختيار الطرف في «كشف الحساب» ثم اضغط الحساب.</td></tr>
            </tbody>
        </table>
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
    <p class="card-hint">إدارة الموردين من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=suppliers'), ENT_QUOTES, 'UTF-8'); ?>">شاشة الموردين</a> أو عند إنشاء <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=purchases'), ENT_QUOTES, 'UTF-8'); ?>">مستند شراء</a>.</p>
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
                        <td><?php echo htmlspecialchars(orange_format_date_dmY((string) ($r['voucher_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td title="<?php echo htmlspecialchars((string) $r['entry_type'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(orange_gl_entry_type_label_ar((string) ($r['entry_type'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
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
var ORANGE_STMT_PREFILL = <?php echo json_encode(['kind' => $prefillStmtKind, 'id' => $prefillStmtId], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var GL_ENTRY_LABELS = <?php echo json_encode(orange_gl_entry_type_labels_map(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
function glEntryTypeLabel(et) {
    et = String(et == null ? '' : et);
    return GL_ENTRY_LABELS[et] || et || '';
}

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
    var asOf = orangeGetDmyValueAsIso(document.getElementById('aging_as_of'));
    var tb = document.getElementById('aging_tbody');
    var sumEl = document.getElementById('aging_summary');
    if (id <= 0) {
        tb.innerHTML = '<tr><td colspan="2">اختر طرفاً من قائمة «كشف الحساب» أولاً.</td></tr>';
        sumEl.textContent = '';
        return;
    }
    if (!asOf) {
        alert('اختر تاريخ المرجع (يوم/شهر/سنة)');
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

function orangePartnerStmtInit() {
    stmtRefreshSelect();
    if (!ORANGE_STMT_PREFILL || !ORANGE_STMT_PREFILL.kind || !ORANGE_STMT_PREFILL.id) {
        return;
    }
    var k = String(ORANGE_STMT_PREFILL.kind);
    var id = parseInt(ORANGE_STMT_PREFILL.id, 10) || 0;
    if (id <= 0 || (k !== 'customer' && k !== 'supplier')) {
        return;
    }
    var rad = document.querySelector('input[name="stmt_kind"][value="' + k + '"]');
    if (rad) {
        rad.checked = true;
    }
    stmtRefreshSelect();
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
</script>
