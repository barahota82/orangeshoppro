<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/date_format.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$nextJournalVoucherNo = 1;
if (orange_journal_vouchers_ready($pdo)) {
    $nextJournalVoucherNo = (int) $pdo->query('SELECT COALESCE(MAX(id),0) + 1 FROM journal_vouchers')->fetchColumn();
}
$jvFormDocumentEnteredDisplay = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));

$hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');
$accCols = $hasGrp ? 'id, name, code, is_group' : 'id, name, code';
$accounts = $pdo->query('SELECT ' . $accCols . ' FROM accounts ORDER BY COALESCE(code, \'\'), name')->fetchAll(PDO::FETCH_ASSOC);

$acctOpts = '';
foreach ($accounts as $a) {
    if ($hasGrp && !empty($a['is_group'])) {
        continue;
    }
    $lab = trim((string) ($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' . $a['name'] : $a['name'];
    $acctOpts .= '<option value="' . (int) $a['id'] . '">' . htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') . '</option>';
}
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>سند قيد</h1>
    </div>
</div>

<div class="card">
    <h3 class="card-title">سند قيد</h3>
    <div class="form-grid">
        <div class="jv-voucher-header-line" style="grid-column:1/-1;">
            <div>
                <label for="jv_number_preview">رقم القيد</label>
                <input type="text" id="jv_number_preview" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;text-align:center;"
                    value="<?php echo (int) $nextJournalVoucherNo; ?>"
                    title="يُخصَّص تلقائياً من النظام عند الحفظ (تسلسل قاعدة البيانات)">
            </div>
            <div>
                <label for="jv_date">تاريخ السند</label>
                <input type="date" id="jv_date" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>"
                    title="تاريخ محاسبي للسند (يختاره المستخدم)" dir="ltr" lang="en">
            </div>
            <div>
                <label for="jv_ref">المرجع <span class="muted" style="font-weight:normal;">(اختياري)</span></label>
                <input type="text" id="jv_ref" placeholder="مرجع داخلي أو خارجي" autocomplete="off">
            </div>
            <div>
                <label for="jv_document_entered">تاريخ المستند</label>
                <input type="text" id="jv_document_entered" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;"
                    value="<?php echo htmlspecialchars($jvFormDocumentEnteredDisplay, ENT_QUOTES, 'UTF-8'); ?>"
                    title="وقت تسجيل إدخال القيد في النظام — يُثبت عند الحفظ ولا يُقبل من المتصفح" dir="ltr" lang="en">
            </div>
        </div>
        <div style="grid-column:1/-1;">
            <label for="jv_desc">البيان</label>
            <input type="text" id="jv_desc" placeholder="وصف السند">
        </div>
    </div>
    <p class="card-hint" id="jv_balance_hint">مجموع المدين: 0 — مجموع الدائن: 0</p>
    <div class="admin-doc-frame">
        <div class="table-wrap">
            <table class="admin-table admin-doc-lines-table">
                <thead>
                    <tr>
                        <th>الحساب</th>
                        <th>مدين</th>
                        <th>دائن</th>
                        <th>البيان</th>
                        <th class="admin-doc-col-actions" aria-label="حذف السطر"></th>
                    </tr>
                </thead>
                <tbody id="jv_lines_body"></tbody>
            </table>
        </div>
    </div>
    <div class="actions admin-doc-lines-toolbar" style="margin-top:10px;flex-wrap:wrap;gap:8px;">
        <button type="button" class="btn-secondary" onclick="jvAddRow()">+ سطر يدوي</button>
        <button type="button" onclick="jvSubmit()">حفظ السند</button>
    </div>
</div>

<script>
var JV_ACCT_OPTS = <?php echo json_encode($acctOpts, JSON_UNESCAPED_UNICODE); ?>;

function jvAddRow() {
    var tb = document.getElementById('jv_lines_body');
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><select class="jv-acc">' + JV_ACCT_OPTS + '</select></td>' +
        '<td><input type="number" class="jv-d admin-inp-money" step="any" min="0" value="" placeholder="0.000" inputmode="decimal" lang="en" dir="ltr"></td>' +
        '<td><input type="number" class="jv-c admin-inp-money" step="any" min="0" value="" placeholder="0.000" inputmode="decimal" lang="en" dir="ltr"></td>' +
        '<td><input type="text" class="jv-m" value="" placeholder="البيان" autocomplete="off"></td>' +
        '<td><button type="button" class="btn-secondary admin-doc-line-remove" onclick="jvRemoveRow(this)">حذف</button></td>';
    tb.appendChild(tr);
    jvRecalc();
}

function jvRemoveRow(btn) {
    var tb = document.getElementById('jv_lines_body');
    if (tb.querySelectorAll('tr').length <= 1) {
        var tr = btn.closest('tr');
        tr.querySelectorAll('.jv-d,.jv-c,.jv-m').forEach(function (el) { el.value = ''; });
        jvSyncTrailingRows();
        jvRecalc();
        return;
    }
    btn.closest('tr').remove();
    jvSyncTrailingRows();
    jvRecalc();
}

function jvRowIsBlank(tr) {
    var deb = parseFloat(String(tr.querySelector('.jv-d').value || '0').replace(',', '.')) || 0;
    var cre = parseFloat(String(tr.querySelector('.jv-c').value || '0').replace(',', '.')) || 0;
    var memo = tr.querySelector('.jv-m').value.trim();
    return deb <= 0 && cre <= 0 && memo === '';
}

function jvTrimExtraTrailingBlanks() {
    var tb = document.getElementById('jv_lines_body');
    var rows;
    for (;;) {
        rows = tb.querySelectorAll('tr');
        if (rows.length < 2) {
            return;
        }
        var a = rows[rows.length - 2];
        var b = rows[rows.length - 1];
        if (jvRowIsBlank(a) && jvRowIsBlank(b)) {
            a.remove();
        } else {
            return;
        }
    }
}

function jvSyncTrailingRows() {
    jvTrimExtraTrailingBlanks();
    var tb = document.getElementById('jv_lines_body');
    var rows = tb.querySelectorAll('tr');
    if (rows.length === 0) {
        jvAddRow();
        return;
    }
    var last = rows[rows.length - 1];
    if (!jvRowIsBlank(last)) {
        jvAddRow();
    }
}

function jvBindLinesBody() {
    var tb = document.getElementById('jv_lines_body');
    if (!tb || tb.getAttribute('data-jv-bound') === '1') {
        return;
    }
    tb.setAttribute('data-jv-bound', '1');
    tb.addEventListener('input', function () {
        jvSyncTrailingRows();
        jvRecalc();
    });
    tb.addEventListener('change', function () {
        jvSyncTrailingRows();
        jvRecalc();
    });
    tb.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab' || e.shiftKey) {
            return;
        }
        var ta = e.target;
        if (!ta || !ta.closest) {
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
        if (!ta.classList || !ta.classList.contains('jv-m')) {
            return;
        }
        e.preventDefault();
        jvSyncTrailingRows();
        var rows2 = tb.querySelectorAll('tr');
        var next = rows2[rows2.length - 1];
        var sel = next && next.querySelector('.jv-acc');
        if (sel) {
            sel.focus();
        }
    });
}

