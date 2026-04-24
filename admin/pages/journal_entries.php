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

$jvAccountsLeaf = [];
foreach ($accounts as $a) {
    if ($hasGrp && !empty($a['is_group'])) {
        continue;
    }
    $jvAccountsLeaf[] = [
        'id' => (int) $a['id'],
        'code' => (string) ($a['code'] ?? ''),
        'name' => (string) ($a['name'] ?? ''),
    ];
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
            <table class="admin-table admin-doc-lines-table jv-lines-table">
                <thead>
                    <tr>
                        <th>كود الحساب</th>
                        <th>اسم الحساب</th>
                        <th>مدين</th>
                        <th>دائن</th>
                        <th>بيان</th>
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

<div id="jv_acct_picker" class="jv-acct-picker" style="display:none;" aria-hidden="true">
    <label class="jv-acct-picker-label" for="jv_acct_picker_search">بحث</label>
    <input type="search" id="jv_acct_picker_search" class="jv-acct-picker-search admin-inp" placeholder="اكتب كلمات من الاسم أو الكود…" autocomplete="off" dir="auto">
    <div class="jv-acct-picker-scroll">
        <table class="admin-table jv-acct-picker-table">
            <thead>
                <tr>
                    <th>كود الحساب</th>
                    <th>اسم الحساب</th>
                </tr>
            </thead>
            <tbody id="jv_acct_picker_tbody"></tbody>
        </table>
    </div>
    <p class="jv-acct-picker-hint muted">نقرتان على صف لاختيار الحساب — Esc للإغلاق</p>
</div>

<style>
.jv-lines-table .jv-acc-code { cursor: pointer; min-width: 7rem; }
.jv-lines-table .jv-acc-name { background: #f4f4f5; cursor: default; min-width: 10rem; }
.jv-acct-picker {
    position: fixed;
    z-index: 10050;
    min-width: min(28rem, calc(100vw - 2rem));
    max-width: calc(100vw - 1rem);
    padding: 10px 12px;
    background: #fff;
    border: 1px solid #d4d4d8;
    border-radius: 8px;
    box-shadow: 0 10px 40px rgba(0,0,0,.12);
    direction: rtl;
}
.jv-acct-picker-label { display: block; font-size: 0.85rem; margin-bottom: 4px; font-weight: 600; }
.jv-acct-picker-search { width: 100%; box-sizing: border-box; margin-bottom: 8px; }
.jv-acct-picker-scroll { max-height: 16rem; overflow: auto; border: 1px solid #e4e4e7; border-radius: 6px; }
.jv-acct-picker-table { margin: 0; font-size: 0.9rem; }
.jv-acct-picker-table thead th { position: sticky; top: 0; background: #fafafa; z-index: 1; }
.jv-acct-picker-table tbody tr { cursor: pointer; }
.jv-acct-picker-table tbody tr:hover { background: #f4f4f5; }
.jv-acct-picker-hint { margin: 8px 0 0; font-size: 0.8rem; }
</style>

<script>
var JV_ACCOUNTS = <?php echo json_encode($jvAccountsLeaf, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;

var jvAcctPickerAnchor = null;

function jvEscapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function jvAcctTokens(q) {
    return String(q || '').trim().toLowerCase().split(/\s+/).filter(Boolean);
}

/** بحث بالكلمات: كل كلمة يجب أن تظهر في (الكود + الاسم) — غير متطابق حرفي بالكامل */
function jvAcctFilterAccounts(q) {
    var tokens = jvAcctTokens(q);
    if (!tokens.length) {
        return JV_ACCOUNTS.slice();
    }
    return JV_ACCOUNTS.filter(function (a) {
        var hay = ((a.code || '') + ' ' + (a.name || '')).toLowerCase();
        return tokens.every(function (t) { return hay.indexOf(t) !== -1; });
    });
}

function jvAcctPickerPosition(anchorEl) {
    var box = document.getElementById('jv_acct_picker');
    if (!box || !anchorEl) {
        return;
    }
    var r = anchorEl.getBoundingClientRect();
    var margin = 8;
    var w = box.offsetWidth || 320;
    box.style.left = Math.max(margin, Math.min(r.left, window.innerWidth - w - margin)) + 'px';
    box.style.top = (r.top - margin) + 'px';
    box.style.transform = 'translateY(-100%)';
}

function jvAcctPickerRender() {
    var searchEl = document.getElementById('jv_acct_picker_search');
    var tb = document.getElementById('jv_acct_picker_tbody');
    if (!tb) {
        return;
    }
    var q = searchEl ? searchEl.value : '';
    var rows = jvAcctFilterAccounts(q);
    tb.innerHTML = '';
    rows.forEach(function (a) {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td>' + jvEscapeHtml(a.code) + '</td><td>' + jvEscapeHtml(a.name) + '</td>';
        tr.addEventListener('dblclick', function () { jvAcctPickerApply(a); });
        tb.appendChild(tr);
    });
}

function jvAcctPickerClose() {
    var box = document.getElementById('jv_acct_picker');
    if (box) {
        box.style.display = 'none';
        box.setAttribute('aria-hidden', 'true');
    }
    jvAcctPickerAnchor = null;
}

function jvAcctPickerApply(a) {
    if (!jvAcctPickerAnchor || !a) {
        jvAcctPickerClose();
        return;
    }
    var tr = jvAcctPickerAnchor.closest('tr');
    if (tr) {
        tr.querySelector('.jv-acc-id').value = String(a.id);
        tr.querySelector('.jv-acc-code').value = a.code || '';
        tr.querySelector('.jv-acc-name').value = a.name || '';
    }
    jvAcctPickerClose();
    jvSyncTrailingRows();
    jvRecalc();
}

function jvAcctPickerOpen(anchorInput) {
    var box = document.getElementById('jv_acct_picker');
    var searchEl = document.getElementById('jv_acct_picker_search');
    if (!box || !searchEl) {
        return;
    }
    jvAcctPickerAnchor = anchorInput;
    searchEl.value = '';
    jvAcctPickerRender();
    box.style.display = 'block';
    box.setAttribute('aria-hidden', 'false');
    box.style.transform = '';
    jvAcctPickerPosition(anchorInput);
    requestAnimationFrame(function () {
        jvAcctPickerPosition(anchorInput);
        searchEl.focus();
        searchEl.select();
    });
}

function jvAcctPickerOnDocMouseDown(ev) {
    var box = document.getElementById('jv_acct_picker');
    if (!box || box.style.display === 'none') {
        return;
    }
    var t = ev.target;
    if (box.contains(t)) {
        return;
    }
    if (jvAcctPickerAnchor && (t === jvAcctPickerAnchor || jvAcctPickerAnchor.contains(t))) {
        return;
    }
    jvAcctPickerClose();
}

function jvAcctPickerOnKey(ev) {
    if (ev.key === 'Escape') {
        jvAcctPickerClose();
    }
}

document.addEventListener('mousedown', jvAcctPickerOnDocMouseDown, true);
document.addEventListener('keydown', jvAcctPickerOnKey, true);

(function jvAcctPickerSearchBind() {
    var searchEl = document.getElementById('jv_acct_picker_search');
    if (searchEl && !searchEl.getAttribute('data-jv-bound')) {
        searchEl.setAttribute('data-jv-bound', '1');
        searchEl.addEventListener('input', function () { jvAcctPickerRender(); });
    }
})();

function jvAddRow() {
    var tb = document.getElementById('jv_lines_body');
    var tr = document.createElement('tr');
    tr.innerHTML = '<td class="jv-acc-code-cell">' +
        '<input type="hidden" class="jv-acc-id" value="">' +
        '<input type="text" class="jv-acc-code admin-inp" value="" placeholder="نقرتان للاختيار" readonly autocomplete="off" title="نقرتان لفتح قائمة الحسابات">' +
        '</td>' +
        '<td><input type="text" class="jv-acc-name admin-inp" value="" readonly tabindex="-1" placeholder="—" title="يُعبأ تلقائياً"></td>' +
        '<td><input type="number" class="jv-d admin-inp-money" step="any" min="0" value="" placeholder="0.000" inputmode="decimal" lang="en" dir="ltr"></td>' +
        '<td><input type="number" class="jv-c admin-inp-money" step="any" min="0" value="" placeholder="0.000" inputmode="decimal" lang="en" dir="ltr"></td>' +
        '<td><input type="text" class="jv-m" value="" placeholder="البيان" autocomplete="off"></td>' +
        '<td><button type="button" class="btn-secondary admin-doc-line-remove" onclick="jvRemoveRow(this)">حذف</button></td>';
    var codeInp = tr.querySelector('.jv-acc-code');
    codeInp.addEventListener('dblclick', function (e) { e.preventDefault(); jvAcctPickerOpen(codeInp); });
    tb.appendChild(tr);
    jvRecalc();
}

function jvRemoveRow(btn) {
    var tb = document.getElementById('jv_lines_body');
    if (tb.querySelectorAll('tr').length <= 1) {
        var tr = btn.closest('tr');
        tr.querySelector('.jv-acc-id').value = '';
        tr.querySelector('.jv-acc-code').value = '';
        tr.querySelector('.jv-acc-name').value = '';
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
    var acc = parseInt(tr.querySelector('.jv-acc-id').value, 10) || 0;
    var deb = parseFloat(String(tr.querySelector('.jv-d').value || '0').replace(',', '.')) || 0;
    var cre = parseFloat(String(tr.querySelector('.jv-c').value || '0').replace(',', '.')) || 0;
    var memo = tr.querySelector('.jv-m').value.trim();
    return acc <= 0 && deb <= 0 && cre <= 0 && memo === '';
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
        var codeInp = next && next.querySelector('.jv-acc-code');
        if (codeInp) {
            codeInp.focus();
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
        var acc = parseInt(tr.querySelector('.jv-acc-id').value, 10) || 0;
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