function jvRecalc() {
    var sd = 0, sc = 0;
    document.querySelectorAll('#jv_lines_body tr').forEach(function (tr) {
        var d = parseFloat(String(tr.querySelector('.jv-d').value || '0').replace(',', '.'));
        var c = parseFloat(String(tr.querySelector('.jv-c').value || '0').replace(',', '.'));
        sd += d; sc += c;
    });
    document.getElementById('jv_balance_hint').textContent = 'مجموع المدين: ' + sd.toFixed(3) + ' — مجموع الدائن: ' + sc.toFixed(3);
}

function jvSubmit() {
    var d = document.getElementById('jv_date').value;
    var ref = document.getElementById('jv_ref').value.trim();
    var desc = document.getElementById('jv_desc').value.trim();
    if (!d || !desc) {
        alert('التاريخ والبيان مطلوبان');
        return;
    }
    var lines = [];
    var memoAbort = false;
    document.querySelectorAll('#jv_lines_body tr').forEach(function (tr) {
        var acc = parseInt(tr.querySelector('.jv-acc').value, 10) || 0;
        var deb = parseFloat(String(tr.querySelector('.jv-d').value || '0').replace(',', '.'));
        var cre = parseFloat(String(tr.querySelector('.jv-c').value || '0').replace(',', '.'));
        var memo = tr.querySelector('.jv-m').value.trim();
        if (acc <= 0) return;
        if (deb > 0 && cre > 0) {
            cre = 0;
        }
        if (deb <= 0 && cre <= 0) return;
        if (memo === '') {
            alert('البيان مطلوب لكل سطر يحتوي مبلغاً');
            memoAbort = true;
            return;
        }
        lines.push({ account_id: acc, debit: deb, credit: cre, memo: memo });
    });
    if (memoAbort) {
        return;
    }
    if (lines.length < 2) {
        alert('أضف سطرين على الأقل بمبالغ صحيحة');
        return;
    }
    var sd = lines.reduce(function (a, x) { return a + x.debit; }, 0);
    var sc = lines.reduce(function (a, x) { return a + x.credit; }, 0);
    if (Math.abs(sd - sc) > 0.001) {
        alert('السند غير متوازن');
        return;
    }
    postJSON('/admin/api/journal/manage.php', {
        action: 'create',
        date: d,
        reference: ref,
        description: desc,
        entry_type: 'manual',
        lines: lines
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

jvAddRow();
jvBindLinesBody();
jvSyncTrailingRows();
</script>
